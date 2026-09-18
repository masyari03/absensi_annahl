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

$pageTitle = 'Grade / Tingkat';
$userId = currentUserId();

$whereSql = "";
$params = [];

if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    
    if ($ksUnitId) {
        $whereSql = "WHERE g.unit_id = ?";
        $params[] = $ksUnitId;
    } else {
        $whereSql = "WHERE 1 = 0"; // Jika tidak punya unit, sembunyikan semua
    }
}

$grades = $pdo->prepare("
    SELECT 
        g.*, 
        un.unit AS unit_name,
        COUNT(cg.id) AS total_classes
    FROM grades g
    LEFT JOIN units un ON un.id = g.unit_id
    LEFT JOIN class_groups cg ON cg.grade_id = g.id
    {$whereSql}
    GROUP BY g.id
    ORDER BY un.id ASC, g.sort_order ASC, g.grade ASC
");
$grades->execute($params);
$gradesData = $grades->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Grade / Tingkat Kelas</h3>
            <small><?= $currentRole === 'kepala_sekolah' ? 'Menampilkan data khusus Unit Anda.' : 'Menampilkan seluruh data Grade.' ?></small>
        </div>
        <a href="create.php" class="btn btn-primary">+ Tambah Grade</a>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Unit</th>
                    <th>Grade</th>
                    <th>Urutan</th>
                    <th>Total Subkelas</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$gradesData): ?>
                    <tr><td colspan="6" style="text-align:center">Data grade tidak ditemukan.</td></tr>
                <?php endif; ?>

                <?php foreach ($gradesData as $index => $grade): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <span class="badge badge-primary" style="background:#0284c7; color:white;">
                            <?= e($grade['unit_name'] ?? '-') ?>
                        </span>
                    </td>
                    <td><?= e($grade['grade']) ?></td>
                    <td><?= e($grade['sort_order']) ?></td>
                    <td><?= number_format($grade['total_classes']) ?></td>
                    <td>
                        <a href="edit.php?id=<?= $grade['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size:12px;">Edit</a>
                        
                        <!-- TOMBOL HAPUS SEKARANG BISA DIAKSES SUPER ADMIN & KEPSEK -->
                        <?php if (in_array($currentRole, ['super_admin', 'kepala_sekolah'])): ?>
                        <form method="POST" action="delete.php" style="display:inline" onsubmit="return confirm('Hapus grade ini?')">
                            <input type="hidden" name="id" value="<?= $grade['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size:12px;">Hapus</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>