-- ============================================================================
-- MIGRATION: SISTEM SUPERVISI GURU FLEKSIBEL & SIDEBAR PARENT-CHILD HIERARCHY
-- Database: library_v4
-- ============================================================================

-- 1. TABEL: supervision_types (Jenis Supervisi)
CREATE TABLE IF NOT EXISTS `supervision_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type_key` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. TABEL: supervision_categories (Aspek / Komponen Penilaian Fleksibel)
CREATE TABLE IF NOT EXISTS `supervision_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type_id` INT UNSIGNED NOT NULL,
    `category_name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sc_type` (`type_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. TABEL: supervision_rubric_items (Butir Indikator Dinamis / Fleksibel)
CREATE TABLE IF NOT EXISTS `supervision_rubric_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(50) NULL,
    `aspek_label` VARCHAR(100) NULL,
    `indicator_text` TEXT NOT NULL,
    `min_score` INT NOT NULL DEFAULT 1,
    `max_score` INT NOT NULL DEFAULT 4,
    `weight` DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sri_category` (`category_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. TABEL: supervision_predicates (Rentang & Ambang Batas Predikat Fleksibel)
CREATE TABLE IF NOT EXISTS `supervision_predicates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type_id` INT UNSIGNED NULL,
    `predicate_name` VARCHAR(50) NOT NULL,
    `min_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `badge_color` VARCHAR(30) NOT NULL DEFAULT '#6366f1',
    `description` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    INDEX `idx_sp_type` (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. TABEL: supervision_schedules (Jadwal Supervisi Sekolah)
CREATE TABLE IF NOT EXISTS `supervision_schedules` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type_id` INT UNSIGNED NOT NULL,
    `teacher_id` BIGINT UNSIGNED NOT NULL,
    `observer_id` BIGINT UNSIGNED NOT NULL,
    `subject_name` VARCHAR(120) NOT NULL,
    `class_name` VARCHAR(60) NOT NULL,
    `scheduled_date` DATE NOT NULL,
    `time_start` TIME NULL,
    `time_end` TIME NULL,
    `room_location` VARCHAR(120) NULL,
    `status` ENUM('scheduled', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ss_date` (`scheduled_date`),
    INDEX `idx_ss_teacher` (`teacher_id`),
    INDEX `idx_ss_observer` (`observer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. TABEL: supervision_sessions (Dokumen Evaluasi Supervisi Tersimpan)
CREATE TABLE IF NOT EXISTS `supervision_sessions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `schedule_id` BIGINT UNSIGNED NULL,
    `type_id` INT UNSIGNED NOT NULL,
    `teacher_id` BIGINT UNSIGNED NOT NULL,
    `observer_id` BIGINT UNSIGNED NOT NULL,
    `subject_name` VARCHAR(120) NOT NULL,
    `class_name` VARCHAR(60) NOT NULL,
    `observation_date` DATE NOT NULL,
    `room_location` VARCHAR(120) NULL,
    `total_score` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `max_possible_score` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `percentage_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `predicate_name` VARCHAR(60) NOT NULL DEFAULT 'Need Improvement',
    `notes_good_practice` TEXT NULL,
    `notes_growth_plan` TEXT NULL,
    `notes_teacher_reflection` TEXT NULL,
    `signed_teacher_name` VARCHAR(150) NULL,
    `signed_observer_name` VARCHAR(150) NULL,
    `signed_principal_name` VARCHAR(150) DEFAULT 'Awang Darmawan, SS',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sess_teacher` (`teacher_id`),
    INDEX `idx_sess_observer` (`observer_id`),
    INDEX `idx_sess_date` (`observation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. TABEL: supervision_scores (Rincian Skor Per Butir Indikator)
CREATE TABLE IF NOT EXISTS `supervision_scores` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `session_id` BIGINT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    INDEX `idx_ssc_sess` (`session_id`),
    INDEX `idx_ssc_item` (`item_id`),
    CONSTRAINT `fk_ssc_session` FOREIGN KEY (`session_id`) REFERENCES `supervision_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SEED DATA AWAL: JENIS, ASPEK, BUTIR INDIKATOR & PREDIKAT
-- ============================================================================

-- Jenis Supervisi
INSERT INTO `supervision_types` (`id`, `type_key`, `name`, `description`, `sort_order`, `is_active`)
VALUES 
(1, 'peer', 'Supervisi Teman Sejawat', 'Observasi rekan sejawat berbasis pembelajaran mendalam & integrasi KURMAA', 1, 1),
(2, 'principal', 'Supervisi Kepala Sekolah', 'Evaluasi manajerial & kepemimpinan pembelajaran kelas oleh Kepala Sekolah', 2, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- Kategori Supervisi Sejawat
INSERT INTO `supervision_categories` (`id`, `type_id`, `category_name`, `description`, `sort_order`, `is_active`)
VALUES
(1, 1, '1. Pembelajaran Mendalam', 'Mindful, Meaningful, Joyful, Kritis, Kreatif, Kolaborasi, Refleksi', 1, 1),
(2, 1, '2. Integrasi KURMAA', 'Keimanan, Al-Qur\'an, Adab, Ilmu & Amal, Karakter Qur\'ani', 2, 1),
(3, 1, '3. Keterlibatan dan Perkembangan Siswa', 'Student Agency, Diferensiasi, Asesmen Formatif, Pertumbuhan Siswa', 3, 1),
-- Kategori Supervisi Kepsek
(4, 2, '1. Perencanaan Pembelajaran & Perangkat Ajar', 'Modul ajar, integrasi KURMAA, diferensiasi, asesmen awal, media TPACK', 1, 1),
(5, 2, '2. Pelaksanaan Pembelajaran Mendalam & Kepemimpinan Kelas', 'Keterlaksanaan runtut, MMJ, adab islami, student agency, HOTS, manajemen kelas, refleksi', 2, 1),
(6, 2, '3. Asesmen & Evaluasi Hasil Belajar', 'Rubrik KKTP, jurnal observasi adab, remedial/pengayaan, portofolio, laporan perkembangan', 3, 1),
(7, 2, '4. Komitmen & Pengembangan Profesionalitas Guru', 'Kedisiplinan, keaktifan komunitas belajar, pengembangan mandiri, keterbukaan supervisi', 4, 1)
ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`), `description`=VALUES(`description`);

-- Butir Indikator Supervisi Sejawat (22 Butir)
INSERT INTO `supervision_rubric_items` (`category_id`, `code`, `aspek_label`, `indicator_text`, `min_score`, `max_score`, `weight`, `sort_order`, `is_active`)
VALUES
-- Kategori 1 (Pembelajaran Mendalam)
(1, 'pm1', 'Berkesadaran (Mindful)', 'Guru membangun kesiapan belajar, perhatian, dan kesadaran siswa terhadap tujuan pembelajaran.', 1, 4, 1.00, 1, 1),
(1, 'pm2', 'Berkesadaran (Mindful)', 'Siswa memahami mengapa mereka mempelajari materi tersebut dan kaitannya dengan kehidupan.', 1, 4, 1.00, 2, 1),
(1, 'pm3', 'Bermakna (Meaningful)', 'Materi dikaitkan dengan pengalaman, kehidupan nyata, lingkungan, atau permasalahan yang dekat dengan siswa.', 1, 4, 1.00, 3, 1),
(1, 'pm4', 'Bermakna (Meaningful)', 'Siswa mampu menghubungkan pengetahuan baru dengan pengetahuan/pengalaman sebelumnya.', 1, 4, 1.00, 4, 1),
(1, 'pm5', 'Menggembirakan (Joyful)', 'Guru menciptakan suasana belajar yang positif, aman, aktif, dan menyenangkan.', 1, 4, 1.00, 5, 1),
(1, 'pm6', 'Menggembirakan (Joyful)', 'Siswa terlihat antusias, terlibat, berani bertanya, berdiskusi, mencoba, atau menyampaikan pendapat.', 1, 4, 1.00, 6, 1),
(1, 'pm7', 'Berpikir Kritis', 'Guru memberikan pertanyaan/tugas yang mendorong siswa menganalisis, memberikan alasan, memecahkan masalah, atau mengambil keputusan.', 1, 4, 1.00, 7, 1),
(1, 'pm8', 'Kreativitas', 'Siswa diberi kesempatan menghasilkan ide, solusi, karya, atau cara penyelesaian yang beragam.', 1, 4, 1.00, 8, 1),
(1, 'pm9', 'Kolaborasi & Komunikasi', 'Siswa bekerja sama, berdiskusi, mendengarkan, dan menyampaikan gagasan dengan baik.', 1, 4, 1.00, 9, 1),
(1, 'pm10', 'Refleksi', 'Guru memberikan kesempatan kepada siswa untuk merefleksikan proses dan hasil belajarnya.', 1, 4, 1.00, 10, 1),

-- Kategori 2 (Integrasi KURMAA)
(2, 'kr1', 'Iman', 'Guru mengaitkan pembelajaran dengan kesadaran kepada Allah SWT sesuai konteks materi secara alami dan tidak dipaksakan.', 1, 4, 1.00, 11, 1),
(2, 'kr2', 'Iman', 'Pembelajaran menumbuhkan rasa syukur, tanggung jawab, kejujuran, amanah, atau nilai keimanan lainnya.', 1, 4, 1.00, 12, 1),
(2, 'kr3', 'Al-Qur\'an', 'Guru menghubungkan materi dengan ayat Al-Qur\'an/hadits atau nilai Qur\'ani yang relevan dengan materi.', 1, 4, 1.00, 13, 1),
(2, 'kr4', 'Al-Qur\'an', 'Siswa diarahkan untuk melihat ilmu sebagai bagian dari upaya memahami tanda-tanda kebesaran Allah dan memberikan kemaslahatan.', 1, 4, 1.00, 14, 1),
(2, 'kr5', 'Adab', 'Guru menjadi teladan dalam sikap, bahasa, kedisiplinan, kesantunan, dan penghormatan kepada siswa.', 1, 4, 1.00, 15, 1),
(2, 'kr6', 'Adab', 'Siswa menunjukkan adab dalam belajar: menghormati guru, teman, ilmu, lingkungan, dan menjaga amanah.', 1, 4, 1.00, 16, 1),
(2, 'kr7', 'Ilmu & Amal', 'Guru membantu siswa memahami bagaimana pengetahuan yang dipelajari dapat diterapkan dalam kehidupan nyata.', 1, 4, 1.00, 17, 1),
(2, 'kr8', 'Karakter Qur\'ani', 'Pembelajaran mendorong tumbuhnya karakter seperti jujur, disiplin, bertanggung jawab, peduli, sabar, mandiri, dan amanah.', 1, 4, 1.00, 18, 1),

-- Kategori 3 (Keterlibatan Siswa)
(3, 'ks1', 'Student Agency', 'Siswa diberi ruang untuk bertanya, memilih, berpendapat, mencoba, atau mengambil tanggung jawab dalam pembelajaran.', 1, 4, 1.00, 19, 1),
(3, 'ks2', 'Diferensiasi', 'Guru memperhatikan perbedaan kesiapan, kebutuhan, kemampuan, atau karakter belajar siswa.', 1, 4, 1.00, 20, 1),
(3, 'ks3', 'Asesmen Formatif', 'Guru melakukan pengecekan pemahaman selama proses pembelajaran dan memberikan umpan balik.', 1, 4, 1.00, 21, 1),
(3, 'ks4', 'Pertumbuhan Siswa', 'Guru tidak hanya berfokus pada hasil akademik, tetapi juga perkembangan sikap, keterampilan, dan karakter siswa.', 1, 4, 1.00, 22, 1),

-- Kategori 4 (Perencanaan Kepsek)
(4, 'kp1', 'Kelengkapan Modul Ajar', 'Guru menyusun Modul Ajar / RPP yang lengkap sesuai capaian pembelajaran dan alur tujuan pembelajaran.', 1, 4, 1.00, 1, 1),
(4, 'kp2', 'Integrasi KURMAA', 'Perencanaan memuat integrasi nilai keimanan, Al-Qur\'an, dan pembiasaan adab secara jelas.', 1, 4, 1.00, 2, 1),
(4, 'kp3', 'Desain Diferensiasi', 'Modul memuat pemetaan kebutuhan siswa (gaya belajar, kesiapan, variasi produk/proses).', 1, 4, 1.00, 3, 1),
(4, 'kp4', 'Rencana Asesmen Formatif', 'Tersedia instrumen asesmen awal dan formatif untuk memantau perkembangan belajar siswa.', 1, 4, 1.00, 4, 1),
(4, 'kp5', 'Kesesuaian Media & TPACK', 'Penggunaan media pembelajaran dan teknologi yang mendukung pembelajaran interaktif.', 1, 4, 1.00, 5, 1),

-- Kategori 5 (Pelaksanaan Kepsek)
(5, 'kl1', 'Keterlaksanaan Pembelajaran', 'Kegiatan pembelajaran terlaksana runtut sesuai modul ajar dari pembukaan hingga penutup.', 1, 4, 1.00, 6, 1),
(5, 'kl2', 'Pembelajaran Mendalam', 'Menciptakan pengalaman belajar Mindful, Meaningful, dan Joyful yang relevan.', 1, 4, 1.00, 7, 1),
(5, 'kl3', 'Penguatan Adab & Karakter', 'Guru secara konsisten menegakkan adab islami, bahasa santun, dan keteladanan positif.', 1, 4, 1.00, 8, 1),
(5, 'kl4', 'Keterlibatan Siswa (Agency)', 'Siswa aktif bertanya, berdiskusi, dan mengambil peran kepemimpinan dalam kelompok.', 1, 4, 1.00, 9, 1),
(5, 'kl5', 'Keterampilan Berpikir Kritis', 'Guru memfasilitasi pertanyaan HOTS dan pemecahan masalah konteks kehidupan nyata.', 1, 4, 1.00, 10, 1),
(5, 'kl6', 'Pemanfaatan Lingkungan', 'Memanfaatkan lingkungan sekolah / media digital sebagai sumber belajar efisien.', 1, 4, 1.00, 11, 1),
(5, 'kl7', 'Manajemen Waktu & Kelas', 'Pengelolaan waktu dan pengondisian kelas kondusif, tertib, dan inklusif.', 1, 4, 1.00, 12, 1),
(5, 'kl8', 'Umpan Balik Instan', 'Guru memberikan umpan balik langsung (afirmatif dan korektif) selama proses belajar.', 1, 4, 1.00, 13, 1),
(5, 'kl9', 'Integrasi Ayat / Hadits', 'Guru mengaitkan materi secara bermakna dengan ayat Al-Qur\'an/Hadits yang relevan.', 1, 4, 1.00, 14, 1),
(5, 'kl10', 'Refleksi Pembelajaran', 'Guru mengajak siswa menyimpulkan materi dan merefleksikan hikmah pembelajaran.', 1, 4, 1.00, 15, 1),

-- Kategori 6 (Asesmen Kepsek)
(6, 'ka1', 'Rubrik Penilaian Jelas', 'Asesmen dilengkapi rubrik kriteria ketercapaian tujuan pembelajaran (KKTP) yang terukur.', 1, 4, 1.00, 16, 1),
(6, 'ka2', 'Penilaian Sikap & Adab', 'Melakukan jurnal observasi adab dan perkembangan karakter siswa secara berkala.', 1, 4, 1.00, 17, 1),
(6, 'ka3', 'Tindak Lanjut Asesmen', 'Memanfaatkan hasil asesmen untuk program remedial dan pengayaan siswa.', 1, 4, 1.00, 18, 1),
(6, 'ka4', 'Dokumentasi Portofolio', 'Menyimpan dan mendokumentasikan hasil karya / portofolio siswa secara rapi.', 1, 4, 1.00, 19, 1),
(6, 'ka5', 'Laporan Perkembangan', 'Menyampaikan umpan balik perkembangan siswa kepada orang tua / wali secara konstruktif.', 1, 4, 1.00, 20, 1),

-- Kategori 7 (Pengembangan Diri Kepsek)
(7, 'kd1', 'Kedisiplinan & Kehadiran', 'Guru hadir tepat waktu, disiplin administrasi, dan menunjukkan etos kerja islami.', 1, 4, 1.00, 21, 1),
(7, 'kd2', 'Keaktifan Komunitas Belajar', 'Aktif dalam MGMP / KKG internal sekolah dan berbagi praktik baik dengan sesama guru.', 1, 4, 1.00, 22, 1),
(7, 'kd3', 'Pengembangan Diri', 'Mengikuti pelatihan / web seminar / literasi untuk meningkatkan kompetensi secara mandiri.', 1, 4, 1.00, 23, 1),
(7, 'kd4', 'Keterbukaan Supervisi', 'Respektif terhadap masukan supervisi dan berkomitmen menjalankan Teacher Growth Plan.', 1, 4, 1.00, 24, 1)
ON DUPLICATE KEY UPDATE `indicator_text`=VALUES(`indicator_text`);

-- Predikat Supervisi
INSERT INTO `supervision_predicates` (`type_id`, `predicate_name`, `min_percentage`, `badge_color`, `description`, `sort_order`)
VALUES
(NULL, 'Outstanding', 90.00, '#8b5cf6', 'Capaian Unggul / Model Pembelajaran Percontohan', 1),
(NULL, 'Excellent', 80.00, '#10b981', 'Sangat Baik & Konsisten dalam Seluruh Indikator', 2),
(NULL, 'Good', 70.00, '#0ea5e9', 'Baik, Terus Ditingkatkan pada Aspek Tertentu', 3),
(NULL, 'Need Improvement', 0.00, '#ef4444', 'Perlu Pendampingan Khusus & Supervisi Ulang', 4)
ON DUPLICATE KEY UPDATE `min_percentage`=VALUES(`min_percentage`), `badge_color`=VALUES(`badge_color`);

-- ============================================================================
-- PEMBARUAN & SEEDING HIERARKI MENU (app_menus)
-- ============================================================================

-- 1. Buat Parent Menu 'Pembinaan' jika belum ada
INSERT INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `parent_id`, `sort_order`, `is_active`)
VALUES ('pembinaan_parent', 'Pembinaan', 'PEMBINAAN', '#', 'fa-hands-holding-child', NULL, 17, 1)
ON DUPLICATE KEY UPDATE `menu_name`='Pembinaan', `category`='PEMBINAAN', `menu_icon`='fa-hands-holding-child';

-- Ambil ID dari pembinaan_parent dan link child-nya
SET @pembinaan_id = (SELECT id FROM `app_menus` WHERE `menu_key` = 'pembinaan_parent' LIMIT 1);

-- Pindahkan kurikulum adab ke kategori PEMBINAAN dan link ke parent
UPDATE `app_menus`
SET `category` = 'PEMBINAAN', `parent_id` = @pembinaan_id, `sort_order` = 19
WHERE `menu_key` = 'mentoring_curriculum';

-- Hubungkan child pembinaan lainnya
UPDATE `app_menus`
SET `parent_id` = @pembinaan_id, `sort_order` = 17
WHERE `menu_key` = 'mentoring_dashboard';

UPDATE `app_menus`
SET `parent_id` = @pembinaan_id, `sort_order` = 18
WHERE `menu_key` = 'mentoring_report';

UPDATE `app_menus`
SET `parent_id` = @pembinaan_id, `sort_order` = 20
WHERE `menu_key` = 'mentoring_adab';

UPDATE `app_menus`
SET `parent_id` = @pembinaan_id, `sort_order` = 21
WHERE `menu_key` = 'mentoring_assignments';

-- 2. Buat Parent Menu 'Supervisi Guru'
INSERT INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `parent_id`, `sort_order`, `is_active`)
VALUES ('supervisi_parent', 'Supervisi Guru', 'SUPERVISI', '#', 'fa-chalkboard-user', NULL, 22, 1)
ON DUPLICATE KEY UPDATE `menu_name`='Supervisi Guru', `category`='SUPERVISI', `menu_icon`='fa-chalkboard-user';

SET @supervisi_id = (SELECT id FROM `app_menus` WHERE `menu_key` = 'supervisi_parent' LIMIT 1);

-- 3. Buat Child Menus di bawah 'Supervisi Guru'
INSERT INTO `app_menus` (`menu_key`, `menu_name`, `category`, `menu_url`, `menu_icon`, `parent_id`, `sort_order`, `is_active`)
VALUES
('supervisi_dashboard', 'Dashboard Supervisi', 'SUPERVISI', 'supervisi/index.php', 'fa-chart-line', @supervisi_id, 23, 1),
('supervisi_sejawat', 'Supervisi Sejawat', 'SUPERVISI', 'supervisi/peer.php', 'fa-user-pen', @supervisi_id, 24, 1),
('supervisi_kepsek', 'Supervisi Kepsek', 'SUPERVISI', 'supervisi/principal.php', 'fa-school', @supervisi_id, 25, 1),
('supervisi_jadwal', 'Jadwal Supervisi', 'SUPERVISI', 'supervisi/schedules.php', 'fa-calendar-days', @supervisi_id, 26, 1),
('supervisi_rekap', 'Rekapitulasi Data', 'SUPERVISI', 'supervisi/reports.php', 'fa-table-list', @supervisi_id, 27, 1),
('supervisi_rubrik', 'Kelola Rubrik & Skor', 'SUPERVISI', 'supervisi/rubrics.php', 'fa-sliders', @supervisi_id, 28, 1)
ON DUPLICATE KEY UPDATE 
    `menu_name`=VALUES(`menu_name`),
    `category`=VALUES(`category`),
    `menu_url`=VALUES(`menu_url`),
    `menu_icon`=VALUES(`menu_icon`),
    `parent_id`=@supervisi_id;

-- 4. Berikan Hak Akses ke Super Admin & Kepala Sekolah
-- Untuk Kepala Sekolah (role_id = 2):
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT 2, id FROM `app_menus` WHERE `category` = 'SUPERVISI';

-- Untuk Staff / Guru (role_id = 4): Berikan akses ke Dashboard, Supervisi Sejawat, dan Jadwal
INSERT IGNORE INTO `role_menu_access` (`role_id`, `menu_id`)
SELECT 4, id FROM `app_menus` WHERE `menu_key` IN ('supervisi_dashboard', 'supervisi_sejawat', 'supervisi_jadwal');
