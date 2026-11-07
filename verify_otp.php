<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Email OTP Verification Page
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
    header("Location: login.php");
    exit();
}

// Get site branding
$branding = getSiteBranding();

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'resend') {
        // Resend OTP
        try {
            $conn = getDBConnection();
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND email_verified = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $stmt->close();

                $otp = createEmailVerification($user['id'], $email);
                $emailBody = getOTPEmailTemplate($otp, 'verify');
                $emailResult = sendEmail($email, 'Verify Your Email - ' . $branding['site_name'], $emailBody);

                if ($emailResult['success']) {
                    $success = 'A new OTP has been sent to your email.';
                } else {
                    $error = 'Failed to send OTP. Please try again.';
                }
            } else {
                $stmt->close();
                header("Location: login.php");
                exit();
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
        }
    } elseif (isset($_POST['otp'])) {
        // Verify OTP
        $otp = trim($_POST['otp']);

        if (strlen($otp) !== 6 || !ctype_digit($otp)) {
            $error = 'Please enter a valid 6-digit OTP code.';
        } else {
            if (verifyEmailOTP($email, $otp)) {
                header("Location: login.php?verified=1");
                exit();
            } else {
                $error = 'Invalid or expired OTP code. Please try again.';
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
    <title>Verify Email - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body class="login-page">
    <div class="auth-shell">
        <?php
        $hero_headline = 'One code away<br>from your dashboard.';
        $hero_lede     = 'We&rsquo;ve emailed a six-digit code to confirm the address is really yours. It lands in seconds.';
        include 'auth_hero.php';
        ?>

        <main class="auth-panel">
            <div class="auth-card">
                <h2 class="auth-title">Verify your email</h2>
                <p class="auth-sub">We sent a 6-digit OTP code to <strong class="highlight"><?php echo htmlspecialchars($email); ?></strong></p>

                <?php if ($error): ?>
                    <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="vo_otp"><i class="fas fa-key"></i> Enter OTP Code</label>
                        <input type="text" id="vo_otp" name="otp" required autofocus maxlength="6" minlength="6" pattern="[0-9]{6}" placeholder="000000" class="otp-input" autocomplete="one-time-code" inputmode="numeric">
                    </div>

                    <button type="submit" class="btn btn-primary btn-block auth-submit">
                        <i class="fas fa-check-circle"></i> Verify Email
                    </button>
                </form>

                <div class="resend-section">
                    <p class="resend-countdown" id="resendText">
                        Didn't receive the code? Wait <span id="countdown" class="timer">60</span>s
                    </p>
                    <form method="POST" action="" id="resendForm" class="initially-hidden">
                        <input type="hidden" name="action" value="resend">
                        <button type="submit" class="btn btn-secondary btn-block">
                            <i class="fas fa-redo"></i> Resend OTP
                        </button>
                    </form>
                </div>

                <p class="login-footer-link auth-signup"><a href="login.php" class="login-link"><i class="fas fa-arrow-left"></i> Back to Login</a></p>

                <?php include 'auth_foot.php'; ?>
            </div>
        </main>
    </div>

    <?php include 'auth_theme.php'; ?>

    <script>
    // resend cooldown
    let countdown = 60;
    const countdownEl = document.getElementById('countdown');
    const resendText  = document.getElementById('resendText');
    const resendForm  = document.getElementById('resendForm');

    const timer = setInterval(function () {
        countdown--;
        if (countdownEl) countdownEl.textContent = countdown;
        if (countdown > 0) return;
        clearInterval(timer);
        resendText.classList.add('initially-hidden');
        resendForm.classList.remove('initially-hidden');
    }, 1000);
    </script>
</body>
</html>
