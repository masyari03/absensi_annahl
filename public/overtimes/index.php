<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// IZINKAN SUPER ADMIN & KEPALA SEKOLAH
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['super_admin', 'kepala_sekolah'])) {
    redirect('../dashboard.php');
}

$pageTitle = 'Laporan Lembur Staff';
$userId = currentUserId();

$filterYear = (int)($_GET['filter_year'] ?? date('Y'));
$filterMonth = (int)($_GET['filter_month'] ?? date('n'));
$nameFilter = trim($_GET['name'] ?? '');

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

// Chart Logika
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $filterMonth, $filterYear);
$chartLabels = [];
$chartDataArr = [];
for ($i = 1; $i <= $daysInMonth; $i++) { $chartLabels[] = $i; $chartDataArr[$i] = 0; }

$chartQueryParams = [$filterYear, $filterMonth];
if ($role === 'kepala_sekolah') $chartQueryParams[] = $ksUnitId;

$stmtChart = $pdo->prepare("
    SELECT DAY(so.overtime_date) as day_label, COUNT(so.id) as total
    FROM staff_overtimes so
    JOIN staff s ON s.id = so.staff_id
    WHERE YEAR(so.overtime_date) = ? AND MONTH(so.overtime_date) = ? {$unitFilterSql}
    GROUP BY DAY(so.overtime_date)
");
$stmtChart->execute($chartQueryParams);
foreach ($stmtChart->fetchAll() as $row) {
    $chartDataArr[(int)$row['day_label']] = (int)$row['total'];
}
$jsLabels = json_encode(array_values($chartLabels));
$jsData = json_encode(array_values($chartDataArr));

// Tabel Logika
$where = ["YEAR(so.overtime_date) = ?", "MONTH(so.overtime_date) = ?"];
$params = [$filterYear, $filterMonth];

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
        s.name, s.nik, s.photo,
        a.staff_out as normal_out
    FROM staff_overtimes so
    JOIN staff s ON s.id = so.staff_id
    JOIN activities a ON a.id = so.activity_id
    WHERE {$whereSql}
    ORDER BY so.overtime_date DESC, s.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$overtimes = $stmt->fetchAll();

$queryParams = http_build_query(['filter_year' => $filterYear, 'filter_month' => $filterMonth, 'name' => $nameFilter]);
require '../../includes/header.php';
?>
    
<div class="card">
    <div class="card-header">
        <div>
            <h3>Grafik Lembur Staff</h3>
            <small>Periode Bulan <?= $filterMonth ?> Tahun <?= $filterYear ?> <?= $role === 'kepala_sekolah' ? '(Khusus Unit Anda)' : '' ?></small>
        </div>
    </div>
    
    <form method="GET" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 20px;">
        <div class="filter-grid">
            <div class="form-group">
                <label>Nama Staff</label>
                <input type="text" name="name" value="<?= e($nameFilter) ?>" placeholder="Cari nama staff..." style="width: 100%; padding: 6px; box-sizing: border-box;">
            </div>

            <div class="form-group">
                <label>Pilih Bulan</label>
                <select name="filter_month" onchange="this.form.submit()" style="width: 100%; padding: 6px; box-sizing: border-box;">
                    <?php 
                    $namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                    foreach($namaBulan as $index => $nama): 
                        $num = $index + 1;
                    ?>
                        <option value="<?= $num ?>" <?= $filterMonth == $num ? 'selected' : '' ?>><?= $nama ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Tahun</label>
                <select name="filter_year" onchange="this.form.submit()" style="width: 100%; padding: 6px; box-sizing: border-box;">
                    <?php for($y = date('Y'); $y >= 2023; $y--): ?>
                        <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group" style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn btn-primary" style="padding: 6px 12px; height: max-content;">Cari</button>
            </div>
        </div>
    </form>
    
    <div style="padding: 10px;">
        <canvas id="overtimeChart" height="100"></canvas>
    </div>
</div>

<div class="card">
    <div class="card-header" style="justify-content: space-between;">
        <h3>Data Rekam Lembur Staff</h3>
        <div style="display: flex; gap: 10px;">
            <a href="excel.php?<?= $queryParams ?>" class="btn btn-success">📊 Export Excel</a>
            <a href="pdf.php?<?= $queryParams ?>" target="_blank" class="btn btn-danger">📄 Export PDF</a>
        </div>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Foto</th>
                    <th>Nama Staff</th>
                    <th>NIK</th>
                    <th>Batas Normal</th>
                    <th>Jam Absen (Lembur)</th>
                    <th>Durasi Lembur</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$overtimes): ?>
                <tr><td colspan="8" style="text-align:center;">Tidak ada data lembur pada periode ini.</td></tr>
                <?php endif; ?>
                <?php foreach ($overtimes as $index => $row): ?>
                    <?php
                        $normalOut = strtotime($row['normal_out']);
                        $actualOut = strtotime($row['time_out']);
                        $durasi = max(0, $actualOut - $normalOut);
                        $jam = floor($durasi / 3600);
                        $menit = floor(($durasi % 3600) / 60);
                    ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= date('d M Y', strtotime($row['overtime_date'])) ?></td>
                    <td>
                        <?php if (!empty($row['photo'])): ?>
                            <img src="../../uploads/staff/<?= e($row['photo']) ?>" width="40" height="40" style="object-fit:cover; border-radius:8px;">
                        <?php else: ?>
                            <div class="avatar" style="width:40px;height:40px;line-height:40px;font-size:16px; background:#e2e8f0; text-align:center; border-radius:8px; font-weight:bold;"><?= strtoupper(substr($row['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($row['name']) ?></strong></td>
                    <td><?= e($row['nik']) ?></td>
                    <td><span class="badge badge-success"><?= e($row['normal_out']) ?></span></td>
                    <td><span class="badge badge-danger"><?= e($row['time_out']) ?></span></td>
                    <td><strong><?= $jam ?> Jam <?= $menit ?> Menit</strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
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
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } }
    }
});
</script>
<?php require '../../includes/footer.php'; ?>