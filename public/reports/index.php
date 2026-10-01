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
$type = $_GET['type'] ?? 'student'; // Default ke laporan siswa
$mode = in_array($_GET['mode'] ?? '', ['detail', 'recap']) ? $_GET['mode'] : 'detail'; // Mode Tampilan: Detail vs Rekap Total

// JIKA ROLE ADMIN INGIN MENCOBA AKSES LAPORAN STAFF, TOLAK / LEMPAR KEMBALI KE SISWA
if ($role === 'admin' && $type === 'staff') {
    flash('error', 'Anda tidak memiliki hak akses untuk melihat laporan staff.');
    redirect('index.php?type=student');
}

$pageTitle = ($type === 'staff' ? 'Laporan Absensi Staff' : 'Laporan Absensi Siswa') . ($mode === 'recap' ? ' - Rekap Total' : ' - Detail Harian');

$period = getReportPeriod();
$dateStart = $period['start'];
$dateEnd = $period['end'];

$name = trim($_GET['name'] ?? '');
$status = $_GET['status'] ?? '';

if ($type === 'student') {
    $gradeId = (int)($_GET['grade_id'] ?? 0);
    $classId = (int)($_GET['class_group_id'] ?? 0);

    // Hak akses universal untuk siswa
    $access = getStudentAccessCondition('g', 'cg');

    $where = [
        "s.deleted_at IS NULL",
        "sa.attendance_date BETWEEN ? AND ?",
        $access['condition']
    ];
    $params = [$dateStart, $dateEnd];
    $params = array_merge($params, $access['params']);

    if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
    if ($gradeId > 0) { $where[] = "g.id = ?"; $params[] = $gradeId; }
    if ($classId > 0) { $where[] = "cg.id = ?"; $params[] = $classId; }
    if ($status !== '') { $where[] = "sa.status = ?"; $params[] = $status; }

    $whereSql = implode(' AND ', $where);

    // Dropdown filter grade & subkelas berdasarkan role
    $gradeQueryCondition = "";
    $gradeParams = [];
    $classQueryCondition = "";
    $classParams = [];

    if ($role === 'kepala_sekolah') {
        $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
        $stmtKs->execute([$userId]);
        $ksUnitId = $stmtKs->fetchColumn();
        $gradeQueryCondition = "WHERE unit_id = ?";
        $gradeParams[] = $ksUnitId;
        $classQueryCondition = "WHERE g.unit_id = ?";
        $classParams[] = $ksUnitId;
    } elseif ($role === 'admin') {
        $gradeQueryCondition = "WHERE id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?)";
        $gradeParams[] = $userId;
        $classQueryCondition = "WHERE cg.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?)";
        $classParams[] = $userId;
    }

    $stmtGrades = $pdo->prepare("SELECT * FROM grades {$gradeQueryCondition} ORDER BY sort_order, grade");
    $stmtGrades->execute($gradeParams);
    $grades = $stmtGrades->fetchAll();

    $stmtClasses = $pdo->prepare("
        SELECT cg.id, cg.name, g.grade 
        FROM class_groups cg 
        INNER JOIN grades g ON g.id = cg.grade_id 
        {$classQueryCondition}
        ORDER BY g.sort_order, cg.name
    ");
    $stmtClasses->execute($classParams);
    $classGroups = $stmtClasses->fetchAll();

    // Query Absensi Siswa
    $stmt = $pdo->prepare("
        SELECT sa.*, s.name, s.nis, g.grade, cg.name AS class_name 
        FROM student_attendances sa
        INNER JOIN students s ON s.id = sa.student_id
        INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
        INNER JOIN class_groups cg ON cg.id = se.class_group_id
        INNER JOIN grades g ON g.id = cg.grade_id
        WHERE {$whereSql}
        ORDER BY sa.attendance_date DESC, sa.time_in DESC, s.name
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

    // Batasan akses staff untuk Kepala Sekolah (hanya unit miliknya)
    if ($role === 'kepala_sekolah') {
        $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
        $stmtKs->execute([$userId]);
        $ksUnitId = $stmtKs->fetchColumn();
        $where[] = "st.unit_id = ?";
        $params[] = $ksUnitId;
    } elseif ($role === 'super_admin' && $unitId > 0) {
        $where[] = "st.unit_id = ?";
        $params[] = $unitId;
    }

    if ($name !== '') { $where[] = "st.name LIKE ?"; $params[] = "%{$name}%"; }
    if ($status !== '') { $where[] = "sta.status = ?"; $params[] = $status; }

    $whereSql = implode(' AND ', $where);

    $units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

    $stmt = $pdo->prepare("
        SELECT sta.*, st.name, st.nik, u.unit AS unit_name
        FROM staff_attendances sta
        INNER JOIN staff st ON st.id = sta.staff_id
        LEFT JOIN units u ON u.id = st.unit_id
        WHERE {$whereSql}
        ORDER BY sta.attendance_date DESC, sta.time_in DESC, st.name
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// 1. Hitung Statistik Ringkasan Keseluruhan
$stats = [
    'tepat_waktu' => 0,
    'terlambat' => 0,
    'izin' => 0,
    'sakit' => 0,
    'tidak_absen' => 0
];
foreach ($rows as $r) {
    if (isset($stats[$r['status']])) {
        $stats[$r['status']]++;
    }
}

// 2. Susun Data Rekapitulasi Per Orang (Siswa / Staff)
$recapRows = [];

// Jika filter subkelas / unit tertentu aktif, inisialisasi daftar siswa/staff aktif agar yang belum pernah absen tetap tercatat
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

// Akumulasi data kehadiran harian ke masing-masing person
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

// Urutkan berdasarkan nama
usort($recapRows, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// Hitung Grand Total untuk Footer Rekap
$grandTepat = 0; $grandTelat = 0; $grandIzin = 0; $grandSakit = 0; $grandAlpha = 0; $grandHadir = 0; $grandHari = 0;
foreach ($recapRows as $rr) {
    $grandTepat += $rr['tepat_waktu'];
    $grandTelat += $rr['terlambat'];
    $grandIzin  += $rr['izin'];
    $grandSakit += $rr['sakit'];
    $grandAlpha += $rr['tidak_absen'];
    $grandHadir += $rr['total_hadir'];
    $grandHari  += $rr['total_hari'];
}
$grandPersen = $grandHari > 0 ? round(($grandHadir / $grandHari) * 100, 1) : 0;

// Query parameter untuk ekspor & toggle mode
$queryParams = [
    'type' => $type,
    'mode' => $mode,
    'preset' => 'custom',
    'start_date' => $dateStart,
    'end_date' => $dateEnd
];
if ($name !== '') { $queryParams['name'] = $name; }
if (isset($gradeId) && $gradeId > 0) { $queryParams['grade_id'] = $gradeId; }
if (isset($classId) && $classId > 0) { $queryParams['class_group_id'] = $classId; }
if (isset($unitId) && $unitId > 0) { $queryParams['unit_id'] = $unitId; }
if ($status !== '') { $queryParams['status'] = $status; }
$exportQuery = http_build_query($queryParams);

require '../../includes/header.php';
?>

<style>
/* TABS MODE LAPORAN */
.report-mode-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 12px;
}
.report-mode-tab {
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.report-mode-tab:hover {
    background: #f1f5f9;
    color: #1e293b;
}
.report-mode-tab.active {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
    box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
}

/* RECAP TABLE STYLING */
.table-recap th {
    text-align: center;
    white-space: nowrap;
}
.table-recap td.num-cell {
    text-align: center;
    font-weight: 700;
    font-size: 13.5px;
}
.count-badge {
    display: inline-block;
    min-width: 26px;
    padding: 2px 7px;
    border-radius: 12px;
    font-weight: 800;
    font-size: 12.5px;
    text-align: center;
}
.count-badge.tepat { background: #dcfce7; color: #15803d; }
.count-badge.telat { background: #fee2e2; color: #b91c1c; }
.count-badge.izin  { background: #dbeafe; color: #1d4ed8; }
.count-badge.sakit { background: #fef9c3; color: #a16207; }
.count-badge.alpha { background: #f1f5f9; color: #475569; }
.count-badge.hadir { background: #e0e7ff; color: #4338ca; }
</style>

<div class="card">
    <div class="card-header">
        <div>
            <h3 style="margin: 0; font-size: 20px;">
                Laporan Absensi <?= $type === 'staff' ? 'Staff' : 'Siswa' ?>
                <span style="font-size: 14px; font-weight: normal; color: #64748b;">(<?= $mode === 'recap' ? 'Mode Rekap Jumlah' : 'Mode Detail Log Harian' ?>)</span>
            </h3>
            <small>Periode: <strong><?= e($dateStart) ?></strong> s/d <strong><?= e($dateEnd) ?></strong></small>
        </div>
        
        <!-- TOMBOL NAVIGASI LIHAT SISWA & STAFF (Admin tidak melihat tombol staff) -->
        <div style="display:flex; gap:10px;">
            <a href="index.php?type=student&mode=<?= $mode ?>" class="btn <?= $type === 'student' ? 'btn-primary' : 'btn-success' ?>">👤 Lihat Siswa</a>
            <?php if ($role !== 'admin'): ?>
                <a href="index.php?type=staff&mode=<?= $mode ?>" class="btn <?= $type === 'staff' ? 'btn-primary' : 'btn-success' ?>">👔 Lihat Staff</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB MODE TAMPILAN: DETAIL HARIAN VS REKAP JUMLAH TOTAL -->
    <div class="report-mode-tabs">
        <a href="?<?= http_build_query(array_merge($queryParams, ['mode' => 'detail'])) ?>" 
           class="report-mode-tab <?= $mode === 'detail' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-check"></i> Detail Log Harian (Jam Masuk & Pulang)
        </a>
        <a href="?<?= http_build_query(array_merge($queryParams, ['mode' => 'recap'])) ?>" 
           class="report-mode-tab <?= $mode === 'recap' ? 'active' : '' ?>">
            <i class="fa-solid fa-calculator"></i> Rekap Jumlah Kehadiran (Tepat Waktu, Telat, Izin, Sakit, Alpha)
        </a>
    </div>

    <!-- QUICK PRESET PERIOD BUTTONS -->
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;">
        <a href="?type=<?= $type ?>&mode=<?= $mode ?>&preset=today" class="btn btn-success">Hari Ini</a>
        <a href="?type=<?= $type ?>&mode=<?= $mode ?>&preset=week" class="btn btn-success">Minggu Ini</a>
        <a href="?type=<?= $type ?>&mode=<?= $mode ?>&preset=month" class="btn btn-success">Bulan Ini</a>
    </div>

    <!-- FILTER FORM (BERLAKU UNTUK SEMUA DATA ATAU SEBAGIAN DATA KELAS / UNIT) -->
    <form method="GET">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <input type="hidden" name="preset" value="custom">
        <div class="filter-grid">
            <div class="form-group">
                <label>Dari Tanggal</label>
                <input type="date" name="start_date" value="<?= e($dateStart) ?>">
            </div>
            <div class="form-group">
                <label>Sampai Tanggal</label>
                <input type="date" name="end_date" value="<?= e($dateEnd) ?>">
            </div>
            <div class="form-group">
                <label>Nama <?= $type === 'staff' ? 'Staff' : 'Siswa' ?></label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="Cari nama...">
            </div>

            <?php if ($type === 'student'): ?>
                <div class="form-group">
                    <label>Grade</label>
                    <select name="grade_id">
                        <option value="0">Semua Grade</option>
                        <?php foreach ($grades as $grade): ?>
                            <option value="<?= $grade['id'] ?>" <?= (isset($gradeId) && $gradeId == $grade['id']) ? 'selected' : '' ?>><?= e($grade['grade']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Subkelas</label>
                    <select name="class_group_id">
                        <option value="0">Semua Subkelas (Cetak Semua)</option>
                        <?php foreach ($classGroups as $class): ?>
                            <option value="<?= $class['id'] ?>" <?= (isset($classId) && $classId == $class['id']) ? 'selected' : '' ?>><?= e($class['grade']) ?> - <?= e($class['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <?php if ($role === 'super_admin'): ?>
                    <div class="form-group">
                        <label>Unit</label>
                        <select name="unit_id">
                            <option value="0">Semua Unit (Cetak Semua)</option>
                            <?php foreach ($units as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= (isset($unitId) && $unitId == $u['id']) ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">Semua Status</option>
                    <option value="tepat_waktu" <?= $status === 'tepat_waktu' ? 'selected' : '' ?>>Tepat Waktu</option>
                    <option value="terlambat" <?= $status === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                    <option value="izin" <?= $status === 'izin' ? 'selected' : '' ?>>Izin</option>
                    <option value="sakit" <?= $status === 'sakit' ? 'selected' : '' ?>>Sakit</option>
                    <option value="tidak_absen" <?= $status === 'tidak_absen' ? 'selected' : '' ?>>Tidak Absen</option>
                </select>
            </div>
            <div>
                <button class="btn btn-primary" type="submit" style="margin-top:22px;">🔎 Tampilkan Data</button>
            </div>
        </div>
    </form>
</div>

<!-- KOTAK REKAPITULASI TOTAL GLOBAL -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
    <div class="card" style="padding: 15px; text-align: center; background: #f0fdf4; border-left: 4px solid #22c55e;">
        <small style="color: #15803d; font-weight: bold;">Tepat Waktu</small>
        <h3 style="margin: 5px 0 0; color: #166534;"><?= number_format($stats['tepat_waktu']) ?></h3>
    </div>
    <div class="card" style="padding: 15px; text-align: center; background: #fef2f2; border-left: 4px solid #ef4444;">
        <small style="color: #b91c1c; font-weight: bold;">Terlambat</small>
        <h3 style="margin: 5px 0 0; color: #991b1b;"><?= number_format($stats['terlambat']) ?></h3>
    </div>
    <div class="card" style="padding: 15px; text-align: center; background: #eff6ff; border-left: 4px solid #3b82f6;">
        <small style="color: #1d4ed8; font-weight: bold;">Izin</small>
        <h3 style="margin: 5px 0 0; color: #1e40af;"><?= number_format($stats['izin']) ?></h3>
    </div>
    <div class="card" style="padding: 15px; text-align: center; background: #fefce8; border-left: 4px solid #eab308;">
        <small style="color: #a16207; font-weight: bold;">Sakit</small>
        <h3 style="margin: 5px 0 0; color: #854d0e;"><?= number_format($stats['sakit']) ?></h3>
    </div>
    <div class="card" style="padding: 15px; text-align: center; background: #f8fafc; border-left: 4px solid #64748b;">
        <small style="color: #475569; font-weight: bold;">Tidak Absen</small>
        <h3 style="margin: 5px 0 0; color: #334155;"><?= number_format($stats['tidak_absen']) ?></h3>
    </div>
</div>

<div class="card">
    <!-- TOMBOL EXPORT (SESUAI DENGAN MODE YANG AKTIF) -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:20px;">
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <a href="excel.php?<?= e($exportQuery) ?>" class="btn btn-success" style="display:inline-flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-file-excel"></i> 
                Export Excel <?= $mode === 'recap' ? '(Rekap Jumlah)' : '(Detail Harian)' ?>
            </a>
            <a href="pdf.php?<?= e($exportQuery) ?>" target="_blank" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-file-pdf"></i> 
                Cetak PDF <?= $mode === 'recap' ? '(Rekap Jumlah)' : '(Detail Harian)' ?>
            </a>
        </div>
        <div style="color: #64748b; font-size: 13px;">
            <?php if ($mode === 'recap'): ?>
                <i class="fa-solid fa-calculator" style="color: #4f46e5;"></i> Menghitung jumlah Tepat Waktu, Terlambat, Izin, Sakit, & Tidak Absen per orang.
            <?php else: ?>
                <i class="fa-solid fa-list-check" style="color: #4f46e5;"></i> Menampilkan riwayat log per tanggal & waktu absensi.
            <?php endif; ?>
        </div>
    </div>

    <!-- KONTEN TABEL SESUAI MODE -->
    <?php if ($mode === 'recap'): ?>
        <!-- =================================================================== -->
        <!-- TABEL 1: REKAPITULASI JUMLAH KEHADIRAN (HASIL SAJA)                -->
        <!-- =================================================================== -->
        <div class="table-wrapper">
            <table class="table-recap">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="width: 50px;">No</th>
                        <th style="width: 110px;"><?= $type === 'staff' ? 'NIK' : 'NIS' ?></th>
                        <th style="text-align: left;">Nama <?= $type === 'staff' ? 'Staff' : 'Siswa' ?></th>
                        <th style="width: 140px;"><?= $type === 'staff' ? 'Unit' : 'Subkelas' ?></th>
                        <th style="width: 100px; color: #15803d;">Tepat Waktu</th>
                        <th style="width: 100px; color: #b91c1c;">Terlambat</th>
                        <th style="width: 90px; color: #1d4ed8;">Izin</th>
                        <th style="width: 90px; color: #a16207;">Sakit</th>
                        <th style="width: 100px; color: #475569;">Tidak Absen</th>
                        <th style="width: 100px; color: #4338ca;">Total Hadir</th>
                        <th style="width: 90px;">% Hadir</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recapRows)): ?>
                        <tr><td colspan="11" style="text-align:center; padding: 30px; color: #94a3b8;">Tidak ada data rekapitulasi ditemukan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recapRows as $idx => $person): 
                            $pct = $person['total_hari'] > 0 ? round(($person['total_hadir'] / $person['total_hari']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td style="text-align: center;"><?= $idx + 1 ?></td>
                                <td style="text-align: center;"><code><?= e($person['identifier']) ?></code></td>
                                <td><strong><?= e($person['name']) ?></strong></td>
                                <td style="text-align: center;"><?= e($person['group_name']) ?></td>
                                <td class="num-cell">
                                    <span class="count-badge tepat"><?= $person['tepat_waktu'] ?></span>
                                </td>
                                <td class="num-cell">
                                    <span class="count-badge telat"><?= $person['terlambat'] ?></span>
                                </td>
                                <td class="num-cell">
                                    <span class="count-badge izin"><?= $person['izin'] ?></span>
                                </td>
                                <td class="num-cell">
                                    <span class="count-badge sakit"><?= $person['sakit'] ?></span>
                                </td>
                                <td class="num-cell">
                                    <span class="count-badge alpha"><?= $person['tidak_absen'] ?></span>
                                </td>
                                <td class="num-cell">
                                    <span class="count-badge hadir"><?= $person['total_hadir'] ?></span>
                                </td>
                                <td class="num-cell" style="font-weight: 800; color: <?= $pct >= 85 ? '#15803d' : ($pct >= 70 ? '#b45309' : '#b91c1c') ?>;">
                                    <?= $pct ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($recapRows)): ?>
                    <tfoot>
                        <tr style="background: #f1f5f9; font-weight: 800; border-top: 2px solid #cbd5e1;">
                            <td colspan="4" style="text-align: right; font-size: 13.5px;">TOTAL KESELURUHAN:</td>
                            <td class="num-cell"><span class="count-badge tepat" style="font-size: 13.5px; padding: 4px 10px;"><?= number_format($grandTepat) ?></span></td>
                            <td class="num-cell"><span class="count-badge telat" style="font-size: 13.5px; padding: 4px 10px;"><?= number_format($grandTelat) ?></span></td>
                            <td class="num-cell"><span class="count-badge izin" style="font-size: 13.5px; padding: 4px 10px;"><?= number_format($grandIzin) ?></span></td>
                            <td class="num-cell"><span class="count-badge sakit" style="font-size: 13.5px; padding: 4px 10px;"><?= number_format($grandSakit) ?></span></td>
                            <td class="num-cell"><span class="count-badge alpha" style="font-size: 13.5px; padding: 4px 10px;"><?= number_format($grandAlpha) ?></span></td>
                            <td class="num-cell"><span class="count-badge hadir" style="font-size: 13.5px; padding: 4px 10px;"><?= number_format($grandHadir) ?></span></td>
                            <td class="num-cell" style="font-size: 14px;"><?= $grandPersen ?>%</td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

    <?php else: ?>
        <!-- =================================================================== -->
        <!-- TABEL 2: DETAIL LOG HARIAN (WAKTU ABSEN LENGKAP)                   -->
        <!-- =================================================================== -->
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>Nama</th>
                        <th><?= $type === 'staff' ? 'NIK' : 'NIS' ?></th>
                        <?php if ($type === 'student'): ?>
                            <th>Subkelas</th>
                        <?php else: ?>
                            <th>Unit</th>
                        <?php endif; ?>
                        <th>Jam Masuk</th>
                        <th>Jam Keluar</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="9" style="text-align:center;">Tidak ada data absensi ditemukan.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $index => $row): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= e($row['attendance_date']) ?></td>
                        <td><strong><?= e($row['name']) ?></strong></td>
                        <td><?= e($type === 'staff' ? ($row['nik'] ?? '-') : ($row['nis'] ?? '-')) ?></td>
                        <td><?= e($type === 'staff' ? ($row['unit_name'] ?? '-') : ($row['class_name'] ?? '-')) ?></td>
                        <td><?= e($row['time_in'] ?? '-') ?></td>
                        <td><?= e($row['time_out'] ?? '-') ?></td>
                        <td>
                            <?php if ($row['status'] === 'tepat_waktu'): ?>
                                <span class="badge badge-success">Tepat Waktu</span>
                            <?php elseif ($row['status'] === 'terlambat'): ?>
                                <span class="badge badge-danger">Terlambat</span>
                            <?php elseif ($row['status'] === 'izin'): ?>
                                <span class="badge badge-primary" style="background:#3b82f6; color:white;">Izin</span>
                            <?php elseif ($row['status'] === 'sakit'): ?>
                                <span class="badge badge-warning" style="background:#eab308; color:white;">Sakit</span>
                            <?php else: ?>
                                <span class="badge" style="background:#64748b; color:white;">Tidak Absen</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($row['keterangan'] ?? $row['description'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require '../../includes/footer.php'; ?>