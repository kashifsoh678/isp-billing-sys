<?php
/**
 * Developed by Kashif Sohail (Kashif  Scripts)
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * update_setup.php — safe, additive schema updater. ZERO data loss.
 *   Open it directly on a live DB after deploying → adds only what's missing, existing rows untouched.
 *   Also require'd by setup.php so install/reset reuse applyUpdates() (single schema source).
 *   Schema grows HERE only — never edit setup.php for schema changes.
 */
require_once dirname(__DIR__, 2) . '/config.php';

function usLog($cls, $msg, $icon = 'fa-check-circle') {
    echo '<div class="log-item log-' . $cls . '"><i class="fas ' . $icon . '"></i> ' . $msg . '</div>';
}

// mysql has no ADD COLUMN IF NOT EXISTS — probe first
function addColumnIfMissing($conn, $table, $col, $definition) {
    try {
        $t = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if (!$t || $t->num_rows === 0) return; // table arrives with setup.php
        $r = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
        if (!$r) { usLog('error', "$table.$col check failed: " . htmlspecialchars($conn->error), 'fa-exclamation-triangle'); return; }
        if ($r->num_rows === 0) {
            if (@$conn->query("ALTER TABLE `$table` ADD COLUMN $definition")) usLog('success', "$table.$col added");
            else usLog('error', "$table.$col add failed: " . htmlspecialchars($conn->error), 'fa-exclamation-triangle');
        }
    } catch (Throwable $e) {
        usLog('error', "$table.$col exception: " . htmlspecialchars($e->getMessage()), 'fa-exclamation-triangle');
    }
}

function mkTable($conn, $name, $sql) {
    try {
        if (@$conn->query($sql) === TRUE) usLog('success', 'Table "' . $name . '" created or already exists');
        else usLog('error', 'Error creating ' . $name . ': ' . htmlspecialchars($conn->error), 'fa-times-circle');
    } catch (Throwable $e) {
        usLog('error', $name . ' error: ' . htmlspecialchars($e->getMessage()), 'fa-exclamation-triangle');
    }
}

// starting grants per built-in role — reproduces the old hardcoded behaviour exactly
function rbacDefaultPerms($roleKey) {
    $perms = [];
    foreach (array_keys(rbacPages()) as $k) $perms[$k] = [0, 0, 0, 0];
    $grant = function (&$perms, $keys, $v, $a, $e, $d) {
        foreach ($keys as $k) if (isset($perms[$k])) $perms[$k] = [$v, $a, $e, $d];
    };
    $ALL = array_keys(rbacPages());

    switch ($roleKey) {
        case ROLE_SUPER_ADMIN:
            $grant($perms, $ALL, 1, 1, 1, 1);
            break;

        case ROLE_ADMIN:
            // no delete anywhere by default — that stays the owner's call, exactly as before.
            // tick the D boxes on the Roles page to hand it over.
            $grant($perms, $ALL, 1, 1, 1, 0);
            $grant($perms, ['roles', 'logs'], 1, 0, 0, 0);
            $grant($perms, ['settings'], 0, 0, 0, 0);
            break;

        case ROLE_ACCOUNTANT:
            $grant($perms, ['customers', 'leads', 'invoices', 'payments', 'collections', 'collections_runs',
                            'expenses', 'vendors', 'bank_accounts', 'ledger'], 1, 1, 1, 0);
            $grant($perms, ['pos'], 1, 1, 0, 0);
            $grant($perms, ['dashboard', 'aging', 'stock', 'stock_movements', 'complaints'], 1, 0, 0, 0);
            break;

        case ROLE_AREA_MANAGER:
            $grant($perms, ['customers', 'leads', 'collections', 'collections_runs',
                            'complaints', 'stock', 'stock_movements'], 1, 1, 1, 0);
            $grant($perms, ['pos'], 1, 1, 0, 0);
            $grant($perms, ['payments'], 1, 1, 0, 0);
            $grant($perms, ['dashboard', 'packages', 'areas', 'invoices', 'aging', 'ledger', 'equipment'], 1, 0, 0, 0);
            break;

        case ROLE_COLLECTOR:
            $grant($perms, ['complaints'], 1, 1, 1, 0);
            $grant($perms, ['payments', 'collections', 'collections_runs'], 1, 1, 0, 0);
            $grant($perms, ['dashboard', 'customers', 'invoices'], 1, 0, 0, 0);
            break;

        default: // new custom role starts with the dashboard only
            $grant($perms, ['dashboard'], 1, 0, 0, 0);
    }
    return $perms;
}

// ───── SINGLE SCHEMA SOURCE — additive & idempotent ─────
// Builds a fresh DB AND updates an existing one. Safe on prod, run as often as you like.
// NEVER DROP / DELETE / narrowing-ALTER here. Grow the schema by APPENDING below.
function applyUpdates($conn) {

    // ── 1. RBAC ──────────────────────────────────────────────────
    mkTable($conn, 'roles', "CREATE TABLE IF NOT EXISTS roles (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        role_key VARCHAR(50) NOT NULL,
        label VARCHAR(60) NOT NULL,
        description VARCHAR(255) NULL,
        badge_color VARCHAR(20) NOT NULL DEFAULT '#0074D9',
        icon VARCHAR(40) NOT NULL DEFAULT 'fas fa-user-tag',
        data_scope ENUM('all','area','own') NOT NULL DEFAULT 'all',
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        is_super TINYINT(1) NOT NULL DEFAULT 0,
        receives_admin_alerts TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT(11) NOT NULL DEFAULT 0,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_role_key (role_key),
        INDEX idx_status (status, is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mkTable($conn, 'role_permissions', "CREATE TABLE IF NOT EXISTS role_permissions (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        role_key VARCHAR(50) NOT NULL,
        page_key VARCHAR(50) NOT NULL,
        can_view TINYINT(1) NOT NULL DEFAULT 0,
        can_add TINYINT(1) NOT NULL DEFAULT 0,
        can_edit TINYINT(1) NOT NULL DEFAULT 0,
        can_delete TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_role_page (role_key, page_key),
        INDEX idx_role (role_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mkTable($conn, 'user_areas', "CREATE TABLE IF NOT EXISTS user_areas (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        user_id INT(11) NOT NULL,
        area_id INT(11) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_area (user_id, area_id),
        INDEX idx_ua_user (user_id),
        INDEX idx_ua_area (area_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // role must hold custom keys — widen the legacy enum (never narrows)
    try {
        $rt = @$conn->query("SHOW COLUMNS FROM users LIKE 'role'");
        if ($rt && ($rc = $rt->fetch_assoc())) {
            if (stripos($rc['Type'], 'enum') === 0) {
                if (@$conn->query("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'collector'"))
                    usLog('success', 'users.role widened ENUM &rarr; VARCHAR(50) — custom roles now possible');
                else usLog('error', 'users.role widen failed: ' . htmlspecialchars($conn->error), 'fa-times-circle');
            }
        }
    } catch (Throwable $e) {
        usLog('info', 'users.role widen skipped: ' . htmlspecialchars($e->getMessage()), 'fa-info-circle');
    }

    // ── 2. Stock / Inventory ─────────────────────────────────────
    mkTable($conn, 'stock_categories', "CREATE TABLE IF NOT EXISTS stock_categories (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255) NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_stock_cat (name),
        INDEX idx_status (status, is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mkTable($conn, 'stock_items', "CREATE TABLE IF NOT EXISTS stock_items (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        item_code VARCHAR(20) NOT NULL,
        name VARCHAR(150) NOT NULL,
        category_id INT(11) NULL,
        tracking_type ENUM('quantity','serial') NOT NULL DEFAULT 'quantity',
        unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
        brand VARCHAR(80) NULL,
        model VARCHAR(100) NULL,
        equipment_type ENUM('router','onu','switch','cable','splitter','antenna','other') NULL,
        opening_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        current_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
        purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        avg_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        location VARCHAR(100) NULL DEFAULT 'Main Warehouse',
        notes TEXT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_item_code (item_code),
        INDEX idx_cat (category_id),
        INDEX idx_track (tracking_type),
        INDEX idx_status (status, is_deleted),
        INDEX idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    mkTable($conn, 'stock_movements', "CREATE TABLE IF NOT EXISTS stock_movements (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        movement_number VARCHAR(20) NOT NULL,
        movement_type ENUM('purchase_in','issue_out','return_in','damage_out','adjustment') NOT NULL,
        movement_date DATE NOT NULL,
        vendor_id INT(11) NULL,
        issued_to_user_id INT(11) NULL,
        customer_id INT(11) NULL,
        area_id INT(11) NULL,
        reference VARCHAR(100) NULL,
        bill_number VARCHAR(60) NULL,
        total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        expense_id INT(11) NULL,
        notes TEXT NULL,
        status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
        created_by_user_id INT(11) NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_movement_number (movement_number),
        INDEX idx_type_date (movement_type, movement_date),
        INDEX idx_vendor (vendor_id),
        INDEX idx_user (issued_to_user_id),
        INDEX idx_customer (customer_id),
        INDEX idx_area (area_id),
        INDEX idx_status (status, is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── POS counter — over-the-counter sales of stock items ──
    mkTable($conn, 'pos_sales', "CREATE TABLE IF NOT EXISTS pos_sales (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        sale_number VARCHAR(20) NOT NULL,
        sale_date DATETIME NOT NULL,
        customer_id INT(11) NULL,
        walk_in_name VARCHAR(120) NULL,
        walk_in_phone VARCHAR(20) NULL,
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        change_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        due_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        payment_method ENUM('cash','bank_transfer','easypaisa','jazzcash','cheque','card') NOT NULL DEFAULT 'cash',
        payment_status ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'paid',
        movement_id INT(11) NULL,
        notes VARCHAR(255) NULL,
        sold_by_user_id INT(11) NULL,
        status ENUM('completed','void') NOT NULL DEFAULT 'completed',
        void_reason VARCHAR(255) NULL,
        voided_at DATETIME NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_pos_number (sale_number),
        INDEX idx_pos_date (sale_date),
        INDEX idx_pos_customer (customer_id),
        INDEX idx_pos_status (status, is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mkTable($conn, 'pos_sale_items', "CREATE TABLE IF NOT EXISTS pos_sale_items (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        sale_id INT(11) NOT NULL,
        item_id INT(11) NOT NULL,
        item_code VARCHAR(20) NULL,
        item_name VARCHAR(150) NOT NULL,
        unit VARCHAR(20) NULL,
        qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_psi_sale (sale_id),
        INDEX idx_psi_item (item_id),
        CONSTRAINT fk_psi_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mkTable($conn, 'stock_movement_items', "CREATE TABLE IF NOT EXISTS stock_movement_items (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        movement_id INT(11) NOT NULL,
        item_id INT(11) NOT NULL,
        qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        serials TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_movement (movement_id),
        INDEX idx_item (item_id),
        CONSTRAINT fk_smi_mov FOREIGN KEY (movement_id) REFERENCES stock_movements(id) ON DELETE CASCADE,
        CONSTRAINT fk_smi_item FOREIGN KEY (item_id) REFERENCES stock_items(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // equipment becomes the serial arm of stock
    addColumnIfMissing($conn, 'equipment', 'item_id',     "item_id INT(11) NULL");
    addColumnIfMissing($conn, 'equipment', 'movement_id', "movement_id INT(11) NULL");
    addColumnIfMissing($conn, 'equipment', 'vendor_id',   "vendor_id INT(11) NULL");
    try {
        $r = @$conn->query("SHOW INDEX FROM equipment WHERE Key_name = 'idx_equip_item'");
        if ($r && $r->num_rows === 0) @$conn->query("ALTER TABLE equipment ADD INDEX idx_equip_item (item_id)");
    } catch (Throwable $e) {}
    // issued = handed to a staff member (assigned = installed at a customer)
    try {
        $es = @$conn->query("SHOW COLUMNS FROM equipment LIKE 'status'");
        if ($es && ($er = $es->fetch_assoc()) && stripos($er['Type'], "'issued'") === false) {
            if (@$conn->query("ALTER TABLE equipment MODIFY COLUMN status ENUM('in_stock','assigned','issued','returned','lost','damaged','retired') NOT NULL DEFAULT 'in_stock'"))
                usLog('success', "Extended equipment.status with 'issued'");
        }
    } catch (Throwable $e) {}

    // ── 3. Ledger ────────────────────────────────────────────────
    // party_id / ref_id are NOT NULL so the unique key actually dedupes (NULL never equals NULL)
    mkTable($conn, 'ledger_entries', "CREATE TABLE IF NOT EXISTS ledger_entries (
        id BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
        entry_date DATE NOT NULL,
        party_type ENUM('customer','vendor','staff','bank','cash','other') NOT NULL,
        party_id INT(11) NOT NULL DEFAULT 0,
        particulars VARCHAR(255) NOT NULL,
        ref_type ENUM('invoice','payment','expense','expense_payment','stock_purchase','stock_return','opening','manual','transfer') NOT NULL,
        ref_id INT(11) NOT NULL DEFAULT 0,
        ref_number VARCHAR(40) NULL,
        debit DECIMAL(14,2) NOT NULL DEFAULT 0,
        credit DECIMAL(14,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_by_user_id INT(11) NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ledger_ref (ref_type, ref_id, party_type, party_id),
        INDEX idx_party (party_type, party_id, entry_date),
        INDEX idx_date (entry_date),
        INDEX idx_ref (ref_type, ref_id),
        INDEX idx_deleted (is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    addColumnIfMissing($conn, 'expense_payments', 'bank_account_id', "bank_account_id INT(11) NULL");
    addColumnIfMissing($conn, 'expense_payments', 'updated_at', "updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

    // ── 4. Seed the built-in roles (locked system rows) ──────────
    // role_key, label, colour, icon, scope, is_super, alerts, sort
    $builtins = [
        [ROLE_SUPER_ADMIN,  'Super Admin',  '#b00020', 'fas fa-crown',       'all',  1, 1, 1, 'Owner — full access to everything including system settings'],
        [ROLE_ADMIN,        'Admin',        '#6a1b9a', 'fas fa-user-shield', 'all',  0, 1, 2, 'Operations manager — everything except system settings'],
        [ROLE_ACCOUNTANT,   'Accountant',   '#0074D9', 'fas fa-calculator',  'all',  0, 0, 3, 'Finance — invoices, payments, expenses, vendors and ledger'],
        [ROLE_AREA_MANAGER, 'Area Manager', '#f59e0b', 'fas fa-map-location-dot', 'area', 0, 0, 4, 'Runs one or more service areas — staff, subscribers, collections and stock in those areas'],
        [ROLE_COLLECTOR,    'Collector',    '#2e7d32', 'fas fa-motorcycle',  'area', 0, 0, 5, 'Field staff — collects payments and logs complaints in their own areas'],
    ];
    $seeded = 0;
    try {
    foreach ($builtins as [$key, $label, $color, $icon, $scope, $super, $alerts, $sort, $desc]) {
        $chk = $conn->prepare("SELECT id FROM roles WHERE role_key = ?");
        $chk->bind_param("s", $key);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
        if (!$exists) {
            $ins = $conn->prepare("INSERT INTO roles (role_key, label, description, badge_color, icon, data_scope, is_system, is_super, receives_admin_alerts, sort_order)
                                   VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
            $ins->bind_param("ssssssiii", $key, $label, $desc, $color, $icon, $scope, $super, $alerts, $sort);
            $ins->execute();
            $ins->close();
            $seeded++;
        }
    }
    } catch (Throwable $e) { usLog('error', 'Role seed error: ' . htmlspecialchars($e->getMessage()), 'fa-exclamation-triangle'); }
    usLog($seeded ? 'success' : 'info', $seeded ? "Built-in roles seeded: $seeded" : 'Built-in roles already present', $seeded ? 'fa-check-circle' : 'fa-info-circle');

    // ── 5. Seed the default permission matrix (only rows that are missing) ──
    $added = 0;
    try {
        $rs = $conn->query("SELECT role_key FROM roles WHERE is_deleted = 0");
        $ins = $conn->prepare("INSERT INTO role_permissions (role_key, page_key, can_view, can_add, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
        $has = $conn->prepare("SELECT id FROM role_permissions WHERE role_key = ? AND page_key = ?");
        while ($r = $rs->fetch_assoc()) {
            $rk = $r['role_key'];
            foreach (rbacDefaultPerms($rk) as $pageKey => $p) {
                $has->bind_param("ss", $rk, $pageKey);
                $has->execute();
                if ($has->get_result()->num_rows > 0) continue;
                [$v, $a, $e, $d] = $p;
                $ins->bind_param("ssiiii", $rk, $pageKey, $v, $a, $e, $d);
                $ins->execute();
                $added++;
            }
        }
        $has->close();
        $ins->close();
        usLog($added ? 'success' : 'info', $added ? "Permission matrix rows added: $added" : 'Permission matrix already complete', $added ? 'fa-check-circle' : 'fa-info-circle');
    } catch (Throwable $e) {
        usLog('error', 'Permission seed error: ' . htmlspecialchars($e->getMessage()), 'fa-exclamation-triangle');
    }

    // ── 6. Mirror users.area_id into user_areas (one-time, additive) ──
    try {
        $conn->query("INSERT IGNORE INTO user_areas (user_id, area_id)
                      SELECT u.id, u.area_id FROM users u
                      WHERE u.area_id IS NOT NULL AND u.area_id > 0 AND u.is_deleted = 0");
        usLog('info', 'user_areas mirrored from users.area_id (' . (int)$conn->affected_rows . ' rows)', 'fa-clock-rotate-left');
    } catch (Throwable $e) {
        usLog('info', 'user_areas backfill skipped: ' . htmlspecialchars($e->getMessage()), 'fa-info-circle');
    }

    // ── 7. Default stock categories (reference data) ─────────────
    $cats = [
        ['Fiber & Cable',     'Drop wire, ADSS, patch cords'],
        ['Connectors & Splitters', 'SC/APC, fast connectors, PLC splitters'],
        ['Routers & ONU',     'CPE devices issued to subscribers'],
        ['Network Equipment', 'Switches, OLT modules, media converters'],
        ['Tools & Consumables', 'Clamps, ties, tape, cleaning kits'],
    ];
    $c = 0;
    try {
    foreach ($cats as [$cn, $cd]) {
        $chk = $conn->prepare("SELECT id FROM stock_categories WHERE name = ?");
        $chk->bind_param("s", $cn);
        $chk->execute();
        $found = $chk->get_result()->num_rows > 0;
        $chk->close();
        if (!$found) {
            $ins = $conn->prepare("INSERT INTO stock_categories (name, description) VALUES (?, ?)");
            $ins->bind_param("ss", $cn, $cd);
            $ins->execute();
            $ins->close();
            $c++;
        }
    }
    } catch (Throwable $e) { usLog('error', 'Stock category seed error: ' . htmlspecialchars($e->getMessage()), 'fa-exclamation-triangle'); }
    usLog($c ? 'success' : 'info', $c ? "Stock categories seeded: $c" : 'Stock categories already present', $c ? 'fa-check-circle' : 'fa-info-circle');

    // ── 8. Ledger backfill — existing history becomes ledger rows ──
    // INSERT IGNORE against uk_ledger_ref = idempotent, re-runnable, never double-posts
    $cash = cashAccountId($conn);
    $L = "INSERT IGNORE INTO ledger_entries (entry_date, party_type, party_id, particulars, ref_type, ref_id, ref_number, debit, credit) ";
    $steps = [
        'invoices' => $L . "SELECT COALESCE(i.issue_date, DATE(i.created_at)), 'customer', i.customer_id,
                CONCAT('Invoice ', i.invoice_number, ' (', i.billing_month, ')'), 'invoice', i.id, i.invoice_number, i.total, 0
            FROM invoices i WHERE i.is_deleted = 0 AND i.cancelled_at IS NULL",

        'payments (customer)' => $L . "SELECT p.payment_date, 'customer', p.customer_id,
                CONCAT('Payment received ', p.receipt_number), 'payment', p.id, p.receipt_number, 0,
                p.amount - COALESCE(p.refunded_amount, 0)
            FROM payments p WHERE p.is_deleted = 0 AND p.status = 'cleared'",

        'payments (cash/bank)' => $L . "SELECT p.payment_date,
                IF(COALESCE(p.bank_account_id,0) = 0, 'cash', 'bank'),
                IF(COALESCE(p.bank_account_id,0) = 0, $cash, p.bank_account_id),
                CONCAT('Receipt ', p.receipt_number), 'payment', p.id, p.receipt_number,
                p.amount - COALESCE(p.refunded_amount, 0), 0
            FROM payments p WHERE p.is_deleted = 0 AND p.status = 'cleared'",

        'vendor bills' => $L . "SELECT e.expense_date, 'vendor', e.vendor_id,
                CONCAT('Bill ', COALESCE(NULLIF(e.expense_number,''), CONCAT('EXP-', e.id)), ' — ', LEFT(e.description, 120)),
                'expense', e.id, e.expense_number, 0, e.amount
            FROM expenses e WHERE e.is_deleted = 0 AND COALESCE(e.vendor_id, 0) > 0",

        'vendor payments' => $L . "SELECT ep.payment_date, 'vendor', e.vendor_id,
                CONCAT('Paid vendor — ', COALESCE(NULLIF(ep.reference,''), CONCAT('EP-', ep.id))),
                'expense_payment', ep.id, ep.reference, ep.amount, 0
            FROM expense_payments ep JOIN expenses e ON e.id = ep.expense_id
            WHERE ep.is_deleted = 0 AND e.is_deleted = 0 AND COALESCE(e.vendor_id, 0) > 0",

        'vendor payments (cash/bank)' => $L . "SELECT ep.payment_date,
                IF(COALESCE(ep.bank_account_id,0) = 0, 'cash', 'bank'),
                IF(COALESCE(ep.bank_account_id,0) = 0, $cash, ep.bank_account_id),
                CONCAT('Paid ', COALESCE(NULLIF(e.vendor_name,''), 'vendor'), ' — ', COALESCE(NULLIF(ep.reference,''), CONCAT('EP-', ep.id))),
                'expense_payment', ep.id, ep.reference, 0, ep.amount
            FROM expense_payments ep JOIN expenses e ON e.id = ep.expense_id
            WHERE ep.is_deleted = 0 AND e.is_deleted = 0",

        'vendor opening balances' => $L . "SELECT DATE(v.created_at), 'vendor', v.id,
                'Opening Balance', 'opening', v.id, 'OPENING', 0, v.opening_balance
            FROM vendors v WHERE v.is_deleted = 0 AND v.opening_balance <> 0",

        'bank opening balances' => $L . "SELECT DATE(b.created_at), 'bank', b.id,
                'Opening Balance', 'opening', b.id, 'OPENING', b.opening_balance, 0
            FROM bank_accounts b WHERE b.is_deleted = 0 AND b.opening_balance <> 0",
    ];
    $tot = 0;
    foreach ($steps as $what => $sql) {
        try {
            $conn->query($sql);
            $n = (int)$conn->affected_rows;
            $tot += $n;
            if ($n) usLog('success', "Ledger backfill — $what: $n rows");
        } catch (Throwable $e) {
            usLog('info', "Ledger backfill skipped ($what): " . htmlspecialchars($e->getMessage()), 'fa-info-circle');
        }
    }
    usLog($tot ? 'success' : 'info', $tot ? "Ledger backfill complete — $tot new entries" : 'Ledger already up to date', $tot ? 'fa-check-circle' : 'fa-info-circle');

    // ── 8. Invoice stock lines — sell inventory straight off an invoice ──
    // one invoice ↔ one auto issue_out movement; the movement is what moves qty, invoice_items is what prints
    mkTable($conn, 'invoice_items', "CREATE TABLE IF NOT EXISTS invoice_items (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT(11) NOT NULL,
        item_id INT(11) NULL,
        item_code VARCHAR(20) NULL,
        description VARCHAR(200) NOT NULL,
        qty DECIMAL(12,2) NOT NULL DEFAULT 1,
        unit VARCHAR(20) NULL,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        serials TEXT NULL,
        sort_order INT(11) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_inv (invoice_id),
        INDEX idx_item (item_id),
        CONSTRAINT fk_ii_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        CONSTRAINT fk_ii_item FOREIGN KEY (item_id) REFERENCES stock_items(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    addColumnIfMissing($conn, 'invoices', 'items_total',       "items_total DECIMAL(12,2) NOT NULL DEFAULT 0");
    addColumnIfMissing($conn, 'invoices', 'stock_movement_id', "stock_movement_id INT(11) NULL");
    addColumnIfMissing($conn, 'stock_movements', 'invoice_id', "invoice_id INT(11) NULL");
    try {
        $r = @$conn->query("SHOW INDEX FROM stock_movements WHERE Key_name = 'idx_mov_invoice'");
        if ($r && $r->num_rows === 0) @$conn->query("ALTER TABLE stock_movements ADD INDEX idx_mov_invoice (invoice_id)");
    } catch (Throwable $e) {}

    // ── append future schema changes BELOW this line ─────────────
}

// run-if-main: opened directly → apply to the live DB + show a page; require'd by setup.php → silent
$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
if (realpath(__FILE__) === realpath($scriptFilename) || basename($scriptFilename) === 'update_setup.php') {

    // setup.php is wide open; this one at least asks who you are (fresh DB = no users yet, so let it run)
    $fresh = false;
    try {
        $c0 = getDBConnection();
        $t = @$c0->query("SHOW TABLES LIKE 'users'");
        $fresh = !$t || $t->num_rows === 0;
        if (!$fresh) {
            $u = @$c0->query("SELECT COUNT(*) c FROM users");
            $fresh = !$u || (int)$u->fetch_assoc()['c'] === 0;
        }
    } catch (Throwable $e) { $fresh = true; }

    if (!$fresh && !isSuperAdmin()) {
        header("Location: dashboard.php");
        exit();
    }
    ?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Database Update</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include APP_ROOT . '/pwa.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body>
    <div class="setup-wrapper">
    <div class="setup-container">
        <h2><i class="fas fa-arrows-rotate"></i> Database Update</h2>
        <p class="subtitle">Additive only — new tables and columns are added, existing data is never touched.</p>
        <hr>
        <?php
        mysqli_report(MYSQLI_REPORT_OFF); // this builder expects query() to return false, not throw
        $conn = getDBConnection();
        usLog('success', 'Database connection successful');
        applyUpdates($conn);
        ?>
        <br>
        <div class="log-item log-success"><i class="fas fa-circle-check"></i> <strong>Update complete — safe to run again any time.</strong></div>
        <br>
        <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-gauge-high"></i> Back to Dashboard</a>
        <a href="db_check.php" class="btn btn-secondary"><i class="fas fa-stethoscope"></i> Run DB Check</a>
    </div>
    </div>
</body>
</html>
<?php
}
