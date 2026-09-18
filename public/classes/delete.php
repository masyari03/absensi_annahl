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
$userId = currentUserId();

// BENTENG KEAMANAN HAPUS (Khusus Kepsek)
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    $stmtClass = $pdo->prepare("
        SELECT g.unit_id 
        FROM class_groups cg 
        INNER JOIN grades g ON g.id = cg.grade_id 
        WHERE cg.id = ? LIMIT 1
    ");
    $stmtClass->execute([$id]);
    $classUnitId = $stmtClass->fetchColumn();

    if ($classUnitId != $ksUnitId) {
        flash('error', 'Akses ditolak. Anda tidak berhak menghapus subkelas dari unit lain.');
        redirect('index.php');
        exit;
    }
}

// Pengecekan keamanan: Jangan hapus jika masih digunakan siswa
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_enrollments WHERE class_group_id = ?");
$stmt->execute([$id]);
$count = (int)$stmt->fetchColumn();

if ($count > 0) {
    flash('error', 'Subkelas tidak bisa dihapus karena masih digunakan oleh siswa (terdaftar pada enrollment).');
    redirect('index.php');
    exit;
}

$stmt = $pdo->prepare("DELETE FROM class_groups WHERE id = ?");
$stmt->execute([$id]);

flash('success', 'Subkelas berhasil dihapus.');
redirect('index.php');