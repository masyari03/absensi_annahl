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

$pageTitle = 'Subkelas';
$userId = currentUserId();

$whereSql = "";
$params = [];

if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    if ($ksUnitId) {
        $whereSql = "WHERE g.unit_id = ?";
        $params[] = $ksUnitId;
    } else {
        $whereSql = "WHERE 1 = 0"; // Sembunyikan jika tidak punya unit
    }
}

$stmt = $pdo->prepare("
    SELECT cg.*, g.grade, un.unit AS unit_name
    FROM class_groups cg
    INNER JOIN grades g ON g.id = cg.grade_id
    LEFT JOIN units un ON un.id = g.unit_id
    {$whereSql}
    ORDER BY un.id ASC, g.sort_order ASC, cg.name ASC
");
$stmt->execute($params);
$classGroups = $stmt->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Subkelas</h3>
            <small><?= $currentRole === 'kepala_sekolah' ? 'Menampilkan data khusus Unit Anda.' : 'Menampilkan seluruh data Subkelas.' ?></small>
        </div>
        <a href="create.php" class="btn btn-primary">+ Tambah Subkelas</a>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Unit</th>
                    <th>Grade</th>
                    <th>Subkelas</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$classGroups): ?>
                    <tr><td colspan="5" style="text-align:center">Data subkelas tidak ditemukan.</td></tr>
                <?php endif; ?>

                <?php foreach ($classGroups as $index => $class): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <span class="badge badge-primary" style="background:#0284c7; color:white;">
                            <?= e($class['unit_name'] ?? '-') ?>
                        </span>
                    </td>
                    <td><?= e($class['grade']) ?></td>
                    <td><?= e($class['name']) ?></td>
                    <td>
                        <a href="edit.php?id=<?= $class['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size:12px;">Edit</a>
                        
                        <form action="delete.php" method="POST" style="display:inline" onsubmit="return confirm('Hapus subkelas ini?')">
                            <input type="hidden" name="id" value="<?= $class['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size:12px;">Hapus</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>