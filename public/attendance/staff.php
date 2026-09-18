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

$pageTitle = 'Absensi Staff';
$userId = currentUserId();

// Ambil Status Hak Akses Hapus (dari tabel yang dibuat otomatis di halaman students)
$ksCanDelete = false;
try {
    $stmtSet = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ks_delete_attendance'");
    $ksCanDelete = ($stmtSet->fetchColumn() === '1');
} catch(Exception $e) {}

$canDelete = ($currentRole === 'super_admin' || ($currentRole === 'kepala_sekolah' && $ksCanDelete));

// ==============================================================================
// PROSES HAPUS MASSAL (BULK DELETE)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if ($canDelete && !empty($_POST['attendance_ids'])) {
        $ids = $_POST['attendance_ids'];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtDelete = $pdo->prepare("DELETE FROM staff_attendances WHERE id IN ($placeholders)");
        $stmtDelete->execute($ids);
        flash('success', count($ids) . ' data absensi guru/staff terpilih berhasil dihapus.');
        redirect('staff.php');
    } else {
        flash('error', 'Tidak ada data yang dipilih atau Anda tidak memiliki akses.');
    }
}

$name = trim($_GET['name'] ?? '');
$status = $_GET['status'] ?? '';
$unitId = (int)($_GET['unit_id'] ?? 0);
$dateStart = $_GET['date_start'] ?? date('Y-m-d');
$dateEnd = $_GET['date_end'] ?? date('Y-m-d');

// PENGUNCIAN UNIT UNTUK KEPALA SEKOLAH
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    $unitId = $ksUnitId ? (int)$ksUnitId : -1; 
}

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

$where = [
    "s.deleted_at IS NULL",
    "sa.attendance_date BETWEEN ? AND ?"
];
$params = [$dateStart, $dateEnd];

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($unitId > 0) { $where[] = "s.unit_id = ?"; $params[] = $unitId; }
if (in_array($status, ['tepat_waktu', 'terlambat', 'izin', 'sakit'])) { 
    $where[] = "sa.status = ?"; $params[] = $status; 
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT sa.*, s.name, s.nik, a.name AS activity_name, u.unit AS unit_name
    FROM staff_attendances sa
    INNER JOIN staff s ON s.id = sa.staff_id
    INNER JOIN activities a ON a.id = sa.activity_id
    LEFT JOIN units u ON u.id = s.unit_id
    WHERE {$whereSql}
    ORDER BY sa.attendance_date DESC, sa.id DESC, s.name
");
$stmt->execute($params);
$attendance = $stmt->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
   <div class="card-header">
        <h3>Absensi Staff / Guru</h3>
        <a href="create_staff.php" class="btn btn-primary">+ Input Izin / Sakit</a>
    </div>
    <form method="GET">
        <div class="filter-grid">
            <div class="form-group"><label>Dari</label><input type="date" name="date_start" value="<?= e($dateStart) ?>"></div>
            <div class="form-group"><label>Sampai</label><input type="date" name="date_end" value="<?= e($dateEnd) ?>"></div>
            <div class="form-group"><label>Nama</label><input type="text" name="name" value="<?= e($name) ?>"></div>
            
            <?php if ($currentRole === 'super_admin'): ?>
            <div class="form-group">
                <label>Unit</label>
                <select name="unit_id">
                    <option value="0">Semua Unit</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?= $unit['id'] ?>" <?= $unitId == $unit['id'] ? 'selected' : '' ?>><?= e($unit['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">Semua</option>
                    <option value="tepat_waktu" <?= $status === 'tepat_waktu' ? 'selected' : '' ?>>Tepat Waktu</option>
                    <option value="terlambat" <?= $status === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                    <option value="izin" <?= $status === 'izin' ? 'selected' : '' ?>>Izin</option>
                    <option value="sakit" <?= $status === 'sakit' ? 'selected' : '' ?>>Sakit</option>
                </select>
            </div>
            <div><button class="btn btn-primary" style="margin-top: 22px;">🔎 Cari</button></div>
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
                        <th>Unit</th>
                        <th>Jam Masuk</th>
                        <th>Jam Keluar</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attendance as $row): ?>
                    <tr>
                        <?php if ($canDelete): ?>
                        <td style="text-align: center;">
                            <input type="checkbox" name="attendance_ids[]" value="<?= $row['id'] ?>" class="checkItem">
                        </td>
                        <?php endif; ?>
                        <td><?= e($row['attendance_date']) ?></td>
                        <td><?= e($row['name']) ?></td>
                        <td><span class="badge badge-primary" style="background:#0284c7; color:white;"><?= e($row['unit_name'] ?? '-') ?></span></td>
                        <td><?= !empty($row['time_in']) ? e($row['time_in']) : '<span style="color:#ef4444; font-weight:600;">tidak absen</span>' ?></td>
                        <td><?= !empty($row['time_out']) ? e($row['time_out']) : '<span style="color:#ef4444; font-weight:600;">tidak absen</span>' ?></td>
                        <td>
                            <?php if ($row['status'] === 'tepat_waktu'): ?>
                                <span class="badge badge-success">Tepat Waktu</span>
                            <?php elseif ($row['status'] === 'terlambat'): ?>
                                <span class="badge badge-danger">Terlambat</span>
                            <?php elseif ($row['status'] === 'izin'): ?>
                                <span class="badge badge-info" style="background:#3b82f6; color:white;">Izin</span>
                            <?php elseif ($row['status'] === 'sakit'): ?>
                                <span class="badge badge-warning" style="background:#f59e0b; color:white;">Sakit</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= e($row['keterangan'] ?? '-') ?></small></td>
                        <td>
                            <a href="edit_staff.php?id=<?= $row['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size:12px;">Edit</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
document.getElementById('selectAll')?.addEventListener('change', function(e) {
    let checkboxes = document.querySelectorAll('.checkItem');
    checkboxes.forEach(cb => cb.checked = e.target.checked);
});

function confirmBulkDelete(e) {
    let checked = document.querySelectorAll('.checkItem:checked').length;
    if (checked === 0) {
        alert('Pilih minimal satu data absensi staff untuk dihapus.');
        e.preventDefault();
        return false;
    }
    if(!confirm('Yakin ingin menghapus ' + checked + ' data absensi staff terpilih secara permanen?')) {
        e.preventDefault();
        return false;
    }
    return true;
}
</script>

<?php require '../../includes/footer.php'; ?>