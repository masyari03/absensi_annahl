<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Edit Unit';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM units WHERE id = ?");
$stmt->execute([$id]);
$unit = $stmt->fetch();

if (!$unit) {
    die('Unit tidak ditemukan.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("UPDATE units SET unit = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([trim($_POST['unit']), $id]);
    flash('success', 'Unit berhasil diperbarui.');
    redirect('index.php');
}

require '../../includes/header.php';
?>
<div class="card">
    <h3>Edit Unit</h3>
    <form method="POST">
        <div class="form-group">
            <label>Nama Unit</label>
            <input type="text" name="unit" value="<?= e($unit['unit']) ?>" required>
        </div>
        <div style="margin-top:20px">
            <button class="btn btn-primary">Update</button>
            <a href="index.php" class="btn btn-success">Batal</a>
        </div>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>