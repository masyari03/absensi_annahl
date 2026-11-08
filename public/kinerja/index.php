<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('kinerja_nilai_paralel');

$pageTitle = 'Penilaian Kinerja — Nilai Rata-Rata Semua Kelas';
$currentUserId = currentUserId();
$currentRole = currentRole();

// ── 1. Ambil Filter Unit & Tahun Ajaran dari API AIS Source ─────────────────
$filtersResponse = fetchAisSourceKinerjaFilters();
$availableUnits = $filtersResponse['success'] ? ($filtersResponse['units'] ?? []) : [];
$availableTahunAjaran = $filtersResponse['success'] ? ($filtersResponse['tahun_ajaran'] ?? []) : [];

// Penentuan Default Filter
$selectedUnitId = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 2; // Default 2: SD
$selectedTaId = isset($_GET['tahun_ajaran_id']) ? (int)$_GET['tahun_ajaran_id'] : 0;

if ($selectedTaId === 0 && !empty($availableTahunAjaran)) {
    // Gunakan tahun ajaran pertama (terbaru)
    $selectedTaId = (int)$availableTahunAjaran[0]['id'];
}

// ── 2. Ambil Data Performa Paralel dari API AIS Source ──────────────────────
$perfResponse = [];
$apiError = null;

if ($filtersResponse['success']) {
    $perfResponse = fetchAisSourceKinerjaPerformance($selectedUnitId, $selectedTaId);
    if (!$perfResponse['success']) {
        $apiError = $perfResponse['message'] ?? 'Gagal memuat data performa dari server AIS Source.';
    }
} else {
    $apiError = $filtersResponse['message'] ?? 'Tidak dapat terhubung ke server AIS Source (Laptop Sekolah).';
}

$unitInfo = $perfResponse['unit'] ?? null;
$taInfo = $perfResponse['tahun_ajaran'] ?? null;
$paralelData = $perfResponse['data'] ?? [];
$totalParalel = count($paralelData);

// Hitung Statistik Ringkasan
$totalSiswaGlobal = 0;
$allParalelAverages = [];
foreach ($paralelData as $p) {
    $totalSiswaGlobal += (int)($p['total_siswa'] ?? 0);
    $allParalelAverages[] = (float)($p['rata_rata_paralel'] ?? 0);
}
$topScoreParalel = !empty($allParalelAverages) ? max($allParalelAverages) : 0;
$avgOverall = !empty($allParalelAverages) ? round(array_sum($allParalelAverages) / count($allParalelAverages), 2) : 0;

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="space-y-6">
    <!-- ── PAGE HEADER ──────────────────────────────────────────────────────── -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <i class="fa-solid fa-link text-[10px] mr-1.5"></i> Terhubung ke AIS Source
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                    Murni Nilai Ujian Akhir (PAS / SAS / UAS)
                </span>
            </div>
            <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Pencapaian Nilai Rata-rata Per Paralel</h1>
            <p class="text-sm text-slate-500 mt-0.5">
                Evaluasi capaian belajar siswa berdasarkan data penilaian semesteran per tingkatan paralel dan rombel kelas.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl transition shadow-sm">
                <i class="fa-solid fa-print"></i>
                <span>Cetak Rekap</span>
            </button>
        </div>
    </div>

    <!-- ── FILTER CARD ──────────────────────────────────────────────────────── -->
    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-100">
        <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
            <!-- Pilihan Unit -->
            <div class="md:col-span-5">
                <label for="unit_id" class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">
                    <i class="fa-solid fa-school text-indigo-500 mr-1"></i> Unit Pendidikan
                </label>
                <select name="unit_id" id="unit_id" class="w-full bg-slate-50 border border-slate-200 text-slate-700 rounded-xl px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none transition">
                    <?php if (empty($availableUnits)): ?>
                        <option value="2">SD (Sekolah Dasar)</option>
                    <?php else: ?>
                        <?php foreach ($availableUnits as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $selectedUnitId === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['nama']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Pilihan Tahun Ajaran & Semester -->
            <div class="md:col-span-5">
                <label for="tahun_ajaran_id" class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">
                    <i class="fa-solid fa-calendar-days text-indigo-500 mr-1"></i> Tahun Ajaran & Semester
                </label>
                <select name="tahun_ajaran_id" id="tahun_ajaran_id" class="w-full bg-slate-50 border border-slate-200 text-slate-700 rounded-xl px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none transition">
                    <?php if (empty($availableTahunAjaran)): ?>
                        <option value="0">Tahun Ajaran Terbaru</option>
                    <?php else: ?>
                        <?php foreach ($availableTahunAjaran as $ta): ?>
                            <?php $semLabel = ((int)$ta['semester'] === 1) ? 'Semester 1 (Ganjil)' : 'Semester 2 (Genap)'; ?>
                            <option value="<?= (int)$ta['id'] ?>" <?= $selectedTaId === (int)$ta['id'] ? 'selected' : '' ?>>
                                Tahun <?= htmlspecialchars($ta['nama']) ?> — <?= $semLabel ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Tombol Submit -->
            <div class="md:col-span-2">
                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm rounded-xl transition shadow-sm shadow-indigo-200">
                    <i class="fa-solid fa-filter"></i>
                    <span>Tampilkan</span>
                </button>
            </div>
        </form>
    </div>

    <!-- ── ALERT JIKA ADA ERROR KONEKSI API ──────────────────────────────────── -->
    <?php if ($apiError): ?>
        <div class="p-5 bg-amber-50 border border-amber-200 rounded-2xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 text-amber-800">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0 text-amber-600 font-bold text-lg">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-base">Tidak Dapat Memuat Data dari AIS Source</h3>
                    <p class="text-sm text-slate-600 mt-0.5"><?= htmlspecialchars($apiError) ?></p>
                    <p class="text-xs text-slate-500 mt-1">
                        Endpoint: <code class="bg-amber-100/60 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars(AIS_SOURCE_API_URL) ?></code>
                    </p>
                </div>
            </div>
            <a href="" class="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold rounded-xl transition flex-shrink-0 shadow-sm">
                <i class="fa-solid fa-rotate-right"></i>
                <span>Coba Lagi</span>
            </a>
        </div>
    <?php endif; ?>

    <!-- ── RINGKASAN KPI / STATISTIK CEPAT ───────────────────────────────────── -->
    <?php if (!$apiError && !empty($paralelData)): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Unit & Semester -->
            <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl flex-shrink-0">
                    <i class="fa-solid fa-school"></i>
                </div>
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Unit Sekolah</span>
                    <h3 class="text-lg font-bold text-slate-800"><?= htmlspecialchars($unitInfo['nama'] ?? 'Unit') ?></h3>
                    <p class="text-xs text-slate-500">
                        <?= htmlspecialchars($taInfo['nama'] ?? '') ?> (Sem <?= htmlspecialchars($taInfo['semester'] ?? '') ?>)
                    </p>
                </div>
            </div>

            <!-- Total Paralel -->
            <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl flex-shrink-0">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Paralel</span>
                    <h3 class="text-xl font-bold text-slate-800"><?= $totalParalel ?> <span class="text-sm font-normal text-slate-500">Tingkat</span></h3>
                    <p class="text-xs text-slate-500">Seluruh rombel terhitung</p>
                </div>
            </div>

            <!-- Total Siswa -->
            <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl flex-shrink-0">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Siswa Terdata</span>
                    <h3 class="text-xl font-bold text-slate-800"><?= number_format($totalSiswaGlobal, 0, ',', '.') ?> <span class="text-sm font-normal text-slate-500">Anak</span></h3>
                    <p class="text-xs text-slate-500">Mengikuti ujian akhir</p>
                </div>
            </div>

            <!-- Rata-rata Tertinggi -->
            <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-xl flex-shrink-0">
                    <i class="fa-solid fa-trophy"></i>
                </div>
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Rerata Tertinggi</span>
                    <h3 class="text-xl font-bold text-amber-600"><?= number_format($topScoreParalel, 2, ',', '.') ?></h3>
                    <p class="text-xs text-slate-500">Capaian paralel terbaik</p>
                </div>
            </div>
        </div>

        <!-- ── DAFTAR CARD PARALEL (BERURUTAN DARI TERTINGGI KE TERENDAH) ────────── -->
        <div class="space-y-6">
            <?php foreach ($paralelData as $idx => $p): 
                $rank = $idx + 1;
                $score = (float)$p['rata_rata_paralel'];

                // Badge styling berdasarkan nilai
                if ($score >= 90) {
                    $badgeBg = 'bg-emerald-500 text-white';
                    $rankBg = 'bg-emerald-100 text-emerald-800 border-emerald-200';
                } elseif ($score >= 85) {
                    $badgeBg = 'bg-blue-600 text-white';
                    $rankBg = 'bg-blue-100 text-blue-800 border-blue-200';
                } elseif ($score >= 80) {
                    $badgeBg = 'bg-indigo-600 text-white';
                    $rankBg = 'bg-indigo-100 text-indigo-800 border-indigo-200';
                } else {
                    $badgeBg = 'bg-amber-500 text-white';
                    $rankBg = 'bg-amber-100 text-amber-800 border-amber-200';
                }
            ?>
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden transition hover:shadow-md">
                    <!-- Header Kartu Paralel -->
                    <div class="p-6 bg-slate-50/60 border-b border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex items-center gap-3.5">
                            <span class="w-9 h-9 rounded-xl flex items-center justify-center font-bold text-sm border <?= $rankBg ?>">
                                #<?= $rank ?>
                            </span>
                            <div>
                                <h2 class="text-xl font-bold text-slate-800">
                                    <?= htmlspecialchars($p['paralel']) ?>
                                </h2>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    Total Siswa Terdata: <strong><?= (int)$p['total_siswa'] ?></strong> anak
                                </p>
                            </div>
                        </div>

                        <!-- Nilai Rata-rata Paralel Utama -->
                        <div class="flex items-center gap-3">
                            <span class="text-xs uppercase tracking-wider font-semibold text-slate-400">Rata-rata Paralel:</span>
                            <span class="px-4 py-1.5 rounded-xl text-lg font-black tracking-tight shadow-sm <?= $badgeBg ?>">
                                <?= number_format($score, 2, ',', '.') ?>
                            </span>
                        </div>
                    </div>

                    <div class="p-6 space-y-6">
                        <!-- Rincian Kelas di Paralel ini (Sub-Rombel) -->
                        <div>
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-2.5">
                                <i class="fa-solid fa-users-rectangle text-indigo-500 mr-1.5"></i>
                                Pencapaian Nilai Rata-rata Per Kelas / Rombel:
                            </span>
                            <div class="flex flex-wrap gap-2.5">
                                <?php foreach ($p['kelas_list'] as $k): ?>
                                    <div class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-slate-50 border border-slate-200 hover:border-indigo-300 transition">
                                        <span class="font-bold text-slate-700 text-sm">
                                            Kelas <?= htmlspecialchars($k['code']) ?>:
                                        </span>
                                        <span class="font-black text-indigo-600 text-sm">
                                            <?= number_format((float)$k['rata_rata'], 2, ',', '.') ?>
                                        </span>
                                        <span class="text-[11px] text-slate-400">
                                            (<?= (int)$k['total_siswa'] ?> siswa)
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Urutan Mapel dari yang Tertinggi di Paralel -->
                        <div>
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-3">
                                <i class="fa-solid fa-arrow-down-wide-short text-emerald-500 mr-1.5"></i>
                                Urutan Mata Pelajaran dari yang Tertinggi di <?= htmlspecialchars($p['paralel']) ?>:
                            </span>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                <?php foreach ($p['mapel_ranking'] as $mIdx => $m): 
                                    $mRank = $mIdx + 1;
                                    $mScore = (float)$m['rata_rata'];
                                ?>
                                    <div class="p-3 rounded-xl bg-slate-50/50 border border-slate-100 flex items-center justify-between gap-3 hover:bg-slate-50 transition">
                                        <div class="flex items-center gap-2.5 min-w-0">
                                            <span class="w-6 h-6 rounded-lg bg-white border border-slate-200 text-slate-600 text-xs font-bold flex items-center justify-center flex-shrink-0">
                                                <?= $mRank ?>
                                            </span>
                                            <div class="truncate">
                                                <h4 class="text-sm font-semibold text-slate-800 truncate" title="<?= htmlspecialchars($m['nama']) ?>">
                                                    <?= htmlspecialchars($m['singkatan']) ?>
                                                </h4>
                                                <p class="text-[11px] text-slate-400 truncate">
                                                    <?= htmlspecialchars($m['nama']) ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            <span class="text-sm font-black text-slate-800">
                                                <?= number_format($mScore, 2, ',', '.') ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (!$apiError): ?>
        <div class="bg-white p-12 rounded-2xl border border-slate-100 shadow-sm text-center">
            <div class="w-16 h-16 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-2xl mx-auto mb-3">
                <i class="fa-solid fa-folder-open"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-1">Belum Ada Data Nilai Ujian Akhir</h3>
            <p class="text-sm text-slate-500 max-w-md mx-auto">
                Tidak ditemukan data nilai PAS/SAS/UAS untuk unit dan tahun ajaran yang dipilih pada database AIS Source.
            </p>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
