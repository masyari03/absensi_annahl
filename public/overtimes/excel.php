<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('reports_overtimes');

$currentRole = currentRole();
$role = $currentRole;
$userId = currentUserId();
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$filterYear = isset($_GET['filter_year']) && $_GET['filter_year'] !== '' ? (int)$_GET['filter_year'] : (int)date('Y');
$filterMonth = (isset($_GET['filter_month']) && $_GET['filter_month'] !== '') ? (int)$_GET['filter_month'] : null;
$nameFilter = trim($_GET['name'] ?? '');
$viewTab = in_array($_GET['tab'] ?? '', ['detail', 'recap']) ? $_GET['tab'] : 'recap';

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
    $where[] = "YEAR(so.overtime_date) = ?";
    $params[] = $filterYear;
}

if ($role === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    $where[] = "s.unit_id = ?";
    $params[] = $ksUnitId;
}

if ($nameFilter !== '') { 
    $where[] = "s.name LIKE ?"; 
    $params[] = "%{$nameFilter}%"; 
}

$whereSql = implode(' AND ', $where);
$sql = "
    SELECT so.overtime_date, so.time_out, s.id as staff_id, s.name, s.nik, u.unit as unit_name, a.staff_out as normal_out
    FROM staff_overtimes so
    JOIN staff s ON s.id = so.staff_id
    LEFT JOIN units u ON u.id = s.unit_id
    LEFT JOIN activities a ON a.id = so.activity_id
    WHERE {$whereSql} ORDER BY so.overtime_date DESC, s.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$overtimes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Perhitungan Total Jam Lembur & Rekap Staff
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
            'unit_name'     => $row['unit_name'] ?? '-',
            'total_seconds' => 0,
            'frekuensi'     => 0
        ];
    }
    $staffRecap[$sKey]['total_seconds'] += $durasi;
    $staffRecap[$sKey]['frekuensi']++;
}

uasort($staffRecap, fn($a, $b) => $b['total_seconds'] <=> $a['total_seconds']);

$totalJamKeseluruhan = floor($grandTotalSeconds / 3600);
$totalMenitKeseluruhan = floor(($grandTotalSeconds % 3600) / 60);
$totalStaffLembur = count($staffRecap);

$namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$namaBulanStr = $filterMonth ? ($namaBulan[$filterMonth - 1] ?? '') : '';

if (!empty($startDate) && !empty($endDate)) {
    $periodLabel = date('d/m/Y', strtotime($startDate)) . ' s/d ' . date('d/m/Y', strtotime($endDate));
    $fileSuffix = str_replace('-', '', $startDate) . '_' . str_replace('-', '', $endDate);
} elseif (!empty($startDate)) {
    $periodLabel = 'Mulai ' . date('d/m/Y', strtotime($startDate));
    $fileSuffix = 'Mulai_' . str_replace('-', '', $startDate);
} elseif (!empty($endDate)) {
    $periodLabel = 'Sampai ' . date('d/m/Y', strtotime($endDate));
    $fileSuffix = 'Sampai_' . str_replace('-', '', $endDate);
} elseif ($filterMonth) {
    $periodLabel = 'Bulan ' . $namaBulanStr . ' Tahun ' . $filterYear;
    $fileSuffix = 'Bulan_' . $filterMonth . '_' . $filterYear;
} else {
    $periodLabel = 'Tahun ' . $filterYear;
    $fileSuffix = 'Tahun_' . $filterYear;
}

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"Laporan_Lembur_Staff_{$fileSuffix}.xls\"");
header('Pragma: no-cache');
header('Expires: 0');
?>
<html>
<head>
<meta charset="UTF-8">
</head>
<body>
    <h2 style="text-align: center; font-family: Arial, sans-serif; color: #0f172a; margin-bottom: 2px;">
        <?= $viewTab === 'recap' ? 'REKAPITULASI TOTAL JAM LEMBUR STAFF' : 'LAPORAN DETAIL LEMBUR STAFF' ?>
    </h2>
    <h4 style="text-align: center; font-family: Arial, sans-serif; color: #0284c7; margin-top: 0; margin-bottom: 10px;">AN NAHL ISLAMIC SCHOOL</h4>
    <p style="text-align: center; font-family: Arial, sans-serif; font-size: 12px; color: #475569;">
        Periode: <strong><?= e($periodLabel) ?></strong>
        <?php if ($nameFilter !== ''): ?> &bull; Filter Nama: <?= e($nameFilter) ?><?php endif; ?>
    </p>

    <!-- Kotak Ringkasan Total Lembur -->
    <table border="1" cellpadding="6" cellspacing="0" style="margin-bottom: 15px; font-family: Arial, sans-serif; font-size: 12px; background-color: #f8fafc;">
        <tr style="font-weight: bold; color: #334155;">
            <td style="color: #b45309; background: #fef3c7;">TOTAL JAM LEMBUR: <?= $totalJamKeseluruhan ?> Jam <?= $totalMenitKeseluruhan ?> Menit</td>
            <td style="color: #1d4ed8;">Total Pegawai Lembur: <?= $totalStaffLembur ?> Orang</td>
            <td style="color: #047857;">Total Sesi / Kejadian: <?= count($overtimes) ?> Kali</td>
        </tr>
    </table>

    <?php if ($viewTab === 'recap'): ?>
        <!-- TABEL EXCEL REKAP PER PEGAWAI -->
        <table border="1" cellpadding="6" cellspacing="0" style="font-family: Arial, sans-serif; font-size: 11px;">
            <thead>
                <tr style="background-color: #0284c7; color: white; font-weight: bold; text-align: center;">
                    <th>No</th>
                    <th>NIK</th>
                    <th>Nama Pegawai / Staff</th>
                    <th>Unit</th>
                    <th>Frekuensi Lembur</th>
                    <th>Total Jam Lembur</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffRecap)): ?>
                    <tr><td colspan="6" style="text-align: center;">Tidak ada data lembur pada periode ini.</td></tr>
                <?php endif; ?>
                <?php $idx = 1; foreach ($staffRecap as $st): 
                    $stJam = floor($st['total_seconds'] / 3600);
                    $stMnt = floor(($st['total_seconds'] % 3600) / 60);
                ?>
                <tr>
                    <td style="text-align: center;"><?= $idx++ ?></td>
                    <td style="text-align: center; mso-number-format:'\@';"><?= e($st['nik']) ?></td>
                    <td><strong><?= e($st['name']) ?></strong></td>
                    <td style="text-align: center;"><?= e($st['unit_name']) ?></td>
                    <td style="text-align: center;"><?= $st['frekuensi'] ?> Kali</td>
                    <td style="text-align: center; font-weight: bold; color: #b45309;"><?= $stJam ?> Jam <?= $stMnt ?> Menit</td>
                </tr>
                <?php endforeach; ?>
                <tr style="background-color: #f1f5f9; font-weight: bold; text-align: center;">
                    <td colspan="4" style="text-align: right;">TOTAL KESELURUHAN:</td>
                    <td><?= count($overtimes) ?> Kali</td>
                    <td style="color: #b45309; font-size: 12px;"><?= $totalJamKeseluruhan ?> Jam <?= $totalMenitKeseluruhan ?> Menit</td>
                </tr>
            </tbody>
        </table>

    <?php else: ?>
        <!-- TABEL EXCEL DETAIL HARIAN -->
        <table border="1" cellpadding="5" cellspacing="0" style="font-family: Arial, sans-serif; font-size: 11px;">
            <thead>
                <tr style="background-color: #0284c7; color: white; font-weight: bold; text-align: center;">
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Nama Staff</th>
                    <th>NIK</th>
                    <th>Unit</th>
                    <th>Batas Normal</th>
                    <th>Jam Keluar</th>
                    <th>Durasi Lembur</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$overtimes): ?>
                    <tr><td colspan="8" style="text-align: center;">Tidak ada data lembur.</td></tr>
                <?php endif; ?>
                <?php foreach ($overtimes as $index => $row): ?>
                    <?php
                        $normalOut = !empty($row['normal_out']) ? strtotime($row['normal_out']) : 0;
                        $actualOut = !empty($row['time_out']) ? strtotime($row['time_out']) : 0;
                        $durasi = ($normalOut > 0 && $actualOut > $normalOut) ? ($actualOut - $normalOut) : 0;
                        $jam = floor($durasi / 3600);
                        $menit = floor(($durasi % 3600) / 60);
                    ?>
                <tr style="background-color: #ffffff; color: #0f172a;">
                    <td style="text-align: center;"><?= $index + 1 ?></td>
                    <td style="text-align: center;"><?= date('d/m/Y', strtotime($row['overtime_date'])) ?></td>
                    <td><strong><?= e($row['name'] ?? '-') ?></strong></td>
                    <td style="text-align: center; mso-number-format:'\@';"><?= e($row['nik'] ?? '-') ?></td>
                    <td style="text-align: center;"><?= e($row['unit_name'] ?? '-') ?></td>
                    <td style="text-align: center;"><?= e($row['normal_out'] ?? '-') ?></td>
                    <td style="text-align: center; color: #dc2626; font-weight: bold;"><?= e($row['time_out'] ?? '-') ?></td>
                    <td style="text-align: center; font-weight: bold;"><?= $jam ?> Jam <?= $menit ?> Menit</td>
                </tr>
                <?php endforeach; ?>
                <tr style="background-color: #f1f5f9; font-weight: bold; text-align: center;">
                    <td colspan="7" style="text-align: right;">TOTAL DURASI JAM LEMBUR KESELURUHAN:</td>
                    <td style="color: #b45309; font-size: 12px;"><?= $totalJamKeseluruhan ?> Jam <?= $totalMenitKeseluruhan ?> Menit</td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>