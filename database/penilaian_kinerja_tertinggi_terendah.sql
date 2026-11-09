-- =========================================================================
-- MIGRATION: Penilaian Kinerja - Nilai Tertinggi dan Terendah (ID: 114)
-- =========================================================================

-- 1. Insert Submenu (Nilai Tertinggi dan Terendah) di bawah parent Penilaian Kinerja (ID 112)
INSERT INTO `app_menus` (`id`, `menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `parent_id`, `sort_order`, `is_active`)
VALUES (114, 'kinerja_tertinggi_terendah', 'Nilai Tertinggi dan Terendah', 'PENILAIAN KINERJA', 'kinerja/tertinggi_terendah.php', 'fa-arrow-up-right-dots', 112, 2, 1)
ON DUPLICATE KEY UPDATE 
    `menu_name` = VALUES(`menu_name`),
    `category` = VALUES(`category`),
    `menu_url` = VALUES(`menu_url`),
    `menu_icon` = VALUES(`menu_icon`),
    `parent_id` = VALUES(`parent_id`),
    `sort_order` = VALUES(`sort_order`),
    `is_active` = VALUES(`is_active`);

-- 2. Inisialisasi Role Menu Access (Default Hanya Super Admin Aktif)
-- Super Admin (role_id = 1) -> Full Access
INSERT INTO `role_menu_access` (`role_id`, `menu_id`, `can_read`, `can_create`, `can_edit`, `can_delete`)
VALUES (1, 114, 1, 1, 1, 1)
ON DUPLICATE KEY UPDATE 
    `can_read` = 1, `can_create` = 1, `can_edit` = 1, `can_delete` = 1;

-- Kepala Sekolah (role_id = 2) -> Default Tutup (0), dapat diaktifkan di matriks
INSERT INTO `role_menu_access` (`role_id`, `menu_id`, `can_read`, `can_create`, `can_edit`, `can_delete`)
VALUES (2, 114, 0, 0, 0, 0)
ON DUPLICATE KEY UPDATE 
    `can_read` = 0, `can_create` = 0, `can_edit` = 0, `can_delete` = 0;

-- Admin Unit (role_id = 3) -> Default Tutup (0), dapat diaktifkan di matriks
INSERT INTO `role_menu_access` (`role_id`, `menu_id`, `can_read`, `can_create`, `can_edit`, `can_delete`)
VALUES (3, 114, 0, 0, 0, 0)
ON DUPLICATE KEY UPDATE 
    `can_read` = 0, `can_create` = 0, `can_edit` = 0, `can_delete` = 0;
