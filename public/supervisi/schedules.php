<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('supervisi_jadwal');

$pageTitle = 'Jadwal Supervisi Sekolah';
$currentUserId = (int)currentUserId();
$currentRole = (string)currentRole();
$canManageSupervision = in_array($currentRole, ['super_admin', 'admin', 'kepala_sekolah'], true);

// =========================================================================
// ACTION: POST HANDLERS (TAMBAH / EDIT / HAPUS JADWAL & TOGGLE KONTROL KEPSEK)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH JADWAL
    if ($action === 'add_schedule') {
        $typeId = (int)($_POST['type_id'] ?? 1);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $canChooseObserver = $canManageSupervision;
        $observerId = $canChooseObserver ? (int)($_POST['observer_id'] ?? $currentUserId) : $currentUserId;
        $principalId = !empty($_POST['principal_id']) ? (int)$_POST['principal_id'] : null;
        $isDelegated = !empty($_POST['is_delegated']) ? 1 : 0;
        $delegatedUserId = ($isDelegated && !empty($_POST['delegated_user_id'])) ? (int)$_POST['delegated_user_id'] : null;

        $subjectName = trim($_POST['subject_name'] ?? '');
        $className = trim($_POST['class_name'] ?? '');
        $schedDate = trim($_POST['scheduled_date'] ?? '');
        $timeStart = !empty($_POST['time_start']) ? trim($_POST['time_start']) : null;
        $timeEnd = !empty($_POST['time_end']) ? trim($_POST['time_end']) : null;
        $roomLocation = trim($_POST['room_location'] ?? '');
        $addObservers = isset($_POST['additional_observers']) && is_array($_POST['additional_observers']) ? array_map('intval', $_POST['additional_observers']) : [];

        if ($teacherId > 0 && $observerId > 0 && !empty($subjectName) && !empty($schedDate)) {
            $stmt = $pdo->prepare("
                INSERT INTO supervision_schedules (
                    type_id, teacher_id, observer_id, principal_id, delegated_user_id,
                    subject_name, class_name, scheduled_date, time_start, time_end,
                    room_location, bypass_time_lock, allow_edit, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'scheduled', ?)
            ");
            $stmt->execute([
                $typeId, $teacherId, $observerId, $principalId, $delegatedUserId,
                $subjectName, $className, $schedDate, $timeStart, $timeEnd,
                $roomLocation, $currentUserId
            ]);
            $schedId = (int)$pdo->lastInsertId();

            // Simpan ke tabel multi-observer
            $stmtSso = $pdo->prepare("INSERT INTO supervision_schedule_observers (schedule_id, observer_id, is_primary) VALUES (?, ?, ?)");
            $stmtSso->execute([$schedId, $observerId, 1]);

            foreach ($addObservers as $addObsId) {
                if ($addObsId > 0 && $addObsId !== $observerId) {
                    try {
                        $stmtSso->execute([$schedId, $addObsId, 0]);
                    } catch (Exception $e) {}
                }
            }

            // Kirim notifikasi otomatis
            try {
                // Info nama guru
                $stmtStaff = $pdo->prepare("SELECT user_id, name FROM staff WHERE id = ?");
                $stmtStaff->execute([$teacherId]);
                $staffInfo = $stmtStaff->fetch(PDO::FETCH_ASSOC);
                $teacherName = $staffInfo['name'] ?? 'Guru';
                $teacherUserId = (int)($staffInfo['user_id'] ?? 0);

                $jamStr = $timeStart ? ' pukul ' . substr($timeStart, 0, 5) : '';

                // Notif ke guru yang dinilai
                if ($teacherUserId > 0) {
                    createNotification($pdo, $teacherUserId, 'supervisi', 'Jadwal Supervisi Baru', "Anda dijadwalkan supervisi pembelajaran pada {$schedDate}{$jamStr} untuk mata pelajaran {$subjectName}.", "supervisi/schedules.php");
                }

                // Notif ke observer utama
                createNotification($pdo, $observerId, 'supervisi', 'Tugas Supervisi Ditugaskan', "Anda ditugaskan sebagai observer supervisi pembelajaran {$teacherName} pada {$schedDate}{$jamStr} (Mapel: {$subjectName}).", "supervisi/schedules.php");

                // Notif ke observer tambahan
                foreach ($addObservers as $addObsId) {
                    if ($addObsId > 0 && $addObsId !== $observerId) {
                        createNotification($pdo, $addObsId, 'supervisi', 'Tugas Supervisi (Tim Observer)', "Anda ditugaskan dalam tim observer supervisi {$teacherName} pada {$schedDate}{$jamStr} (Mapel: {$subjectName}).", "supervisi/schedules.php");
                    }
                }

                // Notif mandat jika dititipkan
                if ($delegatedUserId && $delegatedUserId > 0) {
                    createNotification($pdo, $delegatedUserId, 'supervisi', 'Mandat Supervisi Kepala Sekolah', "Kepala Sekolah menitipkan wewenang penilaian supervisi {$teacherName} pada {$schedDate}{$jamStr} kepada Anda.", "supervisi/principal.php?schedule_id={$schedId}");
                }
            } catch (Throwable $e) {
                error_log("Supervision schedule notification error: " . $e->getMessage());
            }

            flash('success', 'Jadwal supervisi baru dan penugasan observer berhasil disimpan!');
        } else {
            flash('error', 'Harap lengkapi informasi guru, observer utama, mata pelajaran, dan tanggal jadwal.');
        }
        redirect('schedules.php');
    }

    // 2. EDIT JADWAL
    if ($action === 'edit_schedule') {
        $schedId = (int)($_POST['schedule_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 1);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $observerId = (int)($_POST['observer_id'] ?? 0);
        $principalId = !empty($_POST['principal_id']) ? (int)$_POST['principal_id'] : null;
        $isDelegated = !empty($_POST['is_delegated']) ? 1 : 0;
        $delegatedUserId = ($isDelegated && !empty($_POST['delegated_user_id'])) ? (int)$_POST['delegated_user_id'] : null;

        $subjectName = trim($_POST['subject_name'] ?? '');
        $className = trim($_POST['class_name'] ?? '');
        $schedDate = trim($_POST['scheduled_date'] ?? '');
        $timeStart = !empty($_POST['time_start']) ? trim($_POST['time_start']) : null;
        $timeEnd = !empty($_POST['time_end']) ? trim($_POST['time_end']) : null;
        $roomLocation = trim($_POST['room_location'] ?? '');
        $status = trim($_POST['status'] ?? 'scheduled');
        $addObservers = isset($_POST['additional_observers']) && is_array($_POST['additional_observers']) ? array_map('intval', $_POST['additional_observers']) : [];

        if ($schedId > 0 && $teacherId > 0 && $observerId > 0) {
            $stmt = $pdo->prepare("
                UPDATE supervision_schedules
                SET type_id = ?, teacher_id = ?, observer_id = ?, principal_id = ?, delegated_user_id = ?,
                    subject_name = ?, class_name = ?, scheduled_date = ?, time_start = ?, time_end = ?,
                    room_location = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $typeId, $teacherId, $observerId, $principalId, $delegatedUserId,
                $subjectName, $className, $schedDate, $timeStart, $timeEnd,
                $roomLocation, $status, $schedId
            ]);

            // Sinkronisasi tabel multi-observer
            $pdo->prepare("DELETE FROM supervision_schedule_observers WHERE schedule_id = ?")->execute([$schedId]);
            $stmtSso = $pdo->prepare("INSERT INTO supervision_schedule_observers (schedule_id, observer_id, is_primary) VALUES (?, ?, ?)");
            $stmtSso->execute([$schedId, $observerId, 1]);

            foreach ($addObservers as $addObsId) {
                if ($addObsId > 0 && $addObsId !== $observerId) {
                    try {
                        $stmtSso->execute([$schedId, $addObsId, 0]);
                    } catch (Exception $e) {}
                }
            }

            flash('success', 'Jadwal supervisi berhasil diperbarui!');
        }
        redirect('schedules.php');
    }

    // 3. TOGGLE KUNCI JAM (BYPASS TIME-LOCK)
    if ($action === 'toggle_time_lock') {
        if (!$canManageSupervision) {
            flash('error', 'Hanya Kepala Sekolah dan Admin yang berwenang membuka/mengunci jam supervisi.');
            redirect('schedules.php');
        }
        $schedId = (int)($_POST['schedule_id'] ?? 0);
        if ($schedId > 0) {
            $stmt = $pdo->prepare("UPDATE supervision_schedules SET bypass_time_lock = IF(bypass_time_lock = 1, 0, 1) WHERE id = ?");
            $stmt->execute([$schedId]);

            $sc = $pdo->query("SELECT bypass_time_lock, observer_id, subject_name FROM supervision_schedules WHERE id = {$schedId}")->fetch(PDO::FETCH_ASSOC);
            if ($sc && (int)$sc['bypass_time_lock'] === 1) {
                createNotification($pdo, (int)$sc['observer_id'], 'supervisi', 'Kunci Jam Supervisi Dibuka', "Kepala Sekolah telah membuka kunci jam pelaksanaan supervisi mapel {$sc['subject_name']}. Anda kini dapat melakukan input penilaian.", "supervisi/schedules.php");
            }
            flash('success', 'Status kunci jam supervisi berhasil diperbarui.');
        }
        redirect('schedules.php');
    }

    // 4. TOGGLE IZIN EDIT (ALLOW-EDIT LOCK)
    if ($action === 'toggle_allow_edit') {
        if (!$canManageSupervision) {
            flash('error', 'Hanya Kepala Sekolah dan Admin yang berwenang memberikan izin edit penilaian.');
            redirect('schedules.php');
        }
        $schedId = (int)($_POST['schedule_id'] ?? 0);
        if ($schedId > 0) {
            $stmt = $pdo->prepare("UPDATE supervision_schedules SET allow_edit = IF(allow_edit = 1, 0, 1) WHERE id = ?");
            $stmt->execute([$schedId]);

            $sc = $pdo->query("SELECT allow_edit, observer_id, subject_name FROM supervision_schedules WHERE id = {$schedId}")->fetch(PDO::FETCH_ASSOC);
            if ($sc && (int)$sc['allow_edit'] === 1) {
                createNotification($pdo, (int)$sc['observer_id'], 'supervisi', 'Izin Edit Supervisi Diaktifkan', "Kepala Sekolah telah mengaktifkan izin revisi/edit dokumen supervisi mapel {$sc['subject_name']}.", "supervisi/schedules.php");
            }
            flash('success', 'Status izin edit penilaian berhasil diperbarui.');
        }
        redirect('schedules.php');
    }

    // 5. HAPUS JADWAL
    if ($action === 'delete_schedule') {
        $schedId = (int)($_POST['schedule_id'] ?? 0);
        if ($schedId > 0) {
            $pdo->prepare("DELETE FROM supervision_schedules WHERE id = ?")->execute([$schedId]);
            flash('success', 'Jadwal supervisi berhasil dihapus.');
        }
        redirect('schedules.php');
    }
}

// =========================================================================
// QUERY DATA TAMPILAN & SCOPING UNIT
// =========================================================================
$userUnit = getUserUnitInfo($pdo, $currentUserId);
$userUnitId = $userUnit['unit_id'] ?? null;
$userUnitName = $userUnit['unit_name'] ?? null;

$isSuperAdmin = ($currentRole === 'super_admin');
$canChooseObserver = $canManageSupervision;

$search = trim($_GET['search'] ?? '');
$filterType = (int)($_GET['type_id'] ?? 0);
$filterUnit = (int)($_GET['unit_id'] ?? 0);

$query = "
    SELECT ss.*, 
           st.name as type_name, st.type_key,
           s.name as teacher_name, s.nik as teacher_nik, s.unit_id as teacher_unit_id,
           u.name as observer_name,
           up.name as principal_name,
           ud.name as delegated_name,
           sess.id as session_id, sess.status as session_status,
           (
               SELECT GROUP_CONCAT(uo.name SEPARATOR ', ')
               FROM supervision_schedule_observers sso
               JOIN users uo ON uo.id = sso.observer_id
               WHERE sso.schedule_id = ss.id AND sso.is_primary = 0
           ) as additional_observers
    FROM supervision_schedules ss
    JOIN supervision_types st ON st.id = ss.type_id
    JOIN staff s ON s.id = ss.teacher_id
    JOIN users u ON u.id = ss.observer_id
    LEFT JOIN users up ON up.id = ss.principal_id
    LEFT JOIN users ud ON ud.id = ss.delegated_user_id
    LEFT JOIN supervision_sessions sess ON sess.schedule_id = ss.id
    WHERE 1=1
";
$params = [];

if (!$isSuperAdmin && $userUnitId > 0) {
    // Non-super_admin: jadwal di unitnya ATAU user terdaftar sebagai observer (utama/tambahan), guru, kepsek, atau delegated
    $query .= " AND (
        s.unit_id = ? 
        OR ss.observer_id = ? 
        OR ss.principal_id = ? 
        OR ss.delegated_user_id = ?
        OR EXISTS (SELECT 1 FROM supervision_schedule_observers sso WHERE sso.schedule_id = ss.id AND sso.observer_id = ?)
    )";
    $params[] = $userUnitId;
    $params[] = $currentUserId;
    $params[] = $currentUserId;
    $params[] = $currentUserId;
    $params[] = $currentUserId;
} elseif ($isSuperAdmin && $filterUnit > 0) {
    $query .= " AND s.unit_id = ?";
    $params[] = $filterUnit;
}

if ($filterType > 0) {
    $query .= " AND ss.type_id = ?";
    $params[] = $filterType;
}

if (!empty($search)) {
    $query .= " AND (s.name LIKE ? OR u.name LIKE ? OR ss.subject_name LIKE ? OR ss.class_name LIKE ? OR ss.room_location LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$query .= " ORDER BY ss.scheduled_date DESC, ss.time_start ASC, ss.id DESC";

$stmtSchedules = $pdo->prepare($query);
$stmtSchedules->execute($params);
$schedules = $stmtSchedules->fetchAll(PDO::FETCH_ASSOC);

// Map additional observer IDs per schedule for edit modal prefill
$schedAdditionalMap = [];
try {
    $stmtObsAll = $pdo->query("SELECT schedule_id, observer_id FROM supervision_schedule_observers WHERE is_primary = 0");
    while ($row = $stmtObsAll->fetch(PDO::FETCH_ASSOC)) {
        $schedAdditionalMap[$row['schedule_id']][] = (int)$row['observer_id'];
    }
} catch (Exception $e) {}

// Master Data untuk Dropdown Form & Filter
$types = $pdo->query("SELECT id, name, type_key FROM supervision_types WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$unitsList = $pdo->query("SELECT id, unit FROM units WHERE id IN (1, 2, 3, 7) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$teachers = getSupervisionTeachers($pdo, $userUnitId);
$observers = getSupervisionObservers($pdo, $userUnitId);
$principals = getSupervisionPrincipals($pdo, $userUnitId);

$mapelList = [
    "Pendidikan Agama Islam / Al-Qur'an",
    "Bahasa Indonesia",
    "Bahasa Inggris",
    "Matematika",
    "Ilmu Pengetahuan Alam (IPA)",
    "Ilmu Pengetahuan Sosial (IPS)",
    "Informatika / TIK",
    "Pendidikan Pancasila (PPKn)",
    "Seni Budaya & Prakarya",
    "PJOK"
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="supervisi-fullwidth" style="width: 100%; max-width: 100%;">
    <!-- HEADER -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <span style="background: #e0f2fe; color: #0369a1; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 6px; text-transform: uppercase;">
                    <i class="fa-solid fa-calendar-days"></i> Manajemen Waktu &amp; Supervisi
                </span>
                <span style="color: #94a3b8; font-size: 13px;">&bull; <?= $userUnitName ? 'Unit ' . htmlspecialchars($userUnitName) : 'Seluruh Unit Sekolah' ?> &bull; An Nahl Islamic School</span>
            </div>
            <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #0f172a;">
                Jadwal Supervisi Sekolah
            </h1>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 14px;">
                Menampilkan <?= count($schedules) ?> jadwal supervisi pembelajaran guru terencana (dilengkapi multi-observer, kontrol kunci jam, dan mandat Kepala Sekolah).
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <button type="button" class="btn btn-primary" onclick="openAddScheduleModal()" style="display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);">
                <i class="fa-solid fa-plus"></i> Tambah Jadwal Baru
            </button>
        </div>
    </div>

    <!-- FILTER & SEARCH BAR -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
        <form method="GET" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 280px; position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 11px; color: #94a3b8; font-size: 13px;"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari jadwal berdasar guru, observer, mapel, kelas, atau ruang..." class="form-control" style="padding-left: 36px;">
            </div>

            <div style="width: 220px;">
                <select name="type_id" class="form-control" onchange="this.form.submit()">
                    <option value="0">-- Semua Jenis Supervisi --</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($filterType == $t['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($isSuperAdmin): ?>
                <div style="width: 170px;">
                    <select name="unit_id" class="form-control" onchange="this.form.submit()">
                        <option value="0">-- Semua Unit --</option>
                        <?php foreach ($unitsList as $un): ?>
                            <option value="<?= $un['id'] ?>" <?= ($filterUnit == $un['id']) ? 'selected' : '' ?>>
                                Unit <?= htmlspecialchars($un['unit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-secondary">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if (!empty($search) || $filterType > 0): ?>
                <a href="schedules.php" class="btn btn-secondary" title="Reset Filter">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- TABEL JADWAL SUPERVISI -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <?php if (empty($schedules)): ?>
            <div style="padding: 48px; text-align: center; color: #64748b;">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 40px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                Belum ada jadwal supervisi yang sesuai. Silakan klik tombol <strong>"Tambah Jadwal Baru"</strong> untuk menjadwalkan.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                            <th style="padding: 14px 16px; width: 45px; text-align: center;">No</th>
                            <th style="padding: 14px 16px; width: 170px;">Hari &amp; Tanggal</th>
                            <th style="padding: 14px 14px; width: 130px;">Waktu &amp; Kunci</th>
                            <th style="padding: 14px 16px;">Guru Dinilai</th>
                            <th style="padding: 14px 16px;">Tim Observer</th>
                            <th style="padding: 14px 16px;">Penilai Kepsek</th>
                            <th style="padding: 14px 16px;">Mata Pelajaran</th>
                            <th style="padding: 14px 16px; width: 230px; text-align: right;">Aksi &amp; Kontrol</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($schedules as $sc): 
                            $isCompleted = ($sc['status'] === 'completed' || (!empty($sc['session_id']) && $sc['session_status'] === 'completed'));
                            $isDraft = (!empty($sc['session_id']) && $sc['session_status'] === 'draft');
                            $timeStr = '-';
                            if (!empty($sc['time_start'])) {
                                $timeStr = substr($sc['time_start'], 0, 5) . (!empty($sc['time_end']) ? ' - ' . substr($sc['time_end'], 0, 5) : '');
                            }
                            $isPeer = ($sc['type_key'] === 'peer');
                            $evalUrl = $isPeer ? "peer.php?schedule_id={$sc['id']}" : "principal.php?schedule_id={$sc['id']}";

                            $schedDateTime = strtotime($sc['scheduled_date'] . ' ' . ($sc['time_start'] ?: '00:00:00'));
                            $isTimeLocked = (time() < $schedDateTime && (int)$sc['bypass_time_lock'] === 0);

                            // Tambahkan array additional_observer_ids ke data row untuk JS
                            $sc['additional_observer_ids'] = $schedAdditionalMap[$sc['id']] ?? [];
                        ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                <td style="padding: 14px 16px; text-align: center; color: #94a3b8; font-weight: 700;">
                                    <?= $no++ ?>
                                </td>
                                <td style="padding: 14px 16px; font-weight: 600; color: #1e293b;">
                                    <?= formatIndoDate($sc['scheduled_date']) ?>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                        <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: <?= $isPeer ? '#4f46e5' : '#9333ea' ?>; margin-right: 4px;"></span>
                                        <?= htmlspecialchars($sc['type_name']) ?>
                                    </div>
                                </td>
                                <td style="padding: 14px 14px; color: #475569; font-weight: 600; font-size: 12px;">
                                    <div><?= htmlspecialchars($timeStr) ?></div>
                                    <?php if ($sc['bypass_time_lock']): ?>
                                        <span class="badge" style="background: #dcfce7; color: #15803d; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-top: 3px; display: inline-block;">
                                            <i class="fa-solid fa-lock-open"></i> Jam Dibuka
                                        </span>
                                    <?php elseif ($isTimeLocked): ?>
                                        <span class="badge" style="background: #fef3c7; color: #b45309; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-top: 3px; display: inline-block;">
                                            <i class="fa-solid fa-lock"></i> Waktu Dikunci
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #f1f5f9; color: #475569; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-top: 3px; display: inline-block;">
                                            <i class="fa-solid fa-play"></i> Sesi Aktif
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 14px 16px;">
                                    <span style="font-weight: 700; color: #0f172a; display: block;">
                                        <?= htmlspecialchars($sc['teacher_name']) ?>
                                    </span>
                                    <?php if (!empty($sc['class_name'])): ?>
                                        <span style="font-size: 11px; color: #64748b;">
                                            Kelas: <?= htmlspecialchars($sc['class_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 14px 16px; color: #334155; font-size: 12.5px;">
                                    <div>
                                        <strong style="color: #0f172a;"><?= htmlspecialchars($sc['observer_name']) ?></strong>
                                        <span style="font-size: 10px; background: #e0e7ff; color: #3730a3; padding: 1px 4px; border-radius: 3px; font-weight: 700;">Utama</span>
                                    </div>
                                    <?php if (!empty($sc['additional_observers'])): ?>
                                        <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                            + <?= htmlspecialchars($sc['additional_observers']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 14px 16px; font-size: 12.5px; color: #334155;">
                                    <div style="font-weight: 600;"><?= htmlspecialchars($sc['principal_name'] ?: 'Kepala Sekolah') ?></div>
                                    <?php if (!empty($sc['delegated_name'])): ?>
                                        <div style="font-size: 11px; color: #d97706; font-weight: 700; margin-top: 2px;">
                                            <i class="fa-solid fa-hand-holding-hand"></i> Mandat: <?= htmlspecialchars($sc['delegated_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 14px 16px; color: #1e293b;">
                                    <div style="font-weight: 700;"><?= htmlspecialchars($sc['subject_name']) ?></div>
                                    <div style="font-size: 11px; color: #64748b;">Ruang: <?= htmlspecialchars($sc['room_location'] ?: '-') ?></div>
                                </td>
                                <td style="padding: 14px 16px; text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: flex-end;">
                                        <?php if ($isDraft): ?>
                                            <!-- DRAF SUDAH ADA -->
                                            <?php $resumeUrl = $isPeer ? "peer.php?session_id={$sc['session_id']}" : "principal.php?session_id={$sc['session_id']}"; ?>
                                            <a href="<?= $resumeUrl ?>" class="btn btn-sm" style="background: #f59e0b; color: white; font-weight: 700; padding: 5px 10px; border-radius: 6px; font-size: 11.5px; text-decoration: none;" title="Lanjutkan Pengisian Draf">
                                                <i class="fa-solid fa-clock-rotate-left"></i> Draf
                                            </a>
                                        <?php elseif ($isCompleted): ?>
                                            <span style="background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 700; padding: 5px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fa-solid fa-check-circle"></i> Selesai
                                            </span>
                                        <?php else: ?>
                                            <!-- BELUM DIMULAI -->
                                            <a href="<?= $evalUrl ?>" class="btn btn-sm btn-success" style="background: #10b981; border-color: #10b981; color: white; font-weight: 700; padding: 5px 10px; border-radius: 6px; font-size: 11.5px; text-decoration: none;" title="Mulai Nilai Supervisi">
                                                <i class="fa-solid fa-play"></i> Nilai
                                            </a>
                                        <?php endif; ?>

                                        <!-- KONTROL KEPSEK / ADMIN -->
                                        <?php if ($canManageSupervision): ?>
                                            <!-- Toggle Bypass Time Lock -->
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Ubah status kunci jam supervisi ini?');">
                                                <input type="hidden" name="action" value="toggle_time_lock">
                                                <input type="hidden" name="schedule_id" value="<?= $sc['id'] ?>">
                                                <button type="submit" class="btn btn-sm <?= $sc['bypass_time_lock'] ? 'btn-success' : 'btn-secondary' ?>" style="padding: 5px 8px; font-size: 11px;" title="<?= $sc['bypass_time_lock'] ? 'Bypass jam aktif. Klik untuk mengunci kembali.' : 'Kunci jam aktif. Klik untuk membuka jam sekarang.' ?>">
                                                    <i class="fa-solid <?= $sc['bypass_time_lock'] ? 'fa-unlock' : 'fa-lock' ?>"></i>
                                                </button>
                                            </form>

                                            <!-- Toggle Allow Edit (jika sudah completed) -->
                                            <?php if ($isCompleted): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Ubah izin edit dokumen penilaian ini?');">
                                                    <input type="hidden" name="action" value="toggle_allow_edit">
                                                    <input type="hidden" name="schedule_id" value="<?= $sc['id'] ?>">
                                                    <button type="submit" class="btn btn-sm <?= $sc['allow_edit'] ? 'btn-warning' : 'btn-secondary' ?>" style="padding: 5px 8px; font-size: 11px; <?= $sc['allow_edit'] ? 'background: #0284c7; color: white;' : '' ?>" title="<?= $sc['allow_edit'] ? 'Izin edit aktif. Klik untuk mengunci.' : 'Dokumen terkunci. Klik untuk membuka izin edit bagi observer.' ?>">
                                                        <i class="fa-solid <?= $sc['allow_edit'] ? 'fa-pen-to-square' : 'fa-lock' ?>"></i> Edit
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <!-- Tombol Edit Jadwal -->
                                        <button type="button" class="btn btn-sm btn-secondary" style="padding: 5px 8px; font-size: 11px;" onclick='openEditScheduleModal(<?= json_encode($sc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Edit Jadwal">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>

                                        <!-- Tombol Hapus -->
                                        <?php if ($canManageSupervision): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus jadwal supervisi ini?');">
                                                <input type="hidden" name="action" value="delete_schedule">
                                                <input type="hidden" name="schedule_id" value="<?= $sc['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" style="padding: 5px 8px; font-size: 11px;" title="Hapus Jadwal">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TAMBAH / EDIT JADWAL SUPERVISI (DILENGKAPI MULTI-OBSERVER & KEPSEK) -->
<!-- ========================================================================= -->
<div id="modalSchedule" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 680px;">
        <div class="custom-modal-header">
            <h3 id="modalScheduleTitle" style="margin: 0; font-size: 17px; color: #0f172a;">Tambah Jadwal Supervisi Baru</h3>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalSchedule')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" id="schedActionInput" value="add_schedule">
            <input type="hidden" name="schedule_id" id="schedIdInput" value="">

            <div class="custom-modal-body" style="padding: 20px;">
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Jenis Supervisi *</label>
                    <select name="type_id" id="schedTypeSelect" class="form-control" required>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">
                            Guru yang Dinilai *
                            <?php if ($userUnitName): ?>
                                <span style="font-size: 10.5px; font-weight: 600; color: #0284c7; background: #e0f2fe; padding: 1px 5px; border-radius: 4px; margin-left: 2px;">
                                    Unit <?= htmlspecialchars($userUnitName) ?>
                                </span>
                            <?php endif; ?>
                        </label>
                        <input type="text" id="teacherSearchFilter" placeholder="Cari nama guru..." class="form-control" style="margin-bottom: 6px; font-size: 13px;" onkeyup="filterTeacherOptions(this.value)">
                        <select name="teacher_id" id="schedTeacherSelect" class="form-control" required>
                            <option value="">-- Pilih Guru --</option>
                            <?php if ($isSuperAdmin && empty($userUnitId)): ?>
                                <?php 
                                $groupedTeachers = [];
                                foreach ($teachers as $tc) {
                                    $uName = $tc['unit_name'] ?: 'Lainnya';
                                    $groupedTeachers[$uName][] = $tc;
                                }
                                foreach ($groupedTeachers as $uLabel => $tList): ?>
                                    <optgroup label="Unit <?= htmlspecialchars($uLabel) ?>">
                                        <?php foreach ($tList as $tc): ?>
                                            <option value="<?= $tc['id'] ?>"><?= htmlspecialchars($tc['name']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($teachers as $tc): ?>
                                    <option value="<?= $tc['id'] ?>"><?= htmlspecialchars($tc['name']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Observer Utama *</label>
                        <?php if ($canChooseObserver): ?>
                            <select name="observer_id" id="schedObserverSelect" class="form-control" required>
                                <option value="">-- Pilih Observer Utama --</option>
                                <?php foreach ($observers as $ob): ?>
                                    <option value="<?= $ob['id'] ?>"><?= htmlspecialchars($ob['name']) ?> (<?= htmlspecialchars($ob['role']) ?><?= !empty($ob['unit_name']) ? ' - ' . $ob['unit_name'] : '' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="observer_id" id="schedObserverHidden" value="<?= $currentUserId ?>">
                            <div class="form-control" style="background: #f8fafc; font-weight: 700; color: #1e293b; display: flex; align-items: center; justify-content: space-between; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 14px;">
                                <span><i class="fa-solid fa-user-check" style="color: #10b981; margin-right: 6px;"></i> <?= htmlspecialchars($_SESSION['name'] ?? 'Akun Anda') ?></span>
                                <span class="badge" style="background: #e0e7ff; color: #4338ca; font-size: 10px; padding: 2px 6px; border-radius: 4px;">Akun Anda</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- MULTI-OBSERVER TAMBAHAN -->
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">
                        Observer Tambahan (Opsional - Multi-Observer &gt; 1 Guru)
                    </label>
                    <div style="max-height: 120px; overflow-y: auto; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; background: #f8fafc;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <?php foreach ($observers as $ob): ?>
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; cursor: pointer;">
                                    <input type="checkbox" name="additional_observers[]" class="chk-add-obs" value="<?= $ob['id'] ?>">
                                    <span><?= htmlspecialchars($ob['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small style="color: #64748b; font-size: 11px;">Centang guru lain yang ikut mengobservasi kelas bersama observer utama.</small>
                </div>

                <!-- PENYERTAAN KEPALA SEKOLAH & MANDAT / TITIP NILAI -->
                <div style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start;">
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">
                                Kepala Sekolah Penilai *
                            </label>
                            <select name="principal_id" id="schedPrincipalSelect" class="form-control" required>
                                <option value="">-- Pilih Kepala Sekolah --</option>
                                <?php foreach ($principals as $pr): ?>
                                    <option value="<?= $pr['id'] ?>"><?= htmlspecialchars($pr['name']) ?> (<?= htmlspecialchars($pr['role']) ?><?= !empty($pr['unit_name']) ? ' - ' . $pr['unit_name'] : '' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: #0f172a; margin-top: 24px; cursor: pointer;">
                                <input type="checkbox" name="is_delegated" id="chkIsDelegated" value="1" onchange="toggleDelegatedBox(this.checked)">
                                <span>Titipkan Penilaian Kepsek</span>
                            </label>
                        </div>
                    </div>

                    <div id="boxDelegated" style="display: none; margin-top: 10px; border-top: 1px dashed #cbd5e1; padding-top: 10px;">
                        <label style="display: block; font-size: 12.5px; font-weight: 700; margin-bottom: 6px; color: #b45309;">
                            <i class="fa-solid fa-hand-holding-hand"></i> Guru yang Diberikan Mandat Penilaian:
                        </label>
                        <select name="delegated_user_id" id="schedDelegatedSelect" class="form-control">
                            <option value="">-- Pilih Guru Penerima Mandat --</option>
                            <?php foreach ($observers as $ob): ?>
                                <option value="<?= $ob['id'] ?>"><?= htmlspecialchars($ob['name']) ?> (<?= htmlspecialchars($ob['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #64748b; font-size: 11px;">Guru tertunjuk akan menerima wewenang dan notifikasi untuk melakukan penilaian atas nama Kepala Sekolah.</small>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Mata Pelajaran *</label>
                        <input type="text" name="subject_name" id="schedSubjectInput" list="mapelModalList" placeholder="Misal: Bahasa Inggris" class="form-control" required>
                        <datalist id="mapelModalList">
                            <?php foreach ($mapelList as $mp): ?>
                                <option value="<?= htmlspecialchars($mp) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Kelas *</label>
                        <input type="text" name="class_name" id="schedClassInput" placeholder="Misal: 8C" class="form-control" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Tanggal Supervisi *</label>
                        <input type="date" name="scheduled_date" id="schedDateInput" value="<?= date('Y-m-d') ?>" class="form-control" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Jam Mulai</label>
                        <input type="time" name="time_start" id="schedTimeStartInput" class="form-control">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Jam Selesai</label>
                        <input type="time" name="time_end" id="schedTimeEndInput" class="form-control">
                    </div>
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Lokasi / Ruangan</label>
                    <input type="text" name="room_location" id="schedRoomInput" placeholder="Misal: Ruang Kelas 8C / Lab Komputer" class="form-control">
                </div>

                <div id="schedStatusContainer" style="display: none; margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Status Jadwal</label>
                    <select name="status" id="schedStatusSelect" class="form-control">
                        <option value="scheduled">Dijadwalkan</option>
                        <option value="in_progress">Sedang Berlangsung</option>
                        <option value="completed">Selesai</option>
                        <option value="cancelled">Dibatalkan</option>
                    </select>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalSchedule')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDelegatedBox(checked) {
    const box = document.getElementById('boxDelegated');
    if (box) box.style.display = checked ? 'block' : 'none';
}

function openAddScheduleModal() {
    document.getElementById('modalScheduleTitle').innerText = 'Tambah Jadwal Supervisi Baru';
    document.getElementById('schedActionInput').value = 'add_schedule';
    document.getElementById('schedIdInput').value = '';
    document.getElementById('schedTeacherSelect').value = '';
    const elObs = document.getElementById('schedObserverSelect'); if (elObs) elObs.value = '<?= $currentUserId ?>';
    
    // Uncheck all additional observers
    document.querySelectorAll('.chk-add-obs').forEach(chk => chk.checked = false);

    // Default Principal
    const elPr = document.getElementById('schedPrincipalSelect');
    if (elPr && elPr.options.length > 1) {
        elPr.selectedIndex = 1;
    }

    document.getElementById('chkIsDelegated').checked = false;
    toggleDelegatedBox(false);
    document.getElementById('schedDelegatedSelect').value = '';

    document.getElementById('schedSubjectInput').value = '';
    document.getElementById('schedClassInput').value = '';
    document.getElementById('schedDateInput').value = '<?= date('Y-m-d') ?>';
    document.getElementById('schedTimeStartInput').value = '07:30';
    document.getElementById('schedTimeEndInput').value = '08:50';
    document.getElementById('schedRoomInput').value = '';
    document.getElementById('schedStatusContainer').style.display = 'none';
    document.getElementById('modalSchedule').style.display = 'flex';
}

function openEditScheduleModal(sc) {
    document.getElementById('modalScheduleTitle').innerText = 'Edit Jadwal Supervisi';
    document.getElementById('schedActionInput').value = 'edit_schedule';
    document.getElementById('schedIdInput').value = sc.id;
    document.getElementById('schedTypeSelect').value = sc.type_id;
    document.getElementById('schedTeacherSelect').value = sc.teacher_id;
    const elObs2 = document.getElementById('schedObserverSelect'); if (elObs2) elObs2.value = sc.observer_id;
    
    // Set additional observers checkboxes
    const addIds = sc.additional_observer_ids || [];
    document.querySelectorAll('.chk-add-obs').forEach(chk => {
        chk.checked = addIds.includes(parseInt(chk.value));
    });

    // Set Principal & Delegated
    const elPr = document.getElementById('schedPrincipalSelect');
    if (elPr && sc.principal_id) elPr.value = sc.principal_id;

    const isDel = !!sc.delegated_user_id;
    document.getElementById('chkIsDelegated').checked = isDel;
    toggleDelegatedBox(isDel);
    if (isDel) {
        document.getElementById('schedDelegatedSelect').value = sc.delegated_user_id;
    } else {
        document.getElementById('schedDelegatedSelect').value = '';
    }

    document.getElementById('schedSubjectInput').value = sc.subject_name;
    document.getElementById('schedClassInput').value = sc.class_name;
    document.getElementById('schedDateInput').value = sc.scheduled_date;
    document.getElementById('schedTimeStartInput').value = sc.time_start ? sc.time_start.substring(0, 5) : '';
    document.getElementById('schedTimeEndInput').value = sc.time_end ? sc.time_end.substring(0, 5) : '';
    document.getElementById('schedRoomInput').value = sc.room_location || '';
    document.getElementById('schedStatusSelect').value = sc.status;
    document.getElementById('schedStatusContainer').style.display = 'block';
    document.getElementById('modalSchedule').style.display = 'flex';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function filterTeacherOptions(keyword) {
    const term = keyword.toLowerCase();
    const select = document.getElementById('schedTeacherSelect');
    const options = select.options;
    for (let i = 0; i < options.length; i++) {
        const opt = options[i];
        if (opt.value === "") continue;
        const text = opt.text.toLowerCase();
        if (text.includes(term)) {
            opt.style.display = "";
        } else {
            opt.style.display = "none";
        }
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
