<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: super_admin + admin + accountant
if (!isset($_SESSION['user_id']) || !can('leads', 'v')) {
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
$current_page = 'leads';

// next CUST-NNNN code generator (mirrors customers.php)
function nextCustomerCode($conn) {
    $res = $conn->query("SELECT IFNULL(MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)), 0) + 1 AS n FROM customers");
    $n = $res ? (int)$res->fetch_assoc()['n'] : 1;
    if ($n < 1) $n = 1;
    return sprintf('CUST-%04d', $n);
}

// next LEAD-YYYY-NNNN code per current year
function nextLeadCode($conn) {
    $year = (int)date('Y');
    $stmt = $conn->prepare("SELECT IFNULL(MAX(CAST(SUBSTRING_INDEX(lead_code,'-',-1) AS UNSIGNED)), 0) + 1 AS n
                            FROM leads
                            WHERE lead_code LIKE ?");
    $like = "LEAD-$year-%";
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 1);
    $stmt->close();
    if ($n < 1) $n = 1;
    return sprintf('LEAD-%d-%04d', $year, $n);
}

// allowed enum sets
$ALLOWED_STATUS   = ['new','contacted','quoted','negotiating','won','lost','dropped'];
$ALLOWED_PRIORITY = ['low','medium','high'];
$ALLOWED_SOURCE   = ['walk_in','call','whatsapp','referral','website','social','other'];
$ALLOWED_CTYPE    = ['residential','business','enterprise','govt'];
$ALLOWED_TIMELINE = ['immediate','1_month','3_months','exploring'];
$ALLOWED_USAGE    = ['streaming','gaming','wfh','business','mixed'];
$ALLOWED_FEAS     = ['feasible','not_feasible','needs_survey','cabling_required','no_coverage'];
$ALLOWED_TEMP     = ['hot','warm','cold'];
$ALLOWED_DISQ     = ['no_coverage','no_budget','competitor_locked','duplicate','not_interested','no_response','other'];

// csv import/template contract for this page
function leadImportSpec() {
    return [
        'entity'  => 'Lead',
        'table'   => 'leads',
        'perm'    => 'leads',
        'columns' => [
            'full_name'             => ['label' => 'Full Name',             'type' => 's', 'required' => true, 'max' => 100, 'demo' => 'Customer 1'],
            'phone'                 => ['label' => 'Phone',                 'type' => 's', 'required' => true, 'max' => 20, 'demo' => '03001000001'],
            'alternate_phone'       => ['label' => 'Alternate Phone',       'type' => 's', 'max' => 20, 'demo' => '03001000002'],
            'email'                 => ['label' => 'Email',                 'type' => 'email', 'max' => 100, 'demo' => 'customer1@demo.com'],
            'address'               => ['label' => 'Address',               'type' => 's', 'demo' => 'House 1, Street 1, Demo City'],
            'area_id'               => ['label' => 'Area',                  'type' => 'lookup',
                                         'lookup' => ['table' => 'areas', 'match' => ['name', 'area_code'], 'where' => "is_deleted = 0 AND status='active'"],
                                         'demo' => 'Area 1'],
            'package_interested_id' => ['label' => 'Package Interested',    'type' => 'lookup',
                                         'lookup' => ['table' => 'packages', 'match' => ['name'], 'where' => "is_deleted = 0 AND status='active'"],
                                         'demo' => 'Home Pro 25Mbps'],
            'source'                => ['label' => 'Source', 'type' => 'enum',
                                         'values' => ['walk_in','call','whatsapp','referral','website','social','other'], 'default' => 'call', 'demo' => 'call'],
            'status'                => ['label' => 'Status', 'type' => 'enum',
                                         'values' => ['new','contacted','quoted','negotiating','won','lost','dropped'], 'default' => 'new', 'demo' => 'new'],
            'priority'              => ['label' => 'Priority', 'type' => 'enum',
                                         'values' => ['low','medium','high'], 'default' => 'medium', 'demo' => 'medium'],
            'owner_user_id'         => ['label' => 'Assigned Staff', 'type' => 'lookup',
                                         'lookup' => ['table' => 'users', 'match' => ['name', 'username'],
                                                      'where' => "is_deleted = 0 AND status='active' AND " . roleInSql('users', 'leads', 'v')],
                                         'demo' => 'System Administrator'],
            'expected_install_date' => ['label' => 'Expected Install Date', 'type' => 'date', 'demo' => '2026-01-15'],
            'quote_amount'          => ['label' => 'Quote Amount',          'type' => 'd', 'scale' => 2, 'demo' => '2500'],
            'next_followup_date'    => ['label' => 'Next Followup Date',    'type' => 'date', 'demo' => '2026-01-20'],
            'notes'                 => ['label' => 'Notes',                 'type' => 's', 'demo' => 'Imported from CSV'],
        ],
        // year-scoped seq once per batch — mirrors nextLeadCode(), no per-row query
        'init' => function ($conn, &$state) {
            $year = (int)date('Y');
            $like = "LEAD-$year-%";
            $stmt = $conn->prepare("SELECT IFNULL(MAX(CAST(SUBSTRING_INDEX(lead_code,'-',-1) AS UNSIGNED)), 0) AS n FROM leads WHERE lead_code LIKE ?");
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
            $stmt->close();
            $state['year']    = $year;
            $state['nextSeq'] = $n + 1;
        },
        'before' => function (&$row, $conn, &$state) {
            $row['lead_code'] = sprintf('LEAD-%d-%04d', $state['year'], $state['nextSeq']++);
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
            case 'importCsv':      rsImportRun($conn, leadImportSpec());
            case 'importTemplate': rsImportTemplate(leadImportSpec());

            case 'getLeads': {
                $sql = "SELECT l.id, l.lead_code, l.full_name, l.phone, l.alternate_phone, l.email, l.address,
                               l.area_id, l.package_interested_id, l.source, l.referral_customer_id,
                               l.status, l.priority, l.owner_user_id,
                               l.expected_install_date, l.quote_amount, l.lost_reason,
                               l.converted_customer_id, l.converted_at, l.last_contacted_at,
                               l.next_followup_date, l.notes, l.created_at,
                               l.temperature, l.lead_score,
                               a.name  AS area_name,
                               p.name  AS package_name,
                               u.name  AS owner_name,
                               rc.customer_code AS referral_customer_code,
                               cc.customer_code AS converted_customer_code
                        FROM leads l
                        LEFT JOIN areas    a  ON a.id  = l.area_id
                        LEFT JOIN packages p  ON p.id  = l.package_interested_id
                        LEFT JOIN users    u  ON u.id  = l.owner_user_id
                        LEFT JOIN customers rc ON rc.id = l.referral_customer_id
                        LEFT JOIN customers cc ON cc.id = l.converted_customer_id
                        WHERE l.is_deleted = 0
                        ORDER BY FIELD(l.priority,'high','medium','low'), l.created_at DESC";
                $res  = $conn->query($sql);
                $rows = [];
                $today = date('Y-m-d');
                while ($r = $res->fetch_assoc()) {
                    $followup = $r['next_followup_date'];
                    $rows[] = [
                        'id'                       => (int)$r['id'],
                        'lead_code'                => $r['lead_code'] ?? '',
                        'full_name'                => $r['full_name'],
                        'phone'                    => $r['phone'],
                        'alternate_phone'          => $r['alternate_phone'] ?? '',
                        'email'                    => $r['email'] ?? '',
                        'address'                  => $r['address'] ?? '',
                        'area_id'                  => $r['area_id'] !== null ? (int)$r['area_id'] : null,
                        'area_name'                => $r['area_name'] ?? '',
                        'package_interested_id'    => $r['package_interested_id'] !== null ? (int)$r['package_interested_id'] : null,
                        'package_name'             => $r['package_name'] ?? '',
                        'source'                   => $r['source'],
                        'referral_customer_id'     => $r['referral_customer_id'] !== null ? (int)$r['referral_customer_id'] : null,
                        'referral_customer_code'   => $r['referral_customer_code'] ?? '',
                        'status'                   => $r['status'],
                        'priority'                 => $r['priority'],
                        'owner_user_id'            => $r['owner_user_id'] !== null ? (int)$r['owner_user_id'] : null,
                        'owner_name'               => $r['owner_name'] ?? '',
                        'expected_install_date'    => $r['expected_install_date'],
                        'quote_amount'             => $r['quote_amount'] !== null ? (float)$r['quote_amount'] : null,
                        'lost_reason'              => $r['lost_reason'] ?? '',
                        'converted_customer_id'    => $r['converted_customer_id'] !== null ? (int)$r['converted_customer_id'] : null,
                        'converted_customer_code'  => $r['converted_customer_code'] ?? '',
                        'converted_at'             => $r['converted_at'],
                        'last_contacted_at'        => $r['last_contacted_at'],
                        'next_followup_date'       => $followup,
                        'next_followup_overdue'    => ($followup && $followup < $today && !in_array($r['status'], ['won','lost','dropped'], true)) ? 1 : 0,
                        'temperature'              => $r['temperature'] ?? 'warm',
                        'lead_score'               => (int)($r['lead_score'] ?? 0),
                        'notes'                    => $r['notes'] ?? '',
                        'created_at'               => date('M d, Y', strtotime($r['created_at']))
                    ];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getLeadDetail': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid id']);
                    exit();
                }
                $stmt = $conn->prepare("SELECT l.*, a.name AS area_name, p.name AS package_name,
                                               u.name AS owner_name,
                                               rc.customer_code AS referral_customer_code,
                                               cc.customer_code AS converted_customer_code
                                        FROM leads l
                                        LEFT JOIN areas a ON a.id = l.area_id
                                        LEFT JOIN packages p ON p.id = l.package_interested_id
                                        LEFT JOIN users u ON u.id = l.owner_user_id
                                        LEFT JOIN customers rc ON rc.id = l.referral_customer_id
                                        LEFT JOIN customers cc ON cc.id = l.converted_customer_id
                                        WHERE l.id = ? AND l.is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Lead not found']);
                    exit();
                }
                $row['id']                    = (int)$row['id'];
                $row['area_id']               = $row['area_id'] !== null ? (int)$row['area_id'] : null;
                $row['package_interested_id'] = $row['package_interested_id'] !== null ? (int)$row['package_interested_id'] : null;
                $row['owner_user_id']         = $row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null;
                $row['referral_customer_id']  = $row['referral_customer_id'] !== null ? (int)$row['referral_customer_id'] : null;
                $row['converted_customer_id'] = $row['converted_customer_id'] !== null ? (int)$row['converted_customer_id'] : null;
                $row['quote_amount']          = $row['quote_amount'] !== null ? (float)$row['quote_amount'] : null;

                // tier-2 numeric casts (defensive)
                $intNullable = ['number_of_employees','surveyed_by_user_id','installation_distance_meters','current_provider_speed','concurrent_users'];
                foreach ($intNullable as $k) { if (array_key_exists($k, $row)) $row[$k] = $row[$k] !== null ? (int)$row[$k] : null; }
                $intFlag = ['survey_required','whatsapp_consent','email_consent','lead_score'];
                foreach ($intFlag as $k) { if (array_key_exists($k, $row)) $row[$k] = (int)($row[$k] ?? 0); }
                $floatNullable = ['gps_lat','gps_lng','current_provider_price','budget_min','budget_max','referral_amount_promised'];
                foreach ($floatNullable as $k) { if (array_key_exists($k, $row)) $row[$k] = $row[$k] !== null ? (float)$row[$k] : null; }
                if (array_key_exists('discount_offered_pct', $row)) $row['discount_offered_pct'] = (float)($row['discount_offered_pct'] ?? 0);

                echo json_encode(['success' => true, 'lead' => $row]);
                exit();
            }

            case 'getPipelineCounts': {
                $res = $conn->query("SELECT status, COUNT(*) c FROM leads WHERE is_deleted = 0 GROUP BY status");
                $counts = ['all' => 0, 'new' => 0, 'contacted' => 0, 'quoted' => 0, 'negotiating' => 0, 'won' => 0, 'lost' => 0, 'dropped' => 0];
                while ($r = $res->fetch_assoc()) {
                    $s = $r['status'];
                    $c = (int)$r['c'];
                    if (isset($counts[$s])) $counts[$s] = $c;
                    $counts['all'] += $c;
                }
                echo json_encode(['success' => true, 'counts' => $counts]);
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

            case 'getStaff': {
                // owner / surveyor picker — admin-level + accountant + collector
                $sql = "SELECT id, name, role FROM users
                        WHERE is_deleted = 0 AND status='active'
                          AND " . roleInSql('users', 'leads', 'v') . "
                        ORDER BY name ASC";
                $res = $conn->query($sql);
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'role' => $r['role']];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getActiveCustomers': {
                $res = $conn->query("SELECT id, customer_code, full_name FROM customers
                                     WHERE is_deleted = 0 AND connection_status='active'
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

            case 'addLead':
            case 'updateLead': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $isEdit = $_GET['action'] === 'updateLead';
                $id     = (int)($_POST['id'] ?? 0);

                // base
                $full_name  = trim($_POST['full_name'] ?? '');
                $phone      = trim($_POST['phone'] ?? '');
                $alt_phone  = trim($_POST['alternate_phone'] ?? '');
                $email      = trim($_POST['email'] ?? '');
                $address    = trim($_POST['address'] ?? '');
                $area_id_in = trim($_POST['area_id'] ?? '');
                $area_id    = $area_id_in === '' ? null : (int)$area_id_in;
                $pkg_in     = trim($_POST['package_interested_id'] ?? '');
                $pkg_id     = $pkg_in === '' ? null : (int)$pkg_in;
                $source     = $_POST['source'] ?? 'call';
                $ref_in     = trim($_POST['referral_customer_id'] ?? '');
                $ref_cust   = $ref_in === '' ? null : (int)$ref_in;
                $status     = $_POST['status'] ?? 'new';
                $priority   = $_POST['priority'] ?? 'medium';
                $owner_in   = trim($_POST['owner_user_id'] ?? '');
                $owner_id   = $owner_in === '' ? null : (int)$owner_in;
                $exp_inst   = trim($_POST['expected_install_date'] ?? '');
                $exp_inst   = $exp_inst === '' ? null : $exp_inst;
                $quote_in   = trim($_POST['quote_amount'] ?? '');
                $quote_amt  = $quote_in === '' ? null : (float)$quote_in;
                $followup   = trim($_POST['next_followup_date'] ?? '');
                $followup   = $followup === '' ? null : $followup;
                $last_ct    = trim($_POST['last_contacted_at'] ?? '');
                $last_ct    = $last_ct === '' ? null : str_replace('T', ' ', $last_ct);
                $notes      = trim($_POST['notes'] ?? '');

                // tier-2 — qualification
                $cust_type     = $_POST['customer_type'] ?? 'residential';
                $biz_name      = trim($_POST['business_name'] ?? '');
                $emp_in        = trim($_POST['number_of_employees'] ?? '');
                $num_emp       = $emp_in === '' ? null : (int)$emp_in;
                $gps_lat_in    = trim($_POST['gps_lat'] ?? '');
                $gps_lng_in    = trim($_POST['gps_lng'] ?? '');
                $gps_lat       = $gps_lat_in === '' ? null : (float)$gps_lat_in;
                $gps_lng       = $gps_lng_in === '' ? null : (float)$gps_lng_in;
                $feas_status   = $_POST['feasibility_status'] ?? 'needs_survey';
                $feas_notes    = trim($_POST['feasibility_notes'] ?? '');
                $survey_req    = !empty($_POST['survey_required']) ? 1 : 0;
                $survey_dt     = trim($_POST['survey_date'] ?? '');
                $survey_dt     = $survey_dt === '' ? null : $survey_dt;
                $surv_user_in  = trim($_POST['surveyed_by_user_id'] ?? '');
                $surveyed_by   = $surv_user_in === '' ? null : (int)$surv_user_in;
                $survey_res    = trim($_POST['survey_result'] ?? '');
                $inst_dist_in  = trim($_POST['installation_distance_meters'] ?? '');
                $inst_dist     = $inst_dist_in === '' ? null : (int)$inst_dist_in;

                // tier-2 — competitor/budget
                $cur_provider  = trim($_POST['current_provider'] ?? '');
                $cur_pr_in     = trim($_POST['current_provider_price'] ?? '');
                $cur_pr        = $cur_pr_in === '' ? null : (float)$cur_pr_in;
                $cur_sp_in     = trim($_POST['current_provider_speed'] ?? '');
                $cur_speed     = $cur_sp_in === '' ? null : (int)$cur_sp_in;
                $pain_point    = trim($_POST['pain_point'] ?? '');
                $reason_switch = trim($_POST['reason_to_switch'] ?? '');
                $dec_timeline  = $_POST['decision_timeline'] ?? '';
                $dec_timeline  = $dec_timeline === '' ? null : $dec_timeline;
                $bmin_in       = trim($_POST['budget_min'] ?? '');
                $budget_min    = $bmin_in === '' ? null : (float)$bmin_in;
                $bmax_in       = trim($_POST['budget_max'] ?? '');
                $budget_max    = $bmax_in === '' ? null : (float)$bmax_in;
                $usage_in      = $_POST['usage_type'] ?? '';
                $usage_type    = $usage_in === '' ? null : $usage_in;
                $cu_in         = trim($_POST['concurrent_users'] ?? '');
                $concur_users  = $cu_in === '' ? null : (int)$cu_in;

                // tier-2 — quote / engagement
                $quote_pdf     = trim($_POST['quote_pdf_path'] ?? '');
                $quote_exp_in  = trim($_POST['quote_expiry_date'] ?? '');
                $quote_exp     = $quote_exp_in === '' ? null : $quote_exp_in;
                $disc_off_pct  = (float)($_POST['discount_offered_pct'] ?? 0);
                $ref_amt_in    = trim($_POST['referral_amount_promised'] ?? '');
                $ref_amt       = $ref_amt_in === '' ? null : (float)$ref_amt_in;
                $lead_score    = (int)($_POST['lead_score'] ?? 0);
                $temperature   = $_POST['temperature'] ?? 'warm';
                $disq_reason   = $_POST['disqualification_reason'] ?? '';
                $disq_reason   = $disq_reason === '' ? null : $disq_reason;
                $wa_consent    = !empty($_POST['whatsapp_consent']) ? 1 : 0;
                $em_consent    = !empty($_POST['email_consent']) ? 1 : 0;

                if ($full_name === '' || $phone === '') {
                    echo json_encode(['success' => false, 'message' => 'Name and phone are required']);
                    exit();
                }
                if (!in_array($source,      $ALLOWED_SOURCE,   true)) $source       = 'call';
                if (!in_array($status,      $ALLOWED_STATUS,   true)) $status       = 'new';
                if (!in_array($priority,    $ALLOWED_PRIORITY, true)) $priority     = 'medium';
                if (!in_array($cust_type,   $ALLOWED_CTYPE,    true)) $cust_type    = 'residential';
                if (!in_array($feas_status, $ALLOWED_FEAS,     true)) $feas_status  = 'needs_survey';
                if (!in_array($temperature, $ALLOWED_TEMP,     true)) $temperature  = 'warm';
                if ($dec_timeline !== null && !in_array($dec_timeline, $ALLOWED_TIMELINE, true)) $dec_timeline = null;
                if ($usage_type   !== null && !in_array($usage_type,   $ALLOWED_USAGE,    true)) $usage_type   = null;
                if ($disq_reason  !== null && !in_array($disq_reason,  $ALLOWED_DISQ,     true)) $disq_reason  = null;
                // disqualification reason only valid for lost/dropped
                if (!in_array($status, ['lost','dropped'], true)) $disq_reason = null;

                if ($cust_type !== 'residential' && $biz_name === '') {
                    echo json_encode(['success' => false, 'message' => 'Business name is required for business/enterprise/govt customers']);
                    exit();
                }
                if ($lead_score < 0)   $lead_score   = 0;
                if ($lead_score > 100) $lead_score   = 100;
                if ($disc_off_pct < 0) $disc_off_pct = 0;
                if ($disc_off_pct > 100) $disc_off_pct = 100;

                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }
                if ($source !== 'referral') $ref_cust = null;

                // auto-set last_contacted_at when status moves off 'new' and no value provided
                if ($last_ct === null && $status !== 'new') {
                    $last_ct = date('Y-m-d H:i:s');
                }

                if (!$isEdit) {
                    $conn->begin_transaction();
                    try {
                        $code = nextLeadCode($conn);
                        $stmt = $conn->prepare("INSERT INTO leads
                            (lead_code, full_name, phone, alternate_phone, email, address, area_id, package_interested_id,
                             source, referral_customer_id, status, priority, owner_user_id,
                             expected_install_date, quote_amount, last_contacted_at, next_followup_date, notes,
                             customer_type, business_name, number_of_employees,
                             gps_lat, gps_lng,
                             feasibility_status, feasibility_notes,
                             survey_required, survey_date, surveyed_by_user_id, survey_result, installation_distance_meters,
                             current_provider, current_provider_price, current_provider_speed,
                             pain_point, reason_to_switch, decision_timeline, budget_min, budget_max,
                             usage_type, concurrent_users,
                             quote_pdf_path, quote_expiry_date, discount_offered_pct, referral_amount_promised,
                             lead_score, temperature, disqualification_reason,
                             whatsapp_consent, email_consent)
                            VALUES (?,?,?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,
                                    ?,?,?, ?,?, ?,?, ?,?,?,?,?, ?,?,?, ?,?,?,?,?, ?,?,
                                    ?,?,?,?, ?,?,?, ?,?)");
                        // base18: code,name,phone,alt,email,addr=s*6; area=i pkg=i; source=s ref=i status=s prio=s owner=i; exp=s quote=d last=s next=s notes=s → "ssssssiisissisdsss"
                        // qual13: cust_type=s biz=s emp=i; lat=d lng=d; feas=s notes=s; survey_req=i date=s by=i res=s dist=i → "ssiddssisi" (10) — wait emp counted? recount...
                        // Let's count placeholders: base 18 + qual 13 + comp/budget 9 + quote/eng 9 = 49
                        $stmt->bind_param(
                            // base 18
                            "ssssssiisissisdsss"
                            // qualification 13: cust_type,biz_name=s*2, num_emp=i; gps_lat,gps_lng=d*2; feas_status,feas_notes=s*2; survey_req=i, survey_dt=s, surveyed_by=i, survey_res=s, inst_dist=i
                            . "ssi" . "dd" . "ss" . "isisi"
                            // competitor/budget 9: cur_provider=s, cur_pr=d, cur_speed=i, pain=s, reason=s, dec_timeline=s, budget_min=d, budget_max=d, usage_type=s, concur_users=i
                            . "sdisssddsi"
                            // quote/eng 9: quote_pdf=s, quote_exp=s, disc_off=d, ref_amt=d, lead_score=i, temperature=s, disq_reason=s, wa_consent=i, em_consent=i
                            . "ssddisii" . "i",
                            $code, $full_name, $phone, $alt_phone, $email, $address,
                            $area_id, $pkg_id,
                            $source, $ref_cust, $status, $priority, $owner_id,
                            $exp_inst, $quote_amt, $last_ct, $followup, $notes,
                            // qualification
                            $cust_type, $biz_name, $num_emp,
                            $gps_lat, $gps_lng,
                            $feas_status, $feas_notes,
                            $survey_req, $survey_dt, $surveyed_by, $survey_res, $inst_dist,
                            // competitor/budget
                            $cur_provider, $cur_pr, $cur_speed,
                            $pain_point, $reason_switch, $dec_timeline, $budget_min, $budget_max,
                            $usage_type, $concur_users,
                            // quote/engagement
                            $quote_pdf, $quote_exp, $disc_off_pct, $ref_amt,
                            $lead_score, $temperature, $disq_reason,
                            $wa_consent, $em_consent
                        );
                        if (!$stmt->execute()) {
                            $err = $stmt->error; $stmt->close();
                            throw new Exception("Insert failed: $err");
                        }
                        $newId = $stmt->insert_id;
                        $stmt->close();

                        $conn->commit();
                        logActivity($user_id, $username, 'Lead Created', "Created $code: $full_name ($phone)");
                        try { createNotificationForAdmins('Lead Created', 'New lead "' . htmlspecialchars($full_name) . '" added (' . $code . ').', 'info', 'leads.php'); } catch (Exception $e) {}
                        echo json_encode(['success' => true, 'message' => "Lead $code added", 'id' => $newId, 'lead_code' => $code]);
                    } catch (Exception $e) {
                        $conn->rollback();
                        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                    }
                    exit();
                }

                // UPDATE — fetch existing for diff/audit
                if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }
                $stmt = $conn->prepare("SELECT * FROM leads WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$existing) { echo json_encode(['success' => false, 'message' => 'Lead not found']); exit(); }

                // status change side-effect — set last_contacted_at if moving off 'new'
                if ($existing['status'] === 'new' && $status !== 'new' && $last_ct === null && empty($existing['last_contacted_at'])) {
                    $last_ct = date('Y-m-d H:i:s');
                } elseif ($last_ct === null && !empty($existing['last_contacted_at'])) {
                    $last_ct = $existing['last_contacted_at'];
                }

                $stmt = $conn->prepare("UPDATE leads SET
                    full_name=?, phone=?, alternate_phone=?, email=?, address=?,
                    area_id=?, package_interested_id=?,
                    source=?, referral_customer_id=?, status=?, priority=?, owner_user_id=?,
                    expected_install_date=?, quote_amount=?, last_contacted_at=?, next_followup_date=?, notes=?,
                    customer_type=?, business_name=?, number_of_employees=?,
                    gps_lat=?, gps_lng=?,
                    feasibility_status=?, feasibility_notes=?,
                    survey_required=?, survey_date=?, surveyed_by_user_id=?, survey_result=?, installation_distance_meters=?,
                    current_provider=?, current_provider_price=?, current_provider_speed=?,
                    pain_point=?, reason_to_switch=?, decision_timeline=?, budget_min=?, budget_max=?,
                    usage_type=?, concurrent_users=?,
                    quote_pdf_path=?, quote_expiry_date=?, discount_offered_pct=?, referral_amount_promised=?,
                    lead_score=?, temperature=?, disqualification_reason=?,
                    whatsapp_consent=?, email_consent=?
                    WHERE id=? AND is_deleted = 0");
                // base17 (no code on update): name,phone,alt,email,addr=s*5; area=i pkg=i; source=s ref=i status=s prio=s owner=i; exp=s quote=d last=s next=s notes=s
                $stmt->bind_param(
                    "sssssiisissisdsss"
                    . "ssi" . "dd" . "ss" . "isisi"
                    . "sdisssddsi"
                    . "ssddisii" . "i"
                    . "i",
                    $full_name, $phone, $alt_phone, $email, $address,
                    $area_id, $pkg_id,
                    $source, $ref_cust, $status, $priority, $owner_id,
                    $exp_inst, $quote_amt, $last_ct, $followup, $notes,
                    // qualification
                    $cust_type, $biz_name, $num_emp,
                    $gps_lat, $gps_lng,
                    $feas_status, $feas_notes,
                    $survey_req, $survey_dt, $surveyed_by, $survey_res, $inst_dist,
                    // competitor/budget
                    $cur_provider, $cur_pr, $cur_speed,
                    $pain_point, $reason_switch, $dec_timeline, $budget_min, $budget_max,
                    $usage_type, $concur_users,
                    // quote/engagement
                    $quote_pdf, $quote_exp, $disc_off_pct, $ref_amt,
                    $lead_score, $temperature, $disq_reason,
                    $wa_consent, $em_consent,
                    $id
                );
                if ($stmt->execute()) {
                    $details = "Updated lead: $full_name";
                    if ($existing['status'] !== $status) $details .= " (status: {$existing['status']} -> $status)";
                    logActivity($user_id, $username, 'Lead Updated', $details);
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Lead updated']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                }
                exit();
            }

            case 'markWon': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }

                $stmt = $conn->prepare("SELECT full_name, status FROM leads WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $cur = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$cur) { echo json_encode(['success' => false, 'message' => 'Lead not found']); exit(); }

                $now = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("UPDATE leads SET status='won', last_contacted_at = IFNULL(last_contacted_at, ?) WHERE id = ?");
                $stmt->bind_param("si", $now, $id);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Lead Won', "Marked won: {$cur['full_name']}");
                    try { createNotificationForAdmins('Lead Won', 'Lead "' . htmlspecialchars($cur['full_name']) . '" marked as won.', 'success', 'leads.php'); } catch (Exception $e) {}
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Lead marked as won']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                }
                exit();
            }

            case 'markLost': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                $id     = (int)($_POST['id'] ?? 0);
                $reason = trim($_POST['lost_reason'] ?? '');
                if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }
                if ($reason === '') { echo json_encode(['success' => false, 'message' => 'Lost reason is required']); exit(); }
                if (strlen($reason) > 255) $reason = substr($reason, 0, 255);

                $stmt = $conn->prepare("SELECT full_name FROM leads WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $cur = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$cur) { echo json_encode(['success' => false, 'message' => 'Lead not found']); exit(); }

                $now = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("UPDATE leads SET status='lost', lost_reason=?, last_contacted_at = IFNULL(last_contacted_at, ?) WHERE id = ?");
                $stmt->bind_param("ssi", $reason, $now, $id);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Lead Lost', "Marked lost: {$cur['full_name']} ($reason)");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Lead marked as lost']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                }
                exit();
            }

            case 'convertLead': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                if (!canManageCustomers()) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit(); }

                $lead_id = (int)($_POST['lead_id'] ?? 0);
                if ($lead_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid lead id']); exit(); }

                // load lead
                $stmt = $conn->prepare("SELECT * FROM leads WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $lead_id);
                $stmt->execute();
                $lead = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$lead) { echo json_encode(['success' => false, 'message' => 'Lead not found']); exit(); }
                if (!empty($lead['converted_customer_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Lead already converted']);
                    exit();
                }

                // customer fields — prefer POST values, fall back to lead
                $full_name = trim($_POST['full_name']       ?? $lead['full_name']);
                $phone     = trim($_POST['phone']           ?? $lead['phone']);
                $alt_phone = trim($_POST['alternate_phone'] ?? ($lead['alternate_phone'] ?? ''));
                $email     = trim($_POST['email']           ?? ($lead['email'] ?? ''));
                $address   = trim($_POST['address']         ?? ($lead['address'] ?? ''));
                $cnic      = trim($_POST['cnic'] ?? '');
                $area_id   = (int)($_POST['area_id']   ?? ($lead['area_id'] ?? 0));
                $pkg_id    = (int)($_POST['package_id'] ?? ($lead['package_interested_id'] ?? 0));
                $inst_date = trim($_POST['installation_date'] ?? '');
                $due_day   = (int)($_POST['monthly_due_day'] ?? 1);
                $ip_addr   = trim($_POST['ip_address']  ?? '');
                $mac_addr  = trim($_POST['mac_address'] ?? '');
                $conn_st   = 'active';
                $cust_notes = trim($_POST['notes'] ?? ($lead['notes'] ?? ''));

                // validate
                if ($full_name === '' || $phone === '' || $address === '' || $area_id <= 0 || $pkg_id <= 0 || $inst_date === '') {
                    echo json_encode(['success' => false, 'message' => 'Name, phone, address, area, package, and installation date are required']);
                    exit();
                }
                if ($due_day < 1) $due_day = 1; if ($due_day > 28) $due_day = 28;
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                // transaction
                $conn->begin_transaction();
                try {
                    $code = nextCustomerCode($conn);
                    $father = ''; // no father field on lead
                    $stmt = $conn->prepare("INSERT INTO customers
                        (customer_code, full_name, phone, alternate_phone, father_name, email, cnic, address,
                         area_id, package_id, installation_date, monthly_due_day,
                         ip_address, mac_address, connection_status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    // types: code,name,phone,alt,father,email,cnic,addr=s*8; area=i pkg=i; inst=s due=i; ip=s mac=s status=s notes=s
                    $stmt->bind_param(
                        "ssssssssiisissss",
                        $code, $full_name, $phone, $alt_phone, $father, $email, $cnic, $address,
                        $area_id, $pkg_id,
                        $inst_date, $due_day,
                        $ip_addr, $mac_addr, $conn_st, $cust_notes
                    );
                    if (!$stmt->execute()) {
                        $err = $stmt->error; $stmt->close();
                        throw new Exception("Customer insert failed: $err");
                    }
                    $newCustId = $stmt->insert_id;
                    $stmt->close();

                    // mark lead converted
                    $now = date('Y-m-d H:i:s');
                    $stmt = $conn->prepare("UPDATE leads SET status='won', converted_customer_id=?, converted_at=?, last_contacted_at = IFNULL(last_contacted_at, ?) WHERE id=?");
                    $stmt->bind_param("issi", $newCustId, $now, $now, $lead_id);
                    if (!$stmt->execute()) {
                        $err = $stmt->error; $stmt->close();
                        throw new Exception("Lead update failed: $err");
                    }
                    $stmt->close();

                    logActivity($user_id, $username, 'Customer Created', "Created $code: $full_name (from lead #$lead_id)");
                    logActivity($user_id, $username, 'Lead Converted', "Lead {$lead['full_name']} -> customer $code");
                    try {
                        createNotificationForAdmins('Lead Converted', 'Lead "' . htmlspecialchars($lead['full_name']) . '" converted to customer ' . $code . '.', 'success', 'customers.php');
                    } catch (Exception $e) {}

                    $conn->commit();
                    echo json_encode([
                        'success'        => true,
                        'message'        => "Customer $code created from lead",
                        'customer_id'    => $newCustId,
                        'customer_code'  => $code
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                exit();
            }

            case 'deleteLead': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                if (!can('leads', 'd')) { echo json_encode(['success' => false, 'message' => 'Only super admin can delete']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }

                $stmt = $conn->prepare("SELECT full_name FROM leads WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $cur = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$cur) { echo json_encode(['success' => false, 'message' => 'Lead not found']); exit(); }

                $stmt = $conn->prepare("UPDATE leads SET is_deleted = 1 WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Lead Deleted', "Deleted lead: {$cur['full_name']}");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Lead deleted']);
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
        error_log("Leads.php error: " . $e->getMessage());
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
    <title>Sales Leads - ISP Billing</title>

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
                <span>Leads</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-funnel-dollar"></i> Sales Leads</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- chevron pipeline (status quick-filter) -->
            <div class="chevron-pipeline" id="chevronPipeline" role="tablist" aria-label="Filter leads by pipeline status">
                <div class="chevron-tab cv-all active"          data-status="all"          role="tab" tabindex="0">
                    <i class="fas fa-layer-group"></i> All <span class="cv-count" id="cnt-all">0</span>
                </div>
                <div class="chevron-tab cv-new"                 data-status="new"          role="tab" tabindex="0">
                    <i class="fas fa-star"></i> New <span class="cv-count" id="cnt-new">0</span>
                </div>
                <div class="chevron-tab cv-contacted"           data-status="contacted"    role="tab" tabindex="0">
                    <i class="fas fa-phone"></i> Contacted <span class="cv-count" id="cnt-contacted">0</span>
                </div>
                <div class="chevron-tab cv-quoted"              data-status="quoted"       role="tab" tabindex="0">
                    <i class="fas fa-file-invoice-dollar"></i> Quoted <span class="cv-count" id="cnt-quoted">0</span>
                </div>
                <div class="chevron-tab cv-negotiating"         data-status="negotiating"  role="tab" tabindex="0">
                    <i class="fas fa-comments-dollar"></i> Negotiating <span class="cv-count" id="cnt-negotiating">0</span>
                </div>
                <div class="chevron-tab cv-won"                 data-status="won"          role="tab" tabindex="0">
                    <i class="fas fa-trophy"></i> Won <span class="cv-count" id="cnt-won">0</span>
                </div>
                <div class="chevron-tab cv-lost"                data-status="lost"         role="tab" tabindex="0">
                    <i class="fas fa-times-circle"></i> Lost <span class="cv-count" id="cnt-lost">0</span>
                </div>
                <div class="chevron-tab cv-dropped"             data-status="dropped"      role="tab" tabindex="0">
                    <i class="fas fa-ban"></i> Dropped <span class="cv-count" id="cnt-dropped">0</span>
                </div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Leads</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadAll()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('leads', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Lead
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-section initially-hidden" id="filtersSection">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-times-circle"></i> Clear All
                        </button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-bolt"></i> Priority</label>
                            <select id="filterPriority">
                                <option value="">All Priorities</option>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-bullhorn"></i> Source</label>
                            <select id="filterSource">
                                <option value="">All Sources</option>
                                <option value="walk_in">Walk-in</option>
                                <option value="call">Call</option>
                                <option value="whatsapp">WhatsApp</option>
                                <option value="referral">Referral</option>
                                <option value="website">Website</option>
                                <option value="social">Social</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-map-marked-alt"></i> Area</label>
                            <select id="filterArea"><option value="">All Areas</option></select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-user-tie"></i> Owner</label>
                            <select id="filterOwner"><option value="">All Owners</option></select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" id="filterSearch" class="filter-input" placeholder="Name, phone, email...">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-bell"></i> Followup Due</label>
                            <label class="status-toggle" title="Show only overdue followups">
                                <input type="checkbox" id="filterFollowup">
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="table-scroll-hint">
                    <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                </div>
                <div id="skeletonWrap">
                    <div class="skeleton-row"></div>
                    <div class="skeleton-row"></div>
                    <div class="skeleton-row"></div>
                    <div class="skeleton-row"></div>
                </div>
                <div class="table-responsive initially-hidden" id="tableWrap">
                    <table id="leadsTable" class="display table-full-width"></table>
                </div>
                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-funnel-dollar"></i></div>
                    <h3 class="empty-state-title">No leads yet — start your pipeline.</h3>
                    <p class="empty-state-text">Click Add Lead to log your first prospect.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Lead Modal -->
    <div class="modal-overlay" id="leadModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Lead</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="leadForm">
                    <input type="hidden" id="leadId" name="id">

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-user"></i> Contact</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-id-badge"></i> Lead Code</label>
                                <input type="text" id="lead_code_disp" readonly placeholder="Auto-generated on save">
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
                                <label><i class="fas fa-phone-alt"></i> Alternate Phone</label>
                                <input type="text" id="alternate_phone" name="alternate_phone">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" id="email" name="email">
                            </div>
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-map-marker-alt"></i> Address</label>
                                <textarea id="address" name="address" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-map-marked-alt"></i> Area</label>
                                <select id="area_id" name="area_id"><option value="">— Select area —</option></select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-box"></i> Package Interested</label>
                                <select id="package_interested_id" name="package_interested_id"><option value="">— Select package —</option></select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-bullhorn"></i> Source *</label>
                                <select id="source" name="source" required>
                                    <option value="walk_in">Walk-in</option>
                                    <option value="call" selected>Call</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="referral">Referral</option>
                                    <option value="website">Website</option>
                                    <option value="social">Social</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user-friends"></i> Referral Customer</label>
                                <select id="referral_customer_id" name="referral_customer_id" disabled>
                                    <option value="">— Select customer —</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-flag"></i> Status *</label>
                                <select id="status" name="status" required>
                                    <option value="new" selected>New</option>
                                    <option value="contacted">Contacted</option>
                                    <option value="quoted">Quoted</option>
                                    <option value="negotiating">Negotiating</option>
                                    <option value="won">Won</option>
                                    <option value="lost">Lost</option>
                                    <option value="dropped">Dropped</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-bolt"></i> Priority *</label>
                                <select id="priority" name="priority" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user-tie"></i> Owner</label>
                                <select id="owner_user_id" name="owner_user_id"><option value="">— Unassigned —</option></select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-day"></i> Expected Install Date</label>
                                <input type="date" id="expected_install_date" name="expected_install_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-money-bill-wave"></i> Quote Amount</label>
                                <input type="number" id="quote_amount" name="quote_amount" step="0.01" min="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-bell"></i> Next Followup Date</label>
                                <input type="date" id="next_followup_date" name="next_followup_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-clock"></i> Last Contacted At</label>
                                <input type="datetime-local" id="last_contacted_at" name="last_contacted_at">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-clipboard-check"></i> Qualification</div>
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
                            <div class="form-group business-only initially-hidden" id="grpLeadBusinessName">
                                <label><i class="fas fa-building"></i> Business Name *</label>
                                <input type="text" id="business_name" name="business_name">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-users"></i> Number of Employees</label>
                                <input type="number" id="number_of_employees" name="number_of_employees" min="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-map-pin"></i> GPS Latitude</label>
                                <input type="number" step="any" id="gps_lat" name="gps_lat" placeholder="24.8607">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-map-pin"></i> GPS Longitude</label>
                                <input type="number" step="any" id="gps_lng" name="gps_lng" placeholder="67.0011">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-check-circle"></i> Feasibility Status *</label>
                                <select id="feasibility_status" name="feasibility_status" required>
                                    <option value="feasible">Feasible</option>
                                    <option value="not_feasible">Not Feasible</option>
                                    <option value="needs_survey" selected>Needs Survey</option>
                                    <option value="cabling_required">Cabling Required</option>
                                    <option value="no_coverage">No Coverage</option>
                                </select>
                            </div>
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-comment-alt"></i> Feasibility Notes</label>
                                <textarea id="feasibility_notes" name="feasibility_notes" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-clipboard-list"></i> Survey Required</label>
                                <label class="status-toggle">
                                    <input type="checkbox" id="survey_required" name="survey_required" value="1">
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-day"></i> Survey Date</label>
                                <input type="date" id="survey_date" name="survey_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user-md"></i> Surveyed By</label>
                                <select id="surveyed_by_user_id" name="surveyed_by_user_id">
                                    <option value="">— Not Yet —</option>
                                </select>
                            </div>
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-clipboard"></i> Survey Result</label>
                                <textarea id="survey_result" name="survey_result" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-ruler-horizontal"></i> Installation Distance (m)</label>
                                <input type="number" id="installation_distance_meters" name="installation_distance_meters" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-balance-scale"></i> Competitor &amp; Budget</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-network-wired"></i> Current Provider</label>
                                <input type="text" id="current_provider" name="current_provider">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-money-bill"></i> Current Provider Price</label>
                                <input type="number" step="0.01" min="0" id="current_provider_price" name="current_provider_price">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-tachometer-alt"></i> Current Speed (Mbps)</label>
                                <input type="number" min="0" id="current_provider_speed" name="current_provider_speed">
                            </div>
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-exclamation-triangle"></i> Pain Point</label>
                                <textarea id="pain_point" name="pain_point" rows="2"></textarea>
                            </div>
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-exchange-alt"></i> Reason to Switch</label>
                                <textarea id="reason_to_switch" name="reason_to_switch" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hourglass-half"></i> Decision Timeline</label>
                                <select id="decision_timeline" name="decision_timeline">
                                    <option value="">— Select —</option>
                                    <option value="immediate">Immediate</option>
                                    <option value="1_month">1 Month</option>
                                    <option value="3_months">3 Months</option>
                                    <option value="exploring">Just Exploring</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-arrow-down"></i> Budget Min</label>
                                <input type="number" step="0.01" min="0" id="budget_min" name="budget_min">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-arrow-up"></i> Budget Max</label>
                                <input type="number" step="0.01" min="0" id="budget_max" name="budget_max">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-laptop-house"></i> Usage Type</label>
                                <select id="usage_type" name="usage_type">
                                    <option value="">— Select —</option>
                                    <option value="streaming">Streaming</option>
                                    <option value="gaming">Gaming</option>
                                    <option value="wfh">Work from Home</option>
                                    <option value="business">Business</option>
                                    <option value="mixed">Mixed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-users"></i> Concurrent Users</label>
                                <input type="number" id="concurrent_users" name="concurrent_users" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-file-invoice-dollar"></i> Quote &amp; Engagement</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-file-pdf"></i> Quote PDF Path</label>
                                <input type="text" id="quote_pdf_path" name="quote_pdf_path" placeholder="uploads/quotes/...">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-times"></i> Quote Expiry</label>
                                <input type="date" id="quote_expiry_date" name="quote_expiry_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-percent"></i> Discount Offered %</label>
                                <input type="number" step="0.01" min="0" max="100" id="discount_offered_pct" name="discount_offered_pct" value="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-gift"></i> Referral Amount Promised</label>
                                <input type="number" step="0.01" min="0" id="referral_amount_promised" name="referral_amount_promised">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-star-half-alt"></i> Lead Score (0-100)</label>
                                <input type="number" min="0" max="100" id="lead_score" name="lead_score" value="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-thermometer-half"></i> Temperature *</label>
                                <select id="temperature" name="temperature" required>
                                    <option value="hot">Hot</option>
                                    <option value="warm" selected>Warm</option>
                                    <option value="cold">Cold</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-times-circle"></i> Disqualification Reason</label>
                                <select id="disqualification_reason" name="disqualification_reason" disabled>
                                    <option value="">— Select —</option>
                                    <option value="no_coverage">No Coverage</option>
                                    <option value="no_budget">No Budget</option>
                                    <option value="competitor_locked">Competitor Locked</option>
                                    <option value="duplicate">Duplicate</option>
                                    <option value="not_interested">Not Interested</option>
                                    <option value="no_response">No Response</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fab fa-whatsapp"></i> WhatsApp Consent</label>
                                <label class="status-toggle">
                                    <input type="checkbox" id="whatsapp_consent" name="whatsapp_consent" value="1">
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope-open"></i> Email Consent</label>
                                <label class="status-toggle">
                                    <input type="checkbox" id="email_consent" name="email_consent" value="1">
                                    <span class="track"></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-sticky-note"></i> Notes</div>
                        <div class="form-group form-group-full">
                            <label><i class="fas fa-edit"></i> Notes</label>
                            <textarea id="notes" name="notes" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Lead View Modal (read-only, tabbed) -->
    <div class="modal-overlay" id="leadViewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Lead Details</h3>
                <button class="close-btn" onclick="closeLeadView()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="leadViewBody"></div>
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
    // lazy export deps
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
    let leadsTable;
    let isEditMode = false;
    let leadsData  = [];
    let areasCache    = [];
    let packagesCache = [];
    let staffCache    = [];
    let custCache     = [];
    let activeStage   = 'all';   // chevron status
    const ROLE   = <?php echo json_encode($role); ?>;
    const CAN_DELETE  = <?php echo can('leads', 'd') ? 'true' : 'false'; ?>;
    const CAN_CONVERT = <?php echo canManageCustomers() ? 'true' : 'false'; ?>;

    $(document).ready(function() {
        // SD wiring — modal (contact section)
        new SearchableDropdown({ selectId: 'area_id',               placeholder: '— Select area —',     icon: 'fas fa-map-marked-alt' });
        new SearchableDropdown({ selectId: 'package_interested_id', placeholder: '— Select package —',  icon: 'fas fa-box' });
        new SearchableDropdown({ selectId: 'source',                placeholder: 'Source...',           icon: 'fas fa-bullhorn',     allowClear: false });
        new SearchableDropdown({ selectId: 'referral_customer_id',  placeholder: '— Select customer —', icon: 'fas fa-user-friends' });
        new SearchableDropdown({ selectId: 'status',                placeholder: 'Status...',           icon: 'fas fa-flag',         allowClear: false });
        new SearchableDropdown({ selectId: 'priority',              placeholder: 'Priority...',         icon: 'fas fa-bolt',         allowClear: false });
        new SearchableDropdown({ selectId: 'owner_user_id',         placeholder: '— Unassigned —',      icon: 'fas fa-user-tie' });

        // SD wiring — qualification
        new SearchableDropdown({ selectId: 'customer_type',         placeholder: 'Customer type...',    icon: 'fas fa-user-tag',     allowClear: false });
        new SearchableDropdown({ selectId: 'feasibility_status',    placeholder: 'Feasibility...',      icon: 'fas fa-check-circle', allowClear: false });
        new SearchableDropdown({ selectId: 'surveyed_by_user_id',   placeholder: '— Not Yet —',         icon: 'fas fa-user-md' });

        // SD wiring — competitor/budget
        new SearchableDropdown({ selectId: 'decision_timeline',     placeholder: '— Select —',          icon: 'fas fa-hourglass-half' });
        new SearchableDropdown({ selectId: 'usage_type',            placeholder: '— Select —',          icon: 'fas fa-laptop-house' });

        // SD wiring — quote/engagement
        new SearchableDropdown({ selectId: 'temperature',           placeholder: 'Temperature...',      icon: 'fas fa-thermometer-half', allowClear: false });
        new SearchableDropdown({ selectId: 'disqualification_reason', placeholder: '— Select —',        icon: 'fas fa-times-circle' });

        // SD wiring — filters
        new SearchableDropdown({ selectId: 'filterPriority', placeholder: 'All Priorities', icon: 'fas fa-bolt' });
        new SearchableDropdown({ selectId: 'filterSource',   placeholder: 'All Sources',    icon: 'fas fa-bullhorn' });
        new SearchableDropdown({ selectId: 'filterArea',     placeholder: 'All Areas',      icon: 'fas fa-map-marked-alt' });
        new SearchableDropdown({ selectId: 'filterOwner',    placeholder: 'All Owners',     icon: 'fas fa-user-tie' });

        // source -> referral picker enable/disable
        $('#source').on('change', function() {
            const isRef = this.value === 'referral';
            const ref = document.getElementById('referral_customer_id');
            ref.disabled = !isRef;
            if (!isRef) ref.value = '';
            ref._sd && ref._sd.refresh();
        });

        // customer_type -> business_name visibility
        $('#customer_type').on('change', toggleLeadBusinessNameVis);

        // status -> disqualification_reason enable/disable (only for lost/dropped)
        $('#status').on('change', toggleDisqReasonVis);

        // chevron pipeline click → filter by status
        $(document).on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('.chevron-tab').removeClass('active');
            $(this).addClass('active');
            activeStage = $(this).data('status') || 'all';
            applyFilters();
        });

        // filter changes
        $('#filterPriority, #filterSource, #filterArea, #filterOwner, #filterFollowup').on('change', applyFilters);
        $('#filterSearch').on('input', applyFilters);

        // bootstrap loads
        loadLookups(() => loadAll());
        rsImport.init({ entity: 'Lead', onDone: loadAll });
    });

    function toggleLeadBusinessNameVis() {
        const ct = document.getElementById('customer_type').value;
        const grp = document.getElementById('grpLeadBusinessName');
        if (!grp) return;
        if (ct === 'residential') grp.classList.add('initially-hidden');
        else grp.classList.remove('initially-hidden');
    }

    function toggleDisqReasonVis() {
        const st = document.getElementById('status').value;
        const dr = document.getElementById('disqualification_reason');
        if (!dr) return;
        const isLostOrDropped = st === 'lost' || st === 'dropped';
        dr.disabled = !isLostOrDropped;
        if (!isLostOrDropped) dr.value = '';
        dr._sd && dr._sd.refresh();
    }

    function loadLookups(after) {
        let pending = 4;
        const done = () => { if (--pending === 0 && after) after(); };

        $.getJSON('?action=getAreas', function(resp) {
            if (resp.success) {
                areasCache = resp.data;
                fillSelect('area_id',     '— Select area —', resp.data, 'id', 'name');
                fillSelect('filterArea',  'All Areas',       resp.data, 'id', 'name');
            }
            done();
        });
        $.getJSON('?action=getPackages', function(resp) {
            if (resp.success) {
                packagesCache = resp.data;
                fillSelect('package_interested_id', '— Select package —', resp.data, 'id', p => p.name + ' — Rs.' + p.price + ' (' + p.speed_mbps + ' Mbps)');
            }
            done();
        });
        $.getJSON('?action=getStaff', function(resp) {
            if (resp.success) {
                staffCache = resp.data;
                fillSelect('owner_user_id',       '— Unassigned —', resp.data, 'id', s => s.name + ' (' + s.role.replace('_',' ') + ')');
                fillSelect('surveyed_by_user_id', '— Not Yet —',    resp.data, 'id', s => s.name + ' (' + s.role.replace('_',' ') + ')');
                fillSelect('filterOwner',         'All Owners',     resp.data, 'id', 'name');
            }
            done();
        });
        $.getJSON('?action=getActiveCustomers', function(resp) {
            if (resp.success) {
                custCache = resp.data;
                fillSelect('referral_customer_id', '— Select customer —', resp.data, 'id', c => c.customer_code + ' — ' + c.full_name);
            }
            done();
        });
    }

    function fillSelect(id, placeholder, data, valKey, labelKey) {
        const el = document.getElementById(id);
        if (!el) return;
        const labelFn = (typeof labelKey === 'function') ? labelKey : (r) => r[labelKey];
        el.innerHTML = '<option value="">' + escapeHtml(placeholder) + '</option>' +
            data.map(r => `<option value="${r[valKey]}">${escapeHtml(labelFn(r))}</option>`).join('');
        el._sd && el._sd.refresh();
    }

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }

    function loadAll() {
        loadPipeline();
        loadLeads();
    }

    function loadPipeline() {
        $.getJSON('?action=getPipelineCounts', function(resp) {
            if (!resp.success) return;
            const c = resp.counts;
            ['all','new','contacted','quoted','negotiating','won','lost','dropped'].forEach(s => {
                const el = document.getElementById('cnt-' + s);
                if (el) el.textContent = c[s] ?? 0;
            });
        });
    }

    function loadLeads() {
        $('#skeletonWrap').show();
        $('#tableWrap').hide();
        $('#emptyState').hide();
        $.ajax({
            url: '?action=getLeads', method: 'GET', dataType: 'json',
            success: function(resp) {
                $('#skeletonWrap').hide();
                if (resp.success) {
                    leadsData = resp.data;
                    $('#filtersSection').show();
                    if (!resp.data.length) {
                        $('#emptyState').show();
                    } else {
                        $('#tableWrap').show();
                        initializeDataTable(resp.data);
                    }
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load leads' });
                }
            },
            error: function(xhr, status, error) {
                $('#skeletonWrap').hide();
                console.error('AJAX Error:', error);
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
            }
        });
    }

    function fmtCurrency(v) {
        if (v === null || v === undefined || v === '') return '<span class="muted">—</span>';
        return 'Rs. ' + Number(v).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fmtDate(d) {
        if (!d) return '<span class="muted">—</span>';
        try {
            const dt = new Date(d);
            return dt.toLocaleDateString('en-PK', { year: 'numeric', month: 'short', day: '2-digit' });
        } catch(e) { return d; }
    }

    function phoneLinks(phone) {
        const safe = escapeHtml(phone);
        const wa = String(phone).replace(/\D+/g, '');
        return `<a href="tel:${safe}">${safe}</a> ` +
               `<a href="https://wa.me/${wa}" target="_blank" title="WhatsApp" style="color:#16a34a;margin-left:4px;"><i class="fab fa-whatsapp"></i></a>`;
    }

    function actionsCell(row) {
        // primary: View + Convert (pipeline goal)
        let html = `<button class="action-icon view-icon" onclick="viewLead(${row.id})" title="View"><i class="fas fa-eye"></i></button>`;
        const canConvert = CAN_CONVERT && row.status === 'won' && !row.converted_customer_id;
        if (canConvert) {
            html += ` <button class="action-icon view-icon" onclick="convertLead(${row.id})" title="Convert to Customer"><i class="fas fa-user-plus"></i></button>`;
        }
        // ⋮ more menu
        let menu = `<button class="act-more-item" onclick="editLead(${row.id})"><i class="fas fa-edit"></i> Edit</button>`;
        if (['quoted','negotiating'].includes(row.status)) {
            menu += `<button class="act-more-item" onclick="markWon(${row.id})"><i class="fas fa-trophy"></i> Mark Won</button>`;
        }
        if (!['won','lost','dropped'].includes(row.status)) {
            menu += `<button class="act-more-item" onclick="markLost(${row.id})"><i class="fas fa-times-circle"></i> Mark Lost</button>`;
        }
        if (row.converted_customer_id) {
            menu += `<a class="act-more-item" href="customers.php?id=${row.converted_customer_id}"><i class="fas fa-external-link-alt"></i> View Customer</a>`;
        }
        if (CAN_DELETE) {
            menu += `<button class="act-more-item danger" onclick="deleteLead(${row.id})"><i class="fas fa-trash"></i> Delete</button>`;
        }
        html += ` <div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">${menu}</div></div>`;
        return html;
    }

    function initializeDataTable(data) {
        if (leadsTable) {
            leadsTable.destroy();
            $('#leadsTable').empty();
        }
        setTimeout(() => {
            leadsTable = $('#leadsTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    {
                        data: 'lead_code', title: 'Code',
                        render: (d, t, row) => d ? `<a href="javascript:void(0)" style="font-weight:700;color:#0074D9" onclick="editLead(${row.id})">${escapeHtml(d)}</a>` : '<span class="muted">—</span>'
                    },
                    {
                        data: 'full_name', title: 'Name',
                        render: (d, t, row) => `<a href="javascript:void(0)" onclick="editLead(${row.id})">${escapeHtml(d)}</a>`
                    },
                    {
                        data: 'phone', title: 'Phone',
                        render: (d) => phoneLinks(d)
                    },
                    {
                        data: 'area_name', title: 'Area',
                        render: (d) => d ? escapeHtml(d) : '<span class="muted">—</span>'
                    },
                    {
                        data: 'package_name', title: 'Package',
                        render: (d) => d ? escapeHtml(d) : '<span class="muted">—</span>'
                    },
                    {
                        data: 'source', title: 'Source',
                        render: (d) => `<span class="lead-source ${escapeHtml(d)}">${escapeHtml(d.replace('_',' '))}</span>`
                    },
                    {
                        data: 'status', title: 'Status',
                        render: (d) => `<span class="lead-status ${escapeHtml(d)}">${escapeHtml(d)}</span>`
                    },
                    {
                        data: 'priority', title: 'Priority',
                        render: (d) => `<span class="lead-priority ${escapeHtml(d)}">${escapeHtml(d)}</span>`
                    },
                    {
                        data: 'owner_name', title: 'Owner',
                        render: (d) => d ? escapeHtml(d) : '<span class="muted">—</span>'
                    },
                    {
                        data: 'quote_amount', title: 'Quote',
                        className: 'num-cell',
                        render: (d) => fmtCurrency(d)
                    },
                    {
                        data: 'next_followup_date', title: 'Next Followup',
                        render: (d, t, row) => {
                            if (!d) return '<span class="muted">—</span>';
                            const txt = fmtDate(d);
                            return row.next_followup_overdue
                                ? `<span style="color:#ef4444;font-weight:600;"><i class="fas fa-exclamation-circle"></i> ${txt}</span>`
                                : txt;
                        }
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: (d, t, row) => actionsCell(row)
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
                order: []
            });
            // re-apply current filters after rebuild
            applyFilters();
        }, 100);
    }

    function applyFilters() {
        if (!leadsTable) return;
        $.fn.dataTable.ext.search = [];
        const priority = document.getElementById('filterPriority').value;
        const source   = document.getElementById('filterSource').value;
        const area     = document.getElementById('filterArea').value;
        const owner    = document.getElementById('filterOwner').value;
        const search   = (document.getElementById('filterSearch').value || '').trim().toLowerCase();
        const followup = document.getElementById('filterFollowup').checked;

        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const r = leadsData[dataIndex];
            if (!r) return true;
            if (activeStage !== 'all' && r.status !== activeStage) return false;
            if (priority && r.priority !== priority) return false;
            if (source && r.source !== source) return false;
            if (area && String(r.area_id || '') !== String(area)) return false;
            if (owner && String(r.owner_user_id || '') !== String(owner)) return false;
            if (followup && !r.next_followup_overdue) return false;
            if (search) {
                const hay = (r.full_name + ' ' + r.phone + ' ' + (r.email || '') + ' ' + (r.lead_code || '')).toLowerCase();
                if (!hay.includes(search)) return false;
            }
            return true;
        });
        leadsTable.draw();
    }

    function clearFilters() {
        ['filterPriority','filterSource','filterArea','filterOwner'].forEach(id => {
            const el = document.getElementById(id);
            el.value = '';
            el._sd && el._sd.refresh();
        });
        document.getElementById('filterSearch').value = '';
        document.getElementById('filterFollowup').checked = false;
        activeStage = 'all';
        $('.chevron-tab').removeClass('active');
        $('.chevron-tab[data-status="all"]').addClass('active');
        if (leadsTable) {
            $.fn.dataTable.ext.search = [];
            leadsTable.draw();
        }
    }

    const _leadSDIds = [
        'area_id','package_interested_id','source','referral_customer_id','status','priority','owner_user_id',
        'customer_type','feasibility_status','surveyed_by_user_id',
        'decision_timeline','usage_type',
        'temperature','disqualification_reason'
    ];

    function refreshAllLeadSD() {
        _leadSDIds.forEach(id => {
            const el = document.getElementById(id);
            if (el && el._sd) el._sd.refresh();
        });
    }

    // read-only tabbed lead view
    function viewLead(id) {
        $.getJSON('?action=getLeadDetail&id=' + id, function(resp) {
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed to load lead' }); return; }
            const l = resp.lead;
            const cap = s => s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g,' ') : s;
            const dash = v => (v === null || v === undefined || v === '') ? '<span class="muted">—</span>' : escapeHtml(v);

            const area    = l.area_name ? escapeHtml(l.area_name) : '<span class="muted">—</span>';
            const pkg     = l.package_name ? escapeHtml(l.package_name) : '<span class="muted">—</span>';
            const owner   = l.owner_name ? escapeHtml(l.owner_name) : '<span class="muted">—</span>';
            const notes   = l.notes ? escapeHtml(l.notes).replace(/\n/g,'<br>') : '<span class="muted">—</span>';
            const quote   = (l.quote_amount !== null && l.quote_amount !== undefined && l.quote_amount !== '') ? fmtCurrency(l.quote_amount) : '<span class="muted">—</span>';

            // timeline rows — created → won/lost → converted
            let tl = `<tr><td><i class="fas fa-plus-circle"></i> Created</td><td>${fmtDate(l.created_at)}</td><td>${dash(l.lead_code)}</td></tr>`;
            if (l.status === 'won') {
                tl += `<tr><td class="ledger-credit"><i class="fas fa-trophy"></i> Won</td><td>${fmtDate(l.last_contacted_at || l.updated_at)}</td><td>${owner}</td></tr>`;
            } else if (l.status === 'lost' || l.status === 'dropped') {
                tl += `<tr><td class="ledger-debit"><i class="fas fa-times-circle"></i> ${cap(l.status)}</td><td>${fmtDate(l.last_contacted_at || l.updated_at)}</td><td>${dash(l.lost_reason)}</td></tr>`;
            }
            if (l.converted_customer_id) {
                tl += `<tr><td class="ledger-credit"><i class="fas fa-user-plus"></i> Converted</td><td>${fmtDate(l.converted_at)}</td><td>${dash(l.converted_customer_code)}</td></tr>`;
            }

            const body = `
                <div class="vtab-nav">
                    <button type="button" class="vtab-btn active" data-vtab="lvInfo"><i class="fas fa-info-circle"></i> Lead Info</button>
                    <button type="button" class="vtab-btn" data-vtab="lvTimeline"><i class="fas fa-stream"></i> Timeline</button>
                </div>
                <div class="vtab-pane active" id="lvInfo">
                    <div class="vtab-grid">
                        <div class="vtab-field"><label><i class="fas fa-id-badge"></i> Lead Code</label><span>${dash(l.lead_code)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-user"></i> Full Name</label><span>${dash(l.full_name)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-phone"></i> Phone</label><span>${dash(l.phone)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-phone-alt"></i> Alternate Phone</label><span>${dash(l.alternate_phone)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-envelope"></i> Email</label><span>${dash(l.email)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-map-marker-alt"></i> Area</label><span>${area}</span></div>
                        <div class="vtab-field vtab-field-full"><label><i class="fas fa-location-dot"></i> Address</label><span>${dash(l.address)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-box"></i> Package Interested</label><span>${pkg}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-bullhorn"></i> Source</label><span>${escapeHtml(cap(l.source))}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-flag"></i> Status</label><span><span class="lead-status ${escapeHtml(l.status)}">${escapeHtml(cap(l.status))}</span></span></div>
                        <div class="vtab-field"><label><i class="fas fa-exclamation"></i> Priority</label><span>${escapeHtml(cap(l.priority))}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-thermometer-half"></i> Temperature</label><span>${escapeHtml(cap(l.temperature || 'warm'))}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-star"></i> Lead Score</label><span>${Number(l.lead_score || 0)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-user-tie"></i> Owner</label><span>${owner}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-money-bill-wave"></i> Quote Amount</label><span>${quote}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-calendar-check"></i> Expected Install</label><span>${fmtDate(l.expected_install_date)}</span></div>
                        <div class="vtab-field"><label><i class="fas fa-calendar-day"></i> Next Follow-up</label><span>${fmtDate(l.next_followup_date)}</span></div>
                        <div class="vtab-field vtab-field-full"><label><i class="fas fa-sticky-note"></i> Notes</label><span>${notes}</span></div>
                    </div>
                </div>
                <div class="vtab-pane" id="lvTimeline">
                    <table class="ledger-table">
                        <thead><tr><th>Stage</th><th>Date</th><th>Detail</th></tr></thead>
                        <tbody>${tl}</tbody>
                    </table>
                </div>
            `;
            document.getElementById('leadViewBody').innerHTML = body;
            wireVtabs(document.getElementById('leadViewBody'));
            document.getElementById('leadViewModal').classList.add('active');
        }).fail(() => {
            Swal.fire({ icon:'error', title:'Error', text:'Failed to load lead detail' });
        });
    }

    function closeLeadView() {
        document.getElementById('leadViewModal').classList.remove('active');
    }
    document.getElementById('leadViewModal').addEventListener('click', function(e) {
        if (e.target === this) closeLeadView();
    });

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

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Lead';
        document.getElementById('leadForm').reset();
        document.getElementById('leadId').value = '';
        document.getElementById('lead_code_disp').value = '';
        // defaults
        document.getElementById('source').value             = 'call';
        document.getElementById('status').value             = 'new';
        document.getElementById('priority').value           = 'medium';
        document.getElementById('customer_type').value      = 'residential';
        document.getElementById('feasibility_status').value = 'needs_survey';
        document.getElementById('temperature').value        = 'warm';
        document.getElementById('lead_score').value         = 0;
        document.getElementById('discount_offered_pct').value = 0;
        // referral disabled, disq disabled
        const ref = document.getElementById('referral_customer_id');
        ref.disabled = true; ref.value = '';
        const dr = document.getElementById('disqualification_reason');
        dr.disabled = true; dr.value = '';
        refreshAllLeadSD();
        toggleLeadBusinessNameVis();
        toggleDisqReasonVis();
        document.getElementById('leadModal').classList.add('active');
    }

    function editLead(id) {
        const row = leadsData.find(x => x.id === id);
        if (!row) return;
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Lead';

        // fetch full detail (tier-2 fields not in row)
        $.getJSON('?action=getLeadDetail&id=' + id, function(resp) {
            if (!resp.success) {
                Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed to load lead' });
                return;
            }
            const c = resp.lead;
            document.getElementById('leadId').value             = c.id;
            document.getElementById('lead_code_disp').value     = c.lead_code || '';
            document.getElementById('full_name').value          = c.full_name || '';
            document.getElementById('phone').value              = c.phone || '';
            document.getElementById('alternate_phone').value    = c.alternate_phone || '';
            document.getElementById('email').value              = c.email || '';
            document.getElementById('address').value            = c.address || '';
            document.getElementById('area_id').value            = c.area_id || '';
            document.getElementById('package_interested_id').value = c.package_interested_id || '';
            document.getElementById('source').value             = c.source || 'call';
            document.getElementById('referral_customer_id').value = c.referral_customer_id || '';
            document.getElementById('status').value             = c.status || 'new';
            document.getElementById('priority').value           = c.priority || 'medium';
            document.getElementById('owner_user_id').value      = c.owner_user_id || '';
            document.getElementById('expected_install_date').value = c.expected_install_date || '';
            document.getElementById('quote_amount').value       = c.quote_amount != null ? c.quote_amount : '';
            document.getElementById('next_followup_date').value = c.next_followup_date || '';
            document.getElementById('last_contacted_at').value  = c.last_contacted_at ? c.last_contacted_at.replace(' ', 'T').substring(0,16) : '';
            document.getElementById('notes').value              = c.notes || '';

            // qualification
            document.getElementById('customer_type').value          = c.customer_type || 'residential';
            document.getElementById('business_name').value          = c.business_name || '';
            document.getElementById('number_of_employees').value    = c.number_of_employees != null ? c.number_of_employees : '';
            document.getElementById('gps_lat').value                = c.gps_lat != null ? c.gps_lat : '';
            document.getElementById('gps_lng').value                = c.gps_lng != null ? c.gps_lng : '';
            document.getElementById('feasibility_status').value     = c.feasibility_status || 'needs_survey';
            document.getElementById('feasibility_notes').value      = c.feasibility_notes || '';
            document.getElementById('survey_required').checked      = !!Number(c.survey_required);
            document.getElementById('survey_date').value            = c.survey_date || '';
            document.getElementById('surveyed_by_user_id').value    = c.surveyed_by_user_id || '';
            document.getElementById('survey_result').value          = c.survey_result || '';
            document.getElementById('installation_distance_meters').value = c.installation_distance_meters != null ? c.installation_distance_meters : '';

            // competitor/budget
            document.getElementById('current_provider').value        = c.current_provider || '';
            document.getElementById('current_provider_price').value  = c.current_provider_price != null ? c.current_provider_price : '';
            document.getElementById('current_provider_speed').value  = c.current_provider_speed != null ? c.current_provider_speed : '';
            document.getElementById('pain_point').value              = c.pain_point || '';
            document.getElementById('reason_to_switch').value        = c.reason_to_switch || '';
            document.getElementById('decision_timeline').value       = c.decision_timeline || '';
            document.getElementById('budget_min').value              = c.budget_min != null ? c.budget_min : '';
            document.getElementById('budget_max').value              = c.budget_max != null ? c.budget_max : '';
            document.getElementById('usage_type').value              = c.usage_type || '';
            document.getElementById('concurrent_users').value        = c.concurrent_users != null ? c.concurrent_users : '';

            // quote/engagement
            document.getElementById('quote_pdf_path').value           = c.quote_pdf_path || '';
            document.getElementById('quote_expiry_date').value        = c.quote_expiry_date || '';
            document.getElementById('discount_offered_pct').value     = Number(c.discount_offered_pct || 0);
            document.getElementById('referral_amount_promised').value = c.referral_amount_promised != null ? c.referral_amount_promised : '';
            document.getElementById('lead_score').value               = Number(c.lead_score || 0);
            document.getElementById('temperature').value              = c.temperature || 'warm';
            document.getElementById('disqualification_reason').value  = c.disqualification_reason || '';
            document.getElementById('whatsapp_consent').checked       = !!Number(c.whatsapp_consent);
            document.getElementById('email_consent').checked          = !!Number(c.email_consent);

            // ref picker enable based on source
            const ref = document.getElementById('referral_customer_id');
            ref.disabled = (c.source !== 'referral');

            refreshAllLeadSD();
            toggleLeadBusinessNameVis();
            toggleDisqReasonVis();
            document.getElementById('leadModal').classList.add('active');
        }).fail(() => {
            Swal.fire({ icon:'error', title:'Error', text:'Failed to load lead detail' });
        });
    }

    function closeModal() {
        document.getElementById('leadModal').classList.remove('active');
        document.getElementById('leadForm').reset();
    }

    document.getElementById('leadModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.getElementById('leadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const action = isEditMode ? 'updateLead' : 'addLead';
        const newStatus = fd.get('status');

        // status = lost/dropped via main form → ask for reason inline (use markLost path after save for lost)
        // For simplicity: block direct save to lost/dropped without reason — guide user to action button
        if ((newStatus === 'lost' || newStatus === 'dropped') && isEditMode) {
            const id = fd.get('id');
            Swal.fire({
                icon: 'warning',
                title: 'Use Mark Lost button',
                text: 'To capture the lost reason, please save other fields first then use the Mark Lost action button.',
                confirmButtonText: 'OK'
            });
            return;
        }

        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=' + action, method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeModal();
                    setTimeout(() => loadAll(), 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
            }
        });
    });

    function markWon(id) {
        const row = leadsData.find(x => x.id === id);
        Swal.fire({
            icon: 'question',
            title: 'Mark as Won?',
            text: row ? `Mark "${row.full_name}" as won?` : 'Mark this lead as won?',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-trophy"></i> Mark Won',
            confirmButtonColor: '#16a34a'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=markWon', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: (resp) => {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                }
            });
        });
    }

    function markLost(id) {
        const row = leadsData.find(x => x.id === id);
        Swal.fire({
            icon: 'warning',
            title: 'Mark as Lost?',
            html: `<p style="margin-bottom:10px;">${row ? escapeHtml(row.full_name) : ''}</p>`,
            input: 'textarea',
            inputLabel: 'Lost reason (required)',
            inputPlaceholder: 'Why was this lead lost?',
            inputAttributes: { maxlength: 255 },
            showCancelButton: true,
            confirmButtonText: 'Mark Lost',
            confirmButtonColor: '#ef4444',
            inputValidator: (v) => (!v || !v.trim()) ? 'Reason is required' : null
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', id);
            fd.append('lost_reason', r.value.trim());
            $.ajax({
                url: '?action=markLost', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: (resp) => {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                }
            });
        });
    }

    function deleteLead(id) {
        const row = leadsData.find(x => x.id === id);
        Swal.fire({
            icon: 'warning',
            title: 'Delete Lead?',
            text: row ? `Delete "${row.full_name}"? This is a soft delete.` : 'Delete this lead?',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#ea4335'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteLead', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: (resp) => {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(() => loadAll(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                }
            });
        });
    }

    // ── CONVERT TO CUSTOMER ──────────────────────────────────────────────
    function convertLead(id) {
        const lead = leadsData.find(x => x.id === id);
        if (!lead) return;
        if (lead.status !== 'won') {
            Swal.fire({ icon: 'info', title: 'Mark Won First', text: 'Only leads marked "won" can be converted.' });
            return;
        }

        // build pre-filled form HTML
        const today = new Date().toISOString().slice(0,10);
        const areaOpts = areasCache.map(a => `<option value="${a.id}" ${a.id === lead.area_id ? 'selected' : ''}>${escapeHtml(a.name)}</option>`).join('');
        const pkgOpts  = packagesCache.map(p => `<option value="${p.id}" ${p.id === lead.package_interested_id ? 'selected' : ''}>${escapeHtml(p.name + ' — Rs.' + p.price + ' (' + p.speed_mbps + ' Mbps)')}</option>`).join('');

        const html = `
            <div style="text-align:left;">
                <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name *</label>
                        <input type="text" id="conv_full_name" class="swal2-input" value="${escapeHtml(lead.full_name || '')}" style="width:100%;margin:0;">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone *</label>
                        <input type="text" id="conv_phone" class="swal2-input" value="${escapeHtml(lead.phone || '')}" style="width:100%;margin:0;">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone-alt"></i> Alt Phone</label>
                        <input type="text" id="conv_alternate_phone" class="swal2-input" value="${escapeHtml(lead.alternate_phone || '')}" style="width:100%;margin:0;">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" id="conv_email" class="swal2-input" value="${escapeHtml(lead.email || '')}" style="width:100%;margin:0;">
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label><i class="fas fa-map-marker-alt"></i> Address *</label>
                        <textarea id="conv_address" class="swal2-textarea" style="width:100%;margin:0;">${escapeHtml(lead.address || '')}</textarea>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-id-card"></i> CNIC</label>
                        <input type="text" id="conv_cnic" class="swal2-input" placeholder="42101-1234567-8" style="width:100%;margin:0;">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-map-marked-alt"></i> Area *</label>
                        <select id="conv_area_id" class="swal2-select" style="width:100%;margin:0;">
                            <option value="">— Select area —</option>
                            ${areaOpts}
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-box"></i> Package *</label>
                        <select id="conv_package_id" class="swal2-select" style="width:100%;margin:0;">
                            <option value="">— Select package —</option>
                            ${pkgOpts}
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-day"></i> Installation Date *</label>
                        <input type="date" id="conv_installation_date" class="swal2-input" value="${lead.expected_install_date || today}" style="width:100%;margin:0;">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-check"></i> Monthly Due Day (1-28)</label>
                        <input type="number" id="conv_monthly_due_day" class="swal2-input" value="1" min="1" max="28" style="width:100%;margin:0;">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-network-wired"></i> IP Address</label>
                        <input type="text" id="conv_ip_address" class="swal2-input" placeholder="192.168.1.x" style="width:100%;margin:0;">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-microchip"></i> MAC Address</label>
                        <input type="text" id="conv_mac_address" class="swal2-input" placeholder="AA:BB:CC:DD:EE:FF" style="width:100%;margin:0;">
                    </div>
                </div>
            </div>
        `;

        Swal.fire({
            title: '<i class="fas fa-user-plus"></i> Convert to Customer',
            html: html,
            width: 800,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Convert',
            confirmButtonColor: '#0074D9',
            focusConfirm: false,
            didOpen: () => {
                try {
                    new SearchableDropdown({ selectId: 'conv_area_id',    placeholder: '— Select area —',    icon: 'fas fa-map-marked-alt', allowClear: false });
                    new SearchableDropdown({ selectId: 'conv_package_id', placeholder: '— Select package —', icon: 'fas fa-box',            allowClear: false });
                } catch (e) {}
            },
            preConfirm: () => {
                const get = (id) => (document.getElementById(id).value || '').trim();
                const data = {
                    full_name:         get('conv_full_name'),
                    phone:             get('conv_phone'),
                    alternate_phone:   get('conv_alternate_phone'),
                    email:             get('conv_email'),
                    address:           get('conv_address'),
                    cnic:              get('conv_cnic'),
                    area_id:           get('conv_area_id'),
                    package_id:        get('conv_package_id'),
                    installation_date: get('conv_installation_date'),
                    monthly_due_day:   get('conv_monthly_due_day'),
                    ip_address:        get('conv_ip_address'),
                    mac_address:       get('conv_mac_address')
                };
                if (!data.full_name || !data.phone || !data.address || !data.area_id || !data.package_id || !data.installation_date) {
                    Swal.showValidationMessage('Name, phone, address, area, package, and installation date are required');
                    return false;
                }
                return data;
            }
        }).then(r => {
            if (!r.isConfirmed) return;
            // confirm preview
            const v = r.value;
            const areaName = (areasCache.find(a => a.id == v.area_id) || {}).name || '';
            const pkg = packagesCache.find(p => p.id == v.package_id) || {};
            Swal.fire({
                icon: 'question',
                title: 'Confirm Conversion',
                html: `
                    <div style="text-align:left;font-size:14px;">
                        <p><b>Name:</b> ${escapeHtml(v.full_name)}</p>
                        <p><b>Phone:</b> ${escapeHtml(v.phone)}</p>
                        <p><b>Area:</b> ${escapeHtml(areaName)}</p>
                        <p><b>Package:</b> ${escapeHtml(pkg.name || '')} — Rs. ${pkg.price || ''}</p>
                        <p><b>Install Date:</b> ${escapeHtml(v.installation_date)}</p>
                        <p style="margin-top:10px;color:#0074D9;"><i class="fas fa-info-circle"></i> A new CUST-XXXX will be auto-generated.</p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Yes, Convert',
                confirmButtonColor: '#0074D9'
            }).then(c => {
                if (!c.isConfirmed) return;
                doConvert(id, v);
            });
        });
    }

    function doConvert(leadId, v) {
        const fd = new FormData();
        fd.append('lead_id', leadId);
        Object.keys(v).forEach(k => fd.append(k, v[k]));
        Swal.fire({ title: 'Converting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=convertLead', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Customer Created!',
                        text: resp.message,
                        confirmButtonText: 'View Customer',
                        showCancelButton: true,
                        cancelButtonText: 'Stay Here'
                    }).then(r => {
                        if (r.isConfirmed) {
                            window.location.href = 'customers.php?id=' + resp.customer_id;
                        } else {
                            loadAll();
                        }
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Conversion Failed', text: resp.message });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
            }
        });
    }
    </script>
</body>
</html>
