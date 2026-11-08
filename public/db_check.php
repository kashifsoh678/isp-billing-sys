<?php
 

require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || !isSuperAdmin()) {
    http_response_code(403);
    exit('Forbidden — super_admin only');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { exit('DB err: ' . htmlspecialchars($conn->connect_error)); }

$expected_cols = [
    'customers' => ['pppoe_username','pppoe_password','connection_medium','nas_id','static_ip_address','outstanding_balance','customer_type','business_name','ntn_number','dob','gender','salesperson_user_id','agreement_signed','agreement_pdf_path','disconnection_date','vip_status','customer_rating','portal_pin_hash','olt_id','ont_serial','signal_strength_dbm','mac_binding','tax_exempt','credit_limit','auto_pay_enabled','whatsapp_number','installation_user_id','contract_period_months','early_termination_fee','referred_by_customer_id','oldest_overdue_days','last_payment_amount','write_off_status','legal_action_status'],
    'packages'  => ['package_code','tax_pct','tax_inclusive','late_fee_amount','late_fee_grace_days','bandwidth_profile_name','night_speed_mbps','valid_from','valid_until','is_promotional','promotional_price','quota_gb','equipment_included','target_segment','commission_pct_for_sales'],
    'areas'     => ['area_code','country','province','district','pin_code','gps_lat','gps_lng','coverage_status','coverage_technology','total_capacity_subscribers','manager_user_id','default_collector_user_id','pop_id','competitor_presence'],
    'invoices'  => ['subtotal','discount_amount','discount_pct','additional_charges','equipment_charges','installation_charges','package_id_at_time','package_name_at_time','package_speed_at_time','package_price_at_time','tax_pct','wht_amount','wht_pct','total_paid','total_due','paid_date','currency','invoice_type','parent_invoice_id','is_credit_note'],
    'payments'  => ['bank_account_id','payer_name','payer_phone','payer_cnic','verified_by_user_id','verified_at','bounce_reason','bounce_charges','refund_date','refund_reason','original_payment_id','is_advance','currency','whatsapp_sent','email_sent','sms_sent'],
    'complaints'=> ['complaint_source','severity','is_mass_outage','parent_complaint_id','area_id_affected','first_response_at','assigned_at','acknowledged_at','arrived_onsite_at','work_started_at','work_completed_at','resolution_category','resolution_summary','cost_to_resolve','chargeable_to_customer','customer_invoice_id','reopen_count','closed_at','customer_satisfaction_rating','customer_feedback_text','escalation_level','sla_breach_flag','tags'],
    'expenses'  => ['expense_number','expense_date','due_date','paid_date','payment_status','paid_amount','outstanding_amount','vendor_id','vendor_invoice_number','withholding_tax_amount','wht_pct','net_paid_amount','gl_account_code','is_capex','bank_account_id','next_recurrence_date','rejection_reason'],
    'leads'     => ['lead_code','customer_type','business_name','current_provider','feasibility_status','gps_lat','gps_lng','survey_required','installation_distance_meters','quote_pdf_path','lead_score','temperature','disqualification_reason','whatsapp_consent','email_consent'],
    'equipment' => ['asset_tag','imei','firmware_version','warranty_start_date','warranty_end_date','depreciation_method','depreciation_pct_per_year','current_book_value','is_rented','is_sold','is_loaned','assigned_user_id','area_id','parent_equipment_id','last_maintenance_date','equipment_condition','decommissioned_date','disposed_date','gps_lat','gps_lng','is_critical','monitoring_enabled','photos_json','item_id','movement_id','vendor_id'],
    'expense_payments' => ['bank_account_id','updated_at'],
    'users'     => ['employee_code','cnic','dob','gender','marital_status','joining_date','department','designation','reporting_to_user_id','salary_basic','salary_total','ntn','eobi_number','commission_eligible','vehicle_assigned','laptop_assigned','two_factor_enabled','signature_image_path','permanent_address','emergency_contact_name','blood_group','qualification']
];

$expected_tables = ['vendors','bank_accounts','collection_runs','collection_visits',
                    'roles','role_permissions','user_areas',
                    'stock_categories','stock_items','stock_movements','stock_movement_items',
                    'ledger_entries'];

$report = [];
foreach ($expected_cols as $tbl => $cols) {
    $present = []; $missing = [];
    $rs = @$conn->query("SHOW TABLES LIKE '$tbl'");
    if (!$rs || !$rs->num_rows) { $report[$tbl] = ['table_missing' => true]; continue; }
    foreach ($cols as $c) {
        $r = @$conn->query("SHOW COLUMNS FROM `$tbl` LIKE '$c'");
        if ($r && $r->num_rows) $present[] = $c; else $missing[] = $c;
    }
    $report[$tbl] = ['present' => $present, 'missing' => $missing, 'total' => count($cols)];
}

$tables_status = [];
foreach ($expected_tables as $t) {
    $r = @$conn->query("SHOW TABLES LIKE '$t'");
    $tables_status[$t] = ($r && $r->num_rows) ? 'PRESENT' : 'MISSING';
}

$total_expected = 0; $total_present = 0; $total_missing_list = [];
foreach ($report as $tbl => $info) {
    if (isset($info['table_missing'])) continue;
    $total_expected += $info['total'];
    $total_present  += count($info['present']);
    foreach ($info['missing'] as $c) $total_missing_list[] = "$tbl.$c";
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>DB Check — Tier 2 Migration Status</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head><body class="page-db-check">
<div class="setup-wrapper"><div class="setup-container">
<h2><i class="fas fa-stethoscope"></i> Tier 2 Migration Health Check</h2>
<p class="subtitle">Database: <code><?= htmlspecialchars(DB_NAME) ?></code></p>

<div class="db-check-summary">
    <div class="db-check-stat"><strong><?= $total_present ?></strong> / <?= $total_expected ?> columns present</div>
    <div class="db-check-stat"><strong><?= count($total_missing_list) ?></strong> columns missing</div>
</div>

<h3><i class="fas fa-table"></i> New Tables</h3>
<table class="about-roles-table">
<thead><tr><th>Table</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($tables_status as $t => $st): ?>
<tr><td><?= htmlspecialchars($t) ?></td><td><?= $st === 'PRESENT' ? '<span class="db-ok">PRESENT</span>' : '<span class="db-bad">MISSING</span>' ?></td></tr>
<?php endforeach; ?>
</tbody></table>

<h3><i class="fas fa-columns"></i> Per-Table Column Status</h3>
<?php foreach ($report as $tbl => $info): ?>
<div class="db-table-block">
<h4><?= htmlspecialchars($tbl) ?>
<?php if (isset($info['table_missing'])): ?>
    <span class="db-bad">— TABLE MISSING</span>
<?php else: ?>
    <small>(<?= count($info['present']) ?>/<?= $info['total'] ?>)</small>
<?php endif; ?>
</h4>
<?php if (!isset($info['table_missing']) && !empty($info['missing'])): ?>
    <p class="db-missing-list"><strong>Missing:</strong> <?= htmlspecialchars(implode(', ', $info['missing'])) ?></p>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($total_missing_list): ?>
<div class="db-action-card">
    <h3><i class="fas fa-wrench"></i> Action Required</h3>
    <p><strong><?= count($total_missing_list) ?> columns missing.</strong> Run <a href="setup.php" class="btn btn-primary">setup.php</a> again — it's idempotent and will only add what's missing. The hardened <code>$addColIfMissing</code> will surface any ALTER errors so we can pinpoint the issue.</p>
</div>
<?php else: ?>
<div class="db-action-card db-ok-card">
    <h3><i class="fas fa-check-circle"></i> All Tier 2 columns present</h3>
    <p>Schema is fully migrated. If you still see "Unknown column" errors, restart Apache/MySQL or clear MySQL's query cache.</p>
</div>
<?php endif; ?>

<p class="db-back-link"><a href="dashboard.php" class="btn">&larr; Back to Dashboard</a></p>
</div></div>
</body></html>
