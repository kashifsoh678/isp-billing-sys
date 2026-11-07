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
$current_page = 'oauth_setup';

// system-level — super_admin only
if (!canAccessSystem($role)) {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'getOAuthSettings') {
        try {
            $enabled = getSetting('google_oauth_enabled', '0');
            $client_id = getSetting('google_client_id', '');
            $client_secret = getSetting('google_client_secret', '');
            $redirect_uri = getSetting('google_redirect_uri', '');

            // Mask credentials - show only if they exist (as masked)
            $has_client_id = !empty($client_id);
            $has_client_secret = !empty($client_secret);
            $has_redirect_uri = !empty($redirect_uri);

            echo json_encode([
                'success' => true,
                'data' => [
                    'google_oauth_enabled' => $enabled,
                    'has_client_id' => $has_client_id,
                    'has_client_secret' => $has_client_secret,
                    'has_redirect_uri' => $has_redirect_uri,
                    'redirect_uri_display' => $has_redirect_uri ? str_repeat('*', strlen($redirect_uri)) : ''
                ]
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'saveOAuthSettings') {
        try {
            $enabled = isset($_POST['google_oauth_enabled']) ? $_POST['google_oauth_enabled'] : '0';
            $client_id = isset($_POST['google_client_id']) ? trim($_POST['google_client_id']) : '';
            $client_secret = isset($_POST['google_client_secret']) ? trim($_POST['google_client_secret']) : '';
            $redirect_uri = isset($_POST['google_redirect_uri']) ? trim($_POST['google_redirect_uri']) : '';

            // Save enabled/disabled toggle
            setSetting('google_oauth_enabled', $enabled);

            // Only update credentials if new values are provided (not empty placeholder)
            if (!empty($client_id)) {
                setSetting('google_client_id', $client_id);
            }
            if (!empty($client_secret)) {
                setSetting('google_client_secret', $client_secret);
            }
            if (!empty($redirect_uri)) {
                setSetting('google_redirect_uri', $redirect_uri);
            }

            // Log activity
            $statusText = $enabled === '1' ? 'enabled' : 'disabled';
            logActivity($user_id, $username, 'OAuth Settings Updated', "Google OAuth $statusText");

            echo json_encode([
                'success' => true,
                'message' => 'Google OAuth settings saved successfully'
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error saving settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'clearOAuthCredentials') {
        try {
            setSetting('google_client_id', '');
            setSetting('google_client_secret', '');
            setSetting('google_redirect_uri', '');
            setSetting('google_oauth_enabled', '0');

            logActivity($user_id, $username, 'OAuth Credentials Cleared', 'Google OAuth credentials removed');

            echo json_encode([
                'success' => true,
                'message' => 'Google OAuth credentials cleared successfully'
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error clearing credentials: ' . $e->getMessage()]);
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
    <title>Google OAuth Setup - Dashboard System</title>

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
                <span>OAuth Setup</span>
            </div>
            <div class="header">
                <h1><i class="fab fa-google"></i> Google OAuth Setup</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card skeleton-card-mb">
                    <div class="skeleton skeleton-text-large skeleton-w-60 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-70"></div>
                </div>
                <div class="dashboard-grid-4 skeleton-grid-mb">
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon skeleton-mb-md"></div>
                        <div class="skeleton skeleton-text skeleton-w-60"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon skeleton-mb-md"></div>
                        <div class="skeleton skeleton-text skeleton-w-60"></div>
                    </div>
                </div>
            </div>

            <!-- OAuth Settings Content -->
            <div id="oauthContent" class="initially-hidden">
                <!-- Quick Actions -->
                <div class="quick-actions-bar">
                    <button class="btn btn-success" onclick="saveOAuthSettings()">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    <button class="btn btn-secondary" onclick="loadOAuthSettings()">
                        <i class="fas fa-sync"></i> Reload
                    </button>
                    <button class="btn btn-danger" onclick="clearCredentials()">
                        <i class="fas fa-trash"></i> Clear Credentials
                    </button>
                </div>

                <!-- Full Width: Enable/Disable Toggle -->
                <div class="settings-mega-card mb-24">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-navy">
                            <i class="fab fa-google"></i>
                        </div>
                        <div>
                            <h3 class="settings-card-title">Google OAuth Login</h3>
                            <p class="settings-card-subtitle">Enable or disable Google login & signup on your website</p>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon">
                                    <i class="fas fa-power-off"></i>
                                </div>
                                <div class="control-info">
                                    <div class="control-title">Google Login / Signup</div>
                                    <div class="control-desc">Allow users to login or register with their Google account. If a user clicks "Login with Google" and has no account, one is created automatically.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="googleOAuthEnabled" class="toggle-input-large">
                                    <label for="googleOAuthEnabled" class="toggle-label-large">
                                        <span class="toggle-slider-large"></span>
                                    </label>
                                </div>
                                <div class="toggle-status" id="oauthToggleStatus">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>
                        <div class="info-banner">
                            <i class="fas fa-info-circle"></i>
                            <span>You must add valid Google OAuth credentials below before enabling this feature. When enabled, "Login with Google" and "Sign up with Google" buttons will appear on the login & signup pages.</span>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Grid: Credentials + Status -->
                <div class="settings-grid-2x2">

                    <!-- Card 1: Credentials -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-accent">
                                <i class="fas fa-key"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">OAuth Credentials</h3>
                                <p class="settings-card-subtitle">Google Cloud Console credentials</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-group">
                                <label><i class="fas fa-id-card"></i> Client ID</label>
                                <div class="credential-input-wrapper">
                                    <input type="password" id="googleClientId" placeholder="Enter Google Client ID" autocomplete="off">
                                    <span class="credential-status" id="clientIdStatus"></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Client Secret</label>
                                <div class="credential-input-wrapper">
                                    <input type="password" id="googleClientSecret" placeholder="Enter Google Client Secret" autocomplete="off">
                                    <span class="credential-status" id="clientSecretStatus"></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-link"></i> Redirect URI</label>
                                <div class="credential-input-wrapper">
                                    <input type="password" id="googleRedirectUri" placeholder="e.g. http://localhost/PHP MYSQL LOGIN/oauth_callback.php" autocomplete="off">
                                    <span class="credential-status" id="redirectUriStatus"></span>
                                </div>
                            </div>
                            <div class="info-banner info-banner-top">
                                <i class="fas fa-eye-slash"></i>
                                <span>Credentials are always hidden for security. Enter new values to update.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Card 2: Configuration Status -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-light">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Configuration Status</h3>
                                <p class="settings-card-subtitle">Current OAuth setup status</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fab fa-google"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Google OAuth</div>
                                    <div class="security-feature-desc">Login/Signup feature</div>
                                </div>
                                <div class="security-feature-badge" id="statusOAuth">
                                    <i class="fas fa-times-circle"></i> Disabled
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Client ID</div>
                                    <div class="security-feature-desc">Google OAuth Client ID</div>
                                </div>
                                <div class="security-feature-badge" id="statusClientId">
                                    <i class="fas fa-times-circle"></i> Not Set
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Client Secret</div>
                                    <div class="security-feature-desc">Google OAuth Client Secret</div>
                                </div>
                                <div class="security-feature-badge" id="statusClientSecret">
                                    <i class="fas fa-times-circle"></i> Not Set
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-link"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Redirect URI</div>
                                    <div class="security-feature-desc">OAuth callback URL</div>
                                </div>
                                <div class="security-feature-badge" id="statusRedirectUri">
                                    <i class="fas fa-times-circle"></i> Not Set
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Full Width: Step-by-Step Setup Guide Table -->
                <div class="settings-mega-card mt-24">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-navy-accent">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <div>
                            <h3 class="settings-card-title">Step-by-Step Setup Guide</h3>
                            <p class="settings-card-subtitle">How to get Google OAuth credentials from Google Cloud Console</p>
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
                                <tr>
                                    <td class="step-num">1</td>
                                    <td class="step-name">Go to Google Cloud Console</td>
                                    <td>Open <strong>console.cloud.google.com</strong> and sign in with your Google account</td>
                                </tr>
                                <tr>
                                    <td class="step-num">2</td>
                                    <td class="step-name">Create a New Project</td>
                                    <td>Click the project dropdown (top-left) &rarr; <strong>New Project</strong> &rarr; Enter a project name &rarr; Click <strong>Create</strong></td>
                                </tr>
                                <tr>
                                    <td class="step-num">3</td>
                                    <td class="step-name">Go to APIs & Services</td>
                                    <td>From the left sidebar menu, click <strong>APIs & Services</strong> &rarr; then click <strong>OAuth consent screen</strong></td>
                                </tr>
                                <tr>
                                    <td class="step-num">4</td>
                                    <td class="step-name">Configure Consent Screen</td>
                                    <td>Select <strong>External</strong> user type &rarr; Click <strong>Create</strong> &rarr; Fill in App name, User support email, Developer email &rarr; Click <strong>Save and Continue</strong> through all steps</td>
                                </tr>
                                <tr>
                                    <td class="step-num">5</td>
                                    <td class="step-name">Add Scopes</td>
                                    <td>On the Scopes step, click <strong>Add or Remove Scopes</strong> &rarr; Select <strong>email</strong>, <strong>profile</strong>, and <strong>openid</strong> &rarr; Click <strong>Update</strong> &rarr; <strong>Save and Continue</strong></td>
                                </tr>
                                <tr>
                                    <td class="step-num">6</td>
                                    <td class="step-name">Add Test Users (Optional)</td>
                                    <td>If app is in <strong>Testing</strong> mode, add your Gmail address as a test user &rarr; Click <strong>Save and Continue</strong></td>
                                </tr>
                                <tr>
                                    <td class="step-num">7</td>
                                    <td class="step-name">Create OAuth Credentials</td>
                                    <td>Go to <strong>Credentials</strong> (left sidebar) &rarr; Click <strong>+ Create Credentials</strong> &rarr; Select <strong>OAuth 2.0 Client IDs</strong></td>
                                </tr>
                                <tr>
                                    <td class="step-num">8</td>
                                    <td class="step-name">Set Application Type</td>
                                    <td>Select <strong>Web application</strong> as the application type &rarr; Give it a name (e.g., "My Login System")</td>
                                </tr>
                                <tr>
                                    <td class="step-num">9</td>
                                    <td class="step-name">Add Authorized Redirect URI</td>
                                    <td>
                                        Under <strong>Authorized redirect URIs</strong>, click <strong>+ Add URI</strong> and enter your callback URL:<br>
                                        <code>
                                            <?php
                                            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                                            $host = $_SERVER['HTTP_HOST'];
                                            $path = dirname($_SERVER['SCRIPT_NAME']);
                                            echo $protocol . '://' . $host . $path . '/oauth_callback.php';
                                            ?>
                                        </code>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="step-num">10</td>
                                    <td class="step-name">Copy Client ID & Secret</td>
                                    <td>Click <strong>Create</strong> &rarr; A popup will show your <strong>Client ID</strong> and <strong>Client Secret</strong> &rarr; Copy both values and paste them in the credentials card above</td>
                                </tr>
                                <tr>
                                    <td class="step-num">11</td>
                                    <td class="step-name">Paste Credentials Here</td>
                                    <td>Paste <strong>Client ID</strong>, <strong>Client Secret</strong>, and the <strong>Redirect URI</strong> (same URL from step 9) in the OAuth Credentials card above &rarr; Click <strong>Save Settings</strong></td>
                                </tr>
                                <tr class="step-final">
                                    <td class="step-num">12</td>
                                    <td class="step-name">Enable & Test</td>
                                    <td>Turn ON the <strong>Google Login / Signup</strong> toggle &rarr; Click <strong>Save Settings</strong> &rarr; Visit your login page to see the "Login with Google" button</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="info-banner info-banner-inset">
                            <i class="fas fa-magic"></i>
                            <span><strong>How it works:</strong> When a user clicks "Login with Google" or "Sign up with Google", if they don't have an account yet, one is automatically created using their Google email and they are logged in directly. No extra steps needed.</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>

    <script>
        $(document).ready(function() {
            loadOAuthSettings();
        });

        function loadOAuthSettings() {
            $('#loadingSkeleton').show();
            $('#oauthContent').hide();

            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'getOAuthSettings' },
                dataType: 'json',
                success: function(response) {
                    setTimeout(() => {
                        $('#loadingSkeleton').hide();
                        $('#oauthContent').fadeIn(300);
                    }, 500);

                    if (response.success) {
                        const data = response.data;

                        // Set toggle
                        const isEnabled = data.google_oauth_enabled === '1';
                        document.getElementById('googleOAuthEnabled').checked = isEnabled;
                        updateOAuthToggleStatus(isEnabled);

                        // Clear input fields (credentials stay hidden)
                        document.getElementById('googleClientId').value = '';
                        document.getElementById('googleClientSecret').value = '';
                        document.getElementById('googleRedirectUri').value = '';

                        // Update placeholders to show status
                        document.getElementById('googleClientId').placeholder = data.has_client_id ? '••••••••••••••••••••••• (saved)' : 'Enter Google Client ID';
                        document.getElementById('googleClientSecret').placeholder = data.has_client_secret ? '••••••••••••••••••••••• (saved)' : 'Enter Google Client Secret';
                        document.getElementById('googleRedirectUri').placeholder = data.has_redirect_uri ? '••••••••••••••••••••••• (saved)' : 'e.g. http://localhost/PHP MYSQL LOGIN/oauth_callback.php';

                        // Update status badges
                        updateStatusBadge('statusOAuth', isEnabled);
                        updateStatusBadge('statusClientId', data.has_client_id);
                        updateStatusBadge('statusClientSecret', data.has_client_secret);
                        updateStatusBadge('statusRedirectUri', data.has_redirect_uri);

                        // Update credential status indicators
                        updateCredentialStatus('clientIdStatus', data.has_client_id);
                        updateCredentialStatus('clientSecretStatus', data.has_client_secret);
                        updateCredentialStatus('redirectUriStatus', data.has_redirect_uri);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load settings' });
                    }
                },
                error: function() {
                    $('#loadingSkeleton').hide();
                    $('#oauthContent').show();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
                }
            });
        }

        function saveOAuthSettings() {
            const enabled = document.getElementById('googleOAuthEnabled').checked ? '1' : '0';
            const clientId = document.getElementById('googleClientId').value.trim();
            const clientSecret = document.getElementById('googleClientSecret').value.trim();
            const redirectUri = document.getElementById('googleRedirectUri').value.trim();

            Swal.fire({
                title: 'Saving OAuth Settings...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'saveOAuthSettings',
                    google_oauth_enabled: enabled,
                    google_client_id: clientId,
                    google_client_secret: clientSecret,
                    google_redirect_uri: redirectUri
                },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });
                        // Reload to update status
                        setTimeout(() => loadOAuthSettings(), 2100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to save settings.' });
                }
            });
        }

        function clearCredentials() {
            Swal.fire({
                title: 'Clear All Credentials?',
                text: 'This will remove all Google OAuth credentials and disable the feature.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--danger)',
                confirmButtonText: 'Yes, clear all!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: { action: 'clearOAuthCredentials' },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Cleared!',
                                    text: response.message,
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                                setTimeout(() => loadOAuthSettings(), 2100);
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                            }
                        }
                    });
                }
            });
        }

        function updateOAuthToggleStatus(isEnabled) {
            const statusElement = document.getElementById('oauthToggleStatus');
            const statusDot = statusElement.querySelector('.status-dot');
            const statusText = statusElement.querySelector('.status-text');

            if (isEnabled) {
                statusDot.classList.remove('status-disabled');
                statusDot.classList.add('status-enabled');
                statusText.textContent = 'Enabled';
                statusText.className = 'status-text text-success';
            } else {
                statusDot.classList.remove('status-enabled');
                statusDot.classList.add('status-disabled');
                statusText.textContent = 'Disabled';
                statusText.className = 'status-text text-muted';
            }
        }

        function updateStatusBadge(elementId, isActive) {
            const badge = document.getElementById(elementId);
            if (isActive) {
                badge.className = 'security-feature-badge active';
                badge.innerHTML = '<i class="fas fa-check-circle"></i> ' + (elementId === 'statusOAuth' ? 'Enabled' : 'Configured');
            } else {
                badge.className = 'security-feature-badge stat-icon-danger';
                badge.innerHTML = '<i class="fas fa-times-circle"></i> ' + (elementId === 'statusOAuth' ? 'Disabled' : 'Not Set');
            }
        }

        function updateCredentialStatus(elementId, hasValue) {
            const el = document.getElementById(elementId);
            if (hasValue) {
                el.innerHTML = '<i class="fas fa-check-circle text-success"></i>';
            } else {
                el.innerHTML = '<i class="fas fa-exclamation-circle text-warning"></i>';
            }
        }

        // Toggle event listener
        $(document).on('change', '#googleOAuthEnabled', function() {
            updateOAuthToggleStatus(this.checked);
        });
    </script>
</body>
</html>
