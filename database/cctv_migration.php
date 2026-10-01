<?php
/**
 * Migration: Data CCTV, Lokasi Utama & Sub (Lantai), dan Jalur Hik-Connect Web
 */
require_once __DIR__ . '/../config/database.php';

echo "=== MEMULAI MIGRASI DATA CCTV & HIK-CONNECT ===\n";

try {
    // 1. Buat tabel cctv_locations (Utama & Sub / Lantai)
    $sqlLocations = "
    CREATE TABLE IF NOT EXISTS `cctv_locations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `parent_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = Lokasi Utama (Gedung/Area), NOT NULL = Sub Lokasi (Lantai/Zona)',
        `name` VARCHAR(100) NOT NULL,
        `code` VARCHAR(50) NULL,
        `sort_order` INT NOT NULL DEFAULT 0,
        `description` VARCHAR(255) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_parent_id` (`parent_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($sqlLocations);
    echo "[+] Tabel 'cctv_locations' siap.\n";

    // 2. Buat tabel cctv_devices (Titik Kamera CCTV)
    $sqlDevices = "
    CREATE TABLE IF NOT EXISTS `cctv_devices` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `location_id` INT UNSIGNED NOT NULL COMMENT 'Relasi ke cctv_locations (Lantai/Sub-lokasi)',
        `name` VARCHAR(150) NOT NULL,
        `channel_number` INT NULL DEFAULT NULL,
        `sort_order` INT NOT NULL DEFAULT 0,
        `ip_address` VARCHAR(50) NULL DEFAULT NULL,
        `hikconnect_url` VARCHAR(500) NULL DEFAULT NULL,
        `device_serial` VARCHAR(100) NULL DEFAULT NULL,
        `status` ENUM('active', 'inactive', 'maintenance') NOT NULL DEFAULT 'active',
        `location_detail` VARCHAR(255) NULL DEFAULT NULL,
        `notes` TEXT NULL DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_location_id` (`location_id`),
        KEY `idx_sort_order` (`sort_order`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($sqlDevices);
    echo "[+] Tabel 'cctv_devices' siap.\n";

    // 3. Buat tabel cctv_settings (Konfigurasi URL Hik-Connect dsb)
    $sqlSettings = "
    CREATE TABLE IF NOT EXISTS `cctv_settings` (
        `key_name` VARCHAR(50) NOT NULL PRIMARY KEY,
        `key_value` TEXT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($sqlSettings);
    echo "[+] Tabel 'cctv_settings' siap.\n";

    // Default Hik-Connect Web URL
    $stmtSet = $pdo->prepare("
        INSERT INTO `cctv_settings` (`key_name`, `key_value`)
        VALUES ('hikconnect_url', 'https://www.hik-connect.com')
        ON DUPLICATE KEY UPDATE `key_value` = VALUES(`key_value`)
    ");
    $stmtSet->execute();
    echo "[+] Setting default 'hikconnect_url' tersimpan.\n";

    // 4. Seed Template Lokasi Utama & Sub (Lantai) jika masih kosong
    $locCount = (int)$pdo->query("SELECT COUNT(*) FROM `cctv_locations`")->fetchColumn();
    if ($locCount === 0) {
        // Lokasi Utama 1: Gedung Utama
        $pdo->prepare("INSERT INTO `cctv_locations` (parent_id, name, code, sort_order, description) VALUES (NULL, 'Gedung Utama (SD & SMP)', 'G-UTAMA', 1, 'Gedung utama kegiatan belajar')")->execute();
        $gUtamaId = (int)$pdo->lastInsertId();

        // Sub Lantai Gedung Utama
        $pdo->prepare("INSERT INTO `cctv_locations` (parent_id, name, code, sort_order, description) VALUES (?, 'Lantai 1', 'G1-L1', 1, 'Lobby, Ruang Guru, TU, Kelas 1-2')")->execute([$gUtamaId]);
        $g1l1Id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO `cctv_locations` (parent_id, name, code, sort_order, description) VALUES (?, 'Lantai 2', 'G1-L2', 2, 'Kelas 3-4, Perpustakaan, Lab Komputer')")->execute([$gUtamaId]);
        $g1l2Id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO `cctv_locations` (parent_id, name, code, sort_order, description) VALUES (?, 'Lantai 3', 'G1-L3', 3, 'Kelas 5-6, Ruang Musik, Musholla')")->execute([$gUtamaId]);
        $g1l3Id = (int)$pdo->lastInsertId();

        // Lokasi Utama 2: Area Gerbang & Luar
        $pdo->prepare("INSERT INTO `cctv_locations` (parent_id, name, code, sort_order, description) VALUES (NULL, 'Area Gerbang & Luar', 'G-OUTDOOR', 2, 'Fasilitas luar dan pintu masuk')")->execute();
        $gOutdoorId = (int)$pdo->lastInsertId();

        // Sub Area Gerbang
        $pdo->prepare("INSERT INTO `cctv_locations` (parent_id, name, code, sort_order, description) VALUES (?, 'Gerbang Utama & Pos Satpam', 'OUT-POS', 1, 'Akses masuk/keluar utama')")->execute([$gOutdoorId]);
        $posId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO `cctv_locations` (parent_id, name, code, sort_order, description) VALUES (?, 'Lapangan & Parkiran', 'OUT-PARK', 2, 'Area olahraga dan tempat parkir')")->execute([$gOutdoorId]);
        $parkId = (int)$pdo->lastInsertId();

        // Seed Sample Kamera CCTV Terurut Rapi
        $cctvSamples = [
            // Lantai 1
            [$g1l1Id, 'CAM 01 - Lobby Masuk Utama', 1, 1, '192.168.1.101', 'active', 'Menghadap gerbang masuk lobby lantai 1'],
            [$g1l1Id, 'CAM 02 - Koridor Kelas 1 & 2', 2, 2, '192.168.1.102', 'active', 'Lorong kelas lantai 1 sayap timur'],
            [$g1l1Id, 'CAM 03 - Ruang Guru & Tata Usaha', 3, 3, '192.168.1.103', 'active', 'Akses depan ruang guru dan administrasi'],
            [$g1l1Id, 'CAM 04 - Tangga Naik Lantai 2', 4, 4, '192.168.1.104', 'active', 'Pangkal tangga utama lantai 1'],
            // Lantai 2
            [$g1l2Id, 'CAM 05 - Koridor Kelas 3 & 4', 5, 1, '192.168.1.105', 'active', 'Lorong kelas lantai 2 sayap barat'],
            [$g1l2Id, 'CAM 06 - Depan Perpustakaan', 6, 2, '192.168.1.106', 'active', 'Akses masuk perpustakaan dan lab'],
            [$g1l2Id, 'CAM 07 - Tangga Naik Lantai 3', 7, 3, '192.168.1.107', 'active', 'Bordest tangga lantai 2 menuju lantai 3'],
            // Lantai 3
            [$g1l3Id, 'CAM 08 - Koridor Kelas 5 & 6', 8, 1, '192.168.1.108', 'active', 'Lorong atas lantai 3'],
            [$g1l3Id, 'CAM 09 - Area Wudhu & Musholla', 9, 2, '192.168.1.109', 'active', 'Area depan musholla lantai 3'],
            // Area Gerbang
            [$posId, 'CAM 10 - Gerbang Masuk Mobil/Motor', 10, 1, '192.168.1.110', 'active', 'Pintu gerbang luar sekolah'],
            [$posId, 'CAM 11 - Pos Satpam & Drop Zone', 11, 2, '192.168.1.111', 'active', 'Area antar jemput siswa'],
            [$parkId, 'CAM 12 - Lapangan Utama', 12, 1, '192.168.1.112', 'active', 'Lapangan upacara dan olahraga'],
        ];

        $stmtDev = $pdo->prepare("
            INSERT INTO `cctv_devices` 
            (location_id, name, channel_number, sort_order, ip_address, status, location_detail)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($cctvSamples as $cs) {
            $stmtDev->execute($cs);
        }
        echo "[+] Contoh data CCTV terurut per lantai berhasil di-seed.\n";
    }

    // 5. Tambah Menu Baru 'data_cctv' ke app_menus
    $stmtCheckMenu = $pdo->prepare("SELECT id FROM app_menus WHERE menu_key = 'data_cctv' LIMIT 1");
    $stmtCheckMenu->execute();
    $existingMenu = $stmtCheckMenu->fetch();

    if (!$existingMenu) {
        $stmtInsertMenu = $pdo->prepare("
            INSERT INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `sort_order`, `is_active`)
            VALUES ('data_cctv', 'Data CCTV', 'PENGATURAN', 'cctv/index.php', 'fa-video', 20, 1)
        ");
        $stmtInsertMenu->execute();
        $menuId = (int)$pdo->lastInsertId();
        echo "[+] Menu 'data_cctv' (id: {$menuId}) berhasil dibuat di app_menus.\n";
    } else {
        $menuId = (int)$existingMenu['id'];
        $stmtUpdateMenu = $pdo->prepare("
            UPDATE `app_menus`
            SET `menu_name` = 'Data CCTV',
                `category` = 'PENGATURAN',
                `menu_url` = 'cctv/index.php',
                `menu_icon` = 'fa-video',
                `sort_order` = 20,
                `is_active` = 1
            WHERE `id` = ?
        ");
        $stmtUpdateMenu->execute([$menuId]);
        echo "[=] Menu 'data_cctv' (id: {$menuId}) diperbarui di app_menus.\n";
    }

    // 6. Beri hak akses ke role 'super_admin'
    $stmtRole = $pdo->query("SELECT id FROM roles WHERE role_key = 'super_admin' LIMIT 1");
    $superAdminRoleId = $stmtRole->fetchColumn();

    if ($superAdminRoleId && $menuId) {
        $stmtRma = $pdo->prepare("
            INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
            VALUES (?, ?)
        ");
        $stmtRma->execute([$superAdminRoleId, $menuId]);
        echo "[+] Akses menu diberikan ke role 'super_admin' di role_menu_access.\n";

        // Sinkronkan ke user_menu_access bagi user super_admin
        $stmtUma = $pdo->prepare("
            INSERT IGNORE INTO `user_menu_access` (`user_id`, `menu_id`)
            SELECT id, ? FROM `users` WHERE `role` = 'super_admin'
        ");
        $stmtUma->execute([$menuId]);
        echo "[+] Akses menu disinkronkan ke user super_admin di user_menu_access.\n";
    }

    echo "\n=== MIGRASI CCTV SELESAI DENGAN SUKSES! ===\n";
} catch (Exception $e) {
    echo "[-] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
