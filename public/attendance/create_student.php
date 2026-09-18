<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

$currentRole = $_SESSION['role'] ?? '';
if (!in_array($currentRole, ['super_admin', 'kepala_sekolah', 'admin'])) {
    redirect('../dashboard.php');
}

$pageTitle = 'Input Manual Absensi Siswa';
$userId = currentUserId();

// Ambil daftar siswa sesuai hak akses
$access = getStudentAccessCondition('g', 'cg');
$sqlStudents = "
    SELECT s.id, s.name, cg.name AS class_name, g.unit_id, se.id AS enrollment_id
    FROM students s
    INNER JOIN student_enrollments se ON se.student_id = s.id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    WHERE s.deleted_at IS NULL AND se.status = 'active' AND {$access['condition']}
    ORDER BY cg.name, s.name
";
$stmtStudents = $pdo->prepare($sqlStudents);
$stmtStudents->execute($access['params']);
$students = $stmtStudents->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = (int)$_POST['student_id'];
    $date = $_POST['attendance_date'];
    $status = $_POST['status'];
    $keterangan = trim($_POST['keterangan']);
    
    // Cari data siswa yang dipilih
    $selectedStudent = null;
    foreach ($students as $s) {
        if ($s['id'] == $studentId) { $selectedStudent = $s; break; }
    }

    if ($selectedStudent) {
        $unitId = $selectedStudent['unit_id'];
        $enrollmentId = $selectedStudent['enrollment_id'];
        $dayCode = date('N', strtotime($date));

        // 1. Cek atau Buat Activity untuk hari itu
        $stmtAct = $pdo->prepare("SELECT id FROM activities WHERE activity_date = ? AND unit_id = ? LIMIT 1");
        $stmtAct->execute([$date, $unitId]);
        $activityId = $stmtAct->fetchColumn();

        if (!$activityId) {
            $stmtWk = $pdo->prepare("SELECT * FROM weekly_schedules WHERE day_code = ? AND unit_id = ? AND is_active = 'active' LIMIT 1");
            $stmtWk->execute([$dayCode, $unitId]);
            $weekly = $stmtWk->fetch();
            
            if ($weekly) {
                $ayId = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1")->fetchColumn() ?: 1;
                $insertAct = $pdo->prepare("INSERT INTO activities (academic_year_id, unit_id, name, activity_date, student_in, student_late, student_out, staff_in, staff_late, staff_out, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $insertAct->execute([$ayId, $unitId, "KBM Reguler " . $weekly['day_name'], $date, $weekly['student_in'], $weekly['student_late'], $weekly['student_out'], $weekly['staff_in'], $weekly['staff_late'], $weekly['staff_out']]);
                $activityId = $pdo->lastInsertId();
            } else {
                flash('error', 'Gagal: Tidak ada jadwal kegiatan / hari libur pada tanggal tersebut.');
                redirect('create_student.php');
                exit;
            }
        }

        // 2. Cek apakah sudah absen
        $check = $pdo->prepare("SELECT id FROM student_attendances WHERE student_id = ? AND activity_id = ?");
        $check->execute([$studentId, $activityId]);
        if ($check->fetch()) {
            flash('error', 'Siswa sudah memiliki data absensi pada tanggal tersebut (Silakan gunakan fitur Edit).');
        } else {
            // 3. Insert Data Manual
            $insert = $pdo->prepare("INSERT INTO student_attendances (student_id, enrollment_id, activity_id, attendance_date, time_in, time_out, status, keterangan) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?)");
            $insert->execute([$studentId, $enrollmentId, $activityId, $date, $status, $keterangan]);
            flash('success', 'Data kehadiran manual berhasil ditambahkan.');
            redirect('students.php');
            exit;
        }
    } else {
        flash('error', 'Siswa tidak valid atau di luar hak akses Anda.');
    }
}

require '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-header">
        <h3>+ Input Absensi Manual (Siswa)</h3>
    </div>
    
    <form method="POST" style="padding: 20px;">
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:8px; font-weight:600;">Tanggal</label>
            <input type="date" name="attendance_date" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
        </div>
        
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:8px; font-weight:600;">Pilih Siswa</label>
            <select name="student_id" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                <option value="">-- Pilih Siswa --</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= e($s['name']) ?> (Kelas <?= e($s['class_name']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:8px; font-weight:600;">Status Kehadiran</label>
            <select name="status" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                <option value="izin">ℹ Izin</option>
                <option value="sakit">♥ Sakit</option>
                <option value="terlambat">⚠ Terlambat (Manual)</option>
                <option value="tepat_waktu">✓ Tepat Waktu (Manual)</option>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 25px;">
            <label style="display:block; margin-bottom:8px; font-weight:600;">Keterangan</label>
            <textarea name="keterangan" rows="3" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family:inherit;" placeholder="Tulis alasan izin/sakit..."></textarea>
        </div>
        
        <div style="display:flex; justify-content: flex-end; gap:10px;">
            <a href="students.php" class="btn" style="background: #ef4444; color: white;">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan Kehadiran</button>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>