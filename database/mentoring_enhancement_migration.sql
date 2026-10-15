-- Migrasi Peningkatan Modul Mentoring Siswa (MIP) & Kurikulum Adab
-- Menambahkan pengikat Tahun Pelajaran, Kelompok/Halaqah, Semester, dan Bulan

-- 1. Tabel mentoring_assignments: tambahkan group_name dan pastikan academic_year_id terindeks
ALTER TABLE mentoring_assignments
    ADD COLUMN IF NOT EXISTS group_name VARCHAR(100) NULL AFTER mentor_user_id,
    ADD COLUMN IF NOT EXISTS semester ENUM('ganjil', 'genap') DEFAULT 'ganjil' AFTER academic_year_id,
    ADD INDEX IF NOT EXISTS idx_mentoring_academic_year (academic_year_id),
    ADD INDEX IF NOT EXISTS idx_mentoring_mentor (mentor_user_id),
    ADD INDEX IF NOT EXISTS idx_mentoring_student (student_id);

-- 2. Update default academic_year_id yang masih NULL ke tahun ajaran aktif saat ini
UPDATE mentoring_assignments 
SET academic_year_id = (SELECT id FROM academic_years WHERE status = 'active' LIMIT 1)
WHERE academic_year_id IS NULL;

-- 3. Tabel mentoring_curriculums: tambahkan academic_year_id, semester, month_number
ALTER TABLE mentoring_curriculums
    ADD COLUMN IF NOT EXISTS academic_year_id BIGINT UNSIGNED NULL AFTER grade_level,
    ADD COLUMN IF NOT EXISTS semester ENUM('ganjil', 'genap') DEFAULT 'ganjil' AFTER academic_year_id,
    ADD COLUMN IF NOT EXISTS month_number TINYINT UNSIGNED NULL COMMENT '1-12 (Bulan Kalender Masehi)' AFTER semester,
    ADD INDEX IF NOT EXISTS idx_curr_academic_year (academic_year_id),
    ADD INDEX IF NOT EXISTS idx_curr_grade_month_week (grade_level, month_number, week_code);

-- 4. Update data kurikulum eksisting agar terikat ke tahun ajaran aktif
UPDATE mentoring_curriculums
SET academic_year_id = (SELECT id FROM academic_years WHERE status = 'active' LIMIT 1)
WHERE academic_year_id IS NULL;
