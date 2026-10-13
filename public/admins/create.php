<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_admins');
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
                if ($_FILES['photo']['size'] > 2 * 1024 * 1024) { 
                    throw new Exception('Ukuran foto maksimal 2 MB.'); 
                }

                $allowed = [
                    'image/jpeg'  => 'jpg',
                    'image/pjpeg' => 'jpg',
                    'image/jpg'   => 'jpg',
                    'image/png'   => 'png',
                    'image/x-png' => 'png',
                    'image/webp'  => 'webp'
                ];

                $imgInfo = @getimagesize($_FILES['photo']['tmp_name']);
                if (!$imgInfo) {
                    throw new Exception('File yang diunggah bukan file gambar yang valid.');
                }
                $mime = $imgInfo['mime'] ?? @mime_content_type($_FILES['photo']['tmp_name']);
                if (!isset($allowed[$mime])) { 
                    throw new Exception('Format foto harus JPG, PNG, atau WEBP.'); 
                }

                $uploadDir = defined('UPLOAD_PATH') ? UPLOAD_PATH . '/admins/' : '../../uploads/admins/';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
                @chmod($uploadDir, 0777);

                $photoNameSave = uniqid('admin_', true) . '.' . $allowed[$mime];
                $targetFile = $uploadDir . $photoNameSave;

                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $targetFile)) {
                    throw new Exception('Gagal menyimpan file foto ke direktori uploads/admins.');
                }
                @chmod($targetFile, 0644);
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