<?php
/**
 * Migration: Integrasi Notifikasi WhatsApp (Fonnte API), Kontrol Unit & Siswa, serta 4 Jenis Pesan Otomatis
 */
require_once __DIR__ . '/../config/database.php';

echo "=== MEMULAI MIGRASI INTEGRASI WHATSAPP (FONNTE API) ===\n";

try {
    // 1. Tambah kolom parent_phone dan wa_notify pada tabel students jika belum ada
    $stmtColCheck = $pdo->query("SHOW COLUMNS FROM `students` LIKE 'parent_phone'");
    if (!$stmtColCheck->fetch()) {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `parent_phone` VARCHAR(30) NULL DEFAULT NULL AFTER `nik`");
        echo "[+] Kolom 'parent_phone' berhasil ditambahkan ke tabel 'students'.\n";
    } else {
        echo "[i] Kolom 'parent_phone' sudah ada di tabel 'students'.\n";
    }

    $stmtColCheck2 = $pdo->query("SHOW COLUMNS FROM `students` LIKE 'wa_notify'");
    if (!$stmtColCheck2->fetch()) {
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `wa_notify` TINYINT(1) NOT NULL DEFAULT 1 AFTER `parent_phone`");
        echo "[+] Kolom 'wa_notify' berhasil ditambahkan ke tabel 'students'.\n";
    } else {
        echo "[i] Kolom 'wa_notify' sudah ada di tabel 'students'.\n";
    }

    // 2. Buat tabel wa_settings (Pengaturan Token, Global Switch, 4 Status Pesan & Template)
    $sqlSettings = "
    CREATE TABLE IF NOT EXISTS `wa_settings` (
        `key_name` VARCHAR(50) NOT NULL PRIMARY KEY,
        `key_value` TEXT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($sqlSettings);
    echo "[+] Tabel 'wa_settings' siap.\n";

    // Default settings
    $defaultSettings = [
        'wa_enabled'            => '0', // Default nonaktif sampai Super Admin memasukkan Token Fonnte
        'fonnte_token'          => '',
        'wa_country_code'       => '62',
        
        // 4 Status Pesan Otomatis (1 = Aktif, 0 = Nonaktif)
        'msg_in_enabled'        => '1', // Pesan 1: Absen Masuk
        'msg_late_enabled'      => '1', // Pesan 2: Terlambat / Belum Hadir (1 jam setelah batas telat)
        'msg_out_enabled'       => '1', // Pesan 3: Absen Pulang
        'msg_out_late_enabled'  => '1', // Pesan 4: Konfirmasi Pulang (1 jam setelah kepulangan belum absen)

        // Template Pesan 1: Absen Masuk
        'template_in'           => "Assalamu'alaikum Wr. Wb. / Yth. Bapak/Ibu Orang Tua dari:\n\n"
                                 . "👤 Nama: *{nama_siswa}*\n"
                                 . "🏫 Kelas / Unit: *{kelas}* ({unit})\n"
                                 . "📅 Tanggal: *{tanggal}*\n"
                                 . "⏰ Jam Masuk: *{jam_absen} WIB*\n"
                                 . "📌 Status: *{status_kehadiran}*\n\n"
                                 . "Alhamdulillah, ananda telah berada di sekolah dan siap mengikuti kegiatan belajar. Terima kasih.",

        // Template Pesan 2: Terlambat / Belum Masuk 1 Jam Setelah Batas Telat
        'template_late'         => "Pemberitahuan Ketidakhadiran:\n"
                                 . "Yth. Bapak/Ibu Orang Tua dari:\n\n"
                                 . "👤 Nama: *{nama_siswa}*\n"
                                 . "🏫 Kelas / Unit: *{kelas}* ({unit})\n"
                                 . "📅 Tanggal: *{tanggal}*\n"
                                 . "⏰ Waktu Pantau: *{jam_sekarang} WIB* (Batas Masuk: {jam_batas})\n\n"
                                 . "Hingga saat ini, ananda *BELUM TERCATAT* melakukan absensi masuk di sekolah.\n"
                                 . "Mohon segera konfirmasi kehadiran atau alasan ketidakhadiran (Sakit/Izin) kepada pihak sekolah. Terima kasih.",

        // Template Pesan 3: Absen Pulang
        'template_out'          => "Assalamu'alaikum Wr. Wb. / Yth. Bapak/Ibu Orang Tua dari:\n\n"
                                 . "👤 Nama: *{nama_siswa}*\n"
                                 . "🏫 Kelas / Unit: *{kelas}* ({unit})\n"
                                 . "📅 Tanggal: *{tanggal}*\n"
                                 . "⏰ Jam Kepulangan: *{jam_absen} WIB*\n\n"
                                 . "Ananda telah selesai mengikuti kegiatan belajar dan telah melakukan absensi pulang di sekolah. Semoga tiba di rumah dengan selamat. Terima kasih.",

        // Template Pesan 4: Peringatan 1 Jam Setelah Kepulangan Belum Absen
        'template_out_late'     => "Peringatan & Konfirmasi Kepulangan:\n"
                                 . "Yth. Bapak/Ibu Orang Tua dari:\n\n"
                                 . "👤 Nama: *{nama_siswa}*\n"
                                 . "🏫 Kelas / Unit: *{kelas}* ({unit})\n"
                                 . "📅 Tanggal: *{tanggal}*\n"
                                 . "⏰ Jam Selesai Sekolah: *{jam_pulang} WIB*\n\n"
                                 . "Jam kepulangan sekolah telah selesai lebih dari 1 jam, namun ananda belum tercatat melakukan absensi scan pulang di sekolah.\n"
                                 . "Mohon konfirmasi kepada pihak sekolah apakah ananda saat ini sudah tiba di rumah dengan selamat. Terima kasih."
    ];

    foreach ($defaultSettings as $k => $v) {
        $stmtInsert = $pdo->prepare("INSERT IGNORE INTO `wa_settings` (`key_name`, `key_value`) VALUES (?, ?)");
        $stmtInsert->execute([$k, $v]);
    }
    echo "[+] Konfigurasi default 'wa_settings' berhasil disiapkan.\n";

    // 3. Buat tabel wa_unit_settings (Pembatasan Fitur WhatsApp Per Unit Sekolah)
    $sqlUnitSettings = "
    CREATE TABLE IF NOT EXISTS `wa_unit_settings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `unit_id` INT(10) UNSIGNED NOT NULL,
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Boleh kirim WA, 0 = Dibatasi/Dilarang',
        `msg_in_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `msg_late_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `msg_out_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `msg_out_late_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_unit_id` (`unit_id`),
        CONSTRAINT `fk_wa_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($sqlUnitSettings);
    echo "[+] Tabel 'wa_unit_settings' siap.\n";

    // Auto-seed semua unit ke wa_unit_settings
    $pdo->exec("
        INSERT IGNORE INTO `wa_unit_settings` (`unit_id`, `is_enabled`, `msg_in_enabled`, `msg_late_enabled`, `msg_out_enabled`, `msg_out_late_enabled`)
        SELECT id, 1, 1, 1, 1, 1 FROM `units`
    ");
    echo "[+] Seluruh unit sekolah berhasil didaftarkan ke 'wa_unit_settings'.\n";

    // 4. Buat tabel wa_message_logs (Riwayat Pengiriman & Pencegahan Duplikasi Pesan)
    $sqlLogs = "
    CREATE TABLE IF NOT EXISTS `wa_message_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `student_id` BIGINT(20) UNSIGNED NULL,
        `unit_id` INT(10) UNSIGNED NULL,
        `phone_number` VARCHAR(30) NOT NULL,
        `message_type` ENUM('in', 'late', 'out', 'out_late', 'test') NOT NULL,
        `message_text` TEXT NOT NULL,
        `status` ENUM('success', 'failed', 'skipped') NOT NULL DEFAULT 'success',
        `response_api` TEXT NULL,
        `sent_date` DATE NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_student_date_type` (`student_id`, `sent_date`, `message_type`),
        KEY `idx_sent_date` (`sent_date`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $pdo->exec($sqlLogs);
    echo "[+] Tabel 'wa_message_logs' siap.\n";

    // 5. Tambahkan Menu ke `app_menus`
    $sqlMenu = "
    INSERT IGNORE INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `sort_order`, `is_active`)
    VALUES ('whatsapp_notif', 'Notifikasi WhatsApp (Fonnte)', 'PENGATURAN', 'whatsapp/index.php', 'fa-brands fa-whatsapp', 21, 1)
    ON DUPLICATE KEY UPDATE `menu_name` = 'Notifikasi WhatsApp (Fonnte)', `menu_url` = 'whatsapp/index.php', `menu_icon` = 'fa-brands fa-whatsapp', `sort_order` = 21;
    ";
    $pdo->exec($sqlMenu);
    echo "[+] Menu 'whatsapp_notif' berhasil ditambahkan di tabel 'app_menus'.\n";

    $stmtM = $pdo->query("SELECT id FROM `app_menus` WHERE `menu_key` = 'whatsapp_notif' LIMIT 1");
    $menuId = (int)$stmtM->fetchColumn();

    if ($menuId > 0) {
        // Berikan izin default ke role super_admin dan kepala_sekolah
        $pdo->exec("
            INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
            SELECT id, {$menuId} FROM `roles` WHERE `role_key` IN ('super_admin', 'kepala_sekolah')
        ");
        echo "[+] Hak akses role default 'super_admin' & 'kepala_sekolah' berhasil diperbarui.\n";

        // Berikan juga ke semua user super_admin dan kepala_sekolah yang ada di user_menu_access
        $pdo->exec("
            INSERT IGNORE INTO `user_menu_access` (`user_id`, `menu_id`)
            SELECT id, {$menuId} FROM `users` WHERE `role` IN ('super_admin', 'kepala_sekolah') AND `deleted_at` IS NULL
        ");
        echo "[+] Akses menu disinkronkan ke seluruh user super_admin & kepala_sekolah di user_menu_access.\n";
    }

    echo "\n=== MIGRASI INTEGRASI WHATSAPP BERHASIL SELESAI ===\n";

} catch (Throwable $e) {
    echo "[-] Terjadi error saat migrasi: " . $e->getMessage() . "\n";
    exit(1);
}
