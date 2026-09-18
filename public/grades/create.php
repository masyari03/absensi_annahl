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

$pageTitle = 'Tambah Grade';
$userId = currentUserId();

$ksUnitId = 0;
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();
}

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Super Admin bebas milih, Kepsek dipaksa pakai Unit-nya
    $unitId = $currentRole === 'super_admin' ? (int)($_POST['unit_id'] ?? 0) : $ksUnitId;
    $grade = trim($_POST['grade'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($unitId <= 0 || $grade === '') {
        $error = 'Unit dan Nama grade wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO grades (unit_id, grade, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$unitId, $grade, $sortOrder]);

            flash('success', 'Grade berhasil dibuat.');
            redirect('index.php');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Tambah Grade</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit *</label>
                <?php if ($currentRole === 'super_admin'): ?>
                    <select name="unit_id" required>
                        <option value="">Pilih Unit...</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php 
                        $unitNameStr = '-';
                        foreach ($units as $u) {
                            if($u['id'] == $ksUnitId) { $unitNameStr = $u['unit']; break; }
                        }
                    ?>
                    <input type="text" value="<?= e($unitNameStr) ?>" disabled style="background:#f1f5f9; cursor:not-allowed;">
                    <small style="color:#0284c7;">Grade akan otomatis ditambahkan ke unit Anda.</small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Nama Grade *</label>
                <input type="text" name="grade" placeholder="Contoh: Kelas 7" required>
            </div>
            
            <div class="form-group">
                <label>Urutan (Opsional)</label>
                <input type="number" name="sort_order" value="0">
                <small>Angka terkecil tampil lebih dulu.</small>
            </div>
        </div>
        
        <div style="margin-top:20px">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>