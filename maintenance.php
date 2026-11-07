<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Maintenance Mode Page
 */

require_once 'config.php';

// If maintenance mode is OFF, redirect to index
if (!isMaintenanceMode()) {
    header("Location: index.php");
    exit();
}

// super_admin bypasses maintenance
if (isset($_SESSION['user_id']) && canBypassMaintenance()) {
    header("Location: dashboard.php");
    exit();
}

// Get site branding
$branding = getSiteBranding();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Under Maintenance - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body>
    <div class="maintenance-container">
        <!-- Admin Login Icon (Top Right) -->
        <a href="login.php?maintenance=1" class="maintenance-admin-link" title="Admin Login">
            <i class="fas fa-user-shield"></i>
        </a>

        <div class="maintenance-content">
            <div class="maintenance-icon">
                <i class="fas fa-tools"></i>
            </div>
            <h1 class="maintenance-title">Under Maintenance</h1>
            <p class="maintenance-desc">
                We are currently performing scheduled maintenance. We'll be back shortly.
            </p>
            <div class="maintenance-info">
                <i class="fas fa-info-circle"></i>
                <span>Thank you for your patience. Please check back soon.</span>
            </div>
            <div class="maintenance-brand">
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="maintenance-logo" onerror="this.style.display='none'">
                <p><?php echo $branding['copyright_text']; ?></p>
            </div>
        </div>
    </div>

    <!-- Theme Toggle Button -->
    <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>

    <script>
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.body.classList.add('dark-mode');
            updateThemeIcon(true);
        }
    }

    function toggleTheme() {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark);
    }

    function updateThemeIcon(isDark) {
        const icon = document.getElementById('themeIcon');
        if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }

    initTheme();
    </script>
</body>
</html>
