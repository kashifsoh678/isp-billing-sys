<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Database Configuration File
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}

require_once APP_ROOT . '/config/database.php';

// Error handling - disable display_errors in production
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Changed to 0 for production security
ini_set('log_errors', 1);
ini_set('error_log', APP_ROOT . '/storage/logs/error_log.txt');

// app clock — PHP and MySQL MUST agree, or "today"/"this month" filters silently drop rows
// (default PHP is UTC while MySQL runs on server local time — 5 hours apart here)
define('APP_TZ', 'Asia/Karachi');
date_default_timezone_set(APP_TZ);

// Security constants
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Auto-detect HTTPS (works on both XAMPP and Hostinger)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Secure session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $is_https ? 1 : 0);
    ini_set('session.cookie_samesite', $is_https ? 'Strict' : 'Lax');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.sid_length', 48);
    ini_set('session.cookie_lifetime', 0);
    session_start();
}

// Auto-login from Remember Me cookie
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    try {
        $remembered_user = validateRememberToken();
        if ($remembered_user) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $remembered_user['user_id'];
            $_SESSION['username'] = $remembered_user['username'];
            $_SESSION['role'] = $remembered_user['role'];
            $_SESSION['LAST_ACTIVITY'] = time();
        }
    } catch (Exception $e) {
        // Database not ready yet, skip auto-login
    }
}

// asset url + mtime cache-buster — edit styles.css and every browser refetches it, no manual ?v= bumping
function asset($file) {
    $path = APP_ROOT . '/' . basename($file);
    return $file . '?v=' . (is_file($path) ? filemtime($path) : time());
}

// Set HTTP security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(self), microphone=(), camera=()"); // self = our own pages may ask for GPS

// ─── ISP Role System ─────────────────────────────────────────────
// built-ins: super_admin = owner | admin = ops mgr | accountant = finance
//            area_manager = multi-area boss | collector = field
// custom roles (Installation Engineer, Store Keeper...) live in the `roles` table
define('ROLE_SUPER_ADMIN',  'super_admin');
define('ROLE_ADMIN',        'admin');
define('ROLE_ACCOUNTANT',   'accountant');
define('ROLE_AREA_MANAGER', 'area_manager');
define('ROLE_COLLECTOR',    'collector');

// pages the permission matrix governs — key => [label, group, icon]
function rbacPages() {
    static $p = [
        'dashboard'        => ['Dashboard',            'General',     'fas fa-chart-line'],
        'customers'        => ['Customers',            'Subscribers', 'fas fa-user-friends'],
        'packages'         => ['Packages',             'Subscribers', 'fas fa-box'],
        'areas'            => ['Areas',                'Subscribers', 'fas fa-map-marked-alt'],
        'leads'            => ['Leads',                'Subscribers', 'fas fa-funnel-dollar'],
        'invoices'         => ['Invoices',             'Billing',     'fas fa-file-invoice'],
        'payments'         => ['Payments',             'Billing',     'fas fa-money-bill-wave'],
        'collections'      => ['Collections',          'Billing',     'fas fa-route'],
        'collections_runs' => ['Collections Workbook', 'Billing',     'fas fa-clipboard-list'],
        'aging'            => ['Aging Report',         'Billing',     'fas fa-chart-pie'],
        'expenses'         => ['Expenses',             'Accounts',    'fas fa-receipt'],
        'vendors'          => ['Vendors',              'Accounts',    'fas fa-truck'],
        'bank_accounts'    => ['Bank Accounts',        'Accounts',    'fas fa-university'],
        'ledger'           => ['Ledger',               'Accounts',    'fas fa-book'],
        'equipment'        => ['Equipment',            'Inventory',   'fas fa-server'],
        'pos'              => ['POS Counter',          'Inventory',   'fas fa-cash-register'],
        'stock'            => ['Stock Items',          'Inventory',   'fas fa-boxes-stacked'],
        'stock_movements'  => ['Stock Movements',      'Inventory',   'fas fa-dolly'],
        'complaints'       => ['Complaints',           'Support',     'fas fa-headset'],
        'users'            => ['Staff',                'System',      'fas fa-id-badge'],
        'roles'            => ['Roles & Permissions',  'System',      'fas fa-user-shield'],
        'logs'             => ['Activity Logs',        'System',      'fas fa-history'],
        'settings'         => ['System Settings',      'System',      'fas fa-cog'],
    ];
    return $p;
}

function currentRole() {
    return $_SESSION['role'] ?? '';
}

// roles row (cached) — returns null before update_setup.php has run
function roleRow($role = null) {
    static $cache = [];
    $role = $role ?? currentRole();
    if ($role === '') return null;
    if (array_key_exists($role, $cache)) return $cache[$role];
    $cache[$role] = null;
    try {
        $conn = getDBConnection();
        $st = $conn->prepare("SELECT role_key, label, badge_color, icon, data_scope, is_system, is_super, receives_admin_alerts FROM roles WHERE role_key = ? AND is_deleted = 0");
        if ($st) {
            $st->bind_param("s", $role);
            $st->execute();
            $cache[$role] = $st->get_result()->fetch_assoc() ?: null;
            $st->close();
        }
    } catch (Throwable $e) {} // table not built yet
    return $cache[$role];
}

// page => v/a/e/d map for a role — one query per request
function rolePerms($role = null) {
    static $cache = [];
    $role = $role ?? currentRole();
    if (isset($cache[$role])) return $cache[$role];
    $map = [];
    try {
        $conn = getDBConnection();
        $st = $conn->prepare("SELECT page_key, can_view, can_add, can_edit, can_delete FROM role_permissions WHERE role_key = ?");
        if ($st) {
            $st->bind_param("s", $role);
            $st->execute();
            $rs = $st->get_result();
            while ($r = $rs->fetch_assoc()) {
                $map[$r['page_key']] = ['v' => (int)$r['can_view'], 'a' => (int)$r['can_add'], 'e' => (int)$r['can_edit'], 'd' => (int)$r['can_delete']];
            }
            $st->close();
        }
    } catch (Throwable $e) {}
    return $cache[$role] = $map;
}

// THE gate — everything routes through here. $perm: v=view a=add e=edit d=delete
function can($page, $perm = 'v', $role = null) {
    $role = $role ?? currentRole();
    if ($role === '') return false;
    if ($role === ROLE_SUPER_ADMIN) return true; // owner bypass
    $row = roleRow($role);
    if ($row && (int)$row['is_super'] === 1) return true;
    $perms = rolePerms($role);
    if (!$perms) return rbacFallback($page, $perm, $role); // matrix not seeded yet
    return !empty($perms[$page][$perm]);
}

// pre-matrix behaviour — keeps the app working exactly as before until role_permissions is seeded
function rbacFallback($page, $perm, $role) {
    $adminLvl = in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true);
    $finance  = in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTANT], true);
    $everyone = in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTANT, ROLE_AREA_MANAGER, ROLE_COLLECTOR], true);
    if ($perm === 'd') return $role === ROLE_SUPER_ADMIN; // delete was super-only
    switch ($page) {
        case 'dashboard':        return $everyone;
        case 'customers':        return $perm === 'v' ? ($finance || $role === ROLE_COLLECTOR) : $finance;
        case 'packages':
        case 'areas':
        case 'equipment':        return $adminLvl;
        case 'invoices':
        case 'payments':
        case 'collections':
        case 'collections_runs':
        case 'complaints':       return $everyone;
        case 'expenses':
        case 'vendors':
        case 'bank_accounts':
        case 'ledger':
        case 'stock':
        case 'stock_movements':
        case 'leads':
        case 'aging':            return $finance;
        case 'users':
        case 'roles':
        case 'logs':             return $adminLvl;
        case 'settings':         return $role === ROLE_SUPER_ADMIN;
    }
    return false;
}

// page-top gate — HTML pages redirect, ?action= calls get JSON
function requirePerm($page, $perm = 'v') {
    if (!isset($_SESSION['user_id']))  rbacDeny('login.php', 'Not authenticated');
    if (!checkSessionTimeout())        rbacDeny('login.php', 'Session expired');
    if (!can($page, $perm))            rbacDeny('dashboard.php', 'Access denied');
}

function rbacDeny($url, $msg) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit();
    }
    header("Location: $url");
    exit();
}

// strict role checks
function isSuperAdmin($role = null) {
    $r = $role ?? currentRole();
    if ($r === ROLE_SUPER_ADMIN) return true;
    $x = roleRow($r);
    return $x && (int)$x['is_super'] === 1;
}
function isAdmin($role = null)       { return ($role ?? currentRole()) === ROLE_ADMIN; }
function isAccountant($role = null)  { return ($role ?? currentRole()) === ROLE_ACCOUNTANT; }
function isAreaManager($role = null) { return ($role ?? currentRole()) === ROLE_AREA_MANAGER; }
function isCollector($role = null)   { return ($role ?? currentRole()) === ROLE_COLLECTOR; }

// hierarchy / capability checks
function isAdminLevel($role = null) {
    return in_array($role ?? currentRole(), [ROLE_SUPER_ADMIN, ROLE_ADMIN], true);
}
// role is limited to its own areas (collector, area manager, any custom area role)
function isAreaScoped($role = null) {
    $x = roleRow($role);
    return $x ? ($x['data_scope'] === 'area') : isCollector($role);
}

// area ids this user may see — empty array = unrestricted
function currentAreaIds($uid = null) {
    static $cache = [];
    $uid = (int)($uid ?? ($_SESSION['user_id'] ?? 0));
    if (isset($cache[$uid])) return $cache[$uid];
    if (!$uid) return $cache[$uid] = [];
    $ids = [];
    try {
        $conn = getDBConnection();
        $st = $conn->prepare("SELECT role, area_id FROM users WHERE id = ? AND is_deleted = 0");
        $st->bind_param("i", $uid);
        $st->execute();
        $u = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$u || !isAreaScoped($u['role'])) return $cache[$uid] = [];
        if (!empty($u['area_id'])) $ids[] = (int)$u['area_id'];
        $r = @$conn->query("SELECT area_id FROM user_areas WHERE user_id = $uid");
        if ($r) while ($x = $r->fetch_assoc()) $ids[] = (int)$x['area_id'];
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) $ids = [0]; // scoped but nothing assigned -> match nothing, never everything
    } catch (Throwable $e) {}
    return $cache[$uid] = $ids;
}

// is this row inside my areas? (true for unrestricted roles)
function areaAllowed($areaId, $uid = null) {
    $ids = currentAreaIds($uid);
    return !$ids || in_array((int)$areaId, $ids, true);
}

// " AND t.area_id IN (1,2,3)" — append to any WHERE on an area-bearing table
function areaScope($alias, $uid = null) {
    $ids = currentAreaIds($uid);
    return $ids ? " AND {$alias}.area_id IN (" . implode(',', array_map('intval', $ids)) . ")" : '';
}

function canManageUsers($role = null)       { return can('users', 'v', $role); }     // users.php
function canAccessSystem($role = null)      { return can('settings', 'v', $role); }  // settings/smtp/oauth/backup/sessions
function canBypassMaintenance($role = null) { return isSuperAdmin($role); }
function canViewActivityLogs($role = null)  { return can('logs', 'v', $role); }
function canDeleteResource($role = null)    { return isSuperAdmin($role); }          // legacy generic gate — per-page delete is can($page,'d')
function canManageCustomers($role = null)   { return can('customers', 'e', $role); }
function canManagePackages($role = null)    { return can('packages', 'e', $role); }
function canManageAreas($role = null)       { return can('areas', 'e', $role); }
function canViewFinances($role = null)      { return can('expenses', 'v', $role); }
function canViewInvoices($role = null)      { return can('invoices', 'v', $role); }
function canCollectPayments($role = null)   { return can('payments', 'v', $role); }
function canManageComplaints($role = null)  { return can('complaints', 'v', $role); }
function canManageExpenses($role = null)    { return can('expenses', 'v', $role); }
function canManageEquipment($role = null)   { return can('equipment', 'v', $role); }
function canManageLeads($role = null)       { return can('leads', 'v', $role); }
function canViewReports($role = null)       { return can('aging', 'v', $role); }
function canManageStock($role = null)       { return can('stock', 'v', $role); }
function canViewLedger($role = null)        { return can('ledger', 'v', $role); }
function canManageRoles($role = null)       { return can('roles', 'v', $role); }

// presentation
function getRoleDisplay($role) {
    $x = roleRow($role);
    if ($x) return $x['label'];
    $map = [
        ROLE_SUPER_ADMIN  => 'Super Admin',
        ROLE_ADMIN        => 'Admin',
        ROLE_ACCOUNTANT   => 'Accountant',
        ROLE_AREA_MANAGER => 'Area Manager',
        ROLE_COLLECTOR    => 'Collector',
    ];
    return $map[$role] ?? ucwords(str_replace('_', ' ', (string)$role));
}

function getRoleBadgeClass($role) {
    $known = [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTANT, ROLE_AREA_MANAGER, ROLE_COLLECTOR];
    return in_array($role, $known, true) ? 'role-' . str_replace('_', '-', $role) : 'role-default';
}

// custom roles carry their colour from the db — runtime value, so inline is the only way
function getRoleBadgeStyle($role) {
    $known = [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTANT, ROLE_AREA_MANAGER, ROLE_COLLECTOR];
    if (in_array($role, $known, true)) return '';
    $x = roleRow($role);
    if (!$x || empty($x['badge_color'])) return '';
    return ' style="background:' . htmlspecialchars($x['badge_color']) . ';color:#fff"';
}

// active role keys — db driven, falls back to the built-ins on a fresh db
function getAllRoles() {
    static $c = null;
    if ($c !== null) return $c;
    $rows = [];
    try {
        $conn = getDBConnection();
        $r = @$conn->query("SELECT role_key FROM roles WHERE is_deleted = 0 AND status = 'active' ORDER BY sort_order ASC, id ASC");
        if ($r) while ($x = $r->fetch_assoc()) $rows[] = $x['role_key'];
    } catch (Throwable $e) {}
    if (!$rows) $rows = [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTANT, ROLE_COLLECTOR];
    return $c = $rows;
}

// full rows for dropdowns / matrix ui
function getRolesDetailed($activeOnly = true) {
    $rows = [];
    try {
        $conn = getDBConnection();
        $sql = "SELECT role_key, label, description, badge_color, icon, data_scope, is_system, is_super, receives_admin_alerts, sort_order, status
                FROM roles WHERE is_deleted = 0" . ($activeOnly ? " AND status = 'active'" : "") . " ORDER BY sort_order ASC, id ASC";
        $r = @$conn->query($sql);
        if ($r) while ($x = $r->fetch_assoc()) $rows[] = $x;
    } catch (Throwable $e) {}
    return $rows;
}

// role keys holding a permission — replaces every hardcoded role IN (...) staff picker
function rolesWithPerm($page, $perm = 'v') {
    static $cache = [];
    $ck = $page . '|' . $perm;
    if (isset($cache[$ck])) return $cache[$ck];
    $col = ['v' => 'can_view', 'a' => 'can_add', 'e' => 'can_edit', 'd' => 'can_delete'][$perm] ?? 'can_view';
    $keys = [];
    try {
        $conn = getDBConnection();
        $st = $conn->prepare("SELECT r.role_key FROM roles r
                              LEFT JOIN role_permissions p ON p.role_key = r.role_key AND p.page_key = ?
                              WHERE r.is_deleted = 0 AND r.status = 'active' AND (r.is_super = 1 OR p.$col = 1)
                              ORDER BY r.sort_order ASC");
        if ($st) {
            $st->bind_param("s", $page);
            $st->execute();
            $rs = $st->get_result();
            while ($x = $rs->fetch_assoc()) $keys[] = $x['role_key'];
            $st->close();
        }
    } catch (Throwable $e) {}
    if (!$keys) $keys = getAllRoles();
    return $cache[$ck] = $keys;
}

// role keys tied to their own areas — field-staff pickers, collector counts
function areaScopedRoles() {
    static $c = null;
    if ($c !== null) return $c;
    $keys = [];
    try {
        $conn = getDBConnection();
        $r = @$conn->query("SELECT role_key FROM roles WHERE data_scope = 'area' AND is_deleted = 0 AND status = 'active' ORDER BY sort_order ASC");
        if ($r) while ($x = $r->fetch_assoc()) $keys[] = $x['role_key'];
    } catch (Throwable $e) {}
    if (!$keys) $keys = [ROLE_COLLECTOR];
    return $c = $keys;
}

// "u.role IN ('collector','area_manager')"
function areaRoleInSql($alias) {
    $conn = getDBConnection();
    $q = array_map(function ($k) use ($conn) { return "'" . $conn->real_escape_string($k) . "'"; }, areaScopedRoles());
    return $alias . ".role IN (" . implode(',', $q) . ")";
}

// "u.role IN ('admin','collector')" — safe, keys come from the db
function roleInSql($alias, $page, $perm = 'v') {
    $conn = getDBConnection();
    $q = array_map(function ($k) use ($conn) { return "'" . $conn->real_escape_string($k) . "'"; }, rolesWithPerm($page, $perm));
    return $alias . ".role IN (" . implode(',', $q) . ")";
}

// recompute denormalized invoice paid_amount, balance_amount, status — and customer last_payment_date
function recomputeInvoiceFromAllocations($conn, $invoice_id) {
    $r = $conn->prepare("SELECT total, customer_id, due_date, status FROM invoices WHERE id = ? AND is_deleted = 0");
    $r->bind_param("i", $invoice_id);
    $r->execute();
    $inv = $r->get_result()->fetch_assoc();
    $r->close();
    if (!$inv) return;

    // cancelled invoices stay cancelled — never resurrect balance/status
    $isCancelled = ($inv['status'] === 'cancelled');

    // paid = cleared allocations in full + refunded allocations' still-applied remainder.
    // partial refund: remainder = alloc * (amount - refunded_amount) / amount  (0 on full refund)
    $r = $conn->prepare("SELECT IFNULL(SUM(
                              CASE
                                WHEN p.status = 'cleared' THEN a.amount
                                WHEN p.status = 'refunded' AND p.amount > 0
                                     THEN a.amount * (p.amount - IFNULL(p.refunded_amount, 0)) / p.amount
                                ELSE 0
                              END
                         ), 0) AS paid
                         FROM payment_invoice_allocations a
                         JOIN payments p ON p.id = a.payment_id
                         WHERE a.invoice_id = ? AND p.is_deleted = 0");
    $r->bind_param("i", $invoice_id);
    $r->execute();
    $paid = (float)$r->get_result()->fetch_assoc()['paid'];
    $r->close();

    $total = (float)$inv['total'];
    $balance = max(0, $total - $paid);
    $new_status = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
    if ($new_status !== 'paid' && strtotime($inv['due_date']) < strtotime(date('Y-m-d'))) {
        $new_status = 'overdue';
    }

    if ($isCancelled) {
        // keep status='cancelled', only refresh the money columns
        $u = $conn->prepare("UPDATE invoices SET paid_amount = ?, balance_amount = ? WHERE id = ?");
        $u->bind_param("ddi", $paid, $balance, $invoice_id);
    } else {
        $u = $conn->prepare("UPDATE invoices SET paid_amount = ?, balance_amount = ?, status = ? WHERE id = ?");
        $u->bind_param("ddsi", $paid, $balance, $new_status, $invoice_id);
    }
    $u->execute();
    $u->close();

    // refresh customer last_payment_date
    $u = $conn->prepare("UPDATE customers c SET last_payment_date = (SELECT MAX(p.payment_date) FROM payments p WHERE p.customer_id = c.id AND p.is_deleted = 0 AND p.status = 'cleared') WHERE c.id = ?");
    $u->bind_param("i", $inv['customer_id']);
    $u->execute();
    $u->close();
}
// ─── /ISP Role System ────────────────────────────────────────────

// Create database connection (singleton - reuses same connection per request)
function getDBConnection() {
    static $conn = null;
    try {
        if ($conn === null || !@$conn->ping()) {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            if ($conn->connect_error) {
                error_log("Database connection failed: " . $conn->connect_error);
                die("Database connection failed. Please try again later.");
            }

            $conn->set_charset("utf8mb4");
            // put MySQL on the same clock as PHP so NOW()/CURDATE() match date()
            @$conn->query("SET time_zone = '" . (new DateTime('now', new DateTimeZone(APP_TZ)))->format('P') . "'");
        }
        return $conn;
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        die("Database error occurred. Please contact administrator.");
    }
}

// CSRF Protection Functions
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Session timeout check
function checkSessionTimeout() {
    // Check if force-logout flag is set
    try {
        if (checkForceLogout()) {
            removeUserSession();
            session_unset();
            session_destroy();
            return false;
        }
    } catch (Exception $e) {
        // user_sessions table may not exist yet
    }

    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {
        try { removeUserSession(); } catch (Exception $e) {}
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['LAST_ACTIVITY'] = time();

    // Track session activity
    if (isset($_SESSION['user_id'])) {
        try { trackUserSession($_SESSION['user_id']); } catch (Exception $e) {}
    }

    return true;
}

// Input validation functions
function validateUsername($username) {
    $username = trim($username);
    if (strlen($username) < 3 || strlen($username) > 50) {
        return false;
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return false;
    }
    return $username;
}

function validatePassword($password) {
    if (strlen($password) < 6 || strlen($password) > 255) {
        return false;
    }
    return $password;
}

// Rate limiting functions
function checkLoginAttempts($username) {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts
                           WHERE username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $lockout = LOGIN_LOCKOUT_TIME;
    $stmt->bind_param("si", $username, $lockout);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['attempts'] >= MAX_LOGIN_ATTEMPTS;
}

function recordLoginAttempt($username) {
    $conn = getDBConnection();
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $ip);
    $stmt->execute();
    $stmt->close();
}

function clearLoginAttempts($username) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
}

// Activity Logging Functions
function logActivity($user_id, $username, $action, $details = '') {
    try {
        $conn = getDBConnection();

        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $user_id, $username, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();

        return true;
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}

function getActivityLogs($user_id = null, $role = ROLE_COLLECTOR, $limit = null) {
    try {
        $conn = getDBConnection();

        // admin-level sees all logs, others see only their own
        if (isAdminLevel($role) && $user_id === null) {
            $sql = "SELECT * FROM activity_logs ORDER BY timestamp DESC";
            if ($limit) {
                $sql .= " LIMIT ?";
            }
            $stmt = $conn->prepare($sql);
            if ($limit) {
                $stmt->bind_param("i", $limit);
            }
        } else {
            $sql = "SELECT * FROM activity_logs WHERE user_id = ? ORDER BY timestamp DESC";
            if ($limit) {
                $sql .= " LIMIT ?";
            }
            $stmt = $conn->prepare($sql);
            if ($limit) {
                $stmt->bind_param("ii", $user_id, $limit);
            } else {
                $stmt->bind_param("i", $user_id);
            }
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }

        $stmt->close();

        return $logs;
    } catch (Exception $e) {
        error_log("Get activity logs error: " . $e->getMessage());
        return [];
    }
}

// System Settings Functions
function getSetting($key, $default = null) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $value = $row['setting_value'];
            $stmt->close();
            return $value;
        }

        $stmt->close();
        return $default;
    } catch (Exception $e) {
        error_log("Get setting error: " . $e->getMessage());
        return $default;
    }
}

function setSetting($key, $value) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $key, $value, $value);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("Set setting error: " . $e->getMessage());
        return false;
    }
}

function getDefaultLanguage() {
    return getSetting('default_language', 'en');
}

function isMaintenanceMode() {
    return getSetting('maintenance_mode', '0') === '1';
}

// Profile Image Upload Functions
function uploadProfileImage($file, $user_id) {
    // Create uploads directory if it doesn't exist
    $upload_dir = APP_ROOT . '/uploads/profiles/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 2 * 1024 * 1024; // 2MB

    // Check file size
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 2MB'];
    }

    // Check file type by MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP allowed'];
    }

    // Check extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    // Generate unique filename
    $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/profiles/' . $filename];
    }

    return ['success' => false, 'message' => 'Failed to upload file'];
}

function deleteProfileImage($filepath) {
    if ($filepath && file_exists(APP_ROOT . '/' . $filepath)) {
        return unlink(APP_ROOT . '/' . $filepath);
    }
    return false;
}

function getProfileImage($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $profile_image = $row['profile_image'];
            $stmt->close();
            return $profile_image;
        }

        $stmt->close();
        return null;
    } catch (Exception $e) {
        error_log("Get profile image error: " . $e->getMessage());
        return null;
    }
}

// User Theme Preferences Functions
function getUserTheme($user_id) {
    $defaults = [
        'theme_primary' => '#001f3f',
        'theme_secondary' => '#003366',
        'theme_accent' => '#0074D9',
        'theme_mode' => 'light'
    ];

    try {
        $conn = getDBConnection();

        $stmt = @$conn->prepare("SELECT theme_primary, theme_secondary, theme_accent, theme_mode FROM users WHERE id = ?");
        if (!$stmt) {
            return $defaults; // Columns don't exist yet
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return [
                'theme_primary' => $row['theme_primary'] ?? $defaults['theme_primary'],
                'theme_secondary' => $row['theme_secondary'] ?? $defaults['theme_secondary'],
                'theme_accent' => $row['theme_accent'] ?? $defaults['theme_accent'],
                'theme_mode' => $row['theme_mode'] ?? $defaults['theme_mode']
            ];
        }

        $stmt->close();
        return $defaults;
    } catch (Exception $e) {
        error_log("Get user theme error: " . $e->getMessage());
        return $defaults;
    }
}

function setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode) {
    try {
        $conn = getDBConnection();

        $stmt = @$conn->prepare("UPDATE users SET theme_primary = ?, theme_secondary = ?, theme_accent = ?, theme_mode = ? WHERE id = ?");
        if (!$stmt) {
            return false; // Columns don't exist, need to run setup.php first
        }
        $stmt->bind_param("ssssi", $theme_primary, $theme_secondary, $theme_accent, $theme_mode, $user_id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("Set user theme error: " . $e->getMessage());
        return false;
    }
}

// Site Branding Functions
function getSiteBranding() {
    $defaults = [
        'site_name' => 'Dashboard System',
        'site_logo' => '',
        'copyright_text' => '&copy; 2026 Dashboard System. All rights reserved.'
    ];

    try {
        $name = getSetting('site_name', '');
        $logo = getSetting('site_logo', '');
        $copyright = getSetting('copyright_text', '');

        return [
            'site_name' => !empty($name) ? $name : $defaults['site_name'],
            'site_logo' => !empty($logo) ? $logo : $defaults['site_logo'],
            'copyright_text' => !empty($copyright) ? $copyright : $defaults['copyright_text']
        ];
    } catch (Exception $e) {
        return $defaults;
    }
}

// ============================================
// SMTP Email Functions
// ============================================

function sendEmail($to, $subject, $htmlBody) {
    $smtp_enabled = getSetting('smtp_enabled', '0');
    if ($smtp_enabled !== '1') {
        return ['success' => false, 'message' => 'SMTP is not enabled'];
    }

    $host = getSetting('smtp_host', '');
    $port = intval(getSetting('smtp_port', '587'));
    $username = getSetting('smtp_username', '');
    $password = getSetting('smtp_password', '');
    $from_email = getSetting('smtp_from_email', '');
    $from_name = getSetting('smtp_from_name', 'Dashboard System');
    $encryption = getSetting('smtp_encryption', 'tls');

    if (empty($host) || empty($username) || empty($password) || empty($from_email)) {
        return ['success' => false, 'message' => 'SMTP credentials not configured'];
    }

    try {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $prefix . $host . ':' . $port,
            $errno, $errstr, 30,
            STREAM_CLIENT_CONNECT, $context
        );

        if (!$socket) {
            return ['success' => false, 'message' => "Connection failed: $errstr"];
        }

        stream_set_timeout($socket, 10);

        $readResponse = function() use ($socket) {
            $response = '';
            while ($line = fgets($socket, 512)) {
                $response .= $line;
                if (isset($line[3]) && ($line[3] === ' ' || $line[3] === "\r")) break;
            }
            return trim($response);
        };

        $sendCommand = function($cmd) use ($socket, $readResponse) {
            fputs($socket, $cmd . "\r\n");
            return $readResponse();
        };

        $readResponse(); // greeting
        $sendCommand('EHLO ' . gethostname());

        if ($encryption === 'tls') {
            $resp = $sendCommand('STARTTLS');
            if (strpos($resp, '220') === false) {
                fclose($socket);
                return ['success' => false, 'message' => 'STARTTLS failed'];
            }
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (!stream_socket_enable_crypto($socket, true, $crypto)) {
                fclose($socket);
                return ['success' => false, 'message' => 'TLS encryption failed'];
            }
            $sendCommand('EHLO ' . gethostname());
        }

        $sendCommand('AUTH LOGIN');
        $sendCommand(base64_encode($username));
        $authResp = $sendCommand(base64_encode($password));

        if (strpos($authResp, '235') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Authentication failed. Check username/password.'];
        }

        $resp = $sendCommand("MAIL FROM:<$from_email>");
        if (strpos($resp, '250') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Sender rejected by server'];
        }

        $resp = $sendCommand("RCPT TO:<$to>");
        if (strpos($resp, '250') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Recipient rejected by server'];
        }

        $resp = $sendCommand('DATA');
        if (strpos($resp, '354') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'DATA command rejected'];
        }

        $msg = "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <$from_email>\r\n";
        $msg .= "To: $to\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Date: " . date('r') . "\r\n";
        $msg .= "Message-ID: <" . uniqid() . "@" . gethostname() . ">\r\n";
        $msg .= "\r\n";
        $msg .= $htmlBody . "\r\n.\r\n";

        fputs($socket, $msg);
        $sendResp = $readResponse();

        $sendCommand('QUIT');
        fclose($socket);

        if (strpos($sendResp, '250') !== false) {
            return ['success' => true, 'message' => 'Email sent successfully'];
        }

        return ['success' => false, 'message' => 'Email delivery failed'];

    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) fclose($socket);
        return ['success' => false, 'message' => 'SMTP error: ' . $e->getMessage()];
    }
}

function getOTPEmailTemplate($otp, $purpose = 'verify') {
    $branding = getSiteBranding();
    $siteName = htmlspecialchars($branding['site_name']);

    $title = ($purpose === 'verify') ? 'Verify Your Email' : 'Reset Your Password';
    $message = ($purpose === 'verify')
        ? 'Use the following OTP code to verify your email address:'
        : 'Use the following OTP code to reset your password:';

    return '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<div style="background:#001f3f;padding:20px;text-align:center;border-radius:8px 8px 0 0;">'
        . '<h1 style="color:#fff;margin:0;font-size:22px;">' . $siteName . '</h1>'
        . '</div>'
        . '<div style="background:#fff;padding:30px;border:1px solid #e9ecef;border-top:none;">'
        . '<h2 style="color:#333;margin-top:0;">' . $title . '</h2>'
        . '<p style="color:#666;">' . $message . '</p>'
        . '<div style="background:#f8f9fa;padding:20px;text-align:center;border-radius:8px;margin:20px 0;">'
        . '<span style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#001f3f;">' . $otp . '</span>'
        . '</div>'
        . '<p style="color:#999;font-size:13px;">This code expires in 10 minutes. Do not share it with anyone.</p>'
        . '</div>'
        . '<div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0 0 8px 8px;border:1px solid #e9ecef;border-top:none;">'
        . '<p style="color:#999;font-size:12px;margin:0;">This is an automated message. Please do not reply.</p>'
        . '</div></div>';
}

// ============================================
// OTP & Email Verification Functions
// ============================================

function generateOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function createEmailVerification($user_id, $email) {
    $otp = generateOTP();
    $conn = getDBConnection();

    $stmt = $conn->prepare("DELETE FROM email_verifications WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO email_verifications (user_id, email, otp_code, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    $stmt->bind_param("iss", $user_id, $email, $otp);
    $stmt->execute();
    $stmt->close();

    return $otp;
}

function verifyEmailOTP($email, $otp) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT user_id FROM email_verifications WHERE email = ? AND otp_code = ? AND used = 0 AND expires_at > NOW()");
    $stmt->bind_param("ss", $email, $otp);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_id = $row['user_id'];
        $stmt->close();

        $update = $conn->prepare("UPDATE email_verifications SET used = 1 WHERE email = ? AND otp_code = ?");
        $update->bind_param("ss", $email, $otp);
        $update->execute();
        $update->close();

        $verify = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
        $verify->bind_param("i", $user_id);
        $verify->execute();
        $verify->close();

        return true;
    }

    $stmt->close();
    return false;
}

// ============================================
// Password Reset Functions
// ============================================

function createPasswordReset($email) {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        $stmt->close();
        return false;
    }
    $stmt->close();

    $otp = generateOTP();

    $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $del->bind_param("s", $email);
    $del->execute();
    $del->close();

    $stmt = $conn->prepare("INSERT INTO password_resets (email, otp_code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    $stmt->bind_param("ss", $email, $otp);
    $stmt->execute();
    $stmt->close();

    return $otp;
}

function verifyPasswordResetOTP($email, $otp) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id FROM password_resets WHERE email = ? AND otp_code = ? AND used = 0 AND expires_at > NOW()");
    $stmt->bind_param("ss", $email, $otp);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $stmt->close();

        $update = $conn->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND otp_code = ?");
        $update->bind_param("ss", $email, $otp);
        $update->execute();
        $update->close();

        return true;
    }

    $stmt->close();
    return false;
}

// ============================================
// Remember Me Functions
// ============================================

function createRememberToken($user_id) {
    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);

    $conn = getDBConnection();

    $del = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
    $del->bind_param("i", $user_id);
    $del->execute();
    $del->close();

    $stmt = $conn->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $stmt->bind_param("is", $user_id, $token_hash);
    $stmt->execute();
    $stmt->close();

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', $token, time() + (86400 * 30), '/', '', $secure, true);

    return $token;
}

function validateRememberToken() {
    if (!isset($_COOKIE['remember_token'])) return null;

    $token = $_COOKIE['remember_token'];
    $token_hash = hash('sha256', $token);

    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT rt.user_id, u.username, u.role FROM remember_tokens rt JOIN users u ON rt.user_id = u.id WHERE rt.token_hash = ? AND rt.expires_at > NOW()");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $stmt->close();
        return $user;
    }

    $stmt->close();

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', '', time() - 3600, '/', '', $secure, true);
    return null;
}

function clearRememberToken($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Table may not exist yet
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', '', time() - 3600, '/', '', $secure, true);
}

// ============================================
// Session Tracking Functions
// ============================================

function trackUserSession($user_id) {
    try {
        $conn = getDBConnection();
        $session_id = session_id();
        $ip = $_SERVER['REMOTE_ADDR'];
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';

        $stmt = $conn->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, last_activity)
                               VALUES (?, ?, ?, ?, NOW())
                               ON DUPLICATE KEY UPDATE last_activity = NOW(), ip_address = ?, user_agent = ?");
        $stmt->bind_param("isssss", $user_id, $session_id, $ip, $user_agent, $ip, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

function removeUserSession() {
    try {
        $conn = getDBConnection();
        $session_id = session_id();
        $stmt = $conn->prepare("DELETE FROM user_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $session_id);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

function checkForceLogout() {
    try {
        $conn = getDBConnection();
        $session_id = session_id();
        $stmt = $conn->prepare("SELECT force_logout FROM user_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $session_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['force_logout'] == 1;
        }
        $stmt->close();
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function cleanExpiredSessions($timeout = 1800) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->bind_param("i", $timeout);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

// ============================================
// Notification Functions
// ============================================

function createNotification($user_id, $title, $message, $type = 'info', $link = null) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $user_id, $title, $message, $type, $link);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Exception $e) {
        error_log("Create notification error: " . $e->getMessage());
        return false;
    }
}

function createNotificationForAdmins($title, $message, $type = 'info', $link = null) {
    try {
        $conn = getDBConnection();
        // roles flagged to receive owner alerts (falls back to the built-in bosses)
        $keys = [];
        $rr = @$conn->query("SELECT role_key FROM roles WHERE receives_admin_alerts = 1 AND is_deleted = 0 AND status = 'active'");
        if ($rr) while ($x = $rr->fetch_assoc()) $keys[] = $x['role_key'];
        if (!$keys) $keys = [ROLE_SUPER_ADMIN, ROLE_ADMIN];
        $in = implode(',', array_map(function ($k) use ($conn) { return "'" . $conn->real_escape_string($k) . "'"; }, $keys));
        $result = $conn->query("SELECT id FROM users WHERE role IN ($in) AND is_deleted = 0 AND status = 'active'");
        while ($row = $result->fetch_assoc()) {
            createNotification($row['id'], $title, $message, $type, $link);
        }
        return true;
    } catch (Exception $e) {
        error_log("Admin notification error: " . $e->getMessage());
        return false;
    }
}

function getUnreadNotificationCount($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['count'];
    } catch (Exception $e) {
        return 0;
    }
}

function getRecentNotifications($user_id, $limit = 10) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
        $stmt->close();
        return $notifications;
    } catch (Exception $e) {
        return [];
    }
}

function markNotificationRead($id, $user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function markAllNotificationsRead($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Generate CSS variables for user theme
function generateUserThemeCSS($user_id) {
    $theme = getUserTheme($user_id);

    // Calculate hover color (slightly lighter/darker)
    $primary = $theme['theme_primary'];

    $css = "<style id='user-theme-css'>\n";
    $css .= ":root {\n";
    $css .= "    --navy-primary: {$theme['theme_primary']};\n";
    $css .= "    --navy-light: {$theme['theme_secondary']};\n";
    $css .= "    --navy-accent: {$theme['theme_accent']};\n";
    $css .= "    --navy-dark: {$theme['theme_primary']};\n";
    $css .= "    --navy-hover: {$theme['theme_secondary']};\n";
    $css .= "}\n";
    $css .= "</style>\n";

    return $css;
}

// ============================================
// Stock + Ledger Engine
// ============================================

// next doc number: ITM-0007 / GRN-0042 — max+1 on the numeric tail, per prefix
function nextDocNumber($conn, $table, $col, $prefix, $pad = 4) {
    $like = $prefix . '%';
    $st = $conn->prepare("SELECT `$col` FROM `$table` WHERE `$col` LIKE ? ORDER BY LENGTH(`$col`) DESC, `$col` DESC LIMIT 1");
    $st->bind_param("s", $like);
    $st->execute();
    $last = $st->get_result()->fetch_assoc();
    $st->close();
    $n = $last ? (int)substr($last[$col], strlen($prefix)) : 0;
    return $prefix . str_pad($n + 1, $pad, '0', STR_PAD_LEFT);
}

// default cash account — payments with no bank account land here
function cashAccountId($conn) {
    static $id = null;
    if ($id !== null) return $id;
    $id = 0;
    try {
        $r = @$conn->query("SELECT id FROM bank_accounts WHERE is_deleted = 0 AND status = 'active' ORDER BY (account_type = 'cash') DESC, is_default DESC, id ASC LIMIT 1");
        if ($r && ($x = $r->fetch_assoc())) $id = (int)$x['id'];
    } catch (Throwable $e) {}
    return $id;
}

// recompute stock_items.current_qty + avg_cost from movements (or serial units), alert on low stock
function recomputeItemStock($conn, $item_id) {
    try {
        $item_id = (int)$item_id;
        $st = $conn->prepare("SELECT item_code, name, tracking_type, opening_qty, current_qty, reorder_level FROM stock_items WHERE id = ? AND is_deleted = 0");
        $st->bind_param("i", $item_id);
        $st->execute();
        $it = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$it) return false;

        $before  = (float)$it['current_qty'];
        $reorder = (float)$it['reorder_level'];

        if ($it['tracking_type'] === 'serial') {
            $q = $conn->prepare("SELECT COUNT(*) c FROM equipment WHERE item_id = ? AND is_deleted = 0 AND status = 'in_stock'");
            $q->bind_param("i", $item_id);
            $q->execute();
            $qty = (float)$q->get_result()->fetch_assoc()['c'];
            $q->close();
        } else {
            // purchase/return add, issue/damage subtract, adjustment carries its own sign
            $q = $conn->prepare("SELECT COALESCE(SUM(CASE
                        WHEN m.movement_type IN ('purchase_in','return_in') THEN mi.qty
                        WHEN m.movement_type IN ('issue_out','damage_out')  THEN -mi.qty
                        ELSE mi.qty END), 0) AS net
                    FROM stock_movement_items mi
                    JOIN stock_movements m ON m.id = mi.movement_id
                    WHERE mi.item_id = ? AND m.is_deleted = 0 AND m.status = 'posted'");
            $q->bind_param("i", $item_id);
            $q->execute();
            $qty = (float)$it['opening_qty'] + (float)$q->get_result()->fetch_assoc()['net'];
            $q->close();
        }

        // weighted avg purchase cost
        $a = $conn->prepare("SELECT COALESCE(SUM(mi.qty * mi.unit_price), 0) val, COALESCE(SUM(mi.qty), 0) q
                FROM stock_movement_items mi
                JOIN stock_movements m ON m.id = mi.movement_id
                WHERE mi.item_id = ? AND m.movement_type = 'purchase_in' AND m.is_deleted = 0 AND m.status = 'posted'");
        $a->bind_param("i", $item_id);
        $a->execute();
        $av = $a->get_result()->fetch_assoc();
        $a->close();
        $avg = (float)$av['q'] > 0 ? round((float)$av['val'] / (float)$av['q'], 2) : 0.0;

        $u = $conn->prepare("UPDATE stock_items SET current_qty = ?, avg_cost = ? WHERE id = ?");
        $u->bind_param("ddi", $qty, $avg, $item_id);
        $u->execute();
        $u->close();

        // alert only on the crossing, never on every save
        if ($reorder > 0 && $before > $reorder && $qty <= $reorder) {
            try {
                createNotificationForAdmins(
                    'Low Stock Alert',
                    $it['name'] . ' (' . $it['item_code'] . ') is down to ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' — reorder level is ' . rtrim(rtrim(number_format($reorder, 2, '.', ''), '0'), '.') . '.',
                    'warning',
                    'stock.php'
                );
            } catch (Throwable $e) {}
        }
        return $qty;
    } catch (Throwable $e) { error_log("ledger/stock helper: " . $e->getMessage()); return false; }
}

// ── Invoice ↔ Stock ─────────────────────────────────────────────
// selling inventory on an invoice: lines are validated here, the qty is moved by ONE auto
// issue_out movement (so stock history stays in stock_movements), invoice_items is what prints.

function stockQtyTxt($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }

// validate posted lines against live stock — throws on the first bad line, returns clean rows + money total
function invoiceStockLinesClean($conn, $lines) {
    if (is_string($lines)) $lines = json_decode($lines, true);
    if (!is_array($lines) || !count($lines)) return ['lines' => [], 'total' => 0.0];

    $clean = []; $total = 0.0; $seen = [];
    foreach ($lines as $ln) {
        $itemId  = (int)($ln['item_id'] ?? 0);
        $qty     = round((float)($ln['qty'] ?? 0), 2);
        $rate    = round((float)($ln['unit_price'] ?? 0), 2);
        $serials = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($ln['serials'] ?? ''))))));

        if ($itemId <= 0) throw new Exception('A stock line has no item selected');
        if ($rate < 0)    throw new Exception('Item rate cannot be negative');
        if (isset($seen[$itemId])) throw new Exception('The same item is on two lines — merge them into one');
        $seen[$itemId] = 1;

        $g = $conn->prepare("SELECT id, item_code, name, unit, tracking_type, current_qty, sale_price
                             FROM stock_items WHERE id = ? AND is_deleted = 0 AND status = 'active'");
        $g->bind_param("i", $itemId);
        $g->execute();
        $item = $g->get_result()->fetch_assoc();
        $g->close();
        if (!$item) throw new Exception('Unknown or inactive stock item on a line');

        if ($item['tracking_type'] === 'serial') {
            if (!count($serials)) throw new Exception($item['name'] . ' is serial-tracked — pick its serial numbers');
            $qty = count($serials); // the serials ARE the quantity
            foreach ($serials as $sn) {
                $q = $conn->prepare("SELECT item_id, status FROM equipment WHERE serial = ? AND is_deleted = 0");
                $q->bind_param("s", $sn);
                $q->execute();
                $eq = $q->get_result()->fetch_assoc();
                $q->close();
                if (!$eq)                            throw new Exception("Serial $sn was never received into stock");
                if ((int)$eq['item_id'] !== $itemId) throw new Exception("Serial $sn belongs to a different item");
                if ($eq['status'] !== 'in_stock')    throw new Exception("Serial $sn is not in store (status: {$eq['status']})");
            }
        } else {
            if ($qty <= 0) throw new Exception('Quantity is required for ' . $item['name']);
            if ($qty > (float)$item['current_qty'])
                throw new Exception('Only ' . stockQtyTxt($item['current_qty']) . ' ' . $item['unit'] . ' of ' . $item['name'] . ' left in store');
        }

        $lt = round($qty * $rate, 2);
        $total += $lt;
        $clean[] = ['item' => $item, 'qty' => $qty, 'rate' => $rate, 'line_total' => $lt, 'serials' => $serials];
    }
    return ['lines' => $clean, 'total' => round($total, 2)];
}

// write validated lines: ONE issue_out movement moves the qty, invoice_items keeps what was billed
function writeInvoiceStock($conn, $invoiceId, $clean, $uid = null) {
    $invoiceId = (int)$invoiceId;
    $uid = $uid ?? ($_SESSION['user_id'] ?? null);
    $itemsTotal = 0.0;
    foreach ($clean as $c) $itemsTotal += $c['line_total'];
    $itemsTotal = round($itemsTotal, 2);

    if (!count($clean)) {
        $u = $conn->prepare("UPDATE invoices SET items_total = 0, stock_movement_id = NULL WHERE id = ?");
        $u->bind_param("i", $invoiceId);
        $u->execute();
        $u->close();
        return 0.0;
    }

    // the invoice header drives the movement — same customer, same date, same area
    $g = $conn->prepare("SELECT i.invoice_number, i.customer_id, i.issue_date, c.area_id
                         FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                         WHERE i.id = ?");
    $g->bind_param("i", $invoiceId);
    $g->execute();
    $inv = $g->get_result()->fetch_assoc();
    $g->close();
    if (!$inv) throw new Exception('Invoice not found for stock issue');

    $number = nextDocNumber($conn, 'stock_movements', 'movement_number', 'ISS-');
    $date   = $inv['issue_date'] ?: date('Y-m-d');
    $cust   = (int)$inv['customer_id'];
    $area   = $inv['area_id'] !== null ? (int)$inv['area_id'] : null;
    $ref    = $inv['invoice_number'];
    $note   = 'Auto-issued against invoice ' . $inv['invoice_number'];

    $h = $conn->prepare("INSERT INTO stock_movements
            (movement_number, movement_type, movement_date, customer_id, area_id, reference,
             total_amount, notes, invoice_id, created_by_user_id)
            VALUES (?, 'issue_out', ?, ?, ?, ?, ?, ?, ?, ?)");
    $h->bind_param("ssiisdsii", $number, $date, $cust, $area, $ref, $itemsTotal, $note, $invoiceId, $uid);
    $h->execute();
    $mid = $conn->insert_id;
    $h->close();

    $li = $conn->prepare("INSERT INTO stock_movement_items (movement_id, item_id, qty, unit_price, line_total, serials) VALUES (?,?,?,?,?,?)");
    $ii = $conn->prepare("INSERT INTO invoice_items (invoice_id, item_id, item_code, description, qty, unit, unit_price, line_total, serials, sort_order)
                          VALUES (?,?,?,?,?,?,?,?,?,?)");
    $eq = $conn->prepare("UPDATE equipment SET status='assigned', assigned_to_customer_id=?, assigned_at=NOW(), movement_id=? WHERE serial=? AND is_deleted=0");
    $sort = 0;
    foreach ($clean as $c) {
        $iid  = (int)$c['item']['id'];
        $code = $c['item']['item_code'];
        $name = $c['item']['name'];
        $unit = $c['item']['unit'];
        $qty  = $c['qty'];
        $rate = $c['rate'];
        $lt   = $c['line_total'];
        $ser  = $c['serials'] ? implode(',', $c['serials']) : null;
        $sort++;

        $li->bind_param("iiddds", $mid, $iid, $qty, $rate, $lt, $ser);
        $li->execute();
        $ii->bind_param("iissdsddsi", $invoiceId, $iid, $code, $name, $qty, $unit, $rate, $lt, $ser, $sort);
        $ii->execute();

        // serial units follow the sale — they sit with the customer now
        foreach ($c['serials'] as $sn) {
            $eq->bind_param("iis", $cust, $mid, $sn);
            $eq->execute();
        }
    }
    $li->close(); $ii->close(); $eq->close();

    foreach ($clean as $c) recomputeItemStock($conn, (int)$c['item']['id']);

    $u = $conn->prepare("UPDATE invoices SET items_total = ?, stock_movement_id = ? WHERE id = ?");
    $u->bind_param("dii", $itemsTotal, $mid, $invoiceId);
    $u->execute();
    $u->close();
    return $itemsTotal;
}

// POS counter sale — same story as an invoice: ONE issue_out movement carries the stock out
function writePosStock($conn, $saleId, $clean, $uid = null) {
    $saleId = (int)$saleId;
    $uid    = $uid ?? ($_SESSION['user_id'] ?? null);
    if (!count($clean)) return 0;

    $g = $conn->prepare("SELECT s.sale_number, s.sale_date, s.customer_id, c.area_id
                         FROM pos_sales s LEFT JOIN customers c ON c.id = s.customer_id
                         WHERE s.id = ?");
    $g->bind_param("i", $saleId);
    $g->execute();
    $sale = $g->get_result()->fetch_assoc();
    $g->close();
    if (!$sale) throw new Exception('Sale not found for stock issue');

    $total = 0.0;
    foreach ($clean as $c) $total += $c['line_total'];
    $total  = round($total, 2);
    $number = nextDocNumber($conn, 'stock_movements', 'movement_number', 'POS-');
    $date   = substr($sale['sale_date'], 0, 10);
    $cust   = $sale['customer_id'] !== null ? (int)$sale['customer_id'] : null;
    $area   = $sale['area_id'] !== null ? (int)$sale['area_id'] : null;
    $ref    = $sale['sale_number'];
    $note   = 'Counter sale ' . $sale['sale_number'];

    $h = $conn->prepare("INSERT INTO stock_movements
            (movement_number, movement_type, movement_date, customer_id, area_id, reference,
             bill_number, total_amount, notes, created_by_user_id)
            VALUES (?, 'issue_out', ?, ?, ?, 'POS', ?, ?, ?, ?)");
    $h->bind_param("ssiisdsi", $number, $date, $cust, $area, $ref, $total, $note, $uid);
    $h->execute();
    $mid = $conn->insert_id;
    $h->close();

    $li = $conn->prepare("INSERT INTO stock_movement_items (movement_id, item_id, qty, unit_price, line_total, serials) VALUES (?,?,?,?,?,?)");
    $eq = $conn->prepare("UPDATE equipment SET status = 'issued', assigned_to_customer_id = ?, assigned_at = NOW(), movement_id = ? WHERE serial = ? AND is_deleted = 0");
    foreach ($clean as $c) {
        $iid = (int)$c['item']['id']; $qty = $c['qty']; $rate = $c['rate']; $lt = $c['line_total'];
        $ser = $c['serials'] ? implode(',', $c['serials']) : null;
        $li->bind_param("iiddds", $mid, $iid, $qty, $rate, $lt, $ser);
        $li->execute();
        foreach ($c['serials'] as $sn) { $eq->bind_param("iis", $cust, $mid, $sn); $eq->execute(); }
    }
    $li->close(); $eq->close();

    foreach ($clean as $c) recomputeItemStock($conn, (int)$c['item']['id']);

    $u = $conn->prepare("UPDATE pos_sales SET movement_id = ? WHERE id = ?");
    $u->bind_param("ii", $mid, $saleId);
    $u->execute();
    $u->close();
    return $mid;
}

// void a counter sale — the movement is cancelled so recomputeItemStock puts the qty back
function releasePosStock($conn, $saleId) {
    $saleId = (int)$saleId;
    $g = $conn->prepare("SELECT movement_id FROM pos_sales WHERE id = ?");
    $g->bind_param("i", $saleId);
    $g->execute();
    $row = $g->get_result()->fetch_assoc();
    $g->close();
    $mid = $row && $row['movement_id'] ? (int)$row['movement_id'] : 0;
    if (!$mid) return;

    $q = $conn->prepare("SELECT item_id, serials FROM stock_movement_items WHERE movement_id = ?");
    $q->bind_param("i", $mid);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    $c = $conn->prepare("UPDATE stock_movements SET status = 'cancelled', is_deleted = 1 WHERE id = ?");
    $c->bind_param("i", $mid);
    $c->execute();
    $c->close();

    $eq = $conn->prepare("UPDATE equipment SET status = 'in_stock', assigned_to_customer_id = NULL, assigned_at = NULL WHERE serial = ? AND is_deleted = 0");
    foreach ($rows as $r) {
        foreach (array_filter(array_map('trim', explode(',', (string)$r['serials']))) as $sn) {
            $eq->bind_param("s", $sn); $eq->execute();
        }
        recomputeItemStock($conn, (int)$r['item_id']);
    }
    $eq->close();
}

// hand the stock back to the store. $dropLines = the invoice is being re-saved (lines get rewritten);
// false = invoice cancelled/deleted, so the billed lines stay on the document for the record.
function releaseInvoiceStock($conn, $invoiceId, $dropLines = false) {
    $invoiceId = (int)$invoiceId;
    $g = $conn->prepare("SELECT stock_movement_id FROM invoices WHERE id = ?");
    $g->bind_param("i", $invoiceId);
    $g->execute();
    $row = $g->get_result()->fetch_assoc();
    $g->close();
    $mid = (int)($row['stock_movement_id'] ?? 0);

    $itemIds = [];
    if ($mid > 0) {
        $ls = $conn->prepare("SELECT item_id, serials FROM stock_movement_items WHERE movement_id = ?");
        $ls->bind_param("i", $mid);
        $ls->execute();
        $lr = $ls->get_result();
        $serials = [];
        while ($x = $lr->fetch_assoc()) {
            $itemIds[] = (int)$x['item_id'];
            if (!empty($x['serials'])) foreach (array_filter(array_map('trim', explode(',', $x['serials']))) as $sn) $serials[] = $sn;
        }
        $ls->close();

        // serial units come back into store
        if ($serials) {
            $e = $conn->prepare("UPDATE equipment SET status='in_stock', assigned_to_customer_id=NULL, returned_at=NOW() WHERE serial=? AND movement_id=? AND is_deleted=0");
            foreach ($serials as $sn) { $e->bind_param("si", $sn, $mid); $e->execute(); }
            $e->close();
        }

        // re-save wipes the movement, cancel keeps it visible as cancelled — either way it stops counting
        $u = $conn->prepare($dropLines ? "UPDATE stock_movements SET is_deleted = 1 WHERE id = ?"
                                       : "UPDATE stock_movements SET status = 'cancelled' WHERE id = ?");
        $u->bind_param("i", $mid);
        $u->execute();
        $u->close();
    }

    if ($dropLines) {
        $d = $conn->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
        $d->bind_param("i", $invoiceId);
        $d->execute();
        $d->close();
        $u = $conn->prepare("UPDATE invoices SET items_total = 0, stock_movement_id = NULL WHERE id = ?");
        $u->bind_param("i", $invoiceId);
        $u->execute();
        $u->close();
    }

    foreach (array_unique($itemIds) as $iid) recomputeItemStock($conn, $iid);
    return $itemIds;
}

// billed stock lines for one invoice — printable rows
function invoiceStockItems($conn, $invoiceId) {
    $out = [];
    $st = $conn->prepare("SELECT item_id, item_code, description, qty, unit, unit_price, line_total, serials
                          FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
    $st->bind_param("i", $invoiceId);
    $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) {
        $out[] = [
            'item_id'     => (int)$x['item_id'],
            'item_code'   => $x['item_code'] ?? '',
            'description' => $x['description'],
            'qty'         => stockQtyTxt($x['qty']),
            'unit'        => $x['unit'] ?? '',
            'unit_price'  => number_format((float)$x['unit_price'], 2, '.', ''),
            'line_total'  => number_format((float)$x['line_total'], 2, '.', ''),
            'serials'     => $x['serials'] ?? ''
        ];
    }
    $st->close();
    return $out;
}

// ── Ledger ──────────────────────────────────────────────────────
// one writer. debit = owed to us / held by us, credit = owed by us.
// upsert on (ref_type, ref_id, party_type, party_id) so a re-save can never double-post
function postLedger($conn, $party_type, $party_id, $date, $particulars, $debit, $credit, $ref_type, $ref_id, $ref_number = null, $uid = null) {
    try {
        $uid      = $uid ?? ($_SESSION['user_id'] ?? null);
        $party_id = (int)$party_id;
        $ref_id   = (int)$ref_id;
        $debit    = round((float)$debit, 2);
        $credit   = round((float)$credit, 2);
        $st = $conn->prepare("INSERT INTO ledger_entries
                (entry_date, party_type, party_id, particulars, ref_type, ref_id, ref_number, debit, credit, created_by_user_id, is_deleted)
                VALUES (?,?,?,?,?,?,?,?,?,?,0)
            ON DUPLICATE KEY UPDATE
                entry_date = VALUES(entry_date), particulars = VALUES(particulars),
                ref_number = VALUES(ref_number), debit = VALUES(debit), credit = VALUES(credit), is_deleted = 0");
        if (!$st) return false;
        $st->bind_param("ssissisddi", $date, $party_type, $party_id, $particulars, $ref_type, $ref_id, $ref_number, $debit, $credit, $uid);
        $ok = $st->execute();
        $st->close();
        return $ok;
    } catch (Throwable $e) { error_log("ledger/stock helper: " . $e->getMessage()); return false; }
}

// pull a document's rows out of the ledger (edit = reverse then re-post, delete = reverse)
function reverseLedger($conn, $ref_type, $ref_id) {
    try {
        $ref_id = (int)$ref_id;
        $st = $conn->prepare("UPDATE ledger_entries SET is_deleted = 1 WHERE ref_type = ? AND ref_id = ?");
        if (!$st) return false;
        $st->bind_param("si", $ref_type, $ref_id);
        $ok = $st->execute();
        $st->close();
        return $ok;
    } catch (Throwable $e) { error_log("ledger/stock helper: " . $e->getMessage()); return false; }
}

// closing balance for a party — signed: +ve = Dr, -ve = Cr
function ledgerBalance($conn, $party_type, $party_id, $upto = null) {
    $sql = "SELECT COALESCE(SUM(debit - credit), 0) b FROM ledger_entries WHERE is_deleted = 0 AND party_type = ? AND party_id = ?";
    if ($upto) $sql .= " AND entry_date <= ?";
    $st = $conn->prepare($sql);
    $pid = (int)$party_id;
    if ($upto) $st->bind_param("sis", $party_type, $pid, $upto);
    else       $st->bind_param("si", $party_type, $pid);
    $st->execute();
    $b = (float)$st->get_result()->fetch_assoc()['b'];
    $st->close();
    return round($b, 2);
}

// ── one reconciler per document type — reverse then re-post, safe to call after ANY save ──
function syncInvoiceLedger($conn, $id) {
    try {
        $id = (int)$id;
        $st = $conn->prepare("SELECT invoice_number, customer_id, billing_month, total, issue_date, created_at, is_deleted, cancelled_at FROM invoices WHERE id = ?");
        $st->bind_param("i", $id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        reverseLedger($conn, 'invoice', $id);
        if (!$r || (int)$r['is_deleted'] === 1 || !empty($r['cancelled_at'])) return;
        $date = !empty($r['issue_date']) ? $r['issue_date'] : substr((string)$r['created_at'], 0, 10);
        postLedger($conn, 'customer', $r['customer_id'], $date,
            'Invoice ' . $r['invoice_number'] . ' (' . $r['billing_month'] . ')',
            $r['total'], 0, 'invoice', $id, $r['invoice_number']);
    } catch (Throwable $e) { error_log("ledger/stock helper: " . $e->getMessage()); return false; }
}

function syncPaymentLedger($conn, $id) {
    try {
        $id = (int)$id;
        $st = $conn->prepare("SELECT receipt_number, customer_id, amount, refunded_amount, payment_date, status, is_deleted, bank_account_id FROM payments WHERE id = ?");
        $st->bind_param("i", $id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        reverseLedger($conn, 'payment', $id);
        if (!$r || (int)$r['is_deleted'] === 1 || $r['status'] !== 'cleared') return;
        $net = round((float)$r['amount'] - (float)($r['refunded_amount'] ?? 0), 2);
        if ($net <= 0) return;
        postLedger($conn, 'customer', $r['customer_id'], $r['payment_date'],
            'Payment received ' . $r['receipt_number'], 0, $net, 'payment', $id, $r['receipt_number']);
        $acc  = (int)($r['bank_account_id'] ?? 0);
        $type = $acc > 0 ? 'bank' : 'cash';
        if ($acc <= 0) $acc = cashAccountId($conn);
        postLedger($conn, $type, $acc, $r['payment_date'],
            'Receipt ' . $r['receipt_number'], $net, 0, 'payment', $id, $r['receipt_number']);
    } catch (Throwable $e) { error_log("ledger/stock helper: " . $e->getMessage()); return false; }
}

function syncExpenseLedger($conn, $id) {
    try {
        $id = (int)$id;
        $st = $conn->prepare("SELECT expense_number, vendor_id, amount, expense_date, description, is_deleted FROM expenses WHERE id = ?");
        $st->bind_param("i", $id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        reverseLedger($conn, 'expense', $id);
        if (!$r || (int)$r['is_deleted'] === 1 || (int)($r['vendor_id'] ?? 0) <= 0) return;
        $num = $r['expense_number'] !== null && $r['expense_number'] !== '' ? $r['expense_number'] : ('EXP-' . $id);
        postLedger($conn, 'vendor', $r['vendor_id'], $r['expense_date'],
            'Bill ' . $num . ' — ' . mb_substr((string)$r['description'], 0, 120), 0, $r['amount'], 'expense', $id, $num);
    } catch (Throwable $e) { error_log("ledger/stock helper: " . $e->getMessage()); return false; }
}

function syncExpensePaymentLedger($conn, $id) {
    try {
        $id = (int)$id;
        $st = $conn->prepare("SELECT ep.amount, ep.payment_date, ep.reference, ep.is_deleted, ep.bank_account_id,
                                     e.vendor_id, e.vendor_name, e.is_deleted AS e_deleted
                              FROM expense_payments ep JOIN expenses e ON e.id = ep.expense_id WHERE ep.id = ?");
        $st->bind_param("i", $id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        reverseLedger($conn, 'expense_payment', $id);
        if (!$r || (int)$r['is_deleted'] === 1 || (int)$r['e_deleted'] === 1) return;
        $amt = round((float)$r['amount'], 2);
        if ($amt <= 0) return;
        $ref = $r['reference'] !== null && $r['reference'] !== '' ? $r['reference'] : ('EP-' . $id);
        if ((int)($r['vendor_id'] ?? 0) > 0)
            postLedger($conn, 'vendor', $r['vendor_id'], $r['payment_date'], 'Paid vendor — ' . $ref, $amt, 0, 'expense_payment', $id, $ref);
        $acc  = (int)($r['bank_account_id'] ?? 0);
        $type = $acc > 0 ? 'bank' : 'cash';
        if ($acc <= 0) $acc = cashAccountId($conn);
        postLedger($conn, $type, $acc, $r['payment_date'],
            'Paid ' . ($r['vendor_name'] ?: 'vendor') . ' — ' . $ref, 0, $amt, 'expense_payment', $id, $ref);
    } catch (Throwable $e) { error_log("ledger/stock helper: " . $e->getMessage()); return false; }
}

// "12,500.00 Dr" / "—"
function fmtDrCr($amount, $currency = '') {
    $a = round((float)$amount, 2);
    if (abs($a) < 0.005) return $currency . '0.00';
    return $currency . number_format(abs($a), 2) . ' ' . ($a > 0 ? 'Dr' : 'Cr');
}
?>
