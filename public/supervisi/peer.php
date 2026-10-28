<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('supervisi_sejawat');

$pageTitle = 'Supervisi Teman Sejawat (Peer Observation)';
$currentUserId = currentUserId();
$currentRole = currentRole();

// Proteksi Wewenang: Kepala Sekolah dilarang mengisi supervisi sejawat kecuali telah diberi izin Super Admin
if ($currentRole === 'kepala_sekolah' && !canKepsekAccessPeerSupervision($pdo, $currentUserId)) {
    flash('error', 'Akses ditolak: Kepala Sekolah tidak memiliki wewenang untuk mengisi supervisi teman sejawat, kecuali telah diberikan izin khusus oleh Super Admin.');
    redirect('schedules.php');
}

// Ambil type_id = 1 (peer)
$typeId = 1;
$stmtType = $pdo->prepare("SELECT * FROM supervision_types WHERE id = ? LIMIT 1");
$stmtType->execute([$typeId]);
$typeInfo = $stmtType->fetch(PDO::FETCH_ASSOC);

// 1. Cek mode: Sesi Baru atau Melanjutkan Sesi Tersimpan (Draf / Edit)
$sessionId = (int)($_GET['session_id'] ?? 0);
$scheduleId = (int)($_GET['schedule_id'] ?? 0);
$editSession = null;
$savedScores = [];

// WAJIB TERIKAT JADWAL: Penilaian tanpa jadwal dilarang
if ($sessionId <= 0 && $scheduleId <= 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('warning', 'Penilaian supervisi sejawat wajib dimulai dari Jadwal Supervisi yang telah terdaftar. Silakan pilih jadwal Anda di bawah ini.');
    redirect('schedules.php');
}

if ($sessionId > 0) {
    $stmtSess = $pdo->prepare("SELECT * FROM supervision_sessions WHERE id = ? AND type_id = ? LIMIT 1");
    $stmtSess->execute([$sessionId, $typeId]);
    $editSession = $stmtSess->fetch(PDO::FETCH_ASSOC);
    if (!$editSession) {
        flash('error', 'Dokumen supervisi tidak ditemukan.');
        redirect('reports.php');
    }
    // Proteksi otorisasi observer
    if (!in_array($currentRole, ['super_admin', 'admin'], true) && $editSession['observer_id'] != $currentUserId) {
        flash('error', 'Anda tidak memiliki hak akses untuk mengubah dokumen supervisi ini.');
        redirect('reports.php');
    }
    $scheduleId = (int)($editSession['schedule_id'] ?? 0);

    // Ambil skor tersimpan
    $stmtScores = $pdo->prepare("SELECT item_id, score FROM supervision_scores WHERE session_id = ?");
    $stmtScores->execute([$sessionId]);
    $savedScores = $stmtScores->fetchAll(PDO::FETCH_KEY_PAIR);
}

// 2. Ambil metadata schedule_id yang diteruskan atau terhubung dengan sesi
$prefillSchedule = null;
if ($scheduleId > 0) {
    $stmtSched = $pdo->prepare("
        SELECT ss.*, s.name as teacher_name, u.name as observer_name
        FROM supervision_schedules ss
        LEFT JOIN staff s ON s.id = ss.teacher_id
        LEFT JOIN users u ON u.id = ss.observer_id
        WHERE ss.id = ? LIMIT 1
    ");
    $stmtSched->execute([$scheduleId]);
    $prefillSchedule = $stmtSched->fetch(PDO::FETCH_ASSOC);

    if (!$prefillSchedule && !$editSession) {
        flash('error', 'Jadwal supervisi tidak ditemukan.');
        redirect('schedules.php');
    }

    // Otorisasi Observer: user harus super_admin, admin, kepsek, observer utama, atau observer tambahan pada jadwal ini
    if ($prefillSchedule && !$editSession) {
        $isSuperUser = in_array($currentRole, ['super_admin', 'admin'], true);
        $isAssignedPrimary = ((int)$prefillSchedule['observer_id'] === $currentUserId);

        $stmtCheckAdd = $pdo->prepare("SELECT 1 FROM supervision_schedule_observers WHERE schedule_id = ? AND observer_id = ?");
        $stmtCheckAdd->execute([$scheduleId, $currentUserId]);
        $isAssignedAdditional = (bool)$stmtCheckAdd->fetchColumn();

        if (!$isSuperUser && !$isAssignedPrimary && !$isAssignedAdditional) {
            flash('error', 'Akses ditolak: Anda tidak terdaftar sebagai observer pada jadwal supervisi ini.');
            redirect('schedules.php');
        }
    }
}

// 3. Evaluasi Kunci Jam (Time-Lock) & Izin Edit (Edit-Lock)
$isTimeLocked = false;
$timeLockMsg = '';
$isEditLocked = false;
$editLockMsg = '';

$checkScheduleId = $scheduleId ?: (int)($_POST['schedule_id'] ?? 0);
$schedData = null;
if ($checkScheduleId > 0) {
    $stmtScCheck = $pdo->prepare("SELECT * FROM supervision_schedules WHERE id = ?");
    $stmtScCheck->execute([$checkScheduleId]);
    $schedData = $stmtScCheck->fetch(PDO::FETCH_ASSOC);
}

if ($schedData) {
    $timeStatus = getSupervisionTimeLockStatus(
        $schedData['scheduled_date'],
        $schedData['time_start'],
        $schedData['time_end'],
        (int)$schedData['bypass_time_lock']
    );
    $isTimeLocked = $timeStatus['is_locked'];
    $timeLockMsg = $timeStatus['lock_message'];
}

$isCompletedSession = ($editSession && $editSession['status'] === 'completed');
if ($isCompletedSession) {
    $isPrivilegedUser = in_array($currentRole, ['super_admin', 'admin'], true);
    $hasAllowEdit = ($schedData && (int)$schedData['allow_edit'] === 1);
    if (!$isPrivilegedUser && !$hasAllowEdit) {
        $isEditLocked = true;
        $editLockMsg = "Dokumen supervisi ini telah diselesaikan dan terkunci secara permanen. Pengubahan data hanya dapat dilakukan jika Kepala Sekolah mengaktifkan 'Izin Edit' pada jadwal supervisi terkait.";
    }
}
$isFormLocked = ($isTimeLocked || $isEditLocked);

// Proteksi Waktu Sesi (Sebelum jam atau Lewat jam)
if (!$isCompletedSession && $isTimeLocked && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', $timeLockMsg);
    redirect('schedules.php');
}
if ($isCompletedSession && $isEditLocked && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', $editLockMsg);
    redirect('reports.php');
}

// =========================================================================
// ACTION: SIMPAN FORMULIR SUPERVISI SEJAWAT (DRAF ATAU FINALISASI)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_peer_supervision') {
    $sessId = (int)($_POST['session_id'] ?? 0);
    $schedId = !empty($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : null;

    if (empty($schedId) && empty($sessId)) {
        flash('error', 'Penilaian supervisi sejawat wajib terikat dengan jadwal supervisi yang sah.');
        redirect('schedules.php');
    }

    if ($isTimeLocked) {
        flash('error', $timeLockMsg);
        redirect('peer.php' . ($sessId ? "?session_id={$sessId}" : ($schedId ? "?schedule_id={$schedId}" : '')));
    }
    if ($isEditLocked) {
        flash('error', $editLockMsg);
        redirect('peer.php' . ($sessId ? "?session_id={$sessId}" : ($schedId ? "?schedule_id={$schedId}" : '')));
    }

    $submitAction = $_POST['submit_action'] ?? 'save_completed';
    $isDraft = ($submitAction === 'save_draft');
    $sessionStatus = $isDraft ? 'draft' : 'completed';

    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $canChooseObserver = in_array($currentRole, ['super_admin', 'admin'], true);
    $observerId = $canChooseObserver ? (int)($_POST['observer_id'] ?? $currentUserId) : $currentUserId;
    $subjectName = trim($_POST['subject_name'] ?? '');
    $className = trim($_POST['class_name'] ?? '');
    $obsDate = trim($_POST['observation_date'] ?? date('Y-m-d'));
    $roomLocation = trim($_POST['room_location'] ?? '');

    $notesGood = trim($_POST['notes_good_practice'] ?? '');
    $notesGrowth = trim($_POST['notes_growth_plan'] ?? '');
    $notesReflection = trim($_POST['notes_teacher_reflection'] ?? '');

    $scoresInput = $_POST['score'] ?? []; // [item_id => score_val]

    // Validasi
    if ($isDraft) {
        if ($teacherId <= 0) {
            flash('error', 'Harap pilih nama guru yang dinilai sebelum menyimpan draf.');
            redirect('peer.php' . ($sessId ? "?session_id={$sessId}" : ($schedId ? "?schedule_id={$schedId}" : '')));
        }
        if (empty($subjectName)) $subjectName = 'Mata Pelajaran (Draf)';
        if (empty($className)) $className = '-';
        if (empty($obsDate)) $obsDate = date('Y-m-d');
    } else {
        if ($teacherId <= 0 || empty($subjectName) || empty($className) || empty($obsDate)) {
            flash('error', 'Harap lengkapi informasi guru, mata pelajaran, kelas, dan tanggal observasi.');
            redirect('peer.php' . ($sessId ? "?session_id={$sessId}" : ($schedId ? "?schedule_id={$schedId}" : '')));
        }
    }

    // Ambil seluruh butir aktif
    $stmtRubrics = $pdo->prepare("
        SELECT sri.id, sri.category_id, sri.min_score, sri.max_score, sri.weight
        FROM supervision_rubric_items sri
        JOIN supervision_categories sc ON sc.id = sri.category_id
        WHERE sc.type_id = ? AND sri.is_active = 1
    ");
    $stmtRubrics->execute([$typeId]);
    $activeRubricItems = $stmtRubrics->fetchAll(PDO::FETCH_ASSOC);

    if (empty($activeRubricItems)) {
        flash('error', 'Instrumen rubrik belum dikonfigurasi atau tidak ada butir aktif.');
        redirect('peer.php');
    }

    // Hitung total skor & skor maksimal
    $totalScore = 0;
    $maxPossibleScore = 0;
    $validScoresToInsert = [];

    foreach ($activeRubricItems as $item) {
        $itemId = (int)$item['id'];
        $itemWeight = (float)$item['weight'];
        $maxPossibleScore += ($item['max_score'] * $itemWeight);

        if (isset($scoresInput[$itemId]) && $scoresInput[$itemId] !== '') {
            $val = (float)$scoresInput[$itemId];
            if ($val < $item['min_score']) $val = (float)$item['min_score'];
            if ($val > $item['max_score']) $val = (float)$item['max_score'];
            $totalScore += ($val * $itemWeight);
            $validScoresToInsert[] = [
                'item_id' => $itemId,
                'score'   => $val
            ];
        } elseif (!$isDraft) {
            $val = (float)$item['min_score'];
            $totalScore += ($val * $itemWeight);
            $validScoresToInsert[] = [
                'item_id' => $itemId,
                'score'   => $val
            ];
        }
    }

    $percentageScore = ($maxPossibleScore > 0) ? round(($totalScore / $maxPossibleScore) * 100, 2) : 0.00;

    if ($isDraft) {
        $predicateName = 'Draf (Belum Selesai)';
    } else {
        $stmtPred = $pdo->prepare("
            SELECT predicate_name 
            FROM supervision_predicates 
            WHERE (type_id = ? OR type_id IS NULL) AND min_percentage <= ?
            ORDER BY min_percentage DESC LIMIT 1
        ");
        $stmtPred->execute([$typeId, $percentageScore]);
        $predicateName = $stmtPred->fetchColumn() ?: 'Need Improvement';
    }

    // Ambil nama tanda tangan
    $stmtTName = $pdo->prepare("SELECT name FROM staff WHERE id = ?");
    $stmtTName->execute([$teacherId]);
    $signedTeacherName = $stmtTName->fetchColumn() ?: 'Guru Terobservasi';

    $stmtOName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmtOName->execute([$observerId]);
    $signedObserverName = $stmtOName->fetchColumn() ?: 'Observer';

    // SIMPAN KE DATABASE DENGAN TRANSAKSI
    $pdo->beginTransaction();
    try {
        if ($sessId > 0) {
            // UPDATE SESI
            $stmtUpd = $pdo->prepare("
                UPDATE supervision_sessions SET
                    schedule_id = ?, status = ?, teacher_id = ?, observer_id = ?,
                    subject_name = ?, class_name = ?, observation_date = ?, room_location = ?,
                    total_score = ?, max_possible_score = ?, percentage_score = ?,
                    predicate_name = ?, notes_good_practice = ?, notes_growth_plan = ?,
                    notes_teacher_reflection = ?, signed_teacher_name = ?, signed_observer_name = ?
                WHERE id = ?
            ");
            $stmtUpd->execute([
                $schedId, $sessionStatus, $teacherId, $observerId,
                $subjectName, $className, $obsDate, $roomLocation,
                $totalScore, $maxPossibleScore, $percentageScore,
                $predicateName, $notesGood, $notesGrowth, $notesReflection,
                $signedTeacherName, $signedObserverName,
                $sessId
            ]);
            $sessionId = $sessId;

            // Hapus skor lama untuk di-refresh
            $pdo->prepare("DELETE FROM supervision_scores WHERE session_id = ?")->execute([$sessionId]);
        } else {
            // INSERT SESI BARU
            $stmtInsert = $pdo->prepare("
                INSERT INTO supervision_sessions (
                    schedule_id, status, type_id, teacher_id, observer_id, subject_name, class_name,
                    observation_date, room_location, total_score, max_possible_score, percentage_score,
                    predicate_name, notes_good_practice, notes_growth_plan, notes_teacher_reflection,
                    signed_teacher_name, signed_observer_name, signed_principal_name
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, 'Awang Darmawan, SS'
                )
            ");
            $stmtInsert->execute([
                $schedId, $sessionStatus, $typeId, $teacherId, $observerId, $subjectName, $className,
                $obsDate, $roomLocation, $totalScore, $maxPossibleScore, $percentageScore,
                $predicateName, $notesGood, $notesGrowth, $notesReflection,
                $signedTeacherName, $signedObserverName
            ]);
            $sessionId = (int)$pdo->lastInsertId();
        }

        // Insert rincian skor per butir
        $stmtScore = $pdo->prepare("INSERT INTO supervision_scores (session_id, item_id, score) VALUES (?, ?, ?)");
        foreach ($validScoresToInsert as $vScore) {
            $stmtScore->execute([$sessionId, $vScore['item_id'], $vScore['score']]);
        }

        // Update status jadwal jika tertaut
        if ($schedId && $schedId > 0) {
            $schedStatus = $isDraft ? 'in_progress' : 'completed';
            $pdo->prepare("UPDATE supervision_schedules SET status = ? WHERE id = ?")->execute([$schedStatus, $schedId]);
        }

        $pdo->commit();

        if ($isDraft) {
            flash('success', "Draf supervisi untuk {$signedTeacherName} berhasil disimpan sementara. Anda dapat melanjutkan penilaian kapan saja.");
            redirect("peer.php?session_id={$sessionId}");
        } else {
            // Kirim notifikasi hasil supervisi selesai
            try {
                // Notif ke guru yang dinilai
                $stmtTUser = $pdo->prepare("SELECT user_id, name FROM staff WHERE id = ?");
                $stmtTUser->execute([$teacherId]);
                $tStaffRow = $stmtTUser->fetch(PDO::FETCH_ASSOC);
                if ($tStaffRow && !empty($tStaffRow['user_id'])) {
                    createNotification(
                        $pdo, 
                        (int)$tStaffRow['user_id'], 
                        'supervisi', 
                        'Supervisi Teman Sejawat Selesai', 
                        "Supervisi pembelajaran Anda untuk mapel {$subjectName} telah selesai dinilai oleh {$signedObserverName} dengan predikat {$predicateName} ({$percentageScore}%).", 
                        "supervisi/reports.php"
                    );
                }

                // Notif ke Kepala Sekolah (dari jadwal)
                if ($schedId && $schedId > 0) {
                    $stmtScP = $pdo->prepare("SELECT principal_id, delegated_user_id FROM supervision_schedules WHERE id = ?");
                    $stmtScP->execute([$schedId]);
                    $scP = $stmtScP->fetch(PDO::FETCH_ASSOC);
                    if ($scP && !empty($scP['principal_id'])) {
                        createNotification(
                            $pdo,
                            (int)$scP['principal_id'],
                            'supervisi',
                            'Laporan Supervisi Sejawat Selesai',
                            "Observer {$signedObserverName} telah menyelesaikan penilaian supervisi sejawat untuk {$signedTeacherName} (Mapel: {$subjectName}) dengan predikat {$predicateName} ({$percentageScore}%).",
                            "supervisi/reports.php"
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log("Supervision completion notification error: " . $e->getMessage());
            }

            flash('success', "Dokumen Supervisi Teman Sejawat untuk {$signedTeacherName} berhasil difinalisasi & disimpan resmi dengan predikat: {$predicateName} ({$percentageScore}%).");
            redirect('reports.php');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Gagal menyimpan supervisi: ' . $e->getMessage());
        redirect('peer.php' . ($sessId ? "?session_id={$sessId}" : ''));
    }
}

// =========================================================================
// QUERY DATA TAMPILAN INSTRUMEN & SCOPING UNIT
// =========================================================================
$userUnit = getUserUnitInfo($pdo, $currentUserId);
$userUnitId = $userUnit['unit_id'] ?? null;
$userUnitName = $userUnit['unit_name'] ?? null;

$isSuperAdmin = ($currentRole === 'super_admin');
$canChooseObserver = in_array($currentRole, ['super_admin', 'admin'], true);

// 1. Ambil daftar guru terobservasi (scoped per unit pengguna, mengecualikan observer sendiri dan siswa)
$teachers = getSupervisionTeachers($pdo, $userUnitId, $currentUserId);

// 2. Ambil daftar observer (hanya jika super admin/admin yang berhak memilih observer lain)
$observers = getSupervisionObservers($pdo, $userUnitId);

// 3. Ambil Kategori & Butir Aktif untuk Sejawat (type_id = 1)
$stmtCats = $pdo->prepare("SELECT * FROM supervision_categories WHERE type_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC");
$stmtCats->execute([$typeId]);
$categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

$categoryIds = array_column($categories, 'id');
$itemsByCategory = [];
$totalMaxScore = 0;
$totalItemsCount = 0;

if (!empty($categoryIds)) {
    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $stmtItems = $pdo->prepare("
        SELECT sri.* 
        FROM supervision_rubric_items sri
        WHERE sri.category_id IN ($placeholders) AND sri.is_active = 1
        ORDER BY sri.sort_order ASC, sri.id ASC
    ");
    $stmtItems->execute($categoryIds);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $it) {
        $itemsByCategory[$it['category_id']][] = $it;
        $totalMaxScore += ($it['max_score'] * $it['weight']);
        $totalItemsCount++;
    }
}

// 4. Predikat untuk JavaScript threshold
$predicates = $pdo->query("SELECT * FROM supervision_predicates ORDER BY min_percentage DESC")->fetchAll(PDO::FETCH_ASSOC);

// Default mata pelajaran untuk saran input
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
    <!-- HEADER NAVIGASI -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <span style="background: #e0e7ff; color: #4338ca; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 6px;">
                    <i class="fa-solid fa-user-pen"></i> OBSERVASI REKAN SEJAWAT
                </span>
                <span style="color: #94a3b8; font-size: 13px;">&bull; SMP An Nahl Islamic School &bull; Integrasi KURMAA</span>
            </div>
            <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #0f172a;">
                Formulir Supervisi Teman Sejawat
            </h1>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 14px;">
                Observasi pembelajaran mendalam (Mindful, Meaningful, Joyful), adab islami, dan perkembangan santri.
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="reports.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Rekap
            </a>
            <a href="rubrics.php?type_id=1" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;" title="Atur Indikator & Skor">
                <i class="fa-solid fa-sliders"></i> Atur Rubrik (<?= $totalItemsCount ?> Butir)
            </a>
        </div>
    </div>

    <!-- STICKY FLOATING LIVE SCORE BAR -->
    <div id="stickyScoreBar" style="position: sticky; top: 12px; z-index: 90; background: #ffffff; border: 2px solid #4f46e5; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.2); margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-gauge-high"></i>
            </div>
            <div>
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Skor Berjalan Realtime:</div>
                <div style="font-size: 20px; font-weight: 800; color: #0f172a;">
                    <span id="liveTotalScore">0</span> <span style="font-size: 14px; color: #94a3b8; font-weight: 600;">/ <?= number_format($totalMaxScore, 0) ?></span>
                    <span id="livePercentage" style="font-size: 15px; font-weight: 700; color: #4f46e5; margin-left: 8px;">(0.00%)</span>
                </div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="text-align: right;">
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Predikat Capaian:</div>
                <span id="livePredicateBadge" style="background: #94a3b8; color: white; font-size: 13px; font-weight: 800; padding: 4px 12px; border-radius: 20px; display: inline-block;">
                    Need Improvement
                </span>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn btn-warning" onclick="submitAsDraft()" <?= $isFormLocked ? 'disabled style="background: #94a3b8; border-color: #94a3b8; color: white; opacity: 0.6; cursor: not-allowed; padding: 9px 15px; border-radius: 8px;"' : 'style="background: #f59e0b; border-color: #f59e0b; color: white; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; padding: 9px 15px; border-radius: 8px;"' ?> title="<?= $isFormLocked ? 'Form terkunci' : 'Simpan penilaian sementara dan lanjutkan nanti' ?>">
                    <i class="fa-solid fa-file-pen"></i> Simpan Draf
                </button>
                <button type="button" class="btn btn-primary" onclick="submitAsCompleted()" <?= $isFormLocked ? 'disabled style="background: #94a3b8; border-color: #94a3b8; opacity: 0.6; cursor: not-allowed; padding: 9px 16px; border-radius: 8px;"' : 'style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px; font-weight: 700;"' ?> title="<?= $isFormLocked ? 'Form terkunci' : 'Finalisasi dokumen supervisi' ?>">
                    <i class="fa-solid fa-check-double"></i> Selesaikan Penilaian
                </button>
            </div>
        </div>
    </div>

    <?php if ($isTimeLocked): ?>
        <!-- BANNER KUNCI JAM (TIME-LOCK) -->
        <div style="background: #fffbeb; border: 1.5px solid #fde68a; border-left: 5px solid #f59e0b; padding: 16px 20px; border-radius: 10px; margin-bottom: 24px; color: #92400e; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-weight: 800; font-size: 14.5px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-clock-rotate-left" style="font-size: 18px;"></i> Jadwal Supervisi Belum Dimulai (Form Terkunci)
            </div>
            <p style="margin: 6px 0 0; font-size: 13px; line-height: 1.5;">
                <?= htmlspecialchars($timeLockMsg) ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($isEditLocked): ?>
        <!-- BANNER IZIN EDIT TERKUNCI (EDIT-LOCK) -->
        <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-left: 5px solid #64748b; padding: 16px 20px; border-radius: 10px; margin-bottom: 24px; color: #334155; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-weight: 800; font-size: 14.5px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-lock" style="font-size: 18px;"></i> Dokumen Supervisi Telah Selesai &amp; Terkunci Permanen
            </div>
            <p style="margin: 6px 0 0; font-size: 13px; line-height: 1.5;">
                <?= htmlspecialchars($editLockMsg) ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($editSession): ?>
        <!-- BANNER MODE RESUME / DRAF -->
        <div style="background: <?= $editSession['status'] === 'draft' ? '#fef3c7' : '#eff6ff' ?>; border-left: 4px solid <?= $editSession['status'] === 'draft' ? '#f59e0b' : '#3b82f6' ?>; padding: 14px 20px; border-radius: 10px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div>
                <div style="font-weight: 800; font-size: 14px; color: <?= $editSession['status'] === 'draft' ? '#92400e' : '#1e40af' ?>;">
                    <i class="fa-solid fa-pen-to-square"></i> Melanjutkan Penilaian Supervisi (<?= $editSession['status'] === 'draft' ? 'Mode Draf' : 'Mode Edit' ?>)
                </div>
                <div style="font-size: 12.5px; color: #475569; margin-top: 2px;">
                    Guru: <strong><?= htmlspecialchars($editSession['signed_teacher_name'] ?: 'Guru') ?></strong> &bull; Butir Terisi: <strong><?= count($savedScores) ?> / <?= $totalItemsCount ?> butir</strong>
                </div>
            </div>
            <span style="background: white; border: 1.5px solid <?= $editSession['status'] === 'draft' ? '#fde68a' : '#bfdbfe' ?>; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; color: <?= $editSession['status'] === 'draft' ? '#b45309' : '#1d4ed8' ?>;">
                STATUS: <?= strtoupper($editSession['status']) ?>
            </span>
        </div>
    <?php endif; ?>

    <!-- FORM PENILAIAN -->
    <form method="POST" id="formSupervisiPeer" onsubmit="return validateBeforeSubmit();">
        <input type="hidden" name="action" value="save_peer_supervision">
        <input type="hidden" name="submit_action" id="submitActionInput" value="save_completed">
        <?php if ($sessionId): ?>
            <input type="hidden" name="session_id" value="<?= $sessionId ?>">
        <?php endif; ?>
        <?php if ($scheduleId): ?>
            <input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
        <?php endif; ?>

        <?php if ($prefillSchedule): ?>
            <!-- BANNER TERIKAT JADWAL RESMI -->
            <div style="margin-bottom: 20px; background: #e0f2fe; border: 1.5px solid #7dd3fc; border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 42px; height: 42px; border-radius: 8px; background: #0284c7; color: white; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div>
                        <div style="font-weight: 800; color: #0369a1; font-size: 14.5px;">Penilaian Terikat Jadwal Supervisi Resmi</div>
                        <div style="font-size: 12.5px; color: #0c4a6e; margin-top: 2px;">
                            Pelaksanaan: <strong><?= formatIndoDate($prefillSchedule['scheduled_date']) ?></strong> &bull; Waktu: <strong><?= !empty($prefillSchedule['time_start']) ? substr($prefillSchedule['time_start'], 0, 5) . (!empty($prefillSchedule['time_end']) ? ' - ' . substr($prefillSchedule['time_end'], 0, 5) : '') : '-' ?></strong> &bull; Ruang: <strong><?= htmlspecialchars($prefillSchedule['room_location'] ?: '-') ?></strong>
                        </div>
                    </div>
                </div>
                <a href="schedules.php" class="btn btn-sm" style="background: white; border: 1.5px solid #0284c7; color: #0284c7; font-weight: 700; border-radius: 6px; padding: 6px 14px; font-size: 12px; text-decoration: none;">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Jadwal
                </a>
            </div>
        <?php endif; ?>

        <!-- KARTU DATA OBSERVASI -->
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
            <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 800; color: #0f172a; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                <i class="fa-solid fa-clipboard-user" style="color: #4f46e5; margin-right: 8px;"></i>
                Identitas Observasi Pembelajaran
            </h3>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
                <!-- GURU YANG DINILAI -->
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Guru yang Dinilai (Terobservasi) *
                        <?php if ($userUnitName): ?>
                            <span style="font-size: 11px; font-weight: 600; color: #0284c7; background: #e0f2fe; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">
                                Unit <?= htmlspecialchars($userUnitName) ?>
                            </span>
                        <?php endif; ?>
                    </label>
                    <?php if ($prefillSchedule): ?>
                        <input type="hidden" name="teacher_id" value="<?= (int)($prefillSchedule['teacher_id'] ?? $editSession['teacher_id']) ?>">
                        <div class="form-control" style="background: #f8fafc; font-weight: 700; color: #0f172a; display: flex; align-items: center; justify-content: space-between; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 14px;">
                            <span><i class="fa-solid fa-chalkboard-user" style="color: #4f46e5; margin-right: 8px;"></i> <?= htmlspecialchars($prefillSchedule['teacher_name'] ?? $editSession['signed_teacher_name'] ?? 'Guru Terdaftar') ?></span>
                            <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 11px; padding: 3px 8px; border-radius: 4px;">Terkunci Sesuai Jadwal</span>
                        </div>
                    <?php else: ?>
                        <select name="teacher_id" id="teacherSelect" class="form-control" required style="font-size: 13.5px; font-weight: 600;">
                            <option value="">-- Pilih Nama Guru --</option>
                            <?php if ($isSuperAdmin && empty($userUnitId)): ?>
                                <?php 
                                $groupedTeachers = [];
                                foreach ($teachers as $tc) {
                                    $uName = $tc['unit_name'] ?: 'Lainnya';
                                    $groupedTeachers[$uName][] = $tc;
                                }
                                foreach ($groupedTeachers as $uLabel => $tList): ?>
                                    <optgroup label="Unit <?= htmlspecialchars($uLabel) ?>">
                                        <?php foreach ($tList as $tc): 
                                            $isSelected = ($editSession && $editSession['teacher_id'] == $tc['id']) || ($prefillSchedule && $prefillSchedule['teacher_id'] == $tc['id']);
                                        ?>
                                            <option value="<?= $tc['id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tc['name']) ?> <?= !empty($tc['nik']) ? '(' . $tc['nik'] . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($teachers as $tc): 
                                    $isSelected = ($editSession && $editSession['teacher_id'] == $tc['id']) || ($prefillSchedule && $prefillSchedule['teacher_id'] == $tc['id']);
                                ?>
                                    <option value="<?= $tc['id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tc['name']) ?> <?= !empty($tc['nik']) ? '(' . $tc['nik'] . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <!-- GURU OBSERVER -->
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Guru Observer (Penilai) *
                    </label>
                    <?php if ($canChooseObserver): ?>
                        <select name="observer_id" id="observerSelect" class="form-control" required style="font-size: 13.5px;">
                            <option value="">-- Pilih Observer --</option>
                            <?php foreach ($observers as $ob): 
                                $isSelected = ($editSession && $editSession['observer_id'] == $ob['id']) || ($prefillSchedule && $prefillSchedule['observer_id'] == $ob['id']) || (!$editSession && !$prefillSchedule && $ob['id'] == $currentUserId);
                            ?>
                                <option value="<?= $ob['id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ob['name']) ?> (<?= htmlspecialchars($ob['role']) ?><?= !empty($ob['unit_name']) ? ' - ' . $ob['unit_name'] : '' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="observer_id" value="<?= $currentUserId ?>">
                        <div class="form-control" style="background: #f8fafc; font-weight: 700; color: #1e293b; display: flex; align-items: center; justify-content: space-between; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 14px;">
                            <span>
                                <i class="fa-solid fa-user-check" style="color: #10b981; margin-right: 6px;"></i> 
                                <?= htmlspecialchars($_SESSION['name'] ?? 'Akun Anda') ?>
                            </span>
                            <span class="badge" style="background: #e0e7ff; color: #4338ca; font-size: 11px; padding: 3px 8px; border-radius: 4px;">
                                Akun Anda (Observer)
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- MATA PELAJARAN -->
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Mata Pelajaran *
                    </label>
                    <input type="text" name="subject_name" list="mapelOptions" placeholder="Misal: Bahasa Inggris" value="<?= htmlspecialchars($editSession['subject_name'] ?? ($prefillSchedule['subject_name'] ?? '')) ?>" class="form-control" required>
                    <datalist id="mapelOptions">
                        <?php foreach ($mapelList as $mp): ?>
                            <option value="<?= htmlspecialchars($mp) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- KELAS -->
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Kelas *
                    </label>
                    <input type="text" name="class_name" placeholder="Misal: 8B, 7A" value="<?= htmlspecialchars($editSession['class_name'] ?? ($prefillSchedule['class_name'] ?? '')) ?>" class="form-control" required>
                </div>

                <!-- HARI & TANGGAL -->
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Hari & Tanggal Supervisi *
                    </label>
                    <input type="date" name="observation_date" value="<?= htmlspecialchars($editSession['observation_date'] ?? ($prefillSchedule['scheduled_date'] ?? date('Y-m-d'))) ?>" class="form-control" required>
                </div>

                <!-- LOKASI / RUANG -->
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Lokasi / Ruang Kelas
                    </label>
                    <input type="text" name="room_location" placeholder="Misal: Ruang Kelas 8B / Lab IPA" value="<?= htmlspecialchars($editSession['room_location'] ?? ($prefillSchedule['room_location'] ?? '')) ?>" class="form-control">
                </div>
            </div>
        </div>

        <!-- INSTRUMEN PENILAIAN PER ASPEK (DINAMIS DARI DATABASE) -->
        <?php foreach ($categories as $catIndex => $cat): 
            $catId = (int)$cat['id'];
            $items = $itemsByCategory[$catId] ?? [];
            if (empty($items)) continue;

            $catMaxScore = 0;
            foreach ($items as $it) {
                $catMaxScore += ($it['max_score'] * $it['weight']);
            }
        ?>
            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 18px; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                            <?= htmlspecialchars($cat['category_name']) ?>
                        </h3>
                        <?php if (!empty($cat['description'])): ?>
                            <p style="margin: 2px 0 0; font-size: 12.5px; color: #64748b;">
                                <?= htmlspecialchars($cat['description']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span style="background: #e0e7ff; color: #4338ca; font-weight: 800; font-size: 13px; padding: 4px 12px; border-radius: 20px;">
                            Subtotal: <span id="catLive_<?= $catId ?>">0</span> / <?= number_format($catMaxScore, 0) ?>
                        </span>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <?php foreach ($items as $idx => $it): 
                        $min = (int)$it['min_score'];
                        $max = (int)$it['max_score'];
                        $weight = (float)$it['weight'];
                    ?>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;" class="indicator-row">
                            <div style="flex: 1; min-width: 280px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                    <span style="background: #e2e8f0; color: #334155; font-size: 11px; font-weight: 800; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;">
                                        <?= $idx + 1 ?>
                                    </span>
                                    <?php if (!empty($it['aspek_label'])): ?>
                                        <span style="font-size: 11px; font-weight: 700; color: #4f46e5; text-transform: uppercase; background: #eef2ff; padding: 2px 8px; border-radius: 4px;">
                                            <?= htmlspecialchars($it['aspek_label']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($weight != 1.0): ?>
                                        <span style="font-size: 10px; color: #64748b; font-weight: 600;">(Bobot &times;<?= $weight ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 13.5px; color: #1e293b; line-height: 1.5; font-weight: 500;">
                                    <?= htmlspecialchars($it['indicator_text']) ?>
                                </div>
                            </div>

                            <!-- TOMBOL LIKERT INTERAKTIF -->
                            <div style="display: flex; gap: 6px; align-items: center;">
                                <?php for ($sc = $min; $sc <= $max; $sc++): 
                                    $isChecked = (isset($savedScores[$it['id']]) && (int)$savedScores[$it['id']] == $sc);
                                ?>
                                    <label class="likert-pill" style="cursor: pointer; margin: 0;">
                                        <input type="radio" 
                                               name="score[<?= $it['id'] ?>]" 
                                               value="<?= $sc ?>" 
                                               data-item-id="<?= $it['id'] ?>"
                                               data-cat-id="<?= $catId ?>"
                                               data-weight="<?= $weight ?>"
                                               onchange="recalculateScores()"
                                               style="display: none;"
                                               <?= $isChecked ? 'checked' : '' ?>>
                                        <span class="pill-btn"><?= $sc ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- CATATAN KUALITATIF & REFLEKSI GURU -->
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 28px;">
            <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 800; color: #0f172a; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                <i class="fa-solid fa-comment-dots" style="color: #4f46e5; margin-right: 8px;"></i>
                Umpan Balik Kualitatif & Teacher Growth Plan
            </h3>

            <div style="display: flex; flex-direction: column; gap: 16px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #15803d; margin-bottom: 6px;">
                        <i class="fa-solid fa-thumbs-up"></i> 1. Praktik Baik yang Ditemukan (Good Practices)
                    </label>
                    <textarea name="notes_good_practice" rows="3" class="form-control" placeholder="Apresiasi keunggulan guru selama observasi pembelajaran..."><?= htmlspecialchars($editSession['notes_good_practice'] ?? '') ?></textarea>
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #b45309; margin-bottom: 6px;">
                        <i class="fa-solid fa-bullseye"></i> 2. Teacher Growth Plan (Rencana Pengembangan yang Disepakati)
                    </label>
                    <textarea name="notes_growth_plan" rows="3" class="form-control" placeholder="Aspek yang disepakati untuk diperbaiki atau ditingkatkan pada siklus berikutnya..."><?= htmlspecialchars($editSession['notes_growth_plan'] ?? '') ?></textarea>
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #4338ca; margin-bottom: 6px;">
                        <i class="fa-solid fa-lightbulb"></i> 3. Refleksi Guru Terobservasi
                    </label>
                    <textarea name="notes_teacher_reflection" rows="3" class="form-control" placeholder="Catatan refleksi guru mengenai hal yang berhasil dan langkah pendalaman KURMAA..."><?= htmlspecialchars($editSession['notes_teacher_reflection'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- TOMBOL SIMPAN AKHIR -->
        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 12px; margin-bottom: 40px; flex-wrap: wrap;">
            <button type="submit" name="submit_action" value="save_draft" onclick="setSubmitType(true)" class="btn btn-warning" <?= $isFormLocked ? 'disabled style="padding: 12px 24px; font-size: 14.5px; font-weight: 800; background: #94a3b8; border-color: #94a3b8; color: white; border-radius: 8px; opacity: 0.6; cursor: not-allowed;"' : 'style="padding: 12px 24px; font-size: 14.5px; font-weight: 800; background: #f59e0b; border-color: #f59e0b; color: white; border-radius: 8px;"' ?>>
                <i class="fa-solid fa-file-pen"></i> Simpan Draf (Lanjutkan Nanti)
            </button>
            <button type="submit" name="submit_action" value="save_completed" onclick="setSubmitType(false)" class="btn btn-primary" <?= $isFormLocked ? 'disabled style="padding: 12px 28px; font-size: 15px; font-weight: 800; background: #94a3b8; border-color: #94a3b8; opacity: 0.6; cursor: not-allowed; border-radius: 8px;"' : 'style="padding: 12px 28px; font-size: 15px; font-weight: 800; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.4); border-radius: 8px;"' ?>>
                <i class="fa-solid fa-check-double"></i> Selesaikan &amp; Simpan Resmi
            </button>
        </div>
    </form>
</div>

<style>
/* Likert Pill Styling */
.likert-pill .pill-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    color: #475569;
    font-weight: 800;
    font-size: 14px;
    transition: all 0.15s ease;
    user-select: none;
}
.likert-pill input:hover + .pill-btn {
    border-color: #6366f1;
    color: #4f46e5;
    background: #f8fafc;
}
.likert-pill input:checked + .pill-btn {
    background: #4f46e5;
    border-color: #4f46e5;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(79, 70, 229, 0.4);
    transform: scale(1.05);
}
</style>

<script>
const TOTAL_MAX_SCORE = <?= (float)$totalMaxScore ?>;
const PREDICATES_LIST = <?= json_encode($predicates) ?>;

function recalculateScores() {
    let grandTotal = 0;
    const catTotals = {};

    document.querySelectorAll('input[type="radio"]:checked').forEach(function(radio) {
        const val = parseFloat(radio.value) || 0;
        const weight = parseFloat(radio.dataset.weight) || 1.0;
        const catId = radio.dataset.catId;
        const weightedScore = val * weight;

        grandTotal += weightedScore;
        catTotals[catId] = (catTotals[catId] || 0) + weightedScore;
    });

    // Update Live Subtotals
    for (const [catId, subtotal] of Object.entries(catTotals)) {
        const el = document.getElementById('catLive_' + catId);
        if (el) el.innerText = subtotal.toFixed(0);
    }

    // Update Live Grand Total & Percentage
    const liveScoreEl = document.getElementById('liveTotalScore');
    const livePctEl = document.getElementById('livePercentage');
    if (liveScoreEl) liveScoreEl.innerText = grandTotal.toFixed(0);

    const pct = TOTAL_MAX_SCORE > 0 ? ((grandTotal / TOTAL_MAX_SCORE) * 100) : 0;
    if (livePctEl) livePctEl.innerText = '(' + pct.toFixed(2) + '%)';

    // Update Predicate Badge
    let matchedPred = 'Need Improvement';
    let matchedColor = '#ef4444';

    for (let p of PREDICATES_LIST) {
        if (pct >= parseFloat(p.min_percentage)) {
            matchedPred = p.predicate_name;
            matchedColor = p.badge_color || '#6366f1';
            break;
        }
    }

    const badgeEl = document.getElementById('livePredicateBadge');
    if (badgeEl) {
        badgeEl.innerText = matchedPred;
        badgeEl.style.backgroundColor = matchedColor;
    }
}

let isDraftSubmit = false;

function setSubmitType(isDraft) {
    isDraftSubmit = isDraft;
    const input = document.getElementById('submitActionInput');
    if (input) input.value = isDraft ? 'save_draft' : 'save_completed';
}

function submitAsDraft() {
    setSubmitType(true);
    document.getElementById('formSupervisiPeer').requestSubmit();
}

function submitAsCompleted() {
    setSubmitType(false);
    document.getElementById('formSupervisiPeer').requestSubmit();
}

function validateBeforeSubmit() {
    if (isDraftSubmit) {
        const teacherSelect = document.getElementById('teacherSelect');
        if (!teacherSelect || !teacherSelect.value) {
            alert('Harap pilih nama guru yang dinilai sebelum menyimpan draf.');
            return false;
        }
        return true;
    }

    const totalRequired = document.querySelectorAll('.indicator-row').length;
    const totalChecked = document.querySelectorAll('input[type="radio"]:checked').length;

    if (totalChecked < totalRequired) {
        const sisa = totalRequired - totalChecked;
        const confirmDraft = confirm('Perhatian: Masih ada ' + sisa + ' butir indikator yang belum dinilai.\n\nKlik [OK] jika ingin menyimpan sebagai DRAF terlebih dahulu dan melanjutkannya nanti.\nKlik [Batal] jika ingin melengkapi nilai sekarang.');
        if (confirmDraft) {
            setSubmitType(true);
            return true;
        }
        return false;
    }
    return confirm('Apakah Anda yakin data supervisi ini sudah lengkap dan ingin difinalisasi secara resmi?');
}

window.addEventListener('DOMContentLoaded', function() {
    recalculateScores();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
