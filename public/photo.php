<?php
// public/photo.php - Penampil Foto Aman & Auto-Fallback Generator
require_once __DIR__ . '/../config/config.php';

$type = strtolower($_GET['type'] ?? 'student');
$file = trim($_GET['file'] ?? '');
$name = trim($_GET['name'] ?? 'Siswa');

// Sanitasi nama file untuk mencegah path traversal
$fileName = basename($file);

// Tentukan direktori sesuai tipe
if ($type === 'staff') {
    $dir = defined('UPLOAD_PATH') ? UPLOAD_PATH . '/staff/' : (dirname(__DIR__) . '/uploads/staff/');
} elseif ($type === 'admin' || $type === 'admins') {
    $dir = defined('UPLOAD_PATH') ? UPLOAD_PATH . '/admins/' : (dirname(__DIR__) . '/uploads/admins/');
} else {
    $dir = defined('UPLOAD_STUDENT') ? UPLOAD_STUDENT : (dirname(__DIR__) . '/uploads/students/');
}

$filePath = $dir . $fileName;

// Jika file fisik ditemukan dan dapat dibaca, sajikan langsung
if (!empty($fileName) && is_file($filePath) && is_readable($filePath)) {
    $mime = @mime_content_type($filePath);
    if (!$mime) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif'
        ];
        $mime = $mimes[$ext] ?? 'image/jpeg';
    }

    // Bersihkan buffer output jika ada
    if (ob_get_level()) { ob_end_clean(); }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: public, max-age=86400');
    readfile($filePath);
    exit;
}

// Fallback: Sajikan SVG Avatar Modern Berdasarkan Inisial Nama
$initial = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') ?: 'U';

// Palet warna gradient modern berdasarkan inisial
$palettes = [
    ['#4f46e5', '#3730a3'], // Indigo
    ['#0284c7', '#0369a1'], // Sky
    ['#059669', '#047857'], // Emerald
    ['#d97706', '#b45309'], // Amber
    ['#e11d48', '#be123c'], // Rose
    ['#7c3aed', '#6d28d9'], // Violet
    ['#0d9488', '#0f766e'], // Teal
    ['#475569', '#334155'], // Slate
];
$colorIndex = ord($initial) % count($palettes);
$c1 = $palettes[$colorIndex][0];
$c2 = $palettes[$colorIndex][1];

if (ob_get_level()) { ob_end_clean(); }
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">
  <defs>
    <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="<?= $c1 ?>" />
      <stop offset="100%" stop-color="<?= $c2 ?>" />
    </linearGradient>
  </defs>
  <rect width="120" height="120" rx="24" fill="url(#grad)" />
  <text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" fill="#ffffff" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-size="52" font-weight="700"><?= htmlspecialchars($initial) ?></text>
</svg>
