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

$pageTitle = 'Edit Grade';
$userId = currentUserId();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM grades WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$grade = $stmt->fetch();

if (!$grade) { die('Grade tidak ditemukan.'); }

// PROTEKSI: Cegah Kepsek mengedit Grade dari Unit lain
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    if ($grade['unit_id'] != $ksUnitId) {
        flash('error', 'Akses ditolak. Grade ini berada di luar kendali unit Anda.');
        redirect('index.php');
        exit;
    }
}

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kepsek tidak bisa ganti unit, ambil unit lama dari DB
    $unitId = $currentRole === 'super_admin' ? (int)($_POST['unit_id'] ?? 0) : $grade['unit_id'];
    $name = trim($_POST['grade'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($unitId > 0 && $name !== '') {
        $stmt = $pdo->prepare("UPDATE grades SET unit_id = ?, grade = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$unitId, $name, $sortOrder, $id]);

        flash('success', 'Grade berhasil diperbarui.');
        redirect('index.php');
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Edit Grade</h3>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit *</label>
                <?php if ($currentRole === 'super_admin'): ?>
                    <select name="unit_id" required>
                        <option value="">Pilih Unit...</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $grade['unit_id'] == $u['id'] ? 'selected' : '' ?>>
                                <?= e($u['unit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php 
                        $unitNameStr = '-';
                        foreach ($units as $u) {
                            if($u['id'] == $grade['unit_id']) { $unitNameStr = $u['unit']; break; }
                        }
                    ?>
                    <input type="text" value="<?= e($unitNameStr) ?>" disabled style="background:#f1f5f9; cursor:not-allowed;">
                    <small style="color:#ef4444;">Unit tidak dapat diubah oleh Kepala Sekolah.</small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Nama Grade *</label>
                <input type="text" name="grade" value="<?= e($grade['grade']) ?>" required>
            </div>
            
            <div class="form-group">
                <label>Urutan</label>
                <input type="number" name="sort_order" value="<?= e($grade['sort_order']) ?>">
            </div>
        </div>

        <div style="margin-top:20px">
            <button class="btn btn-primary">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>