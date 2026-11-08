<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('kinerja_nilai_paralel');

$pageTitle = 'Nilai Rata-Rata Semua Kelas — Penilaian Kinerja';
$currentUserId = currentUserId();
$currentRole = currentRole();

// Filter Parameter
$unitId = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 2; // Default: Unit 2 (SD)
$tahunAjaranId = isset($_GET['tahun_ajaran_id']) ? (int)$_GET['tahun_ajaran_id'] : null;
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

// Ambil data dari API AIS Laptop Sekolah
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

// Hitung KPI Rata-rata Unit Keseluruhan
$overallUnitAvg = 0;
$topParalel = null;
$maxParalelAvg = -1;
if (!empty($paralelData)) {
    $sum = 0;
    foreach ($paralelData as $p) {
        $avg = (float)($p['rata_rata_paralel'] ?? 0);
        $sum += $avg;
        if ($avg > $maxParalelAvg) {
            $maxParalelAvg = $avg;
            $topParalel = $p['paralel_nama'] ?? '';
        }
    }
    $overallUnitAvg = round($sum / count($paralelData), 2);
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6">

    <!-- HEADER TITLE & ACTION -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-2xl border border-slate-200/80 shadow-sm">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                    <i class="fa-solid fa-chart-line mr-1"></i> Data Pendidikan
                </span>
                <?php if ($isFromCache): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-slate-100 text-slate-600 border border-slate-200" title="Data diambil dari cache lokal untuk performa kilat">
                        <i class="fa-solid fa-bolt text-amber-500 mr-1"></i> Mode Cepat (Cache)
                    </span>
                <?php endif; ?>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Pencapaian Nilai Rata-rata Per Paralel</h1>
            <p class="text-sm text-slate-500 mt-1">
                Perhitungan total nilai seluruh siswa per paralel khusus komponen <strong>Ujian Akhir Semester (PAS)</strong>.
            </p>
        </div>

        <!-- FORM FILTER & REFRESH -->
        <form method="GET" action="" class="flex flex-wrap items-center gap-2.5">
            <!-- Filter Unit -->
            <div class="relative">
                <select name="unit_id" onchange="this.form.submit()" class="pl-3 pr-8 py-2 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-medium text-slate-700 cursor-pointer shadow-sm">
                    <?php if (empty($availableUnits)): ?>
                        <option value="2">SD (Sekolah Dasar)</option>
                    <?php else: ?>
                        <?php foreach ($availableUnits as $u): ?>
                            <option value="<?= (int)$u['id']; ?>" <?= ($selectedUnit && $selectedUnit['id'] == $u['id']) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($u['nama'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Filter Tahun Ajaran & Semester -->
            <div class="relative">
                <select name="tahun_ajaran_id" onchange="this.form.submit()" class="pl-3 pr-8 py-2 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-medium text-slate-700 cursor-pointer shadow-sm">
                    <?php if (empty($availableYears)): ?>
                        <option value="">Tahun Ajaran Aktif</option>
                    <?php else: ?>
                        <?php foreach ($availableYears as $y): ?>
                            <option value="<?= (int)$y['id']; ?>" <?= ($selectedYear && $selectedYear['id'] == $y['id']) ? 'selected' : ''; ?>>
                                TP <?= htmlspecialchars($y['nama'], ENT_QUOTES, 'UTF-8'); ?> (Sm. <?= (int)$y['semester']; ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Tombol Sinkronkan Ulang -->
            <a href="?unit_id=<?= (int)$unitId; ?><?= $tahunAjaranId ? '&tahun_ajaran_id=' . (int)$tahunAjaranId : ''; ?>&refresh=1" 
               class="inline-flex items-center px-3.5 py-2 text-sm font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 hover:text-indigo-600 transition-colors shadow-sm"
               title="Tarik data terbaru dari server AIS laptop sekolah">
                <i class="fa-solid fa-arrows-rotate mr-1.5 <?= $forceRefresh ? 'animate-spin' : ''; ?>"></i> Sinkronkan
            </a>
        </form>
    </div>

    <!-- WARNING OFFLINE / FALLBACK NOTIFICATION -->
    <?php if ($warningMessage): ?>
        <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl flex items-start gap-3 text-amber-800">
            <i class="fa-solid fa-triangle-exclamation text-amber-500 text-lg mt-0.5"></i>
            <div class="text-sm">
                <p class="font-semibold">Pemberitahuan Sinkronisasi Offline</p>
                <p class="text-amber-700 mt-0.5"><?= htmlspecialchars($warningMessage, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- ERROR STATE IF SERVER AIS UNREACHABLE & NO CACHE -->
    <?php if (!$isSuccess): ?>
        <div class="bg-white p-8 rounded-2xl border border-red-200 shadow-sm text-center">
            <div class="w-16 h-16 bg-red-50 text-red-500 rounded-2xl flex items-center justify-center mx-auto mb-4 text-2xl">
                <i class="fa-solid fa-server"></i>
            </div>
            <h2 class="text-xl font-bold text-slate-900 mb-2">Gagal Menghubungkan ke Server AIS</h2>
            <p class="text-sm text-slate-600 max-w-lg mx-auto mb-6">
                <?= htmlspecialchars($errorMessage ?? 'Tidak dapat mengambil data nilai dari laptop sekolah.', ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <div class="flex items-center justify-center gap-3">
                <a href="?unit_id=<?= (int)$unitId; ?>&refresh=1" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 transition">
                    <i class="fa-solid fa-rotate-right mr-2"></i> Coba Hubungkan Lagi
                </a>
            </div>
        </div>
    <?php else: ?>

        <!-- SUMMARY STATS OVERVIEW CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Card 1: Unit Terpilih -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-school"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Unit Pendidikan</p>
                    <h3 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($selectedUnit['nama'] ?? 'SD', ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="text-xs text-slate-500"><?= count($paralelData); ?> Tingkat Paralel</p>
                </div>
            </div>

            <!-- Card 2: Periode Akademik -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Tahun & Semester</p>
                    <h3 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($selectedYear['nama'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="text-xs text-purple-600 font-medium">Semester <?= (int)($selectedYear['semester'] ?? 1); ?> (Ujian Akhir)</p>
                </div>
            </div>

            <!-- Card 3: Rata-rata Seluruh Unit -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-chart-simple"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Rata-rata Unit</p>
                    <h3 class="text-xl font-bold text-emerald-600"><?= number_format($overallUnitAvg, 2, ',', '.'); ?></h3>
                    <p class="text-xs text-slate-500">Akumulasi Semua Paralel</p>
                </div>
            </div>

            <!-- Card 4: Paralel Tertinggi -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-award"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Paralel Tertinggi</p>
                    <h3 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($topParalel ?? '-', ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="text-xs text-amber-600 font-medium"><?= number_format($maxParalelAvg, 2, ',', '.'); ?> Nilai Rata-rata</p>
                </div>
            </div>
        </div>

        <!-- LIST PARALEL KELAS (GRID) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <?php foreach ($paralelData as $index => $paralel): ?>
                <?php 
                    $paralelAvg = (float)($paralel['rata_rata_paralel'] ?? 0);
                    $subClasses = $paralel['sub_kelas'] ?? [];
                    $mapelList  = $paralel['mapel_rankings'] ?? [];
                ?>
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden flex flex-col hover:border-slate-300 transition duration-150">
                    
                    <!-- HEADER KARTU PARALEL -->
                    <div class="p-5 border-b border-slate-100 bg-gradient-to-r from-slate-50/80 via-white to-white flex items-center justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="w-7 h-7 rounded-lg bg-indigo-600 text-white font-bold text-xs flex items-center justify-center shadow-sm">
                                    <?= $index + 1; ?>
                                </span>
                                <h3 class="text-lg font-bold text-slate-900 tracking-tight">
                                    Paralel <?= htmlspecialchars($paralel['paralel_nama'], ENT_QUOTES, 'UTF-8'); ?>
                                </h3>
                            </div>
                            <p class="text-xs text-slate-500 mt-1 pl-9">
                                Total Siswa: <span class="font-medium text-slate-700"><?= (int)($paralel['total_siswa'] ?? 0); ?> Siswa</span> 
                                &bull; <span class="font-medium text-slate-700"><?= count($subClasses); ?> Rombel</span>
                            </p>
                        </div>

                        <!-- BADGE NILAI RATA-RATA PARALEL -->
                        <div class="text-right">
                            <span class="text-xs font-semibold text-slate-400 block uppercase tracking-wider">Rata-rata Paralel</span>
                            <span class="inline-flex items-center px-3 py-1 rounded-xl text-lg font-black bg-indigo-50 text-indigo-700 border border-indigo-200/60 shadow-xs">
                                <?= number_format($paralelAvg, 2, ',', '.'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- RINCIAN SUB-KELAS / ROMBEL -->
                    <div class="px-5 py-3.5 bg-slate-50/60 border-b border-slate-100">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                <i class="fa-solid fa-users-rectangle text-slate-400"></i> Rincian Sub-Kelas (Rombel)
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php if (empty($subClasses)): ?>
                                <span class="text-xs text-slate-400 italic">Belum ada rincian kelas</span>
                            <?php else: ?>
                                <?php foreach ($subClasses as $sub): ?>
                                    <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-xs shadow-2xs">
                                        <span class="font-semibold text-slate-800"><?= htmlspecialchars($sub['nama'], ENT_QUOTES, 'UTF-8'); ?>:</span>
                                        <span class="font-bold text-indigo-600"><?= number_format((float)$sub['rata_rata'], 2, ',', '.'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- RANKING MATA PELAJARAN (TERTINGGI KE TERENDAH) -->
                    <div class="p-5 flex-1 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                    <i class="fa-solid fa-trophy text-amber-500"></i> Urutan Mapel dari yang Tertinggi
                                </span>
                                <span class="text-[11px] text-slate-400">Total <?= count($mapelList); ?> Mapel</span>
                            </div>

                            <div class="space-y-2.5">
                                <?php if (empty($mapelList)): ?>
                                    <div class="py-6 text-center text-xs text-slate-400 italic">
                                        Tidak ada entri nilai PAS untuk paralel ini.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($mapelList as $m): ?>
                                        <?php 
                                            $rank = (int)($m['rank'] ?? 1);
                                            $score = (float)($m['rata_rata'] ?? 0);
                                            
                                            // Badge warna ranking
                                            $rankBadge = 'bg-slate-100 text-slate-600';
                                            if ($rank === 1) $rankBadge = 'bg-amber-100 text-amber-800 font-black border border-amber-300';
                                            elseif ($rank === 2) $rankBadge = 'bg-slate-200 text-slate-700 font-bold';
                                            elseif ($rank === 3) $rankBadge = 'bg-amber-50 text-amber-700 font-bold';

                                            // Warna progress bar
                                            $barColor = 'bg-indigo-600';
                                            if ($score >= 92) $barColor = 'bg-emerald-500';
                                            elseif ($score >= 87) $barColor = 'bg-indigo-500';
                                            elseif ($score >= 80) $barColor = 'bg-blue-500';
                                            else $barColor = 'bg-amber-500';
                                        ?>
                                        <div class="group p-2.5 rounded-xl hover:bg-slate-50 transition border border-transparent hover:border-slate-100">
                                            <div class="flex items-center justify-between text-xs mb-1.5">
                                                <div class="flex items-center gap-2 max-w-[70%]">
                                                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] shrink-0 <?= $rankBadge; ?>">
                                                        <?= $rank; ?>
                                                    </span>
                                                    <span class="font-semibold text-slate-800 truncate" title="<?= htmlspecialchars($m['mapel_nama'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?= htmlspecialchars($m['mapel_nama'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </div>
                                                <span class="font-bold text-slate-900 text-sm">
                                                    <?= number_format($score, 2, ',', '.'); ?>
                                                </span>
                                            </div>
                                            <!-- Progress Bar Visual -->
                                            <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                                <div class="<?= $barColor; ?> h-full rounded-full transition-all duration-500" style="width: <?= min(100, max(0, $score)); ?>%;"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Footer Info Paralel -->
                        <div class="mt-4 pt-3 border-t border-slate-100 text-[11px] text-slate-400 flex items-center justify-between">
                            <span>Komponen: <strong>PAS (Ujian Akhir Semester)</strong></span>
                            <span>Total Penilaian: <?= number_format((int)($paralel['total_entri'] ?? 0), 0, ',', '.'); ?> data</span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
