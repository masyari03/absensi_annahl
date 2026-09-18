<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

$currentRole = $_SESSION['role'] ?? '';
if (!in_array($currentRole, ['super_admin', 'kepala_sekolah'])) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('index.php');
}

// Ambil data siswa beserta informasi Unit-nya saat ini
$stmt = $pdo->prepare("
    SELECT s.id, g.unit_id 
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    WHERE s.id = ? AND s.deleted_at IS NULL LIMIT 1
");
$stmt->execute([$id]);
$student = $stmt->fetch();

if ($student) {
    
    // BENTENG KEAMANAN: Pastikan Kepala Sekolah tidak menghapus siswa dari unit lain
    if ($currentRole === 'kepala_sekolah') {
        $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
        $stmtKs->execute([currentUserId()]);
        $ksUnitId = $stmtKs->fetchColumn();

        if ($student['unit_id'] && $student['unit_id'] != $ksUnitId) {
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

        $pdo->commit();
        flash('success', 'Siswa berhasil dihapus dari sistem.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'Terjadi kesalahan saat menghapus data siswa.');
    }

} else {
    flash('error', 'Data siswa tidak ditemukan atau sudah dihapus.');
}

redirect('index.php');