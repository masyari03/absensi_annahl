<?php
require_once '../../config/config.php'; 
require_once '../../config/database.php'; 
require_once '../../config/auth.php'; 
require_once '../../config/security.php'; 
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_activities');
$currentRole = currentRole();
$pageTitle = 'Activities / Kegiatan Khusus';
$userId = currentUserId();

$activeTab = in_array($_GET['tab'] ?? '', ['student', 'staff']) ? $_GET['tab'] : 'student';

$whereSql = ["1 = 1"];
$params = [];

$ksUnitId = null;
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();

    if ($ksUnitId) {
        $whereSql[] = "a.unit_id = ?";
        $params[] = $ksUnitId;
    } else {
        $whereSql[] = "1 = 0"; 
    }
    $filterUnitId = $ksUnitId;
} else {
    $filterUnitId = (int)($_GET['unit_id'] ?? 0);
    if ($filterUnitId > 0) {
        $whereSql[] = "a.unit_id = ?";
        $params[] = $filterUnitId;
    }
}

if ($activeTab === 'student') {
    $whereSql[] = "(a.target_type = 'student' OR a.target_type = 'all' OR a.target_type IS NULL)";
} elseif ($activeTab === 'staff') {
    $whereSql[] = "(a.target_type = 'staff' OR a.target_type = 'all' OR a.target_type IS NULL)";
}

$whereClause = "WHERE " . implode(" AND ", $whereSql);

$stmt = $pdo->prepare("
    SELECT a.*, ay.name AS academic_year, u.unit AS unit_name
    FROM activities a
    INNER JOIN academic_years ay ON ay.id = a.academic_year_id
    INNER JOIN units u ON u.id = a.unit_id
    {$whereClause}
    ORDER BY a.activity_date DESC, a.id DESC
");
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$units = $pdo->query("SELECT id, unit FROM units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
        <div>
            <h3 style="margin:0;"><i class="fa-solid fa-calendar-day" style="color:#0284c7; margin-right:8px;"></i> Activities / Jadwal Kegiatan Khusus</h3>
            <small style="color:#64748b;">Pengaturan jadwal kegiatan khusus atau libur harian yang terpisah antara Siswa dan Staff.</small>
        </div>
        <div>
            <a href="create.php?tab=<?= e($activeTab) ?><?= $filterUnitId ? '&unit_id='.$filterUnitId : '' ?>" class="btn btn-primary" style="font-weight:700;">
                <i class="fa-solid fa-plus-circle"></i> + Tambah Kegiatan <?= $activeTab === 'student' ? 'Siswa' : 'Staff' ?>
            </a>
        </div>
    </div>

    <!-- TABS PEMISAHAN SISWA DAN STAFF -->
    <div style="display:flex; gap:10px; margin-bottom:18px; border-bottom:2px solid #e2e8f0; padding-bottom:8px; flex-wrap:wrap;">
        <a href="index.php?tab=student<?= $filterUnitId && $currentRole === 'super_admin' ? '&unit_id='.$filterUnitId : '' ?>" 
           class="btn <?= $activeTab === 'student' ? 'btn-primary' : 'btn-light' ?>" 
           style="font-weight:700; padding:9px 18px; border-radius:8px;">
            <i class="fa-solid fa-graduation-cap"></i> 🎓 Kegiatan Siswa (KBM / Ujian / Libur)
        </a>
        <a href="index.php?tab=staff<?= $filterUnitId && $currentRole === 'super_admin' ? '&unit_id='.$filterUnitId : '' ?>" 
           class="btn <?= $activeTab === 'staff' ? 'btn-primary' : 'btn-light' ?>" 
           style="font-weight:700; padding:9px 18px; border-radius:8px;">
            <i class="fa-solid fa-user-tie"></i> 💼 Kegiatan Staff & Guru (Raker / Dinas / Libur)
        </a>
    </div>

    <!-- FILTER BAR KHUSUS SUPER ADMIN -->
    <?php if ($currentRole === 'super_admin'): ?>
        <form method="GET" style="display:flex; gap:10px; align-items:center; margin-bottom:18px; background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0; flex-wrap:wrap;">
            <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
            <label style="font-size:13px; font-weight:700; color:#475569;">Filter Unit Sekolah:</label>
            <select name="unit_id" onchange="this.form.submit()" style="padding:6px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:13px;">
                <option value="">-- Semua Unit --</option>
                <?php foreach ($units as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUnitId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($filterUnitId > 0): ?>
                <a href="index.php?tab=<?= e($activeTab) ?>" class="btn btn-sm btn-light" style="padding:6px 12px; font-size:12px;">Reset Filter</a>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <div class="table-wrapper">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#334155;">
                    <th style="padding:10px 14px;">Tanggal</th>
                    <th style="padding:10px 14px;">Unit</th>
                    <th style="padding:10px 14px;">Nama Kegiatan & Tahun Ajaran</th>
                    <?php if ($activeTab === 'student'): ?>
                        <th style="padding:10px 14px;">Jam Masuk Siswa</th>
                        <th style="padding:10px 14px;">Batas Telat</th>
                        <th style="padding:10px 14px;">Jam Pulang</th>
                    <?php else: ?>
                        <th style="padding:10px 14px;">Jam Masuk Staff</th>
                        <th style="padding:10px 14px;">Batas Telat</th>
                        <th style="padding:10px 14px;">Jam Pulang</th>
                    <?php endif; ?>
                    <th style="padding:10px 14px;">Status Hari</th>
                    <th style="padding:10px 14px;">Status</th>
                    <th style="padding:10px 14px; text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activities)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center; padding:32px; color:#64748b;">
                            Belum ada kegiatan <?= $activeTab === 'student' ? 'siswa' : 'staff' ?> yang terdaftar pada unit ini.
                        </td>
                    </tr>
                <?php endif; ?>
                
                <?php foreach ($activities as $act): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9; font-size:13px;">
                        <td style="padding:10px 14px;">
                            <strong><?= date('d/m/Y', strtotime($act['activity_date'])) ?></strong><br>
                            <small style="color:#64748b;"><?= date('l', strtotime($act['activity_date'])) ?></small>
                        </td>
                        <td style="padding:10px 14px;">
                            <span class="badge" style="background:#0284c7; color:white; font-size:11px; padding:3px 8px; border-radius:4px;">
                                <?= e($act['unit_name']) ?>
                            </span>
                        </td>
                        <td style="padding:10px 14px;">
                            <strong><?= e($act['name']) ?></strong><br>
                            <small style="color: #64748b;"><?= e($act['academic_year']) ?></small>
                            <?php if (!empty($act['description'])): ?>
                                <div style="font-size:11.5px; color:#475569; margin-top:2px; font-style:italic;">
                                    <?= e($act['description']) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <?php if ($activeTab === 'student'): ?>
                            <td style="padding:10px 14px; font-family:monospace; color:#059669; font-weight:700;">
                                <?= $act['is_holiday'] === 'yes' ? '-' : substr($act['student_in'] ?? '00:00', 0, 5) ?>
                            </td>
                            <td style="padding:10px 14px; font-family:monospace; color:#d97706; font-weight:700;">
                                <?= $act['is_holiday'] === 'yes' ? '-' : substr($act['student_late'] ?? '00:00', 0, 5) ?>
                            </td>
                            <td style="padding:10px 14px; font-family:monospace; color:#dc2626; font-weight:700;">
                                <?= $act['is_holiday'] === 'yes' ? '-' : substr($act['student_out'] ?? '00:00', 0, 5) ?>
                            </td>
                        <?php else: ?>
                            <td style="padding:10px 14px; font-family:monospace; color:#059669; font-weight:700;">
                                <?= $act['is_holiday'] === 'yes' ? '-' : substr($act['staff_in'] ?? '00:00', 0, 5) ?>
                            </td>
                            <td style="padding:10px 14px; font-family:monospace; color:#d97706; font-weight:700;">
                                <?= $act['is_holiday'] === 'yes' ? '-' : substr($act['staff_late'] ?? '00:00', 0, 5) ?>
                            </td>
                            <td style="padding:10px 14px; font-family:monospace; color:#dc2626; font-weight:700;">
                                <?= $act['is_holiday'] === 'yes' ? '-' : substr($act['staff_out'] ?? '00:00', 0, 5) ?>
                            </td>
                        <?php endif; ?>

                        <td style="padding:10px 14px;">
                            <?php if ($act['is_holiday'] === 'yes'): ?>
                                <span class="badge" style="background:#fee2e2; color:#b91c1c; font-size:11px; padding:3px 8px; border-radius:4px; font-weight:700;">
                                    🏖️ Libur
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background:#dcfce7; color:#15803d; font-size:11px; padding:3px 8px; border-radius:4px; font-weight:700;">
                                    Masuk
                                </span>
                            <?php endif; ?>
                        </td>

                        <td style="padding:10px 14px;">
                            <?= $act['status'] === 'active' 
                                ? '<span class="badge badge-success" style="background:#16a34a; color:white; padding:3px 8px; border-radius:4px; font-size:11px;">Aktif</span>' 
                                : '<span class="badge badge-danger" style="background:#64748b; color:white; padding:3px 8px; border-radius:4px; font-size:11px;">Ditutup</span>' ?>
                        </td>

                        <td style="padding:10px 14px; text-align:center; white-space:nowrap;">
                            <a href="edit.php?id=<?= $act['id'] ?>&tab=<?= e($activeTab) ?>" class="btn btn-sm btn-success" style="padding:4px 8px; font-size:11.5px;">Edit</a>
                            <?php if ($act['status'] === 'active'): ?>
                                <form method="POST" action="close.php" style="display:inline;" onsubmit="return confirm('Tutup kegiatan ini?');">
                                    <input type="hidden" name="id" value="<?= $act['id'] ?>">
                                    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                                    <button class="btn btn-sm btn-danger" style="padding:4px 8px; font-size:11.5px;">Tutup</button>
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
