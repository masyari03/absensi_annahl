<?php
require_once 'C:/laragon/www/absensi-anak/config/database.php';

echo "=== RUNNING DATABASE MIGRATIONS FOR 4 NEW REQUIREMENTS ===\n\n";

// 1. Update weekly_schedules table
echo "1. Migrating weekly_schedules...\n";
$wsCols = $pdo->query("DESCRIBE weekly_schedules")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('target_type', $wsCols)) {
    $pdo->exec("ALTER TABLE weekly_schedules ADD COLUMN target_type ENUM('student', 'staff') NOT NULL DEFAULT 'student' AFTER unit_id");
    echo "  + Added target_type column\n";
}

if (!in_array('grade_id', $wsCols)) {
    $pdo->exec("ALTER TABLE weekly_schedules ADD COLUMN grade_id INT UNSIGNED NULL AFTER target_type");
    $pdo->exec("ALTER TABLE weekly_schedules ADD INDEX idx_ws_grade (grade_id)");
    echo "  + Added grade_id column\n";
}

if (!in_array('class_group_id', $wsCols)) {
    $pdo->exec("ALTER TABLE weekly_schedules ADD COLUMN class_group_id INT UNSIGNED NULL AFTER grade_id");
    $pdo->exec("ALTER TABLE weekly_schedules ADD INDEX idx_ws_class_group (class_group_id)");
    echo "  + Added class_group_id column\n";
}

// Set existing staff / security shift records to target_type = 'staff'
$pdo->exec("UPDATE weekly_schedules SET target_type = 'staff' WHERE unit_id = 6 OR is_overnight = 1");
echo "  ✓ weekly_schedules migrated.\n";


// 2. Update wa_unit_settings table
echo "\n2. Migrating wa_unit_settings...\n";
$waCols = $pdo->query("DESCRIBE wa_unit_settings")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('template_in', $waCols)) {
    $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN template_in TEXT NULL AFTER staff_recap_enabled");
    echo "  + Added template_in column\n";
}
if (!in_array('template_late', $waCols)) {
    $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN template_late TEXT NULL AFTER template_in");
    echo "  + Added template_late column\n";
}
if (!in_array('template_out', $waCols)) {
    $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN template_out TEXT NULL AFTER template_late");
    echo "  + Added template_out column\n";
}
if (!in_array('template_out_late', $waCols)) {
    $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN template_out_late TEXT NULL AFTER template_out");
    echo "  + Added template_out_late column\n";
}
if (!in_array('msg_in_timing', $waCols)) {
    $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN msg_in_timing VARCHAR(20) DEFAULT 'realtime' AFTER template_out_late");
    echo "  + Added msg_in_timing column\n";
}
if (!in_array('msg_late_timing', $waCols)) {
    $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN msg_late_timing VARCHAR(20) DEFAULT 'after_late' AFTER msg_in_timing");
    echo "  + Added msg_late_timing column\n";
}
if (!in_array('msg_out_timing', $waCols)) {
    $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN msg_out_timing VARCHAR(20) DEFAULT 'realtime' AFTER msg_late_timing");
    echo "  + Added msg_out_timing column\n";
}
if (!in_array('msg_out_late_timing', $waCols)) {
    $pdo->exec("ALTER TABLE wa_unit_settings ADD COLUMN msg_out_late_timing VARCHAR(20) DEFAULT 'after_out' AFTER msg_out_timing");
    echo "  + Added msg_out_late_timing column\n";
}
echo "  ✓ wa_unit_settings migrated.\n";


// 3. Grant auto_attendance permission to kepala_sekolah
echo "\n3. Updating permissions for auto_attendance to kepala_sekolah...\n";
$roleKsId = $pdo->query("SELECT id FROM roles WHERE role_key = 'kepala_sekolah'")->fetchColumn();
$menuAutoId = $pdo->query("SELECT id FROM app_menus WHERE menu_key = 'auto_attendance'")->fetchColumn();

if ($roleKsId && $menuAutoId) {
    $pdo->prepare("INSERT IGNORE INTO role_menu_access (role_id, menu_id) VALUES (?, ?)")->execute([$roleKsId, $menuAutoId]);
    echo "  + Added auto_attendance to role_menu_access for kepala_sekolah\n";

    // Sync to all kepala_sekolah in user_menu_access
    $pdo->prepare("
        INSERT IGNORE INTO user_menu_access (user_id, menu_id)
        SELECT id, ? FROM users WHERE role = 'kepala_sekolah'
    ")->execute([$menuAutoId]);
    echo "  + Synced auto_attendance to user_menu_access for all kepala_sekolah users\n";
}

// 4. Ensure all units have a record in wa_unit_settings
echo "\n4. Ensuring wa_unit_settings for all units...\n";
$pdo->exec("
    INSERT IGNORE INTO wa_unit_settings (unit_id, is_enabled, msg_in_enabled, msg_late_enabled, msg_late_delay_minutes, msg_late_ref, msg_out_enabled, msg_out_late_enabled, msg_out_late_delay_minutes)
    SELECT id, 1, 1, 1, 30, 'student_late', 1, 1, 45 FROM units
");
echo "  ✓ All units seeded in wa_unit_settings.\n";

echo "\n=== ALL MIGRATIONS COMPLETED SUCCESSFULLY! ===\n";
