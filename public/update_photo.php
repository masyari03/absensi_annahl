<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $userId = currentUserId();
    
    // Tarik nama foto lama untuk dihapus
    $stmt = $pdo->prepare("SELECT photo FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $oldPhoto = $stmt->fetchColumn();
    
    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $photoName = uniqid('admin_', true) . '.' . $ext;
    
    $uploadDir = __DIR__ . '/../uploads/admins/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoName)) {
        if ($oldPhoto && file_exists($uploadDir . $oldPhoto)) {
            unlink($uploadDir . $oldPhoto);
        }
        $stmt = $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?");
        $stmt->execute([$photoName, $userId]);
        flash('success', 'Foto profil berhasil diperbarui.');
    } else {
        flash('error', 'Terjadi kesalahan sistem saat mengunggah foto.');
    }
} else {
    flash('error', 'Pilih file foto yang valid terlebih dahulu.');
}

// Redirect ke halaman sebelumnya
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
exit;