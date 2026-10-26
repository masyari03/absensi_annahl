<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
if (($_SESSION['role'] ?? '') === 'siswa') {
    header('Location: ../student/mentoring_notes.php');
    exit;
}
requireMenuAccess('mentoring_report');

$pageTitle = 'Laporan & Raport Mentoring Siswa';
$isPrivileged = in_array(currentRole(), ['super_admin', 'admin', 'kepala_sekolah']) || hasMenuAccess('mentoring_assignments');
$currentUserId = currentUserId();

// 1. FILTER TAHUN PELAJARAN, BULAN & MENTOR
$academicYears = $pdo->query("SELECT id, name, status FROM academic_years ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$activeAyId = 0;
$activeAyName = '2026/2027';
foreach ($academicYears as $ay) {
    if ($ay['status'] === 'active') {
        $activeAyId = (int)$ay['id'];
        $activeAyName = $ay['name'];
        break;
    }
}
if ($activeAyId === 0 && !empty($academicYears)) {
    $activeAyId = (int)$academicYears[0]['id'];
    $activeAyName = $academicYears[0]['name'];
}

$filterAyId   = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : $activeAyId;
$filterMonth  = trim($_GET['month'] ?? 'ALL');
$filterYear   = trim($_GET['year'] ?? 'ALL');
$filterClass  = trim($_GET['class'] ?? '');
$filterMentor = (int)($_GET['mentor_id'] ?? 0);
$selectedStudentId = (int)($_GET['student_id'] ?? 0);

if (!$isPrivileged) {
    $filterMentor = $currentUserId;
}

// Cari Nama Tahun Ajaran Terpilih
$currAyName = $activeAyName;
foreach ($academicYears as $ay) {
    if ($ay['id'] == $filterAyId) {
        $currAyName = $ay['name'];
        break;
    }
}

// 2. DAFTAR GURU MENTOR (Deduplikasi Akun Guru Berdasarkan Nama & Memiliki Penugasan Aktif)
$stmtMentors = $pdo->prepare("
    SELECT MIN(u.id) as id, TRIM(u.name) as name, COUNT(ma.id) as total_mentees
    FROM users u
    INNER JOIN mentoring_assignments ma ON ma.mentor_user_id = u.id AND ma.is_active = 1
    WHERE (ma.academic_year_id = ? OR ? = 0)
    GROUP BY TRIM(u.name)
    ORDER BY TRIM(u.name) ASC
");
$stmtMentors->execute([$filterAyId, $filterAyId]);
$mentorsList = $stmtMentors->fetchAll(PDO::FETCH_ASSOC);

// Fallback: Jika belum ada penugasan di TP ini, ambil dari semua TP
if (empty($mentorsList)) {
    $mentorsList = $pdo->query("
        SELECT MIN(u.id) as id, TRIM(u.name) as name, COUNT(ma.id) as total_mentees
        FROM users u
        INNER JOIN mentoring_assignments ma ON ma.mentor_user_id = u.id AND ma.is_active = 1
        GROUP BY TRIM(u.name)
        ORDER BY TRIM(u.name) ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Fallback kedua: Jika tabel penugasan masih kosong
if (empty($mentorsList)) {
    $mentorsList = $pdo->query("
        SELECT MIN(id) as id, TRIM(name) as name, 0 as total_mentees
        FROM users 
        WHERE role IN ('staff', 'admin', 'kepala_sekolah')
        GROUP BY TRIM(name)
        ORDER BY TRIM(name) ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$accRep = getMentoringAccessCondition('g', 'cg');
$cgStmt = $pdo->prepare("SELECT cg.id, cg.name FROM class_groups cg JOIN grades g ON g.id = cg.grade_id WHERE " . $accRep['condition'] . " ORDER BY cg.name ASC");
$cgStmt->execute($accRep['params']);
$classGroups = $cgStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. DAFTAR SELURUH SANTRI SESUAI FILTER
$sqlMentees = "
    SELECT s.id, s.name, s.nis, cg.name as class_name, u.name as mentor_name, ma.group_name
    FROM students s
    JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    LEFT JOIN mentoring_assignments ma ON ma.student_id = s.id AND ma.academic_year_id = ? AND ma.is_active = 1
    LEFT JOIN users u ON u.id = ma.mentor_user_id
    WHERE s.deleted_at IS NULL AND " . $accRep['condition'] . "
";
$paramsMentees = array_merge([$filterAyId], $accRep['params']);
if ($filterMentor > 0) {
    // Ambil SEMUA akun milik mentor tersebut (mencegah santri terputus akibat akun duplikat NIP vs Nama)
    $linkedMentorIds = getMentorAllAccountIds($pdo, $filterMentor);
    $inQ = implode(',', array_fill(0, count($linkedMentorIds), '?'));
    $sqlMentees .= " AND ma.mentor_user_id IN ($inQ)";
    foreach ($linkedMentorIds as $lId) {
        $paramsMentees[] = (int)$lId;
    }
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

// 4. TARIK DETAIL SANTRI TERPILIH
$selectedStudent = null;
foreach ($menteesList as $m) {
    if ($m['id'] == $selectedStudentId) {
        $selectedStudent = $m;
        break;
    }
}
if (!$selectedStudent && $selectedStudentId > 0 && $isPrivileged) {
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

// 5. TARIK DATA EVALUASI 4 SESI PEKANAN (P1, P2, P3, P4) UNTUK SANTRI TERPILIH
$pekanData = [
    'P1' => null,
    'P2' => null,
    'P3' => null,
    'P4' => null,
];
$allSessionsForStudent = [];

if ($selectedStudentId > 0) {
    $sqlSess = "
        SELECT ms.*, u.name as mentor_name
        FROM mentoring_sessions ms
        JOIN users u ON u.id = ms.mentor_user_id
        WHERE ms.student_id = ?
    ";
    $paramsSess = [$selectedStudentId];
    if ($filterMonth !== 'ALL' && !empty($filterMonth)) {
        $sqlSess .= " AND ms.month_name = ?";
        $paramsSess[] = $filterMonth;
    }
    if ($filterYear !== 'ALL' && !empty($filterYear)) {
        $sqlSess .= " AND YEAR(ms.session_date) = ?";
        $paramsSess[] = $filterYear;
    }
    $sqlSess .= " ORDER BY ms.session_date ASC, ms.id ASC";
    $stmtSess = $pdo->prepare($sqlSess);
    $stmtSess->execute($paramsSess);
    $allSessionsForStudent = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allSessionsForStudent as $s) {
        $pKey = strtoupper(trim($s['week_code'] ?? ''));
        if (empty($pKey) || !in_array($pKey, ['P1', 'P2', 'P3', 'P4'])) {
            $rawStr = ($s['week_code'] ?? '') . ' ' . ($s['session_code'] ?? '');
            if (preg_match('/Pekan\s*1\b|P1\b/i', $rawStr)) {
                $pKey = 'P1';
            } elseif (preg_match('/Pekan\s*2\b|P2\b/i', $rawStr)) {
                $pKey = 'P2';
            } elseif (preg_match('/Pekan\s*3\b|P3\b/i', $rawStr)) {
                $pKey = 'P3';
            } elseif (preg_match('/Pekan\s*4\b|P4\b/i', $rawStr)) {
                $pKey = 'P4';
            }
        }

        if (in_array($pKey, ['P1', 'P2', 'P3', 'P4'])) {
            $pekanData[$pKey] = $s;
        } elseif (empty($pekanData['P1'])) {
            $pekanData['P1'] = $s;
        } elseif (empty($pekanData['P2'])) {
            $pekanData['P2'] = $s;
        } elseif (empty($pekanData['P3'])) {
            $pekanData['P3'] = $s;
        } elseif (empty($pekanData['P4'])) {
            $pekanData['P4'] = $s;
        }
    }
}

// 6. HELPER AMBIL SKOR & CATATAN PER PEKAN
function getScoreVal($pekanData, $pKey, $field) {
    if (isset($pekanData[$pKey]) && $pekanData[$pKey] && isset($pekanData[$pKey][$field])) {
        $val = $pekanData[$pKey][$field];
        return ($val !== null && $val !== '' && $val > 0) ? (float)$val : '-';
    }
    return '-';
}

function getAspectNotes($allSessionsForStudent, $noteField) {
    $notes = [];
    foreach ($allSessionsForStudent as $s) {
        if (!empty($s[$noteField])) {
            $notes[] = trim($s[$noteField]);
        }
    }
    if (!empty($notes)) {
        return implode('; ', array_unique($notes));
    }
    return 'Perkembangan terpantau sangat baik.';
}

// 7. TARIK ISIAN REFLEKSI ADAB SANTRI (4 DIMENSI)
$adabRefleksi = [
    'refleksi1' => '--',
    'refleksi2' => '--',
    'refleksi3' => '--',
    'refleksi4' => '--',
];

$monthMap = [
    'Januari' => 1, 'Februari' => 2, 'Maret' => 3, 'April' => 4,
    'Mei' => 5, 'Juni' => 6, 'Juli' => 7, 'Agustus' => 8,
    'September' => 9, 'Oktober' => 10, 'November' => 11, 'Desember' => 12
];

if ($selectedStudentId > 0) {
    $sqlAdab = "SELECT * FROM student_adab_logs WHERE student_id = ?";
    $paramsAdab = [$selectedStudentId];
    if ($filterMonth !== 'ALL' && !empty($filterMonth)) {
        $mNum = $monthMap[$filterMonth] ?? 0;
        if ($mNum > 0) {
            $sqlAdab .= " AND MONTH(submitted_at) = ?";
            $paramsAdab[] = $mNum;
        }
    }
    $sqlAdab .= " ORDER BY submitted_at DESC LIMIT 1";
    $stmtAdab = $pdo->prepare($sqlAdab);
    $stmtAdab->execute($paramsAdab);
    $adabRow = $stmtAdab->fetch(PDO::FETCH_ASSOC);

    if ($adabRow) {
        $adabRefleksi['refleksi1'] = !empty($adabRow['reflection_action']) ? $adabRow['reflection_action'] : (!empty($adabRow['refleksi1']) ? $adabRow['refleksi1'] : '--');
        $adabRefleksi['refleksi2'] = !empty($adabRow['reflection_obstacle']) ? $adabRow['reflection_obstacle'] : (!empty($adabRow['refleksi2']) ? $adabRow['refleksi2'] : '--');
        $adabRefleksi['refleksi3'] = !empty($adabRow['reflection_solution']) ? $adabRow['reflection_solution'] : (!empty($adabRow['refleksi3']) ? $adabRow['refleksi3'] : '--');
        $adabRefleksi['refleksi4'] = !empty($adabRow['reflection_gratitude']) ? $adabRow['reflection_gratitude'] : (!empty($adabRow['refleksi4']) ? $adabRow['refleksi4'] : '--');
    } else {
        if ($filterMonth !== 'ALL') {
            $msgEmpty = "-- (Belum ada isian refleksi adab untuk bulan $filterMonth) --";
            $adabRefleksi = [
                'refleksi1' => $msgEmpty,
                'refleksi2' => $msgEmpty,
                'refleksi3' => $msgEmpty,
                'refleksi4' => $msgEmpty,
            ];
        }
    }
}

// 8. TEKS PERIODE LAPORAN
$txtPeriode = "Pekan 1 s/d Pekan 4";
if ($filterMonth !== 'ALL' || $filterYear !== 'ALL') {
    $bStr = ($filterMonth !== 'ALL') ? $filterMonth : '';
    $yStr = ($filterYear !== 'ALL') ? $filterYear : '';
    $txtPeriode .= " (" . trim("$bStr $yStr") . ")";
}

require __DIR__ . '/../../includes/header.php';
?>

<!-- STYLE PRINT A4 STRICT EMULATING SPA EXACT SPECIFICATION -->
<style>
/* Responsive styling untuk tampilan layar HP / Tablet (Screen View) */
@media screen and (max-width: 768px) {
    .print-sheet {
        padding: 14px 12px !important;
        border-radius: 12px !important;
        box-sizing: border-box !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: hidden !important;
    }
    
    .screen-table-responsive {
        width: 100% !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 12px;
        border-radius: 8px;
    }

    .screen-table-responsive table {
        min-width: 580px !important;
    }

    #rptAspectsBarDiagram {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 10px !important;
    }

    .student-info-grid {
        grid-template-columns: 1fr !important;
        gap: 6px !important;
    }

    .reflection-grid {
        grid-template-columns: 1fr !important;
        gap: 8px !important;
    }

    .signature-container {
        padding: 12px 6px 0 !important;
    }
    .signature-box {
        width: 140px !important;
    }
}

@media print {
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
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
        max-width: 100% !important;
    }
    body {
        background: white !important;
        font-size: 9.5pt;
        color: black !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    #rptAspectsBarDiagram {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 6px !important;
    }
    .print-aspect-card {
        border: 1px solid #cbd5e1 !important;
        background-color: #f8fafc !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        padding: 6px !important;
    }
    .print-bar-container {
        border: 1px solid #e2e8f0 !important;
        background-color: #ffffff !important;
    }
    .print-bar-fill {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .print-break-inside-avoid { 
        break-inside: avoid !important; 
        page-break-inside: avoid !important; 
    }
    @page { 
        size: A4 portrait; 
        margin: 6mm 6mm 6mm 6mm; 
    }
}
</style>

<div class="content">
    <!-- Header Page (No Print) -->
    <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-file-invoice" style="color: #059669;"></i> Laporan & Raport Mentoring Siswa
            </h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">
                Format A4 Portrait Resmi 1 Halaman — Dilengkapi Diagram Perkembangan 4 Aspek & Refleksi Adab
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <button type="button" class="btn btn-primary" onclick="window.print()" style="padding: 9px 18px; font-size: 13px; font-weight: 700; background: #0f172a; border-color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-print"></i> Cetak Raport Murid (A4)
            </button>
            <a href="index.php" class="btn btn-secondary" style="padding: 9px 16px; font-size: 13px;">
                <i class="fa-solid fa-arrow-left"></i> Dashboard Mentoring
            </a>
        </div>
    </div>

    <!-- Filter Bar Interaktif (No Print) -->
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
                    <option value="ALL" <?= $filterMonth === 'ALL' ? 'selected' : '' ?>>-- Semua Bulan --</option>
                    <?php foreach (['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'] as $bln): ?>
                        <option value="<?= $bln ?>" <?= $filterMonth === $bln ? 'selected' : '' ?>><?= $bln ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Tahun Masehi -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Tahun</label>
                <select name="year" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                    <option value="ALL" <?= $filterYear === 'ALL' ? 'selected' : '' ?>>-- Semua Tahun --</option>
                    <?php foreach ([2026, 2027, 2028] as $y): ?>
                        <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
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
                <?php if ($filterMonth !== 'ALL' || $filterYear !== 'ALL' || $filterMentor > 0): ?>
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
             100% IDENTIK DENGAN VERSI SPA / APLIKASI PENUH
             ======================================================== -->
        <div class="print-sheet" style="padding: 24px 28px; border-radius: 18px; background: #ffffff; max-width: 860px; margin: 0 auto; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;">
            
            <!-- 1. REPORT HEADER KOP SEKOLAH -->
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2.5px solid #0f172a; padding-bottom: 10px; margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <img src="../logofull.jpg" alt="Logo An Nahl" style="height: 52px; width: auto; object-fit: contain;" onerror="this.src='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAKMAAABZCAYAAABSZZT/AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAACr+SURBVHhe7Z1nlFRluu/9cObec9bcdWfOqKgEZdQZHRMKNNB0k0FAQERFMCLmrNAZMSGGMTuGcUwzJlARuoEmIyJ0zjnRuZtOVV3VlXP97nreXbspWs9d9x6/lHQ9a729d+28+/3v/xP3u0/jFJEg4FMtSDAovwIECeAnoJb5ZaNAaCM34PKCsw+68uHwu/R9cB/9L91E78aV9H6SBkUZYGsGXz8EHASDfnUOaepPIABBHwT9ofOG1kXlvy2nDV3waxUBm1sBzwf+APgCCnwy6wlqYFFo8XjBbQFrG1TsxPnVOnpeup6+9bMYSIrF9uQM+p5ZSMfLN2P/9nmoPwS2bnC51EGCclgBtTqiV031uSgYf5mcMmAUFvTgQ/GUjkC/Bg+NtQLgtoO7H46X4Nr1DsdfX4Vxw7XYn52HJWky7tTJ2JPGY02eguWp2fQ+t5juN+4juPtT6KwHmx28GiMG8RAIekLsqz0MUTD+MjllwKhoMODVVKdSx/JHVLUAxwmeXrC0wJHN2N9PxLbxJkxPzaE3aQqGtbFYkuMYSI5hIOEqnImT8KTFY0uOx5g8F+uLd+L+9HkoPww9DeCzKB4WkCv1LxIcnIvKf1NOHTAqO84fmgpKhMHcELCArQnacujd/BK9r96LMXkB9oR47Mmx2FOnYkudgTl5BsbUGQykxuNMiseZOAV74mSsyVOxps7DsH4ZPS/dg2/7u9CaD66+0Dnk5AGCAU/oxFH578opA0ZlDoZUsl/9EbvRDOZ6nHvep+ONezFsWE5/2gLMKbMxJ2pANCdMxpI0A0PCLDoT5tO+Zh79SXOwpM7AkBpLb+okjOumY0ybzcCT8zBtvJGedx6DI1vBbtXsyKBfOUpRNf3L5JQDozjKAfnh94K5CeeBT+h/617MT16DLXGWYkRrUhwmYcW0aZiTp9G9ZgbOV1cz8PcUTO8l0bX+enqTZ2JKnYr1qakYUqZiSJqCNXEyrvWzMD27BMN7a/HmHACTWZkGUTD+cjllwCgiYPD6QqDw25Sj0vLO4xx/chHWpGm41k7FlxiPIyEWS8IUTKlx9KTNpuvJpbDvQ7C1QF8V/q830pk4F/OjV+F6/CpsyTOxps2lf+0kLGlTMa+fRuszi+n79m3oawOvW6PjKBp/kZw6YFRsqNOiqGgTtOfT8vr99D+1CEdiHM7EqfiS4/AkTFL2oCExnpbkq+l/dy20F4DbAK5eKN2J4ZU71T6+xyfiTJyJec10xagDiZNwrY/FsGEhps1/hb5G8NjBF7UZf6mcWmD0SThHgOjRQjgt2bS8/TCGZ67FnBiHac1kBUJXcgzu1FiMCdNpTluKZ88nYO8CvxMCdqXeTd+8TLuo9bVTcCZMw5U4HV/STGyPTcKTMo2BDUsxbn4Z+mrBY9YNhKFXFZX/Dzm1wKh70X6PFn5pzKb5jQfpW3eNUtOWpFgVT3QmT8aWEkd3wmyOv/oANOSB1wZeFwRc4DbiKcig8amlDKTMxLpmKp6E6bjXTsP+WCzepHjMz11Lz6bnwVgHPmvIdYqC8ZfIqQNGERXrC6hsiGK5xgKOv/YIlpQFylZ0psViS4lV4ZrexBm0pSzB9d3r4GiHoB0CYvsJIJ3QVUn/RwkcT56FaW08jsRpWNZOw5Y4DU9KLJaNizB8uwFM9cpeVNZBVH6RnEJg1ALcoRwMeO3QVEz3G0/gSF6If80UHAkxihmtKdM5njSPY8/dAXkZKv8c8Jnx9DYSMAow3eDshR8+ozZ5EcaUWVgTY1UQ3JE2DXtiDAPPL8K4+VnorVZZGUn2nIxHPeiuTU+0qPxXEllglN4MBZFPavry'"/>
                    <div>
                        <h2 style="margin: 0; font-size: 14.5px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: -0.01em; line-height: 1.2;">
                            LAPORAN EVALUASI MENTORING MIP
                        </h2>
                        <h3 style="margin: 2px 0 0; font-size: 11.5px; font-weight: 800; color: #334155; line-height: 1.2;">
                            SMP AN NAHL ISLAMIC SCHOOL
                        </h3>
                        <p style="margin: 2px 0 0; font-size: 9.5px; color: #64748b;">
                            Tahun Ajaran <?= e($currAyName) ?> - Sistem Evaluasi 4 Aspek Pembinaan
                        </p>
                    </div>
                </div>

                <div style="text-align: right; font-size: 11px;">
                    <span style="display: inline-block; padding: 3px 10px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 9999px; font-size: 10px; font-weight: 800; color: #1e293b; text-transform: uppercase;">
                        Raport Mentoring
                    </span>
                </div>
            </div>

            <!-- 2. REPORT STUDENT METADATA (2x2 Grid) -->
            <div class="student-info-grid" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; margin-bottom: 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 11px;">
                <div>
                    <span style="font-size: 9px; color: #64748b; font-weight: 800; text-transform: uppercase; display: block;">Nama Murid / Siswa:</span>
                    <h4 style="margin: 2px 0 0; font-size: 12.5px; font-weight: 900; color: #0f172a;"><?= e($selectedStudent['name']) ?></h4>
                </div>
                <div>
                    <span style="font-size: 9px; color: #64748b; font-weight: 800; text-transform: uppercase; display: block;">NIS / Kelas:</span>
                    <h4 style="margin: 2px 0 0; font-size: 12.5px; font-weight: 900; color: #0f172a;"><?= e($selectedStudent['nis']) ?> / <?= e($selectedStudent['class_name'] ?? '7A') ?></h4>
                </div>
                <div>
                    <span style="font-size: 9px; color: #64748b; font-weight: 800; text-transform: uppercase; display: block;">Mentor Pembimbing:</span>
                    <h4 style="margin: 2px 0 0; font-size: 12.5px; font-weight: 900; color: #0f172a;"><?= e($selectedStudent['mentor_name'] ?? 'Ust. Mentor') ?></h4>
                </div>
                <div>
                    <span style="font-size: 9px; color: #64748b; font-weight: 800; text-transform: uppercase; display: block;">Periode Laporan:</span>
                    <h4 style="margin: 2px 0 0; font-size: 12.5px; font-weight: 900; color: #0f172a;"><?= e($txtPeriode) ?></h4>
                </div>
            </div>

            <!-- 3. A. RINCIAN EVALUASI 4 ASPEK MENTORING -->
            <div style="margin-bottom: 14px;">
                <h4 style="margin: 0 0 6px; font-size: 11px; font-weight: 900; color: #0f172a; text-transform: uppercase;">
                    A. RINCIAN EVALUASI 4 ASPEK MENTORING
                </h4>
                <div class="screen-table-responsive">
                    <table style="width: 100%; border-collapse: collapse; font-size: 10px; border: 1px solid #cbd5e1;">
                        <thead style="background: #f1f5f9; color: #1e293b; font-weight: 800; text-align: center;">
                            <tr>
                                <th style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: left;">Aspek Evaluasi Mentoring</th>
                                <th style="padding: 6px 8px; border: 1px solid #cbd5e1; width: 48px;">Pekan 1</th>
                                <th style="padding: 6px 8px; border: 1px solid #cbd5e1; width: 48px;">Pekan 2</th>
                                <th style="padding: 6px 8px; border: 1px solid #cbd5e1; width: 48px;">Pekan 3</th>
                                <th style="padding: 6px 8px; border: 1px solid #cbd5e1; width: 48px;">Pekan 4</th>
                                <th style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: left;">Catatan Mentor & Advice</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Aspek 1: MIP -->
                            <tr>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; font-weight: 800;">1. MIP (Mission Is Possible)</td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P1', 'score_mip') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P2', 'score_mip') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P3', 'score_mip') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P4', 'score_mip') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; color: #475569; font-style: italic; font-size: 9.5px;"><?= e(getAspectNotes($allSessionsForStudent, 'notes_mip')) ?></td>
                            </tr>
                            <!-- Aspek 2: Akademik -->
                            <tr>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; font-weight: 800;">2. Akademik & Non-Akademik</td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P1', 'score_academic') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P2', 'score_academic') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P3', 'score_academic') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P4', 'score_academic') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; color: #475569; font-style: italic; font-size: 9.5px;"><?= e(getAspectNotes($allSessionsForStudent, 'notes_academic')) ?></td>
                            </tr>
                            <!-- Aspek 3: Mutabaah -->
                            <tr>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; font-weight: 800;">3. Mutaba'ah Yaumiyah</td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P1', 'score_mutabaah') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P2', 'score_mutabaah') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P3', 'score_mutabaah') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;"><?= getScoreVal($pekanData, 'P4', 'score_mutabaah') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; color: #475569; font-style: italic; font-size: 9.5px;"><?= e(getAspectNotes($allSessionsForStudent, 'notes_mutabaah')) ?></td>
                            </tr>
                            <!-- Aspek 4: Adab -->
                            <tr style="background-color: #faf5ff !important;">
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; font-weight: 800; color: #581c87;">4. Adab & Karakter</td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800; color: #581c87;"><?= getScoreVal($pekanData, 'P1', 'score_adab') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800; color: #581c87;"><?= getScoreVal($pekanData, 'P2', 'score_adab') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800; color: #581c87;"><?= getScoreVal($pekanData, 'P3', 'score_adab') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800; color: #581c87;"><?= getScoreVal($pekanData, 'P4', 'score_adab') ?></td>
                                <td style="padding: 6px 8px; border: 1px solid #cbd5e1; color: #475569; font-style: italic; font-size: 9.5px;"><?= e(getAspectNotes($allSessionsForStudent, 'notes_adab')) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 4. B. DIAGRAM GRAFIK PERKEMBANGAN & TENDENSI CAPAIAN 4 ASPEK -->
            <div style="margin-bottom: 14px;">
                <h4 style="margin: 0 0 6px; font-size: 11px; font-weight: 900; color: #0f172a; text-transform: uppercase;">
                    B. DIAGRAM GRAFIK PERKEMBANGAN & TENDENSI CAPAIAN 4 ASPEK
                </h4>
                
                <div id="rptAspectsBarDiagram" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                    <?php
                    $aspectConfigs = [
                        ['key' => 'score_mip', 'label' => '1. MIP', 'hex' => '#10b981', 'border' => '#a7f3d0', 'bg' => '#ecfdf5'],
                        ['key' => 'score_academic', 'label' => '2. Akademik', 'hex' => '#3b82f6', 'border' => '#bfdbfe', 'bg' => '#eff6ff'],
                        ['key' => 'score_mutabaah', 'label' => '3. Mutaba\'ah', 'hex' => '#f59e0b', 'border' => '#fde68a', 'bg' => '#fffbeb'],
                        ['key' => 'score_adab', 'label' => '4. Adab & Karakter', 'hex' => '#9333ea', 'border' => '#e9d5ff', 'bg' => '#faf5ff'],
                    ];

                    foreach ($aspectConfigs as $aspect):
                        $scores = [];
                        foreach (['P1', 'P2', 'P3', 'P4'] as $pKey) {
                            $val = getScoreVal($pekanData, $pKey, $aspect['key']);
                            $scores[] = is_numeric($val) ? (float)$val : null;
                        }

                        $validScores = array_filter($scores, fn($s) => $s !== null);
                        $avgText = '-';
                        $trendBadge = '<span style="padding: 2px 5px; border-radius: 4px; font-size: 8.5px; font-weight: 600; background: #f1f5f9; color: #64748b;">Belum Ada Data</span>';

                        if (!empty($validScores)) {
                            $first = reset($validScores);
                            $last = end($validScores);
                            $delta = $last - $first;
                            $avg = array_sum($validScores) / count($validScores);
                            $avgText = number_format($avg, 1);

                            if ($delta > 0) {
                                $trendBadge = '<span class="print-bar-fill" style="padding: 2px 6px; border-radius: 4px; font-size: 8.5px; font-weight: 800; background-color: #d1fae5 !important; color: #065f46 !important; border: 1px solid #6ee7b7 !important; display: inline-flex; align-items: center; gap: 2px;"><i class="fa-solid fa-arrow-trend-up"></i> Naik (+' . number_format($delta, 1) . ')</span>';
                            } elseif ($delta < 0) {
                                $trendBadge = '<span class="print-bar-fill" style="padding: 2px 6px; border-radius: 4px; font-size: 8.5px; font-weight: 800; background-color: #ffe4e6 !important; color: #9f1239 !important; border: 1px solid #fca5a5 !important; display: inline-flex; align-items: center; gap: 2px;"><i class="fa-solid fa-arrow-trend-down"></i> Turun (' . number_format($delta, 1) . ')</span>';
                            } else {
                                $trendBadge = '<span class="print-bar-fill" style="padding: 2px 6px; border-radius: 4px; font-size: 8.5px; font-weight: 800; background-color: #dbeafe !important; color: #1e40af !important; border: 1px solid #93c5fd !important; display: inline-flex; align-items: center; gap: 2px;"><i class="fa-solid fa-minus"></i> Stabil</span>';
                            }
                        }
                    ?>
                        <div class="print-aspect-card" style="padding: 8px; background: <?= $aspect['bg'] ?>; border: 1px solid <?= $aspect['border'] ?>; border-radius: 10px; display: flex; flex-direction: column; justify-content: space-between; gap: 6px;">
                            <!-- Header Diagram -->
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(203,213,225,0.7); padding-bottom: 4px;">
                                <span style="font-size: 10px; font-weight: 900; color: #1e293b;"><?= $aspect['label'] ?></span>
                                <?= $trendBadge ?>
                            </div>

                            <!-- Chart Body Container -->
                            <div class="print-bar-container" style="background: rgba(255,255,255,0.95); padding: 6px; border-radius: 8px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 4px;">
                                <!-- Angka Nilai -->
                                <div style="display: grid; grid-template-columns: repeat(4, 1fr); text-align: center; font-size: 8.5px; font-weight: 900; color: #334155;">
                                    <?php foreach ($scores as $s): ?>
                                        <span><?= $s !== null ? number_format($s, 1) : '-' ?></span>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Tiang Bar Chart -->
                                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 3px; align-items: flex-end; height: 50px; padding: 2px 0;">
                                    <?php foreach ($scores as $s): 
                                        $scaleMax = ($aspect['key'] === 'score_adab' || ($avgText !== '-' && (float)$avgText > 10)) ? 100 : 10;
                                        $heightPct = $s !== null ? min(100, max(15, round(($s / $scaleMax) * 100))) : 0;
                                    ?>
                                        <div style="width: 100%; height: 100%; background-color: #f1f5f9 !important; border-radius: 3px 3px 0 0; display: flex; align-items: flex-end; overflow: hidden;">
                                            <div class="print-bar-fill" style="width: 100%; height: <?= $heightPct ?>%; background-color: <?= $aspect['hex'] ?> !important; border-radius: 3px 3px 0 0;"></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Label P1-P4 -->
                                <div style="display: grid; grid-template-columns: repeat(4, 1fr); text-align: center; font-size: 8.5px; font-weight: 800; color: #64748b; border-top: 1px solid #f1f5f9; padding-top: 2px;">
                                    <span>P1</span>
                                    <span>P2</span>
                                    <span>P3</span>
                                    <span>P4</span>
                                </div>
                            </div>

                            <!-- Footer Diagram -->
                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 9px; color: #475569;">
                                <span>Rata-rata: <strong style="color: #0f172a; font-weight: 900;"><?= $avgText ?></strong></span>
                                <span style="color: #94a3b8; font-size: 8px;"><?= ($aspect['key'] === 'score_adab' || ($avgText !== '-' && (float)$avgText > 10)) ? 'Skala 100' : 'Skala 10' ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 5. C. HASIL ISIAN REFLEKSI ADAB & KARAKTER MURID (2x2 Grid) -->
            <div style="margin-bottom: 14px;">
                <h4 style="margin: 0 0 6px; font-size: 11px; font-weight: 900; color: #0f172a; text-transform: uppercase;">
                    C. HASIL ISIAN REFLEKSI ADAB & KARAKTER MURID
                </h4>

                <div class="reflection-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 9.5px;">
                    <div style="background: #f8fafc; padding: 7px 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <strong style="display: block; color: #1e293b; font-weight: 800; margin-bottom: 2px;">1. Aksi Nyata Adab:</strong>
                        <p style="margin: 0; color: #475569; font-style: italic; line-height: 1.35;"><?= nl2br(e($adabRefleksi['refleksi1'])) ?></p>
                    </div>

                    <div style="background: #f8fafc; padding: 7px 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <strong style="display: block; color: #1e293b; font-weight: 800; margin-bottom: 2px;">2. Hambatan & Tantangan:</strong>
                        <p style="margin: 0; color: #475569; font-style: italic; line-height: 1.35;"><?= nl2br(e($adabRefleksi['refleksi2'])) ?></p>
                    </div>

                    <div style="background: #f8fafc; padding: 7px 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <strong style="display: block; color: #1e293b; font-weight: 800; margin-bottom: 2px;">3. Solusi & Rencana Perbaikan:</strong>
                        <p style="margin: 0; color: #475569; font-style: italic; line-height: 1.35;"><?= nl2br(e($adabRefleksi['refleksi3'])) ?></p>
                    </div>

                    <div style="background: #f8fafc; padding: 7px 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <strong style="display: block; color: #1e293b; font-weight: 800; margin-bottom: 2px;">4. Catatan Syukur & Refleksi Harian:</strong>
                        <p style="margin: 0; color: #475569; font-style: italic; line-height: 1.35;"><?= nl2br(e($adabRefleksi['refleksi4'])) ?></p>
                    </div>
                </div>
            </div>

            <!-- 6. SIGNATURE BLOCK RESMI (Orang Tua / Wali & Guru Mentor) -->
            <div class="print-break-inside-avoid signature-container" style="display: flex; justify-content: space-between; align-items: flex-end; padding: 12px 24px 0; text-align: center; font-size: 10px; border-top: 1px solid #e2e8f0; margin-top: 8px;">
                <div class="signature-box" style="width: 200px;">
                    <p style="margin: 0 0 45px; font-weight: 600; color: #334155;">Orang Tua / Wali Murid,</p>
                    <p style="margin: 0; font-weight: 800; color: #0f172a; text-decoration: underline;">( _______________________ )</p>
                </div>

                <div class="signature-box" style="width: 200px;">
                    <p style="margin: 0 0 2px; color: #64748b; font-size: 9px;">Bogor, <?= date('d F Y') ?></p>
                    <p style="margin: 0 0 45px; font-weight: 600; color: #334155;">Mentor,</p>
                    <p style="margin: 0; font-weight: 800; color: #0f172a; text-decoration: underline;">( <?= e($selectedStudent['mentor_name'] ?? 'Ust. Mentor') ?> )</p>
                </div>
            </div>

        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
