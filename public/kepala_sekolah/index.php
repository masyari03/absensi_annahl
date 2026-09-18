<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Kepala Sekolah';

// PROSES TAMBAH, CABUT, ATAU EDIT JABATAN KEPALA SEKOLAH
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $staffId = (int)($_POST['staff_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT user_id, name FROM staff WHERE id = ? LIMIT 1");
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch();

    if ($staff && !empty($staff['user_id'])) {
        if ($action === 'add') {
            $unitId = (int)($_POST['unit_id'] ?? 0);
            if ($unitId > 0) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE users SET role = 'kepala_sekolah' WHERE id = ?")->execute([$staff['user_id']]);
                    $pdo->prepare("DELETE FROM admin_unit_permissions WHERE user_id = ?")->execute([$staff['user_id']]);
                    $pdo->prepare("INSERT INTO admin_unit_permissions (user_id, unit_id) VALUES (?, ?)")->execute([$staff['user_id'], $unitId]);
                    
                    $pdo->prepare("DELETE FROM admin_grade_permissions WHERE user_id = ?")->execute([$staff['user_id']]);
                    $pdo->prepare("DELETE FROM admin_class_permissions WHERE user_id = ?")->execute([$staff['user_id']]);
                    
                    $pdo->commit();
                    flash('success', "{$staff['name']} berhasil diangkat menjadi Kepala Sekolah.");
                } catch (Exception $e) {
                    $pdo->rollBack();
                    flash('error', 'Gagal memproses data.');
                }
            } else {
                flash('error', 'Unit wajib dipilih.');
            }
        } elseif ($action === 'edit_unit') {
            $unitId = (int)($_POST['unit_id'] ?? 0);
            if ($unitId > 0) {
                $pdo->prepare("UPDATE admin_unit_permissions SET unit_id = ? WHERE user_id = ?")->execute([$unitId, $staff['user_id']]);
                flash('success', "Hak akses unit untuk {$staff['name']} berhasil diperbarui.");
            }
        } elseif ($action === 'revoke') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE users SET role = 'staff' WHERE id = ?")->execute([$staff['user_id']]);
                $pdo->prepare("DELETE FROM admin_unit_permissions WHERE user_id = ?")->execute([$staff['user_id']]);
                $pdo->commit();
                flash('success', "Jabatan Kepala Sekolah untuk {$staff['name']} telah dicabut.");
            } catch (Exception $e) {
                $pdo->rollBack();
            }
        }
    } else {
        flash('error', 'Staff tidak ditemukan atau belum memiliki akun sistem.');
    }
    redirect('index.php');
}

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

$staffCandidates = $pdo->query("
    SELECT s.id, s.name, u.role 
    FROM staff s 
    INNER JOIN users u ON u.id = s.user_id 
    WHERE u.role IN ('staff', 'admin') AND s.deleted_at IS NULL
    ORDER BY s.name
")->fetchAll();

$kepsek = $pdo->query("
    SELECT s.id, s.name, s.nik, s.photo, u.username, un.unit AS unit_name, aup.unit_id AS assigned_unit_id
    FROM staff s
    INNER JOIN users u ON u.id = s.user_id
    INNER JOIN admin_unit_permissions aup ON aup.user_id = u.id
    INNER JOIN units un ON un.id = aup.unit_id
    WHERE u.role = 'kepala_sekolah' AND s.deleted_at IS NULL
    ORDER BY un.unit, s.name
")->fetchAll();

require '../../includes/header.php';
?>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3>Angkat Kepala Sekolah</h3>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="filter-grid">
            <div class="form-group">
                <label>Pilih Staff / Guru *</label>
                <select name="staff_id" required>
                    <option value="">-- Pilih --</option>
                    <?php foreach ($staffCandidates as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= ucfirst($c['role']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Pilih Hak Akses Unit *</label>
                <select name="unit_id" required>
                    <option value="">-- Pilih Unit --</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" style="margin-top: 22px;">+ Jadikan Kepala Sekolah</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h3>Daftar Kepala Sekolah Aktif</h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Foto</th>
                    <th>Nama</th>
                    <th>NIK / Barcode</th>
                    <th>Username</th>
                    <th>Akses Unit (Edit)</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$kepsek): ?>
                    <tr><td colspan="7" style="text-align:center">Belum ada data kepala sekolah.</td></tr>
                <?php endif; ?>
                
                <?php foreach ($kepsek as $index => $person): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <?php if (!empty($person['photo'])): ?>
                            <img src="../../uploads/staff/<?= e($person['photo']) ?>" width="45" height="45" style="object-fit:cover; border-radius:10px;">
                        <?php else: ?>
                            <div class="avatar"><?= strtoupper(substr($person['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($person['name']) ?></strong></td>
                    <td><?= e($person['nik']) ?></td>
                    <td><?= e($person['username']) ?></td>
                    <td>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="edit_unit">
                            <input type="hidden" name="staff_id" value="<?= $person['id'] ?>">
                            <select name="unit_id" onchange="this.form.submit()" style="padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 600; color: #0284c7; background: #e0f2fe; cursor: pointer;">
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $u['id'] == $person['assigned_unit_id'] ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Cabut jabatan Kepala Sekolah dari <?= e($person['name']) ?>?')">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="staff_id" value="<?= $person['id'] ?>">
                            <button type="submit" class="btn btn-warning" style="padding: 4px 8px; font-size:12px; background:#f59e0b; color:white;">Jadikan Staff Biasa</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>