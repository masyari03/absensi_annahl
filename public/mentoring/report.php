<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Laporan & Raport Mentoring Siswa';
$isPrivileged = in_array(currentRole(), ['super_admin', 'kepala_sekolah', 'admin']);
$currentUserId = currentUserId();

// FILTER TAHUN PELAJARAN, BULAN & MENTOR
$academicYears = $pdo->query("SELECT id, name, status FROM academic_years ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$activeAyId = 0;
foreach ($academicYears as $ay) {
    if ($ay['status'] === 'active') {
        $activeAyId = (int)$ay['id'];
        break;
    }
}
if ($activeAyId === 0 && !empty($academicYears)) {
    $activeAyId = (int)$academicYears[0]['id'];
}

$filterAyId   = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : $activeAyId;
$filterMonth  = trim($_GET['month'] ?? '');
$filterClass  = trim($_GET['class'] ?? '');
$filterMentor = (int)($_GET['mentor_id'] ?? 0);
$selectedStudentId = (int)($_GET['student_id'] ?? 0);

if (!$isPrivileged) {
    $filterMentor = $currentUserId;
}

// DAFTAR GURU MENTOR
$mentorsList = $pdo->query("SELECT id, name FROM users WHERE role IN ('staff', 'admin', 'kepala_sekolah') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$classGroups = $pdo->query("SELECT id, name FROM class_groups ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// DAFTAR SELURUH SANTRI DENGAN MENTORNYA
$sqlMentees = "
    SELECT s.id, s.name, s.nis, cg.name as class_name, u.name as mentor_name, ma.group_name
    FROM students s
    JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN mentoring_assignments ma ON ma.student_id = s.id AND ma.academic_year_id = ? AND ma.is_active = 1
    LEFT JOIN users u ON u.id = ma.mentor_user_id
    WHERE s.deleted_at IS NULL
";
$paramsMentees = [$filterAyId];
if ($filterMentor > 0) {
    $sqlMentees .= " AND ma.mentor_user_id = ?";
    $paramsMentees[] = $filterMentor;
}
if (!empty($filterClass)) {
    $sqlMentees .= " AND cg.name = ?";
    $paramsMentees[] = $filterClass;
}
$sqlMentees .= " ORDER BY cg.name ASC, s.name ASC";
$stmtM = $pdo->prepare($sqlMentees);
$stmtM->execute($paramsMentees);
$menteesList = $stmtM->fetchAll(PDO::FETCH_ASSOC);

if ($selectedStudentId === 0 && !empty($menteesList)) {
    $selectedStudentId = (int)$menteesList[0]['id'];
}

// TARIK DETAIL SANTRI TERPILIH
$selectedStudent = null;
foreach ($menteesList as $m) {
    if ($m['id'] == $selectedStudentId) {
        $selectedStudent = $m;
        break;
    }
}
if (!$selectedStudent && $selectedStudentId > 0) {
    $stmtS = $pdo->prepare("
        SELECT s.id, s.name, s.nis, cg.name as class_name, u.name as mentor_name, ma.group_name
        FROM students s
        LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
        LEFT JOIN class_groups cg ON cg.id = se.class_group_id
        LEFT JOIN mentoring_assignments ma ON ma.student_id = s.id AND ma.academic_year_id = ? AND ma.is_active = 1
        LEFT JOIN users u ON u.id = ma.mentor_user_id
        WHERE s.id = ?
    ");
    $stmtS->execute([$filterAyId, $selectedStudentId]);
    $selectedStudent = $stmtS->fetch(PDO::FETCH_ASSOC);
}

// TARIK RIWAYAT SESI EVALUASI 5 PILAR SANTRI TERPILIH
$sessions = [];
$avgScores = ['mip' => 0, 'academic' => 0, 'mutabaah' => 0, 'adab' => 0];
if ($selectedStudentId > 0) {
    $sqlSess = "
        SELECT ms.*, u.name as mentor_name
        FROM mentoring_sessions ms
        JOIN users u ON u.id = ms.mentor_user_id
        WHERE ms.student_id = ?
    ";
    $paramsSess = [$selectedStudentId];
    if (!empty($filterMonth)) {
        $sqlSess .= " AND ms.month_name = ?";
        $paramsSess[] = $filterMonth;
    }
    $sqlSess .= " ORDER BY ms.session_date DESC, ms.id DESC";
    $stmtSess = $pdo->prepare($sqlSess);
    $stmtSess->execute($paramsSess);
    $sessions = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($sessions)) {
        $totMip = 0; $totAcad = 0; $totMut = 0; $totAdab = 0;
        $cntMip = 0; $cntAcad = 0; $cntMut = 0; $cntAdab = 0;
        foreach ($sessions as $s) {
            if ($s['score_mip'] > 0) { $totMip += $s['score_mip']; $cntMip++; }
            if ($s['score_academic'] > 0) { $totAcad += $s['score_academic']; $cntAcad++; }
            if ($s['score_mutabaah'] > 0) { $totMut += $s['score_mutabaah']; $cntMut++; }
            if ($s['score_adab'] > 0) { $totAdab += $s['score_adab']; $cntAdab++; }
        }
        $avgScores['mip'] = $cntMip > 0 ? round($totMip / $cntMip, 1) : 0;
        $avgScores['academic'] = $cntAcad > 0 ? round($totAcad / $cntAcad, 1) : 0;
        $avgScores['mutabaah'] = $cntMut > 0 ? round($totMut / $cntMut, 1) : 0;
        $avgScores['adab'] = $cntAdab > 0 ? round($totAdab / $cntAdab, 1) : 0;
    }
}

// LOG ADAB PEKANAN TERAKHIR SANTRI
$stmtAdabLogs = $pdo->prepare("
    SELECT * FROM student_adab_logs 
    WHERE student_id = ? 
    ORDER BY submitted_at DESC 
    LIMIT 4
");
$stmtAdabLogs->execute([$selectedStudentId]);
$adabLogs = $stmtAdabLogs->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../../includes/header.php';
?>

<style>
@media print {
    .no-print, #sidebar, .header, .footer, .btn, .filter-box {
        display: none !important;
    }
    .content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    .print-sheet {
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
        width: 100% !important;
    }
    body {
        background: #ffffff !important;
        color: #000000 !important;
        font-size: 12pt;
    }
}
</style>

<div class="content">
    <!-- Header Page -->
    <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-file-invoice" style="color: #059669;"></i> Laporan & Raport Karakter Mentoring MIP
            </h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">
                Rekapitulasi berkala 5 pilar karakter santri dan cetak Raport A4 portrait resmi
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <button type="button" class="btn btn-primary" onclick="window.print()" style="padding: 9px 18px; font-size: 13px; font-weight: 700; background: #0f172a; border-color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-print"></i> Cetak Raport Murid (A4)
            </button>
            <a href="app.php" target="_blank" class="btn" style="padding: 9px 16px; font-size: 13px; font-weight: 700; background: #059669; color: white; border-radius: 8px; text-decoration: none; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-desktop"></i> Mode Aplikasi Penuh (SPA)
            </a>
            <a href="index.php" class="btn btn-secondary" style="padding: 9px 16px; font-size: 13px;">
                <i class="fa-solid fa-arrow-left"></i> Dashboard Mentoring
            </a>
        </div>
    </div>

    <!-- Filter Bar Interaktif -->
    <div class="card filter-box no-print" style="padding: 16px 20px; border-radius: 14px; margin-bottom: 24px; background: #ffffff;">
        <form method="GET" action="report.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <!-- Tahun Pelajaran -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Tahun Pelajaran</label>
                <select name="academic_year_id" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1.5px solid #059669; font-size: 13px; font-weight: 700; color: #047857; background: #ecfdf5;" onchange="this.form.submit()">
                    <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $filterAyId == $ay['id'] ? 'selected' : '' ?>>
                            TP <?= e($ay['name']) ?> <?= $ay['status'] === 'active' ? '(Aktif)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Pilih Santri -->
            <div style="flex: 1; min-width: 220px;">
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Pilih Santri Binaan</label>
                <select name="student_id" class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 700; color: #0f172a;" onchange="this.form.submit()">
                    <?php if (empty($menteesList)): ?>
                        <option value="">-- Tidak ada santri terdaftar --</option>
                    <?php else: ?>
                        <?php foreach ($menteesList as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $selectedStudentId == $m['id'] ? 'selected' : '' ?>>
                                <?= e($m['name']) ?> (Kelas <?= e($m['class_name'] ?? '-') ?> • <?= e($m['mentor_name'] ?? 'Belum ada mentor') ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Filter Bulan -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Bulan</label>
                <select name="month" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Bulan --</option>
                    <?php foreach (['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'] as $bln): ?>
                        <option value="<?= $bln ?>" <?= $filterMonth === $bln ? 'selected' : '' ?>><?= $bln ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Mentor (Khusus Pimpinan) -->
            <?php if ($isPrivileged): ?>
                <div>
                    <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Filter Mentor</label>
                    <select name="mentor_id" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                        <option value="0">-- Semua Guru Mentor --</option>
                        <?php foreach ($mentorsList as $mn): ?>
                            <option value="<?= $mn['id'] ?>" <?= $filterMentor == $mn['id'] ? 'selected' : '' ?>><?= e($mn['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div style="align-self: flex-end;">
                <?php if (!empty($filterMonth) || $filterMentor > 0): ?>
                    <a href="report.php?academic_year_id=<?= $filterAyId ?>&student_id=<?= $selectedStudentId ?>" class="btn btn-secondary" style="padding: 8px 14px; font-size: 13px;">Reset Filter</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$selectedStudent): ?>
        <div class="card" style="padding: 40px; text-align: center; color: #94a3b8; border-radius: 16px;">
            <i class="fa-solid fa-user-xmark" style="font-size: 40px; margin-bottom: 12px; display: block; opacity: 0.5;"></i>
            Belum ada santri binaan yang dipilih atau sesuai filter.
        </div>
    <?php else: ?>
        <!-- ========================================================
             LEMBAR CETAK RAPORT A4 PORTRAIT RESMI (PRINT SHEET)
             ======================================================== -->
        <div class="card print-sheet" style="padding: 32px 36px; border-radius: 18px; background: #ffffff; max-width: 860px; margin: 0 auto; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
            
            <!-- KOP SURAT SEKOLAH -->
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2.5px solid #0f172a; padding-bottom: 14px; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <img src="../logofull.jpg" alt="Logo An Nahl" style="height: 60px; width: auto; object-fit: contain;" onerror="this.style.display='none'">
                    <div>
                        <h2 style="margin: 0; font-size: 16px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px;">
                            RAPOR KARAKTER & EVALUASI MENTORING (MIP)
                        </h2>
                        <h3 style="margin: 2px 0 0; font-size: 13px; font-weight: 800; color: #059669;">
                            SMP AN NAHL ISLAMIC SCHOOL
                        </h3>
                        <p style="margin: 2px 0 0; font-size: 11px; color: #64748b;">
                            Tahun Ajaran <?= e($currAyName ?? '2026/2027') ?> • Sistem Evaluasi 5 Pilar Pembinaan Santri
                        </p>
                    </div>
                </div>

                <div style="text-align: right;">
                    <span style="display: inline-block; padding: 4px 10px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; font-weight: 800; color: #1e293b; text-transform: uppercase;">
                        Raport Karakter
                    </span>
                    <div style="font-size: 10px; color: #94a3b8; margin-top: 4px;">
                        Dicetak: <?= date('d F Y') ?>
                    </div>
                </div>
            </div>

            <!-- PROFIL SANTRI & MENTOR -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; font-size: 12.5px;">
                <div>
                    <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Nama Santri:</span>
                    <strong style="font-size: 14px; color: #0f172a;"><?= e($selectedStudent['name']) ?></strong>
                    <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">NIS: <?= e($selectedStudent['nis']) ?></div>
                </div>
                <div>
                    <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Kelas & Rombel:</span>
                    <strong style="font-size: 13.5px; color: #0284c7;">Kelas <?= e($selectedStudent['class_name'] ?? '-') ?></strong>
                    <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">Halaqah: <?= e($selectedStudent['group_name'] ?: 'Halaqah Mandiri') ?></div>
                </div>
                <div>
                    <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Guru Mentor Pembimbing:</span>
                    <strong style="font-size: 13.5px; color: #0f172a;"><?= e($selectedStudent['mentor_name'] ?? 'Belum Ditugaskan') ?></strong>
                    <div style="font-size: 11.5px; color: #059669; font-weight: 600; margin-top: 2px;">Total Sesi: <?= count($sessions) ?> kali tatap muka</div>
                </div>
            </div>

            <!-- 4 KARTU RATA-RATA ASPEK PEMBINAAN -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 22px;">
                <div style="border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; text-align: center; background: #f0f9ff; border-top: 4px solid #0284c7;">
                    <div style="font-size: 11px; font-weight: 700; color: #0369a1; text-transform: uppercase;">Pilar 1: MIP</div>
                    <div style="font-size: 22px; font-weight: 900; color: #0284c7; margin-top: 4px;"><?= $avgScores['mip'] ?: '-' ?></div>
                    <div style="font-size: 10px; color: #64748b; margin-top: 2px;">Motivasi & Ibadah</div>
                </div>

                <div style="border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; text-align: center; background: #ecfdf5; border-top: 4px solid #10b981;">
                    <div style="font-size: 11px; font-weight: 700; color: #047857; text-transform: uppercase;">Pilar 2: Akademik</div>
                    <div style="font-size: 22px; font-weight: 900; color: #059669; margin-top: 4px;"><?= $avgScores['academic'] ?: '-' ?></div>
                    <div style="font-size: 10px; color: #64748b; margin-top: 2px;">Prestasi & KBM</div>
                </div>

                <div style="border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; text-align: center; background: #fefce8; border-top: 4px solid #f59e0b;">
                    <div style="font-size: 11px; font-weight: 700; color: #b45309; text-transform: uppercase;">Pilar 3: Mutaba'ah</div>
                    <div style="font-size: 22px; font-weight: 900; color: #d97706; margin-top: 4px;"><?= $avgScores['mutabaah'] ?: '-' ?></div>
                    <div style="font-size: 10px; color: #64748b; margin-top: 2px;">Kedisiplinan Amal</div>
                </div>

                <div style="border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; text-align: center; background: #faf5ff; border-top: 4px solid #8b5cf6;">
                    <div style="font-size: 11px; font-weight: 700; color: #6d28d9; text-transform: uppercase;">Pilar 4: Adab</div>
                    <div style="font-size: 22px; font-weight: 900; color: #7c3aed; margin-top: 4px;"><?= $avgScores['adab'] ?: '-' ?></div>
                    <div style="font-size: 10px; color: #64748b; margin-top: 2px;">Akhlaqul Karimah</div>
                </div>
            </div>

            <!-- TABEL DETAIL RIWAYAT EVALUASI SESI MENTORING -->
            <div style="margin-bottom: 24px;">
                <h4 style="margin: 0 0 10px; font-size: 13.5px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-list-check" style="color: #0284c7;"></i> Riwayat Jurnal & Evaluasi Bimbingan Tatap Muka
                </h4>

                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                    <thead>
                        <tr style="background: #f1f5f9; border-top: 1px solid #cbd5e1; border-bottom: 2px solid #94a3b8; text-align: left;">
                            <th style="padding: 8px 10px;">Tanggal / Sesi</th>
                            <th style="padding: 8px 10px; text-align: center;">MIP</th>
                            <th style="padding: 8px 10px; text-align: center;">Akademik</th>
                            <th style="padding: 8px 10px; text-align: center;">Mutaba'ah</th>
                            <th style="padding: 8px 10px; text-align: center;">Adab</th>
                            <th style="padding: 8px 10px;">Catatan & Arahan Motivasi Guru Mentor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sessions)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px; color: #94a3b8;">
                                    Belum ada catatan evaluasi sesi tatap muka untuk santri ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $s): ?>
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 8px 10px; white-space: nowrap;">
                                        <strong><?= date('d/m/Y', strtotime($s['session_date'])) ?></strong>
                                        <div style="font-size: 11px; color: #0284c7;"><?= e($s['session_code']) ?></div>
                                    </td>
                                    <td style="padding: 8px 10px; text-align: center; font-weight: 800; color: #0284c7;"><?= $s['score_mip'] ?: '-' ?></td>
                                    <td style="padding: 8px 10px; text-align: center; font-weight: 800; color: #059669;"><?= $s['score_academic'] ?: '-' ?></td>
                                    <td style="padding: 8px 10px; text-align: center; font-weight: 800; color: #d97706;"><?= $s['score_mutabaah'] ?: '-' ?></td>
                                    <td style="padding: 8px 10px; text-align: center; font-weight: 800; color: #7c3aed;"><?= $s['score_adab'] ?: '-' ?></td>
                                    <td style="padding: 8px 10px; color: #334155; line-height: 1.4;">
                                        <?= !empty($s['notes_feedback']) ? nl2br(e($s['notes_feedback'])) : '-' ?>
                                        <?php if (!empty($s['notes_mip']) || !empty($s['notes_adab'])): ?>
                                            <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                                                <?= !empty($s['notes_mip']) ? '<em>MIP: ' . e($s['notes_mip']) . '</em> ' : '' ?>
                                                <?= !empty($s['notes_adab']) ? '<em>Adab: ' . e($s['notes_adab']) . '</em>' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- TANDA TANGAN RESMI KEPALA SEKOLAH & GURU MENTOR -->
            <div style="display: flex; justify-content: space-between; margin-top: 36px; padding: 0 20px; font-size: 12px; color: #0f172a; page-break-inside: avoid;">
                <div style="text-align: center; width: 220px;">
                    <div>Mengetahui,</div>
                    <div style="font-weight: 700; margin-top: 2px;">Kepala SMP An Nahl Islamic School</div>
                    <div style="height: 60px;"></div>
                    <div style="font-weight: 800; text-decoration: underline;">Ustadz Muhammad Asy'ari, M.Pd.</div>
                    <div style="font-size: 11px; color: #64748b;">NIP. 2026.07.001</div>
                </div>

                <div style="text-align: center; width: 220px;">
                    <div>Bogor, <?= date('d F Y') ?></div>
                    <div style="font-weight: 700; margin-top: 2px;">Guru Mentor Pendamping</div>
                    <div style="height: 60px;"></div>
                    <div style="font-weight: 800; text-decoration: underline;"><?= e($selectedStudent['mentor_name'] ?? 'Guru Mentor') ?></div>
                    <div style="font-size: 11px; color: #64748b;">Pembimbing Karakter Santri</div>
                </div>
            </div>

        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
