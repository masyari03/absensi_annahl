<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Tambah Admin';

$grades = $pdo->query("SELECT * FROM grades ORDER BY sort_order, grade")->fetchAll();
$classGroups = $pdo->query("
    SELECT cg.id, cg.name, cg.grade_id, g.grade
    FROM class_groups cg
    INNER JOIN grades g ON g.id = cg.grade_id
    ORDER BY g.sort_order, cg.name
")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $gradeIds = array_map('intval', $_POST['grade_ids'] ?? []);
    $classIds = array_map('intval', $_POST['class_ids'] ?? []);

    if ($name === '' || $username === '' || $password === '') {
        $error = 'Nama, username, dan password wajib diisi.';
    } else {
        try {
            $pdo->beginTransaction();

            // PROSES UPLOAD FOTO ADMIN
            $photoNameSave = null;
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $photoNameSave = uniqid('admin_', true) . '.' . $ext;
                $uploadDir = '../../uploads/admins/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoNameSave);
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, name, role, photo) 
                VALUES (?, ?, ?, 'admin', ?)
            ");
            $stmt->execute([
                $username,
                hashPassword($password),
                $name,
                $photoNameSave
            ]);

            $userId = $pdo->lastInsertId();

            $stmtGrade = $pdo->prepare("INSERT INTO admin_grade_permissions (user_id, grade_id) VALUES (?, ?)");
            foreach (array_unique($gradeIds) as $gradeId) {
                if ($gradeId > 0) { $stmtGrade->execute([$userId, $gradeId]); }
            }

            $stmtClass = $pdo->prepare("INSERT INTO admin_class_permissions (user_id, class_group_id) VALUES (?, ?)");
            foreach (array_unique($classIds) as $classId) {
                if ($classId > 0) { $stmtClass->execute([$userId, $classId]); }
            }

            $pdo->commit();
            flash('success', 'Admin berhasil dibuat.');
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
    <h3>Tambah Admin Pemantau</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>Nama</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>Foto Profil (Opsional)</label>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
            </div>

            <div class="form-group full">
                <label>Akses Grade</label>
                <div style="display:grid; grid-template-columns: repeat(auto-fit,minmax(130px,1fr)); gap:10px;">
                    <?php foreach ($grades as $grade): ?>
                    <label style="border:1px solid #e5e7eb; padding:12px; border-radius:10px; cursor:pointer;">
                        <input type="checkbox" name="grade_ids[]" value="<?= $grade['id'] ?>">
                        <?= e($grade['grade']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group full">
                <label>Akses Subkelas</label>
                <div style="display:grid; grid-template-columns: repeat(auto-fit,minmax(130px,1fr)); gap:10px;">
                    <?php foreach ($classGroups as $class): ?>
                    <label style="border:1px solid #e5e7eb; padding:12px; border-radius:10px; cursor:pointer;">
                        <input type="checkbox" name="class_ids[]" value="<?= $class['id'] ?>">
                        <?= e($class['grade']) ?> - <?= e($class['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div style="margin-top:20px">
            <button type="submit" class="btn btn-primary">Simpan Admin</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>