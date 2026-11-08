<?php
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
$current_page = 'account';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getAccountInfo':
                $stmt = @$conn->prepare("SELECT id, username, email, role, profile_image, created_at, theme_primary, theme_secondary, theme_accent, theme_mode FROM users WHERE id = ?");
                if (!$stmt) {
                    $stmt = $conn->prepare("SELECT id, username, email, role, profile_image, created_at FROM users WHERE id = ?");
                }

                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'email' => $user['email'],
                            'role' => $user['role'],
                            'profile_image' => $user['profile_image'],
                            'created_at' => $user['created_at'] ? date('c', strtotime($user['created_at'])) : null,
                            'allow_user_uploads' => $allowUserUploads,
                            'theme_primary' => $user['theme_primary'] ?? '#001f3f',
                            'theme_secondary' => $user['theme_secondary'] ?? '#003366',
                            'theme_accent' => $user['theme_accent'] ?? '#0074D9',
                            'theme_mode' => $user['theme_mode'] ?? 'light'
                        ]
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                }

                $stmt->close();
                exit();

            case 'saveTheme':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $theme_primary = isset($_POST['theme_primary']) ? trim($_POST['theme_primary']) : '#001f3f';
                $theme_secondary = isset($_POST['theme_secondary']) ? trim($_POST['theme_secondary']) : '#003366';
                $theme_accent = isset($_POST['theme_accent']) ? trim($_POST['theme_accent']) : '#0074D9';
                $theme_mode = isset($_POST['theme_mode']) ? trim($_POST['theme_mode']) : 'light';

                // Validate hex colors
                $color_pattern = '/^#[0-9A-Fa-f]{6}$/';
                if (!preg_match($color_pattern, $theme_primary) ||
                    !preg_match($color_pattern, $theme_secondary) ||
                    !preg_match($color_pattern, $theme_accent)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid color format. Use hex colors like #001f3f']);
                    exit();
                }

                // Validate theme mode
                if (!in_array($theme_mode, ['light', 'dark'])) {
                    $theme_mode = 'light';
                }

                $result = setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode);

                if ($result) {
                    logActivity($user_id, $username, 'Theme Updated', "Updated UI colors: Primary=$theme_primary, Secondary=$theme_secondary, Accent=$theme_accent, Mode=$theme_mode");
                    echo json_encode(['success' => true, 'message' => 'Theme settings saved successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to save theme settings']);
                }
                exit();

            case 'resetTheme':
                $result = setUserTheme($user_id, '#001f3f', '#003366', '#0074D9', 'light');

                if ($result) {
                    logActivity($user_id, $username, 'Theme Reset', 'Reset UI colors to default');
                    echo json_encode(['success' => true, 'message' => 'Theme reset to default']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to reset theme']);
                }
                exit();

            case 'updateProfile':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $newUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';

                // Validate inputs
                if (empty($newUsername) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                $newUsername = validateUsername($newUsername);
                if ($newUsername === false) {
                    echo json_encode(['success' => false, 'message' => 'Invalid username format. Use 3-50 alphanumeric characters.']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                // Check if username is already taken by another user
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->bind_param("si", $newUsername, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Username already taken']);
                    exit();
                }
                $stmt->close();

                // Update profile
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                $stmt->bind_param("ssi", $newUsername, $email, $user_id);

                if ($stmt->execute()) {
                    // Update session username if changed
                    $_SESSION['username'] = $newUsername;

                    // Log activity
                    logActivity($user_id, $newUsername, 'Profile Updated', 'Updated username and email');

                    $stmt->close();

                    echo json_encode(['success' => true, 'message' => 'Profile updated successfully', 'new_username' => $newUsername]);
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
                }
                exit();

            case 'changePassword':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
                $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
                $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

                // Validate inputs
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                if ($newPassword !== $confirmPassword) {
                    echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
                    exit();
                }

                $newPassword = validatePassword($newPassword);
                if ($newPassword === false) {
                    echo json_encode(['success' => false, 'message' => 'Password must be 6-255 characters']);
                    exit();
                }

                // Verify current password
                $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();

                    if (!password_verify($currentPassword, $user['password'])) {
                        $stmt->close();
                        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                        exit();
                    }
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }
                $stmt->close();

                // Update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashedPassword, $user_id);

                if ($stmt->execute()) {
                    // Log activity
                    logActivity($user_id, $username, 'Password Changed', 'User changed their password');

                    // Notify user
                    try { createNotification($user_id, 'Password Changed', 'Your password was changed successfully. If this wasn\'t you, contact an administrator immediately.', 'warning', 'account.php'); } catch (Exception $e) {}

                    $stmt->close();

                    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to change password']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Account.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle profile image upload (separate from AJAX JSON responses)
if (isset($_POST['action']) && $_POST['action'] === 'uploadProfileImage') {
    header('Content-Type: application/json');

    try {
        // admin-level can always upload; others honor the setting
        $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';
        if (!$allowUserUploads && !isAdminLevel($role)) {
            echo json_encode(['success' => false, 'message' => 'Profile uploads are currently disabled']);
            exit();
        }

        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
            exit();
        }

        // Upload the file
        $uploadResult = uploadProfileImage($_FILES['profile_image'], $user_id);

        if (!$uploadResult['success']) {
            echo json_encode($uploadResult);
            exit();
        }

        // Get old profile image
        $oldImage = getProfileImage($user_id);

        // Update database
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
        $stmt->bind_param("si", $uploadResult['filename'], $user_id);

        if ($stmt->execute()) {
            // Delete old image if exists
            if ($oldImage) {
                deleteProfileImage($oldImage);
            }

            // Log activity
            logActivity($user_id, $username, 'Profile Image Updated', 'User uploaded a new profile image');

            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'Profile image uploaded successfully',
                'image_url' => $uploadResult['filename']
            ]);
        } else {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Failed to update profile image']);
        }
    } catch (Exception $e) {
        error_log("Profile image upload error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

// If we reach here, render the HTML page
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>My Account - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>
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
                <span>My Account</span>
                <span class="breadcrumb-sep">/</span>
                <span>Profile</span>
            </div>
            <div class="header">
                <h1><i class="fas fa-user-circle"></i> My Account</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- Profile Image Section -->
            <div class="data-section mb-30">
                <div class="section-header">
                    <h2><i class="fas fa-image"></i> Profile Image</h2>
                </div>

                <div class="profile-section-grid">
                    <!-- Current Profile Image Display -->
                    <div class="profile-image-display">
                        <div class="profile-image-container">
                            <img id="currentProfileImage" src="" alt="Profile" class="profile-img-cover initially-hidden">
                            <i id="defaultProfileIcon" class="fas fa-user initially-hidden"></i>
                        </div>
                        <div class="profile-image-label">Current Image</div>
                    </div>

                    <!-- Upload Form -->
                    <div class="profile-upload-form">
                        <form id="profileImageForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label><i class="fas fa-upload"></i> Upload New Profile Image</label>
                                <input type="file" id="profileImageInput" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" class="file-input-styled">
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i> Accepted: JPG, PNG, GIF, WEBP (Max 2MB)
                                </div>
                            </div>

                            <div class="form-actions initially-hidden" id="uploadSection">
                                <button type="submit" class="btn btn-primary" id="uploadBtn">
                                    <i class="fas fa-upload"></i> Upload Image
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="cancelUpload()">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </form>

                        <div id="uploadDisabledMessage" class="warning-message initially-hidden">
                            <i class="fas fa-exclamation-triangle"></i> Profile image uploads are currently disabled by administrator.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Account Information Card -->
            <div class="account-info-card">
                <h3><i class="fas fa-info-circle"></i> Account Information</h3>
                <div class="info-row">
                    <div class="info-label"><i class="fas fa-user"></i> Username:</div>
                    <div class="info-value" id="display-username">Loading...</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="fas fa-envelope"></i> Email:</div>
                    <div class="info-value" id="display-email">Loading...</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="fas fa-user-tag"></i> Role:</div>
                    <div class="info-value" id="display-role">Loading...</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="fas fa-calendar-alt"></i> Member Since:</div>
                    <div class="info-value" id="display-created">Loading...</div>
                </div>
            </div>

            <!-- Update Profile Section -->
            <div class="data-section mb-30">
                <div class="section-header">
                    <h2><i class="fas fa-edit"></i> Update Profile</h2>
                </div>

                <form id="profileForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Username *</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email *</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password Section -->
            <div class="data-section mb-30">
                <div class="section-header">
                    <h2><i class="fas fa-lock"></i> Change Password</h2>
                </div>

                <form id="passwordForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Current Password *</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> New Password *</label>
                            <input type="password" id="new_password" name="new_password" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> Confirm New Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- UI Customization Section -->
            <div class="data-section" id="uiCustomizationSection">
                <div class="section-header">
                    <h2><i class="fas fa-palette"></i> UI Customization</h2>
                    <button class="btn btn-secondary" onclick="resetTheme()">
                        <i class="fas fa-undo"></i> Reset to Default
                    </button>
                </div>

                <p class="help-text ui-help-text">
                    <i class="fas fa-info-circle"></i> Customize your dashboard colors. Changes are saved per user and will apply across all pages.
                </p>

                <!-- Color Preview -->
                <div class="theme-preview-container" id="themePreview">
                    <h4 class="theme-preview-title"><i class="fas fa-eye"></i> Live Preview</h4>
                    <div class="theme-preview-swatches">
                        <div id="previewPrimary" class="color-preview-box">Primary</div>
                        <div id="previewSecondary" class="color-preview-box">Secondary</div>
                        <div id="previewAccent" class="color-preview-box">Accent</div>
                        <button class="btn preview-button-spacer" id="previewButton">
                            <i class="fas fa-check"></i> Sample Button
                        </button>
                    </div>
                </div>

                <form id="themeForm">
                    <div class="form-grid form-grid-3col">
                        <div class="form-group">
                            <label><i class="fas fa-square" id="primaryColorIcon"></i> Primary Color</label>
                            <div class="color-picker-row">
                                <input type="color" id="theme_primary" name="theme_primary" value="#001f3f" class="color-picker-input">
                                <input type="text" id="theme_primary_hex" value="#001f3f" maxlength="7" class="hex-input">
                            </div>
                            <small class="help-text color-help-text">Sidebar & headers</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-square" id="secondaryColorIcon"></i> Secondary Color</label>
                            <div class="color-picker-row">
                                <input type="color" id="theme_secondary" name="theme_secondary" value="#003366" class="color-picker-input">
                                <input type="text" id="theme_secondary_hex" value="#003366" maxlength="7" class="hex-input">
                            </div>
                            <small class="help-text color-help-text">Hover states & gradients</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-square" id="accentColorIcon"></i> Accent Color</label>
                            <div class="color-picker-row">
                                <input type="color" id="theme_accent" name="theme_accent" value="#0074D9" class="color-picker-input">
                                <input type="text" id="theme_accent_hex" value="#0074D9" maxlength="7" class="hex-input">
                            </div>
                            <small class="help-text color-help-text">Buttons & links</small>
                        </div>
                    </div>

                    <!-- Theme Mode -->
                    <div class="form-group mt-20">
                        <label><i class="fas fa-adjust"></i> Default Theme Mode</label>
                        <div class="theme-mode-options">
                            <label class="theme-mode-option" id="lightModeOption">
                                <input type="radio" name="theme_mode" value="light" id="theme_mode_light" checked>
                                <i class="fas fa-sun icon-sun"></i>
                                <span>Light Mode</span>
                            </label>
                            <label class="theme-mode-option" id="darkModeOption">
                                <input type="radio" name="theme_mode" value="dark" id="theme_mode_dark">
                                <i class="fas fa-moon icon-moon"></i>
                                <span>Dark Mode</span>
                            </label>
                        </div>
                    </div>

                    <!-- Preset Colors -->
                    <div class="form-group mt-25">
                        <label><i class="fas fa-swatchbook"></i> Quick Presets</label>
                        <div class="preset-colors-row">
                            <button type="button" class="preset-btn" onclick="applyPreset('#001f3f', '#003366', '#0074D9')" style="background: linear-gradient(135deg, #001f3f 50%, #0074D9 50%);" title="Navy Blue (Default)"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#1a1a2e', '#16213e', '#e94560')" style="background: linear-gradient(135deg, #1a1a2e 50%, #e94560 50%);" title="Dark Rose"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#2d3436', '#636e72', '#00b894')" style="background: linear-gradient(135deg, #2d3436 50%, #00b894 50%);" title="Emerald Dark"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#4a0e4e', '#810e7a', '#c92bc8')" style="background: linear-gradient(135deg, #4a0e4e 50%, #c92bc8 50%);" title="Purple Magic"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#1b4332', '#2d6a4f', '#40916c')" style="background: linear-gradient(135deg, #1b4332 50%, #40916c 50%);" title="Forest Green"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#7f5539', '#9c6644', '#dda15e')" style="background: linear-gradient(135deg, #7f5539 50%, #dda15e 50%);" title="Warm Brown"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#03045e', '#0077b6', '#00b4d8')" style="background: linear-gradient(135deg, #03045e 50%, #00b4d8 50%);" title="Ocean Blue"></button>
                            <button type="button" class="preset-btn" onclick="applyPreset('#3d0066', '#7b2cbf', '#c77dff')" style="background: linear-gradient(135deg, #3d0066 50%, #c77dff 50%);" title="Violet Dream"></button>
                        </div>
                    </div>

                    <div class="form-actions mt-30">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Theme Settings
                        </button>
                        <button type="button" class="btn btn-success" onclick="applyThemePreview()">
                            <i class="fas fa-eye"></i> Preview Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>

    <script>
        let allowUserUploads = true;

        // iso -> readable datetime
        function fmtDateTime(iso) {
            if (!iso) return '—';
            const d = new Date(iso);
            if (isNaN(d)) return '—';
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }

        $(document).ready(function() {
            loadAccountInfo();
        });

        function loadAccountInfo() {
            $.ajax({
                url: '?action=getAccountInfo',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;

                        // Display account info
                        document.getElementById('display-username').textContent = data.username;
                        document.getElementById('display-email').textContent = data.email;
                        const ROLE_LABELS = <?php
                            $rl = []; foreach (getRolesDetailed(false) as $r) $rl[$r['role_key']] = $r['label']; echo json_encode($rl);
                        ?>;
                        const ROLE_COLORS = <?php
                            $rc = []; foreach (getRolesDetailed(false) as $r) $rc[$r['role_key']] = $r['badge_color']; echo json_encode($rc);
                        ?>;
                        const _label = ROLE_LABELS[data.role] || data.role;
                        const _color = ROLE_COLORS[data.role] || '#0074D9';
                        document.getElementById('display-role').innerHTML =
                            `<span class="role-badge" style="background:${_color};color:#fff">${_label}</span>`;
                        document.getElementById('display-created').textContent = fmtDateTime(data.created_at);

                        // Populate form
                        document.getElementById('username').value = data.username;
                        document.getElementById('email').value = data.email;

                        // Handle profile image
                        allowUserUploads = data.allow_user_uploads;
                        if (data.profile_image) {
                            document.getElementById('currentProfileImage').src = data.profile_image;
                            document.getElementById('currentProfileImage').style.display = 'block';
                            document.getElementById('defaultProfileIcon').style.display = 'none';
                        } else {
                            document.getElementById('currentProfileImage').style.display = 'none';
                            document.getElementById('defaultProfileIcon').style.display = 'block';
                        }

                        // admin-level can always upload, others honor the toggle
                        const isAdminLevel = <?php echo isAdminLevel() ? 'true' : 'false'; ?>;
                        if (!allowUserUploads && !isAdminLevel) {
                            document.getElementById('profileImageInput').disabled = true;
                            document.getElementById('uploadDisabledMessage').style.display = 'block';
                        }

                        // Load theme values
                        loadThemeValues(data);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load account info'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not load account information'
                    });
                }
            });
        }

        // Profile image file selection
        document.getElementById('profileImageInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const file = this.files[0];

                // Validate file size
                if (file.size > 2 * 1024 * 1024) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Too Large',
                        text: 'Profile image must be less than 2MB'
                    });
                    this.value = '';
                    return;
                }

                // Show upload button
                document.getElementById('uploadSection').style.display = 'flex';

                // Preview image
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('currentProfileImage').src = e.target.result;
                    document.getElementById('currentProfileImage').style.display = 'block';
                    document.getElementById('defaultProfileIcon').style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });

        // Cancel upload
        function cancelUpload() {
            document.getElementById('profileImageInput').value = '';
            document.getElementById('uploadSection').style.display = 'none';
            loadAccountInfo(); // Reload to show original image
        }

        // Profile image upload form
        document.getElementById('profileImageForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData();
            const fileInput = document.getElementById('profileImageInput');

            if (!fileInput.files || !fileInput.files[0]) {
                Swal.fire({
                    icon: 'error',
                    title: 'No File Selected',
                    text: 'Please select an image to upload'
                });
                return;
            }

            formData.append('profile_image', fileInput.files[0]);
            formData.append('action', 'uploadProfileImage');

            Swal.fire({
                title: 'Uploading...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });

                        // Reset form and hide upload buttons
                        document.getElementById('profileImageInput').value = '';
                        document.getElementById('uploadSection').style.display = 'none';

                        // Reload account info to show new image
                        setTimeout(() => loadAccountInfo(), 500);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Upload Failed',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Upload Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Upload Failed',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Profile Update Form
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            Swal.fire({
                title: 'Updating Profile...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=updateProfile',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });

                        // Update displayed username if it changed
                        if (response.new_username) {
                            document.getElementById('display-username').textContent = response.new_username;
                        }

                        setTimeout(() => loadAccountInfo(), 100);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Password Change Form
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'New passwords do not match'
                });
                return;
            }

            const formData = new FormData(this);

            Swal.fire({
                title: 'Changing Password...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=changePassword',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });

                        // Clear password form
                        document.getElementById('passwordForm').reset();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // =============================================
        // THEME CUSTOMIZATION FUNCTIONS
        // =============================================

        // Sync color picker with hex input
        document.getElementById('theme_primary').addEventListener('input', function() {
            document.getElementById('theme_primary_hex').value = this.value.toUpperCase();
            updatePreview();
            updateColorIcons();
        });

        document.getElementById('theme_secondary').addEventListener('input', function() {
            document.getElementById('theme_secondary_hex').value = this.value.toUpperCase();
            updatePreview();
            updateColorIcons();
        });

        document.getElementById('theme_accent').addEventListener('input', function() {
            document.getElementById('theme_accent_hex').value = this.value.toUpperCase();
            updatePreview();
            updateColorIcons();
        });

        // Sync hex input with color picker
        document.getElementById('theme_primary_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('theme_primary').value = this.value;
                updatePreview();
                updateColorIcons();
            }
        });

        document.getElementById('theme_secondary_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('theme_secondary').value = this.value;
                updatePreview();
                updateColorIcons();
            }
        });

        document.getElementById('theme_accent_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('theme_accent').value = this.value;
                updatePreview();
                updateColorIcons();
            }
        });

        // Update preview colors
        function updatePreview() {
            const primary = document.getElementById('theme_primary').value;
            const secondary = document.getElementById('theme_secondary').value;
            const accent = document.getElementById('theme_accent').value;

            document.getElementById('previewPrimary').style.background = primary;
            document.getElementById('previewSecondary').style.background = secondary;
            document.getElementById('previewAccent').style.background = accent;
            document.getElementById('previewButton').style.background = primary;
            document.getElementById('previewButton').style.color = 'white';
        }

        // Update color icons
        function updateColorIcons() {
            document.getElementById('primaryColorIcon').style.color = document.getElementById('theme_primary').value;
            document.getElementById('secondaryColorIcon').style.color = document.getElementById('theme_secondary').value;
            document.getElementById('accentColorIcon').style.color = document.getElementById('theme_accent').value;
        }

        // Apply preset colors
        function applyPreset(primary, secondary, accent) {
            document.getElementById('theme_primary').value = primary;
            document.getElementById('theme_primary_hex').value = primary.toUpperCase();
            document.getElementById('theme_secondary').value = secondary;
            document.getElementById('theme_secondary_hex').value = secondary.toUpperCase();
            document.getElementById('theme_accent').value = accent;
            document.getElementById('theme_accent_hex').value = accent.toUpperCase();
            updatePreview();
            updateColorIcons();

            Swal.fire({
                icon: 'info',
                title: 'Preset Applied',
                text: 'Click "Save Theme Settings" to apply permanently',
                timer: 2000,
                showConfirmButton: false
            });
        }

        // Apply theme preview (live preview without saving)
        function applyThemePreview() {
            const primary = document.getElementById('theme_primary').value;
            const secondary = document.getElementById('theme_secondary').value;
            const accent = document.getElementById('theme_accent').value;

            document.documentElement.style.setProperty('--navy-primary', primary);
            document.documentElement.style.setProperty('--navy-light', secondary);
            document.documentElement.style.setProperty('--navy-dark', primary);
            document.documentElement.style.setProperty('--navy-hover', secondary);
            document.documentElement.style.setProperty('--navy-accent', accent);

            Swal.fire({
                icon: 'success',
                title: 'Preview Applied!',
                text: 'This is a temporary preview. Save to make it permanent.',
                timer: 3000,
                showConfirmButton: false
            });
        }

        // Save theme settings
        document.getElementById('themeForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData();
            formData.append('theme_primary', document.getElementById('theme_primary').value);
            formData.append('theme_secondary', document.getElementById('theme_secondary').value);
            formData.append('theme_accent', document.getElementById('theme_accent').value);
            formData.append('theme_mode', document.querySelector('input[name="theme_mode"]:checked').value);

            Swal.fire({
                title: 'Saving Theme...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=saveTheme',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Apply the theme
                        applyThemePreview();

                        // Save theme mode to localStorage
                        const themeMode = document.querySelector('input[name="theme_mode"]:checked').value;
                        localStorage.setItem('theme', themeMode);
                        if (themeMode === 'dark') {
                            document.body.classList.add('dark-mode');
                        } else {
                            document.body.classList.remove('dark-mode');
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Theme Saved!',
                            text: 'Your custom theme has been saved.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Reset theme to default
        function resetTheme() {
            Swal.fire({
                icon: 'warning',
                title: 'Reset Theme?',
                text: 'This will reset your colors to the default Navy Blue theme.',
                showCancelButton: true,
                confirmButtonColor: '#001f3f',
                confirmButtonText: 'Yes, Reset',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '?action=resetTheme',
                        method: 'POST',
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Reset form values
                                document.getElementById('theme_primary').value = '#001f3f';
                                document.getElementById('theme_primary_hex').value = '#001F3F';
                                document.getElementById('theme_secondary').value = '#003366';
                                document.getElementById('theme_secondary_hex').value = '#003366';
                                document.getElementById('theme_accent').value = '#0074D9';
                                document.getElementById('theme_accent_hex').value = '#0074D9';
                                document.getElementById('theme_mode_light').checked = true;

                                // Apply default theme
                                document.documentElement.style.setProperty('--navy-primary', '#001f3f');
                                document.documentElement.style.setProperty('--navy-light', '#003366');
                                document.documentElement.style.setProperty('--navy-dark', '#001f3f');
                                document.documentElement.style.setProperty('--navy-hover', '#003366');
                                document.documentElement.style.setProperty('--navy-accent', '#0074D9');

                                // Set light mode
                                localStorage.setItem('theme', 'light');
                                document.body.classList.remove('dark-mode');

                                updatePreview();
                                updateColorIcons();

                                Swal.fire({
                                    icon: 'success',
                                    title: 'Theme Reset!',
                                    text: 'Colors have been reset to default.',
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: response.message
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Connection error: ' + error
                            });
                        }
                    });
                }
            });
        }

        // Load theme values into form when account info is loaded
        function loadThemeValues(data) {
            if (data.theme_primary) {
                document.getElementById('theme_primary').value = data.theme_primary;
                document.getElementById('theme_primary_hex').value = data.theme_primary.toUpperCase();
            }
            if (data.theme_secondary) {
                document.getElementById('theme_secondary').value = data.theme_secondary;
                document.getElementById('theme_secondary_hex').value = data.theme_secondary.toUpperCase();
            }
            if (data.theme_accent) {
                document.getElementById('theme_accent').value = data.theme_accent;
                document.getElementById('theme_accent_hex').value = data.theme_accent.toUpperCase();
            }
            if (data.theme_mode === 'dark') {
                document.getElementById('theme_mode_dark').checked = true;
            } else {
                document.getElementById('theme_mode_light').checked = true;
            }

            updatePreview();
            updateColorIcons();
        }

        // Scroll to UI Customization section when ?tab=settings
        if (new URLSearchParams(window.location.search).get('tab') === 'settings') {
            const section = document.getElementById('uiCustomizationSection');
            if (section) {
                setTimeout(function() {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 500);
            }
        }
    </script>
</body>
</html>
