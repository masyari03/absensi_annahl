<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_admins');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$id = (int)($_POST['id'] ?? 0);

if ($id === currentUserId()) {
    flash('error', 'Anda tidak dapat mengubah peran akun Anda sendiri.');
    redirect('index.php');
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Ubah role menjadi staff
    $stmt = $pdo->prepare("UPDATE users SET role = 'staff' WHERE id = ? AND role = 'admin'");
    $stmt->execute([$id]);

    // 2. Bersihkan seluruh hak akses grade & class admin tersebut
    $stmtGrade = $pdo->prepare("DELETE FROM admin_grade_permissions WHERE user_id = ?");
    $stmtGrade->execute([$id]);
    
    $stmtClass = $pdo->prepare("DELETE FROM admin_class_permissions WHERE user_id = ?");
    $stmtClass->execute([$id]);

    // 3. Sinkronkan user_roles & bersihkan override menu agar bersih mengikuti default role staff
    $stmtStaffRoleId = $pdo->query("SELECT id FROM roles WHERE role_key = 'staff' LIMIT 1")->fetchColumn();
    if ($stmtStaffRoleId) {
        $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 1)")->execute([$id, (int)$stmtStaffRoleId]);
    }

    $stmtDelUma = $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?");
    $stmtDelUma->execute([$id]);

    $pdo->commit();
    flash('success', 'Admin berhasil diubah menjadi Staff. Hak akses pemantau telah dicabut.');

} catch (Exception $e) {
    $pdo->rollBack();
    flash('error', 'Terjadi kesalahan saat mengubah peran admin.');
}

redirect('index.php');