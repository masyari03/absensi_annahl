<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('kinerja_capaian_tahfidz');

$canEditTarget = canEdit('kinerja_capaian_tahfidz');
$pageTitle     = 'Capaian Tahfidz & Jurnal Guru — Penilaian Kinerja';
$accessCtx     = getTahfidzAccessContext($pdo);

// 1. Handle POST Simpan Target Kurikulum per Paralel & Semester
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_targets') {
    if (!$canEditTarget) {
        http_response_code(403);
        die('403 - Akses Ditolak: Anda tidak memiliki izin mengubah target kurikulum Tahfidz.');
    }
    requireCsrf();

    $postUnitId   = max(1, (int)($_POST['unit_id'] ?? 2));
    $postSemester = ((int)($_POST['semester'] ?? 1) === 2) ? 2 : 1;
    $postTahun    = trim((string)($_POST['tahun'] ?? ''));
    $postMonth    = max(0, min(12, (int)($_POST['month'] ?? 0)));
    $postTab      = in_array($_POST['tab'] ?? '', ['santri', 'jurnal'], true) ? $_POST['tab'] : 'santri';
    $postSort     = in_array($_POST['sort'] ?? '', ['grade_asc', 'highest', 'lowest'], true) ? $_POST['sort'] : 'grade_asc';

    // Validasi horizontal unit scoping saat POST agar user non-superadmin tidak dapat mengubah unit lain
    assertTahfidzPostUnitAccess($postUnitId, $accessCtx);

    $allowedTargetTypes = [
        'juz30_rank', 'juz29_rank', 'juz28_rank',
        'juz_stage', 'juz29_coverage', 'juz28_coverage', 'teacher_individual'
    ];
    $rawTargets = $_POST['targets'] ?? [];

    if (!is_array($rawTargets) || empty($rawTargets)) {
        $_SESSION['tahfidz_flash'] = ['type' => 'error', 'text' => 'Data target tidak valid atau kosong.'];
    } else {
        try {
            $pdo->beginTransaction();
            $stmtSave = $pdo->prepare("
                INSERT INTO kinerja_tahfidz_targets
                    (unit_id, paralel_id, paralel_nama, semester, target_type, target_value, target_label, require_munaqasyah, updated_by)
                VALUES
                    (:unit_id, :paralel_id, :paralel_nama, :semester, :target_type, :target_value, :target_label, :require_munaqasyah, :updated_by)
                ON DUPLICATE KEY UPDATE
                    paralel_nama       = VALUES(paralel_nama),
                    target_type        = VALUES(target_type),
                    target_value       = VALUES(target_value),
                    target_label       = VALUES(target_label),
                    require_munaqasyah = VALUES(require_munaqasyah),
                    updated_by         = VALUES(updated_by)
            ");

            $savedCount = 0;
            $catalog    = getTahfidzSurahCatalog();

            foreach ($rawTargets as $pIdRaw => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pId = (int)$pIdRaw;
                if ($pId <= 0) {
                    continue;
                }
                $tType = (string)($row['target_type'] ?? 'juz30_rank');
                if (!in_array($tType, $allowedTargetTypes, true)) {
                    $tType = 'juz30_rank';
                }
                $tVal   = max(0.0, min(100.0, (float)($row['target_value'] ?? 13)));
                $pNama  = mb_substr(trim(strip_tags((string)($row['paralel_nama'] ?? "Level {$pId}"))), 0, 100);
                $tLabel = mb_substr(trim(strip_tags((string)($row['target_label'] ?? ''))), 0, 150);
                if ($tLabel === '') {
                    $intKey = (int)round($tVal);
                    $tLabel = $catalog[$tType][$intKey] ?? 'Target Tahfidz';
                }
                $reqMunaq = !empty($row['require_munaqasyah']) ? 1 : 0;

                $stmtSave->execute([
                    ':unit_id'            => $postUnitId,
                    ':paralel_id'         => $pId,
                    ':paralel_nama'       => $pNama,
                    ':semester'           => $postSemester,
                    ':target_type'        => $tType,
                    ':target_value'       => $tVal,
                    ':target_label'       => $tLabel,
                    ':require_munaqasyah' => $reqMunaq,
                    ':updated_by'         => currentUserId(),
                ]);
                $savedCount++;
            }

            $pdo->commit();
            $_SESSION['tahfidz_flash'] = [
                'type' => 'success',
                'text' => "Berhasil menyimpan {$savedCount} target kurikulum Tahfidz (Semester {$postSemester})."
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[tahfidz.php save_targets] Error: ' . $e->getMessage());
            $_SESSION['tahfidz_flash'] = [
                'type' => 'error',
                'text' => 'Gagal menyimpan target kurikulum karena kendala database internal.'
            ];
        }
    }

    $redirParams = [
        'unit_id'  => $postUnitId,
        'semester' => $postSemester,
        'tab'      => $postTab,
        'sort'     => $postSort,
    ];
    if ($postTahun !== '') {
        $redirParams['tahun'] = $postTahun;
    }
    if ($postMonth > 0) {
        $redirParams['month'] = $postMonth;
    }
    header('Location: ' . BASE_URL . '/kinerja/tahfidz.php?' . http_build_query($redirParams));
    exit;
}

$flashMessage = $_SESSION['tahfidz_flash'] ?? null;
unset($_SESSION['tahfidz_flash']);

// 2. Parameter GET Filter & Probe AIS API (Murni Aturan Baku Layer 1 AIS, Terpisah dari Halaman AI)
$activeTab        = in_array($_GET['tab'] ?? '', ['santri', 'jurnal'], true) ? (string)$_GET['tab'] : 'santri';
$ctx              = loadTahfidzFilterContext($pdo, $accessCtx, $_GET);
$unitId           = $ctx['unit_id'];
$month            = $ctx['month'];
$sortMode         = $ctx['sort_mode'];
$apiResponse      = $ctx['api_response'];
$availableUnits   = $ctx['available_units'];
$uniqueYearNames  = $ctx['unique_year_names'];
$availableMonths  = $ctx['available_months'];
$selectedUnit     = $ctx['selected_unit'];
$selectedYear     = $ctx['selected_year'];
$selectedYearName = $ctx['selected_year_name'];
$selectedSemester = $ctx['selected_semester'];
$isSuccess        = $ctx['is_success'];
$apiError         = $ctx['api_error'];
$isFromCache      = $ctx['is_from_cache'];
$sourceVia        = $ctx['source_via'];
$meta             = $ctx['meta'];

$monthLabelsId = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// 3. Hitung Hierarki Capaian Baku & Data Tab Jurnal Guru
$surahCatalog   = getTahfidzSurahCatalog();
$targetConfigs  = $isSuccess ? getTahfidzTargetConfigs($pdo, $unitId, $selectedSemester) : [];
$hierarchy      = $isSuccess ? calculateTahfidzHierarchy($apiResponse, $targetConfigs, $sortMode) : [
    'unit_summary' => [
        'total_students' => 0, 'achieved_students' => 0, 'percentage' => 0.0, 'avg_progress_pct' => 0.0,
        'total_paralels' => 0, 'total_rombels' => 0, 'best_paralel' => null, 'best_rombel' => null, 'lowest_rombel' => null
    ],
    'paralels' => [],
    'teacher_stats' => [],
];

$startDate       = (string)($selectedYear['start_date'] ?? date('Y-01-01'));
$endDate         = (string)($selectedYear['end_date'] ?? date('Y-m-d'));
$hariEfektifUnit = (int)($meta['hari_efektif_unit'] ?? 0);
$unitActiveDays  = is_array($meta['unit_active_days'] ?? null) ? $meta['unit_active_days'] : [];

$journalMerge = $isSuccess
    ? mergeTeacherAttendanceWithJournals(
        $pdo,
        is_array($apiResponse['guru_journals'] ?? null) ? $apiResponse['guru_journals'] : [],
        $hierarchy['teacher_stats'],
        $startDate,
        $endDate,
        $month,
        $hariEfektifUnit
    )
    : [
        'period_info' => ['att_start' => $startDate, 'att_end' => $endDate, 'hari_efektif_unit' => 0, 'has_any_attendance_data' => false],
        'summary'     => ['total_guru' => 0, 'guru_with_attendance' => 0, 'avg_hari_masuk' => 0.0, 'avg_jurnal_pct' => null, 'count_disiplin_85' => 0, 'count_perlu_pembinaan' => 0, 'count_kinerja_prima' => 0, 'avg_quality_disciplined' => null, 'avg_quality_other' => null],
        'rows'        => [],
    ];

$unitSum    = $hierarchy['unit_summary'];
$paralels   = $hierarchy['paralels'];
$jSummary   = $journalMerge['summary'];
$jRows      = $journalMerge['rows'];
$jPeriod    = $journalMerge['period_info'];

// Siapkan JSON aman untuk Chart & Modals
$rombelJsonMap = [];
$chartParalelLabels = [];
$chartParalelPct    = [];
$chartParalelAvg    = [];
foreach ($paralels as $p) {
    $chartParalelLabels[] = $p['paralel_nama'];
    $chartParalelPct[]    = $p['percentage'];
    $chartParalelAvg[]    = $p['avg_progress_pct'];
    foreach ($p['rombels'] as $r) {
        $key = $p['paralel_id'] . '_' . $r['rombel_id'] . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $r['rombel_code']);
        $rombelJsonMap[$key] = [
            'paralel_nama'      => $p['paralel_nama'],
            'rombel_code'       => $r['rombel_code'],
            'rombel_nama'       => $r['rombel_nama'],
            'percentage'        => $r['percentage'],
            'avg_progress_pct'  => $r['avg_progress_pct'],
            'achieved_students' => $r['achieved_students'],
            'total_students'    => $r['total_students'],
            'target_label'      => $p['target_label'],
            'students'          => $r['students'],
        ];
    }
}

$teacherJsonMap   = [];
$chartGuruLabels  = [];
$chartGuruJurnal  = [];
$chartGuruQuality = [];
foreach ($jRows as $jr) {
    $gid = (string)$jr['guru_id'];
    $teacherJsonMap[$gid] = [
        'guru_id'             => $jr['guru_id'],
        'guru_nama'           => $jr['guru_nama'],
        'guru_nik'            => $jr['guru_nik'],
        'level_list'          => $jr['level_list'],
        'jml_halaqah'         => $jr['jml_halaqah'],
        'hari_masuk'          => $jr['hari_masuk'],
        'hari_tepat_waktu'    => $jr['hari_tepat_waktu'],
        'hari_terlambat'      => $jr['hari_terlambat'],
        'hari_izin'           => $jr['hari_izin'],
        'hari_sakit'          => $jr['hari_sakit'],
        'hari_alpha'          => $jr['hari_alpha'],
        'has_attendance'      => $jr['has_attendance'],
        'hari_isi_jurnal'     => $jr['hari_isi_jurnal'],
        'sesi_jurnal_terisi'  => $jr['sesi_jurnal_terisi'],
        'sesi_expected'       => $jr['sesi_expected'],
        'jurnal_pct'          => $jr['jurnal_pct'],
        'fallback_jurnal_pct' => $jr['fallback_jurnal_pct'],
        'binaan_total'        => $jr['binaan_total'],
        'binaan_tuntas'       => $jr['binaan_tuntas'],
        'binaan_pct'          => $jr['binaan_pct'],
        'binaan_avg_progress' => $jr['binaan_avg_progress'],
        'quadrant_label'      => $jr['quadrant_label'],
        'quadrant_desc'       => $jr['quadrant_desc'],
        'students'            => $jr['binaan_students'],
    ];

    $shortName = implode(' ', array_slice(explode(' ', trim($jr['guru_nama'])), 0, 2));
    $chartGuruLabels[]  = $shortName;
    $chartGuruJurnal[]  = $jr['has_attendance'] ? $jr['jurnal_pct'] : $jr['fallback_jurnal_pct'];
    $chartGuruQuality[] = $jr['binaan_pct'];
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

$refreshUrl = '?' . http_build_query(array_filter([
    'unit_id'  => $unitId,
    'tahun'    => $selectedYearName,
    'semester' => $selectedSemester,
    'month'    => $month > 0 ? $month : null,
    'sort'     => $sortMode,
    'tab'      => $activeTab,
    'refresh'  => 1,
], static fn($v) => $v !== null && $v !== ''));

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ===== KINERJA TAHFIDZ DASHBOARD STYLES (100% FLUID FULL-WIDTH) ===== */
.kinerja-wrapper {
    width: 100%;
    max-width: 100%;
    padding: 0;
    margin: 0;
    box-sizing: border-box;
}
@media (max-width: 768px) {
    .kinerja-wrapper { padding: 0; }
}

.kinerja-header-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    padding: 24px 28px;
    margin-bottom: 18px;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    width: 100%;
    box-sizing: border-box;
}
.kinerja-header-info h1 {
    font-size: 23px;
    font-weight: 800;
    color: #0f172a;
    margin: 6px 0;
    letter-spacing: -0.4px;
}
.kinerja-header-info p {
    font-size: 13.5px;
    color: #64748b;
    margin: 0;
    line-height: 1.5;
}
.kinerja-badge-category {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ecfdf5;
    color: #047857;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 11px;
    border-radius: 20px;
    border: 1px solid #a7f3d0;
}
.kinerja-badge-cache {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #f1f5f9;
    color: #475569;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 20px;
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
    padding: 9px 15px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 10px;
    text-decoration: none;
    transition: all 0.18s ease;
    cursor: pointer;
    border: none;
    font-family: inherit;
}
.btn-kinerja-primary { background: #059669; color: #ffffff; }
.btn-kinerja-primary:hover { background: #047857; color: #ffffff; }
.btn-kinerja-indigo { background: #4f46e5; color: #ffffff; }
.btn-kinerja-indigo:hover { background: #4338ca; color: #ffffff; }
.btn-kinerja-outline { background: #ffffff; color: #334155; border: 1px solid #cbd5e1; }
.btn-kinerja-outline:hover { background: #f8fafc; border-color: #94a3b8; color: #0f172a; }

/* FILTER & PROMINENT TAB TOOLBAR */
.kinerja-filter-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 18px 24px;
    margin-bottom: 18px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
    width: 100%;
    box-sizing: border-box;
}
.kinerja-filter-form {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 14px;
    width: 100%;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.filter-group label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.45px;
}
.filter-control {
    height: 39px;
    padding: 0 12px;
    border-radius: 9px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    min-width: 155px;
}
.filter-control:focus {
    outline: none;
    border-color: #059669;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.14);
}

/* SEGMENTED TAB BAR */
.tahfidz-tab-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 22px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    padding: 10px 14px;
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
}
.tab-switch-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 11px 20px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: inherit;
}
.tab-switch-btn:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.tab-switch-btn.active-santri {
    background: linear-gradient(135deg, #059669, #047857);
    color: #ffffff;
    border-color: #047857;
    box-shadow: 0 6px 14px rgba(5, 150, 105, 0.22);
}
.tab-switch-btn.active-jurnal {
    background: linear-gradient(135deg, #4f46e5, #3730a3);
    color: #ffffff;
    border-color: #3730a3;
    box-shadow: 0 6px 14px rgba(79, 70, 229, 0.24);
}
.tab-pill-count {
    background: rgba(255, 255, 255, 0.22);
    color: inherit;
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 800;
}
.tab-switch-btn:not(.active-santri):not(.active-jurnal) .tab-pill-count {
    background: #e2e8f0;
    color: #334155;
}
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* UNIT HERO CARD (TAB 1) */
.unit-hero-card {
    background: linear-gradient(135deg, #064e3b 0%, #065f46 55%, #047857 100%);
    color: #ffffff;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 20px;
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 24px;
    align-items: center;
    box-shadow: 0 10px 25px rgba(6, 78, 59, 0.16);
}
@media (max-width: 960px) {
    .unit-hero-card { grid-template-columns: 1fr; }
}
.unit-hero-eyebrow {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.7px;
    text-transform: uppercase;
    color: #a7f3d0;
    margin-bottom: 6px;
}
.unit-hero-title {
    display: flex;
    align-items: baseline;
    gap: 14px;
    flex-wrap: wrap;
}
.unit-hero-name {
    font-size: 28px;
    font-weight: 800;
}
.unit-hero-pct {
    font-size: 40px;
    font-weight: 900;
    color: #6ee7b7;
    line-height: 1;
}
.unit-hero-sub {
    margin-top: 8px;
    font-size: 13.5px;
    color: #d1fae5;
}
.unit-hero-progress-track {
    margin-top: 14px;
    height: 11px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    overflow: hidden;
}
.unit-hero-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #34d399, #a7f3d0);
    border-radius: 999px;
    transition: width 0.5s ease;
}
.unit-hero-highlights {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.hero-mini-box {
    background: rgba(255, 255, 255, 0.11);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 12px;
    padding: 13px 15px;
}
.hero-mini-box span {
    display: block;
    font-size: 11px;
    color: #a7f3d0;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 4px;
}
.hero-mini-box strong {
    display: block;
    font-size: 16px;
    font-weight: 800;
    color: #ffffff;
}
.hero-mini-box small {
    font-size: 11.5px;
    color: #d1fae5;
}

/* KPI GRID */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
    width: 100%;
}
@media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px)  { .kpi-grid { grid-template-columns: 1fr; } }
.kpi-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.025);
    position: relative;
    overflow: hidden;
}
.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; bottom: 0;
    width: 4px;
}
.kpi-card.emerald::before { background: #10b981; }
.kpi-card.indigo::before  { background: #6366f1; }
.kpi-card.amber::before   { background: #f59e0b; }
.kpi-card.rose::before    { background: #f43f5e; }
.kpi-card.blue::before    { background: #3b82f6; }

.kpi-icon-box {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.kpi-card.emerald .kpi-icon-box { background: #ecfdf5; color: #059669; }
.kpi-card.indigo  .kpi-icon-box { background: #eef2ff; color: #4f46e5; }
.kpi-card.amber   .kpi-icon-box { background: #fffbeb; color: #d97706; }
.kpi-card.rose    .kpi-icon-box { background: #fff1f2; color: #e11d48; }
.kpi-card.blue    .kpi-icon-box { background: #eff6ff; color: #2563eb; }

.kpi-meta { flex: 1; min-width: 0; }
.kpi-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 3px; }
.kpi-value { font-size: 21px; font-weight: 800; color: #0f172a; line-height: 1.2; }
.kpi-subtext { font-size: 12px; color: #64748b; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* CHART & PARALEL GRID (TAB 1) */
.chart-section-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 20px 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.025);
    width: 100%;
    box-sizing: border-box;
}
.chart-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
}
.chart-section-header h3 {
    margin: 0;
    font-size: 15.5px;
    font-weight: 800;
    color: #0f172a;
}
.chart-box {
    position: relative;
    height: 270px;
    width: 100%;
}

.paralel-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    width: 100%;
    box-sizing: border-box;
}
@media (min-width: 1580px) {
    .paralel-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 1050px) {
    .paralel-grid { grid-template-columns: 1fr; }
}
.paralel-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.035);
    display: flex;
    flex-direction: column;
    width: 100%;
    box-sizing: border-box;
}
.paralel-card-top {
    padding: 18px 20px 14px 20px;
    background: linear-gradient(to bottom, #f8fafc, #ffffff);
    border-bottom: 1px solid #f1f5f9;
}
.paralel-title-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}
.paralel-title-row h4 {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
}
.paralel-score-pill {
    font-size: 20px;
    font-weight: 900;
    padding: 4px 12px;
    border-radius: 10px;
}
.score-high { background: #ecfdf5; color: #047857; }
.score-mid  { background: #eff6ff; color: #1d4ed8; }
.score-warn { background: #fffbeb; color: #b45309; }
.score-low  { background: #fff1f2; color: #be123c; }

.paralel-target-meta {
    margin-top: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    font-size: 12px;
    color: #475569;
}
.target-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #eef2ff;
    color: #4338ca;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 6px;
    font-size: 11.5px;
}
.paralel-bar-track {
    margin-top: 10px;
    height: 7px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
}
.paralel-bar-fill {
    height: 100%;
    border-radius: 999px;
}

.paralel-card-body {
    padding: 14px 20px 18px 20px;
    flex: 1;
}
.rombel-list-heading {
    font-size: 11px;
    font-weight: 800;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.45px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
}
.rombel-row-btn {
    width: 100%;
    text-align: left;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.16s ease;
    font-family: inherit;
    display: block;
}
.rombel-row-btn:last-child { margin-bottom: 0; }
.rombel-row-btn:hover {
    background: #ecfdf5;
    border-color: #6ee7b7;
}
.rombel-row-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}
.rombel-name-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.rombel-rank-badge {
    width: 22px; height: 22px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #e2e8f0;
    color: #334155;
    flex-shrink: 0;
}
.rombel-rank-badge.top-1 { background: #fef3c7; color: #b45309; }
.rombel-rank-badge.top-2 { background: #e0e7ff; color: #4338ca; }
.rombel-title-text {
    font-size: 13.5px;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.rombel-metrics-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.rombel-fraction {
    font-size: 11.5px;
    color: #64748b;
    font-weight: 600;
}
.rombel-pct-badge {
    font-size: 13.5px;
    font-weight: 800;
    min-width: 52px;
    text-align: right;
}
.rombel-mini-track {
    height: 6px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
}
.rombel-mini-fill { height: 100%; border-radius: 999px; }

/* TAB 2: JOURNAL & ATTENDANCE TABLE */
.notice-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.5;
}
.notice-banner.info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e40af;
}
.notice-banner.success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
}
.notice-banner.error {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    color: #9f1239;
}

.journal-table-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.03);
}
.journal-toolbar {
    padding: 16px 22px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: #f8fafc;
}
.journal-search-input {
    height: 36px;
    padding: 0 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    min-width: 240px;
}
.journal-table-responsive {
    width: 100%;
    overflow-x: auto;
}
.journal-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.journal-table th {
    background: #f8fafc;
    color: #475569;
    font-size: 11.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.35px;
    padding: 12px 16px;
    text-align: left;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}
.journal-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    color: #1e293b;
}
.journal-table tr:hover td {
    background: #f8fafc;
}

.quadrant-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
}
.q-emerald { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.q-blue    { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.q-teal    { background: #f0fdfa; color: #0f766e; border: 1px solid #99f6e4; }
.q-amber   { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
.q-rose    { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }
.q-slate   { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

.mini-sub-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 5px;
    background: #f1f5f9;
    color: #475569;
    margin-right: 4px;
    margin-top: 4px;
}

/* MODALS (Mengikuti standar .custom-modal global di header.php — bebas backdrop-filter & transform glitch) */
.custom-modal-overlay.tahfidz-modal {
    overflow-y: auto;
}
.custom-modal-overlay.tahfidz-modal .custom-modal-box {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #e2e8f0;
}
.custom-modal-overlay.tahfidz-modal .custom-modal-header {
    padding: 18px 24px;
    background: #f8fafc;
    flex-shrink: 0;
}
.custom-modal-overlay.tahfidz-modal .custom-modal-header h4 {
    margin: 0;
    font-size: 17px;
    font-weight: 800;
    color: #0f172a;
}
.custom-modal-overlay.tahfidz-modal .custom-modal-body {
    padding: 20px 24px;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    overscroll-behavior: contain;
}
.custom-modal-overlay.tahfidz-modal .custom-modal-footer {
    padding: 14px 24px;
    flex-shrink: 0;
}
.raw-table { width: 100%; border-collapse: collapse; font-size: 12.8px; }
.raw-table th {
    background: #f8fafc;
    text-align: left;
    padding: 9px 11px;
    font-weight: 700;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
}
.raw-table td {
    padding: 9px 11px;
    border-bottom: 1px solid #f1f5f9;
}
.status-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}
.st-achieved { background: #dcfce7; color: #166534; }
.st-near     { background: #dbeafe; color: #1e40af; }
.st-progress { background: #fef3c7; color: #92400e; }
.st-empty    { background: #f1f5f9; color: #64748b; }
</style>

<?php
$pctClass = static function (float $val): string {
    if ($val >= 85.0) return 'score-high';
    if ($val >= 70.0) return 'score-mid';
    if ($val >= 50.0) return 'score-warn';
    return 'score-low';
};
$barColor = static function (float $val): string {
    if ($val >= 85.0) return '#10b981';
    if ($val >= 70.0) return '#3b82f6';
    if ($val >= 50.0) return '#f59e0b';
    return '#f43f5e';
};
?>

<div class="kinerja-wrapper">

    <!-- 1. HEADER CARD -->
    <div class="kinerja-header-card">
        <div class="kinerja-header-info">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span class="kinerja-badge-category">
                    <i class="fa-solid fa-book-quran"></i> PENILAIAN KINERJA &bull; TAHFIDZ AL-QUR'AN
                </span>
                <?php if ($isFromCache): ?>
                    <span class="kinerja-badge-cache" title="Data disajikan dari cache lokal berkecepatan tinggi">
                        <i class="fa-solid fa-bolt" style="color:#eab308;"></i> Cache Aktif
                    </span>
                <?php elseif ($isSuccess): ?>
                    <span class="kinerja-badge-cache" style="background:#ecfdf5; color:#047857;">
                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($sourceVia) ?>
                    </span>
                <?php endif; ?>
            </div>
            <h1>Capaian Tahfidz &amp; Indikator Kerja Guru Qur'an</h1>
            <p>
                Monitoring hierarki ketuntasan hafalan <strong>Unit &rarr; Paralel &rarr; Kelas</strong> serta komparasi kedisiplinan <strong>Jurnal vs Kehadiran Guru Qur'an</strong>.
            </p>
        </div>

        <div class="header-action-group">
            <a href="<?= htmlspecialchars($refreshUrl) ?>" class="btn-kinerja btn-kinerja-primary" title="Tarik ulang data live terbaru dari server AIS">
                <i class="fa-solid fa-arrows-rotate"></i> Sinkronkan
            </a>
            <?php if ($isSuccess && !empty($paralels)): ?>
                <button type="button" class="btn-kinerja btn-kinerja-indigo" onclick="openTahfidzModal('targetModal')">
                    <i class="fa-solid fa-sliders"></i> Atur Target Kurikulum
                </button>
            <?php endif; ?>
            <button type="button" class="btn-kinerja btn-kinerja-outline" onclick="openTahfidzModal('methodModal')">
                <i class="fa-solid fa-scale-balanced"></i> Urutan &amp; Rumus
            </button>
        </div>
    </div>

    <?php if (!empty($flashMessage)): ?>
        <div class="notice-banner <?= $flashMessage['type'] === 'success' ? 'success' : 'error' ?>">
            <i class="fa-solid <?= $flashMessage['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>" style="margin-top:2px;"></i>
            <div><?= htmlspecialchars($flashMessage['text']) ?></div>
        </div>
    <?php endif; ?>

    <!-- 2. FILTER BAR -->
    <div class="kinerja-filter-card">
        <form method="GET" action="" class="kinerja-filter-form" id="filterForm">
            <input type="hidden" name="tab" id="activeTabInput" value="<?= htmlspecialchars($activeTab) ?>">

            <div class="filter-group">
                <label for="f_unit"><i class="fa-solid fa-school"></i> Unit Sekolah</label>
                <select name="unit_id" id="f_unit" class="filter-control" onchange="this.form.submit()">
                    <?php if (!empty($availableUnits)): ?>
                        <?php foreach ($availableUnits as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= ((int)$u['id'] === (int)$unitId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$u['nama']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="2" <?= ($unitId == 2) ? 'selected' : '' ?>>SD</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="f_tahun"><i class="fa-solid fa-calendar-days"></i> Tahun Ajaran</label>
                <select name="tahun" id="f_tahun" class="filter-control" onchange="this.form.submit()">
                    <?php foreach ($uniqueYearNames as $yn): ?>
                        <option value="<?= htmlspecialchars($yn) ?>" <?= ($selectedYearName === $yn) ? 'selected' : '' ?>>
                            Tahun <?= htmlspecialchars($yn) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="f_semester"><i class="fa-solid fa-clock-rotate-left"></i> Semester</label>
                <select name="semester" id="f_semester" class="filter-control" onchange="this.form.submit()">
                    <option value="1" <?= ($selectedSemester === 1) ? 'selected' : '' ?>>Semester 1 (Ganjil)</option>
                    <option value="2" <?= ($selectedSemester === 2) ? 'selected' : '' ?>>Semester 2 (Genap)</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="f_month"><i class="fa-solid fa-calendar-week"></i> Bulan (Jurnal &amp; Absen)</label>
                <select name="month" id="f_month" class="filter-control" onchange="this.form.submit()">
                    <option value="0" <?= ($month === 0) ? 'selected' : '' ?>>Semua Bulan Semester Ini</option>
                    <?php foreach ($availableMonths as $mNum): ?>
                        <option value="<?= (int)$mNum ?>" <?= ($month === (int)$mNum) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($monthLabelsId[$mNum] ?? "Bulan {$mNum}") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="f_sort"><i class="fa-solid fa-arrow-down-wide-short"></i> Urutan Kartu Paralel</label>
                <select name="sort" id="f_sort" class="filter-control" onchange="this.form.submit()">
                    <option value="grade_asc" <?= ($sortMode === 'grade_asc') ? 'selected' : '' ?>>Urut Kelas (1 &rarr; 6)</option>
                    <option value="highest"   <?= ($sortMode === 'highest')   ? 'selected' : '' ?>>Tertinggi &rarr; Terendah</option>
                    <option value="lowest"    <?= ($sortMode === 'lowest')    ? 'selected' : '' ?>>Terendah &rarr; Tertinggi</option>
                </select>
            </div>

            <div class="filter-group">
                <button type="submit" class="btn-kinerja btn-kinerja-primary" style="height:39px;">
                    <i class="fa-solid fa-filter"></i> Terapkan
                </button>
            </div>
        </form>
    </div>

    <?php if (!$isSuccess): ?>
        <div class="notice-banner error" style="padding: 24px;">
            <i class="fa-solid fa-server" style="font-size:24px;"></i>
            <div>
                <strong style="font-size:16px;">Gagal Mengambil Data Tahfidz dari Server AIS Sekolah</strong><br>
                <span><?= htmlspecialchars((string)($apiError ?: 'Server AIS belum dapat dihubungi saat ini.')) ?></span><br><br>
                <a href="<?= htmlspecialchars($refreshUrl) ?>" class="btn-kinerja btn-kinerja-primary">
                    <i class="fa-solid fa-rotate"></i> Coba Hubungkan Kembali
                </a>
            </div>
        </div>
    <?php else: ?>

        <!-- 3. TOMBOL TAB UTAMA: CAPAIAN SANTRI vs JUMLAH ISI JURNAL -->
        <div class="tahfidz-tab-bar" role="tablist">
            <button type="button"
                    id="btnTabSantri"
                    class="tab-switch-btn <?= ($activeTab === 'santri') ? 'active-santri' : '' ?>"
                    onclick="switchTahfidzTab('santri')">
                <i class="fa-solid fa-graduation-cap"></i>
                <span>CAPAIAN TAHFIDZ SANTRI</span>
                <span class="tab-pill-count"><?= htmlspecialchars((string)$selectedUnit['nama']) ?> &bull; <?= number_format($unitSum['percentage'], 1) ?>%</span>
            </button>

            <button type="button"
                    id="btnTabJurnal"
                    class="tab-switch-btn <?= ($activeTab === 'jurnal') ? 'active-jurnal' : '' ?>"
                    onclick="switchTahfidzTab('jurnal')">
                <i class="fa-solid fa-clipboard-check"></i>
                <span>JUMLAH ISI JURNAL</span>
                <span class="tab-pill-count"><?= (int)$jSummary['total_guru'] ?> Guru Qur'an</span>
            </button>

            <div style="margin-left:auto; font-size:12px; color:#64748b;">
                Periode: <strong><?= htmlspecialchars($selectedYearName) ?></strong> &bull; <strong>Semester <?= $selectedSemester ?></strong>
                <?php if ($month > 0): ?>
                    &bull; Bulan <strong><?= htmlspecialchars($monthLabelsId[$month] ?? '') ?></strong>
                <?php endif; ?>
            </div>
        </div>

        <!-- =============================================================== -->
        <!-- TAB 1: CAPAIAN TAHFIDZ SANTRI (UNIT -> PARALEL -> ROMBEL)      -->
        <!-- =============================================================== -->
        <div id="tabPanelSantri" class="tab-panel <?= ($activeTab === 'santri') ? 'active' : '' ?>">

            <!-- UNIT EXECUTIVE HERO -->
            <div class="unit-hero-card">
                <div>
                    <div class="unit-hero-eyebrow">
                        Tahun <?= htmlspecialchars($selectedYearName) ?> &bull; Semester <?= $selectedSemester ?> &bull; Capaian Keseluruhan Unit
                    </div>
                    <div class="unit-hero-title">
                        <span class="unit-hero-name"><?= htmlspecialchars((string)$selectedUnit['nama']) ?></span>
                        <span class="unit-hero-pct"><?= number_format($unitSum['percentage'], 1) ?>%</span>
                    </div>
                    <div class="unit-hero-sub">
                        <strong><?= (int)$unitSum['achieved_students'] ?></strong> dari <strong><?= (int)$unitSum['total_students'] ?> santri aktif</strong> mencapai target kurikulum semester ini
                        &bull; Rata-rata progres kumulatif: <strong><?= number_format($unitSum['avg_progress_pct'], 1) ?>%</strong>
                    </div>
                    <div class="unit-hero-progress-track">
                        <div class="unit-hero-progress-fill" style="width: <?= min(100, max(0, $unitSum['percentage'])) ?>%;"></div>
                    </div>
                </div>

                <div class="unit-hero-highlights">
                    <div class="hero-mini-box">
                        <span><i class="fa-solid fa-crown"></i> Paralel Tertinggi</span>
                        <strong><?= htmlspecialchars((string)($unitSum['best_paralel']['paralel_nama'] ?? '-')) ?></strong>
                        <small>
                            <?= $unitSum['best_paralel'] ? number_format((float)$unitSum['best_paralel']['percentage'], 1) . '% Tuntas (' . (int)$unitSum['best_paralel']['achieved_students'] . '/' . (int)$unitSum['best_paralel']['total_students'] . ')' : '-' ?>
                        </small>
                    </div>
                    <div class="hero-mini-box">
                        <span><i class="fa-solid fa-trophy"></i> Kelas Tertinggi</span>
                        <strong><?= htmlspecialchars((string)($unitSum['best_rombel']['rombel_nama'] ?? '-')) ?></strong>
                        <small>
                            <?= $unitSum['best_rombel'] ? htmlspecialchars((string)$unitSum['best_rombel']['paralel_nama']) . ' • ' . number_format((float)$unitSum['best_rombel']['percentage'], 1) . '%' : '-' ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- 4 KPI RINGKASAN SANTRI -->
            <div class="kpi-grid">
                <div class="kpi-card emerald">
                    <div class="kpi-icon-box"><i class="fa-solid fa-users"></i></div>
                    <div class="kpi-meta">
                        <div class="kpi-label">Total Santri Halaqah</div>
                        <div class="kpi-value"><?= number_format((int)$unitSum['total_students']) ?></div>
                        <div class="kpi-subtext"><?= (int)$unitSum['total_paralels'] ?> Paralel &bull; <?= (int)$unitSum['total_rombels'] ?> Rombel</div>
                    </div>
                </div>
                <div class="kpi-card indigo">
                    <div class="kpi-icon-box"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="kpi-meta">
                        <div class="kpi-label">Santri Tuntas Target</div>
                        <div class="kpi-value"><?= number_format((int)$unitSum['achieved_students']) ?></div>
                        <div class="kpi-subtext"><?= number_format($unitSum['percentage'], 1) ?>% mencapai minimal target</div>
                    </div>
                </div>
                <div class="kpi-card blue">
                    <div class="kpi-icon-box"><i class="fa-solid fa-chart-line"></i></div>
                    <div class="kpi-meta">
                        <div class="kpi-label">Rata-rata Progres</div>
                        <div class="kpi-value"><?= number_format($unitSum['avg_progress_pct'], 1) ?>%</div>
                        <div class="kpi-subtext">Skor proporsional termasuk progres ayat</div>
                    </div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-icon-box"><i class="fa-solid fa-hand-holding-heart"></i></div>
                    <div class="kpi-meta">
                        <div class="kpi-label">Perlu Pendampingan</div>
                        <div class="kpi-value"><?= number_format(max(0, (int)$unitSum['total_students'] - (int)$unitSum['achieved_students'])) ?></div>
                        <div class="kpi-subtext">
                            Terendah: <?= $unitSum['lowest_rombel'] ? htmlspecialchars((string)$unitSum['lowest_rombel']['rombel_nama']) . ' (' . number_format((float)$unitSum['lowest_rombel']['percentage'], 1) . '%)' : '-' ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($paralels)): ?>
                <!-- GRAFIK KOMPARASI PARALEL -->
                <div class="chart-section-card">
                    <div class="chart-section-header">
                        <h3><i class="fa-solid fa-chart-column" style="color:#059669;"></i> Grafik Perbandingan Capaian Antar Paralel</h3>
                        <span style="font-size:12px; color:#64748b;">Klik kartu rombel di bawah grafik untuk melihat daftar santri satu per satu</span>
                    </div>
                    <div class="chart-box">
                        <canvas id="paralelChartCanvas"></canvas>
                    </div>
                </div>

                <!-- GRID KARTU PARALEL -> URUTAN ROMBEL TERTINGGI -->
                <div class="paralel-grid">
                    <?php foreach ($paralels as $p):
                        $pPct = (float)$p['percentage'];
                        $pColor = $barColor($pPct);
                    ?>
                        <div class="paralel-card">
                            <div class="paralel-card-top">
                                <div class="paralel-title-row">
                                    <h4><?= htmlspecialchars($p['paralel_nama']) ?></h4>
                                    <span class="paralel-score-pill <?= $pctClass($pPct) ?>">
                                        <?= number_format($pPct, 1) ?>%
                                    </span>
                                </div>
                                <div class="paralel-target-meta">
                                    <span class="target-chip" title="Target kurikulum semester <?= $selectedSemester ?>">
                                        <i class="fa-solid fa-bullseye"></i> <?= htmlspecialchars($p['target_label']) ?>
                                    </span>
                                    <span>
                                        <strong><?= (int)$p['achieved_students'] ?></strong>/<?= (int)$p['total_students'] ?> Santri
                                        &bull; Progres <?= number_format((float)$p['avg_progress_pct'], 1) ?>%
                                    </span>
                                </div>
                                <div class="paralel-bar-track">
                                    <div class="paralel-bar-fill" style="width: <?= min(100, max(0, $pPct)) ?>%; background: <?= $pColor ?>;"></div>
                                </div>
                            </div>

                            <div class="paralel-card-body">
                                <div class="rombel-list-heading">
                                    <span>Urutan Capaian Tertinggi per Kelas</span>
                                    <span>Klik baris untuk rincian</span>
                                </div>
                                <?php foreach ($p['rombels'] as $idx => $r):
                                    $rPct = (float)$r['percentage'];
                                    $rColor = $barColor($rPct);
                                    $rKey = $p['paralel_id'] . '_' . $r['rombel_id'] . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $r['rombel_code']);
                                    $rankNo = $idx + 1;
                                    $rankCls = $rankNo === 1 ? 'top-1' : ($rankNo === 2 ? 'top-2' : '');
                                ?>
                                    <button type="button" class="rombel-row-btn" onclick="openRombelDetail('<?= htmlspecialchars($rKey, ENT_QUOTES) ?>')">
                                        <div class="rombel-row-head">
                                            <div class="rombel-name-wrap">
                                                <span class="rombel-rank-badge <?= $rankCls ?>"><?= $rankNo ?></span>
                                                <span class="rombel-title-text"><?= htmlspecialchars($r['rombel_nama']) ?></span>
                                            </div>
                                            <div class="rombel-metrics-right">
                                                <span class="rombel-fraction"><?= (int)$r['achieved_students'] ?>/<?= (int)$r['total_students'] ?></span>
                                                <span class="rombel-pct-badge" style="color: <?= $rColor ?>;">
                                                    <?= number_format($rPct, 1) ?>%
                                                </span>
                                            </div>
                                        </div>
                                        <div class="rombel-mini-track">
                                            <div class="rombel-mini-fill" style="width: <?= min(100, max(0, $rPct)) ?>%; background: <?= $rColor ?>;"></div>
                                        </div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="notice-banner info">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>Belum ada roster santri halaqah pada unit dan semester ini di database AIS.</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- =============================================================== -->
        <!-- TAB 2: JUMLAH ISI JURNAL (INDIKATOR KERJA GURU QUR'AN)          -->
        <!-- =============================================================== -->
        <div id="tabPanelJurnal" class="tab-panel <?= ($activeTab === 'jurnal') ? 'active' : '' ?>">

            <?php if (!$jPeriod['has_any_attendance_data']): ?>
                <div class="notice-banner info">
                    <i class="fa-solid fa-circle-info" style="font-size:18px; margin-top:2px;"></i>
                    <div>
                        <strong>Informasi Data Kehadiran Guru (<?= htmlspecialchars($jPeriod['att_start']) ?> s.d. <?= htmlspecialchars($jPeriod['att_end']) ?>):</strong><br>
                        Pada rentang tanggal semester/bulan ini belum ditemukan log <em>scan</em> kehadiran di sistem <code>absensi-anak</code> (sistem absensi mulai berjalan September 2026). Oleh karena itu, kolom <strong>Hari Masuk</strong> bernilai <code>0</code> pada periode lampau, dan sistem tetap menampilkan <strong>Rasio Pembanding terhadap Hari Efektif Unit (<?= (int)$jPeriod['hari_efektif_unit'] ?> hari aktif)</strong> agar keaktifan jurnal guru tetap dapat diaudit.
                    </div>
                </div>
            <?php endif; ?>

            <!-- 4 KPI RINGKASAN JURNAL GURU -->
            <div class="kpi-grid">
                <div class="kpi-card indigo">
                    <div class="kpi-icon-box"><i class="fa-solid fa-chalkboard-user"></i></div>
                    <div class="kpi-meta">
                        <div class="kpi-label">Guru Qur'an Aktif</div>
                        <div class="kpi-value"><?= (int)$jSummary['total_guru'] ?> Guru</div>
                        <div class="kpi-subtext">
                            <?= !empty($unitActiveDays) ? ('Jadwal unit: ' . htmlspecialchars(implode(', ', $unitActiveDays))) : 'Hari aktif unit: ' . (int)$jPeriod['hari_efektif_unit'] . ' hari' ?>
                        </div>
                    </div>
                </div>

                <div class="kpi-card blue">
                    <div class="kpi-icon-box"><i class="fa-solid fa-fingerprint"></i></div>
                    <div class="kpi-meta">
                        <div class="kpi-label">Rata-rata Hadir &amp; Jurnal</div>
                        <div class="kpi-value">
                            <?= $jSummary['avg_jurnal_pct'] !== null ? number_format((float)$jSummary['avg_jurnal_pct'], 1) . '%' : ((int)$jPeriod['hari_efektif_unit'] . ' Hari Efektif') ?>
                        </div>
                        <div class="kpi-subtext">
                            <?= $jSummary['guru_with_attendance'] > 0 ? ('Rata-rata hadir: ' . number_format((float)$jSummary['avg_hari_masuk'], 1) . ' hari (' . (int)$jSummary['guru_with_attendance'] . ' guru terdata)') : 'Menunggu log kehadiran di periode aktif' ?>
                        </div>
                    </div>
                </div>

                <div class="kpi-card emerald">
                    <div class="kpi-icon-box"><i class="fa-solid fa-award"></i></div>
                    <div class="kpi-meta">
                        <div class="kpi-label">Kinerja Prima &amp; Tertib</div>
                        <div class="kpi-value"><?= (int)$jSummary['count_kinerja_prima'] ?> / <?= (int)$jSummary['count_disiplin_85'] ?></div>
                        <div class="kpi-subtext">Prima (Jurnal &ge;85% &amp; Anak &ge;75%) / Tertib &ge;85%</div>
                    </div>
                </div>

                <div class="kpi-card amber">
                    <div class="kpi-icon-box"><i class="fa-solid fa-magnifying-glass-chart"></i></div>
                    <div class="kpi-meta">
                        <div class="kpi-label">Korelasi Kualitas Anak</div>
                        <div class="kpi-value">
                            <?= $jSummary['avg_quality_disciplined'] !== null ? number_format((float)$jSummary['avg_quality_disciplined'], 1) . '%' : '-' ?>
                            <span style="font-size:13px; color:#64748b; font-weight:600;">
                                vs <?= $jSummary['avg_quality_other'] !== null ? number_format((float)$jSummary['avg_quality_other'], 1) . '%' : '-' ?>
                            </span>
                        </div>
                        <div class="kpi-subtext">Ketuntasan anak guru tertib (&ge;85%) vs lainnya</div>
                    </div>
                </div>
            </div>

            <?php if (!empty($jRows)): ?>
                <!-- GRAFIK KOMPARASI % JURNAL VS % KUALITAS ANAK PER GURU -->
                <div class="chart-section-card">
                    <div class="chart-section-header">
                        <h3><i class="fa-solid fa-chart-bar" style="color:#4f46e5;"></i> Komparasi Disiplin Jurnal (%) vs Kualitas Anak Binaan (% Tuntas)</h3>
                        <span style="font-size:12px; color:#64748b;">
                            <?= $jPeriod['has_any_attendance_data'] ? 'Rasio Jurnal berbasis Hari Masuk (staff_attendances)' : 'Mode Pembanding Hari Efektif Unit (belum ada log absen di periode ini)' ?>
                        </span>
                    </div>
                    <div class="chart-box" style="height: 310px;">
                        <canvas id="guruJournalChartCanvas"></canvas>
                    </div>
                </div>

                <!-- TABEL INDIKATOR KERJA GURU QUR'AN -->
                <div class="journal-table-card">
                    <div class="journal-toolbar">
                        <div>
                            <strong style="font-size:15px; color:#0f172a;">Indikator Kerja Guru Qur'an &amp; Kualitas Anak</strong>
                            <div style="font-size:12px; color:#64748b;">Klik tombol <em>Detail Santri</em> pada setiap baris untuk memeriksa santri binaan per halaqah.</div>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <input type="text" id="guruSearchInput" class="journal-search-input" placeholder="Cari nama guru / NIK / level..." oninput="filterGuruRows()">
                            <select id="guruQuadrantFilter" class="filter-control" style="height:36px; min-width:190px;" onchange="filterGuruRows()">
                                <option value="">Semua Kuadran Kinerja</option>
                                <option value="prima">Kinerja Prima</option>
                                <option value="perlu_metode">Tertib / Perlu Penguatan Metode</option>
                                <option value="baik_tingkatkan_jurnal">Hasil Baik • Tingkatkan Jurnal</option>
                                <option value="pantau_berkala">Cukup • Pantau Berkala</option>
                                <option value="penertiban_jurnal">Perlu Penertiban Jurnal</option>
                                <option value="prioritas_supervisi">Prioritas Supervisi</option>
                                <option value="no_attendance">Belum Ada Data Absen</option>
                            </select>
                        </div>
                    </div>

                    <div class="journal-table-responsive">
                        <table class="journal-table" id="guruJournalTable">
                            <thead>
                                <tr>
                                    <th style="width:44px;">No</th>
                                    <th>Nama Guru Qur'an &amp; Halaqah</th>
                                    <th>Jumlah Hari Masuk</th>
                                    <th>Jumlah Jurnal Diisi</th>
                                    <th>Persentase Isi Jurnal (%)</th>
                                    <th>Kualitas Anak Binaan</th>
                                    <th>Kuadran Indikator Kerja</th>
                                    <th style="text-align:right;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jRows as $idx => $jr):
                                    $primaryPct = $jr['has_attendance'] ? (float)$jr['jurnal_pct'] : (float)$jr['fallback_jurnal_pct'];
                                    $jBarColor  = $jr['has_attendance'] ? $barColor($primaryPct) : '#94a3b8';
                                    $qBarColor  = $barColor((float)$jr['binaan_pct']);
                                    $searchHay  = strtolower($jr['guru_nama'] . ' ' . $jr['guru_nik'] . ' ' . $jr['level_list'] . ' ' . $jr['quadrant_label']);
                                ?>
                                    <tr class="guru-row"
                                        data-search="<?= htmlspecialchars($searchHay, ENT_QUOTES) ?>"
                                        data-quadrant="<?= htmlspecialchars($jr['quadrant_key'], ENT_QUOTES) ?>">
                                        <td style="font-weight:700; color:#64748b;"><?= $idx + 1 ?></td>
                                        <td>
                                            <div style="font-weight:800; color:#0f172a; font-size:13.8px;">
                                                <?= htmlspecialchars($jr['guru_nama']) ?>
                                            </div>
                                            <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                                                NIK: <code><?= htmlspecialchars($jr['guru_nik'] ?: '-') ?></code>
                                                &bull; <?= (int)$jr['jml_halaqah'] ?> Halaqah (<?= htmlspecialchars($jr['level_list']) ?>)
                                                <?php if ($jr['staff_matched']): ?>
                                                    <span class="mini-sub-badge" style="background:#ecfdf5; color:#047857;">Link <?= $jr['match_method'] === 'nik' ? 'NIK' : 'Nama' ?></span>
                                                <?php else: ?>
                                                    <span class="mini-sub-badge" style="background:#fff1f2; color:#be123c;">Belum Terlink Staff Lokal</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($jr['has_attendance']): ?>
                                                <div style="font-size:15px; font-weight:800; color:#0f172a;"><?= (int)$jr['hari_masuk'] ?> Hari</div>
                                                <div>
                                                    <span class="mini-sub-badge" style="background:#ecfdf5; color:#047857;">Tepat: <?= (int)$jr['hari_tepat_waktu'] ?></span>
                                                    <?php if ($jr['hari_terlambat'] > 0): ?>
                                                        <span class="mini-sub-badge" style="background:#fffbeb; color:#b45309;">Telat: <?= (int)$jr['hari_terlambat'] ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($jr['hari_izin'] + $jr['hari_sakit'] + $jr['hari_alpha'] > 0): ?>
                                                        <span class="mini-sub-badge">I/S/A: <?= (int)$jr['hari_izin'] ?>/<?= (int)$jr['hari_sakit'] ?>/<?= (int)$jr['hari_alpha'] ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size:14px; font-weight:700; color:#94a3b8;">0 Hari</div>
                                                <div style="font-size:11px; color:#64748b;">Tidak ada log absen</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-size:15px; font-weight:800; color:#4f46e5;"><?= (int)$jr['hari_isi_jurnal'] ?> Hari</div>
                                            <div style="font-size:11.5px; color:#64748b;">
                                                <?= (int)$jr['sesi_jurnal_terisi'] ?> Sesi Halaqah &bull; <?= (int)$jr['hari_input_nilai'] ?> Hari Nilai
                                            </div>
                                        </td>
                                        <td style="min-width:170px;">
                                            <?php if ($jr['has_attendance']): ?>
                                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                                    <strong style="font-size:15px; color:<?= $jBarColor ?>;"><?= number_format((float)$jr['jurnal_pct'], 1) ?>%</strong>
                                                    <span style="font-size:11px; color:#64748b;">Sesi: <?= number_format((float)$jr['sesi_pct'], 0) ?>%</span>
                                                </div>
                                                <div class="rombel-mini-track" style="margin-top:5px;">
                                                    <div class="rombel-mini-fill" style="width:<?= min(100, (float)$jr['jurnal_pct']) ?>%; background:<?= $jBarColor ?>;"></div>
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size:12px; font-weight:700; color:#64748b;">
                                                    N/A <span style="font-weight:500;">(vs Efektif: <?= number_format((float)$jr['fallback_jurnal_pct'], 1) ?>%)</span>
                                                </div>
                                                <div class="rombel-mini-track" style="margin-top:5px;">
                                                    <div class="rombel-mini-fill" style="width:<?= min(100, (float)$jr['fallback_jurnal_pct']) ?>%; background:#94a3b8;"></div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="min-width:165px;">
                                            <?php if ($jr['binaan_total'] > 0): ?>
                                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                                    <strong style="font-size:14.5px; color:<?= $qBarColor ?>;"><?= number_format((float)$jr['binaan_pct'], 1) ?>%</strong>
                                                    <span style="font-size:11.5px; color:#64748b;"><?= (int)$jr['binaan_tuntas'] ?>/<?= (int)$jr['binaan_total'] ?> Santri</span>
                                                </div>
                                                <div class="rombel-mini-track" style="margin-top:5px;">
                                                    <div class="rombel-mini-fill" style="width:<?= min(100, (float)$jr['binaan_pct']) ?>%; background:<?= $qBarColor ?>;"></div>
                                                </div>
                                                <div style="font-size:11px; color:#64748b; margin-top:3px;">Avg Progres: <?= number_format((float)$jr['binaan_avg_progress'], 1) ?>%</div>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size:12px;">0 Santri Aktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="quadrant-pill q-<?= htmlspecialchars($jr['quadrant_badge']) ?>" title="<?= htmlspecialchars($jr['quadrant_desc']) ?>">
                                                <?= htmlspecialchars($jr['quadrant_label']) ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right;">
                                            <button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:6px 11px; font-size:12px;"
                                                    onclick="openTeacherDetail('<?= (int)$jr['guru_id'] ?>')">
                                                <i class="fa-solid fa-users-viewfinder"></i> Detail Santri
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="notice-banner info">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>Belum ada data halaqah &amp; pengisian jurnal guru Qur'an pada periode ini.</div>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<!-- ======================================================================= -->
<!-- MODAL 1: DETAIL SANTRI PER ROMBEL (TAB 1)                               -->
<!-- ======================================================================= -->
<div id="studentDetailModal" class="custom-modal-overlay tahfidz-modal" onclick="if(event.target===this) closeModal('studentDetailModal')">
    <div class="custom-modal-box" style="max-width: 920px;">
        <div class="custom-modal-header">
            <h4 id="rombelModalTitle">Rincian Capaian Santri</h4>
            <button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:6px 12px;" onclick="closeModal('studentDetailModal')">Tutup</button>
        </div>
        <div class="custom-modal-body">
            <div id="rombelModalMeta" style="margin-bottom:14px; font-size:13px; color:#475569; display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;"></div>
            <input type="text" id="rombelStudentSearch" class="journal-search-input" style="width:100%; box-sizing:border-box; margin-bottom:12px;" placeholder="Cari nama santri atau pembimbing..." oninput="renderFilteredRombelStudents()">
            <div style="overflow-x:auto;">
                <table class="raw-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Santri</th>
                            <th>Pembimbing Halaqah</th>
                            <th>Capaian Terbaca</th>
                            <th>Progres</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="rombelModalTbody"></tbody>
                </table>
            </div>
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn-kinerja btn-kinerja-outline" onclick="closeModal('studentDetailModal')">Tutup</button>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL 2: DETAIL HALAQAH & SANTRI PER GURU (TAB 2)                       -->
<!-- ======================================================================= -->
<div id="teacherDetailModal" class="custom-modal-overlay tahfidz-modal" onclick="if(event.target===this) closeModal('teacherDetailModal')">
    <div class="custom-modal-box" style="max-width: 920px;">
        <div class="custom-modal-header">
            <h4 id="teacherModalTitle">Detail Halaqah Guru</h4>
            <button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:6px 12px;" onclick="closeModal('teacherDetailModal')">Tutup</button>
        </div>
        <div class="custom-modal-body">
            <div id="teacherModalSummary" style="margin-bottom:16px;"></div>
            <div style="font-size:12.5px; font-weight:700; color:#475569; margin-bottom:8px;">
                Daftar Santri Binaan (Diurutkan dari yang perlu pendampingan prioritas):
            </div>
            <div style="overflow-x:auto;">
                <table class="raw-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Santri</th>
                            <th>Kelas</th>
                            <th>Capaian Aktual</th>
                            <th>Target Kurikulum</th>
                            <th>Progres</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="teacherModalTbody"></tbody>
                </table>
            </div>
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn-kinerja btn-kinerja-outline" onclick="closeModal('teacherDetailModal')">Tutup</button>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL 3: ATUR TARGET KURIKULUM TAHFIDZ                                  -->
<!-- ======================================================================= -->
<div id="targetModal" class="custom-modal-overlay tahfidz-modal" onclick="if(event.target===this) closeModal('targetModal')">
    <div class="custom-modal-box" style="max-width: 980px;">
        <div class="custom-modal-header">
            <h4>Atur Target Kurikulum Tahfidz — <?= htmlspecialchars((string)$selectedUnit['nama']) ?> (Semester <?= $selectedSemester ?>)</h4>
            <button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:6px 12px;" onclick="closeModal('targetModal')">Tutup</button>
        </div>
        <form method="POST" action="">
            <div class="custom-modal-body">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_targets">
                <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
                <input type="hidden" name="semester" value="<?= (int)$selectedSemester ?>">
                <input type="hidden" name="tahun" value="<?= htmlspecialchars($selectedYearName) ?>">
                <input type="hidden" name="month" value="<?= (int)$month ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sortMode) ?>">
                <input type="hidden" name="tab" id="modalTargetTabInput" value="<?= htmlspecialchars($activeTab) ?>">

                <?php if (!$canEditTarget): ?>
                    <div class="notice-banner info">
                        <i class="fa-solid fa-lock"></i>
                        <div>Mode hanya-baca: role Anda tidak memiliki izin edit menu ini. Hubungi Super Admin untuk penyesuaian target.</div>
                    </div>
                <?php endif; ?>

                <div style="overflow-x:auto;">
                    <table class="raw-table">
                        <thead>
                            <tr>
                                <th>Paralel</th>
                                <th>Metode Target</th>
                                <th>Patokan Surat / Juz</th>
                                <th>Label Tampilan Target</th>
                                <th style="text-align:center;">Wajib Munaqasyah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paralels as $p):
                                $pid = (int)$p['paralel_id'];
                                $cfg = $p['target_config'] ?? [
                                    'target_type' => 'juz30_rank',
                                    'target_value' => 13,
                                    'target_label' => 'QS. An-Nas – At-Takatsur',
                                    'require_munaqasyah' => false,
                                ];
                                $curType = (string)$cfg['target_type'];
                                $curVal  = (int)round((float)$cfg['target_value']);
                            ?>
                                <tr>
                                    <td style="font-weight:800; white-space:nowrap;">
                                        <?= htmlspecialchars($p['paralel_nama']) ?>
                                        <input type="hidden" name="targets[<?= $pid ?>][paralel_nama]" value="<?= htmlspecialchars($p['paralel_nama']) ?>">
                                    </td>
                                    <td>
                                        <select name="targets[<?= $pid ?>][target_type]"
                                                class="filter-control" style="min-width:165px; height:34px; font-size:12px;"
                                                onchange="onTargetTypeChange(<?= $pid ?>, this.value)"
                                                <?= $canEditTarget ? '' : 'disabled' ?>>
                                            <option value="juz30_rank"         <?= $curType === 'juz30_rank' ? 'selected' : '' ?>>Juz 30 (An-Nas &rarr; An-Naba')</option>
                                            <option value="juz29_rank"         <?= $curType === 'juz29_rank' ? 'selected' : '' ?>>Juz 29 (Al-Mulk &rarr; Al-Mursalat)</option>
                                            <option value="juz28_rank"         <?= $curType === 'juz28_rank' ? 'selected' : '' ?>>Juz 28 (Al-Mujadilah &rarr; At-Tahrim)</option>
                                            <option value="juz_stage"          <?= $curType === 'juz_stage' ? 'selected' : '' ?>>Tahapan Juz (1..28)</option>
                                            <option value="teacher_individual" <?= $curType === 'teacher_individual' ? 'selected' : '' ?>>Target Individu Rapor AIS</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="targets[<?= $pid ?>][target_value]"
                                                id="target_val_<?= $pid ?>"
                                                class="filter-control" style="min-width:225px; height:34px; font-size:12px;"
                                                onchange="onTargetValueChange(<?= $pid ?>)"
                                                <?= $canEditTarget ? '' : 'disabled' ?>>
                                            <?php foreach ($surahCatalog[$curType] ?? $surahCatalog['juz30_rank'] as $valKey => $valLabel): ?>
                                                <option value="<?= (int)$valKey ?>" <?= ($curVal === (int)$valKey) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($valLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text"
                                               name="targets[<?= $pid ?>][target_label]"
                                               id="target_lbl_<?= $pid ?>"
                                               value="<?= htmlspecialchars((string)$cfg['target_label']) ?>"
                                               class="journal-search-input" style="min-width:210px; height:34px; font-size:12px;"
                                               <?= $canEditTarget ? '' : 'readonly' ?>>
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="checkbox" name="targets[<?= $pid ?>][require_munaqasyah]" value="1"
                                               <?= !empty($cfg['require_munaqasyah']) ? 'checked' : '' ?>
                                               <?= $canEditTarget ? '' : 'disabled' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="custom-modal-footer">
                <button type="button" class="btn-kinerja btn-kinerja-outline" onclick="closeModal('targetModal')">Batal</button>
                <?php if ($canEditTarget): ?>
                    <button type="submit" class="btn-kinerja btn-kinerja-primary">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Target Kurikulum
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL 4: METODOLOGI URUTAN SURAT & RUMUS INDIKATOR                      -->
<!-- ======================================================================= -->
<div id="methodModal" class="custom-modal-overlay tahfidz-modal" onclick="if(event.target===this) closeModal('methodModal')">
    <div class="custom-modal-box" style="max-width: 780px;">
        <div class="custom-modal-header">
            <h4>Acuan Urutan Surat &amp; Rumus Evaluasi Tahfidz</h4>
            <button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:6px 12px;" onclick="closeModal('methodModal')">Tutup</button>
        </div>
        <div class="custom-modal-body" style="font-size:13.5px; line-height:1.6; color:#334155;">
            <h5 style="margin-top:0; color:#0f172a;">1. Urutan Resmi Tangga Hafalan An-Nahl (Komparator)</h5>
            <ul>
                <li><strong>Juz 30 (Rank 1 &ndash; 37):</strong> Mundur dari <code>An-Nas</code> &rarr; <code>An-Naba'</code> (termasuk progres ayat parsial).</li>
                <li><strong>Juz 29 (Rank 38 &ndash; 48):</strong> Maju dari <code>Al-Mulk</code> &rarr; <code>Al-Mursalat</code>.</li>
                <li><strong>Juz 1 s.d. Juz 28 (Tahap 3 &ndash; 30):</strong> Maju dari <code>Juz 1 (Al-Baqarah)</code> &rarr; <code>Juz 28 (Al-Mujadilah s.d. At-Tahrim)</code>.</li>
            </ul>
            <h5 style="color:#0f172a;">2. Rumus Hierarki Ketuntasan Santri</h5>
            <ul>
                <li><strong>Rombel (1A, 1B, ...):</strong> <code>(Santri Tuntas Target &divide; Total Santri Rombel) &times; 100%</code>, diurutkan otomatis dari % tertinggi ke terendah.</li>
                <li><strong>Paralel &amp; Unit:</strong> Agregasi tertimbang jumlah seluruh santri tuntas dibagi total seluruh santri aktif se-Paralel / se-Unit.</li>
            </ul>
            <h5 style="color:#0f172a;">3. Indikator Kerja Guru Qur'an (Tab "JUMLAH ISI JURNAL")</h5>
            <ul>
                <li><strong>Hari Masuk:</strong> Hari unik hadir (<code>Tepat Waktu + Terlambat</code>) dari sistem absensi guru (dicocokkan via NIK tanpa tanda titik / nama ternormalisasi).</li>
                <li><strong>Jurnal Diisi:</strong> Hari unik pengisian <code>quran_jurnal</code> di AIS, agar guru pengampu banyak halaqah tetap terukur adil maksimal <code>100%</code> (dengan info tambahan kelengkapan sesi halaqah).</li>
                <li><strong>Kuadran Kinerja:</strong> Memetakan disiplin <code>% Jurnal</code> (&ge;85% vs &lt;60%) terhadap <code>% Ketuntasan Anak Binaan</code> (&ge;75% vs &lt;75%) sehingga supervisi tepat sasaran.</li>
            </ul>
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn-kinerja btn-kinerja-outline" onclick="closeModal('methodModal')">Tutup</button>
        </div>
    </div>
</div>

<script>
const ROMBEL_DATA     = <?= json_encode($rombelJsonMap, $jsonFlags) ?>;
const TEACHER_DATA    = <?= json_encode($teacherJsonMap, $jsonFlags) ?>;
const SURAH_CATALOG   = <?= json_encode($surahCatalog, $jsonFlags) ?>;

let activeRombelKey = null;

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = (str === null || str === undefined) ? '' : String(str);
    return div.innerHTML;
}

// Gunakan openModal() & closeModal() global dari header.php (scroll lock otomatis) + reset scroll body modal
function openTahfidzModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    if (typeof window.openModal === 'function') {
        window.openModal(id);
    } else {
        el.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    const body = el.querySelector('.custom-modal-body');
    if (body) body.scrollTop = 0;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.tahfidz-modal').forEach(function(m) {
            if (m.style.display === 'flex') {
                if (typeof window.closeModal === 'function') {
                    window.closeModal(m.id);
                } else {
                    m.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }
        });
    }
});

function switchTahfidzTab(tab) {
    const isSantri = (tab === 'santri');
    const pSantri  = document.getElementById('tabPanelSantri');
    const pJurnal  = document.getElementById('tabPanelJurnal');
    const bSantri  = document.getElementById('btnTabSantri');
    const bJurnal  = document.getElementById('btnTabJurnal');
    const hInput   = document.getElementById('activeTabInput');
    const mInput   = document.getElementById('modalTargetTabInput');

    if (pSantri && pJurnal) {
        pSantri.classList.toggle('active', isSantri);
        pJurnal.classList.toggle('active', !isSantri);
    }
    if (bSantri && bJurnal) {
        bSantri.classList.toggle('active-santri', isSantri);
        bJurnal.classList.toggle('active-jurnal', !isSantri);
    }
    if (hInput) hInput.value = tab;
    if (mInput) mInput.value = tab;

    try {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url.toString());
    } catch (e) {}
}

function statusChipHtml(statusKey, statusLabel) {
    const clsMap = {
        achieved: 'st-achieved',
        near:     'st-near',
        progress: 'st-progress',
        empty:    'st-empty'
    };
    return `<span class="status-chip ${clsMap[statusKey] || 'st-empty'}">${escHtml(statusLabel)}</span>`;
}

function openRombelDetail(key) {
    const data = ROMBEL_DATA[key];
    if (!data) return;
    activeRombelKey = key;
    document.getElementById('rombelModalTitle').textContent =
        `${data.paralel_nama} — ${data.rombel_nama} (${Number(data.percentage).toFixed(1)}%)`;
    document.getElementById('rombelModalMeta').innerHTML =
        `<span>Target Kurikulum: <strong>${escHtml(data.target_label)}</strong></span>` +
        `<span>Tuntas: <strong>${data.achieved_students}/${data.total_students} Santri</strong> (Avg Progres: ${Number(data.avg_progress_pct).toFixed(1)}%)</span>`;
    document.getElementById('rombelStudentSearch').value = '';
    renderFilteredRombelStudents();
    openTahfidzModal('studentDetailModal');
}

function renderFilteredRombelStudents() {
    const data = ROMBEL_DATA[activeRombelKey];
    if (!data) return;
    const q = (document.getElementById('rombelStudentSearch').value || '').trim().toLowerCase();
    const tbody = document.getElementById('rombelModalTbody');
    const filtered = (data.students || []).filter(s => {
        if (!q) return true;
        return (s.nama || '').toLowerCase().includes(q) ||
               (s.pengajar_nama || '').toLowerCase().includes(q) ||
               (s.actual_display || '').toLowerCase().includes(q);
    });

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#64748b;">Tidak ada santri yang cocok.</td></tr>';
        return;
    }

    tbody.innerHTML = filtered.map((s, i) => `
        <tr>
            <td>${i + 1}</td>
            <td><strong>${escHtml(s.nama)}</strong></td>
            <td>${escHtml(s.pengajar_nama)}</td>
            <td>${escHtml(s.actual_display)}</td>
            <td><strong>${Number(s.progress_pct).toFixed(1)}%</strong></td>
            <td>${statusChipHtml(s.status_key, s.status_label)}</td>
        </tr>
    `).join('');
}

function openTeacherDetail(guruId) {
    const t = TEACHER_DATA[String(guruId)];
    if (!t) return;
    document.getElementById('teacherModalTitle').textContent = `Halaqah Ust./Ustzh. ${t.guru_nama}`;

    const attText = t.has_attendance
        ? `${t.hari_masuk} Hari (Tepat: ${t.hari_tepat_waktu}, Telat: ${t.hari_terlambat})`
        : '0 Hari (Belum Ada Data Absen)';
    const pctText = t.has_attendance
        ? `${Number(t.jurnal_pct).toFixed(1)}%`
        : `N/A (Pembanding Efektif: ${Number(t.fallback_jurnal_pct).toFixed(1)}%)`;

    document.getElementById('teacherModalSummary').innerHTML = `
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px;">
            <div><small style="color:#64748b; display:block;">Hari Masuk</small><strong>${escHtml(attText)}</strong></div>
            <div><small style="color:#64748b; display:block;">Jurnal Diisi</small><strong>${t.hari_isi_jurnal} Hari Unik (${t.sesi_jurnal_terisi} Sesi)</strong></div>
            <div><small style="color:#64748b; display:block;">% Kepatuhan Jurnal</small><strong>${escHtml(pctText)}</strong></div>
            <div><small style="color:#64748b; display:block;">Kualitas Binaan</small><strong>${Number(t.binaan_pct).toFixed(1)}% (${t.binaan_tuntas}/${t.binaan_total} Tuntas)</strong></div>
        </div>
        <div style="margin-top:8px; font-size:12.5px; color:#475569;">
            <strong>Status Indikator:</strong> ${escHtml(t.quadrant_label)} &mdash; ${escHtml(t.quadrant_desc)}
        </div>
    `;

    const tbody = document.getElementById('teacherModalTbody');
    const stList = t.students || [];
    if (stList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:#64748b;">Belum ada santri di halaqah guru ini.</td></tr>';
    } else {
        tbody.innerHTML = stList.map((s, i) => `
            <tr>
                <td>${i + 1}</td>
                <td><strong>${escHtml(s.nama)}</strong></td>
                <td>${escHtml(s.paralel_nama)} &bull; ${escHtml(s.rombel_nama)}</td>
                <td>${escHtml(s.actual_display)}</td>
                <td>${escHtml(s.target_label)}</td>
                <td><strong>${Number(s.progress_pct).toFixed(1)}%</strong></td>
                <td>${statusChipHtml(s.status_key, s.status_label)}</td>
            </tr>
        `).join('');
    }
    openTahfidzModal('teacherDetailModal');
}

function filterGuruRows() {
    const q = (document.getElementById('guruSearchInput')?.value || '').trim().toLowerCase();
    const qFilter = document.getElementById('guruQuadrantFilter')?.value || '';
    document.querySelectorAll('#guruJournalTable tbody tr.guru-row').forEach(tr => {
        const hay  = tr.getAttribute('data-search') || '';
        const quad = tr.getAttribute('data-quadrant') || '';
        const matchQ = !q || hay.includes(q);
        const matchD = !qFilter || quad === qFilter;
        tr.style.display = (matchQ && matchD) ? '' : 'none';
    });
}

function onTargetTypeChange(paralelId, newType) {
    const valSelect = document.getElementById('target_val_' + paralelId);
    const lblInput  = document.getElementById('target_lbl_' + paralelId);
    if (!valSelect || !SURAH_CATALOG[newType]) return;

    const optionsObj = SURAH_CATALOG[newType];
    const entries = Object.entries(optionsObj);
    valSelect.innerHTML = entries.map(([k, v]) => `<option value="${escHtml(k)}">${escHtml(v)}</option>`).join('');
    if (lblInput && entries.length > 0) {
        lblInput.value = entries[0][1];
    }
}

function onTargetValueChange(paralelId) {
    const valSelect = document.getElementById('target_val_' + paralelId);
    const lblInput  = document.getElementById('target_lbl_' + paralelId);
    if (!valSelect || !lblInput) return;
    const selectedOpt = valSelect.options[valSelect.selectedIndex];
    if (selectedOpt) {
        lblInput.value = selectedOpt.text;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Pindahkan overlay modal langsung ke document.body agar bebas dari stacking-context/transition <main>
    document.querySelectorAll('.tahfidz-modal').forEach((modalEl) => {
        if (modalEl.parentElement !== document.body) {
            document.body.appendChild(modalEl);
        }
    });

    if (typeof Chart === 'undefined') return;

    const paralelCtx = document.getElementById('paralelChartCanvas');
    if (paralelCtx) {
        const pLabels = <?= json_encode($chartParalelLabels, $jsonFlags) ?>;
        const pPct    = <?= json_encode($chartParalelPct, $jsonFlags) ?>;
        const pAvg    = <?= json_encode($chartParalelAvg, $jsonFlags) ?>;
        if (pLabels.length > 0) {
            new Chart(paralelCtx, {
                type: 'bar',
                data: {
                    labels: pLabels,
                    datasets: [
                        {
                            label: '% Santri Tuntas Target',
                            data: pPct,
                            backgroundColor: 'rgba(5, 150, 105, 0.85)',
                            borderRadius: 6
                        },
                        {
                            label: 'Rata-rata Progres Kurikulum (%)',
                            data: pAvg,
                            backgroundColor: 'rgba(79, 70, 229, 0.35)',
                            borderColor: 'rgba(79, 70, 229, 0.85)',
                            borderWidth: 1,
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                    }
                }
            });
        }
    }

    const guruCtx = document.getElementById('guruJournalChartCanvas');
    if (guruCtx) {
        const gLabels  = <?= json_encode($chartGuruLabels, $jsonFlags) ?>;
        const gJurnal  = <?= json_encode($chartGuruJurnal, $jsonFlags) ?>;
        const gQuality = <?= json_encode($chartGuruQuality, $jsonFlags) ?>;
        if (gLabels.length > 0) {
            new Chart(guruCtx, {
                type: 'bar',
                data: {
                    labels: gLabels,
                    datasets: [
                        {
                            label: '<?= $jPeriod['has_any_attendance_data'] ? '% Jurnal vs Hari Masuk' : '% Jurnal vs Hari Efektif' ?>',
                            data: gJurnal,
                            backgroundColor: 'rgba(79, 70, 229, 0.85)',
                            borderRadius: 5
                        },
                        {
                            label: '% Kualitas Anak Binaan (Tuntas)',
                            data: gQuality,
                            backgroundColor: 'rgba(16, 185, 129, 0.82)',
                            borderRadius: 5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                    }
                }
            });
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
