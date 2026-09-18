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

$userId = currentUserId();
$type = $_GET['type'] ?? 'student'; // Default ke laporan siswa

// JIKA ROLE ADMIN INGIN MENCOBA AKSES LAPORAN STAFF, TOLAK / LEMPAR KEMBALI KE SISWA
if ($role === 'admin' && $type === 'staff') {
    flash('error', 'Anda tidak memiliki hak akses untuk melihat laporan staff.');
    redirect('index.php?type=student');
}

$pageTitle = $type === 'staff' ? 'Laporan Absensi Staff' : 'Laporan Absensi Siswa';

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

// Hitung Statistik Ringkasan
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

$queryParams = [
    'type' => $type,
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

<div class="card">
    <div class="card-header">
        <div>
            <h3>Laporan Absensi <?= $type === 'staff' ? 'Staff' : 'Siswa' ?></h3>
            <small>Periode: <strong><?= e($dateStart) ?></strong> s/d <strong><?= e($dateEnd) ?></strong></small>
        </div>
        
        <!-- TOMBOL NAVIGASI LIHAT SISWA & STAFF (Admin tidak melihat tombol staff) -->
        <div style="display:flex; gap:10px;">
            <a href="index.php?type=student" class="btn <?= $type === 'student' ? 'btn-primary' : 'btn-success' ?>">👤 Lihat Siswa</a>
            <?php if ($role !== 'admin'): ?>
                <a href="index.php?type=staff" class="btn <?= $type === 'staff' ? 'btn-primary' : 'btn-success' ?>">👔 Lihat Staff</a>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;">
        <a href="?type=<?= $type ?>&preset=today" class="btn btn-success">Hari Ini</a>
        <a href="?type=<?= $type ?>&preset=week" class="btn btn-success">Minggu Ini</a>
        <a href="?type=<?= $type ?>&preset=month" class="btn btn-success">Bulan Ini</a>
    </div>

    <form method="GET">
        <input type="hidden" name="type" value="<?= e($type) ?>">
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
                        <option value="0">Semua Subkelas</option>
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
                            <option value="0">Semua Unit</option>
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
                <button class="btn btn-primary" type="submit" style="margin-top:22px;">🔎 Tampilkan</button>
            </div>
        </div>
    </form>
</div>

<!-- KOTAK REKAPITULASI TOTAL -->
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
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
        <a href="<?= $type === 'staff' ? 'excel_staff.php' : 'excel.php' ?>?<?= e($exportQuery) ?>" class="btn btn-success">📊 Export Excel</a>
        <a href="<?= $type === 'staff' ? 'pdf_staff.php' : 'pdf.php' ?>?<?= e($exportQuery) ?>" class="btn btn-primary">📄 Export PDF</a>
    </div>

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
                    <td><?= e($row['description'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>