<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// Proteksi: Hanya Super Admin yang berhak mengelola akses & role
requireSuperAdmin();

$pageTitle = 'Manajemen Akses & Pengguna';
$currentUserId = currentUserId();

// =========================================================================
// AJAX: AMBIL HAK AKSES USER (JSON)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_user_permissions') {
    header('Content-Type: application/json');
    $uid = (int)($_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT menu_id FROM user_menu_access WHERE user_id = ?");
    $stmt->execute([$uid]);
    $menuIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Ambil info user
    $stmtUser = $pdo->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $stmtUser->execute([$uid]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'user' => $user,
        'menu_ids' => array_map('intval', $menuIds)
    ]);
    exit;
}

// =========================================================================
// AJAX: AMBIL DEFAULT HAK AKSES ROLE (JSON)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_role_permissions') {
    header('Content-Type: application/json');
    $roleKey = trim($_GET['role_key'] ?? '');
    $stmt = $pdo->prepare("
        SELECT rma.menu_id 
        FROM role_menu_access rma 
        INNER JOIN roles r ON r.id = rma.role_id 
        WHERE r.role_key = ?
    ");
    $stmt->execute([$roleKey]);
    $menuIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'status' => 'success',
        'menu_ids' => array_map('intval', $menuIds)
    ]);
    exit;
}

// =========================================================================
// PROSES POST FORM
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. SIMPAN HAK AKSES USER TUNGGAL (MANUAL)
    if ($action === 'save_user_permissions') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $selectedMenuIds = array_map('intval', $_POST['menu_ids'] ?? []);

        if ($targetUserId > 0) {
            $pdo->beginTransaction();
            try {
                // Hapus permission lama
                $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?")->execute([$targetUserId]);

                // Masukkan permission baru
                if (!empty($selectedMenuIds)) {
                    $stmtInsert = $pdo->prepare("INSERT INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
                    foreach ($selectedMenuIds as $mId) {
                        if ($mId > 0) {
                            $stmtInsert->execute([$targetUserId, $mId]);
                        }
                    }
                }

                $pdo->commit();
                refreshUserPermissions($pdo, $targetUserId);
                if (function_exists('recordActivityAudit')) {
                    recordActivityAudit($pdo, 'access_control', 'UPDATE', 'user_menu_access', $targetUserId, "Perbarui Hak Akses Menu Pengguna #{$targetUserId}");
                }
                flash('success', 'Hak akses pengguna berhasil diperbarui!');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', 'Gagal menyimpan hak akses: ' . $e->getMessage());
            }
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

    // 7. TAMBAH USER BARU
    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'staff');

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
                    $stmt->execute([$name, $username, $hash, $role]);
                    $newUserId = (int)$pdo->lastInsertId();

                    // Copy default permissions dari role ke user_menu_access
                    $stmtRoleAccess = $pdo->prepare("
                        SELECT rma.menu_id 
                        FROM role_menu_access rma 
                        INNER JOIN roles r ON r.id = rma.role_id 
                        WHERE r.role_key = ?
                    ");
                    $stmtRoleAccess->execute([$role]);
                    $defaultMenuIds = $stmtRoleAccess->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($defaultMenuIds)) {
                        $stmtUserMenu = $pdo->prepare("INSERT INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
                        foreach ($defaultMenuIds as $mid) {
                            $stmtUserMenu->execute([$newUserId, $mid]);
                        }
                    }

                    $pdo->commit();
                    if (function_exists('recordActivityAudit')) {
                        recordActivityAudit($pdo, 'access_control', 'CREATE', 'users', $newUserId, "Tambah Pengguna Baru: {$name} ({$username}) - Role: {$role}");
                    }
                    flash('success', 'Pengguna baru berhasil ditambahkan beserta hak akses default role!');
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    flash('error', 'Terjadi kesalahan: ' . $e->getMessage());
                }
            }
        }
        redirect('index.php?tab=users');
    }

    // 8. EDIT USER TUNGGAL LENGKAP
    if ($action === 'edit_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'staff');

        if ($uid > 0 && $name !== '' && $username !== '') {
            $stmtCek = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
            $stmtCek->execute([$username, $uid]);
            if ($stmtCek->fetch()) {
                flash('error', 'Username sudah dipakai oleh pengguna lain.');
            } else {
                if (!empty($password)) {
                    $hash = hashPassword($password);
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ?, password = ? WHERE id = ?");
                    $stmt->execute([$name, $username, $role, $hash, $uid]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ? WHERE id = ?");
                    $stmt->execute([$name, $username, $role, $uid]);
                }
                if (function_exists('recordActivityAudit')) {
                    recordActivityAudit($pdo, 'access_control', 'UPDATE', 'users', $uid, "Ubah Data Pengguna #{$uid}: {$name} ({$username})");
                }
                flash('success', 'Data pengguna berhasil diperbarui.');
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

                    if (!empty($selectedMenuIds)) {
                        $stmtRma = $pdo->prepare("INSERT INTO role_menu_access (role_id, menu_id) VALUES (?, ?)");
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

    // 12. UPDATE DEFAULT ROLE MENU PERMISSIONS
    if ($action === 'save_role_permissions') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $selectedMenuIds = array_map('intval', $_POST['menu_ids'] ?? []);

        if ($roleId > 0) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM role_menu_access WHERE role_id = ?")->execute([$roleId]);
                if (!empty($selectedMenuIds)) {
                    $stmtRma = $pdo->prepare("INSERT INTO role_menu_access (role_id, menu_id) VALUES (?, ?)");
                    foreach ($selectedMenuIds as $mid) {
                        if ($mid > 0) $stmtRma->execute([$roleId, $mid]);
                    }
                }
                $pdo->commit();
                flash('success', 'Default hak akses role berhasil diperbarui!');
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
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
            $stmtCount->execute([$rData['role_key']]);
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

// 1. Ambil Semua Role
$roles = $pdo->query("
    SELECT r.*, 
           (SELECT COUNT(*) FROM users u WHERE u.role COLLATE utf8mb4_general_ci = r.role_key AND u.deleted_at IS NULL) as total_users,
           (SELECT COUNT(*) FROM role_menu_access rma WHERE rma.role_id = r.id) as total_menus
    FROM roles r 
    ORDER BY r.is_system DESC, r.role_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

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

require '../../includes/header.php';
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
                                <div style="display: inline-flex; align-items: center; gap: 6px;">
                                    <span class="role-badge <?= $badgeClass ?>">
                                        <i class="fa-solid fa-circle-dot" style="font-size: 8px;"></i>
                                        <?= htmlspecialchars($roleLabel) ?>
                                    </span>
                                    <!-- QUICK ROLE SWITCHER BUTTON -->
                                    <button type="button" class="btn btn-sm btn-secondary" 
                                            onclick="openQuickChangeRoleModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>', '<?= htmlspecialchars(addslashes($u['username'])) ?>', '<?= $u['role'] ?>')"
                                            style="padding: 2px 7px; font-size: 11px; border-radius: 12px;"
                                            title="Ubah role pengguna ini secara instan">
                                        <i class="fa-solid fa-pen-to-square"></i> Ubah
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
                                    <span style="color: #6d28d9; font-weight: bold;">Semua</span>
                                <?php else: ?>
                                    <span style="background: #ede9fe; color: var(--primary); padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                        <?= (int)$r['total_menus'] ?> menu
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 14px; text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <?php if ($r['role_key'] !== 'super_admin'): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openRolePermissionsModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['role_name'])) ?>', '<?= htmlspecialchars(addslashes($r['role_key'])) ?>')">
                                            <i class="fa-solid fa-list-check"></i> Atur Default Menu
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
<div id="modalUserPermissions" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <div class="custom-modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                    <i class="fa-solid fa-sliders" style="color: var(--primary); margin-right: 8px;"></i>
                    Atur Hak Akses: <span id="modalTargetUserName" style="color: var(--primary);">Loading...</span>
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Role: <strong id="modalTargetUserRole">-</strong> &bull; Centang menu yang diizinkan untuk pengguna ini secara spesifik.
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
                <!-- TOOLBAR AKSI CEPAT -->
                <div style="background: #f1f5f9; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleAllCheckboxes(true)">
                            <i class="fa-solid fa-check-double"></i> Pilih Semua
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleAllCheckboxes(false)">
                            <i class="fa-solid fa-xmark"></i> Kosongkan
                        </button>
                        <div style="position: relative;">
                            <input type="text" id="searchMenuInput" placeholder="Cari nama / kode menu..." onkeyup="filterMenuCheckboxes()" style="padding: 6px 12px 6px 28px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; width: 180px;">
                            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 9px; top: 9px; color: #94a3b8; font-size: 11px;"></i>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <span style="font-size: 12px; color: #475569; font-weight: 600;">Gunakan Template Role:</span>
                        <select id="roleTemplateSelector" style="padding: 5px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px;" onchange="applyRoleTemplate(this.value)">
                            <option value="">-- Pilih Role --</option>
                            <?php foreach ($roles as $r): ?>
                                <?php if ($r['role_key'] !== 'super_admin'): ?>
                                    <option value="<?= htmlspecialchars($r['role_key']) ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- DAFTAR MENU BERDASARKAN KATEGORI -->
                <div id="userMenuCheckboxesContainer">
                    <?php foreach ($menusByCategory as $category => $items): ?>
                        <div class="category-card" data-category="<?= htmlspecialchars($category) ?>">
                            <div class="category-header" style="display: flex; justify-content: space-between; align-items: center;">
                                <span class="category-title">
                                    <i class="fa-solid fa-folder"></i> <?= htmlspecialchars($category) ?>
                                </span>
                                <label style="font-size: 11.5px; color: #0284c7; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; user-select: none; margin: 0;">
                                    <input type="checkbox" class="cat-select-all" onchange="toggleCategoryCheckboxes(this)" style="cursor: pointer;">
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
                                    <label class="menu-checkbox-item" data-menuname="<?= htmlspecialchars(strtolower($m['menu_name'])) ?>" data-menukey="<?= htmlspecialchars(strtolower($m['menu_key'])) ?>" <?= $isOtMenu ? 'style="border: 1.5px solid #f59e0b; background: #fffbeb;"' : '' ?>>
                                        <input type="checkbox" name="menu_ids[]" value="<?= $m['id'] ?>" class="user-menu-chk" id="chk_user_<?= $m['id'] ?>" onchange="syncCategoryCheckboxes()">
                                        <span class="menu-checkbox-label" style="flex: 1; display: flex; align-items: center; justify-content: space-between;">
                                            <span style="display: flex; align-items: center; gap: 6px;">
                                                <i class="<?= htmlspecialchars($icon) ?>" style="color: <?= $isOtMenu ? '#d97706' : 'var(--primary)' ?>; width: 16px; text-align: center;"></i>
                                                <?= htmlspecialchars($m['menu_name']) ?>
                                            </span>
                                            <?php if ($isOtMenu): ?>
                                                <span style="font-size: 10px; background: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px; font-weight: 700; border: 1px solid #fde68a; margin-left: 6px;">⭐ HRD / Jam Lembur</span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalUserPermissions')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Hak Akses
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ATUR DEFAULT MENU ROLE -->
<!-- ========================================================================= -->
<div id="modalRolePermissions" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <div class="custom-modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                    <i class="fa-solid fa-key" style="color: var(--primary); margin-right: 8px;"></i>
                    Atur Default Akses: <span id="modalRoleNameText" style="color: var(--primary);">-</span>
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Setiap user yang diberi role ini akan otomatis mendapatkan hak akses menu di bawah ini.
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
                <div style="display: flex; gap: 8px; margin-bottom: 16px;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="toggleRoleCheckboxes(true)">
                        <i class="fa-solid fa-check-double"></i> Pilih Semua
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="toggleRoleCheckboxes(false)">
                        <i class="fa-solid fa-xmark"></i> Kosongkan
                    </button>
                </div>

                <?php foreach ($menusByCategory as $category => $items): ?>
                    <div class="category-card" data-category="<?= htmlspecialchars($category) ?>">
                        <div class="category-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <span class="category-title"><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($category) ?></span>
                            <label style="font-size: 11.5px; color: #0284c7; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; user-select: none; margin: 0;">
                                <input type="checkbox" class="cat-select-all" onchange="toggleCategoryCheckboxes(this)" style="cursor: pointer;">
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
                                <label class="menu-checkbox-item" data-menuname="<?= htmlspecialchars(strtolower($m['menu_name'])) ?>" data-menukey="<?= htmlspecialchars(strtolower($m['menu_key'])) ?>" <?= $isOtMenu ? 'style="border: 1.5px solid #f59e0b; background: #fffbeb;"' : '' ?>>
                                    <input type="checkbox" name="menu_ids[]" value="<?= $m['id'] ?>" class="role-menu-chk" id="chk_role_<?= $m['id'] ?>" onchange="syncCategoryCheckboxes()">
                                    <span class="menu-checkbox-label" style="flex: 1; display: flex; align-items: center; justify-content: space-between;">
                                        <span style="display: flex; align-items: center; gap: 6px;">
                                            <i class="<?= htmlspecialchars($icon) ?>" style="color: <?= $isOtMenu ? '#d97706' : 'var(--primary)' ?>; width: 16px; text-align: center;"></i>
                                            <?= htmlspecialchars($m['menu_name']) ?>
                                        </span>
                                        <?php if ($isOtMenu): ?>
                                            <span style="font-size: 10px; background: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px; font-weight: 700; border: 1px solid #fde68a; margin-left: 6px;">⭐ HRD / Jam Lembur</span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalRolePermissions')">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Default Role
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TAMBAH USER BARU -->
<!-- ========================================================================= -->
<div id="modalAddUser" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 520px;">
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
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Nama Lengkap *</label>
                    <input type="text" name="name" required placeholder="Contoh: Budi Santoso, S.Kom"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Username *</label>
                    <input type="text" name="username" required placeholder="Contoh: budi_santoso"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Password *</label>
                    <input type="password" name="password" required placeholder="Minimal 6 karakter"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Pilih Role *</label>
                    <select name="role" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= htmlspecialchars($r['role_key']) ?>">
                                <?= htmlspecialchars($r['role_name']) ?> (<?= htmlspecialchars($r['role_key']) ?>)
                            </option>
                        <?php endforeach; ?>
                        <option value="siswa">Siswa (siswa)</option>
                    </select>
                    <small style="color: #64748b; margin-top: 4px; display: block;">
                        Hak akses menu akan otomatis diselaraskan dengan default template role yang dipilih.
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
    <div class="custom-modal-box" style="max-width: 520px;">
        <div class="custom-modal-header">
            <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                <i class="fa-solid fa-user-pen" style="color: var(--primary); margin-right: 8px;"></i>
                Edit Data Pengguna
            </h3>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalEditUser')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId" value="">
            <div class="custom-modal-body">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Nama Lengkap *</label>
                    <input type="text" name="name" id="editName" required
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Username *</label>
                    <input type="text" name="username" id="editUsername" required
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Password Baru (Kosongkan jika tidak diganti)</label>
                    <input type="password" name="password" placeholder="Biarkan kosong jika tidak diubah"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Role *</label>
                    <select name="role" id="editRole" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= htmlspecialchars($r['role_key']) ?>">
                                <?= htmlspecialchars($r['role_name']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="siswa">Siswa</option>
                    </select>
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
<!-- MODAL: BUAT ROLE BARU -->
<!-- ========================================================================= -->
<div id="modalCreateRole" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <div class="custom-modal-header">
            <h3 style="margin: 0; font-size: 18px; color: #0f172a;">
                <i class="fa-solid fa-plus-circle" style="color: var(--primary); margin-right: 8px;"></i>
                Buat Role Baru & Tentukan Default Akses
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
                        <input type="text" name="role_name" required placeholder="Misal: Guru Piket, Tata Usaha"
                               style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;"
                               onkeyup="autoGenerateRoleKey(this.value)">
                    </div>
                    <div class="form-group">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px;">Kode Sistem (Slug) *</label>
                        <input type="text" name="role_key" id="newRoleKeyInput" required placeholder="Misal: guru_piket"
                               style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Deskripsi / Keterangan</label>
                    <input type="text" name="description" placeholder="Contoh: Mengatur piket harian dan absensi kehadiran siswa"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 12px; font-weight: 700; color: #334155; font-size: 14px;">
                    Pilih Menu Default yang Diizinkan untuk Role Ini:
                </div>

                <?php foreach ($menusByCategory as $category => $items): ?>
                    <div class="category-card">
                        <div class="category-header">
                            <span class="category-title"><i class="fa-solid fa-folder"></i> <?= htmlspecialchars($category) ?></span>
                        </div>
                        <div class="menu-grid">
                            <?php foreach ($items as $m): ?>
                                <?php
                                    $icon = !empty($m['menu_icon']) ? $m['menu_icon'] : 'fa-circle';
                                    if (strpos($icon, 'fa-') !== false && strpos($icon, 'fa-solid ') === false) {
                                        $icon = 'fa-solid ' . $icon;
                                    }
                                ?>
                                <label class="menu-checkbox-item">
                                    <input type="checkbox" name="menu_ids[]" value="<?= $m['id'] ?>">
                                    <span class="menu-checkbox-label">
                                        <i class="<?= htmlspecialchars($icon) ?>" style="color: var(--primary); width: 16px; text-align: center;"></i>
                                        <?= htmlspecialchars($m['menu_name']) ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
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

// Tutup modal jika klik di luar box
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

    // Update row highlighting
    document.querySelectorAll('.user-bulk-chk').forEach(chk => {
        const row = chk.closest('tr');
        if (row) {
            if (chk.checked) {
                row.style.background = '#f0fdf4';
            } else {
                row.style.background = '';
            }
        }
    });

    // Update check all header status
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

// Buka Modal Bulk Change Role
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

// Buka Modal Bulk Menu Action (Grant / Revoke)
function openBulkMenuActionModal() {
    const ids = getSelectedUserIds();
    if (ids.length === 0) {
        alert('Silakan centang minimal satu pengguna.');
        return;
    }
    document.getElementById('bulkMenuUserCount').innerText = ids.length;
    document.getElementById('bulkMenuUserIdsInput').value = JSON.stringify(ids);
    toggleBulkMenuCheckboxes(false);
    openModal('modalBulkMenuAction');
}

function toggleBulkMenuCheckboxes(check) {
    document.querySelectorAll('.bulk-menu-chk').forEach(c => c.checked = check);
    syncCategoryCheckboxes();
}

// Eksekusi Bulk Reset Permissions
function submitBulkResetPermissions() {
    const ids = getSelectedUserIds();
    if (ids.length === 0) {
        alert('Silakan centang minimal satu pengguna.');
        return;
    }
    if (confirm(`Apakah Anda yakin ingin me-reset hak akses ${ids.length} pengguna terpilih ke template bawaan role mereka masing-masing?\n\nSemua kustomisasi menu manual mereka akan diselaraskan dengan role.`)) {
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

// Eksekusi Bulk Delete Users
function submitBulkDelete() {
    const ids = getSelectedUserIds();
    if (ids.length === 0) {
        alert('Silakan centang minimal satu pengguna.');
        return;
    }
    if (confirm(`PERINGATAN: Apakah Anda yakin ingin menonaktifkan/menghapus ${ids.length} pengguna terpilih?\n\n(Catatan: Akun Super Admin yang sedang aktif login tidak akan dihapus demi keamanan sistem).`)) {
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
// QUICK CHANGE ROLE PER PENGGUNA (DENGAN OPSI SETTING MANUAL)
// =========================================================================
function openQuickChangeRoleModal(userId, userName, userUsername, currentRole) {
    document.getElementById('quickRoleUserId').value = userId;
    document.getElementById('quickRoleUserName').innerText = userName;
    document.getElementById('quickRoleUserUsername').innerText = userUsername;
    
    const select = document.getElementById('quickRoleSelect');
    if (select) {
        select.value = currentRole;
    }
    openModal('modalQuickChangeRole');
}

// =========================================================================
// EDIT ROLE MODAL
// =========================================================================
function openEditRoleModal(role) {
    document.getElementById('editRoleId').value = role.id;
    document.getElementById('editRoleName').value = role.role_name;
    document.getElementById('editRoleKey').value = role.role_key;
    document.getElementById('editRoleDescription').value = role.description || '';
    openModal('modalEditRole');
}

// =========================================================================
// USER PERMISSIONS MODAL (SETTING MANUAL MENU)
// =========================================================================
function openUserPermissionsModal(userId) {
    document.getElementById('targetUserIdInput').value = userId;
    document.getElementById('modalTargetUserName').innerText = 'Memuat...';
    document.getElementById('modalTargetUserRole').innerText = '-';
    
    const searchInput = document.getElementById('searchMenuInput');
    if (searchInput) {
        searchInput.value = '';
        filterMenuCheckboxes();
    }
    toggleAllCheckboxes(false);
    document.getElementById('roleTemplateSelector').value = '';

    openModal('modalUserPermissions');

    fetch('index.php?action=get_user_permissions&user_id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('modalTargetUserName').innerText = data.user.name;
                document.getElementById('modalTargetUserRole').innerText = data.user.role.toUpperCase();
                
                data.menu_ids.forEach(mId => {
                    const chk = document.getElementById('chk_user_' + mId);
                    if (chk) chk.checked = true;
                });
                syncCategoryCheckboxes();
            } else {
                alert('Gagal mengambil data hak akses pengguna.');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Terjadi kesalahan koneksi.');
        });
}

// Buka Modal Atur Default Role
function openRolePermissionsModal(roleId, roleName, roleKey) {
    document.getElementById('targetRoleIdInput').value = roleId;
    document.getElementById('modalRoleNameText').innerText = roleName + ' (' + roleKey + ')';

    toggleRoleCheckboxes(false);
    openModal('modalRolePermissions');

    fetch('index.php?action=get_role_permissions&role_key=' + encodeURIComponent(roleKey))
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                data.menu_ids.forEach(mId => {
                    const chk = document.getElementById('chk_role_' + mId);
                    if (chk) chk.checked = true;
                });
                syncCategoryCheckboxes();
            }
        })
        .catch(err => console.error(err));
}

// Buka Modal Edit User Lengkap
function openEditUserModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editName').value = user.name;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editRole').value = user.role;
    openModal('modalEditUser');
}

// Helper: Check/Uncheck Semua Checkbox User
function toggleAllCheckboxes(check) {
    document.querySelectorAll('.user-menu-chk').forEach(chk => {
        chk.checked = check;
    });
    syncCategoryCheckboxes();
}

// Helper: Check/Uncheck Semua Checkbox Role
function toggleRoleCheckboxes(check) {
    document.querySelectorAll('.role-menu-chk').forEach(chk => {
        chk.checked = check;
    });
    syncCategoryCheckboxes();
}

// Helper: Pilih Semua Menu dalam Satu Kategori
function toggleCategoryCheckboxes(headerCheckbox) {
    const card = headerCheckbox.closest('.category-card');
    if (!card) return;
    const isChecked = headerCheckbox.checked;
    card.querySelectorAll('.user-menu-chk, .role-menu-chk, .bulk-menu-chk').forEach(chk => {
        chk.checked = isChecked;
    });
    syncCategoryCheckboxes();
}

// Helper: Sinkronisasi Status Checkbox Header Kategori
function syncCategoryCheckboxes() {
    document.querySelectorAll('.category-card').forEach(card => {
        const catChk = card.querySelector('.cat-select-all');
        if (!catChk) return;
        const itemChks = card.querySelectorAll('.user-menu-chk, .role-menu-chk, .bulk-menu-chk');
        if (itemChks.length === 0) return;
        const checkedCount = Array.from(itemChks).filter(c => c.checked).length;
        catChk.checked = (checkedCount === itemChks.length);
        catChk.indeterminate = (checkedCount > 0 && checkedCount < itemChks.length);
    });
}

// Helper: Live Filter Pencarian Menu di Modal Hak Akses
function filterMenuCheckboxes() {
    const q = (document.getElementById('searchMenuInput')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#userMenuCheckboxesContainer .category-card').forEach(card => {
        let hasVisibleChild = false;
        card.querySelectorAll('.menu-checkbox-item').forEach(item => {
            const name = item.getAttribute('data-menuname') || '';
            const key = item.getAttribute('data-menukey') || '';
            if (!q || name.includes(q) || key.includes(q)) {
                item.style.display = '';
                hasVisibleChild = true;
            } else {
                item.style.display = 'none';
            }
        });
        card.style.display = hasVisibleChild ? '' : 'none';
    });
}

// Terapkan Template Role ke Checkbox User
function applyRoleTemplate(roleKey) {
    if (!roleKey) return;
    fetch('index.php?action=get_role_permissions&role_key=' + encodeURIComponent(roleKey))
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                toggleAllCheckboxes(false);
                data.menu_ids.forEach(mId => {
                    const chk = document.getElementById('chk_user_' + mId);
                    if (chk) chk.checked = true;
                });
                syncCategoryCheckboxes();
            }
        });
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
            // Bersihkan parameter dari URL browser tanpa reload halaman
            const newUrl = window.location.pathname + '?tab=users';
            window.history.replaceState({}, document.title, newUrl);
        }
    }
});
</script>

<?php require '../../includes/footer.php'; ?>
