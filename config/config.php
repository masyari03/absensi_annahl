<?php

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
    $docRoot = str_replace('\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']);
    $appRoot = str_replace('\', '/', dirname(__DIR__));
    if ($docRoot && strpos($appRoot, $docRoot) === 0) {
        $subPath = substr($appRoot, strlen($docRoot));
    }
}
if ($subPath === '' && isset($_SERVER['SCRIPT_NAME'])) {
    $scriptDir = str_replace('\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if (strpos($scriptDir, '/public') !== false) {
        $subPath = substr($scriptDir, 0, strpos($scriptDir, '/public'));
    }
}
$subPath = rtrim(str_replace('\', '/', $subPath), '/');

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

// PSR-4 Autoloader untuk namespace Appspl_autoload_register(function ($class) {
    $prefix = 'App\';
    $baseDir = dirname(__DIR__) . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
