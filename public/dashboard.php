<?php
 
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout())       { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? ROLE_COLLECTOR;
$user_id = (int)$_SESSION['user_id'];
$current_page = 'dashboard';
$currency = getSetting('currency_symbol', '$');

// which dashboard to build — derived from permissions so custom roles never get a blank page.
// (this used to test $dashVariant itself, which is undefined here — the admin dashboard never rendered)
$dashVariant = (can('customers', 'v') && can('packages', 'e')) ? 'admin'
             : (can('expenses', 'v') ? 'accountant' : 'collector');
$site_name = getSetting('site_name', 'ISP Billing System');

// resolve dashboard date-range from ?range= param -> [start, end, label]
// presets: today | week | month | quarter | custom (needs from/to)
function dashDateRange() {
    $range = $_GET['range'] ?? 'month';
    $today = date('Y-m-d');
    switch ($range) {
        case 'today':
            return [$today, $today, 'Today'];
        case 'week':
            return [date('Y-m-d', strtotime('monday this week')), $today, 'This Week'];
        case 'quarter':
            $q = (int)floor((date('n') - 1) / 3); // 0..3
            $startM = $q * 3 + 1;
            return [date('Y-' . str_pad($startM, 2, '0', STR_PAD_LEFT) . '-01'), $today, 'This Quarter'];
        case 'custom':
            $f = $_GET['from'] ?? '';
            $t = $_GET['to'] ?? '';
            $fd = DateTime::createFromFormat('Y-m-d', $f);
            $td = DateTime::createFromFormat('Y-m-d', $t);
            $from = ($fd && $fd->format('Y-m-d') === $f) ? $f : date('Y-m-01');
            $to   = ($td && $td->format('Y-m-d') === $t) ? $t : $today;
            if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }
            return [$from, $to, 'Custom'];
        case 'month':
        default:
            return [date('Y-m-01'), $today, 'This Month'];
    }
}

// the four period KPIs shared by the admin + accountant dashboards:
// what we billed, what share of it came in, growth, and revenue per subscriber
function addPeriodKpis($conn, &$data, $start, $end) {
    $st = $conn->prepare("SELECT COALESCE(SUM(total),0) s, COUNT(*) c FROM invoices
                          WHERE is_deleted = 0 AND status <> 'cancelled' AND issue_date BETWEEN ? AND ?");
    $st->bind_param("ss", $start, $end);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    $data['invoicedAmount'] = (float)$r['s'];
    $data['invoicedCount']  = (int)$r['c'];

    $st = $conn->prepare("SELECT COUNT(*) c FROM customers WHERE is_deleted = 0 AND DATE(created_at) BETWEEN ? AND ?");
    $st->bind_param("ss", $start, $end);
    $st->execute();
    $data['newCustomers'] = (int)$st->get_result()->fetch_assoc()['c'];
    $st->close();

    // how much of what we billed this period actually landed
    $collected = (float)($data['monthRevenue'] ?? 0);
    $data['collectionRate'] = $data['invoicedAmount'] > 0
        ? round($collected / $data['invoicedAmount'] * 100, 1) : 0.0;

    // average revenue per active subscriber
    $active = (int)($data['activeCustomers'] ?? 0);
    if (!$active) {
        $active = (int)$conn->query("SELECT COUNT(*) c FROM customers WHERE connection_status='active' AND is_deleted=0")->fetch_assoc()['c'];
        $data['activeCustomers'] = $active;
    }
    $data['arpu'] = $active > 0 ? round($collected / $active, 2) : 0.0;
}

// AJAX: upcoming renewals — bills falling due in the next N days
if (isset($_GET['action']) && $_GET['action'] === 'getUpcomingRenewals') {
    header('Content-Type: application/json');
    if (!can('invoices', 'v')) { echo json_encode(['success' => false, 'message' => 'Not allowed']); exit(); }
    try {
        $conn  = getDBConnection();
        $days  = max(1, min(90, (int)($_GET['days'] ?? 7)));
        $scope = areaScope('c', $user_id);   // area-scoped roles see their own zones only
        $win   = "i.is_deleted = 0 AND i.status IN ('unpaid','partial')
                  AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  AND c.is_deleted = 0 AND c.connection_status <> 'disconnected' $scope";

        $st = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(i.balance_amount),0) s
                              FROM invoices i JOIN customers c ON c.id = i.customer_id
                              WHERE $win");
        $st->bind_param("i", $days); $st->execute();
        $tot = $st->get_result()->fetch_assoc(); $st->close();

        $st = $conn->prepare("SELECT i.id, i.invoice_number, i.due_date, i.balance_amount, i.status,
                                     c.id cid, c.full_name, c.customer_code, c.phone,
                                     p.name pkg, p.price
                              FROM invoices i
                              JOIN customers c ON c.id = i.customer_id
                              LEFT JOIN packages p ON p.id = c.package_id
                              WHERE $win
                              ORDER BY i.due_date ASC, i.balance_amount DESC
                              LIMIT 25");
        $st->bind_param("i", $days); $st->execute();
        $res = $st->get_result(); $rows = [];
        while ($x = $res->fetch_assoc()) {
            $rows[] = [
                'id'      => (int)$x['id'],   'number'  => $x['invoice_number'],
                'cid'     => (int)$x['cid'],  'name'    => $x['full_name'],
                'code'    => $x['customer_code'], 'phone' => $x['phone'],
                'pkg'     => $x['pkg'] ?: '—', 'price'  => (float)$x['price'],
                'due'     => $x['due_date'],  'amount'  => (float)$x['balance_amount'],
                'status'  => $x['status'],
                'inDays'  => (int)((strtotime($x['due_date']) - strtotime(date('Y-m-d'))) / 86400)
            ];
        }
        $st->close();

        // when the whole base gets billed next
        $abOn  = getSetting('auto_billing_enabled', '0') === '1';
        $abDay = max(1, min(28, (int)getSetting('auto_billing_day', '1')));
        $next  = date('Y-m-') . str_pad((string)$abDay, 2, '0', STR_PAD_LEFT);
        if ($next < date('Y-m-d')) $next = date('Y-m-d', strtotime('+1 month', strtotime($next)));

        echo json_encode(['success' => true, 'days' => $days,
                          'count' => (int)$tot['c'], 'amount' => (float)$tot['s'],
                          'autoBilling' => ['on' => $abOn, 'next' => $next],
                          'data' => $rows]);
        exit();
    } catch (Throwable $e) {
        error_log("Dashboard renewals error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not load renewals']);
        exit();
    }
}

// AJAX: KPI stats
if (isset($_GET['action']) && $_GET['action'] === 'getDashboardStats') {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();
        $today = date('Y-m-d');
        $month = (int)date('n');
        $year  = (int)date('Y');
        list($rangeStart, $rangeEnd, $rangeLabel) = dashDateRange();
        $data  = [];
        $data['rangeLabel'] = $rangeLabel;
        $data['rangeStart'] = $rangeStart;
        $data['rangeEnd']   = $rangeEnd;

        // outstanding receivables (uses new balance_amount column)
        $outstanding_sql = "SELECT COALESCE(SUM(balance_amount),0) s FROM invoices WHERE status IN ('unpaid','partial','overdue') AND is_deleted=0";

        if ($dashVariant === 'admin') {
            $data['activeCustomers'] = (int)$conn->query("SELECT COUNT(*) c FROM customers WHERE connection_status='active' AND is_deleted=0")->fetch_assoc()['c'];

            // gross collections + net collected (net_amount = amount - gateway fee)
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) g, COALESCE(SUM(net_amount),0) n FROM payments WHERE payment_date BETWEEN ? AND ? AND status='cleared' AND is_deleted=0");
            $stmt->bind_param("ss", $rangeStart, $rangeEnd); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $data['monthRevenue'] = (float)$row['g'];   // gross collections
            $data['netCollected'] = (float)$row['n'];   // net of gateway fees
            $stmt->close();

            $data['outstanding']    = (float)$conn->query($outstanding_sql)->fetch_assoc()['s'];
            $data['overdueCount']   = (int)$conn->query("SELECT COUNT(*) c FROM invoices WHERE status='overdue' AND is_deleted=0")->fetch_assoc()['c'];
            $data['openComplaints'] = (int)$conn->query("SELECT COUNT(*) c FROM complaints WHERE status IN ('open','in_progress')")->fetch_assoc()['c'];

            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM expenses WHERE expense_date BETWEEN ? AND ? AND is_deleted=0");
            $stmt->bind_param("ss", $rangeStart, $rangeEnd); $stmt->execute();
            $data['monthExpenses'] = (float)$stmt->get_result()->fetch_assoc()['s']; $stmt->close();

            // net profit from NET collected (gateway fees costed), not gross
            $data['netProfit'] = $data['netCollected'] - $data['monthExpenses'];
            addPeriodKpis($conn, $data, $rangeStart, $rangeEnd);

        } elseif ($dashVariant === 'accountant') {
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) s, COALESCE(SUM(net_amount),0) n, COUNT(*) c FROM payments WHERE payment_date BETWEEN ? AND ? AND status='cleared' AND is_deleted=0");
            $stmt->bind_param("ss", $rangeStart, $rangeEnd); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $data['monthRevenue'] = (float)$row['s']; $data['netCollected'] = (float)$row['n'];
            $data['monthRevenueCount'] = (int)$row['c']; $stmt->close();

            $data['outstanding'] = (float)$conn->query($outstanding_sql)->fetch_assoc()['s'];

            $od = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(balance_amount),0) s FROM invoices WHERE status='overdue' AND is_deleted=0")->fetch_assoc();
            $data['overdueCount'] = (int)$od['c']; $data['overdueSum'] = (float)$od['s'];

            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM expenses WHERE expense_date BETWEEN ? AND ? AND is_deleted=0");
            $stmt->bind_param("ss", $rangeStart, $rangeEnd); $stmt->execute();
            $data['monthExpenses'] = (float)$stmt->get_result()->fetch_assoc()['s']; $stmt->close();

            // net profit on net collected
            $data['netProfit'] = $data['netCollected'] - $data['monthExpenses'];
            addPeriodKpis($conn, $data, $rangeStart, $rangeEnd);

            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) s, COUNT(*) c FROM payments WHERE payment_date=? AND status='cleared' AND is_deleted=0");
            $stmt->bind_param("s", $today); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $data['todayCollections'] = (float)$row['s']; $data['todayCollectionsCount'] = (int)$row['c']; $stmt->close();

        } elseif ($dashVariant === 'collector') {
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) s, COUNT(*) c FROM payments WHERE collected_by=? AND payment_date=? AND status='cleared' AND is_deleted=0");
            $stmt->bind_param("is", $user_id, $today); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $data['todayCollections'] = (float)$row['s']; $data['todayCollectionsCount'] = (int)$row['c']; $stmt->close();

            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) s, COUNT(*) c FROM payments WHERE collected_by=? AND payment_date BETWEEN ? AND ? AND status='cleared' AND is_deleted=0");
            $stmt->bind_param("iss", $user_id, $rangeStart, $rangeEnd); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $data['monthCollections'] = (float)$row['s']; $data['monthCollectionsCount'] = (int)$row['c']; $stmt->close();

            $myAreas = currentAreaIds($user_id);
            $area_id = $myAreas ? (int)$myAreas[0] : 0;
            $data['myAreaId'] = $area_id;
            $data['myAreaCount'] = count($myAreas);
            $data['myAreaCustomers']   = 0;
            $data['myActiveCustomers'] = 0;
            if ($area_id) {
                $stmt = $conn->prepare("SELECT COUNT(*) c FROM customers WHERE area_id=? AND is_deleted=0");
                $stmt->bind_param("i", $area_id); $stmt->execute();
                $data['myAreaCustomers'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

                $stmt = $conn->prepare("SELECT COUNT(*) c FROM customers WHERE area_id=? AND connection_status='active' AND is_deleted=0");
                $stmt->bind_param("i", $area_id); $stmt->execute();
                $data['myActiveCustomers'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();
            }

            $stmt = $conn->prepare("SELECT COUNT(*) c FROM complaints WHERE assigned_to=? AND status IN ('open','in_progress')");
            $stmt->bind_param("i", $user_id); $stmt->execute();
            $data['myOpenComplaints'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();
        }

        echo json_encode(['success' => true, 'role' => $role, 'currency' => $currency, 'data' => $data]);
        exit();
    } catch (Throwable $e) {
        error_log("Dashboard stats error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load stats']);
        exit();
    }
}

// AJAX: chart data (role-aware)
if (isset($_GET['action']) && $_GET['action'] === 'getCharts') {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();
        $month = (int)date('n');
        $year  = (int)date('Y');
        $charts = [];

        // trend charts follow the dashboard date-range selector
        list($rangeStart, $rangeEnd, $rangeLabel) = dashDateRange();
        // adaptive bucket: <=31 day span -> day, else -> month
        $spanDays = (int)floor((strtotime($rangeEnd) - strtotime($rangeStart)) / 86400) + 1;
        $bucketByDay = ($spanDays <= 31);
        $sqlFmt = $bucketByDay ? '%Y-%m-%d' : '%Y-%m';
        $charts['trendMeta'] = ['label' => $rangeLabel, 'bucket' => $bucketByDay ? 'day' : 'month', 'start' => $rangeStart, 'end' => $rangeEnd];
        // turn a bucket key into a human label
        $bucketLabel = function($k) use ($bucketByDay) {
            return $bucketByDay ? date('M j', strtotime($k)) : date('M Y', strtotime($k . '-01'));
        };
        // the trend axis has to cover the WHOLE period, not just the buckets that had a payment —
        // the expense line is plotted against these keys, so a gap here reads as zero expenses
        $fillTrend = function($rows) use ($bucketByDay, $rangeStart, $rangeEnd, $bucketLabel) {
            $have = [];
            foreach ($rows as $r) $have[$r['key']] = (float)$r['value'];
            $out  = [];
            $cur  = strtotime($bucketByDay ? $rangeStart : date('Y-m-01', strtotime($rangeStart)));
            $end  = strtotime($rangeEnd);
            $step = $bucketByDay ? '+1 day' : '+1 month';
            $guard = 0;
            while ($cur <= $end && $guard++ < 400) {
                $k = date($bucketByDay ? 'Y-m-d' : 'Y-m', $cur);
                $out[] = ['key' => $k, 'label' => $bucketLabel($k), 'value' => $have[$k] ?? 0.0];
                $cur = strtotime($step, $cur);
            }
            return $out;
        };

        if ($dashVariant === 'admin') {
            // 1. revenue trend — scoped to selected range
            $rev_trend = [];
            $stmt = $conn->prepare("SELECT DATE_FORMAT(payment_date, '$sqlFmt') AS m, SUM(amount) AS s
                                    FROM payments
                                    WHERE is_deleted=0 AND status='cleared'
                                      AND payment_date BETWEEN ? AND ?
                                    GROUP BY m ORDER BY m");
            $stmt->bind_param("ss", $rangeStart, $rangeEnd); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $rev_trend[] = ['key' => $row['m'], 'label' => $bucketLabel($row['m']), 'value' => (float)$row['s']];
            $stmt->close();
            $charts['revenueTrend'] = $fillTrend($rev_trend);

            // 2. expenses trend (same range) — for cash flow
            $exp_trend = [];
            $stmt = $conn->prepare("SELECT DATE_FORMAT(expense_date, '$sqlFmt') AS m, SUM(amount) AS s
                                    FROM expenses
                                    WHERE is_deleted=0
                                      AND expense_date BETWEEN ? AND ?
                                    GROUP BY m ORDER BY m");
            $stmt->bind_param("ss", $rangeStart, $rangeEnd); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $exp_trend[$row['m']] = (float)$row['s'];
            $stmt->close();
            $charts['expensesTrend'] = $exp_trend;

            // 3. customers by area
            $by_area = [];
            $r = $conn->query("SELECT a.name, COUNT(c.id) AS cnt
                               FROM areas a
                               LEFT JOIN customers c ON c.area_id = a.id AND c.is_deleted = 0
                               WHERE a.is_deleted = 0
                               GROUP BY a.id, a.name ORDER BY cnt DESC");
            while ($row = $r->fetch_assoc()) $by_area[] = ['label' => $row['name'], 'value' => (int)$row['cnt']];
            $charts['customersByArea'] = $by_area;

            // 4. connection status breakdown
            $by_status = [];
            $r = $conn->query("SELECT connection_status AS s, COUNT(*) AS c FROM customers WHERE is_deleted=0 GROUP BY connection_status");
            while ($row = $r->fetch_assoc()) $by_status[] = ['label' => ucfirst($row['s']), 'value' => (int)$row['c']];
            $charts['connectionStatus'] = $by_status;

            // 5. complaints by category
            $by_cat = [];
            $r = $conn->query("SELECT category, COUNT(*) c FROM complaints GROUP BY category ORDER BY c DESC");
            while ($row = $r->fetch_assoc()) $by_cat[] = ['label' => ucfirst($row['category']), 'value' => (int)$row['c']];
            $charts['complaintsByCategory'] = $by_cat;

            // 6. collections trend — scoped to selected range, adaptive bucket
            $daily = [];
            $stmt = $conn->prepare("SELECT DATE_FORMAT(payment_date, '$sqlFmt') AS d, SUM(amount) AS s
                                    FROM payments
                                    WHERE is_deleted=0 AND status='cleared'
                                      AND payment_date BETWEEN ? AND ?
                                    GROUP BY d ORDER BY d");
            $stmt->bind_param("ss", $rangeStart, $rangeEnd); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $daily[] = ['label' => $bucketLabel($row['d']), 'value' => (float)$row['s']];
            $stmt->close();
            $charts['dailyCollections'] = $daily;

            // 7. top expense categories (this month)
            $top_exp = [];
            $stmt = $conn->prepare("SELECT category, SUM(amount) s FROM expenses WHERE is_deleted=0 AND MONTH(expense_date)=? AND YEAR(expense_date)=? GROUP BY category ORDER BY s DESC");
            $stmt->bind_param("ii", $month, $year); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $top_exp[] = ['label' => ucfirst($row['category']), 'value' => (float)$row['s']];
            $stmt->close();
            $charts['topExpenses'] = $top_exp;

            // 8. customers by package
            $by_pkg = [];
            $r = $conn->query("SELECT p.name, COUNT(c.id) cnt
                               FROM packages p LEFT JOIN customers c ON c.package_id=p.id AND c.is_deleted=0
                               WHERE p.is_deleted=0 GROUP BY p.id, p.name ORDER BY cnt DESC");
            while ($row = $r->fetch_assoc()) $by_pkg[] = ['label' => $row['name'], 'value' => (int)$row['cnt']];
            $charts['customersByPackage'] = $by_pkg;

        } elseif ($dashVariant === 'accountant') {
            // revenue trend — scoped to selected range
            $rev_trend = [];
            $stmt = $conn->prepare("SELECT DATE_FORMAT(payment_date, '$sqlFmt') AS m, SUM(amount) AS s
                                    FROM payments WHERE is_deleted=0 AND status='cleared'
                                      AND payment_date BETWEEN ? AND ?
                                    GROUP BY m ORDER BY m");
            $stmt->bind_param("ss", $rangeStart, $rangeEnd); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $rev_trend[] = ['key' => $row['m'], 'label' => $bucketLabel($row['m']), 'value' => (float)$row['s']];
            $stmt->close();
            $charts['revenueTrend'] = $fillTrend($rev_trend);

            // expenses trend (parallel, same range)
            $exp_trend = [];
            $stmt = $conn->prepare("SELECT DATE_FORMAT(expense_date, '$sqlFmt') AS m, SUM(amount) AS s
                                    FROM expenses WHERE is_deleted=0
                                      AND expense_date BETWEEN ? AND ?
                                    GROUP BY m ORDER BY m");
            $stmt->bind_param("ss", $rangeStart, $rangeEnd); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $exp_trend[$row['m']] = (float)$row['s'];
            $stmt->close();
            $charts['expensesTrend'] = $exp_trend;

            // payments by method (this month)
            $by_method = [];
            $stmt = $conn->prepare("SELECT payment_method m, SUM(amount) s FROM payments WHERE is_deleted=0 AND status='cleared' AND MONTH(payment_date)=? AND YEAR(payment_date)=? GROUP BY payment_method");
            $stmt->bind_param("ii", $month, $year); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $by_method[] = ['label' => ucfirst(str_replace('_',' ',$row['m'])), 'value' => (float)$row['s']];
            $stmt->close();
            $charts['paymentsByMethod'] = $by_method;

            // top expense categories (this month)
            $top_exp = [];
            $stmt = $conn->prepare("SELECT category, SUM(amount) s FROM expenses WHERE is_deleted=0 AND MONTH(expense_date)=? AND YEAR(expense_date)=? GROUP BY category ORDER BY s DESC");
            $stmt->bind_param("ii", $month, $year); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $top_exp[] = ['label' => ucfirst($row['category']), 'value' => (float)$row['s']];
            $stmt->close();
            $charts['topExpenses'] = $top_exp;

            // invoice status breakdown
            $by_inv_status = [];
            $r = $conn->query("SELECT status, COUNT(*) c FROM invoices WHERE is_deleted=0 GROUP BY status");
            while ($row = $r->fetch_assoc()) $by_inv_status[] = ['label' => ucfirst($row['status']), 'value' => (int)$row['c']];
            $charts['invoiceStatus'] = $by_inv_status;

        } elseif ($dashVariant === 'collector') {
            // my collections trend — scoped to selected range, adaptive bucket
            $daily = [];
            $stmt = $conn->prepare("SELECT DATE_FORMAT(payment_date, '$sqlFmt') AS d, SUM(amount) AS s
                                    FROM payments WHERE is_deleted=0 AND status='cleared' AND collected_by=?
                                      AND payment_date BETWEEN ? AND ?
                                    GROUP BY d ORDER BY d");
            $stmt->bind_param("iss", $user_id, $rangeStart, $rangeEnd); $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $daily[] = ['label' => $bucketLabel($row['d']), 'value' => (float)$row['s']];
            $stmt->close();
            $charts['myDailyCollections'] = $daily;

            // my area connection status breakdown
            $by_status = [];
            $myAreas = currentAreaIds($user_id);
            $area_id = $myAreas ? (int)$myAreas[0] : 0;
            if ($area_id) {
                $scope = areaScope('customers', $user_id);
                $stmt = $conn->prepare("SELECT connection_status s, COUNT(*) c FROM customers WHERE is_deleted=0 $scope GROUP BY connection_status");
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) $by_status[] = ['label' => ucfirst($row['s']), 'value' => (int)$row['c']];
                $stmt->close();
            }
            $charts['myAreaStatus'] = $by_status;
        }

        echo json_encode(['success' => true, 'role' => $role, 'data' => $charts]);
        exit();
    } catch (Throwable $e) {
        error_log("Dashboard charts error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load charts']);
        exit();
    }
}

// AJAX: recent activities
// global search — customers / invoices / packages, area-scoped, indexed prefix match
if (isset($_GET['action']) && $_GET['action'] === 'globalSearch') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(['success' => true, 'data' => []]); exit(); }
    try {
        $conn = getDBConnection();
        $like = '%' . $q . '%';
        $out  = [];

        if (can('customers', 'v')) {
            $st = $conn->prepare("SELECT c.id, c.customer_code, c.full_name, c.phone, c.connection_status
                                  FROM customers c
                                  WHERE c.is_deleted = 0 " . areaScope('c') . "
                                    AND (c.customer_code LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ?)
                                  ORDER BY c.full_name LIMIT 5");
            $st->bind_param("sss", $like, $like, $like);
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) $out[] = ['group' => 'Customers', 'icon' => 'fa-user',
                'label' => $x['full_name'], 'meta' => $x['customer_code'] . ' · ' . $x['connection_status'],
                'url' => 'customers.php?id=' . (int)$x['id']];
            $st->close();
        }
        if (can('invoices', 'v')) {
            $st = $conn->prepare("SELECT i.id, i.invoice_number, i.billing_month, i.total, i.status, cu.full_name
                                  FROM invoices i LEFT JOIN customers cu ON cu.id = i.customer_id
                                  WHERE i.is_deleted = 0 AND (i.invoice_number LIKE ? OR cu.full_name LIKE ?)
                                  ORDER BY i.id DESC LIMIT 5");
            $st->bind_param("ss", $like, $like);
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) $out[] = ['group' => 'Invoices', 'icon' => 'fa-file-invoice-dollar',
                'label' => $x['invoice_number'] . ' — ' . ($x['full_name'] ?? ''),
                'meta' => $x['billing_month'] . ' · ' . $x['status'],
                'url' => 'invoices.php?id=' . (int)$x['id']];
            $st->close();
        }
        if (can('packages', 'v')) {
            $st = $conn->prepare("SELECT id, name, speed_mbps FROM packages
                                  WHERE is_deleted = 0 AND name LIKE ? ORDER BY name LIMIT 5");
            $st->bind_param("s", $like);
            $st->execute();
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) $out[] = ['group' => 'Packages', 'icon' => 'fa-box',
                'label' => $x['name'], 'meta' => $x['speed_mbps'] . ' Mbps', 'url' => 'packages.php'];
            $st->close();
        }
        echo json_encode(['success' => true, 'data' => $out]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Search failed']);
    }
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'getRecentActivities') {
    header('Content-Type: application/json');
    try {
        $logs = $dashVariant === 'admin' ? getActivityLogs(null, $role, 15) : getActivityLogs($user_id, $role, 15);
        echo json_encode(['success' => true, 'data' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error loading activities']);
    }
    exit();
}

// hero copy per role
$hero_titles = [
    ROLE_SUPER_ADMIN => ['Welcome back, ' . htmlspecialchars($username), 'Full control panel — every metric, every customer, every rupee.'],
    ROLE_ADMIN       => ['Hello, ' . htmlspecialchars($username),         'Operations command — manage customers, billing, complaints, and field ops.'],
    ROLE_ACCOUNTANT  => ['Finance overview — ' . htmlspecialchars($username), 'Revenue, receivables, and outflow at a glance.'],
    ROLE_COLLECTOR   => ['Field dashboard — ' . htmlspecialchars($username),  'Today\'s collections, your area, your tickets.'],
];
$hero = $hero_titles[$role] ?? [getRoleDisplay($role) . ' — ' . htmlspecialchars($username), 'Your workspace at a glance.'];
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Dashboard - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>Overview</span>
                <span class="crumb-period" id="dashRangeActive"></span>
            </div>
            <!-- one bar: title + search + period + quick actions + bell — no separate tools section -->
            <div class="header dash-header">
                <h1><i class="fas fa-chart-line"></i> Dashboard</h1>

                <div class="header-tools">
                    <div class="dash-search">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="search" id="globalSearch" autocomplete="off" placeholder="Search customers, invoices, packages…">
                        <div class="dash-search-results" id="globalSearchResults"></div>
                    </div>
                    <div class="hdr-range">
                        <select id="dashRange">
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month" selected>This Month</option>
                            <option value="quarter">This Quarter</option>
                            <option value="custom">Custom range</option>
                        </select>
                    </div>
                    <div class="hdr-dates initially-hidden" id="dashCustomDates">
                        <input type="date" id="dashFrom" class="filter-input" aria-label="From date">
                        <input type="date" id="dashTo" class="filter-input" aria-label="To date">
                        <button type="button" class="btn btn-primary" onclick="applyCustomRange()"><i class="fas fa-check"></i> Apply</button>
                    </div>
                    <div class="act-more hdr-quick">
                        <button type="button" class="act-more-btn act-more-cta" title="Quick actions">
                            <i class="fas fa-bolt"></i> <span>Quick Actions</span> <i class="fas fa-chevron-down act-more-caret"></i>
                        </button>
                        <div class="act-more-menu">
                            <a class="act-more-item" href="customers.php?new=1"><i class="fas fa-user-plus"></i> New Customer</a>
                            <a class="act-more-item" href="payments.php?new=1"><i class="fas fa-hand-holding-dollar"></i> Record Payment</a>
                            <a class="act-more-item" href="invoices.php?new=1"><i class="fas fa-file-invoice-dollar"></i> New Invoice</a>
                            <?php if ($dashVariant === 'admin'): ?>
                            <a class="act-more-item" href="complaints.php?new=1"><i class="fas fa-headset"></i> Log Complaint</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php include 'notifications_bell.php'; ?>
                </div>
            </div>

            <?php
            // period KPIs written once — each board picks the ones it shows
            $periodKpi = [];
            $periodKpi['invoiced'] = <<<HTML
                <a class="kpi-card kpi-purple kpi-link" href="invoices.php" title="Invoices issued in the selected period">
                    <i class="fas fa-file-invoice-dollar kpi-icon"></i>
                    <div class="kpi-label">Invoiced</div>
                    <div class="kpi-value" id="kpi-invoicedAmount"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-invoicedCount"><i class="fas fa-calendar"></i> Selected period</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
HTML;
            $periodKpi['rate'] = <<<HTML
                <div class="kpi-card kpi-teal" title="Collected divided by invoiced for the selected period">
                    <i class="fas fa-percent kpi-icon"></i>
                    <div class="kpi-label">Collection Rate</div>
                    <div class="kpi-value" id="kpi-collectionRate"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-collectionRateSub"><i class="fas fa-scale-balanced"></i> Collected vs billed</div>
                </div>
HTML;
            $periodKpi['new'] = <<<HTML
                <a class="kpi-card kpi-green kpi-link" href="customers.php" title="Customers added in the selected period">
                    <i class="fas fa-user-plus kpi-icon"></i>
                    <div class="kpi-label">New Customers</div>
                    <div class="kpi-value" id="kpi-newCustomers"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-seedling"></i> Joined this period</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
HTML;
            $periodKpi['arpu'] = <<<HTML
                <div class="kpi-card kpi-indigo" title="Average revenue per active subscriber">
                    <i class="fas fa-chart-pie kpi-icon"></i>
                    <div class="kpi-label">ARPU</div>
                    <div class="kpi-value" id="kpi-arpu"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-arpuSub"><i class="fas fa-users"></i> Per active subscriber</div>
                </div>
HTML;
            $periodKpiCards = implode('', $periodKpi); // accountant board shows all four
            ?>
            <!-- KPI grid (5-col) -->
            <div class="dash-kpi-grid">
            <?php if ($dashVariant === 'admin'): ?>
                <!-- row 1: the money cycle — billed, collected, still owed -->
                <a class="kpi-card kpi-green kpi-link" href="payments.php?month=<?php echo date('Y-m'); ?>" title="View collections">
                    <i class="fas fa-money-bill-trend-up kpi-icon"></i>
                    <div class="kpi-label">Collections (Gross)</div>
                    <div class="kpi-value" id="kpi-monthRevenue"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-monthRevenueSub"><i class="fas fa-calendar"></i> Selected period</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <?php echo $periodKpi['invoiced'] . $periodKpi['rate']; ?>
                <a class="kpi-card kpi-amber kpi-link" href="invoices.php?filter=outstanding" title="View all outstanding invoices">
                    <i class="fas fa-file-invoice-dollar kpi-icon"></i>
                    <div class="kpi-label">Outstanding</div>
                    <div class="kpi-value" id="kpi-outstanding"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-hourglass-half"></i> Tap to collect</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <a class="kpi-card kpi-red kpi-link" href="invoices.php?filter=overdue" title="View overdue invoices">
                    <i class="fas fa-triangle-exclamation kpi-icon"></i>
                    <div class="kpi-label">Overdue Invoices</div>
                    <div class="kpi-value" id="kpi-overdueCount"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-clock"></i> Past due date</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>

                <!-- row 2: what is left after costs, and the base earning it -->
                <a class="kpi-card kpi-indigo kpi-link" href="expenses.php" title="View this month's expenses">
                    <i class="fas fa-receipt kpi-icon"></i>
                    <div class="kpi-label">Expenses</div>
                    <div class="kpi-value" id="kpi-monthExpenses"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-arrow-down"></i> Outflow</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <div class="kpi-card kpi-cyan" title="Net collected (after gateway fees) minus expenses">
                    <i class="fas fa-scale-balanced kpi-icon"></i>
                    <div class="kpi-label">Net Profit</div>
                    <div class="kpi-value" id="kpi-netProfit"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-netProfitSub"><i class="fas fa-coins"></i> Net of fees</div>
                </div>
                <a class="kpi-card kpi-blue kpi-link" href="customers.php?status=active" title="View active customers">
                    <i class="fas fa-user-check kpi-icon"></i>
                    <div class="kpi-label">Active Customers</div>
                    <div class="kpi-value" id="kpi-activeCustomers"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-circle"></i> Connected &amp; online</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <?php echo $periodKpi['new']; ?>
                <a class="kpi-card kpi-pink kpi-link" href="complaints.php" title="View open complaints">
                    <i class="fas fa-headset kpi-icon"></i>
                    <div class="kpi-label">Open Complaints</div>
                    <div class="kpi-value" id="kpi-openComplaints"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-phone-volume"></i> Needs attention</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
            <?php elseif ($dashVariant === 'accountant'): ?>
                <a class="kpi-card kpi-green kpi-link" href="payments.php?month=<?php echo date('Y-m'); ?>" title="View collections">
                    <i class="fas fa-money-bill-trend-up kpi-icon"></i>
                    <div class="kpi-label">Collections (Gross)</div>
                    <div class="kpi-value" id="kpi-monthRevenue"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-monthRevenueSub"><i class="fas fa-calendar"></i> Selected period</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <a class="kpi-card kpi-blue kpi-link" href="payments.php?date=<?php echo date('Y-m-d'); ?>" title="Today's collections">
                    <i class="fas fa-hand-holding-dollar kpi-icon"></i>
                    <div class="kpi-label">Today's Collections</div>
                    <div class="kpi-value" id="kpi-todayCollections"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-todayCollectionsSub"><i class="fas fa-calendar-day"></i> <?php echo date('M j, Y'); ?></div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <a class="kpi-card kpi-amber kpi-link" href="invoices.php?filter=outstanding" title="View outstanding invoices">
                    <i class="fas fa-file-invoice-dollar kpi-icon"></i>
                    <div class="kpi-label">Outstanding</div>
                    <div class="kpi-value" id="kpi-outstanding"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-hourglass-half"></i> Tap to collect</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <a class="kpi-card kpi-red kpi-link" href="invoices.php?filter=overdue" title="View overdue invoices">
                    <i class="fas fa-triangle-exclamation kpi-icon"></i>
                    <div class="kpi-label">Overdue Invoices</div>
                    <div class="kpi-value" id="kpi-overdueCount"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-overdueSub"><i class="fas fa-clock"></i> Past due</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <a class="kpi-card kpi-indigo kpi-link" href="expenses.php" title="View expenses">
                    <i class="fas fa-receipt kpi-icon"></i>
                    <div class="kpi-label">Expenses</div>
                    <div class="kpi-value" id="kpi-monthExpenses"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-arrow-down"></i> Outflow</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <div class="kpi-card kpi-teal" title="Net collected (after gateway fees) minus expenses">
                    <i class="fas fa-scale-balanced kpi-icon"></i>
                    <div class="kpi-label">Net Profit</div>
                    <div class="kpi-value" id="kpi-netProfit"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-netProfitSub"><i class="fas fa-coins"></i> Net of fees</div>
                </div>

                <?php echo $periodKpiCards; ?>
            <?php elseif ($dashVariant === 'collector'): ?>
                <a class="kpi-card kpi-orange kpi-link" href="payments.php?date=<?php echo date('Y-m-d'); ?>" title="Today's collections">
                    <i class="fas fa-hand-holding-dollar kpi-icon"></i>
                    <div class="kpi-label">Today's Collections</div>
                    <div class="kpi-value" id="kpi-todayCollections"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-todayCollectionsSub"><i class="fas fa-calendar-day"></i> <?php echo date('M j'); ?></div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <a class="kpi-card kpi-green kpi-link" href="payments.php?month=<?php echo date('Y-m'); ?>" title="Period collections">
                    <i class="fas fa-money-bill-trend-up kpi-icon"></i>
                    <div class="kpi-label">Period Collections</div>
                    <div class="kpi-value" id="kpi-monthCollections"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub" id="kpi-monthCollectionsSub"><i class="fas fa-calendar"></i> <?php echo date('F'); ?></div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <a class="kpi-card kpi-blue kpi-link" href="customers.php" title="My area customers">
                    <i class="fas fa-users kpi-icon"></i>
                    <div class="kpi-label">My Area Customers</div>
                    <div class="kpi-value" id="kpi-myAreaCustomers"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-map-marker-alt"></i> Total in your zone</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <a class="kpi-card kpi-teal kpi-link" href="customers.php?status=active" title="My active customers">
                    <i class="fas fa-user-check kpi-icon"></i>
                    <div class="kpi-label">My Active Customers</div>
                    <div class="kpi-value" id="kpi-myActiveCustomers"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-circle"></i> Connected now</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
                <a class="kpi-card kpi-red kpi-link" href="complaints.php" title="My assigned tickets">
                    <i class="fas fa-headset kpi-icon"></i>
                    <div class="kpi-label">My Open Tickets</div>
                    <div class="kpi-value" id="kpi-myOpenComplaints"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="kpi-sub"><i class="fas fa-phone-volume"></i> Assigned to you</div>
                    <i class="fas fa-arrow-right kpi-link-arrow"></i>
                </a>
            <?php endif; ?>
            </div>

            <!-- Charts -->
            <?php if ($dashVariant === 'admin'): ?>
            <!-- row of three: trend line, collection bars, status split -->
            <div class="dash-charts-grid cols-3">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-chart-line"></i> Revenue vs Expenses</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartRevenue"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-chart-column"></i> Collections</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartDaily"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-circle-nodes"></i> Connection Status</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartConnStatus"></canvas></div>
                </div>
            </div>
            <div class="dash-charts-grid cols-3">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-headset"></i> Complaints by Category</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartComplaints"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-box"></i> Customers by Package</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartByPackage"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-receipt"></i> Top Expense Categories (this month)</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartTopExp"></canvas></div>
                </div>
            </div>
            <div class="dash-charts-grid cols-1">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-map-marked-alt"></i> Customers by Area</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartByArea"></canvas></div>
                </div>
            </div>

            <?php elseif ($dashVariant === 'accountant'): ?>
            <div class="dash-charts-grid">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-chart-line"></i> Cash Flow</div>
                    </div>
                    <div class="chart-canvas-wrap tall"><canvas id="chartRevenue"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-credit-card"></i> Payments by Method</div>
                    </div>
                    <div class="chart-canvas-wrap tall"><canvas id="chartByMethod"></canvas></div>
                </div>
            </div>
            <div class="dash-charts-grid">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-receipt"></i> Top Expense Categories</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartTopExp"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-file-invoice"></i> Invoice Status Breakdown</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartInvStatus"></canvas></div>
                </div>
            </div>

            <?php elseif ($dashVariant === 'collector'): ?>
            <div class="dash-charts-grid">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-chart-line"></i> My Collections</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartMyDaily"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title"><i class="fas fa-circle-nodes"></i> My Area Customer Status</div>
                    </div>
                    <div class="chart-canvas-wrap"><canvas id="chartMyAreaStatus"></canvas></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (can('invoices', 'v')): ?>
            <!-- Upcoming Renewals -->
            <div class="dash-activity mt-24">
                <div class="dash-activity-head">
                    <div>
                        <h2><i class="fas fa-rotate"></i> Upcoming Renewals</h2>
                        <p class="muted mb-0" id="renewalsSub">Subscriptions falling due soon</p>
                    </div>
                    <div class="renewal-tools">
                        <div class="renewal-days" id="renewalDays">
                            <button type="button" class="btn btn-secondary btn-sm active" data-days="7"><i class="fas fa-calendar-day"></i> 7 days</button>
                            <button type="button" class="btn btn-secondary btn-sm" data-days="15">15 days</button>
                            <button type="button" class="btn btn-secondary btn-sm" data-days="30">30 days</button>
                        </div>
                        <a class="btn btn-secondary btn-sm" href="invoices.php?filter=outstanding"><i class="fas fa-arrow-right"></i> All Invoices</a>
                    </div>
                </div>
                <div class="dash-activity-body">
                    <div id="renewalsLoading" class="activities-loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading renewals...</p>
                    </div>
                    <div id="renewalsContent" class="initially-hidden">
                        <table class="setup-guide-table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-user"></i> Customer</th>
                                    <th><i class="fas fa-box"></i> Package</th>
                                    <th><i class="fas fa-file-invoice"></i> Invoice</th>
                                    <th><i class="fas fa-coins"></i> Amount Due</th>
                                    <th><i class="fas fa-calendar-day"></i> Renews</th>
                                    <th><i class="fas fa-bolt"></i> Action</th>
                                </tr>
                            </thead>
                            <tbody id="renewalsBody"></tbody>
                        </table>
                        <div id="renewalsEmpty" class="activities-loading initially-hidden">
                            <i class="fas fa-circle-check"></i>
                            <p>Nothing falls due in this window.</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Activities -->
            <div class="dash-activity mt-24">
                <div class="dash-activity-head">
                    <div>
                        <h2><i class="fas fa-history"></i> Recent Activity</h2>
                        <p class="muted mb-0">Last 15 activity logs</p>
                    </div>
                    <?php if (canViewActivityLogs()): ?>
                    <a class="btn btn-secondary btn-sm" href="logs.php"><i class="fas fa-arrow-right"></i> View All Logs</a>
                    <?php endif; ?>
                </div>
                <div class="dash-activity-body">
                    <div id="activitiesLoading" class="activities-loading">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Loading activities...</p>
                    </div>
                    <div id="activitiesContent" class="initially-hidden">
                        <table class="setup-guide-table">
                            <thead>
                                <tr>
                                    <?php if ($dashVariant === 'admin'): ?>
                                    <th><i class="fas fa-user"></i> User</th>
                                    <?php endif; ?>
                                    <th><i class="fas fa-bolt"></i> Action</th>
                                    <th><i class="fas fa-info-circle"></i> Details</th>
                                    <th><i class="fas fa-globe"></i> IP Address</th>
                                    <th><i class="fas fa-clock"></i> Time</th>
                                </tr>
                            </thead>
                            <tbody id="activitiesTableBody"></tbody>
                        </table>
                        <div id="noActivities" class="activities-empty initially-hidden">
                            <i class="fas fa-inbox"></i>
                            <p>No recent activities found</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <script>
    const CURRENCY = <?php echo json_encode($currency); ?>;
    const ROLE = <?php echo json_encode($role); ?>;
    const DASH = <?php echo json_encode($dashVariant); ?>; // admin | accountant | collector

    // helpers
    function fmtNum(n) { n = Number(n) || 0; return n.toLocaleString('en-US'); }
    function fmtMoney(n) { n = Number(n) || 0; return CURRENCY + ' ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtMoneyShort(n) { n = Number(n) || 0; if (n >= 1e7) return CURRENCY + (n/1e7).toFixed(1) + 'Cr'; if (n >= 1e5) return CURRENCY + (n/1e5).toFixed(1) + 'L'; if (n >= 1e3) return CURRENCY + (n/1e3).toFixed(1) + 'K'; return CURRENCY + n.toFixed(0); }
    function setVal(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }
    // a loss must look like one
    function setMoney(id, n) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = fmtMoney(n);
        el.classList.toggle('neg', (Number(n) || 0) < 0);
    }

    // chart palette (vivid)
    // chart colours come from the live theme variables, so whatever the admin picks
    // in My Account (primary / accent) flows straight into every chart on this page
    const cssVar = (n, fb) => ((getComputedStyle(document.documentElement).getPropertyValue(n) || '').trim() || fb);
    let PALETTE = [], PALETTE_BG = [];
    function refreshPalette() {
        PALETTE = [
            cssVar('--navy-accent', '#0074D9'), cssVar('--tone-green', '#16a34a'),
            cssVar('--tone-amber', '#f59e0b'),  cssVar('--tone-red', '#ef4444'),
            cssVar('--tone-violet', '#7c3aed'), cssVar('--tone-teal', '#14b8a6'),
            cssVar('--tone-pink', '#ec4899'),   cssVar('--tone-indigo', '#6366f1'),
            cssVar('--navy-light', '#003366'),  cssVar('--tone-orange', '#f97316')
        ];
        PALETTE_BG = PALETTE.map(c => c + '33'); // 20% alpha
        if (window.Chart) {
            Chart.defaults.color       = cssVar('--text-muted', '#666');
            Chart.defaults.borderColor = cssVar('--border-light', '#eee');
            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        }
    }
    refreshPalette();

    // donuts get the total in the middle, like the design
    const centreTotalPlugin = {
        id: 'centreTotal',
        afterDraw(chart) {
            if (!chart.config.options.centreLabel) return;
            const ds = chart.data.datasets[0];
            if (!ds) return;
            const total = ds.data.reduce((a, b) => a + (Number(b) || 0), 0);
            const { ctx, chartArea } = chart;
            if (!chartArea) return;
            const cx = (chartArea.left + chartArea.right) / 2, cy = (chartArea.top + chartArea.bottom) / 2;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.fillStyle = cssVar('--text-primary', '#333');
            ctx.font = '700 22px ' + Chart.defaults.font.family;
            ctx.fillText(fmtNum(total), cx, cy);
            ctx.fillStyle = cssVar('--text-muted', '#777');
            ctx.font = '600 11px ' + Chart.defaults.font.family;
            ctx.fillText(chart.config.options.centreLabel, cx, cy + 17);
            ctx.restore();
        }
    };
    if (window.Chart) Chart.register(centreTotalPlugin);

    // legend entries read "Active  3 (60%)" — the value matters more than the swatch
    function countLegend() {
        return {
            position: 'right',
            labels: {
                boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', padding: 9,
                font: { size: 11 },
                generateLabels(chart) {
                    const ds = chart.data.datasets[0] || { data: [] };
                    const total = ds.data.reduce((a, b) => a + (Number(b) || 0), 0) || 1;
                    return chart.data.labels.map((l, i) => ({
                        text: `${l}   ${fmtNum(ds.data[i])} (${Math.round(ds.data[i] / total * 100)}%)`,
                        fillStyle: ds.backgroundColor[i],
                        strokeStyle: ds.backgroundColor[i],
                        index: i
                    }));
                }
            }
        };
    }

    // Chart.js global defaults
    if (window.Chart) {
        Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#475569';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
    }

    // date-range scope state
    let dashRange = { range: 'month', from: '', to: '' };
    function rangeQuery() {
        let q = '&range=' + encodeURIComponent(dashRange.range);
        if (dashRange.range === 'custom') q += '&from=' + encodeURIComponent(dashRange.from) + '&to=' + encodeURIComponent(dashRange.to);
        return q;
    }

    // KPI loader
    // the four period KPIs — one writer for both boards
    function setPeriodKpis(d) {
        setVal('kpi-invoicedAmount',    fmtMoney(d.invoicedAmount));
        setVal('kpi-invoicedCount',     fmtNum(d.invoicedCount) + ' invoice(s) · ' + (d.rangeLabel || 'period'));
        setVal('kpi-collectionRate',    (Number(d.collectionRate) || 0).toFixed(1) + '%');
        setVal('kpi-collectionRateSub', fmtMoney(d.monthRevenue) + ' of ' + fmtMoney(d.invoicedAmount));
        setVal('kpi-newCustomers',      fmtNum(d.newCustomers));
        setVal('kpi-arpu',              fmtMoney(d.arpu));
        setVal('kpi-arpuSub',           fmtNum(d.activeCustomers) + ' active subscriber(s)');
    }

    function loadDashboardStats() {
        $.getJSON('?action=getDashboardStats' + rangeQuery(), function(resp) {
            if (!resp.success) return;
            const d = resp.data, r = resp.role;
            // reflect active period
            const ra = document.getElementById('dashRangeActive');
            if (ra && d.rangeLabel) ra.textContent = '— ' + d.rangeLabel;
            if (DASH === 'admin') {
                setVal('kpi-monthRevenue',          fmtMoney(d.monthRevenue));
                setVal('kpi-monthRevenueSub',       d.rangeLabel || 'Selected period');
                setVal('kpi-outstanding',           fmtMoney(d.outstanding));
                setVal('kpi-overdueCount',          fmtNum(d.overdueCount));
                setVal('kpi-openComplaints',        fmtNum(d.openComplaints));
                setVal('kpi-monthExpenses',         fmtMoney(d.monthExpenses));
                setMoney('kpi-netProfit',           d.netProfit);
                setVal('kpi-netProfitSub',          fmtMoney(d.netCollected) + ' net collected');
                setVal('kpi-activeCustomers',       fmtNum(d.activeCustomers));
                setPeriodKpis(d);
            } else if (DASH === 'accountant') {
                setVal('kpi-monthRevenue',         fmtMoney(d.monthRevenue));
                setVal('kpi-monthRevenueSub',      fmtNum(d.monthRevenueCount) + ' payments');
                setVal('kpi-todayCollections',     fmtMoney(d.todayCollections));
                setVal('kpi-todayCollectionsSub',  fmtNum(d.todayCollectionsCount) + ' today');
                setVal('kpi-outstanding',          fmtMoney(d.outstanding));
                setVal('kpi-overdueCount',         fmtNum(d.overdueCount));
                setVal('kpi-overdueSub',           fmtMoney(d.overdueSum) + ' past due');
                setVal('kpi-monthExpenses',        fmtMoney(d.monthExpenses));
                setMoney('kpi-netProfit',          d.netProfit);
                setVal('kpi-netProfitSub',         fmtMoney(d.netCollected) + ' net collected');
                setPeriodKpis(d);
            } else if (DASH === 'collector') {
                setVal('kpi-todayCollections',     fmtMoney(d.todayCollections));
                setVal('kpi-todayCollectionsSub',  fmtNum(d.todayCollectionsCount) + ' payments today');
                setVal('kpi-monthCollections',     fmtMoney(d.monthCollections));
                setVal('kpi-monthCollectionsSub',  fmtNum(d.monthCollectionsCount) + ' this month');
                setVal('kpi-myAreaCustomers',      fmtNum(d.myAreaCustomers));
                setVal('kpi-myActiveCustomers',    fmtNum(d.myActiveCustomers));
                setVal('kpi-myOpenComplaints',     fmtNum(d.myOpenComplaints));
            }
        });
    }

    // ── Chart builders ──────────────────────────────────────────────
    // track live chart instances so re-renders don't collide on the canvas
    const chartInstances = {};
    function mountChart(canvasId, cfg) {
        const el = document.getElementById(canvasId);
        if (!el) return;
        if (chartInstances[canvasId]) chartInstances[canvasId].destroy();
        chartInstances[canvasId] = new Chart(el, cfg);
    }
    // grouped bars — the revenue/expense comparison reads better side by side than as two areas
    function buildLine(canvasId, labels, datasets) {
        const el = document.getElementById(canvasId);
        if (!el) return;
        mountChart(canvasId, {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => fmtMoneyShort(v) } },
                    x: { grid: { display: false } }
                },
                interaction: { mode: 'index', intersect: false }
            }
        });
    }
    function buildBar(canvasId, labels, dataset, money) {
        const el = document.getElementById(canvasId);
        if (!el) return;
        mountChart(canvasId, {
            type: 'bar',
            data: { labels, datasets: [Object.assign({ label: 'Value', backgroundColor: PALETTE.slice(0, labels.length), borderRadius: 4 }, dataset)] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => money ? fmtMoney(ctx.raw) : fmtNum(ctx.raw) } } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => money ? fmtMoneyShort(v) : v } }, x: { grid: { display: false } } }
            }
        });
    }
    function buildHBar(canvasId, labels, dataset, money) {
        const el = document.getElementById(canvasId);
        if (!el) return;
        mountChart(canvasId, {
            type: 'bar',
            data: { labels, datasets: [Object.assign({ label: 'Value', backgroundColor: PALETTE.slice(0, labels.length), borderRadius: 4 }, dataset)] },
            options: {
                responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => money ? fmtMoney(ctx.raw) : fmtNum(ctx.raw) } } },
                scales: { x: { beginAtZero: true, ticks: { callback: v => money ? fmtMoneyShort(v) : v } }, y: { grid: { display: false } } }
            }
        });
    }
    function buildDoughnut(canvasId, labels, data) {
        const el = document.getElementById(canvasId);
        if (!el) return;
        mountChart(canvasId, {
            type: 'doughnut',
            data: { labels, datasets: [{ data, backgroundColor: PALETTE.slice(0, labels.length), borderWidth: 2, borderColor: cssVar('--dash-tile-bg', '#fff') }] },
            options: { responsive: true, maintainAspectRatio: false, centreLabel: 'Total',
                       plugins: { legend: countLegend() }, cutout: '68%' }
        });
    }
    function buildPie(canvasId, labels, data) {
        const el = document.getElementById(canvasId);
        if (!el) return;
        mountChart(canvasId, {
            type: 'pie',
            data: { labels, datasets: [{ data, backgroundColor: PALETTE.slice(0, labels.length), borderWidth: 2, borderColor: cssVar('--dash-tile-bg', '#fff') }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: countLegend() } }
        });
    }

    // re-title a chart-card to reflect active range + bucket
    function chartTitle(canvasId, baseHtml, meta) {
        const el = document.getElementById(canvasId);
        if (!el) return;
        const card = el.closest('.chart-card');
        const t = card && card.querySelector('.chart-card-title');
        if (!t) return;
        let suffix = meta && meta.label ? (' — ' + meta.label) : '';
        if (meta && meta.bucket) suffix += ' (by ' + meta.bucket + ')';
        t.innerHTML = baseHtml + suffix;
    }

    // align expense buckets to revenue buckets by raw key
    function expenseSeries(revTrend, expMap) {
        return (revTrend || []).map(x => {
            const v = expMap && Object.prototype.hasOwnProperty.call(expMap, x.key) ? expMap[x.key] : 0;
            return Number(v) || 0;
        });
    }

    // ── Load + render charts ────────────────────────────────────────
    function loadCharts() {
        $.getJSON('?action=getCharts' + rangeQuery(), function(resp) {
            if (!resp.success) return;
            const d = resp.data, r = resp.role;
            const meta = d.trendMeta || {};

            if (DASH === 'admin') {
                // revenue + expenses combined line
                if (d.revenueTrend && d.revenueTrend.length) {
                    const labels = d.revenueTrend.map(x => x.label);
                    const revVals = d.revenueTrend.map(x => x.value);
                    const expVals = expenseSeries(d.revenueTrend, d.expensesTrend);
                    buildLine('chartRevenue', labels, [
                        { label: 'Revenue',  data: revVals, backgroundColor: cssVar('--tone-green', '#16a34a'), borderRadius: 3, maxBarThickness: 18 },
                        { label: 'Expenses', data: expVals, backgroundColor: cssVar('--tone-red', '#ef4444'), borderRadius: 3, maxBarThickness: 18 }
                    ]);
                }
                chartTitle('chartRevenue', '<i class="fas fa-chart-line"></i> Revenue vs Expenses', meta);
                if (d.dailyCollections) buildBar('chartDaily', d.dailyCollections.map(x=>x.label), { data: d.dailyCollections.map(x=>x.value) }, true);
                chartTitle('chartDaily', '<i class="fas fa-chart-column"></i> Collections', meta);
                if (d.connectionStatus) buildDoughnut('chartConnStatus', d.connectionStatus.map(x=>x.label), d.connectionStatus.map(x=>x.value));
                if (d.complaintsByCategory) buildPie('chartComplaints', d.complaintsByCategory.map(x=>x.label), d.complaintsByCategory.map(x=>x.value));
                if (d.customersByPackage) buildDoughnut('chartByPackage', d.customersByPackage.map(x=>x.label), d.customersByPackage.map(x=>x.value));
                if (d.customersByArea) buildHBar('chartByArea', d.customersByArea.map(x=>x.label), { data: d.customersByArea.map(x=>x.value) }, false);
                if (d.topExpenses) buildHBar('chartTopExp', d.topExpenses.map(x=>x.label), { data: d.topExpenses.map(x=>x.value) }, true);

            } else if (DASH === 'accountant') {
                if (d.revenueTrend) {
                    const labels = d.revenueTrend.map(x => x.label);
                    const revVals = d.revenueTrend.map(x => x.value);
                    const expVals = expenseSeries(d.revenueTrend, d.expensesTrend);
                    buildLine('chartRevenue', labels, [
                        { label: 'Revenue',  data: revVals, backgroundColor: cssVar('--tone-green', '#16a34a'), borderRadius: 3, maxBarThickness: 18 },
                        { label: 'Expenses', data: expVals, backgroundColor: cssVar('--tone-red', '#ef4444'), borderRadius: 3, maxBarThickness: 18 }
                    ]);
                }
                chartTitle('chartRevenue', '<i class="fas fa-chart-line"></i> Cash Flow', meta);
                if (d.paymentsByMethod) buildDoughnut('chartByMethod', d.paymentsByMethod.map(x=>x.label), d.paymentsByMethod.map(x=>x.value));
                if (d.topExpenses) buildHBar('chartTopExp', d.topExpenses.map(x=>x.label), { data: d.topExpenses.map(x=>x.value) }, true);
                if (d.invoiceStatus) buildDoughnut('chartInvStatus', d.invoiceStatus.map(x=>x.label), d.invoiceStatus.map(x=>x.value));

            } else if (DASH === 'collector') {
                if (d.myDailyCollections) buildBar('chartMyDaily', d.myDailyCollections.map(x=>x.label), { data: d.myDailyCollections.map(x=>x.value) }, true);
                chartTitle('chartMyDaily', '<i class="fas fa-chart-line"></i> My Collections', meta);
                if (d.myAreaStatus) buildDoughnut('chartMyAreaStatus', d.myAreaStatus.map(x=>x.label), d.myAreaStatus.map(x=>x.value));
            }
        });
    }

    // ── Recent activities (preserved from original) ─────────────────
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
        if (code.textContent === code.getAttribute('data-masked')) { code.textContent = code.getAttribute('data-full'); icon.className = 'fas fa-eye-slash'; }
        else { code.textContent = code.getAttribute('data-masked'); icon.className = 'fas fa-eye'; }
    }
    function escapeHtml(text) { if (!text) return ''; const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }

    function loadRecentActivities() {
        $.getJSON('?action=getRecentActivities', function(resp) {
            $('#activitiesLoading').hide();
            $('#activitiesContent').show();
            if (resp.success && resp.data.length > 0) {
                const tbody = document.getElementById('activitiesTableBody');
                tbody.innerHTML = '';
                resp.data.forEach(function(log) {
                    const time = new Date(log.timestamp);
                    const timeStr = time.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                        + ' ' + time.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                    let badgeClass = 'action-badge-default';
                    const al = log.action.toLowerCase();
                    if (al.includes('login') || al.includes('signup')) badgeClass = 'action-badge-success';
                    else if (al.includes('delete') || al.includes('logout')) badgeClass = 'action-badge-danger';
                    else if (al.includes('update') || al.includes('change')) badgeClass = 'action-badge-warning';

                    let row = '<tr>';
                    <?php if ($dashVariant === 'admin'): ?>
                    row += '<td><strong>' + escapeHtml(log.username) + '</strong></td>';
                    <?php endif; ?>
                    row += '<td><span class="action-badge ' + badgeClass + '">' + escapeHtml(log.action) + '</span></td>';
                    row += '<td class="activity-detail">' + escapeHtml(log.details || '-') + '</td>';
                    row += '<td class="activity-ip">' + renderIP(log.ip_address) + '</td>';
                    row += '<td class="activity-time">' + timeStr + '</td>';
                    row += '</tr>';
                    tbody.innerHTML += row;
                });
            } else {
                $('#noActivities').show();
            }
        }).fail(function() {
            $('#activitiesLoading').hide();
            $('#activitiesContent').show();
            $('#noActivities').show();
        });
    }

    // ── Upcoming renewals ───────────────────────────────────────────
    let renewalDays = 7;
    const dayLabel = d => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });

    function renewChip(n) {
        const cls = n <= 0 ? 'today' : (n <= 2 ? 'soon' : '');
        const txt = n <= 0 ? 'Today' : (n === 1 ? 'Tomorrow' : 'in ' + n + ' days');
        return '<span class="renew-chip ' + cls + '"><i class="fas fa-clock"></i> ' + txt + '</span>';
    }

    function loadRenewals() {
        const body = document.getElementById('renewalsBody');
        if (!body) return;   // role can't see invoices
        $('#renewalsLoading').show();
        $('#renewalsContent').addClass('initially-hidden');
        $.getJSON('?action=getUpcomingRenewals&days=' + renewalDays, function (r) {
            $('#renewalsLoading').hide();
            $('#renewalsContent').removeClass('initially-hidden');
            if (!r.success) return;

            let sub = r.count
                ? fmtNum(r.count) + ' renewal(s) · ' + fmtMoney(r.amount) + ' due in the next ' + r.days + ' days'
                : 'Nothing falls due in the next ' + r.days + ' days';
            if (r.autoBilling && r.autoBilling.on) sub += ' · next auto-billing ' + dayLabel(r.autoBilling.next);
            $('#renewalsSub').text(sub);

            body.innerHTML = r.data.map(function (x) {
                const tel = x.phone ? '<a class="btn btn-secondary btn-sm" href="tel:' + escapeHtml(x.phone) + '" title="Call"><i class="fas fa-phone"></i></a>' : '';
                return '<tr>' +
                    '<td><strong>' + escapeHtml(x.name) + '</strong>' + (x.code ? '<div class="muted">' + escapeHtml(x.code) + '</div>' : '') + '</td>' +
                    '<td>' + escapeHtml(x.pkg) + (x.price ? '<div class="muted">' + fmtMoney(x.price) + '/mo</div>' : '') + '</td>' +
                    '<td><a class="renew-inv" href="invoices.php?search=' + encodeURIComponent(x.number) + '">' + escapeHtml(x.number) + '</a>' +
                        '<div class="muted">' + (x.status === 'partial' ? 'Partly paid' : 'Unpaid') + '</div></td>' +
                    '<td><span class="outstanding-badge due">' + fmtMoney(x.amount) + '</span></td>' +
                    '<td>' + dayLabel(x.due) + '<div class="renew-when">' + renewChip(x.inDays) + '</div></td>' +
                    '<td class="renewal-actions"><a class="btn btn-primary btn-sm" href="payments.php?new=1&customer_id=' + x.cid + '" title="Record payment"><i class="fas fa-hand-holding-dollar"></i></a>' + tel + '</td>' +
                '</tr>';
            }).join('');
            document.getElementById('renewalsEmpty').classList.toggle('initially-hidden', r.data.length > 0);
        }).fail(function () {
            $('#renewalsLoading').hide();
            $('#renewalsContent').removeClass('initially-hidden');
        });
    }

    $('#renewalDays').on('click', 'button', function () {
        renewalDays = Number(this.dataset.days) || 7;
        $('#renewalDays button').removeClass('active');
        this.classList.add('active');
        loadRenewals();
    });

    // ── Date-range selector ─────────────────────────────────────────
    function refreshDashboard() {
        // re-pull KPIs + trend charts for the selected period
        loadDashboardStats();
        loadCharts();
    }
    function applyCustomRange() {
        const f = document.getElementById('dashFrom').value;
        const t = document.getElementById('dashTo').value;
        if (!f || !t) {
            Swal.fire({ icon: 'warning', title: 'Pick both dates', text: 'Select a From and To date.' });
            return;
        }
        dashRange = { range: 'custom', from: f, to: t };
        refreshDashboard();
    }
    // period picker lives in the header now — custom reveals the two date inputs beside it
    const dashRangeSel = document.getElementById('dashRange');
    if (dashRangeSel) {
        new SearchableDropdown({ selectId: 'dashRange', placeholder: 'Period...', icon: 'fas fa-calendar-alt', allowClear: false });
        dashRangeSel.addEventListener('change', function() {
            const rg = this.value;
            const custom = document.getElementById('dashCustomDates');
            if (rg === 'custom') {
                custom.classList.remove('initially-hidden');
                return; // wait for Apply
            }
            custom.classList.add('initially-hidden');
            dashRange = { range: rg, from: '', to: '' };
            refreshDashboard();
        });
    }

    // ── global search — debounced, grouped, keyboard-dismissable ──
    (function () {
        const box = document.getElementById('globalSearch');
        const panel = document.getElementById('globalSearchResults');
        if (!box || !panel) return;
        let timer = null, lastQ = '';
        const close = () => panel.classList.remove('open');
        box.addEventListener('input', function () {
            const q = this.value.trim();
            clearTimeout(timer);
            if (q.length < 2) { close(); return; }
            timer = setTimeout(function () {
                if (q === lastQ) { panel.classList.add('open'); return; }
                lastQ = q;
                $.getJSON('?action=globalSearch&q=' + encodeURIComponent(q), function (r) {
                    if (!r.success || !r.data.length) {
                        panel.innerHTML = '<div class="dash-sr-group">Nothing found</div>';
                    } else {
                        let html = '', group = '';
                        r.data.forEach(function (x) {
                            if (x.group !== group) { group = x.group; html += `<div class="dash-sr-group">${escapeHtml(group)}</div>`; }
                            html += `<a class="dash-sr-item" href="${escapeHtml(x.url)}"><i class="fas ${escapeHtml(x.icon)}"></i><span>${escapeHtml(x.label)}</span><small>${escapeHtml(x.meta)}</small></a>`;
                        });
                        panel.innerHTML = html;
                    }
                    panel.classList.add('open');
                });
            }, 250);
        });
        box.addEventListener('focus', function () { if (panel.innerHTML.trim() && this.value.trim().length > 1) panel.classList.add('open'); });
        box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); this.blur(); } });
        document.addEventListener('click', function (e) { if (!e.target.closest('.dash-search')) close(); });
    })();

    // live clock in the date pill
    (function () {
        const el = document.getElementById('dashClock');
        if (!el) return;
        const tick = () => { el.textContent = new Date().toLocaleString(undefined,
            { weekday: 'long', hour: '2-digit', minute: '2-digit', hour12: true }); };
        tick();
        setInterval(tick, 20000);
    })();

    // theme switched (or admin saved new colours) → charts repaint in the new palette
    new MutationObserver(function () {
        refreshPalette();
        loadCharts();
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });

    loadDashboardStats();
    loadCharts();
    loadRenewals();
    loadRecentActivities();
    </script>
</body>
</html>
