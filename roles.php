<?php
 
require_once 'config.php';

requirePerm('roles', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'roles';
$can_edit     = can('roles', 'e');
$can_add      = can('roles', 'a');
$can_delete   = can('roles', 'd');

$SCOPES = ['all' => 'All data', 'area' => 'Own areas only', 'own' => 'Own records only'];

// role_key from a label: "Installation Engineer" -> installation_engineer
function slugRoleKey($label) {
    $k = strtolower(trim($label));
    $k = preg_replace('/[^a-z0-9]+/', '_', $k);
    return trim(substr($k, 0, 50), '_');
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getRoles': {
                $rows = [];
                $res = $conn->query("SELECT r.*,
                        (SELECT COUNT(*) FROM users u WHERE u.role = r.role_key AND u.is_deleted = 0) AS staff_count
                    FROM roles r WHERE r.is_deleted = 0 ORDER BY r.sort_order ASC, r.id ASC");
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'          => (int)$r['id'],
                        'role_key'    => $r['role_key'],
                        'label'       => $r['label'],
                        'description' => $r['description'],
                        'badge_color' => $r['badge_color'],
                        'icon'        => $r['icon'],
                        'data_scope'  => $r['data_scope'],
                        'is_system'   => (int)$r['is_system'],
                        'is_super'    => (int)$r['is_super'],
                        'alerts'      => (int)$r['receives_admin_alerts'],
                        'sort_order'  => (int)$r['sort_order'],
                        'status'      => $r['status'],
                        'staff_count' => (int)$r['staff_count'],
                    ];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            // pages + roles + the full grid in one shot
            case 'getMatrix': {
                $pages = [];
                foreach (rbacPages() as $key => $meta) {
                    $pages[] = ['key' => $key, 'label' => $meta[0], 'group' => $meta[1], 'icon' => $meta[2]];
                }
                $roles = [];
                $res = $conn->query("SELECT role_key, label, badge_color, is_system, is_super FROM roles WHERE is_deleted = 0 AND status = 'active' ORDER BY sort_order ASC, id ASC");
                while ($r = $res->fetch_assoc()) {
                    $roles[] = ['key' => $r['role_key'], 'label' => $r['label'], 'color' => $r['badge_color'], 'is_system' => (int)$r['is_system'], 'is_super' => (int)$r['is_super']];
                }
                $grid = [];
                $res = $conn->query("SELECT role_key, page_key, can_view, can_add, can_edit, can_delete FROM role_permissions");
                while ($r = $res->fetch_assoc()) {
                    $grid[$r['role_key']][$r['page_key']] = ['v' => (int)$r['can_view'], 'a' => (int)$r['can_add'], 'e' => (int)$r['can_edit'], 'd' => (int)$r['can_delete']];
                }
                echo json_encode(['success' => true, 'pages' => $pages, 'roles' => $roles, 'grid' => $grid, 'can_edit' => $can_edit]);
                exit();
            }

            case 'addRole':
            case 'updateRole': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                $isEdit = $_GET['action'] === 'updateRole';
                if ($isEdit && !$can_edit) { echo json_encode(['success' => false, 'message' => 'You cannot edit roles']); exit(); }
                if (!$isEdit && !$can_add)  { echo json_encode(['success' => false, 'message' => 'You cannot add roles']); exit(); }

                $id     = (int)($_POST['id'] ?? 0);
                $label  = trim($_POST['label'] ?? '');
                $desc   = trim($_POST['description'] ?? '');
                $color  = trim($_POST['badge_color'] ?? '#0074D9');
                $icon   = trim($_POST['icon'] ?? 'fas fa-user-tag');
                $scope  = $_POST['data_scope'] ?? 'all';
                $alerts = !empty($_POST['receives_admin_alerts']) ? 1 : 0;
                $sort   = (int)($_POST['sort_order'] ?? 50);
                $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

                if ($label === '')                          { echo json_encode(['success' => false, 'message' => 'Role name is required']); exit(); }
                if (mb_strlen($label) > 60)                 { echo json_encode(['success' => false, 'message' => 'Role name too long (max 60)']); exit(); }
                if (!isset(['all' => 1, 'area' => 1, 'own' => 1][$scope])) { echo json_encode(['success' => false, 'message' => 'Invalid data scope']); exit(); }
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { echo json_encode(['success' => false, 'message' => 'Colour must be a hex value like #0074D9']); exit(); }
                if ($icon === '' || mb_strlen($icon) > 40)  { echo json_encode(['success' => false, 'message' => 'Invalid icon class']); exit(); }

                if ($isEdit) {
                    $g = $conn->prepare("SELECT role_key, is_system, is_super, data_scope FROM roles WHERE id = ? AND is_deleted = 0");
                    $g->bind_param("i", $id);
                    $g->execute();
                    $cur = $g->get_result()->fetch_assoc();
                    $g->close();
                    if (!$cur) { echo json_encode(['success' => false, 'message' => 'Role not found']); exit(); }
                    // system rows keep their key + scope; only the owner may touch the owner role
                    if ((int)$cur['is_super'] === 1 && !isSuperAdmin()) { echo json_encode(['success' => false, 'message' => 'Only the owner can edit the owner role']); exit(); }
                    if ((int)$cur['is_system'] === 1) $scope = $cur['data_scope']; // built-in scope is fixed

                    $u = $conn->prepare("UPDATE roles SET label=?, description=?, badge_color=?, icon=?, data_scope=?, receives_admin_alerts=?, sort_order=?, status=? WHERE id=?");
                    $u->bind_param("sssssiisi", $label, $desc, $color, $icon, $scope, $alerts, $sort, $status, $id);
                    $ok = $u->execute();
                    $u->close();
                    if (!$ok) { echo json_encode(['success' => false, 'message' => 'Failed to update role']); exit(); }
                    logActivity($user_id, $username, 'Role Updated', "Updated role {$cur['role_key']} ($label)");
                    echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
                    exit();
                }

                $key = slugRoleKey($_POST['role_key'] ?? $label);
                if ($key === '') { echo json_encode(['success' => false, 'message' => 'Could not build a role key from that name']); exit(); }
                $c = $conn->prepare("SELECT id FROM roles WHERE role_key = ?");
                $c->bind_param("s", $key);
                $c->execute();
                $dupe = $c->get_result()->num_rows > 0;
                $c->close();
                if ($dupe) { echo json_encode(['success' => false, 'message' => "Role key '$key' already exists"]); exit(); }

                $i = $conn->prepare("INSERT INTO roles (role_key, label, description, badge_color, icon, data_scope, is_system, is_super, receives_admin_alerts, sort_order, status)
                                     VALUES (?,?,?,?,?,?,0,0,?,?,?)");
                $i->bind_param("ssssssiis", $key, $label, $desc, $color, $icon, $scope, $alerts, $sort, $status);
                $ok = $i->execute();
                $i->close();
                if (!$ok) { echo json_encode(['success' => false, 'message' => 'Failed to create role']); exit(); }

                // seed the new role's row in the matrix — dashboard only, tick the rest in the grid
                $ins = $conn->prepare("INSERT INTO role_permissions (role_key, page_key, can_view, can_add, can_edit, can_delete) VALUES (?,?,?,0,0,0)");
                foreach (array_keys(rbacPages()) as $pk) {
                    $v = $pk === 'dashboard' ? 1 : 0;
                    $ins->bind_param("ssi", $key, $pk, $v);
                    $ins->execute();
                }
                $ins->close();
                logActivity($user_id, $username, 'Role Created', "Created custom role $key ($label)");
                echo json_encode(['success' => true, 'message' => "Role \"$label\" created — now tick its permissions in the matrix"]);
                exit();
            }

            case 'deleteRole': {
                if (!$can_delete) { echo json_encode(['success' => false, 'message' => 'Only the owner can delete roles']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                $g = $conn->prepare("SELECT role_key, label, is_system FROM roles WHERE id = ? AND is_deleted = 0");
                $g->bind_param("i", $id);
                $g->execute();
                $r = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$r) { echo json_encode(['success' => false, 'message' => 'Role not found']); exit(); }
                if ((int)$r['is_system'] === 1) { echo json_encode(['success' => false, 'message' => 'Built-in roles cannot be deleted']); exit(); }

                $c = $conn->prepare("SELECT COUNT(*) n FROM users WHERE role = ? AND is_deleted = 0");
                $c->bind_param("s", $r['role_key']);
                $c->execute();
                $n = (int)$c->get_result()->fetch_assoc()['n'];
                $c->close();
                if ($n > 0) { echo json_encode(['success' => false, 'message' => "$n staff member(s) still use this role — move them first"]); exit(); }

                $d = $conn->prepare("UPDATE roles SET is_deleted = 1 WHERE id = ?");
                $d->bind_param("i", $id);
                $d->execute();
                $d->close();
                $dp = $conn->prepare("DELETE FROM role_permissions WHERE role_key = ?");
                $dp->bind_param("s", $r['role_key']);
                $dp->execute();
                $dp->close();
                logActivity($user_id, $username, 'Role Deleted', "Deleted role {$r['role_key']} ({$r['label']})");
                echo json_encode(['success' => true, 'message' => 'Role deleted']);
                exit();
            }

            case 'toggleRoleStatus': {
                if (!$can_edit) { echo json_encode(['success' => false, 'message' => 'You cannot edit roles']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                $g = $conn->prepare("SELECT role_key, status, is_super FROM roles WHERE id = ? AND is_deleted = 0");
                $g->bind_param("i", $id);
                $g->execute();
                $r = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$r) { echo json_encode(['success' => false, 'message' => 'Role not found']); exit(); }
                if ((int)$r['is_super'] === 1) { echo json_encode(['success' => false, 'message' => 'The owner role cannot be disabled']); exit(); }
                $new = $r['status'] === 'active' ? 'inactive' : 'active';
                $u = $conn->prepare("UPDATE roles SET status = ? WHERE id = ?");
                $u->bind_param("si", $new, $id);
                $u->execute();
                $u->close();
                logActivity($user_id, $username, 'Role Status Changed', "Role {$r['role_key']} set to $new");
                echo json_encode(['success' => true, 'message' => "Role set to $new", 'status' => $new]);
                exit();
            }

            // the matrix save — one payload, server re-validates every cell
            case 'savePermissions': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                if (!$can_edit) { echo json_encode(['success' => false, 'message' => 'You cannot edit permissions']); exit(); }

                $payload = json_decode($_POST['grid'] ?? '', true);
                if (!is_array($payload)) { echo json_encode(['success' => false, 'message' => 'Bad payload']); exit(); }

                $validPages = rbacPages();
                $locked = [];   // roles the current user may not rewrite
                $res = $conn->query("SELECT role_key, is_super FROM roles WHERE is_deleted = 0");
                $known = [];
                while ($r = $res->fetch_assoc()) {
                    $known[$r['role_key']] = true;
                    if ((int)$r['is_super'] === 1) $locked[$r['role_key']] = true;
                }

                $conn->begin_transaction();
                try {
                    $up = $conn->prepare("INSERT INTO role_permissions (role_key, page_key, can_view, can_add, can_edit, can_delete)
                                          VALUES (?,?,?,?,?,?)
                                          ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit), can_delete=VALUES(can_delete)");
                    $n = 0;
                    foreach ($payload as $roleKey => $pages) {
                        if (!isset($known[$roleKey]) || isset($locked[$roleKey])) continue; // owner row stays full
                        if (!is_array($pages)) continue;
                        foreach ($pages as $pageKey => $p) {
                            if (!isset($validPages[$pageKey])) continue;
                            $a = !empty($p['a']) ? 1 : 0;
                            $e = !empty($p['e']) ? 1 : 0;
                            $d = !empty($p['d']) ? 1 : 0;
                            // implied-view rule: any of a/e/d forces view on
                            $v = (!empty($p['v']) || $a || $e || $d) ? 1 : 0;
                            if (!$v) { $a = $e = $d = 0; }
                            $up->bind_param("ssiiii", $roleKey, $pageKey, $v, $a, $e, $d);
                            $up->execute();
                            $n++;
                        }
                    }
                    $up->close();
                    $conn->commit();
                    logActivity($user_id, $username, 'Permissions Updated', "Saved permission matrix ($n cells)");
                    echo json_encode(['success' => true, 'message' => "Permissions saved ($n cells)"]);
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
                }
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Roles.php error: " . $e->getMessage());
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
    <title>Roles &amp; Permissions - ISP Billing</title>

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
                <span>Roles &amp; Permissions</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-user-shield"></i> Roles &amp; Permissions</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="summary-grid" id="summaryGrid">
                <div class="summary-card highlight">
                    <div class="summary-card-label"><i class="fas fa-user-tag"></i> Total Roles</div>
                    <div class="summary-card-value" id="smTotal">—</div>
                    <div class="summary-card-sub" id="smTotalSub">&nbsp;</div>
                </div>
                <div class="summary-card">
                    <div class="summary-card-label"><i class="fas fa-lock"></i> Built-in</div>
                    <div class="summary-card-value" id="smSystem">—</div>
                    <div class="summary-card-sub">Locked — cannot be deleted</div>
                </div>
                <div class="summary-card success">
                    <div class="summary-card-label"><i class="fas fa-wand-magic-sparkles"></i> Custom</div>
                    <div class="summary-card-value" id="smCustom">—</div>
                    <div class="summary-card-sub">Created by you</div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-card-label"><i class="fas fa-users"></i> Staff Assigned</div>
                    <div class="summary-card-value" id="smStaff">—</div>
                    <div class="summary-card-sub">Across all roles</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-card-label"><i class="fas fa-user-slash"></i> Unused Roles</div>
                    <div class="summary-card-value" id="smUnused">—</div>
                    <div class="summary-card-sub">No staff on them yet</div>
                </div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-id-card-clip"></i> Staff Roles</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadAll()"><i class="fas fa-sync"></i> Refresh</button>
                        <?php if ($can_add): ?>
                        <button class="btn btn-success" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Role</button>
                        <?php endif; ?>
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
                        <table id="rolesTable" class="display table-full-width"></table>
                    </div>
                </div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table-cells-large"></i> Permission Matrix</h2>
                    <div class="btn-group-inline">
                        <span class="muted rbac-legend"><b>V</b> View &nbsp; <b>A</b> Add &nbsp; <b>E</b> Edit &nbsp; <b>D</b> Delete</span>
                        <?php if ($can_edit): ?>
                        <button class="btn btn-success" id="btnSavePerms" onclick="savePermissions()"><i class="fas fa-save"></i> Save Matrix</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="matrixSkeleton">
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                </div>
                <div class="about-table-wrapper initially-hidden" id="matrixWrap">
                    <table class="about-roles-table rbac-matrix" id="matrixTable"></table>
                </div>
                <p class="muted rbac-note"><i class="fas fa-circle-info"></i> Ticking Add, Edit or Delete automatically turns View on — a role can't act on a page it can't open. The owner row is always full and cannot be changed.</p>
            </div>
        </div>
    </div>

    <!-- Role Modal (add / edit) -->
    <div class="modal-overlay" id="roleModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Role</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="roleForm">
                    <input type="hidden" id="roleId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user-tag"></i> Role Name *</label>
                            <input type="text" id="label" name="label" maxlength="60" placeholder="e.g. Installation Engineer" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> Role Key</label>
                            <input type="text" id="role_key" name="role_key" maxlength="50" placeholder="auto from name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-shield-halved"></i> Data Scope *</label>
                            <select id="data_scope" name="data_scope">
                                <?php foreach ($SCOPES as $k => $v): ?>
                                <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-palette"></i> Badge Colour</label>
                            <input type="color" id="badge_color" name="badge_color" value="#0074D9" class="rbac-color">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-icons"></i> Icon Class</label>
                            <input type="text" id="icon" name="icon" maxlength="40" value="fas fa-user-tag" placeholder="fas fa-user-tag">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-sort"></i> Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" value="50" min="0" max="999">
                        </div>
                        <div class="form-group full-row">
                            <label><i class="fas fa-align-left"></i> Description</label>
                            <input type="text" id="description" name="description" maxlength="255" placeholder="What does this role do?">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-bell"></i> Receives Owner Alerts</label>
                            <label class="status-toggle rbac-toggle">
                                <input type="checkbox" id="receives_admin_alerts" name="receives_admin_alerts" value="1">
                                <span class="track"></span>
                                <span id="alertsLabel">Off</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="status" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>
    <script>
    const CAN_EDIT   = <?php echo $can_edit ? 'true' : 'false'; ?>;
    const CAN_DELETE = <?php echo $can_delete ? 'true' : 'false'; ?>;
    const SCOPE_LABELS = <?php echo json_encode($SCOPES); ?>;

    let rolesTable = null, isEditMode = false, MATRIX = null;

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }

    $(document).ready(function() {
        new SearchableDropdown({ selectId: 'data_scope', placeholder: 'Select scope...', icon: 'fas fa-shield-halved', allowClear: false });
        new SearchableDropdown({ selectId: 'status',     placeholder: 'Select status...', icon: 'fas fa-toggle-on',    allowClear: false });
        $('#receives_admin_alerts').on('change', function() { $('#alertsLabel').text(this.checked ? 'On' : 'Off'); });
        document.getElementById('roleModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
        loadAll();
    });

    function loadAll() { loadRoles(); loadMatrix(); }

    function loadRoles() {
        $('#skeletonWrap').show();
        $('#tableView').hide();
        $.getJSON('?action=getRoles', function(resp) {
            $('#skeletonWrap').hide();
            if (!resp.success) { Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' }); return; }
            $('#tableView').removeClass('initially-hidden').show();
            renderSummary(resp.data);
            renderTable(resp.data);
        }).fail(function() { $('#skeletonWrap').hide(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); });
    }

    function renderSummary(rows) {
        const sys = rows.filter(r => r.is_system).length;
        $('#smTotal').text(rows.length);
        $('#smTotalSub').text(rows.filter(r => r.status === 'active').length + ' active');
        $('#smSystem').text(sys);
        $('#smCustom').text(rows.length - sys);
        $('#smStaff').text(rows.reduce((a, r) => a + r.staff_count, 0));
        $('#smUnused').text(rows.filter(r => !r.staff_count).length);   // nobody assigned
    }

    function renderTable(data) {
        if (rolesTable) { rolesTable.destroy(); $('#rolesTable').empty(); }
        setTimeout(() => {
            rolesTable = $('#rolesTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'label', title: 'Role', render: (d, t, row) =>
                        `<span class="role-badge" style="background:${escapeHtml(row.badge_color)};color:#fff"><i class="${escapeHtml(row.icon)}"></i> ${escapeHtml(d)}</span>` },
                    { data: 'role_key', title: 'Key', render: d => `<span class="muted">${escapeHtml(d)}</span>` },
                    { data: 'data_scope', title: 'Data Scope', render: d => escapeHtml(SCOPE_LABELS[d] || d) },
                    { data: 'is_system', title: 'Type', render: d => d
                        ? '<span class="status-pill active"><i class="fas fa-lock"></i> Built-in</span>'
                        : '<span class="status-pill inactive"><i class="fas fa-wand-magic-sparkles"></i> Custom</span>' },
                    { data: 'staff_count', title: 'Staff', className: 'num-cell' },
                    { data: 'status', title: 'Status', render: d => `<span class="status-pill ${escapeHtml(d)}">${d === 'active' ? 'Active' : 'Inactive'}</span>` },
                    { data: 'description', title: 'Description', render: d => escapeHtml(d || '—') },
                    { data: null, title: 'Actions', orderable: false, render: function(d, t, row) {
                        const rowJson = JSON.stringify(row).replace(/'/g, "&#39;");
                        let menu = '';
                        if (CAN_EDIT) {
                            menu += `<button class="act-more-item" onclick='editRole(${rowJson})'><i class="fas fa-edit"></i> Edit</button>`;
                            if (!row.is_super) menu += `<button class="act-more-item" onclick="toggleStatus(${row.id})"><i class="fas fa-power-off"></i> ${row.status === 'active' ? 'Deactivate' : 'Activate'}</button>`;
                        }
                        if (CAN_DELETE && !row.is_system) {
                            menu += `<button class="act-more-item danger" onclick="deleteRole(${row.id}, '${escapeHtml(row.label)}')"><i class="fas fa-trash"></i> Delete</button>`;
                        }
                        if (!menu) return '<span class="muted">—</span>';
                        return `<div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">${menu}</div></div>`;
                    } }
                ],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
                responsive: true,
                dom: 'lfrtip',
                order: [[4, 'desc']],
                language: { emptyTable: 'No roles found.' }
            });
        }, 50);
    }

    // ── matrix ──────────────────────────────────────────────────
    function loadMatrix() {
        $('#matrixSkeleton').show();
        $('#matrixWrap').hide();
        $.getJSON('?action=getMatrix', function(resp) {
            $('#matrixSkeleton').hide();
            if (!resp.success) { Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load matrix' }); return; }
            MATRIX = resp;
            $('#matrixWrap').removeClass('initially-hidden').show();
            renderMatrix();
        }).fail(function() { $('#matrixSkeleton').hide(); });
    }

    function renderMatrix() {
        const { pages, roles, grid } = MATRIX;
        let head = '<thead><tr><th>Page</th>';
        roles.forEach(r => {
            head += `<th><span class="role-badge" style="background:${escapeHtml(r.color)};color:#fff">${escapeHtml(r.label)}</span>`
                 +  (r.is_super ? '<div class="rbac-owner">owner — always full</div>' : '<div class="rbac-cols">V A E D</div>') + '</th>';
        });
        head += '</tr></thead>';

        let body = '<tbody>', lastGroup = '';
        pages.forEach(p => {
            if (p.group !== lastGroup) {
                lastGroup = p.group;
                body += `<tr class="rbac-group"><td colspan="${roles.length + 1}"><i class="fas fa-folder-open"></i> ${escapeHtml(p.group)}</td></tr>`;
            }
            body += `<tr><td><i class="${escapeHtml(p.icon)}"></i> ${escapeHtml(p.label)}</td>`;
            roles.forEach(r => {
                const cell = (grid[r.key] && grid[r.key][p.key]) || { v: 0, a: 0, e: 0, d: 0 };
                if (r.is_super) {
                    body += '<td class="rbac-cell"><span class="rbac-full">VAED</span></td>';
                    return;
                }
                let boxes = '';
                ['v', 'a', 'e', 'd'].forEach(k => {
                    boxes += `<label class="rbac-box" title="${ {v:'View',a:'Add',e:'Edit',d:'Delete'}[k] }">`
                          +  `<input type="checkbox" data-role="${escapeHtml(r.key)}" data-page="${escapeHtml(p.key)}" data-perm="${k}"`
                          +  `${cell[k] ? ' checked' : ''}${CAN_EDIT ? '' : ' disabled'}><span>${k.toUpperCase()}</span></label>`;
                });
                body += `<td class="rbac-cell">${boxes}</td>`;
            });
            body += '</tr>';
        });
        body += '</tbody>';
        $('#matrixTable').html(head + body);
    }

    // implied-view both ways, live
    $(document).on('change', '#matrixTable input[type=checkbox]', function() {
        const $cell = $(this).closest('td');
        const perm  = $(this).data('perm');
        if (perm === 'v' && !this.checked) $cell.find('input').prop('checked', false);
        if (perm !== 'v' && this.checked)  $cell.find('input[data-perm=v]').prop('checked', true);
    });

    function savePermissions() {
        const grid = {};
        $('#matrixTable input[type=checkbox]').each(function() {
            const r = $(this).data('role'), p = $(this).data('page'), k = $(this).data('perm');
            if (!grid[r]) grid[r] = {};
            if (!grid[r][p]) grid[r][p] = { v: 0, a: 0, e: 0, d: 0 };
            grid[r][p][k] = this.checked ? 1 : 0;
        });
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData();
        fd.append('grid', JSON.stringify(grid));
        $.ajax({
            url: '?action=savePermissions', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 1800, showConfirmButton: false });
                    setTimeout(loadMatrix, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    // ── role crud ───────────────────────────────────────────────
    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Role';
        document.getElementById('roleForm').reset();
        $('#roleId').val('');
        $('#badge_color').val('#0074D9');
        $('#icon').val('fas fa-user-tag');
        $('#sort_order').val(50);
        $('#role_key').prop('disabled', false);
        $('#alertsLabel').text('Off');
        ['data_scope', 'status'].forEach(id => { const s = document.getElementById(id); if (s && s._sd) s._sd.refresh(); });
        document.getElementById('roleModal').classList.add('active');
    }

    function editRole(row) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Role';
        $('#roleId').val(row.id);
        $('#label').val(row.label);
        $('#role_key').val(row.role_key).prop('disabled', true);
        $('#description').val(row.description || '');
        $('#badge_color').val(row.badge_color);
        $('#icon').val(row.icon);
        $('#sort_order').val(row.sort_order);
        $('#data_scope').val(row.data_scope);
        $('#status').val(row.status);
        $('#receives_admin_alerts').prop('checked', !!row.alerts);
        $('#alertsLabel').text(row.alerts ? 'On' : 'Off');
        ['data_scope', 'status'].forEach(id => { const s = document.getElementById(id); if (s && s._sd) s._sd.refresh(); });
        document.getElementById('roleModal').classList.add('active');
    }

    function closeModal() { document.getElementById('roleModal').classList.remove('active'); }

    document.getElementById('roleForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        if (isEditMode) fd.set('role_key', $('#role_key').val()); // disabled inputs don't post
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=' + (isEditMode ? 'updateRole' : 'addRole'), method: 'POST', data: fd,
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

    function toggleStatus(id) {
        const fd = new FormData(); fd.append('id', id);
        $.ajax({ url: '?action=toggleRoleStatus', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) { Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false }); setTimeout(loadAll, 100); }
                else Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    function deleteRole(id, label) {
        Swal.fire({
            icon: 'warning', title: 'Delete Role?',
            text: 'Remove the role "' + label + '"? Staff must be moved off it first.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({ url: '?action=deleteRole', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
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
