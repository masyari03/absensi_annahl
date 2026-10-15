-- Migration: Student Portal, Bulk Accounts, Permissions & Forensic Logs
-- Date: 13 Oktober 2026
-- Database: library_v4

-- 1. Modify users table role enum and add student_id
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('super_admin','admin','staff','kepala_sekolah','siswa') NOT NULL DEFAULT 'staff';
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `student_id` INT(11) NULL DEFAULT NULL AFTER `role`;
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_student_id` (`student_id`);

-- 2. Create student_permits table
CREATE TABLE IF NOT EXISTS `student_permits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT(11) NOT NULL,
  `class_group_id` INT(11) NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `permit_type` ENUM('izin', 'sakit') NOT NULL DEFAULT 'izin',
  `reason` TEXT NOT NULL,
  `attachment` VARCHAR(255) NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` INT(11) NULL,
  `review_notes` TEXT NULL,
  `reviewed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  INDEX `idx_sp_student_id` (`student_id`),
  INDEX `idx_sp_class_group_id` (`class_group_id`),
  INDEX `idx_sp_status` (`status`),
  INDEX `idx_sp_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create student_achievements table
CREATE TABLE IF NOT EXISTS `student_achievements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `level` VARCHAR(50) NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `achievement_date` DATE NOT NULL,
  `description` TEXT NULL,
  `certificate_file` VARCHAR(255) NULL,
  `status` ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
  `verified_by` INT(11) NULL,
  `verified_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  INDEX `idx_sa_student_id` (`student_id`),
  INDEX `idx_sa_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create student_activity_logs table
CREATE TABLE IF NOT EXISTS `student_activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT(11) NOT NULL,
  `user_id` INT(11) NULL,
  `activity_type` VARCHAR(50) NOT NULL,
  `description` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `device_name` VARCHAR(255) NULL,
  `user_agent` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  INDEX `idx_sal_student_id` (`student_id`),
  INDEX `idx_sal_user_id` (`user_id`),
  INDEX `idx_sal_activity_type` (`activity_type`),
  INDEX `idx_sal_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. System settings defaults
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
  ('student_web_permit_enabled', '1'),
  ('student_can_change_username', '0'),
  ('student_can_change_photo', '0')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- 6. Menus and ACL
INSERT IGNORE INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `sort_order`, `is_active`) VALUES
  ('student_permits', 'Izin & Surat Sakit', 'KEHADIRAN', 'attendance/permits.php', 'fa-file-medical', 5, 1),
  ('student_achievements', 'Piagam & Prestasi', 'MASTER DATA', 'achievements/index.php', 'fa-trophy', 16, 1);
