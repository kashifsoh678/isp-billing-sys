<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: super_admin / admin / accountant
if (!isset($_SESSION['user_id']) || !can('bank_accounts', 'v')) {
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
$current_page = 'bank_accounts';
$can_delete   = can('bank_accounts', 'd');
$currency     = getSetting('currency_symbol', '$');

$ACCT_TYPES = ['current','savings','cash','jazzcash','easypaisa','other'];
$CURRENCIES = ['PKR','USD','EUR'];
$STATUSES   = ['active','inactive','closed'];

// csv import/template contract for this page
function bankAccountImportSpec() {
    return [
        'entity'  => 'Bank Account',
        'table'   => 'bank_accounts',
        'perm'    => 'bank_accounts',
        'columns' => [
            'account_name'   => ['label' => 'Account Name',  'type' => 's', 'required' => true, 'max' => 150, 'demo' => 'Demo Main Account'],
            'account_title'  => ['label' => 'Account Title', 'type' => 's', 'required' => true, 'max' => 150, 'demo' => 'Demo Company Ltd'],
            'bank_name'      => ['label' => 'Bank Name',     'type' => 's', 'required' => true, 'max' => 150, 'demo' => 'Demo Bank'],
            'branch_name'    => ['label' => 'Branch Name',   'type' => 's', 'max' => 150, 'demo' => 'Demo Branch'],
            'account_number' => ['label' => 'Account Number', 'type' => 's', 'required' => true, 'max' => 50, 'demo' => '0123456789'],
            'iban'           => ['label' => 'IBAN',          'type' => 's', 'max' => 50, 'demo' => 'PK00DEMO0000000000000000'],
            'swift_code'     => ['label' => 'SWIFT Code',    'type' => 's', 'max' => 20, 'demo' => 'DEMOPKKA'],
            'account_type'   => ['label' => 'Account Type',  'type' => 'enum', 'values' => ['current','savings','cash','jazzcash','easypaisa','other'], 'default' => 'current', 'demo' => 'current'],
            'currency'       => ['label' => 'Currency',      'type' => 'enum', 'values' => ['PKR','USD','EUR'], 'default' => 'PKR', 'demo' => 'PKR'],
            'opening_balance' => ['label' => 'Opening Balance', 'type' => 'd', 'scale' => 2, 'default' => 0, 'demo' => '10000'],
            'is_default'     => ['label' => 'Default Account', 'type' => 'bool', 'default' => 0, 'demo' => 'No'],
            'notes'          => ['label' => 'Notes',         'type' => 's', 'demo' => 'Imported from CSV'],
            'status'         => ['label' => 'Status',        'type' => 'enum', 'values' => ['active','inactive','closed'], 'default' => 'active', 'demo' => 'active'],
        ],
        // current_balance mirrors opening_balance on create, same as the Add Account form
        'before' => function (array &$row, mysqli $conn, array &$state) {
            $row['current_balance'] = $row['opening_balance'];
            return null;
        },
        // only one default account allowed — mirrors the app's set-default flip
        'after' => function (int $id, array $row, mysqli $conn, array &$state) {
            if (!empty($row['is_default'])) {
                $st = $conn->prepare("UPDATE bank_accounts SET is_default = 0 WHERE id != ? AND is_deleted = 0");
                $st->bind_param("i", $id);
                $st->execute();
                $st->close();
            }
        },
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'importCsv':      rsImportRun($conn, bankAccountImportSpec());
            case 'importTemplate': rsImportTemplate(bankAccountImportSpec());

            case 'getBankAccounts': {
                $sql = "SELECT id, account_name, account_title, bank_name, branch_name, account_number, iban,
                               swift_code, account_type, currency, opening_balance, current_balance,
                               is_default, notes, status, created_at
                        FROM bank_accounts
                        WHERE is_deleted = 0
                        ORDER BY is_default DESC, id DESC";
                $res  = $conn->query($sql);
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'              => (int)$r['id'],
                        'account_name'    => $r['account_name'],
                        'account_title'   => $r['account_title'],
                        'bank_name'       => $r['bank_name'],
                        'branch_name'     => $r['branch_name'] ?? '',
                        'account_number'  => $r['account_number'],
                        'iban'            => $r['iban'] ?? '',
                        'swift_code'      => $r['swift_code'] ?? '',
                        'account_type'    => $r['account_type'],
                        'currency'        => $r['currency'],
                        'opening_balance' => number_format((float)$r['opening_balance'], 2, '.', ''),
                        'current_balance' => number_format((float)$r['current_balance'], 2, '.', ''),
                        'is_default'      => (int)$r['is_default'],
                        'notes'           => $r['notes'] ?? '',
                        'status'          => $r['status'],
                        'created_at'      => $r['created_at'] ? date('c', strtotime($r['created_at'])) : ''
                    ];
                }
                echo json_encode(['success'=>true,'data'=>$rows,'currency'=>$currency]);
                exit();
            }

            case 'getBankAccount': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $st = $conn->prepare("SELECT * FROM bank_accounts WHERE id = ? AND is_deleted = 0");
                $st->bind_param("i", $id);
                $st->execute();
                $r = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$r) { echo json_encode(['success'=>false,'message'=>'Not found']); exit(); }
                $r['id'] = (int)$r['id'];
                $r['is_default'] = (int)$r['is_default'];
                echo json_encode(['success'=>true,'data'=>$r]);
                exit();
            }

            case 'addBankAccount':
            case 'updateBankAccount': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $isEdit = $_GET['action'] === 'updateBankAccount';
                $id       = (int)($_POST['id'] ?? 0);
                $acctName = trim($_POST['account_name'] ?? '');
                $acctTitle = trim($_POST['account_title'] ?? '');
                $bankNm   = trim($_POST['bank_name'] ?? '');
                $branch   = trim($_POST['branch_name'] ?? '');
                $acctNum  = trim($_POST['account_number'] ?? '');
                $iban     = trim($_POST['iban'] ?? '');
                $swift    = trim($_POST['swift_code'] ?? '');
                $type     = $_POST['account_type'] ?? 'current';
                $cur      = $_POST['currency'] ?? 'PKR';
                $openBal  = (float)($_POST['opening_balance'] ?? 0);
                $isDef    = !empty($_POST['is_default']) ? 1 : 0;
                $notes    = trim($_POST['notes'] ?? '');
                $status   = $_POST['status'] ?? 'active';

                global $ACCT_TYPES, $CURRENCIES, $STATUSES;
                if ($acctName === '')   { echo json_encode(['success'=>false,'message'=>'Account name required']); exit(); }
                if ($acctTitle === '')  { echo json_encode(['success'=>false,'message'=>'Account title required']); exit(); }
                if ($bankNm === '')     { echo json_encode(['success'=>false,'message'=>'Bank name required']); exit(); }
                if ($acctNum === '')    { echo json_encode(['success'=>false,'message'=>'Account # required']); exit(); }
                if (!in_array($type, $ACCT_TYPES, true)) { echo json_encode(['success'=>false,'message'=>'Invalid type']); exit(); }
                if (!in_array($cur, $CURRENCIES, true))  { echo json_encode(['success'=>false,'message'=>'Invalid currency']); exit(); }
                if (!in_array($status, $STATUSES, true)) { echo json_encode(['success'=>false,'message'=>'Invalid status']); exit(); }
                if (mb_strlen($acctName) > 150)  { echo json_encode(['success'=>false,'message'=>'Account name too long']); exit(); }
                if (mb_strlen($acctTitle) > 150) { echo json_encode(['success'=>false,'message'=>'Title too long']); exit(); }
                if (mb_strlen($bankNm) > 150)    { echo json_encode(['success'=>false,'message'=>'Bank name too long']); exit(); }
                if (mb_strlen($branch) > 150)    { echo json_encode(['success'=>false,'message'=>'Branch too long']); exit(); }
                if (mb_strlen($acctNum) > 50)    { echo json_encode(['success'=>false,'message'=>'Account # too long']); exit(); }
                if (mb_strlen($iban) > 50)       { echo json_encode(['success'=>false,'message'=>'IBAN too long']); exit(); }
                if (mb_strlen($swift) > 20)      { echo json_encode(['success'=>false,'message'=>'SWIFT too long']); exit(); }

                $branchBind = $branch === '' ? null : $branch;
                $ibanBind   = $iban === '' ? null : $iban;
                $swiftBind  = $swift === '' ? null : $swift;
                $notesBind  = $notes === '' ? null : $notes;

                // begin txn for default-flip safety
                $conn->begin_transaction();

                try {
                    if (!$isEdit) {
                        $stmt = $conn->prepare("INSERT INTO bank_accounts
                            (account_name, account_title, bank_name, branch_name, account_number, iban, swift_code,
                             account_type, currency, opening_balance, current_balance, is_default, notes, status)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        // 14 placeholders
                        // ssssss s ss dd i ss
                        $types = "sssssss" . "ss" . "dd" . "i" . "ss";
                        $stmt->bind_param($types,
                            $acctName, $acctTitle, $bankNm, $branchBind, $acctNum, $ibanBind, $swiftBind,
                            $type, $cur,
                            $openBal, $openBal,
                            $isDef,
                            $notesBind, $status);
                        if (!$stmt->execute()) {
                            $err = $stmt->error; $stmt->close();
                            throw new Exception('Failed to add: ' . $err);
                        }
                        $newId = $stmt->insert_id;
                        $stmt->close();

                        // if marked default, reset others
                        if ($isDef) {
                            $up = $conn->prepare("UPDATE bank_accounts SET is_default = 0 WHERE id != ? AND is_deleted = 0");
                            $up->bind_param("i", $newId);
                            $up->execute();
                            $up->close();
                        }

                        $conn->commit();
                        logActivity($user_id, $username, 'Bank Account Created', "Added bank account: $acctName ($bankNm)");
                        echo json_encode(['success'=>true,'message'=>'Bank account added','id'=>$newId]);
                        exit();
                    }

                    // UPDATE
                    if ($id <= 0) throw new Exception('Invalid id');

                    $stmt = $conn->prepare("UPDATE bank_accounts SET
                        account_name=?, account_title=?, bank_name=?, branch_name=?, account_number=?, iban=?, swift_code=?,
                        account_type=?, currency=?, opening_balance=?, is_default=?, notes=?, status=?
                        WHERE id=? AND is_deleted = 0");
                    // 13 SET cols + 1 id = 14
                    // ssssss s ss d i ss i
                    $types = "sssssss" . "ss" . "d" . "i" . "ss" . "i";
                    $stmt->bind_param($types,
                        $acctName, $acctTitle, $bankNm, $branchBind, $acctNum, $ibanBind, $swiftBind,
                        $type, $cur,
                        $openBal,
                        $isDef,
                        $notesBind, $status,
                        $id);
                    if (!$stmt->execute()) {
                        $err = $stmt->error; $stmt->close();
                        throw new Exception('Failed: ' . $err);
                    }
                    $stmt->close();

                    if ($isDef) {
                        $up = $conn->prepare("UPDATE bank_accounts SET is_default = 0 WHERE id != ? AND is_deleted = 0");
                        $up->bind_param("i", $id);
                        $up->execute();
                        $up->close();
                    }

                    $conn->commit();
                    logActivity($user_id, $username, 'Bank Account Updated', "Updated bank account: $acctName ($bankNm)");
                    echo json_encode(['success'=>true,'message'=>'Bank account updated']);
                    exit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    echo json_encode(['success'=>false,'message'=>$ex->getMessage()]);
                    exit();
                }
            }

            case 'setDefault': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                $st = $conn->prepare("SELECT account_name FROM bank_accounts WHERE id=? AND is_deleted=0");
                $st->bind_param("i", $id);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit(); }

                $conn->begin_transaction();
                try {
                    $up = $conn->prepare("UPDATE bank_accounts SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE is_deleted = 0");
                    $up->bind_param("i", $id);
                    $up->execute();
                    $up->close();
                    $conn->commit();
                    logActivity($user_id, $username, 'Bank Account Default Set', "Default → " . $row['account_name']);
                    echo json_encode(['success'=>true,'message'=>'Default account updated']);
                } catch (Exception $ex) {
                    $conn->rollback();
                    echo json_encode(['success'=>false,'message'=>$ex->getMessage()]);
                }
                exit();
            }

            case 'deleteBankAccount': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!can('bank_accounts', 'd')) { echo json_encode(['success'=>false,'message'=>'Only super admin can delete']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                // block: linked expenses (paid_from this bank)
                $chk = $conn->prepare("SELECT COUNT(*) AS c FROM expenses WHERE bank_account_id=? AND is_deleted=0");
                $chk->bind_param("i", $id);
                $chk->execute();
                $cnt = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0);
                $chk->close();
                if ($cnt > 0) { echo json_encode(['success'=>false,'message'=>"Cannot delete: $cnt expense(s) reference this account"]); exit(); }

                $st = $conn->prepare("SELECT account_name FROM bank_accounts WHERE id=? AND is_deleted=0");
                $st->bind_param("i", $id);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit(); }

                $up = $conn->prepare("UPDATE bank_accounts SET is_deleted=1, status='closed', is_default=0 WHERE id=?");
                $up->bind_param("i", $id);
                if ($up->execute()) {
                    $up->close();
                    logActivity($user_id, $username, 'Bank Account Deleted', "Deleted: " . $row['account_name']);
                    echo json_encode(['success'=>true,'message'=>'Bank account deleted']);
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
        error_log("BankAccounts.php error: " . $e->getMessage());
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
    <title>Bank Accounts - ISP Billing</title>

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
                <span>Bank Accounts</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-university"></i> Bank Accounts</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Company Bank Accounts</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadBankAccounts()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('bank_accounts', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Account
                        </button>
                    </div>
                </div>

                <!-- chevron pipeline -->
                <div class="chevron-pipeline initially-hidden" id="chevronPipeline" role="tablist" aria-label="Filter by status">
                    <div class="chevron-tab cv-all active"      data-status="all"      role="tab" tabindex="0">
                        <i class="fas fa-list"></i> All <span class="cv-count" id="cv-c-all">0</span>
                    </div>
                    <div class="chevron-tab cv-active"          data-status="active"   role="tab" tabindex="0">
                        <i class="fas fa-circle-check"></i> Active <span class="cv-count" id="cv-c-active">0</span>
                    </div>
                    <div class="chevron-tab cv-inactive"        data-status="inactive" role="tab" tabindex="0">
                        <i class="fas fa-circle-minus"></i> Inactive <span class="cv-count" id="cv-c-inactive">0</span>
                    </div>
                    <div class="chevron-tab cv-overdue"         data-status="closed"   role="tab" tabindex="0">
                        <i class="fas fa-times-circle"></i> Closed <span class="cv-count" id="cv-c-closed">0</span>
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
                            <input type="text" id="filterSearch" placeholder="Account / bank / number...">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-tag"></i> Type</label>
                            <select id="filterType">
                                <option value="">All Types</option>
                                <?php foreach ($ACCT_TYPES as $t): ?>
                                    <option value="<?php echo $t; ?>"><?php echo ucfirst($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap" class="table-responsive">
                    <table class="display table-full-width">
                        <thead>
                            <tr>
                                <th>Account Name</th><th>Title</th><th>Bank</th><th>Branch</th>
                                <th>Account #</th><th>Type</th><th>Currency</th><th>Balance</th>
                                <th>Default</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr>
                                <?php for ($j = 0; $j < 11; $j++): ?>
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
                    <table id="banksTable" class="display table-full-width"></table>
                </div>

                <!-- empty state -->
                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-university"></i></div>
                    <div class="empty-state-title">No bank accounts yet.</div>
                    <div class="empty-state-text">Add your first account to track expense payments.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bank Account Modal -->
    <div class="modal-overlay" id="bankModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add Bank Account</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="bankForm">
                    <input type="hidden" id="bankId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Account Name *</label>
                            <input type="text" id="account_name" name="account_name" maxlength="150" required placeholder="e.g. Main Operating">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Account Title *</label>
                            <input type="text" id="account_title" name="account_title" maxlength="150" required placeholder="Title on the account">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Name *</label>
                            <input type="text" id="bank_name" name="bank_name" maxlength="150" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Branch Name</label>
                            <input type="text" id="branch_name" name="branch_name" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Account # *</label>
                            <input type="text" id="account_number" name="account_number" maxlength="50" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-globe"></i> IBAN</label>
                            <input type="text" id="iban" name="iban" maxlength="50">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-globe-asia"></i> SWIFT Code</label>
                            <input type="text" id="swift_code" name="swift_code" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-list"></i> Account Type *</label>
                            <select id="account_type" name="account_type" required>
                                <?php foreach ($ACCT_TYPES as $t): ?>
                                    <option value="<?php echo $t; ?>"><?php echo ucfirst($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill"></i> Currency *</label>
                            <select id="currency" name="currency" required>
                                <?php foreach ($CURRENCIES as $c): ?>
                                    <option value="<?php echo $c; ?>"<?php echo $c === 'PKR' ? ' selected' : ''; ?>><?php echo $c; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Opening Balance</label>
                            <input type="number" id="opening_balance" name="opening_balance" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-star"></i> Default?</label>
                            <label class="status-toggle" style="margin-top:6px">
                                <input type="checkbox" id="is_default" name="is_default" value="1">
                                <span class="track"></span>
                                <span id="isDefaultLabel">No</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status *</label>
                            <select id="status" name="status" required>
                                <?php foreach ($STATUSES as $s): ?>
                                    <option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option>
                                <?php endforeach; ?>
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

    <!-- Bank Account View Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-university"></i> Bank Account Detail</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
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
    let banksTable;
    let isEditMode  = false;
    let banksData   = [];
    const CAN_DELETE = <?php echo $can_delete ? 'true' : 'false'; ?>;
    const CURRENCY = <?php echo json_encode($currency); ?>;
    let chevronStatus = 'all';

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function fmtAmt(n, cur) { return (cur || CURRENCY) + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    // iso -> readable, sorts on raw
    function fmtDate(v) {
        if (!v) return '';
        const d = new Date(v);
        if (isNaN(d)) return v;
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
    }
    function debounce(fn, ms) { let t; return function(){ clearTimeout(t); t = setTimeout(fn, ms); }; }

    $(document).ready(function() {
        new SearchableDropdown({ selectId: 'account_type', placeholder: 'Type...', icon: 'fas fa-list', allowClear: false });
        new SearchableDropdown({ selectId: 'currency',     placeholder: 'Currency...', icon: 'fas fa-money-bill', allowClear: false });
        new SearchableDropdown({ selectId: 'status',       placeholder: 'Status...', icon: 'fas fa-toggle-on', allowClear: false });
        new SearchableDropdown({ selectId: 'filterType',   placeholder: 'All Types', icon: 'fas fa-tag' });

        $('#is_default').on('change', function() {
            document.getElementById('isDefaultLabel').textContent = this.checked ? 'Yes' : 'No';
        });

        $(document).on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('.chevron-tab').removeClass('active');
            $(this).addClass('active');
            chevronStatus = $(this).data('status') || 'all';
            applyFilters();
        });
        $('#filterSearch').on('input', debounce(applyFilters, 200));
        $('#filterType').on('change', applyFilters);

        loadBankAccounts();
        rsImport.init({ entity: 'Bank Account', onDone: loadBankAccounts });
    });

    function refreshChevronCounts(rows) {
        const c = { all: rows.length, active: 0, inactive: 0, closed: 0 };
        rows.forEach(r => { if (r.status in c) c[r.status]++; });
        Object.keys(c).forEach(k => {
            const el = document.getElementById('cv-c-' + k);
            if (el) el.textContent = c[k];
        });
    }

    function loadBankAccounts() {
        $('#skeletonWrap').show();
        $('#tableWrap').hide();
        $('#emptyState').hide();
        $('#filtersSection').hide();

        $.ajax({
            url: '?action=getBankAccounts', method: 'GET', dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    banksData = resp.data || [];
                    refreshChevronCounts(banksData);
                    $('#chevronPipeline').removeClass('initially-hidden').css('display', '');
                    $('#filtersSection').removeClass('initially-hidden').show();
                    $('#skeletonWrap').hide();
                    if (!banksData.length) {
                        $('#tableWrap').hide();
                        $('#emptyState').show();
                        return;
                    }
                    $('#emptyState').hide();
                    $('#tableWrap').show();
                    initTable(banksData);
                    if (chevronStatus !== 'all') setTimeout(applyFilters, 120);
                } else {
                    $('#skeletonWrap').hide();
                    Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed to load' });
                }
            },
            error: function() {
                $('#skeletonWrap').hide();
                Swal.fire({ icon:'error', title:'Connection Error', text:'Could not connect to server.' });
            }
        });
    }

    function initTable(data) {
        if (banksTable) { banksTable.destroy(); $('#banksTable').empty(); }
        setTimeout(() => {
            banksTable = $('#banksTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    { data: 'account_name', title: 'Account Name', render: d => `<strong>${escapeHtml(d)}</strong>` },
                    { data: 'account_title', title: 'Title', render: d => escapeHtml(d) },
                    { data: 'bank_name', title: 'Bank', render: d => escapeHtml(d) },
                    { data: 'branch_name', title: 'Branch', render: d => d ? escapeHtml(d) : '<span class="muted">—</span>' },
                    { data: 'account_number', title: 'Account #', render: d => `<code>${escapeHtml(d)}</code>` },
                    {
                        data: 'account_type', title: 'Type',
                        render: d => `<span class="cat-badge ${escapeHtml(d)}">${escapeHtml(d)}</span>`
                    },
                    { data: 'currency', title: 'Currency', render: d => `<strong>${escapeHtml(d)}</strong>` },
                    {
                        data: 'current_balance', title: 'Balance',
                        className: 'num-cell',
                        render: function(d, t, row) { return fmtAmt(d, row.currency); }
                    },
                    {
                        data: 'is_default', title: 'Default',
                        render: d => Number(d) ? '<i class="fas fa-star" style="color:#f59e0b" title="Default"></i>' : '<span class="muted">—</span>'
                    },
                    {
                        data: 'status', title: 'Status',
                        render: d => `<span class="status-pill ${escapeHtml(d)}">${escapeHtml(d)}</span>`
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(d, t, row) {
                            let html = `<button class="action-icon" onclick="viewBankAccount(${row.id})" title="View"><i class="fas fa-eye"></i></button>`;
                            if (!row.is_default) {
                                html += ` <button class="action-icon warning-icon" onclick="setDefault(${row.id})" title="Set as Default"><i class="fas fa-star"></i></button>`;
                            }
                            // more menu
                            let menu = `<button class="act-more-item" onclick="editBankAccount(${row.id})"><i class="fas fa-edit"></i> Edit</button>`;
                            if (CAN_DELETE) {
                                menu += `<button class="act-more-item danger" onclick="deleteBankAccount(${row.id})"><i class="fas fa-trash"></i> Delete</button>`;
                            }
                            html += ` <div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">${menu}</div></div>`;
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
                order: [[8, 'desc']]
            });
        }, 100);
    }

    function applyFilters() {
        if (!banksTable) return;
        const q  = ($('#filterSearch').val() || '').toLowerCase().trim();
        const ft = $('#filterType').val();
        $.fn.dataTable.ext.search = [];
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const r = banksData[dataIndex];
            if (!r) return true;
            if (chevronStatus !== 'all' && r.status !== chevronStatus) return false;
            if (ft && r.account_type !== ft) return false;
            if (q !== '') {
                const hay = (r.account_name + ' ' + r.bank_name + ' ' + r.account_number + ' ' + (r.branch_name||'')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });
        banksTable.draw();
    }

    function clearFilters() {
        $('#filterSearch').val('');
        const ft = document.getElementById('filterType'); ft.value = ''; ft._sd && ft._sd.refresh();
        chevronStatus = 'all';
        $('.chevron-tab').removeClass('active');
        $('.chevron-tab[data-status="all"]').addClass('active');
        if (banksTable) { $.fn.dataTable.ext.search = []; banksTable.draw(); }
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Bank Account';
        document.getElementById('bankForm').reset();
        document.getElementById('bankId').value = '';
        document.getElementById('opening_balance').value = '0';
        document.getElementById('isDefaultLabel').textContent = 'No';
        ['account_type','currency','status'].forEach(id => {
            const el = document.getElementById(id);
            if (id === 'currency') el.value = 'PKR';
            else if (id === 'account_type') el.value = 'current';
            else el.value = 'active';
            el._sd && el._sd.refresh();
        });
        document.getElementById('bankModal').classList.add('active');
    }

    function editBankAccount(id) {
        $.getJSON('?action=getBankAccount&id=' + id, function(resp) {
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed' }); return; }
            const v = resp.data;
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Bank Account';
            document.getElementById('bankForm').reset();
            document.getElementById('bankId').value = v.id;
            document.getElementById('account_name').value = v.account_name || '';
            document.getElementById('account_title').value = v.account_title || '';
            document.getElementById('bank_name').value = v.bank_name || '';
            document.getElementById('branch_name').value = v.branch_name || '';
            document.getElementById('account_number').value = v.account_number || '';
            document.getElementById('iban').value = v.iban || '';
            document.getElementById('swift_code').value = v.swift_code || '';
            document.getElementById('opening_balance').value = v.opening_balance || 0;
            document.getElementById('notes').value = v.notes || '';
            const isDefChk = document.getElementById('is_default');
            isDefChk.checked = !!Number(v.is_default);
            document.getElementById('isDefaultLabel').textContent = isDefChk.checked ? 'Yes' : 'No';
            ['account_type','currency','status'].forEach(id => {
                const el = document.getElementById(id);
                el.value = v[id] || '';
                el._sd && el._sd.refresh();
            });
            document.getElementById('bankModal').classList.add('active');
        });
    }

    function closeModal() {
        document.getElementById('bankModal').classList.remove('active');
        document.getElementById('bankForm').reset();
    }

    document.getElementById('bankModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    function viewBankAccount(id) {
        $.getJSON('?action=getBankAccount&id=' + id, function(resp) {
            if (!resp.success) { Swal.fire({ icon:'error', title:'Error', text: resp.message || 'Failed' }); return; }
            const v = resp.data;
            const fields = [
                ['Account Name',   v.account_name || '—'],
                ['Account Title',  v.account_title || '—'],
                ['Bank Name',      v.bank_name || '—'],
                ['Branch',         v.branch_name || '—'],
                ['Account #',      v.account_number || '—'],
                ['IBAN',           v.iban || '—'],
                ['SWIFT Code',     v.swift_code || '—'],
                ['Type',           v.account_type || '—'],
                ['Currency',       v.currency || '—'],
                ['Opening Balance', fmtAmt(v.opening_balance, v.currency)],
                ['Current Balance', fmtAmt(v.current_balance, v.currency)],
                ['Default',        Number(v.is_default) ? 'Yes' : 'No'],
                ['Status',         v.status || '—'],
                ['Created',        fmtDate(v.created_at) || '—'],
                ['Notes',          v.notes || '—']
            ];
            const grid = fields.map(f =>
                `<div class="vtab-field${f[0]==='Notes'?' vtab-field-full':''}"><label>${escapeHtml(f[0])}</label><span>${escapeHtml(f[1])}</span></div>`
            ).join('');
            document.getElementById('viewModalBody').innerHTML = '<div class="vtab-grid">' + grid + '</div>';
            document.getElementById('viewModal').classList.add('active');
        });
    }

    function closeViewModal() {
        document.getElementById('viewModal').classList.remove('active');
    }

    document.getElementById('viewModal').addEventListener('click', function(e) {
        if (e.target === this) closeViewModal();
    });

    document.getElementById('bankForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const action = isEditMode ? 'updateBankAccount' : 'addBankAccount';
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=' + action, method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon:'success', title:'Success!', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeModal();
                    setTimeout(loadBankAccounts, 100);
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
        });
    });

    function setDefault(id) {
        Swal.fire({
            icon: 'question', title: 'Set as Default?', text: 'This will be the default account used for new payments.',
            showCancelButton: true, confirmButtonText: 'Set Default', cancelButtonText: 'Cancel'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=setDefault', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(loadBankAccounts, 100);
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Connection error' }); }
            });
        });
    }

    function deleteBankAccount(id) {
        if (!CAN_DELETE) return;
        Swal.fire({
            icon: 'warning', title: 'Delete Account?', text: 'This will soft-delete the account.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteBankAccount', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon:'success', text: resp.message, timer: 1800, showConfirmButton: false });
                        setTimeout(loadBankAccounts, 100);
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
