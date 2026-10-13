<?php
$pageTitle = 'Riwayat Absensi Lengkap';
require_once __DIR__ . '/includes/header.php';

$filterMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = date('Y-m');
}

// Tarik data absensi siswa untuk bulan terpilih
$stmtAtt = $pdo->prepare("
    SELECT sa.*, a.name as activity_name
    FROM student_attendances sa
    LEFT JOIN activities a ON a.id = sa.activity_id
    WHERE sa.student_id = ? AND DATE_FORMAT(sa.attendance_date, '%Y-%m') = ?
    ORDER BY sa.attendance_date DESC
");
$stmtAtt->execute([$studentId, $filterMonth]);
$attendances = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

// Hitung rekap untuk bulan terpilih
$summary = [
    'tepat_waktu' => 0,
    'terlambat'   => 0,
    'izin'        => 0,
    'sakit'       => 0,
    'alpha'       => 0,
    'total'       => count($attendances)
];
foreach ($attendances as $row) {
    if (isset($summary[$row['status']])) {
        $summary[$row['status']]++;
    }
}
$attended = $summary['tepat_waktu'] + $summary['terlambat'];
$percentage = $summary['total'] > 0 ? round(($attended / $summary['total']) * 100, 1) : 100;
?>

<!-- Filter & Header Card -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header" style="flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center;">
        <div>
            <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;">
                <i class="fa-solid fa-calendar-days" style="color: #0284c7;"></i> Riwayat Absensi Bulanan
            </h3>
            <small style="color: #64748b;">Periksa catatan kehadiran harian, jam tap masuk, dan kepulangan Anda</small>
        </div>
        
        <form method="GET" style="display: flex; gap: 10px; align-items: center;">
            <input type="month" name="month" value="<?= e($filterMonth) ?>" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-weight: 600;" onchange="this.form.submit()">
            <button type="submit" class="btn btn-primary" style="padding: 8px 14px; font-size: 13px;">Filter</button>
        </form>
    </div>

    <!-- Mini KPI Ringkasan Bulan Terpilih -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; padding: 15px 20px; background: #f8fafc; border-top: 1px solid #f1f5f9;">
        <div style="text-align: center;">
            <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">% Kehadiran</div>
            <div style="font-size: 18px; font-weight: 800; color: #0284c7;"><?= $percentage ?>%</div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Tepat Waktu</div>
            <div style="font-size: 18px; font-weight: 800; color: #16a34a;"><?= $summary['tepat_waktu'] ?></div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Terlambat</div>
            <div style="font-size: 18px; font-weight: 800; color: #d97706;"><?= $summary['terlambat'] ?></div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Izin</div>
            <div style="font-size: 18px; font-weight: 800; color: #0284c7;"><?= $summary['izin'] ?></div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Sakit</div>
            <div style="font-size: 18px; font-weight: 800; color: #7c3aed;"><?= $summary['sakit'] ?></div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Alpha</div>
            <div style="font-size: 18px; font-weight: 800; color: #dc2626;"><?= $summary['alpha'] ?></div>
        </div>
    </div>
</div>

<!-- Tabel Riwayat Absensi -->
<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">No</th>
                    <th>Hari & Tanggal</th>
                    <th>Jam Masuk</th>
                    <th>Jam Pulang</th>
                    <th>Status Kehadiran</th>
                    <th>Aktivitas / Kegiatan</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attendances)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px 10px; color: #94a3b8;">
                            <i class="fa-solid fa-calendar-xmark" style="font-size: 32px; margin-bottom: 8px;"></i>
                            <p style="margin: 0; font-size: 13.5px;">Tidak ada catatan absensi untuk bulan <?= date('F Y', strtotime($filterMonth . '-01')) ?>.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($attendances as $row): 
                        $badgeStyles = [
                            'tepat_waktu' => ['#dcfce7', '#15803d', 'fa-check', 'Hadir Tepat Waktu'],
                            'terlambat'   => ['#fef3c7', '#b45309', 'fa-clock', 'Terlambat'],
                            'izin'        => ['#e0f2fe', '#0369a1', 'fa-file-lines', 'Izin'],
                            'sakit'       => ['#ede9fe', '#6d28d9', 'fa-hospital-user', 'Sakit'],
                            'alpha'       => ['#fee2e2', '#b91c1c', 'fa-xmark', 'Tanpa Keterangan'],
                        ];
                        $b = $badgeStyles[$row['status']] ?? ['#f1f5f9', '#475569', 'fa-info', $row['status']];
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <strong><?= date('l', strtotime($row['attendance_date'])) ?></strong>, 
                                <span style="color: #64748b; font-size: 13px;"><?= date('d F Y', strtotime($row['attendance_date'])) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($row['time_in'])): ?>
                                    <span style="font-weight: 700; color: #1e293b; font-family: monospace; font-size: 13px;">
                                        <i class="fa-solid fa-arrow-right-to-bracket" style="color: #10b981; font-size: 11px;"></i>
                                        <?= date('H:i:s', strtotime($row['time_in'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['time_out'])): ?>
                                    <span style="font-weight: 700; color: #1e293b; font-family: monospace; font-size: 13px;">
                                        <i class="fa-solid fa-arrow-right-from-bracket" style="color: #0284c7; font-size: 11px;"></i>
                                        <?= date('H:i:s', strtotime($row['time_out'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; background: <?= $b[0] ?>; color: <?= $b[1] ?>;">
                                    <i class="fa-solid <?= $b[2] ?>"></i> <?= $b[3] ?>
                                </span>
                            </td>
                            <td>
                                <?= !empty($row['activity_name']) ? e($row['activity_name']) : '<span style="color: #94a3b8; font-size: 12px;">Reguler</span>' ?>
                            </td>
                            <td>
                                <?= !empty($row['keterangan']) ? e($row['keterangan']) : '<span style="color: #cbd5e1;">-</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

