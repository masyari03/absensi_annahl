<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// IZINKAN SUPER ADMIN & KEPALA SEKOLAH
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['super_admin', 'kepala_sekolah'])) {
    die("Akses ditolak.");
}

$userId = currentUserId();
$filterYear = (int)($_GET['filter_year'] ?? date('Y'));
$filterMonth = (int)($_GET['filter_month'] ?? date('n'));
$nameFilter = trim($_GET['name'] ?? '');

$where = ["YEAR(so.overtime_date) = ?", "MONTH(so.overtime_date) = ?"];
$params = [$filterYear, $filterMonth];

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
    SELECT so.overtime_date, so.time_out, s.name, s.nik, a.staff_out as normal_out
    FROM staff_overtimes so
    JOIN staff s ON s.id = so.staff_id
    JOIN activities a ON a.id = so.activity_id
    WHERE {$whereSql} ORDER BY so.overtime_date DESC, s.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$overtimes = $stmt->fetchAll();

$namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$namaBulanStr = $namaBulan[$filterMonth - 1] ?? '';

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"Laporan_Lembur_Staff_Bulan_{$filterMonth}_{$filterYear}.xls\"");
header('Pragma: no-cache');
header('Expires: 0');
?>
<html>
<head>
<meta charset="UTF-8">
</head>
<body>
    <h2 style="text-align: center; font-family: Arial, sans-serif; color: #0f172a; margin-bottom: 2px;">LAPORAN LEMBUR STAFF</h2>
    <h4 style="text-align: center; font-family: Arial, sans-serif; color: #0284c7; margin-top: 0; margin-bottom: 10px;">AN NAHL ISLAMIC SCHOOL</h4>
    <p style="text-align: center; font-family: Arial, sans-serif; font-size: 12px; color: #475569;">Periode: <strong>Bulan <?= $namaBulanStr ?> Tahun <?= $filterYear ?></strong></p>

    <table border="1" cellpadding="6" cellspacing="0" style="font-family: Arial, sans-serif; font-size: 11px;">
        <thead>
            <tr style="background-color: #0284c7; color: white; font-weight: bold; text-align: center;">
                <th>No</th>
                <th>Tanggal</th>
                <th>Nama Staff</th>
                <th>NIK</th>
                <th>Batas Normal</th>
                <th>Jam Keluar</th>
                <th>Durasi Lembur</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$overtimes): ?>
                <tr>
                    <td colspan="7" style="text-align: center;">Tidak ada data lembur.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($overtimes as $index => $row): ?>
                <?php
                    $durasi = max(0, strtotime($row['time_out']) - strtotime($row['normal_out']));
                    $jam = floor($durasi / 3600); $menit = floor(($durasi % 3600) / 60);
                ?>
            <tr style="background-color: #ffffff; color: #0f172a;">
                <td style="text-align: center;"><?= $index + 1 ?></td>
                <td style="text-align: center;"><?= date('d/m/Y', strtotime($row['overtime_date'])) ?></td>
                <td><?= e($row['name']) ?></td>
                <td style="text-align: center; mso-number-format:'\@';"><?= e($row['nik']) ?></td>
                <td style="text-align: center;"><?= e($row['normal_out']) ?></td>
                <td style="text-align: center; color: #dc2626; font-weight: bold;"><?= e($row['time_out']) ?></td>
                <td style="text-align: center; font-weight: bold;"><?= $jam ?> Jam <?= $menit ?> Menit</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>