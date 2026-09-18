<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// IZINKAN SUPER ADMIN, KEPALA SEKOLAH, DAN ADMIN
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['super_admin', 'kepala_sekolah', 'admin'])) {
    redirect('../dashboard.php');
}

$period = getReportPeriod();
$dateStart = $period['start'];
$dateEnd = $period['end'];
$name = trim($_GET['name'] ?? '');
$gradeId = (int)($_GET['grade_id'] ?? 0);
$classId = (int)($_GET['class_group_id'] ?? 0);
$status = $_GET['status'] ?? '';

$access = getStudentAccessCondition('g', 'cg');
$where = ["s.deleted_at IS NULL", "sa.attendance_date BETWEEN ? AND ?", $access['condition']];
$params = [$dateStart, $dateEnd];
$params = array_merge($params, $access['params']);

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($gradeId > 0) { $where[] = "g.id = ?"; $params[] = $gradeId; }
if ($classId > 0) { $where[] = "cg.id = ?"; $params[] = $classId; }
if ($status !== '') { $where[] = "sa.status = ?"; $params[] = $status; }

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT sa.*, s.name, s.nis, g.grade, cg.name AS class_name, a.name AS activity_name
    FROM student_attendances sa
    INNER JOIN students s ON s.id = sa.student_id
    INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN activities a ON a.id = sa.activity_id
    WHERE {$whereSql}
    ORDER BY sa.attendance_date DESC, g.sort_order, cg.name, s.name
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Hitung ringkasan total
$totTepat = 0; $totTelat = 0; $totIzin = 0; $totSakit = 0; $totAlpha = 0;
foreach($rows as $r) {
    if($r['status'] === 'tepat_waktu') $totTepat++;
    elseif($r['status'] === 'terlambat') $totTelat++;
    elseif($r['status'] === 'izin') $totIzin++;
    elseif($r['status'] === 'sakit') $totSakit++;
    else $totAlpha++;
}

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="laporan_absensi_siswa_' . $dateStart . '_sampai_' . $dateEnd . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');
?>
<html>
<head>
<meta charset="UTF-8">
</head>
<body>
    <h2 style="text-align: center; font-family: Arial, sans-serif; color: #0f172a; margin-bottom: 2px;">LAPORAN ABSENSI SISWA</h2>
    <h4 style="text-align: center; font-family: Arial, sans-serif; color: #0284c7; margin-top: 0; margin-bottom: 10px;">AN NAHL ISLAMIC SCHOOL</h4>
    <p style="text-align: center; font-family: Arial, sans-serif; font-size: 12px; color: #475569;">Periode: <strong><?= e($dateStart) ?></strong> s/d <strong><?= e($dateEnd) ?></strong></p>

    <!-- Kotak Ringkasan Total -->
    <table border="1" cellpadding="6" cellspacing="0" style="margin-bottom: 15px; font-family: Arial, sans-serif; font-size: 12px; background-color: #f8fafc;">
        <tr style="font-weight: bold; color: #334155;">
            <td>Tepat Waktu: <?= $totTepat ?></td>
            <td>Terlambat: <?= $totTelat ?></td>
            <td>Izin: <?= $totIzin ?></td>
            <td>Sakit: <?= $totSakit ?></td>
            <td>Tidak Absen: <?= $totAlpha ?></td>
        </tr>
    </table>

    <table border="1" cellpadding="5" cellspacing="0" style="font-family: Arial, sans-serif; font-size: 11px;">
        <tr style="background-color: #0284c7; color: white; font-weight: bold; text-align: center;">
            <th>No</th>
            <th>Tanggal</th>
            <th>Nama Siswa</th>
            <th>NIS</th>
            <th>Subkelas</th>
            <th>Kegiatan</th>
            <th>Jam Masuk</th>
            <th>Jam Keluar</th>
            <th>Status</th>
            <th>Keterangan</th>
        </tr>
        <?php foreach ($rows as $index => $row): 
            $statusMap = [
                'tepat_waktu' => 'Tepat Waktu',
                'terlambat' => 'Terlambat',
                'izin' => 'Izin',
                'sakit' => 'Sakit',
                'tidak_absen' => 'Tidak Absen'
            ];
            $stText = $statusMap[$row['status']] ?? ucwords($row['status']);
        ?>
        <tr style="background-color: #ffffff; color: #0f172a;">
            <td style="text-align: center;"><?= $index + 1 ?></td>
            <td style="text-align: center;"><?= e($row['attendance_date']) ?></td>
            <td><?= e($row['name']) ?></td>
            <td style="text-align: center;"><?= e($row['nis']) ?></td>
            <td style="text-align: center;"><?= e($row['grade']) ?> - <?= e($row['class_name']) ?></td>
            <td><?= e($row['activity_name']) ?></td>
            <td style="text-align: center;"><?= e($row['time_in'] ?? '-') ?></td>
            <td style="text-align: center;"><?= e($row['time_out'] ?? '-') ?></td>
            <td style="text-align: center;"><?= e($stText) ?></td>
            <td><?= e($row['description'] ?? '-') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>