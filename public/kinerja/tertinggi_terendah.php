<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('kinerja_tertinggi_terendah');

$pageTitle = 'Mata Pelajaran Tertinggi dan Terendah — Penilaian Kinerja';
$currentUserId = currentUserId();
$currentRole = currentRole();

// 1. Parameter Filter
$unitId = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 2; // Default Unit 2: SD
$selectedYearName = isset($_GET['tahun']) ? trim($_GET['tahun']) : null;
$selectedSemester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

// Direct tahun_ajaran_id override jika ada
$tahunAjaranId = isset($_GET['tahun_ajaran_id']) ? (int)$_GET['tahun_ajaran_id'] : null;

// 2. Fetch data awal untuk mendapatkan metadata unit & tahun ajaran
$perfResponse = fetchAcademicPerformanceAverages($unitId, $tahunAjaranId, $forceRefresh);

$isSuccess = !empty($perfResponse['success']);
$errorMessage = $perfResponse['error'] ?? null;
$meta = $perfResponse['meta'] ?? [];
$paralelData = $perfResponse['data'] ?? [];
$isFromCache = !empty($perfResponse['from_cache']);
$warningMessage = $perfResponse['warning'] ?? null;

$availableUnits = $meta['units'] ?? [];
$availableYears = $meta['academic_years'] ?? [];
$selectedUnit = $meta['selected_unit'] ?? null;
$selectedYear = $meta['selected_tahun_ajaran'] ?? null;

// 3. Ekstrak Daftar Unik Tahun & Semester untuk Form Filter
$uniqueYearNames = [];
$availableSemesters = [1 => 'Semester 1 (Ganjil)', 2 => 'Semester 2 (Genap)'];

foreach ($availableYears as $ay) {
    $yName = trim($ay['nama'] ?? '');
    if ($yName && !in_array($yName, $uniqueYearNames)) {
        $uniqueYearNames[] = $yName;
    }
}

// Tentukan Tahun & Semester aktif saat ini
if (!$selectedYearName && $selectedYear) {
    $selectedYearName = trim($selectedYear['nama'] ?? '');
}
if (!$selectedSemester && $selectedYear) {
    $selectedSemester = (int)($selectedYear['semester'] ?? 1);
}

// Jika user memilih kombinasi Tahun dan Semester, cocokkan kembali ID tahun ajaran yang tepat
if ($selectedYearName && $selectedSemester) {
    $matchedAy = null;
    foreach ($availableYears as $ay) {
        if (trim($ay['nama']) === $selectedYearName && (int)$ay['semester'] === (int)$selectedSemester) {
            $matchedAy = $ay;
            break;
        }
    }
    if ($matchedAy && (!isset($selectedYear['id']) || (int)$matchedAy['id'] !== (int)$selectedYear['id'])) {
        // Fetch ulang jika ID tahun ajaran berbeda dari fetch awal
        $tahunAjaranId = (int)$matchedAy['id'];
        $perfResponse = fetchAcademicPerformanceAverages($unitId, $tahunAjaranId, false);
        $isSuccess = !empty($perfResponse['success']);
        $meta = $perfResponse['meta'] ?? [];
        $paralelData = $perfResponse['data'] ?? [];
        $selectedYear = $meta['selected_tahun_ajaran'] ?? $matchedAy;
    }
}

// 4. ALGORITMA PERHITUNGAN STATISTIK TERBOBOT (WEIGHTED AVERAGE) KESELURUHAN PARALEL
$subjectStats = [];
$totalAllEntries = 0;
$totalAllStudents = 0;

if (!empty($paralelData) && is_array($paralelData)) {
    foreach ($paralelData as $paralel) {
        $pId = (int)($paralel['paralel_id'] ?? 0);
        $pNama = (string)($paralel['paralel_nama'] ?? 'Paralel');
        $totalAllStudents += (int)($paralel['total_siswa'] ?? 0);

        foreach ($paralel['mapel_rankings'] ?? [] as $m) {
            $mId = (int)($m['mapel_id'] ?? 0);
            $mNama = (string)($m['mapel_nama'] ?? 'Mata Pelajaran');
            $mAvg = (float)($m['rata_rata'] ?? 0);
            $mEntri = (int)($m['total_entri'] ?? 0);
            $rankInParalel = (int)($m['rank'] ?? 0);

            if (!isset($subjectStats[$mId])) {
                $subjectStats[$mId] = [
                    'id' => $mId,
                    'nama' => $mNama,
                    'total_weighted_score' => 0,
                    'total_entri' => 0,
                    'paralels' => []
                ];
            }
            $subjectStats[$mId]['total_weighted_score'] += ($mAvg * $mEntri);
            $subjectStats[$mId]['total_entri'] += $mEntri;
            $subjectStats[$mId]['paralels'][] = [
                'paralel_id' => $pId,
                'paralel_nama' => $pNama,
                'rata_rata' => $mAvg,
                'total_entri' => $mEntri,
                'rank' => $rankInParalel
            ];
            $totalAllEntries += $mEntri;
        }
    }
}

// Hitung Nilai Rata-Rata Terbobot per Mapel & Urutkan Paralel
foreach ($subjectStats as $mId => &$stat) {
    $stat['weighted_avg'] = $stat['total_entri'] > 0 
        ? round($stat['total_weighted_score'] / $stat['total_entri'], 2) 
        : 0;

    // Urutkan capaian paralel dari tertinggi ke terendah untuk analisis highlight
    usort($stat['paralels'], function ($a, $b) {
        if ($a['rata_rata'] == $b['rata_rata']) return 0;
        return ($a['rata_rata'] > $b['rata_rata']) ? -1 : 1;
    });
}
unset($stat);

// Urutkan Mapel untuk Top 3 Tertinggi (DESC)
$topSubjectsList = $subjectStats;
uasort($topSubjectsList, function ($a, $b) {
    if ($a['weighted_avg'] == $b['weighted_avg']) return 0;
    return ($a['weighted_avg'] > $b['weighted_avg']) ? -1 : 1;
});
$top3Global = array_slice($topSubjectsList, 0, 3);

// Urutkan Mapel untuk Bottom 3 Terendah (ASC)
$bottomSubjectsList = $subjectStats;
uasort($bottomSubjectsList, function ($a, $b) {
    if ($a['weighted_avg'] == $b['weighted_avg']) return 0;
    return ($a['weighted_avg'] < $b['weighted_avg']) ? -1 : 1;
});
$bottom3Global = array_slice($bottomSubjectsList, 0, 3);

// KPI Overview
$highestSubject = !empty($top3Global) ? reset($top3Global) : null;
$lowestSubject = !empty($bottom3Global) ? reset($bottom3Global) : null;
$scoreGap = ($highestSubject && $lowestSubject) ? round($highestSubject['weighted_avg'] - $lowestSubject['weighted_avg'], 2) : 0;
$totalParalelsCount = count($paralelData);

// 5. Analisis Per Paralel (Top 3 vs Bottom 3 per Kelas)
$paralelAnalysis = [];
if (!empty($paralelData) && is_array($paralelData)) {
    foreach ($paralelData as $p) {
        $pId = $p['paralel_id'];
        $pNama = $p['paralel_nama'];
        $rankings = $p['mapel_rankings'] ?? [];

        // Pastikan terurut DESC
        usort($rankings, function ($a, $b) {
            $va = (float)($a['rata_rata'] ?? 0);
            $vb = (float)($b['rata_rata'] ?? 0);
            if ($va == $vb) return 0;
            return ($va > $vb) ? -1 : 1;
        });

        $top3InP = array_slice($rankings, 0, 3);
        $bot3InP = array_slice($rankings, -3);
        // Balik bot3 agar yang paling rendah tampil paling atas atau urut
        usort($bot3InP, function ($a, $b) {
            $va = (float)($a['rata_rata'] ?? 0);
            $vb = (float)($b['rata_rata'] ?? 0);
            if ($va == $vb) return 0;
            return ($va < $vb) ? -1 : 1; // Terendah ke agak tinggi
        });

        $topScore = !empty($top3InP) ? (float)$top3InP[0]['rata_rata'] : 0;
        $botScore = !empty($bot3InP) ? (float)$bot3InP[0]['rata_rata'] : 0;
        $gapInP = round($topScore - $botScore, 2);

        $paralelAnalysis[] = [
            'id' => $pId,
            'nama' => $pNama,
            'rata_rata_paralel' => (float)($p['rata_rata_paralel'] ?? 0),
            'total_siswa' => (int)($p['total_siswa'] ?? 0),
            'top3' => $top3InP,
            'bottom3' => $bot3InP,
            'gap' => $gapInP
        ];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ========================================================================= -->
<!-- STYLESHEET KHUSUS HALAMAN NILAI TERTINGGI & TERENDAH (DUAL-SHOWCASE UI) -->
<!-- ========================================================================= -->
<style>
.kinerja-wrapper {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #1e293b;
    padding: 0;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    margin: 0;
}

/* TOP HEADER CARD */
.kinerja-header-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    padding: 24px 28px;
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 24px;
    width: 100%;
    box-sizing: border-box;
}

.kinerja-header-info h1 {
    margin: 6px 0 0;
    font-size: 26px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.5px;
}
.kinerja-header-info p {
    margin: 6px 0 0;
    font-size: 14px;
    color: #64748b;
    line-height: 1.5;
}

.kinerja-badge-category {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #eef2ff;
    color: #4338ca;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid #c7d2fe;
}
.kinerja-badge-cache {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #f1f5f9;
    color: #475569;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 6px;
}

.header-action-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-kinerja {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 16px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 10px;
    text-decoration: none;
    transition: all 0.2s ease;
    cursor: pointer;
    border: none;
}
.btn-kinerja-primary {
    background: #4f46e5;
    color: #ffffff;
}
.btn-kinerja-primary:hover {
    background: #4338ca;
    color: #ffffff;
}
.btn-kinerja-outline {
    background: #ffffff;
    color: #334155;
    border: 1px solid #cbd5e1;
}
.btn-kinerja-outline:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #0f172a;
}

/* FILTER TOOLBAR */
.kinerja-filter-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 18px 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
    width: 100%;
    box-sizing: border-box;
}
.kinerja-filter-form {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    width: 100%;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.filter-group label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.filter-control {
    height: 40px;
    padding: 0 14px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    min-width: 170px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.filter-control:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

/* 4 KPI OVERVIEW CARDS */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 28px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1200px) {
    .kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .kpi-grid { grid-template-columns: 1fr; }
}
.kpi-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    position: relative;
    overflow: hidden;
    width: 100%;
    box-sizing: border-box;
}
.kpi-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    bottom: 0;
    width: 4px;
}
.kpi-card.emerald::before { background: #10b981; }
.kpi-card.rose::before { background: #f43f5e; }
.kpi-card.blue::before { background: #3b82f6; }
.kpi-card.indigo::before { background: #6366f1; }

.kpi-icon-box {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.kpi-card.emerald .kpi-icon-box { background: #ecfdf5; color: #059669; }
.kpi-card.rose .kpi-icon-box { background: #fff1f2; color: #e11d48; }
.kpi-card.blue .kpi-icon-box { background: #eff6ff; color: #2563eb; }
.kpi-card.indigo .kpi-icon-box { background: #eef2ff; color: #4f46e5; }

.kpi-meta { flex: 1; min-width: 0; }
.kpi-label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.kpi-value {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}
.kpi-subtext {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* SECTION 1: DUAL-COLUMN EXECUTIVE SHOWCASE */
.executive-showcase {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 36px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1050px) {
    .executive-showcase { grid-template-columns: 1fr; }
}

.showcase-column {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
    width: 100%;
    box-sizing: border-box;
}
.showcase-header {
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e2e8f0;
}
.showcase-header.highest {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-bottom-color: #bbf7d0;
}
.showcase-header.lowest {
    background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%);
    border-bottom-color: #fecdd3;
}
.showcase-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}
.showcase-title-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.showcase-header.highest .showcase-title-icon { background: #15803d; color: #ffffff; }
.showcase-header.lowest .showcase-title-icon { background: #be123c; color: #ffffff; }

.showcase-title-wrap h2 {
    margin: 0;
    font-size: 17px;
    font-weight: 800;
}
.showcase-header.highest h2 { color: #14532d; }
.showcase-header.lowest h2 { color: #881337; }

.showcase-title-wrap p {
    margin: 3px 0 0;
    font-size: 12px;
    color: #475569;
}

.showcase-body {
    padding: 20px 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* SUBJECT CARD */
.subject-card {
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 16px 20px;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.subject-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}
.subject-card.highest {
    border-left: 4px solid #10b981;
    background: #ffffff;
}
.subject-card.lowest {
    border-left: 4px solid #f43f5e;
    background: #ffffff;
}

.subject-top-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    gap: 12px;
}
.subject-rank-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 800;
    padding: 4px 10px;
    border-radius: 20px;
}
.subject-rank-badge.rank-1 { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.subject-rank-badge.rank-2 { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
.subject-rank-badge.rank-3 { background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }

.subject-rank-badge.priority-1 { background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; }
.subject-rank-badge.priority-2 { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
.subject-rank-badge.priority-3 { background: #fef9c3; color: #854d0e; border: 1px solid #fef08a; }

.subject-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    flex: 1;
}
.subject-score-badge {
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.5px;
}
.subject-score-badge.highest { color: #059669; }
.subject-score-badge.lowest { color: #e11d48; }

/* PROGRESS BAR */
.progress-container {
    height: 8px;
    background: #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 12px;
}
.progress-fill {
    height: 100%;
    border-radius: 6px;
    transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}
.progress-fill.highest {
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
}
.progress-fill.lowest {
    background: linear-gradient(90deg, #f59e0b 0%, #f43f5e 100%);
}

/* PARALEL HIGHLIGHT PILLS */
.paralel-pills-wrap {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.paralel-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 6px;
}
.paralel-pill.strong {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}
.paralel-pill.weak {
    background: #fff1f2;
    color: #be123c;
    border: 1px solid #fecdd3;
}
.paralel-pill.neutral {
    background: #f1f5f9;
    color: #475569;
}

.narrative-box {
    margin-top: 10px;
    font-size: 12px;
    line-height: 1.5;
    color: #475569;
    padding: 8px 12px;
    border-radius: 8px;
    background: #f8fafc;
    border-left: 3px solid #cbd5e1;
}

/* SECTION 2: GRID PER PARALEL (SIDE-BY-SIDE) */
.section-title-wrap {
    margin: 32px 0 18px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 12px;
}
.section-title-wrap h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 800;
    color: #0f172a;
}
.section-title-wrap p {
    margin: 4px 0 0;
    font-size: 13px;
    color: #64748b;
}

.paralel-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 36px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1100px) {
    .paralel-grid { grid-template-columns: 1fr; }
}

.paralel-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 20px;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
}
.paralel-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 14px;
    border-bottom: 1px solid #f1f5f9;
    margin-bottom: 16px;
}
.paralel-name-badge {
    display: flex;
    align-items: center;
    gap: 10px;
}
.paralel-avatar {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #eef2ff;
    color: #4f46e5;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 14px;
}
.paralel-title {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
}
.paralel-subtitle {
    font-size: 12px;
    color: #64748b;
}
.gap-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 20px;
    background: #f8fafc;
    color: #334155;
    border: 1px solid #e2e8f0;
}

/* PARALEL DUAL SUB-COLUMNS */
.paralel-cols {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
@media (max-width: 480px) {
    .paralel-cols { grid-template-columns: 1fr; }
}

.subcol-box {
    border-radius: 10px;
    padding: 12px 14px;
}
.subcol-box.top {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
}
.subcol-box.bot {
    background: #fff1f2;
    border: 1px solid #fecdd3;
}

.subcol-header {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.subcol-box.top .subcol-header { color: #166534; }
.subcol-box.bot .subcol-header { color: #9f1239; }

.subcol-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px dashed rgba(0, 0, 0, 0.06);
    font-size: 12px;
}
.subcol-item:last-child { border-bottom: none; }
.subcol-item-name {
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 115px;
}
.subcol-item-score {
    font-weight: 800;
}
.subcol-box.top .subcol-item-score { color: #047857; }
.subcol-box.bot .subcol-item-score { color: #be123c; }

/* MODAL TRANSFORMATION */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: #ffffff;
    border-radius: 16px;
    max-width: 800px;
    width: 100%;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
    border: 1px solid #e2e8f0;
}
.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h4 { margin: 0; font-size: 18px; font-weight: 800; color: #0f172a; }
.modal-body { padding: 24px; }
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
}
.formula-box {
    background: #0f172a;
    color: #38bdf8;
    padding: 16px;
    border-radius: 10px;
    font-family: monospace;
    font-size: 13px;
    margin-bottom: 20px;
}
.raw-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.raw-table th {
    background: #f8fafc;
    text-align: left;
    padding: 10px 12px;
    font-weight: 700;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
}
.raw-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #f1f5f9;
}
</style>

<div class="kinerja-wrapper">

    <!-- 1. TOP HEADER CARD -->
    <div class="kinerja-header-card">
        <div class="kinerja-header-info">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span class="kinerja-badge-category">
                    <i class="fa-solid fa-chart-pie"></i> MODUL PENILAIAN KINERJA
                </span>
                <?php if ($isFromCache): ?>
                    <span class="kinerja-badge-cache" title="Data disajikan dari cache lokal berkecepatan tinggi">
                        <i class="fa-solid fa-bolt" style="color:#eab308;"></i> Cache Aktif
                    </span>
                <?php else: ?>
                    <span class="kinerja-badge-cache" style="background:#ecfdf5; color:#047857;" title="Data baru diambil langsung dari server sekolah">
                        <i class="fa-solid fa-circle-check"></i> Live API
                    </span>
                <?php endif; ?>
            </div>
            <h1>Analisis Nilai Tertinggi & Terendah</h1>
            <p>
                Komparasi 3 mata pelajaran dengan nilai rata-rata tertinggi dan terendah secara umum serta perbandingan per paralel kelas.
            </p>
        </div>

        <div class="header-action-group">
            <a href="<?= BASE_URL ?>/kinerja/index.php?unit_id=<?= $unitId ?>&tahun_ajaran_id=<?= $tahunAjaranId ?? '' ?>" class="btn-kinerja btn-kinerja-outline" title="Lihat Peringkat Nilai Rata-Rata Seluruh Kelas">
                <i class="fa-solid fa-ranking-star"></i> Nilai Rata-Rata Paralel
            </a>
            <a href="?unit_id=<?= $unitId ?>&tahun=<?= urlencode($selectedYearName) ?>&semester=<?= $selectedSemester ?>&refresh=1" class="btn-kinerja btn-kinerja-primary" title="Paksa tarik data terbaru dari database sekolah">
                <i class="fa-solid fa-arrows-rotate"></i> Segarkan Data
            </a>
            <button type="button" class="btn-kinerja btn-kinerja-outline" onclick="openFormulaModal()">
                <i class="fa-solid fa-calculator"></i> Rumus Statistik
            </button>
        </div>
    </div>

    <!-- 2. FILTER TOOLBAR (UNIT, TAHUN AJARAN & SEMESTER) -->
    <div class="kinerja-filter-card">
        <form method="GET" action="" class="kinerja-filter-form">
            <!-- Filter Unit -->
            <div class="filter-group">
                <label for="filter_unit"><i class="fa-solid fa-school"></i> Unit Sekolah</label>
                <select name="unit_id" id="filter_unit" class="filter-control" onchange="this.form.submit()">
                    <?php if (!empty($availableUnits)): ?>
                        <?php foreach ($availableUnits as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ((int)$u['id'] === (int)$unitId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['nama']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="2" <?= ($unitId == 2) ? 'selected' : '' ?>>SD (Sekolah Dasar)</option>
                        <option value="3" <?= ($unitId == 3) ? 'selected' : '' ?>>SMP</option>
                        <option value="4" <?= ($unitId == 4) ? 'selected' : '' ?>>SMA</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Filter Tahun Ajaran -->
            <div class="filter-group">
                <label for="filter_tahun"><i class="fa-solid fa-calendar-days"></i> Tahun Ajaran</label>
                <select name="tahun" id="filter_tahun" class="filter-control" onchange="this.form.submit()">
                    <?php if (!empty($uniqueYearNames)): ?>
                        <?php foreach ($uniqueYearNames as $yName): ?>
                            <option value="<?= htmlspecialchars($yName) ?>" <?= ($selectedYearName === $yName) ? 'selected' : '' ?>>
                                Tahun <?= htmlspecialchars($yName) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="2024 - 2025" selected>Tahun 2024 - 2025</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Filter Semester -->
            <div class="filter-group">
                <label for="filter_semester"><i class="fa-solid fa-clock-rotate-left"></i> Semester</label>
                <select name="semester" id="filter_semester" class="filter-control" onchange="this.form.submit()">
                    <?php foreach ($availableSemesters as $semVal => $semLabel): ?>
                        <option value="<?= $semVal ?>" <?= ((int)$selectedSemester === (int)$semVal) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($semLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group" style="align-self: flex-end;">
                <button type="submit" class="btn-kinerja btn-kinerja-primary" style="height: 40px;">
                    <i class="fa-solid fa-filter"></i> Terapkan
                </button>
            </div>

            <?php if (!empty($selectedUnit) && !empty($selectedYear)): ?>
                <div style="margin-left: auto; font-size: 12px; color: #64748b; align-self: flex-end; padding-bottom: 6px;">
                    Menampilkan data: <strong style="color:#0f172a;"><?= htmlspecialchars($selectedUnit['nama'] ?? '') ?></strong> &bull;
                    <strong><?= htmlspecialchars($selectedYear['nama'] ?? '') ?></strong> (Semester <?= (int)($selectedYear['semester'] ?? 1) ?>)
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!empty($warningMessage)): ?>
        <div style="background:#fffbeb; border:1px solid #fef3c7; color:#92400e; padding:14px 18px; border-radius:12px; margin-bottom:24px; font-size:13px; display:flex; align-items:center; gap:10px;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:18px;"></i>
            <div><?= htmlspecialchars($warningMessage) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!$isSuccess && empty($paralelData)): ?>
        <!-- ERROR STATE KETIKA TIDAK ADA DATA ATAU JARINGAN OFFLINE -->
        <div style="background:#ffffff; border-radius:16px; border:1px solid #fecdd3; padding:48px 24px; text-align:center; box-shadow:0 4px 12px rgba(0,0,0,0.03);">
            <div style="width:64px; height:64px; border-radius:50%; background:#fff1f2; color:#e11d48; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:28px;">
                <i class="fa-solid fa-cloud-arrow-down"></i>
            </div>
            <h3 style="margin:0 0 8px; font-weight:800; color:#0f172a;">Data Performa Belum Tersedia</h3>
            <p style="margin:0 auto 20px; max-width:500px; color:#64748b; font-size:14px;">
                <?= htmlspecialchars($errorMessage ?: 'Tidak dapat terhubung ke database AIS Source di laptop sekolah atau belum ada data nilai ujian semester untuk filter yang dipilih.') ?>
            </p>
            <a href="?unit_id=<?= $unitId ?>&tahun=<?= urlencode($selectedYearName) ?>&semester=<?= $selectedSemester ?>&refresh=1" class="btn-kinerja btn-kinerja-primary">
                <i class="fa-solid fa-rotate"></i> Coba Hubungkan Kembali
            </a>
        </div>
    <?php else: ?>

        <!-- 3. TOP KPI OVERVIEW CARDS -->
        <div class="kpi-grid">
            <!-- KPI 1: Mapel Tertinggi -->
            <div class="kpi-card emerald">
                <div class="kpi-icon-box">
                    <i class="fa-solid fa-trophy"></i>
                </div>
                <div class="kpi-meta">
                    <div class="kpi-label">Mapel Tertinggi Umum</div>
                    <div class="kpi-value"><?= $highestSubject ? number_format($highestSubject['weighted_avg'], 2, ',', '.') : '0' ?></div>
                    <div class="kpi-subtext" title="<?= htmlspecialchars($highestSubject['nama'] ?? '-') ?>">
                        <?= htmlspecialchars($highestSubject['nama'] ?? 'Belum ada data') ?>
                    </div>
                </div>
            </div>

            <!-- KPI 2: Mapel Terendah -->
            <div class="kpi-card rose">
                <div class="kpi-icon-box">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div class="kpi-meta">
                    <div class="kpi-label">Fokus Peningkatan Mutu</div>
                    <div class="kpi-value"><?= $lowestSubject ? number_format($lowestSubject['weighted_avg'], 2, ',', '.') : '0' ?></div>
                    <div class="kpi-subtext" title="<?= htmlspecialchars($lowestSubject['nama'] ?? '-') ?>">
                        <?= htmlspecialchars($lowestSubject['nama'] ?? 'Belum ada data') ?>
                    </div>
                </div>
            </div>

            <!-- KPI 3: Kesenjangan Nilai (Score Gap) -->
            <div class="kpi-card blue">
                <div class="kpi-icon-box">
                    <i class="fa-solid fa-arrows-left-right-to-line"></i>
                </div>
                <div class="kpi-meta">
                    <div class="kpi-label">Rentang Kesenjangan (Gap)</div>
                    <div class="kpi-value"><?= number_format($scoreGap, 2, ',', '.') ?> poin</div>
                    <div class="kpi-subtext">Selisih mapel tertinggi vs terendah</div>
                </div>
            </div>

            <!-- KPI 4: Cakupan Data -->
            <div class="kpi-card indigo">
                <div class="kpi-icon-box">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="kpi-meta">
                    <div class="kpi-label">Cakupan Penilaian</div>
                    <div class="kpi-value"><?= number_format($totalAllStudents, 0, ',', '.') ?> Siswa</div>
                    <div class="kpi-subtext"><?= $totalParalelsCount ?> Paralel &bull; <?= number_format($totalAllEntries, 0, ',', '.') ?> Entri Nilai</div>
                </div>
            </div>
        </div>

        <!-- 4. SECTION 1: EXECUTIVE DUAL-COLUMN SHOWCASE -->
        <div class="executive-showcase">

            <!-- KOLOM KIRI: 3 MATA PELAJARAN TERTINGGI SECARA UMUM -->
            <div class="showcase-column">
                <div class="showcase-header highest">
                    <div class="showcase-title-wrap">
                        <div class="showcase-title-icon">
                            <i class="fa-solid fa-arrow-trend-up"></i>
                        </div>
                        <div>
                            <h2>3 Mata Pelajaran Tertinggi</h2>
                            <p>Capaian rata-rata terunggul dari akumulasi seluruh paralel kelas</p>
                        </div>
                    </div>
                    <span class="subject-rank-badge rank-1" style="font-size:11px;">PRESTASI UTAMA</span>
                </div>

                <div class="showcase-body">
                    <?php if (!empty($top3Global)): ?>
                        <?php 
                        $medals = ['🥇 Juara 1', '🥈 Juara 2', '🥉 Juara 3'];
                        $rankClasses = ['rank-1', 'rank-2', 'rank-3'];
                        $i = 0;
                        ?>
                        <?php foreach ($top3Global as $mapel): ?>
                            <?php 
                            $medal = $medals[$i] ?? ("#" . ($i + 1));
                            $rankCls = $rankClasses[$i] ?? 'rank-2';
                            $percentage = min(100, max(0, (float)$mapel['weighted_avg']));
                            $bestParalels = array_slice($mapel['paralels'], 0, 3);
                            $i++;
                            ?>
                            <div class="subject-card highest">
                                <div class="subject-top-row">
                                    <span class="subject-rank-badge <?= $rankCls ?>"><?= $medal ?></span>
                                    <span class="subject-title" title="<?= htmlspecialchars($mapel['nama']) ?>">
                                        <?= htmlspecialchars($mapel['nama']) ?>
                                    </span>
                                    <span class="subject-score-badge highest">
                                        <?= number_format($mapel['weighted_avg'], 2, ',', '.') ?>
                                    </span>
                                </div>

                                <div class="progress-container">
                                    <div class="progress-fill highest" style="width: <?= $percentage ?>%;"></div>
                                </div>

                                <div class="paralel-pills-wrap">
                                    <span style="font-size:11px; font-weight:700; color:#047857;">Unggulan:</span>
                                    <?php foreach ($bestParalels as $bp): ?>
                                        <span class="paralel-pill strong" title="Peringkat <?= $bp['rank'] ?> di <?= htmlspecialchars($bp['paralel_nama']) ?>">
                                            <?= htmlspecialchars($bp['paralel_nama']) ?>: <strong><?= number_format($bp['rata_rata'], 2, ',', '.') ?></strong>
                                        </span>
                                    <?php endforeach; ?>
                                </div>

                                <div class="narrative-box">
                                    <i class="fa-solid fa-circle-check" style="color:#10b981;"></i>
                                    Menunjukkan performa konsisten tinggi lintas jenjang, khususnya di <?= htmlspecialchars($bestParalels[0]['paralel_nama'] ?? 'paralel') ?> dengan rata-rata <?= number_format($bestParalels[0]['rata_rata'] ?? 0, 2, ',', '.') ?>.
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:20px; text-align:center; color:#94a3b8;">Belum ada data mata pelajaran tertinggi.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KOLOM KANAN: 3 MATA PELAJARAN TERENDAH SECARA UMUM -->
            <div class="showcase-column">
                <div class="showcase-header lowest">
                    <div class="showcase-title-wrap">
                        <div class="showcase-title-icon">
                            <i class="fa-solid fa-arrow-trend-down"></i>
                        </div>
                        <div>
                            <h2>3 Mata Pelajaran Terendah</h2>
                            <p>Area prioritas evaluasi dan program penguatan akademik guru</p>
                        </div>
                    </div>
                    <span class="subject-rank-badge priority-1" style="font-size:11px;">FOKUS PERBAIKAN</span>
                </div>

                <div class="showcase-body">
                    <?php if (!empty($bottom3Global)): ?>
                        <?php 
                        $priorities = ['⚠️ Prioritas 1', '⚠️ Prioritas 2', '⚠️ Prioritas 3'];
                        $prioClasses = ['priority-1', 'priority-2', 'priority-3'];
                        $j = 0;
                        ?>
                        <?php foreach ($bottom3Global as $mapel): ?>
                            <?php 
                            $prio = $priorities[$j] ?? ("Prioritas #" . ($j + 1));
                            $prioCls = $prioClasses[$j] ?? 'priority-2';
                            $percentage = min(100, max(0, (float)$mapel['weighted_avg']));
                            // Urutkan dari yang paling rendah untuk highlight area kritis
                            $sortedWorst = $mapel['paralels'];
                            usort($sortedWorst, function ($a, $b) {
                                if ($a['rata_rata'] == $b['rata_rata']) return 0;
                                return ($a['rata_rata'] < $b['rata_rata']) ? -1 : 1;
                            });
                            $criticalParalels = array_slice($sortedWorst, 0, 3);
                            $j++;
                            ?>
                            <div class="subject-card lowest">
                                <div class="subject-top-row">
                                    <span class="subject-rank-badge <?= $prioCls ?>"><?= $prio ?></span>
                                    <span class="subject-title" title="<?= htmlspecialchars($mapel['nama']) ?>">
                                        <?= htmlspecialchars($mapel['nama']) ?>
                                    </span>
                                    <span class="subject-score-badge lowest">
                                        <?= number_format($mapel['weighted_avg'], 2, ',', '.') ?>
                                    </span>
                                </div>

                                <div class="progress-container">
                                    <div class="progress-fill lowest" style="width: <?= $percentage ?>%;"></div>
                                </div>

                                <div class="paralel-pills-wrap">
                                    <span style="font-size:11px; font-weight:700; color:#be123c;">Paling Kritis:</span>
                                    <?php foreach ($criticalParalels as $cp): ?>
                                        <span class="paralel-pill weak" title="Rata-rata <?= number_format($cp['rata_rata'], 2, ',', '.') ?> di <?= htmlspecialchars($cp['paralel_nama']) ?>">
                                            <?= htmlspecialchars($cp['paralel_nama']) ?>: <strong><?= number_format($cp['rata_rata'], 2, ',', '.') ?></strong>
                                        </span>
                                    <?php endforeach; ?>
                                </div>

                                <div class="narrative-box" style="border-left-color:#f43f5e; background:#fff1f2;">
                                    <i class="fa-solid fa-circle-exclamation" style="color:#f43f5e;"></i>
                                    Perlu atensi pedagogik dan pengayaan remedial, terutama di <?= htmlspecialchars($criticalParalels[0]['paralel_nama'] ?? 'paralel') ?> (capaian <?= number_format($criticalParalels[0]['rata_rata'] ?? 0, 2, ',', '.') ?>).
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:20px; text-align:center; color:#94a3b8;">Belum ada data mata pelajaran terendah.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- 5. SECTION 2: ANALISIS KOMPARASI DUA KOLOM PER PARALEL (KELAS 1 S/D 6) -->
        <div class="section-title-wrap">
            <div>
                <h3><i class="fa-solid fa-layer-group" style="color:#4f46e5;"></i> Analisis Komparasi Tiap Paralel Kelas</h3>
                <p>Membandingkan 3 mata pelajaran tertinggi dan 3 mata pelajaran terendah pada masing-masing jenjang paralel.</p>
            </div>
            <div style="font-size:12px; color:#64748b;">
                Menampilkan <strong><?= count($paralelAnalysis) ?></strong> Paralel Kelas
            </div>
        </div>

        <div class="paralel-grid">
            <?php foreach ($paralelAnalysis as $pa): ?>
                <div class="paralel-card">
                    <div class="paralel-card-header">
                        <div class="paralel-name-badge">
                            <div class="paralel-avatar">
                                <?= htmlspecialchars(substr($pa['nama'], 0, 2)) ?>
                            </div>
                            <div>
                                <div class="paralel-title"><?= htmlspecialchars($pa['nama']) ?></div>
                                <div class="paralel-subtitle">
                                    Rata-Rata: <strong><?= number_format($pa['rata_rata_paralel'], 2, ',', '.') ?></strong> &bull; <?= $pa['total_siswa'] ?> Siswa
                                </div>
                            </div>
                        </div>

                        <span class="gap-badge" title="Selisih nilai tertinggi dengan terendah di paralel ini">
                            <i class="fa-solid fa-arrows-split-up-and-left" style="color:#6366f1;"></i>
                            Gap: <strong><?= number_format($pa['gap'], 2, ',', '.') ?></strong>
                        </span>
                    </div>

                    <div class="paralel-cols">
                        <!-- SUB-KOLOM HIJAU (TOP 3) -->
                        <div class="subcol-box top">
                            <div class="subcol-header">
                                <i class="fa-solid fa-circle-arrow-up"></i> 3 Tertinggi
                            </div>
                            <?php if (!empty($pa['top3'])): ?>
                                <?php foreach ($pa['top3'] as $idx => $mTop): ?>
                                    <div class="subcol-item">
                                        <span class="subcol-item-name" title="<?= htmlspecialchars($mTop['mapel_nama']) ?>">
                                            <?= ($idx + 1) ?>. <?= htmlspecialchars($mTop['mapel_nama']) ?>
                                        </span>
                                        <span class="subcol-item-score">
                                            <?= number_format((float)$mTop['rata_rata'], 2, ',', '.') ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size:11px; color:#94a3b8;">Tidak ada data</div>
                            <?php endif; ?>
                        </div>

                        <!-- SUB-KOLOM MERAH (BOTTOM 3) -->
                        <div class="subcol-box bot">
                            <div class="subcol-header">
                                <i class="fa-solid fa-circle-arrow-down"></i> 3 Terendah
                            </div>
                            <?php if (!empty($pa['bottom3'])): ?>
                                <?php foreach ($pa['bottom3'] as $idx => $mBot): ?>
                                    <div class="subcol-item">
                                        <span class="subcol-item-name" title="<?= htmlspecialchars($mBot['mapel_nama']) ?>">
                                            <?= ($idx + 1) ?>. <?= htmlspecialchars($mBot['mapel_nama']) ?>
                                        </span>
                                        <span class="subcol-item-score">
                                            <?= number_format((float)$mBot['rata_rata'], 2, ',', '.') ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size:11px; color:#94a3b8;">Tidak ada data</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<!-- ========================================================================= -->
<!-- MODAL AUDIT & TRANSPARANSI FORMULA STATISTIK -->
<!-- ========================================================================= -->
<div id="formulaModal" class="modal-overlay" onclick="if(event.target===this) closeFormulaModal()">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="fa-solid fa-calculator" style="color:#4f46e5;"></i> Transparansi Perhitungan & Formula Statistik</h4>
            <button type="button" onclick="closeFormulaModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px; color:#475569; margin-top:0;">
                Sistem tidak menggunakan rata-rata sederhana melainkan <strong>Rata-Rata Terbobot (*Weighted Average*)</strong> agar proporsi jumlah siswa di tiap kelas tercermin secara presisi dan adil:
            </p>

            <div class="formula-box">
                // Formula Weighted Average Keseluruhan:<br>
                Rata_Rata_Mapel = ∑ ( Rata_Rata_Paralel[i] × Total_Entri[i] ) / ∑ ( Total_Entri[i] )
            </div>

            <h5 style="margin:20px 0 10px; font-size:14px; font-weight:800; color:#0f172a;">
                Tabel Rekapitulasi Seluruh Mata Pelajaran (Diurutkan Tertinggi ke Terendah)
            </h5>

            <div style="overflow-x:auto;">
                <table class="raw-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">Rank</th>
                            <th>Mata Pelajaran</th>
                            <th style="text-align:right;">Rata-Rata Terbobot</th>
                            <th style="text-align:right;">Total Entri Siswa</th>
                            <th style="text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rankCounter = 1;
                        $totalMapelCount = count($topSubjectsList);
                        ?>
                        <?php foreach ($topSubjectsList as $mItem): ?>
                            <?php 
                            $isTop3 = $rankCounter <= 3;
                            $isBot3 = $rankCounter > ($totalMapelCount - 3);
                            ?>
                            <tr>
                                <td style="font-weight:700; color:#64748b;"><?= $rankCounter ?></td>
                                <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($mItem['nama']) ?></td>
                                <td style="text-align:right; font-weight:800; color:<?= $isTop3 ? '#059669' : ($isBot3 ? '#e11d48' : '#0f172a') ?>;">
                                    <?= number_format($mItem['weighted_avg'], 2, ',', '.') ?>
                                </td>
                                <td style="text-align:right; color:#64748b;">
                                    <?= number_format($mItem['total_entri'], 0, ',', '.') ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($isTop3): ?>
                                        <span class="paralel-pill strong">Top 3 Tertinggi</span>
                                    <?php elseif ($isBot3): ?>
                                        <span class="paralel-pill weak">Bottom 3 Terendah</span>
                                    <?php else: ?>
                                        <span class="paralel-pill neutral">Menengah</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php $rankCounter++; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-kinerja btn-kinerja-outline" onclick="closeFormulaModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
function openFormulaModal() {
    document.getElementById('formulaModal').classList.add('active');
}
function closeFormulaModal() {
    document.getElementById('formulaModal').classList.remove('active');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

