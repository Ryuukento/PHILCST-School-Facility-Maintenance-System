<?php
require_once __DIR__ . '/../bootstrap.php';

// Ensure session middleware has initialized the session
if (class_exists('SessionMiddleware')) {
    SessionMiddleware::initialize();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['user']['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

// Allow all authenticated users to access notifications
// if ($user['role'] !== 'super_admin') {
//     http_response_code(403);
//     die(json_encode(['success' => false, 'message' => 'Forbidden']));
// }

require_once __DIR__ . '/../models/Notification.php';
$notification = new Notification($pdo);

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'getUnread':
            $limit = $_GET['limit'] ?? 10;
            $unread = $notification->getUnread($userId, (int)$limit);
            echo json_encode([
                'success' => true,
                'data' => [
                    'notifications' => $unread,
                    'count' => count($unread)
                ]
            ]);
            break;
            
        case 'getAll':
            $limit = $_GET['limit'] ?? 50;
            $offset = $_GET['offset'] ?? 0;
            $all = $notification->getAll($userId, (int)$limit, (int)$offset);
            $count = $notification->countUnread($userId);
            echo json_encode([
                'success' => true,
                'data' => [
                    'notifications' => $all,
                    'unread_count' => $count
                ]
            ]);
            break;
            
        case 'markAsRead':
            $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
            if ($notificationId <= 0) {
                throw new Exception('Notification ID is required');
            }
            // TASK 52 — $userId passed through so Notification::markAsRead()
            // can scope the UPDATE to the caller's own notifications (IDOR fix).
            $notification->markAsRead($notificationId, $userId);
            echo json_encode(['success' => true, 'message' => 'Marked as read']);
            break;
            
        case 'markAllAsRead':
            $notification->markAllAsRead($userId);
            echo json_encode(['success' => true, 'message' => 'All marked as read']);
            break;
            
        case 'delete':
            $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
            if ($notificationId <= 0) {
                throw new Exception('Notification ID is required');
            }
            // TASK 52 — $userId passed through so Notification::delete() can
            // scope the DELETE to the caller's own notifications (IDOR fix).
            $notification->delete($notificationId, $userId);
            echo json_encode(['success' => true, 'message' => 'Notification deleted']);
            break;
            
        case 'count':
            $count = $notification->countUnread($userId);
            echo json_encode([
                'success' => true,
                'data' => ['count' => $count]
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (PDOException $e) {
    // TASK 59 — Legacy Public Backend Shim Audit. This endpoint previously had
    // only the generic `catch (Exception)` below, which returned
    // $e->getMessage() verbatim to the HTTP client. PDOException extends
    // Exception, and the legacy PDO handle is built with
    // PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    // (public/backend/config/database.php:67), so any database failure was
    // caught there and its message — which can carry the SQLSTATE code, the
    // literal SQL, and column/constraint names — was echoed into the response
    // body. This mirrors the disclosure TASK 55 fixed in
    // FacilityService::createBuilding() (services/FacilityService.php:66-78);
    // Task 55 audited the legacy services and did not reach this procedural
    // endpoint. The `display_errors=0` hardening in config/settings.php does
    // not mitigate it, because this is an explicit echo in application code
    // rather than a PHP-emitted error.
    //
    // Caught BEFORE `Exception` deliberately: PDOException is a subclass, so
    // the reverse order would leave this block unreachable. The full message
    // is still captured server-side; only the client-facing copy changes. 500
    // replaces the old blanket 400 because a database failure is not a client
    // error.
    Logger::error('Notifications endpoint database failure', [
        'action'  => $action,
        'user_id' => $userId,
        'error'   => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to process notification request'
    ]);
} catch (Exception $e) {
    // Retains $e->getMessage() on purpose: the only exceptions reaching here
    // are the deliberate validation throws above ('Notification ID is
    // required', 'Invalid action'), which are developer-authored literals and
    // are this endpoint's only user-facing feedback.
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}