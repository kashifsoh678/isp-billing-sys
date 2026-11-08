<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: super_admin or admin
if (!isset($_SESSION['user_id']) || !can('packages', 'v')) {
    header("Location: dashboard.php");
    exit();
}
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'packages';
$can_delete = can('packages', 'd');
$currency = getSetting('currency_symbol', '$');

// csv import/template contract for this page
function packageImportSpec() {
    return [
        'entity'  => 'Package',
        'table'   => 'packages',
        'perm'    => 'packages',
        'columns' => [
            'name'                => ['label' => 'Package Name',  'type' => 's', 'required' => true, 'max' => 100, 'demo' => 'Demo Package 1'],
            'package_code'        => ['label' => 'Package Code',  'type' => 's', 'max' => 30, 'demo' => 'PKG-DEMO-1'],
            'speed_mbps'          => ['label' => 'Download Mbps', 'type' => 'i', 'required' => true, 'demo' => '20'],
            'upload_speed_mbps'   => ['label' => 'Upload Mbps',   'type' => 'i', 'default' => 0, 'demo' => '10'],
            'price'               => ['label' => 'Price',         'type' => 'd', 'scale' => 2, 'required' => true, 'demo' => '1500'],
            'setup_fee'           => ['label' => 'Setup Fee',     'type' => 'd', 'scale' => 2, 'default' => 0, 'demo' => '0'],
            'security_deposit'    => ['label' => 'Security Deposit', 'type' => 'd', 'scale' => 2, 'default' => 0, 'demo' => '0'],
            'billing_cycle'       => ['label' => 'Billing Cycle', 'type' => 'enum', 'values' => ['monthly','quarterly','yearly'], 'default' => 'monthly', 'demo' => 'monthly'],
            'package_category'    => ['label' => 'Category',      'type' => 'enum', 'values' => ['home','business','enterprise'], 'default' => 'home', 'demo' => 'home'],
            'target_segment'      => ['label' => 'Target Segment','type' => 'enum', 'values' => ['home','sme','enterprise','wisp'], 'default' => 'home', 'demo' => 'home'],
            'fup_gb'              => ['label' => 'FUP (GB)',      'type' => 'i', 'demo' => '100'],
            'quota_gb'            => ['label' => 'Quota (GB)',    'type' => 'i', 'demo' => '500'],
            'min_contract_months' => ['label' => 'Min Contract Months', 'type' => 'i', 'default' => 0, 'demo' => '0'],
            'tax_pct'             => ['label' => 'Tax %',         'type' => 'd', 'scale' => 2, 'default' => 0, 'demo' => '0'],
            'description'         => ['label' => 'Description',  'type' => 's', 'demo' => 'Imported from CSV'],
            'status'              => ['label' => 'Status',        'type' => 'enum', 'values' => ['active','inactive'], 'default' => 'active', 'demo' => 'active'],
        ],
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'importCsv':      rsImportRun($conn, packageImportSpec());
            case 'importTemplate': rsImportTemplate(packageImportSpec());

            case 'getPackages':
                $sql = "SELECT p.id, p.name, p.speed_mbps, p.upload_speed_mbps, p.fup_gb, p.fup_throttled_speed_mbps,
                               p.price, p.setup_fee, p.security_deposit, p.billing_cycle, p.description,
                               p.package_category, p.min_contract_months, p.sort_order,
                               p.status, p.created_at,
                               p.package_code, p.target_segment, p.tax_pct, p.tax_inclusive,
                               p.late_fee_amount, p.late_fee_pct, p.late_fee_grace_days,
                               p.bandwidth_profile_name, p.quota_gb,
                               p.night_speed_mbps, p.night_start_time, p.night_end_time,
                               p.valid_from, p.valid_until,
                               p.is_promotional, p.promotional_price, p.promotional_months,
                               p.equipment_included, p.equipment_rental_charge,
                               p.included_static_ip, p.commission_pct_for_sales,
                               (SELECT COUNT(*) FROM customers c WHERE c.package_id = p.id AND c.is_deleted = 0) AS subscriber_count
                        FROM packages p
                        WHERE p.is_deleted = 0
                        ORDER BY p.sort_order ASC, p.name ASC";
                $res = $conn->query($sql);
                $packages = [];
                while ($row = $res->fetch_assoc()) {
                    $fupGb = isset($row['fup_gb']) && $row['fup_gb'] !== null ? (int)$row['fup_gb'] : null;
                    $fupThr = isset($row['fup_throttled_speed_mbps']) && $row['fup_throttled_speed_mbps'] !== null ? (int)$row['fup_throttled_speed_mbps'] : null;
                    $packages[] = [
                        'id'                       => (int)$row['id'],
                        'name'                     => $row['name'],
                        'speed_mbps'               => (int)$row['speed_mbps'],
                        'upload_speed_mbps'        => (int)$row['upload_speed_mbps'],
                        'fup_gb'                   => $fupGb,
                        'fup_throttled_speed_mbps' => $fupThr,
                        'price'                    => number_format((float)$row['price'], 2, '.', ''),
                        'price_display'            => $currency . number_format((float)$row['price'], 2),
                        'setup_fee'                => number_format((float)$row['setup_fee'], 2, '.', ''),
                        'setup_fee_display'        => $currency . number_format((float)$row['setup_fee'], 2),
                        'security_deposit'         => number_format((float)$row['security_deposit'], 2, '.', ''),
                        'security_deposit_display' => $currency . number_format((float)$row['security_deposit'], 2),
                        'billing_cycle'            => $row['billing_cycle'],
                        'description'              => $row['description'] ?? '',
                        'package_category'         => $row['package_category'],
                        'min_contract_months'      => (int)$row['min_contract_months'],
                        'sort_order'               => (int)$row['sort_order'],
                        'status'                   => $row['status'],
                        'subscriber_count'         => (int)$row['subscriber_count'],
                        'created_at'               => date('c', strtotime($row['created_at'])),
                        // new fields
                        'package_code'             => $row['package_code'] ?? '',
                        'target_segment'           => $row['target_segment'] ?? 'home',
                        'tax_pct'                  => number_format((float)($row['tax_pct'] ?? 0), 2, '.', ''),
                        'tax_inclusive'            => (int)($row['tax_inclusive'] ?? 0),
                        'late_fee_amount'          => number_format((float)($row['late_fee_amount'] ?? 0), 2, '.', ''),
                        'late_fee_pct'             => number_format((float)($row['late_fee_pct'] ?? 0), 2, '.', ''),
                        'late_fee_grace_days'      => (int)($row['late_fee_grace_days'] ?? 0),
                        'bandwidth_profile_name'   => $row['bandwidth_profile_name'] ?? '',
                        'quota_gb'                 => isset($row['quota_gb']) && $row['quota_gb'] !== null ? (int)$row['quota_gb'] : null,
                        'night_speed_mbps'         => isset($row['night_speed_mbps']) && $row['night_speed_mbps'] !== null ? (int)$row['night_speed_mbps'] : null,
                        'night_start_time'         => $row['night_start_time'] ?? '',
                        'night_end_time'           => $row['night_end_time'] ?? '',
                        'valid_from'               => $row['valid_from'] ?? '',
                        'valid_until'              => $row['valid_until'] ?? '',
                        'is_promotional'           => (int)($row['is_promotional'] ?? 0),
                        'promotional_price'        => isset($row['promotional_price']) && $row['promotional_price'] !== null ? number_format((float)$row['promotional_price'], 2, '.', '') : '',
                        'promotional_months'       => isset($row['promotional_months']) && $row['promotional_months'] !== null ? (int)$row['promotional_months'] : null,
                        'equipment_included'       => (int)($row['equipment_included'] ?? 0),
                        'equipment_rental_charge'  => number_format((float)($row['equipment_rental_charge'] ?? 0), 2, '.', ''),
                        'included_static_ip'       => (int)($row['included_static_ip'] ?? 0),
                        'commission_pct_for_sales' => number_format((float)($row['commission_pct_for_sales'] ?? 0), 2, '.', '')
                    ];
                }
                echo json_encode(['success' => true, 'data' => $packages, 'currency' => $currency]);
                exit();

            // ─── subscribers on a package (for View modal) ───────────
            // package 360 — every subscriber with their live billing state, in ONE query (never N+1)
            case 'getPackageSubscribers': {
                $pid = (int)($_GET['id'] ?? 0);
                if ($pid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $scope = areaScope('c'); // area-limited roles only see their own areas
                $st = $conn->prepare("SELECT c.id, c.customer_code, c.full_name, c.phone, c.email, c.address,
                                             c.connection_status, c.installation_date, c.last_payment_date,
                                             a.name AS area_name,
                                             COALESCE(o.outstanding, 0) AS outstanding,
                                             COALESCE(o.open_count, 0)  AS open_count,
                                             li.id AS inv_id, li.invoice_number, li.billing_month,
                                             li.total AS inv_total, li.paid_amount AS inv_paid,
                                             li.balance_amount AS inv_balance, li.status AS inv_status, li.due_date AS inv_due
                                      FROM customers c
                                      LEFT JOIN areas a ON a.id = c.area_id
                                      LEFT JOIN (SELECT customer_id, SUM(balance_amount) outstanding, COUNT(*) open_count
                                                 FROM invoices
                                                 WHERE is_deleted = 0 AND status IN ('unpaid','partial','overdue')
                                                 GROUP BY customer_id) o ON o.customer_id = c.id
                                      LEFT JOIN invoices li ON li.id = (SELECT id FROM invoices
                                                                        WHERE customer_id = c.id AND is_deleted = 0
                                                                        ORDER BY billing_month DESC, id DESC LIMIT 1)
                                      WHERE c.package_id = ? AND c.is_deleted = 0 $scope
                                      ORDER BY c.full_name ASC");
                $st->bind_param("i", $pid);
                $st->execute();
                $res = $st->get_result();
                $rows = [];
                $sum = ['active' => 0, 'suspended' => 0, 'disconnected' => 0, 'outstanding' => 0.0, 'paid' => 0, 'due' => 0];
                while ($r = $res->fetch_assoc()) {
                    $out  = (float)$r['outstanding'];
                    $paid = $out <= 0; // nothing open = settled up
                    $cs   = $r['connection_status'] ?? '';
                    if (isset($sum[$cs])) $sum[$cs]++;
                    $sum['outstanding'] += $out;
                    $paid ? $sum['paid']++ : $sum['due']++;
                    $rows[] = [
                        'id'                => (int)$r['id'],
                        'customer_code'     => $r['customer_code'] ?? '',
                        'full_name'         => $r['full_name'] ?? '',
                        'phone'             => $r['phone'] ?? '',
                        'email'             => $r['email'] ?? '',
                        'address'           => $r['address'] ?? '',
                        'area_name'         => $r['area_name'] ?? '',
                        'connection_status' => $cs,
                        'installation_date' => $r['installation_date'],
                        'last_payment_date' => $r['last_payment_date'],
                        'outstanding'       => $out,
                        'open_count'        => (int)$r['open_count'],
                        'is_paid'           => $paid ? 1 : 0,
                        'invoice_id'        => (int)($r['inv_id'] ?? 0),
                        'invoice_number'    => $r['invoice_number'] ?? '',
                        'billing_month'     => $r['billing_month'] ?? '',
                        'invoice_total'     => (float)($r['inv_total'] ?? 0),
                        'invoice_paid'      => (float)($r['inv_paid'] ?? 0),
                        'invoice_balance'   => (float)($r['inv_balance'] ?? 0),
                        'invoice_status'    => $r['inv_status'] ?? '',
                        'invoice_due'       => $r['inv_due']
                    ];
                }
                $st->close();
                $sum['outstanding'] = round($sum['outstanding'], 2);
                $sum['total'] = count($rows);
                echo json_encode(['success'=>true,'data'=>$rows,'summary'=>$sum]);
                exit();
            }

            case 'addPackage':
            case 'updatePackage':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $isEdit  = $_GET['action'] === 'updatePackage';
                $id      = (int)($_POST['id'] ?? 0);
                $name    = trim($_POST['name'] ?? '');
                $speed   = (int)($_POST['speed_mbps'] ?? 0);
                $upSpeed = (int)($_POST['upload_speed_mbps'] ?? 0);
                $price   = (float)($_POST['price'] ?? 0);
                $cycle   = $_POST['billing_cycle'] ?? 'monthly';
                $desc    = trim($_POST['description'] ?? '');
                $status  = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

                // tier-1 fields
                $fupGb     = isset($_POST['fup_gb']) && $_POST['fup_gb'] !== '' ? (int)$_POST['fup_gb'] : null;
                $fupThr    = isset($_POST['fup_throttled_speed_mbps']) && $_POST['fup_throttled_speed_mbps'] !== '' ? (int)$_POST['fup_throttled_speed_mbps'] : null;
                $setupFee  = (float)($_POST['setup_fee'] ?? 0);
                $secDep    = (float)($_POST['security_deposit'] ?? 0);
                $category  = $_POST['package_category'] ?? 'home';
                $minMonths = (int)($_POST['min_contract_months'] ?? 0);
                $sortOrd   = (int)($_POST['sort_order'] ?? 0);

                // tier-2 fields
                $pkgCode   = trim($_POST['package_code'] ?? '');
                $segment   = $_POST['target_segment'] ?? 'home';
                $taxPct    = (float)($_POST['tax_pct'] ?? 0);
                $taxIncl   = !empty($_POST['tax_inclusive']) ? 1 : 0;
                $lateAmt   = (float)($_POST['late_fee_amount'] ?? 0);
                $latePct   = (float)($_POST['late_fee_pct'] ?? 0);
                $lateGrace = (int)($_POST['late_fee_grace_days'] ?? 0);
                $bwProf    = trim($_POST['bandwidth_profile_name'] ?? '');
                $quotaGb   = isset($_POST['quota_gb']) && $_POST['quota_gb'] !== '' ? (int)$_POST['quota_gb'] : null;
                $nightSp   = isset($_POST['night_speed_mbps']) && $_POST['night_speed_mbps'] !== '' ? (int)$_POST['night_speed_mbps'] : null;
                $nightStart = trim($_POST['night_start_time'] ?? '');
                $nightEnd   = trim($_POST['night_end_time'] ?? '');
                $validFrom  = trim($_POST['valid_from'] ?? '');
                $validUntil = trim($_POST['valid_until'] ?? '');
                $isPromo    = !empty($_POST['is_promotional']) ? 1 : 0;
                $promoPrice = isset($_POST['promotional_price']) && $_POST['promotional_price'] !== '' ? (float)$_POST['promotional_price'] : null;
                $promoMon   = isset($_POST['promotional_months']) && $_POST['promotional_months'] !== '' ? (int)$_POST['promotional_months'] : null;
                $eqIncl     = !empty($_POST['equipment_included']) ? 1 : 0;
                $eqRental   = (float)($_POST['equipment_rental_charge'] ?? 0);
                $staticIp   = !empty($_POST['included_static_ip']) ? 1 : 0;
                $commPct    = (float)($_POST['commission_pct_for_sales'] ?? 0);

                // null nightly times if no night speed
                if ($nightSp === null) { $nightStart = ''; $nightEnd = ''; }
                $nightStartOrNull = $nightStart !== '' ? $nightStart : null;
                $nightEndOrNull   = $nightEnd !== '' ? $nightEnd : null;
                $validFromOrNull  = $validFrom !== '' ? $validFrom : null;
                $validUntilOrNull = $validUntil !== '' ? $validUntil : null;
                $bwProfOrNull     = $bwProf !== '' ? $bwProf : null;
                $pkgCodeOrNull    = $pkgCode !== '' ? $pkgCode : null;

                // validate
                if ($name === '') {
                    echo json_encode(['success' => false, 'message' => 'Package name is required']);
                    exit();
                }
                if ($speed < 1) {
                    echo json_encode(['success' => false, 'message' => 'Download speed must be at least 1 Mbps']);
                    exit();
                }
                if ($upSpeed < 1) {
                    echo json_encode(['success' => false, 'message' => 'Upload speed must be at least 1 Mbps']);
                    exit();
                }
                if ($price < 0) {
                    echo json_encode(['success' => false, 'message' => 'Price cannot be negative']);
                    exit();
                }
                if ($setupFee < 0 || $secDep < 0) {
                    echo json_encode(['success' => false, 'message' => 'Fees cannot be negative']);
                    exit();
                }
                if ($minMonths < 0) {
                    echo json_encode(['success' => false, 'message' => 'Contract months cannot be negative']);
                    exit();
                }
                if (!in_array($cycle, ['monthly','quarterly','yearly'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid billing cycle']);
                    exit();
                }
                if (!in_array($category, ['home','business','enterprise'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid package category']);
                    exit();
                }
                if (!in_array($segment, ['home','sme','enterprise','wisp'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid target segment']);
                    exit();
                }
                if ($fupGb !== null) {
                    if ($fupGb < 1) {
                        echo json_encode(['success' => false, 'message' => 'FUP data cap must be at least 1 GB']);
                        exit();
                    }
                    if ($fupThr === null || $fupThr < 1) {
                        echo json_encode(['success' => false, 'message' => 'FUP throttled speed is required when FUP cap is set']);
                        exit();
                    }
                } else {
                    // no fup => clear throttled
                    $fupThr = null;
                }
                if ($taxPct < 0 || $taxPct > 100) {
                    echo json_encode(['success' => false, 'message' => 'Tax % must be between 0 and 100']);
                    exit();
                }
                if ($lateAmt < 0 || $latePct < 0 || $lateGrace < 0) {
                    echo json_encode(['success' => false, 'message' => 'Late fee values cannot be negative']);
                    exit();
                }
                if ($latePct > 100) {
                    echo json_encode(['success' => false, 'message' => 'Late fee % cannot exceed 100']);
                    exit();
                }
                if ($commPct < 0 || $commPct > 100) {
                    echo json_encode(['success' => false, 'message' => 'Commission % must be 0-100']);
                    exit();
                }
                if ($eqRental < 0) {
                    echo json_encode(['success' => false, 'message' => 'Equipment rental cannot be negative']);
                    exit();
                }
                if ($quotaGb !== null && $quotaGb < 1) {
                    echo json_encode(['success' => false, 'message' => 'Hard cap (GB) must be at least 1']);
                    exit();
                }
                // night boost: both times required if night speed set
                if ($nightSp !== null) {
                    if ($nightSp < 1) {
                        echo json_encode(['success' => false, 'message' => 'Night boost speed must be at least 1']);
                        exit();
                    }
                    if ($nightStartOrNull === null || $nightEndOrNull === null) {
                        echo json_encode(['success' => false, 'message' => 'Night start and end times required when night boost is set']);
                        exit();
                    }
                }
                // promotional checks
                if ($isPromo) {
                    if ($promoPrice === null || $promoPrice < 0) {
                        echo json_encode(['success' => false, 'message' => 'Promotional price required when promo is on']);
                        exit();
                    }
                    if ($promoMon === null || $promoMon < 1) {
                        echo json_encode(['success' => false, 'message' => 'Promotional months required when promo is on']);
                        exit();
                    }
                } else {
                    $promoPrice = null;
                    $promoMon   = null;
                }
                // validity window
                if ($validFromOrNull !== null && $validUntilOrNull !== null) {
                    if (strtotime($validFromOrNull) > strtotime($validUntilOrNull)) {
                        echo json_encode(['success' => false, 'message' => 'Valid From cannot be after Valid Until']);
                        exit();
                    }
                }

                // dup name check
                $dupSql = $isEdit
                    ? "SELECT id FROM packages WHERE name = ? AND id != ? AND is_deleted = 0"
                    : "SELECT id FROM packages WHERE name = ? AND is_deleted = 0";
                $stmt = $conn->prepare($dupSql);
                if ($isEdit) $stmt->bind_param("si", $name, $id);
                else         $stmt->bind_param("s", $name);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Package name already exists']);
                    exit();
                }
                $stmt->close();

                // dup pkg code check (if provided)
                if ($pkgCodeOrNull !== null) {
                    $dupSql2 = $isEdit
                        ? "SELECT id FROM packages WHERE package_code = ? AND id != ? AND is_deleted = 0"
                        : "SELECT id FROM packages WHERE package_code = ? AND is_deleted = 0";
                    $stmt = $conn->prepare($dupSql2);
                    if ($isEdit) $stmt->bind_param("si", $pkgCodeOrNull, $id);
                    else         $stmt->bind_param("s", $pkgCodeOrNull);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        $stmt->close();
                        echo json_encode(['success' => false, 'message' => 'Package code already exists']);
                        exit();
                    }
                    $stmt->close();
                }

                // build INSERT/UPDATE — use single prepared stmt, bind nullables via PHP nulls
                // 32 columns total
                if (!$isEdit) {
                    $sql = "INSERT INTO packages
                        (name, speed_mbps, upload_speed_mbps, fup_gb, fup_throttled_speed_mbps, price, setup_fee, security_deposit, billing_cycle, description, package_category, min_contract_months, sort_order, status,
                         package_code, target_segment, tax_pct, tax_inclusive, late_fee_amount, late_fee_pct, late_fee_grace_days, bandwidth_profile_name, quota_gb, night_speed_mbps, night_start_time, night_end_time, valid_from, valid_until, is_promotional, promotional_price, promotional_months, equipment_included, equipment_rental_charge, included_static_ip, commission_pct_for_sales)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                    $stmt = $conn->prepare($sql);
                    // 35 params:
                    // 1 name(s) 2 speed(i) 3 upSpeed(i) 4 fupGb(i) 5 fupThr(i) 6 price(d) 7 setupFee(d) 8 secDep(d) 9 cycle(s) 10 desc(s) 11 category(s) 12 minMonths(i) 13 sortOrd(i) 14 status(s)
                    // 15 pkgCode(s) 16 segment(s) 17 taxPct(d) 18 taxIncl(i) 19 lateAmt(d) 20 latePct(d) 21 lateGrace(i) 22 bwProf(s) 23 quotaGb(i) 24 nightSp(i) 25 nightStart(s) 26 nightEnd(s) 27 validFrom(s) 28 validUntil(s) 29 isPromo(i) 30 promoPrice(d) 31 promoMon(i) 32 eqIncl(i) 33 eqRental(d) 34 staticIp(i) 35 commPct(d)
                    $types = "siiiidddsssiis" . "ssdiddisii" . "ssss" . "idiidid";
                    // verify length 35
                    $stmt->bind_param(
                        $types,
                        $name, $speed, $upSpeed, $fupGb, $fupThr, $price, $setupFee, $secDep, $cycle, $desc, $category, $minMonths, $sortOrd, $status,
                        $pkgCodeOrNull, $segment, $taxPct, $taxIncl, $lateAmt, $latePct, $lateGrace, $bwProfOrNull, $quotaGb, $nightSp, $nightStartOrNull, $nightEndOrNull, $validFromOrNull, $validUntilOrNull, $isPromo, $promoPrice, $promoMon, $eqIncl, $eqRental, $staticIp, $commPct
                    );
                    if ($stmt->execute()) {
                        logActivity($user_id, $username, 'Package Created', "Created package: $name ({$speed}/{$upSpeed}Mbps, {$currency}{$price}/$cycle, $category)");
                        $stmt->close();
                        echo json_encode(['success' => true, 'message' => 'Package added successfully']);
                    } else {
                        $err = $stmt->error;
                        $stmt->close();
                        echo json_encode(['success' => false, 'message' => 'Failed to add: ' . $err]);
                    }
                    exit();
                }

                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid package id']);
                    exit();
                }

                $sql = "UPDATE packages SET
                    name=?, speed_mbps=?, upload_speed_mbps=?, fup_gb=?, fup_throttled_speed_mbps=?, price=?, setup_fee=?, security_deposit=?, billing_cycle=?, description=?, package_category=?, min_contract_months=?, sort_order=?, status=?,
                    package_code=?, target_segment=?, tax_pct=?, tax_inclusive=?, late_fee_amount=?, late_fee_pct=?, late_fee_grace_days=?, bandwidth_profile_name=?, quota_gb=?, night_speed_mbps=?, night_start_time=?, night_end_time=?, valid_from=?, valid_until=?, is_promotional=?, promotional_price=?, promotional_months=?, equipment_included=?, equipment_rental_charge=?, included_static_ip=?, commission_pct_for_sales=?
                    WHERE id=? AND is_deleted = 0";
                $stmt = $conn->prepare($sql);
                // same 35 + i=id => 36 params
                $types = "siiiidddsssiis" . "ssdiddisii" . "ssss" . "idiidid" . "i";
                $stmt->bind_param(
                    $types,
                    $name, $speed, $upSpeed, $fupGb, $fupThr, $price, $setupFee, $secDep, $cycle, $desc, $category, $minMonths, $sortOrd, $status,
                    $pkgCodeOrNull, $segment, $taxPct, $taxIncl, $lateAmt, $latePct, $lateGrace, $bwProfOrNull, $quotaGb, $nightSp, $nightStartOrNull, $nightEndOrNull, $validFromOrNull, $validUntilOrNull, $isPromo, $promoPrice, $promoMon, $eqIncl, $eqRental, $staticIp, $commPct,
                    $id
                );
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Package Updated', "Updated package: $name");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Package updated successfully']);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update: ' . $err]);
                }
                exit();

            case 'deletePackage':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!can('packages', 'd')) {
                    echo json_encode(['success' => false, 'message' => 'Only super admin can delete packages']);
                    exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }

                // block del if subscribers attached
                $check = $conn->prepare("SELECT COUNT(*) AS c FROM customers WHERE package_id = ? AND is_deleted = 0");
                $check->bind_param("i", $id);
                $check->execute();
                $cnt = (int)($check->get_result()->fetch_assoc()['c'] ?? 0);
                $check->close();
                if ($cnt > 0) {
                    echo json_encode(['success' => false, 'message' => "Cannot delete: $cnt active subscriber(s) on this package. Move them to another package first."]);
                    exit();
                }

                $stmt = $conn->prepare("SELECT name FROM packages WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Package not found']);
                    exit();
                }

                $stmt = $conn->prepare("UPDATE packages SET is_deleted = 1, status='inactive' WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Package Deleted', "Deleted package: " . $row['name']);
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Package deleted successfully']);
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
        error_log("Packages.php error: " . $e->getMessage());
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
    <title>Packages - ISP Billing</title>

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
                <span>Packages</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-box"></i> Packages</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-boxes-stacked"></i> Internet Plans</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-secondary" id="toggleViewBtn" onclick="toggleView()">
                            <i class="fas fa-table"></i> Table View
                        </button>
                        <button class="btn btn-primary" onclick="loadPackages()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('packages', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Package
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
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filterStatus">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-layer-group"></i> Category</label>
                            <select id="filterCategory">
                                <option value="">All Categories</option>
                                <option value="home">Home</option>
                                <option value="business">Business</option>
                                <option value="enterprise">Enterprise</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap">
                    <div class="pkg-grid">
                        <div class="pkg-card">
                            <div class="skeleton skeleton-text-large skeleton-w-60"></div>
                            <div class="skeleton skeleton-text skeleton-w-50 skeleton-mb-md"></div>
                            <div class="skeleton skeleton-text skeleton-w-80"></div>
                        </div>
                        <div class="pkg-card">
                            <div class="skeleton skeleton-text-large skeleton-w-60"></div>
                            <div class="skeleton skeleton-text skeleton-w-50 skeleton-mb-md"></div>
                            <div class="skeleton skeleton-text skeleton-w-80"></div>
                        </div>
                        <div class="pkg-card">
                            <div class="skeleton skeleton-text-large skeleton-w-60"></div>
                            <div class="skeleton skeleton-text skeleton-w-50 skeleton-mb-md"></div>
                            <div class="skeleton skeleton-text skeleton-w-80"></div>
                        </div>
                    </div>
                </div>

                <!-- card view -->
                <div id="cardView" class="initially-hidden">
                    <div class="pkg-grid" id="pkgGrid"></div>
                    <div class="empty-state initially-hidden" id="emptyStateCards">
                        <div class="empty-state-icon"><i class="fas fa-box"></i></div>
                        <div class="empty-state-title">No packages defined yet.</div>
                        <div class="empty-state-text">Create your first internet plan to start enrolling customers.</div>
                    </div>
                </div>

                <!-- table view -->
                <div id="tableView" class="initially-hidden">
                    <div class="table-responsive">
                        <table id="packagesTable" class="display table-full-width"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Package Modal -->
    <div class="modal-overlay" id="packageModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Package</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="packageForm">
                    <input type="hidden" id="packageId" name="id">

                    <h4 class="form-section-title"><i class="fas fa-id-card"></i> Identity</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Package Name *</label>
                            <input type="text" id="name" name="name" required placeholder="e.g. Home Pro">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-barcode"></i> Package Code (SKU)</label>
                            <input type="text" id="package_code" name="package_code" placeholder="e.g. FIB-50M">
                            <small class="form-hint">Auto-suggested from name + speed; editable</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Category *</label>
                            <select id="package_category" name="package_category" required>
                                <option value="home">Home</option>
                                <option value="business">Business</option>
                                <option value="enterprise">Enterprise</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-bullseye"></i> Target Segment *</label>
                            <select id="target_segment" name="target_segment" required>
                                <option value="home">Home</option>
                                <option value="sme">SME</option>
                                <option value="enterprise">Enterprise</option>
                                <option value="wisp">WISP</option>
                            </select>
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-tachometer-alt"></i> Speed &amp; Bandwidth</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tachometer-alt"></i> Download Speed (Mbps) *</label>
                            <input type="number" id="speed_mbps" name="speed_mbps" min="1" required placeholder="e.g. 25">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-arrow-up"></i> Upload Speed (Mbps) *</label>
                            <input type="number" id="upload_speed_mbps" name="upload_speed_mbps" min="1" required placeholder="e.g. 5">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-database"></i> FUP Data Cap (GB)</label>
                            <input type="number" id="fup_gb" name="fup_gb" min="1" placeholder="e.g. 500">
                            <small class="form-hint">Leave blank for unlimited</small>
                        </div>
                        <div class="form-group initially-hidden" id="fupThrottleGroup">
                            <label><i class="fas fa-gauge-low"></i> Throttled Speed After FUP (Mbps)</label>
                            <input type="number" id="fup_throttled_speed_mbps" name="fup_throttled_speed_mbps" min="1" placeholder="e.g. 2">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hard-drive"></i> Hard Cap Quota (GB)</label>
                            <input type="number" id="quota_gb" name="quota_gb" min="1" placeholder="e.g. 1000">
                            <small class="form-hint">Hard cap; leave blank for unlimited</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-route"></i> Bandwidth Profile</label>
                            <input type="text" id="bandwidth_profile_name" name="bandwidth_profile_name" placeholder="Mikrotik queue profile">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-moon"></i> Night Boost</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-rocket"></i> Night Speed (Mbps)</label>
                            <input type="number" id="night_speed_mbps" name="night_speed_mbps" min="1" placeholder="e.g. 100">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> Night Start Time</label>
                            <input type="time" id="night_start_time" name="night_start_time" placeholder="00:00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> Night End Time</label>
                            <input type="time" id="night_end_time" name="night_end_time" placeholder="06:00">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-money-bill-wave"></i> Pricing &amp; Tax</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Price (<?php echo htmlspecialchars($currency); ?>) *</label>
                            <input type="number" id="price" name="price" step="0.01" min="0" required placeholder="e.g. 1500.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Billing Cycle *</label>
                            <select id="billing_cycle" name="billing_cycle" required>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Tax %</label>
                            <input type="number" id="tax_pct" name="tax_pct" step="0.01" min="0" max="100" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-receipt"></i> Tax Mode</label>
                            <div class="toggle-row">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="tax_inclusive" name="tax_inclusive" value="1" class="toggle-input">
                                    <label for="tax_inclusive" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                                <span class="toggle-text">Price includes tax</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-screwdriver-wrench"></i> Setup Fee (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="setup_fee" name="setup_fee" step="0.01" min="0" value="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-shield-halved"></i> Security Deposit (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="security_deposit" name="security_deposit" step="0.01" min="0" value="0" placeholder="0.00">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-triangle-exclamation"></i> Late Fee Policy</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Late Fee Amount (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="late_fee_amount" name="late_fee_amount" step="0.01" min="0" value="0">
                            <small class="form-hint">Flat amount; or use % below</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Late Fee % of Invoice</label>
                            <input type="number" id="late_fee_pct" name="late_fee_pct" step="0.01" min="0" max="100" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hourglass-half"></i> Grace Days</label>
                            <input type="number" id="late_fee_grace_days" name="late_fee_grace_days" min="0" value="0">
                            <small class="form-hint">Days overdue before fee applies</small>
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-bullhorn"></i> Promotional</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-gift"></i> Is Promotional</label>
                            <div class="toggle-row">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="is_promotional" name="is_promotional" value="1" class="toggle-input">
                                    <label for="is_promotional" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                                <span class="toggle-text">Enable promotional pricing</span>
                            </div>
                        </div>
                        <div class="form-group initially-hidden" id="promoPriceGroup">
                            <label><i class="fas fa-tag"></i> Promotional Price (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="promotional_price" name="promotional_price" step="0.01" min="0" placeholder="e.g. 999.00">
                        </div>
                        <div class="form-group initially-hidden" id="promoMonthsGroup">
                            <label><i class="fas fa-stopwatch"></i> Promo Duration (Months)</label>
                            <input type="number" id="promotional_months" name="promotional_months" min="1" placeholder="e.g. 3">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-calendar-day"></i> Validity Window</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-plus"></i> Valid From</label>
                            <input type="date" id="valid_from" name="valid_from">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-minus"></i> Valid Until</label>
                            <input type="date" id="valid_until" name="valid_until">
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-router"></i> Equipment &amp; Extras</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-router"></i> Router Included</label>
                            <div class="toggle-row">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="equipment_included" name="equipment_included" value="1" class="toggle-input">
                                    <label for="equipment_included" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                                <span class="toggle-text">Equipment included</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-trend-up"></i> Equipment Rental (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="equipment_rental_charge" name="equipment_rental_charge" step="0.01" min="0" value="0">
                            <small class="form-hint">Monthly rental if not included</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-globe"></i> Static IP Included</label>
                            <div class="toggle-row">
                                <div class="toggle-switch">
                                    <input type="checkbox" id="included_static_ip" name="included_static_ip" value="1" class="toggle-input">
                                    <label for="included_static_ip" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                                <span class="toggle-text">Public static IP included</span>
                            </div>
                        </div>
                    </div>

                    <h4 class="form-section-title"><i class="fas fa-handshake"></i> Sales</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Sales Commission %</label>
                            <input type="number" id="commission_pct_for_sales" name="commission_pct_for_sales" step="0.01" min="0" max="100" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-contract"></i> Min Contract (Months)</label>
                            <input type="number" id="min_contract_months" name="min_contract_months" min="0" value="0" placeholder="0">
                            <small class="form-hint">0 = no contract</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-sort-numeric-down"></i> Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" value="0" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status *</label>
                            <select id="status" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="form-group form-group-wide">
                            <label><i class="fas fa-info-circle"></i> Description</label>
                            <textarea id="description" name="description" rows="3" placeholder="Optional plan details..."></textarea>
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

    <!-- View Package Modal -->
    <!-- package 360 — plan on the left, everyone subscribed to it on the right -->
    <div class="modal-overlay" id="viewPkgModal">
        <div class="modal modal-360">
            <div class="modal-header">
                <h3 id="pkgViewTitle"><i class="fas fa-box"></i> Package Detail</h3>
                <button class="close-btn" onclick="closeViewPkgModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="cv-360">
                    <aside class="cv-profile" id="pkgProfile"></aside>
                    <section class="cv-data">
                        <div class="vtab-nav" id="pkgTabs">
                            <button type="button" class="vtab-btn active" data-ptab="subs"><i class="fas fa-users"></i> Subscribers <span class="cv-count" id="pkgcount-subs">0</span></button>
                            <button type="button" class="vtab-btn" data-ptab="specs"><i class="fas fa-sliders"></i> Plan Specs</button>
                        </div>
                        <div class="vtab-pane active" id="pkgpane-subs"><table id="pkgSubsTable" class="display cv-dt" style="width:100%"></table></div>
                        <div class="vtab-pane" id="pkgpane-specs"></div>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>

    <script>
    const CAN_DELETE = <?php echo $can_delete ? 'true' : 'false'; ?>;
    const CURRENCY = <?php echo json_encode($currency); ?>;
    function fmtMoney(n) { return CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    let packagesTable;
    let isEditMode = false;
    let packagesData = [];
    let currentView = 'cards'; // cards | table

    $(document).ready(function() {
        // modal selects
        new SearchableDropdown({ selectId: 'package_category', placeholder: 'Category...', icon: 'fas fa-layer-group', allowClear: false });
        new SearchableDropdown({ selectId: 'target_segment', placeholder: 'Segment...', icon: 'fas fa-bullseye', allowClear: false });
        new SearchableDropdown({ selectId: 'billing_cycle', placeholder: 'Cycle...', icon: 'fas fa-calendar-alt', allowClear: false });
        new SearchableDropdown({ selectId: 'status', placeholder: 'Status...', icon: 'fas fa-toggle-on', allowClear: false });
        // filter selects
        new SearchableDropdown({ selectId: 'filterStatus', placeholder: 'All Statuses', icon: 'fas fa-toggle-on' });
        new SearchableDropdown({ selectId: 'filterCategory', placeholder: 'All Categories', icon: 'fas fa-layer-group' });

        loadPackages();
        rsImport.init({ entity: 'Package', onDone: loadPackages });
        $('#filterStatus').on('change', applyFilters);
        $('#filterCategory').on('change', applyFilters);
        // toggle fup throttle visibility
        $('#fup_gb').on('input', toggleFupThrottle);
        // promo group visibility
        $('#is_promotional').on('change', togglePromoGroups);
        // auto-suggest pkg code from name + speed
        $('#name, #speed_mbps').on('input', autosuggestPkgCode);
    });

    function togglePromoGroups() {
        const on = document.getElementById('is_promotional').checked;
        ['promoPriceGroup','promoMonthsGroup'].forEach(id => {
            const el = document.getElementById(id);
            if (on) { el.classList.remove('initially-hidden'); el.style.display = ''; }
            else    { el.classList.add('initially-hidden');    el.style.display = 'none';
                      const inp = el.querySelector('input'); if (inp) inp.value = ''; }
        });
    }

    function autosuggestPkgCode() {
        if (isEditMode) return; // don't override on edit
        const codeEl = document.getElementById('package_code');
        if (codeEl.dataset.touched === '1') return; // user typed manually
        const nm = (document.getElementById('name').value || '').trim();
        const sp = parseInt(document.getElementById('speed_mbps').value || '0', 10);
        if (!nm) { codeEl.value = ''; return; }
        const prefix = nm.replace(/[^A-Za-z0-9]/g,'').slice(0,3).toUpperCase() || 'PKG';
        codeEl.value = sp > 0 ? `${prefix}-${sp}M` : prefix;
    }
    // mark code as user-touched
    document.addEventListener('DOMContentLoaded', function() {
        const c = document.getElementById('package_code');
        if (c) c.addEventListener('input', () => { c.dataset.touched = '1'; });
    });

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }

    function cycleLabel(c) {
        return { monthly: 'Per Month', quarterly: 'Per Quarter', yearly: 'Per Year' }[c] || c;
    }

    function catLabel(c) {
        return { home: 'Home', business: 'Business', enterprise: 'Enterprise' }[c] || c;
    }

    // ── Package 360 — the plan on the left, its subscribers (with live billing state) on the right ──
    let pkgSubsTable = null;

    function viewPackage(id) {
        const p = packagesData.find(x => x.id === id);
        if (!p) { Swal.fire({ icon:'error', title:'Error', text:'Package not found' }); return; }
        const yn = v => parseInt(v, 10) ? 'Yes' : 'No';
        const field = (lbl, val) => `<div class="cv-field"><label>${lbl}</label><span>${val !== '' && val != null ? val : '<span class="muted">—</span>'}</span></div>`;

        document.getElementById('pkgViewTitle').innerHTML =
            `<i class="fas fa-box"></i> ${escapeHtml(p.name)} <span class="muted">(${escapeHtml(p.package_code || '')})</span>`;

        const money = v => fmtMoney(v);
        const or_   = (v, d) => (v === '' || v == null) ? (d || '<span class="muted">—</span>') : v;
        const pct   = v => (Number(v || 0)).toFixed(2).replace(/\.00$/, '') + '%';
        const promo = parseInt(p.is_promotional, 10) === 1 && p.promotional_price !== '';

        document.getElementById('pkgProfile').innerHTML = `
            <div class="cv-id">
                <div class="cv-avatar"><i class="fas fa-box"></i></div>
                <div class="cv-id-text">
                    <strong>${escapeHtml(p.name)}</strong>
                    <span class="muted">${escapeHtml(p.package_code || '—')} · ${escapeHtml(catLabel(p.package_category))}</span>
                </div>
            </div>

            <div class="cv-hero">
                <label>Monthly Price</label>
                <div class="cv-hero-value">${escapeHtml(money(p.price))}
                    <span class="cv-hero-cycle">/ ${escapeHtml(cycleLabel(p.billing_cycle))}</span></div>
                ${promo ? `<div class="cv-hero-note"><i class="fas fa-tag"></i> Promo ${escapeHtml(money(p.promotional_price))}
                    ${p.promotional_months ? 'for ' + escapeHtml(String(p.promotional_months)) + ' months' : ''}</div>` : ''}
                <div class="cv-hero-pills">
                    <span class="status-pill ${escapeHtml(p.status)}">${escapeHtml(p.status)}</span>
                    <span class="cv-pill"><i class="fas fa-gauge-high"></i> ${escapeHtml(p.speed_mbps + '/' + p.upload_speed_mbps)} Mbps</span>
                    <span class="cv-pill"><i class="fas fa-database"></i> ${p.fup_gb ? escapeHtml(p.fup_gb + ' GB') : 'Unlimited'}</span>
                </div>
            </div>

            <div class="pkg-rollup" id="pkgRollup"><span class="muted">Loading subscribers…</span></div>

            <div class="cv-facts">
                ${field('Setup Fee', escapeHtml(money(p.setup_fee)))}
                ${field('Security Deposit', escapeHtml(money(p.security_deposit)))}
                ${field('Equipment Rental', Number(p.equipment_rental_charge) > 0 ? escapeHtml(money(p.equipment_rental_charge)) : '<span class="muted">None</span>')}
                ${field('Tax', escapeHtml(pct(p.tax_pct)) + (parseInt(p.tax_inclusive, 10) ? ' <span class="muted">(inclusive)</span>' : ' <span class="muted">(added)</span>'))}
                ${field('Min Contract', parseInt(p.min_contract_months, 10) > 0 ? escapeHtml(p.min_contract_months + ' months') : '<span class="muted">No minimum</span>')}
                ${field('Data Quota', p.quota_gb ? escapeHtml(p.quota_gb + ' GB') : '<span class="muted">Unlimited</span>')}
                ${field('Description', p.description ? escapeHtml(p.description) : '')}
            </div>
        `;

        // full spec sheet — every stored field, grouped, as a real table
        const specs = [
            ['Identity', [
                ['Package Code', escapeHtml(p.package_code || '')],
                ['Name', escapeHtml(p.name)],
                ['Category', escapeHtml(catLabel(p.package_category))],
                ['Target Segment', escapeHtml(String(p.target_segment || '').replace(/^./, c => c.toUpperCase()))],
                ['Status', `<span class="status-pill ${escapeHtml(p.status)}">${escapeHtml(p.status)}</span>`],
                ['Subscribers', escapeHtml(String(p.subscriber_count))],
                ['Sort Order', escapeHtml(String(p.sort_order))]
            ]],
            ['Speed &amp; Data', [
                ['Download Speed', escapeHtml(p.speed_mbps + ' Mbps')],
                ['Upload Speed', escapeHtml(p.upload_speed_mbps + ' Mbps')],
                ['Bandwidth Profile', escapeHtml(p.bandwidth_profile_name || '')],
                ['FUP Cap', p.fup_gb ? escapeHtml(p.fup_gb + ' GB') : 'Unlimited'],
                ['Throttled Speed After FUP', p.fup_throttled_speed_mbps ? escapeHtml(p.fup_throttled_speed_mbps + ' Mbps') : ''],
                ['Data Quota', p.quota_gb ? escapeHtml(p.quota_gb + ' GB') : 'Unlimited'],
                ['Night Speed', p.night_speed_mbps ? escapeHtml(p.night_speed_mbps + ' Mbps') : ''],
                ['Night Window', p.night_start_time && p.night_end_time ? escapeHtml(p.night_start_time + ' – ' + p.night_end_time) : '']
            ]],
            ['Pricing', [
                ['Monthly Price', escapeHtml(money(p.price))],
                ['Billing Cycle', escapeHtml(cycleLabel(p.billing_cycle))],
                ['Setup Fee', escapeHtml(money(p.setup_fee))],
                ['Security Deposit', escapeHtml(money(p.security_deposit))],
                ['Equipment Rental', Number(p.equipment_rental_charge) > 0 ? escapeHtml(money(p.equipment_rental_charge)) : ''],
                ['Promotional', yn(p.is_promotional)],
                ['Promotional Price', promo ? escapeHtml(money(p.promotional_price)) : ''],
                ['Promotional Months', p.promotional_months ? escapeHtml(String(p.promotional_months)) : '']
            ]],
            ['Tax &amp; Late Fees', [
                ['Tax %', escapeHtml(pct(p.tax_pct))],
                ['Tax Inclusive', yn(p.tax_inclusive)],
                ['Late Fee Amount', Number(p.late_fee_amount) > 0 ? escapeHtml(money(p.late_fee_amount)) : ''],
                ['Late Fee %', Number(p.late_fee_pct) > 0 ? escapeHtml(pct(p.late_fee_pct)) : ''],
                ['Grace Days', escapeHtml(String(p.late_fee_grace_days))]
            ]],
            ['Terms &amp; Extras', [
                ['Min Contract', parseInt(p.min_contract_months, 10) > 0 ? escapeHtml(p.min_contract_months + ' months') : 'No minimum'],
                ['Equipment Included', yn(p.equipment_included)],
                ['Static IP Included', yn(p.included_static_ip)],
                ['Sales Commission', Number(p.commission_pct_for_sales) > 0 ? escapeHtml(pct(p.commission_pct_for_sales)) : ''],
                ['Valid From', escapeHtml(p.valid_from || '')],
                ['Valid Until', escapeHtml(p.valid_until || '')],
                ['Description', p.description ? escapeHtml(p.description) : '']
            ]]
        ];
        document.getElementById('pkgpane-specs').innerHTML =
            `<div class="about-table-wrapper spec-wrap"><table class="spec-table">
                <thead><tr><th>Specification</th><th>Value</th></tr></thead><tbody>` +
            specs.map(([grp, rows]) =>
                `<tr class="spec-group"><td colspan="2">${grp}</td></tr>` +
                rows.map(([k, v]) => `<tr><td class="spec-k">${k}</td><td class="spec-v">${or_(v)}</td></tr>`).join('')
            ).join('') + `</tbody></table></div>`;

        pkgShowTab('subs');
        document.getElementById('viewPkgModal').classList.add('active');
        loadPkgSubscribers(id);
    }

    function pkgShowTab(t) {
        $('#pkgTabs .vtab-btn').removeClass('active').filter('[data-ptab="' + t + '"]').addClass('active');
        $('#viewPkgModal .vtab-pane').removeClass('active');
        $('#pkgpane-' + t).addClass('active');
        // a table built in a hidden pane measures 0 — re-measure when it becomes visible
        if (t === 'subs' && pkgSubsTable) { pkgSubsTable.columns.adjust(); pkgSubsTable.responsive && pkgSubsTable.responsive.recalc(); }
    }
    $(document).on('click', '#pkgTabs .vtab-btn', function() { pkgShowTab($(this).data('ptab')); });

    function loadPkgSubscribers(id) {
        $.getJSON('?action=getPackageSubscribers&id=' + id, function(resp) {
            if (!resp.success) { $('#pkgRollup').html('<span class="muted">Failed to load subscribers.</span>'); return; }
            const rows = resp.data || [], s = resp.summary || {};
            $('#pkgcount-subs').text(rows.length);
            $('#pkgRollup').html(`
                <div class="pkg-rollup-row"><span><i class="fas fa-plug"></i> Active</span><strong>${s.active || 0}</strong></div>
                <div class="pkg-rollup-row"><span><i class="fas fa-user-clock"></i> Suspended</span><strong>${s.suspended || 0}</strong></div>
                <div class="pkg-rollup-row"><span><i class="fas fa-user-slash"></i> Disconnected</span><strong>${s.disconnected || 0}</strong></div>
                <div class="pkg-rollup-row"><span><i class="fas fa-circle-check"></i> Settled up</span><strong>${s.paid || 0}</strong></div>
                <div class="pkg-rollup-row"><span><i class="fas fa-hourglass-half"></i> Has dues</span><strong>${s.due || 0}</strong></div>
                <div class="pkg-rollup-row total"><span><i class="fas fa-sack-dollar"></i> Outstanding</span><strong>${fmtMoney(s.outstanding || 0)}</strong></div>`);

            if (pkgSubsTable) { pkgSubsTable.destroy(); $('#pkgSubsTable').empty(); }
            pkgSubsTable = $('#pkgSubsTable').DataTable({
                data: rows,
                pageLength: 10,
                lengthChange: false,
                responsive: true,
                autoWidth: false,
                order: [[1, 'asc']],
                dom: 'Bfrtip',
                buttons: [
                    { extend: 'csv',   text: '<i class="fas fa-file-csv"></i> CSV',   className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last-child)' } },
                    { extend: 'pdf',   text: '<i class="fas fa-file-pdf"></i> PDF',   className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last-child)' } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print',    className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last-child)' } }
                ],
                language: { emptyTable: 'Nobody is on this package yet.', search: '', searchPlaceholder: 'Search subscriber…' },
                columns: [
                    { data: 'customer_code', title: 'Code', render: d => escapeHtml(d) },
                    { data: 'full_name',     title: 'Customer',
                      render: (d, t, row) => t !== 'display' ? d
                        : `<strong>${escapeHtml(d)}</strong><br><small class="muted">${escapeHtml(row.phone || '')}${row.area_name ? ' · ' + escapeHtml(row.area_name) : ''}</small>` },
                    { data: 'connection_status', title: 'Connection',
                      render: (d, t) => t === 'display' ? `<span class="status-pill ${escapeHtml(d)}">${escapeHtml(d)}</span>` : d },
                    { data: 'billing_month', title: 'Latest Bill',
                      render: (d, t, row) => t !== 'display' ? (d || '')
                        : (row.invoice_id ? `${escapeHtml(row.invoice_number)}<br><small class="muted">${escapeHtml(d || '')}</small>` : '<span class="muted">No bill yet</span>') },
                    { data: 'invoice_status', title: 'Bill Status',
                      render: (d, t) => t !== 'display' ? (d || '')
                        : (d ? `<span class="conn-status ${escapeHtml(d)}">${escapeHtml(d)}</span>` : '<span class="muted">—</span>') },
                    { data: 'is_paid', title: 'Account',
                      render: (d, t) => t !== 'display' ? d
                        : (parseInt(d) ? '<span class="outstanding-badge zero"><i class="fas fa-check"></i> Paid up</span>'
                                       : '<span class="outstanding-badge due"><i class="fas fa-hourglass-half"></i> Dues</span>') },
                    { data: 'outstanding', title: 'Outstanding', className: 'num-cell',
                      render: (d, t, row) => t !== 'display' ? (Number(d) || 0)
                        : `<span class="outstanding-badge ${Number(d) <= 0 ? 'zero' : (row.invoice_status === 'overdue' ? 'overdue' : 'due')}">${fmtMoney(d)}</span>` },
                    { data: null, title: 'Actions', orderable: false, searchable: false, className: 'cv-actions',
                      render: (d, t, row) => t !== 'display' ? '' : `
                        ${row.invoice_id ? `<button class="action-icon" title="Print latest bill" onclick="printCustomerBill(${row.invoice_id})"><i class="fas fa-print"></i></button>` : ''}
                        <a class="action-icon view-icon" title="Open customer" href="customers.php?id=${row.id}"><i class="fas fa-up-right-from-square"></i></a>` }
                ]
            });
            pkgShowTab('subs');
        }).fail(function() {
            $('#pkgRollup').html('<span class="muted">Connection error.</span>');
        });
    }

    // straight to the printable bill — invoices.php fires the print dialog on ?print=1
    function printCustomerBill(invoiceId) {
        window.open('invoices.php?id=' + invoiceId + '&print=1', '_blank', 'noopener');
    }

    function closeViewPkgModal() { document.getElementById('viewPkgModal').classList.remove('active'); }
    document.getElementById('viewPkgModal').addEventListener('click', function(e) { if (e.target === this) closeViewPkgModal(); });

    function toggleFupThrottle() {
        const v = document.getElementById('fup_gb').value.trim();
        const grp = document.getElementById('fupThrottleGroup');
        if (v !== '' && parseInt(v, 10) > 0) {
            grp.classList.remove('initially-hidden');
            grp.style.display = '';
        } else {
            grp.classList.add('initially-hidden');
            grp.style.display = 'none';
            document.getElementById('fup_throttled_speed_mbps').value = '';
        }
    }

    function loadPackages() {
        $('#skeletonWrap').show();
        $('#cardView,#tableView,#filtersSection').hide();
        $.getJSON('?action=getPackages', function(resp) {
            $('#skeletonWrap').hide();
            if (resp.success) {
                packagesData = resp.data;
                $('#filtersSection').show();
                renderCurrentView();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' });
            }
        }).fail(function() {
            $('#skeletonWrap').hide();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
        });
    }

    function getFilteredData() {
        const status = document.getElementById('filterStatus').value;
        const cat = document.getElementById('filterCategory').value;
        // already sorted by sort_order ASC, name ASC from server
        return packagesData.filter(r => {
            if (status && r.status !== status) return false;
            if (cat && r.package_category !== cat) return false;
            return true;
        });
    }

    function renderCurrentView() {
        if (currentView === 'cards') {
            $('#tableView').hide();
            $('#cardView').show();
            renderCards(getFilteredData());
        } else {
            $('#cardView').hide();
            $('#tableView').show();
            renderTable(getFilteredData());
        }
    }

    function renderCards(data) {
        const grid = document.getElementById('pkgGrid');
        const empty = document.getElementById('emptyStateCards');
        if (!data.length) {
            grid.innerHTML = '';
            empty.classList.remove('initially-hidden');
            empty.style.display = 'block';
            return;
        }
        empty.classList.add('initially-hidden');
        empty.style.display = 'none';
        grid.innerHTML = data.map(p => {
            const delBtn = CAN_DELETE
                ? `<button class="btn btn-secondary btn-sm pkg-foot-btn" onclick="deletePackage(${p.id}, ${p.subscriber_count})"><i class="fas fa-trash"></i> Delete</button>`
                : '';
            const desc = p.description
                ? `<p class="pkg-desc">${escapeHtml(p.description)}</p>`
                : '';
            const setupSpec = parseFloat(p.setup_fee) > 0
                ? `<li><i class="fas fa-screwdriver-wrench"></i><span>Setup Fee</span><strong>${escapeHtml(p.setup_fee_display)}</strong></li>`
                : `<li><i class="fas fa-screwdriver-wrench"></i><span>Setup Fee</span><strong class="muted">Free</strong></li>`;
            const fupSpec = p.fup_gb
                ? `<li><i class="fas fa-database"></i><span>FUP Cap</span><strong>${p.fup_gb} GB</strong></li>
                   <li><i class="fas fa-gauge-low"></i><span>Throttle</span><strong>${p.fup_throttled_speed_mbps} Mbps</strong></li>`
                : `<li class="pkg-spec-wide"><i class="fas fa-infinity"></i><span>Data</span><strong>Unlimited (no FUP)</strong></li>`;
            const cat = escapeHtml(p.package_category);
            return `
                <article class="pkg-card pkg-cat-${cat}">
                    <header class="pkg-head">
                        <div class="pkg-title">
                            <h3 class="pkg-name">${escapeHtml(p.name)}</h3>
                            <span class="pkg-cat-pill ${cat}">${escapeHtml(catLabel(p.package_category))}</span>
                        </div>
                        <span class="pkg-status-pill ${p.status}"><span class="pkg-status-dot"></span>${p.status}</span>
                    </header>

                    <div class="pkg-hero">
                        <div class="pkg-speed-block">
                            <div class="pkg-speed-num">${p.speed_mbps}</div>
                            <div class="pkg-speed-label">Mbps Down</div>
                            <div class="pkg-speed-sub"><i class="fas fa-arrow-up"></i> ${p.upload_speed_mbps} Up</div>
                        </div>
                        <div class="pkg-price-block">
                            <div class="pkg-price">${escapeHtml(p.price_display)}</div>
                            <div class="pkg-cycle"><i class="fas fa-calendar-alt"></i> ${cycleLabel(p.billing_cycle)}</div>
                        </div>
                    </div>

                    ${desc}

                    <ul class="pkg-specs">
                        <li><i class="fas fa-users"></i><span>Subscribers</span><strong>${p.subscriber_count}</strong></li>
                        ${setupSpec}
                        ${fupSpec}
                    </ul>

                    <footer class="pkg-foot">
                        <button class="btn btn-primary btn-sm pkg-foot-btn" onclick='editPackage(${JSON.stringify(p)})'><i class="fas fa-edit"></i> Edit</button>
                        <button class="btn btn-secondary btn-sm pkg-foot-btn" onclick="viewPackage(${p.id})" title="Who is on this package right now"><i class="fas fa-users-viewfinder"></i> 360° View</button>
                        ${delBtn}
                    </footer>
                </article>
            `;
        }).join('');
    }

    function renderTable(data) {
        if (packagesTable) { packagesTable.destroy(); $('#packagesTable').empty(); }
        setTimeout(() => {
            packagesTable = $('#packagesTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'sort_order', title: 'Sort', visible: false },
                    { data: 'id', title: 'ID' },
                    { data: 'name', title: 'Name', render: d => escapeHtml(d) },
                    {
                        data: 'package_category', title: 'Category',
                        render: d => `<span class="pkg-cat ${escapeHtml(d)}">${escapeHtml(catLabel(d))}</span>`
                    },
                    {
                        data: 'speed_mbps', title: 'Down',
                        render: d => `<span class="speed-cell">${d} Mbps</span>`
                    },
                    {
                        data: 'upload_speed_mbps', title: 'Up',
                        render: d => `<span class="speed-cell">${d} Mbps</span>`
                    },
                    {
                        data: null, title: 'FUP',
                        render: function(d, t, row) {
                            if (!row.fup_gb) return '<span class="muted">Unlimited</span>';
                            return `${row.fup_gb} GB &rarr; ${row.fup_throttled_speed_mbps} Mbps`;
                        }
                    },
                    {
                        data: 'price_display', title: 'Price',
                        render: d => `<span class="price-cell">${escapeHtml(d)}</span>`
                    },
                    {
                        data: 'setup_fee_display', title: 'Setup Fee',
                        render: (d, t, row) => parseFloat(row.setup_fee) > 0 ? escapeHtml(d) : '<span class="muted">-</span>'
                    },
                    {
                        data: 'billing_cycle', title: 'Cycle',
                        render: d => cycleLabel(d)
                    },
                    {
                        data: 'subscriber_count', title: 'Subscribers',
                        render: d => `<span class="subs-cell">${d}</span>`
                    },
                    {
                        data: 'status', title: 'Status',
                        render: d => `<span class="status-pill ${d}">${d}</span>`
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(d, t, row) {
                            const rj = escapeHtml(JSON.stringify(row));
                            const del = CAN_DELETE
                                ? `<button class="act-more-item danger" onclick="deletePackage(${row.id}, ${row.subscriber_count})"><i class="fas fa-trash"></i> Delete</button>`
                                : '';
                            return `
                                <button class="action-icon view-icon" onclick="viewPackage(${row.id})" title="View"><i class="fas fa-eye"></i></button>
                                <div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">
                                    <button class="act-more-item" onclick="viewPackage(${row.id})"><i class="fas fa-users-viewfinder"></i> 360° View</button>
                                    <button class="act-more-item" data-row="${rj}" onclick="editPackage(JSON.parse(this.dataset.row))"><i class="fas fa-edit"></i> Edit</button>
                                    ${del}
                                </div></div>
                            `;
                        }
                    }
                ],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: ':not(:last-child)' } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':not(:last-child)' } }
                ],
                order: [[0, 'asc'], [2, 'asc']], // sort_order asc, name asc
                language: { emptyTable: 'No packages defined yet.' }
            });
        }, 50);
    }

    function toggleView() {
        currentView = currentView === 'cards' ? 'table' : 'cards';
        const btn = document.getElementById('toggleViewBtn');
        btn.innerHTML = currentView === 'cards'
            ? '<i class="fas fa-table"></i> Table View'
            : '<i class="fas fa-th"></i> Card View';
        renderCurrentView();
    }

    function applyFilters() {
        renderCurrentView();
    }

    function clearFilters() {
        ['filterStatus','filterCategory'].forEach(id => {
            const el = document.getElementById(id);
            el.value = '';
            el._sd && el._sd.refresh();
        });
        renderCurrentView();
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Package';
        document.getElementById('packageForm').reset();
        document.getElementById('packageId').value = '';
        // defaults
        document.getElementById('setup_fee').value = '0';
        document.getElementById('security_deposit').value = '0';
        document.getElementById('min_contract_months').value = '0';
        document.getElementById('sort_order').value = '0';
        document.getElementById('tax_pct').value = '0';
        document.getElementById('late_fee_amount').value = '0';
        document.getElementById('late_fee_pct').value = '0';
        document.getElementById('late_fee_grace_days').value = '0';
        document.getElementById('equipment_rental_charge').value = '0';
        document.getElementById('commission_pct_for_sales').value = '0';
        // reset toggles (uncheck)
        ['tax_inclusive','is_promotional','equipment_included','included_static_ip'].forEach(id => {
            const el = document.getElementById(id); if (el) el.checked = false;
        });
        // reset code touched flag
        const c = document.getElementById('package_code'); if (c) c.dataset.touched = '0';
        ['package_category','target_segment','billing_cycle','status'].forEach(id => {
            const el = document.getElementById(id);
            if (id === 'package_category') el.value = 'home';
            else if (id === 'target_segment') el.value = 'home';
            else if (id === 'billing_cycle') el.value = 'monthly';
            else el.value = 'active';
            el._sd && el._sd.refresh();
        });
        toggleFupThrottle();
        togglePromoGroups();
        document.getElementById('packageModal').classList.add('active');
    }

    function editPackage(pkg) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Package';
        document.getElementById('packageId').value = pkg.id;
        document.getElementById('name').value = pkg.name || '';
        document.getElementById('speed_mbps').value = pkg.speed_mbps || '';
        document.getElementById('upload_speed_mbps').value = pkg.upload_speed_mbps || '';
        document.getElementById('fup_gb').value = pkg.fup_gb != null ? pkg.fup_gb : '';
        document.getElementById('fup_throttled_speed_mbps').value = pkg.fup_throttled_speed_mbps != null ? pkg.fup_throttled_speed_mbps : '';
        document.getElementById('price').value = pkg.price || '';
        document.getElementById('setup_fee').value = pkg.setup_fee || '0';
        document.getElementById('security_deposit').value = pkg.security_deposit || '0';
        document.getElementById('min_contract_months').value = pkg.min_contract_months || '0';
        document.getElementById('sort_order').value = pkg.sort_order || '0';
        document.getElementById('description').value = pkg.description || '';

        // new fields
        const codeEl = document.getElementById('package_code');
        codeEl.value = pkg.package_code || '';
        codeEl.dataset.touched = '1'; // edit: don't auto-replace
        document.getElementById('tax_pct').value = pkg.tax_pct || '0';
        document.getElementById('tax_inclusive').checked = !!parseInt(pkg.tax_inclusive || 0);
        document.getElementById('late_fee_amount').value = pkg.late_fee_amount || '0';
        document.getElementById('late_fee_pct').value = pkg.late_fee_pct || '0';
        document.getElementById('late_fee_grace_days').value = pkg.late_fee_grace_days || '0';
        document.getElementById('bandwidth_profile_name').value = pkg.bandwidth_profile_name || '';
        document.getElementById('quota_gb').value = pkg.quota_gb != null ? pkg.quota_gb : '';
        document.getElementById('night_speed_mbps').value = pkg.night_speed_mbps != null ? pkg.night_speed_mbps : '';
        document.getElementById('night_start_time').value = pkg.night_start_time || '';
        document.getElementById('night_end_time').value = pkg.night_end_time || '';
        document.getElementById('valid_from').value = pkg.valid_from || '';
        document.getElementById('valid_until').value = pkg.valid_until || '';
        document.getElementById('is_promotional').checked = !!parseInt(pkg.is_promotional || 0);
        document.getElementById('promotional_price').value = pkg.promotional_price || '';
        document.getElementById('promotional_months').value = pkg.promotional_months != null ? pkg.promotional_months : '';
        document.getElementById('equipment_included').checked = !!parseInt(pkg.equipment_included || 0);
        document.getElementById('equipment_rental_charge').value = pkg.equipment_rental_charge || '0';
        document.getElementById('included_static_ip').checked = !!parseInt(pkg.included_static_ip || 0);
        document.getElementById('commission_pct_for_sales').value = pkg.commission_pct_for_sales || '0';

        const cat = document.getElementById('package_category');
        cat.value = pkg.package_category || 'home';
        cat._sd && cat._sd.refresh();
        const seg = document.getElementById('target_segment');
        seg.value = pkg.target_segment || 'home';
        seg._sd && seg._sd.refresh();
        const bc = document.getElementById('billing_cycle');
        bc.value = pkg.billing_cycle;
        bc._sd && bc._sd.refresh();
        const st = document.getElementById('status');
        st.value = pkg.status;
        st._sd && st._sd.refresh();

        toggleFupThrottle();
        togglePromoGroups();
        document.getElementById('packageModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('packageModal').classList.remove('active');
        document.getElementById('packageForm').reset();
    }

    document.getElementById('packageModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.getElementById('packageForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        // strip throttle if fup empty (server also clears, but be tidy)
        if (!document.getElementById('fup_gb').value.trim()) {
            fd.delete('fup_throttled_speed_mbps');
        }
        const action = isEditMode ? 'updatePackage' : 'addPackage';
        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=' + action, method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 2000, showConfirmButton: false });
                    closeModal();
                    setTimeout(() => loadPackages(), 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    });

    function deletePackage(id, subCount) {
        if (!CAN_DELETE) return;
        if (subCount > 0) {
            Swal.fire({ icon: 'warning', title: 'Cannot Delete', text: `${subCount} active subscriber(s) on this package. Move them to another package first.` });
            return;
        }
        Swal.fire({
            icon: 'warning', title: 'Delete Package?', text: 'This will soft-delete the package.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deletePackage', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(() => loadPackages(), 100);
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
