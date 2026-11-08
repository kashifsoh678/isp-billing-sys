<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Shared Sidebar Component
 * Include this file in all dashboard pages
 *
 * Required vars: $username, $role, $current_page, $user_id
 * Roles: super_admin | admin | accountant | collector
 */

if (!isset($username) || !isset($role) || !isset($current_page) || !isset($user_id)) {
    die('Sidebar requires $username, $role, $current_page, and $user_id variables');
}

// Get user's profile image, fallback to default logo
$profile_image = getProfileImage($user_id);
$default_logo = "https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg";
$image_src = $profile_image ? $profile_image : $default_logo;

// visibility comes straight off the permission matrix (roles.php edits it live)
$show_customers  = can('customers', 'v', $role);
$show_packages   = can('packages', 'v', $role);
$show_areas      = can('areas', 'v', $role);
$show_invoices   = can('invoices', 'v', $role);
$show_payments    = can('payments', 'v', $role);
$show_collections = can('collections', 'v', $role);
$show_collections_workbook = can('collections_runs', 'v', $role);
$show_expenses    = can('expenses', 'v', $role);
$show_vendors     = can('vendors', 'v', $role);
$show_bank_accts  = can('bank_accounts', 'v', $role);
$show_ledger      = can('ledger', 'v', $role);
$show_complaints = can('complaints', 'v', $role);
$show_leads      = can('leads', 'v', $role);
$show_equipment  = can('equipment', 'v', $role);
$show_pos        = can('pos', 'v', $role);
$show_stock      = can('stock', 'v', $role);
$show_stock_mov  = can('stock_movements', 'v', $role);
$show_aging      = can('aging', 'v', $role);
$show_roles      = can('roles', 'v', $role);

$user_theme = getUserTheme($user_id);
$default_language = getDefaultLanguage();

echo generateUserThemeCSS($user_id);
?>
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title">
            <i class="fas fa-wifi"></i>
            <span class="sidebar-title-text">ISP Billing</span>
        </div>
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
        </button>
    </div>
    <!-- <div class="sidebar-logo-section">
        <img src="<?php echo htmlspecialchars($image_src); ?>" alt="Profile" class="sidebar-logo" onerror="this.src='<?php echo $default_logo; ?>'">
    </div> -->
    <div class="sidebar-menu-section">
        <div class="sidebar-menu-title">Navigation</div>
        <ul class="sidebar-menu">
            <!-- Dashboard (all roles) -->
            <li data-tooltip="Dashboard">
                <a href="dashboard.php" class="<?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <?php if ($show_customers): ?>
            <li data-tooltip="Customers">
                <a href="customers.php" class="<?php echo $current_page === 'customers' ? 'active' : ''; ?>">
                    <i class="fas fa-user-friends"></i>
                    <span>Customers</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_packages): ?>
            <li data-tooltip="Packages">
                <a href="packages.php" class="<?php echo $current_page === 'packages' ? 'active' : ''; ?>">
                    <i class="fas fa-box"></i>
                    <span>Packages</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_areas): ?>
            <li data-tooltip="Service Areas">
                <a href="areas.php" class="<?php echo $current_page === 'areas' ? 'active' : ''; ?>">
                    <i class="fas fa-map-marked-alt"></i>
                    <span>Areas</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_invoices): ?>
            <li data-tooltip="Invoices">
                <a href="invoices.php" class="<?php echo $current_page === 'invoices' ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice"></i>
                    <span>Invoices</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_payments): ?>
            <li data-tooltip="Payments">
                <a href="payments.php" class="<?php echo $current_page === 'payments' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Payments</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_collections): ?>
            <li data-tooltip="Today's Collections">
                <a href="collections.php" class="<?php echo $current_page === 'collections' ? 'active' : ''; ?>">
                    <i class="fas fa-route"></i>
                    <span>Collections</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_collections_workbook): ?>
            <li data-tooltip="Collections Workbook">
                <a href="collections_runs.php" class="<?php echo $current_page === 'collections_runs' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Collections Workbook</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_complaints): ?>
            <li data-tooltip="Complaints">
                <a href="complaints.php" class="<?php echo $current_page === 'complaints' ? 'active' : ''; ?>">
                    <i class="fas fa-headset"></i>
                    <span>Complaints</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_expenses): ?>
            <li data-tooltip="Expenses">
                <a href="expenses.php" class="<?php echo $current_page === 'expenses' ? 'active' : ''; ?>">
                    <i class="fas fa-receipt"></i>
                    <span>Expenses</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_vendors): ?>
            <li data-tooltip="Vendors">
                <a href="vendors.php" class="<?php echo $current_page === 'vendors' ? 'active' : ''; ?>">
                    <i class="fas fa-truck"></i>
                    <span>Vendors</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_bank_accts): ?>
            <li data-tooltip="Bank Accounts">
                <a href="bank_accounts.php" class="<?php echo $current_page === 'bank_accounts' ? 'active' : ''; ?>">
                    <i class="fas fa-university"></i>
                    <span>Bank Accounts</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_ledger): ?>
            <li data-tooltip="Ledger">
                <a href="ledger.php" class="<?php echo $current_page === 'ledger' ? 'active' : ''; ?>">
                    <i class="fas fa-book"></i>
                    <span>Ledger</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_leads): ?>
            <li data-tooltip="Leads">
                <a href="leads.php" class="<?php echo $current_page === 'leads' ? 'active' : ''; ?>">
                    <i class="fas fa-funnel-dollar"></i>
                    <span>Leads</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_equipment): ?>
            <li data-tooltip="Equipment">
                <a href="equipment.php" class="<?php echo $current_page === 'equipment' ? 'active' : ''; ?>">
                    <i class="fas fa-server"></i>
                    <span>Equipment</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_pos): ?>
            <li data-tooltip="POS Counter">
                <a href="pos.php" class="<?php echo $current_page === 'pos' ? 'active' : ''; ?>">
                    <i class="fas fa-cash-register"></i>
                    <span>POS Counter</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_stock): ?>
            <li data-tooltip="Stock">
                <a href="stock.php" class="<?php echo $current_page === 'stock' ? 'active' : ''; ?>">
                    <i class="fas fa-boxes-stacked"></i>
                    <span>Stock Items</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_stock_mov): ?>
            <li data-tooltip="Stock Movements">
                <a href="stock_movements.php" class="<?php echo $current_page === 'stock_movements' ? 'active' : ''; ?>">
                    <i class="fas fa-dolly"></i>
                    <span>Stock Movements</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_aging): ?>
            <li data-tooltip="Aging Report">
                <a href="aging.php" class="<?php echo $current_page === 'aging' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-pie"></i>
                    <span>Aging Report</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (canManageUsers($role)): ?>
            <li data-tooltip="Staff">
                <a href="users.php" class="<?php echo $current_page === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-id-badge"></i>
                    <span>Staff</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($show_roles): ?>
            <li data-tooltip="Roles">
                <a href="roles.php" class="<?php echo $current_page === 'roles' ? 'active' : ''; ?>">
                    <i class="fas fa-user-shield"></i>
                    <span>Roles &amp; Permissions</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (canViewActivityLogs($role)): ?>
            <li data-tooltip="Activity Logs">
                <a href="logs.php" class="<?php echo $current_page === 'logs' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span>Activity Logs</span>
                </a>
            </li>
            <?php endif; ?>

            <li data-tooltip="My Account">
                <a href="account.php" class="<?php echo $current_page === 'account' ? 'active' : ''; ?>">
                    <i class="fas fa-user-circle"></i>
                    <span>My Account</span>
                </a>
            </li>

            <?php if (canAccessSystem($role)): ?>
            <li data-tooltip="Site Settings">
                <a href="settings.php" class="<?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>Site Settings</span>
                </a>
            </li>
            <li data-tooltip="OAuth Setup">
                <a href="oauth_setup.php" class="<?php echo $current_page === 'oauth_setup' ? 'active' : ''; ?>">
                    <i class="fab fa-google"></i>
                    <span>OAuth Setup</span>
                </a>
            </li>
            <li data-tooltip="SMTP Setup">
                <a href="smtp_setup.php" class="<?php echo $current_page === 'smtp_setup' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i>
                    <span>SMTP Setup</span>
                </a>
            </li>
            <li data-tooltip="Sessions">
                <a href="sessions.php" class="<?php echo $current_page === 'sessions' ? 'active' : ''; ?>">
                    <i class="fas fa-desktop"></i>
                    <span>Sessions</span>
                </a>
            </li>
            <li data-tooltip="Backup">
                <a href="backup.php" class="<?php echo $current_page === 'backup' ? 'active' : ''; ?>">
                    <i class="fas fa-database"></i>
                    <span>Backup</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- About App (all roles) -->
            <li data-tooltip="About App">
                <a href="about.php" class="<?php echo $current_page === 'about' ? 'active' : ''; ?>">
                    <i class="fas fa-info-circle"></i>
                    <span>About App</span>
                </a>
            </li>
        </ul>
    </div>
    <div id="google_translate_element" class="notranslate" style="display:none;"></div>
    <div class="sidebar-theme">
        <button onclick="toggleTheme()">
            <i class="fas fa-moon" id="themeIcon"></i>
            <span id="themeText">Dark Mode</span>
        </button>
    </div>
    <div class="sidebar-logout">
        <button onclick="window.location.href='logout.php'">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </button>
    </div>
</div>

<!-- Theme Toggle JavaScript -->
<script>
/**
 * Theme Toggle Functionality
 * Handles light/dark mode switching with localStorage persistence
 * Also respects user's saved theme preference from database
 */
function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const userThemeMode = '<?php echo $user_theme['theme_mode']; ?>';
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    // Priority: localStorage > database setting > system preference
    let isDark = false;
    if (savedTheme) {
        isDark = savedTheme === 'dark';
    } else if (userThemeMode) {
        isDark = userThemeMode === 'dark';
        // Save to localStorage so it persists
        localStorage.setItem('theme', userThemeMode);
    } else {
        isDark = prefersDark;
    }

    if (isDark) {
        document.body.classList.add('dark-mode');
        updateThemeButton(true);
    } else {
        document.body.classList.remove('dark-mode');
        updateThemeButton(false);
    }
}

function toggleTheme() {
    const isDark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    updateThemeButton(isDark);
}

function updateThemeButton(isDark) {
    const icon = document.getElementById('themeIcon');
    const text = document.getElementById('themeText');

    if (icon && text) {
        if (isDark) {
            icon.className = 'fas fa-sun';
            text.textContent = 'Light Mode';
        } else {
            icon.className = 'fas fa-moon';
            text.textContent = 'Dark Mode';
        }
    }
}

// Initialize theme on page load
initTheme();

/**
 * Sidebar Collapse Functionality
 * Handles sidebar expand/collapse with localStorage persistence
 */
function initSidebar() {
    const savedState = localStorage.getItem('sidebarCollapsed');
    if (savedState === 'true') {
        document.getElementById('sidebar').classList.add('collapsed');
        updateSidebarIcon(true);
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed);
    updateSidebarIcon(isCollapsed);
}

function updateSidebarIcon(isCollapsed) {
    const icon = document.getElementById('sidebarToggleIcon');
    if (icon) {
        icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
    }
}

// Initialize sidebar on page load
initSidebar();

// Listen for system theme changes
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
    if (!localStorage.getItem('theme')) {
        if (e.matches) {
            document.body.classList.add('dark-mode');
            updateThemeButton(true);
        } else {
            document.body.classList.remove('dark-mode');
            updateThemeButton(false);
        }
    }
});
</script>

<!-- Google Translate Integration -->
<script>
/**
 * Google Translate Functionality
 * Auto-translates page based on admin's default language setting
 */
function googleTranslateElementInit() {
    new google.translate.TranslateElement({
        pageLanguage: 'en',
        autoDisplay: false,
        layout: google.translate.TranslateElement.InlineLayout.SIMPLE
    }, 'google_translate_element');
}

// Set default language from admin setting
(function() {
    const defaultLang = '<?php echo htmlspecialchars($default_language); ?>';
    const isReverted = localStorage.getItem('lang_reverted_to_english');

    if (isReverted === 'true') {
        // User manually reverted to English — don't auto-translate
        return;
    }

    if (defaultLang && defaultLang !== 'en') {
        const currentTrans = document.cookie.split(';').find(c => c.trim().startsWith('googtrans='));
        if (!currentTrans || currentTrans.trim() === 'googtrans=') {
            document.cookie = 'googtrans=/en/' + defaultLang + ';path=/';
            document.cookie = 'googtrans=/en/' + defaultLang + ';path=/;domain=' + window.location.hostname;
        }
    }
})();

function revertToEnglish() {
    // Clear googtrans cookies
    document.cookie = 'googtrans=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT';
    document.cookie = 'googtrans=;path=/;domain=' + window.location.hostname + ';expires=Thu, 01 Jan 1970 00:00:00 GMT';
    // Remember user chose English
    localStorage.setItem('lang_reverted_to_english', 'true');
    location.reload();
}
</script>
<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

<script>
// company block + asset urls every page's print code reads
window.RS_COMPANY = <?php echo json_encode([
    'name'    => getSetting('site_name', 'ISP Billing'),
    'logo'    => getSetting('site_logo', ''),
    'address' => getSetting('company_address', ''),
    'phone'   => getSetting('company_phone', ''),
    'email'   => getSetting('company_email', ''),
    'currency'=> getSetting('currency_symbol', '$')
]); ?>;
window.RS_ASSETS = { thermal: <?php echo json_encode(asset('thermal.css')); ?> };
</script>
<!-- shared page UI: header actions + wide add/edit modals -->
<script src="<?php echo asset('page_ui.js'); ?>"></script>
