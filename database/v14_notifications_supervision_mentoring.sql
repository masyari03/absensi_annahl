-- =========================================================================
-- MIGRATION: V14 NOTIFICATIONS, SUPERVISION ENHANCEMENT, & MENTORING SCOPING
-- Target Database: library_v4
-- =========================================================================

-- 1. Buat Tabel notifications
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

-- 2. Buat Tabel Multi-Observer supervision_schedule_observers
CREATE TABLE IF NOT EXISTS `supervision_schedule_observers` (
    `schedule_id` BIGINT UNSIGNED NOT NULL,
    `observer_id` BIGINT UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`schedule_id`, `observer_id`),
    KEY `idx_sso_obs` (`observer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tambah Kolom di supervision_schedules
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supervision_schedules' AND column_name = 'principal_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE supervision_schedules ADD COLUMN principal_id BIGINT UNSIGNED NULL AFTER observer_id', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supervision_schedules' AND column_name = 'delegated_user_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE supervision_schedules ADD COLUMN delegated_user_id BIGINT UNSIGNED NULL AFTER principal_id', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supervision_schedules' AND column_name = 'bypass_time_lock');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE supervision_schedules ADD COLUMN bypass_time_lock TINYINT(1) NOT NULL DEFAULT 0 AFTER room_location', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supervision_schedules' AND column_name = 'allow_edit');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE supervision_schedules ADD COLUMN allow_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER bypass_time_lock', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Tambah Kolom di supervision_sessions
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supervision_sessions' AND column_name = 'status');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE supervision_sessions ADD COLUMN status ENUM(\'draft\', \'completed\') NOT NULL DEFAULT \'completed\' AFTER room_location', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supervision_sessions' AND column_name = 'is_delegated');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE supervision_sessions ADD COLUMN is_delegated TINYINT(1) NOT NULL DEFAULT 0 AFTER status', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Seeding supervision_schedule_observers dari data eksisting
INSERT IGNORE INTO supervision_schedule_observers (schedule_id, observer_id, is_primary)
SELECT id, observer_id, 1
FROM supervision_schedules
WHERE observer_id IS NOT NULL AND observer_id > 0;
