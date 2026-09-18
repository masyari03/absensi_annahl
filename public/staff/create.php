<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Tambah Staff';

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $nik = trim($_POST['nik'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $unitId = (int)($_POST['unit_id'] ?? 0);

    if ($name === '' || $nik === '' || $username === '' || $password === '' || $unitId <= 0) {
        $error = 'Semua field wajib diisi, termasuk Unit.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Proses Upload Foto
            $photoNameSave = null;
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $photoNameSave = uniqid('staff_', true) . '.' . $ext;
                $uploadDir = '../../uploads/staff/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoNameSave);
            }

            $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role) VALUES (?, ?, ?, 'staff')");
            $stmt->execute([$username, hashPassword($password), $name]);
            $userId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO staff (user_id, unit_id, name, nik, photo) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $unitId, $name, $nik, $photoNameSave]);

            $pdo->commit();
            flash('success', 'Staff berhasil ditambahkan.');
            redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = $e->getMessage();
        }
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Tambah Staff / Guru</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit *</label>
                <select name="unit_id" required>
                    <option value="">Pilih Unit...</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Nama Lengkap *</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>NIK / Barcode *</label>
                <input type="text" name="nik" required>
            </div>
            <div class="form-group">
                <label>Username (Untuk Login) *</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group full">
                <label>Foto Profil (Opsional)</label>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                <small>Maksimal 2 MB.</small>
            </div>
        </div>
        <div style="margin-top:20px">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>