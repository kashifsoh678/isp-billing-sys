<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 */

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
$current_page = 'smtp_setup';

// system-level — super_admin only
if (!canAccessSystem($role)) {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'getSmtpSettings') {
        try {
            $enabled = getSetting('smtp_enabled', '0');
            $host = getSetting('smtp_host', '');
            $port = getSetting('smtp_port', '587');
            $smtp_user = getSetting('smtp_username', '');
            $smtp_pass = getSetting('smtp_password', '');
            $from_email = getSetting('smtp_from_email', '');
            $from_name = getSetting('smtp_from_name', 'Dashboard System');
            $encryption = getSetting('smtp_encryption', 'tls');
            $verification_enabled = getSetting('email_verification_enabled', '0');
            $show_forgot_password = getSetting('show_forgot_password', '1');

            echo json_encode([
                'success' => true,
                'data' => [
                    'smtp_enabled' => $enabled,
                    'smtp_host' => $host,
                    'smtp_port' => $port,
                    'has_username' => !empty($smtp_user),
                    'has_password' => !empty($smtp_pass),
                    'smtp_from_email' => $from_email,
                    'smtp_from_name' => $from_name,
                    'smtp_encryption' => $encryption,
                    'email_verification_enabled' => $verification_enabled,
                    'show_forgot_password' => $show_forgot_password
                ]
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'saveSmtpSettings') {
        try {
            $fields = ['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name', 'smtp_encryption', 'email_verification_enabled', 'show_forgot_password'];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $value = trim($_POST[$field]);
                    // Only update username/password if not the placeholder
                    if (($field === 'smtp_username' || $field === 'smtp_password') && $value === '********') {
                        continue;
                    }
                    setSetting($field, $value);
                }
            }

            logActivity($user_id, $username, 'SMTP Updated', 'SMTP settings updated');

            echo json_encode(['success' => true, 'message' => 'SMTP settings saved successfully']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error saving settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'testSmtpEmail') {
        try {
            $test_email = isset($_POST['test_email']) ? trim($_POST['test_email']) : '';
            if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
                exit();
            }

            $branding = getSiteBranding();
            $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
                . '<div style="background:#001f3f;padding:20px;text-align:center;border-radius:8px 8px 0 0;">'
                . '<h1 style="color:#fff;margin:0;font-size:22px;">' . htmlspecialchars($branding['site_name']) . '</h1>'
                . '</div>'
                . '<div style="background:#fff;padding:30px;border:1px solid #e9ecef;border-top:none;">'
                . '<h2 style="color:#333;margin-top:0;">SMTP Test Successful!</h2>'
                . '<p style="color:#666;">If you are reading this email, your SMTP configuration is working correctly.</p>'
                . '<div style="background:#d4edda;padding:15px;border-radius:8px;margin:20px 0;border:1px solid #c3e6cb;">'
                . '<p style="color:#155724;margin:0;font-weight:bold;"><i>&#10004;</i> SMTP Connection Verified</p>'
                . '</div>'
                . '<p style="color:#999;font-size:13px;">Sent from ' . htmlspecialchars($branding['site_name']) . ' admin panel.</p>'
                . '</div></div>';

            $result = sendEmail($test_email, 'SMTP Test - ' . $branding['site_name'], $htmlBody);

            logActivity($user_id, $username, 'SMTP Test', 'Test email sent to ' . $test_email . ': ' . ($result['success'] ? 'Success' : 'Failed'));

            echo json_encode($result);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'clearSmtpCredentials') {
        try {
            $clear_fields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name'];
            foreach ($clear_fields as $field) {
                setSetting($field, '');
            }
            setSetting('smtp_port', '587');
            setSetting('smtp_from_name', 'Dashboard System');
            setSetting('smtp_encryption', 'tls');
            setSetting('smtp_enabled', '0');
            setSetting('email_verification_enabled', '0');
            setSetting('show_forgot_password', '1');

            logActivity($user_id, $username, 'SMTP Cleared', 'SMTP credentials cleared');

            echo json_encode(['success' => true, 'message' => 'SMTP credentials cleared successfully']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
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
    <title>SMTP Setup - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>System</span>
                <span class="breadcrumb-sep">/</span>
                <span>SMTP Setup</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-envelope"></i> SMTP Email Setup</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card skeleton-card-mb">
                    <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-70"></div>
                </div>
            </div>

            <!-- SMTP Content -->
            <div id="smtpContent" class="initially-hidden">

                <!-- Enable/Disable SMTP (Full Width) -->
                <div class="settings-mega-card mb-24">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-navy">
                            <i class="fas fa-power-off"></i>
                        </div>
                        <div>
                            <h3 class="settings-card-title">SMTP Email Service</h3>
                            <p class="settings-card-subtitle">Enable or disable SMTP email functionality</p>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-envelope"></i></div>
                                <div class="control-info">
                                    <div class="control-title">SMTP Email</div>
                                    <div class="control-desc">Enable SMTP for sending OTP verification & password reset emails</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="smtpEnabled" class="toggle-input-large">
                                    <label for="smtpEnabled" class="toggle-label-large">
                                        <span class="toggle-slider-large"></span>
                                    </label>
                                </div>
                                <div class="toggle-status" id="smtpToggleStatus">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>
                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-shield-alt"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Email Verification on Signup</div>
                                    <div class="control-desc">Require OTP email verification when users sign up manually</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="emailVerificationEnabled" class="toggle-input-large">
                                    <label for="emailVerificationEnabled" class="toggle-label-large">
                                        <span class="toggle-slider-large"></span>
                                    </label>
                                </div>
                                <div class="toggle-status" id="verificationToggleStatus">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>
                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-key"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Forgot Password on Login</div>
                                    <div class="control-desc">Show the "Forgot Password?" link on the login page</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="showForgotPassword" class="toggle-input-large">
                                    <label for="showForgotPassword" class="toggle-label-large">
                                        <span class="toggle-slider-large"></span>
                                    </label>
                                </div>
                                <div class="toggle-status" id="forgotPasswordToggleStatus">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Grid: Credentials + Status -->
                <div class="settings-grid-2x2">

                    <!-- SMTP Credentials Card -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-navy">
                                <i class="fas fa-key"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">SMTP Credentials</h3>
                                <p class="settings-card-subtitle">Enter your SMTP server details (hidden on screen)</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-group">
                                <label><i class="fas fa-server"></i> SMTP Host</label>
                                <input type="password" id="smtpHost" placeholder="e.g. smtp.gmail.com">
                            </div>
                            <div class="smtp-form-grid">
                                <div class="form-group">
                                    <label><i class="fas fa-hashtag"></i> Port</label>
                                    <input type="password" id="smtpPort" placeholder="587">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-lock"></i> Encryption</label>
                                    <select id="smtpEncryption" class="smtp-select">
                                        <option value="tls">TLS (Port 587)</option>
                                        <option value="ssl">SSL (Port 465)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> SMTP Username</label>
                                <input type="password" id="smtpUsername" placeholder="e.g. your@gmail.com">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-key"></i> SMTP Password</label>
                                <input type="password" id="smtpPassword" placeholder="App password or email password">
                            </div>
                            <div class="smtp-form-grid">
                                <div class="form-group">
                                    <label><i class="fas fa-at"></i> From Email</label>
                                    <input type="password" id="smtpFromEmail" placeholder="noreply@yourdomain.com">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-tag"></i> From Name</label>
                                    <input type="text" id="smtpFromName" placeholder="Dashboard System">
                                </div>
                            </div>
                            <div class="smtp-actions">
                                <button class="btn btn-success" onclick="saveSmtpSettings()">
                                    <i class="fas fa-save"></i> Save Settings
                                </button>
                                <button class="btn btn-secondary" onclick="clearSmtpCredentials()">
                                    <i class="fas fa-trash"></i> Clear
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Connection Status & Test Card -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-success">
                                <i class="fas fa-plug"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Connection Status</h3>
                                <p class="settings-card-subtitle">Test your SMTP configuration</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="stat-item-inline">
                                <div class="stat-item-icon">
                                    <i class="fas fa-server"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">SMTP Host</div>
                                    <div class="stat-item-value" id="statusHost">Not configured</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Credentials</div>
                                    <div class="stat-item-value" id="statusCredentials">Not set</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Encryption</div>
                                    <div class="stat-item-value" id="statusEncryption">TLS</div>
                                </div>
                            </div>

                            <hr class="card-divider">

                            <div class="form-group">
                                <label><i class="fas fa-paper-plane"></i> Send Test Email</label>
                                <input type="email" id="testEmail" placeholder="Enter email to receive test">
                            </div>
                            <button class="btn btn-primary btn-block" onclick="sendTestEmail()">
                                <i class="fas fa-paper-plane"></i> Send Test Email
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step-by-Step Guide Table (Full Width) -->
                <div class="settings-mega-card">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-navy">
                            <i class="fas fa-book"></i>
                        </div>
                        <div>
                            <h3 class="settings-card-title">SMTP Setup Guide</h3>
                            <p class="settings-card-subtitle">Step-by-step instructions for Gmail & Hostinger SMTP</p>
                        </div>
                    </div>
                    <div class="settings-card-body card-body-flush-scroll">
                        <table class="setup-guide-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Step</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="step-section-header">
                                    <td colspan="3">
                                        <i class="fab fa-google"></i> Gmail SMTP Setup (Free - 500 emails/day)
                                    </td>
                                </tr>
                                <tr>
                                    <td class="step-num">1</td>
                                    <td class="step-name">Open Google Account</td>
                                    <td>Go to <strong>myaccount.google.com</strong> and sign in with your Gmail</td>
                                </tr>
                                <tr>
                                    <td class="step-num">2</td>
                                    <td class="step-name">Go to Security</td>
                                    <td>Click <strong>Security</strong> in the left sidebar</td>
                                </tr>
                                <tr>
                                    <td class="step-num">3</td>
                                    <td class="step-name">Enable 2-Step Verification</td>
                                    <td>Under "Signing in to Google", enable <strong>2-Step Verification</strong> (required for App Passwords)</td>
                                </tr>
                                <tr>
                                    <td class="step-num">4</td>
                                    <td class="step-name">Create App Password</td>
                                    <td>Go to <strong>Security &rarr; 2-Step Verification &rarr; App passwords</strong> (or search "App passwords" in account settings)</td>
                                </tr>
                                <tr>
                                    <td class="step-num">5</td>
                                    <td class="step-name">Generate Password</td>
                                    <td>Enter app name (e.g. "Dashboard SMTP"), click <strong>Create</strong>. Copy the <strong>16-character password</strong></td>
                                </tr>
                                <tr>
                                    <td class="step-num">6</td>
                                    <td class="step-name">Enter Gmail SMTP Settings</td>
                                    <td>
                                        <strong>Host:</strong> smtp.gmail.com<br>
                                        <strong>Port:</strong> 587<br>
                                        <strong>Encryption:</strong> TLS<br>
                                        <strong>Username:</strong> your@gmail.com<br>
                                        <strong>Password:</strong> 16-char App Password<br>
                                        <strong>From Email:</strong> your@gmail.com
                                    </td>
                                </tr>
                                <tr class="step-section-header">
                                    <td colspan="3">
                                        <i class="fas fa-globe"></i> Hostinger SMTP Setup
                                    </td>
                                </tr>
                                <tr>
                                    <td class="step-num">7</td>
                                    <td class="step-name">Log in to Hostinger</td>
                                    <td>Go to <strong>hPanel &rarr; Emails &rarr; Email Accounts</strong></td>
                                </tr>
                                <tr>
                                    <td class="step-num">8</td>
                                    <td class="step-name">Create Email Account</td>
                                    <td>Create an email like <strong>noreply@yourdomain.com</strong> and set a password</td>
                                </tr>
                                <tr>
                                    <td class="step-num">9</td>
                                    <td class="step-name">Enter Hostinger SMTP Settings</td>
                                    <td>
                                        <strong>Host:</strong> smtp.hostinger.com<br>
                                        <strong>Port:</strong> 465<br>
                                        <strong>Encryption:</strong> SSL<br>
                                        <strong>Username:</strong> noreply@yourdomain.com<br>
                                        <strong>Password:</strong> your email password<br>
                                        <strong>From Email:</strong> noreply@yourdomain.com
                                    </td>
                                </tr>
                                <tr class="step-section-header">
                                    <td colspan="3">
                                        <i class="fas fa-check-circle"></i> Final Steps
                                    </td>
                                </tr>
                                <tr>
                                    <td class="step-num">10</td>
                                    <td class="step-name">Save & Test</td>
                                    <td>Click <strong>Save Settings</strong>, then use <strong>Send Test Email</strong> to verify everything works</td>
                                </tr>
                                <tr>
                                    <td class="step-num">11</td>
                                    <td class="step-name">Enable SMTP</td>
                                    <td>Toggle <strong>SMTP Email</strong> to ON at the top of this page</td>
                                </tr>
                                <tr class="step-final">
                                    <td class="step-num">12</td>
                                    <td class="step-name">Enable Email Verification</td>
                                    <td>Toggle <strong>Email Verification on Signup</strong> to ON to require OTP verification for new manual signups</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="info-banner info-banner-inset">
                            <i class="fas fa-magic"></i>
                            <span><strong>How it works:</strong> When SMTP is enabled, the system can send OTP verification emails on signup and password reset emails. Gmail allows 500 free emails/day.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>

    <script>
        $(document).ready(function() { loadSmtpSettings(); });

        function loadSmtpSettings() {
            $('#loadingSkeleton').show();
            $('#smtpContent').hide();

            $.ajax({
                url: '', method: 'POST',
                data: { action: 'getSmtpSettings' },
                dataType: 'json',
                success: function(response) {
                    setTimeout(() => {
                        $('#loadingSkeleton').hide();
                        $('#smtpContent').fadeIn(300);
                    }, 400);

                    if (response.success) {
                        const d = response.data;

                        // Toggles
                        $('#smtpEnabled').prop('checked', d.smtp_enabled === '1');
                        updateToggleStatus('smtpToggleStatus', d.smtp_enabled === '1');
                        $('#emailVerificationEnabled').prop('checked', d.email_verification_enabled === '1');
                        updateToggleStatus('verificationToggleStatus', d.email_verification_enabled === '1');
                        $('#showForgotPassword').prop('checked', d.show_forgot_password === '1');
                        updateToggleStatus('forgotPasswordToggleStatus', d.show_forgot_password === '1');

                        // Credentials (show host/port/from for convenience, mask sensitive)
                        if (d.smtp_host) $('#smtpHost').val(d.smtp_host);
                        if (d.smtp_port) $('#smtpPort').val(d.smtp_port);
                        $('#smtpEncryption').val(d.smtp_encryption || 'tls');
                        if (d.has_username) $('#smtpUsername').val('********');
                        if (d.has_password) $('#smtpPassword').val('********');
                        if (d.smtp_from_email) $('#smtpFromEmail').val(d.smtp_from_email);
                        if (d.smtp_from_name) $('#smtpFromName').val(d.smtp_from_name);

                        // Status card
                        $('#statusHost').text(d.smtp_host || 'Not configured');
                        $('#statusCredentials').html(d.has_username && d.has_password
                            ? '<span class="text-success">Configured</span>'
                            : '<span class="text-danger">Not set</span>');
                        $('#statusEncryption').text((d.smtp_encryption || 'tls').toUpperCase() + ' (Port ' + (d.smtp_port || '587') + ')');
                    }
                },
                error: function() {
                    $('#loadingSkeleton').hide();
                    $('#smtpContent').show();
                }
            });
        }

        function saveSmtpSettings() {
            Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '', method: 'POST',
                data: {
                    action: 'saveSmtpSettings',
                    smtp_enabled: $('#smtpEnabled').is(':checked') ? '1' : '0',
                    smtp_host: $('#smtpHost').val(),
                    smtp_port: $('#smtpPort').val(),
                    smtp_username: $('#smtpUsername').val(),
                    smtp_password: $('#smtpPassword').val(),
                    smtp_from_email: $('#smtpFromEmail').val(),
                    smtp_from_name: $('#smtpFromName').val(),
                    smtp_encryption: $('#smtpEncryption').val(),
                    email_verification_enabled: $('#emailVerificationEnabled').is(':checked') ? '1' : '0',
                    show_forgot_password: $('#showForgotPassword').is(':checked') ? '1' : '0'
                },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Saved!', text: response.message, timer: 2000, showConfirmButton: false });
                        loadSmtpSettings();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to save settings' });
                }
            });
        }

        function sendTestEmail() {
            const email = $('#testEmail').val().trim();
            if (!email) {
                Swal.fire({ icon: 'warning', title: 'Enter Email', text: 'Please enter an email address to send the test to.' });
                return;
            }

            Swal.fire({ title: 'Sending Test Email...', text: 'This may take a few seconds', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '', method: 'POST',
                data: { action: 'testSmtpEmail', test_email: email },
                dataType: 'json',
                timeout: 30000,
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Test Email Sent!', text: 'Check your inbox at ' + email });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Failed', text: response.message });
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Request timed out. Check your SMTP settings.' });
                }
            });
        }

        function clearSmtpCredentials() {
            Swal.fire({
                title: 'Clear SMTP Credentials?',
                text: 'This will remove all SMTP settings and disable email.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, Clear All'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '', method: 'POST',
                        data: { action: 'clearSmtpCredentials' },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', title: 'Cleared!', text: response.message, timer: 2000, showConfirmButton: false });
                                loadSmtpSettings();
                            }
                        }
                    });
                }
            });
        }

        function updateToggleStatus(elementId, isEnabled) {
            const el = document.getElementById(elementId);
            const dot = el.querySelector('.status-dot');
            const text = el.querySelector('.status-text');
            if (isEnabled) {
                dot.classList.remove('status-disabled');
                dot.classList.add('status-enabled');
                text.textContent = 'Enabled';
                text.className = 'status-text text-success';
            } else {
                dot.classList.remove('status-enabled');
                dot.classList.add('status-disabled');
                text.textContent = 'Disabled';
                text.className = 'status-text text-muted';
            }
        }

        $(document).on('change', '#smtpEnabled', function() { updateToggleStatus('smtpToggleStatus', this.checked); });
        $(document).on('change', '#emailVerificationEnabled', function() { updateToggleStatus('verificationToggleStatus', this.checked); });
        $(document).on('change', '#showForgotPassword', function() { updateToggleStatus('forgotPasswordToggleStatus', this.checked); });
    </script>
</body>
</html>
