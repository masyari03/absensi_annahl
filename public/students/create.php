<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

$currentRole = $_SESSION['role'] ?? '';
if (!in_array($currentRole, ['super_admin', 'kepala_sekolah'])) {
    redirect('index.php');
}

$pageTitle = 'Tambah Siswa';
$userId = currentUserId();

/*
|--------------------------------------------------------------------------
| DATA SUBKELAS (DI-FILTER JIKA KEPALA SEKOLAH)
|--------------------------------------------------------------------------
*/
$cgSql = "
    SELECT cg.id, cg.name, cg.grade_id, g.grade 
    FROM class_groups cg 
    INNER JOIN grades g ON g.id = cg.grade_id 
";
$cgParams = [];

if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    
    $cgSql .= " WHERE g.unit_id = ? ";
    $cgParams[] = $ksUnitId;
}

$cgSql .= " ORDER BY g.sort_order, cg.name";
$stmtCg = $pdo->prepare($cgSql);
$stmtCg->execute($cgParams);
$classGroups = $stmtCg->fetchAll();

$stmtAy = $pdo->query("SELECT * FROM academic_years WHERE status = 'active' LIMIT 1");
$academicYear = $stmtAy->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $nis = trim($_POST['nis'] ?? '');
    $nik = trim($_POST['nik'] ?? '');
    $classGroupId = (int)($_POST['class_group_id'] ?? 0);

    if (!$academicYear) {
        $error = 'Belum ada Tahun Ajaran aktif.';
    } elseif ($name === '' || $classGroupId <= 0) {
        $error = 'Nama dan subkelas wajib diisi.';
    } else {
        try {
            $pdo->beginTransaction();

            $photo = null;
            if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $mime = mime_content_type($_FILES['photo']['tmp_name']);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime])) { throw new Exception('Format foto harus JPG, PNG, atau WEBP.'); }
                if ($_FILES['photo']['size'] > 2 * 1024 * 1024) { throw new Exception('Ukuran foto maksimal 2 MB.'); }
                if (!is_dir(UPLOAD_STUDENT)) { mkdir(UPLOAD_STUDENT, 0755, true); }
                
                $photo = uniqid('student_', true) . '.' . $allowed[$mime];
                move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_STUDENT . $photo);
            }

            $stmt = $pdo->prepare("INSERT INTO students (name, nis, nik, photo) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $nis !== '' ? $nis : null, $nik !== '' ? $nik : null, $photo]);
            $studentId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO student_enrollments (student_id, academic_year_id, class_group_id, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$studentId, $academicYear['id'], $classGroupId]);

            $pdo->commit();
            flash('success', 'Siswa berhasil ditambahkan.');
            redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if (!empty($photo) && file_exists(UPLOAD_STUDENT . $photo)) { unlink(UPLOAD_STUDENT . $photo); }
            $error = $e->getMessage();
        }
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Tambah Siswa</h3>
            <small>Tahun Ajaran: <?= e($academicYear['name'] ?? 'Belum aktif') ?></small>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>Nama Siswa *</label>
                <input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>NIS</label>
                <input type="text" name="nis" value="<?= e($_POST['nis'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>NIK</label>
                <input type="text" name="nik" value="<?= e($_POST['nik'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Subkelas *</label>
                <select name="class_group_id" required>
                    <option value="">Pilih Subkelas</option>
                    <?php foreach ($classGroups as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= (($_POST['class_group_id'] ?? '') == $class['id']) ? 'selected' : '' ?>>
                            <?= e($class['grade']) ?> - <?= e($class['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full">
                <label>Foto</label>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                <small>Maksimal 2 MB.</small>
            </div>
        </div>
        <div style="margin-top:20px">
            <button type="submit" class="btn btn-primary">Simpan Siswa</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>