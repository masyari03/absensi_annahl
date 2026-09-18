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

$pageTitle = 'Tambah Subkelas';
$userId = currentUserId();

$whereGrade = "";
$paramsGrade = [];

// Filter pilihan Grade khusus untuk Kepala Sekolah
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    $whereGrade = "WHERE unit_id = ?";
    $paramsGrade[] = $ksUnitId;
}

$stmt = $pdo->prepare("SELECT * FROM grades {$whereGrade} ORDER BY sort_order, grade");
$stmt->execute($paramsGrade);
$grades = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gradeId = (int)($_POST['grade_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($gradeId > 0 && $name !== '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO class_groups (grade_id, name) VALUES (?, ?)");
            $stmt->execute([$gradeId, $name]);

            flash('success', 'Subkelas berhasil dibuat.');
            redirect('index.php');
        } catch (Throwable $e) {
            flash('error', 'Gagal menambah data: ' . $e->getMessage());
        }
    } else {
        flash('error', 'Grade dan Nama Subkelas wajib diisi.');
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Tambah Subkelas</h3>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Grade *</label>
                <select name="grade_id" required>
                    <option value="">Pilih Grade...</option>
                    <?php foreach ($grades as $grade): ?>
                        <option value="<?= $grade['id'] ?>"><?= e($grade['grade']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($grades) && $currentRole === 'kepala_sekolah'): ?>
                    <small style="color:red;">Tidak ada Grade di unit Anda. Buat Grade terlebih dahulu.</small>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label>Nama Subkelas *</label>
                <input type="text" name="name" placeholder="Contoh: 7A" required>
            </div>
        </div>
        
        <div style="margin-top:20px">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>