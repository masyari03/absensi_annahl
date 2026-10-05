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
    SELECT s.id, s.name, s.nis, cg.name AS class_name, g.grade, cg.id AS class_group_id, g.id AS grade_id
    FROM students s
    INNER JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    INNER JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    WHERE s.deleted_at IS NULL AND g.unit_id = ?
    ORDER BY g.sort_order, cg.name, s.name ASC
");
$stmt->execute([$unitId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($students);
exit;
