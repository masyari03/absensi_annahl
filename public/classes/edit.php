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

$pageTitle = 'Edit Subkelas';
$userId = currentUserId();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT cg.*, g.unit_id 
    FROM class_groups cg 
    INNER JOIN grades g ON g.id = cg.grade_id 
    WHERE cg.id = ? LIMIT 1
");
$stmt->execute([$id]);
$class = $stmt->fetch();

if (!$class) { die('Subkelas tidak ditemukan.'); }

$whereGrade = "";
$paramsGrade = [];

// BENTENG KEAMANAN KEPALA SEKOLAH
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    // Pastikan Kepsek tidak membajak Edit milik unit lain
    if ($class['unit_id'] != $ksUnitId) {
        flash('error', 'Akses ditolak. Subkelas ini berada di luar kendali unit Anda.');
        redirect('index.php');
        exit;
    }

    $whereGrade = "WHERE unit_id = ?";
    $paramsGrade[] = $ksUnitId;
}

// Menampilkan dropdown grade (dibatasi unit jika kepsek)
$stmtGrade = $pdo->prepare("SELECT * FROM grades {$whereGrade} ORDER BY sort_order, grade");
$stmtGrade->execute($paramsGrade);
$grades = $stmtGrade->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gradeId = (int)($_POST['grade_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($gradeId > 0 && $name !== '') {
        $stmt = $pdo->prepare("UPDATE class_groups SET grade_id = ?, name = ? WHERE id = ?");
        $stmt->execute([$gradeId, $name, $id]);

        flash('success', 'Subkelas berhasil diperbarui.');
        redirect('index.php');
    } else {
        flash('error', 'Semua kolom wajib diisi.');
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Edit Subkelas</h3>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Grade *</label>
                <select name="grade_id" required>
                    <?php foreach ($grades as $grade): ?>
                        <option value="<?= $grade['id'] ?>" <?= ($class['grade_id'] == $grade['id']) ? 'selected' : '' ?>>
                            <?= e($grade['grade']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Subkelas *</label>
                <input type="text" name="name" value="<?= e($class['name']) ?>" required>
            </div>
        </div>
        
        <div style="margin-top:20px">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>