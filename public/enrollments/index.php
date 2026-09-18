<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// IZINKAN SUPER ADMIN DAN KEPALA SEKOLAH
$currentRole = $_SESSION['role'] ?? '';
if (!in_array($currentRole, ['super_admin', 'kepala_sekolah'])) {
    redirect('../dashboard.php');
}

$pageTitle = 'Kenaikan Kelas';
$userId = currentUserId();

// AMBIL UNIT KEPALA SEKOLAH
$ksUnitId = 0;
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();
}

$years = $pdo->query("SELECT * FROM academic_years ORDER BY start_date DESC")->fetchAll();

// FILTER SUBKELAS (TARGET) BERDASARKAN UNIT KEPSEK
$cgWhere = "";
$cgParams = [];
if ($currentRole === 'kepala_sekolah') {
    $cgWhere = "WHERE g.unit_id = ?";
    $cgParams[] = $ksUnitId;
}

$stmtCg = $pdo->prepare("
    SELECT cg.id, cg.name, g.grade, g.sort_order
    FROM class_groups cg
    INNER JOIN grades g ON g.id = cg.grade_id
    {$cgWhere}
    ORDER BY g.sort_order, cg.name
");
$stmtCg->execute($cgParams);
$classGroups = $stmtCg->fetchAll();

$sourceYearId = (int)($_GET['source_year_id'] ?? 0);
$targetYearId = (int)($_GET['target_year_id'] ?? 0);

if ($sourceYearId === 0) {
    foreach ($years as $year) {
        if ($year['status'] === 'active') {
            $sourceYearId = $year['id'];
            break;
        }
    }
}

$students = [];

if ($sourceYearId > 0 && $targetYearId > 0) {
    
    // FILTER SISWA BERDASARKAN UNIT KEPSEK
    $studentWhere = "se.academic_year_id = ? AND se.status = 'active' AND s.deleted_at IS NULL";
    $studentParams = [$sourceYearId];
    
    if ($currentRole === 'kepala_sekolah') {
        $studentWhere .= " AND g.unit_id = ?";
        $studentParams[] = $ksUnitId;
    }

    $stmt = $pdo->prepare("
        SELECT 
            s.id, s.name, s.nis, se.id AS enrollment_id, 
            cg.name AS current_class, g.grade
        FROM students s
        INNER JOIN student_enrollments se ON se.student_id = s.id
        INNER JOIN class_groups cg ON cg.id = se.class_group_id
        INNER JOIN grades g ON g.id = cg.grade_id
        WHERE {$studentWhere}
        ORDER BY g.sort_order, cg.name, s.name
    ");
    $stmt->execute($studentParams);
    $students = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| PROSES KENAIKAN KELAS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sourceYear = (int)($_POST['source_year_id'] ?? 0);
    $targetYear = (int)($_POST['target_year_id'] ?? 0);
    $studentIds = $_POST['student_ids'] ?? [];
    $classMap = $_POST['class_group_id'] ?? [];
    $actionMap = $_POST['action'] ?? [];

    try {
        $pdo->beginTransaction();

        foreach ($studentIds as $studentId) {
            $studentId = (int)$studentId;
            $action = $actionMap[$studentId] ?? 'promote';

            // Cek jika siswa sudah punya record di tahun tujuan
            $stmt = $pdo->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND academic_year_id = ? LIMIT 1");
            $stmt->execute([$studentId, $targetYear]);
            if ($stmt->fetch()) {
                continue;
            }

            if ($action === 'promote') {
                $classId = (int)($classMap[$studentId] ?? 0);
                if ($classId <= 0) continue;
                $status = 'active';

            } elseif ($action === 'graduate') {
                $stmt = $pdo->prepare("
                    UPDATE student_enrollments SET status = 'graduated' 
                    WHERE id = (SELECT source.id FROM (SELECT id FROM student_enrollments WHERE student_id = ? AND academic_year_id = ? LIMIT 1) AS source)
                ");
                $stmt->execute([$studentId, $sourceYear]);
                continue;

            } elseif ($action === 'transfer') {
                $stmt = $pdo->prepare("
                    UPDATE student_enrollments SET status = 'transferred' 
                    WHERE id = (SELECT source.id FROM (SELECT id FROM student_enrollments WHERE student_id = ? AND academic_year_id = ? LIMIT 1) AS source)
                ");
                $stmt->execute([$studentId, $sourceYear]);
                continue;

            } else {
                // Tinggal kelas
                $classId = (int)($classMap[$studentId] ?? 0);
                if ($classId <= 0) continue;
                $status = 'repeated';
            }

            $stmt = $pdo->prepare("INSERT INTO student_enrollments (student_id, academic_year_id, class_group_id, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$studentId, $targetYear, $classId, $status]);
        }

        $pdo->commit();
        flash('success', 'Proses kenaikan kelas berhasil.');
        redirect('index.php?source_year_id=' . $sourceYear . '&target_year_id=' . $targetYear);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die($e->getMessage());
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Kenaikan Kelas</h3>
            <small>Atur siswa satu per satu sebelum diproses <?= $currentRole === 'kepala_sekolah' ? '(Khusus siswa Unit Anda)' : '' ?>.</small>
        </div>
    </div>

    <form method="GET">
        <div class="filter-grid">
            <div class="form-group">
                <label>Tahun Asal</label>
                <select name="source_year_id" required>
                    <option value="">Pilih</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year['id'] ?>" <?= $sourceYearId == $year['id'] ? 'selected' : '' ?>><?= e($year['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Tahun Tujuan</label>
                <select name="target_year_id" required>
                    <option value="">Pilih</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year['id'] ?>" <?= $targetYearId == $year['id'] ? 'selected' : '' ?>><?= e($year['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <button class="btn btn-primary" type="submit" style="margin-top: 22px;">Tampilkan</button>
            </div>
        </div>
    </form>
</div>

<?php if ($students): ?>
    <form method="POST">
        <input type="hidden" name="source_year_id" value="<?= $sourceYearId ?>">
        <input type="hidden" name="target_year_id" value="<?= $targetYearId ?>">

        <div class="card">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Nama</th>
                            <th>NIS</th>
                            <th>Asal</th>
                            <th>Aksi</th>
                            <th>Kelas Tujuan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td><input type="checkbox" name="student_ids[]" value="<?= $student['id'] ?>"></td>
                            <td><?= e($student['name']) ?></td>
                            <td><?= e($student['nis']) ?></td>
                            <td><?= e($student['grade']) ?> - <?= e($student['current_class']) ?></td>
                            <td>
                                <select name="action[<?= $student['id'] ?>]">
                                    <option value="promote">Naik Kelas</option>
                                    <option value="repeat">Tinggal Kelas</option>
                                    <option value="graduate">Lulus</option>
                                    <option value="transfer">Pindah</option>
                                </select>
                            </td>
                            <td>
                                <select name="class_group_id[<?= $student['id'] ?>]">
                                    <option value="">Pilih Kelas</option>
                                    <?php foreach ($classGroups as $class): ?>
                                        <option value="<?= $class['id'] ?>"><?= e($class['grade']) ?> - <?= e($class['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:20px">
                <button class="btn btn-primary" type="submit">Proses yang Dipilih</button>
            </div>
        </div>
    </form>

<?php elseif ($sourceYearId && $targetYearId): ?>
    <div class="card">
        <p style="text-align: center; color: #64748b;">Tidak ada siswa aktif pada tahun asal di unit Anda yang perlu diproses.</p>
    </div>
<?php endif; ?>

<?php require '../../includes/footer.php'; ?>