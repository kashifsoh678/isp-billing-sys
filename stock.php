<?php
 
require_once 'config.php';
require_once 'import_helper.php';

requirePerm('stock', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'stock';
$can_add      = can('stock', 'a');
$can_edit     = can('stock', 'e');
$can_delete   = can('stock', 'd');
$currency     = getSetting('currency_symbol', '$');

$TRACK_TYPES = ['quantity', 'serial'];
$UNITS       = ['pcs', 'meter', 'box', 'roll', 'set', 'kg'];
$EQ_TYPES    = ['router', 'onu', 'switch', 'cable', 'splitter', 'antenna', 'other'];

// csv import/template contract for this page
function stockItemImportSpec() {
    return [
        'entity'  => 'Stock Item',
        'table'   => 'stock_items',
        'perm'    => 'stock',
        'columns' => [
            'name'          => ['label' => 'Item Name', 'type' => 's', 'required' => true, 'max' => 150, 'demo' => 'Demo Item 1'],
            'category_id'   => ['label' => 'Category', 'type' => 'lookup',
                                 'lookup' => ['table' => 'stock_categories', 'match' => ['name'], 'where' => 'is_deleted = 0'],
                                 'demo'  => ''],
            'tracking_type' => ['label' => 'Tracking Type', 'type' => 'enum', 'values' => ['quantity','serial'], 'default' => 'quantity', 'demo' => 'quantity'],
            'unit'          => ['label' => 'Unit', 'type' => 'enum', 'values' => ['pcs','meter','box','roll','set','kg'], 'default' => 'pcs', 'demo' => 'pcs'],
            'brand'         => ['label' => 'Brand', 'type' => 's', 'max' => 80, 'demo' => 'Demo Brand'],
            'model'         => ['label' => 'Model', 'type' => 's', 'max' => 100, 'demo' => 'Model X1'],
            'equipment_type' => ['label' => 'Equipment Type', 'type' => 'enum',
                                  'values' => ['router','onu','switch','cable','splitter','antenna','other'], 'demo' => ''],
            'opening_qty'   => ['label' => 'Opening Qty', 'type' => 'd', 'default' => 0, 'demo' => '50'],
            'reorder_level' => ['label' => 'Reorder Level', 'type' => 'd', 'default' => 0, 'demo' => '10'],
            'purchase_price' => ['label' => 'Purchase Price', 'type' => 'd', 'default' => 0, 'demo' => '500.00'],
            'sale_price'    => ['label' => 'Sale Price', 'type' => 'd', 'default' => 0, 'demo' => '650.00'],
            'location'      => ['label' => 'Location', 'type' => 's', 'max' => 100, 'default' => 'Main Warehouse', 'demo' => 'Main Warehouse'],
            'notes'         => ['label' => 'Notes', 'type' => 's', 'demo' => 'Imported from CSV'],
            'status'        => ['label' => 'Status', 'type' => 'enum', 'values' => ['active','inactive'], 'default' => 'active', 'demo' => 'active'],
        ],
        // item_code is app-generated (ITM-0001...) — seed the counter once, bump in-memory per row
        'init' => function ($conn, &$state) {
            $state['next_seq'] = (int)substr(nextDocNumber($conn, 'stock_items', 'item_code', 'ITM-'), 4);
        },
        'before' => function (&$row, $conn, &$state) {
            $row['item_code'] = 'ITM-' . str_pad($state['next_seq']++, 4, '0', STR_PAD_LEFT);
            // serial stock is counted from the units themselves, never a typed-in opening qty
            if (($row['tracking_type'] ?? 'quantity') === 'serial') $row['opening_qty'] = 0;
            $row['current_qty'] = $row['opening_qty'];
            return null;
        },
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'importCsv':      rsImportRun($conn, stockItemImportSpec());
            case 'importTemplate': rsImportTemplate(stockItemImportSpec());

            case 'getItems': {
                $cat   = (int)($_GET['category_id'] ?? 0);
                $track = $_GET['tracking_type'] ?? '';
                $stat  = $_GET['stock_status'] ?? '';
                $q     = trim($_GET['q'] ?? '');

                $where = "i.is_deleted = 0";
                $types = ''; $vals = [];
                if ($cat > 0)                                 { $where .= " AND i.category_id = ?"; $types .= "i"; $vals[] = $cat; }
                if (in_array($track, $TRACK_TYPES, true))     { $where .= " AND i.tracking_type = ?"; $types .= "s"; $vals[] = $track; }
                if ($q !== '') {
                    $where .= " AND (i.name LIKE ? OR i.item_code LIKE ? OR i.brand LIKE ? OR i.model LIKE ?)";
                    $types .= "ssss";
                    $like = "%$q%";
                    array_push($vals, $like, $like, $like, $like);
                }
                if ($stat === 'low')  $where .= " AND i.reorder_level > 0 AND i.current_qty <= i.reorder_level AND i.current_qty > 0";
                if ($stat === 'out')  $where .= " AND i.current_qty <= 0";
                if ($stat === 'ok')   $where .= " AND (i.reorder_level <= 0 OR i.current_qty > i.reorder_level)";

                $sql = "SELECT i.*, c.name AS category_name
                        FROM stock_items i
                        LEFT JOIN stock_categories c ON c.id = i.category_id
                        WHERE $where
                        ORDER BY i.name ASC";
                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$vals);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $qty = (float)$r['current_qty']; $ro = (float)$r['reorder_level'];
                    $rows[] = [
                        'id'            => (int)$r['id'],
                        'item_code'     => $r['item_code'],
                        'name'          => $r['name'],
                        'category_id'   => $r['category_id'] !== null ? (int)$r['category_id'] : null,
                        'category_name' => $r['category_name'],
                        'tracking_type' => $r['tracking_type'],
                        'unit'          => $r['unit'],
                        'brand'         => $r['brand'],
                        'model'         => $r['model'],
                        'equipment_type'=> $r['equipment_type'],
                        'opening_qty'   => number_format((float)$r['opening_qty'], 2, '.', ''),
                        'current_qty'   => number_format($qty, 2, '.', ''),
                        'reorder_level' => number_format($ro, 2, '.', ''),
                        'purchase_price'=> number_format((float)$r['purchase_price'], 2, '.', ''),
                        'avg_cost'      => number_format((float)$r['avg_cost'], 2, '.', ''),
                        'sale_price'    => number_format((float)$r['sale_price'], 2, '.', ''),
                        'stock_value'   => number_format($qty * (float)$r['avg_cost'], 2, '.', ''),
                        'location'      => $r['location'],
                        'notes'         => $r['notes'],
                        'status'        => $r['status'],
                        'stock_status'  => $qty <= 0 ? 'out' : (($ro > 0 && $qty <= $ro) ? 'low' : 'ok'),
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows, 'currency' => $currency]);
                exit();
            }

            case 'getSummary': {
                $r = $conn->query("SELECT COUNT(*) items,
                        COALESCE(SUM(current_qty * avg_cost), 0) value,
                        SUM(CASE WHEN reorder_level > 0 AND current_qty <= reorder_level AND current_qty > 0 THEN 1 ELSE 0 END) low,
                        SUM(CASE WHEN current_qty <= 0 THEN 1 ELSE 0 END) outs
                    FROM stock_items WHERE is_deleted = 0 AND status = 'active'")->fetch_assoc();
                $m = $conn->query("SELECT
                        COALESCE(SUM(CASE WHEN m.movement_type IN ('purchase_in','return_in') THEN mi.qty ELSE 0 END), 0) qin,
                        COALESCE(SUM(CASE WHEN m.movement_type IN ('issue_out','damage_out') THEN mi.qty ELSE 0 END), 0) qout
                    FROM stock_movement_items mi JOIN stock_movements m ON m.id = mi.movement_id
                    WHERE m.is_deleted = 0 AND m.status = 'posted'
                      AND m.movement_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetch_assoc();
                echo json_encode(['success' => true, 'summary' => [
                    'items' => (int)$r['items'],
                    'value' => number_format((float)$r['value'], 2, '.', ''),
                    'low'   => (int)$r['low'],
                    'out'   => (int)$r['outs'],
                    'qin'   => number_format((float)$m['qin'], 2, '.', ''),
                    'qout'  => number_format((float)$m['qout'], 2, '.', ''),
                ], 'currency' => $currency]);
                exit();
            }

            case 'getCategories': {
                $rows = [];
                $res = $conn->query("SELECT id, name FROM stock_categories WHERE is_deleted = 0 AND status = 'active' ORDER BY name ASC");
                while ($r = $res->fetch_assoc()) $rows[] = ['id' => (int)$r['id'], 'name' => $r['name']];
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getItemDetail': {
                $id = (int)($_GET['id'] ?? 0);
                $stmt = $conn->prepare("SELECT i.*, c.name AS category_name FROM stock_items i
                                        LEFT JOIN stock_categories c ON c.id = i.category_id
                                        WHERE i.id = ? AND i.is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $it = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$it) { echo json_encode(['success' => false, 'message' => 'Item not found']); exit(); }

                // movement history
                $moves = [];
                $m = $conn->prepare("SELECT m.movement_number, m.movement_type, m.movement_date, m.reference,
                                            mi.qty, mi.unit_price, mi.line_total,
                                            v.vendor_name, u.name AS staff_name
                                     FROM stock_movement_items mi
                                     JOIN stock_movements m ON m.id = mi.movement_id
                                     LEFT JOIN vendors v ON v.id = m.vendor_id
                                     LEFT JOIN users u ON u.id = m.issued_to_user_id
                                     WHERE mi.item_id = ? AND m.is_deleted = 0
                                     ORDER BY m.movement_date DESC, m.id DESC LIMIT 200");
                $m->bind_param("i", $id);
                $m->execute();
                $mr = $m->get_result();
                $run = 0.0;
                while ($x = $mr->fetch_assoc()) {
                    $in = in_array($x['movement_type'], ['purchase_in', 'return_in'], true);
                    $moves[] = [
                        'number' => $x['movement_number'], 'type' => $x['movement_type'],
                        'date' => $x['movement_date'], 'reference' => $x['reference'],
                        'party' => $x['vendor_name'] ?: ($x['staff_name'] ?: '—'),
                        'in'  => $in ? number_format((float)$x['qty'], 2, '.', '') : '0.00',
                        'out' => $in ? '0.00' : number_format((float)$x['qty'], 2, '.', ''),
                        'rate' => number_format((float)$x['unit_price'], 2, '.', ''),
                        'total' => number_format((float)$x['line_total'], 2, '.', ''),
                    ];
                }
                $m->close();

                // serial units live in equipment
                $units = [];
                if ($it['tracking_type'] === 'serial') {
                    $u = $conn->prepare("SELECT e.id, e.serial, e.mac, e.status, e.assigned_to_customer_id, e.assigned_user_id,
                                                c.full_name AS customer_name, us.name AS staff_name
                                         FROM equipment e
                                         LEFT JOIN customers c ON c.id = e.assigned_to_customer_id
                                         LEFT JOIN users us ON us.id = e.assigned_user_id
                                         WHERE e.item_id = ? AND e.is_deleted = 0
                                         ORDER BY e.status ASC, e.serial ASC LIMIT 500");
                    $u->bind_param("i", $id);
                    $u->execute();
                    $ur = $u->get_result();
                    while ($x = $ur->fetch_assoc()) {
                        $units[] = ['id' => (int)$x['id'], 'serial' => $x['serial'], 'mac' => $x['mac'], 'status' => $x['status'],
                                    'holder' => $x['customer_name'] ?: ($x['staff_name'] ?: '—')];
                    }
                    $u->close();
                }

                $it['stock_value'] = number_format((float)$it['current_qty'] * (float)$it['avg_cost'], 2, '.', '');
                echo json_encode(['success' => true, 'data' => $it, 'movements' => $moves, 'units' => $units, 'currency' => $currency]);
                exit();
            }

            case 'addItem':
            case 'updateItem': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                $isEdit = $_GET['action'] === 'updateItem';
                if ($isEdit && !$can_edit) { echo json_encode(['success' => false, 'message' => 'You cannot edit stock items']); exit(); }
                if (!$isEdit && !$can_add)  { echo json_encode(['success' => false, 'message' => 'You cannot add stock items']); exit(); }

                $id      = (int)($_POST['id'] ?? 0);
                $name    = trim($_POST['name'] ?? '');
                $cat     = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
                $track   = $_POST['tracking_type'] ?? 'quantity';
                $unit    = trim($_POST['unit'] ?? 'pcs');
                $brand   = trim($_POST['brand'] ?? '');
                $model   = trim($_POST['model'] ?? '');
                $eqType  = ($_POST['equipment_type'] ?? '') !== '' ? $_POST['equipment_type'] : null;
                $opening = (float)($_POST['opening_qty'] ?? 0);
                $reorder = (float)($_POST['reorder_level'] ?? 0);
                $pprice  = (float)($_POST['purchase_price'] ?? 0);
                $sprice  = (float)($_POST['sale_price'] ?? 0);
                $loc     = trim($_POST['location'] ?? 'Main Warehouse');
                $notes   = trim($_POST['notes'] ?? '');
                $status  = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

                if ($name === '')                              { echo json_encode(['success' => false, 'message' => 'Item name is required']); exit(); }
                if (mb_strlen($name) > 150)                    { echo json_encode(['success' => false, 'message' => 'Item name too long']); exit(); }
                if (!in_array($track, $TRACK_TYPES, true))     { echo json_encode(['success' => false, 'message' => 'Invalid tracking type']); exit(); }
                if ($unit === '' || mb_strlen($unit) > 20)     { echo json_encode(['success' => false, 'message' => 'Invalid unit']); exit(); }
                if ($eqType !== null && !in_array($eqType, $EQ_TYPES, true)) { echo json_encode(['success' => false, 'message' => 'Invalid equipment type']); exit(); }
                if ($opening < 0 || $reorder < 0 || $pprice < 0 || $sprice < 0) { echo json_encode(['success' => false, 'message' => 'Numbers cannot be negative']); exit(); }
                if ($track === 'serial') $opening = 0; // serial stock is counted from the units themselves

                $brandBind = $brand === '' ? null : $brand;
                $modelBind = $model === '' ? null : $model;
                $notesBind = $notes === '' ? null : $notes;

                if ($isEdit) {
                    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid item id']); exit(); }
                    $stmt = $conn->prepare("UPDATE stock_items SET name=?, category_id=?, tracking_type=?, unit=?, brand=?, model=?,
                            equipment_type=?, opening_qty=?, reorder_level=?, purchase_price=?, sale_price=?, location=?, notes=?, status=?
                            WHERE id=? AND is_deleted=0");
                    $stmt->bind_param("sisssssddddsssi", $name, $cat, $track, $unit, $brandBind, $modelBind, $eqType,
                                      $opening, $reorder, $pprice, $sprice, $loc, $notesBind, $status, $id);
                    if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); echo json_encode(['success' => false, 'message' => 'Failed to update: ' . $e]); exit(); }
                    $stmt->close();
                    recomputeItemStock($conn, $id);
                    logActivity($user_id, $username, 'Stock Item Updated', "Updated item #$id — $name");
                    echo json_encode(['success' => true, 'message' => 'Item updated successfully']);
                    exit();
                }

                $code = nextDocNumber($conn, 'stock_items', 'item_code', 'ITM-');
                $current = $track === 'serial' ? 0.0 : $opening; // serial qty comes from the units
                $stmt = $conn->prepare("INSERT INTO stock_items (item_code, name, category_id, tracking_type, unit, brand, model,
                        equipment_type, opening_qty, current_qty, reorder_level, purchase_price, sale_price, location, notes, status)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("ssisssssdddddsss", $code, $name, $cat, $track, $unit, $brandBind, $modelBind, $eqType,
                                  $opening, $current, $reorder, $pprice, $sprice, $loc, $notesBind, $status);
                if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); echo json_encode(['success' => false, 'message' => 'Failed to add: ' . $e]); exit(); }
                $newId = $stmt->insert_id;
                $stmt->close();
                logActivity($user_id, $username, 'Stock Item Added', "Added item $code — $name");
                try { createNotificationForAdmins('Stock Item Added', 'Item "' . htmlspecialchars($name) . '" (' . $code . ') was added to inventory.', 'info', 'stock.php'); } catch (Exception $e) {}
                echo json_encode(['success' => true, 'message' => "Item $code added successfully", 'id' => $newId]);
                exit();
            }

            case 'deleteItem': {
                if (!$can_delete) { echo json_encode(['success' => false, 'message' => 'You cannot delete stock items']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                $c = $conn->prepare("SELECT COUNT(*) n FROM stock_movement_items mi JOIN stock_movements m ON m.id = mi.movement_id
                                     WHERE mi.item_id = ? AND m.is_deleted = 0");
                $c->bind_param("i", $id);
                $c->execute();
                $n = (int)$c->get_result()->fetch_assoc()['n'];
                $c->close();
                if ($n > 0) { echo json_encode(['success' => false, 'message' => "This item has $n movement(s) — it cannot be deleted"]); exit(); }

                $d = $conn->prepare("UPDATE stock_items SET is_deleted = 1 WHERE id = ?");
                $d->bind_param("i", $id);
                $ok = $d->execute();
                $d->close();
                if (!$ok) { echo json_encode(['success' => false, 'message' => 'Failed to delete item']); exit(); }
                logActivity($user_id, $username, 'Stock Item Deleted', "Deleted item #$id");
                echo json_encode(['success' => true, 'message' => 'Item deleted']);
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Stock.php error: " . $e->getMessage());
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
    <title>Stock Items - ISP Billing</title>

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
                <span>Stock Items</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-boxes-stacked"></i> Stock Items</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="summary-grid" id="summaryGrid">
                <div class="summary-card highlight">
                    <div class="summary-card-label"><i class="fas fa-cubes"></i> Total Items</div>
                    <div class="summary-card-value" id="smItems">—</div>
                    <div class="summary-card-sub" id="smItemsSub">&nbsp;</div>
                </div>
                <div class="summary-card success">
                    <div class="summary-card-label"><i class="fas fa-sack-dollar"></i> Stock Value</div>
                    <div class="summary-card-value" id="smValue">—</div>
                    <div class="summary-card-sub">Qty &times; average cost</div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-card-label"><i class="fas fa-triangle-exclamation"></i> Low Stock</div>
                    <div class="summary-card-value" id="smLow">—</div>
                    <div class="summary-card-sub">At or below reorder level</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-card-label"><i class="fas fa-ban"></i> Out of Stock</div>
                    <div class="summary-card-value" id="smOut">—</div>
                    <div class="summary-card-sub">Nothing left in store</div>
                </div>
                <div class="summary-card">
                    <div class="summary-card-label"><i class="fas fa-circle-check"></i> Healthy Stock</div>
                    <div class="summary-card-value" id="smHealthy">—</div>
                    <div class="summary-card-sub">Above reorder level</div>
                </div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-warehouse"></i> Item Catalog</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadAll()"><i class="fas fa-sync"></i> Refresh</button>
                        <a class="btn btn-secondary" href="stock_movements.php"><i class="fas fa-dolly"></i> Movements</a>
                        <?php if ($can_add): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()"><i class="fas fa-file-import"></i> Import CSV</button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()"><i class="fas fa-download"></i> Template</button>
                        <?php if ($can_add): ?>
                        <button class="btn btn-success" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Item</button>
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
                            <label><i class="fas fa-tags"></i> Category</label>
                            <select id="filterCategory"><option value="">All Categories</option></select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-barcode"></i> Tracking</label>
                            <select id="filterTracking">
                                <option value="">All Types</option>
                                <option value="quantity">Quantity</option>
                                <option value="serial">Serial / MAC</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-signal"></i> Stock Status</label>
                            <select id="filterStock">
                                <option value="">All</option>
                                <option value="ok">In Stock</option>
                                <option value="low">Low Stock</option>
                                <option value="out">Out of Stock</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" id="filterSearch" placeholder="Name / code / brand / model...">
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
                        <table id="stockTable" class="display table-full-width"></table>
                    </div>
                </div>

                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-boxes-stacked"></i></div>
                    <div class="empty-state-title">No stock items yet.</div>
                    <div class="empty-state-text">Click Add Item to build your inventory catalog.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Item Modal (add / edit) -->
    <div class="modal-overlay" id="itemModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Item</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="itemForm">
                    <input type="hidden" id="itemId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-box"></i> Item Name *</label>
                            <input type="text" id="name" name="name" maxlength="150" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tags"></i> Category</label>
                            <select id="category_id" name="category_id"><option value="">— None —</option></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-barcode"></i> Tracking Type *</label>
                            <select id="tracking_type" name="tracking_type">
                                <option value="quantity">Quantity (cable, connectors...)</option>
                                <option value="serial">Serial / MAC (ONU, router...)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-ruler"></i> Unit *</label>
                            <select id="unit" name="unit">
                                <?php foreach ($UNITS as $u): ?>
                                <option value="<?php echo $u; ?>"><?php echo $u; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-copyright"></i> Brand</label>
                            <input type="text" id="brand" name="brand" maxlength="80">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-microchip"></i> Model</label>
                            <input type="text" id="model" name="model" maxlength="100">
                        </div>
                        <div class="form-group" id="eqTypeGroup">
                            <label><i class="fas fa-server"></i> Equipment Type</label>
                            <select id="equipment_type" name="equipment_type">
                                <option value="">— None —</option>
                                <?php foreach ($EQ_TYPES as $t): ?>
                                <option value="<?php echo $t; ?>"><?php echo ucfirst($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="openingGroup">
                            <label><i class="fas fa-flag-checkered"></i> Opening Qty</label>
                            <input type="number" id="opening_qty" name="opening_qty" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-triangle-exclamation"></i> Reorder Level</label>
                            <input type="number" id="reorder_level" name="reorder_level" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-cart-shopping"></i> Purchase Price</label>
                            <input type="number" id="purchase_price" name="purchase_price" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Sale Price</label>
                            <input type="number" id="sale_price" name="sale_price" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-warehouse"></i> Location</label>
                            <input type="text" id="location" name="location" maxlength="100" value="Main Warehouse">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="status" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="form-group full-row">
                            <label><i class="fas fa-align-left"></i> Notes</label>
                            <input type="text" id="notes" name="notes" maxlength="255">
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
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Item Details</h3>
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

    let stockTable = null, isEditMode = false;

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

    // lazy pdf — only pulled when someone actually exports
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
        new SearchableDropdown({ selectId: 'category_id',    placeholder: '— None —',        icon: 'fas fa-tags' });
        new SearchableDropdown({ selectId: 'tracking_type',  placeholder: 'Tracking...',     icon: 'fas fa-barcode', allowClear: false });
        new SearchableDropdown({ selectId: 'unit',           placeholder: 'Unit...',         icon: 'fas fa-ruler',   allowClear: false });
        new SearchableDropdown({ selectId: 'equipment_type', placeholder: '— None —',        icon: 'fas fa-server' });
        new SearchableDropdown({ selectId: 'status',         placeholder: 'Status...',       icon: 'fas fa-toggle-on', allowClear: false });
        new SearchableDropdown({ selectId: 'filterCategory', placeholder: 'All Categories',  icon: 'fas fa-tags' });
        new SearchableDropdown({ selectId: 'filterTracking', placeholder: 'All Types',       icon: 'fas fa-barcode' });
        new SearchableDropdown({ selectId: 'filterStock',    placeholder: 'All',             icon: 'fas fa-signal' });

        $('#tracking_type').on('change', onTrackingChange);
        $('#filterCategory, #filterTracking, #filterStock').on('change', loadItems);
        $('#filterSearch').on('input', debounce(loadItems, 300));
        document.getElementById('itemModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
        document.getElementById('viewModal').addEventListener('click', function(e) { if (e.target === this) closeViewModal(); });

        // deep link from the dashboard: stock.php?stock=low
        const preset = new URLSearchParams(location.search).get('stock');
        if (preset === 'low' || preset === 'out') {
            $('#filterStock').val(preset);
            const fs = document.getElementById('filterStock'); fs._sd && fs._sd.refresh();
        }

        loadCategories(loadAll);
        rsImport.init({ entity: 'Stock Item', onDone: loadAll });
    });

    function loadAll() { loadSummary(); loadItems(); }

    function loadCategories(after) {
        $.getJSON('?action=getCategories', function(resp) {
            if (resp.success) {
                const opts = resp.data.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
                const m = document.getElementById('category_id');
                m.innerHTML = '<option value="">— None —</option>' + opts;
                m._sd && m._sd.refresh();
                const f = document.getElementById('filterCategory');
                f.innerHTML = '<option value="">All Categories</option>' + opts;
                f._sd && f._sd.refresh();
            }
            if (after) after();
        });
    }

    function loadSummary() {
        $.getJSON('?action=getSummary', function(resp) {
            if (!resp.success) return;
            const s = resp.summary;
            $('#smItems').text(s.items);
            $('#smItemsSub').text(fmtQty(s.qin) + ' in / ' + fmtQty(s.qout) + ' out this month');
            $('#smValue').text(fmtAmt(s.value));
            $('#smLow').text(s.low);
            $('#smOut').text(s.out);
            $('#smHealthy').text(Math.max(0, s.items - s.low - s.out));   // neither low nor empty
        });
    }

    function loadItems() {
        $('#skeletonWrap').show();
        $('#tableView,#emptyState').hide();
        const params = {
            category_id: $('#filterCategory').val(),
            tracking_type: $('#filterTracking').val(),
            stock_status: $('#filterStock').val(),
            q: $('#filterSearch').val()
        };
        $.getJSON('?action=getItems', params, function(resp) {
            $('#skeletonWrap').hide();
            $('#filtersSection').removeClass('initially-hidden').show();
            if (!resp.success) { Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' }); return; }
            if (!resp.data.length) {
                $('#tableView').hide();
                $('#emptyState').removeClass('initially-hidden').show();
                if (stockTable) stockTable.clear().draw();
                return;
            }
            $('#emptyState').hide();
            $('#tableView').removeClass('initially-hidden').show();
            renderTable(resp.data);
        }).fail(function() { $('#skeletonWrap').hide(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); });
    }

    function clearFilters() {
        $('#filterCategory,#filterTracking,#filterStock').val('');
        $('#filterSearch').val('');
        ['filterCategory','filterTracking','filterStock'].forEach(id => { const e = document.getElementById(id); e._sd && e._sd.refresh(); });
        loadItems();
    }

    function renderTable(data) {
        if (stockTable) { stockTable.destroy(); $('#stockTable').empty(); }
        setTimeout(() => {
            stockTable = $('#stockTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'item_code', title: 'Code' },
                    { data: 'name', title: 'Item', render: (d, t, row) =>
                        `${escapeHtml(d)}${row.brand || row.model ? `<div class="muted">${escapeHtml([row.brand, row.model].filter(Boolean).join(' '))}</div>` : ''}` },
                    { data: 'category_name', title: 'Category', render: d => escapeHtml(d || '—') },
                    { data: 'tracking_type', title: 'Tracking', render: d =>
                        `<span class="method-badge ${d === 'serial' ? 'cheque' : 'cash'}">${d === 'serial' ? 'Serial' : 'Quantity'}</span>` },
                    { data: 'current_qty', title: 'In Stock', className: 'num-cell', render: (d, t, row) => `${fmtQty(d)} <span class="muted">${escapeHtml(row.unit)}</span>` },
                    { data: 'reorder_level', title: 'Reorder', className: 'num-cell', render: d => fmtQty(d) },
                    { data: 'stock_value', title: 'Value', className: 'num-cell', render: d => fmtAmt(d) },
                    { data: 'stock_status', title: 'Status', render: d =>
                        d === 'out' ? '<span class="inv-status overdue">Out of Stock</span>'
                      : d === 'low' ? '<span class="inv-status partial">Low Stock</span>'
                                    : '<span class="inv-status paid">In Stock</span>' },
                    { data: null, title: 'Actions', orderable: false, render: function(d, t, row) {
                        let menu = '';
                        if (CAN_EDIT)   menu += `<button class="act-more-item" onclick="editItem(${row.id})"><i class="fas fa-edit"></i> Edit</button>`;
                        if (CAN_ADD)    menu += `<button class="act-more-item" onclick="location.href='stock_movements.php?item=${row.id}'"><i class="fas fa-dolly"></i> New Movement</button>`;
                        if (CAN_ADD)    menu += `<button class="act-more-item" onclick="location.href='invoices.php?new=1&item=${row.id}'"><i class="fas fa-file-invoice-dollar"></i> Bill on Invoice</button>`;
                        if (CAN_DELETE) menu += `<button class="act-more-item danger" onclick="deleteItem(${row.id}, '${escapeHtml(row.name)}')"><i class="fas fa-trash"></i> Delete</button>`;
                        const more = menu ? `<div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">${menu}</div></div>` : '';
                        return `<button class="action-icon view-icon" onclick="viewItem(${row.id})" title="View"><i class="fas fa-eye"></i></button>${more}`;
                    } }
                ],
                createdRow: function(tr, row) {
                    if (row.stock_status === 'out') $(tr).addClass('row-danger');
                    else if (row.stock_status === 'low') $(tr).addClass('row-lowstock');
                },
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
                order: [[1, 'asc']],
                language: { emptyTable: 'No stock items found.' }
            });
        }, 50);
    }

    // serial items count their units, so opening qty makes no sense there
    function onTrackingChange() {
        const serial = $('#tracking_type').val() === 'serial';
        $('#openingGroup').toggle(!serial);
        if (serial) $('#opening_qty').val(0);
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Item';
        document.getElementById('itemForm').reset();
        $('#itemId').val('');
        $('#location').val('Main Warehouse');
        ['category_id','tracking_type','unit','equipment_type','status'].forEach(id => { const e = document.getElementById(id); e._sd && e._sd.refresh(); });
        onTrackingChange();
        document.getElementById('itemModal').classList.add('active');
    }

    function editItem(id) {
        $.getJSON('?action=getItemDetail&id=' + id, function(resp) {
            if (!resp.success) { Swal.fire({ icon: 'error', title: 'Error', text: resp.message }); return; }
            const it = resp.data;
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Item';
            $('#itemId').val(it.id);
            $('#name').val(it.name);
            $('#category_id').val(it.category_id || '');
            $('#tracking_type').val(it.tracking_type);
            $('#unit').val(it.unit);
            $('#brand').val(it.brand || '');
            $('#model').val(it.model || '');
            $('#equipment_type').val(it.equipment_type || '');
            $('#opening_qty').val(it.opening_qty);
            $('#reorder_level').val(it.reorder_level);
            $('#purchase_price').val(it.purchase_price);
            $('#sale_price').val(it.sale_price);
            $('#location').val(it.location || '');
            $('#notes').val(it.notes || '');
            $('#status').val(it.status);
            ['category_id','tracking_type','unit','equipment_type','status'].forEach(i => { const e = document.getElementById(i); e._sd && e._sd.refresh(); });
            onTrackingChange();
            document.getElementById('itemModal').classList.add('active');
        });
    }

    function closeModal() { document.getElementById('itemModal').classList.remove('active'); }
    function closeViewModal() { document.getElementById('viewModal').classList.remove('active'); }

    document.getElementById('itemForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=' + (isEditMode ? 'updateItem' : 'addItem'), method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeModal();
                    setTimeout(loadAll, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    });

    function viewItem(id) {
        Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.getJSON('?action=getItemDetail&id=' + id, function(resp) {
            Swal.close();
            if (!resp.success) { Swal.fire({ icon: 'error', title: 'Error', text: resp.message }); return; }
            const it = resp.data, moves = resp.movements || [], units = resp.units || [];

            let movRows = moves.length ? moves.map(m => `<tr>
                    <td>${fmtDate(m.date)}</td>
                    <td>${escapeHtml(m.number)}</td>
                    <td>${escapeHtml(capFirst(m.type.replace('_', ' ')))}</td>
                    <td>${escapeHtml(m.party)}</td>
                    <td class="ledger-credit num-cell">${Number(m.in) > 0 ? fmtQty(m.in) : '—'}</td>
                    <td class="ledger-debit num-cell">${Number(m.out) > 0 ? fmtQty(m.out) : '—'}</td>
                    <td class="num-cell">${fmtAmt(m.total)}</td>
                </tr>`).join('')
                : '<tr><td colspan="7" class="muted">No movements yet.</td></tr>';

            let unitRows = units.length ? units.map(u => `<tr>
                    <td>${escapeHtml(u.serial)}</td>
                    <td>${escapeHtml(u.mac || '—')}</td>
                    <td><span class="status-pill ${u.status === 'in_stock' ? 'active' : 'inactive'}">${escapeHtml(capFirst(u.status.replace('_', ' ')))}</span></td>
                    <td>${escapeHtml(u.holder)}</td>
                </tr>`).join('')
                : '<tr><td colspan="4" class="muted">No serial units recorded.</td></tr>';

            const unitsTab = it.tracking_type === 'serial'
                ? `<button type="button" class="vtab-btn" data-vtab="vtUnits"><i class="fas fa-barcode"></i> Serial Units (${units.length})</button>` : '';
            const unitsPane = it.tracking_type === 'serial'
                ? `<div class="vtab-pane" id="vtUnits">
                     <div class="table-responsive"><table class="ledger-table table-full-width">
                       <thead><tr><th>Serial</th><th>MAC</th><th>Status</th><th>Held By</th></tr></thead>
                       <tbody>${unitRows}</tbody>
                     </table></div>
                   </div>` : '';

            document.getElementById('viewModalBody').innerHTML = `
                <div class="vtab-nav">
                    <button type="button" class="vtab-btn active" data-vtab="vtDetails"><i class="fas fa-info-circle"></i> Details</button>
                    <button type="button" class="vtab-btn" data-vtab="vtMoves"><i class="fas fa-dolly"></i> Movements (${moves.length})</button>
                    ${unitsTab}
                </div>
                <div class="vtab-pane active" id="vtDetails">
                    <div class="vtab-grid">
                        <div class="vtab-field"><label><i class="fas fa-barcode"></i> Code</label><span>${escapeHtml(it.item_code)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-box"></i> Name</label><span>${escapeHtml(it.name)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-tags"></i> Category</label><span>${escapeHtml(it.category_name || '—')}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-barcode"></i> Tracking</label><span>${it.tracking_type === 'serial' ? 'Serial / MAC' : 'Quantity'}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-cubes"></i> In Stock</label><span>${fmtQty(it.current_qty)} ${escapeHtml(it.unit)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-triangle-exclamation"></i> Reorder Level</label><span>${fmtQty(it.reorder_level)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-cart-shopping"></i> Avg Cost</label><span>${fmtAmt(it.avg_cost)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-sack-dollar"></i> Stock Value</label><span>${fmtAmt(it.stock_value)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-tag"></i> Sale Price</label><span>${fmtAmt(it.sale_price)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-warehouse"></i> Location</label><span>${escapeHtml(it.location || '—')}</span></div>
                        <div class="vtab-field vtab-field-full"><label><i class="fas fa-align-left"></i> Notes</label><span>${escapeHtml(it.notes || '—')}</span></div>
                    </div>
                </div>
                <div class="vtab-pane" id="vtMoves">
                    <div class="table-responsive"><table class="ledger-table table-full-width">
                      <thead><tr><th>Date</th><th>Doc #</th><th>Type</th><th>Party</th><th>In</th><th>Out</th><th>Value</th></tr></thead>
                      <tbody>${movRows}</tbody>
                    </table></div>
                </div>
                ${unitsPane}`;
            wireVtabs(document.getElementById('viewModalBody'));
            document.getElementById('viewModal').classList.add('active');
        }).fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); });
    }

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

    function deleteItem(id, name) {
        Swal.fire({
            icon: 'warning', title: 'Delete Item?',
            text: 'Remove "' + name + '" from the catalog? Items with movements cannot be deleted.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({ url: '?action=deleteItem', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) { Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false }); setTimeout(loadAll, 100); }
                    else Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }
    </script>
</body>
</html>
