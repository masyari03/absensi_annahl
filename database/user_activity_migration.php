<?php
/**
 * Migration: User Activity, Online/Offline Tracking, and Superadmin Login Logs
 */
require_once __DIR__ . '/../config/database.php';

echo "=== MEMULAI MIGRASI AKTIVITAS USER & LOG SUPERADMIN ===\n";

try {
    // 1. Tambah kolom di tabel users jika belum ada
    $columnsToAdd = [
        'last_activity' => "ALTER TABLE `users` ADD COLUMN `last_activity` DATETIME DEFAULT NULL AFTER `deleted_at`",
        'last_login_at' => "ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME DEFAULT NULL AFTER `last_activity`",
        'last_login_ip' => "ALTER TABLE `users` ADD COLUMN `last_login_ip` VARCHAR(45) DEFAULT NULL AFTER `last_login_at`",
        'is_online'     => "ALTER TABLE `users` ADD COLUMN `is_online` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_login_ip`"
    ];

    $existingColsStmt = $pdo->query("DESCRIBE users");
    $existingCols = array_column($existingColsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    foreach ($columnsToAdd as $col => $sql) {
        if (!in_array($col, $existingCols)) {
            $pdo->exec($sql);
            echo "[+] Kolom '{$col}' berhasil ditambahkan ke tabel users.\n";
        } else {
            echo "[=] Kolom '{$col}' sudah ada di tabel users.\n";
        }
    }

    // 2. Buat tabel user_login_logs
    $createLogsTable = "
    CREATE TABLE IF NOT EXISTS `user_login_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `username` VARCHAR(100) NOT NULL,
        `role` VARCHAR(50) NOT NULL,
        `is_superadmin` TINYINT(1) NOT NULL DEFAULT 0,
        `ip_address` VARCHAR(45) NOT NULL,
        `user_agent` TEXT DEFAULT NULL,
        `device_type` VARCHAR(50) DEFAULT 'Desktop',
        `browser` VARCHAR(100) DEFAULT NULL,
        `platform` VARCHAR(100) DEFAULT NULL,
        `login_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `logout_at` DATETIME DEFAULT NULL,
        `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `status` ENUM('online', 'offline', 'logged_out', 'revoked') NOT NULL DEFAULT 'online',
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_role` (`role`),
        KEY `idx_is_superadmin` (`is_superadmin`),
        KEY `idx_login_at` (`login_at`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($createLogsTable);
    // Pastikan ENUM status sudah mencakup 'revoked'
    $pdo->exec("ALTER TABLE `user_login_logs` MODIFY COLUMN `status` ENUM('online', 'offline', 'logged_out', 'revoked') NOT NULL DEFAULT 'online'");
    echo "[+] Tabel 'user_login_logs' status ENUM('online','offline','logged_out','revoked') siap.\n";

    // 3. Tambah menu baru 'user_activity' ke app_menus
    $stmtCheckMenu = $pdo->prepare("SELECT id FROM app_menus WHERE menu_key = 'user_activity' LIMIT 1");
    $stmtCheckMenu->execute();
    $existingMenu = $stmtCheckMenu->fetch();

    if (!$existingMenu) {
        $stmtInsertMenu = $pdo->prepare("
            INSERT INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `sort_order`, `is_active`)
            VALUES ('user_activity', 'Aktivitas & Log Pengguna', 'PENGATURAN', 'kelola/activity.php', 'fa-user-clock', 19, 1)
        ");
        $stmtInsertMenu->execute();
        $menuId = (int)$pdo->lastInsertId();
        echo "[+] Menu 'user_activity' (id: {$menuId}) berhasil dibuat di app_menus.\n";
    } else {
        $menuId = (int)$existingMenu['id'];
        $stmtUpdateMenu = $pdo->prepare("
            UPDATE `app_menus`
            SET `menu_name` = 'Aktivitas & Log Pengguna',
                `category` = 'PENGATURAN',
                `menu_url` = 'kelola/activity.php',
                `menu_icon` = 'fa-user-clock',
                `sort_order` = 19,
                `is_active` = 1
            WHERE `id` = ?
        ");
        $stmtUpdateMenu->execute([$menuId]);
        echo "[=] Menu 'user_activity' (id: {$menuId}) diperbarui di app_menus.\n";
    }

    // 4. Beri akses ke role super_admin
    $stmtRole = $pdo->query("SELECT id FROM roles WHERE role_key = 'super_admin' LIMIT 1");
    $superAdminRoleId = $stmtRole->fetchColumn();

    if ($superAdminRoleId && $menuId) {
        $stmtRma = $pdo->prepare("
            INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
            VALUES (?, ?)
        ");
        $stmtRma->execute([$superAdminRoleId, $menuId]);
        echo "[+] Akses menu diberikan ke role 'super_admin' di role_menu_access.\n";

        // Berikan juga ke semua user yang role-nya super_admin di user_menu_access
        $stmtUma = $pdo->prepare("
            INSERT IGNORE INTO `user_menu_access` (`user_id`, `menu_id`)
            SELECT id, ? FROM `users` WHERE `role` = 'super_admin'
        ");
        $stmtUma->execute([$menuId]);
        echo "[+] Akses menu disinkronkan ke seluruh user super_admin di user_menu_access.\n";
    }

    echo "\n=== MIGRASI SELESAI DENGAN SUKSES! ===\n";
} catch (Exception $e) {
    echo "[-] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
