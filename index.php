<?php
// Default index page - redirects to login or dashboard
require_once 'config.php';

// Check maintenance mode
if (isMaintenanceMode()) {
    // only super_admin can bypass maintenance
    if (isset($_SESSION['user_id']) && canBypassMaintenance()) {
        header("Location: dashboard.php");
        exit();
    }
    header("Location: maintenance.php");
    exit();
}

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}
exit();
?>
