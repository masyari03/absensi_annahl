<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

// Proteksi: Hanya Super Admin yang berhak mengelola akses & role
requireSuperAdmin();

$pageTitle = 'Manajemen Akses & Pengguna';
$currentUserId = currentUserId();

// =========================================================================
// AJAX: AMBIL HAK AKSES USER (JSON DENGAN GRANULAR CRUD MATRIX)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_user_permissions') {
    header('Content-Type: application/json');
    $uid = (int)($_GET['user_id'] ?? 0);

    // Ambil info user
    $stmtUser = $pdo->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $stmtUser->execute([$uid]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    // Ambil granular permissions per menu_id
    $stmt = $pdo->prepare("
        SELECT menu_id, can_read, can_create, can_edit, can_delete 
        FROM user_menu_access 
        WHERE user_id = ?
    ");
    $stmt->execute([$uid]);
    $permissions = [];
    $menuIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$row['menu_id'];
        $menuIds[] = $mid;
        $permissions[$mid] = [
            'read'   => (int)$row['can_read'],
            'create' => (int)$row['can_create'],
            'edit'   => (int)$row['can_edit'],
            'delete' => (int)$row['can_delete'],
        ];
    }

    // Ambil list assigned roles dari user_roles
    $stmtRoles = $pdo->prepare("
        SELECT r.id, r.role_key, r.role_name, ur.is_primary
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
        ORDER BY ur.is_primary DESC, r.id ASC
    ");
    $stmtRoles->execute([$uid]);
    $userRoles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'user' => $user,
        'menu_ids' => array_map('intval', $menuIds),
        'permissions' => $permissions,
        'roles' => $userRoles
    ]);
    exit;
}

// =========================================================================
// AJAX: AMBIL DEFAULT HAK AKSES ROLE (JSON DENGAN GRANULAR CRUD MATRIX)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_role_permissions') {
    header('Content-Type: application/json');
    $roleId = (int)($_GET['role_id'] ?? 0);
    $roleKey = trim($_GET['role_key'] ?? '');

    $roleData = null;
    if ($roleId > 0) {
        $stmtR = $pdo->prepare("SELECT id, role_key, role_name, description FROM roles WHERE id = ?");
        $stmtR->execute([$roleId]);
        $roleData = $stmtR->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("
            SELECT menu_id, can_read, can_create, can_edit, can_delete 
            FROM role_menu_access 
            WHERE role_id = ?
        ");
        $stmt->execute([$roleId]);
    } else {
        $stmtR = $pdo->prepare("SELECT id, role_key, role_name, description FROM roles WHERE role_key = ?");
        $stmtR->execute([$roleKey]);
        $roleData = $stmtR->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("
            SELECT rma.menu_id, rma.can_read, rma.can_create, rma.can_edit, rma.can_delete 
            FROM role_menu_access rma 
            INNER JOIN roles r ON r.id = rma.role_id 
            WHERE r.role_key = ?
        ");
        $stmt->execute([$roleKey]);
    }

    $permissions = [];
    $menuIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$row['menu_id'];
        $menuIds[] = $mid;
        $permissions[$mid] = [
            'read'   => (int)$row['can_read'],
            'create' => (int)$row['can_create'],
            'edit'   => (int)$row['can_edit'],
            'delete' => (int)$row['can_delete'],
        ];
    }

    echo json_encode([
        'status' => 'success',
        'role' => $roleData,
        'menu_ids' => array_map('intval', $menuIds),
        'permissions' => $permissions
    ]);
    exit;
}

// =========================================================================
// AJAX: AMBIL DATA MULTI-ROLE PENGGUNA (JSON)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_user_roles') {
    header('Content-Type: application/json');
    $uid = (int)($_GET['user_id'] ?? 0);

    $stmtUser = $pdo->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $stmtUser->execute([$uid]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    // Ambil assigned role IDs
    $stmtUR = $pdo->prepare("SELECT role_id, is_primary FROM user_roles WHERE user_id = ?");
    $stmtUR->execute([$uid]);
    $assignedRoles = $stmtUR->fetchAll(PDO::FETCH_ASSOC);

    $assignedIds = [];
    $primaryId = 0;
    foreach ($assignedRoles as $ar) {
        $rid = (int)$ar['role_id'];
        $assignedIds[] = $rid;
        if ((int)$ar['is_primary'] === 1) {
            $primaryId = $rid;
        }
    }

    // Fallback primary role jika di user_roles belum diset
    if ($primaryId === 0 && !empty($user['role'])) {
        $stmtFind = $pdo->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
        $stmtFind->execute([$user['role']]);
        $primaryId = (int)$stmtFind->fetchColumn();
        if ($primaryId > 0 && !in_array($primaryId, $assignedIds, true)) {
            $assignedIds[] = $primaryId;
        }
    }

    echo json_encode([
        'status' => 'success',
        'user' => $user,
        'assigned_role_ids' => $assignedIds,
        'primary_role_id' => $primaryId
    ]);
    exit;
}

// =========================================================================
// PROSES POST FORM
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. SIMPAN HAK AKSES USER TUNGGAL (MANUAL DENGAN 2D CRUD MATRIX)
    if ($action === 'save_user_permissions') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $permData = $_POST['perm'] ?? [];
        $selectedMenuIds = array_map('intval', $_POST['menu_ids'] ?? []);

        if ($targetUserId > 0) {
            $pdo->beginTransaction();
            try {
                // Hapus permission lama
                $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?")->execute([$targetUserId]);

                $stmtInsert = $pdo->prepare("
                    INSERT INTO user_menu_access (user_id, menu_id, can_read, can_create, can_edit, can_delete) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                if (!empty($permData) && is_array($permData)) {
                    // Simpan dari 2D CRUD Matrix
                    foreach ($permData as $menuId => $actions) {
                        $mId = (int)$menuId;
                        $r = !empty($actions['read']) ? 1 : 0;
                        $c = !empty($actions['create']) ? 1 : 0;
                        $e = !empty($actions['edit']) ? 1 : 0;
                        $d = !empty($actions['delete']) ? 1 : 0;
                        // Otomatis aktifkan read jika create/edit/delete aktif
                        if ($c || $e || $d) {
                            $r = 1;
                        }
                        if ($mId > 0 && ($r || $c || $e || $d)) {
                            $stmtInsert->execute([$targetUserId, $mId, $r, $c, $e, $d]);
                        }
                    }
                } elseif (!empty($selectedMenuIds)) {
                    // Fallback backward compatibility
                    foreach ($selectedMenuIds as $mId) {
                        if ($mId > 0) {
                            $stmtInsert->execute([$targetUserId, $mId, 1, 0, 0, 0]);
                        }
                    }
                }

                $pdo->commit();
                refreshUserPermissions($pdo, $targetUserId);
                if (function_exists('recordActivityAudit')) {
                    recordActivityAudit($pdo, 'access_control', 'UPDATE', 'user_menu_access', $targetUserId, "Perbarui Matriks Hak Akses (CRUD) Pengguna #{$targetUserId}");
                }
                flash('success', 'Matriks hak akses (CRUD) pengguna berhasil diperbarui!');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', 'Gagal menyimpan hak akses: ' . $e->getMessage());
            }
        }
        redirect('index.php?tab=users');
    }

    // 1B. SIMPAN MULTI-ROLE PENGGUNA
    if ($action === 'save_user_roles') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $selectedRoleIds = array_map('intval', $_POST['role_ids'] ?? []);
        $primaryRoleId = (int)($_POST['primary_role_id'] ?? 0);

        if ($uid <= 0 || empty($selectedRoleIds)) {
            flash('error', 'Pilih minimal satu role untuk pengguna.');
            redirect('index.php?tab=users');
        }

        if (!in_array($primaryRoleId, $selectedRoleIds, true)) {
            $primaryRoleId = $selectedRoleIds[0];
        }

        // Ambil role_key dari primary role
        $stmtP = $pdo->prepare("SELECT role_key FROM roles WHERE id = ?");
        $stmtP->execute([$primaryRoleId]);
        $primaryRoleKey = $stmtP->fetchColumn() ?: 'staff';

        // Proteksi: cegah Super Admin mendemote dirinya sendiri
        if ($uid === $currentUserId && $primaryRoleKey !== 'super_admin') {
            $stmtSA = $pdo->query("SELECT id FROM roles WHERE role_key = 'super_admin' LIMIT 1");
            $saId = (int)$stmtSA->fetchColumn();
            if (!in_array($saId, $selectedRoleIds, true)) {
                flash('error', 'Anda tidak dapat menghapus wewenang Super Admin dari akun Anda sendiri.');
                redirect('index.php?tab=users');
            }
        }

        $pdo->beginTransaction();
        try {
            // Update users.role dengan primary role
            $stmtUpdUser = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmtUpdUser->execute([$primaryRoleKey, $uid]);

            // Re-sync user_roles
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$uid]);
            $stmtInsertUR = $pdo->prepare("
                INSERT INTO user_roles (user_id, role_id, is_primary) 
                VALUES (?, ?, ?)
            ");
            foreach ($selectedRoleIds as $rid) {
                $isPri = ($rid === $primaryRoleId) ? 1 : 0;
                $stmtInsertUR->execute([$uid, $rid, $isPri]);
            }

            $pdo->commit();
            refreshUserPermissions($pdo, $uid);
            if (function_exists('recordActivityAudit')) {
                recordActivityAudit($pdo, 'access_control', 'UPDATE', 'user_roles', $uid, "Perbarui Multi-Role Pengguna #{$uid}: Primary {$primaryRoleKey}");
            }
            flash('success', 'Multi-role pengguna berhasil diperbarui!');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal memperbarui multi-role: ' . $e->getMessage());
        }
        redirect('index.php?tab=users');
    }

    // 2. QUICK CHANGE ROLE (GANTI ROLE TUNGGAL CEPAT & OPSI SETTING MANUAL)
    if ($action === 'quick_change_role') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $newRole = trim($_POST['new_role'] ?? '');
        $syncPolicy = trim($_POST['sync_policy'] ?? 'apply_defaults'); // apply_defaults | keep_existing | manual_customize

        if ($uid <= 0 || empty($newRole)) {
            flash('error', 'Parameter pengguna atau role tidak valid.');
            redirect('index.php?tab=users');
        }

        // Proteksi: cegah Super Admin mendemote dirinya sendiri ke role non-super_admin
        if ($uid === $currentUserId && $newRole !== 'super_admin') {
            flash('error', 'Anda tidak dapat menurunkan role akun Super Admin Anda sendiri.');
            redirect('index.php?tab=users');
        }

        // Cek apakah role tujuan ada di master roles atau 'siswa'
        $stmtCheckRole = $pdo->prepare("SELECT id, role_name FROM roles WHERE role_key = ?");
        $stmtCheckRole->execute([$newRole]);
        $roleRow = $stmtCheckRole->fetch(PDO::FETCH_ASSOC);

        if (!$roleRow && $newRole !== 'siswa') {
            flash('error', 'Role tujuan tidak ditemukan dalam sistem.');
            redirect('index.php?tab=users');
        }

        $pdo->beginTransaction();
        try {
            // Ambil data user lama
            $stmtOld = $pdo->prepare("SELECT name, username, role FROM users WHERE id = ?");
            $stmtOld->execute([$uid]);
            $oldUser = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if (!$oldUser) {
                throw new Exception("Pengguna tidak ditemukan.");
            }

            // Update role di tabel users
            $stmtUp = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmtUp->execute([$newRole, $uid]);

            // Eksekusi kebijakan sinkronisasi hak akses
            if ($syncPolicy === 'apply_defaults') {
                // Hapus izin lama
                $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?")->execute([$uid]);

                // Salin default role menu jika role ada di tabel roles
                if ($roleRow) {
                    $stmtDef = $pdo->prepare("SELECT menu_id FROM role_menu_access WHERE role_id = ?");
                    $stmtDef->execute([$roleRow['id']]);
                    $defMenuIds = $stmtDef->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($defMenuIds)) {
                        $stmtIns = $pdo->prepare("INSERT INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
                        foreach ($defMenuIds as $mId) {
                            $stmtIns->execute([$uid, (int)$mId]);
                        }
                    }
                }
            }
            // Jika keep_existing atau manual_customize, menu user dipertahankan saat ini

            $pdo->commit();
            refreshUserPermissions($pdo, $uid);

            if (function_exists('recordActivityAudit')) {
                recordActivityAudit($pdo, 'access_control', 'UPDATE', 'users', $uid, "Quick Role Change: {$oldUser['name']} (#{$uid}) dari {$oldUser['role']} ke {$newRole} (Policy: {$syncPolicy})");
            }

            $roleNameText = $roleRow ? $roleRow['role_name'] : ucfirst($newRole);
            flash('success', "Role {$oldUser['name']} berhasil diubah menjadi \"{$roleNameText}\".");

            if ($syncPolicy === 'manual_customize') {
                redirect("index.php?tab=users&open_permissions_user_id={$uid}");
            } else {
                redirect('index.php?tab=users');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal mengubah role: ' . $e->getMessage());
            redirect('index.php?tab=users');
        }
    }

    // 3. BULK CHANGE ROLE (AKSI MASSAL UBAH ROLE)
    if ($action === 'bulk_change_role') {
        $rawUserIds = $_POST['user_ids'] ?? [];
        if (is_string($rawUserIds)) {
            $rawUserIds = json_decode($rawUserIds, true) ?: [];
        }
        $userIds = array_unique(array_filter(array_map('intval', (array)$rawUserIds)));
        $newRole = trim($_POST['new_role'] ?? '');
        $syncPolicy = trim($_POST['sync_policy'] ?? 'apply_defaults'); // apply_defaults | keep_existing

        if (empty($userIds) || empty($newRole)) {
            flash('error', 'Pilih minimal satu pengguna dan tentukan role baru.');
            redirect('index.php?tab=users');
        }

        // Proteksi: jangan demote current Super Admin jika ikut terpilih
        if (in_array($currentUserId, $userIds) && $newRole !== 'super_admin') {
            $userIds = array_values(array_diff($userIds, [$currentUserId]));
            flash('warning', 'Akun Super Admin Anda saat ini dilewati dari perubahan role massal untuk mencegah kehilangan akses.');
            if (empty($userIds)) {
                redirect('index.php?tab=users');
            }
        }

        // Cek role
        $stmtCheckRole = $pdo->prepare("SELECT id, role_name FROM roles WHERE role_key = ?");
        $stmtCheckRole->execute([$newRole]);
        $roleRow = $stmtCheckRole->fetch(PDO::FETCH_ASSOC);

        if (!$roleRow && $newRole !== 'siswa') {
            flash('error', 'Role tujuan tidak valid.');
            redirect('index.php?tab=users');
        }

        $pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            
            // 1. Update role users
            $stmtUp = $pdo->prepare("UPDATE users SET role = ? WHERE id IN ($placeholders)");
            $params = array_merge([$newRole], $userIds);
            $stmtUp->execute($params);

            // 2. Sinkronisasi menu jika apply_defaults
            if ($syncPolicy === 'apply_defaults') {
                $stmtDel = $pdo->prepare("DELETE FROM user_menu_access WHERE user_id IN ($placeholders)");
                $stmtDel->execute($userIds);

                if ($roleRow) {
                    $stmtDef = $pdo->prepare("SELECT menu_id FROM role_menu_access WHERE role_id = ?");
                    $stmtDef->execute([$roleRow['id']]);
                    $defMenuIds = $stmtDef->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($defMenuIds)) {
                        $stmtIns = $pdo->prepare("INSERT INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
                        foreach ($userIds as $uId) {
                            foreach ($defMenuIds as $mId) {
                                $stmtIns->execute([(int)$uId, (int)$mId]);
                            }
                        }
                    }
                }
            }

            $pdo->commit();

            foreach ($userIds as $uId) {
                refreshUserPermissions($pdo, $uId);
            }

            if (function_exists('recordActivityAudit')) {
                recordActivityAudit($pdo, 'access_control', 'UPDATE', 'users', 0, "Bulk Change Role: " . count($userIds) . " pengguna diubah ke role '{$newRole}' (Policy: {$syncPolicy})");
            }

            $roleNameText = $roleRow ? $roleRow['role_name'] : ucfirst($newRole);
            flash('success', "Berhasil mengubah role " . count($userIds) . " pengguna menjadi \"{$roleNameText}\".");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal memproses perubahan role massal: ' . $e->getMessage());
        }
        redirect('index.php?tab=users');
    }

    // 4. BULK RESET PERMISSIONS (RESET MASSAL KE TEMPLATE DEFAULT ROLE)
    if ($action === 'bulk_reset_permissions') {
        $rawUserIds = $_POST['user_ids'] ?? [];
        if (is_string($rawUserIds)) {
            $rawUserIds = json_decode($rawUserIds, true) ?: [];
        }
        $userIds = array_unique(array_filter(array_map('intval', (array)$rawUserIds)));

        if (empty($userIds)) {
            flash('error', 'Pilih minimal satu pengguna.');
            redirect('index.php?tab=users');
        }

        $pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            
            // Hapus izin lama user terpilih
            $stmtDel = $pdo->prepare("DELETE FROM user_menu_access WHERE user_id IN ($placeholders)");
            $stmtDel->execute($userIds);

            // Ambil user dan rolenya
            $stmtUsers = $pdo->prepare("SELECT id, role FROM users WHERE id IN ($placeholders)");
            $stmtUsers->execute($userIds);
            $userList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

            // Cache menu per role
            $stmtRma = $pdo->query("
                SELECT r.role_key, rma.menu_id 
                FROM role_menu_access rma 
                JOIN roles r ON r.id = rma.role_id
            ");
            $roleMenus = [];
            while ($rRow = $stmtRma->fetch(PDO::FETCH_ASSOC)) {
                $roleMenus[$rRow['role_key']][] = (int)$rRow['menu_id'];
            }

            $stmtIns = $pdo->prepare("INSERT INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
            $appliedCount = 0;
            foreach ($userList as $u) {
                $rKey = $u['role'];
                if (!empty($roleMenus[$rKey])) {
                    foreach ($roleMenus[$rKey] as $mId) {
                        $stmtIns->execute([(int)$u['id'], $mId]);
                    }
                    $appliedCount++;
                }
            }

            $pdo->commit();

            foreach ($userIds as $uId) {
                refreshUserPermissions($pdo, $uId);
            }

            if (function_exists('recordActivityAudit')) {
                recordActivityAudit($pdo, 'access_control', 'UPDATE', 'user_menu_access', 0, "Bulk Reset Permissions: " . count($userIds) . " pengguna diset ulang ke template role default.");
            }

            flash('success', "Hak akses " . count($userIds) . " pengguna berhasil diselaraskan ke template default role masing-masing.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal mereset hak akses massal: ' . $e->getMessage());
        }
        redirect('index.php?tab=users');
    }

    // 5. BULK MENU ACTION (BERI ATAU CABUT MENU MASSAL)
    if ($action === 'bulk_menu_action') {
        $rawUserIds = $_POST['user_ids'] ?? [];
        if (is_string($rawUserIds)) {
            $rawUserIds = json_decode($rawUserIds, true) ?: [];
        }
        $userIds = array_unique(array_filter(array_map('intval', (array)$rawUserIds)));
        $subAction = trim($_POST['sub_action'] ?? 'grant'); // grant | revoke
        $selectedMenuIds = array_map('intval', $_POST['menu_ids'] ?? []);

        if (empty($userIds) || empty($selectedMenuIds)) {
            flash('error', 'Pilih minimal satu pengguna dan minimal satu menu.');
            redirect('index.php?tab=users');
        }

        $pdo->beginTransaction();
        try {
            if ($subAction === 'grant') {
                $stmtIns = $pdo->prepare("INSERT IGNORE INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
                foreach ($userIds as $uId) {
                    foreach ($selectedMenuIds as $mId) {
                        if ($mId > 0) $stmtIns->execute([(int)$uId, $mId]);
                    }
                }
                $pdo->commit();
                flash('success', "Akses " . count($selectedMenuIds) . " menu berhasil DIBERIKAN ke " . count($userIds) . " pengguna terpilih.");
            } else { // revoke
                $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
                $menuPlaceholders = implode(',', array_fill(0, count($selectedMenuIds), '?'));
                $sql = "DELETE FROM user_menu_access WHERE user_id IN ($userPlaceholders) AND menu_id IN ($menuPlaceholders)";
                $stmtDel = $pdo->prepare($sql);
                $params = array_merge($userIds, $selectedMenuIds);
                $stmtDel->execute($params);
                $pdo->commit();
                flash('success', "Akses " . count($selectedMenuIds) . " menu berhasil DICABUT dari " . count($userIds) . " pengguna terpilih.");
            }

            foreach ($userIds as $uId) {
                refreshUserPermissions($pdo, $uId);
            }

            if (function_exists('recordActivityAudit')) {
                recordActivityAudit($pdo, 'access_control', 'UPDATE', 'user_menu_access', 0, "Bulk Menu Action ({$subAction}) untuk " . count($userIds) . " pengguna.");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal memproses aksi menu massal: ' . $e->getMessage());
        }
        redirect('index.php?tab=users');
    }

    // 6. BULK DELETE USERS (HAPUS MASSAL PENGGUNA)
    if ($action === 'bulk_delete_users') {
        $rawUserIds = $_POST['user_ids'] ?? [];
        if (is_string($rawUserIds)) {
            $rawUserIds = json_decode($rawUserIds, true) ?: [];
        }
        $userIds = array_unique(array_filter(array_map('intval', (array)$rawUserIds)));

        if (empty($userIds)) {
            flash('error', 'Pilih minimal satu pengguna untuk dihapus.');
            redirect('index.php?tab=users');
        }

        // Proteksi anti-self-delete & root super admin id 1
        $blocked = [];
        if (in_array($currentUserId, $userIds)) {
            $blocked[] = $currentUserId;
        }
        if (in_array(1, $userIds)) {
            $blocked[] = 1;
        }
        $blocked = array_unique($blocked);

        if (!empty($blocked)) {
            $userIds = array_values(array_diff($userIds, $blocked));
            flash('warning', 'Akun Super Admin aktif atau akun Root #1 dilewati dari penghapusan demi keamanan.');
        }

        if (empty($userIds)) {
            flash('error', 'Tidak ada pengguna valid yang dapat dihapus.');
            redirect('index.php?tab=users');
        }

        $pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            // Hapus akses menu
            $pdo->prepare("DELETE FROM user_menu_access WHERE user_id IN ($placeholders)")->execute($userIds);
            // Soft delete
            $pdo->prepare("UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)")->execute($userIds);
            $pdo->commit();

            if (function_exists('recordActivityAudit')) {
                recordActivityAudit($pdo, 'access_control', 'DELETE', 'users', 0, "Bulk Delete: " . count($userIds) . " pengguna dinonaktifkan.");
            }

            flash('success', "Berhasil menonaktifkan " . count($userIds) . " pengguna terpilih.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal menghapus pengguna massal: ' . $e->getMessage());
        }
        redirect('index.php?tab=users');
    }

    // 7. TAMBAH USER BARU (DENGAN DUKUNGAN MULTI-ROLE)
    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $selectedRoleIds = array_map('intval', $_POST['role_ids'] ?? []);
        $primaryRoleId = (int)($_POST['primary_role_id'] ?? 0);

        if (empty($selectedRoleIds) && !empty($_POST['role'])) {
            $stmtR = $pdo->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
            $stmtR->execute([$_POST['role']]);
            $rid = (int)$stmtR->fetchColumn();
            if ($rid > 0) {
                $selectedRoleIds = [$rid];
                $primaryRoleId = $rid;
            }
        }

        if (empty($selectedRoleIds)) {
            $stmtDef = $pdo->query("SELECT id FROM roles WHERE role_key = 'staff' LIMIT 1");
            $defId = (int)$stmtDef->fetchColumn();
            $selectedRoleIds = [$defId];
            $primaryRoleId = $defId;
        }

        if (!in_array($primaryRoleId, $selectedRoleIds, true)) {
            $primaryRoleId = $selectedRoleIds[0];
        }

        $stmtP = $pdo->prepare("SELECT role_key FROM roles WHERE id = ?");
        $stmtP->execute([$primaryRoleId]);
        $primaryRoleKey = $stmtP->fetchColumn() ?: 'staff';

        if ($name === '' || $username === '' || $password === '') {
            flash('error', 'Nama, Username, dan Password wajib diisi.');
        } else {
            $stmtCek = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmtCek->execute([$username]);
            if ($stmtCek->fetch()) {
                flash('error', 'Username "' . $username . '" sudah digunakan. Silakan gunakan username lain.');
            } else {
                $pdo->beginTransaction();
                try {
                    $hash = hashPassword($password);
                    $stmt = $pdo->prepare("INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $username, $hash, $primaryRoleKey]);
                    $newUserId = (int)$pdo->lastInsertId();

                    // Simpan ke user_roles
                    $stmtInsertUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, ?)");
                    foreach ($selectedRoleIds as $rid) {
                        $isPri = ($rid === $primaryRoleId) ? 1 : 0;
                        $stmtInsertUR->execute([$newUserId, $rid, $isPri]);
                    }

                    // Copy default permissions dari seluruh selected roles (Additive Union)
                    $placeholders = implode(',', array_fill(0, count($selectedRoleIds), '?'));
                    $stmtRoleAccess = $pdo->prepare("
                        SELECT menu_id, 
                               MAX(can_read) as can_read, 
                               MAX(can_create) as can_create, 
                               MAX(can_edit) as can_edit, 
                               MAX(can_delete) as can_delete
                        FROM role_menu_access 
                        WHERE role_id IN ($placeholders)
                        GROUP BY menu_id
                    ");
                    $stmtRoleAccess->execute($selectedRoleIds);
                    $roleAccessList = $stmtRoleAccess->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($roleAccessList)) {
                        $stmtUserMenu = $pdo->prepare("
                            INSERT INTO user_menu_access (user_id, menu_id, can_read, can_create, can_edit, can_delete) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        foreach ($roleAccessList as $ra) {
                            $stmtUserMenu->execute([
                                $newUserId, 
                                (int)$ra['menu_id'], 
                                (int)$ra['can_read'], 
                                (int)$ra['can_create'], 
                                (int)$ra['can_edit'], 
                                (int)$ra['can_delete']
                            ]);
                        }
                    }

                    $pdo->commit();
                    refreshUserPermissions($pdo, $newUserId);
                    if (function_exists('recordActivityAudit')) {
                        recordActivityAudit($pdo, 'access_control', 'CREATE', 'users', $newUserId, "Tambah Pengguna Baru: {$name} ({$username}) - Primary: {$primaryRoleKey}");
                    }
                    flash('success', 'Pengguna baru berhasil ditambahkan beserta multi-role & hak akses default!');
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    flash('error', 'Terjadi kesalahan: ' . $e->getMessage());
                }
            }
        }
        redirect('index.php?tab=users');
    }

    // 8. EDIT USER TUNGGAL LENGKAP (DENGAN DUKUNGAN MULTI-ROLE)
    if ($action === 'edit_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $selectedRoleIds = array_map('intval', $_POST['role_ids'] ?? []);
        $primaryRoleId = (int)($_POST['primary_role_id'] ?? 0);

        if (empty($selectedRoleIds) && !empty($_POST['role'])) {
            $stmtR = $pdo->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
            $stmtR->execute([$_POST['role']]);
            $rid = (int)$stmtR->fetchColumn();
            if ($rid > 0) {
                $selectedRoleIds = [$rid];
                $primaryRoleId = $rid;
            }
        }

        if ($uid > 0 && $name !== '' && $username !== '') {
            $stmtCek = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
            $stmtCek->execute([$username, $uid]);
            if ($stmtCek->fetch()) {
                flash('error', 'Username sudah dipakai oleh pengguna lain.');
            } else {
                $pdo->beginTransaction();
                try {
                    $primaryRoleKey = null;
                    if (!empty($selectedRoleIds)) {
                        if (!in_array($primaryRoleId, $selectedRoleIds, true)) {
                            $primaryRoleId = $selectedRoleIds[0];
                        }

                        $stmtP = $pdo->prepare("SELECT role_key FROM roles WHERE id = ?");
                        $stmtP->execute([$primaryRoleId]);
                        $primaryRoleKey = $stmtP->fetchColumn() ?: 'staff';

                        // Proteksi self-demote super_admin
                        if ($uid === $currentUserId && $primaryRoleKey !== 'super_admin') {
                            $stmtSA = $pdo->query("SELECT id FROM roles WHERE role_key = 'super_admin' LIMIT 1");
                            $saId = (int)$stmtSA->fetchColumn();
                            if (!in_array($saId, $selectedRoleIds, true)) {
                                throw new Exception('Anda tidak dapat menghapus wewenang Super Admin dari akun Anda sendiri.');
                            }
                        }

                        // Update user_roles
                        $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$uid]);
                        $stmtInsertUR = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, ?)");
                        foreach ($selectedRoleIds as $rid) {
                            $isPri = ($rid === $primaryRoleId) ? 1 : 0;
                            $stmtInsertUR->execute([$uid, $rid, $isPri]);
                        }
                    }

                    if (!empty($password)) {
                        $hash = hashPassword($password);
                        if ($primaryRoleKey) {
                            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ?, password = ? WHERE id = ?");
                            $stmt->execute([$name, $username, $primaryRoleKey, $hash, $uid]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, password = ? WHERE id = ?");
                            $stmt->execute([$name, $username, $hash, $uid]);
                        }
                    } else {
                        if ($primaryRoleKey) {
                            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ? WHERE id = ?");
                            $stmt->execute([$name, $username, $primaryRoleKey, $uid]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ? WHERE id = ?");
                            $stmt->execute([$name, $username, $uid]);
                        }
                    }

                    $pdo->commit();
                    refreshUserPermissions($pdo, $uid);
                    if (function_exists('recordActivityAudit')) {
                        recordActivityAudit($pdo, 'access_control', 'UPDATE', 'users', $uid, "Ubah Data Pengguna & Multi-Role #{$uid}: {$name} ({$username})");
                    }
                    flash('success', 'Data pengguna & multi-role berhasil diperbarui.');
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    flash('error', 'Gagal memperbarui data pengguna: ' . $e->getMessage());
                }
            }
        }
        redirect('index.php?tab=users');
    }

    // 9. HAPUS USER TUNGGAL
    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $currentUserId) {
            flash('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        } elseif ($uid === 1) {
            flash('error', 'Akun Root Super Admin #1 tidak dapat dihapus.');
        } elseif ($uid > 0) {
            $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$uid]);
            if (function_exists('recordActivityAudit')) {
                recordActivityAudit($pdo, 'access_control', 'DELETE', 'users', $uid, "Hapus/Nonaktifkan Pengguna #{$uid}");
            }
            flash('success', 'Pengguna berhasil dinonaktifkan/dihapus.');
        }
        redirect('index.php?tab=users');
    }

    // 10. BUAT ROLE BARU
    if ($action === 'create_role') {
        $roleName = trim($_POST['role_name'] ?? '');
        $roleKey = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $_POST['role_key'] ?? ''))));
        $desc = trim($_POST['description'] ?? '');
        $selectedMenuIds = array_map('intval', $_POST['menu_ids'] ?? []);

        if ($roleName === '' || $roleKey === '') {
            flash('error', 'Nama Role dan Kode Role wajib diisi.');
        } else {
            $stmtCek = $pdo->prepare("SELECT id FROM roles WHERE role_key = ? LIMIT 1");
            $stmtCek->execute([$roleKey]);
            if ($stmtCek->fetch()) {
                flash('error', 'Kode Role "' . $roleKey . '" sudah ada. Silakan gunakan kode lain.');
            } else {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("INSERT INTO roles (role_name, role_key, description, is_system) VALUES (?, ?, ?, 0)");
                    $stmt->execute([$roleName, $roleKey, $desc]);
                    $newRoleId = (int)$pdo->lastInsertId();

                    $permData = $_POST['perm'] ?? [];
                    if (!empty($permData) && is_array($permData)) {
                        $stmtRma = $pdo->prepare("INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($permData as $menuId => $actions) {
                            $mId = (int)$menuId;
                            $r = !empty($actions['read']) ? 1 : 0;
                            $c = !empty($actions['create']) ? 1 : 0;
                            $e = !empty($actions['edit']) ? 1 : 0;
                            $d = !empty($actions['delete']) ? 1 : 0;
                            if ($c || $e || $d) $r = 1;
                            if ($mId > 0 && ($r || $c || $e || $d)) {
                                $stmtRma->execute([$newRoleId, $mId, $r, $c, $e, $d]);
                            }
                        }
                    } elseif (!empty($selectedMenuIds)) {
                        $stmtRma = $pdo->prepare("INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete) VALUES (?, ?, 1, 0, 0, 0)");
                        foreach ($selectedMenuIds as $mid) {
                            if ($mid > 0) $stmtRma->execute([$newRoleId, $mid]);
                        }
                    }

                    $pdo->commit();
                    flash('success', 'Role baru "' . htmlspecialchars($roleName) . '" berhasil dibuat!');
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    flash('error', 'Gagal membuat role: ' . $e->getMessage());
                }
            }
        }
        redirect('index.php?tab=roles');
    }

    // 11. EDIT ROLE (UBAH NAMA & DESKRIPSI ROLE)
    if ($action === 'edit_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $roleName = trim($_POST['role_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if ($roleId <= 0 || $roleName === '') {
            flash('error', 'Nama role wajib diisi.');
        } else {
            $stmt = $pdo->prepare("UPDATE roles SET role_name = ?, description = ? WHERE id = ?");
            $stmt->execute([$roleName, $desc, $roleId]);
            if (function_exists('recordActivityAudit')) {
                recordActivityAudit($pdo, 'access_control', 'UPDATE', 'roles', $roleId, "Ubah Role #{$roleId}: {$roleName}");
            }
            flash('success', 'Data role berhasil diperbarui!');
        }
        redirect('index.php?tab=roles');
    }

    // 12. UPDATE DEFAULT ROLE MENU PERMISSIONS (DENGAN 2D CRUD MATRIX)
    if ($action === 'save_role_permissions') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $permData = $_POST['perm'] ?? [];
        $selectedMenuIds = array_map('intval', $_POST['menu_ids'] ?? []);

        if ($roleId > 0) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM role_menu_access WHERE role_id = ?")->execute([$roleId]);
                $stmtRma = $pdo->prepare("
                    INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                if (!empty($permData) && is_array($permData)) {
                    foreach ($permData as $menuId => $actions) {
                        $mId = (int)$menuId;
                        $r = !empty($actions['read']) ? 1 : 0;
                        $c = !empty($actions['create']) ? 1 : 0;
                        $e = !empty($actions['edit']) ? 1 : 0;
                        $d = !empty($actions['delete']) ? 1 : 0;
                        if ($c || $e || $d) {
                            $r = 1;
                        }
                        if ($mId > 0 && ($r || $c || $e || $d)) {
                            $stmtRma->execute([$roleId, $mId, $r, $c, $e, $d]);
                        }
                    }
                } elseif (!empty($selectedMenuIds)) {
                    foreach ($selectedMenuIds as $mid) {
                        if ($mid > 0) {
                            $stmtRma->execute([$roleId, $mid, 1, 0, 0, 0]);
                        }
                    }
                }

                $pdo->commit();
                if (function_exists('recordActivityAudit')) {
                    recordActivityAudit($pdo, 'access_control', 'UPDATE', 'role_menu_access', $roleId, "Perbarui Default Matriks CRUD Role #{$roleId}");
                }
                flash('success', 'Default matriks hak akses role (CRUD) berhasil diperbarui!');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', 'Gagal menyimpan: ' . $e->getMessage());
            }
        }
        redirect('index.php?tab=roles');
    }

    // 13. HAPUS ROLE
    if ($action === 'delete_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $stmtRole = $pdo->prepare("SELECT role_key, is_system FROM roles WHERE id = ?");
        $stmtRole->execute([$roleId]);
        $rData = $stmtRole->fetch();

        if (!$rData) {
            flash('error', 'Role tidak ditemukan.');
        } elseif ($rData['is_system'] == 1) {
            flash('error', 'Role sistem tidak dapat dihapus.');
        } else {
            $stmtCount = $pdo->prepare("
                SELECT (
                    (SELECT COUNT(*) FROM users WHERE role = ?) + 
                    (SELECT COUNT(*) FROM user_roles WHERE role_id = ?)
                ) as total
            ");
            $stmtCount->execute([$rData['role_key'], $roleId]);
            $countUsers = (int)$stmtCount->fetchColumn();

            if ($countUsers > 0) {
                flash('error', "Role tidak dapat dihapus karena masih digunakan oleh {$countUsers} pengguna.");
            } else {
                $pdo->prepare("DELETE FROM role_menu_access WHERE role_id = ?")->execute([$roleId]);
                $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$roleId]);
                flash('success', 'Role berhasil dihapus.');
            }
        }
        redirect('index.php?tab=roles');
    }

    // 14. TOGGLE AKSES DATA LEMBUR STAFF (KHUSUS SUPER ADMIN)
    if ($action === 'toggle_overtime_access') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            $stmtMId = $pdo->query("SELECT id FROM app_menus WHERE menu_key = 'reports_overtimes' LIMIT 1");
            $overtimeMenuId = (int)$stmtMId->fetchColumn();

            if ($overtimeMenuId > 0) {
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM user_menu_access WHERE user_id = ? AND menu_id = ?");
                $stmtCheck->execute([$targetUserId, $overtimeMenuId]);
                $hasAccess = (int)$stmtCheck->fetchColumn() > 0;

                $stmtUName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $stmtUName->execute([$targetUserId]);
                $uNameStr = $stmtUName->fetchColumn() ?: 'Pengguna';

                if ($hasAccess) {
                    $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ? AND menu_id = ?")->execute([$targetUserId, $overtimeMenuId]);
                    refreshUserPermissions($pdo, $targetUserId);
                    flash('success', "Akses Data Lembur Staff untuk \"{$uNameStr}\" telah DICABUT.");
                } else {
                    $pdo->prepare("INSERT IGNORE INTO user_menu_access (user_id, menu_id) VALUES (?, ?)")->execute([$targetUserId, $overtimeMenuId]);
                    refreshUserPermissions($pdo, $targetUserId);
                    flash('success', "Akses Data Lembur Staff untuk \"{$uNameStr}\" berhasil DIBERIKAN!");
                }
            }
        }
        redirect('index.php?tab=users');
    }
}

// =========================================================================
// QUERY DATA TAMPILAN
// =========================================================================
$activeTab = $_GET['tab'] ?? 'users';

// 1. Ambil Semua Role (Lengkap dengan Breakdown CRUD Permissions)
$roles = $pdo->query("
    SELECT r.*, 
           (
               SELECT COUNT(DISTINCT u.id) 
               FROM users u 
               LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.role_id = r.id
               WHERE (u.role COLLATE utf8mb4_general_ci = r.role_key OR ur.role_id IS NOT NULL) 
                 AND u.deleted_at IS NULL
           ) as total_users,
           (SELECT COUNT(*) FROM role_menu_access rma WHERE rma.role_id = r.id) as total_menus,
           (SELECT COUNT(*) FROM role_menu_access rma WHERE rma.role_id = r.id AND rma.can_read = 1) as total_read,
           (SELECT COUNT(*) FROM role_menu_access rma WHERE rma.role_id = r.id AND rma.can_create = 1) as total_create,
           (SELECT COUNT(*) FROM role_menu_access rma WHERE rma.role_id = r.id AND rma.can_edit = 1) as total_edit,
           (SELECT COUNT(*) FROM role_menu_access rma WHERE rma.role_id = r.id AND rma.can_delete = 1) as total_delete
    FROM roles r 
    ORDER BY r.is_system DESC, r.role_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Map Multi-Role untuk setiap User
$userRolesMap = [];
$stmtAllUR = $pdo->query("
    SELECT ur.user_id, r.id as role_id, r.role_key, r.role_name, ur.is_primary
    FROM user_roles ur
    INNER JOIN roles r ON r.id = ur.role_id
    ORDER BY ur.is_primary DESC, r.id ASC
");
while ($rRow = $stmtAllUR->fetch(PDO::FETCH_ASSOC)) {
    $uid = (int)$rRow['user_id'];
    if (!isset($userRolesMap[$uid])) {
        $userRolesMap[$uid] = [];
    }
    $userRolesMap[$uid][] = $rRow;
}

// 2. Statistik User per Role (untuk Filter Bar)
$stmtCounts = $pdo->query("
    SELECT role, COUNT(*) as cnt 
    FROM users 
    WHERE deleted_at IS NULL 
    GROUP BY role
");
$roleCounts = [];
$totalUsersCount = 0;
$totalStaffCount = 0;
while ($rc = $stmtCounts->fetch(PDO::FETCH_ASSOC)) {
    $rKey = $rc['role'];
    $cnt = (int)$rc['cnt'];
    $roleCounts[$rKey] = $cnt;
    $totalUsersCount += $cnt;
    if ($rKey !== 'siswa') {
        $totalStaffCount += $cnt;
    }
}

// 3. Filter Pengguna (Default: staff_only agar tabel ringan & ultra-cepat)
$roleFilter = trim($_GET['role_filter'] ?? 'staff_only');
$validFilterRoles = ['staff_only', 'all', 'super_admin', 'kepala_sekolah', 'admin', 'staff', 'hrd', 'siswa'];

$whereSql = "WHERE u.deleted_at IS NULL";
$queryParams = [];

if ($roleFilter === 'staff_only') {
    $whereSql .= " AND u.role != 'siswa'";
} elseif ($roleFilter !== 'all' && in_array($roleFilter, $validFilterRoles)) {
    $whereSql .= " AND u.role = ?";
    $queryParams[] = $roleFilter;
}

// 4. Ambil Daftar Pengguna
$stmtUsers = $pdo->prepare("
    SELECT u.id, u.name, u.username, u.role, u.photo, u.created_at,
           (SELECT COUNT(*) FROM user_menu_access uma WHERE uma.user_id = u.id) as active_menus,
           (SELECT COUNT(*) FROM user_menu_access uma2 
            JOIN app_menus am2 ON am2.id = uma2.menu_id 
            WHERE uma2.user_id = u.id AND am2.menu_key = 'reports_overtimes') as has_overtime_access
    FROM users u
    {$whereSql}
    ORDER BY u.id DESC
");
$stmtUsers->execute($queryParams);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// 5. Ambil Semua Menu Aktif untuk Checkbox (Dikelompokkan per Kategori)
$allMenus = $pdo->query("
    SELECT id, menu_key, menu_name, category, menu_url, menu_icon, sort_order
    FROM app_menus
    WHERE is_active = 1 AND menu_url != '#'
    ORDER BY sort_order ASC
")->fetchAll(PDO::FETCH_ASSOC);

$menusByCategory = [];
foreach ($allMenus as $m) {
    $cat = !empty($m['category']) ? strtoupper($m['category']) : 'LAINNYA';
    $menusByCategory[$cat][] = $m;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* Scoped Styling untuk Halaman Manajemen Akses */
.acl-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 20px;
}
.acl-tab-btn {
    padding: 12px 20px;
    font-weight: 600;
    font-size: 14px;
    color: #64748b;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
}
.acl-tab-btn:hover {
    color: var(--primary);
}
.acl-tab-btn.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    background: rgba(79, 70, 229, 0.04);
    border-radius: 8px 8px 0 0;
}

.role-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.role-badge.super_admin { background: #ede9fe; color: #6d28d9; }
.role-badge.kepala_sekolah { background: #e0f2fe; color: #0369a1; }
.role-badge.admin { background: #dcfce7; color: #15803d; }
.role-badge.staff { background: #f1f5f9; color: #475569; }
.role-badge.hrd { background: #fef3c7; color: #b45309; }
.role-badge.siswa { background: #e0e7ff; color: #3730a3; }
.role-badge.custom { background: #fef3c7; color: #b45309; }

/* Filter Role Badges / Pills */
.role-pills-bar {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 6px;
    margin-bottom: 16px;
    align-items: center;
}
.role-pill {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: all 0.2s;
}
.role-pill:hover {
    background: #e2e8f0;
    color: #0f172a;
}
.role-pill.active {
    background: var(--primary, #4f46e5);
    color: #ffffff;
    border-color: var(--primary, #4f46e5);
}

/* =========================================================================
   2D CRUD MATRIX & MULTI-ROLE ADVANCED STYLING
   ========================================================================= */
/* RESET CHECKBOXES SECARA KETAT DI SELURUH MATRIKS (ANTI-EXPAND IPAD / MOBILE) */
.crud-matrix-wrapper input[type="checkbox"],
.crud-matrix-table input[type="checkbox"],
input[type="checkbox"].crud-chk,
input[type="checkbox"].crud-header-chk,
input[type="checkbox"].crud-row-all {
    width: 19px !important;
    height: 19px !important;
    min-width: 19px !important;
    max-width: 19px !important;
    min-height: 19px !important;
    max-height: 19px !important;
    padding: 0 !important;
    margin: 0 auto !important;
    border: 1.5px solid #94a3b8 !important;
    border-radius: 4px !important;
    background-color: #ffffff !important;
    outline: none !important;
    box-shadow: none !important;
    cursor: pointer !important;
    display: inline-block !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
    -webkit-appearance: checkbox !important;
    appearance: checkbox !important;
}

.crud-matrix-wrapper {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.crud-matrix-toolbar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 10px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.crud-toolbar-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.crud-toolbar-search {
    display: flex;
    align-items: center;
    gap: 8px;
}
.crud-table-scroll {
    max-height: 52vh;
    overflow-y: auto;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.crud-matrix-table {
    width: 100%;
    min-width: 680px;
    border-collapse: collapse;
    font-size: 13px;
    table-layout: auto;
}
.crud-matrix-table thead th {
    position: sticky;
    top: 0;
    background: #f8fafc;
    color: #475569;
    padding: 10px 6px;
    border-bottom: 2px solid #cbd5e1;
    z-index: 10;
    vertical-align: middle;
}
.crud-cat-header-row td {
    background: #f1f5f9;
    padding: 8px 14px;
    border-top: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
}
.crud-cat-title {
    font-weight: 700;
    font-size: 11.5px;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.crud-row td {
    padding: 9px 8px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.crud-row:hover {
    background: #f8fafc;
}

/* Modern Stacked Header for CRUD Matrix */
.matrix-th-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
    user-select: none;
}
.matrix-th-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    text-align: center;
    line-height: 1.3;
}
.matrix-th-badge.badge-read {
    background: #e0f2fe;
    color: #0284c7;
    border: 1px solid #bae6fd;
}
.matrix-th-badge.badge-create {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}
.matrix-th-badge.badge-edit {
    background: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
}
.matrix-th-badge.badge-delete {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}
.matrix-th-badge.badge-all {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
}
.matrix-th-chk-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    margin: 0 !important;
    padding: 2px !important;
    border: none !important;
    background: transparent !important;
    width: auto !important;
}
.matrix-th-subtext {
    font-size: 10px;
    color: #64748b;
    font-weight: 600;
}

/* Distinct Accent Colors per Action */
.crud-chk.user-chk-read:checked,
.crud-chk.role-chk-read:checked,
.crud-chk.create-chk-read:checked,
.crud-header-chk[id*="_read"]:checked {
    accent-color: #0284c7 !important;
}
.crud-chk.user-chk-create:checked,
.crud-chk.role-chk-create:checked,
.crud-chk.create-chk-create:checked,
.crud-header-chk[id*="_create"]:checked {
    accent-color: #16a34a !important;
}
.crud-chk.user-chk-edit:checked,
.crud-chk.role-chk-edit:checked,
.crud-chk.create-chk-edit:checked,
.crud-header-chk[id*="_edit"]:checked {
    accent-color: #d97706 !important;
}
.crud-chk.user-chk-delete:checked,
.crud-chk.role-chk-delete:checked,
.crud-chk.create-chk-delete:checked,
.crud-header-chk[id*="_delete"]:checked {
    accent-color: #dc2626 !important;
}
.crud-row-all:checked {
    accent-color: #4f46e5 !important;
}

.badge-crud-pill {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    margin: 1px;
}
.badge-crud-read { background: #e0f2fe; color: #0369a1; }
.badge-crud-create { background: #dcfce7; color: #15803d; }
.badge-crud-edit { background: #fef3c7; color: #b45309; }
.badge-crud-delete { background: #fee2e2; color: #b91c1c; }

/* Multi-Role Badges in Users Table */
.multi-role-badges {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
}
.role-badge-secondary {
    font-size: 10.5px;
    padding: 2px 7px;
    border-radius: 12px;
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
    font-weight: 600;
}
.multi-role-option-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    transition: all 0.2s;
}
.multi-role-option-card:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.multi-role-option-card.active {
    border-color: var(--primary, #4f46e5);
    background: rgba(79, 70, 229, 0.03);
}
.primary-radio-label {
    display: inline-flex;
    align-items: center;
    cursor: pointer;
    margin: 0;
}
.multi-role-primary-radio {
    display: none;
}
.primary-radio-pill {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #cbd5e1;
    transition: all 0.15s;
    user-select: none;
}
.multi-role-primary-radio:checked + .primary-radio-pill {
    background: #10b981;
    color: #ffffff;
    border-color: #10b981;
    box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
}

/* Category Cards for Checkbox Grid */
.category-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
}
.category-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e2e8f0;
}
.category-title {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #475569;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 6px;
}
.menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px;
}
.menu-checkbox-item {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    padding: 10px 12px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.menu-checkbox-item:hover {
    border-color: var(--primary);
    background: #f8fafc;
}
.menu-checkbox-label {
    font-size: 13px;
    color: #1e293b;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Modal UI */
.custom-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.custom-modal-box {
    background: #ffffff;
    border-radius: 16px;
    width: 100%;
    max-width: 900px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    animation: modalSlideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideIn {
    from { opacity: 0; transform: scale(0.95) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.custom-modal-header {
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.custom-modal-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
}
.custom-modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: #f8fafc;
    border-radius: 0 0 16px 16px;
}

/* Floating Bulk Toolbar */
.bulk-toolbar {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    opacity: 0;
    pointer-events: none;
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    z-index: 1000;
    background: #0f172a;
    color: #f8fafc;
    padding: 10px 20px;
    border-radius: 50px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
    display: flex;
    align-items: center;
    gap: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.bulk-toolbar.active {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
    pointer-events: auto;
}
.bulk-counter-pill {
    background: rgba(255, 255, 255, 0.15);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    color: #38bdf8;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

@media (max-width: 768px) {
    .custom-modal-overlay {
        padding: 10px;
    }
    .custom-modal-box {
        width: 100%;
        max-height: calc(100vh - 20px);
        border-radius: 14px;
    }
    .custom-modal-header {
        padding: 14px 16px;
    }
    .custom-modal-body {
        padding: 16px 14px;
    }
    .custom-modal-footer {
        padding: 12px 14px;
        flex-wrap: wrap;
    }
    .custom-modal-footer .btn {
        width: 100%;
    }
    .acl-tabs {
        flex-direction: column;
    }
    .bulk-toolbar {
        width: calc(100% - 24px);
        left: 12px;
        transform: translateY(100px);
        border-radius: 16px;
        flex-wrap: wrap;
        padding: 12px;
    }
    .bulk-toolbar.active {
        transform: translateY(0);
    }
}
</style>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h2 style="margin: 0 0 5px 0; font-size: 22px; color: #1e293b;">
                <i class="fa-solid fa-shield-halved" style="color: var(--primary); margin-right: 8px;"></i>
                Manajemen Akses & Role Pengguna
            </h2>
            <p style="margin: 0; color: #64748b; font-size: 14px;">
                Atur hak akses menu dinamis, kelola aksi massal (bulk role), atau sesuaikan template role sistem.
            </p>
        </div>
        <div>
            <?php if ($activeTab === 'users'): ?>
                <button type="button" class="btn btn-primary" onclick="openModal('modalAddUser')">
                    <i class="fa-solid fa-user-plus"></i> Tambah Pengguna Baru
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-primary" onclick="openModal('modalCreateRole')">
                    <i class="fa-solid fa-plus-circle"></i> Buat Role Baru
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB NAVIGATION -->
    <div class="acl-tabs">
        <a href="?tab=users&role_filter=<?= urlencode($roleFilter) ?>" class="acl-tab-btn <?= $activeTab === 'users' ? 'active' : '' ?>">
            <i class="fa-solid fa-users"></i>
            Daftar Pengguna & Hak Akses (<?= count($users) ?>)
        </a>
        <a href="?tab=roles" class="acl-tab-btn <?= $activeTab === 'roles' ? 'active' : '' ?>">
            <i class="fa-solid fa-key"></i>
            Master Role & Default Akses (<?= count($roles) ?>)
        </a>
    </div>

    <?php if ($activeTab === 'users'): ?>
        <!-- ============================================================= -->
        <!-- TAB 1: DAFTAR PENGGUNA -->
        <!-- ============================================================= -->

        <!-- FILTER PILLS / ROLE QUICK SELECTOR -->
        <div class="role-pills-bar">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; margin-right: 4px;">
                <i class="fa-solid fa-filter"></i> Filter:
            </span>
            <a href="?tab=users&role_filter=staff_only" class="role-pill <?= $roleFilter === 'staff_only' ? 'active' : '' ?>" title="Menampilkan seluruh guru, staff, kepala sekolah, admin, dan super admin">
                <i class="fa-solid fa-user-tie"></i> Semua Staf & Manajemen (<?= $totalStaffCount ?>)
            </a>
            <a href="?tab=users&role_filter=all" class="role-pill <?= $roleFilter === 'all' ? 'active' : '' ?>" title="Menampilkan seluruh pengguna termasuk siswa">
                <i class="fa-solid fa-users-viewfinder"></i> Semua Pengguna (<?= $totalUsersCount ?>)
            </a>
            <?php foreach ($roles as $r): ?>
                <?php $cntR = $roleCounts[$r['role_key']] ?? 0; ?>
                <a href="?tab=users&role_filter=<?= urlencode($r['role_key']) ?>" class="role-pill <?= $roleFilter === $r['role_key'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($r['role_name']) ?> (<?= $cntR ?>)
                </a>
            <?php endforeach; ?>
            <a href="?tab=users&role_filter=siswa" class="role-pill <?= $roleFilter === 'siswa' ? 'active' : '' ?>">
                <i class="fa-solid fa-graduation-cap"></i> Siswa (<?= $roleCounts['siswa'] ?? 0 ?>)
            </a>
        </div>

        <!-- SEARCH & ACTION BAR -->
        <div style="margin-bottom: 16px; display: flex; gap: 12px; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div style="position: relative; max-width: 340px; width: 100%;">
                <input type="text" id="searchUser" placeholder="Cari nama, username, atau role..." 
                       style="width: 100%; padding: 8px 12px 8px 34px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;"
                       onkeyup="filterUsersTable()">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 12px; color: #94a3b8;"></i>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <span id="selectedCountInline" style="font-size: 13px; color: #64748b; font-weight: 600; display: none;">
                    <i class="fa-solid fa-check-circle" style="color: var(--primary);"></i> <span>0</span> terpilih
                </span>
                <button type="button" class="btn btn-secondary" onclick="resetUserFilter()" style="font-size: 13px;">
                    <i class="fa-solid fa-rotate-left"></i> Reset Cari
                </button>
            </div>
        </div>

        <div class="table-container" style="overflow-x: auto;">
            <table class="table" id="usersTable" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 12px 10px; width: 40px; text-align: center;">
                            <input type="checkbox" id="checkAllUsers" title="Pilih Semua di Halaman Ini" style="cursor: pointer; width: 16px; height: 16px;" onchange="toggleSelectAllUsers(this)">
                        </th>
                        <th style="padding: 12px 14px; text-align: left;">Pengguna</th>
                        <th style="padding: 12px 14px; text-align: left;">Username</th>
                        <th style="padding: 12px 14px; text-align: left;">Role</th>
                        <th style="padding: 12px 14px; text-align: center;">Menu Aktif</th>
                        <th style="padding: 12px 14px; text-align: center;">Akses Lembur (HRD)</th>
                        <th style="padding: 12px 14px; text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8;">
                                <i class="fa-solid fa-user-slash" style="font-size: 28px; margin-bottom: 8px; display: block;"></i>
                                Tidak ada data pengguna untuk filter saat ini.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($users as $u): ?>
                        <?php 
                            $badgeClass = in_array($u['role'], ['super_admin', 'kepala_sekolah', 'admin', 'staff', 'hrd', 'siswa']) ? $u['role'] : 'custom';
                            $roleLabel = ucwords(str_replace('_', ' ', $u['role']));
                            $avatar = !empty($u['photo']) ? UPLOAD_URL . '/admins/' . $u['photo'] : null;
                            $avatarFile = !empty($u['photo']) ? UPLOAD_PATH . '/admins/' . $u['photo'] : null;
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;" class="user-row" data-name="<?= strtolower($u['name']) ?>" data-username="<?= strtolower($u['username']) ?>" data-role="<?= $u['role'] ?>" id="row_user_<?= $u['id'] ?>">
                            <td style="padding: 12px 10px; text-align: center;">
                                <input type="checkbox" class="user-bulk-chk" value="<?= $u['id'] ?>" onchange="onUserCheckboxChange(this)" style="cursor: pointer; width: 16px; height: 16px;">
                            </td>
                            <td style="padding: 12px 14px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 38px; height: 38px; border-radius: 50%; background: #e0e7ff; color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: bold; overflow: hidden; flex-shrink: 0;">
                                        <?php if ($avatarFile && file_exists($avatarFile)): ?>
                                            <img src="<?= $avatar ?>" alt="Photo" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.onerror=null; this.src='<?= BASE_URL ?>/photo.php?type=admin&file=<?= urlencode($u['photo']) ?>&name=<?= urlencode($u['name']) ?>';">
                                        <?php else: ?>
                                            <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600; color: #1e293b; font-size: 14px;"><?= htmlspecialchars($u['name']) ?></div>
                                        <div style="font-size: 11px; color: #94a3b8;">ID: #<?= $u['id'] ?> &bull; Dibuat: <?= date('d M Y', strtotime($u['created_at'])) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding: 12px 14px; font-weight: 500; color: #475569; font-size: 14px;">
                                <code><?= htmlspecialchars($u['username']) ?></code>
                            </td>
                            <td style="padding: 12px 14px;">
                                <?php
                                    $assigned = $userRolesMap[$u['id']] ?? [];
                                ?>
                                <div class="multi-role-badges">
                                    <span class="role-badge <?= $badgeClass ?>">
                                        <i class="fa-solid fa-circle-dot" style="font-size: 8px;"></i>
                                        <?= htmlspecialchars($roleLabel) ?>
                                    </span>
                                    <?php foreach ($assigned as $aRole): ?>
                                        <?php if ((int)$aRole['is_primary'] !== 1 && $aRole['role_key'] !== $u['role']): ?>
                                            <span class="role-badge-secondary" title="Role Tambahan">
                                                + <?= htmlspecialchars($aRole['role_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <!-- TOMBOL ATUR MULTI-ROLE -->
                                    <button type="button" class="btn btn-sm btn-secondary" 
                                            onclick="openMultiRoleModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>', '<?= htmlspecialchars(addslashes($u['username'])) ?>')"
                                            style="padding: 2px 7px; font-size: 11px; border-radius: 12px; margin-left: 2px;"
                                            title="Atur Role & Multi-Role Pengguna">
                                        <i class="fa-solid fa-users-gear"></i>
                                    </button>
                                </div>
                            </td>
                            <td style="padding: 12px 14px; text-align: center;">
                                <?php if ($u['role'] === 'super_admin'): ?>
                                    <span style="display: inline-block; padding: 4px 10px; background: #ede9fe; color: #6d28d9; border-radius: 12px; font-size: 12px; font-weight: 700;">
                                        <i class="fa-solid fa-infinity"></i> Semua Akses
                                    </span>
                                <?php else: ?>
                                    <span style="display: inline-block; padding: 4px 10px; background: #f1f5f9; color: #334155; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                        <?= (int)$u['active_menus'] ?> menu
                                    </span>
                                <?php endif; ?>
                            </td>
                            <!-- STATUS & TOGGLE AKSES LEMBUR (HRD) -->
                            <td style="padding: 12px 14px; text-align: center;">
                                <?php if ($u['role'] === 'super_admin'): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; background: #ede9fe; color: #6d28d9; border-radius: 12px; font-size: 11.5px; font-weight: 700;">
                                        <i class="fa-solid fa-infinity"></i> Otomatis
                                    </span>
                                <?php else: 
                                    $hasOt = !empty($u['has_overtime_access']);
                                ?>
                                    <div style="display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                        <?php if ($hasOt): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; background: #dcfce7; color: #15803d; border-radius: 12px; font-size: 11.5px; font-weight: 700; border: 1px solid #bbf7d0;">
                                                <i class="fa-solid fa-check"></i> Ya
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; background: #f1f5f9; color: #64748b; border-radius: 12px; font-size: 11.5px; font-weight: 600; border: 1px solid #cbd5e1;">
                                                <i class="fa-solid fa-xmark"></i> Tidak
                                            </span>
                                        <?php endif; ?>

                                        <form method="POST" style="margin: 0; display: inline;">
                                            <input type="hidden" name="action" value="toggle_overtime_access">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $hasOt ? 'btn-secondary' : 'btn-primary' ?>" 
                                                    style="padding: 2px 8px; font-size: 11px; font-weight: 700;" 
                                                    title="<?= $hasOt ? 'Cabut izin melihat data lembur' : 'Beri izin melihat data & jumlah jam lembur staff' ?>">
                                                <?= $hasOt ? 'Cabut' : '+ Beri Akses' ?>
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 14px; text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <?php if ($u['role'] !== 'super_admin'): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openUserPermissionsModal(<?= $u['id'] ?>)" title="Atur Hak Akses Menu Manual">
                                            <i class="fa-solid fa-key"></i> Atur Akses
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-secondary" disabled title="Super Admin memiliki akses penuh otomatis">
                                            <i class="fa-solid fa-lock"></i> Root Admin
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u)) ?>)" title="Edit Profil Lengkap">
                                        <i class="fa-solid fa-user-pen"></i>
                                    </button>

                                    <?php if ($u['id'] !== $currentUserId && $u['id'] !== 1): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menonaktifkan/menghapus pengguna ini?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus Pengguna">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <!-- ============================================================= -->
        <!-- TAB 2: MASTER ROLE -->
        <!-- ============================================================= -->
        <div style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="margin: 0; font-size: 16px; color: #1e293b; font-weight: 700;">
                    <i class="fa-solid fa-shield-halved" style="color: var(--primary);"></i> Master Role & Matriks Default Akses
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                    Role dinamis memungkinkan pembuatan peran baru dengan kombinasi hak akses CRUD (Read, Create, Edit, Delete).
                </div>
            </div>
            <button type="button" class="btn btn-primary" onclick="openModal('modalCreateRole')" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-plus-circle"></i> Buat Role Baru
            </button>
        </div>

        <div class="table-container" style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 12px 14px; text-align: left;">Nama Role</th>
                        <th style="padding: 12px 14px; text-align: left;">Kode Sistem (Slug)</th>
                        <th style="padding: 12px 14px; text-align: left;">Deskripsi</th>
                        <th style="padding: 12px 14px; text-align: center;">Jumlah Pengguna</th>
                        <th style="padding: 12px 14px; text-align: center;">Default Menu</th>
                        <th style="padding: 12px 14px; text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px 14px;">
                                <div style="font-weight: 700; color: #1e293b; font-size: 14px;">
                                    <?= htmlspecialchars($r['role_name']) ?>
                                    <?php if ($r['is_system'] == 1): ?>
                                        <span style="font-size: 10px; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 6px; margin-left: 6px;">SISTEM</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="padding: 12px 14px;">
                                <code><?= htmlspecialchars($r['role_key']) ?></code>
                            </td>
                            <td style="padding: 12px 14px; color: #64748b; font-size: 13px;">
                                <?= htmlspecialchars($r['description'] ?? '-') ?>
                            </td>
                            <td style="padding: 12px 14px; text-align: center;">
                                <a href="?tab=users&role_filter=<?= urlencode($r['role_key']) ?>" style="font-weight: 600; color: var(--primary); text-decoration: none;">
                                    <?= (int)$r['total_users'] ?> orang
                                </a>
                            </td>
                            <td style="padding: 12px 14px; text-align: center;">
                                <?php if ($r['role_key'] === 'super_admin'): ?>
                                    <span style="background: #ede9fe; color: #6d28d9; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                        <i class="fa-solid fa-bolt"></i> Full Akses (Semua CRUD)
                                    </span>
                                <?php else: ?>
                                    <div style="display: inline-flex; flex-direction: column; gap: 4px; align-items: center;">
                                        <span style="background: #ede9fe; color: var(--primary); padding: 3px 8px; border-radius: 10px; font-size: 11.5px; font-weight: bold;">
                                            <?= (int)$r['total_menus'] ?> menu
                                        </span>
                                        <div style="display: flex; gap: 2px;">
                                            <span class="badge-crud-pill badge-crud-read" title="Izin Read: <?= (int)$r['total_read'] ?> menu">R:<?= (int)$r['total_read'] ?></span>
                                            <span class="badge-crud-pill badge-crud-create" title="Izin Create: <?= (int)$r['total_create'] ?> menu">C:<?= (int)$r['total_create'] ?></span>
                                            <span class="badge-crud-pill badge-crud-edit" title="Izin Edit: <?= (int)$r['total_edit'] ?> menu">E:<?= (int)$r['total_edit'] ?></span>
                                            <span class="badge-crud-pill badge-crud-delete" title="Izin Delete: <?= (int)$r['total_delete'] ?> menu">D:<?= (int)$r['total_delete'] ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 14px; text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <?php if ($r['role_key'] !== 'super_admin'): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openRolePermissionsModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['role_name'])) ?>', '<?= htmlspecialchars(addslashes($r['role_key'])) ?>')">
                                            <i class="fa-solid fa-table-cells"></i> Atur Matriks CRUD
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openEditRoleModal(<?= htmlspecialchars(json_encode($r)) ?>)" title="Ubah Nama & Deskripsi Role">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </button>

                                    <?php if ($r['is_system'] != 1): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus role ini?');">
                                            <input type="hidden" name="action" value="delete_role">
                                            <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus Role">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- FLOATING BULK ACTION TOOLBAR (AKSI MASSAL) -->
<!-- ========================================================================= -->
<div id="bulkToolbar" class="bulk-toolbar">
    <div class="bulk-counter-pill">
        <i class="fa-solid fa-check-double"></i>
        <span id="bulkSelectedCount">0</span> Pengguna Terpilih
    </div>
    <button type="button" class="btn btn-sm" style="background: #6366f1; color: #ffffff; border-radius: 20px; font-weight: 600;" onclick="openBulkChangeRoleModal()">
        <i class="fa-solid fa-user-tag"></i> Ubah Role Massal
    </button>
    <button type="button" class="btn btn-sm" style="background: #0284c7; color: #ffffff; border-radius: 20px; font-weight: 600;" onclick="submitBulkResetPermissions()">
        <i class="fa-solid fa-rotate-left"></i> Reset ke Default Role
    </button>
    <button type="button" class="btn btn-sm" style="background: #10b981; color: #ffffff; border-radius: 20px; font-weight: 600;" onclick="openBulkMenuActionModal()">
        <i class="fa-solid fa-list-check"></i> Beri/Cabut Menu
    </button>
    <button type="button" class="btn btn-sm" style="background: #ef4444; color: #ffffff; border-radius: 20px; font-weight: 600;" onclick="submitBulkDelete()">
        <i class="fa-solid fa-trash"></i> Hapus Terpilih
    </button>
    <button type="button" class="btn btn-sm" style="background: rgba(255,255,255,0.2); color: #ffffff; border-radius: 20px;" onclick="deselectAllUsers()">
        <i class="fa-solid fa-xmark"></i> Batal
    </button>
</div>

<!-- ========================================================================= -->
<!-- MODAL: QUICK CHANGE ROLE (GANTI ROLE CEPAT DENGAN OPSI MANUAL) -->
<!-- ========================================================================= -->
<div id="modalQuickChangeRole" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 520px;">
        <div class="custom-modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                    <i class="fa-solid fa-user-tag" style="color: var(--primary); margin-right: 8px;"></i>
                    Ubah Role Pengguna
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Pengguna: <strong id="quickRoleUserName" style="color: #1e293b;">-</strong> (<code id="quickRoleUserUsername">-</code>)
                </div>
            </div>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalQuickChangeRole')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="quick_change_role">
            <input type="hidden" name="user_id" id="quickRoleUserId" value="">

            <div class="custom-modal-body">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; color: #334155;">Pilih Role Baru *</label>
                    <select name="new_role" id="quickRoleSelect" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= htmlspecialchars($r['role_key']) ?>">
                                <?= htmlspecialchars($r['role_name']) ?> (<?= htmlspecialchars($r['role_key']) ?>)
                            </option>
                        <?php endforeach; ?>
                        <option value="siswa">Siswa (siswa)</option>
                    </select>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 13px; color: #334155;">Kebijakan Hak Akses Menu:</label>
                    
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <label style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; background: #f8fafc;">
                            <input type="radio" name="sync_policy" value="apply_defaults" checked style="margin-top: 3px;">
                            <div>
                                <strong style="font-size: 13px; color: #0f172a;">Terapkan Hak Akses Default Role Baru (Disarankan)</strong>
                                <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                    Menghapus izin kustom saat ini dan menyelaraskan dengan default template menu dari role baru.
                                </div>
                            </div>
                        </label>

                        <label style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; background: #ffffff;">
                            <input type="radio" name="sync_policy" value="keep_existing" style="margin-top: 3px;">
                            <div>
                                <strong style="font-size: 13px; color: #0f172a;">Pertahankan Hak Akses Menu Saat Ini</strong>
                                <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                    Hanya mengubah nama role pengguna tanpa merubah daftar menu aktif yang sudah disetting sebelumnya.
                                </div>
                            </div>
                        </label>

                        <label style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1.5px solid #6366f1; border-radius: 8px; cursor: pointer; background: #eef2ff;">
                            <input type="radio" name="sync_policy" value="manual_customize" style="margin-top: 3px;">
                            <div>
                                <strong style="font-size: 13px; color: #4338ca;">Ubah Role & Buka Checklist Setting Manual</strong>
                                <div style="font-size: 11.5px; color: #6366f1; margin-top: 2px;">
                                    Mengubah role pengguna dan langsung membuka jendela checklist menu untuk kustomisasi manual secara spesifik.
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalQuickChangeRole')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i> Simpan Role
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: BULK CHANGE ROLE (AKSI MASSAL GANTI ROLE) -->
<!-- ========================================================================= -->
<div id="modalBulkChangeRole" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 520px;">
        <div class="custom-modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                    <i class="fa-solid fa-users-gear" style="color: var(--primary); margin-right: 8px;"></i>
                    Ubah Role Massal (<span id="bulkChangeCount">0</span> Pengguna)
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Terapkan role baru ke seluruh pengguna terpilih sekaligus.
                </div>
            </div>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalBulkChangeRole')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="bulk_change_role">
            <input type="hidden" name="user_ids" id="bulkChangeUserIdsInput" value="">

            <div class="custom-modal-body">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">Pilih Role Baru *</label>
                    <select name="new_role" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= htmlspecialchars($r['role_key']) ?>">
                                <?= htmlspecialchars($r['role_name']) ?> (<?= htmlspecialchars($r['role_key']) ?>)
                            </option>
                        <?php endforeach; ?>
                        <option value="siswa">Siswa (siswa)</option>
                    </select>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 13px;">Kebijakan Hak Akses Menu Massal:</label>
                    
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <label style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; background: #f8fafc;">
                            <input type="radio" name="sync_policy" value="apply_defaults" checked style="margin-top: 3px;">
                            <div>
                                <strong style="font-size: 13px; color: #0f172a;">Terapkan Hak Akses Default Role Baru (Disarankan)</strong>
                                <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                    Semua pengguna terpilih akan mendapatkan hak akses menu sesuai template default dari role baru tersebut.
                                </div>
                            </div>
                        </label>

                        <label style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; background: #ffffff;">
                            <input type="radio" name="sync_policy" value="keep_existing" style="margin-top: 3px;">
                            <div>
                                <strong style="font-size: 13px; color: #0f172a;">Pertahankan Hak Akses Menu Masing-Masing</strong>
                                <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                    Hanya mengubah nama role mereka tanpa merubah daftar menu yang saat ini mereka miliki.
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalBulkChangeRole')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i> Eksekusi Ubah Role Massal
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: BULK MENU ACTION (BERI / CABUT MENU MASSAL) -->
<!-- ========================================================================= -->
<div id="modalBulkMenuAction" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <div class="custom-modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                    <i class="fa-solid fa-list-check" style="color: var(--primary); margin-right: 8px;"></i>
                    Atur Menu Massal (<span id="bulkMenuUserCount">0</span> Pengguna Terpilih)
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Berikan atau cabut akses menu tertentu sekaligus untuk seluruh pengguna terpilih.
                </div>
            </div>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalBulkMenuAction')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="bulk_menu_action">
            <input type="hidden" name="user_ids" id="bulkMenuUserIdsInput" value="">

            <div class="custom-modal-body">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <span style="font-weight: 700; font-size: 13px; color: #334155;">Pilih Jenis Aksi:</span>
                        <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #15803d;">
                            <input type="radio" name="sub_action" value="grant" checked>
                            <i class="fa-solid fa-plus-circle"></i> Berikan Menu (+Grant)
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #b91c1c;">
                            <input type="radio" name="sub_action" value="revoke">
                            <i class="fa-solid fa-minus-circle"></i> Cabut Menu (-Revoke)
                        </label>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleBulkMenuCheckboxes(true)">Pilih Semua</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleBulkMenuCheckboxes(false)">Kosongkan</button>
                    </div>
                </div>

                <!-- Checkbox Menu Per Kategori -->
                <?php foreach ($menusByCategory as $category => $items): ?>
                    <div class="category-card" data-category="<?= htmlspecialchars($category) ?>">
                        <div class="category-header">
                            <span class="category-title"><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($category) ?></span>
                            <label style="font-size: 11.5px; color: #0284c7; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; margin: 0;">
                                <input type="checkbox" class="cat-select-all" onchange="toggleCategoryCheckboxes(this)">
                                <span>Pilih Semua (<?= count($items) ?>)</span>
                            </label>
                        </div>
                        <div class="menu-grid">
                            <?php foreach ($items as $m): ?>
                                <?php
                                    $icon = !empty($m['menu_icon']) ? $m['menu_icon'] : 'fa-circle';
                                    if (strpos($icon, 'fa-') !== false && strpos($icon, 'fa-solid ') === false) {
                                        $icon = 'fa-solid ' . $icon;
                                    }
                                    $isOtMenu = ($m['menu_key'] === 'reports_overtimes');
                                ?>
                                <label class="menu-checkbox-item" <?= $isOtMenu ? 'style="border: 1.5px solid #f59e0b; background: #fffbeb;"' : '' ?>>
                                    <input type="checkbox" name="menu_ids[]" value="<?= $m['id'] ?>" class="bulk-menu-chk" onchange="syncCategoryCheckboxes()">
                                    <span class="menu-checkbox-label">
                                        <i class="<?= htmlspecialchars($icon) ?>" style="color: <?= $isOtMenu ? '#d97706' : 'var(--primary)' ?>; width: 16px; text-align: center;"></i>
                                        <?= htmlspecialchars($m['menu_name']) ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalBulkMenuAction')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check-double"></i> Terapkan Perubahan Menu
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: EDIT ROLE (UBAH NAMA & DESKRIPSI ROLE) -->
<!-- ========================================================================= -->
<div id="modalEditRole" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 500px;">
        <div class="custom-modal-header">
            <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                <i class="fa-solid fa-pen-to-square" style="color: var(--primary); margin-right: 8px;"></i>
                Edit Data Role
            </h3>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalEditRole')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="edit_role">
            <input type="hidden" name="role_id" id="editRoleId" value="">

            <div class="custom-modal-body">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Nama Role *</label>
                    <input type="text" name="role_name" id="editRoleName" required
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Kode Sistem (Slug)</label>
                    <input type="text" id="editRoleKey" disabled
                           style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #64748b; box-sizing: border-box;">
                    <small style="color: #94a3b8; display: block; margin-top: 4px;">Kode sistem tidak dapat diubah demi konsistensi kode sistem.</small>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Deskripsi / Keterangan</label>
                    <textarea name="description" id="editRoleDescription" rows="3"
                              style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;"></textarea>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditRole')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Role
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ATUR HAK AKSES PENGGUNA MANUAL (CHECKBOX UI) -->
<!-- ========================================================================= -->
<!-- MODAL: KELOLA MULTI-ROLE PENGGUNA -->
<!-- ========================================================================= -->
<div id="modalMultiRole" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 660px;">
        <div class="custom-modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                    <i class="fa-solid fa-users-gear" style="color: var(--primary); margin-right: 8px;"></i>
                    Kelola Role & Multi-Role: <span id="multiRoleUserName" style="color: var(--primary);">-</span>
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Username: <code id="multiRoleUserUsername">-</code> &bull; Tetapkan satu atau beberapa peran sekaligus untuk pengguna ini.
                </div>
            </div>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalMultiRole')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_user_roles">
            <input type="hidden" name="user_id" id="multiRoleUserId" value="">

            <div class="custom-modal-body">
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 12px 16px; margin-bottom: 18px;">
                    <div style="font-weight: 700; color: #1e40af; font-size: 13px; margin-bottom: 4px;">
                        <i class="fa-solid fa-shield-halved"></i> Prinsip Multi-Role (Additive Union)
                    </div>
                    <div style="font-size: 12px; color: #1e3a8a; line-height: 1.5;">
                        Pengguna akan memiliki akumulasi hak akses dari <strong>seluruh role</strong> yang dicentang. Hak akses menu dan wewenang CRUD akan digabung secara otomatis. Tentukan salah satu sebagai <strong>Role Utama</strong> untuk identitas tampilan.
                    </div>
                </div>

                <div style="margin-bottom: 12px; font-weight: 700; color: #334155; font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
                    <span>Pilih Role yang Ditugaskan:</span>
                    <span style="font-size: 11.5px; font-weight: 600; color: #64748b;">Tandai "Utama" di sebelah kanan</span>
                </div>

                <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px;">
                    <?php foreach ($roles as $r): ?>
                        <div class="multi-role-option-card" id="role_card_<?= $r['id'] ?>">
                            <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; flex: 1; margin: 0;">
                                    <input type="checkbox" name="role_ids[]" value="<?= $r['id'] ?>" class="multi-role-chk" id="mr_chk_<?= $r['id'] ?>" onchange="onMultiRoleCheckChange(<?= $r['id'] ?>)">
                                    <div>
                                        <div style="font-weight: 700; font-size: 13.5px; color: #1e293b;">
                                            <?= htmlspecialchars($r['role_name']) ?>
                                            <code>(<?= htmlspecialchars($r['role_key']) ?>)</code>
                                            <?php if ($r['is_system'] == 1): ?>
                                                <span style="font-size: 10px; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 6px; margin-left: 4px;">SISTEM</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 11.5px; color: #64748b;">
                                            <?= htmlspecialchars($r['description'] ?? 'Tidak ada keterangan') ?>
                                        </div>
                                    </div>
                                </label>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <label class="primary-radio-label" title="Jadikan role ini sebagai Role Utama pengguna">
                                        <input type="radio" name="primary_role_id" value="<?= $r['id'] ?>" class="multi-role-primary-radio" id="mr_radio_<?= $r['id'] ?>" onchange="onPrimaryRadioChange(<?= $r['id'] ?>)">
                                        <span class="primary-radio-pill"><i class="fa-solid fa-star"></i> Utama</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalMultiRole')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Multi-Role
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ATUR HAK AKSES PENGGUNA MANUAL (2D CRUD MATRIX) -->
<!-- ========================================================================= -->
<div id="modalUserPermissions" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 960px;">
        <div class="custom-modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                    <i class="fa-solid fa-table-cells" style="color: var(--primary); margin-right: 8px;"></i>
                    Matriks Hak Akses: <span id="modalTargetUserName" style="color: var(--primary);">Loading...</span>
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Role: <strong id="modalTargetUserRole">-</strong> &bull; Atur wewenang granular per menu: <strong>Read (Lihat)</strong>, <strong>Create (Tambah)</strong>, <strong>Edit (Ubah)</strong>, <strong>Delete (Hapus)</strong>.
                </div>
            </div>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalUserPermissions')">
                <i class="fa-solid fa-xmark" style="font-size: 18px;"></i>
            </button>
        </div>

        <form method="POST" id="formUserPermissions">
            <input type="hidden" name="action" value="save_user_permissions">
            <input type="hidden" name="target_user_id" id="targetUserIdInput" value="">

            <div class="custom-modal-body">
                <div class="crud-matrix-wrapper">
                    <!-- TOOLBAR AKSI PRESET & PENCARIAN -->
                    <div class="crud-matrix-toolbar">
                        <div class="crud-toolbar-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="applyMatrixPreset('user', 'full')" title="Beri akses penuh (Read, Create, Edit, Delete) ke seluruh menu">
                                <i class="fa-solid fa-check-double" style="color: #10b981;"></i> Pilih Semua (Full CRUD)
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="applyMatrixPreset('user', 'readonly')" title="Beri izin Read saja ke semua menu">
                                <i class="fa-solid fa-eye" style="color: #0284c7;"></i> Hanya Lihat (View Only)
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="applyMatrixPreset('user', 'empty')" title="Kosongkan seluruh izin">
                                <i class="fa-solid fa-xmark" style="color: #ef4444;"></i> Kosongkan
                            </button>
                        </div>
                        <div class="crud-toolbar-search">
                            <div style="position: relative;">
                                <input type="text" id="searchMenuInputUser" placeholder="Cari modul / menu..." onkeyup="filterMatrixRows('user', this.value)" style="padding: 6px 12px 6px 28px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; width: 170px;">
                                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 9px; top: 9px; color: #94a3b8; font-size: 11px;"></i>
                            </div>
                            <select id="roleTemplateSelector" style="padding: 5px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px;" onchange="applyRoleTemplateToMatrix(this.value)">
                                <option value="">-- Template Role --</option>
                                <?php foreach ($roles as $r): ?>
                                    <?php if ($r['role_key'] !== 'super_admin'): ?>
                                        <option value="<?= htmlspecialchars($r['role_key']) ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- TABEL MATRIKS CRUD -->
                    <div class="crud-table-scroll">
                        <table class="crud-matrix-table" id="tableMatrixUser">
                            <thead>
                                <tr>
                                    <th style="min-width: 250px; text-align: left; padding: 12px 14px;">Modul / Fitur Aplikasi</th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-read">READ</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Read">
                                                <input type="checkbox" id="user_col_read" class="crud-header-chk" onchange="toggleMatrixCol('user', 'read', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-create">CREATE</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Create">
                                                <input type="checkbox" id="user_col_create" class="crud-header-chk" onchange="toggleMatrixCol('user', 'create', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-edit">EDIT</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Edit">
                                                <input type="checkbox" id="user_col_edit" class="crud-header-chk" onchange="toggleMatrixCol('user', 'edit', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-delete">DELETE</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Delete">
                                                <input type="checkbox" id="user_col_delete" class="crud-header-chk" onchange="toggleMatrixCol('user', 'delete', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 75px; min-width: 70px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-all">SEMUA</span>
                                            <span class="matrix-th-subtext">Baris</span>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($menusByCategory as $category => $items): ?>
                                    <tr class="crud-cat-header-row user-cat-row" data-cat="<?= htmlspecialchars($category) ?>">
                                        <td colspan="6">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <span class="crud-cat-title"><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($category) ?></span>
                                                <span style="font-size: 11px; color: #64748b; font-weight: 600;"><?= count($items) ?> menu</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php foreach ($items as $m): ?>
                                        <?php
                                            $icon = !empty($m['menu_icon']) ? $m['menu_icon'] : 'fa-circle';
                                            if (strpos($icon, 'fa-') !== false && strpos($icon, 'fa-solid ') === false) {
                                                $icon = 'fa-solid ' . $icon;
                                            }
                                            $isOtMenu = ($m['menu_key'] === 'reports_overtimes');
                                        ?>
                                        <tr class="crud-row user-crud-row" data-menuid="<?= $m['id'] ?>" data-name="<?= htmlspecialchars(strtolower($m['menu_name'])) ?>" data-key="<?= htmlspecialchars(strtolower($m['menu_key'])) ?>" data-cat="<?= htmlspecialchars($category) ?>" <?= $isOtMenu ? 'style="background: #fffbeb;"' : '' ?>>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <i class="<?= htmlspecialchars($icon) ?>" style="color: <?= $isOtMenu ? '#d97706' : 'var(--primary)' ?>; width: 18px; text-align: center; font-size: 15px;"></i>
                                                    <div>
                                                        <div style="font-weight: 600; color: #1e293b; font-size: 13.5px;">
                                                            <?= htmlspecialchars($m['menu_name']) ?>
                                                            <?php if ($isOtMenu): ?>
                                                                <span style="font-size: 10px; background: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px; font-weight: 700; border: 1px solid #fde68a; margin-left: 4px;">⭐ HRD / Lembur</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div style="font-size: 11px; color: #94a3b8;"><code><?= htmlspecialchars($m['menu_key']) ?></code></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][read]" value="1" class="crud-chk user-chk-read" id="user_perm_<?= $m['id'] ?>_read" onchange="onCrudCheckboxChange('user', <?= $m['id'] ?>, 'read')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][create]" value="1" class="crud-chk user-chk-create" id="user_perm_<?= $m['id'] ?>_create" onchange="onCrudCheckboxChange('user', <?= $m['id'] ?>, 'create')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][edit]" value="1" class="crud-chk user-chk-edit" id="user_perm_<?= $m['id'] ?>_edit" onchange="onCrudCheckboxChange('user', <?= $m['id'] ?>, 'edit')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][delete]" value="1" class="crud-chk user-chk-delete" id="user_perm_<?= $m['id'] ?>_delete" onchange="onCrudCheckboxChange('user', <?= $m['id'] ?>, 'delete')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" class="crud-row-all user-chk-row-all" id="user_row_all_<?= $m['id'] ?>" onchange="toggleMatrixRow('user', <?= $m['id'] ?>, this.checked)">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalUserPermissions')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Matriks Hak Akses
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ATUR DEFAULT MATRIKS AKSES ROLE (2D CRUD MATRIX) -->
<!-- ========================================================================= -->
<div id="modalRolePermissions" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 960px;">
        <div class="custom-modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                    <i class="fa-solid fa-table-cells" style="color: var(--primary); margin-right: 8px;"></i>
                    Atur Matriks Default Akses: <span id="modalRoleNameText" style="color: var(--primary);">-</span>
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Setiap user yang diberi role ini akan otomatis mendapatkan hak akses CRUD di bawah ini.
                </div>
            </div>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalRolePermissions')">
                <i class="fa-solid fa-xmark" style="font-size: 18px;"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_role_permissions">
            <input type="hidden" name="role_id" id="targetRoleIdInput" value="">

            <div class="custom-modal-body">
                <div class="crud-matrix-wrapper">
                    <!-- TOOLBAR AKSI PRESET -->
                    <div class="crud-matrix-toolbar">
                        <div class="crud-toolbar-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="applyMatrixPreset('role', 'full')">
                                <i class="fa-solid fa-check-double" style="color: #10b981;"></i> Pilih Semua (Full CRUD)
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="applyMatrixPreset('role', 'readonly')">
                                <i class="fa-solid fa-eye" style="color: #0284c7;"></i> Hanya Lihat (View Only)
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="applyMatrixPreset('role', 'empty')">
                                <i class="fa-solid fa-xmark" style="color: #ef4444;"></i> Kosongkan
                            </button>
                        </div>
                        <div class="crud-toolbar-search">
                            <div style="position: relative;">
                                <input type="text" id="searchMenuInputRole" placeholder="Cari modul / menu..." onkeyup="filterMatrixRows('role', this.value)" style="padding: 6px 12px 6px 28px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; width: 200px;">
                                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 9px; top: 9px; color: #94a3b8; font-size: 11px;"></i>
                            </div>
                        </div>
                    </div>

                    <!-- TABEL MATRIKS CRUD ROLE -->
                    <div class="crud-table-scroll">
                        <table class="crud-matrix-table" id="tableMatrixRole">
                            <thead>
                                <tr>
                                    <th style="min-width: 250px; text-align: left; padding: 12px 14px;">Modul / Fitur Aplikasi</th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-read">READ</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Read">
                                                <input type="checkbox" id="role_col_read" class="crud-header-chk" onchange="toggleMatrixCol('role', 'read', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-create">CREATE</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Create">
                                                <input type="checkbox" id="role_col_create" class="crud-header-chk" onchange="toggleMatrixCol('role', 'create', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-edit">EDIT</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Edit">
                                                <input type="checkbox" id="role_col_edit" class="crud-header-chk" onchange="toggleMatrixCol('role', 'edit', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-delete">DELETE</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Delete">
                                                <input type="checkbox" id="role_col_delete" class="crud-header-chk" onchange="toggleMatrixCol('role', 'delete', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 75px; min-width: 70px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-all">SEMUA</span>
                                            <span class="matrix-th-subtext">Baris</span>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($menusByCategory as $category => $items): ?>
                                    <tr class="crud-cat-header-row role-cat-row" data-cat="<?= htmlspecialchars($category) ?>">
                                        <td colspan="6">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <span class="crud-cat-title"><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($category) ?></span>
                                                <span style="font-size: 11px; color: #64748b; font-weight: 600;"><?= count($items) ?> menu</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php foreach ($items as $m): ?>
                                        <?php
                                            $icon = !empty($m['menu_icon']) ? $m['menu_icon'] : 'fa-circle';
                                            if (strpos($icon, 'fa-') !== false && strpos($icon, 'fa-solid ') === false) {
                                                $icon = 'fa-solid ' . $icon;
                                            }
                                            $isOtMenu = ($m['menu_key'] === 'reports_overtimes');
                                        ?>
                                        <tr class="crud-row role-crud-row" data-menuid="<?= $m['id'] ?>" data-name="<?= htmlspecialchars(strtolower($m['menu_name'])) ?>" data-key="<?= htmlspecialchars(strtolower($m['menu_key'])) ?>" data-cat="<?= htmlspecialchars($category) ?>">
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <i class="<?= htmlspecialchars($icon) ?>" style="color: <?= $isOtMenu ? '#d97706' : 'var(--primary)' ?>; width: 18px; text-align: center; font-size: 15px;"></i>
                                                    <div>
                                                        <div style="font-weight: 600; color: #1e293b; font-size: 13.5px;"><?= htmlspecialchars($m['menu_name']) ?></div>
                                                        <div style="font-size: 11px; color: #94a3b8;"><code><?= htmlspecialchars($m['menu_key']) ?></code></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][read]" value="1" class="crud-chk role-chk-read" id="role_perm_<?= $m['id'] ?>_read" onchange="onCrudCheckboxChange('role', <?= $m['id'] ?>, 'read')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][create]" value="1" class="crud-chk role-chk-create" id="role_perm_<?= $m['id'] ?>_create" onchange="onCrudCheckboxChange('role', <?= $m['id'] ?>, 'create')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][edit]" value="1" class="crud-chk role-chk-edit" id="role_perm_<?= $m['id'] ?>_edit" onchange="onCrudCheckboxChange('role', <?= $m['id'] ?>, 'edit')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][delete]" value="1" class="crud-chk role-chk-delete" id="role_perm_<?= $m['id'] ?>_delete" onchange="onCrudCheckboxChange('role', <?= $m['id'] ?>, 'delete')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" class="crud-row-all role-chk-row-all" id="role_row_all_<?= $m['id'] ?>" onchange="toggleMatrixRow('role', <?= $m['id'] ?>, this.checked)">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalRolePermissions')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Matriks Role
                </button>
            </div>
        </form>
    </div>
</div>
<!-- ========================================================================= -->
<!-- MODAL: TAMBAH USER BARU -->
<!-- ========================================================================= -->
<div id="modalAddUser" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 620px;">
        <div class="custom-modal-header">
            <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                <i class="fa-solid fa-user-plus" style="color: var(--primary); margin-right: 8px;"></i>
                Tambah Pengguna Baru
            </h3>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalAddUser')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            <div class="custom-modal-body">
                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Nama Lengkap *</label>
                    <input type="text" name="name" required placeholder="Contoh: Budi Santoso, S.Kom"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div class="form-group">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px;">Username *</label>
                        <input type="text" name="username" required placeholder="Contoh: budi_santoso"
                               style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                    </div>
                    <div class="form-group">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px;">Password *</label>
                        <input type="password" name="password" required placeholder="Minimal 6 karakter"
                               style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-weight: 600; margin: 0; color: #1e293b; font-size: 13.5px;">
                            <i class="fa-solid fa-users-gear" style="color: var(--primary); margin-right: 4px;"></i>
                            Pilih Role (Bisa lebih dari satu role) *
                        </label>
                        <span style="font-size: 11px; color: #64748b; font-weight: 600;">
                            <i class="fa-solid fa-star" style="color: #10b981;"></i> Tandai Role Utama
                        </span>
                    </div>

                    <div style="max-height: 200px; overflow-y: auto; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 8px; background: #f8fafc; display: flex; flex-direction: column; gap: 6px;">
                        <?php foreach ($roles as $r): ?>
                            <?php $isStaff = ($r['role_key'] === 'staff'); ?>
                            <div class="add-role-card" id="add_role_card_<?= $r['id'] ?>" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; transition: all 0.15s;">
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin: 0; flex: 1;">
                                    <input type="checkbox" name="role_ids[]" value="<?= $r['id'] ?>" class="add-user-role-chk" id="aur_chk_<?= $r['id'] ?>" <?= $isStaff ? 'checked' : '' ?> onchange="onAddUserRoleChange(<?= $r['id'] ?>)">
                                    <div>
                                        <div style="font-weight: 700; font-size: 13px; color: #1e293b;">
                                            <?= htmlspecialchars($r['role_name']) ?>
                                            <code style="font-size: 11px; color: #64748b;">(<?= htmlspecialchars($r['role_key']) ?>)</code>
                                        </div>
                                    </div>
                                </label>
                                <div>
                                    <label class="primary-radio-label" title="Jadikan role utama">
                                        <input type="radio" name="primary_role_id" value="<?= $r['id'] ?>" class="multi-role-primary-radio add-user-primary-radio" id="aur_radio_<?= $r['id'] ?>" <?= $isStaff ? 'checked' : '' ?> onchange="onAddUserPrimaryRadioChange(<?= $r['id'] ?>)">
                                        <span class="primary-radio-pill" style="font-size: 10px; padding: 3px 8px;"><i class="fa-solid fa-star"></i> Utama</span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small style="color: #64748b; margin-top: 6px; display: block; font-size: 11.5px;">
                        <i class="fa-solid fa-circle-info" style="color: #3b82f6;"></i> Hak akses menu akan otomatis diselaraskan secara gabungan (<em>additive union</em>) dari seluruh role yang dicentang.
                    </small>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalAddUser')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i> Tambah Pengguna
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: EDIT USER LENGKAP -->
<!-- ========================================================================= -->
<div id="modalEditUser" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 620px;">
        <div class="custom-modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                    <i class="fa-solid fa-user-pen" style="color: var(--primary); margin-right: 8px;"></i>
                    Edit Data Pengguna & Multi-Role
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Kelola profil dan tetapkan satu atau beberapa peran sekaligus untuk pengguna ini.
                </div>
            </div>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalEditUser')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId" value="">
            <div class="custom-modal-body">
                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Nama Lengkap *</label>
                    <input type="text" name="name" id="editName" required
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div class="form-group">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px;">Username *</label>
                        <input type="text" name="username" id="editUsername" required
                               style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                    </div>

                    <div class="form-group">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px;">Password Baru</label>
                        <input type="password" name="password" placeholder="Kosongkan jika tidak diganti"
                               style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="font-weight: 600; margin: 0; color: #1e293b; font-size: 13.5px;">
                            <i class="fa-solid fa-users-gear" style="color: var(--primary); margin-right: 4px;"></i>
                            Role Pengguna (Bisa pilih lebih dari satu role) *
                        </label>
                        <span style="font-size: 11px; color: #64748b; font-weight: 600;">
                            <i class="fa-solid fa-star" style="color: #10b981;"></i> Tandai Role Utama
                        </span>
                    </div>

                    <div class="edit-user-roles-container" style="max-height: 210px; overflow-y: auto; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 8px; background: #f8fafc; display: flex; flex-direction: column; gap: 6px;">
                        <?php foreach ($roles as $r): ?>
                            <div class="edit-role-card" id="edit_role_card_<?= $r['id'] ?>" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; transition: all 0.15s;">
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin: 0; flex: 1;">
                                    <input type="checkbox" name="role_ids[]" value="<?= $r['id'] ?>" class="edit-user-role-chk" id="eur_chk_<?= $r['id'] ?>" onchange="onEditUserRoleChange(<?= $r['id'] ?>)">
                                    <div>
                                        <div style="font-weight: 700; font-size: 13px; color: #1e293b;">
                                            <?= htmlspecialchars($r['role_name']) ?>
                                            <code style="font-size: 11px; color: #64748b;">(<?= htmlspecialchars($r['role_key']) ?>)</code>
                                            <?php if ($r['is_system'] == 1): ?>
                                                <span style="font-size: 9.5px; background: #e2e8f0; color: #475569; padding: 1px 5px; border-radius: 4px; margin-left: 3px;">SISTEM</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($r['description'])): ?>
                                            <div style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($r['description']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </label>
                                <div>
                                    <label class="primary-radio-label" title="Jadikan role utama pengguna">
                                        <input type="radio" name="primary_role_id" value="<?= $r['id'] ?>" class="multi-role-primary-radio edit-user-primary-radio" id="eur_radio_<?= $r['id'] ?>" onchange="onEditUserPrimaryRadioChange(<?= $r['id'] ?>)">
                                        <span class="primary-radio-pill" style="font-size: 10px; padding: 3px 8px;"><i class="fa-solid fa-star"></i> Utama</span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small style="color: #64748b; margin-top: 6px; display: block; font-size: 11.5px; line-height: 1.4;">
                        <i class="fa-solid fa-circle-info" style="color: #3b82f6;"></i> Centang satu atau beberapa peran. Hak akses menu dan wewenang CRUD akan digabung secara otomatis (<em>additive union</em>).
                    </small>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditUser')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ========================================================================= -->
<!-- MODAL: BUAT ROLE BARU DENGAN 2D CRUD MATRIX -->
<!-- ========================================================================= -->
<div id="modalCreateRole" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 960px;">
        <div class="custom-modal-header">
            <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                <i class="fa-solid fa-plus-circle" style="color: var(--primary); margin-right: 8px;"></i>
                Buat Role Baru & Tentukan Default Matriks Akses
            </h3>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalCreateRole')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="create_role">
            <div class="custom-modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div class="form-group">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px;">Nama Role *</label>
                        <input type="text" name="role_name" required placeholder="Misal: Guru Piket, Mentor MIP, HRD"
                               style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;"
                               onkeyup="autoGenerateRoleKey(this.value)">
                    </div>
                    <div class="form-group">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px;">Kode Sistem (Slug) *</label>
                        <input type="text" name="role_key" id="newRoleKeyInput" required placeholder="Misal: guru_piket, mentor_mip"
                               style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Deskripsi / Keterangan</label>
                    <input type="text" name="description" placeholder="Contoh: Mengatur pembinaan adab santri dan pemantauan harian"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 12px; font-weight: 700; color: #334155; font-size: 14px;">
                    Tentukan Default Hak Akses CRUD untuk Role Baru Ini:
                </div>

                <div class="crud-matrix-wrapper">
                    <div class="crud-matrix-toolbar">
                        <div class="crud-toolbar-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="applyMatrixPreset('create', 'full')">
                                <i class="fa-solid fa-check-double" style="color: #10b981;"></i> Pilih Semua (Full CRUD)
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="applyMatrixPreset('create', 'readonly')">
                                <i class="fa-solid fa-eye" style="color: #0284c7;"></i> Hanya Lihat (View Only)
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="applyMatrixPreset('create', 'empty')">
                                <i class="fa-solid fa-xmark" style="color: #ef4444;"></i> Kosongkan
                            </button>
                        </div>
                    </div>

                    <div class="crud-table-scroll" style="max-height: 40vh;">
                        <table class="crud-matrix-table" id="tableMatrixCreate">
                            <thead>
                                <tr>
                                    <th style="min-width: 250px; text-align: left; padding: 12px 14px;">Modul / Fitur Aplikasi</th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-read">READ</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Read">
                                                <input type="checkbox" id="create_col_read" class="crud-header-chk" onchange="toggleMatrixCol('create', 'read', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-create">CREATE</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Create">
                                                <input type="checkbox" id="create_col_create" class="crud-header-chk" onchange="toggleMatrixCol('create', 'create', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-edit">EDIT</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Edit">
                                                <input type="checkbox" id="create_col_edit" class="crud-header-chk" onchange="toggleMatrixCol('create', 'edit', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 85px; min-width: 80px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-delete">DELETE</span>
                                            <label class="matrix-th-chk-wrap" title="Pilih Semua Delete">
                                                <input type="checkbox" id="create_col_delete" class="crud-header-chk" onchange="toggleMatrixCol('create', 'delete', this.checked)">
                                            </label>
                                        </div>
                                    </th>
                                    <th style="width: 75px; min-width: 70px; text-align: center; padding: 8px 4px;">
                                        <div class="matrix-th-col">
                                            <span class="matrix-th-badge badge-all">SEMUA</span>
                                            <span class="matrix-th-subtext">Baris</span>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($menusByCategory as $category => $items): ?>
                                    <tr class="crud-cat-header-row create-cat-row">
                                        <td colspan="6">
                                            <span class="crud-cat-title"><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($category) ?></span>
                                        </td>
                                    </tr>
                                    <?php foreach ($items as $m): ?>
                                        <tr class="crud-row create-crud-row" data-menuid="<?= $m['id'] ?>">
                                            <td>
                                                <div style="font-weight: 600; color: #1e293b; font-size: 13.5px;"><?= htmlspecialchars($m['menu_name']) ?></div>
                                                <div style="font-size: 11px; color: #94a3b8;"><code><?= htmlspecialchars($m['menu_key']) ?></code></div>
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][read]" value="1" class="crud-chk create-chk-read" id="create_perm_<?= $m['id'] ?>_read" onchange="onCrudCheckboxChange('create', <?= $m['id'] ?>, 'read')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][create]" value="1" class="crud-chk create-chk-create" id="create_perm_<?= $m['id'] ?>_create" onchange="onCrudCheckboxChange('create', <?= $m['id'] ?>, 'create')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][edit]" value="1" class="crud-chk create-chk-edit" id="create_perm_<?= $m['id'] ?>_edit" onchange="onCrudCheckboxChange('create', <?= $m['id'] ?>, 'edit')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="perm[<?= $m['id'] ?>][delete]" value="1" class="crud-chk create-chk-delete" id="create_perm_<?= $m['id'] ?>_delete" onchange="onCrudCheckboxChange('create', <?= $m['id'] ?>, 'delete')">
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" class="crud-row-all create-chk-row-all" id="create_row_all_<?= $m['id'] ?>" onchange="toggleMatrixRow('create', <?= $m['id'] ?>, this.checked)">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalCreateRole')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i> Buat Role & Simpan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// =========================================================================
// MODAL GENERAL CONTROLLER
// =========================================================================
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.style.display = 'none';
    }
}

window.addEventListener('click', function(e) {
    if (e.target.classList.contains('custom-modal-overlay')) {
        e.target.style.display = 'none';
    }
});

// =========================================================================
// BULK ACTION CHECKBOX CONTROLLER
// =========================================================================
function getSelectedUserCheckboxes() {
    return Array.from(document.querySelectorAll('.user-bulk-chk:checked'));
}

function getSelectedUserIds() {
    return getSelectedUserCheckboxes().map(chk => parseInt(chk.value)).filter(id => id > 0);
}

function updateBulkToolbarState() {
    const checked = getSelectedUserCheckboxes();
    const count = checked.length;
    const toolbar = document.getElementById('bulkToolbar');
    const counterText = document.getElementById('bulkSelectedCount');
    const inlineCounter = document.getElementById('selectedCountInline');

    if (counterText) counterText.innerText = count;
    if (inlineCounter) {
        if (count > 0) {
            inlineCounter.style.display = 'inline-block';
            inlineCounter.querySelector('span').innerText = count;
        } else {
            inlineCounter.style.display = 'none';
        }
    }

    if (toolbar) {
        if (count > 0) {
            toolbar.classList.add('active');
        } else {
            toolbar.classList.remove('active');
        }
    }

    document.querySelectorAll('.user-bulk-chk').forEach(chk => {
        const row = chk.closest('tr');
        if (row) {
            row.style.background = chk.checked ? '#f0fdf4' : '';
        }
    });

    const allRowChks = Array.from(document.querySelectorAll('.user-bulk-chk'));
    const checkAllHeader = document.getElementById('checkAllUsers');
    if (checkAllHeader && allRowChks.length > 0) {
        const visibleChks = allRowChks.filter(c => c.closest('tr').style.display !== 'none');
        const visibleChecked = visibleChks.filter(c => c.checked);
        checkAllHeader.checked = (visibleChecked.length > 0 && visibleChecked.length === visibleChks.length);
        checkAllHeader.indeterminate = (visibleChecked.length > 0 && visibleChecked.length < visibleChks.length);
    }
}

function onUserCheckboxChange(chk) {
    updateBulkToolbarState();
}

function toggleSelectAllUsers(headerCheckbox) {
    const isChecked = headerCheckbox.checked;
    document.querySelectorAll('.user-bulk-chk').forEach(chk => {
        const row = chk.closest('tr');
        if (row && row.style.display !== 'none') {
            chk.checked = isChecked;
        }
    });
    updateBulkToolbarState();
}

function deselectAllUsers() {
    document.querySelectorAll('.user-bulk-chk').forEach(chk => chk.checked = false);
    const headerChk = document.getElementById('checkAllUsers');
    if (headerChk) {
        headerChk.checked = false;
        headerChk.indeterminate = false;
    }
    updateBulkToolbarState();
}

function openBulkChangeRoleModal() {
    const ids = getSelectedUserIds();
    if (ids.length === 0) {
        alert('Silakan centang minimal satu pengguna.');
        return;
    }
    document.getElementById('bulkChangeCount').innerText = ids.length;
    document.getElementById('bulkChangeUserIdsInput').value = JSON.stringify(ids);
    openModal('modalBulkChangeRole');
}

function openBulkMenuActionModal(actionType) {
    const ids = getSelectedUserIds();
    if (ids.length === 0) {
        alert('Silakan centang minimal satu pengguna.');
        return;
    }
    document.getElementById('bulkMenuActionType').value = actionType;
    document.getElementById('bulkMenuActionCount').innerText = ids.length;
    document.getElementById('bulkMenuActionUserIds').value = JSON.stringify(ids);
    
    const titleEl = document.getElementById('bulkMenuActionTitle');
    const noteEl = document.getElementById('bulkMenuActionNote');
    if (actionType === 'grant') {
        titleEl.innerHTML = '<i class="fa-solid fa-plus-circle" style="color: #10b981; margin-right: 8px;"></i> Tambah Izin Menu Massal';
        noteEl.innerHTML = 'Centang menu yang ingin <strong>DITAMBAHKAN</strong> ke ' + ids.length + ' pengguna terpilih (izin menu mereka sebelumnya tetap aman).';
    } else {
        titleEl.innerHTML = '<i class="fa-solid fa-minus-circle" style="color: #ef4444; margin-right: 8px;"></i> Cabut Izin Menu Massal';
        noteEl.innerHTML = 'Centang menu yang ingin <strong>DICABUT</strong> dari ' + ids.length + ' pengguna terpilih.';
    }
    document.querySelectorAll('.bulk-menu-chk').forEach(chk => chk.checked = false);
    openModal('modalBulkMenuAction');
}

function submitBulkResetPermissions() {
    const ids = getSelectedUserIds();
    if (ids.length === 0) {
        alert('Silakan centang minimal satu pengguna.');
        return;
    }
    if (confirm(`Apakah Anda yakin ingin me-reset hak akses ${ids.length} pengguna terpilih ke DEFAULT ROLE mereka masing-masing?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="bulk_reset_permissions">
            <input type="hidden" name="user_ids" value='${JSON.stringify(ids)}'>
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function submitBulkDelete() {
    const ids = getSelectedUserIds();
    if (ids.length === 0) {
        alert('Silakan centang minimal satu pengguna.');
        return;
    }
    if (confirm(`PERINGATAN: Apakah Anda yakin ingin menonaktifkan/menghapus ${ids.length} pengguna terpilih?

(Catatan: Akun Super Admin yang sedang aktif login tidak akan dihapus demi keamanan sistem).`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="bulk_delete_users">
            <input type="hidden" name="user_ids" value='${JSON.stringify(ids)}'>
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// =========================================================================
// MULTI-ROLE MODAL CONTROLLER
// =========================================================================
function openMultiRoleModal(userId, userName, userUsername) {
    document.getElementById('multiRoleUserId').value = userId;
    document.getElementById('multiRoleUserName').innerText = userName;
    document.getElementById('multiRoleUserUsername').innerText = userUsername;

    // Reset all checkboxes & radios
    document.querySelectorAll('.multi-role-chk').forEach(c => c.checked = false);
    document.querySelectorAll('.multi-role-primary-radio').forEach(r => r.checked = false);
    document.querySelectorAll('.multi-role-option-card').forEach(card => card.classList.remove('active'));

    openModal('modalMultiRole');

    fetch('index.php?action=get_user_roles&user_id=' + userId)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const assigned = data.assigned_role_ids || [];
                const primaryId = data.primary_role_id || 0;

                assigned.forEach(rid => {
                    const chk = document.getElementById('mr_chk_' + rid);
                    if (chk) {
                        chk.checked = true;
                        const card = document.getElementById('role_card_' + rid);
                        if (card) card.classList.add('active');
                    }
                });

                if (primaryId > 0) {
                    const rRadio = document.getElementById('mr_radio_' + primaryId);
                    if (rRadio) rRadio.checked = true;
                } else if (assigned.length > 0) {
                    const rRadio = document.getElementById('mr_radio_' + assigned[0]);
                    if (rRadio) rRadio.checked = true;
                }
            }
        })
        .catch(err => {
            console.error(err);
            alert('Gagal mengambil data role pengguna.');
        });
}

function onMultiRoleCheckChange(roleId) {
    const chk = document.getElementById('mr_chk_' + roleId);
    const card = document.getElementById('role_card_' + roleId);
    const radio = document.getElementById('mr_radio_' + roleId);

    if (chk && chk.checked) {
        if (card) card.classList.add('active');
        // Jika belum ada radio primary yang dicentang, centang ini
        const hasCheckedRadio = Array.from(document.querySelectorAll('.multi-role-primary-radio')).some(r => r.checked);
        if (!hasCheckedRadio && radio) {
            radio.checked = true;
        }
    } else {
        if (card) card.classList.remove('active');
        if (radio && radio.checked) {
            radio.checked = false;
            // Pilih role lain yang aktif sebagai primary
            const firstActiveChk = document.querySelector('.multi-role-chk:checked');
            if (firstActiveChk) {
                const otherRadio = document.getElementById('mr_radio_' + firstActiveChk.value);
                if (otherRadio) otherRadio.checked = true;
            }
        }
    }
}

function onPrimaryRadioChange(roleId) {
    // Otomatis centang role tersebut jika dijadikan primary
    const chk = document.getElementById('mr_chk_' + roleId);
    if (chk && !chk.checked) {
        chk.checked = true;
        onMultiRoleCheckChange(roleId);
    }
}

// =========================================================================
// 2D CRUD MATRIX LOGIC & EVENT HANDLERS
// =========================================================================

// Preset: Full CRUD, Read Only, Empty
function applyMatrixPreset(target, mode) {
    const rows = document.querySelectorAll(`.${target}-crud-row`);
    rows.forEach(row => {
        if (row.style.display === 'none') return; // Lewati jika tersembunyi karena search
        const mId = row.getAttribute('data-menuid');
        const chkR = document.getElementById(`${target}_perm_${mId}_read`);
        const chkC = document.getElementById(`${target}_perm_${mId}_create`);
        const chkE = document.getElementById(`${target}_perm_${mId}_edit`);
        const chkD = document.getElementById(`${target}_perm_${mId}_delete`);
        const chkAll = document.getElementById(`${target}_row_all_${mId}`);

        if (mode === 'full') {
            if (chkR) chkR.checked = true;
            if (chkC) chkC.checked = true;
            if (chkE) chkE.checked = true;
            if (chkD) chkD.checked = true;
            if (chkAll) chkAll.checked = true;
        } else if (mode === 'readonly') {
            if (chkR) chkR.checked = true;
            if (chkC) chkC.checked = false;
            if (chkE) chkE.checked = false;
            if (chkD) chkD.checked = false;
            if (chkAll) chkAll.checked = false;
        } else if (mode === 'empty') {
            if (chkR) chkR.checked = false;
            if (chkC) chkC.checked = false;
            if (chkE) chkE.checked = false;
            if (chkD) chkD.checked = false;
            if (chkAll) chkAll.checked = false;
        }
    });
    syncMatrixColHeaders(target);
}

// Toggle Kolom (Read, Create, Edit, Delete)
function toggleMatrixCol(target, action, isChecked) {
    const chks = document.querySelectorAll(`.${target}-chk-${action}`);
    chks.forEach(chk => {
        const row = chk.closest('tr');
        if (row && row.style.display !== 'none') {
            chk.checked = isChecked;
            const mId = row.getAttribute('data-menuid');
            // Jika Create/Edit/Delete dicentang, otomatis aktifkan Read
            if (isChecked && action !== 'read') {
                const readChk = document.getElementById(`${target}_perm_${mId}_read`);
                if (readChk) readChk.checked = true;
            }
            syncMatrixRowState(target, mId);
        }
    });
    syncMatrixColHeaders(target);
}

// Toggle Baris (Semua CRUD pada satu baris menu)
function toggleMatrixRow(target, menuId, isChecked) {
    const chkR = document.getElementById(`${target}_perm_${menuId}_read`);
    const chkC = document.getElementById(`${target}_perm_${menuId}_create`);
    const chkE = document.getElementById(`${target}_perm_${menuId}_edit`);
    const chkD = document.getElementById(`${target}_perm_${menuId}_delete`);

    if (chkR) chkR.checked = isChecked;
    if (chkC) chkC.checked = isChecked;
    if (chkE) chkE.checked = isChecked;
    if (chkD) chkD.checked = isChecked;

    syncMatrixColHeaders(target);
}

// Perubahan pada Checkbox CRUD individu
function onCrudCheckboxChange(target, menuId, action) {
    const chk = document.getElementById(`${target}_perm_${menuId}_${action}`);
    const chkR = document.getElementById(`${target}_perm_${menuId}_read`);

    // Aturan RBAC: Tidak bisa Create/Edit/Delete tanpa hak Read
    if (chk && chk.checked && action !== 'read') {
        if (chkR) chkR.checked = true;
    }
    // Jika Read dimatikan, otomatis matikan Create/Edit/Delete
    if (action === 'read' && chk && !chk.checked) {
        const chkC = document.getElementById(`${target}_perm_${menuId}_create`);
        const chkE = document.getElementById(`${target}_perm_${menuId}_edit`);
        const chkD = document.getElementById(`${target}_perm_${menuId}_delete`);
        if (chkC) chkC.checked = false;
        if (chkE) chkE.checked = false;
        if (chkD) chkD.checked = false;
    }

    syncMatrixRowState(target, menuId);
    syncMatrixColHeaders(target);
}

// Sinkronisasi status checkbox "Semua" pada baris
function syncMatrixRowState(target, menuId) {
    const chkR = document.getElementById(`${target}_perm_${menuId}_read`);
    const chkC = document.getElementById(`${target}_perm_${menuId}_create`);
    const chkE = document.getElementById(`${target}_perm_${menuId}_edit`);
    const chkD = document.getElementById(`${target}_perm_${menuId}_delete`);
    const chkAll = document.getElementById(`${target}_row_all_${menuId}`);

    if (chkAll) {
        const allChecked = (chkR && chkR.checked) && (chkC && chkC.checked) && (chkE && chkE.checked) && (chkD && chkD.checked);
        const someChecked = (chkR && chkR.checked) || (chkC && chkC.checked) || (chkE && chkE.checked) || (chkD && chkD.checked);
        chkAll.checked = allChecked;
        chkAll.indeterminate = !allChecked && someChecked;
    }
}

// Sinkronisasi status checkbox di header kolom
function syncMatrixColHeaders(target) {
    ['read', 'create', 'edit', 'delete'].forEach(act => {
        const headerChk = document.getElementById(`${target}_col_${act}`);
        if (!headerChk) return;
        const colChks = Array.from(document.querySelectorAll(`.${target}-chk-${act}`)).filter(c => c.closest('tr').style.display !== 'none');
        if (colChks.length === 0) return;
        const checkedCount = colChks.filter(c => c.checked).length;
        headerChk.checked = (checkedCount === colChks.length);
        headerChk.indeterminate = (checkedCount > 0 && checkedCount < colChks.length);
    });
}

// Live Search di Matriks
function filterMatrixRows(target, query) {
    const q = (query || '').toLowerCase().trim();
    const rows = document.querySelectorAll(`.${target}-crud-row`);
    const catRows = document.querySelectorAll(`.${target}-cat-row`);

    const activeCats = new Set();
    rows.forEach(r => {
        const name = r.getAttribute('data-name') || '';
        const key = r.getAttribute('data-key') || '';
        const cat = r.getAttribute('data-cat') || '';

        if (!q || name.includes(q) || key.includes(q) || cat.toLowerCase().includes(q)) {
            r.style.display = '';
            activeCats.add(cat);
        } else {
            r.style.display = 'none';
        }
    });

    catRows.forEach(cr => {
        const cat = cr.getAttribute('data-cat') || '';
        cr.style.display = activeCats.has(cat) ? '' : 'none';
    });

    syncMatrixColHeaders(target);
}

// =========================================================================
// BUKA MODAL USER PERMISSIONS (2D MATRIX)
// =========================================================================
function openUserPermissionsModal(userId) {
    document.getElementById('targetUserIdInput').value = userId;
    document.getElementById('modalTargetUserName').innerText = 'Memuat...';
    document.getElementById('modalTargetUserRole').innerText = '-';
    
    const searchInput = document.getElementById('searchMenuInputUser');
    if (searchInput) {
        searchInput.value = '';
        filterMatrixRows('user', '');
    }
    applyMatrixPreset('user', 'empty');
    document.getElementById('roleTemplateSelector').value = '';

    openModal('modalUserPermissions');

    fetch('index.php?action=get_user_permissions&user_id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('modalTargetUserName').innerText = data.user.name;
                
                // Format label role (termasuk multi-role jika ada)
                let roleStr = data.user.role.toUpperCase();
                if (data.roles && data.roles.length > 1) {
                    const extraNames = data.roles.filter(r => r.role_key !== data.user.role).map(r => r.role_name);
                    if (extraNames.length > 0) {
                        roleStr += ' (+' + extraNames.join(', ') + ')';
                    }
                }
                document.getElementById('modalTargetUserRole').innerText = roleStr;
                
                const perms = data.permissions || {};
                for (const [mId, p] of Object.entries(perms)) {
                    const chkR = document.getElementById(`user_perm_${mId}_read`);
                    const chkC = document.getElementById(`user_perm_${mId}_create`);
                    const chkE = document.getElementById(`user_perm_${mId}_edit`);
                    const chkD = document.getElementById(`user_perm_${mId}_delete`);

                    if (chkR) chkR.checked = (p.read === 1);
                    if (chkC) chkC.checked = (p.create === 1);
                    if (chkE) chkE.checked = (p.edit === 1);
                    if (chkD) chkD.checked = (p.delete === 1);

                    syncMatrixRowState('user', mId);
                }
                syncMatrixColHeaders('user');
            } else {
                alert('Gagal mengambil data hak akses pengguna.');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Terjadi kesalahan koneksi.');
        });
}

// Terapkan Template Role ke Matriks User
function applyRoleTemplateToMatrix(roleKey) {
    if (!roleKey) return;
    fetch('index.php?action=get_role_permissions&role_key=' + encodeURIComponent(roleKey))
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                applyMatrixPreset('user', 'empty');
                const perms = data.permissions || {};
                for (const [mId, p] of Object.entries(perms)) {
                    const chkR = document.getElementById(`user_perm_${mId}_read`);
                    const chkC = document.getElementById(`user_perm_${mId}_create`);
                    const chkE = document.getElementById(`user_perm_${mId}_edit`);
                    const chkD = document.getElementById(`user_perm_${mId}_delete`);

                    if (chkR) chkR.checked = (p.read === 1);
                    if (chkC) chkC.checked = (p.create === 1);
                    if (chkE) chkE.checked = (p.edit === 1);
                    if (chkD) chkD.checked = (p.delete === 1);

                    syncMatrixRowState('user', mId);
                }
                syncMatrixColHeaders('user');
            }
        });
}

// =========================================================================
// BUKA MODAL ROLE PERMISSIONS (2D MATRIX)
// =========================================================================
function openRolePermissionsModal(roleId, roleName, roleKey) {
    document.getElementById('targetRoleIdInput').value = roleId;
    document.getElementById('modalRoleNameText').innerText = roleName + ' (' + roleKey + ')';

    const searchInput = document.getElementById('searchMenuInputRole');
    if (searchInput) {
        searchInput.value = '';
        filterMatrixRows('role', '');
    }
    applyMatrixPreset('role', 'empty');
    openModal('modalRolePermissions');

    fetch('index.php?action=get_role_permissions&role_id=' + roleId + '&role_key=' + encodeURIComponent(roleKey))
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const perms = data.permissions || {};
                for (const [mId, p] of Object.entries(perms)) {
                    const chkR = document.getElementById(`role_perm_${mId}_read`);
                    const chkC = document.getElementById(`role_perm_${mId}_create`);
                    const chkE = document.getElementById(`role_perm_${mId}_edit`);
                    const chkD = document.getElementById(`role_perm_${mId}_delete`);

                    if (chkR) chkR.checked = (p.read === 1);
                    if (chkC) chkC.checked = (p.create === 1);
                    if (chkE) chkE.checked = (p.edit === 1);
                    if (chkD) chkD.checked = (p.delete === 1);

                    syncMatrixRowState('role', mId);
                }
                syncMatrixColHeaders('role');
            }
        })
        .catch(err => console.error(err));
}

// Buka Modal Edit Role
function openEditRoleModal(role) {
    document.getElementById('editRoleId').value = role.id;
    document.getElementById('editRoleName').value = role.role_name;
    document.getElementById('editRoleKey').value = role.role_key;
    document.getElementById('editRoleDescription').value = role.description || '';
    openModal('modalEditRole');
}

// Buka Modal Edit User Lengkap (Multi-Role Aware)
function openEditUserModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editName').value = user.name;
    document.getElementById('editUsername').value = user.username;

    // Reset password input
    const passInput = document.querySelector('#modalEditUser input[name="password"]');
    if (passInput) passInput.value = '';

    // Reset all edit checkboxes and radios
    document.querySelectorAll('.edit-user-role-chk').forEach(c => c.checked = false);
    document.querySelectorAll('.edit-user-primary-radio').forEach(r => r.checked = false);
    document.querySelectorAll('.edit-role-card').forEach(card => {
        card.style.borderColor = '#e2e8f0';
        card.style.background = '#ffffff';
    });

    openModal('modalEditUser');

    // Fetch existing assigned roles
    fetch('index.php?action=get_user_roles&user_id=' + user.id)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const assigned = data.assigned_role_ids || [];
                const primaryId = data.primary_role_id || 0;

                assigned.forEach(rid => {
                    const chk = document.getElementById('eur_chk_' + rid);
                    if (chk) {
                        chk.checked = true;
                        const card = document.getElementById('edit_role_card_' + rid);
                        if (card) {
                            card.style.borderColor = 'var(--primary, #4f46e5)';
                            card.style.background = 'rgba(79, 70, 229, 0.04)';
                        }
                    }
                });

                if (primaryId > 0) {
                    const rRadio = document.getElementById('eur_radio_' + primaryId);
                    if (rRadio) rRadio.checked = true;
                } else if (assigned.length > 0) {
                    const rRadio = document.getElementById('eur_radio_' + assigned[0]);
                    if (rRadio) rRadio.checked = true;
                }
            }
        })
        .catch(err => {
            console.error('Error fetching user roles:', err);
        });
}

function onEditUserRoleChange(roleId) {
    const chk = document.getElementById('eur_chk_' + roleId);
    const card = document.getElementById('edit_role_card_' + roleId);
    const radio = document.getElementById('eur_radio_' + roleId);

    if (chk && chk.checked) {
        if (card) {
            card.style.borderColor = 'var(--primary, #4f46e5)';
            card.style.background = 'rgba(79, 70, 229, 0.04)';
        }
        const hasCheckedRadio = Array.from(document.querySelectorAll('.edit-user-primary-radio')).some(r => r.checked);
        if (!hasCheckedRadio && radio) {
            radio.checked = true;
        }
    } else {
        if (card) {
            card.style.borderColor = '#e2e8f0';
            card.style.background = '#ffffff';
        }
        if (radio && radio.checked) {
            radio.checked = false;
            const firstActiveChk = document.querySelector('.edit-user-role-chk:checked');
            if (firstActiveChk) {
                const otherRadio = document.getElementById('eur_radio_' + firstActiveChk.value);
                if (otherRadio) otherRadio.checked = true;
            }
        }
    }
}

function onEditUserPrimaryRadioChange(roleId) {
    const chk = document.getElementById('eur_chk_' + roleId);
    if (chk && !chk.checked) {
        chk.checked = true;
        onEditUserRoleChange(roleId);
    }
}

function onAddUserRoleChange(roleId) {
    const chk = document.getElementById('aur_chk_' + roleId);
    const card = document.getElementById('add_role_card_' + roleId);
    const radio = document.getElementById('aur_radio_' + roleId);

    if (chk && chk.checked) {
        if (card) {
            card.style.borderColor = 'var(--primary, #4f46e5)';
            card.style.background = 'rgba(79, 70, 229, 0.04)';
        }
        const hasCheckedRadio = Array.from(document.querySelectorAll('.add-user-primary-radio')).some(r => r.checked);
        if (!hasCheckedRadio && radio) {
            radio.checked = true;
        }
    } else {
        if (card) {
            card.style.borderColor = '#e2e8f0';
            card.style.background = '#ffffff';
        }
        if (radio && radio.checked) {
            radio.checked = false;
            const firstActiveChk = document.querySelector('.add-user-role-chk:checked');
            if (firstActiveChk) {
                const otherRadio = document.getElementById('aur_radio_' + firstActiveChk.value);
                if (otherRadio) otherRadio.checked = true;
            }
        }
    }
}

function onAddUserPrimaryRadioChange(roleId) {
    const chk = document.getElementById('aur_chk_' + roleId);
    if (chk && !chk.checked) {
        chk.checked = true;
        onAddUserRoleChange(roleId);
    }
}

// Otomatis generate slug role
function autoGenerateRoleKey(val) {
    const slug = val.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
    document.getElementById('newRoleKeyInput').value = slug;
}

// Filter tabel user
function filterUsersTable() {
    const q = document.getElementById('searchUser').value.toLowerCase();
    document.querySelectorAll('.user-row').forEach(row => {
        const name = row.getAttribute('data-name');
        const username = row.getAttribute('data-username');
        const role = row.getAttribute('data-role');
        if (name.includes(q) || username.includes(q) || role.includes(q)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    updateBulkToolbarState();
}

function resetUserFilter() {
    document.getElementById('searchUser').value = '';
    filterUsersTable();
}

// AUTO-OPEN USER PERMISSIONS MODAL JIKA ADA URL PARAM open_permissions_user_id
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const openPermUserId = urlParams.get('open_permissions_user_id');
    if (openPermUserId) {
        const uid = parseInt(openPermUserId);
        if (uid > 0) {
            openUserPermissionsModal(uid);
            const newUrl = window.location.pathname + '?tab=users';
            window.history.replaceState({}, document.title, newUrl);
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
