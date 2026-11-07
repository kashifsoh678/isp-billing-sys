<?php
 
require_once 'config.php';

requirePerm('ledger', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'ledger';
$can_add      = can('ledger', 'a');
$can_delete   = can('ledger', 'd');
$currency     = getSetting('currency_symbol', '$');

// party_type => [label, icon]. debit = owed to us / held by us, credit = owed by us
$PARTY_TYPES = [
    'customer' => ['Customer', 'fas fa-user-friends'],
    'vendor'   => ['Vendor',   'fas fa-truck'],
    'staff'    => ['Staff',    'fas fa-id-badge'],
    'bank'     => ['Bank',     'fas fa-university'],
    'cash'     => ['Cash',     'fas fa-wallet'],
    'other'    => ['Other',    'fas fa-circle-dot'],
];
$REF_TYPES = ['opening', 'manual', 'transfer'];   // the only ones a human may post by hand

// name for a party id, one query per type
function partyName($conn, $type, $id) {
    $id = (int)$id;
    $map = [
        'customer' => ["SELECT CONCAT(full_name, ' (', customer_code, ')') n FROM customers WHERE id = ?"],
        'vendor'   => ["SELECT CONCAT(vendor_name, ' (', vendor_code, ')') n FROM vendors WHERE id = ?"],
        'staff'    => ["SELECT COALESCE(NULLIF(name,''), username) n FROM users WHERE id = ?"],
        'bank'     => ["SELECT CONCAT(account_name, ' — ', bank_name) n FROM bank_accounts WHERE id = ?"],
        'cash'     => ["SELECT CONCAT(account_name, ' — ', bank_name) n FROM bank_accounts WHERE id = ?"],
    ];
    if (!isset($map[$type]) || $id <= 0) return ucfirst($type);
    $st = $conn->prepare($map[$type][0]);
    $st->bind_param("i", $id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ? $r['n'] : ucfirst($type) . " #$id";
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getParties': {
                $type = $_GET['party_type'] ?? '';
                $rows = [];
                $scope = areaScope('customers');
                $sqls = [
                    'customer' => "SELECT id, CONCAT(full_name, ' (', customer_code, ')') n FROM customers WHERE is_deleted = 0 $scope ORDER BY full_name ASC LIMIT 3000",
                    'vendor'   => "SELECT id, CONCAT(vendor_name, ' (', vendor_code, ')') n FROM vendors WHERE is_deleted = 0 ORDER BY vendor_name ASC",
                    'staff'    => "SELECT id, COALESCE(NULLIF(name,''), username) n FROM users WHERE is_deleted = 0 ORDER BY name ASC",
                    'bank'     => "SELECT id, CONCAT(account_name, ' — ', bank_name) n FROM bank_accounts WHERE is_deleted = 0 AND account_type <> 'cash' ORDER BY account_name ASC",
                    'cash'     => "SELECT id, CONCAT(account_name, ' — ', bank_name) n FROM bank_accounts WHERE is_deleted = 0 AND account_type = 'cash' ORDER BY account_name ASC",
                ];
                if (isset($sqls[$type])) {
                    $r = @$conn->query($sqls[$type]);
                    if ($r) while ($x = $r->fetch_assoc()) $rows[] = ['id' => (int)$x['id'], 'name' => $x['n']];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            // one party statement: opening + running balance + closing
            case 'getLedger': {
                $type = $_GET['party_type'] ?? '';
                $pid  = (int)($_GET['party_id'] ?? 0);
                $from = $_GET['from'] ?? '';
                $to   = $_GET['to'] ?? '';
                if (!isset($PARTY_TYPES[$type])) { echo json_encode(['success' => false, 'message' => 'Pick a party type']); exit(); }
                if ($pid <= 0)                   { echo json_encode(['success' => false, 'message' => 'Pick a party']); exit(); }

                // customers outside my areas stay invisible
                if ($type === 'customer') {
                    $c = $conn->prepare("SELECT area_id FROM customers WHERE id = ? AND is_deleted = 0");
                    $c->bind_param("i", $pid);
                    $c->execute();
                    $cr = $c->get_result()->fetch_assoc();
                    $c->close();
                    if (!$cr || !areaAllowed($cr['area_id'])) { echo json_encode(['success' => false, 'message' => 'Customer not in your scope']); exit(); }
                }

                $opening = 0.0;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                    $o = $conn->prepare("SELECT COALESCE(SUM(debit - credit), 0) b FROM ledger_entries
                                         WHERE is_deleted = 0 AND party_type = ? AND party_id = ? AND entry_date < ?");
                    $o->bind_param("sis", $type, $pid, $from);
                    $o->execute();
                    $opening = (float)$o->get_result()->fetch_assoc()['b'];
                    $o->close();
                }

                $where = "is_deleted = 0 AND party_type = ? AND party_id = ?";
                $types = "si"; $vals = [$type, $pid];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= " AND entry_date >= ?"; $types .= "s"; $vals[] = $from; }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where .= " AND entry_date <= ?"; $types .= "s"; $vals[] = $to; }

                $stmt = $conn->prepare("SELECT id, entry_date, particulars, ref_type, ref_id, ref_number, debit, credit
                                        FROM ledger_entries WHERE $where
                                        ORDER BY entry_date ASC, id ASC");
                $stmt->bind_param($types, ...$vals);
                $stmt->execute();
                $res = $stmt->get_result();

                $run = $opening; $td = 0.0; $tc = 0.0; $lines = [];
                while ($r = $res->fetch_assoc()) {
                    $d = (float)$r['debit']; $c = (float)$r['credit'];
                    $run += $d - $c; $td += $d; $tc += $c;
                    $lines[] = [
                        'id'          => (int)$r['id'],
                        'date'        => $r['entry_date'],
                        'particulars' => $r['particulars'],
                        'ref_type'    => $r['ref_type'],
                        'ref_number'  => $r['ref_number'],
                        'debit'       => number_format($d, 2, '.', ''),
                        'credit'      => number_format($c, 2, '.', ''),
                        'balance'     => number_format($run, 2, '.', ''),
                        'manual'      => in_array($r['ref_type'], ['manual', 'opening', 'transfer'], true) ? 1 : 0,
                    ];
                }
                $stmt->close();

                echo json_encode(['success' => true,
                    'party'   => ['type' => $type, 'id' => $pid, 'name' => partyName($conn, $type, $pid)],
                    'opening' => number_format($opening, 2, '.', ''),
                    'lines'   => $lines,
                    'total_debit'  => number_format($td, 2, '.', ''),
                    'total_credit' => number_format($tc, 2, '.', ''),
                    'closing' => number_format($run, 2, '.', ''),
                    'currency' => $currency]);
                exit();
            }

            // every entry in the window, whoever it belongs to
            case 'getDayBook': {
                $from = $_GET['from'] ?? '';
                $to   = $_GET['to'] ?? '';
                $where = "l.is_deleted = 0";
                $types = ''; $vals = [];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= " AND l.entry_date >= ?"; $types .= "s"; $vals[] = $from; }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where .= " AND l.entry_date <= ?"; $types .= "s"; $vals[] = $to; }

                $stmt = $conn->prepare("SELECT l.*,
                        COALESCE(c.full_name, v.vendor_name, u.name, b.account_name, '—') AS party_name
                    FROM ledger_entries l
                    LEFT JOIN customers c     ON l.party_type = 'customer' AND c.id = l.party_id
                    LEFT JOIN vendors v       ON l.party_type = 'vendor'   AND v.id = l.party_id
                    LEFT JOIN users u         ON l.party_type = 'staff'    AND u.id = l.party_id
                    LEFT JOIN bank_accounts b ON l.party_type IN ('bank','cash') AND b.id = l.party_id
                    WHERE $where
                    ORDER BY l.entry_date DESC, l.id DESC
                    LIMIT 2000");
                if ($types !== '') $stmt->bind_param($types, ...$vals);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = []; $td = 0.0; $tc = 0.0;
                while ($r = $res->fetch_assoc()) {
                    $td += (float)$r['debit']; $tc += (float)$r['credit'];
                    $rows[] = [
                        'id' => (int)$r['id'], 'date' => $r['entry_date'],
                        'party_type' => $r['party_type'], 'party_name' => $r['party_name'],
                        'particulars' => $r['particulars'], 'ref_type' => $r['ref_type'], 'ref_number' => $r['ref_number'],
                        'debit' => number_format((float)$r['debit'], 2, '.', ''),
                        'credit' => number_format((float)$r['credit'], 2, '.', ''),
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows,
                    'total_debit' => number_format($td, 2, '.', ''),
                    'total_credit' => number_format($tc, 2, '.', ''), 'currency' => $currency]);
                exit();
            }

            // cash + bank book — opening, movement, closing per account
            case 'getCashBank': {
                $from = $_GET['from'] ?? '';
                $to   = $_GET['to'] ?? '';
                $rows = [];
                $accs = @$conn->query("SELECT id, account_name, bank_name, account_type FROM bank_accounts WHERE is_deleted = 0 ORDER BY account_type ASC, account_name ASC");
                if ($accs) while ($a = $accs->fetch_assoc()) {
                    $aid  = (int)$a['id'];
                    $ptype = $a['account_type'] === 'cash' ? 'cash' : 'bank';

                    $open = 0.0;
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                        $o = $conn->prepare("SELECT COALESCE(SUM(debit - credit),0) b FROM ledger_entries
                                             WHERE is_deleted = 0 AND party_type = ? AND party_id = ? AND entry_date < ?");
                        $o->bind_param("sis", $ptype, $aid, $from);
                        $o->execute();
                        $open = (float)$o->get_result()->fetch_assoc()['b'];
                        $o->close();
                    }
                    $w = "is_deleted = 0 AND party_type = ? AND party_id = ?";
                    $t = "si"; $v = [$ptype, $aid];
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w .= " AND entry_date >= ?"; $t .= "s"; $v[] = $from; }
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $w .= " AND entry_date <= ?"; $t .= "s"; $v[] = $to; }
                    $s = $conn->prepare("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM ledger_entries WHERE $w");
                    $s->bind_param($t, ...$v);
                    $s->execute();
                    $x = $s->get_result()->fetch_assoc();
                    $s->close();

                    $in = (float)$x['d']; $out = (float)$x['c'];
                    $rows[] = [
                        'id' => $aid, 'party_type' => $ptype,
                        'name' => $a['account_name'], 'bank' => $a['bank_name'], 'type' => $a['account_type'],
                        'opening' => number_format($open, 2, '.', ''),
                        'in' => number_format($in, 2, '.', ''),
                        'out' => number_format($out, 2, '.', ''),
                        'closing' => number_format($open + $in - $out, 2, '.', ''),
                    ];
                }
                echo json_encode(['success' => true, 'data' => $rows, 'currency' => $currency]);
                exit();
            }

            // who owes us / who we owe, at a glance
            case 'getBalances': {
                $type = ($_GET['party_type'] ?? 'customer') === 'vendor' ? 'vendor' : 'customer';
                $upto = $_GET['to'] ?? '';
                $dateWhere = preg_match('/^\d{4}-\d{2}-\d{2}$/', $upto) ? " AND l.entry_date <= '" . $conn->real_escape_string($upto) . "'" : '';

                if ($type === 'customer') {
                    $scope = areaScope('c');
                    $sql = "SELECT c.id, CONCAT(c.full_name, ' (', c.customer_code, ')') nm,
                                   COALESCE(SUM(l.debit - l.credit), 0) bal,
                                   DATEDIFF(CURDATE(), MIN(CASE WHEN l.ref_type = 'invoice' AND l.debit > 0 THEN l.entry_date END)) age
                            FROM customers c
                            JOIN ledger_entries l ON l.party_type = 'customer' AND l.party_id = c.id AND l.is_deleted = 0 $dateWhere
                            WHERE c.is_deleted = 0 $scope
                            GROUP BY c.id
                            HAVING ROUND(bal, 2) <> 0
                            ORDER BY bal DESC LIMIT 1000";
                } else {
                    $sql = "SELECT v.id, CONCAT(v.vendor_name, ' (', v.vendor_code, ')') nm,
                                   COALESCE(SUM(l.debit - l.credit), 0) bal, NULL age
                            FROM vendors v
                            JOIN ledger_entries l ON l.party_type = 'vendor' AND l.party_id = v.id AND l.is_deleted = 0 $dateWhere
                            WHERE v.is_deleted = 0
                            GROUP BY v.id
                            HAVING ROUND(bal, 2) <> 0
                            ORDER BY bal ASC LIMIT 1000";
                }
                $rows = []; $tot = 0.0;
                $r = $conn->query($sql);
                while ($x = $r->fetch_assoc()) {
                    $b = (float)$x['bal']; $tot += $b;
                    $rows[] = ['id' => (int)$x['id'], 'name' => $x['nm'],
                               'balance' => number_format($b, 2, '.', ''),
                               'age' => $x['age'] !== null ? (int)$x['age'] : null];
                }
                echo json_encode(['success' => true, 'data' => $rows, 'total' => number_format($tot, 2, '.', ''), 'currency' => $currency]);
                exit();
            }

            case 'addManual': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                if (!$can_add) { echo json_encode(['success' => false, 'message' => 'You cannot add ledger entries']); exit(); }

                $type  = $_POST['party_type'] ?? '';
                $pid   = (int)($_POST['party_id'] ?? 0);
                $date  = $_POST['entry_date'] ?? '';
                $desc  = trim($_POST['particulars'] ?? '');
                $ref   = $_POST['ref_type'] ?? 'manual';
                $refNo = trim($_POST['ref_number'] ?? '');
                $side  = $_POST['side'] ?? 'debit';
                $amt   = round((float)($_POST['amount'] ?? 0), 2);
                $notes = trim($_POST['notes'] ?? '');

                if (!isset($PARTY_TYPES[$type]))                 { echo json_encode(['success' => false, 'message' => 'Invalid party type']); exit(); }
                if ($pid <= 0)                                   { echo json_encode(['success' => false, 'message' => 'Pick a party']); exit(); }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['success' => false, 'message' => 'Invalid date']); exit(); }
                if ($desc === '')                                { echo json_encode(['success' => false, 'message' => 'Particulars are required']); exit(); }
                if (mb_strlen($desc) > 255)                      { echo json_encode(['success' => false, 'message' => 'Particulars too long']); exit(); }
                if (!in_array($ref, $REF_TYPES, true))           { echo json_encode(['success' => false, 'message' => 'Manual entries must be opening, manual or transfer']); exit(); }
                if ($amt <= 0)                                   { echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']); exit(); }
                if (!in_array($side, ['debit', 'credit'], true)) { echo json_encode(['success' => false, 'message' => 'Pick debit or credit']); exit(); }

                // opening rows are keyed by party so there can only ever be one
                $refId = $ref === 'opening' ? $pid : 0;
                if ($ref !== 'opening') {
                    $mx = $conn->prepare("SELECT COALESCE(MAX(ref_id), 0) m FROM ledger_entries WHERE ref_type = ?");
                    $mx->bind_param("s", $ref);
                    $mx->execute();
                    $refId = (int)$mx->get_result()->fetch_assoc()['m'] + 1;
                    $mx->close();
                }

                $debit  = $side === 'debit'  ? $amt : 0;
                $credit = $side === 'credit' ? $amt : 0;
                $refNoBind = $refNo === '' ? strtoupper($ref) : $refNo;

                $ok = postLedger($conn, $type, $pid, $date, $desc, $debit, $credit, $ref, $refId, $refNoBind, $user_id);
                if (!$ok) { echo json_encode(['success' => false, 'message' => 'Failed to post entry']); exit(); }
                if ($notes !== '') {
                    $n = $conn->prepare("UPDATE ledger_entries SET notes = ? WHERE ref_type = ? AND ref_id = ? AND party_type = ? AND party_id = ?");
                    $n->bind_param("ssisi", $notes, $ref, $refId, $type, $pid);
                    $n->execute();
                    $n->close();
                }
                logActivity($user_id, $username, 'Ledger Entry Added', "$type #$pid — $desc (" . ($debit ?: $credit) . ")");
                echo json_encode(['success' => true, 'message' => 'Ledger entry posted']);
                exit();
            }

            // only hand-made rows can be removed — document rows follow their document
            case 'deleteEntry': {
                if (!$can_delete) { echo json_encode(['success' => false, 'message' => 'You cannot delete ledger entries']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                $g = $conn->prepare("SELECT ref_type, particulars FROM ledger_entries WHERE id = ? AND is_deleted = 0");
                $g->bind_param("i", $id);
                $g->execute();
                $r = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$r) { echo json_encode(['success' => false, 'message' => 'Entry not found']); exit(); }
                if (!in_array($r['ref_type'], $REF_TYPES, true)) {
                    echo json_encode(['success' => false, 'message' => 'This entry belongs to a document — edit or cancel that document instead']);
                    exit();
                }
                $d = $conn->prepare("UPDATE ledger_entries SET is_deleted = 1 WHERE id = ?");
                $d->bind_param("i", $id);
                $d->execute();
                $d->close();
                logActivity($user_id, $username, 'Ledger Entry Deleted', $r['particulars']);
                echo json_encode(['success' => true, 'message' => 'Entry removed']);
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Ledger.php error: " . $e->getMessage());
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
    <title>Ledger - ISP Billing</title>

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
                <span>Ledger</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-book"></i> Ledger</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="summary-grid" id="summaryGrid">
                <div class="summary-card highlight">
                    <div class="summary-card-label"><i class="fas fa-flag"></i> Opening</div>
                    <div class="summary-card-value" id="smOpening">—</div>
                    <div class="summary-card-sub">Before the selected period</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-card-label"><i class="fas fa-arrow-down"></i> Total Debit</div>
                    <div class="summary-card-value" id="smDebit">—</div>
                    <div class="summary-card-sub">Owed to us / received</div>
                </div>
                <div class="summary-card success">
                    <div class="summary-card-label"><i class="fas fa-arrow-up"></i> Total Credit</div>
                    <div class="summary-card-value" id="smCredit">—</div>
                    <div class="summary-card-sub">Owed by us / paid out</div>
                </div>
                <div class="summary-card">
                    <div class="summary-card-label"><i class="fas fa-scale-balanced"></i> Closing</div>
                    <div class="summary-card-value" id="smClosing">—</div>
                    <div class="summary-card-sub" id="smClosingSub">&nbsp;</div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-card-label"><i class="fas fa-right-left"></i> Net Movement</div>
                    <div class="summary-card-value" id="smNet">—</div>
                    <div class="summary-card-sub" id="smNetSub">Debit less credit this period</div>
                </div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-book-open"></i> <span id="sectionTitle">Party Ledger</span></h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadActive()"><i class="fas fa-sync"></i> Refresh</button>
                        <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                        <?php if ($can_add): ?>
                        <button class="btn btn-success" onclick="openManualModal()"><i class="fas fa-plus"></i> Manual Entry</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="vtab-nav" id="ledgerTabs">
                    <button type="button" class="vtab-btn active" data-tab="party"><i class="fas fa-user-tag"></i> Party Ledger</button>
                    <button type="button" class="vtab-btn" data-tab="daybook"><i class="fas fa-calendar-days"></i> Day Book</button>
                    <button type="button" class="vtab-btn" data-tab="cashbank"><i class="fas fa-wallet"></i> Cash &amp; Bank Book</button>
                    <button type="button" class="vtab-btn" data-tab="balances"><i class="fas fa-scale-unbalanced"></i> Balances</button>
                </div>

                <div class="filters-section" id="filtersSection">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Selection</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()"><i class="fas fa-times-circle"></i> Clear</button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group" id="grpPartyType">
                            <label><i class="fas fa-layer-group"></i> Party Type</label>
                            <select id="partyType">
                                <?php foreach ($PARTY_TYPES as $k => $v): if ($k === 'other') continue; ?>
                                <option value="<?php echo $k; ?>"><?php echo $v[0]; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group" id="grpParty">
                            <label><i class="fas fa-user"></i> Party</label>
                            <select id="partyId"><option value="">— Select —</option></select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Date Range</label>
                            <div class="filter-dates">
                                <input type="date" id="filterFrom" title="From">
                                <input type="date" id="filterTo" title="To">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="skeletonWrap" class="initially-hidden">
                    <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                </div>

                <div class="table-responsive" id="ledgerWrap">
                    <table class="ledger-table table-full-width" id="ledgerTable"></table>
                </div>

                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-book"></i></div>
                    <div class="empty-state-title">Nothing to show.</div>
                    <div class="empty-state-text">Pick a party, or widen the date range.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual Entry Modal -->
    <div class="modal-overlay" id="manualModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-pen-to-square"></i> Manual Ledger Entry</h3>
                <button class="close-btn" onclick="closeManualModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="manualForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Party Type *</label>
                            <select id="m_party_type" name="party_type">
                                <?php foreach ($PARTY_TYPES as $k => $v): if ($k === 'other') continue; ?>
                                <option value="<?php echo $k; ?>"><?php echo $v[0]; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Party *</label>
                            <select id="m_party_id" name="party_id"><option value="">— Select —</option></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date *</label>
                            <input type="date" id="m_entry_date" name="entry_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Entry Type *</label>
                            <select id="m_ref_type" name="ref_type">
                                <option value="opening">Opening Balance</option>
                                <option value="manual">Adjustment</option>
                                <option value="transfer">Transfer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-right-left"></i> Side *</label>
                            <select id="m_side" name="side">
                                <option value="debit">Debit (they owe us / money in)</option>
                                <option value="credit">Credit (we owe them / money out)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Amount *</label>
                            <input type="number" id="m_amount" name="amount" step="0.01" min="0.01" required>
                        </div>
                        <div class="form-group full-row">
                            <label><i class="fas fa-align-left"></i> Particulars *</label>
                            <input type="text" id="m_particulars" name="particulars" maxlength="255" required placeholder="e.g. Opening balance as at 01-Jul">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference</label>
                            <input type="text" id="m_ref_number" name="ref_number" maxlength="40">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-note-sticky"></i> Notes</label>
                            <input type="text" id="m_notes" name="notes" maxlength="255">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Post Entry</button>
                        <button type="button" class="btn btn-secondary" onclick="closeManualModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>
    <script>
    const CAN_ADD    = <?php echo $can_add ? 'true' : 'false'; ?>;
    const CAN_DELETE = <?php echo $can_delete ? 'true' : 'false'; ?>;
    const CURRENCY   = <?php echo json_encode($currency); ?>;

    let activeTab = 'party';

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function capFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
    function fmtAmt(n) { return CURRENCY + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtDate(d) {
        if (!d) return '—';
        const dt = /^\d{4}-\d{2}-\d{2}$/.test(d) ? new Date(d + 'T00:00:00') : new Date(d);
        if (isNaN(dt)) return escapeHtml(d);
        return dt.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
    }
    // +ve = Dr (owed to us / in hand), -ve = Cr (we owe)
    function fmtDrCr(n) {
        const a = Number(n || 0);
        if (Math.abs(a) < 0.005) return fmtAmt(0);
        return fmtAmt(Math.abs(a)) + ' ' + (a > 0 ? 'Dr' : 'Cr');
    }

    $(document).ready(function() {
        new SearchableDropdown({ selectId: 'partyType',    placeholder: 'Party type...', icon: 'fas fa-layer-group', allowClear: false });
        new SearchableDropdown({ selectId: 'partyId',      placeholder: '— Select —',    icon: 'fas fa-user' });
        new SearchableDropdown({ selectId: 'm_party_type', placeholder: 'Party type...', icon: 'fas fa-layer-group', allowClear: false });
        new SearchableDropdown({ selectId: 'm_party_id',   placeholder: '— Select —',    icon: 'fas fa-user' });
        new SearchableDropdown({ selectId: 'm_ref_type',   placeholder: 'Type...',       icon: 'fas fa-tag', allowClear: false });
        new SearchableDropdown({ selectId: 'm_side',       placeholder: 'Side...',       icon: 'fas fa-right-left', allowClear: false });

        $('#ledgerTabs').on('click', '.vtab-btn', function() {
            $('#ledgerTabs .vtab-btn').removeClass('active');
            $(this).addClass('active');
            activeTab = $(this).data('tab');
            onTabChange();
        });
        $('#partyType').on('change', function() { loadParties($('#partyType').val(), 'partyId', loadActive); });
        $('#partyId').on('change', loadActive);
        $('#filterFrom, #filterTo').on('change', loadActive);
        $('#m_party_type').on('change', function() { loadParties($('#m_party_type').val(), 'm_party_id'); });
        document.getElementById('manualModal').addEventListener('click', function(e) { if (e.target === this) closeManualModal(); });

        // default window: this month
        const now = new Date();
        $('#filterFrom').val(new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10));
        $('#filterTo').val(now.toISOString().slice(0, 10));

        loadParties('customer', 'partyId');
        loadParties('customer', 'm_party_id');
        onTabChange();
    });

    function onTabChange() {
        const isParty = activeTab === 'party';
        const isBal   = activeTab === 'balances';
        $('#grpParty').toggle(isParty);
        $('#grpPartyType').toggle(isParty || isBal);
        $('#sectionTitle').text({ party: 'Party Ledger', daybook: 'Day Book', cashbank: 'Cash & Bank Book', balances: 'Outstanding Balances' }[activeTab]);
        $('#summaryGrid').toggle(activeTab !== 'cashbank' && activeTab !== 'balances');
        loadActive();
    }

    function loadParties(type, targetId, after) {
        $.getJSON('?action=getParties&party_type=' + encodeURIComponent(type), function(resp) {
            const el = document.getElementById(targetId);
            el.innerHTML = '<option value="">— Select —</option>' +
                (resp.success ? resp.data.map(p => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('') : '');
            el._sd && el._sd.refresh();
            if (after) after();
        });
    }

    function clearFilters() {
        $('#filterFrom,#filterTo').val('');
        $('#partyId').val('');
        const e = document.getElementById('partyId'); e._sd && e._sd.refresh();
        loadActive();
    }

    function busy(on) {
        $('#skeletonWrap').toggleClass('initially-hidden', !on).toggle(on);
        $('#ledgerWrap').toggle(!on);
    }

    function showEmpty(msg) {
        $('#ledgerWrap').hide();
        $('#emptyState').removeClass('initially-hidden').show();
        if (msg) $('#emptyState .empty-state-title').text(msg);
    }
    function hideEmpty() { $('#emptyState').hide(); $('#ledgerWrap').show(); }

    function loadActive() {
        if (activeTab === 'party')    return loadParty();
        if (activeTab === 'daybook')  return loadDayBook();
        if (activeTab === 'cashbank') return loadCashBank();
        return loadBalances();
    }

    function setSummary(open, dr, cr, close) {
        $('#smOpening').text(fmtDrCr(open));
        $('#smDebit').text(fmtAmt(dr));
        $('#smCredit').text(fmtAmt(cr));
        $('#smClosing').text(fmtDrCr(close));
        $('#smClosingSub').text(Number(close) > 0 ? 'Receivable / in hand' : (Number(close) < 0 ? 'Payable' : 'Settled'));
        const net = Number(dr) - Number(cr);            // what the period actually moved
        $('#smNet').text(fmtDrCr(net));
        $('#smNetSub').text(net > 0 ? 'Debit heavy period' : (net < 0 ? 'Credit heavy period' : 'Debit and credit level'));
    }

    function loadParty() {
        const pid = $('#partyId').val();
        if (!pid) { setSummary(0, 0, 0, 0); showEmpty('Pick a party to see its ledger.'); return; }
        busy(true);
        const p = { party_type: $('#partyType').val(), party_id: pid, from: $('#filterFrom').val(), to: $('#filterTo').val() };
        $.getJSON('?action=getLedger', p, function(resp) {
            busy(false);
            if (!resp.success) { showEmpty(resp.message || 'Failed to load'); return; }
            hideEmpty();
            setSummary(resp.opening, resp.total_debit, resp.total_credit, resp.closing);
            const rows = resp.lines.map(l => `<tr>
                    <td>${fmtDate(l.date)}</td>
                    <td>${escapeHtml(l.ref_number || '—')}</td>
                    <td>${escapeHtml(l.particulars)}</td>
                    <td class="ledger-debit num-cell">${Number(l.debit) > 0 ? fmtAmt(l.debit) : '—'}</td>
                    <td class="ledger-credit num-cell">${Number(l.credit) > 0 ? fmtAmt(l.credit) : '—'}</td>
                    <td class="ledger-balance num-cell">${fmtDrCr(l.balance)}</td>
                    <td>${(CAN_DELETE && l.manual) ? `<button class="action-icon delete-icon" onclick="deleteEntry(${l.id})" title="Remove"><i class="fas fa-trash"></i></button>` : ''}</td>
                </tr>`).join('');
            $('#ledgerTable').html(`
                <thead><tr><th>Date</th><th>Ref</th><th>Particulars</th><th>Debit</th><th>Credit</th><th>Balance</th><th></th></tr></thead>
                <tbody>
                    <tr class="led-open"><td colspan="5">Opening Balance</td><td class="ledger-balance num-cell">${fmtDrCr(resp.opening)}</td><td></td></tr>
                    ${rows || '<tr><td colspan="7" class="muted">No entries in this period.</td></tr>'}
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" class="dt-foot-label">Totals</th>
                        <th class="dt-foot-val">${fmtAmt(resp.total_debit)}</th>
                        <th class="dt-foot-val">${fmtAmt(resp.total_credit)}</th>
                        <th class="dt-foot-val">${fmtDrCr(resp.closing)}</th>
                        <th></th>
                    </tr>
                </tfoot>`);
        }).fail(function() { busy(false); showEmpty('Connection error'); });
    }

    function loadDayBook() {
        busy(true);
        $.getJSON('?action=getDayBook', { from: $('#filterFrom').val(), to: $('#filterTo').val() }, function(resp) {
            busy(false);
            if (!resp.success) { showEmpty(resp.message || 'Failed to load'); return; }
            if (!resp.data.length) { setSummary(0, 0, 0, 0); showEmpty('No entries in this period.'); return; }
            hideEmpty();
            setSummary(0, resp.total_debit, resp.total_credit, Number(resp.total_debit) - Number(resp.total_credit));
            const rows = resp.data.map(l => `<tr>
                    <td>${fmtDate(l.date)}</td>
                    <td><span class="method-badge cash">${escapeHtml(capFirst(l.party_type))}</span></td>
                    <td>${escapeHtml(l.party_name)}</td>
                    <td>${escapeHtml(l.particulars)}</td>
                    <td>${escapeHtml(l.ref_number || '—')}</td>
                    <td class="ledger-debit num-cell">${Number(l.debit) > 0 ? fmtAmt(l.debit) : '—'}</td>
                    <td class="ledger-credit num-cell">${Number(l.credit) > 0 ? fmtAmt(l.credit) : '—'}</td>
                </tr>`).join('');
            $('#ledgerTable').html(`
                <thead><tr><th>Date</th><th>Type</th><th>Party</th><th>Particulars</th><th>Ref</th><th>Debit</th><th>Credit</th></tr></thead>
                <tbody>${rows}</tbody>
                <tfoot><tr>
                    <th colspan="5" class="dt-foot-label">Totals</th>
                    <th class="dt-foot-val">${fmtAmt(resp.total_debit)}</th>
                    <th class="dt-foot-val">${fmtAmt(resp.total_credit)}</th>
                </tr></tfoot>`);
        }).fail(function() { busy(false); showEmpty('Connection error'); });
    }

    function loadCashBank() {
        busy(true);
        $.getJSON('?action=getCashBank', { from: $('#filterFrom').val(), to: $('#filterTo').val() }, function(resp) {
            busy(false);
            if (!resp.success) { showEmpty(resp.message || 'Failed to load'); return; }
            if (!resp.data.length) { showEmpty('No cash or bank accounts defined yet.'); return; }
            hideEmpty();
            let tOpen = 0, tIn = 0, tOut = 0, tClose = 0;
            const rows = resp.data.map(a => {
                tOpen += Number(a.opening); tIn += Number(a.in); tOut += Number(a.out); tClose += Number(a.closing);
                return `<tr>
                    <td>${escapeHtml(a.name)}<div class="muted">${escapeHtml(a.bank)}</div></td>
                    <td><span class="method-badge ${a.type === 'cash' ? 'cash' : 'bank_transfer'}">${escapeHtml(capFirst(a.type))}</span></td>
                    <td class="num-cell">${fmtAmt(a.opening)}</td>
                    <td class="ledger-credit num-cell">${fmtAmt(a.in)}</td>
                    <td class="ledger-debit num-cell">${fmtAmt(a.out)}</td>
                    <td class="ledger-balance num-cell">${fmtAmt(a.closing)}</td>
                </tr>`;
            }).join('');
            $('#ledgerTable').html(`
                <thead><tr><th>Account</th><th>Type</th><th>Opening</th><th>Money In</th><th>Money Out</th><th>Closing</th></tr></thead>
                <tbody>${rows}</tbody>
                <tfoot><tr>
                    <th colspan="2" class="dt-foot-label">Totals</th>
                    <th class="dt-foot-val">${fmtAmt(tOpen)}</th>
                    <th class="dt-foot-val pos">${fmtAmt(tIn)}</th>
                    <th class="dt-foot-val neg">${fmtAmt(tOut)}</th>
                    <th class="dt-foot-val">${fmtAmt(tClose)}</th>
                </tr></tfoot>`);
        }).fail(function() { busy(false); showEmpty('Connection error'); });
    }

    function loadBalances() {
        busy(true);
        const type = $('#partyType').val() === 'vendor' ? 'vendor' : 'customer';
        $.getJSON('?action=getBalances', { party_type: type, to: $('#filterTo').val() }, function(resp) {
            busy(false);
            if (!resp.success) { showEmpty(resp.message || 'Failed to load'); return; }
            if (!resp.data.length) { showEmpty('Everything is settled.'); return; }
            hideEmpty();
            const rows = resp.data.map(b => `<tr>
                    <td>${escapeHtml(b.name)}</td>
                    <td class="ledger-balance num-cell">${fmtDrCr(b.balance)}</td>
                    <td>${b.age !== null && b.age > 0 ? `<span class="badge-days ${b.age > 60 ? 'danger' : (b.age > 30 ? 'warn' : '')}">${b.age} days</span>` : '—'}</td>
                </tr>`).join('');
            $('#ledgerTable').html(`
                <thead><tr><th>${type === 'vendor' ? 'Vendor' : 'Customer'}</th><th>Balance</th><th>Oldest Item</th></tr></thead>
                <tbody>${rows}</tbody>
                <tfoot><tr>
                    <th class="dt-foot-label">Total ${type === 'vendor' ? 'Payable' : 'Receivable'}</th>
                    <th class="dt-foot-val">${fmtDrCr(resp.total)}</th>
                    <th></th>
                </tr></tfoot>`);
        }).fail(function() { busy(false); showEmpty('Connection error'); });
    }

    function openManualModal() {
        document.getElementById('manualForm').reset();
        $('#m_entry_date').val(new Date().toISOString().slice(0, 10));
        $('#m_party_type').val($('#partyType').val() || 'customer');
        loadParties($('#m_party_type').val(), 'm_party_id');
        ['m_party_type','m_ref_type','m_side'].forEach(id => { const e = document.getElementById(id); e._sd && e._sd.refresh(); });
        document.getElementById('manualModal').classList.add('active');
    }
    function closeManualModal() { document.getElementById('manualModal').classList.remove('active'); }

    document.getElementById('manualForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=addManual', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeManualModal();
                    setTimeout(loadActive, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    });

    function deleteEntry(id) {
        Swal.fire({ icon: 'warning', title: 'Remove entry?', text: 'Only hand-made entries can be removed.',
            showCancelButton: true, confirmButtonColor: '#ea4335', confirmButtonText: 'Remove', cancelButtonText: 'Cancel' })
        .then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({ url: '?action=deleteEntry', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) { Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false }); setTimeout(loadActive, 100); }
                    else Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }
    </script>
</body>
</html>
