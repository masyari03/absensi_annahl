<?php
/**
 * Migrasi Database: Pengaturan Waktu Pengiriman 4 Pesan WhatsApp Fonnte
 * Menambahkan kolom delay menit & preferensi waktu kirim pada wa_settings dan wa_unit_settings
 */
require_once __DIR__ . '/../config/database.php';

echo "Memulai migrasi pengaturan waktu kirim WhatsApp...\n";

try {
    // 1. Tambah kolom di wa_unit_settings jika belum ada
    $cols = $pdo->query("SHOW COLUMNS FROM wa_unit_settings")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('msg_late_delay_minutes', $cols)) {
        $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN msg_late_delay_minutes INT NULL DEFAULT NULL AFTER msg_late_enabled");
        echo "Kolom msg_late_delay_minutes ditambahkan ke wa_unit_settings.\n";
    }

    if (!in_array('msg_out_late_delay_minutes', $cols)) {
        $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN msg_out_late_delay_minutes INT NULL DEFAULT NULL AFTER msg_out_late_enabled");
        echo "Kolom msg_out_late_delay_minutes ditambahkan ke wa_unit_settings.\n";
    }

    if (!in_array('msg_late_ref', $cols)) {
        $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN msg_late_ref VARCHAR(20) NULL DEFAULT NULL AFTER msg_late_delay_minutes");
        echo "Kolom msg_late_ref ditambahkan ke wa_unit_settings.\n";
    }

    // 2. Masukkan default pengaturan waktu kirim di wa_settings jika belum ada
    $defaultSettings = [
        'msg_in_timing'              => 'realtime',      // Ketika Absen Masuk
        'msg_in_delay_minutes'       => '0',
        'msg_late_timing'            => 'after_late',     // Sesudah Jam Batas Masuk
        'msg_late_ref'               => 'student_late',  // Patokan: student_late (Batas Telat)
        'msg_late_delay_minutes'     => '30',            // 30 menit sesudah batas telat
        'msg_out_timing'             => 'realtime',      // Ketika Absen Pulang
        'msg_out_delay_minutes'      => '0',
        'msg_out_late_timing'        => 'after_out',     // Berapa menit ketika lewat jam pulang
        'msg_out_late_delay_minutes' => '45',            // 45 menit sesudah jam pulang
    ];

    $stmtSet = $pdo->prepare("INSERT INTO wa_settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    foreach ($defaultSettings as $key => $val) {
        // Cek jika key belum ada
        $check = $pdo->prepare("SELECT COUNT(*) FROM wa_settings WHERE key_name = ?");
        $check->execute([$key]);
        if ($check->fetchColumn() == 0) {
            $stmtSet->execute([$key, $val]);
            echo "Default setting '$key' = '$val' ditambahkan ke wa_settings.\n";
        }
    }

    echo "Migrasi pengaturan waktu kirim WhatsApp BERHASIL!\n";
} catch (Exception $e) {
    echo "Gagal migrasi: " . $e->getMessage() . "\n";
}
