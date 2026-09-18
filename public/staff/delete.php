<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('index.php'); }

$id = (int)($_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT user_id, photo FROM staff WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$staff = $stmt->fetch();

if ($staff) {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE staff SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    if (!empty($staff['user_id'])) {
        $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$staff['user_id']]);
    }
    
    // Hapus file fisik foto
    $uploadDir = '../../uploads/staff/';
    if (!empty($staff['photo']) && file_exists($uploadDir . $staff['photo'])) {
        unlink($uploadDir . $staff['photo']);
    }

    $pdo->commit();
    flash('success', 'Staff berhasil dihapus.');
}

redirect('index.php');