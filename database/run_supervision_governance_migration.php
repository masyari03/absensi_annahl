<?php
require_once __DIR__ . '/../config/database.php';

echo "=== MEMULAI MIGRASI TATA KELOLA SUPERVISI ===\n";

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS `supervision_schedule_creator_permissions` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` bigint NOT NULL,
        `unit_id` int NULL,
        `granted_by` bigint NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_creator` (`user_id`),
        KEY `idx_unit` (`unit_id`),
        KEY `idx_granted_by` (`granted_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);
    echo "[1] Tabel supervision_schedule_creator_permissions berhasil dibuat/siap.\n";

    $sqlKepsek = "
    CREATE TABLE IF NOT EXISTS `supervision_kepsek_peer_permissions` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` bigint NOT NULL,
        `granted_by` bigint NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_kepsek_peer_user` (`user_id`),
        KEY `idx_granted_by` (`granted_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlKepsek);
    echo "[2] Tabel supervision_kepsek_peer_permissions berhasil dibuat/siap.\n";

    echo "=== MIGRASI TATA KELOLA SUPERVISI SELESAI SUKSES 100% ===\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
