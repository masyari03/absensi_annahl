<?php

// Loader .env server-side aman (tidak pernah mengekspos variabel sensitif ke client)
$rootEnvPath = dirname(__DIR__) . '/.env';
if (is_file($rootEnvPath) && is_readable($rootEnvPath)) {
    $envLines = @file($rootEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($envLines)) {
        foreach ($envLines as $envLine) {
            $trimmed = trim($envLine);
            if ($trimmed === '' || $trimmed[0] === '#' || strpos($trimmed, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $trimmed, 2);
            $k = trim($k);
            $v = trim($v);
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '' && getenv($k) === false) {
                @putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }
    }
}

// Deteksi Skema & Host Otomatis
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false) ||
    (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
);
$scheme = $isHttps ? 'https://' : 'http://';
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Deteksi subdirektori aplikasi (misal: /absensi-anak di Laragon, atau root di subdomain live)
$subPath = '';
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']);
    $appRoot = str_replace('\\', '/', dirname(__DIR__));
    if ($docRoot && strpos($appRoot, $docRoot) === 0) {
        $subPath = substr($appRoot, strlen($docRoot));
    }
}
if ($subPath === '' && isset($_SERVER['SCRIPT_NAME'])) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if (strpos($scriptDir, '/public') !== false) {
        $subPath = substr($scriptDir, 0, strpos($scriptDir, '/public'));
    }
}
$subPath = rtrim(str_replace('\\', '/', $subPath), '/');

if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('APP_BASE_URL') ?: ($subPath ? ($scheme . $httpHost . $subPath . '/public') : '/public'));
}
if (!defined('ASSET_URL')) {
    define('ASSET_URL', getenv('APP_ASSET_URL') ?: ($subPath ? ($scheme . $httpHost . $subPath . '/assets') : '/assets'));
}
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', getenv('APP_UPLOAD_URL') ?: ($subPath ? ($scheme . $httpHost . $subPath . '/uploads') : '/uploads'));
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', dirname(__DIR__) . '/uploads');
}
if (!defined('UPLOAD_STUDENT')) {
    define('UPLOAD_STUDENT', dirname(__DIR__) . '/uploads/students/');
}
if (!defined('UPLOAD_STAFF')) {
    define('UPLOAD_STAFF', dirname(__DIR__) . '/uploads/staff/');
}
if (!defined('UPLOAD_ADMIN')) {
    define('UPLOAD_ADMIN', dirname(__DIR__) . '/uploads/admins/');
}

$uploadStudentPath = UPLOAD_STUDENT;

// Pastikan folder upload selalu ada dan memiliki izin tulis
foreach ([UPLOAD_PATH, UPLOAD_STUDENT, UPLOAD_PATH . '/staff/', UPLOAD_PATH . '/admins/', UPLOAD_PATH . '/permits/', UPLOAD_PATH . '/achievements/'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);
}

date_default_timezone_set('Asia/Jakarta');

// Forensic & Device Detector Helper
require_once __DIR__ . '/../includes/device_detector.php';

// Konfigurasi Geofencing Lokasi Sekolah (Default: An-Nahl Islamic School)
if (!defined('SCHOOL_NAME')) {
    define('SCHOOL_NAME', getenv('SCHOOL_NAME') ?: 'An-Nahl Islamic School');
}
if (!defined('SCHOOL_LATITUDE')) {
    define('SCHOOL_LATITUDE', (float)(getenv('SCHOOL_LATITUDE') ?: -6.377038));
}
if (!defined('SCHOOL_LONGITUDE')) {
    define('SCHOOL_LONGITUDE', (float)(getenv('SCHOOL_LONGITUDE') ?: 106.945892));
}
if (!defined('SCHOOL_RADIUS_METERS')) {
    define('SCHOOL_RADIUS_METERS', (int)(getenv('SCHOOL_RADIUS_METERS') ?: 250));
}

// AIS Performance API Integration (Laptop Sekolah / Cloudflare Tunnel / Tailscale)
if (!defined('AIS_PERFORMANCE_API_URL')) {
    define('AIS_PERFORMANCE_API_URL', getenv('AIS_PERFORMANCE_API_URL') ?: 'https://ais.codika.my.id/V1/performance/academic-averages');
}
if (!defined('AIS_PERFORMANCE_API_FALLBACK_URL')) {
    define('AIS_PERFORMANCE_API_FALLBACK_URL', getenv('AIS_PERFORMANCE_API_FALLBACK_URL') ?: 'http://100.126.179.54:8069/V1/performance/academic-averages');
}
if (!defined('AIS_PERFORMANCE_API_KEY')) {
    define('AIS_PERFORMANCE_API_KEY', getenv('AIS_PERFORMANCE_API_KEY') ?: 'c7f99b24e6a8d8102e3b8a139fa4219b6e828453');
}
if (!defined('AIS_TAHFIDZ_API_URL')) {
    define('AIS_TAHFIDZ_API_URL', getenv('AIS_TAHFIDZ_API_URL') ?: 'https://ais.codika.my.id/V1/performance/tahfidz-achievement');
}
if (!defined('AIS_TAHFIDZ_API_FALLBACK_URL')) {
    define('AIS_TAHFIDZ_API_FALLBACK_URL', getenv('AIS_TAHFIDZ_API_FALLBACK_URL') ?: 'http://100.126.179.54:8069/V1/performance/tahfidz-achievement');
}

// Konfigurasi AI Semantic Cache untuk Ekstraksi Catatan Guru Tahfidz (100% Server-Side)
if (!defined('TAHFIDZ_AI_ENABLED')) {
    $aiEnabledEnv = getenv('TAHFIDZ_AI_ENABLED');
    define('TAHFIDZ_AI_ENABLED', $aiEnabledEnv === false ? true : in_array(strtolower((string)$aiEnabledEnv), ['1', 'true', 'yes', 'on'], true));
}
if (!defined('TAHFIDZ_AI_PROVIDER')) {
    $prov = strtolower(trim((string)(getenv('TAHFIDZ_AI_PROVIDER') ?: 'gemini')));
    define('TAHFIDZ_AI_PROVIDER', in_array($prov, ['gemini', 'openai'], true) ? $prov : 'gemini');
}
if (!defined('TAHFIDZ_AI_MODEL')) {
    $defModel = TAHFIDZ_AI_PROVIDER === 'openai' ? 'gpt-4o-mini' : 'gemini-3.1-flash-lite';
    define('TAHFIDZ_AI_MODEL', trim((string)(getenv('TAHFIDZ_AI_MODEL') ?: $defModel)));
}
if (!defined('TAHFIDZ_AI_API_KEY')) {
    define('TAHFIDZ_AI_API_KEY', trim((string)(getenv('TAHFIDZ_AI_API_KEY') ?: '')));
}

// PSR-4 Autoloader untuk namespace App
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
