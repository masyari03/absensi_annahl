<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/security.php'; // <-- INI YANG KURANG SEBELUMNYA
require_once '../includes/functions.php';

// Pastikan user sudah login menggunakan fungsi bawaan Anda
$userId = currentUserId();
if ($userId <= 0) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newUsername = trim($_POST['username'] ?? '');
    $newPassword = $_POST['password'] ?? '';
    
    // Jika dua-duanya kosong, berarti tidak ada yang diubah
    if (empty($newUsername) && empty($newPassword)) {
        flash('info', 'Tidak ada data akun yang diubah.');
        redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
    }

    $updateFields = [];
    $params = [];

    // 1. Logika Update Username
    if (!empty($newUsername)) {
        // Pengecekan agar username tidak bentrok dengan user lain
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$newUsername, $userId]);
        
        if ($stmt->fetch()) {
            flash('error', 'Username sudah digunakan oleh pengguna lain. Silakan pilih yang berbeda.');
            redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
            exit;
        }
        
        $updateFields[] = "username = ?";
        $params[] = $newUsername;
    }

    // 2. Logika Update Password menggunakan fungsi bawaan sistem Anda
    if (!empty($newPassword)) {
        // Karena security.php sudah di-include di atas, fungsi ini sekarang akan berjalan normal
        $hashedPassword = hashPassword($newPassword);
        
        $updateFields[] = "password = ?";
        $params[] = $hashedPassword;
    }

    // 3. Eksekusi Update ke Database
    if (!empty($updateFields)) {
        $params[] = $userId; // Parameter untuk WHERE id = ?
        
        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute($params)) {
            // Jika username berhasil diubah, perbarui juga data di sesi (session) yang sedang aktif
            if (!empty($newUsername)) {
                $_SESSION['username'] = $newUsername;
            }
            flash('success', 'Informasi akun (Username/Password) berhasil diperbarui.');
        } else {
            flash('error', 'Terjadi kesalahan sistem saat memperbarui akun.');
        }
    }
    
    // Kembalikan user ke halaman sebelumnya (tempat dia menekan tombol simpan)
    redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');

} else {
    // Jika diakses tidak menggunakan metode POST, tendang kembali
    redirect('dashboard.php');
}
?>