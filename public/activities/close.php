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

// BENTENG KEAMANAN
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    $stmtAct = $pdo->prepare("SELECT unit_id FROM activities WHERE id = ?");
    $stmtAct->execute([$id]);
    $actUnitId = $stmtAct->fetchColumn();

    if ($actUnitId != $ksUnitId) {
        flash('error', 'Akses ditolak. Anda tidak bisa menutup jadwal kegiatan unit lain.');
        redirect('index.php');
        exit;
    }
}

$stmt = $pdo->prepare("UPDATE activities SET status = 'closed' WHERE id = ?");
$stmt->execute([$id]);

flash('success', 'Kegiatan berhasil ditutup.');
redirect('index.php');