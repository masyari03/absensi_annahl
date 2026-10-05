<?php
/**
 * Migration Script untuk Fitur Baru:
 * 1. Eskul di Jadwal Pekanan (weekly_schedules & weekly_schedule_students)
 * 2. Log Aktivitas & Rollback Engine (activity_audit_logs)
 * 3. Matikan Absen Otomatis (auto_attendance_exclusions)
 * 4. Shift Malam Security (cross-midnight support di weekly_schedules)
 */

require_once __DIR__ . '/../config/database.php';

echo "=== MEMULAI MIGRATION FITUR BARU ===\n";

try {
    // 1. Modifikasi tabel weekly_schedules
    $colsStmt = $pdo->query("SHOW COLUMNS FROM weekly_schedules");
    $wsCols = [];
    while ($r = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
        $wsCols[] = $r['Field'];
    }

    if (!in_array('schedule_type', $wsCols)) {
        $pdo->exec("ALTER TABLE weekly_schedules ADD COLUMN schedule_type ENUM('reguler', 'eskul') NOT NULL DEFAULT 'reguler' AFTER unit_id");
        echo "[OK] Kolom schedule_type ditambahkan ke weekly_schedules.\n";
    }

    if (!in_array('name', $wsCols)) {
        $pdo->exec("ALTER TABLE weekly_schedules ADD COLUMN name VARCHAR(100) NULL DEFAULT NULL AFTER schedule_type");
        echo "[OK] Kolom name ditambahkan ke weekly_schedules.\n";
    }

    if (!in_array('is_overnight', $wsCols)) {
        $pdo->exec("ALTER TABLE weekly_schedules ADD COLUMN is_overnight TINYINT(1) NOT NULL DEFAULT 0 AFTER staff_out");
        echo "[OK] Kolom is_overnight ditambahkan ke weekly_schedules.\n";
    }

    // 2. Tabel weekly_schedule_students (Relasi Siswa Peserta Eskul)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weekly_schedule_students (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            weekly_schedule_id INT NOT NULL,
            student_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_schedule_student (weekly_schedule_id, student_id),
            INDEX idx_student (student_id),
            FOREIGN KEY (weekly_schedule_id) REFERENCES weekly_schedules(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] Tabel weekly_schedule_students siap.\n";

    // 3. Tabel activity_audit_logs (Log Aktivitas & Engine Rollback)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            username VARCHAR(100) NULL,
            role VARCHAR(50) NULL,
            unit_id INT UNSIGNED NULL,
            module VARCHAR(50) NOT NULL,
            action ENUM('CREATE', 'UPDATE', 'DELETE', 'BULK_DELETE', 'ROLLBACK') NOT NULL,
            table_name VARCHAR(64) NOT NULL,
            record_id BIGINT UNSIGNED NULL,
            description TEXT NOT NULL,
            old_data LONGTEXT NULL,
            new_data LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            is_deleted_by_superadmin TINYINT(1) NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL,
            is_rolled_back TINYINT(1) NOT NULL DEFAULT 0,
            rolled_back_at DATETIME NULL,
            rolled_back_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_unit (unit_id),
            INDEX idx_module (module),
            INDEX idx_action (action),
            INDEX idx_created (created_at),
            INDEX idx_del_super (is_deleted_by_superadmin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] Tabel activity_audit_logs siap.\n";

    // 4. Tabel auto_attendance_exclusions (Pengaturan Mematikan Absen Otomatis)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auto_attendance_exclusions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scope_type ENUM('global', 'unit', 'grade', 'class_group', 'student', 'staff') NOT NULL,
            target_id BIGINT UNSIGNED NULL DEFAULT 0,
            target_name VARCHAR(255) NOT NULL,
            is_disabled TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(255) NULL,
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_scope_target (scope_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] Tabel auto_attendance_exclusions siap.\n";

    // 5. Tambahkan menu app_menus jika belum ada untuk fitur baru
    $appMenuCols = $pdo->query("SHOW TABLES LIKE 'app_menus'")->fetch();
    if ($appMenuCols) {
        $checkMenus = [
            ['activity_logs', 'Log Aktivitas Data', 'SISTEM', 'kelola/audit_logs.php', 'fa-history', 22],
            ['auto_attendance', 'Atur Absen Otomatis', 'PENGATURAN', 'kelola/auto_attendance.php', 'fa-toggle-off', 23]
        ];

        $stmtInsMenu = $pdo->prepare("INSERT IGNORE INTO app_menus (menu_key, menu_name, category, menu_url, menu_icon, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($checkMenus as $m) {
            $stmtInsMenu->execute($m);
        }
        
        // Grant super_admin access
        $pdo->exec("
            INSERT IGNORE INTO role_menu_access (role_id, menu_id)
            SELECT r.id, am.id FROM roles r, app_menus am 
            WHERE r.role_key = 'super_admin' AND am.menu_key IN ('activity_logs', 'auto_attendance')
        ");

        // Grant kepala_sekolah access to activity_logs
        $pdo->exec("
            INSERT IGNORE INTO role_menu_access (role_id, menu_id)
            SELECT r.id, am.id FROM roles r, app_menus am 
            WHERE r.role_key = 'kepala_sekolah' AND am.menu_key = 'activity_logs'
        ");
        echo "[OK] app_menus dan role_menu_access diperbarui.\n";
    }

    // 6. Buat Jadwal Default untuk Security (Shift Pagi & Shift Malam) jika belum ada
    $secCount = (int)$pdo->query("SELECT COUNT(*) FROM weekly_schedules WHERE unit_id = 6")->fetchColumn();
    if ($secCount === 0) {
        $days = [1=>'Senin', 2=>'Selasa', 3=>'Rabu', 4=>'Kamis', 5=>'Jumat', 6=>'Sabtu', 7=>'Minggu'];
        $insWs = $pdo->prepare("
            INSERT INTO weekly_schedules 
            (unit_id, schedule_type, name, day_name, day_code, student_in, student_late, student_out, staff_in, staff_late, staff_out, is_overnight, is_active)
            VALUES (6, 'reguler', 'Shift Malam Security', ?, ?, '00:00:00', '00:00:00', '00:00:00', '18:00:00', '18:30:00', '06:00:00', 1, 'active')
        ");
        foreach ($days as $c => $d) {
            $insWs->execute([$d, $c]);
        }
        echo "[OK] Default Shift Malam Security (18:00 - 06:00) berhasil dibuat.\n";
    }

    echo "\n=== MIGRATION SELESAI DENGAN SUKSES ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
