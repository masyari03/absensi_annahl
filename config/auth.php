<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!function_exists('requireLogin')) {
    function requireLogin()
    {
        if (empty($_SESSION['user_id'])) {
            $loginUrl = defined('BASE_URL') ? BASE_URL . '/login.php' : '/public/login.php';
            header('Location: ' . $loginUrl);
            exit;
        }

        global $pdo;
        if (isset($pdo)) {
            // Cek apakah sesi ini telah diputus secara paksa oleh Super Admin
            if (!empty($_SESSION['login_log_id'])) {
                try {
                    $stmtCheck = $pdo->prepare("SELECT status FROM user_login_logs WHERE id = ?");
                    $stmtCheck->execute([(int)$_SESSION['login_log_id']]);
                    $sessStatus = $stmtCheck->fetchColumn();

                    if ($sessStatus === 'revoked' || $sessStatus === 'logged_out') {
                        $_SESSION = array();
                        if (ini_get("session.use_cookies")) {
                            $params = session_get_cookie_params();
                            setcookie(session_name(), '', time() - 42000,
                                $params["path"], $params["domain"],
                                $params["secure"], $params["httponly"]
                            );
                        }
                        session_destroy();
                        if (isset($_COOKIE['ais_remember'])) {
                            setcookie('ais_remember', '', time() - 3600, "/");
                        }
                        $loginUrl = defined('BASE_URL') ? BASE_URL . '/login.php?revoked=1' : '/public/login.php?revoked=1';
                        header('Location: ' . $loginUrl);
                        exit;
                    }
                } catch (Exception $e) {}
            }

            if (function_exists('recordUserActivity')) {
                recordUserActivity($pdo, (int)$_SESSION['user_id']);
            }
        }
    }
}

if (!function_exists('requireSuperAdmin')) {
    function requireSuperAdmin()
    {
        requireLogin();

        if (($_SESSION['role'] ?? '') !== 'super_admin') {
            http_response_code(403);
            die('403 - Hanya Super Admin yang dapat mengakses halaman ini.');
        }
    }
}

// --- FUNGSI KHUSUS SUPER ADMIN & KEPALA SEKOLAH ---
if (!function_exists('requireKepalaSekolah')) {
    function requireKepalaSekolah()
    {
        requireLogin();

        if (!in_array($_SESSION['role'] ?? '', ['super_admin', 'kepala_sekolah'])) {
            http_response_code(403);
            die('403 - Hanya Super Admin dan Kepala Sekolah yang dapat mengakses halaman ini.');
        }
    }
}

// --- FUNGSI requireAdmin (Super Admin, Kepala Sekolah, Admin) ---
if (!function_exists('requireAdmin')) {
    function requireAdmin()
    {
        requireLogin();

        if (!in_array($_SESSION['role'] ?? '', ['super_admin', 'kepala_sekolah', 'admin'])) {
            http_response_code(403);
            die('403 - Anda tidak memiliki akses.');
        }
    }
}

if (!function_exists('currentUserId')) {
    function currentUserId()
    {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('currentRole')) {
    function currentRole()
    {
        return $_SESSION['role'] ?? null;
    }
}

if (!function_exists('currentUserName')) {
    function currentUserName()
    {
        return $_SESSION['name'] ?? $_SESSION['username'] ?? null;
    }
}

if (!function_exists('requireStudent')) {
    function requireStudent()
    {
        requireLogin();
        if (($_SESSION['role'] ?? '') !== 'siswa') {
            http_response_code(403);
            die('403 - Akses hanya untuk siswa.');
        }
    }
}

if (!function_exists('requireRole')) {
    function requireRole(...$roles)
    {
        requireLogin();
        $allowed = is_array($roles[0] ?? null) ? $roles[0] : $roles;
        if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
            http_response_code(403);
            die('403 - Akses Ditolak: Anda tidak memiliki izin untuk halaman ini.');
        }
    }
}

if (!function_exists('isStudent')) {
    function isStudent(): bool
    {
        return ($_SESSION['role'] ?? '') === 'siswa';
    }
}

/**
 * =========================================================================
 * SISTEM RBAC FLEKSIBEL (DATABASE-DRIVEN ACL)
 * =========================================================================
 */

if (!function_exists('hasMenuAccess')) {
    /**
     * Memeriksa apakah user saat ini memiliki hak akses terhadap menu_key tertentu.
     * Super Admin selalu diizinkan (true).
     *
     * @param string $menuKey Kode unik menu (misal: 'mentoring_report', 'mentoring_assignments')
     * @param int|null $userId ID pengguna (opsional, default: session saat ini)
     * @return bool
     */
    function hasMenuAccess(string $menuKey, ?int $userId = null): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $currentRole = $_SESSION['role'] ?? '';
        if ($currentRole === 'super_admin') {
            return true;
        }

        $uid = $userId !== null ? $userId : (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }

        // Cek cache session jika user adalah user yang sedang aktif login
        if ($uid === (int)($_SESSION['user_id'] ?? 0) && isset($_SESSION['menu_keys']) && is_array($_SESSION['menu_keys'])) {
            if (in_array($menuKey, $_SESSION['menu_keys'], true)) {
                return true;
            }
        }

        // Cek langsung ke database (user_menu_access atau fallback role_menu_access)
        global $pdo;
        if (isset($pdo)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM app_menus am
                    LEFT JOIN user_menu_access uma ON uma.menu_id = am.id AND uma.user_id = ?
                    LEFT JOIN role_menu_access rma ON rma.menu_id = am.id
                    LEFT JOIN roles r ON r.id = rma.role_id
                    LEFT JOIN users u ON u.id = ? AND u.role COLLATE utf8mb4_general_ci = r.role_key
                    WHERE am.menu_key = ? AND am.is_active = 1
                      AND (uma.user_id IS NOT NULL OR u.id IS NOT NULL)
                ");
                $stmt->execute([$uid, $uid, $menuKey]);
                $allowed = ($stmt->fetchColumn() > 0);

                if ($allowed && $uid === (int)($_SESSION['user_id'] ?? 0)) {
                    if (!isset($_SESSION['menu_keys']) || !is_array($_SESSION['menu_keys'])) {
                        $_SESSION['menu_keys'] = [];
                    }
                    if (!in_array($menuKey, $_SESSION['menu_keys'], true)) {
                        $_SESSION['menu_keys'][] = $menuKey;
                    }
                }
                return $allowed;
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }
}

if (!function_exists('requireMenuAccess')) {
    /**
     * Memproteksi rute/halaman berdasarkan menu_key.
     * Jika tidak memiliki izin, otomatis mengembalikan respons 403 Forbidden berdesain elegan.
     *
     * @param string $menuKey
     */
    function requireMenuAccess(string $menuKey)
    {
        requireLogin();

        if (!hasMenuAccess($menuKey)) {
            http_response_code(403);
            $backUrl = defined('BASE_URL') ? BASE_URL . '/dashboard.php' : '../dashboard.php';
            ?>
            <!DOCTYPE html>
            <html lang="id">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>403 - Akses Terbatas | AIS</title>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
                <style>
                    body { margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
                    .box { background: #1e293b; border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 36px 32px; max-width: 480px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
                    .icon { width: 64px; height: 64px; background: rgba(239, 68, 68, 0.15); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #ef4444; font-size: 28px; margin-bottom: 16px; }
                    h2 { margin: 0 0 8px; font-size: 20px; font-weight: 800; color: #ffffff; }
                    p { margin: 0 0 24px; font-size: 13.5px; color: #94a3b8; line-height: 1.6; }
                    .badge { display: inline-block; padding: 4px 10px; background: rgba(255,255,255,0.06); border-radius: 6px; font-family: monospace; font-size: 12px; color: #38bdf8; margin-bottom: 20px; }
                    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #0284c7; color: white; text-decoration: none; border-radius: 8px; font-size: 13.5px; font-weight: 700; transition: all 0.2s; }
                    .btn:hover { background: #0369a1; }
                </style>
            </head>
            <body>
                <div class="box">
                    <div class="icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <h2>Akses Dibatasi</h2>
                    <p>Maaf, akun Anda tidak memiliki hak akses untuk membuka halaman ini. Silakan hubungi Super Admin jika Anda memerlukan wewenang pada modul ini.</p>
                    <div class="badge">Izin yang dibutuhkan: <?= htmlspecialchars($menuKey) ?></div>
                    <div>
                        <a href="<?= $backUrl ?>" class="btn"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}

if (!function_exists('getUserAllowedUnitIds')) {
    /**
     * Mengambil ID Unit yang boleh diakses oleh user saat ini.
     * Jika Super Admin, mengembalikan array kosong [] (artinya bebas akses semua unit).
     *
     * @param PDO $pdo
     * @param int|null $userId
     * @return array
     */
    function getUserAllowedUnitIds(PDO $pdo, ?int $userId = null): array
    {
        $role = currentRole();
        if ($role === 'super_admin') {
            return [];
        }
        $uid = $userId !== null ? $userId : (int)currentUserId();
        if ($uid <= 0) {
            return [0];
        }

        try {
            // Cek apakah tabel users memiliki unit_id
            $stmt = $pdo->prepare("SELECT unit_id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $unitId = $stmt->fetchColumn();
            if ($unitId) {
                return [(int)$unitId];
            }
        } catch (Exception $e) {}

        return [];
    }
}