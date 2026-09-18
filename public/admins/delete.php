<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$id = (int)($_POST['id'] ?? 0);

if ($id === currentUserId()) {
    die('Anda tidak dapat menghapus akun sendiri.');
}

// Cek dan Hapus file foto
$stmt = $pdo->prepare("SELECT photo FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$admin = $stmt->fetch();

if ($admin && !empty($admin['photo'])) {
    $uploadDir = '../../uploads/admins/';
    if (file_exists($uploadDir . $admin['photo'])) {
        unlink($uploadDir . $admin['photo']);
    }
}

// Update ke Database
$stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ? AND role = 'admin'");
$stmt->execute([$id]);

flash('success', 'Admin berhasil dihapus.');
redirect('index.php');