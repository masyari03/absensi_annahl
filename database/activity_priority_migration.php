<?php
require_once __DIR__ . '/../config/database.php';

try {
    // 1. Kolom is_auto_generated pada activities
    $checkCol = $pdo->query("SHOW COLUMNS FROM activities LIKE 'is_auto_generated'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE activities ADD COLUMN is_auto_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER is_holiday");
    }
    $pdo->exec("
        UPDATE activities 
        SET is_auto_generated = 1 
        WHERE name = 'KBM Reguler' OR name LIKE 'Jadwal Reguler%'
    ");

    // 2. Tambahkan 'alpha' ke enum status student_attendances dan staff_attendances jika belum ada
    $pdo->exec("ALTER TABLE student_attendances MODIFY COLUMN status ENUM('tepat_waktu','terlambat','izin','sakit','alpha') NOT NULL");
    $pdo->exec("ALTER TABLE staff_attendances MODIFY COLUMN status ENUM('tepat_waktu','terlambat','izin','sakit','alpha') NOT NULL");

    echo "Migration activity_priority_migration completed successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
