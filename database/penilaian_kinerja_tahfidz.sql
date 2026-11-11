-- =========================================================================
-- Migrasi Menu & Kurikulum Target Capaian Tahfidz (Penilaian Kinerja)
-- Database target: library_v4
-- Tanggal: 2026-11-11
-- =========================================================================

-- 1. Pastikan Menu Induk 'Penilaian Kinerja' (ID 112) aktif
INSERT INTO app_menus (id, menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active)
VALUES (112, 'kinerja_parent', 'Penilaian Kinerja', 'PENILAIAN KINERJA', '#', 'fa-chart-pie', NULL, 23, 1)
ON DUPLICATE KEY UPDATE
    menu_name  = VALUES(menu_name),
    category   = VALUES(category),
    menu_icon  = VALUES(menu_icon),
    sort_order = VALUES(sort_order),
    is_active  = 1;

-- 2. Tambahkan Sub-menu 'Capaian Tahfidz' (ID 115)
INSERT INTO app_menus (id, menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active)
VALUES (115, 'kinerja_capaian_tahfidz', 'Capaian Tahfidz', 'PENILAIAN KINERJA', 'kinerja/tahfidz.php', 'fa-book-quran', 112, 3, 1)
ON DUPLICATE KEY UPDATE
    menu_name  = VALUES(menu_name),
    category   = VALUES(category),
    menu_url   = VALUES(menu_url),
    menu_icon  = VALUES(menu_icon),
    parent_id  = VALUES(parent_id),
    sort_order = VALUES(sort_order),
    is_active  = 1;

-- 3. Default Akses Role: Super Admin FULL ACCESS; role lain default TERTUTUP (0) via INSERT IGNORE
INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, 115, 1, 1, 1, 1
FROM roles r
WHERE r.role_key = 'super_admin'
ON DUPLICATE KEY UPDATE
    can_read = 1, can_create = 1, can_edit = 1, can_delete = 1;

INSERT IGNORE INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, 115, 0, 0, 0, 0
FROM roles r
WHERE r.role_key <> 'super_admin';

-- 4. Tabel Referensi Target Kurikulum Tahfidz per Unit / Paralel / Semester
CREATE TABLE IF NOT EXISTS kinerja_tahfidz_targets (
    id INT NOT NULL AUTO_INCREMENT,
    unit_id INT NOT NULL,
    paralel_id INT NOT NULL,
    paralel_nama VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    semester TINYINT NOT NULL DEFAULT 1,
    target_type ENUM('juz30_rank','juz29_rank','juz28_rank','juz29_coverage','juz28_coverage','juz_stage','teacher_individual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'juz30_rank',
    target_value DECIMAL(6,2) NOT NULL DEFAULT 13.00,
    target_label VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL,
    require_munaqasyah TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unit_paralel_sem (unit_id, paralel_id, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pastikan ENUM sudah mendukung juz29_rank & juz28_rank apabila tabel lama sudah ada
ALTER TABLE kinerja_tahfidz_targets
    MODIFY COLUMN target_type ENUM('juz30_rank','juz29_rank','juz28_rank','juz29_coverage','juz28_coverage','juz_stage','teacher_individual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'juz30_rank';

-- 5. Seed Default Target Kurikulum (TK, SD, SMP, SMA) — Idempoten (INSERT IGNORE)
INSERT IGNORE INTO kinerja_tahfidz_targets (unit_id, paralel_id, paralel_nama, semester, target_type, target_value, target_label, require_munaqasyah) VALUES
-- Unit 1: TK
(1, 2, 'PLAYGROUP',       1, 'juz30_rank', 1.00,  'QS. Al-Fatihah – An-Nas', 0),
(1, 2, 'PLAYGROUP',       2, 'juz30_rank', 3.00,  'QS. An-Nas – Al-Ikhlas', 0),
(1, 3, 'KINDERGARTEN A',  1, 'juz30_rank', 4.00,  'QS. An-Nas – Al-Lahab', 0),
(1, 3, 'KINDERGARTEN A',  2, 'juz30_rank', 9.00,  'QS. An-Nas – Quraisy', 0),
(1, 4, 'KINDERGARTEN B',  1, 'juz30_rank', 13.00, 'QS. An-Nas – At-Takatsur', 0),
(1, 4, 'KINDERGARTEN B',  2, 'juz30_rank', 17.00, 'QS. An-Nas – Al-Bayyinah', 0),
-- Unit 2: SD
(2, 5, 'Kelas 1',         1, 'juz30_rank', 13.00, 'QS. An-Nas – At-Takatsur', 0),
(2, 5, 'Kelas 1',         2, 'juz30_rank', 17.00, 'QS. An-Nas – Al-Bayyinah', 0),
(2, 6, 'Kelas 2',         1, 'juz30_rank', 22.00, 'QS. An-Nas – Adh-Dhuha', 0),
(2, 6, 'Kelas 2',         2, 'juz30_rank', 25.00, 'QS. An-Nas – Al-Balad', 0),
(2, 7, 'Kelas 3',         1, 'juz30_rank', 28.00, 'QS. An-Nas – Al-A''laa', 0),
(2, 7, 'Kelas 3',         2, 'juz30_rank', 31.00, 'QS. An-Nas – Al-Insyiqaq', 0),
(2, 8, 'Kelas 4',         1, 'juz30_rank', 33.00, 'QS. An-Nas – Al-Infithar', 0),
(2, 8, 'Kelas 4',         2, 'juz30_rank', 35.00, 'QS. An-Nas – ''Abasa', 0),
(2, 9, 'Kelas 5',         1, 'juz30_rank', 37.00, 'QS. An-Nas – An-Naba'' (Juz 30)', 0),
(2, 9, 'Kelas 5',         2, 'juz30_rank', 37.00, 'Tuntas Juz 30 / Munaqasyah Juz 30', 0),
(2, 10, 'Kelas 6',        1, 'juz29_rank', 43.00, 'QS. Al-Mulk – Al-Jin (Juz 29 Bag. 1)', 0),
(2, 10, 'Kelas 6',        2, 'juz29_rank', 48.00, 'QS. Al-Mulk – Al-Mursalat (Tuntas Juz 29)', 0),
-- Unit 3: SMP
(3, 11, 'Kelas 7',        1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(3, 11, 'Kelas 7',        2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(3, 12, 'Kelas 8',        1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(3, 12, 'Kelas 8',        2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(3, 13, 'Kelas 9',        1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(3, 13, 'Kelas 9',        2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
-- Unit 4: SMA
(4, 14, 'Kelas 10',       1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(4, 14, 'Kelas 10',       2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(4, 15, 'Kelas 11',       1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(4, 15, 'Kelas 11',       2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(4, 16, 'Kelas 12',       1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0),
(4, 16, 'Kelas 12',       2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS', 0);

-- Upgrade legacy seed Kelas 6 (juz29_coverage -> juz29_rank resmi Al-Mulk s.d. Al-Mursalat)
UPDATE kinerja_tahfidz_targets
SET target_type  = 'juz29_rank',
    target_value = 43.00,
    target_label = 'QS. Al-Mulk – Al-Jin (Juz 29 Bag. 1)'
WHERE unit_id = 2 AND paralel_id = 10 AND semester = 1 AND target_type = 'juz29_coverage';

UPDATE kinerja_tahfidz_targets
SET target_type  = 'juz29_rank',
    target_value = 48.00,
    target_label = 'QS. Al-Mulk – Al-Mursalat (Tuntas Juz 29)'
WHERE unit_id = 2 AND paralel_id = 10 AND semester = 2 AND target_type = 'juz29_coverage';
