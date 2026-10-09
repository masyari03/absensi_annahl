<?php
require_once __DIR__ . '/../config/database.php';

try {
    // 1. Buat tabel officer_attendance_permissions jika belum ada
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS officer_attendance_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            granted_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_officer (user_id),
            CONSTRAINT fk_officer_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Tabel officer_attendance_permissions berhasil dibuat/diverifikasi.\n";

    // 2. Perbaiki data absensi tanggal 2026-10-09 yang sebelumnya salah terinput tepat_waktu
    // Farzana Syafiqa (07:59:41) dan Adeeva Afsheen Nugraha (07:59:56)
    // Di SD, jam terlambat adalah 06:45:00, jadi 07:59 harusnya 'terlambat'
    $stmtFix = $pdo->prepare("
        UPDATE student_attendances 
        SET status = 'terlambat' 
        WHERE attendance_date = '2026-10-09' 
          AND time_in > '06:45:00' 
          AND status = 'tepat_waktu'
    ");
    $stmtFix->execute();
    $affected = $stmtFix->rowCount();
    echo "Data absensi ter-update menjadi 'terlambat': {$affected} baris.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
