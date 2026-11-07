<?php
 
require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Check maintenance mode
$is_maintenance = isMaintenanceMode();
$maintenance_login = isset($_GET['maintenance']) && $_GET['maintenance'] === '1';

// If maintenance is ON and user is NOT coming via maintenance page link, redirect
if ($is_maintenance && !$maintenance_login) {
    header("Location: maintenance.php");
    exit();
}

$error = '';
$success = '';
$csrf_token = generateCSRFToken();

// Check for success messages
if (isset($_GET['verified']) && $_GET['verified'] == '1') {
    $success = 'Email verified successfully! You can now login.';
}
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    $success = 'Password reset successfully! You can now login with your new password.';
}

// Check for OAuth error messages
if (isset($_GET['error'])) {
    $oauth_errors = [
        'oauth_denied' => 'Google login was cancelled.',
        'oauth_token_failed' => 'Google authentication failed. Please try again.',
        'oauth_userinfo_failed' => 'Could not get Google account info. Please try again.',
        'oauth_create_failed' => 'Could not create account. Please try again.',
        'oauth_not_configured' => 'Google login is not configured. Contact administrator.',
        'oauth_error' => 'An error occurred with Google login. Please try again.'
    ];
    $error = isset($oauth_errors[$_GET['error']]) ? $oauth_errors[$_GET['error']] : '';
}

// Check if Forgot Password is enabled
$show_forgot_password = getSetting('show_forgot_password', '1') === '1';

// Check if Google OAuth is enabled
$google_oauth_enabled = false;
$google_login_url = '';
try {
    $oauth_enabled = getSetting('google_oauth_enabled', '0');
    if ($oauth_enabled === '1') {
        $g_client_id = getSetting('google_client_id', '');
        $g_redirect_uri = getSetting('google_redirect_uri', '');
        if (!empty($g_client_id) && !empty($g_redirect_uri)) {
            $google_oauth_enabled = true;
            $google_login_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $g_client_id,
                'redirect_uri' => $g_redirect_uri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'access_type' => 'online',
                'prompt' => 'select_account'
            ]);
        }
    }
} catch (Exception $e) {
    // OAuth not available, silently skip
}

// Get site branding
$branding = getSiteBranding();

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = isset($_POST['username']) ? $_POST['username'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        // Input validation
        $username = validateUsername($username);
        $password = validatePassword($password);

        if ($username === false) {
            $error = 'Invalid username format. Use 3-50 alphanumeric characters.';
        } elseif ($password === false) {
            $error = 'Invalid password format.';
        } else {
            // Check rate limiting
            if (checkLoginAttempts($username)) {
                $error = 'Too many login attempts. Please try again in 15 minutes.';
            } else {
                try {
                    $conn = getDBConnection();

                    $stmt = $conn->prepare("SELECT id, username, password, role, email, email_verified FROM users WHERE username = ?");
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                } catch (Exception $e) {
                    // Check if it's a table/column not found error
                    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown column") !== false) {
                        $error = 'Database not set up. Please run <a href="setup.php" class="login-link">setup.php</a> first.';
                    } else {
                        $error = 'Database error. Please contact administrator.';
                        error_log("Login error: " . $e->getMessage());
                    }
                    if (isset($stmt)) $stmt->close();
                    goto skip_login;
                }

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();

                    if (password_verify($password, $user['password'])) {
                        // only super_admin can log in during maintenance
                        if ($is_maintenance && !canBypassMaintenance($user['role'])) {
                            $error = 'Only the super admin can login during maintenance mode.';
                            $stmt->close();
                            goto skip_login;
                        }
                        // block inactive accounts
                        if (isset($user['status']) && $user['status'] === 'inactive') {
                            $error = 'Your account is inactive. Contact the administrator.';
                            $stmt->close();
                            goto skip_login;
                        }
                        if (isset($user['is_deleted']) && (int)$user['is_deleted'] === 1) {
                            $error = 'This account no longer exists.';
                            $stmt->close();
                            goto skip_login;
                        }

                        // Check if email is verified (when verification is enabled)
                        $verification_required = getSetting('email_verification_enabled', '0') === '1';
                        $email_verified = isset($user['email_verified']) ? $user['email_verified'] : 1;

                        if ($verification_required && !$email_verified) {
                            // Resend OTP and redirect to verification page
                            $otp = createEmailVerification($user['id'], $user['email']);
                            $emailBody = getOTPEmailTemplate($otp, 'verify');
                            sendEmail($user['email'], 'Verify Your Email - ' . $branding['site_name'], $emailBody);

                            $stmt->close();
                            header("Location: verify_otp.php?email=" . urlencode($user['email']));
                            exit();
                        }

                        // Clear failed login attempts
                        clearLoginAttempts($username);

                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['LAST_ACTIVITY'] = time();

                        // Handle Remember Me
                        $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
                        if ($remember_me) {
                            try {
                                createRememberToken($user['id']);
                            } catch (Exception $e) {
                                // Remember me table may not exist yet, skip
                            }
                        }

                        // Log successful login
                        logActivity($user['id'], $user['username'], 'Login', 'User logged in successfully');

                        // stamp last login
                        try {
                            $ll = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                            $ll->bind_param("i", $user['id']);
                            $ll->execute();
                            $ll->close();
                        } catch (Exception $e) {}

                        // Track session
                        try { trackUserSession($user['id']); } catch (Exception $e) {}

                        $stmt->close();

                        header("Location: dashboard.php");
                        exit();
                    } else {
                        recordLoginAttempt($username);
                        // Alert admins on login attempt spike
                        try {
                            $conn_la = getDBConnection();
                            $stmt_la = $conn_la->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                            $stmt_la->bind_param("s", $username);
                            $stmt_la->execute();
                            $la_count = $stmt_la->get_result()->fetch_assoc()['attempts'];
                            $stmt_la->close();
                            if ($la_count >= 5) {
                                $dedup = $conn_la->prepare("SELECT id FROM notifications WHERE title = 'Login Alert: Failed Attempts' AND message LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
                                $like_str = '%"' . $conn_la->real_escape_string($username) . '"%';
                                $dedup->bind_param("s", $like_str);
                                $dedup->execute();
                                if ($dedup->get_result()->num_rows == 0) {
                                    createNotificationForAdmins('Login Alert: Failed Attempts', $la_count . ' failed login attempts for "' . htmlspecialchars($username) . '" in last 15 min from IP ' . $_SERVER['REMOTE_ADDR'], 'danger', 'logs.php');
                                }
                                $dedup->close();
                            }
                        } catch (Exception $e) {}
                        $error = 'Invalid username or password';
                    }
                } else {
                    recordLoginAttempt($username);
                    $error = 'Invalid username or password';
                }

                $stmt->close();
            }
        }
        skip_login:
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
    <title>Login - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body class="login-page">
    <div class="auth-shell">

        <!-- ── brand side: the network, drawn ── -->
        <?php include 'auth_hero.php'; ?>

        <!-- ── sign-in side ── -->
        <main class="auth-panel">
            <div class="auth-card">
                <h2 class="auth-title">Welcome back</h2>
                <p class="auth-sub">Sign in to continue to <?php echo htmlspecialchars($branding['site_name']); ?>.</p>

                <?php if ($success): ?>
                    <div class="success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="form-group">
                        <label for="username"><i class="fas fa-user"></i> Username</label>
                        <div class="auth-input">
                            <input type="text" id="username" name="username" placeholder="Your username" required autofocus autocomplete="username" minlength="3" maxlength="50">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password</label>
                        <div class="auth-input">
                            <input type="password" id="password" name="password" placeholder="Your password" required autocomplete="current-password" minlength="6">
                            <button type="button" class="auth-eye" onclick="rsPwd(this)" aria-label="Show password" title="Show password"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>

                    <div class="login-options-row">
                        <label class="remember-me-label">
                            <input type="checkbox" name="remember_me" value="1">
                            Remember Me
                        </label>
                        <?php if ($show_forgot_password && !($is_maintenance && $maintenance_login)): ?>
                        <a href="forgot_password.php" class="login-link">Forgot Password?</a>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block auth-submit">
                        <i class="fas fa-right-to-bracket"></i> Login
                    </button>
                </form>

                <?php if ($is_maintenance && $maintenance_login): ?>
                <div class="info-banner info-banner-warning auth-maint">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Maintenance Mode Active - Admin Login Only</span>
                </div>
                <?php else: ?>
                    <?php if ($google_oauth_enabled): ?>
                    <div class="oauth-divider">
                        <hr>
                        <span>or continue with</span>
                        <hr>
                    </div>
                    <a href="<?php echo htmlspecialchars($google_login_url); ?>" class="google-oauth-btn">
                        <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                        Login with Google
                    </a>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="auth-secure">
                    <i class="fas fa-shield-halved"></i> Rate-limited sign-in with CSRF protection
                </div>

                <?php if (!($is_maintenance && $maintenance_login)): ?>
                <p class="login-footer-link auth-signup">Don't have an account? <a href="signup.php" class="login-link">Sign Up</a></p>
                <?php endif; ?>

                <?php include 'auth_foot.php'; ?>
            </div>
        </main>
    </div>

    <?php include 'auth_theme.php'; ?>
</body>
</html>
