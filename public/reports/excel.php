<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// IZINKAN SUPER ADMIN, KEPALA SEKOLAH, DAN ADMIN
requireLogin();
checkUserAccess('reports_attendance');

$currentRole = currentRole();
$role = $currentRole;
$userId = currentUserId();
$type = $_GET['type'] ?? 'student';
$mode = in_array($_GET['mode'] ?? '', ['detail', 'recap']) ? $_GET['mode'] : 'detail';

// Batasi Admin agar tidak bisa akses laporan staff
if ($role === 'admin' && $type === 'staff') {
    die('Akses ditolak: Anda tidak memiliki hak akses untuk mengunduh laporan absensi staff.');
}

$period = getReportPeriod();
$dateStart = $period['start'];
$dateEnd = $period['end'];
$name = trim($_GET['name'] ?? '');
$status = $_GET['status'] ?? '';

$filterSubtitleInfo = [];

if ($type === 'student') {
    $gradeId = (int)($_GET['grade_id'] ?? 0);
    $classId = (int)($_GET['class_group_id'] ?? 0);

    $access = getStudentAccessCondition('g', 'cg');
    $where = ["s.deleted_at IS NULL", "sa.attendance_date BETWEEN ? AND ?", $access['condition']];
    $params = [$dateStart, $dateEnd];
    $params = array_merge($params, $access['params']);

    if ($name !== '') { 
        $where[] = "s.name LIKE ?"; 
        $params[] = "%{$name}%"; 
        $filterSubtitleInfo[] = "Nama: $name";
    }
    if ($gradeId > 0) { 
        $where[] = "g.id = ?"; 
        $params[] = $gradeId; 
        $gName = $pdo->prepare("SELECT grade FROM grades WHERE id = ?");
        $gName->execute([$gradeId]);
        if ($gn = $gName->fetchColumn()) {
            $filterSubtitleInfo[] = "Grade: $gn";
        }
    }
    if ($classId > 0) { 
        $where[] = "cg.id = ?"; 
        $params[] = $classId; 
        $cName = $pdo->prepare("SELECT CONCAT(g.grade, ' - ', cg.name) FROM class_groups cg JOIN grades g ON g.id = cg.grade_id WHERE cg.id = ?");
        $cName->execute([$classId]);
        if ($cn = $cName->fetchColumn()) {
            $filterSubtitleInfo[] = "Subkelas: $cn";
        }
    }
    if ($status !== '') { 
        $where[] = "sa.status = ?"; 
        $params[] = $status; 
        $filterSubtitleInfo[] = "Status: " . ucwords(str_replace('_', ' ', $status));
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT sa.*, s.name, s.nis, g.grade, cg.name AS class_name, a.name AS activity_name
        FROM student_attendances sa
        INNER JOIN students s ON s.id = sa.student_id
        INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
        INNER JOIN class_groups cg ON cg.id = se.class_group_id
        INNER JOIN grades g ON g.id = cg.grade_id
        LEFT JOIN activities a ON a.id = sa.activity_id
        WHERE {$whereSql}
        ORDER BY sa.attendance_date DESC, g.sort_order, cg.name, s.name
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    // LAPORAN STAFF
    $unitId = (int)($_GET['unit_id'] ?? 0);
    $where = [
        "st.deleted_at IS NULL",
        "sta.attendance_date BETWEEN ? AND ?"
    ];
    $params = [$dateStart, $dateEnd];

    if ($role === 'kepala_sekolah') {
        $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
        $stmtKs->execute([$userId]);
        $ksUnitId = $stmtKs->fetchColumn();
        $where[] = "st.unit_id = ?";
        $params[] = $ksUnitId;
        $uName = $pdo->prepare("SELECT unit FROM units WHERE id = ?");
        $uName->execute([$ksUnitId]);
        if ($un = $uName->fetchColumn()) {
            $filterSubtitleInfo[] = "Unit: $un";
        }
    } elseif ($role === 'super_admin' && $unitId > 0) {
        $where[] = "st.unit_id = ?";
        $params[] = $unitId;
        $uName = $pdo->prepare("SELECT unit FROM units WHERE id = ?");
        $uName->execute([$unitId]);
        if ($un = $uName->fetchColumn()) {
            $filterSubtitleInfo[] = "Unit: $un";
        }
    }

    if ($name !== '') { 
        $where[] = "st.name LIKE ?"; 
        $params[] = "%{$name}%"; 
        $filterSubtitleInfo[] = "Nama: $name";
    }
    if ($status !== '') { 
        $where[] = "sta.status = ?"; 
        $params[] = $status; 
        $filterSubtitleInfo[] = "Status: " . ucwords(str_replace('_', ' ', $status));
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT sta.*, st.name, st.nik, u.unit AS unit_name, a.name AS activity_name
        FROM staff_attendances sta
        INNER JOIN staff st ON st.id = sta.staff_id
        LEFT JOIN units u ON u.id = st.unit_id
        LEFT JOIN activities a ON a.id = sta.activity_id
        WHERE {$whereSql}
        ORDER BY sta.attendance_date DESC, sta.time_in DESC, st.name
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// Hitung ringkasan total global
$totTepat = 0; $totTelat = 0; $totIzin = 0; $totSakit = 0; $totAlpha = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'tepat_waktu') $totTepat++;
    elseif ($r['status'] === 'terlambat') $totTelat++;
    elseif ($r['status'] === 'izin') $totIzin++;
    elseif ($r['status'] === 'sakit') $totSakit++;
    else $totAlpha++;
}

if ($mode === 'recap') {
    $filePrefix = $type === 'staff' ? 'rekap_kehadiran_staff_' : 'rekap_kehadiran_siswa_';
    $reportTitle = $type === 'staff' ? 'REKAPITULASI JUMLAH KEHADIRAN STAFF' : 'REKAPITULASI JUMLAH KEHADIRAN SISWA';
} else {
    $filePrefix = $type === 'staff' ? 'laporan_absensi_staff_' : 'laporan_absensi_siswa_';
    $reportTitle = $type === 'staff' ? 'LAPORAN ABSENSI STAFF' : 'LAPORAN ABSENSI SISWA';
}

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filePrefix . $dateStart . '_sampai_' . $dateEnd . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');
?>
<html>
<head>
<meta charset="UTF-8">
</head>
<body>
    <h2 style="text-align: center; font-family: Arial, sans-serif; color: #0f172a; margin-bottom: 2px;"><?= $reportTitle ?></h2>
    <h4 style="text-align: center; font-family: Arial, sans-serif; color: #0284c7; margin-top: 0; margin-bottom: 10px;">AN NAHL ISLAMIC SCHOOL</h4>
    <p style="text-align: center; font-family: Arial, sans-serif; font-size: 12px; color: #475569;">
        Periode: <strong><?= e($dateStart) ?></strong> s/d <strong><?= e($dateEnd) ?></strong>
        <?php if (!empty($filterSubtitleInfo)): ?>
            &bull; <?= e(implode(' &bull; ', $filterSubtitleInfo)) ?>
        <?php endif; ?>
    </p>

    <!-- Kotak Ringkasan Total -->
    <table border="1" cellpadding="6" cellspacing="0" style="margin-bottom: 15px; font-family: Arial, sans-serif; font-size: 12px; background-color: #f8fafc;">
        <tr style="font-weight: bold; color: #334155;">
            <td style="color: #15803d;">Tepat Waktu: <?= $totTepat ?></td>
            <td style="color: #b91c1c;">Terlambat: <?= $totTelat ?></td>
            <td style="color: #1d4ed8;">Izin: <?= $totIzin ?></td>
            <td style="color: #a16207;">Sakit: <?= $totSakit ?></td>
            <td style="color: #475569;">Tidak Absen: <?= $totAlpha ?></td>
            <td style="color: #4338ca;">Total Hadir: <?= $totTepat + $totTelat ?></td>
        </tr>
    </table>

    <?php if ($mode === 'recap'): ?>
        <?php
        $recapRows = [];
        if ($type === 'student' && isset($classId) && $classId > 0) {
            $stmtStudents = $pdo->prepare("
                SELECT s.id, s.name, s.nis, g.grade, cg.name AS class_name
                FROM student_enrollments se
                INNER JOIN students s ON s.id = se.student_id AND s.deleted_at IS NULL
                INNER JOIN class_groups cg ON cg.id = se.class_group_id
                INNER JOIN grades g ON g.id = cg.grade_id
                WHERE se.class_group_id = ? AND se.status = 'active'
                ORDER BY s.name ASC
            ");
            $stmtStudents->execute([$classId]);
            foreach ($stmtStudents->fetchAll(PDO::FETCH_ASSOC) as $st) {
                $recapRows[$st['id']] = [
                    'id'           => $st['id'],
                    'name'         => $st['name'],
                    'identifier'   => $st['nis'] ?: '-',
                    'group_name'   => $st['grade'] . ' - ' . $st['class_name'],
                    'tepat_waktu'  => 0,
                    'terlambat'    => 0,
                    'izin'         => 0,
                    'sakit'        => 0,
                    'tidak_absen'  => 0,
                    'total_hadir'  => 0,
                    'total_hari'   => 0
                ];
            }
        } elseif ($type === 'staff' && isset($unitId) && $unitId > 0) {
            $stmtStaffList = $pdo->prepare("
                SELECT st.id, st.name, st.nik, u.unit AS unit_name
                FROM staff st
                LEFT JOIN units u ON u.id = st.unit_id
                WHERE st.unit_id = ? AND st.deleted_at IS NULL
                ORDER BY st.name ASC
            ");
            $stmtStaffList->execute([$unitId]);
            foreach ($stmtStaffList->fetchAll(PDO::FETCH_ASSOC) as $st) {
                $recapRows[$st['id']] = [
                    'id'           => $st['id'],
                    'name'         => $st['name'],
                    'identifier'   => $st['nik'] ?: '-',
                    'group_name'   => $st['unit_name'] ?: '-',
                    'tepat_waktu'  => 0,
                    'terlambat'    => 0,
                    'izin'         => 0,
                    'sakit'        => 0,
                    'tidak_absen'  => 0,
                    'total_hadir'  => 0,
                    'total_hari'   => 0
                ];
            }
        }

        foreach ($rows as $r) {
            $personKey = ($type === 'student') ? $r['student_id'] : $r['staff_id'];
            if (!isset($recapRows[$personKey])) {
                $recapRows[$personKey] = [
                    'id'           => $personKey,
                    'name'         => $r['name'],
                    'identifier'   => ($type === 'student') ? ($r['nis'] ?? '-') : ($r['nik'] ?? '-'),
                    'group_name'   => ($type === 'student') ? (($r['grade'] ?? '') . ' - ' . ($r['class_name'] ?? '')) : ($r['unit_name'] ?? '-'),
                    'tepat_waktu'  => 0,
                    'terlambat'    => 0,
                    'izin'         => 0,
                    'sakit'        => 0,
                    'tidak_absen'  => 0,
                    'total_hadir'  => 0,
                    'total_hari'   => 0
                ];
            }

            $st = $r['status'] ?? '';
            if (isset($recapRows[$personKey][$st])) {
                $recapRows[$personKey][$st]++;
            }
            if ($st === 'tepat_waktu' || $st === 'terlambat') {
                $recapRows[$personKey]['total_hadir']++;
            }
            $recapRows[$personKey]['total_hari']++;
        }

        usort($recapRows, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $grandTepat = 0; $grandTelat = 0; $grandIzin = 0; $grandSakit = 0; $grandAlpha = 0; $grandHadir = 0; $grandHari = 0;
        ?>

        <!-- TABEL EXCEL REKAP -->
        <table border="1" cellpadding="6" cellspacing="0" style="font-family: Arial, sans-serif; font-size: 11px;">
            <tr style="background-color: #0284c7; color: white; font-weight: bold; text-align: center;">
                <th>No</th>
                <th><?= $type === 'staff' ? 'NIK' : 'NIS' ?></th>
                <th>Nama <?= $type === 'staff' ? 'Staff' : 'Siswa' ?></th>
                <th><?= $type === 'staff' ? 'Unit' : 'Subkelas' ?></th>
                <th>Tepat Waktu</th>
                <th>Terlambat</th>
                <th>Izin</th>
                <th>Sakit</th>
                <th>Tidak Absen</th>
                <th>Total Hadir</th>
                <th>% Hadir</th>
            </tr>
            <?php foreach ($recapRows as $idx => $person): 
                $grandTepat += $person['tepat_waktu'];
                $grandTelat += $person['terlambat'];
                $grandIzin  += $person['izin'];
                $grandSakit += $person['sakit'];
                $grandAlpha += $person['tidak_absen'];
                $grandHadir += $person['total_hadir'];
                $grandHari  += $person['total_hari'];
                $pct = $person['total_hari'] > 0 ? round(($person['total_hadir'] / $person['total_hari']) * 100, 1) : 0;
            ?>
                <tr>
                    <td style="text-align: center;"><?= $idx + 1 ?></td>
                    <td style="text-align: center; mso-number-format:'\@';"><?= e($person['identifier']) ?></td>
                    <td><strong><?= e($person['name']) ?></strong></td>
                    <td style="text-align: center;"><?= e($person['group_name']) ?></td>
                    <td style="text-align: center; font-weight: bold; color: #15803d;"><?= $person['tepat_waktu'] ?></td>
                    <td style="text-align: center; font-weight: bold; color: #b91c1c;"><?= $person['terlambat'] ?></td>
                    <td style="text-align: center; font-weight: bold; color: #1d4ed8;"><?= $person['izin'] ?></td>
                    <td style="text-align: center; font-weight: bold; color: #a16207;"><?= $person['sakit'] ?></td>
                    <td style="text-align: center; font-weight: bold; color: #475569;"><?= $person['tidak_absen'] ?></td>
                    <td style="text-align: center; font-weight: bold; color: #4338ca;"><?= $person['total_hadir'] ?></td>
                    <td style="text-align: center; font-weight: bold;"><?= $pct ?>%</td>
                </tr>
            <?php endforeach; ?>
            <tr style="background-color: #f1f5f9; font-weight: bold; text-align: center;">
                <td colspan="4" style="text-align: right;">TOTAL KESELURUHAN:</td>
                <td style="color: #15803d;"><?= $grandTepat ?></td>
                <td style="color: #b91c1c;"><?= $grandTelat ?></td>
                <td style="color: #1d4ed8;"><?= $grandIzin ?></td>
                <td style="color: #a16207;"><?= $grandSakit ?></td>
                <td style="color: #475569;"><?= $grandAlpha ?></td>
                <td style="color: #4338ca;"><?= $grandHadir ?></td>
                <td><?= $grandHari > 0 ? round(($grandHadir / $grandHari) * 100, 1) : 0 ?>%</td>
            </tr>
        </table>

    <?php else: ?>
        <!-- TABEL EXCEL DETAIL HARIAN -->
        <table border="1" cellpadding="5" cellspacing="0" style="font-family: Arial, sans-serif; font-size: 11px;">
            <tr style="background-color: #0284c7; color: white; font-weight: bold; text-align: center;">
                <th>No</th>
                <th>Tanggal</th>
                <th><?= $type === 'staff' ? 'Nama Staff' : 'Nama Siswa' ?></th>
                <th><?= $type === 'staff' ? 'NIK' : 'NIS' ?></th>
                <th><?= $type === 'staff' ? 'Unit' : 'Subkelas' ?></th>
                <th>Kegiatan</th>
                <th>Jam Masuk</th>
                <th>Jam Keluar</th>
                <th>Status</th>
                <th>Keterangan</th>
            </tr>
            <?php if (!$rows): ?>
            <tr>
                <td colspan="10" style="text-align: center; color: #64748b;">Tidak ada data absensi pada periode ini.</td>
            </tr>
            <?php endif; ?>
            <?php foreach ($rows as $index => $row): 
                $statusMap = [
                    'tepat_waktu' => 'Tepat Waktu',
                    'terlambat' => 'Terlambat',
                    'izin' => 'Izin',
                    'sakit' => 'Sakit',
                    'tidak_absen' => 'Tidak Absen'
                ];
                $stText = $statusMap[$row['status']] ?? ucwords((string)($row['status'] ?? ''));
                $keteranganText = $row['keterangan'] ?? $row['description'] ?? '-';
                if (trim((string)$keteranganText) === '') $keteranganText = '-';
            ?>
            <tr style="background-color: #ffffff; color: #0f172a;">
                <td style="text-align: center;"><?= $index + 1 ?></td>
                <td style="text-align: center;"><?= e($row['attendance_date']) ?></td>
                <td><strong><?= e($row['name'] ?? '-') ?></strong></td>
                <td style="text-align: center; mso-number-format:'\@';"><?= e($type === 'staff' ? ($row['nik'] ?? '-') : ($row['nis'] ?? '-')) ?></td>
                <td style="text-align: center;"><?= e($type === 'staff' ? ($row['unit_name'] ?? '-') : (($row['grade'] ?? '') . ' - ' . ($row['class_name'] ?? ''))) ?></td>
                <td><?= e($row['activity_name'] ?? '-') ?></td>
                <td style="text-align: center;"><?= e($row['time_in'] ?? '-') ?></td>
                <td style="text-align: center;"><?= e($row['time_out'] ?? '-') ?></td>
                <td style="text-align: center;"><?= e($stText) ?></td>
                <td><?= e($keteranganText) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>
</html>