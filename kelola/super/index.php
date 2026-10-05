<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
global $pdo;
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

// TIDAK PERLU LOGIN (SESUAI PERMINTAAN USER: BEBAS DIAKSES TANPA LOGIN SEBAGAI CADANGAN DARURAT / ANTI-HACK)
// Digunakan untuk:
// 1. Kelola Akun Cadangan (CRUD akun darurat ketika Super Admin diretas atau terkunci)
// 2. Master Audit & Rollback Center (pemantauan & pemulihan log yang dihapus)

$pageTitle = 'Master Konsol Darurat (Emergency Console & Backdoor)';

// Helper flash message manual jika belum ada
if (!function_exists('getFlash')) {
    function getFlash(): ?array {
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $f;
        }
        return null;
    }
}
if (!function_exists('setFlash')) {
    function setFlash(string $type, string $message): void {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}

// =========================================================================
// PROSES POST ACTIONS
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------------------
    // AKSI CRUD AKUN PENGGUNA (CADANGAN DARURAT ANTI-HACK)
    // -------------------------------------------------------------

    // 1. Tambah Akun Baru
    if ($action === 'create_user') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'super_admin');
        $unitId = (int)($_POST['unit_id'] ?? 0);

        if ($name === '' || $username === '' || $password === '') {
            setFlash('error', 'Nama lengkap, username, dan password wajib diisi.');
        } elseif (strlen($password) < 4) {
            setFlash('error', 'Password minimal terdiri dari 4 karakter.');
        } else {
            // Cek keunikan username
            $stmtCek = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmtCek->execute([$username]);
            if ($stmtCek->fetch()) {
                setFlash('error', "Username '{$username}' sudah digunakan oleh akun lain. Gunakan username lain.");
            } else {
                $pdo->beginTransaction();
                try {
                    $hash = hashPassword($password);
                    $stmtIns = $pdo->prepare("
                        INSERT INTO users (name, username, password, role, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmtIns->execute([$name, $username, $hash, $role]);
                    $newUserId = (int)$pdo->lastInsertId();

                    // Berikan hak akses menu secara otomatis
                    if ($role === 'super_admin') {
                        $allMenuIds = $pdo->query("SELECT id FROM app_menus WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                        $stmtMenu = $pdo->prepare("INSERT IGNORE INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
                        foreach ($allMenuIds as $mid) {
                            $stmtMenu->execute([$newUserId, $mid]);
                        }
                    } else {
                        $stmtRoleMenu = $pdo->prepare("
                            SELECT rma.menu_id 
                            FROM role_menu_access rma 
                            JOIN roles r ON r.id = rma.role_id 
                            WHERE r.role_key = ?
                        ");
                        $stmtRoleMenu->execute([$role]);
                        $defaultMenus = $stmtRoleMenu->fetchAll(PDO::FETCH_COLUMN);
                        $stmtMenu = $pdo->prepare("INSERT IGNORE INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
                        foreach ($defaultMenus as $mid) {
                            $stmtMenu->execute([$newUserId, $mid]);
                        }
                    }

                    // Tautkan unit jika kepala_sekolah atau admin unit
                    if ($unitId > 0 && in_array($role, ['kepala_sekolah', 'admin'])) {
                        $stmtUnit = $pdo->prepare("INSERT INTO admin_unit_permissions (user_id, unit_id, created_at) VALUES (?, ?, NOW())");
                        $stmtUnit->execute([$newUserId, $unitId]);
                    }

                    // Catat Audit
                    recordActivityAudit($pdo, 'kelola_super', 'CREATE', 'users', $newUserId, 
                        "Tambah Akun Cadangan Darurat: {$username} ({$role})", null, [
                            'name' => $name, 'username' => $username, 'role' => $role, 'unit_id' => $unitId
                        ], $unitId ?: null);

                    $pdo->commit();
                    setFlash('success', "✓ Berhasil membuat akun cadangan '{$username}' dengan role '{$role}'. Akun siap digunakan untuk login darurat.");
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    setFlash('error', "Gagal menyimpan akun: " . $e->getMessage());
                }
            }
        }
        header('Location: index.php?mode=users');
        exit;
    }

    // 2. Edit Data Akun
    if ($action === 'edit_user') {
        $uid = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'super_admin');
        $unitId = (int)($_POST['unit_id'] ?? 0);

        if ($uid > 0 && $name !== '' && $username !== '') {
            $stmtCek = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
            $stmtCek->execute([$username, $uid]);
            if ($stmtCek->fetch()) {
                setFlash('error', "Username '{$username}' sudah dipakai pengguna lain.");
            } else {
                $stmtOld = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmtOld->execute([$uid]);
                $oldUser = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if ($oldUser) {
                    $pdo->beginTransaction();
                    try {
                        if (!empty($password)) {
                            $hash = hashPassword($password);
                            $stmtUp = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ?, password = ?, updated_at = NOW() WHERE id = ?");
                            $stmtUp->execute([$name, $username, $role, $hash, $uid]);
                        } else {
                            $stmtUp = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ?, updated_at = NOW() WHERE id = ?");
                            $stmtUp->execute([$name, $username, $role, $uid]);
                        }

                        // Perbarui izin unit
                        $pdo->prepare("DELETE FROM admin_unit_permissions WHERE user_id = ?")->execute([$uid]);
                        if ($unitId > 0 && in_array($role, ['kepala_sekolah', 'admin'])) {
                            $pdo->prepare("INSERT INTO admin_unit_permissions (user_id, unit_id, created_at) VALUES (?, ?, NOW())")->execute([$uid, $unitId]);
                        }

                        // Jika role berganti ke super_admin, pastikan seluruh menu diberikan
                        if ($role === 'super_admin') {
                            $allMenuIds = $pdo->query("SELECT id FROM app_menus WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                            $stmtMenu = $pdo->prepare("INSERT IGNORE INTO user_menu_access (user_id, menu_id) VALUES (?, ?)");
                            foreach ($allMenuIds as $mid) {
                                $stmtMenu->execute([$uid, $mid]);
                            }
                        }

                        recordActivityAudit($pdo, 'kelola_super', 'UPDATE', 'users', $uid, 
                            "Update Akun Cadangan ID #{$uid} ({$username})", $oldUser, [
                                'name' => $name, 'username' => $username, 'role' => $role, 'unit_id' => $unitId
                            ], $unitId ?: null);

                        $pdo->commit();
                        setFlash('success', "✓ Data akun '{$username}' berhasil diperbarui.");
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        setFlash('error', "Gagal memperbarui akun: " . $e->getMessage());
                    }
                }
            }
        }
        header('Location: index.php?mode=users');
        exit;
    }

    // 3. Reset Password Cepat
    if ($action === 'reset_password') {
        $uid = (int)($_POST['id'] ?? 0);
        $newPass = trim($_POST['new_password'] ?? '');

        if ($uid > 0 && !empty($newPass)) {
            if (strlen($newPass) < 4) {
                setFlash('error', 'Password minimal terdiri dari 4 karakter.');
            } else {
                $stmtU = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $stmtU->execute([$uid]);
                $uname = $stmtU->fetchColumn();

                if ($uname) {
                    $hash = hashPassword($newPass);
                    $stmtUp = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                    $stmtUp->execute([$hash, $uid]);

                    recordActivityAudit($pdo, 'kelola_super', 'UPDATE', 'users', $uid, 
                        "Emergency Reset Password Akun {$uname}", null, ['action' => 'reset_password']);

                    setFlash('success', "✓ Password akun '{$uname}' berhasil di-reset! Silakan login dengan password baru.");
                }
            }
        }
        header('Location: index.php?mode=users');
        exit;
    }

    // 4. Toggle Status (Nonaktifkan / Aktifkan Kembali Akun)
    if ($action === 'toggle_status') {
        $uid = (int)($_POST['id'] ?? 0);
        $targetStatus = $_POST['target_status'] ?? 'deactivate';

        if ($uid > 0) {
            $stmtU = $pdo->prepare("SELECT username, deleted_at FROM users WHERE id = ?");
            $stmtU->execute([$uid]);
            $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);

            if ($uRow) {
                if ($targetStatus === 'deactivate') {
                    $pdo->prepare("UPDATE users SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$uid]);
                    recordActivityAudit($pdo, 'kelola_super', 'UPDATE', 'users', $uid, "Nonaktifkan Akun: {$uRow['username']}", null, ['deleted_at' => date('Y-m-d H:i:s')]);
                    setFlash('success', "✓ Akun '{$uRow['username']}' berhasil dinonaktifkan. Pengguna tidak dapat login.");
                } else {
                    $pdo->prepare("UPDATE users SET deleted_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$uid]);
                    recordActivityAudit($pdo, 'kelola_super', 'UPDATE', 'users', $uid, "Aktifkan Kembali Akun: {$uRow['username']}", null, ['deleted_at' => null]);
                    setFlash('success', "✓ Akun '{$uRow['username']}' berhasil diaktifkan kembali. Pengguna dapat login kembali.");
                }
            }
        }
        header('Location: index.php?mode=users');
        exit;
    }

    // 5. Hapus Akun Permanen
    if ($action === 'delete_user_permanent') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid > 0) {
            $stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmtU->execute([$uid]);
            $uData = $stmtU->fetch(PDO::FETCH_ASSOC);

            if ($uData) {
                $pdo->beginTransaction();
                try {
                    // Lepaskan relasi foreign key sebelum menghapus record
                    $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?")->execute([$uid]);
                    $pdo->prepare("DELETE FROM admin_unit_permissions WHERE user_id = ?")->execute([$uid]);
                    $pdo->prepare("DELETE FROM admin_grade_permissions WHERE user_id = ?")->execute([$uid]);
                    $pdo->prepare("DELETE FROM admin_class_permissions WHERE user_id = ?")->execute([$uid]);
                    $pdo->prepare("UPDATE staff SET user_id = NULL WHERE user_id = ?")->execute([$uid]);
                    $pdo->prepare("UPDATE student_attendances SET scanned_by = NULL WHERE scanned_by = ?")->execute([$uid]);
                    $pdo->prepare("UPDATE staff_attendances SET scanned_by = NULL WHERE scanned_by = ?")->execute([$uid]);

                    $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmtDel->execute([$uid]);

                    recordActivityAudit($pdo, 'kelola_super', 'DELETE', 'users', $uid, 
                        "Hapus Permanen Akun: {$uData['username']} ({$uData['role']})", $uData, null, null);

                    $pdo->commit();
                    setFlash('success', "✓ Akun '{$uData['username']}' telah dihapus secara permanen dari sistem database.");
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    setFlash('error', "Gagal menghapus permanen: " . $e->getMessage());
                }
            }
        }
        header('Location: index.php?mode=users');
        exit;
    }

    // -------------------------------------------------------------
    // AKSI AUDIT LOG & ROLLBACK (EXISTING)
    // -------------------------------------------------------------
    $logId = (int)($_POST['id'] ?? 0);

    if ($action === 'rollback' && $logId > 0) {
        $result = rollbackActivityAudit($pdo, $logId, 'Super Admin Backdoor');
        if ($result['success']) {
            setFlash('success', '✓ ' . $result['message']);
        } else {
            setFlash('error', '⚠ ' . $result['message']);
        }
        header('Location: index.php?' . http_build_query($_GET));
        exit;
    }

    if ($action === 'restore_log' && $logId > 0) {
        $stmtRestore = $pdo->prepare("
            UPDATE activity_audit_logs 
            SET is_deleted_by_superadmin = 0, deleted_at = NULL 
            WHERE id = ?
        ");
        $stmtRestore->execute([$logId]);
        setFlash('success', '✓ Log aktivitas ID #' . $logId . ' berhasil dipulihkan kembali ke tampilan Super Admin.');
        header('Location: index.php?' . http_build_query($_GET));
        exit;
    }
}

// =========================================================================
// PENENTUAN MODE TAMPILAN: 'users' (Kelola Akun) atau 'audit' (Log Audit)
// =========================================================================
$mainMode = $_GET['mode'] ?? (isset($_GET['tab']) && $_GET['tab'] !== 'users' ? 'audit' : 'users');

// Master Data Unit & Roles
$unitsList = $pdo->query("SELECT id, unit FROM units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$rolesList = $pdo->query("SELECT id, role_key, role_name FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// DATA UNTUK MODE: KELOLA AKUN
// =========================================================================
$filterUserRole = trim($_GET['user_role'] ?? '');
$filterUserStatus = trim($_GET['user_status'] ?? '');
$searchUserKeyword = trim($_GET['user_q'] ?? '');

$uWhereSql = ["1 = 1"];
$uParams = [];

if (!empty($filterUserRole)) {
    $uWhereSql[] = "u.role = ?";
    $uParams[] = $filterUserRole;
}
if ($filterUserStatus === 'active') {
    $uWhereSql[] = "u.deleted_at IS NULL";
} elseif ($filterUserStatus === 'deleted') {
    $uWhereSql[] = "u.deleted_at IS NOT NULL";
}
if (!empty($searchUserKeyword)) {
    $uWhereSql[] = "(u.name LIKE ? OR u.username LIKE ?)";
    $kw = "%{$searchUserKeyword}%";
    $uParams[] = $kw;
    $uParams[] = $kw;
}

$uWhereClause = "WHERE " . implode(" AND ", $uWhereSql);

$usersQuery = "
    SELECT u.*, 
           aup.unit_id as assigned_unit_id,
           COALESCE(un.unit, st_u.unit) as unit_name
    FROM users u
    LEFT JOIN admin_unit_permissions aup ON aup.user_id = u.id
    LEFT JOIN units un ON un.id = aup.unit_id
    LEFT JOIN staff st ON st.user_id = u.id
    LEFT JOIN units st_u ON st_u.id = st.unit_id
    {$uWhereClause}
    ORDER BY (u.deleted_at IS NOT NULL) ASC, 
             FIELD(u.role, 'super_admin', 'kepala_sekolah', 'admin', 'hrd', 'staff') ASC, 
             u.id ASC
";
$stmtUsers = $pdo->prepare($usersQuery);
$stmtUsers->execute($uParams);
$userAccounts = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Counter Statistik Akun
$statTotalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$statSuperAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND deleted_at IS NULL")->fetchColumn();
$statKepsekAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('kepala_sekolah', 'admin') AND deleted_at IS NULL")->fetchColumn();
$statDisabledUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL")->fetchColumn();

// =========================================================================
// DATA UNTUK MODE: MASTER AUDIT & ROLLBACK CENTER
// =========================================================================
$viewTab = trim($_GET['tab'] ?? 'all'); // 'all', 'deleted_super', 'rolled_back'
$filterUnit = (int)($_GET['unit_id'] ?? 0);
$filterAction = trim($_GET['action_type'] ?? '');
$filterModule = trim($_GET['module'] ?? '');
$filterDate = trim($_GET['date'] ?? '');
$searchKeyword = trim($_GET['q'] ?? '');

$whereSql = ["1 = 1"];
$params = [];

if ($viewTab === 'deleted_super') {
    $whereSql[] = "aal.is_deleted_by_superadmin = 1";
} elseif ($viewTab === 'rolled_back') {
    $whereSql[] = "aal.is_rolled_back = 1";
}

if ($filterUnit > 0) {
    $whereSql[] = "aal.unit_id = ?";
    $params[] = $filterUnit;
}
if (!empty($filterAction)) {
    $whereSql[] = "aal.action = ?";
    $params[] = $filterAction;
}
if (!empty($filterModule)) {
    $whereSql[] = "aal.module = ?";
    $params[] = $filterModule;
}
if (!empty($filterDate)) {
    $whereSql[] = "DATE(aal.created_at) = ?";
    $params[] = $filterDate;
}
if (!empty($searchKeyword)) {
    $whereSql[] = "(aal.username LIKE ? OR aal.module LIKE ? OR aal.record_id LIKE ? OR aal.ip_address LIKE ?)";
    $kw = "%{$searchKeyword}%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$whereClause = "WHERE " . implode(" AND ", $whereSql);

$countAll = (int)$pdo->query("SELECT COUNT(*) FROM activity_audit_logs")->fetchColumn();
$countDeletedSuper = (int)$pdo->query("SELECT COUNT(*) FROM activity_audit_logs WHERE is_deleted_by_superadmin = 1")->fetchColumn();
$countRolledBack = (int)$pdo->query("SELECT COUNT(*) FROM activity_audit_logs WHERE is_rolled_back = 1")->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM activity_audit_logs aal {$whereClause}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$stmtLogs = $pdo->prepare("
    SELECT aal.*, u.unit as unit_name
    FROM activity_audit_logs aal
    LEFT JOIN units u ON u.id = aal.unit_id
    {$whereClause}
    ORDER BY aal.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmtLogs->execute($params);
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

$distinctModules = $pdo->query("SELECT DISTINCT module FROM activity_audit_logs WHERE module IS NOT NULL AND module != '' ORDER BY module ASC")->fetchAll(PDO::FETCH_COLUMN);

$flashMessage = getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            padding: 24px;
            min-height: 100vh;
        }
        .container { max-width: 1440px; margin: 0 auto; }
        .header-card {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5);
        }
        .header-title h1 {
            margin: 0 0 6px 0;
            font-size: 24px;
            font-weight: 800;
            color: #38bdf8;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-title p { margin: 0; color: #94a3b8; font-size: 13.5px; line-height: 1.5; }
        .badge-no-login {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.4);
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .main-modes-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            border-bottom: 2px solid #334155;
            padding-bottom: 8px;
            flex-wrap: wrap;
        }
        .mode-tab-link {
            padding: 12px 22px;
            font-size: 14.5px;
            font-weight: 700;
            color: #94a3b8;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #1e293b;
            border: 1px solid #334155;
        }
        .mode-tab-link:hover { color: #38bdf8; border-color: #38bdf8; background: #0f172a; }
        .mode-tab-link.active {
            color: #ffffff;
            background: #0284c7;
            border-color: #0284c7;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .stat-val { font-size: 26px; font-weight: 800; color: #f1f5f9; margin: 4px 0 0 0; }
        .stat-lbl { font-size: 12px; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3);
        }
        .filter-bar {
            padding: 18px 20px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
        }
        .form-control {
            background: #0f172a;
            border: 1px solid #334155;
            color: #f1f5f9;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
        }
        .form-control:focus { outline: none; border-color: #38bdf8; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-primary { background: #0284c7; color: white; }
        .btn-primary:hover { background: #0369a1; }
        .btn-success { background: #16a34a; color: white; }
        .btn-success:hover { background: #15803d; }
        .btn-warning { background: #d97706; color: white; }
        .btn-warning:hover { background: #b45309; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-light { background: #334155; color: #f1f5f9; }
        .btn-light:hover { background: #475569; }
        .table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table th { background: #0f172a; color: #cbd5e1; padding: 12px 14px; text-align: left; border-bottom: 1px solid #334155; }
        .table td { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .row-deleted { background: rgba(239, 68, 68, 0.08); border-left: 4px solid #ef4444 !important; }
        .row-rolledback { background: rgba(245, 158, 11, 0.08); }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }
        .badge-role-super { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }
        .badge-role-kepsek { background: rgba(56, 189, 248, 0.2); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4); }
        .badge-role-admin { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.4); }
        .badge-role-staff { background: rgba(148, 163, 184, 0.2); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.4); }
        .badge-role-hrd { background: rgba(168, 85, 247, 0.2); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.4); }

        .alert-box {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 14px;
        }
        .alert-success { background: #064e3b; color: #6ee7b7; border: 1px solid #059669; }
        .alert-error { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            z-index: 99999;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            justify-content: center;
            align-items: center;
            padding: 16px;
        }
        .modal-box {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 14px;
            width: 100%;
            max-width: 580px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7);
            overflow: hidden;
        }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h4 { margin: 0; font-size: 16px; font-weight: 800; color: #38bdf8; display: flex; align-items: center; gap: 8px; }
        .modal-body { padding: 20px; max-height: 75vh; overflow-y: auto; }
        .modal-footer {
            padding: 14px 20px;
            border-top: 1px solid #334155;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #0f172a;
        }
        .form-group-modal { margin-bottom: 14px; }
        .form-group-modal label { display: block; font-size: 12px; font-weight: 700; color: #94a3b8; margin-bottom: 5px; }
        .form-group-modal input, .form-group-modal select {
            width: 100%;
            background: #0f172a;
            border: 1px solid #334155;
            color: #f1f5f9;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
        }
        .form-group-modal small { color: #64748b; font-size: 11.5px; display: block; margin-top: 4px; }
    </style>
</head>
<body>

<div class="container">

    <!-- FLASH MESSAGE -->
    <?php if ($flashMessage): ?>
        <div class="alert-box <?= $flashMessage['type'] === 'success' ? 'alert-success' : 'alert-error' ?>">
            <div><?= e($flashMessage['message']) ?></div>
            <button onclick="this.parentElement.remove()" style="background:none; border:none; color:inherit; font-size:18px; cursor:pointer;">&times;</button>
        </div>
    <?php endif; ?>

    <!-- HEADER CARD -->
    <div class="header-card">
        <div class="header-title">
            <h1><i class="fa-solid fa-shield-halved"></i> Master Konsol Darurat (Emergency Console)</h1>
            <p>
                Pusat Kontrol Pemulihan Akun Cadangan & Audit Trail Sistem. Beroperasi sebagai instrumen fail-safe darurat jika akun Super Admin diretas, sandi diambil alih, atau terkunci dari panel utama.
            </p>
        </div>
        <div>
            <div class="badge-no-login">
                <i class="fa-solid fa-unlock-keyhole"></i> Area Pemulihan Darurat (No-Login Area)
            </div>
            <div style="margin-top:8px; text-align:right;">
                <a href="<?= BASE_URL ?>/kelola/index.php" style="color:#38bdf8; font-size:12.5px; font-weight:600; text-decoration:none;">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Menu Kelola Utama
                </a>
            </div>
        </div>
    </div>

    <!-- MAIN MODES BAR: KELOLA AKUN vs AUDIT ROLLBACK -->
    <div class="main-modes-bar">
        <a href="index.php?mode=users" class="mode-tab-link <?= $mainMode === 'users' ? 'active' : '' ?>">
            <i class="fa-solid fa-users-gear"></i> 👥 Kelola Akun Cadangan (CRUD & Anti-Hack) (<?= number_format($statTotalUsers) ?>)
        </a>
        <a href="index.php?mode=audit" class="mode-tab-link <?= $mainMode === 'audit' ? 'active' : '' ?>">
            <i class="fa-solid fa-satellite-dish"></i> 📜 Master Audit & Rollback Center (<?= number_format($countAll) ?>)
        </a>
    </div>

    <!-- ================================================================= -->
    <!-- MODE 1: KELOLA AKUN CADANGAN DARURAT (CRUD UNTUK AKUN)            -->
    <!-- ================================================================= -->
    <?php if ($mainMode === 'users'): ?>

        <!-- STATS COUNTERS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-lbl">Total Akun Terdaftar</div>
                    <div class="stat-val"><?= number_format($statTotalUsers) ?></div>
                </div>
                <div style="font-size:28px; color:#38bdf8;"><i class="fa-solid fa-users"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-lbl">Super Admin Aktif</div>
                    <div class="stat-val" style="color:#f87171;"><?= number_format($statSuperAdmin) ?></div>
                </div>
                <div style="font-size:28px; color:#f87171;"><i class="fa-solid fa-user-shield"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-lbl">Kepala Sekolah & Admin</div>
                    <div class="stat-val" style="color:#4ade80;"><?= number_format($statKepsekAdmin) ?></div>
                </div>
                <div style="font-size:28px; color:#4ade80;"><i class="fa-solid fa-school"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-lbl">Akun Dinonaktifkan</div>
                    <div class="stat-val" style="color:#fbbf24;"><?= number_format($statDisabledUsers) ?></div>
                </div>
                <div style="font-size:28px; color:#fbbf24;"><i class="fa-solid fa-user-slash"></i></div>
            </div>
        </div>

        <div class="card">
            <!-- ACTION BAR: BUTTON TAMBAH & FILTER -->
            <div class="filter-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div>
                    <button type="button" class="btn btn-success" onclick="openCreateUserModal()" style="padding:10px 18px; font-size:13.5px;">
                        <i class="fa-solid fa-user-plus"></i> + Tambah Akun Cadangan Baru
                    </button>
                </div>

                <form method="GET" action="index.php" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                    <input type="hidden" name="mode" value="users">

                    <select name="user_role" class="form-control" style="min-width:140px;">
                        <option value="">-- Semua Role --</option>
                        <option value="super_admin" <?= $filterUserRole === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                        <option value="kepala_sekolah" <?= $filterUserRole === 'kepala_sekolah' ? 'selected' : '' ?>>Kepala Sekolah</option>
                        <option value="admin" <?= $filterUserRole === 'admin' ? 'selected' : '' ?>>Admin Unit / Pemantau</option>
                        <option value="staff" <?= $filterUserRole === 'staff' ? 'selected' : '' ?>>Staff / Guru</option>
                        <option value="hrd" <?= $filterUserRole === 'hrd' ? 'selected' : '' ?>>HRD</option>
                    </select>

                    <select name="user_status" class="form-control" style="min-width:130px;">
                        <option value="">-- Semua Status --</option>
                        <option value="active" <?= $filterUserStatus === 'active' ? 'selected' : '' ?>>Hanya Aktif</option>
                        <option value="deleted" <?= $filterUserStatus === 'deleted' ? 'selected' : '' ?>>Dinonaktifkan</option>
                    </select>

                    <input type="text" name="user_q" value="<?= e($searchUserKeyword) ?>" placeholder="🔍 Cari Nama / Username..." class="form-control" style="min-width:180px;">

                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
                    <?php if (!empty($filterUserRole) || !empty($filterUserStatus) || !empty($searchUserKeyword)): ?>
                        <a href="index.php?mode=users" class="btn btn-light">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- TABEL AKUN PENGGUNA -->
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>Pengguna (Nama & Username)</th>
                            <th>Role & Akses</th>
                            <th>Unit Sekolah</th>
                            <th>Login Terakhir</th>
                            <th>Status Akun</th>
                            <th style="text-align: center; width: 240px;">Aksi Cadangan Darurat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($userAccounts)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:32px; color:#64748b;">
                                    Tidak ada akun yang sesuai dengan kriteria filter.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($userAccounts as $usr): ?>
                            <?php
                            $isDeleted = !empty($usr['deleted_at']);
                            $roleClass = 'badge-role-staff';
                            if ($usr['role'] === 'super_admin') $roleClass = 'badge-role-super';
                            elseif ($usr['role'] === 'kepala_sekolah') $roleClass = 'badge-role-kepsek';
                            elseif ($usr['role'] === 'admin') $roleClass = 'badge-role-admin';
                            elseif ($usr['role'] === 'hrd') $roleClass = 'badge-role-hrd';
                            ?>
                            <tr class="<?= $isDeleted ? 'row-deleted' : '' ?>">
                                <td style="font-family:'JetBrains Mono', monospace; color:#94a3b8; font-weight:700;">
                                    #<?= $usr['id'] ?>
                                </td>

                                <td>
                                    <strong style="color:#f1f5f9; font-size:14px;"><?= e($usr['name']) ?></strong>
                                    <div style="font-size:12px; color:#38bdf8; font-family:'JetBrains Mono', monospace; margin-top:2px;">
                                        @<?= e($usr['username']) ?>
                                    </div>
                                    <small style="color:#64748b; font-size:11px;">Dibuat: <?= !empty($usr['created_at']) ? date('d/m/Y H:i', strtotime($usr['created_at'])) : '-' ?></small>
                                </td>

                                <td>
                                    <span class="badge <?= $roleClass ?>" style="font-size:11.5px; padding:4px 9px;">
                                        <?= strtoupper(str_replace('_', ' ', $usr['role'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (!empty($usr['unit_name'])): ?>
                                        <span class="badge" style="background:#0369a1; color:#e0f2fe; font-size:11px;">
                                            🏫 <?= e($usr['unit_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:11.5px;">Global / Seluruh</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (!empty($usr['last_login_at'])): ?>
                                        <div style="font-size:12px; color:#f1f5f9; font-weight:600;">
                                            <?= date('d/m/Y H:i', strtotime($usr['last_login_at'])) ?>
                                        </div>
                                        <div style="font-size:11px; color:#94a3b8; font-family:'JetBrains Mono', monospace;">
                                            IP: <?= e($usr['last_login_ip'] ?: '-') ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#64748b; font-size:11.5px; font-style:italic;">Belum pernah login</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($isDeleted): ?>
                                        <span class="badge" style="background:#7f1d1d; color:#fca5a5; border:1px solid #dc2626; font-size:11px;">
                                            <i class="fa-solid fa-ban"></i> DINONAKTIFKAN
                                        </span>
                                        <div style="font-size:10px; color:#f87171; margin-top:2px;">
                                            Pada: <?= date('d/m/y H:i', strtotime($usr['deleted_at'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge" style="background:#064e3b; color:#6ee7b7; border:1px solid #059669; font-size:11px;">
                                            <i class="fa-solid fa-circle-check"></i> AKTIF
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align:center; white-space:nowrap;">
                                    <!-- TOMBOL RESET PASSWORD CEPAT -->
                                    <button type="button" class="btn btn-warning" 
                                            onclick="openResetPasswordModal(<?= $usr['id'] ?>, '<?= e($usr['username']) ?>', '<?= e($usr['name']) ?>')" 
                                            style="font-size:11.5px; padding:4px 8px;" title="Reset password langsung">
                                        <i class="fa-solid fa-key"></i> Reset Sandi
                                    </button>

                                    <!-- TOMBOL EDIT DATA AKUN -->
                                    <button type="button" class="btn btn-primary" 
                                            onclick="openEditUserModal(<?= htmlspecialchars(json_encode($usr), ENT_QUOTES, 'UTF-8') ?>)" 
                                            style="font-size:11.5px; padding:4px 8px;" title="Edit data akun">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </button>

                                    <!-- TOGGLE AKTIF / NONAKTIF -->
                                    <?php if (!$isDeleted): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Nonaktifkan akun <?= e($usr['username']) ?>? Pengguna ini tidak akan bisa login lagi.');">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= $usr['id'] ?>">
                                            <input type="hidden" name="target_status" value="deactivate">
                                            <button type="submit" class="btn btn-light" style="font-size:11.5px; padding:4px 8px; color:#f87171;" title="Nonaktifkan akun">
                                                <i class="fa-solid fa-user-slash"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Aktifkan kembali akun <?= e($usr['username']) ?>?');">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= $usr['id'] ?>">
                                            <input type="hidden" name="target_status" value="activate">
                                            <button type="submit" class="btn btn-success" style="font-size:11.5px; padding:4px 8px;" title="Pulihkan / Aktifkan akun">
                                                <i class="fa-solid fa-user-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- TOMBOL HAPUS PERMANEN -->
                                    <form method="POST" style="display:inline; margin-left:3px;" onsubmit="return confirm('PERINGATAN: Apakah Anda yakin ingin MENGHAPUS PERMANEN akun <?= e($usr['username']) ?> dari database? Tindakan ini tidak dapat dibatalkan!');">
                                        <input type="hidden" name="action" value="delete_user_permanent">
                                        <input type="hidden" name="id" value="<?= $usr['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="font-size:11.5px; padding:4px 8px;" title="Hapus permanen dari database">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <!-- ================================================================= -->
    <!-- MODE 2: MASTER AUDIT & ROLLBACK CENTER (EXISTING)                 -->
    <!-- ================================================================= -->
    <?php elseif ($mainMode === 'audit'): ?>

        <!-- TAB FILTER AUDIT -->
        <div class="nav-tabs" style="display:flex; gap:8px; margin-bottom:20px; border-bottom:2px solid #334155; padding-bottom:4px; overflow-x:auto;">
            <a href="?mode=audit&tab=all" class="mode-tab-link <?= $viewTab === 'all' ? 'active' : '' ?>">
                <i class="fa-solid fa-layer-group"></i> Semua Aktivitas (<?= number_format($countAll) ?>)
            </a>
            <a href="?mode=audit&tab=deleted_super" class="mode-tab-link <?= $viewTab === 'deleted_super' ? 'active' : '' ?>" style="color: <?= $viewTab === 'deleted_super' ? '#ffffff' : '#f87171' ?>;">
                <i class="fa-solid fa-trash-can-arrow-up"></i> 🗑️ Dihapus di Super Admin (<?= number_format($countDeletedSuper) ?>)
            </a>
            <a href="?mode=audit&tab=rolled_back" class="mode-tab-link <?= $viewTab === 'rolled_back' ? 'active' : '' ?>">
                <i class="fa-solid fa-rotate-left"></i> ↩️ Telah di-Rollback (<?= number_format($countRolledBack) ?>)
            </a>
        </div>

        <div class="card">
            <div class="filter-bar">
                <form method="GET" action="index.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
                    <input type="hidden" name="mode" value="audit">
                    <input type="hidden" name="tab" value="<?= e($viewTab) ?>">

                    <div style="flex: 1; min-width: 150px;">
                        <label style="font-size:11px; font-weight:700; color:#94a3b8; display:block; margin-bottom:4px;">Unit:</label>
                        <select name="unit_id" class="form-control" style="width:100%;">
                            <option value="">-- Semua Unit --</option>
                            <?php foreach ($unitsList as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $filterUnit === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex: 1; min-width: 130px;">
                        <label style="font-size:11px; font-weight:700; color:#94a3b8; display:block; margin-bottom:4px;">Aksi:</label>
                        <select name="action_type" class="form-control" style="width:100%;">
                            <option value="">-- Semua Aksi --</option>
                            <option value="CREATE" <?= $filterAction === 'CREATE' ? 'selected' : '' ?>>🟢 CREATE</option>
                            <option value="UPDATE" <?= $filterAction === 'UPDATE' ? 'selected' : '' ?>>🔵 UPDATE</option>
                            <option value="DELETE" <?= $filterAction === 'DELETE' ? 'selected' : '' ?>>🔴 DELETE</option>
                        </select>
                    </div>

                    <div style="flex: 1; min-width: 150px;">
                        <label style="font-size:11px; font-weight:700; color:#94a3b8; display:block; margin-bottom:4px;">Modul:</label>
                        <select name="module" class="form-control" style="width:100%;">
                            <option value="">-- Semua Modul --</option>
                            <?php foreach ($distinctModules as $dm): ?>
                                <option value="<?= e($dm) ?>" <?= $filterModule === $dm ? 'selected' : '' ?>><?= e($dm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex: 1; min-width: 130px;">
                        <label style="font-size:11px; font-weight:700; color:#94a3b8; display:block; margin-bottom:4px;">Tanggal:</label>
                        <input type="date" name="date" value="<?= e($filterDate) ?>" class="form-control" style="width:100%;">
                    </div>

                    <div style="flex: 2; min-width: 180px;">
                        <label style="font-size:11px; font-weight:700; color:#94a3b8; display:block; margin-bottom:4px;">Pencarian (User / ID / IP):</label>
                        <input type="text" name="q" value="<?= e($searchKeyword) ?>" placeholder="Cari..." class="form-control" style="width:100%;">
                    </div>

                    <div style="display:flex; gap:6px;">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
                        <a href="index.php?mode=audit&tab=<?= e($viewTab) ?>" class="btn btn-light">Reset</a>
                    </div>
                </form>
            </div>

            <!-- TABLE OF LOGS -->
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 130px;">Waktu</th>
                            <th>Pengguna & IP</th>
                            <th>Unit</th>
                            <th>Modul & Aksi</th>
                            <th>Status Super Admin</th>
                            <th style="text-align: center;">Snapshot</th>
                            <th style="text-align: center; width: 220px;">Aksi Rollback & Pulihkan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:32px; color:#64748b;">
                                    Belum ada log aktivitas yang cocok dengan filter.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($logs as $l): ?>
                            <?php
                            $isDelSuper = !empty($l['is_deleted_by_superadmin']);
                            $isRolled = !empty($l['is_rolled_back']);
                            $rowClass = '';
                            if ($isDelSuper) $rowClass = 'row-deleted';
                            elseif ($isRolled) $rowClass = 'row-rolledback';

                            $actionBadge = [
                                'CREATE' => ['bg' => 'rgba(34, 197, 94, 0.2)', 'color' => '#4ade80', 'text' => 'CREATE'],
                                'UPDATE' => ['bg' => 'rgba(56, 189, 248, 0.2)', 'color' => '#38bdf8', 'text' => 'UPDATE'],
                                'DELETE' => ['bg' => 'rgba(239, 68, 68, 0.2)', 'color' => '#f87171', 'text' => 'DELETE'],
                            ][$l['action']] ?? ['bg' => 'rgba(148, 163, 184, 0.2)', 'color' => '#94a3b8', 'text' => $l['action']];
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td style="font-size:12px; color:#94a3b8; font-family:'JetBrains Mono', monospace;">
                                    <strong style="color:#f1f5f9;"><?= date('d/m/Y', strtotime($l['created_at'])) ?></strong><br>
                                    <?= date('H:i:s', strtotime($l['created_at'])) ?>
                                </td>

                                <td>
                                    <strong style="color:#f1f5f9; font-size:13.5px;"><?= e($l['username'] ?: 'Sistem') ?></strong>
                                    <div style="font-size:11px; color:#94a3b8;">
                                        Role: <span style="color:#38bdf8;"><?= e($l['role'] ?: '-') ?></span> | IP: <?= e($l['ip_address'] ?: '-') ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($l['unit_name'])): ?>
                                        <span class="badge" style="background:#0369a1; color:#e0f2fe;"><?= e($l['unit_name']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#64748b; font-size:11px;">Global</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge" style="background:<?= $actionBadge['bg'] ?>; color:<?= $actionBadge['color'] ?>;">
                                        <?= $actionBadge['text'] ?>
                                    </span>
                                    <div style="font-weight:700; color:#f1f5f9; margin-top:2px;">
                                        <?= e($l['module'] ?: $l['table_name']) ?>
                                    </div>
                                    <small style="color:#94a3b8; font-family:'JetBrains Mono', monospace;">ID: <?= e($l['record_id']) ?></small>
                                </td>

                                <td>
                                    <?php if ($isDelSuper): ?>
                                        <span class="badge" style="background:#7f1d1d; color:#fca5a5; font-size:11px; padding:4px 8px; border:1px solid #dc2626;">
                                            <i class="fa-solid fa-trash-can"></i> DIHAPUS DI SUPER ADMIN
                                        </span>
                                        <div style="font-size:10.5px; color:#f87171; margin-top:2px;">
                                            Pada: <?= !empty($l['deleted_at']) ? date('d/m/y H:i', strtotime($l['deleted_at'])) : '-' ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge" style="background:#064e3b; color:#6ee7b7; font-size:10.5px;">
                                            <i class="fa-solid fa-eye"></i> Tampil di Super Admin
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($isRolled): ?>
                                        <div style="margin-top:4px;">
                                            <span class="badge" style="background:#78350f; color:#fcd34d; font-size:10.5px;">
                                                <i class="fa-solid fa-rotate-left"></i> Telah Di-Rollback
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align:center;">
                                    <button type="button" class="btn btn-light" 
                                            onclick="viewAuditDiff(<?= htmlspecialchars(json_encode($l), ENT_QUOTES, 'UTF-8') ?>)"
                                            style="font-size:11.5px; padding:4px 10px;">
                                        <i class="fa-solid fa-code"></i> Data
                                    </button>
                                </td>

                                <td style="text-align:center; white-space:nowrap;">
                                    <?php if (!$isRolled): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Yakin ingin me-ROLLBACK aktivitas ini? Data database akan dipulihkan ke versi sebelum aksi ini terjadi.');">
                                            <input type="hidden" name="action" value="rollback">
                                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                            <button type="submit" class="btn btn-warning" style="font-size:11px; padding:4px 8px;" title="Rollback data ke sebelum aksi ini">
                                                <i class="fa-solid fa-rotate-left"></i> Rollback
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($isDelSuper): ?>
                                        <form method="POST" style="display:inline; margin-left:4px;" onsubmit="return confirm('Pulihkan log ini agar kembali terlihat di menu Super Admin?');">
                                            <input type="hidden" name="action" value="restore_log">
                                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                            <button type="submit" class="btn btn-success" style="font-size:11px; padding:4px 8px;" title="Kembalikan log ke super admin">
                                                <i class="fa-solid fa-arrow-rotate-right"></i> Pulihkan
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION AUDIT -->
            <?php if ($totalPages > 1): ?>
                <div style="padding:16px 20px; background:#0f172a; border-top:1px solid #334155; display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:13px; color:#94a3b8;">
                        Halaman <strong><?= $page ?></strong> dari <strong><?= $totalPages ?></strong> (Total <?= number_format($totalRows) ?> log)
                    </div>
                    <div style="display:flex; gap:6px;">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-light">Sebelumnya</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-primary">Berikutnya</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ================================================================= -->
<!-- MODAL: TAMBAH AKUN CADANGAN                                       -->
<!-- ================================================================= -->
<div id="modalCreateUser" class="modal-overlay">
    <div class="modal-box">
        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="create_user">
            <div class="modal-header">
                <h4><i class="fa-solid fa-user-shield"></i> Tambah Akun Cadangan Darurat</h4>
                <button type="button" onclick="closeModal('modalCreateUser')" style="background:none; border:none; color:#94a3b8; font-size:22px; cursor:pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group-modal">
                    <label>Nama Lengkap <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="name" required placeholder="Contoh: Super Admin Cadangan">
                </div>
                <div class="form-group-modal">
                    <label>Username (Untuk Login) <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="username" required placeholder="Contoh: superadmin_cadangan" autocomplete="off">
                    <small>Gunakan huruf, angka, atau underscore tanpa spasi.</small>
                </div>
                <div class="form-group-modal">
                    <label>Password <span style="color:#ef4444;">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="inputNewPass" required placeholder="Minimal 4 karakter" autocomplete="new-password">
                        <button type="button" onclick="togglePassVisibility('inputNewPass')" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:#94a3b8; cursor:pointer;">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group-modal">
                    <label>Role / Tingkat Akses <span style="color:#ef4444;">*</span></label>
                    <select name="role" id="selectCreateRole" onchange="toggleUnitField('selectCreateRole', 'groupCreateUnit')">
                        <option value="super_admin" selected>👑 Super Admin (Akses Penuh Seluruh Sistem)</option>
                        <option value="kepala_sekolah">🏫 Kepala Sekolah</option>
                        <option value="admin">💼 Admin Unit / Pemantau</option>
                        <option value="staff">👤 Staff / Guru</option>
                        <option value="hrd">📊 HRD</option>
                    </select>
                </div>
                <div class="form-group-modal" id="groupCreateUnit" style="display:none;">
                    <label>Unit Sekolah <span style="color:#ef4444;">*</span></label>
                    <select name="unit_id">
                        <option value="">-- Pilih Unit Sekolah --</option>
                        <?php foreach ($unitsList as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Wajib dipilih jika memilih Kepala Sekolah atau Admin Unit.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closeModal('modalCreateUser')">Batal</button>
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk"></i> Simpan Akun Cadangan</button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================================= -->
<!-- MODAL: EDIT DATA AKUN                                             -->
<!-- ================================================================= -->
<div id="modalEditUser" class="modal-overlay">
    <div class="modal-box">
        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="id" id="editUserId">
            <div class="modal-header">
                <h4><i class="fa-solid fa-pen-to-square"></i> Edit Data Akun</h4>
                <button type="button" onclick="closeModal('modalEditUser')" style="background:none; border:none; color:#94a3b8; font-size:22px; cursor:pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group-modal">
                    <label>Nama Lengkap <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="name" id="editUserName" required>
                </div>
                <div class="form-group-modal">
                    <label>Username <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="username" id="editUserUsername" required autocomplete="off">
                </div>
                <div class="form-group-modal">
                    <label>Role / Tingkat Akses <span style="color:#ef4444;">*</span></label>
                    <select name="role" id="editUserRole" onchange="toggleUnitField('editUserRole', 'groupEditUnit')">
                        <option value="super_admin">👑 Super Admin</option>
                        <option value="kepala_sekolah">🏫 Kepala Sekolah</option>
                        <option value="admin">💼 Admin Unit / Pemantau</option>
                        <option value="staff">👤 Staff / Guru</option>
                        <option value="hrd">📊 HRD</option>
                    </select>
                </div>
                <div class="form-group-modal" id="groupEditUnit">
                    <label>Unit Sekolah</label>
                    <select name="unit_id" id="editUserUnit">
                        <option value="">-- Tidak Terikat Unit (Global) --</option>
                        <?php foreach ($unitsList as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group-modal">
                    <label>Password Baru (Opsional)</label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="editUserPass" placeholder="Kosongkan jika tidak ingin mengubah password" autocomplete="new-password">
                        <button type="button" onclick="togglePassVisibility('editUserPass')" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:#94a3b8; cursor:pointer;">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <small>Isi kolom ini hanya jika ingin mengganti kata sandi pengguna.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closeModal('modalEditUser')">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================================= -->
<!-- MODAL: RESET PASSWORD CEPAT                                       -->
<!-- ================================================================= -->
<div id="modalResetPassword" class="modal-overlay">
    <div class="modal-box" style="max-width: 480px;">
        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="resetUserId">
            <div class="modal-header">
                <h4><i class="fa-solid fa-key" style="color:#f59e0b;"></i> Reset Password Darurat</h4>
                <button type="button" onclick="closeModal('modalResetPassword')" style="background:none; border:none; color:#94a3b8; font-size:22px; cursor:pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div style="background:#0f172a; border:1px solid #334155; padding:12px; border-radius:8px; margin-bottom:16px;">
                    <div style="font-size:12px; color:#94a3b8;">Akun Target:</div>
                    <div style="font-size:14px; font-weight:700; color:#38bdf8;" id="resetTargetDisplay">@username (Nama Pengguna)</div>
                </div>

                <div class="form-group-modal">
                    <label>Password Baru <span style="color:#ef4444;">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="new_password" id="inputResetPass" required placeholder="Masukkan password baru" autocomplete="new-password">
                        <button type="button" onclick="togglePassVisibility('inputResetPass')" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:#94a3b8; cursor:pointer;">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <small>Password baru akan langsung dienkripsi dan diaktifkan seketika.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closeModal('modalResetPassword')">Batal</button>
                <button type="submit" class="btn btn-warning"><i class="fa-solid fa-shield-check"></i> Reset Password Sekarang</button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================================= -->
<!-- MODAL: SNAPSHOT DATA AUDIT DIFF                                   -->
<!-- ================================================================= -->
<div id="diffModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 850px; max-height: 85vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <div>
                <h4 id="diffTitle">Snapshot Data</h4>
                <small id="diffSub" style="color:#94a3b8; font-size:12px;"></small>
            </div>
            <button type="button" onclick="closeModal('diffModal')" style="border:none; background:transparent; font-size:24px; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>
        <div class="modal-body" id="diffBody" style="flex:1;"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" onclick="closeModal('diffModal')">Tutup</button>
        </div>
    </div>
</div>

<script>
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function openCreateUserModal() {
    document.getElementById('modalCreateUser').style.display = 'flex';
}

function openEditUserModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUserName').value = user.name || '';
    document.getElementById('editUserUsername').value = user.username || '';
    document.getElementById('editUserRole').value = user.role || 'super_admin';
    document.getElementById('editUserUnit').value = user.assigned_unit_id || '';
    document.getElementById('editUserPass').value = '';

    toggleUnitField('editUserRole', 'groupEditUnit');
    document.getElementById('modalEditUser').style.display = 'flex';
}

function openResetPasswordModal(id, username, name) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetTargetDisplay').innerText = '@' + username + ' (' + name + ')';
    document.getElementById('inputResetPass').value = '';
    document.getElementById('modalResetPassword').style.display = 'flex';
}

function toggleUnitField(roleSelectId, unitGroupId) {
    const sel = document.getElementById(roleSelectId);
    const grp = document.getElementById(unitGroupId);
    if (!sel || !grp) return;
    if (sel.value === 'kepala_sekolah' || sel.value === 'admin') {
        grp.style.display = 'block';
    } else {
        grp.style.display = 'none';
    }
}

function togglePassVisibility(inputId) {
    const inp = document.getElementById(inputId);
    if (inp) {
        inp.type = (inp.type === 'password') ? 'text' : 'password';
    }
}

function viewAuditDiff(log) {
    document.getElementById('diffTitle').innerText = 'Snapshot: ' + (log.module || log.table_name) + ' (' + log.action + ')';
    document.getElementById('diffSub').innerText = 'User: ' + (log.username || 'Sistem') + ' | ' + log.created_at + ' | IP: ' + (log.ip_address || '-');

    const body = document.getElementById('diffBody');
    let oldData = null;
    let newData = null;

    try { oldData = log.old_data ? JSON.parse(log.old_data) : null; } catch(e) { oldData = log.old_data; }
    try { newData = log.new_data ? JSON.parse(log.new_data) : null; } catch(e) { newData = log.new_data; }

    let html = '<div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">';
    
    // OLD DATA
    html += '<div style="background:#0f172a; border:1px solid #dc2626; border-radius:8px; padding:14px;">';
    html += '<h5 style="margin:0 0 10px 0; color:#f87171; font-size:13px; font-weight:700;"><i class="fa-solid fa-clock-rotate-left"></i> Data Sebelumnya (Old Data)</h5>';
    if (oldData) {
        html += '<pre style="margin:0; font-size:11.5px; background:#020617; color:#fca5a5; padding:10px; border-radius:6px; max-height:300px; overflow:auto; white-space:pre-wrap; font-family:\'JetBrains Mono\', monospace;">' + JSON.stringify(oldData, null, 2) + '</pre>';
    } else {
        html += '<div style="color:#64748b; font-size:12px; font-style:italic;">Data kosong (Penambahan baru).</div>';
    }
    html += '</div>';

    // NEW DATA
    html += '<div style="background:#0f172a; border:1px solid #16a34a; border-radius:8px; padding:14px;">';
    html += '<h5 style="margin:0 0 10px 0; color:#4ade80; font-size:13px; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Data Baru (New Data)</h5>';
    if (newData) {
        html += '<pre style="margin:0; font-size:11.5px; background:#020617; color:#86efac; padding:10px; border-radius:6px; max-height:300px; overflow:auto; white-space:pre-wrap; font-family:\'JetBrains Mono\', monospace;">' + JSON.stringify(newData, null, 2) + '</pre>';
    } else {
        html += '<div style="color:#64748b; font-size:12px; font-style:italic;">Data kosong (Data dihapus).</div>';
    }
    html += '</div>';

    html += '</div>';

    body.innerHTML = html;
    document.getElementById('diffModal').style.display = 'flex';
}

window.onclick = function(event) {
    if (event.target.classList && event.target.classList.contains('modal-overlay')) {
        event.target.style.display = 'none';
    }
};
</script>

</body>
</html>