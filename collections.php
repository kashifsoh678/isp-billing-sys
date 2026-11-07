<?php
 
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !can('collections', 'v')) {
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
$current_page = 'collections';
$currency     = getSetting('currency_symbol', '$');
$siteName     = getSetting('site_name', 'ISP Billing');

// auto-flip overdue (idempotent)
function autoFlipOverdue($conn) {
    $conn->query("UPDATE invoices SET status = 'overdue' WHERE status IN ('unpaid','partial') AND due_date < CURDATE() AND is_deleted = 0");
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getCollections': {
                autoFlipOverdue($conn);

                // collector area scope
                $areaWhere = '';
                $params = [];
                $types  = '';
                if (isAreaScoped()) {
                    $areaWhere = areaScope('c');
                    if ($areaWhere === '') {
                        echo json_encode(['success' => true, 'data' => [], 'totals' => ['count' => 0, 'amount' => 0]]);
                        exit();
                    }
                }
                $reqArea = (int)($_GET['area_id'] ?? 0);
                if (!isAreaScoped() && $reqArea > 0) {
                    $areaWhere = " AND c.area_id = ? ";
                    $params[]  = $reqArea;
                    $types    .= 'i';
                }

                $sql = "SELECT
                            c.id, c.customer_code, c.full_name, c.phone, c.alternate_phone,
                            c.area_id, a.name AS area_name, p.name AS package_name,
                            SUM(i.balance_amount) AS total_outstanding,
                            MIN(i.due_date)       AS oldest_due_date,
                            DATEDIFF(CURDATE(), MIN(i.due_date)) AS days_overdue,
                            COUNT(i.id) AS invoice_count,
                            c.last_payment_date,
                            cl.call_date     AS last_call_date,
                            cl.outcome       AS last_call_outcome,
                            cl.notes         AS last_call_notes,
                            cl.promised_date AS last_promised_date
                        FROM invoices i
                        JOIN customers c ON c.id = i.customer_id AND c.is_deleted = 0
                        LEFT JOIN areas    a ON a.id = c.area_id
                        LEFT JOIN packages p ON p.id = c.package_id
                        LEFT JOIN call_logs cl ON cl.id = (
                            SELECT cl2.id FROM call_logs cl2
                            WHERE cl2.customer_id = c.id AND cl2.is_deleted = 0
                            ORDER BY cl2.call_date DESC LIMIT 1
                        )
                        WHERE i.is_deleted = 0
                          AND i.status IN ('unpaid','partial','overdue')
                          AND i.balance_amount > 0
                          $areaWhere
                        GROUP BY c.id
                        ORDER BY
                            CASE WHEN MIN(i.due_date) < CURDATE() THEN 0 ELSE 1 END,
                            MIN(i.due_date) ASC,
                            total_outstanding DESC";

                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = []; $totalAmt = 0;
                $today = date('Y-m-d');
                while ($r = $res->fetch_assoc()) {
                    $oldest    = $r['oldest_due_date'];
                    $days      = (int)$r['days_overdue'];
                    $promised  = $r['last_promised_date'];
                    $isPromised = ($r['last_call_outcome'] === 'promised' && $promised && $promised >= $today);

                    // bucket: overdue / due_today / due_soon (next 7d) / future
                    $bucket = 'future';
                    if ($days > 0) $bucket = 'overdue';
                    else if ($days === 0) $bucket = 'due_today';
                    else if ($days >= -7) $bucket = 'due_soon';

                    $rows[] = [
                        'id'                 => (int)$r['id'],
                        'customer_code'      => $r['customer_code'],
                        'full_name'          => $r['full_name'],
                        'phone'              => $r['phone'],
                        'alternate_phone'    => $r['alternate_phone'] ?? '',
                        'area_id'            => (int)($r['area_id'] ?? 0),
                        'area_name'          => $r['area_name'] ?? '',
                        'package_name'       => $r['package_name'] ?? '',
                        'total_outstanding'  => (float)$r['total_outstanding'],
                        'oldest_due_date'    => $oldest,
                        'days_overdue'       => $days,
                        'invoice_count'      => (int)$r['invoice_count'],
                        'last_payment_date'  => $r['last_payment_date'],
                        'last_call_date'     => $r['last_call_date'],
                        'last_call_outcome'  => $r['last_call_outcome'],
                        'last_call_notes'    => $r['last_call_notes'] ?? '',
                        'last_promised_date' => $promised,
                        'is_promised'        => $isPromised ? 1 : 0,
                        'bucket'             => $bucket
                    ];
                    $totalAmt += (float)$r['total_outstanding'];
                }
                $stmt->close();
                echo json_encode([
                    'success' => true,
                    'data'    => $rows,
                    'totals'  => ['count' => count($rows), 'amount' => round($totalAmt, 2)]
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

            case 'logCall': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $cid     = (int)($_POST['customer_id'] ?? 0);
                $outcome = $_POST['outcome'] ?? '';
                $promise = trim($_POST['promised_date'] ?? '');
                $notes   = trim($_POST['notes'] ?? '');
                if ($cid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid customer']); exit(); }
                if (!in_array($outcome, ['no_answer','promised','paid','disputed','wrong_number','other'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid outcome']);
                    exit();
                }
                if ($outcome === 'promised' && $promise === '') {
                    echo json_encode(['success' => false, 'message' => 'Promised date is required']);
                    exit();
                }
                $promiseDb = $promise !== '' ? $promise : null;

                // collector area scope check
                if (isAreaScoped()) {
                    $chk = $conn->prepare("SELECT area_id FROM customers WHERE id = ? AND is_deleted = 0");
                    $chk->bind_param("i", $cid);
                    $chk->execute();
                    $crow = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if (!$crow || !areaAllowed($crow['area_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Access denied (area scope)']);
                        exit();
                    }
                }

                $stmt = $conn->prepare("INSERT INTO call_logs (customer_id, called_by, outcome, promised_date, notes)
                                        VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iisss", $cid, $user_id, $outcome, $promiseDb, $notes);
                if ($stmt->execute()) {
                    $logId = $stmt->insert_id;
                    $stmt->close();
                    logActivity($user_id, $username, 'Call Logged', "Customer #$cid — outcome=$outcome" . ($promise ? " promise=$promise" : ''));
                    echo json_encode(['success' => true, 'message' => 'Call logged', 'id' => $logId]);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $err]);
                }
                exit();
            }

            case 'getCallHistory': {
                $cid = (int)($_GET['customer_id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }
                $stmt = $conn->prepare("SELECT cl.id, cl.call_date, cl.outcome, cl.promised_date, cl.notes,
                                               u.name AS caller_name, u.username AS caller_username
                                        FROM call_logs cl
                                        LEFT JOIN users u ON u.id = cl.called_by
                                        WHERE cl.customer_id = ? AND cl.is_deleted = 0
                                        ORDER BY cl.call_date DESC LIMIT 25");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $r = $stmt->get_result();
                $rows = [];
                while ($x = $r->fetch_assoc()) {
                    $rows[] = [
                        'id'             => (int)$x['id'],
                        'call_date'      => $x['call_date'],
                        'outcome'        => $x['outcome'],
                        'promised_date'  => $x['promised_date'],
                        'notes'          => $x['notes'] ?? '',
                        'caller_name'    => $x['caller_name'] ?? $x['caller_username'] ?? '—'
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("collections.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

$isColl     = isAreaScoped(); // area-locked roles get no area picker
$canViewAll = canViewFinances();
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Collections — <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body>
    <div class="app-container">
        <?php include 'sidebar.php'; ?>
        <?php include 'mobile-menu.php'; ?>

        <div class="main-content">
            <div class="breadcrumb no-print">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>Collections</span>
            </div>
            <div class="header no-print">
                <h1><i class="fas fa-route"></i> Today's Collections</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section no-print">
                <div class="section-header">
                    <h2><i class="fas fa-list-check"></i> Door-to-Door List</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadCollections()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <button class="btn btn-secondary" onclick="copyPhones()" title="Copy all phone numbers to clipboard">
                            <i class="fas fa-mobile-screen"></i> Copy Phones
                        </button>
                        <button class="btn btn-secondary" onclick="copyMessage()" title="Copy reminder message text">
                            <i class="fas fa-comment-dots"></i> Copy Message
                        </button>
                        <button class="btn btn-warning" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Route Sheet
                        </button>
                    </div>
                </div>

                <!-- Summary strip -->
                <div class="summary-grid">
                    <div class="summary-card danger">
                        <div class="summary-card-label"><i class="fas fa-triangle-exclamation"></i> Overdue</div>
                        <div class="summary-card-value" id="sumOverdue">—</div>
                    </div>
                    <div class="summary-card warning">
                        <div class="summary-card-label"><i class="fas fa-clock"></i> Due Today</div>
                        <div class="summary-card-value" id="sumDueToday">—</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-card-label"><i class="fas fa-calendar-day"></i> Due Soon (7d)</div>
                        <div class="summary-card-value" id="sumDueSoon">—</div>
                    </div>
                    <div class="summary-card success">
                        <div class="summary-card-label"><i class="fas fa-handshake"></i> Promised</div>
                        <div class="summary-card-value" id="sumPromised">—</div>
                    </div>
                    <div class="summary-card highlight">
                        <div class="summary-card-label"><i class="fas fa-sack-dollar"></i> Total Outstanding</div>
                        <div class="summary-card-value" id="sumOutstanding">—</div>
                    </div>
                </div>

                <!-- chevron pipeline -->
                <div class="chevron-pipeline" id="chevronPipeline" role="tablist" aria-label="Filter collections">
                    <div class="chevron-tab cv-all active" data-bucket="all" role="tab" tabindex="0">
                        <i class="fas fa-list"></i> All <span class="cv-count" id="cv-c-all">0</span>
                    </div>
                    <div class="chevron-tab cv-overdue" data-bucket="overdue" role="tab" tabindex="0">
                        <i class="fas fa-triangle-exclamation"></i> Overdue <span class="cv-count" id="cv-c-overdue">0</span>
                    </div>
                    <div class="chevron-tab cv-outstanding" data-bucket="due_today" role="tab" tabindex="0">
                        <i class="fas fa-clock"></i> Due Today <span class="cv-count" id="cv-c-due_today">0</span>
                    </div>
                    <div class="chevron-tab cv-partial" data-bucket="due_soon" role="tab" tabindex="0">
                        <i class="fas fa-calendar-day"></i> Due Soon <span class="cv-count" id="cv-c-due_soon">0</span>
                    </div>
                    <div class="chevron-tab cv-paid" data-bucket="promised" role="tab" tabindex="0">
                        <i class="fas fa-handshake"></i> Promised <span class="cv-count" id="cv-c-promised">0</span>
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
                            <label><i class="fas fa-search"></i> Search</label>
                            <div class="filter-input-wrap filter-search-wrap">
                                <i class="fas fa-search filter-search-icon"></i>
                                <input type="text" id="filterSearch" class="filter-input-styled has-leading-icon" placeholder="Name / code / phone">
                            </div>
                        </div>
                        <?php if (!$isColl): ?>
                        <div class="filter-group">
                            <label><i class="fas fa-map-marked-alt"></i> Area</label>
                            <select id="filterArea">
                                <option value="">All Areas</option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap" class="table-responsive">
                    <table class="display table-full-width">
                        <thead>
                            <tr><th>#</th><th>Code</th><th>Name</th><th>Phone</th><th>Area</th><th>Outstanding</th><th>Days</th><th>Last Call</th><th>Actions</th></tr>
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

                <div class="table-responsive initially-hidden" id="tableWrap">
                    <table id="collectionsTable" class="display table-full-width"></table>
                </div>

                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-circle-check"></i></div>
                    <div class="empty-state-title">All clear — no outstanding bills.</div>
                    <div class="empty-state-text">Every customer in scope is paid up. Great job!</div>
                </div>
            </div>

            <!-- Print-only route sheet -->
            <div class="collection-printable print-only">
                <div class="collection-print-header">
                    <h2 class="collection-isp-name"><?php echo htmlspecialchars($siteName); ?></h2>
                    <h1 class="collection-print-title">Today's Collection Route Sheet</h1>
                    <div class="collection-print-meta">
                        <div class="collection-print-meta-item">
                            <div class="collection-print-meta-label">Date</div>
                            <div class="collection-print-meta-value"><?php echo date('M d, Y (l)'); ?></div>
                        </div>
                        <div class="collection-print-meta-item">
                            <div class="collection-print-meta-label">Collector</div>
                            <div class="collection-print-meta-value"><?php echo htmlspecialchars($username); ?></div>
                        </div>
                        <div class="collection-print-meta-item">
                            <div class="collection-print-meta-label">Customers</div>
                            <div class="collection-print-meta-value" id="printCount">—</div>
                        </div>
                        <div class="collection-print-meta-item">
                            <div class="collection-print-meta-label">Total Outstanding</div>
                            <div class="collection-print-meta-value" id="printTotal">—</div>
                        </div>
                    </div>
                </div>
                <table class="collection-print-table">
                    <thead>
                        <tr>
                            <th style="width:36px">#</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Area</th>
                            <th class="num-cell">Balance</th>
                            <th>Days</th>
                            <th style="width:90px">Promise</th>
                            <th style="width:140px">Signature</th>
                        </tr>
                    </thead>
                    <tbody id="printBody"></tbody>
                </table>
                <div class="collection-print-footer">
                    <span>Printed: <?php echo date('M d, Y H:i'); ?></span>
                    <span><?php echo htmlspecialchars($siteName); ?> — Internal Use</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Call Modal -->
    <div class="modal-overlay" id="logCallModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width:520px">
            <div class="modal-header">
                <h3><i class="fas fa-phone"></i> Log Call</h3>
                <button class="close-btn" onclick="closeLogCallModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="logCallForm">
                    <input type="hidden" id="logCustomerId" name="customer_id">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Customer</label>
                        <input type="text" id="logCustomerName" readonly>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-clipboard-check"></i> Outcome *</label>
                        <select id="logOutcome" name="outcome" required>
                            <option value="">Select outcome...</option>
                            <option value="no_answer">No Answer</option>
                            <option value="promised">Promised Payment</option>
                            <option value="paid">Paid (recording later)</option>
                            <option value="disputed">Disputed Amount</option>
                            <option value="wrong_number">Wrong / Unreachable Number</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group" id="promisedDateGroup" style="display:none">
                        <label><i class="fas fa-calendar"></i> Promised Date *</label>
                        <input type="date" id="logPromisedDate" name="promised_date">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> Notes</label>
                        <textarea id="logNotes" name="notes" rows="3" placeholder="What did the customer say? Anything to remember..."></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeLogCallModal()"><i class="fas fa-times"></i> Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Call</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>

    <script>
    const CCY        = '<?php echo addslashes($currency); ?>';
    const IS_COLL    = <?php echo $isColl ? 'true' : 'false'; ?>;
    const SITE_NAME  = '<?php echo addslashes($siteName); ?>';

    let collTable, collData = [], chevronBucket = 'all';

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function fmtMoney(n)   { return CCY + ' ' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtDate(d)    { if (!d || d === '0000-00-00') return '—'; const dt = /^\d{4}-\d{2}-\d{2}$/.test(d) ? new Date(d + 'T00:00:00') : new Date(d); return isNaN(dt) ? d : dt.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }); }
    function fmtDateTime(s){ if (!s) return '—'; const dt = new Date(s.replace(' ','T')); return isNaN(dt) ? s : dt.toLocaleString('en-GB', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' }); }
    function digits(p)     { return String(p || '').replace(/[^0-9]/g, ''); }

    $(document).ready(function() {
        if (!IS_COLL) {
            new SearchableDropdown({ selectId: 'filterArea', placeholder: 'All Areas', icon: 'fas fa-map-marked-alt' });
            loadAreas();
        }

        // chevron click
        $(document).on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('.chevron-tab').removeClass('active');
            $(this).addClass('active');
            chevronBucket = $(this).data('bucket') || 'all';
            applyFilters();
        });

        $('#filterArea').on('change', loadCollections);
        $('#filterSearch').on('input', applyFilters);

        // outcome → conditional promised_date
        $('#logOutcome').on('change', function() {
            $('#promisedDateGroup').toggle(this.value === 'promised');
            if (this.value !== 'promised') $('#logPromisedDate').val('');
        });

        // log call form
        $('#logCallForm').on('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.ajax({
                url: '?action=logCall', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1400, showConfirmButton: false });
                        closeLogCallModal();
                        setTimeout(loadCollections, 200);
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: () => Swal.fire({ icon:'error', title:'Error', text:'Connection error' })
            });
        });

        loadCollections();

        // cross-tab refresh when payment recorded elsewhere
        let lastSeenDirty = localStorage.getItem('isp_invoices_dirty') || '';
        function refreshIfDirty() {
            const v = localStorage.getItem('isp_invoices_dirty') || '';
            if (v && v !== lastSeenDirty) { lastSeenDirty = v; loadCollections(); }
        }
        window.addEventListener('storage', e => { if (e.key === 'isp_invoices_dirty') refreshIfDirty(); });
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refreshIfDirty(); });
        window.addEventListener('pageshow', e => { if (e.persisted) refreshIfDirty(); });
    });

    function loadAreas() {
        $.getJSON('?action=getAreas', function(resp) {
            if (!resp.success) return;
            const sel = document.getElementById('filterArea');
            resp.data.forEach(a => sel.add(new Option(a.name, a.id)));
            sel._sd && sel._sd.refresh();
        });
    }

    function loadCollections() {
        $('#skeletonWrap').show();
        $('#tableWrap, #emptyState, #filtersSection').hide();
        const aid = (document.getElementById('filterArea') || {}).value || '';
        const url = '?action=getCollections' + (aid ? '&area_id=' + encodeURIComponent(aid) : '');
        $.getJSON(url).done(function(resp) {
            $('#skeletonWrap').hide();
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message }); return; }
            collData = resp.data;
            refreshSummary(collData);
            refreshChevronCounts(collData);
            if (!collData.length) { $('#emptyState').show(); return; }
            $('#filtersSection').show();
            $('#tableWrap').show();
            initTable(collData);
            if (chevronBucket !== 'all') setTimeout(applyFilters, 120);
        }).fail(() => { $('#skeletonWrap').hide(); Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); });
    }

    function refreshSummary(rows) {
        let overdue = 0, dueToday = 0, dueSoon = 0, promised = 0, total = 0;
        rows.forEach(r => {
            total += Number(r.total_outstanding) || 0;
            if (r.bucket === 'overdue')   overdue++;
            if (r.bucket === 'due_today') dueToday++;
            if (r.bucket === 'due_soon')  dueSoon++;
            if (Number(r.is_promised))    promised++;
        });
        document.getElementById('sumOverdue').textContent     = overdue;
        document.getElementById('sumDueToday').textContent    = dueToday;
        document.getElementById('sumDueSoon').textContent     = dueSoon;
        document.getElementById('sumPromised').textContent    = promised;
        document.getElementById('sumOutstanding').textContent = fmtMoney(total);
    }

    function refreshChevronCounts(rows) {
        const c = { all: rows.length, overdue: 0, due_today: 0, due_soon: 0, promised: 0 };
        rows.forEach(r => {
            if (r.bucket in c) c[r.bucket]++;
            if (Number(r.is_promised)) c.promised++;
        });
        Object.keys(c).forEach(k => {
            const el = document.getElementById('cv-c-' + k);
            if (el) el.textContent = c[k];
        });
    }

    function initTable(data) {
        if (collTable) { collTable.destroy(); $('#collectionsTable').empty(); }
        setTimeout(() => {
            collTable = $('#collectionsTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: null, title: '#', orderable: false, render: (d, t, r, m) => m.row + 1 },
                    { data: 'customer_code', title: 'Code',
                      render: (d, t, r) => `<a href="customers.php?id=${r.id}">${escapeHtml(d)}</a>` },
                    { data: 'full_name', title: 'Name',
                      render: (d, t, r) => `<strong>${escapeHtml(d)}</strong>` + (r.area_name ? `<br><small class="muted">${escapeHtml(r.area_name)}</small>` : '') },
                    { data: 'phone', title: 'Phone',
                      render: (d, t, r) => {
                          const dg = digits(d);
                          let h = `<a href="tel:${escapeHtml(dg)}" class="action-icon" title="Call"><i class="fas fa-phone"></i></a> ${escapeHtml(d)}`;
                          if (r.alternate_phone) h += `<br><small class="muted">${escapeHtml(r.alternate_phone)}</small>`;
                          return h;
                      } },
                    { data: 'package_name', title: 'Package', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'total_outstanding', title: 'Outstanding',
                      render: (d, t, r) => {
                          const cls = r.bucket === 'overdue' ? 'overdue' : (r.bucket === 'due_today' ? 'due' : 'due');
                          return `<span class="outstanding-badge ${cls}">${fmtMoney(d)}</span><br><small class="muted">${r.invoice_count} inv</small>`;
                      } },
                    { data: 'days_overdue', title: 'Days',
                      render: (d, t, r) => {
                          const n = Number(d);
                          if (n > 0)  return `<span class="inv-status overdue">+${n} d</span>`;
                          if (n === 0) return `<span class="inv-status partial">today</span>`;
                          return `<span class="inv-status unpaid">in ${Math.abs(n)} d</span>`;
                      } },
                    { data: null, title: 'Last Call',
                      render: r => {
                          if (!r.last_call_date) return '<span class="muted">never</span>';
                          let h = `<small>${escapeHtml(fmtDateTime(r.last_call_date))}</small><br>`;
                          h += `<span class="inv-status ${r.last_call_outcome}">${escapeHtml((r.last_call_outcome || '').replace('_',' '))}</span>`;
                          if (Number(r.is_promised)) h += `<br><small style="color:#16a34a"><i class="fas fa-handshake"></i> ${escapeHtml(fmtDate(r.last_promised_date))}</small>`;
                          return h;
                      } },
                    { data: null, title: 'Actions', orderable: false,
                      render: r => {
                          const dg = digits(r.phone);
                          const msg = encodeURIComponent(`Hello ${r.full_name}, this is a reminder that ${fmtMoney(r.total_outstanding)} is pending on your account (${r.customer_code}). Please clear at the earliest. Thank you.`);
                          let h = '';
                          h += `<a href="https://wa.me/${dg}?text=${msg}" target="_blank" class="action-icon" title="WhatsApp" style="color:#25D366"><i class="fab fa-whatsapp"></i></a> `;
                          h += `<button class="action-icon edit-icon" onclick='openLogCall(${JSON.stringify({id: r.id, full_name: r.full_name})})' title="Log Call"><i class="fas fa-phone-volume"></i></button> `;
                          h += `<a href="payments.php?customer_id=${r.id}" class="action-icon" title="Record Payment" style="color:#16a34a"><i class="fas fa-money-bill"></i></a> `;
                          h += `<a href="customers.php?id=${r.id}" class="action-icon view-icon" title="View"><i class="fas fa-eye"></i></a>`;
                          return h;
                      } }
                ],
                pageLength: 25,
                lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'All']],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv',  text: '<i class="fas fa-file-csv"></i> CSV',  exportOptions: { columns: ':not(:last-child)' } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':not(:last-child)' } }
                ],
                order: []
            });
        }, 100);
    }

    function applyFilters() {
        if (!collTable) return;
        $.fn.dataTable.ext.search = [];
        const sr = ((document.getElementById('filterSearch') || {}).value || '').toLowerCase();
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const r = collData[dataIndex];
            if (!r) return true;
            if (chevronBucket === 'overdue'   && r.bucket !== 'overdue') return false;
            if (chevronBucket === 'due_today' && r.bucket !== 'due_today') return false;
            if (chevronBucket === 'due_soon'  && r.bucket !== 'due_soon') return false;
            if (chevronBucket === 'promised'  && !Number(r.is_promised)) return false;
            if (sr) {
                const hay = (r.customer_code + ' ' + r.full_name + ' ' + r.phone + ' ' + (r.alternate_phone || '')).toLowerCase();
                if (hay.indexOf(sr) === -1) return false;
            }
            return true;
        });
        collTable.draw();
    }

    function clearFilters() {
        chevronBucket = 'all';
        $('.chevron-tab').removeClass('active');
        $('.chevron-tab[data-bucket="all"]').addClass('active');
        document.getElementById('filterSearch').value = '';
        const a = document.getElementById('filterArea');
        if (a) { a.value = ''; a._sd && a._sd.refresh(); loadCollections(); }
        if (collTable) { $.fn.dataTable.ext.search = []; collTable.draw(); }
    }

    // ─── Log Call modal ───
    function openLogCall(c) {
        document.getElementById('logCustomerId').value   = c.id;
        document.getElementById('logCustomerName').value = c.full_name;
        document.getElementById('logOutcome').value      = '';
        document.getElementById('logPromisedDate').value = '';
        document.getElementById('logNotes').value        = '';
        document.getElementById('promisedDateGroup').style.display = 'none';
        document.getElementById('logCallModal').classList.add('active');
    }
    function closeLogCallModal() {
        document.getElementById('logCallModal').classList.remove('active');
    }
    document.getElementById('logCallModal').addEventListener('click', function(e) {
        if (e.target === this) closeLogCallModal();
    });

    // ─── Bulk helpers ───
    function visibleRows() {
        const filtered = (collTable && collTable.rows({ search: 'applied' }).data()) || [];
        return Array.from({ length: filtered.length }, (_, i) => filtered[i]);
    }

    function copyPhones() {
        const rows = visibleRows();
        if (!rows.length) { Swal.fire({ icon:'info', text:'No customers in current view' }); return; }
        const phones = rows.map(r => digits(r.phone)).filter(Boolean).join(', ');
        navigator.clipboard.writeText(phones).then(() => {
            Swal.fire({ icon:'success', title:'Copied!', text: rows.length + ' phone numbers copied to clipboard', timer: 1800, showConfirmButton: false });
        }).catch(() => {
            Swal.fire({ icon:'error', text:'Could not copy. Browser blocked clipboard.' });
        });
    }

    function copyMessage() {
        const rows = visibleRows();
        if (!rows.length) { Swal.fire({ icon:'info', text:'No customers in current view' }); return; }
        const lines = rows.map(r => `${r.full_name} (${r.customer_code}) — ${fmtMoney(r.total_outstanding)} pending [${r.phone}]`);
        const text = `${SITE_NAME} — Pending Bill Reminder\n\n${lines.join('\n')}\n\nTotal: ${rows.length} customers`;
        navigator.clipboard.writeText(text).then(() => {
            Swal.fire({ icon:'success', title:'Copied!', text:'Message text ready — paste into WhatsApp / SMS', timer: 2000, showConfirmButton: false });
        });
    }

    // ─── Print: build print body with current filtered rows ───
    window.addEventListener('beforeprint', function() {
        const rows = visibleRows();
        const tbody = document.getElementById('printBody');
        let total = 0;
        let html = '';
        rows.forEach((r, i) => {
            const bal = Number(r.total_outstanding) || 0;
            total += bal;
            const days = Number(r.days_overdue);
            const daysTxt = days > 0 ? `+${days}d` : (days === 0 ? 'today' : `in ${Math.abs(days)}d`);
            const promise = Number(r.is_promised) ? fmtDate(r.last_promised_date) : '';
            html += `<tr>
                <td>${i + 1}</td>
                <td><strong>${escapeHtml(r.customer_code)}</strong> — ${escapeHtml(r.full_name)}</td>
                <td>${escapeHtml(r.phone)}${r.alternate_phone ? '<br>' + escapeHtml(r.alternate_phone) : ''}</td>
                <td>${escapeHtml(r.area_name || '—')}</td>
                <td class="num-cell">${fmtMoney(bal)}</td>
                <td>${daysTxt}</td>
                <td>${escapeHtml(promise)}</td>
                <td class="collection-print-signature">&nbsp;</td>
            </tr>`;
        });
        tbody.innerHTML = html;
        document.getElementById('printCount').textContent = rows.length;
        document.getElementById('printTotal').textContent = fmtMoney(total);
    });
    </script>
</body>
</html>
