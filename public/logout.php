<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 */

require_once 'config.php';

// Log logout activity before destroying session
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    logActivity($_SESSION['user_id'], $_SESSION['username'], 'Logout', 'User logged out');

    // Remove session tracking
    try { removeUserSession(); } catch (Exception $e) {}

    // Clear Remember Me token
    clearRememberToken($_SESSION['user_id']);
}

// Destroy all session data
$_SESSION = array();

// Delete the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>
