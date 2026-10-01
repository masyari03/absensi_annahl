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
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
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
        backdrop-filter: blur(2px);
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

    <div class="profile-container" onclick="toggleDropdown(event)">
        <div class="avatar" style="overflow: hidden; display: flex; justify-content: center; align-items: center; padding: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <?php if (!empty($userPhoto)): ?>
                <img src="<?= UPLOAD_URL ?>/admins/<?= e($userPhoto) ?>" alt="Profil" style="width: 100%; height: 100%; object-fit: cover;">
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
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    e.stopPropagation();
}

document.addEventListener('click', function() {
    document.getElementById('profileMenu').style.display = 'none';
});

function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
    document.getElementById('profileMenu').style.display = 'none';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
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

// Activity Heartbeat — menjaga status online tetap akurat setiap 60 detik jika tab aktif
(function() {
    let lastPing = Date.now();
    function sendHeartbeat() {
        if (Date.now() - lastPing < 40000) return;
        lastPing = Date.now();
        fetch('<?= BASE_URL ?>/kelola/activity.php?action=heartbeat', { method: 'POST' }).catch(function(){});
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