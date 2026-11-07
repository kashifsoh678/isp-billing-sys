<?php
 
require_once 'config.php';

// gate: any logged-in role (data scoped per role)
if (!isset($_SESSION['user_id']) || !checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'collections_runs';
$can_delete   = can('collections_runs', 'd');
$is_admin_lvl = isAdminLevel();
$is_collector = isCollector();
$can_verify   = in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTANT], true);
$currency     = getSetting('currency_symbol', '$');

$RUN_OUTCOMES = ['collected','promised','refused','not_home','disconnected_request','dispute','partial'];
$RUN_STATUSES = ['open','closed','reconciled','disputed'];

// receipt-style upload helper for visit photos / signatures
function handleVisitMediaUpload($file, $kind, $visit_id) {
    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($file['error'] !== UPLOAD_ERR_OK) return '';
    if ($file['size'] > 5 * 1024 * 1024) return '';
    $allowed = ['jpg','jpeg','png','webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return '';
    $dir = __DIR__ . '/uploads/visits';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = $kind . '_' . intval($visit_id) . '_' . time() . '.' . $ext;
    $dest  = $dir . '/' . $fname;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return '';
    return 'uploads/visits/' . $fname;
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            // ─── runs list (scoped) ──────────────────────────────────
            case 'getRuns': {
                $st = $_GET['status'] ?? '';
                $sql = "SELECT cr.id, cr.collector_user_id, cr.collection_date,
                               cr.opening_balance, cr.total_collected_cash, cr.total_collected_online,
                               cr.total_expenses_paid, cr.closing_balance, cr.deposited_to_bank_amount,
                               cr.deposit_date, cr.deposit_reference, cr.deposit_bank_account_id,
                               cr.status, cr.closed_at, cr.verified_by_user_id, cr.verified_at,
                               cr.discrepancy_amount, cr.discrepancy_reason,
                               cr.actual_visits_count, cr.successful_collections_count, cr.failed_visits_count,
                               cr.notes, cr.created_at,
                               COALESCE(u.name, u.username, '—') AS collector_name,
                               COALESCE(v.name, v.username, NULL) AS verifier_name,
                               b.account_name AS deposit_bank_name
                        FROM collection_runs cr
                        LEFT JOIN users u ON u.id = cr.collector_user_id
                        LEFT JOIN users v ON v.id = cr.verified_by_user_id
                        LEFT JOIN bank_accounts b ON b.id = cr.deposit_bank_account_id
                        WHERE 1=1 ";
                $types = ""; $vals = [];
                if (!$is_admin_lvl && !in_array($role, [ROLE_ACCOUNTANT], true)) {
                    // collector → only own
                    $sql .= " AND cr.collector_user_id = ? ";
                    $types .= "i"; $vals[] = $user_id;
                }
                if ($st !== '' && in_array($st, $RUN_STATUSES, true)) {
                    $sql .= " AND cr.status = ? ";
                    $types .= "s"; $vals[] = $st;
                }
                $sql .= " ORDER BY cr.collection_date DESC, cr.id DESC";
                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$vals);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'                => (int)$r['id'],
                        'collector_user_id' => (int)$r['collector_user_id'],
                        'collector_name'    => $r['collector_name'],
                        'collection_date'   => $r['collection_date'],
                        'opening_balance'   => number_format((float)$r['opening_balance'], 2, '.', ''),
                        'total_collected_cash'   => number_format((float)$r['total_collected_cash'], 2, '.', ''),
                        'total_collected_online' => number_format((float)$r['total_collected_online'], 2, '.', ''),
                        'total_expenses_paid'    => number_format((float)$r['total_expenses_paid'], 2, '.', ''),
                        'closing_balance'        => number_format((float)$r['closing_balance'], 2, '.', ''),
                        'deposited_to_bank_amount' => number_format((float)$r['deposited_to_bank_amount'], 2, '.', ''),
                        'deposit_date'      => $r['deposit_date'] ?? '',
                        'deposit_reference' => $r['deposit_reference'] ?? '',
                        'deposit_bank_account_id' => $r['deposit_bank_account_id'] !== null ? (int)$r['deposit_bank_account_id'] : 0,
                        'deposit_bank_name' => $r['deposit_bank_name'] ?? '',
                        'status'            => $r['status'],
                        'closed_at'         => $r['closed_at'] ?? '',
                        'verified_by_user_id' => $r['verified_by_user_id'] !== null ? (int)$r['verified_by_user_id'] : 0,
                        'verifier_name'     => $r['verifier_name'] ?? '',
                        'verified_at'       => $r['verified_at'] ?? '',
                        'discrepancy_amount' => number_format((float)$r['discrepancy_amount'], 2, '.', ''),
                        'discrepancy_reason' => $r['discrepancy_reason'] ?? '',
                        'actual_visits_count' => (int)$r['actual_visits_count'],
                        'successful_collections_count' => (int)$r['successful_collections_count'],
                        'failed_visits_count' => (int)$r['failed_visits_count'],
                        'notes'             => $r['notes'] ?? '',
                        'created_at'        => date('c', strtotime($r['created_at']))
                    ];
                }
                $stmt->close();
                echo json_encode(['success'=>true,'data'=>$rows,'currency'=>$currency]);
                exit();
            }

            // ─── my open run ─────────────────────────────────────────
            case 'getMyOpenRun': {
                $stmt = $conn->prepare("SELECT id FROM collection_runs WHERE collector_user_id=? AND status='open' ORDER BY collection_date DESC LIMIT 1");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                echo json_encode(['success'=>true,'open_run_id' => $r ? (int)$r['id'] : 0]);
                exit();
            }

            // ─── visits for a run ────────────────────────────────────
            case 'getVisits': {
                $runId = (int)($_GET['run_id'] ?? 0);
                if ($runId <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid run id']); exit(); }

                // gate: collectors only see own runs
                if (!$is_admin_lvl && !in_array($role, [ROLE_ACCOUNTANT], true)) {
                    $g = $conn->prepare("SELECT collector_user_id FROM collection_runs WHERE id=?");
                    $g->bind_param("i", $runId);
                    $g->execute();
                    $rg = $g->get_result()->fetch_assoc();
                    $g->close();
                    if (!$rg || (int)$rg['collector_user_id'] !== $user_id) { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }
                }

                $stmt = $conn->prepare("SELECT cv.*, COALESCE(c.full_name, '—') AS customer_name, c.phone AS customer_phone
                                        FROM collection_visits cv
                                        LEFT JOIN customers c ON c.id = cv.customer_id
                                        WHERE cv.collection_run_id = ?
                                        ORDER BY cv.id DESC");
                $stmt->bind_param("i", $runId);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'              => (int)$r['id'],
                        'customer_id'     => (int)$r['customer_id'],
                        'customer_name'   => $r['customer_name'] ?? '—',
                        'customer_phone'  => $r['customer_phone'] ?? '',
                        'visit_date'      => $r['visit_date'],
                        'visit_time'      => $r['visit_time'] ?? '',
                        'outcome'         => $r['outcome'],
                        'amount_collected' => number_format((float)$r['amount_collected'], 2, '.', ''),
                        'payment_method'  => $r['payment_method'] ?? 'cash',
                        'promised_amount' => $r['promised_amount'] !== null ? number_format((float)$r['promised_amount'], 2, '.', '') : '',
                        'promised_date'   => $r['promised_date'] ?? '',
                        'payment_id'      => $r['payment_id'] !== null ? (int)$r['payment_id'] : 0,
                        'gps_lat'         => $r['gps_lat'] ?? '',
                        'gps_lng'         => $r['gps_lng'] ?? '',
                        'photo_path'      => $r['photo_path'] ?? '',
                        'signature_path'  => $r['signature_path'] ?? '',
                        'reason_for_no_payment' => $r['reason_for_no_payment'] ?? '',
                        'notes'           => $r['notes'] ?? '',
                        'created_at'      => $r['created_at']
                    ];
                }
                $stmt->close();
                echo json_encode(['success'=>true,'data'=>$rows]);
                exit();
            }

            // ─── customer search for visit form ──────────────────────
            case 'searchCustomers': {
                $q = trim($_GET['q'] ?? '');
                if (mb_strlen($q) < 2) { echo json_encode(['success'=>true,'data'=>[]]); exit(); }
                $like = "%$q%";
                $stmt = $conn->prepare("SELECT id, name, phone, area_id FROM customers WHERE is_deleted = 0 AND (name LIKE ? OR phone LIKE ?) ORDER BY name LIMIT 25");
                $stmt->bind_param("ss", $like, $like);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = ['id'=>(int)$r['id'], 'name'=>$r['name'], 'phone'=>$r['phone'] ?? ''];
                }
                $stmt->close();
                echo json_encode(['success'=>true,'data'=>$rows]);
                exit();
            }

            // ─── start today's run ───────────────────────────────────
            case 'startRun': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $today = date('Y-m-d');

                // already an open run for today?
                $chk = $conn->prepare("SELECT id FROM collection_runs WHERE collector_user_id=? AND collection_date=?");
                $chk->bind_param("is", $user_id, $today);
                $chk->execute();
                $r = $chk->get_result()->fetch_assoc();
                $chk->close();
                if ($r) { echo json_encode(['success'=>true,'message'=>'Run already exists for today','id'=>(int)$r['id']]); exit(); }

                // opening balance = yesterday's closing if there was one
                $st = $conn->prepare("SELECT closing_balance FROM collection_runs WHERE collector_user_id=? AND collection_date < ? ORDER BY collection_date DESC LIMIT 1");
                $st->bind_param("is", $user_id, $today);
                $st->execute();
                $prev = $st->get_result()->fetch_assoc();
                $st->close();
                $opening = $prev ? (float)$prev['closing_balance'] : 0;

                $ins = $conn->prepare("INSERT INTO collection_runs (collector_user_id, collection_date, opening_balance, status) VALUES (?,?,?,'open')");
                $ins->bind_param("isd", $user_id, $today, $opening);
                if (!$ins->execute()) {
                    $err = $ins->error; $ins->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]); exit();
                }
                $newId = $ins->insert_id;
                $ins->close();
                logActivity($user_id, $username, 'Collection Run Started', "Run #$newId opened on $today, opening: $opening");
                echo json_encode(['success'=>true,'message'=>'Run started','id'=>$newId]);
                exit();
            }

            // ─── add visit ───────────────────────────────────────────
            case 'addVisit': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $runId    = (int)($_POST['run_id'] ?? 0);
                $custId   = (int)($_POST['customer_id'] ?? 0);
                $outcome  = $_POST['outcome'] ?? '';
                $amtCol   = (float)($_POST['amount_collected'] ?? 0);
                $payMethod = $_POST['payment_method'] ?? 'cash';
                $promAmt  = trim($_POST['promised_amount'] ?? '');
                $promDate = trim($_POST['promised_date'] ?? '');
                $gpsLat   = trim($_POST['gps_lat'] ?? '');
                $gpsLng   = trim($_POST['gps_lng'] ?? '');
                $reason   = trim($_POST['reason_for_no_payment'] ?? '');
                $notes    = trim($_POST['notes'] ?? '');
                $payId    = (int)($_POST['payment_id'] ?? 0);
                $visitDate = $_POST['visit_date'] ?? date('Y-m-d');
                $visitTime = $_POST['visit_time'] ?? date('H:i:s');

                if ($runId <= 0)  { echo json_encode(['success'=>false,'message'=>'Invalid run id']); exit(); }
                if ($custId <= 0) { echo json_encode(['success'=>false,'message'=>'Customer required']); exit(); }
                if (!in_array($outcome, $RUN_OUTCOMES, true)) { echo json_encode(['success'=>false,'message'=>'Invalid outcome']); exit(); }
                if (!in_array($payMethod, ['cash','online'], true)) $payMethod = 'cash';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) { echo json_encode(['success'=>false,'message'=>'Invalid visit date']); exit(); }
                if ($amtCol < 0) { echo json_encode(['success'=>false,'message'=>'Amount cannot be negative']); exit(); }
                if ($promDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $promDate)) { echo json_encode(['success'=>false,'message'=>'Invalid promised date']); exit(); }

                // verify run is open + ownership
                $g = $conn->prepare("SELECT collector_user_id, status FROM collection_runs WHERE id=?");
                $g->bind_param("i", $runId);
                $g->execute();
                $rg = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$rg) { echo json_encode(['success'=>false,'message'=>'Run not found']); exit(); }
                if ((int)$rg['collector_user_id'] !== $user_id && !$is_admin_lvl) { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }
                if ($rg['status'] !== 'open') { echo json_encode(['success'=>false,'message'=>'Run is not open']); exit(); }

                $promAmtBind = $promAmt === '' ? null : (float)$promAmt;
                $promDateBind = $promDate === '' ? null : $promDate;
                $gpsLatBind   = $gpsLat === '' ? null : (float)$gpsLat;
                $gpsLngBind   = $gpsLng === '' ? null : (float)$gpsLng;
                $reasonBind   = $reason === '' ? null : $reason;
                $notesBind    = $notes === '' ? null : $notes;
                $payIdBind    = $payId > 0 ? $payId : null;
                $vTimeBind    = $visitTime === '' ? null : $visitTime;

                $ins = $conn->prepare("INSERT INTO collection_visits
                    (collection_run_id, customer_id, collector_user_id, visit_date, visit_time, outcome,
                     amount_collected, payment_method, promised_amount, promised_date, payment_id,
                     gps_lat, gps_lng, reason_for_no_payment, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                // 15 placeholders
                // i i i s s s d s d s i d d s s
                $types = "iiisss" . "dsds" . "i" . "ddss";
                $ins->bind_param($types,
                    $runId, $custId, $user_id, $visitDate, $vTimeBind, $outcome,
                    $amtCol, $payMethod, $promAmtBind, $promDateBind,
                    $payIdBind,
                    $gpsLatBind, $gpsLngBind, $reasonBind, $notesBind);
                if (!$ins->execute()) {
                    $err = $ins->error; $ins->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]); exit();
                }
                $visitId = $ins->insert_id;
                $ins->close();

                // optional photo / signature
                if (!empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $p = handleVisitMediaUpload($_FILES['photo'], 'photo', $visitId);
                    if ($p !== '') {
                        $up = $conn->prepare("UPDATE collection_visits SET photo_path=? WHERE id=?");
                        $up->bind_param("si", $p, $visitId);
                        $up->execute(); $up->close();
                    }
                }
                if (!empty($_FILES['signature']) && ($_FILES['signature']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $s = handleVisitMediaUpload($_FILES['signature'], 'sig', $visitId);
                    if ($s !== '') {
                        $up = $conn->prepare("UPDATE collection_visits SET signature_path=? WHERE id=?");
                        $up->bind_param("si", $s, $visitId);
                        $up->execute(); $up->close();
                    }
                }

                // refresh run totals
                refreshRunTotals($conn, $runId);

                logActivity($user_id, $username, 'Collection Visit Added', "Run #$runId — customer $custId — outcome $outcome — amt $amtCol");
                echo json_encode(['success'=>true,'message'=>'Visit added','id'=>$visitId]);
                exit();
            }

            // ─── close run ───────────────────────────────────────────
            case 'closeRun': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $runId = (int)($_POST['run_id'] ?? 0);
                $depAmt = (float)($_POST['deposited_amount'] ?? 0);
                $depBank = (int)($_POST['deposit_bank_account_id'] ?? 0);
                $depRef  = trim($_POST['deposit_reference'] ?? '');
                $notes   = trim($_POST['notes'] ?? '');
                if ($runId <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid run id']); exit(); }

                $g = $conn->prepare("SELECT collector_user_id, status, opening_balance FROM collection_runs WHERE id=?");
                $g->bind_param("i", $runId);
                $g->execute();
                $rg = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$rg) { echo json_encode(['success'=>false,'message'=>'Run not found']); exit(); }
                if ((int)$rg['collector_user_id'] !== $user_id && !$is_admin_lvl) { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }
                if ($rg['status'] !== 'open') { echo json_encode(['success'=>false,'message'=>'Run is not open']); exit(); }

                refreshRunTotals($conn, $runId); // make sure totals are current

                // recompute closing
                $r = $conn->prepare("SELECT opening_balance, total_collected_cash, total_collected_online, total_expenses_paid FROM collection_runs WHERE id=?");
                $r->bind_param("i", $runId);
                $r->execute();
                $row = $r->get_result()->fetch_assoc();
                $r->close();
                $closing = (float)$row['opening_balance'] + (float)$row['total_collected_cash'] - (float)$row['total_expenses_paid'];
                $discrepancy = round($closing - $depAmt, 2);
                $today = date('Y-m-d');
                $closedAt = date('Y-m-d H:i:s');
                $depBankBind = $depBank > 0 ? $depBank : null;
                $depRefBind  = $depRef === '' ? null : $depRef;
                $notesBind   = $notes === '' ? null : $notes;

                $up = $conn->prepare("UPDATE collection_runs SET
                    closing_balance=?, deposited_to_bank_amount=?, deposit_date=?, deposit_reference=?, deposit_bank_account_id=?,
                    discrepancy_amount=?, status='closed', closed_at=?, notes=COALESCE(?, notes)
                    WHERE id=?");
                // 9 placeholders
                // d d s s i d s s i
                $types = "ddssidssi";
                $up->bind_param($types, $closing, $depAmt, $today, $depRefBind, $depBankBind, $discrepancy, $closedAt, $notesBind, $runId);
                if (!$up->execute()) {
                    $err = $up->error; $up->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]); exit();
                }
                $up->close();
                logActivity($user_id, $username, 'Collection Run Closed', "Run #$runId closed. closing=$closing deposited=$depAmt discrepancy=$discrepancy");
                echo json_encode(['success'=>true,'message'=>'Run closed','closing'=>$closing,'discrepancy'=>$discrepancy]);
                exit();
            }

            // ─── verify run (admin/accountant) ───────────────────────
            case 'verifyRun': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!$can_verify) { echo json_encode(['success'=>false,'message'=>'Only admin/accountant can verify']); exit(); }
                $runId = (int)($_POST['run_id'] ?? 0);
                if ($runId <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid run id']); exit(); }

                $g = $conn->prepare("SELECT status FROM collection_runs WHERE id=?");
                $g->bind_param("i", $runId);
                $g->execute();
                $rg = $g->get_result()->fetch_assoc();
                $g->close();
                if (!$rg) { echo json_encode(['success'=>false,'message'=>'Run not found']); exit(); }
                if ($rg['status'] === 'reconciled') { echo json_encode(['success'=>false,'message'=>'Already reconciled']); exit(); }
                if ($rg['status'] === 'open') { echo json_encode(['success'=>false,'message'=>'Run must be closed first']); exit(); }

                $now = date('Y-m-d H:i:s');
                $up = $conn->prepare("UPDATE collection_runs SET verified_by_user_id=?, verified_at=?, status='reconciled' WHERE id=?");
                $up->bind_param("isi", $user_id, $now, $runId);
                if (!$up->execute()) {
                    $err = $up->error; $up->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]); exit();
                }
                $up->close();
                logActivity($user_id, $username, 'Collection Run Verified', "Run #$runId reconciled");
                echo json_encode(['success'=>true,'message'=>'Run reconciled']);
                exit();
            }

            // ─── dispute run ─────────────────────────────────────────
            case 'disputeRun': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!$can_verify) { echo json_encode(['success'=>false,'message'=>'Only admin/accountant can dispute']); exit(); }
                $runId  = (int)($_POST['run_id'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                if ($runId <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid run id']); exit(); }
                if ($reason === '') { echo json_encode(['success'=>false,'message'=>'Reason required']); exit(); }
                if (mb_strlen($reason) > 255) { echo json_encode(['success'=>false,'message'=>'Reason too long']); exit(); }

                $up = $conn->prepare("UPDATE collection_runs SET status='disputed', discrepancy_reason=? WHERE id=?");
                $up->bind_param("si", $reason, $runId);
                if (!$up->execute()) {
                    $err = $up->error; $up->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]); exit();
                }
                $up->close();
                logActivity($user_id, $username, 'Collection Run Disputed', "Run #$runId disputed: $reason");
                echo json_encode(['success'=>true,'message'=>'Run flagged disputed']);
                exit();
            }

            // ─── bank list (deposit) ─────────────────────────────────
            case 'getBankAccountsList': {
                $rows = [];
                $res = $conn->query("SELECT id, account_name, bank_name, account_number FROM bank_accounts WHERE is_deleted=0 AND status='active' ORDER BY is_default DESC, account_name");
                if ($res) while ($r = $res->fetch_assoc()) {
                    $rows[] = ['id'=>(int)$r['id'],'name'=>$r['account_name'],'bank'=>$r['bank_name'],'number'=>$r['account_number']];
                }
                echo json_encode(['success'=>true,'data'=>$rows]); exit();
            }

            default:
                echo json_encode(['success'=>false,'message'=>'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("CollectionsRuns.php error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// helper: refresh totals on a run from its visits
function refreshRunTotals($conn, $runId) {
    // split cash vs online so closing-balance reconciliation only counts physical cash
    $sql = "SELECT
              COALESCE(SUM(CASE WHEN payment_method = 'cash'   THEN amount_collected ELSE 0 END),0) AS cash_total,
              COALESCE(SUM(CASE WHEN payment_method = 'online' THEN amount_collected ELSE 0 END),0) AS online_total,
              COUNT(*) AS visits,
              SUM(CASE WHEN outcome IN ('collected','partial') AND amount_collected > 0 THEN 1 ELSE 0 END) AS ok,
              SUM(CASE WHEN outcome NOT IN ('collected','partial') OR amount_collected = 0 THEN 1 ELSE 0 END) AS bad
            FROM collection_visits WHERE collection_run_id = ?";
    $st = $conn->prepare($sql);
    $st->bind_param("i", $runId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$r) return;
    $cashTotal   = (float)$r['cash_total'];
    $onlineTotal = (float)$r['online_total'];
    $visits      = (int)$r['visits'];
    $ok          = (int)$r['ok'];
    $bad         = (int)$r['bad'];

    $up = $conn->prepare("UPDATE collection_runs SET total_collected_cash=?, total_collected_online=?, actual_visits_count=?, successful_collections_count=?, failed_visits_count=? WHERE id=?");
    $up->bind_param("ddiiii", $cashTotal, $onlineTotal, $visits, $ok, $bad, $runId);
    $up->execute();
    $up->close();
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Collection Runs - ISP Billing</title>

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
                <span>Collection Runs</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-route"></i> Collection Runs</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- today's open run card -->
            <div class="data-section" id="openRunWrap" style="margin-bottom:18px">
                <div class="section-header">
                    <h2><i class="fas fa-flag"></i> My Open Run (Today)</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-success" id="btnStartRun" onclick="startRun()" style="display:none">
                            <i class="fas fa-play"></i> Start Today's Run
                        </button>
                    </div>
                </div>
                <div id="openRunBody"><div class="muted" style="padding:10px">Checking...</div></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Run History</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadRuns()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- chevron pipeline (status) -->
                <div class="chevron-pipeline initially-hidden" id="chevronPipeline" role="tablist" aria-label="Filter runs by status">
                    <div class="chevron-tab cv-all active"     data-status="all"        role="tab" tabindex="0">
                        <i class="fas fa-list"></i> All <span class="cv-count" id="cv-c-all">0</span>
                    </div>
                    <div class="chevron-tab cv-partial"        data-status="open"       role="tab" tabindex="0">
                        <i class="fas fa-flag"></i> Open <span class="cv-count" id="cv-c-open">0</span>
                    </div>
                    <div class="chevron-tab cv-paid"           data-status="closed"     role="tab" tabindex="0">
                        <i class="fas fa-check"></i> Closed <span class="cv-count" id="cv-c-closed">0</span>
                    </div>
                    <div class="chevron-tab cv-active"         data-status="reconciled" role="tab" tabindex="0">
                        <i class="fas fa-circle-check"></i> Reconciled <span class="cv-count" id="cv-c-reconciled">0</span>
                    </div>
                    <div class="chevron-tab cv-overdue"        data-status="disputed"   role="tab" tabindex="0">
                        <i class="fas fa-exclamation-triangle"></i> Disputed <span class="cv-count" id="cv-c-disputed">0</span>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap" class="table-responsive">
                    <table class="display table-full-width">
                        <thead>
                            <tr>
                                <th>Date</th><th>Collector</th><th>Visits</th><th>Cash</th>
                                <th>Closing</th><th>Deposited</th><th>Discrepancy</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 0; $i < 4; $i++): ?>
                            <tr>
                                <?php for ($j = 0; $j < 9; $j++): ?>
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
                    <table id="runsTable" class="display table-full-width"></table>
                </div>

                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-route"></i></div>
                    <div class="empty-state-title">No collection runs yet.</div>
                    <div class="empty-state-text">Start your first daily run to begin tracking.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Visit Modal -->
    <div class="modal-overlay" id="visitModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Add Visit</h3>
                <button class="close-btn" onclick="closeVisitModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="visitForm" enctype="multipart/form-data">
                    <input type="hidden" id="visitRunId" name="run_id">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-user"></i> Customer *</label>
                            <select id="visitCustomer" name="customer_id" required>
                                <option value="">Type to search customer...</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Visit Date *</label>
                            <input type="date" id="visit_date" name="visit_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> Visit Time</label>
                            <input type="time" id="visit_time" name="visit_time">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag"></i> Outcome *</label>
                            <select id="outcome" name="outcome" required>
                                <?php foreach ($RUN_OUTCOMES as $o): ?>
                                    <option value="<?php echo $o; ?>"><?php echo ucwords(str_replace('_',' ',$o)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Amount Collected (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="amount_collected" name="amount_collected" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-wallet"></i> Payment Method</label>
                            <select id="payment_method" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="online">Online</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-handshake"></i> Promised Amount</label>
                            <input type="number" id="promised_amount" name="promised_amount" step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Promised Date</label>
                            <input type="date" id="promised_date" name="promised_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> GPS Lat</label>
                            <input type="text" id="gps_lat" name="gps_lat" placeholder="e.g. 31.5204">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> GPS Lng</label>
                            <input type="text" id="gps_lng" name="gps_lng" placeholder="e.g. 74.3587">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-question-circle"></i> Reason (if no payment)</label>
                            <input type="text" id="reason_for_no_payment" name="reason_for_no_payment" maxlength="255">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-camera"></i> Photo</label>
                            <input type="file" id="photo" name="photo" accept="image/*">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-signature"></i> Signature</label>
                            <input type="file" id="signature" name="signature" accept="image/*">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-sticky-note"></i> Notes</label>
                            <textarea id="visit_notes" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="useGeolocation()"><i class="fas fa-location-arrow"></i> Use My Location</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Visit</button>
                        <button type="button" class="btn btn-secondary" onclick="closeVisitModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Close Run Modal -->
    <div class="modal-overlay" id="closeModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-lock"></i> Close Run</h3>
                <button class="close-btn" onclick="closeCloseModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="closeForm">
                    <input type="hidden" id="closeRunId">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-money-check-alt"></i> Deposited Amount *</label>
                            <input type="number" id="deposited_amount" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Deposit Bank</label>
                            <select id="deposit_bank_account_id">
                                <option value="">— None —</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-hashtag"></i> Deposit Reference</label>
                            <input type="text" id="deposit_reference" maxlength="100" placeholder="Slip / txn #">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-sticky-note"></i> Notes</label>
                            <textarea id="close_notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-lock"></i> Close & Reconcile</button>
                        <button type="button" class="btn btn-secondary" onclick="closeCloseModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Visits Detail Modal -->
    <div class="modal-overlay" id="visitsModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-list"></i> Visits Detail</h3>
                <button class="close-btn" onclick="closeVisitsModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="visitsModalBody"></div>
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
    let runsTable;
    let runsData = [];
    let bankAccountsList = [];
    const CAN_DELETE  = <?php echo $can_delete ? 'true' : 'false'; ?>;
    const CAN_VERIFY  = <?php echo $can_verify ? 'true' : 'false'; ?>;
    const IS_COLLECTOR = <?php echo $is_collector ? 'true' : 'false'; ?>;
    const IS_ADMIN_LVL = <?php echo $is_admin_lvl ? 'true' : 'false'; ?>;
    const SESSION_USER_ID = <?php echo (int)$user_id; ?>;
    const CURRENCY = <?php echo json_encode($currency); ?>;
    let chevronStatus = 'all';
    let myOpenRunId = 0;

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function fmtAmt(n) { return CURRENCY + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function capFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
    function fmtDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso + (iso.length === 10 ? 'T00:00:00' : ''));
        if (isNaN(d)) return iso;
        return d.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' });
    }

    $(document).ready(function() {
        new SearchableDropdown({ selectId: 'outcome',                  placeholder: 'Outcome...', icon: 'fas fa-flag', allowClear: false });
        new SearchableDropdown({ selectId: 'payment_method',           placeholder: 'Payment method...', icon: 'fas fa-wallet', allowClear: false });
        new SearchableDropdown({ selectId: 'visitCustomer',            placeholder: 'Search customer...', icon: 'fas fa-user', searchUrl: '?action=searchCustomers', searchUrlField: 'q', searchMin: 2 });
        new SearchableDropdown({ selectId: 'deposit_bank_account_id',  placeholder: 'Select bank a/c...', icon: 'fas fa-university' });

        $(document).on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('.chevron-tab').removeClass('active');
            $(this).addClass('active');
            chevronStatus = $(this).data('status') || 'all';
            applyFilters();
        });

        loadBankList().then(() => {
            loadOpenRun();
            loadRuns();
        });
    });

    function loadBankList() {
        return $.getJSON('?action=getBankAccountsList').then(function(resp) {
            if (!resp.success) return;
            bankAccountsList = resp.data || [];
            const sel = document.getElementById('deposit_bank_account_id');
            sel.innerHTML = '<option value="">— None —</option>' + bankAccountsList.map(b =>
                `<option value="${b.id}">${escapeHtml(b.name)} — ${escapeHtml(b.bank)} (${escapeHtml(b.number)})</option>`
            ).join('');
            sel._sd && sel._sd.refresh();
        });
    }

    function loadOpenRun() {
        $.getJSON('?action=getMyOpenRun', function(resp) {
            if (!resp.success) return;
            myOpenRunId = resp.open_run_id || 0;
            const wrap = document.getElementById('openRunBody');
            const startBtn = document.getElementById('btnStartRun');
            if (myOpenRunId) {
                startBtn.style.display = 'none';
                renderOpenRun();
            } else {
                startBtn.style.display = '';
                wrap.innerHTML = '<div class="muted" style="padding:10px">No open run for today. Click "Start Today\'s Run" to begin.</div>';
            }
        });
    }

    function renderOpenRun() {
        if (!myOpenRunId) return;
        // pull the open run details from runsData if loaded; else fetch fresh
        const r = runsData.find(x => x.id === myOpenRunId);
        const wrap = document.getElementById('openRunBody');
        if (!r) { wrap.innerHTML = '<div class="muted" style="padding:10px">Loading run #' + myOpenRunId + '...</div>'; return; }
        wrap.innerHTML = `
            <div class="form-grid">
                <div class="form-group"><label><i class="fas fa-calendar"></i> Date</label><div>${fmtDate(r.collection_date)}</div></div>
                <div class="form-group"><label><i class="fas fa-money-bill"></i> Opening</label><div class="num-cell">${fmtAmt(r.opening_balance)}</div></div>
                <div class="form-group"><label><i class="fas fa-coins"></i> Cash Collected</label><div class="num-cell">${fmtAmt(r.total_collected_cash)}</div></div>
                <div class="form-group"><label><i class="fas fa-list"></i> Visits</label><div>${r.actual_visits_count} (${r.successful_collections_count} ok / ${r.failed_visits_count} bad)</div></div>
            </div>
            <div class="form-actions">
                <button class="btn btn-success" onclick="openVisitModal(${r.id})"><i class="fas fa-plus"></i> Add Visit</button>
                <button class="btn btn-warning" onclick="viewVisits(${r.id})"><i class="fas fa-list"></i> View Visits</button>
                <button class="btn btn-primary" onclick="openCloseModal(${r.id})"><i class="fas fa-lock"></i> Close Run</button>
            </div>
        `;
    }

    function startRun() {
        Swal.fire({
            icon: 'question', title: 'Start today\'s run?',
            showCancelButton: true, confirmButtonText: 'Start', cancelButtonText: 'Cancel'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData();
            $.ajax({
                url: '?action=startRun', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        myOpenRunId = resp.id;
                        setTimeout(() => { loadRuns(); loadOpenRun(); }, 100);
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
            });
        });
    }

    function refreshChevronCounts(rows) {
        const c = { all: rows.length, open: 0, closed: 0, reconciled: 0, disputed: 0 };
        rows.forEach(r => { if (r.status in c) c[r.status]++; });
        Object.keys(c).forEach(k => {
            const el = document.getElementById('cv-c-' + k);
            if (el) el.textContent = c[k];
        });
    }

    function loadRuns() {
        $('#skeletonWrap').show();
        $('#tableWrap').hide();
        $('#emptyState').hide();
        $.ajax({
            url: '?action=getRuns', method: 'GET', dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    runsData = resp.data || [];
                    refreshChevronCounts(runsData);
                    $('#chevronPipeline').removeClass('initially-hidden').css('display', '');
                    $('#skeletonWrap').hide();
                    if (!runsData.length) {
                        $('#tableWrap').hide();
                        $('#emptyState').show();
                        renderOpenRun();
                        return;
                    }
                    $('#emptyState').hide();
                    $('#tableWrap').show();
                    initTable(runsData);
                    if (chevronStatus !== 'all') setTimeout(applyFilters, 120);
                    renderOpenRun();
                } else {
                    $('#skeletonWrap').hide();
                    Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed to load' });
                }
            },
            error: function() {
                $('#skeletonWrap').hide();
                Swal.fire({ icon:'error', title:'Connection Error', text:'Could not connect.' });
            }
        });
    }

    function statusPill(s) {
        const map = {
            open: '#0074D9', closed: '#16a34a', reconciled: '#001f3f', disputed: '#dc2626'
        };
        return `<span class="status-pill" style="background:${map[s]||'#6b7280'};color:#fff">${capFirst(s)}</span>`;
    }

    function initTable(data) {
        if (runsTable) { runsTable.destroy(); $('#runsTable').empty(); }
        setTimeout(() => {
            runsTable = $('#runsTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'collection_date', title: 'Date', render: d => fmtDate(d) },
                    { data: 'collector_name',  title: 'Collector', render: d => escapeHtml(d) },
                    {
                        data: 'actual_visits_count', title: 'Visits',
                        render: function(d, t, row) { return `${d} <small class="muted">(${row.successful_collections_count}/${row.failed_visits_count})</small>`; }
                    },
                    { data: 'total_collected_cash', title: 'Cash', className:'num-cell', render: d => fmtAmt(d) },
                    { data: 'closing_balance',      title: 'Closing', className:'num-cell', render: d => fmtAmt(d) },
                    { data: 'deposited_to_bank_amount', title: 'Deposited', className:'num-cell', render: d => Number(d) > 0 ? fmtAmt(d) : '<span class="muted">—</span>' },
                    {
                        data: 'discrepancy_amount', title: 'Discrepancy', className:'num-cell',
                        render: function(d) {
                            const v = parseFloat(d || 0);
                            if (v === 0) return '<span class="muted">—</span>';
                            const c = v > 0 ? '#dc2626' : '#f59e0b';
                            return `<span style="color:${c};font-weight:600">${fmtAmt(v)}</span>`;
                        }
                    },
                    { data: 'status', title: 'Status', render: d => statusPill(d) },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(d, t, row) {
                            let html = `<button class="action-icon view-icon" onclick="viewVisits(${row.id})" title="View Visits"><i class="fas fa-list"></i></button>`;
                            const isOpen = row.status === 'open' && (row.collector_user_id === SESSION_USER_ID || IS_ADMIN_LVL);
                            if (isOpen) {
                                html += ` <button class="action-icon" style="color:#16a34a" onclick="openVisitModal(${row.id})" title="Add Visit"><i class="fas fa-plus"></i></button>`;
                                html += ` <button class="action-icon edit-icon" onclick="openCloseModal(${row.id})" title="Close Run"><i class="fas fa-lock"></i></button>`;
                            }
                            // ⋮ More — verify / dispute (admin/accountant, closed runs)
                            if (CAN_VERIFY && row.status === 'closed') {
                                html += ` <div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">`
                                      + `<button class="act-more-item" onclick="verifyRun(${row.id})"><i class="fas fa-stamp"></i> Verify / Reconcile</button>`
                                      + `<button class="act-more-item danger" onclick="disputeRun(${row.id})"><i class="fas fa-exclamation-triangle"></i> Dispute</button>`
                                      + `</div></div>`;
                            }
                            return html;
                        }
                    }
                ],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
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
                order: [[0, 'desc']]
            });
        }, 100);
    }

    function applyFilters() {
        if (!runsTable) return;
        $.fn.dataTable.ext.search = [];
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const r = runsData[dataIndex];
            if (!r) return true;
            if (chevronStatus !== 'all' && r.status !== chevronStatus) return false;
            return true;
        });
        runsTable.draw();
    }

    function openVisitModal(runId) {
        document.getElementById('visitForm').reset();
        document.getElementById('visitRunId').value = runId;
        document.getElementById('visit_date').value = new Date().toISOString().slice(0,10);
        document.getElementById('visit_time').value = new Date().toTimeString().slice(0,5);
        const oc = document.getElementById('outcome'); oc.value = 'collected'; oc._sd && oc._sd.refresh();
        const pm = document.getElementById('payment_method'); pm.value = 'cash'; pm._sd && pm._sd.refresh();
        const cust = document.getElementById('visitCustomer'); cust.innerHTML = '<option value="">Type to search customer...</option>'; cust._sd && cust._sd.refresh();
        document.getElementById('visitModal').classList.add('active');
    }
    function closeVisitModal() {
        document.getElementById('visitModal').classList.remove('active');
        document.getElementById('visitForm').reset();
    }
    document.getElementById('visitModal').addEventListener('click', function(e) {
        if (e.target === this) closeVisitModal();
    });

    function useGeolocation() {
        if (!navigator.geolocation) { Swal.fire({ icon:'warning', title:'GPS not available' }); return; }
        navigator.geolocation.getCurrentPosition(function(pos) {
            document.getElementById('gps_lat').value = pos.coords.latitude.toFixed(6);
            document.getElementById('gps_lng').value = pos.coords.longitude.toFixed(6);
            Swal.fire({ icon:'success', title:'Location captured', timer: 1200, showConfirmButton: false });
        }, function(err) {
            // 1 denied, 2 position unavailable, 3 timeout — https is required off localhost
            const why = err.code === 1 ? 'Location permission is blocked. Allow it for this site in the browser address bar, then try again.'
                      : err.code === 3 ? 'The GPS did not answer in time. Move somewhere with a clearer sky view and try again.'
                      : (location.protocol === 'https:' || location.hostname === 'localhost'
                         ? 'Your device could not fix a position right now.'
                         : 'GPS needs a secure (https) connection on this device.');
            Swal.fire({ icon:'error', title:'Could not get location', text: why });
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }

    document.getElementById('visitForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=addVisit', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                    closeVisitModal();
                    setTimeout(() => { loadRuns(); }, 100);
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
        });
    });

    function openCloseModal(runId) {
        document.getElementById('closeForm').reset();
        document.getElementById('closeRunId').value = runId;
        const ba = document.getElementById('deposit_bank_account_id'); ba.value = ''; ba._sd && ba._sd.refresh();
        document.getElementById('closeModal').classList.add('active');
    }
    function closeCloseModal() {
        document.getElementById('closeModal').classList.remove('active');
    }
    document.getElementById('closeModal').addEventListener('click', function(e) {
        if (e.target === this) closeCloseModal();
    });

    document.getElementById('closeForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('run_id', document.getElementById('closeRunId').value);
        fd.append('deposited_amount', document.getElementById('deposited_amount').value || 0);
        fd.append('deposit_bank_account_id', document.getElementById('deposit_bank_account_id').value || 0);
        fd.append('deposit_reference', document.getElementById('deposit_reference').value || '');
        fd.append('notes', document.getElementById('close_notes').value || '');
        Swal.fire({ title:'Closing...', allowOutsideClick:false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=closeRun', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon:'success', title:'Run closed', html: `Closing: <b>${fmtAmt(resp.closing)}</b><br>Discrepancy: <b>${fmtAmt(resp.discrepancy)}</b>`, timer: 2400, showConfirmButton: false });
                    closeCloseModal();
                    myOpenRunId = 0;
                    setTimeout(() => { loadRuns(); loadOpenRun(); }, 100);
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
        });
    });

    function viewVisits(runId) {
        $.getJSON('?action=getVisits&run_id=' + runId, function(resp) {
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message }); return; }
            const data = resp.data || [];
            let body = '';
            if (!data.length) {
                body = '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-list"></i></div><div class="empty-state-title">No visits yet</div></div>';
            } else {
                body = '<div class="table-responsive"><table class="display table-full-width"><thead><tr><th>Customer</th><th>Date</th><th>Outcome</th><th>Amount</th><th>Method</th><th>Promised</th><th>Photo</th><th>Notes</th></tr></thead><tbody>';
                data.forEach(v => {
                    const photo = v.photo_path ? `<a href="${escapeHtml(v.photo_path)}" target="_blank"><i class="fas fa-camera"></i></a>` : '<span class="muted">—</span>';
                    const prom = v.promised_amount ? fmtAmt(v.promised_amount) + (v.promised_date ? ' @ ' + fmtDate(v.promised_date) : '') : '<span class="muted">—</span>';
                    const pm = (v.payment_method === 'online') ? 'Online' : 'Cash';
                    body += `<tr>
                        <td>${escapeHtml(v.customer_name)}<br><small class="muted">${escapeHtml(v.customer_phone||'')}</small></td>
                        <td>${fmtDate(v.visit_date)} ${escapeHtml(v.visit_time||'')}</td>
                        <td><span class="cat-badge ${escapeHtml(v.outcome)}">${escapeHtml(v.outcome.replace(/_/g,' '))}</span></td>
                        <td class="num-cell">${fmtAmt(v.amount_collected)}</td>
                        <td>${pm}</td>
                        <td>${prom}</td>
                        <td>${photo}</td>
                        <td>${escapeHtml(v.notes||'')}</td>
                    </tr>`;
                });
                body += '</tbody></table></div>';
            }
            document.getElementById('visitsModalBody').innerHTML = body;
            document.getElementById('visitsModal').classList.add('active');
        });
    }
    function closeVisitsModal() {
        document.getElementById('visitsModal').classList.remove('active');
    }
    document.getElementById('visitsModal').addEventListener('click', function(e) {
        if (e.target === this) closeVisitsModal();
    });

    function verifyRun(runId) {
        Swal.fire({
            icon: 'question', title: 'Verify Run?', text: 'Mark this run as reconciled.',
            showCancelButton: true, confirmButtonText: 'Verify', cancelButtonText: 'Cancel'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('run_id', runId);
            $.ajax({
                url: '?action=verifyRun', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(loadRuns, 100);
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
            });
        });
    }

    function disputeRun(runId) {
        Swal.fire({
            icon: 'warning', title: 'Dispute Run', input: 'textarea',
            inputLabel: 'Reason',
            inputPlaceholder: 'Why is this being disputed?',
            inputAttributes: { 'maxlength': 255 },
            showCancelButton: true, confirmButtonColor: '#dc2626',
            confirmButtonText: 'Dispute', cancelButtonText: 'Cancel',
            inputValidator: v => !v || !v.trim() ? 'Reason required' : undefined
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('run_id', runId); fd.append('reason', (r.value||'').trim());
            $.ajax({
                url: '?action=disputeRun', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(loadRuns, 100);
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
            });
        });
    }
    </script>
</body>
</html>
