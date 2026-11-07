<?php
/**
 * Developed by Kashif Sohail (Kashif  Scripts)
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Automatic Monthly Bill Generator
 * --------------------------------
 * Hostinger cron entry: run daily at 00:05
 *   /usr/bin/php /home/USER/public_html/cron_auto_billing.php
 *
 * Or via curl (if cron supports HTTP):
 *   curl -s https://yourdomain.com/cron_auto_billing.php?token=AUTOBILL
 *
 * Behavior:
 *   - Reads system_settings keys: auto_billing_enabled, auto_billing_day,
 *     auto_billing_due_day, auto_billing_tax_pct, auto_billing_last_run.
 *   - Skips silently unless: enabled=1 AND today day-of-month == auto_billing_day
 *     AND auto_billing_last_run != today.
 *   - On match: runs the same logic as the manual "Generate Monthly Batch"
 *     for the current calendar month, with skip_existing=1 (dedup-safe).
 *   - Logs activity as user_id=0 / username='SYSTEM_CRON'.
 *
 * Safe to call multiple times per day (only runs once via last_run marker).
 */

require_once __DIR__ . '/config.php';

// allow CLI; if called via web, require optional token to avoid public abuse
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
    $expected = getSetting('auto_billing_token', 'AUTOBILL');
    $given    = $_GET['token'] ?? $_POST['token'] ?? '';
    if ($given !== $expected) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit();
    }
}

// helper writers (CLI: stdout, web: collected for JSON response)
$logLines = [];
function out($line) {
    global $logLines;
    $logLines[] = $line;
    if (PHP_SAPI === 'cli') echo "[" . date('Y-m-d H:i:s') . "] " . $line . PHP_EOL;
}

try {
    $conn = getDBConnection();

    // 1) settings
    $enabled    = (int)getSetting('auto_billing_enabled', '0');
    $billDay    = max(1, min(28, (int)getSetting('auto_billing_day', '1')));
    $dueDay     = max(1, min(28, (int)getSetting('auto_billing_due_day', '10')));
    $taxPct     = max(0, (float)getSetting('auto_billing_tax_pct', '0'));
    $lastRunYmd = trim((string)getSetting('auto_billing_last_run', ''));

    $todayYmd = date('Ymd');
    $todayDay = (int)date('j');
    $month    = date('Y-m');

    out("Auto-billing check — enabled=$enabled, today_day=$todayDay, run_day=$billDay, last_run=$lastRunYmd, month=$month");

    // 2) gating
    if (!$enabled) {
        out("Skipped: auto_billing_enabled = 0");
        $resp = ['success' => true, 'status' => 'skipped', 'reason' => 'disabled', 'log' => $logLines];
        echo (PHP_SAPI === 'cli') ? '' : json_encode($resp);
        exit();
    }
    if ($todayDay !== $billDay) {
        out("Skipped: today is day $todayDay, configured run day is $billDay");
        $resp = ['success' => true, 'status' => 'skipped', 'reason' => 'wrong_day', 'log' => $logLines];
        echo (PHP_SAPI === 'cli') ? '' : json_encode($resp);
        exit();
    }
    if ($lastRunYmd === $todayYmd) {
        out("Skipped: already ran today ($lastRunYmd)");
        $resp = ['success' => true, 'status' => 'skipped', 'reason' => 'already_ran_today', 'log' => $logLines];
        echo (PHP_SAPI === 'cli') ? '' : json_encode($resp);
        exit();
    }

    // 3) generate
    $issueDate = date('Y-m-d');
    $dueDate   = $month . '-' . sprintf('%02d', $dueDay);
    $perStart  = $month . '-01';
    $perEnd    = date('Y-m-t', strtotime($perStart));
    $batchId   = 'AUTO-' . date('YmdHis');

    out("Running batch $batchId: month=$month, due=$dueDate, tax={$taxPct}%");

    // eligible customers (active, no existing invoice for this month)
    $sql = "SELECT c.id, c.package_id, p.price
            FROM customers c LEFT JOIN packages p ON p.id = c.package_id
            WHERE c.is_deleted = 0 AND c.connection_status = 'active'
              AND NOT EXISTS (
                  SELECT 1 FROM invoices i
                  WHERE i.customer_id = c.id AND i.billing_month = ? AND i.is_deleted = 0
              )";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $res = $stmt->get_result();
    $eligible = [];
    while ($r = $res->fetch_assoc()) $eligible[] = $r;
    $stmt->close();

    out("Eligible customers: " . count($eligible));

    if (!count($eligible)) {
        // mark run so we don't re-check throughout the day
        setSetting('auto_billing_last_run', $todayYmd);
        setSetting('auto_billing_last_status', 'no_eligible');
        logActivity(0, 'SYSTEM_CRON', 'Auto Billing Run', "month=$month, eligible=0, skipped — all already invoiced");
        $resp = ['success' => true, 'status' => 'no_eligible', 'month' => $month, 'log' => $logLines];
        echo (PHP_SAPI === 'cli') ? '' : json_encode($resp);
        exit();
    }

    // tx wrap — atomic insert + sequence lock
    $created = 0; $failed = 0;
    $conn->begin_transaction();
    try {
        // lock invoice # sequence — blocks concurrent cron runs from racing
        $yr   = date('Y');
        $like = 'INV-' . $yr . '-%';
        $maxStmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) AS mx FROM invoices WHERE invoice_number LIKE ? FOR UPDATE");
        $maxStmt->bind_param("s", $like);
        $maxStmt->execute();
        $invSeq = (int)($maxStmt->get_result()->fetch_assoc()['mx'] ?? 0);
        $maxStmt->close();

        $ins = $conn->prepare("INSERT INTO invoices
            (invoice_number, customer_id, package_id, billing_month, amount, previous_balance, discount, tax, late_fee, total,
             due_date, issue_date, invoice_period_start, invoice_period_end, status, created_by_user_id, batch_id)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?, ?, ?, ?, 'unpaid', 0, ?)");

        $storedPrevBal = 0; // arrears stay on their own open invoices — never rolled into a new one

        foreach ($eligible as $e) {
            $cid = (int)$e['id'];
            $pid = (int)$e['package_id'];
            $amt = (float)($e['price'] ?? 0);
            if ($amt <= 0 || $pid <= 0) { $failed++; continue; }

            $taxAmt = round($amt * $taxPct / 100, 2);
            $total  = round($amt + $taxAmt, 2); // current charges only — no double-count of arrears
            $invSeq++;
            $invNum = sprintf('INV-%s-%04d', $yr, $invSeq);

            $ins->bind_param("siisddddsssss",
                $invNum, $cid, $pid, $month, $amt, $storedPrevBal, $taxAmt, $total,
                $dueDate, $issueDate, $perStart, $perEnd, $batchId
            );
            if ($ins->execute()) {
                $created++;
            } else {
                throw new Exception('Insert failed for customer ' . $cid . ': ' . $ins->error);
            }
        }
        $ins->close();

        $conn->commit();
    } catch (Throwable $txe) {
        $conn->rollback();
        // do NOT set auto_billing_last_run — allow retry on next run
        setSetting('auto_billing_last_status', 'error: ' . substr($txe->getMessage(), 0, 200));
        error_log('cron_auto_billing tx failed: ' . $txe->getMessage());
        out('ERROR: ' . $txe->getMessage());
        $resp = ['success' => false, 'message' => 'Transaction failed: ' . $txe->getMessage(), 'log' => $logLines];
        if (PHP_SAPI !== 'cli') echo json_encode($resp);
        exit(1);
    }

    out("Done: created=$created, failed=$failed");

    // success path only — mark run
    setSetting('auto_billing_last_run', $todayYmd);
    setSetting('auto_billing_last_batch_id', $batchId);
    setSetting('auto_billing_last_count', (string)$created);
    setSetting('auto_billing_last_status', 'success');
    logActivity(0, 'SYSTEM_CRON', 'Auto Billing Run',
                "Batch $batchId month=$month — created=$created, failed=$failed, due=$dueDate, tax={$taxPct}%");

    try {
        createNotificationForAdmins('Auto Billing Run',
            "$created invoices auto-generated for $month (batch $batchId).",
            'success', 'invoices.php?filter=outstanding');
    } catch (Exception $e) { /* notifications optional */ }

    $resp = [
        'success'  => true,
        'status'   => 'generated',
        'month'    => $month,
        'created'  => $created,
        'failed'   => $failed,
        'batch_id' => $batchId,
        'log'      => $logLines
    ];
    echo (PHP_SAPI === 'cli') ? '' : json_encode($resp);

} catch (Throwable $e) {
    error_log("cron_auto_billing.php error: " . $e->getMessage());
    setSetting('auto_billing_last_status', 'error: ' . $e->getMessage());
    $resp = ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'log' => $logLines];
    if (PHP_SAPI !== 'cli') echo json_encode($resp);
    exit(1);
}
