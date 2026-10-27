<?php
/**
 * Migration Runner for Plan Edisi 14
 */
require_once __DIR__ . '/../config/database.php';
global $pdo;

echo "=== MEMULAI MIGRASI PLAN EDISI 14 ===\n";

try {
    // 1. Tabel notifications
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `message` TEXT NOT NULL,
            `category` ENUM('supervisi', 'mentoring', 'presensi', 'sistem') NOT NULL DEFAULT 'sistem',
            `link_url` VARCHAR(255) NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_notif_user` (`user_id`, `is_read`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[1] Tabel notifications siap.\n";

    // 2. Tabel supervision_schedule_observers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `supervision_schedule_observers` (
            `schedule_id` BIGINT UNSIGNED NOT NULL,
            `observer_id` BIGINT UNSIGNED NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`schedule_id`, `observer_id`),
            KEY `idx_sso_obs` (`observer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[2] Tabel supervision_schedule_observers siap.\n";

    // Helper col exists
    function colExists(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.columns 
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ");
        $stmt->execute([$table, $column]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    // 3. Kolom supervision_schedules
    if (!colExists($pdo, 'supervision_schedules', 'principal_id')) {
        $pdo->exec("ALTER TABLE supervision_schedules ADD COLUMN principal_id BIGINT UNSIGNED NULL AFTER observer_id");
        echo "[3a] Added supervision_schedules.principal_id\n";
    }
    if (!colExists($pdo, 'supervision_schedules', 'delegated_user_id')) {
        $pdo->exec("ALTER TABLE supervision_schedules ADD COLUMN delegated_user_id BIGINT UNSIGNED NULL AFTER principal_id");
        echo "[3b] Added supervision_schedules.delegated_user_id\n";
    }
    if (!colExists($pdo, 'supervision_schedules', 'bypass_time_lock')) {
        $pdo->exec("ALTER TABLE supervision_schedules ADD COLUMN bypass_time_lock TINYINT(1) NOT NULL DEFAULT 0 AFTER room_location");
        echo "[3c] Added supervision_schedules.bypass_time_lock\n";
    }
    if (!colExists($pdo, 'supervision_schedules', 'allow_edit')) {
        $pdo->exec("ALTER TABLE supervision_schedules ADD COLUMN allow_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER bypass_time_lock");
        echo "[3d] Added supervision_schedules.allow_edit\n";
    }

    // 4. Kolom supervision_sessions
    if (!colExists($pdo, 'supervision_sessions', 'status')) {
        $pdo->exec("ALTER TABLE supervision_sessions ADD COLUMN status ENUM('draft', 'completed') NOT NULL DEFAULT 'completed' AFTER room_location");
        echo "[4a] Added supervision_sessions.status\n";
    }
    if (!colExists($pdo, 'supervision_sessions', 'is_delegated')) {
        $pdo->exec("ALTER TABLE supervision_sessions ADD COLUMN is_delegated TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        echo "[4b] Added supervision_sessions.is_delegated\n";
    }

    // 5. Seeding multi-observer
    $pdo->exec("
        INSERT IGNORE INTO supervision_schedule_observers (schedule_id, observer_id, is_primary)
        SELECT id, observer_id, 1
        FROM supervision_schedules
        WHERE observer_id IS NOT NULL AND observer_id > 0
    ");
    echo "[5] Seeding supervision_schedule_observers selesai.\n";

    echo "=== MIGRASI SELESAI SUKSES 100% ===\n";
} catch (Exception $e) {
    echo "ERROR MIGRASI: " . $e->getMessage() . "\n";
    exit(1);
}
