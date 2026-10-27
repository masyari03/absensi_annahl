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

if ($currentRole !== 'super_admin') {
    $allowedUnitIds = getUserAllowedUnitIds($pdo, $userId, $currentRole);
    if (!empty($allowedUnitIds) && !in_array($unitId, $allowedUnitIds, true)) {
        $unitId = $allowedUnitIds[0];
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
