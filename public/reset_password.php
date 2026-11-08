<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Reset Password Page (OTP + New Password)
 */

require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: forgot_password.php");
    exit();
}

// Get site branding
$branding = getSiteBranding();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $otp = isset($_POST['otp']) ? trim($_POST['otp']) : '';
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    if (strlen($otp) !== 6 || !ctype_digit($otp)) {
        $error = 'Please enter a valid 6-digit OTP code.';
    } elseif (strlen($new_password) < 6 || strlen($new_password) > 255) {
        $error = 'Password must be between 6 and 255 characters.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        if (verifyPasswordResetOTP($email, $otp)) {
            // Update password
            try {
                $conn = getDBConnection();
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $hashed, $email);

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $stmt->close();

                    // Get user for logging
                    $log_stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ?");
                    $log_stmt->bind_param("s", $email);
                    $log_stmt->execute();
                    $log_result = $log_stmt->get_result();
                    if ($log_result->num_rows > 0) {
                        $log_user = $log_result->fetch_assoc();
                        logActivity($log_user['id'], $log_user['username'], 'Password Reset', 'Password reset via email OTP');
                    }
                    $log_stmt->close();

                    header("Location: login.php?reset=1");
                    exit();
                } else {
                    $stmt->close();
                    $error = 'Failed to update password. Please try again.';
                }
            } catch (Exception $e) {
                $error = 'An error occurred. Please try again.';
                error_log("Password reset error: " . $e->getMessage());
            }
        } else {
            $error = 'Invalid or expired OTP code. Please request a new one.';
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
    <title>Reset Password - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body class="login-page">
    <div class="auth-shell">
        <?php
        $hero_headline = 'New password,<br>back to work.';
        $hero_lede     = 'Pick something you haven&rsquo;t used before. Six characters minimum, and the code expires in an hour.';
        include 'auth_hero.php';
        ?>

        <main class="auth-panel">
            <div class="auth-card">
                <h2 class="auth-title">Reset password</h2>
                <p class="auth-sub">Enter the OTP sent to <strong class="highlight"><?php echo htmlspecialchars($email); ?></strong> and your new password.</p>

                <?php if ($error): ?>
                    <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="rp_otp"><i class="fas fa-key"></i> OTP Code</label>
                        <input type="text" id="rp_otp" name="otp" required autofocus maxlength="6" minlength="6" pattern="[0-9]{6}" placeholder="000000" class="otp-input" autocomplete="one-time-code" inputmode="numeric">
                    </div>

                    <div class="form-group">
                        <label for="rp_pwd"><i class="fas fa-lock"></i> New Password</label>
                        <div class="auth-input">
                            <input type="password" id="rp_pwd" name="new_password" required autocomplete="new-password" minlength="6" placeholder="Minimum 6 characters">
                            <button type="button" class="auth-eye" onclick="rsPwd(this)" aria-label="Show password" title="Show password"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="rp_pwd2"><i class="fas fa-lock"></i> Confirm Password</label>
                        <div class="auth-input">
                            <input type="password" id="rp_pwd2" name="confirm_password" required autocomplete="new-password" minlength="6" placeholder="Confirm new password">
                            <button type="button" class="auth-eye" onclick="rsPwd(this)" aria-label="Show password" title="Show password"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block auth-submit">
                        <i class="fas fa-save"></i> Reset Password
                    </button>
                </form>

                <p class="login-footer-link auth-signup">
                    <a href="forgot_password.php" class="login-link"><i class="fas fa-redo"></i> Request New OTP</a>
                    &nbsp;&middot;&nbsp;
                    <a href="login.php" class="login-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
                </p>

                <?php include 'auth_foot.php'; ?>
            </div>
        </main>
    </div>

    <?php include 'auth_theme.php'; ?>
</body>
</html>
