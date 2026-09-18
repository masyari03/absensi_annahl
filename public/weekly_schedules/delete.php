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

        $stmt = $pdo->prepare("DELETE FROM weekly_schedules WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'Jadwal pekanan berhasil dihapus.');
    }
}

header('Location: index.php');
exit;