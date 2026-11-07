<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: super_admin / admin
if (!isset($_SESSION['user_id']) || !canManageUsers()) {
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
$current_page = 'users';

// rbac flags for sensitive sections
$canViewSalary   = isAdminLevel($role);   // super_admin/admin manage salary; self gets read-only on own row (handled client-side)
$canViewSecurity = isSuperAdmin($role);   // security: super_admin only
$canEditExit     = isAdminLevel($role);   // exit fields: super_admin/admin

// helpers — null-safe casts
function _ns($v) { $v = trim((string)($v ?? '')); return $v === '' ? null : $v; }
function _ni($v) { return ($v === '' || $v === null) ? null : (int)$v; }
function _nd($v) { return ($v === '' || $v === null) ? null : (float)$v; }
function _bool01($v) { return !empty($v) && $v !== '0' && $v !== 'false' ? 1 : 0; }
// validate enum vals or null
function _enum($v, $allowed) { return in_array($v, $allowed, true) ? $v : null; }
// validate iso date
function _date($v) {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}
function _datetime($v) {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $v = str_replace('T', ' ', $v);
    if (strlen($v) === 16) $v .= ':00'; // add seconds
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $v);
    return ($d && $d->format('Y-m-d H:i:s') === $v) ? $v : null;
}

// next employee_code — EMP-0001 sequence
function nextEmployeeCode($conn) {
    $r = $conn->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(employee_code,'-',-1) AS UNSIGNED)),0) AS mx
                       FROM users WHERE employee_code LIKE 'EMP-%' AND is_deleted = 0");
    $row = $r ? $r->fetch_assoc() : null;
    $next = ($row ? (int)$row['mx'] : 0) + 1;
    return 'EMP-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// area-scoped roles can cover several areas — user_areas is the map
function syncUserAreas($conn, $uid, $areaIds, $primary = null) {
    if ($primary) $areaIds[] = (int)$primary;
    $areaIds = array_values(array_unique(array_filter(array_map('intval', $areaIds))));
    $d = $conn->prepare("DELETE FROM user_areas WHERE user_id = ?");
    $d->bind_param("i", $uid);
    $d->execute();
    $d->close();
    if (!$areaIds) return;
    $i = $conn->prepare("INSERT IGNORE INTO user_areas (user_id, area_id) VALUES (?, ?)");
    foreach ($areaIds as $aid) { $i->bind_param("ii", $uid, $aid); $i->execute(); }
    $i->close();
}

// csv import/template contract for this page
function userImportSpec() {
    // role list mirrors addUser's own rule — only the owner can mint another owner
    $superOk  = isSuperAdmin();
    $roleKeys = array_values(array_map(fn($r) => $r['role_key'],
        array_filter(getRolesDetailed(), fn($r) => $superOk || !(int)$r['is_super'])));
    if (!$roleKeys) $roleKeys = [ROLE_COLLECTOR];

    return [
        'entity'  => 'User',
        'table'   => 'users',
        'perm'    => 'users',
        'unique'  => ['username', 'email'],
        'columns' => [
            'username'     => ['label' => 'Username',     'type' => 's', 'required' => true, 'max' => 50, 'demo' => 'employee1'],
            'name'         => ['label' => 'Full Name',    'type' => 's', 'required' => true, 'max' => 100, 'demo' => 'Employee 1'],
            'email'        => ['label' => 'Email',        'type' => 'email', 'required' => true, 'max' => 100, 'demo' => 'employee1@demo.com'],
            'phone'        => ['label' => 'Phone',        'type' => 's', 'required' => true, 'max' => 20, 'demo' => '03001000001'],
            'password'     => ['label' => 'Password',     'type' => 's', 'required' => true, 'demo' => 'emp123'],
            'role'         => ['label' => 'Role',         'type' => 'enum', 'required' => true, 'values' => $roleKeys, 'demo' => ROLE_COLLECTOR],
            'area_id'      => ['label' => 'Area',         'type' => 'lookup',
                                'lookup' => ['table' => 'areas', 'match' => ['name', 'area_code'], 'where' => "is_deleted = 0 AND status='active'"],
                                'demo' => 'Area 1'],
            'status'       => ['label' => 'Status',       'type' => 'enum', 'values' => ['active', 'inactive'], 'default' => 'active', 'demo' => 'active'],
            'department'   => ['label' => 'Department',   'type' => 's', 'max' => 50, 'demo' => 'Operations'],
            'designation'  => ['label' => 'Designation',  'type' => 's', 'max' => 100, 'demo' => 'Field Technician'],
            'cnic'         => ['label' => 'CNIC',         'type' => 's', 'max' => 20, 'demo' => '3520330000001'],
            'joining_date' => ['label' => 'Joining Date', 'type' => 'date', 'demo' => '2026-01-01'],
        ],
        // plain pwd -> hash here; engine never sees/stores it raw
        'before' => function (&$row, $conn, &$state) {
            if (strlen($row['password']) < 6) return 'Password must be at least 6 characters';
            $row['password'] = password_hash($row['password'], PASSWORD_DEFAULT);
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
            case 'importCsv':      rsImportRun($conn, userImportSpec());
            case 'importTemplate': rsImportTemplate(userImportSpec());

            case 'getUsers':
                // list view — only essential cols (table is wide enough)
                $sql = "SELECT u.id, u.username, u.name, u.email, u.phone, u.role, u.area_id,
                               u.status, a.name AS area_name, u.employee_code, u.cnic,
                               u.department, u.designation, u.joining_date, u.created_at,
                               u.last_login
                        FROM users u
                        LEFT JOIN areas a ON u.area_id = a.id
                        WHERE u.is_deleted = 0
                        ORDER BY u.id DESC";
                $res = $conn->query($sql);
                $users = [];
                while ($row = $res->fetch_assoc()) {
                    $users[] = [
                        'id'            => (int)$row['id'],
                        'username'      => $row['username'],
                        'name'          => $row['name'],
                        'email'         => $row['email'],
                        'phone'         => $row['phone'],
                        'role'          => $row['role'],
                        'role_label'    => getRoleDisplay($row['role']),
                        'area_id'       => $row['area_id'] ? (int)$row['area_id'] : null,
                        'area_name'     => $row['area_name'] ?? '',
                        'status'        => $row['status'],
                        'employee_code' => $row['employee_code'] ?? '',
                        'cnic'          => $row['cnic'] ?? '',
                        'department'    => $row['department'] ?? '',
                        'designation'   => $row['designation'] ?? '',
                        'joining_date'  => $row['joining_date'] ?? '',
                        // iso transport — formatted in JS
                        'last_login'    => $row['last_login'] ? date('c', strtotime($row['last_login'])) : null,
                        'created_at'    => $row['created_at'] ? date('c', strtotime($row['created_at'])) : null
                    ];
                }
                echo json_encode(['success' => true, 'data' => $users]);
                exit();

            case 'getUserDetail':
                // full detail for edit — sensitive fields filtered server-side
                $uid = (int)($_GET['id'] ?? 0);
                if ($uid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }
                $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$u) { echo json_encode(['success' => false, 'message' => 'Not found']); exit(); }

                // never leak password / 2fa secret
                unset($u['password'], $u['two_factor_secret']);

                $isSelf = ((int)$uid === (int)$user_id);
                // mask salary/statutory for non-admin viewing other users (collector/accountant cannot reach this AJAX anyway, but defensive)
                if (!$canViewSalary && !$isSelf) {
                    $u['salary_basic'] = $u['salary_allowances'] = $u['salary_total'] = null;
                    $u['salary_account_bank_name'] = $u['salary_account_number'] = null;
                    $u['ntn'] = $u['eobi_number'] = $u['pf_number'] = $u['tax_filer_status'] = null;
                }
                if (!$canViewSecurity) {
                    $u['account_locked_until'] = $u['last_password_change'] = null;
                    $u['failed_login_count']   = $u['password_must_change'] = null;
                    $u['two_factor_enabled']   = null;
                }

                $u['_can_view_salary']   = $canViewSalary || $isSelf;
                $u['_can_edit_salary']   = $canViewSalary; // self read-only
                $u['_can_view_security'] = $canViewSecurity;
                $u['_can_edit_exit']     = $canEditExit;
                $u['_is_self']           = $isSelf;

                // extra areas for area-scoped roles
                $u['assigned_areas'] = [];
                $ua = $conn->prepare("SELECT area_id FROM user_areas WHERE user_id = ?");
                $ua->bind_param("i", $uid);
                $ua->execute();
                $uar = $ua->get_result();
                while ($x = $uar->fetch_assoc()) $u['assigned_areas'][] = (int)$x['area_id'];
                $ua->close();

                echo json_encode(['success' => true, 'data' => $u]);
                exit();

            case 'getRoles':
                // matrix-driven — custom roles show up here automatically
                $rolesOut = [];
                foreach (getRolesDetailed() as $r) {
                    $rolesOut[] = [
                        'key' => $r['role_key'], 'label' => $r['label'], 'color' => $r['badge_color'],
                        'icon' => $r['icon'], 'scope' => $r['data_scope'], 'is_super' => (int)$r['is_super']
                    ];
                }
                echo json_encode(['success' => true, 'data' => $rolesOut, 'can_assign_super' => isSuperAdmin()]);
                exit();

            case 'getAreas':
                $res = $conn->query("SELECT id, name FROM areas WHERE is_deleted = 0 AND status='active' ORDER BY name ASC");
                $areas = [];
                while ($row = $res->fetch_assoc()) {
                    $areas[] = ['id' => (int)$row['id'], 'name' => $row['name']];
                }
                echo json_encode(['success' => true, 'data' => $areas]);
                exit();

            case 'getReportingTo':
                // active users for reporting_to dropdown — exclude deleted; client filters self
                $res = $conn->query("SELECT id, name, username, role FROM users WHERE is_deleted = 0 AND status='active' ORDER BY name ASC");
                $list = [];
                while ($row = $res->fetch_assoc()) {
                    $list[] = [
                        'id'    => (int)$row['id'],
                        'label' => $row['name'] . ' (' . $row['username'] . ' — ' . getRoleDisplay($row['role']) . ')'
                    ];
                }
                echo json_encode(['success' => true, 'data' => $list]);
                exit();

            case 'addUser':
            case 'updateUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $isEdit   = $_GET['action'] === 'updateUser';
                $userId   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
                $newUsername = trim($_POST['username'] ?? '');
                $name     = trim($_POST['name'] ?? '');
                $email    = trim($_POST['email'] ?? '');
                $phone    = trim($_POST['phone'] ?? '');
                $newRole  = $_POST['role'] ?? '';
                $areaId   = isset($_POST['area_id']) && $_POST['area_id'] !== '' ? (int)$_POST['area_id'] : null;
                $extraAreas = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['areas'] ?? [])))));
                $status   = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
                $password = $_POST['password'] ?? '';

                // hr fields — sanitize
                $employee_code = _ns($_POST['employee_code'] ?? '');
                $cnic          = _ns($_POST['cnic'] ?? '');
                $dob           = _date($_POST['dob'] ?? '');
                $gender        = _enum($_POST['gender'] ?? '', ['male','female','other']);
                $marital       = _enum($_POST['marital_status'] ?? '', ['single','married','divorced','widowed']);
                $blood         = _ns($_POST['blood_group'] ?? '');

                $perm_addr     = _ns($_POST['permanent_address'] ?? '');
                $curr_addr     = _ns($_POST['current_address'] ?? '');

                $ec_name       = _ns($_POST['emergency_contact_name'] ?? '');
                $ec_phone      = _ns($_POST['emergency_contact_phone'] ?? '');
                $ec_relation   = _ns($_POST['emergency_contact_relation'] ?? '');

                $qualification = _ns($_POST['qualification'] ?? '');
                $specialization= _ns($_POST['specialization'] ?? '');
                $experience    = _nd($_POST['experience_years'] ?? '');

                $joining_date  = _date($_POST['joining_date'] ?? '');
                $confirm_date  = _date($_POST['confirmation_date'] ?? '');
                $probation_end = _date($_POST['probation_end_date'] ?? '');

                $department    = _ns($_POST['department'] ?? '');
                $designation   = _ns($_POST['designation'] ?? '');
                $reporting_to  = _ni($_POST['reporting_to_user_id'] ?? '');
                $branch_id     = _ni($_POST['branch_id'] ?? '');
                $shift         = _ns($_POST['shift'] ?? '');

                // self-circular guard
                if ($isEdit && $reporting_to !== null && $reporting_to === $userId) $reporting_to = null;

                // sensitive — only if rbac allows
                $salary_basic = $salary_allow = $salary_total = null;
                $sal_bank = $sal_acc = null;
                $tax_filer = $ntn = $eobi = $pf = null;
                if ($canViewSalary) {
                    $salary_basic = _nd($_POST['salary_basic'] ?? '');
                    $salary_allow = _nd($_POST['salary_allowances'] ?? '');
                    $salary_total = _nd($_POST['salary_total'] ?? '');
                    if ($salary_total === null && ($salary_basic !== null || $salary_allow !== null)) {
                        $salary_total = (float)$salary_basic + (float)$salary_allow;
                    }
                    $sal_bank  = _ns($_POST['salary_account_bank_name'] ?? '');
                    $sal_acc   = _ns($_POST['salary_account_number'] ?? '');
                    $tax_filer = _enum($_POST['tax_filer_status'] ?? '', ['filer','non_filer','exempt']);
                    $ntn       = _ns($_POST['ntn'] ?? '');
                    $eobi      = _ns($_POST['eobi_number'] ?? '');
                    $pf        = _ns($_POST['pf_number'] ?? '');
                }

                $comm_elig = isset($_POST['commission_eligible']) ? _bool01($_POST['commission_eligible']) : 0;
                $comm_rate = _nd($_POST['commission_rate_pct'] ?? '');
                $tgt_amt   = _nd($_POST['target_monthly_amount'] ?? '');
                $tgt_cnt   = _ni($_POST['target_monthly_count'] ?? '');

                $veh_assign  = _bool01($_POST['vehicle_assigned'] ?? 0);
                $veh_num     = _ns($_POST['vehicle_number'] ?? '');
                $mob_assign  = _bool01($_POST['mobile_assigned'] ?? 0);
                $mob_num     = _ns($_POST['mobile_number_company'] ?? '');
                $lap_assign  = _bool01($_POST['laptop_assigned'] ?? 0);
                $lap_serial  = _ns($_POST['laptop_serial'] ?? '');

                // security — super_admin only
                $pw_must_change = $two_fa = null;
                $locked_until = null;
                if ($canViewSecurity) {
                    $pw_must_change = _bool01($_POST['password_must_change'] ?? 0);
                    $two_fa         = _bool01($_POST['two_factor_enabled'] ?? 0);
                    $locked_until   = _datetime($_POST['account_locked_until'] ?? '');
                }

                // exit
                $resign_date = $term_date = $reason_leave = null;
                if ($canEditExit) {
                    $resign_date  = _date($_POST['resignation_date'] ?? '');
                    $term_date    = _date($_POST['termination_date'] ?? '');
                    $reason_leave = _ns($_POST['reason_for_leaving'] ?? '');
                }

                $sig_path = _ns($_POST['signature_image_path'] ?? '');

                // validate
                if ($newUsername === '' || $name === '' || $email === '' || $phone === '' || $newRole === '') {
                    echo json_encode(['success' => false, 'message' => 'Username, name, email, phone, and role are required']);
                    exit();
                }
                if (!in_array($newRole, getAllRoles(), true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid role']);
                    exit();
                }
                // only the owner can mint another owner
                $targetRole = roleRow($newRole);
                if ($targetRole && (int)$targetRole['is_super'] === 1 && !isSuperAdmin()) {
                    echo json_encode(['success' => false, 'message' => 'Only the owner can assign the owner role']);
                    exit();
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }
                if (!$isEdit && $password === '') {
                    echo json_encode(['success' => false, 'message' => 'Password is required']);
                    exit();
                }
                if ($password !== '' && strlen($password) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                    exit();
                }

                // duplicate username check
                $dupSql = $isEdit
                    ? "SELECT id FROM users WHERE username = ? AND id != ?"
                    : "SELECT id FROM users WHERE username = ?";
                $stmt = $conn->prepare($dupSql);
                if ($isEdit) $stmt->bind_param("si", $newUsername, $userId);
                else         $stmt->bind_param("s", $newUsername);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Username already exists']);
                    exit();
                }
                $stmt->close();

                // auto-gen employee_code on insert if blank
                if (!$isEdit && $employee_code === null) {
                    $employee_code = nextEmployeeCode($conn);
                }

                // build the wide column list once — INSERT and UPDATE share it
                // cols: 32 user-editable + admin/security/exit (rbac-gated above; null when not permitted)
                $cols = [
                    'username', 'name', 'email', 'phone', 'role', 'area_id', 'status',
                    'employee_code', 'cnic', 'dob', 'gender', 'marital_status', 'blood_group',
                    'permanent_address', 'current_address',
                    'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
                    'qualification', 'specialization', 'experience_years',
                    'joining_date', 'confirmation_date', 'probation_end_date',
                    'department', 'designation', 'reporting_to_user_id', 'branch_id', 'shift',
                    'salary_basic', 'salary_allowances', 'salary_total',
                    'salary_account_bank_name', 'salary_account_number',
                    'tax_filer_status', 'ntn', 'eobi_number', 'pf_number',
                    'commission_eligible', 'commission_rate_pct',
                    'target_monthly_amount', 'target_monthly_count',
                    'vehicle_assigned', 'vehicle_number',
                    'mobile_assigned', 'mobile_number_company',
                    'laptop_assigned', 'laptop_serial',
                    'password_must_change', 'two_factor_enabled', 'account_locked_until',
                    'resignation_date', 'termination_date', 'reason_for_leaving',
                    'signature_image_path'
                ];
                // type letters per col, in sync
                // s for varchar/text/date/datetime/enum, i for int/tinyint, d for decimal
                $types_map = [
                    'username' => 's', 'name' => 's', 'email' => 's', 'phone' => 's', 'role' => 's',
                    'area_id' => 'i', 'status' => 's',
                    'employee_code' => 's', 'cnic' => 's', 'dob' => 's', 'gender' => 's',
                    'marital_status' => 's', 'blood_group' => 's',
                    'permanent_address' => 's', 'current_address' => 's',
                    'emergency_contact_name' => 's', 'emergency_contact_phone' => 's',
                    'emergency_contact_relation' => 's',
                    'qualification' => 's', 'specialization' => 's', 'experience_years' => 'd',
                    'joining_date' => 's', 'confirmation_date' => 's', 'probation_end_date' => 's',
                    'department' => 's', 'designation' => 's',
                    'reporting_to_user_id' => 'i', 'branch_id' => 'i', 'shift' => 's',
                    'salary_basic' => 'd', 'salary_allowances' => 'd', 'salary_total' => 'd',
                    'salary_account_bank_name' => 's', 'salary_account_number' => 's',
                    'tax_filer_status' => 's', 'ntn' => 's', 'eobi_number' => 's', 'pf_number' => 's',
                    'commission_eligible' => 'i', 'commission_rate_pct' => 'd',
                    'target_monthly_amount' => 'd', 'target_monthly_count' => 'i',
                    'vehicle_assigned' => 'i', 'vehicle_number' => 's',
                    'mobile_assigned' => 'i', 'mobile_number_company' => 's',
                    'laptop_assigned' => 'i', 'laptop_serial' => 's',
                    'password_must_change' => 'i', 'two_factor_enabled' => 'i',
                    'account_locked_until' => 's',
                    'resignation_date' => 's', 'termination_date' => 's', 'reason_for_leaving' => 's',
                    'signature_image_path' => 's'
                ];
                $vals = [
                    'username' => $newUsername, 'name' => $name, 'email' => $email,
                    'phone' => $phone, 'role' => $newRole, 'area_id' => $areaId, 'status' => $status,
                    'employee_code' => $employee_code, 'cnic' => $cnic, 'dob' => $dob,
                    'gender' => $gender, 'marital_status' => $marital, 'blood_group' => $blood,
                    'permanent_address' => $perm_addr, 'current_address' => $curr_addr,
                    'emergency_contact_name' => $ec_name, 'emergency_contact_phone' => $ec_phone,
                    'emergency_contact_relation' => $ec_relation,
                    'qualification' => $qualification, 'specialization' => $specialization,
                    'experience_years' => $experience,
                    'joining_date' => $joining_date, 'confirmation_date' => $confirm_date,
                    'probation_end_date' => $probation_end,
                    'department' => $department, 'designation' => $designation,
                    'reporting_to_user_id' => $reporting_to, 'branch_id' => $branch_id, 'shift' => $shift,
                    'salary_basic' => $salary_basic, 'salary_allowances' => $salary_allow,
                    'salary_total' => $salary_total,
                    'salary_account_bank_name' => $sal_bank, 'salary_account_number' => $sal_acc,
                    'tax_filer_status' => $tax_filer, 'ntn' => $ntn,
                    'eobi_number' => $eobi, 'pf_number' => $pf,
                    'commission_eligible' => $comm_elig, 'commission_rate_pct' => $comm_rate,
                    'target_monthly_amount' => $tgt_amt, 'target_monthly_count' => $tgt_cnt,
                    'vehicle_assigned' => $veh_assign, 'vehicle_number' => $veh_num,
                    'mobile_assigned' => $mob_assign, 'mobile_number_company' => $mob_num,
                    'laptop_assigned' => $lap_assign, 'laptop_serial' => $lap_serial,
                    'password_must_change' => $pw_must_change, 'two_factor_enabled' => $two_fa,
                    'account_locked_until' => $locked_until,
                    'resignation_date' => $resign_date, 'termination_date' => $term_date,
                    'reason_for_leaving' => $reason_leave,
                    'signature_image_path' => $sig_path
                ];

                // build dynamic SQL — only include cols whose val is non-null OR cols required for normalization
                // for required identity cols we always include; for nullables we only include if value present, so NULL-default applies cleanly
                $required = ['username','name','email','phone','role','status'];

                if (!$isEdit) {
                    // INSERT path
                    $insertCols = [];
                    $placeholders = [];
                    $types = '';
                    $params = [];
                    foreach ($cols as $c) {
                        $v = $vals[$c];
                        if (!in_array($c, $required, true) && $v === null) continue; // skip NULLs — let DB default
                        $insertCols[]   = $c;
                        $placeholders[] = '?';
                        $types         .= $types_map[$c];
                        $params[]       = $v;
                    }
                    // password
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $insertCols[]   = 'password';
                    $placeholders[] = '?';
                    $types         .= 's';
                    $params[]       = $hashed;
                    // last_password_change auto-set
                    $insertCols[]   = 'last_password_change';
                    $placeholders[] = 'NOW()';
                    // (no param for NOW())

                    // re-sequence placeholders text — NOW() inline, ? for the rest
                    $sqlCols = implode(',', $insertCols);
                    $sqlVals = implode(',', $placeholders);
                    $sql = "INSERT INTO users ($sqlCols) VALUES ($sqlVals)";

                    if (strlen($types) !== count($params)) {
                        echo json_encode(['success' => false, 'message' => 'Internal: type/param mismatch']);
                        exit();
                    }
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                        exit();
                    }
                    $stmt->bind_param($types, ...$params);

                    if ($stmt->execute()) {
                        $newId = $stmt->insert_id;
                        try { syncUserAreas($conn, $newId, $extraAreas, $areaId); } catch (Throwable $e) {}
                        logActivity($user_id, $username, 'User Created', "Created $newRole: $newUsername" . ($employee_code ? " ($employee_code)" : ''));
                        try { createNotificationForAdmins('User Created', 'User "' . htmlspecialchars($newUsername) . '" (' . getRoleDisplay($newRole) . ') was created.', 'info', 'users.php'); } catch (Exception $e) {}
                        $stmt->close();
                        echo json_encode(['success' => true, 'message' => 'User added successfully', 'id' => $newId]);
                    } else {
                        $err = $stmt->error;
                        $stmt->close();
                        echo json_encode(['success' => false, 'message' => 'Failed to add user: ' . $err]);
                    }
                    exit();
                }

                // UPDATE path
                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user id']);
                    exit();
                }
                $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $oldRoleRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$oldRoleRow) {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }
                $oldRole = $oldRoleRow['role'];

                // build SET clause — set every col (NULL-able cols get set to NULL when blank, that's fine on UPDATE)
                $setParts = [];
                $types = '';
                $params = [];
                foreach ($cols as $c) {
                    $setParts[] = "$c = ?";
                    $types     .= $types_map[$c];
                    $params[]   = $vals[$c];
                }

                $sql = "UPDATE users SET " . implode(', ', $setParts);
                // password (optional)
                if ($password !== '') {
                    $sql .= ", password = ?, last_password_change = NOW()";
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $types .= 's';
                    $params[] = $hashed;
                }
                $sql .= " WHERE id = ?";
                $types .= 'i';
                $params[] = $userId;

                if (strlen($types) !== count($params)) {
                    echo json_encode(['success' => false, 'message' => 'Internal: type/param mismatch']);
                    exit();
                }

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                    exit();
                }
                $stmt->bind_param($types, ...$params);

                if ($stmt->execute()) {
                    try { syncUserAreas($conn, $userId, $extraAreas, $areaId); } catch (Throwable $e) {}
                    $details = $password !== '' ? "Updated $newUsername (password changed)" : "Updated $newUsername";
                    logActivity($user_id, $username, 'User Updated', $details);
                    try {
                        if ($oldRole !== $newRole) {
                            createNotification($userId, 'Role Changed', 'Your role has been changed from ' . getRoleDisplay($oldRole) . ' to ' . getRoleDisplay($newRole) . '.', 'warning', 'account.php');
                        }
                    } catch (Exception $e) {}
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update user: ' . $err]);
                }
                exit();

            case 'clearLockout':
                // super_admin only — clear account lockout + reset failed_login_count
                if (!$canViewSecurity) {
                    echo json_encode(['success' => false, 'message' => 'Permission denied']);
                    exit();
                }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $uid = (int)($_POST['id'] ?? 0);
                if ($uid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }
                $stmt = $conn->prepare("UPDATE users SET account_locked_until = NULL, failed_login_count = 0 WHERE id = ?");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $stmt->close();
                logActivity($user_id, $username, 'Lockout Cleared', "User ID $uid");
                echo json_encode(['success' => true, 'message' => 'Lockout cleared']);
                exit();

            case 'toggleStatus':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $userId = (int)($_POST['id'] ?? 0);
                if ($userId <= 0 || $userId === (int)$_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Cannot change own status']);
                    exit();
                }
                $stmt = $conn->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $userId);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    logActivity($user_id, $username, 'User Status Toggled', "User ID $userId");
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Status updated']);
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'No change']);
                }
                exit();

            case 'deleteUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $userId = (int)($_POST['id'] ?? 0);
                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit();
                }
                if ($userId === (int)$_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
                    exit();
                }

                // grab username for log
                $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }

                // soft delete
                $stmt = $conn->prepare("UPDATE users SET is_deleted = 1, status = 'inactive' WHERE id = ?");
                $stmt->bind_param("i", $userId);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'User Deleted', "Deleted user: " . $row['username']);
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
                }
                exit();

            case 'resetPassword':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $uid = (int)($_POST['id'] ?? 0);
                $newPw = (string)($_POST['new_password'] ?? '');
                if ($uid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }
                if (strlen($newPw) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                    exit();
                }
                // grab username for log
                $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $tgt = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$tgt) { echo json_encode(['success' => false, 'message' => 'User not found']); exit(); }

                $hashed = password_hash($newPw, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ?, last_password_change = NOW() WHERE id = ?");
                $stmt->bind_param("si", $hashed, $uid);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Password Reset', "Reset password for: " . $tgt['username']);
                    try { createNotification($uid, 'Password Reset', 'An administrator reset your password. Please contact them for the new credentials.', 'warning', 'account.php'); } catch (Exception $e) {}
                    echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to reset password: ' . $err]);
                }
                exit();

            case 'getUserActivity':
                // recent activity log rows for a single user
                $uid = (int)($_GET['id'] ?? 0);
                if ($uid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }
                $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $tgt = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$tgt) { echo json_encode(['success' => false, 'message' => 'User not found']); exit(); }

                $stmt = $conn->prepare("SELECT action, details, ip_address, timestamp
                                        FROM activity_logs WHERE user_id = ?
                                        ORDER BY timestamp DESC LIMIT 30");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $r = $stmt->get_result();
                $rows = [];
                while ($row = $r->fetch_assoc()) {
                    $rows[] = [
                        'action'    => $row['action'],
                        'details'   => $row['details'] ?? '',
                        'ip'        => $row['ip_address'] ?? '',
                        'timestamp' => $row['timestamp'] ? date('c', strtotime($row['timestamp'])) : null
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'username' => $tgt['username'], 'data' => $rows]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Users.php error: " . $e->getMessage());
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
    <title>User Management - ISP Billing</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body class="page-users">
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>Staff</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-id-badge"></i> Staff Management</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Staff Directory</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadUsers()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('users', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Staff
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
                            <label><i class="fas fa-calendar-alt"></i> Date From</label>
                            <input type="date" id="filterDateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Date To</label>
                            <input type="date" id="filterDateTo" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-user-tag"></i> Role</label>
                            <select id="filterRole">
                                <option value="">All Roles</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filterStatus">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-map-marked-alt"></i> Area</label>
                            <select id="filterArea">
                                <option value="">All Areas</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-building"></i> Department</label>
                            <select id="filterDept">
                                <option value="">All Departments</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="table-scroll-hint">
                    <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                </div>
                <div class="table-responsive">
                    <table id="usersTable" class="display table-full-width">
                        <tfoot>
                            <tr>
                                <td colspan="15">
                                    <i class="fas fa-user-check"></i> Active: <strong id="footActive">0</strong>
                                    &nbsp;&nbsp;|&nbsp;&nbsp;
                                    <i class="fas fa-user-slash"></i> Inactive: <strong id="footInactive">0</strong>
                                    &nbsp;&nbsp;|&nbsp;&nbsp;
                                    <i class="fas fa-users"></i> Total Staff: <strong id="footTotal">0</strong>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- User Modal — tabbed for HR sections -->
    <div class="modal-overlay" id="userModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add Staff</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- tabs -->
                <div class="detail-tabs" id="userTabs">
                    <div class="detail-tab active" data-tab="tab-account"><i class="fas fa-key"></i> Account</div>
                    <div class="detail-tab" data-tab="tab-identity"><i class="fas fa-id-card"></i> Identity</div>
                    <div class="detail-tab" data-tab="tab-address"><i class="fas fa-home"></i> Address</div>
                    <div class="detail-tab" data-tab="tab-emergency"><i class="fas fa-phone-volume"></i> Emergency</div>
                    <div class="detail-tab" data-tab="tab-education"><i class="fas fa-graduation-cap"></i> Education</div>
                    <div class="detail-tab" data-tab="tab-employment"><i class="fas fa-briefcase"></i> Employment</div>
                    <?php if ($canViewSalary): ?>
                    <div class="detail-tab" data-tab="tab-salary"><i class="fas fa-money-check-alt"></i> Salary</div>
                    <div class="detail-tab" data-tab="tab-statutory"><i class="fas fa-balance-scale"></i> Statutory</div>
                    <?php endif; ?>
                    <div class="detail-tab" data-tab="tab-targets"><i class="fas fa-bullseye"></i> Targets</div>
                    <div class="detail-tab" data-tab="tab-assets"><i class="fas fa-laptop"></i> Assets</div>
                    <?php if ($canViewSecurity): ?>
                    <div class="detail-tab" data-tab="tab-security"><i class="fas fa-shield-alt"></i> Security</div>
                    <?php endif; ?>
                    <?php if ($canEditExit): ?>
                    <div class="detail-tab" data-tab="tab-exit"><i class="fas fa-door-open"></i> Exit</div>
                    <?php endif; ?>
                </div>

                <form id="userForm">
                    <input type="hidden" id="userId" name="id">

                    <div class="detail-tab-content" id="tab-account">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-at"></i> Username *</label>
                                <input type="text" id="username" name="username" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Full Name *</label>
                                <input type="text" id="name" name="name" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email *</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Phone *</label>
                                <input type="text" id="phone" name="phone" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Password <span id="passwordHint" class="initially-hidden">(Leave empty to keep current)</span></label>
                                <input type="password" id="password" name="password">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user-tag"></i> Role *</label>
                                <select id="role" name="role" required>
                                    <option value="">Select role...</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-map-marked-alt"></i> Service Area</label>
                                <select id="area_id" name="area_id">
                                    <option value="">— No area —</option>
                                </select>
                            </div>
                            <div class="form-group full-row initially-hidden" id="areasGroup">
                                <label><i class="fas fa-map-location-dot"></i> Assigned Areas <span class="muted">(this role only sees these areas)</span></label>
                                <div class="checkbox-group" id="areasCheckGroup"></div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-toggle-on"></i> Status *</label>
                                <select id="status" name="status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="detail-tab-content tab-hidden" id="tab-identity">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-id-badge"></i> Employee Code</label>
                                <input type="text" id="employee_code" name="employee_code" placeholder="Auto: EMP-0001">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-id-card"></i> CNIC</label>
                                <input type="text" id="cnic" name="cnic" placeholder="xxxxx-xxxxxxx-x" pattern="[0-9]{5}-[0-9]{7}-[0-9]" title="Format: xxxxx-xxxxxxx-x">
                            </div>
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
                                <label><i class="fas fa-ring"></i> Marital Status</label>
                                <select id="marital_status" name="marital_status">
                                    <option value="">— Select —</option>
                                    <option value="single">Single</option>
                                    <option value="married">Married</option>
                                    <option value="divorced">Divorced</option>
                                    <option value="widowed">Widowed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-tint"></i> Blood Group</label>
                                <select id="blood_group" name="blood_group">
                                    <option value="">— Select —</option>
                                    <option value="A+">A+</option>
                                    <option value="A-">A-</option>
                                    <option value="B+">B+</option>
                                    <option value="B-">B-</option>
                                    <option value="AB+">AB+</option>
                                    <option value="AB-">AB-</option>
                                    <option value="O+">O+</option>
                                    <option value="O-">O-</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="detail-tab-content tab-hidden" id="tab-address">
                        <div class="form-grid">
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-map-pin"></i> Permanent Address</label>
                                <textarea id="permanent_address" name="permanent_address" rows="3"></textarea>
                            </div>
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-map-marker-alt"></i> Current Address</label>
                                <textarea id="current_address" name="current_address" rows="3"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="detail-tab-content tab-hidden" id="tab-emergency">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-user-shield"></i> Contact Name</label>
                                <input type="text" id="emergency_contact_name" name="emergency_contact_name">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-phone-volume"></i> Contact Phone</label>
                                <input type="text" id="emergency_contact_phone" name="emergency_contact_phone">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-people-arrows"></i> Relation</label>
                                <input type="text" id="emergency_contact_relation" name="emergency_contact_relation" placeholder="Father / Spouse / Sibling…">
                            </div>
                        </div>
                    </div>

                    <div class="detail-tab-content tab-hidden" id="tab-education">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-graduation-cap"></i> Qualification</label>
                                <input type="text" id="qualification" name="qualification" placeholder="BSc Computer Science / Matric / FA…">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-stream"></i> Specialization</label>
                                <input type="text" id="specialization" name="specialization" placeholder="Networking / Accounts / Marketing…">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-clock"></i> Experience (Years)</label>
                                <input type="number" id="experience_years" name="experience_years" step="0.1" min="0" max="60">
                            </div>
                        </div>
                    </div>

                    <div class="detail-tab-content tab-hidden" id="tab-employment">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-check"></i> Joining Date</label>
                                <input type="date" id="joining_date" name="joining_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-day"></i> Confirmation Date</label>
                                <input type="date" id="confirmation_date" name="confirmation_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hourglass-half"></i> Probation End</label>
                                <input type="date" id="probation_end_date" name="probation_end_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-building"></i> Department</label>
                                <select id="department" name="department">
                                    <option value="">— Select / type —</option>
                                    <option value="Sales">Sales</option>
                                    <option value="Technical">Technical</option>
                                    <option value="Accounts">Accounts</option>
                                    <option value="Customer Service">Customer Service</option>
                                    <option value="Operations">Operations</option>
                                    <option value="Admin">Admin</option>
                                    <option value="HR">HR</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user-tie"></i> Designation</label>
                                <input type="text" id="designation" name="designation" placeholder="Manager / Engineer / Officer…">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-sitemap"></i> Reports To</label>
                                <select id="reporting_to_user_id" name="reporting_to_user_id">
                                    <option value="">— No reporting —</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-code-branch"></i> Branch ID</label>
                                <input type="number" id="branch_id" name="branch_id" placeholder="(optional)">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-business-time"></i> Shift</label>
                                <input type="text" id="shift" name="shift" placeholder="Day 9-6 / Night 10pm-6am">
                            </div>
                        </div>
                    </div>

                    <?php if ($canViewSalary): ?>
                    <div class="detail-tab-content tab-hidden" id="tab-salary">
                        <div class="rbac-note" id="salaryRbacNote">
                            <i class="fas fa-eye-slash"></i> Salary is sensitive — only super admins, admins, and the user themselves can see it.
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-money-bill"></i> Basic Salary</label>
                                <input type="number" id="salary_basic" name="salary_basic" step="0.01" min="0" oninput="recomputeSalaryTotal()">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-coins"></i> Allowances</label>
                                <input type="number" id="salary_allowances" name="salary_allowances" step="0.01" min="0" oninput="recomputeSalaryTotal()">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-wallet"></i> Total (auto)</label>
                                <input type="number" id="salary_total" name="salary_total" step="0.01" min="0" readonly>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-university"></i> Bank Name</label>
                                <input type="text" id="salary_account_bank_name" name="salary_account_bank_name" placeholder="HBL / MCB / Meezan…">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-credit-card"></i> Account Number</label>
                                <input type="text" id="salary_account_number" name="salary_account_number">
                            </div>
                        </div>
                    </div>

                    <div class="detail-tab-content tab-hidden" id="tab-statutory">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-file-invoice-dollar"></i> Tax Filer Status</label>
                                <select id="tax_filer_status" name="tax_filer_status">
                                    <option value="">— Select —</option>
                                    <option value="filer">Filer</option>
                                    <option value="non_filer">Non-Filer</option>
                                    <option value="exempt">Exempt</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hashtag"></i> NTN</label>
                                <input type="text" id="ntn" name="ntn">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-id-card-alt"></i> EOBI Number</label>
                                <input type="text" id="eobi_number" name="eobi_number">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-piggy-bank"></i> PF Number</label>
                                <input type="text" id="pf_number" name="pf_number">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="detail-tab-content tab-hidden" id="tab-targets">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="toggle-row">
                                    <span><i class="fas fa-percent"></i> Commission Eligible</span>
                                    <span class="toggle-switch">
                                        <input type="checkbox" id="commission_eligible" name="commission_eligible" value="1" class="toggle-input" onchange="onCommToggle()">
                                        <label for="commission_eligible" class="toggle-label"><span class="toggle-slider"></span></label>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group" id="commRateGroup">
                                <label><i class="fas fa-percent"></i> Commission Rate (%)</label>
                                <input type="number" id="commission_rate_pct" name="commission_rate_pct" step="0.01" min="0" max="100">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-bullseye"></i> Monthly Target Amount</label>
                                <input type="number" id="target_monthly_amount" name="target_monthly_amount" step="0.01" min="0">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-list-ol"></i> Monthly Target Count</label>
                                <input type="number" id="target_monthly_count" name="target_monthly_count" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="detail-tab-content tab-hidden" id="tab-assets">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="toggle-row">
                                    <span><i class="fas fa-car"></i> Vehicle Assigned</span>
                                    <span class="toggle-switch">
                                        <input type="checkbox" id="vehicle_assigned" name="vehicle_assigned" value="1" class="toggle-input" onchange="onAssetToggle('vehicle')">
                                        <label for="vehicle_assigned" class="toggle-label"><span class="toggle-slider"></span></label>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group" id="vehicleNumGroup">
                                <label><i class="fas fa-hashtag"></i> Vehicle Number</label>
                                <input type="text" id="vehicle_number" name="vehicle_number">
                            </div>
                            <div class="form-group">
                                <label class="toggle-row">
                                    <span><i class="fas fa-mobile-alt"></i> Mobile Assigned</span>
                                    <span class="toggle-switch">
                                        <input type="checkbox" id="mobile_assigned" name="mobile_assigned" value="1" class="toggle-input" onchange="onAssetToggle('mobile')">
                                        <label for="mobile_assigned" class="toggle-label"><span class="toggle-slider"></span></label>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group" id="mobileNumGroup">
                                <label><i class="fas fa-phone"></i> Company Mobile #</label>
                                <input type="text" id="mobile_number_company" name="mobile_number_company">
                            </div>
                            <div class="form-group">
                                <label class="toggle-row">
                                    <span><i class="fas fa-laptop"></i> Laptop Assigned</span>
                                    <span class="toggle-switch">
                                        <input type="checkbox" id="laptop_assigned" name="laptop_assigned" value="1" class="toggle-input" onchange="onAssetToggle('laptop')">
                                        <label for="laptop_assigned" class="toggle-label"><span class="toggle-slider"></span></label>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group" id="laptopSerialGroup">
                                <label><i class="fas fa-barcode"></i> Laptop Serial</label>
                                <input type="text" id="laptop_serial" name="laptop_serial">
                            </div>
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-signature"></i> Signature Image Path</label>
                                <input type="text" id="signature_image_path" name="signature_image_path" placeholder="uploads/signatures/sig-001.png">
                            </div>
                        </div>
                    </div>

                    <?php if ($canViewSecurity): ?>
                    <div class="detail-tab-content tab-hidden" id="tab-security">
                        <div class="rbac-note">
                            <i class="fas fa-user-shield"></i> Security controls — super admin only.
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="toggle-row">
                                    <span><i class="fas fa-key"></i> Force Password Change</span>
                                    <span class="toggle-switch">
                                        <input type="checkbox" id="password_must_change" name="password_must_change" value="1" class="toggle-input">
                                        <label for="password_must_change" class="toggle-label"><span class="toggle-slider"></span></label>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label class="toggle-row">
                                    <span><i class="fas fa-shield-alt"></i> Two-Factor Auth</span>
                                    <span class="toggle-switch">
                                        <input type="checkbox" id="two_factor_enabled" name="two_factor_enabled" value="1" class="toggle-input">
                                        <label for="two_factor_enabled" class="toggle-label"><span class="toggle-slider"></span></label>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Locked Until</label>
                                <input type="datetime-local" id="account_locked_until" name="account_locked_until">
                                <button type="button" class="btn btn-secondary btn-sm btn-clear-lockout" onclick="clearLockout()">
                                    <i class="fas fa-unlock"></i> Clear Lockout
                                </button>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-history"></i> Last Password Change</label>
                                <input type="text" id="last_password_change_view" readonly>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-exclamation-triangle"></i> Failed Login Count</label>
                                <input type="number" id="failed_login_count_view" readonly>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($canEditExit): ?>
                    <div class="detail-tab-content tab-hidden" id="tab-exit">
                        <div class="rbac-note">
                            <i class="fas fa-info-circle"></i> Fill these only when an employee resigns or is terminated.
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-times"></i> Resignation Date</label>
                                <input type="date" id="resignation_date" name="resignation_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-ban"></i> Termination Date</label>
                                <input type="date" id="termination_date" name="termination_date">
                            </div>
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-comment"></i> Reason for Leaving</label>
                                <input type="text" id="reason_for_leaving" name="reason_for_leaving" maxlength="255">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

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

    <!-- View Staff — read-only tabbed modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-id-card"></i> Staff Profile</h3>
                <button class="close-btn" onclick="closeViewModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="vtab-nav" id="viewTabs">
                    <button type="button" class="vtab-btn active" data-vtab="vpane-profile"><i class="fas fa-user"></i> Profile</button>
                    <button type="button" class="vtab-btn" data-vtab="vpane-perms"><i class="fas fa-user-shield"></i> Permissions</button>
                </div>
                <div class="vtab-pane active" id="vpane-profile">
                    <div class="vtab-grid" id="viewProfileGrid"></div>
                </div>
                <div class="vtab-pane" id="vpane-perms">
                    <div class="vtab-grid" id="viewPermsGrid"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Staff Activity log modal -->
    <div class="modal-overlay" id="activityModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-clock-rotate-left"></i> Staff Activity <span id="activityModalUser"></span></h3>
                <button class="close-btn" onclick="closeActivityModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="activityModalBody">
                    <p class="muted"><i class="fas fa-spinner fa-spin"></i> Loading...</p>
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
    let usersTable;
    let isEditMode = false;
    let usersData = [];
    let areasCache = [];
    let rolesCache = [];
    const ROLE_SCOPES = {};
    let reportingCache = [];
    const CURRENT_USER_ID = <?php echo (int)$user_id; ?>;
    const PERM_MATRIX = <?php
        $pm = [];
        try {
            $pmc = getDBConnection();
            $pmr = @$pmc->query("SELECT role_key, page_key, can_view, can_add, can_edit, can_delete FROM role_permissions");
            if ($pmr) while ($x = $pmr->fetch_assoc()) {
                $pm[$x['role_key']][$x['page_key']] = ['v' => (int)$x['can_view'], 'a' => (int)$x['can_add'], 'e' => (int)$x['can_edit'], 'd' => (int)$x['can_delete']];
            }
        } catch (Throwable $e) {}
        echo json_encode($pm);
    ?>;
    const CAN_VIEW_SALARY   = <?php echo $canViewSalary ? 'true' : 'false'; ?>;
    const CAN_VIEW_SECURITY = <?php echo $canViewSecurity ? 'true' : 'false'; ?>;
    const CAN_EDIT_EXIT     = <?php echo $canEditExit ? 'true' : 'false'; ?>;

    $(document).ready(function() {
        // SD wiring
        new SearchableDropdown({ selectId: 'role', placeholder: 'Select role...', icon: 'fas fa-user-tag' });
        new SearchableDropdown({ selectId: 'area_id', placeholder: '— No area —', icon: 'fas fa-map-marked-alt' });
        new SearchableDropdown({ selectId: 'status', placeholder: 'Status...', icon: 'fas fa-toggle-on', allowClear: false });
        new SearchableDropdown({ selectId: 'gender', placeholder: '— Select —', icon: 'fas fa-venus-mars' });
        new SearchableDropdown({ selectId: 'marital_status', placeholder: '— Select —', icon: 'fas fa-ring' });
        new SearchableDropdown({ selectId: 'blood_group', placeholder: '— Select —', icon: 'fas fa-tint' });
        new SearchableDropdown({ selectId: 'department', placeholder: '— Select / type —', icon: 'fas fa-building', allowCustom: true });
        new SearchableDropdown({ selectId: 'reporting_to_user_id', placeholder: '— No reporting —', icon: 'fas fa-sitemap' });
        if (CAN_VIEW_SALARY) {
            new SearchableDropdown({ selectId: 'tax_filer_status', placeholder: '— Select —', icon: 'fas fa-file-invoice-dollar' });
        }
        // filters
        new SearchableDropdown({ selectId: 'filterRole', placeholder: 'All Roles', icon: 'fas fa-user-tag' });
        new SearchableDropdown({ selectId: 'filterStatus', placeholder: 'All Statuses', icon: 'fas fa-toggle-on' });
        new SearchableDropdown({ selectId: 'filterArea', placeholder: 'All Areas', icon: 'fas fa-map-marked-alt' });
        new SearchableDropdown({ selectId: 'filterDept', placeholder: 'All Departments', icon: 'fas fa-building' });

        // tabs
        $('#userTabs').on('click', '.detail-tab', function() {
            const target = $(this).data('tab');
            $('#userTabs .detail-tab').removeClass('active');
            $(this).addClass('active');
            $('#userForm .detail-tab-content').addClass('tab-hidden');
            $('#' + target).removeClass('tab-hidden');
        });

        $('#role').on('change', onRoleChange);
        loadRoles(() => loadAreas(() => loadReporting(() => loadUsers())));
        rsImport.init({ entity: 'User', onDone: loadUsers });

        $('#filterDateFrom, #filterDateTo, #filterRole, #filterStatus, #filterArea, #filterDept').on('change', applyFilters);
    });

    // roles come from the matrix now — a new custom role appears here with zero code
    function loadRoles(after) {
        $.getJSON('?action=getRoles', function(resp) {
            if (resp.success) {
                rolesCache = resp.data;
                resp.data.forEach(r => { ROLE_LABELS_JS[r.key] = r.label; ROLE_SCOPES[r.key] = r.scope; });
                const assignable = resp.data.filter(r => resp.can_assign_super || !r.is_super);
                const modalSel = document.getElementById('role');
                modalSel.innerHTML = '<option value="">Select role...</option>' +
                    assignable.map(r => `<option value="${r.key}">${$('<div/>').text(r.label).html()}</option>`).join('');
                modalSel._sd && modalSel._sd.refresh();

                const filterSel = document.getElementById('filterRole');
                filterSel.innerHTML = '<option value="">All Roles</option>' +
                    resp.data.map(r => `<option value="${r.key}">${$('<div/>').text(r.label).html()}</option>`).join('');
                filterSel._sd && filterSel._sd.refresh();
            }
            if (after) after();
        });
    }

    // area picker only matters for area-scoped roles
    function onRoleChange() {
        const scope = ROLE_SCOPES[$('#role').val()] || 'all';
        if (scope === 'area') $('#areasGroup').removeClass('initially-hidden').show();
        else { $('#areasGroup').hide(); $('#areasCheckGroup input').prop('checked', false); }
    }

    function renderAreaChecks(checked) {
        const on = (checked || []).map(Number);
        document.getElementById('areasCheckGroup').innerHTML = areasCache.map(a =>
            `<label class="cb-item"><input type="checkbox" name="areas[]" value="${a.id}"${on.indexOf(a.id) >= 0 ? ' checked' : ''}> <span><i class="fas fa-map-marker-alt"></i> ${$('<div/>').text(a.name).html()}</span></label>`
        ).join('') || '<span class="muted">No areas defined yet.</span>';
    }

    function loadAreas(after) {
        $.getJSON('?action=getAreas', function(resp) {
            if (resp.success) {
                areasCache = resp.data;
                const modalSel = document.getElementById('area_id');
                modalSel.innerHTML = '<option value="">— No area —</option>' +
                    resp.data.map(a => `<option value="${a.id}">${$('<div/>').text(a.name).html()}</option>`).join('');
                modalSel._sd && modalSel._sd.refresh();

                const filterSel = document.getElementById('filterArea');
                filterSel.innerHTML = '<option value="">All Areas</option>' +
                    resp.data.map(a => `<option value="${a.id}">${$('<div/>').text(a.name).html()}</option>`).join('');
                filterSel._sd && filterSel._sd.refresh();

                renderAreaChecks([]);
            }
            if (after) after();
        });
    }

    function loadReporting(after) {
        $.getJSON('?action=getReportingTo', function(resp) {
            if (resp.success) {
                reportingCache = resp.data;
                const sel = document.getElementById('reporting_to_user_id');
                sel.innerHTML = '<option value="">— No reporting —</option>' +
                    resp.data.map(u => `<option value="${u.id}">${$('<div/>').text(u.label).html()}</option>`).join('');
                sel._sd && sel._sd.refresh();
            }
            if (after) after();
        });
    }

    function populateDeptFilter() {
        const seen = new Set();
        const opts = [];
        usersData.forEach(u => {
            if (u.department && !seen.has(u.department)) {
                seen.add(u.department);
                opts.push(u.department);
            }
        });
        const sel = document.getElementById('filterDept');
        sel.innerHTML = '<option value="">All Departments</option>' +
            opts.sort().map(d => `<option value="${$('<div/>').text(d).html()}">${$('<div/>').text(d).html()}</option>`).join('');
        sel._sd && sel._sd.refresh();
    }

    function loadUsers() {
        $.ajax({
            url: '?action=getUsers',
            method: 'GET',
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    usersData = resp.data;
                    $('#filtersSection').show();
                    populateDeptFilter();
                    initializeDataTable(resp.data);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load users' });
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
            }
        });
    }

    function escapeHtml(s) {
        return $('<div/>').text(s == null ? '' : s).html();
    }

    // iso -> readable date / datetime
    function fmtDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        if (isNaN(d)) return '—';
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function fmtDateTime(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        if (isNaN(d)) return '—';
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
            + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    function initializeDataTable(data) {
        if (usersTable) {
            usersTable.destroy();
            // drop thead/tbody only — keep <tfoot> for the summary footer
            $('#usersTable thead, #usersTable tbody').remove();
        }

        setTimeout(() => {
            usersTable = $('#usersTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'id', title: 'ID' },
                    { data: 'employee_code', title: 'Emp Code', render: function(d) { return d ? escapeHtml(d) : '<span class="muted">—</span>'; } },
                    { data: 'username', title: 'Username' },
                    { data: 'name', title: 'Name' },
                    { data: 'email', title: 'Email' },
                    { data: 'phone', title: 'Phone' },
                    {
                        data: 'role', title: 'Role',
                        render: function(data, type, row) {
                            return `<span class="role-badge role-${data.replace('_','-')}">${escapeHtml(row.role_label)}</span>`;
                        }
                    },
                    { data: 'department', title: 'Dept', render: function(d) { return d ? escapeHtml(d) : '<span class="muted">—</span>'; } },
                    { data: 'designation', title: 'Designation', render: function(d) { return d ? escapeHtml(d) : '<span class="muted">—</span>'; } },
                    {
                        data: 'area_name', title: 'Area',
                        render: function(data) { return data ? escapeHtml(data) : '<span class="muted">—</span>'; }
                    },
                    {
                        data: 'joining_date', title: 'Joined',
                        render: function(d) { return d ? escapeHtml(d) : '<span class="muted">—</span>'; }
                    },
                    {
                        data: 'status', title: 'Status',
                        render: function(data, type, row) {
                            const checked = data === 'active' ? 'checked' : '';
                            const disabled = row.id === CURRENT_USER_ID ? 'disabled' : '';
                            return `<label class="status-toggle" title="Toggle status">
                                <input type="checkbox" ${checked} ${disabled} onchange="toggleStatus(${row.id})">
                                <span class="track"></span>
                            </label>`;
                        }
                    },
                    {
                        data: 'last_login', title: 'Last Login',
                        render: function(d, type) {
                            if (type === 'sort' || type === 'type') return d || '';
                            return d ? fmtDateTime(d) : '<span class="muted">Never</span>';
                        }
                    },
                    {
                        data: 'created_at', title: 'Created',
                        render: function(d, type) {
                            if (type === 'sort' || type === 'type') return d || '';
                            return d ? fmtDate(d) : '<span class="muted">—</span>';
                        }
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(data, type, row) {
                            const isSelf = row.id === CURRENT_USER_ID;
                            const toggleLbl = row.status === 'active' ? 'Deactivate' : 'Activate';
                            const toggleIcon = row.status === 'active' ? 'fa-toggle-off' : 'fa-toggle-on';
                            return `
                                <button class="action-icon view-icon" onclick="viewUser(${row.id})" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="action-icon" onclick="viewUserActivity(${row.id})" title="Activity">
                                    <i class="fas fa-clock-rotate-left"></i>
                                </button>
                                <div class="act-more">
                                    <button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                    <div class="act-more-menu">
                                        <button class="act-more-item" onclick="editUser(${row.id})"><i class="fas fa-edit"></i> Edit</button>
                                        <button class="act-more-item" onclick="toggleStatus(${row.id})" ${isSelf ? 'disabled' : ''}><i class="fas ${toggleIcon}"></i> ${toggleLbl}</button>
                                        <button class="act-more-item" onclick="resetPassword(${row.id})"><i class="fas fa-key"></i> Reset Password</button>
                                        <div class="act-more-divider"></div>
                                        <button class="act-more-item danger" onclick="deleteUser(${row.id})" ${isSelf ? 'disabled' : ''}><i class="fas fa-trash"></i> Delete</button>
                                    </div>
                                </div>
                            `;
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
                order: [[0, 'desc']],
                // tint inactive rows (dimmed grey)
                createdRow: function(rowEl, rowData) {
                    if (rowData.status === 'inactive') $(rowEl).addClass('row-cancelled');
                },
                // summary footer — active / inactive / total
                footerCallback: function() {
                    let active = 0, inactive = 0;
                    (usersData || []).forEach(u => { u.status === 'active' ? active++ : inactive++; });
                    setText('footActive', active);
                    setText('footInactive', inactive);
                    setText('footTotal', active + inactive);
                }
            });
        }, 100);
    }

    function setText(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }

    function applyFilters() {
        if (!usersTable) return;
        $.fn.dataTable.ext.search = [];
        const dateFrom = document.getElementById('filterDateFrom').value;
        const dateTo   = document.getElementById('filterDateTo').value;
        const role     = document.getElementById('filterRole').value;
        const status   = document.getElementById('filterStatus').value;
        const area     = document.getElementById('filterArea').value;
        const dept     = document.getElementById('filterDept').value;

        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const r = usersData[dataIndex];
            if (!r) return true;
            if (dateFrom || dateTo) {
                const recDate = new Date(r.created_at);
                if (dateFrom && recDate < new Date(dateFrom)) return false;
                if (dateTo && recDate > new Date(dateTo + 'T23:59:59')) return false;
            }
            if (role && r.role !== role) return false;
            if (status && r.status !== status) return false;
            if (area && String(r.area_id) !== String(area)) return false;
            if (dept && r.department !== dept) return false;
            return true;
        });
        usersTable.draw();
    }

    function clearFilters() {
        ['filterDateFrom','filterDateTo','filterRole','filterStatus','filterArea','filterDept'].forEach(id => {
            const el = document.getElementById(id);
            el.value = '';
            el._sd && el._sd.refresh();
        });
        if (usersTable) {
            $.fn.dataTable.ext.search = [];
            usersTable.draw();
        }
    }

    function resetForm() {
        const form = document.getElementById('userForm');
        form.reset();
        // explicitly reset hidden + readonly + toggles
        document.getElementById('userId').value = '';
        document.getElementById('salary_total') && (document.getElementById('salary_total').value = '');
        const lpc = document.getElementById('last_password_change_view'); if (lpc) lpc.value = '';
        const flc = document.getElementById('failed_login_count_view'); if (flc) flc.value = '0';
        // reset all SD displays
        ['role','area_id','status','gender','marital_status','blood_group','department','reporting_to_user_id','tax_filer_status'].forEach(id => {
            const el = document.getElementById(id);
            if (el && el._sd) el._sd.refresh();
        });
        // first tab
        $('#userTabs .detail-tab').removeClass('active').first().addClass('active');
        $('#userForm .detail-tab-content').addClass('tab-hidden');
        $('#tab-account').removeClass('tab-hidden');
        // commission visibility default
        document.getElementById('commRateGroup').style.display = '';
        // reset asset slot visibility
        ['vehicleNumGroup','mobileNumGroup','laptopSerialGroup'].forEach(id => {
            const g = document.getElementById(id); if (g) g.style.display = '';
        });
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add Staff';
        resetForm();
        document.getElementById('passwordHint').style.display = 'none';
        document.getElementById('password').required = true;
        document.getElementById('status').value = 'active';
        ['role','area_id','status'].forEach(id => {
            const el = document.getElementById(id); el._sd && el._sd.refresh();
        });
        // reset salary editability
        setSalaryEditable(true, true);
        renderAreaChecks([]);
        onRoleChange();
        onCommToggle();
        onAssetToggle('vehicle'); onAssetToggle('mobile'); onAssetToggle('laptop');
        document.getElementById('userModal').classList.add('active');
    }

    function editUser(uid) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Staff';
        resetForm();

        Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.getJSON('?action=getUserDetail&id=' + uid, function(resp) {
            Swal.close();
            if (!resp.success) { Swal.fire({ icon: 'error', title: 'Error', text: resp.message }); return; }
            const u = resp.data;

            // base fields
            document.getElementById('userId').value = u.id;
            document.getElementById('username').value = u.username || '';
            document.getElementById('name').value = u.name || '';
            document.getElementById('email').value = u.email || '';
            document.getElementById('phone').value = u.phone || '';
            document.getElementById('role').value = u.role || '';
            document.getElementById('area_id').value = u.area_id || '';
            renderAreaChecks(u.assigned_areas || []);
            onRoleChange();
            document.getElementById('status').value = u.status || 'active';
            document.getElementById('password').value = '';
            document.getElementById('passwordHint').style.display = 'inline';
            document.getElementById('password').required = false;

            // identity
            document.getElementById('employee_code').value = u.employee_code || '';
            document.getElementById('cnic').value = u.cnic || '';
            document.getElementById('dob').value = u.dob || '';
            document.getElementById('gender').value = u.gender || '';
            document.getElementById('marital_status').value = u.marital_status || '';
            document.getElementById('blood_group').value = u.blood_group || '';

            // address
            document.getElementById('permanent_address').value = u.permanent_address || '';
            document.getElementById('current_address').value = u.current_address || '';

            // emergency
            document.getElementById('emergency_contact_name').value = u.emergency_contact_name || '';
            document.getElementById('emergency_contact_phone').value = u.emergency_contact_phone || '';
            document.getElementById('emergency_contact_relation').value = u.emergency_contact_relation || '';

            // education
            document.getElementById('qualification').value = u.qualification || '';
            document.getElementById('specialization').value = u.specialization || '';
            document.getElementById('experience_years').value = u.experience_years != null ? u.experience_years : '';

            // employment
            document.getElementById('joining_date').value = u.joining_date || '';
            document.getElementById('confirmation_date').value = u.confirmation_date || '';
            document.getElementById('probation_end_date').value = u.probation_end_date || '';
            document.getElementById('department').value = u.department || '';
            document.getElementById('designation').value = u.designation || '';
            document.getElementById('reporting_to_user_id').value = (u.reporting_to_user_id && u.reporting_to_user_id !== u.id) ? u.reporting_to_user_id : '';
            document.getElementById('branch_id').value = u.branch_id != null ? u.branch_id : '';
            document.getElementById('shift').value = u.shift || '';

            // salary (rbac-gated client display)
            if (CAN_VIEW_SALARY || u._is_self) {
                const sb = document.getElementById('salary_basic');
                const sa = document.getElementById('salary_allowances');
                const st = document.getElementById('salary_total');
                if (sb) sb.value = u.salary_basic != null ? u.salary_basic : '';
                if (sa) sa.value = u.salary_allowances != null ? u.salary_allowances : '';
                if (st) st.value = u.salary_total != null ? u.salary_total : '';
                const sbn = document.getElementById('salary_account_bank_name');
                const san = document.getElementById('salary_account_number');
                if (sbn) sbn.value = u.salary_account_bank_name || '';
                if (san) san.value = u.salary_account_number || '';

                // tax
                const tf = document.getElementById('tax_filer_status');
                if (tf) tf.value = u.tax_filer_status || '';
                const ntn = document.getElementById('ntn'); if (ntn) ntn.value = u.ntn || '';
                const eobi = document.getElementById('eobi_number'); if (eobi) eobi.value = u.eobi_number || '';
                const pf = document.getElementById('pf_number'); if (pf) pf.value = u.pf_number || '';

                // self-view = read-only on salary/statutory
                setSalaryEditable(CAN_VIEW_SALARY, !u._is_self || CAN_VIEW_SALARY);
            }

            // targets
            document.getElementById('commission_eligible').checked = !!Number(u.commission_eligible);
            document.getElementById('commission_rate_pct').value = u.commission_rate_pct != null ? u.commission_rate_pct : '';
            document.getElementById('target_monthly_amount').value = u.target_monthly_amount != null ? u.target_monthly_amount : '';
            document.getElementById('target_monthly_count').value = u.target_monthly_count != null ? u.target_monthly_count : '';
            onCommToggle();

            // assets
            document.getElementById('vehicle_assigned').checked = !!Number(u.vehicle_assigned);
            document.getElementById('vehicle_number').value = u.vehicle_number || '';
            document.getElementById('mobile_assigned').checked = !!Number(u.mobile_assigned);
            document.getElementById('mobile_number_company').value = u.mobile_number_company || '';
            document.getElementById('laptop_assigned').checked = !!Number(u.laptop_assigned);
            document.getElementById('laptop_serial').value = u.laptop_serial || '';
            document.getElementById('signature_image_path').value = u.signature_image_path || '';
            onAssetToggle('vehicle'); onAssetToggle('mobile'); onAssetToggle('laptop');

            // security
            if (CAN_VIEW_SECURITY) {
                document.getElementById('password_must_change').checked = !!Number(u.password_must_change);
                document.getElementById('two_factor_enabled').checked = !!Number(u.two_factor_enabled);
                const al = document.getElementById('account_locked_until');
                if (al) al.value = u.account_locked_until ? u.account_locked_until.replace(' ', 'T').substring(0,16) : '';
                const lpc = document.getElementById('last_password_change_view');
                if (lpc) lpc.value = u.last_password_change || '—';
                const flc = document.getElementById('failed_login_count_view');
                if (flc) flc.value = u.failed_login_count != null ? u.failed_login_count : 0;
            }

            // exit
            if (CAN_EDIT_EXIT) {
                document.getElementById('resignation_date').value = u.resignation_date || '';
                document.getElementById('termination_date').value = u.termination_date || '';
                document.getElementById('reason_for_leaving').value = u.reason_for_leaving || '';
            }

            // refresh all SD
            ['role','area_id','status','gender','marital_status','blood_group','department','reporting_to_user_id','tax_filer_status'].forEach(id => {
                const el = document.getElementById(id);
                if (el && el._sd) el._sd.refresh();
            });

            document.getElementById('userModal').classList.add('active');
        }).fail(function() {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load user details' });
        });
    }

    function setSalaryEditable(salaryCanEdit, statutoryCanEdit) {
        const sFields = ['salary_basic','salary_allowances','salary_account_bank_name','salary_account_number'];
        sFields.forEach(id => { const el = document.getElementById(id); if (el) el.readOnly = !salaryCanEdit; });
        const tFields = ['tax_filer_status','ntn','eobi_number','pf_number'];
        tFields.forEach(id => { const el = document.getElementById(id); if (!el) return; el.disabled = !statutoryCanEdit; });
        const note = document.getElementById('salaryRbacNote');
        if (note) note.style.display = salaryCanEdit ? 'none' : '';
    }

    function recomputeSalaryTotal() {
        const sb = parseFloat(document.getElementById('salary_basic').value) || 0;
        const sa = parseFloat(document.getElementById('salary_allowances').value) || 0;
        document.getElementById('salary_total').value = (sb + sa).toFixed(2);
    }

    function onCommToggle() {
        const on = document.getElementById('commission_eligible').checked;
        document.getElementById('commRateGroup').style.display = on ? '' : 'none';
        if (!on) document.getElementById('commission_rate_pct').value = '';
    }

    function onAssetToggle(kind) {
        const map = { vehicle: ['vehicle_assigned','vehicleNumGroup','vehicle_number'],
                      mobile:  ['mobile_assigned','mobileNumGroup','mobile_number_company'],
                      laptop:  ['laptop_assigned','laptopSerialGroup','laptop_serial'] };
        const [tog, grp, fld] = map[kind];
        const on = document.getElementById(tog).checked;
        document.getElementById(grp).style.display = on ? '' : 'none';
        if (!on) document.getElementById(fld).value = '';
    }

    function clearLockout() {
        const uid = document.getElementById('userId').value;
        if (!uid) return;
        Swal.fire({
            icon: 'question',
            title: 'Clear lockout?',
            text: 'This unlocks the account and resets failed login count.',
            showCancelButton: true,
            confirmButtonText: 'Clear'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', uid);
            $.ajax({
                url: '?action=clearLockout',
                method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        document.getElementById('account_locked_until').value = '';
                        document.getElementById('failed_login_count_view').value = '0';
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                }
            });
        });
    }

    function closeModal() {
        document.getElementById('userModal').classList.remove('active');
        resetForm();
    }

    document.getElementById('userModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.getElementById('userForm').addEventListener('submit', function(e) {
        e.preventDefault();
        // ensure salary_total is current
        if (CAN_VIEW_SALARY) recomputeSalaryTotal();

        const fd = new FormData(this);
        // toggles only post when checked — explicitly include 0 for unchecked so server can set to 0
        ['commission_eligible','vehicle_assigned','mobile_assigned','laptop_assigned','password_must_change','two_factor_enabled']
            .forEach(name => { if (!fd.has(name)) fd.append(name, '0'); });

        const action = isEditMode ? 'updateUser' : 'addUser';
        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.ajax({
            url: '?action=' + action,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 2000, showConfirmButton: false });
                    closeModal();
                    setTimeout(() => loadUsers(), 100);
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

    function toggleStatus(userId) {
        const fd = new FormData();
        fd.append('id', userId);
        $.ajax({
            url: '?action=toggleStatus',
            method: 'POST',
            data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    setTimeout(() => loadUsers(), 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    loadUsers();
                }
            }
        });
    }

    // ── View modal (read-only, tabbed) ──────────────────────────────
    const ROLE_LABELS_JS = {}; // filled by loadRoles()

    // access summary straight off the live matrix — never a hardcoded role string
    function rolePermissions(role) {
        const perms = PERM_MATRIX[role] || {};
        const on = (page, k) => !!(perms[page] && perms[page][k || 'v']);
        return [
            ['Manage Staff', on('users')],
            ['Roles & Permissions', on('roles')],
            ['Customers & Packages', on('customers')],
            ['Billing & Invoices', on('invoices')],
            ['Record Payments', on('payments', 'a')],
            ['Expenses & Finance', on('expenses')],
            ['Ledger', on('ledger')],
            ['Stock & Inventory', on('stock')],
            ['Complaints / Field Ops', on('complaints')],
            ['System Settings', on('settings')]
        ];
    }

    function vfield(label, value) {
        return `<div class="vtab-field"><label>${escapeHtml(label)}</label><span>${value}</span></div>`;
    }

    function viewUser(uid) {
        Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.getJSON('?action=getUserDetail&id=' + uid, function(resp) {
            Swal.close();
            if (!resp.success) { Swal.fire({ icon: 'error', title: 'Error', text: resp.message }); return; }
            const u = resp.data;
            // resolve names from cache
            const area = (areasCache.find(a => String(a.id) === String(u.area_id)) || {}).name || '—';
            const roleLbl = ROLE_LABELS_JS[u.role] || u.role || '—';
            const statusBadge = u.status === 'active'
                ? '<span class="role-badge role-accountant">Active</span>'
                : '<span class="role-badge role-collector">Inactive</span>';

            document.getElementById('viewProfileGrid').innerHTML =
                vfield('Full Name', escapeHtml(u.name || '—')) +
                vfield('Username', escapeHtml(u.username || '—')) +
                vfield('Email', escapeHtml(u.email || '—')) +
                vfield('Phone', escapeHtml(u.phone || '—')) +
                vfield('Role', `<span class="role-badge role-${(u.role||'').replace('_','-')}">${escapeHtml(roleLbl)}</span>`) +
                vfield('Status', statusBadge) +
                vfield('Department', escapeHtml(u.department || '—')) +
                vfield('Designation', escapeHtml(u.designation || '—')) +
                vfield('Service Area', escapeHtml(area)) +
                vfield('Employee Code', escapeHtml(u.employee_code || '—')) +
                vfield('Joined', u.joining_date ? fmtDate(u.joining_date) : '—') +
                vfield('Last Password Change', u.last_password_change ? fmtDateTime(u.last_password_change) : '—') +
                vfield('Created', u.created_at ? fmtDateTime(u.created_at) : '—');

            document.getElementById('viewPermsGrid').innerHTML =
                rolePermissions(u.role).map(p =>
                    vfield(p[0], p[1]
                        ? '<span class="role-badge role-accountant"><i class="fas fa-check"></i> Allowed</span>'
                        : '<span class="muted"><i class="fas fa-minus"></i> No access</span>')
                ).join('');

            // reset to first tab
            document.querySelectorAll('#viewTabs .vtab-btn').forEach(b => b.classList.remove('active'));
            document.querySelector('#viewTabs .vtab-btn').classList.add('active');
            document.querySelectorAll('#viewModal .vtab-pane').forEach(p => p.classList.remove('active'));
            document.getElementById('vpane-profile').classList.add('active');

            document.getElementById('viewModal').classList.add('active');
        }).fail(function() {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Could not load profile' });
        });
    }
    function closeViewModal() { document.getElementById('viewModal').classList.remove('active'); }

    // view-modal tab switching
    document.querySelectorAll('#viewTabs .vtab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#viewTabs .vtab-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            document.querySelectorAll('#viewModal .vtab-pane').forEach(p => p.classList.remove('active'));
            document.getElementById(this.dataset.vtab).classList.add('active');
        });
    });

    // ── Activity modal ──────────────────────────────────────────────
    function viewUserActivity(uid) {
        const modal = document.getElementById('activityModal');
        document.getElementById('activityModalBody').innerHTML =
            '<p class="muted"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
        document.getElementById('activityModalUser').textContent = '';
        modal.classList.add('active');

        $.getJSON('?action=getUserActivity&id=' + uid, function(resp) {
            if (!resp.success) {
                document.getElementById('activityModalBody').innerHTML =
                    '<p class="muted">' + escapeHtml(resp.message || 'Failed to load') + '</p>';
                return;
            }
            document.getElementById('activityModalUser').textContent = '— ' + (resp.username || '');
            if (!resp.data.length) {
                document.getElementById('activityModalBody').innerHTML =
                    '<div class="activities-empty"><i class="fas fa-inbox"></i><p>No activity recorded for this user</p></div>';
                return;
            }
            let html = '<table class="setup-guide-table"><thead><tr>' +
                '<th><i class="fas fa-bolt"></i> Action</th>' +
                '<th><i class="fas fa-info-circle"></i> Details</th>' +
                '<th><i class="fas fa-clock"></i> Time</th></tr></thead><tbody>';
            resp.data.forEach(function(row) {
                const al = (row.action || '').toLowerCase();
                let badge = 'action-badge-default';
                if (al.includes('login') || al.includes('created')) badge = 'action-badge-success';
                else if (al.includes('delete') || al.includes('reset') || al.includes('logout')) badge = 'action-badge-danger';
                else if (al.includes('update') || al.includes('change') || al.includes('toggle')) badge = 'action-badge-warning';
                html += '<tr><td><span class="action-badge ' + badge + '">' + escapeHtml(row.action) + '</span></td>' +
                    '<td>' + escapeHtml(row.details || '—') + '</td>' +
                    '<td>' + fmtDateTime(row.timestamp) + '</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('activityModalBody').innerHTML = html;
        }).fail(function() {
            document.getElementById('activityModalBody').innerHTML =
                '<p class="muted">Connection error loading activity</p>';
        });
    }
    function closeActivityModal() { document.getElementById('activityModal').classList.remove('active'); }

    // ── Reset password ──────────────────────────────────────────────
    function resetPassword(userId) {
        Swal.fire({
            icon: 'warning',
            title: 'Reset Password',
            input: 'password',
            inputLabel: 'New password (min 6 chars)',
            inputPlaceholder: 'Enter new password',
            inputAttributes: { minlength: 6, autocomplete: 'new-password' },
            showCancelButton: true,
            confirmButtonColor: '#001f3f',
            confirmButtonText: 'Reset',
            cancelButtonText: 'Cancel',
            preConfirm: (pw) => {
                if (!pw || pw.length < 6) {
                    Swal.showValidationMessage('Password must be at least 6 characters');
                }
                return pw;
            }
        }).then((result) => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', userId);
            fd.append('new_password', result.value);
            $.ajax({
                url: '?action=resetPassword',
                method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });
    }

    function deleteUser(userId) {
        Swal.fire({
            icon: 'warning',
            title: 'Delete User?',
            text: 'This will soft-delete the account.',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', userId);
            $.ajax({
                url: '?action=deleteUser',
                method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 2000, showConfirmButton: false });
                        setTimeout(() => loadUsers(), 100);
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
    }
    </script>
</body>
</html>
