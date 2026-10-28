<?php
require_once __DIR__ . '/../config/database.php';

echo "=== MENGUPDATE IZIN ROLE_MENU_ACCESS UNTUK MENTORING_ASSIGNMENTS ===\n";

try {
    // 1. Ambil id menu mentoring_assignments
    $stmtMenu = $pdo->query("SELECT id FROM app_menus WHERE menu_key = 'mentoring_assignments' LIMIT 1");
    $menuId = (int)$stmtMenu->fetchColumn();

    if ($menuId > 0) {
        // Ambil role_id untuk staff
        $stmtRole = $pdo->query("SELECT id FROM roles WHERE role_key = 'staff' LIMIT 1");
        $staffRoleId = (int)$stmtRole->fetchColumn();

        if ($staffRoleId > 0) {
            $stmtUp = $pdo->prepare("
                UPDATE role_menu_access 
                SET can_read = 0, can_create = 0, can_edit = 0, can_delete = 0 
                WHERE role_id = ? AND menu_id = ?
            ");
            $stmtUp->execute([$staffRoleId, $menuId]);
            echo "SUCCESS: role_menu_access untuk role staff pada mentoring_assignments di-set ke 0 (diblokir).\n";
        }
    } else {
        echo "WARNING: menu mentoring_assignments tidak ditemukan.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
