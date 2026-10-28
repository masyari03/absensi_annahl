<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

$pageTitle = $pageTitle ?? 'Absensi An-Nahl';
$flash = getFlash();

$currentUserId = $_SESSION['user_id'] ?? 0;
$userPhoto = null;
if ($currentUserId > 0 && isset($pdo)) {
    $stmtPhoto = $pdo->prepare("SELECT photo FROM users WHERE id = ?");
    $stmtPhoto->execute([$currentUserId]);
    $userPhoto = $stmtPhoto->fetchColumn();

    if (function_exists('recordUserActivity')) {
        recordUserActivity($pdo, (int)$currentUserId);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | Absensi An-Nahl Islamic School</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/favicon.png">
<link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/logo.jpg">
<link rel="shortcut icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/logo.jpg">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    /* CSS Dropdown & Modal Profil */
    .profile-container { position: relative; display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 5px 10px; border-radius: 8px; transition: 0.2s; border: 1px solid transparent; }
    .profile-container:hover { background: #f8fafc; border-color: #e2e8f0; }
    .dropdown-menu { display: none; position: absolute; top: 110%; right: 0; background: #fff; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; border: 1px solid #e5e7eb; min-width: 200px; z-index: 1000; overflow: hidden; }
    .dropdown-menu a { display: block; padding: 12px 15px; color: #374151; text-decoration: none; font-size: 14px; transition: 0.2s; }
    .dropdown-menu a:hover { background: #f0f9ff; color: #0284c7; }
    
    /* CSS Notifikasi Bell (Plan Edisi 14) */
    .header-actions { display: flex; align-items: center; gap: 12px; }
    .notification-wrapper { position: relative; }
    .notification-bell-btn { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #475569; position: relative; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    .notification-bell-btn:hover { background: #f8fafc; color: #0284c7; border-color: #0284c7; box-shadow: 0 4px 6px -1px rgba(2,132,199,0.1); }
    .notification-badge { position: absolute; top: -5px; right: -5px; background: #ef4444; color: #ffffff; font-size: 11px; font-weight: 800; height: 18px; min-width: 18px; padding: 0 5px; border-radius: 9px; display: flex; align-items: center; justify-content: center; border: 2px solid #ffffff; box-shadow: 0 2px 4px rgba(239,68,68,0.4); }
    .notification-dropdown { display: none; position: absolute; top: calc(100% + 10px); right: 0; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 12px 28px -4px rgba(15,23,42,0.15); width: 340px; max-width: 90vw; z-index: 1100; overflow: hidden; animation: fadeInDropdown 0.2s ease; }
    @keyframes fadeInDropdown { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
    .notif-item { display: flex; gap: 12px; padding: 12px 14px; border-bottom: 1px solid #f1f5f9; text-decoration: none; color: inherit; transition: background 0.15s; }
    .notif-item:hover { background: #f8fafc; }
    .notif-item.unread { background: #f0f9ff; }
    .notif-item.unread:hover { background: #e0f2fe; }
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 2000; justify-content: center; align-items: center; }
    .modal-box { background: #fff; padding: 25px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); transform: translateY(0); transition: transform 0.3s ease; }

    /* --- GAYA BARU: TOMBOL HAMBURGER & TOMBOL X SIDEBAR --- */
    .app { display: flex; transition: 0.3s; }
    .main { flex: 1; transition: 0.3s; width: 100%; }
    
    /* Tombol Hamburger Header (Modern) */
    .btn-hamburger {
        display: none; /* Sembunyikan default di layar besar */
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        color: #475569;
        width: 42px;
        height: 42px;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-right: 15px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        user-select: none;
        -webkit-tap-highlight-color: transparent;
    }
    .btn-hamburger:hover {
        background: #f8fafc;
        color: #0284c7;
        border-color: #0284c7;
        box-shadow: 0 4px 6px -1px rgba(2,132,199,0.1);
    }
    .btn-hamburger svg {
        pointer-events: none;
    }

    /* Tombol X Sidebar (Modern) */
    .btn-close-sidebar {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #cbd5e1;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        user-select: none;
        -webkit-tap-highlight-color: transparent;
        z-index: 10;
    }
    .btn-close-sidebar:hover {
        background: #ef4444;
        border-color: #ef4444;
        color: #ffffff;
    }
    .btn-close-sidebar svg {
        pointer-events: none;
    }

    /* Backdrop Layar Gelap Saat Sidebar Aktif di HP */
    .sidebar-backdrop {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(15, 23, 42, 0.65);
        /* backdrop-filter dihapus – mencegah GPU compositing glitch saat scroll */
        z-index: 9998;
        opacity: 0;
        cursor: pointer;
        transition: opacity 0.25s ease;
        -webkit-tap-highlight-color: transparent;
    }
    .sidebar-backdrop.show {
        display: block !important;
        opacity: 1 !important;
    }

    @media (min-width: 993px) {
        body.sidebar-closed #sidebar { display: none !important; }
        body.sidebar-closed .main { margin-left: 0 !important; width: 100% !important; }
        body.sidebar-closed .btn-hamburger { display: flex !important; }
    }

    @media (max-width: 992px) {
        .btn-hamburger {
            display: flex !important; /* Selalu tampil di HP / Tablet */
            z-index: 100;
        }
        #sidebar {
            position: fixed !important;
            top: 0 !important;
            bottom: 0 !important;
            left: 0 !important;
            width: 280px !important;
            max-width: 85vw !important;
            transform: translateX(-100%) !important;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            z-index: 9999 !important;
            box-shadow: 10px 0 30px rgba(0,0,0,0.5) !important;
            display: block !important;
        }
        #sidebar.active {
            transform: translateX(0) !important;
        }
        .main {
            margin-left: 0 !important;
            width: 100% !important;
        }
    }
    /* ===== MODAL POPUP (custom-modal) GLOBAL =====
       Definisi kelas modal dipindahkan ke header global agar seluruh halaman
       (mis. Supervisi: Tambah/Edit Jadwal, Kategori & Butir Rubrik) menampilkan
       form sebagai popup overlay, bukan blok biasa di bawah tabel. */
    .custom-modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(15, 23, 42, 0.75);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 20px;
        overflow: hidden;
        -webkit-overflow-scrolling: touch;
    }
    .custom-modal-box {
        background: #ffffff;
        border-radius: 16px;
        width: 100%;
        max-width: 900px;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }
    .custom-modal-box > form {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }
    @keyframes modalSlideIn {
        from { opacity: 0; transform: scale(0.95) translateY(10px); }
        to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .custom-modal-header {
        padding: 18px 24px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-shrink: 0;
    }
    .custom-modal-body {
        padding: 24px;
        overflow-y: auto;
        flex: 1;
        min-height: 0;
    }
    .custom-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        background: #f8fafc;
        border-radius: 0 0 16px 16px;
        flex-shrink: 0;
    }
    @media (max-width: 768px) {
        .custom-modal-overlay { padding: 10px; }
        .custom-modal-box { max-height: calc(100vh - 20px); border-radius: 14px; }
        .custom-modal-header { padding: 14px 16px; }
        .custom-modal-body { padding: 16px 14px; }
        .custom-modal-footer { padding: 12px 14px; flex-wrap: wrap; }
        .custom-modal-footer .btn { width: 100%; }
    }
</style>
</head>
<body>

<div class="app">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebarMenu(event)"></div>
<main class="main">

<header class="topbar" style="display: flex; justify-content: space-between; align-items: center;">
    <div style="display: flex; align-items: center;">
        <!-- TOMBOL HAMBURGER YANG LEBIH INDAH & RESPONSIF -->
        <button id="menuButton" class="btn-hamburger" onclick="toggleSidebarMenu(event)" title="Buka Menu" type="button">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="pointer-events: none;">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
        <div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 700; color: #1e293b;"><?= e($pageTitle) ?></h2>
            <span class="date-text" style="color: #64748b; font-size: 13px;"><?= date('d F Y') ?></span>
        </div>
    </div>

    <div class="header-actions" style="display: flex; align-items: center; gap: 12px;">
        <!-- NOTIFIKASI BELL DROPDOWN -->
        <?php 
        $unreadNotifCount = 0;
        if ($currentUserId > 0 && isset($pdo) && function_exists('getUnreadNotificationCount')) {
            $unreadNotifCount = getUnreadNotificationCount($pdo, (int)$currentUserId);
        }
        ?>
        <div class="notification-wrapper" style="position: relative;">
            <button type="button" class="notification-bell-btn" id="notifBellBtn" onclick="toggleNotifDropdown(event)" title="Notifikasi">
                <i class="fa-solid fa-bell" style="font-size: 18px;"></i>
                <span id="notifBadge" class="notification-badge" style="<?= $unreadNotifCount > 0 ? '' : 'display:none;' ?>">
                    <?= $unreadNotifCount > 99 ? '99+' : $unreadNotifCount ?>
                </span>
            </button>

            <!-- DROPDOWN NOTIFIKASI -->
            <div class="notification-dropdown" id="notifDropdown">
                <div style="padding: 12px 16px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
                    <strong style="color: #0f172a; font-size: 14px;">Pemberitahuan</strong>
                    <button type="button" onclick="markAllNotificationsReadAjax(event)" style="background: none; border: none; color: #0284c7; font-size: 12px; font-weight: 700; cursor: pointer; padding: 0;">
                        Tandai semua dibaca
                    </button>
                </div>
                <div id="notifDropdownList" style="max-height: 320px; overflow-y: auto;">
                    <div style="padding: 24px; text-align: center; color: #94a3b8; font-size: 13px;">
                        <i class="fa-solid fa-spinner fa-spin" style="margin-right: 6px;"></i> Memuat notifikasi...
                    </div>
                </div>
                <div style="padding: 10px 16px; border-top: 1px solid #e2e8f0; text-align: center; background: #f8fafc;">
                    <a href="<?= BASE_URL ?>/notifications/index.php" style="color: #0284c7; text-decoration: none; font-size: 13px; font-weight: 700;">
                        Lihat Semua Notifikasi <i class="fa-solid fa-arrow-right" style="font-size: 11px;"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="profile-container" onclick="toggleDropdown(event)">
            <div class="avatar" style="overflow: hidden; display: flex; justify-content: center; align-items: center; padding: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <?php if (!empty($userPhoto)): ?>
                    <img src="<?= UPLOAD_URL ?>/admins/<?= e($userPhoto) ?>" 
                         alt="Profil" 
                         style="width: 100%; height: 100%; object-fit: cover;"
                         onerror="this.onerror=null; this.src='<?= BASE_URL ?>/photo.php?type=admin&file=<?= urlencode($userPhoto) ?>&name=<?= urlencode($_SESSION['name'] ?? 'U') ?>';">
                <?php else: ?>
                    <?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div class="profile-text">
                <strong style="color: #1e293b;"><?= e($_SESSION['name'] ?? '') ?></strong>
                <small style="color: #64748b;"><?= e(ucwords(str_replace('_', ' ', $_SESSION['role'] ?? '')) ) ?></small>
            </div>
            
            <!-- DROPDOWN MENU -->
            <div class="dropdown-menu" id="profileMenu">
                <a href="#" onclick="openModal('photoModal'); return false;">📷 Ubah Foto Profil</a>
                <a href="#" onclick="openModal('accountModal'); return false;">🔐 Ubah Akun (User & Pass)</a>
            </div>
        </div>
    </div>
</header>

<!-- MODAL FOTO PROFIL -->
<div class="modal-overlay" id="photoModal" onclick="closeModal('photoModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <h3 style="margin-top:0; border-bottom:1px solid #e5e7eb; padding-bottom:15px; margin-bottom:15px; color:#1e293b;">Ubah Foto Profil</h3>
        <form action="<?= BASE_URL ?>/update_photo.php" method="POST" enctype="multipart/form-data">
            <div style="margin-bottom: 20px;">
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required style="width:100%; padding:10px; border:1px dashed #cbd5e1; border-radius:8px; background: #f8fafc;">
                <small style="color:#6b7280; display:block; margin-top:8px;">Format didukung: JPG, PNG, WEBP</small>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-danger" onclick="closeModal('photoModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Foto</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL UBAH USERNAME & PASSWORD -->
<div class="modal-overlay" id="accountModal" onclick="closeModal('accountModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <h3 style="margin-top:0; border-bottom:1px solid #e5e7eb; padding-bottom:15px; margin-bottom:15px; color:#1e293b;">Ubah Akses Akun</h3>
        <form action="<?= BASE_URL ?>/update_account.php" method="POST">
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; color:#475569;">Username Baru</label>
                <input type="text" name="username" placeholder="Abaikan jika tidak ingin diubah" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; outline:none; transition:0.2s;" onfocus="this.style.borderColor='#0284c7'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
            <div style="margin-bottom: 25px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; color:#475569;">Password Baru</label>
                <input type="password" name="password" placeholder="Abaikan jika tidak ingin diubah" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; outline:none; transition:0.2s;" onfocus="this.style.borderColor='#0284c7'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-danger" onclick="closeModal('accountModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
// --- LOGIKA DROPDOWN & MODAL ---
function toggleDropdown(e) {
    let menu = document.getElementById('profileMenu');
    const nd = document.getElementById('notifDropdown');
    if (nd) nd.style.display = 'none';
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    e.stopPropagation();
}

function toggleNotifDropdown(e) {
    if (e) e.stopPropagation();
    const dd = document.getElementById('notifDropdown');
    const pm = document.getElementById('profileMenu');
    if (pm) pm.style.display = 'none';
    if (!dd) return;
    const isShow = (dd.style.display === 'block');
    dd.style.display = isShow ? 'none' : 'block';
    if (!isShow) {
        loadNotificationsAjax();
    }
}

function loadNotificationsAjax() {
    const list = document.getElementById('notifDropdownList');
    if (!list) return;
    fetch('<?= BASE_URL ?>/api/notifications.php?action=get_latest')
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            const badge = document.getElementById('notifBadge');
            if (badge) {
                if (data.unread_count > 0) {
                    badge.innerText = data.unread_count > 99 ? '99+' : data.unread_count;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }
            if (!data.notifications || data.notifications.length === 0) {
                list.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:13px;"><i class="fa-regular fa-bell-slash" style="font-size:22px; display:block; margin-bottom:8px;"></i>Tidak ada notifikasi baru</div>';
                return;
            }
            let html = '';
            data.notifications.forEach(n => {
                const isUnread = (parseInt(n.is_read) === 0);
                const targetLink = n.link_url && n.link_url.trim() !== '' ? n.link_url : '<?= BASE_URL ?>/notifications/index.php';
                html += `
                    <a href="${targetLink}" class="notif-item ${isUnread ? 'unread' : ''}" onclick="markNotificationReadAjax(${n.id})">
                        <div style="width:34px; height:34px; border-radius:8px; background:#f1f5f9; color:${n.color}; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:14px; margin-top:2px;">
                            <i class="fa-solid ${n.icon}"></i>
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:13px; font-weight:${isUnread ? '700' : '600'}; color:#0f172a; margin-bottom:2px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(n.title)}</span>
                                ${isUnread ? '<span style="width:6px; height:6px; border-radius:50%; background:#ef4444; flex-shrink:0;"></span>' : ''}
                            </div>
                            <div style="font-size:12px; color:#475569; line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">${escapeHtml(n.message)}</div>
                            <div style="font-size:11px; color:#94a3b8; margin-top:4px;">${n.time_ago}</div>
                        </div>
                    </a>
                `;
            });
            list.innerHTML = html;
        })
        .catch(() => {});
}

function markNotificationReadAjax(id) {
    if (!id) return;
    fetch('<?= BASE_URL ?>/api/notifications.php?action=mark_read&id=' + id, { method: 'POST' }).catch(() => {});
}

function markAllNotificationsReadAjax(e) {
    if (e) e.stopPropagation();
    fetch('<?= BASE_URL ?>/api/notifications.php?action=mark_all_read', { method: 'POST' })
        .then(() => {
            const badge = document.getElementById('notifBadge');
            if (badge) badge.style.display = 'none';
            loadNotificationsAjax();
        })
        .catch(() => {});
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('click', function() {
    const pm = document.getElementById('profileMenu');
    if (pm) pm.style.display = 'none';
    const nd = document.getElementById('notifDropdown');
    if (nd) nd.style.display = 'none';
});

setInterval(loadNotificationsAjax, 45000);

function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
    document.getElementById('profileMenu').style.display = 'none';
    const nd = document.getElementById('notifDropdown');
    if (nd) nd.style.display = 'none';
    // Kunci scroll body agar background tidak ikut scroll saat modal terbuka
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    // Cek apakah masih ada modal lain yang terbuka; jika tidak, kembalikan scroll
    const anyOpen = document.querySelector('.custom-modal-overlay[style*="flex"], .modal-overlay[style*="flex"]');
    if (!anyOpen) {
        document.body.style.overflow = '';
    }
}

// --- FUNGSI GLOBAL PENGONTROL SIDEBAR (DESKTOP & MOBILE) ---
let _sidebarBusy = false;

window.openSidebarMenu = function() {
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    if (sb) sb.classList.add('active');
    if (bd) bd.classList.add('show');
    document.body.style.overflow = 'hidden';
};

window.closeSidebarMenu = function(e) {
    if (e) {
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
        if (typeof e.preventDefault === 'function') e.preventDefault();
    }
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    
    // Tutup drawer di layar HP / tablet
    if (sb) sb.classList.remove('active');
    if (bd) bd.classList.remove('show');
    document.body.style.overflow = '';
    
    // Jika di layar desktop (> 992px), tombol X menyembunyikan sidebar desktop
    if (window.innerWidth > 992) {
        document.body.classList.add('sidebar-closed');
        try { localStorage.setItem('sidebarState', 'closed'); } catch(err) {}
    }
};

window.toggleSidebarMenu = function(e) {
    if (e) {
        if (typeof e.preventDefault === 'function') e.preventDefault();
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
    }
    
    // Cegah double triggering dari sentuhan mobile / klik ganda
    if (_sidebarBusy) return;
    _sidebarBusy = true;
    setTimeout(() => { _sidebarBusy = false; }, 250);

    const sb = document.getElementById('sidebar');
    if (window.innerWidth <= 992) {
        if (sb && sb.classList.contains('active')) {
            window.closeSidebarMenu(e);
        } else {
            window.openSidebarMenu();
        }
    } else {
        document.body.classList.toggle('sidebar-closed');
        const isClosed = document.body.classList.contains('sidebar-closed');
        try { localStorage.setItem('sidebarState', isClosed ? 'closed' : 'open'); } catch(err) {}
    }
};

document.addEventListener('DOMContentLoaded', () => {
    if (window.innerWidth > 992) {
        try {
            if (localStorage.getItem('sidebarState') === 'closed') {
                document.body.classList.add('sidebar-closed');
            }
        } catch(err) {}
    } else {
        document.body.classList.remove('sidebar-closed');
    }
});

window.addEventListener('resize', () => {
    if (window.innerWidth <= 992) {
        document.body.classList.remove('sidebar-closed');
    } else {
        // Jika beralih ke desktop, reset drawer mobile
        const sb = document.getElementById('sidebar');
        const bd = document.getElementById('sidebarBackdrop');
        if (sb) sb.classList.remove('active');
        if (bd) bd.classList.remove('show');
        document.body.style.overflow = '';
        try {
            if (localStorage.getItem('sidebarState') === 'closed') {
                document.body.classList.add('sidebar-closed');
            }
        } catch(err) {}
    }
});

// Menutup sidebar dengan tombol Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const sb = document.getElementById('sidebar');
        if (sb && sb.classList.contains('active')) {
            window.closeSidebarMenu();
        }
    }
});

// Activity Heartbeat & Background Auto Reminder WA (setiap 60-120 detik)
(function() {
    let lastPing = Date.now();
    let lastWaCheck = 0;
    function sendHeartbeat() {
        if (Date.now() - lastPing < 40000) return;
        lastPing = Date.now();
        fetch('<?= BASE_URL ?>/kelola/activity.php?action=heartbeat', { method: 'POST' }).catch(function(){});
        
        // Pengecekan pesan WA otomatis (Pesan 2 & 4) setiap 2 menit jika aktif
        if (Date.now() - lastWaCheck > 120000) {
            lastWaCheck = Date.now();
            fetch('<?= BASE_URL ?>/whatsapp/check_reminders.php').catch(function(){});
        }
    }
    setInterval(sendHeartbeat, 60000);
    window.addEventListener('focus', sendHeartbeat);
    window.addEventListener('click', sendHeartbeat);
})();
</script>

<div class="content">
<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" style="box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-radius: 8px;">
        <?= e($flash['message']) ?>
    </div>
<?php endif; ?>