<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 */

require_once 'config.php';

// gate: super_admin only (system-level)
if (!isset($_SESSION['user_id']) || !canAccessSystem()) {
    header("Location: dashboard.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'backup';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getBackupInfo':
                $tables = [];
                $totalSize = 0;

                $result = $conn->query("SHOW TABLE STATUS");
                while ($row = $result->fetch_assoc()) {
                    $size = $row['Data_length'] + $row['Index_length'];
                    $tables[] = [
                        'name' => $row['Name'],
                        'rows' => $row['Rows'],
                        'size' => round($size / 1024, 2)
                    ];
                    $totalSize += $size;
                }

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'database' => DB_NAME,
                        'tables' => $tables,
                        'total_tables' => count($tables),
                        'total_size' => round($totalSize / 1024, 2)
                    ]
                ]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle backup download
if (isset($_POST['action']) && $_POST['action'] === 'downloadBackup') {
    try {
        $conn = getDBConnection();

        $sql = "-- Database Backup\n";
        $sql .= "-- Database: " . DB_NAME . "\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- By: " . $username . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

        $tables_result = $conn->query("SHOW TABLES");
        while ($table_row = $tables_result->fetch_row()) {
            $table = $table_row[0];

            // Get CREATE TABLE statement
            $create_result = $conn->query("SHOW CREATE TABLE `$table`");
            $create_row = $create_result->fetch_row();

            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $create_row[1] . ";\n\n";

            // Get table data
            $data_result = $conn->query("SELECT * FROM `$table`");
            if ($data_result->num_rows > 0) {
                // Get column names
                $fields = $data_result->fetch_fields();
                $columns = array_map(function($f) { return '`' . $f->name . '`'; }, $fields);
                $column_list = implode(', ', $columns);

                while ($data_row = $data_result->fetch_assoc()) {
                    $values = [];
                    foreach ($data_row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . $conn->real_escape_string($value) . "'";
                        }
                    }
                    $sql .= "INSERT INTO `$table` ($column_list) VALUES (" . implode(', ', $values) . ");\n";
                }
                $sql .= "\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        // Log activity
        logActivity($user_id, $username, 'Backup Downloaded', 'Database backup downloaded');

        // Send as download
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="backup_' . DB_NAME . '_' . date('Y-m-d_His') . '.sql"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit();

    } catch (Exception $e) {
        error_log("Backup error: " . $e->getMessage());
        header("Location: backup.php?error=backup_failed");
        exit();
    }
}

// Handle restore
if (isset($_POST['action']) && $_POST['action'] === 'restoreBackup') {
    header('Content-Type: application/json');

    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
        exit();
    }

    $file = $_FILES['backup_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext !== 'sql') {
        echo json_encode(['success' => false, 'message' => 'Only .sql files are allowed']);
        exit();
    }

    if ($file['size'] > 50 * 1024 * 1024) { // 50MB limit
        echo json_encode(['success' => false, 'message' => 'File too large (max 50MB)']);
        exit();
    }

    try {
        $sql_content = file_get_contents($file['tmp_name']);

        if (empty($sql_content)) {
            echo json_encode(['success' => false, 'message' => 'Empty SQL file']);
            exit();
        }

        // Buffer output to prevent PHP warnings from corrupting JSON
        ob_start();

        $conn = getDBConnection();

        // Execute SQL
        if (!$conn->multi_query($sql_content)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'SQL Error: ' . $conn->error]);
            exit();
        }

        // Process all results
        $errors = [];
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
            if ($conn->error) {
                $errors[] = $conn->error;
            }
        } while ($conn->more_results() && $conn->next_result());

        // Check final error too
        if ($conn->error && !in_array($conn->error, $errors)) {
            $errors[] = $conn->error;
        }

        ob_end_clean();

        if (!empty($errors)) {
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'SQL Errors: ' . implode('; ', array_slice($errors, 0, 3))]);
            exit();
        }

        // Close and re-establish connection after multi_query
        $conn->close();

        // Log activity with fresh connection
        try { logActivity($user_id, $username, 'Backup Restored', 'Database restored from: ' . $file['name']); } catch (Exception $e) {}

        echo json_encode(['success' => true, 'message' => 'Database restored successfully']);
        exit();

    } catch (Exception $e) {
        if (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
        exit();
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
    <title>Backup - <?php echo htmlspecialchars(getSiteBranding()['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body class="initially-hidden">
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>System</span>
                <span class="breadcrumb-sep">/</span>
                <span>Backup</span>
            </div>

            <div class="header">
                <h1><i class="fas fa-database"></i> Database Backup</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'backup_failed'): ?>
            <div class="info-banner info-banner-warning mb-24">
                <i class="fas fa-exclamation-triangle"></i> Backup download failed. Please try again.
            </div>
            <?php endif; ?>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card skeleton-card-mb">
                    <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-70"></div>
                </div>
            </div>

            <!-- Backup Content -->
            <div id="backupContent" class="initially-hidden">

                <!-- 2-Column Grid: Download + Restore -->
                <div class="settings-grid-2x2">

                    <!-- Download Backup Card -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-navy">
                                <i class="fas fa-download"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Download Backup</h3>
                                <p class="settings-card-subtitle">Export your database as a .sql file</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="stat-item-inline">
                                <div class="stat-item-icon"><i class="fas fa-database"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Database</div>
                                    <div class="stat-item-value" id="dbName">-</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon"><i class="fas fa-table"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Tables</div>
                                    <div class="stat-item-value" id="dbTables">-</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon"><i class="fas fa-weight-hanging"></i></div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Total Size</div>
                                    <div class="stat-item-value" id="dbSize">-</div>
                                </div>
                            </div>

                            <hr class="card-divider">

                            <form method="POST" action="backup.php" id="downloadForm">
                                <input type="hidden" name="action" value="downloadBackup">
                                <button type="submit" class="btn btn-primary btn-block" id="downloadBtn">
                                    <i class="fas fa-download"></i> Download .sql Backup
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Restore Backup Card -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-warning">
                                <i class="fas fa-upload"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Restore Backup</h3>
                                <p class="settings-card-subtitle">Import a .sql backup file</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="info-banner info-banner-warning mb-24">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span><strong>Warning:</strong> Restoring a backup will overwrite existing data. Make sure to download a backup first!</span>
                            </div>

                            <div class="form-group">
                                <label><i class="fas fa-file-upload"></i> Select .sql File</label>
                                <input type="file" id="restoreFile" accept=".sql">
                            </div>

                            <button type="button" class="btn btn-danger btn-block" onclick="restoreBackup()" id="restoreBtn">
                                <i class="fas fa-upload"></i> Restore Database
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Table Details Card (Full Width) -->
                <div class="settings-mega-card">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-navy">
                            <i class="fas fa-list"></i>
                        </div>
                        <div>
                            <h3 class="settings-card-title">Database Tables</h3>
                            <p class="settings-card-subtitle">Overview of all tables in your database</p>
                        </div>
                    </div>
                    <div class="settings-card-body card-body-flush-scroll">
                        <table class="setup-guide-table" id="tableListTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Table Name</th>
                                    <th>Rows</th>
                                    <th>Size</th>
                                </tr>
                            </thead>
                            <tbody id="tableListBody">
                                <tr>
                                    <td colspan="4">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="info-banner info-banner-inset">
                            <i class="fas fa-info-circle"></i>
                            <span><strong>Tip:</strong> Download a backup regularly to keep your data safe. The .sql file can be restored anytime from this page or via phpMyAdmin.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script>
    function loadBackupInfo() {
        $('#loadingSkeleton').show();
        $('#backupContent').hide();

        $.ajax({
            url: 'backup.php?action=getBackupInfo',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                setTimeout(function() {
                    $('#loadingSkeleton').hide();
                    $('#backupContent').fadeIn(300);
                }, 400);

                if (response.success) {
                    var data = response.data;
                    $('#dbName').text(data.database);
                    $('#dbTables').text(data.total_tables);
                    $('#dbSize').text(data.total_size + ' KB');

                    // Build table list using setup-guide-table pattern
                    var html = '';
                    data.tables.forEach(function(t, i) {
                        html += '<tr>';
                        html += '<td class="step-num">' + (i + 1) + '</td>';
                        html += '<td class="step-name"><i class="fas fa-table"></i> ' + t.name + '</td>';
                        html += '<td>' + t.rows + '</td>';
                        html += '<td>' + t.size + ' KB</td>';
                        html += '</tr>';
                    });
                    html += '<tr class="step-final">';
                    html += '<td class="step-num"><i class="fas fa-sigma"></i></td>';
                    html += '<td class="step-name">Total</td>';
                    html += '<td><strong>' + data.total_tables + ' tables</strong></td>';
                    html += '<td><strong>' + data.total_size + ' KB</strong></td>';
                    html += '</tr>';
                    $('#tableListBody').html(html);
                }
            },
            error: function() {
                $('#loadingSkeleton').hide();
                $('#backupContent').show();
            }
        });
    }

    function restoreBackup() {
        var fileInput = document.getElementById('restoreFile');
        if (!fileInput.files.length) {
            Swal.fire('No File', 'Please select a .sql file first.', 'warning');
            return;
        }

        Swal.fire({
            title: 'Restore Database?',
            html: '<strong class="text-danger">This will overwrite all existing data!</strong><br><br>Are you absolutely sure?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Yes, Restore!',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (result.isConfirmed) {
                var formData = new FormData();
                formData.append('action', 'restoreBackup');
                formData.append('backup_file', fileInput.files[0]);

                Swal.fire({ title: 'Restoring...', text: 'Please wait while the database is being restored.', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });

                $.ajax({
                    url: 'backup.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('Restored!', response.message, 'success').then(function() {
                                loadBackupInfo();
                            });
                        } else {
                            Swal.fire('Error', response.message, 'error');
                        }
                    },
                    error: function(xhr) {
                        var msg = 'Restore request failed';
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.message) msg = resp.message;
                        } catch(e) {
                            if (xhr.responseText) msg = 'Server error: ' + xhr.responseText.substring(0, 200);
                        }
                        Swal.fire('Error', msg, 'error');
                    }
                });
            }
        });
    }

    $(document).ready(function() {
        document.body.classList.remove('initially-hidden');
        loadBackupInfo();
    });
    </script>
</body>
</html>