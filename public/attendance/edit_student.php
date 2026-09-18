<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

$currentRole = $_SESSION['role'] ?? '';
if (!in_array($currentRole, ['super_admin', 'kepala_sekolah', 'admin'])) {
    redirect('../dashboard.php');
}

$id = (int)($_GET['id'] ?? 0);

// Ambil Status Akses Kepala Sekolah (Edit Jam) dari sistem setting
$ksCanEditTime = false;
try {
    $stmtSet = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ks_edit_time'");
    $ksCanEditTime = ($stmtSet->fetchColumn() === '1');
} catch(Exception $e) {}

// Logika siapa yang boleh edit jam
$canEditTime = in_array($currentRole, ['super_admin', 'admin']) || ($currentRole === 'kepala_sekolah' && $ksCanEditTime);

// Proteksi akses data sesuai role Admin / Kepala Sekolah
$access = getStudentAccessCondition('g', 'cg');
$params = [$id];
$params = array_merge($params, $access['params']);

$stmt = $pdo->prepare("
    SELECT sa.*, s.name, s.nis, cg.name AS class_name 
    FROM student_attendances sa
    INNER JOIN students s ON s.id = sa.student_id
    INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    WHERE sa.id = ? AND {$access['condition']}
");
$stmt->execute($params);
$attendance = $stmt->fetch();

if (!$attendance) {
    flash('error', 'Data absensi tidak ditemukan atau Anda tidak memiliki akses.');
    redirect('students.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = $_POST['status'] ?? 'terlambat';
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    // Gunakan logika $canEditTime untuk menentukan apakah ambil dari POST atau Database
    if (!$canEditTime) {
        $timeIn = $attendance['time_in'];
        $timeOut = $attendance['time_out'];
    } else {
        $timeIn = empty($_POST['time_in']) ? null : $_POST['time_in'];
        $timeOut = empty($_POST['time_out']) ? null : $_POST['time_out'];
    }

    $update = $pdo->prepare("UPDATE student_attendances SET status = ?, keterangan = ?, time_in = ?, time_out = ? WHERE id = ?");
    $update->execute([$status, $keterangan, $timeIn, $timeOut, $id]);
    
    flash('success', 'Data absensi siswa berhasil diperbarui.');
    redirect('students.php');
}

$pageTitle = 'Edit Absensi Siswa';
require '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-header">
        <h3>Edit Kehadiran Siswa</h3>
    </div>
    
    <form method="POST" style="padding: 20px;">
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:8px; font-weight:600; color:#374151;">Nama Siswa & Kelas</label>
            <input type="text" value="<?= e($attendance['name']) ?> (<?= e($attendance['class_name']) ?>)" disabled style="width: 100%; padding: 10px; background:#f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; color: #475569;">
        </div>
        
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:8px; font-weight:600; color:#374151;">Status Kehadiran</label>
            <select name="status" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; background-color: #fff;">
                <option value="tepat_waktu" <?= $attendance['status'] === 'tepat_waktu' ? 'selected' : '' ?>>✓ Tepat Waktu</option>
                <option value="terlambat" <?= $attendance['status'] === 'terlambat' ? 'selected' : '' ?>>⚠ Terlambat</option>
                <option value="izin" <?= $attendance['status'] === 'izin' ? 'selected' : '' ?>>ℹ Izin</option>
                <option value="sakit" <?= $attendance['status'] === 'sakit' ? 'selected' : '' ?>>♥ Sakit</option>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:8px; font-weight:600; color:#374151;">Keterangan (Opsional)</label>
            <textarea name="keterangan" rows="3" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family:inherit;" placeholder="Tulis alasan izin, sakit, atau catatan lainnya..."><?= e($attendance['keterangan'] ?? '') ?></textarea>
        </div>
        
        <?php if ($canEditTime): ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px;">
            <div class="form-group">
                <label style="display:block; margin-bottom:8px; font-weight:600; color:#374151;">Jam Masuk</label>
                <input type="time" name="time_in" step="1" value="<?= e($attendance['time_in'] ?? '') ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
            </div>
            <div class="form-group">
                <label style="display:block; margin-bottom:8px; font-weight:600; color:#374151;">Jam Keluar</label>
                <input type="time" name="time_out" step="1" value="<?= e($attendance['time_out'] ?? '') ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
            </div>
        </div>
        <?php else: ?>
            <div style="margin-bottom: 25px;">
                <p style="font-size: 13px; color: #ef4444; background: #fef2f2; padding: 10px; border-radius: 6px; border: 1px solid #fecaca; margin-bottom: 0;">
                    <i class="fa fa-info-circle"></i> Jam absen aktual (<strong style="color:#b91c1c;"><?= e($attendance['time_in'] ?? '--:--') ?> - <?= e($attendance['time_out'] ?? '--:--') ?></strong>) tidak dapat dimanipulasi oleh hak akses Anda saat ini.
                </p>
            </div>
        <?php endif; ?>
        
        <div style="display:flex; justify-content: flex-end; gap:10px;">
            <a href="students.php" class="btn" style="background: #ef4444; color: white;">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>