<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// IZINKAN SUPER ADMIN, KEPALA SEKOLAH, DAN ADMIN
requireLogin();
checkUserAccess('reports_attendance');

require_once '../../vendor/fpdf/fpdf.php';

$currentRole = currentRole();
$role = $currentRole;
$userId = currentUserId();
$type = $_GET['type'] ?? 'student';
$mode = in_array($_GET['mode'] ?? '', ['detail', 'recap']) ? $_GET['mode'] : 'detail';

// Batasi Admin agar tidak bisa akses laporan staff
if ($role === 'admin' && $type === 'staff') {
    die('Akses ditolak: Anda tidak memiliki hak akses untuk mengunduh laporan absensi staff.');
}

$period = getReportPeriod();
$dateStart = $period['start'];
$dateEnd = $period['end'];
$name = trim($_GET['name'] ?? '');
$status = $_GET['status'] ?? '';

$filterSubtitleInfo = [];

if ($type === 'student') {
    $gradeId = (int)($_GET['grade_id'] ?? 0);
    $classId = (int)($_GET['class_group_id'] ?? 0);

    $access = getStudentAccessCondition('g', 'cg');
    $where = ["s.deleted_at IS NULL", "sa.attendance_date BETWEEN ? AND ?", $access['condition']];
    $params = [$dateStart, $dateEnd];
    $params = array_merge($params, $access['params']);

    if ($name !== '') { 
        $where[] = "s.name LIKE ?"; 
        $params[] = "%{$name}%"; 
        $filterSubtitleInfo[] = "Nama: $name";
    }
    if ($gradeId > 0) { 
        $where[] = "g.id = ?"; 
        $params[] = $gradeId; 
        $gName = $pdo->prepare("SELECT grade FROM grades WHERE id = ?");
        $gName->execute([$gradeId]);
        if ($gn = $gName->fetchColumn()) {
            $filterSubtitleInfo[] = "Grade: $gn";
        }
    }
    if ($classId > 0) { 
        $where[] = "cg.id = ?"; 
        $params[] = $classId; 
        $cName = $pdo->prepare("SELECT CONCAT(g.grade, ' - ', cg.name) FROM class_groups cg JOIN grades g ON g.id = cg.grade_id WHERE cg.id = ?");
        $cName->execute([$classId]);
        if ($cn = $cName->fetchColumn()) {
            $filterSubtitleInfo[] = "Subkelas: $cn";
        }
    }
    if ($status !== '') { 
        $where[] = "sa.status = ?"; 
        $params[] = $status; 
        $filterSubtitleInfo[] = "Status: " . ucwords(str_replace('_', ' ', $status));
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT sa.*, s.name, s.nis, g.grade, cg.name AS class_name, a.name AS activity_name
        FROM student_attendances sa
        INNER JOIN students s ON s.id = sa.student_id
        INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
        INNER JOIN class_groups cg ON cg.id = se.class_group_id
        INNER JOIN grades g ON g.id = cg.grade_id
        LEFT JOIN activities a ON a.id = sa.activity_id
        WHERE {$whereSql}
        ORDER BY sa.attendance_date DESC, g.sort_order, cg.name, s.name
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    // LAPORAN STAFF
    $unitId = (int)($_GET['unit_id'] ?? 0);
    $where = [
        "st.deleted_at IS NULL",
        "sta.attendance_date BETWEEN ? AND ?"
    ];
    $params = [$dateStart, $dateEnd];

    if ($role === 'kepala_sekolah') {
        $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
        $stmtKs->execute([$userId]);
        $ksUnitId = $stmtKs->fetchColumn();
        $where[] = "st.unit_id = ?";
        $params[] = $ksUnitId;
        $uName = $pdo->prepare("SELECT unit FROM units WHERE id = ?");
        $uName->execute([$ksUnitId]);
        if ($un = $uName->fetchColumn()) {
            $filterSubtitleInfo[] = "Unit: $un";
        }
    } elseif ($role === 'super_admin' && $unitId > 0) {
        $where[] = "st.unit_id = ?";
        $params[] = $unitId;
        $uName = $pdo->prepare("SELECT unit FROM units WHERE id = ?");
        $uName->execute([$unitId]);
        if ($un = $uName->fetchColumn()) {
            $filterSubtitleInfo[] = "Unit: $un";
        }
    }

    if ($name !== '') { 
        $where[] = "st.name LIKE ?"; 
        $params[] = "%{$name}%"; 
        $filterSubtitleInfo[] = "Nama: $name";
    }
    if ($status !== '') { 
        $where[] = "sta.status = ?"; 
        $params[] = $status; 
        $filterSubtitleInfo[] = "Status: " . ucwords(str_replace('_', ' ', $status));
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT sta.*, st.name, st.nik, u.unit AS unit_name, a.name AS activity_name
        FROM staff_attendances sta
        INNER JOIN staff st ON st.id = sta.staff_id
        LEFT JOIN units u ON u.id = st.unit_id
        LEFT JOIN activities a ON a.id = sta.activity_id
        WHERE {$whereSql}
        ORDER BY sta.attendance_date DESC, sta.time_in DESC, st.name
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// Hitung total ringkasan global
$totTepat = 0; $totTelat = 0; $totIzin = 0; $totSakit = 0; $totAlpha = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'tepat_waktu') $totTepat++;
    elseif ($r['status'] === 'terlambat') $totTelat++;
    elseif ($r['status'] === 'izin') $totIzin++;
    elseif ($r['status'] === 'sakit') $totSakit++;
    else $totAlpha++;
}

// Helper Tanggal Indonesia
function indoDateFormatted($timestamp = null) {
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $ts = $timestamp ?? time();
    $d = date('j', $ts);
    $m = $months[(int)date('n', $ts)] ?? '';
    $y = date('Y', $ts);
    return "$d $m $y";
}

class PDFReport extends FPDF {
    public $reportType = 'student';
    public $reportMode = 'detail';
    public $filterInfo = '';

    function Header() {
        $logoPath = __DIR__ . '/../logofull.jpg';
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 15, 8, 16);
        }

        if ($this->reportMode === 'recap') {
            $title = $this->reportType === 'staff' 
                ? 'REKAPITULASI JUMLAH KEHADIRAN STAFF' 
                : 'REKAPITULASI JUMLAH KEHADIRAN SISWA';
        } else {
            $title = $this->reportType === 'staff' 
                ? 'LAPORAN ABSENSI STAFF' 
                : 'LAPORAN ABSENSI SISWA';
        }

        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 6, $title, 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(2, 132, 199);
        $this->Cell(0, 5, 'AN NAHL ISLAMIC SCHOOL', 0, 1, 'C');
        
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.4);
        $this->Line(15, 24, 282, 24);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-13);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 8, 'Halaman ' . $this->PageNo() . ' dari {nb} | Dicetak pada: ' . date('d/m/Y H:i'), 0, 0, 'C');
    }
}

$pdf = new PDFReport('L', 'mm', 'A4');
$pdf->reportType = $type;
$pdf->reportMode = $mode;
$pdf->AliasNbPages();
$pdf->AddPage();

// Info Periode & Filter Aktif
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetTextColor(51, 65, 85);
$infoText = 'Periode Laporan: ' . $dateStart . ' s/d ' . $dateEnd;
if (!empty($filterSubtitleInfo)) {
    $infoText .= '   |   ' . implode('   |   ', $filterSubtitleInfo);
}
$pdf->Cell(0, 5, $infoText, 0, 1, 'L');

// Kotak Ringkasan Singkat
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetFillColor(241, 245, 249);
$pdf->SetTextColor(15, 23, 42);
$totHadirGlobal = $totTepat + $totTelat;
$pdf->Cell(0, 6.5, "  Ringkasan Total -> Tepat Waktu: {$totTepat}   |   Terlambat: {$totTelat}   |   Izin: {$totIzin}   |   Sakit: {$totSakit}   |   Tidak Absen: {$totAlpha}   |   Total Hadir: {$totHadirGlobal}  ", 1, 1, 'L', true);
$pdf->Ln(3);

if ($mode === 'recap') {
    // =========================================================================
    // CETAK REKAPITULASI JUMLAH KEHADIRAN (HASIL SAJA)
    // =========================================================================
    $recapRows = [];

    // Pre-populate siswa/staff aktif jika ada filter subkelas / unit
    if ($type === 'student' && isset($classId) && $classId > 0) {
        $stmtStudents = $pdo->prepare("
            SELECT s.id, s.name, s.nis, g.grade, cg.name AS class_name
            FROM student_enrollments se
            INNER JOIN students s ON s.id = se.student_id AND s.deleted_at IS NULL
            INNER JOIN class_groups cg ON cg.id = se.class_group_id
            INNER JOIN grades g ON g.id = cg.grade_id
            WHERE se.class_group_id = ? AND se.status = 'active'
            ORDER BY s.name ASC
        ");
        $stmtStudents->execute([$classId]);
        foreach ($stmtStudents->fetchAll(PDO::FETCH_ASSOC) as $st) {
            $recapRows[$st['id']] = [
                'id'           => $st['id'],
                'name'         => $st['name'],
                'identifier'   => $st['nis'] ?: '-',
                'group_name'   => $st['grade'] . ' - ' . $st['class_name'],
                'tepat_waktu'  => 0,
                'terlambat'    => 0,
                'izin'         => 0,
                'sakit'        => 0,
                'tidak_absen'  => 0,
                'total_hadir'  => 0,
                'total_hari'   => 0
            ];
        }
    } elseif ($type === 'staff' && isset($unitId) && $unitId > 0) {
        $stmtStaffList = $pdo->prepare("
            SELECT st.id, st.name, st.nik, u.unit AS unit_name
            FROM staff st
            LEFT JOIN units u ON u.id = st.unit_id
            WHERE st.unit_id = ? AND st.deleted_at IS NULL
            ORDER BY st.name ASC
        ");
        $stmtStaffList->execute([$unitId]);
        foreach ($stmtStaffList->fetchAll(PDO::FETCH_ASSOC) as $st) {
            $recapRows[$st['id']] = [
                'id'           => $st['id'],
                'name'         => $st['name'],
                'identifier'   => $st['nik'] ?: '-',
                'group_name'   => $st['unit_name'] ?: '-',
                'tepat_waktu'  => 0,
                'terlambat'    => 0,
                'izin'         => 0,
                'sakit'        => 0,
                'tidak_absen'  => 0,
                'total_hadir'  => 0,
                'total_hari'   => 0
            ];
        }
    }

    // Akumulasi data kehadiran
    foreach ($rows as $r) {
        $personKey = ($type === 'student') ? $r['student_id'] : $r['staff_id'];
        if (!isset($recapRows[$personKey])) {
            $recapRows[$personKey] = [
                'id'           => $personKey,
                'name'         => $r['name'],
                'identifier'   => ($type === 'student') ? ($r['nis'] ?? '-') : ($r['nik'] ?? '-'),
                'group_name'   => ($type === 'student') ? (($r['grade'] ?? '') . ' - ' . ($r['class_name'] ?? '')) : ($r['unit_name'] ?? '-'),
                'tepat_waktu'  => 0,
                'terlambat'    => 0,
                'izin'         => 0,
                'sakit'        => 0,
                'tidak_absen'  => 0,
                'total_hadir'  => 0,
                'total_hari'   => 0
            ];
        }

        $st = $r['status'] ?? '';
        if (isset($recapRows[$personKey][$st])) {
            $recapRows[$personKey][$st]++;
        }
        if ($st === 'tepat_waktu' || $st === 'terlambat') {
            $recapRows[$personKey]['total_hadir']++;
        }
        $recapRows[$personKey]['total_hari']++;
    }

    usort($recapRows, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    // Header Tabel Rekap (Total Lebar: 267 mm)
    $headersRecap = [
        ['No', 10],
        [$type === 'staff' ? 'NIK' : 'NIS', 25],
        ['Nama Lengkap ' . ($type === 'staff' ? 'Staff' : 'Siswa'), 70],
        [$type === 'staff' ? 'Unit' : 'Subkelas', 34],
        ['Tepat Waktu', 22],
        ['Terlambat', 22],
        ['Izin', 18],
        ['Sakit', 18],
        ['Tidak Absen', 24],
        ['Total Hadir', 24]
    ];

    $printRecapHeader = function() use ($pdf, $headersRecap) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(2, 132, 199);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($headersRecap as $h) {
            $pdf->Cell($h[1], 7, $h[0], 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(15, 23, 42);
    };

    $printRecapHeader();

    $grandTepat = 0; $grandTelat = 0; $grandIzin = 0; $grandSakit = 0; $grandAlpha = 0; $grandHadir = 0;
    $rowCounter = 0;

    foreach ($recapRows as $idx => $item) {
        if ($pdf->PageNo() == 1 && $rowCounter >= 21) {
            $pdf->AddPage();
            $rowCounter = 0;
            $printRecapHeader();
        } elseif ($pdf->PageNo() > 1 && $rowCounter >= 25) {
            $pdf->AddPage();
            $rowCounter = 0;
            $printRecapHeader();
        }

        $grandTepat += $item['tepat_waktu'];
        $grandTelat += $item['terlambat'];
        $grandIzin  += $item['izin'];
        $grandSakit += $item['sakit'];
        $grandAlpha += $item['tidak_absen'];
        $grandHadir += $item['total_hadir'];

        $bgFill = ($idx % 2 === 1);
        $pdf->SetFillColor(248, 250, 252);

        $pdf->Cell(10, 6, $idx + 1, 1, 0, 'C', $bgFill);
        $pdf->Cell(25, 6, (string)$item['identifier'], 1, 0, 'C', $bgFill);
        $pdf->Cell(70, 6, mb_substr((string)$item['name'], 0, 36), 1, 0, 'L', $bgFill);
        $pdf->Cell(34, 6, mb_substr((string)$item['group_name'], 0, 20), 1, 0, 'C', $bgFill);
        $pdf->Cell(22, 6, (string)$item['tepat_waktu'], 1, 0, 'C', $bgFill);
        $pdf->Cell(22, 6, (string)$item['terlambat'], 1, 0, 'C', $bgFill);
        $pdf->Cell(18, 6, (string)$item['izin'], 1, 0, 'C', $bgFill);
        $pdf->Cell(18, 6, (string)$item['sakit'], 1, 0, 'C', $bgFill);
        $pdf->Cell(24, 6, (string)$item['tidak_absen'], 1, 0, 'C', $bgFill);
        $pdf->Cell(24, 6, (string)$item['total_hadir'], 1, 0, 'C', $bgFill);
        $pdf->Ln();

        $rowCounter++;
    }

    // Baris Total Akumulasi
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(139, 7, 'TOTAL KESELURUHAN:', 1, 0, 'R', true);
    $pdf->Cell(22, 7, (string)$grandTepat, 1, 0, 'C', true);
    $pdf->Cell(22, 7, (string)$grandTelat, 1, 0, 'C', true);
    $pdf->Cell(18, 7, (string)$grandIzin, 1, 0, 'C', true);
    $pdf->Cell(18, 7, (string)$grandSakit, 1, 0, 'C', true);
    $pdf->Cell(24, 7, (string)$grandAlpha, 1, 0, 'C', true);
    $pdf->Cell(24, 7, (string)$grandHadir, 1, 1, 'C', true);

    // Tanda Tangan
    $pdf->Ln(6);
    if ($pdf->GetY() > 165) {
        $pdf->AddPage();
    }
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(51, 65, 85);
    $pdf->Cell(190);
    $pdf->Cell(70, 5, 'Bogor, ' . indoDateFormatted(), 0, 1, 'C');
    $pdf->Cell(190);
    $pdf->Cell(70, 5, 'Mengetahui,', 0, 1, 'C');
    $pdf->Ln(15);
    $pdf->Cell(190);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(70, 5, ($type === 'staff' ? 'Kepala Bagian HRD' : 'Kepala Sekolah'), 0, 1, 'C');

    $filePrefix = $type === 'staff' ? 'rekap_kehadiran_staff_' : 'rekap_kehadiran_siswa_';
    $pdf->Output('D', $filePrefix . $dateStart . '_to_' . $dateEnd . '.pdf');
    exit;

} else {
    // =========================================================================
    // CETAK DETAIL HARIAN (JAM MASUK & PULANG)
    // =========================================================================
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(2, 132, 199);
    $pdf->SetTextColor(255, 255, 255);

    if ($type === 'student') {
        $headers = [
            ['No', 10],
            ['Tanggal', 22],
            ['Nama Siswa', 45],
            ['NIS', 22],
            ['Subkelas', 25],
            ['Kegiatan', 45],
            ['Masuk', 18],
            ['Keluar', 18],
            ['Status', 28],
            ['Keterangan', 46]
        ];
    } else {
        $headers = [
            ['No', 10],
            ['Tanggal', 22],
            ['Nama Staff', 45],
            ['NIK', 25],
            ['Unit', 25],
            ['Kegiatan', 42],
            ['Masuk', 18],
            ['Keluar', 18],
            ['Status', 28],
            ['Keterangan', 46]
        ];
    }

    $printDetailHeader = function() use ($pdf, $headers) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(2, 132, 199);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($headers as $h) {
            $pdf->Cell($h[1], 7, $h[0], 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(15, 23, 42);
    };

    $printDetailHeader();

    $rowCounter = 0;
    $statusMap = [
        'tepat_waktu' => 'Tepat Waktu',
        'terlambat' => 'Terlambat',
        'izin' => 'Izin',
        'sakit' => 'Sakit',
        'tidak_absen' => 'Tidak Absen'
    ];

    foreach ($rows as $index => $row) {
        if ($pdf->PageNo() == 1 && $rowCounter >= 20) {
            $pdf->AddPage();
            $rowCounter = 0;
            $printDetailHeader();
        } elseif ($pdf->PageNo() > 1 && $rowCounter >= 25) {
            $pdf->AddPage();
            $rowCounter = 0;
            $printDetailHeader();
        }

        $stText = $statusMap[$row['status']] ?? ucwords((string)($row['status'] ?? ''));
        $keteranganText = $row['keterangan'] ?? $row['description'] ?? '-';
        if (trim((string)$keteranganText) === '') $keteranganText = '-';

        $bgFill = ($index % 2 === 1);
        $pdf->SetFillColor(248, 250, 252);
        
        $pdf->Cell(10, 6, $index + 1, 1, 0, 'C', $bgFill);
        $pdf->Cell(22, 6, (string)$row['attendance_date'], 1, 0, 'C', $bgFill);
        $pdf->Cell(45, 6, mb_substr((string)($row['name'] ?? ''), 0, 25), 1, 0, 'L', $bgFill);
        
        if ($type === 'student') {
            $pdf->Cell(22, 6, (string)($row['nis'] ?? '-'), 1, 0, 'C', $bgFill);
            $pdf->Cell(25, 6, (string)($row['grade'] ?? '') . '-' . (string)($row['class_name'] ?? ''), 1, 0, 'C', $bgFill);
            $pdf->Cell(45, 6, mb_substr((string)($row['activity_name'] ?? '-'), 0, 25), 1, 0, 'L', $bgFill);
        } else {
            $pdf->Cell(25, 6, (string)($row['nik'] ?? '-'), 1, 0, 'C', $bgFill);
            $pdf->Cell(25, 6, mb_substr((string)($row['unit_name'] ?? '-'), 0, 14), 1, 0, 'C', $bgFill);
            $pdf->Cell(42, 6, mb_substr((string)($row['activity_name'] ?? '-'), 0, 23), 1, 0, 'L', $bgFill);
        }

        $pdf->Cell(18, 6, (string)($row['time_in'] ?? '-'), 1, 0, 'C', $bgFill);
        $pdf->Cell(18, 6, (string)($row['time_out'] ?? '-'), 1, 0, 'C', $bgFill);
        $pdf->Cell(28, 6, $stText, 1, 0, 'C', $bgFill);
        $pdf->Cell(46, 6, mb_substr((string)$keteranganText, 0, 27), 1, 0, 'L', $bgFill);
        $pdf->Ln();
        
        $rowCounter++;
    }

    $filePrefix = $type === 'staff' ? 'laporan_absensi_staff_' : 'laporan_absensi_siswa_';
    $pdf->Output('D', $filePrefix . $dateStart . '_to_' . $dateEnd . '.pdf');
    exit;
}