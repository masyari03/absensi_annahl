<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_staff');
$currentRole = currentRole();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$staffId = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'toggle_admin';

$stmt = $pdo->prepare("SELECT user_id, name, unit_id FROM staff WHERE id = ? LIMIT 1");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();

if (!$staff || empty($staff['user_id'])) {
    flash('error', 'Gagal merubah role. Akun pengguna tidak ditemukan.');
    redirect('index.php');
    exit;
}

// Cek role pengguna saat ini di tabel users
$stmtUser = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmtUser->execute([$staff['user_id']]);
$user = $stmtUser->fetch();

if (!$user) {
    flash('error', 'Terjadi kesalahan saat memproses data pengguna.');
    redirect('index.php');
    exit;
}

// BENTENG KEAMANAN: Pastikan Kepala Sekolah tidak membajak staff unit lain
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([currentUserId()]);
    $ksUnitId = $stmtKs->fetchColumn();

    if ($staff['unit_id'] != $ksUnitId) {
        flash('error', 'Akses ditolak. Staff ini berada di luar kendali unit Anda.');
        redirect('index.php');
        exit;
    }
}

// LOGIKA JADIKAN KEPALA SEKOLAH
if ($action === 'make_kepsek') {
    if ($currentRole !== 'super_admin') {
        flash('error', 'Hanya Super Admin yang dapat mengangkat Kepala Sekolah.');
        redirect('index.php');
        exit;
    }
    if ($user['role'] === 'admin') {
        flash('error', 'Admin tidak bisa langsung dijadikan Kepala Sekolah. Jadikan staff terlebih dahulu.');
        redirect('index.php');
        exit;
    }
    if (empty($staff['unit_id'])) {
        flash('error', 'Staff belum memiliki Unit. Edit staff terlebih dahulu untuk menetapkan Unit.');
        redirect('index.php');
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET role = 'kepala_sekolah' WHERE id = ?")->execute([$staff['user_id']]);
        $pdo->prepare("DELETE FROM admin_unit_permissions WHERE user_id = ?")->execute([$staff['user_id']]);
        $pdo->prepare("INSERT INTO admin_unit_permissions (user_id, unit_id) VALUES (?, ?)")->execute([$staff['user_id'], $staff['unit_id']]);
        
        // Sinkronkan user_roles & bersihkan override menu agar mengikuti default role kepala_sekolah
        $kepsekRoleId = $pdo->query("SELECT id FROM roles WHERE role_key = 'kepala_sekolah' LIMIT 1")->fetchColumn();
        if ($kepsekRoleId) {
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$staff['user_id']]);
            $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 1)")->execute([$staff['user_id'], (int)$kepsekRoleId]);
        }
        $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?")->execute([$staff['user_id']]);

        $pdo->commit();
        flash('success', "{$staff['name']} berhasil diangkat menjadi Kepala Sekolah.");
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'Terjadi kesalahan sistem.');
    }
    redirect('index.php');
    exit;
}

// LOGIKA TOGGLE (ADMIN <-> STAFF)
if ($action === 'toggle_admin') {
    $newRole = ($user['role'] === 'admin') ? 'staff' : 'admin';
    
    $updateStmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
    $updateStmt->execute([$newRole, $staff['user_id']]);
    
    // Bersihkan akses kelas/grade admin jika jabatannya diturunkan kembali jadi staff biasa
    if ($newRole === 'staff') {
        $pdo->prepare("DELETE FROM admin_grade_permissions WHERE user_id = ?")->execute([$staff['user_id']]);
        $pdo->prepare("DELETE FROM admin_class_permissions WHERE user_id = ?")->execute([$staff['user_id']]);
    }

    // Sinkronkan user_roles & reset override menu ke default role baru
    $stmtRoleId = $pdo->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
    $stmtRoleId->execute([$newRole]);
    $newRoleId = (int)$stmtRoleId->fetchColumn();
    if ($newRoleId > 0) {
        $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$staff['user_id']]);
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 1)")->execute([$staff['user_id'], $newRoleId]);
    }
    $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?")->execute([$staff['user_id']]);

    flash('success', "Akses untuk " . $staff['name'] . " berhasil diubah menjadi " . ucfirst($newRole) . ".");
    redirect('index.php');
    exit;
}