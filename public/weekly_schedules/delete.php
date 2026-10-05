<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_schedules');
$currentRole = currentRole();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $userId = currentUserId();
    
    if ($id > 0) {
        // BENTENG KEAMANAN HAPUS
        if ($currentRole === 'kepala_sekolah') {
            $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
            $stmtKs->execute([$userId]);
            $ksUnitId = $stmtKs->fetchColumn();

            $stmtSch = $pdo->prepare("SELECT unit_id FROM weekly_schedules WHERE id = ?");
            $stmtSch->execute([$id]);
            $schUnitId = $stmtSch->fetchColumn();

            if ($schUnitId != $ksUnitId) {
                flash('error', 'Akses ditolak. Anda tidak berhak menghapus jadwal unit lain.');
                header('Location: index.php');
                exit;
            }
        }

        $stmtOld = $pdo->prepare("SELECT * FROM weekly_schedules WHERE id = ?");
        $stmtOld->execute([$id]);
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        if ($oldData) {
            $stmtSt = $pdo->prepare("SELECT student_id FROM weekly_schedule_students WHERE weekly_schedule_id = ?");
            $stmtSt->execute([$id]);
            $oldData['student_ids'] = $stmtSt->fetchAll(PDO::FETCH_COLUMN);

            recordActivityAudit($pdo, 'jadwal', 'DELETE', 'weekly_schedules', $id, "Hapus Jadwal Pekanan ID #$id", $oldData, null, $oldData['unit_id'] ?? null);
        }

        $retTab = 'student';
        if (!empty($oldData)) {
            if ($oldData['schedule_type'] === 'eskul') {
                $retTab = 'eskul';
            } elseif ($oldData['target_type'] === 'staff') {
                $retTab = 'staff';
            }
        }
        flash('success', 'Jadwal berhasil dihapus.');
        header('Location: index.php?tab=' . $retTab);
        exit;
    }
}

header('Location: index.php');
exit;
