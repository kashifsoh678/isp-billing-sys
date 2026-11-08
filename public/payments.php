<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: all 4 roles can collect payments
if (!isset($_SESSION['user_id']) || !can('payments', 'v')) {
    header("Location: dashboard.php");
    exit();
}
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = (int)$_SESSION['user_id'];
$current_page = 'payments';

// next receipt #
function nextReceiptNumber($conn) {
    $r = $conn->query("SELECT IFNULL(MAX(CAST(SUBSTRING(receipt_number, 5) AS UNSIGNED)), 0) + 1 AS nxt FROM payments");
    $n = (int)($r->fetch_assoc()['nxt'] ?? 1);
    return sprintf('RCP-%04d', $n);
}

// csv import/template contract — one payment per row, allocated whole against ONE invoice.
// receipt_number/net_amount are computed, never CSV-supplied. after() mirrors addPayment exactly:
// allocation row + recomputeInvoiceFromAllocations + syncPaymentLedger, no hand-rolled ledger math.
function paymentImportSpec() {
    return [
        'entity'  => 'Payment',
        'table'   => 'payments',
        'perm'    => 'payments',
        'unique'  => ['reference_number'],   // only enforced when a row actually provides one
        'columns' => [
            'customer_id'      => ['label' => 'Customer (code or name)', 'type' => 'lookup', 'required' => true,
                                    'lookup' => ['table' => 'customers', 'match' => ['customer_code', 'full_name'], 'where' => 'is_deleted = 0'],
                                    'demo' => 'CUS-0001'],
            // NOT NULL FK on payments.invoice_id — an "advance, no invoice" payment can't be imported safely
            'invoice_id'       => ['label' => 'Invoice Number', 'type' => 'lookup', 'required' => true,
                                    'lookup' => ['table' => 'invoices', 'match' => ['invoice_number'],
                                                 'where' => "is_deleted = 0 AND status IN ('unpaid','partial','overdue')"],
                                    'demo' => 'INV-2026-0001'],
            'amount'           => ['label' => 'Amount', 'type' => 'd', 'required' => true, 'demo' => '2500'],
            'fee'              => ['label' => 'Fee', 'type' => 'd', 'default' => 0, 'demo' => '0'],
            'payment_method'   => ['label' => 'Payment Method', 'type' => 'enum', 'required' => true,
                                    'values' => ['cash', 'bank_transfer', 'easypaisa', 'jazzcash', 'cheque'], 'demo' => 'cash'],
            'payment_date'     => ['label' => 'Payment Date', 'type' => 'date', 'required' => true, 'demo' => '2026-09-01'],
            'status'           => ['label' => 'Status', 'type' => 'enum', 'values' => ['cleared', 'pending', 'bounced'],
                                    'default' => 'cleared', 'demo' => 'cleared'],
            'reference_number' => ['label' => 'Reference Number', 'type' => 's', 'max' => 100, 'demo' => ''],
            'notes'            => ['label' => 'Notes', 'type' => 's', 'demo' => 'Imported from CSV'],
        ],
        'static'  => ['collected_by' => (int)($_SESSION['user_id'] ?? 0), 'source' => 'office'],
        'init'    => function (mysqli $conn, array &$state) {
            $r = $conn->query("SELECT IFNULL(MAX(CAST(SUBSTRING(receipt_number,5) AS UNSIGNED)),0) mx FROM payments");
            $state['seq'] = (int)($r->fetch_assoc()['mx'] ?? 0);

            // invoice id -> its customer, so a payment can't land on someone else's invoice
            $state['invCust'] = [];
            $r = $conn->query("SELECT id, customer_id FROM invoices WHERE is_deleted = 0");
            while ($x = $r->fetch_assoc()) $state['invCust'][(int)$x['id']] = (int)$x['customer_id'];

            // existing (invoice|date|amount) fingerprints — a re-import can never double-post the same receipt
            $state['seenPay'] = [];
            $r = $conn->query("SELECT invoice_id, payment_date, amount FROM payments WHERE is_deleted = 0");
            while ($x = $r->fetch_assoc())
                $state['seenPay'][$x['invoice_id'] . '|' . $x['payment_date'] . '|' . number_format((float)$x['amount'], 2, '.', '')] = true;
        },
        'before'  => function (array &$row, mysqli $conn, array &$state) {
            $amount = (float)$row['amount'];
            $fee    = (float)$row['fee'];
            if ($amount <= 0) return 'Amount must be greater than zero';
            if ($fee < 0 || $fee > $amount) return 'Fee must be between 0 and amount';
            if (($state['invCust'][$row['invoice_id']] ?? null) !== $row['customer_id']) return 'Invoice does not belong to this customer';

            $fp = $row['invoice_id'] . '|' . $row['payment_date'] . '|' . number_format($amount, 2, '.', '');
            if (isset($state['seenPay'][$fp])) return 'A payment of this amount already exists for this invoice on this date';

            $state['seq']++;
            $row['receipt_number'] = sprintf('RCP-%04d', $state['seq']);
            $row['net_amount']     = round($amount - $fee, 2);
            $row['cleared_at']     = ($row['status'] === 'cleared') ? date('Y-m-d H:i:s') : null;

            $state['seenPay'][$fp] = true;
            return null;
        },
        'after'   => function (int $id, array $row, mysqli $conn, array &$state) {
            $st  = $conn->prepare("INSERT INTO payment_invoice_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
            $iid = $row['invoice_id']; $amt = $row['amount'];
            $st->bind_param('iid', $id, $iid, $amt);
            $st->execute();
            $st->close();
            recomputeInvoiceFromAllocations($conn, $row['invoice_id']);
            syncPaymentLedger($conn, $id);
        },
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();
        $isCollectorOnly = isCollector();

        switch ($_GET['action']) {
            case 'importCsv':      rsImportRun($conn, paymentImportSpec());
            case 'importTemplate': rsImportTemplate(paymentImportSpec());

            case 'getPayments': {
                // filters
                $from    = trim($_GET['from'] ?? '');
                $to      = trim($_GET['to'] ?? '');
                $method  = trim($_GET['method'] ?? '');
                $status  = trim($_GET['status'] ?? '');
                $source  = trim($_GET['source'] ?? '');
                $col_id  = (int)($_GET['collector_id'] ?? 0);
                $area_id = (int)($_GET['area_id'] ?? 0);

                $sql = "SELECT p.id, p.receipt_number, p.invoice_id, p.customer_id, p.amount, p.fee, p.net_amount,
                               p.payment_method, p.payment_date, p.collected_by, p.reference_number, p.notes,
                               p.status, p.source, p.bank_name, p.cheque_number, p.cheque_date,
                               p.cleared_at, p.refunded_amount, p.refunded_at, p.refund_reason, p.bounce_reason,
                               p.deposit_slip_image, p.created_at,
                               p.bank_account_id, p.payer_name, p.payer_phone, p.payer_cnic,
                               p.verified_by_user_id, p.verified_at, p.bounce_charges,
                               p.refund_date, p.refund_reference, p.refunded_to_method,
                               p.is_advance, p.applied_to_advance_balance, p.currency,
                               p.whatsapp_sent, p.email_sent, p.sms_sent,
                               c.full_name AS customer_name, c.customer_code, c.area_id,
                               i.invoice_number,
                               u.name AS collected_by_name, u.username AS collected_by_username,
                               a.name AS area_name,
                               ba.bank_name AS recv_bank_name, ba.account_title AS recv_account_title, ba.account_number AS recv_account_number
                        FROM payments p
                        LEFT JOIN customers c ON p.customer_id = c.id
                        LEFT JOIN invoices  i ON p.invoice_id  = i.id
                        LEFT JOIN users     u ON p.collected_by = u.id
                        LEFT JOIN areas     a ON c.area_id = a.id
                        LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id
                        WHERE p.is_deleted = 0";
                $types = '';
                $params = [];

                // collector → only own
                if ($isCollectorOnly) {
                    $sql .= " AND p.collected_by = ?";
                    $types .= 'i';
                    $params[] = $user_id;
                }
                if ($from   !== '') { $sql .= " AND p.payment_date >= ?";  $types .= 's'; $params[] = $from; }
                if ($to     !== '') { $sql .= " AND p.payment_date <= ?";  $types .= 's'; $params[] = $to; }
                if ($method !== '') { $sql .= " AND p.payment_method = ?"; $types .= 's'; $params[] = $method; }
                if ($status !== '') { $sql .= " AND p.status = ?";         $types .= 's'; $params[] = $status; }
                if ($source !== '') { $sql .= " AND p.source = ?";         $types .= 's'; $params[] = $source; }
                if ($col_id > 0 && !$isCollectorOnly) { $sql .= " AND p.collected_by = ?"; $types .= 'i'; $params[] = $col_id; }
                if ($area_id > 0) { $sql .= " AND c.area_id = ?"; $types .= 'i'; $params[] = $area_id; }

                $sql .= " ORDER BY p.payment_date DESC, p.id DESC";

                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'                => (int)$r['id'],
                        'receipt_number'    => $r['receipt_number'],
                        'invoice_id'        => (int)$r['invoice_id'],
                        'invoice_number'    => $r['invoice_number'] ?? '',
                        'customer_id'       => (int)$r['customer_id'],
                        'customer_name'     => $r['customer_name'] ?? '',
                        'customer_code'     => $r['customer_code'] ?? '',
                        'amount'            => number_format((float)$r['amount'], 2, '.', ''),
                        'fee'               => number_format((float)$r['fee'], 2, '.', ''),
                        'net_amount'        => number_format((float)$r['net_amount'], 2, '.', ''),
                        'payment_method'    => $r['payment_method'],
                        'payment_date'      => $r['payment_date'],
                        'payment_date_disp' => date('M d, Y', strtotime($r['payment_date'])),
                        'collected_by'      => (int)$r['collected_by'],
                        'collected_by_name' => $r['collected_by_name'] ?: ($r['collected_by_username'] ?? ''),
                        'area_id'           => (int)($r['area_id'] ?? 0),
                        'area_name'         => $r['area_name'] ?? '',
                        'reference_number'  => $r['reference_number'] ?? '',
                        'notes'             => $r['notes'] ?? '',
                        'status'            => $r['status'] ?? 'cleared',
                        'source'            => $r['source'] ?? 'field',
                        'bank_name'         => $r['bank_name'] ?? '',
                        'cheque_number'     => $r['cheque_number'] ?? '',
                        'cheque_date'       => $r['cheque_date'] ?? '',
                        'refunded_amount'   => number_format((float)($r['refunded_amount'] ?? 0), 2, '.', ''),
                        'refund_reason'     => $r['refund_reason'] ?? '',
                        'bounce_reason'     => $r['bounce_reason'] ?? '',
                        // new fields
                        'bank_account_id'   => (int)($r['bank_account_id'] ?? 0),
                        'recv_account'      => $r['recv_bank_name']
                                                ? ($r['recv_bank_name'] . ' — ' . $r['recv_account_title'])
                                                : '',
                        'payer_name'        => $r['payer_name'] ?? '',
                        'payer_phone'       => $r['payer_phone'] ?? '',
                        'payer_cnic'        => $r['payer_cnic'] ?? '',
                        'verified_at'       => $r['verified_at'] ?? '',
                        'bounce_charges'    => number_format((float)($r['bounce_charges'] ?? 0), 2, '.', ''),
                        'refund_date'       => $r['refund_date'] ?? '',
                        'refund_reference'  => $r['refund_reference'] ?? '',
                        'refunded_to_method'=> $r['refunded_to_method'] ?? '',
                        'is_advance'        => (int)($r['is_advance'] ?? 0),
                        'applied_to_advance_balance' => number_format((float)($r['applied_to_advance_balance'] ?? 0), 2, '.', ''),
                        'currency'          => $r['currency'] ?? 'PKR',
                        'whatsapp_sent'     => (int)($r['whatsapp_sent'] ?? 0),
                        'email_sent'        => (int)($r['email_sent'] ?? 0),
                        'sms_sent'          => (int)($r['sms_sent'] ?? 0)
                    ];
                }
                $stmt->close();

                // summary cards (today + this month) — cleared only, same collector scope
                $todayQ = "SELECT IFNULL(SUM(amount),0) AS amt, COUNT(*) AS cnt FROM payments
                           WHERE is_deleted = 0 AND status = 'cleared' AND DATE(payment_date) = CURDATE()";
                $monthQ = "SELECT IFNULL(SUM(amount),0) AS amt, COUNT(*) AS cnt FROM payments
                           WHERE is_deleted = 0 AND status = 'cleared' AND YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())";
                if ($isCollectorOnly) {
                    $todayQ .= " AND collected_by = " . $user_id;
                    $monthQ .= " AND collected_by = " . $user_id;
                }
                $pendQ = "SELECT IFNULL(SUM(amount),0) AS amt, COUNT(*) AS cnt FROM payments
                          WHERE is_deleted = 0 AND status = 'pending'";
                if ($isCollectorOnly) $pendQ .= " AND collected_by = " . $user_id;
                $tRow = $conn->query($todayQ)->fetch_assoc();
                $mRow = $conn->query($monthQ)->fetch_assoc();
                $pRow = $conn->query($pendQ)->fetch_assoc();

                echo json_encode([
                    'success' => true,
                    'data'    => $rows,
                    'summary' => [
                        'today_total' => number_format((float)$tRow['amt'], 2, '.', ''),
                        'today_count' => (int)$tRow['cnt'],
                        'month_total' => number_format((float)$mRow['amt'], 2, '.', ''),
                        'month_count' => (int)$mRow['cnt'],
                        'pending_total' => number_format((float)$pRow['amt'], 2, '.', ''),
                        'pending_count' => (int)$pRow['cnt']
                    ]
                ]);
                exit();
            }

            case 'getPaymentDetail': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                $sql = "SELECT p.*,
                               c.full_name AS customer_name, c.customer_code, c.phone AS customer_phone,
                               c.email AS customer_email, c.address AS customer_address,
                               i.invoice_number, i.total AS invoice_total, i.billing_month, i.due_date,
                               u.name AS collected_by_name, u.username AS collected_by_username,
                               a.name AS area_name
                        FROM payments p
                        LEFT JOIN customers c ON p.customer_id = c.id
                        LEFT JOIN invoices  i ON p.invoice_id  = i.id
                        LEFT JOIN users     u ON p.collected_by = u.id
                        LEFT JOIN areas     a ON c.area_id = a.id
                        WHERE p.id = ? AND p.is_deleted = 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Payment not found']); exit(); }

                if ($isCollectorOnly && (int)$row['collected_by'] !== $user_id) {
                    echo json_encode(['success'=>false,'message'=>'Not allowed']); exit();
                }

                // allocations
                $a = $conn->prepare("SELECT a.id, a.invoice_id, a.amount, i.invoice_number, i.billing_month, i.total AS invoice_total
                                     FROM payment_invoice_allocations a
                                     LEFT JOIN invoices i ON a.invoice_id = i.id
                                     WHERE a.payment_id = ?
                                     ORDER BY a.id ASC");
                $a->bind_param("i", $id);
                $a->execute();
                $ar = $a->get_result();
                $allocs = [];
                while ($x = $ar->fetch_assoc()) {
                    $allocs[] = [
                        'id'             => (int)$x['id'],
                        'invoice_id'     => (int)$x['invoice_id'],
                        'invoice_number' => $x['invoice_number'] ?? '',
                        'billing_month'  => $x['billing_month'] ?? '',
                        'invoice_total'  => number_format((float)($x['invoice_total'] ?? 0), 2, '.', ''),
                        'amount'         => number_format((float)$x['amount'], 2, '.', '')
                    ];
                }
                $a->close();
                $row['allocations'] = $allocs;

                echo json_encode(['success'=>true,'data'=>$row]);
                exit();
            }

            case 'getCustomersWithDues': {
                $sql = "SELECT DISTINCT c.id, c.full_name, c.customer_code, c.phone
                        FROM customers c
                        INNER JOIN invoices i ON i.customer_id = c.id AND i.is_deleted = 0
                        WHERE c.is_deleted = 0 AND i.status IN ('unpaid','partial','overdue')
                        ORDER BY c.full_name ASC";
                $res = $conn->query($sql);
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $out[] = [
                        'id'   => (int)$r['id'],
                        'label'=> $r['full_name'] . ' (' . $r['customer_code'] . ')',
                        'phone'=> $r['phone'] ?? ''
                    ];
                }
                echo json_encode(['success'=>true,'data'=>$out]);
                exit();
            }

            case 'getCustomerInvoices': {
                $cid = (int)($_GET['customer_id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success'=>true,'data'=>[]]); exit(); }
                // use balance_amount column directly
                $sql = "SELECT i.id, i.invoice_number, i.total, i.paid_amount, i.balance_amount, i.due_date, i.status, i.billing_month
                        FROM invoices i
                        WHERE i.customer_id = ? AND i.is_deleted = 0
                          AND i.status IN ('unpaid','partial','overdue')
                        ORDER BY i.due_date ASC, i.id ASC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $res = $stmt->get_result();
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $bal = (float)$r['balance_amount'];
                    if ($bal <= 0) $bal = max(0, (float)$r['total'] - (float)$r['paid_amount']);
                    $out[] = [
                        'id'             => (int)$r['id'],
                        'invoice_number' => $r['invoice_number'],
                        'total'          => number_format((float)$r['total'], 2, '.', ''),
                        'paid'           => number_format((float)$r['paid_amount'], 2, '.', ''),
                        'balance_amount' => number_format($bal, 2, '.', ''),
                        'outstanding'    => number_format($bal, 2, '.', ''),
                        'due_date'       => $r['due_date'],
                        'status'         => $r['status'],
                        'billing_month'  => $r['billing_month'],
                        'label'          => $r['invoice_number'] . ' — ' . $r['billing_month'] . ' (Bal: ' . number_format($bal, 2) . ')'
                    ];
                }
                $stmt->close();
                echo json_encode(['success'=>true,'data'=>$out]);
                exit();
            }

            case 'getCollectors': {
                if ($isCollectorOnly) { echo json_encode(['success'=>true,'data'=>[]]); exit(); }
                $sql = "SELECT id, name, username, role FROM users
                        WHERE " . roleInSql('users', 'payments', 'v') . "
                          AND is_deleted = 0
                        ORDER BY name ASC, username ASC";
                $res = $conn->query($sql);
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $nm = $r['name'] ?: $r['username'];
                    $out[] = ['id' => (int)$r['id'], 'label' => $nm . ' (' . getRoleDisplay($r['role']) . ')'];
                }
                echo json_encode(['success'=>true,'data'=>$out]);
                exit();
            }

            case 'getAreas': {
                $res = $conn->query("SELECT id, name FROM areas WHERE is_deleted = 0 AND status = 'active' ORDER BY name ASC");
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $out[] = ['id' => (int)$r['id'], 'name' => $r['name']];
                }
                echo json_encode(['success'=>true,'data'=>$out]);
                exit();
            }

            case 'getBankAccounts': {
                // company own accounts for "Receiving Account" dropdown
                $res = $conn->query("SELECT id, bank_name, account_title, account_number, account_type
                                     FROM bank_accounts
                                     WHERE is_deleted = 0 AND status = 'active'
                                     ORDER BY is_default DESC, bank_name ASC");
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $label = $r['bank_name'] . ' — ' . $r['account_title'] . ' (' . $r['account_number'] . ')';
                    $out[] = [
                        'id'    => (int)$r['id'],
                        'label' => $label,
                        'type'  => $r['account_type']
                    ];
                }
                echo json_encode(['success'=>true,'data'=>$out]);
                exit();
            }

            case 'addPayment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit();
                }
                $customer_id     = (int)($_POST['customer_id'] ?? 0);
                $amount          = (float)($_POST['amount'] ?? 0);
                $fee             = (float)($_POST['fee'] ?? 0);
                $method          = trim($_POST['payment_method'] ?? '');
                $pdate           = trim($_POST['payment_date'] ?? '');
                $reference       = trim($_POST['reference_number'] ?? '');
                $notes           = trim($_POST['notes'] ?? '');
                $status          = trim($_POST['status'] ?? 'cleared');
                $source          = trim($_POST['source'] ?? 'field');
                $bank_name       = trim($_POST['bank_name'] ?? '');
                $cheque_number   = trim($_POST['cheque_number'] ?? '');
                $cheque_date     = trim($_POST['cheque_date'] ?? '');
                // new manual-collection fields
                $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);
                $payer_name      = trim($_POST['payer_name'] ?? '');
                $payer_phone     = trim($_POST['payer_phone'] ?? '');
                $payer_cnic      = trim($_POST['payer_cnic'] ?? '');
                $is_advance      = !empty($_POST['is_advance']) ? 1 : 0;
                $applied_adv     = (float)($_POST['applied_to_advance_balance'] ?? 0);
                $currency_code   = strtoupper(trim($_POST['currency'] ?? 'PKR'));
                $whatsapp_sent   = !empty($_POST['whatsapp_sent']) ? 1 : 0;
                $email_sent      = !empty($_POST['email_sent']) ? 1 : 0;
                $sms_sent        = !empty($_POST['sms_sent']) ? 1 : 0;

                // allocations array
                $allocations = [];
                if (isset($_POST['allocations']) && is_array($_POST['allocations'])) {
                    foreach ($_POST['allocations'] as $a) {
                        $iid = (int)($a['invoice_id'] ?? 0);
                        $amt = (float)($a['amount'] ?? 0);
                        if ($iid > 0 && $amt > 0) $allocations[] = ['invoice_id'=>$iid, 'amount'=>$amt];
                    }
                }

                // validate
                if ($customer_id <= 0) { echo json_encode(['success'=>false,'message'=>'Customer is required']); exit(); }
                if ($amount <= 0)      { echo json_encode(['success'=>false,'message'=>'Amount must be greater than 0']); exit(); }
                if ($fee < 0 || $fee > $amount) { echo json_encode(['success'=>false,'message'=>'Fee must be between 0 and amount']); exit(); }
                if (!in_array($method, ['cash','bank_transfer','easypaisa','jazzcash','cheque'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid payment method']); exit();
                }
                if (!in_array($status, ['cleared','pending','bounced','refunded'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid status']); exit();
                }
                if (!in_array($source, ['field','portal','auto_debit','api','office'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid source']); exit();
                }
                if (!in_array($currency_code, ['PKR','USD','EUR'], true)) $currency_code = 'PKR';
                if ($pdate === '' || !strtotime($pdate)) {
                    echo json_encode(['success'=>false,'message'=>'Valid payment date required']); exit();
                }
                // advance mode: no invoices required; otherwise must allocate
                if (!$is_advance && empty($allocations)) {
                    echo json_encode(['success'=>false,'message'=>'Please allocate to at least one invoice']); exit();
                }
                // sum check (skip for advance)
                if (!$is_advance) {
                    $sumAlloc = 0; foreach ($allocations as $a) $sumAlloc += $a['amount'];
                    if (abs($sumAlloc - $amount) > 0.01) {
                        echo json_encode(['success'=>false,'message'=>'Allocations ('.number_format($sumAlloc,2).') must equal payment amount ('.number_format($amount,2).')']); exit();
                    }
                }

                // verify all invoices belong to this customer
                $invIds = array_map(fn($a) => $a['invoice_id'], $allocations);
                if (!empty($invIds)) {
                    $placeholders = implode(',', array_fill(0, count($invIds), '?'));
                    $vsql = "SELECT id FROM invoices WHERE id IN ($placeholders) AND customer_id = ? AND is_deleted = 0";
                    $vstmt = $conn->prepare($vsql);
                    $vtypes = str_repeat('i', count($invIds)) . 'i';
                    $vparams = array_merge($invIds, [$customer_id]);
                    $vstmt->bind_param($vtypes, ...$vparams);
                    $vstmt->execute();
                    $vfound = $vstmt->get_result()->num_rows;
                    $vstmt->close();
                    if ($vfound !== count($invIds)) {
                        echo json_encode(['success'=>false,'message'=>'One or more invoices do not belong to this customer']); exit();
                    }
                }

                $rcpt        = nextReceiptNumber($conn);
                $net_amount  = $amount - $fee;
                $primary_inv = !empty($allocations) ? $allocations[0]['invoice_id'] : 0;
                $cleared_at  = ($status === 'cleared') ? date('Y-m-d H:i:s') : null;

                // nullables for cheque/bank
                $bk  = $bank_name     === '' ? null : $bank_name;
                $cn  = $cheque_number === '' ? null : $cheque_number;
                $cd  = ($cheque_date === '' || !strtotime($cheque_date)) ? null : $cheque_date;
                $bai = $bank_account_id > 0 ? $bank_account_id : null;
                $pn  = $payer_name  === '' ? null : $payer_name;
                $pp  = $payer_phone === '' ? null : $payer_phone;
                $pc  = $payer_cnic  === '' ? null : $payer_cnic;

                $conn->begin_transaction();
                try {
                    // lock invoice rows to prevent race on recompute
                    if (!empty($invIds)) {
                        $lockPh = implode(',', array_fill(0, count($invIds), '?'));
                        $lock = $conn->prepare("SELECT id FROM invoices WHERE id IN ($lockPh) FOR UPDATE");
                        $lock->bind_param(str_repeat('i', count($invIds)), ...$invIds);
                        $lock->execute();
                        $lock->close();
                    }

                    // cols: rcpt(s), primary_inv(i), customer_id(i), amount(d), fee(d), net_amount(d),
                    //       method(s), pdate(s), user_id(i), reference(s), notes(s),
                    //       status(s), source(s), bk(s), cn(s), cd(s), cleared_at(s),
                    //       bai(i), pn(s), pp(s), pc(s), is_advance(i), applied_adv(d), currency(s),
                    //       whatsapp_sent(i), email_sent(i), sms_sent(i)
                    // types: s i i d d d   s s i s s   s s s s s s   i s s s i d s   i i i  = 27
                    $stmt = $conn->prepare("INSERT INTO payments
                        (receipt_number, invoice_id, customer_id, amount, fee, net_amount,
                         payment_method, payment_date, collected_by, reference_number, notes,
                         status, source, bank_name, cheque_number, cheque_date, cleared_at,
                         bank_account_id, payer_name, payer_phone, payer_cnic,
                         is_advance, applied_to_advance_balance, currency,
                         whatsapp_sent, email_sent, sms_sent)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    // type string: 17 base + 4 payer (bai i, pn/pp/pc s) + 3 advance/curr (i d s) + 3 comms (i i i) = 27
                    $types = "siidddssissssssss" . "isss" . "ids" . "iii";
                    if (strlen($types) !== 27) {
                        throw new Exception('Type string length mismatch: expected 27 got ' . strlen($types));
                    }
                    $stmt->bind_param($types,
                        $rcpt, $primary_inv, $customer_id, $amount, $fee, $net_amount,
                        $method, $pdate, $user_id, $reference, $notes,
                        $status, $source, $bk, $cn, $cd, $cleared_at,
                        $bai, $pn, $pp, $pc,
                        $is_advance, $applied_adv, $currency_code,
                        $whatsapp_sent, $email_sent, $sms_sent);
                    if (!$stmt->execute()) {
                        $err = $stmt->error; $stmt->close();
                        throw new Exception('Insert failed: ' . $err);
                    }
                    $newId = $stmt->insert_id;
                    $stmt->close();

                    // insert allocations
                    $aStmt = $conn->prepare("INSERT INTO payment_invoice_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
                    foreach ($allocations as $a) {
                        $iid = $a['invoice_id']; $amt = $a['amount'];
                        $aStmt->bind_param("iid", $newId, $iid, $amt);
                        if (!$aStmt->execute()) {
                            $err = $aStmt->error; $aStmt->close();
                            throw new Exception('Allocation insert failed: ' . $err);
                        }
                    }
                    $aStmt->close();

                    // recompute each invoice
                    foreach ($invIds as $iid) {
                        recomputeInvoiceFromAllocations($conn, $iid);
                    }

                    $conn->commit();

                    syncPaymentLedger($conn, $newId);
                    logActivity($user_id, $username, 'Payment Recorded', "Receipt $rcpt — Amt $amount ($status) via $method, " . count($allocations) . " inv(s)");
                    try { createNotificationForAdmins('Payment Recorded', "Receipt $rcpt of " . number_format($amount, 2) . " collected.", 'success', 'payments.php?id=' . $newId); } catch (Exception $e) {}

                    echo json_encode(['success'=>true, 'message'=>'Payment recorded — Receipt ' . $rcpt, 'id'=>$newId, 'receipt_number'=>$rcpt]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success'=>false,'message'=>'Failed to save: ' . $e->getMessage()]);
                }
                exit();
            }

            case 'updatePayment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit();
                }
                if (!canViewFinances()) {
                    echo json_encode(['success'=>false,'message'=>'You are not allowed to edit payments']); exit();
                }
                $id              = (int)($_POST['id'] ?? 0);
                $customer_id     = (int)($_POST['customer_id'] ?? 0);
                $amount          = (float)($_POST['amount'] ?? 0);
                $fee             = (float)($_POST['fee'] ?? 0);
                $method          = trim($_POST['payment_method'] ?? '');
                $pdate           = trim($_POST['payment_date'] ?? '');
                $reference       = trim($_POST['reference_number'] ?? '');
                $notes           = trim($_POST['notes'] ?? '');
                $status          = trim($_POST['status'] ?? 'cleared');
                $source          = trim($_POST['source'] ?? 'field');
                $bank_name       = trim($_POST['bank_name'] ?? '');
                $cheque_number   = trim($_POST['cheque_number'] ?? '');
                $cheque_date     = trim($_POST['cheque_date'] ?? '');
                // new fields
                $bank_account_id = (int)($_POST['bank_account_id'] ?? 0);
                $payer_name      = trim($_POST['payer_name'] ?? '');
                $payer_phone     = trim($_POST['payer_phone'] ?? '');
                $payer_cnic      = trim($_POST['payer_cnic'] ?? '');
                $currency_code   = strtoupper(trim($_POST['currency'] ?? 'PKR'));
                if (!in_array($currency_code, ['PKR','USD','EUR'], true)) $currency_code = 'PKR';

                $allocations = [];
                if (isset($_POST['allocations']) && is_array($_POST['allocations'])) {
                    foreach ($_POST['allocations'] as $a) {
                        $iid = (int)($a['invoice_id'] ?? 0);
                        $amt = (float)($a['amount'] ?? 0);
                        if ($iid > 0 && $amt > 0) $allocations[] = ['invoice_id'=>$iid, 'amount'=>$amt];
                    }
                }

                if ($id <= 0)          { echo json_encode(['success'=>false,'message'=>'Invalid payment id']); exit(); }
                if ($customer_id <= 0) { echo json_encode(['success'=>false,'message'=>'Customer is required']); exit(); }
                if ($amount <= 0)      { echo json_encode(['success'=>false,'message'=>'Amount must be greater than 0']); exit(); }
                if ($fee < 0 || $fee > $amount) { echo json_encode(['success'=>false,'message'=>'Fee must be between 0 and amount']); exit(); }
                if (!in_array($method, ['cash','bank_transfer','easypaisa','jazzcash','cheque'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid payment method']); exit();
                }
                if (!in_array($status, ['cleared','pending','bounced','refunded'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid status']); exit();
                }
                if (!in_array($source, ['field','portal','auto_debit','api','office'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid source']); exit();
                }
                if ($pdate === '' || !strtotime($pdate)) { echo json_encode(['success'=>false,'message'=>'Valid date required']); exit(); }
                if (empty($allocations)) { echo json_encode(['success'=>false,'message'=>'At least one allocation required']); exit(); }
                $sumAlloc = 0; foreach ($allocations as $a) $sumAlloc += $a['amount'];
                if (abs($sumAlloc - $amount) > 0.01) {
                    echo json_encode(['success'=>false,'message'=>'Allocations ('.number_format($sumAlloc,2).') must equal payment amount ('.number_format($amount,2).')']); exit();
                }

                // grab old row
                $old = $conn->prepare("SELECT receipt_number, status, cleared_at FROM payments WHERE id = ? AND is_deleted = 0");
                $old->bind_param("i", $id);
                $old->execute();
                $oldRow = $old->get_result()->fetch_assoc();
                $old->close();
                if (!$oldRow) { echo json_encode(['success'=>false,'message'=>'Payment not found']); exit(); }

                // existing allocations — for recomputation later
                $oa = $conn->prepare("SELECT invoice_id FROM payment_invoice_allocations WHERE payment_id = ?");
                $oa->bind_param("i", $id);
                $oa->execute();
                $oar = $oa->get_result();
                $oldInvIds = [];
                while ($x = $oar->fetch_assoc()) $oldInvIds[] = (int)$x['invoice_id'];
                $oa->close();

                // verify new invoices
                $invIds = array_map(fn($a) => $a['invoice_id'], $allocations);
                $placeholders = implode(',', array_fill(0, count($invIds), '?'));
                $vsql = "SELECT id FROM invoices WHERE id IN ($placeholders) AND customer_id = ? AND is_deleted = 0";
                $vstmt = $conn->prepare($vsql);
                $vtypes = str_repeat('i', count($invIds)) . 'i';
                $vparams = array_merge($invIds, [$customer_id]);
                $vstmt->bind_param($vtypes, ...$vparams);
                $vstmt->execute();
                $vfound = $vstmt->get_result()->num_rows;
                $vstmt->close();
                if ($vfound !== count($invIds)) {
                    echo json_encode(['success'=>false,'message'=>'One or more invoices do not belong to this customer']); exit();
                }

                $net_amount  = $amount - $fee;
                $primary_inv = $allocations[0]['invoice_id'];

                // cleared_at logic: set when status flips TO cleared and was null; preserve when flipping away
                $cleared_at = $oldRow['cleared_at'];
                if ($status === 'cleared' && empty($cleared_at)) {
                    $cleared_at = date('Y-m-d H:i:s');
                }

                $bk  = $bank_name     === '' ? null : $bank_name;
                $cn  = $cheque_number === '' ? null : $cheque_number;
                $cd  = ($cheque_date === '' || !strtotime($cheque_date)) ? null : $cheque_date;
                $bai = $bank_account_id > 0 ? $bank_account_id : null;
                $pn  = $payer_name  === '' ? null : $payer_name;
                $pp  = $payer_phone === '' ? null : $payer_phone;
                $pc  = $payer_cnic  === '' ? null : $payer_cnic;

                $conn->begin_transaction();
                try {
                    // lock invoice rows (old + new union) to prevent recompute race
                    $allInv = array_unique(array_merge($oldInvIds, $invIds));
                    if (!empty($allInv)) {
                        $lockPh = implode(',', array_fill(0, count($allInv), '?'));
                        $lock = $conn->prepare("SELECT id FROM invoices WHERE id IN ($lockPh) FOR UPDATE");
                        $lock->bind_param(str_repeat('i', count($allInv)), ...$allInv);
                        $lock->execute();
                        $lock->close();
                    }

                    $stmt = $conn->prepare("UPDATE payments SET
                        invoice_id = ?, customer_id = ?, amount = ?, fee = ?, net_amount = ?,
                        payment_method = ?, payment_date = ?, reference_number = ?, notes = ?,
                        status = ?, source = ?, bank_name = ?, cheque_number = ?, cheque_date = ?,
                        cleared_at = ?,
                        bank_account_id = ?, payer_name = ?, payer_phone = ?, payer_cnic = ?,
                        currency = ?
                        WHERE id = ? AND is_deleted = 0");
                    // 15 base + 5 new (i s s s s) + 1 id (i) = 21
                    $upTypes = "iiddd" . "ssss" . "sssss" . "s" . "isss" . "s" . "i";
                    if (strlen($upTypes) !== 21) {
                        throw new Exception('Update type string length mismatch: ' . strlen($upTypes));
                    }
                    $stmt->bind_param($upTypes,
                        $primary_inv, $customer_id, $amount, $fee, $net_amount,
                        $method, $pdate, $reference, $notes,
                        $status, $source, $bk, $cn, $cd,
                        $cleared_at,
                        $bai, $pn, $pp, $pc,
                        $currency_code,
                        $id);
                    if (!$stmt->execute()) {
                        $err = $stmt->error; $stmt->close();
                        throw new Exception('Update failed: ' . $err);
                    }
                    $stmt->close();

                    // wipe + reinsert allocations
                    $del = $conn->prepare("DELETE FROM payment_invoice_allocations WHERE payment_id = ?");
                    $del->bind_param("i", $id);
                    $del->execute();
                    $del->close();

                    $aStmt = $conn->prepare("INSERT INTO payment_invoice_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
                    foreach ($allocations as $a) {
                        $iid = $a['invoice_id']; $amt = $a['amount'];
                        $aStmt->bind_param("iid", $id, $iid, $amt);
                        if (!$aStmt->execute()) {
                            $err = $aStmt->error; $aStmt->close();
                            throw new Exception('Allocation insert failed: ' . $err);
                        }
                    }
                    $aStmt->close();

                    // recompute affected invoices: union of old + new
                    $affected = array_unique(array_merge($oldInvIds, $invIds));
                    foreach ($affected as $iid) {
                        recomputeInvoiceFromAllocations($conn, $iid);
                    }

                    $conn->commit();

                    syncPaymentLedger($conn, $id);
                    logActivity($user_id, $username, 'Payment Updated', "Receipt " . $oldRow['receipt_number'] . " updated — Amt $amount ($status)");
                    echo json_encode(['success'=>true,'message'=>'Payment updated']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success'=>false,'message'=>'Failed to update: ' . $e->getMessage()]);
                }
                exit();
            }

            case 'deletePayment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit();
                }
                if (!can('payments', 'd')) {
                    echo json_encode(['success'=>false,'message'=>'Only super admin can delete payments']); exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                // grab affected invoices
                $g = $conn->prepare("SELECT receipt_number FROM payments WHERE id = ? AND is_deleted = 0");
                $g->bind_param("i", $id);
                $g->execute();
                $row = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Payment not found']); exit(); }

                $oa = $conn->prepare("SELECT invoice_id FROM payment_invoice_allocations WHERE payment_id = ?");
                $oa->bind_param("i", $id);
                $oa->execute();
                $oar = $oa->get_result();
                $invIds = [];
                while ($x = $oar->fetch_assoc()) $invIds[] = (int)$x['invoice_id'];
                $oa->close();

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE payments SET is_deleted = 1 WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    if (!$stmt->execute()) {
                        $err = $stmt->error; $stmt->close();
                        throw new Exception('Delete failed: ' . $err);
                    }
                    $stmt->close();

                    foreach ($invIds as $iid) {
                        recomputeInvoiceFromAllocations($conn, $iid);
                    }
                    $conn->commit();

                    syncPaymentLedger($conn, $id);
                    logActivity($user_id, $username, 'Payment Deleted', "Receipt " . $row['receipt_number'] . " deleted");
                    echo json_encode(['success'=>true,'message'=>'Payment deleted']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success'=>false,'message'=>'Failed to delete: ' . $e->getMessage()]);
                }
                exit();
            }

            case 'markPaymentCleared': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit();
                }
                if (!isAdminLevel()) {
                    echo json_encode(['success'=>false,'message'=>'Admin only']); exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                $g = $conn->prepare("SELECT receipt_number, status, cleared_at FROM payments WHERE id = ? AND is_deleted = 0");
                $g->bind_param("i", $id);
                $g->execute();
                $row = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Payment not found']); exit(); }
                if ($row['status'] !== 'pending') {
                    echo json_encode(['success'=>false,'message'=>'Only pending payments can be cleared']); exit();
                }

                // grab invoices
                $oa = $conn->prepare("SELECT invoice_id FROM payment_invoice_allocations WHERE payment_id = ?");
                $oa->bind_param("i", $id);
                $oa->execute();
                $oar = $oa->get_result();
                $invIds = [];
                while ($x = $oar->fetch_assoc()) $invIds[] = (int)$x['invoice_id'];
                $oa->close();

                $conn->begin_transaction();
                try {
                    $now = date('Y-m-d H:i:s');
                    $cleared = empty($row['cleared_at']) ? $now : $row['cleared_at'];
                    $u = $conn->prepare("UPDATE payments SET status = 'cleared', cleared_at = ? WHERE id = ?");
                    $u->bind_param("si", $cleared, $id);
                    if (!$u->execute()) {
                        $err = $u->error; $u->close();
                        throw new Exception('Update failed: ' . $err);
                    }
                    $u->close();

                    foreach ($invIds as $iid) {
                        recomputeInvoiceFromAllocations($conn, $iid);
                    }
                    $conn->commit();

                    syncPaymentLedger($conn, $id);
                    logActivity($user_id, $username, 'Payment Cleared', "Receipt " . $row['receipt_number'] . " marked cleared");
                    echo json_encode(['success'=>true,'message'=>'Payment marked cleared']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success'=>false,'message'=>'Failed: ' . $e->getMessage()]);
                }
                exit();
            }

            case 'markPaymentBounced': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit();
                }
                if (!isAdminLevel()) {
                    echo json_encode(['success'=>false,'message'=>'Admin only']); exit();
                }
                $id      = (int)($_POST['id'] ?? 0);
                $reason  = trim($_POST['bounce_reason'] ?? '');
                $charges = (float)($_POST['bounce_charges'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                if ($reason === '') { echo json_encode(['success'=>false,'message'=>'Bounce reason is required']); exit(); }
                if ($charges < 0) $charges = 0;

                $g = $conn->prepare("SELECT receipt_number, status FROM payments WHERE id = ? AND is_deleted = 0");
                $g->bind_param("i", $id);
                $g->execute();
                $row = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Payment not found']); exit(); }
                if ($row['status'] === 'bounced') {
                    echo json_encode(['success'=>false,'message'=>'Already bounced']); exit();
                }

                $oa = $conn->prepare("SELECT invoice_id FROM payment_invoice_allocations WHERE payment_id = ?");
                $oa->bind_param("i", $id);
                $oa->execute();
                $oar = $oa->get_result();
                $invIds = [];
                while ($x = $oar->fetch_assoc()) $invIds[] = (int)$x['invoice_id'];
                $oa->close();

                $conn->begin_transaction();
                try {
                    if (!empty($invIds)) {
                        $lockPh = implode(',', array_fill(0, count($invIds), '?'));
                        $lock = $conn->prepare("SELECT id FROM invoices WHERE id IN ($lockPh) FOR UPDATE");
                        $lock->bind_param(str_repeat('i', count($invIds)), ...$invIds);
                        $lock->execute();
                        $lock->close();
                    }
                    $u = $conn->prepare("UPDATE payments SET status = 'bounced', bounce_reason = ?, bounce_charges = ? WHERE id = ?");
                    $u->bind_param("sdi", $reason, $charges, $id);
                    if (!$u->execute()) {
                        $err = $u->error; $u->close();
                        throw new Exception('Update failed: ' . $err);
                    }
                    $u->close();

                    foreach ($invIds as $iid) {
                        recomputeInvoiceFromAllocations($conn, $iid);
                    }
                    $conn->commit();

                    syncPaymentLedger($conn, $id);
                    logActivity($user_id, $username, 'Payment Bounced', "Receipt " . $row['receipt_number'] . " bounced — $reason");
                    echo json_encode(['success'=>true,'message'=>'Payment marked bounced']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success'=>false,'message'=>'Failed: ' . $e->getMessage()]);
                }
                exit();
            }

            case 'verifyPayment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit();
                }
                if (!isAdminLevel()) {
                    echo json_encode(['success'=>false,'message'=>'Admin/accountant only']); exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                $g = $conn->prepare("SELECT receipt_number, verified_at FROM payments WHERE id = ? AND is_deleted = 0");
                $g->bind_param("i", $id);
                $g->execute();
                $row = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Payment not found']); exit(); }
                if (!empty($row['verified_at'])) {
                    echo json_encode(['success'=>false,'message'=>'Already verified']); exit();
                }

                $now = date('Y-m-d H:i:s');
                $u = $conn->prepare("UPDATE payments SET verified_by_user_id = ?, verified_at = ? WHERE id = ?");
                $u->bind_param("isi", $user_id, $now, $id);
                if (!$u->execute()) {
                    $err = $u->error; $u->close();
                    echo json_encode(['success'=>false,'message'=>'Verify failed: ' . $err]); exit();
                }
                $u->close();

                logActivity($user_id, $username, 'Payment Verified', "Receipt " . $row['receipt_number'] . " verified");
                echo json_encode(['success'=>true,'message'=>'Payment verified']);
                exit();
            }

            case 'refundPayment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit();
                }
                if (!isAdminLevel()) {
                    echo json_encode(['success'=>false,'message'=>'Admin only']); exit();
                }
                $id      = (int)($_POST['id'] ?? 0);
                $refAmt  = (float)($_POST['refund_amount'] ?? 0);
                $reason  = trim($_POST['refund_reason'] ?? '');
                $refMethod = trim($_POST['refunded_to_method'] ?? '');
                $refRef    = trim($_POST['refund_reference'] ?? '');
                $refDate   = trim($_POST['refund_date'] ?? '');
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                if ($refAmt <= 0) { echo json_encode(['success'=>false,'message'=>'Refund amount must be greater than 0']); exit(); }
                if ($reason === '') { echo json_encode(['success'=>false,'message'=>'Refund reason is required']); exit(); }
                if ($refMethod !== '' && !in_array($refMethod, ['cash','bank_transfer','cheque','online','jazzcash','easypaisa'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid refund method']); exit();
                }
                if ($refDate !== '' && !strtotime($refDate)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid refund date']); exit();
                }

                $g = $conn->prepare("SELECT receipt_number, amount, status FROM payments WHERE id = ? AND is_deleted = 0");
                $g->bind_param("i", $id);
                $g->execute();
                $row = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Payment not found']); exit(); }
                if ($refAmt > (float)$row['amount']) {
                    echo json_encode(['success'=>false,'message'=>'Refund cannot exceed payment amount']); exit();
                }

                $oa = $conn->prepare("SELECT invoice_id FROM payment_invoice_allocations WHERE payment_id = ?");
                $oa->bind_param("i", $id);
                $oa->execute();
                $oar = $oa->get_result();
                $invIds = [];
                while ($x = $oar->fetch_assoc()) $invIds[] = (int)$x['invoice_id'];
                $oa->close();

                $conn->begin_transaction();
                try {
                    if (!empty($invIds)) {
                        $lockPh = implode(',', array_fill(0, count($invIds), '?'));
                        $lock = $conn->prepare("SELECT id FROM invoices WHERE id IN ($lockPh) FOR UPDATE");
                        $lock->bind_param(str_repeat('i', count($invIds)), ...$invIds);
                        $lock->execute();
                        $lock->close();
                    }
                    $now  = date('Y-m-d H:i:s');
                    $rdt  = $refDate === '' ? null : $refDate;
                    $rmth = $refMethod === '' ? null : $refMethod;
                    $rrf  = $refRef === '' ? null : $refRef;
                    $u = $conn->prepare("UPDATE payments SET status = 'refunded', refunded_amount = ?, refunded_at = ?,
                                                              refund_reason = ?, refund_date = ?, refund_reference = ?,
                                                              refunded_to_method = ?
                                         WHERE id = ?");
                    // d s s s s s i = 7 (refAmt, now, reason, rdt, rrf, rmth, id)
                    $u->bind_param("dsssss" . "i", $refAmt, $now, $reason, $rdt, $rrf, $rmth, $id);
                    if (!$u->execute()) {
                        $err = $u->error; $u->close();
                        throw new Exception('Update failed: ' . $err);
                    }
                    $u->close();

                    foreach ($invIds as $iid) {
                        recomputeInvoiceFromAllocations($conn, $iid);
                    }
                    $conn->commit();

                    syncPaymentLedger($conn, $id);
                    logActivity($user_id, $username, 'Payment Refunded', "Receipt " . $row['receipt_number'] . " refunded $refAmt — $reason");
                    echo json_encode(['success'=>true,'message'=>'Payment refunded']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success'=>false,'message'=>'Failed: ' . $e->getMessage()]);
                }
                exit();
            }

            default:
                echo json_encode(['success'=>false,'message'=>'Invalid action']); exit();
        }
    } catch (Exception $e) {
        error_log("Payments.php error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// detail view (?id=X) — fetch server-side for SSR receipt
$detail   = null;
$detailAllocs = [];
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($detailId > 0) {
    $conn = getDBConnection();
    $sql = "SELECT p.*,
                   c.full_name AS customer_name, c.customer_code, c.phone AS customer_phone,
                   c.email AS customer_email, c.address AS customer_address,
                   i.invoice_number, i.total AS invoice_total, i.billing_month, i.due_date,
                   u.name AS collected_by_name, u.username AS collected_by_username,
                   a.name AS area_name
            FROM payments p
            LEFT JOIN customers c ON p.customer_id = c.id
            LEFT JOIN invoices  i ON p.invoice_id  = i.id
            LEFT JOIN users     u ON p.collected_by = u.id
            LEFT JOIN areas     a ON c.area_id = a.id
            WHERE p.id = ? AND p.is_deleted = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $detailId);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // collector — own only
    if ($detail && isCollector() && (int)$detail['collected_by'] !== $user_id) {
        $detail = null;
    }

    // allocations for this payment
    if ($detail) {
        $a = $conn->prepare("SELECT a.id, a.invoice_id, a.amount, i.invoice_number, i.billing_month, i.total AS invoice_total
                             FROM payment_invoice_allocations a
                             LEFT JOIN invoices i ON a.invoice_id = i.id
                             WHERE a.payment_id = ?
                             ORDER BY a.id ASC");
        $a->bind_param("i", $detailId);
        $a->execute();
        $ar = $a->get_result();
        while ($x = $ar->fetch_assoc()) $detailAllocs[] = $x;
        $a->close();
    }
}

$canDelete  = can('payments', 'd');
$canEdit    = can('payments', 'e');
$isAdminLvl = can('payments', 'e'); // positive check — an unknown role is no longer admin by default

// branding + currency
$branding  = getSiteBranding();
$currency  = getSetting('currency_symbol', 'PKR');
$siteName  = $branding['site_name'];
$siteLogo  = $branding['site_logo'];
$compPhone = getSetting('company_phone', '');
$compEmail = getSetting('company_email', '');
$compAddr  = getSetting('company_address', '');
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?php echo $detailId > 0 ? 'Receipt' : 'Payments'; ?> - ISP Billing</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <?php if ($detailId > 0): ?>
                    <a href="payments.php">Payments</a>
                    <span class="breadcrumb-sep">/</span>
                    <span>Receipt <?php echo htmlspecialchars($detail['receipt_number'] ?? '#' . $detailId); ?></span>
                <?php else: ?>
                    <span>Payments</span>
                <?php endif; ?>
            </div>
            <div class="header">
                <h1>
                    <i class="fas fa-money-bill-wave"></i>
                    <?php echo $detailId > 0 ? 'Payment Receipt' : 'Payments'; ?>
                </h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if ($detailId > 0): ?>
                <!-- ===== Receipt Detail / Print View ===== -->
                <?php if (!$detail): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-exclamation-circle"></i></div>
                        <div class="empty-state-title">Receipt not found</div>
                        <div class="empty-state-text">The receipt you requested does not exist or you don't have access.</div>
                        <a class="btn btn-primary" href="payments.php"><i class="fas fa-arrow-left"></i> Back to Payments</a>
                    </div>
                <?php else:
                    $payStatus = $detail['status'] ?? 'cleared';
                    $paySource = $detail['source'] ?? 'field';
                    $totalAlloc = 0; foreach ($detailAllocs as $da) $totalAlloc += (float)$da['amount'];
                ?>
                    <div class="printable-actions">
                        <a class="btn btn-secondary" href="payments.php"><i class="fas fa-arrow-left"></i> Back</a>
                        <?php if ($detail['invoice_id']): ?>
                            <a class="btn btn-primary" href="invoices.php?id=<?php echo (int)$detail['invoice_id']; ?>">
                                <i class="fas fa-file-invoice-dollar"></i> View Invoice
                            </a>
                        <?php endif; ?>
                        <button class="btn btn-success" onclick="window.print()"><i class="fas fa-print"></i> Print A4</button>
                        <button class="btn btn-secondary" onclick="printReceiptThermal()"><i class="fas fa-receipt"></i> Thermal</button>
                    </div>

                    <script>
                    // the 80mm slip prints the same receipt the A4 sheet shows
                    const RCPT = <?php echo json_encode([
                        'number'   => $detail['receipt_number'],
                        'date'     => date('d M Y', strtotime($detail['payment_date'])),
                        'customer' => $detail['customer_name'] ?? '—',
                        'code'     => $detail['customer_code'] ?? '',
                        'method'   => ucwords(str_replace('_', ' ', $detail['payment_method'])),
                        'ref'      => $detail['reference_number'] ?? '',
                        'taker'    => $detail['collected_by_name'] ?? ($detail['collected_by_username'] ?? ''),
                        'status'   => $payStatus,
                        'allocs'   => array_map(fn($a) => [
                            'invoice' => $a['invoice_number'] ?? '-',
                            'month'   => $a['billing_month'] ?? '',
                            'amount'  => $currency . ' ' . number_format((float)$a['amount'], 2)
                        ], $detailAllocs),
                        'alloc_total' => $currency . ' ' . number_format($totalAlloc, 2),
                        'fee'         => (float)($detail['fee'] ?? 0) > 0 ? $currency . ' ' . number_format((float)$detail['fee'], 2) : '',
                        'net'         => $currency . ' ' . number_format((float)$detail['net_amount'], 2),
                        'amount'      => $currency . ' ' . number_format((float)$detail['amount'], 2)
                    ]); ?>;

                    // ?print= lands here straight from the list row
                    window.addEventListener('load', function () {
                        const want = new URLSearchParams(location.search).get('print');
                        if (want) setTimeout(() => want === 'thermal' ? printReceiptThermal() : window.print(), 350);
                    });

                    function printReceiptThermal() {
                        rsThermalPrint({
                            title: 'PAYMENT RECEIPT',
                            meta: [['Receipt', RCPT.number], ['Date', RCPT.date], ['Customer', RCPT.customer],
                                   ['Code', RCPT.code], ['Method', RCPT.method], ['Ref', RCPT.ref], ['Received by', RCPT.taker]],
                            items: RCPT.allocs.map(a => ({ name: 'Invoice ' + a.invoice + (a.month ? ' (' + a.month + ')' : ''),
                                                           qty: '1', unit: '', price: a.amount, total: a.amount })),
                            sums: [
                                ['Amount Received', RCPT.amount],
                                RCPT.allocs.length ? ['Allocated', RCPT.alloc_total] : null,
                                RCPT.fee ? ['Fee', RCPT.fee] : null,
                                ['NET', RCPT.net, 'grand']
                            ],
                            note: RCPT.status === 'bounced' ? '*** BOUNCED ***' : (RCPT.status === 'refunded' ? '*** REFUNDED ***' : ''),
                            footer: 'Payment received with thanks<br>Keep this slip for your record'
                        });
                    }
                    </script>

                    <div class="printable">
                        <div class="printable-header">
                            <div>
                                <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="logo" class="printable-logo">
                            </div>
                            <div class="printable-company">
                                <h2><?php echo htmlspecialchars($siteName); ?></h2>
                                <?php if ($compPhone): ?><p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($compPhone); ?></p><?php endif; ?>
                                <?php if ($compEmail): ?><p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($compEmail); ?></p><?php endif; ?>
                                <?php if ($compAddr):  ?><p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($compAddr); ?></p><?php endif; ?>
                            </div>
                        </div>

                        <div class="printable-title">
                            Payment Receipt
                            <span class="pay-status <?php echo htmlspecialchars($payStatus); ?>"><?php echo htmlspecialchars($payStatus); ?></span>
                        </div>

                        <div class="printable-meta">
                            <div>
                                <h4>Received From</h4>
                                <p><strong><?php echo htmlspecialchars($detail['customer_name'] ?? '-'); ?></strong></p>
                                <p>Code: <?php echo htmlspecialchars($detail['customer_code'] ?? '-'); ?></p>
                                <?php if (!empty($detail['customer_phone'])): ?><p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($detail['customer_phone']); ?></p><?php endif; ?>
                                <?php if (!empty($detail['customer_email'])): ?><p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($detail['customer_email']); ?></p><?php endif; ?>
                                <?php if (!empty($detail['area_name'])):      ?><p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($detail['area_name']); ?></p><?php endif; ?>
                                <?php if (!empty($detail['customer_address'])):?><p><?php echo htmlspecialchars($detail['customer_address']); ?></p><?php endif; ?>
                            </div>
                            <div>
                                <h4>Receipt Details</h4>
                                <p><strong>Receipt #:</strong> <?php echo htmlspecialchars($detail['receipt_number']); ?></p>
                                <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($detail['payment_date'])); ?></p>
                                <p><strong>Method:</strong> <span class="method-badge <?php echo htmlspecialchars($detail['payment_method']); ?>"><?php echo str_replace('_', ' ', $detail['payment_method']); ?></span></p>
                                <p><strong>Status:</strong> <span class="pay-status <?php echo htmlspecialchars($payStatus); ?>"><?php echo htmlspecialchars($payStatus); ?></span></p>
                                <p><strong>Source:</strong> <span class="pay-source <?php echo htmlspecialchars($paySource); ?>"><?php echo str_replace('_', ' ', htmlspecialchars($paySource)); ?></span></p>
                                <?php if (!empty($detail['bank_name'])): ?>
                                    <p><strong>Bank:</strong> <?php echo htmlspecialchars($detail['bank_name']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($detail['cheque_number'])): ?>
                                    <p><strong>Cheque #:</strong> <?php echo htmlspecialchars($detail['cheque_number']); ?>
                                    <?php if (!empty($detail['cheque_date'])): ?> · <?php echo date('M d, Y', strtotime($detail['cheque_date'])); ?><?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                <?php if (!empty($detail['reference_number'])): ?>
                                    <p><strong>Reference:</strong> <?php echo htmlspecialchars($detail['reference_number']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($detail['cleared_at'])): ?>
                                    <p><strong>Cleared:</strong> <?php echo date('M d, Y H:i', strtotime($detail['cleared_at'])); ?></p>
                                <?php endif; ?>
                                <p><strong>Collected By:</strong> <?php echo htmlspecialchars($detail['collected_by_name'] ?: $detail['collected_by_username']); ?></p>
                            </div>
                        </div>

                        <table class="printable-table">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Billing Month</th>
                                    <th class="num-cell">Invoice Total</th>
                                    <th class="num-cell">Amount Allocated</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($detailAllocs)): ?>
                                    <tr>
                                        <td colspan="4" class="muted">No allocations recorded for this receipt.</td>
                                    </tr>
                                <?php else: foreach ($detailAllocs as $da): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($da['invoice_number'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($da['billing_month'] ?? '-'); ?></td>
                                        <td class="num-cell"><?php echo $currency . ' ' . number_format((float)($da['invoice_total'] ?? 0), 2); ?></td>
                                        <td class="num-cell"><?php echo $currency . ' ' . number_format((float)$da['amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>

                        <div class="printable-totals">
                            <div class="printable-totals-row">
                                <span>Total Allocated:</span>
                                <span><?php echo $currency . ' ' . number_format($totalAlloc, 2); ?></span>
                            </div>
                            <?php if ((float)($detail['fee'] ?? 0) > 0): ?>
                                <div class="printable-totals-row">
                                    <span>Fee:</span>
                                    <span><?php echo $currency . ' ' . number_format((float)$detail['fee'], 2); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="printable-totals-row grand">
                                <span>Net Amount:</span>
                                <span><?php echo $currency . ' ' . number_format((float)$detail['net_amount'], 2); ?></span>
                            </div>
                            <?php if ($payStatus === 'refunded' && (float)($detail['refunded_amount'] ?? 0) > 0): ?>
                                <div class="printable-totals-row">
                                    <span>Refunded:</span>
                                    <span><?php echo $currency . ' ' . number_format((float)$detail['refunded_amount'], 2); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($payStatus === 'bounced'): ?>
                            <div class="printable-stamp overdue">BOUNCED</div>
                            <?php if (!empty($detail['bounce_reason'])): ?>
                                <p class="muted"><strong>Reason:</strong> <?php echo htmlspecialchars($detail['bounce_reason']); ?></p>
                            <?php endif; ?>
                        <?php elseif ($payStatus === 'refunded'): ?>
                            <div class="printable-stamp overdue">REFUNDED</div>
                            <?php if (!empty($detail['refund_reason'])): ?>
                                <p class="muted"><strong>Reason:</strong> <?php echo htmlspecialchars($detail['refund_reason']); ?></p>
                            <?php endif; ?>
                        <?php elseif ($payStatus === 'pending'): ?>
                            <div class="printable-stamp partial">PENDING</div>
                        <?php endif; ?>

                        <?php if (!empty($detail['notes'])): ?>
                            <div class="printable-notes">
                                <h4>Notes</h4>
                                <p><?php echo nl2br(htmlspecialchars($detail['notes'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <div class="printable-signatures">
                            <div class="printable-sig">Received By</div>
                            <div class="printable-sig">Customer</div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- ===== List View ===== -->

                <!-- summary cards (cleared only) -->
                <div class="summary-grid">
                    <div class="summary-card highlight">
                        <div class="summary-card-label"><i class="fas fa-calendar-day"></i> Today's Total</div>
                        <div class="summary-card-value" id="sumTodayTotal"><?php echo $currency; ?> 0.00</div>
                        <div class="summary-card-sub">Cleared today</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-card-label"><i class="fas fa-receipt"></i> Today's Count</div>
                        <div class="summary-card-value" id="sumTodayCount">0</div>
                        <div class="summary-card-sub">Receipts cleared today</div>
                    </div>
                    <div class="summary-card success">
                        <div class="summary-card-label"><i class="fas fa-calendar-alt"></i> This Month Total</div>
                        <div class="summary-card-value" id="sumMonthTotal"><?php echo $currency; ?> 0.00</div>
                        <div class="summary-card-sub">Cleared month-to-date</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-card-label"><i class="fas fa-list-ol"></i> This Month Count</div>
                        <div class="summary-card-value" id="sumMonthCount">0</div>
                        <div class="summary-card-sub">Receipts this month</div>
                    </div>
                    <div class="summary-card warning">
                        <div class="summary-card-label"><i class="fas fa-hourglass-half"></i> Pending Clearance</div>
                        <div class="summary-card-value" id="sumPendingTotal"><?php echo $currency; ?> 0.00</div>
                        <div class="summary-card-sub" id="sumPendingCount">0 receipts waiting</div>
                    </div>
                </div>

                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-table"></i> Payments</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-primary" onclick="loadPayments()">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <?php if (can('payments', 'a')): ?>
                            <button class="btn btn-secondary" onclick="rsImport.pick()">
                                <i class="fas fa-file-import"></i> Import CSV
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-secondary" onclick="rsImport.template()">
                                <i class="fas fa-download"></i> Template
                            </button>
                            <button class="btn btn-success" onclick="openAddModal()">
                                <i class="fas fa-plus"></i> Record Payment
                            </button>
                        </div>
                    </div>

                    <!-- filters -->
                    <div class="filters-section initially-hidden" id="filtersSection">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                                <i class="fas fa-times-circle"></i> Clear All
                            </button>
                        </div>
                        <div class="filter-dates">
                            <div class="filter-group">
                                <label><i class="fas fa-calendar"></i> Payment Date From</label>
                                <input type="date" id="filterFrom">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-calendar"></i> Payment Date To</label>
                                <input type="date" id="filterTo">
                            </div>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-credit-card"></i> Method</label>
                                <select id="filterMethod">
                                    <option value="">All Methods</option>
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="easypaisa">Easypaisa</option>
                                    <option value="jazzcash">JazzCash</option>
                                    <option value="cheque">Cheque</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-circle-check"></i> Status</label>
                                <select id="filterStatus">
                                    <option value="">All Statuses</option>
                                    <option value="cleared">Cleared</option>
                                    <option value="pending">Pending</option>
                                    <option value="bounced">Bounced</option>
                                    <option value="refunded">Refunded</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-tower-broadcast"></i> Source</label>
                                <select id="filterSource">
                                    <option value="">All Sources</option>
                                    <option value="field">Field</option>
                                    <option value="portal">Portal</option>
                                    <option value="auto_debit">Auto Debit</option>
                                    <option value="api">API</option>
                                    <option value="office">Office</option>
                                </select>
                            </div>
                            <?php if ($isAdminLvl): ?>
                            <div class="filter-group">
                                <label><i class="fas fa-user-tie"></i> Collector</label>
                                <select id="filterCollector">
                                    <option value="">All Collectors</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="filter-group">
                                <label><i class="fas fa-map-marked-alt"></i> Area</label>
                                <select id="filterArea">
                                    <option value="">All Areas</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- skeleton -->
                    <div id="skeletonWrap" class="table-responsive">
                        <table class="display table-full-width">
                            <thead>
                                <tr>
                                    <th>Receipt #</th><th>Customer</th><th>Amount</th><th>Fee</th><th>Net</th>
                                    <th>Method</th><th>Status</th><th>Source</th><th>Date</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i = 0; $i < 5; $i++): ?>
                                <tr>
                                    <?php for ($j = 0; $j < 10; $j++): ?>
                                    <td><div class="skeleton-line"></div></td>
                                    <?php endfor; ?>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div class="table-responsive initially-hidden" id="tableWrap">
                        <table id="paymentsTable" class="display table-full-width">
                            <tfoot>
                                <tr>
                                    <th class="dt-foot-label" colspan="2">Totals (<span id="footCount">0</span> payments)</th>
                                    <th class="num-cell" id="footAmount"></th>
                                    <th></th>
                                    <th class="num-cell" id="footNet"></th>
                                    <th colspan="5"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- empty -->
                    <div class="empty-state initially-hidden" id="emptyState">
                        <div class="empty-state-icon"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="empty-state-title">No payments recorded yet.</div>
                        <div class="empty-state-text">Click "Record Payment" to log your first collection.</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($detailId === 0): ?>
    <!-- Payment modal -->
    <div class="modal-overlay" id="paymentModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Record Payment</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="paymentForm">
                    <input type="hidden" id="paymentId" name="id">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-receipt"></i> Receipt #</label>
                            <input type="text" id="receiptNumberDisp" readonly placeholder="(auto-generated)">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-user"></i> Customer *</label>
                            <select id="customer_id" name="customer_id" required></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Payment Method *</label>
                            <select id="payment_method" name="payment_method" required>
                                <option value="">Select method...</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="easypaisa">Easypaisa</option>
                                <option value="jazzcash">JazzCash</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Payment Date *</label>
                            <input type="date" id="payment_date" name="payment_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill"></i> Amount *</label>
                            <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Fee</label>
                            <input type="number" id="fee" name="fee" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Net Amount</label>
                            <input type="number" id="net_amount" name="net_amount" step="0.01" readonly>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tower-broadcast"></i> Source *</label>
                            <select id="source" name="source" required>
                                <option value="field">Field</option>
                                <option value="portal">Portal</option>
                                <option value="auto_debit">Auto Debit</option>
                                <option value="api">API</option>
                                <option value="office">Office</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-circle-check"></i> Status *</label>
                            <select id="status" name="status" required>
                                <option value="cleared">Cleared</option>
                                <option value="pending">Pending</option>
                                <option value="bounced">Bounced</option>
                                <option value="refunded">Refunded</option>
                            </select>
                        </div>
                        <div class="form-group conditional-field" id="bankNameGroup">
                            <label><i class="fas fa-building-columns"></i> Bank Name</label>
                            <input type="text" id="bank_name" name="bank_name" placeholder="e.g. HBL, MCB, Meezan">
                        </div>
                        <div class="form-group conditional-field" id="chequeNumberGroup">
                            <label><i class="fas fa-money-check"></i> Cheque #</label>
                            <input type="text" id="cheque_number" name="cheque_number" placeholder="e.g. 0123456">
                        </div>
                        <div class="form-group conditional-field" id="chequeDateGroup">
                            <label><i class="fas fa-calendar-day"></i> Cheque Date</label>
                            <input type="date" id="cheque_date" name="cheque_date">
                        </div>
                        <div class="form-group conditional-field" id="referenceGroup" style="grid-column:1/-1">
                            <label><i class="fas fa-hashtag"></i> Reference / Txn #</label>
                            <input type="text" id="reference_number" name="reference_number" placeholder="e.g. JZ-99887766">
                        </div>
                        <div class="form-group" id="bankAccountGroup">
                            <label><i class="fas fa-piggy-bank"></i> Receiving Account</label>
                            <select id="bank_account_id" name="bank_account_id">
                                <option value="">— None / Cash —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Currency</label>
                            <select id="currency" name="currency">
                                <option value="PKR">PKR</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> Payer Name</label>
                            <input type="text" id="payer_name" name="payer_name" placeholder="Defaults to customer">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Payer Phone</label>
                            <input type="tel" id="payer_phone" name="payer_phone" placeholder="03XX-XXXXXXX">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-fingerprint"></i> Payer CNIC</label>
                            <input type="text" id="payer_cnic" name="payer_cnic" placeholder="XXXXX-XXXXXXX-X">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-wallet"></i> Advance Payment</label>
                            <label class="toggle-switch">
                                <input type="checkbox" id="is_advance" name="is_advance" value="1">
                                <span class="toggle-slider"></span>
                                <span class="toggle-label">No invoice; balance applied later</span>
                            </label>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-bell"></i> Send Notification</label>
                            <div class="toggle-row">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="whatsapp_sent" name="whatsapp_sent" value="1">
                                    <span class="toggle-slider"></span>
                                    <span class="toggle-label"><i class="fab fa-whatsapp"></i> WhatsApp</span>
                                </label>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="email_sent" name="email_sent" value="1">
                                    <span class="toggle-slider"></span>
                                    <span class="toggle-label"><i class="fas fa-envelope"></i> Email</span>
                                </label>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="sms_sent" name="sms_sent" value="1">
                                    <span class="toggle-slider"></span>
                                    <span class="toggle-label"><i class="fas fa-sms"></i> SMS</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-list-check"></i> Allocate to Invoices <span id="allocReqStar">*</span></label>
                            <div id="allocList" class="alloc-list">
                                <p class="muted"><i class="fas fa-info-circle"></i> Pick a customer to load open invoices.</p>
                            </div>
                            <div class="alloc-summary" id="allocSummary" style="display:none">
                                <span>Allocated: <strong id="allocSumDisp">0.00</strong> / <strong id="amtDisp">0.00</strong></span>
                                <span id="allocStatus" class="ok">OK</span>
                            </div>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-sticky-note"></i> Notes</label>
                            <textarea id="notes" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Payment view modal — tabbed -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-receipt"></i> Payment Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="vtab-nav">
                    <button type="button" class="vtab-btn active" data-vtab="vtPay"><i class="fas fa-money-bill-wave"></i> Payment Details</button>
                    <button type="button" class="vtab-btn" data-vtab="vtAlloc"><i class="fas fa-list-check"></i> Allocations</button>
                    <button type="button" class="vtab-btn" data-vtab="vtCust"><i class="fas fa-user"></i> Customer Info</button>
                </div>
                <div class="vtab-pane active" id="vtPay"></div>
                <div class="vtab-pane" id="vtAlloc"></div>
                <div class="vtab-pane" id="vtCust"></div>
                <div class="form-actions">
                    <a class="btn btn-primary" id="viewPrintBtn" href="#" target="_blank"><i class="fas fa-print"></i> Print Receipt</a>
                    <button type="button" class="btn btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>

    <?php if ($detailId === 0): ?>
    <script>
    // lazy pdf deps
    function loadExportDeps(cb) {
        if (window.pdfMake) { cb(); return; }
        const urls = [
            'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'
        ];
        let i = 0;
        (function next(){
            if (i >= urls.length) { cb(); return; }
            const s = document.createElement('script');
            s.src = urls[i++]; s.onload = next; document.head.appendChild(s);
        })();
    }
    </script>

    <script>
    let payTable;
    let isEditMode = false;
    let payData = [];
    let invoicesCache = [];     // open invoices for selected customer
    let editingPayment = null;  // when editing — full row incl allocations
    const CAN_DELETE = <?php echo $canDelete ? 'true' : 'false'; ?>;
    const CAN_EDIT   = <?php echo $canEdit   ? 'true' : 'false'; ?>;
    const IS_ADMIN_LVL = <?php echo $isAdminLvl ? 'true' : 'false'; ?>;
    const CURR = <?php echo json_encode($currency); ?>;

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function fmtMoney(v)   { return CURR + ' ' + Number(v || 0).toFixed(2); }
    // raw YYYY-MM-DD -> "Mon DD, YYYY" for display
    const MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function fmtDate(d) {
        if (!d) return '<span class="muted">—</span>';
        const p = String(d).slice(0, 10).split('-');
        if (p.length !== 3) return escapeHtml(d);
        return MON[parseInt(p[1], 10) - 1] + ' ' + p[2] + ', ' + p[0];
    }

    $(document).ready(function() {
        // SD wiring — list filters
        new SearchableDropdown({ selectId: 'filterMethod', placeholder: 'All Methods', icon: 'fas fa-credit-card' });
        new SearchableDropdown({ selectId: 'filterStatus', placeholder: 'All Statuses', icon: 'fas fa-circle-check' });
        new SearchableDropdown({ selectId: 'filterSource', placeholder: 'All Sources', icon: 'fas fa-tower-broadcast' });
        if (IS_ADMIN_LVL) {
            new SearchableDropdown({ selectId: 'filterCollector', placeholder: 'All Collectors', icon: 'fas fa-user-tie' });
        }
        new SearchableDropdown({ selectId: 'filterArea', placeholder: 'All Areas', icon: 'fas fa-map-marked-alt' });

        // SD wiring — modal
        new SearchableDropdown({ selectId: 'customer_id',     placeholder: 'Select customer...', icon: 'fas fa-user', allowClear: true });
        new SearchableDropdown({ selectId: 'payment_method',  placeholder: 'Select method...',   icon: 'fas fa-credit-card' });
        new SearchableDropdown({ selectId: 'source',          placeholder: 'Select source...',   icon: 'fas fa-tower-broadcast' });
        new SearchableDropdown({ selectId: 'status',          placeholder: 'Select status...',   icon: 'fas fa-circle-check' });
        new SearchableDropdown({ selectId: 'bank_account_id', placeholder: 'None / Cash',         icon: 'fas fa-piggy-bank', allowClear: true });
        new SearchableDropdown({ selectId: 'currency',        placeholder: 'PKR',                 icon: 'fas fa-coins' });

        loadCollectorsFilter();
        loadAreasFilter();
        loadBankAccounts();

        $('#filterFrom, #filterTo').on('change', loadPayments);
        $('#filterMethod, #filterStatus, #filterSource, #filterCollector, #filterArea').on('change', loadPayments);
        $('#customer_id').on('change', onCustomerChange);
        $('#payment_method').on('change', toggleConditionalFields);
        $('#amount, #fee').on('input', recomputeNetAndAlloc);
        $('#is_advance').on('change', onAdvanceToggle);

        loadPayments();
        rsImport.init({ entity: 'Payment', onDone: loadPayments });

        // auto-open add form from dashboard quick action (?new=1)
        if (new URLSearchParams(location.search).get('new') === '1' && document.querySelector('button[onclick="openAddModal()"]')) openAddModal();
    });

    function loadBankAccounts() {
        $.getJSON('?action=getBankAccounts', function(resp) {
            if (!resp.success) return;
            const sel = document.getElementById('bank_account_id');
            if (!sel) return;
            const opts = ['<option value="">— None / Cash —</option>'];
            resp.data.forEach(b => opts.push(`<option value="${b.id}">${escapeHtml(b.label)}</option>`));
            sel.innerHTML = opts.join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function onAdvanceToggle() {
        const isAdv = $('#is_advance').is(':checked');
        $('#allocReqStar').toggle(!isAdv);
        if (isAdv) {
            $('#allocList').html('<p class="muted"><i class="fas fa-info-circle"></i> Advance mode — no invoice allocation required. Balance will be carried forward.</p>');
            $('#allocSummary').hide();
        } else {
            onCustomerChange();
        }
    }

    function loadCollectorsFilter() {
        if (!IS_ADMIN_LVL) return;
        $.getJSON('?action=getCollectors', function(resp) {
            if (!resp.success) return;
            const sel = document.getElementById('filterCollector');
            sel.innerHTML = '<option value="">All Collectors</option>' +
                resp.data.map(c => `<option value="${c.id}">${escapeHtml(c.label)}</option>`).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function loadAreasFilter() {
        $.getJSON('?action=getAreas', function(resp) {
            if (!resp.success) return;
            const sel = document.getElementById('filterArea');
            sel.innerHTML = '<option value="">All Areas</option>' +
                resp.data.map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function getFilterParams() {
        return {
            from:         $('#filterFrom').val(),
            to:           $('#filterTo').val(),
            method:       $('#filterMethod').val(),
            status:       $('#filterStatus').val(),
            source:       $('#filterSource').val(),
            collector_id: IS_ADMIN_LVL ? ($('#filterCollector').val() || 0) : 0,
            area_id:      $('#filterArea').val() || 0
        };
    }

    function loadPayments() {
        $('#skeletonWrap').show();
        $('#tableWrap').hide();
        $('#emptyState').hide();

        $.ajax({
            url: '?action=getPayments',
            method: 'GET',
            data: getFilterParams(),
            dataType: 'json',
            success: function(resp) {
                if (!resp.success) {
                    $('#skeletonWrap').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' });
                    return;
                }
                payData = resp.data;

                if (resp.summary) {
                    $('#sumTodayTotal').text(fmtMoney(resp.summary.today_total));
                    $('#sumTodayCount').text(resp.summary.today_count);
                    $('#sumMonthTotal').text(fmtMoney(resp.summary.month_total));
                    $('#sumMonthCount').text(resp.summary.month_count);
                    $('#sumPendingTotal').text(fmtMoney(resp.summary.pending_total));
                    $('#sumPendingCount').text(resp.summary.pending_count + ' receipt' + (resp.summary.pending_count == 1 ? '' : 's') + ' waiting');
                }

                $('#filtersSection').show();
                $('#skeletonWrap').hide();
                if (!resp.data.length) {
                    $('#tableWrap').hide();
                    $('#emptyState').show();
                    return;
                }
                $('#emptyState').hide();
                $('#tableWrap').show();
                initTable(resp.data);
            },
            error: function() {
                $('#skeletonWrap').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
            }
        });
    }

    // keep a copy of the static footer — empty() on re-init would wipe it
    const FOOT_HTML = $('#paymentsTable tfoot').length ? $('#paymentsTable tfoot')[0].outerHTML : '';

    function initTable(data) {
        if (payTable) { payTable.destroy(); $('#paymentsTable').empty(); }
        // restore footer (DataTables builds thead from columns config)
        if (FOOT_HTML && !$('#paymentsTable tfoot').length) $('#paymentsTable').append(FOOT_HTML);
        setTimeout(() => {
            payTable = $('#paymentsTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'receipt_number', title: 'Receipt #', render: d => `<strong>${escapeHtml(d)}</strong>` },
                    {
                        data: null, title: 'Customer',
                        render: row => row.customer_name
                            ? `${escapeHtml(row.customer_name)}<br><small class="muted">${escapeHtml(row.customer_code)}</small>`
                            : '<span class="muted">—</span>'
                    },
                    { data: 'amount',     title: 'Amount', className: 'num-cell', render: d => fmtMoney(d) },
                    { data: 'fee',        title: 'Fee',    className: 'num-cell', render: d => Number(d) > 0 ? fmtMoney(d) : '<span class="muted">—</span>' },
                    { data: 'net_amount', title: 'Net',    className: 'num-cell', render: d => fmtMoney(d) },
                    {
                        data: 'payment_method', title: 'Method',
                        render: d => `<span class="method-badge ${d}">${d.replace('_', ' ')}</span>`
                    },
                    {
                        data: 'status', title: 'Status',
                        render: d => `<span class="pay-status ${d}">${d}</span>`
                    },
                    {
                        data: 'source', title: 'Source',
                        render: d => `<span class="pay-source ${d}">${d.replace('_', ' ')}</span>`
                    },
                    {
                        data: 'payment_date', title: 'Date',
                        // raw DATE for sorting; render formats for display only
                        render: function(d, t, row) {
                            if (t === 'display' || t === 'filter') return fmtDate(d);
                            return d || '';
                        }
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(d, t, row) {
                            const rcpt = escapeHtml(row.receipt_number);
                            // View + Print always visible
                            let html = `<button class="action-icon view-icon" onclick="openViewModal(${row.id})" title="View"><i class="fas fa-eye"></i></button>`;
                            html += ` <a class="action-icon view-icon" href="payments.php?id=${row.id}&print=a4" target="_blank" title="Print A4"><i class="fas fa-print"></i></a>`;
                            html += ` <a class="action-icon view-icon" href="payments.php?id=${row.id}&print=thermal" target="_blank" title="Thermal slip"><i class="fas fa-receipt"></i></a>`;

                            // build ⋮ More items
                            let items = '';
                            if (CAN_EDIT) {
                                items += `<button class="act-more-item" onclick='editPayment(${row.id})'><i class="fas fa-edit"></i> Edit</button>`;
                            }
                            if (IS_ADMIN_LVL) {
                                if (row.status === 'pending') {
                                    items += `<button class="act-more-item" onclick="markCleared(${row.id}, '${rcpt}')"><i class="fas fa-check"></i> Mark Cleared</button>`;
                                }
                                if (!row.verified_at) {
                                    items += `<button class="act-more-item" onclick="verifyPayment(${row.id}, '${rcpt}')"><i class="fas fa-shield-halved"></i> Mark Verified</button>`;
                                }
                                if (row.status !== 'bounced' && row.status !== 'refunded') {
                                    items += `<button class="act-more-item" onclick="markBounced(${row.id}, '${rcpt}')"><i class="fas fa-ban"></i> Mark Bounced</button>`;
                                }
                                if (row.status === 'cleared') {
                                    items += `<button class="act-more-item" onclick="refundPayment(${row.id}, '${rcpt}', ${row.amount})"><i class="fas fa-undo"></i> Refund</button>`;
                                }
                            }
                            if (CAN_DELETE) {
                                items += `<button class="act-more-item danger" onclick="deletePayment(${row.id}, '${rcpt}')"><i class="fas fa-trash"></i> Delete</button>`;
                            }
                            if (items) {
                                html += ` <div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button>`
                                      + `<div class="act-more-menu">${items}</div></div>`;
                            }
                            return html;
                        }
                    }
                ],
                pageLength: 50,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv',  text: '<i class="fas fa-file-csv"></i> CSV',  exportOptions: { columns: ':not(:last-child)' } },
                    {
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        action: function(e, dt, node, config) {
                            loadExportDeps(function() {
                                $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config);
                            });
                        },
                        exportOptions: { columns: ':not(:last-child)' }
                    },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':not(:last-child)' } }
                ],
                order: [[8, 'desc']],   // payment_date column index
                footerCallback: function(row, dt, start, end, display) {
                    const api = this.api();
                    const num = v => {
                        const n = parseFloat(String(v == null ? 0 : v).replace(/[^\d.\-]/g, ''));
                        return isNaN(n) ? 0 : n;
                    };
                    // sum across filtered rows (all pages)
                    const totAmt = api.column(2, { search: 'applied' }).data().reduce((s, v) => s + num(v), 0);
                    const totNet = api.column(4, { search: 'applied' }).data().reduce((s, v) => s + num(v), 0);
                    const cnt    = api.column(0, { search: 'applied' }).data().length;
                    $('#footAmount').text(fmtMoney(totAmt));
                    $('#footNet').text(fmtMoney(totNet));
                    $('#footCount').text(cnt);
                }
            });
        }, 100);
    }

    function clearFilters() {
        ['filterFrom','filterTo'].forEach(id => $('#'+id).val(''));
        ['filterMethod','filterStatus','filterSource','filterCollector','filterArea'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = '';
            el._sd && el._sd.refresh();
        });
        loadPayments();
    }

    // ===== Modal flow =====
    function openAddModal() {
        isEditMode = false;
        editingPayment = null;
        $('#modalTitle').html('<i class="fas fa-plus"></i> Record Payment');
        $('#paymentForm')[0].reset();
        $('#paymentId').val('');
        $('#receiptNumberDisp').val('(auto-generated on save)');
        $('#payment_date').val(new Date().toISOString().slice(0, 10));
        $('#fee').val('0');
        $('#net_amount').val('');
        $('#allocList').html('<p class="muted"><i class="fas fa-info-circle"></i> Pick a customer to load open invoices.</p>');
        $('#allocSummary').hide();
        // reset new fields
        ['payer_name','payer_phone','payer_cnic'].forEach(id => $('#' + id).val(''));
        ['is_advance','whatsapp_sent','email_sent','sms_sent'].forEach(id => $('#' + id).prop('checked', false));

        loadCustomersWithDues(null);

        ['payment_method','source','status','bank_account_id','currency'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            if (id === 'source')           el.value = 'field';
            else if (id === 'status')      el.value = 'cleared';
            else if (id === 'currency')    el.value = 'PKR';
            else if (id === 'bank_account_id') el.value = '';
            else                           el.value = '';
            el._sd && el._sd.refresh();
        });
        toggleConditionalFields();

        $('#paymentModal').addClass('active');
    }

    function loadCustomersWithDues(selectedId, cb) {
        $.getJSON('?action=getCustomersWithDues', function(resp) {
            const sel = document.getElementById('customer_id');
            const opts = ['<option value="">Select customer...</option>'];
            resp.data.forEach(c => opts.push(`<option value="${c.id}">${escapeHtml(c.label)}</option>`));
            sel.innerHTML = opts.join('');
            if (selectedId) sel.value = selectedId;
            sel._sd && sel._sd.refresh();
            if (cb) cb();
            else if (selectedId) onCustomerChange();
        });
    }

    function onCustomerChange() {
        const cid = parseInt(document.getElementById('customer_id').value, 10) || 0;
        const list = document.getElementById('allocList');
        if (!cid) {
            invoicesCache = [];
            list.innerHTML = '<p class="muted"><i class="fas fa-info-circle"></i> Pick a customer to load open invoices.</p>';
            $('#allocSummary').hide();
            return;
        }
        $.getJSON('?action=getCustomerInvoices&customer_id=' + cid, function(resp) {
            invoicesCache = resp.data || [];
            renderAllocations();
        });
    }

    // render alloc rows
    function renderAllocations() {
        const list = document.getElementById('allocList');
        // current selection (when re-rendering after customer reload)
        const existingSel = {};
        $('#allocList .alloc-row').each(function() {
            const iid = $(this).data('iid');
            existingSel[iid] = {
                checked: $(this).find('.alloc-check').is(':checked'),
                amount:  parseFloat($(this).find('.alloc-amount').val()) || 0
            };
        });

        if (!invoicesCache.length) {
            list.innerHTML = '<p class="muted"><i class="fas fa-circle-exclamation"></i> No open invoices for this customer.</p>';
            $('#allocSummary').hide();
            return;
        }

        // pre-fill from editingPayment if applicable
        const editAllocs = editingPayment && editingPayment.allocations ? editingPayment.allocations : null;
        const editMap = {};
        if (editAllocs) editAllocs.forEach(a => editMap[a.invoice_id] = parseFloat(a.amount));

        let payAmt = parseFloat($('#amount').val()) || 0;
        let remaining = payAmt;
        const html = invoicesCache.map((inv, idx) => {
            const editVal = editMap[inv.id];
            const prev    = existingSel[inv.id];
            const isEditFor = editVal != null;
            const isPrevChecked = prev ? prev.checked : false;
            // default: first invoice checked when adding
            const defaultCheck = !editingPayment && !prev && idx === 0;
            const checked = isEditFor || isPrevChecked || defaultCheck;
            let amt = 0;
            if (isEditFor) amt = editVal;
            else if (prev && prev.checked) amt = prev.amount;
            else if (defaultCheck) {
                amt = Math.min(remaining > 0 ? remaining : parseFloat(inv.balance_amount), parseFloat(inv.balance_amount));
                if (amt > 0) remaining -= amt;
            }

            return `
                <div class="alloc-row" data-iid="${inv.id}" data-bal="${inv.balance_amount}">
                    <label class="alloc-check-wrap">
                        <input type="checkbox" class="alloc-check" ${checked ? 'checked' : ''}>
                        <strong>${escapeHtml(inv.invoice_number)}</strong>
                        <small class="muted"> — ${escapeHtml(inv.billing_month)} · Bal: ${fmtMoney(inv.balance_amount)} · Due: ${escapeHtml(inv.due_date || '-')}</small>
                    </label>
                    <span class="muted">Pay:</span>
                    <input type="number" class="alloc-amount" step="0.01" min="0" max="${inv.balance_amount}" value="${amt > 0 ? amt.toFixed(2) : ''}" ${checked ? '' : 'disabled'}>
                </div>
            `;
        }).join('');

        list.innerHTML = html;

        // wire change events
        $('#allocList .alloc-check').on('change', function() {
            const row = $(this).closest('.alloc-row');
            const amtInp = row.find('.alloc-amount');
            if (this.checked) {
                amtInp.prop('disabled', false);
                if (!parseFloat(amtInp.val())) amtInp.val(parseFloat(row.data('bal')).toFixed(2));
            } else {
                amtInp.val('').prop('disabled', true);
            }
            recomputeNetAndAlloc();
        });
        $('#allocList .alloc-amount').on('input', recomputeNetAndAlloc);

        recomputeNetAndAlloc();
    }

    function recomputeNetAndAlloc() {
        const amount = parseFloat($('#amount').val()) || 0;
        const fee    = parseFloat($('#fee').val()) || 0;
        $('#net_amount').val((amount - fee).toFixed(2));

        // alloc summary
        let sum = 0;
        $('#allocList .alloc-row').each(function() {
            if ($(this).find('.alloc-check').is(':checked')) {
                sum += parseFloat($(this).find('.alloc-amount').val()) || 0;
            }
        });
        $('#allocSumDisp').text(sum.toFixed(2));
        $('#amtDisp').text(amount.toFixed(2));
        $('#allocSummary').show();
        const st = $('#allocStatus');
        if (Math.abs(sum - amount) < 0.01 && amount > 0) {
            st.removeClass('mismatch').addClass('ok').html('<i class="fas fa-check"></i> Balanced');
        } else {
            st.removeClass('ok').addClass('mismatch').html('<i class="fas fa-triangle-exclamation"></i> Diff: ' + (amount - sum).toFixed(2));
        }
    }

    function toggleConditionalFields() {
        const m = document.getElementById('payment_method').value;
        const showBank   = (m === 'bank_transfer' || m === 'cheque');
        const showCheque = (m === 'cheque');
        const showRef    = (m && m !== 'cash');
        $('#bankNameGroup').css('display', showBank ? '' : 'none');
        $('#chequeNumberGroup').css('display', showCheque ? '' : 'none');
        $('#chequeDateGroup').css('display', showCheque ? '' : 'none');
        $('#referenceGroup').css('display', showRef ? '' : 'none');
    }

    function editPayment(id) {
        if (!CAN_EDIT) return;
        Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.getJSON('?action=getPaymentDetail&id=' + id, function(resp) {
            Swal.close();
            if (!resp.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' });
                return;
            }
            const p = resp.data;
            isEditMode = true;
            editingPayment = p;

            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Payment');
            $('#paymentForm')[0].reset();
            $('#paymentId').val(p.id);
            $('#receiptNumberDisp').val(p.receipt_number);
            $('#amount').val(p.amount);
            $('#fee').val(p.fee || 0);
            $('#net_amount').val(p.net_amount || (p.amount - (p.fee||0)).toFixed(2));
            $('#payment_date').val(p.payment_date);
            $('#reference_number').val(p.reference_number || '');
            $('#notes').val(p.notes || '');
            $('#bank_name').val(p.bank_name || '');
            $('#cheque_number').val(p.cheque_number || '');
            $('#cheque_date').val(p.cheque_date || '');
            // new fields
            $('#payer_name').val(p.payer_name || '');
            $('#payer_phone').val(p.payer_phone || '');
            $('#payer_cnic').val(p.payer_cnic || '');
            $('#is_advance').prop('checked', !!parseInt(p.is_advance || 0, 10));
            $('#whatsapp_sent').prop('checked', !!parseInt(p.whatsapp_sent || 0, 10));
            $('#email_sent').prop('checked', !!parseInt(p.email_sent || 0, 10));
            $('#sms_sent').prop('checked', !!parseInt(p.sms_sent || 0, 10));

            ['payment_method','source','status','bank_account_id','currency'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                if (id === 'bank_account_id') el.value = p.bank_account_id || '';
                else if (id === 'currency')   el.value = p.currency || 'PKR';
                else                          el.value = p[id] || '';
                el._sd && el._sd.refresh();
            });

            // load customers, then invoices, then alloc rows (pre-checked from p.allocations)
            loadCustomersWithDues(p.customer_id, function() {
                const sel = document.getElementById('customer_id');
                // ensure current customer present
                if (!Array.from(sel.options).some(o => parseInt(o.value,10) === parseInt(p.customer_id,10))) {
                    const o = document.createElement('option');
                    o.value = p.customer_id; o.textContent = (p.customer_name || '') + ' (' + (p.customer_code || '') + ')';
                    sel.appendChild(o);
                }
                sel.value = p.customer_id;
                sel._sd && sel._sd.refresh();

                $.getJSON('?action=getCustomerInvoices&customer_id=' + p.customer_id, function(r2) {
                    invoicesCache = r2.data || [];
                    // append any allocated invoices not in open list (closed since)
                    (p.allocations || []).forEach(a => {
                        if (!invoicesCache.some(x => x.id === a.invoice_id)) {
                            invoicesCache.push({
                                id: a.invoice_id,
                                invoice_number: a.invoice_number + ' (closed)',
                                billing_month: a.billing_month || '-',
                                total: a.invoice_total || a.amount,
                                paid: a.amount,
                                balance_amount: a.amount,
                                due_date: '-'
                            });
                        }
                    });
                    renderAllocations();
                });
            });

            toggleConditionalFields();
            $('#paymentModal').addClass('active');
        }).fail(function() {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load payment details' });
        });
    }

    function closeModal() {
        $('#paymentModal').removeClass('active');
        $('#paymentForm')[0].reset();
        editingPayment = null;
    }

    document.getElementById('paymentModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.getElementById('paymentForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const isAdv = $('#is_advance').is(':checked');

        // collect allocations (skip in advance mode)
        const allocs = [];
        if (!isAdv) {
            $('#allocList .alloc-row').each(function() {
                if ($(this).find('.alloc-check').is(':checked')) {
                    const iid = parseInt($(this).data('iid'), 10);
                    const amt = parseFloat($(this).find('.alloc-amount').val()) || 0;
                    if (iid > 0 && amt > 0) allocs.push({ invoice_id: iid, amount: amt });
                }
            });
            if (!allocs.length) {
                Swal.fire({ icon: 'warning', title: 'No allocations', text: 'Please tick at least one invoice and enter an amount, or turn on Advance Payment.' });
                return;
            }
            const amount = parseFloat($('#amount').val()) || 0;
            const sum = allocs.reduce((s, a) => s + a.amount, 0);
            if (Math.abs(sum - amount) > 0.01) {
                Swal.fire({ icon: 'warning', title: 'Allocation mismatch', text: 'Allocations (' + sum.toFixed(2) + ') must equal payment amount (' + amount.toFixed(2) + ').' });
                return;
            }
        }

        const fd = new FormData(this);
        // strip the readonly net_amount (server recomputes)
        fd.delete('net_amount');
        // toggles must be sent as 1 or 0
        ['is_advance','whatsapp_sent','email_sent','sms_sent'].forEach(k => {
            fd.delete(k);
            fd.append(k, $('#' + k).is(':checked') ? '1' : '0');
        });
        // append allocations[]
        allocs.forEach((a, i) => {
            fd.append('allocations[' + i + '][invoice_id]', a.invoice_id);
            fd.append('allocations[' + i + '][amount]', a.amount);
        });

        const action = isEditMode ? 'updatePayment' : 'addPayment';
        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=' + action, method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    // signal other open tabs (invoices.php, customers.php) to refresh
                    try { localStorage.setItem('isp_invoices_dirty', String(Date.now())); } catch (e) {}

                    Swal.fire({
                        icon: 'success', title: 'Success!', text: resp.message,
                        showConfirmButton: !isEditMode && resp.id ? true : false,
                        confirmButtonText: 'View Receipt',
                        timer: !isEditMode && resp.id ? null : 2000
                    }).then(r => {
                        if (r.isConfirmed && resp.id) {
                            window.location.href = 'payments.php?id=' + resp.id;
                            return;
                        }
                        closeModal();
                        loadPayments();
                    });
                    if (isEditMode) { closeModal(); loadPayments(); }
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    });

    function deletePayment(id, rcpt) {
        if (!CAN_DELETE) return;
        Swal.fire({
            icon: 'warning', title: 'Delete Payment?',
            html: `Receipt <strong>${escapeHtml(rcpt)}</strong> will be soft-deleted and all linked invoice statuses will be recomputed.`,
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deletePayment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        try { localStorage.setItem('isp_invoices_dirty', String(Date.now())); } catch (e) {}
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(() => loadPayments(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function markCleared(id, rcpt) {
        Swal.fire({
            icon: 'question', title: 'Mark Cleared?',
            html: `Receipt <strong>${escapeHtml(rcpt)}</strong> will be marked as cleared and invoices recomputed.`,
            showCancelButton: true, confirmButtonText: 'Mark Cleared', cancelButtonText: 'Cancel'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=markPaymentCleared', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(loadPayments, 100);
                    } else { Swal.fire({ icon: 'error', title: 'Error', text: resp.message }); }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function markBounced(id, rcpt) {
        Swal.fire({
            title: 'Mark Bounced?',
            html: `Receipt <strong>${escapeHtml(rcpt)}</strong> — enter bounce details:` +
                  `<input id="swal-bounce-reason" class="swal2-input" placeholder="Reason (e.g. Insufficient funds)">` +
                  `<input id="swal-bounce-charges" type="number" step="0.01" min="0" class="swal2-input" placeholder="Bounce charges (optional)">`,
            focusConfirm: false,
            showCancelButton: true, confirmButtonColor: '#dc2626',
            confirmButtonText: 'Mark Bounced', cancelButtonText: 'Cancel',
            preConfirm: () => {
                const rsn = (document.getElementById('swal-bounce-reason').value || '').trim();
                const ch  = parseFloat(document.getElementById('swal-bounce-charges').value) || 0;
                if (!rsn) { Swal.showValidationMessage('Reason is required'); return false; }
                if (ch < 0) { Swal.showValidationMessage('Charges cannot be negative'); return false; }
                return { rsn, ch };
            }
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', id);
            fd.append('bounce_reason', r.value.rsn);
            fd.append('bounce_charges', r.value.ch);
            $.ajax({
                url: '?action=markPaymentBounced', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(loadPayments, 100);
                    } else { Swal.fire({ icon: 'error', title: 'Error', text: resp.message }); }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function refundPayment(id, rcpt, maxAmt) {
        const today = new Date().toISOString().slice(0, 10);
        Swal.fire({
            title: 'Refund Payment',
            html: `Receipt <strong>${escapeHtml(rcpt)}</strong> — Max refundable: <strong>${fmtMoney(maxAmt)}</strong>` +
                  `<input id="swal-amt"    type="number" class="swal2-input" step="0.01" min="0.01" max="${maxAmt}" placeholder="Refund amount">` +
                  `<input id="swal-date"   type="date"   class="swal2-input" value="${today}">` +
                  `<select id="swal-method" class="swal2-input">` +
                    `<option value="">— Refund Method —</option>` +
                    `<option value="cash">Cash</option>` +
                    `<option value="bank_transfer">Bank Transfer</option>` +
                    `<option value="cheque">Cheque</option>` +
                    `<option value="online">Online</option>` +
                    `<option value="jazzcash">JazzCash</option>` +
                    `<option value="easypaisa">Easypaisa</option>` +
                  `</select>` +
                  `<input id="swal-ref"    class="swal2-input" placeholder="Refund reference / Txn # (optional)">` +
                  `<input id="swal-reason" class="swal2-input" placeholder="Refund reason">`,
            focusConfirm: false,
            showCancelButton: true, confirmButtonColor: '#7c3aed',
            confirmButtonText: 'Refund', cancelButtonText: 'Cancel',
            preConfirm: () => {
                const amt = parseFloat(document.getElementById('swal-amt').value) || 0;
                const dt  = (document.getElementById('swal-date').value || '').trim();
                const mth = (document.getElementById('swal-method').value || '').trim();
                const ref = (document.getElementById('swal-ref').value || '').trim();
                const rsn = (document.getElementById('swal-reason').value || '').trim();
                if (amt <= 0 || amt > maxAmt) { Swal.showValidationMessage('Enter a valid refund amount (>0, <= ' + maxAmt + ')'); return false; }
                if (!rsn) { Swal.showValidationMessage('Reason is required'); return false; }
                return { amt, dt, mth, ref, rsn };
            }
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', id);
            fd.append('refund_amount', r.value.amt);
            fd.append('refund_reason', r.value.rsn);
            fd.append('refund_date', r.value.dt);
            fd.append('refunded_to_method', r.value.mth);
            fd.append('refund_reference', r.value.ref);
            $.ajax({
                url: '?action=refundPayment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(loadPayments, 100);
                    } else { Swal.fire({ icon: 'error', title: 'Error', text: resp.message }); }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    const CURRENT_USERNAME = <?php echo json_encode($username); ?>;
    function verifyPayment(id, rcpt) {
        Swal.fire({
            icon: 'question', title: 'Mark Verified?',
            html: `Receipt <strong>${escapeHtml(rcpt)}</strong> will be marked as verified by you (${escapeHtml(CURRENT_USERNAME)}).`,
            showCancelButton: true, confirmButtonColor: '#0d9488',
            confirmButtonText: 'Verify', cancelButtonText: 'Cancel'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=verifyPayment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(loadPayments, 100);
                    } else { Swal.fire({ icon: 'error', title: 'Error', text: resp.message }); }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    // ===== View modal (tabbed) =====
    function fmtMethod(m) { return m ? String(m).replace(/_/g, ' ') : '—'; }
    function fld(label, val) {
        return `<div class="vtab-field"><label>${label}</label><span>${val == null || val === '' ? '—' : escapeHtml(val)}</span></div>`;
    }

    // vtab switching
    $(document).on('click', '.vtab-btn', function() {
        const t = $(this).data('vtab');
        $('.vtab-btn').removeClass('active');
        $(this).addClass('active');
        $('.vtab-pane').removeClass('active');
        $('#' + t).addClass('active');
    });

    function openViewModal(id) {
        Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.getJSON('?action=getPaymentDetail&id=' + id, function(resp) {
            Swal.close();
            if (!resp.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' });
                return;
            }
            const p = resp.data;
            $('#viewModalTitle').html('<i class="fas fa-receipt"></i> Receipt ' + escapeHtml(p.receipt_number || ''));
            $('#viewPrintBtn').attr('href', 'payments.php?id=' + p.id);

            // reset to first tab
            $('.vtab-btn').removeClass('active').first().addClass('active');
            $('.vtab-pane').removeClass('active');
            $('#vtPay').addClass('active');

            // Payment Details
            $('#vtPay').html('<div class="vtab-grid">'
                + fld('Receipt #', p.receipt_number)
                + fld('Amount', fmtMoney(p.amount))
                + fld('Fee', fmtMoney(p.fee || 0))
                + fld('Net Amount', fmtMoney(p.net_amount))
                + fld('Method', fmtMethod(p.payment_method))
                + fld('Status', p.status)
                + fld('Source', fmtMethod(p.source))
                + fld('Payment Date', fmtDate(p.payment_date))
                + fld('Currency', p.currency || 'PKR')
                + fld('Collected By', p.collected_by_name || p.collected_by_username)
                + fld('Bank Name', p.bank_name)
                + fld('Cheque #', p.cheque_number)
                + fld('Cheque Date', p.cheque_date ? fmtDate(p.cheque_date) : '')
                + fld('Reference #', p.reference_number)
                + fld('Cleared At', p.cleared_at)
                + fld('Verified At', p.verified_at)
                + ((Number(p.refunded_amount) > 0)
                    ? fld('Refunded Amount', fmtMoney(p.refunded_amount)) + fld('Refund Reason', p.refund_reason)
                    : '')
                + ((p.status === 'bounced') ? fld('Bounce Reason', p.bounce_reason) : '')
                + '<div class="vtab-field vtab-field-full"><label>Notes</label><span>'
                    + (p.notes ? escapeHtml(p.notes) : '—') + '</span></div>'
                + '</div>');

            // Allocations
            const allocs = p.allocations || [];
            let allocHtml;
            if (!allocs.length) {
                allocHtml = '<p class="muted"><i class="fas fa-info-circle"></i> No invoice allocations recorded for this payment.</p>';
            } else {
                let sum = 0;
                const rows = allocs.map(a => {
                    sum += parseFloat(a.amount) || 0;
                    return `<tr><td>${escapeHtml(a.invoice_number || '-')}</td>`
                         + `<td>${escapeHtml(a.billing_month || '-')}</td>`
                         + `<td class="num-cell">${fmtMoney(a.invoice_total)}</td>`
                         + `<td class="num-cell">${fmtMoney(a.amount)}</td></tr>`;
                }).join('');
                allocHtml = '<table class="display table-full-width"><thead><tr>'
                    + '<th>Invoice #</th><th>Billing Month</th><th class="num-cell">Invoice Total</th><th class="num-cell">Allocated</th>'
                    + '</tr></thead><tbody>' + rows + '</tbody>'
                    + '<tfoot><tr><th class="dt-foot-label" colspan="3">Total Allocated</th>'
                    + `<th class="num-cell">${fmtMoney(sum)}</th></tr></tfoot></table>`;
            }
            $('#vtAlloc').html(allocHtml);

            // Customer Info
            $('#vtCust').html('<div class="vtab-grid">'
                + fld('Customer', p.customer_name)
                + fld('Customer Code', p.customer_code)
                + fld('Phone', p.customer_phone)
                + fld('Email', p.customer_email)
                + fld('Area', p.area_name)
                + fld('Payer Name', p.payer_name)
                + fld('Payer Phone', p.payer_phone)
                + fld('Payer CNIC', p.payer_cnic)
                + '<div class="vtab-field vtab-field-full"><label>Address</label><span>'
                    + (p.customer_address ? escapeHtml(p.customer_address) : '—') + '</span></div>'
                + '</div>');

            $('#viewModal').addClass('active');
        }).fail(function() {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load payment details' });
        });
    }

    function closeViewModal() {
        $('#viewModal').removeClass('active');
    }

    document.getElementById('viewModal').addEventListener('click', function(e) {
        if (e.target === this) closeViewModal();
    });
    </script>
    <?php endif; ?>
</body>
</html>
