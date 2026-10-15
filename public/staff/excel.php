<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

$name = trim($_GET['name'] ?? '');
$nik = trim($_GET['nik'] ?? '');
$unitId = (int)($_GET['unit_id'] ?? 0);
$format = strtolower(trim($_GET['format'] ?? 'csv'));

$where = [
    "s.deleted_at IS NULL",
    "(u.deleted_at IS NULL OR u.id IS NULL)",
    "(u.role IN ('staff', 'admin') OR u.role IS NULL)"
];
$params = [];

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($nik !== '') { $where[] = "s.nik LIKE ?"; $params[] = "%{$nik}%"; }
if ($unitId > 0) { $where[] = "s.unit_id = ?"; $params[] = $unitId; }

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.nik, s.photo, s.created_at, u.username, u.role, un.unit AS unit_name
    FROM staff s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN units un ON un.id = s.unit_id
    WHERE {$whereSql}
    ORDER BY s.name
");
$stmt->execute($params);
$staff = $stmt->fetchAll();

$timestamp = date('Ymd_His');

if ($format === 'xls') {
    // Format HTML Excel Spreadsheet (.xls)
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"data_staff_guru_{$timestamp}.xls\"");
    header('Pragma: no-cache');
    header('Expires: 0');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8">';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 12px; }';
    echo 'th { background-color: #312e81; color: #ffffff; padding: 8px 12px; border: 1px solid #94a3b8; text-align: left; }';
    echo 'td { padding: 6px 10px; border: 1px solid #cbd5e1; }';
    echo '.text-cell { mso-number-format:"\@"; }';
    echo '.center { text-align: center; }';
    echo '</style>';
    echo '</head><body>';
    echo '<h2>DATA STAFF & GURU - AIS ABSENSI</h2>';
    echo '<p>Tanggal Ekspor: ' . date('d-m-Y H:i:s') . '</p>';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>No</th><th>Nama Lengkap</th><th>NIK / Barcode</th><th>Unit</th><th>Username</th><th>Hak Akses (Role)</th><th>Status Foto</th><th>Tanggal Terdaftar</th>';
    echo '</tr></thead><tbody>';

    foreach ($staff as $index => $person) {
        $hasPhoto = !empty($person['photo']) ? 'Ada Foto' : 'Belum Ada Foto';
        $roleName = ($person['role'] === 'admin') ? 'Admin' : (($person['role'] === 'staff') ? 'Staff' : ($person['role'] ?? '-'));
        $created = !empty($person['created_at']) ? date('d-m-Y', strtotime($person['created_at'])) : '-';

        echo '<tr>';
        echo '<td class="center">' . ($index + 1) . '</td>';
        echo '<td>' . htmlspecialchars($person['name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-cell">' . htmlspecialchars($person['nik'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($person['unit_name'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($person['username'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="center">' . htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="center">' . $hasPhoto . '</td>';
        echo '<td class="center">' . $created . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

// Default CSV Format (BOM UTF-8, kompatibel langsung dengan Microsoft Excel & Calc)
header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"data_staff_guru_{$timestamp}.csv\"");
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
// Tulis UTF-8 BOM agar Excel membaca karakter Indonesia dan simbol dengan benar
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Header Kolom
fputcsv($output, ['No', 'Nama Lengkap', 'NIK / Barcode', 'Unit', 'Username', 'Hak Akses (Role)', 'Status Foto', 'Tanggal Terdaftar']);

foreach ($staff as $index => $person) {
    $roleName = ($person['role'] === 'admin') ? 'Admin' : (($person['role'] === 'staff') ? 'Staff' : ($person['role'] ?? '-'));

    fputcsv($output, [
        $index + 1,
        $person['name'] ?? '',
        !empty($person['nik']) ? "'" . $person['nik'] : '-',
        $person['unit_name'] ?? '-',
        $person['username'] ?? '-',
        $roleName,
        !empty($person['photo']) ? 'Ada Foto' : 'Belum Ada Foto',
        !empty($person['created_at']) ? date('d-m-Y', strtotime($person['created_at'])) : '-'
    ]);
}

fclose($output);
exit;
