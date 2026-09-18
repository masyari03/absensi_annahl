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

$pageTitle = 'Edit Activity';
$userId = currentUserId();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM activities WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$activity = $stmt->fetch();

if (!$activity) { die('Activity tidak ditemukan.'); }

// PROTEKSI KEPALA SEKOLAH
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    if ($activity['unit_id'] != $ksUnitId) {
        flash('error', 'Akses ditolak. Anda tidak bisa mengubah jadwal unit lain.');
        redirect('index.php');
        exit;
    }
}

$years = $pdo->query("SELECT * FROM academic_years ORDER BY start_date DESC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("
        UPDATE activities
        SET
            academic_year_id = ?, name = ?, activity_date = ?, is_holiday = ?,
            student_in = ?, student_late = ?, student_out = ?,
            staff_in = ?, staff_late = ?, staff_out = ?, description = ?, status = ?
        WHERE id = ?
    ");

    $stmt->execute([
        (int)$_POST['academic_year_id'], trim($_POST['name']), $_POST['activity_date'], $_POST['is_holiday'],
        $_POST['student_in'] ?: null, $_POST['student_late'] ?: null, $_POST['student_out'] ?: null,
        $_POST['staff_in'] ?: null, $_POST['staff_late'] ?: null, $_POST['staff_out'] ?: null,
        trim($_POST['description'] ?? '') ?: null, $_POST['status'], $id
    ]);

    flash('success', 'Activity berhasil diperbarui.');
    redirect('index.php');
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Edit Activity</h3>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Tahun Ajaran</label>
                <select name="academic_year_id" required>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year['id'] ?>" <?= ($activity['academic_year_id'] == $year['id']) ? 'selected' : '' ?>>
                            <?= e($year['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group"><label>Nama Kegiatan</label><input type="text" name="name" value="<?= e($activity['name']) ?>" required></div>
            <div class="form-group"><label>Tanggal</label><input type="date" name="activity_date" value="<?= e($activity['activity_date']) ?>" required></div>
            <div class="form-group">
                <label>Hari Libur?</label>
                <select name="is_holiday">
                    <option value="no" <?= ($activity['is_holiday'] === 'no') ? 'selected' : '' ?>>Tidak</option>
                    <option value="yes" <?= ($activity['is_holiday'] === 'yes') ? 'selected' : '' ?>>Ya (Mesin Absen Mati)</option>
                </select>
            </div>
            
            <div class="form-group"><label>Status</label>
                <select name="status">
                    <option value="active" <?= $activity['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="closed" <?= $activity['status'] === 'closed' ? 'selected' : '' ?>>Ditutup</option>
                </select>
            </div>
            <div class="form-group" style="visibility:hidden"></div>

            <div class="form-group"><label>Siswa: Jam Masuk</label><input type="time" name="student_in" value="<?= e($activity['student_in']) ?>"></div>
            <div class="form-group"><label>Siswa: Terlambat Setelah</label><input type="time" name="student_late" value="<?= e($activity['student_late']) ?>"></div>
            <div class="form-group"><label>Siswa: Jam Pulang</label><input type="time" name="student_out" value="<?= e($activity['student_out']) ?>"></div>
            
            <div class="form-group"><label>Staff: Jam Masuk</label><input type="time" name="staff_in" value="<?= e($activity['staff_in']) ?>"></div>
            <div class="form-group"><label>Staff: Terlambat Setelah</label><input type="time" name="staff_late" value="<?= e($activity['staff_late']) ?>"></div>
            <div class="form-group"><label>Staff: Jam Pulang</label><input type="time" name="staff_out" value="<?= e($activity['staff_out']) ?>"></div>

            <div class="form-group full"><label>Keterangan</label><textarea name="description" rows="4"><?= e($activity['description']) ?></textarea></div>
        </div>
        <div style="margin-top:20px"><button class="btn btn-primary">Simpan</button><a href="index.php" class="btn btn-success">Kembali</a></div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>