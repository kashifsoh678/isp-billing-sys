<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: super_admin / admin / accountant
if (!isset($_SESSION['user_id']) || !can('vendors', 'v')) {
    header("Location: dashboard.php");
    exit();
}
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'vendors';
$can_delete   = can('vendors', 'd');
$currency     = getSetting('currency_symbol', '$');

// next vendor code: VEN-YYYY-NNN
function nextVendorCode($conn) {
    $year = date('Y');
    $like = "VEN-$year-%";
    $st = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(vendor_code,'-',-1) AS UNSIGNED)) AS mx FROM vendors WHERE vendor_code LIKE ?");
    $st->bind_param("s", $like);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    $next = (int)($r['mx'] ?? 0) + 1;
    return sprintf('VEN-%s-%03d', $year, $next);
}

// csv import/template contract for this page
function vendorImportSpec() {
    return [
        'entity'  => 'Vendor',
        'table'   => 'vendors',
        'perm'    => 'vendors',
        'unique'  => ['vendor_code'],
        'columns' => [
            'vendor_code'         => ['label' => 'Vendor Code',   'type' => 's', 'required' => true, 'max' => 30, 'demo' => 'VEN-DEMO-001'],
            'vendor_name'         => ['label' => 'Vendor Name',   'type' => 's', 'required' => true, 'max' => 200, 'demo' => 'Demo Vendor 1'],
            'contact_person'      => ['label' => 'Contact Person','type' => 's', 'max' => 150, 'demo' => 'Demo Contact'],
            'phone'               => ['label' => 'Phone',         'type' => 's', 'max' => 20, 'demo' => '03001234567'],
            'alternate_phone'     => ['label' => 'Alternate Phone', 'type' => 's', 'max' => 20, 'demo' => '03007654321'],
            'email'               => ['label' => 'Email',         'type' => 'email', 'demo' => 'vendor1@demo.com'],
            'address'             => ['label' => 'Address',       'type' => 's', 'demo' => 'House 1, Street 1, Demo City'],
            'city'                => ['label' => 'City',          'type' => 's', 'max' => 100, 'demo' => 'Demo City'],
            'ntn'                 => ['label' => 'NTN',            'type' => 's', 'max' => 50, 'demo' => '1234567-8'],
            'gst_number'          => ['label' => 'GST Number',    'type' => 's', 'max' => 50, 'demo' => 'GST-0001'],
            'tax_id'              => ['label' => 'Tax ID',        'type' => 's', 'max' => 50, 'demo' => 'TAX-0001'],
            'bank_name'           => ['label' => 'Bank Name',     'type' => 's', 'max' => 150, 'demo' => 'Demo Bank'],
            'bank_account_title'  => ['label' => 'Bank Account Title', 'type' => 's', 'max' => 150, 'demo' => 'Demo Vendor 1'],
            'bank_account_number' => ['label' => 'Bank Account Number', 'type' => 's', 'max' => 50, 'demo' => '0123456789'],
            'iban'                => ['label' => 'IBAN',          'type' => 's', 'max' => 50, 'demo' => 'PK00DEMO0000000000000000'],
            'payment_terms'       => ['label' => 'Payment Terms', 'type' => 's', 'max' => 100, 'demo' => 'Net 30'],
            'opening_balance'     => ['label' => 'Opening Balance', 'type' => 'd', 'scale' => 2, 'default' => 0, 'demo' => '0'],
            'notes'               => ['label' => 'Notes',         'type' => 's', 'demo' => 'Imported from CSV'],
            'status'              => ['label' => 'Status',        'type' => 'enum', 'values' => ['active','inactive'], 'default' => 'active', 'demo' => 'active'],
        ],
        // current_balance mirrors opening_balance on create, same as the Add Vendor form
        'before' => function (array &$row, mysqli $conn, array &$state) {
            $row['current_balance'] = $row['opening_balance'];
            return null;
        },
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'importCsv':      rsImportRun($conn, vendorImportSpec());
            case 'importTemplate': rsImportTemplate(vendorImportSpec());

            case 'getVendors': {
                // computed: outstanding from expenses
                $sql = "SELECT v.id, v.vendor_code, v.vendor_name, v.contact_person, v.phone, v.alternate_phone,
                               v.email, v.address, v.city, v.ntn, v.gst_number, v.tax_id,
                               v.bank_name, v.bank_account_title, v.bank_account_number, v.iban,
                               v.payment_terms, v.opening_balance, v.current_balance, v.notes,
                               v.status, v.created_at,
                               COALESCE((SELECT SUM(outstanding_amount) FROM expenses e
                                         WHERE e.vendor_id = v.id AND e.is_deleted = 0), 0) AS open_outstanding,
                               (SELECT COUNT(*) FROM expenses e WHERE e.vendor_id = v.id AND e.is_deleted = 0) AS expense_count
                        FROM vendors v
                        WHERE v.is_deleted = 0
                        ORDER BY v.id DESC";
                $res  = $conn->query($sql);
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'                  => (int)$r['id'],
                        'vendor_code'         => $r['vendor_code'],
                        'vendor_name'         => $r['vendor_name'],
                        'contact_person'      => $r['contact_person'] ?? '',
                        'phone'               => $r['phone'] ?? '',
                        'alternate_phone'     => $r['alternate_phone'] ?? '',
                        'email'               => $r['email'] ?? '',
                        'address'             => $r['address'] ?? '',
                        'city'                => $r['city'] ?? '',
                        'ntn'                 => $r['ntn'] ?? '',
                        'gst_number'          => $r['gst_number'] ?? '',
                        'tax_id'              => $r['tax_id'] ?? '',
                        'bank_name'           => $r['bank_name'] ?? '',
                        'bank_account_title'  => $r['bank_account_title'] ?? '',
                        'bank_account_number' => $r['bank_account_number'] ?? '',
                        'iban'                => $r['iban'] ?? '',
                        'payment_terms'       => $r['payment_terms'] ?? '',
                        'opening_balance'     => number_format((float)$r['opening_balance'], 2, '.', ''),
                        'current_balance'     => number_format((float)$r['current_balance'], 2, '.', ''),
                        'open_outstanding'    => number_format((float)$r['open_outstanding'], 2, '.', ''),
                        'expense_count'       => (int)$r['expense_count'],
                        'notes'               => $r['notes'] ?? '',
                        'status'              => $r['status'],
                        'created_at'          => date('c', strtotime($r['created_at']))
                    ];
                }
                echo json_encode(['success'=>true,'data'=>$rows,'currency'=>$currency]);
                exit();
            }

            case 'getVendor': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $st = $conn->prepare("SELECT * FROM vendors WHERE id = ? AND is_deleted = 0");
                $st->bind_param("i", $id);
                $st->execute();
                $r = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$r) { echo json_encode(['success'=>false,'message'=>'Not found']); exit(); }
                $r['id'] = (int)$r['id'];
                echo json_encode(['success'=>true,'data'=>$r]);
                exit();
            }

            // ─── vendor ledger — expenses = credit, real expense_payments = debit ──
            case 'getVendorLedger': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                $v = $conn->prepare("SELECT vendor_code, vendor_name, opening_balance FROM vendors WHERE id=? AND is_deleted=0");
                $v->bind_param("i", $id);
                $v->execute();
                $vr = $v->get_result()->fetch_assoc();
                $v->close();
                if (!$vr) { echo json_encode(['success'=>false,'message'=>'Vendor not found']); exit(); }

                // one source of truth — ledger_entries (opening, bills, payments, stock purchases)
                $st = $conn->prepare("SELECT entry_date, particulars, ref_type, ref_number, debit, credit
                                      FROM ledger_entries
                                      WHERE is_deleted = 0 AND party_type = 'vendor' AND party_id = ?
                                      ORDER BY entry_date ASC, id ASC");
                $st->bind_param("i", $id);
                $st->execute();
                $res = $st->get_result();

                // running balance = cumulative credit - cumulative debit (positive = what we owe)
                $running = 0.0; $totCredit = 0.0; $totDebit = 0.0;
                $lines = [];
                while ($e = $res->fetch_assoc()) {
                    $d = (float)$e['debit']; $c = (float)$e['credit'];
                    $totDebit += $d; $totCredit += $c;
                    $running += $c - $d;
                    $lines[] = [
                        'date'    => $e['entry_date'],
                        'ref'     => $e['ref_number'] !== null && $e['ref_number'] !== '' ? $e['ref_number'] : strtoupper($e['ref_type']),
                        'desc'    => $e['particulars'],
                        'debit'   => number_format($d, 2, '.', ''),
                        'credit'  => number_format($c, 2, '.', ''),
                        'balance' => number_format($running, 2, '.', '')
                    ];
                }
                $st->close();

                echo json_encode([
                    'success'   => true,
                    'vendor'    => ['code'=>$vr['vendor_code'], 'name'=>$vr['vendor_name']],
                    'lines'     => $lines,
                    'total_credit' => number_format($totCredit,2,'.',''),
                    'total_debit'  => number_format($totDebit,2,'.',''),
                    'balance'   => number_format($running,2,'.',''),
                    'currency'  => $currency
                ]);
                exit();
            }

            case 'addVendor':
            case 'updateVendor': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $isEdit = $_GET['action'] === 'updateVendor';
                $id      = (int)($_POST['id'] ?? 0);
                $code    = trim($_POST['vendor_code'] ?? '');
                $name    = trim($_POST['vendor_name'] ?? '');
                $contact = trim($_POST['contact_person'] ?? '');
                $phone   = trim($_POST['phone'] ?? '');
                $altPhone = trim($_POST['alternate_phone'] ?? '');
                $email   = trim($_POST['email'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $city    = trim($_POST['city'] ?? '');
                $ntn     = trim($_POST['ntn'] ?? '');
                $gstNum  = trim($_POST['gst_number'] ?? '');
                $taxId   = trim($_POST['tax_id'] ?? '');
                $bankNm  = trim($_POST['bank_name'] ?? '');
                $bankTtl = trim($_POST['bank_account_title'] ?? '');
                $bankNum = trim($_POST['bank_account_number'] ?? '');
                $iban    = trim($_POST['iban'] ?? '');
                $payTerm = trim($_POST['payment_terms'] ?? '');
                $openBal = (float)($_POST['opening_balance'] ?? 0);
                $notes   = trim($_POST['notes'] ?? '');
                $status  = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

                // validations
                if ($name === '') { echo json_encode(['success'=>false,'message'=>'Vendor name is required']); exit(); }
                if (mb_strlen($name) > 200) { echo json_encode(['success'=>false,'message'=>'Vendor name too long']); exit(); }
                if (mb_strlen($contact) > 150) { echo json_encode(['success'=>false,'message'=>'Contact person too long']); exit(); }
                if (mb_strlen($phone) > 20)    { echo json_encode(['success'=>false,'message'=>'Phone too long']); exit(); }
                if (mb_strlen($altPhone) > 20) { echo json_encode(['success'=>false,'message'=>'Alt phone too long']); exit(); }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'message'=>'Invalid email']); exit(); }
                if (mb_strlen($city) > 100)    { echo json_encode(['success'=>false,'message'=>'City too long']); exit(); }
                if (mb_strlen($ntn) > 50)      { echo json_encode(['success'=>false,'message'=>'NTN too long']); exit(); }
                if (mb_strlen($gstNum) > 50)   { echo json_encode(['success'=>false,'message'=>'GST too long']); exit(); }
                if (mb_strlen($taxId) > 50)    { echo json_encode(['success'=>false,'message'=>'Tax ID too long']); exit(); }
                if (mb_strlen($bankNm) > 150)  { echo json_encode(['success'=>false,'message'=>'Bank name too long']); exit(); }
                if (mb_strlen($bankTtl) > 150) { echo json_encode(['success'=>false,'message'=>'Bank title too long']); exit(); }
                if (mb_strlen($bankNum) > 50)  { echo json_encode(['success'=>false,'message'=>'Account # too long']); exit(); }
                if (mb_strlen($iban) > 50)     { echo json_encode(['success'=>false,'message'=>'IBAN too long']); exit(); }
                if (mb_strlen($payTerm) > 100) { echo json_encode(['success'=>false,'message'=>'Payment terms too long']); exit(); }
                if (mb_strlen($code) > 30)     { echo json_encode(['success'=>false,'message'=>'Code too long']); exit(); }

                // auto-gen code if blank
                if ($code === '') $code = nextVendorCode($conn);

                // dup code check
                $dupSql = $isEdit
                    ? "SELECT id FROM vendors WHERE vendor_code = ? AND id != ? AND is_deleted = 0"
                    : "SELECT id FROM vendors WHERE vendor_code = ? AND is_deleted = 0";
                $st = $conn->prepare($dupSql);
                if ($isEdit) $st->bind_param("si", $code, $id);
                else         $st->bind_param("s", $code);
                $st->execute();
                if ($st->get_result()->num_rows > 0) {
                    $st->close();
                    echo json_encode(['success'=>false,'message'=>'Vendor code already exists']); exit();
                }
                $st->close();

                // null-safe binds
                $contactBind = $contact === '' ? null : $contact;
                $phoneBind   = $phone === '' ? null : $phone;
                $altBind     = $altPhone === '' ? null : $altPhone;
                $emailBind   = $email === '' ? null : $email;
                $addrBind    = $address === '' ? null : $address;
                $cityBind    = $city === '' ? null : $city;
                $ntnBind     = $ntn === '' ? null : $ntn;
                $gstBind     = $gstNum === '' ? null : $gstNum;
                $taxBind     = $taxId === '' ? null : $taxId;
                $bnmBind     = $bankNm === '' ? null : $bankNm;
                $btlBind     = $bankTtl === '' ? null : $bankTtl;
                $bnumBind    = $bankNum === '' ? null : $bankNum;
                $ibanBind    = $iban === '' ? null : $iban;
                $payTermBind = $payTerm === '' ? null : $payTerm;
                $notesBind   = $notes === '' ? null : $notes;

                if (!$isEdit) {
                    // current_balance starts at opening_balance for new vendors
                    $stmt = $conn->prepare("INSERT INTO vendors
                        (vendor_code, vendor_name, contact_person, phone, alternate_phone, email, address, city,
                         ntn, gst_number, tax_id,
                         bank_name, bank_account_title, bank_account_number, iban,
                         payment_terms, opening_balance, current_balance, notes, status)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    // 20 placeholders
                    // ssssssss sss ssss s ddss
                    $types = "ssssssss" . "sss" . "ssss" . "s" . "ddss";
                    $stmt->bind_param($types,
                        $code, $name, $contactBind, $phoneBind, $altBind, $emailBind, $addrBind, $cityBind,
                        $ntnBind, $gstBind, $taxBind,
                        $bnmBind, $btlBind, $bnumBind, $ibanBind,
                        $payTermBind, $openBal, $openBal, $notesBind, $status);
                    if ($stmt->execute()) {
                        $newId = $stmt->insert_id;
                        $stmt->close();
                        logActivity($user_id, $username, 'Vendor Created', "Created vendor: $name ($code)");
                        try { createNotificationForAdmins('Vendor Added', 'Vendor "' . htmlspecialchars($name) . '" was added.', 'info', 'vendors.php'); } catch (Exception $e) {}
                        echo json_encode(['success'=>true,'message'=>'Vendor added','id'=>$newId]);
                    } else {
                        $err = $stmt->error; $stmt->close();
                        echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                    }
                    exit();
                }

                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $stmt = $conn->prepare("UPDATE vendors SET
                    vendor_code=?, vendor_name=?, contact_person=?, phone=?, alternate_phone=?, email=?, address=?, city=?,
                    ntn=?, gst_number=?, tax_id=?,
                    bank_name=?, bank_account_title=?, bank_account_number=?, iban=?,
                    payment_terms=?, opening_balance=?, notes=?, status=?
                    WHERE id=? AND is_deleted = 0");
                // 19 SET cols + 1 id = 20
                // ssssssss sss ssss s d ssi
                $types = "ssssssss" . "sss" . "ssss" . "s" . "d" . "ss" . "i";
                $stmt->bind_param($types,
                    $code, $name, $contactBind, $phoneBind, $altBind, $emailBind, $addrBind, $cityBind,
                    $ntnBind, $gstBind, $taxBind,
                    $bnmBind, $btlBind, $bnumBind, $ibanBind,
                    $payTermBind, $openBal, $notesBind, $status,
                    $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Vendor Updated', "Updated vendor: $name ($code)");
                    echo json_encode(['success'=>true,'message'=>'Vendor updated']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                }
                exit();
            }

            case 'toggleStatus': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $st = $conn->prepare("SELECT vendor_name, status FROM vendors WHERE id=? AND is_deleted=0");
                $st->bind_param("i", $id);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit(); }
                $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
                $up = $conn->prepare("UPDATE vendors SET status=? WHERE id=? AND is_deleted=0");
                $up->bind_param("si", $newStatus, $id);
                if ($up->execute()) {
                    $up->close();
                    logActivity($user_id, $username, 'Vendor Status Changed', "Vendor {$row['vendor_name']} → $newStatus");
                    echo json_encode(['success'=>true,'message'=>"Status set to $newStatus",'status'=>$newStatus]);
                } else {
                    $err = $up->error; $up->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                }
                exit();
            }

            case 'deleteVendor': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!can('vendors', 'd')) { echo json_encode(['success'=>false,'message'=>'Only super admin can delete']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                // block: any expenses linked
                $chk = $conn->prepare("SELECT COUNT(*) AS c FROM expenses WHERE vendor_id=? AND is_deleted=0");
                $chk->bind_param("i", $id);
                $chk->execute();
                $cnt = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0);
                $chk->close();
                if ($cnt > 0) { echo json_encode(['success'=>false,'message'=>"Cannot delete: $cnt expense(s) linked"]); exit(); }

                $st = $conn->prepare("SELECT vendor_name FROM vendors WHERE id=? AND is_deleted=0");
                $st->bind_param("i", $id);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit(); }

                $up = $conn->prepare("UPDATE vendors SET is_deleted=1, status='inactive' WHERE id=?");
                $up->bind_param("i", $id);
                if ($up->execute()) {
                    $up->close();
                    logActivity($user_id, $username, 'Vendor Deleted', "Deleted vendor: " . $row['vendor_name']);
                    echo json_encode(['success'=>true,'message'=>'Vendor deleted']);
                } else {
                    $up->close();
                    echo json_encode(['success'=>false,'message'=>'Failed to delete']);
                }
                exit();
            }

            default:
                echo json_encode(['success'=>false,'message'=>'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Vendors.php error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Server error: ' . $e->getMessage()]);
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
    <title>Vendors - ISP Billing</title>

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
                <span>Vendors</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-user-tie"></i> Vendors</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Vendor Master</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadVendors()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('vendors', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Vendor
                        </button>
                    </div>
                </div>

                <!-- chevron pipeline (status quick-filter) -->
                <div class="chevron-pipeline initially-hidden" id="chevronPipeline" role="tablist" aria-label="Filter vendors by status">
                    <div class="chevron-tab cv-all active"      data-status="all"      role="tab" tabindex="0">
                        <i class="fas fa-list"></i> All <span class="cv-count" id="cv-c-all">0</span>
                    </div>
                    <div class="chevron-tab cv-active"          data-status="active"   role="tab" tabindex="0">
                        <i class="fas fa-circle-check"></i> Active <span class="cv-count" id="cv-c-active">0</span>
                    </div>
                    <div class="chevron-tab cv-inactive"        data-status="inactive" role="tab" tabindex="0">
                        <i class="fas fa-circle-minus"></i> Inactive <span class="cv-count" id="cv-c-inactive">0</span>
                    </div>
                </div>

                <!-- search filter -->
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
                            <input type="text" id="filterSearch" placeholder="Code / name / city / phone...">
                        </div>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap" class="table-responsive">
                    <table class="display table-full-width">
                        <thead>
                            <tr>
                                <th>Code</th><th>Name</th><th>Contact</th><th>Phone</th>
                                <th>Email</th><th>City</th><th>NTN</th><th>Balance</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr>
                                <?php for ($j = 0; $j < 10; $j++): ?>
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
                    <table id="vendorsTable" class="display table-full-width">
                        <tfoot>
                            <tr>
                                <th colspan="7" class="dt-foot-label">Totals</th>
                                <th class="dt-foot-val" id="footPayable">—</th>
                                <th class="dt-foot-val" id="footCount">—</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- empty state -->
                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-user-tie"></i></div>
                    <div class="empty-state-title">No vendors yet.</div>
                    <div class="empty-state-text">Add your first vendor to start tracking expenses.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Vendor Modal -->
    <div class="modal-overlay" id="vendorModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Vendor</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="vendorForm">
                    <input type="hidden" id="vendorId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Vendor Code</label>
                            <input type="text" id="vendor_code" name="vendor_code" maxlength="30" placeholder="Auto: VEN-YYYY-NNN">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Vendor Name *</label>
                            <input type="text" id="vendor_name" name="vendor_name" maxlength="200" required placeholder="Vendor / supplier name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Contact Person</label>
                            <input type="text" id="contact_person" name="contact_person" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="text" id="phone" name="phone" maxlength="20" placeholder="03001234567">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-mobile-alt"></i> Alternate Phone</label>
                            <input type="text" id="alternate_phone" name="alternate_phone" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="email" name="email" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> City</label>
                            <input type="text" id="city" name="city" maxlength="100">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> NTN</label>
                            <input type="text" id="ntn" name="ntn" maxlength="50">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-receipt"></i> GST #</label>
                            <input type="text" id="gst_number" name="gst_number" maxlength="50">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-invoice"></i> Tax ID</label>
                            <input type="text" id="tax_id" name="tax_id" maxlength="50">
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-map-marker-alt"></i> Address</label>
                            <textarea id="address" name="address" rows="2"></textarea>
                        </div>

                        <div class="form-group" style="grid-column:1/-1; border-top:1px solid #e5e7eb; padding-top:10px; margin-top:6px">
                            <strong><i class="fas fa-university"></i> Bank Details</strong>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Name</label>
                            <input type="text" id="bank_name" name="bank_name" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-circle"></i> Account Title</label>
                            <input type="text" id="bank_account_title" name="bank_account_title" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Account #</label>
                            <input type="text" id="bank_account_number" name="bank_account_number" maxlength="50">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-globe"></i> IBAN</label>
                            <input type="text" id="iban" name="iban" maxlength="50">
                        </div>

                        <div class="form-group" style="grid-column:1/-1; border-top:1px solid #e5e7eb; padding-top:10px; margin-top:6px">
                            <strong><i class="fas fa-handshake"></i> Terms & Balance</strong>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> Payment Terms</label>
                            <input type="text" id="payment_terms" name="payment_terms" maxlength="100" placeholder="e.g. Net 30">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Opening Balance (<?php echo htmlspecialchars($currency); ?>)</label>
                            <input type="number" id="opening_balance" name="opening_balance" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status *</label>
                            <select id="status" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label><i class="fas fa-sticky-note"></i> Notes</label>
                            <textarea id="notes" name="notes" rows="3"></textarea>
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

    <!-- View Vendor Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-user-tie"></i> Vendor Profile</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <!-- Vendor Ledger Modal -->
    <div class="modal-overlay" id="ledgerModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-book"></i> Vendor Ledger</h3>
                <button class="close-btn" onclick="closeLedgerModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="ledgerModalBody"></div>
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
    let vendorsTable;
    let isEditMode  = false;
    let vendorsData = [];
    const CAN_DELETE = <?php echo $can_delete ? 'true' : 'false'; ?>;
    const CURRENCY   = <?php echo json_encode($currency); ?>;
    let chevronStatus = 'all';

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function fmtAmt(n) { return CURRENCY + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtDate(d) {
        if (!d) return '—';
        const dt = /^\d{4}-\d{2}-\d{2}$/.test(d) ? new Date(d + 'T00:00:00') : new Date(d);
        if (isNaN(dt)) return escapeHtml(d);
        return dt.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' });
    }
    function debounce(fn, ms) { let t; return function(){ clearTimeout(t); t = setTimeout(fn, ms); }; }

    $(document).ready(function() {
        new SearchableDropdown({ selectId: 'status', placeholder: 'Status...', icon: 'fas fa-toggle-on', allowClear: false });

        $(document).on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('.chevron-tab').removeClass('active');
            $(this).addClass('active');
            chevronStatus = $(this).data('status') || 'all';
            applyFilters();
        });
        $('#filterSearch').on('input', debounce(applyFilters, 200));

        loadVendors();
        rsImport.init({ entity: 'Vendor', onDone: loadVendors });
    });

    function refreshChevronCounts(rows) {
        const c = { all: rows.length, active: 0, inactive: 0 };
        rows.forEach(r => { if (r.status in c) c[r.status]++; });
        Object.keys(c).forEach(k => {
            const el = document.getElementById('cv-c-' + k);
            if (el) el.textContent = c[k];
        });
    }

    function loadVendors() {
        $('#skeletonWrap').show();
        $('#tableWrap').hide();
        $('#emptyState').hide();
        $('#filtersSection').hide();

        $.ajax({
            url: '?action=getVendors', method: 'GET', dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    vendorsData = resp.data || [];
                    refreshChevronCounts(vendorsData);
                    $('#chevronPipeline').removeClass('initially-hidden').css('display', '');
                    $('#filtersSection').removeClass('initially-hidden').show();
                    $('#skeletonWrap').hide();
                    if (!vendorsData.length) {
                        $('#tableWrap').hide();
                        $('#emptyState').show();
                        return;
                    }
                    $('#emptyState').hide();
                    $('#tableWrap').show();
                    initTable(vendorsData);
                    if (chevronStatus !== 'all') setTimeout(applyFilters, 120);
                } else {
                    $('#skeletonWrap').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' });
                }
            },
            error: function() {
                $('#skeletonWrap').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
            }
        });
    }

    function initTable(data) {
        if (vendorsTable) { vendorsTable.destroy(); $('#vendorsTable').empty(); }
        setTimeout(() => {
            vendorsTable = $('#vendorsTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'vendor_code', title: 'Code', render: d => `<strong>${escapeHtml(d)}</strong>` },
                    { data: 'vendor_name', title: 'Name', render: d => escapeHtml(d) },
                    { data: 'contact_person', title: 'Contact', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'phone', title: 'Phone', render: d => d ? `<a href="tel:${escapeHtml(d)}">${escapeHtml(d)}</a>` : '<span class="muted">—</span>' },
                    { data: 'email', title: 'Email', render: d => d ? `<a href="mailto:${escapeHtml(d)}">${escapeHtml(d)}</a>` : '<span class="muted">—</span>' },
                    { data: 'city', title: 'City', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'ntn', title: 'NTN', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    {
                        data: 'open_outstanding', title: 'Outstanding',
                        className: 'num-cell',
                        render: function(d, t, row) {
                            const v = parseFloat(d || 0);
                            const cls = v > 0 ? 'color:#dc2626;font-weight:600' : 'color:#16a34a';
                            return `<span style="${cls}">${fmtAmt(v)}</span>`;
                        }
                    },
                    {
                        data: 'status', title: 'Status',
                        render: d => `<span class="status-pill ${escapeHtml(d)}">${escapeHtml(d)}</span>`
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(d, t, row) {
                            let html = `<button class="action-icon view-icon" onclick="viewVendor(${row.id})" title="View"><i class="fas fa-eye"></i></button>`;
                            html += ` <button class="action-icon" style="color:#0074D9" onclick="viewLedger(${row.id})" title="Ledger"><i class="fas fa-book"></i></button>`;
                            html += ` <div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">`
                                  + `<button class="act-more-item" onclick="editVendor(${row.id})"><i class="fas fa-edit"></i> Edit</button>`
                                  + `<button class="act-more-item" onclick="toggleStatus(${row.id})"><i class="fas fa-toggle-on"></i> Toggle Status</button>`;
                            if (CAN_DELETE) {
                                html += `<button class="act-more-item danger" onclick="deleteVendor(${row.id}, ${row.expense_count})"><i class="fas fa-trash"></i> Delete</button>`;
                            }
                            html += `</div></div>`;
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
                order: [[0, 'desc']],
                footerCallback: function(row, data, start, end, display) {
                    // sum total payable across filtered rows + count
                    const api = this.api();
                    let payable = 0;
                    api.rows({ search: 'applied' }).data().each(r => { payable += parseFloat(r.open_outstanding || 0); });
                    document.getElementById('footPayable').textContent = fmtAmt(payable);
                    document.getElementById('footCount').textContent = api.rows({ search: 'applied' }).count() + ' vendors';
                }
            });
        }, 100);
    }

    function applyFilters() {
        if (!vendorsTable) return;
        const q = ($('#filterSearch').val() || '').toLowerCase().trim();
        $.fn.dataTable.ext.search = [];
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const r = vendorsData[dataIndex];
            if (!r) return true;
            if (chevronStatus !== 'all' && r.status !== chevronStatus) return false;
            if (q !== '') {
                const hay = (r.vendor_code + ' ' + r.vendor_name + ' ' + (r.city||'') + ' ' + (r.phone||'') + ' ' + (r.contact_person||'')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });
        vendorsTable.draw();
    }

    function clearFilters() {
        $('#filterSearch').val('');
        chevronStatus = 'all';
        $('.chevron-tab').removeClass('active');
        $('.chevron-tab[data-status="all"]').addClass('active');
        if (vendorsTable) { $.fn.dataTable.ext.search = []; vendorsTable.draw(); }
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Vendor';
        document.getElementById('vendorForm').reset();
        document.getElementById('vendorId').value = '';
        document.getElementById('opening_balance').value = '0';
        const sEl = document.getElementById('status'); sEl.value = 'active'; sEl._sd && sEl._sd.refresh();
        document.getElementById('vendorModal').classList.add('active');
    }

    function viewVendor(id) {
        $.getJSON('?action=getVendor&id=' + id, function(resp) {
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed' }); return; }
            const v = resp.data;
            const f = (lbl, val) => `<div class="vtab-field"><label>${lbl}</label><span>${val ? escapeHtml(val) : '—'}</span></div>`;
            const body = `
              <div class="vtab-nav">
                <button type="button" class="vtab-btn active" data-pane="vp-basic"><i class="fas fa-user-tie"></i> Basic</button>
                <button type="button" class="vtab-btn" data-pane="vp-tax"><i class="fas fa-file-invoice"></i> Tax</button>
                <button type="button" class="vtab-btn" data-pane="vp-bank"><i class="fas fa-university"></i> Bank</button>
                <button type="button" class="vtab-btn" data-pane="vp-terms"><i class="fas fa-handshake"></i> Terms</button>
              </div>
              <div class="vtab-pane active" id="vp-basic">
                <div class="vtab-grid">
                  ${f('Vendor Code', v.vendor_code)}
                  ${f('Vendor Name', v.vendor_name)}
                  ${f('Contact Person', v.contact_person)}
                  ${f('Phone', v.phone)}
                  ${f('Alternate Phone', v.alternate_phone)}
                  ${f('Email', v.email)}
                  ${f('City', v.city)}
                  ${f('Status', v.status)}
                  ${f('Address', v.address)}
                </div>
              </div>
              <div class="vtab-pane" id="vp-tax">
                <div class="vtab-grid">
                  ${f('NTN', v.ntn)}
                  ${f('GST #', v.gst_number)}
                  ${f('Tax ID', v.tax_id)}
                </div>
              </div>
              <div class="vtab-pane" id="vp-bank">
                <div class="vtab-grid">
                  ${f('Bank Name', v.bank_name)}
                  ${f('Account Title', v.bank_account_title)}
                  ${f('Account #', v.bank_account_number)}
                  ${f('IBAN', v.iban)}
                </div>
              </div>
              <div class="vtab-pane" id="vp-terms">
                <div class="vtab-grid">
                  ${f('Payment Terms', v.payment_terms)}
                  ${f('Opening Balance', fmtAmt(v.opening_balance))}
                  ${f('Current Balance', fmtAmt(v.current_balance))}
                  ${f('Notes', v.notes)}
                </div>
              </div>`;
            document.getElementById('viewModalBody').innerHTML = body;
            document.getElementById('viewModal').classList.add('active');
        });
    }
    function closeViewModal() { document.getElementById('viewModal').classList.remove('active'); }
    document.getElementById('viewModal').addEventListener('click', function(e) { if (e.target === this) closeViewModal(); });
    // vtab switching (delegated)
    $(document).on('click', '#viewModalBody .vtab-btn', function() {
        const pane = $(this).data('pane');
        $('#viewModalBody .vtab-btn').removeClass('active');
        $(this).addClass('active');
        $('#viewModalBody .vtab-pane').removeClass('active');
        $('#viewModalBody #' + pane).addClass('active');
    });

    function viewLedger(id) {
        Swal.fire({ title:'Loading ledger...', allowOutsideClick:false, didOpen: () => Swal.showLoading() });
        $.getJSON('?action=getVendorLedger&id=' + id, function(resp) {
            Swal.close();
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed' }); return; }
            let rows = '';
            if (!resp.lines.length) {
                rows = '<tr><td colspan="6"><div class="empty-state"><div class="empty-state-icon"><i class="fas fa-book"></i></div><div class="empty-state-title">No ledger entries</div></div></td></tr>';
            } else {
                resp.lines.forEach(l => {
                    rows += `<tr>
                        <td>${l.date ? fmtDate(l.date) : '<span class="muted">—</span>'}</td>
                        <td>${escapeHtml(l.ref)}</td>
                        <td>${escapeHtml(l.desc)}</td>
                        <td class="ledger-debit num-cell">${parseFloat(l.debit) > 0 ? fmtAmt(l.debit) : '—'}</td>
                        <td class="ledger-credit num-cell">${parseFloat(l.credit) > 0 ? fmtAmt(l.credit) : '—'}</td>
                        <td class="ledger-balance num-cell">${fmtAmt(l.balance)}</td>
                    </tr>`;
                });
            }
            const body = `
              <div class="vtab-grid">
                <div class="vtab-field"><label>Vendor</label><span>${escapeHtml(resp.vendor.name)} (${escapeHtml(resp.vendor.code)})</span></div>
                <div class="vtab-field"><label>Outstanding Payable</label><span>${fmtAmt(resp.balance)}</span></div>
              </div>
              <div class="table-responsive">
                <table class="ledger-table table-full-width">
                  <thead><tr><th>Date</th><th>Ref</th><th>Description</th><th>Debit (Paid)</th><th>Credit (Owed)</th><th>Balance</th></tr></thead>
                  <tbody>${rows}</tbody>
                  <tfoot><tr>
                    <th colspan="3" class="dt-foot-label">Totals</th>
                    <th class="dt-foot-val">${fmtAmt(resp.total_debit)}</th>
                    <th class="dt-foot-val">${fmtAmt(resp.total_credit)}</th>
                    <th class="dt-foot-val">${fmtAmt(resp.balance)}</th>
                  </tr></tfoot>
                </table>
              </div>`;
            document.getElementById('ledgerModalBody').innerHTML = body;
            document.getElementById('ledgerModal').classList.add('active');
        }).fail(function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); });
    }
    function closeLedgerModal() { document.getElementById('ledgerModal').classList.remove('active'); }
    document.getElementById('ledgerModal').addEventListener('click', function(e) { if (e.target === this) closeLedgerModal(); });

    function editVendor(id) {
        $.getJSON('?action=getVendor&id=' + id, function(resp) {
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed' }); return; }
            const v = resp.data;
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Vendor';
            document.getElementById('vendorForm').reset();
            document.getElementById('vendorId').value = v.id;
            document.getElementById('vendor_code').value = v.vendor_code || '';
            document.getElementById('vendor_name').value = v.vendor_name || '';
            document.getElementById('contact_person').value = v.contact_person || '';
            document.getElementById('phone').value = v.phone || '';
            document.getElementById('alternate_phone').value = v.alternate_phone || '';
            document.getElementById('email').value = v.email || '';
            document.getElementById('city').value = v.city || '';
            document.getElementById('ntn').value = v.ntn || '';
            document.getElementById('gst_number').value = v.gst_number || '';
            document.getElementById('tax_id').value = v.tax_id || '';
            document.getElementById('address').value = v.address || '';
            document.getElementById('bank_name').value = v.bank_name || '';
            document.getElementById('bank_account_title').value = v.bank_account_title || '';
            document.getElementById('bank_account_number').value = v.bank_account_number || '';
            document.getElementById('iban').value = v.iban || '';
            document.getElementById('payment_terms').value = v.payment_terms || '';
            document.getElementById('opening_balance').value = v.opening_balance || 0;
            document.getElementById('notes').value = v.notes || '';
            const sEl = document.getElementById('status'); sEl.value = v.status; sEl._sd && sEl._sd.refresh();
            document.getElementById('vendorModal').classList.add('active');
        });
    }

    function closeModal() {
        document.getElementById('vendorModal').classList.remove('active');
        document.getElementById('vendorForm').reset();
    }

    document.getElementById('vendorModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.getElementById('vendorForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const action = isEditMode ? 'updateVendor' : 'addVendor';
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=' + action, method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeModal();
                    setTimeout(loadVendors, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    });

    function toggleStatus(id) {
        const fd = new FormData(); fd.append('id', id);
        $.ajax({
            url: '?action=toggleStatus', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                    setTimeout(loadVendors, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    function deleteVendor(id, expenseCount) {
        if (!CAN_DELETE) return;
        if (expenseCount > 0) {
            Swal.fire({ icon:'warning', title:'Cannot Delete', text: `${expenseCount} expense(s) linked. Reassign first or set vendor inactive.` });
            return;
        }
        Swal.fire({
            icon: 'warning', title: 'Delete Vendor?', text: 'This will soft-delete the vendor.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteVendor', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1800, showConfirmButton: false });
                        setTimeout(loadVendors, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
            });
        });
    }
    </script>
</body>
</html>
