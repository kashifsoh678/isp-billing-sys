<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 */

require_once 'config.php';

// gate: super_admin only (system-level)
if (!isset($_SESSION['user_id']) || !canAccessSystem()) {
    header("Location: dashboard.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'sessions';
$current_session_id = session_id();

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getSessions':
                // Clean expired sessions first
                cleanExpiredSessions(SESSION_TIMEOUT);

                $stmt = $conn->prepare("SELECT us.*, u.username, u.role
                    FROM user_sessions us
                    JOIN users u ON us.user_id = u.id
                    WHERE us.last_activity > DATE_SUB(NOW(), INTERVAL ? SECOND)
                    ORDER BY us.last_activity DESC");
                $timeout = SESSION_TIMEOUT;
                $stmt->bind_param("i", $timeout);
                $stmt->execute();
                $result = $stmt->get_result();

                $sessions = [];
                $unique_users = [];
                $admin_count = 0;

                while ($row = $result->fetch_assoc()) {
                    $is_current = ($row['session_id'] === $current_session_id);

                    // Parse user agent for browser
                    $browser = 'Unknown';
                    $ua = $row['user_agent'];
                    if (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
                    elseif (strpos($ua, 'Edg') !== false) $browser = 'Edge';
                    elseif (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
                    elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
                    elseif (strpos($ua, 'Opera') !== false || strpos($ua, 'OPR') !== false) $browser = 'Opera';

                    // Parse OS
                    $os = 'Unknown';
                    if (strpos($ua, 'Windows') !== false) $os = 'Windows';
                    elseif (strpos($ua, 'Mac') !== false) $os = 'macOS';
                    elseif (strpos($ua, 'Linux') !== false) $os = 'Linux';
                    elseif (strpos($ua, 'Android') !== false) $os = 'Android';
                    elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) $os = 'iOS';

                    $sessions[] = [
                        'id' => $row['id'],
                        'session_id' => $row['session_id'],
                        'user_id' => $row['user_id'],
                        'username' => $row['username'],
                        'role' => $row['role'],
                        'ip_address' => $row['ip_address'],
                        'browser' => $browser,
                        'os' => $os,
                        'last_activity' => date('c', strtotime($row['last_activity'])),
                        'created_at' => date('c', strtotime($row['created_at'])),
                        'is_current' => $is_current
                    ];

                    $unique_users[$row['user_id']] = true;
                    if (isAdminLevel($row['role'])) $admin_count++;
                }
                $stmt->close();

                echo json_encode([
                    'success' => true,
                    'data' => $sessions,
                    'stats' => [
                        'total' => count($sessions),
                        'unique_users' => count($unique_users),
                        'admin_sessions' => $admin_count
                    ]
                ]);
                exit();

            case 'forceLogout':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $target_session_id = isset($_POST['session_id']) ? $_POST['session_id'] : '';

                if (empty($target_session_id)) {
                    echo json_encode(['success' => false, 'message' => 'Session ID required']);
                    exit();
                }

                if ($target_session_id === $current_session_id) {
                    echo json_encode(['success' => false, 'message' => 'Cannot force-logout your own session']);
                    exit();
                }

                // Get target user info
                $stmt = $conn->prepare("SELECT us.user_id, u.username FROM user_sessions us JOIN users u ON us.user_id = u.id WHERE us.session_id = ?");
                $stmt->bind_param("s", $target_session_id);
                $stmt->execute();
                $target = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$target) {
                    echo json_encode(['success' => false, 'message' => 'Session not found']);
                    exit();
                }

                // Set force_logout flag
                $stmt = $conn->prepare("UPDATE user_sessions SET force_logout = 1 WHERE session_id = ?");
                $stmt->bind_param("s", $target_session_id);
                $stmt->execute();
                $stmt->close();

                // Log activity
                logActivity($user_id, $username, 'Force Logout', 'Force-logged out user: ' . $target['username']);

                // Notify the target user
                try {
                    createNotification($target['user_id'], 'Session Terminated', 'Your session was terminated by an administrator.', 'danger', 'login.php');
                } catch (Exception $e) {}

                echo json_encode(['success' => true, 'message' => 'User session terminated']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
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
    <title>Sessions - <?php echo htmlspecialchars(getSiteBranding()['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body class="initially-hidden">
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>System</span>
                <span class="breadcrumb-sep">/</span>
                <span>Sessions</span>
            </div>

            <div class="header">
                <h1><i class="fas fa-desktop"></i> Session Management</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- Stat Cards -->
            <div class="summary-grid" id="statsContainer">
                <div class="summary-card highlight">
                    <div class="summary-card-label"><i class="fas fa-desktop"></i> Active Sessions</div>
                    <div class="summary-card-value" id="statActiveSessions">-</div>
                    <div class="summary-card-sub">Signed in right now</div>
                </div>
                <div class="summary-card success">
                    <div class="summary-card-label"><i class="fas fa-users"></i> Unique Users Online</div>
                    <div class="summary-card-value" id="statUniqueUsers">-</div>
                    <div class="summary-card-sub">One person, many devices counts once</div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-card-label"><i class="fas fa-user-shield"></i> Admin Sessions</div>
                    <div class="summary-card-value" id="statAdminSessions">-</div>
                    <div class="summary-card-sub">Admin level and above</div>
                </div>
                <div class="summary-card">
                    <div class="summary-card-label"><i class="fas fa-mobile-screen"></i> Mobile Sessions</div>
                    <div class="summary-card-value" id="statMobileSessions">-</div>
                    <div class="summary-card-sub">Android / iOS devices</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-card-label"><i class="fas fa-hourglass-half"></i> Idle 5 min+</div>
                    <div class="summary-card-value" id="statIdleSessions">-</div>
                    <div class="summary-card-sub">No activity in the last 5 minutes</div>
                </div>
            </div>

            <!-- Sessions Table -->
            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-list"></i> Active Sessions</h2>
                    <button class="btn btn-primary" onclick="loadSessions()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>

                <!-- Skeleton Loader -->
                <div id="sessionsSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>
                    </div>
                </div>

                <div id="sessionsTableContainer" class="initially-hidden">
                    <table id="sessionsTable" class="display table-full-width">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>IP Address</th>
                                <th>Browser / OS</th>
                                <th>Last Active</th>
                                <th>Started</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script>
    let sessionsTable = null;
    let refreshInterval = null;

    function maskIP(ip) {
        var parts = ip.split('.');
        if (parts.length === 4) return parts[0] + '.' + parts[1] + '.***. ***';
        return ip.replace(/:[\da-f]+:[\da-f]+$/i, ':***:***');
    }

    function renderIP(ip) {
        var masked = maskIP(ip);
        var escaped = $('<span>').text(ip).html();
        var escapedMasked = $('<span>').text(masked).html();
        return '<span class="ip-mask-wrap">' +
            '<code class="ip-display" data-full="' + escaped + '" data-masked="' + escapedMasked + '">' + escapedMasked + '</code>' +
            '<button class="ip-toggle-btn" onclick="toggleIP(this)" title="Show/Hide IP"><i class="fas fa-eye"></i></button>' +
            '</span>';
    }

    function toggleIP(btn) {
        var code = btn.previousElementSibling;
        var icon = btn.querySelector('i');
        if (code.textContent === code.getAttribute('data-masked')) {
            code.textContent = code.getAttribute('data-full');
            icon.className = 'fas fa-eye-slash';
        } else {
            code.textContent = code.getAttribute('data-masked');
            icon.className = 'fas fa-eye';
        }
    }

    function timeAgo(dateStr) {
        const seconds = Math.floor((new Date() - new Date(dateStr)) / 1000);
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        return Math.floor(seconds / 86400) + 'd ago';
    }

    function loadSessions() {
        $.ajax({
            url: 'sessions.php?action=getSessions',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update stats
                    $('#statActiveSessions').text(response.stats.total);
                    $('#statUniqueUsers').text(response.stats.unique_users);
                    $('#statAdminSessions').text(response.stats.admin_sessions);
                    const rows = response.data || [], idleFrom = Date.now() - 5 * 60 * 1000;
                    $('#statMobileSessions').text(rows.filter(r => r.os === 'Android' || r.os === 'iOS').length);
                    $('#statIdleSessions').text(rows.filter(r => new Date(r.last_activity).getTime() < idleFrom).length);

                    // Build table data
                    const roleLabels = <?php
                        $rl = []; foreach (getRolesDetailed(false) as $r) $rl[$r['role_key']] = $r['label']; echo json_encode($rl);
                    ?>;
                    const adminRoles = <?php echo json_encode(rolesWithPerm('users', 'v')); ?>;
                    const tableData = response.data.map(function(s) {
                        const label = roleLabels[s.role] || s.role;
                        const cls = adminRoles.includes(s.role) ? 'status-active' : 'status-inactive';
                        const roleBadge = `<span class="status-badge ${cls}">${label}</span>`;

                        const currentBadge = s.is_current
                            ? ' <span class="status-badge status-current">Current</span>'
                            : '';

                        const actionBtn = s.is_current
                            ? '<button class="btn btn-secondary btn-sm" disabled><i class="fas fa-shield-alt"></i> Current</button>'
                            : '<button class="btn btn-danger btn-sm" onclick="forceLogout(\'' + s.session_id + '\', \'' + s.username.replace(/'/g, "\\'") + '\')"><i class="fas fa-power-off"></i> Force Logout</button>';

                        return [
                            '<strong>' + $('<span>').text(s.username).html() + '</strong>' + currentBadge,
                            roleBadge,
                            renderIP(s.ip_address),
                            '<i class="fas fa-globe"></i> ' + s.browser + ' / ' + s.os,
                            '<span data-order="' + s.last_activity + '">' + timeAgo(s.last_activity) + '</span>',
                            '<span data-order="' + s.created_at + '">' + timeAgo(s.created_at) + '</span>',
                            actionBtn
                        ];
                    });

                    // Show table
                    $('#sessionsSkeleton').hide();
                    $('#sessionsTableContainer').show();

                    if (sessionsTable) {
                        sessionsTable.clear().rows.add(tableData).draw();
                    } else {
                        sessionsTable = $('#sessionsTable').DataTable({
                            data: tableData,
                            pageLength: 10,
                            responsive: true,
                            order: [[4, 'asc']],
                            dom: 'Bfrtip',
                            buttons: ['csv', 'pdf', 'print'],
                            language: {
                                emptyTable: 'No active sessions found'
                            }
                        });
                    }
                }
            },
            error: function() {
                Swal.fire('Error', 'Failed to load sessions', 'error');
            }
        });
    }

    function forceLogout(sessionId, username) {
        Swal.fire({
            title: 'Force Logout?',
            html: 'Are you sure you want to terminate <strong>' + username + '</strong>\'s session?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Yes, Terminate',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'sessions.php?action=forceLogout',
                    method: 'POST',
                    data: { session_id: sessionId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('Terminated!', response.message, 'success');
                            loadSessions();
                        } else {
                            Swal.fire('Error', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Request failed', 'error');
                    }
                });
            }
        });
    }

    // Initialize
    $(document).ready(function() {
        document.body.classList.remove('initially-hidden');
        loadSessions();

        // Auto-refresh every 30 seconds
        refreshInterval = setInterval(loadSessions, 30000);
    });
    </script>
</body>
</html>
