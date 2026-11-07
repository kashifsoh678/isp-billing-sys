<?php
 
require_once 'config.php';
require_once 'import_helper.php';

// gate: all 4 roles
if (!isset($_SESSION['user_id']) || !can('complaints', 'v')) {
    header("Location: dashboard.php");
    exit();
}
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'complaints';
$can_delete   = can('complaints', 'd');
$is_collector = isAreaScoped(); // area-locked roles: own areas only
$is_admin_lvl = isAdminLevel();

// upload constraints
define('CMP_UPLOAD_DIR', __DIR__ . '/uploads/complaint_attachments/');
define('CMP_UPLOAD_URL', 'uploads/complaint_attachments/');
define('CMP_MAX_BYTES',  5 * 1024 * 1024); // 5MB
$CMP_ALLOWED_EXT  = ['jpg','jpeg','png','webp','pdf'];
$CMP_ALLOWED_MIME = ['image/jpeg','image/png','image/webp','application/pdf'];

// auto ticket # — TKT-NNNN
function nextTicketNumber($conn) {
    $res = $conn->query("SELECT MAX(CAST(SUBSTRING(ticket_number, 5) AS UNSIGNED)) AS m FROM complaints WHERE ticket_number LIKE 'TKT-%'");
    $row = $res ? $res->fetch_assoc() : null;
    $n = ($row && $row['m']) ? (int)$row['m'] + 1 : 1;
    return sprintf('TKT-%04d', $n);
}

// fetch attachments for a complaint
function fetchAttachments($conn, $complaint_id) {
    $stmt = $conn->prepare("SELECT a.id, a.file_path, a.original_name, a.file_size, a.uploaded_by, a.uploaded_at,
                                   u.name AS uploaded_by_name
                            FROM complaint_attachments a
                            LEFT JOIN users u ON u.id = a.uploaded_by
                            WHERE a.complaint_id = ?
                            ORDER BY a.uploaded_at DESC");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $ext = strtolower(pathinfo($r['original_name'], PATHINFO_EXTENSION));
        $rows[] = [
            'id'               => (int)$r['id'],
            'file_path'        => $r['file_path'],
            'file_url'         => CMP_UPLOAD_URL . $r['file_path'],
            'original_name'    => $r['original_name'],
            'file_size'        => (int)$r['file_size'],
            'ext'              => $ext,
            'is_image'         => in_array($ext, ['jpg','jpeg','png','webp'], true),
            'uploaded_by'      => (int)$r['uploaded_by'],
            'uploaded_by_name' => $r['uploaded_by_name'] ?: 'Unknown',
            'uploaded_at'      => date('c', strtotime($r['uploaded_at'])),
        ];
    }
    $stmt->close();
    return $rows;
}

// csv import/template contract for this page
function complaintImportSpec() {
    return [
        'entity'  => 'Complaint',
        'table'   => 'complaints',
        'perm'    => 'complaints',
        'columns' => [
            'customer_id' => ['label' => 'Customer', 'type' => 'lookup', 'required' => true,
                               'lookup' => ['table' => 'customers', 'match' => ['customer_code', 'full_name'], 'where' => 'is_deleted = 0'],
                               'demo'  => 'CUST-0001'],
            'subject'     => ['label' => 'Subject', 'type' => 's', 'required' => true, 'max' => 255, 'demo' => 'Slow internet speed'],
            'description' => ['label' => 'Description', 'type' => 's', 'required' => true, 'demo' => 'Customer reports slow speeds since this morning'],
            'category'    => ['label' => 'Category', 'type' => 'enum',
                               'values' => ['speed','connectivity','billing','installation','equipment','outage','other'],
                               'default' => 'other', 'demo' => 'connectivity'],
            'priority'    => ['label' => 'Priority', 'type' => 'enum',
                               'values' => ['low','medium','high','urgent'], 'default' => 'medium', 'demo' => 'medium'],
            'status'      => ['label' => 'Status', 'type' => 'enum',
                               'values' => ['open','in_progress','on_hold','resolved','closed'], 'default' => 'open', 'demo' => 'open'],
            'source'      => ['label' => 'Source', 'type' => 'enum',
                               'values' => ['call','whatsapp','email','walk_in','portal'], 'default' => 'call', 'demo' => 'call'],
            'assigned_to' => ['label' => 'Assigned Staff', 'type' => 'lookup',
                               'lookup' => ['table' => 'users', 'match' => ['username', 'name'], 'where' => 'is_deleted = 0'],
                               'demo'  => ''],
        ],
        // ticket # is app-generated (TKT-0001...) — seed the counter once, bump in-memory per row
        'init' => function ($conn, &$state) {
            $state['next_ticket'] = (int)substr(nextTicketNumber($conn), 4);
        },
        'before' => function (&$row, $conn, &$state) {
            $row['ticket_number'] = 'TKT-' . str_pad($state['next_ticket']++, 4, '0', STR_PAD_LEFT);
            return null;
        },
    ];
}

// AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'importCsv':      rsImportRun($conn, complaintImportSpec());
            case 'importTemplate': rsImportTemplate(complaintImportSpec());

            case 'getComplaints': {
                $where = [];
                $types = '';
                $params = [];

                // area-locked roles: their own areas only
                if ($is_collector) {
                    $scope = areaScope('c');
                    if ($scope === '') {
                        echo json_encode(['success' => true, 'data' => []]);
                        exit();
                    }
                    $where[] = ltrim($scope, ' AND');
                }

                if (!empty($_GET['status'])) {
                    $where[] = "cmp.status = ?";
                    $types  .= 's';
                    $params[] = $_GET['status'];
                }
                if (!empty($_GET['priority'])) {
                    $where[] = "cmp.priority = ?";
                    $types  .= 's';
                    $params[] = $_GET['priority'];
                }
                if (!empty($_GET['category'])) {
                    $where[] = "cmp.category = ?";
                    $types  .= 's';
                    $params[] = $_GET['category'];
                }
                if (!empty($_GET['source'])) {
                    $where[] = "cmp.source = ?";
                    $types  .= 's';
                    $params[] = $_GET['source'];
                }
                if (isset($_GET['assigned_to']) && $_GET['assigned_to'] !== '') {
                    if ($_GET['assigned_to'] === 'unassigned') {
                        $where[] = "cmp.assigned_to IS NULL";
                    } else {
                        $where[] = "cmp.assigned_to = ?";
                        $types  .= 'i';
                        $params[] = (int)$_GET['assigned_to'];
                    }
                }
                if (!empty($_GET['area_id']) && $is_admin_lvl) {
                    $where[] = "c.area_id = ?";
                    $types  .= 'i';
                    $params[] = (int)$_GET['area_id'];
                }
                if (!empty($_GET['q'])) {
                    $q = '%' . $_GET['q'] . '%';
                    $where[] = "(cmp.ticket_number LIKE ? OR c.full_name LIKE ? OR c.customer_code LIKE ? OR cmp.subject LIKE ?)";
                    $types  .= 'ssss';
                    $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
                }

                $sql = "SELECT cmp.id, cmp.ticket_number, cmp.subject, cmp.priority, cmp.status,
                               cmp.category, cmp.source, cmp.complaint_source, cmp.severity,
                               cmp.is_mass_outage, cmp.affected_users_count,
                               cmp.sla_breach_flag, cmp.sla_breach_minutes, cmp.tags,
                               cmp.expected_resolution_date,
                               cmp.assigned_to, cmp.created_at, cmp.resolved_at,
                               c.id AS customer_id, c.customer_code, c.full_name AS customer_name, c.area_id,
                               u.name AS assigned_name
                        FROM complaints cmp
                        INNER JOIN customers c ON c.id = cmp.customer_id
                        LEFT  JOIN users u ON u.id = cmp.assigned_to";
                if ($where) $sql .= " WHERE " . implode(' AND ', $where);
                $sql .= " ORDER BY CASE cmp.priority WHEN 'urgent' THEN 0 ELSE 1 END, cmp.created_at DESC";

                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id'                  => (int)$r['id'],
                        'ticket_number'       => $r['ticket_number'],
                        'subject'             => $r['subject'],
                        'priority'            => $r['priority'],
                        'status'              => $r['status'],
                        'category'            => $r['category'],
                        'source'              => $r['source'],
                        'complaint_source'    => $r['complaint_source'] ?? $r['source'],
                        'severity'            => $r['severity'] ?? 'medium',
                        'is_mass_outage'      => (int)($r['is_mass_outage'] ?? 0),
                        'affected_users_count'=> $r['affected_users_count'] !== null ? (int)$r['affected_users_count'] : null,
                        'sla_breach_flag'     => (int)($r['sla_breach_flag'] ?? 0),
                        'sla_breach_minutes'  => (int)($r['sla_breach_minutes'] ?? 0),
                        'tags'                => $r['tags'] ?? '',
                        'expected_resolution_date' => $r['expected_resolution_date'] ? date('c', strtotime($r['expected_resolution_date'])) : null,
                        'customer_id'         => (int)$r['customer_id'],
                        'customer_code'       => $r['customer_code'],
                        'customer_name'       => $r['customer_name'],
                        'assigned_to'         => $r['assigned_to'] ? (int)$r['assigned_to'] : null,
                        'assigned_name'       => $r['assigned_name'],
                        'created_at'          => date('c', strtotime($r['created_at'])),
                        'resolved_at'         => $r['resolved_at'] ? date('c', strtotime($r['resolved_at'])) : null,
                    ];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getComplaintDetail': {
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }

                $sql = "SELECT cmp.*,
                               c.customer_code, c.full_name AS customer_name, c.phone AS customer_phone,
                               c.area_id, a.name AS area_name,
                               u.name AS assigned_name,
                               eu.name AS escalated_to_user_name,
                               cu.name AS closed_by_user_name
                        FROM complaints cmp
                        INNER JOIN customers c ON c.id = cmp.customer_id
                        LEFT  JOIN areas a  ON a.id = c.area_id
                        LEFT  JOIN users u  ON u.id = cmp.assigned_to
                        LEFT  JOIN users eu ON eu.id = cmp.escalated_to_user_id
                        LEFT  JOIN users cu ON cu.id = cmp.closed_by_user_id
                        WHERE cmp.id = ? LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { echo json_encode(['success' => false, 'message' => 'Ticket not found']); exit(); }

                // collector area scope
                if ($is_collector && !areaAllowed($row['area_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $can_edit = $is_admin_lvl || ($is_collector && (int)$row['assigned_to'] === $user_id);

                $att = fetchAttachments($conn, $id);

                // iso date helper — frontend formats for display
                $fmtIso = function($v) { return $v ? date('c', strtotime($v)) : null; };

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'id'                       => (int)$row['id'],
                        'ticket_number'            => $row['ticket_number'],
                        'subject'                  => $row['subject'],
                        'description'              => $row['description'],
                        'priority'                 => $row['priority'],
                        'status'                   => $row['status'],
                        'category'                 => $row['category'],
                        'source'                   => $row['source'],
                        'complaint_source'         => $row['complaint_source'] ?? $row['source'],
                        'severity'                 => $row['severity'] ?? 'medium',
                        'is_mass_outage'           => (int)($row['is_mass_outage'] ?? 0),
                        'affected_users_count'     => $row['affected_users_count'] !== null ? (int)$row['affected_users_count'] : null,
                        'area_id_affected'         => $row['area_id_affected'] ? (int)$row['area_id_affected'] : null,
                        'parent_complaint_id'      => $row['parent_complaint_id'] ? (int)$row['parent_complaint_id'] : null,
                        'tags'                     => $row['tags'] ?? '',
                        'assigned_to'              => $row['assigned_to'] ? (int)$row['assigned_to'] : null,
                        'assigned_name'            => $row['assigned_name'],
                        'resolution'               => $row['resolution'],
                        'resolved_at'              => $fmtIso($row['resolved_at']),
                        'first_response_at'        => $fmtIso($row['first_response_at']),
                        'first_response_user_id'   => $row['first_response_user_id'] ? (int)$row['first_response_user_id'] : null,
                        'assigned_at'              => $fmtIso($row['assigned_at']),
                        'acknowledged_at'          => $fmtIso($row['acknowledged_at']),
                        'arrived_onsite_at'        => $fmtIso($row['arrived_onsite_at']),
                        'work_started_at'          => $fmtIso($row['work_started_at']),
                        'work_completed_at'        => $fmtIso($row['work_completed_at']),
                        'closed_at'                => $fmtIso($row['closed_at']),
                        'expected_resolution_date' => $fmtIso($row['expected_resolution_date']),
                        'expected_resolution_input'   => $row['expected_resolution_date'] ? date('Y-m-d\TH:i', strtotime($row['expected_resolution_date'])) : '',
                        'resolution_time_minutes'  => $row['resolution_time_minutes'] !== null ? (int)$row['resolution_time_minutes'] : null,
                        'resolution_category'      => $row['resolution_category'] ?? '',
                        'resolution_summary'       => $row['resolution_summary'] ?? '',
                        'parts_replaced_json'      => $row['parts_replaced_json'] ?? '',
                        'cost_to_resolve'          => (float)($row['cost_to_resolve'] ?? 0),
                        'chargeable_to_customer'   => (int)($row['chargeable_to_customer'] ?? 0),
                        'customer_charge_amount'   => (float)($row['customer_charge_amount'] ?? 0),
                        'customer_invoice_id'      => $row['customer_invoice_id'] ? (int)$row['customer_invoice_id'] : null,
                        'sla_breach_flag'          => (int)($row['sla_breach_flag'] ?? 0),
                        'sla_breach_minutes'       => (int)($row['sla_breach_minutes'] ?? 0),
                        'reopen_count'             => (int)($row['reopen_count'] ?? 0),
                        'reopened_reason'          => $row['reopened_reason'] ?? '',
                        'escalation_level'         => (int)($row['escalation_level'] ?? 0),
                        'escalation_reason'        => $row['escalation_reason'] ?? '',
                        'customer_satisfaction_rating' => $row['customer_satisfaction_rating'] !== null ? (int)$row['customer_satisfaction_rating'] : null,
                        'customer_feedback_text'   => $row['customer_feedback_text'] ?? '',
                        'feedback_collected_at'    => $fmtIso($row['feedback_collected_at']),
                        'customer_rating'          => $row['customer_rating'] !== null ? (int)$row['customer_rating'] : null,
                        'customer_feedback'        => $row['customer_feedback'],
                        'closed_by_user_id'        => $row['closed_by_user_id'] ? (int)$row['closed_by_user_id'] : null,
                        'closed_by_user_name'      => $row['closed_by_user_name'],
                        'escalated_to_user_id'     => $row['escalated_to_user_id'] ? (int)$row['escalated_to_user_id'] : null,
                        'escalated_to_user_name'   => $row['escalated_to_user_name'],
                        'escalated_at'             => $fmtIso($row['escalated_at']),
                        'reopened_count'           => (int)$row['reopened_count'],
                        'created_at'               => date('c', strtotime($row['created_at'])),
                        'updated_at'               => date('c', strtotime($row['updated_at'])),
                        'customer_id'              => (int)$row['customer_id'],
                        'customer_code'            => $row['customer_code'],
                        'customer_name'            => $row['customer_name'],
                        'customer_phone'           => $row['customer_phone'],
                        'area_id'                  => $row['area_id'] ? (int)$row['area_id'] : null,
                        'area_name'                => $row['area_name'],
                        'can_edit'                 => $can_edit,
                        'attachments'              => $att
                    ]
                ]);
                exit();
            }

            case 'getStaff': {
                $sql = "SELECT id, name, role FROM users
                        WHERE is_deleted = 0 AND status = 'active'
                          AND " . roleInSql('users', 'complaints', 'v') . "
                        ORDER BY name ASC";
                $res = $conn->query($sql);
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'role' => $r['role']];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getActiveCustomers': {
                if ($is_collector) {
                    $scope = areaScope('customers');
                    if ($scope === '') { echo json_encode(['success' => true, 'data' => []]); exit(); }
                    $stmt = $conn->prepare("SELECT id, customer_code, full_name FROM customers WHERE is_deleted = 0 AND connection_status != 'disconnected' $scope ORDER BY full_name ASC");
                } else {
                    $stmt = $conn->prepare("SELECT id, customer_code, full_name FROM customers WHERE is_deleted = 0 AND connection_status != 'disconnected' ORDER BY full_name ASC");
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = ['id' => (int)$r['id'], 'customer_code' => $r['customer_code'], 'full_name' => $r['full_name']];
                }
                $stmt->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'getAreas': {
                $res = $conn->query("SELECT id, name FROM areas WHERE is_deleted = 0 AND status = 'active' ORDER BY name ASC");
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = ['id' => (int)$r['id'], 'name' => $r['name']];
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            case 'addComplaint': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit();
                }
                $cust_id  = (int)($_POST['customer_id'] ?? 0);
                $subject  = trim($_POST['subject'] ?? '');
                $desc     = trim($_POST['description'] ?? '');
                $priority = $_POST['priority'] ?? 'medium';
                $category = $_POST['category'] ?? 'other';
                $source   = $_POST['source']   ?? 'call';
                $assigned = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;

                // new fields
                $cmp_source = $_POST['complaint_source'] ?? $source;
                if (!in_array($cmp_source, ['call','whatsapp','portal','walk_in','social','app','email'], true)) {
                    $cmp_source = 'call';
                }
                $severity = $_POST['severity'] ?? 'medium';
                if (!in_array($severity, ['low','medium','high','critical'], true)) $severity = 'medium';

                $is_mass_outage = !empty($_POST['is_mass_outage']) && $_POST['is_mass_outage'] !== '0' ? 1 : 0;
                $affected_in    = trim($_POST['affected_users_count'] ?? '');
                $affected       = ($is_mass_outage && $affected_in !== '') ? (int)$affected_in : null;
                $area_aff_in    = trim($_POST['area_id_affected'] ?? '');
                $area_aff       = ($is_mass_outage && $area_aff_in !== '') ? (int)$area_aff_in : null;
                $parent_in      = trim($_POST['parent_complaint_id'] ?? '');
                $parent_id      = ($parent_in !== '' ? (int)$parent_in : null);
                $tags           = trim($_POST['tags'] ?? '');
                if (strlen($tags) > 255) $tags = substr($tags, 0, 255);

                // expected resolution — admin-level only
                $exp_raw = trim($_POST['expected_resolution_date'] ?? '');
                $exp_dt  = null;
                if ($is_admin_lvl && $exp_raw !== '') {
                    $ts = strtotime($exp_raw);
                    if ($ts === false) { echo json_encode(['success' => false, 'message' => 'Invalid expected resolution date']); exit(); }
                    $exp_dt = date('Y-m-d H:i:s', $ts);
                }

                if ($cust_id <= 0)        { echo json_encode(['success' => false, 'message' => 'Customer is required']); exit(); }
                if ($subject === '')      { echo json_encode(['success' => false, 'message' => 'Subject is required']); exit(); }
                if ($desc === '')         { echo json_encode(['success' => false, 'message' => 'Description is required']); exit(); }
                if (!in_array($priority, ['low','medium','high','urgent'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid priority']); exit();
                }
                if (!in_array($category, ['speed','connectivity','billing','installation','equipment','outage','other'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid category']); exit();
                }
                if (!in_array($source, ['call','whatsapp','email','walk_in','portal'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid source']); exit();
                }

                // validate customer exists + area scope for collector
                $stmt = $conn->prepare("SELECT id, area_id, full_name FROM customers WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("i", $cust_id);
                $stmt->execute();
                $cust = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$cust) { echo json_encode(['success' => false, 'message' => 'Customer not found']); exit(); }

                if ($is_collector) {
                    if (!areaAllowed($cust['area_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Customer not in your area']); exit();
                    }
                    // collector pre-assigns to themselves
                    $assigned = $user_id;
                }

                $ticket = nextTicketNumber($conn);

                // assigned_at auto-set if assigning at create
                $assigned_at_now = $assigned ? date('Y-m-d H:i:s') : null;

                if ($assigned === null) {
                    $sql = "INSERT INTO complaints
                            (ticket_number, customer_id, subject, description, priority, category, source,
                             complaint_source, severity, is_mass_outage, affected_users_count, area_id_affected,
                             parent_complaint_id, tags, expected_resolution_date, assigned_to, assigned_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)";
                    $stmt = $conn->prepare($sql);
                    // 15 vars: ticket(s) cust(i) subj(s) desc(s) pri(s) cat(s) src(s)
                    //          cmp_src(s) sev(s) is_mass(i) affected(i) area_aff(i)
                    //          parent(i) tags(s) exp(s)
                    $types = "sisssssssiiiiss"; // 15 letters
                    $stmt->bind_param(
                        $types,
                        $ticket, $cust_id, $subject, $desc, $priority, $category, $source,
                        $cmp_source, $severity, $is_mass_outage, $affected, $area_aff,
                        $parent_id, $tags, $exp_dt
                    );
                } else {
                    $sql = "INSERT INTO complaints
                            (ticket_number, customer_id, subject, description, priority, category, source,
                             complaint_source, severity, is_mass_outage, affected_users_count, area_id_affected,
                             parent_complaint_id, tags, expected_resolution_date, assigned_to, assigned_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    // 17 vars: 15 above + assigned(i) + assigned_at_now(s)
                    $types = "sisssssssiiiissis"; // 17 letters
                    $stmt->bind_param(
                        $types,
                        $ticket, $cust_id, $subject, $desc, $priority, $category, $source,
                        $cmp_source, $severity, $is_mass_outage, $affected, $area_aff,
                        $parent_id, $tags, $exp_dt, $assigned, $assigned_at_now
                    );
                }

                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;
                    $stmt->close();
                    logActivity($user_id, $username, 'Complaint Created', "Created ticket $ticket for customer #$cust_id - $subject ($category/$source)");

                    // notify admins
                    createNotificationForAdmins('New Ticket', "$ticket — $subject", 'info', "complaints.php?id=$new_id");
                    // notify assignee
                    if ($assigned && $assigned !== $user_id) {
                        createNotification($assigned, 'New Ticket Assigned', "$ticket — $subject", 'warning', "complaints.php?id=$new_id");
                    }

                    echo json_encode(['success' => true, 'message' => "Ticket $ticket created", 'id' => $new_id, 'ticket_number' => $ticket]);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to create: ' . $err]);
                }
                exit();
            }

            case 'updateComplaint': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }

                // load existing
                $stmt = $conn->prepare("SELECT cmp.*, c.area_id FROM complaints cmp INNER JOIN customers c ON c.id = cmp.customer_id WHERE cmp.id = ? LIMIT 1");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $cur = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$cur) { echo json_encode(['success' => false, 'message' => 'Ticket not found']); exit(); }

                // collector — assigned-only + area scope
                if ($is_collector) {
                    if ((int)$cur['assigned_to'] !== $user_id) {
                        echo json_encode(['success' => false, 'message' => 'Only the assigned collector can update this ticket']); exit();
                    }
                    if (!areaAllowed($cur['area_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Access denied']); exit();
                    }
                }

                $status     = $_POST['status']   ?? $cur['status'];
                $resolution = trim($_POST['resolution'] ?? '');
                if (!in_array($status, ['open','in_progress','on_hold','resolved','closed'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid status']); exit();
                }
                if (in_array($status, ['resolved','closed'], true) && $resolution === '') {
                    echo json_encode(['success' => false, 'message' => 'Resolution is required when resolving or closing']); exit();
                }

                // priority + assignment + category + source + expected_resolution — admin-level only can change
                if ($is_admin_lvl) {
                    $priority = $_POST['priority'] ?? $cur['priority'];
                    if (!in_array($priority, ['low','medium','high','urgent'], true)) {
                        echo json_encode(['success' => false, 'message' => 'Invalid priority']); exit();
                    }
                    $category = $_POST['category'] ?? $cur['category'];
                    if (!in_array($category, ['speed','connectivity','billing','installation','equipment','outage','other'], true)) {
                        echo json_encode(['success' => false, 'message' => 'Invalid category']); exit();
                    }
                    $source = $_POST['source'] ?? $cur['source'];
                    if (!in_array($source, ['call','whatsapp','email','walk_in','portal'], true)) {
                        echo json_encode(['success' => false, 'message' => 'Invalid source']); exit();
                    }
                    $assigned = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;

                    $exp_raw = trim($_POST['expected_resolution_date'] ?? '');
                    if ($exp_raw === '') {
                        $exp_dt = null;
                    } else {
                        $ts = strtotime($exp_raw);
                        if ($ts === false) { echo json_encode(['success' => false, 'message' => 'Invalid expected resolution date']); exit(); }
                        $exp_dt = date('Y-m-d H:i:s', $ts);
                    }
                } else {
                    $priority = $cur['priority'];
                    $category = $cur['category'];
                    $source   = $cur['source'];
                    $assigned = $cur['assigned_to'] ? (int)$cur['assigned_to'] : null;
                    $exp_dt   = $cur['expected_resolution_date'];
                }

                // workflow auto-fields
                $was_status   = $cur['status'];
                $was_resolved = !empty($cur['resolved_at']);
                $is_resolved  = ($status === 'resolved');
                $is_closed    = ($status === 'closed');
                $is_open      = ($status === 'open');
                $is_progress  = ($status === 'in_progress');

                $extra_sql    = []; // dynamic SQL fragments
                $extra_types  = '';
                $extra_params = [];

                // first_response_at — set on first non-customer activity (any non-open transition while NULL)
                if (empty($cur['first_response_at']) && !$is_open) {
                    $extra_sql[] = "first_response_at = NOW()";
                    $extra_sql[] = "first_response_user_id = ?";
                    $extra_types .= 'i';
                    $extra_params[] = $user_id;
                }

                // assigned_at — set when assignment changes from NULL to a user
                $prev_a = $cur['assigned_to'] ? (int)$cur['assigned_to'] : null;
                if ($prev_a === null && $assigned !== null && empty($cur['assigned_at'])) {
                    $extra_sql[] = "assigned_at = NOW()";
                }

                // resolved_at — set when entering resolved (not from closed)
                if ($is_resolved && !$was_resolved) {
                    $extra_sql[] = "resolved_at = NOW()";
                    $extra_sql[] = "resolution_time_minutes = TIMESTAMPDIFF(MINUTE, created_at, NOW())";
                    // also set work_completed_at if not yet set
                    if (empty($cur['work_completed_at'])) {
                        $extra_sql[] = "work_completed_at = NOW()";
                    }
                    // sla breach computation
                    if (!empty($cur['expected_resolution_date'])) {
                        $extra_sql[] = "sla_breach_flag = (NOW() > expected_resolution_date)";
                        $extra_sql[] = "sla_breach_minutes = GREATEST(0, TIMESTAMPDIFF(MINUTE, expected_resolution_date, NOW()))";
                    }
                }

                // closed — set closed_by + closed_at; preserve resolved_at + resolution_time_minutes
                if ($is_closed && $was_status !== 'closed') {
                    $extra_sql[] = "closed_by_user_id = ?";
                    $extra_types .= 'i';
                    $extra_params[] = $user_id;
                    $extra_sql[] = "closed_at = NOW()";
                    // if going closed without ever being resolved, also set resolved_at + minutes
                    if (!$was_resolved) {
                        $extra_sql[] = "resolved_at = NOW()";
                        $extra_sql[] = "resolution_time_minutes = TIMESTAMPDIFF(MINUTE, created_at, NOW())";
                    }
                }

                // reopen — going back to open/in_progress from resolved/closed
                if (($is_open || $is_progress) && in_array($was_status, ['resolved','closed'], true)) {
                    $extra_sql[] = "reopened_count = reopened_count + 1";
                    $extra_sql[] = "reopen_count = reopen_count + 1";
                    $extra_sql[] = "resolved_at = NULL";
                    $extra_sql[] = "resolution_time_minutes = NULL";
                    // closed_by_user_id preserved (historical), closed_at preserved
                }

                $extra_clause = $extra_sql ? (', ' . implode(', ', $extra_sql)) : '';

                if ($assigned === null) {
                    $sql = "UPDATE complaints
                            SET status=?, priority=?, category=?, source=?, expected_resolution_date=?,
                                resolution=?, assigned_to=NULL
                                $extra_clause
                            WHERE id=?";
                    $stmt = $conn->prepare($sql);
                    $types  = "ssssss" . $extra_types . "i";
                    $params = [$status, $priority, $category, $source, $exp_dt, $resolution];
                    foreach ($extra_params as $ep) $params[] = $ep;
                    $params[] = $id;
                    $stmt->bind_param($types, ...$params);
                } else {
                    $sql = "UPDATE complaints
                            SET status=?, priority=?, category=?, source=?, expected_resolution_date=?,
                                resolution=?, assigned_to=?
                                $extra_clause
                            WHERE id=?";
                    $stmt = $conn->prepare($sql);
                    $types  = "ssssssi" . $extra_types . "i";
                    $params = [$status, $priority, $category, $source, $exp_dt, $resolution, $assigned];
                    foreach ($extra_params as $ep) $params[] = $ep;
                    $params[] = $id;
                    $stmt->bind_param($types, ...$params);
                }

                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Complaint Updated', "Ticket {$cur['ticket_number']} -> $status");

                    // assignment changed — notify new assignee
                    $prev_assigned = $cur['assigned_to'] ? (int)$cur['assigned_to'] : null;
                    if ($assigned && $assigned !== $prev_assigned && $assigned !== $user_id) {
                        createNotification($assigned, 'New Ticket Assigned', "{$cur['ticket_number']} — {$cur['subject']}", 'warning', "complaints.php?id=$id");
                    }
                    // resolved — notify assignee (if any)
                    if ($is_resolved && !$was_resolved && $assigned && $assigned !== $user_id) {
                        createNotification($assigned, 'Ticket Resolved', "{$cur['ticket_number']} — $status", 'success', "complaints.php?id=$id");
                    }
                    // reopened — alert admins
                    if ($is_open && in_array($was_status, ['resolved','closed'], true)) {
                        createNotificationForAdmins('Ticket Reopened', "{$cur['ticket_number']} — {$cur['subject']}", 'warning', "complaints.php?id=$id");
                    }

                    echo json_encode(['success' => true, 'message' => 'Ticket updated']);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update: ' . $err]);
                }
                exit();
            }

            case 'escalateComplaint': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit();
                }
                if (!$is_admin_lvl) {
                    echo json_encode(['success' => false, 'message' => 'Only admins can escalate tickets']); exit();
                }
                $id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
                $to     = (int)($_POST['escalated_to_user_id'] ?? 0);
                $reason = trim($_POST['escalation_reason'] ?? '');
                if (strlen($reason) > 255) $reason = substr($reason, 0, 255);
                if ($id <= 0 || $to <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid params']); exit(); }

                // verify ticket exists
                $stmt = $conn->prepare("SELECT ticket_number, subject, assigned_to, escalation_level FROM complaints WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { echo json_encode(['success' => false, 'message' => 'Ticket not found']); exit(); }

                // verify user exists and is admin-level
                $stmt = $conn->prepare("SELECT id, name, role FROM users WHERE id = ? AND is_deleted = 0 AND status = 'active' AND " . roleInSql('users', 'complaints', 'v'));
                $stmt->bind_param("i", $to);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$u) { echo json_encode(['success' => false, 'message' => 'Target user not found']); exit(); }

                // increment escalation level + set reason + escalated_to + at
                $stmt = $conn->prepare("UPDATE complaints
                                        SET escalated_to_user_id = ?, escalated_at = NOW(),
                                            escalation_level = escalation_level + 1,
                                            escalation_reason = ?
                                        WHERE id = ?");
                $stmt->bind_param("isi", $to, $reason, $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Complaint Escalated', "Ticket {$row['ticket_number']} escalated to {$u['name']}");

                    // notify escalation target
                    createNotification($to, 'Ticket Escalated to You', "{$row['ticket_number']} — {$row['subject']}", 'warning', "complaints.php?id=$id");
                    // notify current assignee
                    if ($row['assigned_to'] && (int)$row['assigned_to'] !== $user_id && (int)$row['assigned_to'] !== $to) {
                        createNotification((int)$row['assigned_to'], 'Ticket Escalated', "{$row['ticket_number']} escalated to {$u['name']}", 'info', "complaints.php?id=$id");
                    }

                    echo json_encode(['success' => true, 'message' => "Escalated to {$u['name']}"]);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to escalate: ' . $err]);
                }
                exit();
            }

            // ── new SLA timestamp + workflow actions ──

            case 'acknowledgeComplaint': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                $stmt = $conn->prepare("SELECT cmp.ticket_number, cmp.assigned_to, cmp.acknowledged_at, cmp.first_response_at, c.area_id FROM complaints cmp INNER JOIN customers c ON c.id = cmp.customer_id WHERE cmp.id = ?");
                $stmt->bind_param("i", $id); $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$r) { echo json_encode(['success'=>false,'message'=>'Ticket not found']); exit(); }
                if ($is_collector && (!areaAllowed($r['area_id']) || (int)$r['assigned_to'] !== $user_id)) {
                    echo json_encode(['success'=>false,'message'=>'Access denied']); exit();
                }
                // set acknowledged_at + first_response_at if first
                $set_first = empty($r['first_response_at']);
                if ($set_first) {
                    $stmt = $conn->prepare("UPDATE complaints SET acknowledged_at = NOW(), first_response_at = NOW(), first_response_user_id = ? WHERE id = ?");
                    $stmt->bind_param("ii", $user_id, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE complaints SET acknowledged_at = NOW() WHERE id = ?");
                    $stmt->bind_param("i", $id);
                }
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Complaint Acknowledged', "Ticket {$r['ticket_number']} acknowledged");
                    echo json_encode(['success'=>true, 'message'=>'Ticket acknowledged']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                }
                exit();
            }

            case 'markArrived':
            case 'markStarted':
            case 'markCompleted': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                $action = $_GET['action'];
                $col = $action === 'markArrived' ? 'arrived_onsite_at'
                     : ($action === 'markStarted' ? 'work_started_at' : 'work_completed_at');

                $stmt = $conn->prepare("SELECT cmp.ticket_number, cmp.assigned_to, cmp.expected_resolution_date, c.area_id FROM complaints cmp INNER JOIN customers c ON c.id = cmp.customer_id WHERE cmp.id = ?");
                $stmt->bind_param("i", $id); $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$r) { echo json_encode(['success'=>false,'message'=>'Ticket not found']); exit(); }
                if ($is_collector && (!areaAllowed($r['area_id']) || (int)$r['assigned_to'] !== $user_id)) {
                    echo json_encode(['success'=>false,'message'=>'Access denied']); exit();
                }

                // build SQL — for markCompleted also recompute SLA
                if ($action === 'markCompleted') {
                    $sla_extra = '';
                    if (!empty($r['expected_resolution_date'])) {
                        $sla_extra = ", sla_breach_flag = (NOW() > expected_resolution_date),
                                       sla_breach_minutes = GREATEST(0, TIMESTAMPDIFF(MINUTE, expected_resolution_date, NOW()))";
                    }
                    $sql = "UPDATE complaints SET work_completed_at = NOW() $sla_extra WHERE id = ?";
                } else {
                    $sql = "UPDATE complaints SET $col = NOW() WHERE id = ?";
                }
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Ticket '.$action, "Ticket {$r['ticket_number']}: $col set");
                    echo json_encode(['success'=>true,'message'=>ucwords(str_replace('_',' ',$col)).' recorded']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                }
                exit();
            }

            case 'resolveComplaint': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }

                $rcat   = $_POST['resolution_category'] ?? '';
                $rsumm  = trim($_POST['resolution_summary'] ?? '');
                $parts  = trim($_POST['parts_replaced_json'] ?? '');
                $cost   = (float)($_POST['cost_to_resolve'] ?? 0);
                $charge = !empty($_POST['chargeable_to_customer']) && $_POST['chargeable_to_customer'] !== '0' ? 1 : 0;
                $cAmt   = (float)($_POST['customer_charge_amount'] ?? 0);
                if (!$charge) $cAmt = 0;

                $allowed_rcat = ['cable_cut','equipment_fault','config','outage','billing','customer_error','signal_low','olt_issue','no_fault_found','other',''];
                if (!in_array($rcat, $allowed_rcat, true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid resolution category']); exit();
                }
                if ($rsumm === '') {
                    echo json_encode(['success'=>false,'message'=>'Resolution summary is required']); exit();
                }

                $stmt = $conn->prepare("SELECT cmp.ticket_number, cmp.assigned_to, cmp.expected_resolution_date, cmp.resolution, c.area_id FROM complaints cmp INNER JOIN customers c ON c.id = cmp.customer_id WHERE cmp.id = ?");
                $stmt->bind_param("i", $id); $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$r) { echo json_encode(['success'=>false,'message'=>'Ticket not found']); exit(); }
                if ($is_collector && (!areaAllowed($r['area_id']) || (int)$r['assigned_to'] !== $user_id)) {
                    echo json_encode(['success'=>false,'message'=>'Access denied']); exit();
                }

                // validate parts json — accept either valid JSON or convert text/lines into JSON array
                $parts_clean = '';
                if ($parts !== '') {
                    $decoded = json_decode($parts, true);
                    if (is_array($decoded)) {
                        $parts_clean = json_encode($decoded);
                    } else {
                        // split by newlines/commas, trim, json-encode
                        $items = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $parts)));
                        $parts_clean = json_encode(array_values($items));
                    }
                }

                $sla_extra = '';
                if (!empty($r['expected_resolution_date'])) {
                    $sla_extra = ", sla_breach_flag = (NOW() > expected_resolution_date),
                                   sla_breach_minutes = GREATEST(0, TIMESTAMPDIFF(MINUTE, expected_resolution_date, NOW()))";
                }

                // resolution column is the legacy text — fill with summary so old code still works
                $sql = "UPDATE complaints SET
                            status = 'resolved',
                            resolution_category = ?,
                            resolution_summary  = ?,
                            resolution          = ?,
                            parts_replaced_json = ?,
                            cost_to_resolve     = ?,
                            chargeable_to_customer = ?,
                            customer_charge_amount = ?,
                            work_completed_at   = COALESCE(work_completed_at, NOW()),
                            resolved_at         = COALESCE(resolved_at, NOW()),
                            resolution_time_minutes = COALESCE(resolution_time_minutes, TIMESTAMPDIFF(MINUTE, created_at, NOW()))
                            $sla_extra
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                // ssss d i d i  -> 8 vars
                // rcat(s) rsumm(s) resolution(s) parts(s) cost(d) charge(i) cAmt(d) id(i)
                $types = "ssssdidi";
                $stmt->bind_param($types, $rcat, $rsumm, $rsumm, $parts_clean, $cost, $charge, $cAmt, $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Complaint Resolved', "Ticket {$r['ticket_number']} resolved (cat=$rcat, cost=$cost, charge=$cAmt)");

                    // optional invoice creation if chargeable + amount > 0 + invoices table exists
                    $invoice_id = null;
                    if ($charge && $cAmt > 0) {
                        try {
                            $exist = $conn->query("SHOW TABLES LIKE 'invoices'");
                            if ($exist && $exist->num_rows > 0) {
                                // insert simple invoice line; pull customer_id
                                $cidStmt = $conn->prepare("SELECT customer_id FROM complaints WHERE id = ?");
                                $cidStmt->bind_param("i", $id);
                                $cidStmt->execute();
                                $cidRow = $cidStmt->get_result()->fetch_assoc();
                                $cidStmt->close();
                                if ($cidRow) {
                                    $cid = (int)$cidRow['customer_id'];
                                    // best-effort minimal invoice — caller can fill in details later
                                    $inv_no = 'CMP-INV-' . $id . '-' . time();
                                    $note   = "Complaint #{$r['ticket_number']} resolution charge";
                                    $iSt = $conn->prepare("INSERT INTO invoices (customer_id, invoice_number, total_amount, balance_due, status, notes, created_at) VALUES (?, ?, ?, ?, 'unpaid', ?, NOW())");
                                    if ($iSt) {
                                        $iSt->bind_param("isdds", $cid, $inv_no, $cAmt, $cAmt, $note);
                                        if ($iSt->execute()) {
                                            $invoice_id = $iSt->insert_id;
                                            $iSt->close();
                                            $u2 = $conn->prepare("UPDATE complaints SET customer_invoice_id = ? WHERE id = ?");
                                            $u2->bind_param("ii", $invoice_id, $id);
                                            $u2->execute();
                                            $u2->close();
                                        } else {
                                            $iSt->close();
                                        }
                                    }
                                }
                            }
                        } catch (Exception $ie) {
                            // soft-fail invoice creation, don't fail the resolve
                            error_log('Invoice create on complaint resolve failed: ' . $ie->getMessage());
                        }
                    }

                    echo json_encode(['success'=>true, 'message'=>'Ticket resolved', 'invoice_id'=>$invoice_id]);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                }
                exit();
            }

            case 'reopenComplaint': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $id = (int)($_POST['id'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                if ($reason === '') { echo json_encode(['success'=>false,'message'=>'Reason is required']); exit(); }
                if (strlen($reason) > 255) $reason = substr($reason, 0, 255);

                $stmt = $conn->prepare("SELECT cmp.ticket_number, cmp.status, cmp.assigned_to, c.area_id FROM complaints cmp INNER JOIN customers c ON c.id = cmp.customer_id WHERE cmp.id = ?");
                $stmt->bind_param("i", $id); $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$r) { echo json_encode(['success'=>false,'message'=>'Ticket not found']); exit(); }
                if (!$is_admin_lvl) {
                    if (!($is_collector && areaAllowed($r['area_id']) && (int)$r['assigned_to'] === $user_id)) {
                        echo json_encode(['success'=>false,'message'=>'Access denied']); exit();
                    }
                }
                if (!in_array($r['status'], ['resolved','closed'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Only resolved/closed tickets can be reopened']); exit();
                }

                $sql = "UPDATE complaints SET
                            status = 'in_progress',
                            reopen_count = reopen_count + 1,
                            reopened_count = reopened_count + 1,
                            reopened_reason = ?,
                            resolved_at = NULL,
                            resolution_time_minutes = NULL
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $reason, $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Complaint Reopened', "Ticket {$r['ticket_number']}: $reason");
                    createNotificationForAdmins('Ticket Reopened', "{$r['ticket_number']} reopened: $reason", 'warning', "complaints.php?id=$id");
                    echo json_encode(['success'=>true,'message'=>'Ticket reopened']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                }
                exit();
            }

            case 'submitCsat': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                $id     = (int)($_POST['id'] ?? 0);
                $rating = (int)($_POST['rating'] ?? 0);
                $fb     = trim($_POST['feedback'] ?? '');
                if ($id <= 0)             { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit(); }
                if ($rating < 1 || $rating > 5) { echo json_encode(['success'=>false,'message'=>'Rating must be 1-5']); exit(); }

                $stmt = $conn->prepare("SELECT cmp.ticket_number, cmp.status, c.area_id FROM complaints cmp INNER JOIN customers c ON c.id = cmp.customer_id WHERE cmp.id = ?");
                $stmt->bind_param("i", $id); $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$r) { echo json_encode(['success'=>false,'message'=>'Ticket not found']); exit(); }
                if ($is_collector && !areaAllowed($r['area_id'])) {
                    echo json_encode(['success'=>false,'message'=>'Access denied']); exit();
                }
                if (!in_array($r['status'], ['resolved','closed'], true)) {
                    echo json_encode(['success'=>false,'message'=>'CSAT can only be collected after resolution']); exit();
                }

                $sql = "UPDATE complaints SET
                            customer_satisfaction_rating = ?,
                            customer_feedback_text       = ?,
                            customer_rating              = ?,
                            customer_feedback            = ?,
                            feedback_collected_at        = NOW()
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                // i s i s i
                $stmt->bind_param("isisi", $rating, $fb, $rating, $fb, $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'CSAT Submitted', "Ticket {$r['ticket_number']}: $rating/5");
                    echo json_encode(['success'=>true,'message'=>'Feedback recorded']);
                } else {
                    $err = $stmt->error; $stmt->close();
                    echo json_encode(['success'=>false,'message'=>'Failed: '.$err]);
                }
                exit();
            }

            case 'getOpenParents': {
                // open complaints that could be a parent (mass outage)
                $sql = "SELECT cmp.id, cmp.ticket_number, cmp.subject FROM complaints cmp WHERE cmp.is_mass_outage = 1 AND cmp.status IN ('open','in_progress','on_hold') ORDER BY cmp.id DESC LIMIT 200";
                $res = $conn->query($sql);
                $rows = [];
                while ($r = $res->fetch_assoc()) $rows[] = ['id'=>(int)$r['id'], 'ticket_number'=>$r['ticket_number'], 'subject'=>$r['subject']];
                echo json_encode(['success'=>true, 'data'=>$rows]);
                exit();
            }

            case 'getAttachments': {
                $cid = (int)($_GET['complaint_id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }
                // collector area scope
                if ($is_collector) {
                    $stmt = $conn->prepare("SELECT c.area_id FROM complaints cmp INNER JOIN customers c ON c.id = cmp.customer_id WHERE cmp.id = ?");
                    $stmt->bind_param("i", $cid);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$r || !areaAllowed($r['area_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Access denied']); exit();
                    }
                }
                echo json_encode(['success' => true, 'data' => fetchAttachments($conn, $cid)]);
                exit();
            }

            case 'uploadAttachment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit();
                }
                $cid = (int)($_POST['complaint_id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid complaint id']); exit(); }

                if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']); exit();
                }

                // verify complaint + area scope for collector
                $stmt = $conn->prepare("SELECT cmp.ticket_number, cmp.assigned_to, c.area_id FROM complaints cmp INNER JOIN customers c ON c.id = cmp.customer_id WHERE cmp.id = ?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$r) { echo json_encode(['success' => false, 'message' => 'Ticket not found']); exit(); }
                if ($is_collector) {
                    if (!areaAllowed($r['area_id'])) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                    if ((int)$r['assigned_to'] !== $user_id) { echo json_encode(['success' => false, 'message' => 'Only the assigned collector can upload']); exit(); }
                }

                $f = $_FILES['file'];
                if ($f['size'] > CMP_MAX_BYTES) {
                    echo json_encode(['success' => false, 'message' => 'File too large (max 5MB)']); exit();
                }
                $orig = $f['name'];
                $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, $CMP_ALLOWED_EXT, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: jpg/jpeg/png/webp/pdf']); exit();
                }

                // mime sniff
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $f['tmp_name']);
                finfo_close($finfo);
                if (!in_array($mime, $CMP_ALLOWED_MIME, true)) {
                    echo json_encode(['success' => false, 'message' => 'File MIME mismatch']); exit();
                }

                if (!is_dir(CMP_UPLOAD_DIR)) {
                    @mkdir(CMP_UPLOAD_DIR, 0755, true);
                }

                $fname = "comp_{$cid}_" . time() . "_" . mt_rand(1000,9999) . ".$ext";
                $dest  = CMP_UPLOAD_DIR . $fname;
                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to save file']); exit();
                }

                $size = (int)$f['size'];
                $stmt = $conn->prepare("INSERT INTO complaint_attachments (complaint_id, file_path, original_name, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issii", $cid, $fname, $orig, $size, $user_id);
                if ($stmt->execute()) {
                    $stmt->close();
                    logActivity($user_id, $username, 'Attachment Uploaded', "Ticket {$r['ticket_number']}: $orig");
                    echo json_encode(['success' => true, 'message' => 'File uploaded']);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    @unlink($dest); // rollback
                    echo json_encode(['success' => false, 'message' => 'Failed to record file: ' . $err]);
                }
                exit();
            }

            case 'deleteAttachment': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit();
                }
                $aid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
                if ($aid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }

                $stmt = $conn->prepare("SELECT a.id, a.complaint_id, a.file_path, a.uploaded_by, cmp.ticket_number, c.area_id
                                        FROM complaint_attachments a
                                        INNER JOIN complaints cmp ON cmp.id = a.complaint_id
                                        INNER JOIN customers c ON c.id = cmp.customer_id
                                        WHERE a.id = ?");
                $stmt->bind_param("i", $aid);
                $stmt->execute();
                $a = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$a) { echo json_encode(['success' => false, 'message' => 'Attachment not found']); exit(); }

                // permission: uploader or admin-level
                $is_uploader = ((int)$a['uploaded_by'] === $user_id);
                if (!$is_uploader && !$is_admin_lvl) {
                    echo json_encode(['success' => false, 'message' => 'You can only delete your own uploads']); exit();
                }
                // collector area scope
                if ($is_collector && !areaAllowed($a['area_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']); exit();
                }

                $stmt = $conn->prepare("DELETE FROM complaint_attachments WHERE id = ?");
                $stmt->bind_param("i", $aid);
                if ($stmt->execute()) {
                    $stmt->close();
                    @unlink(CMP_UPLOAD_DIR . $a['file_path']);
                    logActivity($user_id, $username, 'Attachment Deleted', "Ticket {$a['ticket_number']}: {$a['file_path']}");
                    echo json_encode(['success' => true, 'message' => 'Attachment deleted']);
                } else {
                    $err = $stmt->error;
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete: ' . $err]);
                }
                exit();
            }

            case 'deleteComplaint': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit();
                }
                if (!can('complaints', 'd')) {
                    echo json_encode(['success' => false, 'message' => 'Only super admin can delete tickets']); exit();
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit(); }

                $stmt = $conn->prepare("SELECT ticket_number FROM complaints WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) { echo json_encode(['success' => false, 'message' => 'Ticket not found']); exit(); }

                // collect attachments to unlink after FK CASCADE removes rows
                $stmt = $conn->prepare("SELECT file_path FROM complaint_attachments WHERE complaint_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $atts = $stmt->get_result();
                $files = [];
                while ($a = $atts->fetch_assoc()) $files[] = $a['file_path'];
                $stmt->close();

                // hard delete — FK CASCADE wipes attachments
                $stmt = $conn->prepare("DELETE FROM complaints WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    foreach ($files as $fp) @unlink(CMP_UPLOAD_DIR . $fp);
                    logActivity($user_id, $username, 'Complaint Deleted', "Deleted ticket: " . $row['ticket_number']);
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Ticket deleted']);
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete']);
                }
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Complaints.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// detail view detection
$detail_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Complaints - ISP Billing</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <?php include "pwa.php"; ?>
    <link rel="stylesheet" href="<?php echo asset('styles.css'); ?>">
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <?php if ($detail_id > 0): ?>
                    <a href="complaints.php">Complaints</a>
                    <span class="breadcrumb-sep">/</span>
                    <span>Ticket #<?php echo (int)$detail_id; ?></span>
                <?php else: ?>
                    <span>Complaints</span>
                <?php endif; ?>
            </div>
            <div class="header">
                <h1><i class="fas fa-headset"></i> Complaints</h1>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if ($detail_id > 0): ?>
            <!-- ── DETAIL VIEW ── -->
            <div id="detailWrap">
                <div id="detailSkeleton">
                    <div class="detail-grid">
                        <div class="detail-side">
                            <div class="detail-card">
                                <div class="skeleton skeleton-text-large skeleton-w-60 skeleton-mb-md"></div>
                                <div class="skeleton skeleton-text skeleton-w-80"></div>
                                <div class="skeleton skeleton-text skeleton-w-60"></div>
                                <div class="skeleton skeleton-text skeleton-w-80"></div>
                            </div>
                            <div class="detail-card">
                                <div class="skeleton skeleton-text-large skeleton-w-60 skeleton-mb-md"></div>
                                <div class="skeleton skeleton-text skeleton-w-80"></div>
                                <div class="skeleton skeleton-text skeleton-w-60"></div>
                            </div>
                        </div>
                        <div class="detail-card">
                            <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                            <div class="skeleton skeleton-text skeleton-w-100"></div>
                            <div class="skeleton skeleton-text skeleton-w-80"></div>
                        </div>
                    </div>
                </div>

                <div id="detailContent" class="initially-hidden">
                    <div class="data-section">
                        <div class="section-header">
                            <h2 id="detailTitle"><i class="fas fa-ticket"></i> Ticket</h2>
                            <div class="btn-group-inline">
                                <a class="btn btn-secondary" href="complaints.php"><i class="fas fa-arrow-left"></i> Back</a>
                                <?php if ($is_admin_lvl): ?>
                                <button class="btn btn-warning" id="btnEscalate"><i class="fas fa-arrow-up-right-from-square"></i> Escalate</button>
                                <?php endif; ?>
                                <button class="btn btn-primary" onclick="loadDetail()"><i class="fas fa-sync"></i> Refresh</button>
                            </div>
                        </div>

                        <div class="detail-grid">
                            <!-- LEFT: side cards -->
                            <div class="detail-side">
                                <div class="detail-card">
                                    <h3><i class="fas fa-info-circle"></i> Ticket Info</h3>
                                    <div id="ticketInfo"></div>
                                </div>
                                <div class="detail-card">
                                    <h3><i class="fas fa-user"></i> Customer</h3>
                                    <div id="customerInfo"></div>
                                </div>
                                <div class="detail-card initially-hidden" id="escalationCard">
                                    <h3><i class="fas fa-arrow-up-right-from-square"></i> Escalation</h3>
                                    <div id="escalationInfo"></div>
                                </div>
                                <div class="detail-card initially-hidden" id="feedbackCard">
                                    <h3><i class="fas fa-star"></i> Customer Feedback</h3>
                                    <div id="feedbackInfo"></div>
                                </div>
                            </div>

                            <!-- RIGHT: thread + update form + attachments -->
                            <div>
                                <div class="detail-card">
                                    <h3><i class="fas fa-comments"></i> Thread</h3>
                                    <div class="thread" id="threadBox"></div>
                                </div>

                                <!-- mass outage banner -->
                                <div class="mass-outage-banner initially-hidden" id="massOutageBanner">
                                    <i class="fas fa-tower-broadcast"></i> Mass outage ticket — <span id="massOutageInfo"></span>
                                </div>

                                <!-- SLA timestamp action buttons -->
                                <div class="detail-card initially-hidden" id="slaActionsCard" style="margin-top:16px">
                                    <h3><i class="fas fa-stopwatch"></i> SLA Timeline</h3>
                                    <div id="slaTimeline"></div>
                                    <div class="ts-action-row" id="slaActionRow"></div>
                                </div>

                                <!-- Resolve form (collapsed by default, shown via button) -->
                                <div class="detail-card initially-hidden" id="resolveCard" style="margin-top:16px">
                                    <h3><i class="fas fa-clipboard-check"></i> Resolve Ticket</h3>
                                    <form id="resolveForm">
                                        <input type="hidden" id="rv_id" name="id">
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label><i class="fas fa-tags"></i> Resolution Category *</label>
                                                <select id="rv_category" name="resolution_category" required>
                                                    <option value="">— Pick —</option>
                                                    <option value="cable_cut">Cable Cut</option>
                                                    <option value="equipment_fault">Equipment Fault</option>
                                                    <option value="config">Config</option>
                                                    <option value="outage">Outage</option>
                                                    <option value="billing">Billing</option>
                                                    <option value="customer_error">Customer Error</option>
                                                    <option value="signal_low">Low Signal</option>
                                                    <option value="olt_issue">OLT Issue</option>
                                                    <option value="no_fault_found">No Fault Found</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label><i class="fas fa-coins"></i> Cost to Resolve</label>
                                                <input type="number" step="0.01" min="0" id="rv_cost" name="cost_to_resolve" value="0">
                                            </div>
                                            <div class="form-group full-row">
                                                <label><i class="fas fa-align-left"></i> Resolution Summary *</label>
                                                <textarea id="rv_summary" name="resolution_summary" rows="3" required placeholder="What was done to fix the issue?"></textarea>
                                            </div>
                                            <div class="form-group full-row">
                                                <label><i class="fas fa-screwdriver-wrench"></i> Parts Replaced <small class="muted-dash">(one per line or JSON array)</small></label>
                                                <textarea id="rv_parts" name="parts_replaced_json" rows="2" placeholder="ONU&#10;Patch cord 5m"></textarea>
                                            </div>
                                            <div class="form-group full-row">
                                                <label class="form-toggle-row">
                                                    <span><i class="fas fa-file-invoice-dollar"></i> Chargeable to Customer?</span>
                                                    <span class="toggle-switch">
                                                        <input type="checkbox" id="rv_chargeable" name="chargeable_to_customer" class="toggle-input" value="1">
                                                        <label for="rv_chargeable" class="toggle-label"><span class="toggle-slider"></span></label>
                                                    </span>
                                                </label>
                                            </div>
                                            <div class="form-group rv-charge-only initially-hidden">
                                                <label><i class="fas fa-money-bill-wave"></i> Charge Amount</label>
                                                <input type="number" step="0.01" min="0" id="rv_amount" name="customer_charge_amount" value="0">
                                            </div>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-success"><i class="fas fa-circle-check"></i> Mark Resolved</button>
                                            <button type="button" class="btn btn-secondary" onclick="$('#resolveCard').addClass('initially-hidden')"><i class="fas fa-times"></i> Cancel</button>
                                        </div>
                                    </form>
                                </div>

                                <!-- CSAT card (post-resolution) -->
                                <div class="detail-card initially-hidden" id="csatCard" style="margin-top:16px">
                                    <h3><i class="fas fa-star"></i> Customer Satisfaction</h3>
                                    <form id="csatForm">
                                        <input type="hidden" id="cs_id" name="id">
                                        <div class="form-group">
                                            <label><i class="fas fa-star"></i> Rating (1-5)</label>
                                            <div class="star-rating-input" id="csStars" data-rating="0">
                                                <i class="fas fa-star" data-v="1"></i>
                                                <i class="fas fa-star" data-v="2"></i>
                                                <i class="fas fa-star" data-v="3"></i>
                                                <i class="fas fa-star" data-v="4"></i>
                                                <i class="fas fa-star" data-v="5"></i>
                                            </div>
                                            <input type="hidden" id="cs_rating" name="rating" value="0">
                                        </div>
                                        <div class="form-group full-row">
                                            <label><i class="fas fa-comment-dots"></i> Customer Feedback</label>
                                            <textarea id="cs_feedback" name="feedback" rows="3" placeholder="Any feedback from the customer..."></textarea>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Feedback</button>
                                        </div>
                                    </form>
                                </div>

                                <div class="detail-card initially-hidden" id="updateFormCard" style="margin-top:16px">
                                    <h3><i class="fas fa-pen-to-square"></i> Update Ticket</h3>
                                    <form id="updateForm">
                                        <input type="hidden" id="upd_id" name="id">
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label><i class="fas fa-toggle-on"></i> Status *</label>
                                                <select id="upd_status" name="status" required>
                                                    <option value="open">Open</option>
                                                    <option value="in_progress">In Progress</option>
                                                    <option value="on_hold">On Hold</option>
                                                    <option value="resolved">Resolved</option>
                                                    <option value="closed">Closed</option>
                                                </select>
                                            </div>
                                            <?php if ($is_admin_lvl): ?>
                                            <div class="form-group">
                                                <label><i class="fas fa-flag"></i> Priority *</label>
                                                <select id="upd_priority" name="priority" required>
                                                    <option value="low">Low</option>
                                                    <option value="medium">Medium</option>
                                                    <option value="high">High</option>
                                                    <option value="urgent">Urgent</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label><i class="fas fa-tags"></i> Category *</label>
                                                <select id="upd_category" name="category" required>
                                                    <option value="speed">Speed</option>
                                                    <option value="connectivity">Connectivity</option>
                                                    <option value="billing">Billing</option>
                                                    <option value="installation">Installation</option>
                                                    <option value="equipment">Equipment</option>
                                                    <option value="outage">Outage</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label><i class="fas fa-inbox"></i> Source *</label>
                                                <select id="upd_source" name="source" required>
                                                    <option value="call">Call</option>
                                                    <option value="whatsapp">WhatsApp</option>
                                                    <option value="email">Email</option>
                                                    <option value="walk_in">Walk-in</option>
                                                    <option value="portal">Portal</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label><i class="fas fa-user-gear"></i> Assigned To</label>
                                                <select id="upd_assigned" name="assigned_to">
                                                    <option value="">Unassigned</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label><i class="fas fa-calendar-day"></i> Expected Resolution</label>
                                                <input type="datetime-local" id="upd_expected" name="expected_resolution_date">
                                            </div>
                                            <?php endif; ?>
                                            <div class="form-group" style="grid-column:1/-1">
                                                <label><i class="fas fa-clipboard-check"></i> Resolution <span id="resolReq" class="initially-hidden">*</span></label>
                                                <textarea id="upd_resolution" name="resolution" rows="4" placeholder="Required when resolving or closing..."></textarea>
                                            </div>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Ticket</button>
                                        </div>
                                    </form>
                                </div>

                                <div class="detail-card" style="margin-top:16px">
                                    <h3><i class="fas fa-paperclip"></i> Attachments</h3>
                                    <form id="uploadForm" style="margin-bottom:14px">
                                        <input type="hidden" name="complaint_id" value="<?php echo (int)$detail_id; ?>">
                                        <div class="form-grid">
                                            <div class="form-group" style="grid-column:1/-1">
                                                <label><i class="fas fa-file-arrow-up"></i> Add file (jpg/png/webp/pdf, max 5MB)</label>
                                                <input type="file" id="attFile" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" required>
                                            </div>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Upload</button>
                                        </div>
                                    </form>
                                    <div id="attachmentsList"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- ── LIST VIEW ── -->
            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-ticket"></i> Support Tickets</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadComplaints()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('complaints', 'a')): ?>
                        <button class="btn btn-secondary" onclick="rsImport.pick()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-secondary" onclick="rsImport.template()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> New Ticket
                        </button>
                    </div>
                </div>

                <!-- chevron pipeline (status quick-filter) -->
                <div class="chevron-pipeline initially-hidden" id="chevronPipeline" role="tablist" aria-label="Filter complaints by status">
                    <div class="chevron-tab cv-all active"        data-status="all"         role="tab" tabindex="0">
                        <i class="fas fa-list"></i> All <span class="cv-count" id="cv-c-all">0</span>
                    </div>
                    <div class="chevron-tab cv-open"              data-status="open"        role="tab" tabindex="0">
                        <i class="fas fa-folder-open"></i> Open <span class="cv-count" id="cv-c-open">0</span>
                    </div>
                    <div class="chevron-tab cv-in-progress"       data-status="in_progress" role="tab" tabindex="0">
                        <i class="fas fa-spinner"></i> In Progress <span class="cv-count" id="cv-c-in_progress">0</span>
                    </div>
                    <div class="chevron-tab cv-on-hold"           data-status="on_hold"     role="tab" tabindex="0">
                        <i class="fas fa-pause"></i> On Hold <span class="cv-count" id="cv-c-on_hold">0</span>
                    </div>
                    <div class="chevron-tab cv-resolved"          data-status="resolved"    role="tab" tabindex="0">
                        <i class="fas fa-circle-check"></i> Resolved <span class="cv-count" id="cv-c-resolved">0</span>
                    </div>
                    <div class="chevron-tab cv-closed"            data-status="closed"      role="tab" tabindex="0">
                        <i class="fas fa-lock"></i> Closed <span class="cv-count" id="cv-c-closed">0</span>
                    </div>
                </div>

                <div class="filters-section initially-hidden" id="filtersSection">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-times-circle"></i> Clear All
                        </button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-flag"></i> Priority</label>
                            <select id="filterPriority">
                                <option value="">All Priorities</option>
                                <option value="urgent">Urgent</option>
                                <option value="high">High</option>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-tags"></i> Category</label>
                            <select id="filterCategory">
                                <option value="">All Categories</option>
                                <option value="speed">Speed</option>
                                <option value="connectivity">Connectivity</option>
                                <option value="billing">Billing</option>
                                <option value="installation">Installation</option>
                                <option value="equipment">Equipment</option>
                                <option value="outage">Outage</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-inbox"></i> Source</label>
                            <select id="filterSource">
                                <option value="">All Sources</option>
                                <option value="call">Call</option>
                                <option value="whatsapp">WhatsApp</option>
                                <option value="email">Email</option>
                                <option value="walk_in">Walk-in</option>
                                <option value="portal">Portal</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-user-gear"></i> Assigned To</label>
                            <select id="filterAssigned">
                                <option value="">All Assignees</option>
                                <option value="unassigned">Unassigned</option>
                            </select>
                        </div>
                        <?php if ($is_admin_lvl): ?>
                        <div class="filter-group">
                            <label><i class="fas fa-map-marked-alt"></i> Area</label>
                            <select id="filterArea">
                                <option value="">All Areas</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" id="filterSearch" placeholder="Ticket / customer / subject">
                        </div>
                    </div>
                </div>

                <!-- skeleton -->
                <div id="skeletonWrap">
                    <div class="skeleton skeleton-text-large skeleton-w-60 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-100 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-100 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-80"></div>
                </div>

                <!-- empty -->
                <div class="empty-state initially-hidden" id="emptyState">
                    <div class="empty-state-icon"><i class="fas fa-headset"></i></div>
                    <div class="empty-state-title">No complaints — all clear.</div>
                    <div class="empty-state-text">All quiet on the support front.</div>
                </div>

                <!-- table -->
                <div id="tableWrap" class="initially-hidden">
                    <div class="table-responsive">
                        <table id="complaintsTable" class="display table-full-width"></table>
                    </div>
                </div>
            </div>

            <!-- New Ticket Modal -->
            <div class="modal-overlay" id="ticketModal">
                <div class="modal" onclick="event.stopPropagation()">
                    <div class="modal-header">
                        <h3><i class="fas fa-plus"></i> New Ticket</h3>
                        <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="modal-body">
                        <form id="ticketForm">
                            <div class="form-grid">
                                <div class="form-group" style="grid-column:1/-1">
                                    <label><i class="fas fa-user"></i> Customer *</label>
                                    <select id="customer_id" name="customer_id" required></select>
                                </div>
                                <div class="form-group" style="grid-column:1/-1">
                                    <label><i class="fas fa-tag"></i> Subject *</label>
                                    <input type="text" id="subject" name="subject" required maxlength="255" placeholder="Short summary of the issue">
                                </div>
                                <div class="form-group" style="grid-column:1/-1">
                                    <label><i class="fas fa-align-left"></i> Description *</label>
                                    <textarea id="description" name="description" rows="4" required placeholder="Describe the issue in detail..."></textarea>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-tags"></i> Category *</label>
                                    <select id="category" name="category" required>
                                        <option value="speed">Speed</option>
                                        <option value="connectivity">Connectivity</option>
                                        <option value="billing">Billing</option>
                                        <option value="installation">Installation</option>
                                        <option value="equipment">Equipment</option>
                                        <option value="outage">Outage</option>
                                        <option value="other" selected>Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-inbox"></i> Source *</label>
                                    <select id="source" name="source" required>
                                        <option value="call" selected>Call</option>
                                        <option value="whatsapp">WhatsApp</option>
                                        <option value="email">Email</option>
                                        <option value="walk_in">Walk-in</option>
                                        <option value="portal">Portal</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-flag"></i> Priority *</label>
                                    <select id="priority" name="priority" required>
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-triangle-exclamation"></i> Severity</label>
                                    <select id="severity" name="severity">
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-headset"></i> Complaint Channel</label>
                                    <select id="complaint_source" name="complaint_source">
                                        <option value="call" selected>Call</option>
                                        <option value="whatsapp">WhatsApp</option>
                                        <option value="portal">Portal</option>
                                        <option value="walk_in">Walk-in</option>
                                        <option value="social">Social Media</option>
                                        <option value="app">App</option>
                                        <option value="email">Email</option>
                                    </select>
                                </div>
                                <div class="form-group full-row">
                                    <label class="form-toggle-row">
                                        <span><i class="fas fa-tower-broadcast"></i> Mass Outage</span>
                                        <span class="toggle-switch">
                                            <input type="checkbox" id="is_mass_outage" name="is_mass_outage" class="toggle-input" value="1">
                                            <label for="is_mass_outage" class="toggle-label"><span class="toggle-slider"></span></label>
                                        </span>
                                    </label>
                                </div>
                                <div class="form-group mass-outage-only initially-hidden">
                                    <label><i class="fas fa-users"></i> Affected Users Count</label>
                                    <input type="number" min="0" id="affected_users_count" name="affected_users_count" placeholder="e.g. 50">
                                </div>
                                <div class="form-group mass-outage-only initially-hidden">
                                    <label><i class="fas fa-map-marked-alt"></i> Area Affected</label>
                                    <select id="area_id_affected" name="area_id_affected">
                                        <option value="">— None —</option>
                                    </select>
                                </div>
                                <div class="form-group mass-outage-only initially-hidden">
                                    <label><i class="fas fa-link"></i> Parent Outage Ticket</label>
                                    <select id="parent_complaint_id" name="parent_complaint_id">
                                        <option value="">— None —</option>
                                    </select>
                                </div>
                                <div class="form-group full-row">
                                    <label><i class="fas fa-tags"></i> Tags <small class="muted-dash">(comma-separated)</small></label>
                                    <input type="text" id="tags" name="tags" maxlength="255" placeholder="e.g. vip, recurring, weekend">
                                </div>
                                <?php if ($is_admin_lvl): ?>
                                <div class="form-group">
                                    <label><i class="fas fa-user-gear"></i> Assign To</label>
                                    <select id="assigned_to" name="assigned_to">
                                        <option value="">Unassigned</option>
                                    </select>
                                </div>
                                <div class="form-group full-row">
                                    <label><i class="fas fa-calendar-day"></i> Expected Resolution</label>
                                    <input type="datetime-local" id="expected_resolution_date" name="expected_resolution_date">
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create Ticket</button>
                                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?php echo asset('rs_popup.js'); ?>"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.7.0/jspdf.plugin.autotable.min.js"></script>
    <script src="<?php echo asset('searchable-dropdown.js'); ?>"></script>

    <script>
    const DETAIL_ID    = <?php echo (int)$detail_id; ?>;
    const IS_ADMIN_LVL = <?php echo $is_admin_lvl ? 'true' : 'false'; ?>;
    const IS_COLLECTOR = <?php echo $is_collector ? 'true' : 'false'; ?>;
    const CAN_DELETE   = <?php echo $can_delete   ? 'true' : 'false'; ?>;
    const ME_ID        = <?php echo (int)$user_id; ?>;
    const MAX_BYTES    = 5 * 1024 * 1024;
    const ALLOWED_EXT  = ['jpg','jpeg','png','webp','pdf'];

    let complaintsTable;
    let staffList    = [];
    let areasList    = [];
    let customerList = [];

    function escapeHtml(s) { return $('<div/>').text(s == null ? '' : s).html(); }
    function trunc(s, n) { s = s || ''; return s.length > n ? s.substring(0, n) + '…' : s; }
    function fmtDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        if (isNaN(d)) return '—';
        return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    function priorityBadge(p) { return `<span class="priority-badge ${p}">${p}</span>`; }
    function statusBadge(s)   { return `<span class="cmp-status ${s}">${s.replace('_',' ')}</span>`; }
    function catBadge(c)      { return c ? `<span class="cmp-cat ${c}">${c}</span>` : ''; }
    function srcBadge(s)      { return s ? `<span class="cmp-src ${s}">${s.replace('_',' ')}</span>` : ''; }
    function fmtMinutes(m) {
        if (m == null) return '—';
        m = parseInt(m, 10);
        if (m < 60) return m + 'm';
        const h = Math.floor(m / 60), mm = m % 60;
        if (h < 24) return h + 'h ' + mm + 'm';
        const d = Math.floor(h / 24), hh = h % 24;
        return d + 'd ' + hh + 'h ' + mm + 'm';
    }
    function fmtBytes(b) {
        if (b == null) return '—';
        b = parseInt(b, 10);
        if (b < 1024) return b + ' B';
        if (b < 1024*1024) return (b/1024).toFixed(1) + ' KB';
        return (b/1024/1024).toFixed(2) + ' MB';
    }
    function ratingStars(r) {
        if (!r) return '—';
        let html = '';
        for (let i = 1; i <= 5; i++) {
            html += i <= r
                ? '<i class="fas fa-star" style="color:#f59e0b"></i> '
                : '<i class="far fa-star" style="color:#cbd5e1"></i> ';
        }
        return html + ` <strong>${r}/5</strong>`;
    }

    $(document).ready(function() {
        if (DETAIL_ID > 0) {
            initDetail();
        } else {
            initList();
        }
    });

    // ────────────────────────────────────────
    // LIST
    // ────────────────────────────────────────
    let chevronStatus = 'all';

    function initList() {
        // status filter is now chevron pipeline above the table
        new SearchableDropdown({ selectId: 'filterPriority', placeholder: 'All Priorities', icon: 'fas fa-flag' });
        new SearchableDropdown({ selectId: 'filterCategory', placeholder: 'All Categories', icon: 'fas fa-tags' });
        new SearchableDropdown({ selectId: 'filterSource',   placeholder: 'All Sources',    icon: 'fas fa-inbox' });
        new SearchableDropdown({ selectId: 'filterAssigned', placeholder: 'All Assignees',  icon: 'fas fa-user-gear' });
        if (IS_ADMIN_LVL) {
            new SearchableDropdown({ selectId: 'filterArea', placeholder: 'All Areas', icon: 'fas fa-map-marked-alt' });
        }
        new SearchableDropdown({ selectId: 'priority', placeholder: 'Priority...', icon: 'fas fa-flag', allowClear: false });
        new SearchableDropdown({ selectId: 'category', placeholder: 'Category...', icon: 'fas fa-tags', allowClear: false });
        new SearchableDropdown({ selectId: 'source',   placeholder: 'Source...',   icon: 'fas fa-inbox', allowClear: false });
        new SearchableDropdown({ selectId: 'severity', placeholder: 'Severity...', icon: 'fas fa-triangle-exclamation', allowClear: false });
        new SearchableDropdown({ selectId: 'complaint_source', placeholder: 'Channel...', icon: 'fas fa-headset', allowClear: false });
        new SearchableDropdown({ selectId: 'area_id_affected', placeholder: 'Pick area...', icon: 'fas fa-map-marked-alt' });
        new SearchableDropdown({ selectId: 'parent_complaint_id', placeholder: 'Pick parent ticket...', icon: 'fas fa-link' });
        if (IS_ADMIN_LVL) {
            new SearchableDropdown({ selectId: 'assigned_to', placeholder: 'Unassigned', icon: 'fas fa-user-gear' });
        }
        new SearchableDropdown({ selectId: 'customer_id', placeholder: 'Pick a customer...', icon: 'fas fa-user', allowClear: false });

        // mass outage toggle — show extra rows
        $('#is_mass_outage').on('change', function() {
            const on = this.checked;
            $('.mass-outage-only').toggleClass('initially-hidden', !on);
        });

        $('#filterPriority,#filterCategory,#filterSource,#filterAssigned,#filterArea').on('change', applyFilters);
        $('#filterSearch').on('input', debounce(applyFilters, 300));

        // chevron pipeline click
        $(document).on('click keydown', '.chevron-tab', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            $('.chevron-tab').removeClass('active');
            $(this).addClass('active');
            chevronStatus = $(this).data('status') || 'all';
            applyFilters();
        });

        $('#ticketForm').on('submit', handleAddSubmit);
        $('#ticketModal').on('click', function(e) { if (e.target === this) closeModal(); });

        // load deps + initial data
        Promise.all([loadStaff(), loadCustomers(), loadAreas(), loadOpenParents()])
            .then(() => {
                loadComplaints();
                // auto-open add form from dashboard quick action (?new=1)
                if (new URLSearchParams(location.search).get('new') === '1' && document.querySelector('button[onclick="openAddModal()"]')) openAddModal();
            });
        rsImport.init({ entity: 'Complaint', onDone: loadComplaints });
    }

    function debounce(fn, ms) {
        let t;
        return function() { clearTimeout(t); t = setTimeout(fn, ms); };
    }

    function loadStaff() {
        return $.getJSON('?action=getStaff').then(resp => {
            if (resp.success) {
                staffList = resp.data;
                // populate filter
                const fa = document.getElementById('filterAssigned');
                staffList.forEach(s => {
                    const o = document.createElement('option');
                    o.value = s.id; o.textContent = s.name;
                    fa.appendChild(o);
                });
                fa._sd && fa._sd.refresh();
                // populate modal
                const ma = document.getElementById('assigned_to');
                if (ma) {
                    staffList.forEach(s => {
                        const o = document.createElement('option');
                        o.value = s.id; o.textContent = s.name;
                        ma.appendChild(o);
                    });
                    ma._sd && ma._sd.refresh();
                }
            }
        });
    }

    function loadCustomers() {
        return $.getJSON('?action=getActiveCustomers').then(resp => {
            if (resp.success) {
                customerList = resp.data;
                const sel = document.getElementById('customer_id');
                sel.innerHTML = '';
                if (!customerList.length) {
                    sel.innerHTML = '<option value="">No active customers</option>';
                } else {
                    customerList.forEach(c => {
                        const o = document.createElement('option');
                        o.value = c.id;
                        o.textContent = `${c.full_name} (${c.customer_code})`;
                        sel.appendChild(o);
                    });
                }
                sel._sd && sel._sd.refresh();
            }
        });
    }

    function loadAreas() {
        return $.getJSON('?action=getAreas').then(resp => {
            if (resp.success) {
                areasList = resp.data;
                // populate filter (admin only)
                const fa = document.getElementById('filterArea');
                if (fa) {
                    areasList.forEach(a => {
                        const o = document.createElement('option');
                        o.value = a.id; o.textContent = a.name;
                        fa.appendChild(o);
                    });
                    fa._sd && fa._sd.refresh();
                }
                // populate area_id_affected (mass-outage section, all roles)
                const ma = document.getElementById('area_id_affected');
                if (ma) {
                    areasList.forEach(a => {
                        const o = document.createElement('option');
                        o.value = a.id; o.textContent = a.name;
                        ma.appendChild(o);
                    });
                    ma._sd && ma._sd.refresh();
                }
            }
        });
    }

    function loadOpenParents() {
        return $.getJSON('?action=getOpenParents').then(resp => {
            if (!resp.success) return;
            const sel = document.getElementById('parent_complaint_id');
            if (!sel) return;
            resp.data.forEach(p => {
                const o = document.createElement('option');
                o.value = p.id; o.textContent = `${p.ticket_number} — ${p.subject}`;
                sel.appendChild(o);
            });
            sel._sd && sel._sd.refresh();
        });
    }

    function applyFilters() { loadComplaints(); }

    function clearFilters() {
        ['filterPriority','filterCategory','filterSource','filterAssigned','filterArea'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = '';
            el._sd && el._sd.refresh();
        });
        document.getElementById('filterSearch').value = '';
        // reset chevron to All
        chevronStatus = 'all';
        $('.chevron-tab').removeClass('active');
        $('.chevron-tab[data-status="all"]').addClass('active');
        loadComplaints();
    }

    function loadComplaints() {
        $('#skeletonWrap').show();
        $('#tableWrap,#emptyState,#filtersSection,#chevronPipeline').hide();

        // status from chevron (sent as filter to server too)
        const params = {
            action:      'getComplaints',
            status:      (chevronStatus && chevronStatus !== 'all') ? chevronStatus : '',
            priority:    document.getElementById('filterPriority').value || '',
            category:    document.getElementById('filterCategory').value || '',
            source:      document.getElementById('filterSource').value || '',
            assigned_to: document.getElementById('filterAssigned').value || '',
            q:           document.getElementById('filterSearch').value || ''
        };
        const fa = document.getElementById('filterArea');
        if (fa) params.area_id = fa.value || '';

        // also fetch ALL counts (no status filter) for chevron
        const countsParams = Object.assign({}, params); countsParams.status = '';

        // run two parallel requests: filtered + counts
        Promise.all([
            $.getJSON('?' + $.param(params)),
            $.getJSON('?' + $.param(countsParams))
        ]).then(([resp, all]) => {
            $('#skeletonWrap').hide();
            $('#filtersSection').show();
            $('#chevronPipeline').show().removeClass('initially-hidden');

            // refresh chevron counts from the unfiltered set (still respects other filters)
            if (all && all.success) refreshChevronCounts(all.data);

            if (!resp.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: resp.message || 'Failed to load' });
                return;
            }
            if (!resp.data.length) {
                $('#emptyState').show().removeClass('initially-hidden');
                $('#tableWrap').hide();
                return;
            }
            $('#emptyState').hide();
            $('#tableWrap').show().removeClass('initially-hidden');
            renderTable(resp.data);
        }).catch(() => {
            $('#skeletonWrap').hide();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
        });
    }

    function refreshChevronCounts(rows) {
        const c = { all: rows.length, open: 0, in_progress: 0, on_hold: 0, resolved: 0, closed: 0 };
        rows.forEach(r => { if (r.status in c) c[r.status]++; });
        Object.keys(c).forEach(k => {
            const el = document.getElementById('cv-c-' + k);
            if (el) el.textContent = c[k];
        });
    }

    function renderTable(data) {
        if (complaintsTable) { complaintsTable.destroy(); $('#complaintsTable').empty(); }
        setTimeout(() => {
            complaintsTable = $('#complaintsTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    {
                        data: 'ticket_number', title: 'Ticket #',
                        render: (d, t, row) => `<a href="complaints.php?id=${row.id}" style="font-weight:600;color:#0074D9">${escapeHtml(d)}</a>`
                    },
                    {
                        data: null, title: 'Customer',
                        render: row => `<div><div style="font-weight:600">${escapeHtml(row.customer_name)}</div><div style="font-size:12px;color:#666">${escapeHtml(row.customer_code)}</div></div>`
                    },
                    {
                        data: 'subject', title: 'Subject',
                        render: d => escapeHtml(trunc(d, 60))
                    },
                    {
                        data: 'category', title: 'Category',
                        render: d => catBadge(d)
                    },
                    {
                        data: 'source', title: 'Source',
                        render: d => srcBadge(d)
                    },
                    {
                        data: 'priority', title: 'Priority',
                        render: (d, t, row) => priorityBadge(d) + (row.is_mass_outage ? ' <span class="tag-pill" title="Mass outage"><i class="fas fa-tower-broadcast"></i> mass</span>' : '')
                    },
                    {
                        data: 'status', title: 'Status',
                        render: (d, t, row) => statusBadge(d) + (row.sla_breach_flag ? ' <span class="sla-breach-badge" title="SLA breached by '+row.sla_breach_minutes+'m">SLA</span>' : '')
                    },
                    {
                        data: 'assigned_name', title: 'Assigned To',
                        render: d => d ? escapeHtml(d) : '<span style="color:#94a3b8">Unassigned</span>'
                    },
                    {
                        data: 'created_at', title: 'Created',
                        render: (d, t, row) => {
                            // sort/filter on raw ISO, display formatted
                            if (t !== 'display') return d || '';
                            return `<span title="${escapeHtml(d || '')}">${escapeHtml(fmtDate(d))}</span>`;
                        }
                    },
                    {
                        data: null, title: 'Actions', orderable: false,
                        render: function(d, t, row) {
                            const canEdit = IS_ADMIN_LVL || (IS_COLLECTOR && row.assigned_to === ME_ID);
                            // view first, then edit, then ⋮ more (delete)
                            const viewBtn = `<a class="action-icon" href="complaints.php?id=${row.id}" title="View"><i class="fas fa-eye"></i></a>`;
                            const editBtn = canEdit
                                ? `<a class="action-icon edit-icon" href="complaints.php?id=${row.id}" title="Edit"><i class="fas fa-edit"></i></a>`
                                : '';
                            const moreItems = CAN_DELETE
                                ? `<button class="act-more-item danger" onclick="deleteTicket(${row.id}, '${escapeHtml(row.ticket_number)}')"><i class="fas fa-trash"></i> Delete</button>`
                                : '';
                            const moreMenu = moreItems
                                ? `<div class="act-more"><button class="act-more-btn" type="button" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button><div class="act-more-menu">${moreItems}</div></div>`
                                : '';
                            return `${viewBtn}${editBtn}${moreMenu}`;
                        }
                    }
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: true,
                ordering: false, // server pre-sorts: urgent first, then created DESC
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv',   text: '<i class="fas fa-file-csv"></i> CSV',   exportOptions: { columns: ':not(:last-child)' } },
                    { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: ':not(:last-child)' }, orientation: 'landscape', pageSize: 'A4' },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':not(:last-child)' } }
                ],
                language: { emptyTable: 'No complaints — all clear.' }
            });
        }, 50);
    }

    // ─── add ticket ───
    function openAddModal() {
        document.getElementById('ticketForm').reset();
        ['priority','category','source','assigned_to','customer_id','severity','complaint_source','area_id_affected','parent_complaint_id'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            if (id === 'priority') el.value = 'medium';
            else if (id === 'category') el.value = 'other';
            else if (id === 'source')   el.value = 'call';
            else if (id === 'severity') el.value = 'medium';
            else if (id === 'complaint_source') el.value = 'call';
            else el.value = '';
            el._sd && el._sd.refresh();
        });
        const exp = document.getElementById('expected_resolution_date');
        if (exp) exp.value = '';
        // reset mass outage toggle + hidden rows
        const mo = document.getElementById('is_mass_outage');
        if (mo) mo.checked = false;
        $('.mass-outage-only').addClass('initially-hidden');
        document.getElementById('ticketModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('ticketModal').classList.remove('active');
    }

    function handleAddSubmit(e) {
        e.preventDefault();
        const fd = new FormData(this);
        Swal.fire({ title: 'Creating ticket...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=addComplaint', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Ticket Created', text: resp.message, timer: 1800, showConfirmButton: false });
                    closeModal();
                    setTimeout(() => loadComplaints(), 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    function deleteTicket(id, ticket) {
        if (!CAN_DELETE) return;
        Swal.fire({
            icon: 'warning', title: 'Delete Ticket?',
            text: `This permanently deletes ${ticket}. This cannot be undone.`,
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteComplaint', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(() => loadComplaints(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    // ────────────────────────────────────────
    // DETAIL
    // ────────────────────────────────────────
    function initDetail() {
        new SearchableDropdown({ selectId: 'upd_status',  placeholder: 'Status...',  icon: 'fas fa-toggle-on', allowClear: false });
        if (IS_ADMIN_LVL) {
            new SearchableDropdown({ selectId: 'upd_priority', placeholder: 'Priority...', icon: 'fas fa-flag', allowClear: false });
            new SearchableDropdown({ selectId: 'upd_category', placeholder: 'Category...', icon: 'fas fa-tags', allowClear: false });
            new SearchableDropdown({ selectId: 'upd_source',   placeholder: 'Source...',   icon: 'fas fa-inbox', allowClear: false });
            new SearchableDropdown({ selectId: 'upd_assigned', placeholder: 'Unassigned',  icon: 'fas fa-user-gear' });
        }

        $('#upd_status').on('change', function() {
            const s = this.value;
            const need = (s === 'resolved' || s === 'closed');
            $('#resolReq').toggleClass('initially-hidden', !need);
            $('#upd_resolution').prop('required', need);
        });

        // resolve form select dropdown
        new SearchableDropdown({ selectId: 'rv_category', placeholder: 'Category...', icon: 'fas fa-tags', allowClear: false });

        $('#updateForm').on('submit', handleUpdateSubmit);
        $('#uploadForm').on('submit', handleUploadSubmit);
        $('#btnEscalate').on('click', openEscalateDialog);
        $('#resolveForm').on('submit', handleResolveSubmit);
        $('#csatForm').on('submit', handleCsatSubmit);

        // CSAT star click + chargeable toggle
        $('#csStars').on('click', 'i', function() {
            const v = parseInt($(this).data('v'), 10);
            const stars = document.getElementById('csStars');
            if (stars.classList.contains('locked')) return;
            stars.dataset.rating = v;
            document.getElementById('cs_rating').value = v;
            paintStars(stars, v);
        });
        $('#rv_chargeable').on('change', function() {
            $('.rv-charge-only').toggleClass('initially-hidden', !this.checked);
        });

        // load staff first if admin (for the assignee dropdown), then detail
        const dep = IS_ADMIN_LVL ? loadStaffForDetail() : Promise.resolve();
        dep.then(() => loadDetail());
    }

    function handleResolveSubmit(e) {
        e.preventDefault();
        const fd = new FormData(this);
        Swal.fire({ title: 'Resolving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=resolveComplaint', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon:'success', title:'Resolved', text: resp.message + (resp.invoice_id ? ' (invoice #'+resp.invoice_id+' created)' : ''), timer: 1800, showConfirmButton: false });
                    setTimeout(loadDetail, 200);
                } else {
                    Swal.fire({ icon:'error', title:'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon:'error', text:'Connection error' }); }
        });
    }

    function handleCsatSubmit(e) {
        e.preventDefault();
        const r = parseInt(document.getElementById('cs_rating').value || 0, 10);
        if (r < 1 || r > 5) { Swal.fire({ icon:'warning', title:'Pick a rating', text:'Click 1-5 stars' }); return; }
        const fd = new FormData(this);
        Swal.fire({ title: 'Submitting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=submitCsat', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) { Swal.fire({ icon:'success', text:resp.message, timer:1500, showConfirmButton:false }); setTimeout(loadDetail, 200); }
                else Swal.fire({ icon:'error', title:'Error', text: resp.message });
            },
            error: function() { Swal.fire({ icon:'error', text:'Connection error' }); }
        });
    }

    function loadStaffForDetail() {
        return $.getJSON('?action=getStaff').then(resp => {
            if (resp.success) {
                staffList = resp.data;
                const sel = document.getElementById('upd_assigned');
                if (!sel) return;
                staffList.forEach(s => {
                    const o = document.createElement('option');
                    o.value = s.id; o.textContent = s.name;
                    sel.appendChild(o);
                });
                sel._sd && sel._sd.refresh();
            }
        });
    }

    function loadDetail() {
        $('#detailSkeleton').show();
        $('#detailContent').hide();
        $.getJSON('?action=getComplaintDetail&id=' + DETAIL_ID, function(resp) {
            $('#detailSkeleton').hide();
            if (!resp.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: resp.message }).then(() => {
                    window.location = 'complaints.php';
                });
                return;
            }
            renderDetail(resp.data);
            $('#detailContent').show().removeClass('initially-hidden');
        }).fail(function() {
            $('#detailSkeleton').hide();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
        });
    }

    function renderDetail(d) {
        document.getElementById('detailTitle').innerHTML =
            `<i class="fas fa-ticket"></i> ${escapeHtml(d.ticket_number)} — ${escapeHtml(trunc(d.subject, 60))}`;

        // ticket info
        const reopenedRow = d.reopened_count > 0
            ? `<div class="detail-row"><span class="detail-row-label">Reopened</span><span class="detail-row-value">${d.reopened_count}x</span></div>`
            : '';
        const closedByRow = d.closed_by_user_name
            ? `<div class="detail-row"><span class="detail-row-label">Closed By</span><span class="detail-row-value">${escapeHtml(d.closed_by_user_name)}</span></div>`
            : '';

        document.getElementById('ticketInfo').innerHTML = `
            <div class="detail-row"><span class="detail-row-label">Ticket #</span><span class="detail-row-value">${escapeHtml(d.ticket_number)}</span></div>
            <div class="detail-row"><span class="detail-row-label">Created</span><span class="detail-row-value" title="${escapeHtml(d.created_at || '')}">${escapeHtml(fmtDate(d.created_at))}</span></div>
            <div class="detail-row"><span class="detail-row-label">Category</span><span class="detail-row-value">${catBadge(d.category)}</span></div>
            <div class="detail-row"><span class="detail-row-label">Source</span><span class="detail-row-value">${srcBadge(d.source)}</span></div>
            <div class="detail-row"><span class="detail-row-label">Priority</span><span class="detail-row-value">${priorityBadge(d.priority)}</span></div>
            <div class="detail-row"><span class="detail-row-label">Status</span><span class="detail-row-value">${statusBadge(d.status)}</span></div>
            <div class="detail-row"><span class="detail-row-label">Assigned To</span><span class="detail-row-value">${d.assigned_name ? escapeHtml(d.assigned_name) : '<span style="color:#94a3b8">Unassigned</span>'}</span></div>
            <div class="detail-row"><span class="detail-row-label">Expected By</span><span class="detail-row-value">${escapeHtml(fmtDate(d.expected_resolution_date))}</span></div>
            <div class="detail-row"><span class="detail-row-label">First Response</span><span class="detail-row-value">${escapeHtml(fmtDate(d.first_response_at))}</span></div>
            ${d.resolved_at ? `<div class="detail-row"><span class="detail-row-label">Resolved</span><span class="detail-row-value">${escapeHtml(fmtDate(d.resolved_at))}</span></div>` : ''}
            ${d.resolution_time_minutes != null ? `<div class="detail-row"><span class="detail-row-label">Resolution Time</span><span class="detail-row-value">${fmtMinutes(d.resolution_time_minutes)}</span></div>` : ''}
            ${closedByRow}
            ${reopenedRow}
        `;

        // customer info
        document.getElementById('customerInfo').innerHTML = `
            <div class="detail-row"><span class="detail-row-label">Code</span><span class="detail-row-value">${escapeHtml(d.customer_code)}</span></div>
            <div class="detail-row"><span class="detail-row-label">Name</span><span class="detail-row-value"><a href="customers.php?id=${d.customer_id}" style="color:#0074D9">${escapeHtml(d.customer_name)}</a></span></div>
            <div class="detail-row"><span class="detail-row-label">Phone</span><span class="detail-row-value">${escapeHtml(d.customer_phone || '—')}</span></div>
            <div class="detail-row"><span class="detail-row-label">Area</span><span class="detail-row-value">${d.area_name ? escapeHtml(d.area_name) : '—'}</span></div>
        `;

        // escalation card
        if (d.escalated_to_user_id) {
            $('#escalationCard').show().removeClass('initially-hidden');
            document.getElementById('escalationInfo').innerHTML = `
                <div class="detail-row"><span class="detail-row-label">Escalated To</span><span class="detail-row-value">${escapeHtml(d.escalated_to_user_name || '—')}</span></div>
                <div class="detail-row"><span class="detail-row-label">When</span><span class="detail-row-value">${escapeHtml(fmtDate(d.escalated_at))}</span></div>
            `;
        } else {
            $('#escalationCard').hide();
        }

        // feedback card — only if status resolved/closed AND rating or feedback present
        const showFeedback = (d.status === 'resolved' || d.status === 'closed')
                             && (d.customer_rating != null || (d.customer_feedback && d.customer_feedback.trim() !== ''));
        if (showFeedback) {
            $('#feedbackCard').show().removeClass('initially-hidden');
            document.getElementById('feedbackInfo').innerHTML = `
                <div class="detail-row"><span class="detail-row-label">Rating</span><span class="detail-row-value">${ratingStars(d.customer_rating)}</span></div>
                ${d.customer_feedback ? `<div class="thread-msg" style="margin-top:8px"><div class="thread-msg-body">${escapeHtml(d.customer_feedback)}</div></div>` : ''}
            `;
        } else {
            $('#feedbackCard').hide();
        }

        // thread
        renderThread(d);

        // attachments
        renderAttachments(d.attachments || []);

        // update form
        if (d.can_edit) {
            $('#updateFormCard').show().removeClass('initially-hidden');
            document.getElementById('upd_id').value = d.id;
            const st = document.getElementById('upd_status');
            st.value = d.status;
            st._sd && st._sd.refresh();
            $('#upd_status').trigger('change');

            if (IS_ADMIN_LVL) {
                const pr = document.getElementById('upd_priority');
                pr.value = d.priority;
                pr._sd && pr._sd.refresh();
                const cat = document.getElementById('upd_category');
                cat.value = d.category;
                cat._sd && cat._sd.refresh();
                const src = document.getElementById('upd_source');
                src.value = d.source;
                src._sd && src._sd.refresh();
                const as = document.getElementById('upd_assigned');
                as.value = d.assigned_to || '';
                as._sd && as._sd.refresh();
                const exp = document.getElementById('upd_expected');
                if (exp) exp.value = d.expected_resolution_input || '';
            }
            document.getElementById('upd_resolution').value = d.resolution || '';
        } else {
            $('#updateFormCard').hide();
        }

        // mass-outage banner
        if (d.is_mass_outage) {
            $('#massOutageBanner').removeClass('initially-hidden');
            $('#massOutageInfo').text(`affected: ${d.affected_users_count || '?'} users`);
        } else {
            $('#massOutageBanner').addClass('initially-hidden');
        }

        // SLA timeline + action buttons
        if (d.can_edit) {
            renderSlaTimeline(d);
            renderSlaActions(d);
            $('#slaActionsCard').removeClass('initially-hidden');
        } else {
            renderSlaTimeline(d);
            $('#slaActionsCard').removeClass('initially-hidden'); // read-only timeline still useful
            $('#slaActionRow').empty();
        }

        // Resolve form is hidden by default; opens via "Mark Resolved" SLA button
        $('#resolveCard').addClass('initially-hidden');
        document.getElementById('rv_id').value = d.id;

        // CSAT card — show if resolved/closed and CSAT not yet collected (or readonly if collected)
        if (d.status === 'resolved' || d.status === 'closed') {
            $('#csatCard').removeClass('initially-hidden');
            document.getElementById('cs_id').value = d.id;
            const stars = document.getElementById('csStars');
            const ratingHidden = document.getElementById('cs_rating');
            const fbBox = document.getElementById('cs_feedback');
            const collected = d.customer_satisfaction_rating != null || d.customer_rating != null;
            const r = d.customer_satisfaction_rating || d.customer_rating || 0;
            stars.dataset.rating = r;
            ratingHidden.value = r;
            paintStars(stars, r);
            fbBox.value = d.customer_feedback_text || d.customer_feedback || '';
            if (collected) {
                stars.classList.add('locked');
                fbBox.setAttribute('readonly', 'readonly');
                $('#csatForm .form-actions').hide();
            } else {
                stars.classList.remove('locked');
                fbBox.removeAttribute('readonly');
                $('#csatForm .form-actions').show();
            }
        } else {
            $('#csatCard').addClass('initially-hidden');
        }
    }

    function paintStars(box, r) {
        $(box).find('i').each(function() {
            const v = parseInt($(this).data('v'), 10);
            $(this).toggleClass('active', v <= r);
        });
    }

    function renderSlaTimeline(d) {
        const rows = [
            ['Created',          d.created_at, 'fa-flag'],
            ['First Response',   d.first_response_at, 'fa-reply'],
            ['Assigned',         d.assigned_at, 'fa-user-gear'],
            ['Acknowledged',     d.acknowledged_at, 'fa-thumbs-up'],
            ['Arrived On Site',  d.arrived_onsite_at, 'fa-location-dot'],
            ['Work Started',     d.work_started_at, 'fa-screwdriver-wrench'],
            ['Work Completed',   d.work_completed_at, 'fa-check'],
            ['Resolved',         d.resolved_at, 'fa-circle-check'],
            ['Closed',           d.closed_at, 'fa-lock'],
            ['CSAT Collected',   d.feedback_collected_at, 'fa-star']
        ];
        const expected = d.expected_resolution_date ? `<div class="detail-row"><span class="detail-row-label"><i class="fas fa-bullseye"></i> Expected By</span><span class="detail-row-value">${escapeHtml(fmtDate(d.expected_resolution_date))}</span></div>` : '';
        const slaPill  = d.sla_breach_flag
            ? `<div class="detail-row"><span class="detail-row-label"><i class="fas fa-stopwatch"></i> SLA</span><span class="detail-row-value"><span class="sla-breach-badge">Breached by ${fmtMinutes(d.sla_breach_minutes)}</span></span></div>`
            : (d.work_completed_at ? `<div class="detail-row"><span class="detail-row-label"><i class="fas fa-stopwatch"></i> SLA</span><span class="detail-row-value"><span class="sla-breach-badge ok">On Time</span></span></div>` : '');
        const html = expected + slaPill + rows.map(([label, val, ico]) => `
            <div class="detail-row"><span class="detail-row-label"><i class="fas ${ico}"></i> ${label}</span><span class="detail-row-value">${val ? escapeHtml(fmtDate(val)) : '<span class="muted-dash">—</span>'}</span></div>
        `).join('');
        document.getElementById('slaTimeline').innerHTML = html;
    }

    function renderSlaActions(d) {
        const row = document.getElementById('slaActionRow');
        const btns = [];
        if (!d.acknowledged_at) {
            btns.push(`<button class="btn btn-primary" onclick="slaAction(${d.id}, 'acknowledgeComplaint', 'Acknowledge')"><i class="fas fa-thumbs-up"></i> Acknowledge</button>`);
        }
        if (!d.arrived_onsite_at) {
            btns.push(`<button class="btn btn-primary" onclick="slaAction(${d.id}, 'markArrived', 'Mark Arrived')"><i class="fas fa-location-dot"></i> Mark Arrived</button>`);
        }
        if (!d.work_started_at) {
            btns.push(`<button class="btn btn-primary" onclick="slaAction(${d.id}, 'markStarted', 'Mark Started')"><i class="fas fa-play"></i> Mark Started</button>`);
        }
        if (!d.work_completed_at) {
            btns.push(`<button class="btn btn-primary" onclick="slaAction(${d.id}, 'markCompleted', 'Mark Completed')"><i class="fas fa-check"></i> Mark Completed</button>`);
        }
        if (d.status !== 'resolved' && d.status !== 'closed') {
            btns.push(`<button class="btn btn-success" onclick="openResolveCard()"><i class="fas fa-circle-check"></i> Resolve…</button>`);
        }
        if (d.status === 'resolved' || d.status === 'closed') {
            btns.push(`<button class="btn btn-warning" onclick="reopenTicket(${d.id})"><i class="fas fa-rotate-left"></i> Reopen</button>`);
        }
        row.innerHTML = btns.join('');
    }

    function slaAction(id, action, label) {
        Swal.fire({ title: label + '?', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes' }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=' + action, method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) { Swal.fire({ icon:'success', text: resp.message, timer: 1200, showConfirmButton: false }); setTimeout(loadDetail, 300); }
                    else Swal.fire({ icon:'error', title:'Error', text: resp.message });
                },
                error: function() { Swal.fire({ icon:'error', text:'Connection error' }); }
            });
        });
    }

    function openResolveCard() { $('#resolveCard').removeClass('initially-hidden'); }

    function reopenTicket(id) {
        Swal.fire({
            title: 'Reopen Ticket', input: 'textarea', inputLabel: 'Reason for reopening',
            inputPlaceholder: 'Why is this being reopened?',
            showCancelButton: true, confirmButtonText: 'Reopen',
            inputValidator: v => (!v || !v.trim()) ? 'Reason is required' : undefined
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id); fd.append('reason', r.value);
            $.ajax({
                url: '?action=reopenComplaint', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) { Swal.fire({ icon:'success', text: resp.message, timer: 1200, showConfirmButton: false }); setTimeout(loadDetail, 300); }
                    else Swal.fire({ icon:'error', title:'Error', text: resp.message });
                },
                error: function() { Swal.fire({ icon:'error', text:'Connection error' }); }
            });
        });
    }

    function renderThread(d) {
        const box = document.getElementById('threadBox');
        const msgs = [];

        // initial complaint
        msgs.push({
            title: `<i class="fas fa-comment-dots"></i> Complaint filed by ${escapeHtml(d.customer_name)} via ${srcBadge(d.source)}`,
            ts:    d.created_at,
            body:  d.description
        });

        // first response
        if (d.first_response_at) {
            msgs.push({
                title: `<i class="fas fa-reply"></i> First response logged`,
                ts:    d.first_response_at,
                body:  ''
            });
        }

        // assigned
        if (d.assigned_name) {
            msgs.push({
                title: `<i class="fas fa-user-gear"></i> Assigned to ${escapeHtml(d.assigned_name)}`,
                ts:    d.assigned_at || d.updated_at,
                body:  ''
            });
        }

        // escalated
        if (d.escalated_to_user_name) {
            msgs.push({
                title: `<i class="fas fa-arrow-up-right-from-square"></i> Escalated to ${escapeHtml(d.escalated_to_user_name)}`,
                ts:    d.escalated_at || d.updated_at,
                body:  ''
            });
        }

        // in progress
        if (d.status === 'in_progress') {
            msgs.push({
                title: `<i class="fas fa-spinner"></i> Status: In Progress`,
                ts:    d.updated_at,
                body:  ''
            });
        }

        // resolved / closed
        if (d.status === 'resolved' || d.status === 'closed') {
            const who = d.assigned_name ? d.assigned_name : 'staff';
            msgs.push({
                title: `<i class="fas fa-clipboard-check"></i> Resolution by ${escapeHtml(who)}${d.resolution_time_minutes != null ? ' (' + fmtMinutes(d.resolution_time_minutes) + ')' : ''}`,
                ts:    d.resolved_at || d.updated_at,
                body:  d.resolution || '(no resolution text provided)'
            });
            if (d.status === 'closed') {
                const closedBy = d.closed_by_user_name ? ` by ${escapeHtml(d.closed_by_user_name)}` : '';
                msgs.push({
                    title: `<i class="fas fa-circle-check"></i> Ticket closed${closedBy}`,
                    ts:    d.closed_at || d.updated_at,
                    body:  ''
                });
            }
        }

        // reopened
        if (d.reopened_count > 0) {
            msgs.push({
                title: `<i class="fas fa-rotate-left"></i> Reopened ${d.reopened_count}x`,
                ts:    d.updated_at,
                body:  ''
            });
        }

        box.innerHTML = msgs.map(m => `
            <div class="thread-msg">
                <div class="thread-msg-header">
                    <span class="thread-msg-title">${m.title}</span>
                    <span>${escapeHtml(fmtDate(m.ts))}</span>
                </div>
                ${m.body ? `<div class="thread-msg-body">${escapeHtml(m.body)}</div>` : ''}
            </div>
        `).join('');
    }

    // ─── attachments ───
    function renderAttachments(list) {
        const box = document.getElementById('attachmentsList');
        if (!list.length) {
            box.innerHTML = '<div class="muted" style="padding:8px 0">No attachments yet.</div>';
            return;
        }
        box.innerHTML = list.map(a => {
            const canDel = IS_ADMIN_LVL || (a.uploaded_by === ME_ID);
            const thumb = a.is_image
                ? `<a href="${escapeHtml(a.file_url)}" target="_blank"><img src="${escapeHtml(a.file_url)}" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:4px;border:1px solid #e0e0e0"></a>`
                : `<a href="${escapeHtml(a.file_url)}" target="_blank" style="display:inline-flex;width:48px;height:48px;align-items:center;justify-content:center;background:#f1f5f9;border-radius:4px;border:1px solid #e0e0e0;color:#001f3f"><i class="fas fa-file-${a.ext === 'pdf' ? 'pdf' : 'lines'}" style="font-size:20px"></i></a>`;
            const delBtn = canDel
                ? `<button class="action-icon delete-icon" onclick="deleteAttachment(${a.id})" title="Delete"><i class="fas fa-trash"></i></button>`
                : '';
            return `
                <div class="detail-row" style="align-items:center">
                    <span class="detail-row-label" style="display:flex;align-items:center;gap:10px">
                        ${thumb}
                        <span>
                            <a href="${escapeHtml(a.file_url)}" target="_blank" style="color:#0074D9;font-weight:600">${escapeHtml(a.original_name)}</a>
                            <br><small class="muted">${fmtBytes(a.file_size)} — by ${escapeHtml(a.uploaded_by_name)} on ${escapeHtml(fmtDate(a.uploaded_at))}</small>
                        </span>
                    </span>
                    <span class="detail-row-value">${delBtn}</span>
                </div>
            `;
        }).join('');
    }

    function handleUploadSubmit(e) {
        e.preventDefault();
        const fileEl = document.getElementById('attFile');
        const f = fileEl.files[0];
        if (!f) { Swal.fire({ icon: 'warning', title: 'Pick a file', text: 'Choose a file to upload.' }); return; }
        if (f.size > MAX_BYTES) { Swal.fire({ icon: 'error', title: 'Too large', text: 'Max file size is 5MB.' }); return; }
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!ALLOWED_EXT.includes(ext)) {
            Swal.fire({ icon: 'error', title: 'Invalid type', text: 'Allowed: jpg, jpeg, png, webp, pdf' });
            return;
        }

        const fd = new FormData(this);
        Swal.fire({ title: 'Uploading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=uploadAttachment', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', text: resp.message, timer: 1200, showConfirmButton: false });
                    fileEl.value = '';
                    setTimeout(() => loadDetail(), 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    function deleteAttachment(id) {
        Swal.fire({
            icon: 'warning', title: 'Delete attachment?',
            text: 'This permanently removes the file.',
            showCancelButton: true, confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData(); fd.append('id', id);
            $.ajax({
                url: '?action=deleteAttachment', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', text: resp.message, timer: 1200, showConfirmButton: false });
                        setTimeout(() => loadDetail(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    // ─── escalate ───
    function openEscalateDialog() {
        if (!IS_ADMIN_LVL) return;
        // build options HTML
        const opts = staffList.filter(s => s.id !== ME_ID).map(s => `<option value="${s.id}">${escapeHtml(s.name)} (${escapeHtml(s.role)})</option>`).join('');
        Swal.fire({
            title: '<i class="fas fa-arrow-up-right-from-square"></i> Escalate Ticket',
            html: `
                <p style="margin-bottom:10px;color:#666;font-size:14px">Pick a staff member to escalate this ticket to.</p>
                <select id="swal_escalate_to" style="width:100%"><option value="">— Pick a user —</option>${opts}</select>
                <textarea id="swal_escalate_reason" style="width:100%;margin-top:10px;padding:8px;font-size:14px" rows="2" placeholder="Reason for escalation..."></textarea>
            `,
            showCancelButton: true,
            confirmButtonText: 'Escalate',
            confirmButtonColor: '#f59e0b',
            didOpen: () => {
                new SearchableDropdown({ selectId: 'swal_escalate_to', placeholder: 'Pick a user...', icon: 'fas fa-user-gear', allowClear: false });
            },
            preConfirm: () => {
                const v = document.getElementById('swal_escalate_to').value;
                if (!v) {
                    Swal.showValidationMessage('Please pick a user');
                    return false;
                }
                return { to: v, reason: document.getElementById('swal_escalate_reason').value || '' };
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', DETAIL_ID);
            fd.append('escalated_to_user_id', result.value.to);
            fd.append('escalation_reason', result.value.reason);
            Swal.fire({ title: 'Escalating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.ajax({
                url: '?action=escalateComplaint', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        Swal.fire({ icon: 'success', title: 'Escalated', text: resp.message, timer: 1500, showConfirmButton: false });
                        setTimeout(() => loadDetail(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function handleUpdateSubmit(e) {
        e.preventDefault();
        const fd = new FormData(this);
        Swal.fire({ title: 'Updating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '?action=updateComplaint', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Updated', text: resp.message, timer: 1500, showConfirmButton: false });
                    setTimeout(() => loadDetail(), 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: resp.message });
                }
            },
            error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }
    </script>
</body>
</html>
