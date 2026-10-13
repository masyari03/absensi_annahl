<?php
// includes/device_detector.php
// Forensic helper for device detection, IP detection, system settings, and student audit logging

if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ipList = explode(',', $_SERVER[$header]);
                $ip = trim($ipList[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '127.0.0.1';
    }
}

if (!function_exists('detectClientDevice')) {
    function detectClientDevice(?string $userAgent = null): string {
        $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (empty($ua)) {
            return 'Unknown Device';
        }

        $device = 'Perangkat Tidak Dikenal';
        $os = 'OS Tidak Dikenal';
        $browser = 'Browser';

        // 1. Detect OS
        if (preg_match('/Android\s+([0-9\.]+)/i', $ua, $matches)) {
            $os = 'Android ' . $matches[1];
        } elseif (preg_match('/iPhone OS\s+([0-9_]+)/i', $ua, $matches)) {
            $os = 'iOS ' . str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/iPad.*OS\s+([0-9_]+)/i', $ua, $matches)) {
            $os = 'iPadOS ' . str_replace('_', '.', $matches[1]);
        } elseif (stripos($ua, 'Windows NT 10.0') !== false) {
            $os = 'Windows 10/11';
        } elseif (stripos($ua, 'Windows NT 6.3') !== false) {
            $os = 'Windows 8.1';
        } elseif (stripos($ua, 'Windows NT 6.1') !== false) {
            $os = 'Windows 7';
        } elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        }

        // 2. Detect Specific Phone Models / Brands
        if (stripos($ua, 'iPhone') !== false) {
            $device = 'Apple iPhone';
        } elseif (stripos($ua, 'iPad') !== false) {
            $device = 'Apple iPad';
        } elseif (preg_match('/(SM-[A-Z0-9]+|GT-[A-Z0-9]+|SCH-[A-Z0-9]+)/i', $ua, $m)) {
            $device = 'Samsung ' . strtoupper($m[1]);
        } elseif (stripos($ua, 'SAMSUNG') !== false) {
            $device = 'Samsung Galaxy';
        } elseif (preg_match('/(Redmi|Mi|POCO)\s*([A-Za-z0-9\+\s]+)?/i', $ua, $m)) {
            $device = 'Xiaomi ' . trim($m[0]);
        } elseif (preg_match('/(CPH[0-9]+|OPPO\s*[A-Za-z0-9]+)/i', $ua, $m)) {
            $device = 'Oppo ' . strtoupper($m[1]);
        } elseif (preg_match('/(V[0-9]{4}[A-Za-z]*|vivo\s*[A-Za-z0-9]+)/i', $ua, $m)) {
            $device = 'Vivo ' . trim($m[0]);
        } elseif (preg_match('/(RMX[0-9]+|realme\s*[A-Za-z0-9]+)/i', $ua, $m)) {
            $device = 'Realme ' . trim($m[0]);
        } elseif (preg_match('/(Infinix\s*[A-Za-z0-9\-]+|X[0-9]{3}[A-Za-z]?)/i', $ua, $m)) {
            $device = 'Infinix ' . trim($m[0]);
        } elseif (preg_match('/(TECNO\s*[A-Za-z0-9\-]+)/i', $ua, $m)) {
            $device = 'Tecno ' . trim($m[0]);
        } elseif (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false) {
            $device = 'Smartphone Android';
        } elseif (stripos($ua, 'Windows') !== false) {
            $device = 'Windows PC';
        } elseif (stripos($ua, 'Macintosh') !== false) {
            $device = 'Mac Desktop/Laptop';
        }

        // 3. Detect Browser
        if (stripos($ua, 'SamsungBrowser') !== false) {
            $browser = 'Samsung Internet';
        } elseif (stripos($ua, 'Edg') !== false) {
            $browser = 'Edge';
        } elseif (stripos($ua, 'Chrome') !== false && stripos($ua, 'Chromium') === false) {
            $browser = 'Chrome';
        } elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) {
            $browser = 'Safari';
        } elseif (stripos($ua, 'Firefox') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR') !== false) {
            $browser = 'Opera';
        }

        return "{$device} ({$os}) - {$browser}";
    }
}

if (!function_exists('logStudentActivity')) {
    function logStudentActivity(PDO $pdo, int $studentId, ?int $userId, string $activityType, string $description): bool {
        try {
            $ip = getClientIp();
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $deviceName = detectClientDevice($ua);

            $stmt = $pdo->prepare("
                INSERT INTO student_activity_logs (student_id, user_id, activity_type, description, ip_address, device_name, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            return $stmt->execute([
                $studentId,
                $userId,
                $activityType,
                $description,
                $ip,
                $deviceName,
                $ua
            ]);
        } catch (Exception $e) {
            error_log("Failed to log student activity: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getSystemSetting')) {
    function getSystemSetting(PDO $pdo, string $key, ?string $default = null): ?string {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return ($val !== false) ? (string)$val : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}

if (!function_exists('setSystemSetting')) {
    function setSystemSetting(PDO $pdo, string $key, string $value): bool {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            return $stmt->execute([$key, $value]);
        } catch (Exception $e) {
            error_log("Failed to set system setting: " . $e->getMessage());
            return false;
        }
    }
}
