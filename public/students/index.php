<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_students');
$currentRole = currentRole();
$pageTitle = 'Data Siswa';
$userId = currentUserId();

$name = trim($_GET['name'] ?? '');
$gradeId = (int)($_GET['grade_id'] ?? 0);
$classId = (int)($_GET['class_group_id'] ?? 0);

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll(); 

// PENGUNCIAN UNIT: HANYA SUPER ADMIN YANG BISA FILTER UNIT SECARA BEBAS
if ($currentRole === 'super_admin') {
    $unitId = (int)($_GET['unit_id'] ?? 0);
    if ($unitId > 0) {
        $stmtGrades = $pdo->prepare("SELECT * FROM grades WHERE unit_id = ? ORDER BY sort_order, grade");
        $stmtGrades->execute([$unitId]);
        $grades = $stmtGrades->fetchAll();

        $stmtCg = $pdo->prepare("
            SELECT cg.id, cg.name, cg.grade_id, g.grade
            FROM class_groups cg
            INNER JOIN grades g ON g.id = cg.grade_id
            WHERE g.unit_id = ?
            ORDER BY g.sort_order, cg.name
        ");
        $stmtCg->execute([$unitId]);
        $classGroups = $stmtCg->fetchAll();
    } else {
        $grades = $pdo->query("SELECT * FROM grades ORDER BY sort_order, grade")->fetchAll();
        $classGroups = $pdo->query("
            SELECT cg.id, cg.name, cg.grade_id, g.grade
            FROM class_groups cg
            INNER JOIN grades g ON g.id = cg.grade_id
            ORDER BY g.sort_order, cg.name
        ")->fetchAll();
    }
} elseif ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    $unitId = $ksUnitId ? (int)$ksUnitId : -1; 

    $stmtGrades = $pdo->prepare("SELECT * FROM grades WHERE unit_id = ? ORDER BY sort_order, grade");
    $stmtGrades->execute([$unitId]);
    $grades = $stmtGrades->fetchAll();

    $stmtCg = $pdo->prepare("
        SELECT cg.id, cg.name, cg.grade_id, g.grade
        FROM class_groups cg
        INNER JOIN grades g ON g.id = cg.grade_id
        WHERE g.unit_id = ?
        ORDER BY g.sort_order, cg.name
    ");
    $stmtCg->execute([$unitId]);
    $classGroups = $stmtCg->fetchAll();
} else {
    // Non-superadmin & non-kepsek tidak diizinkan filter unit
    $unitId = 0;
    $grades = $pdo->query("SELECT * FROM grades ORDER BY sort_order, grade")->fetchAll();
    $classGroups = $pdo->query("
        SELECT cg.id, cg.name, cg.grade_id, g.grade
        FROM class_groups cg
        INNER JOIN grades g ON g.id = cg.grade_id
        ORDER BY g.sort_order, cg.name
    ")->fetchAll();
}

$access = getStudentAccessCondition('g', 'cg');

$where = [
    "s.deleted_at IS NULL",
    $access['condition']
];
$params = $access['params'];

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($gradeId > 0) { $where[] = "g.id = ?"; $params[] = $gradeId; }
if ($classId > 0) { $where[] = "cg.id = ?"; $params[] = $classId; }
if ($unitId > 0) { $where[] = "un.id = ?"; $params[] = $unitId; } 

$whereSql = implode(' AND ', $where);

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$countSql = "
    SELECT COUNT(DISTINCT s.id)
    FROM students s
    INNER JOIN student_enrollments se ON se.student_id = s.id
    INNER JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN units un ON un.id = g.unit_id
    WHERE {$whereSql}
";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, ceil($total / $limit));

$sql = "
    SELECT 
        s.id, s.name, s.nis, s.nik, s.photo, s.parent_phone, s.wa_notify, s.rfid_uid, s.fingerprint_id,
        cg.name AS class_name,
        g.grade,
        un.unit AS unit_name
    FROM students s
    INNER JOIN student_enrollments se ON se.student_id = s.id
    INNER JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN units un ON un.id = g.unit_id
    WHERE {$whereSql}
    ORDER BY g.sort_order, cg.name, s.name
    LIMIT {$limit} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 10px;">
        <div>
            <h3>Data Siswa</h3>
            <small><?= number_format($total) ?> siswa</small>
        </div>
        <!-- HANYA SUPER ADMIN DAN KEPSEK YANG BISA TAMBAH / IMPORT / BULK -->
        <?php if (in_array($currentRole, ['super_admin', 'kepala_sekolah'])): ?>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="bulk_edit.php" class="btn" style="background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; font-weight: 700;">
                    <i class="fa-solid fa-table-cells"></i> Bulk Update (Google Admin)
                </a>
                <a href="../whatsapp/index.php?tab=students" class="btn" style="background-color: #059669; color: white;">
                    <i class="fa-brands fa-whatsapp"></i> Atur WA Ortu
                </a>
                <a href="import.php" class="btn" style="background-color: #10b981; color: white;">📄 Import Excel</a>
                <a href="create.php" class="btn btn-primary">+ Tambah Siswa</a>
            </div>
        <?php endif; ?>
    </div>

    <form method="GET">
        <div class="filter-grid">
            <div class="form-group">
                <label>Nama</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="Cari nama...">
            </div>
            
            <?php if ($currentRole === 'super_admin'): ?>
            <div class="form-group">
                <label>Unit</label>
                <select name="unit_id" onchange="this.form.submit()">
                    <option value="0">Semua Unit</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Grade</label>
                <select name="grade_id" id="gradeFilter">
                    <option value="0">Semua Grade</option>
                    <?php foreach ($grades as $grade): ?>
                        <option value="<?= $grade['id'] ?>" <?= $gradeId == $grade['id'] ? 'selected' : '' ?>><?= e($grade['grade']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Subkelas</label>
                <select name="class_group_id">
                    <option value="0">Semua Subkelas</option>
                    <?php foreach ($classGroups as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= $classId == $class['id'] ? 'selected' : '' ?>><?= e($class['name']) ?> - <?= e($class['grade']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button class="btn btn-primary" type="submit" style="margin-top: 22px;">🔎 Cari</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Foto</th>
                    <th>Nama</th>
                    <th>NIS</th>
                    <th>NIK</th>
                    <th>Unit</th>
                    <th>Kelas</th>
                    <th>WA Ortu</th>
                    <th>RFID / Finger</th>
                    <?php if (in_array($currentRole, ['super_admin', 'kepala_sekolah'])): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$students): ?>
                <tr><td colspan="10" style="text-align:center">Data siswa tidak ditemukan.</td></tr>
            <?php endif; ?>

            <?php foreach ($students as $index => $student): ?>
                <tr>
                    <td><?= $offset + $index + 1 ?></td>
                    <td>
                        <?php if (!empty($student['photo'])): ?>
                            <img src="../../uploads/students/<?= e($student['photo']) ?>" width="45" height="45" style="object-fit:cover; border-radius:10px;">
                        <?php else: ?>
                            <div class="avatar"><?= strtoupper(substr($student['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight: 600;"><?= e($student['name']) ?></td>
                    <td><?= e($student['nis'] ?: '-') ?></td>
                    <td><?= e($student['nik'] ?: '-') ?></td>
                    <td>
                        <span class="badge badge-primary" style="background:#0284c7; color:white;">
                            <?= e($student['unit_name']) ?>
                        </span>
                    </td>
                    <td><?= e($student['grade']) ?> - <?= e($student['class_name']) ?></td>
                    <td>
                        <?php if (!empty($student['parent_phone'])): ?>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; background: <?= ((int)$student['wa_notify'] === 1) ? '#dcfce7' : '#f1f5f9' ?>; color: <?= ((int)$student['wa_notify'] === 1) ? '#166534' : '#64748b' ?>; border-radius: 6px; font-size: 11.5px; font-weight: 600;">
                                    <i class="fa-brands fa-whatsapp" style="color: <?= ((int)$student['wa_notify'] === 1) ? '#16a34a' : '#94a3b8' ?>;"></i>
                                    <?= e($student['parent_phone']) ?>
                                </span>
                                <?php if ((int)$student['wa_notify'] === 0): ?>
                                    <span style="font-size: 10px; color: #dc2626; font-weight: 700;">(OFF)</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span style="font-size: 11px; color: #94a3b8; font-style: italic;">Belum diisi</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <?php if (!empty($student['rfid_uid'])): ?>
                                <span style="font-size: 11px; color: #4338ca; font-weight: 600;"><i class="fa-solid fa-id-card"></i> <?= e($student['rfid_uid']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($student['fingerprint_id'])): ?>
                                <span style="font-size: 11px; color: #0284c7; font-weight: 600;"><i class="fa-solid fa-fingerprint"></i> ID #<?= e($student['fingerprint_id']) ?></span>
                            <?php endif; ?>
                            <?php if (empty($student['rfid_uid']) && empty($student['fingerprint_id'])): ?>
                                <span style="font-size: 10.5px; color: #cbd5e1;">-</span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <?php if (in_array($currentRole, ['super_admin', 'kepala_sekolah'])): ?>
                    <td>
                        <div style="display: flex; gap: 4px;">
                            <a href="edit.php?id=<?= $student['id'] ?>" class="btn btn-success" style="padding: 4px 8px; font-size:12px;">Edit</a>
                            <form action="delete.php" method="POST" style="display:inline" onsubmit="return confirm('Hapus siswa ini?')">
                                <input type="hidden" name="id" value="<?= $student['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size:12px;">Hapus</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div style="display:flex; gap:8px; margin-top:20px; flex-wrap:wrap;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?<?= buildQuery(['page' => $i]) ?>" class="btn <?= $page == $i ? 'btn-primary' : 'btn-success' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require '../../includes/footer.php'; ?>
