<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('supervisi_dashboard');

$pageTitle = 'Dashboard Supervisi Kinerja Guru';
$currentUserId = currentUserId();
$currentRole = currentRole();

// =========================================================================
// QUERY STATISTIK KPI UTAMA
// =========================================================================
// 1. Total Dokumen Supervisi
$stmtTotal = $pdo->query("SELECT COUNT(*) FROM supervision_sessions");
$totalSupervisi = (int)$stmtTotal->fetchColumn();

// 2. Rata-rata Skor Sekolah
$stmtAvg = $pdo->query("SELECT COALESCE(AVG(percentage_score), 0) FROM supervision_sessions");
$avgScore = round((float)$stmtAvg->fetchColumn(), 1);

// 3. Guru Outstanding
$stmtOut = $pdo->query("SELECT COUNT(*) FROM supervision_sessions WHERE predicate_name = 'Outstanding'");
$totalOutstanding = (int)$stmtOut->fetchColumn();

// 4. Guru Need Improvement
$stmtNeed = $pdo->query("SELECT COUNT(*) FROM supervision_sessions WHERE predicate_name = 'Need Improvement'");
$totalNeedImprovement = (int)$stmtNeed->fetchColumn();

// =========================================================================
// QUERY CHART DISTRIBUSI PREDIKAT (DONUT CHART)
// =========================================================================
$stmtPredDist = $pdo->query("
    SELECT predicate_name, COUNT(*) as count
    FROM supervision_sessions
    GROUP BY predicate_name
");
$rawPredDist = $stmtPredDist->fetchAll(PDO::FETCH_KEY_PAIR);

$predLabels = ['Outstanding', 'Excellent', 'Good', 'Need Improvement'];
$predData = [
    $rawPredDist['Outstanding'] ?? 0,
    $rawPredDist['Excellent'] ?? 0,
    $rawPredDist['Good'] ?? 0,
    $rawPredDist['Need Improvement'] ?? 0
];
$predColors = ['#8b5cf6', '#10b981', '#0ea5e9', '#ef4444'];

// =========================================================================
// QUERY CAPAIAN RERATA PER KOMPONEN INSTRUMEN (BAR CHART)
// =========================================================================
$stmtComp = $pdo->query("
    SELECT sc.category_name, 
           COALESCE(AVG(scores_calc.user_cat_score), 0) as avg_score,
           COALESCE(MAX(scores_calc.max_cat_score), 40) as max_score
    FROM supervision_categories sc
    LEFT JOIN (
        SELECT ss.id as session_id, sri.category_id,
               SUM(ssc.score * sri.weight) as user_cat_score,
               SUM(sri.max_score * sri.weight) as max_cat_score
        FROM supervision_scores ssc
        JOIN supervision_sessions ss ON ss.id = ssc.session_id
        JOIN supervision_rubric_items sri ON sri.id = ssc.item_id
        GROUP BY ss.id, sri.category_id
    ) scores_calc ON scores_calc.category_id = sc.id
    WHERE sc.is_active = 1 AND sc.type_id = 1
    GROUP BY sc.id, sc.category_name
    ORDER BY sc.sort_order ASC
");
$compRows = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

$barLabels = [];
$barData = [];
foreach ($compRows as $cr) {
    $cleanName = preg_replace('/^[0-9]+\.\s*/', '', $cr['category_name']);
    $barLabels[] = $cleanName . ' (Max ' . round($cr['max_score']) . ')';
    $barData[] = round((float)$cr['avg_score'], 1);
}

// Fallback jika belum ada sesi supervisi
if (empty($barLabels)) {
    $barLabels = ['Pembelajaran Mendalam (Max 40)', 'Integrasi KURMAA (Max 32)', 'Keterlibatan Siswa (Max 16)'];
    $barData = [0, 0, 0];
}

// =========================================================================
// QUERY DOKUMEN SUPERVISI TERBARU
// =========================================================================
$stmtRecent = $pdo->query("
    SELECT ss.*, st.name as type_name, s.name as teacher_name, u.name as observer_name
    FROM supervision_sessions ss
    JOIN supervision_types st ON st.id = ss.type_id
    JOIN staff s ON s.id = ss.teacher_id
    JOIN users u ON u.id = ss.observer_id
    ORDER BY ss.observation_date DESC, ss.id DESC
    LIMIT 5
");
$recentSupervisions = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

function getPredBadgeColor($name) {
    switch (strtolower($name)) {
        case 'outstanding': return '#8b5cf6';
        case 'excellent': return '#10b981';
        case 'good': return '#0ea5e9';
        default: return '#ef4444';
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="supervisi-fullwidth" style="width: 100%; max-width: 100%;">
    <!-- HEADER MODUL -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <span style="background: #e0e7ff; color: #4338ca; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 6px; text-transform: uppercase;">
                    <i class="fa-solid fa-chart-line"></i> Analytics & Overview
                </span>
                <span style="color: #94a3b8; font-size: 13px;">&bull; SMP An Nahl Islamic School &bull; Integrasi KURMAA</span>
            </div>
            <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #0f172a;">
                Dashboard Supervisi Guru & Teman Sejawat
            </h1>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 14px;">
                Pemantauan mutu evaluasi pembelajaran, integrasi nilai adab islami, dan capaian kinerja pendidik.
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="peer.php" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);">
                <i class="fa-solid fa-user-pen"></i> + Nilai Sejawat
            </a>
            <a href="principal.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; background: #9333ea; border-color: #9333ea; color: white;">
                <i class="fa-solid fa-school"></i> + Nilai Kepsek
            </a>
            <a href="schedules.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600;">
                <i class="fa-solid fa-calendar-days"></i> Jadwal
            </a>
            <a href="reports.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600;">
                <i class="fa-solid fa-table-list"></i> Rekap
            </a>
        </div>
    </div>

<style>
.kpi-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 24px -6px rgba(0, 0, 0, 0.08) !important;
}
.chart-box {
    transition: box-shadow 0.2s ease;
}
.chart-box:hover {
    box-shadow: 0 12px 24px -6px rgba(0, 0, 0, 0.08) !important;
}
</style>

    <!-- 4 KARTU METRIK UTAMA (KPI) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-bottom: 28px;">
        <!-- KARTU 1: TOTAL SUPERVISI -->
        <div class="kpi-card" style="background: white; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">TOTAL SUPERVISI</div>
                <div style="font-size: 32px; font-weight: 800; color: #0f172a; margin: 4px 0;"><?= $totalSupervisi ?></div>
                <div style="font-size: 12px; color: #64748b;">Dokumen Tersimpan</div>
            </div>
            <div style="width: 52px; height: 52px; border-radius: 12px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 22px;">
                <i class="fa-solid fa-folder"></i>
            </div>
        </div>

        <!-- KARTU 2: RATA-RATA SKOR SEKOLAH -->
        <div class="kpi-card" style="background: white; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">RATA-RATA SKOR SEKOLAH</div>
                <div style="font-size: 32px; font-weight: 800; color: #0284c7; margin: 4px 0;"><?= $avgScore ?></div>
                <div style="font-size: 12px; color: #64748b;">Skor Evaluasi Pembelajaran</div>
            </div>
            <div style="width: 52px; height: 52px; border-radius: 12px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 22px;">
                <i class="fa-solid fa-chart-line"></i>
            </div>
        </div>

        <!-- KARTU 3: GURU OUTSTANDING -->
        <div class="kpi-card" style="background: white; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">GURU OUTSTANDING</div>
                <div style="font-size: 32px; font-weight: 800; color: #8b5cf6; margin: 4px 0;"><?= $totalOutstanding ?></div>
                <div style="font-size: 12px; color: #64748b;">Capaian Unggul</div>
            </div>
            <div style="width: 52px; height: 52px; border-radius: 12px; background: #f5f3ff; color: #8b5cf6; display: flex; align-items: center; justify-content: center; font-size: 22px;">
                <i class="fa-solid fa-star"></i>
            </div>
        </div>

        <!-- KARTU 4: NEED IMPROVEMENT -->
        <div class="kpi-card" style="background: white; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">NEED IMPROVEMENT</div>
                <div style="font-size: 32px; font-weight: 800; color: #ef4444; margin: 4px 0;"><?= $totalNeedImprovement ?></div>
                <div style="font-size: 12px; color: #64748b;">Perlu Pendampingan</div>
            </div>
            <div style="width: 52px; height: 52px; border-radius: 12px; background: #fef2f2; color: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 22px;">
                <i class="fa-solid fa-bullseye"></i>
            </div>
        </div>
    </div>

    <!-- AREA GRAFIK DUA KOLOM -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(480px, 1fr)); gap: 24px; margin-bottom: 28px;">
        <!-- GRAFIK 1: DONUT CHART DISTRIBUSI PREDIKAT -->
        <div class="chart-box" style="background: white; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                    Distribusi Predikat Supervisi Guru
                </h3>
                <span style="font-size: 12px; color: #64748b;">Berdasarkan Skor Akhir</span>
            </div>
            <div style="height: 280px; position: relative;">
                <canvas id="donutPredicateChart"></canvas>
            </div>
        </div>

        <!-- GRAFIK 2: BAR CHART CAPAIAN RERATA PER KOMPONEN -->
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                    Capaian Rerata Per Komponen Instrumen
                </h3>
                <span style="font-size: 12px; color: #64748b;">Observasi Sejawat</span>
            </div>
            <div style="height: 280px; position: relative;">
                <canvas id="barComponentChart"></canvas>
            </div>
        </div>
    </div>

    <!-- TABEL 5 DOKUMEN SUPERVISI TERBARU -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                Aktivitas Supervisi Guru Terbaru
            </h3>
            <a href="reports.php" style="font-size: 13px; font-weight: 700; color: #4f46e5; text-decoration: none;">
                Lihat Semua Rekap &rarr;
            </a>
        </div>

        <?php if (empty($recentSupervisions)): ?>
            <div style="padding: 24px; text-align: center; color: #94a3b8; font-size: 13.5px;">
                Belum ada data supervisi yang tercatat.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                    <thead>
                        <tr style="border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 11.5px; text-transform: uppercase;">
                            <th style="padding: 10px 14px; text-align: left;">Guru yang Dinilai</th>
                            <th style="padding: 10px 14px; text-align: left;">Jenis & Mapel</th>
                            <th style="padding: 10px 14px; text-align: left;">Observer</th>
                            <th style="padding: 10px 14px; text-align: center;">Tanggal</th>
                            <th style="padding: 10px 14px; text-align: center;">Skor</th>
                            <th style="padding: 10px 14px; text-align: center;">Predikat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSupervisions as $rs): 
                            $bColor = getPredBadgeColor($rs['predicate_name']);
                        ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px 14px; font-weight: 700; color: #0f172a;">
                                    <?= htmlspecialchars($rs['teacher_name']) ?>
                                </td>
                                <td style="padding: 12px 14px; color: #334155;">
                                    <strong><?= htmlspecialchars($rs['subject_name']) ?></strong> (<?= htmlspecialchars($rs['class_name']) ?>)
                                    <div style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($rs['type_name']) ?></div>
                                </td>
                                <td style="padding: 12px 14px; color: #475569;">
                                    <?= htmlspecialchars($rs['observer_name']) ?>
                                </td>
                                <td style="padding: 12px 14px; text-align: center; color: #64748b; font-size: 12.5px;">
                                    <?= htmlspecialchars($rs['observation_date']) ?>
                                </td>
                                <td style="padding: 12px 14px; text-align: center; font-weight: 800; color: #0f172a;">
                                    <?= number_format($rs['total_score'], 0) ?> <span style="font-size: 11px; color: #94a3b8;">/ <?= number_format($rs['max_possible_score'], 0) ?></span>
                                    <div style="font-size: 11px; color: #4f46e5;"><?= number_format($rs['percentage_score'], 1) ?>%</div>
                                </td>
                                <td style="padding: 12px 14px; text-align: center;">
                                    <span style="background: <?= $bColor ?>15; color: <?= $bColor ?>; border: 1px solid <?= $bColor ?>40; font-weight: 800; font-size: 11.5px; padding: 3px 10px; border-radius: 20px;">
                                        <?= htmlspecialchars($rs['predicate_name']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Donut Chart Distribusi Predikat
    const donutCtx = document.getElementById('donutPredicateChart');
    if (donutCtx) {
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($predLabels) ?>,
                datasets: [{
                    data: <?= json_encode($predData) ?>,
                    backgroundColor: <?= json_encode($predColors) ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            font: { size: 12, weight: 'bold' },
                            padding: 16
                        }
                    }
                },
                cutout: '65%'
            }
        });
    }

    // 2. Bar Chart Capaian Rerata Per Komponen
    const barCtx = document.getElementById('barComponentChart');
    if (barCtx) {
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($barLabels) ?>,
                datasets: [{
                    label: 'Skor Rerata Guru',
                    data: <?= json_encode($barData) ?>,
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.85)',
                        'rgba(16, 185, 129, 0.85)',
                        'rgba(168, 85, 247, 0.85)',
                        'rgba(245, 158, 11, 0.85)'
                    ],
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 40,
                        grid: { color: '#f1f5f9' },
                        ticks: { font: { size: 11, weight: 'bold' } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: 'bold' } }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
