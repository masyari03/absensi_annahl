<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('kinerja_nilai_paralel');

$pageTitle = 'Pencapaian Nilai Rata-Rata Per Paralel — Penilaian Kinerja';
$currentUserId = currentUserId();
$currentRole = currentRole();

// 1. Filter Parameter
$unitId = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 2; // Default: Unit 2 (SD)
$tahunAjaranId = isset($_GET['tahun_ajaran_id']) ? (int)$_GET['tahun_ajaran_id'] : null;
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

// 2. Ambil data dari API AIS Laptop Sekolah
$perfResponse = fetchAcademicPerformanceAverages($unitId, $tahunAjaranId, $forceRefresh);

$isSuccess = !empty($perfResponse['success']);
$errorMessage = $perfResponse['error'] ?? null;
$meta = $perfResponse['meta'] ?? [];
$paralelData = $perfResponse['data'] ?? [];
$isFromCache = !empty($perfResponse['from_cache']);
$warningMessage = $perfResponse['warning'] ?? null;

// Ekstrak meta
$availableUnits = $meta['units'] ?? [];
$availableYears = $meta['academic_years'] ?? [];
$selectedUnit = $meta['selected_unit'] ?? null;
$selectedYear = $meta['selected_tahun_ajaran'] ?? null;

// 3. PENGURUTAN PARALEL: DARI NILAI RATA-RATA TERTINGGI KE TERENDAH (DESC)
if (!empty($paralelData) && is_array($paralelData)) {
    usort($paralelData, function ($a, $b) {
        $avgA = (float)($a['rata_rata_paralel'] ?? 0);
        $avgB = (float)($b['rata_rata_paralel'] ?? 0);
        if ($avgA == $avgB) {
            return 0;
        }
        return ($avgA > $avgB) ? -1 : 1; // Urut Menurun (Highest to Lowest)
    });
}

// 4. Hitung KPI Ringkasan
$overallUnitAvg = 0;
$topParalelName = null;
$topParalelScore = 0;
$totalStudentsInUnit = 0;

if (!empty($paralelData)) {
    $sum = 0;
    foreach ($paralelData as $idx => $p) {
        $avg = (float)($p['rata_rata_paralel'] ?? 0);
        $sum += $avg;
        $totalStudentsInUnit += (int)($p['total_siswa'] ?? 0);
        if ($idx === 0) {
            $topParalelName = $p['paralel_nama'] ?? '';
            $topParalelScore = $avg;
        }
    }
    $overallUnitAvg = round($sum / count($paralelData), 2);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ========================================================================= -->
<!-- STYLESHEET KHUSUS DASHBOARD PENILAIAN KINERJA (PREMIUM SAAS LOOK) -->
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
    border: 1px solid #e2e8f0;
    margin-left: 8px;
}

/* FILTER CONTROLS */
.kinerja-filter-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.kinerja-select {
    appearance: none;
    background: #f8fafc url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%2364748b' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e") right 10px center/16px 16px no-repeat;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 9px 36px 9px 14px;
    font-size: 13.5px;
    font-weight: 600;
    color: #334155;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}
.kinerja-select:hover {
    border-color: #6366f1;
    background-color: #ffffff;
}
.kinerja-select:focus {
    outline: none;
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
}

.btn-sync-refresh {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: #ffffff !important;
    font-size: 13px;
    font-weight: 700;
    padding: 10px 18px;
    border-radius: 10px;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
    transition: all 0.2s ease;
    border: none;
}
.btn-sync-refresh:hover {
    background: linear-gradient(135deg, #4338ca, #4f46e5);
    box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
    transform: translateY(-1px);
}

/* KPI CARDS GRID */
.kinerja-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1200px) {
    .kinerja-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 640px) {
    .kinerja-kpi-grid {
        grid-template-columns: 1fr;
    }
}
.kinerja-kpi-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 18px 20px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    width: 100%;
    box-sizing: border-box;
}
.kinerja-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}
.kinerja-kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.kinerja-kpi-text span.label {
    display: block;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 2px;
}
.kinerja-kpi-text h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 800;
    color: #0f172a;
}
.kinerja-kpi-text p.sub {
    margin: 3px 0 0;
    font-size: 12px;
    color: #64748b;
}

/* LEADERBOARD PARALEL CARDS GRID (FLUID FULL WIDTH) */
.kinerja-paralel-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 22px;
    width: 100%;
    box-sizing: border-box;
}

@media (max-width: 1100px) {
    .kinerja-paralel-grid {
        grid-template-columns: 1fr;
    }
}

.kinerja-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}
.kinerja-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.09);
    border-color: #cbd5e1;
}

/* TOP RANK SPECIAL HIGHLIGHT */
.kinerja-card.rank-1 {
    border: 2px solid #f59e0b;
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.12);
}
.kinerja-card.rank-2 {
    border: 1.5px solid #94a3b8;
}
.kinerja-card.rank-3 {
    border: 1.5px solid #d97706;
}

/* CARD HEADER */
.kinerja-card-header {
    padding: 18px 22px;
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.kinerja-title-group {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* RANK BADGES (GOLD, SILVER, BRONZE) */
.badge-rank-gold {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    border: 1px solid #fcd34d;
    font-weight: 800;
    font-size: 13px;
    padding: 5px 12px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 6px rgba(245, 158, 11, 0.25);
}
.badge-rank-silver {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    color: #334155;
    border: 1px solid #cbd5e1;
    font-weight: 800;
    font-size: 13px;
    padding: 5px 12px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.badge-rank-bronze {
    background: linear-gradient(135deg, #ffedd5, #fed7aa);
    color: #9a3412;
    border: 1px solid #fdba74;
    font-weight: 800;
    font-size: 13px;
    padding: 5px 12px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.badge-rank-default {
    background: #f8fafc;
    color: #64748b;
    border: 1px solid #e2e8f0;
    font-weight: 700;
    font-size: 12.5px;
    padding: 4px 10px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.kinerja-paralel-name {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
}
.kinerja-paralel-meta {
    margin: 2px 0 0;
    font-size: 12px;
    color: #64748b;
}

/* BIG AVERAGE SCORE DISPLAY */
.kinerja-score-display {
    text-align: right;
}
.kinerja-score-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 2px;
}
.kinerja-score-pill {
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    color: #3730a3;
    font-size: 20px;
    font-weight: 900;
    padding: 4px 14px;
    border-radius: 12px;
    border: 1px solid #c7d2fe;
    display: inline-block;
}
.kinerja-score-pill.highlight-top {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    border-color: #fcd34d;
}

/* SUB-KELAS / ROMBEL CHIPS SECTION */
.kinerja-subkelas-box {
    background: #f8fafc;
    border-bottom: 1px solid #f1f5f9;
    padding: 12px 22px;
}
.kinerja-subkelas-label {
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    letter-spacing: 0.4px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.kinerja-chips-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.kinerja-chip {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 5px 10px;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}
.kinerja-chip .sub-name {
    font-weight: 600;
    color: #334155;
}
.kinerja-chip .sub-val {
    font-weight: 800;
    color: #4f46e5;
    background: #eef2ff;
    padding: 1px 6px;
    border-radius: 5px;
}

/* MAPEL RANKING SECTION */
.kinerja-mapel-box {
    padding: 18px 22px 22px;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.kinerja-mapel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.kinerja-mapel-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #475569;
    display: flex;
    align-items: center;
    gap: 6px;
}

.kinerja-mapel-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.kinerja-mapel-item {
    background: #ffffff;
    border: 1px solid #f1f5f9;
    border-radius: 10px;
    padding: 8px 12px;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.kinerja-mapel-item:hover {
    background: #f8fafc;
    border-color: #e2e8f0;
}
.kinerja-mapel-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    margin-bottom: 5px;
}
.kinerja-mapel-left {
    display: flex;
    align-items: center;
    gap: 9px;
    max-width: 75%;
}
.kinerja-num-badge {
    width: 22px;
    height: 22px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.kinerja-num-1 { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.kinerja-num-2 { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
.kinerja-num-3 { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
.kinerja-num-def { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }

.kinerja-mapel-name {
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.kinerja-mapel-score {
    font-weight: 800;
    font-size: 14px;
    color: #0f172a;
}

/* PROGRESS BAR */
.kinerja-progress-bg {
    width: 100%;
    height: 5px;
    background: #f1f5f9;
    border-radius: 10px;
    overflow: hidden;
}
.kinerja-progress-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.4s ease;
}

.bar-emerald { background: linear-gradient(90deg, #10b981, #059669); }
.bar-blue    { background: linear-gradient(90deg, #3b82f6, #2563eb); }
.bar-indigo  { background: linear-gradient(90deg, #6366f1, #4f46e5); }
.bar-amber   { background: linear-gradient(90deg, #f59e0b, #d97706); }

/* FOOTER NOTE */
.kinerja-card-footer {
    margin-top: 14px;
    padding-top: 10px;
    border-top: 1px solid #f1f5f9;
    font-size: 11.5px;
    color: #94a3b8;
    display: flex;
    justify-content: space-between;
}

/* OFFLINE / ERROR STATE */
.kinerja-error-box {
    background: #ffffff;
    border: 1px solid #fecaca;
    border-radius: 16px;
    padding: 40px 20px;
    text-align: center;
    box-shadow: 0 4px 16px rgba(239, 68, 68, 0.06);
}
.kinerja-error-icon {
    width: 60px;
    height: 60px;
    background: #fee2e2;
    color: #dc2626;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    margin: 0 auto 16px;
}
</style>

<div class="kinerja-wrapper">

    <!-- 1. HEADER CARD (TITLE, SUBTITLE, FILTER & REFRESH ACTION) -->
    <div class="kinerja-header-card">
        <div class="kinerja-header-info">
            <div style="display: flex; align-items: center; flex-wrap: wrap;">
                <span class="kinerja-badge-category">
                    <i class="fa-solid fa-trophy"></i> Evaluasi Pembelajaran
                </span>
                <?php if ($isFromCache): ?>
                    <span class="kinerja-badge-cache" title="Data disajikan dari cache lokal untuk waktu respon instan">
                        <i class="fa-solid fa-bolt" style="color: #f59e0b;"></i> Cache Aktif
                    </span>
                <?php endif; ?>
            </div>
            <h1>Pencapaian Nilai Rata-rata Per Paralel</h1>
            <p>
                Urutan paralel diperingkatkan <strong>dari rata-rata tertinggi ke terendah</strong> bersumber dari <strong>Ujian Akhir Semester (PAS)</strong>.
            </p>
        </div>

        <!-- FORM FILTER & TOMBOL SINKRONISASI -->
        <form method="GET" action="" class="kinerja-filter-form">
            <!-- Filter Unit -->
            <select name="unit_id" onchange="this.form.submit()" class="kinerja-select" title="Pilih Unit Sekolah">
                <?php if (empty($availableUnits)): ?>
                    <option value="2">SD (Sekolah Dasar)</option>
                <?php else: ?>
                    <?php foreach ($availableUnits as $u): ?>
                        <option value="<?= (int)$u['id']; ?>" <?= ($selectedUnit && $selectedUnit['id'] == $u['id']) ? 'selected' : ''; ?>>
                            Unit <?= htmlspecialchars($u['nama'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>

            <!-- Filter Tahun Ajaran & Semester -->
            <select name="tahun_ajaran_id" onchange="this.form.submit()" class="kinerja-select" title="Pilih Tahun Ajaran & Semester">
                <?php if (empty($availableYears)): ?>
                    <option value="">Tahun Ajaran Aktif</option>
                <?php else: ?>
                    <?php foreach ($availableYears as $y): ?>
                        <option value="<?= (int)$y['id']; ?>" <?= ($selectedYear && $selectedYear['id'] == $y['id']) ? 'selected' : ''; ?>>
                            TP <?= htmlspecialchars($y['nama'], ENT_QUOTES, 'UTF-8'); ?> (Sem. <?= (int)$y['semester']; ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>

            <!-- Tombol Sinkronkan Ulang -->
            <a href="?unit_id=<?= (int)$unitId; ?><?= $tahunAjaranId ? '&tahun_ajaran_id=' . (int)$tahunAjaranId : ''; ?>&refresh=1" 
               class="btn-sync-refresh"
               title="Tarik data nilai terbaru langsung dari server laptop sekolah">
                <i class="fa-solid fa-arrows-rotate <?= $forceRefresh ? 'fa-spin' : ''; ?>"></i> Sinkronkan
            </a>
        </form>
    </div>

    <!-- NOTIFIKASI JIKA OFFLINE FALLBACK -->
    <?php if ($warningMessage): ?>
        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 14px 18px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; color: #92400e; font-size: 13.5px;">
            <i class="fa-solid fa-circle-exclamation" style="font-size: 18px; color: #f59e0b;"></i>
            <div>
                <strong>Pemberitahuan Cache:</strong> <?= htmlspecialchars($warningMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAMPILAN ERROR JIKA KONEKSI GAGAL DAN TIDAK ADA CACHE -->
    <?php if (!$isSuccess): ?>
        <div class="kinerja-error-box">
            <div class="kinerja-error-icon">
                <i class="fa-solid fa-server"></i>
            </div>
            <h2 style="margin: 0 0 8px; font-size: 20px; font-weight: 800; color: #0f172a;">Gagal Memuat Data Nilai</h2>
            <p style="margin: 0 auto 20px; max-width: 500px; font-size: 14px; color: #64748b; line-height: 1.5;">
                <?= htmlspecialchars($errorMessage ?? 'Server data nilai laptop sekolah sedang tidak terjangkau atau offline.', ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <a href="?unit_id=<?= (int)$unitId; ?>&refresh=1" class="btn-sync-refresh" style="display: inline-flex;">
                <i class="fa-solid fa-rotate-right"></i> Coba Hubungkan Kembali
            </a>
        </div>
    <?php else: ?>

        <!-- 2. SUMMARY KPI OVERVIEW CARDS -->
        <div class="kinerja-kpi-grid">
            <!-- KPI 1: Unit -->
            <div class="kinerja-kpi-card">
                <div class="kinerja-kpi-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-school"></i>
                </div>
                <div class="kinerja-kpi-text">
                    <span class="label">Satuan Pendidikan</span>
                    <h3><?= htmlspecialchars($selectedUnit['nama'] ?? 'SD', ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="sub"><?= count($paralelData); ?> Paralel &bull; <?= number_format($totalStudentsInUnit, 0, ',', '.'); ?> Siswa</p>
                </div>
            </div>

            <!-- KPI 2: Tahun & Semester -->
            <div class="kinerja-kpi-card">
                <div class="kinerja-kpi-icon" style="background: #faf5ff; color: #9333ea;">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div class="kinerja-kpi-text">
                    <span class="label">Periode Penilaian</span>
                    <h3><?= htmlspecialchars($selectedYear['nama'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="sub" style="color: #9333ea; font-weight: 600;">Semester <?= (int)($selectedYear['semester'] ?? 1); ?> (Ujian Akhir)</p>
                </div>
            </div>

            <!-- KPI 3: Rata-Rata Seluruh Unit -->
            <div class="kinerja-kpi-card">
                <div class="kinerja-kpi-icon" style="background: #ecfdf5; color: #059669;">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div class="kinerja-kpi-text">
                    <span class="label">Rata-rata Unit</span>
                    <h3 style="color: #059669;"><?= number_format($overallUnitAvg, 2, ',', '.'); ?></h3>
                    <p class="sub">Rerata Seluruh Tingkat</p>
                </div>
            </div>

            <!-- KPI 4: Paralel Tertinggi / Bintang Utama -->
            <div class="kinerja-kpi-card" style="border-color: #fde68a;">
                <div class="kinerja-kpi-icon" style="background: #fef3c7; color: #d97706;">
                    <i class="fa-solid fa-crown"></i>
                </div>
                <div class="kinerja-kpi-text">
                    <span class="label">Paralel Tertinggi (Rank #1)</span>
                    <h3 style="color: #92400e;"><?= htmlspecialchars($topParalelName ?? '-', ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="sub" style="color: #b45309; font-weight: 700;">Rata-rata: <?= number_format($topParalelScore, 2, ',', '.'); ?></p>
                </div>
            </div>
        </div>

        <!-- 3. GRID KARTU PARALEL (TERURUT DARI RATA-RATA TERTINGGI KE TERENDAH) -->
        <div class="kinerja-paralel-grid">
            <?php foreach ($paralelData as $rankIndex => $paralel): ?>
                <?php 
                    $rankNumber = $rankIndex + 1;
                    $paralelAvg = (float)($paralel['rata_rata_paralel'] ?? 0);
                    $subClasses = $paralel['sub_kelas'] ?? [];
                    $mapelList  = $paralel['mapel_rankings'] ?? [];

                    // Penentuan styling card dan badge ranking berdasarkan posisi
                    $cardRankClass = 'rank-default';
                    $badgeRankHtml = '';
                    $pillClass = '';

                    if ($rankNumber === 1) {
                        $cardRankClass = 'rank-1';
                        $badgeRankHtml = '<span class="badge-rank-gold"><i class="fa-solid fa-crown"></i> Juara 1</span>';
                        $pillClass = 'highlight-top';
                    } elseif ($rankNumber === 2) {
                        $cardRankClass = 'rank-2';
                        $badgeRankHtml = '<span class="badge-rank-silver"><i class="fa-solid fa-medal"></i> Juara 2</span>';
                    } elseif ($rankNumber === 3) {
                        $cardRankClass = 'rank-3';
                        $badgeRankHtml = '<span class="badge-rank-bronze"><i class="fa-solid fa-award"></i> Juara 3</span>';
                    } else {
                        $badgeRankHtml = '<span class="badge-rank-default"><i class="fa-solid fa-hashtag"></i> Peringkat ' . $rankNumber . '</span>';
                    }
                ?>
                <div class="kinerja-card <?= $cardRankClass; ?>">

                    <!-- CARD HEADER -->
                    <div class="kinerja-card-header">
                        <div class="kinerja-title-group">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                    <?= $badgeRankHtml; ?>
                                </div>
                                <h3 class="kinerja-paralel-name">
                                    Paralel <?= htmlspecialchars($paralel['paralel_nama'], ENT_QUOTES, 'UTF-8'); ?>
                                </h3>
                                <p class="kinerja-paralel-meta">
                                    <?= (int)($paralel['total_siswa'] ?? 0); ?> Siswa &bull; <?= count($subClasses); ?> Rombongan Belajar
                                </p>
                            </div>
                        </div>

                        <!-- BIG AVERAGE SCORE BADGE -->
                        <div class="kinerja-score-display">
                            <span class="kinerja-score-label">Rata-rata Paralel</span>
                            <span class="kinerja-score-pill <?= $pillClass; ?>">
                                <?= number_format($paralelAvg, 2, ',', '.'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- SUB-KELAS / ROMBEL CHIPS -->
                    <div class="kinerja-subkelas-box">
                        <div class="kinerja-subkelas-label">
                            <i class="fa-solid fa-layer-group" style="color: #6366f1;"></i> Rincian Sub-Kelas (Rombel)
                        </div>
                        <div class="kinerja-chips-container">
                            <?php if (empty($subClasses)): ?>
                                <span style="font-size: 12px; color: #94a3b8; font-style: italic;">Tidak ada data rombel</span>
                            <?php else: ?>
                                <?php foreach ($subClasses as $sub): ?>
                                    <div class="kinerja-chip">
                                        <span class="sub-name"><?= htmlspecialchars($sub['nama'], ENT_QUOTES, 'UTF-8'); ?>:</span>
                                        <span class="sub-val"><?= number_format((float)$sub['rata_rata'], 2, ',', '.'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- MAPEL RANKING TABLE -->
                    <div class="kinerja-mapel-box">
                        <div>
                            <div class="kinerja-mapel-header">
                                <span class="kinerja-mapel-title">
                                    <i class="fa-solid fa-ranking-star" style="color: #f59e0b;"></i> Urutan Mapel dari yang Tertinggi
                                </span>
                                <span style="font-size: 11.5px; color: #94a3b8; font-weight: 600;">
                                    <?= count($mapelList); ?> Mata Pelajaran
                                </span>
                            </div>

                            <div class="kinerja-mapel-list">
                                <?php if (empty($mapelList)): ?>
                                    <div style="text-align: center; padding: 24px; font-size: 12.5px; color: #94a3b8; font-style: italic;">
                                        Tidak ada entri nilai PAS untuk paralel ini.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($mapelList as $mIdx => $m): ?>
                                        <?php 
                                            $mRank = $mIdx + 1;
                                            $mScore = (float)($m['rata_rata'] ?? 0);

                                            // Styling nomor ranking mapel
                                            $numBadgeClass = 'kinerja-num-def';
                                            if ($mRank === 1) $numBadgeClass = 'kinerja-num-1';
                                            elseif ($mRank === 2) $numBadgeClass = 'kinerja-num-2';
                                            elseif ($mRank === 3) $numBadgeClass = 'kinerja-num-3';

                                            // Dynamic bar color
                                            $barColorClass = 'bar-indigo';
                                            if ($mScore >= 92) $barColorClass = 'bar-emerald';
                                            elseif ($mScore >= 87) $barColorClass = 'bar-blue';
                                            elseif ($mScore >= 80) $barColorClass = 'bar-indigo';
                                            else $barColorClass = 'bar-amber';
                                        ?>
                                        <div class="kinerja-mapel-item">
                                            <div class="kinerja-mapel-row">
                                                <div class="kinerja-mapel-left">
                                                    <span class="kinerja-num-badge <?= $numBadgeClass; ?>">
                                                        <?= $mRank; ?>
                                                    </span>
                                                    <span class="kinerja-mapel-name" title="<?= htmlspecialchars($m['mapel_nama'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?= htmlspecialchars($m['mapel_nama'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </div>
                                                <span class="kinerja-mapel-score">
                                                    <?= number_format($mScore, 2, ',', '.'); ?>
                                                </span>
                                            </div>
                                            <!-- Mini Progress Bar -->
                                            <div class="kinerja-progress-bg">
                                                <div class="kinerja-progress-fill <?= $barColorClass; ?>" 
                                                     style="width: <?= min(100, max(0, $mScore)); ?>%;"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- CARD FOOTER INFO -->
                        <div class="kinerja-card-footer">
                            <span><i class="fa-solid fa-file-lines" style="margin-right: 4px;"></i> Komponen: <strong>UAS (PAS)</strong></span>
                            <span>Total Penilaian: <strong><?= number_format((int)($paralel['total_entri'] ?? 0), 0, ',', '.'); ?></strong> data</span>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
