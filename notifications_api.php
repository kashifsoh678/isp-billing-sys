<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit();
}

header('Content-Type: application/json');
$user_id = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {
        case 'getCount':
            $count = getUnreadNotificationCount($user_id);
            echo json_encode(['success' => true, 'count' => $count]);
            break;

        case 'getRecent':
            $notifications = getRecentNotifications($user_id, 10);
            $count = getUnreadNotificationCount($user_id);
            echo json_encode(['success' => true, 'notifications' => $notifications, 'count' => $count]);
            break;

        case 'markRead':
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id > 0) {
                markNotificationRead($id, $user_id);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
            }
            break;

        case 'markAllRead':
            markAllNotificationsRead($user_id);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit();
?>
