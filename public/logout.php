<?php
session_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$studentId = (int)($_SESSION['student_id'] ?? 0);
$userName = $_SESSION['username'] ?? 'User';

if ($studentId > 0 && isset($pdo) && function_exists('logStudentActivity')) {
    logStudentActivity($pdo, $studentId, $userId, 'logout', 'Siswa logout dari portal siswa');
}

if ($userId > 0 && isset($pdo)) {
    if (function_exists('recordActivityAudit')) {
        recordActivityAudit($pdo, 'auth', 'LOGOUT', 'users', $userId, "Pengguna {$userName} logout dari sistem");
    }
    if (function_exists('recordUserLogout')) {
        recordUserLogout($pdo, $userId);
    }
}

// 1. Hapus semua data session
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Hancurkan session
session_destroy();

// 2. Hapus Cookie "Ingat Saya" (ais_remember) agar bisa logout dengan bersih
if (isset($_COOKIE['ais_remember'])) {
    setcookie('ais_remember', '', time() - 3600, "/");
}

// 3. Arahkan kembali ke halaman login
header('Location: login.php');
exit;