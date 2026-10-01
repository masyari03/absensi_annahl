<?php

if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/absensi-anak/public');
}
if (!defined('ASSET_URL')) {
    define('ASSET_URL', 'http://localhost/absensi-anak/assets');
}
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', 'http://localhost/absensi-anak/uploads');
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', dirname(__DIR__) . '/uploads');
}
if (!defined('UPLOAD_STUDENT')) {
    define('UPLOAD_STUDENT', dirname(__DIR__) . '/uploads/students/');
}

date_default_timezone_set('Asia/Jakarta');