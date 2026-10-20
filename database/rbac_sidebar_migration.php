<?php
require_once __DIR__ . '/../config/database.php';

echo "=== MIGRATING RBAC & SIDEBAR MENUS ===" . PHP_EOL;

try {
    // 1. Perbarui / Tambah Menu di app_menus
    // mentoring_dashboard (id: 99, sort: 17)
    // mentoring_assignments (id: 102, sort: 18)
    // mentoring_report (id: 103, sort: 19)
    // mentoring_adab (id: 100, sort: 20)
    // mentoring_curriculum (id: 101, sort: 25)

    $stmtCheck = $pdo->prepare("SELECT id FROM app_menus WHERE menu_key = ? LIMIT 1");
    
    // Menu: Kelola Kelompok Mentoring
    $stmtCheck->execute(['mentoring_assignments']);
    $assignId = $stmtCheck->fetchColumn();
    if (!$assignId) {
        $stmtIns = $pdo->prepare("
            INSERT INTO app_menus (menu_key, menu_name, category, menu_url, menu_icon, sort_order, is_active)
            VALUES ('mentoring_assignments', 'Kelompok Mentoring', 'PEMBINAAN', 'mentoring/assignments.php', 'fa-users-gear', 18, 1)
        ");
        $stmtIns->execute();
        $assignId = $pdo->lastInsertId();
        echo "Created menu: mentoring_assignments (ID: $assignId)" . PHP_EOL;
    } else {
        $pdo->prepare("
            UPDATE app_menus SET
                menu_name = 'Kelompok Mentoring',
                category = 'PEMBINAAN',
                menu_url = 'mentoring/assignments.php',
                menu_icon = 'fa-users-gear',
                sort_order = 18,
                is_active = 1
            WHERE id = ?
        ")->execute([$assignId]);
        echo "Updated menu: mentoring_assignments (ID: $assignId)" . PHP_EOL;
    }

    // Menu: Laporan & Raport Mentoring
    $stmtCheck->execute(['mentoring_report']);
    $reportId = $stmtCheck->fetchColumn();
    if (!$reportId) {
        $stmtIns = $pdo->prepare("
            INSERT INTO app_menus (menu_key, menu_name, category, menu_url, menu_icon, sort_order, is_active)
            VALUES ('mentoring_report', 'Laporan & Raport', 'PEMBINAAN', 'mentoring/report.php', 'fa-chart-pie', 19, 1)
        ");
        $stmtIns->execute();
        $reportId = $pdo->lastInsertId();
        echo "Created menu: mentoring_report (ID: $reportId)" . PHP_EOL;
    } else {
        $pdo->prepare("
            UPDATE app_menus SET
                menu_name = 'Laporan & Raport',
                category = 'PEMBINAAN',
                menu_url = 'mentoring/report.php',
                menu_icon = 'fa-chart-pie',
                sort_order = 19,
                is_active = 1
            WHERE id = ?
        ")->execute([$reportId]);
        echo "Updated menu: mentoring_report (ID: $reportId)" . PHP_EOL;
    }

    // Update sort_order menu pembinaan lain agar urutan rapi
    $pdo->exec("UPDATE app_menus SET menu_name = 'Mentoring Siswa (MIP)', category = 'PEMBINAAN', sort_order = 17, is_active = 1 WHERE menu_key = 'mentoring_dashboard'");
    $pdo->exec("UPDATE app_menus SET menu_name = 'Pemantauan Adab', category = 'PEMBINAAN', sort_order = 20, is_active = 1 WHERE menu_key = 'mentoring_adab'");
    $pdo->exec("UPDATE app_menus SET menu_name = 'Kurikulum Adab', category = 'PENGATURAN', sort_order = 25, is_active = 1 WHERE menu_key = 'mentoring_curriculum'");

    // 2. Berikan default role_menu_access
    // roles: 1=super_admin, 2=kepala_sekolah, 3=admin, 4=staff
    $rolesStmt = $pdo->query("SELECT id, role_key FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Definisikan hak akses role:
    // super_admin & kepala_sekolah & admin: mendapatkan mentoring_assignments dan mentoring_report
    // staff: mendapatkan mentoring_report (guru bisa lihat rekap rapor santri binaannya)
    $stmtRMA = $pdo->prepare("INSERT IGNORE INTO role_menu_access (role_id, menu_id) VALUES (?, ?)");

    foreach ($rolesStmt as $rId => $rKey) {
        if (in_array($rKey, ['super_admin', 'kepala_sekolah', 'admin'])) {
            $stmtRMA->execute([$rId, $assignId]);
            $stmtRMA->execute([$rId, $reportId]);
        } elseif ($rKey === 'staff') {
            // Guru mentor berhak melihat Laporan/Raport
            $stmtRMA->execute([$rId, $reportId]);
        }
    }
    echo "Role menu access defaults registered." . PHP_EOL;

    // 3. Tambahkan juga ke user_menu_access untuk pengguna super_admin yang sudah ada
    $stmtUsers = $pdo->query("SELECT id FROM users WHERE role = 'super_admin'");
    $saStmt = $pdo->prepare("INSERT IGNORE INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
    while ($uid = $stmtUsers->fetchColumn()) {
        $saStmt->execute([$uid, $assignId]);
        $saStmt->execute([$uid, $reportId]);
    }
    echo "Super admin users permissions updated." . PHP_EOL;

    echo "=== MIGRATION COMPLETED SUCCESSFULLY ===" . PHP_EOL;
} catch (Exception $e) {
    echo "MIGRATION ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
