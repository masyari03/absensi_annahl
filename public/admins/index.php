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

$pageTitle = 'Admin / Guru Pemantau';
$userId = currentUserId();

// LOGIKA FILTER DIPERBAIKI
$whereClause = "WHERE u.role = 'admin' AND u.deleted_at IS NULL";
$params = [];

if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    // Tampilkan admin jika mereka terdaftar sebagai staff di unit ini (meskipun belum disetting kelasnya)
    // ATAU jika mereka punya akses Grade / Subkelas di Unit Kepsek ini
    if ($ksUnitId) {
        $whereClause .= " AND (
            s.unit_id = ? 
            OR EXISTS (
                SELECT 1 FROM admin_grade_permissions agp 
                INNER JOIN grades g ON g.id = agp.grade_id 
                WHERE agp.user_id = u.id AND g.unit_id = ?
            ) OR 
            EXISTS (
                SELECT 1 FROM admin_class_permissions acp 
                INNER JOIN class_groups cg ON cg.id = acp.class_group_id 
                INNER JOIN grades g ON g.id = cg.grade_id 
                WHERE acp.user_id = u.id AND g.unit_id = ?
            )
        )";
        $params[] = $ksUnitId;
        $params[] = $ksUnitId;
        $params[] = $ksUnitId;
    } else {
        // Jika Kepsek tidak punya unit, jangan tampilkan apa-apa
        $whereClause .= " AND 1 = 0"; 
    }
}

// Tambahkan LEFT JOIN staff s untuk mengecek unit asli dari admin tersebut
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.username, u.photo, u.created_at
    FROM users u
    LEFT JOIN staff s ON s.user_id = u.id
    {$whereClause}
    ORDER BY u.name
");
$stmt->execute($params);
$admins = $stmt->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Admin / Pemantau</h3>
            <small>Daftar admin yang bertugas mengelola absensi kelas/grade.</small>
        </div>
        <?php if ($currentRole === 'super_admin'): ?>
            <a href="create.php" class="btn btn-primary">+ Tambah Admin</a>
        <?php endif; ?>
    </div>

    <div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Foto</th>
                <th>Nama</th>
                <th>Username</th>
                <th>Akses (Grade/Subkelas)</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$admins): ?>
            <tr><td colspan="6" style="text-align:center">Belum ada admin yang bertugas.</td></tr>
        <?php endif; ?>

        <?php foreach ($admins as $index => $admin): ?>
            <?php
            // Ambil detail Grade Permission
            $stmt = $pdo->prepare("
                SELECT g.grade, g.unit_id FROM admin_grade_permissions agp
                INNER JOIN grades g ON g.id = agp.grade_id
                WHERE agp.user_id = ? ORDER BY g.sort_order
            ");
            $stmt->execute([$admin['id']]);
            $gradePermissions = $stmt->fetchAll();

            // Ambil detail Class Permission
            $stmt = $pdo->prepare("
                SELECT g.grade, cg.name, g.unit_id FROM admin_class_permissions acp
                INNER JOIN class_groups cg ON cg.id = acp.class_group_id
                INNER JOIN grades g ON g.id = cg.grade_id
                WHERE acp.user_id = ? ORDER BY g.sort_order, cg.name
            ");
            $stmt->execute([$admin['id']]);
            $classPermissions = $stmt->fetchAll();
            ?>
            <tr>
                <td><?= $index + 1 ?></td>
                <td>
                    <?php if (!empty($admin['photo'])): ?>
                        <img src="../../uploads/admins/<?= e($admin['photo']) ?>" width="45" height="45" style="object-fit:cover; border-radius:10px;">
                    <?php else: ?>
                        <div class="avatar"><?= strtoupper(substr($admin['name'], 0, 1)) ?></div>
                    <?php endif; ?>
                </td>
                <td><strong><?= e($admin['name']) ?></strong></td>
                <td><?= e($admin['username']) ?></td>
                <td style="max-width: 300px; display: flex; flex-wrap: wrap; gap: 4px;">
                    <?php foreach ($gradePermissions as $permission): ?>
                        <span class="badge badge-success"><?= e($permission['grade']) ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($classPermissions as $permission): ?>
                        <span class="badge badge-primary" style="background:#0284c7; color:white;">
                            <?= e($permission['grade']) ?> - <?= e($permission['name']) ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if (!$gradePermissions && !$classPermissions): ?>
                        <span class="badge badge-danger">Belum ada akses</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="POST" action="make_staff.php" style="display:inline" onsubmit="return confirm('Jadikan admin ini sebagai staff biasa? Hak akses pemantaunya akan dicabut.')">
                        <input type="hidden" name="id" value="<?= $admin['id'] ?>">
                        <button class="btn btn-warning" type="submit" style="padding: 4px 8px; font-size: 12px; background-color: #f59e0b; color: white;">Jadikan Staff</button>
                    </form>
                    
                    <a href="edit.php?id=<?= $admin['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size: 12px;">Edit</a>
                    
                    <?php if ($currentRole === 'super_admin'): ?>
                    <form method="POST" action="delete.php" style="display:inline" onsubmit="return confirm('Hapus admin ini?')">
                        <input type="hidden" name="id" value="<?= $admin['id'] ?>">
                        <button class="btn btn-danger" type="submit" style="padding: 4px 8px; font-size: 12px;">Hapus</button>
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