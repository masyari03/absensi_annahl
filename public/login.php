<?php
session_start();

require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/config.php';

// Kunci Rahasia untuk mengenkripsi Cookie (Jangan diubah agar cookie tetap valid)
$secretKey = 'Ais_Absensi_Secret_2026_!@#'; 

// =========================================================================
// CEK COOKIE "REMEMBER ME" JIKA BELUM ADA SESSION (TANPA KOLOM DATABASE)
// =========================================================================
if (empty($_SESSION['user_id']) && isset($_COOKIE['ais_remember'])) {
    // Pecah cookie menjadi ID dan Hash
    $cookieParts = explode('::', $_COOKIE['ais_remember']);
    
    if (count($cookieParts) === 2) {
        $cookieUserId = (int)$cookieParts[0];
        $cookieHash = $cookieParts[1];

        // Cari user berdasarkan ID
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$cookieUserId]);
        $userCookie = $stmt->fetch();

        if ($userCookie) {
            // Cocokkan kode hash (ID + Password DB + Secret Key)
            $validHash = hash('sha256', $userCookie['id'] . $userCookie['password'] . $secretKey);
            
            // Jika hash cocok (artinya password belum diganti & cookie valid), Auto Login!
            if (hash_equals($validHash, $cookieHash)) {
                $_SESSION['user_id'] = $userCookie['id'];
                $_SESSION['username'] = $userCookie['username'];
                $_SESSION['name'] = $userCookie['name'];
                $_SESSION['role'] = $userCookie['role'];
                
                header('Location: dashboard.php');
                exit;
            }
        }
    }
}

// JIKA SUDAH LOGIN, LANGSUNG KE DASHBOARD
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

// =========================================================================
// PROSES SUBMIT LOGIN
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) ? true : false; 

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, username, password, name, role
            FROM users
            WHERE username = ? AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && verifyPassword($password, $user['password'])) {
            
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];

            // Jika user mencentang "Ingat Saya"
            if ($remember) {
                // Buat Hash gabungan ID + Password DB + Secret Key
                $hashToStore = hash('sha256', $user['id'] . $user['password'] . $secretKey);
                $cookieValue = $user['id'] . '::' . $hashToStore;
                
                // Simpan Cookie selama 30 Hari
                setcookie('ais_remember', $cookieValue, time() + (86400 * 1), "/"); 
            } else {
                // Hapus cookie jika tidak dicentang
                setcookie('ais_remember', '', time() - 3600, "/");
            }

            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Username atau password salah.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login | AIS ABSENSI</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
    .remember-container {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: -5px;
        margin-bottom: 20px;
        cursor: pointer;
    }
    .remember-container input[type="checkbox"] {
        margin: 0;
        width: 16px;
        height: 16px;
        cursor: pointer;
    }
    .remember-container label {
        margin: 0;
        font-size: 14px;
        color: #475569;
        font-weight: 500;
        cursor: pointer;
        text-transform: none;
    }
</style>
</head>

<body class="login-page">

<div class="login-card">
    <div class="brand-icon" style="background:transparent; padding:0; display:flex; justify-content:center; align-items:center;">
        <img src="<?= BASE_URL ?>/logo.jpg" alt="Logo" style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover;">
    </div>

    <h1>An - Nahl Islamic School</h1>
    <p>Absensi Sekolah</p>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <label>Username</label>
        <!-- Penambahan atribut autocomplete membantu browser menyimpan & mengisi otomatis -->
        <input type="text" name="username" autocomplete="username" required autofocus>

        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password" required>

        <!-- FITUR INGAT SAYA -->
        <label class="remember-container">
            <input type="checkbox" name="remember" id="remember">
            <label for="remember">Ingat Saya</label>
        </label>

        <button type="submit">Masuk ke Sistem</button>
    </form>
</div>

</body>
</html>