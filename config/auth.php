<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


function requireLogin()
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}


function requireSuperAdmin()
{
    requireLogin();

    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        die('403 - Hanya Super Admin yang dapat mengakses halaman ini.');
    }
}


// --- FUNGSI BARU: KHUSUS SUPER ADMIN & KEPALA SEKOLAH (Admin Pemantau Ditolak) ---
function requireKepalaSekolah()
{
    requireLogin();

    if (!in_array($_SESSION['role'] ?? '', ['super_admin', 'kepala_sekolah'])) {
        http_response_code(403);
        die('403 - Hanya Super Admin dan Kepala Sekolah yang dapat mengakses halaman ini.');
    }
}


// --- FUNGSI requireAdmin DIPERBARUI: Kepala Sekolah Ditambahkan ---
function requireAdmin()
{
    requireLogin();

    if (
        !in_array(
            $_SESSION['role'] ?? '',
            ['super_admin', 'kepala_sekolah', 'admin']
        )
    ) {
        http_response_code(403);
        die('403 - Anda tidak memiliki akses.');
    }
}


function currentUserId()
{
    return $_SESSION['user_id'] ?? null;
}


function currentRole()
{
    return $_SESSION['role'] ?? null;
}