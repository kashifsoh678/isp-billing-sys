<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: super_admin + admin only (accountant/collector blocked)
if (!isset($_SESSION['user_id']) || !can('areas', 'v')) {
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
$current_page = 'areas';

// allowed coverage tech tokens (SET column)
$ALLOWED_TECH = ['fiber','wireless','dsl'];
// allowed coverage statuses
$ALLOWED_COV  = ['active','planned','no_coverage','saturated'];

// csv import/template contract for this page
function areaImportSpec() {
    return [
        'entity'  => 'Area',
        'table'   => 'areas',
        'perm'    => 'areas',
        'unique'  => ['name'],
        'columns' => [
            'name'        => ['label' => 'Area Name',   'type' => 's', 'required' => true, 'max' => 100, 'demo' => 'Demo Area 1'],
            'area_code'   => ['label' => 'Area Code',   'type' => 's', 'max' => 20,  'demo' => 'DEM-001'],
            'city'        => ['label' => 'City',        'type' => 's', 'max' => 100, 'default' => '', 'demo' => 'Demo City'],
            'province'    => ['label' => 'Province',    'type' => 's', 'max' => 80,  'demo' => 'Demo Province'],
            'district'    => ['label' => 'District',    'type' => 's', 'max' => 80,  'demo' => 'Demo District'],
            'pin_code'    => ['label' => 'Pin Code',    'type' => 's', 'max' => 20,  'demo' => '10001'],
            'country'     => ['label' => 'Country',     'type' => 's', 'max' => 80,  'default' => 'Pakistan', 'demo' => 'Pakistan'],
            'gps_lat'     => ['label' => 'GPS Lat',     'type' => 'd', 'scale' => 7, 'demo' => '31.5204000'],
            'gps_lng'     => ['label' => 'GPS Lng',     'type' => 'd', 'scale' => 7, 'demo' => '74.3587000'],
            'coverage_status' => ['label' => 'Coverage Status', 'type' => 'enum',
                                  'values' => ['active','planned','no_coverage','saturated'], 'default' => 'active', 'demo' => 'active'],
            'total_capacity_subscribers' => ['label' => 'Capacity', 'type' => 'i', 'demo' => '500'],
            'description' => ['label' => 'Description', 'type' => 's', 'demo' => 'Imported from CSV'],
            'status'      => ['label' => 'Status', 'type' => 'enum', 'values' => ['active','inactive'], 'default' => 'active', 'demo' => 'active'],
        ],
    ];
}

// auto-suggest area code from name — first 3 alpha chars upper + sequential
function autoAreaCode($conn, $name) {
    $alpha = preg_replace('/[^A-Za-z]/', '', $name);
    $prefix = strtoupper(substr($alpha !== '' ? $alpha : 'ARE', 0, 3));
    // find next seq for this prefix
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(area_code, ?+2) AS UNSIGNED)) AS m FROM areas WHERE area_code LIKE ?");
    $like = $prefix . '-%';
    $plen = strlen($prefix);
    $stmt->bind_param("is", $plen, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $n = ($row && $row['m']) ? (int)$row['m'] + 1 : 1;
    return sprintf('%s-%03d', $prefix, $n);
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'importCsv':      rsImportRun($conn, areaImportSpec());
            case 'importTemplate': rsImportTemplate(areaImportSpec());

            case 'getAreas':
                // computed counts: customers + assigned collectors
                $sql = "SELECT a.id, a.name, a.city, a.description, a.status, a.created_at,
                               a.area_code, a.country, a.province, a.district, a.pin_code,
                               a.gps_lat, a.gps_lng,
                               a.coverage_status, a.coverage_technology,
                               a.total_capacity_subscribers, a.competitor_presence,
                               a.manager_user_id, a.default_collector_user_id, a.pop_id,
                               mu.name AS manager_name,
                               cu.name AS default_collector_name,
                               (SELECT COUNT(*) FROM customers c WHERE c.area_id = a.id AND c.is_deleted = 0) AS customer_count,
                               (SELECT COUNT(*) FROM users u WHERE u.area_id = a.id AND " . areaRoleInSql('u') . " AND u.is_deleted = 0) AS collector_count
                        FROM areas a
                        LEFT JOIN users mu ON mu.id = a.manager_user_id
                        LEFT JOIN users cu ON cu.id = a.default_collector_user_id
                        WHERE a.is_deleted = 0
                        ORDER BY a.id DESC";
                $res   = $conn->query($sql);
                $areas = [];
                while ($row = $res->fetch_assoc()) {
                    $areas[] = [
                        'id'                          => (int)$row['id'],
                        'name'                        => $row['name'],
                        'city'                        => $row['city'] ?? '',
                        'description'                 => $row['description'] ?? '',
                        'status'                      => $row['status'],
                        'area_code'                   => $row['area_code'] ?? '',
                        'country'                     => $row['country'] ?? '',
                        'province'                    => $row['province'] ?? '',
                        'district'                    => $row['district'] ?? '',
                        'pin_code'                    => $row['pin_code'] ?? '',
                        'gps_lat'                     => $row['gps_lat'] !== null ? (float)$row['gps_lat'] : null,
                        'gps_lng'                     => $row['gps_lng'] !== null ? (float)$row['gps_lng'] : null,
                        'coverage_status'             => $row['coverage_status'] ?? 'active',
                        'coverage_technology'         => $row['coverage_technology'] ?? '',
                        'total_capacity_subscribers'  => $row['total_capacity_subscribers'] !== null ? (int)$row['total_capacity_subscribers'] : null,
                        'competitor_presence'         => $row['competitor_presence'] ?? '',
                        'manager_user_id'             => $row['manager_user_id'] ? (int)$row['manager_user_id'] : null,
                        'manager_name'                => $row['manager_name'],
                        'default_collector_user_id'   => $row['default_collector_user_id'] ? (int)$row['default_collector_user_id'] : null,
                        'default_collector_name'      => $row['default_collector_name'],
                        'pop_id'                      => $row['pop_id'] !== null ? (int)$row['pop_id'] : null,
                        'customer_count'              => (int)$row['customer_count'],
                        'collector_count'             => (int)$row['collector_count'],
                        'created_at'                  => $row['created_at'] ? date('c', strtotime($row['created_at'])) : ''
                    ];
                }
                echo json_encode(['success' => true, 'data' => $areas]);
                exit();

            // area 360 — the people in this area: subscribers + the field staff assigned to it
            case 'getAreaPeople': {
                $aid = (int)($_GET['id'] ?? 0);
                if ($aid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                if (!areaAllowed($aid)) { echo json_encode(['success'=>false,'message'=>'That area is outside your scope']); exit(); }

                // subscribers with their live billing state — one query, no N+1
                $cust = [];
                $sum  = ['active'=>0,'suspended'=>0,'disconnected'=>0,'outstanding'=>0.0,'paid'=>0,'due'=>0];
                $st = $conn->prepare("SELECT c.id, c.customer_code, c.full_name, c.phone, c.connection_status,
                                             c.installation_date, c.last_payment_date,
                                             p.name AS package_name, p.price AS package_price,
                                             COALESCE(o.outstanding, 0) AS outstanding
                                      FROM customers c
                                      LEFT JOIN packages p ON p.id = c.package_id
                                      LEFT JOIN (SELECT customer_id, SUM(balance_amount) outstanding FROM invoices
                                                 WHERE is_deleted = 0 AND status IN ('unpaid','partial','overdue')
                                                 GROUP BY customer_id) o ON o.customer_id = c.id
                                      WHERE c.area_id = ? AND c.is_deleted = 0
                                      ORDER BY c.full_name ASC");
                $st->bind_param("i", $aid);
                $st->execute();
                $r = $st->get_result();
                while ($x = $r->fetch_assoc()) {
                    $out = (float)$x['outstanding'];
                    $cs  = $x['connection_status'] ?? '';
                    if (isset($sum[$cs])) $sum[$cs]++;
                    $sum['outstanding'] += $out;
                    $out <= 0 ? $sum['paid']++ : $sum['due']++;
                    $cust[] = [
                        'id'                => (int)$x['id'],
                        'customer_code'     => $x['customer_code'] ?? '',
                        'full_name'         => $x['full_name'] ?? '',
                        'phone'             => $x['phone'] ?? '',
                        'connection_status' => $cs,
                        'package_name'      => $x['package_name'] ?? '',
                        'package_price'     => (float)($x['package_price'] ?? 0),
                        'installation_date' => $x['installation_date'],
                        'last_payment_date' => $x['last_payment_date'],
                        'outstanding'       => $out,
                        'is_paid'           => $out <= 0 ? 1 : 0
                    ];
                }
                $st->close();
                $sum['outstanding'] = round($sum['outstanding'], 2);
                $sum['customers']   = count($cust);

                // field staff — primary area_id OR an extra area assignment, with what they collected
                $staff = [];
                $st = $conn->prepare("SELECT u.id, u.username, u.name, u.role, u.phone, u.email, u.status, u.last_login,
                                             COALESCE(pc.collected, 0) AS collected, COALESCE(pc.receipts, 0) AS receipts
                                      FROM users u
                                      LEFT JOIN user_areas ua ON ua.user_id = u.id AND ua.area_id = ?
                                      LEFT JOIN (SELECT collected_by, SUM(amount) collected, COUNT(*) receipts
                                                 FROM payments WHERE is_deleted = 0 AND status = 'cleared'
                                                 GROUP BY collected_by) pc ON pc.collected_by = u.id
                                      WHERE u.is_deleted = 0 AND " . areaRoleInSql('u') . "
                                        AND (u.area_id = ? OR ua.area_id IS NOT NULL)
                                      GROUP BY u.id
                                      ORDER BY u.name ASC");
                $st->bind_param("ii", $aid, $aid);
                $st->execute();
                $r = $st->get_result();
                while ($x = $r->fetch_assoc()) {
                    $staff[] = [
                        'id'         => (int)$x['id'],
                        'username'   => $x['username'],
                        'name'       => $x['name'] ?? '',
                        'role'       => $x['role'],
                        'role_label' => getRoleDisplay($x['role']),
                        'phone'      => $x['phone'] ?? '',
                        'email'      => $x['email'] ?? '',
                        'status'     => $x['status'],
                        'last_login' => $x['last_login'],
                        'collected'  => (float)$x['collected'],
                        'receipts'   => (int)$x['receipts']
                    ];
                }
                $st->close();
                $sum['collectors'] = count($staff);

                echo json_encode(['success'=>true,'customers'=>$cust,'staff'=>$staff,'summary'=>$sum]);
                exit();
            }

            case 'getManagerStaff':
                // admin/super_admin pool for area manager dropdown
                $res = $conn->query("SELECT id, name, role FROM users WHERE is_deleted = 0 AND status='active' AND " . roleInSql('users', 'areas', 'v') . " ORDER BY name ASC");
                $rows = [];
                while ($r = $res->fetch_assoc()) $rows[] = ['id'=>(int)$r['id'], 'name'=>$r['name'], 'role'=>$r['role']];
                echo json_encode(['success'=>true, 'data'=>$rows]);
                exit();

            case 'getCollectorStaff':
                // collector pool for default collector dropdown
                $res = $conn->query("SELECT id, name FROM users WHERE is_deleted = 0 AND status='active' AND " . areaRoleInSql('users') . " ORDER BY name ASC");
                $rows = [];
                while ($r = $res->fetch_assoc()) $rows[] = ['id'=>(int)$r['id'], 'name'=>$r['name']];
                echo json_encode(['success'=>true, 'data'=>$rows]);
                exit();

            case 'addArea':
            case 'updateArea':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $isEdit = $_GET['action'] === 'updateArea';
                $id     = (int)($_POST['id'] ?? 0);
                $name   = trim($_POST['name'] ?? '');
                $city   = trim($_POST['city'] ?? '');
                $desc   = trim($_POST['description'] ?? '');
                $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

                // new fields
                $area_code = trim($_POST['area_code'] ?? '');
                $country   = trim($_POST['country'] ?? 'Pakistan');
                if ($country === '') $country = 'Pakistan';
                $province  = trim($_POST['province'] ?? '');
                $district  = trim($_POST['district'] ?? '');
                $pin_code  = trim($_POST['pin_code'] ?? '');
                $competitor = trim($_POST['competitor_presence'] ?? '');

                // gps — nullable decimals
                $gps_lat_in = trim($_POST['gps_lat'] ?? '');
                $gps_lng_in = trim($_POST['gps_lng'] ?? '');
                $gps_lat = ($gps_lat_in === '' ? null : (float)$gps_lat_in);
                $gps_lng = ($gps_lng_in === '' ? null : (float)$gps_lng_in);

                // coverage
                $cov_status = $_POST['coverage_status'] ?? 'active';
                if (!in_array($cov_status, $ALLOWED_COV, true)) $cov_status = 'active';

                // coverage_technology: array (multi) -> CSV (SET column stores comma-list)
                $tech_in  = $_POST['coverage_technology'] ?? [];
                if (!is_array($tech_in)) $tech_in = $tech_in === '' ? [] : explode(',', $tech_in);
                $tech_clean = [];
                foreach ($tech_in as $t) {
                    $t = trim($t);
                    if ($t !== '' && in_array($t, $ALLOWED_TECH, true) && !in_array($t, $tech_clean, true)) $tech_clean[] = $t;
                }
                $cov_tech = implode(',', $tech_clean); // SET column accepts CSV

                $cap_in = trim($_POST['total_capacity_subscribers'] ?? '');
                $cap    = ($cap_in === '' ? null : (int)$cap_in);

                $mgr_in = trim($_POST['manager_user_id'] ?? '');
                $mgr    = ($mgr_in === '' ? null : (int)$mgr_in);
                $col_in = trim($_POST['default_collector_user_id'] ?? '');
                $col_id = ($col_in === '' ? null : (int)$col_in);
                $pop_in = trim($_POST['pop_id'] ?? '');
                $pop    = ($pop_in === '' ? null : (int)$pop_in);

                // validate
                if ($name === '') {
                    echo json_encode(['success' => false, 'message' => 'Area name is required']);
                    exit();
                }
                if ($city === '') {
                    echo json_encode(['success' => false, 'message' => 'City is required']);
                    exit();
                }

                // dup name check
                $dupSql = $isEdit
                    ? "SELECT id FROM areas WHERE name = ? AND id != ? AND is_deleted = 0"
                    : "SELECT id FROM areas WHERE name = ? AND is_deleted = 0";
                $stmt = $conn->prepare($dupSql);
                if ($isEdit) $stmt->bind_param("si", $name, $id);
                else         $stmt->bind_param("s", $name);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Area name already exists']);
                    exit();
                }
                $stmt->close();

                // auto-gen area_code if blank
                if ($area_code === '') {
                    $area_code = autoAreaCode($conn, $name);
                }

                // dup area_code check
                $dupAcSql = $isEdit
                    ? "SELECT id FROM areas WHERE area_code = ? AND id != ? AND is_deleted = 0"
                    : "SELECT id FROM areas WHERE area_code = ? AND is_deleted = 0";
                $stmt = $conn->prepare($dupAcSql);
                if ($isEdit) $stmt->bind_param("si", $area_code, $id);
                else         $stmt->bind_param("s", $area_code);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => "Area code '$area_code' already in use"]);
                    exit();
                }
                $stmt->close();

                if (!$isEdit) {
                    $sql = "INSERT INTO areas
                            (name, city, description, status,
                             area_code, country, province, district, pin_code,
                             gps_lat, gps_lng,
                             coverage_status, coverage_technology,
                             total_capacity_subscribers, competitor_presence,
                             manager_user_id, default_collector_user_id, pop_id)
                            VALUES (?,?,?,?, ?,?,?,?,?, ?,?, ?,?, ?,?, ?,?,?)";
                    $stmt = $conn->prepare($sql);
                    // types: name(s) city(s) desc(s) status(s) | area_code(s) country(s) province(s) district(s) pin_code(s)
                    //        gps_lat(d) gps_lng(d) | cov_status(s) cov_tech(s)
                    //        cap(i) competitor(s) | mgr(i) col_id(i) pop(i)
                    // = ssss sssss dd ss is iii  -> 18 letters
                    $types = "sssssssssddssisiii";
                    $stmt->bind_param(
                        $types,
                        $name, $city, $desc, $status,
                        $area_code, $country, $province, $district, $pin_code,
                        $gps_lat, $gps_lng,
                        $cov_status, $cov_tech,
                        $cap, $competitor,
                        $mgr, $col_id, $pop
                    );
                    if ($stmt->execute()) {
                        logActivity($user_id, $username, 'Area Created', "Created area: $name ($city) [$area_code]");
                        try { createNotificationForAdmins('Area Created', 'Area "' . htmlspecialchars($name) . '" was added.', 'info', 'areas.php'); } catch (Exception $e) {}
                        $stmt->close();
                        echo json_encode(['success' => true, 'message' => 'Area added successfully', 'area_code' => $area_code]);
                    } else {
                        $err = $stmt->error;
                        $stmt->close();
                        echo json_encode(['success' => false, 'message' => 'Failed to add: ' . $err]);
                    }
                    exit();
                }

                // UPDATE branch
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid area id']);
                    exit();
                }
                $sql = "UPDATE areas SET
                            name=?, city=?, description=?, status=?,
                            area_code=?, country=?, province=?, district=?, pin_code=?,
                            gps_lat=?, gps_lng=?,
                            coverage_status=?, coverage_technology=?,
                            total_capacity_subscribers=?, competitor_presence=?,
                            manager_user_id=?, default_collector_user_id=?, pop_id=?
                        WHERE id=? AND is_deleted = 0";
                $stmt = $conn->prepare($sql);
                // 18 set cols + 1 id = 19 vars
                // ssss sssss dd ss is iii i  -> ssssssssssddssisii i  (let's count: s s s s s s s s s d d s s i s i i i i = 19)
                $types = "sssssssssddssisiiii";
                $stmt->bind_param(
                    $types,
                    $name, $city, $desc, $status,
                    $area_code, $country, $province, $district, $pin_code,
                    $gps_lat, $gps_lng,
                    $cov_status, $cov_tech,
                    $cap, $competitor,
                    $mgr, $col_id, $pop,
                    $id
                );
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Area Updated', "Updated area: $name ($city) [$area_code]");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Area updated successfully']);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update: ' . $err]);
                }
                exit();

            case 'deleteArea':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                // super_admin only
                if (!can('areas', 'd')) {
                    echo json_encode(['success' => false, 'message' => 'You are not allowed to delete areas']);
                    exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }

                // block: customers attached
                $check = $conn->prepare("SELECT COUNT(*) AS c FROM customers WHERE area_id = ? AND is_deleted = 0");
                $check->bind_param("i", $id);
                $check->execute();
                $custCnt = (int)($check->get_result()->fetch_assoc()['c'] ?? 0);
                $check->close();
                if ($custCnt > 0) {
                    echo json_encode(['success' => false, 'message' => "Cannot delete: $custCnt customer(s) still assigned to this area"]);
                    exit();
                }

                // block: collectors attached
                $check = $conn->prepare("SELECT COUNT(*) AS c FROM users WHERE area_id = ? AND " . areaRoleInSql('users') . " AND is_deleted = 0");
                $check->bind_param("i", $id);
                $check->execute();
                $colCnt = (int)($check->get_result()->fetch_assoc()['c'] ?? 0);
                $check->close();
                if ($colCnt > 0) {
                    echo json_encode(['success' => false, 'message' => "Cannot delete: $colCnt collector(s) still assigned to this area"]);
                    exit();
                }

                // grab name for log
                $stmt = $conn->prepare("SELECT name FROM areas WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Area not found']);
                    exit();
                }

                // soft del
                $stmt = $conn->prepare("UPDATE areas SET is_deleted = 1, status='inactive' WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Area Deleted', "Deleted area: " . $row['name']);
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Area deleted successfully']);
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Areas.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

$canDelete = can('areas', 'd');
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Service Areas - ISP Billing</title>

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
                <span>Service Areas</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-map-marked-alt"></i> Service Areas</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Areas</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadAreas()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('areas', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Area
                        </button>
                    </div>
                </div>

                <!-- chevron pipeline (status quick-filter) -->
                <div class="chevron-pipeline initially-hidden" id="chevronPipeline" role="tablist" aria-label="Filter areas by status">
                    <div class="chevron-tab cv-all active"      data-status="all"      role="tab" tabindex="0">
                        <i class="fas fa-list"></i> All Areas <span class="cv-count" id="cv-c-all">0</span>
                    </div>
                    <div class="chevron-tab cv-active"          data-status="active"   role="tab" tabindex="0">
                        <i class="fas fa-circle-check"></i> Active <span class="cv-count" id="cv-c-active">0</span>
                    </div>
                    <div class="chevron-tab cv-inactive"        data-status="inactive" role="tab" tabindex="0">
                        <i class="fas fa-circle-minus"></i> Inactive <span class="cv-count" id="cv-c-inactive">0</span>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap" class="table-responsive">
                    <table class="display table-full-width">
                        <thead>
                            <tr>
                                <th>ID</th><th>Code</th><th>Name</th><th>City</th><th>Customers</th>
                                <th>Collectors</th><th>Status</th><th>Created</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr>
                                <?php for ($j = 0; $j < 9; $j++): ?>
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
                    <table id="areasTable" class="display table-full-width"></table>
                </div>

                <!-- empty state -->
                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-map-marked-alt"></i></div>
                    <div class="empty-state-title">No service areas defined.</div>
                    <div class="empty-state-text">Add your first area to start mapping customers.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Area Modal -->
    <div class="modal-overlay" id="areaModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Area</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="areaForm">
                    <input type="hidden" id="areaId" name="id">

                    <h4 class="form-section-title"><i class="fas fa-id-card"></i> Identity</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-map"></i> Area Name *</label>
                            <input type="text" id="name" name="name" required placeholder="e.g. Defence Phase 5">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Area Code</label>
                            <input type="text" id="area_code" name="area_code" placeholder="auto-generated if blank, e.g. DEF-001" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> City *</label>
                            <input type="text" id="city" name="city" placeholder="e.g. Lahore" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-globe"></i> Country</label>
                            <input type="text" id="country" name="country" value="Pakistan" placeholder="Pakistan">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag"></i> Province / State</label>
                            <input type="text" id="province" name="province" placeholder="e.g. Punjab">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-pin"></i> District</label>
                            <input type="text" id="district" name="district" placeholder="e.g. Lahore">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-mail-bulk"></i> PIN / Postal Code</label>
                            <input type="text" id="pin_code" name="pin_code" placeholder="e.g. 54000" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status *</label>
                            <select id="status" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-location-dot"></i> Geo Coordinates</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-globe"></i> GPS Latitude</label>
                            <input type="number" step="0.0000001" id="gps_lat" name="gps_lat" placeholder="e.g. 31.5204">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-globe"></i> GPS Longitude</label>
                            <input type="number" step="0.0000001" id="gps_lng" name="gps_lng" placeholder="e.g. 74.3587">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-tower-broadcast"></i> Coverage</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-signal"></i> Coverage Status</label>
                            <select id="coverage_status" name="coverage_status">
                                <option value="active">Active</option>
                                <option value="planned">Planned</option>
                                <option value="no_coverage">No Coverage</option>
                                <option value="saturated">Saturated</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-network-wired"></i> Coverage Technology</label>
                            <div class="checkbox-group" id="cov_tech_group">
                                <label class="cb-item"><input type="checkbox" name="coverage_technology[]" value="fiber"> <span><i class="fas fa-bolt"></i> Fiber</span></label>
                                <label class="cb-item"><input type="checkbox" name="coverage_technology[]" value="wireless"> <span><i class="fas fa-wifi"></i> Wireless</span></label>
                                <label class="cb-item"><input type="checkbox" name="coverage_technology[]" value="dsl"> <span><i class="fas fa-phone"></i> DSL</span></label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Total Capacity (Subscribers)</label>
                            <input type="number" min="0" id="total_capacity_subscribers" name="total_capacity_subscribers" placeholder="e.g. 500">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Competitor Presence</label>
                            <input type="text" id="competitor_presence" name="competitor_presence" maxlength="255" placeholder="e.g. PTCL, StormFiber">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-user-gear"></i> Assignment</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Area Manager</label>
                            <select id="manager_user_id" name="manager_user_id">
                                <option value="">— None —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Default Collector</label>
                            <select id="default_collector_user_id" name="default_collector_user_id">
                                <option value="">— None —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tower-broadcast"></i> POP ID</label>
                            <input type="number" min="0" id="pop_id" name="pop_id" placeholder="optional">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-info-circle"></i> Description</h4>
                    <div class="form-grid">
                        <div class="form-group full-row">
                            <label><i class="fas fa-info-circle"></i> Description</label>
                            <textarea id="description" name="description" rows="3"></textarea>
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

    <!-- Area View Modal -->
    <!-- area 360 — the area on the left, its people on the right -->
    <div class="modal-overlay" id="areaViewModal">
        <div class="modal modal-360">
            <div class="modal-header">
                <h3 id="viewTitle"><i class="fas fa-map-marked-alt"></i> Area Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="cv-360">
                    <aside class="cv-profile" id="areaProfile"></aside>
                    <section class="cv-data">
                        <div class="vtab-nav" id="areaTabs">
                            <button type="button" class="vtab-btn active" data-atab="customers"><i class="fas fa-users"></i> Customers <span class="cv-count" id="areacount-customers">0</span></button>
                            <button type="button" class="vtab-btn" data-atab="collectors"><i class="fas fa-user-tie"></i> Collectors <span class="cv-count" id="areacount-collectors">0</span></button>
                            <button type="button" class="vtab-btn" data-atab="detail"><i class="fas fa-circle-info"></i> Full Detail</button>
                        </div>
                        <div class="vtab-pane active" id="areapane-customers"><table id="areaCustTable" class="display cv-dt" style="width:100%"></table></div>
                        <div class="vtab-pane" id="areapane-collectors"><table id="areaStaffTable" class="display cv-dt" style="width:100%"></table></div>
                        <div class="vtab-pane" id="areapane-detail"></div>
                    </section>
                </div>
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
    let areasTable;
    let isEditMode  = false;
    const CURRENCY = <?php echo json_encode(getSetting('currency_symbol', '$')); ?>;
    function fmtMoney(n) { return CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    let areasData   = [];
    let managerList = [];
    let collectorList = [];
    const CAN_DELETE = <?php echo $canDelete ? 'true' : 'false'; ?>;

    let chevronStatus = 'all';

    $(document).ready(function() {
        // SD wiring
        new SearchableDropdown({ selectId: 'status', placeholder: 'Status...', icon: 'fas fa-toggle-on', allowClear: false });
        new SearchableDropdown({ selectId: 'coverage_status', placeholder: 'Coverage...', icon: 'fas fa-signal', allowClear: false });
        new SearchableDropdown({ selectId: 'manager_user_id', placeholder: 'Pick manager...', icon: 'fas fa-user-tie' });
        new SearchableDropdown({ selectId: 'default_collector_user_id', placeholder: 'Pick collector...', icon: 'fas fa-money-bill-wave' });

        // chevron pipeline click → set status & re-apply
        $(document).on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('.chevron-tab').removeClass('active');
            $(this).addClass('active');
            chevronStatus = $(this).data('status') || 'all';
            applyFilters();
        });

        // load staff pools then areas
        Promise.all([loadManagers(), loadCollectors()]).then(() => loadAreas());
        rsImport.init({ entity: 'Area', onDone: loadAreas });
    });

    function loadManagers() {
        return $.getJSON('?action=getManagerStaff').then(resp => {
            if (!resp.success) return;
            managerList = resp.data;
            const sel = document.getElementById('manager_user_id');
            managerList.forEach(s => {
                const o = document.createElement('option');
                o.value = s.id; o.textContent = s.name + ' (' + s.role + ')';
                sel.appendChild(o);
            });
            sel._sd && sel._sd.refresh();
        });
    }

    function loadCollectors() {
        return $.getJSON('?action=getCollectorStaff').then(resp => {
            if (!resp.success) return;
            collectorList = resp.data;
            const sel = document.getElementById('default_collector_user_id');
            collectorList.forEach(s => {
                const o = document.createElement('option');
                o.value = s.id; o.textContent = s.name;
                sel.appendChild(o);
            });
            sel._sd && sel._sd.refresh();
        });
    }

    function refreshChevronCounts(rows) {
        const c = { all: rows.length, active: 0, inactive: 0 };
        rows.forEach(r => { if (r.status in c) c[r.status]++; });
        Object.keys(c).forEach(k => {
            const el = document.getElementById('cv-c-' + k);
            if (el) el.textContent = c[k];
        });
    }

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }

    // iso -> "Mon dd, yyyy"
    function fmtDate(d) {
        if (!d) return '';
        const dt = new Date(d);
        return isNaN(dt) ? d : dt.toLocaleDateString('en-US', { day:'2-digit', month:'short', year:'numeric' });
    }

    // area detail modal
    // ── Area 360 — the area on the left, its customers / collectors on the right ──
    let areaCustTable = null, areaStaffTable = null, areaCurrent = null;

    function viewArea(id, tab) {
        const a = (areasData || []).find(x => x.id === parseInt(id, 10));
        if (!a) { Swal.fire({ icon: 'error', title: 'Error', text: 'Area not found' }); return; }
        areaCurrent = a;
        const dash = '<span class="muted-dash">—</span>';
        const v = x => (x === null || x === undefined || x === '') ? dash : escapeHtml(x);
        const field = (lbl, val) => `<div class="cv-field"><label>${lbl}</label><span>${val}</span></div>`;
        const techList = (a.coverage_technology || '').split(',').map(s => s.trim()).filter(Boolean);

        document.getElementById('viewTitle').innerHTML =
            `<i class="fas fa-map-marked-alt"></i> ${escapeHtml(a.name || 'Area')} <span class="muted">(${escapeHtml(a.area_code || '')})</span>`;

        document.getElementById('areaProfile').innerHTML = `
            <div class="cv-id">
                <div class="cv-avatar"><i class="fas fa-map-marked-alt"></i></div>
                <div class="cv-id-text">
                    <strong>${escapeHtml(a.name || '')}</strong>
                    <span class="muted">${escapeHtml(a.city || '')}${a.province ? ', ' + escapeHtml(a.province) : ''}</span>
                </div>
            </div>
            <div class="pkg-rollup" id="areaRollup"><span class="muted">Loading people…</span></div>
            ${field('Status', `<span class="status-pill ${escapeHtml(a.status)}">${escapeHtml(a.status)}</span>`)}
            ${field('Area Code', v(a.area_code))}
            ${field('City', v(a.city))}
            ${field('District', v(a.district))}
            ${field('Province / State', v(a.province))}
            ${field('Country', v(a.country))}
            ${field('PIN / Postal', v(a.pin_code))}
            ${field('GPS', a.gps_lat != null && a.gps_lng != null ? escapeHtml(a.gps_lat + ', ' + a.gps_lng) : dash)}
            ${field('Coverage Status', v(a.coverage_status))}
            ${field('Coverage Technology', v(a.coverage_technology))}
        `;

        const body = `
            <div class="vtab-grid">
                <div class="vtab-field"><label>Area Code</label><span>${v(a.area_code)}</span></div>
                <div class="vtab-field"><label>Name</label><span>${v(a.name)}</span></div>
                <div class="vtab-field"><label>City</label><span>${v(a.city)}</span></div>
                <div class="vtab-field"><label>Status</label><span><span class="status-pill ${a.status}">${escapeHtml(a.status)}</span></span></div>
                <div class="vtab-field"><label>Country</label><span>${v(a.country)}</span></div>
                <div class="vtab-field"><label>Province / State</label><span>${v(a.province)}</span></div>
                <div class="vtab-field"><label>District</label><span>${v(a.district)}</span></div>
                <div class="vtab-field"><label>PIN / Postal</label><span>${v(a.pin_code)}</span></div>
                <div class="vtab-field"><label>GPS</label><span>${a.gps_lat != null && a.gps_lng != null ? escapeHtml(a.gps_lat + ', ' + a.gps_lng) : dash}</span></div>
                <div class="vtab-field"><label>Coverage Status</label><span>${v(a.coverage_status)}</span></div>
                <div class="vtab-field"><label>Coverage Tech</label><span>${techList.length ? escapeHtml(techList.join(', ')) : dash}</span></div>
                <div class="vtab-field"><label>Total Capacity</label><span>${a.total_capacity_subscribers != null ? escapeHtml(a.total_capacity_subscribers) : dash}</span></div>
                <div class="vtab-field"><label>Competitor Presence</label><span>${v(a.competitor_presence)}</span></div>
                <div class="vtab-field"><label>Area Manager</label><span>${v(a.manager_name)}</span></div>
                <div class="vtab-field"><label>Default Collector</label><span>${v(a.default_collector_name)}</span></div>
                <div class="vtab-field"><label>POP ID</label><span>${a.pop_id != null ? escapeHtml(a.pop_id) : dash}</span></div>
                <div class="vtab-field"><label><i class="fas fa-users"></i> Customers</label><span>${escapeHtml(a.customer_count)}</span></div>
                <div class="vtab-field"><label><i class="fas fa-money-bill-wave"></i> Collectors</label><span>${escapeHtml(a.collector_count)}</span></div>
                <div class="vtab-field"><label>Created</label><span>${a.created_at ? escapeHtml(fmtDate(a.created_at)) : dash}</span></div>
                <div class="vtab-field"><label>Description</label><span>${v(a.description)}</span></div>
            </div>`;
        document.getElementById('areapane-detail').innerHTML = body;

        areaShowTab(tab || 'customers');
        document.getElementById('areaViewModal').classList.add('active');
        loadAreaPeople(a.id, tab || 'customers');
    }

    function areaShowTab(t) {
        $('#areaTabs .vtab-btn').removeClass('active').filter('[data-atab="' + t + '"]').addClass('active');
        $('#areaViewModal .vtab-pane').removeClass('active');
        $('#areapane-' + t).addClass('active');
        // a table built in a hidden pane measures 0 — re-measure once it is visible
        const dt = t === 'customers' ? areaCustTable : (t === 'collectors' ? areaStaffTable : null);
        if (dt) { dt.columns.adjust(); dt.responsive && dt.responsive.recalc(); }
    }
    $(document).on('click', '#areaTabs .vtab-btn', function() { areaShowTab($(this).data('atab')); });

    // shared table options — 10/page, exports, responsive (never scrollX alongside it)
    function areaDtOpts(extra) {
        return Object.assign({
            pageLength: 10, lengthChange: false, responsive: true, autoWidth: false,
            dom: 'Bfrtip',
            buttons: [
                { extend: 'csv',   text: '<i class="fas fa-file-csv"></i> CSV',   className: 'btn btn-secondary btn-sm' },
                { extend: 'pdf',   text: '<i class="fas fa-file-pdf"></i> PDF',   className: 'btn btn-secondary btn-sm' },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print',    className: 'btn btn-secondary btn-sm' }
            ]
        }, extra);
    }

    function loadAreaPeople(id, tab) {
        loadExportDeps(function() { // pdf/csv deps are lazy on this page — have them ready before the tables build
        $.getJSON('?action=getAreaPeople&id=' + id, function(resp) {
            if (!resp.success) { $('#areaRollup').html('<span class="muted">' + escapeHtml(resp.message || 'Failed to load') + '</span>'); return; }
            const cust = resp.customers || [], staff = resp.staff || [], s = resp.summary || {};
            $('#areacount-customers').text(cust.length);
            $('#areacount-collectors').text(staff.length);
            $('#areaRollup').html(`
                <div class="pkg-rollup-row"><span><i class="fas fa-plug"></i> Active</span><strong>${s.active || 0}</strong></div>
                <div class="pkg-rollup-row"><span><i class="fas fa-user-clock"></i> Suspended</span><strong>${s.suspended || 0}</strong></div>
                <div class="pkg-rollup-row"><span><i class="fas fa-user-slash"></i> Disconnected</span><strong>${s.disconnected || 0}</strong></div>
                <div class="pkg-rollup-row"><span><i class="fas fa-user-tie"></i> Field staff</span><strong>${s.collectors || 0}</strong></div>
                <div class="pkg-rollup-row total"><span><i class="fas fa-sack-dollar"></i> Outstanding</span><strong>${fmtMoney(s.outstanding || 0)}</strong></div>`);

            if (areaCustTable) { areaCustTable.destroy(); $('#areaCustTable').empty(); }
            areaCustTable = $('#areaCustTable').DataTable(areaDtOpts({
                data: cust,
                order: [[1, 'asc']],
                language: { emptyTable: 'No customers in this area yet.', search: '', searchPlaceholder: 'Search customer…' },
                columns: [
                    { data: 'customer_code', title: 'Code', render: d => escapeHtml(d) },
                    { data: 'full_name', title: 'Customer',
                      render: (d, t, row) => t !== 'display' ? d
                        : `<strong>${escapeHtml(d)}</strong><br><small class="muted">${escapeHtml(row.phone || '')}</small>` },
                    { data: 'package_name', title: 'Package',
                      render: (d, t, row) => t !== 'display' ? (d || '')
                        : (d ? `${escapeHtml(d)}<br><small class="muted">${fmtMoney(row.package_price)}</small>` : '<span class="muted">—</span>') },
                    { data: 'connection_status', title: 'Connection',
                      render: (d, t) => t === 'display' ? `<span class="status-pill ${escapeHtml(d)}">${escapeHtml(d)}</span>` : d },
                    { data: 'is_paid', title: 'Account',
                      render: (d, t) => t !== 'display' ? d
                        : (parseInt(d) ? '<span class="outstanding-badge zero"><i class="fas fa-check"></i> Paid up</span>'
                                       : '<span class="outstanding-badge due"><i class="fas fa-hourglass-half"></i> Dues</span>') },
                    { data: 'outstanding', title: 'Outstanding', className: 'num-cell',
                      render: (d, t) => t !== 'display' ? (Number(d) || 0)
                        : `<span class="outstanding-badge ${Number(d) <= 0 ? 'zero' : 'due'}">${fmtMoney(d)}</span>` },
                    { data: 'last_payment_date', title: 'Last Payment',
                      render: (d, t) => t === 'display' ? (d ? fmtDate(d) : '<span class="muted">—</span>') : (d || '') },
                    { data: null, title: 'Actions', orderable: false, searchable: false, className: 'cv-actions',
                      render: (d, t, row) => t !== 'display' ? ''
                        : `<a class="action-icon view-icon" title="Open customer" href="customers.php?id=${row.id}"><i class="fas fa-up-right-from-square"></i></a>` }
                ]
            }));

            if (areaStaffTable) { areaStaffTable.destroy(); $('#areaStaffTable').empty(); }
            areaStaffTable = $('#areaStaffTable').DataTable(areaDtOpts({
                data: staff,
                order: [[0, 'asc']],
                language: { emptyTable: 'No collector or area manager assigned here.', search: '', searchPlaceholder: 'Search staff…' },
                columns: [
                    { data: 'name', title: 'Staff',
                      render: (d, t, row) => t !== 'display' ? d
                        : `<strong>${escapeHtml(d || row.username)}</strong><br><small class="muted">@${escapeHtml(row.username)}</small>` },
                    { data: 'role_label', title: 'Role',
                      render: (d, t, row) => t === 'display' ? `<span class="role-badge role-${escapeHtml(row.role)}">${escapeHtml(d)}</span>` : d },
                    { data: 'phone', title: 'Phone', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'email', title: 'Email', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'status', title: 'Status',
                      render: (d, t) => t === 'display' ? `<span class="status-pill ${escapeHtml(d)}">${escapeHtml(d)}</span>` : d },
                    { data: 'receipts', title: 'Receipts', className: 'num-cell', render: d => Number(d) || 0 },
                    { data: 'collected', title: 'Collected', className: 'num-cell',
                      render: (d, t) => t === 'display' ? fmtMoney(d) : (Number(d) || 0) },
                    { data: 'last_login', title: 'Last Login',
                      render: (d, t) => t === 'display' ? (d ? fmtDate(d) : '<span class="muted">Never</span>') : (d || '') }
                ]
            }));

            areaShowTab(tab || 'customers');
        }).fail(function() {
            $('#areaRollup').html('<span class="muted">Connection error.</span>');
        });
        });
    }

    function closeViewModal() {
        document.getElementById('areaViewModal').classList.remove('active');
    }

    document.getElementById('areaViewModal').addEventListener('click', function(e) {
        if (e.target === this) closeViewModal();
    });

    function loadAreas() {
        // show skel, hide table+empty
        $('#skeletonWrap').show();
        $('#tableWrap').hide();
        $('#emptyState').hide();

        $.ajax({
            url: '?action=getAreas',
            method: 'GET',
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    areasData = resp.data;
                    refreshChevronCounts(areasData);
                    $('#chevronPipeline').removeClass('initially-hidden').css('display', '');
                    $('#skeletonWrap').hide();
                    if (!resp.data.length) {
                        $('#tableWrap').hide();
                        $('#emptyState').show();
                        return;
                    }
                    $('#emptyState').hide();
                    $('#tableWrap').show();
                    initTable(resp.data);
                    if (chevronStatus !== 'all') setTimeout(applyFilters, 120);
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

    function initTable(data) {
        if (areasTable) { areasTable.destroy(); $('#areasTable').empty(); }
        setTimeout(() => {
            areasTable = $('#areasTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'id', title: 'ID' },
                    { data: 'area_code', title: 'Code', render: d => d ? `<span class="muted-code">${escapeHtml(d)}</span>` : '<span class="muted-dash">—</span>' },
                    { data: 'name', title: 'Name', render: d => escapeHtml(d) },
                    { data: 'city', title: 'City', render: d => d ? escapeHtml(d) : '<span class="muted-dash">—</span>' },
                    {
                        // the count IS the door — click it to see who they are
                        data: 'customer_count', title: 'Customers',
                        render: (d, t, row) => t !== 'display' ? d
                            : `<button type="button" class="num-link" title="See these customers" onclick="viewArea(${row.id}, 'customers')">${d}</button>`
                    },
                    {
                        data: 'collector_count', title: 'Collectors',
                        render: (d, t, row) => t !== 'display' ? d
                            : `<button type="button" class="num-link" title="See the field staff here" onclick="viewArea(${row.id}, 'collectors')">${d}</button>`
                    },
                    {
                        data: 'status', title: 'Status',
                        render: d => `<span class="status-pill ${d}">${d}</span>`
                    },
                    {
                        data: 'created_at', title: 'Created',
                        render: function(d, t) {
                            // sort on raw iso, display formatted
                            if (t === 'sort' || t === 'type') return d || '';
                            return d ? escapeHtml(fmtDate(d)) : '<span class="muted-dash">—</span>';
                        }
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(d, t, row) {
                            const rj = encodeURIComponent(JSON.stringify(row));
                            let html = `<button class="action-icon view-icon" onclick="viewArea(${row.id})" title="View"><i class="fas fa-eye"></i></button>`;
                            html += `<div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">`;
                            html += `<button class="act-more-item" onclick='editArea(${JSON.stringify(row)})'><i class="fas fa-edit"></i> Edit</button>`;
                            if (CAN_DELETE) {
                                html += `<button class="act-more-item danger" onclick="deleteArea(${row.id}, ${row.customer_count}, ${row.collector_count})"><i class="fas fa-trash"></i> Delete</button>`;
                            }
                            html += `</div></div>`;
                            return html;
                        }
                    }
                ],
                pageLength: 10,
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
                order: [[0, 'desc']]
            });
        }, 100);
    }

    function applyFilters() {
        if (!areasTable) return;
        $.fn.dataTable.ext.search = [];
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const r = areasData[dataIndex];
            if (!r) return true;
            if (chevronStatus !== 'all' && r.status !== chevronStatus) return false;
            return true;
        });
        areasTable.draw();
    }

    function clearFilters() {
        chevronStatus = 'all';
        $('.chevron-tab').removeClass('active');
        $('.chevron-tab[data-status="all"]').addClass('active');
        if (areasTable) { $.fn.dataTable.ext.search = []; areasTable.draw(); }
    }

    // helper to set value + refresh SD
    function setSel(id, v) {
        const el = document.getElementById(id);
        if (!el) return;
        el.value = v == null ? '' : v;
        el._sd && el._sd.refresh();
    }
    // helper to set checkbox group values from CSV
    function setMulti(groupId, csv) {
        const grp = document.getElementById(groupId);
        if (!grp) return;
        const wanted = (csv || '').split(',').map(s => s.trim()).filter(Boolean);
        grp.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.checked = wanted.includes(cb.value);
        });
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Area';
        document.getElementById('areaForm').reset();
        document.getElementById('areaId').value = '';
        // explicit defaults
        setSel('status', 'active');
        setSel('coverage_status', 'active');
        setSel('manager_user_id', '');
        setSel('default_collector_user_id', '');
        setMulti('cov_tech_group', '');
        document.getElementById('country').value = 'Pakistan';
        document.getElementById('areaModal').classList.add('active');
    }

    function editArea(area) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Area';
        document.getElementById('areaId').value     = area.id;
        document.getElementById('name').value       = area.name || '';
        document.getElementById('area_code').value  = area.area_code || '';
        document.getElementById('city').value       = area.city || '';
        document.getElementById('country').value    = area.country || 'Pakistan';
        document.getElementById('province').value   = area.province || '';
        document.getElementById('district').value   = area.district || '';
        document.getElementById('pin_code').value   = area.pin_code || '';
        document.getElementById('description').value = area.description || '';
        document.getElementById('gps_lat').value    = area.gps_lat != null ? area.gps_lat : '';
        document.getElementById('gps_lng').value    = area.gps_lng != null ? area.gps_lng : '';
        document.getElementById('total_capacity_subscribers').value = area.total_capacity_subscribers != null ? area.total_capacity_subscribers : '';
        document.getElementById('competitor_presence').value = area.competitor_presence || '';
        document.getElementById('pop_id').value     = area.pop_id != null ? area.pop_id : '';

        setSel('status', area.status);
        setSel('coverage_status', area.coverage_status || 'active');
        setSel('manager_user_id', area.manager_user_id || '');
        setSel('default_collector_user_id', area.default_collector_user_id || '');
        setMulti('cov_tech_group', area.coverage_technology || '');

        document.getElementById('areaModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('areaModal').classList.remove('active');
        document.getElementById('areaForm').reset();
    }

    document.getElementById('areaModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.getElementById('areaForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const action = isEditMode ? 'updateArea' : 'addArea';
        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=' + action, method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 2000, showConfirmButton: false });
                    closeModal();
                    setTimeout(() => loadAreas(), 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    });

    function deleteArea(id, custCount, colCount) {
        // pre-block UI side
        if (custCount > 0) {
            Swal.fire({ icon: 'warning', title: 'Cannot Delete', text: `${custCount} customer(s) still assigned to this area. Reassign them first.` });
            return;
        }
        if (colCount > 0) {
            Swal.fire({ icon: 'warning', title: 'Cannot Delete', text: `${colCount} collector(s) still assigned to this area. Reassign them first.` });
            return;
        }
        Swal.fire({
            icon: 'warning', title: 'Delete Area?', text: 'This will soft-delete the area.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteArea', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(() => loadAreas(), 100);
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
