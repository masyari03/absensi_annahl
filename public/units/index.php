<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Master Unit';

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

require '../../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <div>
            <h3>Master Unit</h3>
        </div>
        <a href="create.php" class="btn btn-primary">+ Tambah Unit</a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Unit</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($units as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td>
                        <span class="badge badge-primary" style="background:#0284c7; color:white;">
                            <?= e($u['unit']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="edit.php?id=<?= $u['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size: 12px;">Edit</a>
                        <form method="POST" action="delete.php" style="display:inline;">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button class="btn btn-danger" style="padding: 4px 8px; font-size: 12px;" onclick="return confirm('Yakin ingin menghapus unit ini?');">Hapus</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require '../../includes/footer.php'; ?>