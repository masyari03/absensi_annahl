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

$pageTitle = 'Tambah Activity';
$userId = currentUserId();

$ksUnitId = 0;
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();
}

$years = $pdo->query("SELECT * FROM academic_years ORDER BY start_date DESC")->fetchAll();
$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitId = $currentRole === 'super_admin' ? (int)$_POST['unit_id'] : $ksUnitId;
    
    if ($_POST['status'] === 'active') {
        $stmt = $pdo->prepare("UPDATE activities SET status = 'closed' WHERE activity_date = ? AND unit_id = ? AND status = 'active'");
        $stmt->execute([$_POST['activity_date'], $unitId]);
    }

    $stmt = $pdo->prepare("INSERT INTO activities (academic_year_id, unit_id, name, activity_date, is_holiday, student_in, student_late, student_out, staff_in, staff_late, staff_out, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        (int)$_POST['academic_year_id'], 
        $unitId, 
        $_POST['name'], 
        $_POST['activity_date'], 
        $_POST['is_holiday'], 
        $_POST['student_in'] ?: null, 
        $_POST['student_late'] ?: null, 
        $_POST['student_out'] ?: null, 
        $_POST['staff_in'] ?: null, 
        $_POST['staff_late'] ?: null, 
        $_POST['staff_out'] ?: null, 
        trim($_POST['description'] ?: ''), 
        $_POST['status']
    ]);
    
    flash('success', 'Kegiatan berhasil dibuat.');
    redirect('index.php');
}
require '../../includes/header.php';
?>
<div class="card">
    <h3>Tambah Activity</h3>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit *</label>
                <?php if ($currentRole === 'super_admin'): ?>
                    <select name="unit_id" required>
                        <option value="">Pilih Unit</option>
                        <?php foreach($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php 
                        $unitNameStr = '-';
                        foreach ($units as $u) {
                            if($u['id'] == $ksUnitId) { $unitNameStr = $u['unit']; break; }
                        }
                    ?>
                    <input type="text" value="<?= e($unitNameStr) ?>" disabled style="background:#f1f5f9; cursor:not-allowed;">
                    <small style="color:#0284c7;">Otomatis ditambahkan ke unit Anda.</small>
                <?php endif; ?>
            </div>
            <div class="form-group"><label>Tahun Ajaran *</label><select name="academic_year_id" required><option value="">Pilih Tahun Ajaran</option><?php foreach($years as $y): ?><option value="<?= $y['id'] ?>"><?= e($y['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Nama Kegiatan *</label><input type="text" name="name" required></div>
            <div class="form-group"><label>Tanggal *</label><input type="date" name="activity_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group"><label>Libur?</label><select name="is_holiday"><option value="no">Tidak</option><option value="yes">Ya</option></select></div>
            <div class="form-group"><label>Status</label><select name="status"><option value="active">Aktif</option><option value="closed">Ditutup</option></select></div>
            
            <div class="form-group"><label>Siswa: Jam Masuk</label><input type="time" name="student_in"></div>
            <div class="form-group"><label>Siswa: Terlambat</label><input type="time" name="student_late"></div>
            <div class="form-group"><label>Siswa: Jam Pulang</label><input type="time" name="student_out"></div>
            
            <div class="form-group"><label>Staff: Jam Masuk</label><input type="time" name="staff_in"></div>
            <div class="form-group"><label>Staff: Terlambat</label><input type="time" name="staff_late"></div>
            <div class="form-group"><label>Staff: Jam Pulang</label><input type="time" name="staff_out"></div>
            <div class="form-group full"><label>Keterangan</label><textarea name="description"></textarea></div>
        </div>
        <button class="btn btn-primary" style="margin-top:20px;">Simpan</button>
        <a href="index.php" class="btn btn-success" style="margin-top:20px;">Batal</a>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>