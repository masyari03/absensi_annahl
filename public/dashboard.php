<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/security.php';
require_once '../config/config.php';
require_once '../includes/functions.php';

requireLogin();
$pageTitle = 'Dashboard';
$role = $_SESSION['role'];
$userId = currentUserId();

/*
|--------------------------------------------------------------------------
| Statistik Chart (Filter Hari, Bulan, Tahun)
|--------------------------------------------------------------------------
*/
$chartType = $_GET['chart_type'] ?? 'student'; 
$filterMode = $_GET['filter_mode'] ?? 'year'; 
$filterYear = (int)($_GET['filter_year'] ?? date('Y'));
$filterMonth = (int)($_GET['filter_month'] ?? date('n'));

$chartLabels = [];
$chartOntime = [];
$chartLate = [];

$tableTarget = $chartType === 'student' ? 'student_attendances' : 'staff_attendances';

// Menyusun Dynamic Join dan Where berdasarkan Role
$joinSql = "";
$whereSql = "WHERE YEAR(sa.attendance_date) = :year";
$queryParams = [':year' => $filterYear];

if ($filterMode === 'month') {
    $whereSql .= " AND MONTH(sa.attendance_date) = :month";
    $queryParams[':month'] = $filterMonth;
}

if ($role === 'kepala_sekolah') {
    if ($chartType === 'student') {
        $joinSql = "INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
                    INNER JOIN class_groups cg ON cg.id = se.class_group_id
                    INNER JOIN grades g ON g.id = cg.grade_id";
        $whereSql .= " AND g.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = :ks_id)";
        $queryParams[':ks_id'] = $userId;
    } else {
        $joinSql = "INNER JOIN staff st ON st.id = sa.staff_id";
        $whereSql .= " AND st.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = :ks_id)";
        $queryParams[':ks_id'] = $userId;
    }
} elseif ($role === 'admin') {
    if ($chartType === 'student') {
        $joinSql = "INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
                    INNER JOIN class_groups cg ON cg.id = se.class_group_id
                    INNER JOIN grades g ON g.id = cg.grade_id";
        $whereSql .= " AND (g.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = :adm_id1) 
                       OR cg.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = :adm_id2))";
        $queryParams[':adm_id1'] = $userId;
        $queryParams[':adm_id2'] = $userId;
    } else {
        $whereSql .= " AND 1 = 0"; // Admin tidak dapat melihat chart staff
    }
}

// Menyiapkan Labels & Default Data array (0)
if ($filterMode === 'month') {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $filterMonth, $filterYear);
    for ($i = 1; $i <= $daysInMonth; $i++) {
        $chartLabels[] = $i;
        $chartOntime[$i] = 0;
        $chartLate[$i] = 0;
    }
    $selectLabel = "DAY(sa.attendance_date)";
} else {
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    foreach ($months as $i => $m) {
        $chartLabels[] = $m;
        $chartOntime[$i+1] = 0;
        $chartLate[$i+1] = 0;
    }
    $selectLabel = "MONTH(sa.attendance_date)";
}

$stmtChart = $pdo->prepare("
    SELECT {$selectLabel} as label, sa.status, COUNT(sa.id) as total 
    FROM {$tableTarget} sa
    {$joinSql}
    {$whereSql}
    GROUP BY label, sa.status
");
$stmtChart->execute($queryParams);
$chartData = $stmtChart->fetchAll();

foreach ($chartData as $row) {
    $idx = (int)$row['label'];
    if ($row['status'] === 'tepat_waktu') {
        $chartOntime[$idx] = (int)$row['total'];
    } else {
        $chartLate[$idx] = (int)$row['total'];
    }
}

$jsLabels = json_encode(array_values($chartLabels));
$jsOntime = json_encode(array_values($chartOntime));
$jsLate = json_encode(array_values($chartLate));

/*
|--------------------------------------------------------------------------
| Counter Atas
|--------------------------------------------------------------------------
*/
if ($role === 'super_admin') {
    $totalStudents = $pdo->query("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL")->fetchColumn();
    $totalStaff = $pdo->query("SELECT COUNT(*) FROM staff WHERE deleted_at IS NULL")->fetchColumn();
    $todayTotal = $pdo->query("SELECT COUNT(*) FROM student_attendances WHERE attendance_date = CURDATE()")->fetchColumn();
    $todayLate = $pdo->query("SELECT COUNT(*) FROM student_attendances WHERE attendance_date = CURDATE() AND status = 'terlambat'")->fetchColumn();
} elseif ($role === 'kepala_sekolah') {
    $stmt1 = $pdo->prepare("SELECT COUNT(DISTINCT s.id) FROM students s JOIN student_enrollments se ON se.student_id = s.id JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id JOIN academic_years ay ON ay.id = se.academic_year_id WHERE s.deleted_at IS NULL AND ay.status = 'active' AND g.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?)");
    $stmt1->execute([$userId]); $totalStudents = $stmt1->fetchColumn();
    
    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE deleted_at IS NULL AND unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?)");
    $stmt2->execute([$userId]); $totalStaff = $stmt2->fetchColumn();
    
    $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM student_attendances sa JOIN student_enrollments se ON se.id = sa.enrollment_id JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id WHERE sa.attendance_date = CURDATE() AND g.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?)");
    $stmt3->execute([$userId]); $todayTotal = $stmt3->fetchColumn();
    
    $stmt4 = $pdo->prepare("SELECT COUNT(*) FROM student_attendances sa JOIN student_enrollments se ON se.id = sa.enrollment_id JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id WHERE sa.attendance_date = CURDATE() AND sa.status = 'terlambat' AND g.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?)");
    $stmt4->execute([$userId]); $todayLate = $stmt4->fetchColumn();
} else { // admin
    $stmt1 = $pdo->prepare("SELECT COUNT(DISTINCT s.id) FROM students s JOIN student_enrollments se ON se.student_id = s.id JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id JOIN academic_years ay ON ay.id = se.academic_year_id WHERE s.deleted_at IS NULL AND ay.status = 'active' AND (g.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?) OR cg.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?))");
    $stmt1->execute([$userId, $userId]); $totalStudents = $stmt1->fetchColumn();
    
    $totalStaff = 0;

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM student_attendances sa JOIN student_enrollments se ON se.id = sa.enrollment_id JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id WHERE sa.attendance_date = CURDATE() AND (g.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?) OR cg.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?))");
    $stmt2->execute([$userId, $userId]); $todayTotal = $stmt2->fetchColumn();

    $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM student_attendances sa JOIN student_enrollments se ON se.id = sa.enrollment_id JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id WHERE sa.attendance_date = CURDATE() AND sa.status = 'terlambat' AND (g.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?) OR cg.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?))");
    $stmt3->execute([$userId, $userId]); $todayLate = $stmt3->fetchColumn();
}

require '../includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">👨‍🎓</div>
        <h3><?= number_format($totalStudents) ?></h3>
        <p>Total Siswa</p>
    </div>
    <?php if (in_array($role, ['super_admin', 'kepala_sekolah'])): ?>
    <div class="stat-card">
        <div class="stat-icon">👨‍🏫</div>
        <h3><?= number_format($totalStaff) ?></h3>
        <p>Staff / Guru</p>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-icon">✓</div>
        <h3><?= number_format($todayTotal) ?></h3>
        <p>Absen Siswa Hari Ini</p>
    </div>
    <div class="stat-card">
        <div class="stat-icon">⚠</div>
        <h3><?= number_format($todayLate) ?></h3>
        <p>Siswa Telat Hari Ini</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Statistik Kehadiran</h3>
            <small>Data akurat berdasarkan absensi harian</small>
        </div>
    </div>
    
    <form method="GET" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 20px;">
        <div class="filter-grid">
            <?php if (in_array($role, ['super_admin', 'kepala_sekolah'])): ?>
            <div class="form-group">
                <label>Tipe Data</label>
                <select name="chart_type" onchange="this.form.submit()">
                    <option value="student" <?= $chartType === 'student' ? 'selected' : '' ?>>Siswa</option>
                    <option value="staff" <?= $chartType === 'staff' ? 'selected' : '' ?>>Staff / Guru</option>
                </select>
            </div>
            <?php else: ?>
                <input type="hidden" name="chart_type" value="student">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Tampilan Grafik</label>
                <select name="filter_mode" onchange="this.form.submit()">
                    <option value="year" <?= $filterMode === 'year' ? 'selected' : '' ?>>Grafik Bulanan (1 Tahun)</option>
                    <option value="month" <?= $filterMode === 'month' ? 'selected' : '' ?>>Grafik Harian (1 Bulan)</option>
                </select>
            </div>

            <?php if ($filterMode === 'month'): ?>
            <div class="form-group">
                <label>Pilih Bulan</label>
                <select name="filter_month" onchange="this.form.submit()">
                    <?php 
                    $namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                    foreach($namaBulan as $index => $nama): 
                        $num = $index + 1;
                    ?>
                        <option value="<?= $num ?>" <?= $filterMonth == $num ? 'selected' : '' ?>><?= $nama ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Tahun</label>
                <select name="filter_year" onchange="this.form.submit()">
                    <?php for($y = date('Y'); $y >= 2023; $y--): ?>
                        <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
    </form>
    
    <div style="padding: 10px;">
        <canvas id="attendanceChart" height="100"></canvas>
    </div>
</div>

<script>
const ctx = document.getElementById('attendanceChart');
new Chart(ctx, {
    type: 'bar', 
    data: {
        labels: <?= $jsLabels ?>,
        datasets: [
            {
                label: 'Tepat Waktu',
                data: <?= $jsOntime ?>,
                backgroundColor: '#10b981',
                borderRadius: 4
            },
            {
                label: 'Terlambat',
                data: <?= $jsLate ?>,
                backgroundColor: '#ef4444',
                borderRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1, precision: 0 }
            }
        }
    }
});
</script>

<?php require '../includes/footer.php'; ?>