<?php

define(
    'PASSWORD_SALT',
    'ABSENSIKU_V4_2026'
);


function hashPassword($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}


function verifyPassword($password, $hash)
{
    if (empty($hash) || empty($password)) {
        return false;
    }

    // Modern password hash (bcrypt / argon2)
    if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$') || str_starts_with($hash, '$argon2')) {
        return password_verify($password, $hash);
    }

    // Fallback legacy SHA-256 hash
    $legacyHash = hash('sha256', PASSWORD_SALT . $password);
    return hash_equals($hash, $legacyHash);
}


function e($value)
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

function csrfToken()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrfToken($token = null)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken)) {
        return false;
    }
    $tokenToVerify = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return hash_equals($sessionToken, $tokenToVerify);
}

function requireCsrf()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken()) {
            http_response_code(403);
            die('403 - Akses Ditolak: Validasi CSRF Token Gagal. Silakan refresh halaman dan coba kembali.');
        }
    }
}