<?php
 
require_once 'config.php';
require_once 'import_helper.php';

requirePerm('stock_movements', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'stock_movements';
$can_add      = can('stock_movements', 'a');
$can_edit     = can('stock_movements', 'e');
$can_delete   = can('stock_movements', 'd');
$currency     = getSetting('currency_symbol', '$');

// type => [label, icon, direction, doc prefix]
$MOVE_TYPES = [
    'purchase_in' => ['Purchase In',  'fas fa-cart-arrow-down', 'in',  'GRN-'],
    'issue_out'   => ['Issue Out',    'fas fa-hand-holding-box','out', 'ISS-'],
    'return_in'   => ['Return In',    'fas fa-rotate-left',     'in',  'RTN-'],
    'damage_out'  => ['Damage / Loss','fas fa-triangle-exclamation','out','DMG-'],
    'adjustment'  => ['Adjustment',   'fas fa-sliders',         'in',  'ADJ-'],
];

// csv import/template contract — one single-item movement per CSV row (a row can't express a multi-line doc)
function stockMovementImportSpec() {
    global $MOVE_TYPES;
    $uid = $_SESSION['user_id'] ?? null;

    return [
        'entity'  => 'Stock Movement',
        'table'   => 'stock_movements',
        'perm'    => 'stock_movements',
        'unique'  => ['movement_number'], // real UNIQUE KEY uk_movement_number
        'columns' => [
            'movement_type'     => ['label' => 'Movement Type', 'type' => 'enum', 'values' => array_keys($MOVE_TYPES), 'required' => true, 'demo' => 'purchase_in'],
            'movement_date'     => ['label' => 'Movement Date', 'type' => 'date', 'required' => true, 'demo' => '2026-01-15'],
            'vendor_id'         => ['label' => 'Vendor (name or code)', 'type' => 'lookup',
                                     'lookup' => ['table' => 'vendors', 'match' => ['vendor_name', 'vendor_code'], 'where' => "is_deleted = 0 AND status = 'active'"],
                                     'demo' => 'Demo Vendor 1'],
            'issued_to_user_id' => ['label' => 'Issue To (username)', 'type' => 'lookup',
                                     'lookup' => ['table' => 'users', 'match' => ['username', 'name'], 'where' => "is_deleted = 0 AND status = 'active'"],
                                     'demo' => ''],
            'customer_id'       => ['label' => 'Customer (code or name)', 'type' => 'lookup',
                                     'lookup' => ['table' => 'customers', 'match' => ['customer_code', 'full_name'], 'where' => "is_deleted = 0"],
                                     'demo' => ''],
            'area_id'           => ['label' => 'Area', 'type' => 'lookup',
                                     'lookup' => ['table' => 'areas', 'match' => ['name'], 'where' => "is_deleted = 0 AND status = 'active'"],
                                     'demo' => 'Demo Area 1'],
            'reference'         => ['label' => 'Reference', 'type' => 's', 'max' => 100, 'demo' => ''],
            'bill_number'       => ['label' => 'Vendor Bill #', 'type' => 's', 'max' => 60, 'demo' => ''],
            'notes'             => ['label' => 'Notes', 'type' => 's', 'demo' => ''],
            'movement_number'   => ['label' => 'Doc #', 'type' => 's', 'max' => 20, 'demo' => ''],
            // the one line this row carries — NOT stock_movements columns, stripped in before() and
            // carried to after() through a $state queue (see below)
            'item_id'           => ['label' => 'Item (code or name)', 'type' => 'lookup', 'required' => true,
                                     'lookup' => ['table' => 'stock_items', 'match' => ['item_code', 'name'], 'where' => "is_deleted = 0 AND status = 'active'"],
                                     'demo' => 'Demo Item 1'],
            'qty'               => ['label' => 'Quantity', 'type' => 'd', 'required' => true, 'demo' => '10'],
            'unit_price'        => ['label' => 'Rate', 'type' => 'd', 'default' => 0, 'demo' => '100'],
        ],
        'static' => [
            'status'              => 'posted',
            'created_by_user_id'  => $uid,
        ],
        'init' => function ($conn, &$state) use ($MOVE_TYPES) {
            // running stock snapshot, updated in-memory row by row — never re-queried per row
            $state['items'] = [];
            $r = $conn->query("SELECT id, name, tracking_type, current_qty FROM stock_items WHERE is_deleted = 0 AND status = 'active'");
            while ($x = $r->fetch_assoc())
                $state['items'][(int)$x['id']] = ['name' => $x['name'], 'track' => $x['tracking_type'], 'qty' => (float)$x['current_qty']];

            // doc-number sequence per movement-type prefix, seeded ONCE
            $state['seq'] = [];
            foreach ($MOVE_TYPES as $def) {
                $next = nextDocNumber($conn, 'stock_movements', 'movement_number', $def[3]);
                $state['seq'][$def[3]] = (int)substr($next, strlen($def[3]));
            }
            $state['lines'] = []; // FIFO queue: before() appends, after() shifts — see note in before()
        },
        // NOTE: the engine validates every row (calling before() for each) BEFORE it inserts any of them
        // (two separate passes — see import_helper.php). So the line data can't ride in a single $state
        // slot; it's queued in before() and shifted off in after() in the same order rows are inserted.
        'before' => function (&$row, $conn, &$state) use ($MOVE_TYPES) {
            $type   = $row['movement_type'];
            $dir    = $MOVE_TYPES[$type][2];
            $prefix = $MOVE_TYPES[$type][3];

            if ($type === 'purchase_in' && !$row['vendor_id'])
                return 'Select the vendor this stock came from';
            if ($type === 'issue_out' && !$row['issued_to_user_id'] && !$row['customer_id'])
                return 'Issue to a staff member or a customer';
            if ($row['area_id'] && !areaAllowed($row['area_id']))
                return 'That area is outside your scope';

            $itemId = (int)$row['item_id'];
            $item   = $state['items'][$itemId] ?? null;
            if (!$item) return 'Unknown item on this row';
            if ($item['track'] === 'serial')
                return "{$item['name']} is serial-tracked — record it directly on the Stock Movements page";

            $qty  = round((float)$row['qty'], 2);
            $rate = round((float)$row['unit_price'], 2);
            if ($qty == 0)                            return 'Quantity is required';
            if ($type !== 'adjustment' && $qty < 0)   return 'Quantity cannot be negative';
            if ($rate < 0)                             return 'Rate cannot be negative';

            // same guard addMovement enforces — only for stock going OUT; adjustment carries its own sign, unguarded
            if ($dir === 'out' && $qty > $item['qty'])
                return "Only " . rtrim(rtrim(number_format($item['qty'], 2, '.', ''), '0'), '.') . " of {$item['name']} left in store";

            // simulate the effect so the next row for the same item sees the right running balance
            $state['items'][$itemId]['qty'] += ($dir === 'out') ? -$qty : $qty;

            // auto doc number when blank — sequential per prefix, no per-row query
            if (empty($row['movement_number'])) {
                $state['seq'][$prefix]++;
                $row['movement_number'] = $prefix . str_pad($state['seq'][$prefix], 4, '0', STR_PAD_LEFT);
            }

            $row['total_amount'] = round(abs($qty) * $rate, 2);

            $state['lines'][] = ['item_id' => $itemId, 'qty' => $qty, 'unit_price' => $rate, 'line_total' => $row['total_amount']];
            unset($row['item_id'], $row['qty'], $row['unit_price']); // not stock_movements columns
            return null;
        },
        'after' => function ($id, $row, $conn, &$state) {
            $ln = array_shift($state['lines']); // same order before() queued them in

            $li = $conn->prepare("INSERT INTO stock_movement_items (movement_id, item_id, qty, unit_price, line_total) VALUES (?,?,?,?,?)");
            $li->bind_param("iiddd", $id, $ln['item_id'], $ln['qty'], $ln['unit_price'], $ln['line_total']);
            $li->execute();
            $li->close();

            recomputeItemStock($conn, $ln['item_id']);

            // same vendor ledger posting addMovement does for purchase/return
            if ($row['vendor_id'] && $row['total_amount'] > 0) {
                $uid = $_SESSION['user_id'] ?? null;
                if ($row['movement_type'] === 'purchase_in')
                    postLedger($conn, 'vendor', $row['vendor_id'], $row['movement_date'], "Stock purchase {$row['movement_number']}", 0, $row['total_amount'], 'stock_purchase', $id, $row['movement_number'], $uid);
                elseif ($row['movement_type'] === 'return_in')
                    postLedger($conn, 'vendor', $row['vendor_id'], $row['movement_date'], "Stock returned to vendor {$row['movement_number']}", $row['total_amount'], 0, 'stock_return', $id, $row['movement_number'], $uid);
            }
        },
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'importCsv':      rsImportRun($conn, stockMovementImportSpec());
            case 'importTemplate': rsImportTemplate(stockMovementImportSpec());

            case 'getMovements': {
                $type = $_GET['movement_type'] ?? '';
                $from = $_GET['from'] ?? '';
                $to   = $_GET['to'] ?? '';
                $q    = trim($_GET['q'] ?? '');

                $where = "m.is_deleted = 0";
                $types = ''; $vals = [];
                if (isset($MOVE_TYPES[$type]))                  { $where .= " AND m.movement_type = ?"; $types .= "s"; $vals[] = $type; }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= " AND m.movement_date >= ?"; $types .= "s"; $vals[] = $from; }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where .= " AND m.movement_date <= ?"; $types .= "s"; $vals[] = $to; }
                if ($q !== '') {
                    $where .= " AND (m.movement_number LIKE ? OR m.reference LIKE ? OR m.bill_number LIKE ? OR v.vendor_name LIKE ? OR u.name LIKE ?)";
                    $types .= "sssss";
                    $like = "%$q%";
                    array_push($vals, $like, $like, $like, $like, $like);
                }
                // area-locked roles only see their own areas' issues
                $where .= areaScope('m');

                $sql = "SELECT m.*, v.vendor_name, u.name AS staff_name, c.full_name AS customer_name, a.name AS area_name,
                               (SELECT COUNT(*) FROM stock_movement_items mi WHERE mi.movement_id = m.id) AS line_count,
                               (SELECT COALESCE(SUM(mi.qty),0) FROM stock_movement_items mi WHERE mi.movement_id = m.id) AS total_qty
                        FROM stock_movements m
                        LEFT JOIN vendors v   ON v.id = m.vendor_id
                        LEFT JOIN users u     ON u.id = m.issued_to_user_id
                        LEFT JOIN customers c ON c.id = m.customer_id
                        LEFT JOIN areas a     ON a.id = m.area_id
                        WHERE $where
                        ORDER BY m.movement_date DESC, m.id DESC";
                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$vals);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'              => (int)$r['id'],
                        'movement_number' => $r['movement_number'],
                        'movement_type'   => $r['movement_type'],
                        'movement_date'   => $r['movement_date'],
                        'party'           => $r['vendor_name'] ?: ($r['staff_name'] ?: ($r['customer_name'] ?: '—')),
                        'area_name'       => $r['area_name'],
                        'reference'       => $r['reference'],
                        'bill_number'     => $r['bill_number'],
                        'line_count'      => (int)$r['line_count'],
                        'total_qty'       => number_format((float)$r['total_qty'], 2, '.', ''),
                        'total_amount'    => number_format((float)$r['total_amount'], 2, '.', ''),
                        'status'          => $r['status'],
                        'notes'           => $r['notes'],
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows, 'currency' => $currency]);
                exit();
            }

            case 'getMovementDetail': {
                $id = (int)($_GET['id'] ?? 0);
                $stmt = $conn->prepare("SELECT m.*, v.vendor_name, u.name AS staff_name, c.full_name AS customer_name,
                                               a.name AS area_name, cu.name AS created_by_name
                                        FROM stock_movements m
                                        LEFT JOIN vendors v   ON v.id = m.vendor_id
                                        LEFT JOIN users u     ON u.id = m.issued_to_user_id
                                        LEFT JOIN customers c ON c.id = m.customer_id
                                        LEFT JOIN areas a     ON a.id = m.area_id
                                        LEFT JOIN users cu    ON cu.id = m.created_by_user_id
                                        WHERE m.id = ? AND m.is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $m = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$m) { echo json_encode(['success' => false, 'message' => 'Movement not found']); exit(); }

                $lines = [];
                $l = $conn->prepare("SELECT mi.*, i.item_code, i.name, i.unit, i.tracking_type
                                     FROM stock_movement_items mi
                                     JOIN stock_items i ON i.id = mi.item_id
                                     WHERE mi.movement_id = ? ORDER BY mi.id ASC");
                $l->bind_param("i", $id);
                $l->execute();
                $lr = $l->get_result();
                while ($x = $lr->fetch_assoc()) {
                    $lines[] = [
                        'item_code' => $x['item_code'], 'name' => $x['name'], 'unit' => $x['unit'],
                        'tracking_type' => $x['tracking_type'],
                        'qty' => number_format((float)$x['qty'], 2, '.', ''),
                        'unit_price' => number_format((float)$x['unit_price'], 2, '.', ''),
                        'line_total' => number_format((float)$x['line_total'], 2, '.', ''),
                        'serials' => $x['serials'],
                    ];
                }
                $l->close();
                echo json_encode(['success' => true, 'data' => $m, 'lines' => $lines, 'currency' => $currency]);
                exit();
            }

            case 'getFormData': {
                $items = [];
                $r = $conn->query("SELECT id, item_code, name, unit, tracking_type, purchase_price, sale_price, current_qty
                                   FROM stock_items WHERE is_deleted = 0 AND status = 'active' ORDER BY name ASC");
                while ($x = $r->fetch_assoc()) {
                    $items[] = ['id' => (int)$x['id'], 'code' => $x['item_code'], 'name' => $x['name'], 'unit' => $x['unit'],
                                'track' => $x['tracking_type'], 'buy' => number_format((float)$x['purchase_price'], 2, '.', ''),
                                'sell' => number_format((float)$x['sale_price'], 2, '.', ''),
                                'qty' => number_format((float)$x['current_qty'], 2, '.', '')];
                }
                $vendors = [];
                try {
                    $r = $conn->query("SELECT id, vendor_code, vendor_name FROM vendors WHERE is_deleted = 0 AND status = 'active' ORDER BY vendor_name ASC");
                    if ($r) while ($x = $r->fetch_assoc()) $vendors[] = ['id' => (int)$x['id'], 'name' => $x['vendor_name'] . ' (' . $x['vendor_code'] . ')'];
                } catch (Throwable $e) {}
                $staff = [];
                $r = $conn->query("SELECT id, name, username FROM users WHERE is_deleted = 0 AND status = 'active' ORDER BY name ASC");
                while ($x = $r->fetch_assoc()) $staff[] = ['id' => (int)$x['id'], 'name' => $x['name'] ?: $x['username']];
                $areas = [];
                $r = $conn->query("SELECT id, name FROM areas WHERE is_deleted = 0 AND status = 'active' ORDER BY name ASC");
                while ($x = $r->fetch_assoc()) $areas[] = ['id' => (int)$x['id'], 'name' => $x['name']];
                $customers = [];
                $scope = areaScope('customers');
                $r = $conn->query("SELECT id, customer_code, full_name FROM customers WHERE is_deleted = 0 $scope ORDER BY full_name ASC LIMIT 2000");
                while ($x = $r->fetch_assoc()) $customers[] = ['id' => (int)$x['id'], 'name' => $x['full_name'] . ' (' . $x['customer_code'] . ')'];

                echo json_encode(['success' => true, 'items' => $items, 'vendors' => $vendors, 'staff' => $staff, 'areas' => $areas, 'customers' => $customers]);
                exit();
            }

            // serials currently in store for an item — the issue/damage picker reads this
            case 'getAvailableSerials': {
                $itemId = (int)($_GET['item_id'] ?? 0);
                $rows = [];
                $s = $conn->prepare("SELECT serial FROM equipment WHERE item_id = ? AND is_deleted = 0 AND status = 'in_stock' ORDER BY serial ASC LIMIT 500");
                $s->bind_param("i", $itemId);
                $s->execute();
                $sr = $s->get_result();
                while ($x = $sr->fetch_assoc()) $rows[] = $x['serial'];
                $s->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'addMovement': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                if (!$can_add) { echo json_encode(['success' => false, 'message' => 'You cannot record stock movements']); exit(); }

                $type   = $_POST['movement_type'] ?? '';
                $date   = $_POST['movement_date'] ?? '';
                $vendor = ($_POST['vendor_id'] ?? '') !== '' ? (int)$_POST['vendor_id'] : null;
                $staff  = ($_POST['issued_to_user_id'] ?? '') !== '' ? (int)$_POST['issued_to_user_id'] : null;
                $cust   = ($_POST['customer_id'] ?? '') !== '' ? (int)$_POST['customer_id'] : null;
                $area   = ($_POST['area_id'] ?? '') !== '' ? (int)$_POST['area_id'] : null;
                $ref    = trim($_POST['reference'] ?? '');
                $bill   = trim($_POST['bill_number'] ?? '');
                $notes  = trim($_POST['notes'] ?? '');
                $lines  = json_decode($_POST['lines'] ?? '', true);

                if (!isset($MOVE_TYPES[$type]))                  { echo json_encode(['success' => false, 'message' => 'Invalid movement type']); exit(); }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['success' => false, 'message' => 'Invalid date']); exit(); }
                if (!is_array($lines) || !count($lines))         { echo json_encode(['success' => false, 'message' => 'Add at least one item line']); exit(); }
                if ($type === 'purchase_in' && !$vendor)         { echo json_encode(['success' => false, 'message' => 'Select the vendor this stock came from']); exit(); }
                if ($type === 'issue_out' && !$staff && !$cust)  { echo json_encode(['success' => false, 'message' => 'Issue to a staff member or a customer']); exit(); }
                if ($area !== null && !areaAllowed($area))       { echo json_encode(['success' => false, 'message' => 'That area is outside your scope']); exit(); }

                $dir = $MOVE_TYPES[$type][2];

                // ── validate every line before writing anything ──
                $clean = [];
                foreach ($lines as $ln) {
                    $itemId = (int)($ln['item_id'] ?? 0);
                    $qty    = round((float)($ln['qty'] ?? 0), 2);
                    $rate   = round((float)($ln['unit_price'] ?? 0), 2);
                    $serials = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($ln['serials'] ?? '')))));

                    if ($itemId <= 0) { echo json_encode(['success' => false, 'message' => 'A line has no item selected']); exit(); }
                    $g = $conn->prepare("SELECT id, item_code, name, tracking_type, current_qty, equipment_type, brand, model FROM stock_items WHERE id = ? AND is_deleted = 0");
                    $g->bind_param("i", $itemId);
                    $g->execute();
                    $item = $g->get_result()->fetch_assoc();
                    $g->close();
                    if (!$item) { echo json_encode(['success' => false, 'message' => 'Unknown item on a line']); exit(); }

                    if ($item['tracking_type'] === 'serial') {
                        if (!count($serials)) { echo json_encode(['success' => false, 'message' => "{$item['name']} is serial-tracked — enter its serial numbers"]); exit(); }
                        if (count($serials) !== count(array_unique($serials))) { echo json_encode(['success' => false, 'message' => "Duplicate serials entered for {$item['name']}"]); exit(); }
                        $qty = count($serials); // the serials ARE the quantity
                        if ($type === 'adjustment') { echo json_encode(['success' => false, 'message' => 'Serial items cannot be adjusted by quantity — use a return or damage entry']); exit(); }

                        foreach ($serials as $sn) {
                            $q = $conn->prepare("SELECT id, item_id, status FROM equipment WHERE serial = ? AND is_deleted = 0");
                            $q->bind_param("s", $sn);
                            $q->execute();
                            $eq = $q->get_result()->fetch_assoc();
                            $q->close();
                            if ($dir === 'in' && $type === 'purchase_in') {
                                if ($eq) { echo json_encode(['success' => false, 'message' => "Serial $sn already exists in the system"]); exit(); }
                            } else {
                                if (!$eq) { echo json_encode(['success' => false, 'message' => "Serial $sn was never received into stock"]); exit(); }
                                if ((int)$eq['item_id'] !== $itemId) { echo json_encode(['success' => false, 'message' => "Serial $sn belongs to a different item"]); exit(); }
                                if ($dir === 'out' && $eq['status'] !== 'in_stock') { echo json_encode(['success' => false, 'message' => "Serial $sn is not in store (status: {$eq['status']})"]); exit(); }
                                if ($type === 'return_in' && $eq['status'] === 'in_stock') { echo json_encode(['success' => false, 'message' => "Serial $sn is already in store"]); exit(); }
                            }
                        }
                    } else {
                        if ($qty == 0) { echo json_encode(['success' => false, 'message' => "Quantity is required for {$item['name']}"]); exit(); }
                        if ($type !== 'adjustment' && $qty < 0) { echo json_encode(['success' => false, 'message' => 'Quantity cannot be negative']); exit(); }
                        if ($dir === 'out' && $qty > (float)$item['current_qty']) {
                            echo json_encode(['success' => false, 'message' => "Only " . rtrim(rtrim(number_format((float)$item['current_qty'], 2, '.', ''), '0'), '.') . " of {$item['name']} left in store"]);
                            exit();
                        }
                    }
                    if ($rate < 0) { echo json_encode(['success' => false, 'message' => 'Rate cannot be negative']); exit(); }
                    $clean[] = ['item' => $item, 'qty' => $qty, 'rate' => $rate, 'serials' => $serials];
                }

                // ── write ──
                $conn->begin_transaction();
                try {
                    $number = nextDocNumber($conn, 'stock_movements', 'movement_number', $MOVE_TYPES[$type][3]);
                    $total  = 0.0;
                    foreach ($clean as $c) $total += abs($c['qty']) * $c['rate'];

                    $refBind  = $ref === '' ? null : $ref;
                    $billBind = $bill === '' ? null : $bill;
                    $noteBind = $notes === '' ? null : $notes;

                    $h = $conn->prepare("INSERT INTO stock_movements (movement_number, movement_type, movement_date, vendor_id,
                            issued_to_user_id, customer_id, area_id, reference, bill_number, total_amount, notes, created_by_user_id)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                    $h->bind_param("sssiiiissdsi", $number, $type, $date, $vendor, $staff, $cust, $area, $refBind, $billBind, $total, $noteBind, $user_id);
                    $h->execute();
                    $mid = $conn->insert_id;
                    $h->close();

                    $li = $conn->prepare("INSERT INTO stock_movement_items (movement_id, item_id, qty, unit_price, line_total, serials) VALUES (?,?,?,?,?,?)");
                    foreach ($clean as $c) {
                        $lt  = abs($c['qty']) * $c['rate'];
                        $ser = $c['serials'] ? implode(',', $c['serials']) : null;
                        $iid = (int)$c['item']['id'];
                        $li->bind_param("iiddds", $mid, $iid, $c['qty'], $c['rate'], $lt, $ser);
                        $li->execute();

                        // serial units live in equipment — keep them in step with the movement
                        foreach ($c['serials'] as $sn) {
                            if ($type === 'purchase_in') {
                                $et = $c['item']['equipment_type'] ?: 'other';
                                $e = $conn->prepare("INSERT INTO equipment (type, brand, model, serial, status, purchase_cost, purchase_date, location, item_id, movement_id, vendor_id)
                                                     VALUES (?,?,?,?, 'in_stock', ?, ?, 'Main Warehouse', ?,?,?)");
                                $e->bind_param("ssssdsiii", $et, $c['item']['brand'], $c['item']['model'], $sn, $c['rate'], $date, $iid, $mid, $vendor);
                                $e->execute();
                                $e->close();
                            } elseif ($type === 'issue_out') {
                                $e = $conn->prepare("UPDATE equipment SET status='issued', assigned_user_id=?, assigned_at=NOW(), movement_id=? WHERE serial=? AND is_deleted=0");
                                $e->bind_param("iis", $staff, $mid, $sn);
                                $e->execute();
                                $e->close();
                            } elseif ($type === 'return_in') {
                                $e = $conn->prepare("UPDATE equipment SET status='in_stock', assigned_user_id=NULL, returned_at=NOW(), movement_id=? WHERE serial=? AND is_deleted=0");
                                $e->bind_param("is", $mid, $sn);
                                $e->execute();
                                $e->close();
                            } elseif ($type === 'damage_out') {
                                $e = $conn->prepare("UPDATE equipment SET status='damaged', movement_id=? WHERE serial=? AND is_deleted=0");
                                $e->bind_param("is", $mid, $sn);
                                $e->execute();
                                $e->close();
                            }
                        }
                    }
                    $li->close();

                    foreach ($clean as $c) recomputeItemStock($conn, (int)$c['item']['id']);

                    // a vendor purchase is a payable; a vendor return gives it back
                    if ($vendor && $total > 0) {
                        if ($type === 'purchase_in')
                            postLedger($conn, 'vendor', $vendor, $date, "Stock purchase $number", 0, $total, 'stock_purchase', $mid, $number, $user_id);
                        elseif ($type === 'return_in')
                            postLedger($conn, 'vendor', $vendor, $date, "Stock returned to vendor $number", $total, 0, 'stock_return', $mid, $number, $user_id);
                    }

                    $conn->commit();
                    logActivity($user_id, $username, 'Stock Movement', "$number — {$MOVE_TYPES[$type][0]} (" . count($clean) . " line(s))");
                    echo json_encode(['success' => true, 'message' => "$number recorded successfully", 'id' => $mid, 'number' => $number]);
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
                }
                exit();
            }

            case 'cancelMovement':
            case 'deleteMovement': {
                $isDelete = $_GET['action'] === 'deleteMovement';
                if ($isDelete && !$can_delete) { echo json_encode(['success' => false, 'message' => 'You cannot delete stock movements']); exit(); }
                if (!$isDelete && !$can_edit)  { echo json_encode(['success' => false, 'message' => 'You cannot cancel stock movements']); exit(); }

                $id = (int)($_POST['id'] ?? 0);
                $g = $conn->prepare("SELECT * FROM stock_movements WHERE id = ? AND is_deleted = 0");
                $g->bind_param("i", $id);
                $g->execute();
                $m = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$m) { echo json_encode(['success' => false, 'message' => 'Movement not found']); exit(); }
                if (!$isDelete && $m['status'] === 'cancelled') { echo json_encode(['success' => false, 'message' => 'Already cancelled']); exit(); }

                $conn->begin_transaction();
                try {
                    // put serial units back where they were
                    $ls = $conn->prepare("SELECT mi.item_id, mi.serials FROM stock_movement_items mi WHERE mi.movement_id = ?");
                    $ls->bind_param("i", $id);
                    $ls->execute();
                    $lr = $ls->get_result();
                    $itemIds = [];
                    $serialSets = [];
                    while ($x = $lr->fetch_assoc()) {
                        $itemIds[] = (int)$x['item_id'];
                        if (!empty($x['serials'])) $serialSets[] = array_filter(array_map('trim', explode(',', $x['serials'])));
                    }
                    $ls->close();

                    foreach ($serialSets as $set) {
                        foreach ($set as $sn) {
                            if ($m['movement_type'] === 'purchase_in') {
                                $e = $conn->prepare("UPDATE equipment SET is_deleted = 1 WHERE serial = ? AND movement_id = ?");
                                $e->bind_param("si", $sn, $id);
                            } elseif ($m['movement_type'] === 'issue_out' || $m['movement_type'] === 'damage_out') {
                                $e = $conn->prepare("UPDATE equipment SET status='in_stock', assigned_user_id=NULL WHERE serial = ? AND movement_id = ?");
                                $e->bind_param("si", $sn, $id);
                            } else { // return_in reversed -> back out to the holder
                                $e = $conn->prepare("UPDATE equipment SET status='issued' WHERE serial = ? AND movement_id = ?");
                                $e->bind_param("si", $sn, $id);
                            }
                            $e->execute();
                            $e->close();
                        }
                    }

                    if ($isDelete) {
                        $u = $conn->prepare("UPDATE stock_movements SET is_deleted = 1 WHERE id = ?");
                    } else {
                        $u = $conn->prepare("UPDATE stock_movements SET status = 'cancelled' WHERE id = ?");
                    }
                    $u->bind_param("i", $id);
                    $u->execute();
                    $u->close();

                    foreach (array_unique($itemIds) as $iid) recomputeItemStock($conn, $iid);
                    reverseLedger($conn, 'stock_purchase', $id);
                    reverseLedger($conn, 'stock_return', $id);

                    $conn->commit();
                    logActivity($user_id, $username, $isDelete ? 'Stock Movement Deleted' : 'Stock Movement Cancelled', $m['movement_number']);
                    echo json_encode(['success' => true, 'message' => $m['movement_number'] . ($isDelete ? ' deleted' : ' cancelled') . ' — stock restored']);
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $e->getMessage()]);
                }
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Stock_movements.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Stock Movements - ISP Billing</title>

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
                <a href="stock.php">Stock</a>
                <span class="breadcrumb-sep">/</span>
                <span>Movements</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-dolly"></i> Stock Movements</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="chevron-pipeline initially-hidden" id="chevronPipeline" role="tablist" aria-label="Filter by movement type">
                <div class="chevron-tab cv-all active" data-type="all" role="tab" tabindex="0"><i class="fas fa-list"></i> All <span class="cv-count" id="cv-c-all">0</span></div>
                <?php foreach ($MOVE_TYPES as $k => $t): ?>
                <div class="chevron-tab <?php echo $t[2] === 'in' ? 'cv-paid' : 'cv-overdue'; ?>" data-type="<?php echo $k; ?>" role="tab" tabindex="0">
                    <i class="<?php echo $t[1]; ?>"></i> <?php echo $t[0]; ?> <span class="cv-count" id="cv-c-<?php echo $k; ?>">0</span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-right-left"></i> Movement Register</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadMovements()"><i class="fas fa-sync"></i> Refresh</button>
                        <a class="btn btn-secondary" href="stock.php"><i class="fas fa-boxes-stacked"></i> Items</a>
                        <?php if ($can_add): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()"><i class="fas fa-file-import"></i> Import CSV</button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()"><i class="fas fa-download"></i> Template</button>
                        <?php if ($can_add): ?>
                        <button class="btn btn-success" onclick="openAddModal()"><i class="fas fa-plus"></i> New Movement</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="filters-section initially-hidden" id="filtersSection">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()"><i class="fas fa-times-circle"></i> Clear All</button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Date Range</label>
                            <div class="filter-dates">
                                <input type="date" id="filterFrom" title="From">
                                <input type="date" id="filterTo" title="To">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" id="filterSearch" placeholder="Doc # / reference / bill / party...">
                        </div>
                    </div>
                </div>

                <div id="skeletonWrap">
                    <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                </div>

                <div id="tableView" class="initially-hidden">
                    <div class="table-responsive">
                        <table id="movTable" class="display table-full-width"></table>
                    </div>
                </div>

                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-dolly"></i></div>
                    <div class="empty-state-title">No stock movements yet.</div>
                    <div class="empty-state-text">Record a purchase to bring stock in, then issue it to your field staff.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Movement Modal -->
    <div class="modal-overlay" id="movModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> New Stock Movement</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="movForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-right-left"></i> Movement Type *</label>
                            <select id="movement_type" name="movement_type">
                                <?php foreach ($MOVE_TYPES as $k => $t): ?>
                                <option value="<?php echo $k; ?>"><?php echo $t[0]; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date *</label>
                            <input type="date" id="movement_date" name="movement_date" required>
                        </div>
                        <div class="form-group" id="grpVendor">
                            <label><i class="fas fa-truck"></i> Vendor *</label>
                            <select id="vendor_id" name="vendor_id"><option value="">— Select vendor —</option></select>
                        </div>
                        <div class="form-group" id="grpStaff">
                            <label><i class="fas fa-user-gear"></i> Issue To (Staff)</label>
                            <select id="issued_to_user_id" name="issued_to_user_id"><option value="">— Select staff —</option></select>
                        </div>
                        <div class="form-group" id="grpCustomer">
                            <label><i class="fas fa-user"></i> For Customer</label>
                            <select id="customer_id" name="customer_id"><option value="">— None —</option></select>
                        </div>
                        <div class="form-group" id="grpArea">
                            <label><i class="fas fa-map-marked-alt"></i> Area</label>
                            <select id="area_id" name="area_id"><option value="">— None —</option></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference</label>
                            <input type="text" id="reference" name="reference" maxlength="100" placeholder="e.g. Job #, DC #">
                        </div>
                        <div class="form-group" id="grpBill">
                            <label><i class="fas fa-file-invoice"></i> Vendor Bill #</label>
                            <input type="text" id="bill_number" name="bill_number" maxlength="60">
                        </div>
                        <div class="form-group full-row">
                            <label><i class="fas fa-align-left"></i> Notes</label>
                            <input type="text" id="notes" name="notes" maxlength="255">
                        </div>
                    </div>

                    <div class="section-header mt-20">
                        <h2><i class="fas fa-list-check"></i> Items</h2>
                        <div class="btn-group-inline">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addLine()"><i class="fas fa-plus"></i> Add Line</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="ledger-table table-full-width" id="lineTable">
                            <thead>
                                <tr><th>Item</th><th>Qty</th><th>Rate</th><th>Total</th><th></th></tr>
                            </thead>
                            <tbody id="lineBody"></tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="dt-foot-label">Grand Total</th>
                                    <th class="dt-foot-val" id="grandTotal">0.00</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Movement</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Movement Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>
    <script>
    const CAN_ADD    = <?php echo $can_add ? 'true' : 'false'; ?>;
    const CAN_EDIT   = <?php echo $can_edit ? 'true' : 'false'; ?>;
    const CAN_DELETE = <?php echo $can_delete ? 'true' : 'false'; ?>;
    const CURRENCY   = <?php echo json_encode($currency); ?>;
    const MOVE_TYPES = <?php echo json_encode(array_map(function ($t) { return ['label' => $t[0], 'icon' => $t[1], 'dir' => $t[2]]; }, $MOVE_TYPES)); ?>;
    const PRESET_ITEM = <?php echo (int)($_GET['item'] ?? 0); ?>;

    let movTable = null, FORM = { items: [], vendors: [], staff: [], areas: [], customers: [] };
    let lineSeq = 0, activeType = 'all';

    function debounce(fn, ms) { let t; return function(){ clearTimeout(t); t = setTimeout(fn, ms); }; }
    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function capFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
    function fmtAmt(n) { return CURRENCY + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtQty(n) { return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
    function fmtDate(d) {
        if (!d) return '—';
        const dt = /^\d{4}-\d{2}-\d{2}$/.test(d) ? new Date(d + 'T00:00:00') : new Date(d);
        if (isNaN(dt)) return escapeHtml(d);
        return dt.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
    }

    let exportDepsLoaded = false;
    function loadExportDeps(cb) {
        if (exportDepsLoaded) return cb();
        const urls = [
            'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'
        ];
        let i = 0;
        (function next() {
            if (i >= urls.length) { exportDepsLoaded = true; return cb(); }
            const sc = document.createElement('script');
            sc.src = urls[i++]; sc.onload = next; document.head.appendChild(sc);
        })();
    }

    $(document).ready(function() {
        new SearchableDropdown({ selectId: 'movement_type',     placeholder: 'Type...',          icon: 'fas fa-right-left', allowClear: false });
        new SearchableDropdown({ selectId: 'vendor_id',         placeholder: '— Select vendor —', icon: 'fas fa-truck' });
        new SearchableDropdown({ selectId: 'issued_to_user_id', placeholder: '— Select staff —',  icon: 'fas fa-user-gear' });
        new SearchableDropdown({ selectId: 'customer_id',       placeholder: '— None —',          icon: 'fas fa-user' });
        new SearchableDropdown({ selectId: 'area_id',           placeholder: '— None —',          icon: 'fas fa-map-marked-alt' });

        $('#movement_type').on('change', onTypeChange);
        $('#filterFrom, #filterTo').on('change', loadMovements);
        $('#filterSearch').on('input', debounce(loadMovements, 300));
        $('#chevronPipeline').on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('#chevronPipeline .chevron-tab').removeClass('active');
            $(this).addClass('active');
            activeType = $(this).data('type');
            loadMovements();
        });
        document.getElementById('movModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
        document.getElementById('viewModal').addEventListener('click', function(e) { if (e.target === this) closeViewModal(); });

        loadFormData(function() {
            loadMovements();
            if (PRESET_ITEM && CAN_ADD) openAddModal(PRESET_ITEM);
        });
        rsImport.init({ entity: 'Stock Movement', onDone: loadMovements });
    });

    function loadFormData(after) {
        $.getJSON('?action=getFormData', function(resp) {
            if (resp.success) {
                FORM = resp;
                fill('vendor_id', resp.vendors, '— Select vendor —');
                fill('issued_to_user_id', resp.staff, '— Select staff —');
                fill('customer_id', resp.customers, '— None —');
                fill('area_id', resp.areas, '— None —');
            }
            if (after) after();
        });
    }

    function fill(id, rows, placeholder) {
        const el = document.getElementById(id);
        el.innerHTML = `<option value="">${placeholder}</option>` + rows.map(r => `<option value="${r.id}">${escapeHtml(r.name)}</option>`).join('');
        el._sd && el._sd.refresh();
    }

    function loadMovements() {
        $('#skeletonWrap').show();
        $('#tableView,#emptyState').hide();
        const params = {
            movement_type: activeType === 'all' ? '' : activeType,
            from: $('#filterFrom').val(), to: $('#filterTo').val(), q: $('#filterSearch').val()
        };
        $.getJSON('?action=getMovements', params, function(resp) {
            $('#skeletonWrap').hide();
            $('#filtersSection,#chevronPipeline').removeClass('initially-hidden').show();
            if (!resp.success) { Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' }); return; }
            refreshCounts(resp.data);
            if (!resp.data.length) {
                $('#tableView').hide();
                $('#emptyState').removeClass('initially-hidden').show();
                if (movTable) movTable.clear().draw();
                return;
            }
            $('#emptyState').hide();
            $('#tableView').removeClass('initially-hidden').show();
            renderTable(resp.data);
        }).fail(function() { $('#skeletonWrap').hide(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); });
    }

    // counts reflect the loaded page only when a type filter is on — keep 'all' honest
    function refreshCounts(rows) {
        if (activeType === 'all') {
            $('#cv-c-all').text(rows.length);
            Object.keys(MOVE_TYPES).forEach(k => $('#cv-c-' + k).text(rows.filter(r => r.movement_type === k).length));
        } else {
            $('#cv-c-' + activeType).text(rows.length);
        }
    }

    function clearFilters() {
        $('#filterFrom,#filterTo,#filterSearch').val('');
        loadMovements();
    }

    function renderTable(data) {
        if (movTable) { movTable.destroy(); $('#movTable').empty(); }
        setTimeout(() => {
            movTable = $('#movTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'movement_date', title: 'Date', render: d => fmtDate(d) },
                    { data: 'movement_number', title: 'Doc #' },
                    { data: 'movement_type', title: 'Type', render: d => {
                        const t = MOVE_TYPES[d] || { label: d, icon: 'fas fa-circle', dir: 'in' };
                        return `<span class="method-badge ${t.dir === 'in' ? 'cash' : 'cheque'}"><i class="${t.icon}"></i> ${escapeHtml(t.label)}</span>`;
                    } },
                    { data: 'party', title: 'Party', render: d => escapeHtml(d || '—') },
                    { data: 'line_count', title: 'Lines', className: 'num-cell' },
                    { data: 'total_qty', title: 'Qty', className: 'num-cell', render: d => fmtQty(d) },
                    { data: 'total_amount', title: 'Value', className: 'num-cell', render: d => fmtAmt(d) },
                    { data: 'status', title: 'Status', render: d => d === 'cancelled'
                        ? '<span class="inv-status overdue">Cancelled</span>'
                        : '<span class="inv-status paid">Posted</span>' },
                    { data: null, title: 'Actions', orderable: false, render: function(d, t, row) {
                        let menu = '';
                        if (CAN_EDIT && row.status !== 'cancelled')
                            menu += `<button class="act-more-item" onclick="cancelMovement(${row.id}, '${escapeHtml(row.movement_number)}')"><i class="fas fa-ban"></i> Cancel</button>`;
                        if (CAN_DELETE)
                            menu += `<button class="act-more-item danger" onclick="deleteMovement(${row.id}, '${escapeHtml(row.movement_number)}')"><i class="fas fa-trash"></i> Delete</button>`;
                        const more = menu ? `<div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">${menu}</div></div>` : '';
                        return `<button class="action-icon view-icon" onclick="viewMovement(${row.id})" title="View"><i class="fas fa-eye"></i></button>${more}`;
                    } }
                ],
                createdRow: function(tr, row) { if (row.status === 'cancelled') $(tr).addClass('row-cancelled'); },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: ':not(:last-child)' } },
                    { text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: ':not(:last-child)' },
                      action: function(e, dt, node, config) {
                          loadExportDeps(function() { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                      } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':not(:last-child)' } }
                ],
                order: [[0, 'desc']],
                language: { emptyTable: 'No movements found.' }
            });
        }, 50);
    }

    // ── the form ────────────────────────────────────────────────
    function onTypeChange() {
        const t = $('#movement_type').val();
        const isPurchase = t === 'purchase_in', isReturn = t === 'return_in';
        const isIssue = t === 'issue_out', isDamage = t === 'damage_out';
        $('#grpVendor').toggle(isPurchase || isReturn);
        $('#grpBill').toggle(isPurchase || isReturn);
        $('#grpStaff').toggle(isIssue || isReturn);
        $('#grpCustomer').toggle(isIssue);
        $('#grpArea').toggle(isIssue || isDamage);
        $('#lineBody tr').each(function() { syncLineUI($(this)); });
    }

    function addLine(presetItemId) {
        const id = ++lineSeq;
        const opts = FORM.items.map(i => `<option value="${i.id}">${escapeHtml(i.code + ' — ' + i.name)}</option>`).join('');
        const $tr = $(`<tr data-line="${id}">
            <td>
                <select class="line-item"><option value="">— Select item —</option>${opts}</select>
                <div class="line-serials initially-hidden">
                    <label class="muted"><i class="fas fa-barcode"></i> Serial numbers (one per line)</label>
                    <textarea class="line-serial-box" rows="3" placeholder="SN0001&#10;SN0002"></textarea>
                    <div class="muted line-avail"></div>
                </div>
            </td>
            <td><input type="number" class="line-qty" step="0.01" min="0" value="1"></td>
            <td><input type="number" class="line-rate" step="0.01" min="0" value="0"></td>
            <td class="num-cell line-total">0.00</td>
            <td><button type="button" class="action-icon delete-icon" onclick="$(this).closest('tr').remove(); recalcTotals();"><i class="fas fa-trash"></i></button></td>
        </tr>`);
        $('#lineBody').append($tr);
        const sel = $tr.find('.line-item')[0];
        new SearchableDropdown({ selectId: sel, placeholder: '— Select item —', icon: 'fas fa-box' });
        if (presetItemId) { $(sel).val(presetItemId); sel._sd && sel._sd.refresh(); syncLineUI($tr); }
        return $tr;
    }

    // serial items type their serials; quantity items just type a number
    function syncLineUI($tr) {
        const itemId = $tr.find('.line-item').val();
        const item = FORM.items.find(i => String(i.id) === String(itemId));
        const type = $('#movement_type').val();
        const serial = item && item.track === 'serial';
        $tr.find('.line-serials').toggleClass('initially-hidden', !serial).toggle(!!serial);
        $tr.find('.line-qty').prop('readonly', !!serial);
        if (item && Number($tr.find('.line-rate').val()) === 0) {
            $tr.find('.line-rate').val(type === 'purchase_in' ? item.buy : item.sell);
        }
        if (serial && (type === 'issue_out' || type === 'damage_out' || type === 'return_in')) {
            $.getJSON('?action=getAvailableSerials&item_id=' + itemId, function(r) {
                if (r.success) $tr.find('.line-avail').text(r.data.length ? 'In store: ' + r.data.slice(0, 25).join(', ') + (r.data.length > 25 ? ' …' : '') : 'Nothing in store for this item.');
            });
        } else {
            $tr.find('.line-avail').text('');
        }
        recalcLine($tr);
    }

    function recalcLine($tr) {
        const serialBox = $tr.find('.line-serial-box');
        const isSerial = !$tr.find('.line-serials').hasClass('initially-hidden');
        let qty = Number($tr.find('.line-qty').val() || 0);
        if (isSerial) {
            qty = serialBox.val().split(/[\s,;]+/).filter(Boolean).length;
            $tr.find('.line-qty').val(qty);
        }
        const rate = Number($tr.find('.line-rate').val() || 0);
        $tr.find('.line-total').text(fmtAmt(Math.abs(qty) * rate));
        recalcTotals();
    }

    function recalcTotals() {
        let total = 0;
        $('#lineBody tr').each(function() {
            const qty = Math.abs(Number($(this).find('.line-qty').val() || 0));
            const rate = Number($(this).find('.line-rate').val() || 0);
            total += qty * rate;
        });
        $('#grandTotal').text(fmtAmt(total));
    }

    $(document).on('change', '.line-item', function() { syncLineUI($(this).closest('tr')); });
    $(document).on('input', '.line-qty, .line-rate, .line-serial-box', function() { recalcLine($(this).closest('tr')); });

    function openAddModal(presetItemId) {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> New Stock Movement';
        document.getElementById('movForm').reset();
        $('#lineBody').empty();
        $('#movement_date').val(new Date().toISOString().slice(0, 10));
        $('#movement_type').val('purchase_in');
        ['movement_type','vendor_id','issued_to_user_id','customer_id','area_id'].forEach(id => { const e = document.getElementById(id); e._sd && e._sd.refresh(); });
        onTypeChange();
        addLine(presetItemId || null);
        recalcTotals();
        document.getElementById('movModal').classList.add('active');
    }

    function closeModal() { document.getElementById('movModal').classList.remove('active'); }
    function closeViewModal() { document.getElementById('viewModal').classList.remove('active'); }

    document.getElementById('movForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const lines = [];
        let bad = '';
        $('#lineBody tr').each(function() {
            const itemId = $(this).find('.line-item').val();
            if (!itemId) { bad = bad || 'Every line needs an item.'; return; }
            const isSerial = !$(this).find('.line-serials').hasClass('initially-hidden');
            lines.push({
                item_id: itemId,
                qty: Number($(this).find('.line-qty').val() || 0),
                unit_price: Number($(this).find('.line-rate').val() || 0),
                serials: isSerial ? $(this).find('.line-serial-box').val() : ''
            });
        });
        if (bad) { Swal.fire({ icon: 'error', title: 'Error', text: bad }); return; }
        if (!lines.length) { Swal.fire({ icon: 'error', title: 'Error', text: 'Add at least one item line.' }); return; }

        const fd = new FormData(this);
        fd.append('lines', JSON.stringify(lines));
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=addMovement', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeModal();
                    setTimeout(loadMovements, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    });

    function viewMovement(id) {
        Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.getJSON('?action=getMovementDetail&id=' + id, function(resp) {
            Swal.close();
            if (!resp.success) { Swal.fire({ icon: 'error', title: 'Error', text: resp.message }); return; }
            const m = resp.data, lines = resp.lines || [];
            const t = MOVE_TYPES[m.movement_type] || { label: m.movement_type };
            const rows = lines.map(l => `<tr>
                    <td>${escapeHtml(l.item_code)} — ${escapeHtml(l.name)}
                        ${l.serials ? `<div class="muted"><i class="fas fa-barcode"></i> ${escapeHtml(l.serials)}</div>` : ''}</td>
                    <td class="num-cell">${fmtQty(l.qty)} ${escapeHtml(l.unit)}</td>
                    <td class="num-cell">${fmtAmt(l.unit_price)}</td>
                    <td class="num-cell">${fmtAmt(l.line_total)}</td>
                </tr>`).join('');
            document.getElementById('viewModalBody').innerHTML = `
                <div class="vtab-grid">
                    <div class="vtab-field"><label><i class="fas fa-hashtag"></i> Document</label><span>${escapeHtml(m.movement_number)}</span></div>
                    <div class="vtab-field"><label><i class="fas fa-right-left"></i> Type</label><span>${escapeHtml(t.label)}</span></div>
                    <div class="vtab-field"><label><i class="fas fa-calendar-day"></i> Date</label><span>${fmtDate(m.movement_date)}</span></div>
                    <div class="vtab-field"><label><i class="fas fa-user"></i> Party</label><span>${escapeHtml(m.vendor_name || m.staff_name || m.customer_name || '—')}</span></div>
                    <div class="vtab-field"><label><i class="fas fa-map-marked-alt"></i> Area</label><span>${escapeHtml(m.area_name || '—')}</span></div>
                    <div class="vtab-field"><label><i class="fas fa-file-invoice"></i> Bill #</label><span>${escapeHtml(m.bill_number || '—')}</span></div>
                    <div class="vtab-field"><label><i class="fas fa-sack-dollar"></i> Total</label><span>${fmtAmt(m.total_amount)}</span></div>
                    <div class="vtab-field"><label><i class="fas fa-user-pen"></i> Recorded By</label><span>${escapeHtml(m.created_by_name || '—')}</span></div>
                    <div class="vtab-field vtab-field-full"><label><i class="fas fa-align-left"></i> Notes</label><span>${escapeHtml(m.notes || '—')}</span></div>
                </div>
                <div class="table-responsive mt-20"><table class="ledger-table table-full-width">
                    <thead><tr><th>Item</th><th>Qty</th><th>Rate</th><th>Total</th></tr></thead>
                    <tbody>${rows || '<tr><td colspan="4" class="muted">No lines.</td></tr>'}</tbody>
                </table></div>`;
            document.getElementById('viewModal').classList.add('active');
        }).fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); });
    }

    function postAction(url, id, okMsg) {
        const fd = new FormData(); fd.append('id', id);
        Swal.fire({ title: 'Working...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({ url: url, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) { Swal.fire({ icon: 'success', text: resp.message, timer: 1800, showConfirmButton: false }); setTimeout(loadMovements, 100); }
                else Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    function cancelMovement(id, num) {
        Swal.fire({ icon: 'warning', title: 'Cancel ' + num + '?', text: 'Stock will be put back exactly as it was.',
            showCancelButton: true, confirmButtonColor: '#ea4335', confirmButtonText: 'Cancel Movement', cancelButtonText: 'Keep' })
        .then(r => { if (r.isConfirmed) postAction('?action=cancelMovement', id); });
    }

    function deleteMovement(id, num) {
        Swal.fire({ icon: 'warning', title: 'Delete ' + num + '?', text: 'This removes the document and restores stock levels.',
            showCancelButton: true, confirmButtonColor: '#ea4335', confirmButtonText: 'Delete', cancelButtonText: 'Cancel' })
        .then(r => { if (r.isConfirmed) postAction('?action=deleteMovement', id); });
    }
    </script>
</body>
</html>
