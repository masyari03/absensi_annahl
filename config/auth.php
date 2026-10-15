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