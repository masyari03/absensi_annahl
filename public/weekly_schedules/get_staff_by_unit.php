<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
header('Content-Type: application/json');

$unitId = (int)($_GET['unit_id'] ?? 0);
$currentRole = currentRole();
$userId = currentUserId();

if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();
    if ($unitId !== $ksUnitId) {
        $unitId = $ksUnitId;
    }
}

if ($unitId <= 0) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.nik, u.unit as unit_name
    FROM staff s
    LEFT JOIN units u ON u.id = s.unit_id
    WHERE s.deleted_at IS NULL AND s.unit_id = ?
    ORDER BY s.name ASC
");
$stmt->execute([$unitId]);
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($staff);
exit;
