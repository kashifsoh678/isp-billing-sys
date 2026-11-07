<?php
 
require_once 'config.php';

// gate: super_admin + admin + accountant only — collectors blocked
if (!isset($_SESSION['user_id']) || !can('aging', 'v')) {
    header("Location: dashboard.php");
    exit();
}
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = (int)$_SESSION['user_id'];
$current_page = 'aging';

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getAging': {
                // filters
                $area_id      = (int)($_GET['area_id'] ?? 0);
                $package_id   = (int)($_GET['package_id'] ?? 0);
                $collector_id = (int)($_GET['collector_id'] ?? 0);
                $conn_status  = trim($_GET['connection_status'] ?? '');
                $min_out      = (float)($_GET['min_outstanding'] ?? 0);

                // resolve collector → area_id (collectors assigned to areas via users.area_id)
                $col_area_id = 0;
                if ($collector_id > 0) {
                    $cs = $conn->prepare("SELECT area_id FROM users WHERE id = ? AND " . areaRoleInSql('users') . " AND is_deleted = 0");
                    $cs->bind_param("i", $collector_id);
                    $cs->execute();
                    $cr = $cs->get_result()->fetch_assoc();
                    $cs->close();
                    $col_area_id = (int)($cr['area_id'] ?? 0);
                    // collector w/ no area → no rows
                    if ($col_area_id === 0) {
                        echo json_encode([
                            'success' => true,
                            'data'    => [],
                            'totals'  => [
                                'bucket_current'=>0, 'bucket_30'=>0, 'bucket_60'=>0,
                                'bucket_90'=>0, 'bucket_90plus'=>0, 'total'=>0,
                                'count_current'=>0, 'count_30'=>0, 'count_60'=>0,
                                'count_90'=>0, 'count_90plus'=>0, 'count_total'=>0
                            ]
                        ]);
                        exit();
                    }
                }

                $sql = "SELECT
                          c.id, c.customer_code, c.full_name, c.phone,
                          c.connection_status, c.last_payment_date,
                          a.name AS area_name, p.name AS package_name,
                          SUM(CASE WHEN i.due_date >= CURDATE()                                    THEN i.balance_amount ELSE 0 END) AS bucket_current,
                          SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1  AND 30          THEN i.balance_amount ELSE 0 END) AS bucket_30,
                          SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60          THEN i.balance_amount ELSE 0 END) AS bucket_60,
                          SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90          THEN i.balance_amount ELSE 0 END) AS bucket_90,
                          SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90                       THEN i.balance_amount ELSE 0 END) AS bucket_90plus,
                          SUM(i.balance_amount) AS total_outstanding,
                          COUNT(i.id) AS invoice_count
                        FROM invoices i
                        JOIN customers c ON c.id = i.customer_id AND c.is_deleted = 0
                        LEFT JOIN areas    a ON a.id = c.area_id
                        LEFT JOIN packages p ON p.id = c.package_id
                        WHERE i.is_deleted = 0
                          AND i.status IN ('unpaid','partial','overdue')
                          AND i.balance_amount > 0";
                $types  = '';
                $params = [];

                if ($area_id > 0)        { $sql .= " AND c.area_id = ?";          $types .= 'i'; $params[] = $area_id; }
                if ($package_id > 0)     { $sql .= " AND c.package_id = ?";       $types .= 'i'; $params[] = $package_id; }
                if ($conn_status !== '') { $sql .= " AND c.connection_status = ?"; $types .= 's'; $params[] = $conn_status; }
                if ($col_area_id > 0)    { $sql .= " AND c.area_id = ?";          $types .= 'i'; $params[] = $col_area_id; }

                $sql .= " GROUP BY c.id";

                if ($min_out > 0) {
                    $sql .= " HAVING total_outstanding >= ?";
                    $types .= 'd';
                    $params[] = $min_out;
                }

                $sql .= " ORDER BY total_outstanding DESC";

                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();

                $rows = [];
                $tot = ['bc'=>0,'b30'=>0,'b60'=>0,'b90'=>0,'b90p'=>0,'all'=>0];
                $cnt = ['bc'=>0,'b30'=>0,'b60'=>0,'b90'=>0,'b90p'=>0,'all'=>0];

                while ($r = $res->fetch_assoc()) {
                    $bc   = (float)$r['bucket_current'];
                    $b30  = (float)$r['bucket_30'];
                    $b60  = (float)$r['bucket_60'];
                    $b90  = (float)$r['bucket_90'];
                    $b90p = (float)$r['bucket_90plus'];
                    $sum  = (float)$r['total_outstanding'];

                    $rows[] = [
                        'id'                => (int)$r['id'],
                        'customer_code'     => $r['customer_code'] ?? '',
                        'full_name'         => $r['full_name'] ?? '',
                        'phone'             => $r['phone'] ?? '',
                        'area_name'         => $r['area_name'] ?? '',
                        'package_name'      => $r['package_name'] ?? '',
                        'connection_status' => $r['connection_status'] ?? 'active',
                        'last_payment_date' => $r['last_payment_date'],
                        'bucket_current'    => number_format($bc,   2, '.', ''),
                        'bucket_30'         => number_format($b30,  2, '.', ''),
                        'bucket_60'         => number_format($b60,  2, '.', ''),
                        'bucket_90'         => number_format($b90,  2, '.', ''),
                        'bucket_90plus'     => number_format($b90p, 2, '.', ''),
                        'total_outstanding' => number_format($sum,  2, '.', ''),
                        'invoice_count'     => (int)$r['invoice_count']
                    ];

                    $tot['bc']   += $bc;   if ($bc   > 0) $cnt['bc']++;
                    $tot['b30']  += $b30;  if ($b30  > 0) $cnt['b30']++;
                    $tot['b60']  += $b60;  if ($b60  > 0) $cnt['b60']++;
                    $tot['b90']  += $b90;  if ($b90  > 0) $cnt['b90']++;
                    $tot['b90p'] += $b90p; if ($b90p > 0) $cnt['b90p']++;
                    $tot['all']  += $sum;
                    $cnt['all']++;
                }
                $stmt->close();

                echo json_encode([
                    'success' => true,
                    'data'    => $rows,
                    'totals'  => [
                        'bucket_current' => number_format($tot['bc'],   2, '.', ''),
                        'bucket_30'      => number_format($tot['b30'],  2, '.', ''),
                        'bucket_60'      => number_format($tot['b60'],  2, '.', ''),
                        'bucket_90'      => number_format($tot['b90'],  2, '.', ''),
                        'bucket_90plus'  => number_format($tot['b90p'], 2, '.', ''),
                        'total'          => number_format($tot['all'],  2, '.', ''),
                        'count_current'  => $cnt['bc'],
                        'count_30'       => $cnt['b30'],
                        'count_60'       => $cnt['b60'],
                        'count_90'       => $cnt['b90'],
                        'count_90plus'   => $cnt['b90p'],
                        'count_total'    => $cnt['all']
                    ]
                ]);
                exit();
            }

            case 'getAreas': {
                $res = $conn->query("SELECT id, name FROM areas WHERE is_deleted = 0 ORDER BY name ASC");
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $out[] = ['id' => (int)$r['id'], 'name' => $r['name']];
                }
                echo json_encode(['success'=>true,'data'=>$out]);
                exit();
            }

            case 'getPackages': {
                $res = $conn->query("SELECT id, name FROM packages WHERE is_deleted = 0 ORDER BY name ASC");
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $out[] = ['id' => (int)$r['id'], 'name' => $r['name']];
                }
                echo json_encode(['success'=>true,'data'=>$out]);
                exit();
            }

            case 'getCollectors': {
                $sql = "SELECT id, name, username FROM users
                        WHERE " . areaRoleInSql('users') . " AND is_deleted = 0
                        ORDER BY name ASC, username ASC";
                $res = $conn->query($sql);
                $out = [];
                while ($r = $res->fetch_assoc()) {
                    $nm = $r['name'] ?: $r['username'];
                    $out[] = ['id' => (int)$r['id'], 'label' => $nm];
                }
                echo json_encode(['success'=>true,'data'=>$out]);
                exit();
            }

            default:
                echo json_encode(['success'=>false,'message'=>'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Aging.php error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// log open
logActivity($user_id, $username, 'Viewed Aging Report', 'AR aging buckets opened');

$currency = getSetting('currency_symbol', 'PKR');
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Aging Report - ISP Billing</title>

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
                <span>Aging Report</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-hourglass-half"></i> AR Aging Report</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- bucket summary strip -->
            <div class="aging-summary-grid">
                <div class="aging-summary-card bucket-current">
                    <div class="aging-summary-card-label"><i class="fas fa-circle-check"></i> Current</div>
                    <div class="aging-summary-card-value" id="sumCurrent"><?php echo $currency; ?> 0.00</div>
                    <div class="aging-summary-card-sub" id="cntCurrent">Not yet due</div>
                </div>
                <div class="aging-summary-card bucket-30">
                    <div class="aging-summary-card-label"><i class="fas fa-clock"></i> 1-30 Days</div>
                    <div class="aging-summary-card-value" id="sum30"><?php echo $currency; ?> 0.00</div>
                    <div class="aging-summary-card-sub" id="cnt30">0 customers</div>
                </div>
                <div class="aging-summary-card bucket-60">
                    <div class="aging-summary-card-label"><i class="fas fa-triangle-exclamation"></i> 31-60 Days</div>
                    <div class="aging-summary-card-value" id="sum60"><?php echo $currency; ?> 0.00</div>
                    <div class="aging-summary-card-sub" id="cnt60">0 customers</div>
                </div>
                <div class="aging-summary-card bucket-90">
                    <div class="aging-summary-card-label"><i class="fas fa-circle-exclamation"></i> 61-90 Days</div>
                    <div class="aging-summary-card-value" id="sum90"><?php echo $currency; ?> 0.00</div>
                    <div class="aging-summary-card-sub" id="cnt90">0 customers</div>
                </div>
                <div class="aging-summary-card bucket-90plus">
                    <div class="aging-summary-card-label"><i class="fas fa-skull-crossbones"></i> 90+ Days</div>
                    <div class="aging-summary-card-value" id="sum90plus"><?php echo $currency; ?> 0.00</div>
                    <div class="aging-summary-card-sub" id="cnt90plus">0 customers</div>
                </div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Outstanding by Customer</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadAging()">
                            <i class="fas fa-sync"></i> Refresh
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
                            <label><i class="fas fa-map-marked-alt"></i> Area</label>
                            <select id="filterArea">
                                <option value="">All Areas</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-box"></i> Package</label>
                            <select id="filterPackage">
                                <option value="">All Packages</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-user-tie"></i> Collector</label>
                            <select id="filterCollector">
                                <option value="">All Collectors</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-plug"></i> Connection</label>
                            <select id="filterConnection">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                                <option value="disconnected">Disconnected</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-money-bill-trend-up"></i> Min Outstanding</label>
                            <input type="number" id="filterMinOut" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-day"></i> Last Payment Range</label>
                            <div class="filter-dates">
                                <input type="date" id="filterPayFrom" title="Last payment from">
                                <span class="filter-dates-sep">to</span>
                                <input type="date" id="filterPayTo" title="Last payment to">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap" class="table-responsive">
                    <table class="display table-full-width">
                        <thead>
                            <tr>
                                <th>Code</th><th>Name</th><th>Area</th><th>Package</th><th>Phone</th>
                                <th>Status</th><th>Last Pay</th><th>Current</th><th>1-30</th>
                                <th>31-60</th><th>61-90</th><th>90+</th><th>Total</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr>
                                <?php for ($j = 0; $j < 14; $j++): ?>
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
                    <table id="agingTable" class="display table-full-width">
                        <tfoot>
                            <tr>
                                <th colspan="7" class="dt-foot-label">Totals</th>
                                <th id="footCurrent"></th>
                                <th id="foot30"></th>
                                <th id="foot60"></th>
                                <th id="foot90"></th>
                                <th id="foot90plus"></th>
                                <th id="footTotal"></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- empty -->
                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-circle-check"></i></div>
                    <div class="empty-state-title">All clear!</div>
                    <div class="empty-state-text">No customers have outstanding balances.</div>
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
    let agingTable;
    let agingData = [];
    const CURR = <?php echo json_encode($currency); ?>;

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function fmtMoney(v)   { return CURR + ' ' + Number(v || 0).toFixed(2); }
    function fmtDate(d) {
        if (!d) return '<span class="muted">—</span>';
        const dt = new Date(d);
        if (isNaN(dt)) return escapeHtml(d);
        return dt.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    }
    function bucketCell(v, cls) {
        const n = Number(v || 0);
        if (n <= 0) return '<span class="aging-bucket b-zero">—</span>';
        return '<span class="aging-bucket ' + cls + '">' + fmtMoney(n) + '</span>';
    }

    $(document).ready(function() {
        // SD wiring
        new SearchableDropdown({ selectId: 'filterArea',       placeholder: 'All Areas',       icon: 'fas fa-map-marked-alt' });
        new SearchableDropdown({ selectId: 'filterPackage',    placeholder: 'All Packages',    icon: 'fas fa-box' });
        new SearchableDropdown({ selectId: 'filterCollector',  placeholder: 'All Collectors',  icon: 'fas fa-user-tie' });
        new SearchableDropdown({ selectId: 'filterConnection', placeholder: 'All Statuses',    icon: 'fas fa-plug' });

        loadAreasFilter();
        loadPackagesFilter();
        loadCollectorsFilter();

        $('#filterArea, #filterPackage, #filterCollector, #filterConnection').on('change', loadAging);
        $('#filterMinOut').on('input', debounce(loadAging, 400));
        // last-payment date range — client-side, just redraw
        $('#filterPayFrom, #filterPayTo').on('change', function() {
            if (agingTable) agingTable.draw();
        });

        // last-payment date-range search filter
        $.fn.dataTable.ext.search.push(function(settings, rowData, dataIndex) {
            if (settings.nTable !== document.getElementById('agingTable')) return true;
            const dF = $('#filterPayFrom').val();
            const dT = $('#filterPayTo').val();
            if (!dF && !dT) return true;
            const r = agingData[dataIndex];
            const lp = (r && r.last_payment_date ? String(r.last_payment_date) : '').slice(0, 10);
            if (!lp) return false;   // no payment date → excluded when a range is set
            if (dF && lp < dF) return false;
            if (dT && lp > dT) return false;
            return true;
        });

        loadAging();
    });

    function debounce(fn, ms) {
        let t; return function() { clearTimeout(t); const a = arguments, c = this; t = setTimeout(() => fn.apply(c, a), ms); };
    }

    function loadAreasFilter() {
        $.getJSON('?action=getAreas', function(resp) {
            if (!resp.success) return;
            const sel = document.getElementById('filterArea');
            sel.innerHTML = '<option value="">All Areas</option>' +
                resp.data.map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function loadPackagesFilter() {
        $.getJSON('?action=getPackages', function(resp) {
            if (!resp.success) return;
            const sel = document.getElementById('filterPackage');
            sel.innerHTML = '<option value="">All Packages</option>' +
                resp.data.map(p => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function loadCollectorsFilter() {
        $.getJSON('?action=getCollectors', function(resp) {
            if (!resp.success) return;
            const sel = document.getElementById('filterCollector');
            sel.innerHTML = '<option value="">All Collectors</option>' +
                resp.data.map(c => `<option value="${c.id}">${escapeHtml(c.label)}</option>`).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function getFilterParams() {
        return {
            area_id:           $('#filterArea').val()       || 0,
            package_id:        $('#filterPackage').val()    || 0,
            collector_id:      $('#filterCollector').val()  || 0,
            connection_status: $('#filterConnection').val() || '',
            min_outstanding:   $('#filterMinOut').val()     || 0
        };
    }

    function loadAging() {
        $('#skeletonWrap').show();
        $('#tableWrap').hide();
        $('#emptyState').hide();

        $.ajax({
            url: '?action=getAging',
            method: 'GET',
            data: getFilterParams(),
            dataType: 'json',
            success: function(resp) {
                if (!resp.success) {
                    $('#skeletonWrap').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' });
                    return;
                }
                renderTotals(resp.totals);
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
            },
            error: function() {
                $('#skeletonWrap').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
            }
        });
    }

    function renderTotals(t) {
        if (!t) return;
        $('#sumCurrent').text(fmtMoney(t.bucket_current));
        $('#sum30').text(fmtMoney(t.bucket_30));
        $('#sum60').text(fmtMoney(t.bucket_60));
        $('#sum90').text(fmtMoney(t.bucket_90));
        $('#sum90plus').text(fmtMoney(t.bucket_90plus));
        $('#cntCurrent').text((t.count_current || 0) + ' customers · not due');
        $('#cnt30').text((t.count_30 || 0) + ' customers');
        $('#cnt60').text((t.count_60 || 0) + ' customers');
        $('#cnt90').text((t.count_90 || 0) + ' customers');
        $('#cnt90plus').text((t.count_90plus || 0) + ' customers');
    }

    function initTable(data) {
        // destroy() restores original DOM (keeps <tfoot>); don't .empty() or footer is lost
        if (agingTable) { agingTable.destroy(); $('#agingTable thead').remove(); }
        agingData = data;
        setTimeout(() => {
            agingTable = $('#agingTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    {
                        data: 'customer_code', title: 'Code',
                        render: (d, t, row) => `<a href="customers.php?id=${row.id}"><strong>${escapeHtml(d || '—')}</strong></a>`
                    },
                    { data: 'full_name',    title: 'Name',    render: d => escapeHtml(d || '—') },
                    { data: 'area_name',    title: 'Area',    render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'package_name', title: 'Package', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'phone',        title: 'Phone',   render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    {
                        data: 'connection_status', title: 'Status',
                        render: d => `<span class="conn-status ${d}">${d}</span>`
                    },
                    {
                        data: 'last_payment_date', title: 'Last Pay',
                        render: d => d ? fmtDate(d) : '<span class="muted">—</span>'
                    },
                    { data: 'bucket_current', title: 'Current', className: 'num-cell', render: d => bucketCell(d, 'b-current') },
                    { data: 'bucket_30',      title: '1-30',    className: 'num-cell', render: d => bucketCell(d, 'b-30') },
                    { data: 'bucket_60',      title: '31-60',   className: 'num-cell', render: d => bucketCell(d, 'b-60') },
                    { data: 'bucket_90',      title: '61-90',   className: 'num-cell', render: d => bucketCell(d, 'b-90') },
                    { data: 'bucket_90plus',  title: '90+',     className: 'num-cell', render: d => bucketCell(d, 'b-90plus') },
                    {
                        data: 'total_outstanding', title: 'Total Outstanding', className: 'num-cell',
                        render: d => `<span class="outstanding-badge due"><strong>${fmtMoney(d)}</strong></span>`
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: row => `<a class="action-icon view-icon" href="customers.php?id=${row.id}" title="View Customer"><i class="fas fa-eye"></i></a>`
                    }
                ],
                footerCallback: function(tfoot, rowsData, start, end, display) {
                    // sum the visible (filtered) rows per bucket
                    let bc = 0, b30 = 0, b60 = 0, b90 = 0, b90p = 0, all = 0;
                    display.forEach(idx => {
                        const r = agingData[idx];
                        if (!r) return;
                        bc   += parseFloat(r.bucket_current)    || 0;
                        b30  += parseFloat(r.bucket_30)         || 0;
                        b60  += parseFloat(r.bucket_60)         || 0;
                        b90  += parseFloat(r.bucket_90)         || 0;
                        b90p += parseFloat(r.bucket_90plus)     || 0;
                        all  += parseFloat(r.total_outstanding) || 0;
                    });
                    $('#footCurrent').text(fmtMoney(bc));
                    $('#foot30').text(fmtMoney(b30));
                    $('#foot60').text(fmtMoney(b60));
                    $('#foot90').text(fmtMoney(b90));
                    $('#foot90plus').text(fmtMoney(b90p));
                    $('#footTotal').text(fmtMoney(all)).addClass('dt-foot-val' + (all > 0 ? ' neg' : ''));
                },
                pageLength: 50,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: 'Aging-Report', exportOptions: { columns: ':not(:last-child)' } },
                    {
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        action: function(e, dt, node, config) {
                            loadExportDeps(function() {
                                $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config);
                            });
                        },
                        title: 'Aging-Report',
                        exportOptions: { columns: ':not(:last-child)' }
                    },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: 'Aging Report', exportOptions: { columns: ':not(:last-child)' } }
                ],
                order: [[12, 'desc']]   // total_outstanding desc default
            });
        }, 100);
    }

    function clearFilters() {
        ['filterArea','filterPackage','filterCollector','filterConnection'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = '';
            el._sd && el._sd.refresh();
        });
        $('#filterMinOut').val('');
        $('#filterPayFrom, #filterPayTo').val('');
        loadAging();
    }
    </script>
</body>
</html>
