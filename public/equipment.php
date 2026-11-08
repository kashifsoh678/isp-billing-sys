<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: super_admin + admin only
if (!isset($_SESSION['user_id']) || !can('equipment', 'v')) {
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
$current_page = 'equipment';

$VALID_TYPES      = ['router','onu','switch','cable','splitter','antenna','other'];
$VALID_STATUS     = ['in_stock','assigned','returned','lost','damaged','retired'];
$VALID_DEPR       = ['straight_line','reducing_balance','none'];
$VALID_CONDITION  = ['new','good','fair','needs_repair','scrapped'];
$VALID_POWER      = ['mains','battery','solar','mixed'];
$EQ_PHOTOS_DIR    = __DIR__ . '/uploads/equipment_photos/';
$EQ_PHOTOS_REL    = 'uploads/equipment_photos/';
if (!is_dir($EQ_PHOTOS_DIR)) @mkdir($EQ_PHOTOS_DIR, 0755, true);

// helper: compute current book value (straight-line / reducing-balance)
function computeBookValue($cost, $purchaseDate, $method, $pctYear, $salvage) {
    $cost    = (float)$cost;
    $salvage = (float)$salvage;
    if ($cost <= 0 || !$purchaseDate || $method === 'none') return $cost;
    $start = strtotime($purchaseDate);
    if (!$start) return $cost;
    $monthsElapsed = max(0, (int)floor((time() - $start) / (30.4375 * 86400)));
    $yearsElapsed  = $monthsElapsed / 12.0;
    if ($method === 'straight_line') {
        $annualDep = $cost * ((float)$pctYear / 100.0);
        $bv = $cost - ($annualDep * $yearsElapsed);
    } else { // reducing_balance
        $bv = $cost * pow(1 - ((float)$pctYear / 100.0), $yearsElapsed);
    }
    if ($bv < $salvage) $bv = $salvage;
    if ($bv < 0) $bv = 0;
    return round($bv, 2);
}

// csv import/template contract for this page
function equipmentImportSpec() {
    return [
        'entity'  => 'Equipment',
        'table'   => 'equipment',
        'perm'    => 'equipment',
        'unique'  => ['serial'],
        'columns' => [
            'type'   => ['label' => 'Type', 'type' => 'enum',
                         'values' => ['router','onu','switch','cable','splitter','antenna','other'],
                         'default' => 'router', 'demo' => 'router'],
            'brand'  => ['label' => 'Brand', 'type' => 's', 'max' => 50, 'demo' => 'Demo Brand'],
            'model'  => ['label' => 'Model', 'type' => 's', 'max' => 100, 'demo' => 'Model X1'],
            'serial' => ['label' => 'Serial Number', 'type' => 's', 'required' => true, 'max' => 100, 'demo' => 'DEMO-SER-0001'],
            'mac'    => ['label' => 'MAC Address', 'type' => 's', 'max' => 20, 'demo' => '00:11:22:33:44:01'],
            'status' => ['label' => 'Status', 'type' => 'enum',
                         'values' => ['in_stock','assigned','issued','returned','lost','damaged','retired'],
                         'default' => 'in_stock', 'demo' => 'in_stock'],
            'purchase_cost' => ['label' => 'Purchase Cost', 'type' => 'd', 'default' => 0, 'demo' => '5000.00'],
            'purchase_date' => ['label' => 'Purchase Date', 'type' => 'date', 'demo' => '2026-01-01'],
            'location'      => ['label' => 'Location', 'type' => 's', 'max' => 100, 'default' => 'Main Warehouse', 'demo' => 'Main Warehouse'],
            'notes'         => ['label' => 'Notes', 'type' => 's', 'demo' => 'Imported from CSV'],
            'item_id' => ['label' => 'Stock Item', 'type' => 'lookup',
                          'lookup' => ['table' => 'stock_items', 'match' => ['item_code', 'name'], 'where' => 'is_deleted = 0'],
                          'demo'  => ''],
            'assigned_to_customer_id' => ['label' => 'Assigned Customer', 'type' => 'lookup',
                          'lookup' => ['table' => 'customers', 'match' => ['customer_code', 'full_name'], 'where' => 'is_deleted = 0'],
                          'demo'  => ''],
            'area_id' => ['label' => 'Area', 'type' => 'lookup',
                          'lookup' => ['table' => 'areas', 'match' => ['area_code', 'name'], 'where' => 'is_deleted = 0'],
                          'demo'  => ''],
            'vendor_id' => ['label' => 'Vendor', 'type' => 'lookup',
                          'lookup' => ['table' => 'vendors', 'match' => ['vendor_code', 'vendor_name'], 'where' => 'is_deleted = 0'],
                          'demo'  => ''],
        ],
        // serial-tracked stock items count in-stock units live — recompute after linking a unit
        'after' => function ($id, $row, $conn, &$state) {
            if (!empty($row['item_id'])) recomputeItemStock($conn, (int)$row['item_id']);
        },
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'importCsv':      rsImportRun($conn, equipmentImportSpec());
            case 'importTemplate': rsImportTemplate(equipmentImportSpec());

            case 'getEquipment': {
                // optional filters via GET
                $type   = trim($_GET['type']   ?? '');
                $status = trim($_GET['status'] ?? '');
                $brand  = trim($_GET['brand']  ?? '');
                $q      = trim($_GET['q']      ?? '');

                $sql = "SELECT e.*, c.customer_code, c.full_name AS customer_name
                        FROM equipment e
                        LEFT JOIN customers c ON c.id = e.assigned_to_customer_id AND c.is_deleted = 0
                        WHERE e.is_deleted = 0";
                $types = '';
                $params = [];
                if ($type !== '' && in_array($type, $VALID_TYPES, true)) {
                    $sql .= " AND e.type = ?"; $types .= 's'; $params[] = $type;
                }
                if ($status !== '' && in_array($status, $VALID_STATUS, true)) {
                    $sql .= " AND e.status = ?"; $types .= 's'; $params[] = $status;
                }
                if ($brand !== '') {
                    $sql .= " AND e.brand = ?"; $types .= 's'; $params[] = $brand;
                }
                if ($q !== '') {
                    $sql .= " AND (e.serial LIKE ? OR e.mac LIKE ? OR e.model LIKE ?)";
                    $like = '%' . $q . '%';
                    $types .= 'sss';
                    $params[] = $like; $params[] = $like; $params[] = $like;
                }
                $sql .= " ORDER BY e.created_at DESC, e.id DESC";

                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($row = $res->fetch_assoc()) {
                    // photos: stored as JSON array of relative paths
                    $photos = [];
                    if (!empty($row['photos_json'])) {
                        $tmp = json_decode($row['photos_json'], true);
                        if (is_array($tmp)) $photos = array_values($tmp);
                    }
                    $rows[] = [
                        'id'                   => (int)$row['id'],
                        'type'                 => $row['type'],
                        'brand'                => $row['brand'] ?? '',
                        'model'                => $row['model'] ?? '',
                        'serial'               => $row['serial'],
                        'mac'                  => $row['mac'] ?? '',
                        'status'               => $row['status'],
                        'assigned_to_customer_id' => $row['assigned_to_customer_id'] ? (int)$row['assigned_to_customer_id'] : null,
                        'customer_code'        => $row['customer_code'] ?? '',
                        'customer_name'        => $row['customer_name'] ?? '',
                        'assigned_at'          => $row['assigned_at'] ? date('c', strtotime($row['assigned_at'])) : '',
                        'returned_at'          => $row['returned_at'] ? date('c', strtotime($row['returned_at'])) : '',
                        'return_reason'        => $row['return_reason'] ?? '',
                        'purchase_cost'        => (float)$row['purchase_cost'],
                        'purchase_date'        => $row['purchase_date'] ?? '',
                        'vendor_name'          => $row['vendor_name'] ?? '',
                        'location'             => $row['location'] ?? '',
                        'notes'                => $row['notes'] ?? '',
                        'created_at'           => $row['created_at'],
                        // identity
                        'asset_tag'            => $row['asset_tag'] ?? '',
                        'imei'                 => $row['imei'] ?? '',
                        'firmware_version'     => $row['firmware_version'] ?? '',
                        'hardware_revision'    => $row['hardware_revision'] ?? '',
                        // accounting
                        'purchase_invoice_id'  => $row['purchase_invoice_id'] ? (int)$row['purchase_invoice_id'] : null,
                        'warranty_start_date'  => $row['warranty_start_date'] ?? '',
                        'warranty_end_date'    => $row['warranty_end_date'] ?? '',
                        'warranty_contact'     => $row['warranty_contact'] ?? '',
                        'expected_lifetime_months' => isset($row['expected_lifetime_months']) && $row['expected_lifetime_months'] !== null ? (int)$row['expected_lifetime_months'] : null,
                        'depreciation_method'  => $row['depreciation_method'] ?? 'straight_line',
                        'depreciation_pct_per_year' => (float)($row['depreciation_pct_per_year'] ?? 0),
                        'salvage_value'        => (float)($row['salvage_value'] ?? 0),
                        'current_book_value'   => isset($row['current_book_value']) && $row['current_book_value'] !== null ? (float)$row['current_book_value'] : null,
                        // ownership
                        'is_rented'            => (int)($row['is_rented'] ?? 0),
                        'monthly_rental_charge'=> (float)($row['monthly_rental_charge'] ?? 0),
                        'is_sold'              => (int)($row['is_sold'] ?? 0),
                        'sold_amount'          => isset($row['sold_amount']) && $row['sold_amount'] !== null ? (float)$row['sold_amount'] : null,
                        'is_loaned'            => (int)($row['is_loaned'] ?? 0),
                        'loan_due_date'        => $row['loan_due_date'] ?? '',
                        // assignment / topology
                        'assigned_user_id'     => $row['assigned_user_id'] ? (int)$row['assigned_user_id'] : null,
                        'area_id'              => $row['area_id'] ? (int)$row['area_id'] : null,
                        'parent_equipment_id'  => $row['parent_equipment_id'] ? (int)$row['parent_equipment_id'] : null,
                        'port_number'          => $row['port_number'] ?? '',
                        // maintenance
                        'last_inspection_date' => $row['last_inspection_date'] ?? '',
                        'next_inspection_date' => $row['next_inspection_date'] ?? '',
                        'last_maintenance_date'=> $row['last_maintenance_date'] ?? '',
                        'equipment_condition'  => $row['equipment_condition'] ?? 'good',
                        // EOL
                        'decommissioned_date'  => $row['decommissioned_date'] ?? '',
                        'decommissioned_reason'=> $row['decommissioned_reason'] ?? '',
                        'disposed_date'        => $row['disposed_date'] ?? '',
                        'disposed_to'          => $row['disposed_to'] ?? '',
                        'disposal_value'       => isset($row['disposal_value']) && $row['disposal_value'] !== null ? (float)$row['disposal_value'] : null,
                        // physical
                        'gps_lat'              => isset($row['gps_lat']) && $row['gps_lat'] !== null ? (float)$row['gps_lat'] : null,
                        'gps_lng'              => isset($row['gps_lng']) && $row['gps_lng'] !== null ? (float)$row['gps_lng'] : null,
                        'mount_height_meters'  => isset($row['mount_height_meters']) && $row['mount_height_meters'] !== null ? (int)$row['mount_height_meters'] : null,
                        'power_source'         => $row['power_source'] ?? '',
                        'battery_backup_hours' => isset($row['battery_backup_hours']) && $row['battery_backup_hours'] !== null ? (int)$row['battery_backup_hours'] : null,
                        // monitoring
                        'is_critical'          => (int)($row['is_critical'] ?? 0),
                        'monitoring_enabled'   => (int)($row['monitoring_enabled'] ?? 0),
                        'snmp_community'       => $row['snmp_community'] ?? '',
                        'snmp_ip'              => $row['snmp_ip'] ?? '',
                        // photos
                        'photos'               => $photos
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getEquipmentDetail': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }
                $stmt = $conn->prepare("SELECT e.*, c.customer_code, c.full_name AS customer_name, c.phone AS customer_phone
                                        FROM equipment e
                                        LEFT JOIN customers c ON c.id = e.assigned_to_customer_id AND c.is_deleted = 0
                                        WHERE e.id = ? AND e.is_deleted = 0 LIMIT 1");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Equipment not found']);
                    exit();
                }
                echo json_encode(['success' => true, 'data' => $row]);
                exit();
            }

            case 'getEquipmentSummary': {
                // counts + asset value
                $sql = "SELECT
                          COUNT(*) AS total,
                          SUM(status='in_stock') AS in_stock,
                          SUM(status='assigned') AS assigned,
                          SUM(status='lost' OR status='damaged') AS lost_dmg,
                          SUM(CASE WHEN status NOT IN ('lost','retired') THEN purchase_cost ELSE 0 END) AS asset_value
                        FROM equipment WHERE is_deleted = 0";
                $row = $conn->query($sql)->fetch_assoc() ?: [];
                echo json_encode(['success' => true, 'data' => [
                    'total'       => (int)($row['total'] ?? 0),
                    'in_stock'    => (int)($row['in_stock'] ?? 0),
                    'assigned'    => (int)($row['assigned'] ?? 0),
                    'lost_dmg'    => (int)($row['lost_dmg'] ?? 0),
                    'asset_value' => (float)($row['asset_value'] ?? 0)
                ]]);
                exit();
            }

            case 'getActiveCustomers': {
                $res = $conn->query("SELECT id, customer_code, full_name FROM customers
                                     WHERE is_deleted = 0 AND connection_status IN ('active','suspended')
                                     ORDER BY customer_code ASC");
                $custs = [];
                while ($row = $res->fetch_assoc()) {
                    $custs[] = [
                        'id'            => (int)$row['id'],
                        'customer_code' => $row['customer_code'],
                        'full_name'     => $row['full_name']
                    ];
                }
                echo json_encode(['success' => true, 'data' => $custs]);
                exit();
            }

            case 'getActiveUsers': {
                $res = $conn->query("SELECT id, name, role FROM users WHERE is_deleted = 0 AND status = 'active' ORDER BY name ASC");
                $users = [];
                while ($row = $res->fetch_assoc()) {
                    $users[] = [
                        'id'   => (int)$row['id'],
                        'name' => $row['name'],
                        'role' => $row['role'] ?? ''
                    ];
                }
                echo json_encode(['success' => true, 'data' => $users]);
                exit();
            }

            case 'getActiveAreas': {
                $res = $conn->query("SELECT id, name FROM areas WHERE is_deleted = 0 ORDER BY name ASC");
                $areas = [];
                while ($row = $res->fetch_assoc()) {
                    $areas[] = ['id' => (int)$row['id'], 'name' => $row['name']];
                }
                echo json_encode(['success' => true, 'data' => $areas]);
                exit();
            }

            case 'getParentEquipmentList': {
                // candidates: OLT/switch/antenna/router (not soft-deleted, not the same id)
                $excludeId = (int)($_GET['exclude_id'] ?? 0);
                $sql = "SELECT id, type, brand, model, serial FROM equipment
                        WHERE is_deleted = 0 AND status NOT IN ('lost','retired','damaged')
                        AND type IN ('switch','antenna','router','other')";
                if ($excludeId > 0) $sql .= " AND id != " . $excludeId;
                $sql .= " ORDER BY type ASC, serial ASC";
                $res = $conn->query($sql);
                $list = [];
                while ($row = $res->fetch_assoc()) {
                    $label = strtoupper($row['type']) . ' — ' . ($row['brand'] ? $row['brand'] . ' ' : '') . ($row['model'] ? $row['model'] . ' ' : '') . '[' . $row['serial'] . ']';
                    $list[] = ['id' => (int)$row['id'], 'label' => $label];
                }
                echo json_encode(['success' => true, 'data' => $list]);
                exit();
            }

            case 'getActiveExpenses': {
                // for purchase_invoice_id linkage
                $res = $conn->query("SELECT id, expense_date, category, amount, description FROM expenses
                                     WHERE is_deleted = 0
                                     ORDER BY expense_date DESC, id DESC LIMIT 500");
                $list = [];
                while ($row = $res->fetch_assoc()) {
                    $label = '#' . $row['id'] . ' — ' . $row['expense_date'] . ' [' . $row['category'] . '] ' . substr($row['description'], 0, 40);
                    $list[] = ['id' => (int)$row['id'], 'label' => $label];
                }
                echo json_encode(['success' => true, 'data' => $list]);
                exit();
            }

            case 'getDistinctBrands': {
                $res = $conn->query("SELECT DISTINCT brand FROM equipment
                                     WHERE is_deleted = 0 AND brand IS NOT NULL AND brand <> ''
                                     ORDER BY brand ASC");
                $brands = [];
                while ($row = $res->fetch_assoc()) $brands[] = $row['brand'];
                echo json_encode(['success' => true, 'data' => $brands]);
                exit();
            }

            case 'addEquipment':
            case 'updateEquipment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $isEdit = $_GET['action'] === 'updateEquipment';
                $id     = (int)($_POST['id'] ?? 0);
                $type   = $_POST['type'] ?? '';
                $brand  = trim($_POST['brand'] ?? '');
                $model  = trim($_POST['model'] ?? '');
                $serial = trim($_POST['serial'] ?? '');
                $mac    = trim($_POST['mac'] ?? '');
                $cost   = isset($_POST['purchase_cost']) && $_POST['purchase_cost'] !== '' ? (float)$_POST['purchase_cost'] : 0.0;
                $pdate  = trim($_POST['purchase_date'] ?? '');
                $vendor = trim($_POST['vendor_name'] ?? '');
                $loc    = trim($_POST['location'] ?? '');
                if ($loc === '') $loc = 'Main Warehouse';
                $notes  = trim($_POST['notes'] ?? '');

                // identity extras
                $assetTag = trim($_POST['asset_tag'] ?? '');
                $imei     = trim($_POST['imei'] ?? '');
                $fwVer    = trim($_POST['firmware_version'] ?? '');
                $hwRev    = trim($_POST['hardware_revision'] ?? '');

                // accounting
                $purchInvId = isset($_POST['purchase_invoice_id']) && $_POST['purchase_invoice_id'] !== '' ? (int)$_POST['purchase_invoice_id'] : null;
                $warStart   = trim($_POST['warranty_start_date'] ?? '');
                $warEnd     = trim($_POST['warranty_end_date'] ?? '');
                $warContact = trim($_POST['warranty_contact'] ?? '');
                $lifeMon    = isset($_POST['expected_lifetime_months']) && $_POST['expected_lifetime_months'] !== '' ? (int)$_POST['expected_lifetime_months'] : null;
                $deprMethod = $_POST['depreciation_method'] ?? 'straight_line';
                $deprPct    = (float)($_POST['depreciation_pct_per_year'] ?? 20);
                $salvage    = (float)($_POST['salvage_value'] ?? 0);

                // ownership
                $isRented = !empty($_POST['is_rented']) ? 1 : 0;
                $rentAmt  = (float)($_POST['monthly_rental_charge'] ?? 0);
                $isSold   = !empty($_POST['is_sold']) ? 1 : 0;
                $soldAmt  = isset($_POST['sold_amount']) && $_POST['sold_amount'] !== '' ? (float)$_POST['sold_amount'] : null;
                $isLoaned = !empty($_POST['is_loaned']) ? 1 : 0;
                $loanDue  = trim($_POST['loan_due_date'] ?? '');

                // assignment / topology
                $assignUser = isset($_POST['assigned_user_id']) && $_POST['assigned_user_id'] !== '' ? (int)$_POST['assigned_user_id'] : null;
                $areaId     = isset($_POST['area_id']) && $_POST['area_id'] !== '' ? (int)$_POST['area_id'] : null;
                $parentEq   = isset($_POST['parent_equipment_id']) && $_POST['parent_equipment_id'] !== '' ? (int)$_POST['parent_equipment_id'] : null;
                $portNum    = trim($_POST['port_number'] ?? '');

                // maintenance
                $lastInsp = trim($_POST['last_inspection_date'] ?? '');
                $nextInsp = trim($_POST['next_inspection_date'] ?? '');
                $lastMaint = trim($_POST['last_maintenance_date'] ?? '');
                $eqCond   = $_POST['equipment_condition'] ?? 'good';

                // physical
                $gpsLat = isset($_POST['gps_lat']) && $_POST['gps_lat'] !== '' ? (float)$_POST['gps_lat'] : null;
                $gpsLng = isset($_POST['gps_lng']) && $_POST['gps_lng'] !== '' ? (float)$_POST['gps_lng'] : null;
                $mountH = isset($_POST['mount_height_meters']) && $_POST['mount_height_meters'] !== '' ? (int)$_POST['mount_height_meters'] : null;
                $powerSrc = trim($_POST['power_source'] ?? '');
                $batBackup = isset($_POST['battery_backup_hours']) && $_POST['battery_backup_hours'] !== '' ? (int)$_POST['battery_backup_hours'] : null;

                // monitoring
                $isCrit  = !empty($_POST['is_critical']) ? 1 : 0;
                $monitOn = !empty($_POST['monitoring_enabled']) ? 1 : 0;
                $snmpCom = trim($_POST['snmp_community'] ?? '');
                $snmpIp  = trim($_POST['snmp_ip'] ?? '');

                if (!in_array($type, $VALID_TYPES, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid equipment type']);
                    exit();
                }
                if ($serial === '') {
                    echo json_encode(['success' => false, 'message' => 'Serial number is required']);
                    exit();
                }
                if (!in_array($deprMethod, $VALID_DEPR, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid depreciation method']);
                    exit();
                }
                if (!in_array($eqCond, $VALID_CONDITION, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid equipment condition']);
                    exit();
                }
                if ($powerSrc !== '' && !in_array($powerSrc, $VALID_POWER, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid power source']);
                    exit();
                }
                if ($deprPct < 0 || $deprPct > 100) {
                    echo json_encode(['success' => false, 'message' => 'Depreciation % must be 0-100']);
                    exit();
                }
                if ($salvage < 0 || $cost < 0 || $rentAmt < 0) {
                    echo json_encode(['success' => false, 'message' => 'Money values cannot be negative']);
                    exit();
                }

                // dup serial check
                $dupSql = $isEdit
                    ? "SELECT id FROM equipment WHERE serial = ? AND id != ?"
                    : "SELECT id FROM equipment WHERE serial = ?";
                $stmt = $conn->prepare($dupSql);
                if ($isEdit) $stmt->bind_param("si", $serial, $id);
                else         $stmt->bind_param("s", $serial);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Serial number already exists']);
                    exit();
                }
                $stmt->close();

                // dup asset_tag check
                if ($assetTag !== '') {
                    $dupSql2 = $isEdit
                        ? "SELECT id FROM equipment WHERE asset_tag = ? AND id != ? AND is_deleted = 0"
                        : "SELECT id FROM equipment WHERE asset_tag = ? AND is_deleted = 0";
                    $stmt = $conn->prepare($dupSql2);
                    if ($isEdit) $stmt->bind_param("si", $assetTag, $id);
                    else         $stmt->bind_param("s", $assetTag);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        $stmt->close();
                        echo json_encode(['success' => false, 'message' => 'Asset tag already exists']);
                        exit();
                    }
                    $stmt->close();
                }

                // null-handle dates / strings (mysqli bind_param accepts php null for nullable cols)
                $pdateOrNull   = $pdate     !== '' ? $pdate     : null;
                $warStartOrN   = $warStart  !== '' ? $warStart  : null;
                $warEndOrN     = $warEnd    !== '' ? $warEnd    : null;
                $warContactOrN = $warContact !== '' ? $warContact : null;
                $loanDueOrN    = $loanDue   !== '' ? $loanDue   : null;
                $lastInspOrN   = $lastInsp  !== '' ? $lastInsp  : null;
                $nextInspOrN   = $nextInsp  !== '' ? $nextInsp  : null;
                $lastMaintOrN  = $lastMaint !== '' ? $lastMaint : null;
                $assetTagOrN   = $assetTag  !== '' ? $assetTag  : null;
                $imeiOrN       = $imei      !== '' ? $imei      : null;
                $fwVerOrN      = $fwVer     !== '' ? $fwVer     : null;
                $hwRevOrN      = $hwRev     !== '' ? $hwRev     : null;
                $portNumOrN    = $portNum   !== '' ? $portNum   : null;
                $powerSrcOrN   = $powerSrc  !== '' ? $powerSrc  : null;
                $snmpComOrN    = $snmpCom   !== '' ? $snmpCom   : null;
                $snmpIpOrN     = $snmpIp    !== '' ? $snmpIp    : null;
                $brandOrEmpty  = $brand; // varchar default ''
                $modelOrEmpty  = $model;
                $macOrEmpty    = $mac;
                $vendorOrEmpty = $vendor;
                $notesOrEmpty  = $notes;

                // compute book value
                $bookVal = computeBookValue($cost, $pdateOrNull, $deprMethod, $deprPct, $salvage);

                // photos: existing kept + new uploaded files
                $photosArr = [];
                if (!empty($_POST['existing_photos_json'])) {
                    $tmp = json_decode($_POST['existing_photos_json'], true);
                    if (is_array($tmp)) $photosArr = array_values(array_map('strval', $tmp));
                }
                if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
                    $allowedExt = ['jpg','jpeg','png','webp'];
                    $maxSize = 5 * 1024 * 1024;
                    $count = count($_FILES['photos']['name']);
                    for ($i = 0; $i < $count; $i++) {
                        $err = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                        if ($err !== UPLOAD_ERR_OK) continue;
                        $sz = (int)($_FILES['photos']['size'][$i] ?? 0);
                        if ($sz <= 0 || $sz > $maxSize) continue;
                        $orig = $_FILES['photos']['name'][$i] ?? '';
                        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowedExt, true)) continue;
                        $tmpPath = $_FILES['photos']['tmp_name'][$i];
                        $fname   = 'eq_' . ($id > 0 ? $id : 'new') . '_' . time() . '_' . mt_rand(1000,9999) . '_' . $i . '.' . $ext;
                        $dest    = $EQ_PHOTOS_DIR . $fname;
                        if (move_uploaded_file($tmpPath, $dest)) {
                            $photosArr[] = $EQ_PHOTOS_REL . $fname;
                        }
                    }
                }
                $photosJson = !empty($photosArr) ? json_encode(array_values($photosArr)) : null;

                if (!$isEdit) {
                    $sql = "INSERT INTO equipment
                        (type, brand, model, serial, mac, purchase_cost, purchase_date, vendor_name, location, notes,
                         asset_tag, imei, firmware_version, hardware_revision,
                         purchase_invoice_id, warranty_start_date, warranty_end_date, warranty_contact, expected_lifetime_months,
                         depreciation_method, depreciation_pct_per_year, current_book_value, salvage_value,
                         is_rented, monthly_rental_charge, is_sold, sold_amount, is_loaned, loan_due_date,
                         assigned_user_id, area_id, parent_equipment_id, port_number,
                         last_inspection_date, next_inspection_date, last_maintenance_date, equipment_condition,
                         gps_lat, gps_lng, mount_height_meters, power_source, battery_backup_hours,
                         is_critical, monitoring_enabled, snmp_community, snmp_ip, photos_json)
                        VALUES (?,?,?,?,?,?,?,?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?,?, ?)";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) { echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$conn->error]); exit(); }
                    // 47 params: 10+4+5+4+6+4+4+5+4+1
                    $types = "sssssdssss" . "ssss" . "isssi" . "sddd" . "ididis" . "iiis" . "ssss" . "ddisi" . "iiss" . "s";
                    $stmt->bind_param(
                        $types,
                        $type, $brandOrEmpty, $modelOrEmpty, $serial, $macOrEmpty, $cost, $pdateOrNull, $vendorOrEmpty, $loc, $notesOrEmpty,
                        $assetTagOrN, $imeiOrN, $fwVerOrN, $hwRevOrN,
                        $purchInvId, $warStartOrN, $warEndOrN, $warContactOrN, $lifeMon,
                        $deprMethod, $deprPct, $bookVal, $salvage,
                        $isRented, $rentAmt, $isSold, $soldAmt, $isLoaned, $loanDueOrN,
                        $assignUser, $areaId, $parentEq, $portNumOrN,
                        $lastInspOrN, $nextInspOrN, $lastMaintOrN, $eqCond,
                        $gpsLat, $gpsLng, $mountH, $powerSrcOrN, $batBackup,
                        $isCrit, $monitOn, $snmpComOrN, $snmpIpOrN, $photosJson
                    );
                    if ($stmt->execute()) {
                        $newId = $stmt->insert_id;
                        logActivity($user_id, $username, 'Equipment Added', "Added $type: $serial" . ($brand ? " ($brand)" : ''));
                        try { createNotificationForAdmins('Equipment Added', 'New ' . htmlspecialchars($type) . ' "' . htmlspecialchars($serial) . '" was added to inventory.', 'info', 'equipment.php'); } catch (Exception $e) {}
                        $stmt->close();
                        echo json_encode(['success' => true, 'message' => 'Equipment added successfully', 'id' => $newId]);
                    } else {
                        $err = $stmt->error;
                        $stmt->close();
                        echo json_encode(['success' => false, 'message' => 'Failed to add: ' . $err]);
                    }
                    exit();
                }

                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }
                $sql = "UPDATE equipment SET
                        type=?, brand=?, model=?, serial=?, mac=?, purchase_cost=?, purchase_date=?, vendor_name=?, location=?, notes=?,
                        asset_tag=?, imei=?, firmware_version=?, hardware_revision=?,
                        purchase_invoice_id=?, warranty_start_date=?, warranty_end_date=?, warranty_contact=?, expected_lifetime_months=?,
                        depreciation_method=?, depreciation_pct_per_year=?, current_book_value=?, salvage_value=?,
                        is_rented=?, monthly_rental_charge=?, is_sold=?, sold_amount=?, is_loaned=?, loan_due_date=?,
                        assigned_user_id=?, area_id=?, parent_equipment_id=?, port_number=?,
                        last_inspection_date=?, next_inspection_date=?, last_maintenance_date=?, equipment_condition=?,
                        gps_lat=?, gps_lng=?, mount_height_meters=?, power_source=?, battery_backup_hours=?,
                        is_critical=?, monitoring_enabled=?, snmp_community=?, snmp_ip=?, photos_json=?
                        WHERE id=? AND is_deleted = 0";
                $stmt = $conn->prepare($sql);
                if (!$stmt) { echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$conn->error]); exit(); }
                // 47 params + i for id => 48
                $types = "sssssdssss" . "ssss" . "isssi" . "sddd" . "ididis" . "iiis" . "ssss" . "ddisi" . "iiss" . "s" . "i";
                $stmt->bind_param(
                    $types,
                    $type, $brandOrEmpty, $modelOrEmpty, $serial, $macOrEmpty, $cost, $pdateOrNull, $vendorOrEmpty, $loc, $notesOrEmpty,
                    $assetTagOrN, $imeiOrN, $fwVerOrN, $hwRevOrN,
                    $purchInvId, $warStartOrN, $warEndOrN, $warContactOrN, $lifeMon,
                    $deprMethod, $deprPct, $bookVal, $salvage,
                    $isRented, $rentAmt, $isSold, $soldAmt, $isLoaned, $loanDueOrN,
                    $assignUser, $areaId, $parentEq, $portNumOrN,
                    $lastInspOrN, $nextInspOrN, $lastMaintOrN, $eqCond,
                    $gpsLat, $gpsLng, $mountH, $powerSrcOrN, $batBackup,
                    $isCrit, $monitOn, $snmpComOrN, $snmpIpOrN, $photosJson,
                    $id
                );
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Equipment Updated', "Updated $type: $serial");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Equipment updated successfully']);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update: ' . $err]);
                }
                exit();
            }

            case 'decommissionEquipment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $eqId   = (int)($_POST['id'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                if ($eqId <= 0 || $reason === '') {
                    echo json_encode(['success' => false, 'message' => 'Equipment id and reason required']);
                    exit();
                }
                $stmt = $conn->prepare("SELECT serial, type FROM equipment WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $eqId);
                $stmt->execute();
                $eq = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$eq) { echo json_encode(['success'=>false,'message'=>'Equipment not found']); exit(); }

                $today = date('Y-m-d');
                $stmt = $conn->prepare("UPDATE equipment SET status='retired', equipment_condition='scrapped', decommissioned_date=?, decommissioned_reason=? WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("ssi", $today, $reason, $eqId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) { echo json_encode(['success'=>false,'message'=>'Decommission failed']); exit(); }
                logActivity($user_id, $username, 'Equipment Decommissioned', "{$eq['type']} {$eq['serial']} — $reason");
                echo json_encode(['success' => true, 'message' => 'Equipment decommissioned']);
                exit();
            }

            case 'disposeEquipment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $eqId    = (int)($_POST['id'] ?? 0);
                $disposedTo = trim($_POST['disposed_to'] ?? '');
                $disposedDate = trim($_POST['disposed_date'] ?? '');
                $disposalVal = isset($_POST['disposal_value']) && $_POST['disposal_value'] !== '' ? (float)$_POST['disposal_value'] : null;
                if ($eqId <= 0 || $disposedTo === '' || $disposedDate === '') {
                    echo json_encode(['success'=>false,'message'=>'Equipment id, disposed_to, disposed_date required']);
                    exit();
                }
                $stmt = $conn->prepare("SELECT serial, type FROM equipment WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $eqId);
                $stmt->execute();
                $eq = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$eq) { echo json_encode(['success'=>false,'message'=>'Equipment not found']); exit(); }

                $stmt = $conn->prepare("UPDATE equipment SET status='retired', disposed_date=?, disposed_to=?, disposal_value=? WHERE id = ? AND is_deleted = 0");
                // s s d i
                $stmt->bind_param("ssdi", $disposedDate, $disposedTo, $disposalVal, $eqId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) { echo json_encode(['success'=>false,'message'=>'Dispose failed']); exit(); }
                logActivity($user_id, $username, 'Equipment Disposed', "{$eq['type']} {$eq['serial']} → $disposedTo ($disposedDate)");
                echo json_encode(['success' => true, 'message' => 'Equipment disposed']);
                exit();
            }

            case 'markInspected': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $eqId = (int)($_POST['id'] ?? 0);
                $nextDate = trim($_POST['next_inspection_date'] ?? '');
                if ($eqId <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $today = date('Y-m-d');
                $nextOrNull = $nextDate !== '' ? $nextDate : null;
                $stmt = $conn->prepare("UPDATE equipment SET last_inspection_date=?, next_inspection_date=? WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("ssi", $today, $nextOrNull, $eqId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) { echo json_encode(['success'=>false,'message'=>'Mark inspected failed']); exit(); }
                logActivity($user_id, $username, 'Equipment Inspected', "ID #$eqId — next: " . ($nextDate ?: 'n/a'));
                echo json_encode(['success' => true, 'message' => 'Marked inspected']);
                exit();
            }

            case 'markMaintained': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $eqId = (int)($_POST['id'] ?? 0);
                if ($eqId <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $stmt = $conn->prepare("UPDATE equipment SET last_maintenance_date = CURDATE() WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $eqId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) { echo json_encode(['success'=>false,'message'=>'Mark maintained failed']); exit(); }
                logActivity($user_id, $username, 'Equipment Maintained', "ID #$eqId — maintenance logged");
                echo json_encode(['success' => true, 'message' => 'Maintenance logged']);
                exit();
            }

            case 'assignEquipment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $eqId   = (int)($_POST['equipment_id'] ?? 0);
                $custId = (int)($_POST['customer_id'] ?? 0);
                if ($eqId <= 0 || $custId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Equipment and customer required']);
                    exit();
                }

                // verify in_stock
                $stmt = $conn->prepare("SELECT serial, type, status FROM equipment WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $eqId);
                $stmt->execute();
                $eq = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$eq) {
                    echo json_encode(['success' => false, 'message' => 'Equipment not found']);
                    exit();
                }
                if ($eq['status'] !== 'in_stock') {
                    echo json_encode(['success' => false, 'message' => 'Only in-stock items can be assigned']);
                    exit();
                }

                // verify customer
                $stmt = $conn->prepare("SELECT customer_code, full_name FROM customers WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $custId);
                $stmt->execute();
                $cust = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$cust) {
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }

                // assign
                $stmt = $conn->prepare("UPDATE equipment SET status='assigned', assigned_to_customer_id=?, assigned_at=NOW(), returned_at=NULL, return_reason=NULL WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("ii", $custId, $eqId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    echo json_encode(['success' => false, 'message' => 'Assign failed']);
                    exit();
                }

                // mirror to customers.router_serial
                $stmt = $conn->prepare("UPDATE customers SET router_serial = ? WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("si", $eq['serial'], $custId);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'Equipment Assigned', "{$eq['type']} {$eq['serial']} -> {$cust['customer_code']} ({$cust['full_name']})");
                try { createNotificationForAdmins('Equipment Assigned', htmlspecialchars($eq['serial']) . ' assigned to ' . htmlspecialchars($cust['customer_code']) . '.', 'info', 'equipment.php'); } catch (Exception $e) {}
                echo json_encode(['success' => true, 'message' => 'Equipment assigned to ' . $cust['customer_code']]);
                exit();
            }

            case 'returnEquipment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $eqId   = (int)($_POST['equipment_id'] ?? 0);
                $reason = trim($_POST['return_reason'] ?? '');
                if ($eqId <= 0 || $reason === '') {
                    echo json_encode(['success' => false, 'message' => 'Equipment id and return reason required']);
                    exit();
                }

                // load eq + assigned customer
                $stmt = $conn->prepare("SELECT serial, type, status, assigned_to_customer_id FROM equipment WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $eqId);
                $stmt->execute();
                $eq = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$eq) {
                    echo json_encode(['success' => false, 'message' => 'Equipment not found']);
                    exit();
                }
                if ($eq['status'] !== 'assigned') {
                    echo json_encode(['success' => false, 'message' => 'Only assigned items can be returned']);
                    exit();
                }

                $stmt = $conn->prepare("UPDATE equipment SET status='returned', returned_at=NOW(), return_reason=? WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("si", $reason, $eqId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    echo json_encode(['success' => false, 'message' => 'Return failed']);
                    exit();
                }

                // clear customers.router_serial if matches
                if ($eq['assigned_to_customer_id']) {
                    $stmt = $conn->prepare("UPDATE customers SET router_serial = NULL WHERE id = ? AND router_serial = ? AND is_deleted = 0");
                    $stmt->bind_param("is", $eq['assigned_to_customer_id'], $eq['serial']);
                    $stmt->execute();
                    $stmt->close();
                }

                logActivity($user_id, $username, 'Equipment Returned', "{$eq['type']} {$eq['serial']} — reason: $reason");
                try { createNotificationForAdmins('Equipment Returned', htmlspecialchars($eq['serial']) . ' returned. Reason: ' . htmlspecialchars($reason), 'info', 'equipment.php'); } catch (Exception $e) {}
                echo json_encode(['success' => true, 'message' => 'Equipment returned']);
                exit();
            }

            case 'markLost': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $eqId   = (int)($_POST['equipment_id'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                if ($eqId <= 0 || $reason === '') {
                    echo json_encode(['success' => false, 'message' => 'Equipment id and reason required']);
                    exit();
                }

                $stmt = $conn->prepare("SELECT serial, type, status, assigned_to_customer_id FROM equipment WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $eqId);
                $stmt->execute();
                $eq = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$eq) {
                    echo json_encode(['success' => false, 'message' => 'Equipment not found']);
                    exit();
                }
                if ($eq['status'] !== 'assigned') {
                    echo json_encode(['success' => false, 'message' => 'Only assigned items can be marked lost']);
                    exit();
                }

                $tagged = "LOST: $reason";
                $stmt = $conn->prepare("UPDATE equipment SET status='lost', returned_at=NOW(), return_reason=? WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("si", $tagged, $eqId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    echo json_encode(['success' => false, 'message' => 'Mark lost failed']);
                    exit();
                }

                // clear customers.router_serial if matches
                if ($eq['assigned_to_customer_id']) {
                    $stmt = $conn->prepare("UPDATE customers SET router_serial = NULL WHERE id = ? AND router_serial = ? AND is_deleted = 0");
                    $stmt->bind_param("is", $eq['assigned_to_customer_id'], $eq['serial']);
                    $stmt->execute();
                    $stmt->close();
                }

                logActivity($user_id, $username, 'Equipment Lost', "{$eq['type']} {$eq['serial']} — $reason");
                try { createNotificationForAdmins('Equipment Lost', htmlspecialchars($eq['serial']) . ' marked LOST: ' . htmlspecialchars($reason), 'warning', 'equipment.php'); } catch (Exception $e) {}
                echo json_encode(['success' => true, 'message' => 'Marked as lost']);
                exit();
            }

            case 'deleteEquipment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!can('equipment', 'd')) {
                    echo json_encode(['success' => false, 'message' => 'You are not allowed to delete equipment']);
                    exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }

                $stmt = $conn->prepare("SELECT serial, type, status FROM equipment WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Equipment not found']);
                    exit();
                }
                if ($row['status'] === 'assigned') {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete assigned equipment — return it first']);
                    exit();
                }

                $stmt = $conn->prepare("UPDATE equipment SET is_deleted = 1 WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Equipment Deleted', "Deleted {$row['type']}: {$row['serial']}");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Equipment deleted']);
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete']);
                }
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Equipment.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

$canDelete = can('equipment', 'd');
$currency  = getSetting('currency_symbol', '$');
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Equipment Inventory - ISP Billing</title>

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
                <span>Equipment</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-server"></i> Equipment Inventory</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- summary strip -->
            <div class="summary-grid" id="summaryStrip">
                <div class="summary-card">
                    <div class="summary-card-label"><i class="fas fa-boxes-stacked"></i> Total Equipment</div>
                    <div class="summary-card-value" id="sumTotal">—</div>
                </div>
                <div class="summary-card success">
                    <div class="summary-card-label"><i class="fas fa-warehouse"></i> In Stock</div>
                    <div class="summary-card-value" id="sumInStock">—</div>
                </div>
                <div class="summary-card highlight">
                    <div class="summary-card-label"><i class="fas fa-link"></i> Assigned</div>
                    <div class="summary-card-value" id="sumAssigned">—</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-card-label"><i class="fas fa-triangle-exclamation"></i> Lost / Damaged</div>
                    <div class="summary-card-value" id="sumLostDmg">—</div>
                </div>
                <div class="summary-card">
                    <div class="summary-card-label"><i class="fas fa-sack-dollar"></i> Total Asset Value</div>
                    <div class="summary-card-value" id="sumAssetValue">—</div>
                </div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Inventory</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadAll()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('equipment', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Equipment
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
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-network-wired"></i> Type</label>
                            <select id="filterType">
                                <option value="">All Types</option>
                                <option value="router">Router</option>
                                <option value="onu">ONU</option>
                                <option value="switch">Switch</option>
                                <option value="cable">Cable</option>
                                <option value="splitter">Splitter</option>
                                <option value="antenna">Antenna</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-flag"></i> Status</label>
                            <select id="filterStatus">
                                <option value="">All Statuses</option>
                                <option value="in_stock">In Stock</option>
                                <option value="assigned">Assigned</option>
                                <option value="returned">Returned</option>
                                <option value="lost">Lost</option>
                                <option value="damaged">Damaged</option>
                                <option value="retired">Retired</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-tag"></i> Brand</label>
                            <select id="filterBrand">
                                <option value="">All Brands</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-magnifying-glass"></i> Search</label>
                            <input type="text" id="filterSearch" class="filter-input" placeholder="Serial / MAC / Model">
                        </div>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap" class="table-responsive">
                    <table class="display table-full-width">
                        <thead>
                            <tr>
                                <th>Serial</th><th>Type</th><th>Brand/Model</th><th>MAC</th>
                                <th>Status</th><th>Assigned To</th><th>Assigned</th><th>Cost</th>
                                <th>Location</th><th>Actions</th>
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
                    <table id="equipTable" class="display table-full-width"></table>
                </div>

                <!-- empty -->
                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-server"></i></div>
                    <div class="empty-state-title">No equipment yet — start your inventory.</div>
                    <div class="empty-state-text">Click Add Equipment to register your first router.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- equipment add/edit modal -->
    <div class="modal-overlay" id="equipModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Equipment</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="equipForm" enctype="multipart/form-data">
                    <input type="hidden" id="equipId" name="id">
                    <input type="hidden" id="existingPhotosJson" name="existing_photos_json" value="[]">

                    <h4 class="form-section-title"><i class="fas fa-id-card"></i> Identity</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-network-wired"></i> Type *</label>
                            <select id="type" name="type" required>
                                <option value="">Select type...</option>
                                <option value="router">Router</option>
                                <option value="onu">ONU</option>
                                <option value="switch">Switch</option>
                                <option value="cable">Cable</option>
                                <option value="splitter">Splitter</option>
                                <option value="antenna">Antenna</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Brand</label>
                            <input type="text" id="brand" name="brand" placeholder="e.g. Mikrotik, TP-Link">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-microchip"></i> Model</label>
                            <input type="text" id="model" name="model" placeholder="e.g. hAP ac²">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-barcode"></i> Serial *</label>
                            <input type="text" id="serial" name="serial" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-fingerprint"></i> MAC Address</label>
                            <input type="text" id="mac" name="mac" placeholder="AA:BB:CC:DD:EE:FF">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Asset Tag</label>
                            <input type="text" id="asset_tag" name="asset_tag" placeholder="e.g. RTR-0001">
                            <small class="form-hint">Auto-suggested from type + sequence</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-mobile-screen"></i> IMEI (cellular)</label>
                            <input type="text" id="imei" name="imei" placeholder="15-digit IMEI">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Firmware Version</label>
                            <input type="text" id="firmware_version" name="firmware_version" placeholder="e.g. 6.49.10">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-microchip"></i> Hardware Revision</label>
                            <input type="text" id="hardware_revision" name="hardware_revision" placeholder="e.g. v2">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-money-check-dollar"></i> Purchase &amp; Accounting</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-money-bill"></i> Purchase Cost</label>
                            <input type="number" id="purchase_cost" name="purchase_cost" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Purchase Date</label>
                            <input type="date" id="purchase_date" name="purchase_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-truck"></i> Vendor</label>
                            <input type="text" id="vendor_name" name="vendor_name" placeholder="Supplier name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-invoice-dollar"></i> Purchase Invoice (Expense)</label>
                            <select id="purchase_invoice_id" name="purchase_invoice_id">
                                <option value="">— None —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-shield-halved"></i> Warranty Start</label>
                            <input type="date" id="warranty_start_date" name="warranty_start_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-shield-halved"></i> Warranty End</label>
                            <input type="date" id="warranty_end_date" name="warranty_end_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-headset"></i> Warranty Contact</label>
                            <input type="text" id="warranty_contact" name="warranty_contact" placeholder="Vendor support contact">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hourglass"></i> Expected Lifetime (months)</label>
                            <input type="number" id="expected_lifetime_months" name="expected_lifetime_months" min="1" placeholder="e.g. 60">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-chart-line-down"></i> Depreciation Method</label>
                            <select id="depreciation_method" name="depreciation_method">
                                <option value="straight_line">Straight-Line</option>
                                <option value="reducing_balance">Reducing Balance</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Depreciation % per Year</label>
                            <input type="number" id="depreciation_pct_per_year" name="depreciation_pct_per_year" step="0.01" min="0" max="100" value="20">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Salvage Value</label>
                            <input type="number" id="salvage_value" name="salvage_value" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calculator"></i> Current Book Value</label>
                            <input type="text" id="current_book_value_display" readonly placeholder="Auto-computed">
                            <small class="form-hint">cost - (depr% × years), floored at salvage</small>
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-handshake"></i> Ownership</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-house-chimney-window"></i> Rented to Customer</label>
                            <div class="toggle-row">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="is_rented" name="is_rented" value="1" class="toggle-input">
                                    <label for="is_rented" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                                <span class="toggle-text">On rental</span>
                            </div>
                        </div>
                        <div class="form-group initially-hidden" id="rentalChargeGroup">
                            <label><i class="fas fa-money-bill-trend-up"></i> Monthly Rental</label>
                            <input type="number" id="monthly_rental_charge" name="monthly_rental_charge" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-cart-shopping"></i> Sold</label>
                            <div class="toggle-row">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="is_sold" name="is_sold" value="1" class="toggle-input">
                                    <label for="is_sold" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                                <span class="toggle-text">Sold to customer</span>
                            </div>
                        </div>
                        <div class="form-group initially-hidden" id="soldAmountGroup">
                            <label><i class="fas fa-money-bill-wave"></i> Sold Amount</label>
                            <input type="number" id="sold_amount" name="sold_amount" step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hand-holding"></i> Loaned</label>
                            <div class="toggle-row">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="is_loaned" name="is_loaned" value="1" class="toggle-input">
                                    <label for="is_loaned" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                                <span class="toggle-text">On loan</span>
                            </div>
                        </div>
                        <div class="form-group initially-hidden" id="loanDueGroup">
                            <label><i class="fas fa-calendar-day"></i> Loan Due Date</label>
                            <input type="date" id="loan_due_date" name="loan_due_date">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-user-tag"></i> Assignment</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Assigned to Staff</label>
                            <select id="assigned_user_id" name="assigned_user_id">
                                <option value="">— None —</option>
                            </select>
                            <small class="form-hint">For staff laptops/phones (alt to customer assignment)</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Area / Site</label>
                            <select id="area_id" name="area_id">
                                <option value="">— None —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-warehouse"></i> Location</label>
                            <input type="text" id="location" name="location" placeholder="Main Warehouse" value="Main Warehouse">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-network-wired"></i> Network Topology</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-sitemap"></i> Parent Equipment</label>
                            <select id="parent_equipment_id" name="parent_equipment_id">
                                <option value="">— None —</option>
                            </select>
                            <small class="form-hint">e.g. ONU under specific OLT</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-plug"></i> Port Number</label>
                            <input type="text" id="port_number" name="port_number" placeholder="e.g. 1/0/3">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-screwdriver-wrench"></i> Maintenance</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-magnifying-glass"></i> Last Inspection</label>
                            <input type="date" id="last_inspection_date" name="last_inspection_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Next Inspection</label>
                            <input type="date" id="next_inspection_date" name="next_inspection_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hammer"></i> Last Maintenance</label>
                            <input type="date" id="last_maintenance_date" name="last_maintenance_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-clipboard-check"></i> Condition</label>
                            <select id="equipment_condition" name="equipment_condition">
                                <option value="new">New</option>
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                                <option value="needs_repair">Needs Repair</option>
                                <option value="scrapped">Scrapped</option>
                            </select>
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-tower-cell"></i> Physical (Towers/OLTs)</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-location-crosshairs"></i> GPS Latitude</label>
                            <input type="number" id="gps_lat" name="gps_lat" step="0.0000001" placeholder="31.4695">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-location-crosshairs"></i> GPS Longitude</label>
                            <input type="number" id="gps_lng" name="gps_lng" step="0.0000001" placeholder="74.2728">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-ruler-vertical"></i> Mount Height (m)</label>
                            <input type="number" id="mount_height_meters" name="mount_height_meters" min="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-bolt"></i> Power Source</label>
                            <select id="power_source" name="power_source">
                                <option value="">— Select —</option>
                                <option value="mains">Mains</option>
                                <option value="battery">Battery</option>
                                <option value="solar">Solar</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-battery-full"></i> Battery Backup (hrs)</label>
                            <input type="number" id="battery_backup_hours" name="battery_backup_hours" min="0">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-eye"></i> Monitoring</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-triangle-exclamation"></i> Critical</label>
                            <div class="toggle-row">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="is_critical" name="is_critical" value="1" class="toggle-input">
                                    <label for="is_critical" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                                <span class="toggle-text">Critical infrastructure</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-satellite-dish"></i> Monitoring</label>
                            <div class="toggle-row">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="monitoring_enabled" name="monitoring_enabled" value="1" class="toggle-input">
                                    <label for="monitoring_enabled" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                                <span class="toggle-text">SNMP monitoring</span>
                            </div>
                        </div>
                        <div class="form-group initially-hidden" id="snmpCommunityGroup">
                            <label><i class="fas fa-key"></i> SNMP Community</label>
                            <input type="text" id="snmp_community" name="snmp_community" placeholder="public">
                        </div>
                        <div class="form-group initially-hidden" id="snmpIpGroup">
                            <label><i class="fas fa-globe"></i> SNMP IP</label>
                            <input type="text" id="snmp_ip" name="snmp_ip" placeholder="10.0.0.1">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-image"></i> Photos</h4>
                    <div class="form-grid">
                        <div class="form-group form-group-wide">
                            <label><i class="fas fa-cloud-arrow-up"></i> Upload Photos (jpg/png/webp, max 5MB each)</label>
                            <input type="file" id="photos" name="photos[]" multiple accept=".jpg,.jpeg,.png,.webp">
                        </div>
                        <div class="form-group form-group-wide" id="existingPhotosWrap">
                            <label><i class="fas fa-images"></i> Existing Photos</label>
                            <div id="existingPhotosGrid" class="eq-photo-grid"></div>
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-note-sticky"></i> Notes</h4>
                    <div class="form-grid">
                        <div class="form-group form-group-wide">
                            <label><i class="fas fa-note-sticky"></i> Notes</label>
                            <textarea id="notes" name="notes" rows="3"></textarea>
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

    <!-- Equipment View Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-server"></i> Equipment Detail</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="vtab-nav">
                    <button class="vtab-btn active" type="button" data-vtab="vtDetails"><i class="fas fa-circle-info"></i> Details</button>
                    <button class="vtab-btn" type="button" data-vtab="vtHistory"><i class="fas fa-clock-rotate-left"></i> History</button>
                </div>
                <div class="vtab-pane active" id="vtDetails"></div>
                <div class="vtab-pane" id="vtHistory"></div>
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
    </script>

    <script>
    let equipTable;
    let isEditMode = false;
    let equipData  = [];
    let activeCustomers = [];
    let activeUsersList = [];
    let activeAreasList = [];
    let activeExpenses  = [];
    let parentEqList    = [];
    let existingPhotos  = []; // editing equipment current photos
    const CAN_DELETE = <?php echo $canDelete ? 'true' : 'false'; ?>;
    const CURRENCY   = <?php echo json_encode($currency); ?>;

    $(document).ready(function() {
        // SD wiring — table filters
        new SearchableDropdown({ selectId: 'type', placeholder: 'Select type...', icon: 'fas fa-network-wired' });
        new SearchableDropdown({ selectId: 'filterType', placeholder: 'All Types', icon: 'fas fa-network-wired' });
        new SearchableDropdown({ selectId: 'filterStatus', placeholder: 'All Statuses', icon: 'fas fa-flag' });
        new SearchableDropdown({ selectId: 'filterBrand', placeholder: 'All Brands', icon: 'fas fa-tag' });
        // SD wiring — modal selects
        new SearchableDropdown({ selectId: 'depreciation_method', placeholder: 'Method...', icon: 'fas fa-chart-line' });
        new SearchableDropdown({ selectId: 'equipment_condition', placeholder: 'Condition...', icon: 'fas fa-clipboard-check' });
        new SearchableDropdown({ selectId: 'power_source', placeholder: 'Power...', icon: 'fas fa-bolt' });
        new SearchableDropdown({ selectId: 'assigned_user_id', placeholder: 'Assign to staff...', icon: 'fas fa-user-tie' });
        new SearchableDropdown({ selectId: 'area_id', placeholder: 'Area / site...', icon: 'fas fa-map-marker-alt' });
        new SearchableDropdown({ selectId: 'parent_equipment_id', placeholder: 'Parent equipment...', icon: 'fas fa-sitemap' });
        new SearchableDropdown({ selectId: 'purchase_invoice_id', placeholder: 'Linked expense...', icon: 'fas fa-file-invoice-dollar' });

        loadActiveCustomers();
        loadActiveUsers();
        loadActiveAreas();
        loadActiveExpenses();
        loadParentEquipment();
        loadDistinctBrands(() => loadAll());
        rsImport.init({ entity: 'Equipment', onDone: loadAll });

        // filter wiring
        $('#filterType, #filterStatus, #filterBrand').on('change', applyFilters);
        $('#filterSearch').on('input', applyFilters);
        // ownership toggles
        $('#is_rented').on('change', () => toggleGroup('is_rented','rentalChargeGroup','monthly_rental_charge'));
        $('#is_sold').on('change',   () => toggleGroup('is_sold','soldAmountGroup','sold_amount'));
        $('#is_loaned').on('change', () => toggleGroup('is_loaned','loanDueGroup','loan_due_date'));
        // monitoring toggle
        $('#monitoring_enabled').on('change', toggleSnmpGroups);
        // book value live recompute
        $('#purchase_cost, #purchase_date, #depreciation_method, #depreciation_pct_per_year, #salvage_value').on('input change', updateBookValueDisplay);
        // auto-suggest asset_tag
        $('#type').on('change', autosuggestAssetTag);
    });

    function toggleGroup(toggleId, groupId, inputId) {
        const on = document.getElementById(toggleId).checked;
        const el = document.getElementById(groupId);
        if (on) { el.classList.remove('initially-hidden'); el.style.display = ''; }
        else    { el.classList.add('initially-hidden');    el.style.display = 'none';
                  const inp = document.getElementById(inputId); if (inp) inp.value = ''; }
    }

    function toggleSnmpGroups() {
        const on = document.getElementById('monitoring_enabled').checked;
        ['snmpCommunityGroup','snmpIpGroup'].forEach(id => {
            const el = document.getElementById(id);
            if (on) { el.classList.remove('initially-hidden'); el.style.display = ''; }
            else    { el.classList.add('initially-hidden');    el.style.display = 'none';
                      const inp = el.querySelector('input'); if (inp) inp.value = ''; }
        });
    }

    function updateBookValueDisplay() {
        const cost = parseFloat(document.getElementById('purchase_cost').value || '0');
        const pdate = document.getElementById('purchase_date').value;
        const method = document.getElementById('depreciation_method').value || 'straight_line';
        const pct = parseFloat(document.getElementById('depreciation_pct_per_year').value || '0');
        const salvage = parseFloat(document.getElementById('salvage_value').value || '0');
        const out = document.getElementById('current_book_value_display');
        if (!cost || !pdate || method === 'none') { out.value = fmtMoney(cost); return; }
        const start = new Date(pdate);
        const months = Math.max(0, Math.floor((Date.now() - start.getTime()) / (30.4375 * 86400000)));
        const years  = months / 12.0;
        let bv;
        if (method === 'straight_line') {
            bv = cost - (cost * (pct/100) * years);
        } else { // reducing_balance
            bv = cost * Math.pow(1 - (pct/100), years);
        }
        if (bv < salvage) bv = salvage;
        if (bv < 0) bv = 0;
        out.value = fmtMoney(bv);
    }

    function autosuggestAssetTag() {
        if (isEditMode) return;
        const tagEl = document.getElementById('asset_tag');
        if (tagEl.dataset.touched === '1') return;
        const t = (document.getElementById('type').value || '').toUpperCase();
        if (!t) { tagEl.value = ''; return; }
        const seq = ((equipData || []).filter(r => r.type === t.toLowerCase()).length + 1).toString().padStart(4, '0');
        const prefix = { ROUTER:'RTR', ONU:'ONU', SWITCH:'SWT', CABLE:'CBL', SPLITTER:'SPL', ANTENNA:'ANT', OTHER:'EQP' }[t] || 'EQP';
        tagEl.value = `${prefix}-${seq}`;
    }
    document.addEventListener('DOMContentLoaded', function() {
        const t = document.getElementById('asset_tag');
        if (t) t.addEventListener('input', () => { t.dataset.touched = '1'; });
    });

    function loadActiveUsers() {
        $.getJSON('?action=getActiveUsers', function(resp) {
            if (!resp.success) return;
            activeUsersList = resp.data;
            const sel = document.getElementById('assigned_user_id');
            sel.innerHTML = '<option value="">— None —</option>' +
                resp.data.map(u => `<option value="${u.id}">${escapeHtml(u.name)} (${escapeHtml(u.role)})</option>`).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function loadActiveAreas() {
        $.getJSON('?action=getActiveAreas', function(resp) {
            if (!resp.success) return;
            activeAreasList = resp.data;
            const sel = document.getElementById('area_id');
            sel.innerHTML = '<option value="">— None —</option>' +
                resp.data.map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function loadActiveExpenses() {
        $.getJSON('?action=getActiveExpenses', function(resp) {
            if (!resp.success) return;
            activeExpenses = resp.data;
            const sel = document.getElementById('purchase_invoice_id');
            sel.innerHTML = '<option value="">— None —</option>' +
                resp.data.map(e => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function loadParentEquipment(excludeId) {
        const q = excludeId ? '&exclude_id=' + excludeId : '';
        $.getJSON('?action=getParentEquipmentList' + q, function(resp) {
            if (!resp.success) return;
            parentEqList = resp.data;
            const sel = document.getElementById('parent_equipment_id');
            sel.innerHTML = '<option value="">— None —</option>' +
                resp.data.map(e => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function renderExistingPhotos() {
        const grid = document.getElementById('existingPhotosGrid');
        const wrap = document.getElementById('existingPhotosWrap');
        if (!existingPhotos.length) {
            grid.innerHTML = '<span class="muted">No photos yet</span>';
            return;
        }
        grid.innerHTML = existingPhotos.map((p, i) => `
            <div class="eq-photo-tile">
                <img src="${escapeHtml(p)}" alt="photo ${i+1}">
                <button type="button" class="eq-photo-rm" onclick="removeExistingPhoto(${i})" title="Remove">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `).join('');
        document.getElementById('existingPhotosJson').value = JSON.stringify(existingPhotos);
    }

    function removeExistingPhoto(idx) {
        existingPhotos.splice(idx, 1);
        renderExistingPhotos();
    }

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function fmtMoney(v) {
        const n = Number(v) || 0;
        return CURRENCY + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    // iso/date -> readable; empty stays empty
    function fmtDate(v) {
        if (!v) return '';
        const d = new Date(v);
        if (isNaN(d)) return v;
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
    }

    function loadAll() {
        loadSummary();
        loadEquipment();
    }

    function loadSummary() {
        $.getJSON('?action=getEquipmentSummary', function(resp) {
            if (!resp.success) return;
            const d = resp.data;
            $('#sumTotal').text(d.total);
            $('#sumInStock').text(d.in_stock);
            $('#sumAssigned').text(d.assigned);
            $('#sumLostDmg').text(d.lost_dmg);
            $('#sumAssetValue').text(fmtMoney(d.asset_value));
        });
    }

    function loadActiveCustomers() {
        $.getJSON('?action=getActiveCustomers', function(resp) {
            if (resp.success) activeCustomers = resp.data;
        });
    }

    function loadDistinctBrands(after) {
        $.getJSON('?action=getDistinctBrands', function(resp) {
            if (resp.success) {
                const sel = document.getElementById('filterBrand');
                sel.innerHTML = '<option value="">All Brands</option>' +
                    resp.data.map(b => `<option value="${escapeHtml(b)}">${escapeHtml(b)}</option>`).join('');
                sel._sd && sel._sd.refresh();
            }
            if (after) after();
        });
    }

    function loadEquipment() {
        $('#skeletonWrap').show();
        $('#tableWrap').hide();
        $('#emptyState').hide();

        $.ajax({
            url: '?action=getEquipment',
            method: 'GET',
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    equipData = resp.data;
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
                } else {
                    $('#skeletonWrap').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' });
                }
            },
            error: function() {
                $('#skeletonWrap').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
            }
        });
    }

    function typeBadge(t)   { return `<span class="equip-type ${escapeHtml(t)}">${escapeHtml(t)}</span>`; }
    function statusBadge(s) { return `<span class="equip-status ${escapeHtml(s)}">${escapeHtml(s).replace('_',' ')}</span>`; }

    function initTable(data) {
        if (equipTable) { equipTable.destroy(); $('#equipTable').empty(); }
        setTimeout(() => {
            equipTable = $('#equipTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    {
                        data: 'serial', title: 'Serial',
                        render: (d, t, row) => `<a href="#" onclick="copySerial('${escapeHtml(d)}'); return false;" title="Click to copy"><strong>${escapeHtml(d)}</strong></a>`
                    },
                    { data: 'type', title: 'Type', render: d => typeBadge(d) },
                    {
                        data: null, title: 'Brand / Model',
                        render: (d, t, row) => {
                            const b = row.brand ? escapeHtml(row.brand) : '';
                            const m = row.model ? escapeHtml(row.model) : '';
                            if (!b && !m) return '<span class="muted">—</span>';
                            return `<strong>${b}</strong>${b && m ? ' / ' : ''}${m}`;
                        }
                    },
                    { data: 'mac', title: 'MAC', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'status', title: 'Status', render: d => statusBadge(d) },
                    {
                        data: null, title: 'Assigned To',
                        render: (d, t, row) => {
                            if (!row.assigned_to_customer_id) return '<span class="muted">—</span>';
                            return `<a href="customers.php?id=${row.assigned_to_customer_id}"><strong>${escapeHtml(row.customer_code)}</strong> ${escapeHtml(row.customer_name)}</a>`;
                        }
                    },
                    {
                        data: 'assigned_at', title: 'Assigned At',
                        render: function(d, t) {
                            // sort/filter on raw iso, display formatted
                            if (t === 'sort' || t === 'type') return d || '';
                            return d ? escapeHtml(fmtDate(d)) : '<span class="muted">—</span>';
                        }
                    },
                    { data: 'purchase_cost', title: 'Cost', render: d => `<span class="num-cell">${fmtMoney(d)}</span>` },
                    { data: 'location', title: 'Location', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: (d, t, row) => {
                            const sr = escapeHtml(row.serial);
                            let html = `<button class="action-icon" onclick="viewEquipment(${row.id})" title="View"><i class="fas fa-eye"></i></button>`;
                            if (row.status === 'in_stock') {
                                html += `<button class="action-icon" onclick="assignEquipment(${row.id}, '${sr}')" title="Assign"><i class="fas fa-user-plus"></i></button>`;
                            }
                            if (row.status === 'assigned') {
                                html += `<button class="action-icon" onclick="returnEquipment(${row.id}, '${sr}')" title="Return"><i class="fas fa-undo"></i></button>`;
                            }
                            // overflow menu
                            let menu = `<button class="act-more-item" onclick='editEquipment(${JSON.stringify(row)})'><i class="fas fa-edit"></i> Edit</button>`;
                            if (row.status === 'assigned') {
                                menu += `<button class="act-more-item" onclick="markLost(${row.id}, '${sr}')"><i class="fas fa-triangle-exclamation"></i> Mark Lost</button>`;
                            }
                            menu += `<button class="act-more-item" onclick="markInspected(${row.id}, '${sr}')"><i class="fas fa-magnifying-glass"></i> Mark Inspected</button>`;
                            menu += `<button class="act-more-item" onclick="markMaintained(${row.id}, '${sr}')"><i class="fas fa-screwdriver-wrench"></i> Mark Maintained</button>`;
                            if (row.status !== 'retired') {
                                menu += `<button class="act-more-item" onclick="decommissionEquipment(${row.id}, '${sr}')"><i class="fas fa-power-off"></i> Decommission</button>`;
                            }
                            menu += `<button class="act-more-item" onclick="disposeEquipment(${row.id}, '${sr}')"><i class="fas fa-recycle"></i> Dispose</button>`;
                            if (CAN_DELETE) {
                                menu += `<button class="act-more-item danger" onclick="deleteEquipment(${row.id}, '${escapeHtml(row.status)}')"><i class="fas fa-trash"></i> Delete</button>`;
                            }
                            html += ` <div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">${menu}</div></div>`;
                            return html;
                        }
                    }
                ],
                pageLength: 25,
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
                order: [[0, 'desc']],
                createdRow: function(tr, row) {
                    // lost/damaged -> danger; decommissioned/disposed/retired -> cancelled
                    if (row.status === 'lost' || row.status === 'damaged') {
                        $(tr).addClass('row-danger');
                    } else if (row.status === 'retired' || row.decommissioned_date || row.disposed_date) {
                        $(tr).addClass('row-cancelled');
                    }
                }
            });
        }, 100);
    }

    function applyFilters() {
        if (!equipTable) return;
        $.fn.dataTable.ext.search = [];
        const t  = document.getElementById('filterType').value;
        const s  = document.getElementById('filterStatus').value;
        const b  = document.getElementById('filterBrand').value;
        const q  = document.getElementById('filterSearch').value.trim().toLowerCase();
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const r = equipData[dataIndex];
            if (!r) return true;
            if (t && r.type !== t) return false;
            if (s && r.status !== s) return false;
            if (b && r.brand !== b) return false;
            if (q) {
                const hay = ((r.serial || '') + ' ' + (r.mac || '') + ' ' + (r.model || '')).toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
        equipTable.draw();
    }

    function clearFilters() {
        ['filterType','filterStatus','filterBrand'].forEach(id => {
            const el = document.getElementById(id);
            el.value = '';
            el._sd && el._sd.refresh();
        });
        document.getElementById('filterSearch').value = '';
        if (equipTable) { $.fn.dataTable.ext.search = []; equipTable.draw(); }
    }

    function copySerial(s) {
        navigator.clipboard.writeText(s).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', text: s, timer: 1200, showConfirmButton: false, toast: true, position: 'top-end' });
        });
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Equipment';
        document.getElementById('equipForm').reset();
        document.getElementById('equipId').value = '';
        document.getElementById('location').value = 'Main Warehouse';
        document.getElementById('purchase_cost').value = '0';
        document.getElementById('depreciation_pct_per_year').value = '20';
        document.getElementById('salvage_value').value = '0';
        document.getElementById('monthly_rental_charge').value = '0';
        document.getElementById('current_book_value_display').value = '';
        document.getElementById('existingPhotosJson').value = '[]';
        existingPhotos = [];
        renderExistingPhotos();
        // reset all toggles
        ['is_rented','is_sold','is_loaned','is_critical','monitoring_enabled'].forEach(id => {
            const el = document.getElementById(id); if (el) el.checked = false;
        });
        // reset SD selects
        ['type','depreciation_method','equipment_condition','power_source','assigned_user_id','area_id','parent_equipment_id','purchase_invoice_id'].forEach(id => {
            const el = document.getElementById(id);
            if (id === 'depreciation_method') el.value = 'straight_line';
            else if (id === 'equipment_condition') el.value = 'good';
            else el.value = '';
            el._sd && el._sd.refresh();
        });
        const tag = document.getElementById('asset_tag'); if (tag) tag.dataset.touched = '0';
        toggleGroup('is_rented','rentalChargeGroup','monthly_rental_charge');
        toggleGroup('is_sold','soldAmountGroup','sold_amount');
        toggleGroup('is_loaned','loanDueGroup','loan_due_date');
        toggleSnmpGroups();
        loadParentEquipment(0);
        document.getElementById('equipModal').classList.add('active');
    }

    function editEquipment(eq) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Equipment';
        document.getElementById('equipId').value         = eq.id;
        document.getElementById('type').value            = eq.type;
        document.getElementById('brand').value           = eq.brand || '';
        document.getElementById('model').value           = eq.model || '';
        document.getElementById('serial').value          = eq.serial;
        document.getElementById('mac').value             = eq.mac || '';
        document.getElementById('purchase_cost').value   = eq.purchase_cost || 0;
        document.getElementById('purchase_date').value   = eq.purchase_date || '';
        document.getElementById('vendor_name').value     = eq.vendor_name || '';
        document.getElementById('location').value        = eq.location || 'Main Warehouse';
        document.getElementById('notes').value           = eq.notes || '';

        // identity
        const tagEl = document.getElementById('asset_tag');
        tagEl.value = eq.asset_tag || '';
        tagEl.dataset.touched = '1';
        document.getElementById('imei').value = eq.imei || '';
        document.getElementById('firmware_version').value = eq.firmware_version || '';
        document.getElementById('hardware_revision').value = eq.hardware_revision || '';

        // accounting
        document.getElementById('warranty_start_date').value = eq.warranty_start_date || '';
        document.getElementById('warranty_end_date').value = eq.warranty_end_date || '';
        document.getElementById('warranty_contact').value = eq.warranty_contact || '';
        document.getElementById('expected_lifetime_months').value = eq.expected_lifetime_months != null ? eq.expected_lifetime_months : '';
        document.getElementById('depreciation_pct_per_year').value = eq.depreciation_pct_per_year != null ? eq.depreciation_pct_per_year : '20';
        document.getElementById('salvage_value').value = eq.salvage_value != null ? eq.salvage_value : '0';

        // ownership
        document.getElementById('is_rented').checked = !!parseInt(eq.is_rented || 0);
        document.getElementById('monthly_rental_charge').value = eq.monthly_rental_charge || '0';
        document.getElementById('is_sold').checked = !!parseInt(eq.is_sold || 0);
        document.getElementById('sold_amount').value = eq.sold_amount != null ? eq.sold_amount : '';
        document.getElementById('is_loaned').checked = !!parseInt(eq.is_loaned || 0);
        document.getElementById('loan_due_date').value = eq.loan_due_date || '';

        // topology
        document.getElementById('port_number').value = eq.port_number || '';

        // maintenance
        document.getElementById('last_inspection_date').value = eq.last_inspection_date || '';
        document.getElementById('next_inspection_date').value = eq.next_inspection_date || '';
        document.getElementById('last_maintenance_date').value = eq.last_maintenance_date || '';

        // physical
        document.getElementById('gps_lat').value = eq.gps_lat != null ? eq.gps_lat : '';
        document.getElementById('gps_lng').value = eq.gps_lng != null ? eq.gps_lng : '';
        document.getElementById('mount_height_meters').value = eq.mount_height_meters != null ? eq.mount_height_meters : '';
        document.getElementById('battery_backup_hours').value = eq.battery_backup_hours != null ? eq.battery_backup_hours : '';

        // monitoring
        document.getElementById('is_critical').checked = !!parseInt(eq.is_critical || 0);
        document.getElementById('monitoring_enabled').checked = !!parseInt(eq.monitoring_enabled || 0);
        document.getElementById('snmp_community').value = eq.snmp_community || '';
        document.getElementById('snmp_ip').value = eq.snmp_ip || '';

        // SD selects
        const setSD = (id, v) => { const el = document.getElementById(id); el.value = v || ''; el._sd && el._sd.refresh(); };
        setSD('type', eq.type);
        setSD('depreciation_method', eq.depreciation_method || 'straight_line');
        setSD('equipment_condition', eq.equipment_condition || 'good');
        setSD('power_source', eq.power_source || '');
        setSD('assigned_user_id', eq.assigned_user_id || '');
        setSD('area_id', eq.area_id || '');
        setSD('purchase_invoice_id', eq.purchase_invoice_id || '');

        // parent equipment dropdown — exclude self, then set value
        loadParentEquipment(eq.id);
        setTimeout(() => setSD('parent_equipment_id', eq.parent_equipment_id || ''), 250);

        // photos
        existingPhotos = Array.isArray(eq.photos) ? eq.photos.slice() : [];
        renderExistingPhotos();

        // toggle visibility groups
        toggleGroup('is_rented','rentalChargeGroup','monthly_rental_charge');
        toggleGroup('is_sold','soldAmountGroup','sold_amount');
        toggleGroup('is_loaned','loanDueGroup','loan_due_date');
        toggleSnmpGroups();
        updateBookValueDisplay();

        document.getElementById('equipModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('equipModal').classList.remove('active');
        document.getElementById('equipForm').reset();
    }

    document.getElementById('equipModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.getElementById('equipForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const action = isEditMode ? 'updateEquipment' : 'addEquipment';
        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=' + action, method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeModal();
                    setTimeout(() => { loadAll(); loadDistinctBrands(); }, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    });

    function viewEquipment(id) {
        $.getJSON('?action=getEquipmentDetail&id=' + id, function(resp) {
            if (!resp.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                return;
            }
            const e = resp.data;
            const rows = [
                ['Serial',          e.serial || '—'],
                ['Asset Tag',       e.asset_tag || '—'],
                ['Type',            e.type || '—'],
                ['Brand / Model',   ((e.brand || '') + ' ' + (e.model || '')).trim() || '—'],
                ['MAC',             e.mac || '—'],
                ['IMEI',            e.imei || '—'],
                ['Firmware / HW',   ((e.firmware_version || '') + ' / ' + (e.hardware_revision || '')).trim() || '—'],
                ['Status',          (e.status || '').replace('_',' ')],
                ['Condition',       e.equipment_condition || '—'],
                ['Assigned To',     e.customer_code ? `${e.customer_code} — ${e.customer_name}` : '—'],
                ['Customer Phone',  e.customer_phone || '—'],
                ['Purchase Cost',   fmtMoney(e.purchase_cost)],
                ['Purchase Date',   fmtDate(e.purchase_date) || '—'],
                ['Vendor',          e.vendor_name || '—'],
                ['Warranty',        (e.warranty_start_date || e.warranty_end_date) ? ((fmtDate(e.warranty_start_date) || '?') + ' → ' + (fmtDate(e.warranty_end_date) || '?')) : '—'],
                ['Depreciation',    (e.depreciation_method || '—') + ' @ ' + (e.depreciation_pct_per_year || 0) + '%/yr'],
                ['Book Value',      e.current_book_value != null ? fmtMoney(e.current_book_value) : '—'],
                ['Salvage Value',   fmtMoney(e.salvage_value || 0)],
                ['Ownership',       [e.is_rented ? 'Rented' : null, e.is_sold ? 'Sold' : null, e.is_loaned ? 'Loaned' : null].filter(x => x).join(', ') || '—'],
                ['Topology',        e.parent_equipment_id ? `Parent #${e.parent_equipment_id} / port ${e.port_number || '—'}` : (e.port_number || '—')],
                ['GPS',             (e.gps_lat && e.gps_lng) ? `${e.gps_lat}, ${e.gps_lng}` : '—'],
                ['Power',           e.power_source ? `${e.power_source} / ${e.battery_backup_hours || 0}h backup` : '—'],
                ['Critical / Monitor', (e.is_critical ? 'CRITICAL' : 'normal') + ' / ' + (e.monitoring_enabled ? 'monitored' : 'off')],
                ['Location',        e.location || '—'],
                ['Notes',           e.notes || '—']
            ];
            // details tab — fields in vtab-grid
            const detailGrid = rows.map(r =>
                `<div class="vtab-field${r[0]==='Notes'?' vtab-field-full':''}"><label>${escapeHtml(r[0])}</label><span>${escapeHtml(r[1])}</span></div>`
            ).join('');
            let photosHtml = '';
            let photoArr = [];
            if (Array.isArray(e.photos) && e.photos.length) {
                photoArr = e.photos;
            } else if (typeof e.photos_json === 'string' && e.photos_json) {
                try { photoArr = JSON.parse(e.photos_json) || []; } catch(_) {}
            }
            if (photoArr.length) {
                photosHtml = '<div class="eq-photo-grid">' +
                    photoArr.map(p => `<div class="eq-photo-tile"><img src="${escapeHtml(p)}" alt="photo"></div>`).join('') + '</div>';
            }
            document.getElementById('vtDetails').innerHTML = '<div class="vtab-grid">' + detailGrid + '</div>' + photosHtml;

            // history tab — timeline built from available dates
            const hist = [
                ['Purchased',        e.purchase_date,         e.vendor_name ? 'from ' + e.vendor_name : ''],
                ['Assigned',         e.assigned_at,           e.customer_name ? 'to ' + (e.customer_code || '') + ' ' + e.customer_name : ''],
                ['Returned',         e.returned_at,           e.return_reason || ''],
                ['Last Inspection',  e.last_inspection_date,  ''],
                ['Next Inspection',  e.next_inspection_date,  'scheduled'],
                ['Last Maintenance', e.last_maintenance_date, ''],
                ['Decommissioned',   e.decommissioned_date,   e.decommissioned_reason || ''],
                ['Disposed',         e.disposed_date,         (e.disposed_to ? 'to ' + e.disposed_to + ' ' : '') + (e.disposal_value != null ? '(' + fmtMoney(e.disposal_value) + ')' : '')]
            ].filter(h => h[1]);
            let histHtml;
            if (hist.length) {
                histHtml = '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>' +
                    '<th class="text-left">Event</th><th class="text-left">Date</th><th class="text-left">Detail</th></tr></thead><tbody>' +
                    hist.map(h => `<tr><th class="text-left">${escapeHtml(h[0])}</th><td>${escapeHtml(fmtDate(h[1]))}</td><td>${escapeHtml(h[2] || '—')}</td></tr>`).join('') +
                    '</tbody></table></div>';
            } else {
                histHtml = '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-clock-rotate-left"></i></div>' +
                    '<div class="empty-state-title">No history yet.</div>' +
                    '<div class="empty-state-text">Assignment and lifecycle events will appear here.</div></div>';
            }
            document.getElementById('vtHistory').innerHTML = histHtml;

            // reset to first tab
            $('#viewModal .vtab-btn').removeClass('active').first().addClass('active');
            $('#viewModal .vtab-pane').removeClass('active');
            document.getElementById('vtDetails').classList.add('active');
            document.getElementById('viewModal').classList.add('active');
        });
    }

    function closeViewModal() {
        document.getElementById('viewModal').classList.remove('active');
    }

    document.getElementById('viewModal').addEventListener('click', function(e) {
        if (e.target === this) closeViewModal();
    });

    // view modal tab switching
    $(document).on('click', '#viewModal .vtab-btn', function() {
        const tgt = $(this).data('vtab');
        $('#viewModal .vtab-btn').removeClass('active');
        $(this).addClass('active');
        $('#viewModal .vtab-pane').removeClass('active');
        document.getElementById(tgt).classList.add('active');
    });

    function assignEquipment(id, serial) {
        if (!activeCustomers.length) {
            Swal.fire({ icon: 'warning', title: 'No customers', text: 'No active customers available to assign.' });
            return;
        }
        const opts = '<option value="">Select customer...</option>' +
            activeCustomers.map(c => `<option value="${c.id}">${escapeHtml(c.customer_code)} — ${escapeHtml(c.full_name)}</option>`).join('');
        Swal.fire({
            title: 'Assign Equipment',
            html: `<p style="margin-bottom:10px">Assign <strong>${escapeHtml(serial)}</strong> to:</p>
                   <select id="swalCustSelect" style="width:100%">${opts}</select>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-link"></i> Assign',
            cancelButtonText: 'Cancel',
            didOpen: () => {
                new SearchableDropdown({ selectId: 'swalCustSelect', placeholder: 'Select customer...', icon: 'fas fa-user' });
            },
            preConfirm: () => {
                const v = document.getElementById('swalCustSelect').value;
                if (!v) { Swal.showValidationMessage('Please select a customer'); return false; }
                return v;
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('equipment_id', id);
            fd.append('customer_id', result.value);
            $.ajax({
                url: '?action=assignEquipment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1800, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function returnEquipment(id, serial) {
        Swal.fire({
            title: 'Return Equipment',
            html: `<p style="margin-bottom:10px">Return <strong>${escapeHtml(serial)}</strong> to stock.</p>
                   <textarea id="swalReason" placeholder="Return reason — e.g. Customer disconnected, Upgrading model" rows="3" style="width:100%"></textarea>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-undo"></i> Return',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const v = document.getElementById('swalReason').value.trim();
                if (!v) { Swal.showValidationMessage('Reason is required'); return false; }
                return v;
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('equipment_id', id);
            fd.append('return_reason', result.value);
            $.ajax({
                url: '?action=returnEquipment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1800, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function markLost(id, serial) {
        Swal.fire({
            title: 'Mark as Lost',
            icon: 'warning',
            html: `<p style="margin-bottom:10px">Mark <strong>${escapeHtml(serial)}</strong> as LOST?</p>
                   <textarea id="swalLostReason" placeholder="Reason — e.g. Customer disappeared, Theft, etc." rows="3" style="width:100%"></textarea>`,
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: '<i class="fas fa-triangle-exclamation"></i> Mark Lost',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const v = document.getElementById('swalLostReason').value.trim();
                if (!v) { Swal.showValidationMessage('Reason is required'); return false; }
                return v;
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('equipment_id', id);
            fd.append('reason', result.value);
            $.ajax({
                url: '?action=markLost', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1800, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function decommissionEquipment(id, serial) {
        Swal.fire({
            title: 'Decommission Equipment',
            icon: 'warning',
            html: `<p style="margin-bottom:10px">Decommission <strong>${escapeHtml(serial)}</strong>?</p>
                   <textarea id="swalDecomReason" placeholder="Reason — e.g. End of life, Hardware failure" rows="3" style="width:100%"></textarea>`,
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: '<i class="fas fa-power-off"></i> Decommission',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const v = document.getElementById('swalDecomReason').value.trim();
                if (!v) { Swal.showValidationMessage('Reason is required'); return false; }
                return v;
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id); fd.append('reason', result.value);
            $.ajax({
                url: '?action=decommissionEquipment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1800, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function disposeEquipment(id, serial) {
        const today = new Date().toISOString().slice(0,10);
        Swal.fire({
            title: 'Dispose Equipment',
            icon: 'warning',
            html: `<p style="margin-bottom:10px">Dispose <strong>${escapeHtml(serial)}</strong>?</p>
                   <input id="swalDispTo" placeholder="Disposed to (e.g. Recycler X)" class="swal2-input" style="width:90%">
                   <input id="swalDispDate" type="date" value="${today}" class="swal2-input" style="width:90%">
                   <input id="swalDispVal" type="number" step="0.01" min="0" placeholder="Disposal value" class="swal2-input" style="width:90%">`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-recycle"></i> Dispose',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const to = document.getElementById('swalDispTo').value.trim();
                const dt = document.getElementById('swalDispDate').value;
                const v  = document.getElementById('swalDispVal').value;
                if (!to || !dt) { Swal.showValidationMessage('Disposed-to and date are required'); return false; }
                return { disposed_to: to, disposed_date: dt, disposal_value: v };
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', id);
            fd.append('disposed_to', result.value.disposed_to);
            fd.append('disposed_date', result.value.disposed_date);
            fd.append('disposal_value', result.value.disposal_value || '');
            $.ajax({
                url: '?action=disposeEquipment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1800, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function markInspected(id, serial) {
        const today = new Date();
        const next = new Date(today.getTime() + 90 * 86400000); // default +90d
        Swal.fire({
            title: 'Mark Inspected',
            html: `<p style="margin-bottom:10px">Mark <strong>${escapeHtml(serial)}</strong> as inspected today.</p>
                   <label style="display:block;margin:8px 0 4px">Next inspection date:</label>
                   <input id="swalNextInsp" type="date" value="${next.toISOString().slice(0,10)}" class="swal2-input" style="width:90%">`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-magnifying-glass"></i> Mark Inspected',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const v = document.getElementById('swalNextInsp').value;
                return v;
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', id);
            fd.append('next_inspection_date', result.value || '');
            $.ajax({
                url: '?action=markInspected', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function markMaintained(id, serial) {
        Swal.fire({
            title: 'Mark Maintained?',
            text: `Log maintenance for ${serial} as today?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-screwdriver-wrench"></i> Yes, log it',
            cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=markMaintained', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function deleteEquipment(id, status) {
        if (status === 'assigned') {
            Swal.fire({ icon: 'warning', title: 'Cannot Delete', text: 'Return the equipment first before deleting.' });
            return;
        }
        Swal.fire({
            icon: 'warning', title: 'Delete Equipment?', text: 'This will soft-delete the record.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteEquipment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1800, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }
    </script>
</body>
</html>
