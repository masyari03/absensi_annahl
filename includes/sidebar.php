<?php
/**
 * SIDEBAR DINAMIS — Database-Driven ACL
 *
 * Menu hanya ditampilkan jika user memiliki menu_key yang sesuai.
 * Super Admin otomatis melihat semua menu.
 * Jika izin belum diset di user_menu_access, otomatis fallback ke default role.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($currentRole === 'super_admin');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

global $pdo;

$sidebarMenus = [];
if (isset($pdo) && $currentUserId > 0) {
    if ($isSuperAdmin) {
        // Super Admin: ambil semua menu aktif
        $stmtNav = $pdo->query("
            SELECT id, menu_key, menu_name, category, menu_url, menu_icon, sort_order
            FROM app_menus
            WHERE is_active = 1
            ORDER BY sort_order ASC
        ");
        $rawMenus = $stmtNav->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // User biasa: ambil dari user_menu_access
        $stmtNav = $pdo->prepare("
            SELECT am.id, am.menu_key, am.menu_name, am.category, am.menu_url, am.menu_icon, am.sort_order
            FROM app_menus am
            INNER JOIN user_menu_access uma ON uma.menu_id = am.id
            WHERE uma.user_id = ? AND am.is_active = 1
            ORDER BY am.sort_order ASC
        ");
        $stmtNav->execute([$currentUserId]);
        $rawMenus = $stmtNav->fetchAll(PDO::FETCH_ASSOC);

        // Fallback ke default role jika user_menu_access belum ada
        if (empty($rawMenus) && !empty($currentRole)) {
            $stmtFallback = $pdo->prepare("
                SELECT am.id, am.menu_key, am.menu_name, am.category, am.menu_url, am.menu_icon, am.sort_order
                FROM app_menus am
                INNER JOIN role_menu_access rma ON rma.menu_id = am.id
                INNER JOIN roles r ON r.id = rma.role_id
                WHERE r.role_key = ? AND am.is_active = 1
                ORDER BY am.sort_order ASC
            ");
            $stmtFallback->execute([$currentRole]);
            $rawMenus = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);

            // Auto seed ke user_menu_access
            if (!empty($rawMenus)) {
                try {
                    $stmtSeed = $pdo->prepare("
                        INSERT IGNORE INTO user_menu_access (user_id, menu_id)
                        SELECT ?, rma.menu_id
                        FROM role_menu_access rma
                        INNER JOIN roles r ON r.id = rma.role_id
                        WHERE r.role_key = ?
                    ");
                    $stmtSeed->execute([$currentUserId, $currentRole]);
                } catch (Exception $e) {}
            }
        }
    }

    // Realtime session synchronization agar hak akses langsung sinkron tanpa logout
    $_SESSION['menu_keys'] = array_column($rawMenus, 'menu_key');

    // Kelompokkan per kategori
    foreach ($rawMenus as $m) {
        $cat = strtoupper($m['category'] ?? 'LAINNYA');
        $sidebarMenus[$cat][] = $m;
    }
}

// Canonical URL Map
$menuUrlMap = [
    'dashboard'            => '/dashboard.php',
    'scanner'              => '/scanner.php',
    'attendance_students'  => '/attendance/students.php',
    'attendance_staff'     => '/attendance/staff.php',
    'master_students'      => '/students/index.php',
    'master_staff'         => '/staff/index.php',
    'master_admins'        => '/admins/index.php',
    'master_kepsek'        => '/kepala_sekolah/index.php',
    'master_units'         => '/units/index.php',
    'master_grades'        => '/grades/index.php',
    'master_classes'       => '/classes/index.php',
    'master_activities'    => '/activities/index.php',
    'master_enrollments'   => '/enrollments/index.php',
    'master_schedules'     => '/weekly_schedules/index.php',
    'master_academic_years'=> '/academic_years/index.php',
    'reports_attendance'   => '/reports/index.php',
    'reports_overtimes'    => '/overtimes/index.php',
    'access_control'       => '/kelola/index.php',
    'user_activity'        => '/kelola/activity.php',
    'data_cctv'            => '/cctv/index.php',
    'whatsapp_notif'       => '/whatsapp/index.php',
];

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if (!function_exists('isActiveMenu')) {
    function isActiveMenu(string $menuUrl, string $currentPath): bool {
        $curr = str_replace('\\', '/', $currentPath);
        $urlPath = '/' . ltrim($menuUrl, '/');

        if (strpos($curr, $urlPath) !== false) {
            return true;
        }

        $dir = dirname(ltrim($menuUrl, '/'));
        if ($dir !== '.' && $dir !== '' && strpos($curr, '/' . $dir . '/') !== false) {
            return true;
        }

        return false;
    }
}
?>

<aside class="sidebar" id="sidebar">
    <div class="brand" style="position: relative;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="brand-icon" style="background:transparent; padding:0; display:flex; justify-content:center; align-items:center;">
                <img src="<?= BASE_URL ?>/favicon.png" alt="Logo" style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover;">
            </div>
            <div>
                <strong>AIS Absensi</strong>
                <small>An Nahl Islamic School</small>
            </div>
        </div>
        <!-- TOMBOL X (TUTUP) -->
        <button id="closeSidebar" class="btn-close-sidebar" onclick="closeSidebarMenu(event)" title="Tutup Menu" type="button">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="pointer-events: none;">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <nav>
        <?php if (empty($sidebarMenus)): ?>
            <div style="padding: 20px 12px; color: #94a3b8; font-size: 13px; text-align: center;">
                <i class="fa-solid fa-lock" style="display:block; font-size:24px; margin-bottom:8px;"></i>
                Belum ada menu yang diizinkan.
            </div>
        <?php else: ?>
            <?php foreach ($sidebarMenus as $category => $items): ?>
                <span class="menu-title"><?= htmlspecialchars($category) ?></span>
                <?php foreach ($items as $item):
                    $url = isset($menuUrlMap[$item['menu_key']])
                           ? BASE_URL . $menuUrlMap[$item['menu_key']]
                           : BASE_URL . '/' . ltrim($item['menu_url'], '/');

                    $isActive = isActiveMenu($item['menu_url'], $currentPath);

                    $iconClass = !empty($item['menu_icon']) ? $item['menu_icon'] : 'fa-circle';
                    if (strpos($iconClass, 'fa-') !== false && strpos($iconClass, 'fa-solid ') === false) {
                        $iconClass = 'fa-solid ' . $iconClass;
                    }

                    $isScanner = ($item['menu_key'] === 'scanner');
                    $extraAttr = $isScanner ? ' onclick="handleScannerMenuClick(event)"' : '';
                ?>
                    <a href="<?= $url ?>"<?= $isActive ? ' class="active"' : '' ?><?= $extraAttr ?>>
                        <i class="<?= htmlspecialchars($iconClass) ?>" style="width:18px; text-align:center;"></i>
                        <?= htmlspecialchars($item['menu_name']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <script>
        function handleScannerMenuClick(e) {
            sessionStorage.setItem('ais_fullscreen', '1');
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(function(){});
            }
        }
        </script>

        <!-- Tombol Keluar selalu ditampilkan -->
        <span class="menu-title">AKUN</span>
        <a href="<?= BASE_URL ?>/logout.php" class="logout-link">
            <i class="fa-solid fa-right-from-bracket" style="width:18px; text-align:center;"></i>
            Keluar
        </a>
    </nav>
</aside>