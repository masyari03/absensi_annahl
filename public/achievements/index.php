<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();

$pageTitle = 'Rekapan Prestasi & Piagam Siswa';
$currentRole = currentRole();
$userId = currentUserId();

// PROSES VERIFIKASI PRESTASI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify'])) {
    $achId = (int)($_POST['achievement_id'] ?? 0);
    $action = $_POST['action_verify']; // 'verify' atau 'reject'

    $stmtCheck = $pdo->prepare("SELECT * FROM student_achievements WHERE id = ?");
    $stmtCheck->execute([$achId]);
    $ach = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($ach) {
        $newStatus = ($action === 'verify') ? 'verified' : 'rejected';
        $stmtUp = $pdo->prepare("
            UPDATE student_achievements 
            SET status = ?, verified_by = ?, verified_at = NOW() 
            WHERE id = ?
        ");
        $stmtUp->execute([$newStatus, $userId, $achId]);

        flash('success', 'Status piagam prestasi berhasil diperbarui menjadi: ' . strtoupper($newStatus));
    }
    redirect('index.php');
}

// MULTI-UNIT RBAC
$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();
$unitId = (int)($_GET['unit_id'] ?? 0);

$where = ["1=1"];
$params = [];

if ($currentRole === 'kepala_sekolah') {
    // Kepala Sekolah KETAT hanya melihat unitnya sendiri
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    $unitId = $ksUnitId ? (int)$ksUnitId : -1;
    $where[] = "un.id = ?";
    $params[] = $unitId;
} elseif ($currentRole === 'super_admin') {
    // Super Admin bebas filter semua unit
    if ($unitId > 0) {
        $where[] = "un.id = ?";
        $params[] = $unitId;
    }
}

$statusFilter = $_GET['status'] ?? '';
if ($statusFilter !== '') {
    $where[] = "sa.status = ?";
    $params[] = $statusFilter;
}

$nameFilter = trim($_GET['name'] ?? '');
if ($nameFilter !== '') {
    $where[] = "s.name LIKE ?";
    $params[] = "%{$nameFilter}%";
}

$whereSql = implode(' AND ', $where);

$stmtList = $pdo->prepare("
    SELECT sa.*, s.name as student_name, s.nis, s.photo as student_photo,
           cg.name as class_name, g.grade, un.unit as unit_name,
           u.name as verifier_name
    FROM student_achievements sa
    INNER JOIN students s ON s.id = sa.student_id
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    LEFT JOIN units un ON un.id = g.unit_id
    LEFT JOIN users u ON u.id = sa.verified_by
    WHERE {$whereSql}
    ORDER BY sa.created_at DESC
");
$stmtList->execute($params);
$achievements = $stmtList->fetchAll(PDO::FETCH_ASSOC);

require '../../includes/header.php';
?>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header" style="flex-wrap: wrap; gap: 10px; justify-content: space-between;">
        <div>
            <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">
                <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i> Rekapan Prestasi Siswa
            </h3>
            <small style="color: #64748b;">
                <?php if ($currentRole === 'kepala_sekolah'): ?>
                    Menampilkan prestasi siswa pada unit sekolah Anda
                <?php else: ?>
                    Rekapan seluruh unit sekolah (Super Admin / Manajemen)
                <?php endif; ?>
            </small>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET">
        <div class="filter-grid" style="align-items: flex-end;">
            <?php if ($currentRole === 'super_admin'): ?>
                <div class="form-group">
                    <label>Unit Sekolah</label>
                    <select name="unit_id" onchange="this.form.submit()">
                        <option value="0">Semua Unit</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Status Verifikasi</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>Semua Status</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>⏳ Menunggu Verifikasi</option>
                    <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : '' ?>>✅ Terverifikasi</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>❌ Ditolak</option>
                </select>
            </div>

            <div class="form-group">
                <label>Nama Siswa</label>
                <input type="text" name="name" value="<?= e($nameFilter) ?>" placeholder="Cari nama siswa...">
            </div>

            <div>
                <button type="submit" class="btn btn-primary" style="margin-top: 22px;">🔎 Cari</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">No</th>
                    <th>Siswa</th>
                    <th>Unit & Kelas</th>
                    <th>Judul Prestasi</th>
                    <th>Tingkat</th>
                    <th>Kategori</th>
                    <th>Tanggal</th>
                    <th>Berkas Piagam</th>
                    <th>Status</th>
                    <th>Aksi Verifikasi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($achievements)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 40px; color: #94a3b8;">
                            Tidak ada data prestasi siswa yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($achievements as $row): 
                        $statusBadge = [
                            'pending'  => ['#fef3c7', '#92400e', 'Menunggu'],
                            'verified' => ['#dcfce7', '#166534', 'Terverifikasi'],
                            'rejected' => ['#fee2e2', '#991b1b', 'Ditolak']
                        ];
                        $sb = $statusBadge[$row['status']] ?? ['#f1f5f9', '#475569', $row['status']];
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <strong style="color: #0f172a;"><?= e($row['student_name']) ?></strong>
                                <div style="font-size: 11.5px; color: #64748b;">NIS: <?= e($row['nis'] ?? '-') ?></div>
                            </td>
                            <td>
                                <span style="font-weight: 700; color: #0284c7;"><?= e($row['unit_name'] ?? '') ?></span>
                                <div style="font-size: 12px; color: #64748b;"><?= e($row['grade'] ?? '') ?> - <?= e($row['class_name'] ?? '') ?></div>
                            </td>
                            <td>
                                <strong style="color: #0f172a; font-size: 13.5px;"><?= e($row['title']) ?></strong>
                                <?php if (!empty($row['description'])): ?>
                                    <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;"><?= e($row['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight: 700; text-transform: uppercase; font-size: 11px; color: #475569; background: #f1f5f9; padding: 3px 8px; border-radius: 6px;">
                                    <?= str_replace('_', ' ', e($row['level'])) ?>
                                </span>
                            </td>
                            <td><?= e($row['category']) ?></td>
                            <td><?= date('d M Y', strtotime($row['achievement_date'])) ?></td>
                            <td>
                                <a href="<?= UPLOAD_URL ?>/achievements/<?= e($row['certificate_file']) ?>" target="_blank" class="btn" style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; font-size: 11.5px; text-decoration: none; border-radius: 6px;">
                                    <i class="fa-solid fa-file-image"></i> Piagam
                                </a>
                            </td>
                            <td>
                                <span style="padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; background: <?= $sb[0] ?>; color: <?= $sb[1] ?>;">
                                    <?= $sb[2] ?>
                                </span>
                                <?php if (!empty($row['verifier_name'])): ?>
                                    <div style="font-size: 10px; color: #94a3b8; margin-top: 3px;">oleh: <?= e($row['verifier_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'pending'): ?>
                                    <div style="display: flex; gap: 6px;">
                                        <form method="POST" onsubmit="return confirm('Verifikasi dan sahkan piagam prestasi ini?')">
                                            <input type="hidden" name="achievement_id" value="<?= $row['id'] ?>">
                                            <input type="hidden" name="action_verify" value="verify">
                                            <button type="submit" class="btn btn-success" style="padding: 4px 10px; font-size: 12px;">
                                                <i class="fa-solid fa-check"></i> Sahkan
                                            </button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Tolak piagam prestasi ini?')">
                                            <input type="hidden" name="achievement_id" value="<?= $row['id'] ?>">
                                            <input type="hidden" name="action_verify" value="reject">
                                            <button type="submit" class="btn btn-danger" style="padding: 4px 10px; font-size: 12px;">
                                                <i class="fa-solid fa-xmark"></i> Tolak
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: #94a3b8; font-style: italic;">Selesai</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>
