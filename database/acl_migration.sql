-- =========================================================================
-- ACL MIGRATION — Database-Driven Access Control
-- Proyek: AIS Absensi An-Nahl Islamic School
-- Tanggal: 2026-09-20
-- =========================================================================
-- File ini memastikan semua tabel ACL sudah ada dan terisi dengan benar.
-- Aman dijalankan berulang kali (menggunakan IF NOT EXISTS & INSERT IGNORE).
-- =========================================================================

-- -------------------------------------------------------------------------
-- TABEL 1: roles — Master Role yang bisa dibuat/diedit oleh Super Admin
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

ALTER TABLE `roles` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Default roles (sistem — tidak bisa dihapus)
INSERT IGNORE INTO `roles` (`role_key`, `role_name`, `description`, `is_system`) VALUES
('super_admin',    'Super Admin',         'Akses penuh ke seluruh sistem dan konfigurasi', 1),
('kepala_sekolah', 'Kepala Sekolah',      'Akses monitoring dan laporan unit sekolah', 1),
('admin',          'Admin Unit / Pemantau','Akses monitoring data dan absensi siswa', 1),
('staff',          'Staff / Guru',        'Akses dasar guru dan pegawai', 1);

-- -------------------------------------------------------------------------
-- TABEL 2: app_menus — Daftar semua halaman/fitur di aplikasi
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

-- Data menu (sesuai dengan struktur project)
INSERT IGNORE INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `sort_order`) VALUES
('dashboard',            'Dashboard',              'UTAMA',       'dashboard.php',                    'fa-home',             1),
('scanner',              'Scanner',                'UTAMA',       'scanner.php',                      'fa-qrcode',           2),
('attendance_students',  'Absensi Siswa',          'KEHADIRAN',   'attendance/students.php',          'fa-user-check',       3),
('attendance_staff',     'Absensi Staff',          'KEHADIRAN',   'attendance/staff.php',             'fa-user-tie',         4),
('master_students',      'Siswa',                  'MASTER DATA', 'students/index.php',               'fa-users',            5),
('master_staff',         'Staff / Guru',           'MASTER DATA', 'staff/index.php',                  'fa-chalkboard-teacher',6),
('master_admins',        'Admin Pemantau',         'MASTER DATA', 'admins/index.php',                 'fa-user-shield',      7),
('master_kepsek',        'Kepala Sekolah',         'MASTER DATA', 'kepala_sekolah/index.php',         'fa-user-tie',         8),
('master_units',         'Unit',                   'MASTER DATA', 'units/index.php',                  'fa-building',         9),
('master_grades',        'Grade',                  'MASTER DATA', 'grades/index.php',                 'fa-layer-group',      10),
('master_classes',       'Subkelas',               'MASTER DATA', 'classes/index.php',                'fa-door-open',        11),
('master_activities',    'Activities',             'MASTER DATA', 'activities/index.php',             'fa-calendar-alt',     12),
('master_enrollments',   'Kenaikan Kelas',         'MASTER DATA', 'enrollments/index.php',            'fa-exchange-alt',     13),
('master_schedules',     'Jadwal Pekanan',         'MASTER DATA', 'weekly_schedules/index.php',       'fa-clock',            14),
('master_academic_years','Tahun Ajaran',           'MASTER DATA', 'academic_years/index.php',         'fa-calendar',         15),
('reports_attendance',   'Laporan Absen',          'LAPORAN',     'reports/index.php',                'fa-file-alt',         16),
('reports_overtimes',    'Lembur Staff',           'LAPORAN',     'overtimes/index.php',              'fa-business-time',    17),
('access_control',       'Manajemen Akses & User', 'PENGATURAN',  'kelola/index.php',                 'fa-user-cog',         18),
('user_activity',        'Aktivitas & Log Pengguna', 'PENGATURAN', 'kelola/activity.php',             'fa-user-clock',       19),
('data_cctv',            'Data CCTV',              'PENGATURAN',  'cctv/index.php',                   'fa-video',            20);

-- -------------------------------------------------------------------------
-- TABEL 3: role_menu_access — Default akses per role
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_menu_access` (
  `role_id` int unsigned NOT NULL,
  `menu_id` int unsigned NOT NULL,
  PRIMARY KEY (`role_id`, `menu_id`),
  CONSTRAINT `fk_rma_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rma_menu` FOREIGN KEY (`menu_id`) REFERENCES `app_menus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default: Super Admin dapat semua menu
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT r.id, am.id FROM roles r, app_menus am WHERE r.role_key = 'super_admin';

-- Default: Kepala Sekolah (kecuali access_control dan master_academic_years)
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT r.id, am.id FROM roles r, app_menus am
WHERE r.role_key = 'kepala_sekolah'
  AND am.menu_key NOT IN ('access_control', 'master_academic_years', 'master_kepsek');

-- Default: Admin (hanya monitoring)
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT r.id, am.id FROM roles r, app_menus am
WHERE r.role_key = 'admin'
  AND am.menu_key IN ('dashboard', 'scanner', 'attendance_students', 'master_students', 'reports_attendance');

-- Default: Staff/Guru (hanya dashboard dan scanner)
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT r.id, am.id FROM roles r, app_menus am
WHERE r.role_key = 'staff'
  AND am.menu_key IN ('dashboard', 'scanner');

-- -------------------------------------------------------------------------
-- TABEL 4: user_menu_access — Hak akses per user (override role default)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_menu_access` (
  `user_id` bigint unsigned NOT NULL,
  `menu_id` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `menu_id`),
  CONSTRAINT `fk_uma_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uma_menu` FOREIGN KEY (`menu_id`) REFERENCES `app_menus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- POPULATE user_menu_access dari role_menu_access untuk user yang belum ada
-- (Jalankan sekali saja untuk populate user lama)
-- -------------------------------------------------------------------------
INSERT IGNORE INTO `user_menu_access` (`user_id`, `menu_id`)
SELECT u.id, rma.menu_id
FROM users u
INNER JOIN roles r ON r.role_key = u.role
INNER JOIN role_menu_access rma ON rma.role_id = r.id
WHERE u.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM user_menu_access uma2
    WHERE uma2.user_id = u.id
  );

-- -------------------------------------------------------------------------
-- TABEL 4: user_login_logs & Kolom Aktivitas Pengguna
-- -------------------------------------------------------------------------
ALTER TABLE `users` 
  ADD COLUMN IF NOT EXISTS `last_activity` DATETIME DEFAULT NULL AFTER `deleted_at`,
  ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME DEFAULT NULL AFTER `last_activity`,
  ADD COLUMN IF NOT EXISTS `last_login_ip` VARCHAR(45) DEFAULT NULL AFTER `last_login_at`,
  ADD COLUMN IF NOT EXISTS `is_online` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_login_ip`;

CREATE TABLE IF NOT EXISTS `user_login_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `role` VARCHAR(50) NOT NULL,
  `is_superadmin` TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `device_type` VARCHAR(50) DEFAULT 'Desktop',
  `browser` VARCHAR(100) DEFAULT NULL,
  `platform` VARCHAR(100) DEFAULT NULL,
  `login_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `logout_at` DATETIME DEFAULT NULL,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('online', 'offline', 'logged_out', 'revoked') NOT NULL DEFAULT 'online',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role` (`role`),
  KEY `idx_is_superadmin` (`is_superadmin`),
  KEY `idx_login_at` (`login_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =========================================================================
-- SELESAI — Sistem ACL & Logging siap digunakan.
-- =========================================================================

