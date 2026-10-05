-- =========================================================================
-- ACL & DATABASE FULL MIGRATION SCRIPT
-- Proyek: AIS Absensi An-Nahl Islamic School
-- Pembaruan Terkini: 2026-10-05
-- Fitur: Multi-Shift Security, Pemisahan Siswa & Staff (Jadwal & Activity),
--        Jadwal Eskul per Murid, Template Pesan WhatsApp Unit,
--        Audit Log Aktivitas & Rollback, Dashboard Pemakaian WA,
--        Kelola Akun Cadangan Darurat Superadmin.
-- =========================================================================
-- File ini aman dijalankan berulang kali (Idempotent: CREATE IF NOT EXISTS,
-- INSERT IGNORE, dan pengecekan kolom).
-- =========================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------------
-- 1. TABEL: roles — Master Role Sistem
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_key` varchar(50) NOT NULL COMMENT 'Kode unik role, dipakai di kolom users.role',
  `role_name` varchar(100) NOT NULL COMMENT 'Nama tampilan role',
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT 0 COMMENT '1 = tidak bisa dihapus',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `roles` (`role_key`, `role_name`, `description`, `is_system`) VALUES
('super_admin',    'Super Admin',           'Akses penuh ke seluruh sistem dan konfigurasi', 1),
('kepala_sekolah', 'Kepala Sekolah',        'Akses monitoring, jadwal unit, dan laporan unit', 1),
('admin',          'Admin Unit / Pemantau', 'Akses monitoring data dan absensi siswa', 1),
('staff',          'Staff / Guru',          'Akses dasar guru dan pegawai', 1);

-- -------------------------------------------------------------------------
-- 2. TABEL: app_menus — Master Menu & Fitur Aplikasi
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_menus` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `menu_key` varchar(50) DEFAULT NULL COMMENT 'Identifier unik, digunakan di checkUserAccess()',
  `menu_name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'UTAMA',
  `menu_url` varchar(255) NOT NULL,
  `menu_icon` varchar(50) DEFAULT NULL COMMENT 'Class FontAwesome: fa-home, fa-qrcode, dst.',
  `parent_id` int unsigned DEFAULT NULL,
  `sort_order` int DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_menu_key` (`menu_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Sinkronisasi Menu Lengkap (Termasuk Fitur Baru 2026-10-05)
INSERT IGNORE INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `sort_order`) VALUES
('dashboard',                'Dashboard',                'UTAMA',       'dashboard.php',                    'fa-home',             1),
('scanner',                  'Scanner',                  'UTAMA',       'scanner.php',                      'fa-qrcode',           2),
('attendance_students',      'Absensi Siswa',            'KEHADIRAN',   'attendance/students.php',          'fa-user-check',       3),
('attendance_staff',         'Absensi Staff',            'KEHADIRAN',   'attendance/staff.php',             'fa-user-tie',         4),
('master_students',          'Siswa',                    'MASTER DATA', 'students/index.php',               'fa-users',            5),
('master_staff',             'Staff / Guru',             'MASTER DATA', 'staff/index.php',                  'fa-chalkboard-teacher',6),
('master_admins',            'Admin Pemantau',           'MASTER DATA', 'admins/index.php',                 'fa-user-shield',      7),
('master_kepsek',            'Kepala Sekolah',           'MASTER DATA', 'kepala_sekolah/index.php',         'fa-user-tie',         8),
('master_units',             'Unit',                     'MASTER DATA', 'units/index.php',                  'fa-building',         9),
('master_grades',            'Grade',                    'MASTER DATA', 'grades/index.php',                 'fa-layer-group',      10),
('master_classes',           'Subkelas',                 'MASTER DATA', 'classes/index.php',                'fa-door-open',        11),
('master_activities',        'Activities',               'MASTER DATA', 'activities/index.php',             'fa-calendar-alt',     12),
('master_enrollments',       'Kenaikan Kelas',           'MASTER DATA', 'enrollments/index.php',            'fa-exchange-alt',     13),
('master_schedules',         'Jadwal Pekanan',           'MASTER DATA', 'weekly_schedules/index.php',       'fa-clock',            14),
('master_academic_years',    'Tahun Ajaran',             'MASTER DATA', 'academic_years/index.php',         'fa-calendar',         15),
('reports_attendance',       'Laporan Absen',            'LAPORAN',     'reports/index.php',                'fa-file-alt',         16),
('reports_overtimes',        'Lembur Staff',             'LAPORAN',     'overtimes/index.php',              'fa-business-time',    17),
('access_control',           'Manajemen Akses & User',   'PENGATURAN',  'kelola/index.php',                 'fa-user-cog',         18),
('user_activity',            'Aktivitas Pengguna',       'PENGATURAN',  'kelola/activity.php',             'fa-user-clock',       19),
('data_cctv',                'Data CCTV',                'PENGATURAN',  'cctv/index.php',                   'fa-video',            20),
('kelola_wa_dashboard',      'Dashboard WA Gateway',     'PENGATURAN',  'kelola/wa_dashboard.php',          'fa-chart-pie',        21),
('kelola_audit_logs',        'Audit Log Aktivitas',      'PENGATURAN',  'kelola/audit_logs.php',            'fa-clock-rotate-left',22),
('kelola_message_templates', 'Template Pesan Unit',      'PENGATURAN',  'kelola/message_templates.php',     'fa-comments',         23),
('super_kelola_akun',        'Kelola Akun Cadangan',     'PENGATURAN',  'kelola/super/index.php',           'fa-users-gear',       24);

-- -------------------------------------------------------------------------
-- 3. TABEL: role_menu_access — Hak Akses Menu Default per Role
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_menu_access` (
  `role_id` int unsigned NOT NULL,
  `menu_id` int unsigned NOT NULL,
  PRIMARY KEY (`role_id`, `menu_id`),
  CONSTRAINT `fk_rma_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rma_menu` FOREIGN KEY (`menu_id`) REFERENCES `app_menus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 1. Super Admin: Seluruh menu tanpa batas
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT r.id, am.id FROM roles r, app_menus am WHERE r.role_key = 'super_admin';

-- 2. Kepala Sekolah: Akses unit, jadwal, aktivitas, audit log unit, template pesan unit
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT r.id, am.id FROM roles r, app_menus am
WHERE r.role_key = 'kepala_sekolah'
  AND am.menu_key NOT IN ('access_control', 'master_academic_years', 'master_kepsek', 'super_kelola_akun', 'data_cctv');

-- 3. Admin Unit / Pemantau: Monitoring
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT r.id, am.id FROM roles r, app_menus am
WHERE r.role_key = 'admin'
  AND am.menu_key IN ('dashboard', 'scanner', 'attendance_students', 'master_students', 'reports_attendance');

-- 4. Staff / Guru: Dashboard & Scanner
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT r.id, am.id FROM roles r, app_menus am
WHERE r.role_key = 'staff'
  AND am.menu_key IN ('dashboard', 'scanner');

-- -------------------------------------------------------------------------
-- 4. TABEL: user_menu_access — Penyesuaian Akses Spesifik per User
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_menu_access` (
  `user_id` bigint unsigned NOT NULL,
  `menu_id` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `menu_id`),
  CONSTRAINT `fk_uma_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uma_menu` FOREIGN KEY (`menu_id`) REFERENCES `app_menus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Sinkronkan user_menu_access untuk user eksisting
INSERT IGNORE INTO `user_menu_access` (`user_id`, `menu_id`)
SELECT u.id, rma.menu_id
FROM users u
INNER JOIN roles r ON r.role_key = u.role
INNER JOIN role_menu_access rma ON rma.role_id = r.id
WHERE u.deleted_at IS NULL;

-- -------------------------------------------------------------------------
-- 5. TABEL BARU: activity_audit_logs — Pencatatan Aktivitas, Audit & Rollback
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `username` VARCHAR(100) NOT NULL,
  `role` VARCHAR(50) NOT NULL,
  `unit_id` INT DEFAULT NULL,
  `module` VARCHAR(100) NOT NULL,
  `action` VARCHAR(50) NOT NULL COMMENT 'CREATE, UPDATE, DELETE, ROLLBACK, STATUS_CHANGE',
  `table_name` VARCHAR(100) NOT NULL,
  `record_id` BIGINT DEFAULT NULL,
  `description` TEXT NOT NULL,
  `old_data` LONGTEXT DEFAULT NULL,
  `new_data` LONGTEXT DEFAULT NULL,
  `is_rolled_back` TINYINT(1) NOT NULL DEFAULT 0,
  `rolled_back_at` DATETIME DEFAULT NULL,
  `rolled_back_by` VARCHAR(100) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_unit` (`unit_id`),
  KEY `idx_audit_module` (`module`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 6. TABEL BARU: weekly_schedule_students — Relasi Peserta Eskul per Murid
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_schedule_students` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `weekly_schedule_id` INT NOT NULL,
  `student_id` BIGINT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sch_stu` (`weekly_schedule_id`, `student_id`),
  KEY `idx_sch_id` (`weekly_schedule_id`),
  KEY `idx_stu_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 7. TABEL BARU: weekly_schedule_staff — Relasi Penugasan Shift per Personel
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_schedule_staff` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `weekly_schedule_id` INT NOT NULL,
  `staff_id` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sch_stf` (`weekly_schedule_id`, `staff_id`),
  KEY `idx_wss_sch` (`weekly_schedule_id`),
  KEY `idx_wss_stf` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 8. TABEL BARU: unit_message_templates — 4 Template Pesan Otomatis per Unit
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `unit_message_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` INT NOT NULL,
  `message_type` ENUM('masuk_siswa', 'pulang_siswa', 'masuk_staff', 'pulang_staff') NOT NULL,
  `template_text` TEXT NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_msg` (`unit_id`, `message_type`),
  KEY `idx_unit_id` (`unit_id`),
  KEY `idx_msg_type` (`message_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 9. TABEL BARU: whatsapp_usage_daily — Agregat Harian WhatsApp per Unit
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `whatsapp_usage_daily` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date` DATE NOT NULL,
  `unit_id` INT NOT NULL,
  `total_sent` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_delivered` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_read` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_failed` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_date_unit` (`log_date`, `unit_id`),
  KEY `idx_log_date` (`log_date`),
  KEY `idx_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 10. MODIFIKASI KOLOM TABEL EKSISTING (PENAMBAHAN KOLOM JIKA BELUM ADA)
-- -------------------------------------------------------------------------

-- A. Tabel weekly_schedules: Fitur Pemisahan Siswa/Staff, Eskul, Grade, Subkelas & Overnight
DROP PROCEDURE IF EXISTS `AddColWeeklySchedules`;
DELIMITER //
CREATE PROCEDURE `AddColWeeklySchedules`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'target_type') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `target_type` ENUM('student', 'staff') NOT NULL DEFAULT 'student' AFTER `unit_id`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'grade_id') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `grade_id` INT NULL AFTER `target_type`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'class_group_id') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `class_group_id` INT NULL AFTER `grade_id`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'schedule_type') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `schedule_type` ENUM('reguler', 'eskul') NOT NULL DEFAULT 'reguler' AFTER `class_group_id`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'student_in') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `student_in` TIME NULL DEFAULT '00:00:00' AFTER `day_code`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'student_late') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `student_late` TIME NULL DEFAULT '00:00:00' AFTER `student_in`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'student_out') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `student_out` TIME NULL DEFAULT '00:00:00' AFTER `student_late`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'staff_in') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `staff_in` TIME NULL DEFAULT '00:00:00' AFTER `student_out`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'staff_late') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `staff_late` TIME NULL DEFAULT '00:00:00' AFTER `staff_in`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'staff_out') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `staff_out` TIME NULL DEFAULT '00:00:00' AFTER `staff_late`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_schedules' AND COLUMN_NAME = 'is_overnight') THEN
    ALTER TABLE `weekly_schedules` ADD COLUMN `is_overnight` TINYINT(1) NOT NULL DEFAULT 0 AFTER `staff_out`;
  END IF;
END //
DELIMITER ;
CALL `AddColWeeklySchedules`();
DROP PROCEDURE IF EXISTS `AddColWeeklySchedules`;

-- B. Tabel auto_attendances: Fitur custom per guru/user, grade, subkelas
DROP PROCEDURE IF EXISTS `AddColAutoAttendances`;
DELIMITER //
CREATE PROCEDURE `AddColAutoAttendances`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auto_attendances' AND COLUMN_NAME = 'user_id') THEN
    ALTER TABLE `auto_attendances` ADD COLUMN `user_id` INT NULL AFTER `unit_id`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auto_attendances' AND COLUMN_NAME = 'grade_id') THEN
    ALTER TABLE `auto_attendances` ADD COLUMN `grade_id` INT NULL AFTER `user_id`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auto_attendances' AND COLUMN_NAME = 'class_group_id') THEN
    ALTER TABLE `auto_attendances` ADD COLUMN `class_group_id` INT NULL AFTER `grade_id`;
  END IF;
END //
DELIMITER ;
CALL `AddColAutoAttendances`();
DROP PROCEDURE IF EXISTS `AddColAutoAttendances`;

-- C. Tabel activities: Fitur pemisahan kegiatan Siswa vs Staff
DROP PROCEDURE IF EXISTS `AddColActivities`;
DELIMITER //
CREATE PROCEDURE `AddColActivities`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activities' AND COLUMN_NAME = 'target_type') THEN
    ALTER TABLE `activities` ADD COLUMN `target_type` ENUM('student', 'staff', 'all') NOT NULL DEFAULT 'student' AFTER `unit_id`;
  END IF;
END //
DELIMITER ;
CALL `AddColActivities`();
DROP PROCEDURE IF EXISTS `AddColActivities`;

-- -------------------------------------------------------------------------
-- 11. SEED DATA DEFAULT & ANTI-TABRAKAN SECURITY SHIFT 1 & SHIFT 2
-- -------------------------------------------------------------------------

-- Tambahkan Jadwal Shift 1 Pagi/Siang Security (Unit 6, 06:00:00 - 18:00:00) untuk hari 1 s/d 7 jika belum ada
INSERT INTO `weekly_schedules` 
  (`unit_id`, `target_type`, `grade_id`, `class_group_id`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 6, 'staff', NULL, NULL, 'reguler', 'Shift 1 Pagi/Siang Security', 
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' WHEN 6 THEN 'Sabtu' WHEN 7 THEN 'Minggu' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '06:00:00', '06:30:00', '18:00:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7) d
WHERE NOT EXISTS (
  SELECT 1 FROM weekly_schedules 
  WHERE unit_id = 6 AND target_type = 'staff' AND day_code = d.code AND (is_overnight = 0 OR staff_in < staff_out)
);

-- Template Pesan WhatsApp Default per Unit (Semua unit yang terdaftar)
INSERT IGNORE INTO `unit_message_templates` (`unit_id`, `message_type`, `template_text`, `is_enabled`)
SELECT u.id, 'masuk_siswa', 
       'Assalamualaikum Wr. Wb. Diberitahukan bahwa ananda *{nama}* telah hadir di {unit} pada pukul *{jam}* (Status: {status}). Terima kasih.', 1
FROM units u;

INSERT IGNORE INTO `unit_message_templates` (`unit_id`, `message_type`, `template_text`, `is_enabled`)
SELECT u.id, 'pulang_siswa', 
       'Assalamualaikum Wr. Wb. Diberitahukan bahwa ananda *{nama}* telah pulang dari {unit} pada pukul *{jam}*. Terima kasih.', 1
FROM units u;

INSERT IGNORE INTO `unit_message_templates` (`unit_id`, `message_type`, `template_text`, `is_enabled`)
SELECT u.id, 'masuk_staff', 
       'Halo {nama}, presensi kehadiran Anda di {unit} tercatat pada pukul {jam} ({status}). Selamat bertugas!', 1
FROM units u;

INSERT IGNORE INTO `unit_message_templates` (`unit_id`, `message_type`, `template_text`, `is_enabled`)
SELECT u.id, 'pulang_staff', 
       'Halo {nama}, presensi kepulangan Anda di {unit} tercatat pada pukul {jam}. Terima kasih atas dedikasi Anda hari ini.', 1
FROM units u;

-- -------------------------------------------------------------------------
-- 12. SEED JADWAL REGULER STAFF & GURU UNTUK SELURUH UNIT SEKOLAH
-- -------------------------------------------------------------------------
-- Bersihkan jam staff dari jadwal siswa legacy (ID 1-7)
UPDATE `weekly_schedules` SET staff_in = '00:00:00', staff_late = '00:00:00', staff_out = '00:00:00', name = 'KBM Reguler SD' WHERE id = 1;
UPDATE `weekly_schedules` SET staff_in = '00:00:00', staff_late = '00:00:00', staff_out = '00:00:00', name = 'KBM Reguler SMP' WHERE id BETWEEN 2 AND 7;

-- Unit 1: TK (Senin - Jumat, 06:45 - 14:30)
INSERT INTO `weekly_schedules` (`unit_id`, `target_type`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 1, 'staff', 'reguler', 'Shift Reguler Guru & Staff TK',
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '06:45:00', '07:00:00', '14:30:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) d
WHERE NOT EXISTS (SELECT 1 FROM weekly_schedules WHERE unit_id = 1 AND target_type = 'staff' AND day_code = d.code);

-- Unit 2: SD (Senin - Jumat, 06:30 - 15:30)
INSERT INTO `weekly_schedules` (`unit_id`, `target_type`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 2, 'staff', 'reguler', 'Shift Reguler Guru & Staff SD',
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '06:30:00', '06:45:00', '15:30:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) d
WHERE NOT EXISTS (SELECT 1 FROM weekly_schedules WHERE unit_id = 2 AND target_type = 'staff' AND day_code = d.code);

-- Unit 3: SMP (Senin - Sabtu, 06:30 - 16:00)
INSERT INTO `weekly_schedules` (`unit_id`, `target_type`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 3, 'staff', 'reguler', 'Shift Reguler Guru & Staff SMP',
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' WHEN 6 THEN 'Sabtu' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '06:30:00', '06:45:00', '16:00:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) d
WHERE NOT EXISTS (SELECT 1 FROM weekly_schedules WHERE unit_id = 3 AND target_type = 'staff' AND day_code = d.code);

-- Unit 4: SDM & Umum (Senin - Jumat, 07:00 - 16:00)
INSERT INTO `weekly_schedules` (`unit_id`, `target_type`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 4, 'staff', 'reguler', 'Shift Reguler SDM & Umum',
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '07:00:00', '07:30:00', '16:00:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) d
WHERE NOT EXISTS (SELECT 1 FROM weekly_schedules WHERE unit_id = 4 AND target_type = 'staff' AND day_code = d.code);

-- Unit 5: Office Boy (Senin - Sabtu, 05:30 - 16:30)
INSERT INTO `weekly_schedules` (`unit_id`, `target_type`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 5, 'staff', 'reguler', 'Shift Reguler Office Boy',
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' WHEN 6 THEN 'Sabtu' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '05:30:00', '06:00:00', '16:30:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) d
WHERE NOT EXISTS (SELECT 1 FROM weekly_schedules WHERE unit_id = 5 AND target_type = 'staff' AND day_code = d.code);

-- Unit 7: SMA (Senin - Jumat, 06:30 - 16:00)
INSERT INTO `weekly_schedules` (`unit_id`, `target_type`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 7, 'staff', 'reguler', 'Shift Reguler Guru & Staff SMA',
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '06:30:00', '06:45:00', '16:00:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) d
WHERE NOT EXISTS (SELECT 1 FROM weekly_schedules WHERE unit_id = 7 AND target_type = 'staff' AND day_code = d.code);

-- Unit 8: Gardener (Senin - Sabtu, 06:00 - 15:00)
INSERT INTO `weekly_schedules` (`unit_id`, `target_type`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 8, 'staff', 'reguler', 'Shift Reguler Gardener',
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' WHEN 6 THEN 'Sabtu' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '06:00:00', '06:30:00', '15:00:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) d
WHERE NOT EXISTS (SELECT 1 FROM weekly_schedules WHERE unit_id = 8 AND target_type = 'staff' AND day_code = d.code);

-- Unit 9: Litbang (Senin - Jumat, 07:30 - 16:00)
INSERT INTO `weekly_schedules` (`unit_id`, `target_type`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 9, 'staff', 'reguler', 'Shift Reguler Litbang',
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '07:30:00', '08:00:00', '16:00:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) d
WHERE NOT EXISTS (SELECT 1 FROM weekly_schedules WHERE unit_id = 9 AND target_type = 'staff' AND day_code = d.code);

-- Unit 10: Marketing & Keuangan (Senin - Jumat, 07:30 - 16:00)
INSERT INTO `weekly_schedules` (`unit_id`, `target_type`, `schedule_type`, `name`, `day_name`, `day_code`, `student_in`, `student_late`, `student_out`, `staff_in`, `staff_late`, `staff_out`, `is_overnight`, `is_active`)
SELECT 10, 'staff', 'reguler', 'Shift Reguler Marketing & Keuangan',
       CASE d.code WHEN 1 THEN 'Senin' WHEN 2 THEN 'Selasa' WHEN 3 THEN 'Rabu' WHEN 4 THEN 'Kamis' WHEN 5 THEN 'Jumat' END,
       d.code, '00:00:00', '00:00:00', '00:00:00', '07:30:00', '08:00:00', '16:00:00', 0, 'active'
FROM (SELECT 1 AS code UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) d
WHERE NOT EXISTS (SELECT 1 FROM weekly_schedules WHERE unit_id = 10 AND target_type = 'staff' AND day_code = d.code);

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================================
-- SELESAI — Migrasi ACL dan Database Berhasil Diperbarui Secara Penuh.
-- =========================================================================

