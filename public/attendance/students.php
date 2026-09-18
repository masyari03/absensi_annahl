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

$pageTitle = 'Absensi Siswa';
$userId = currentUserId();

// ==============================================================================
// SISTEM PENGATURAN AKSES KEPALA SEKOLAH (AUTO-CREATE TABLE JIKA BELUM ADA)
// ==============================================================================
$pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255)
)");

// Proses Toggle Akses (Hanya Super Admin yang bisa melakukan ini)
if ($currentRole === 'super_admin' && isset($_POST['toggle_ks_access'])) {
    $key = $_POST['setting_key'];
    $val = $_POST['setting_value'] === '1' ? '0' : '1'; // Balikkan nilai (Toggle)
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$key, $val, $val]);
    flash('success', 'Hak akses Kepala Sekolah berhasil diperbarui.');
    redirect('students.php');
}

// Ambil Status Akses Saat Ini
$ksCanDelete = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ks_delete_attendance'")->fetchColumn() === '1';
$ksCanEditTime = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ks_edit_time'")->fetchColumn() === '1';

// Logika Siapa yang Boleh Hapus
$canDelete = ($currentRole === 'super_admin' || ($currentRole === 'kepala_sekolah' && $ksCanDelete));

// ==============================================================================
// PROSES HAPUS MASSAL (BULK DELETE)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if ($canDelete && !empty($_POST['attendance_ids'])) {
        $ids = $_POST['attendance_ids'];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtDelete = $pdo->prepare("DELETE FROM student_attendances WHERE id IN ($placeholders)");
        $stmtDelete->execute($ids);
        flash('success', count($ids) . ' data absensi terpilih berhasil dihapus.');
        redirect('students.php');
    } else {
        flash('error', 'Tidak ada data yang dipilih atau Anda tidak memiliki akses.');
    }
}

$name = trim($_GET['name'] ?? '');
$status = $_GET['status'] ?? '';
$dateStart = $_GET['date_start'] ?? date('Y-m-d');
$dateEnd = $_GET['date_end'] ?? date('Y-m-d');
$gradeId = (int)($_GET['grade_id'] ?? 0);
$classId = (int)($_GET['class_group_id'] ?? 0);

// Menyesuaikan Dropdown Filter berdasarkan Role
if ($currentRole === 'super_admin') {
    $grades = $pdo->query("SELECT * FROM grades ORDER BY sort_order, grade")->fetchAll();
    $classGroups = $pdo->query("SELECT cg.id, cg.name, g.grade FROM class_groups cg INNER JOIN grades g ON g.id = cg.grade_id ORDER BY g.sort_order, cg.name")->fetchAll();
} elseif ($currentRole === 'kepala_sekolah') {
    $grades = $pdo->prepare("SELECT * FROM grades WHERE unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?) ORDER BY sort_order, grade");
    $grades->execute([$userId]);
    $grades = $grades->fetchAll();
    
    $classGroups = $pdo->prepare("SELECT cg.id, cg.name, g.grade FROM class_groups cg INNER JOIN grades g ON g.id = cg.grade_id WHERE g.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?) ORDER BY g.sort_order, cg.name");
    $classGroups->execute([$userId]);
    $classGroups = $classGroups->fetchAll();
} else {
    $grades = [];
    $classGroups = [];
}

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
if (in_array($status, ['tepat_waktu', 'terlambat', 'izin', 'sakit'])) { 
    $where[] = "sa.status = ?"; $params[] = $status; 
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT 
        sa.*, s.name, s.nis, s.photo, 
        g.grade, cg.name AS class_name, 
        a.name AS activity_name
    FROM student_attendances sa
    INNER JOIN students s ON s.id = sa.student_id
    INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN activities a ON a.id = sa.activity_id
    WHERE {$whereSql}
    ORDER BY sa.attendance_date DESC, sa.id DESC, s.name
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attendances = $stmt->fetchAll();

require '../../includes/header.php';
?>

<?php if ($currentRole === 'super_admin'): ?>
<!-- PANEL PENGATURAN HAK AKSES KEPALA SEKOLAH -->
<div class="card" style="margin-bottom: 20px; background: #f8fafc; border: 1px solid #cbd5e1;">
    <div style="padding: 15px 20px;">
        <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #334155;">⚙️ Panel Akses: Hak Kepala Sekolah</h4>
        <div style="display: flex; gap: 10px;">
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="toggle_ks_access" value="1">
                <input type="hidden" name="setting_key" value="ks_delete_attendance">
                <input type="hidden" name="setting_value" value="<?= $ksCanDelete ? '1' : '0' ?>">
                <button type="submit" class="btn <?= $ksCanDelete ? 'btn-success' : 'btn-danger' ?>" style="font-size: 12px; padding: 5px 10px;">
                    Akses Hapus Absensi: <?= $ksCanDelete ? 'ON (Bisa Hapus)' : 'OFF (Terkunci)' ?>
                </button>
            </form>
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="toggle_ks_access" value="1">
                <input type="hidden" name="setting_key" value="ks_edit_time">
                <input type="hidden" name="setting_value" value="<?= $ksCanEditTime ? '1' : '0' ?>">
                <button type="submit" class="btn <?= $ksCanEditTime ? 'btn-success' : 'btn-danger' ?>" style="font-size: 12px; padding: 5px 10px;">
                    Akses Edit Jam: <?= $ksCanEditTime ? 'ON (Bisa Edit)' : 'OFF (Terkunci)' ?>
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Absensi Siswa</h3>
            <small>Data otomatis disesuaikan dengan hak akses Anda.</small>
        </div>
        <div>
            <a href="create_student.php" class="btn btn-primary">+ Input Izin / Sakit</a>
        </div>
    </div>

    <form method="GET">
        <div class="filter-grid">
            <div class="form-group">
                <label>Dari Tanggal</label>
                <input type="date" name="date_start" value="<?= e($dateStart) ?>">
            </div>
            <div class="form-group">
                <label>Sampai Tanggal</label>
                <input type="date" name="date_end" value="<?= e($dateEnd) ?>">
            </div>
            <div class="form-group">
                <label>Nama Siswa</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="Cari nama...">
            </div>

            <?php if (in_array($currentRole, ['super_admin', 'kepala_sekolah'])): ?>
            <div class="form-group">
                <label>Grade</label>
                <select name="grade_id">
                    <option value="0">Semua Grade</option>
                    <?php foreach ($grades as $grade): ?>
                        <option value="<?= $grade['id'] ?>" <?= $gradeId == $grade['id'] ? 'selected' : '' ?>><?= e($grade['grade']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Subkelas</label>
                <select name="class_group_id">
                    <option value="0">Semua Subkelas</option>
                    <?php foreach ($classGroups as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= $classId == $class['id'] ? 'selected' : '' ?>><?= e($class['grade']) ?> - <?= e($class['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">Semua Status</option>
                    <option value="tepat_waktu" <?= $status === 'tepat_waktu' ? 'selected' : '' ?>>Tepat Waktu</option>
                    <option value="terlambat" <?= $status === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                    <option value="izin" <?= $status === 'izin' ? 'selected' : '' ?>>Izin</option>
                    <option value="sakit" <?= $status === 'sakit' ? 'selected' : '' ?>>Sakit</option>
                </select>
            </div>
            <div>
                <button class="btn btn-primary" type="submit" style="margin-top:22px;">🔎 Cari</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <form method="POST" id="bulkDeleteForm" onsubmit="return confirmBulkDelete(event)">
        <input type="hidden" name="bulk_delete" value="1">
        
        <?php if ($canDelete): ?>
        <div style="padding: 15px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: flex-start;">
            <button type="submit" class="btn" style="background-color: #ef4444; color: white; font-size: 13px;">
                🗑️ Hapus Data Terpilih
            </button>
        </div>
        <?php endif; ?>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <?php if ($canDelete): ?>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" id="selectAll" title="Pilih Semua">
                        </th>
                        <?php endif; ?>
                        <th>Tanggal</th>
                        <th>Nama</th>
                        <th>Subkelas</th>
                        <th>Jam Masuk</th>
                        <th>Jam Keluar</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attendances as $attendance): ?>
                    <tr>
                        <?php if ($canDelete): ?>
                        <td style="text-align: center;">
                            <input type="checkbox" name="attendance_ids[]" value="<?= $attendance['id'] ?>" class="checkItem">
                        </td>
                        <?php endif; ?>
                        <td><?= e($attendance['attendance_date']) ?></td>
                        <td><?= e($attendance['name']) ?></td>
                        <td><?= e($attendance['class_name']) ?></td>
                        <td><?= !empty($attendance['time_in']) ? e($attendance['time_in']) : '<span style="color:#ef4444; font-weight:600;">tidak absen</span>' ?></td>
                        <td><?= !empty($attendance['time_out']) ? e($attendance['time_out']) : '<span style="color:#ef4444; font-weight:600;">tidak absen</span>' ?></td>
                        <td>
                            <?php if ($attendance['status'] === 'tepat_waktu'): ?>
                                <span class="badge badge-success">Tepat Waktu</span>
                            <?php elseif ($attendance['status'] === 'terlambat'): ?>
                                <span class="badge badge-danger">Terlambat</span>
                            <?php elseif ($attendance['status'] === 'izin'): ?>
                                <span class="badge badge-info" style="background:#3b82f6; color:white;">Izin</span>
                            <?php elseif ($attendance['status'] === 'sakit'): ?>
                                <span class="badge badge-warning" style="background:#f59e0b; color:white;">Sakit</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= e($attendance['keterangan'] ?? '-') ?></small></td>
                        <td>
                            <a href="edit_student.php?id=<?= $attendance['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size:12px;">Edit</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
// Script untuk Handle Checkbox Pilih Semua
document.getElementById('selectAll')?.addEventListener('change', function(e) {
    let checkboxes = document.querySelectorAll('.checkItem');
    checkboxes.forEach(cb => cb.checked = e.target.checked);
});

// Konfirmasi Hapus Massal
function confirmBulkDelete(e) {
    let checked = document.querySelectorAll('.checkItem:checked').length;
    if (checked === 0) {
        alert('Pilih minimal satu data absensi untuk dihapus.');
        e.preventDefault();
        return false;
    }
    if(!confirm('Yakin ingin menghapus ' + checked + ' data absensi terpilih secara permanen?')) {
        e.preventDefault();
        return false;
    }
    return true;
}
</script>

<?php require '../../includes/footer.php'; ?>