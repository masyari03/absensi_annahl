<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('kinerja_tahfidz_ai');

$canEditAi = canEdit('kinerja_tahfidz_ai');
$pageTitle = 'Analisis Catatan AI Tahfidz — Penilaian Kinerja';
$accessCtx = getTahfidzAccessContext($pdo);

// 1. Handle POST Actions (Eksekusi Batch AI, Verifikasi Hasil AI, Koreksi Manual Santri)
$handledActions = ['run_ai_batch', 'review_ai_note', 'save_student_override'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', $handledActions, true)) {
    if (!$canEditAi) {
        http_response_code(403);
        die('403 - Akses Ditolak: Anda tidak memiliki izin mengelola analisis AI atau koreksi Tahfidz.');
    }
    requireCsrf();

    $postAction   = (string)$_POST['action'];
    $postUnitId   = max(1, (int)($_POST['unit_id'] ?? 2));
    $postSemester = ((int)($_POST['semester'] ?? 1) === 2) ? 2 : 1;
    $postTahun    = trim((string)($_POST['tahun'] ?? ''));
    $postSort     = in_array($_POST['sort'] ?? '', ['grade_asc', 'highest', 'lowest'], true) ? $_POST['sort'] : 'grade_asc';
    $postTab      = in_array($_POST['tab'] ?? '', ['komparasi', 'antrean'], true) ? $_POST['tab'] : 'komparasi';
    $postStatus   = in_array($_POST['status_filter'] ?? '', ['all', 'pending', 'low_confidence', 'auto_ai', 'verified', 'rejected'], true)
        ? (string)$_POST['status_filter'] : 'all';
    $postTaId     = max(0, (int)($_POST['tahun_ajaran_id'] ?? 0));

    // Guard anti-IDOR lintas unit sekolah
    assertTahfidzPostUnitAccess($postUnitId, $accessCtx);

    if ($postAction === 'run_ai_batch') {
        $probeApi = fetchTahfidzAchievement($postUnitId, $postTaId > 0 ? $postTaId : null, 0, false);
        if (empty($probeApi['success'])) {
            $_SESSION['tahfidz_ai_flash'] = ['type' => 'error', 'text' => 'Tidak dapat mengambil daftar catatan AIS untuk dianalisis.'];
        } else {
            $effTaId = (int)($probeApi['meta']['selected_tahun_ajaran']['id'] ?? $postTaId);
            $aiPre   = applyTahfidzAiCacheToStudents($pdo, $probeApi, $postUnitId, $effTaId, 0.80);
            if (empty($aiPre['tables_ready'])) {
                $_SESSION['tahfidz_ai_flash'] = ['type' => 'error', 'text' => 'Tabel cache AI belum siap. Jalankan php database/migrate_penilaian_kinerja_tahfidz_ai.php.'];
            } elseif (empty($aiPre['pending_notes'])) {
                $_SESSION['tahfidz_ai_flash'] = ['type' => 'success', 'text' => 'Seluruh catatan bebas guru pada periode ini sudah tersimpan di cache AI.'];
            } else {
                $batchRes = extractTahfidzNotesWithAiBatch($pdo, $aiPre['pending_notes'], 20);
                if (!empty($batchRes['success'])) {
                    $_SESSION['tahfidz_ai_flash'] = [
                        'type' => 'success',
                        'text' => "AI berhasil menganalisis {$batchRes['processed']} catatan unik guru. Sisa antrean: {$batchRes['remaining']} catatan."
                    ];
                } elseif (($batchRes['processed'] ?? 0) > 0) {
                    $_SESSION['tahfidz_ai_flash'] = [
                        'type' => 'success',
                        'text' => "Tersimpan {$batchRes['processed']} catatan ke cache AI (sisa {$batchRes['remaining']}), namun sesi terhenti sementara: " . (string)($batchRes['error'] ?? '')
                    ];
                } else {
                    $_SESSION['tahfidz_ai_flash'] = [
                        'type' => 'error',
                        'text' => (string)($batchRes['error'] ?? 'Ekstraksi catatan AI gagal diproses.')
                    ];
                }
            }
        }
    } elseif ($postAction === 'review_ai_note') {
        $noteHash = trim((string)($_POST['note_hash'] ?? ''));
        $decision = (string)($_POST['decision'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $noteHash) || !in_array($decision, ['verified', 'rejected'], true)) {
            $_SESSION['tahfidz_ai_flash'] = ['type' => 'error', 'text' => 'Parameter verifikasi catatan AI tidak valid.'];
        } else {
            try {
                $stmtRev = $pdo->prepare("
                    UPDATE kinerja_tahfidz_ai_cache
                    SET review_status = :status, reviewed_by = :uid
                    WHERE note_hash = :hash
                ");
                $stmtRev->execute([
                    ':status' => $decision,
                    ':uid'    => currentUserId(),
                    ':hash'   => $noteHash,
                ]);
                $_SESSION['tahfidz_ai_flash'] = [
                    'type' => 'success',
                    'text' => $decision === 'verified'
                        ? 'Tafsir catatan AI telah disetujui dan masuk perhitungan komparasi AI.'
                        : 'Tafsir catatan AI ditolak dan dikecualikan dari perhitungan.'
                ];
            } catch (Throwable $e) {
                error_log('[tahfidz_ai.php review_ai_note] Error: ' . $e->getMessage());
                $_SESSION['tahfidz_ai_flash'] = ['type' => 'error', 'text' => 'Gagal memperbarui status verifikasi catatan AI.'];
            }
        }
    } elseif ($postAction === 'save_student_override') {
        $siswaId       = (int)($_POST['siswa_id'] ?? 0);
        $clearOverride = !empty($_POST['clear_override']);
        if ($siswaId <= 0 || $postTaId <= 0) {
            $_SESSION['tahfidz_ai_flash'] = ['type' => 'error', 'text' => 'Identitas santri atau tahun ajaran tidak valid.'];
        } elseif ($clearOverride) {
            try {
                $stmtDel = $pdo->prepare("
                    DELETE FROM kinerja_tahfidz_student_overrides
                    WHERE unit_id = :u AND tahun_ajaran_id = :ta AND siswa_id = :sid
                ");
                $stmtDel->execute([':u' => $postUnitId, ':ta' => $postTaId, ':sid' => $siswaId]);
                $_SESSION['tahfidz_ai_flash'] = ['type' => 'success', 'text' => 'Koreksi manual santri dihapus; perhitungan kembali mengikuti cache AI.'];
            } catch (Throwable $e) {
                error_log('[tahfidz_ai.php clear_override] Error: ' . $e->getMessage());
                $_SESSION['tahfidz_ai_flash'] = ['type' => 'error', 'text' => 'Gagal menghapus koreksi manual santri.'];
            }
        } else {
            $surahKey = trim((string)($_POST['surah_key'] ?? ''));
            $juzOnly  = (int)($_POST['juz_fallback'] ?? 0);
            $ayatEnd  = isset($_POST['ayat_end']) && (int)$_POST['ayat_end'] > 0 ? (int)$_POST['ayat_end'] : null;
            $reqMunaq = !empty($_POST['is_munaqasyah']);
            $reason   = mb_substr(trim(strip_tags((string)($_POST['reason'] ?? ''))), 0, 250);

            $conv = convertAiExtractionToRanks([
                'is_achievement' => 1,
                'surah_key'      => $surahKey !== '' ? $surahKey : null,
                'juz'            => ($surahKey === '' && $juzOnly > 0) ? $juzOnly : null,
                'ayat_end'       => $ayatEnd,
                'is_munaqasyah'  => $reqMunaq ? 1 : 0,
                'confidence'     => 1.0,
            ]);

            if ($conv['juz_stage'] <= 0 && $conv['juz30_rank'] <= 0) {
                $_SESSION['tahfidz_ai_flash'] = ['type' => 'error', 'text' => 'Pilih surat atau Juz capaian yang valid untuk koreksi manual.'];
            } else {
                $dispLabel = 'Koreksi: ' . ($conv['surah_label'] !== '' ? $conv['surah_label'] : 'Capaian Disesuaikan');
                $m30 = ($conv['juz'] === 30 && $reqMunaq) || $conv['juz_stage'] >= 2 ? 1 : 0;
                $m29 = ($conv['juz'] === 29 && $reqMunaq) || $conv['juz_stage'] >= 3 ? 1 : 0;
                $m28 = ($conv['juz'] === 28 && $reqMunaq) ? 1 : 0;

                try {
                    $stmtOv = $pdo->prepare("
                        INSERT INTO kinerja_tahfidz_student_overrides
                            (unit_id, tahun_ajaran_id, siswa_id, juz30_rank, juz29_rank, juz28_rank, juz_stage,
                             has_munaqasyah_juz30, has_munaqasyah_juz29, has_munaqasyah_juz28, display_label, reason, updated_by)
                        VALUES
                            (:u, :ta, :sid, :r30, :r29, :r28, :st, :m30, :m29, :m28, :lbl, :rsn, :uid)
                        ON DUPLICATE KEY UPDATE
                            juz30_rank = VALUES(juz30_rank), juz29_rank = VALUES(juz29_rank), juz28_rank = VALUES(juz28_rank),
                            juz_stage = VALUES(juz_stage), has_munaqasyah_juz30 = VALUES(has_munaqasyah_juz30),
                            has_munaqasyah_juz29 = VALUES(has_munaqasyah_juz29), has_munaqasyah_juz28 = VALUES(has_munaqasyah_juz28),
                            display_label = VALUES(display_label), reason = VALUES(reason), updated_by = VALUES(updated_by)
                    ");
                    $stmtOv->execute([
                        ':u' => $postUnitId, ':ta' => $postTaId, ':sid' => $siswaId,
                        ':r30' => $conv['juz30_rank'], ':r29' => $conv['juz29_rank'], ':r28' => $conv['juz28_rank'],
                        ':st' => $conv['juz_stage'], ':m30' => $m30, ':m29' => $m29, ':m28' => $m28,
                        ':lbl' => $dispLabel, ':rsn' => $reason, ':uid' => currentUserId(),
                    ]);
                    $_SESSION['tahfidz_ai_flash'] = ['type' => 'success', 'text' => "Koreksi manual {$dispLabel} berhasil disimpan."];
                } catch (Throwable $e) {
                    error_log('[tahfidz_ai.php save_student_override] Error: ' . $e->getMessage());
                    $_SESSION['tahfidz_ai_flash'] = ['type' => 'error', 'text' => 'Gagal menyimpan koreksi manual santri.'];
                }
            }
        }
    }

    $redirParams = [
        'unit_id'       => $postUnitId,
        'semester'      => $postSemester,
        'tab'           => $postTab,
        'sort'          => $postSort,
        'status_filter' => $postStatus,
    ];
    if ($postTahun !== '') {
        $redirParams['tahun'] = $postTahun;
    }
    header('Location: ' . BASE_URL . '/kinerja/tahfidz_ai.php?' . http_build_query($redirParams));
    exit;
}

$flashMessage = $_SESSION['tahfidz_ai_flash'] ?? null;
unset($_SESSION['tahfidz_ai_flash']);

// 2. Parameter GET Filter & Snapshot Context (Menggunakan shared helper RBAC + AIS)
$activeTab    = in_array($_GET['tab'] ?? '', ['komparasi', 'antrean'], true) ? (string)$_GET['tab'] : 'komparasi';
$statusFilter = in_array($_GET['status_filter'] ?? '', ['all', 'pending', 'low_confidence', 'auto_ai', 'verified', 'rejected'], true)
    ? (string)$_GET['status_filter'] : 'all';
$noteSearch   = trim((string)($_GET['q'] ?? ''));
$showAllNotes = (isset($_GET['show_all']) && $_GET['show_all'] === '1');

$ctx              = loadTahfidzFilterContext($pdo, $accessCtx, $_GET);
$unitId           = $ctx['unit_id'];
$sortMode         = $ctx['sort_mode'];
$availableUnits   = $ctx['available_units'];
$uniqueYearNames  = $ctx['unique_year_names'];
$selectedUnit     = $ctx['selected_unit'];
$selectedYear     = $ctx['selected_year'];
$selectedYearName = $ctx['selected_year_name'];
$selectedSemester = $ctx['selected_semester'];
$selectedTaId     = $ctx['selected_ta_id'];
$isSuccess        = $ctx['is_success'];
$apiError         = $ctx['api_error'];
$isFromCache      = $ctx['is_from_cache'];
$sourceVia        = $ctx['source_via'];

// 3. Hitung Dua Snapshot: Sebelum AI (Baku) vs Sesudah AI (+ Cache & Koreksi Manual)
$surahAiDict  = getTahfidzAiSurahDictionary();
$aiConfigured = (defined('TAHFIDZ_AI_ENABLED') && TAHFIDZ_AI_ENABLED && defined('TAHFIDZ_AI_API_KEY') && TAHFIDZ_AI_API_KEY !== '');
$aiProvider   = defined('TAHFIDZ_AI_PROVIDER') ? (string)TAHFIDZ_AI_PROVIDER : 'gemini';
$aiModel      = defined('TAHFIDZ_AI_MODEL') ? (string)TAHFIDZ_AI_MODEL : 'gemini-3.1-flash-lite';

$targetConfigs   = $isSuccess ? getTahfidzTargetConfigs($pdo, $unitId, $selectedSemester) : [];
$emptyHierarchy  = [
    'unit_summary' => [
        'total_students' => 0, 'achieved_students' => 0, 'percentage' => 0.0, 'avg_progress_pct' => 0.0,
        'total_paralels' => 0, 'total_rombels' => 0, 'best_paralel' => null, 'best_rombel' => null, 'lowest_rombel' => null,
    ],
    'paralels' => [],
    'teacher_stats' => [],
];

$apiBefore       = $ctx['api_response'];
$apiAfter        = $ctx['api_response'];
$aiSummary       = [
    'total_ambiguous' => 0, 'cached_count' => 0, 'pending_count' => 0,
    'pending_review_count' => 0, 'ai_upgraded_count' => 0, 'override_count' => 0, 'tables_ready' => true,
];
$beforeHierarchy = $emptyHierarchy;
$afterHierarchy  = $emptyHierarchy;
$auditCatalog    = ['items' => [], 'counts' => ['all' => 0, 'pending' => 0, 'low_confidence' => 0, 'auto_ai' => 0, 'verified' => 0, 'rejected' => 0], 'tables_ready' => true];

if ($isSuccess) {
    $beforeHierarchy = calculateTahfidzHierarchy($apiBefore, $targetConfigs, $sortMode);
    $aiSummary       = applyTahfidzAiCacheToStudents($pdo, $apiAfter, $unitId, $selectedTaId, 0.80);
    $afterHierarchy  = calculateTahfidzHierarchy($apiAfter, $targetConfigs, $sortMode);
    $auditCatalog    = fetchTahfidzAiNoteCatalogForAudit($pdo, $ctx['api_response'], 0.80);
}

$comparison     = buildTahfidzAiComparisonData($beforeHierarchy, $afterHierarchy);
$unitDelta      = $comparison['unit_delta'];
$paralelComp    = $comparison['paralels'];
$changedStuds   = $comparison['changed_students'];
$paralelsAfter  = $afterHierarchy['paralels'];
$auditCounts    = $auditCatalog['counts'];

// Filter item catatan untuk Tab 2 (status_filter & search query)
$filteredAuditItems = array_values(array_filter($auditCatalog['items'], static function ($item) use ($statusFilter, $noteSearch) {
    if ($statusFilter !== 'all' && ($item['audit_bucket'] ?? '') !== $statusFilter) {
        return false;
    }
    if ($noteSearch !== '') {
        $hay = strtolower(
            ($item['raw_text'] ?? '') . ' ' .
            ($item['kd_nama'] ?? '') . ' ' .
            ($item['materi'] ?? '') . ' ' .
            ($item['surah_label'] ?? '') . ' ' .
            implode(' ', array_map(static fn($sr) => ($sr['nama'] ?? '') . ' ' . ($sr['rombel_nama'] ?? ''), $item['student_samples'] ?? []))
        );
        if (strpos($hay, strtolower($noteSearch)) === false) {
            return false;
        }
    }
    return true;
}));

$totalFilteredNotes = count($filteredAuditItems);
$noteLimitCap       = $showAllNotes ? 1000 : 120;
$visibleAuditItems  = array_slice($filteredAuditItems, 0, $noteLimitCap);

// JSON data untuk modal Rincian Rombel & Koreksi Santri
$rombelJsonMap = [];
foreach ($paralelsAfter as $p) {
    foreach ($p['rombels'] as $r) {
        $key = $p['paralel_id'] . '_' . $r['rombel_id'] . '_' . preg_replace('/[^a-zA-Z0-9]/', '', (string)$r['rombel_code']);
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

$jsonFlags  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$refreshUrl = '?' . http_build_query(array_filter([
    'unit_id'       => $unitId,
    'tahun'         => $selectedYearName,
    'semester'      => $selectedSemester,
    'sort'          => $sortMode,
    'tab'           => $activeTab,
    'status_filter' => $statusFilter,
    'refresh'       => 1,
], static fn($v) => $v !== null && $v !== ''));

$mainTahfidzUrl = BASE_URL . '/kinerja/tahfidz.php?' . http_build_query(array_filter([
    'unit_id'  => $unitId,
    'tahun'    => $selectedYearName,
    'semester' => $selectedSemester,
    'sort'     => $sortMode,
], static fn($v) => $v !== null && $v !== ''));

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.kinerja-wrapper {
    width: 100%;
    max-width: 100%;
    padding: 0;
    margin: 0;
    box-sizing: border-box;
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
    gap: 16px;
}
.kinerja-header-info h1 {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    margin: 6px 0 4px 0;
    letter-spacing: -0.02em;
}
.kinerja-header-info p {
    font-size: 13.5px;
    color: #64748b;
    margin: 0;
}
.kinerja-badge-category {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #4338ca;
    background: #eef2ff;
    padding: 4px 10px;
    border-radius: 999px;
}
.kinerja-badge-cache {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #475569;
}
.header-action-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}
.btn-kinerja {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 15px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.16s ease;
}
.btn-kinerja-primary { background: #059669; color: #fff; }
.btn-kinerja-primary:hover { background: #047857; color: #fff; }
.btn-kinerja-indigo  { background: #4f46e5; color: #fff; }
.btn-kinerja-indigo:hover  { background: #4338ca; color: #fff; }
.btn-kinerja-outline { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
.btn-kinerja-outline:hover { background: #f8fafc; color: #0f172a; }

.notice-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 18px;
    font-size: 13.5px;
    line-height: 1.5;
    border: 1px solid transparent;
}
.notice-banner.success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
.notice-banner.error   { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
.notice-banner.info    { background: #eef2ff; border-color: #c7d2fe; color: #312e81; }
.notice-banner.warn    { background: #fffbeb; border-color: #fde68a; color: #92400e; }

.kinerja-filter-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px 20px;
    margin-bottom: 18px;
}
.kinerja-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: flex-end;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.filter-group label {
    font-size: 11.5px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.filter-control, .journal-search-input {
    border: 1px solid #cbd5e1;
    border-radius: 9px;
    padding: 8px 12px;
    font-size: 13.5px;
    color: #0f172a;
    background: #fff;
    min-width: 155px;
}

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
}
.kpi-label {
    font-size: 11.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 6px;
}
.kpi-value {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}
.kpi-sub {
    font-size: 12px;
    color: #64748b;
    margin-top: 6px;
}

.tahfidz-tab-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 18px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 12px;
}
.tab-switch-btn {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 10px 18px;
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #475569;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.16s ease;
}
.tab-switch-btn.active {
    background: #4f46e5;
    border-color: #4f46e5;
    color: #fff;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
}
.tab-pill-count {
    font-size: 11.5px;
    padding: 2px 8px;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.08);
}
.tab-switch-btn.active .tab-pill-count {
    background: rgba(255, 255, 255, 0.22);
    color: #fff;
}

.section-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 20px 24px;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(15, 23, 42, 0.03);
}
.section-card h3 {
    margin: 0 0 14px 0;
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}

.raw-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.raw-table th {
    text-align: left;
    padding: 10px 12px;
    background: #f8fafc;
    color: #475569;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1px solid #e2e8f0;
}
.raw-table td {
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: top;
}
.raw-table tr:hover td {
    background: #f8fafc;
}

.rombel-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(225px, 1fr));
    gap: 12px;
    margin-top: 12px;
}
.rombel-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px;
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: left;
}
.rombel-card:hover {
    border-color: #6366f1;
    background: #eef2ff;
}

.ai-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    margin-top: 3px;
}
.ai-chip-applied  { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; }
.ai-chip-verified { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.ai-chip-pending  { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
.ai-chip-manual   { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
.ai-chip-rejected { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

.status-chip {
    display: inline-flex;
    align-items: center;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}
.st-achieved { background: #dcfce7; color: #166534; }
.st-near     { background: #dbeafe; color: #1e40af; }
.st-progress { background: #fef3c7; color: #92400e; }
.st-empty    { background: #f1f5f9; color: #64748b; }

.delta-pos { color: #047857; font-weight: 800; }
.delta-zero { color: #64748b; font-weight: 600; }

.status-filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}
.status-pill-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #475569;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}
.status-pill-link.active {
    background: #1e293b;
    border-color: #1e293b;
    color: #fff;
}

/* Modal Anti-Glitch Override: No backdrop-filter, solid dark overlay */
.custom-modal-overlay.tahfidz-modal {
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    background: rgba(15, 23, 42, 0.72) !important;
    z-index: 9999 !important;
}
</style>

<div class="kinerja-wrapper">

    <!-- 1. HEADER CARD -->
    <div class="kinerja-header-card">
        <div class="kinerja-header-info">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span class="kinerja-badge-category">
                    <i class="fa-solid fa-robot"></i> PENILAIAN KINERJA &bull; ANALISIS AI TAHFIDZ
                </span>
                <?php if ($aiConfigured): ?>
                    <span class="kinerja-badge-cache" style="background:#ecfdf5; color:#047857;">
                        <i class="fa-solid fa-microchip"></i> Engine: <?= htmlspecialchars(strtoupper($aiProvider)) ?> (<?= htmlspecialchars($aiModel) ?>)
                    </span>
                <?php else: ?>
                    <span class="kinerja-badge-cache" style="background:#fffbeb; color:#b45309;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Mode Koreksi Manual (API Key belum aktif)
                    </span>
                <?php endif; ?>
                <?php if ($isSuccess): ?>
                    <span class="kinerja-badge-cache">
                        <i class="fa-solid <?= $isFromCache ? 'fa-bolt' : 'fa-circle-check' ?>"></i> <?= htmlspecialchars($sourceVia) ?>
                    </span>
                <?php endif; ?>
            </div>
            <h1>Analisis Catatan Bebas Guru (AI) &amp; Komparasi Capaian</h1>
            <p>
                Halaman khusus pembacaan catatan naratif guru oleh AI, verifikasi hasil ambigu, koreksi manual santri, serta perbandingan <strong>Sebelum AI vs Sesudah AI</strong> (terpisah dari laporan utama Capaian Tahfidz).
            </p>
        </div>

        <div class="header-action-group">
            <a href="<?= htmlspecialchars($mainTahfidzUrl) ?>" class="btn-kinerja btn-kinerja-outline">
                <i class="fa-solid fa-book-quran"></i> Buka Capaian Baku
            </a>
            <a href="<?= htmlspecialchars($refreshUrl) ?>" class="btn-kinerja btn-kinerja-primary">
                <i class="fa-solid fa-arrows-rotate"></i> Sinkronkan
            </a>
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
        <form method="GET" action="" class="kinerja-filter-form">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">

            <div class="filter-group">
                <label for="ai_unit"><i class="fa-solid fa-school"></i> Unit Sekolah</label>
                <select name="unit_id" id="ai_unit" class="filter-control" onchange="this.form.submit()">
                    <?php foreach ($availableUnits as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= ((int)$u['id'] === (int)$unitId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$u['nama']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="ai_tahun"><i class="fa-solid fa-calendar-days"></i> Tahun Ajaran</label>
                <select name="tahun" id="ai_tahun" class="filter-control" onchange="this.form.submit()">
                    <?php foreach ($uniqueYearNames as $yn): ?>
                        <option value="<?= htmlspecialchars($yn) ?>" <?= ($selectedYearName === $yn) ? 'selected' : '' ?>>
                            Tahun <?= htmlspecialchars($yn) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="ai_semester"><i class="fa-solid fa-clock-rotate-left"></i> Semester</label>
                <select name="semester" id="ai_semester" class="filter-control" onchange="this.form.submit()">
                    <option value="1" <?= ($selectedSemester === 1) ? 'selected' : '' ?>>Semester 1 (Ganjil)</option>
                    <option value="2" <?= ($selectedSemester === 2) ? 'selected' : '' ?>>Semester 2 (Genap)</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="ai_sort"><i class="fa-solid fa-arrow-down-wide-short"></i> Urutan Paralel</label>
                <select name="sort" id="ai_sort" class="filter-control" onchange="this.form.submit()">
                    <option value="grade_asc" <?= ($sortMode === 'grade_asc') ? 'selected' : '' ?>>Urut Kelas (1 &rarr; 6)</option>
                    <option value="highest"   <?= ($sortMode === 'highest')   ? 'selected' : '' ?>>Tertinggi &rarr; Terendah</option>
                    <option value="lowest"    <?= ($sortMode === 'lowest')    ? 'selected' : '' ?>>Terendah &rarr; Tertinggi</option>
                </select>
            </div>

            <div class="filter-group">
                <button type="submit" class="btn-kinerja btn-kinerja-indigo" style="height:39px;">
                    <i class="fa-solid fa-filter"></i> Terapkan Filter
                </button>
            </div>
        </form>
    </div>

    <?php if (!$isSuccess): ?>
        <div class="notice-banner error" style="padding:22px;">
            <i class="fa-solid fa-server" style="font-size:22px;"></i>
            <div>
                <strong>Gagal Mengambil Data Tahfidz dari Server AIS</strong><br>
                <span><?= htmlspecialchars((string)($apiError ?: 'Server AIS belum dapat dihubungi saat ini.')) ?></span>
            </div>
        </div>
    <?php else: ?>

        <?php if (empty($aiSummary['tables_ready'])): ?>
            <div class="notice-banner warn">
                <i class="fa-solid fa-database"></i>
                <div>
                    Tabel cache AI belum tersedia di database. Jalankan perintah <code>php database/migrate_penilaian_kinerja_tahfidz_ai.php</code>.
                </div>
            </div>
        <?php endif; ?>

        <!-- 3. KPI RINGKASAN KOMPARASI & STATUS AI -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Capaian Baku (Sebelum AI)</div>
                <div class="kpi-value"><?= number_format($unitDelta['before_pct'], 1) ?>%</div>
                <div class="kpi-sub">
                    <?= (int)$unitDelta['before_achieved'] ?> / <?= (int)$unitDelta['total_students'] ?> santri tuntas aturan baku AIS
                </div>
            </div>

            <div class="kpi-card" style="border-color:#c7d2fe; background:linear-gradient(135deg, #ffffff 0%, #f5f3ff 100%);">
                <div class="kpi-label" style="color:#4338ca;">Capaian Berbantuan AI (Sesudah)</div>
                <div class="kpi-value" style="color:#312e81;">
                    <?= number_format($unitDelta['after_pct'], 1) ?>%
                    <?php if ($unitDelta['delta_pct'] > 0): ?>
                        <span style="font-size:14px; color:#047857; font-weight:800;">(+<?= number_format($unitDelta['delta_pct'], 1) ?>%)</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-sub">
                    <?= (int)$unitDelta['after_achieved'] ?> / <?= (int)$unitDelta['total_students'] ?> santri
                    <?php if ($unitDelta['delta_achieved'] > 0): ?>
                        &bull; <strong style="color:#047857;">+<?= (int)$unitDelta['delta_achieved'] ?> santri naik tuntas</strong>
                    <?php endif; ?>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Antrean Catatan Bebas Guru</div>
                <div class="kpi-value"><?= (int)$aiSummary['pending_count'] ?></div>
                <div class="kpi-sub">
                    <?= (int)$aiSummary['cached_count'] ?> dari <?= (int)$aiSummary['total_ambiguous'] ?> catatan unik sudah di-cache AI
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Dampak AI &amp; Koreksi Manual</div>
                <div class="kpi-value"><?= (int)$aiSummary['ai_upgraded_count'] + (int)$aiSummary['override_count'] ?></div>
                <div class="kpi-sub">
                    <?= (int)$aiSummary['ai_upgraded_count'] ?> terbantu AI &bull; <?= (int)$aiSummary['override_count'] ?> koreksi manual
                    <?php if ($aiSummary['pending_review_count'] > 0): ?>
                        &bull; <strong style="color:#b45309;"><?= (int)$aiSummary['pending_review_count'] ?> butuh tinjauan</strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ACTION PANEL BATCH AI -->
        <?php if ($aiSummary['pending_count'] > 0 || !$aiConfigured): ?>
            <div class="notice-banner info" style="align-items:center; justify-content:space-between; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fa-solid fa-wand-magic-sparkles" style="font-size:18px; color:#4f46e5;"></i>
                    <div>
                        <strong>Antrean Analisis AI:</strong>
                        <?php if ($aiSummary['pending_count'] > 0): ?>
                            Terdapat <strong><?= (int)$aiSummary['pending_count'] ?> catatan unik guru</strong> di unit <?= htmlspecialchars((string)$selectedUnit['nama']) ?> yang belum dianalisis oleh AI.
                        <?php else: ?>
                            Semua catatan pada periode ini sudah masuk ke cache semantik AI.
                        <?php endif; ?>
                        <?php if (!$aiConfigured): ?>
                            <div style="font-size:12px; color:#475569; margin-top:2px;">
                                Pasang <code>TAHFIDZ_AI_API_KEY</code> di file <code>.env</code> server untuk menjalankan batch otomatis AI; verifikasi dan koreksi manual santri tetap dapat digunakan kapan saja.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($canEditAi && $aiSummary['pending_count'] > 0): ?>
                    <form method="POST" action="" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="run_ai_batch">
                        <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
                        <input type="hidden" name="tahun_ajaran_id" value="<?= (int)$selectedTaId ?>">
                        <input type="hidden" name="semester" value="<?= (int)$selectedSemester ?>">
                        <input type="hidden" name="tahun" value="<?= htmlspecialchars($selectedYearName) ?>">
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($sortMode) ?>">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                        <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                        <button type="submit" class="btn-kinerja btn-kinerja-indigo">
                            <i class="fa-solid fa-play"></i> Analisis Catatan AI (Batch 20)
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- 4. TAB NAVIGASI -->
        <?php
        $tabUrlBase = [
            'unit_id'  => $unitId,
            'tahun'    => $selectedYearName,
            'semester' => $selectedSemester,
            'sort'     => $sortMode,
        ];
        ?>
        <div class="tahfidz-tab-bar" role="tablist">
            <a href="?<?= htmlspecialchars(http_build_query($tabUrlBase + ['tab' => 'komparasi'])) ?>"
               class="tab-switch-btn <?= ($activeTab === 'komparasi') ? 'active' : '' ?>">
                <i class="fa-solid fa-code-compare"></i>
                <span>Komparasi Sebelum vs Sesudah AI</span>
                <span class="tab-pill-count"><?= number_format($unitDelta['after_pct'], 1) ?>%</span>
            </a>

            <a href="?<?= htmlspecialchars(http_build_query($tabUrlBase + ['tab' => 'antrean', 'status_filter' => $statusFilter])) ?>"
               class="tab-switch-btn <?= ($activeTab === 'antrean') ? 'active' : '' ?>">
                <i class="fa-solid fa-list-check"></i>
                <span>Antrean &amp; Audit Catatan Guru</span>
                <span class="tab-pill-count"><?= (int)$auditCounts['all'] ?> Catatan</span>
            </a>
        </div>

        <?php if ($activeTab === 'komparasi'): ?>
            <!-- =========================================================== -->
            <!-- TAB 1: KOMPARASI SEBELUM AI VS SESUDAH AI + HASIL ROMBEL    -->
            <!-- =========================================================== -->
            <div class="section-card">
                <h3><i class="fa-solid fa-table-columns" style="color:#4f46e5;"></i> Perbandingan Capaian Per Paralel (Baku AIS vs Berbantuan AI)</h3>
                <div style="overflow-x:auto;">
                    <table class="raw-table">
                        <thead>
                            <tr>
                                <th>Paralel / Kelas</th>
                                <th>Target Kurikulum</th>
                                <th>Total Santri</th>
                                <th>Sebelum AI (Baku AIS)</th>
                                <th>Sesudah AI (+ Koreksi)</th>
                                <th>Selisih Kenaikan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($paralelComp)): ?>
                                <tr><td colspan="6" style="text-align:center; color:#64748b;">Tidak ada data paralel untuk unit ini.</td></tr>
                            <?php else: ?>
                                <?php foreach ($paralelComp as $pc): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($pc['paralel_nama']) ?></strong></td>
                                        <td><?= htmlspecialchars($pc['target_label']) ?></td>
                                        <td><?= (int)$pc['total_students'] ?> santri</td>
                                        <td>
                                            <strong><?= number_format($pc['before_pct'], 1) ?>%</strong>
                                            <span style="color:#64748b;">(<?= (int)$pc['before_achieved'] ?>)</span>
                                        </td>
                                        <td>
                                            <strong style="color:#312e81;"><?= number_format($pc['after_pct'], 1) ?>%</strong>
                                            <span style="color:#4338ca;">(<?= (int)$pc['after_achieved'] ?>)</span>
                                        </td>
                                        <td>
                                            <?php if ($pc['delta_achieved'] > 0 || $pc['delta_pct'] > 0): ?>
                                                <span class="delta-pos">+<?= number_format($pc['delta_pct'], 1) ?>% (+<?= (int)$pc['delta_achieved'] ?> santri)</span>
                                            <?php else: ?>
                                                <span class="delta-zero">0% (Tetap)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- KARTU ROMBEL VERSI AI (KLIK UNTUK RINCIAN SANTRI & KOREKSI) -->
            <div class="section-card">
                <h3><i class="fa-solid fa-layer-group" style="color:#059669;"></i> Rincian Kelas / Rombel (Hasil Sesudah AI &amp; Koreksi Manual)</h3>
                <p style="font-size:13px; color:#64748b; margin-top:-6px; margin-bottom:14px;">
                    Klik salah satu kartu kelas untuk melihat santri yang terbantu pembacaan catatan AI maupun melakukan koreksi manual.
                </p>
                <?php foreach ($paralelsAfter as $p): ?>
                    <div style="margin-bottom:18px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:6px;">
                            <strong style="font-size:14.5px; color:#0f172a;"><?= htmlspecialchars($p['paralel_nama']) ?> &mdash; <?= number_format($p['percentage'], 1) ?>%</strong>
                            <span style="font-size:12px; color:#64748b;">Target: <?= htmlspecialchars($p['target_label']) ?></span>
                        </div>
                        <div class="rombel-grid">
                            <?php foreach ($p['rombels'] as $r):
                                $rKey = $p['paralel_id'] . '_' . $r['rombel_id'] . '_' . preg_replace('/[^a-zA-Z0-9]/', '', (string)$r['rombel_code']);
                            ?>
                                <button type="button" class="rombel-card" onclick="openAiRombelDetail('<?= htmlspecialchars($rKey, ENT_QUOTES) ?>')">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <strong style="font-size:14px; color:#0f172a;"><?= htmlspecialchars($r['rombel_nama']) ?></strong>
                                        <span style="font-weight:800; color:#4338ca;"><?= number_format($r['percentage'], 1) ?>%</span>
                                    </div>
                                    <div style="font-size:12px; color:#64748b; margin-top:4px;">
                                        <?= (int)$r['achieved_students'] ?> / <?= (int)$r['total_students'] ?> tuntas target
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- DAFTAR SANTRI TERDAMPAK AI / KOREKSI MANUAL -->
            <div class="section-card">
                <h3><i class="fa-solid fa-user-check" style="color:#4f46e5;"></i> Daftar Santri Terpengaruh AI / Koreksi Manual (<?= count($changedStuds) ?>)</h3>
                <?php if (empty($changedStuds)): ?>
                    <p style="font-size:13px; color:#64748b; margin:0;">
                        Belum ada santri yang capaiannya berubah oleh AI maupun koreksi manual di unit dan semester ini. Jalankan analisis AI pada tab <strong>Antrean &amp; Audit Catatan Guru</strong> atau buka kelas di atas untuk koreksi manual.
                    </p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="raw-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Santri &amp; Kelas</th>
                                    <th>Sebelum AI (Baku AIS)</th>
                                    <th>Sesudah AI / Koreksi</th>
                                    <th>Status</th>
                                    <?php if ($canEditAi): ?><th>Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($changedStuds, 0, 150) as $idx => $cs): ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($cs['nama']) ?></strong><br>
                                            <small style="color:#64748b;"><?= htmlspecialchars($cs['paralel_nama'] . ' • ' . $cs['rombel_nama']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($cs['before_display']) ?> (<?= number_format($cs['before_progress'], 1) ?>%)</td>
                                        <td>
                                            <strong><?= htmlspecialchars($cs['after_display']) ?></strong> (<?= number_format($cs['after_progress'], 1) ?>%)
                                            <?php if ($cs['ai_badge'] === 'manual_override'): ?>
                                                <div><span class="ai-chip ai-chip-manual"><i class="fa-solid fa-pen"></i> Koreksi Manual</span></div>
                                            <?php elseif ($cs['ai_badge'] === 'ai_verified'): ?>
                                                <div><span class="ai-chip ai-chip-verified"><i class="fa-solid fa-check-double"></i> AI Terverifikasi</span></div>
                                            <?php elseif ($cs['ai_badge'] === 'ai_applied'): ?>
                                                <div><span class="ai-chip ai-chip-applied"><i class="fa-solid fa-robot"></i> AI Otomatis</span></div>
                                            <?php elseif ($cs['ai_badge'] === 'ai_pending'): ?>
                                                <div><span class="ai-chip ai-chip-pending"><i class="fa-solid fa-circle-question"></i> Perlu Tinjau AI</span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($cs['flipped_achieved']): ?>
                                                <span class="status-chip st-achieved">Naik Jadi Tuntas</span>
                                            <?php elseif ($cs['after_achieved']): ?>
                                                <span class="status-chip st-achieved">Tuntas</span>
                                            <?php else: ?>
                                                <span class="status-chip st-progress">Proses</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($canEditAi): ?>
                                            <td>
                                                <button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:5px 10px; font-size:11.5px;"
                                                        onclick="openStudentOverrideModal(<?= (int)$cs['siswa_id'] ?>)">
                                                    Tinjau / Koreksi
                                                </button>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- =========================================================== -->
            <!-- TAB 2: ANTREAN & AUDIT CATATAN BEBAS GURU                   -->
            <!-- =========================================================== -->
            <div class="section-card">
                <h3><i class="fa-solid fa-clipboard-list" style="color:#4f46e5;"></i> Antrean &amp; Audit Cache Catatan Guru</h3>

                <div class="status-filter-pills">
                    <?php
                    $buckets = [
                        'all'            => 'Semua',
                        'pending'        => 'Belum Dianalisis',
                        'low_confidence' => 'Perlu Tinjau (<80%)',
                        'auto_ai'        => 'Otomatis AI',
                        'verified'       => 'Disetujui',
                        'rejected'       => 'Ditolak',
                    ];
                    foreach ($buckets as $bKey => $bLabel):
                        $bUrl = '?' . http_build_query(array_filter([
                            'unit_id'       => $unitId,
                            'tahun'         => $selectedYearName,
                            'semester'      => $selectedSemester,
                            'sort'          => $sortMode,
                            'tab'           => 'antrean',
                            'status_filter' => $bKey,
                            'q'             => $noteSearch !== '' ? $noteSearch : null,
                        ], static fn($v) => $v !== null && $v !== ''));
                    ?>
                        <a href="<?= htmlspecialchars($bUrl) ?>" class="status-pill-link <?= ($statusFilter === $bKey) ? 'active' : '' ?>">
                            <span><?= htmlspecialchars($bLabel) ?></span>
                            <span>(<?= (int)($auditCounts[$bKey] ?? 0) ?>)</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <form method="GET" action="" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                    <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
                    <input type="hidden" name="tahun" value="<?= htmlspecialchars($selectedYearName) ?>">
                    <input type="hidden" name="semester" value="<?= (int)$selectedSemester ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortMode) ?>">
                    <input type="hidden" name="tab" value="antrean">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                    <input type="text" name="q" value="<?= htmlspecialchars($noteSearch) ?>" class="journal-search-input" style="flex:1; min-width:240px;"
                           placeholder="Cari kata di catatan guru, nama santri, atau nama surat...">
                    <button type="submit" class="btn-kinerja btn-kinerja-outline">
                        <i class="fa-solid fa-magnifying-glass"></i> Cari Catatan
                    </button>
                </form>

                <div style="font-size:12px; color:#64748b; margin-bottom:10px;">
                    Menampilkan <?= count($visibleAuditItems) ?> dari <?= $totalFilteredNotes ?> catatan cocok.
                    <?php if ($totalFilteredNotes > $noteLimitCap && !$showAllNotes): ?>
                        <a href="?<?= htmlspecialchars(http_build_query($tabUrlBase + ['tab' => 'antrean', 'status_filter' => $statusFilter, 'q' => $noteSearch, 'show_all' => 1])) ?>"
                           style="color:#4f46e5; font-weight:700; margin-left:6px;">
                            Tampilkan semua (<?= $totalFilteredNotes ?>)
                        </a>
                    <?php endif; ?>
                </div>

                <div style="overflow-x:auto;">
                    <table class="raw-table">
                        <thead>
                            <tr>
                                <th style="width:42px;">No</th>
                                <th>Sumber &amp; Santri Terkait</th>
                                <th>Teks Catatan Asli Guru</th>
                                <th>Hasil Tafsir AI</th>
                                <th>Status</th>
                                <?php if ($canEditAi): ?><th>Aksi</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($visibleAuditItems)): ?>
                                <tr>
                                    <td colspan="<?= $canEditAi ? 6 : 5 ?>" style="text-align:center; color:#64748b;">
                                        Tidak ada catatan guru yang cocok pada filter status ini.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($visibleAuditItems as $idx => $it): ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td>
                                            <span class="kinerja-badge-cache" style="font-size:10.5px;"><?= htmlspecialchars($it['source_type']) ?></span>
                                            <div style="font-size:12px; color:#475569; margin-top:4px;">
                                                <strong><?= (int)$it['student_count'] ?> santri</strong>
                                                <?php if (!empty($it['student_samples'])): ?>
                                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                                                        <?php
                                                        $sampleTexts = array_map(
                                                            static fn($sr) => $sr['nama'] . ($sr['rombel_nama'] !== '' ? ' (' . $sr['rombel_nama'] . ')' : ''),
                                                            $it['student_samples']
                                                        );
                                                        echo htmlspecialchars(implode(', ', $sampleTexts));
                                                        ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="max-width:340px;">
                                            <?php if ($it['kd_nama'] !== '' || $it['materi'] !== ''): ?>
                                                <div style="font-size:11.5px; color:#64748b; margin-bottom:3px;">
                                                    <?= htmlspecialchars(trim($it['kd_nama'] . ' ' . $it['materi'])) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="font-weight:600; color:#0f172a; word-break:break-word;">
                                                &ldquo;<?= htmlspecialchars($it['raw_text']) ?>&rdquo;
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!$it['in_cache']): ?>
                                                <span style="color:#64748b; font-style:italic;">Menunggu proses AI...</span>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($it['surah_label']) ?></strong>
                                                <span style="color:#475569;">(<?= (int)$it['confidence_pct'] ?>%)</span>
                                                <?php if ($it['ai_reason'] !== ''): ?>
                                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px;"><?= htmlspecialchars($it['ai_reason']) ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($it['audit_bucket'] === 'verified'): ?>
                                                <span class="ai-chip ai-chip-verified">Disetujui</span>
                                            <?php elseif ($it['audit_bucket'] === 'rejected'): ?>
                                                <span class="ai-chip ai-chip-rejected">Ditolak</span>
                                            <?php elseif ($it['audit_bucket'] === 'low_confidence'): ?>
                                                <span class="ai-chip ai-chip-pending">Perlu Tinjau</span>
                                            <?php elseif ($it['audit_bucket'] === 'auto_ai'): ?>
                                                <span class="ai-chip ai-chip-applied">Otomatis AI</span>
                                            <?php else: ?>
                                                <span class="status-chip st-empty">Antrean</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($canEditAi): ?>
                                            <td>
                                                <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                                    <?php if ($it['in_cache']): ?>
                                                        <form method="POST" action="" style="margin:0;">
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="action" value="review_ai_note">
                                                            <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
                                                            <input type="hidden" name="tahun_ajaran_id" value="<?= (int)$selectedTaId ?>">
                                                            <input type="hidden" name="semester" value="<?= (int)$selectedSemester ?>">
                                                            <input type="hidden" name="tahun" value="<?= htmlspecialchars($selectedYearName) ?>">
                                                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortMode) ?>">
                                                            <input type="hidden" name="tab" value="antrean">
                                                            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                                                            <input type="hidden" name="note_hash" value="<?= htmlspecialchars($it['note_hash']) ?>">
                                                            <?php if ($it['review_status'] !== 'verified'): ?>
                                                                <button type="submit" name="decision" value="verified" class="btn-kinerja btn-kinerja-primary" style="padding:4px 9px; font-size:11px;">Setujui</button>
                                                            <?php endif; ?>
                                                            <?php if ($it['review_status'] !== 'rejected'): ?>
                                                                <button type="submit" name="decision" value="rejected" class="btn-kinerja btn-kinerja-outline" style="padding:4px 9px; font-size:11px; color:#be123c; border-color:#fecdd3;">Tolak</button>
                                                            <?php endif; ?>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (!empty($it['student_samples'][0]['siswa_id'])): ?>
                                                        <button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:4px 9px; font-size:11px;"
                                                                onclick="openStudentOverrideModal(<?= (int)$it['student_samples'][0]['siswa_id'] ?>)">
                                                            Koreksi Santri
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ======================================================================= -->
<!-- MODAL 1: RINCIAN SANTRI PER ROMBEL (DENGAN BADGE AI + AKSI KOREKSI)     -->
<!-- ======================================================================= -->
<div id="rombelAiDetailModal" class="custom-modal-overlay tahfidz-modal" onclick="if(event.target===this) closeModal('rombelAiDetailModal')">
    <div class="custom-modal-box" style="max-width: 940px;">
        <div class="custom-modal-header">
            <h4 id="rombelAiModalTitle">Rincian Capaian Santri (Sesudah AI)</h4>
            <button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:6px 12px;" onclick="closeModal('rombelAiDetailModal')">Tutup</button>
        </div>
        <div class="custom-modal-body">
            <div id="rombelAiModalMeta" style="margin-bottom:14px; font-size:13px; color:#475569; display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;"></div>
            <input type="text" id="rombelAiStudentSearch" class="journal-search-input" style="width:100%; box-sizing:border-box; margin-bottom:12px;" placeholder="Cari nama santri, pembimbing, atau catatan AI..." oninput="renderFilteredAiRombelStudents()">
            <div style="overflow-x:auto;">
                <table class="raw-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Santri</th>
                            <th>Pembimbing Halaqah</th>
                            <th>Capaian Terbaca (+ AI / Koreksi)</th>
                            <th>Progres</th>
                            <th>Status</th>
                            <?php if ($canEditAi): ?><th>Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="rombelAiModalTbody"></tbody>
                </table>
            </div>
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn-kinerja btn-kinerja-outline" onclick="closeModal('rombelAiDetailModal')">Tutup</button>
        </div>
    </div>
</div>

<?php if ($canEditAi): ?>
<!-- ======================================================================= -->
<!-- MODAL 2: VERIFIKASI CATATAN AI / KOREKSI MANUAL SANTRI                  -->
<!-- ======================================================================= -->
<div id="studentOverrideModal" class="custom-modal-overlay tahfidz-modal" onclick="if(event.target===this) closeModal('studentOverrideModal')">
    <div class="custom-modal-box" style="max-width: 620px;">
        <div class="custom-modal-header">
            <h4 id="ovModalTitle">Tinjau AI / Koreksi Manual Santri</h4>
            <button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:6px 12px;" onclick="closeModal('studentOverrideModal')">Tutup</button>
        </div>
        <div class="custom-modal-body">
            <div id="ovAiBox" style="display:none; background:#f8fafc; border:1px solid #cbd5e1; border-radius:10px; padding:12px 14px; margin-bottom:16px;">
                <div style="font-size:12px; font-weight:800; color:#4338ca; margin-bottom:6px;">
                    <i class="fa-solid fa-robot"></i> Pembacaan Catatan Guru oleh AI
                </div>
                <div id="ovAiDetails" style="font-size:12.5px; color:#334155; line-height:1.5; margin-bottom:10px;"></div>
                <form method="POST" action="" style="display:flex; gap:8px; flex-wrap:wrap; margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="review_ai_note">
                    <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
                    <input type="hidden" name="tahun_ajaran_id" value="<?= (int)$selectedTaId ?>">
                    <input type="hidden" name="semester" value="<?= (int)$selectedSemester ?>">
                    <input type="hidden" name="tahun" value="<?= htmlspecialchars($selectedYearName) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortMode) ?>">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                    <input type="hidden" name="note_hash" id="ovNoteHash" value="">
                    <button type="submit" name="decision" value="verified" class="btn-kinerja btn-kinerja-primary" style="padding:6px 12px; font-size:12px;">
                        <i class="fa-solid fa-check"></i> Setujui Tafsir AI
                    </button>
                    <button type="submit" name="decision" value="rejected" class="btn-kinerja btn-kinerja-outline" style="padding:6px 12px; font-size:12px; color:#be123c; border-color:#fecdd3;">
                        <i class="fa-solid fa-xmark"></i> Tolak Tafsir Ini
                    </button>
                </form>
            </div>

            <form method="POST" action="" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_student_override">
                <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
                <input type="hidden" name="tahun_ajaran_id" value="<?= (int)$selectedTaId ?>">
                <input type="hidden" name="semester" value="<?= (int)$selectedSemester ?>">
                <input type="hidden" name="tahun" value="<?= htmlspecialchars($selectedYearName) ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sortMode) ?>">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">
                <input type="hidden" name="siswa_id" id="ovSiswaId" value="">

                <div style="margin-bottom:12px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:5px;">Pilih Surat Terakhir yang Benar-benar Tuntas (Juz 30 / 29 / 28)</label>
                    <select name="surah_key" class="filter-control" style="width:100%;">
                        <option value="">-- Atau pilih Juz Lanjutan di bawah (Juz 1..27) --</option>
                        <?php foreach ($surahAiDict['surahs'] as $sKey => $sInfo): ?>
                            <option value="<?= htmlspecialchars($sKey) ?>">
                                Juz <?= (int)$sInfo['juz'] ?> &mdash; <?= htmlspecialchars($sInfo['label']) ?> (<?= (int)$sInfo['ayats'] ?> Ayat)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:5px;">Juz Lanjutan (Jika Juz 1–27)</label>
                        <select name="juz_fallback" class="filter-control" style="width:100%;">
                            <option value="0">-- Tidak Ada --</option>
                            <?php for ($j = 1; $j <= 27; $j++): ?>
                                <option value="<?= $j ?>">Tuntas Juz <?= $j ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:5px;">Ayat Terakhir (Opsional)</label>
                        <input type="number" name="ayat_end" min="1" max="286" class="journal-search-input" style="width:100%; box-sizing:border-box;" placeholder="Kosong = tuntas 1 surat">
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:inline-flex; align-items:center; gap:8px; font-size:12.5px; font-weight:600; color:#334155;">
                        <input type="checkbox" name="is_munaqasyah" value="1"> Sudah Lulus Munaqasyah Juz tersebut
                    </label>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:5px;">Alasan / Catatan Koreksi Admin</label>
                    <input type="text" name="reason" id="ovReasonInput" maxlength="250" class="journal-search-input" style="width:100%; box-sizing:border-box;" placeholder="Mis. Catatan guru di buku mutaba'ah / bukti tes">
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; border-top:1px solid #e2e8f0; padding-top:14px;">
                    <button type="submit" name="clear_override" value="1" id="ovClearBtn" class="btn-kinerja btn-kinerja-outline" style="color:#be123c; border-color:#fecdd3; display:none;">
                        <i class="fa-solid fa-trash-can"></i> Hapus Koreksi Manual
                    </button>
                    <div style="display:flex; gap:8px; margin-left:auto;">
                        <button type="button" class="btn-kinerja btn-kinerja-outline" onclick="closeModal('studentOverrideModal')">Batal</button>
                        <button type="submit" class="btn-kinerja btn-kinerja-primary">
                            <i class="fa-solid fa-floppy-disk"></i> Simpan Koreksi Manual
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const AI_ROMBEL_DATA = <?= json_encode($rombelJsonMap, $jsonFlags) ?>;
const CAN_EDIT_AI    = <?= $canEditAi ? 'true' : 'false' ?>;
const STUDENT_INDEX  = {};
Object.values(AI_ROMBEL_DATA).forEach(r => {
    (r.students || []).forEach(s => { STUDENT_INDEX[String(s.siswa_id)] = s; });
});

let activeAiRombelKey = null;

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = (str === null || str === undefined) ? '' : String(str);
    return div.innerHTML;
}

function openTahfidzAiModal(id) {
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

function statusChipHtml(statusKey, statusLabel) {
    const clsMap = { achieved: 'st-achieved', near: 'st-near', progress: 'st-progress', empty: 'st-empty' };
    return `<span class="status-chip ${clsMap[statusKey] || 'st-empty'}">${escHtml(statusLabel)}</span>`;
}

function formatStudentActualHtml(s) {
    let html = `<div>${escHtml(s.actual_display)}</div>`;
    const ev = s.ai_evidence || null;
    if (s.ai_badge === 'manual_override') {
        const rs = (s.manual_override && s.manual_override.reason) ? s.manual_override.reason : 'Koreksi Manual Admin';
        html += `<div><span class="ai-chip ai-chip-manual" title="${escHtml(rs)}"><i class="fa-solid fa-pen"></i> Koreksi Manual</span></div>`;
    } else if (s.ai_badge === 'ai_verified' && ev) {
        html += `<div><span class="ai-chip ai-chip-verified" title="${escHtml(ev.raw_text)}"><i class="fa-solid fa-check-double"></i> Terverifikasi (${escHtml(ev.confidence)}%)</span></div>`;
    } else if (s.ai_badge === 'ai_applied' && ev) {
        html += `<div><span class="ai-chip ai-chip-applied" title="${escHtml(ev.raw_text)} (${escHtml(ev.ai_reason)})"><i class="fa-solid fa-robot"></i> AI Catatan (${escHtml(ev.confidence)}%)</span></div>`;
    } else if (s.ai_badge === 'ai_pending' && ev) {
        html += `<div><span class="ai-chip ai-chip-pending" title="${escHtml(ev.raw_text)}"><i class="fa-solid fa-circle-question"></i> Perlu Tinjau AI (${escHtml(ev.confidence)}%)</span></div>`;
    }
    return html;
}

function openAiRombelDetail(key) {
    const data = AI_ROMBEL_DATA[key];
    if (!data) return;
    activeAiRombelKey = key;
    document.getElementById('rombelAiModalTitle').textContent =
        `${data.paralel_nama} — ${data.rombel_nama} (Sesudah AI: ${Number(data.percentage).toFixed(1)}%)`;
    document.getElementById('rombelAiModalMeta').innerHTML =
        `<span>Target Kurikulum: <strong>${escHtml(data.target_label)}</strong></span>` +
        `<span>Tuntas (Versi AI): <strong>${data.achieved_students}/${data.total_students} Santri</strong></span>`;
    document.getElementById('rombelAiStudentSearch').value = '';
    renderFilteredAiRombelStudents();
    openTahfidzAiModal('rombelAiDetailModal');
}

function renderFilteredAiRombelStudents() {
    const data = AI_ROMBEL_DATA[activeAiRombelKey];
    if (!data) return;
    const q = (document.getElementById('rombelAiStudentSearch').value || '').trim().toLowerCase();
    const tbody = document.getElementById('rombelAiModalTbody');
    const colSpan = CAN_EDIT_AI ? 7 : 6;
    const filtered = (data.students || []).filter(s => {
        if (!q) return true;
        return (s.nama || '').toLowerCase().includes(q) ||
               (s.pengajar_nama || '').toLowerCase().includes(q) ||
               (s.actual_display || '').toLowerCase().includes(q);
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center; color:#64748b;">Tidak ada santri yang cocok.</td></tr>`;
        return;
    }

    tbody.innerHTML = filtered.map((s, i) => `
        <tr>
            <td>${i + 1}</td>
            <td><strong>${escHtml(s.nama)}</strong></td>
            <td>${escHtml(s.pengajar_nama)}</td>
            <td>${formatStudentActualHtml(s)}</td>
            <td><strong>${Number(s.progress_pct).toFixed(1)}%</strong></td>
            <td>${statusChipHtml(s.status_key, s.status_label)}</td>
            ${CAN_EDIT_AI ? `<td><button type="button" class="btn-kinerja btn-kinerja-outline" style="padding:4px 9px; font-size:11px;" onclick="openStudentOverrideModal(${Number(s.siswa_id)})">Koreksi</button></td>` : ''}
        </tr>
    `).join('');
}

function openStudentOverrideModal(siswaId) {
    if (!CAN_EDIT_AI) return;
    const s = STUDENT_INDEX[String(siswaId)];
    if (!s) return;
    document.getElementById('ovModalTitle').textContent = `Tinjau AI / Koreksi: ${s.nama}`;
    document.getElementById('ovSiswaId').value = String(s.siswa_id);
    document.getElementById('ovReasonInput').value = (s.manual_override && s.manual_override.reason) ? s.manual_override.reason : '';
    document.getElementById('ovClearBtn').style.display = (s.ai_badge === 'manual_override') ? 'inline-flex' : 'none';

    const aiBox = document.getElementById('ovAiBox');
    if (s.ai_evidence) {
        aiBox.style.display = 'block';
        document.getElementById('ovNoteHash').value = s.ai_evidence.note_hash || '';
        document.getElementById('ovAiDetails').innerHTML =
            `<div><strong>KD/Sumber:</strong> ${escHtml(s.ai_evidence.kd_nama || '-')} (${escHtml(s.ai_evidence.source_type)})</div>` +
            `<div><strong>Catatan Guru:</strong> &ldquo;${escHtml(s.ai_evidence.raw_text)}&rdquo;</div>` +
            `<div><strong>Hasil AI:</strong> ${escHtml(s.ai_evidence.surah_label)} (${escHtml(s.ai_evidence.confidence)}%) &mdash; ${escHtml(s.ai_evidence.ai_reason)}</div>`;
    } else {
        aiBox.style.display = 'none';
    }
    openTahfidzAiModal('studentOverrideModal');
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

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.tahfidz-modal').forEach((modalEl) => {
        if (modalEl.parentElement !== document.body) {
            document.body.appendChild(modalEl);
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

