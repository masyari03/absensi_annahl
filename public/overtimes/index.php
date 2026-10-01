<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// IZINKAN PENGGUNA YANG MEMILIKI HAK AKSES 'reports_overtimes' (HRD, SUPER ADMIN, KEPALA SEKOLAH, DLL)
requireLogin();
checkUserAccess('reports_overtimes');

$pageTitle = 'Laporan & Rekap Lembur Staff';
$currentRole = currentRole();
$role = $currentRole;
$userId = currentUserId();

$isFiltered = isset($_GET['filter_month']) || isset($_GET['start_date']) || isset($_GET['end_date']) || isset($_GET['filter_year']) || isset($_GET['name']);

$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$filterYear = isset($_GET['filter_year']) && $_GET['filter_year'] !== '' ? (int)$_GET['filter_year'] : (int)date('Y');

// Default ke bulan berjalan hanya saat pertama kali halaman dibuka tanpa query filter
if (!$isFiltered) {
    $filterMonth = (int)date('n');
} else {
    $filterMonth = (isset($_GET['filter_month']) && $_GET['filter_month'] !== '') ? (int)$_GET['filter_month'] : null;
}

$nameFilter = trim($_GET['name'] ?? '');
$viewTab = in_array($_GET['tab'] ?? '', ['detail', 'recap']) ? $_GET['tab'] : 'recap'; // Default ke Rekap agar HRD langsung lihat total jam per staff

// Filter Unit Khusus Kepala Sekolah
$unitFilterSql = "";
$unitParams = [];
$ksUnitId = 0;
if ($role === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    
    $unitFilterSql = " AND s.unit_id = ?";
    $unitParams[] = $ksUnitId;
}

// =========================================================================
// TABEL LOGIKA & QUERY BUILDER (SUPPORT RANGE TANGGAL / BULAN / TAHUN)
// =========================================================================
$where = [];
$params = [];

if (!empty($startDate) && !empty($endDate)) {
    $where[] = "so.overtime_date BETWEEN ? AND ?";
    $params[] = $startDate;
    $params[] = $endDate;
} elseif (!empty($startDate)) {
    $where[] = "so.overtime_date >= ?";
    $params[] = $startDate;
} elseif (!empty($endDate)) {
    $where[] = "so.overtime_date <= ?";
    $params[] = $endDate;
} elseif (!empty($filterMonth)) {
    $where[] = "YEAR(so.overtime_date) = ?";
    $where[] = "MONTH(so.overtime_date) = ?";
    $params[] = $filterYear;
    $params[] = $filterMonth;
} else {
    // Jika bulan dikosongkan dan tidak ada filter tanggal spesifik -> filter per tahun
    $where[] = "YEAR(so.overtime_date) = ?";
    $params[] = $filterYear;
}

if ($role === 'kepala_sekolah') {
    $where[] = "s.unit_id = ?";
    $params[] = $ksUnitId;
}

if ($nameFilter !== '') {
    $where[] = "s.name LIKE ?";
    $params[] = "%{$nameFilter}%";
}
$whereSql = implode(' AND ', $where);

$sql = "
    SELECT 
        so.overtime_date, so.time_out,
        s.id as staff_id, s.name, s.nik, s.photo,
        u.unit as unit_name,
        a.staff_out as normal_out
    FROM staff_overtimes so
    JOIN staff s ON s.id = so.staff_id
    LEFT JOIN units u ON u.id = s.unit_id
    LEFT JOIN activities a ON a.id = so.activity_id
    WHERE {$whereSql}
    ORDER BY so.overtime_date DESC, s.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$overtimes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// CHART LOGIKA (ADAPTIF: RANGE TANGGAL / BULAN / TAHUNAN)
// =========================================================================
$chartLabels = [];
$chartDataArr = [];

if (!empty($startDate) && !empty($endDate)) {
    $sTime = strtotime($startDate);
    $eTime = strtotime($endDate);
    $diffDays = ($sTime && $eTime && $eTime >= $sTime) ? (int)(($eTime - $sTime) / 86400) + 1 : 0;

    if ($diffDays > 0 && $diffDays <= 62) {
        $curr = $sTime;
        while ($curr <= $eTime) {
            $dKey = date('Y-m-d', $curr);
            $chartLabels[$dKey] = date('d M', $curr);
            $chartDataArr[$dKey] = 0;
            $curr = strtotime('+1 day', $curr);
        }
        $cWhere = ["so.overtime_date BETWEEN ? AND ?"];
        $cParams = [$startDate, $endDate];
        if ($role === 'kepala_sekolah') {
            $cWhere[] = "s.unit_id = ?";
            $cParams[] = $ksUnitId;
        }
        $stmtChart = $pdo->prepare("
            SELECT so.overtime_date as day_key, COUNT(so.id) as total
            FROM staff_overtimes so
            JOIN staff s ON s.id = so.staff_id
            WHERE " . implode(' AND ', $cWhere) . "
            GROUP BY so.overtime_date
        ");
        $stmtChart->execute($cParams);
        foreach ($stmtChart->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($chartDataArr[$row['day_key']])) {
                $chartDataArr[$row['day_key']] = (int)$row['total'];
            }
        }
    } else {
        $cWhere = ["so.overtime_date BETWEEN ? AND ?"];
        $cParams = [$startDate, $endDate];
        if ($role === 'kepala_sekolah') {
            $cWhere[] = "s.unit_id = ?";
            $cParams[] = $ksUnitId;
        }
        $stmtChart = $pdo->prepare("
            SELECT DATE_FORMAT(so.overtime_date, '%b %Y') as m_label, COUNT(so.id) as total
            FROM staff_overtimes so
            JOIN staff s ON s.id = so.staff_id
            WHERE " . implode(' AND ', $cWhere) . "
            GROUP BY DATE_FORMAT(so.overtime_date, '%Y-%m')
            ORDER BY so.overtime_date ASC
        ");
        $stmtChart->execute($cParams);
        foreach ($stmtChart->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chartLabels[] = $row['m_label'];
            $chartDataArr[] = (int)$row['total'];
        }
    }
} elseif (!empty($filterMonth)) {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $filterMonth, $filterYear);
    for ($i = 1; $i <= $daysInMonth; $i++) { 
        $chartLabels[$i] = (string)$i; 
        $chartDataArr[$i] = 0; 
    }
    $cWhere = ["YEAR(so.overtime_date) = ?", "MONTH(so.overtime_date) = ?"];
    $cParams = [$filterYear, $filterMonth];
    if ($role === 'kepala_sekolah') {
        $cWhere[] = "s.unit_id = ?";
        $cParams[] = $ksUnitId;
    }
    $stmtChart = $pdo->prepare("
        SELECT DAY(so.overtime_date) as day_label, COUNT(so.id) as total
        FROM staff_overtimes so
        JOIN staff s ON s.id = so.staff_id
        WHERE " . implode(' AND ', $cWhere) . "
        GROUP BY DAY(so.overtime_date)
    ");
    $stmtChart->execute($cParams);
    foreach ($stmtChart->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chartDataArr[(int)$row['day_label']] = (int)$row['total'];
    }
} else {
    // Tampilan grafik 12 bulan dalam setahun bila bulan dikosongkan
    $namaBlnArr = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    for ($m = 1; $m <= 12; $m++) {
        $chartLabels[$m] = $namaBlnArr[$m - 1];
        $chartDataArr[$m] = 0;
    }
    $cWhere = ["YEAR(so.overtime_date) = ?"];
    $cParams = [$filterYear];
    if ($role === 'kepala_sekolah') {
        $cWhere[] = "s.unit_id = ?";
        $cParams[] = $ksUnitId;
    }
    $stmtChart = $pdo->prepare("
        SELECT MONTH(so.overtime_date) as m_label, COUNT(so.id) as total
        FROM staff_overtimes so
        JOIN staff s ON s.id = so.staff_id
        WHERE " . implode(' AND ', $cWhere) . "
        GROUP BY MONTH(so.overtime_date)
    ");
    $stmtChart->execute($cParams);
    foreach ($stmtChart->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chartDataArr[(int)$row['m_label']] = (int)$row['total'];
    }
}

$jsLabels = json_encode(array_values($chartLabels));
$jsData = json_encode(array_values($chartDataArr));

// =========================================================================
// PERHITUNGAN TOTAL JUMLAH JAM LEMBUR & REKAPITULASI PER PEGAWAI (HRD)
// =========================================================================
$grandTotalSeconds = 0;
$staffRecap = [];

foreach ($overtimes as $row) {
    $normalOut = !empty($row['normal_out']) ? strtotime($row['normal_out']) : 0;
    $actualOut = !empty($row['time_out']) ? strtotime($row['time_out']) : 0;
    $durasi = ($normalOut > 0 && $actualOut > $normalOut) ? ($actualOut - $normalOut) : 0;
    
    $grandTotalSeconds += $durasi;

    $sKey = $row['staff_id'] ?: $row['nik'];
    if (!isset($staffRecap[$sKey])) {
        $staffRecap[$sKey] = [
            'staff_id'      => $sKey,
            'name'          => $row['name'],
            'nik'           => $row['nik'],
            'photo'         => $row['photo'],
            'unit_name'     => $row['unit_name'] ?? '-',
            'total_seconds' => 0,
            'frekuensi'     => 0
        ];
    }
    $staffRecap[$sKey]['total_seconds'] += $durasi;
    $staffRecap[$sKey]['frekuensi']++;
}

// Urutkan rekap staff dari yang paling banyak jam lemburnya
uasort($staffRecap, function($a, $b) {
    return $b['total_seconds'] <=> $a['total_seconds'];
});

$totalStaffLembur = count($staffRecap);
$totalJamKeseluruhan = floor($grandTotalSeconds / 3600);
$totalMenitKeseluruhan = floor(($grandTotalSeconds % 3600) / 60);
$totalJamDecimal = round($grandTotalSeconds / 3600, 1);
$rataRataPerStaff = $totalStaffLembur > 0 ? round(($grandTotalSeconds / 3600) / $totalStaffLembur, 1) : 0;

$namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$namaBulanStr = $filterMonth ? ($namaBulan[$filterMonth - 1] ?? '') : '';

if (!empty($startDate) && !empty($endDate)) {
    $periodLabel = date('d/m/Y', strtotime($startDate)) . ' s/d ' . date('d/m/Y', strtotime($endDate));
} elseif (!empty($startDate)) {
    $periodLabel = 'Mulai ' . date('d/m/Y', strtotime($startDate));
} elseif (!empty($endDate)) {
    $periodLabel = 'Sampai ' . date('d/m/Y', strtotime($endDate));
} elseif ($filterMonth) {
    $periodLabel = 'Bulan ' . $namaBulanStr . ' ' . $filterYear;
} else {
    $periodLabel = 'Tahun ' . $filterYear;
}

$queryParams = http_build_query([
    'start_date'   => $startDate,
    'end_date'     => $endDate,
    'filter_month' => $filterMonth ?? '',
    'filter_year'  => $filterYear,
    'name'         => $nameFilter
]);

require '../../includes/header.php';
?>

<style>
/* SUMMARY KPI CARDS KHUSUS HRD */
.kpi-overtime-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}
.kpi-ot-card {
    padding: 18px 20px;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.04);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.2s ease;
}
.kpi-ot-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px -3px rgba(0,0,0,0.08);
}
.kpi-ot-card.hero {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border-left: 5px solid #f59e0b;
}
.kpi-ot-card.users {
    background: #ffffff;
    border-left: 5px solid #3b82f6;
}
.kpi-ot-card.freq {
    background: #ffffff;
    border-left: 5px solid #10b981;
}
.kpi-ot-card.avg {
    background: #ffffff;
    border-left: 5px solid #8b5cf6;
}
.kpi-ot-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.kpi-ot-card.hero .kpi-ot-icon { background: rgba(245, 158, 11, 0.2); color: #d97706; }
.kpi-ot-card.users .kpi-ot-icon { background: rgba(59, 130, 246, 0.12); color: #2563eb; }
.kpi-ot-card.freq .kpi-ot-icon { background: rgba(16, 185, 129, 0.12); color: #059669; }
.kpi-ot-card.avg .kpi-ot-icon { background: rgba(139, 92, 246, 0.12); color: #7c3aed; }

/* TABS TABEL */
.ot-tabs-wrap {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 16px;
    padding-bottom: 8px;
}
.ot-tab-btn {
    padding: 9px 18px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: 0.15s;
}
.ot-tab-btn:hover {
    background: #f1f5f9;
    color: #1e293b;
}
.ot-tab-btn.active {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
    box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
}
</style>

<!-- ========================================================================= -->
<!-- 1. KARTU TOTAL JUMLAH JAM LEMBUR (LANGSUNG MUNCUL KETIKA HRD MEMBUKA)      -->
<!-- ========================================================================= -->
<div class="kpi-overtime-grid">
    <!-- KARTU UTAMA: TOTAL JAM LEMBUR -->
    <div class="kpi-ot-card hero">
        <div>
            <small style="color: #b45309; font-weight: 800; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                <i class="fa-solid fa-clock"></i> Total Jumlah Jam Lembur
            </small>
            <h2 style="margin: 6px 0 2px 0; color: #92400e; font-size: 26px; font-weight: 900; line-height: 1.1;">
                <?= $totalJamKeseluruhan ?> <span style="font-size: 16px; font-weight: 700;">Jam</span> <?= $totalMenitKeseluruhan ?> <span style="font-size: 16px; font-weight: 700;">Mnt</span>
            </h2>
            <span style="font-size: 12px; color: #b45309; font-weight: 600;">(Setara <?= $totalJamDecimal ?> Jam Kerja Periode Ini)</span>
        </div>
        <div class="kpi-ot-icon">
            <i class="fa-solid fa-business-time"></i>
        </div>
    </div>

    <!-- KARTU 2: PEGAWAI LEMBUR -->
    <div class="kpi-ot-card users">
        <div>
            <small style="color: #1d4ed8; font-weight: 800; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                <i class="fa-solid fa-users"></i> Pegawai Lembur
            </small>
            <h2 style="margin: 6px 0 2px 0; color: #1e3a8a; font-size: 26px; font-weight: 900; line-height: 1.1;">
                <?= $totalStaffLembur ?> <span style="font-size: 16px; font-weight: 700;">Orang</span>
            </h2>
            <span style="font-size: 12px; color: #64748b;">Staff lembur periode <?= e($periodLabel) ?></span>
        </div>
        <div class="kpi-ot-icon">
            <i class="fa-solid fa-user-clock"></i>
        </div>
    </div>

    <!-- KARTU 3: TOTAL SESI LEMBUR -->
    <div class="kpi-ot-card freq">
        <div>
            <small style="color: #047857; font-weight: 800; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                <i class="fa-solid fa-calendar-check"></i> Total Kejadian Lembur
            </small>
            <h2 style="margin: 6px 0 2px 0; color: #064e3b; font-size: 26px; font-weight: 900; line-height: 1.1;">
                <?= count($overtimes) ?> <span style="font-size: 16px; font-weight: 700;">Kali</span>
            </h2>
            <span style="font-size: 12px; color: #64748b;">Total absensi lembur tercatat</span>
        </div>
        <div class="kpi-ot-icon">
            <i class="fa-solid fa-list-check"></i>
        </div>
    </div>

    <!-- KARTU 4: RATA-RATA LEMBUR PER PEGAWAI -->
    <div class="kpi-ot-card avg">
        <div>
            <small style="color: #6d28d9; font-weight: 800; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                <i class="fa-solid fa-chart-line"></i> Rata-Rata / Pegawai
            </small>
            <h2 style="margin: 6px 0 2px 0; color: #4c1d95; font-size: 26px; font-weight: 900; line-height: 1.1;">
                <?= $rataRataPerStaff ?> <span style="font-size: 16px; font-weight: 700;">Jam</span>
            </h2>
            <span style="font-size: 12px; color: #64748b;">Rata-rata jam per pegawai</span>
        </div>
        <div class="kpi-ot-icon">
            <i class="fa-solid fa-chart-pie"></i>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 2. FILTER PERIODE TANGGAL, BULAN & TAHUN                                  -->
<!-- ========================================================================= -->
<div class="card">
    <div class="card-header" style="justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <h3 style="margin: 0; font-size: 18px;"><i class="fa-solid fa-chart-simple" style="color: #f59e0b;"></i> Grafik & Analisis Lembur Staff</h3>
            <small>Periode: <strong><?= e($periodLabel) ?></strong> <?= $role === 'kepala_sekolah' ? '(Khusus Unit Anda)' : '' ?></small>
        </div>
    </div>
    
    <form method="GET" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 16px; margin-bottom: 16px;">
        <input type="hidden" name="tab" value="<?= e($viewTab) ?>">
        <div class="filter-grid" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
            <div class="form-group">
                <label>Dari Tanggal</label>
                <input type="date" name="start_date" value="<?= e($startDate) ?>" style="width: 100%; padding: 8px; box-sizing: border-box; border-radius: 6px; border: 1px solid #cbd5e1;">
            </div>

            <div class="form-group">
                <label>Sampai Tanggal</label>
                <input type="date" name="end_date" value="<?= e($endDate) ?>" style="width: 100%; padding: 8px; box-sizing: border-box; border-radius: 6px; border: 1px solid #cbd5e1;">
            </div>

            <div class="form-group">
                <label>Pilih Bulan</label>
                <select name="filter_month" style="width: 100%; padding: 8px; box-sizing: border-box; border-radius: 6px; border: 1px solid #cbd5e1;">
                    <option value="">-- Kosongkan / Semua --</option>
                    <?php 
                    $namaBulanList = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    foreach($namaBulanList as $index => $nama): 
                        $num = $index + 1;
                    ?>
                        <option value="<?= $num ?>" <?= ($filterMonth !== null && $filterMonth == $num) ? 'selected' : '' ?>><?= $nama ?> (<?= $num ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Tahun</label>
                <select name="filter_year" style="width: 100%; padding: 8px; box-sizing: border-box; border-radius: 6px; border: 1px solid #cbd5e1;">
                    <?php for($y = date('Y'); $y >= 2023; $y--): ?>
                        <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Nama Staff</label>
                <input type="text" name="name" value="<?= e($nameFilter) ?>" placeholder="Cari nama staff..." style="width: 100%; padding: 8px; box-sizing: border-box; border-radius: 6px; border: 1px solid #cbd5e1;">
            </div>

            <div class="form-group" style="display: flex; align-items: flex-end; gap: 8px;">
                <button type="submit" class="btn btn-primary" style="padding: 8px 16px; height: 38px; font-weight: 700;">
                    <i class="fa-solid fa-magnifying-glass"></i> Filter
                </button>
                <a href="index.php?tab=<?= e($viewTab) ?>" class="btn" style="padding: 8px 12px; height: 38px; display: inline-flex; align-items: center; border: 1px solid #cbd5e1; background: #f8fafc; color: #475569;" title="Reset Filter">
                    <i class="fa-solid fa-rotate-right"></i>
                </a>
            </div>
        </div>
    </form>
    
    <div style="padding: 10px; position: relative; width: 100%; min-height: 250px; height: 280px;">
        <canvas id="overtimeChart"></canvas>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 3. TABEL DATA: REKAP TOTAL PER STAFF VS DETAIL LOG HARIAN                 -->
<!-- ========================================================================= -->
<div class="card">
    <div class="card-header" style="justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
            <h3 style="margin: 0; font-size: 18px;">
                <?= $viewTab === 'recap' ? '📊 Rekap Total Jam Lembur Per Pegawai' : '📋 Log Detail Harian Lembur Staff' ?>
            </h3>
            <small>Menampilkan total jam lembur yang tercatat pada periode ini</small>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="excel.php?<?= $queryParams ?>&tab=<?= $viewTab ?>" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-file-excel"></i> Export Excel
            </a>
            <a href="pdf.php?<?= $queryParams ?>&tab=<?= $viewTab ?>" target="_blank" class="btn btn-danger" style="display:inline-flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-file-pdf"></i> Export PDF
            </a>
        </div>
    </div>

    <!-- TABS PILIHAN TAMPILAN -->
    <div class="ot-tabs-wrap">
        <a href="?<?= $queryParams ?>&tab=recap" class="ot-tab-btn <?= $viewTab === 'recap' ? 'active' : '' ?>">
            <i class="fa-solid fa-calculator"></i> Rekap Total Jam Per Pegawai (HRD)
        </a>
        <a href="?<?= $queryParams ?>&tab=detail" class="ot-tab-btn <?= $viewTab === 'detail' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-check"></i> Log Detail Harian
        </a>
    </div>

    <?php if ($viewTab === 'recap'): ?>
        <!-- ================================================================= -->
        <!-- TABEL 1: REKAP TOTAL JAM LEMBUR PER STAFF                        -->
        <!-- ================================================================= -->
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="width: 50px; text-align: center;">No</th>
                        <th style="width: 60px; text-align: center;">Foto</th>
                        <th>Nama Pegawai / Staff</th>
                        <th>NIK</th>
                        <th>Unit</th>
                        <th style="text-align: center;">Frekuensi Lembur</th>
                        <th style="text-align: center; color: #b45309;">Total Jam Lembur</th>
                        <th style="text-align: center;">Rata-rata / Hari</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staffRecap)): ?>
                        <tr><td colspan="8" style="text-align:center; padding: 30px; color: #94a3b8;">Tidak ada data lembur pada periode bulan ini.</td></tr>
                    <?php else: ?>
                        <?php $sIdx = 1; foreach ($staffRecap as $st): 
                            $stJam = floor($st['total_seconds'] / 3600);
                            $stMnt = floor(($st['total_seconds'] % 3600) / 60);
                            $stAvg = $st['frekuensi'] > 0 ? round(($st['total_seconds'] / 3600) / $st['frekuensi'], 1) : 0;
                            $avatar = !empty($st['photo']) ? '../../uploads/staff/' . $st['photo'] : null;
                        ?>
                            <tr>
                                <td style="text-align: center;"><?= $sIdx++ ?></td>
                                <td style="text-align: center;">
                                    <?php if ($avatar && file_exists(__DIR__ . '/../../uploads/staff/' . $st['photo'])): ?>
                                        <img src="<?= e($avatar) ?>" width="38" height="38" style="object-fit:cover; border-radius:8px;">
                                    <?php else: ?>
                                        <div style="width:38px; height:38px; line-height:38px; font-size:15px; background:#e0e7ff; color:#4f46e5; text-align:center; border-radius:8px; font-weight:bold; margin:auto;">
                                            <?= strtoupper(substr($st['name'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= e($st['name']) ?></strong>
                                </td>
                                <td><code><?= e($st['nik'] ?: '-') ?></code></td>
                                <td><?= e($st['unit_name']) ?></td>
                                <td style="text-align: center;">
                                    <span style="background: #f1f5f9; padding: 3px 10px; border-radius: 12px; font-weight: 700; color: #334155;">
                                        <?= $st['frekuensi'] ?> Kali
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <span style="background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 20px; font-weight: 800; font-size: 13.5px; border: 1px solid #fde68a;">
                                        <i class="fa-solid fa-clock" style="font-size:11px;"></i> <?= $stJam ?> Jam <?= $stMnt ?> Menit
                                    </span>
                                </td>
                                <td style="text-align: center; color: #64748b; font-weight: 600;">
                                    <?= $stAvg ?> Jam / kali
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($staffRecap)): ?>
                    <tfoot>
                        <tr style="background: #f8fafc; font-weight: 800; border-top: 2px solid #cbd5e1;">
                            <td colspan="5" style="text-align: right; font-size: 14px;">TOTAL KESELURUHAN (<?= $totalStaffLembur ?> PEGAWAI):</td>
                            <td style="text-align: center; font-size: 14px; color: #047857;"><?= count($overtimes) ?> Kali</td>
                            <td style="text-align: center;">
                                <span style="background: #f59e0b; color: #ffffff; padding: 5px 14px; border-radius: 20px; font-weight: 900; font-size: 14px;">
                                    <?= $totalJamKeseluruhan ?> Jam <?= $totalMenitKeseluruhan ?> Menit
                                </span>
                            </td>
                            <td style="text-align: center; font-size: 13px; color: #475569;"><?= $rataRataPerStaff ?> Jam (Rata-rata)</td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

    <?php else: ?>
        <!-- ================================================================= -->
        <!-- TABEL 2: LOG DETAIL HARIAN LEMBUR                                 -->
        <!-- ================================================================= -->
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="width: 50px; text-align: center;">No</th>
                        <th>Tanggal</th>
                        <th style="width: 60px; text-align: center;">Foto</th>
                        <th>Nama Staff</th>
                        <th>NIK</th>
                        <th>Batas Normal</th>
                        <th>Jam Keluar</th>
                        <th style="text-align: center;">Durasi Lembur</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$overtimes): ?>
                        <tr><td colspan="8" style="text-align:center; padding: 30px; color: #94a3b8;">Tidak ada data lembur pada periode ini.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($overtimes as $index => $row): ?>
                        <?php
                            $normalOut = !empty($row['normal_out']) ? strtotime($row['normal_out']) : 0;
                            $actualOut = !empty($row['time_out']) ? strtotime($row['time_out']) : 0;
                            $durasi = ($normalOut > 0 && $actualOut > $normalOut) ? ($actualOut - $normalOut) : 0;
                            $jam = floor($durasi / 3600);
                            $menit = floor(($durasi % 3600) / 60);
                            $avatar = !empty($row['photo']) ? '../../uploads/staff/' . $row['photo'] : null;
                        ?>
                    <tr>
                        <td style="text-align: center;"><?= $index + 1 ?></td>
                        <td><?= date('d M Y', strtotime($row['overtime_date'])) ?></td>
                        <td style="text-align: center;">
                            <?php if ($avatar && file_exists(__DIR__ . '/../../uploads/staff/' . $row['photo'])): ?>
                                <img src="<?= e($avatar) ?>" width="38" height="38" style="object-fit:cover; border-radius:8px;">
                            <?php else: ?>
                                <div style="width:38px; height:38px; line-height:38px; font-size:15px; background:#e0e7ff; color:#4f46e5; text-align:center; border-radius:8px; font-weight:bold; margin:auto;">
                                    <?= strtoupper(substr($row['name'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= e($row['name']) ?></strong></td>
                        <td><code><?= e($row['nik']) ?></code></td>
                        <td><span class="badge badge-success"><?= e($row['normal_out'] ?? '-') ?></span></td>
                        <td><span class="badge badge-danger"><?= e($row['time_out'] ?? '-') ?></span></td>
                        <td style="text-align: center;">
                            <span style="background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 12px; font-weight: 700; border: 1px solid #fde68a;">
                                <?= $jam ?> Jam <?= $menit ?> Menit
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if (!empty($overtimes)): ?>
                    <tfoot>
                        <tr style="background: #f8fafc; font-weight: 800; border-top: 2px solid #cbd5e1;">
                            <td colspan="7" style="text-align: right; font-size: 14px;">TOTAL DURASI JAM LEMBUR KESELURUHAN:</td>
                            <td style="text-align: center;">
                                <span style="background: #f59e0b; color: #ffffff; padding: 5px 14px; border-radius: 20px; font-weight: 900; font-size: 14px;">
                                    <?= $totalJamKeseluruhan ?> Jam <?= $totalMenitKeseluruhan ?> Menit
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
const ctx = document.getElementById('overtimeChart');
new Chart(ctx, {
    type: 'bar', 
    data: {
        labels: <?= $jsLabels ?>,
        datasets: [{
            label: 'Total Orang Lembur',
            data: <?= $jsData ?>,
            backgroundColor: '#f59e0b',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } }
    }
});
</script>
<?php require '../../includes/footer.php'; ?>