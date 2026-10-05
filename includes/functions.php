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

// =========================================================================
// SISTEM LOG AKTIVITAS & AUDIT TRAIL DATA DENGAN FITUR ROLLBACK
// =========================================================================

if (!function_exists('recordActivityAudit')) {
    function recordActivityAudit(
        PDO $pdo, 
        string $module, 
        string $action, 
        string $tableName, 
        $recordId, 
        ?string $description = '', 
        ?array $oldData = null, 
        ?array $newData = null, 
        ?int $unitId = null
    ): ?int {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }

        if (empty($description)) {
            $description = "$action pada $tableName #" . ($recordId ?: '-');
        }

        $userId = function_exists('currentUserId') ? (currentUserId() ?: null) : ($_SESSION['user_id'] ?? null);
        $username = function_exists('currentUserName') ? (currentUserName() ?: 'System') : ($_SESSION['username'] ?? 'System');
        $role = function_exists('currentRole') ? (currentRole() ?: 'system') : ($_SESSION['role'] ?? 'system');
        $ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // Jika unitId tidak diberikan, coba cari dari oldData atau newData
        if ($unitId === null) {
            $unitId = $oldData['unit_id'] ?? $newData['unit_id'] ?? null;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_audit_logs 
                (user_id, username, role, unit_id, module, action, table_name, record_id, description, old_data, new_data, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $username,
                $role,
                $unitId ? (int)$unitId : null,
                $module,
                $action,
                $tableName,
                $recordId ? (int)$recordId : null,
                $description,
                $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null,
                $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null,
                $ip
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            error_log('Audit Log Error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('rollbackActivityAudit')) {
    function rollbackActivityAudit(PDO $pdo, int $auditLogId, string $operator = 'Super Admin'): array
    {
        $stmt = $pdo->prepare("SELECT * FROM activity_audit_logs WHERE id = ?");
        $stmt->execute([$auditLogId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            return ['success' => false, 'message' => 'Log aktivitas tidak ditemukan.'];
        }

        if ((int)$log['is_rolled_back'] === 1) {
            return ['success' => false, 'message' => 'Aktivitas ini sudah pernah di-rollback sebelumnya pada ' . $log['rolled_back_at']];
        }

        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $log['table_name']);
        $action = $log['action'];
        $oldData = !empty($log['old_data']) ? json_decode($log['old_data'], true) : null;
        $newData = !empty($log['new_data']) ? json_decode($log['new_data'], true) : null;
        $recordId = (int)$log['record_id'];

        $pdo->beginTransaction();
        try {
            if ($action === 'DELETE') {
                if (!$oldData || !is_array($oldData)) {
                    throw new Exception('Data cadangan (snapshot lama) tidak tersedia untuk rollback penghapusan ini.');
                }

                // Cek apakah tabel memiliki deleted_at
                $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'deleted_at'");
                $hasDeletedAt = (bool)$colsStmt->fetch();

                if ($hasDeletedAt && !empty($oldData['id'])) {
                    // Coba cek apakah row masih ada tapi deleted_at terisi
                    $checkRow = $pdo->prepare("SELECT id FROM `{$table}` WHERE id = ?");
                    $checkRow->execute([$oldData['id']]);
                    if ($checkRow->fetch()) {
                        $pdo->prepare("UPDATE `{$table}` SET deleted_at = NULL WHERE id = ?")->execute([$oldData['id']]);
                    } else {
                        // Re-insert row
                        $oldData['deleted_at'] = null;
                        $fields = array_keys($oldData);
                        $placeholders = implode(',', array_fill(0, count($fields), '?'));
                        $colNames = implode('`,`', $fields);
                        $stmtIns = $pdo->prepare("INSERT INTO `{$table}` (`{$colNames}`) VALUES ({$placeholders})");
                        $stmtIns->execute(array_values($oldData));
                    }
                } else {
                    // Re-insert row
                    $fields = array_keys($oldData);
                    $placeholders = implode(',', array_fill(0, count($fields), '?'));
                    $colNames = implode('`,`', $fields);
                    $stmtIns = $pdo->prepare("INSERT INTO `{$table}` (`{$colNames}`) VALUES ({$placeholders})");
                    $stmtIns->execute(array_values($oldData));
                }
            } elseif ($action === 'BULK_DELETE') {
                if (!$oldData || !is_array($oldData)) {
                    throw new Exception('Data snapshot massal tidak ditemukan.');
                }
                foreach ($oldData as $item) {
                    if (is_array($item)) {
                        $fields = array_keys($item);
                        $placeholders = implode(',', array_fill(0, count($fields), '?'));
                        $colNames = implode('`,`', $fields);
                        $stmtIns = $pdo->prepare("INSERT IGNORE INTO `{$table}` (`{$colNames}`) VALUES ({$placeholders})");
                        $stmtIns->execute(array_values($item));
                    }
                }
            } elseif ($action === 'UPDATE') {
                if (!$oldData || !is_array($oldData)) {
                    throw new Exception('Data snapshot sebelum perubahan tidak tersedia.');
                }
                $setClauses = [];
                $params = [];
                foreach ($oldData as $col => $val) {
                    if ($col === 'id') continue;
                    $setClauses[] = "`{$col}` = ?";
                    $params[] = $val;
                }
                $params[] = $recordId ?: $oldData['id'];
                $sqlUp = "UPDATE `{$table}` SET " . implode(', ', $setClauses) . " WHERE id = ?";
                $pdo->prepare($sqlUp)->execute($params);
            } elseif ($action === 'CREATE') {
                // Rollback CREATE = hapus data yang baru dibuat
                if ($recordId > 0) {
                    $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'deleted_at'");
                    if ($colsStmt->fetch()) {
                        $pdo->prepare("UPDATE `{$table}` SET deleted_at = NOW() WHERE id = ?")->execute([$recordId]);
                    } else {
                        $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?")->execute([$recordId]);
                    }
                }
            }

            // Tandai log ini sudah di-rollback
            $stmtMark = $pdo->prepare("
                UPDATE activity_audit_logs 
                SET is_rolled_back = 1, rolled_back_at = NOW(), rolled_back_by = ? 
                WHERE id = ?
            ");
            $stmtMark->execute([$operator, $auditLogId]);

            // Catat log audit untuk tindakan rollback itu sendiri
            $opUserId = function_exists('currentUserId') ? (currentUserId() ?: null) : ($_SESSION['user_id'] ?? null);
            $opRole = function_exists('currentRole') ? (currentRole() ?: 'super_admin') : ($_SESSION['role'] ?? 'super_admin');
            $opIp = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $stmtLogRoll = $pdo->prepare("
                INSERT INTO activity_audit_logs 
                (user_id, username, role, unit_id, module, action, table_name, record_id, description, ip_address, created_at)
                VALUES (?, ?, ?, ?, 'rollback', 'ROLLBACK', ?, ?, ?, ?, NOW())
            ");
            $stmtLogRoll->execute([
                $opUserId,
                $operator,
                $opRole,
                $log['unit_id'],
                $table,
                $recordId,
                "Memulihkan (Rollback) aktivitas ID #{$auditLogId}: {$log['description']}",
                $opIp
            ]);

            $pdo->commit();
            return ['success' => true, 'message' => "Berhasil me-rollback data untuk: {$log['description']}"];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Gagal melakukan rollback: ' . $e->getMessage()];
        }
    }
}

// =========================================================================
// SISTEM CEK KONTROL ABSEN OTOMATIS (AUTO-ALPHA EXCLUSION)
// =========================================================================

if (!function_exists('isAutoAttendanceDisabled')) {
    function isAutoAttendanceDisabled(
        PDO $pdo, 
        ?int $unitId = null, 
        ?int $gradeId = null, 
        ?int $classGroupId = null, 
        ?int $studentId = null, 
        ?int $staffId = null,
        bool $forceReload = false
    ): bool {
        static $rulesCache = null;
        if ($rulesCache === null || $forceReload) {
            try {
                $stmt = $pdo->query("SELECT scope_type, target_id FROM auto_attendance_exclusions WHERE is_disabled = 1");
                $rulesCache = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $rulesCache = [];
            }
        }

        foreach ($rulesCache as $r) {
            $st = $r['scope_type'];
            $tid = (int)$r['target_id'];

            if ($st === 'global') return true;
            if ($unitId !== null && $st === 'unit' && $tid === (int)$unitId) return true;
            if ($gradeId !== null && $st === 'grade' && $tid === (int)$gradeId) return true;
            if ($classGroupId !== null && $st === 'class_group' && $tid === (int)$classGroupId) return true;
            if ($studentId !== null && $st === 'student' && $tid === (int)$studentId) return true;
            if ($staffId !== null && $st === 'staff' && $tid === (int)$staffId) return true;
        }

        return false;
    }
}

// =========================================================================
// JADWAL EFEKTIF SISWA (REGULER, ESKUL, AKTIVITAS KHUSUS)
// Aturan:
// - Jam Masuk: ambil yang paling awal (min) jika ada beberapa jadwal
// - Jam Pulang: ambil yang paling akhir (max) jika ada beberapa jadwal
// =========================================================================
if (!function_exists('getStudentEffectiveSchedule')) {
    function getStudentEffectiveSchedule(PDO $pdo, int $studentId, int $unitId, string $dateToday, int $dayCode): array {
        // 1. Ambil jadwal harian reguler dari activities atau weekly_schedules
        $stmtAct = $pdo->prepare("SELECT * FROM activities WHERE activity_date = ? AND unit_id = ? AND status = 'active' AND is_holiday = 'no' ORDER BY id ASC");
        $stmtAct->execute([$dateToday, $unitId]);
        $activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

        $schedules = [];

        if (!empty($activities)) {
            foreach ($activities as $act) {
                $schedules[] = [
                    'source' => 'activity',
                    'name' => $act['name'],
                    'student_in' => $act['student_in'],
                    'student_late' => $act['student_late'],
                    'student_out' => $act['student_out']
                ];
            }
        } else {
            // Resolusi berjenjang jadwal reguler siswa: Subkelas -> Grade -> Seluruh Unit
            $cgId = 0;
            $gId = 0;
            try {
                $stmtEnroll = $pdo->prepare("
                    SELECT se.class_group_id, cg.grade_id 
                    FROM student_enrollments se 
                    INNER JOIN class_groups cg ON cg.id = se.class_group_id
                    WHERE se.student_id = ? AND se.status = 'active' 
                    LIMIT 1
                ");
                $stmtEnroll->execute([$studentId]);
                $enroll = $stmtEnroll->fetch(PDO::FETCH_ASSOC);
                if ($enroll) {
                    $cgId = (int)$enroll['class_group_id'];
                    $gId = (int)$enroll['grade_id'];
                }
            } catch (Exception $e) {}

            $stmtWeek = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? 
                  AND day_code = ? 
                  AND is_active = 'active' 
                  AND (target_type = 'student' OR target_type IS NULL)
                  AND (schedule_type = 'reguler' OR schedule_type IS NULL)
                  AND (
                      class_group_id = ?
                      OR (grade_id = ? AND (class_group_id IS NULL OR class_group_id = 0))
                      OR ((grade_id IS NULL OR grade_id = 0) AND (class_group_id IS NULL OR class_group_id = 0))
                  )
                ORDER BY 
                  CASE 
                    WHEN class_group_id = ? AND class_group_id > 0 THEN 1
                    WHEN grade_id = ? AND grade_id > 0 THEN 2
                    ELSE 3
                  END ASC, 
                  id DESC 
                LIMIT 1
            ");
            $stmtWeek->execute([$unitId, $dayCode, $cgId, $gId, $cgId, $gId]);
            $weekly = $stmtWeek->fetch(PDO::FETCH_ASSOC);

            if (!$weekly) {
                // Fallback untuk kompatibilitas data lama
                $stmtFb = $pdo->prepare("SELECT * FROM weekly_schedules WHERE unit_id = ? AND day_code = ? AND (schedule_type = 'reguler' OR schedule_type IS NULL) AND is_active = 'active' ORDER BY id DESC LIMIT 1");
                $stmtFb->execute([$unitId, $dayCode]);
                $weekly = $stmtFb->fetch(PDO::FETCH_ASSOC);
            }

            if ($weekly) {
                $schedules[] = [
                    'source' => 'reguler',
                    'name' => $weekly['name'] ?: 'KBM Reguler',
                    'student_in' => $weekly['student_in'],
                    'student_late' => $weekly['student_late'],
                    'student_out' => $weekly['student_out']
                ];
            }
        }

        // 2. Ambil jadwal eskul siswa yang aktif pada hari ini
        $stmtEskul = $pdo->prepare("
            SELECT ws.* 
            FROM weekly_schedules ws
            INNER JOIN weekly_schedule_students wss ON wss.weekly_schedule_id = ws.id
            WHERE wss.student_id = ? AND ws.day_code = ? AND ws.schedule_type = 'eskul' AND ws.is_active = 'active'
        ");
        $stmtEskul->execute([$studentId, $dayCode]);
        $eskuls = $stmtEskul->fetchAll(PDO::FETCH_ASSOC);

        $eskulNames = [];
        foreach ($eskuls as $esk) {
            $eskulNames[] = $esk['name'];
            $schedules[] = [
                'source' => 'eskul',
                'name' => $esk['name'],
                'student_in' => $esk['student_in'],
                'student_late' => $esk['student_late'],
                'student_out' => $esk['student_out']
            ];
        }

        if (empty($schedules)) {
            return [
                'has_schedule' => false,
                'student_in' => null,
                'student_late' => null,
                'student_out' => null,
                'eskul_names' => []
            ];
        }

        // 3. Tentukan jam masuk paling awal (min) dan jam pulang paling akhir (max)
        $earliestIn = null;
        $lateForEarliest = null;
        $latestOut = null;
        $earliestScheduleName = '';
        $latestScheduleName = '';

        foreach ($schedules as $s) {
            if (!empty($s['student_in'])) {
                if ($earliestIn === null || $s['student_in'] < $earliestIn) {
                    $earliestIn = $s['student_in'];
                    $lateForEarliest = $s['student_late'] ?? $s['student_in'];
                    $earliestScheduleName = $s['name'];
                }
            }
            if (!empty($s['student_out'])) {
                if ($latestOut === null || $s['student_out'] > $latestOut) {
                    $latestOut = $s['student_out'];
                    $latestScheduleName = $s['name'];
                }
            }
        }

        return [
            'has_schedule' => true,
            'student_in' => $earliestIn,
            'student_late' => $lateForEarliest,
            'student_out' => $latestOut,
            'earliest_schedule_name' => $earliestScheduleName,
            'latest_schedule_name' => $latestScheduleName,
            'has_eskul' => !empty($eskulNames),
            'eskul_names' => $eskulNames,
            'all_schedules' => $schedules
        ];
    }
}

// =========================================================================
// JADWAL & SHIFT STAFF (TERMASUK SHIFT MALAM SECURITY CROSS-MIDNIGHT & ANTI-TABRAKAN)
// =========================================================================
if (!function_exists('getStaffShiftSchedule')) {
    function getStaffShiftSchedule(PDO $pdo, int $staffId, int $unitId, int $dayCode, ?string $currentTime = null): array {
        if ($currentTime === null) {
            $currentTime = date('H:i:s');
        }
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');
        $currentSeconds = strtotime($currentTime);

        // 1. PRIORITAS 1: Cek apakah staff ini secara spesifik ditugaskan ke shift tertentu
        if ($staffId > 0) {
            $stmtAssigned = $pdo->prepare("
                SELECT ws.* 
                FROM weekly_schedules ws
                INNER JOIN weekly_schedule_staff wss ON wss.weekly_schedule_id = ws.id
                WHERE wss.staff_id = ? AND ws.day_code = ? AND ws.is_active = 'active'
                LIMIT 1
            ");
            $stmtAssigned->execute([$staffId, $dayCode]);
            $assignedSched = $stmtAssigned->fetch(PDO::FETCH_ASSOC);

            if ($assignedSched) {
                $isOvernight = !empty($assignedSched['is_overnight']) || ($assignedSched['staff_in'] > $assignedSched['staff_out']);
                return [
                    'has_schedule' => true,
                    'id' => (int)$assignedSched['id'],
                    'schedule_id' => (int)$assignedSched['id'],
                    'is_overnight' => $isOvernight,
                    'staff_in' => $assignedSched['staff_in'],
                    'staff_late' => $assignedSched['staff_late'],
                    'staff_out' => $assignedSched['staff_out'],
                    'name' => $assignedSched['name'] ?: 'Shift Staff',
                    'assignment_type' => 'specific_staff'
                ];
            }
        }

        // 2. PRIORITAS 2: Deteksi Status Presensi Nyata (Cegah Tabrakan Shift 1 vs Shift 2)
        // A. Cek apakah ada sesi shift malam kemarin yang masih terbuka (belum scan pulang)
        $hasYesterdayOpenNightShift = false;
        if ($staffId > 0 && $currentSeconds >= strtotime('04:00:00') && $currentSeconds <= strtotime('12:00:00')) {
            $stmtYestOpen = $pdo->prepare("
                SELECT id FROM staff_attendances 
                WHERE staff_id = ? AND attendance_date = ? 
                  AND time_in IS NOT NULL AND time_in != '00:00:00'
                  AND (time_out IS NULL OR time_out = '00:00:00')
                LIMIT 1
            ");
            $stmtYestOpen->execute([$staffId, $yesterday]);
            if ($stmtYestOpen->fetchColumn()) {
                $hasYesterdayOpenNightShift = true;
            }
        }

        if ($hasYesterdayOpenNightShift) {
            // Ambil jadwal shift malam untuk unit ini
            $stmtNight = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? AND is_active = 'active' AND (target_type = 'staff' OR target_type IS NULL)
                  AND (is_overnight = 1 OR staff_in > staff_out)
                ORDER BY id ASC LIMIT 1
            ");
            $stmtNight->execute([$unitId]);
            $nightSched = $stmtNight->fetch(PDO::FETCH_ASSOC);
            if ($nightSched) {
                return [
                    'has_schedule' => true,
                    'id' => (int)$nightSched['id'],
                    'schedule_id' => (int)$nightSched['id'],
                    'is_overnight' => true,
                    'staff_in' => $nightSched['staff_in'],
                    'staff_late' => $nightSched['staff_late'],
                    'staff_out' => $nightSched['staff_out'],
                    'name' => $nightSched['name'] ?: 'Shift Malam Security',
                    'assignment_type' => 'open_night_shift'
                ];
            }
        }

        // B. Cek apakah ada sesi shift siang hari ini yang sedang berjalan (sudah scan masuk, belum scan pulang)
        $hasTodayOpenDayShift = false;
        if ($staffId > 0 && $currentSeconds >= strtotime('15:00:00') && $currentSeconds <= strtotime('22:00:00')) {
            $stmtTodayOpen = $pdo->prepare("
                SELECT id FROM staff_attendances 
                WHERE staff_id = ? AND attendance_date = ? 
                  AND time_in IS NOT NULL AND time_in != '00:00:00'
                  AND (time_out IS NULL OR time_out = '00:00:00')
                LIMIT 1
            ");
            $stmtTodayOpen->execute([$staffId, $today]);
            if ($stmtTodayOpen->fetchColumn()) {
                $hasTodayOpenDayShift = true;
            }
        }

        if ($hasTodayOpenDayShift) {
            // Ambil jadwal shift siang (non-overnight) untuk unit ini
            $stmtDay = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? AND day_code = ? AND is_active = 'active' AND (target_type = 'staff' OR target_type IS NULL)
                  AND (is_overnight = 0 AND staff_in <= staff_out)
                ORDER BY id ASC LIMIT 1
            ");
            $stmtDay->execute([$unitId, $dayCode]);
            $daySched = $stmtDay->fetch(PDO::FETCH_ASSOC);
            if ($daySched) {
                return [
                    'has_schedule' => true,
                    'id' => (int)$daySched['id'],
                    'schedule_id' => (int)$daySched['id'],
                    'is_overnight' => false,
                    'staff_in' => $daySched['staff_in'],
                    'staff_late' => $daySched['staff_late'],
                    'staff_out' => $daySched['staff_out'],
                    'name' => $daySched['name'] ?: 'Shift Siang Security',
                    'assignment_type' => 'open_day_shift'
                ];
            }
        }

        // 3. PRIORITAS 3: Berdasarkan Kedekatan Jam Kedatangan (Masuk Shift Terdekat)
        $stmtAll = $pdo->prepare("
            SELECT * FROM weekly_schedules 
            WHERE unit_id = ? AND day_code = ? AND is_active = 'active' AND (target_type = 'staff' OR target_type IS NULL)
            ORDER BY is_overnight ASC, id ASC
        ");
        $stmtAll->execute([$unitId, $dayCode]);
        $allShifts = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        // Fallback untuk jadwal legacy jika target_type = 'staff' belum ada
        if (empty($allShifts)) {
            $stmtLegacy = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? AND day_code = ? AND is_active = 'active' AND (staff_in IS NOT NULL AND staff_in != '00:00:00')
                ORDER BY is_overnight ASC, id ASC
            ");
            $stmtLegacy->execute([$unitId, $dayCode]);
            $allShifts = $stmtLegacy->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fallback jika hari ini belum terdaftar jadwal, ambil template shift dari hari lain di unit yang sama
        if (empty($allShifts)) {
            $stmtAny = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? AND is_active = 'active' 
                  AND (target_type = 'staff' OR (staff_in IS NOT NULL AND staff_in != '00:00:00'))
                ORDER BY id ASC LIMIT 1
            ");
            $stmtAny->execute([$unitId]);
            $any = $stmtAny->fetch(PDO::FETCH_ASSOC);

            return [
                'has_schedule' => true,
                'id' => $any ? (int)$any['id'] : 0,
                'schedule_id' => $any ? (int)$any['id'] : 0,
                'is_overnight' => !empty($any['is_overnight']),
                'staff_in' => $any['staff_in'] ?? '06:45:00',
                'staff_late' => $any['staff_late'] ?? '07:00:00',
                'staff_out' => $any['staff_out'] ?? '15:30:00',
                'name' => $any['name'] ?: 'Shift Reguler Staff'
            ];
        }

        if (count($allShifts) === 1) {
            $s = $allShifts[0];
            $isOvernight = !empty($s['is_overnight']) || ($s['staff_in'] > $s['staff_out']);
            return [
                'has_schedule' => true,
                'id' => (int)$s['id'],
                'schedule_id' => (int)$s['id'],
                'is_overnight' => $isOvernight,
                'staff_in' => $s['staff_in'],
                'staff_late' => $s['staff_late'],
                'staff_out' => $s['staff_out'],
                'name' => $s['name'] ?: 'Shift Staff'
            ];
        }

        // Jika terdapat lebih dari 1 shift (misal Shift 1 Siang 06:00 vs Shift 2 Malam 18:00):
        $selectedShift = null;
        $minDiff = PHP_INT_MAX;

        foreach ($allShifts as $s) {
            $shiftInSec = strtotime($s['staff_in']);
            $diff = abs($currentSeconds - $shiftInSec);
            if ($diff > 43200) {
                $diff = 86400 - $diff;
            }
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $selectedShift = $s;
            }
        }

        $s = $selectedShift ?: $allShifts[0];
        $isOvernight = !empty($s['is_overnight']) || ($s['staff_in'] > $s['staff_out']);
        return [
            'has_schedule' => true,
            'id' => (int)$s['id'],
            'schedule_id' => (int)$s['id'],
            'is_overnight' => $isOvernight,
            'staff_in' => $s['staff_in'],
            'staff_late' => $s['staff_late'],
            'staff_out' => $s['staff_out'],
            'name' => $s['name'] ?: 'Shift Staff'
        ];
    }
}

if (!function_exists('getDailyActivity')) {
    function getDailyActivity(PDO $pdo, int $unitId, string $dateToday, int $dayCode, string $targetType = 'student'): ?array {
        $stmt = $pdo->prepare("
            SELECT * FROM activities 
            WHERE activity_date = ? AND unit_id = ? 
              AND (target_type = ? OR target_type = 'all' OR target_type IS NULL) 
            ORDER BY (target_type = ?) DESC, id ASC 
            LIMIT 1
        ");
        $stmt->execute([$dateToday, $unitId, $targetType, $targetType]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activity) {
            $stmtWeek = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE day_code = ? AND unit_id = ? AND is_active = 'active' 
                  AND (target_type = ? OR target_type IS NULL) 
                  AND (schedule_type = 'reguler' OR schedule_type IS NULL) 
                ORDER BY (grade_id IS NULL AND class_group_id IS NULL) DESC, id ASC 
                LIMIT 1
            ");
            $stmtWeek->execute([$dayCode, $unitId, $targetType]);
            $weekly = $stmtWeek->fetch(PDO::FETCH_ASSOC);
            
            // Fallback 1: Jika staff tapi belum ada target_type = 'staff', cari jadwal legacy dengan staff_in valid
            if (!$weekly && $targetType === 'staff') {
                $stmtFallback = $pdo->prepare("
                    SELECT * FROM weekly_schedules 
                    WHERE day_code = ? AND unit_id = ? AND is_active = 'active' 
                      AND (staff_in IS NOT NULL AND staff_in != '00:00:00')
                    ORDER BY id ASC LIMIT 1
                ");
                $stmtFallback->execute([$dayCode, $unitId]);
                $weekly = $stmtFallback->fetch(PDO::FETCH_ASSOC);
            }

            // Fallback 2: Jika masih kosong untuk staff, ambil template hari lain di unit yang sama atau default jam kerja
            if (!$weekly && $targetType === 'staff') {
                $stmtAny = $pdo->prepare("
                    SELECT * FROM weekly_schedules 
                    WHERE unit_id = ? AND is_active = 'active' 
                      AND (target_type = 'staff' OR (staff_in IS NOT NULL AND staff_in != '00:00:00'))
                    ORDER BY id ASC LIMIT 1
                ");
                $stmtAny->execute([$unitId]);
                $anyStaff = $stmtAny->fetch(PDO::FETCH_ASSOC);

                $weekly = [
                    'student_in'   => '00:00:00',
                    'student_late' => '00:00:00',
                    'student_out'  => '00:00:00',
                    'staff_in'     => $anyStaff['staff_in'] ?? '06:45:00',
                    'staff_late'   => $anyStaff['staff_late'] ?? '07:00:00',
                    'staff_out'    => $anyStaff['staff_out'] ?? '15:30:00',
                    'name'         => 'Jadwal Reguler Staff'
                ];
            }

            if ($weekly) {
                $stmtAy = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1");
                $ay = $stmtAy->fetch(PDO::FETCH_ASSOC);
                $ayId = $ay ? $ay['id'] : 1;

                $actName = ($targetType === 'staff') ? "Jadwal Reguler Staff & Guru" : "Jadwal Reguler Siswa";

                $insertAct = $pdo->prepare("
                    INSERT INTO activities 
                    (academic_year_id, unit_id, target_type, name, activity_date, student_in, student_late, student_out, staff_in, staff_late, staff_out, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $insertAct->execute([
                    $ayId, $unitId, $targetType, $actName, $dateToday, 
                    $weekly['student_in'] ?? '00:00:00', $weekly['student_late'] ?? '00:00:00', $weekly['student_out'] ?? '00:00:00', 
                    $weekly['staff_in'] ?? '06:45:00', $weekly['staff_late'] ?? '07:00:00', $weekly['staff_out'] ?? '15:30:00'
                ]);
                $stmt->execute([$dateToday, $unitId, $targetType, $targetType]);
                $activity = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        return $activity ?: null;
    }
}