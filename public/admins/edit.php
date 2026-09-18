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

$pageTitle = 'Edit Admin';
$userId = currentUserId();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
$stmt->execute([$id]);
$admin = $stmt->fetch();

if (!$admin) { die('Admin tidak ditemukan.'); }

// BATASI TAMPILAN KELAS BERDASARKAN ROLE
$gradeSql = "SELECT * FROM grades ";
$cgSql = "SELECT cg.id, cg.name, cg.grade_id, g.grade FROM class_groups cg INNER JOIN grades g ON g.id = cg.grade_id ";
$queryParams = [];
$ksUnitId = null;

if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    $gradeSql .= "WHERE unit_id = ? ";
    $cgSql .= "WHERE g.unit_id = ? ";
    $queryParams[] = $ksUnitId;
}

$gradeSql .= "ORDER BY sort_order, grade";
$cgSql .= "ORDER BY g.sort_order, cg.name";

$stmtG = $pdo->prepare($gradeSql); $stmtG->execute($queryParams); $grades = $stmtG->fetchAll();
$stmtC = $pdo->prepare($cgSql); $stmtC->execute($queryParams); $classGroups = $stmtC->fetchAll();

// Ambil akses admin saat ini
$stmt = $pdo->prepare("SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?");
$stmt->execute([$id]);
$selectedGrades = array_map('intval', array_column($stmt->fetchAll(), 'grade_id'));

$stmt = $pdo->prepare("SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?");
$stmt->execute([$id]);
$selectedClasses = array_map('intval', array_column($stmt->fetchAll(), 'class_group_id'));

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $gradeIds = array_map('intval', $_POST['grade_ids'] ?? []);
    $classIds = array_map('intval', $_POST['class_ids'] ?? []);
    $photoNameSave = $admin['photo'];

    try {
        $pdo->beginTransaction();

        // UPLOAD FOTO
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photoNameSave = uniqid('admin_', true) . '.' . $ext;
            $uploadDir = '../../uploads/admins/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            if (!empty($admin['photo']) && file_exists($uploadDir . $admin['photo'])) {
                unlink($uploadDir . $admin['photo']);
            }
            move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoNameSave);
        }

        // UPDATE PROFIL & PASSWORD
        if ($currentRole === 'super_admin' && $password !== '') {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, password = ?, photo = ? WHERE id = ?");
            $stmt->execute([$name, $username, hashPassword($password), $photoNameSave, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, photo = ? WHERE id = ?");
            $stmt->execute([$name, $username, $photoNameSave, $id]);
        }

        // UPDATE HAK AKSES AMAN
        if ($currentRole === 'super_admin') {
            // Super Admin me-reset semua akses
            $pdo->prepare("DELETE FROM admin_grade_permissions WHERE user_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM admin_class_permissions WHERE user_id = ?")->execute([$id]);
        } else {
            // Kepsek HANYA me-reset akses yang beririsan dengan unitnya (Tidak merusak hak akses di unit lain)
            $pdo->prepare("DELETE agp FROM admin_grade_permissions agp INNER JOIN grades g ON g.id = agp.grade_id WHERE agp.user_id = ? AND g.unit_id = ?")->execute([$id, $ksUnitId]);
            $pdo->prepare("DELETE acp FROM admin_class_permissions acp INNER JOIN class_groups cg ON cg.id = acp.class_group_id INNER JOIN grades g ON g.id = cg.grade_id WHERE acp.user_id = ? AND g.unit_id = ?")->execute([$id, $ksUnitId]);
        }

        // INSERT AKSES BARU
        $stmtGrade = $pdo->prepare("INSERT INTO admin_grade_permissions (user_id, grade_id) VALUES (?, ?)");
        foreach (array_unique($gradeIds) as $gId) { if ($gId > 0) { $stmtGrade->execute([$id, $gId]); } }

        $stmtClass = $pdo->prepare("INSERT INTO admin_class_permissions (user_id, class_group_id) VALUES (?, ?)");
        foreach (array_unique($classIds) as $cId) { if ($cId > 0) { $stmtClass->execute([$id, $cId]); } }

        $pdo->commit();
        flash('success', 'Data dan akses admin berhasil diperbarui.');
        redirect('index.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Edit Admin / Pemantau</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>Nama Lengkap</label>
                <input type="text" name="name" required value="<?= e($admin['name']) ?>">
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required value="<?= e($admin['username']) ?>">
            </div>
            
            <?php if ($currentRole === 'super_admin'): ?>
            <div class="form-group">
                <label>Password Baru</label>
                <input type="password" name="password" placeholder="Kosongkan jika tidak diganti">
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Ganti Foto (Opsional)</label>
                <?php if (!empty($admin['photo'])): ?>
                    <div style="margin-bottom:10px;">
                        <img src="../../uploads/admins/<?= e($admin['photo']) ?>" width="60" style="border-radius:10px;">
                    </div>
                <?php endif; ?>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
            </div>

            <div class="form-group full">
                <label>Akses Tingkat (Grade) <?= $currentRole === 'kepala_sekolah' ? '<small style="color:#0284c7;">(Hanya unit Anda)</small>' : '' ?></label>
                <div style="display:grid; grid-template-columns: repeat(auto-fit,minmax(130px,1fr)); gap:10px;">
                    <?php if(!$grades) echo "<i>Tidak ada pilihan grade.</i>"; ?>
                    <?php foreach ($grades as $grade): ?>
                    <label style="padding:12px; border:1px solid #e5e7eb; border-radius:10px; cursor:pointer;">
                        <input type="checkbox" name="grade_ids[]" value="<?= $grade['id'] ?>" <?= in_array((int)$grade['id'], $selectedGrades, true) ? 'checked' : '' ?>>
                        <?= e($grade['grade']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group full">
                <label>Akses Subkelas <?= $currentRole === 'kepala_sekolah' ? '<small style="color:#0284c7;">(Hanya unit Anda)</small>' : '' ?></label>
                <div style="display:grid; grid-template-columns: repeat(auto-fit,minmax(130px,1fr)); gap:10px;">
                    <?php if(!$classGroups) echo "<i>Tidak ada pilihan subkelas.</i>"; ?>
                    <?php foreach ($classGroups as $class): ?>
                    <label style="padding:12px; border:1px solid #e5e7eb; border-radius:10px; cursor:pointer;">
                        <input type="checkbox" name="class_ids[]" value="<?= $class['id'] ?>" <?= in_array((int)$class['id'], $selectedClasses, true) ? 'checked' : '' ?>>
                        <?= e($class['grade']) ?> - <?= e($class['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div style="margin-top:20px; display:flex; gap:10px;">
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            <a href="index.php" class="btn btn-success" style="background:#ef4444; border:none;">Batal</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>