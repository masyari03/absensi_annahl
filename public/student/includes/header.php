<?php
// public/student/includes/header.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../config/security.php';
require_once __DIR__ . '/../../../includes/functions.php';

requireStudent();

$pageTitle = $pageTitle ?? 'Portal Siswa';
$flash = getFlash();

$currentUserId = (int)$_SESSION['user_id'];
$studentId = (int)($_SESSION['student_id'] ?? 0);

// Tarik data lengkap siswa dari DB
$stmtStudent = $pdo->prepare("
    SELECT s.*, cg.name as class_name, g.grade, un.unit as unit_name,
           u.username, u.photo as user_photo
    FROM students s
    INNER JOIN users u ON u.id = ?
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    LEFT JOIN units un ON un.id = g.unit_id
    WHERE s.id = ? LIMIT 1
");
$stmtStudent->execute([$currentUserId, $studentId]);
$studentData = $stmtStudent->fetch(PDO::FETCH_ASSOC);

// Pengaturan Master dari Super Admin
$canChangeUsername = (getSystemSetting($pdo, 'student_can_change_username', '0') === '1');
$canChangePhoto = (getSystemSetting($pdo, 'student_can_change_photo', '0') === '1');
$webPermitEnabled = (getSystemSetting($pdo, 'student_web_permit_enabled', '1') === '1');

$studentPhoto = $studentData['photo'] ?? $studentData['user_photo'] ?? null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | Portal Siswa AIS</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/favicon.png">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
/* Header & Dropdown Siswa */
.student-header {
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    padding: 12px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.student-profile-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    padding: 6px 12px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    transition: all 0.2s;
    user-select: none;
}
.student-profile-btn:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}
.student-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    overflow: hidden;
    background: #e0f2fe;
    color: #0284c7;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.06);
}
.student-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: 24px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
    min-width: 250px;
    z-index: 1000;
    overflow: hidden;
}
.student-dropdown-header {
    padding: 14px 16px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
.student-dropdown a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 16px;
    color: #334155;
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 500;
    transition: all 0.15s;
}
.student-dropdown a:hover {
    background: #f0f9ff;
    color: #0284c7;
}
.student-dropdown a.danger {
    color: #ef4444;
    border-top: 1px solid #f1f5f9;
}
.student-dropdown a.danger:hover {
    background: #fef2f2;
    color: #dc2626;
}
/* Modal Overlay */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.6);
    z-index: 2000;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(3px);
}
.modal-box {
    background: #ffffff;
    padding: 24px;
    border-radius: 14px;
    width: 100%;
    max-width: 440px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);
}
</style>
</head>
<body style="background: #f8fafc; margin: 0; font-family: system-ui, -apple-system, sans-serif;">

<div class="app">
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    
    <div class="main" style="min-height: 100vh; display: flex; flex-direction: column;">
        
        <!-- Header Topbar Siswa -->
        <header class="student-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="btn-hamburger" id="btnHamburger" onclick="toggleStudentSidebar()" style="display: none; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; width: 40px; height: 40px; align-items: center; justify-content: center; cursor: pointer;">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <h2 style="margin: 0; font-size: 20px; font-weight: 800; color: #0f172a;"><?= e($pageTitle) ?></h2>
                    <span style="font-size: 12.5px; color: #64748b;"><?= date('l, d F Y') ?></span>
                </div>
            </div>

            <!-- Header Right Profile & Dropdown -->
            <div style="position: relative;">
                <div class="student-profile-btn" onclick="toggleStudentDropdown(event)">
                    <div class="student-avatar">
                        <?php if (!empty($studentPhoto)): ?>
                            <img src="<?= UPLOAD_URL ?>/students/<?= e($studentPhoto) ?>" alt="Foto" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <?= strtoupper(substr($studentData['name'] ?? $_SESSION['name'] ?? 'S', 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: left;">
                        <strong style="display: block; font-size: 13.5px; color: #0f172a; line-height: 1.2;"><?= e($studentData['name'] ?? $_SESSION['name'] ?? 'Siswa') ?></strong>
                        <small style="font-size: 11.5px; color: #64748b;">
                            <?= e($studentData['grade'] ?? '') ?> <?= e($studentData['class_name'] ?? '') ?> • NIS: <?= e($studentData['nis'] ?? '-') ?>
                        </small>
                    </div>
                    <i class="fa-solid fa-chevron-down" style="font-size: 11px; color: #94a3b8; margin-left: 4px;"></i>
                </div>

                <!-- Dropdown Menu -->
                <div class="student-dropdown" id="studentDropdownMenu">
                    <div class="student-dropdown-header">
                        <div style="font-weight: 700; color: #0f172a; font-size: 13.5px;"><?= e($studentData['name'] ?? '') ?></div>
                        <div style="font-size: 12px; color: #64748b;">Username: <strong>@<?= e($_SESSION['username'] ?? '') ?></strong></div>
                        <div style="font-size: 11px; color: #0284c7; font-weight: 600; margin-top: 3px;"><?= e($studentData['unit_name'] ?? '') ?></div>
                    </div>

                    <!-- TOMBOL GANTI USERNAME: KONDISIONAL DIATUR SUPER ADMIN -->
                    <?php if ($canChangeUsername): ?>
                        <a href="#" onclick="openModal('usernameModal'); return false;">
                            <i class="fa-solid fa-user-pen" style="color: #0284c7;"></i> Ganti Username
                        </a>
                    <?php endif; ?>

                    <!-- TOMBOL GANTI PASSWORD: SELALU ADA -->
                    <a href="#" onclick="openModal('passwordModal'); return false;">
                        <i class="fa-solid fa-key" style="color: #f59e0b;"></i> Ganti Password
                    </a>

                    <!-- TOMBOL GANTI FOTO: KONDISIONAL DIATUR SUPER ADMIN -->
                    <?php if ($canChangePhoto): ?>
                        <a href="#" onclick="openModal('photoModal'); return false;">
                            <i class="fa-solid fa-camera" style="color: #10b981;"></i> Ganti Foto Profil
                        </a>
                    <?php endif; ?>

                    <a href="<?= BASE_URL ?>/logout.php" class="danger">
                        <i class="fa-solid fa-right-from-bracket"></i> Keluar (Logout)
                    </a>
                </div>
            </div>
        </header>

        <!-- Flash Alert -->
        <?php if ($flash): ?>
            <div style="margin: 16px 24px 0 24px;">
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?>" style="margin: 0;">
                    <?= e($flash['message']) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Container Konten Utama -->
        <div style="padding: 24px; flex: 1;">

<!-- MODAL GANTI USERNAME (HANYA AKTIF JIKA SUPER ADMIN MENGIZINKAN) -->
<?php if ($canChangeUsername): ?>
<div class="modal-overlay" id="usernameModal" onclick="closeModal('usernameModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;">Ganti Username Siswa</h3>
            <button type="button" onclick="closeModal('usernameModal')" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <form action="<?= BASE_URL ?>/update_account.php" method="POST">
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px;">Username Saat Ini</label>
                <input type="text" value="<?= e($_SESSION['username'] ?? '') ?>" disabled style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; font-size: 14px; color: #64748b;">
            </div>
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px;">Username Baru <span style="color: #ef4444;">*</span></label>
                <input type="text" name="username" required placeholder="Masukkan username baru..." style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;">
                <small style="color: #64748b; font-size: 11.5px; display: block; margin-top: 4px;">Gunakan huruf atau angka unik tanpa spasi.</small>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn" style="background: #e2e8f0; color: #475569;" onclick="closeModal('usernameModal')">Batal</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan Username</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL GANTI PASSWORD -->
<div class="modal-overlay" id="passwordModal" onclick="closeModal('passwordModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;">Ganti Password Akun</h3>
            <button type="button" onclick="closeModal('passwordModal')" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <form action="<?= BASE_URL ?>/update_account.php" method="POST">
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px;">Password Baru <span style="color: #ef4444;">*</span></label>
                <input type="password" name="password" required placeholder="Masukkan password baru..." style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;">
                <small style="color: #64748b; font-size: 11.5px; display: block; margin-top: 4px;">Minimal 6 karakter demi keamanan akun Anda.</small>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn" style="background: #e2e8f0; color: #475569;" onclick="closeModal('passwordModal')">Batal</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan Password</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL GANTI FOTO (HANYA AKTIF JIKA SUPER ADMIN MENGIZINKAN) -->
<?php if ($canChangePhoto): ?>
<div class="modal-overlay" id="photoModal" onclick="closeModal('photoModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;">Ganti Foto Profil Siswa</h3>
            <button type="button" onclick="closeModal('photoModal')" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <form action="<?= BASE_URL ?>/update_photo.php" method="POST" enctype="multipart/form-data">
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px;">Pilih File Foto Baru</label>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required style="width: 100%; padding: 10px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc;">
                <small style="color: #64748b; font-size: 11.5px; display: block; margin-top: 4px;">Format: JPG, PNG, WEBP (Maksimal 2 MB).</small>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn" style="background: #e2e8f0; color: #475569;" onclick="closeModal('photoModal')">Batal</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 700;">Upload Foto</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function toggleStudentDropdown(e) {
    e.stopPropagation();
    const menu = document.getElementById('studentDropdownMenu');
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}

document.addEventListener('click', function() {
    const menu = document.getElementById('studentDropdownMenu');
    if (menu) menu.style.display = 'none';
});

function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
</script>
