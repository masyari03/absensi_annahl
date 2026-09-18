<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

$currentRole = $_SESSION['role'] ?? '';
if (!in_array($currentRole, ['super_admin', 'kepala_sekolah'])) {
    redirect('../dashboard.php');
}

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

    $pdo->commit();
    flash('success', 'Admin berhasil diubah menjadi Staff. Hak akses pemantau telah dicabut.');

} catch (Exception $e) {
    $pdo->rollBack();
    flash('error', 'Terjadi kesalahan saat mengubah peran admin.');
}

redirect('index.php');