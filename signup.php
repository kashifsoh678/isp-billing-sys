<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 */

require_once 'config.php';

// Check maintenance mode - block signup
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
$csrf_token = generateCSRFToken();

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

// Handle signup form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = isset($_POST['username']) ? $_POST['username'] : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

        // Input validation
        $username = validateUsername($username);
        $password = validatePassword($password);

        if ($username === false) {
            $error = 'Invalid username. Use 3-50 alphanumeric characters or underscores.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($password === false) {
            $error = 'Password must be between 6 and 255 characters.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $conn = getDBConnection();

                // Check if username already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $error = 'Username already taken. Please choose another.';
                    $stmt->close();
                } else {
                    $stmt->close();

                    // Check if email already exists
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0) {
                        $error = 'Email already registered. Please use another.';
                        $stmt->close();
                    } else {
                        $stmt->close();

                        // Hash password and create user
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $role = ROLE_COLLECTOR; // legacy 'User' was never a valid role key

                        $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("ssss", $username, $hashed_password, $email, $role);

                        if ($stmt->execute()) {
                            $new_user_id = $stmt->insert_id;
                            $stmt->close();

                            // Log the signup
                            logActivity($new_user_id, $username, 'Signup', 'New user registered');

                            // Notify admins about new registration
                            try { createNotificationForAdmins('New User Registered', 'User "' . htmlspecialchars($username) . '" has signed up.', 'info', 'users.php'); } catch (Exception $e) {}

                            // Check if email verification is enabled
                            $verification_enabled = getSetting('email_verification_enabled', '0');
                            $smtp_enabled = getSetting('smtp_enabled', '0');

                            if ($verification_enabled === '1' && $smtp_enabled === '1') {
                                // Send OTP email for verification
                                $otp = createEmailVerification($new_user_id, $email);
                                $emailBody = getOTPEmailTemplate($otp, 'verify');
                                sendEmail($email, 'Verify Your Email - ' . $branding['site_name'], $emailBody);

                                header("Location: verify_otp.php?email=" . urlencode($email));
                                exit();
                            } else {
                                // Auto-verify if email verification is disabled
                                $verify_stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
                                $verify_stmt->bind_param("i", $new_user_id);
                                $verify_stmt->execute();
                                $verify_stmt->close();
                            }

                            $success = 'Account created successfully! You can now login.';
                        } else {
                            $error = 'Registration failed. Please try again.';
                            $stmt->close();
                        }
                    }
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), "doesn't exist") !== false) {
                    $error = 'Database not set up. Please run <a href="setup.php" class="login-link">setup.php</a> first.';
                } else {
                    $error = 'An error occurred. Please contact administrator.';
                    error_log("Signup error: " . $e->getMessage());
                }
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
    <title>Sign Up - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body class="login-page">
    <div class="auth-shell">
        <?php
        $hero_headline = 'Set up your<br>operator account.';
        $hero_lede     = 'One login for billing, field complaints, stock and collections &mdash; with the access your role needs, nothing more.';
        include 'auth_hero.php';
        ?>

        <main class="auth-panel">
            <div class="auth-card">
                <h2 class="auth-title">Create account</h2>
                <p class="auth-sub">Register to use <?php echo htmlspecialchars($branding['site_name']); ?>.</p>

                <?php if ($error): ?>
                    <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="form-group">
                        <label for="su_user"><i class="fas fa-user"></i> Username</label>
                        <input type="text" id="su_user" name="username" required autofocus autocomplete="username" minlength="3" maxlength="50" placeholder="Choose a username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="su_mail"><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" id="su_mail" name="email" required autocomplete="email" maxlength="100" placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="su_pwd"><i class="fas fa-lock"></i> Password</label>
                        <div class="auth-input">
                            <input type="password" id="su_pwd" name="password" required autocomplete="new-password" minlength="6" placeholder="Minimum 6 characters">
                            <button type="button" class="auth-eye" onclick="rsPwd(this)" aria-label="Show password" title="Show password"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="su_pwd2"><i class="fas fa-lock"></i> Confirm Password</label>
                        <div class="auth-input">
                            <input type="password" id="su_pwd2" name="confirm_password" required autocomplete="new-password" minlength="6" placeholder="Confirm your password">
                            <button type="button" class="auth-eye" onclick="rsPwd(this)" aria-label="Show password" title="Show password"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block auth-submit">
                        <i class="fas fa-user-plus"></i> Sign Up
                    </button>
                </form>

                <?php if ($google_oauth_enabled): ?>
                <div class="oauth-divider">
                    <hr>
                    <span>or continue with</span>
                    <hr>
                </div>
                <a href="<?php echo htmlspecialchars($google_login_url); ?>" class="google-oauth-btn">
                    <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                    Sign up with Google
                </a>
                <?php endif; ?>

                <p class="login-footer-link auth-signup">Already have an account? <a href="login.php" class="login-link">Login here</a></p>

                <?php include 'auth_foot.php'; ?>
            </div>
        </main>
    </div>

    <?php include 'auth_theme.php'; ?>
</body>
</html>
