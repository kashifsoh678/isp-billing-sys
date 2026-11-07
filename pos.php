<?php
/**
 * Developed by Kashif Sohail (Kashif  Scripts)
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * POS Counter — sell stock items over the counter, print thermal + A4 bills
 */
require_once 'config.php';

requirePerm('pos', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'pos';
$can_sell     = can('pos', 'a');
$can_void     = can('pos', 'd');
$currency     = getSetting('currency_symbol', '$');
$siteName     = getSetting('site_name', 'ISP Billing');
$siteLogo     = getSetting('site_logo', '');
$compPhone    = getSetting('company_phone', '');
$compEmail    = getSetting('company_email', '');
$compAddr     = getSetting('company_address', '');

$METHODS = ['cash', 'bank_transfer', 'easypaisa', 'jazzcash', 'cheque', 'card'];

// ─────────────────────────── AJAX ───────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            // catalog + pickers the counter needs on load
            case 'getBootstrap': {
                $cats = [];
                $r = $conn->query("SELECT id, name FROM stock_categories WHERE is_deleted = 0 AND status = 'active' ORDER BY name");
                while ($x = $r->fetch_assoc()) $cats[] = ['id' => (int)$x['id'], 'name' => $x['name']];

                $custs = [];
                $r = $conn->query("SELECT id, customer_code, full_name, phone FROM customers WHERE is_deleted = 0 ORDER BY full_name LIMIT 2000");
                while ($x = $r->fetch_assoc()) {
                    $custs[] = ['id' => (int)$x['id'], 'label' => $x['full_name'] . ' (' . $x['customer_code'] . ')', 'phone' => $x['phone']];
                }
                echo json_encode(['success' => true, 'categories' => $cats, 'customers' => $custs,
                                  'tax_pct' => (float)getSetting('auto_billing_tax_pct', 0)]);
                exit();
            }

            // what the customer already owes before this bill — invoices + open counter bills
            case 'getCustomerDues': {
                $cid = (int)($_GET['customer_id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success' => true, 'total' => 0]); exit(); }

                $st = $conn->prepare("SELECT IFNULL(SUM(balance_amount),0) amt, COUNT(*) cnt,
                                             IFNULL(SUM(status = 'overdue'),0) od, MIN(due_date) oldest
                                      FROM invoices
                                      WHERE customer_id = ? AND is_deleted = 0
                                        AND status IN ('unpaid','partial','overdue')");
                $st->bind_param("i", $cid);
                $st->execute(); $inv = $st->get_result()->fetch_assoc(); $st->close();

                // counter dues + last payment in one trip
                $st = $conn->prepare("SELECT IFNULL(SUM(s.due_amount),0) amt, COUNT(s.id) cnt,
                                             (SELECT last_payment_date FROM customers WHERE id = ?) last_pay
                                      FROM pos_sales s
                                      WHERE s.customer_id = ? AND s.is_deleted = 0
                                        AND s.status = 'completed' AND s.due_amount > 0");
                $st->bind_param("ii", $cid, $cid);
                $st->execute(); $pos = $st->get_result()->fetch_assoc(); $st->close();

                $invDue = round((float)$inv['amt'], 2); $posDue = round((float)$pos['amt'], 2);
                echo json_encode(['success' => true,
                    'total'         => round($invDue + $posDue, 2),
                    'invoice_due'   => $invDue,   'invoice_count' => (int)$inv['cnt'],
                    'pos_due'       => $posDue,   'pos_count'     => (int)$pos['cnt'],
                    'overdue_count' => (int)$inv['od'], 'oldest_due' => $inv['oldest'],
                    'last_payment'  => $pos['last_pay']]);
                exit();
            }

            case 'getCatalog': {
                $cat = (int)($_GET['category_id'] ?? 0);
                $q   = trim($_GET['q'] ?? '');
                $where = "i.is_deleted = 0 AND i.status = 'active'";
                $types = ''; $vals = [];
                if ($cat > 0) { $where .= " AND i.category_id = ?"; $types .= 'i'; $vals[] = $cat; }
                if ($q !== '') {
                    $where .= " AND (i.name LIKE ? OR i.item_code LIKE ? OR i.brand LIKE ? OR i.model LIKE ?)";
                    $types .= 'ssss'; $like = "%$q%"; array_push($vals, $like, $like, $like, $like);
                }
                $sql = "SELECT i.id, i.item_code, i.name, i.unit, i.tracking_type, i.current_qty, i.sale_price, i.avg_cost,
                               c.name AS category_name
                        FROM stock_items i
                        LEFT JOIN stock_categories c ON c.id = i.category_id
                        WHERE $where ORDER BY i.name ASC LIMIT 300";
                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$vals);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($x = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'        => (int)$x['id'],
                        'code'      => $x['item_code'],
                        'name'      => $x['name'],
                        'unit'      => $x['unit'],
                        'serial'    => $x['tracking_type'] === 'serial',
                        'qty'       => (float)$x['current_qty'],
                        'price'     => round((float)$x['sale_price'], 2),
                        'cost'      => round((float)$x['avg_cost'], 2),
                        'category'  => $x['category_name'] ?? ''
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getSummary': {
                $g = fn($sql) => $conn->query($sql)->fetch_assoc();
                $today = $g("SELECT COUNT(*) c, IFNULL(SUM(total),0) t FROM pos_sales
                             WHERE is_deleted = 0 AND status = 'completed' AND DATE(sale_date) = CURDATE()");
                $items = $g("SELECT IFNULL(SUM(si.qty),0) q FROM pos_sale_items si
                             JOIN pos_sales s ON s.id = si.sale_id
                             WHERE s.is_deleted = 0 AND s.status = 'completed' AND DATE(s.sale_date) = CURDATE()");
                $due   = $g("SELECT IFNULL(SUM(due_amount),0) d, COUNT(*) c FROM pos_sales
                             WHERE is_deleted = 0 AND status = 'completed' AND due_amount > 0");
                $month = $g("SELECT IFNULL(SUM(total),0) t FROM pos_sales
                             WHERE is_deleted = 0 AND status = 'completed'
                               AND YEAR(sale_date) = YEAR(CURDATE()) AND MONTH(sale_date) = MONTH(CURDATE())");
                $cnt = (int)$today['c'];
                echo json_encode(['success' => true, 'summary' => [
                    'today_total' => round((float)$today['t'], 2),
                    'today_bills' => $cnt,
                    'avg_bill'    => $cnt ? round((float)$today['t'] / $cnt, 2) : 0,
                    'items_sold'  => round((float)$items['q'], 2),
                    'due_total'   => round((float)$due['d'], 2),
                    'due_bills'   => (int)$due['c'],
                    'month_total' => round((float)$month['t'], 2)
                ]]);
                exit();
            }

            case 'getSales': {
                $from = $_GET['from'] ?? '';
                $to   = $_GET['to'] ?? '';
                $st   = $_GET['status'] ?? '';
                $where = "s.is_deleted = 0";
                $types = ''; $vals = [];
                if ($from !== '') { $where .= " AND DATE(s.sale_date) >= ?"; $types .= 's'; $vals[] = $from; }
                if ($to   !== '') { $where .= " AND DATE(s.sale_date) <= ?"; $types .= 's'; $vals[] = $to; }
                if ($st === 'completed' || $st === 'void') { $where .= " AND s.status = ?"; $types .= 's'; $vals[] = $st; }
                elseif ($st === 'due')                     { $where .= " AND s.due_amount > 0 AND s.status = 'completed'"; }

                $sql = "SELECT s.id, s.sale_number, s.sale_date, s.total, s.paid_amount, s.due_amount,
                               s.payment_method, s.payment_status, s.status, s.walk_in_name,
                               c.full_name AS customer_name, c.customer_code,
                               u.name AS sold_by,
                               (SELECT COUNT(*) FROM pos_sale_items si WHERE si.sale_id = s.id) AS line_count
                        FROM pos_sales s
                        LEFT JOIN customers c ON c.id = s.customer_id
                        LEFT JOIN users u     ON u.id = s.sold_by_user_id
                        WHERE $where ORDER BY s.id DESC LIMIT 500";
                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$vals);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($x = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'         => (int)$x['id'],
                        'number'     => $x['sale_number'],
                        'date'       => date('c', strtotime($x['sale_date'])),
                        'buyer'      => $x['customer_name'] ?: ($x['walk_in_name'] ?: 'Walk-in'),
                        'code'       => $x['customer_code'] ?? '',
                        'lines'      => (int)$x['line_count'],
                        'total'      => (float)$x['total'],
                        'paid'       => (float)$x['paid_amount'],
                        'due'        => (float)$x['due_amount'],
                        'method'     => $x['payment_method'],
                        'pay_status' => $x['payment_status'],
                        'status'     => $x['status'],
                        'sold_by'    => $x['sold_by'] ?? ''
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getSaleDetail': {
                $id = (int)($_GET['id'] ?? 0);
                $stmt = $conn->prepare("SELECT s.*, c.full_name AS customer_name, c.customer_code, c.phone AS customer_phone,
                                               c.address AS customer_address, u.name AS sold_by
                                        FROM pos_sales s
                                        LEFT JOIN customers c ON c.id = s.customer_id
                                        LEFT JOIN users u     ON u.id = s.sold_by_user_id
                                        WHERE s.id = ? AND s.is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $sale = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$sale) { echo json_encode(['success' => false, 'message' => 'Sale not found']); exit(); }

                $stmt = $conn->prepare("SELECT item_code, item_name, unit, qty, unit_price, line_discount, line_total
                                        FROM pos_sale_items WHERE sale_id = ? ORDER BY id ASC");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $res = $stmt->get_result();
                $items = [];
                while ($x = $res->fetch_assoc()) {
                    $items[] = ['code' => $x['item_code'], 'name' => $x['item_name'], 'unit' => $x['unit'],
                                'qty' => (float)$x['qty'], 'price' => (float)$x['unit_price'],
                                'discount' => (float)$x['line_discount'], 'total' => (float)$x['line_total']];
                }
                $stmt->close();

                echo json_encode(['success' => true, 'sale' => [
                    'id'         => (int)$sale['id'],
                    'number'     => $sale['sale_number'],
                    'date'       => date('c', strtotime($sale['sale_date'])),
                    'buyer'      => $sale['customer_name'] ?: ($sale['walk_in_name'] ?: 'Walk-in customer'),
                    'code'       => $sale['customer_code'] ?? '',
                    'phone'      => $sale['customer_phone'] ?: ($sale['walk_in_phone'] ?? ''),
                    'address'    => $sale['customer_address'] ?? '',
                    'subtotal'   => (float)$sale['subtotal'],
                    'discount'   => (float)$sale['discount_amount'],
                    'tax_pct'    => (float)$sale['tax_pct'],
                    'tax'        => (float)$sale['tax_amount'],
                    'total'      => (float)$sale['total'],
                    'paid'       => (float)$sale['paid_amount'],
                    'change'     => (float)$sale['change_amount'],
                    'due'        => (float)$sale['due_amount'],
                    'method'     => $sale['payment_method'],
                    'pay_status' => $sale['payment_status'],
                    'status'     => $sale['status'],
                    'notes'      => $sale['notes'] ?? '',
                    'sold_by'    => $sale['sold_by'] ?? '',
                    'items'      => $items
                ]]);
                exit();
            }

            case 'saveSale': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST only']); exit(); }
                if (!can('pos', 'a')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }

                $lines    = json_decode($_POST['lines'] ?? '[]', true);
                $custId   = (int)($_POST['customer_id'] ?? 0);
                $wName    = trim($_POST['walk_in_name'] ?? '');
                $wPhone   = trim($_POST['walk_in_phone'] ?? '');
                $discount = round((float)($_POST['discount'] ?? 0), 2);
                $taxPct   = round((float)($_POST['tax_pct'] ?? 0), 2);
                $paid     = round((float)($_POST['paid'] ?? 0), 2);
                $method   = $_POST['payment_method'] ?? 'cash';
                $notes    = trim($_POST['notes'] ?? '');

                if (!is_array($lines) || !count($lines)) { echo json_encode(['success' => false, 'message' => 'The cart is empty']); exit(); }
                if (!in_array($method, $METHODS, true))  { echo json_encode(['success' => false, 'message' => 'Unknown payment method']); exit(); }
                if ($discount < 0 || $taxPct < 0 || $paid < 0) { echo json_encode(['success' => false, 'message' => 'Amounts cannot be negative']); exit(); }

                $conn->begin_transaction();
                try {
                    // same validator the invoice uses — stock levels, serials, unknown items
                    $clean = invoiceStockLinesClean($conn, $lines);
                    $subtotal = $clean['total'];
                    if ($discount > $subtotal) throw new Exception('Discount cannot be more than the subtotal');

                    $taxable = round($subtotal - $discount, 2);
                    $taxAmt  = round($taxable * $taxPct / 100, 2);
                    $total   = round($taxable + $taxAmt, 2);
                    $change  = $paid > $total ? round($paid - $total, 2) : 0.0;
                    $due     = $paid < $total ? round($total - $paid, 2) : 0.0;
                    $payStat = $due <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                    if ($due > 0 && $custId <= 0) throw new Exception('Part-paid bills need a registered customer — the balance has to sit on someone\'s account');

                    $number = nextDocNumber($conn, 'pos_sales', 'sale_number', 'POS-');
                    $now    = date('Y-m-d H:i:s');
                    $cid    = $custId > 0 ? $custId : null;
                    $paidIn = $paid > $total ? $total : $paid;   // never book more than the bill

                    $st = $conn->prepare("INSERT INTO pos_sales
                        (sale_number, sale_date, customer_id, walk_in_name, walk_in_phone,
                         subtotal, discount_amount, tax_pct, tax_amount, total, paid_amount, change_amount, due_amount,
                         payment_method, payment_status, notes, sold_by_user_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $st->bind_param("ssissddddddddsssi", $number, $now, $cid, $wName, $wPhone,
                        $subtotal, $discount, $taxPct, $taxAmt, $total, $paidIn, $change, $due,
                        $method, $payStat, $notes, $user_id);
                    $st->execute();
                    $saleId = $conn->insert_id;
                    $st->close();

                    $li = $conn->prepare("INSERT INTO pos_sale_items
                        (sale_id, item_id, item_code, item_name, unit, qty, unit_price, unit_cost, line_discount, line_total)
                        VALUES (?,?,?,?,?,?,?,?,?,?)");
                    foreach ($clean['lines'] as $c) {
                        $iid  = (int)$c['item']['id'];
                        $code = $c['item']['item_code'];
                        $name = $c['item']['name'];
                        $unit = $c['item']['unit'];
                        $qty  = $c['qty'];
                        $rate = $c['rate'];
                        $cost = 0.0;
                        $ldis = 0.0;
                        $lt   = $c['line_total'];
                        $li->bind_param("iisssddddd", $saleId, $iid, $code, $name, $unit, $qty, $rate, $cost, $ldis, $lt);
                        $li->execute();
                    }
                    $li->close();

                    writePosStock($conn, $saleId, $clean['lines'], $user_id);

                    // money trail — receivable on the customer, cash/bank in
                    $day = substr($now, 0, 10);
                    if ($cid) {
                        postLedger($conn, 'customer', $cid, $day, "POS sale $number", $total, 0, 'pos_sale', $saleId, $number, $user_id);
                        if ($paidIn > 0)
                            postLedger($conn, 'customer', $cid, $day, "POS payment $number", 0, $paidIn, 'pos_payment', $saleId, $number, $user_id);
                    }
                    if ($paidIn > 0) {
                        $party = $method === 'cash' ? 'cash' : 'bank';
                        postLedger($conn, $party, 0, $day, "Counter sale $number", $paidIn, 0, 'pos_cash', $saleId, $number, $user_id);
                    }

                    $conn->commit();
                    logActivity($user_id, $username, 'POS Sale', "Sold $number — {$currency}" . number_format($total, 2) . " ({$method})");
                    echo json_encode(['success' => true, 'id' => $saleId, 'number' => $number]);
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                exit();
            }

            case 'voidSale': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST only']); exit(); }
                if (!can('pos', 'd')) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                $id     = (int)($_POST['id'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                if ($id <= 0 || $reason === '') { echo json_encode(['success' => false, 'message' => 'A void reason is required']); exit(); }

                $conn->begin_transaction();
                try {
                    $g = $conn->prepare("SELECT sale_number, status FROM pos_sales WHERE id = ? AND is_deleted = 0 FOR UPDATE");
                    $g->bind_param("i", $id);
                    $g->execute();
                    $sale = $g->get_result()->fetch_assoc();
                    $g->close();
                    if (!$sale) throw new Exception('Sale not found');
                    if ($sale['status'] === 'void') throw new Exception('This bill is already void');

                    releasePosStock($conn, $id);          // stock back on the shelf
                    reverseLedger($conn, 'pos_sale', $id);
                    reverseLedger($conn, 'pos_payment', $id);
                    reverseLedger($conn, 'pos_cash', $id);

                    $u = $conn->prepare("UPDATE pos_sales SET status = 'void', void_reason = ?, voided_at = NOW() WHERE id = ?");
                    $u->bind_param("si", $reason, $id);
                    $u->execute();
                    $u->close();

                    $conn->commit();
                    logActivity($user_id, $username, 'POS Sale Voided', "Voided {$sale['sale_number']} — $reason");
                    echo json_encode(['success' => true]);
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                exit();
            }
        }
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
    } catch (Throwable $e) {
        error_log('pos.php: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
    exit();
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Counter - <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="breadcrumb no-print">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>POS Counter</span>
            </div>

            <div class="header no-print">
                <h1><i class="fas fa-cash-register"></i> POS Counter</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="summary-grid no-print">
                <div class="summary-card highlight">
                    <div class="summary-card-label"><i class="fas fa-money-bill-trend-up"></i> Today's Sales</div>
                    <div class="summary-card-value" id="smToday">—</div>
                    <div class="summary-card-sub">Completed bills today</div>
                </div>
                <div class="summary-card">
                    <div class="summary-card-label"><i class="fas fa-receipt"></i> Today's Bills</div>
                    <div class="summary-card-value" id="smBills">—</div>
                    <div class="summary-card-sub">Receipts printed today</div>
                </div>
                <div class="summary-card success">
                    <div class="summary-card-label"><i class="fas fa-scale-balanced"></i> Average Bill</div>
                    <div class="summary-card-value" id="smAvg">—</div>
                    <div class="summary-card-sub">Today's sales per bill</div>
                </div>
                <div class="summary-card">
                    <div class="summary-card-label"><i class="fas fa-cubes"></i> Items Sold</div>
                    <div class="summary-card-value" id="smItems">—</div>
                    <div class="summary-card-sub">Units off the shelf today</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-card-label"><i class="fas fa-hourglass-half"></i> Unpaid Balance</div>
                    <div class="summary-card-value" id="smDue">—</div>
                    <div class="summary-card-sub" id="smDueSub">Left on counter bills</div>
                </div>
            </div>

            <div class="detail-tabs pos-tabs no-print">
                <div class="detail-tab active" data-postab="counter"><i class="fas fa-store"></i> Counter</div>
                <div class="detail-tab" data-postab="bills"><i class="fas fa-table"></i> Recent Bills</div>
            </div>

            <!-- ── the counter: catalog left, cart right ── -->
            <div class="data-section no-print" id="tabCounter">
                <div class="section-header">
                    <h2><i class="fas fa-store"></i> Counter</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadCatalog()"><i class="fas fa-sync"></i> Refresh</button>
                        <button class="btn btn-secondary" onclick="clearCart()"><i class="fas fa-trash-can"></i> Clear Cart</button>
                    </div>
                </div>

                <div class="pos-grid">
                    <section class="pos-catalog">
                        <div class="pos-search">
                            <i class="fas fa-magnifying-glass"></i>
                            <input type="search" id="posSearch" placeholder="Search item, code, brand or model…" autocomplete="off">
                        </div>
                        <div class="pos-cat-row">
                            <select id="posCategory"><option value="">All categories</option></select>
                        </div>
                        <div class="pos-items" id="posItems">
                            <div class="pos-empty"><i class="fas fa-spinner fa-spin"></i> Loading stock…</div>
                        </div>
                    </section>

                    <aside class="pos-cart">
                        <div class="pos-cart-head"><i class="fas fa-basket-shopping"></i> Current Bill
                            <span class="pos-cart-count" id="cartCount">0</span></div>

                        <div class="pos-buyer">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Customer (optional)</label>
                                <select id="posCustomer"><option value="">Walk-in customer</option></select>
                            </div>
                            <div class="pos-due" id="custDueBox">
                                <div class="pos-due-head">
                                    <span><i class="fas fa-clock-rotate-left"></i> Previous Balance</span>
                                    <strong id="custDueAmt">—</strong>
                                </div>
                                <div class="pos-due-meta" id="custDueMeta"></div>
                            </div>
                            <div class="form-row-2" id="walkInBox">
                                <div class="form-group">
                                    <label><i class="fas fa-id-card"></i> Walk-in Name</label>
                                    <input type="text" id="walkName" placeholder="Optional">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-phone"></i> Walk-in Phone</label>
                                    <input type="text" id="walkPhone" placeholder="Optional">
                                </div>
                            </div>
                        </div>

                        <div class="pos-lines" id="posLines">
                            <div class="pos-empty"><i class="fas fa-cart-shopping"></i><br>Tap an item to start the bill</div>
                        </div>

                        <div class="pos-totals">
                            <div class="pos-total-row"><span>Subtotal</span><strong id="tSub"><?php echo $currency; ?> 0.00</strong></div>
                            <div class="pos-total-row">
                                <span>Discount</span>
                                <input type="number" id="tDiscount" class="pos-num" min="0" step="0.01" value="0">
                            </div>
                            <div class="pos-total-row">
                                <span>Tax %</span>
                                <input type="number" id="tTaxPct" class="pos-num" min="0" step="0.01" value="0">
                            </div>
                            <div class="pos-total-row"><span>Tax Amount</span><strong id="tTax"><?php echo $currency; ?> 0.00</strong></div>
                            <div class="pos-total-row grand"><span>Total</span><strong id="tTotal"><?php echo $currency; ?> 0.00</strong></div>
                            <div class="pos-total-row">
                                <span>Paid</span>
                                <input type="number" id="tPaid" class="pos-num" min="0" step="0.01" value="0">
                            </div>
                            <div class="pos-total-row change"><span id="tChangeLabel">Change</span><strong id="tChange"><?php echo $currency; ?> 0.00</strong></div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-wallet"></i> Payment Method</label>
                            <select id="posMethod">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="easypaisa">EasyPaisa</option>
                                <option value="jazzcash">JazzCash</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-note-sticky"></i> Note</label>
                            <input type="text" id="posNotes" placeholder="Optional note on the bill">
                        </div>

                        <?php if ($can_sell): ?>
                        <button class="btn btn-success pos-pay-btn" id="payBtn" onclick="completeSale()">
                            <i class="fas fa-circle-check"></i> Complete Sale
                        </button>
                        <?php else: ?>
                        <div class="pos-empty"><i class="fas fa-lock"></i> Your role can view the counter but not sell.</div>
                        <?php endif; ?>
                    </aside>
                </div>
            </div>

            <!-- ── bills: own tab, never under the counter ── -->
            <div class="data-section no-print tab-hidden" id="tabBills">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Recent Bills</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadSales()"><i class="fas fa-sync"></i> Reload</button>
                    </div>
                </div>

                <div class="filters-section">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()"><i class="fas fa-times-circle"></i> Clear All</button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> From</label>
                            <input type="date" id="fFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-check"></i> To</label>
                            <input type="date" id="fTo" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-flag"></i> Status</label>
                            <select id="fStatus">
                                <option value="">All bills</option>
                                <option value="completed">Completed</option>
                                <option value="due">Has balance</option>
                                <option value="void">Void</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="salesSkeleton" class="skeleton-table">
                    <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                    <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                </div>
                <div class="table-responsive initially-hidden" id="salesWrap">
                    <table id="salesTable" class="display" style="width:100%"></table>
                </div>
            </div>
        </div>
    </div>

    <!-- bill preview -->
    <div class="modal-overlay" id="billModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="billTitle"><i class="fas fa-receipt"></i> Bill</h3>
                <div class="modal-head-actions">
                    <button class="close-btn" onclick="printThermal()" title="Thermal receipt (80mm)"><i class="fas fa-receipt"></i></button>
                    <button class="close-btn" onclick="printA4()" title="A4 invoice"><i class="fas fa-print"></i></button>
                    <button class="close-btn" onclick="closeBill()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="modal-body" id="billBody"></div>
        </div>
    </div>

    <!-- A4 bill — screen-hidden, printed by printA4() -->
    <div class="sheet print-only" id="posSheet">
        <div class="sheet-header">
            <?php if ($siteLogo): ?><img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="" class="sheet-logo"><?php endif; ?>
            <div class="sheet-brand"><?php echo htmlspecialchars($siteName); ?></div>
            <div class="sheet-contact"><?php echo htmlspecialchars(trim(implode('  ·  ', array_filter([$compAddr, $compPhone, $compEmail])))); ?></div>
            <h1 class="sheet-title">Sales Invoice</h1>
        </div>
        <div class="sheet-meta">
            <div class="sheet-meta-item"><div class="sheet-meta-label">Bill No</div><div class="sheet-meta-value" id="a4Number">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Date</div><div class="sheet-meta-value" id="a4Date">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Payment</div><div class="sheet-meta-value" id="a4Method">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Customer</div><div class="sheet-meta-value" id="a4Buyer">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Phone</div><div class="sheet-meta-value" id="a4Phone">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Served By</div><div class="sheet-meta-value" id="a4Seller">—</div></div>
        </div>
        <table class="sheet-table">
            <thead><tr>
                <th style="width:8%">#</th><th>Item</th><th style="width:12%">Unit</th>
                <th class="num-cell" style="width:10%">Qty</th>
                <th class="num-cell" style="width:16%">Rate</th>
                <th class="num-cell" style="width:18%">Amount</th>
            </tr></thead>
            <tbody id="a4Body"></tbody>
            <tfoot id="a4Foot"></tfoot>
        </table>
        <div class="sheet-note" id="a4Note"></div>
        <div class="sheet-signs">
            <div class="sheet-sign">Authorised Signature</div>
            <div class="sheet-sign">Customer Signature</div>
        </div>
        <div class="sheet-footer">
            <span>Printed by <?php echo htmlspecialchars($username); ?></span>
            <span><?php echo htmlspecialchars($siteName); ?> — thank you for your business</span>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>

    <script>
    const CURRENCY = <?php echo json_encode($currency); ?>;
    const CAN_SELL = <?php echo $can_sell ? 'true' : 'false'; ?>;
    const CAN_VOID = <?php echo $can_void ? 'true' : 'false'; ?>;
    const THERMAL_CSS = <?php echo json_encode(asset('thermal.css')); ?>;

    const escapeHtml = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
    const fmtMoney = n => CURRENCY + ' ' + (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtQty   = n => (Number(n) || 0).toFixed(2).replace(/\.00$/, '');
    const fmtDate  = d => d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
    const fmtDateTime = d => d ? new Date(d).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
    const METHOD_LABEL = { cash:'Cash', card:'Card', bank_transfer:'Bank Transfer', easypaisa:'EasyPaisa', jazzcash:'JazzCash', cheque:'Cheque' };

    let catalog = [], cart = [], salesTable = null, currentBill = null, billsLoaded = false;

    // ── boot ──────────────────────────────────────────────────────────────
    $(function () {
        $.getJSON('?action=getBootstrap', function (r) {
            if (!r.success) return;
            $('#posCategory').append(r.categories.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join(''));
            $('#posCustomer').append(r.customers.map(c => `<option value="${c.id}">${escapeHtml(c.label)}</option>`).join(''));
            if (r.tax_pct > 0) { $('#tTaxPct').val(r.tax_pct); recalc(); }
            new SearchableDropdown({ selectId: 'posCategory', placeholder: 'All categories', icon: 'fas fa-layer-group', allowClear: false });
            new SearchableDropdown({ selectId: 'posCustomer', placeholder: 'Walk-in customer', icon: 'fas fa-user' });
            new SearchableDropdown({ selectId: 'posMethod',   placeholder: 'Payment method',   icon: 'fas fa-wallet', allowClear: false });
            new SearchableDropdown({ selectId: 'fStatus',     placeholder: 'All bills',        icon: 'fas fa-flag' });
            $('#posCategory').on('change', loadCatalog);
            $('#posCustomer').on('change', function () { $('#walkInBox').toggle(!this.value); loadCustomerDues(); });
        });

        let t = null;
        $('#posSearch').on('input', function () { clearTimeout(t); t = setTimeout(loadCatalog, 250); });
        $('#tDiscount, #tTaxPct, #tPaid').on('input', recalc);
        $('#fFrom, #fTo').on('change', loadSales);
        $('#fStatus').on('change', loadSales);

        $('.pos-tabs .detail-tab').on('click', function () { showTab($(this).data('postab')); });

        loadCatalog(); loadSummary();   // bills load when their tab is opened
    });

    // ── tabs ──────────────────────────────────────────────────────────────
    function showTab(tab) {
        $('.pos-tabs .detail-tab').removeClass('active').filter('[data-postab="' + tab + '"]').addClass('active');
        $('#tabCounter').toggleClass('tab-hidden', tab !== 'counter');
        $('#tabBills').toggleClass('tab-hidden', tab !== 'bills');
        if (tab !== 'bills') return;
        if (!billsLoaded) { billsLoaded = true; loadSales(); return; }
        if (salesTable) { salesTable.columns.adjust(); salesTable.responsive && salesTable.responsive.recalc(); } // measured while hidden
    }
    const billsOpen = () => !$('#tabBills').hasClass('tab-hidden');
    function refreshSales() { billsOpen() ? loadSales() : (billsLoaded = false); } // stale till the tab opens

    // ── what the picked customer already owes ─────────────────────────────
    function loadCustomerDues() {
        const id = $('#posCustomer').val() || 0, box = $('#custDueBox');
        if (!id) { box.removeClass('on clear'); return; }
        box.addClass('on').removeClass('clear');
        $('#custDueAmt').html('<i class="fas fa-spinner fa-spin"></i>');
        $('#custDueMeta').html('<span>Checking account…</span>');
        $.getJSON('?action=getCustomerDues&customer_id=' + id, function (r) {
            if (!r.success) { box.removeClass('on'); return; }
            const clear = !(r.total > 0);
            box.toggleClass('clear', clear);
            $('#custDueAmt').text(fmtMoney(r.total));
            if (clear) { $('#custDueMeta').html('<span><i class="fas fa-circle-check"></i> Account is clear — nothing pending</span>'); return; }
            const bits = [];
            if (r.invoice_due > 0) bits.push(`<span><i class="fas fa-file-invoice-dollar"></i> ${r.invoice_count} unpaid invoice${r.invoice_count === 1 ? '' : 's'} · ${fmtMoney(r.invoice_due)}</span>`);
            if (r.pos_due > 0)     bits.push(`<span><i class="fas fa-receipt"></i> ${r.pos_count} open counter bill${r.pos_count === 1 ? '' : 's'} · ${fmtMoney(r.pos_due)}</span>`);
            if (r.overdue_count)   bits.push(`<span class="od"><i class="fas fa-triangle-exclamation"></i> ${r.overdue_count} overdue</span>`);
            if (r.oldest_due)      bits.push(`<span><i class="fas fa-calendar-day"></i> Oldest due ${fmtDate(r.oldest_due)}</span>`);
            if (r.last_payment)    bits.push(`<span><i class="fas fa-hand-holding-dollar"></i> Last paid ${fmtDate(r.last_payment)}</span>`);
            $('#custDueMeta').html(bits.join(''));
        }).fail(() => box.removeClass('on'));
    }

    function loadSummary() {
        $.getJSON('?action=getSummary', function (r) {
            if (!r.success) return;
            const s = r.summary;
            $('#smToday').text(fmtMoney(s.today_total));
            $('#smBills').text(s.today_bills);
            $('#smAvg').text(fmtMoney(s.avg_bill));
            $('#smItems').text(fmtQty(s.items_sold));
            $('#smDue').text(fmtMoney(s.due_total));
            $('#smDueSub').text(s.due_bills + ' bill' + (s.due_bills === 1 ? '' : 's') + ' carrying a balance');
        });
    }

    // ── catalog ───────────────────────────────────────────────────────────
    function loadCatalog() {
        const q = $('#posSearch').val() || '', cat = $('#posCategory').val() || '';
        $('#posItems').html('<div class="pos-empty"><i class="fas fa-spinner fa-spin"></i> Loading stock…</div>');
        $.getJSON('?action=getCatalog&q=' + encodeURIComponent(q) + '&category_id=' + encodeURIComponent(cat), function (r) {
            if (!r.success) return;
            catalog = r.data;
            if (!catalog.length) {
                $('#posItems').html('<div class="pos-empty"><i class="fas fa-box-open"></i><br>No item matches this search</div>');
                return;
            }
            $('#posItems').html(catalog.map(i => `
                <button type="button" class="pos-item ${i.qty <= 0 ? 'out' : ''}" onclick="addItem(${i.id})" ${i.qty <= 0 ? 'disabled' : ''}>
                    <span class="pos-item-name">${escapeHtml(i.name)}</span>
                    <span class="pos-item-meta">${escapeHtml(i.code)}${i.category ? ' · ' + escapeHtml(i.category) : ''}</span>
                    <span class="pos-item-foot">
                        <span class="pos-item-price">${fmtMoney(i.price)}</span>
                        <span class="pos-item-qty ${i.qty <= 0 ? 'zero' : ''}">${fmtQty(i.qty)} ${escapeHtml(i.unit)}</span>
                    </span>
                </button>`).join(''));
        });
    }

    // ── cart ──────────────────────────────────────────────────────────────
    function addItem(id) {
        const it = catalog.find(x => x.id === id);
        if (!it) return;
        if (it.serial) {
            Swal.fire({ icon: 'info', title: 'Serial item',
                text: it.name + ' is serial-tracked — sell it from Stock Movements so its serial numbers are recorded.' });
            return;
        }
        const line = cart.find(l => l.id === id);
        const have = (line ? line.qty : 0) + 1;
        if (have > it.qty) {
            Swal.fire({ icon: 'warning', title: 'Not enough stock', text: `Only ${fmtQty(it.qty)} ${it.unit} of ${it.name} left in store.` });
            return;
        }
        if (line) line.qty = have;
        else cart.push({ id: it.id, code: it.code, name: it.name, unit: it.unit, price: it.price, qty: 1, max: it.qty });
        renderCart();
    }

    function setQty(id, qty) {
        const line = cart.find(l => l.id === id);
        if (!line) return;
        qty = Math.max(0, Math.round(Number(qty) * 100) / 100);
        if (qty > line.max) {
            Swal.fire({ icon: 'warning', title: 'Not enough stock', text: `Only ${fmtQty(line.max)} ${line.unit} of ${line.name} left in store.` });
            qty = line.max;
        }
        if (qty <= 0) cart = cart.filter(l => l.id !== id); else line.qty = qty;
        renderCart();
    }
    function setPrice(id, p) {
        const line = cart.find(l => l.id === id);
        if (line) { line.price = Math.max(0, Number(p) || 0); recalc(); }
    }
    const dropLine = id => { cart = cart.filter(l => l.id !== id); renderCart(); };

    function clearCart() {
        if (!cart.length) return;
        cart = []; renderCart();
        $('#tDiscount, #tPaid').val(0); $('#posNotes').val(''); $('#walkName, #walkPhone').val('');
        recalc();
    }

    function renderCart() {
        $('#cartCount').text(cart.length);
        if (!cart.length) {
            $('#posLines').html('<div class="pos-empty"><i class="fas fa-cart-shopping"></i><br>Tap an item to start the bill</div>');
            recalc(); return;
        }
        $('#posLines').html(cart.map(l => `
            <div class="pos-line">
                <div class="pos-line-top">
                    <span class="pos-line-name">${escapeHtml(l.name)}</span>
                    <button type="button" class="pos-line-del" onclick="dropLine(${l.id})" title="Remove"><i class="fas fa-xmark"></i></button>
                </div>
                <div class="pos-line-grid">
                    <div class="pos-step">
                        <button type="button" onclick="setQty(${l.id}, ${l.qty} - 1)"><i class="fas fa-minus"></i></button>
                        <input type="number" min="0" step="0.01" value="${l.qty}" onchange="setQty(${l.id}, this.value)">
                        <button type="button" onclick="setQty(${l.id}, ${l.qty} + 1)"><i class="fas fa-plus"></i></button>
                    </div>
                    <input type="number" class="pos-rate" min="0" step="0.01" value="${l.price}" onchange="setPrice(${l.id}, this.value)" title="Rate">
                    <span class="pos-line-total">${fmtMoney(l.qty * l.price)}</span>
                </div>
            </div>`).join(''));
        recalc();
    }

    function totals() {
        const sub  = cart.reduce((a, l) => a + l.qty * l.price, 0);
        const disc = Math.min(Number($('#tDiscount').val()) || 0, sub);
        const pct  = Number($('#tTaxPct').val()) || 0;
        const tax  = Math.round((sub - disc) * pct) / 100;
        const total = Math.round((sub - disc + tax) * 100) / 100;
        const paid = Number($('#tPaid').val()) || 0;
        return { sub, disc, pct, tax, total, paid, change: Math.max(0, paid - total), due: Math.max(0, total - paid) };
    }

    function recalc() {
        const t = totals();
        $('#tSub').text(fmtMoney(t.sub));
        $('#tTax').text(fmtMoney(t.tax));
        $('#tTotal').text(fmtMoney(t.total));
        $('#tChangeLabel').text(t.due > 0 ? 'Balance due' : 'Change');
        $('#tChange').text(fmtMoney(t.due > 0 ? t.due : t.change)).toggleClass('due', t.due > 0);
    }

    function completeSale() {
        if (!CAN_SELL) return;
        const t = totals();
        if (!cart.length) { Swal.fire({ icon: 'info', title: 'Cart is empty', text: 'Add at least one item to the bill.' }); return; }
        if (t.due > 0 && !$('#posCustomer').val()) {
            Swal.fire({ icon: 'warning', title: 'Balance needs an account',
                text: 'A part-paid bill has to sit on a registered customer. Pick a customer or take the full amount.' });
            return;
        }
        const btn = document.getElementById('payBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
        Swal.fire({ title: 'Completing sale…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.post('?action=saveSale', {
            lines: JSON.stringify(cart.map(l => ({ item_id: l.id, qty: l.qty, unit_price: l.price }))),
            customer_id: $('#posCustomer').val() || 0,
            walk_in_name: $('#walkName').val(), walk_in_phone: $('#walkPhone').val(),
            discount: t.disc, tax_pct: t.pct, paid: t.paid,
            payment_method: $('#posMethod').val(), notes: $('#posNotes').val()
        }, function (r) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-circle-check"></i> Complete Sale';
            if (!r.success) { Swal.fire({ icon: 'error', title: 'Sale not saved', text: r.message || 'Something went wrong' }); return; }
            Swal.close();
            clearCart(); loadCatalog(); loadSummary(); refreshSales(); loadCustomerDues();
            openBill(r.id, true);
        }, 'json').fail(function () {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-circle-check"></i> Complete Sale';
            Swal.fire({ icon: 'error', title: 'Connection error', text: 'The sale was not saved.' });
        });
    }

    // ── bills ─────────────────────────────────────────────────────────────
    function clearFilters() { $('#fFrom, #fTo').val(''); $('#fStatus').val(''); loadSales(); }

    function loadSales() {
        $('#salesSkeleton').show(); $('#salesWrap').addClass('initially-hidden');
        const p = { from: $('#fFrom').val() || '', to: $('#fTo').val() || '', status: $('#fStatus').val() || '' };
        $.getJSON('?action=getSales&from=' + p.from + '&to=' + p.to + '&status=' + p.status, function (r) {
            $('#salesSkeleton').hide(); $('#salesWrap').removeClass('initially-hidden');
            if (!r.success) return;
            if (salesTable) { salesTable.destroy(); $('#salesTable').empty(); }
            salesTable = $('#salesTable').DataTable({
                data: r.data, pageLength: 10, responsive: true, autoWidth: false, order: [[0, 'desc']],
                language: { emptyTable: 'No bills yet — the first sale shows up here.' },
                columns: [
                    { data: 'number', title: 'Bill #', render: (d, t, row) =>
                        `<strong>${escapeHtml(d)}</strong>${row.status === 'void' ? ' <span class="inv-status cancelled">VOID</span>' : ''}` },
                    { data: 'date', title: 'Date', render: d => fmtDateTime(d) },
                    { data: 'buyer', title: 'Customer', render: (d, t, row) =>
                        escapeHtml(d) + (row.code ? `<div class="muted">${escapeHtml(row.code)}</div>` : '') },
                    { data: 'lines', title: 'Items', className: 'num-cell' },
                    { data: 'total', title: 'Total', className: 'num-cell', render: d => fmtMoney(d) },
                    { data: 'paid',  title: 'Paid',  className: 'num-cell', render: d => fmtMoney(d) },
                    { data: 'due',   title: 'Balance', className: 'num-cell', render: d =>
                        d > 0 ? `<span class="outstanding-badge due">${fmtMoney(d)}</span>` : '<span class="outstanding-badge zero">—</span>' },
                    { data: 'method', title: 'Method', render: d => escapeHtml(METHOD_LABEL[d] || d) },
                    { data: 'sold_by', title: 'Sold By', render: d => escapeHtml(d || '—') },
                    { data: null, title: 'Actions', orderable: false, className: 'actions-cell', render: (d, t, row) => {
                        let h = `<button class="action-icon" onclick="openBill(${row.id})" title="View bill"><i class="fas fa-eye"></i></button>`;
                        h += `<button class="action-icon" onclick="openBill(${row.id}, false, 'thermal')" title="Thermal receipt"><i class="fas fa-receipt"></i></button>`;
                        h += `<button class="action-icon" onclick="openBill(${row.id}, false, 'a4')" title="A4 invoice"><i class="fas fa-print"></i></button>`;
                        if (CAN_VOID && row.status !== 'void')
                            h += `<button class="action-icon danger" onclick="voidSale(${row.id}, '${escapeHtml(row.number)}')" title="Void bill"><i class="fas fa-ban"></i></button>`;
                        return h;
                    } }
                ]
            });
        });
    }

    function openBill(id, justSold, printMode) {
        $.getJSON('?action=getSaleDetail&id=' + id, function (r) {
            if (!r.success) { Swal.fire({ icon: 'error', title: 'Not found', text: r.message || '' }); return; }
            currentBill = r.sale;
            if (printMode === 'thermal') { printThermal(); return; }
            if (printMode === 'a4')      { printA4(); return; }
            renderBillModal(justSold);
        });
    }
    function closeBill() { document.getElementById('billModal').classList.remove('active'); }
    document.getElementById('billModal').addEventListener('click', function (e) { if (e.target === this) closeBill(); });

    function renderBillModal(justSold) {
        const s = currentBill;
        $('#billTitle').html(`<i class="fas fa-receipt"></i> ${escapeHtml(s.number)}${s.status === 'void' ? ' <span class="inv-status cancelled">VOID</span>' : ''}`);
        const rows = s.items.map((it, i) => `<tr>
            <td>${i + 1}</td><td>${escapeHtml(it.name)}<div class="muted">${escapeHtml(it.code || '')}</div></td>
            <td class="num-cell">${fmtQty(it.qty)} ${escapeHtml(it.unit || '')}</td>
            <td class="num-cell">${fmtMoney(it.price)}</td>
            <td class="num-cell">${fmtMoney(it.total)}</td></tr>`).join('');
        $('#billBody').html(`
            ${justSold ? '<div class="pos-sold-banner"><i class="fas fa-circle-check"></i> Sale completed — print the bill or hand it over.</div>' : ''}
            <div class="vtab-grid">
                <div class="vtab-field"><label>Customer</label><span>${escapeHtml(s.buyer)}</span></div>
                <div class="vtab-field"><label>Phone</label><span>${escapeHtml(s.phone || '—')}</span></div>
                <div class="vtab-field"><label>Date</label><span>${fmtDateTime(s.date)}</span></div>
                <div class="vtab-field"><label>Payment</label><span>${escapeHtml(METHOD_LABEL[s.method] || s.method)} · ${escapeHtml(s.pay_status)}</span></div>
                <div class="vtab-field"><label>Served By</label><span>${escapeHtml(s.sold_by || '—')}</span></div>
                <div class="vtab-field"><label>Note</label><span>${escapeHtml(s.notes || '—')}</span></div>
            </div>
            <table class="ledger-table">
                <thead><tr><th>#</th><th>Item</th><th class="num-cell">Qty</th><th class="num-cell">Rate</th><th class="num-cell">Amount</th></tr></thead>
                <tbody>${rows}</tbody>
                <tfoot>
                    <tr><th colspan="4" class="dt-foot-label">Subtotal</th><th class="num-cell">${fmtMoney(s.subtotal)}</th></tr>
                    ${s.discount ? `<tr><th colspan="4" class="dt-foot-label">Discount</th><th class="num-cell">- ${fmtMoney(s.discount)}</th></tr>` : ''}
                    ${s.tax ? `<tr><th colspan="4" class="dt-foot-label">Tax (${s.tax_pct}%)</th><th class="num-cell">${fmtMoney(s.tax)}</th></tr>` : ''}
                    <tr><th colspan="4" class="dt-foot-label">Total</th><th class="num-cell">${fmtMoney(s.total)}</th></tr>
                    <tr><th colspan="4" class="dt-foot-label">Paid</th><th class="num-cell">${fmtMoney(s.paid)}</th></tr>
                    ${s.due ? `<tr><th colspan="4" class="dt-foot-label">Balance</th><th class="num-cell">${fmtMoney(s.due)}</th></tr>`
                            : `<tr><th colspan="4" class="dt-foot-label">Change</th><th class="num-cell">${fmtMoney(s.change)}</th></tr>`}
                </tfoot>
            </table>`);
        document.getElementById('billModal').classList.add('active');
    }

    function voidSale(id, number) {
        Swal.fire({ icon: 'warning', title: 'Void ' + number + '?',
            text: 'The items go back into stock and the ledger entries are reversed.',
            input: 'text', inputPlaceholder: 'Reason for voiding', showCancelButton: true,
            confirmButtonText: 'Void bill', confirmButtonColor: '#ef4444',
            inputValidator: v => !v && 'A reason is required'
        }).then(res => {
            if (!res.isConfirmed) return;
            Swal.fire({ title: 'Voiding…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.post('?action=voidSale', { id: id, reason: res.value }, function (r) {
                if (!r.success) { Swal.fire({ icon: 'error', title: 'Not voided', text: r.message || '' }); return; }
                Swal.fire({ icon: 'success', title: 'Bill voided', timer: 1300, showConfirmButton: false });
                refreshSales(); loadSummary(); loadCatalog();
            }, 'json');
        });
    }

    // ── printing ──────────────────────────────────────────────────────────
    function printA4() {
        const s = currentBill;
        if (!s) return;
        $('#a4Number').text(s.number);
        $('#a4Date').text(fmtDateTime(s.date));
        $('#a4Method').text(METHOD_LABEL[s.method] || s.method);
        $('#a4Buyer').text(s.buyer + (s.code ? ' (' + s.code + ')' : ''));
        $('#a4Phone').text(s.phone || '—');
        $('#a4Seller').text(s.sold_by || '—');
        $('#a4Body').html(s.items.map((it, i) => `<tr>
            <td>${i + 1}</td><td>${escapeHtml(it.name)}${it.code ? ' <span class="muted">' + escapeHtml(it.code) + '</span>' : ''}</td>
            <td>${escapeHtml(it.unit || '')}</td>
            <td class="num-cell">${fmtQty(it.qty)}</td>
            <td class="num-cell">${fmtMoney(it.price)}</td>
            <td class="num-cell">${fmtMoney(it.total)}</td></tr>`).join(''));
        $('#a4Foot').html(`
            <tr><th colspan="5">Subtotal</th><th class="num-cell">${fmtMoney(s.subtotal)}</th></tr>
            ${s.discount ? `<tr><th colspan="5">Discount</th><th class="num-cell">- ${fmtMoney(s.discount)}</th></tr>` : ''}
            ${s.tax ? `<tr><th colspan="5">Tax (${s.tax_pct}%)</th><th class="num-cell">${fmtMoney(s.tax)}</th></tr>` : ''}
            <tr class="sheet-total"><th colspan="5">Total Payable</th><th class="num-cell">${fmtMoney(s.total)}</th></tr>
            <tr><th colspan="5">Paid (${escapeHtml(METHOD_LABEL[s.method] || s.method)})</th><th class="num-cell">${fmtMoney(s.paid)}</th></tr>
            <tr><th colspan="5">${s.due ? 'Balance Due' : 'Change Returned'}</th><th class="num-cell">${fmtMoney(s.due || s.change)}</th></tr>`);
        $('#a4Note').text(s.status === 'void' ? 'THIS BILL HAS BEEN VOIDED.'
                        : s.due ? 'Balance of ' + fmtMoney(s.due) + ' remains on the customer account.'
                        : 'Paid in full — thank you.');
        window.print();
    }

    // thermal — the shared builder in page_ui.js owns the 80mm document
    function printThermal() {
        const s = currentBill;
        if (!s) return;
        rsThermalPrint({
            title: 'SALES RECEIPT',
            meta: [['Bill', s.number], ['Date', fmtDateTime(s.date)], ['Customer', s.buyer], ['Served by', s.sold_by || '—']],
            items: s.items.map(it => ({ name: it.name, qty: fmtQty(it.qty), unit: it.unit, price: fmtMoney(it.price), total: fmtMoney(it.total) })),
            sums: [
                ['Subtotal', fmtMoney(s.subtotal)],
                s.discount ? ['Discount', '- ' + fmtMoney(s.discount)] : null,
                s.tax ? ['Tax (' + s.tax_pct + '%)', fmtMoney(s.tax)] : null,
                ['TOTAL', fmtMoney(s.total), 'grand'],
                ['Paid (' + (METHOD_LABEL[s.method] || s.method) + ')', fmtMoney(s.paid)],
                [s.due ? 'Balance' : 'Change', fmtMoney(s.due || s.change)]
            ],
            note: s.status === 'void' ? '*** VOIDED BILL ***' : '',
            footer: 'Thank you for your business<br>Goods once sold are checked at counter'
        });
    }

    </script>
</body>
</html>
