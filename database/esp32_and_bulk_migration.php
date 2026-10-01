<?php
require_once __DIR__ . '/../config/database.php';

echo "Running Migration...\n";

// Helper column exists check
function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}


try {
    // 1. ALTER students table
    if (!columnExists($pdo, 'students', 'rfid_uid')) {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `rfid_uid` VARCHAR(50) NULL UNIQUE AFTER `parent_phone`");
        echo "[+] Added students.rfid_uid\n";
    }
    if (!columnExists($pdo, 'students', 'fingerprint_id')) {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `fingerprint_id` INT UNSIGNED NULL AFTER `rfid_uid`");
        echo "[+] Added students.fingerprint_id\n";
    }

    // 2. ALTER staff table
    if (!columnExists($pdo, 'staff', 'phone')) {
        $pdo->exec("ALTER TABLE `staff` ADD COLUMN `phone` VARCHAR(30) NULL AFTER `name`");
        echo "[+] Added staff.phone\n";
    }
    if (!columnExists($pdo, 'staff', 'wa_notify')) {
        $pdo->exec("ALTER TABLE `staff` ADD COLUMN `wa_notify` TINYINT(1) NOT NULL DEFAULT 1 AFTER `phone`");
        echo "[+] Added staff.wa_notify\n";
    }
    if (!columnExists($pdo, 'staff', 'rfid_uid')) {
        $pdo->exec("ALTER TABLE `staff` ADD COLUMN `rfid_uid` VARCHAR(50) NULL UNIQUE AFTER `wa_notify`");
        echo "[+] Added staff.rfid_uid\n";
    }
    if (!columnExists($pdo, 'staff', 'fingerprint_id')) {
        $pdo->exec("ALTER TABLE `staff` ADD COLUMN `fingerprint_id` INT UNSIGNED NULL AFTER `rfid_uid`");
        echo "[+] Added staff.fingerprint_id\n";
    }

    // 3. ALTER wa_message_logs
    $pdo->exec("ALTER TABLE `wa_message_logs` MODIFY `message_type` VARCHAR(50) NOT NULL");
    echo "[*] Updated wa_message_logs.message_type to VARCHAR(50)\n";

    if (!columnExists($pdo, 'wa_message_logs', 'staff_id')) {
        $pdo->exec("ALTER TABLE `wa_message_logs` ADD COLUMN `staff_id` BIGINT UNSIGNED NULL AFTER `student_id`");
        echo "[+] Added wa_message_logs.staff_id\n";
    }

    // 4. ALTER wa_unit_settings
    if (!columnExists($pdo, 'wa_unit_settings', 'staff_recap_enabled')) {
        $pdo->exec("ALTER TABLE `wa_unit_settings` ADD COLUMN `staff_recap_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `msg_out_late_enabled`");
        echo "[+] Added wa_unit_settings.staff_recap_enabled\n";
    }

    // 5. Seed wa_settings default template for staff monthly recap
    $defaultRecapTemplate = "Halo Bapak/Ibu *{nama_staff}*,\nBerikut adalah rekapan presensi & lembur Anda untuk periode *{periode}* di *{unit}*:\n\n📋 *Ringkasan Kehadiran:*\n• Total Hari Hadir: *{total_hadir} hari* ({persentase_kehadiran})\n• Hadir Tepat Waktu: *{tepat_waktu}*\n• Terlambat: *{terlambat}*\n• Izin / Sakit: *{izin_sakit}*\n• Alpa: *{alpa}*\n\n⏱️ *Rekapitulasi Lembur:*\n• Total Durasi Lembur: *{total_lembur}*\n• Frekuensi Lembur: *{frekuensi_lembur} kali*\n\nTerima kasih atas dedikasi dan kerja keras Anda.";

    $stmtInsert = $pdo->prepare("INSERT IGNORE INTO `wa_settings` (`key_name`, `key_value`) VALUES (?, ?)");
    $stmtInsert->execute(['staff_recap_enabled', '1']);
    $stmtInsert->execute(['staff_recap_template', $defaultRecapTemplate]);
    echo "[+] Seeded wa_settings for staff monthly recap\n";

    // 6. CREATE TABLE iot_devices
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `iot_devices` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `device_id` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `unit_id` INT UNSIGNED NULL,
            `device_token` VARCHAR(64) NOT NULL UNIQUE,
            `device_type` VARCHAR(30) NOT NULL DEFAULT 'rfid_fingerprint',
            `ip_address` VARCHAR(45) NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_seen` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`unit_id`),
            INDEX (`device_token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "[+] Created iot_devices table\n";

    // Seed default demo devices if empty
    $countDevices = (int)$pdo->query("SELECT COUNT(*) FROM `iot_devices`")->fetchColumn();
    if ($countDevices === 0) {
        $pdo->exec("
            INSERT INTO `iot_devices` (`device_id`, `name`, `unit_id`, `device_token`, `device_type`) VALUES
            ('ESP32-UNIT1-DEV1', 'Gerbang Utama Unit SD (Mesin 1)', 1, 'TOK-DEV-SD-01-A8B9C', 'rfid_fingerprint'),
            ('ESP32-UNIT1-DEV2', 'Lobi Depan Unit SD (Mesin 2)', 1, 'TOK-DEV-SD-02-D7E6F', 'rfid_fingerprint'),
            ('ESP32-UNIT2-DEV1', 'Gerbang Utama Unit SMP (Mesin 1)', 2, 'TOK-DEV-SMP-01-G5H4I', 'rfid_fingerprint')
        ");
        echo "[+] Seeded initial sample iot_devices\n";
    }

    // 7. CREATE TABLE iot_attendance_logs
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `iot_attendance_logs` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `device_id` VARCHAR(50) NOT NULL,
            `unit_id` INT UNSIGNED NULL,
            `scan_type` ENUM('rfid', 'fingerprint') NOT NULL,
            `scan_code` VARCHAR(100) NOT NULL,
            `user_type` ENUM('student', 'staff', 'unknown') NOT NULL,
            `user_id` BIGINT UNSIGNED NULL,
            `status` ENUM('success', 'cooldown', 'unregistered', 'error') NOT NULL,
            `response_message` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`device_id`),
            INDEX (`scan_code`),
            INDEX (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "[+] Created iot_attendance_logs table\n";

    // 8. Register app_menu for IoT Devices Management under PENGATURAN
    $checkMenu = $pdo->query("SELECT id FROM `app_menus` WHERE `menu_key` = 'iot_devices' LIMIT 1")->fetch();
    if (!$checkMenu) {
        $pdo->exec("
            INSERT INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `sort_order`, `is_active`)
            VALUES ('iot_devices', 'Perangkat ESP32 / IoT', 'PENGATURAN', 'devices/index.php', 'fa-solid fa-microchip', 95, 1)
        ");
        echo "[+] Added iot_devices to app_menus\n";
    }

    echo "\n=== ALL MIGRATIONS COMPLETED SUCCESSFULLY! ===\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
