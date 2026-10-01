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

    // 1. SIMPAN HAK AKSES USER
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
                flash('success', 'Hak akses pengguna berhasil diperbarui!');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', 'Gagal menyimpan hak akses: ' . $e->getMessage());
            }
        }
        redirect('index.php');
    }

    // 2. TAMBAH USER BARU
    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'staff');

        if ($name === '' || $username === '' || $password === '') {
            flash('error', 'Nama, Username, dan Password wajib diisi.');
        } else {
            // Cek username unik
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
                    flash('success', 'Pengguna baru berhasil ditambahkan beserta hak akses default role!');
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    flash('error', 'Terjadi kesalahan: ' . $e->getMessage());
                }
            }
        }
        redirect('index.php');
    }

    // 3. EDIT USER
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
                flash('success', 'Data pengguna berhasil diperbarui.');
            }
        }
        redirect('index.php');
    }

    // 4. HAPUS USER
    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $currentUserId) {
            flash('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        } elseif ($uid > 0) {
            $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$uid]);
            flash('success', 'Pengguna berhasil dinonaktifkan/dihapus.');
        }
        redirect('index.php');
    }

    // 5. BUAT ROLE BARU
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

    // 6. UPDATE DEFAULT ROLE MENU PERMISSIONS
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

    // 7. HAPUS ROLE
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
            // Cek apakah ada user yang masih menggunakan role ini
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

    // 7. TOGGLE AKSES DATA LEMBUR STAFF (KHUSUS SUPER ADMIN)
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

// 2. Ambil Semua User (Termasuk status akses menu lembur)
$users = $pdo->query("
    SELECT u.id, u.name, u.username, u.role, u.photo, u.created_at,
           (SELECT COUNT(*) FROM user_menu_access uma WHERE uma.user_id = u.id) as active_menus,
           (SELECT COUNT(*) FROM user_menu_access uma2 
            JOIN app_menus am2 ON am2.id = uma2.menu_id 
            WHERE uma2.user_id = u.id AND am2.menu_key = 'reports_overtimes') as has_overtime_access
    FROM users u
    WHERE u.deleted_at IS NULL
    ORDER BY u.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Ambil Semua Menu Aktif untuk Checkbox (Dikelompokkan per Kategori)
$allMenus = $pdo->query("
    SELECT id, menu_key, menu_name, category, menu_url, menu_icon, sort_order
    FROM app_menus
    WHERE is_active = 1
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
    margin-bottom: 24px;
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
.role-badge.custom { background: #fef3c7; color: #b45309; }

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
    background: #f5f3ff;
}
.menu-checkbox-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
    cursor: pointer;
}
.menu-checkbox-label {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Modal Styling */
.custom-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    z-index: 2500;
    justify-content: center;
    align-items: center;
    padding: 20px;
    overflow-y: auto;
}
.custom-modal-box {
    background: #ffffff;
    border-radius: 16px;
    width: 100%;
    max-width: 820px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    animation: modalIn 0.2s ease-out;
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.96) translateY(10px); }
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
                Atur hak akses menu dinamis per pengguna atau definisikan role baru tanpa mengubah kodingan PHP.
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
        <a href="?tab=users" class="acl-tab-btn <?= $activeTab === 'users' ? 'active' : '' ?>">
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
        <div style="margin-bottom: 16px; display: flex; gap: 12px; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div style="position: relative; max-width: 320px; width: 100%;">
                <input type="text" id="searchUser" placeholder="Cari nama atau username..." 
                       style="width: 100%; padding: 8px 12px 8px 34px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;"
                       onkeyup="filterUsersTable()">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 12px; color: #94a3b8;"></i>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary" onclick="resetUserFilter()" style="font-size: 13px;">
                    <i class="fa-solid fa-rotate-left"></i> Reset Filter
                </button>
            </div>
        </div>

        <div class="table-container" style="overflow-x: auto;">
            <table class="table" id="usersTable" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 12px 14px; text-align: left;">Pengguna</th>
                        <th style="padding: 12px 14px; text-align: left;">Username</th>
                        <th style="padding: 12px 14px; text-align: left;">Role</th>
                        <th style="padding: 12px 14px; text-align: center;">Menu Aktif</th>
                        <th style="padding: 12px 14px; text-align: center;">Akses Lembur (HRD)</th>
                        <th style="padding: 12px 14px; text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php 
                            $badgeClass = in_array($u['role'], ['super_admin', 'kepala_sekolah', 'admin', 'staff']) ? $u['role'] : 'custom';
                            $roleLabel = ucwords(str_replace('_', ' ', $u['role']));
                            $avatar = !empty($u['photo']) ? UPLOAD_URL . '/admins/' . $u['photo'] : null;
                            $avatarFile = !empty($u['photo']) ? UPLOAD_PATH . '/admins/' . $u['photo'] : null;
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;" class="user-row" data-name="<?= strtolower($u['name']) ?>" data-username="<?= strtolower($u['username']) ?>" data-role="<?= $u['role'] ?>">
                            <td style="padding: 12px 14px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 38px; height: 38px; border-radius: 50%; background: #e0e7ff; color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: bold; overflow: hidden; flex-shrink: 0;">
                                        <?php if ($avatarFile && file_exists($avatarFile)): ?>
                                            <img src="<?= $avatar ?>" alt="Photo" style="width: 100%; height: 100%; object-fit: cover;">
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
                                <span class="role-badge <?= $badgeClass ?>">
                                    <i class="fa-solid fa-circle-dot" style="font-size: 8px;"></i>
                                    <?= htmlspecialchars($roleLabel) ?>
                                </span>
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
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openUserPermissionsModal(<?= $u['id'] ?>)" title="Atur Hak Akses">
                                            <i class="fa-solid fa-key"></i> Atur Akses
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-secondary" disabled title="Super Admin memiliki akses penuh otomatis">
                                            <i class="fa-solid fa-lock"></i> Root Admin
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u)) ?>)" title="Edit Profil">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>

                                    <?php if ($u['id'] !== $currentUserId): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus pengguna ini?');">
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
                                <span style="font-weight: 600; color: #334155;"><?= (int)$r['total_users'] ?> orang</span>
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
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openRolePermissionsModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['role_name']) ?>', '<?= htmlspecialchars($r['role_key']) ?>')">
                                            <i class="fa-solid fa-list-check"></i> Atur Default Menu
                                        </button>
                                    <?php endif; ?>

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
<!-- MODAL: ATUR HAK AKSES PENGGUNA (CHECKBOX UI) -->
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
                    Role: <strong id="modalTargetUserRole">-</strong> &bull; Centang menu yang diizinkan untuk pengguna ini.
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
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleAllCheckboxes(true)">
                            <i class="fa-solid fa-check-double"></i> Pilih Semua
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleAllCheckboxes(false)">
                            <i class="fa-solid fa-xmark"></i> Kosongkan
                        </button>
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
                        <div class="category-card">
                            <div class="category-header">
                                <span class="category-title">
                                    <i class="fa-solid fa-folder"></i> <?= htmlspecialchars($category) ?>
                                </span>
                                <span style="font-size: 11px; color: #94a3b8;"><?= count($items) ?> Menu</span>
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
                                        <input type="checkbox" name="menu_ids[]" value="<?= $m['id'] ?>" class="user-menu-chk" id="chk_user_<?= $m['id'] ?>">
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
                                    $isOtMenu = ($m['menu_key'] === 'reports_overtimes');
                                ?>
                                <label class="menu-checkbox-item" <?= $isOtMenu ? 'style="border: 1.5px solid #f59e0b; background: #fffbeb;"' : '' ?>>
                                    <input type="checkbox" name="menu_ids[]" value="<?= $m['id'] ?>" class="role-menu-chk" id="chk_role_<?= $m['id'] ?>">
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
                    </select>
                    <small style="color: #64748b; margin-top: 4px; display: block;">
                        Hak akses menu akan otomatis diisi sesuai default role yang dipilih.
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
<!-- MODAL: EDIT USER -->
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

// Buka Modal Atur Hak Akses User
function openUserPermissionsModal(userId) {
    document.getElementById('targetUserIdInput').value = userId;
    document.getElementById('modalTargetUserName').innerText = 'Memuat...';
    document.getElementById('modalTargetUserRole').innerText = '-';
    
    // Reset semua checkbox
    toggleAllCheckboxes(false);
    document.getElementById('roleTemplateSelector').value = '';

    openModal('modalUserPermissions');

    // Fetch via AJAX
    fetch('index.php?action=get_user_permissions&user_id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('modalTargetUserName').innerText = data.user.name;
                document.getElementById('modalTargetUserRole').innerText = data.user.role.toUpperCase();
                
                // Centang menu yang ada di user_menu_access
                data.menu_ids.forEach(mId => {
                    const chk = document.getElementById('chk_user_' + mId);
                    if (chk) chk.checked = true;
                });
            } else {
                alert('Gagal mengambil data hak akses pengguna.');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Terjadi kesalahan jaringan.');
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
            }
        })
        .catch(err => console.error(err));
}

// Buka Modal Edit User
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
}

// Helper: Check/Uncheck Semua Checkbox Role
function toggleRoleCheckboxes(check) {
    document.querySelectorAll('.role-menu-chk').forEach(chk => {
        chk.checked = check;
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
}

function resetUserFilter() {
    document.getElementById('searchUser').value = '';
    filterUsersTable();
}
</script>

<?php require '../../includes/footer.php'; ?>
