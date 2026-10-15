<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Dashboard Mentoring Siswa (MIP)';
$currentRole = currentRole();
$userId = currentUserId();
$isPrivileged = in_array($currentRole, ['super_admin', 'kepala_sekolah']);

// Flash message
$flashMessage = '';
$flashType = 'success';

// PROSES SIMPAN / UPDATE JURNAL SESI MENTORING
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_session') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Sesi telah kadaluarsa. Silakan ulangi kembali.');
        redirect('index.php');
    }

    $studentId    = (int)($_POST['student_id'] ?? 0);
    $mentorUserId = $isPrivileged && !empty($_POST['mentor_user_id']) ? (int)$_POST['mentor_user_id'] : $userId;
    $sessionDate  = $_POST['session_date'] ?? date('Y-m-d');
    $sessionCode  = trim($_POST['session_code'] ?? 'Pekan 1 - Selasa');
    $monthName    = date('F', strtotime($sessionDate));
    $yearVal      = (int)date('Y', strtotime($sessionDate));

    $scoreMip   = !empty($_POST['score_mip']) ? (float)$_POST['score_mip'] : null;
    $notesMip   = trim($_POST['notes_mip'] ?? '');

    $scoreAcad  = !empty($_POST['score_academic']) ? (float)$_POST['score_academic'] : null;
    $notesAcad  = trim($_POST['notes_academic'] ?? '');

    $scoreMut   = !empty($_POST['score_mutabaah']) ? (float)$_POST['score_mutabaah'] : null;
    $notesMut   = trim($_POST['notes_mutabaah'] ?? '');

    $scoreAdab  = !empty($_POST['score_adab']) ? (float)$_POST['score_adab'] : null;
    $notesAdab  = trim($_POST['notes_adab'] ?? '');

    $notesFb    = trim($_POST['notes_feedback'] ?? '');

    if ($studentId > 0) {
        // Keamanan: Jika bukan Super Admin/Kepsek, pastikan santri ini memang mentee binaan user yang sedang login
        if (!$isPrivileged) {
            $checkAssign = $pdo->prepare("SELECT COUNT(*) FROM mentoring_assignments WHERE student_id = ? AND mentor_user_id = ? AND is_active = 1");
            $checkAssign->execute([$studentId, $userId]);
            if ($checkAssign->fetchColumn() == 0) {
                flash('danger', 'Akses ditolak: Santri ini bukan merupakan santri binaan kelompok Anda.');
                redirect('index.php');
            }
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO mentoring_sessions (
                student_id, mentor_user_id, session_date, session_code, month_name, year_val,
                score_mip, notes_mip, score_academic, notes_academic, score_mutabaah, notes_mutabaah,
                score_adab, notes_adab, score_feedback, notes_feedback, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW())
        ");
        $stmtIns->execute([
            $studentId, $mentorUserId, $sessionDate, $sessionCode, $monthName, $yearVal,
            $scoreMip, $notesMip, $scoreAcad, $notesAcad, $scoreMut, $notesMut,
            $scoreAdab, $notesAdab, $notesFb
        ]);

        flash('success', 'Jurnal sesi mentoring santri berhasil dicatat dan tersimpan ke basis data.');
    }
    redirect('index.php');
}

// FILTER MENTOR (Hanya Super Admin & Kepsek yang bisa ganti filter mentor)
$filterMentorId = 0;
if ($isPrivileged) {
    $filterMentorId = (int)($_GET['mentor_id'] ?? 0);
} else {
    $filterMentorId = $userId;
}

// Daftar Seluruh Guru Mentor (untuk dropdown filter pimpinan)
$mentorsList = [];
if ($isPrivileged) {
    $mentorsList = $pdo->query("
        SELECT DISTINCT u.id, u.name, u.username
        FROM users u
        INNER JOIN mentoring_assignments ma ON ma.mentor_user_id = u.id
        ORDER BY u.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// TARIK TAHUN PELAJARAN AKTIF
$activeAyId = (int)$pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1")->fetchColumn();
if ($activeAyId === 0) {
    $activeAyId = (int)$pdo->query("SELECT id FROM academic_years ORDER BY id DESC LIMIT 1")->fetchColumn();
}

// TARIK DAFTAR SANTRI BINAAN
$whereAssigned = ["ma.is_active = 1"];
$paramsAssigned = [];

if ($activeAyId > 0) {
    $whereAssigned[] = "ma.academic_year_id = ?";
    $paramsAssigned[] = $activeAyId;
}

if ($filterMentorId > 0) {
    $whereAssigned[] = "ma.mentor_user_id = ?";
    $paramsAssigned[] = $filterMentorId;
}

$whereSql = !empty($whereAssigned) ? "WHERE " . implode(' AND ', $whereAssigned) : "";

$stmtMentees = $pdo->prepare("
    SELECT s.id, s.name, s.nis, s.parent_phone, cg.name as class_name,
           u.name as mentor_name, u.id as mentor_id, ma.group_name,
           (SELECT COUNT(*) FROM mentoring_sessions ms WHERE ms.student_id = s.id) as total_sessions,
           (SELECT ROUND(AVG(score_adab), 1) FROM mentoring_sessions ms WHERE ms.student_id = s.id AND score_adab IS NOT NULL) as avg_adab,
           (SELECT ROUND(AVG(score_mutabaah), 1) FROM mentoring_sessions ms WHERE ms.student_id = s.id AND score_mutabaah IS NOT NULL) as avg_mutabaah,
           (SELECT MAX(session_date) FROM mentoring_sessions ms WHERE ms.student_id = s.id) as last_session_date
    FROM mentoring_assignments ma
    JOIN students s ON s.id = ma.student_id
    JOIN users u ON u.id = ma.mentor_user_id
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    {$whereSql}
    ORDER BY cg.name ASC, s.name ASC
");
$stmtMentees->execute($paramsAssigned);
$mentees = $stmtMentees->fetchAll(PDO::FETCH_ASSOC);

// METRIK RINGKASAN
$totalMentees = count($mentees);
$totalSessionsAll = 0;
$sumAdab = 0;
$countAdab = 0;

foreach ($mentees as $m) {
    $totalSessionsAll += (int)$m['total_sessions'];
    if (!empty($m['avg_adab'])) {
        $sumAdab += (float)$m['avg_adab'];
        $countAdab++;
    }
}
$overallAvgAdab = $countAdab > 0 ? round($sumAdab / $countAdab, 1) : 0;

// TARIK RIWAYAT SESI TERBARU
$stmtRecent = $pdo->prepare("
    SELECT ms.*, s.name as student_name, s.nis, cg.name as class_name, u.name as mentor_name
    FROM mentoring_sessions ms
    JOIN students s ON s.id = ms.student_id
    JOIN users u ON u.id = ms.mentor_user_id
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    " . ($filterMentorId > 0 ? "WHERE ms.mentor_user_id = ?" : "") . "
    ORDER BY ms.session_date DESC, ms.id DESC
    LIMIT 25
");
$stmtRecent->execute($filterMentorId > 0 ? [$filterMentorId] : []);
$recentSessions = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../../includes/header.php';
?>

<style>
@media (max-width: 768px) {
    /* Sembunyikan thead tabel */
    .responsive-card-table thead {
        display: none !important;
    }
    
    /* Ubah baris tabel menjadi bentuk Card mandiri */
    .responsive-card-table, 
    .responsive-card-table tbody, 
    .responsive-card-table tr {
        display: block !important;
        width: 100% !important;
    }
    
    .responsive-card-table tr {
        background: #ffffff;
        border: 1px solid #e2e8f0 !important;
        border-radius: 14px;
        padding: 14px 16px !important;
        margin-bottom: 14px;
        box-shadow: 0 2px 6px -1px rgba(0, 0, 0, 0.05);
    }
    
    /* Setiap td menjadi flex item dengan label */
    .responsive-card-table td {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding: 8px 0 !important;
        border-bottom: 1px dashed #f1f5f9 !important;
        text-align: right !important;
        white-space: normal !important;
    }
    
    .responsive-card-table td:last-child {
        border-bottom: none !important;
        padding-top: 12px !important;
        margin-top: 6px;
        justify-content: flex-end !important;
        gap: 8px;
    }
    
    .responsive-card-table td::before {
        content: attr(data-label);
        font-weight: 700;
        font-size: 11.5px;
        color: #64748b;
        text-transform: uppercase;
        text-align: left;
        padding-right: 12px;
        flex-shrink: 0;
    }

    /* Khusus sel santri utama buat rata kiri penuh */
    .responsive-card-table td[data-label="Santri"] {
        display: block !important;
        text-align: left !important;
        padding-bottom: 10px !important;
        border-bottom: 1px solid #e2e8f0 !important;
    }
    .responsive-card-table td[data-label="Santri"]::before {
        display: none !important;
    }
}
</style>

<div class="content">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-hands-holding-child" style="color: #0284c7;"></i> Mentoring Siswa & Student Journal (MIP)
            </h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">
                Pendampingan karakter holistik santri An Nahl Islamic School (5 Pilar MIP)
            </p>
        </div>

        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <a href="report.php" class="btn" style="padding: 8px 14px; font-size: 13px; font-weight: 700; background: #0f172a; color: white; border-radius: 8px; text-decoration: none; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-print"></i> Laporan & Cetak Raport
            </a>

            <?php if ($isPrivileged): ?>
                <a href="assignments.php" class="btn" style="padding: 8px 14px; font-size: 13px; font-weight: 700; background: #0284c7; color: white; border-radius: 8px; text-decoration: none; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-users-rectangle"></i> Kelola Kelompok Mentoring
                </a>
            <?php endif; ?>

            <?php if ($isPrivileged): ?>
                <!-- Filter Pilihan Guru Mentor Khusus Pimpinan -->
                <form method="GET" style="display: flex; gap: 8px; align-items: center;">
                    <select name="mentor_id" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                        <option value="0">-- Semua Guru Mentor (<?= count($mentorsList) ?>) --</option>
                        <?php foreach ($mentorsList as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $filterMentorId == $m['id'] ? 'selected' : '' ?>>
                                <?= e($m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($filterMentorId > 0): ?>
                        <a href="index.php" class="btn btn-secondary" style="padding: 8px 12px; font-size: 13px;">Reset</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4 Metrik Ringkasan -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="card" style="padding: 18px; border-radius: 14px; border-left: 4px solid #0284c7;">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Santri Binaan</span>
            <div style="font-size: 28px; font-weight: 800; color: #0f172a; margin-top: 4px;">
                <?= $totalMentees ?> <span style="font-size: 14px; font-weight: 600; color: #94a3b8;">Santri</span>
            </div>
        </div>

        <div class="card" style="padding: 18px; border-radius: 14px; border-left: 4px solid #10b981;">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Total Sesi Evaluasi</span>
            <div style="font-size: 28px; font-weight: 800; color: #10b981; margin-top: 4px;">
                <?= $totalSessionsAll ?> <span style="font-size: 14px; font-weight: 600; color: #94a3b8;">Sesi Tatap Muka</span>
            </div>
        </div>

        <div class="card" style="padding: 18px; border-radius: 14px; border-left: 4px solid #8b5cf6;">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Rata-rata Skor Adab</span>
            <div style="font-size: 28px; font-weight: 800; color: #8b5cf6; margin-top: 4px;">
                <?= $overallAvgAdab > 0 ? $overallAvgAdab : '-' ?> <span style="font-size: 14px; font-weight: 600; color: #94a3b8;">/10</span>
            </div>
        </div>

        <div class="card" style="padding: 18px; border-radius: 14px; border-left: 4px solid #f59e0b;">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Frekuensi Rutin</span>
            <div style="font-size: 18px; font-weight: 800; color: #f59e0b; margin-top: 6px;">
                Selasa & Jumat
            </div>
            <small style="color: #64748b; font-size: 11.5px;">Pekan 1 s.d. Pekan 4</small>
        </div>
    </div>

    <!-- Tabel Daftar Santri Binaan -->
    <div class="card" style="margin-bottom: 24px; padding: 20px; border-radius: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                <i class="fa-solid fa-users-viewfinder" style="color: #0284c7;"></i> Daftar Santri Binaan
            </h3>
            <span style="font-size: 12px; color: #64748b;">
                <?= count($mentees) ?> Santri Terdaftar
            </span>
        </div>

        <div class="table-responsive">
            <table class="table responsive-card-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 12px;">Santri</th>
                        <th style="padding: 12px;">Kelas</th>
                        <th style="padding: 12px;">Mentor Pendamping</th>
                        <th style="padding: 12px; text-align: center;">Total Sesi</th>
                        <th style="padding: 12px; text-align: center;">Rata-rata Adab</th>
                        <th style="padding: 12px; text-align: center;">Sesi Terakhir</th>
                        <th style="padding: 12px; text-align: right;">Aksi Bimbingan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mentees)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8;">
                                Belum ada santri binaan yang dialokasikan untuk filter ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mentees as $m): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td data-label="Santri" style="padding: 12px;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                                        <div>
                                            <strong style="font-size: 14.5px; color: #0f172a;"><?= e($m['name']) ?></strong>
                                            <div style="font-size: 11.5px; color: #64748b;">NIS: <?= e($m['nis']) ?></div>
                                        </div>
                                        <span style="padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; background: #e0f2fe; color: #0369a1;">
                                            <?= e($m['class_name'] ?? 'Santri') ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($m['group_name'])): ?>
                                        <div style="margin-top: 4px;">
                                            <span style="display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 10.5px; font-weight: 700; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;">
                                                <i class="fa-solid fa-users"></i> <?= e($m['group_name']) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Kelas" style="padding: 12px;">
                                    <span style="padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; background: #e0f2fe; color: #0369a1;">
                                        <?= e($m['class_name'] ?? 'Santri') ?>
                                    </span>
                                </td>
                                <td data-label="Mentor" style="padding: 12px;">
                                    <span style="font-size: 13px; font-weight: 600; color: #334155;">
                                        <?= e($m['mentor_name']) ?>
                                    </span>
                                </td>
                                <td data-label="Total Sesi" style="padding: 12px; text-align: center;">
                                    <span style="font-weight: 800; font-size: 14px; color: #0284c7;">
                                        <?= $m['total_sessions'] ?> Sesi
                                    </span>
                                </td>
                                <td data-label="Rata-rata Adab" style="padding: 12px; text-align: center;">
                                    <?php if (!empty($m['avg_adab'])): ?>
                                        <span style="padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: 800; background: #f3e8ff; color: #7e22ce;">
                                            <?= $m['avg_adab'] ?> / 10
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Sesi Terakhir" style="padding: 12px; text-align: center; font-size: 12px; color: #64748b;">
                                    <?= !empty($m['last_session_date']) ? date('d/m/Y', strtotime($m['last_session_date'])) : '<span style="color:#cbd5e1;">Belum ada</span>' ?>
                                </td>
                                <td data-label="Aksi" style="padding: 12px; text-align: right; white-space: nowrap;">
                                    <a href="report.php?student_id=<?= $m['id'] ?>" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px; font-weight: 700; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-right: 4px;" title="Lihat Laporan & Cetak Raport">
                                        <i class="fa-solid fa-print"></i> Raport
                                    </a>
                                    <button type="button" class="btn btn-primary" style="padding: 6px 12px; font-size: 12.5px; font-weight: 700; border-radius: 8px;" onclick="openSessionModal(<?= $m['id'] ?>, '<?= addslashes(e($m['name'])) ?>', <?= $m['mentor_id'] ?>)">
                                        <i class="fa-solid fa-pen-to-square"></i> Input Jurnal
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Riwayat Sesi Terkini -->
    <div class="card" style="padding: 20px; border-radius: 16px;">
        <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 800; color: #0f172a;">
            <i class="fa-solid fa-clock-rotate-left" style="color: #059669;"></i> 25 Log Sesi Mentoring Terkini
        </h3>

        <div class="table-responsive">
            <table class="table responsive-card-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 10px;">Tanggal / Sesi</th>
                        <th style="padding: 10px;">Santri</th>
                        <th style="padding: 10px;">Mentor</th>
                        <th style="padding: 10px; text-align: center;">MIP</th>
                        <th style="padding: 10px; text-align: center;">Akademik</th>
                        <th style="padding: 10px; text-align: center;">Mutaba'ah</th>
                        <th style="padding: 10px; text-align: center;">Adab</th>
                        <th style="padding: 10px;">Catatan & Solusi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentSessions)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 25px; color: #94a3b8;">
                                Belum ada sesi mentoring yang tercatat.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentSessions as $rs): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; font-size: 12.5px;">
                                <td data-label="Santri" style="padding: 10px;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                                        <div>
                                            <strong style="font-size: 14px; color: #0f172a;"><?= e($rs['student_name']) ?></strong>
                                            <div style="font-size: 11px; color: #64748b;"><?= e($rs['class_name'] ?? '-') ?></div>
                                        </div>
                                        <div style="text-align: right;">
                                            <strong style="font-size: 12px; color: #334155;"><?= date('d/m/Y', strtotime($rs['session_date'])) ?></strong>
                                            <div style="font-size: 11px; color: #0284c7; font-weight: 700;"><?= e($rs['session_code']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Tanggal & Sesi" style="padding: 10px;">
                                    <strong><?= date('d/m/Y', strtotime($rs['session_date'])) ?></strong>
                                    <div style="font-size: 11px; color: #0284c7; font-weight: 700;"><?= e($rs['session_code']) ?></div>
                                </td>
                                <td data-label="Mentor" style="padding: 10px; color: #334155;">
                                    <?= e($rs['mentor_name']) ?>
                                </td>
                                <td data-label="Skor MIP" style="padding: 10px; text-align: center; font-weight: 800; color: #0284c7;">
                                    <?= !empty($rs['score_mip']) ? $rs['score_mip'] : '-' ?>
                                </td>
                                <td data-label="Skor Akademik" style="padding: 10px; text-align: center; font-weight: 800; color: #10b981;">
                                    <?= !empty($rs['score_academic']) ? $rs['score_academic'] : '-' ?>
                                </td>
                                <td data-label="Skor Mutaba'ah" style="padding: 10px; text-align: center; font-weight: 800; color: #f59e0b;">
                                    <?= !empty($rs['score_mutabaah']) ? $rs['score_mutabaah'] : '-' ?>
                                </td>
                                <td data-label="Skor Adab" style="padding: 10px; text-align: center; font-weight: 800; color: #8b5cf6;">
                                    <?= !empty($rs['score_adab']) ? $rs['score_adab'] : '-' ?>
                                </td>
                                <td data-label="Catatan" style="padding: 10px; color: #475569; max-width: 250px;">
                                    <?= !empty($rs['notes_feedback']) ? e(mb_strimwidth($rs['notes_feedback'], 0, 80, '...')) : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL INPUT JURNAL SESI MENTORING -->
<div id="modalSession" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: white; border-radius: 16px; width: 100%; max-width: 650px; max-height: 90vh; overflow-y: auto; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 18px;">
            <div>
                <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;">
                    <i class="fa-solid fa-pen-nib" style="color: #0284c7;"></i> Input Jurnal Mentoring
                </h3>
                <small style="color: #64748b;">Santri: <strong id="modalStudentName" style="color:#0f172a;">-</strong></small>
            </div>
            <button type="button" onclick="closeSessionModal()" style="border: none; background: none; font-size: 20px; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_session">
            <input type="hidden" name="student_id" id="modalStudentId" value="">
            <input type="hidden" name="mentor_user_id" id="modalMentorUserId" value="">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Tanggal Pertemuan</label>
                    <input type="date" name="session_date" value="<?= date('Y-m-d') ?>" class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" required>
                </div>
                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Kode Sesi</label>
                    <select name="session_code" class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                        <option value="Pekan 1 - Selasa">Pekan 1 - Selasa</option>
                        <option value="Pekan 1 - Jumat">Pekan 1 - Jumat</option>
                        <option value="Pekan 2 - Selasa">Pekan 2 - Selasa</option>
                        <option value="Pekan 2 - Jumat">Pekan 2 - Jumat</option>
                        <option value="Pekan 3 - Selasa">Pekan 3 - Selasa</option>
                        <option value="Pekan 3 - Jumat">Pekan 3 - Jumat</option>
                        <option value="Pekan 4 - Selasa">Pekan 4 - Selasa</option>
                        <option value="Pekan 4 - Jumat">Pekan 4 - Jumat</option>
                    </select>
                </div>
            </div>

            <!-- 5 Pilar Form -->
            <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px;">
                <!-- Pilar 1 -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-size: 13px; font-weight: 700; color: #0284c7;">1. MIP (Student Journal)</label>
                        <input type="number" step="0.1" min="0" max="10" name="score_mip" placeholder="Skor (0-10)" style="width: 90px; padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; font-weight: 700;">
                    </div>
                    <input type="text" name="notes_mip" placeholder="Catatan target, hafalan, keterisian jurnal..." style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                </div>

                <!-- Pilar 2 -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-size: 13px; font-weight: 700; color: #10b981;">2. Akademik & Non Akademik</label>
                        <input type="number" step="0.1" min="0" max="10" name="score_academic" placeholder="Skor (0-10)" style="width: 90px; padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; font-weight: 700;">
                    </div>
                    <input type="text" name="notes_academic" placeholder="Kendala mapel (MTK, B.Arab, IPA), remedial..." style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                </div>

                <!-- Pilar 3 -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-size: 13px; font-weight: 700; color: #f59e0b;">3. Mutaba'ah Ibadah</label>
                        <input type="number" step="0.1" min="0" max="10" name="score_mutabaah" placeholder="Skor (0-10)" style="width: 90px; padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; font-weight: 700;">
                    </div>
                    <input type="text" name="notes_mutabaah" placeholder="Sholat 5 waktu berjamaah, tahajud, dhuha, tilawah..." style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                </div>

                <!-- Pilar 4 -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-size: 13px; font-weight: 700; color: #8b5cf6;">4. Adab & Perilaku</label>
                        <input type="number" step="0.1" min="0" max="10" name="score_adab" placeholder="Skor (0-10)" style="width: 90px; padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; font-weight: 700;">
                    </div>
                    <input type="text" name="notes_adab" placeholder="Adab lisan, bercanda fisik, pinjam barang, adab di kelas..." style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                </div>

                <!-- Pilar 5 -->
                <div style="background: #fdf2f8; border: 1px solid #fbcfe8; border-radius: 10px; padding: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #db2777; margin-bottom: 6px;">
                        5. Masukan, Rekomendasi & Arahan Khusus Mentor
                    </label>
                    <textarea name="notes_feedback" rows="2" placeholder="Catatan tindak lanjut untuk santri / koordinasi dengan orang tua & wali kelas..." style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #f472b6; font-size: 12.5px; resize: vertical;"></textarea>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeSessionModal()" class="btn btn-secondary" style="padding: 10px 18px; font-size: 13px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-size: 13px; font-weight: 700;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Jurnal Evaluasi
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openSessionModal(studentId, studentName, mentorId) {
    document.getElementById('modalStudentId').value = studentId;
    document.getElementById('modalStudentName').textContent = studentName;
    document.getElementById('modalMentorUserId').value = mentorId;
    document.getElementById('modalSession').style.display = 'flex';
}

function closeSessionModal() {
    document.getElementById('modalSession').style.display = 'none';
}

// Tutup modal jika klik di luar
window.onclick = function(event) {
    var modal = document.getElementById('modalSession');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
