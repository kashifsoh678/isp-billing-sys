<?php
/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 */

require_once 'config.php';
require_once 'update_setup.php';   // applyUpdates() + addColumnIfMissing() — single schema source
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Database Setup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
</head>
<body>
    <div class="setup-wrapper">
    <div class="setup-container">
        <h2><i class="fas fa-database"></i> Database Setup</h2>
        <p class="subtitle">Setting up your ISP Billing database tables...</p>
        <?php if (!isset($_GET['reset'])): ?>
        <div class="setup-reset-bar">
            <button type="button" class="btn btn-danger" onclick="confirmReset()">
                <i class="fas fa-bomb"></i> Drop ALL tables &amp; reseed
            </button>
            <span class="muted">— wipes all data and recreates schema + demo data from scratch</span>
        </div>
        <?php endif; ?>
        <hr>

        <?php
        $errors = [];
        $success = [];

        // force mysqli to throw on any error so we see failures
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // Create connection using config.php credentials
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        // Check connection
        if ($conn->connect_error) {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Connection failed: ' . $conn->connect_error . '</div>';
            die();
        }

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Database connection successful</div>';

        // ─── RESET MODE ─────────────────────────────────────────────────
        // setup.php?reset=1 — drop ALL tables then recreate + reseed from scratch
        $reset_mode = isset($_GET['reset']) && $_GET['reset'] === '1';
        if ($reset_mode) {
            echo '<br><div class="log-item log-warning"><i class="fas fa-bomb"></i> <strong>RESET MODE — dropping all tables...</strong></div>';
            try {
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                $drop_tables = [
                    // child → parent (order matters less w/ FK off, but kept logical)
                    'stock_movement_items', 'stock_movements', 'stock_items', 'stock_categories',
                    'ledger_entries', 'role_permissions', 'user_areas', 'roles',
                    'collection_visits', 'collection_runs', 'vendors', 'bank_accounts',
                    'call_logs', 'payment_invoice_allocations', 'complaint_attachments',
                    'customer_documents', 'equipment', 'leads',
                    'payments', 'invoices', 'complaints', 'expense_payments', 'expenses', 'customers',
                    'packages', 'areas',
                    'notifications', 'user_sessions', 'login_attempts',
                    'remember_tokens', 'password_resets', 'email_verifications',
                    'activity_logs', 'system_settings',
                    'users'
                ];
                foreach ($drop_tables as $t) {
                    $conn->query("DROP TABLE IF EXISTS `$t`");
                    echo '<div class="log-item log-info"><i class="fas fa-trash"></i> Dropped table "' . $t . '"</div>';
                }
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> All tables dropped — recreating fresh...</div><br>';
            } catch (Throwable $e) {
                echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Reset error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
        // ─── /RESET MODE ────────────────────────────────────────────────

        // users table — ISP roles
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) NOT NULL,
            role ENUM('super_admin','admin','accountant','collector') NOT NULL DEFAULT 'collector',
            last_login DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "users" created successfully or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating table: ' . $conn->error . '</div>';
        }

        // role col exists?
        $check_column = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
        if ($check_column && $check_column->num_rows == 0) {
            $alter_sql = "ALTER TABLE users ADD COLUMN role ENUM('super_admin','admin','accountant','collector') NOT NULL DEFAULT 'collector' AFTER email";
            if ($conn->query($alter_sql) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Column "role" added to users table</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error adding role column: ' . $conn->error . '</div>';
            }
        } else {
            // migrate legacy role enum (Admin/User → super_admin/collector)
            $col = $check_column->fetch_assoc();
            $type = $col['Type'] ?? '';
            if (stripos($type, "'Admin'") !== false || stripos($type, "'User'") !== false) {
                echo '<div class="log-item log-info"><i class="fas fa-sync"></i> Legacy role enum detected — migrating to ISP roles...</div>';
                $conn->query("ALTER TABLE users MODIFY COLUMN role VARCHAR(20) NOT NULL DEFAULT 'collector'");
                $conn->query("UPDATE users SET role = 'super_admin' WHERE role = 'Admin'");
                $conn->query("UPDATE users SET role = 'collector' WHERE role = 'User' OR role = '' OR role IS NULL");
                if ($conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','admin','accountant','collector') NOT NULL DEFAULT 'collector'") === TRUE) {
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Role enum migrated: Admin→super_admin, User→collector</div>';
                } else {
                    echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error migrating role enum: ' . $conn->error . '</div>';
                }
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Column "role" already on ISP enum</div>';
            }
        }

        // null role → collector
        $conn->query("UPDATE users SET role = 'collector' WHERE role IS NULL OR role = ''");

        // ISP user fields: name, phone, area_id, status, is_deleted, updated_at
        $isp_user_cols = [
            'name'       => "ALTER TABLE users ADD COLUMN name VARCHAR(100) NOT NULL DEFAULT '' AFTER username",
            'phone'      => "ALTER TABLE users ADD COLUMN phone VARCHAR(20) NOT NULL DEFAULT '' AFTER email",
            'area_id'    => "ALTER TABLE users ADD COLUMN area_id INT(11) DEFAULT NULL AFTER phone",
            'status'     => "ALTER TABLE users ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER role",
            'is_deleted' => "ALTER TABLE users ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
            'updated_at' => "ALTER TABLE users ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
        ];
        foreach ($isp_user_cols as $col_name => $alter_sql) {
            $check = $conn->query("SHOW COLUMNS FROM users LIKE '$col_name'");
            if ($check && $check->num_rows == 0) {
                if ($conn->query($alter_sql) === TRUE) {
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Column "' . $col_name . '" added to users table</div>';
                } else {
                    echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error adding ' . $col_name . ' column: ' . $conn->error . '</div>';
                }
            }
        }

        // backfill name from username for existing rows
        $conn->query("UPDATE users SET name = username WHERE name = '' OR name IS NULL");

        // areas table — ISP service areas
        $areas_sql = "CREATE TABLE IF NOT EXISTS areas (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            city VARCHAR(100) NOT NULL DEFAULT '',
            description TEXT,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_areas_name (name),
            INDEX idx_status (status),
            INDEX idx_deleted (is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($areas_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "areas" created or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating areas table: ' . $conn->error . '</div>';
        }

        // areas migration: drop "code", add "city", ensure UNIQUE on name
        $check_code = $conn->query("SHOW COLUMNS FROM areas LIKE 'code'");
        if ($check_code && $check_code->num_rows > 0) {
            if ($conn->query("ALTER TABLE areas DROP COLUMN code") === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Dropped legacy "code" column from areas</div>';
            }
        }
        $check_city = $conn->query("SHOW COLUMNS FROM areas LIKE 'city'");
        if ($check_city && $check_city->num_rows == 0) {
            $conn->query("ALTER TABLE areas ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT '' AFTER name");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Added "city" column to areas</div>';
        }
        // ensure UNIQUE on name (idempotent)
        $check_uk = $conn->query("SHOW INDEX FROM areas WHERE Key_name='uk_areas_name'");
        if ($check_uk && $check_uk->num_rows == 0) {
            @$conn->query("ALTER TABLE areas ADD UNIQUE KEY uk_areas_name (name)");
        }

        // remove placeholder "Main Area" if no users attached
        $ph = $conn->query("SELECT id FROM areas WHERE name='Main Area' LIMIT 1");
        if ($ph && $ph->num_rows > 0) {
            $ph_row = $ph->fetch_assoc();
            $u_check = $conn->query("SELECT COUNT(*) AS c FROM users WHERE area_id = " . (int)$ph_row['id']);
            if ((int)($u_check->fetch_assoc()['c'] ?? 1) === 0) {
                $conn->query("DELETE FROM areas WHERE id = " . (int)$ph_row['id']);
            }
        }

        // seed demo areas — generic, YouTube-policy friendly
        $demo_areas = [
            ['Area 1', 'Demo City', 'Demo coverage area 1 — residential zone'],
            ['Area 2', 'Demo City', 'Demo coverage area 2 — mixed residential / commercial'],
            ['Area 3', 'Demo City', 'Demo coverage area 3 — outer expansion zone']
        ];
        foreach ($demo_areas as [$a_name, $a_city, $a_desc]) {
            $chk = $conn->prepare("SELECT id FROM areas WHERE name = ?");
            $chk->bind_param("s", $a_name);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $ins = $conn->prepare("INSERT INTO areas (name, city, description) VALUES (?, ?, ?)");
                $ins->bind_param("sss", $a_name, $a_city, $a_desc);
                $ins->execute();
                $ins->close();
            }
            $chk->close();
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo service areas seeded</div>';

        // ─── DEFAULT super_admin (must exist before ISP seeds reference it) ───
        $default_username = 'admin';
        $default_password = password_hash('admin123', PASSWORD_DEFAULT);
        $default_email    = 'admin@demo.com';
        $default_role     = 'super_admin';
        $default_name     = 'System Administrator';

        $stmt = $conn->prepare("SELECT id, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $default_username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            $has_name_col = $conn->query("SHOW COLUMNS FROM users LIKE 'name'");
            if ($has_name_col && $has_name_col->num_rows > 0) {
                $insert_stmt = $conn->prepare("INSERT INTO users (username, name, password, email, role) VALUES (?, ?, ?, ?, ?)");
                $insert_stmt->bind_param("sssss", $default_username, $default_name, $default_password, $default_email, $default_role);
            } else {
                $insert_stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
                $insert_stmt->bind_param("ssss", $default_username, $default_password, $default_email, $default_role);
            }
            if ($insert_stmt->execute()) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default super_admin user created successfully</div>';
                echo '<div class="credentials-box">';
                echo '<strong><i class="fas fa-key"></i> Login Credentials:</strong><br><br>';
                echo '<strong>Username:</strong> admin<br>';
                echo '<strong>Password:</strong> admin123<br>';
                echo '<strong>Role:</strong> Super Admin<br><br>';
                echo '<em class="text-warning"><i class="fas fa-exclamation-triangle"></i> Please change the password after first login!</em>';
                echo '</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating admin user: ' . $insert_stmt->error . '</div>';
            }
            $insert_stmt->close();
        } else {
            $user = $result->fetch_assoc();
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Admin user already exists</div>';
            if ($user['role'] !== 'super_admin') {
                $update_stmt = $conn->prepare("UPDATE users SET role = 'super_admin' WHERE username = ?");
                $update_stmt->bind_param("s", $default_username);
                if ($update_stmt->execute()) {
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Promoted "admin" account to super_admin</div>';
                }
                $update_stmt->close();
            }
        }
        $stmt->close();
        // ─── /DEFAULT super_admin ─────────────────────────────────────────────

        // ─── ISP TABLES ────────────────────────────────────────────────
        echo '<br><div class="log-item log-info"><i class="fas fa-wifi"></i> <strong>Setting up ISP tables...</strong></div>';

        // packages
        $pkg_sql = "CREATE TABLE IF NOT EXISTS packages (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            speed_mbps INT(11) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            billing_cycle ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
            description TEXT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_deleted (is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($pkg_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "packages" created or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // customers
        $cust_sql = "CREATE TABLE IF NOT EXISTS customers (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            customer_code VARCHAR(20) NOT NULL UNIQUE,
            full_name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(100) NULL,
            cnic VARCHAR(20) NULL,
            address TEXT NOT NULL,
            area_id INT(11) NOT NULL,
            package_id INT(11) NOT NULL,
            installation_date DATE NOT NULL,
            monthly_due_day INT(11) NOT NULL DEFAULT 1,
            ip_address VARCHAR(45) NULL,
            mac_address VARCHAR(20) NULL,
            connection_status ENUM('active','suspended','disconnected') NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_area (area_id),
            INDEX idx_package (package_id),
            INDEX idx_conn_status (connection_status),
            INDEX idx_deleted (is_deleted),
            INDEX idx_phone (phone),
            CONSTRAINT fk_cust_area FOREIGN KEY (area_id) REFERENCES areas(id),
            CONSTRAINT fk_cust_pkg FOREIGN KEY (package_id) REFERENCES packages(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($cust_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "customers" created or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // invoices
        $inv_sql = "CREATE TABLE IF NOT EXISTS invoices (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(20) NOT NULL UNIQUE,
            customer_id INT(11) NOT NULL,
            package_id INT(11) NOT NULL,
            billing_month VARCHAR(7) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL,
            due_date DATE NOT NULL,
            status ENUM('unpaid','partial','paid','overdue') NOT NULL DEFAULT 'unpaid',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer (customer_id),
            INDEX idx_package (package_id),
            INDEX idx_status (status),
            INDEX idx_billing_month (billing_month),
            INDEX idx_due_date (due_date),
            CONSTRAINT fk_inv_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
            CONSTRAINT fk_inv_pkg FOREIGN KEY (package_id) REFERENCES packages(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($inv_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "invoices" created or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // payments
        $pay_sql = "CREATE TABLE IF NOT EXISTS payments (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            receipt_number VARCHAR(20) NOT NULL UNIQUE,
            invoice_id INT(11) NOT NULL,
            customer_id INT(11) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method ENUM('cash','bank_transfer','easypaisa','jazzcash','cheque') NOT NULL,
            payment_date DATE NOT NULL,
            collected_by INT(11) NOT NULL,
            reference_number VARCHAR(100) NULL,
            notes TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_invoice (invoice_id),
            INDEX idx_customer (customer_id),
            INDEX idx_collected_by (collected_by),
            INDEX idx_payment_date (payment_date),
            CONSTRAINT fk_pay_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id),
            CONSTRAINT fk_pay_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
            CONSTRAINT fk_pay_user FOREIGN KEY (collected_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($pay_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "payments" created or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // complaints
        $cmp_sql = "CREATE TABLE IF NOT EXISTS complaints (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            ticket_number VARCHAR(20) NOT NULL UNIQUE,
            customer_id INT(11) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
            status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
            assigned_to INT(11) NULL,
            resolution TEXT NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer (customer_id),
            INDEX idx_status (status),
            INDEX idx_priority (priority),
            INDEX idx_assigned (assigned_to),
            CONSTRAINT fk_comp_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
            CONSTRAINT fk_comp_user FOREIGN KEY (assigned_to) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($cmp_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "complaints" created or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // expenses
        $exp_sql = "CREATE TABLE IF NOT EXISTS expenses (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            expense_date DATE NOT NULL,
            category ENUM('salary','equipment','utilities','rent','bandwidth','maintenance','other') NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            paid_by INT(11) NOT NULL,
            receipt_image VARCHAR(255) NULL,
            notes TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_expense_date (expense_date),
            INDEX idx_category (category),
            INDEX idx_paid_by (paid_by),
            INDEX idx_deleted (is_deleted),
            CONSTRAINT fk_exp_user FOREIGN KEY (paid_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($exp_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "expenses" created or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error: ' . $conn->error . '</div>';
        }

        // expenses receipt upload dir
        $upload_receipts = __DIR__ . '/uploads/receipts';
        if (!file_exists($upload_receipts)) {
            @mkdir($upload_receipts, 0755, true);
        }

        // ─── DEMO DATA SEEDS ───────────────────────────────────────────
        echo '<br><div class="log-item log-info"><i class="fas fa-database"></i> <strong>Seeding demo data...</strong></div>';

        // staff logins — one per role, so every RBAC path is walkable.
        // must run BEFORE the collection seeds further down: they need a collector to exist.
        $demo_area_scope = [];
        try {
            $areaIds = [];
            $ar = $conn->query("SELECT id FROM areas WHERE is_deleted = 0 ORDER BY id LIMIT 3");
            while ($ar && $x = $ar->fetch_assoc()) $areaIds[] = (int)$x['id'];
            $a1 = $areaIds[0] ?? null; $a2 = $areaIds[1] ?? $a1; $a3 = $areaIds[2] ?? $a1;

            // base columns only — the extended HR columns are added later in this same run
            // [username, name, email, phone, role, area, pwd]
            $demo_staff = [
                ['admin2',      'Admin 2',      'admin2@demo.com',      '03001000002', 'admin',        null, 'admin123'],
                ['accountant1', 'Accountant 1', 'accountant1@demo.com', '03001000003', 'accountant',   null, 'acc123'],
                ['manager1',    'Manager 1',    'manager1@demo.com',    '03001000004', 'area_manager', $a1,  'manager123'],
                ['collector1',  'Collector 1',  'collector1@demo.com',  '03001000005', 'collector',    $a1,  'collector123'],
                ['collector2',  'Collector 2',  'collector2@demo.com',  '03001000006', 'collector',    $a2,  'collector123'],
            ];
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $ins = $conn->prepare("INSERT INTO users (username, name, password, email, phone, role, area_id, status)
                                   VALUES (?,?,?,?,?,?,?, 'active')");
            $added = 0;
            foreach ($demo_staff as $s) {
                $chk->bind_param("s", $s[0]);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()) continue; // already seeded
                $hash = password_hash($s[6], PASSWORD_DEFAULT);
                $ins->bind_param("ssssssi", $s[0], $s[1], $hash, $s[2], $s[3], $s[4], $s[5]);
                if ($ins->execute()) $added++;
            }
            $chk->close(); $ins->close();
            echo $added
                ? '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo staff seeded: ' . $added . ' (admin2 / accountant1 / manager1 / collector1 / collector2)</div>'
                : '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Demo staff already present, skipping</div>';
            $demo_area_scope = ['manager1' => [$a1, $a2], 'collector1' => [$a1], 'collector2' => [$a2, $a3]]; // seeded after applyUpdates builds user_areas
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Staff seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // packages
        try {
            $demo_pkgs = [
                ['Home Lite 10Mbps',     10, 1500.00, 'monthly', 'Entry-level home internet — 10 Mbps unlimited'],
                ['Home Pro 25Mbps',      25, 2500.00, 'monthly', 'Mid-tier home plan — 25 Mbps unlimited, suitable for families'],
                ['Business Fiber 50Mbps', 50, 5000.00, 'monthly', 'Business-grade fiber connection — 50 Mbps symmetric, priority support'],
            ];
            foreach ($demo_pkgs as [$p_name, $p_speed, $p_price, $p_cycle, $p_desc]) {
                $chk = $conn->prepare("SELECT id FROM packages WHERE name = ?");
                $chk->bind_param("s", $p_name);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $ins = $conn->prepare("INSERT INTO packages (name, speed_mbps, price, billing_cycle, description) VALUES (?, ?, ?, ?, ?)");
                    $ins->bind_param("sidss", $p_name, $p_speed, $p_price, $p_cycle, $p_desc);
                    $ins->execute();
                    $ins->close();
                }
                $chk->close();
            }
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo packages seeded</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Packages seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // customers (only seed if customers table is empty) — generic CLAUDE.md-compliant
        try {
            $cust_check = $conn->query("SELECT COUNT(*) AS c FROM customers");
            if ((int)($cust_check->fetch_assoc()['c'] ?? 1) === 0) {
                $r = $conn->query("SELECT id FROM areas WHERE name='Area 1' LIMIT 1"); $a1 = $r->fetch_assoc(); $a1 = $a1 ? (int)$a1['id'] : 0;
                $r = $conn->query("SELECT id FROM areas WHERE name='Area 2' LIMIT 1"); $a2 = $r->fetch_assoc(); $a2 = $a2 ? (int)$a2['id'] : 0;
                $r = $conn->query("SELECT id FROM areas WHERE name='Area 3' LIMIT 1"); $a3 = $r->fetch_assoc(); $a3 = $a3 ? (int)$a3['id'] : 0;
                $r = $conn->query("SELECT id FROM packages WHERE name='Home Lite 10Mbps' LIMIT 1");      $p1 = $r->fetch_assoc(); $p1 = $p1 ? (int)$p1['id'] : 0;
                $r = $conn->query("SELECT id FROM packages WHERE name='Home Pro 25Mbps' LIMIT 1");       $p2 = $r->fetch_assoc(); $p2 = $p2 ? (int)$p2['id'] : 0;
                $r = $conn->query("SELECT id FROM packages WHERE name='Business Fiber 50Mbps' LIMIT 1"); $p3 = $r->fetch_assoc(); $p3 = $p3 ? (int)$p3['id'] : 0;

                if (!$a1 || !$a2 || !$a3 || !$p1 || !$p2 || !$p3) {
                    throw new Exception("Required parent rows missing — areas: $a1/$a2/$a3, packages: $p1/$p2/$p3");
                }

                // 5 customers: 3 active, 1 suspended, 1 disconnected
                $demo_custs = [
                    ['CUST-0001', 'Customer 1', '03001000001', 'customer1@demo.com', '3520210000001', 'House 1, Street 1, Demo City', $a1, $p2, '2025-08-01', 10, 'active',       'Long-term customer. Pays on time every month.'],
                    ['CUST-0002', 'Customer 2', '03001000002', 'customer2@demo.com', '3520210000002', 'House 2, Street 2, Demo City', $a2, $p2, '2025-11-01',  5, 'active',       'Reports occasional speed drops in evening hours.'],
                    ['CUST-0003', 'Customer 3', '03001000003', 'customer3@demo.com', '3520210000003', 'House 3, Street 3, Demo City', $a3, $p1, '2026-01-01', 20, 'suspended',    'Suspended due to repeated non-payment. Awaiting clearance.'],
                    ['CUST-0004', 'Customer 4', '03001000004', 'customer4@demo.com', '3520210000004', 'House 4, Street 4, Demo City', $a1, $p3, '2024-06-01', 15, 'active',       'Business customer — priority support, dedicated bandwidth.'],
                    ['CUST-0005', 'Customer 5', '03001000005', 'customer5@demo.com', '3520210000005', 'House 5, Street 5, Demo City', $a2, $p2, '2025-04-01',  1, 'disconnected', 'Disconnected after relocation request. Equipment recovered.'],
                ];
                foreach ($demo_custs as $c) {
                    $ins = $conn->prepare("INSERT INTO customers (customer_code, full_name, phone, email, cnic, address, area_id, package_id, installation_date, monthly_due_day, connection_status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param("ssssssiisiss", $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], $c[7], $c[8], $c[9], $c[10], $c[11]);
                    $ins->execute();
                    $ins->close();
                }
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo customers seeded (5 customers, varied statuses, with notes)</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Customers seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // rolling demo window — the last 3 billing months. Every dated seed below hangs off these,
        // so a install in any month still lands data inside the dashboard's "This Month" view.
        $m0 = date('Y-m', strtotime('first day of -2 month'));
        $m1 = date('Y-m', strtotime('first day of -1 month'));
        $m2 = date('Y-m');

        // invoices (only if empty) — 3 months × 5 customers = 15 invoices
        try {
            $inv_check = $conn->query("SELECT COUNT(*) AS c FROM invoices");
            if ((int)($inv_check->fetch_assoc()['c'] ?? 1) === 0) {
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0001' LIMIT 1"); $c1 = $r->fetch_assoc(); $c1 = $c1 ? (int)$c1['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0002' LIMIT 1"); $c2 = $r->fetch_assoc(); $c2 = $c2 ? (int)$c2['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0003' LIMIT 1"); $c3 = $r->fetch_assoc(); $c3 = $c3 ? (int)$c3['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0004' LIMIT 1"); $c4 = $r->fetch_assoc(); $c4 = $c4 ? (int)$c4['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0005' LIMIT 1"); $c5 = $r->fetch_assoc(); $c5 = $c5 ? (int)$c5['id'] : 0;
                $r = $conn->query("SELECT id FROM packages WHERE name='Home Lite 10Mbps' LIMIT 1");      $p1 = $r->fetch_assoc(); $p1 = $p1 ? (int)$p1['id'] : 0;
                $r = $conn->query("SELECT id FROM packages WHERE name='Home Pro 25Mbps' LIMIT 1");       $p2 = $r->fetch_assoc(); $p2 = $p2 ? (int)$p2['id'] : 0;
                $r = $conn->query("SELECT id FROM packages WHERE name='Business Fiber 50Mbps' LIMIT 1"); $p3 = $r->fetch_assoc(); $p3 = $p3 ? (int)$p3['id'] : 0;

                if (!$c1 || !$c2 || !$c3 || !$c4 || !$c5 || !$p1 || !$p2 || !$p3) {
                    throw new Exception("Required parent rows missing — customers: $c1/$c2/$c3/$c4/$c5, packages: $p1/$p2/$p3");
                }

                // 15 invoices: the last 3 billing months × 5 customers.
                // months roll with the clock — the dashboard's "This Month" must never open empty.
                $yr = date('Y');
                $num = function ($n) use ($yr) { return sprintf('INV-%s-%04d', $yr, $n); };
                // status is only a starting point — the post-seed recompute resolves it from the payments
                $demo_invs = [
                    // Customer 1 (Home Pro 2500/mo) — fully paid history, current month settled
                    [$num(1),  $c1, $p2, $m0, 2500.00, 0.00, 0.00, 2500.00, $m0 . '-10', 'paid'],
                    [$num(2),  $c1, $p2, $m1, 2500.00, 0.00, 0.00, 2500.00, $m1 . '-10', 'paid'],
                    [$num(3),  $c1, $p2, $m2, 2500.00, 0.00, 0.00, 2500.00, $m2 . '-10', 'paid'],
                    // Customer 2 (Home Pro 2500/mo) — mixed: paid, partial, part-paid this month
                    [$num(4),  $c2, $p2, $m0, 2500.00, 0.00, 0.00, 2500.00, $m0 . '-05', 'paid'],
                    [$num(5),  $c2, $p2, $m1, 2500.00, 0.00, 0.00, 2500.00, $m1 . '-05', 'partial'],
                    [$num(6),  $c2, $p2, $m2, 2500.00, 0.00, 0.00, 2500.00, $m2 . '-05', 'partial'],
                    // Customer 3 (Home Lite 1500/mo) — overdue, suspended
                    [$num(7),  $c3, $p1, $m0, 1500.00, 0.00, 0.00, 1500.00, $m0 . '-20', 'overdue'],
                    [$num(8),  $c3, $p1, $m1, 1500.00, 0.00, 0.00, 1500.00, $m1 . '-20', 'overdue'],
                    [$num(9),  $c3, $p1, $m2, 1500.00, 0.00, 0.00, 1500.00, $m2 . '-20', 'unpaid'],
                    // Customer 4 (Business Fiber 5000/mo) — paid + partial running
                    [$num(10), $c4, $p3, $m0, 5000.00, 0.00, 0.00, 5000.00, $m0 . '-15', 'paid'],
                    [$num(11), $c4, $p3, $m1, 5000.00, 0.00, 0.00, 5000.00, $m1 . '-15', 'paid'],
                    [$num(12), $c4, $p3, $m2, 5000.00, 0.00, 0.00, 5000.00, $m2 . '-15', 'partial'],
                    // Customer 5 (Home Pro 2500/mo) — paid before disconnect
                    [$num(13), $c5, $p2, $m0, 2500.00, 0.00, 0.00, 2500.00, $m0 . '-01', 'paid'],
                    [$num(14), $c5, $p2, $m1, 2500.00, 0.00, 0.00, 2500.00, $m1 . '-01', 'overdue'],
                    [$num(15), $c5, $p2, $m2, 2500.00, 0.00, 0.00, 2500.00, $m2 . '-01', 'unpaid'],
                ];
                foreach ($demo_invs as $i) {
                    $ins = $conn->prepare("INSERT INTO invoices (invoice_number, customer_id, package_id, billing_month, amount, discount, tax, total, due_date, status) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param("siisddddss", $i[0], $i[1], $i[2], $i[3], $i[4], $i[5], $i[6], $i[7], $i[8], $i[9]);
                    $ins->execute();
                    $ins->close();
                }
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo invoices seeded (15 invoices, 3 months × 5 customers)</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Invoices seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // payments (only if empty) — 8 payments matching invoices, mixed methods
        try {
            $pay_check = $conn->query("SELECT COUNT(*) AS c FROM payments");
            if ((int)($pay_check->fetch_assoc()['c'] ?? 1) === 0) {
                $r = $conn->query("SELECT id FROM users WHERE username='admin' LIMIT 1"); $admin_id = $r->fetch_assoc(); $admin_id = $admin_id ? (int)$admin_id['id'] : 0;
                if (!$admin_id) throw new Exception("admin user not found");

                // field cash goes against the collectors, not the owner — otherwise a collector
                // logs in to an all-zero dashboard
                $staffId = function ($uname) use ($conn, $admin_id) {
                    $q = $conn->query("SELECT id FROM users WHERE username='" . $conn->real_escape_string($uname) . "' AND is_deleted=0 LIMIT 1");
                    $x = $q ? $q->fetch_assoc() : null;
                    return $x ? (int)$x['id'] : $admin_id;
                };
                $col1 = $staffId('collector1');
                $col2 = $staffId('collector2');

                // resolve invoice + customer ids by stable codes
                $invMap = []; $custMap = [];
                foreach (range(1, 15) as $n) {
                    $code = sprintf('INV-%s-%04d', date('Y'), $n);
                    $rs = $conn->query("SELECT id, customer_id FROM invoices WHERE invoice_number='$code' LIMIT 1");
                    $row = $rs->fetch_assoc();
                    if ($row) { $invMap[$n] = (int)$row['id']; $custMap[$n] = (int)$row['customer_id']; }
                }

                // 11 payments — full and partial mix, three of them in the current month
                // (a payment can never be dated ahead of today, so current-month days get clamped)
                $today = date('Y-m-d');
                $day = function ($ym, $d) use ($today) {
                    $x = $ym . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                    return $x > $today ? $today : $x;
                };
                $demo_pays = [
                    // Customer 1 — paid INV 1 + 2 fully, and this month's on time
                    ['RCP-0001', $invMap[1] ?? 0, $custMap[1] ?? 0, 2500.00, 'cash',          $day($m0, 8),  $col1, null],
                    ['RCP-0002', $invMap[2] ?? 0, $custMap[2] ?? 0, 2500.00, 'easypaisa',     $day($m1, 9),  $admin_id, 'EP-DEMO-001'],
                    ['RCP-0009', $invMap[3] ?? 0, $custMap[3] ?? 0, 2500.00, 'easypaisa',     $today,        $admin_id, 'EP-DEMO-002'],
                    // Customer 2 — paid INV 4 fully, INV 5 + 6 partially
                    ['RCP-0003', $invMap[4] ?? 0, $custMap[4] ?? 0, 2500.00, 'jazzcash',      $day($m0, 4),  $admin_id, 'JC-DEMO-001'],
                    ['RCP-0004', $invMap[5] ?? 0, $custMap[5] ?? 0, 1500.00, 'cash',          $day($m1, 8),  $col1, null],
                    ['RCP-0010', $invMap[6] ?? 0, $custMap[6] ?? 0, 1000.00, 'cash',          $today,        $col1, null],
                    // Customer 4 — paid INV 10 + 11 fully, INV 12 partially
                    ['RCP-0005', $invMap[10] ?? 0, $custMap[10] ?? 0, 5000.00, 'bank_transfer', $day($m0, 14), $admin_id, 'BT-DEMO-001'],
                    ['RCP-0006', $invMap[11] ?? 0, $custMap[11] ?? 0, 5000.00, 'bank_transfer', $day($m1, 13), $admin_id, 'BT-DEMO-002'],
                    ['RCP-0007', $invMap[12] ?? 0, $custMap[12] ?? 0, 3000.00, 'cash',          $day($m2, 12), $col2, null],
                    ['RCP-0011', $invMap[12] ?? 0, $custMap[12] ?? 0, 1000.00, 'jazzcash',      $today,        $admin_id, 'JC-DEMO-002'],
                    // Customer 5 — paid INV 13 fully (before disconnect)
                    ['RCP-0008', $invMap[13] ?? 0, $custMap[13] ?? 0, 2500.00, 'cash',          $day($m0, 2),  $col2, null],
                ];
                foreach ($demo_pays as $p) {
                    if (!$p[1] || !$p[2]) continue;
                    $ins = $conn->prepare("INSERT INTO payments (receipt_number, invoice_id, customer_id, amount, payment_method, payment_date, collected_by, reference_number) VALUES (?,?,?,?,?,?,?,?)");
                    $ins->bind_param("siidssis", $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7]);
                    $ins->execute();
                    $ins->close();
                }
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo payments seeded (8 payments — cash / easypaisa / jazzcash / bank_transfer)</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Payments seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // complaints (only if empty) — 6 tickets, varied priorities + statuses
        try {
            $cmp_check = $conn->query("SELECT COUNT(*) AS c FROM complaints");
            if ((int)($cmp_check->fetch_assoc()['c'] ?? 1) === 0) {
                $r = $conn->query("SELECT id FROM users WHERE username='admin' LIMIT 1"); $admin_id = $r->fetch_assoc(); $admin_id = $admin_id ? (int)$admin_id['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0001' LIMIT 1"); $c1 = $r->fetch_assoc(); $c1 = $c1 ? (int)$c1['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0002' LIMIT 1"); $c2 = $r->fetch_assoc(); $c2 = $c2 ? (int)$c2['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0003' LIMIT 1"); $c3 = $r->fetch_assoc(); $c3 = $c3 ? (int)$c3['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0004' LIMIT 1"); $c4 = $r->fetch_assoc(); $c4 = $c4 ? (int)$c4['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0005' LIMIT 1"); $c5 = $r->fetch_assoc(); $c5 = $c5 ? (int)$c5['id'] : 0;

                if (!$admin_id || !$c1 || !$c2 || !$c3 || !$c4 || !$c5) {
                    throw new Exception("Required parent rows missing — admin:$admin_id, customers:$c1/$c2/$c3/$c4/$c5");
                }

                $demo_cmps = [
                    ['TKT-0001', $c1, 'Slow speed in evening',         'Speeds drop after 7pm in this area. Sample test runs needed.',                'medium', 'resolved',    $admin_id, 'Router upgraded and channel tuned. Speeds restored to normal.'],
                    ['TKT-0002', $c1, 'Connection drops randomly',     'Brief disconnects every few hours through the day.',                          'high',   'open',        null,      null],
                    ['TKT-0003', $c2, 'Speed below contracted plan',   'Customer subscribed to 25Mbps but tests show 12-15Mbps at peak.',             'medium', 'in_progress', $admin_id, null],
                    ['TKT-0004', $c3, 'Wrong billing amount',          'Customer disputes invoice total — claims package was changed last month.',    'low',    'resolved',    $admin_id, 'Verified package and billing — amount correct per signed agreement.'],
                    ['TKT-0005', $c4, 'Static IP stopped responding',  'Business static IP unreachable since this morning. Urgent uptime impact.',    'urgent', 'in_progress', $admin_id, null],
                    ['TKT-0006', $c5, 'Service cancellation request',  'Customer relocating. Requested disconnection and equipment pickup.',          'low',    'closed',      $admin_id, 'Service disconnected. Equipment recovered. Account closed.'],
                ];
                foreach ($demo_cmps as $c) {
                    $resolved_at = ($c[5] === 'resolved' || $c[5] === 'closed') ? date('Y-m-d H:i:s') : null;
                    if ($c[6] === null) {
                        $ins = $conn->prepare("INSERT INTO complaints (ticket_number, customer_id, subject, description, priority, status, assigned_to, resolution, resolved_at) VALUES (?,?,?,?,?,?,NULL,?,?)");
                        $ins->bind_param("sissssss", $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[7], $resolved_at);
                    } else {
                        $ins = $conn->prepare("INSERT INTO complaints (ticket_number, customer_id, subject, description, priority, status, assigned_to, resolution, resolved_at) VALUES (?,?,?,?,?,?,?,?,?)");
                        $ins->bind_param("sissssiss", $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], $c[7], $resolved_at);
                    }
                    $ins->execute();
                    $ins->close();
                }
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo complaints seeded (6 tickets — open / in_progress / resolved / closed)</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Complaints seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // expenses (only if empty)
        try {
            $exp_check = $conn->query("SELECT COUNT(*) AS c FROM expenses");
            if ((int)($exp_check->fetch_assoc()['c'] ?? 1) === 0) {
                $r = $conn->query("SELECT id FROM users WHERE username='admin' LIMIT 1"); $admin_id = $r->fetch_assoc(); $admin_id = $admin_id ? (int)$admin_id['id'] : 0;
                if (!$admin_id) {
                    throw new Exception("admin user not found");
                }
                $today = date('Y-m-d');
                $eDay = function ($ym, $d) use ($today) {
                    $x = $ym . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                    return $x > $today ? $today : $x;
                };
                $demo_exps = [
                    [$eDay($m0, 1), 'bandwidth',  'Upstream bandwidth — ' . date('F', strtotime($m0 . '-01')), 85000.00, $admin_id],
                    [$eDay($m0, 3), 'salary',     'Employee 1 — monthly salary',                              35000.00, $admin_id],
                    [$eDay($m1, 1), 'bandwidth',  'Upstream bandwidth — ' . date('F', strtotime($m1 . '-01')), 85000.00, $admin_id],
                    [$eDay($m1, 6), 'equipment',  '2 router units for Area 2',                                24500.00, $admin_id],
                    [$eDay($m2, 1), 'bandwidth',  'Upstream bandwidth — ' . date('F', strtotime($m2 . '-01')), 85000.00, $admin_id],
                    [$eDay($m2, 3), 'salary',     'Employee 1 — monthly salary',                              35000.00, $admin_id],
                    [$eDay($m2, 4), 'maintenance', 'Field team fuel and travel',                               8500.00, $admin_id],
                ];
                foreach ($demo_exps as $e_row) {
                    $ins = $conn->prepare("INSERT INTO expenses (expense_date, category, description, amount, paid_by) VALUES (?,?,?,?,?)");
                    $ins->bind_param("sssdi", $e_row[0], $e_row[1], $e_row[2], $e_row[3], $e_row[4]);
                    $ins->execute();
                    $ins->close();
                }
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo expenses seeded</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Expenses seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        // ─── TIER 1 — ISP business columns (idempotent ALTER blocks) ───
        echo '<br><div class="log-item log-info"><i class="fas fa-layer-group"></i> <strong>Tier 1 ISP enrichment...</strong></div>';

        $addColIfMissing = function($table, $col, $sql) use ($conn) {
            try {
                // table may not be built yet (its own CREATE runs further down) — it ships the column itself
                $t = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
                if (!$t || $t->num_rows === 0) return;
                $r = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
                if (!$r) {
                    echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> ' . $table . '.' . $col . ' check failed: ' . htmlspecialchars($conn->error) . '</div>';
                    return;
                }
                if ($r->num_rows === 0) {
                    if (!@$conn->query("ALTER TABLE `$table` ADD COLUMN $sql")) {
                        echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> ' . $table . '.' . $col . ' add failed: ' . htmlspecialchars($conn->error) . '</div>';
                        return;
                    }
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> ' . $table . '.' . $col . ' added</div>';
                }
            } catch (Throwable $e) {
                echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> ' . $table . '.' . $col . ' exception: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        };

        // customers
        $addColIfMissing('customers', 'alternate_phone',     "alternate_phone VARCHAR(20) NULL AFTER phone");
        $addColIfMissing('customers', 'father_name',         "father_name VARCHAR(100) NULL AFTER full_name");
        $addColIfMissing('customers', 'gps_lat',             "gps_lat DECIMAL(10,7) NULL AFTER address");
        $addColIfMissing('customers', 'gps_lng',             "gps_lng DECIMAL(10,7) NULL AFTER gps_lat");
        $addColIfMissing('customers', 'router_serial',       "router_serial VARCHAR(50) NULL AFTER mac_address");
        $addColIfMissing('customers', 'connection_type',     "connection_type ENUM('pppoe','static','dhcp') NOT NULL DEFAULT 'pppoe' AFTER router_serial");
        $addColIfMissing('customers', 'vlan_id',             "vlan_id INT(11) NULL AFTER connection_type");
        $addColIfMissing('customers', 'registration_fee',    "registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER monthly_due_day");
        $addColIfMissing('customers', 'security_deposit',    "security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER registration_fee");
        $addColIfMissing('customers', 'discount_pct',        "discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER security_deposit");
        $addColIfMissing('customers', 'last_payment_date',   "last_payment_date DATE NULL AFTER discount_pct");
        $addColIfMissing('customers', 'suspended_at',        "suspended_at DATETIME NULL AFTER connection_status");
        $addColIfMissing('customers', 'suspension_reason',   "suspension_reason VARCHAR(255) NULL AFTER suspended_at");
        $addColIfMissing('customers', 'disconnected_at',     "disconnected_at DATETIME NULL AFTER suspension_reason");
        $addColIfMissing('customers', 'disconnect_reason',   "disconnect_reason VARCHAR(255) NULL AFTER disconnected_at");
        $addColIfMissing('customers', 'billing_email_optin', "billing_email_optin TINYINT(1) NOT NULL DEFAULT 1 AFTER disconnect_reason");
        $addColIfMissing('customers', 'whatsapp_optin',      "whatsapp_optin TINYINT(1) NOT NULL DEFAULT 1 AFTER billing_email_optin");

        // packages
        $addColIfMissing('packages', 'upload_speed_mbps',         "upload_speed_mbps INT(11) NOT NULL DEFAULT 0 AFTER speed_mbps");
        $addColIfMissing('packages', 'fup_gb',                    "fup_gb INT(11) NULL AFTER upload_speed_mbps");
        $addColIfMissing('packages', 'fup_throttled_speed_mbps',  "fup_throttled_speed_mbps INT(11) NULL AFTER fup_gb");
        $addColIfMissing('packages', 'setup_fee',                 "setup_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER price");
        $addColIfMissing('packages', 'security_deposit',          "security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER setup_fee");
        $addColIfMissing('packages', 'package_category',          "package_category ENUM('home','business','enterprise') NOT NULL DEFAULT 'home' AFTER billing_cycle");
        $addColIfMissing('packages', 'min_contract_months',       "min_contract_months INT(11) NOT NULL DEFAULT 0 AFTER package_category");
        $addColIfMissing('packages', 'sort_order',                "sort_order INT(11) NOT NULL DEFAULT 0 AFTER min_contract_months");

        // invoices
        $addColIfMissing('invoices', 'previous_balance',      "previous_balance DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER amount");
        $addColIfMissing('invoices', 'late_fee',              "late_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER tax");
        $addColIfMissing('invoices', 'paid_amount',           "paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total");
        $addColIfMissing('invoices', 'balance_amount',        "balance_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER paid_amount");
        $addColIfMissing('invoices', 'issue_date',            "issue_date DATE NULL AFTER balance_amount");
        $addColIfMissing('invoices', 'invoice_period_start',  "invoice_period_start DATE NULL AFTER billing_month");
        $addColIfMissing('invoices', 'invoice_period_end',    "invoice_period_end DATE NULL AFTER invoice_period_start");
        $addColIfMissing('invoices', 'created_by_user_id',    "created_by_user_id INT(11) NULL AFTER status");
        $addColIfMissing('invoices', 'batch_id',              "batch_id VARCHAR(40) NULL AFTER created_by_user_id");
        $addColIfMissing('invoices', 'notes',                 "notes TEXT NULL AFTER batch_id");
        $addColIfMissing('invoices', 'sent_at',               "sent_at DATETIME NULL AFTER notes");
        $addColIfMissing('invoices', 'cancelled_at',          "cancelled_at DATETIME NULL AFTER sent_at");
        $addColIfMissing('invoices', 'cancel_reason',         "cancel_reason VARCHAR(255) NULL AFTER cancelled_at");

        // payments
        $addColIfMissing('payments', 'status',           "status ENUM('cleared','pending','bounced','refunded') NOT NULL DEFAULT 'cleared' AFTER payment_method");
        $addColIfMissing('payments', 'bank_name',        "bank_name VARCHAR(100) NULL AFTER status");
        $addColIfMissing('payments', 'cheque_number',    "cheque_number VARCHAR(50) NULL AFTER bank_name");
        $addColIfMissing('payments', 'cheque_date',      "cheque_date DATE NULL AFTER cheque_number");
        $addColIfMissing('payments', 'fee',              "fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER amount");
        $addColIfMissing('payments', 'net_amount',       "net_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER fee");
        $addColIfMissing('payments', 'source',           "source ENUM('field','portal','auto_debit','api','office') NOT NULL DEFAULT 'field' AFTER cheque_date");
        $addColIfMissing('payments', 'cleared_at',       "cleared_at DATETIME NULL AFTER source");
        $addColIfMissing('payments', 'refunded_amount',  "refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cleared_at");
        $addColIfMissing('payments', 'refunded_at',      "refunded_at DATETIME NULL AFTER refunded_amount");
        $addColIfMissing('payments', 'refund_reason',    "refund_reason VARCHAR(255) NULL AFTER refunded_at");
        $addColIfMissing('payments', 'bounce_reason',    "bounce_reason VARCHAR(255) NULL AFTER refund_reason");
        $addColIfMissing('payments', 'deposit_slip_image',"deposit_slip_image VARCHAR(255) NULL AFTER bounce_reason");

        // complaints
        $addColIfMissing('complaints', 'category',                  "category ENUM('speed','connectivity','billing','installation','equipment','outage','other') NOT NULL DEFAULT 'other' AFTER subject");
        $addColIfMissing('complaints', 'source',                    "source ENUM('call','whatsapp','email','walk_in','portal') NOT NULL DEFAULT 'call' AFTER category");
        $addColIfMissing('complaints', 'first_response_at',         "first_response_at DATETIME NULL AFTER assigned_to");
        $addColIfMissing('complaints', 'expected_resolution_date',  "expected_resolution_date DATETIME NULL AFTER first_response_at");
        $addColIfMissing('complaints', 'resolution_time_minutes',   "resolution_time_minutes INT(11) NULL AFTER resolved_at");
        $addColIfMissing('complaints', 'customer_rating',           "customer_rating TINYINT(1) NULL AFTER resolution_time_minutes");
        $addColIfMissing('complaints', 'customer_feedback',         "customer_feedback TEXT NULL AFTER customer_rating");
        $addColIfMissing('complaints', 'closed_by_user_id',         "closed_by_user_id INT(11) NULL AFTER customer_feedback");
        $addColIfMissing('complaints', 'escalated_to_user_id',      "escalated_to_user_id INT(11) NULL AFTER closed_by_user_id");
        $addColIfMissing('complaints', 'escalated_at',              "escalated_at DATETIME NULL AFTER escalated_to_user_id");
        $addColIfMissing('complaints', 'reopened_count',            "reopened_count INT(11) NOT NULL DEFAULT 0 AFTER escalated_at");

        // expenses
        $addColIfMissing('expenses', 'vendor_name',          "vendor_name VARCHAR(100) NULL AFTER description");
        $addColIfMissing('expenses', 'payment_method',       "payment_method ENUM('cash','bank_transfer','cheque','online') NOT NULL DEFAULT 'cash' AFTER amount");
        $addColIfMissing('expenses', 'reference_number',     "reference_number VARCHAR(100) NULL AFTER payment_method");
        $addColIfMissing('expenses', 'tax_amount',           "tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER reference_number");
        $addColIfMissing('expenses', 'status',               "status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER paid_by");
        $addColIfMissing('expenses', 'approved_by_user_id',  "approved_by_user_id INT(11) NULL AFTER status");
        $addColIfMissing('expenses', 'approved_at',          "approved_at DATETIME NULL AFTER approved_by_user_id");
        $addColIfMissing('expenses', 'is_recurring',         "is_recurring TINYINT(1) NOT NULL DEFAULT 0 AFTER approved_at");
        $addColIfMissing('expenses', 'recurrence_period',    "recurrence_period ENUM('monthly','quarterly','yearly') NULL AFTER is_recurring");
        $addColIfMissing('expenses', 'subcategory',          "subcategory VARCHAR(50) NULL AFTER recurrence_period");

        // collection_visits — payment method so cash/online totals split correctly
        $addColIfMissing('collection_visits', 'payment_method', "payment_method ENUM('cash','online') NOT NULL DEFAULT 'cash' AFTER amount_collected");

        // ─── NEW SUPPORT TABLES ─────────────────────────────────────────
        // payment_invoice_allocations — splits a payment across multiple invoices
        $alloc_sql = "CREATE TABLE IF NOT EXISTS payment_invoice_allocations (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            payment_id INT(11) NOT NULL,
            invoice_id INT(11) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payment (payment_id),
            INDEX idx_invoice (invoice_id),
            CONSTRAINT fk_alloc_pay FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
            CONSTRAINT fk_alloc_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($alloc_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "payment_invoice_allocations" created or already exists</div>';
        }

        // expense_payments — individual payment history per expense
        $exp_pay_sql = "CREATE TABLE IF NOT EXISTS expense_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_date DATE NOT NULL,
            payment_method VARCHAR(50) DEFAULT NULL,
            reference VARCHAR(100) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_by_user_id INT DEFAULT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_exp_pay_expense (expense_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($exp_pay_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "expense_payments" created or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating expense_payments table: ' . $conn->error . '</div>';
        }

        // complaint_attachments
        $att_sql = "CREATE TABLE IF NOT EXISTS complaint_attachments (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            complaint_id INT(11) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            file_size INT(11) NULL,
            uploaded_by INT(11) NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_complaint (complaint_id),
            CONSTRAINT fk_att_comp FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
            CONSTRAINT fk_att_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($att_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "complaint_attachments" created or already exists</div>';
        }

        // customer_documents
        $doc_sql = "CREATE TABLE IF NOT EXISTS customer_documents (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            customer_id INT(11) NOT NULL,
            doc_type ENUM('cnic_front','cnic_back','contract','utility_bill','other') NOT NULL DEFAULT 'other',
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            file_size INT(11) NULL,
            uploaded_by INT(11) NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_customer (customer_id),
            INDEX idx_type (doc_type),
            CONSTRAINT fk_doc_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            CONSTRAINT fk_doc_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($doc_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "customer_documents" created or already exists</div>';
        }

        // call_logs — collection call history per customer
        $cl_sql = "CREATE TABLE IF NOT EXISTS call_logs (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            customer_id INT(11) NOT NULL,
            invoice_id INT(11) NULL,
            called_by INT(11) NOT NULL,
            call_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            outcome ENUM('no_answer','promised','paid','disputed','wrong_number','other') NOT NULL,
            promised_date DATE NULL,
            notes TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer_date (customer_id, call_date DESC),
            INDEX idx_called_by (called_by),
            INDEX idx_promised_date (promised_date),
            INDEX idx_outcome (outcome),
            INDEX idx_deleted (is_deleted),
            CONSTRAINT fk_cl_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
            CONSTRAINT fk_cl_inv  FOREIGN KEY (invoice_id)  REFERENCES invoices(id),
            CONSTRAINT fk_cl_user FOREIGN KEY (called_by)   REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($cl_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "call_logs" created or already exists</div>';
        }

        // upload dirs
        foreach (['/uploads/customer_docs', '/uploads/complaint_attachments'] as $dir) {
            $full = __DIR__ . $dir;
            if (!file_exists($full)) {
                @mkdir($full, 0755, true);
            }
        }

        // customer_documents demo seed — placeholder file paths (image preview uses brand logo URL)
        try {
            $doc_check = $conn->query("SELECT COUNT(*) AS c FROM customer_documents");
            if ((int)($doc_check->fetch_assoc()['c'] ?? 1) === 0) {
                $r = $conn->query("SELECT id FROM users WHERE username='admin' LIMIT 1"); $admin_id = $r->fetch_assoc(); $admin_id = $admin_id ? (int)$admin_id['id'] : 0;
                $logo_demo = 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';
                $pdf_demo  = 'uploads/customer_docs/demo_contract.pdf';

                $custIds = [];
                foreach (['CUST-0001','CUST-0002','CUST-0003','CUST-0004','CUST-0005'] as $code) {
                    $rs = $conn->query("SELECT id FROM customers WHERE customer_code='$code' LIMIT 1");
                    $row = $rs->fetch_assoc();
                    if ($row) $custIds[$code] = (int)$row['id'];
                }

                if ($admin_id && count($custIds) === 5) {
                    $demo_docs = [
                        // [customer_id, doc_type, file_path, original_name, file_size]
                        [$custIds['CUST-0001'], 'cnic_front',   $logo_demo, 'cnic_front_demo.jpg', 84200],
                        [$custIds['CUST-0001'], 'contract',     $pdf_demo,  'service_agreement_demo.pdf', 156800],
                        [$custIds['CUST-0002'], 'cnic_front',   $logo_demo, 'cnic_front_demo.jpg', 91500],
                        [$custIds['CUST-0003'], 'cnic_front',   $logo_demo, 'cnic_front_demo.jpg', 78900],
                        [$custIds['CUST-0003'], 'utility_bill', $pdf_demo,  'utility_bill_demo.pdf', 132400],
                        [$custIds['CUST-0004'], 'contract',     $pdf_demo,  'business_agreement_demo.pdf', 210500],
                    ];
                    foreach ($demo_docs as $d) {
                        $ins = $conn->prepare("INSERT INTO customer_documents (customer_id, doc_type, file_path, original_name, file_size, uploaded_by) VALUES (?,?,?,?,?,?)");
                        $ins->bind_param("isssii", $d[0], $d[1], $d[2], $d[3], $d[4], $admin_id);
                        $ins->execute();
                        $ins->close();
                    }
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo customer documents seeded (6 docs — CNIC / contract / utility bill)</div>';
                }
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Customer documents seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // ─── BACKFILL ───────────────────────────────────────────────────
        try {
            // seed allocations from existing payments (one alloc per existing payment)
            $conn->query("INSERT INTO payment_invoice_allocations (payment_id, invoice_id, amount)
                SELECT p.id, p.invoice_id, p.amount FROM payments p
                LEFT JOIN payment_invoice_allocations a ON a.payment_id = p.id
                WHERE p.is_deleted = 0 AND a.id IS NULL");

            // backfill payments.net_amount = amount - fee
            $conn->query("UPDATE payments SET net_amount = amount - fee WHERE net_amount = 0");
            // backfill payments.cleared_at for existing 'cleared' rows
            $conn->query("UPDATE payments SET cleared_at = created_at WHERE status = 'cleared' AND cleared_at IS NULL");

            // backfill invoices.paid_amount + balance_amount from allocations
            $conn->query("UPDATE invoices i
                SET paid_amount = IFNULL((SELECT SUM(a.amount) FROM payment_invoice_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.invoice_id = i.id AND p.is_deleted = 0 AND p.status = 'cleared'), 0)
                WHERE i.is_deleted = 0");
            $conn->query("UPDATE invoices SET balance_amount = total - paid_amount WHERE is_deleted = 0");
            // issue_date follows the invoice's OWN billing month — the row's created date is only a fallback.
            // (backfilling straight from created_at dated every historical invoice to install day)
            $conn->query("UPDATE invoices SET issue_date = DATE(CONCAT(billing_month, '-01'))
                          WHERE issue_date IS NULL AND billing_month REGEXP '^[0-9]{4}-[0-9]{2}$'");
            $conn->query("UPDATE invoices SET issue_date = DATE(created_at) WHERE issue_date IS NULL");
            // backfill invoice_period_start/end from billing_month
            $conn->query("UPDATE invoices SET
                invoice_period_start = DATE(CONCAT(billing_month, '-01')),
                invoice_period_end   = LAST_DAY(DATE(CONCAT(billing_month, '-01')))
                WHERE invoice_period_start IS NULL AND billing_month REGEXP '^[0-9]{4}-[0-9]{2}$'");

            // backfill customers.last_payment_date from payments
            $conn->query("UPDATE customers c
                SET last_payment_date = (SELECT MAX(p.payment_date) FROM payments p WHERE p.customer_id = c.id AND p.is_deleted = 0 AND p.status = 'cleared')
                WHERE c.is_deleted = 0");

            // packages: default upload_speed = download/4 (sensible asymmetric default)
            $conn->query("UPDATE packages SET upload_speed_mbps = GREATEST(1, FLOOR(speed_mbps/4)) WHERE upload_speed_mbps = 0");

            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Tier 1 backfill complete</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Backfill error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // ─── DEMO ENRICHMENT (Tier 1 fields on existing demo rows) ──────
        try {
            // customers — populate Tier 1 demo fields
            $conn->query("UPDATE customers SET
                alternate_phone = '03219999000',
                father_name = 'Khan Sahib',
                gps_lat = 31.4695, gps_lng = 74.2728,
                router_serial = 'MK-RT-001-A1B2',
                connection_type = 'pppoe',
                registration_fee = 2000.00, security_deposit = 1000.00, discount_pct = 0
                WHERE customer_code = 'CUST-0001'");
            $conn->query("UPDATE customers SET
                alternate_phone = '03334445566',
                father_name = 'Zahid Hussain',
                gps_lat = 31.4744, gps_lng = 74.4060,
                router_serial = 'MK-RT-002-C3D4',
                connection_type = 'pppoe',
                registration_fee = 2000.00, security_deposit = 1500.00, discount_pct = 5.00
                WHERE customer_code = 'CUST-0002'");
            $conn->query("UPDATE customers SET
                alternate_phone = '03001234567',
                father_name = 'Hassan Raza',
                gps_lat = 31.4818, gps_lng = 74.3293,
                router_serial = 'MK-RT-003-E5F6',
                connection_type = 'pppoe',
                registration_fee = 2000.00, security_deposit = 1000.00,
                suspended_at = '2026-04-25 10:00:00', suspension_reason = 'Non-payment of April invoice'
                WHERE customer_code = 'CUST-0003'");

            // packages — fill upload speeds, FUP, fees, category
            $conn->query("UPDATE packages SET upload_speed_mbps = 5,  fup_gb = 300,  fup_throttled_speed_mbps = 2,  setup_fee = 1500, security_deposit = 1000, package_category = 'home',     min_contract_months = 6, sort_order = 1 WHERE name = 'Home Lite 10Mbps'");
            $conn->query("UPDATE packages SET upload_speed_mbps = 10, fup_gb = 600,  fup_throttled_speed_mbps = 5,  setup_fee = 2000, security_deposit = 1500, package_category = 'home',     min_contract_months = 6, sort_order = 2 WHERE name = 'Home Pro 25Mbps'");
            $conn->query("UPDATE packages SET upload_speed_mbps = 50, fup_gb = NULL, fup_throttled_speed_mbps = NULL, setup_fee = 5000, security_deposit = 3000, package_category = 'business', min_contract_months = 12, sort_order = 3 WHERE name = 'Business Fiber 50Mbps'");



            // payments — set source, fee, bank_name for demo rows
            $conn->query("UPDATE payments SET source = 'field', fee = 0, net_amount = amount, bank_name = NULL WHERE receipt_number IN ('RCP-0001','RCP-0002','RCP-0003')");
            $conn->query("UPDATE payments SET source = 'portal', bank_name = 'EasyPaisa' WHERE receipt_number = 'RCP-0001'");

            // complaints — populate category/source for demo rows
            $conn->query("UPDATE complaints SET category='speed',        source='call'  WHERE ticket_number = 'TKT-0001'");
            $conn->query("UPDATE complaints SET category='connectivity', source='whatsapp' WHERE ticket_number = 'TKT-0002'");
            $conn->query("UPDATE complaints SET category='billing',      source='walk_in'  WHERE ticket_number = 'TKT-0003'");

            // expenses — vendor, method, tax for demo rows
            $conn->query("UPDATE expenses SET vendor_name='PTCL',           payment_method='bank_transfer', tax_amount=12750.00, reference_number='PTCL-INV-0501', status='approved', approved_at=NOW() WHERE description LIKE 'PTCL upstream%'");
            $conn->query("UPDATE expenses SET vendor_name='Bilal Ahmed',    payment_method='cash',          tax_amount=0,        status='approved', approved_at=NOW() WHERE description LIKE 'Bilal Ahmed%'");
            $conn->query("UPDATE expenses SET vendor_name='Mikrotik Distributor', payment_method='bank_transfer', tax_amount=3675.00, status='approved', approved_at=NOW() WHERE description LIKE '%Mikrotik routers%'");

            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Tier 1 demo enrichment applied</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Demo enrichment error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        // ─── /TIER 1 ────────────────────────────────────────────────────

        // ─── EXPANSION: equipment, leads, customer portal ──────────────
        echo '<br><div class="log-item log-info"><i class="fas fa-puzzle-piece"></i> <strong>ISP expansion modules...</strong></div>';

        // customers: portal_pin + portal_last_login + portal_enabled
        $addColIfMissing('customers', 'portal_pin',        "portal_pin VARCHAR(10) NULL AFTER whatsapp_optin");
        $addColIfMissing('customers', 'portal_last_login', "portal_last_login DATETIME NULL AFTER portal_pin");
        $addColIfMissing('customers', 'portal_enabled',    "portal_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER portal_last_login");

        // backfill portal_pin: last 6 digits of CNIC (strip dashes/spaces) when present
        try {
            $conn->query("UPDATE customers
                SET portal_pin = RIGHT(REPLACE(REPLACE(IFNULL(cnic,''),'-',''),' ',''), 6)
                WHERE (portal_pin IS NULL OR portal_pin = '')
                  AND cnic IS NOT NULL
                  AND CHAR_LENGTH(REPLACE(REPLACE(cnic,'-',''),' ','')) >= 6
                  AND is_deleted = 0");
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> portal_pin backfill: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // equipment table
        $eq_sql = "CREATE TABLE IF NOT EXISTS equipment (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            type ENUM('router','onu','switch','cable','splitter','antenna','other') NOT NULL DEFAULT 'router',
            brand VARCHAR(50) NULL,
            model VARCHAR(100) NULL,
            serial VARCHAR(100) NOT NULL UNIQUE,
            mac VARCHAR(20) NULL,
            status ENUM('in_stock','assigned','returned','lost','damaged','retired') NOT NULL DEFAULT 'in_stock',
            assigned_to_customer_id INT(11) NULL,
            assigned_at DATETIME NULL,
            returned_at DATETIME NULL,
            return_reason VARCHAR(255) NULL,
            purchase_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            purchase_date DATE NULL,
            vendor_name VARCHAR(100) NULL,
            location VARCHAR(100) NULL DEFAULT 'Main Warehouse',
            notes TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_type (type),
            INDEX idx_status (status),
            INDEX idx_customer (assigned_to_customer_id),
            INDEX idx_serial (serial),
            INDEX idx_deleted (is_deleted),
            CONSTRAINT fk_equip_cust FOREIGN KEY (assigned_to_customer_id) REFERENCES customers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($eq_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "equipment" created or already exists</div>';
        }

        // leads table
        $ld_sql = "CREATE TABLE IF NOT EXISTS leads (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            alternate_phone VARCHAR(20) NULL,
            email VARCHAR(100) NULL,
            address TEXT NULL,
            area_id INT(11) NULL,
            package_interested_id INT(11) NULL,
            source ENUM('walk_in','call','whatsapp','referral','website','social','other') NOT NULL DEFAULT 'call',
            referral_customer_id INT(11) NULL,
            status ENUM('new','contacted','quoted','negotiating','won','lost','dropped') NOT NULL DEFAULT 'new',
            priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
            owner_user_id INT(11) NULL,
            expected_install_date DATE NULL,
            quote_amount DECIMAL(10,2) NULL,
            lost_reason VARCHAR(255) NULL,
            converted_customer_id INT(11) NULL,
            converted_at DATETIME NULL,
            last_contacted_at DATETIME NULL,
            next_followup_date DATE NULL,
            notes TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_owner (owner_user_id),
            INDEX idx_phone (phone),
            INDEX idx_followup (next_followup_date),
            INDEX idx_deleted (is_deleted),
            CONSTRAINT fk_lead_area FOREIGN KEY (area_id) REFERENCES areas(id),
            CONSTRAINT fk_lead_pkg FOREIGN KEY (package_interested_id) REFERENCES packages(id),
            CONSTRAINT fk_lead_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
            CONSTRAINT fk_lead_ref_cust FOREIGN KEY (referral_customer_id) REFERENCES customers(id),
            CONSTRAINT fk_lead_conv_cust FOREIGN KEY (converted_customer_id) REFERENCES customers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($ld_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "leads" created or already exists</div>';
        }

        // demo equipment
        try {
            $eq_check = $conn->query("SELECT COUNT(*) c FROM equipment");
            if ((int)($eq_check->fetch_assoc()['c'] ?? 1) === 0) {
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0001' LIMIT 1"); $c1 = $r->fetch_assoc(); $c1 = $c1 ? (int)$c1['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0002' LIMIT 1"); $c2 = $r->fetch_assoc(); $c2 = $c2 ? (int)$c2['id'] : 0;
                $r = $conn->query("SELECT id FROM customers WHERE customer_code='CUST-0003' LIMIT 1"); $c3 = $r->fetch_assoc(); $c3 = $c3 ? (int)$c3['id'] : 0;

                // 5 routers — 3 assigned, 2 in stock
                $demo_eq = [
                    ['router', 'MikroTik', 'hAP ac²',     'MK-RT-001-A1B2', '11:22:33:44:55:01', 'assigned',   $c1, '2025-08-12 10:00:00', 8500.00,  '2025-07-20', 'Mikrotik Distributor'],
                    ['router', 'MikroTik', 'hAP ac²',     'MK-RT-002-C3D4', '11:22:33:44:55:02', 'assigned',   $c2, '2025-11-03 11:30:00', 8500.00,  '2025-10-15', 'Mikrotik Distributor'],
                    ['router', 'MikroTik', 'hAP ac²',     'MK-RT-003-E5F6', '11:22:33:44:55:03', 'assigned',   $c3, '2026-01-20 09:15:00', 8500.00,  '2026-01-10', 'Mikrotik Distributor'],
                    ['router', 'TP-Link',  'Archer C6',   'TP-RT-101-G7H8', '11:22:33:44:55:04', 'in_stock',   null, null,                  4500.00,  '2026-04-02', 'TP-Link Authorized Dealer'],
                    ['router', 'TP-Link',  'Archer C6',   'TP-RT-102-I9J0', '11:22:33:44:55:05', 'in_stock',   null, null,                  4500.00,  '2026-04-02', 'TP-Link Authorized Dealer'],
                    ['onu',    'Huawei',   'HG8546M',     'HW-ONU-001-K1L2', '11:22:33:44:55:06', 'in_stock',  null, null,                  6000.00,  '2026-03-15', 'Huawei Distributor'],
                ];
                foreach ($demo_eq as $e) {
                    if ($e[6] === null) {
                        $ins = $conn->prepare("INSERT INTO equipment (type, brand, model, serial, mac, status, purchase_cost, purchase_date, vendor_name) VALUES (?,?,?,?,?,?,?,?,?)");
                        $ins->bind_param("ssssssdss", $e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $e[8], $e[9], $e[10]);
                    } else {
                        $ins = $conn->prepare("INSERT INTO equipment (type, brand, model, serial, mac, status, assigned_to_customer_id, assigned_at, purchase_cost, purchase_date, vendor_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                        $ins->bind_param("ssssssisdss", $e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $e[6], $e[7], $e[8], $e[9], $e[10]);
                    }
                    $ins->execute();
                    $ins->close();
                }
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo equipment seeded (6 items)</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Equipment seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // demo leads
        try {
            $ld_check = $conn->query("SELECT COUNT(*) c FROM leads");
            if ((int)($ld_check->fetch_assoc()['c'] ?? 1) === 0) {
                $r = $conn->query("SELECT id FROM users WHERE username='admin' LIMIT 1"); $admin_id = $r->fetch_assoc(); $admin_id = $admin_id ? (int)$admin_id['id'] : null;
                $r = $conn->query("SELECT id FROM areas WHERE name='Johar Town Block H' LIMIT 1"); $a1 = $r->fetch_assoc(); $a1 = $a1 ? (int)$a1['id'] : null;
                $r = $conn->query("SELECT id FROM areas WHERE name='DHA Phase 5' LIMIT 1");        $a2 = $r->fetch_assoc(); $a2 = $a2 ? (int)$a2['id'] : null;
                $r = $conn->query("SELECT id FROM packages WHERE name='Home Pro 25Mbps' LIMIT 1"); $p2 = $r->fetch_assoc(); $p2 = $p2 ? (int)$p2['id'] : null;
                $r = $conn->query("SELECT id FROM packages WHERE name='Business Fiber 50Mbps' LIMIT 1"); $p3 = $r->fetch_assoc(); $p3 = $p3 ? (int)$p3['id'] : null;
                // fallback: if named areas/packages missing, use first available so FK passes
                if ($a1 === null) { $r = $conn->query("SELECT id FROM areas LIMIT 1");    if ($r && ($x = $r->fetch_assoc())) $a1 = (int)$x['id']; }
                if ($a2 === null) { $r = $conn->query("SELECT id FROM areas LIMIT 1 OFFSET 1"); if ($r && ($x = $r->fetch_assoc())) $a2 = (int)$x['id']; else $a2 = $a1; }
                if ($p2 === null) { $r = $conn->query("SELECT id FROM packages LIMIT 1"); if ($r && ($x = $r->fetch_assoc())) $p2 = (int)$x['id']; }
                if ($p3 === null) { $r = $conn->query("SELECT id FROM packages LIMIT 1 OFFSET 1"); if ($r && ($x = $r->fetch_assoc())) $p3 = (int)$x['id']; else $p3 = $p2; }
                // skip leads seed entirely if no admin or no areas — FK would fail
                if ($admin_id === null || $a1 === null) {
                    echo '<div class="log-item log-warning"><i class="fas fa-info-circle"></i> Leads seed skipped — no admin user or no areas yet (run setup again after areas exist)</div>';
                    throw new Exception('skip leads seed (deps missing)');
                }

                $demo_leads = [
                    ['Sara Iqbal',     '03001234500', null,          'sara.i@example.com', 'House 22, Johar Town Block H', $a1, $p2, 'whatsapp', 'new',         'medium', $admin_id, null,          null,    'Initial enquiry on WhatsApp; wants 25Mbps for home use'],
                    ['Bilal Hussain',  '03219876500', null,          null,                   'Plot 401, DHA Phase 5',         $a2, $p3, 'walk_in',  'quoted',      'high',   $admin_id, '2026-05-15', 5000.00, 'Quoted business fiber, awaiting approval from spouse'],
                    ['Hina Saeed',     '03334567000', '04212345678', 'hina.s@example.com',   'House 9, Block H',              $a1, $p2, 'referral', 'negotiating', 'high',   $admin_id, '2026-05-12', 2500.00, 'Referred by CUST-0001; negotiating on installation fee'],
                    ['Faisal Ahmed',   '03157654300', null,          null,                   null,                            $a2, null, 'call',     'lost',        'low',    $admin_id, null,          null,    'Decided to go with competitor; price-sensitive'],
                ];
                foreach ($demo_leads as $l) {
                    $ins = $conn->prepare("INSERT INTO leads (full_name, phone, alternate_phone, email, address, area_id, package_interested_id, source, status, priority, owner_user_id, expected_install_date, quote_amount, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    // types: s s s s s i i s s s i s d s = 14
                    $ins->bind_param("sssssiisssisds", $l[0], $l[1], $l[2], $l[3], $l[4], $l[5], $l[6], $l[7], $l[8], $l[9], $l[10], $l[11], $l[12], $l[13]);
                    $ins->execute();
                    $ins->close();
                }
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo leads seeded (4 items)</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Leads seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        // ─── /EXPANSION ────────────────────────────────────────────────

        // ─── /ISP TABLES ───────────────────────────────────────────────

        // Add profile_image column to users table if it doesn't exist
        $check_profile_column = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
        if ($check_profile_column && $check_profile_column->num_rows == 0) {
            $alter_profile = "ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL AFTER email";
            if ($conn->query($alter_profile) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Column "profile_image" added to users table</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error adding profile_image column: ' . $conn->error . '</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Column "profile_image" already exists</div>';
        }

        // Create activity_logs table
        $activity_logs_sql = "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(50) NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45) NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_username (username),
            INDEX idx_action (action),
            INDEX idx_timestamp (timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($activity_logs_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "activity_logs" created successfully or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating activity_logs table: ' . $conn->error . '</div>';
        }

        // Create system_settings table for admin controls
        $settings_sql = "CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($settings_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "system_settings" created successfully or already exists</div>';

            // Insert default setting for user profile uploads (enabled by default)
            $check_setting = $conn->query("SELECT id FROM system_settings WHERE setting_key = 'allow_user_profile_uploads'");
            if ($check_setting->num_rows == 0) {
                $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('allow_user_profile_uploads', '1')");
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default setting "allow_user_profile_uploads" created</div>';
            }
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating system_settings table: ' . $conn->error . '</div>';
        }

        // Create uploads directory for profile images
        echo '<br><div class="log-item log-info"><i class="fas fa-folder"></i> <strong>Creating Upload Directories...</strong></div>';

        $upload_base = __DIR__ . '/uploads';
        $upload_profiles = __DIR__ . '/uploads/profiles';

        // Create base uploads directory
        if (!file_exists($upload_base)) {
            if (mkdir($upload_base, 0755, true)) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Directory "uploads/" created successfully</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to create "uploads/" directory</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Directory "uploads/" already exists</div>';
        }

        // Create profiles subdirectory
        if (!file_exists($upload_profiles)) {
            if (mkdir($upload_profiles, 0755, true)) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Directory "uploads/profiles/" created successfully</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to create "uploads/profiles/" directory</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Directory "uploads/profiles/" already exists</div>';
        }

        // Test write permissions
        $test_file = $upload_profiles . '/test.txt';
        if (file_put_contents($test_file, 'test') !== false) {
            unlink($test_file);
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Upload directory is writable (permissions OK)</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Warning: Upload directory may not be writable. Check permissions.</div>';
        }

        // Create .htaccess for security
        $htaccess_content = "# Protect uploads directory\n";
        $htaccess_content .= "<Files ~ \"\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|htm|shtml|sh|cgi)$\">\n";
        $htaccess_content .= "    deny from all\n";
        $htaccess_content .= "</Files>\n";
        $htaccess_content .= "\n# Allow image files only\n";
        $htaccess_content .= "<FilesMatch \"\\.(jpg|jpeg|png|gif|webp)$\">\n";
        $htaccess_content .= "    allow from all\n";
        $htaccess_content .= "</FilesMatch>\n";

        $htaccess_file = $upload_base . '/.htaccess';
        if (file_put_contents($htaccess_file, $htaccess_content) !== false) {
            echo '<div class="log-item log-success"><i class="fas fa-shield-alt"></i> Security .htaccess file created in uploads directory</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Warning: Could not create security .htaccess file</div>';
        }

        // Add UI customization columns to users table
        echo '<br><div class="log-item log-info"><i class="fas fa-palette"></i> <strong>Setting up UI Customization...</strong></div>';

        // Check and add theme_primary column
        $check_theme_primary = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_primary'");
        if ($check_theme_primary && $check_theme_primary->num_rows == 0) {
            $alter_theme_primary = "ALTER TABLE users ADD COLUMN theme_primary VARCHAR(20) DEFAULT '#001f3f' AFTER profile_image";
            if ($conn->query($alter_theme_primary) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Column "theme_primary" added to users table</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error adding theme_primary column: ' . $conn->error . '</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Column "theme_primary" already exists</div>';
        }

        // Check and add theme_secondary column
        $check_theme_secondary = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_secondary'");
        if ($check_theme_secondary && $check_theme_secondary->num_rows == 0) {
            $alter_theme_secondary = "ALTER TABLE users ADD COLUMN theme_secondary VARCHAR(20) DEFAULT '#003366' AFTER theme_primary";
            if ($conn->query($alter_theme_secondary) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Column "theme_secondary" added to users table</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error adding theme_secondary column: ' . $conn->error . '</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Column "theme_secondary" already exists</div>';
        }

        // Check and add theme_accent column
        $check_theme_accent = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_accent'");
        if ($check_theme_accent && $check_theme_accent->num_rows == 0) {
            $alter_theme_accent = "ALTER TABLE users ADD COLUMN theme_accent VARCHAR(20) DEFAULT '#0074D9' AFTER theme_secondary";
            if ($conn->query($alter_theme_accent) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Column "theme_accent" added to users table</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error adding theme_accent column: ' . $conn->error . '</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Column "theme_accent" already exists</div>';
        }

        // Check and add theme_mode column (light/dark preference)
        $check_theme_mode = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_mode'");
        if ($check_theme_mode && $check_theme_mode->num_rows == 0) {
            $alter_theme_mode = "ALTER TABLE users ADD COLUMN theme_mode VARCHAR(10) DEFAULT 'light' AFTER theme_accent";
            if ($conn->query($alter_theme_mode) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Column "theme_mode" added to users table</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error adding theme_mode column: ' . $conn->error . '</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Column "theme_mode" already exists</div>';
        }

        // Add site branding default settings
        echo '<br><div class="log-item log-info"><i class="fas fa-paint-brush"></i> <strong>Setting up Site Branding...</strong></div>';

        $branding_defaults = [
            'site_name' => 'Dashboard System',
            'site_logo' => 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg',
            'copyright_text' => '&copy; 2026 Dashboard System. All rights reserved.',
            'currency_symbol' => '$',
            'default_language' => 'en',
            'show_forgot_password' => '1',
            'maintenance_mode' => '0'
        ];

        foreach ($branding_defaults as $key => $value) {
            $check = $conn->query("SELECT id FROM system_settings WHERE setting_key = '$key'");
            if ($check->num_rows == 0) {
                $stmt_brand = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt_brand->bind_param("ss", $key, $value);
                $stmt_brand->execute();
                $stmt_brand->close();
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default setting "' . $key . '" created</div>';
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Setting "' . $key . '" already exists</div>';
            }
        }

        // Add email_verified column to users table
        echo '<br><div class="log-item log-info"><i class="fas fa-envelope-open"></i> <strong>Setting up Email Verification...</strong></div>';

        $check_email_verified = $conn->query("SHOW COLUMNS FROM users LIKE 'email_verified'");
        if ($check_email_verified && $check_email_verified->num_rows == 0) {
            $alter_ev = "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0 AFTER email";
            if ($conn->query($alter_ev) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Column "email_verified" added to users table</div>';
                // Mark existing users as verified
                $conn->query("UPDATE users SET email_verified = 1");
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Existing users marked as email verified</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error adding email_verified column: ' . $conn->error . '</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Column "email_verified" already exists</div>';
        }

        // Create email_verifications table
        $ev_sql = "CREATE TABLE IF NOT EXISTS email_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(100) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL DEFAULT NULL,
            used TINYINT(1) DEFAULT 0,
            INDEX idx_user_id (user_id),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($ev_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "email_verifications" created successfully or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating email_verifications table: ' . $conn->error . '</div>';
        }

        // Create password_resets table
        $pr_sql = "CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL DEFAULT NULL,
            used TINYINT(1) DEFAULT 0,
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($pr_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "password_resets" created successfully or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating password_resets table: ' . $conn->error . '</div>';
        }

        // Create remember_tokens table
        $rt_sql = "CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL DEFAULT NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_token (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($rt_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "remember_tokens" created successfully or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating remember_tokens table: ' . $conn->error . '</div>';
        }

        // Create login_attempts table
        echo '<br><div class="log-item log-info"><i class="fas fa-shield-alt"></i> <strong>Setting up Login Attempts Tracking...</strong></div>';

        $login_attempts_sql = "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_attempt_time (attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($login_attempts_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "login_attempts" created successfully or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating login_attempts table: ' . $conn->error . '</div>';
        }

        // Create user_sessions table
        echo '<br><div class="log-item log-info"><i class="fas fa-desktop"></i> <strong>Setting up Session Management...</strong></div>';

        $user_sessions_sql = "CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_id VARCHAR(128) NOT NULL UNIQUE,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            force_logout TINYINT(1) DEFAULT 0,
            INDEX idx_user_id (user_id),
            INDEX idx_session_id (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($user_sessions_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "user_sessions" created successfully or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating user_sessions table: ' . $conn->error . '</div>';
        }

        // Create notifications table
        echo '<br><div class="log-item log-info"><i class="fas fa-bell"></i> <strong>Setting up Notifications...</strong></div>';

        $notifications_sql = "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info','success','warning','danger') DEFAULT 'info',
            is_read TINYINT(1) DEFAULT 0,
            link VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_user_read (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($notifications_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "notifications" created successfully or already exists</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating notifications table: ' . $conn->error . '</div>';
        }

        // ─── TIER 2 NEW TABLES — vendors, bank_accounts, collection_runs, collection_visits ───
        echo '<br><div class="log-item log-info"><i class="fas fa-database"></i> <strong>Setting up vendors / bank accounts / collection runs...</strong></div>';

        // vendors — expense vendors
        $vendors_sql = "CREATE TABLE IF NOT EXISTS vendors (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_code VARCHAR(30) NOT NULL,
            vendor_name VARCHAR(200) NOT NULL,
            contact_person VARCHAR(150) NULL,
            phone VARCHAR(20) NULL,
            alternate_phone VARCHAR(20) NULL,
            email VARCHAR(150) NULL,
            address TEXT NULL,
            city VARCHAR(100) NULL,
            ntn VARCHAR(50) NULL,
            gst_number VARCHAR(50) NULL,
            tax_id VARCHAR(50) NULL,
            bank_name VARCHAR(150) NULL,
            bank_account_title VARCHAR(150) NULL,
            bank_account_number VARCHAR(50) NULL,
            iban VARCHAR(50) NULL,
            payment_terms VARCHAR(100) NULL,
            opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            current_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_vendor_code (vendor_code),
            KEY idx_vendor_name (vendor_name),
            KEY idx_status (status, is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try {
            if ($conn->query($vendors_sql) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "vendors" created successfully or already exists</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating vendors table: ' . $conn->error . '</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> vendors error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // bank_accounts — company's own bank accounts
        $bank_accounts_sql = "CREATE TABLE IF NOT EXISTS bank_accounts (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_name VARCHAR(150) NOT NULL,
            account_title VARCHAR(150) NOT NULL,
            bank_name VARCHAR(150) NOT NULL,
            branch_name VARCHAR(150) NULL,
            account_number VARCHAR(50) NOT NULL,
            iban VARCHAR(50) NULL,
            swift_code VARCHAR(20) NULL,
            account_type ENUM('current','savings','cash','jazzcash','easypaisa','other') NOT NULL DEFAULT 'current',
            currency VARCHAR(3) NOT NULL DEFAULT 'PKR',
            opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            current_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            status ENUM('active','inactive','closed') NOT NULL DEFAULT 'active',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status, is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try {
            if ($conn->query($bank_accounts_sql) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "bank_accounts" created successfully or already exists</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating bank_accounts table: ' . $conn->error . '</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> bank_accounts error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // collection_runs — collector daybook
        $collection_runs_sql = "CREATE TABLE IF NOT EXISTS collection_runs (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            collector_user_id INT(11) NOT NULL,
            collection_date DATE NOT NULL,
            opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_collected_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_collected_online DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_expenses_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
            closing_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            deposited_to_bank_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            deposit_date DATE NULL,
            deposit_reference VARCHAR(100) NULL,
            deposit_bank_account_id INT(11) NULL,
            status ENUM('open','closed','reconciled','disputed') NOT NULL DEFAULT 'open',
            closed_at DATETIME NULL,
            verified_by_user_id INT(11) NULL,
            verified_at DATETIME NULL,
            discrepancy_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            discrepancy_reason VARCHAR(255) NULL,
            actual_visits_count INT(11) NOT NULL DEFAULT 0,
            successful_collections_count INT(11) NOT NULL DEFAULT 0,
            failed_visits_count INT(11) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_collector_date (collector_user_id, collection_date),
            KEY idx_status (status),
            KEY idx_date (collection_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try {
            if ($conn->query($collection_runs_sql) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "collection_runs" created successfully or already exists</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating collection_runs table: ' . $conn->error . '</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> collection_runs error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // collection_visits — per-customer visit log
        $collection_visits_sql = "CREATE TABLE IF NOT EXISTS collection_visits (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            collection_run_id INT(11) NULL,
            customer_id INT(11) NOT NULL,
            collector_user_id INT(11) NOT NULL,
            visit_date DATE NOT NULL,
            visit_time TIME NULL,
            outcome ENUM('collected','promised','refused','not_home','disconnected_request','dispute','partial') NOT NULL DEFAULT 'collected',
            amount_collected DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_method ENUM('cash','online') NOT NULL DEFAULT 'cash',
            promised_amount DECIMAL(10,2) NULL,
            promised_date DATE NULL,
            payment_id INT(11) NULL,
            gps_lat DECIMAL(10,7) NULL,
            gps_lng DECIMAL(10,7) NULL,
            photo_path VARCHAR(255) NULL,
            signature_path VARCHAR(255) NULL,
            reason_for_no_payment VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_run (collection_run_id),
            KEY idx_customer_date (customer_id, visit_date),
            KEY idx_collector_date (collector_user_id, visit_date),
            KEY idx_outcome (outcome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try {
            if ($conn->query($collection_visits_sql) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "collection_visits" created successfully or already exists</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating collection_visits table: ' . $conn->error . '</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> collection_visits error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // Add SMTP default settings
        echo '<br><div class="log-item log-info"><i class="fas fa-envelope"></i> <strong>Setting up SMTP Email...</strong></div>';

        $smtp_defaults = [
            'smtp_enabled' => '0',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_from_email' => '',
            'smtp_from_name' => 'Dashboard System',
            'smtp_encryption' => 'tls',
            'email_verification_enabled' => '0'
        ];

        foreach ($smtp_defaults as $key => $value) {
            $check = $conn->query("SELECT id FROM system_settings WHERE setting_key = '$key'");
            if ($check->num_rows == 0) {
                $stmt_smtp = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt_smtp->bind_param("ss", $key, $value);
                $stmt_smtp->execute();
                $stmt_smtp->close();
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default setting "' . $key . '" created</div>';
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Setting "' . $key . '" already exists</div>';
            }
        }

        // Add google_id column to users table for Google OAuth
        echo '<br><div class="log-item log-info"><i class="fab fa-google"></i> <strong>Setting up Google OAuth...</strong></div>';

        $check_google_id = $conn->query("SHOW COLUMNS FROM users LIKE 'google_id'");
        if ($check_google_id && $check_google_id->num_rows == 0) {
            $alter_google_id = "ALTER TABLE users ADD COLUMN google_id VARCHAR(255) DEFAULT NULL AFTER theme_mode";
            if ($conn->query($alter_google_id) === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Column "google_id" added to users table</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error adding google_id column: ' . $conn->error . '</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Column "google_id" already exists</div>';
        }

        // Add default OAuth settings
        $oauth_defaults = [
            'google_oauth_enabled' => '0',
            'google_client_id' => '',
            'google_client_secret' => '',
            'google_redirect_uri' => ''
        ];

        foreach ($oauth_defaults as $key => $value) {
            $check = $conn->query("SELECT id FROM system_settings WHERE setting_key = '$key'");
            if ($check->num_rows == 0) {
                $stmt_oauth = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt_oauth->bind_param("ss", $key, $value);
                $stmt_oauth->execute();
                $stmt_oauth->close();
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default setting "' . $key . '" created</div>';
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Setting "' . $key . '" already exists</div>';
            }
        }


        // ─── TIER 2 — comprehensive ISP business columns (idempotent) ───
        echo '<br><div class="log-item log-info"><i class="fas fa-layer-group"></i> <strong>Tier 2 enrichment...</strong></div>';

        // customers — network / pppoe
        $addColIfMissing('customers', 'pppoe_username',          "pppoe_username VARCHAR(100) NULL");
        $addColIfMissing('customers', 'pppoe_password',          "pppoe_password VARCHAR(255) NULL");
        $addColIfMissing('customers', 'connection_medium',       "connection_medium ENUM('fiber','wireless','ethernet','dsl','vdsl') NOT NULL DEFAULT 'fiber'");
        $addColIfMissing('customers', 'nas_id',                  "nas_id INT(11) NULL");
        $addColIfMissing('customers', 'static_ip_address',       "static_ip_address VARCHAR(45) NULL");
        $addColIfMissing('customers', 'subnet_mask',             "subnet_mask VARCHAR(45) NULL");
        $addColIfMissing('customers', 'gateway',                 "gateway VARCHAR(45) NULL");
        $addColIfMissing('customers', 'dns1',                    "dns1 VARCHAR(45) NULL");
        $addColIfMissing('customers', 'dns2',                    "dns2 VARCHAR(45) NULL");
        $addColIfMissing('customers', 'olt_id',                  "olt_id INT(11) NULL");
        $addColIfMissing('customers', 'olt_port',                "olt_port VARCHAR(20) NULL");
        $addColIfMissing('customers', 'pon_port',                "pon_port VARCHAR(20) NULL");
        $addColIfMissing('customers', 'splitter_id',             "splitter_id INT(11) NULL");
        $addColIfMissing('customers', 'splitter_port',           "splitter_port VARCHAR(20) NULL");
        $addColIfMissing('customers', 'ont_serial',              "ont_serial VARCHAR(50) NULL");
        $addColIfMissing('customers', 'ont_loid',                "ont_loid VARCHAR(50) NULL");
        $addColIfMissing('customers', 'signal_strength_dbm',     "signal_strength_dbm DECIMAL(6,2) NULL");
        $addColIfMissing('customers', 'bandwidth_profile_name',  "bandwidth_profile_name VARCHAR(100) NULL");
        $addColIfMissing('customers', 'mac_binding',             "mac_binding TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'cable_length_meters',     "cable_length_meters INT(11) NULL");

        // customers — billing
        $addColIfMissing('customers', 'outstanding_balance',     "outstanding_balance DECIMAL(12,2) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'next_invoice_date',       "next_invoice_date DATE NULL");
        $addColIfMissing('customers', 'credit_limit',            "credit_limit DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'auto_pay_enabled',        "auto_pay_enabled TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'auto_pay_method',         "auto_pay_method VARCHAR(30) NULL");
        $addColIfMissing('customers', 'last_payment_amount',     "last_payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'oldest_overdue_days',     "oldest_overdue_days INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'write_off_status',        "write_off_status ENUM('none','provision','written_off','recovered') NOT NULL DEFAULT 'none'");
        $addColIfMissing('customers', 'write_off_amount',        "write_off_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'legal_action_status',     "legal_action_status ENUM('none','notice_sent','legal','settled') NOT NULL DEFAULT 'none'");

        // customers — kyc / business
        $addColIfMissing('customers', 'customer_type',           "customer_type ENUM('residential','business','enterprise','govt') NOT NULL DEFAULT 'residential'");
        $addColIfMissing('customers', 'business_name',           "business_name VARCHAR(200) NULL");
        $addColIfMissing('customers', 'tax_id',                  "tax_id VARCHAR(50) NULL");
        $addColIfMissing('customers', 'ntn_number',              "ntn_number VARCHAR(50) NULL");
        $addColIfMissing('customers', 'gst_number',              "gst_number VARCHAR(50) NULL");
        $addColIfMissing('customers', 'tax_exempt',              "tax_exempt TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'dob',                     "dob DATE NULL");
        $addColIfMissing('customers', 'gender',                  "gender ENUM('male','female','other') NULL");
        $addColIfMissing('customers', 'nationality',             "nationality VARCHAR(50) NULL");
        $addColIfMissing('customers', 'nearest_landmark',        "nearest_landmark VARCHAR(255) NULL");
        $addColIfMissing('customers', 'whatsapp_number',         "whatsapp_number VARCHAR(20) NULL");

        // customers — assignment / lifecycle
        $addColIfMissing('customers', 'salesperson_user_id',     "salesperson_user_id INT(11) NULL");
        $addColIfMissing('customers', 'installation_user_id',    "installation_user_id INT(11) NULL");
        $addColIfMissing('customers', 'agreement_signed',        "agreement_signed TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'agreement_pdf_path',      "agreement_pdf_path VARCHAR(255) NULL");
        $addColIfMissing('customers', 'agreement_start_date',    "agreement_start_date DATE NULL");
        $addColIfMissing('customers', 'agreement_end_date',      "agreement_end_date DATE NULL");
        $addColIfMissing('customers', 'contract_period_months',  "contract_period_months INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'early_termination_fee',   "early_termination_fee DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('customers', 'disconnection_date',      "disconnection_date DATE NULL");
        $addColIfMissing('customers', 'disconnection_reason',    "disconnection_reason VARCHAR(255) NULL");
        $addColIfMissing('customers', 'vip_status',              "vip_status ENUM('regular','silver','gold','vip') NOT NULL DEFAULT 'regular'");
        $addColIfMissing('customers', 'customer_rating',         "customer_rating ENUM('A','B','C','D') NOT NULL DEFAULT 'B'");
        $addColIfMissing('customers', 'acquisition_source',      "acquisition_source VARCHAR(50) NULL");
        $addColIfMissing('customers', 'referred_by_customer_id', "referred_by_customer_id INT(11) NULL");
        $addColIfMissing('customers', 'portal_pin_hash',         "portal_pin_hash VARCHAR(255) NULL");

        // packages — tax + promo + segment
        $addColIfMissing('packages', 'package_code',              "package_code VARCHAR(30) NULL");
        $addColIfMissing('packages', 'tax_pct',                   "tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
        $addColIfMissing('packages', 'tax_inclusive',             "tax_inclusive TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('packages', 'late_fee_amount',           "late_fee_amount DECIMAL(8,2) NOT NULL DEFAULT 0");
        $addColIfMissing('packages', 'late_fee_pct',              "late_fee_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
        $addColIfMissing('packages', 'late_fee_grace_days',       "late_fee_grace_days INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('packages', 'bandwidth_profile_name',    "bandwidth_profile_name VARCHAR(100) NULL");
        $addColIfMissing('packages', 'night_speed_mbps',          "night_speed_mbps INT(11) NULL");
        $addColIfMissing('packages', 'night_start_time',          "night_start_time TIME NULL");
        $addColIfMissing('packages', 'night_end_time',            "night_end_time TIME NULL");
        $addColIfMissing('packages', 'valid_from',                "valid_from DATE NULL");
        $addColIfMissing('packages', 'valid_until',               "valid_until DATE NULL");
        $addColIfMissing('packages', 'target_segment',            "target_segment ENUM('home','sme','enterprise','wisp') NOT NULL DEFAULT 'home'");
        $addColIfMissing('packages', 'is_promotional',            "is_promotional TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('packages', 'promotional_price',         "promotional_price DECIMAL(10,2) NULL");
        $addColIfMissing('packages', 'promotional_months',        "promotional_months INT(11) NULL");
        $addColIfMissing('packages', 'quota_gb',                  "quota_gb INT(11) NULL");
        $addColIfMissing('packages', 'equipment_included',        "equipment_included TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('packages', 'equipment_rental_charge',   "equipment_rental_charge DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('packages', 'included_static_ip',        "included_static_ip TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('packages', 'commission_pct_for_sales',  "commission_pct_for_sales DECIMAL(5,2) NOT NULL DEFAULT 0");

        // areas — geo + coverage
        $addColIfMissing('areas', 'area_code',                    "area_code VARCHAR(20) NULL");
        $addColIfMissing('areas', 'country',                      "country VARCHAR(80) NOT NULL DEFAULT 'Pakistan'");
        $addColIfMissing('areas', 'province',                     "province VARCHAR(80) NULL");
        $addColIfMissing('areas', 'district',                     "district VARCHAR(80) NULL");
        $addColIfMissing('areas', 'pin_code',                     "pin_code VARCHAR(20) NULL");
        $addColIfMissing('areas', 'gps_lat',                      "gps_lat DECIMAL(10,7) NULL");
        $addColIfMissing('areas', 'gps_lng',                      "gps_lng DECIMAL(10,7) NULL");
        $addColIfMissing('areas', 'coverage_status',              "coverage_status ENUM('active','planned','no_coverage','saturated') NOT NULL DEFAULT 'active'");
        $addColIfMissing('areas', 'coverage_technology',          "coverage_technology SET('fiber','wireless','dsl') NULL");
        $addColIfMissing('areas', 'total_capacity_subscribers',   "total_capacity_subscribers INT(11) NULL");
        $addColIfMissing('areas', 'manager_user_id',              "manager_user_id INT(11) NULL");
        $addColIfMissing('areas', 'default_collector_user_id',    "default_collector_user_id INT(11) NULL");
        $addColIfMissing('areas', 'pop_id',                       "pop_id INT(11) NULL");
        $addColIfMissing('areas', 'competitor_presence',          "competitor_presence VARCHAR(255) NULL");

        // invoices — line items / tax / lifecycle
        $addColIfMissing('invoices', 'subtotal',                       "subtotal DECIMAL(12,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'discount_amount',                "discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'discount_pct',                   "discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'discount_reason',                "discount_reason VARCHAR(255) NULL");
        $addColIfMissing('invoices', 'additional_charges',             "additional_charges DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'additional_charges_description', "additional_charges_description VARCHAR(255) NULL");
        $addColIfMissing('invoices', 'equipment_charges',              "equipment_charges DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'installation_charges',           "installation_charges DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'package_id_at_time',             "package_id_at_time INT(11) NULL");
        $addColIfMissing('invoices', 'package_name_at_time',           "package_name_at_time VARCHAR(150) NULL");
        $addColIfMissing('invoices', 'package_speed_at_time',          "package_speed_at_time INT(11) NULL");
        $addColIfMissing('invoices', 'package_price_at_time',          "package_price_at_time DECIMAL(10,2) NULL");
        $addColIfMissing('invoices', 'tax_pct',                        "tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'tax_breakdown_json',             "tax_breakdown_json TEXT NULL");
        $addColIfMissing('invoices', 'wht_amount',                     "wht_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'wht_pct',                        "wht_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'total_paid',                     "total_paid DECIMAL(12,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'total_due',                      "total_due DECIMAL(12,2) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'paid_date',                      "paid_date DATE NULL");
        $addColIfMissing('invoices', 'payment_method_summary',         "payment_method_summary VARCHAR(100) NULL");
        $addColIfMissing('invoices', 'last_reminder_sent_date',        "last_reminder_sent_date DATE NULL");
        $addColIfMissing('invoices', 'reminder_count',                 "reminder_count INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'pdf_path',                       "pdf_path VARCHAR(255) NULL");
        $addColIfMissing('invoices', 'qr_code_data',                   "qr_code_data VARCHAR(500) NULL");
        $addColIfMissing('invoices', 'cancel_date',                    "cancel_date DATE NULL");
        $addColIfMissing('invoices', 'cancel_reason',                  "cancel_reason VARCHAR(255) NULL");
        $addColIfMissing('invoices', 'cancelled_by_user_id',           "cancelled_by_user_id INT(11) NULL");
        $addColIfMissing('invoices', 'notes_internal',                 "notes_internal TEXT NULL");
        $addColIfMissing('invoices', 'customer_notes',                 "customer_notes TEXT NULL");
        $addColIfMissing('invoices', 'currency',                       "currency VARCHAR(3) NOT NULL DEFAULT 'PKR'");
        $addColIfMissing('invoices', 'invoice_type',                   "invoice_type ENUM('recurring','one_time','credit_note','proforma','adjustment') NOT NULL DEFAULT 'recurring'");
        $addColIfMissing('invoices', 'parent_invoice_id',              "parent_invoice_id INT(11) NULL");
        $addColIfMissing('invoices', 'is_credit_note',                 "is_credit_note TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('invoices', 'proration_days',                 "proration_days INT(11) NULL");
        $addColIfMissing('invoices', 'proration_amount',               "proration_amount DECIMAL(10,2) NULL");

        // payments — payer / verify / refund / channel
        $addColIfMissing('payments', 'bank_account_id',                "bank_account_id INT(11) NULL");
        $addColIfMissing('payments', 'payer_name',                     "payer_name VARCHAR(150) NULL");
        $addColIfMissing('payments', 'payer_phone',                    "payer_phone VARCHAR(20) NULL");
        $addColIfMissing('payments', 'payer_cnic',                     "payer_cnic VARCHAR(20) NULL");
        $addColIfMissing('payments', 'verified_by_user_id',            "verified_by_user_id INT(11) NULL");
        $addColIfMissing('payments', 'verified_at',                    "verified_at DATETIME NULL");
        $addColIfMissing('payments', 'bounce_charges',                 "bounce_charges DECIMAL(8,2) NOT NULL DEFAULT 0");
        $addColIfMissing('payments', 'refund_date',                    "refund_date DATE NULL");
        $addColIfMissing('payments', 'refund_reference',               "refund_reference VARCHAR(100) NULL");
        $addColIfMissing('payments', 'refunded_to_method',             "refunded_to_method VARCHAR(50) NULL");
        $addColIfMissing('payments', 'original_payment_id',            "original_payment_id INT(11) NULL");
        $addColIfMissing('payments', 'is_advance',                     "is_advance TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('payments', 'applied_to_advance_balance',     "applied_to_advance_balance DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('payments', 'branch_id',                      "branch_id INT(11) NULL");
        $addColIfMissing('payments', 'cash_drawer_id',                 "cash_drawer_id INT(11) NULL");
        $addColIfMissing('payments', 'shift_id',                       "shift_id INT(11) NULL");
        $addColIfMissing('payments', 'whatsapp_sent',                  "whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('payments', 'email_sent',                     "email_sent TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('payments', 'sms_sent',                       "sms_sent TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('payments', 'currency',                       "currency VARCHAR(3) NOT NULL DEFAULT 'PKR'");

        // complaints — sla / outage / resolution
        $addColIfMissing('complaints', 'complaint_source',             "complaint_source ENUM('call','whatsapp','portal','walk_in','social','app','email') NOT NULL DEFAULT 'call'");
        $addColIfMissing('complaints', 'severity',                     "severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium'");
        $addColIfMissing('complaints', 'affected_users_count',         "affected_users_count INT(11) NULL");
        $addColIfMissing('complaints', 'parent_complaint_id',          "parent_complaint_id INT(11) NULL");
        $addColIfMissing('complaints', 'is_mass_outage',               "is_mass_outage TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('complaints', 'area_id_affected',             "area_id_affected INT(11) NULL");
        $addColIfMissing('complaints', 'first_response_user_id',       "first_response_user_id INT(11) NULL");
        $addColIfMissing('complaints', 'assigned_at',                  "assigned_at DATETIME NULL");
        $addColIfMissing('complaints', 'acknowledged_at',              "acknowledged_at DATETIME NULL");
        $addColIfMissing('complaints', 'arrived_onsite_at',            "arrived_onsite_at DATETIME NULL");
        $addColIfMissing('complaints', 'work_started_at',              "work_started_at DATETIME NULL");
        $addColIfMissing('complaints', 'work_completed_at',            "work_completed_at DATETIME NULL");
        $addColIfMissing('complaints', 'resolution_category',          "resolution_category ENUM('cable_cut','equipment_fault','config','outage','billing','customer_error','signal_low','olt_issue','no_fault_found','other') NULL");
        $addColIfMissing('complaints', 'resolution_summary',           "resolution_summary TEXT NULL");
        $addColIfMissing('complaints', 'parts_replaced_json',          "parts_replaced_json TEXT NULL");
        $addColIfMissing('complaints', 'cost_to_resolve',              "cost_to_resolve DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('complaints', 'chargeable_to_customer',       "chargeable_to_customer TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('complaints', 'customer_charge_amount',       "customer_charge_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('complaints', 'customer_invoice_id',          "customer_invoice_id INT(11) NULL");
        $addColIfMissing('complaints', 'reopen_count',                 "reopen_count INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('complaints', 'reopened_reason',              "reopened_reason VARCHAR(255) NULL");
        $addColIfMissing('complaints', 'closed_at',                    "closed_at DATETIME NULL");
        $addColIfMissing('complaints', 'customer_satisfaction_rating', "customer_satisfaction_rating TINYINT(1) NULL");
        $addColIfMissing('complaints', 'customer_feedback_text',       "customer_feedback_text TEXT NULL");
        $addColIfMissing('complaints', 'feedback_collected_at',        "feedback_collected_at DATETIME NULL");
        $addColIfMissing('complaints', 'escalation_level',             "escalation_level TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('complaints', 'escalation_reason',            "escalation_reason VARCHAR(255) NULL");
        $addColIfMissing('complaints', 'sla_breach_flag',              "sla_breach_flag TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('complaints', 'sla_breach_minutes',           "sla_breach_minutes INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('complaints', 'tags',                         "tags VARCHAR(255) NULL");

        // complaints — extend status enum to include 'on_hold' for chevron pipeline
        $cur_status_enum = $conn->query("SHOW COLUMNS FROM complaints LIKE 'status'");
        if ($cur_status_enum && $row_se = $cur_status_enum->fetch_assoc()) {
            if (stripos($row_se['Type'], "'on_hold'") === false) {
                @$conn->query("ALTER TABLE complaints MODIFY COLUMN status ENUM('open','in_progress','on_hold','resolved','closed') NOT NULL DEFAULT 'open'");
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Extended complaints.status with on_hold</div>';
            }
        }

        // expenses — vendor / accounting / status
        $addColIfMissing('expenses', 'expense_number',                 "expense_number VARCHAR(30) NULL");
        $addColIfMissing('expenses', 'due_date',                       "due_date DATE NULL");
        $addColIfMissing('expenses', 'paid_date',                      "paid_date DATE NULL");
        $addColIfMissing('expenses', 'payment_status',                 "payment_status ENUM('unpaid','partial','paid','overdue') NOT NULL DEFAULT 'paid'");
        $addColIfMissing('expenses', 'paid_amount',                    "paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('expenses', 'outstanding_amount',             "outstanding_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('expenses', 'vendor_id',                      "vendor_id INT(11) NULL");
        $addColIfMissing('expenses', 'vendor_invoice_number',          "vendor_invoice_number VARCHAR(100) NULL");
        $addColIfMissing('expenses', 'vendor_invoice_date',            "vendor_invoice_date DATE NULL");
        $addColIfMissing('expenses', 'vendor_invoice_path',            "vendor_invoice_path VARCHAR(255) NULL");
        $addColIfMissing('expenses', 'withholding_tax_amount',         "withholding_tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('expenses', 'wht_pct',                        "wht_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
        $addColIfMissing('expenses', 'net_paid_amount',                "net_paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('expenses', 'gl_account_code',                "gl_account_code VARCHAR(30) NULL");
        $addColIfMissing('expenses', 'cost_center',                    "cost_center VARCHAR(50) NULL");
        $addColIfMissing('expenses', 'area_id',                        "area_id INT(11) NULL");
        $addColIfMissing('expenses', 'branch_id',                      "branch_id INT(11) NULL");
        $addColIfMissing('expenses', 'is_capex',                       "is_capex TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('expenses', 'depreciation_period_months',     "depreciation_period_months INT(11) NULL");
        $addColIfMissing('expenses', 'bank_account_id',                "bank_account_id INT(11) NULL");
        $addColIfMissing('expenses', 'cheque_number',                  "cheque_number VARCHAR(50) NULL");
        $addColIfMissing('expenses', 'cheque_date',                    "cheque_date DATE NULL");
        $addColIfMissing('expenses', 'next_recurrence_date',           "next_recurrence_date DATE NULL");
        $addColIfMissing('expenses', 'parent_recurring_expense_id',    "parent_recurring_expense_id INT(11) NULL");
        $addColIfMissing('expenses', 'rejection_reason',               "rejection_reason VARCHAR(255) NULL");
        $addColIfMissing('expenses', 'rejected_by_user_id',            "rejected_by_user_id INT(11) NULL");
        $addColIfMissing('expenses', 'rejected_at',                    "rejected_at DATETIME NULL");

        // leads — qualification / quote / temperature
        $addColIfMissing('leads', 'lead_code',                         "lead_code VARCHAR(20) NULL");
        $addColIfMissing('leads', 'customer_type',                     "customer_type ENUM('residential','business','enterprise','govt') NOT NULL DEFAULT 'residential'");
        $addColIfMissing('leads', 'business_name',                     "business_name VARCHAR(200) NULL");
        $addColIfMissing('leads', 'number_of_employees',               "number_of_employees INT(11) NULL");
        $addColIfMissing('leads', 'current_provider',                  "current_provider VARCHAR(150) NULL");
        $addColIfMissing('leads', 'current_provider_price',            "current_provider_price DECIMAL(10,2) NULL");
        $addColIfMissing('leads', 'current_provider_speed',            "current_provider_speed INT(11) NULL");
        $addColIfMissing('leads', 'pain_point',                        "pain_point VARCHAR(500) NULL");
        $addColIfMissing('leads', 'reason_to_switch',                  "reason_to_switch VARCHAR(500) NULL");
        $addColIfMissing('leads', 'decision_timeline',                 "decision_timeline ENUM('immediate','1_month','3_months','exploring') NULL");
        $addColIfMissing('leads', 'budget_min',                        "budget_min DECIMAL(10,2) NULL");
        $addColIfMissing('leads', 'budget_max',                        "budget_max DECIMAL(10,2) NULL");
        $addColIfMissing('leads', 'usage_type',                        "usage_type ENUM('streaming','gaming','wfh','business','mixed') NULL");
        $addColIfMissing('leads', 'concurrent_users',                  "concurrent_users INT(11) NULL");
        $addColIfMissing('leads', 'gps_lat',                           "gps_lat DECIMAL(10,7) NULL");
        $addColIfMissing('leads', 'gps_lng',                           "gps_lng DECIMAL(10,7) NULL");
        $addColIfMissing('leads', 'feasibility_status',                "feasibility_status ENUM('feasible','not_feasible','needs_survey','cabling_required','no_coverage') NOT NULL DEFAULT 'needs_survey'");
        $addColIfMissing('leads', 'feasibility_checked_at',            "feasibility_checked_at DATETIME NULL");
        $addColIfMissing('leads', 'feasibility_notes',                 "feasibility_notes VARCHAR(500) NULL");
        $addColIfMissing('leads', 'survey_required',                   "survey_required TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('leads', 'survey_date',                       "survey_date DATE NULL");
        $addColIfMissing('leads', 'surveyed_by_user_id',               "surveyed_by_user_id INT(11) NULL");
        $addColIfMissing('leads', 'survey_result',                     "survey_result VARCHAR(500) NULL");
        $addColIfMissing('leads', 'installation_distance_meters',      "installation_distance_meters INT(11) NULL");
        $addColIfMissing('leads', 'referral_amount_promised',          "referral_amount_promised DECIMAL(10,2) NULL");
        $addColIfMissing('leads', 'last_meeting_date',                 "last_meeting_date DATE NULL");
        $addColIfMissing('leads', 'meeting_count',                     "meeting_count INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('leads', 'quote_sent_at',                     "quote_sent_at DATETIME NULL");
        $addColIfMissing('leads', 'quote_pdf_path',                    "quote_pdf_path VARCHAR(255) NULL");
        $addColIfMissing('leads', 'quote_expiry_date',                 "quote_expiry_date DATE NULL");
        $addColIfMissing('leads', 'quote_revisions_count',             "quote_revisions_count INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('leads', 'discount_offered_pct',              "discount_offered_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
        $addColIfMissing('leads', 'assigned_at',                       "assigned_at DATETIME NULL");
        $addColIfMissing('leads', 'disqualification_reason',           "disqualification_reason ENUM('no_coverage','no_budget','competitor_locked','duplicate','not_interested','no_response','other') NULL");
        $addColIfMissing('leads', 'whatsapp_consent',                  "whatsapp_consent TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('leads', 'email_consent',                     "email_consent TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('leads', 'lead_score',                        "lead_score INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('leads', 'temperature',                       "temperature ENUM('hot','warm','cold') NOT NULL DEFAULT 'warm'");

        // equipment — asset / warranty / depreciation / install
        $addColIfMissing('equipment', 'asset_tag',                     "asset_tag VARCHAR(30) NULL");
        $addColIfMissing('equipment', 'imei',                          "imei VARCHAR(50) NULL");
        $addColIfMissing('equipment', 'firmware_version',              "firmware_version VARCHAR(50) NULL");
        $addColIfMissing('equipment', 'hardware_revision',             "hardware_revision VARCHAR(50) NULL");
        $addColIfMissing('equipment', 'purchase_invoice_id',           "purchase_invoice_id INT(11) NULL");
        $addColIfMissing('equipment', 'warranty_start_date',           "warranty_start_date DATE NULL");
        $addColIfMissing('equipment', 'warranty_end_date',             "warranty_end_date DATE NULL");
        $addColIfMissing('equipment', 'warranty_contact',              "warranty_contact VARCHAR(255) NULL");
        $addColIfMissing('equipment', 'expected_lifetime_months',      "expected_lifetime_months INT(11) NULL");
        $addColIfMissing('equipment', 'depreciation_method',           "depreciation_method ENUM('straight_line','reducing_balance','none') NOT NULL DEFAULT 'straight_line'");
        $addColIfMissing('equipment', 'depreciation_pct_per_year',     "depreciation_pct_per_year DECIMAL(5,2) NOT NULL DEFAULT 20");
        $addColIfMissing('equipment', 'current_book_value',            "current_book_value DECIMAL(12,2) NULL");
        $addColIfMissing('equipment', 'salvage_value',                 "salvage_value DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('equipment', 'is_rented',                     "is_rented TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('equipment', 'monthly_rental_charge',         "monthly_rental_charge DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('equipment', 'is_sold',                       "is_sold TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('equipment', 'sold_amount',                   "sold_amount DECIMAL(10,2) NULL");
        $addColIfMissing('equipment', 'is_loaned',                     "is_loaned TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('equipment', 'loan_due_date',                 "loan_due_date DATE NULL");
        $addColIfMissing('equipment', 'assigned_user_id',              "assigned_user_id INT(11) NULL");
        $addColIfMissing('equipment', 'area_id',                       "area_id INT(11) NULL");
        $addColIfMissing('equipment', 'parent_equipment_id',           "parent_equipment_id INT(11) NULL");
        $addColIfMissing('equipment', 'port_number',                   "port_number VARCHAR(20) NULL");
        $addColIfMissing('equipment', 'last_inspection_date',          "last_inspection_date DATE NULL");
        $addColIfMissing('equipment', 'next_inspection_date',          "next_inspection_date DATE NULL");
        $addColIfMissing('equipment', 'last_maintenance_date',         "last_maintenance_date DATE NULL");
        $addColIfMissing('equipment', 'equipment_condition',           "equipment_condition ENUM('new','good','fair','needs_repair','scrapped') NOT NULL DEFAULT 'good'");
        $addColIfMissing('equipment', 'decommissioned_date',           "decommissioned_date DATE NULL");
        $addColIfMissing('equipment', 'decommissioned_reason',         "decommissioned_reason VARCHAR(255) NULL");
        $addColIfMissing('equipment', 'disposed_date',                 "disposed_date DATE NULL");
        $addColIfMissing('equipment', 'disposed_to',                   "disposed_to VARCHAR(150) NULL");
        $addColIfMissing('equipment', 'disposal_value',                "disposal_value DECIMAL(10,2) NULL");
        $addColIfMissing('equipment', 'gps_lat',                       "gps_lat DECIMAL(10,7) NULL");
        $addColIfMissing('equipment', 'gps_lng',                       "gps_lng DECIMAL(10,7) NULL");
        $addColIfMissing('equipment', 'mount_height_meters',           "mount_height_meters INT(11) NULL");
        $addColIfMissing('equipment', 'power_source',                  "power_source ENUM('mains','battery','solar','mixed') NULL");
        $addColIfMissing('equipment', 'battery_backup_hours',          "battery_backup_hours INT(11) NULL");
        $addColIfMissing('equipment', 'is_critical',                   "is_critical TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('equipment', 'monitoring_enabled',            "monitoring_enabled TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('equipment', 'snmp_community',                "snmp_community VARCHAR(50) NULL");
        $addColIfMissing('equipment', 'snmp_ip',                       "snmp_ip VARCHAR(45) NULL");
        $addColIfMissing('equipment', 'photos_json',                   "photos_json TEXT NULL");

        // users — hr fields
        $addColIfMissing('users', 'employee_code',                     "employee_code VARCHAR(20) NULL");
        $addColIfMissing('users', 'cnic',                              "cnic VARCHAR(20) NULL");
        $addColIfMissing('users', 'dob',                               "dob DATE NULL");
        $addColIfMissing('users', 'gender',                            "gender ENUM('male','female','other') NULL");
        $addColIfMissing('users', 'marital_status',                    "marital_status ENUM('single','married','divorced','widowed') NULL");
        $addColIfMissing('users', 'joining_date',                      "joining_date DATE NULL");
        $addColIfMissing('users', 'confirmation_date',                 "confirmation_date DATE NULL");
        $addColIfMissing('users', 'probation_end_date',                "probation_end_date DATE NULL");
        $addColIfMissing('users', 'resignation_date',                  "resignation_date DATE NULL");
        $addColIfMissing('users', 'termination_date',                  "termination_date DATE NULL");
        $addColIfMissing('users', 'reason_for_leaving',                "reason_for_leaving VARCHAR(255) NULL");
        $addColIfMissing('users', 'permanent_address',                 "permanent_address TEXT NULL");
        $addColIfMissing('users', 'current_address',                   "current_address TEXT NULL");
        $addColIfMissing('users', 'emergency_contact_name',            "emergency_contact_name VARCHAR(150) NULL");
        $addColIfMissing('users', 'emergency_contact_phone',           "emergency_contact_phone VARCHAR(20) NULL");
        $addColIfMissing('users', 'emergency_contact_relation',        "emergency_contact_relation VARCHAR(50) NULL");
        $addColIfMissing('users', 'blood_group',                       "blood_group VARCHAR(5) NULL");
        $addColIfMissing('users', 'qualification',                     "qualification VARCHAR(150) NULL");
        $addColIfMissing('users', 'specialization',                    "specialization VARCHAR(150) NULL");
        $addColIfMissing('users', 'experience_years',                  "experience_years DECIMAL(4,1) NULL");
        $addColIfMissing('users', 'department',                        "department VARCHAR(50) NULL");
        $addColIfMissing('users', 'designation',                       "designation VARCHAR(100) NULL");
        $addColIfMissing('users', 'reporting_to_user_id',              "reporting_to_user_id INT(11) NULL");
        $addColIfMissing('users', 'branch_id',                         "branch_id INT(11) NULL");
        $addColIfMissing('users', 'shift',                             "shift VARCHAR(50) NULL");
        $addColIfMissing('users', 'salary_basic',                      "salary_basic DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'salary_allowances',                 "salary_allowances DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'salary_total',                      "salary_total DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'salary_account_bank_name',          "salary_account_bank_name VARCHAR(150) NULL");
        $addColIfMissing('users', 'salary_account_number',             "salary_account_number VARCHAR(50) NULL");
        $addColIfMissing('users', 'tax_filer_status',                  "tax_filer_status ENUM('filer','non_filer','exempt') NULL");
        $addColIfMissing('users', 'ntn',                               "ntn VARCHAR(50) NULL");
        $addColIfMissing('users', 'eobi_number',                       "eobi_number VARCHAR(50) NULL");
        $addColIfMissing('users', 'pf_number',                         "pf_number VARCHAR(50) NULL");
        $addColIfMissing('users', 'commission_eligible',               "commission_eligible TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'commission_rate_pct',               "commission_rate_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'target_monthly_amount',             "target_monthly_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'target_monthly_count',              "target_monthly_count INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'vehicle_assigned',                  "vehicle_assigned TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'vehicle_number',                    "vehicle_number VARCHAR(50) NULL");
        $addColIfMissing('users', 'mobile_assigned',                   "mobile_assigned TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'mobile_number_company',             "mobile_number_company VARCHAR(20) NULL");
        $addColIfMissing('users', 'laptop_assigned',                   "laptop_assigned TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'laptop_serial',                     "laptop_serial VARCHAR(50) NULL");
        $addColIfMissing('users', 'last_password_change',              "last_password_change DATETIME NULL");
        $addColIfMissing('users', 'password_must_change',              "password_must_change TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'failed_login_count',                "failed_login_count INT(11) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'account_locked_until',              "account_locked_until DATETIME NULL");
        $addColIfMissing('users', 'two_factor_enabled',                "two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0");
        $addColIfMissing('users', 'two_factor_secret',                 "two_factor_secret VARCHAR(100) NULL");
        $addColIfMissing('users', 'signature_image_path',              "signature_image_path VARCHAR(255) NULL");
        $addColIfMissing('users', 'last_login',                        "last_login DATETIME DEFAULT NULL");

        // backfill last_login from latest successful login in activity_logs — one-time, only null rows
        try {
            $ll_chk = @$conn->query("SHOW TABLES LIKE 'activity_logs'");
            if ($ll_chk && $ll_chk->num_rows > 0) {
                $conn->query("UPDATE users u
                    JOIN (
                        SELECT user_id, MAX(timestamp) AS last_ts
                        FROM activity_logs
                        WHERE action LIKE '%Login%' AND action NOT LIKE '%Fail%'
                        GROUP BY user_id
                    ) l ON l.user_id = u.id
                    SET u.last_login = l.last_ts
                    WHERE u.last_login IS NULL");
                echo '<div class="log-item log-info"><i class="fas fa-clock-rotate-left"></i> users.last_login backfilled from activity_logs</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> last_login backfill skipped: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Tier 2 enrichment complete</div>';
        // ─── /TIER 2 ────────────────────────────────────────────────────

        // ─── POST-SEED BALANCE RECOMPUTE ────────────────────────────────
        // Sync invoice paid_amount / balance_amount / status from direct payments
        // (since seed payments use invoice_id directly, not the allocations table).
        try {
            $cnt = $conn->query("UPDATE invoices i
                LEFT JOIN (
                    SELECT invoice_id, COALESCE(SUM(amount),0) AS paid
                    FROM payments
                    WHERE is_deleted = 0
                    GROUP BY invoice_id
                ) p ON p.invoice_id = i.id
                SET
                    i.paid_amount    = COALESCE(p.paid, 0),
                    i.balance_amount = GREATEST(i.total - COALESCE(p.paid, 0), 0),
                    i.status = CASE
                        WHEN COALESCE(p.paid,0) >= i.total THEN 'paid'
                        WHEN COALESCE(p.paid,0) > 0       THEN 'partial'
                        WHEN i.due_date < CURDATE()       THEN 'overdue'
                        ELSE 'unpaid'
                    END
                WHERE i.is_deleted = 0");
            $afff = $conn->affected_rows;
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Invoice balances recomputed (' . (int)$afff . ' rows synced from payments)</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Balance recompute error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // sync customers.last_payment_date from latest payment per customer
        try {
            $conn->query("UPDATE customers c
                LEFT JOIN (
                    SELECT customer_id, MAX(payment_date) AS last_dt
                    FROM payments
                    WHERE is_deleted = 0
                    GROUP BY customer_id
                ) p ON p.customer_id = c.id
                SET c.last_payment_date = p.last_dt
                WHERE c.is_deleted = 0");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Customer last_payment_date synced</div>';
        } catch (Throwable $e) {
            // column may not exist on very old schemas — non-fatal
        }

        // ─── TIER 2 DEMO DATA ───────────────────────────────────────────
        echo '<br><div class="log-item log-info"><i class="fas fa-database"></i> <strong>Seeding Tier 2 demo data...</strong></div>';

        // 1. vendors seed
        try {
            $r = $conn->query("SELECT COUNT(*) AS c FROM vendors WHERE is_deleted=0");
            $vc = $r ? (int)$r->fetch_assoc()['c'] : 0;
            if ($vc === 0) {
                $vendors = [
                    ['VEN-2025-001','PTCL Pakistan','Imran Khan','03001234001',null,'imran@ptcl.demo','PTCL HQ Building','Islamabad','0710101010101','GST-PTCL-001',null,'HBL','PTCL Pakistan','04230000111111','PK36HABB0011223344556677','15 days',0,'Bandwidth provider'],
                    ['VEN-2025-002','Cyber Networks Pvt Ltd','Sara Ahmed','03002345002','03219876502','sara@cybernet.demo','Plot 12, I-9/2','Islamabad','0710102020202','GST-CYB-002',null,'UBL','Cyber Networks Pvt Ltd','02050000222222',null,'30 days',0,'Fiber backbone'],
                    ['VEN-2025-003','Ali Electronics','Ali Raza','03003456003',null,'ali@electronics.demo','Hall Road','Lahore','0710103030303',null,null,'Meezan Bank','Ali Electronics','00010000333333',null,'COD',0,'Router & ONU supplier'],
                    ['VEN-2025-004','Sky Fiber Cables','Bilal Hussain','03004567004',null,'bilal@skyfiber.demo','Industrial Area Sector 27','Karachi','0710104040404','GST-SFC-004',null,'Bank Alfalah','Sky Fiber Cables','01230000444444',null,'45 days',0,'Fiber/cable supplier'],
                    ['VEN-2025-005','Mehran Power Solutions','Hina Khan','03005678005',null,'hina@power.demo','Block A, Gulshan','Karachi','0710105050505',null,null,'HBL','Mehran Power Solutions','04230000555555',null,'COD',0,'UPS & batteries'],
                ];
                $sql = "INSERT INTO vendors (vendor_code, vendor_name, contact_person, phone, alternate_phone, email, address, city, ntn, gst_number, tax_id, bank_name, bank_account_title, bank_account_number, iban, payment_terms, opening_balance, current_balance, notes, status, is_deleted) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'active', 0)";
                $st = $conn->prepare($sql);
                $added = 0;
                foreach ($vendors as $v) {
                    // 16 strings + 1 decimal (opening) + 1 decimal (current=opening) + 1 string (notes) = 19
                    $code=$v[0]; $name=$v[1]; $cp=$v[2]; $ph=$v[3]; $aph=$v[4]; $em=$v[5]; $addr=$v[6]; $ct=$v[7]; $ntn=$v[8]; $gst=$v[9]; $tx=$v[10]; $bn=$v[11]; $bt=$v[12]; $ban=$v[13]; $ib=$v[14]; $pt=$v[15]; $ob=(float)$v[16]; $cb=(float)$v[16]; $nt=$v[17];
                    // types: ssssssssssssssssdds (16 s, 2 d, 1 s = 19)
                    $st->bind_param('ssssssssssssssssdds', $code,$name,$cp,$ph,$aph,$em,$addr,$ct,$ntn,$gst,$tx,$bn,$bt,$ban,$ib,$pt,$ob,$cb,$nt);
                    if ($st->execute()) $added++;
                }
                $st->close();
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> vendors seeded: ' . $added . ' rows</div>';
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> vendors already has data, skipping seed</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> vendors seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 2. bank_accounts seed
        try {
            $r = $conn->query("SELECT COUNT(*) AS c FROM bank_accounts WHERE is_deleted=0");
            $bc = $r ? (int)$r->fetch_assoc()['c'] : 0;
            if ($bc === 0) {
                $banks = [
                    ['Main HBL Account','ISP Billing Demo','HBL','Main Branch','04230000123456','PK10HABB0040230000123456','HABBPKKA','current','PKR',250000.00,250000.00,1,'Primary operating account','active'],
                    ['Petty Cash','Cash on Hand','Cash',null,'CASH-001',null,null,'cash','PKR',15000.00,15000.00,0,'Office cash drawer','active'],
                    ['JazzCash Wallet','ISP Demo Mobile','JazzCash',null,'03001234567',null,null,'jazzcash','PKR',8500.00,8500.00,0,'Mobile wallet for online collections','active'],
                ];
                $sql = "INSERT INTO bank_accounts (account_name, account_title, bank_name, branch_name, account_number, iban, swift_code, account_type, currency, opening_balance, current_balance, is_default, notes, status, is_deleted) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 0)";
                $st = $conn->prepare($sql);
                $added = 0;
                foreach ($banks as $b) {
                    // 9 strings + 2 decimals + 1 int + 2 strings = 14 placeholders
                    $an=$b[0]; $at=$b[1]; $bn=$b[2]; $br=$b[3]; $acn=$b[4]; $ib=$b[5]; $sc=$b[6]; $aty=$b[7]; $cur=$b[8]; $ob=(float)$b[9]; $cb=(float)$b[10]; $isd=(int)$b[11]; $nt=$b[12]; $stt=$b[13];
                    // types: sssssssssddiss
                    $st->bind_param('sssssssssddiss', $an,$at,$bn,$br,$acn,$ib,$sc,$aty,$cur,$ob,$cb,$isd,$nt,$stt);
                    if ($st->execute()) $added++;
                }
                $st->close();
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> bank_accounts seeded: ' . $added . ' rows</div>';
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> bank_accounts already has data, skipping seed</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> bank_accounts seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 3. collection_runs seed (collector daybook)
        $yest_run_id = 0; $today_run_id = 0;
        try {
            $r = $conn->query("SELECT COUNT(*) AS c FROM collection_runs");
            $crc = $r ? (int)$r->fetch_assoc()['c'] : 0;
            if ($crc === 0) {
                $collector_id = 0;
                $rr = $conn->query("SELECT id FROM users WHERE role='collector' AND is_deleted=0 LIMIT 1");
                if ($rr && ($x = $rr->fetch_assoc())) $collector_id = (int)$x['id'];
                if ($collector_id > 0) {
                    $yest = date('Y-m-d', strtotime('-1 day'));
                    $today = date('Y-m-d');
                    $closed_at = $yest . ' 18:30:00';
                    // yesterday closed: open=0, cash=4500, online=2000, exp=200, close=6300
                    $sql = "INSERT INTO collection_runs (collector_user_id, collection_date, opening_balance, total_collected_cash, total_collected_online, total_expenses_paid, closing_balance, status, closed_at, actual_visits_count, successful_collections_count, failed_visits_count, notes) VALUES (?,?,?,?,?,?,?, 'closed', ?, 4, 3, 1, 'Daybook for yesterday')";
                    $st = $conn->prepare($sql);
                    $ob=0.0; $cash=4500.0; $online=2000.0; $exp=200.0; $cls=6300.0;
                    $st->bind_param('isddddds', $collector_id, $yest, $ob, $cash, $online, $exp, $cls, $closed_at);
                    if ($st->execute()) $yest_run_id = (int)$conn->insert_id;
                    $st->close();
                    // today open: opening=6300, totals=0
                    // i s d d d d d  → 'isddddd' (collector, date, opening, cash, online, exp, closing)
                    $sql2 = "INSERT INTO collection_runs (collector_user_id, collection_date, opening_balance, total_collected_cash, total_collected_online, total_expenses_paid, closing_balance, status, actual_visits_count, successful_collections_count, failed_visits_count, notes) VALUES (?,?,?,?,?,?,?, 'open', 1, 0, 0, 'Today daybook in progress')";
                    $st2 = $conn->prepare($sql2);
                    $ob2=6300.0; $z=0.0; $cls2=6300.0;
                    $st2->bind_param('isddddd', $collector_id, $today, $ob2, $z, $z, $z, $cls2);
                    if ($st2->execute()) $today_run_id = (int)$conn->insert_id;
                    $st2->close();
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> collection_runs seeded: 2 rows (yesterday closed, today open)</div>';
                } else {
                    echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> No collector user found, skipping collection_runs seed</div>';
                }
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> collection_runs already has data, skipping seed</div>';
                // pull existing ids for visits below
                $rrun = $conn->query("SELECT id FROM collection_runs ORDER BY collection_date ASC LIMIT 2");
                if ($rrun) {
                    $rows = [];
                    while ($x = $rrun->fetch_assoc()) $rows[] = (int)$x['id'];
                    if (count($rows) >= 1) $yest_run_id = $rows[0];
                    if (count($rows) >= 2) $today_run_id = $rows[1];
                }
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> collection_runs seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 4. collection_visits seed
        try {
            $r = $conn->query("SELECT COUNT(*) AS c FROM collection_visits");
            $cvc = $r ? (int)$r->fetch_assoc()['c'] : 0;
            if ($cvc === 0 && ($yest_run_id > 0 || $today_run_id > 0)) {
                // grab first 5 customer ids
                $cust_ids = [];
                $cr = $conn->query("SELECT id FROM customers WHERE is_deleted=0 ORDER BY id LIMIT 5");
                if ($cr) while ($x = $cr->fetch_assoc()) $cust_ids[] = (int)$x['id'];
                $collector_id = 0;
                $ur = $conn->query("SELECT id FROM users WHERE role='collector' AND is_deleted=0 LIMIT 1");
                if ($ur && ($x = $ur->fetch_assoc())) $collector_id = (int)$x['id'];
                if (count($cust_ids) >= 5 && $collector_id > 0) {
                    $yest = date('Y-m-d', strtotime('-1 day'));
                    $today = date('Y-m-d');
                    // [run_id, cust_id, visit_date, visit_time, outcome, amount_collected, pay_method, promised_amount, promised_date, notes]
                    $rid_y = $yest_run_id ?: $today_run_id;
                    $rid_t = $today_run_id ?: $yest_run_id;
                    $promise_dt = date('Y-m-d', strtotime('+3 days'));
                    $visits = [
                        [$rid_y, $cust_ids[0], $yest,  '10:30:00', 'collected', 1500.00, 'cash',   null,    null,        'Cash collected'],
                        [$rid_y, $cust_ids[1], $yest,  '11:45:00', 'collected', 2000.00, 'online', null,    null,        'Paid via JazzCash'],
                        [$rid_y, $cust_ids[2], $yest,  '14:20:00', 'promised',  0.00,    'cash',   1800.00, $promise_dt, 'Will pay on Friday'],
                        [$rid_y, $cust_ids[3], $yest,  '15:30:00', 'not_home',  0.00,    'cash',   null,    null,        'No one at home, will retry'],
                        [$rid_t, $cust_ids[4], $today, '09:15:00', 'collected', 1200.00, 'cash',   null,    null,        'Morning visit, paid in cash'],
                    ];
                    $sql = "INSERT INTO collection_visits (collection_run_id, customer_id, collector_user_id, visit_date, visit_time, outcome, amount_collected, payment_method, promised_amount, promised_date, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
                    $st = $conn->prepare($sql);
                    $added = 0;
                    foreach ($visits as $v) {
                        $rid=(int)$v[0]; $cid=(int)$v[1]; $vd=$v[2]; $vt=$v[3]; $out=$v[4]; $amt=(float)$v[5]; $pm=$v[6]; $pamt=$v[7]; $pdt=$v[8]; $nt=$v[9];
                        // i, i, i, s, s, s, d, s, d, s, s = iiisssdsdss  (11 placeholders)
                        $st->bind_param('iiisssdsdss', $rid, $cid, $collector_id, $vd, $vt, $out, $amt, $pm, $pamt, $pdt, $nt);
                        if ($st->execute()) $added++;
                    }
                    $st->close();
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> collection_visits seeded: ' . $added . ' rows</div>';
                } else {
                    echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Need 5 customers + collector for visits, skipping</div>';
                }
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> collection_visits already has data or no run available, skipping</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> collection_visits seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 5. call_logs seed (uses actual schema: customer_id, invoice_id, called_by, call_date, outcome enum, promised_date, notes)
        try {
            $r = $conn->query("SELECT COUNT(*) AS c FROM call_logs WHERE is_deleted=0");
            $clc = $r ? (int)$r->fetch_assoc()['c'] : 0;
            if ($clc === 0) {
                $cust_ids = [];
                $cr = $conn->query("SELECT id FROM customers WHERE is_deleted=0 ORDER BY id LIMIT 5");
                if ($cr) while ($x = $cr->fetch_assoc()) $cust_ids[] = (int)$x['id'];
                $admin_id = 0;
                $ur = $conn->query("SELECT id FROM users WHERE role IN ('admin','super_admin') AND is_deleted=0 LIMIT 1");
                if ($ur && ($x = $ur->fetch_assoc())) $admin_id = (int)$x['id'];
                if (count($cust_ids) >= 4 && $admin_id > 0) {
                    $promise_dt = date('Y-m-d', strtotime('+5 days'));
                    // outcome enum: no_answer, promised, paid, disputed, wrong_number, other
                    $calls = [
                        [$cust_ids[0], $admin_id, '2026-04-15 10:30:00', 'other',       null,        'Sent payment reminder'],
                        [$cust_ids[1], $admin_id, '2026-04-20 14:15:00', 'other',       null,        'Speed issue reported, escalated to support'],
                        [$cust_ids[2], $admin_id, '2026-05-02 11:00:00', 'other',       null,        'Confirmed installation date with customer'],
                        [$cust_ids[0], $admin_id, '2026-05-05 09:45:00', 'other',       null,        'Customer asked about upgrade options'],
                        [$cust_ids[3], $admin_id, '2026-05-08 16:20:00', 'promised',    $promise_dt, 'Promised payment by Friday'],
                    ];
                    $sql = "INSERT INTO call_logs (customer_id, called_by, call_date, outcome, promised_date, notes) VALUES (?,?,?,?,?,?)";
                    $st = $conn->prepare($sql);
                    $added = 0;
                    foreach ($calls as $c) {
                        $cid=(int)$c[0]; $by=(int)$c[1]; $dt=$c[2]; $out=$c[3]; $pdt=$c[4]; $nt=$c[5];
                        $st->bind_param('iissss', $cid, $by, $dt, $out, $pdt, $nt);
                        if ($st->execute()) $added++;
                    }
                    $st->close();
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> call_logs seeded: ' . $added . ' rows</div>';
                } else {
                    echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Need customers + admin user for call_logs, skipping</div>';
                }
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> call_logs already has data, skipping seed</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> call_logs seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // ─── TIER 3 DEMO SEEDS — the audit / security / notification tables ───
        // each one only fires on an empty table, so re-running setup never duplicates them
        $seedIfEmpty = function ($table, $cb) use ($conn) {
            try {
                $r = $conn->query("SELECT COUNT(*) c FROM `$table`");
                if (!$r || (int)$r->fetch_assoc()['c'] !== 0) {
                    echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> ' . $table . ' already has data, skipping seed</div>';
                    return;
                }
                $n = $cb($conn);
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> ' . $table . ' seeded: ' . $n . ' rows</div>';
            } catch (Throwable $e) {
                echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> ' . $table . ' seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        };

        // staff map — every seed below hangs off a real user row
        $staff = [];
        $sr = $conn->query("SELECT id, username FROM users WHERE is_deleted = 0");
        while ($sr && $x = $sr->fetch_assoc()) $staff[$x['username']] = (int)$x['id'];
        $adminId = $staff['admin'] ?? 0;

        // 7. activity_logs — the audit trail every page writes to
        $seedIfEmpty('activity_logs', function ($conn) use ($staff, $adminId) {
            $rows = [
                ['admin',       'Login',             'Logged in successfully',                        1],
                ['admin',       'Customer Added',    'Added CUST-0005 — Customer 5',                  4],
                ['accountant1', 'Invoice Created',   'Created INV-2026-0012 (recurring) — 2500.00',   7],
                ['collector1',  'Payment Recorded',  'Recorded RCP-0006 — 2500.00 cash',             11],
                ['collector1',  'Complaint Logged',  'Logged TKT-0004 — slow speed reported',        14],
                ['manager1',    'Stock Movement',    'ISS-0001 — Issue Out (2 line(s))',             20],
                ['admin2',      'Expense Added',     'Added EXP-0003 — fuel and travel',             26],
                ['admin',       'Settings Updated',  'Updated company profile settings',             33],
                ['accountant1', 'Payment Recorded',  'Recorded RCP-0008 — 5000.00 bank transfer',    41],
                ['collector2',  'Login',             'Logged in successfully',                       48],
            ];
            $st = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address, timestamp)
                                  VALUES (?,?,?,?, '127.0.0.1', DATE_SUB(NOW(), INTERVAL ? HOUR))");
            $n = 0;
            foreach ($rows as $r) {
                $uid = $staff[$r[0]] ?? $adminId;
                if (!$uid) continue;
                $st->bind_param("isssi", $uid, $r[0], $r[1], $r[2], $r[3]);
                if ($st->execute()) $n++;
            }
            $st->close();
            return $n;
        });

        // 8. notifications — the bell dropdown
        $seedIfEmpty('notifications', function ($conn) use ($adminId) {
            $rows = [
                ['Invoice Created',   'INV-2026-0012 has been created.',                          'info',    1, 'invoices.php',    2],
                ['Payment Received',  'RCP-0008 — 5000.00 received against INV-2026-0009.',       'success', 1, 'payments.php',    6],
                ['Low Stock Alert',   'SC/APC Fast Connector (ITM-0002) is down to 40 — reorder level is 50.', 'warning', 0, 'stock.php', 12],
                ['Invoice Overdue',   '3 invoices crossed their due date and are now overdue.',    'danger',  0, 'invoices.php?filter=overdue', 24],
                ['New Complaint',     'TKT-0004 logged by Customer 3 — priority urgent.',          'warning', 0, 'complaints.php',  30],
                ['Collection Closed', 'Collector 1 closed the day sheet — 7500.00 cash collected.', 'info',   1, 'collections_runs.php', 36],
            ];
            $st = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, link, created_at)
                                  VALUES (?,?,?,?,?,?, DATE_SUB(NOW(), INTERVAL ? HOUR))");
            $n = 0;
            foreach ($rows as $r) {
                $st->bind_param("isssisi", $adminId, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5]);
                if ($st->execute()) $n++;
            }
            $st->close();
            return $n;
        });

        // 9. login_attempts — what the lockout counter reads
        $seedIfEmpty('login_attempts', function ($conn) {
            $rows = [['admin', 45], ['admin', 44], ['collector1', 180], ['unknown_user', 300]];
            $st = $conn->prepare("INSERT INTO login_attempts (username, ip_address, attempt_time) VALUES (?, '127.0.0.1', DATE_SUB(NOW(), INTERVAL ? MINUTE))");
            $n = 0;
            foreach ($rows as $r) { $st->bind_param("si", $r[0], $r[1]); if ($st->execute()) $n++; }
            $st->close();
            return $n;
        });

        // 10. email_verifications — signup OTPs (one used, one still live)
        $seedIfEmpty('email_verifications', function ($conn) use ($staff) {
            $rows = [ // [user, email, otp, used, hours-from-now]
                [$staff['collector2'] ?? 0, 'collector2@demo.com', '482913', 1, -48],
                [$staff['manager1']   ?? 0, 'manager1@demo.com',   '750264', 0,   1],
            ];
            $st = $conn->prepare("INSERT INTO email_verifications (user_id, email, otp_code, used, expires_at) VALUES (?,?,?,?, NOW() + INTERVAL ? HOUR)");
            $n = 0;
            foreach ($rows as $r) {
                if (!$r[0]) continue;
                $st->bind_param("issii", $r[0], $r[1], $r[2], $r[3], $r[4]);
                if ($st->execute()) $n++;
            }
            $st->close();
            return $n;
        });

        // 11. password_resets — forgot-password OTPs (used + expired)
        $seedIfEmpty('password_resets', function ($conn) {
            $rows = [['accountant1@demo.com', '319574', 1, -24], ['collector1@demo.com', '605128', 0, -1]];
            $st = $conn->prepare("INSERT INTO password_resets (email, otp_code, used, expires_at) VALUES (?,?,?, NOW() + INTERVAL ? HOUR)");
            $n = 0;
            foreach ($rows as $r) { $st->bind_param("ssii", $r[0], $r[1], $r[2], $r[3]); if ($st->execute()) $n++; }
            $st->close();
            return $n;
        });

        // 12. remember_tokens — a live "remember me" cookie, hash only (the raw token never lands in the db)
        $seedIfEmpty('remember_tokens', function ($conn) use ($staff) {
            $st = $conn->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
            $n = 0;
            foreach (['admin2', 'collector1'] as $u) {
                if (empty($staff[$u])) continue;
                $hash = hash('sha256', 'demo-token-' . $u . '-' . bin2hex(random_bytes(8)));
                $st->bind_param("is", $staff[$u], $hash);
                if ($st->execute()) $n++;
            }
            $st->close();
            return $n;
        });

        // 13. complaint_attachments — placeholder paths, same convention as customer_documents
        $seedIfEmpty('complaint_attachments', function ($conn) use ($adminId, $staff) {
            $img = 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';
            $pdf = 'uploads/complaint_attachments/demo_site_report.pdf';
            $ids = [];
            $r = $conn->query("SELECT id FROM complaints ORDER BY id LIMIT 3");
            while ($r && $x = $r->fetch_assoc()) $ids[] = (int)$x['id'];
            if (!count($ids) || !$adminId) return 0;
            $rows = [
                [$ids[0], $img, 'fault_photo_demo.jpg',   96400],
                [$ids[0], $pdf, 'site_report_demo.pdf',  148200],
                [count($ids) > 1 ? $ids[1] : $ids[0], $img, 'router_led_demo.jpg', 88100],
                [count($ids) > 2 ? $ids[2] : $ids[0], $img, 'cable_cut_demo.jpg', 102900],
            ];
            $up = $staff['collector1'] ?? $adminId;
            $st = $conn->prepare("INSERT INTO complaint_attachments (complaint_id, file_path, original_name, file_size, uploaded_by) VALUES (?,?,?,?,?)");
            $n = 0;
            foreach ($rows as $c) { $st->bind_param("issii", $c[0], $c[1], $c[2], $c[3], $up); if ($st->execute()) $n++; }
            $st->close();
            return $n;
        });

        // 6. UPDATE customers — Tier 2 enrichment
        try {
            $conn->query("UPDATE customers SET
                pppoe_username = LOWER(CONCAT(SUBSTRING_INDEX(full_name, ' ', 1), '_', customer_code)),
                pppoe_password = SUBSTRING(MD5(RAND()), 1, 10),
                connection_medium = 'fiber',
                customer_type = 'residential',
                nationality = 'Pakistan',
                next_invoice_date = DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY),
                credit_limit = 1000.00,
                auto_pay_enabled = 0,
                vip_status = 'regular',
                customer_rating = 'B',
                acquisition_source = 'walk_in',
                agreement_signed = 1,
                contract_period_months = 12,
                early_termination_fee = 2000.00,
                olt_id = 1,
                olt_port = '1',
                pon_port = '1/1',
                ont_serial = CONCAT('ONT', LPAD(id, 6, '0')),
                bandwidth_profile_name = 'Default-Home',
                mac_binding = 1
                WHERE pppoe_username IS NULL OR pppoe_username = ''");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> customers Tier 2 enriched (' . (int)$conn->affected_rows . ' rows)</div>';

            // Make customer #2 a business
            $conn->query("UPDATE customers SET customer_type='business', business_name='Hussain Trading Co', ntn_number='1234567-8'
                WHERE id = (SELECT id FROM (SELECT id FROM customers ORDER BY id LIMIT 1 OFFSET 1) tmp)
                AND (business_name IS NULL OR business_name = '')");

            // gender + dob variety
            $conn->query("UPDATE customers SET dob='1990-05-15', gender='male' WHERE id IN (SELECT id FROM (SELECT id FROM customers WHERE gender IS NULL ORDER BY id LIMIT 2) t)");
            $conn->query("UPDATE customers SET dob='1985-08-22', gender='female' WHERE id IN (SELECT id FROM (SELECT id FROM customers WHERE gender IS NULL ORDER BY id LIMIT 2) t)");

            // assign sales/install user from first admin
            $conn->query("UPDATE customers SET salesperson_user_id = (SELECT id FROM users WHERE role='admin' AND is_deleted=0 LIMIT 1) WHERE salesperson_user_id IS NULL");
            $conn->query("UPDATE customers SET installation_user_id = (SELECT id FROM users WHERE role='admin' AND is_deleted=0 LIMIT 1) WHERE installation_user_id IS NULL");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> customers business/gender/staff enrichment done</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> customers Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 7. UPDATE packages — Tier 2 enrichment
        try {
            $conn->query("UPDATE packages SET
                package_code = CONCAT('PKG-', LPAD(id, 3, '0')),
                target_segment = 'home',
                tax_pct = 18.00,
                tax_inclusive = 0,
                late_fee_amount = 200.00,
                late_fee_grace_days = 5,
                bandwidth_profile_name = CONCAT('queue-', LPAD(id, 3, '0')),
                is_promotional = 0
                WHERE package_code IS NULL OR package_code = ''");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> packages Tier 2 enriched (' . (int)$conn->affected_rows . ' rows)</div>';

            // optional cols — try each independently in case schema slimmer
            try { $conn->query("UPDATE packages SET equipment_included = 1 WHERE equipment_included = 0"); } catch (Throwable $e) {}
            try { $conn->query("UPDATE packages SET equipment_rental_charge = 0 WHERE equipment_rental_charge IS NULL"); } catch (Throwable $e) {}
            try { $conn->query("UPDATE packages SET included_static_ip = 0 WHERE included_static_ip IS NULL"); } catch (Throwable $e) {}
            try { $conn->query("UPDATE packages SET commission_pct_for_sales = 5.00 WHERE commission_pct_for_sales IS NULL OR commission_pct_for_sales = 0"); } catch (Throwable $e) {}

            // business package upgrade — pick package_category='business' if exists
            try {
                $conn->query("UPDATE packages SET target_segment='sme', commission_pct_for_sales=8.00, included_static_ip=1
                    WHERE package_category='business'
                    AND (target_segment != 'sme' OR target_segment IS NULL)");
            } catch (Throwable $e) {}
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> packages business segment enrichment done</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> packages Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 8. UPDATE areas — Tier 2 enrichment
        try {
            $conn->query("UPDATE areas SET
                area_code = CONCAT('AREA-', LPAD(id, 3, '0')),
                country = 'Pakistan',
                coverage_status = 'active',
                coverage_technology = 'fiber,wireless',
                total_capacity_subscribers = 500
                WHERE area_code IS NULL OR area_code = ''");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> areas Tier 2 enriched (' . (int)$conn->affected_rows . ' rows)</div>';

            // heuristic province/city assignment
            try {
                $conn->query("UPDATE areas SET province='Punjab', city='Lahore' WHERE (city IS NULL OR city='') AND (name LIKE '%Johar%' OR name LIKE '%DHA%' OR name LIKE '%Model Town%' OR name LIKE '%Gulberg%')");
                $conn->query("UPDATE areas SET province='Sindh', city='Karachi' WHERE (city IS NULL OR city='') AND (name LIKE '%Gulshan%' OR name LIKE '%Clifton%' OR name LIKE '%Defence%' OR name LIKE '%Nazimabad%')");
                $conn->query("UPDATE areas SET province='Punjab', city='Lahore' WHERE province IS NULL OR province=''");
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> areas Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 9. UPDATE invoices — Tier 2 enrichment
        try {
            $conn->query("UPDATE invoices SET
                subtotal = amount,
                currency = 'PKR',
                invoice_type = 'recurring',
                is_credit_note = 0
                WHERE subtotal IS NULL OR subtotal = 0");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> invoices subtotal/currency/type enriched (' . (int)$conn->affected_rows . ' rows)</div>';

            // total_paid + total_due from allocations OR direct payments fallback
            try {
                $conn->query("UPDATE invoices i
                    LEFT JOIN (
                        SELECT invoice_id, COALESCE(SUM(amount),0) AS paid
                        FROM payment_invoice_allocations
                        GROUP BY invoice_id
                    ) a ON a.invoice_id = i.id
                    LEFT JOIN (
                        SELECT invoice_id, COALESCE(SUM(amount),0) AS paid
                        FROM payments WHERE is_deleted=0
                        GROUP BY invoice_id
                    ) p ON p.invoice_id = i.id
                    SET i.total_paid = COALESCE(a.paid, p.paid, 0),
                        i.total_due  = i.total - COALESCE(a.paid, p.paid, 0)
                    WHERE (i.total_paid IS NULL OR i.total_paid = 0) AND i.is_deleted = 0");
            } catch (Throwable $e) {}

            // package snapshot at invoice time
            try {
                $conn->query("UPDATE invoices i
                    JOIN customers c ON c.id = i.customer_id
                    JOIN packages p ON p.id = c.package_id
                    SET i.package_id_at_time = p.id,
                        i.package_name_at_time = p.name,
                        i.package_speed_at_time = p.speed_mbps,
                        i.package_price_at_time = p.price
                    WHERE i.package_id_at_time IS NULL");
            } catch (Throwable $e) {}
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> invoices payment totals + package snapshot enriched</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> invoices Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 10. UPDATE payments — Tier 2 enrichment
        try {
            $conn->query("UPDATE payments SET
                bank_account_id = (SELECT id FROM bank_accounts WHERE is_default = 1 AND is_deleted=0 LIMIT 1),
                payer_name = (SELECT full_name FROM customers WHERE customers.id = payments.customer_id),
                currency = 'PKR',
                is_advance = 0
                WHERE bank_account_id IS NULL");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> payments Tier 2 enriched (' . (int)$conn->affected_rows . ' rows)</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> payments Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 11. UPDATE complaints — Tier 2 enrichment
        try {
            $conn->query("UPDATE complaints SET
                complaint_source = 'call',
                severity = CASE
                    WHEN priority='urgent' THEN 'critical'
                    WHEN priority='high'   THEN 'high'
                    WHEN priority='medium' THEN 'medium'
                    ELSE 'low'
                END,
                is_mass_outage = 0,
                reopen_count = 0,
                escalation_level = 0,
                sla_breach_flag = 0
                WHERE complaint_source IS NULL OR complaint_source = ''");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> complaints Tier 2 enriched (' . (int)$conn->affected_rows . ' rows)</div>';

            // resolved complaint detail enrichment
            try {
                $conn->query("UPDATE complaints SET
                    resolution_category = 'configuration',
                    resolution_summary = 'Issue resolved by router reconfiguration',
                    work_completed_at = COALESCE(updated_at, created_at, NOW()),
                    customer_satisfaction_rating = 5
                    WHERE status='resolved' AND (resolution_summary IS NULL OR resolution_summary = '')
                    LIMIT 1");
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> complaints Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 12. UPDATE expenses — Tier 2 enrichment
        try {
            $conn->query("UPDATE expenses SET
                expense_number = CONCAT('EXP-', YEAR(COALESCE(created_at, CURDATE())), '-', LPAD(id, 4, '0')),
                expense_date = COALESCE(expense_date, DATE(COALESCE(created_at, CURDATE()))),
                payment_status = CASE WHEN status='approved' THEN 'paid' ELSE 'unpaid' END,
                paid_amount = CASE WHEN status='approved' THEN amount ELSE 0 END,
                outstanding_amount = CASE WHEN status='approved' THEN 0 ELSE amount END,
                paid_date = CASE WHEN status='approved' THEN DATE(COALESCE(approved_at, created_at, CURDATE())) ELSE NULL END,
                bank_account_id = (SELECT id FROM bank_accounts WHERE is_default=1 AND is_deleted=0 LIMIT 1),
                wht_pct = 0
                WHERE expense_number IS NULL OR expense_number = ''");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> expenses Tier 2 enriched (' . (int)$conn->affected_rows . ' rows)</div>';

            // link first 2 expenses to vendors
            try {
                $conn->query("UPDATE expenses SET vendor_id = (SELECT id FROM vendors WHERE is_deleted=0 ORDER BY id LIMIT 1)
                    WHERE id = (SELECT id FROM (SELECT id FROM expenses ORDER BY id LIMIT 1) t) AND vendor_id IS NULL");
                $conn->query("UPDATE expenses SET vendor_id = (SELECT id FROM vendors WHERE is_deleted=0 ORDER BY id LIMIT 1 OFFSET 1)
                    WHERE id = (SELECT id FROM (SELECT id FROM expenses ORDER BY id LIMIT 1 OFFSET 1) t) AND vendor_id IS NULL");
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> expenses Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 12b. backfill expense_payments — one consolidated row per paid expense w/ no history yet
        try {
            $conn->query("INSERT INTO expense_payments
                    (expense_id, amount, payment_date, payment_method, reference, notes, created_by_user_id)
                SELECT e.id, e.paid_amount,
                    COALESCE(e.paid_date, e.expense_date, CURDATE()),
                    e.payment_method, 'MIGRATED',
                    'Opening payment (migrated from expense record)', e.paid_by
                FROM expenses e
                WHERE e.paid_amount > 0
                  AND NOT EXISTS (SELECT 1 FROM expense_payments ep WHERE ep.expense_id = e.id)");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> expense_payments backfilled (' . (int)$conn->affected_rows . ' rows)</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> expense_payments backfill skipped: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 13. UPDATE leads — Tier 2 enrichment
        try {
            $conn->query("UPDATE leads SET
                lead_code = CONCAT('LEAD-', YEAR(COALESCE(created_at, CURDATE())), '-', LPAD(id, 4, '0')),
                customer_type = 'residential',
                feasibility_status = 'feasible',
                survey_required = 0,
                temperature = CASE
                    WHEN status='new' THEN 'warm'
                    WHEN status='quoted' THEN 'hot'
                    WHEN status='lost' THEN 'cold'
                    ELSE 'warm'
                END,
                lead_score = CASE
                    WHEN status='quoted' THEN 75
                    WHEN status='negotiating' THEN 85
                    WHEN status='lost' THEN 10
                    ELSE 50
                END,
                whatsapp_consent = 1,
                email_consent = 1
                WHERE lead_code IS NULL OR lead_code = ''");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> leads Tier 2 enriched (' . (int)$conn->affected_rows . ' rows)</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> leads Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 14. UPDATE equipment — Tier 2 enrichment
        try {
            $conn->query("UPDATE equipment SET
                asset_tag = CONCAT('ASSET-', UPPER(LEFT(type, 3)), '-', LPAD(id, 4, '0')),
                warranty_start_date = COALESCE(purchase_date, '2024-01-01'),
                warranty_end_date = DATE_ADD(COALESCE(purchase_date, '2024-01-01'), INTERVAL 24 MONTH),
                expected_lifetime_months = 60,
                depreciation_method = 'straight_line',
                depreciation_pct_per_year = 20.00,
                salvage_value = 0,
                current_book_value = COALESCE(purchase_cost, 0) * 0.8,
                is_rented = 0,
                is_sold = 0,
                is_loaned = 0,
                equipment_condition = CASE
                    WHEN status='retired' OR status='lost' THEN 'scrapped'
                    WHEN status='damaged' THEN 'needs_repair'
                    ELSE 'good'
                END,
                is_critical = 0,
                monitoring_enabled = 0
                WHERE asset_tag IS NULL OR asset_tag = ''");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> equipment Tier 2 enriched (' . (int)$conn->affected_rows . ' rows)</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> equipment Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 15. UPDATE users — Tier 2 enrichment
        try {
            $conn->query("UPDATE users SET
                employee_code = CONCAT('EMP-', LPAD(id, 4, '0')),
                joining_date = COALESCE(joining_date, '2023-01-15'),
                department = CASE
                    WHEN role='super_admin' THEN 'Admin'
                    WHEN role='admin' THEN 'Operations'
                    WHEN role='accountant' THEN 'Accounts'
                    WHEN role='collector' THEN 'Field Operations'
                    ELSE 'General'
                END,
                designation = CASE
                    WHEN role='super_admin' THEN 'System Administrator'
                    WHEN role='admin' THEN 'Operations Manager'
                    WHEN role='accountant' THEN 'Senior Accountant'
                    WHEN role='collector' THEN 'Field Collector'
                    ELSE 'Staff'
                END,
                salary_basic = CASE
                    WHEN role='super_admin' THEN 80000
                    WHEN role='admin' THEN 60000
                    WHEN role='accountant' THEN 45000
                    WHEN role='collector' THEN 30000
                    ELSE 25000
                END,
                salary_allowances = CASE
                    WHEN role='super_admin' THEN 20000
                    WHEN role='admin' THEN 15000
                    WHEN role='accountant' THEN 10000
                    WHEN role='collector' THEN 5000
                    ELSE 3000
                END,
                tax_filer_status = 'filer',
                commission_eligible = CASE WHEN role='collector' THEN 1 ELSE 0 END,
                commission_rate_pct = CASE WHEN role='collector' THEN 2.00 ELSE 0 END,
                target_monthly_amount = CASE WHEN role='collector' THEN 50000 ELSE 0 END
                WHERE employee_code IS NULL OR employee_code = ''");
            // salary_total derived
            try {
                $conn->query("UPDATE users SET salary_total = COALESCE(salary_basic,0) + COALESCE(salary_allowances,0)
                    WHERE salary_total IS NULL OR salary_total = 0");
            } catch (Throwable $e) {}
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> users Tier 2 enriched (' . (int)$conn->affected_rows . ' rows)</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> users Tier 2 update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> <strong>Tier 2 demo data complete</strong></div>';
        // ─── /TIER 2 DEMO DATA ──────────────────────────────────────────

        // ─── TIER 3: roles matrix + stock + ledger ──────────────────────
        // schema lives in update_setup.php (single source) — run it last so the
        // ledger backfill picks up everything seeded above
        echo '<br><div class="log-item log-info"><i class="fas fa-database"></i> <strong>Applying Tier 3 schema (roles / stock / ledger)...</strong></div>';
        try {
            mysqli_report(MYSQLI_REPORT_OFF); // applyUpdates expects query() to return false, not throw
            applyUpdates($conn);
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        } catch (Throwable $e) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Tier 3 schema error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // stock demo — items + one purchase + one issue + serial units
        try {
            $sc = $conn->query("SELECT COUNT(*) c FROM stock_items");
            if ((int)($sc->fetch_assoc()['c'] ?? 1) === 0) {
                $catOf = function ($name) use ($conn) {
                    $r = $conn->query("SELECT id FROM stock_categories WHERE name = '" . $conn->real_escape_string($name) . "' LIMIT 1");
                    $x = $r ? $r->fetch_assoc() : null;
                    return $x ? (int)$x['id'] : null;
                };
                // code, name, cat, track, unit, brand, model, eqtype, reorder, price, sale
                $items = [
                    ['ITM-0001', 'Fiber Drop Wire 1-Core', 'Fiber & Cable', 'quantity', 'meter', 'Generic', 'FTTH-1C', null, 500, 22.00, 30.00],
                    ['ITM-0002', 'SC/APC Fast Connector',  'Connectors & Splitters', 'quantity', 'pcs', 'Generic', 'SC-APC', null, 200, 65.00, 100.00],
                    ['ITM-0003', '1x8 PLC Splitter',       'Connectors & Splitters', 'quantity', 'pcs', 'Generic', 'PLC-1X8', 'splitter', 20, 950.00, 1400.00],
                    ['ITM-0004', 'GPON ONU Router',        'Routers & ONU', 'serial', 'pcs', 'Generic', 'ONU-4GE', 'onu', 10, 4200.00, 5500.00],
                    ['ITM-0005', 'Nylon Cable Tie 8in',    'Tools & Consumables', 'quantity', 'pcs', 'Generic', 'CT-8', null, 300, 3.00, 5.00],
                ];
                $ins = $conn->prepare("INSERT INTO stock_items (item_code, name, category_id, tracking_type, unit, brand, model, equipment_type, reorder_level, purchase_price, sale_price)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $ids = [];
                foreach ($items as $it) {
                    $cid = $catOf($it[2]);
                    $ins->bind_param("ssisssssddd", $it[0], $it[1], $cid, $it[3], $it[4], $it[5], $it[6], $it[7], $it[8], $it[9], $it[10]);
                    $ins->execute();
                    $ids[$it[0]] = $conn->insert_id;
                }
                $ins->close();
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo stock items seeded (' . count($ids) . ')</div>';

                $vr  = $conn->query("SELECT id, vendor_name FROM vendors WHERE is_deleted=0 ORDER BY id ASC LIMIT 1");
                $ven = $vr ? $vr->fetch_assoc() : null;
                $ur  = $conn->query("SELECT id FROM users WHERE role='collector' AND is_deleted=0 LIMIT 1");
                $col = $ur ? $ur->fetch_assoc() : null;
                $ar  = $conn->query("SELECT id FROM areas WHERE is_deleted=0 ORDER BY id ASC LIMIT 1");
                $are = $ar ? $ar->fetch_assoc() : null;
                $adm = $conn->query("SELECT id FROM users WHERE role='super_admin' AND is_deleted=0 LIMIT 1");
                $uid = $adm ? (int)($adm->fetch_assoc()['id'] ?? 0) : 0;

                // header, then lines, then recompute — same funnel the page uses
                $mkMove = function ($num, $type, $date, $vendorId, $userId, $areaId, $ref, $lines) use ($conn, $uid) {
                    $total = 0;
                    foreach ($lines as $l) $total += $l[1] * $l[2];
                    $h = $conn->prepare("INSERT INTO stock_movements (movement_number, movement_type, movement_date, vendor_id, issued_to_user_id, area_id, reference, total_amount, created_by_user_id)
                                         VALUES (?,?,?,?,?,?,?,?,?)");
                    $h->bind_param("sssiiisdi", $num, $type, $date, $vendorId, $userId, $areaId, $ref, $total, $uid);
                    $h->execute();
                    $mid = $conn->insert_id;
                    $h->close();
                    $li = $conn->prepare("INSERT INTO stock_movement_items (movement_id, item_id, qty, unit_price, line_total) VALUES (?,?,?,?,?)");
                    foreach ($lines as [$itemId, $qty, $rate]) {
                        $lt = $qty * $rate;
                        $li->bind_param("iiddd", $mid, $itemId, $qty, $rate, $lt);
                        $li->execute();
                    }
                    $li->close();
                    return [$mid, $total];
                };

                [$grnId, $grnTotal] = $mkMove('GRN-0001', 'purchase_in', date('Y-m-d', strtotime('-20 days')),
                    $ven ? (int)$ven['id'] : null, null, null, 'Opening purchase',
                    [[$ids['ITM-0001'], 2000, 22.00], [$ids['ITM-0002'], 500, 65.00], [$ids['ITM-0005'], 1000, 3.00]]);

                $mkMove('ISS-0001', 'issue_out', date('Y-m-d', strtotime('-5 days')),
                    null, $col ? (int)$col['id'] : null, $are ? (int)$are['id'] : null, 'Field stock for installations',
                    [[$ids['ITM-0001'], 350, 22.00], [$ids['ITM-0002'], 40, 65.00]]);

                // serial purchase -> equipment rows are the units
                [$grn2Id, $grn2Total] = $mkMove('GRN-0002', 'purchase_in', date('Y-m-d', strtotime('-15 days')),
                    $ven ? (int)$ven['id'] : null, null, null, 'ONU stock',
                    [[$ids['ITM-0004'], 4, 4200.00]]);
                $eq = $conn->prepare("INSERT INTO equipment (type, brand, model, serial, status, purchase_cost, purchase_date, vendor_name, location, item_id, movement_id, vendor_id)
                                      VALUES ('onu','Generic','ONU-4GE',?, 'in_stock', 4200.00, ?, ?, 'Main Warehouse', ?, ?, ?)");
                $pdate = date('Y-m-d', strtotime('-15 days'));
                $vname = $ven ? $ven['vendor_name'] : null;
                $vid   = $ven ? (int)$ven['id'] : null;
                for ($i = 1; $i <= 4; $i++) {
                    $sn = 'ONU-DEMO-' . str_pad($i, 4, '0', STR_PAD_LEFT);
                    $eq->bind_param("sssiii", $sn, $pdate, $vname, $ids['ITM-0004'], $grn2Id, $vid);
                    $eq->execute();
                }
                $eq->close();

                foreach ($ids as $iid) recomputeItemStock($conn, $iid);
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Demo stock movements seeded (GRN-0001, GRN-0002, ISS-0001)</div>';

                // vendor owes us nothing until paid — purchases sit as payable
                if ($ven) {
                    postLedger($conn, 'vendor', (int)$ven['id'], date('Y-m-d', strtotime('-20 days')), 'Stock purchase GRN-0001', 0, $grnTotal, 'stock_purchase', $grnId, 'GRN-0001', $uid);
                    postLedger($conn, 'vendor', (int)$ven['id'], date('Y-m-d', strtotime('-15 days')), 'Stock purchase GRN-0002', 0, $grn2Total, 'stock_purchase', $grn2Id, 'GRN-0002', $uid);
                    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Stock purchases posted to vendor ledger</div>';
                }
            } else {
                echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> stock_items already has data, skipping seed</div>';
            }
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Stock demo seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        // deferred seeds — their tables are built by applyUpdates() just above

        // multi-area scope. applyUpdates already mirrored each user's single users.area_id,
        // so this only tops up the extra areas — INSERT IGNORE rides the (user_id, area_id) unique key
        try {
            $g = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $u = $conn->prepare("INSERT IGNORE INTO user_areas (user_id, area_id) VALUES (?,?)");
            $n = 0;
            foreach ($demo_area_scope as $uname => $ars) {
                $g->bind_param("s", $uname);
                $g->execute();
                $row = $g->get_result()->fetch_assoc();
                if (!$row) continue;
                $uid = (int)$row['id'];
                foreach (array_unique(array_filter($ars)) as $aid) {
                    $u->bind_param("ii", $uid, $aid);
                    $u->execute();
                    $n += $conn->affected_rows > 0 ? 1 : 0;
                }
            }
            $g->close(); $u->close();
            echo '<div class="log-item log-' . ($n ? 'success' : 'info') . '"><i class="fas fa-' . ($n ? 'check-circle' : 'info-circle') . '"></i> user_areas: ' . $n . ' extra area assignment(s) added</div>';
        } catch (Throwable $e) {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> user_areas seed error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // 15. user_sessions — the "active devices" list on the Sessions page
        $seedIfEmpty('user_sessions', function ($conn) use ($staff, $adminId) {
            $rows = [ // [user, minutes since last activity, device]
                ['admin',      5,   'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0 Safari/537.36'],
                ['collector1', 45,  'Mozilla/5.0 (Linux; Android 13) Chrome/121.0 Mobile Safari/537.36'],
                ['accountant1', 900, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/122.0'],
            ];
            $st = $conn->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, last_activity, created_at)
                                  VALUES (?,?, '127.0.0.1', ?, DATE_SUB(NOW(), INTERVAL ? MINUTE), DATE_SUB(NOW(), INTERVAL ? MINUTE))");
            $n = 0;
            foreach ($rows as $r) {
                $uid = $staff[$r[0]] ?? $adminId;
                if (!$uid) continue;
                $sid  = 'demo' . bin2hex(random_bytes(20));
                $open = $r[1] + 120;
                $st->bind_param("issii", $uid, $sid, $r[2], $r[1], $open);
                if ($st->execute()) $n++;
            }
            $st->close();
            return $n;
        });

        // 14. invoice_items — stock billed straight onto an invoice.
        // goes through the same funnel the invoice page uses, so the issue_out movement and qty stay honest
        $seedIfEmpty('invoice_items', function ($conn) use ($adminId) {
            $ids = [];
            $r = $conn->query("SELECT id FROM invoices WHERE is_deleted = 0 AND status IN ('unpaid','overdue')
                               AND COALESCE(stock_movement_id, 0) = 0 ORDER BY id DESC LIMIT 2");
            while ($r && $x = $r->fetch_assoc()) $ids[] = (int)$x['id'];
            if (!count($ids)) return 0;

            $item = function ($code) use ($conn) {
                $q = $conn->query("SELECT id, sale_price FROM stock_items WHERE item_code = '" . $conn->real_escape_string($code) . "' AND is_deleted = 0 LIMIT 1");
                return $q ? $q->fetch_assoc() : null;
            };
            $wire = $item('ITM-0001'); $conn2 = $item('ITM-0002'); $onu = $item('ITM-0004');

            // new-connection kit on the first invoice
            $lines = [];
            if ($wire)  $lines[] = ['item_id' => (int)$wire['id'],  'qty' => 30, 'unit_price' => (float)$wire['sale_price']];
            if ($conn2) $lines[] = ['item_id' => (int)$conn2['id'], 'qty' => 4,  'unit_price' => (float)$conn2['sale_price']];
            $n = 0;
            $bill = function ($invId, $lines) use ($conn, $adminId, &$n) {
                if (!$lines) return;
                $clean = invoiceStockLinesClean($conn, $lines);
                writeInvoiceStock($conn, $invId, $clean['lines'], $adminId);
                // total follows the same formula the invoice page uses
                $conn->query("UPDATE invoices SET
                        total = ROUND(amount + items_total + equipment_charges + installation_charges + additional_charges
                                      + tax + late_fee - discount_amount - wht_amount, 2),
                        total_due = ROUND(amount + items_total + equipment_charges + installation_charges + additional_charges
                                      + tax + late_fee - discount_amount - wht_amount, 2)
                    WHERE id = " . (int)$invId);
                recomputeInvoiceFromAllocations($conn, $invId);
                syncInvoiceLedger($conn, $invId);
                $n += count($clean['lines']);
            };
            $bill($ids[0], $lines);

            // a serial-tracked router on the second one
            if (count($ids) > 1 && $onu) {
                $s = $conn->query("SELECT serial FROM equipment WHERE item_id = " . (int)$onu['id'] . " AND status = 'in_stock' AND is_deleted = 0 ORDER BY serial LIMIT 1");
                $sn = $s ? $s->fetch_assoc() : null;
                if ($sn) $bill($ids[1], [['item_id' => (int)$onu['id'], 'qty' => 1, 'unit_price' => (float)$onu['sale_price'], 'serials' => $sn['serial']]]);
            }
            return $n;
        });

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> <strong>Tier 3 complete</strong></div>';
        // ─── /TIER 3 ────────────────────────────────────────────────────

        echo '<br><div class="log-item log-success">';
        echo '<i class="fas fa-check-circle"></i> <strong>Setup completed successfully!</strong>';
        echo '</div>';
        ?>

        <a href="login.php" class="btn">
            <i class="fas fa-sign-in-alt"></i> Go to Login Page
        </a>
    </div>

    <!-- Theme Toggle Button -->
    <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    </div>

    <script>
    // Theme Toggle for Setup Page
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.body.classList.add('dark-mode');
            updateThemeIcon(true);
        }
    }

    function toggleTheme() {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark);
    }

    function updateThemeIcon(isDark) {
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    initTheme();

    // reset confirm
    function confirmReset() {
        Swal.fire({
            icon: 'warning',
            title: 'Drop ALL tables?',
            html: 'This will <b>permanently delete</b> all data — users, customers, invoices, payments, complaints, expenses, areas, packages.<br><br>Type <b>RESET</b> to confirm.',
            input: 'text',
            inputPlaceholder: 'RESET',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Yes, drop everything',
            cancelButtonText: 'Cancel',
            preConfirm: (val) => {
                if (val !== 'RESET') {
                    Swal.showValidationMessage('You must type RESET exactly');
                    return false;
                }
                return true;
            }
        }).then((r) => {
            if (r.isConfirmed) {
                window.location.href = 'setup.php?reset=1';
            }
        });
    }
    </script>
</body>
</html>
