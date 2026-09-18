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

// BENTENG KEAMANAN: Pastikan Kepala Sekolah hanya menghapus Grade di unit miliknya
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    $stmtGrade = $pdo->prepare("SELECT unit_id FROM grades WHERE id = ?");
    $stmtGrade->execute([$id]);
    $gradeUnitId = $stmtGrade->fetchColumn();

    if ($gradeUnitId != $ksUnitId) {
        flash('error', 'Akses ditolak. Anda tidak berhak menghapus grade dari unit lain.');
        redirect('index.php');
        exit;
    }
}

// Pengecekan keamanan: Jangan hapus jika masih ada relasi subkelas
$stmt = $pdo->prepare("SELECT COUNT(*) FROM class_groups WHERE grade_id = ?");
$stmt->execute([$id]);
$count = (int)$stmt->fetchColumn();

if ($count > 0) {
    flash('error', 'Grade tidak bisa dihapus karena masih terhubung dengan subkelas aktif.');
    redirect('index.php');
    exit;
}

// Eksekusi Hapus
$stmt = $pdo->prepare("DELETE FROM grades WHERE id = ?");
$stmt->execute([$id]);

flash('success', 'Grade berhasil dihapus.');
redirect('index.php');