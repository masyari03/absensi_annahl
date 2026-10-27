<?php
/**
 * API NOTIFIKASI PENGGUNA (AJAX)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = currentUserId();
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_latest';

try {
    if ($action === 'get_latest') {
        $unreadCount = getUnreadNotificationCount($pdo, $userId);
        $notifications = getRecentNotifications($pdo, $userId, 7);

        // Format data
        foreach ($notifications as &$n) {
            $n['time_ago'] = formatTimeAgo($n['created_at']);
            // Icon kategori
            $icon = 'fa-bell';
            $color = '#0284c7';
            if ($n['category'] === 'supervisi') {
                $icon = 'fa-clipboard-check';
                $color = '#6366f1';
            } elseif ($n['category'] === 'mentoring') {
                $icon = 'fa-hands-holding-child';
                $color = '#10b981';
            } elseif ($n['category'] === 'presensi') {
                $icon = 'fa-user-clock';
                $color = '#f59e0b';
            }
            $n['icon'] = $icon;
            $n['color'] = $color;
        }
        unset($n);

        echo json_encode([
            'success' => true,
            'unread_count' => $unreadCount,
            'notifications' => $notifications
        ]);
        exit;
    }

    if ($action === 'mark_read') {
        $notifId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $res = markNotificationAsRead($pdo, $notifId, $userId);
        echo json_encode(['success' => $res]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $res = markAllNotificationsAsRead($pdo, $userId);
        echo json_encode(['success' => $res]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
