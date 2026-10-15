<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireAdmin();

$name = trim($_GET['name'] ?? '');
$gradeId = (int)($_GET['grade_id'] ?? 0);
$classId = (int)($_GET['class_group_id'] ?? 0);
$unitId = (int)($_GET['unit_id'] ?? 0);
$format = strtolower(trim($_GET['format'] ?? 'csv'));

$access = getStudentAccessCondition('g', 'cg');

$where = [
    "s.deleted_at IS NULL",
    $access['condition']
];
$params = $access['params'];

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($gradeId > 0) { $where[] = "g.id = ?"; $params[] = $gradeId; }
if ($classId > 0) { $where[] = "cg.id = ?"; $params[] = $classId; }
if ($unitId > 0) { $where[] = "un.id = ?"; $params[] = $unitId; }

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT 
        s.id, s.name, s.nis, s.nik, s.parent_phone, s.photo, s.created_at,
        COALESCE(cg.name, 'Belum Masuk Kelas') AS class_name,
        COALESCE(g.grade, '-') AS grade,
        COALESCE(un.unit, '-') AS unit_name
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.academic_year_id = (SELECT id FROM academic_years WHERE status = 'active' LIMIT 1)
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    LEFT JOIN units un ON un.id = g.unit_id
    WHERE {$whereSql}
    ORDER BY g.sort_order IS NULL, g.sort_order, cg.name, s.name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$timestamp = date('Ymd_His');

if ($format === 'xls') {
    // Format HTML Excel Spreadsheet (.xls)
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"data_siswa_{$timestamp}.xls\"");
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
    echo '<h2>DATA SISWA - AIS ABSENSI</h2>';
    echo '<p>Tanggal Ekspor: ' . date('d-m-Y H:i:s') . '</p>';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>No</th><th>Nama Lengkap</th><th>NIS</th><th>NIK</th><th>Unit</th><th>Grade</th><th>Subkelas</th><th>No. HP / WA Ortu</th><th>Status Foto</th><th>Tanggal Terdaftar</th>';
    echo '</tr></thead><tbody>';

    foreach ($students as $index => $row) {
        $hasPhoto = !empty($row['photo']) ? 'Ada Foto' : 'Belum Ada Foto';
        $created = !empty($row['created_at']) ? date('d-m-Y', strtotime($row['created_at'])) : '-';
        echo '<tr>';
        echo '<td class="center">' . ($index + 1) . '</td>';
        echo '<td>' . htmlspecialchars($row['name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-cell">' . htmlspecialchars($row['nis'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-cell">' . htmlspecialchars($row['nik'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['unit_name'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['grade'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['class_name'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-cell">' . htmlspecialchars($row['parent_phone'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="center">' . $hasPhoto . '</td>';
        echo '<td class="center">' . $created . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

// Default CSV Format (BOM UTF-8, kompatibel langsung dengan Microsoft Excel & Calc)
header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"data_siswa_{$timestamp}.csv\"");
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
// Tulis UTF-8 BOM agar Excel membaca karakter Indonesia dan simbol dengan benar
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Header Kolom
fputcsv($output, ['No', 'Nama Lengkap', 'NIS', 'NIK', 'Unit', 'Grade', 'Subkelas', 'No. HP / WA Ortu', 'Status Foto', 'Tanggal Terdaftar']);

foreach ($students as $index => $row) {
    fputcsv($output, [
        $index + 1,
        $row['name'] ?? '',
        !empty($row['nis']) ? "'" . $row['nis'] : '-',
        !empty($row['nik']) ? "'" . $row['nik'] : '-',
        $row['unit_name'] ?? '-',
        $row['grade'] ?? '-',
        $row['class_name'] ?? '-',
        !empty($row['parent_phone']) ? "'" . $row['parent_phone'] : '-',
        !empty($row['photo']) ? 'Ada Foto' : 'Belum Ada Foto',
        !empty($row['created_at']) ? date('d-m-Y', strtotime($row['created_at'])) : '-'
    ]);
}

fclose($output);
exit;
