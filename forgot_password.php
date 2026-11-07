<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Forgot Password Page
 */

require_once 'config.php';

// Check maintenance mode - block forgot password
if (isMaintenanceMode()) {
    header("Location: maintenance.php");
    exit();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

// Get site branding
$branding = getSiteBranding();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if SMTP is enabled
        $smtp_enabled = getSetting('smtp_enabled', '0');
        if ($smtp_enabled !== '1') {
            $error = 'Password reset is not available. Contact administrator.';
        } else {
            $otp = createPasswordReset($email);

            if ($otp !== false) {
                // Send OTP email
                $emailBody = getOTPEmailTemplate($otp, 'reset');
                $result = sendEmail($email, 'Password Reset - ' . $branding['site_name'], $emailBody);

                if ($result['success']) {
                    // Redirect to reset page
                    header("Location: reset_password.php?email=" . urlencode($email));
                    exit();
                } else {
                    $error = 'Failed to send reset email. Please try again.';
                    error_log("Password reset email failed: " . $result['message']);
                }
            } else {
                // Don't reveal if email exists or not (security best practice)
                // But still show a success-like message
                header("Location: reset_password.php?email=" . urlencode($email));
                exit();
            }
        }
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
    <title>Forgot Password - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body class="login-page">
    <div class="auth-shell">
        <?php
        $hero_headline = 'Locked out?<br>We&rsquo;ll fix that.';
        $hero_lede     = 'Tell us the email on your account and a one-time code is on its way &mdash; the link is good for one hour.';
        include 'auth_hero.php';
        ?>

        <main class="auth-panel">
            <div class="auth-card">
                <h2 class="auth-title">Forgot password</h2>
                <p class="auth-sub">Enter your email address and we'll send you an OTP to reset your password.</p>

                <?php if ($error): ?>
                    <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="fp_mail"><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" id="fp_mail" name="email" required autofocus autocomplete="email" maxlength="100" placeholder="Enter your registered email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-block auth-submit">
                        <i class="fas fa-paper-plane"></i> Send Reset OTP
                    </button>
                </form>

                <p class="login-footer-link auth-signup"><a href="login.php" class="login-link"><i class="fas fa-arrow-left"></i> Back to Login</a></p>

                <?php include 'auth_foot.php'; ?>
            </div>
        </main>
    </div>

    <?php include 'auth_theme.php'; ?>
</body>
</html>
