<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

$key = $_POST['key'] ?? '';
$allowedKeys = [
    'student_can_change_username',
    'student_can_change_photo',
    'student_web_permit_enabled'
];

if (!in_array($key, $allowedKeys, true)) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => false, 'message' => 'Key tidak valid']);
        exit;
    }
    flash('error', 'Kunci pengaturan tidak valid.');
    redirect($_SERVER['HTTP_REFERER'] ?? '../kelola/activity.php');
}

// Ambil nilai sekarang lalu balikkan (toggle)
$currentVal = getSystemSetting($pdo, $key, '0');
$newVal = ($currentVal === '1') ? '0' : '1';

setSystemSetting($pdo, $key, $newVal);

$labels = [
    'student_can_change_username' => 'Izin Ganti Username Siswa',
    'student_can_change_photo'    => 'Izin Ganti Foto Profil Siswa',
    'student_web_permit_enabled'  => 'Fitur Pengajuan Izin Siswa Lewat Web'
];

$label = $labels[$key] ?? $key;
$statusText = ($newVal === '1') ? 'DIAKTIFKAN' : 'DINONAKTIFKAN';

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'new_value' => $newVal,
        'message' => "Pengaturan {$label} berhasil {$statusText}."
    ]);
    exit;
}

flash('success', "Pengaturan {$label} berhasil {$statusText}.");
redirect($_SERVER['HTTP_REFERER'] ?? '../students/index.php');
