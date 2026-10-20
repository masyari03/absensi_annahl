-- Migrasi Modul Mentoring Siswa & Kurikulum Adab V2
-- Menambahkan kolom dinamis tasks_json, active_days, reflection_prompts_json, dan pemisahan skor adab

-- 1. Penambahan kolom dinamis pada mentoring_curriculums
ALTER TABLE mentoring_curriculums
    ADD COLUMN IF NOT EXISTS tasks_json JSON NULL COMMENT 'Daftar butir penugasan dinamis' AFTER file_link,
    ADD COLUMN IF NOT EXISTS active_days JSON NULL COMMENT 'Array hari aktif pekanan misal ["Rabu","Kamis","Jumat"]' AFTER tasks_json,
    ADD COLUMN IF NOT EXISTS reflection_prompts_json JSON NULL COMMENT 'Daftar pertanyaan refleksi dinamis' AFTER active_days;

-- 2. Penyesuaian presisi nilai dan pemisahan skor pada mentoring_sessions
ALTER TABLE mentoring_sessions
    MODIFY COLUMN score_adab DECIMAL(5,2) NULL COMMENT 'Total Nilai Akhir Pilar 4: (Nilai A + Nilai B)/2',
    ADD COLUMN IF NOT EXISTS score_adab_checklist DECIMAL(5,2) NULL COMMENT 'Nilai A: Capaian Penugasan Ceklis (0-100)' AFTER notes_mutabaah,
    ADD COLUMN IF NOT EXISTS score_adab_mentor DECIMAL(5,2) NULL COMMENT 'Nilai B: Skor Evaluasi Refleksi Guru (0-100)' AFTER score_adab_checklist,
    ADD COLUMN IF NOT EXISTS week_code VARCHAR(50) NULL COMMENT 'Kode Pekan (Pekan 1, Pekan 2, dst)' AFTER session_code;

-- 2b. Tabel student_adab_logs: simpan seluruh jawaban refleksi dalam JSON
ALTER TABLE student_adab_logs
    ADD COLUMN IF NOT EXISTS reflections_json JSON NULL COMMENT 'Array jawaban refleksi dinamis' AFTER checklist_json;

-- 3. Setting default sekolah untuk hari aktif mentoring adab
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('mentoring_default_active_days', '["Rabu","Kamis","Jumat"]')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
