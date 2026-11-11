-- =========================================================================
-- Migrasi Cache Semantik AI & Koreksi Manual Capaian Tahfidz
-- Database target: library_v4
-- Tanggal: 2026-11-11
-- =========================================================================

CREATE TABLE IF NOT EXISTS kinerja_tahfidz_ai_cache (
    id INT NOT NULL AUTO_INCREMENT,
    note_hash CHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    source_type ENUM('quran_nilai','tes_ummi','rapor','jurnal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quran_nilai',
    kd_nama VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    materi VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    raw_text TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
    is_achievement TINYINT(1) NOT NULL DEFAULT 0,
    juz TINYINT DEFAULT NULL,
    surah_key VARCHAR(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    ayat_end SMALLINT DEFAULT NULL,
    juz30_rank DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    juz29_rank DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    juz28_rank DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    juz_stage SMALLINT NOT NULL DEFAULT 0,
    is_munaqasyah TINYINT(1) NOT NULL DEFAULT 0,
    confidence DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    ai_reason VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    ai_provider VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    ai_model VARCHAR(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    parsed_json LONGTEXT COLLATE utf8mb4_unicode_ci NULL,
    review_status ENUM('auto_ai','verified','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto_ai',
    reviewed_by INT DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_note_hash (note_hash),
    KEY idx_review_status (review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kinerja_tahfidz_student_overrides (
    id INT NOT NULL AUTO_INCREMENT,
    unit_id INT NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    siswa_id INT NOT NULL,
    juz30_rank DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    juz29_rank DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    juz28_rank DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    juz_stage SMALLINT NOT NULL DEFAULT 0,
    has_munaqasyah_juz30 TINYINT(1) NOT NULL DEFAULT 0,
    has_munaqasyah_juz29 TINYINT(1) NOT NULL DEFAULT 0,
    has_munaqasyah_juz28 TINYINT(1) NOT NULL DEFAULT 0,
    display_label VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL,
    reason VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unit_ta_siswa (unit_id, tahun_ajaran_id, siswa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Registrasi Sub-menu Analisis AI Tahfidz (ID 116)
INSERT INTO app_menus (id, menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active)
VALUES (116, 'kinerja_tahfidz_ai', 'Analisis AI Tahfidz', 'PENILAIAN KINERJA', 'kinerja/tahfidz_ai.php', 'fa-robot', 112, 4, 1)
ON DUPLICATE KEY UPDATE
    menu_name  = VALUES(menu_name),
    category   = VALUES(category),
    menu_url   = VALUES(menu_url),
    menu_icon  = VALUES(menu_icon),
    parent_id  = VALUES(parent_id),
    sort_order = VALUES(sort_order),
    is_active  = 1;

INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
SELECT id, 116, 1, 1, 1, 1 FROM roles WHERE role_key = 'super_admin'
ON DUPLICATE KEY UPDATE can_read = 1, can_create = 1, can_edit = 1, can_delete = 1;

INSERT IGNORE INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
SELECT id, 116, 0, 0, 0, 0 FROM roles WHERE role_key <> 'super_admin';


