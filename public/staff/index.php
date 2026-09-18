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

$pageTitle = 'Staff / Guru';
$userId = currentUserId();

$name = trim($_GET['name'] ?? '');
$nik = trim($_GET['nik'] ?? '');
$unitId = (int)($_GET['unit_id'] ?? 0);

// PENGUNCIAN UNIT UNTUK KEPALA SEKOLAH
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    
    // Paksa pencarian/filter hanya pada unit Kepala Sekolah tersebut
    $unitId = $ksUnitId ? (int)$ksUnitId : -1; 
}

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

$where = [
    "s.deleted_at IS NULL",
    "(u.deleted_at IS NULL OR u.id IS NULL)",
    "(u.role IN ('staff', 'admin', 'kepala_sekolah') OR u.role IS NULL)"
];
$params = [];

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($nik !== '') { $where[] = "s.nik LIKE ?"; $params[] = "%{$nik}%"; }
if ($unitId > 0) { $where[] = "s.unit_id = ?"; $params[] = $unitId; }

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.nik, s.photo, s.unit_id, u.username, u.role, un.unit AS unit_name
    FROM staff s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN units un ON un.id = s.unit_id
    WHERE {$whereSql}
    ORDER BY s.name
");
$stmt->execute($params);
$staff = $stmt->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Staff / Guru</h3>
            <small>Menampilkan data staff sesuai hak akses unit.</small>
        </div>
        <?php if ($currentRole === 'super_admin'): ?>
        <div style="display: flex; gap: 10px;">
            <a href="import.php" class="btn" style="background-color: #10b981; color: white;">📄 Import Excel (XLSX)</a>
            <a href="create.php" class="btn btn-primary">+ Tambah Staff</a>
        </div>
        <?php endif; ?>
    </div>

    <form method="GET">
        <div class="filter-grid">
            <div class="form-group">
                <label>Nama</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="Cari nama...">
            </div>
            <div class="form-group">
                <label>NIK / Barcode</label>
                <input type="text" name="nik" value="<?= e($nik) ?>">
            </div>
            
            <?php if ($currentRole === 'super_admin'): ?>
            <div class="form-group">
                <label>Unit</label>
                <select name="unit_id">
                    <option value="0">Semua Unit</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
                <input type="hidden" name="unit_id" value="<?= $unitId ?>">
            <?php endif; ?>
            
            <div>
                <button type="submit" class="btn btn-primary" style="margin-top: 22px;">🔎 Cari</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Foto</th>
                    <th>Nama</th>
                    <th>NIK / Barcode</th>
                    <th>Unit</th>
                    <th>Username</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff as $index => $person): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <?php if (!empty($person['photo'])): ?>
                            <img src="../../uploads/staff/<?= e($person['photo']) ?>" width="45" height="45" style="object-fit:cover; border-radius:10px;">
                        <?php else: ?>
                            <div class="avatar"><?= strtoupper(substr($person['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($person['name']) ?>
                        <!-- Badge Role -->
                        <?php if ($person['role'] === 'admin'): ?>
                            <span class="badge badge-success" style="font-size: 10px; margin-left: 5px; vertical-align: middle;">Admin</span>
                        <?php elseif ($person['role'] === 'kepala_sekolah'): ?>
                            <span class="badge" style="font-size: 10px; margin-left: 5px; vertical-align: middle; background-color:#8b5cf6; color:white;">Kepala Sekolah</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($person['nik']) ?></td>
                    <td>
                        <span class="badge badge-primary" style="background:#0284c7; color:white;">
                            <?= e($person['unit_name'] ?? '-') ?>
                        </span>
                    </td>
                    <td><?= e($person['username']) ?></td>
                    <td>
                        <!-- TOMBOL EDIT BISA DIAKSES SUPER ADMIN & KEPSEK -->
                        <a href="edit.php?id=<?= $person['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size:12px;">Edit</a>
                        
                        <?php if (!empty($person['username'])): ?>
                            <?php 
                                $targetRole = $person['role'] ?? 'staff'; 
                                $showRoleButtons = true;

                                // JIKA KEPSEK MELIHAT BARIS DIRINYA SENDIRI, HILANGKAN SEMUA TOMBOL (Kecuali Edit)
                                if ($currentRole === 'kepala_sekolah' && $targetRole === 'kepala_sekolah') {
                                    $showRoleButtons = false;
                                }
                            ?>

                            <?php if ($showRoleButtons): ?>

                                <?php if ($targetRole === 'kepala_sekolah'): ?>
                                    
                                    <!-- JIKA TARGET KEPSEK: HANYA SUPER ADMIN YANG BISA TURUNKAN JADI STAFF -->
                                    <?php if ($currentRole === 'super_admin'): ?>
                                        <form method="POST" action="toggle_role.php" style="display:inline" onsubmit="return confirm('Turunkan Kepala Sekolah ini menjadi Staff?');">
                                            <input type="hidden" name="id" value="<?= $person['id'] ?>">
                                            <input type="hidden" name="action" value="make_staff">
                                            <button class="btn btn-warning" type="submit" style="padding: 4px 8px; font-size:12px; background-color: #f59e0b; color: white; border: none;">Jadikan Staff</button>
                                        </form>
                                    <?php endif; ?>

                                <?php elseif ($targetRole === 'admin'): ?>
                                    
                                    <!-- JIKA TARGET ADMIN: BISA DITURUNKAN JADI STAFF OLEH SUPER ADMIN MAUPUN KEPSEK -->
                                    <form method="POST" action="toggle_role.php" style="display:inline" onsubmit="return confirm('Turunkan Admin ini menjadi Staff?');">
                                        <input type="hidden" name="id" value="<?= $person['id'] ?>">
                                        <input type="hidden" name="action" value="toggle_admin">
                                        <button class="btn btn-warning" type="submit" style="padding: 4px 8px; font-size:12px; background-color: #f59e0b; color: white; border: none;">Jadikan Staff</button>
                                    </form>

                                <?php else: ?>
                                    <!-- JIKA TARGET STAFF BIASA -->

                                    <!-- HANYA SUPER ADMIN YANG BISA JADIKAN STAFF SEBAGAI KEPALA SEKOLAH -->
                                    <?php if ($currentRole === 'super_admin'): ?>
                                        <form method="POST" action="toggle_role.php" style="display:inline" onsubmit="return confirm('Angkat staff ini menjadi Kepala Sekolah untuk Unit <?= e($person['unit_name']) ?>?');">
                                            <input type="hidden" name="id" value="<?= $person['id'] ?>">
                                            <input type="hidden" name="action" value="make_kepsek">
                                            <button class="btn btn-primary" type="submit" style="padding: 4px 8px; font-size:12px; background-color: #8b5cf6; color: white; border: none;">Jadikan Kepsek</button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- SUPER ADMIN DAN KEPSEK SAMA-SAMA BISA JADIKAN STAFF SEBAGAI ADMIN -->
                                    <form method="POST" action="toggle_role.php" style="display:inline" onsubmit="return confirm('Angkat staff ini menjadi Admin?');">
                                        <input type="hidden" name="id" value="<?= $person['id'] ?>">
                                        <input type="hidden" name="action" value="toggle_admin">
                                        <button class="btn btn-info" type="submit" style="padding: 4px 8px; font-size:12px; background-color: #3b82f6; color: white; border: none;">Jadikan Admin</button>
                                    </form>
                                <?php endif; ?>

                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- TOMBOL HAPUS HANYA UNTUK SUPER ADMIN -->
                        <?php if ($currentRole === 'super_admin'): ?>
                            <form method="POST" action="delete.php" style="display:inline" onsubmit="return confirm('Hapus staff ini?')">
                                <input type="hidden" name="id" value="<?= $person['id'] ?>">
                                <button class="btn btn-danger" type="submit" style="padding: 4px 8px; font-size:12px; border: none;">Hapus</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>