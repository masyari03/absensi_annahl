<?php
/**
 * Migrasi: Tabel izin akses guru ke modul Mentoring
 *
 * Tabel: mentoring_teacher_permissions
 * - user_id    : ID user guru yang diberi izin
 * - granted_by : ID kepala_sekolah / super_admin yang memberikan izin
 * - unit_id    : Unit kepala sekolah pemberi izin (untuk multi-unit scoping)
 * - created_at : Waktu pemberian izin
 *
 * Cara edit manual:
 * - Untuk menambah kolom baru, tambahkan ALTER TABLE setelah CREATE TABLE.
 * - granted_by bisa NULL jika super_admin global yang memberikan.
 */

require_once __DIR__ . '/../config/database.php';

echo "=== MIGRASI IZIN GURU MENTORING ===\n";

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS `mentoring_teacher_permissions` (
        `id`         bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id`    bigint NOT NULL COMMENT 'ID guru yang diberi izin akses mentoring',
        `granted_by` bigint NOT NULL COMMENT 'ID kepala_sekolah/super_admin pemberi izin',
        `unit_id`    int NULL COMMENT 'Unit sekolah kepala_sekolah pemberi izin',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mentoring_teacher_user` (`user_id`),
        KEY `idx_granted_by` (`granted_by`),
        KEY `idx_unit_id` (`unit_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Izin akses guru ke modul Mentoring, diberikan oleh Kepala Sekolah atau Super Admin';
    ";
    $pdo->exec($sql);
    echo "[OK] Tabel mentoring_teacher_permissions berhasil dibuat/siap.\n";
    echo "=== MIGRASI SELESAI SUKSES ===\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

