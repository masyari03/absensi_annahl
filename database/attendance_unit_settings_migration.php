<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attendance_unit_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            unit_id INT UNSIGNED NOT NULL UNIQUE,
            early_departure_mode ENUM('offset_minutes', 'fixed_time') NOT NULL DEFAULT 'offset_minutes',
            early_departure_minutes INT NULL DEFAULT 0,
            early_departure_time TIME NULL DEFAULT NULL,
            auto_attendance_mode ENUM('offset_minutes', 'fixed_time') NOT NULL DEFAULT 'offset_minutes',
            auto_attendance_minutes INT NULL DEFAULT 180,
            auto_attendance_time TIME NULL DEFAULT NULL,
            checkout_window_hours DECIMAL(4,1) NOT NULL DEFAULT 4.0,
            checkout_max_delay_minutes INT NOT NULL DEFAULT 60,
            updated_by BIGINT UNSIGNED NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_att_unit_settings_unit FOREIGN KEY (unit_id) REFERENCES units (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("ALTER TABLE attendance_unit_settings MODIFY COLUMN early_departure_minutes INT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE attendance_unit_settings MODIFY COLUMN auto_attendance_minutes INT NULL DEFAULT 180");

    // Seed default rows for existing units
    $pdo->exec("
        INSERT IGNORE INTO attendance_unit_settings 
        (unit_id, early_departure_mode, early_departure_minutes, auto_attendance_mode, auto_attendance_minutes, checkout_window_hours, checkout_max_delay_minutes)
        SELECT id, 'offset_minutes', 0, 'offset_minutes', 180, 4.0, 60 FROM units
    ");

    echo "Migration attendance_unit_settings_migration completed successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
