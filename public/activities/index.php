<?php
require_once '../../config/config.php'; 
require_once '../../config/database.php'; 
require_once '../../config/auth.php'; 
require_once '../../config/security.php'; 
require_once '../../includes/functions.php';

$currentRole = $_SESSION['role'] ?? '';
if (!in_array($currentRole, ['super_admin', 'kepala_sekolah'])) {
    redirect('../dashboard.php');
}

$pageTitle = 'Activities';
$userId = currentUserId();

$whereSql = "";
$params = [];

if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    if ($ksUnitId) {
        $whereSql = "WHERE a.unit_id = ?";
        $params[] = $ksUnitId;
    } else {
        $whereSql = "WHERE 1 = 0"; 
    }
}

$stmt = $pdo->prepare("
    SELECT a.*, ay.name AS academic_year, u.unit AS unit_name
    FROM activities a
    INNER JOIN academic_years ay ON ay.id = a.academic_year_id
    INNER JOIN units u ON u.id = a.unit_id
    {$whereSql}
    ORDER BY a.activity_date DESC, a.student_in DESC
");
$stmt->execute($params);
$activities = $stmt->fetchAll();

require '../../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <div>
            <h3>Activities / Kegiatan</h3>
            <small>Jadwal Harian Khusus <?= $currentRole === 'kepala_sekolah' ? '(Hanya Unit Anda)' : '' ?></small>
        </div>
        <a href="create.php" class="btn btn-primary">+ Tambah Activity</a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Tanggal</th><th>Unit</th><th>Kegiatan & Tahun</th><th>Jam Siswa</th><th>Jam Staff</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
                <?php if (!$activities): ?>
                    <tr><td colspan="7" style="text-align:center">Belum ada kegiatan.</td></tr>
                <?php endif; ?>
                
                <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td><strong><?= e($activity['activity_date']) ?></strong></td>
                        <td><span class="badge badge-primary" style="background:#0284c7;color:white;"><?= e($activity['unit_name']) ?></span></td>
                        <td><strong><?= e($activity['name']) ?></strong><br><small style="color: #64748b;"><?= e($activity['academic_year']) ?></small></td>
                        <td>
                            <?php if ($activity['is_holiday'] === 'yes'): ?> Libur <?php else: ?>
                            <small>Masuk: <strong><?= e(substr($activity['student_in'], 0, 5)) ?></strong> | Telat: <span style="color:red;"><?= e(substr($activity['student_late'], 0, 5)) ?></span></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($activity['is_holiday'] === 'yes'): ?> Libur <?php else: ?>
                            <small>Masuk: <strong><?= e(substr($activity['staff_in'], 0, 5)) ?></strong> | Telat: <span style="color:red;"><?= e(substr($activity['staff_late'], 0, 5)) ?></span></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $activity['status'] === 'active' ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-danger">Ditutup</span>' ?></td>
                        <td>
                            <a href="edit.php?id=<?= $activity['id'] ?>" class="btn btn-success" style="margin-bottom: 4px;">Edit</a>
                            <?php if ($activity['status'] === 'active'): ?>
                                <form method="POST" action="close.php" style="display:inline"><input type="hidden" name="id" value="<?= $activity['id'] ?>"><button class="btn btn-danger" onclick="return confirm('Tutup kegiatan ini?');">Tutup</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require '../../includes/footer.php'; ?>