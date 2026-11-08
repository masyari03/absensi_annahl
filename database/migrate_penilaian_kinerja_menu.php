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

    // 3. Berikan Hak Akses ke roles: super_admin (1), kepala_sekolah (2), admin (3)
    $rolesToGrant = ['super_admin', 'kepala_sekolah', 'admin'];
    $menuIds = [$parentId, $childId];

    foreach ($rolesToGrant as $roleKey) {
        $stmtRole = $pdo->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
        $stmtRole->execute([$roleKey]);
        $roleId = $stmtRole->fetchColumn();

        if ($roleId) {
            foreach ($menuIds as $mId) {
                $stmtCheck = $pdo->prepare("SELECT 1 FROM role_menu_access WHERE role_id = ? AND menu_id = ? LIMIT 1");
                $stmtCheck->execute([$roleId, $mId]);
                if (!$stmtCheck->fetchColumn()) {
                    $stmtGrant = $pdo->prepare("
                        INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
                        VALUES (?, ?, 1, 1, 1, 1)
                    ");
                    $stmtGrant->execute([$roleId, $mId]);
                    echo "[OK] Diberikan izin akses menu ID {$mId} untuk role '{$roleKey}'.\n";
                } else {
                    $stmtUpdate = $pdo->prepare("
                        UPDATE role_menu_access 
                        SET can_read = 1, can_create = 1, can_edit = 1, can_delete = 1 
                        WHERE role_id = ? AND menu_id = ?
                    ");
                    $stmtUpdate->execute([$roleId, $mId]);
                    echo "[OK] Diperbarui izin akses menu ID {$mId} untuk role '{$roleKey}'.\n";
                }
            }
        }
    }

    echo "=== MIGRASI MENU SELESAI SUKSES 100% ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
