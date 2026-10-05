<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_activities');
$currentRole = currentRole();
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

    $stmtAct = $pdo->prepare("SELECT unit_id, target_type FROM activities WHERE id = ?");
    $stmtAct->execute([$id]);
    $actData = $stmtAct->fetch(PDO::FETCH_ASSOC);
    $actUnitId = $actData['unit_id'] ?? null;
    $targetType = $actData['target_type'] ?? 'student';

    if ($actUnitId != $ksUnitId) {
        flash('error', 'Akses ditolak. Anda tidak bisa menutup jadwal kegiatan unit lain.');
        redirect('index.php?tab=' . urlencode($targetType));
        exit;
    }
} else {
    $stmtAct = $pdo->prepare("SELECT target_type FROM activities WHERE id = ?");
    $stmtAct->execute([$id]);
    $targetType = $stmtAct->fetchColumn() ?: 'student';
}

$tab = $_POST['tab'] ?? $targetType;
if (!in_array($tab, ['student', 'staff'])) {
    $tab = 'student';
}

$stmt = $pdo->prepare("UPDATE activities SET status = 'closed' WHERE id = ?");
$stmt->execute([$id]);

flash('success', 'Kegiatan berhasil ditutup.');
redirect('index.php?tab=' . urlencode($tab));
