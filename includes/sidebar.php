<?php
/**
 * SIDEBAR DINAMIS — Database-Driven ACL & Parent-Child Dropdown Accordion
 *
 * Menu hanya ditampilkan jika user memiliki menu_key yang sesuai.
 * Super Admin otomatis melihat semua menu.
 * Jika menu memiliki sub-menu (parent_id), otomatis dirender sebagai collapsible dropdown.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($currentRole === 'super_admin');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

global $pdo;

$sidebarMenus = [];
$rawMenus = [];

if (isset($pdo) && $currentUserId > 0) {
    if ($isSuperAdmin) {
        // Super Admin: ambil semua menu aktif
        $stmtNav = $pdo->query("
            SELECT id, menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order
            FROM app_menus
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        $rawMenus = $stmtNav->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // User biasa: ambil dari multi-role additive union (user_roles + roles + user_menu_access)
        // serta sertakan parent menu otomatis jika punya child yang diizinkan
        $stmtNav = $pdo->prepare("
            SELECT am.id, am.menu_key, am.menu_name, am.category, am.menu_url, am.menu_icon, am.parent_id, am.sort_order
            FROM app_menus am
            LEFT JOIN user_menu_access uma 
                ON uma.menu_id = am.id AND uma.user_id = ?
            LEFT JOIN user_roles ur 
                ON ur.user_id = ?
            LEFT JOIN roles r_ur 
                ON r_ur.id = ur.role_id
            LEFT JOIN users u 
                ON u.id = ?
            LEFT JOIN roles r_primary 
                ON r_primary.role_key = u.role
            LEFT JOIN role_menu_access rma 
                ON rma.menu_id = am.id AND (rma.role_id = ur.role_id OR rma.role_id = r_primary.id)
            WHERE am.is_active = 1
            GROUP BY am.id, am.menu_key, am.menu_name, am.category, am.menu_url, am.menu_icon, am.parent_id, am.sort_order
            HAVING GREATEST(COALESCE(MAX(uma.can_read), 0), COALESCE(MAX(rma.can_read), 0)) > 0

            UNION

            SELECT p.id, p.menu_key, p.menu_name, p.category, p.menu_url, p.menu_icon, p.parent_id, p.sort_order
            FROM app_menus p
            INNER JOIN app_menus ch ON ch.parent_id = p.id
            LEFT JOIN user_menu_access uma 
                ON uma.menu_id = ch.id AND uma.user_id = ?
            LEFT JOIN user_roles ur 
                ON ur.user_id = ?
            LEFT JOIN roles r_ur 
                ON r_ur.id = ur.role_id
            LEFT JOIN users u 
                ON u.id = ?
            LEFT JOIN roles r_primary 
                ON r_primary.role_key = u.role
            LEFT JOIN role_menu_access rma 
                ON rma.menu_id = ch.id AND (rma.role_id = ur.role_id OR rma.role_id = r_primary.id)
            WHERE p.is_active = 1 AND ch.is_active = 1
            GROUP BY p.id, p.menu_key, p.menu_name, p.category, p.menu_url, p.menu_icon, p.parent_id, p.sort_order
            HAVING GREATEST(COALESCE(MAX(uma.can_read), 0), COALESCE(MAX(rma.can_read), 0)) > 0

            ORDER BY sort_order ASC, id ASC
        ");
        $stmtNav->execute([
            $currentUserId, $currentUserId, $currentUserId,
            $currentUserId, $currentUserId, $currentUserId
        ]);
        $rawMenus = $stmtNav->fetchAll(PDO::FETCH_ASSOC);

        // Realtime session synchronization agar hak akses langsung sinkron tanpa logout
        if (function_exists('loadUserPermissions')) {
            loadUserPermissions($pdo, $currentUserId);
        }
    }

    // FILTER KEAMANAN MANDATORI:
    // 1. Menu 'user_activity' (Monitoring & Log Aktivitas Pengguna) HANYA BISA DIAKSES OLEH SUPER ADMIN
    if ($currentRole !== 'super_admin') {
        $rawMenus = array_filter($rawMenus, function($m) {
            return ($m['menu_key'] ?? '') !== 'user_activity';
        });
    }

    // 2. Jika izin web dinonaktifkan oleh Super Admin, sembunyikan menu izin siswa
    $webPermitEnabled = (getSystemSetting($pdo, 'student_web_permit_enabled', '1') === '1');
    if (!$webPermitEnabled) {
        $rawMenus = array_filter($rawMenus, function($m) {
            return ($m['menu_key'] ?? '') !== 'student_permits';
        });
    }

    // 3. Proteksi Modul Mentoring & Pembuatan Kelompok:
    // A. Menu 'mentoring_assignments' (Kelola Kelompok Mentoring) HANYA BISA DIAKSES OLEH KEPALA SEKOLAH & SUPER ADMIN
    //    Guru / Staff TIDAK BOLEH melihat atau membuat kelompok mentoring dalam kondisi apa pun.
    $isSuperOrKepsek = in_array($currentRole, ['super_admin', 'kepala_sekolah'], true);
    if (!$isSuperOrKepsek) {
        $rawMenus = array_filter($rawMenus, function($m) {
            return ($m['menu_key'] ?? '') !== 'mentoring_assignments';
        });
    }

    // B. Menu Pembinaan lainnya (dashboard, report, adab, curriculum):
    //    Guru / Staff hanya bisa mengakses JIKA Kepala Sekolah telah memberikan izin.
    if (!in_array($currentRole, ['super_admin', 'admin', 'kepala_sekolah'], true)) {
        $hasMentoringAccess = function_exists('canTeacherAccessMentoring') && canTeacherAccessMentoring($pdo, (int)$currentUserId);
        if (!$hasMentoringAccess) {
            $mentoringKeys = ['mentoring_dashboard', 'mentoring_report', 'mentoring_adab', 'mentoring_curriculum', 'pembinaan_parent'];
            $rawMenus = array_filter($rawMenus, function($m) use ($mentoringKeys) {
                return !in_array($m['menu_key'] ?? '', $mentoringKeys, true);
            });
        }
    }

    // Realtime session synchronization agar hak akses langsung sinkron tanpa logout
    $_SESSION['menu_keys'] = array_filter(array_column($rawMenus, 'menu_key'));
}

// Canonical URL Map
$menuUrlMap = [
    'dashboard'             => '/dashboard.php',
    'scanner'               => '/scanner.php',
    'attendance_students'   => '/attendance/students.php',
    'attendance_staff'      => '/attendance/staff.php',
    'student_permits'       => '/attendance/permits.php',
    'student_achievements'  => '/achievements/index.php',
    'master_students'       => '/students/index.php',
    'master_staff'          => '/staff/index.php',
    'master_admins'         => '/admins/index.php',
    'master_kepsek'         => '/kepala_sekolah/index.php',
    'master_units'          => '/units/index.php',
    'master_grades'         => '/grades/index.php',
    'master_classes'        => '/classes/index.php',
    'master_activities'     => '/activities/index.php',
    'master_enrollments'    => '/enrollments/index.php',
    'master_schedules'      => '/weekly_schedules/index.php',
    'master_academic_years' => '/academic_years/index.php',
    'reports_attendance'    => '/reports/index.php',
    'reports_overtimes'     => '/overtimes/index.php',
    'access_control'        => '/kelola/index.php',
    'user_activity'         => '/kelola/activity.php',
    'activity_logs'         => '/kelola/audit_logs.php',
    'auto_attendance'       => '/kelola/auto_attendance.php',
    'data_cctv'             => '/cctv/index.php',
    'whatsapp_notif'        => '/whatsapp/index.php',
    // PEMBINAAN
    'mentoring_dashboard'   => '/mentoring/index.php',
    'mentoring_assignments' => '/mentoring/assignments.php',
    'mentoring_report'      => '/mentoring/report.php',
    'mentoring_adab'        => '/mentoring/adab_monitoring.php',
    'mentoring_curriculum'  => '/mentoring/curriculum.php',
    // SUPERVISI GURU
    'supervisi_dashboard'   => '/supervisi/index.php',
    'supervisi_sejawat'     => '/supervisi/peer.php',
    'supervisi_kepsek'      => '/supervisi/principal.php',
    'supervisi_jadwal'      => '/supervisi/schedules.php',
    'supervisi_rekap'       => '/supervisi/reports.php',
    'supervisi_rubrik'      => '/supervisi/rubrics.php',
];

$allMenuUrls = [];
if (!empty($rawMenus)) {
    foreach ($rawMenus as $rm) {
        if (!empty($rm['menu_key']) && isset($menuUrlMap[$rm['menu_key']])) {
            $allMenuUrls[] = $menuUrlMap[$rm['menu_key']];
        } elseif (!empty($rm['menu_url']) && $rm['menu_url'] !== '#') {
            $allMenuUrls[] = '/' . ltrim($rm['menu_url'], '/');
        }
    }
}

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if (!function_exists('isActiveMenu')) {
    function isActiveMenu(string $menuUrl, string $currentPath, ?array $knownUrls = null): bool {
        global $menuUrlMap;
        $allUrls = ($knownUrls !== null && !empty($knownUrls)) ? $knownUrls : (array)($menuUrlMap ?? []);

        $curr = str_replace('\\', '/', $currentPath);
        $urlPath = '/' . ltrim($menuUrl, '/');

        // 1. Exact match file (misal /mentoring/report.php)
        if (strpos($curr, $urlPath) !== false) {
            return true;
        }

        // 2. Jika halaman yang dibuka memiliki menu eksplisit sendiri di sidebar, jangan aktifkan menu index/parent
        $currentFilename = basename($curr);
        if ($currentFilename && $currentFilename !== 'index.php') {
            foreach ($allUrls as $otherUrl) {
                if (basename($otherUrl) === $currentFilename && $otherUrl !== $urlPath) {
                    return false;
                }
            }
        }

        // 3. Subpage matching untuk halaman create/edit yang tidak ada di sidebar
        $dir = dirname(ltrim($menuUrl, '/'));
        if ($dir !== '.' && $dir !== '' && strpos($curr, '/' . $dir . '/') !== false) {
            if (basename($urlPath) === 'index.php') {
                return true;
            }
        }

        return false;
    }
}

// STRUKTURISASI HIRARKI MENU (PARENT & CHILD)
$parentsByCategory = [];
$childrenByParentId = [];
$allParentsMap = [];

foreach ($rawMenus as $m) {
    $parentId = !empty($m['parent_id']) ? (int)$m['parent_id'] : 0;
    if ($parentId > 0) {
        $childrenByParentId[$parentId][] = $m;
    } else {
        $cat = strtoupper($m['category'] ?? 'LAINNYA');
        $parentsByCategory[$cat][] = $m;
        $allParentsMap[(int)$m['id']] = $m;
    }
}
?>

<style>
/* CSS Dropdown Accordion Sidebar */
.sidebar-dropdown {
    margin-bottom: 4px;
}
.sidebar-dropdown-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-radius: 10px;
    background: transparent;
    border: none;
    color: #cbd5e1;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    text-align: left;
    transition: all 0.2s ease;
    font-family: inherit;
    user-select: none;
}
.sidebar-dropdown-btn:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff;
}
.sidebar-dropdown-btn.has-active-child {
    background: rgba(99, 102, 241, 0.15);
    color: #a5b4fc;
    font-weight: 600;
}
.sidebar-dropdown-btn .parent-info {
    display: flex;
    align-items: center;
    gap: 12px;
}
.sidebar-dropdown-btn .chevron-icon {
    font-size: 11px;
    transition: transform 0.25s ease;
    color: #94a3b8;
}
.sidebar-dropdown-btn.open .chevron-icon {
    transform: rotate(180deg);
}
.sidebar-submenu {
    display: none;
    padding-left: 12px;
    margin-top: 3px;
    margin-bottom: 6px;
    border-left: 2px solid rgba(255, 255, 255, 0.12);
    margin-left: 20px;
}
.sidebar-submenu.show {
    display: block;
}
.sidebar-submenu a {
    padding: 9px 12px;
    font-size: 13.5px;
    color: #94a3b8;
    margin-bottom: 2px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    transition: all 0.2s ease;
}
.sidebar-submenu a:hover {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.07);
    transform: translateX(3px);
}
.sidebar-submenu a.active {
    color: #ffffff;
    background: #4f46e5;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.35);
}
</style>

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
        <?php if (empty($rawMenus)): ?>
            <div style="padding: 20px 12px; color: #94a3b8; font-size: 13px; text-align: center;">
                <i class="fa-solid fa-lock" style="display:block; font-size:24px; margin-bottom:8px;"></i>
                Belum ada menu yang diizinkan.
            </div>
        <?php else: ?>
            <?php foreach ($parentsByCategory as $category => $items): ?>
                <span class="menu-title"><?= htmlspecialchars($category) ?></span>
                <?php foreach ($items as $item):
                    $itemId = (int)$item['id'];
                    $hasChildren = !empty($childrenByParentId[$itemId]);

                    if ($hasChildren):
                        $children = $childrenByParentId[$itemId];
                        // Cek apakah ada child yang sedang aktif dibuka
                        $isAnyChildActive = false;
                        foreach ($children as $ch) {
                            $chTarget = $menuUrlMap[$ch['menu_key']] ?? ('/' . ltrim($ch['menu_url'], '/'));
                            if (isActiveMenu($chTarget, $currentPath, $allMenuUrls)) {
                                $isAnyChildActive = true;
                                break;
                            }
                        }

                        $parentIcon = !empty($item['menu_icon']) ? $item['menu_icon'] : 'fa-folder';
                        if (strpos($parentIcon, 'fa-') !== false && strpos($parentIcon, 'fa-solid ') === false) {
                            $parentIcon = 'fa-solid ' . $parentIcon;
                        }
                        $dropdownKey = 'sidebar_dd_' . $itemId;
                    ?>
                        <div class="sidebar-dropdown">
                            <button type="button" 
                                    class="sidebar-dropdown-btn <?= $isAnyChildActive ? 'has-active-child open' : '' ?>" 
                                    id="btn_<?= $dropdownKey ?>"
                                    onclick="toggleSidebarDropdown('<?= $dropdownKey ?>', event)">
                                <div class="parent-info">
                                    <i class="<?= htmlspecialchars($parentIcon) ?>" style="width:18px; text-align:center;"></i>
                                    <span><?= htmlspecialchars($item['menu_name']) ?></span>
                                </div>
                                <i class="fa-solid fa-chevron-down chevron-icon"></i>
                            </button>
                            <div class="sidebar-submenu <?= $isAnyChildActive ? 'show' : '' ?>" id="<?= $dropdownKey ?>">
                                <?php foreach ($children as $child):
                                    $chTarget = $menuUrlMap[$child['menu_key']] ?? ('/' . ltrim($child['menu_url'], '/'));
                                    $chUrl = BASE_URL . $chTarget;
                                    $chActive = isActiveMenu($chTarget, $currentPath, $allMenuUrls);

                                    $chIcon = !empty($child['menu_icon']) ? $child['menu_icon'] : 'fa-circle-dot';
                                    if (strpos($chIcon, 'fa-') !== false && strpos($chIcon, 'fa-solid ') === false) {
                                        $chIcon = 'fa-solid ' . $chIcon;
                                    }
                                ?>
                                    <a href="<?= $chUrl ?>" class="<?= $chActive ? 'active' : '' ?>">
                                        <i class="<?= htmlspecialchars($chIcon) ?>" style="width:16px; font-size:12px; text-align:center;"></i>
                                        <span><?= htmlspecialchars($child['menu_name']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php else:
                        // Item biasa tanpa anak
                        $targetPath = $menuUrlMap[$item['menu_key']] ?? ('/' . ltrim($item['menu_url'], '/'));
                        $url = BASE_URL . $targetPath;
                        $isActive = isActiveMenu($targetPath, $currentPath, $allMenuUrls);

                        $iconClass = !empty($item['menu_icon']) ? $item['menu_icon'] : 'fa-circle';
                        if (strpos($iconClass, 'fa-') !== false && strpos($iconClass, 'fa-solid ') === false) {
                            $iconClass = 'fa-solid ' . $iconClass;
                        }

                        $isScanner = ($item['menu_key'] === 'scanner');
                        $extraAttr = $isScanner ? ' onclick="handleScannerMenuClick(event)"' : '';
                    ?>
                        <a href="<?= $url ?>"<?= $isActive ? ' class="active"' : '' ?><?= $extraAttr ?>>
                            <i class="<?= htmlspecialchars($iconClass) ?>" style="width:18px; text-align:center;"></i>
                            <span><?= htmlspecialchars($item['menu_name']) ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <script>
        function toggleSidebarDropdown(id, e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            const submenu = document.getElementById(id);
            if (!submenu) return;
            const btn = document.getElementById('btn_' + id);
            const isOpen = submenu.classList.contains('show');

            if (isOpen) {
                submenu.classList.remove('show');
                if (btn) btn.classList.remove('open');
                sessionStorage.setItem('ais_nav_' + id, 'closed');
            } else {
                submenu.classList.add('show');
                if (btn) btn.classList.add('open');
                sessionStorage.setItem('ais_nav_' + id, 'open');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.sidebar-submenu').forEach(function(sub) {
                const id = sub.id;
                const btn = document.getElementById('btn_' + id);
                const savedState = sessionStorage.getItem('ais_nav_' + id);
                if (savedState === 'open' && !sub.classList.contains('show')) {
                    sub.classList.add('show');
                    if (btn) btn.classList.add('open');
                } else if (savedState === 'closed' && btn && !btn.classList.contains('has-active-child')) {
                    sub.classList.remove('show');
                    btn.classList.remove('open');
                }
            });
        });

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