<?php

// Deteksi Skema & Host Otomatis
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
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
    define('BASE_URL', $scheme . $httpHost . $subPath . '/public');
}
if (!defined('ASSET_URL')) {
    define('ASSET_URL', $scheme . $httpHost . $subPath . '/assets');
}
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', $scheme . $httpHost . $subPath . '/uploads');
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', dirname(__DIR__) . '/uploads');
}
if (!defined('UPLOAD_STUDENT')) {
    define('UPLOAD_STUDENT', dirname(__DIR__) . '/uploads/students/');
}

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