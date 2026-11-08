-- =========================================================================
-- MIGRATION: Penilaian Kinerja Menu & Access Control (Super Admin Exclusive)
-- =========================================================================

-- 1. Insert Parent Menu (Penilaian Kinerja)
INSERT INTO `app_menus` (`id`, `menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `parent_id`, `sort_order`, `is_active`)
VALUES (112, 'kinerja_parent', 'Penilaian Kinerja', 'PENILAIAN KINERJA', '#', 'fa-chart-line', NULL, 65, 1)
ON DUPLICATE KEY UPDATE 
    `menu_name` = VALUES(`menu_name`),
    `category` = VALUES(`category`),
    `menu_url` = VALUES(`menu_url`),
    `menu_icon` = VALUES(`menu_icon`),
    `sort_order` = VALUES(`sort_order`),
    `is_active` = VALUES(`is_active`);

-- 2. Insert Submenu (Nilai Rata- Rata semua kelas)
INSERT INTO `app_menus` (`id`, `menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `parent_id`, `sort_order`, `is_active`)
VALUES (113, 'kinerja_nilai_paralel', 'Nilai Rata- Rata semua kelas', 'PENILAIAN KINERJA', 'kinerja/index.php', 'fa-ranking-star', 112, 1, 1)
ON DUPLICATE KEY UPDATE 
    `menu_name` = VALUES(`menu_name`),
    `category` = VALUES(`category`),
    `menu_url` = VALUES(`menu_url`),
    `menu_icon` = VALUES(`menu_icon`),
    `parent_id` = VALUES(`parent_id`),
    `sort_order` = VALUES(`sort_order`),
    `is_active` = VALUES(`is_active`);

-- 3. Inisialisasi Role Menu Access (Default Hanya Super Admin Aktif)
-- Super Admin (role_id = 1) -> Full Access
INSERT INTO `role_menu_access` (`role_id`, `menu_id`, `can_read`, `can_create`, `can_edit`, `can_delete`)
VALUES 
    (1, 112, 1, 1, 1, 1),
    (1, 113, 1, 1, 1, 1)
ON DUPLICATE KEY UPDATE 
    `can_read` = 1, `can_create` = 1, `can_edit` = 1, `can_delete` = 1;

-- Kepala Sekolah (role_id = 2) -> Default Tutup (0)
INSERT INTO `role_menu_access` (`role_id`, `menu_id`, `can_read`, `can_create`, `can_edit`, `can_delete`)
VALUES 
    (2, 112, 0, 0, 0, 0),
    (2, 113, 0, 0, 0, 0)
ON DUPLICATE KEY UPDATE 
    `can_read` = 0, `can_create` = 0, `can_edit` = 0, `can_delete` = 0;

-- Admin (role_id = 3) -> Default Tutup (0)
INSERT INTO `role_menu_access` (`role_id`, `menu_id`, `can_read`, `can_create`, `can_edit`, `can_delete`)
VALUES 
    (3, 112, 0, 0, 0, 0),
    (3, 113, 0, 0, 0, 0)
ON DUPLICATE KEY UPDATE 
    `can_read` = 0, `can_create` = 0, `can_edit` = 0, `can_delete` = 0;
