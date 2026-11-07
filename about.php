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
$current_page = 'about';

$branding = getSiteBranding();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>About - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body class="initially-hidden">
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                About App
            </div>

            <div class="header">
                <h1><i class="fas fa-info-circle"></i> About App</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="about-section">
                <div class="about-header">
                    <div class="about-logo">
                        <!-- <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="App Logo"> -->
                    </div>
                    <div class="about-title">
                        <h1><?php echo htmlspecialchars($branding['site_name']); ?></h1>
                        <p class="about-dev">Developed by <strong>Kashif Sohail</strong> (Kashif  Scripts)</p>
                    </div>
                </div>

                <div class="about-card">
                    <h2><i class="fas fa-question-circle"></i> What is this App?</h2>
                    <p>So basicaly this is a complete dashboard system built with PHP and MySQL. Its used to manage users, monitor activity, and keep everything organized in one place. Think of it like a control panel where you can manage your entire web application from a single dashboard with diffrent tools and features.</p>
                </div>

                <div class="about-card">
                    <h2><i class="fas fa-clipboard-list"></i> What Can You Do With It?</h2>
                    <ul class="about-features">
                        <li><i class="fas fa-lock"></i> User Authentication</li>
                        <li><i class="fas fa-tachometer-alt"></i> Admin Dashboard</li>
                        <li><i class="fas fa-users-cog"></i> User Management</li>
                        <li><i class="fas fa-history"></i> Activity Logs</li>
                        <li><i class="fas fa-palette"></i> Theme Customization</li>
                        <li><i class="fas fa-envelope"></i> SMTP Email</li>
                        <li><i class="fab fa-google"></i> Google OAuth</li>
                        <li><i class="fas fa-desktop"></i> Session Management</li>
                        <li><i class="fas fa-bell"></i> Notifications</li>
                        <li><i class="fas fa-database"></i> Database Backup</li>
                        <li><i class="fas fa-tools"></i> Maintenance Mode</li>
                        <li><i class="fas fa-language"></i> Google Translate</li>
                    </ul>
                </div>

                <div class="about-card">
                    <h2><i class="fas fa-users-cog"></i> User Roles &amp; Permissions</h2>
                    <p class="mb-24">This grid is live — it reads the same <code>role_permissions</code> table the app checks on every click.
                       Edit it on the <a href="roles.php">Roles &amp; Permissions</a> page and this table updates itself.
                       <b>V</b> = View &nbsp; <b>A</b> = Add &nbsp; <b>E</b> = Edit &nbsp; <b>D</b> = Delete.</p>
                    <div class="about-table-wrapper">
                        <table class="about-roles-table rbac-matrix">
                            <?php
                            $abRoles = getRolesDetailed();
                            $abGrid  = [];
                            try {
                                $abC = getDBConnection();
                                $abR = @$abC->query("SELECT role_key, page_key, can_view, can_add, can_edit, can_delete FROM role_permissions");
                                if ($abR) while ($x = $abR->fetch_assoc()) $abGrid[$x['role_key']][$x['page_key']] = $x;
                            } catch (Throwable $e) {}
                            ?>
                            <thead>
                                <tr>
                                    <th>Page / Module</th>
                                    <?php foreach ($abRoles as $r): ?>
                                    <th><span class="role-badge" style="background:<?php echo htmlspecialchars($r['badge_color']); ?>;color:#fff"><?php echo htmlspecialchars($r['label']); ?></span></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $abGroup = ''; foreach (rbacPages() as $pk => $meta):
                                    if ($meta[1] !== $abGroup):
                                        $abGroup = $meta[1]; ?>
                                <tr class="rbac-group"><td colspan="<?php echo count($abRoles) + 1; ?>"><i class="fas fa-folder-open"></i> <?php echo htmlspecialchars($abGroup); ?></td></tr>
                                <?php endif; ?>
                                <tr>
                                    <td><i class="<?php echo htmlspecialchars($meta[2]); ?>"></i> <?php echo htmlspecialchars($meta[0]); ?></td>
                                    <?php foreach ($abRoles as $r):
                                        if ((int)$r['is_super'] === 1) { echo '<td><span class="rbac-full">VAED</span></td>'; continue; }
                                        $c = $abGrid[$r['role_key']][$pk] ?? null;
                                        $txt = '';
                                        if ($c) {
                                            if ((int)$c['can_view'])   $txt .= 'V';
                                            if ((int)$c['can_add'])    $txt .= 'A';
                                            if ((int)$c['can_edit'])   $txt .= 'E';
                                            if ((int)$c['can_delete']) $txt .= 'D';
                                        }
                                        echo $txt !== ''
                                            ? '<td><span class="rbac-full">' . $txt . '</span></td>'
                                            : '<td><i class="fas fa-times-circle text-danger"></i></td>';
                                    endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="mb-24"><i class="fas fa-circle-info"></i> Roles marked <b>Built-in</b> cannot be deleted. Custom roles
                       (for example an Installation Engineer or Store Keeper) are created on the Roles page and get their access from this same grid.
                       A role whose <b>data scope</b> is <i>Own areas only</i> sees just the areas assigned to that staff member.</p>
                </div>

                <div class="about-card">
                    <h2><i class="fas fa-calculator"></i> Formulas &amp; Business Logic</h2>
                    <p class="mb-24">Every calculation the app performs, and where it lives.</p>
                    <div class="about-table-wrapper">
                        <table class="about-roles-table">
                            <thead>
                                <tr><th>What</th><th>Formula / Logic</th><th>Where Used</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>Invoice Total</td><td>Amount + Stock Items &minus; Discount + Tax + Additional / Equipment / Installation charges + Late Fee &minus; WHT</td><td>invoices.php (single + batch), cron_auto_billing.php</td></tr>
                                <tr><td>Invoice Stock Items Total</td><td>&Sigma;(line qty &times; rate) over <code>invoice_items</code> &mdash; recomputed server-side on every save, the posted figure is never trusted</td><td>invoiceStockLinesClean() in config.php</td></tr>
                                <tr><td>Stock Issued by an Invoice</td><td>Saving an invoice with stock lines posts ONE <code>issue_out</code> movement (ISS-) tied to that invoice, so billed stock leaves the store exactly once</td><td>writeInvoiceStock() in config.php</td></tr>
                                <tr><td>Stock Returned by an Invoice</td><td>Re-saving voids the old movement and posts a fresh one &middot; cancel / delete marks it cancelled and puts serial units back to in_stock</td><td>releaseInvoiceStock() in config.php</td></tr>
                                <tr><td>Sellable Quantity Check</td><td>Line qty &le; current_qty (quantity items) &middot; every serial must exist, belong to that item and be in_stock (serial items)</td><td>invoiceStockLinesClean()</td></tr>
                                <tr><td>Invoice Balance</td><td>Total &minus; Paid (cleared payments; refunds credited pro-rata)</td><td>recomputeInvoiceFromAllocations() in config.php</td></tr>
                                <tr><td>Invoice Status</td><td>Paid &ge; Total &rarr; paid &middot; Paid &gt; 0 &rarr; partial &middot; past due date &rarr; overdue &middot; else unpaid</td><td>recomputeInvoiceFromAllocations()</td></tr>
                                <tr><td>Customer Outstanding</td><td>SUM(balance_amount) over open, non-cancelled invoices</td><td>customers.php, aging.php, dashboard.php</td></tr>
                                <tr><td>Counter Previous Balance</td><td>SUM(invoices.balance_amount) over open invoices + SUM(pos_sales.due_amount) over completed counter bills &mdash; shown the moment a customer is picked at the counter</td><td>pos.php &rarr; getCustomerDues</td></tr>
                                <tr><td>Expense Outstanding</td><td>Amount &minus; SUM(expense_payments.amount)</td><td>recomputeExpenseFromPayments() in expenses.php</td></tr>
                                <tr><td>Stock on Hand (quantity item)</td><td>Opening Qty + &Sigma;(Purchase In + Return In) &minus; &Sigma;(Issue Out + Damage Out) &plusmn; Adjustments</td><td>recomputeItemStock() in config.php</td></tr>
                                <tr><td>Stock on Hand (serial item)</td><td>COUNT of its units in <code>equipment</code> with status = in_stock</td><td>recomputeItemStock()</td></tr>
                                <tr><td>Average Cost</td><td>&Sigma;(purchase qty &times; rate) &divide; &Sigma;(purchase qty)</td><td>recomputeItemStock()</td></tr>
                                <tr><td>Stock Value</td><td>Current Qty &times; Average Cost</td><td>stock.php summary cards</td></tr>
                                <tr><td>Low Stock Flag</td><td>Reorder Level &gt; 0 AND Current Qty &le; Reorder Level AND Qty &gt; 0 (Out of Stock when Qty &le; 0)</td><td>stock.php, low-stock notification</td></tr>
                                <tr><td>Ledger Direction</td><td>Debit increases what is owed to us or held by us &middot; Credit increases what we owe</td><td>postLedger() in config.php</td></tr>
                                <tr><td>Customer Ledger Balance</td><td>&Sigma;(Debit &minus; Credit) &mdash; a positive closing balance is <b>Dr</b> = receivable</td><td>ledger.php, customers.php</td></tr>
                                <tr><td>Vendor Ledger Balance</td><td>&Sigma;(Credit &minus; Debit) &mdash; a positive closing balance is <b>Cr</b> = payable</td><td>ledger.php, vendors.php</td></tr>
                                <tr><td>Cash / Bank Closing</td><td>Opening + Money In (debits) &minus; Money Out (credits)</td><td>ledger.php &rarr; Cash &amp; Bank Book</td></tr>
                                <tr><td>Ledger De-duplication</td><td>One row per (ref_type, ref_id, party_type, party_id) &mdash; re-saving a document updates that row instead of adding another</td><td>ledger_entries unique key</td></tr>
                                <tr><td>Permission Check</td><td>role_permissions[role][page][V|A|E|D]; ticking A/E/D forces V on, clearing V clears A/E/D</td><td>can() / requirePerm() in config.php</td></tr>
                                <tr><td>Area Scope</td><td>users.area_id &cup; user_areas rows &rarr; <code>area_id IN (...)</code> for any role whose scope is &ldquo;Own areas only&rdquo;</td><td>currentAreaIds() / areaScope() in config.php</td></tr>
                                <tr><td>Aging Bucket</td><td>Days between the oldest unpaid invoice date and today</td><td>aging.php, ledger.php &rarr; Balances</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="about-card about-developer">
                    <h2><i class="fas fa-code"></i> About the Developer</h2>
                    <div class="developer-info">
                        <!-- <img src="https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg" alt="Kashif Sohail" class="developer-avatar"> -->
                        <div class="developer-details">
                            <h3>Kashif Sohail</h3>
                            <p class="developer-brand">Kashif  Scripts</p>
                            <p>I build custom web apps, dashboards, PHP/MySQL solutions, and automation tools. If you need somthing built for your buisness or project, feel free to reach out!</p>
                            <div class="developer-links">
                                <a href="https://wa.me/923071344399" target="_blank" class="dev-link whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp Me</a>
                                <a href="https://www.youtube.com/@kashifsoh678" target="_blank" class="dev-link youtube"><i class="fab fa-youtube"></i> Subscribe on YouTube</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="about-footer">
                    <p>Made with <i class="fas fa-heart text-danger"></i> by Kashif  Scripts</p>
                    <p class="about-version">Version 1.0.0</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
    $(document).ready(function() {
        document.body.classList.remove('initially-hidden');
    });
    </script>
</body>
</html>
