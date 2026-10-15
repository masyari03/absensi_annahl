-- Migration: Modul Mentoring Siswa & Pembiasaan Adab
-- Date: 14 Oktober 2026
-- Database: library_v4

-- 1. Master Kurikulum Materi Adab Berjenjang
CREATE TABLE IF NOT EXISTS `mentoring_curriculums` (
    `id` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `grade_level` INT NOT NULL, -- 7, 8, atau 9
    `week_code` VARCHAR(50) NOT NULL, -- 'Pekan 1', 'Pekan 2', dst
    `description` TEXT NULL,
    `file_link` VARCHAR(500) NULL,
    `task_1` TEXT NULL,
    `task_2` TEXT NULL,
    `task_3` TEXT NULL,
    `task_4` TEXT NULL,
    `reflection_prompt_1` TEXT NULL,
    `reflection_prompt_2` TEXT NULL,
    `reflection_prompt_3` TEXT NULL,
    `reflection_prompt_4` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_curriculum_lookup` (`grade_level`, `week_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Penugasan Santri ke Mentor (Mentee-Mentor Assignment)
CREATE TABLE IF NOT EXISTS `mentoring_assignments` (
    `id` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT(20) UNSIGNED NOT NULL,
    `mentor_user_id` BIGINT(20) UNSIGNED NOT NULL,
    `academic_year_id` BIGINT(20) UNSIGNED NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ma_student` (`student_id`),
    INDEX `idx_ma_mentor` (`mentor_user_id`),
    CONSTRAINT `fk_ma_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ma_mentor` FOREIGN KEY (`mentor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Jurnal Sesi Tatap Muka Mentoring Guru (5 Pilar MIP)
CREATE TABLE IF NOT EXISTS `mentoring_sessions` (
    `id` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` BIGINT(20) UNSIGNED NULL,
    `student_id` BIGINT(20) UNSIGNED NOT NULL,
    `mentor_user_id` BIGINT(20) UNSIGNED NOT NULL,
    `session_date` DATE NOT NULL,
    `session_code` VARCHAR(50) NOT NULL, -- Contoh: 'P1-Selasa', 'P4-Jumat'
    `month_name` VARCHAR(30) NOT NULL,
    `year_val` INT NOT NULL,
    `score_mip` DECIMAL(4,1) NULL,
    `notes_mip` TEXT NULL,
    `score_academic` DECIMAL(4,1) NULL,
    `notes_academic` TEXT NULL,
    `score_mutabaah` DECIMAL(4,1) NULL,
    `notes_mutabaah` TEXT NULL,
    `score_adab` DECIMAL(4,1) NULL,
    `notes_adab` TEXT NULL,
    `score_feedback` DECIMAL(4,1) NULL,
    `notes_feedback` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ms_student` (`student_id`),
    INDEX `idx_ms_mentor` (`mentor_user_id`),
    INDEX `idx_ms_session_date` (`session_date`),
    CONSTRAINT `fk_ms_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ms_mentor` FOREIGN KEY (`mentor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Log Checklist Harian & Refleksi 4 Dimensi Siswa
CREATE TABLE IF NOT EXISTS `student_adab_logs` (
    `id` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT(20) UNSIGNED NOT NULL,
    `curriculum_id` BIGINT(20) UNSIGNED NULL,
    `grade_name` VARCHAR(20) NOT NULL, -- Contoh: '7A', '8B'
    `week_code` VARCHAR(50) NOT NULL, -- 'Pekan 1', 'Pekan 2'
    `day_name` VARCHAR(30) NOT NULL, -- 'Selasa', 'Rabu', 'Kamis'
    `checklist_json` JSON NOT NULL, -- {"Selasa":[true,true,true,true,true], ...}
    `reflection_action` TEXT NULL,
    `reflection_obstacle` TEXT NULL,
    `reflection_solution` TEXT NULL,
    `reflection_gratitude` TEXT NULL,
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sal_student` (`student_id`),
    INDEX `idx_sal_grade_week` (`grade_name`, `week_code`),
    CONSTRAINT `fk_sal_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Pendaftaran Menu ACL
INSERT INTO `app_menus` (`id`, `menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `sort_order`, `is_active`)
VALUES
    (99, 'mentoring_dashboard', 'Mentoring Siswa (MIP)', 'PEMBINAAN', 'mentoring/index.php', 'fa-hands-holding-child', 17, 1),
    (100, 'mentoring_adab', 'Pemantauan Adab', 'PEMBINAAN', 'mentoring/adab_monitoring.php', 'fa-heart-circle-check', 18, 1),
    (101, 'mentoring_curriculum', 'Kurikulum Adab', 'PENGATURAN', 'mentoring/curriculum.php', 'fa-book-open-reader', 25, 1)
ON DUPLICATE KEY UPDATE
    `menu_name` = VALUES(`menu_name`),
    `category` = VALUES(`category`),
    `menu_url` = VALUES(`menu_url`),
    `menu_icon` = VALUES(`menu_icon`),
    `sort_order` = VALUES(`sort_order`),
    `is_active` = 1;

-- 6. Mapping Role Menu Access (roles: 1=super_admin, 2=kepala_sekolah, 3=admin, 4=staff/guru)
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`) VALUES
    (1, 99), (1, 100), (1, 101),
    (2, 99), (2, 100), (2, 101),
    (3, 99), (3, 100),
    (4, 99), (4, 100);
