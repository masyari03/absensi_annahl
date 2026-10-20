<?php
require_once __DIR__ . '/../config/database.php';

echo "Running Migration V2 on " . $pdo->getAttribute(PDO::ATTR_SERVER_INFO) . "...\n";

// Helper check column
function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.columns 
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

try {
    // 1. mentoring_curriculums: tasks_json
    if (!columnExists($pdo, 'mentoring_curriculums', 'tasks_json')) {
        $pdo->exec("ALTER TABLE mentoring_curriculums ADD COLUMN tasks_json JSON NULL COMMENT 'Daftar butir penugasan dinamis' AFTER file_link");
        echo "Added column mentoring_curriculums.tasks_json\n";
    } else {
        echo "Column mentoring_curriculums.tasks_json already exists\n";
    }

    // 2. mentoring_curriculums: active_days
    if (!columnExists($pdo, 'mentoring_curriculums', 'active_days')) {
        $pdo->exec("ALTER TABLE mentoring_curriculums ADD COLUMN active_days JSON NULL COMMENT 'Array hari aktif pekanan' AFTER tasks_json");
        echo "Added column mentoring_curriculums.active_days\n";
    } else {
        echo "Column mentoring_curriculums.active_days already exists\n";
    }

    // 3. mentoring_curriculums: reflection_prompts_json
    if (!columnExists($pdo, 'mentoring_curriculums', 'reflection_prompts_json')) {
        $pdo->exec("ALTER TABLE mentoring_curriculums ADD COLUMN reflection_prompts_json JSON NULL COMMENT 'Daftar pertanyaan refleksi dinamis' AFTER active_days");
        echo "Added column mentoring_curriculums.reflection_prompts_json\n";
    } else {
        echo "Column mentoring_curriculums.reflection_prompts_json already exists\n";
    }

    // 4. mentoring_sessions: score_adab modify to DECIMAL(5,2)
    $pdo->exec("ALTER TABLE mentoring_sessions MODIFY COLUMN score_adab DECIMAL(5,2) NULL COMMENT 'Total Nilai Akhir Pilar 4: (Nilai A + Nilai B)/2'");
    echo "Modified column mentoring_sessions.score_adab to DECIMAL(5,2)\n";

    // 5. mentoring_sessions: score_adab_checklist
    if (!columnExists($pdo, 'mentoring_sessions', 'score_adab_checklist')) {
        $pdo->exec("ALTER TABLE mentoring_sessions ADD COLUMN score_adab_checklist DECIMAL(5,2) NULL COMMENT 'Nilai A: Capaian Penugasan Ceklis (0-100)' AFTER notes_mutabaah");
        echo "Added column mentoring_sessions.score_adab_checklist\n";
    } else {
        echo "Column mentoring_sessions.score_adab_checklist already exists\n";
    }

    // 6. mentoring_sessions: score_adab_mentor
    if (!columnExists($pdo, 'mentoring_sessions', 'score_adab_mentor')) {
        $pdo->exec("ALTER TABLE mentoring_sessions ADD COLUMN score_adab_mentor DECIMAL(5,2) NULL COMMENT 'Nilai B: Skor Evaluasi Refleksi Guru (0-100)' AFTER score_adab_checklist");
        echo "Added column mentoring_sessions.score_adab_mentor\n";
    } else {
        echo "Column mentoring_sessions.score_adab_mentor already exists\n";
    }

    // 7. mentoring_sessions: week_code
    if (!columnExists($pdo, 'mentoring_sessions', 'week_code')) {
        $pdo->exec("ALTER TABLE mentoring_sessions ADD COLUMN week_code VARCHAR(50) NULL COMMENT 'Kode Pekan (Pekan 1, Pekan 2, dst)' AFTER session_code");
        echo "Added column mentoring_sessions.week_code\n";
    } else {
        echo "Column mentoring_sessions.week_code already exists\n";
    }

    // 7b. student_adab_logs: reflections_json
    if (!columnExists($pdo, 'student_adab_logs', 'reflections_json')) {
        $pdo->exec("ALTER TABLE student_adab_logs ADD COLUMN reflections_json JSON NULL COMMENT 'Array jawaban refleksi' AFTER checklist_json");
        echo "Added column student_adab_logs.reflections_json\n";
    } else {
        echo "Column student_adab_logs.reflections_json already exists\n";
    }

    // 8. system_settings: mentoring_default_active_days
    $stmtCheckSetting = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'mentoring_default_active_days'");
    $stmtCheckSetting->execute();
    if ($stmtCheckSetting->fetchColumn() == 0) {
        $stmtInsSetting = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES ('mentoring_default_active_days', '[\"Rabu\",\"Kamis\",\"Jumat\"]')
        ");
        $stmtInsSetting->execute();
        echo "Seeded setting mentoring_default_active_days\n";
    } else {
        echo "Setting mentoring_default_active_days already exists\n";
    }

    echo "=== MIGRATION V2 COMPLETED SUCCESSFULLY ===\n";
} catch (Exception $e) {
    echo "MIGRATION ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
