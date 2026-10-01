<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('reports_overtimes');

require_once '../../vendor/fpdf/fpdf.php';

$currentRole = currentRole();
$role = $currentRole;
$userId = currentUserId();
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$filterYear = isset($_GET['filter_year']) && $_GET['filter_year'] !== '' ? (int)$_GET['filter_year'] : (int)date('Y');
$filterMonth = (isset($_GET['filter_month']) && $_GET['filter_month'] !== '') ? (int)$_GET['filter_month'] : null;
$nameFilter = trim($_GET['name'] ?? '');
$viewTab = in_array($_GET['tab'] ?? '', ['detail', 'recap']) ? $_GET['tab'] : 'recap';

$where = [];
$params = [];

if (!empty($startDate) && !empty($endDate)) {
    $where[] = "so.overtime_date BETWEEN ? AND ?";
    $params[] = $startDate;
    $params[] = $endDate;
} elseif (!empty($startDate)) {
    $where[] = "so.overtime_date >= ?";
    $params[] = $startDate;
} elseif (!empty($endDate)) {
    $where[] = "so.overtime_date <= ?";
    $params[] = $endDate;
} elseif (!empty($filterMonth)) {
    $where[] = "YEAR(so.overtime_date) = ?";
    $where[] = "MONTH(so.overtime_date) = ?";
    $params[] = $filterYear;
    $params[] = $filterMonth;
} else {
    $where[] = "YEAR(so.overtime_date) = ?";
    $params[] = $filterYear;
}

if ($role === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    $where[] = "s.unit_id = ?";
    $params[] = $ksUnitId;
}

if ($nameFilter !== '') { 
    $where[] = "s.name LIKE ?"; 
    $params[] = "%{$nameFilter}%"; 
}

$whereSql = implode(' AND ', $where);
$sql = "
    SELECT so.overtime_date, so.time_out, s.id as staff_id, s.name, s.nik, u.unit as unit_name, a.staff_out as normal_out
    FROM staff_overtimes so
    JOIN staff s ON s.id = so.staff_id
    LEFT JOIN units u ON u.id = s.unit_id
    LEFT JOIN activities a ON a.id = so.activity_id
    WHERE {$whereSql} 
    ORDER BY so.overtime_date ASC, s.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$overtimes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Perhitungan Total Jam Lembur & Rekap Staff
$grandTotalSeconds = 0;
$staffRecap = [];

foreach ($overtimes as $row) {
    $normalOut = !empty($row['normal_out']) ? strtotime($row['normal_out']) : 0;
    $actualOut = !empty($row['time_out']) ? strtotime($row['time_out']) : 0;
    $durasi = ($normalOut > 0 && $actualOut > $normalOut) ? ($actualOut - $normalOut) : 0;
    
    $grandTotalSeconds += $durasi;

    $sKey = $row['staff_id'] ?: $row['nik'];
    if (!isset($staffRecap[$sKey])) {
        $staffRecap[$sKey] = [
            'staff_id'      => $sKey,
            'name'          => $row['name'],
            'nik'           => $row['nik'],
            'unit_name'     => $row['unit_name'] ?? '-',
            'total_seconds' => 0,
            'frekuensi'     => 0
        ];
    }
    $staffRecap[$sKey]['total_seconds'] += $durasi;
    $staffRecap[$sKey]['frekuensi']++;
}

uasort($staffRecap, fn($a, $b) => $b['total_seconds'] <=> $a['total_seconds']);

$totalJamKeseluruhan = floor($grandTotalSeconds / 3600);
$totalMenitKeseluruhan = floor(($grandTotalSeconds % 3600) / 60);
$totalStaffLembur = count($staffRecap);

$namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$namaBulanStr = $filterMonth ? ($namaBulan[$filterMonth - 1] ?? '') : '';

if (!empty($startDate) && !empty($endDate)) {
    $periodLabel = date('d/m/Y', strtotime($startDate)) . ' s/d ' . date('d/m/Y', strtotime($endDate));
} elseif (!empty($startDate)) {
    $periodLabel = 'Mulai ' . date('d/m/Y', strtotime($startDate));
} elseif (!empty($endDate)) {
    $periodLabel = 'Sampai ' . date('d/m/Y', strtotime($endDate));
} elseif ($filterMonth) {
    $periodLabel = 'Bulan ' . $namaBulanStr . ' Tahun ' . $filterYear;
} else {
    $periodLabel = 'Tahun ' . $filterYear;
}

class PDFReport extends FPDF {
    public $reportMode = 'recap';

    function Header() {
        $logoPath = __DIR__ . '/../logofull.jpg'; 
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 15, 8, 16); 
        }

        $title = $this->reportMode === 'recap' 
            ? 'REKAPITULASI TOTAL JAM LEMBUR STAFF' 
            : 'LAPORAN DETAIL LEMBUR STAFF';

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
        $this->Cell(0, 8, 'Halaman ' . $this->PageNo() . ' dari {nb} | Dicetak: ' . date('d/m/Y H:i'), 0, 0, 'C');
    }
}

$pdf = new PDFReport('L', 'mm', 'A4');
$pdf->reportMode = $viewTab;
$pdf->AliasNbPages();
$pdf->AddPage();

// Info Periode & Kotak Total Jam Lembur
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetTextColor(51, 65, 85);
$pdf->Cell(0, 5, 'Periode Laporan: ' . $periodLabel . ($nameFilter !== '' ? "   |   Filter Nama: {$nameFilter}" : ''), 0, 1, 'L');

$pdf->SetFont('Arial', '', 8.5);
$pdf->SetFillColor(241, 245, 249);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(0, 6.5, "  TOTAL JAM LEMBUR: {$totalJamKeseluruhan} Jam {$totalMenitKeseluruhan} Menit   |   Total Pegawai: {$totalStaffLembur} Orang   |   Total Sesi: " . count($overtimes) . " Kali  ", 1, 1, 'L', true);
$pdf->Ln(3);

if ($viewTab === 'recap') {
    // =========================================================================
    // REKAP PER PEGAWAI (HRD VIEW)
    // =========================================================================
    $headers = [
        ['No', 12],
        ['NIK', 35],
        ['Nama Pegawai / Staff', 85],
        ['Unit', 40],
        ['Frekuensi Lembur', 35],
        ['Total Jam Lembur', 60]
    ];

    $printHeader = function() use ($pdf, $headers) {
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetFillColor(2, 132, 199);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($headers as $h) {
            $pdf->Cell($h[1], 7, $h[0], 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor(15, 23, 42);
    };

    $printHeader();
    $rowCounter = 0;
    $idx = 1;

    foreach ($staffRecap as $st) {
        if ($pdf->PageNo() == 1 && $rowCounter >= 21) {
            $pdf->AddPage();
            $rowCounter = 0;
            $printHeader();
        } elseif ($pdf->PageNo() > 1 && $rowCounter >= 25) {
            $pdf->AddPage();
            $rowCounter = 0;
            $printHeader();
        }

        $stJam = floor($st['total_seconds'] / 3600);
        $stMnt = floor(($st['total_seconds'] % 3600) / 60);

        $bgFill = ($idx % 2 === 0);
        $pdf->SetFillColor(248, 250, 252);

        $pdf->Cell(12, 6.5, $idx++, 1, 0, 'C', $bgFill);
        $pdf->Cell(35, 6.5, (string)$st['nik'], 1, 0, 'C', $bgFill);
        $pdf->Cell(85, 6.5, mb_substr((string)$st['name'], 0, 42), 1, 0, 'L', $bgFill);
        $pdf->Cell(40, 6.5, (string)$st['unit_name'], 1, 0, 'C', $bgFill);
        $pdf->Cell(35, 6.5, $st['frekuensi'] . ' Kali', 1, 0, 'C', $bgFill);
        
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->Cell(60, 6.5, "{$stJam} Jam {$stMnt} Menit", 1, 0, 'C', $bgFill);
        $pdf->SetFont('Arial', '', 8.5);

        $pdf->Ln();
        $rowCounter++;
    }

    // Baris Total Keseluruhan
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->Cell(172, 7, 'TOTAL KESELURUHAN LEMBUR:', 1, 0, 'R', true);
    $pdf->Cell(35, 7, count($overtimes) . ' Kali', 1, 0, 'C', true);
    $pdf->Cell(60, 7, "{$totalJamKeseluruhan} Jam {$totalMenitKeseluruhan} Menit", 1, 1, 'C', true);

} else {
    // =========================================================================
    // DETAIL LOG HARIAN
    // =========================================================================
    $headers = [
        ['No', 10],
        ['Tanggal', 30],
        ['Nama Staff', 70],
        ['NIK', 35],
        ['Batas Normal', 35],
        ['Waktu Keluar', 35],
        ['Durasi Lembur', 52]
    ];

    $printDetailHeader = function() use ($pdf, $headers) {
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetFillColor(2, 132, 199);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($headers as $h) {
            $pdf->Cell($h[1], 7, $h[0], 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor(15, 23, 42);
    };

    $printDetailHeader();
    $rowCounter = 0;

    foreach ($overtimes as $index => $row) {
        if ($pdf->PageNo() == 1 && $rowCounter >= 21) {
            $pdf->AddPage();
            $rowCounter = 0;
            $printDetailHeader();
        } elseif ($pdf->PageNo() > 1 && $rowCounter >= 25) {
            $pdf->AddPage();
            $rowCounter = 0;
            $printDetailHeader();
        }

        $normalOut = !empty($row['normal_out']) ? strtotime($row['normal_out']) : 0;
        $actualOut = !empty($row['time_out']) ? strtotime($row['time_out']) : 0;
        $durasi = ($normalOut > 0 && $actualOut > $normalOut) ? ($actualOut - $normalOut) : 0;
        $jam = floor($durasi / 3600);
        $menit = floor(($durasi % 3600) / 60);
        $durasiText = "{$jam} Jam {$menit} Menit";

        $bgFill = ($index % 2 === 1);
        $pdf->SetFillColor(248, 250, 252);
        
        $pdf->Cell(10, 6.5, $index + 1, 1, 0, 'C', $bgFill);
        $pdf->Cell(30, 6.5, date('d/m/Y', strtotime($row['overtime_date'])), 1, 0, 'C', $bgFill);
        $pdf->Cell(70, 6.5, mb_substr((string)($row['name'] ?? ''), 0, 35), 1, 0, 'L', $bgFill);
        $pdf->Cell(35, 6.5, (string)($row['nik'] ?? '-'), 1, 0, 'C', $bgFill);
        $pdf->Cell(35, 6.5, (string)($row['normal_out'] ?? '-'), 1, 0, 'C', $bgFill);
        $pdf->Cell(35, 6.5, (string)($row['time_out'] ?? '-'), 1, 0, 'C', $bgFill);
        
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->Cell(52, 6.5, $durasiText, 1, 0, 'C', $bgFill);
        $pdf->SetFont('Arial', '', 8.5);
        
        $pdf->Ln();
        $rowCounter++;
    }

    // Baris Total Keseluruhan
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->Cell(215, 7, 'TOTAL DURASI JAM LEMBUR KESELURUHAN:', 1, 0, 'R', true);
    $pdf->Cell(52, 7, "{$totalJamKeseluruhan} Jam {$totalMenitKeseluruhan} Menit", 1, 1, 'C', true);
}

// Tanda Tangan
$pdf->Ln(6);
if ($pdf->GetY() > 165) {
    $pdf->AddPage();
}
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(51, 65, 85);
$pdf->Cell(190);
$pdf->Cell(70, 5, 'Bogor, ' . date('d') . ' ' . $namaBulan[(int)date('m') - 1] . ' ' . date('Y'), 0, 1, 'C');
$pdf->Cell(190);
$pdf->Cell(70, 5, 'Mengetahui,', 0, 1, 'C');
$pdf->Ln(15);
$pdf->Cell(190);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(70, 5, 'Bagian Kepegawaian & HRD', 0, 1, 'C');

$pdf->Output('D', 'laporan_lembur_staff_' . $filterMonth . '_' . $filterYear . '.pdf');
exit;