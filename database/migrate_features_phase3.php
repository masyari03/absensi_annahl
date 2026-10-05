<?php
require_once __DIR__ . '/../config/database.php';

echo "=== RUNNING DATABASE MIGRATIONS PHASE 3 ===\n\n";

// 1. Create weekly_schedule_staff table
echo "1. Creating weekly_schedule_staff table...\n";
$pdo->exec("
    CREATE TABLE IF NOT EXISTS weekly_schedule_staff (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        weekly_schedule_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_schedule_staff (weekly_schedule_id, staff_id),
        INDEX idx_staff_id (staff_id),
        INDEX idx_ws_id (weekly_schedule_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
echo "  ✓ weekly_schedule_staff table ready.\n";

// 2. Ensure Shift 1 (Siang: 06:00 - 18:00) exists for Security (Unit 6)
echo "\n2. Seeding Shift 1 Siang for Security (Unit 6)...\n";
$days = [1=>'Senin', 2=>'Selasa', 3=>'Rabu', 4=>'Kamis', 5=>'Jumat', 6=>'Sabtu', 7=>'Minggu'];
$stmtCheckShift1 = $pdo->prepare("SELECT id FROM weekly_schedules WHERE unit_id = 6 AND day_code = ? AND is_overnight = 0 AND target_type = 'staff'");
$stmtInsertShift1 = $pdo->prepare("
    INSERT INTO weekly_schedules 
    (unit_id, target_type, grade_id, class_group_id, schedule_type, name, day_name, day_code, student_in, student_late, student_out, staff_in, staff_late, staff_out, is_overnight, is_active)
    VALUES (6, 'staff', NULL, NULL, 'reguler', 'Shift 1 Pagi/Siang Security', ?, ?, '00:00:00', '00:00:00', '00:00:00', '06:00:00', '06:30:00', '18:00:00', 0, 'active')
");

foreach ($days as $dCode => $dName) {
    $stmtCheckShift1->execute([$dCode]);
    if (!$stmtCheckShift1->fetch()) {
        $stmtInsertShift1->execute([$dName, $dCode]);
        echo "  + Inserted Shift 1 Siang for {$dName}\n";
    }
}
echo "  ✓ Security Shift 1 ready for all 7 days.\n";

// 3. Add target_type to activities table
echo "\n3. Migrating activities table for target_type (siswa vs staff)...\n";
$actCols = $pdo->query("DESCRIBE activities")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('target_type', $actCols)) {
    $pdo->exec("ALTER TABLE activities ADD COLUMN target_type ENUM('student', 'staff', 'all') NOT NULL DEFAULT 'student' AFTER unit_id");
    $pdo->exec("ALTER TABLE activities ADD INDEX idx_act_target (target_type)");
    echo "  + Added target_type column to activities table\n";

    // Set existing records that have staff hours to 'all' or 'staff'
    $pdo->exec("UPDATE activities SET target_type = 'all' WHERE (staff_in IS NOT NULL AND staff_in != '00:00:00') AND (student_in IS NOT NULL AND student_in != '00:00:00')");
    $pdo->exec("UPDATE activities SET target_type = 'staff' WHERE (staff_in IS NOT NULL AND staff_in != '00:00:00') AND (student_in IS NULL OR student_in = '00:00:00')");
    echo "  + Updated existing activity records target_type\n";
} else {
    echo "  - target_type column already exists in activities table\n";
}

echo "\n=== PHASE 3 MIGRATION COMPLETE! ===\n";
