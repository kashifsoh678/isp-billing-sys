<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: super_admin / admin / accountant
if (!isset($_SESSION['user_id']) || !canManageExpenses()) {
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
$current_page = 'expenses';
$can_delete   = can('expenses', 'd');
$currency     = getSetting('currency_symbol', '$');

// allowed expense categories
$EXP_CATS = ['salary','equipment','utilities','rent','bandwidth','maintenance','other'];
// allowed payment methods + recurrence periods
$PAY_METHODS = ['cash','bank_transfer','cheque','online'];
$REC_PERIODS = ['monthly','quarterly','yearly'];
$EXP_STATUSES = ['pending','approved','rejected'];

$is_admin_level = isAdminLevel();

// receipt upload: jpg/jpeg/png/webp/pdf — max 5MB
function handleReceiptUpload($file, $expense_id) {
    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($file['error'] !== UPLOAD_ERR_OK) return '';
    if ($file['size'] > 5 * 1024 * 1024) return ''; // 5MB cap
    $allowed = ['jpg','jpeg','png','webp','pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';
    $dir = __DIR__ . '/uploads/receipts';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = 'expense_' . intval($expense_id) . '_' . time() . '.' . $ext;
    $dest  = $dir . '/' . $fname;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return '';
    return 'uploads/receipts/' . $fname;
}

// vendor invoice upload (separate dir)
function handleVendorInvoiceUpload($file, $expense_id) {
    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($file['error'] !== UPLOAD_ERR_OK) return '';
    if ($file['size'] > 5 * 1024 * 1024) return '';
    $allowed = ['jpg','jpeg','png','webp','pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';
    $dir = __DIR__ . '/uploads/vendor_invoices';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = 'vinv_' . intval($expense_id) . '_' . time() . '.' . $ext;
    $dest  = $dir . '/' . $fname;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return '';
    return 'uploads/vendor_invoices/' . $fname;
}

// next sequence: EXP-YYYY-NNNN
function nextExpenseNumber($conn) {
    $year = date('Y');
    $like = "EXP-$year-%";
    $st = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(expense_number,'-',-1) AS UNSIGNED)) AS mx
                          FROM expenses WHERE expense_number LIKE ?");
    $st->bind_param("s", $like);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    $next = (int)($r['mx'] ?? 0) + 1;
    return sprintf('EXP-%s-%04d', $year, $next);
}

// compute outstanding + payment_status from amount/paid/due/status
function deriveExpensePayStatus($amount, $paid, $dueDate) {
    $amount = (float)$amount; $paid = (float)$paid;
    $out = max(0, $amount - $paid);
    if ($paid >= $amount && $amount > 0) return ['paid', $out];
    $st = $paid > 0 ? 'partial' : 'unpaid';
    if ($dueDate && strtotime($dueDate) < strtotime(date('Y-m-d')) && $st !== 'paid') return ['overdue', $out];
    return [$st, $out];
}

// recompute denormalized paid_amount/outstanding/payment_status from expense_payments rows
function recomputeExpenseFromPayments($conn, $expenseId) {
    $expenseId = (int)$expenseId;
    if ($expenseId <= 0) return false;
    // load expense base figures
    $ex = $conn->prepare("SELECT amount, due_date, wht_pct FROM expenses WHERE id=? AND is_deleted=0");
    $ex->bind_param("i", $expenseId);
    $ex->execute();
    $exR = $ex->get_result()->fetch_assoc();
    $ex->close();
    if (!$exR) return false;
    $amount = (float)$exR['amount'];
    $whtPct = (float)($exR['wht_pct'] ?? 0);

    // sum active payments + latest date
    $sp = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid, MAX(payment_date) AS last_dt
                          FROM expense_payments WHERE expense_id=? AND is_deleted=0");
    $sp->bind_param("i", $expenseId);
    $sp->execute();
    $spR = $sp->get_result()->fetch_assoc();
    $sp->close();
    $paid    = (float)$spR['paid'];
    $lastDt  = $spR['last_dt'] ?: null;
    $whtAmt  = round(($amount * $whtPct) / 100, 2);
    $netPaid = max(0, $paid - $whtAmt);
    [$ps, $out] = deriveExpensePayStatus($amount, $paid, ($exR['due_date'] ?? null) ?: null);

    $up = $conn->prepare("UPDATE expenses SET paid_amount=?, outstanding_amount=?, payment_status=?,
                          paid_date=?, net_paid_amount=?, withholding_tax_amount=? WHERE id=? AND is_deleted=0");
    $up->bind_param("ddssddi", $paid, $out, $ps, $lastDt, $netPaid, $whtAmt, $expenseId);
    $ok = $up->execute();
    $up->close();
    return $ok ? ['paid'=>$paid, 'outstanding'=>$out, 'status'=>$ps] : false;
}

// next recurrence date helper
function bumpRecurrence($from, $period) {
    $base = $from ?: date('Y-m-d');
    $map = ['monthly' => '+1 month', 'quarterly' => '+3 months', 'yearly' => '+1 year'];
    $delta = $map[$period] ?? '+1 month';
    return date('Y-m-d', strtotime("$delta", strtotime($base)));
}

// helper — month bounds
function monthBounds($offset = 0) {
    $first = date('Y-m-01', strtotime("$offset month"));
    $last  = date('Y-m-t',  strtotime("$offset month"));
    return [$first, $last];
}

// csv import/template contract for this page
function expenseImportSpec() {
    global $EXP_CATS, $PAY_METHODS, $REC_PERIODS;
    $isAdminLvl = isAdminLevel();
    $uid        = $_SESSION['user_id'] ?? null;

    return [
        'entity'  => 'Expense',
        'table'   => 'expenses',
        'perm'    => 'expenses',
        'unique'  => [], // no real UNIQUE key on expenses besides id — expense_number is app-generated, not DB-unique
        'columns' => [
            'expense_date'    => ['label' => 'Expense Date',   'type' => 'date', 'required' => true, 'demo' => '2026-01-15'],
            'category'        => ['label' => 'Category',       'type' => 'enum', 'values' => $EXP_CATS, 'required' => true, 'demo' => 'utilities'],
            'subcategory'     => ['label' => 'Subcategory',    'type' => 's', 'max' => 50, 'demo' => 'Electricity'],
            'description'     => ['label' => 'Description',    'type' => 's', 'required' => true, 'max' => 255, 'demo' => 'Demo expense entry'],
            'vendor_name'     => ['label' => 'Vendor Name',    'type' => 's', 'max' => 100, 'demo' => 'Demo Vendor 1'],
            'amount'          => ['label' => 'Amount',         'type' => 'd', 'required' => true, 'demo' => '1500'],
            'tax_amount'      => ['label' => 'Tax Amount',     'type' => 'd', 'default' => 0, 'demo' => '0'],
            'payment_method'  => ['label' => 'Payment Method', 'type' => 'enum', 'values' => $PAY_METHODS, 'default' => 'cash', 'demo' => 'cash'],
            'reference_number'=> ['label' => 'Reference #',    'type' => 's', 'max' => 100, 'demo' => ''],
            'paid_by'         => ['label' => 'Paid By (username)', 'type' => 'lookup', 'required' => true,
                                   'lookup' => ['table' => 'users', 'match' => ['username', 'name'],
                                                'where' => "is_deleted = 0 AND status = 'active' AND " . roleInSql('users', 'expenses', 'v')],
                                   'demo' => 'admin'],
            'notes'           => ['label' => 'Notes', 'type' => 's', 'demo' => ''],
            'due_date'        => ['label' => 'Due Date', 'type' => 'date', 'demo' => ''],
            'paid_amount'     => ['label' => 'Paid Amount', 'type' => 'd', 'default' => 0, 'demo' => '0'],
            'wht_pct'         => ['label' => 'WHT %', 'type' => 'd', 'default' => 0, 'demo' => '0'],
            'vendor_id'       => ['label' => 'Vendor (name or code)', 'type' => 'lookup',
                                   'lookup' => ['table' => 'vendors', 'match' => ['vendor_name', 'vendor_code'], 'where' => "is_deleted = 0 AND status = 'active'"],
                                   'demo' => 'Demo Vendor 1'],
            'vendor_invoice_number' => ['label' => 'Vendor Invoice #', 'type' => 's', 'max' => 100, 'demo' => ''],
            'vendor_invoice_date'   => ['label' => 'Vendor Invoice Date', 'type' => 'date', 'demo' => ''],
            'gl_account_code' => ['label' => 'GL Account Code', 'type' => 's', 'max' => 30, 'demo' => ''],
            'cost_center'     => ['label' => 'Cost Center', 'type' => 's', 'max' => 50, 'demo' => ''],
            'area_id'         => ['label' => 'Area', 'type' => 'lookup',
                                   'lookup' => ['table' => 'areas', 'match' => ['name'], 'where' => "is_deleted = 0 AND status = 'active'"],
                                   'demo' => 'Demo Area 1'],
            'branch_id'       => ['label' => 'Branch #', 'type' => 'i', 'demo' => ''],
            'is_capex'        => ['label' => 'CapEx?', 'type' => 'bool', 'default' => 0, 'demo' => 'No'],
            'depreciation_period_months' => ['label' => 'Depreciation Months', 'type' => 'i', 'demo' => ''],
            'bank_account_id' => ['label' => 'Bank Account', 'type' => 'lookup',
                                   'lookup' => ['table' => 'bank_accounts', 'match' => ['account_name', 'account_number'], 'where' => "is_deleted = 0 AND status = 'active'"],
                                   'demo' => ''],
            'cheque_number'   => ['label' => 'Cheque #', 'type' => 's', 'max' => 50, 'demo' => ''],
            'cheque_date'     => ['label' => 'Cheque Date', 'type' => 'date', 'demo' => ''],
            'is_recurring'    => ['label' => 'Recurring?', 'type' => 'bool', 'default' => 0, 'demo' => 'No'],
            'recurrence_period' => ['label' => 'Recurrence Period', 'type' => 'enum', 'values' => $REC_PERIODS, 'demo' => ''],
            'expense_number'  => ['label' => 'Expense #', 'type' => 's', 'max' => 30, 'demo' => ''],
        ],
        // approval state mirrors what addExpense does for the CURRENT importing user — same on every row
        'static' => [
            'status'              => $isAdminLvl ? 'approved' : 'pending',
            'approved_by_user_id' => $isAdminLvl ? $uid : null,
            'approved_at'         => $isAdminLvl ? date('Y-m-d H:i:s') : null,
        ],
        // seed the expense_number sequence ONCE — never query per row
        'init' => function ($conn, &$state) {
            $seed = nextExpenseNumber($conn); // 'EXP-YYYY-NNNN'
            $state['expYear'] = date('Y');
            $state['expSeq']  = (int)substr($seed, strrpos($seed, '-') + 1);
        },
        'before' => function (&$row, $conn, &$state) {
            // same bounds addExpense enforces
            if ($row['amount'] <= 0)                              return 'Amount must be greater than 0';
            if ($row['tax_amount'] < 0)                           return 'Tax cannot be negative';
            if ($row['paid_amount'] < 0)                          return 'Paid amount cannot be negative';
            if ($row['wht_pct'] < 0 || $row['wht_pct'] > 100)     return 'WHT % must be 0-100';

            // cash clears reference, non-cheque clears cheque fields — same as addExpense
            if ($row['payment_method'] === 'cash') $row['reference_number'] = null;
            if ($row['payment_method'] !== 'cheque') { $row['cheque_number'] = null; $row['cheque_date'] = null; }

            if (!empty($row['is_recurring'])) {
                if (empty($row['recurrence_period'])) return 'Recurrence Period is required when Recurring is Yes';
                $row['next_recurrence_date'] = bumpRecurrence($row['expense_date'], $row['recurrence_period']);
            } else {
                $row['recurrence_period']    = null;
                $row['next_recurrence_date'] = null; // key must exist on every row — row 1 sets insertCols for all
            }

            // same derivation addExpense runs at insert time — cap paid, compute wht/net/status/outstanding
            if ($row['paid_amount'] > $row['amount']) $row['paid_amount'] = $row['amount'];
            $row['withholding_tax_amount'] = round(($row['amount'] * $row['wht_pct']) / 100, 2);
            $row['net_paid_amount']        = max(0, $row['paid_amount'] - $row['withholding_tax_amount']);
            [$row['payment_status'], $row['outstanding_amount']] = deriveExpensePayStatus($row['amount'], $row['paid_amount'], $row['due_date'] ?: null);

            // auto-gen expense_number when blank — sequential, no per-row query
            if (empty($row['expense_number'])) {
                $state['expSeq']++;
                $row['expense_number'] = sprintf('EXP-%s-%04d', $state['expYear'], $state['expSeq']);
            }
            return null;
        },
        // same helper addExpense/updateExpense call after every write
        'after' => function ($id, $row, $conn, &$state) {
            syncExpenseLedger($conn, $id);
        },
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'importCsv':      rsImportRun($conn, expenseImportSpec());
            case 'importTemplate': rsImportTemplate(expenseImportSpec());

            // ─── list ──────────────────────────────────────────────────
            case 'getExpenses': {
                $from   = $_GET['from']     ?? '';
                $to     = $_GET['to']       ?? '';
                $cat    = $_GET['category'] ?? '';
                $paidBy = (int)($_GET['paid_by'] ?? 0);
                $pm     = $_GET['payment_method'] ?? '';
                $st     = $_GET['status'] ?? '';
                $recOnly = !empty($_GET['recurring_only']);
                $q      = trim($_GET['q'] ?? '');

                $where = "e.is_deleted = 0";
                $types = "";
                $vals  = [];
                if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= " AND e.expense_date >= ?"; $types .= "s"; $vals[] = $from; }
                if ($to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where .= " AND e.expense_date <= ?"; $types .= "s"; $vals[] = $to; }
                if ($cat  !== '' && in_array($cat, $EXP_CATS, true))            { $where .= " AND e.category = ?";      $types .= "s"; $vals[] = $cat; }
                if ($paidBy > 0) { $where .= " AND e.paid_by = ?"; $types .= "i"; $vals[] = $paidBy; }
                if ($pm !== '' && in_array($pm, $PAY_METHODS, true))            { $where .= " AND e.payment_method = ?"; $types .= "s"; $vals[] = $pm; }
                if ($st !== '' && in_array($st, $EXP_STATUSES, true))           { $where .= " AND e.status = ?";         $types .= "s"; $vals[] = $st; }
                if ($recOnly) { $where .= " AND e.is_recurring = 1"; }
                if ($q !== '')   { $where .= " AND (e.description LIKE ? OR e.vendor_name LIKE ? OR e.reference_number LIKE ?)"; $types .= "sss"; $vals[] = "%$q%"; $vals[] = "%$q%"; $vals[] = "%$q%"; }

                $sql = "SELECT e.id, e.expense_date, e.category, e.subcategory, e.description, e.vendor_name,
                               e.amount, e.tax_amount, e.payment_method, e.reference_number,
                               e.paid_by, e.receipt_image, e.notes, e.status,
                               e.is_recurring, e.recurrence_period, e.created_at,
                               e.expense_number, e.due_date, e.paid_date, e.payment_status,
                               e.paid_amount, e.outstanding_amount,
                               e.vendor_id, e.vendor_invoice_number, e.vendor_invoice_path,
                               e.withholding_tax_amount, e.wht_pct, e.net_paid_amount,
                               e.gl_account_code, e.cost_center, e.is_capex, e.bank_account_id,
                               COALESCE(u.name, u.username, '—') AS paid_by_name,
                               v.vendor_name AS vendor_master_name,
                               b.account_name AS bank_account_name
                        FROM expenses e
                        LEFT JOIN users u ON u.id = e.paid_by
                        LEFT JOIN vendors v ON v.id = e.vendor_id
                        LEFT JOIN bank_accounts b ON b.id = e.bank_account_id
                        WHERE $where
                        ORDER BY e.expense_date DESC, e.id DESC";
                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$vals);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'                => (int)$r['id'],
                        'expense_date'      => $r['expense_date'],
                        'category'          => $r['category'],
                        'subcategory'       => $r['subcategory'] ?? '',
                        'description'       => $r['description'],
                        'vendor_name'       => $r['vendor_name'] ?? '',
                        'amount'            => number_format((float)$r['amount'], 2, '.', ''),
                        'amount_display'    => $currency . number_format((float)$r['amount'], 2),
                        'tax_amount'        => number_format((float)$r['tax_amount'], 2, '.', ''),
                        'tax_display'       => $currency . number_format((float)$r['tax_amount'], 2),
                        'payment_method'    => $r['payment_method'] ?? 'cash',
                        'reference_number'  => $r['reference_number'] ?? '',
                        'paid_by'           => (int)$r['paid_by'],
                        'paid_by_name'      => $r['paid_by_name'],
                        'receipt_image'     => $r['receipt_image'] ?? '',
                        'notes'             => $r['notes'] ?? '',
                        'status'            => $r['status'] ?? 'approved',
                        'is_recurring'      => (int)$r['is_recurring'],
                        'recurrence_period' => $r['recurrence_period'] ?? '',
                        'created_at'        => date('c', strtotime($r['created_at'])),
                        'expense_number'    => $r['expense_number'] ?? '',
                        'due_date'          => $r['due_date'] ?? '',
                        'paid_date'         => $r['paid_date'] ?? '',
                        'payment_status'    => $r['payment_status'] ?? 'paid',
                        'paid_amount'       => number_format((float)($r['paid_amount'] ?? 0), 2, '.', ''),
                        'outstanding_amount'=> number_format((float)($r['outstanding_amount'] ?? 0), 2, '.', ''),
                        'vendor_id'         => $r['vendor_id'] !== null ? (int)$r['vendor_id'] : 0,
                        'vendor_master_name'=> $r['vendor_master_name'] ?? '',
                        'vendor_invoice_number' => $r['vendor_invoice_number'] ?? '',
                        'vendor_invoice_path'   => $r['vendor_invoice_path'] ?? '',
                        'wht_pct'           => number_format((float)($r['wht_pct'] ?? 0), 2, '.', ''),
                        'withholding_tax_amount' => number_format((float)($r['withholding_tax_amount'] ?? 0), 2, '.', ''),
                        'net_paid_amount'   => number_format((float)($r['net_paid_amount'] ?? 0), 2, '.', ''),
                        'gl_account_code'   => $r['gl_account_code'] ?? '',
                        'cost_center'       => $r['cost_center'] ?? '',
                        'is_capex'          => (int)($r['is_capex'] ?? 0),
                        'bank_account_id'   => $r['bank_account_id'] !== null ? (int)$r['bank_account_id'] : 0,
                        'bank_account_name' => $r['bank_account_name'] ?? ''
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows, 'currency' => $currency]);
                exit();
            }

            // ─── single detail ────────────────────────────────────────
            case 'getExpenseDetail': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $stmt = $conn->prepare("SELECT e.*, COALESCE(u.name,u.username,'—') AS paid_by_name,
                                               COALESCE(a.name,a.username,NULL) AS approver_name
                                        FROM expenses e
                                        LEFT JOIN users u ON u.id = e.paid_by
                                        LEFT JOIN users a ON a.id = e.approved_by_user_id
                                        WHERE e.id = ? AND e.is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$r) { echo json_encode(['success'=>false,'message'=>'Not found']); exit(); }
                $r['amount_display'] = $currency . number_format((float)$r['amount'], 2);
                $r['tax_display']    = $currency . number_format((float)($r['tax_amount'] ?? 0), 2);
                $r['id']                  = (int)$r['id'];
                $r['paid_by']             = (int)$r['paid_by'];
                $r['is_recurring']        = (int)($r['is_recurring'] ?? 0);
                $r['approved_by_user_id'] = isset($r['approved_by_user_id']) ? (int)$r['approved_by_user_id'] : 0;
                echo json_encode(['success'=>true,'data'=>$r,'currency'=>$currency,'is_admin_level'=>$is_admin_level]);
                exit();
            }

            // ─── monthly summary ──────────────────────────────────────
            case 'getMonthlySummary': {
                [$tStart, $tEnd] = monthBounds(0);
                [$lStart, $lEnd] = monthBounds(-1);

                // this month total + count + tax
                $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS tot,
                                               COALESCE(SUM(tax_amount),0) AS tax_tot,
                                               COUNT(*) AS cnt
                                        FROM expenses WHERE is_deleted = 0 AND expense_date BETWEEN ? AND ?");
                $stmt->bind_param("ss", $tStart, $tEnd);
                $stmt->execute();
                $tm = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // last month total
                $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS tot
                                        FROM expenses WHERE is_deleted = 0 AND expense_date BETWEEN ? AND ?");
                $stmt->bind_param("ss", $lStart, $lEnd);
                $stmt->execute();
                $lm = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // top category this month
                $stmt = $conn->prepare("SELECT category, SUM(amount) AS tot
                                        FROM expenses WHERE is_deleted = 0 AND expense_date BETWEEN ? AND ?
                                        GROUP BY category ORDER BY tot DESC LIMIT 1");
                $stmt->bind_param("ss", $tStart, $tEnd);
                $stmt->execute();
                $top = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // category breakdown this month
                $stmt = $conn->prepare("SELECT category, SUM(amount) AS tot, COUNT(*) AS cnt
                                        FROM expenses WHERE is_deleted = 0 AND expense_date BETWEEN ? AND ?
                                        GROUP BY category ORDER BY tot DESC");
                $stmt->bind_param("ss", $tStart, $tEnd);
                $stmt->execute();
                $br = $stmt->get_result();
                $breakdown = [];
                while ($row = $br->fetch_assoc()) {
                    $breakdown[] = [
                        'category' => $row['category'],
                        'amount'   => (float)$row['tot'],
                        'count'    => (int)$row['cnt']
                    ];
                }
                $stmt->close();

                echo json_encode([
                    'success'            => true,
                    'this_month_total'   => (float)$tm['tot'],
                    'this_month_tax'     => (float)$tm['tax_tot'],
                    'this_month_count'   => (int)$tm['cnt'],
                    'last_month_total'   => (float)$lm['tot'],
                    'top_category'       => $top ? ['name' => $top['category'], 'amount' => (float)$top['tot']] : null,
                    'breakdown'          => $breakdown,
                    'month_label'        => date('F Y'),
                    'last_month_label'   => date('F Y', strtotime('-1 month')),
                    'currency'           => $currency
                ]);
                exit();
            }

            // ─── staff list (paid_by dropdown) ────────────────────────
            case 'getStaff': {
                if (!canManageExpenses()) { echo json_encode(['success'=>false,'message'=>'No access']); exit(); }
                $sql = "SELECT id, name, username, role FROM users
                        WHERE is_deleted = 0 AND status = 'active'
                          AND " . roleInSql('users', 'expenses', 'v') . "
                        ORDER BY name ASC, username ASC";
                $res = $conn->query($sql);
                $staff = [];
                while ($r = $res->fetch_assoc()) {
                    $staff[] = [
                        'id'    => (int)$r['id'],
                        'name'  => $r['name'] ?: $r['username'],
                        'role'  => $r['role']
                    ];
                }
                echo json_encode(['success'=>true,'data'=>$staff]);
                exit();
            }

            // ─── lookup lists for form ────────────────────────────────
            case 'getVendorsList': {
                $rows = [];
                try {
                    $res = $conn->query("SELECT id, vendor_code, vendor_name FROM vendors WHERE is_deleted=0 AND status='active' ORDER BY vendor_name ASC");
                    if ($res) while ($r = $res->fetch_assoc()) {
                        $rows[] = ['id' => (int)$r['id'], 'code' => $r['vendor_code'], 'name' => $r['vendor_name']];
                    }
                } catch (Throwable $e) {}
                echo json_encode(['success'=>true,'data'=>$rows]); exit();
            }
            case 'getBankAccountsList': {
                $rows = [];
                try {
                    $res = $conn->query("SELECT id, account_name, bank_name, account_number, currency FROM bank_accounts WHERE is_deleted=0 AND status='active' ORDER BY is_default DESC, account_name ASC");
                    if ($res) while ($r = $res->fetch_assoc()) {
                        $rows[] = [
                            'id' => (int)$r['id'],
                            'name' => $r['account_name'],
                            'bank' => $r['bank_name'],
                            'number' => $r['account_number'],
                            'currency' => $r['currency']
                        ];
                    }
                } catch (Throwable $e) {}
                echo json_encode(['success'=>true,'data'=>$rows]); exit();
            }
            case 'getAreasList': {
                $rows = [];
                try {
                    $res = $conn->query("SELECT id, name FROM areas WHERE is_deleted=0 AND status='active' ORDER BY name ASC");
                    if ($res) while ($r = $res->fetch_assoc()) {
                        $rows[] = ['id' => (int)$r['id'], 'name' => $r['name']];
                    }
                } catch (Throwable $e) {}
                echo json_encode(['success'=>true,'data'=>$rows]); exit();
            }

            // ─── add / update ─────────────────────────────────────────
            case 'addExpense':
            case 'updateExpense': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit();
                }
                $isEdit = $_GET['action'] === 'updateExpense';
                $id      = (int)($_POST['id'] ?? 0);
                $date    = $_POST['expense_date'] ?? '';
                $cat     = $_POST['category'] ?? '';
                $sub     = trim($_POST['subcategory'] ?? '');
                $desc    = trim($_POST['description'] ?? '');
                $vendor  = trim($_POST['vendor_name'] ?? '');
                $amt     = (float)($_POST['amount'] ?? 0);
                $tax     = (float)($_POST['tax_amount'] ?? 0);
                $pm      = $_POST['payment_method'] ?? 'cash';
                $refNum  = trim($_POST['reference_number'] ?? '');
                $paidBy  = (int)($_POST['paid_by'] ?? 0);
                $notes   = trim($_POST['notes'] ?? '');
                $remRec  = !empty($_POST['remove_receipt']);
                $remVInv = !empty($_POST['remove_vendor_invoice']);
                $isRec   = !empty($_POST['is_recurring']) ? 1 : 0;
                $recPer  = $_POST['recurrence_period'] ?? '';

                // accounting fields
                $expNum     = trim($_POST['expense_number'] ?? '');
                $dueDate    = trim($_POST['due_date'] ?? '');
                $paidDate   = trim($_POST['paid_date'] ?? '');
                $paidAmt    = (float)($_POST['paid_amount'] ?? 0);
                $vendorId   = (int)($_POST['vendor_id'] ?? 0);
                $vInvNum    = trim($_POST['vendor_invoice_number'] ?? '');
                $vInvDate   = trim($_POST['vendor_invoice_date'] ?? '');
                $whtPct     = (float)($_POST['wht_pct'] ?? 0);
                $glAcct     = trim($_POST['gl_account_code'] ?? '');
                $costCtr    = trim($_POST['cost_center'] ?? '');
                $areaId     = (int)($_POST['area_id'] ?? 0);
                $branchId   = (int)($_POST['branch_id'] ?? 0);
                $isCapex    = !empty($_POST['is_capex']) ? 1 : 0;
                $depMonths  = (int)($_POST['depreciation_period_months'] ?? 0);
                $bankAcctId = (int)($_POST['bank_account_id'] ?? 0);
                $chqNum     = trim($_POST['cheque_number'] ?? '');
                $chqDate    = trim($_POST['cheque_date'] ?? '');

                // validate
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['success'=>false,'message'=>'Invalid date']); exit(); }
                if (!in_array($cat, $EXP_CATS, true))            { echo json_encode(['success'=>false,'message'=>'Invalid category']); exit(); }
                if ($desc === '')                                { echo json_encode(['success'=>false,'message'=>'Description required']); exit(); }
                if (mb_strlen($desc) > 255)                      { echo json_encode(['success'=>false,'message'=>'Description too long']); exit(); }
                if (mb_strlen($vendor) > 100)                    { echo json_encode(['success'=>false,'message'=>'Vendor name too long']); exit(); }
                if (mb_strlen($sub) > 50)                        { echo json_encode(['success'=>false,'message'=>'Subcategory too long']); exit(); }
                if (mb_strlen($refNum) > 100)                    { echo json_encode(['success'=>false,'message'=>'Reference too long']); exit(); }
                if ($amt <= 0)                                   { echo json_encode(['success'=>false,'message'=>'Amount must be > 0']); exit(); }
                if ($tax < 0)                                    { echo json_encode(['success'=>false,'message'=>'Tax cannot be negative']); exit(); }
                if (!in_array($pm, $PAY_METHODS, true))          { echo json_encode(['success'=>false,'message'=>'Invalid payment method']); exit(); }
                if ($paidBy <= 0)                                { echo json_encode(['success'=>false,'message'=>'Paid By required']); exit(); }
                if ($pm === 'cash') $refNum = ''; // no ref for cash
                if ($isRec) {
                    if (!in_array($recPer, $REC_PERIODS, true)) { echo json_encode(['success'=>false,'message'=>'Invalid recurrence period']); exit(); }
                } else {
                    $recPer = '';
                }
                $vendorBind = $vendor === '' ? null : $vendor;
                $subBind    = $sub    === '' ? null : $sub;
                $refBind    = $refNum === '' ? null : $refNum;
                $recPerBind = $recPer === '' ? null : $recPer;

                // accounting validations
                if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate))   { echo json_encode(['success'=>false,'message'=>'Invalid due date']); exit(); }
                if ($paidDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidDate)) { echo json_encode(['success'=>false,'message'=>'Invalid paid date']); exit(); }
                if ($vInvDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vInvDate)) { echo json_encode(['success'=>false,'message'=>'Invalid vendor invoice date']); exit(); }
                if ($chqDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $chqDate))   { echo json_encode(['success'=>false,'message'=>'Invalid cheque date']); exit(); }
                if ($paidAmt < 0)  { echo json_encode(['success'=>false,'message'=>'Paid amount cannot be negative']); exit(); }
                if ($paidAmt > $amt) $paidAmt = $amt; // cap at amount
                if ($whtPct < 0 || $whtPct > 100) { echo json_encode(['success'=>false,'message'=>'WHT % must be 0-100']); exit(); }
                if (mb_strlen($expNum) > 30)   { echo json_encode(['success'=>false,'message'=>'Expense # too long']); exit(); }
                if (mb_strlen($vInvNum) > 100) { echo json_encode(['success'=>false,'message'=>'Vendor invoice # too long']); exit(); }
                if (mb_strlen($glAcct) > 30)   { echo json_encode(['success'=>false,'message'=>'GL code too long']); exit(); }
                if (mb_strlen($costCtr) > 50)  { echo json_encode(['success'=>false,'message'=>'Cost center too long']); exit(); }
                if (mb_strlen($chqNum) > 50)   { echo json_encode(['success'=>false,'message'=>'Cheque # too long']); exit(); }

                // compute derived
                $whtAmt    = round(($amt * $whtPct) / 100, 2);
                $netPaid   = max(0, $paidAmt - $whtAmt);
                [$payStatus, $outstanding] = deriveExpensePayStatus($amt, $paidAmt, $dueDate ?: null);

                // cheque required only when method=cheque
                if ($pm !== 'cheque') { $chqNum = ''; $chqDate = ''; }

                $expNumBind   = $expNum   === '' ? null : $expNum;
                $dueBind      = $dueDate  === '' ? null : $dueDate;
                $paidDateBind = $paidDate === '' ? null : $paidDate;
                $vendorIdBind = $vendorId > 0 ? $vendorId : null;
                $vInvNumBind  = $vInvNum  === '' ? null : $vInvNum;
                $vInvDateBind = $vInvDate === '' ? null : $vInvDate;
                $glAcctBind   = $glAcct   === '' ? null : $glAcct;
                $costCtrBind  = $costCtr  === '' ? null : $costCtr;
                $areaIdBind   = $areaId > 0 ? $areaId : null;
                $branchIdBind = $branchId > 0 ? $branchId : null;
                $bankAcctBind = $bankAcctId > 0 ? $bankAcctId : null;
                $depMonthsBind = $depMonths > 0 ? $depMonths : null;
                $chqNumBind   = $chqNum   === '' ? null : $chqNum;
                $chqDateBind  = $chqDate  === '' ? null : $chqDate;

                // next recurrence date for new recurring rows
                $nextRecDate = $isRec ? bumpRecurrence($date, $recPer) : null;

                // verify paid_by is admin-level / accountant
                $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND is_deleted = 0 AND status='active' AND " . roleInSql('users', 'expenses', 'v'));
                $chk->bind_param("i", $paidBy);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $chk->close();
                    echo json_encode(['success'=>false,'message'=>'Invalid Paid By staff']); exit();
                }
                $chk->close();

                if (!$isEdit) {
                    // approval state: admin-level auto-approve, accountant pending
                    $newStatus = $is_admin_level ? 'approved' : 'pending';
                    $approverId = $is_admin_level ? $user_id : null;
                    $approvedAt = $is_admin_level ? date('Y-m-d H:i:s') : null;

                    // auto-gen expense_number if blank
                    if ($expNumBind === null) $expNumBind = nextExpenseNumber($conn);

                    // INSERT — 38 cols
                    $stmt = $conn->prepare("INSERT INTO expenses
                        (expense_date, category, subcategory, description, vendor_name, amount, tax_amount,
                         payment_method, reference_number, paid_by, notes, status, approved_by_user_id, approved_at,
                         is_recurring, recurrence_period,
                         expense_number, due_date, paid_date, payment_status, paid_amount, outstanding_amount,
                         vendor_id, vendor_invoice_number, vendor_invoice_date,
                         withholding_tax_amount, wht_pct, net_paid_amount,
                         gl_account_code, cost_center, area_id, branch_id,
                         is_capex, depreciation_period_months,
                         bank_account_id, cheque_number, cheque_date, next_recurrence_date)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    // 38 type letters
                    // 1-7:   s s s s s d d        (date,cat,sub,desc,vendor,amt,tax)
                    // 8-14:  s s i s s i s        (pm,ref,paidBy,notes,status,approverId,approvedAt)
                    // 15-16: i s                  (isRec,recPer)
                    // 17-22: s s s s d d          (expNum,due,paidDate,payStatus,paidAmt,outstanding)
                    // 23-25: i s s                (vendorId,vInvNum,vInvDate)
                    // 26-28: d d d                (whtAmt,whtPct,netPaid)
                    // 29-32: s s i i              (glAcct,costCtr,areaId,branchId)
                    // 33-34: i i                  (isCapex,depMonths)
                    // 35-38: i s s s              (bankAcctId,chqNum,chqDate,nextRecDate)
                    // total = 'sssssdd' . 'ssissis' . 'is' . 'ssssdd' . 'iss' . 'ddd' . 'ssii' . 'ii' . 'isss' = 38
                    $types = "sssssdd" . "ssissis" . "is" . "ssssdd" . "iss" . "ddd" . "ssii" . "ii" . "isss";
                    $stmt->bind_param($types,
                        $date, $cat, $subBind, $desc, $vendorBind, $amt, $tax,
                        $pm, $refBind, $paidBy, $notes, $newStatus, $approverId, $approvedAt,
                        $isRec, $recPerBind,
                        $expNumBind, $dueBind, $paidDateBind, $payStatus, $paidAmt, $outstanding,
                        $vendorIdBind, $vInvNumBind, $vInvDateBind,
                        $whtAmt, $whtPct, $netPaid,
                        $glAcctBind, $costCtrBind, $areaIdBind, $branchIdBind,
                        $isCapex, $depMonthsBind,
                        $bankAcctBind, $chqNumBind, $chqDateBind, $nextRecDate);
                    if (!$stmt->execute()) {
                        $err = $stmt->error; $stmt->close();
                        echo json_encode(['success'=>false,'message'=>'Failed to add: '.$err]); exit();
                    }
                    $newId = $stmt->insert_id;
                    $stmt->close();

                    // upload receipt if provided
                    $rcptPath = '';
                    if (!empty($_FILES['receipt_image']) && ($_FILES['receipt_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $rcptPath = handleReceiptUpload($_FILES['receipt_image'], $newId);
                        if ($rcptPath !== '') {
                            $up = $conn->prepare("UPDATE expenses SET receipt_image = ? WHERE id = ?");
                            $up->bind_param("si", $rcptPath, $newId);
                            $up->execute();
                            $up->close();
                        }
                    }
                    // upload vendor invoice if provided
                    if (!empty($_FILES['vendor_invoice_path']) && ($_FILES['vendor_invoice_path']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $vp = handleVendorInvoiceUpload($_FILES['vendor_invoice_path'], $newId);
                        if ($vp !== '') {
                            $up = $conn->prepare("UPDATE expenses SET vendor_invoice_path = ? WHERE id = ?");
                            $up->bind_param("si", $vp, $newId);
                            $up->execute();
                            $up->close();
                        }
                    }

                    syncExpenseLedger($conn, $newId);
                    logActivity($user_id, $username, 'Expense Created', "Added expense [{$cat}] {$currency}".number_format($amt,2)." — $desc (status: $newStatus)");
                    $notifMsg = "{$cat}: {$currency}".number_format($amt,2)." — $desc";
                    if ($newStatus === 'pending') $notifMsg .= ' (awaiting approval)';
                    createNotificationForAdmins('Expense Logged', $notifMsg, 'info', 'expenses.php');
                    echo json_encode(['success'=>true,'message'=>'Expense added successfully','id'=>$newId,'status'=>$newStatus]);
                    exit();
                }

                // UPDATE
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid expense id']); exit(); }

                // fetch current to know existing files + expense_number
                $cur = $conn->prepare("SELECT receipt_image, vendor_invoice_path, expense_number FROM expenses WHERE id = ? AND is_deleted = 0");
                $cur->bind_param("i", $id);
                $cur->execute();
                $curRow = $cur->get_result()->fetch_assoc();
                $cur->close();
                if (!$curRow) { echo json_encode(['success'=>false,'message'=>'Expense not found']); exit(); }
                $existingRcpt = $curRow['receipt_image'] ?? '';
                $existingVInv = $curRow['vendor_invoice_path'] ?? '';

                // expense_number — only super_admin can edit; others keep current; auto-gen if missing
                if (!isSuperAdmin() || $expNumBind === null) {
                    $expNumBind = !empty($curRow['expense_number']) ? $curRow['expense_number'] : nextExpenseNumber($conn);
                }

                $newRcpt = $existingRcpt; // default keep
                $hasNewFile = !empty($_FILES['receipt_image']) && ($_FILES['receipt_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

                if ($hasNewFile) {
                    $up = handleReceiptUpload($_FILES['receipt_image'], $id);
                    if ($up !== '') {
                        if ($existingRcpt && file_exists(__DIR__.'/'.$existingRcpt)) @unlink(__DIR__.'/'.$existingRcpt);
                        $newRcpt = $up;
                    }
                } elseif ($remRec) {
                    if ($existingRcpt && file_exists(__DIR__.'/'.$existingRcpt)) @unlink(__DIR__.'/'.$existingRcpt);
                    $newRcpt = '';
                }

                // vendor invoice file
                $newVInv = $existingVInv;
                $hasNewVInv = !empty($_FILES['vendor_invoice_path']) && ($_FILES['vendor_invoice_path']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                if ($hasNewVInv) {
                    $up = handleVendorInvoiceUpload($_FILES['vendor_invoice_path'], $id);
                    if ($up !== '') {
                        if ($existingVInv && file_exists(__DIR__.'/'.$existingVInv)) @unlink(__DIR__.'/'.$existingVInv);
                        $newVInv = $up;
                    }
                } elseif ($remVInv) {
                    if ($existingVInv && file_exists(__DIR__.'/'.$existingVInv)) @unlink(__DIR__.'/'.$existingVInv);
                    $newVInv = '';
                }

                $rcptForBind = $newRcpt === '' ? null : $newRcpt;
                $vInvForBind = $newVInv === '' ? null : $newVInv;

                // approval fields untouched here
                $stmt = $conn->prepare("UPDATE expenses SET
                    expense_date=?, category=?, subcategory=?, description=?, vendor_name=?,
                    amount=?, tax_amount=?, payment_method=?, reference_number=?,
                    paid_by=?, notes=?, receipt_image=?, is_recurring=?, recurrence_period=?,
                    expense_number=?, due_date=?, paid_date=?, payment_status=?, paid_amount=?, outstanding_amount=?,
                    vendor_id=?, vendor_invoice_number=?, vendor_invoice_date=?, vendor_invoice_path=?,
                    withholding_tax_amount=?, wht_pct=?, net_paid_amount=?,
                    gl_account_code=?, cost_center=?, area_id=?, branch_id=?,
                    is_capex=?, depreciation_period_months=?,
                    bank_account_id=?, cheque_number=?, cheque_date=?
                    WHERE id=? AND is_deleted = 0");
                // type letter count = 36 (35 SET cols + 1 WHERE)
                // 1-14:  s s s s s d d s s i s s i s     (existing 14)
                // 15-20: s s s s d d                     (expNum,due,paidDate,payStatus,paidAmt,outstanding)
                // 21-24: i s s s                         (vendorId,vInvNum,vInvDate,vInvPath)
                // 25-27: d d d                           (whtAmt,whtPct,netPaid)
                // 28-31: s s i i                         (glAcct,costCtr,areaId,branchId)
                // 32-33: i i                             (isCapex,depMonths)
                // 34-36: i s s                           (bankAcct,chqNum,chqDate)
                // 37: i                                  (id)
                $types = "sssssddssissis" . "ssssdd" . "isss" . "ddd" . "ssii" . "ii" . "iss" . "i";
                $stmt->bind_param($types,
                    $date, $cat, $subBind, $desc, $vendorBind,
                    $amt, $tax, $pm, $refBind,
                    $paidBy, $notes, $rcptForBind, $isRec, $recPerBind,
                    $expNumBind, $dueBind, $paidDateBind, $payStatus, $paidAmt, $outstanding,
                    $vendorIdBind, $vInvNumBind, $vInvDateBind, $vInvForBind,
                    $whtAmt, $whtPct, $netPaid,
                    $glAcctBind, $costCtrBind, $areaIdBind, $branchIdBind,
                    $isCapex, $depMonthsBind,
                    $bankAcctBind, $chqNumBind, $chqDateBind,
                    $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    syncExpenseLedger($conn, $id);
                    logActivity($user_id, $username, 'Expense Updated', "Updated expense #$id [{$cat}] — $desc");
                    echo json_encode(['success'=>true,'message'=>'Expense updated successfully']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed to update: '.$err]);
                }
                exit();
            }

            // ─── approve (admin-level only) ───────────────────────────
            case 'approveExpense': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!$is_admin_level) { echo json_encode(['success'=>false,'message'=>'Only admin-level can approve']); exit(); }
                $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                $cur = $conn->prepare("SELECT status, category, amount, description FROM expenses WHERE id = ? AND is_deleted = 0");
                $cur->bind_param("i", $id);
                $cur->execute();
                $row = $cur->get_result()->fetch_assoc();
                $cur->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Expense not found']); exit(); }
                if ($row['status'] === 'approved') { echo json_encode(['success'=>false,'message'=>'Already approved']); exit(); }

                $stmt = $conn->prepare("UPDATE expenses SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=? AND is_deleted = 0");
                $stmt->bind_param("ii", $user_id, $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Expense Approved', "Approved expense #$id [{$row['category']}] {$currency}".number_format((float)$row['amount'],2)." — {$row['description']}");
                    echo json_encode(['success'=>true,'message'=>'Expense approved']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                }
                exit();
            }

            // ─── reject (admin-level only) ────────────────────────────
            case 'rejectExpense': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!$is_admin_level) { echo json_encode(['success'=>false,'message'=>'Only admin-level can reject']); exit(); }
                $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
                $reason = trim($_POST['reject_reason'] ?? '');
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                if ($reason === '') { echo json_encode(['success'=>false,'message'=>'Reason required']); exit(); }
                if (mb_strlen($reason) > 500) { echo json_encode(['success'=>false,'message'=>'Reason too long']); exit(); }

                $cur = $conn->prepare("SELECT status, notes, category, amount, description FROM expenses WHERE id = ? AND is_deleted = 0");
                $cur->bind_param("i", $id);
                $cur->execute();
                $row = $cur->get_result()->fetch_assoc();
                $cur->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Expense not found']); exit(); }
                if ($row['status'] === 'rejected') { echo json_encode(['success'=>false,'message'=>'Already rejected']); exit(); }

                $existing = trim($row['notes'] ?? '');
                $marker = "[REJECTED: $reason]";
                $newNotes = $existing === '' ? $marker : ($existing . "\n" . $marker);

                $stmt = $conn->prepare("UPDATE expenses SET status='rejected', notes=? WHERE id=? AND is_deleted = 0");
                $stmt->bind_param("si", $newNotes, $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Expense Rejected', "Rejected expense #$id [{$row['category']}] {$currency}".number_format((float)$row['amount'],2)." — {$row['description']} (reason: $reason)");
                    echo json_encode(['success'=>true,'message'=>'Expense rejected']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                }
                exit();
            }

            // ─── delete (super_admin only) ────────────────────────────
            case 'deleteExpense': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!can('expenses', 'd')) { echo json_encode(['success'=>false,'message'=>'Only super admin can delete expenses']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                $stmt = $conn->prepare("SELECT description, category, amount FROM expenses WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Expense not found']); exit(); }

                $stmt = $conn->prepare("UPDATE expenses SET is_deleted = 1 WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    // soft-delete linked payments for consistency
                    $dp = $conn->prepare("UPDATE expense_payments SET is_deleted = 1 WHERE expense_id = ? AND is_deleted = 0");
                    $dp->bind_param("i", $id);
                    $dp->execute();
                    $dp->close();
                    syncExpenseLedger($conn, $id);
                    logActivity($user_id, $username, 'Expense Deleted', "Deleted expense [{$row['category']}] {$currency}".number_format((float)$row['amount'],2)." — {$row['description']}");
                    echo json_encode(['success'=>true,'message'=>'Expense deleted successfully']);
                } else {
                    $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed to delete']);
                }
                exit();
            }

            // ─── record payment (inserts expense_payments row) ─────────
            case 'recordPayment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                $addAmt = (float)($_POST['amount'] ?? 0);
                $pDate = trim($_POST['date'] ?? '');
                $pm2 = $_POST['method'] ?? '';
                $bankId = (int)($_POST['bank_account_id'] ?? 0);
                $chqN = trim($_POST['cheque_number'] ?? '');
                $payNotes = trim($_POST['notes'] ?? '');
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                if ($addAmt <= 0) { echo json_encode(['success'=>false,'message'=>'Amount must be > 0']); exit(); }
                if ($pDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pDate)) { echo json_encode(['success'=>false,'message'=>'Invalid date']); exit(); }
                if ($pm2 !== '' && !in_array($pm2, $PAY_METHODS, true)) { echo json_encode(['success'=>false,'message'=>'Invalid method']); exit(); }
                if (mb_strlen($chqN) > 100) { echo json_encode(['success'=>false,'message'=>'Reference too long']); exit(); }

                $cur = $conn->prepare("SELECT amount, paid_amount, outstanding_amount, expense_number FROM expenses WHERE id=? AND is_deleted=0");
                $cur->bind_param("i", $id);
                $cur->execute();
                $cR = $cur->get_result()->fetch_assoc();
                $cur->close();
                if (!$cR) { echo json_encode(['success'=>false,'message'=>'Expense not found']); exit(); }
                $amount = (float)$cR['amount'];
                $outNow = (float)$cR['outstanding_amount'];
                // reject overpayment — beyond current outstanding (tiny epsilon for rounding)
                if ($addAmt > $outNow + 0.009) {
                    echo json_encode(['success'=>false,'message'=>'Amount exceeds outstanding ('.$currency.number_format($outNow,2).')']); exit();
                }

                $pDateBind = $pDate === '' ? date('Y-m-d') : $pDate;
                $pmBind    = $pm2 === '' ? 'cash' : $pm2; // expense_payments needs a method
                $refBind   = $chqN === '' ? null : $chqN; // cheque # / ref
                $notesBind = $payNotes === '' ? null : $payNotes;

                // insert payment row — cols: expense_id,amount,payment_date,payment_method,reference,notes,created_by_user_id
                $ins = $conn->prepare("INSERT INTO expense_payments
                    (expense_id, amount, payment_date, payment_method, reference, notes, created_by_user_id)
                    VALUES (?,?,?,?,?,?,?)");
                $ins->bind_param("idssssi", $id, $addAmt, $pDateBind, $pmBind, $refBind, $notesBind, $user_id);
                if (!$ins->execute()) {
                    $err = $ins->error; $ins->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]); exit();
                }
                $newPayRowId = $conn->insert_id;
                $ins->close();

                // keep bank account on expense if supplied
                if ($bankId > 0) {
                    $bu = $conn->prepare("UPDATE expenses SET bank_account_id=? WHERE id=? AND is_deleted=0");
                    $bu->bind_param("ii", $bankId, $id);
                    $bu->execute();
                    $bu->close();
                }

                $rc = recomputeExpenseFromPayments($conn, $id);
                $ps  = $rc ? $rc['status'] : 'partial';
                $out = $rc ? $rc['outstanding'] : 0;
                $paidTot = $rc ? $rc['paid'] : 0;
                syncExpensePaymentLedger($conn, $newPayRowId);
                logActivity($user_id, $username, 'Expense Payment Added', "#{$id} ({$cR['expense_number']}) +{$currency}".number_format($addAmt,2)." total={$currency}".number_format($paidTot,2)." status=$ps");
                echo json_encode(['success'=>true,'message'=>'Payment added','status'=>$ps,'paid'=>$paidTot,'outstanding'=>$out]);
                exit();
            }

            // ─── payment history for an expense ───────────────────────
            case 'getExpensePayments': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $stmt = $conn->prepare("SELECT p.id, p.amount, p.payment_date, p.payment_method, p.reference, p.notes,
                                               p.created_at, p.created_by_user_id,
                                               COALESCE(u.name, u.username, '—') AS created_by_name
                                        FROM expense_payments p
                                        LEFT JOIN users u ON u.id = p.created_by_user_id
                                        WHERE p.expense_id = ? AND p.is_deleted = 0
                                        ORDER BY p.payment_date ASC, p.id ASC");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = []; $total = 0;
                while ($r = $res->fetch_assoc()) {
                    $total += (float)$r['amount'];
                    $rows[] = [
                        'id'             => (int)$r['id'],
                        'amount'         => number_format((float)$r['amount'], 2, '.', ''),
                        'amount_display' => $currency . number_format((float)$r['amount'], 2),
                        'payment_date'   => $r['payment_date'],
                        'payment_method' => $r['payment_method'] ?? 'cash',
                        'reference'      => $r['reference'] ?? '',
                        'notes'          => $r['notes'] ?? '',
                        'created_at'     => $r['created_at'] ? date('c', strtotime($r['created_at'])) : '',
                        'created_by_name'=> $r['created_by_name']
                    ];
                }
                $stmt->close();
                echo json_encode(['success'=>true,'data'=>$rows,'total_paid'=>$total,'currency'=>$currency,'can_delete'=>$can_delete]);
                exit();
            }

            // ─── delete a payment (soft) + recompute ──────────────────
            case 'deleteExpensePayment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!can('expenses', 'd')) { echo json_encode(['success'=>false,'message'=>'Only super admin can delete payments']); exit(); }
                $pid = (int)($_POST['payment_id'] ?? 0);
                if ($pid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid payment id']); exit(); }

                $cur = $conn->prepare("SELECT id, expense_id, amount, is_deleted FROM expense_payments WHERE id=?");
                $cur->bind_param("i", $pid);
                $cur->execute();
                $pR = $cur->get_result()->fetch_assoc();
                $cur->close();
                if (!$pR) { echo json_encode(['success'=>false,'message'=>'Payment not found']); exit(); }
                if ((int)$pR['is_deleted'] === 1) { echo json_encode(['success'=>false,'message'=>'Already deleted']); exit(); }
                $expId = (int)$pR['expense_id'];

                $del = $conn->prepare("UPDATE expense_payments SET is_deleted=1 WHERE id=?");
                $del->bind_param("i", $pid);
                if (!$del->execute()) {
                    $err = $del->error; $del->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]); exit();
                }
                $del->close();

                $rc = recomputeExpenseFromPayments($conn, $expId);
                syncExpensePaymentLedger($conn, $pid);
                logActivity($user_id, $username, 'Expense Payment Deleted', "payment #{$pid} ({$currency}".number_format((float)$pR['amount'],2).") from expense #{$expId}");
                echo json_encode([
                    'success'=>true, 'message'=>'Payment deleted',
                    'status'=> $rc ? $rc['status'] : '', 'paid'=> $rc ? $rc['paid'] : 0,
                    'outstanding'=> $rc ? $rc['outstanding'] : 0
                ]);
                exit();
            }

            // ─── generate recurring (cron-like, on-demand) ────────────
            case 'generateRecurring': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!$is_admin_level) { echo json_encode(['success'=>false,'message'=>'Only admin-level can fire recurring']); exit(); }

                // find due recurring sources
                $today = date('Y-m-d');
                $sql = "SELECT id, expense_date, category, subcategory, description, vendor_name, amount, tax_amount,
                               payment_method, paid_by, recurrence_period, next_recurrence_date,
                               vendor_id, gl_account_code, cost_center, area_id, branch_id,
                               wht_pct, is_capex, depreciation_period_months, due_date
                        FROM expenses
                        WHERE is_deleted=0 AND is_recurring=1
                          AND parent_recurring_expense_id IS NULL
                          AND next_recurrence_date IS NOT NULL
                          AND next_recurrence_date <= ?";
                $st = $conn->prepare($sql);
                $st->bind_param("s", $today);
                $st->execute();
                $res = $st->get_result();

                $created = 0; $skipped = 0;
                while ($r = $res->fetch_assoc()) {
                    $newDate   = $r['next_recurrence_date'];
                    $sourceId  = (int)$r['id'];
                    $period    = $r['recurrence_period'] ?: 'monthly';
                    $newExpNum = nextExpenseNumber($conn);
                    $newDue    = null;
                    if (!empty($r['due_date']) && !empty($r['expense_date'])) {
                        $diff = (strtotime($r['due_date']) - strtotime($r['expense_date'])) / 86400;
                        if ($diff >= 0) $newDue = date('Y-m-d', strtotime($newDate . " +{$diff} days"));
                    }
                    $whtPct2  = (float)($r['wht_pct'] ?? 0);
                    $whtAmt2  = round(((float)$r['amount'] * $whtPct2) / 100, 2);
                    $payStat2 = 'unpaid';
                    $outAmt2  = (float)$r['amount'];
                    $newRecD  = bumpRecurrence($newDate, $period);
                    $approverFlag = $is_admin_level ? $user_id : null;
                    $approvedAt2  = $is_admin_level ? date('Y-m-d H:i:s') : null;
                    $statusFlag   = 'approved';
                    $isRec2 = 0; // child rows are not recurring sources
                    $catVal   = $r['category'];
                    $subVal   = $r['subcategory'];
                    $descVal  = $r['description'];
                    $vendVal  = $r['vendor_name'];
                    $amtVal   = (float)$r['amount'];
                    $taxVal   = (float)$r['tax_amount'];
                    $pmVal    = $r['payment_method'];
                    $paidByV  = (int)$r['paid_by'];
                    $vendIdV  = $r['vendor_id'] !== null ? (int)$r['vendor_id'] : null;
                    $glV      = $r['gl_account_code'];
                    $ccV      = $r['cost_center'];
                    $arV      = $r['area_id'] !== null ? (int)$r['area_id'] : null;
                    $brV      = $r['branch_id'] !== null ? (int)$r['branch_id'] : null;
                    $capV     = (int)($r['is_capex'] ?? 0);
                    $depV     = $r['depreciation_period_months'] !== null ? (int)$r['depreciation_period_months'] : null;
                    $netPaid3 = 0;
                    $paidAmt3 = 0;

                    $ins = $conn->prepare("INSERT INTO expenses
                        (expense_date, category, subcategory, description, vendor_name, amount, tax_amount,
                         payment_method, reference_number, paid_by, notes, status, approved_by_user_id, approved_at,
                         is_recurring, recurrence_period,
                         expense_number, due_date, payment_status, paid_amount, outstanding_amount,
                         vendor_id, withholding_tax_amount, wht_pct, net_paid_amount,
                         gl_account_code, cost_center, area_id, branch_id,
                         is_capex, depreciation_period_months,
                         parent_recurring_expense_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $refBind3 = null; $notes3 = "Auto-generated from recurring expense #$sourceId";
                    // 32 placeholders: 7+7+2+5+4+4+2+1
                    $types2 = "sssssdd" . "ssissis" . "is" . "sssdd" . "iddd" . "ssii" . "ii" . "i";
                    $ins->bind_param($types2,
                        $newDate, $catVal, $subVal, $descVal, $vendVal, $amtVal, $taxVal,
                        $pmVal, $refBind3, $paidByV, $notes3, $statusFlag, $approverFlag, $approvedAt2,
                        $isRec2, $period,
                        $newExpNum, $newDue, $payStat2, $paidAmt3, $outAmt2,
                        $vendIdV, $whtAmt2, $whtPct2, $netPaid3,
                        $glV, $ccV, $arV, $brV,
                        $capV, $depV,
                        $sourceId);
                    if ($ins->execute()) {
                        $created++;
                        $ins->close();
                        // bump source next_recurrence_date
                        $bp = $conn->prepare("UPDATE expenses SET next_recurrence_date=? WHERE id=?");
                        $bp->bind_param("si", $newRecD, $sourceId);
                        $bp->execute();
                        $bp->close();
                    } else {
                        $skipped++;
                        $ins->close();
                    }
                }
                $st->close();
                logActivity($user_id, $username, 'Recurring Generated', "Created $created recurring expense entries (skipped $skipped)");
                echo json_encode(['success'=>true,'message'=>"Generated $created entries (skipped $skipped)",'created'=>$created,'skipped'=>$skipped]);
                exit();
            }

            default:
                echo json_encode(['success'=>false,'message'=>'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Expenses.php error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
        exit();
    }
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Expenses - ISP Billing</title>

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
                <span>Expenses</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-receipt"></i> Expenses</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- monthly summary cards -->
            <div class="summary-grid" id="summaryGrid">
                <div class="summary-card highlight">
                    <div class="summary-card-label"><i class="fas fa-calendar-day"></i> This Month Total</div>
                    <div class="summary-card-value" id="smThisTotal">—</div>
                    <div class="summary-card-sub" id="smThisLabel">&nbsp;</div>
                </div>
                <div class="summary-card">
                    <div class="summary-card-label"><i class="fas fa-list-ol"></i> This Month Count</div>
                    <div class="summary-card-value" id="smThisCount">—</div>
                    <div class="summary-card-sub">entries logged</div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-card-label"><i class="fas fa-fire"></i> Top Category</div>
                    <div class="summary-card-value" id="smTopCat">—</div>
                    <div class="summary-card-sub" id="smTopAmt">&nbsp;</div>
                </div>
                <div class="summary-card success">
                    <div class="summary-card-label"><i class="fas fa-history"></i> Last Month Total</div>
                    <div class="summary-card-value" id="smLastTotal">—</div>
                    <div class="summary-card-sub" id="smLastLabel">&nbsp;</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-card-label"><i class="fas fa-percent"></i> Tax Paid This Month</div>
                    <div class="summary-card-value" id="smThisTax">—</div>
                    <div class="summary-card-sub">total tax/GST</div>
                </div>
            </div>

            <!-- category breakdown strip -->
            <div class="summary-grid" id="catBreakdown"></div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-money-bill-trend-up"></i> Expense Log</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadAll()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if ($is_admin_level): ?>
                        <button class="btn btn-warning" onclick="generateRecurring()" title="Auto-generate recurring expenses for today">
                            <i class="fas fa-redo-alt"></i> Generate Recurring (today)
                        </button>
                        <?php endif; ?>
                        <?php if (can('expenses', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Expense
                        </button>
                    </div>
                </div>

                <div class="filters-section initially-hidden" id="filtersSection">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-times-circle"></i> Clear All
                        </button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Expense Date Range</label>
                            <div class="filter-dates">
                                <input type="date" id="filterFrom" title="From">
                                <input type="date" id="filterTo" title="To">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-tags"></i> Category</label>
                            <select id="filterCategory">
                                <option value="">All Categories</option>
                                <?php foreach ($EXP_CATS as $c): ?>
                                    <option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-user"></i> Paid By</label>
                            <select id="filterPaidBy">
                                <option value="">All Staff</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-credit-card"></i> Method</label>
                            <select id="filterMethod">
                                <option value="">All Methods</option>
                                <?php foreach ($PAY_METHODS as $m): ?>
                                    <option value="<?php echo $m; ?>"><?php echo ucfirst(str_replace('_',' ',$m)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-flag"></i> Status</label>
                            <select id="filterStatus">
                                <option value="">All Statuses</option>
                                <?php foreach ($EXP_STATUSES as $s): ?>
                                    <option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-redo"></i> Recurring Only</label>
                            <label class="status-toggle" style="margin-top:6px">
                                <input type="checkbox" id="filterRecurring">
                                <span class="track"></span>
                                <span id="filterRecurringLabel">Off</span>
                            </label>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" id="filterSearch" placeholder="Description / vendor / ref...">
                        </div>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap">
                    <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                </div>

                <!-- table -->
                <div id="tableView" class="initially-hidden">
                    <div class="table-responsive">
                        <table id="expensesTable" class="display table-full-width"></table>
                    </div>
                </div>

                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-receipt"></i></div>
                    <div class="empty-state-title">No expenses logged this month.</div>
                    <div class="empty-state-text">Click Add Expense to record your first transaction.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Expense Modal (add / edit) -->
    <div class="modal-overlay" id="expenseModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Expense</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="expenseForm" enctype="multipart/form-data">
                    <input type="hidden" id="expenseId" name="id">
                    <input type="hidden" id="removeReceipt" name="remove_receipt" value="">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date *</label>
                            <input type="date" id="expense_date" name="expense_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tags"></i> Category *</label>
                            <select id="category" name="category" required>
                                <?php foreach ($EXP_CATS as $c): ?>
                                    <option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Subcategory</label>
                            <input type="text" id="subcategory" name="subcategory" maxlength="50" placeholder="Optional sub-tag">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-store"></i> Vendor</label>
                            <input type="text" id="vendor_name" name="vendor_name" maxlength="100" placeholder="Vendor / supplier name">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-align-left"></i> Description *</label>
                            <input type="text" id="description" name="description" maxlength="255" required placeholder="What was this expense for?">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Amount (<?php echo htmlspecialchars($currency); ?>) *</label>
                            <input type="number" id="amount" name="amount" step="0.01" min="0.01" required placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Tax Amount (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="tax_amount" name="tax_amount" step="0.01" min="0" value="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Payment Method *</label>
                            <select id="payment_method" name="payment_method" required>
                                <?php foreach ($PAY_METHODS as $m): ?>
                                    <option value="<?php echo $m; ?>"<?php echo $m === 'cash' ? ' selected' : ''; ?>><?php echo ucfirst(str_replace('_',' ',$m)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group initially-hidden" id="refNumWrap">
                            <label><i class="fas fa-hashtag"></i> Reference Number</label>
                            <input type="text" id="reference_number" name="reference_number" maxlength="100" placeholder="Txn / cheque #">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Paid By *</label>
                            <select id="paid_by" name="paid_by" required>
                                <option value="">Select staff...</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-redo"></i> Recurring?</label>
                            <label class="status-toggle" style="margin-top:6px">
                                <input type="checkbox" id="is_recurring" name="is_recurring" value="1">
                                <span class="track"></span>
                                <span id="isRecurringLabel">No</span>
                            </label>
                        </div>
                        <div class="form-group initially-hidden" id="recPerWrap">
                            <label><i class="fas fa-calendar-alt"></i> Recurrence Period</label>
                            <select id="recurrence_period" name="recurrence_period">
                                <option value="">Select period...</option>
                                <?php foreach ($REC_PERIODS as $p): ?>
                                    <option value="<?php echo $p; ?>"><?php echo ucfirst($p); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-paperclip"></i> Receipt (jpg/png/webp/pdf, max 5MB)</label>
                            <input type="file" id="receipt_image" name="receipt_image" accept="image/*,.pdf">
                            <div id="existingRcptWrap" class="initially-hidden" style="margin-top:8px">
                                <a id="existingRcptLink" href="#" target="_blank" rel="noopener"><i class="fas fa-file-alt"></i> View current receipt</a>
                                &nbsp;
                                <button type="button" class="btn btn-secondary btn-sm" onclick="markRemoveReceipt()"><i class="fas fa-times"></i> Remove</button>
                            </div>
                        </div>

                        <!-- ── accounting / vendor / payment block ── -->
                        <div class="form-group" style="grid-column:1/-1; border-top:1px solid #e5e7eb; padding-top:10px; margin-top:6px">
                            <strong><i class="fas fa-calculator"></i> Accounting / Vendor / Payment</strong>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Expense #</label>
                            <input type="text" id="expense_number" name="expense_number" maxlength="30" placeholder="Auto: EXP-YYYY-NNNN" <?php echo isSuperAdmin() ? '' : 'readonly'; ?>>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Due Date</label>
                            <input type="date" id="due_date" name="due_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-check"></i> Paid Amount (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="paid_amount" name="paid_amount" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Paid Date</label>
                            <input type="date" id="paid_date" name="paid_date">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Vendor (Master)</label>
                            <select id="vendor_id" name="vendor_id">
                                <option value="">— None —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-invoice"></i> Vendor Invoice #</label>
                            <input type="text" id="vendor_invoice_number" name="vendor_invoice_number" maxlength="100" placeholder="Bill # from vendor">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Vendor Invoice Date</label>
                            <input type="date" id="vendor_invoice_date" name="vendor_invoice_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> WHT %</label>
                            <input type="number" id="wht_pct" name="wht_pct" step="0.01" min="0" max="100" value="0" placeholder="e.g. 4.5">
                        </div>

                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-paperclip"></i> Vendor Invoice File (jpg/png/webp/pdf, max 5MB)</label>
                            <input type="file" id="vendor_invoice_file" name="vendor_invoice_path" accept="image/*,.pdf">
                            <input type="hidden" id="removeVendorInvoice" name="remove_vendor_invoice" value="">
                            <div id="existingVInvWrap" class="initially-hidden" style="margin-top:8px">
                                <a id="existingVInvLink" href="#" target="_blank" rel="noopener"><i class="fas fa-file-alt"></i> View current vendor invoice</a>
                                &nbsp;
                                <button type="button" class="btn btn-secondary btn-sm" onclick="markRemoveVendorInvoice()"><i class="fas fa-times"></i> Remove</button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-book"></i> GL Account Code</label>
                            <input type="text" id="gl_account_code" name="gl_account_code" maxlength="30" placeholder="e.g. 6100-OPEX">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Cost Center</label>
                            <input type="text" id="cost_center" name="cost_center" maxlength="50" placeholder="Department / unit">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marked-alt"></i> Area</label>
                            <select id="area_id" name="area_id">
                                <option value="">— Any —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Branch ID</label>
                            <input type="number" id="branch_id" name="branch_id" min="0" placeholder="Branch #">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-industry"></i> CapEx?</label>
                            <label class="status-toggle" style="margin-top:6px">
                                <input type="checkbox" id="is_capex" name="is_capex" value="1">
                                <span class="track"></span>
                                <span id="isCapexLabel">No</span>
                            </label>
                        </div>
                        <div class="form-group initially-hidden" id="depWrap">
                            <label><i class="fas fa-stopwatch"></i> Depreciation Period (months)</label>
                            <input type="number" id="depreciation_period_months" name="depreciation_period_months" min="0" step="1" placeholder="e.g. 36">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Account (Paid From)</label>
                            <select id="bank_account_id" name="bank_account_id">
                                <option value="">— None —</option>
                            </select>
                        </div>
                        <div class="form-group initially-hidden" id="chqWrap">
                            <label><i class="fas fa-money-check-alt"></i> Cheque #</label>
                            <input type="text" id="cheque_number" name="cheque_number" maxlength="50" placeholder="Cheque number">
                        </div>
                        <div class="form-group initially-hidden" id="chqDateWrap">
                            <label><i class="fas fa-calendar-day"></i> Cheque Date</label>
                            <input type="date" id="cheque_date" name="cheque_date">
                        </div>

                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-sticky-note"></i> Notes</label>
                            <textarea id="notes" name="notes" rows="3" placeholder="Optional details..."></textarea>
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

    <!-- View Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Expense Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <!-- Record Payment Modal -->
    <div class="modal-overlay" id="payModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="payModalTitle"><i class="fas fa-money-check"></i> Record Payment</h3>
                <button class="close-btn" onclick="closePayModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="payForm">
                    <input type="hidden" id="payExpenseId">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-info-circle"></i> Outstanding</label>
                            <div id="payOutstanding" style="font-weight:600">—</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Amount *</label>
                            <input type="number" id="payAmount" step="0.01" min="0.01" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date *</label>
                            <input type="date" id="payDate" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Method</label>
                            <select id="payMethod">
                                <option value="">— Keep current —</option>
                                <?php foreach ($PAY_METHODS as $m): ?>
                                    <option value="<?php echo $m; ?>"><?php echo ucfirst(str_replace('_',' ',$m)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Account</label>
                            <select id="payBankAccount">
                                <option value="">— None —</option>
                            </select>
                        </div>
                        <div class="form-group initially-hidden" id="payChqWrap" style="grid-column:1/-1">
                            <label><i class="fas fa-money-check-alt"></i> Cheque #</label>
                            <input type="text" id="payCheque" maxlength="50">
                        </div>
                        <div class="form-group" id="payNotesWrap" style="grid-column:1/-1">
                            <label><i class="fas fa-sticky-note"></i> Notes</label>
                            <input type="text" id="payNotes" maxlength="255" placeholder="Optional note for this payment">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Payment</button>
                        <button type="button" class="btn btn-secondary" onclick="closePayModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/pdfmake.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/vfs_fonts.js"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>

    <script>
    const CAN_DELETE = <?php echo $can_delete ? 'true' : 'false'; ?>;
    const IS_ADMIN_LEVEL = <?php echo $is_admin_level ? 'true' : 'false'; ?>;
    const CURRENCY   = <?php echo json_encode($currency); ?>;
    const SESSION_USER_ID = <?php echo (int)$user_id; ?>;
    const EXP_CATS   = <?php echo json_encode($EXP_CATS); ?>;
    const PAY_METHODS = <?php echo json_encode($PAY_METHODS); ?>;
    const REC_PERIODS = <?php echo json_encode($REC_PERIODS); ?>;

    let expensesTable;
    let isEditMode = false;
    let staffCache = [];

    $(document).ready(function() {
        // sd init — form
        new SearchableDropdown({ selectId: 'category',          placeholder: 'Select category...', icon: 'fas fa-tags', allowClear: false });
        new SearchableDropdown({ selectId: 'paid_by',           placeholder: 'Select staff...',    icon: 'fas fa-user' });
        new SearchableDropdown({ selectId: 'payment_method',    placeholder: 'Select method...',   icon: 'fas fa-credit-card', allowClear: false });
        new SearchableDropdown({ selectId: 'recurrence_period', placeholder: 'Select period...',   icon: 'fas fa-calendar-alt' });
        new SearchableDropdown({ selectId: 'vendor_id',         placeholder: 'Select vendor...',   icon: 'fas fa-user-tie' });
        new SearchableDropdown({ selectId: 'area_id',           placeholder: 'Select area...',     icon: 'fas fa-map-marked-alt' });
        new SearchableDropdown({ selectId: 'bank_account_id',   placeholder: 'Select bank a/c...', icon: 'fas fa-university' });
        new SearchableDropdown({ selectId: 'payMethod',         placeholder: 'Keep current',       icon: 'fas fa-credit-card' });
        new SearchableDropdown({ selectId: 'payBankAccount',    placeholder: 'Select bank a/c...', icon: 'fas fa-university' });
        // sd init — filters
        new SearchableDropdown({ selectId: 'filterCategory', placeholder: 'All Categories', icon: 'fas fa-tags' });
        new SearchableDropdown({ selectId: 'filterPaidBy',   placeholder: 'All Staff',      icon: 'fas fa-user' });
        new SearchableDropdown({ selectId: 'filterMethod',   placeholder: 'All Methods',    icon: 'fas fa-credit-card' });
        new SearchableDropdown({ selectId: 'filterStatus',   placeholder: 'All Statuses',   icon: 'fas fa-flag' });

        // default date today
        document.getElementById('expense_date').value = new Date().toISOString().slice(0,10);

        // method change → ref visibility + cheque visibility
        document.getElementById('payment_method').addEventListener('change', function() {
            toggleRefVisibility();
            toggleChequeVisibility();
        });
        document.getElementById('payMethod').addEventListener('change', function() {
            const v = this.value;
            const w = document.getElementById('payChqWrap');
            if (v === 'cheque') { w.classList.remove('initially-hidden'); w.style.display = ''; }
            else { w.classList.add('initially-hidden'); w.style.display = 'none'; }
        });
        // recurring toggle
        document.getElementById('is_recurring').addEventListener('change', function() {
            document.getElementById('isRecurringLabel').textContent = this.checked ? 'Yes' : 'No';
            toggleRecurrenceVisibility();
        });
        document.getElementById('filterRecurring').addEventListener('change', function() {
            document.getElementById('filterRecurringLabel').textContent = this.checked ? 'On' : 'Off';
            loadExpenses();
        });
        // capex toggle
        document.getElementById('is_capex').addEventListener('change', function() {
            document.getElementById('isCapexLabel').textContent = this.checked ? 'Yes' : 'No';
            const w = document.getElementById('depWrap');
            if (this.checked) { w.classList.remove('initially-hidden'); w.style.display = ''; }
            else { w.classList.add('initially-hidden'); w.style.display = 'none'; document.getElementById('depreciation_period_months').value=''; }
        });
        // auto-compute outstanding hint when amount/paid changes
        ['amount','paid_amount','wht_pct'].forEach(id => {
            document.getElementById(id).addEventListener('input', recalcExpenseHint);
        });

        Promise.all([loadStaff(), loadVendors(), loadAreasList(), loadBankAccounts()]).then(() => loadAll());
        rsImport.init({ entity: 'Expense', onDone: loadAll });

        $('#filterFrom,#filterTo,#filterCategory,#filterPaidBy,#filterMethod,#filterStatus').on('change', loadExpenses);
        $('#filterSearch').on('input', debounce(loadExpenses, 300));
    });

    function toggleRefVisibility() {
        const pm = document.getElementById('payment_method').value;
        const wrap = document.getElementById('refNumWrap');
        if (pm && pm !== 'cash') {
            wrap.classList.remove('initially-hidden');
            wrap.style.display = '';
        } else {
            wrap.classList.add('initially-hidden');
            wrap.style.display = 'none';
            document.getElementById('reference_number').value = '';
        }
    }

    function toggleRecurrenceVisibility() {
        const isRec = document.getElementById('is_recurring').checked;
        const wrap = document.getElementById('recPerWrap');
        if (isRec) {
            wrap.classList.remove('initially-hidden');
            wrap.style.display = '';
        } else {
            wrap.classList.add('initially-hidden');
            wrap.style.display = 'none';
            const rp = document.getElementById('recurrence_period');
            rp.value = '';
            rp._sd && rp._sd.refresh();
        }
    }

    function toggleChequeVisibility() {
        const pm = document.getElementById('payment_method').value;
        const w1 = document.getElementById('chqWrap');
        const w2 = document.getElementById('chqDateWrap');
        if (pm === 'cheque') {
            w1.classList.remove('initially-hidden'); w1.style.display = '';
            w2.classList.remove('initially-hidden'); w2.style.display = '';
        } else {
            w1.classList.add('initially-hidden'); w1.style.display = 'none';
            w2.classList.add('initially-hidden'); w2.style.display = 'none';
            document.getElementById('cheque_number').value = '';
            document.getElementById('cheque_date').value = '';
        }
    }

    function recalcExpenseHint() {
        // pure UI hint — server recomputes authoritatively
        const a = parseFloat(document.getElementById('amount').value || 0);
        const p = parseFloat(document.getElementById('paid_amount').value || 0);
        const w = parseFloat(document.getElementById('wht_pct').value || 0);
        // no UI badge yet — keep silent; reserved for future
        void [a,p,w];
    }

    let vendorsCache = [], bankAccountsCache = [], areasCacheList = [];

    function loadVendors() {
        return $.getJSON('?action=getVendorsList').then(function(resp) {
            if (!resp.success) return;
            vendorsCache = resp.data || [];
            const sel = document.getElementById('vendor_id');
            sel.innerHTML = '<option value="">— None —</option>' + vendorsCache.map(v =>
                `<option value="${v.id}">${escapeHtml(v.code)} — ${escapeHtml(v.name)}</option>`
            ).join('');
            sel._sd && sel._sd.refresh();
        });
    }
    function loadAreasList() {
        return $.getJSON('?action=getAreasList').then(function(resp) {
            if (!resp.success) return;
            areasCacheList = resp.data || [];
            const sel = document.getElementById('area_id');
            sel.innerHTML = '<option value="">— Any —</option>' + areasCacheList.map(a =>
                `<option value="${a.id}">${escapeHtml(a.name)}</option>`
            ).join('');
            sel._sd && sel._sd.refresh();
        });
    }
    function loadBankAccounts() {
        return $.getJSON('?action=getBankAccountsList').then(function(resp) {
            if (!resp.success) return;
            bankAccountsCache = resp.data || [];
            const opts = '<option value="">— None —</option>' + bankAccountsCache.map(b =>
                `<option value="${b.id}">${escapeHtml(b.name)} — ${escapeHtml(b.bank)} (${escapeHtml(b.number)})</option>`
            ).join('');
            const sel1 = document.getElementById('bank_account_id');
            const sel2 = document.getElementById('payBankAccount');
            sel1.innerHTML = opts; sel1._sd && sel1._sd.refresh();
            sel2.innerHTML = opts; sel2._sd && sel2._sd.refresh();
        });
    }

    function markRemoveVendorInvoice() {
        document.getElementById('removeVendorInvoice').value = '1';
        document.getElementById('existingVInvWrap').style.display = 'none';
        Swal.fire({ icon:'info', title:'Vendor invoice will be removed on Save', timer: 1500, showConfirmButton: false });
    }

    function generateRecurring() {
        Swal.fire({
            icon: 'question', title: 'Generate Recurring Expenses?',
            text: 'Auto-create entries for any recurring expense whose next date is today or earlier.',
            showCancelButton: true, confirmButtonText: 'Generate', cancelButtonText: 'Cancel'
        }).then(r => {
            if (!r.isConfirmed) return;
            Swal.fire({ title: 'Working...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            const fd = new FormData();
            $.ajax({
                url: '?action=generateRecurring', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', title: 'Done', text: resp.message, timer: 2200, showConfirmButton: false });
                        setTimeout(loadAll, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function openPayModal(row) {
        document.getElementById('payExpenseId').value = row.id;
        const out = parseFloat(row.outstanding_amount || 0);
        document.getElementById('payOutstanding').textContent = fmtAmt(out) + ' (status: ' + capFirst(row.payment_status||'unpaid') + ')';
        document.getElementById('payAmount').value = out > 0 ? out.toFixed(2) : '';
        document.getElementById('payDate').value = new Date().toISOString().slice(0,10);
        const pm = document.getElementById('payMethod'); pm.value = ''; pm._sd && pm._sd.refresh();
        const ba = document.getElementById('payBankAccount'); ba.value = row.bank_account_id ? String(row.bank_account_id) : ''; ba._sd && ba._sd.refresh();
        document.getElementById('payCheque').value = '';
        document.getElementById('payNotes').value = '';
        document.getElementById('payChqWrap').classList.add('initially-hidden');
        document.getElementById('payChqWrap').style.display = 'none';
        document.getElementById('payModalTitle').innerHTML = '<i class="fas fa-money-check"></i> Record Payment';
        document.getElementById('payModal').classList.add('active');
    }
    function closePayModal() {
        document.getElementById('payModal').classList.remove('active');
        document.getElementById('payForm').reset();
    }

    function debounce(fn, ms) { let t; return function(){ clearTimeout(t); t = setTimeout(fn, ms); }; }
    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function capFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
    function fmtAmt(n) { return CURRENCY + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso + (iso.length === 10 ? 'T00:00:00' : ''));
        if (isNaN(d)) return iso;
        return d.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' });
    }

    function loadAll() {
        loadSummary();
        loadExpenses();
    }

    function loadStaff() {
        return $.getJSON('?action=getStaff').then(function(resp) {
            if (!resp.success) return;
            staffCache = resp.data || [];
            // populate paid_by + filter
            const sel = document.getElementById('paid_by');
            const fil = document.getElementById('filterPaidBy');
            sel.innerHTML = '<option value="">Select staff...</option>' + staffCache.map(s =>
                `<option value="${s.id}">${escapeHtml(s.name)} (${escapeHtml(s.role)})</option>`
            ).join('');
            fil.innerHTML = '<option value="">All Staff</option>' + staffCache.map(s =>
                `<option value="${s.id}">${escapeHtml(s.name)}</option>`
            ).join('');
            sel._sd && sel._sd.refresh();
            fil._sd && fil._sd.refresh();
        });
    }

    function loadSummary() {
        $.getJSON('?action=getMonthlySummary', function(resp) {
            if (!resp.success) return;
            $('#smThisTotal').text(fmtAmt(resp.this_month_total));
            $('#smThisLabel').text(resp.month_label || '');
            $('#smThisCount').text(resp.this_month_count);
            $('#smThisTax').text(fmtAmt(resp.this_month_tax || 0));
            $('#smLastTotal').text(fmtAmt(resp.last_month_total));
            $('#smLastLabel').text(resp.last_month_label || '');
            if (resp.top_category) {
                $('#smTopCat').text(capFirst(resp.top_category.name));
                $('#smTopAmt').text(fmtAmt(resp.top_category.amount));
            } else {
                $('#smTopCat').text('—');
                $('#smTopAmt').text('No data');
            }
            // breakdown strip
            const wrap = document.getElementById('catBreakdown');
            if (!resp.breakdown || !resp.breakdown.length) {
                wrap.innerHTML = '';
                return;
            }
            wrap.innerHTML = resp.breakdown.map(b => `
                <div class="summary-card">
                    <div class="summary-card-label">
                        <span class="cat-badge ${escapeHtml(b.category)}">${escapeHtml(capFirst(b.category))}</span>
                    </div>
                    <div class="summary-card-value">${fmtAmt(b.amount)}</div>
                    <div class="summary-card-sub">${b.count} entr${b.count === 1 ? 'y' : 'ies'}</div>
                </div>
            `).join('');
        });
    }

    function loadExpenses() {
        $('#skeletonWrap').show();
        $('#tableView,#filtersSection,#emptyState').hide();
        const params = {
            from:           $('#filterFrom').val(),
            to:             $('#filterTo').val(),
            category:       $('#filterCategory').val(),
            paid_by:        $('#filterPaidBy').val(),
            payment_method: $('#filterMethod').val(),
            status:         $('#filterStatus').val(),
            recurring_only: $('#filterRecurring').is(':checked') ? 1 : 0,
            q:              $('#filterSearch').val()
        };
        $.getJSON('?action=getExpenses', params, function(resp) {
            $('#skeletonWrap').hide();
            $('#filtersSection').show();
            if (!resp.success) {
                Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed to load' });
                return;
            }
            if (!resp.data.length) {
                $('#tableView').hide();
                $('#emptyState').removeClass('initially-hidden').show();
                if (expensesTable) { expensesTable.clear().draw(); }
                return;
            }
            $('#emptyState').hide();
            $('#tableView').show();
            renderTable(resp.data);
        }).fail(function() {
            $('#skeletonWrap').hide();
            Swal.fire({ icon:'error', title:'Error', text:'Connection error' });
        });
    }

    function renderTable(data) {
        if (expensesTable) { expensesTable.destroy(); $('#expensesTable').empty(); }
        setTimeout(() => {
            expensesTable = $('#expensesTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'expense_date', title: 'Date', render: d => `<span>${fmtDate(d)}</span>` },
                    {
                        data: 'category', title: 'Category',
                        render: function(d, t, row) {
                            const sub = row.subcategory ? `<div class="muted" style="font-size:11px">${escapeHtml(row.subcategory)}</div>` : '';
                            const rec = row.is_recurring ? ` <i class="fas fa-redo" title="Recurring (${escapeHtml(row.recurrence_period||'')})"></i>` : '';
                            return `<span class="cat-badge ${escapeHtml(d)}">${escapeHtml(capFirst(d))}</span>${rec}${sub}`;
                        }
                    },
                    { data: 'vendor_name', title: 'Vendor', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'description', title: 'Description', render: d => escapeHtml(d) },
                    {
                        data: 'amount', title: 'Amount',
                        className: 'num-cell',
                        render: d => `<span>${fmtAmt(d)}</span>`
                    },
                    {
                        data: 'tax_amount', title: 'Tax',
                        className: 'num-cell',
                        render: d => Number(d) > 0 ? `<span>${fmtAmt(d)}</span>` : '<span class="muted">—</span>'
                    },
                    {
                        data: 'payment_method', title: 'Method',
                        render: d => `<span class="method-badge ${escapeHtml(d)}">${escapeHtml(capFirst((d||'').replace('_',' ')))}</span>`
                    },
                    {
                        data: 'reference_number', title: 'Reference',
                        render: d => d ? escapeHtml(d) : '<span class="muted">—</span>'
                    },
                    { data: 'paid_by_name', title: 'Paid By', render: d => escapeHtml(d) },
                    {
                        data: 'status', title: 'Status',
                        render: d => `<span class="exp-status ${escapeHtml(d)}">${escapeHtml(capFirst(d))}</span>`
                    },
                    {
                        data: 'receipt_image', title: 'Receipt', orderable: false,
                        render: function(d) {
                            if (!d) return '<span class="muted">—</span>';
                            return `<a href="${escapeHtml(d)}" target="_blank" rel="noopener" title="View receipt"><i class="fas fa-file-alt"></i> View</a>`;
                        }
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(d, t, row) {
                            // ⋮ menu items
                            const rowJson = JSON.stringify(row).replace(/'/g,"&#39;");
                            const apr = (IS_ADMIN_LEVEL && row.status === 'pending')
                                ? `<button class="act-more-item" onclick="approveExpense(${row.id})"><i class="fas fa-check"></i> Approve</button>
                                   <button class="act-more-item" onclick="rejectExpense(${row.id})"><i class="fas fa-ban"></i> Reject</button>`
                                : '';
                            const del = CAN_DELETE
                                ? `<button class="act-more-item danger" onclick="deleteExpense(${row.id})"><i class="fas fa-trash"></i> Delete</button>`
                                : '';
                            const payable = ['unpaid','partial','overdue'].indexOf(row.payment_status) >= 0;
                            const pay = payable
                                ? `<button class="action-icon view-icon" onclick='openPayModal(${rowJson})' title="Record Payment"><i class="fas fa-money-check"></i></button>`
                                : '';
                            return `
                                <button class="action-icon view-icon" onclick="viewExpense(${row.id})" title="View"><i class="fas fa-eye"></i></button>
                                ${pay}
                                <div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">
                                    <button class="act-more-item" onclick="editExpense(${row.id})"><i class="fas fa-edit"></i> Edit</button>
                                    ${apr}
                                    ${del}
                                </div></div>
                            `;
                        }
                    }
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv',   text: '<i class="fas fa-file-csv"></i> CSV',   exportOptions: { columns: [0,1,2,3,4,5,6,7,8,9] } },
                    { extend: 'pdf',   text: '<i class="fas fa-file-pdf"></i> PDF',   exportOptions: { columns: [0,1,2,3,4,5,6,7,8,9] }, title: 'Expenses' },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print',   exportOptions: { columns: [0,1,2,3,4,5,6,7,8,9] } }
                ],
                order: [[0, 'desc']],
                language: { emptyTable: 'No expenses found.' }
            });
        }, 50);
    }

    // ─── modal helpers ─────────────────────────────────────────────────
    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Expense';
        document.getElementById('expenseForm').reset();
        document.getElementById('expenseId').value = '';
        document.getElementById('removeReceipt').value = '';
        document.getElementById('expense_date').value = new Date().toISOString().slice(0,10);
        document.getElementById('tax_amount').value = '0';
        document.getElementById('isRecurringLabel').textContent = 'No';
        // default category + paid_by = session user
        const cat = document.getElementById('category');
        cat.value = EXP_CATS[0];
        cat._sd && cat._sd.refresh();
        const pb = document.getElementById('paid_by');
        // auto-select session user if present in staff list
        const me = staffCache.find(s => s.id === SESSION_USER_ID);
        pb.value = me ? String(me.id) : '';
        pb._sd && pb._sd.refresh();
        const pm = document.getElementById('payment_method');
        pm.value = 'cash';
        pm._sd && pm._sd.refresh();
        const rp = document.getElementById('recurrence_period');
        rp.value = '';
        rp._sd && rp._sd.refresh();
        toggleRefVisibility();
        toggleRecurrenceVisibility();
        toggleChequeVisibility();
        document.getElementById('existingRcptWrap').classList.add('initially-hidden');
        document.getElementById('existingRcptWrap').style.display = 'none';
        document.getElementById('existingVInvWrap').classList.add('initially-hidden');
        document.getElementById('existingVInvWrap').style.display = 'none';
        // reset accounting fields
        document.getElementById('expense_number').value = '';
        document.getElementById('due_date').value = '';
        document.getElementById('paid_amount').value = '0';
        document.getElementById('paid_date').value = '';
        const vId = document.getElementById('vendor_id'); vId.value = ''; vId._sd && vId._sd.refresh();
        document.getElementById('vendor_invoice_number').value = '';
        document.getElementById('vendor_invoice_date').value = '';
        document.getElementById('wht_pct').value = '0';
        document.getElementById('gl_account_code').value = '';
        document.getElementById('cost_center').value = '';
        const aId = document.getElementById('area_id'); aId.value = ''; aId._sd && aId._sd.refresh();
        document.getElementById('branch_id').value = '';
        document.getElementById('is_capex').checked = false;
        document.getElementById('isCapexLabel').textContent = 'No';
        document.getElementById('depWrap').classList.add('initially-hidden');
        document.getElementById('depWrap').style.display = 'none';
        document.getElementById('depreciation_period_months').value = '';
        const bId = document.getElementById('bank_account_id'); bId.value = ''; bId._sd && bId._sd.refresh();
        document.getElementById('removeVendorInvoice').value = '';
        document.getElementById('expenseModal').classList.add('active');
    }

    function editExpense(id) {
        $.getJSON('?action=getExpenseDetail&id=' + id, function(resp) {
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed' }); return; }
            const e = resp.data;
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Expense';
            document.getElementById('expenseForm').reset();
            document.getElementById('expenseId').value = e.id;
            document.getElementById('removeReceipt').value = '';
            document.getElementById('expense_date').value = e.expense_date;
            const cat = document.getElementById('category'); cat.value = e.category; cat._sd && cat._sd.refresh();
            document.getElementById('subcategory').value = e.subcategory || '';
            document.getElementById('vendor_name').value = e.vendor_name || '';
            document.getElementById('description').value = e.description || '';
            document.getElementById('amount').value = e.amount;
            document.getElementById('tax_amount').value = e.tax_amount || 0;
            const pm = document.getElementById('payment_method'); pm.value = e.payment_method || 'cash'; pm._sd && pm._sd.refresh();
            document.getElementById('reference_number').value = e.reference_number || '';
            const pb = document.getElementById('paid_by'); pb.value = String(e.paid_by); pb._sd && pb._sd.refresh();
            const isRecChk = document.getElementById('is_recurring');
            isRecChk.checked = !!Number(e.is_recurring);
            document.getElementById('isRecurringLabel').textContent = isRecChk.checked ? 'Yes' : 'No';
            const rp = document.getElementById('recurrence_period');
            rp.value = e.recurrence_period || '';
            rp._sd && rp._sd.refresh();
            document.getElementById('notes').value = e.notes || '';

            // accounting fields
            document.getElementById('expense_number').value = e.expense_number || '';
            document.getElementById('due_date').value = e.due_date || '';
            document.getElementById('paid_amount').value = e.paid_amount || 0;
            document.getElementById('paid_date').value = e.paid_date || '';
            const vId = document.getElementById('vendor_id'); vId.value = e.vendor_id ? String(e.vendor_id) : ''; vId._sd && vId._sd.refresh();
            document.getElementById('vendor_invoice_number').value = e.vendor_invoice_number || '';
            document.getElementById('vendor_invoice_date').value = e.vendor_invoice_date || '';
            document.getElementById('wht_pct').value = e.wht_pct || 0;
            document.getElementById('gl_account_code').value = e.gl_account_code || '';
            document.getElementById('cost_center').value = e.cost_center || '';
            const aId = document.getElementById('area_id'); aId.value = e.area_id ? String(e.area_id) : ''; aId._sd && aId._sd.refresh();
            document.getElementById('branch_id').value = e.branch_id || '';
            const isCapexChk = document.getElementById('is_capex');
            isCapexChk.checked = !!Number(e.is_capex);
            document.getElementById('isCapexLabel').textContent = isCapexChk.checked ? 'Yes' : 'No';
            const depW = document.getElementById('depWrap');
            if (isCapexChk.checked) { depW.classList.remove('initially-hidden'); depW.style.display = ''; }
            else { depW.classList.add('initially-hidden'); depW.style.display = 'none'; }
            document.getElementById('depreciation_period_months').value = e.depreciation_period_months || '';
            const bId = document.getElementById('bank_account_id'); bId.value = e.bank_account_id ? String(e.bank_account_id) : ''; bId._sd && bId._sd.refresh();
            document.getElementById('cheque_number').value = e.cheque_number || '';
            document.getElementById('cheque_date').value = e.cheque_date || '';
            document.getElementById('removeVendorInvoice').value = '';

            toggleRefVisibility();
            toggleRecurrenceVisibility();
            toggleChequeVisibility();

            const wrap = document.getElementById('existingRcptWrap');
            const link = document.getElementById('existingRcptLink');
            if (e.receipt_image) {
                link.href = e.receipt_image;
                wrap.classList.remove('initially-hidden');
                wrap.style.display = 'block';
            } else {
                wrap.classList.add('initially-hidden');
                wrap.style.display = 'none';
            }
            const vWrap = document.getElementById('existingVInvWrap');
            const vLink = document.getElementById('existingVInvLink');
            if (e.vendor_invoice_path) {
                vLink.href = e.vendor_invoice_path;
                vWrap.classList.remove('initially-hidden');
                vWrap.style.display = 'block';
            } else {
                vWrap.classList.add('initially-hidden');
                vWrap.style.display = 'none';
            }
            document.getElementById('expenseModal').classList.add('active');
        });
    }

    function markRemoveReceipt() {
        document.getElementById('removeReceipt').value = '1';
        document.getElementById('existingRcptWrap').style.display = 'none';
        Swal.fire({ icon:'info', title:'Receipt will be removed on Save', timer: 1500, showConfirmButton: false });
    }

    function viewExpense(id) {
        $.getJSON('?action=getExpenseDetail&id=' + id, function(resp) {
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed' }); return; }
            const e = resp.data;
            let rcptHtml = '<span class="muted">No receipt</span>';
            if (e.receipt_image) {
                const isPdf = /\.pdf$/i.test(e.receipt_image);
                rcptHtml = isPdf
                    ? `<a href="${escapeHtml(e.receipt_image)}" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Open PDF</a>`
                    : `<a href="${escapeHtml(e.receipt_image)}" target="_blank" rel="noopener"><img src="${escapeHtml(e.receipt_image)}" alt="receipt" class="logo-preview-img"></a>`;
            }
            const sub = e.subcategory ? escapeHtml(e.subcategory) : '<span class="muted">—</span>';
            const vendor = e.vendor_name ? escapeHtml(e.vendor_name) : '<span class="muted">—</span>';
            const tax = Number(e.tax_amount) > 0 ? fmtAmt(e.tax_amount) : '<span class="muted">—</span>';
            const ref = e.reference_number ? escapeHtml(e.reference_number) : '<span class="muted">—</span>';
            const recHtml = Number(e.is_recurring)
                ? `<i class="fas fa-redo"></i> ${escapeHtml(capFirst(e.recurrence_period||'—'))}`
                : '<span class="muted">No</span>';
            const apprHtml = e.approver_name
                ? `${escapeHtml(e.approver_name)}${e.approved_at ? ' <span class="muted">@ ' + fmtDate(e.approved_at) + '</span>' : ''}`
                : '<span class="muted">—</span>';

            // approve/reject buttons for pending + admin-level
            const showActions = (resp.is_admin_level && e.status === 'pending');
            const actionsHtml = showActions
                ? `<div class="form-actions vtab-field-full">
                       <button type="button" class="btn btn-success" onclick="approveExpense(${e.id}, true)"><i class="fas fa-check"></i> Approve</button>
                       <button type="button" class="btn btn-danger" onclick="rejectExpense(${e.id}, true)"><i class="fas fa-ban"></i> Reject</button>
                   </div>`
                : '';

            // notes — keep line breaks without inline css
            const notesHtml = e.notes ? escapeHtml(e.notes).replace(/\n/g, '<br>') : '<span class="muted">—</span>';

            // payment numbers off the expense row
            const amt   = Number(e.amount || 0);
            const paid  = Number(e.paid_amount || 0);
            const out   = Number(e.outstanding_amount || Math.max(0, amt - paid));
            const wht   = Number(e.withholding_tax_amount || 0);
            const netP  = Number(e.net_paid_amount || 0);
            const payBadge = `<span class="exp-status ${escapeHtml(e.payment_status||'paid')}">${escapeHtml(capFirst((e.payment_status||'paid').replace('_',' ')))}</span>`;

            const body = `
                <div class="vtab-nav">
                    <button type="button" class="vtab-btn active" data-vtab="vtDetails"><i class="fas fa-info-circle"></i> Details</button>
                    <button type="button" class="vtab-btn" data-vtab="vtPayments"><i class="fas fa-money-check"></i> Payments</button>
                </div>
                <div class="vtab-pane active" id="vtDetails">
                    <div class="vtab-grid">
                        <div class="vtab-field"><label><i class="fas fa-calendar-day"></i> Date</label><span>${fmtDate(e.expense_date)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-tags"></i> Category</label><span><span class="cat-badge ${escapeHtml(e.category)}">${escapeHtml(capFirst(e.category))}</span></span></div>
                        <div class="vtab-field"><label><i class="fas fa-layer-group"></i> Subcategory</label><span>${sub}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-store"></i> Vendor</label><span>${vendor}</span></div>
                        <div class="vtab-field vtab-field-full"><label><i class="fas fa-align-left"></i> Description</label><span>${escapeHtml(e.description)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-money-bill-wave"></i> Amount</label><span>${fmtAmt(e.amount)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-percent"></i> Tax</label><span>${tax}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-credit-card"></i> Method</label><span><span class="method-badge ${escapeHtml(e.payment_method||'cash')}">${escapeHtml(capFirst((e.payment_method||'cash').replace('_',' ')))}</span></span></div>
                        <div class="vtab-field"><label><i class="fas fa-hashtag"></i> Reference</label><span>${ref}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-user"></i> Paid By</label><span>${escapeHtml(e.paid_by_name)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-flag"></i> Status</label><span><span class="exp-status ${escapeHtml(e.status||'approved')}">${escapeHtml(capFirst(e.status||'approved'))}</span></span></div>
                        <div class="vtab-field"><label><i class="fas fa-redo"></i> Recurring</label><span>${recHtml}</span></div>
                        <div class="vtab-field vtab-field-full"><label><i class="fas fa-user-check"></i> Approved By</label><span>${apprHtml}</span></div>
                        <div class="vtab-field vtab-field-full"><label><i class="fas fa-sticky-note"></i> Notes</label><span>${notesHtml}</span></div>
                        <div class="vtab-field vtab-field-full"><label><i class="fas fa-paperclip"></i> Receipt</label><span>${rcptHtml}</span></div>
                        ${actionsHtml}
                    </div>
                </div>
                <div class="vtab-pane" id="vtPayments">
                    <div class="vtab-grid">
                        <div class="vtab-field"><label><i class="fas fa-money-bill-wave"></i> Total Amount</label><span>${fmtAmt(amt)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-check-circle"></i> Total Paid</label><span id="payTabPaid">${fmtAmt(paid)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-hourglass-half"></i> Outstanding</label><span id="payTabOut">${fmtAmt(out)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-flag"></i> Payment Status</label><span id="payTabStatus">${payBadge}</span></div>
                        ${wht > 0 ? `<div class="vtab-field"><label><i class="fas fa-percent"></i> Withholding Tax</label><span>${fmtAmt(wht)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-hand-holding-usd"></i> Net Paid</label><span>${fmtAmt(netP)}</span></div>` : ''}
                    </div>
                    <table class="ledger-table mt-20">
                        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Notes</th><th>Logged By</th><th></th></tr></thead>
                        <tbody id="payHistBody"><tr><td colspan="7" class="muted">Loading payments...</td></tr></tbody>
                    </table>
                </div>
            `;
            document.getElementById('viewModalBody').innerHTML = body;
            wireVtabs(document.getElementById('viewModalBody'));
            document.getElementById('viewModal').classList.add('active');
            loadExpensePayments(e.id, amt, e.due_date || '');
        });
    }

    // load real payment history into the Payments tab
    let payTabExpenseId = 0, payTabAmount = 0;
    function loadExpensePayments(expId, amount, dueDate) {
        payTabExpenseId = expId; payTabAmount = Number(amount || 0);
        const body = document.getElementById('payHistBody');
        if (!body) return;
        $.getJSON('?action=getExpensePayments&id=' + expId, function(resp) {
            const b = document.getElementById('payHistBody');
            if (!b) return;
            if (!resp.success) { b.innerHTML = `<tr><td colspan="7" class="muted">${escapeHtml(resp.message||'Failed to load')}</td></tr>`; return; }
            const rows = resp.data || [];
            if (!rows.length) {
                b.innerHTML = `<tr><td colspan="7" class="muted">No payments recorded yet.</td></tr>`;
            } else {
                b.innerHTML = rows.map(p => {
                    const method = (p.payment_method||'cash');
                    const ref = p.reference ? escapeHtml(p.reference) : '<span class="muted">—</span>';
                    const notes = p.notes ? escapeHtml(p.notes) : '<span class="muted">—</span>';
                    const delBtn = resp.can_delete
                        ? `<button class="action-icon delete-icon" onclick="deleteExpensePayment(${p.id})" title="Delete Payment"><i class="fas fa-trash"></i></button>`
                        : '';
                    return `<tr>
                        <td>${fmtDate(p.payment_date)}</td>
                        <td class="ledger-credit">${fmtAmt(p.amount)}</td>
                        <td><span class="method-badge ${escapeHtml(method)}">${escapeHtml(capFirst(method.replace('_',' ')))}</span></td>
                        <td>${ref}</td>
                        <td>${notes}</td>
                        <td>${escapeHtml(p.created_by_name||'—')}</td>
                        <td>${delBtn}</td>
                    </tr>`;
                }).join('');
            }
            // refresh summary numbers from real total
            const totalPaid = Number(resp.total_paid || 0);
            const outstanding = Math.max(0, payTabAmount - totalPaid);
            let ps = totalPaid > 0 ? (totalPaid >= payTabAmount && payTabAmount > 0 ? 'paid' : 'partial') : 'unpaid';
            if (ps !== 'paid' && dueDate && new Date(dueDate) < new Date(new Date().toISOString().slice(0,10))) ps = 'overdue';
            const pEl = document.getElementById('payTabPaid'); if (pEl) pEl.textContent = fmtAmt(totalPaid);
            const oEl = document.getElementById('payTabOut'); if (oEl) oEl.textContent = fmtAmt(outstanding);
            const sEl = document.getElementById('payTabStatus');
            if (sEl) sEl.innerHTML = `<span class="exp-status ${ps}">${capFirst(ps)}</span>`;
        });
    }

    // soft-delete one payment then refresh
    function deleteExpensePayment(paymentId) {
        if (!CAN_DELETE) return;
        Swal.fire({
            icon: 'warning', title: 'Delete Payment?', text: 'This removes the payment and recalculates the balance.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('payment_id', paymentId);
            $.ajax({
                url: '?action=deleteExpensePayment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        if (payTabExpenseId) viewExpense(payTabExpenseId);
                        setTimeout(loadAll, 100);
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
            });
        });
    }

    // tab switcher for view modal
    function wireVtabs(scope) {
        scope.querySelectorAll('.vtab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                scope.querySelectorAll('.vtab-btn').forEach(b => b.classList.remove('active'));
                scope.querySelectorAll('.vtab-pane').forEach(p => p.classList.remove('active'));
                btn.classList.add('active');
                const pane = scope.querySelector('#' + btn.dataset.vtab);
                if (pane) pane.classList.add('active');
            });
        });
    }

    function closeModal() {
        document.getElementById('expenseModal').classList.remove('active');
        document.getElementById('expenseForm').reset();
    }
    function closeViewModal() {
        document.getElementById('viewModal').classList.remove('active');
    }

    document.getElementById('expenseModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    document.getElementById('viewModal').addEventListener('click', function(e) {
        if (e.target === this) closeViewModal();
    });
    document.getElementById('payModal').addEventListener('click', function(e) {
        if (e.target === this) closePayModal();
    });

    // pay form submit
    document.getElementById('payForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const id = document.getElementById('payExpenseId').value;
        const amt = parseFloat(document.getElementById('payAmount').value || 0);
        if (amt <= 0) { Swal.fire({ icon:'warning', title:'Enter amount > 0' }); return; }
        const fd = new FormData();
        fd.append('id', id);
        fd.append('amount', amt);
        fd.append('date', document.getElementById('payDate').value);
        fd.append('method', document.getElementById('payMethod').value || '');
        fd.append('bank_account_id', document.getElementById('payBankAccount').value || 0);
        fd.append('cheque_number', document.getElementById('payCheque').value || '');
        fd.append('notes', document.getElementById('payNotes').value || '');
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=recordPayment', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon:'success', text: resp.message, timer: 1800, showConfirmButton: false });
                    closePayModal();
                    setTimeout(loadAll, 100);
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
        });
    });

    // submit
    document.getElementById('expenseForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const action = isEditMode ? 'updateExpense' : 'addExpense';
        // size guard 5MB
        const file = document.getElementById('receipt_image').files[0];
        if (file && file.size > 5 * 1024 * 1024) {
            Swal.fire({ icon:'error', title:'File too large', text:'Max 5MB' });
            return;
        }
        Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen:() => Swal.showLoading() });
        $.ajax({
            url: '?action=' + action, method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon:'success', title:'Success!', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeModal();
                    setTimeout(loadAll, 100);
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
        });
    });

    function deleteExpense(id) {
        if (!CAN_DELETE) return;
        Swal.fire({
            icon: 'warning', title: 'Delete Expense?', text: 'This will soft-delete the expense.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteExpense', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1800, showConfirmButton: false });
                        setTimeout(loadAll, 100);
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
            });
        });
    }

    function clearFilters() {
        ['filterFrom','filterTo','filterSearch'].forEach(id => document.getElementById(id).value = '');
        ['filterCategory','filterPaidBy','filterMethod','filterStatus'].forEach(id => {
            const el = document.getElementById(id);
            el.value = '';
            el._sd && el._sd.refresh();
        });
        document.getElementById('filterRecurring').checked = false;
        document.getElementById('filterRecurringLabel').textContent = 'Off';
        loadExpenses();
    }

    // approve / reject — admin-level only
    function approveExpense(id, fromView) {
        if (!IS_ADMIN_LEVEL) return;
        Swal.fire({
            icon: 'question', title: 'Approve Expense?', text: 'Mark this expense as approved.',
            showCancelButton: true, confirmButtonColor: '#16a34a',
            confirmButtonText: 'Approve', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=approveExpense', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        if (fromView) closeViewModal();
                        setTimeout(loadAll, 100);
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
            });
        });
    }

    function rejectExpense(id, fromView) {
        if (!IS_ADMIN_LEVEL) return;
        Swal.fire({
            icon: 'warning', title: 'Reject Expense', input: 'textarea',
            inputLabel: 'Rejection reason',
            inputPlaceholder: 'Why is this being rejected?',
            inputAttributes: { 'aria-label': 'Reason', 'maxlength': 500 },
            showCancelButton: true, confirmButtonColor: '#dc2626',
            confirmButtonText: 'Reject', cancelButtonText: 'Cancel',
            inputValidator: v => !v || !v.trim() ? 'Reason required' : undefined
        }).then(result => {
            if (!result.isConfirmed) return;
            const reason = (result.value || '').trim();
            const fd = new FormData(); fd.append('id', id); fd.append('reject_reason', reason);
            $.ajax({
                url: '?action=rejectExpense', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        if (fromView) closeViewModal();
                        setTimeout(loadAll, 100);
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
            });
        });
    }
    </script>
</body>
</html>
