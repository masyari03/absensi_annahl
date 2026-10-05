<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_schedules');

$currentRole = currentRole();
$userId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
    exit;
}

$action = trim($_POST['bulk_action_type'] ?? '');
$scheduleIds = $_POST['schedule_ids'] ?? [];
$tab = trim($_POST['tab'] ?? 'student');
if (!in_array($tab, ['student', 'staff', 'eskul'])) {
    $tab = 'student';
}
$returnUnit = (int)($_POST['unit_id'] ?? 0);
$redirectUrl = 'index.php?tab=' . urlencode($tab) . ($returnUnit > 0 && $currentRole === 'super_admin' ? '&unit_id=' . $returnUnit : '');

if (empty($scheduleIds) || !is_array($scheduleIds)) {
    flash('error', 'Silakan pilih minimal satu jadwal untuk diproses.');
    redirect($redirectUrl);
    exit;
}

// Sanitasi ID
$validIds = array_filter(array_map('intval', $scheduleIds), function($id) {
    return $id > 0;
});

if (empty($validIds)) {
    flash('error', 'Tidak ada ID jadwal valid yang dipilih.');
    redirect($redirectUrl);
    exit;
}

// BENTENG KEAMANAN KEPALA SEKOLAH
$ksUnitId = 0;
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();

    if ($ksUnitId <= 0) {
        flash('error', 'Akses ditolak. Unit Kepala Sekolah tidak valid.');
        redirect('index.php');
        exit;
    }

    // Filter hanya jadwal yang benar-benar milik unit Kepala Sekolah
    $inClause = implode(',', array_fill(0, count($validIds), '?'));
    $checkUnitStmt = $pdo->prepare("SELECT id FROM weekly_schedules WHERE id IN ($inClause) AND unit_id = ?");
    $checkParams = array_merge(array_values($validIds), [$ksUnitId]);
    $checkUnitStmt->execute($checkParams);
    $validIds = array_map('intval', $checkUnitStmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($validIds)) {
        flash('error', 'Akses ditolak. Anda tidak memiliki wewenang pada jadwal yang dipilih.');
        redirect($redirectUrl);
        exit;
    }
}

$count = count($validIds);
$inClause = implode(',', array_fill(0, $count, '?'));

$pdo->beginTransaction();
try {
    if ($action === 'activate') {
        // AKTIFKAN SEKALIGUS
        $stmt = $pdo->prepare("UPDATE weekly_schedules SET is_active = 'active' WHERE id IN ($inClause)");
        $stmt->execute(array_values($validIds));

        recordActivityAudit($pdo, 'jadwal', 'UPDATE', 'weekly_schedules', 0, 
            "Aktifkan Sekaligus: {$count} jadwal diaktifkan", 
            null, ['action' => 'bulk_activate', 'ids' => $validIds], 
            $ksUnitId ?: ($returnUnit ?: null)
        );

        $pdo->commit();
        flash('success', "Berhasil mengaktifkan {$count} jadwal terpilih.");
        redirect($redirectUrl);
        exit;

    } elseif ($action === 'deactivate') {
        // NONAKTIFKAN SEKALIGUS
        $stmt = $pdo->prepare("UPDATE weekly_schedules SET is_active = 'inactive' WHERE id IN ($inClause)");
        $stmt->execute(array_values($validIds));

        recordActivityAudit($pdo, 'jadwal', 'UPDATE', 'weekly_schedules', 0, 
            "Nonaktifkan Sekaligus: {$count} jadwal dinonaktifkan", 
            null, ['action' => 'bulk_deactivate', 'ids' => $validIds], 
            $ksUnitId ?: ($returnUnit ?: null)
        );

        $pdo->commit();
        flash('success', "Berhasil menonaktifkan {$count} jadwal terpilih.");
        redirect($redirectUrl);
        exit;

    } elseif ($action === 'delete') {
        // HAPUS SEKALIGUS
        $pdo->prepare("DELETE FROM weekly_schedule_students WHERE weekly_schedule_id IN ($inClause)")->execute(array_values($validIds));
        $pdo->prepare("DELETE FROM weekly_schedule_staff WHERE weekly_schedule_id IN ($inClause)")->execute(array_values($validIds));
        $pdo->prepare("DELETE FROM weekly_schedules WHERE id IN ($inClause)")->execute(array_values($validIds));

        recordActivityAudit($pdo, 'jadwal', 'DELETE', 'weekly_schedules', 0, 
            "Hapus Sekaligus: {$count} jadwal dihapus", 
            ['deleted_ids' => $validIds], null, 
            $ksUnitId ?: ($returnUnit ?: null)
        );

        $pdo->commit();
        flash('success', "Berhasil menghapus {$count} jadwal terpilih.");
        redirect($redirectUrl);
        exit;

    } elseif ($action === 'bulk_edit') {
        // EDIT MASSAL / SEKALIGUS
        $updates = [];
        $params = [];

        // 1. Perubahan Jam Masuk, Telat, Pulang
        if (!empty($_POST['apply_hours'])) {
            if ($tab === 'student') {
                $timeIn = trim($_POST['bulk_student_in'] ?? '');
                $timeLate = trim($_POST['bulk_student_late'] ?? '');
                $timeOut = trim($_POST['bulk_student_out'] ?? '');
                if ($timeIn !== '') { $updates[] = "student_in = ?"; $params[] = $timeIn; }
                if ($timeLate !== '') { $updates[] = "student_late = ?"; $params[] = $timeLate; }
                if ($timeOut !== '') { $updates[] = "student_out = ?"; $params[] = $timeOut; }
            } elseif ($tab === 'staff') {
                $timeIn = trim($_POST['bulk_staff_in'] ?? '');
                $timeLate = trim($_POST['bulk_staff_late'] ?? '');
                $timeOut = trim($_POST['bulk_staff_out'] ?? '');
                if ($timeIn !== '') { $updates[] = "staff_in = ?"; $params[] = $timeIn; }
                if ($timeLate !== '') { $updates[] = "staff_late = ?"; $params[] = $timeLate; }
                if ($timeOut !== '') { $updates[] = "staff_out = ?"; $params[] = $timeOut; }
            } elseif ($tab === 'eskul') {
                $timeIn = trim($_POST['bulk_eskul_in'] ?? '');
                $timeLate = trim($_POST['bulk_eskul_late'] ?? '');
                $timeOut = trim($_POST['bulk_eskul_out'] ?? '');
                if ($timeIn !== '') { $updates[] = "student_in = ?"; $params[] = $timeIn; }
                if ($timeLate !== '') { $updates[] = "student_late = ?"; $params[] = $timeLate; }
                if ($timeOut !== '') { $updates[] = "student_out = ?"; $params[] = $timeOut; }
            }
        }

        // 2. Perubahan Nama Shift Staff (Khusus Staff)
        if ($tab === 'staff' && !empty($_POST['apply_shift_name'])) {
            $shiftName = trim($_POST['bulk_staff_shift_name'] ?? '');
            if ($shiftName !== '') {
                $updates[] = "name = ?";
                $params[] = $shiftName;
            }
        }

        // 3. Perubahan Status Shift Malam / Overnight (Khusus Staff)
        if ($tab === 'staff' && !empty($_POST['apply_overnight'])) {
            $isOvernight = ((int)($_POST['bulk_is_overnight'] ?? 0) === 1) ? 1 : 0;
            $updates[] = "is_overnight = ?";
            $params[] = $isOvernight;
        }

        // 4. Perubahan Status Aktif/Nonaktif
        if (!empty($_POST['apply_status'])) {
            $bulkStatus = in_array($_POST['bulk_status'] ?? '', ['active', 'inactive']) ? $_POST['bulk_status'] : 'active';
            $updates[] = "is_active = ?";
            $params[] = $bulkStatus;
        }

        if (empty($updates)) {
            $pdo->rollBack();
            flash('warning', 'Tidak ada bidang yang dicentang untuk diubah secara massal.');
            redirect($redirectUrl);
            exit;
        }

        $sql = "UPDATE weekly_schedules SET " . implode(', ', $updates) . " WHERE id IN ($inClause)";
        $allParams = array_merge($params, array_values($validIds));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);

        recordActivityAudit($pdo, 'jadwal', 'UPDATE', 'weekly_schedules', 0, 
            "Edit Sekaligus: {$count} jadwal berhasil diperbarui", 
            null, ['action' => 'bulk_edit', 'ids' => $validIds, 'updates' => $updates, 'params' => $params], 
            $ksUnitId ?: ($returnUnit ?: null)
        );

        $pdo->commit();
        flash('success', "Berhasil memperbarui data pada {$count} jadwal terpilih.");
        redirect($redirectUrl);
        exit;

    } else {
        $pdo->rollBack();
        flash('error', 'Aksi massal tidak dikenali.');
        redirect($redirectUrl);
        exit;
    }

} catch (Exception $e) {
    $pdo->rollBack();
    flash('error', 'Terjadi kesalahan saat memproses aksi massal: ' . $e->getMessage());
    redirect($redirectUrl);
    exit;
}
