<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $userId = currentUserId();

    if (($_SESSION['role'] ?? '') === 'siswa') {
        $canChangePhoto = (getSystemSetting($pdo, 'student_can_change_photo', '0') === '1');
        if (!$canChangePhoto) {
            flash('error', 'Izin mengubah foto profil siswa sedang dinonaktifkan oleh Administrator.');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'student/dashboard.php'));
            exit;
        }
    }

    if ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
        flash('error', 'Ukuran foto maksimal 2 MB.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
        exit;
    }

    $allowed = [
        'image/jpeg'  => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/jpg'   => 'jpg',
        'image/png'   => 'png',
        'image/x-png' => 'png',
        'image/webp'  => 'webp'
    ];

    $imgInfo = @getimagesize($_FILES['photo']['tmp_name']);
    $mime = $imgInfo['mime'] ?? @mime_content_type($_FILES['photo']['tmp_name']);

    if (!$imgInfo || !isset($allowed[$mime])) {
        flash('error', 'Format foto harus JPG, PNG, atau WEBP.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
        exit;
    }
    
    // Tarik nama foto lama untuk dihapus
    $stmt = $pdo->prepare("SELECT photo, student_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch();
    $oldPhoto = $userRow['photo'] ?? null;
    $studentId = $userRow['student_id'] ?? null;
    
    $prefix = (($_SESSION['role'] ?? '') === 'siswa') ? 'student_' : 'admin_';
    $photoName = uniqid($prefix, true) . '.' . $allowed[$mime];
    
    if (($_SESSION['role'] ?? '') === 'siswa') {
        $uploadDir = defined('UPLOAD_STUDENT') ? UPLOAD_STUDENT : (__DIR__ . '/../uploads/students/');
    } else {
        $uploadDir = defined('UPLOAD_PATH') ? UPLOAD_PATH . '/admins/' : (__DIR__ . '/../uploads/admins/');
    }

    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
    @chmod($uploadDir, 0777);
    
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoName)) {
        @chmod($uploadDir . $photoName, 0644);
        if ($oldPhoto && file_exists($uploadDir . $oldPhoto)) {
            @unlink($uploadDir . $oldPhoto);
        }
        $stmt = $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?");
        $stmt->execute([$photoName, $userId]);

        if (!empty($studentId)) {
            $stmtStud = $pdo->prepare("UPDATE students SET photo = ? WHERE id = ?");
            $stmtStud->execute([$photoName, $studentId]);

            if (function_exists('logStudentActivity')) {
                logStudentActivity($pdo, (int)$studentId, (int)$userId, 'upload_photo', 'Siswa memperbarui foto profil');
            }
        }

        flash('success', 'Foto profil berhasil diperbarui.');
    } else {
        flash('error', 'Terjadi kesalahan sistem saat menyimpan foto ke folder server.');
    }
} else {
    flash('error', 'Pilih file foto yang valid terlebih dahulu.');
}

// Redirect ke halaman sebelumnya
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
exit;