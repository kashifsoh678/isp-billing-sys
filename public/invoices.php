<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate page
if (!isset($_SESSION['user_id']) || !can('invoices', 'v')) {
    header("Location: dashboard.php");
    exit();
}
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'invoices';

// next inv num: INV-{year}-{nnnn}
function nextInvoiceNumber($conn) {
    $yr = date('Y');
    $like = 'INV-' . $yr . '-%';
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) AS mx FROM invoices WHERE invoice_number LIKE ?");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $mx = (int)($stmt->get_result()->fetch_assoc()['mx'] ?? 0);
    $stmt->close();
    return sprintf('INV-%s-%04d', $yr, $mx + 1);
}

// auto-flip overdue
function autoFlipOverdue($conn) {
    $conn->query("UPDATE invoices SET status = 'overdue' WHERE status IN ('unpaid','partial') AND due_date < CURDATE() AND is_deleted = 0");
}

// csv import/template contract — invoice HEADERS only, no line items/stock.
// invoice_number/subtotal/total/paid_amount/balance_amount/status are computed, never CSV-supplied —
// every imported row lands as a fresh unpaid invoice, same as a manual "New Invoice" save.
function invoiceImportSpec() {
    return [
        'entity'  => 'Invoice',
        'table'   => 'invoices',
        'perm'    => 'invoices',
        'columns' => [
            'customer_id'     => ['label' => 'Customer (code or name)', 'type' => 'lookup', 'required' => true,
                                   'lookup' => ['table' => 'customers', 'match' => ['customer_code', 'full_name'], 'where' => 'is_deleted = 0'],
                                   'demo' => 'CUS-0001'],
            'package_id'      => ['label' => 'Package', 'type' => 'lookup', 'required' => true,
                                   'lookup' => ['table' => 'packages', 'match' => ['name'], 'where' => 'is_deleted = 0'],
                                   'demo' => 'Demo Package'],
            'billing_month'   => ['label' => 'Billing Month (YYYY-MM)', 'type' => 's', 'required' => true, 'max' => 7, 'demo' => '2026-09'],
            'amount'          => ['label' => 'Amount', 'type' => 'd', 'required' => true, 'demo' => '2500'],
            'discount_amount' => ['label' => 'Discount', 'type' => 'd', 'default' => 0, 'demo' => '0'],
            'tax'             => ['label' => 'Tax', 'type' => 'd', 'default' => 0, 'demo' => '0'],
            'late_fee'        => ['label' => 'Late Fee', 'type' => 'd', 'default' => 0, 'demo' => '0'],
            'issue_date'      => ['label' => 'Issue Date', 'type' => 'date', 'default' => date('Y-m-d'), 'demo' => '2026-09-01'],
            'due_date'        => ['label' => 'Due Date', 'type' => 'date', 'demo' => '2026-09-10'],
            'notes'           => ['label' => 'Notes', 'type' => 's', 'demo' => 'Imported from CSV'],
        ],
        // brand-new recurring invoice, PKR, created by whoever ran the import
        'static'  => ['created_by_user_id' => (int)($_SESSION['user_id'] ?? 0), 'invoice_type' => 'recurring', 'currency' => 'PKR'],
        'init'    => function (mysqli $conn, array &$state) {
            $yr   = date('Y');
            $like = 'INV-' . $yr . '-%';
            $st = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)) mx FROM invoices WHERE invoice_number LIKE ?");
            $st->bind_param('s', $like);
            $st->execute();
            $state['seq'] = (int)($st->get_result()->fetch_assoc()['mx'] ?? 0);
            $st->close();
            $state['yr'] = $yr;

            // package snapshot map — id => row, so "at time" fields never need a per-row query
            $state['pkgs'] = [];
            $r = $conn->query("SELECT id, name, speed_mbps, price FROM packages");
            while ($x = $r->fetch_assoc()) $state['pkgs'][(int)$x['id']] = $x;

            // existing (customer|month) pairs — a re-import can never double-bill the same month
            $state['seenMonth'] = [];
            $r = $conn->query("SELECT customer_id, billing_month FROM invoices WHERE is_deleted = 0");
            while ($x = $r->fetch_assoc()) $state['seenMonth'][$x['customer_id'] . '|' . $x['billing_month']] = true;
        },
        'before'  => function (array &$row, mysqli $conn, array &$state) {
            if (!preg_match('/^\d{4}-\d{2}$/', $row['billing_month'])) return 'Billing Month must be YYYY-MM';
            if ((float)$row['amount'] <= 0) return 'Amount must be greater than zero';
            if ((float)$row['discount_amount'] < 0 || (float)$row['tax'] < 0 || (float)$row['late_fee'] < 0) return 'Negative values not allowed';
            if (empty($row['due_date'])) $row['due_date'] = $row['billing_month'] . '-10'; // same default as New Invoice / batch generate

            $key = $row['customer_id'] . '|' . $row['billing_month'];
            if (isset($state['seenMonth'][$key])) return 'An invoice for this customer already exists for ' . $row['billing_month'];

            $pkg = $state['pkgs'][$row['package_id']] ?? null;

            $state['seq']++;
            $row['invoice_number'] = sprintf('INV-%s-%04d', $state['yr'], $state['seq']);

            $disc = round((float)$row['discount_amount'], 2);
            $row['discount']  = $disc;                        // legacy field kept in sync
            $row['subtotal']  = round((float)$row['amount'], 2);
            $row['total']     = max(0, round((float)$row['amount'] + (float)$row['tax'] + (float)$row['late_fee'] - $disc, 2));
            $row['paid_amount']    = 0;
            $row['balance_amount'] = $row['total'];
            $row['total_paid']     = 0;
            $row['total_due']      = $row['total'];
            $row['status']         = 'unpaid';
            $row['invoice_period_start'] = $row['billing_month'] . '-01';
            $row['invoice_period_end']   = date('Y-m-t', strtotime($row['invoice_period_start']));
            if ($pkg) {
                $row['package_id_at_time']    = (int)$pkg['id'];
                $row['package_name_at_time']  = $pkg['name'];
                $row['package_speed_at_time'] = (int)$pkg['speed_mbps'];
                $row['package_price_at_time'] = (float)$pkg['price'];
            }

            $state['seenMonth'][$key] = true;
            return null;
        },
        'after'   => function (int $id, array $row, mysqli $conn, array &$state) {
            syncInvoiceLedger($conn, $id);
        },
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'importCsv':      rsImportRun($conn, invoiceImportSpec());
            case 'importTemplate': rsImportTemplate(invoiceImportSpec());

            case 'getInvoices': {
                autoFlipOverdue($conn);

                $month   = trim($_GET['month']  ?? '');
                $status  = trim($_GET['status'] ?? '');
                $areaId  = (int)($_GET['area_id'] ?? 0);
                $search  = trim($_GET['search'] ?? '');

                $where = ["i.is_deleted = 0"];
                $types = "";
                $args  = [];

                if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
                    $where[] = "i.billing_month = ?";
                    $types .= "s"; $args[] = $month;
                }
                if (in_array($status, ['unpaid','partial','paid','overdue'], true)) {
                    $where[] = "i.status = ?";
                    $types .= "s"; $args[] = $status;
                }
                if ($areaId > 0) {
                    $where[] = "c.area_id = ?";
                    $types .= "i"; $args[] = $areaId;
                }
                if ($search !== '') {
                    $where[] = "(i.invoice_number LIKE ? OR c.full_name LIKE ? OR c.customer_code LIKE ?)";
                    $like = '%' . $search . '%';
                    $types .= "sss"; $args[] = $like; $args[] = $like; $args[] = $like;
                }
                // collector: own area only
                if (isAreaScoped()) {
                    $scope = areaScope('c');
                    if ($scope === '') {
                        echo json_encode(['success' => true, 'data' => []]);
                        exit();
                    }
                    $where[] = ltrim($scope, ' AND');
                }

                $sql = "SELECT i.id, i.invoice_number, i.customer_id, i.package_id, i.billing_month,
                               i.amount, i.subtotal, i.previous_balance, i.discount, i.discount_amount, i.discount_pct, i.discount_reason,
                               i.additional_charges, i.additional_charges_description,
                               i.equipment_charges, i.installation_charges, i.items_total,
                               i.tax, i.tax_pct, i.wht_amount, i.wht_pct, i.late_fee, i.total,
                               i.paid_amount, i.balance_amount, i.total_paid, i.total_due,
                               i.due_date, i.issue_date, i.invoice_period_start, i.invoice_period_end,
                               i.status, i.notes, i.notes_internal, i.customer_notes,
                               i.batch_id, i.created_at,
                               i.invoice_type, i.parent_invoice_id, i.is_credit_note,
                               i.currency, i.proration_days, i.proration_amount,
                               i.paid_date, i.payment_method_summary,
                               i.cancel_date, i.cancel_reason, i.cancelled_by_user_id,
                               c.full_name, c.customer_code, c.area_id,
                               a.name AS area_name,
                               p.name AS package_name, p.speed_mbps
                        FROM invoices i
                        LEFT JOIN customers c ON c.id = i.customer_id
                        LEFT JOIN areas a     ON a.id = c.area_id
                        LEFT JOIN packages p  ON p.id = i.package_id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY i.due_date ASC, i.id DESC";
                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$args);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    // money fmt — match payments.php convention
                    $rows[] = [
                        'id'                   => (int)$r['id'],
                        'invoice_number'       => $r['invoice_number'],
                        'customer_id'          => (int)$r['customer_id'],
                        'customer_name'        => $r['full_name'] ?? '',
                        'customer_code'        => $r['customer_code'] ?? '',
                        'area_id'              => (int)($r['area_id'] ?? 0),
                        'area_name'            => $r['area_name'] ?? '',
                        'package_id'           => (int)$r['package_id'],
                        'package_name'         => $r['package_name'] ?? '',
                        'speed_mbps'           => (int)($r['speed_mbps'] ?? 0),
                        'billing_month'        => $r['billing_month'],
                        'amount'               => number_format((float)$r['amount'], 2, '.', ''),
                        'subtotal'             => number_format((float)($r['subtotal'] ?? 0), 2, '.', ''),
                        'previous_balance'     => number_format((float)($r['previous_balance'] ?? 0), 2, '.', ''),
                        'discount'             => number_format((float)$r['discount'], 2, '.', ''),
                        'discount_amount'      => number_format((float)($r['discount_amount'] ?? 0), 2, '.', ''),
                        'discount_pct'         => number_format((float)($r['discount_pct'] ?? 0), 2, '.', ''),
                        'discount_reason'      => $r['discount_reason'] ?? '',
                        'additional_charges'   => number_format((float)($r['additional_charges'] ?? 0), 2, '.', ''),
                        'additional_charges_description' => $r['additional_charges_description'] ?? '',
                        'equipment_charges'    => number_format((float)($r['equipment_charges'] ?? 0), 2, '.', ''),
                        'installation_charges' => number_format((float)($r['installation_charges'] ?? 0), 2, '.', ''),
                        'items_total'          => number_format((float)($r['items_total'] ?? 0), 2, '.', ''),
                        'tax'                  => number_format((float)$r['tax'], 2, '.', ''),
                        'tax_pct'              => number_format((float)($r['tax_pct'] ?? 0), 2, '.', ''),
                        'wht_amount'           => number_format((float)($r['wht_amount'] ?? 0), 2, '.', ''),
                        'wht_pct'              => number_format((float)($r['wht_pct'] ?? 0), 2, '.', ''),
                        'late_fee'             => number_format((float)($r['late_fee'] ?? 0), 2, '.', ''),
                        'total'                => number_format((float)$r['total'], 2, '.', ''),
                        'paid_amount'          => number_format((float)($r['paid_amount'] ?? 0), 2, '.', ''),
                        'balance_amount'       => number_format((float)($r['balance_amount'] ?? 0), 2, '.', ''),
                        'total_paid'           => number_format((float)($r['total_paid'] ?? 0), 2, '.', ''),
                        'total_due'            => number_format((float)($r['total_due'] ?? 0), 2, '.', ''),
                        'due_date'             => $r['due_date'],
                        'issue_date'           => $r['issue_date'],
                        'invoice_period_start' => $r['invoice_period_start'],
                        'invoice_period_end'   => $r['invoice_period_end'],
                        'status'               => $r['status'],
                        'notes'                => $r['notes'] ?? '',
                        'notes_internal'       => $r['notes_internal'] ?? '',
                        'customer_notes'       => $r['customer_notes'] ?? '',
                        'batch_id'             => $r['batch_id'] ?? '',
                        'invoice_type'         => $r['invoice_type'] ?? 'recurring',
                        'parent_invoice_id'    => (int)($r['parent_invoice_id'] ?? 0),
                        'is_credit_note'       => (int)($r['is_credit_note'] ?? 0),
                        'currency'             => $r['currency'] ?? 'PKR',
                        'proration_days'       => (int)($r['proration_days'] ?? 0),
                        'proration_amount'     => number_format((float)($r['proration_amount'] ?? 0), 2, '.', ''),
                        'paid_date'            => $r['paid_date'],
                        'payment_method_summary' => $r['payment_method_summary'] ?? '',
                        'cancel_date'          => $r['cancel_date'],
                        'cancel_reason'        => $r['cancel_reason'] ?? '',
                        'cancelled_by_user_id' => (int)($r['cancelled_by_user_id'] ?? 0),
                        'created_at'           => $r['created_at']
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getInvoiceDetail': {
                autoFlipOverdue($conn);
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }
                $sql = "SELECT i.*, c.full_name, c.customer_code, c.phone, c.email, c.address, c.area_id,
                               a.name AS area_name, a.city AS area_city,
                               p.name AS package_name, p.speed_mbps, p.billing_cycle
                        FROM invoices i
                        LEFT JOIN customers c ON c.id = i.customer_id
                        LEFT JOIN areas a     ON a.id = c.area_id
                        LEFT JOIN packages p  ON p.id = i.package_id
                        WHERE i.id = ? AND i.is_deleted = 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$r) {
                    echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                    exit();
                }
                // collector area scope
                if (isAreaScoped() && !areaAllowed($r['area_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                echo json_encode([
                    'success' => true,
                    'data'    => [
                        'id'                   => (int)$r['id'],
                        'invoice_number'       => $r['invoice_number'],
                        'customer_id'          => (int)$r['customer_id'],
                        'customer_name'        => $r['full_name'] ?? '',
                        'customer_code'        => $r['customer_code'] ?? '',
                        'phone'                => $r['phone'] ?? '',
                        'email'                => $r['email'] ?? '',
                        'address'              => $r['address'] ?? '',
                        'area_name'            => $r['area_name'] ?? '',
                        'area_city'            => $r['area_city'] ?? '',
                        'package_id'           => (int)$r['package_id'],
                        'package_name'         => $r['package_name'] ?? '',
                        'speed_mbps'           => (int)($r['speed_mbps'] ?? 0),
                        'billing_cycle'        => $r['billing_cycle'] ?? 'monthly',
                        'billing_month'        => $r['billing_month'],
                        'amount'               => number_format((float)$r['amount'], 2, '.', ''),
                        'subtotal'             => number_format((float)($r['subtotal'] ?? 0), 2, '.', ''),
                        'previous_balance'     => number_format((float)($r['previous_balance'] ?? 0), 2, '.', ''),
                        'discount'             => number_format((float)$r['discount'], 2, '.', ''),
                        'discount_amount'      => number_format((float)($r['discount_amount'] ?? 0), 2, '.', ''),
                        'discount_pct'         => number_format((float)($r['discount_pct'] ?? 0), 2, '.', ''),
                        'discount_reason'      => $r['discount_reason'] ?? '',
                        'additional_charges'   => number_format((float)($r['additional_charges'] ?? 0), 2, '.', ''),
                        'additional_charges_description' => $r['additional_charges_description'] ?? '',
                        'equipment_charges'    => number_format((float)($r['equipment_charges'] ?? 0), 2, '.', ''),
                        'installation_charges' => number_format((float)($r['installation_charges'] ?? 0), 2, '.', ''),
                        'tax'                  => number_format((float)$r['tax'], 2, '.', ''),
                        'tax_pct'              => number_format((float)($r['tax_pct'] ?? 0), 2, '.', ''),
                        'wht_amount'           => number_format((float)($r['wht_amount'] ?? 0), 2, '.', ''),
                        'wht_pct'              => number_format((float)($r['wht_pct'] ?? 0), 2, '.', ''),
                        'late_fee'             => number_format((float)($r['late_fee'] ?? 0), 2, '.', ''),
                        'total'                => number_format((float)$r['total'], 2, '.', ''),
                        'paid_amount'          => number_format((float)($r['paid_amount'] ?? 0), 2, '.', ''),
                        'balance_amount'       => number_format((float)($r['balance_amount'] ?? 0), 2, '.', ''),
                        'total_paid'           => number_format((float)($r['total_paid'] ?? 0), 2, '.', ''),
                        'total_due'            => number_format((float)($r['total_due'] ?? 0), 2, '.', ''),
                        'due_date'             => $r['due_date'],
                        'issue_date'           => $r['issue_date'],
                        'invoice_period_start' => $r['invoice_period_start'],
                        'invoice_period_end'   => $r['invoice_period_end'],
                        'status'               => $r['status'],
                        'notes'                => $r['notes'] ?? '',
                        'notes_internal'       => $r['notes_internal'] ?? '',
                        'customer_notes'       => $r['customer_notes'] ?? '',
                        'batch_id'             => $r['batch_id'] ?? '',
                        'invoice_type'         => $r['invoice_type'] ?? 'recurring',
                        'parent_invoice_id'    => (int)($r['parent_invoice_id'] ?? 0),
                        'is_credit_note'       => (int)($r['is_credit_note'] ?? 0),
                        'currency'             => $r['currency'] ?? 'PKR',
                        'proration_days'       => (int)($r['proration_days'] ?? 0),
                        'proration_amount'     => number_format((float)($r['proration_amount'] ?? 0), 2, '.', ''),
                        'paid_date'            => $r['paid_date'] ?? null,
                        'payment_method_summary' => $r['payment_method_summary'] ?? '',
                        'package_name_at_time'  => $r['package_name_at_time'] ?? '',
                        'package_speed_at_time' => (int)($r['package_speed_at_time'] ?? 0),
                        'package_price_at_time' => number_format((float)($r['package_price_at_time'] ?? 0), 2, '.', ''),
                        'cancel_date'          => $r['cancel_date'] ?? null,
                        'sent_at'              => $r['sent_at'] ?? null,
                        'cancelled_at'         => $r['cancelled_at'] ?? null,
                        'cancel_reason'        => $r['cancel_reason'] ?? '',
                        'cancelled_by_user_id' => (int)($r['cancelled_by_user_id'] ?? 0),
                        'items_total'          => number_format((float)($r['items_total'] ?? 0), 2, '.', ''),
                        'items'                => invoiceStockItems($conn, $id),
                        'created_at'           => $r['created_at']
                    ]
                ]);
                exit();
            }

            case 'getParentInvoices': {
                // for credit-note / adjustment parent picker
                $scope = areaScope('c');
                $stmt = $conn->prepare("SELECT i.id, i.invoice_number, i.total, c.full_name
                                        FROM invoices i
                                        LEFT JOIN customers c ON c.id = i.customer_id
                                        WHERE i.is_deleted = 0
                                          AND i.invoice_type IN ('recurring','one_time','proforma')
                                          $scope
                                        ORDER BY i.id DESC LIMIT 500");
                $stmt->execute();
                $res = $stmt->get_result();
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $out[] = [
                        'id'             => (int)$r['id'],
                        'invoice_number' => $r['invoice_number'],
                        'total'          => number_format((float)$r['total'], 2, '.', ''),
                        'customer_name'  => $r['full_name'] ?? ''
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $out]);
                exit();
            }

            case 'getCustomerPriorBalance': {
                $cid = (int)($_GET['customer_id'] ?? 0);
                $excId = (int)($_GET['exclude_invoice_id'] ?? 0);
                if ($cid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid customer']);
                    exit();
                }
                if ($excId > 0) {
                    $stmt = $conn->prepare("SELECT IFNULL(SUM(balance_amount), 0) AS bal
                                            FROM invoices
                                            WHERE customer_id = ? AND is_deleted = 0
                                              AND status IN ('unpaid','partial','overdue')
                                              AND id != ?");
                    $stmt->bind_param("ii", $cid, $excId);
                } else {
                    $stmt = $conn->prepare("SELECT IFNULL(SUM(balance_amount), 0) AS bal
                                            FROM invoices
                                            WHERE customer_id = ? AND is_deleted = 0
                                              AND status IN ('unpaid','partial','overdue')");
                    $stmt->bind_param("i", $cid);
                }
                $stmt->execute();
                $bal = (float)$stmt->get_result()->fetch_assoc()['bal'];
                $stmt->close();
                echo json_encode(['success' => true, 'previous_balance' => round($bal, 2)]);
                exit();
            }

            case 'getActiveCustomers': {
                $scope = areaScope('c');
                $stmt = $conn->prepare("SELECT c.id, c.customer_code, c.full_name, c.package_id, c.area_id, p.price, p.name AS pkg_name
                                        FROM customers c LEFT JOIN packages p ON p.id = c.package_id
                                        WHERE c.is_deleted = 0 AND c.connection_status = 'active' $scope
                                        ORDER BY c.full_name ASC");
                $stmt->execute();
                $res = $stmt->get_result();
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $out[] = [
                        'id'            => (int)$r['id'],
                        'customer_code' => $r['customer_code'],
                        'full_name'     => $r['full_name'],
                        'package_id'    => (int)$r['package_id'],
                        'area_id'       => (int)$r['area_id'],
                        'price'         => (float)($r['price'] ?? 0),
                        'pkg_name'      => $r['pkg_name'] ?? ''
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $out]);
                exit();
            }

            case 'getPackages': {
                $res = $conn->query("SELECT id, name, speed_mbps, price FROM packages WHERE is_deleted = 0 AND status = 'active' ORDER BY price ASC");
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $out[] = [
                        'id'         => (int)$r['id'],
                        'name'       => $r['name'],
                        'speed_mbps' => (int)$r['speed_mbps'],
                        'price'      => (float)$r['price']
                    ];
                }
                echo json_encode(['success' => true, 'data' => $out]);
                exit();
            }

            case 'getAreas': {
                $res = $conn->query("SELECT id, name, city FROM areas WHERE is_deleted = 0 AND status = 'active' ORDER BY name ASC");
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'city' => $r['city'] ?? ''];
                }
                echo json_encode(['success' => true, 'data' => $out]);
                exit();
            }

            // stock picker — what is actually sellable right now
            case 'getStockItems': {
                $rows = [];
                $r = $conn->query("SELECT id, item_code, name, unit, tracking_type, current_qty, sale_price, purchase_price
                                   FROM stock_items WHERE is_deleted = 0 AND status = 'active' ORDER BY name");
                while ($x = $r->fetch_assoc()) {
                    $rows[] = ['id' => (int)$x['id'], 'code' => $x['item_code'], 'name' => $x['name'],
                               'unit' => $x['unit'], 'track' => $x['tracking_type'],
                               'qty' => (float)$x['current_qty'], 'sell' => (float)$x['sale_price'], 'buy' => (float)$x['purchase_price']];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            // serial units sitting in store for one item
            case 'getItemSerials': {
                $iid = (int)($_GET['item_id'] ?? 0);
                $out = [];
                $st = $conn->prepare("SELECT serial FROM equipment WHERE item_id = ? AND status = 'in_stock' AND is_deleted = 0 ORDER BY serial");
                $st->bind_param("i", $iid);
                $st->execute();
                $r = $st->get_result();
                while ($x = $r->fetch_assoc()) $out[] = $x['serial'];
                $st->close();
                echo json_encode(['success' => true, 'data' => $out]);
                exit();
            }

            // billed stock lines — feeds the edit form
            case 'getInvoiceItems': {
                $iid = (int)($_GET['id'] ?? 0);
                echo json_encode(['success' => true, 'data' => $iid > 0 ? invoiceStockItems($conn, $iid) : []]);
                exit();
            }

            case 'addInvoice':
            case 'updateInvoice': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!canViewFinances()) {
                    echo json_encode(['success' => false, 'message' => 'You are not allowed to manage invoices']);
                    exit();
                }
                $isEdit  = $_GET['action'] === 'updateInvoice';
                $id      = (int)($_POST['id'] ?? 0);
                $custId  = (int)($_POST['customer_id'] ?? 0);
                $pkgId   = (int)($_POST['package_id']  ?? 0);
                $bMonth  = trim($_POST['billing_month'] ?? '');
                $amount  = (float)($_POST['amount']    ?? 0);
                $prevBal = (float)($_POST['previous_balance'] ?? 0);
                $disc    = (float)($_POST['discount']  ?? 0);
                $tax     = (float)($_POST['tax']       ?? 0);
                $lateFee = (float)($_POST['late_fee']  ?? 0);
                $dueDate = trim($_POST['due_date']     ?? '');
                $issueDate = trim($_POST['issue_date'] ?? '');
                $perStart  = trim($_POST['invoice_period_start'] ?? '');
                $perEnd    = trim($_POST['invoice_period_end']   ?? '');
                $notes   = trim($_POST['notes']       ?? '');
                $status  = trim($_POST['status']      ?? 'unpaid');

                // new structured fields
                $invType    = trim($_POST['invoice_type'] ?? 'recurring');
                $parentInv  = (int)($_POST['parent_invoice_id'] ?? 0);
                $equipChg   = (float)($_POST['equipment_charges']    ?? 0);
                $instChg    = (float)($_POST['installation_charges'] ?? 0);
                $addChg     = (float)($_POST['additional_charges']   ?? 0);
                $addChgDesc = trim($_POST['additional_charges_description'] ?? '');
                $discAmt    = (float)($_POST['discount_amount'] ?? 0);
                $discPct    = (float)($_POST['discount_pct']    ?? 0);
                $discReason = trim($_POST['discount_reason']    ?? '');
                $taxPct     = (float)($_POST['tax_pct']    ?? 0);
                $whtAmt     = (float)($_POST['wht_amount'] ?? 0);
                $whtPct     = (float)($_POST['wht_pct']    ?? 0);
                $currency   = strtoupper(trim($_POST['currency'] ?? 'PKR'));
                $proDays    = (int)($_POST['proration_days']   ?? 0);
                $proAmt     = (float)($_POST['proration_amount'] ?? 0);
                $custNotes  = trim($_POST['customer_notes'] ?? '');
                $intNotes   = trim($_POST['notes_internal'] ?? '');

                // validate
                if ($custId <= 0 || $pkgId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Customer and package are required']);
                    exit();
                }
                if (!preg_match('/^\d{4}-\d{2}$/', $bMonth)) {
                    echo json_encode(['success' => false, 'message' => 'Billing month must be YYYY-MM']);
                    exit();
                }
                // credit_note may be negative; others must be > 0
                if ($invType !== 'credit_note' && $invType !== 'adjustment' && $amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
                    exit();
                }
                if ($disc < 0 || $tax < 0 || $prevBal < 0 || $lateFee < 0 || $equipChg < 0 || $instChg < 0 || $addChg < 0 || $whtAmt < 0) {
                    echo json_encode(['success' => false, 'message' => 'Negative values not allowed']);
                    exit();
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid due date']);
                    exit();
                }
                if ($issueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid issue date']);
                    exit();
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $perStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $perEnd)) {
                    echo json_encode(['success' => false, 'message' => 'Invoice period start and end are required']);
                    exit();
                }
                if (strtotime($perEnd) < strtotime($perStart)) {
                    echo json_encode(['success' => false, 'message' => 'Period end must be on/after start']);
                    exit();
                }
                if (!in_array($status, ['unpaid','partial','paid','overdue','cancelled'], true)) {
                    $status = 'unpaid';
                }
                if (!in_array($invType, ['recurring','one_time','credit_note','proforma','adjustment'], true)) {
                    $invType = 'recurring';
                }
                if (!in_array($currency, ['PKR','USD','EUR'], true)) $currency = 'PKR';
                // credit_note / adjustment require a parent invoice
                if (($invType === 'credit_note' || $invType === 'adjustment') && $parentInv <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Parent invoice required for credit note / adjustment']);
                    exit();
                }
                if ($issueDate === '') $issueDate = date('Y-m-d');

                // stock lines — on a re-save the old qty goes back to the store FIRST, then the
                // new lines are re-checked against live stock. Everything below is one transaction.
                $conn->begin_transaction();
                try {
                    if ($isEdit && $id > 0) releaseInvoiceStock($conn, $id, true);
                    $stk = invoiceStockLinesClean($conn, $_POST['stock_lines'] ?? '');
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                    exit();
                }
                $itemsTotal = $stk['total'];

                // discount: prefer pct over flat if pct given (compute amt)
                if ($discPct > 0 && $discAmt == 0) {
                    $discAmt = round($amount * $discPct / 100, 2);
                }
                // sync legacy discount field with discount_amount
                if ($discAmt > 0 && $disc == 0) $disc = $discAmt;
                // tax: pct over flat — stock lines are taxable too
                if ($taxPct > 0 && $tax == 0) {
                    $tax = round(($amount + $itemsTotal + $equipChg + $instChg + $addChg - $discAmt) * $taxPct / 100, 2);
                }
                // wht pct → amount
                if ($whtPct > 0 && $whtAmt == 0) {
                    $whtAmt = round(($amount + $itemsTotal + $equipChg + $instChg + $addChg) * $whtPct / 100, 2);
                }

                // allocation-based: each invoice stands alone — no prior-balance roll-in.
                // total = current charges only (stock items + extras + tax + late - discount_amount - wht)
                $subtotal = round($amount, 2);
                $total = round($subtotal + $itemsTotal + $equipChg + $instChg + $addChg + $tax + $lateFee - $discAmt - $whtAmt, 2);
                // prev balance never folded into a new invoice — arrears stay in their own open invoices
                $prevBal = 0.0;
                // credit_note: amounts represent negative impact on customer balance
                // (storage convention: store positive magnitudes here, treat as credit by invoice_type)
                if ($invType === 'credit_note' && $total > 0) {
                    // total_due tracks net effect; credit notes reduce balance
                }
                if ($total < 0 && $invType !== 'credit_note' && $invType !== 'adjustment') $total = 0;

                $isCreditNote = ($invType === 'credit_note') ? 1 : 0;
                $totalPaid    = 0.0;
                $totalDue     = $total;

                if (!$isEdit) {
                    // snapshot package details — at-time fields
                    $pkgSnap = ['name' => '', 'speed' => 0, 'price' => 0.0];
                    $ps = $conn->prepare("SELECT name, speed_mbps, price FROM packages WHERE id = ?");
                    $ps->bind_param("i", $pkgId);
                    $ps->execute();
                    if ($pr = $ps->get_result()->fetch_assoc()) {
                        $pkgSnap['name']  = $pr['name'];
                        $pkgSnap['speed'] = (int)$pr['speed_mbps'];
                        $pkgSnap['price'] = (float)$pr['price'];
                    }
                    $ps->close();
                    $pkgIdAt    = $pkgId;
                    $pkgNameAt  = $pkgSnap['name'];
                    $pkgSpeedAt = $pkgSnap['speed'];
                    $pkgPriceAt = $pkgSnap['price'];

                    $invNum = nextInvoiceNumber($conn);
                    $stmt = $conn->prepare("INSERT INTO invoices
                        (invoice_number, customer_id, package_id, billing_month,
                         amount, subtotal, previous_balance, discount, tax, late_fee, total,
                         due_date, issue_date, invoice_period_start, invoice_period_end,
                         status, notes, created_by_user_id,
                         invoice_type, parent_invoice_id, is_credit_note, currency,
                         equipment_charges, installation_charges, additional_charges, additional_charges_description,
                         discount_amount, discount_pct, discount_reason,
                         tax_pct, wht_amount, wht_pct,
                         total_paid, total_due,
                         proration_days, proration_amount,
                         customer_notes, notes_internal,
                         package_id_at_time, package_name_at_time, package_speed_at_time, package_price_at_time)
                        VALUES (?, ?, ?, ?,
                                ?, ?, ?, ?, ?, ?, ?,
                                ?, ?, ?, ?,
                                ?, ?, ?,
                                ?, ?, ?, ?,
                                ?, ?, ?, ?,
                                ?, ?, ?,
                                ?, ?, ?,
                                ?, ?,
                                ?, ?,
                                ?, ?,
                                ?, ?, ?, ?)");
                    // type-letter map (count = 41):
                    // s(invNum) i(custId) i(pkgId) s(bMonth)                                      → siis  (4)
                    // d(amt) d(subtot) d(prevBal) d(disc) d(tax) d(lateFee) d(total)              → ddddddd (7)
                    // s(due) s(issue) s(perStart) s(perEnd)                                       → ssss (4)
                    // s(status) s(notes) i(user_id)                                               → ssi (3)
                    // s(invType) i(parentInv) i(isCreditNote) s(currency)                         → siis (4)
                    // d(equipChg) d(instChg) d(addChg) s(addChgDesc)                              → ddds (4)
                    // d(discAmt) d(discPct) s(discReason)                                         → dds (3)
                    // d(taxPct) d(whtAmt) d(whtPct)                                               → ddd (3)
                    // d(totalPaid) d(totalDue)                                                    → dd (2)
                    // i(proDays) d(proAmt)                                                        → id (2)
                    // s(custNotes) s(intNotes)                                                    → ss (2)
                    // i(pkgIdAt) s(pkgNameAt) i(pkgSpeedAt) d(pkgPriceAt)                         → isid (4)
                    // total: 4+7+4+3+4+4+3+3+2+2+2+4 = 42 — verified
                    $types = "siis" . "ddddddd" . "ssss" . "ssi" . "siis" . "ddds" . "dds" . "ddd" . "dd" . "id" . "ss" . "isid";
                    $stmt->bind_param($types,
                        $invNum, $custId, $pkgId, $bMonth,
                        $amount, $subtotal, $prevBal, $disc, $tax, $lateFee, $total,
                        $dueDate, $issueDate, $perStart, $perEnd,
                        $status, $notes, $user_id,
                        $invType, $parentInv, $isCreditNote, $currency,
                        $equipChg, $instChg, $addChg, $addChgDesc,
                        $discAmt, $discPct, $discReason,
                        $taxPct, $whtAmt, $whtPct,
                        $totalPaid, $totalDue,
                        $proDays, $proAmt,
                        $custNotes, $intNotes,
                        $pkgIdAt, $pkgNameAt, $pkgSpeedAt, $pkgPriceAt
                    );
                    if ($stmt->execute()) {
                        $newId = $stmt->insert_id;
                        $stmt->close();
                        try {
                            writeInvoiceStock($conn, $newId, $stk['lines'], $user_id);
                            syncInvoiceLedger($conn, $newId);
                            $conn->commit();
                        } catch (Throwable $e) {
                            $conn->rollback();
                            echo json_encode(['success' => false, 'message' => 'Stock issue failed: ' . $e->getMessage()]);
                            exit();
                        }
                        $stkNote = count($stk['lines']) ? ' + ' . count($stk['lines']) . ' stock line(s)' : '';
                        logActivity($user_id, $username, 'Invoice Created', "Created $invNum ($invType) for customer #$custId — $total$stkNote");
                        try { createNotificationForAdmins('Invoice Created', "$invNum has been created.", 'info', 'invoices.php?id=' . $newId); } catch (Exception $e) {}
                        echo json_encode(['success' => true, 'message' => 'Invoice created', 'id' => $newId, 'invoice_number' => $invNum]);
                    } else {
                        $err = $stmt->error; $stmt->close();
                        $conn->rollback();
                        echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                    }
                    exit();
                }

                if ($id <= 0) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Invalid invoice id']);
                    exit();
                }
                $stmt = $conn->prepare("UPDATE invoices SET
                                            customer_id=?, package_id=?, billing_month=?,
                                            amount=?, subtotal=?, previous_balance=?, discount=?, tax=?, late_fee=?, total=?,
                                            due_date=?, issue_date=?, invoice_period_start=?, invoice_period_end=?,
                                            status=?, notes=?,
                                            invoice_type=?, parent_invoice_id=?, is_credit_note=?, currency=?,
                                            equipment_charges=?, installation_charges=?, additional_charges=?, additional_charges_description=?,
                                            discount_amount=?, discount_pct=?, discount_reason=?,
                                            tax_pct=?, wht_amount=?, wht_pct=?,
                                            proration_days=?, proration_amount=?,
                                            customer_notes=?, notes_internal=?
                                        WHERE id=? AND is_deleted = 0");
                // type-letter map (count = 35):
                // i(custId) i(pkgId) s(bMonth)                                  → iis (3)
                // d(amt) d(subtot) d(prevBal) d(disc) d(tax) d(lateFee) d(tot)  → ddddddd (7)
                // s(due) s(issue) s(perStart) s(perEnd) s(status) s(notes)      → ssssss (6)
                // s(invType) i(parentInv) i(isCreditNote) s(currency)           → siis (4)
                // d(equipChg) d(instChg) d(addChg) s(addChgDesc)                → ddds (4)
                // d(discAmt) d(discPct) s(discReason)                           → dds (3)
                // d(taxPct) d(whtAmt) d(whtPct)                                 → ddd (3)
                // i(proDays) d(proAmt) s(custNotes) s(intNotes)                 → idss (4)
                // i(id)                                                         → i (1)
                // total: 3+7+6+4+4+3+3+4+1 = 35 — verified
                $subtotalUpd = round($amount, 2);
                $types = "iis" . "ddddddd" . "ssssss" . "siis" . "ddds" . "dds" . "ddd" . "idss" . "i";
                $stmt->bind_param($types,
                    $custId, $pkgId, $bMonth,
                    $amount, $subtotalUpd, $prevBal, $disc, $tax, $lateFee, $total,
                    $dueDate, $issueDate, $perStart, $perEnd, $status, $notes,
                    $invType, $parentInv, $isCreditNote, $currency,
                    $equipChg, $instChg, $addChg, $addChgDesc,
                    $discAmt, $discPct, $discReason,
                    $taxPct, $whtAmt, $whtPct,
                    $proDays, $proAmt, $custNotes, $intNotes,
                    $id
                );
                if ($stmt->execute()) {
                    $stmt->close();
                    try {
                        writeInvoiceStock($conn, $id, $stk['lines'], $user_id);
                        // re-sync paid/balance after total change
                        recomputeInvoiceFromAllocations($conn, $id);
                        syncInvoiceLedger($conn, $id);
                        $conn->commit();
                    } catch (Throwable $e) {
                        $conn->rollback();
                        echo json_encode(['success' => false, 'message' => 'Stock issue failed: ' . $e->getMessage()]);
                        exit();
                    }
                    logActivity($user_id, $username, 'Invoice Updated', "Updated invoice #$id — total $total");
                    echo json_encode(['success' => true, 'message' => 'Invoice updated']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                }
                exit();
            }

            case 'cancelInvoice': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                // admin+ only
                if (!isAdmin() && !isSuperAdmin()) {
                    echo json_encode(['success' => false, 'message' => 'Only admin can cancel invoices']);
                    exit();
                }
                $id     = (int)($_POST['id'] ?? 0);
                $reason = trim($_POST['cancel_reason'] ?? '');
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }
                if ($reason === '') {
                    echo json_encode(['success' => false, 'message' => 'Cancel reason is required']);
                    exit();
                }
                if (strlen($reason) > 255) $reason = substr($reason, 0, 255);

                $stmt = $conn->prepare("SELECT invoice_number, status FROM invoices WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                    exit();
                }
                if ($row['status'] === 'cancelled') {
                    echo json_encode(['success' => false, 'message' => 'Already cancelled']);
                    exit();
                }
                // mark cancelled, set cancel_date + cancelled_by_user_id — billed stock goes back to the store
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE invoices SET status='cancelled', cancel_date=CURDATE(), cancel_reason=?, cancelled_by_user_id=?, cancelled_at=NOW() WHERE id=?");
                    $stmt->bind_param("sii", $reason, $user_id, $id);
                    $stmt->execute();
                    $stmt->close();
                    $freed = releaseInvoiceStock($conn, $id, false);
                    // re-sync paid/balance
                    recomputeInvoiceFromAllocations($conn, $id);
                    syncInvoiceLedger($conn, $id);
                    $conn->commit();
                    logActivity($user_id, $username, 'Invoice Cancelled', "Cancelled " . $row['invoice_number'] . " — reason: $reason");
                    echo json_encode(['success' => true, 'message' => 'Invoice cancelled' . (count($freed) ? ' — stock returned to store' : '')]);
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $e->getMessage()]);
                }
                exit();
            }

            case 'deleteInvoice': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!can('invoices', 'd')) {
                    echo json_encode(['success' => false, 'message' => 'Only super admin can cancel invoices']);
                    exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                $reason = trim($_POST['cancel_reason'] ?? '');
                if (strlen($reason) > 255) $reason = substr($reason, 0, 255);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }
                // grab inv num for log
                $stmt = $conn->prepare("SELECT invoice_number FROM invoices WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                    exit();
                }
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE invoices SET is_deleted = 1, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?");
                    $stmt->bind_param("si", $reason, $id);
                    $stmt->execute();
                    $stmt->close();
                    $freed = releaseInvoiceStock($conn, $id, false); // stock never left the store on a killed invoice
                    syncInvoiceLedger($conn, $id);
                    $conn->commit();
                    $logTxt = "Cancelled invoice " . $row['invoice_number'] . ($reason !== '' ? " — reason: $reason" : '');
                    logActivity($user_id, $username, 'Invoice Cancelled', $logTxt);
                    echo json_encode(['success' => true, 'message' => 'Invoice cancelled' . (count($freed) ? ' — stock returned to store' : '')]);
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Failed to cancel: ' . $e->getMessage()]);
                }
                exit();
            }

            case 'batchPreview': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!canViewFinances()) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed']);
                    exit();
                }
                $month = trim($_POST['month'] ?? '');
                $skip  = (int)($_POST['skip_existing'] ?? 1);
                if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid month']);
                    exit();
                }

                if ($skip) {
                    $sql = "SELECT c.id, c.customer_code, c.full_name, p.name AS pkg_name, p.price
                            FROM customers c
                            LEFT JOIN packages p ON p.id = c.package_id
                            WHERE c.is_deleted = 0 AND c.connection_status = 'active'
                              AND NOT EXISTS (
                                  SELECT 1 FROM invoices i
                                  WHERE i.customer_id = c.id AND i.billing_month = ? AND i.is_deleted = 0
                              )
                            ORDER BY c.full_name ASC";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("s", $month);
                } else {
                    $sql = "SELECT c.id, c.customer_code, c.full_name, p.name AS pkg_name, p.price
                            FROM customers c
                            LEFT JOIN packages p ON p.id = c.package_id
                            WHERE c.is_deleted = 0 AND c.connection_status = 'active'
                            ORDER BY c.full_name ASC";
                    $stmt = $conn->prepare($sql);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $list = [];
                while ($r = $res->fetch_assoc()) {
                    $list[] = [
                        'id'            => (int)$r['id'],
                        'customer_code' => $r['customer_code'],
                        'full_name'     => $r['full_name'],
                        'pkg_name'      => $r['pkg_name'] ?? '',
                        'price'         => (float)($r['price'] ?? 0)
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'count' => count($list), 'data' => $list, 'month' => $month]);
                exit();
            }

            case 'batchGenerate': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!canViewFinances()) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed']);
                    exit();
                }
                $month  = trim($_POST['month'] ?? '');
                $dueDay = max(1, min(28, (int)($_POST['due_day'] ?? 10)));
                $taxPct = max(0, (float)($_POST['tax_pct'] ?? 0));
                $skip   = (int)($_POST['skip_existing'] ?? 1);

                if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid month']);
                    exit();
                }
                $dueDate = $month . '-' . sprintf('%02d', $dueDay);

                // batch_id once per run
                $batchId   = 'BATCH-' . date('YmdHis');
                $issueDate = date('Y-m-d');
                // period = first/last day of selected month
                $perStart = $month . '-01';
                $perEnd   = date('Y-m-t', strtotime($perStart));

                // grab eligible customers
                if ($skip) {
                    $sql = "SELECT c.id, c.package_id, p.price
                            FROM customers c LEFT JOIN packages p ON p.id = c.package_id
                            WHERE c.is_deleted = 0 AND c.connection_status = 'active'
                              AND NOT EXISTS (
                                  SELECT 1 FROM invoices i
                                  WHERE i.customer_id = c.id AND i.billing_month = ? AND i.is_deleted = 0
                              )";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("s", $month);
                } else {
                    $sql = "SELECT c.id, c.package_id, p.price
                            FROM customers c LEFT JOIN packages p ON p.id = c.package_id
                            WHERE c.is_deleted = 0 AND c.connection_status = 'active'";
                    $stmt = $conn->prepare($sql);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $eligible = [];
                while ($r = $res->fetch_assoc()) $eligible[] = $r;
                $stmt->close();

                if (!count($eligible)) {
                    echo json_encode(['success' => true, 'created' => 0, 'message' => 'No eligible customers']);
                    exit();
                }

                // allocation-based: new batch invoices stand alone — previous_balance always 0,
                // customer arrears stay in their own open invoices (surface via aging/ledger)
                $ins = $conn->prepare("INSERT INTO invoices
                    (invoice_number, customer_id, package_id, billing_month, amount, previous_balance, discount, tax, late_fee, total,
                     due_date, issue_date, invoice_period_start, invoice_period_end, status, created_by_user_id, batch_id)
                    VALUES (?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?, ?, ?, ?, 'unpaid', ?, ?)");
                // s i i s d d s s s s i s
                $created = 0; $failed = 0; $batchIds = [];
                foreach ($eligible as $e) {
                    $cid    = (int)$e['id'];
                    $pid    = (int)$e['package_id'];
                    $amt    = (float)($e['price'] ?? 0);
                    if ($amt <= 0 || $pid <= 0) { $failed++; continue; }

                    $taxAmt = round($amt * $taxPct / 100, 2);
                    $total  = round($amt + $taxAmt, 2);   // current charges only
                    $invNum = nextInvoiceNumber($conn);
                    // 13 params: s(invNum) i(cid) i(pid) s(month) d(amt) d(tax) d(total) s(due) s(issue) s(perStart) s(perEnd) i(user) s(batch)
                    $ins->bind_param("siisdddssssis",
                        $invNum, $cid, $pid, $month, $amt, $taxAmt, $total,
                        $dueDate, $issueDate, $perStart, $perEnd, $user_id, $batchId
                    );
                    if ($ins->execute()) { $created++; $batchIds[] = $conn->insert_id; } else $failed++;
                }
                $ins->close();
                foreach ($batchIds as $bid) syncInvoiceLedger($conn, $bid);

                logActivity($user_id, $username, 'Invoice Batch Generated', "Batch $batchId month $month — created $created, failed $failed, due $dueDate, tax {$taxPct}%");
                try { createNotificationForAdmins('Invoice Batch Generated', "$created invoices created for $month (batch $batchId).", 'success', 'invoices.php'); } catch (Exception $e) {}

                echo json_encode(['success' => true, 'created' => $created, 'failed' => $failed, 'month' => $month, 'batch_id' => $batchId]);
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Invoices.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

$canWrite  = canViewFinances();
$canDelete = can('invoices', 'd');
$isColl    = isCollector();

// detail mode?
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// settings for printable header
$siteName = getSetting('site_name', 'ISP Billing');
$siteLogo = getSetting('site_logo', '');
$copyTxt  = getSetting('copyright_text', '');
$curSym   = getSetting('currency_symbol', '$');
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Invoices - ISP Billing</title>

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
                    <a href="invoices.php">Invoices</a>
                    <span class="breadcrumb-sep">/</span>
                    <span>Detail</span>
                <?php else: ?>
                    <span>Invoices</span>
                <?php endif; ?>
            </div>
            <div class="header">
                <h1><i class="fas fa-file-invoice-dollar"></i> Invoices</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if ($detailId > 0): ?>
            <!-- ─── DETAIL / PRINT VIEW ─── -->
            <div id="detailWrap" data-id="<?php echo (int)$detailId; ?>">
                <div class="printable-actions">
                    <a href="invoices.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
                    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print A4</button>
                    <button class="btn btn-secondary" onclick="printInvoiceThermal()"><i class="fas fa-receipt"></i> Thermal</button>
                    <?php if ($canWrite): ?>
                    <a href="payments.php?invoice_id=<?php echo (int)$detailId; ?>" class="btn btn-success" id="payBtn"><i class="fas fa-money-bill-wave"></i> Record Payment</a>
                    <?php endif; ?>
                    <?php if (isAdmin() || isSuperAdmin()): ?>
                    <button class="btn btn-warning initially-hidden" id="cancelInvBtn" onclick="cancelInvoiceFromDetail()"><i class="fas fa-ban"></i> Cancel Invoice</button>
                    <?php endif; ?>
                </div>

                <div id="detailSkeleton" class="printable">
                    <div class="skeleton-line" style="height:30px;width:60%;margin-bottom:12px"></div>
                    <div class="skeleton-line" style="height:18px;width:40%;margin-bottom:24px"></div>
                    <div class="skeleton-line" style="height:80px"></div>
                </div>

                <div id="detailBody" class="printable initially-hidden">
                    <!-- filled by JS -->
                </div>
            </div>
            <?php else: ?>
            <!-- ─── LIST VIEW ─── -->
            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Billing &amp; Invoices</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadInvoices()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if ($canWrite): ?>
                        <button class="btn btn-warning btn-cta-pulse" onclick="openBatchWizard()" title="Generate this month's bills for all active customers">
                            <i class="fas fa-bolt"></i> Generate Monthly Bills
                        </button>
                        <?php endif; ?>
                        <?php if (can('invoices', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <?php if ($canWrite): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> New Invoice
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- chevron pipeline (status quick-filter) — labels match your demo terminology -->
                <div class="chevron-pipeline" id="chevronPipeline" role="tablist" aria-label="Filter by status">
                    <div class="chevron-tab cv-all active"         data-status="all"         role="tab" tabindex="0">
                        <i class="fas fa-list"></i> All Bills <span class="cv-count" id="cv-c-all">0</span>
                    </div>
                    <div class="chevron-tab cv-outstanding "         data-status="outstanding"         role="tab" tabindex="0">
                        <i class="fas fa-hourglass-half"></i> Due <span class="cv-count" id="cv-c-outstanding">0</span>
                    </div>
                    <!-- <div class="chevron-tab cv-outstanding"        data-status="outstanding" role="tab" tabindex="0">
                        <i class="fas fa-hourglass-half"></i> Due Bills <span class="cv-count" id="cv-c-outstanding">0</span>
                    </div> -->
                    <div class="chevron-tab cv-unpaid"             data-status="unpaid"      role="tab" tabindex="0">
                        <i class="fas fa-circle"></i> Pending <span class="cv-count" id="cv-c-unpaid">0</span>
                    </div>
                    <div class="chevron-tab cv-partial"            data-status="partial"     role="tab" tabindex="0">
                        <i class="fas fa-adjust"></i> Running Bill <span class="cv-count" id="cv-c-partial">0</span>
                    </div>
                    <div class="chevron-tab cv-overdue"            data-status="overdue"     role="tab" tabindex="0">
                        <i class="fas fa-triangle-exclamation"></i> Overdue <span class="cv-count" id="cv-c-overdue">0</span>
                    </div>
                    <div class="chevron-tab cv-paid"               data-status="paid"        role="tab" tabindex="0">
                        <i class="fas fa-check-circle"></i> Received <span class="cv-count" id="cv-c-paid">0</span>
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
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Billing Month</label>
                            <div class="filter-input-wrap">
                                <input type="month" id="filterMonth" class="filter-input-styled">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-day"></i> Issue Date Range</label>
                            <div class="filter-dates">
                                <input type="date" id="filterIssueFrom" class="filter-input-styled" title="Issue date from">
                                <span class="filter-dates-sep">to</span>
                                <input type="date" id="filterIssueTo" class="filter-input-styled" title="Issue date to">
                            </div>
                        </div>
                        <?php if (!$isColl): ?>
                        <div class="filter-group">
                            <label><i class="fas fa-map-marked-alt"></i> Area</label>
                            <select id="filterArea">
                                <option value="">All Areas</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <div class="filter-input-wrap filter-search-wrap">
                                <i class="fas fa-search filter-search-icon"></i>
                                <input type="text" id="filterSearch" class="filter-input-styled has-leading-icon" placeholder="Invoice # / Customer / Code">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap" class="table-responsive">
                    <table class="display table-full-width">
                        <thead>
                            <tr>
                                <th>Invoice #</th><th>Customer</th><th>Month</th><th>Issue Date</th>
                                <th>Period</th><th>Amount</th><th>Total</th><th>Paid</th>
                                <th>Balance</th><th>Due Date</th><th>Days Overdue</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr>
                                <?php for ($j = 0; $j < 13; $j++): ?>
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
                    <table id="invoicesTable" class="display table-full-width">
                        <tfoot>
                            <tr>
                                <th colspan="5" class="dt-foot-label">Totals</th>
                                <th></th>
                                <th id="footInvoiced"></th>
                                <th id="footPaid"></th>
                                <th id="footOutstanding"></th>
                                <th></th>
                                <th id="footOverdue"></th>
                                <th></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- empty state -->
                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-file-invoice"></i></div>
                    <div class="empty-state-title">No invoices yet — generate this month's bills in one click.</div>
                    <div class="empty-state-text">Tap "Generate Monthly Bills" above to bulk-create invoices for all active customers.</div>
                    <?php if ($canWrite): ?>
                    <div class="form-actions" style="justify-content:center;margin-top:14px">
                        <button class="btn btn-warning btn-cta-pulse" onclick="openBatchWizard()">
                            <i class="fas fa-bolt"></i> Generate <?php echo date('F Y'); ?> Bills
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$detailId && $canWrite): ?>
    <!-- Invoice Modal -->
    <div class="modal-overlay" id="invoiceModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> New Invoice</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="invoiceForm">
                    <input type="hidden" id="invoiceId" name="id">
                    <input type="hidden" id="invoiceStatus" name="status" value="unpaid">

                    <!-- invoice type / parent -->
                    <h4 class="form-section-title"><i class="fas fa-tag"></i> Invoice Type</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tags"></i> Type *</label>
                            <select id="invoice_type" name="invoice_type" required>
                                <option value="recurring">Recurring</option>
                                <option value="one_time">One-Time</option>
                                <option value="credit_note">Credit Note</option>
                                <option value="proforma">Proforma</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="form-group initially-hidden" id="parentInvoiceGroup">
                            <label><i class="fas fa-link"></i> Parent Invoice *</label>
                            <select id="parent_invoice_id" name="parent_invoice_id"></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Currency</label>
                            <select id="currency" name="currency">
                                <option value="PKR">PKR</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                            </select>
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-user"></i> Customer &amp; Period</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Customer *</label>
                            <select id="customer_id" name="customer_id" required></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-box"></i> Package *</label>
                            <select id="package_id" name="package_id" required></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Billing Month *</label>
                            <input type="month" id="billing_month" name="billing_month" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Due Date *</label>
                            <input type="date" id="due_date" name="due_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Issue Date</label>
                            <input type="date" id="issue_date" name="issue_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-play"></i> Period Start *</label>
                            <input type="date" id="invoice_period_start" name="invoice_period_start" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-stop"></i> Period End *</label>
                            <input type="date" id="invoice_period_end" name="invoice_period_end" required>
                        </div>
                    </div>

                    <!-- line items / charges -->
                    <h4 class="form-section-title"><i class="fas fa-list"></i> Line Items</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-dollar-sign"></i> Subtotal (Package Amount) *</label>
                            <input type="number" id="amount" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-router"></i> Equipment Charges</label>
                            <input type="number" id="equipment_charges" name="equipment_charges" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tools"></i> Installation Charges</label>
                            <input type="number" id="installation_charges" name="installation_charges" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-plus-circle"></i> Additional Charges</label>
                            <input type="number" id="additional_charges" name="additional_charges" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group form-group-span-2">
                            <label><i class="fas fa-info-circle"></i> Additional Charges Description</label>
                            <input type="text" id="additional_charges_description" name="additional_charges_description" maxlength="255" placeholder="What is the extra charge for?">
                        </div>
                    </div>

                    <!-- stock sold on this invoice — every line leaves the store when saved -->
                    <div class="section-header mt-20">
                        <h4 class="form-section-title mb-0"><i class="fas fa-boxes-stacked"></i> Stock Items <span class="muted">(deducted from inventory on save)</span></h4>
                        <div class="btn-group-inline">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addStockLine()"><i class="fas fa-plus"></i> Add Item</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="ledger-table table-full-width" id="stockLineTable">
                            <thead>
                                <tr><th><i class="fas fa-box"></i> Item</th><th><i class="fas fa-hashtag"></i> Qty</th><th><i class="fas fa-tag"></i> Rate</th><th class="num-cell"><i class="fas fa-coins"></i> Total</th><th></th></tr>
                            </thead>
                            <tbody id="stockLineBody"></tbody>
                            <tfoot>
                                <tr><th colspan="3">Items Total</th><th class="num-cell" id="stockLinesTotal">0.00</th><th></th></tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="muted" id="stockLinesEmpty"><i class="fas fa-circle-info"></i> No stock on this invoice — click <strong>Add Item</strong> to bill a router, cable or any inventory item.</p>
                    <input type="hidden" id="stock_lines" name="stock_lines" value="">

                    <!-- discount block -->
                    <h4 class="form-section-title"><i class="fas fa-percent"></i> Discount</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Discount Amount</label>
                            <input type="number" id="discount_amount" name="discount_amount" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Discount %</label>
                            <input type="number" id="discount_pct" name="discount_pct" step="0.01" min="0" max="100" value="0">
                        </div>
                        <div class="form-group form-group-span-2">
                            <label><i class="fas fa-comment-dots"></i> Discount Reason</label>
                            <input type="text" id="discount_reason" name="discount_reason" maxlength="255" placeholder="Loyalty / promo / goodwill etc.">
                        </div>
                        <!-- legacy single 'discount' kept hidden for sync -->
                        <input type="hidden" id="discount" name="discount" value="0">
                    </div>

                    <!-- tax / wht block -->
                    <h4 class="form-section-title"><i class="fas fa-receipt"></i> Tax &amp; Withholding</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Tax %</label>
                            <input type="number" id="tax_pct" name="tax_pct" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-receipt"></i> Tax Amount</label>
                            <input type="number" id="tax" name="tax" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> WHT %</label>
                            <input type="number" id="wht_pct" name="wht_pct" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-minus-circle"></i> WHT Amount</label>
                            <input type="number" id="wht_amount" name="wht_amount" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-exclamation-circle"></i> Late Fee</label>
                            <input type="number" id="late_fee" name="late_fee" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Grand Total (auto)</label>
                            <input type="number" id="total" step="0.01" readonly>
                        </div>
                    </div>

                    <!-- proration / lifecycle -->
                    <h4 class="form-section-title"><i class="fas fa-clock"></i> Proration &amp; Notes</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Proration Days</label>
                            <input type="number" id="proration_days" name="proration_days" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-divide"></i> Proration Amount</label>
                            <input type="number" id="proration_amount" name="proration_amount" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group form-group-span-2">
                            <label><i class="fas fa-sticky-note"></i> Notes</label>
                            <textarea id="notes" name="notes" rows="2" placeholder="Brief invoice note"></textarea>
                        </div>
                        <div class="form-group form-group-span-2">
                            <label><i class="fas fa-eye"></i> Customer-facing Notes (visible on print)</label>
                            <textarea id="customer_notes" name="customer_notes" rows="2" placeholder="Thank-you message, payment instructions, etc."></textarea>
                        </div>
                        <div class="form-group form-group-span-2">
                            <label><i class="fas fa-lock"></i> Internal Notes (staff only)</label>
                            <textarea id="notes_internal" name="notes_internal" rows="2" placeholder="Internal-only remarks (not printed)"></textarea>
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

    <!-- Batch Wizard Modal -->
    <div class="modal-overlay" id="batchModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width:640px">
            <div class="modal-header">
                <h3><i class="fas fa-layer-group"></i> Generate Monthly Batch</h3>
                <button class="close-btn" onclick="closeBatchModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="batch-stepper">
                    <div class="batch-step-pill active" id="pill1">1. Select Month</div>
                    <div class="batch-step-pill" id="pill2">2. Preview</div>
                    <div class="batch-step-pill" id="pill3">3. Confirm</div>
                </div>

                <!-- step 1 -->
                <div class="batch-step active" id="step1">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-calendar"></i> Billing Month *</label>
                            <select id="batchMonth"></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Default Due Day *</label>
                            <input type="number" id="batchDueDay" min="1" max="28" value="10">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Default Tax %</label>
                            <input type="number" id="batchTaxPct" min="0" step="0.01" value="0">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="toggle-switch-label">
                                <input type="checkbox" id="batchSkip" class="toggle-switch" checked>
                                <span>Skip customers who already have an invoice for this month</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-primary" onclick="batchGoto(2)"><i class="fas fa-arrow-right"></i> Next: Preview</button>
                        <button type="button" class="btn btn-secondary" onclick="closeBatchModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </div>

                <!-- step 2 -->
                <div class="batch-step" id="step2">
                    <div class="batch-preview-box">
                        <div class="batch-preview-num" id="previewNum">0</div>
                        <div class="batch-preview-label" id="previewLabel">active customers will receive an invoice</div>
                    </div>
                    <div class="muted" style="font-size:12px;margin-bottom:8px"><i class="fas fa-info-circle"></i> Showing first 20 below.</div>
                    <div id="previewList" style="max-height:240px;overflow:auto;border:1px solid #e0e0e0;border-radius:6px;padding:8px"></div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="batchGoto(1)"><i class="fas fa-arrow-left"></i> Back</button>
                        <button type="button" class="btn btn-primary" onclick="batchGoto(3)" id="toStep3Btn"><i class="fas fa-arrow-right"></i> Next: Confirm</button>
                    </div>
                </div>

                <!-- step 3 -->
                <div class="batch-step" id="step3">
                    <div class="batch-preview-box">
                        <div style="font-size:14px"><strong>Month:</strong> <span id="confMonth">—</span></div>
                        <div style="font-size:14px"><strong>Due Date:</strong> <span id="confDue">—</span></div>
                        <div style="font-size:14px"><strong>Tax %:</strong> <span id="confTax">—</span></div>
                        <div style="font-size:14px"><strong>Customers:</strong> <span id="confCount">—</span></div>
                    </div>
                    <div class="muted" style="font-size:13px"><i class="fas fa-exclamation-triangle"></i> This will create invoices in bulk. Review the preview before generating.</div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="batchGoto(2)"><i class="fas fa-arrow-left"></i> Back</button>
                        <button type="button" class="btn btn-success" onclick="batchGenerate()"><i class="fas fa-bolt"></i> Generate Invoices</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$detailId): ?>
    <!-- Invoice View Modal (tabbed) -->
    <div class="modal-overlay" id="invViewModal">
        <div class="modal modal-360">
            <div class="modal-header">
                <h3 id="invViewTitle"><i class="fas fa-file-invoice-dollar"></i> Invoice</h3>
                <button class="close-btn" onclick="closeInvoiceView()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="vtab-nav">
                    <button class="vtab-btn active" type="button" data-vtab="vt-items"><i class="fas fa-list"></i> Invoice Items</button>
                    <button class="vtab-btn" type="button" data-vtab="vt-pay"><i class="fas fa-money-bill-wave"></i> Payments</button>
                    <button class="vtab-btn" type="button" data-vtab="vt-cust"><i class="fas fa-user"></i> Customer Info</button>
                    <button class="vtab-btn" type="button" data-vtab="vt-time"><i class="fas fa-clock"></i> Timeline</button>
                </div>
                <div class="vtab-pane active" id="vt-items"></div>
                <div class="vtab-pane" id="vt-pay"></div>
                <div class="vtab-pane" id="vt-cust"></div>
                <div class="vtab-pane" id="vt-time"></div>
                <div class="form-actions">
                    <a href="#" id="invViewPrint" target="_blank" class="btn btn-primary"><i class="fas fa-print"></i> Open / Print</a>
                    <button type="button" class="btn btn-secondary" onclick="closeInvoiceView()"><i class="fas fa-times"></i> Close</button>
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

    const CAN_WRITE    = <?php echo $canWrite  ? 'true' : 'false'; ?>;
    const CAN_DELETE   = <?php echo $canDelete ? 'true' : 'false'; ?>;
    const IS_COLL      = <?php echo $isColl    ? 'true' : 'false'; ?>;
    const IS_ADMIN_PLUS = <?php echo (isAdmin() || isSuperAdmin()) ? 'true' : 'false'; ?>;
    const CUR_SYM    = <?php echo json_encode($curSym ?? '$'); ?>;
    const SITE_NAME  = <?php echo json_encode($siteName ?? ''); ?>;
    const SITE_LOGO  = <?php echo json_encode($siteLogo ?? ''); ?>;
    const COPY_TXT   = <?php echo json_encode($copyTxt  ?? ''); ?>;
    const DETAIL_ID  = <?php echo (int)$detailId; ?>;

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function fmtMoney(n) { return CUR_SYM + Number(n || 0).toFixed(2); }
    function fmtDate(iso) {
        if (!iso) return '';
        const d = new Date(iso + (iso.length === 10 ? 'T00:00:00' : ''));
        if (isNaN(d)) return iso;
        return d.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' });
    }
    // whole days a date is past today (0 if not past / no date)
    function daysPast(iso) {
        if (!iso) return 0;
        const d = new Date(iso.slice(0, 10) + 'T00:00:00');
        if (isNaN(d)) return 0;
        const t = new Date(); t.setHours(0, 0, 0, 0);
        const diff = Math.floor((t - d) / 86400000);
        return diff > 0 ? diff : 0;
    }
    // overdue-days badge for unpaid/partial/overdue invoices past due
    function overdueBadge(row) {
        const open = ['unpaid','partial','overdue'].includes(row.status);
        if (!open) return '<span class="muted">—</span>';
        const n = daysPast(row.due_date);
        if (n <= 0) return '<span class="muted">—</span>';
        const cls = n > 30 ? 'danger' : 'warn';
        return '<span class="badge-days ' + cls + '">' + n + 'd</span>';
    }

    // "Apr 1 – Apr 30" style range
    function fmtPeriodRange(s, e) {
        if (!s && !e) return '<span class="muted">—</span>';
        const ds = s ? new Date(s + 'T00:00:00') : null;
        const de = e ? new Date(e + 'T00:00:00') : null;
        const opts = { month:'short', day:'numeric' };
        const a = ds && !isNaN(ds) ? ds.toLocaleDateString(undefined, opts) : '';
        const b = de && !isNaN(de) ? de.toLocaleDateString(undefined, opts) : '';
        if (a && b) return a + ' – ' + b;
        return a || b || '<span class="muted">—</span>';
    }
    </script>

    <?php if ($detailId > 0): ?>
    <script>
    // detail mode
    $(document).ready(function() { loadDetail(); });

    function loadDetail() {
        $.ajax({
            url: '?action=getInvoiceDetail&id=' + DETAIL_ID,
            method: 'GET', dataType: 'json',
            success: function(resp) {
                if (!resp.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' })
                        .then(() => window.location.href = 'invoices.php');
                    return;
                }
                renderDetail(resp.data);
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Connection Error' }); }
        });
    }

    let invDetail = null;   // the A4 sheet and the 80mm slip print the same object

    // 80mm slip of the same invoice — shared builder in page_ui.js
    function printInvoiceThermal() {
        const d = invDetail;
        if (!d) { Swal.fire({ icon: 'info', title: 'Still loading', text: 'Give the invoice a moment to load.' }); return; }
        const num = n => Number(n || 0);
        const paid = num(d.total_paid !== undefined ? d.total_paid : d.paid_amount);
        const bal  = num(d.total_due  !== undefined ? d.total_due  : d.balance_amount);
        const lines = [{ name: (d.package_name || 'Internet service') + (d.speed_mbps ? ' (' + d.speed_mbps + ' Mbps)' : ''),
                         qty: '1', unit: '', price: fmtMoney(d.amount), total: fmtMoney(d.amount) }]
            .concat((d.items || []).map(it => ({ name: it.description, qty: String(it.qty), unit: it.unit || '',
                                                 price: fmtMoney(it.unit_price), total: fmtMoney(it.line_total) })));
        rsThermalPrint({
            title: num(d.tax_amount) > 0 ? 'TAX INVOICE' : 'INVOICE',
            meta: [['Invoice', d.invoice_number], ['Date', fmtDate(d.issue_date || d.created_at)],
                   ['Due', fmtDate(d.due_date)], ['Month', d.billing_month],
                   ['Customer', d.customer_name], ['Code', d.customer_code]],
            items: lines,
            sums: [
                ['Subtotal', fmtMoney(d.amount)],
                num(d.items_total)    > 0 ? ['Stock Items', fmtMoney(d.items_total)] : null,
                num(d.previous_balance) > 0 ? ['Previous Balance', fmtMoney(d.previous_balance)] : null,
                num(d.discount_amount)  > 0 ? ['Discount', '- ' + fmtMoney(d.discount_amount)] : null,
                num(d.tax_amount)     > 0 ? ['Tax', fmtMoney(d.tax_amount)] : null,
                num(d.late_fee)       > 0 ? ['Late Fee', fmtMoney(d.late_fee)] : null,
                ['TOTAL', fmtMoney(d.total), 'grand'],
                paid > 0 ? ['Paid', fmtMoney(paid)] : null,
                bal  > 0 ? ['Balance Due', fmtMoney(bal)] : null
            ],
            note: d.status === 'cancelled' ? '*** CANCELLED ***' : '',
            footer: bal > 0 ? 'Please clear the balance by ' + fmtDate(d.due_date) : 'Paid in full — thank you'
        });
    }

    function renderDetail(d) {
        invDetail = d;
        const wantPrint = new URLSearchParams(location.search).get('print');
        if (wantPrint) setTimeout(() => wantPrint === 'thermal' ? printInvoiceThermal() : window.print(), 400);
        const isPaid = d.status === 'paid';
        if (isPaid) $('#payBtn').hide();
        // show cancel button only when not already cancelled
        if (d.status === 'cancelled') $('#cancelInvBtn').hide();
        else $('#cancelInvBtn').removeClass('initially-hidden');
        // stash id for cancel action
        window._currentInvoiceId  = d.id;
        window._currentInvoiceNum = d.invoice_number;

        const prevBal  = Number(d.previous_balance || 0);
        const disc     = Number(d.discount || 0);
        const tax      = Number(d.tax || 0);
        const lateFee  = Number(d.late_fee || 0);
        const paid     = Number(d.paid_amount || 0);
        const balance  = Number(d.balance_amount || 0);

        const itemsTot = Number(d.items_total || 0);
        const equipChg = Number(d.equipment_charges || 0);
        const instChg  = Number(d.installation_charges || 0);
        const addChg   = Number(d.additional_charges || 0);
        const whtAmt   = Number(d.wht_amount || 0);

        // every charge that lands in the grand total gets its own printed line
        const tRow = (label, val, neg) => `<div class="printable-totals-row"><span>${label}</span><span>${neg ? '-' : ''}${fmtMoney(val)}</span></div>`;
        const totalsRows = [];
        totalsRows.push(tRow('Subtotal (Service)', d.amount));
        if (itemsTot > 0) totalsRows.push(tRow('Stock Items', itemsTot));
        if (equipChg > 0) totalsRows.push(tRow('Equipment Charges', equipChg));
        if (instChg > 0)  totalsRows.push(tRow('Installation Charges', instChg));
        if (addChg > 0)   totalsRows.push(tRow('Additional Charges', addChg));
        if (prevBal > 0)  totalsRows.push(tRow('Previous Balance', prevBal));
        if (disc > 0)     totalsRows.push(tRow('Discount', disc, true));
        if (tax > 0)      totalsRows.push(tRow('Tax', tax));
        if (lateFee > 0)  totalsRows.push(tRow('Late Fee', lateFee));
        if (whtAmt > 0)   totalsRows.push(tRow('Withholding Tax', whtAmt, true));
        totalsRows.push(`<div class="printable-totals-row grand"><span>Grand Total</span><span>${fmtMoney(d.total)}</span></div>`);

        // stock sold on this invoice prints as its own lines under the service line
        const itemRows = (d.items || []).map(it => `
                    <tr>
                        <td>${escapeHtml(it.description)}${it.item_code ? '<br><small class="muted">' + escapeHtml(it.item_code) + '</small>' : ''}${it.serials ? '<br><small class="muted">S/N: ' + escapeHtml(it.serials) + '</small>' : ''}</td>
                        <td class="num-cell">${escapeHtml(it.qty)}${it.unit ? ' ' + escapeHtml(it.unit) : ''}</td>
                        <td class="num-cell">${fmtMoney(it.unit_price)}</td>
                        <td class="num-cell">${fmtMoney(it.line_total)}</td>
                    </tr>`).join('');
        if (paid > 0)     totalsRows.push(`<div class="printable-totals-row"><span>Paid</span><span>${fmtMoney(paid)}</span></div>`);
        if (balance > 0)  totalsRows.push(`<div class="printable-totals-row grand"><span>Balance Due</span><span>${fmtMoney(balance)}</span></div>`);

        const titleTxt   = tax > 0 ? 'TAX INVOICE' : 'INVOICE';
        const stampClass = d.status;
        const pkgLine    = escapeHtml(d.package_name || '') + (d.speed_mbps ? ' (' + d.speed_mbps + ' Mbps)' : '');
        const periodTxt  = fmtPeriodRange(d.invoice_period_start, d.invoice_period_end);
        const issueTxt   = d.issue_date ? fmtDate(d.issue_date) : fmtDate(d.created_at);
        const notesHtml  = d.notes ? `<div class="printable-totals-row" style="margin-top:18px;font-style:italic;color:#475569"><span>Notes:</span><span>${escapeHtml(d.notes)}</span></div>` : '';
        const cancelMeta = d.cancelled_at
            ? `<p class="muted"><strong>Cancelled:</strong> ${fmtDate(d.cancelled_at)}${d.cancel_reason ? ' — ' + escapeHtml(d.cancel_reason) : ''}</p>`
            : '';

        const html = `
            <div class="printable-header">
                ${SITE_LOGO ? `<img src="${escapeHtml(SITE_LOGO)}" class="printable-logo" alt="logo">` : '<div></div>'}
                <div class="printable-company">
                    <h2>${escapeHtml(SITE_NAME)}</h2>
                    <p>${COPY_TXT}</p>
                </div>
            </div>

            <div class="printable-title">${titleTxt}</div>

            <div class="printable-meta">
                <div>
                    <h4>Bill To</h4>
                    <p><strong>${escapeHtml(d.customer_name)}</strong></p>
                    <p class="muted">Code: ${escapeHtml(d.customer_code)}</p>
                    <p>${escapeHtml(d.address || '')}</p>
                    <p>${escapeHtml(d.area_name || '')}${d.area_city ? ', ' + escapeHtml(d.area_city) : ''}</p>
                    <p>${escapeHtml(d.phone || '')}</p>
                    ${d.email ? `<p>${escapeHtml(d.email)}</p>` : ''}
                </div>
                <div>
                    <h4>Invoice Details</h4>
                    <p><strong>Invoice #:</strong> ${escapeHtml(d.invoice_number)}</p>
                    <p><strong>Issue Date:</strong> ${issueTxt}</p>
                    <p><strong>Period:</strong> ${periodTxt}</p>
                    <p><strong>Due Date:</strong> ${fmtDate(d.due_date)}</p>
                    <p><strong>Billing Month:</strong> ${escapeHtml(d.billing_month)}</p>
                    <p><strong>Status:</strong> <span class="inv-status ${d.status}">${d.status}</span></p>
                    ${cancelMeta}
                </div>
            </div>

            <table class="printable-table">
                <thead>
                    <tr><th>Description</th><th>Qty</th><th>Price</th><th>Amount</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>${pkgLine}<br><small class="muted">Internet service for ${escapeHtml(d.billing_month)}</small></td>
                        <td class="num-cell">1</td>
                        <td class="num-cell">${fmtMoney(d.amount)}</td>
                        <td class="num-cell">${fmtMoney(d.amount)}</td>
                    </tr>
                    ${itemRows}
                </tbody>
            </table>

            <div class="printable-totals">
                ${totalsRows.join('')}
                ${notesHtml}
            </div>

            <div style="text-align:center;margin-top:24px">
                <span class="printable-stamp ${stampClass}">${d.status}</span>
            </div>

            <div class="printable-signatures">
                <div class="printable-sig">Customer Signature</div>
                <div class="printable-sig">Authorized Signature</div>
            </div>
        `;
        $('#detailBody').html(html).show();
        $('#detailSkeleton').hide();
        // ?print=1 — opened straight from a 360 view, so go to the print dialog
        if (new URLSearchParams(location.search).get('print') === '1') setTimeout(() => window.print(), 500);
    }

    // detail-page cancel — admin+
    function cancelInvoiceFromDetail() {
        const id  = window._currentInvoiceId;
        const num = window._currentInvoiceNum || '';
        if (!id) return;
        Swal.fire({
            icon: 'warning', title: 'Cancel Invoice ' + num + '?',
            input: 'textarea',
            inputLabel: 'Reason (required)',
            inputPlaceholder: 'Why is this invoice being cancelled?',
            inputAttributes: { maxlength: 255, required: true },
            inputValidator: v => (!v || !v.trim()) ? 'Reason is required' : null,
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Cancel Invoice', cancelButtonText: 'Keep'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', id);
            fd.append('cancel_reason', (result.value || '').trim());
            $.ajax({
                url: '?action=cancelInvoice', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false })
                            .then(() => loadDetail());
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Connection Error' }); }
            });
        });
    }
    </script>

    <?php else: ?>
    <script>
    // list mode
    let invTable;
    let isEditMode = false;
    let invData    = [];
    let custList   = [];
    let pkgList    = [];

    $(document).ready(function() {
        // wire SDs
        if (!IS_COLL) {
            new SearchableDropdown({ selectId: 'filterArea', placeholder: 'All Areas', icon: 'fas fa-map-marked-alt' });
            loadAreas();
        }
        if (CAN_WRITE) {
            new SearchableDropdown({ selectId: 'customer_id', placeholder: 'Select customer...', icon: 'fas fa-user', allowClear: false });
            new SearchableDropdown({ selectId: 'package_id',  placeholder: 'Select package...',  icon: 'fas fa-box',  allowClear: false });
            new SearchableDropdown({ selectId: 'batchMonth',  placeholder: 'Select month...',    icon: 'fas fa-calendar', allowClear: false });
            // new SDs — invoice type, currency, parent invoice
            new SearchableDropdown({ selectId: 'invoice_type', placeholder: 'Select type...', icon: 'fas fa-tags', allowClear: false });
            new SearchableDropdown({ selectId: 'currency',     placeholder: 'PKR', icon: 'fas fa-coins', allowClear: false });
            new SearchableDropdown({ selectId: 'parent_invoice_id', placeholder: 'Select parent invoice...', icon: 'fas fa-link', allowClear: false });
            preloadFormData();
            loadStockItems();

            // auto-open add form from dashboard quick action (?new=1), with ?item=N pre-billed from Stock
            const qs = new URLSearchParams(location.search);
            if (qs.get('new') === '1' && document.querySelector('button[onclick="openAddModal()"]')) {
                openAddModal();
                presetStockLine(parseInt(qs.get('item') || 0));
            }

            // invoice_type → toggle parent picker
            document.getElementById('invoice_type').addEventListener('change', function() {
                const v = this.value;
                const grp = document.getElementById('parentInvoiceGroup');
                if (v === 'credit_note' || v === 'adjustment') {
                    grp.classList.remove('initially-hidden');
                    if (!parentInvLoaded) loadParentInvoices();
                } else {
                    grp.classList.add('initially-hidden');
                }
            });

            // live total calc — current charges only (prev balance excluded)
            ['amount','discount_amount','discount_pct','tax','tax_pct','late_fee',
             'equipment_charges','installation_charges','additional_charges','wht_amount','wht_pct'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', recalcTotal);
            });
            // billing month → auto period start/end
            document.getElementById('billing_month').addEventListener('change', autoFillPeriod);
            // customer auto-fills package + price + prior balance
            document.getElementById('customer_id').addEventListener('change', function() {
                const cid = parseInt(this.value || 0);
                const c = custList.find(x => x.id === cid);
                if (c) {
                    if (c.package_id) {
                        const pEl = document.getElementById('package_id');
                        pEl.value = c.package_id;
                        pEl._sd && pEl._sd.refresh();
                    }
                    if (c.price > 0 && !document.getElementById('amount').value) {
                        document.getElementById('amount').value = c.price.toFixed(2);
                        recalcTotal();
                    }
                }
                // prev-balance no longer auto-filled — invoices stand alone
            });
            // package change → fill amount
            document.getElementById('package_id').addEventListener('change', function() {
                const pid = parseInt(this.value || 0);
                const p = pkgList.find(x => x.id === pid);
                if (p && p.price > 0) {
                    document.getElementById('amount').value = p.price.toFixed(2);
                    recalcTotal();
                }
            });
        }

        // Chevron pipeline click → set status & re-apply
        $(document).on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('.chevron-tab').removeClass('active');
            $(this).addClass('active');
            chevronStatus = $(this).data('status') || 'all';
            applyFilters();
        });

        // URL ?filter=... → preset chevron + filter
        const qp = new URLSearchParams(window.location.search);
        const qFilter = (qp.get('filter') || '').toLowerCase();
        const validFilters = ['all','outstanding','overdue','unpaid','partial','paid'];
        if (validFilters.includes(qFilter)) {
            chevronStatus = qFilter;
            $('.chevron-tab').removeClass('active');
            $(`.chevron-tab[data-status="${qFilter}"]`).addClass('active');
        }

        loadInvoices();
        rsImport.init({ entity: 'Invoice', onDone: loadInvoices });

        $('#filterMonth, #filterArea, #filterIssueFrom, #filterIssueTo').on('change', applyFilters);
        $('#filterSearch').on('input', applyFilters);

        // cross-tab + back-button refresh when payment recorded elsewhere
        let lastSeenDirty = localStorage.getItem('isp_invoices_dirty') || '';
        function refreshIfDirty() {
            const v = localStorage.getItem('isp_invoices_dirty') || '';
            if (v && v !== lastSeenDirty) {
                lastSeenDirty = v;
                loadInvoices();
            }
        }
        window.addEventListener('storage', function(e) { if (e.key === 'isp_invoices_dirty') refreshIfDirty(); });
        document.addEventListener('visibilitychange', function() { if (document.visibilityState === 'visible') refreshIfDirty(); });
        window.addEventListener('pageshow', function(e) { if (e.persisted) refreshIfDirty(); });
    });

    // chevron-driven status filter (separate from explicit filterStatus dropdown)
    let chevronStatus = 'all';

    function refreshChevronCounts(rows) {
        const c = { all: rows.length, outstanding: 0, unpaid: 0, partial: 0, overdue: 0, paid: 0 };
        rows.forEach(r => {
            const s = r.status;
            if (s in c) c[s]++;
            if (s !== 'paid') c.outstanding++;
        });
        Object.keys(c).forEach(k => {
            const el = document.getElementById('cv-c-' + k);
            if (el) el.textContent = c[k];
        });
    }

    // ── stock lines: inventory billed straight onto this invoice ──
    // every saved line leaves the store as one auto issue_out movement — the server re-validates all of it
    let STOCK_ITEMS = [], stockLineSeq = 0;

    function fmtQty(n) { return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }

    function loadStockItems() {
        $.getJSON('?action=getStockItems', function(r) { if (r.success) STOCK_ITEMS = r.data; });
    }

    function stockItemOpts() {
        return STOCK_ITEMS.map(i => {
            const left = i.track === 'serial' ? 'serial-tracked' : fmtQty(i.qty) + ' ' + i.unit + ' left';
            return `<option value="${i.id}">${escapeHtml(i.code + ' — ' + i.name + ' (' + left + ')')}</option>`;
        }).join('');
    }

    function addStockLine(preset) {
        if (!STOCK_ITEMS.length) {
            Swal.fire({ icon: 'info', title: 'Nothing in stock', text: 'Add items under Stock first, then you can bill them here.' });
            return null;
        }
        const id = ++stockLineSeq;
        const $tr = $(`<tr data-sline="${id}">
            <td>
                <select class="line-item"><option value="">— Select item —</option>${stockItemOpts()}</select>
                <div class="line-serials initially-hidden">
                    <label class="muted"><i class="fas fa-barcode"></i> Serial numbers (one per line)</label>
                    <textarea class="line-serial-box" rows="3" placeholder="SN0001&#10;SN0002"></textarea>
                    <div class="muted line-avail"></div>
                </div>
            </td>
            <td><input type="number" class="line-qty" step="0.01" min="0" value="1"></td>
            <td><input type="number" class="line-rate" step="0.01" min="0" value="0"></td>
            <td class="num-cell line-total">0.00</td>
            <td><button type="button" class="action-icon delete-icon" onclick="removeStockLine(this)"><i class="fas fa-trash"></i></button></td>
        </tr>`);
        $('#stockLineBody').append($tr);
        new SearchableDropdown({ selectId: $tr.find('.line-item')[0], placeholder: '— Select item —', icon: 'fas fa-box' });
        syncStockEmpty();
        if (preset) setStockLine($tr, preset);
        return $tr;
    }

    function setStockLine($tr, d) {
        const sel = $tr.find('.line-item')[0];
        sel.value = d.item_id;
        sel._sd && sel._sd.refresh();
        $tr.find('.line-qty').val(d.qty);
        $tr.find('.line-rate').val(Number(d.unit_price || 0).toFixed(2));
        if (d.serials) $tr.find('.line-serial-box').val(String(d.serials).split(',').join('\n'));
        syncStockLineUI($tr);
    }

    function removeStockLine(btn) { $(btn).closest('tr').remove(); syncStockEmpty(); recalcTotal(); }

    // arriving from Stock → "Bill on Invoice" — wait for the item list, then drop the line in
    function presetStockLine(itemId) {
        if (!itemId) return;
        if (!STOCK_ITEMS.length) { setTimeout(() => presetStockLine(itemId), 200); return; }
        const item = STOCK_ITEMS.find(i => String(i.id) === String(itemId));
        if (!item) return;
        const $tr = addStockLine();
        if ($tr) setStockLine($tr, { item_id: item.id, qty: 1, unit_price: item.sell });
    }

    function clearStockLines() { $('#stockLineBody').empty(); syncStockEmpty(); }

    function syncStockEmpty() {
        const n = $('#stockLineBody tr').length;
        $('#stockLinesEmpty').toggle(n === 0);
        $('#stockLineTable').toggle(n > 0);
    }

    // serial items list their serials; quantity items just take a number
    function syncStockLineUI($tr) {
        const itemId = $tr.find('.line-item').val();
        const item   = STOCK_ITEMS.find(i => String(i.id) === String(itemId));
        const serial = !!item && item.track === 'serial';
        $tr.find('.line-serials').toggleClass('initially-hidden', !serial).toggle(serial);
        $tr.find('.line-qty').prop('readonly', serial);
        if (item && Number($tr.find('.line-rate').val()) === 0) $tr.find('.line-rate').val(Number(item.sell || 0).toFixed(2));
        if (serial) {
            $.getJSON('?action=getItemSerials&item_id=' + itemId, function(r) {
                if (r.success) $tr.find('.line-avail').text(r.data.length
                    ? 'In store: ' + r.data.slice(0, 25).join(', ') + (r.data.length > 25 ? ' …' : '')
                    : 'Nothing in store for this item.');
            });
        } else {
            $tr.find('.line-avail').text('');
        }
        recalcStockLine($tr);
    }

    function recalcStockLine($tr) {
        const isSerial = !$tr.find('.line-serials').hasClass('initially-hidden');
        let qty = Number($tr.find('.line-qty').val() || 0);
        if (isSerial) { // the serials ARE the quantity
            qty = ($tr.find('.line-serial-box').val() || '').split(/[\s,;]+/).filter(Boolean).length;
            $tr.find('.line-qty').val(qty);
        }
        const rate = Number($tr.find('.line-rate').val() || 0);
        $tr.find('.line-total').text((Math.abs(qty) * rate).toFixed(2));
        recalcTotal();
    }

    function stockLinesTotal() {
        let t = 0;
        $('#stockLineBody tr').each(function() {
            t += Math.abs(Number($(this).find('.line-qty').val() || 0)) * Number($(this).find('.line-rate').val() || 0);
        });
        return +t.toFixed(2);
    }

    // hidden field the form posts — the server re-checks every line against live stock anyway
    function collectStockLines() {
        const out = [];
        $('#stockLineBody tr').each(function() {
            const itemId = Number($(this).find('.line-item').val() || 0);
            if (!itemId) return;
            out.push({
                item_id: itemId,
                qty: Number($(this).find('.line-qty').val() || 0),
                unit_price: Number($(this).find('.line-rate').val() || 0),
                serials: ($(this).find('.line-serial-box').val() || '').trim()
            });
        });
        document.getElementById('stock_lines').value = out.length ? JSON.stringify(out) : '';
        return out;
    }

    // pull the billed lines back into the form when editing
    function loadStockLines(invId) {
        clearStockLines();
        $.getJSON('?action=getInvoiceItems&id=' + invId, function(r) {
            if (!r.success || !r.data.length) return;
            r.data.forEach(d => { if (d.item_id) { const $tr = addStockLine(); if ($tr) setStockLine($tr, d); } });
            recalcTotal();
        });
    }

    $(document).on('change', '#stockLineBody .line-item', function() { syncStockLineUI($(this).closest('tr')); });
    $(document).on('input', '#stockLineBody .line-qty, #stockLineBody .line-rate, #stockLineBody .line-serial-box', function() { recalcStockLine($(this).closest('tr')); });

    // structured total: subtotal + stock items + extras + tax + late - discount - wht
    function recalcTotal() {
        const num = id => parseFloat((document.getElementById(id) || {}).value) || 0;
        const a   = num('amount');
        const items = stockLinesTotal();
        const eq  = num('equipment_charges');
        const ins = num('installation_charges');
        const adj = num('additional_charges');
        let dAmt  = num('discount_amount');
        const dPc = num('discount_pct');
        // pct overrides amount when given
        if (dPc > 0) {
            dAmt = +(a * dPc / 100).toFixed(2);
            const dEl = document.getElementById('discount_amount');
            if (dEl && document.activeElement !== dEl) dEl.value = dAmt.toFixed(2);
        }
        let tax = num('tax');
        const tPc = num('tax_pct');
        if (tPc > 0) {
            tax = +((a + items + eq + ins + adj - dAmt) * tPc / 100).toFixed(2);
            const tEl = document.getElementById('tax');
            if (tEl && document.activeElement !== tEl) tEl.value = tax.toFixed(2);
        }
        let wht = num('wht_amount');
        const wPc = num('wht_pct');
        if (wPc > 0) {
            wht = +((a + items + eq + ins + adj) * wPc / 100).toFixed(2);
            const wEl = document.getElementById('wht_amount');
            if (wEl && document.activeElement !== wEl) wEl.value = wht.toFixed(2);
        }
        const lf  = num('late_fee');
        // sync legacy 'discount' hidden input
        const legD = document.getElementById('discount');
        if (legD) legD.value = dAmt.toFixed(2);
        const stEl = document.getElementById('stockLinesTotal');
        if (stEl) stEl.textContent = items.toFixed(2);
        // allocation-based: prev balance NOT rolled into the invoice total
        const tot = a + items + eq + ins + adj + tax + lf - dAmt - wht;
        document.getElementById('total').value = (tot < 0 ? 0 : tot).toFixed(2);
    }

    // parent invoice loader (for credit notes / adjustments)
    let parentInvLoaded = false;
    function loadParentInvoices() {
        $.ajax({
            url: '?action=getParentInvoices', method: 'GET', dataType: 'json',
            success: function(resp) {
                if (!resp.success) return;
                const sel = document.getElementById('parent_invoice_id');
                sel.innerHTML = '<option value="">Select parent invoice...</option>';
                resp.data.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.invoice_number + ' — ' + (p.customer_name || '') + ' — ' + fmtMoney(p.total);
                    sel.appendChild(opt);
                });
                sel._sd && sel._sd.refresh();
                parentInvLoaded = true;
            }
        });
    }

    // first/last day of selected billing month
    function autoFillPeriod() {
        const ym = document.getElementById('billing_month').value;
        if (!ym || !/^\d{4}-\d{2}$/.test(ym)) return;
        const [y, m] = ym.split('-').map(Number);
        const last = new Date(y, m, 0).getDate();
        const ps = document.getElementById('invoice_period_start');
        const pe = document.getElementById('invoice_period_end');
        if (!ps.value) ps.value = ym + '-01';
        if (!pe.value) pe.value = ym + '-' + String(last).padStart(2, '0');
    }

    function loadAreas() {
        $.ajax({
            url: '?action=getAreas', method: 'GET', dataType: 'json',
            success: function(resp) {
                if (!resp.success) return;
                const sel = document.getElementById('filterArea');
                resp.data.forEach(a => {
                    const opt = document.createElement('option');
                    opt.value = a.id;
                    opt.textContent = a.name + (a.city ? ' — ' + a.city : '');
                    sel.appendChild(opt);
                });
                sel._sd && sel._sd.refresh();
            }
        });
    }

    function preloadFormData() {
        // customers
        $.ajax({
            url: '?action=getActiveCustomers', method: 'GET', dataType: 'json',
            success: function(resp) {
                if (!resp.success) return;
                custList = resp.data;
                const sel = document.getElementById('customer_id');
                sel.innerHTML = '<option value="">Select customer...</option>';
                resp.data.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.full_name + ' (' + c.customer_code + ')';
                    sel.appendChild(opt);
                });
                sel._sd && sel._sd.refresh();
            }
        });
        // packages
        $.ajax({
            url: '?action=getPackages', method: 'GET', dataType: 'json',
            success: function(resp) {
                if (!resp.success) return;
                pkgList = resp.data;
                const sel = document.getElementById('package_id');
                sel.innerHTML = '<option value="">Select package...</option>';
                resp.data.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.name + ' — ' + p.speed_mbps + ' Mbps — ' + fmtMoney(p.price);
                    sel.appendChild(opt);
                });
                sel._sd && sel._sd.refresh();
            }
        });
        // batch month options (last 12 + next 3)
        const sel = document.getElementById('batchMonth');
        if (sel) {
            sel.innerHTML = '';
            const now = new Date();
            const months = [];
            for (let i = 12; i >= -3; i--) {
                const dt = new Date(now.getFullYear(), now.getMonth() - i, 1);
                const ym = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0');
                months.push({ ym, label: dt.toLocaleDateString(undefined, { month:'long', year:'numeric' }) });
            }
            const cur = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
            months.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.ym;
                opt.textContent = m.label;
                if (m.ym === cur) opt.selected = true;
                sel.appendChild(opt);
            });
            sel._sd && sel._sd.refresh();
        }
    }

    function loadInvoices() {
        $('#skeletonWrap').show();
        $('#tableWrap').hide();
        $('#emptyState').hide();
        $.ajax({
            url: '?action=getInvoices', method: 'GET', dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    invData = resp.data;
                    refreshChevronCounts(invData);
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
                    if (chevronStatus !== 'all') setTimeout(applyFilters, 150);
                } else {
                    $('#skeletonWrap').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed' });
                }
            },
            error: function() {
                $('#skeletonWrap').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error' });
            }
        });
    }

    function initTable(data) {
        // destroy() restores original DOM (keeps <tfoot>); don't .empty() or footer is lost
        if (invTable) { invTable.destroy(); $('#invoicesTable thead').remove(); }
        setTimeout(() => {
            invTable = $('#invoicesTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'invoice_number', title: 'Invoice #', render: d => '<strong>' + escapeHtml(d) + '</strong>' },
                    {
                        data: null, title: 'Customer',
                        render: r => escapeHtml(r.customer_name) + '<br><small class="muted">' + escapeHtml(r.customer_code) + '</small>'
                    },
                    { data: 'billing_month', title: 'Month' },
                    { data: 'issue_date', title: 'Issue Date', render: d => d ? fmtDate(d) : '<span class="muted">—</span>' },
                    {
                        data: null, title: 'Period',
                        render: r => fmtPeriodRange(r.invoice_period_start, r.invoice_period_end)
                    },
                    { data: 'amount',   title: 'Amount',  render: d => '<span class="num-cell">' + fmtMoney(d) + '</span>' },
                    { data: 'total',    title: 'Total',   render: d => '<span class="num-cell"><strong>' + fmtMoney(d) + '</strong></span>' },
                    { data: 'paid_amount',    title: 'Paid',    render: d => '<span class="num-cell">' + fmtMoney(d) + '</span>' },
                    {
                        data: 'balance_amount', title: 'Balance',
                        render: function(d, t, row) {
                            const v = Number(d || 0);
                            const cls = v <= 0 ? 'zero' : (row.status === 'overdue' ? 'overdue' : 'due');
                            return '<span class="num-cell"><span class="outstanding-badge ' + cls + '">' + fmtMoney(v) + '</span></span>';
                        }
                    },
                    { data: 'due_date', title: 'Due Date', render: d => fmtDate(d) },
                    {
                        data: null, title: 'Days Overdue', className: 'num-cell',
                        render: function(d, t, row) {
                            if (t === 'sort' || t === 'type') {
                                const open = ['unpaid','partial','overdue'].includes(row.status);
                                return open ? daysPast(row.due_date) : -1;
                            }
                            return overdueBadge(row);
                        }
                    },
                    { data: 'status',   title: 'Status',   render: d => '<span class="inv-status ' + d + '">' + d + '</span>' },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(d, t, row) {
                            let h = `<button class="action-icon view-icon" onclick="openInvoiceView(${row.id})" title="View"><i class="fas fa-eye"></i></button>`;
                            if (CAN_WRITE && row.status !== 'paid' && row.status !== 'cancelled') {
                                h += ` <a class="action-icon" href="payments.php?invoice_id=${row.id}" title="Record Payment"><i class="fas fa-money-bill-wave"></i></a>`;
                            }
                            h += ` <a class="action-icon" href="invoices.php?id=${row.id}&print=a4" target="_blank" title="Print A4"><i class="fas fa-print"></i></a>`;
                            h += ` <a class="action-icon" href="invoices.php?id=${row.id}&print=thermal" target="_blank" title="Thermal slip"><i class="fas fa-receipt"></i></a>`;
                            // ⋮ more — edit / cancel / delete
                            let more = '';
                            if (CAN_WRITE && row.status !== 'paid' && row.status !== 'cancelled') {
                                more += `<button class="act-more-item" onclick='editInvoice(${JSON.stringify(row)})'><i class="fas fa-edit"></i> Edit</button>`;
                            }
                            if (IS_ADMIN_PLUS && row.status !== 'cancelled') {
                                more += `<button class="act-more-item" onclick="cancelInvoiceAction(${row.id}, '${escapeHtml(row.invoice_number)}')"><i class="fas fa-ban"></i> Cancel</button>`;
                            }
                            if (CAN_DELETE) {
                                more += `<button class="act-more-item danger" onclick="cancelInvoice(${row.id}, '${escapeHtml(row.invoice_number)}')"><i class="fas fa-trash"></i> Delete</button>`;
                            }
                            if (more) {
                                h += ` <div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">${more}</div></div>`;
                            }
                            return h;
                        }
                    }
                ],
                createdRow: function(rowEl, rowData) {
                    if (rowData.status === 'cancelled')      $(rowEl).addClass('row-cancelled');
                    else if (rowData.status === 'paid')      $(rowEl).addClass('row-paid');
                    else if (rowData.status === 'overdue')   $(rowEl).addClass('row-overdue');
                    else if (rowData.status === 'partial')   $(rowEl).addClass('row-partial');
                },
                footerCallback: function(tfoot, data, start, end, display) {
                    // sum visible (filtered) rows
                    let invoiced = 0, paid = 0, outstanding = 0, overdue = 0;
                    display.forEach(idx => {
                        const r = invData[idx];
                        if (!r || r.status === 'cancelled') return;
                        const tot = parseFloat(r.total) || 0;
                        const pd  = parseFloat(r.paid_amount) || 0;
                        const bal = parseFloat(r.balance_amount) || 0;
                        invoiced    += tot;
                        paid        += pd;
                        outstanding += bal;
                        if (r.status === 'overdue') overdue += bal;
                    });
                    $('#footInvoiced').text(fmtMoney(invoiced));
                    $('#footPaid').text(fmtMoney(paid)).addClass('dt-foot-val pos');
                    $('#footOutstanding').text(fmtMoney(outstanding)).addClass('dt-foot-val' + (outstanding > 0 ? ' neg' : ''));
                    $('#footOverdue').text(fmtMoney(overdue)).addClass('dt-foot-val' + (overdue > 0 ? ' neg' : ''));
                },
                pageLength: 50,
                lengthMenu: [[25, 50, 100, 250, -1], [25, 50, 100, 250, "All"]],
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
                order: [[9, 'asc']]
            });
        }, 100);
    }

    function applyFilters() {
        if (!invTable) return;
        $.fn.dataTable.ext.search = [];
        const m  = (document.getElementById('filterMonth')  || {}).value || '';
        const ar = (document.getElementById('filterArea')   || {}).value || '';
        const dF = (document.getElementById('filterIssueFrom') || {}).value || '';
        const dT = (document.getElementById('filterIssueTo')   || {}).value || '';
        const sr = ((document.getElementById('filterSearch')|| {}).value || '').toLowerCase();
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const r = invData[dataIndex];
            if (!r) return true;
            if (m  && r.billing_month !== m) return false;
            if (ar && String(r.area_id) !== String(ar)) return false;
            // issue-date range (compare YYYY-MM-DD prefix)
            const iss = (r.issue_date || '').slice(0, 10);
            if (dF && (!iss || iss < dF)) return false;
            if (dT && (!iss || iss > dT)) return false;
            // chevron pipeline status — primary status filter
            if (chevronStatus === 'outstanding' && r.status === 'paid') return false;
            if (['unpaid','partial','overdue','paid'].includes(chevronStatus) && r.status !== chevronStatus) return false;
            if (sr) {
                const hay = (r.invoice_number + ' ' + r.customer_name + ' ' + r.customer_code).toLowerCase();
                if (hay.indexOf(sr) === -1) return false;
            }
            return true;
        });
        invTable.draw();
    }

    function clearFilters() {
        // also reset chevron to "All"
        chevronStatus = 'all';
        $('.chevron-tab').removeClass('active');
        $('.chevron-tab[data-status="all"]').addClass('active');
        ['filterMonth','filterArea','filterSearch','filterIssueFrom','filterIssueTo'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = '';
            el._sd && el._sd.refresh();
        });
        if (invTable) { $.fn.dataTable.ext.search = []; invTable.draw(); }
    }

    // ─── tabbed view modal ───
    function vtRow(label, val) {
        return `<div class="vtab-field"><label>${escapeHtml(label)}</label><div>${val}</div></div>`;
    }

    function openInvoiceView(id) {
        const r = invData.find(x => x.id === id);
        if (!r) { Swal.fire({ icon: 'error', title: 'Not found' }); return; }

        document.getElementById('invViewTitle').innerHTML =
            '<i class="fas fa-file-invoice-dollar"></i> ' + escapeHtml(r.invoice_number);
        document.getElementById('invViewPrint').href = 'invoices.php?id=' + r.id;

        // Invoice Items tab — charges breakdown (current charges only)
        const lines = [];
        lines.push(vtRow('Package / Subtotal', fmtMoney(r.amount)));
        if (Number(r.items_total) > 0)          lines.push(vtRow('Stock Items', fmtMoney(r.items_total)));
        if (Number(r.equipment_charges) > 0)    lines.push(vtRow('Equipment Charges', fmtMoney(r.equipment_charges)));
        if (Number(r.installation_charges) > 0) lines.push(vtRow('Installation Charges', fmtMoney(r.installation_charges)));
        if (Number(r.additional_charges) > 0)   lines.push(vtRow('Additional Charges', fmtMoney(r.additional_charges)
            + (r.additional_charges_description ? ' <small class="muted">(' + escapeHtml(r.additional_charges_description) + ')</small>' : '')));
        if (Number(r.discount_amount) > 0)      lines.push(vtRow('Discount', '-' + fmtMoney(r.discount_amount)
            + (r.discount_reason ? ' <small class="muted">(' + escapeHtml(r.discount_reason) + ')</small>' : '')));
        if (Number(r.tax) > 0)                  lines.push(vtRow('Tax', fmtMoney(r.tax)));
        if (Number(r.wht_amount) > 0)           lines.push(vtRow('Withholding (WHT)', '-' + fmtMoney(r.wht_amount)));
        if (Number(r.late_fee) > 0)             lines.push(vtRow('Late Fee', fmtMoney(r.late_fee)));
        lines.push(vtRow('Grand Total', '<strong>' + fmtMoney(r.total) + '</strong>'));
        document.getElementById('vt-items').innerHTML =
            '<div class="vtab-grid">' + lines.join('') + '</div>';

        // Payments tab — paid / balance summary (row data)
        const bal = Number(r.balance_amount || 0);
        const balCls = bal > 0 ? (r.status === 'overdue' ? 'overdue' : 'due') : 'zero';
        const payLines = [
            vtRow('Invoice Total', fmtMoney(r.total)),
            vtRow('Total Paid', fmtMoney(r.paid_amount)),
            vtRow('Balance Due', '<span class="outstanding-badge ' + balCls + '">' + fmtMoney(bal) + '</span>'),
            vtRow('Last Payment', r.paid_date ? fmtDate(r.paid_date) : '<span class="muted">—</span>'),
            vtRow('Payment Method', r.payment_method_summary ? escapeHtml(r.payment_method_summary) : '<span class="muted">—</span>')
        ];
        let payHtml = '<div class="vtab-grid">' + payLines.join('') + '</div>';
        if (CAN_WRITE && r.status !== 'paid' && r.status !== 'cancelled') {
            payHtml += `<div class="form-actions"><a href="payments.php?invoice_id=${r.id}" class="btn btn-success"><i class="fas fa-money-bill-wave"></i> Record Payment</a></div>`;
        }
        document.getElementById('vt-pay').innerHTML = payHtml;

        // Customer Info tab
        document.getElementById('vt-cust').innerHTML = '<div class="vtab-grid">' + [
            vtRow('Customer', escapeHtml(r.customer_name || '—')),
            vtRow('Customer Code', escapeHtml(r.customer_code || '—')),
            vtRow('Area', r.area_name ? escapeHtml(r.area_name) : '<span class="muted">—</span>'),
            vtRow('Package', r.package_name ? escapeHtml(r.package_name) + (r.speed_mbps ? ' (' + r.speed_mbps + ' Mbps)' : '') : '<span class="muted">—</span>'),
            vtRow('Billing Month', escapeHtml(r.billing_month || '—')),
            vtRow('Currency', escapeHtml(r.currency || 'PKR'))
        ].join('') + '</div>';

        // Timeline tab
        const open = ['unpaid','partial','overdue'].includes(r.status);
        const odNote = open && daysPast(r.due_date) > 0
            ? '<span class="badge-days ' + (daysPast(r.due_date) > 30 ? 'danger' : 'warn') + '">' + daysPast(r.due_date) + 'd overdue</span>'
            : '<span class="muted">—</span>';
        document.getElementById('vt-time').innerHTML = '<div class="vtab-grid">' + [
            vtRow('Status', '<span class="inv-status ' + r.status + '">' + r.status + '</span>'),
            vtRow('Issue Date', r.issue_date ? fmtDate(r.issue_date) : '<span class="muted">—</span>'),
            vtRow('Period', fmtPeriodRange(r.invoice_period_start, r.invoice_period_end)),
            vtRow('Due Date', r.due_date ? fmtDate(r.due_date) : '<span class="muted">—</span>'),
            vtRow('Days Overdue', odNote),
            vtRow('Created', r.created_at ? fmtDate(r.created_at) : '<span class="muted">—</span>'),
            vtRow('Cancelled', r.cancel_date ? fmtDate(r.cancel_date) + (r.cancel_reason ? ' <small class="muted">(' + escapeHtml(r.cancel_reason) + ')</small>' : '') : '<span class="muted">—</span>')
        ].join('') + '</div>';

        // reset to first tab
        $('#invViewModal .vtab-btn').removeClass('active').first().addClass('active');
        $('#invViewModal .vtab-pane').removeClass('active');
        $('#vt-items').addClass('active');
        document.getElementById('invViewModal').classList.add('active');
    }

    function closeInvoiceView() {
        document.getElementById('invViewModal').classList.remove('active');
    }

    // vtab switching + overlay close
    $(document).on('click', '#invViewModal .vtab-btn', function() {
        const t = $(this).data('vtab');
        $('#invViewModal .vtab-btn').removeClass('active');
        $(this).addClass('active');
        $('#invViewModal .vtab-pane').removeClass('active');
        $('#' + t).addClass('active');
    });
    document.getElementById('invViewModal').addEventListener('click', function(e) {
        if (e.target === this) closeInvoiceView();
    });

    // ─── add / edit modal ───
    function openAddModal() {
        if (!CAN_WRITE) return;
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> New Invoice';
        document.getElementById('invoiceForm').reset();
        document.getElementById('invoiceId').value = '';
        document.getElementById('invoiceStatus').value = 'unpaid';
        // default month = current
        const now = new Date();
        const ym = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        const last = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
        const today = ym + '-' + String(now.getDate()).padStart(2, '0');
        document.getElementById('billing_month').value = ym;
        document.getElementById('due_date').value             = ym + '-10';
        document.getElementById('issue_date').value           = today;
        document.getElementById('invoice_period_start').value = ym + '-01';
        document.getElementById('invoice_period_end').value   = ym + '-' + String(last).padStart(2, '0');
        // reset SDs
        ['customer_id','package_id','parent_invoice_id'].forEach(id => {
            const el = document.getElementById(id); if (el) { el.value = ''; el._sd && el._sd.refresh(); }
        });
        const it = document.getElementById('invoice_type');  if (it) { it.value = 'recurring'; it._sd && it._sd.refresh(); }
        const cu = document.getElementById('currency');      if (cu) { cu.value = 'PKR'; cu._sd && cu._sd.refresh(); }
        document.getElementById('parentInvoiceGroup').classList.add('initially-hidden');

        // numeric defaults
        const zeroFields = ['discount','discount_amount','discount_pct','tax','tax_pct',
                            'wht_amount','wht_pct','late_fee','equipment_charges','installation_charges',
                            'additional_charges','proration_days','proration_amount'];
        zeroFields.forEach(id => { const el = document.getElementById(id); if (el) el.value = '0'; });
        ['discount_reason','additional_charges_description','notes','customer_notes','notes_internal'].forEach(id => {
            const el = document.getElementById(id); if (el) el.value = '';
        });
        clearStockLines();
        recalcTotal();
        document.getElementById('invoiceModal').classList.add('active');
    }

    function editInvoice(inv) {
        if (!CAN_WRITE) return;
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Invoice ' + inv.invoice_number;
        document.getElementById('invoiceId').value     = inv.id;
        document.getElementById('invoiceStatus').value = inv.status;
        const ce = document.getElementById('customer_id'); ce.value = inv.customer_id; ce._sd && ce._sd.refresh();
        const pe = document.getElementById('package_id');  pe.value = inv.package_id;  pe._sd && pe._sd.refresh();
        document.getElementById('billing_month').value        = inv.billing_month || '';
        document.getElementById('due_date').value             = inv.due_date || '';
        document.getElementById('issue_date').value           = inv.issue_date || '';
        document.getElementById('invoice_period_start').value = inv.invoice_period_start || '';
        document.getElementById('invoice_period_end').value   = inv.invoice_period_end   || '';
        document.getElementById('amount').value           = Number(inv.amount).toFixed(2);
        document.getElementById('discount').value         = Number(inv.discount || 0).toFixed(2);
        document.getElementById('tax').value              = Number(inv.tax || 0).toFixed(2);
        document.getElementById('late_fee').value         = Number(inv.late_fee || 0).toFixed(2);
        document.getElementById('notes').value            = inv.notes || '';

        // new structured fields
        const it = document.getElementById('invoice_type'); if (it) { it.value = inv.invoice_type || 'recurring'; it._sd && it._sd.refresh(); }
        const cu = document.getElementById('currency');     if (cu) { cu.value = inv.currency || 'PKR'; cu._sd && cu._sd.refresh(); }
        document.getElementById('equipment_charges').value    = Number(inv.equipment_charges || 0).toFixed(2);
        document.getElementById('installation_charges').value = Number(inv.installation_charges || 0).toFixed(2);
        document.getElementById('additional_charges').value   = Number(inv.additional_charges || 0).toFixed(2);
        document.getElementById('additional_charges_description').value = inv.additional_charges_description || '';
        document.getElementById('discount_amount').value = Number(inv.discount_amount || inv.discount || 0).toFixed(2);
        document.getElementById('discount_pct').value    = Number(inv.discount_pct || 0).toFixed(2);
        document.getElementById('discount_reason').value = inv.discount_reason || '';
        document.getElementById('tax_pct').value         = Number(inv.tax_pct || 0).toFixed(2);
        document.getElementById('wht_amount').value      = Number(inv.wht_amount || 0).toFixed(2);
        document.getElementById('wht_pct').value         = Number(inv.wht_pct || 0).toFixed(2);
        document.getElementById('proration_days').value  = parseInt(inv.proration_days || 0);
        document.getElementById('proration_amount').value = Number(inv.proration_amount || 0).toFixed(2);
        document.getElementById('customer_notes').value  = inv.customer_notes || '';
        document.getElementById('notes_internal').value  = inv.notes_internal || '';

        // parent picker
        const grp = document.getElementById('parentInvoiceGroup');
        const isCN = (inv.invoice_type === 'credit_note' || inv.invoice_type === 'adjustment');
        if (isCN) {
            grp.classList.remove('initially-hidden');
            if (!parentInvLoaded) loadParentInvoices();
            const pInv = document.getElementById('parent_invoice_id');
            // load then set value
            setTimeout(() => {
                if (pInv) { pInv.value = inv.parent_invoice_id || ''; pInv._sd && pInv._sd.refresh(); }
            }, 250);
        } else {
            grp.classList.add('initially-hidden');
        }
        loadStockLines(inv.id); // billed stock comes back into the form
        recalcTotal();
        document.getElementById('invoiceModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('invoiceModal').classList.remove('active');
    }

    if (CAN_WRITE) {
        document.getElementById('invoiceModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.getElementById('invoiceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            collectStockLines(); // stock lines ride along in the hidden field
            const fd = new FormData(this);
            const action = isEditMode ? 'updateInvoice' : 'addInvoice';
            Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.ajax({
                url: '?action=' + action, method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', title: 'Success', text: resp.message, timer: 2000, showConfirmButton: false });
                        closeModal();
                        setTimeout(loadInvoices, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Connection Error' }); }
            });
        });
    }

    // hard delete (super admin) — soft-deletes and removes from list
    function cancelInvoice(id, num) {
        Swal.fire({
            icon: 'warning', title: 'Delete Invoice ' + num + '?',
            input: 'textarea',
            inputLabel: 'Reason (optional)',
            inputPlaceholder: 'Why is this invoice being deleted?',
            inputAttributes: { maxlength: 255 },
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Keep'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', id);
            fd.append('cancel_reason', result.value || '');
            $.ajax({
                url: '?action=deleteInvoice', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(loadInvoices, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Connection Error' }); }
            });
        });
    }

    // status=cancelled (admin+) — keeps invoice in records, just flags it cancelled
    function cancelInvoiceAction(id, num) {
        Swal.fire({
            icon: 'warning', title: 'Cancel Invoice ' + num + '?',
            input: 'textarea',
            inputLabel: 'Reason (required)',
            inputPlaceholder: 'Why is this invoice being cancelled?',
            inputAttributes: { maxlength: 255, required: true },
            inputValidator: v => (!v || !v.trim()) ? 'Reason is required' : null,
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Cancel Invoice', cancelButtonText: 'Keep'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', id);
            fd.append('cancel_reason', (result.value || '').trim());
            $.ajax({
                url: '?action=cancelInvoice', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(loadInvoices, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Connection Error' }); }
            });
        });
    }

    // ─── batch wizard ───
    let batchPreviewData = null;

    function openBatchWizard() {
        if (!CAN_WRITE) return;
        batchGoto(1);
        document.getElementById('batchModal').classList.add('active');
    }

    function closeBatchModal() {
        document.getElementById('batchModal').classList.remove('active');
    }

    function batchGoto(step) {
        document.querySelectorAll('.batch-step').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.batch-step-pill').forEach(p => p.classList.remove('active','done'));
        document.getElementById('step' + step).classList.add('active');
        document.getElementById('pill' + step).classList.add('active');
        for (let i = 1; i < step; i++) document.getElementById('pill' + i).classList.add('done');
        if (step === 2) runBatchPreview();
        if (step === 3) fillBatchConfirm();
    }

    function runBatchPreview() {
        const month = document.getElementById('batchMonth').value;
        const skip  = document.getElementById('batchSkip').checked ? 1 : 0;
        if (!month) {
            Swal.fire({ icon: 'warning', title: 'Pick a month' });
            batchGoto(1);
            return;
        }
        document.getElementById('previewNum').textContent = '...';
        document.getElementById('previewLabel').textContent = 'loading';
        document.getElementById('previewList').innerHTML = '';
        const fd = new FormData();
        fd.append('month', month);
        fd.append('skip_existing', skip);
        $.ajax({
            url: '?action=batchPreview', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (!resp.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    batchGoto(1);
                    return;
                }
                batchPreviewData = resp;
                document.getElementById('previewNum').textContent = resp.count;
                document.getElementById('previewLabel').textContent = 'active customers will receive an invoice for ' + month;
                const list = (resp.data || []).slice(0, 20);
                document.getElementById('previewList').innerHTML = list.length
                    ? list.map(c => `<div style="padding:4px 0;border-bottom:1px dashed #eee;font-size:13px">
                        <strong>${escapeHtml(c.full_name)}</strong> <span class="muted">(${escapeHtml(c.customer_code)})</span>
                        — ${escapeHtml(c.pkg_name || '—')} — ${fmtMoney(c.price)}
                      </div>`).join('')
                    : '<div class="muted" style="text-align:center;padding:16px">No eligible customers.</div>';
                document.getElementById('toStep3Btn').disabled = !resp.count;
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Connection Error' }); }
        });
    }

    function fillBatchConfirm() {
        if (!batchPreviewData) { batchGoto(2); return; }
        const month  = document.getElementById('batchMonth').value;
        const dueDay = parseInt(document.getElementById('batchDueDay').value || 10);
        const taxPct = parseFloat(document.getElementById('batchTaxPct').value || 0);
        document.getElementById('confMonth').textContent = month;
        document.getElementById('confDue').textContent   = month + '-' + String(dueDay).padStart(2, '0');
        document.getElementById('confTax').textContent   = taxPct + '%';
        document.getElementById('confCount').textContent = batchPreviewData.count;
    }

    function batchGenerate() {
        const month  = document.getElementById('batchMonth').value;
        const dueDay = parseInt(document.getElementById('batchDueDay').value || 10);
        const taxPct = parseFloat(document.getElementById('batchTaxPct').value || 0);
        const skip   = document.getElementById('batchSkip').checked ? 1 : 0;
        const count  = batchPreviewData ? batchPreviewData.count : 0;
        if (!count) {
            Swal.fire({ icon: 'info', title: 'Nothing to generate' });
            return;
        }
        Swal.fire({
            icon: 'question', title: 'Generate ' + count + ' invoices?',
            text: 'For ' + month + ', due ' + month + '-' + String(dueDay).padStart(2, '0') + '.',
            showCancelButton: true, confirmButtonText: 'Generate', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            Swal.fire({ title: 'Generating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            const fd = new FormData();
            fd.append('month', month);
            fd.append('due_day', dueDay);
            fd.append('tax_pct', taxPct);
            fd.append('skip_existing', skip);
            $.ajax({
                url: '?action=batchGenerate', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (!resp.success) {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                        return;
                    }
                    Swal.fire({
                        icon: 'success',
                        title: 'Batch Generated',
                        html: '<strong>' + resp.created + '</strong> invoices created' + (resp.failed ? ', ' + resp.failed + ' failed' : '') + '.'
                    });
                    closeBatchModal();
                    setTimeout(loadInvoices, 200);
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Connection Error' }); }
            });
        });
    }

    if (CAN_WRITE) {
        document.getElementById('batchModal').addEventListener('click', function(e) {
            if (e.target === this) closeBatchModal();
        });
    }
    </script>
    <?php endif; ?>
</body>
</html>
