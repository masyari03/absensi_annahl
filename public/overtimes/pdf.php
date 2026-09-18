<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// IZINKAN SUPER ADMIN & KEPALA SEKOLAH
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['super_admin', 'kepala_sekolah'])) {
    die("Akses ditolak.");
}

require_once '../../vendor/fpdf/fpdf.php';

$userId = currentUserId();
$filterYear = (int)($_GET['filter_year'] ?? date('Y'));
$filterMonth = (int)($_GET['filter_month'] ?? date('n'));
$nameFilter = trim($_GET['name'] ?? '');

$where = ["YEAR(so.overtime_date) = ?", "MONTH(so.overtime_date) = ?"];
$params = [$filterYear, $filterMonth];

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
    SELECT so.overtime_date, so.time_out, s.name, s.nik, a.staff_out as normal_out
    FROM staff_overtimes so
    JOIN staff s ON s.id = so.staff_id
    JOIN activities a ON a.id = so.activity_id
    WHERE {$whereSql} 
    ORDER BY so.overtime_date ASC, s.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$overtimes = $stmt->fetchAll();

$namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$namaBulanStr = $namaBulan[$filterMonth - 1] ?? '';

class PDFReport extends FPDF {
    function Header() {
        // Logo di samping kiri atas (Kunci ukuran proporsional)
        // Silakan sesuaikan path file logo di sini:
        $logoPath = '../logofull.jpg'; 
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 15, 8, 16); 
        }

        // Header Teks Kop
        $this->SetFont('Arial', 'B', 15);
        $this->SetTextColor(15, 23, 42); // Warna gelap elegan
        $this->Cell(0, 7, 'LAPORAN LEMBUR STAFF', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(2, 132, 199); // Warna biru tema
        $this->Cell(0, 5, 'AN NAHL ISLAMIC SCHOOL', 0, 1, 'C');
        
        // Garis pemisah kop surat
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.4);
        $this->Line(15, 26, 282, 26);
        $this->Ln(6);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 10, 'Halaman ' . $this->PageNo() . ' dari {nb}', 0, 0, 'C');
    }
}

$pdf = new PDFReport('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();

// Info Periode
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(51, 65, 85);
$pdf->Cell(0, 6, 'Periode Laporan: Bulan ' . $namaBulanStr . ' Tahun ' . $filterYear, 0, 1, 'L');
$pdf->Ln(2);

// Header Tabel
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(2, 132, 199); // Biru profesional
$pdf->SetTextColor(255, 255, 255);

$headers = [
    ['No', 10],
    ['Tanggal', 30],
    ['Nama Staff', 70],
    ['NIK', 40],
    ['Batas Normal', 35],
    ['Waktu Keluar', 35],
    ['Total Lembur', 45]
];

foreach ($headers as $h) {
    $pdf->Cell($h[1], 8, $h[0], 1, 0, 'C', true);
}
$pdf->Ln();

// Isi Tabel
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(15, 23, 42);

$rowCounter = 0;

foreach ($overtimes as $index => $row) {
    // Batasan maksimal 20 baris di halaman pertama
    if ($pdf->PageNo() == 1 && $rowCounter >= 20) {
        $pdf->AddPage();
        $rowCounter = 0;
        
        // Cetak ulang header tabel di halaman baru
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(2, 132, 199);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($headers as $h) {
            $pdf->Cell($h[1], 8, $h[0], 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(15, 23, 42);
    }

    $durasi = max(0, strtotime($row['time_out']) - strtotime($row['normal_out']));
    $jam = floor($durasi / 3600);
    $menit = floor(($durasi % 3600) / 60);
    $durasiText = "{$jam} Jam {$menit} Menit";

    $pdf->SetFillColor(255, 255, 255); // Dasar putih
    
    $pdf->Cell(10, 7, $index + 1, 1, 0, 'C', true);
    $pdf->Cell(30, 7, date('d/m/Y', strtotime($row['overtime_date'])), 1, 0, 'C', true);
    $pdf->Cell(70, 7, mb_substr($row['name'], 0, 35), 1, 0, 'L', true);
    $pdf->Cell(40, 7, $row['nik'], 1, 0, 'C', true);
    $pdf->Cell(35, 7, $row['normal_out'], 1, 0, 'C', true);
    $pdf->Cell(35, 7, $row['time_out'], 1, 0, 'C', true);
    
    $pdf->SetFont('Arial', 'B', 9); // Bold untuk durasi lembur
    $pdf->Cell(45, 7, $durasiText, 1, 0, 'C', true);
    $pdf->SetFont('Arial', '', 9); // Kembali normal
    
    $pdf->Ln();
    
    $rowCounter++;
}

$pdf->Output('D', 'laporan_lembur_staff_' . $filterMonth . '_' . $filterYear . '.pdf');