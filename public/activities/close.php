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

// BENTENG KEAMANAN WEWENANG UNIT
$allowedUnitIds = getUserAllowedUnitIds($pdo, $userId, $currentRole);

$stmtAct = $pdo->prepare("SELECT unit_id, target_type FROM activities WHERE id = ?");
$stmtAct->execute([$id]);
$actData = $stmtAct->fetch(PDO::FETCH_ASSOC);

if (!$actData) {
    flash('error', 'Kegiatan tidak ditemukan.');
    redirect('index.php');
    exit;
}

$actUnitId = (int)($actData['unit_id'] ?? 0);
$targetType = $actData['target_type'] ?? 'student';

if (!empty($allowedUnitIds) && !in_array($actUnitId, $allowedUnitIds, true)) {
    flash('error', 'Akses ditolak. Anda tidak berhak menutup kegiatan unit lain.');
    redirect('index.php?tab=' . urlencode($targetType));
    exit;
}

$tab = $_POST['tab'] ?? $targetType;
if (!in_array($tab, ['student', 'staff'])) {
    $tab = 'student';
}

$stmt = $pdo->prepare("UPDATE activities SET status = 'closed' WHERE id = ?");
$stmt->execute([$id]);

if (function_exists('recordActivityAudit')) {
    recordActivityAudit($pdo, 'activities', 'UPDATE', 'activities', $id, "Tutup Kegiatan Khusus #{$id}", null, ['status' => 'closed']);
}

flash('success', 'Kegiatan berhasil ditutup.');
redirect('index.php?tab=' . urlencode($tab));
