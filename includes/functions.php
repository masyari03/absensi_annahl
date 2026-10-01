<?php

if (!function_exists('redirect')) {
    function redirect($url) {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('flash')) {
    function flash($type, $message) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'] = [
            'type'    => $type,
            'message' => $message
        ];
    }
}

if (!function_exists('getFlash')) {
    function getFlash() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }
}

// =========================================================================
// ACCESS CONTROL — DATABASE-DRIVEN ACL
// =========================================================================

/**
 * Ambil semua menu_key yang boleh diakses oleh $userId dari DB.
 * Fallback: jika user belum punya entri di user_menu_access, ambil dari default role.
 */
if (!function_exists('getUserMenuKeys')) {
    function getUserMenuKeys(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare("
            SELECT am.menu_key
            FROM user_menu_access uma
            INNER JOIN app_menus am ON am.id = uma.menu_id
            WHERE uma.user_id = ? AND am.is_active = 1
            ORDER BY am.sort_order ASC
        ");
        $stmt->execute([$userId]);
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($keys)) {
            return $keys;
        }

        // Fallback: ambil default permission dari role jika belum di-set per-user
        $stmtUser = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $roleKey = $stmtUser->fetchColumn();

        if ($roleKey) {
            if ($roleKey === 'super_admin') {
                $stmtAll = $pdo->query("SELECT menu_key FROM app_menus WHERE is_active = 1 ORDER BY sort_order ASC");
                return $stmtAll->fetchAll(PDO::FETCH_COLUMN);
            }

            $stmtRole = $pdo->prepare("
                SELECT am.menu_key
                FROM role_menu_access rma
                INNER JOIN roles r ON r.id = rma.role_id
                INNER JOIN app_menus am ON am.id = rma.menu_id
                WHERE r.role_key = ? AND am.is_active = 1
                ORDER BY am.sort_order ASC
            ");
            $stmtRole->execute([$roleKey]);
            $roleKeys = $stmtRole->fetchAll(PDO::FETCH_COLUMN);

            // Auto-seed ke user_menu_access agar request selanjutnya lebih cepat
            if (!empty($roleKeys)) {
                try {
                    $stmtInsert = $pdo->prepare("
                        INSERT IGNORE INTO user_menu_access (user_id, menu_id)
                        SELECT ?, rma.menu_id
                        FROM role_menu_access rma
                        INNER JOIN roles r ON r.id = rma.role_id
                        WHERE r.role_key = ?
                    ");
                    $stmtInsert->execute([$userId, $roleKey]);
                } catch (Exception $e) {
                    // Ignore insert duplicate
                }
            }

            return $roleKeys;
        }

        return [];
    }
}

/**
 * Dipanggil setelah login ATAU setelah Super Admin mengubah hak akses user.
 * Mengisi $_SESSION['menu_keys'] dengan daftar menu_key yang diizinkan.
 */
if (!function_exists('loadUserPermissions')) {
    function loadUserPermissions(PDO $pdo, int $userId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $role = $_SESSION['role'] ?? '';

        // Super Admin: akses ke semua menu aktif
        if ($role === 'super_admin') {
            $stmt = $pdo->query("
                SELECT menu_key FROM app_menus WHERE is_active = 1 ORDER BY sort_order ASC
            ");
            $_SESSION['menu_keys'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return;
        }

        $_SESSION['menu_keys'] = getUserMenuKeys($pdo, $userId);
    }
}

/**
 * Refresh session permissions user tertentu.
 */
if (!function_exists('refreshUserPermissions')) {
    function refreshUserPermissions(PDO $pdo, int $targetUserId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $currentUserId = (int)($_SESSION['user_id'] ?? 0);

        if ($targetUserId === $currentUserId) {
            loadUserPermissions($pdo, $currentUserId);
        }
    }
}

/**
 * FUNGSI UTAMA PENJAGA HALAMAN.
 *
 * @param string $menuKey  Nilai menu_key dari tabel app_menus.
 * @param string|null $redirect URL tujuan jika akses ditolak (default: dashboard).
 */
if (!function_exists('checkUserAccess')) {
    function checkUserAccess(string $menuKey, ?string $redirect = null): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Pastikan user sudah login
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }

        $role = $_SESSION['role'] ?? '';

        // Super Admin selalu lolos
        if ($role === 'super_admin') {
            return;
        }

        // Halaman Dashboard selalu diizinkan untuk semua user yang sudah login
        if ($menuKey === 'dashboard') {
            return;
        }

        // Jika belum ada di session, load dari DB
        if (!isset($_SESSION['menu_keys'])) {
            global $pdo;
            if (isset($pdo)) {
                loadUserPermissions($pdo, (int)$_SESSION['user_id']);
            }
        }

        $allowedKeys = $_SESSION['menu_keys'] ?? [];

        if (!in_array($menuKey, $allowedKeys)) {
            flash('error', 'Anda tidak memiliki hak akses untuk halaman tersebut.');
            $target = $redirect ?? BASE_URL . '/dashboard.php';
            header('Location: ' . $target);
            exit;
        }
    }
}

/**
 * Cek apakah user yang sedang login punya akses ke menu tertentu (tanpa redirect).
 */
if (!function_exists('hasMenuAccess')) {
    function hasMenuAccess(string $menuKey): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $role = $_SESSION['role'] ?? '';
        if ($role === 'super_admin') {
            return true;
        }

        if ($menuKey === 'dashboard') {
            return true;
        }

        if (!isset($_SESSION['menu_keys'])) {
            global $pdo;
            if (isset($pdo) && !empty($_SESSION['user_id'])) {
                loadUserPermissions($pdo, (int)$_SESSION['user_id']);
            }
        }

        $allowedKeys = $_SESSION['menu_keys'] ?? [];
        return in_array($menuKey, $allowedKeys);
    }
}

// =========================================================================
// ACCESS CONTROL SISWA (Filter berdasarkan izin unit/grade/kelas)
// =========================================================================

if (!function_exists('getStudentAccessCondition')) {
    function getStudentAccessCondition($gradeAlias = 'g', $classAlias = 'cg') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (($_SESSION['role'] ?? '') === 'super_admin') {
            return [
                'condition' => '1 = 1',
                'params'    => []
            ];
        }

        $uid = currentUserId();
        return [
            'condition' => "(
                {$gradeAlias}.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?)
                OR {$gradeAlias}.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?)
                OR {$classAlias}.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?)
            )",
            'params' => [
                $uid,
                $uid,
                $uid
            ]
        ];
    }
}

// =========================================================================
// UPLOAD FOTO
// =========================================================================

if (!function_exists('uploadStudentPhoto')) {
    function uploadStudentPhoto($file) {
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];

        $mime = mime_content_type($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            return null;
        }

        $extension = $allowed[$mime];
        $filename  = uniqid('student_', true) . '.' . $extension;

        $targetDir = defined('UPLOAD_STUDENT') ? UPLOAD_STUDENT : (dirname(__DIR__) . '/uploads/students/');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        move_uploaded_file($file['tmp_name'], $targetDir . $filename);
        return $filename;
    }
}

// =========================================================================
// PERIODE LAPORAN
// =========================================================================

if (!function_exists('getReportPeriod')) {
    function getReportPeriod() {
        $preset = $_GET['preset'] ?? 'month';
        $today  = new DateTime();

        if ($preset === 'today') {
            return [
                'start' => $today->format('Y-m-d'),
                'end'   => $today->format('Y-m-d')
            ];
        }

        if ($preset === 'week') {
            $monday = new DateTime('monday this week');
            $sunday = new DateTime('sunday this week');
            return [
                'start' => $monday->format('Y-m-d'),
                'end'   => $sunday->format('Y-m-d')
            ];
        }

        if ($preset === 'month') {
            return [
                'start' => $today->format('Y-m-01'),
                'end'   => $today->format('Y-m-t')
            ];
        }

        $start = $_GET['start_date'] ?? $today->format('Y-m-01');
        $end   = $_GET['end_date']   ?? $today->format('Y-m-d');

        return [
            'start' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : $today->format('Y-m-01'),
            'end'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)   ? $end   : $today->format('Y-m-d')
        ];
    }
}

// =========================================================================
// URL QUERY STRING
// =========================================================================

if (!function_exists('buildQuery')) {
    function buildQuery(array $params = []) {
        return http_build_query(
            array_merge($_GET, $params)
        );
    }
}

// =========================================================================
// USER ACTIVITY & LOGIN AUDIT LOGGING
// =========================================================================

if (!function_exists('getClientIp')) {
    function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ips = explode(',', $_SERVER[$h]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

if (!function_exists('parseUserAgent')) {
    function parseUserAgent(?string $ua = null): array
    {
        $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $device = 'Desktop';
        $platform = 'Unknown OS';
        $browser = 'Unknown Browser';

        // Deteksi Device
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile|mobile)/i', $ua)) {
            $device = 'Mobile';
        }

        // Deteksi Platform / OS
        if (preg_match('/windows nt 10/i', $ua)) {
            $platform = 'Windows 10/11';
        } elseif (preg_match('/windows nt 6.3/i', $ua)) {
            $platform = 'Windows 8.1';
        } elseif (preg_match('/windows nt 6.2/i', $ua)) {
            $platform = 'Windows 8';
        } elseif (preg_match('/windows nt 6.1/i', $ua)) {
            $platform = 'Windows 7';
        } elseif (preg_match('/windows/i', $ua)) {
            $platform = 'Windows';
        } elseif (preg_match('/iphone/i', $ua)) {
            $platform = 'iOS (iPhone)';
        } elseif (preg_match('/ipad/i', $ua)) {
            $platform = 'iOS (iPad)';
        } elseif (preg_match('/android/i', $ua)) {
            $platform = 'Android';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $platform = 'macOS';
        } elseif (preg_match('/linux/i', $ua)) {
            $platform = 'Linux';
        }

        // Deteksi Browser
        if (preg_match('/edg/i', $ua)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/chrome/i', $ua) && !preg_match('/opr|opera/i', $ua)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/firefox/i', $ua)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) {
            $browser = 'Apple Safari';
        } elseif (preg_match('/opr|opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/trident|msie/i', $ua)) {
            $browser = 'Internet Explorer';
        }

        return [
            'device'   => $device,
            'platform' => $platform,
            'browser'  => $browser
        ];
    }
}

if (!function_exists('recordUserLogin')) {
    function recordUserLogin(PDO $pdo, int $userId, string $username, string $role): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $ip = getClientIp();
        $uaString = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uaParsed = parseUserAgent($uaString);

        $isSuperadmin = ($role === 'super_admin') ? 1 : 0;

        try {
            // Update table users
            $stmtUser = $pdo->prepare("
                UPDATE users 
                SET last_login_at = NOW(), 
                    last_login_ip = ?, 
                    last_activity = NOW(), 
                    is_online = 1 
                WHERE id = ?
            ");
            $stmtUser->execute([$ip, $userId]);

            // Insert ke user_login_logs
            $stmtLog = $pdo->prepare("
                INSERT INTO user_login_logs (
                    user_id, username, role, is_superadmin, 
                    ip_address, user_agent, device_type, browser, platform, 
                    login_at, last_seen_at, status
                ) VALUES (
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, 
                    NOW(), NOW(), 'online'
                )
            ");
            $stmtLog->execute([
                $userId,
                $username,
                $role,
                $isSuperadmin,
                $ip,
                substr($uaString, 0, 500),
                $uaParsed['device'],
                $uaParsed['browser'],
                $uaParsed['platform']
            ]);

            $logId = (int)$pdo->lastInsertId();
            $_SESSION['login_log_id'] = $logId;
            $_SESSION['last_activity_update'] = time();

            return $logId;
        } catch (Exception $e) {
            error_log('Error recordUserLogin: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('recordUserActivity')) {
    function recordUserActivity(PDO $pdo, int $userId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $now = time();
        // Throttle pembaruan ke DB: maksimal 1x per 30 detik agar performa tetap kencang
        if (isset($_SESSION['last_activity_update']) && ($now - $_SESSION['last_activity_update']) < 30) {
            return;
        }
        $_SESSION['last_activity_update'] = $now;

        try {
            $stmt = $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1 WHERE id = ?");
            $stmt->execute([$userId]);

            if (!empty($_SESSION['login_log_id'])) {
                $stmtLog = $pdo->prepare("UPDATE user_login_logs SET last_seen_at = NOW(), status = 'online' WHERE id = ?");
                $stmtLog->execute([(int)$_SESSION['login_log_id']]);
            }
        } catch (Exception $e) {
            // Ignore background error
        }
    }
}

if (!function_exists('recordUserLogout')) {
    function recordUserLogout(PDO $pdo, int $userId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $stmt = $pdo->prepare("UPDATE users SET is_online = 0, last_activity = NOW() WHERE id = ?");
            $stmt->execute([$userId]);

            if (!empty($_SESSION['login_log_id'])) {
                $stmtLog = $pdo->prepare("UPDATE user_login_logs SET logout_at = NOW(), status = 'logged_out' WHERE id = ?");
                $stmtLog->execute([(int)$_SESSION['login_log_id']]);
            } else {
                $stmtLog = $pdo->prepare("
                    UPDATE user_login_logs 
                    SET logout_at = NOW(), status = 'logged_out' 
                    WHERE user_id = ? AND status = 'online' 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtLog->execute([$userId]);
            }
        } catch (Exception $e) {
            // Ignore error
        }
    }
}

if (!function_exists('formatTimeAgo')) {
    function formatTimeAgo(?string $datetime): string
    {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return 'Belum pernah';
        }

        $time = strtotime($datetime);
        if (!$time) return 'Tidak valid';

        $diff = time() - $time;
        if ($diff < 0) return 'Baru saja';
        if ($diff < 60) return $diff . ' detik yang lalu';
        if ($diff < 3600) return floor($diff / 60) . ' menit yang lalu';
        if ($diff < 86400) return floor($diff / 3600) . ' jam yang lalu';
        if ($diff < 172800) return 'Kemarin ' . date('H:i', $time);
        if ($diff < 604800) return floor($diff / 86400) . ' hari yang lalu';

        return date('d/m/Y H:i', $time);
    }
}