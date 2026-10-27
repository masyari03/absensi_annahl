<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Pusat Notifikasi & Informasi';
$currentUserId = currentUserId();

// Handle aksi post (Tandai semua dibaca / tandai satu dibaca)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all_read') {
        markAllNotificationsAsRead($pdo, $currentUserId);
        flash('success', 'Semua notifikasi telah ditandai sebagai sudah dibaca.');
        redirect('index.php');
    }
    if ($action === 'mark_read') {
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            markNotificationAsRead($pdo, $nid, $currentUserId);
        }
        $redirectUrl = trim($_POST['redirect_url'] ?? '');
        if (!empty($redirectUrl)) {
            header('Location: ' . $redirectUrl);
            exit;
        }
        redirect('index.php');
    }
}

// Filter
$filterCategory = trim($_GET['category'] ?? '');
$filterUnread = isset($_GET['unread']) && $_GET['unread'] === '1';

$where = ["user_id = ?"];
$params = [$currentUserId];

if (!empty($filterCategory) && in_array($filterCategory, ['supervisi', 'mentoring', 'presensi', 'sistem'], true)) {
    $where[] = "category = ?";
    $params[] = $filterCategory;
}

if ($filterUnread) {
    $where[] = "is_read = 0";
}

$whereSql = "WHERE " . implode(' AND ', $where);

// Hitung total unread
$unreadCount = getUnreadNotificationCount($pdo, $currentUserId);

// Tarik data notifikasi
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    {$whereSql} 
    ORDER BY created_at DESC, id DESC 
    LIMIT 100
");
$stmt->execute($params);
$notificationsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="content" style="max-width: 900px; margin: 0 auto;">
    <!-- HEADER -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
        <div>
            <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-bell" style="color: #0284c7;"></i> Pusat Notifikasi
            </h1>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 14px;">
                Informasi jadwal supervisi, jurnal pembinaan santri, dan pemantauan presensi Anda.
            </p>
        </div>

        <?php if ($unreadCount > 0): ?>
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; cursor: pointer;">
                    <i class="fa-solid fa-check-double"></i> Tandai Semua Dibaca
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- FILTER TABS -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px;">
        <a href="index.php" class="btn" style="padding: 7px 14px; font-size: 13px; font-weight: 700; border-radius: 8px; text-decoration: none; <?= (empty($filterCategory) && !$filterUnread) ? 'background:#0284c7; color:white;' : 'background:#fff; color:#475569; border:1px solid #cbd5e1;' ?>">
            Semua
        </a>
        <a href="index.php?unread=1" class="btn" style="padding: 7px 14px; font-size: 13px; font-weight: 700; border-radius: 8px; text-decoration: none; <?= $filterUnread ? 'background:#0284c7; color:white;' : 'background:#fff; color:#475569; border:1px solid #cbd5e1;' ?>">
            Belum Dibaca <?= $unreadCount > 0 ? "({$unreadCount})" : '' ?>
        </a>
        <a href="index.php?category=supervisi" class="btn" style="padding: 7px 14px; font-size: 13px; font-weight: 700; border-radius: 8px; text-decoration: none; <?= $filterCategory === 'supervisi' ? 'background:#6366f1; color:white;' : 'background:#fff; color:#475569; border:1px solid #cbd5e1;' ?>">
            <i class="fa-solid fa-clipboard-check"></i> Supervisi
        </a>
        <a href="index.php?category=mentoring" class="btn" style="padding: 7px 14px; font-size: 13px; font-weight: 700; border-radius: 8px; text-decoration: none; <?= $filterCategory === 'mentoring' ? 'background:#10b981; color:white;' : 'background:#fff; color:#475569; border:1px solid #cbd5e1;' ?>">
            <i class="fa-solid fa-hands-holding-child"></i> Mentoring
        </a>
        <a href="index.php?category=presensi" class="btn" style="padding: 7px 14px; font-size: 13px; font-weight: 700; border-radius: 8px; text-decoration: none; <?= $filterCategory === 'presensi' ? 'background:#f59e0b; color:white;' : 'background:#fff; color:#475569; border:1px solid #cbd5e1;' ?>">
            <i class="fa-solid fa-user-clock"></i> Presensi
        </a>
    </div>

    <!-- LIST NOTIFIKASI -->
    <?php if (empty($notificationsList)): ?>
        <div class="card" style="text-align: center; padding: 48px 20px; background: #fff; border-radius: 14px; border: 1px solid #e2e8f0;">
            <div style="font-size: 42px; color: #cbd5e1; margin-bottom: 12px;">
                <i class="fa-regular fa-bell-slash"></i>
            </div>
            <h3 style="margin: 0; color: #475569; font-weight: 700; font-size: 16px;">Tidak ada notifikasi</h3>
            <p style="margin: 6px 0 0; color: #94a3b8; font-size: 13px;">Belum ada pemberitahuan baru pada filter yang dipilih.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($notificationsList as $item): ?>
                <?php
                $cat = $item['category'];
                $icon = 'fa-bell';
                $color = '#0284c7';
                $bgLight = '#f0f9ff';
                if ($cat === 'supervisi') {
                    $icon = 'fa-clipboard-check';
                    $color = '#6366f1';
                    $bgLight = '#eef2ff';
                } elseif ($cat === 'mentoring') {
                    $icon = 'fa-hands-holding-child';
                    $color = '#10b981';
                    $bgLight = '#ecfdf5';
                } elseif ($cat === 'presensi') {
                    $icon = 'fa-user-clock';
                    $color = '#f59e0b';
                    $bgLight = '#fffbeb';
                }
                $isUnread = ((int)$item['is_read'] === 0);
                ?>
                <div class="card" style="padding: 16px; border-radius: 12px; background: <?= $isUnread ? '#ffffff' : '#f8fafc' ?>; border: 1px solid <?= $isUnread ? '#cbd5e1' : '#f1f5f9' ?>; border-left: 4px solid <?= $color ?>; display: flex; gap: 14px; align-items: flex-start; transition: all 0.2s ease;">
                    <div style="width: 40px; height: 40px; border-radius: 10px; background: <?= $bgLight ?>; color: <?= $color ?>; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; margin-top: 2px;">
                        <i class="fa-solid <?= $icon ?>"></i>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 4px;">
                            <h4 style="margin: 0; font-size: 15px; font-weight: <?= $isUnread ? '800' : '600' ?>; color: #0f172a;">
                                <?= htmlspecialchars($item['title']) ?>
                                <?php if ($isUnread): ?>
                                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #ef4444; margin-left: 6px;"></span>
                                <?php endif; ?>
                            </h4>
                            <span style="font-size: 12px; color: #94a3b8; white-space: nowrap;">
                                <?= formatTimeAgo($item['created_at']) ?>
                            </span>
                        </div>
                        <p style="margin: 0; font-size: 13.5px; color: #475569; line-height: 1.5;">
                            <?= nl2br(htmlspecialchars($item['message'])) ?>
                        </p>
                        <?php if (!empty($item['link_url'])): ?>
                            <div style="margin-top: 10px;">
                                <form method="POST" style="margin: 0; display: inline-block;">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= $item['id'] ?>">
                                    <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($item['link_url']) ?>">
                                    <button type="submit" style="background: none; border: none; padding: 0; color: #0284c7; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                                        Lihat Detail <i class="fa-solid fa-arrow-right" style="font-size: 11px;"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
