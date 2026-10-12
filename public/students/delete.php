<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_students');
$currentRole = currentRole();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('index.php');
}

// Ambil data siswa beserta informasi Unit-nya saat ini
$stmt = $pdo->prepare("
    SELECT s.id, s.name, g.unit_id 
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    WHERE s.id = ? AND s.deleted_at IS NULL LIMIT 1
");
$stmt->execute([$id]);
$student = $stmt->fetch();

if ($student) {
    $studentName = $student['name'] ?? 'Siswa';
    $unitId = (int)($student['unit_id'] ?? 0);

    // BENTENG KEAMANAN: Pastikan Kepala Sekolah tidak menghapus siswa dari unit lain
    if ($currentRole === 'kepala_sekolah') {
        $stmtKs = $pdo->prepare("
            SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? 
            UNION 
            SELECT unit_id FROM staff WHERE user_id = ? AND unit_id IS NOT NULL
        ");
        $stmtKs->execute([currentUserId(), currentUserId()]);
        $allowedUnits = $stmtKs->fetchAll(PDO::FETCH_COLUMN);

        if ($unitId > 0 && !in_array($unitId, $allowedUnits)) {
            flash('error', 'Akses ditolak. Anda tidak memiliki izin untuk menghapus siswa dari unit ini.');
            redirect('index.php');
            exit;
        }
    }

    $pdo->beginTransaction();
    try {
        // 1. Soft delete data siswa
        $stmtDel = $pdo->prepare("UPDATE students SET deleted_at = NOW() WHERE id = ?");
        $stmtDel->execute([$id]);

        // 2. Nonaktifkan status kelasnya agar tidak muncul di menu absensi atau scanner
        $stmtEnroll = $pdo->prepare("UPDATE student_enrollments SET status = 'inactive' WHERE student_id = ? AND status = 'active'");
        $stmtEnroll->execute([$id]);

        // 3. Hapus juga seluruh riwayat absensi siswa agar bersih dari laporan absensi
        $stmtDelAtt = $pdo->prepare("DELETE FROM student_attendances WHERE student_id = ?");
        $stmtDelAtt->execute([$id]);

        // 4. Hapus relasi jadwal khusus siswa jika ada
        $stmtDelSched = $pdo->prepare("DELETE FROM weekly_schedule_students WHERE student_id = ?");
        $stmtDelSched->execute([$id]);

        $pdo->commit();

        if (function_exists('recordActivityAudit')) {
            recordActivityAudit($pdo, 'master_students', 'DELETE', 'students', $id, "Menghapus Siswa '{$studentName}' (ID #{$id}) beserta riwayat absensinya", $student, null, $unitId ?: null);
        }

        flash('success', "Siswa '{$studentName}' berhasil dihapus dari sistem beserta seluruh riwayat absensinya.");
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Terjadi kesalahan saat menghapus data siswa: ' . $e->getMessage());
    }

} else {
    flash('error', 'Data siswa tidak ditemukan atau sudah dihapus.');
}

redirect('index.php');
