<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? ROLE_COLLECTOR;
$user_id = $_SESSION['user_id'];
$current_page = 'logs';

// gate: super_admin + admin only
if (!canViewActivityLogs($role)) {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'getLogs') {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        // admin-level sees all logs, others see own
        if (isAdminLevel($role)) {
            $stmt = $conn->prepare("SELECT * FROM activity_logs ORDER BY timestamp DESC");
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY timestamp DESC");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
        }

        $result = $stmt->get_result();
        $logs = [];

        while ($row = $result->fetch_assoc()) {
            $logs[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'action' => $row['action'],
                'details' => $row['details'],
                'ip_address' => $row['ip_address'],
                'timestamp' => date('c', strtotime($row['timestamp'])),
                'timestamp_iso' => date('c', strtotime($row['timestamp']))
            ];
        }

        $stmt->close();

        echo json_encode(['success' => true, 'data' => $logs]);
        exit();

    } catch (Exception $e) {
        error_log("Logs.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading logs: ' . $e->getMessage()]);
        exit();
    }
}

// If we reach here, render the HTML page
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Activity Logs - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>My Account</span>
                <span class="breadcrumb-sep">/</span>
                <span>Activity Logs</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-history"></i> Activity Logs</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Activity History</h2>
                    <button class="btn btn-primary" onclick="loadLogs()">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>

                <!-- Filters Section -->
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
                            <label><i class="fas fa-tag"></i> Action Type</label>
                            <select id="filterAction" class="filter-input">
                                <option value="">All Actions</option>
                            </select>
                        </div>
                        <?php if (isAdminLevel($role)): ?>
                        <div class="filter-group">
                            <label><i class="fas fa-user"></i> Username</label>
                            <select id="filterUsername" class="filter-input">
                                <option value="">All Users</option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- DataTable -->
                <div id="tableContainer" class="initially-hidden">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div class="table-responsive">
                        <table id="logsTable" class="display table-full-width"></table>
                    </div>
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

    <script>
    // Lazy-load export dependencies (PDF/Excel) on first use
    function loadExportDeps(callback) {
        if (window.pdfMake) { callback(); return; }
        var urls = [
            'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'
        ];
        var loaded = 0;
        function loadNext() {
            if (loaded >= urls.length) { callback(); return; }
            var s = document.createElement('script');
            s.src = urls[loaded];
            s.onload = function() { loaded++; loadNext(); };
            document.head.appendChild(s);
        }
        loadNext();
    }
    </script>

    <script>
        let logsTable;
        let logsData = [];
        let uniqueActions = [];
        let uniqueUsernames = [];

        // consistent date display from ISO; sort still uses ISO
        function fmtDate(iso) {
            if (!iso) return '-';
            const d = new Date(iso);
            if (isNaN(d)) return '-';
            return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

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

        $(document).ready(function() {
            loadLogs();
        });

        function loadLogs() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();
            $('#filtersSection').hide();

            $.ajax({
                url: '?action=getLogs',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logsData = response.data;

                        // Extract unique values for filters
                        uniqueActions = [...new Set(response.data.map(r => r.action).filter(Boolean))];
                        uniqueUsernames = [...new Set(response.data.map(r => r.username).filter(Boolean))];

                        // Populate filter dropdowns
                        populateFilters();

                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        $('#filtersSection').show();

                        setTimeout(() => initializeDataTable(response.data), 100);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load logs'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#loadingSkeleton').hide();
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        function populateFilters() {
            // Populate action filter
            const actionSelect = document.getElementById('filterAction');
            actionSelect.innerHTML = '<option value="">All Actions</option>';
            uniqueActions.forEach(action => {
                const option = document.createElement('option');
                option.value = action;
                option.textContent = action;
                actionSelect.appendChild(option);
            });
            // searchable dropdown — init once, then refresh
            if (actionSelect._sd) actionSelect._sd.refresh();
            else new SearchableDropdown({ selectId: 'filterAction', placeholder: 'All Actions', icon: 'fas fa-tag' });

            // Populate username filter (Admin only)
            <?php if (isAdminLevel($role)): ?>
            const usernameSelect = document.getElementById('filterUsername');
            usernameSelect.innerHTML = '<option value="">All Users</option>';
            uniqueUsernames.forEach(username => {
                const option = document.createElement('option');
                option.value = username;
                option.textContent = username;
                usernameSelect.appendChild(option);
            });
            <?php endif; ?>
        }

        function initializeDataTable(data) {
            if (logsTable) {
                logsTable.destroy();
                $('#logsTable').empty();
            }

            setTimeout(() => {
                logsTable = $('#logsTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: [
                        { data: 'id', title: 'ID', width: '60px' },
                        { data: 'username', title: 'Username' },
                        { data: 'action', title: 'Action' },
                        {
                            data: 'details',
                            title: 'Details',
                            render: function(data) {
                                if (!data) return '<em class="text-muted">No details</em>';
                                return data.length > 100 ? data.substring(0, 100) + '...' : data;
                            }
                        },
                        { data: 'ip_address', title: 'IP Address', render: function(data) { return renderIP(data); } },
                        {
                            data: 'timestamp', title: 'Timestamp',
                            render: function(data, type) {
                                // sort/filter on raw ISO, display formatted
                                if (type === 'display') return fmtDate(data);
                                return data || '';
                            }
                        }
                    ],
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        {
                            extend: 'csv',
                            text: '<i class="fas fa-file-csv"></i> CSV',
                            exportOptions: { columns: ':visible' }
                        },
                        {
                            text: '<i class="fas fa-file-pdf"></i> PDF',
                            action: function(e, dt, node, config) {
                                loadExportDeps(function() {
                                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config);
                                });
                            },
                            exportOptions: { columns: ':visible' }
                        },
                        {
                            extend: 'print',
                            text: '<i class="fas fa-print"></i> Print',
                            exportOptions: { columns: ':visible' }
                        }
                    ],
                    order: [[0, 'desc']]
                });

                // Apply filters on change
                $('#filterDateFrom, #filterDateTo, #filterAction, #filterUsername').on('change', function() {
                    applyFilters();
                });

            }, 100);
        }

        function applyFilters() {
            if (!logsTable) return;

            // Clear previous custom filters
            $.fn.dataTable.ext.search = [];

            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            const action = document.getElementById('filterAction').value;
            <?php if (isAdminLevel($role)): ?>
            const username = document.getElementById('filterUsername').value;
            <?php endif; ?>

            // Date range filter
            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const timestamp = logsData[dataIndex]?.timestamp_iso;
                    if (!timestamp) return true;

                    const recordDate = new Date(timestamp);
                    const fromDate = dateFrom ? new Date(dateFrom) : null;
                    const toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;

                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            // Column filters
            logsTable.columns().search('');
            if (action) logsTable.column(2).search(action);
            <?php if (isAdminLevel($role)): ?>
            if (username) logsTable.column(1).search(username);
            <?php endif; ?>

            logsTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterAction').value = '';
            <?php if (isAdminLevel($role)): ?>
            document.getElementById('filterUsername').value = '';
            <?php endif; ?>

            if (logsTable) {
                $.fn.dataTable.ext.search = [];
                logsTable.columns().search('').draw();
            }
        }
    </script>
</body>
</html>
