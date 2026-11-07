<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: any of the 4 roles (collector reads own area only)
if (!isset($_SESSION['user_id']) || !can('customers', 'v')) {
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
$current_page = 'customers';

// next CUST-NNNN code generator
function nextCustomerCode($conn) {
    $res = $conn->query("SELECT IFNULL(MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)), 0) + 1 AS n FROM customers");
    $n = $res ? (int)$res->fetch_assoc()['n'] : 1;
    if ($n < 1) $n = 1;
    return sprintf('CUST-%04d', $n);
}

// csv import/template contract for this page
function customerImportSpec() {
    return [
        'unique'  => ['phone'],   // a re-run of the same sheet must not duplicate the subscriber base
        'entity'  => 'Customer',
        'table'   => 'customers',
        'perm'    => 'customers',
        'columns' => [
            'full_name'         => ['label' => 'Full Name',         'type' => 's', 'required' => true, 'max' => 100, 'demo' => 'Customer 1'],
            'father_name'       => ['label' => 'Father Name',       'type' => 's', 'max' => 100, 'demo' => 'Father 1'],
            'phone'             => ['label' => 'Phone',             'type' => 's', 'required' => true, 'max' => 20, 'demo' => '03001000001'],
            'alternate_phone'   => ['label' => 'Alternate Phone',   'type' => 's', 'max' => 20, 'demo' => '03001000002'],
            'email'             => ['label' => 'Email',             'type' => 'email', 'max' => 100, 'demo' => 'customer1@demo.com'],
            'cnic'              => ['label' => 'CNIC',              'type' => 's', 'max' => 20, 'demo' => '3520210000001'],
            'address'           => ['label' => 'Address',           'type' => 's', 'required' => true, 'demo' => 'House 1, Street 1, Demo City'],
            'area_id'           => ['label' => 'Area',              'type' => 'lookup', 'required' => true,
                                     'lookup' => ['table' => 'areas', 'match' => ['name', 'area_code'], 'where' => "is_deleted = 0 AND status='active'"],
                                     'demo' => 'Area 1'],
            'package_id'        => ['label' => 'Package',           'type' => 'lookup', 'required' => true,
                                     'lookup' => ['table' => 'packages', 'match' => ['name'], 'where' => "is_deleted = 0 AND status='active'"],
                                     'demo' => 'Home Pro 25Mbps'],
            'installation_date' => ['label' => 'Installation Date', 'type' => 'date', 'required' => true, 'demo' => '2026-01-01'],
            'monthly_due_day'   => ['label' => 'Monthly Due Day',   'type' => 'i', 'default' => 1, 'demo' => '5'],
            'connection_status' => ['label' => 'Connection Status', 'type' => 'enum',
                                     'values' => ['active', 'suspended', 'disconnected'], 'default' => 'active', 'demo' => 'active'],
            'ip_address'        => ['label' => 'IP Address',        'type' => 's', 'max' => 45, 'demo' => '192.168.1.10'],
            'mac_address'       => ['label' => 'MAC Address',       'type' => 's', 'max' => 20, 'demo' => '00:1A:2B:3C:4D:5E'],
            'notes'             => ['label' => 'Notes',             'type' => 's', 'demo' => 'Imported from CSV'],
        ],
        // seq code once per batch — mirrors addCustomer's nextCustomerCode(), no per-row query
        'init' => function ($conn, &$state) {
            $r = $conn->query("SELECT IFNULL(MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)), 0) AS n FROM customers");
            $state['nextSeq'] = $r ? ((int)$r->fetch_assoc()['n'] + 1) : 1;
        },
        'before' => function (&$row, $conn, &$state) {
            // clamp due day same as addCustomer
            if ($row['monthly_due_day'] < 1)  $row['monthly_due_day'] = 1;
            if ($row['monthly_due_day'] > 28) $row['monthly_due_day'] = 28;
            $row['customer_code'] = sprintf('CUST-%04d', $state['nextSeq']++);
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
            case 'importCsv':      rsImportRun($conn, customerImportSpec());
            case 'importTemplate': rsImportTemplate(customerImportSpec());

            case 'getCustomers': {
                // collector area filter
                $areaFilterSQL = '';
                $params = [];
                $types  = '';
                if (isAreaScoped()) {
                    $areaFilterSQL = areaScope('c');
                    if ($areaFilterSQL === '') {
                        echo json_encode(['success' => true, 'data' => []]);
                        exit();
                    }
                }

                $sql = "SELECT c.id, c.customer_code, c.full_name, c.phone, c.alternate_phone, c.email, c.cnic,
                               c.area_id, c.package_id, c.connection_status, c.installation_date,
                               c.last_payment_date, c.created_at,
                               a.name AS area_name, p.name AS package_name, p.price AS package_price,
                               IFNULL((
                                   SELECT SUM(i.balance_amount)
                                   FROM invoices i
                                   WHERE i.customer_id = c.id AND i.is_deleted = 0
                                     AND i.status IN ('unpaid','partial','overdue')
                               ),0) AS outstanding,
                               (SELECT COUNT(*) FROM invoices io
                                  WHERE io.customer_id = c.id AND io.is_deleted = 0
                                    AND io.status = 'overdue') AS overdue_count,
                               (SELECT COUNT(*) FROM invoices iu
                                  WHERE iu.customer_id = c.id AND iu.is_deleted = 0
                                    AND iu.status IN ('unpaid','partial','overdue')) AS unpaid_count
                        FROM customers c
                        LEFT JOIN areas a ON a.id = c.area_id
                        LEFT JOIN packages p ON p.id = c.package_id
                        WHERE c.is_deleted = 0 $areaFilterSQL
                        ORDER BY c.customer_code ASC";

                if ($params) {
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $res = $stmt->get_result();
                } else {
                    $res = $conn->query($sql);
                }

                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $lpd = $r['last_payment_date'];
                    $rows[] = [
                        'id'                  => (int)$r['id'],
                        'customer_code'       => $r['customer_code'],
                        'full_name'           => $r['full_name'],
                        'phone'               => $r['phone'],
                        'alternate_phone'     => $r['alternate_phone'] ?? '',
                        'email'               => $r['email'] ?? '',
                        'cnic'                => $r['cnic'] ?? '',
                        'area_id'             => (int)$r['area_id'],
                        'package_id'          => (int)$r['package_id'],
                        'area_name'           => $r['area_name'] ?? '',
                        'package_name'        => $r['package_name'] ?? '',
                        'package_price'       => (float)($r['package_price'] ?? 0),
                        'connection_status'   => $r['connection_status'],
                        'outstanding'         => (float)$r['outstanding'],
                        'overdue_count'       => (int)($r['overdue_count'] ?? 0),
                        'unpaid_count'        => (int)($r['unpaid_count'] ?? 0),
                        'installation_date'   => $r['installation_date'],
                        'last_payment_date'   => $lpd ? date('c', strtotime($lpd)) : '',
                        'created_at'          => $r['created_at'] ? date('c', strtotime($r['created_at'])) : ''
                    ];
                }
                if (isset($stmt)) $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getCustomerDetail': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }

                // base + collector gate
                $sql = "SELECT c.*, a.name AS area_name, p.name AS package_name, p.price AS package_price, p.speed_mbps
                        FROM customers c
                        LEFT JOIN areas a ON a.id = c.area_id
                        LEFT JOIN packages p ON p.id = c.package_id
                        WHERE c.id = ? AND c.is_deleted = 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $cust = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$cust) {
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }
                if (isAreaScoped() && !areaAllowed($cust['area_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                // invoices (last 25)
                $invs = [];
                $stmt = $conn->prepare("SELECT id, invoice_number, billing_month, total, paid_amount, balance_amount,
                                               due_date, issue_date, status, created_at
                                        FROM invoices WHERE customer_id = ? AND is_deleted = 0
                                        ORDER BY id DESC LIMIT 25");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($x = $r->fetch_assoc()) {
                    $invs[] = [
                        'id'             => (int)$x['id'],
                        'invoice_number' => $x['invoice_number'],
                        'billing_month'  => $x['billing_month'],
                        'total'          => (float)$x['total'],
                        'paid_amount'    => (float)($x['paid_amount'] ?? 0),
                        'balance_amount' => (float)($x['balance_amount'] ?? 0),
                        'due_date'       => $x['due_date'],
                        'issue_date'     => $x['issue_date'],
                        'status'         => $x['status'],
                        'created_at'     => $x['created_at'] ? date('c', strtotime($x['created_at'])) : ''
                    ];
                }
                $stmt->close();

                // payments (last 25)
                $pays = [];
                $stmt = $conn->prepare("SELECT pa.id, pa.receipt_number, pa.payment_date, pa.amount,
                                               pa.payment_method, u.name AS collector
                                        FROM payments pa
                                        LEFT JOIN users u ON u.id = pa.collected_by
                                        WHERE pa.customer_id = ? AND pa.is_deleted = 0
                                        ORDER BY pa.id DESC LIMIT 25");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($x = $r->fetch_assoc()) {
                    $pays[] = [
                        'id'             => (int)$x['id'],
                        'receipt_number' => $x['receipt_number'],
                        'payment_date'   => $x['payment_date'],
                        'amount'         => (float)$x['amount'],
                        'payment_method' => $x['payment_method'],
                        'collector'      => $x['collector'] ?? '—'
                    ];
                }
                $stmt->close();

                // complaints (last 25)
                $cmps = [];
                $stmt = $conn->prepare("SELECT id, ticket_number, subject, priority, status, created_at
                                        FROM complaints WHERE customer_id = ?
                                        ORDER BY id DESC LIMIT 25");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($x = $r->fetch_assoc()) {
                    $cmps[] = [
                        'id'            => (int)$x['id'],
                        'ticket_number' => $x['ticket_number'],
                        'subject'       => $x['subject'],
                        'priority'      => $x['priority'],
                        'status'        => $x['status'],
                        'created_at'    => $x['created_at'] ? date('c', strtotime($x['created_at'])) : ''
                    ];
                }
                $stmt->close();

                // outstanding total — denormalized balance, consistent w/ aging/collections
                $stmt = $conn->prepare("SELECT IFNULL(SUM(i.balance_amount),0) AS outstanding
                                        FROM invoices i
                                        WHERE i.customer_id = ? AND i.is_deleted = 0
                                          AND i.status IN ('unpaid','partial','overdue')");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $out = (float)($stmt->get_result()->fetch_assoc()['outstanding'] ?? 0);
                $stmt->close();

                // documents
                $docs = [];
                $stmt = $conn->prepare("SELECT d.id, d.doc_type, d.file_path, d.original_name, d.file_size,
                                               d.uploaded_by, d.uploaded_at, u.name AS uploader_name
                                        FROM customer_documents d
                                        LEFT JOIN users u ON u.id = d.uploaded_by
                                        WHERE d.customer_id = ?
                                        ORDER BY d.id DESC");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($x = $r->fetch_assoc()) {
                    $docs[] = [
                        'id'             => (int)$x['id'],
                        'doc_type'       => $x['doc_type'],
                        'file_path'      => $x['file_path'],
                        'original_name'  => $x['original_name'],
                        'file_size'      => (int)$x['file_size'],
                        'uploaded_by'    => (int)$x['uploaded_by'],
                        'uploader_name'  => $x['uploader_name'] ?? '—',
                        'uploaded_at'    => $x['uploaded_at'] ? date('c', strtotime($x['uploaded_at'])) : ''
                    ];
                }
                $stmt->close();

                // overdue count (used to color outstanding badge red)
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM invoices WHERE customer_id = ? AND is_deleted = 0 AND status = 'overdue'");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $overdue_cnt = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();

                $cust['outstanding']       = $out;
                $cust['overdue_count']     = $overdue_cnt;
                $cust['id']                = (int)$cust['id'];
                $cust['area_id']           = (int)$cust['area_id'];
                $cust['package_id']        = (int)$cust['package_id'];
                $cust['monthly_due_day']   = (int)$cust['monthly_due_day'];
                // numeric casts for tier-1
                $cust['gps_lat']             = $cust['gps_lat']           !== null ? (float)$cust['gps_lat'] : null;
                $cust['gps_lng']             = $cust['gps_lng']           !== null ? (float)$cust['gps_lng'] : null;
                $cust['vlan_id']             = $cust['vlan_id']           !== null ? (int)$cust['vlan_id']   : null;
                $cust['registration_fee']    = (float)$cust['registration_fee'];
                $cust['security_deposit']    = (float)$cust['security_deposit'];
                $cust['discount_pct']        = (float)$cust['discount_pct'];
                $cust['billing_email_optin'] = (int)$cust['billing_email_optin'];
                $cust['whatsapp_optin']      = (int)$cust['whatsapp_optin'];
                // numeric casts for tier-2 (defensive — may not exist on legacy rows)
                $intNullable = ['nas_id','olt_id','splitter_id','cable_length_meters','contract_period_months','salesperson_user_id','installation_user_id','referred_by_customer_id'];
                foreach ($intNullable as $k) {
                    if (array_key_exists($k, $cust)) $cust[$k] = $cust[$k] !== null ? (int)$cust[$k] : null;
                }
                $intFlag = ['mac_binding','tax_exempt','auto_pay_enabled','agreement_signed'];
                foreach ($intFlag as $k) { if (array_key_exists($k, $cust)) $cust[$k] = (int)$cust[$k]; }
                $floatFields = ['signal_strength_dbm','credit_limit','early_termination_fee'];
                foreach ($floatFields as $k) {
                    if (array_key_exists($k, $cust)) $cust[$k] = $cust[$k] !== null ? (float)$cust[$k] : null;
                }

                echo json_encode([
                    'success'    => true,
                    'customer'   => $cust,
                    'invoices'   => $invs,
                    'payments'   => $pays,
                    'complaints' => $cmps,
                    'documents'  => $docs
                ]);
                exit();
            }

            // full unbounded ledger for one customer — no LIMIT, server-side running balance
            case 'getCustomerLedger': {
                $id = (int)($_POST['customer_id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid customer']);
                    exit();
                }

                // base + collector gate (same as getCustomerDetail)
                $stmt = $conn->prepare("SELECT c.id, c.customer_code, c.full_name, c.area_id, c.phone, c.address,
                                               a.name AS area_name, p.name AS package_name, c.connection_status
                                        FROM customers c
                                        LEFT JOIN areas a    ON a.id = c.area_id
                                        LEFT JOIN packages p ON p.id = c.package_id
                                        WHERE c.id = ? AND c.is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $cust = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$cust) {
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }
                if (isAreaScoped() && !areaAllowed($cust['area_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                // one source of truth — ledger_entries (posted by the sync* helpers in config.php)
                $stmt = $conn->prepare("SELECT entry_date, particulars, ref_type, ref_number, debit, credit
                                        FROM ledger_entries
                                        WHERE is_deleted = 0 AND party_type = 'customer' AND party_id = ?
                                        ORDER BY entry_date ASC, id ASC");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result();

                // running balance = cumulative debit - cumulative credit (positive = receivable)
                $bal = 0.0;
                $ledger = [];
                while ($x = $r->fetch_assoc()) {
                    $d = (float)$x['debit']; $c = (float)$x['credit'];
                    $bal += $d - $c;
                    $ledger[] = [
                        'date'            => $x['entry_date'] ? date('c', strtotime($x['entry_date'])) : '',
                        'type'            => $x['ref_type'],
                        'ref'             => $x['ref_number'],
                        'description'     => $x['particulars'],
                        'debit'           => $d,
                        'credit'          => $c,
                        'running_balance' => round($bal, 2)
                    ];
                }
                $stmt->close();

                echo json_encode([
                    'success'  => true,
                    'customer' => [
                        'id'            => (int)$cust['id'],
                        'customer_code' => $cust['customer_code'],
                        'full_name'     => $cust['full_name'],
                        'phone'         => $cust['phone'] ?? '',
                        'address'       => $cust['address'] ?? '',
                        'area_name'     => $cust['area_name'] ?? '',
                        'package_name'  => $cust['package_name'] ?? '',
                        'status'        => $cust['connection_status'] ?? ''
                    ],
                    'ledger'   => $ledger
                ]);
                exit();
            }

            case 'getAreas': {
                $res = $conn->query("SELECT id, name FROM areas WHERE is_deleted = 0 AND status='active' ORDER BY name ASC");
                $rows = [];
                while ($r = $res->fetch_assoc()) $rows[] = ['id' => (int)$r['id'], 'name' => $r['name']];
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getPackages': {
                $res = $conn->query("SELECT id, name, price, speed_mbps FROM packages WHERE is_deleted = 0 AND status='active' ORDER BY price ASC");
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'         => (int)$r['id'],
                        'name'       => $r['name'],
                        'price'      => (float)$r['price'],
                        'speed_mbps' => (int)$r['speed_mbps']
                    ];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            // staff for salesperson + installer + commission_eligible flag fallback
            case 'getStaff': {
                $sql = "SELECT id, name, role
                        FROM users
                        WHERE is_deleted = 0 AND status='active'
                          AND (" . roleInSql('users', 'customers', 'v') . "
                               OR commission_eligible = 1)
                        ORDER BY name ASC";
                if (!($res = @$conn->query($sql))) {
                    // fallback if commission_eligible not yet present
                    $res = $conn->query("SELECT id, name, role FROM users
                                         WHERE is_deleted = 0 AND status='active'
                                           AND " . roleInSql('users', 'customers', 'v') . "
                                         ORDER BY name ASC");
                }
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'role' => $r['role']];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            // referral picker — searchable list of customers
            case 'getReferralCustomers': {
                $res = $conn->query("SELECT id, customer_code, full_name FROM customers
                                     WHERE is_deleted = 0
                                     ORDER BY customer_code ASC");
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'            => (int)$r['id'],
                        'customer_code' => $r['customer_code'],
                        'full_name'     => $r['full_name']
                    ];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            // counts per status for chevron pipeline
            case 'getStatusCounts': {
                $areaFilterSQL = '';
                $params = [];
                $types  = '';
                if (isAreaScoped()) {
                    $areaFilterSQL = areaScope('customers');
                    if ($areaFilterSQL === '') {
                        echo json_encode(['success' => true, 'counts' => ['all'=>0,'active'=>0,'suspended'=>0,'disconnected'=>0]]);
                        exit();
                    }
                }
                $sql = "SELECT connection_status, COUNT(*) c
                        FROM customers
                        WHERE is_deleted = 0 $areaFilterSQL
                        GROUP BY connection_status";
                if ($params) {
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $r = $stmt->get_result();
                } else {
                    $r = $conn->query($sql);
                }
                $counts = ['all'=>0,'active'=>0,'suspended'=>0,'disconnected'=>0];
                while ($row = $r->fetch_assoc()) {
                    $s = $row['connection_status'];
                    $c = (int)$row['c'];
                    if (isset($counts[$s])) $counts[$s] = $c;
                    $counts['all'] += $c;
                }
                if (isset($stmt)) $stmt->close();
                echo json_encode(['success' => true, 'counts' => $counts]);
                exit();
            }

            case 'addCustomer': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!canManageCustomers()) {
                    echo json_encode(['success' => false, 'message' => 'Permission denied']);
                    exit();
                }

                $full_name = trim($_POST['full_name'] ?? '');
                $phone     = trim($_POST['phone'] ?? '');
                $alt_phone = trim($_POST['alternate_phone'] ?? '');
                $father    = trim($_POST['father_name'] ?? '');
                $email     = trim($_POST['email'] ?? '');
                $cnic      = trim($_POST['cnic'] ?? '');
                $address   = trim($_POST['address'] ?? '');
                $area_id   = (int)($_POST['area_id'] ?? 0);
                $pkg_id    = (int)($_POST['package_id'] ?? 0);
                $inst_date = trim($_POST['installation_date'] ?? '');
                $due_day   = (int)($_POST['monthly_due_day'] ?? 1);
                $ip_addr   = trim($_POST['ip_address'] ?? '');
                $mac_addr  = trim($_POST['mac_address'] ?? '');
                $conn_st   = $_POST['connection_status'] ?? 'active';
                $notes     = trim($_POST['notes'] ?? '');
                // tier-1
                $gps_lat_in = trim($_POST['gps_lat'] ?? '');
                $gps_lng_in = trim($_POST['gps_lng'] ?? '');
                $gps_lat    = $gps_lat_in === '' ? null : (float)$gps_lat_in;
                $gps_lng    = $gps_lng_in === '' ? null : (float)$gps_lng_in;
                $router_sn  = trim($_POST['router_serial'] ?? '');
                $conn_type  = $_POST['connection_type'] ?? 'pppoe';
                $vlan_in    = trim($_POST['vlan_id'] ?? '');
                $vlan_id    = $vlan_in === '' ? null : (int)$vlan_in;
                $reg_fee    = (float)($_POST['registration_fee'] ?? 0);
                $sec_dep    = (float)($_POST['security_deposit'] ?? 0);
                $disc_pct   = (float)($_POST['discount_pct'] ?? 0);
                $bill_optin = !empty($_POST['billing_email_optin']) ? 1 : 0;
                $wa_optin   = !empty($_POST['whatsapp_optin']) ? 1 : 0;

                // ── tier-2 — network ──
                $pppoe_user   = trim($_POST['pppoe_username'] ?? '');
                $pppoe_pwd    = trim($_POST['pppoe_password'] ?? '');
                $conn_medium  = $_POST['connection_medium'] ?? 'fiber';
                $nas_in       = trim($_POST['nas_id'] ?? '');
                $nas_id       = $nas_in === '' ? null : (int)$nas_in;
                $static_ip    = trim($_POST['static_ip_address'] ?? '');
                $subnet       = trim($_POST['subnet_mask'] ?? '');
                $gateway      = trim($_POST['gateway'] ?? '');
                $dns1         = trim($_POST['dns1'] ?? '');
                $dns2         = trim($_POST['dns2'] ?? '');
                $olt_in       = trim($_POST['olt_id'] ?? '');
                $olt_id       = $olt_in === '' ? null : (int)$olt_in;
                $olt_port     = trim($_POST['olt_port'] ?? '');
                $pon_port     = trim($_POST['pon_port'] ?? '');
                $spl_in       = trim($_POST['splitter_id'] ?? '');
                $splitter_id  = $spl_in === '' ? null : (int)$spl_in;
                $splitter_port= trim($_POST['splitter_port'] ?? '');
                $ont_serial   = trim($_POST['ont_serial'] ?? '');
                $ont_loid     = trim($_POST['ont_loid'] ?? '');
                $sig_in       = trim($_POST['signal_strength_dbm'] ?? '');
                $signal_dbm   = $sig_in === '' ? null : (float)$sig_in;
                $bw_profile   = trim($_POST['bandwidth_profile_name'] ?? '');
                $mac_binding  = !empty($_POST['mac_binding']) ? 1 : 0;
                $cable_in     = trim($_POST['cable_length_meters'] ?? '');
                $cable_len    = $cable_in === '' ? null : (int)$cable_in;

                // ── tier-2 — billing/tax ──
                $cust_type    = $_POST['customer_type'] ?? 'residential';
                $biz_name     = trim($_POST['business_name'] ?? '');
                $tax_id       = trim($_POST['tax_id'] ?? '');
                $ntn_number   = trim($_POST['ntn_number'] ?? '');
                $gst_number   = trim($_POST['gst_number'] ?? '');
                $tax_exempt   = !empty($_POST['tax_exempt']) ? 1 : 0;
                $credit_limit = (float)($_POST['credit_limit'] ?? 0);
                $autopay_en   = !empty($_POST['auto_pay_enabled']) ? 1 : 0;
                $autopay_mthd = trim($_POST['auto_pay_method'] ?? '');
                $vip_status   = $_POST['vip_status'] ?? 'regular';

                // ── tier-2 — kyc ──
                $dob          = trim($_POST['dob'] ?? '');
                $dob          = $dob === '' ? null : $dob;
                $gender_in    = $_POST['gender'] ?? '';
                $gender       = in_array($gender_in, ['male','female','other'], true) ? $gender_in : null;
                $nationality  = trim($_POST['nationality'] ?? 'Pakistan');
                $landmark     = trim($_POST['nearest_landmark'] ?? '');
                $whatsapp_no  = trim($_POST['whatsapp_number'] ?? '');

                // ── tier-2 — lifecycle ──
                $sales_in     = trim($_POST['salesperson_user_id'] ?? '');
                $salesperson  = $sales_in === '' ? null : (int)$sales_in;
                $inst_user_in = trim($_POST['installation_user_id'] ?? '');
                $install_user = $inst_user_in === '' ? null : (int)$inst_user_in;
                $agreement_signed = !empty($_POST['agreement_signed']) ? 1 : 0;
                $agreement_pdf    = trim($_POST['agreement_pdf_path'] ?? '');
                $agree_start_in   = trim($_POST['agreement_start_date'] ?? '');
                $agree_start      = $agree_start_in === '' ? null : $agree_start_in;
                $agree_end_in     = trim($_POST['agreement_end_date'] ?? '');
                $agree_end        = $agree_end_in === '' ? null : $agree_end_in;
                $contract_months  = (int)($_POST['contract_period_months'] ?? 0);
                $early_term_fee   = (float)($_POST['early_termination_fee'] ?? 0);
                $acq_src          = trim($_POST['acquisition_source'] ?? '');
                $ref_cust_in      = trim($_POST['referred_by_customer_id'] ?? '');
                $ref_cust_id      = $ref_cust_in === '' ? null : (int)$ref_cust_in;

                if ($full_name === '' || $phone === '' || $address === '' || $area_id <= 0 || $pkg_id <= 0 || $inst_date === '') {
                    echo json_encode(['success' => false, 'message' => 'Name, phone, address, area, package and installation date are required']);
                    exit();
                }
                if (!in_array($conn_st, ['active','suspended','disconnected'], true)) $conn_st = 'active';
                if (!in_array($conn_type, ['pppoe','static','dhcp'], true)) $conn_type = 'pppoe';
                if (!in_array($conn_medium, ['fiber','wireless','ethernet','dsl','vdsl'], true)) $conn_medium = 'fiber';
                if (!in_array($cust_type, ['residential','business','enterprise','govt'], true)) $cust_type = 'residential';
                if (!in_array($vip_status, ['regular','silver','gold','vip'], true)) $vip_status = 'regular';
                if ($cust_type !== 'residential' && $biz_name === '') {
                    echo json_encode(['success' => false, 'message' => 'Business name is required for business/enterprise/govt customers']);
                    exit();
                }
                if ($due_day < 1) $due_day = 1; if ($due_day > 28) $due_day = 28;
                if ($disc_pct < 0) $disc_pct = 0; if ($disc_pct > 100) $disc_pct = 100;
                if ($reg_fee < 0) $reg_fee = 0;
                if ($sec_dep < 0) $sec_dep = 0;
                if ($credit_limit < 0) $credit_limit = 0;
                if ($early_term_fee < 0) $early_term_fee = 0;
                if ($contract_months < 0) $contract_months = 0;
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                $code = nextCustomerCode($conn);

                $stmt = $conn->prepare("INSERT INTO customers
                    (customer_code, full_name, phone, alternate_phone, father_name, email, cnic, address,
                     area_id, package_id, installation_date, monthly_due_day,
                     ip_address, mac_address, connection_status, notes,
                     gps_lat, gps_lng, router_serial, connection_type, vlan_id,
                     registration_fee, security_deposit, discount_pct,
                     billing_email_optin, whatsapp_optin,
                     pppoe_username, pppoe_password, connection_medium, nas_id,
                     static_ip_address, subnet_mask, gateway, dns1, dns2,
                     olt_id, olt_port, pon_port, splitter_id, splitter_port,
                     ont_serial, ont_loid, signal_strength_dbm, bandwidth_profile_name,
                     mac_binding, cable_length_meters,
                     customer_type, business_name, tax_id, ntn_number, gst_number, tax_exempt,
                     credit_limit, auto_pay_enabled, auto_pay_method, vip_status,
                     dob, gender, nationality, nearest_landmark, whatsapp_number,
                     salesperson_user_id, installation_user_id,
                     agreement_signed, agreement_pdf_path, agreement_start_date, agreement_end_date,
                     contract_period_months, early_termination_fee,
                     acquisition_source, referred_by_customer_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                // 71 params: 26 base + 20 net + 10 bill + 5 kyc + 10 lifecycle
                $stmt->bind_param(
                    "ssssssssiisissssddssidddii"
                    . "sssisssssississsdsii"
                    . "sssssidiss"
                    . "sssss"
                    . "iiisssidsi",
                    $code, $full_name, $phone, $alt_phone, $father, $email, $cnic, $address,
                    $area_id, $pkg_id,
                    $inst_date, $due_day,
                    $ip_addr, $mac_addr, $conn_st, $notes,
                    $gps_lat, $gps_lng,
                    $router_sn, $conn_type, $vlan_id,
                    $reg_fee, $sec_dep, $disc_pct,
                    $bill_optin, $wa_optin,
                    // network (20)
                    $pppoe_user, $pppoe_pwd, $conn_medium, $nas_id,
                    $static_ip, $subnet, $gateway, $dns1, $dns2,
                    $olt_id, $olt_port, $pon_port, $splitter_id, $splitter_port,
                    $ont_serial, $ont_loid, $signal_dbm, $bw_profile,
                    $mac_binding, $cable_len,
                    // billing/tax (10)
                    $cust_type, $biz_name, $tax_id, $ntn_number, $gst_number, $tax_exempt,
                    $credit_limit, $autopay_en, $autopay_mthd, $vip_status,
                    // kyc (5)
                    $dob, $gender, $nationality, $landmark, $whatsapp_no,
                    // lifecycle (10)
                    $salesperson, $install_user,
                    $agreement_signed, $agreement_pdf, $agree_start, $agree_end,
                    $contract_months, $early_term_fee,
                    $acq_src, $ref_cust_id
                );

                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    logActivity($user_id, $username, 'Customer Created', "Created $code: $full_name");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => "Customer $code added", 'id' => $newId, 'customer_code' => $code]);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add: ' . $err]);
                }
                exit();
            }

            case 'updateCustomer': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }

                // load existing for collector gate + audit
                $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$existing) {
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }

                // collector — own area only, status field only
                if (isAreaScoped()) {
                    if (!areaAllowed($existing['area_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Access denied']);
                        exit();
                    }
                    $newStatus = $_POST['connection_status'] ?? '';
                    if (!in_array($newStatus, ['active','suspended','disconnected'], true)) {
                        echo json_encode(['success' => false, 'message' => 'Invalid connection status']);
                        exit();
                    }
                    $reason = trim($_POST['reason'] ?? '');
                    $now = date('Y-m-d H:i:s');
                    if ($newStatus === 'suspended') {
                        $stmt = $conn->prepare("UPDATE customers SET connection_status='suspended', suspended_at=?, suspension_reason=? WHERE id=?");
                        $stmt->bind_param("ssi", $now, $reason, $id);
                    } elseif ($newStatus === 'disconnected') {
                        $stmt = $conn->prepare("UPDATE customers SET connection_status='disconnected', disconnected_at=?, disconnect_reason=? WHERE id=?");
                        $stmt->bind_param("ssi", $now, $reason, $id);
                    } else {
                        $stmt = $conn->prepare("UPDATE customers SET connection_status='active', suspended_at=NULL, suspension_reason=NULL, disconnected_at=NULL, disconnect_reason=NULL WHERE id=?");
                        $stmt->bind_param("i", $id);
                    }
                    if ($stmt->execute()) {
                        logActivity($user_id, $username, 'Customer Status', "Changed {$existing['customer_code']} -> $newStatus" . ($reason !== '' ? " ($reason)" : ''));
                        $stmt->close();
                        echo json_encode(['success' => true, 'message' => 'Connection status updated']);
                    } else {
                        $err = $stmt->error; $stmt->close();
                        echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                    }
                    exit();
                }

                if (!canManageCustomers()) {
                    echo json_encode(['success' => false, 'message' => 'Permission denied']);
                    exit();
                }

                $full_name = trim($_POST['full_name'] ?? '');
                $phone     = trim($_POST['phone'] ?? '');
                $alt_phone = trim($_POST['alternate_phone'] ?? '');
                $father    = trim($_POST['father_name'] ?? '');
                $email     = trim($_POST['email'] ?? '');
                $cnic      = trim($_POST['cnic'] ?? '');
                $address   = trim($_POST['address'] ?? '');
                $area_id   = (int)($_POST['area_id'] ?? 0);
                $pkg_id    = (int)($_POST['package_id'] ?? 0);
                $inst_date = trim($_POST['installation_date'] ?? '');
                $due_day   = (int)($_POST['monthly_due_day'] ?? 1);
                $ip_addr   = trim($_POST['ip_address'] ?? '');
                $mac_addr  = trim($_POST['mac_address'] ?? '');
                $conn_st   = $_POST['connection_status'] ?? 'active';
                $notes     = trim($_POST['notes'] ?? '');
                // tier-1
                $gps_lat_in = trim($_POST['gps_lat'] ?? '');
                $gps_lng_in = trim($_POST['gps_lng'] ?? '');
                $gps_lat    = $gps_lat_in === '' ? null : (float)$gps_lat_in;
                $gps_lng    = $gps_lng_in === '' ? null : (float)$gps_lng_in;
                $router_sn  = trim($_POST['router_serial'] ?? '');
                $conn_type  = $_POST['connection_type'] ?? 'pppoe';
                $vlan_in    = trim($_POST['vlan_id'] ?? '');
                $vlan_id    = $vlan_in === '' ? null : (int)$vlan_in;
                $reg_fee    = (float)($_POST['registration_fee'] ?? 0);
                $sec_dep    = (float)($_POST['security_deposit'] ?? 0);
                $disc_pct   = (float)($_POST['discount_pct'] ?? 0);
                $bill_optin = !empty($_POST['billing_email_optin']) ? 1 : 0;
                $wa_optin   = !empty($_POST['whatsapp_optin']) ? 1 : 0;

                // tier-2 — network
                $pppoe_user   = trim($_POST['pppoe_username'] ?? '');
                $pppoe_pwd    = trim($_POST['pppoe_password'] ?? '');
                $conn_medium  = $_POST['connection_medium'] ?? 'fiber';
                $nas_in       = trim($_POST['nas_id'] ?? '');
                $nas_id       = $nas_in === '' ? null : (int)$nas_in;
                $static_ip    = trim($_POST['static_ip_address'] ?? '');
                $subnet       = trim($_POST['subnet_mask'] ?? '');
                $gateway      = trim($_POST['gateway'] ?? '');
                $dns1         = trim($_POST['dns1'] ?? '');
                $dns2         = trim($_POST['dns2'] ?? '');
                $olt_in       = trim($_POST['olt_id'] ?? '');
                $olt_id       = $olt_in === '' ? null : (int)$olt_in;
                $olt_port     = trim($_POST['olt_port'] ?? '');
                $pon_port     = trim($_POST['pon_port'] ?? '');
                $spl_in       = trim($_POST['splitter_id'] ?? '');
                $splitter_id  = $spl_in === '' ? null : (int)$spl_in;
                $splitter_port= trim($_POST['splitter_port'] ?? '');
                $ont_serial   = trim($_POST['ont_serial'] ?? '');
                $ont_loid     = trim($_POST['ont_loid'] ?? '');
                $sig_in       = trim($_POST['signal_strength_dbm'] ?? '');
                $signal_dbm   = $sig_in === '' ? null : (float)$sig_in;
                $bw_profile   = trim($_POST['bandwidth_profile_name'] ?? '');
                $mac_binding  = !empty($_POST['mac_binding']) ? 1 : 0;
                $cable_in     = trim($_POST['cable_length_meters'] ?? '');
                $cable_len    = $cable_in === '' ? null : (int)$cable_in;

                // tier-2 — billing/tax
                $cust_type    = $_POST['customer_type'] ?? 'residential';
                $biz_name     = trim($_POST['business_name'] ?? '');
                $tax_id       = trim($_POST['tax_id'] ?? '');
                $ntn_number   = trim($_POST['ntn_number'] ?? '');
                $gst_number   = trim($_POST['gst_number'] ?? '');
                $tax_exempt   = !empty($_POST['tax_exempt']) ? 1 : 0;
                $credit_limit = (float)($_POST['credit_limit'] ?? 0);
                $autopay_en   = !empty($_POST['auto_pay_enabled']) ? 1 : 0;
                $autopay_mthd = trim($_POST['auto_pay_method'] ?? '');
                $vip_status   = $_POST['vip_status'] ?? 'regular';

                // tier-2 — kyc
                $dob          = trim($_POST['dob'] ?? '');
                $dob          = $dob === '' ? null : $dob;
                $gender_in    = $_POST['gender'] ?? '';
                $gender       = in_array($gender_in, ['male','female','other'], true) ? $gender_in : null;
                $nationality  = trim($_POST['nationality'] ?? 'Pakistan');
                $landmark     = trim($_POST['nearest_landmark'] ?? '');
                $whatsapp_no  = trim($_POST['whatsapp_number'] ?? '');

                // tier-2 — lifecycle
                $sales_in     = trim($_POST['salesperson_user_id'] ?? '');
                $salesperson  = $sales_in === '' ? null : (int)$sales_in;
                $inst_user_in = trim($_POST['installation_user_id'] ?? '');
                $install_user = $inst_user_in === '' ? null : (int)$inst_user_in;
                $agreement_signed = !empty($_POST['agreement_signed']) ? 1 : 0;
                $agreement_pdf    = trim($_POST['agreement_pdf_path'] ?? '');
                $agree_start_in   = trim($_POST['agreement_start_date'] ?? '');
                $agree_start      = $agree_start_in === '' ? null : $agree_start_in;
                $agree_end_in     = trim($_POST['agreement_end_date'] ?? '');
                $agree_end        = $agree_end_in === '' ? null : $agree_end_in;
                $contract_months  = (int)($_POST['contract_period_months'] ?? 0);
                $early_term_fee   = (float)($_POST['early_termination_fee'] ?? 0);
                $acq_src          = trim($_POST['acquisition_source'] ?? '');
                $ref_cust_in      = trim($_POST['referred_by_customer_id'] ?? '');
                $ref_cust_id      = $ref_cust_in === '' ? null : (int)$ref_cust_in;

                if ($full_name === '' || $phone === '' || $address === '' || $area_id <= 0 || $pkg_id <= 0 || $inst_date === '') {
                    echo json_encode(['success' => false, 'message' => 'Required fields missing']);
                    exit();
                }
                if (!in_array($conn_st, ['active','suspended','disconnected'], true)) $conn_st = 'active';
                if (!in_array($conn_type, ['pppoe','static','dhcp'], true)) $conn_type = 'pppoe';
                if (!in_array($conn_medium, ['fiber','wireless','ethernet','dsl','vdsl'], true)) $conn_medium = 'fiber';
                if (!in_array($cust_type, ['residential','business','enterprise','govt'], true)) $cust_type = 'residential';
                if (!in_array($vip_status, ['regular','silver','gold','vip'], true)) $vip_status = 'regular';
                if ($cust_type !== 'residential' && $biz_name === '') {
                    echo json_encode(['success' => false, 'message' => 'Business name is required for business/enterprise/govt customers']);
                    exit();
                }
                if ($due_day < 1) $due_day = 1; if ($due_day > 28) $due_day = 28;
                if ($disc_pct < 0) $disc_pct = 0; if ($disc_pct > 100) $disc_pct = 100;
                if ($reg_fee < 0) $reg_fee = 0;
                if ($sec_dep < 0) $sec_dep = 0;
                if ($credit_limit < 0) $credit_limit = 0;
                if ($early_term_fee < 0) $early_term_fee = 0;
                if ($contract_months < 0) $contract_months = 0;
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                // status side-effects for suspended/disconnected timestamps
                $oldStatus  = $existing['connection_status'];
                $reason     = trim($_POST['reason'] ?? '');
                $susAt      = $existing['suspended_at'];
                $susReason  = $existing['suspension_reason'];
                $disAt      = $existing['disconnected_at'];
                $disReason  = $existing['disconnect_reason'];
                if ($conn_st !== $oldStatus) {
                    if ($conn_st === 'suspended') {
                        $susAt = date('Y-m-d H:i:s');
                        if ($reason !== '') $susReason = $reason;
                    } elseif ($conn_st === 'disconnected') {
                        $disAt = date('Y-m-d H:i:s');
                        if ($reason !== '') $disReason = $reason;
                    } elseif ($conn_st === 'active') {
                        $susAt = null; $susReason = null;
                        $disAt = null; $disReason = null;
                    }
                }

                $stmt = $conn->prepare("UPDATE customers SET
                    full_name=?, phone=?, alternate_phone=?, father_name=?, email=?, cnic=?, address=?,
                    area_id=?, package_id=?, installation_date=?, monthly_due_day=?,
                    ip_address=?, mac_address=?, connection_status=?, notes=?,
                    gps_lat=?, gps_lng=?, router_serial=?, connection_type=?, vlan_id=?,
                    registration_fee=?, security_deposit=?, discount_pct=?,
                    billing_email_optin=?, whatsapp_optin=?,
                    pppoe_username=?, pppoe_password=?, connection_medium=?, nas_id=?,
                    static_ip_address=?, subnet_mask=?, gateway=?, dns1=?, dns2=?,
                    olt_id=?, olt_port=?, pon_port=?, splitter_id=?, splitter_port=?,
                    ont_serial=?, ont_loid=?, signal_strength_dbm=?, bandwidth_profile_name=?,
                    mac_binding=?, cable_length_meters=?,
                    customer_type=?, business_name=?, tax_id=?, ntn_number=?, gst_number=?, tax_exempt=?,
                    credit_limit=?, auto_pay_enabled=?, auto_pay_method=?, vip_status=?,
                    dob=?, gender=?, nationality=?, nearest_landmark=?, whatsapp_number=?,
                    salesperson_user_id=?, installation_user_id=?,
                    agreement_signed=?, agreement_pdf_path=?, agreement_start_date=?, agreement_end_date=?,
                    contract_period_months=?, early_termination_fee=?,
                    acquisition_source=?, referred_by_customer_id=?,
                    suspended_at=?, suspension_reason=?, disconnected_at=?, disconnect_reason=?
                    WHERE id=? AND is_deleted = 0");
                // 75 params: 25 base (no code) + 20 net + 10 bill + 5 kyc + 10 lifecycle + 4 sus/dis + 1 id
                $stmt->bind_param(
                    "sssssssiisissssddssidddii"
                    . "sssisssssississsdsii"
                    . "sssssidiss"
                    . "sssss"
                    . "iiisssidsi"
                    . "ssss"
                    . "i",
                    $full_name, $phone, $alt_phone, $father, $email, $cnic, $address,
                    $area_id, $pkg_id, $inst_date, $due_day,
                    $ip_addr, $mac_addr, $conn_st, $notes,
                    $gps_lat, $gps_lng,
                    $router_sn, $conn_type, $vlan_id,
                    $reg_fee, $sec_dep, $disc_pct,
                    $bill_optin, $wa_optin,
                    // network 20
                    $pppoe_user, $pppoe_pwd, $conn_medium, $nas_id,
                    $static_ip, $subnet, $gateway, $dns1, $dns2,
                    $olt_id, $olt_port, $pon_port, $splitter_id, $splitter_port,
                    $ont_serial, $ont_loid, $signal_dbm, $bw_profile,
                    $mac_binding, $cable_len,
                    // billing/tax 10
                    $cust_type, $biz_name, $tax_id, $ntn_number, $gst_number, $tax_exempt,
                    $credit_limit, $autopay_en, $autopay_mthd, $vip_status,
                    // kyc 5
                    $dob, $gender, $nationality, $landmark, $whatsapp_no,
                    // lifecycle 10
                    $salesperson, $install_user,
                    $agreement_signed, $agreement_pdf, $agree_start, $agree_end,
                    $contract_months, $early_term_fee,
                    $acq_src, $ref_cust_id,
                    // sus/dis 4
                    $susAt, $susReason, $disAt, $disReason,
                    $id
                );

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Updated', "Updated {$existing['customer_code']}: $full_name");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Customer updated']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                }
                exit();
            }

            case 'toggleConnection': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }

                $stmt = $conn->prepare("SELECT customer_code, area_id, connection_status FROM customers WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $cur = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$cur) {
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }
                if (isAreaScoped() && !areaAllowed($cur['area_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                // explicit target or auto-cycle active->suspended->disconnected->active
                $target = $_POST['target_status'] ?? '';
                $reason = trim($_POST['reason'] ?? '');
                if (!in_array($target, ['active','suspended','disconnected'], true)) {
                    $cyc = ['active' => 'suspended', 'suspended' => 'disconnected', 'disconnected' => 'active'];
                    $target = $cyc[$cur['connection_status']] ?? 'active';
                }

                $now = date('Y-m-d H:i:s');
                if ($target === 'suspended') {
                    $stmt = $conn->prepare("UPDATE customers SET connection_status='suspended', suspended_at=?, suspension_reason=? WHERE id=?");
                    $stmt->bind_param("ssi", $now, $reason, $id);
                } elseif ($target === 'disconnected') {
                    $stmt = $conn->prepare("UPDATE customers SET connection_status='disconnected', disconnected_at=?, disconnect_reason=? WHERE id=?");
                    $stmt->bind_param("ssi", $now, $reason, $id);
                } else {
                    // back to active — clear all sus/dis trace
                    $stmt = $conn->prepare("UPDATE customers SET connection_status='active', suspended_at=NULL, suspension_reason=NULL, disconnected_at=NULL, disconnect_reason=NULL WHERE id=?");
                    $stmt->bind_param("i", $id);
                }
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Status', "Set {$cur['customer_code']} -> $target" . ($reason !== '' ? " ($reason)" : ''));
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Status changed', 'status' => $target]);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                }
                exit();
            }

            case 'deleteCustomer': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!can('customers', 'd')) {
                    echo json_encode(['success' => false, 'message' => 'Only super admin can delete']);
                    exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }

                $stmt = $conn->prepare("SELECT customer_code, full_name FROM customers WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $cur = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$cur) {
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }

                $stmt = $conn->prepare("UPDATE customers SET is_deleted = 1 WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Deleted', "Deleted {$cur['customer_code']}: {$cur['full_name']}");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Customer deleted']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                }
                exit();
            }

            case 'getCustomerDocuments': {
                $cid = (int)($_GET['customer_id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid customer']); exit(); }

                // collector area gate
                if (isCollector()) {
                    $stmt = $conn->prepare("SELECT area_id FROM customers WHERE id = ? AND is_deleted = 0");
                    $stmt->bind_param("i", $cid);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$row || !areaAllowed($row['area_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Access denied']);
                        exit();
                    }
                }

                $stmt = $conn->prepare("SELECT d.id, d.doc_type, d.file_path, d.original_name, d.file_size,
                                               d.uploaded_by, d.uploaded_at, u.name AS uploader_name
                                        FROM customer_documents d
                                        LEFT JOIN users u ON u.id = d.uploaded_by
                                        WHERE d.customer_id = ?
                                        ORDER BY d.id DESC");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $r = $stmt->get_result();
                $docs = [];
                while ($x = $r->fetch_assoc()) {
                    $docs[] = [
                        'id'             => (int)$x['id'],
                        'doc_type'       => $x['doc_type'],
                        'file_path'      => $x['file_path'],
                        'original_name'  => $x['original_name'],
                        'file_size'      => (int)$x['file_size'],
                        'uploaded_by'    => (int)$x['uploaded_by'],
                        'uploader_name'  => $x['uploader_name'] ?? '—',
                        'uploaded_at'    => $x['uploaded_at'] ? date('c', strtotime($x['uploaded_at'])) : ''
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $docs]);
                exit();
            }

            case 'uploadCustomerDocument': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $cid     = (int)($_POST['customer_id'] ?? 0);
                $docType = $_POST['doc_type'] ?? '';
                if ($cid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid customer']); exit(); }
                if (!in_array($docType, ['cnic_front','cnic_back','contract','utility_bill','other'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid document type']);
                    exit();
                }
                if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
                    exit();
                }

                // collector area gate (collectors can upload too)
                $stmt = $conn->prepare("SELECT customer_code, area_id FROM customers WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $cust = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$cust) { echo json_encode(['success' => false, 'message' => 'Customer not found']); exit(); }
                if (isAreaScoped() && !areaAllowed($cust['area_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $f = $_FILES['file'];
                if ($f['size'] > 5 * 1024 * 1024) {
                    echo json_encode(['success' => false, 'message' => 'File too large (max 5MB)']);
                    exit();
                }
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','webp','pdf'];
                if (!in_array($ext, $allowed, true)) {
                    echo json_encode(['success' => false, 'message' => 'Type not allowed (jpg/png/webp/pdf only)']);
                    exit();
                }

                $dir = __DIR__ . '/uploads/customer_docs/';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $fname = 'cust_' . $cid . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
                $dest  = $dir . $fname;
                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
                    exit();
                }

                $relPath = 'uploads/customer_docs/' . $fname;
                $size    = (int)$f['size'];
                $orig    = substr($f['name'], 0, 200);
                $stmt = $conn->prepare("INSERT INTO customer_documents
                    (customer_id, doc_type, file_path, original_name, file_size, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?)");
                // cid=i, type=s, path=s, name=s, size=i, uploader=i
                $stmt->bind_param("isssii", $cid, $docType, $relPath, $orig, $size, $user_id);
                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    logActivity($user_id, $username, 'Document Uploaded', "Doc $docType for {$cust['customer_code']}");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Document uploaded', 'id' => $newId, 'file_path' => $relPath]);
                } else {
                    @unlink($dest);
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'DB error: ' . $err]);
                }
                exit();
            }

            case 'deleteCustomerDocument': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $did = (int)($_POST['id'] ?? 0);
                if ($did <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }

                $stmt = $conn->prepare("SELECT d.id, d.customer_id, d.uploaded_by, d.file_path, c.customer_code, c.area_id
                                        FROM customer_documents d
                                        LEFT JOIN customers c ON c.id = d.customer_id
                                        WHERE d.id = ?");
                $stmt->bind_param("i", $did);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { echo json_encode(['success' => false, 'message' => 'Document not found']); exit(); }

                // permission: uploader OR admin-level
                $isOwn = ((int)$row['uploaded_by'] === (int)$user_id);
                if (!$isOwn && !isAdminLevel()) {
                    echo json_encode(['success' => false, 'message' => 'Permission denied']);
                    exit();
                }
                if (isAreaScoped() && !areaAllowed($row['area_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM customer_documents WHERE id = ?");
                $stmt->bind_param("i", $did);
                if ($stmt->execute()) {
                    $abs = __DIR__ . '/' . $row['file_path'];
                    if (is_file($abs)) @unlink($abs);
                    logActivity($user_id, $username, 'Document Deleted', "Doc id $did from {$row['customer_code']}");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Document deleted']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                }
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("customers.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// detail vs list mode flag for HTML render
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$canEdit  = canManageCustomers();
$canDel   = can('customers', 'd');
$currency = htmlspecialchars(getSetting('currency_symbol', 'PKR'));
$siteName = getSetting('site_name', 'ISP Billing');
$siteLogo = getSetting('site_logo', '');
$compPhone = getSetting('company_phone', '');
$compEmail = getSetting('company_email', '');
$compAddr  = getSetting('company_address', '');
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Customers - ISP Billing</title>

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
            <?php if ($detailId > 0): ?>
                <div class="breadcrumb">
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                    <span class="breadcrumb-sep">/</span>
                    <a href="customers.php"><i class="fas fa-user-friends"></i> Customers</a>
                    <span class="breadcrumb-sep">/</span>
                    <span id="bcCustCode">Loading...</span>
                </div>
                <div class="header">
                    <h1><i class="fas fa-id-card"></i> Customer Detail</h1>
                    <?php include 'notifications_bell.php'; ?>
                </div>

                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-user"></i> <span id="custTitle">Loading...</span></h2>
                        <div class="btn-group-inline">
                            <a href="customers.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                            <?php if ($canEdit): ?>
                            <button class="btn btn-warning" onclick="editFromDetail()"><i class="fas fa-edit"></i> Edit</button>
                            <?php endif; ?>
                            <button class="btn btn-success" onclick="genInvoiceQuick()"><i class="fas fa-file-invoice"></i> Generate Invoice</button>
                        </div>
                    </div>

                    <!-- skeleton while loading -->
                    <div id="detailSkeleton" class="skeleton-table">
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="width:30%"></div><div class="skeleton skeleton-table-cell" style="width:70%"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="width:30%"></div><div class="skeleton skeleton-table-cell" style="width:70%"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="width:30%"></div><div class="skeleton skeleton-table-cell" style="width:70%"></div></div>
                    </div>

                    <div class="detail-grid initially-hidden" id="detailGrid">
                        <div class="detail-side">
                            <div class="detail-card">
                                <h3><i class="fas fa-user-circle"></i> Customer Info</h3>
                                <div class="detail-row"><span class="detail-row-label">Code</span><span class="detail-row-value" id="dCode">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Full Name</span><span class="detail-row-value" id="dName">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Father Name</span><span class="detail-row-value" id="dFather">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Phone</span><span class="detail-row-value" id="dPhone">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Alt Phone</span><span class="detail-row-value" id="dAltPhone">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Email</span><span class="detail-row-value" id="dEmail">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">CNIC</span><span class="detail-row-value" id="dCnic">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Status</span><span class="detail-row-value" id="dConn">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Outstanding</span><span class="detail-row-value" id="dOutstanding">—</span></div>
                            </div>

                            <div class="detail-card">
                                <h3><i class="fas fa-network-wired"></i> Connection</h3>
                                <div class="detail-row"><span class="detail-row-label">Area</span><span class="detail-row-value" id="dArea">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Package</span><span class="detail-row-value" id="dPkg">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Installed</span><span class="detail-row-value" id="dInstall">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Due Day</span><span class="detail-row-value" id="dDueDay">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">IP Address</span><span class="detail-row-value" id="dIp">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">MAC</span><span class="detail-row-value" id="dMac">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Type</span><span class="detail-row-value" id="dConnType">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">VLAN</span><span class="detail-row-value" id="dVlan">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Router S/N</span><span class="detail-row-value" id="dRouter">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Last Payment</span><span class="detail-row-value" id="dLastPay">—</span></div>
                            </div>

                            <div class="detail-card">
                                <h3><i class="fas fa-map-marker-alt"></i> Address</h3>
                                <div class="detail-row" style="display:block">
                                    <span class="detail-row-value" id="dAddress" style="text-align:left">—</span>
                                </div>
                                <div class="detail-row" id="dGpsRow" style="display:none">
                                    <span class="detail-row-label">GPS</span>
                                    <span class="detail-row-value" id="dGps">—</span>
                                </div>
                            </div>

                            <div class="detail-card">
                                <h3><i class="fas fa-coins"></i> Financial &amp; Preferences</h3>
                                <div class="detail-row"><span class="detail-row-label">Registration Fee</span><span class="detail-row-value num-cell" id="dRegFee">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Security Deposit</span><span class="detail-row-value num-cell" id="dSecDep">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Discount %</span><span class="detail-row-value num-cell" id="dDiscPct">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Billing Email</span><span class="detail-row-value" id="dBillOptin">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">WhatsApp</span><span class="detail-row-value" id="dWaOptin">—</span></div>
                            </div>

                            <div class="detail-card initially-hidden" id="dSusCard">
                                <h3><i class="fas fa-pause-circle"></i> Suspension</h3>
                                <div class="detail-row"><span class="detail-row-label">Suspended At</span><span class="detail-row-value" id="dSusAt">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Reason</span><span class="detail-row-value" id="dSusReason">—</span></div>
                            </div>

                            <div class="detail-card initially-hidden" id="dDisCard">
                                <h3><i class="fas fa-power-off"></i> Disconnection</h3>
                                <div class="detail-row"><span class="detail-row-label">Disconnected At</span><span class="detail-row-value" id="dDisAt">—</span></div>
                                <div class="detail-row"><span class="detail-row-label">Reason</span><span class="detail-row-value" id="dDisReason">—</span></div>
                            </div>
                        </div>

                        <div>
                            <div class="detail-tabs">
                                <div class="detail-tab active" data-tab="invoices"><i class="fas fa-file-invoice-dollar"></i> Invoices</div>
                                <div class="detail-tab" data-tab="payments"><i class="fas fa-money-bill-wave"></i> Payments</div>
                                <div class="detail-tab" data-tab="complaints"><i class="fas fa-ticket-alt"></i> Complaints</div>
                                <div class="detail-tab" data-tab="documents"><i class="fas fa-folder-open"></i> Documents</div>
                                <div class="detail-tab" data-tab="notes"><i class="fas fa-sticky-note"></i> Notes</div>
                            </div>
                            <div class="detail-tab-content">
                                <div id="tab-invoices"></div>
                                <div id="tab-payments" class="initially-hidden"></div>
                                <div id="tab-complaints" class="initially-hidden"></div>
                                <div id="tab-documents" class="initially-hidden"></div>
                                <div id="tab-notes" class="initially-hidden">
                                    <textarea id="dNotes" rows="8" style="width:100%" <?php if (!$canEdit) echo 'readonly'; ?>></textarea>
                                    <?php if ($canEdit): ?>
                                    <div class="form-actions">
                                        <button class="btn btn-primary" onclick="saveNotes()"><i class="fas fa-save"></i> Save Notes</button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- LIST VIEW -->
                <div class="breadcrumb">
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                    <span class="breadcrumb-sep">/</span>
                    <span>Customers</span>
                </div>
                <div class="header">
                    <h1><i class="fas fa-user-friends"></i> Customers</h1>
                    <?php include 'notifications_bell.php'; ?>
                </div>

                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-table"></i> Subscribers</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-primary" onclick="loadCustomers()">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <?php if (can('customers', 'a')): ?>
                            <button class="btn btn-secondary" onclick="rsImport.pick()">
                                <i class="fas fa-file-import"></i> Import CSV
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-secondary" onclick="rsImport.template()">
                                <i class="fas fa-download"></i> Template
                            </button>
                            <?php if ($canEdit): ?>
                            <button class="btn btn-success" onclick="openAddModal()">
                                <i class="fas fa-user-plus"></i> Add Customer
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- chevron pipeline (status quick-filter) -->
                    <div class="chevron-pipeline initially-hidden" id="chevronPipeline" role="tablist" aria-label="Filter customers by connection status">
                        <div class="chevron-tab cv-all active"          data-status="all"          role="tab" tabindex="0">
                            <i class="fas fa-list"></i> All <span class="cv-count" id="cv-c-all">0</span>
                        </div>
                        <div class="chevron-tab cv-active"              data-status="active"       role="tab" tabindex="0">
                            <i class="fas fa-circle-check"></i> Active <span class="cv-count" id="cv-c-active">0</span>
                        </div>
                        <div class="chevron-tab cv-suspended"           data-status="suspended"    role="tab" tabindex="0">
                            <i class="fas fa-pause-circle"></i> Suspended <span class="cv-count" id="cv-c-suspended">0</span>
                        </div>
                        <div class="chevron-tab cv-disconnected"        data-status="disconnected" role="tab" tabindex="0">
                            <i class="fas fa-power-off"></i> Disconnected <span class="cv-count" id="cv-c-disconnected">0</span>
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
                                <label><i class="fas fa-search"></i> Search</label>
                                <input type="text" id="filterSearch" placeholder="Code / name / phone...">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-map-marked-alt"></i> Area</label>
                                <select id="filterArea"><option value="">All Areas</option></select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-box"></i> Package</label>
                                <select id="filterPkg"><option value="">All Packages</option></select>
                            </div>
                        </div>
                    </div>

                    <!-- skeleton while loading -->
                    <div id="listSkeleton" class="skeleton-table">
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                    </div>

                    <div class="table-responsive initially-hidden" id="tableWrap">
                        <table id="custTable" class="display table-full-width">
                            <tfoot>
                                <tr>
                                    <th class="dt-foot-label">Total Customers</th>
                                    <th></th><th></th><th></th><th></th>
                                    <th class="dt-foot-val" id="footTotalCount">0</th>
                                    <th class="dt-foot-val" id="footOutstanding">—</th>
                                    <th class="dt-foot-label" id="footOverdue">Overdue: 0</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="empty-state initially-hidden" id="emptyState">
                        <div class="empty-state-icon"><i class="fas fa-user-friends"></i></div>
                        <div class="empty-state-title">No customers yet — add your first subscriber.</div>
                        <div class="empty-state-text">Click the Add Customer button to start enrolling.</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Customer Modal -->
    <div class="modal-overlay" id="custModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add Customer</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="custForm">
                    <input type="hidden" id="custId" name="id">

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-user"></i> Personal</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-id-badge"></i> Customer Code</label>
                                <input type="text" id="customer_code" readonly placeholder="Auto-generated">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Phone *</label>
                                <input type="text" id="phone" name="phone" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-mobile-alt"></i> Alternate Phone</label>
                                <input type="text" id="alternate_phone" name="alternate_phone">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user-tie"></i> Father Name</label>
                                <input type="text" id="father_name" name="father_name">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" id="email" name="email">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-id-card"></i> CNIC</label>
                                <input type="text" id="cnic" name="cnic" placeholder="00000-0000000-0">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-map-marker-alt"></i> Address</div>
                        <div class="form-group">
                            <label><i class="fas fa-home"></i> Full Address *</label>
                            <textarea id="address" name="address" rows="3" required></textarea>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-map-pin"></i> GPS Latitude</label>
                                <input type="number" step="any" id="gps_lat" name="gps_lat" placeholder="24.8607">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-map-pin"></i> GPS Longitude</label>
                                <input type="number" step="any" id="gps_lng" name="gps_lng" placeholder="67.0011">
                            </div>
                        </div>
                        <div class="form-group">
                            <a href="#" id="useMyLocation" class="gps-link"><i class="fas fa-location-arrow"></i> Use my location</a>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-network-wired"></i> Connection</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-map-marked-alt"></i> Area *</label>
                                <select id="area_id" name="area_id" required><option value="">Select area...</option></select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-box"></i> Package *</label>
                                <select id="package_id" name="package_id" required><option value="">Select package...</option></select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-day"></i> Installation Date *</label>
                                <input type="date" id="installation_date" name="installation_date" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-alt"></i> Monthly Due Day *</label>
                                <input type="number" id="monthly_due_day" name="monthly_due_day" min="1" max="28" value="1" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-globe"></i> IP Address</label>
                                <input type="text" id="ip_address" name="ip_address" placeholder="192.168.1.100">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-microchip"></i> MAC Address</label>
                                <input type="text" id="mac_address" name="mac_address" placeholder="AA:BB:CC:DD:EE:FF">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-cogs"></i> Connection Type *</label>
                                <select id="connection_type" name="connection_type" required>
                                    <option value="pppoe">PPPoE</option>
                                    <option value="static">Static</option>
                                    <option value="dhcp">DHCP</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-project-diagram"></i> VLAN ID</label>
                                <input type="number" id="vlan_id" name="vlan_id" min="0" max="4095" placeholder="e.g. 100">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-router"></i> Router Serial</label>
                                <input type="text" id="router_serial" name="router_serial">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-plug"></i> Connection Status *</label>
                                <select id="connection_status" name="connection_status" required>
                                    <option value="active">Active</option>
                                    <option value="suspended">Suspended</option>
                                    <option value="disconnected">Disconnected</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-network-wired"></i> Network Settings</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> PPPoE Username</label>
                                <input type="text" id="pppoe_username" name="pppoe_username" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-key"></i> PPPoE Password</label>
                                <input type="password" id="pppoe_password" name="pppoe_password" autocomplete="new-password">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-broadcast-tower"></i> Connection Medium *</label>
                                <select id="connection_medium" name="connection_medium" required>
                                    <option value="fiber">Fiber</option>
                                    <option value="wireless">Wireless</option>
                                    <option value="ethernet">Ethernet</option>
                                    <option value="dsl">DSL</option>
                                    <option value="vdsl">VDSL</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-server"></i> NAS ID</label>
                                <input type="number" id="nas_id" name="nas_id" min="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-globe"></i> Static IP Address</label>
                                <input type="text" id="static_ip_address" name="static_ip_address" placeholder="e.g. 203.0.113.10">
                            </div>
                            <div class="form-group static-only">
                                <label><i class="fas fa-network-wired"></i> Subnet Mask</label>
                                <input type="text" id="subnet_mask" name="subnet_mask" placeholder="255.255.255.0">
                            </div>
                            <div class="form-group static-only">
                                <label><i class="fas fa-route"></i> Gateway</label>
                                <input type="text" id="gateway" name="gateway" placeholder="192.168.1.1">
                            </div>
                            <div class="form-group static-only">
                                <label><i class="fas fa-search-location"></i> DNS 1</label>
                                <input type="text" id="dns1" name="dns1" placeholder="8.8.8.8">
                            </div>
                            <div class="form-group static-only">
                                <label><i class="fas fa-search-location"></i> DNS 2</label>
                                <input type="text" id="dns2" name="dns2" placeholder="8.8.4.4">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-microchip"></i> OLT ID</label>
                                <input type="number" id="olt_id" name="olt_id" min="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-plug"></i> OLT Port</label>
                                <input type="text" id="olt_port" name="olt_port">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-network-wired"></i> PON Port</label>
                                <input type="text" id="pon_port" name="pon_port">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-code-branch"></i> Splitter ID</label>
                                <input type="number" id="splitter_id" name="splitter_id" min="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-sitemap"></i> Splitter Port</label>
                                <input type="text" id="splitter_port" name="splitter_port">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-barcode"></i> ONT Serial</label>
                                <input type="text" id="ont_serial" name="ont_serial">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> ONT LOID</label>
                                <input type="text" id="ont_loid" name="ont_loid">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-signal"></i> Signal Strength (dBm)</label>
                                <input type="number" step="0.01" id="signal_strength_dbm" name="signal_strength_dbm" placeholder="-23.50">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-tachometer-alt"></i> Bandwidth Profile</label>
                                <input type="text" id="bandwidth_profile_name" name="bandwidth_profile_name">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-link"></i> MAC Binding</label>
                                <label class="status-toggle">
                                    <input type="checkbox" id="mac_binding" name="mac_binding" value="1">
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-ruler"></i> Cable Length (m)</label>
                                <input type="number" id="cable_length_meters" name="cable_length_meters" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-coins"></i> Billing &amp; Tax</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-user-tag"></i> Customer Type *</label>
                                <select id="customer_type" name="customer_type" required>
                                    <option value="residential">Residential</option>
                                    <option value="business">Business</option>
                                    <option value="enterprise">Enterprise</option>
                                    <option value="govt">Government</option>
                                </select>
                            </div>
                            <div class="form-group business-only initially-hidden" id="grpBusinessName">
                                <label><i class="fas fa-building"></i> Business Name *</label>
                                <input type="text" id="business_name" name="business_name">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-id-card-alt"></i> Tax ID</label>
                                <input type="text" id="tax_id" name="tax_id">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-file-contract"></i> NTN Number</label>
                                <input type="text" id="ntn_number" name="ntn_number">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-receipt"></i> GST Number</label>
                                <input type="text" id="gst_number" name="gst_number">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-shield-alt"></i> Tax Exempt</label>
                                <label class="status-toggle">
                                    <input type="checkbox" id="tax_exempt" name="tax_exempt" value="1">
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-receipt"></i> Registration Fee</label>
                                <input type="number" step="0.01" min="0" id="registration_fee" name="registration_fee" value="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-piggy-bank"></i> Security Deposit</label>
                                <input type="number" step="0.01" min="0" id="security_deposit" name="security_deposit" value="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-percentage"></i> Discount %</label>
                                <input type="number" step="0.01" min="0" max="100" id="discount_pct" name="discount_pct" value="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-credit-card"></i> Credit Limit</label>
                                <input type="number" step="0.01" min="0" id="credit_limit" name="credit_limit" value="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-sync"></i> Auto Pay Enabled</label>
                                <label class="status-toggle">
                                    <input type="checkbox" id="auto_pay_enabled" name="auto_pay_enabled" value="1">
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-money-check"></i> Auto Pay Method</label>
                                <input type="text" id="auto_pay_method" name="auto_pay_method" placeholder="e.g. card, bank_debit">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-crown"></i> VIP Status *</label>
                                <select id="vip_status" name="vip_status" required>
                                    <option value="regular">Regular</option>
                                    <option value="silver">Silver</option>
                                    <option value="gold">Gold</option>
                                    <option value="vip">VIP</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope-open-text"></i> Billing Email Opt-in</label>
                                <label class="status-toggle">
                                    <input type="checkbox" id="billing_email_optin" name="billing_email_optin" value="1" checked>
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><i class="fab fa-whatsapp"></i> WhatsApp Opt-in</label>
                                <label class="status-toggle">
                                    <input type="checkbox" id="whatsapp_optin" name="whatsapp_optin" value="1" checked>
                                    <span class="track"></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-id-card"></i> KYC</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-birthday-cake"></i> Date of Birth</label>
                                <input type="date" id="dob" name="dob">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-venus-mars"></i> Gender</label>
                                <select id="gender" name="gender">
                                    <option value="">— Select —</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-flag"></i> Nationality</label>
                                <input type="text" id="nationality" name="nationality" value="Pakistan">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-map-pin"></i> Nearest Landmark</label>
                                <input type="text" id="nearest_landmark" name="nearest_landmark">
                            </div>
                            <div class="form-group">
                                <label><i class="fab fa-whatsapp"></i> WhatsApp Number</label>
                                <input type="text" id="whatsapp_number" name="whatsapp_number">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-recycle"></i> Lifecycle &amp; Agreement</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-user-tie"></i> Salesperson</label>
                                <select id="salesperson_user_id" name="salesperson_user_id">
                                    <option value="">— Unassigned —</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-tools"></i> Installation Engineer</label>
                                <select id="installation_user_id" name="installation_user_id">
                                    <option value="">— Unassigned —</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-file-signature"></i> Agreement Signed</label>
                                <label class="status-toggle">
                                    <input type="checkbox" id="agreement_signed" name="agreement_signed" value="1">
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-file-pdf"></i> Agreement PDF Path</label>
                                <input type="text" id="agreement_pdf_path" name="agreement_pdf_path" placeholder="uploads/agreements/...">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-day"></i> Agreement Start</label>
                                <input type="date" id="agreement_start_date" name="agreement_start_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-day"></i> Agreement End</label>
                                <input type="date" id="agreement_end_date" name="agreement_end_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hourglass-half"></i> Contract Period (months)</label>
                                <input type="number" id="contract_period_months" name="contract_period_months" min="0" value="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-money-bill"></i> Early Termination Fee</label>
                                <input type="number" step="0.01" min="0" id="early_termination_fee" name="early_termination_fee" value="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-bullhorn"></i> Acquisition Source</label>
                                <select id="acquisition_source" name="acquisition_source">
                                    <option value="">— Select —</option>
                                    <option value="walk_in">Walk-in</option>
                                    <option value="call">Call</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="referral">Referral</option>
                                    <option value="website">Website</option>
                                    <option value="social">Social</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user-friends"></i> Referred By Customer</label>
                                <select id="referred_by_customer_id" name="referred_by_customer_id">
                                    <option value="">— None —</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-sticky-note"></i> Notes</div>
                        <div class="form-group">
                            <label><i class="fas fa-edit"></i> Internal Notes</label>
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

    <!-- Customer View Modal (tabbed) -->
    <div class="modal-overlay" id="custViewModal">
        <div class="modal modal-360">
            <div class="modal-header">
                <h3 id="cvTitle"><i class="fas fa-user"></i> Customer Details</h3>
                <button class="close-btn" onclick="closeCustView()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div id="cvSkeleton" class="skeleton-table">
                    <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="width:30%"></div><div class="skeleton skeleton-table-cell" style="width:70%"></div></div>
                    <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="width:30%"></div><div class="skeleton skeleton-table-cell" style="width:70%"></div></div>
                    <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="width:30%"></div><div class="skeleton skeleton-table-cell" style="width:70%"></div></div>
                </div>
                <!-- left: who they are · right: everything they did -->
                <div id="cvContent" class="cv-360 initially-hidden">
                    <aside class="cv-profile" id="cvProfile"></aside>
                    <section class="cv-data">
                        <div class="vtab-nav" id="cvTabs">
                            <button type="button" class="vtab-btn active" data-vtab="invoices"><i class="fas fa-file-invoice"></i> Invoices <span class="cv-count" id="cvcount-invoices">0</span></button>
                            <button type="button" class="vtab-btn" data-vtab="payments"><i class="fas fa-money-bill-wave"></i> Payments <span class="cv-count" id="cvcount-payments">0</span></button>
                            <button type="button" class="vtab-btn" data-vtab="complaints"><i class="fas fa-ticket-alt"></i> Complaints <span class="cv-count" id="cvcount-complaints">0</span></button>
                            <button type="button" class="vtab-btn" data-vtab="ledger"><i class="fas fa-book"></i> Ledger <span class="cv-count" id="cvcount-ledger">0</span></button>
                        </div>
                        <div class="vtab-pane active" id="cvpane-invoices"><table id="cvInvTable" class="display cv-dt" style="width:100%"></table></div>
                        <div class="vtab-pane" id="cvpane-payments"><table id="cvPayTable" class="display cv-dt" style="width:100%"></table></div>
                        <div class="vtab-pane" id="cvpane-complaints"><table id="cvCmpTable" class="display cv-dt" style="width:100%"></table></div>
                        <div class="vtab-pane" id="cvpane-ledger"><table id="cvLedTable" class="display cv-dt" style="width:100%"></table></div>
                    </section>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Customer Ledger Modal -->
    <div class="modal-overlay" id="custLedgerModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="clTitle"><i class="fas fa-book"></i> Customer Ledger</h3>
                <div class="modal-head-actions">
                    <button class="close-btn" id="clPrintBtn" onclick="printLedger()" title="Print A4 statement"><i class="fas fa-print"></i></button>
                    <button class="close-btn" onclick="closeLedger()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="modal-body">
                <div id="clSkeleton" class="skeleton-table">
                    <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                    <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                </div>
                <div id="clContent" class="initially-hidden"></div>
            </div>
        </div>
    </div>

    <!-- A4 statement — screen-hidden, filled by printLedger() -->
    <div class="sheet print-only" id="ledgerSheet">
        <div class="sheet-header">
            <?php if ($siteLogo): ?><img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="" class="sheet-logo"><?php endif; ?>
            <div class="sheet-brand"><?php echo htmlspecialchars($siteName); ?></div>
            <div class="sheet-contact">
                <?php echo htmlspecialchars(trim(implode('  ·  ', array_filter([$compAddr, $compPhone, $compEmail])))); ?>
            </div>
            <h1 class="sheet-title">Customer Ledger Statement</h1>
        </div>

        <div class="sheet-meta">
            <div class="sheet-meta-item"><div class="sheet-meta-label">Customer</div><div class="sheet-meta-value" id="lpName">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Code</div><div class="sheet-meta-value" id="lpCode">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Phone</div><div class="sheet-meta-value" id="lpPhone">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Area / Package</div><div class="sheet-meta-value" id="lpArea">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Address</div><div class="sheet-meta-value" id="lpAddress">—</div></div>
            <div class="sheet-meta-item"><div class="sheet-meta-label">Statement Date</div><div class="sheet-meta-value" id="lpDate">—</div></div>
        </div>

        <table class="sheet-table" id="lpTable">
            <thead><tr>
                <th style="width:14%">Date</th><th style="width:16%">Reference</th><th>Description</th>
                <th class="num-cell" style="width:14%">Debit</th>
                <th class="num-cell" style="width:14%">Credit</th>
                <th class="num-cell" style="width:15%">Balance</th>
            </tr></thead>
            <tbody id="lpBody"></tbody>
            <tfoot id="lpFoot"></tfoot>
        </table>

        <div class="sheet-note" id="lpNote"></div>
        <div class="sheet-signs">
            <div class="sheet-sign">Received By</div>
            <div class="sheet-sign">Customer Signature</div>
        </div>
        <div class="sheet-footer">
            <span>Printed by <?php echo htmlspecialchars($username); ?></span>
            <span><?php echo htmlspecialchars($siteName); ?> — computer generated statement</span>
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
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>

    <script>
    // page-level state
    const DETAIL_ID    = <?php echo (int)$detailId; ?>;
    const CAN_EDIT     = <?php echo $canEdit ? 'true' : 'false'; ?>;
    const CAN_DELETE   = <?php echo $canDel ? 'true' : 'false'; ?>;
    const IS_COLLECTOR = <?php echo isCollector() ? 'true' : 'false'; ?>;
    const ROLE         = '<?php echo $role; ?>';
    const CCY          = '<?php echo $currency; ?>';

    let custTable, isEditMode = false, customersData = [], areasCache = [], packagesCache = [];
    let staffCache = [], referralCustomersCache = [];
    let detailCustomer = null;
    let chevronStatus  = 'all';  // active chevron tab

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function fmtMoney(n) { return CCY + ' ' + Number(n || 0).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtDate(d) {
        if (!d || d === '0000-00-00') return '—';
        // bare YYYY-MM-DD -> pin to local midnight (avoid UTC shift); ISO strings parse as-is
        const dt = /^\d{4}-\d{2}-\d{2}$/.test(d) ? new Date(d + 'T00:00:00') : new Date(d);
        return isNaN(dt) ? d : dt.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
    }

    $(document).ready(function() {
        if (DETAIL_ID > 0) {
            loadDetail();
        } else {
            initListPage();
        }

        // cross-tab + back-button refresh when payment recorded elsewhere
        let lastSeenDirty = localStorage.getItem('isp_invoices_dirty') || '';
        function refreshIfDirty() {
            const v = localStorage.getItem('isp_invoices_dirty') || '';
            if (v && v !== lastSeenDirty) {
                lastSeenDirty = v;
                if (DETAIL_ID > 0) loadDetail(); else if (typeof loadCustomers === 'function') loadCustomers();
            }
        }
        window.addEventListener('storage', function(e) { if (e.key === 'isp_invoices_dirty') refreshIfDirty(); });
        document.addEventListener('visibilitychange', function() { if (document.visibilityState === 'visible') refreshIfDirty(); });
        window.addEventListener('pageshow', function(e) { if (e.persisted) refreshIfDirty(); });
    });

    // ─── LIST VIEW ─────────────────────────────────
    function initListPage() {
        rsImport.init({ entity: 'Customer', onDone: loadCustomers });
        new SearchableDropdown({ selectId: 'filterArea', placeholder: 'All Areas', icon: 'fas fa-map-marked-alt' });
        new SearchableDropdown({ selectId: 'filterPkg',  placeholder: 'All Packages', icon: 'fas fa-box' });

        $('#filterArea, #filterPkg').on('change', applyFilters);
        $('#filterSearch').on('input', applyFilters);

        // chevron status quick-filter
        $(document).on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('.chevron-tab').removeClass('active');
            $(this).addClass('active');
            chevronStatus = $(this).data('status') || 'all';
            applyFilters();
        });

        // pre-fill filters from URL (?status=active|suspended|disconnected, ?search=...)
        const qp = new URLSearchParams(window.location.search);
        const qStatus = qp.get('status');
        const qSearch = qp.get('search');
        if (qStatus && ['active','suspended','disconnected'].includes(qStatus)) {
            chevronStatus = qStatus;
        }
        if (qSearch) document.getElementById('filterSearch').value = qSearch;

        loadDropdownData().then(() => {
            loadCustomers().then(() => { if (qStatus || qSearch) applyFilters(); });
            // auto-open add form from dashboard quick action (?new=1)
            if (qp.get('new') === '1' && document.querySelector('button[onclick="openAddModal()"]')) openAddModal();
        });
    }

    function refreshChevronCounts(rows) {
        const c = { all: rows.length, active: 0, suspended: 0, disconnected: 0 };
        rows.forEach(r => { if (r.connection_status in c) c[r.connection_status]++; });
        ['all','active','suspended','disconnected'].forEach(k => {
            const el = document.getElementById('cv-c-' + k);
            if (el) el.textContent = c[k];
        });
        // sync active class to chevronStatus
        $('.chevron-tab').removeClass('active');
        $('.chevron-tab[data-status="' + chevronStatus + '"]').addClass('active');
    }

    function loadDropdownData() {
        return Promise.all([
            $.getJSON('?action=getAreas'),
            $.getJSON('?action=getPackages'),
            $.getJSON('?action=getStaff'),
            $.getJSON('?action=getReferralCustomers')
        ]).then(([ar, pk, st, rc]) => {
            areasCache = ar.success ? ar.data : [];
            packagesCache = pk.success ? pk.data : [];
            staffCache = st.success ? st.data : [];
            referralCustomersCache = rc.success ? rc.data : [];
            // populate filter dropdowns
            const fArea = document.getElementById('filterArea');
            areasCache.forEach(a => fArea.add(new Option(a.name, a.id)));
            fArea._sd && fArea._sd.refresh();

            const fPkg = document.getElementById('filterPkg');
            packagesCache.forEach(p => fPkg.add(new Option(p.name, p.id)));
            fPkg._sd && fPkg._sd.refresh();
        });
    }

    // ─── CUSTOMER VIEW MODAL (tabbed) ────────────────
    let cvData = null;

    // ── Customer 360 — profile on the left, everything they did on the right ──
    let cvTables = {}; // one DataTable per tab, rebuilt on every open

    function viewCustomerModal(id) {
        $('#cvContent').addClass('initially-hidden');
        $('#cvSkeleton').show();
        $('#cvTitle').html('<i class="fas fa-user"></i> Customer Details');
        document.getElementById('custViewModal').classList.add('active');

        $.getJSON('?action=getCustomerDetail&id=' + id, function(resp) {
            $('#cvSkeleton').hide();
            if (!resp.success) {
                closeCustView();
                Swal.fire({ icon: 'error', title: 'Could not open', text: resp.message || 'Failed to load customer' });
                return;
            }
            cvData = resp;
            const c = resp.customer;
            $('#cvTitle').html(`<i class="fas fa-user"></i> ${escapeHtml(c.full_name)} <span class="muted">(${escapeHtml(c.customer_code)})</span>`);
            $('#cvContent').removeClass('initially-hidden');
            renderCvProfile(c);
            renderCvInvoices(resp.invoices || []);
            renderCvPayments(resp.payments || []);
            renderCvComplaints(resp.complaints || []);
            renderCvLedger(resp.invoices || [], resp.payments || []);
            cvShowTab('invoices');
        }).fail(() => {
            $('#cvSkeleton').hide();
            closeCustView();
            Swal.fire({ icon: 'error', title: 'Connection error', text: 'Could not reach the server.' });
        });
    }

    function closeCustView() { document.getElementById('custViewModal').classList.remove('active'); }
    document.getElementById('custViewModal').addEventListener('click', function(e) {
        if (e.target === this) closeCustView();
    });

    // tab switch — a table built inside a hidden pane measures 0px, so re-measure on show
    function cvShowTab(t) {
        $('#cvTabs .vtab-btn').removeClass('active').filter('[data-vtab="' + t + '"]').addClass('active');
        $('#cvContent .vtab-pane').removeClass('active');
        $('#cvpane-' + t).addClass('active');
        const dt = cvTables[t];
        if (dt) { dt.columns.adjust(); dt.responsive && dt.responsive.recalc(); }
    }
    $(document).on('click', '#cvTabs .vtab-btn', function() { cvShowTab($(this).data('vtab')); });

    // one builder for all four tabs — 10/page, exports, responsive (never scrollX alongside it)
    function cvTable(key, sel, data, columns, order) {
        if (cvTables[key]) { cvTables[key].destroy(); $(sel).empty(); }
        $('#cvcount-' + key).text(data.length);
        cvTables[key] = $(sel).DataTable({
            data: data,
            columns: columns,
            order: order || [],
            pageLength: 10,
            lengthChange: false,
            responsive: true,
            autoWidth: false,
            dom: 'Bfrtip',
            buttons: [
                { extend: 'csv',   text: '<i class="fas fa-file-csv"></i> CSV',   className: 'btn btn-secondary btn-sm' },
                { extend: 'pdf',   text: '<i class="fas fa-file-pdf"></i> PDF',   className: 'btn btn-secondary btn-sm' },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print',    className: 'btn btn-secondary btn-sm' }
            ],
            language: { emptyTable: 'Nothing recorded yet.', search: '', searchPlaceholder: 'Search…', zeroRecords: 'No match.' }
        });
        return cvTables[key];
    }

    // sort on the raw value, show the pretty one — never sort by formatted markup
    const cvDate  = (d, t) => t === 'display' ? (d ? fmtDate(d) : '<span class="muted">—</span>') : (d || '');
    const cvMoney = (d, t) => t === 'display' ? fmtMoney(d) : (Number(d) || 0);

    function renderCvProfile(c) {
        const dash = '<span class="muted">—</span>';
        const v = x => (x === null || x === undefined || x === '') ? dash : escapeHtml(x);
        const outAmt = Number(c.outstanding) || 0;
        const outCls = outAmt <= 0 ? 'zero' : (Number(c.overdue_count) > 0 ? 'overdue' : 'due');
        const field = (label, val) => `<div class="cv-field"><label>${label}</label><span>${val}</span></div>`;
        $('#cvProfile').html(`
            <div class="cv-id">
                <div class="cv-avatar"><i class="fas fa-user"></i></div>
                <div class="cv-id-text">
                    <strong>${escapeHtml(c.full_name)}</strong>
                    <span class="muted">${escapeHtml(c.customer_code)}</span>
                </div>
            </div>
            <div class="cv-outstanding">
                <label>Outstanding</label>
                <span class="outstanding-badge ${outCls}">${fmtMoney(outAmt)}</span>
            </div>
            ${field('Connection Status', `<span class="conn-status ${c.connection_status}">${escapeHtml(c.connection_status)}</span>`)}
            ${field('Package', c.package_name ? escapeHtml(c.package_name) + ' — ' + fmtMoney(c.package_price) : dash)}
            ${field('Phone', v(c.phone))}
            ${field('Alternate Phone', v(c.alternate_phone))}
            ${field('Email', v(c.email))}
            ${field('CNIC', v(c.cnic))}
            ${field('Area', v(c.area_name))}
            ${field('Address', v(c.address))}
            ${field('Installation Date', c.installation_date ? escapeHtml(fmtDate(c.installation_date)) : dash)}
            ${field('Last Payment', c.last_payment_date ? escapeHtml(fmtDate(c.last_payment_date)) : dash)}
            ${field('Created', c.created_at ? escapeHtml(fmtDateTime(c.created_at)) : dash)}
        `);
    }

    function renderCvInvoices(invs) {
        cvTable('invoices', '#cvInvTable', invs, [
            { data: 'invoice_number', title: 'Invoice #', render: d => escapeHtml(d) },
            { data: 'billing_month',  title: 'Month',     render: d => escapeHtml(d || '') },
            { data: 'total',          title: 'Total',     className: 'num-cell', render: cvMoney },
            { data: 'paid_amount',    title: 'Paid',      className: 'num-cell', render: cvMoney },
            { data: 'balance_amount', title: 'Balance',   className: 'num-cell',
              render: function(d, t, row) {
                  const bal = Number(d) || 0;
                  if (t !== 'display') return bal;
                  const cls = bal <= 0 ? 'zero' : (row.status === 'overdue' ? 'overdue' : 'due');
                  return `<span class="outstanding-badge ${cls}">${fmtMoney(bal)}</span>`;
              }
            },
            { data: 'due_date', title: 'Due Date', render: cvDate },
            { data: 'status',   title: 'Status',   render: (d, t) => t === 'display' ? `<span class="conn-status ${d}">${escapeHtml(d)}</span>` : d }
        ], [[1, 'desc']]);
    }

    function renderCvPayments(pays) {
        cvTable('payments', '#cvPayTable', pays, [
            { data: 'receipt_number', title: 'Receipt #', render: d => escapeHtml(d) },
            { data: 'payment_date',   title: 'Date',      render: cvDate },
            { data: 'amount',         title: 'Amount',    className: 'num-cell', render: cvMoney },
            { data: 'payment_method', title: 'Method',    render: d => escapeHtml((d || '').replace('_', ' ')) },
            { data: 'collector',      title: 'Collected By', render: d => escapeHtml(d || '') }
        ], [[1, 'desc']]);
    }

    function renderCvComplaints(cmps) {
        cvTable('complaints', '#cvCmpTable', cmps, [
            { data: 'ticket_number', title: 'Ticket #', render: d => escapeHtml(d) },
            { data: 'subject',       title: 'Subject',  render: d => escapeHtml(d || '') },
            { data: 'priority',      title: 'Priority', render: (d, t) => t === 'display' ? `<span class="priority-badge ${d}">${escapeHtml(d)}</span>` : d },
            { data: 'status',        title: 'Status',   render: (d, t) => t === 'display' ? `<span class="cmp-status ${d}">${escapeHtml((d || '').replace('_', ' '))}</span>` : d },
            { data: 'created_at',    title: 'Created',  render: cvDate }
        ], [[4, 'desc']]);
    }

    // invoices are debits, payments are credits — running balance in date order
    function renderCvLedger(invs, pays) {
        const rows = [];
        invs.forEach(i => rows.push({
            // the accounting date is when it was issued — created_at is just when the row was written
            date: i.issue_date || i.due_date || i.created_at, ref: i.invoice_number,
            label: 'Invoice ' + (i.billing_month || ''), debit: Number(i.total) || 0, credit: 0
        }));
        pays.forEach(p => rows.push({
            date: p.payment_date, ref: p.receipt_number,
            label: 'Payment (' + (p.payment_method || '').replace('_', ' ') + ')', debit: 0, credit: Number(p.amount) || 0
        }));
        rows.sort((a, b) => new Date(a.date || 0) - new Date(b.date || 0));
        let bal = 0;
        rows.forEach(r => { bal += r.debit - r.credit; r.balance = bal; });

        cvTable('ledger', '#cvLedTable', rows, [
            { data: 'date',    title: 'Date',      render: cvDate },
            { data: 'ref',     title: 'Reference', render: d => escapeHtml(d || '—') },
            { data: 'label',   title: 'Description', render: d => escapeHtml(d) },
            { data: 'debit',   title: 'Debit',   className: 'num-cell ledger-debit',   render: (d, t) => t === 'display' ? (d ? fmtMoney(d) : '—') : (Number(d) || 0) },
            { data: 'credit',  title: 'Credit',  className: 'num-cell ledger-credit',  render: (d, t) => t === 'display' ? (d ? fmtMoney(d) : '—') : (Number(d) || 0) },
            { data: 'balance', title: 'Balance', className: 'num-cell ledger-balance', render: cvMoney }
        ], [[0, 'asc']]);
    }

    // render full server-side ledger (entries already sorted + running balance computed)
    function buildLedgerHtmlFromEntries(entries) {
        if (!entries || !entries.length) {
            return '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-book"></i></div><div class="empty-state-title">No ledger activity</div><div class="empty-state-text">Invoices and payments will appear here.</div></div>';
        }
        let totDebit = 0, totCredit = 0;
        let h = '<table class="ledger-table"><thead><tr><th>Date</th><th>Reference</th><th>Description</th><th class="num-cell">Debit</th><th class="num-cell">Credit</th><th class="num-cell">Balance</th></tr></thead><tbody>';
        entries.forEach(r => {
            totDebit  += Number(r.debit) || 0;
            totCredit += Number(r.credit) || 0;
            h += `<tr>
                <td>${fmtDate(r.date)}</td>
                <td>${escapeHtml(r.ref || '—')}</td>
                <td>${escapeHtml(r.description || '')}</td>
                <td class="num-cell ledger-debit">${r.debit ? fmtMoney(r.debit) : '—'}</td>
                <td class="num-cell ledger-credit">${r.credit ? fmtMoney(r.credit) : '—'}</td>
                <td class="num-cell ledger-balance">${fmtMoney(r.running_balance)}</td>
            </tr>`;
        });
        const closing = entries[entries.length - 1].running_balance;
        h += `</tbody><tfoot>
            <tr><th colspan="3" class="dt-foot-label">Totals</th>
                <th class="num-cell ledger-debit">${fmtMoney(totDebit)}</th>
                <th class="num-cell ledger-credit">${fmtMoney(totCredit)}</th>
                <th class="num-cell ledger-balance">${fmtMoney(closing)}</th></tr>
            <tr><th colspan="5" class="dt-foot-label">Closing Balance</th>
                <th class="num-cell ledger-balance">${fmtMoney(closing)}</th></tr>
        </tfoot></table>`;
        return h;
    }

    // ─── STANDALONE LEDGER MODAL ─────────────────────
    let clData = null;

    // A4 statement — fills the print-only sheet from the loaded ledger, then hands it to the browser
    function printLedger() {
        if (!clData || !clData.ledger.length) {
            Swal.fire({ icon: 'info', title: 'Nothing to print', text: 'This customer has no ledger activity yet.' });
            return;
        }
        const c = clData.customer, rows = clData.ledger;
        const dash = v => v ? escapeHtml(v) : '—';
        $('#lpName').text(c.full_name || '—');
        $('#lpCode').text(c.customer_code || '—');
        $('#lpPhone').text(c.phone || '—');
        $('#lpArea').text([c.area_name, c.package_name].filter(Boolean).join(' / ') || '—');
        $('#lpAddress').text(c.address || '—');
        $('#lpDate').text(new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }));

        let dr = 0, cr = 0;
        $('#lpBody').html(rows.map(r => {
            dr += Number(r.debit) || 0; cr += Number(r.credit) || 0;
            return `<tr>
                <td>${fmtDate(r.date)}</td>
                <td>${dash(r.ref)}</td>
                <td>${escapeHtml(r.description || '')}</td>
                <td class="num-cell">${r.debit  ? fmtMoney(r.debit)  : '—'}</td>
                <td class="num-cell">${r.credit ? fmtMoney(r.credit) : '—'}</td>
                <td class="num-cell">${fmtMoney(r.running_balance)}</td>
            </tr>`;
        }).join(''));

        const closing = Number(rows[rows.length - 1].running_balance) || 0;
        $('#lpFoot').html(`<tr>
                <th colspan="3">Totals — ${rows.length} entr${rows.length === 1 ? 'y' : 'ies'}</th>
                <th class="num-cell">${fmtMoney(dr)}</th>
                <th class="num-cell">${fmtMoney(cr)}</th>
                <th class="num-cell">${fmtMoney(closing)}</th>
            </tr>
            <tr class="sheet-total"><th colspan="5">Closing Balance</th><th class="num-cell">${fmtMoney(closing)}</th></tr>`);
        $('#lpNote').text(closing > 0 ? 'Closing balance is receivable from the customer.'
                        : closing < 0 ? 'Closing balance is an advance held for the customer.'
                        : 'Account is fully settled — nothing outstanding.');
        window.print();
    }

    function openLedger(id) {
        $('#clContent').addClass('initially-hidden').empty();
        $('#clSkeleton').show();
        $('#clTitle').html('<i class="fas fa-book"></i> Customer Ledger');
        document.getElementById('custLedgerModal').classList.add('active');

        // full unbounded ledger — dedicated endpoint, no LIMIT 25
        $.post('?action=getCustomerLedger', { customer_id: id }, function(resp) {
            $('#clSkeleton').hide();
            $('#clContent').removeClass('initially-hidden');
            if (!resp.success) {
                $('#clContent').html(`<div class="empty-state"><div class="empty-state-title">${escapeHtml(resp.message || 'Failed to load')}</div></div>`);
                return;
            }
            const c = resp.customer;
            clData = { customer: c, ledger: resp.ledger || [] };   // print reuses what the modal already fetched
            $('#clTitle').html(`<i class="fas fa-book"></i> Ledger — ${escapeHtml(c.full_name)} <span class="muted">(${escapeHtml(c.customer_code)})</span>`);
            $('#clContent').html(buildLedgerHtmlFromEntries(resp.ledger || []));
        }, 'json').fail(() => {
            $('#clSkeleton').hide();
            $('#clContent').removeClass('initially-hidden').html('<div class="empty-state"><div class="empty-state-title">Connection error</div></div>');
        });
    }

    function closeLedger() { document.getElementById('custLedgerModal').classList.remove('active'); }
    document.getElementById('custLedgerModal').addEventListener('click', function(e) {
        if (e.target === this) closeLedger();
    });

    function loadCustomers() {
        $('#listSkeleton').show();
        $('#tableWrap, #emptyState, #filtersSection, #chevronPipeline').hide();

        return $.getJSON('?action=getCustomers').done(function(resp) {
            $('#listSkeleton').hide();
            if (!resp.success) {
                Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed to load' });
                return;
            }
            customersData = resp.data;
            refreshChevronCounts(customersData);
            $('#chevronPipeline').removeClass('initially-hidden').show();
            if (customersData.length === 0) {
                $('#emptyState').show();
            } else {
                $('#filtersSection').show();
                $('#tableWrap').show();
                initTable(customersData);
            }
        }).fail(() => {
            $('#listSkeleton').hide();
            Swal.fire({ icon:'error', title:'Error', text:'Connection error' });
        });
    }

    function initTable(data) {
        if (custTable) { custTable.destroy(); $('#custTable').empty(); }
        setTimeout(() => {
            custTable = $('#custTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'customer_code', title: 'Code',
                      render: function(d, t, row) {
                          return `<a href="customers.php?id=${row.id}" style="font-weight:700;color:#0074D9">${escapeHtml(d)}</a>`;
                      }
                    },
                    { data: 'full_name', title: 'Name', render: d => escapeHtml(d) },
                    { data: 'area_name', title: 'Area', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'package_name', title: 'Package', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'phone', title: 'Phone', render: d => escapeHtml(d) },
                    { data: 'connection_status', title: 'Status', render: d => `<span class="conn-status ${d}">${d}</span>` },
                    { data: 'outstanding', title: 'Outstanding',
                      render: function(d, t, row) {
                          const amt = Number(d) || 0;
                          const cls = amt <= 0 ? 'zero' : (Number(row.overdue_count) > 0 ? 'overdue' : 'due');
                          const tip = Number(row.overdue_count) > 0
                                      ? `${row.overdue_count} overdue invoice(s) — collect now`
                                      : (amt > 0 ? `${row.unpaid_count} unpaid invoice(s)` : 'All bills paid');
                          return `<span class="outstanding-badge ${cls}" title="${tip}">${fmtMoney(amt)}</span>`;
                      } },
                    { data: 'last_payment_date', title: 'Last Payment',
                      render: function(d, t) {
                          // sort on raw iso, display formatted
                          if (t === 'sort' || t === 'type') return d || '';
                          return d ? escapeHtml(fmtDate(d)) : '<span class="muted">—</span>';
                      } },
                    { data: null, title: 'Actions', orderable: false,
                      render: function(d, t, row) {
                          const rj = encodeURIComponent(JSON.stringify(row));
                          // [View] [Ledger] [Record Payment] [⋮ More]
                          let h = `<button class="action-icon view-icon" onclick="viewCustomerModal(${row.id})" title="View"><i class="fas fa-eye"></i></button>`;
                          h += ` <button class="action-icon" onclick="openLedger(${row.id})" title="Ledger"><i class="fas fa-book"></i></button>`;
                          h += ` <a href="payments.php?customer_id=${row.id}" class="action-icon" title="Record Payment"><i class="fas fa-money-bill-wave"></i></a>`;
                          h += `<div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">`;
                          if (CAN_EDIT) {
                              h += `<button class="act-more-item" onclick='editCustomer(${JSON.stringify(row)})'><i class="fas fa-edit"></i> Edit</button>`;
                          }
                          h += `<a class="act-more-item" href="invoices.php?customer_id=${row.id}"><i class="fas fa-file-invoice"></i> Generate Invoice</a>`;
                          // toggle suspend/activate — collector enforced server-side by area
                          const tIcon = row.connection_status === 'active' ? 'fa-pause' : 'fa-play';
                          const tLabel = row.connection_status === 'active' ? 'Suspend' : 'Activate';
                          h += `<button class="act-more-item" onclick="toggleConn(${row.id})"><i class="fas ${tIcon}"></i> ${tLabel}</button>`;
                          if (CAN_DELETE) {
                              h += `<button class="act-more-item danger" onclick="deleteCustomer(${row.id}, '${escapeHtml(row.customer_code)}')"><i class="fas fa-trash"></i> Delete</button>`;
                          }
                          h += `</div></div>`;
                          return h;
                      }
                    }
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv',   text: '<i class="fas fa-file-csv"></i> CSV',   exportOptions: { columns: ':not(:last-child)' } },
                    { extend: 'pdf',   text: '<i class="fas fa-file-pdf"></i> PDF',   exportOptions: { columns: ':not(:last-child)' }, orientation: 'landscape', pageSize: 'A4' },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print',    exportOptions: { columns: ':not(:last-child)' } }
                ],
                order: [[0, 'asc']],
                // overdue rows -> red tint
                createdRow: function(tr, rowData) {
                    if (Number(rowData.overdue_count) > 0) $(tr).addClass('row-overdue');
                },
                // footer totals — reflects current filter
                footerCallback: function() {
                    const api = this.api();
                    let count = 0, outstanding = 0, overdue = 0;
                    api.rows({ search: 'applied' }).data().each(function(r) {
                        count++;
                        outstanding += Number(r.outstanding) || 0;
                        if (Number(r.overdue_count) > 0) overdue++;
                    });
                    $('#footTotalCount').text(count);
                    $('#footOutstanding').text(fmtMoney(outstanding))
                        .toggleClass('neg', outstanding > 0).toggleClass('pos', outstanding <= 0);
                    $('#footOverdue').text('Overdue: ' + overdue);
                }
            });
        }, 100);
    }

    function applyFilters() {
        if (!custTable) return;
        $.fn.dataTable.ext.search = [];
        const area = document.getElementById('filterArea').value;
        const pkg  = document.getElementById('filterPkg').value;
        const term = (document.getElementById('filterSearch').value || '').toLowerCase().trim();

        $.fn.dataTable.ext.search.push(function(s, d, idx) {
            const r = customersData[idx];
            if (!r) return true;
            if (area && String(r.area_id)    !== String(area)) return false;
            if (pkg  && String(r.package_id) !== String(pkg))  return false;
            if (chevronStatus !== 'all' && r.connection_status !== chevronStatus) return false;
            if (term) {
                const hay = (r.customer_code + ' ' + r.full_name + ' ' + r.phone + ' ' + (r.alternate_phone || '')).toLowerCase();
                if (!hay.includes(term)) return false;
            }
            return true;
        });
        custTable.draw();
    }

    function clearFilters() {
        ['filterArea','filterPkg'].forEach(id => {
            const el = document.getElementById(id);
            el.value = '';
            el._sd && el._sd.refresh();
        });
        document.getElementById('filterSearch').value = '';
        chevronStatus = 'all';
        $('.chevron-tab').removeClass('active');
        $('.chevron-tab[data-status="all"]').addClass('active');
        if (custTable) { $.fn.dataTable.ext.search = []; custTable.draw(); }
    }

    // ─── ADD / EDIT MODAL ────────────────────────────
    // ids that get cleared on add (text/number/date inputs)
    const _clearTextIds = [
        'alternate_phone','father_name','gps_lat','gps_lng','vlan_id','router_serial',
        'pppoe_username','pppoe_password','nas_id','static_ip_address','subnet_mask','gateway',
        'dns1','dns2','olt_id','olt_port','pon_port','splitter_id','splitter_port',
        'ont_serial','ont_loid','signal_strength_dbm','bandwidth_profile_name','cable_length_meters',
        'business_name','tax_id','ntn_number','gst_number','auto_pay_method',
        'dob','nearest_landmark','whatsapp_number',
        'agreement_pdf_path','agreement_start_date','agreement_end_date'
    ];

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add Customer';
        document.getElementById('custForm').reset();
        document.getElementById('custId').value = '';
        document.getElementById('customer_code').value = '';
        document.getElementById('customer_code').placeholder = 'Auto-generated on save';

        ensureModalDropdowns().then(() => {
            // defaults
            setSDValue('connection_status', 'active');
            setSDValue('connection_type',   'pppoe');
            setSDValue('connection_medium', 'fiber');
            setSDValue('customer_type',     'residential');
            setSDValue('vip_status',        'regular');
            setSDValue('gender',            '');
            setSDValue('acquisition_source','');
            setSDValue('referred_by_customer_id','');
            setSDValue('salesperson_user_id','');
            setSDValue('installation_user_id','');

            document.getElementById('monthly_due_day').value = 1;
            document.getElementById('installation_date').value = new Date().toISOString().slice(0,10);
            // financial defaults
            document.getElementById('registration_fee').value = 0;
            document.getElementById('security_deposit').value = 0;
            document.getElementById('discount_pct').value = 0;
            document.getElementById('credit_limit').value = 0;
            document.getElementById('contract_period_months').value = 0;
            document.getElementById('early_termination_fee').value = 0;
            document.getElementById('nationality').value = 'Pakistan';
            // toggles
            document.getElementById('billing_email_optin').checked = true;
            document.getElementById('whatsapp_optin').checked = true;
            document.getElementById('mac_binding').checked = false;
            document.getElementById('tax_exempt').checked = false;
            document.getElementById('auto_pay_enabled').checked = false;
            document.getElementById('agreement_signed').checked = false;
            // clear text fields
            _clearTextIds.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
            // toggle business name / static IP fields visibility
            toggleBusinessNameVis();
            toggleStaticOnlyVis();
            document.getElementById('custModal').classList.add('active');
        });
    }

    function setSDValue(id, val) {
        const el = document.getElementById(id);
        if (!el) return;
        el.value = val;
        el._sd && el._sd.refresh();
    }

    function editCustomer(row) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Customer';
        document.getElementById('custId').value = row.id;
        document.getElementById('customer_code').value = row.customer_code;
        document.getElementById('full_name').value = row.full_name || '';
        document.getElementById('phone').value = row.phone || '';
        document.getElementById('email').value = row.email || '';
        document.getElementById('cnic').value = row.cnic || '';
        // fetch full detail
        $.getJSON('?action=getCustomerDetail&id=' + row.id, function(resp) {
            if (!resp.success) {
                Swal.fire({ icon:'error', title:'Error', text: resp.message });
                return;
            }
            const c = resp.customer;
            // base
            document.getElementById('address').value = c.address || '';
            document.getElementById('installation_date').value = c.installation_date || '';
            document.getElementById('monthly_due_day').value = c.monthly_due_day || 1;
            document.getElementById('ip_address').value = c.ip_address || '';
            document.getElementById('mac_address').value = c.mac_address || '';
            document.getElementById('notes').value = c.notes || '';
            document.getElementById('alternate_phone').value = c.alternate_phone || '';
            document.getElementById('father_name').value     = c.father_name || '';
            document.getElementById('gps_lat').value         = c.gps_lat != null ? c.gps_lat : '';
            document.getElementById('gps_lng').value         = c.gps_lng != null ? c.gps_lng : '';
            document.getElementById('vlan_id').value         = c.vlan_id != null ? c.vlan_id : '';
            document.getElementById('router_serial').value   = c.router_serial || '';
            document.getElementById('registration_fee').value = Number(c.registration_fee || 0);
            document.getElementById('security_deposit').value = Number(c.security_deposit || 0);
            document.getElementById('discount_pct').value     = Number(c.discount_pct || 0);
            document.getElementById('billing_email_optin').checked = !!Number(c.billing_email_optin);
            document.getElementById('whatsapp_optin').checked      = !!Number(c.whatsapp_optin);

            // tier-2 network
            document.getElementById('pppoe_username').value         = c.pppoe_username || '';
            document.getElementById('pppoe_password').value         = c.pppoe_password || '';
            document.getElementById('nas_id').value                 = c.nas_id != null ? c.nas_id : '';
            document.getElementById('static_ip_address').value      = c.static_ip_address || '';
            document.getElementById('subnet_mask').value            = c.subnet_mask || '';
            document.getElementById('gateway').value                = c.gateway || '';
            document.getElementById('dns1').value                   = c.dns1 || '';
            document.getElementById('dns2').value                   = c.dns2 || '';
            document.getElementById('olt_id').value                 = c.olt_id != null ? c.olt_id : '';
            document.getElementById('olt_port').value               = c.olt_port || '';
            document.getElementById('pon_port').value               = c.pon_port || '';
            document.getElementById('splitter_id').value            = c.splitter_id != null ? c.splitter_id : '';
            document.getElementById('splitter_port').value          = c.splitter_port || '';
            document.getElementById('ont_serial').value             = c.ont_serial || '';
            document.getElementById('ont_loid').value               = c.ont_loid || '';
            document.getElementById('signal_strength_dbm').value    = c.signal_strength_dbm != null ? c.signal_strength_dbm : '';
            document.getElementById('bandwidth_profile_name').value = c.bandwidth_profile_name || '';
            document.getElementById('mac_binding').checked          = !!Number(c.mac_binding);
            document.getElementById('cable_length_meters').value    = c.cable_length_meters != null ? c.cable_length_meters : '';

            // tier-2 billing/tax
            document.getElementById('business_name').value     = c.business_name || '';
            document.getElementById('tax_id').value            = c.tax_id || '';
            document.getElementById('ntn_number').value        = c.ntn_number || '';
            document.getElementById('gst_number').value        = c.gst_number || '';
            document.getElementById('tax_exempt').checked      = !!Number(c.tax_exempt);
            document.getElementById('credit_limit').value      = Number(c.credit_limit || 0);
            document.getElementById('auto_pay_enabled').checked= !!Number(c.auto_pay_enabled);
            document.getElementById('auto_pay_method').value   = c.auto_pay_method || '';

            // tier-2 kyc
            document.getElementById('dob').value              = c.dob || '';
            document.getElementById('nationality').value      = c.nationality || 'Pakistan';
            document.getElementById('nearest_landmark').value = c.nearest_landmark || '';
            document.getElementById('whatsapp_number').value  = c.whatsapp_number || '';

            // tier-2 lifecycle
            document.getElementById('agreement_signed').checked     = !!Number(c.agreement_signed);
            document.getElementById('agreement_pdf_path').value     = c.agreement_pdf_path || '';
            document.getElementById('agreement_start_date').value   = c.agreement_start_date || '';
            document.getElementById('agreement_end_date').value     = c.agreement_end_date || '';
            document.getElementById('contract_period_months').value = Number(c.contract_period_months || 0);
            document.getElementById('early_termination_fee').value  = Number(c.early_termination_fee || 0);

            ensureModalDropdowns().then(() => {
                setSDValue('area_id',                c.area_id);
                setSDValue('package_id',             c.package_id);
                setSDValue('connection_status',      c.connection_status);
                setSDValue('connection_type',        c.connection_type || 'pppoe');
                setSDValue('connection_medium',      c.connection_medium || 'fiber');
                setSDValue('customer_type',          c.customer_type || 'residential');
                setSDValue('vip_status',             c.vip_status || 'regular');
                setSDValue('gender',                 c.gender || '');
                setSDValue('acquisition_source',     c.acquisition_source || '');
                setSDValue('salesperson_user_id',    c.salesperson_user_id != null ? c.salesperson_user_id : '');
                setSDValue('installation_user_id',   c.installation_user_id != null ? c.installation_user_id : '');
                setSDValue('referred_by_customer_id',c.referred_by_customer_id != null ? c.referred_by_customer_id : '');
                toggleBusinessNameVis();
                toggleStaticOnlyVis();
                document.getElementById('custModal').classList.add('active');
            });
        });
    }

    // show/hide business_name based on customer_type
    function toggleBusinessNameVis() {
        const ct = document.getElementById('customer_type').value;
        const grp = document.getElementById('grpBusinessName');
        if (!grp) return;
        if (ct === 'residential') grp.classList.add('initially-hidden');
        else grp.classList.remove('initially-hidden');
    }

    // show static-only fields only when connection_type=static
    function toggleStaticOnlyVis() {
        const ct = (document.getElementById('connection_type').value || 'pppoe');
        const isStatic = ct === 'static';
        document.querySelectorAll('.form-group.static-only').forEach(g => {
            if (isStatic) g.classList.remove('initially-hidden');
            else g.classList.add('initially-hidden');
        });
    }

    let modalDropdownsBuilt = false;
    function ensureModalDropdowns() {
        return new Promise(resolve => {
            // ensure caches loaded
            const need = (areasCache.length === 0 || packagesCache.length === 0 || staffCache.length === 0)
                ? Promise.all([
                    $.getJSON('?action=getAreas'),
                    $.getJSON('?action=getPackages'),
                    $.getJSON('?action=getStaff'),
                    $.getJSON('?action=getReferralCustomers')
                  ]).then(([a,p,s,rc]) => {
                    areasCache             = a.success ? a.data : [];
                    packagesCache          = p.success ? p.data : [];
                    staffCache             = s.success ? s.data : [];
                    referralCustomersCache = rc.success ? rc.data : [];
                  })
                : Promise.resolve();

            need.then(() => {
                // populate selects
                const aEl = document.getElementById('area_id');
                const pEl = document.getElementById('package_id');
                const sEl = document.getElementById('salesperson_user_id');
                const iEl = document.getElementById('installation_user_id');
                const rEl = document.getElementById('referred_by_customer_id');

                aEl.innerHTML = '<option value="">Select area...</option>';
                areasCache.forEach(a => aEl.add(new Option(a.name, a.id)));
                pEl.innerHTML = '<option value="">Select package...</option>';
                packagesCache.forEach(p => pEl.add(new Option(`${p.name} (${CCY} ${p.price.toFixed(0)} / ${p.speed_mbps}Mbps)`, p.id)));
                sEl.innerHTML = '<option value="">— Unassigned —</option>';
                staffCache.forEach(u => sEl.add(new Option(`${u.name} (${u.role.replace('_',' ')})`, u.id)));
                iEl.innerHTML = '<option value="">— Unassigned —</option>';
                staffCache.forEach(u => iEl.add(new Option(`${u.name} (${u.role.replace('_',' ')})`, u.id)));
                rEl.innerHTML = '<option value="">— None —</option>';
                referralCustomersCache.forEach(c => rEl.add(new Option(`${c.customer_code} — ${c.full_name}`, c.id)));

                if (!modalDropdownsBuilt) {
                    new SearchableDropdown({ selectId: 'area_id',                placeholder: 'Select area...',    icon: 'fas fa-map-marked-alt', allowClear: false });
                    new SearchableDropdown({ selectId: 'package_id',             placeholder: 'Select package...', icon: 'fas fa-box',            allowClear: false });
                    new SearchableDropdown({ selectId: 'connection_status',      placeholder: 'Status...',         icon: 'fas fa-plug',           allowClear: false });
                    new SearchableDropdown({ selectId: 'connection_type',        placeholder: 'Type...',           icon: 'fas fa-cogs',           allowClear: false });
                    new SearchableDropdown({ selectId: 'connection_medium',      placeholder: 'Medium...',         icon: 'fas fa-broadcast-tower', allowClear: false });
                    new SearchableDropdown({ selectId: 'customer_type',          placeholder: 'Type...',           icon: 'fas fa-user-tag',       allowClear: false });
                    new SearchableDropdown({ selectId: 'vip_status',             placeholder: 'VIP...',            icon: 'fas fa-crown',          allowClear: false });
                    new SearchableDropdown({ selectId: 'gender',                 placeholder: 'Gender...',         icon: 'fas fa-venus-mars' });
                    new SearchableDropdown({ selectId: 'acquisition_source',     placeholder: 'Source...',         icon: 'fas fa-bullhorn' });
                    new SearchableDropdown({ selectId: 'salesperson_user_id',    placeholder: '— Unassigned —',    icon: 'fas fa-user-tie' });
                    new SearchableDropdown({ selectId: 'installation_user_id',   placeholder: '— Unassigned —',    icon: 'fas fa-tools' });
                    new SearchableDropdown({ selectId: 'referred_by_customer_id',placeholder: '— None —',          icon: 'fas fa-user-friends' });

                    // wire change handlers for cascading visibility
                    $('#customer_type').on('change', toggleBusinessNameVis);
                    $('#connection_type').on('change', toggleStaticOnlyVis);
                    modalDropdownsBuilt = true;
                } else {
                    [aEl, pEl, sEl, iEl, rEl].forEach(el => el && el._sd && el._sd.refresh());
                }
                resolve();
            });
        });
    }

    function closeModal() {
        document.getElementById('custModal').classList.remove('active');
        document.getElementById('custForm').reset();
    }

    document.getElementById('custModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // geolocation fill
    document.addEventListener('click', function(e) {
        const a = e.target.closest('#useMyLocation');
        if (!a) return;
        e.preventDefault();
        if (!navigator.geolocation) {
            Swal.fire({ icon:'warning', title:'Unsupported', text:'Geolocation not available in this browser' });
            return;
        }
        Swal.fire({ title:'Locating...', allowOutsideClick:false, didOpen:() => Swal.showLoading() });
        navigator.geolocation.getCurrentPosition(
            pos => {
                document.getElementById('gps_lat').value = pos.coords.latitude.toFixed(7);
                document.getElementById('gps_lng').value = pos.coords.longitude.toFixed(7);
                Swal.close();
            },
            err => Swal.fire({ icon:'error', title:'Could not get location',
                text: err.code === 1 ? 'Location permission is blocked. Allow it for this site in the browser address bar, then try again.'
                    : err.code === 3 ? 'The GPS did not answer in time. Try again near a window or outdoors.'
                    : (location.protocol === 'https:' || location.hostname === 'localhost'
                       ? (err.message || 'Your device could not fix a position right now.')
                       : 'GPS needs a secure (https) connection on this device.') }),
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    });

    document.getElementById('custForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const action = isEditMode ? 'updateCustomer' : 'addCustomer';
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.ajax({
            url: '?action=' + action, method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon:'success', title:'Saved!', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeModal();
                    setTimeout(() => loadCustomers(), 200);
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
        });
    });

    function toggleConn(id) {
        // cycle: active -> suspended -> disconnected -> active
        const row = customersData.find(r => r.id === id) || (detailCustomer && detailCustomer.id === id ? detailCustomer : null);
        const cur = row ? row.connection_status : 'active';
        const next = cur === 'active' ? 'suspended' : (cur === 'suspended' ? 'disconnected' : 'active');

        const sendIt = (reason) => {
            const fd = new FormData();
            fd.append('id', id);
            fd.append('target_status', next);
            if (reason) fd.append('reason', reason);
            $.ajax({
                url: '?action=toggleConnection', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', title:'Status: ' + resp.status, timer: 1500, showConfirmButton: false });
                        if (DETAIL_ID > 0) loadDetail(); else loadCustomers();
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: () => Swal.fire({ icon:'error', title:'Error', text:'Connection error' })
            });
        };

        if (next === 'active') {
            sendIt('');
            return;
        }
        // ask reason for suspend / disconnect
        const lbl = next === 'suspended' ? 'Suspension' : 'Disconnection';
        Swal.fire({
            title: lbl + ' reason',
            input: 'text',
            inputPlaceholder: 'e.g. Non-payment',
            inputAttributes: { maxlength: 255 },
            showCancelButton: true,
            confirmButtonText: 'Confirm ' + (next === 'suspended' ? 'Suspend' : 'Disconnect'),
            confirmButtonColor: next === 'suspended' ? '#f59e0b' : '#ef4444',
            inputValidator: v => (!v || v.trim() === '') ? 'Reason is required' : undefined
        }).then(r => {
            if (r.isConfirmed) sendIt(r.value.trim());
        });
    }

    function deleteCustomer(id, code) {
        Swal.fire({
            icon: 'warning', title: 'Delete ' + code + '?',
            text: 'This will soft-delete the customer record. Invoices and payments stay intact.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteCustomer', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        loadCustomers();
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                }
            });
        });
    }

    // ─── DETAIL VIEW ─────────────────────────────────
    function loadDetail() {
        $.getJSON('?action=getCustomerDetail&id=' + DETAIL_ID, function(resp) {
            $('#detailSkeleton').hide();
            if (!resp.success) {
                Swal.fire({ icon:'error', title:'Error', text: resp.message }).then(() => location.href = 'customers.php');
                return;
            }
            detailCustomer = resp.customer;
            renderDetail(resp);
            $('#detailGrid').show();
        }).fail(() => {
            $('#detailSkeleton').hide();
            Swal.fire({ icon:'error', title:'Error', text:'Failed to load customer' });
        });
    }

    function renderDetail(resp) {
        const c = resp.customer;
        $('#bcCustCode').text(c.customer_code);
        $('#custTitle').text(c.full_name + ' — ' + c.customer_code);
        $('#dCode').text(c.customer_code);
        $('#dName').text(c.full_name);
        $('#dFather').text(c.father_name || '—');
        $('#dPhone').text(c.phone);
        $('#dAltPhone').text(c.alternate_phone || '—');
        $('#dEmail').text(c.email || '—');
        $('#dCnic').text(c.cnic || '—');
        $('#dConn').html(`<span class="conn-status ${c.connection_status}">${c.connection_status}</span>`);
        const outAmt = Number(c.outstanding) || 0;
        const outCls = outAmt <= 0 ? 'zero' : (Number(c.overdue_count) > 0 ? 'overdue' : 'due');
        $('#dOutstanding').html(`<span class="outstanding-badge ${outCls}">${fmtMoney(outAmt)}</span>`);
        $('#dArea').text(c.area_name || '—');
        $('#dPkg').text(c.package_name ? `${c.package_name} — ${fmtMoney(c.package_price)}` : '—');
        $('#dInstall').text(fmtDate(c.installation_date));
        $('#dDueDay').text(c.monthly_due_day);
        $('#dIp').text(c.ip_address || '—');
        $('#dMac').text(c.mac_address || '—');
        $('#dConnType').text((c.connection_type || 'pppoe').toUpperCase());
        $('#dVlan').text(c.vlan_id != null ? c.vlan_id : '—');
        $('#dRouter').text(c.router_serial || '—');
        $('#dLastPay').text(c.last_payment_date ? fmtDate(c.last_payment_date) : '—');
        $('#dAddress').text(c.address || '—');

        // gps map link
        if (c.gps_lat != null && c.gps_lng != null && c.gps_lat !== '' && c.gps_lng !== '') {
            const url = `https://www.google.com/maps?q=${c.gps_lat},${c.gps_lng}`;
            $('#dGps').html(`<a class="gps-link" target="_blank" href="${url}"><i class="fas fa-map-pin"></i> Open in Maps</a>`);
            $('#dGpsRow').show();
        } else {
            $('#dGpsRow').hide();
        }

        // financial
        $('#dRegFee').text(fmtMoney(c.registration_fee));
        $('#dSecDep').text(fmtMoney(c.security_deposit));
        $('#dDiscPct').text(Number(c.discount_pct || 0).toFixed(2) + ' %');
        $('#dBillOptin').html(Number(c.billing_email_optin) ? '<span class="conn-status active">Yes</span>' : '<span class="conn-status disconnected">No</span>');
        $('#dWaOptin').html(Number(c.whatsapp_optin) ? '<span class="conn-status active">Yes</span>' : '<span class="conn-status disconnected">No</span>');

        // suspension card
        if (c.connection_status === 'suspended' && (c.suspended_at || c.suspension_reason)) {
            $('#dSusAt').text(c.suspended_at ? fmtDateTime(c.suspended_at) : '—');
            $('#dSusReason').text(c.suspension_reason || '—');
            $('#dSusCard').show();
        } else {
            $('#dSusCard').hide();
        }
        // disconnection card
        if (c.connection_status === 'disconnected' && (c.disconnected_at || c.disconnect_reason)) {
            $('#dDisAt').text(c.disconnected_at ? fmtDateTime(c.disconnected_at) : '—');
            $('#dDisReason').text(c.disconnect_reason || '—');
            $('#dDisCard').show();
        } else {
            $('#dDisCard').hide();
        }

        $('#dNotes').val(c.notes || '');

        // tabs content
        renderInvoicesTab(resp.invoices);
        renderPaymentsTab(resp.payments);
        renderComplaintsTab(resp.complaints);
        renderDocumentsTab(resp.documents || []);

        // wire tab switching — toggle the .initially-hidden class (CSS sets display:none
        // without !important; clearing inline display alone leaves the class active → blank tab)
        $('.detail-tab').off('click').on('click', function() {
            const t = $(this).data('tab');
            $('.detail-tab').removeClass('active');
            $(this).addClass('active');
            ['invoices','payments','complaints','documents','notes'].forEach(k => {
                const el = document.getElementById('tab-' + k);
                if (!el) return;
                el.classList.toggle('initially-hidden', k !== t);
                el.style.display = (k === t) ? '' : 'none';
            });
        });

        // inject Edit + Delete icon buttons into every detail card header
        injectCardActions();
    }

    function injectCardActions() {
        document.querySelectorAll('.detail-card h3').forEach(h3 => {
            if (h3.querySelector('.detail-card-actions')) return; // idempotent

            // wrap existing icon+text into .detail-card-title so flex space-between works
            if (!h3.querySelector('.detail-card-title')) {
                const titleSpan = document.createElement('span');
                titleSpan.className = 'detail-card-title';
                while (h3.firstChild) titleSpan.appendChild(h3.firstChild);
                h3.appendChild(titleSpan);
            }

            let btnHtml = '';
            if (CAN_EDIT)   btnHtml += '<button type="button" class="action-icon edit-icon" onclick="editFromDetail()" title="Edit"><i class="fas fa-edit"></i></button>';
            if (CAN_DELETE) btnHtml += '<button type="button" class="action-icon delete-icon" onclick="deleteFromDetail()" title="Delete"><i class="fas fa-trash"></i></button>';
            if (!btnHtml) return;

            const actions = document.createElement('span');
            actions.className = 'detail-card-actions';
            actions.innerHTML = btnHtml;
            h3.appendChild(actions);
        });
    }

    function deleteFromDetail() {
        if (!detailCustomer || !CAN_DELETE) return;
        Swal.fire({
            icon: 'warning',
            title: 'Delete ' + detailCustomer.customer_code + '?',
            text: 'This will soft-delete the customer record. Linked invoices and payments stay intact for accounting history.',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', detailCustomer.id);
            Swal.fire({ title: 'Deleting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.ajax({
                url: '?action=deleteCustomer', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({
                            icon: 'success', text: resp.message || 'Customer deleted',
                            timer: 1400, showConfirmButton: false
                        }).then(() => location.href = 'customers.php');
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Delete failed' });
                    }
                },
                error: () => Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' })
            });
        });
    }

    function fmtDateTime(s) {
        if (!s) return '—';
        const dt = new Date(s.replace(' ', 'T'));
        return isNaN(dt) ? s : dt.toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
    }
    function fmtFileSize(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024*1024) return (n/1024).toFixed(1) + ' KB';
        return (n/(1024*1024)).toFixed(2) + ' MB';
    }

    function renderInvoicesTab(invs) {
        const c = detailCustomer || {};
        const out = Number(c.outstanding) || 0;
        const overdue = Number(c.overdue_count) || 0;
        const sumCls = out <= 0 ? 'zero' : (overdue > 0 ? 'overdue' : 'due');
        const sumIcon = out <= 0 ? 'fa-check-circle' : (overdue > 0 ? 'fa-triangle-exclamation' : 'fa-hourglass-half');
        const sumLabel = out <= 0 ? 'All bills paid' : (overdue > 0 ? `${overdue} OVERDUE — collect now` : 'Outstanding receivable');

        // billing summary card on top of the tab
        let summary = `
          <div class="billing-summary-card ${sumCls}">
            <div class="bs-left">
              <div class="bs-label"><i class="fas ${sumIcon}"></i> ${sumLabel}</div>
              <div class="bs-amount">${fmtMoney(out)}</div>
              <div class="bs-meta">${invs.length} invoice(s) on file • Package: ${escapeHtml(c.package_name || '—')} ${c.package_price ? '• ' + fmtMoney(c.package_price) + '/mo' : ''}</div>
            </div>
            <div class="bs-actions">
              <button class="btn btn-success btn-block" onclick="genInvoiceQuick()"><i class="fas fa-bolt"></i> Generate This Month's Bill</button>
              ${out > 0 ? '<a class="btn btn-primary btn-block" href="payments.php?customer_id=' + c.id + '"><i class="fas fa-money-bill-wave"></i> Record Payment</a>' : ''}
            </div>
          </div>`;

        if (!invs.length) {
            $('#tab-invoices').html(summary + '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-file-invoice"></i></div><div class="empty-state-title">No invoices yet</div><div class="empty-state-text">Click "Generate This Month\'s Bill" above to create the first invoice.</div></div>');
            return;
        }
        let h = summary + '<div class="styled-data-table-wrap"><table class="styled-data-table"><thead><tr><th>Invoice #</th><th>Month</th><th class="num-cell">Total</th><th class="num-cell">Paid</th><th class="num-cell">Balance</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        invs.forEach(i => {
            const bal = Number(i.balance_amount) || 0;
            const balCls = bal <= 0 ? 'zero' : (i.status === 'overdue' ? 'overdue' : 'due');
            h += `<tr>
                <td><a href="invoices.php?id=${i.id}">${escapeHtml(i.invoice_number)}</a></td>
                <td>${escapeHtml(i.billing_month)}</td>
                <td class="num-cell">${fmtMoney(i.total)}</td>
                <td class="num-cell">${fmtMoney(i.paid_amount)}</td>
                <td class="num-cell"><span class="outstanding-badge ${balCls}">${fmtMoney(bal)}</span></td>
                <td>${fmtDate(i.due_date)}</td>
                <td><span class="inv-status ${i.status}">${i.status}</span></td>
                <td>
                    <a href="invoices.php?id=${i.id}" class="action-icon view-icon" title="View"><i class="fas fa-eye"></i></a>
                    ${bal > 0 ? `<a href="payments.php?invoice_id=${i.id}" class="action-icon" style="color:#16a34a" title="Pay"><i class="fas fa-money-bill"></i></a>` : ''}
                </td>
            </tr>`;
        });
        h += '</tbody></table></div>';
        $('#tab-invoices').html(h);
    }

    function renderPaymentsTab(pays) {
        const c = detailCustomer || {};
        const cta = `<div class="form-actions" style="margin-bottom:12px">
                       <a class="btn btn-success" href="payments.php?customer_id=${c.id}"><i class="fas fa-plus"></i> Record New Payment</a>
                     </div>`;
        if (!pays.length) {
            $('#tab-payments').html(cta + '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-money-bill-wave"></i></div><div class="empty-state-title">No payments recorded</div><div class="empty-state-text">Payments will appear here once collected.</div></div>');
            return;
        }
        let h = cta + '<div class="styled-data-table-wrap"><table class="styled-data-table"><thead><tr><th>Receipt #</th><th>Date</th><th class="num-cell">Amount</th><th>Method</th><th>Collected By</th></tr></thead><tbody>';
        pays.forEach(p => {
            h += `<tr>
                <td><strong>${escapeHtml(p.receipt_number)}</strong></td>
                <td>${fmtDate(p.payment_date)}</td>
                <td class="num-cell">${fmtMoney(p.amount)}</td>
                <td><span class="method-badge ${p.payment_method}">${p.payment_method.replace('_',' ')}</span></td>
                <td>${escapeHtml(p.collector)}</td>
            </tr>`;
        });
        h += '</tbody></table></div>';
        $('#tab-payments').html(h);
    }

    function renderComplaintsTab(cmps) {
        if (!cmps.length) {
            $('#tab-complaints').html('<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-ticket-alt"></i></div><div class="empty-state-title">No complaints</div><div class="empty-state-text">Open tickets and history will appear here.</div></div>');
            return;
        }
        let h = '<div class="styled-data-table-wrap"><table class="styled-data-table"><thead><tr><th>Ticket #</th><th>Subject</th><th>Priority</th><th>Status</th><th>Created</th></tr></thead><tbody>';
        cmps.forEach(c => {
            h += `<tr>
                <td><a href="complaints.php?id=${c.id}">${escapeHtml(c.ticket_number)}</a></td>
                <td>${escapeHtml(c.subject)}</td>
                <td><span class="priority-badge ${c.priority}">${c.priority}</span></td>
                <td><span class="cmp-status ${c.status}">${c.status.replace('_',' ')}</span></td>
                <td>${escapeHtml(fmtDate(c.created_at))}</td>
            </tr>`;
        });
        h += '</tbody></table></div>';
        $('#tab-complaints').html(h);
    }

    function renderDocumentsTab(docs) {
        let h = '';
        if (CAN_EDIT || IS_COLLECTOR) {
            h += `<div class="form-actions" style="margin-bottom:12px">
                    <button class="btn btn-success" onclick="openUploadDocModal()"><i class="fas fa-upload"></i> Upload Document</button>
                  </div>`;
        }
        if (!docs.length) {
            h += '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-folder-open"></i></div><div class="empty-state-title">No documents yet</div><div class="empty-state-text">Upload CNIC, contracts, or utility bills.</div></div>';
            $('#tab-documents').html(h);
            return;
        }
        h += '<div class="styled-data-table-wrap"><table class="styled-data-table"><thead><tr><th>Type</th><th>Preview</th><th>File</th><th>Size</th><th>Uploaded By</th><th>When</th><th>Actions</th></tr></thead><tbody>';
        docs.forEach(d => {
            const ext = (d.original_name || '').split('.').pop().toLowerCase();
            const isImg = ['jpg','jpeg','png','webp','gif'].includes(ext);
            const preview = isImg
                ? `<a href="${escapeHtml(d.file_path)}" target="_blank"><img src="${escapeHtml(d.file_path)}" alt="" style="max-width:60px;max-height:60px;border-radius:4px"></a>`
                : `<a href="${escapeHtml(d.file_path)}" target="_blank"><i class="fas fa-file-pdf" style="font-size:28px;color:#ef4444"></i></a>`;
            const canDel = (Number(d.uploaded_by) === <?php echo (int)$user_id; ?>) || <?php echo isAdminLevel() ? 'true' : 'false'; ?>;
            const delBtn = canDel ? `<button class="action-icon delete-icon" onclick="deleteDoc(${d.id})" title="Delete"><i class="fas fa-trash"></i></button>` : '';
            h += `<tr>
                <td><span class="doc-type ${d.doc_type}">${escapeHtml(d.doc_type.replace('_',' '))}</span></td>
                <td>${preview}</td>
                <td><a href="${escapeHtml(d.file_path)}" target="_blank" style="color:#0074D9">${escapeHtml(d.original_name)}</a></td>
                <td class="num-cell">${fmtFileSize(d.file_size)}</td>
                <td>${escapeHtml(d.uploader_name)}</td>
                <td>${escapeHtml(fmtDateTime(d.uploaded_at))}</td>
                <td>${delBtn}</td>
            </tr>`;
        });
        h += '</tbody></table></div>';
        $('#tab-documents').html(h);
    }

    function openUploadDocModal() {
        Swal.fire({
            title: 'Upload Document',
            html: `
                <div class="swal-form-block">
                    <label class="swal-form-label"><i class="fas fa-tag"></i> Type</label>
                    <select id="swDocType">
                        <option value="cnic_front">CNIC Front</option>
                        <option value="cnic_back">CNIC Back</option>
                        <option value="contract">Contract</option>
                        <option value="utility_bill">Utility Bill</option>
                        <option value="other">Other</option>
                    </select>
                    <label class="swal-form-label swal-form-label-mt"><i class="fas fa-file"></i> File (jpg/png/webp/pdf, max 5MB)</label>
                    <input type="file" id="swDocFile" class="swal2-file" accept=".jpg,.jpeg,.png,.webp,.pdf">
                </div>`,
            didOpen: () => { try { new SearchableDropdown({ selectId: 'swDocType', placeholder: 'Document type...', icon: 'fas fa-tag', allowClear: false }); } catch (e) {} },
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-upload"></i> Upload',
            preConfirm: () => {
                const t = document.getElementById('swDocType').value;
                const f = document.getElementById('swDocFile').files[0];
                if (!f) { Swal.showValidationMessage('Pick a file'); return false; }
                if (f.size > 5*1024*1024) { Swal.showValidationMessage('File too large (max 5MB)'); return false; }
                return { type: t, file: f };
            }
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData();
            fd.append('customer_id', DETAIL_ID);
            fd.append('doc_type', r.value.type);
            fd.append('file', r.value.file);
            Swal.fire({ title:'Uploading...', allowOutsideClick:false, didOpen:() => Swal.showLoading() });
            $.ajax({
                url: '?action=uploadCustomerDocument', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', title:'Uploaded', timer:1500, showConfirmButton:false });
                        loadDetail();
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: () => Swal.fire({ icon:'error', title:'Error', text:'Upload failed' })
            });
        });
    }

    function deleteDoc(id) {
        Swal.fire({
            icon:'warning', title:'Delete document?',
            showCancelButton:true, confirmButtonColor:'#ea4335', confirmButtonText:'Delete'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteCustomerDocument', method:'POST', data: fd,
                processData:false, contentType:false, dataType:'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text:'Deleted', timer:1300, showConfirmButton:false });
                        loadDetail();
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: () => Swal.fire({ icon:'error', title:'Error', text:'Delete failed' })
            });
        });
    }

    function editFromDetail() {
        if (!detailCustomer) return;
        editCustomer({
            id: detailCustomer.id,
            customer_code: detailCustomer.customer_code,
            full_name: detailCustomer.full_name,
            phone: detailCustomer.phone,
            email: detailCustomer.email,
            cnic: detailCustomer.cnic
        });
    }

    function saveNotes() {
        if (!CAN_EDIT || !detailCustomer) return;
        const fd = new FormData();
        // full payload so update doesn't blank tier-1/tier-2 fields
        const c = detailCustomer;
        fd.append('id', c.id);
        fd.append('full_name', c.full_name);
        fd.append('phone', c.phone);
        fd.append('alternate_phone', c.alternate_phone || '');
        fd.append('father_name', c.father_name || '');
        fd.append('email', c.email || '');
        fd.append('cnic', c.cnic || '');
        fd.append('address', c.address || '');
        fd.append('area_id', c.area_id);
        fd.append('package_id', c.package_id);
        fd.append('installation_date', c.installation_date);
        fd.append('monthly_due_day', c.monthly_due_day);
        fd.append('ip_address', c.ip_address || '');
        fd.append('mac_address', c.mac_address || '');
        fd.append('connection_status', c.connection_status);
        fd.append('notes', document.getElementById('dNotes').value);
        if (c.gps_lat != null) fd.append('gps_lat', c.gps_lat);
        if (c.gps_lng != null) fd.append('gps_lng', c.gps_lng);
        fd.append('router_serial', c.router_serial || '');
        fd.append('connection_type', c.connection_type || 'pppoe');
        if (c.vlan_id != null) fd.append('vlan_id', c.vlan_id);
        fd.append('registration_fee', c.registration_fee || 0);
        fd.append('security_deposit', c.security_deposit || 0);
        fd.append('discount_pct', c.discount_pct || 0);
        if (Number(c.billing_email_optin)) fd.append('billing_email_optin', '1');
        if (Number(c.whatsapp_optin))      fd.append('whatsapp_optin', '1');

        // tier-2 — keep existing values
        fd.append('pppoe_username',          c.pppoe_username || '');
        fd.append('pppoe_password',          c.pppoe_password || '');
        fd.append('connection_medium',       c.connection_medium || 'fiber');
        if (c.nas_id != null)        fd.append('nas_id', c.nas_id);
        fd.append('static_ip_address',       c.static_ip_address || '');
        fd.append('subnet_mask',             c.subnet_mask || '');
        fd.append('gateway',                 c.gateway || '');
        fd.append('dns1',                    c.dns1 || '');
        fd.append('dns2',                    c.dns2 || '');
        if (c.olt_id != null)        fd.append('olt_id', c.olt_id);
        fd.append('olt_port',                c.olt_port || '');
        fd.append('pon_port',                c.pon_port || '');
        if (c.splitter_id != null)   fd.append('splitter_id', c.splitter_id);
        fd.append('splitter_port',           c.splitter_port || '');
        fd.append('ont_serial',              c.ont_serial || '');
        fd.append('ont_loid',                c.ont_loid || '');
        if (c.signal_strength_dbm != null) fd.append('signal_strength_dbm', c.signal_strength_dbm);
        fd.append('bandwidth_profile_name',  c.bandwidth_profile_name || '');
        if (Number(c.mac_binding))   fd.append('mac_binding', '1');
        if (c.cable_length_meters != null) fd.append('cable_length_meters', c.cable_length_meters);
        fd.append('customer_type',           c.customer_type || 'residential');
        fd.append('business_name',           c.business_name || '');
        fd.append('tax_id',                  c.tax_id || '');
        fd.append('ntn_number',              c.ntn_number || '');
        fd.append('gst_number',              c.gst_number || '');
        if (Number(c.tax_exempt))    fd.append('tax_exempt', '1');
        fd.append('credit_limit',            c.credit_limit || 0);
        if (Number(c.auto_pay_enabled)) fd.append('auto_pay_enabled', '1');
        fd.append('auto_pay_method',         c.auto_pay_method || '');
        fd.append('vip_status',              c.vip_status || 'regular');
        fd.append('dob',                     c.dob || '');
        fd.append('gender',                  c.gender || '');
        fd.append('nationality',             c.nationality || 'Pakistan');
        fd.append('nearest_landmark',        c.nearest_landmark || '');
        fd.append('whatsapp_number',         c.whatsapp_number || '');
        if (c.salesperson_user_id != null)  fd.append('salesperson_user_id',   c.salesperson_user_id);
        if (c.installation_user_id != null) fd.append('installation_user_id',  c.installation_user_id);
        if (Number(c.agreement_signed)) fd.append('agreement_signed', '1');
        fd.append('agreement_pdf_path',      c.agreement_pdf_path || '');
        fd.append('agreement_start_date',    c.agreement_start_date || '');
        fd.append('agreement_end_date',      c.agreement_end_date || '');
        fd.append('contract_period_months',  c.contract_period_months || 0);
        fd.append('early_termination_fee',   c.early_termination_fee || 0);
        fd.append('acquisition_source',      c.acquisition_source || '');
        if (c.referred_by_customer_id != null) fd.append('referred_by_customer_id', c.referred_by_customer_id);

        Swal.fire({ title:'Saving notes...', allowOutsideClick:false, didOpen:() => Swal.showLoading() });
        $.ajax({
            url: '?action=updateCustomer', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    detailCustomer.notes = document.getElementById('dNotes').value;
                    Swal.fire({ icon:'success', text:'Notes saved', timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: resp.message });
                }
            },
            error: () => Swal.fire({ icon:'error', title:'Error', text:'Connection error' })
        });
    }

    function genInvoiceQuick() {
        if (!detailCustomer) return;
        location.href = 'invoices.php?customer_id=' + detailCustomer.id;
    }
    </script>
</body>
</html>
