<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Tambah Unit';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT INTO units (unit, created_at) VALUES (?, NOW())");
    $stmt->execute([trim($_POST['unit'])]);
    flash('success', 'Unit berhasil ditambahkan.');
    redirect('index.php');
}

require '../../includes/header.php';
?>
<div class="card">
    <h3>Tambah Unit</h3>
    <form method="POST">
        <div class="form-group">
            <label>Nama Unit (Misal: SD, SMP, SMA)</label>
            <input type="text" name="unit" required>
        </div>
        <div style="margin-top:20px">
            <button class="btn btn-primary">Simpan</button>
            <a href="index.php" class="btn btn-success">Batal</a>
        </div>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>