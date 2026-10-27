-- =========================================================================
-- MIGRATION: ADVANCED GRANULAR RBAC (2D CRUD MATRIX & DYNAMIC MULTI-ROLE)
-- Target Database: library_v4
-- =========================================================================

-- 1. Pastikan role 'siswa' ada di tabel roles
INSERT IGNORE INTO roles (role_key, role_name, description, is_system)
SELECT 'siswa', 'Siswa / Santri', 'Akses portal mandiri siswa dan santri', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_key = 'siswa');

-- 2. Ubah kolom users.role menjadi VARCHAR(50) dinamis
ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff';

-- 3. Buat tabel user_roles (Pivot Multi-Role)
CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id` BIGINT(20) UNSIGNED NOT NULL,
    `role_id` INT(10) UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `role_id`),
    KEY `idx_ur_user` (`user_id`),
    KEY `idx_ur_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Seeding user_roles dari data users yang sudah ada
INSERT IGNORE INTO user_roles (user_id, role_id, is_primary)
SELECT u.id, r.id, 1
FROM users u
INNER JOIN roles r ON r.role_key = u.role;

-- 5. Tambah kolom granular CRUD di role_menu_access jika belum ada
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'role_menu_access' AND column_name = 'can_read');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE role_menu_access ADD COLUMN can_read TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'role_menu_access' AND column_name = 'can_create');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE role_menu_access ADD COLUMN can_create TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'role_menu_access' AND column_name = 'can_edit');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE role_menu_access ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'role_menu_access' AND column_name = 'can_delete');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE role_menu_access ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Tambah kolom granular CRUD di user_menu_access jika belum ada
SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_menu_access' AND column_name = 'can_read');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE user_menu_access ADD COLUMN can_read TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_menu_access' AND column_name = 'can_create');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE user_menu_access ADD COLUMN can_create TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_menu_access' AND column_name = 'can_edit');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE user_menu_access ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_menu_access' AND column_name = 'can_delete');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE user_menu_access ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Inisialisasi default permissions
UPDATE role_menu_access SET can_read = 1, can_create = 1, can_edit = 1, can_delete = 1 
WHERE role_id IN (SELECT id FROM roles WHERE role_key = 'super_admin');

UPDATE role_menu_access SET can_read = 1, can_create = 1, can_edit = 1 
WHERE role_id IN (SELECT id FROM roles WHERE role_key IN ('kepala_sekolah', 'admin'));

UPDATE role_menu_access SET can_read = 1, can_create = 1 
WHERE role_id IN (SELECT id FROM roles WHERE role_key = 'staff');
