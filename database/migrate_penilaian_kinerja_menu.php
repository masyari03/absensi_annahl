<?php
/**
 * Migrasi Database: Pendaftaran Menu Penilaian Kinerja
 * 
 * Mendaftarkan:
 * 1. Parent Menu: Penilaian Kinerja (kinerja_parent)
 * 2. Child Menu: Nilai Rata- Rata semua kelas (kinerja_nilai_paralel)
 * Serta memberikan izin akses ke role: super_admin, kepala_sekolah, admin.
 */

require_once __DIR__ . '/../config/database.php';

echo "=== MIGRASI MENU PENILAIAN KINERJA ===\n";

try {
    // 1. Cek atau Buat Parent Menu
    $stmtParent = $pdo->prepare("SELECT id FROM app_menus WHERE menu_key = 'kinerja_parent' LIMIT 1");
    $stmtParent->execute();
    $parentId = $stmtParent->fetchColumn();

    if (!$parentId) {
        $stmtInsertParent = $pdo->prepare("
            INSERT INTO app_menus (menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active)
            VALUES ('kinerja_parent', 'Penilaian Kinerja', 'PENILAIAN KINERJA', '#', 'fa-chart-pie', NULL, 23, 1)
        ");
        $stmtInsertParent->execute();
        $parentId = (int)$pdo->lastInsertId();
        echo "[OK] Parent menu 'kinerja_parent' berhasil dibuat dengan ID {$parentId}.\n";
    } else {
        echo "[SKIP] Parent menu 'kinerja_parent' sudah ada (ID {$parentId}).\n";
    }

    // 2. Cek atau Buat Child Menu
    $stmtChild = $pdo->prepare("SELECT id FROM app_menus WHERE menu_key = 'kinerja_nilai_paralel' LIMIT 1");
    $stmtChild->execute();
    $childId = $stmtChild->fetchColumn();

    if (!$childId) {
        $stmtInsertChild = $pdo->prepare("
            INSERT INTO app_menus (menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active)
            VALUES ('kinerja_nilai_paralel', 'Nilai Rata- Rata semua kelas', 'PENILAIAN KINERJA', 'kinerja/index.php', 'fa-ranking-star', ?, 1, 1)
        ");
        $stmtInsertChild->execute([$parentId]);
        $childId = (int)$pdo->lastInsertId();
        echo "[OK] Child menu 'kinerja_nilai_paralel' berhasil dibuat dengan ID {$childId}.\n";
    } else {
        $stmtUpdate = $pdo->prepare("UPDATE app_menus SET parent_id = ?, menu_name = 'Nilai Rata- Rata semua kelas', menu_url = 'kinerja/index.php' WHERE id = ?");
        $stmtUpdate->execute([$parentId, $childId]);
        echo "[OK] Child menu 'kinerja_nilai_paralel' diperbarui (ID {$childId}).\n";
    }

    // 3. Hak Akses: HANYA Super Admin yang aktif secara default.
    // Role lain (kepala_sekolah, admin, staff) ditutup (0), agar admin dapat mengatur secara selektif via matrix.
    $menuIds = [$parentId, $childId];

    $stmtSuper = $pdo->prepare("SELECT id FROM roles WHERE role_key = 'super_admin' LIMIT 1");
    $stmtSuper->execute();
    $superAdminRoleId = (int)$stmtSuper->fetchColumn();

    // Pastikan super_admin memiliki izin penuh (1)
    foreach ($menuIds as $mId) {
        $stmtCheck = $pdo->prepare("SELECT 1 FROM role_menu_access WHERE role_id = ? AND menu_id = ? LIMIT 1");
        $stmtCheck->execute([$superAdminRoleId, $mId]);
        if (!$stmtCheck->fetchColumn()) {
            $stmtGrant = $pdo->prepare("
                INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
                VALUES (?, ?, 1, 1, 1, 1)
            ");
            $stmtGrant->execute([$superAdminRoleId, $mId]);
        } else {
            $stmtUpdate = $pdo->prepare("
                UPDATE role_menu_access 
                SET can_read = 1, can_create = 1, can_edit = 1, can_delete = 1 
                WHERE role_id = ? AND menu_id = ?
            ");
            $stmtUpdate->execute([$superAdminRoleId, $mId]);
        }
        echo "[OK] Izin akses menu ID {$mId} untuk Super Admin diaktifkan.\n";
    }

    // Cabut izin (set 0) untuk semua role lain selain super_admin
    foreach ($menuIds as $mId) {
        $stmtRevoke = $pdo->prepare("
            UPDATE role_menu_access 
            SET can_read = 0, can_create = 0, can_edit = 0, can_delete = 0 
            WHERE menu_id = ? AND role_id != ?
        ");
        $stmtRevoke->execute([$mId, $superAdminRoleId]);
        echo "[OK] Izin akses menu ID {$mId} untuk role lain dicabut (dibatasi hanya Super Admin).\n";
    }

    echo "=== MIGRASI MENU SELESAI SUKSES 100% ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
