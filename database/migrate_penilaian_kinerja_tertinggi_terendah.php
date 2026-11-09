<?php
/**
 * Migrasi Database: Pendaftaran Submenu "Nilai Tertinggi dan Terendah" (ID 114)
 * Mendukung eksekusi lokal dan remote server dengan penanganan try-catch.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "=== MIGRASI MENU PENILAIAN KINERJA (TERTINGGI & TERENDAH) ===\n";

try {
    // 1. Pastikan Parent Menu (ID 112) Ada
    $stmtCheckParent = $pdo->prepare("SELECT id FROM app_menus WHERE menu_key = 'kinerja_parent'");
    $stmtCheckParent->execute();
    $parent = $stmtCheckParent->fetch(PDO::FETCH_ASSOC);

    if (!$parent) {
        $stmtInsertParent = $pdo->prepare("
            INSERT INTO app_menus (id, menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active)
            VALUES (112, 'kinerja_parent', 'Penilaian Kinerja', 'PENILAIAN KINERJA', '#', 'fa-chart-pie', NULL, 23, 1)
        ");
        $stmtInsertParent->execute();
        $parentId = 112;
        echo "[OK] Parent menu 'kinerja_parent' berhasil dibuat (ID 112).\n";
    } else {
        $parentId = $parent['id'];
        echo "[SKIP] Parent menu 'kinerja_parent' sudah ada (ID {$parentId}).\n";
    }

    // 2. Tambahkan / Update Submenu (ID 114: kinerja_tertinggi_terendah)
    $stmtCheckChild = $pdo->prepare("SELECT id FROM app_menus WHERE menu_key = 'kinerja_tertinggi_terendah'");
    $stmtCheckChild->execute();
    $child = $stmtCheckChild->fetch(PDO::FETCH_ASSOC);

    if (!$child) {
        $stmtInsertChild = $pdo->prepare("
            INSERT INTO app_menus (id, menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active)
            VALUES (114, 'kinerja_tertinggi_terendah', 'Nilai Tertinggi dan Terendah', 'PENILAIAN KINERJA', 'kinerja/tertinggi_terendah.php', 'fa-arrow-up-right-dots', ?, 2, 1)
        ");
        $stmtInsertChild->execute([$parentId]);
        $childId = 114;
        echo "[OK] Child menu 'kinerja_tertinggi_terendah' berhasil dibuat (ID 114).\n";
    } else {
        $childId = $child['id'];
        $stmtUpdateChild = $pdo->prepare("
            UPDATE app_menus 
            SET menu_name = 'Nilai Tertinggi dan Terendah', 
                category = 'PENILAIAN KINERJA', 
                menu_url = 'kinerja/tertinggi_terendah.php',
                menu_icon = 'fa-arrow-up-right-dots',
                parent_id = ?,
                sort_order = 2,
                is_active = 1
            WHERE id = ?
        ");
        $stmtUpdateChild->execute([$parentId, $childId]);
        echo "[OK] Child menu 'kinerja_tertinggi_terendah' diperbarui (ID {$childId}).\n";
    }

    // 3. Konfigurasi Role Menu Access
    // Super Admin (role_id = 1) -> Full Akses
    $stmtSuperAdmin = $pdo->prepare("
        INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
        VALUES (1, ?, 1, 1, 1, 1)
        ON DUPLICATE KEY UPDATE can_read = 1, can_create = 1, can_edit = 1, can_delete = 1
    ");
    $stmtSuperAdmin->execute([$childId]);
    echo "[OK] Izin akses menu ID {$childId} untuk Super Admin diaktifkan.\n";

    // Non-Super Admin: Tutup default (can_read = 0)
    $stmtRoles = $pdo->query("SELECT id FROM roles WHERE id != 1");
    $otherRoles = $stmtRoles->fetchAll(PDO::FETCH_COLUMN);

    $stmtRevoke = $pdo->prepare("
        INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
        VALUES (?, ?, 0, 0, 0, 0)
        ON DUPLICATE KEY UPDATE can_read = 0, can_create = 0, can_edit = 0, can_delete = 0
    ");

    foreach ($otherRoles as $rId) {
        $stmtRevoke->execute([$rId, $childId]);
    }
    echo "[OK] Izin akses menu ID {$childId} untuk role lain dicabut (dibatasi hanya Super Admin secara default).\n";

    echo "=== MIGRASI MENU SELESAI SUKSES 100% ===\n";

} catch (PDOException $e) {
    echo "ERROR MIGRASI: " . $e->getMessage() . "\n";
    exit(1);
}
