<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// IZINKAN SUPER ADMIN, KEPALA SEKOLAH, DAN ADMIN
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['super_admin', 'kepala_sekolah', 'admin'])) {
    redirect('../dashboard.php');
}

require_once '../../vendor/fpdf/fpdf.php';

$period = getReportPeriod();
$dateStart = $period['start'];
$dateEnd = $period['end'];
$name = trim($_GET['name'] ?? '');
$gradeId = (int)($_GET['grade_id'] ?? 0);
$classId = (int)($_GET['class_group_id'] ?? 0);
$status = $_GET['status'] ?? '';

$access = getStudentAccessCondition('g', 'cg');
$where = ["s.deleted_at IS NULL", "sa.attendance_date BETWEEN ? AND ?", $access['condition']];
$params = [$dateStart, $dateEnd];
$params = array_merge($params, $access['params']);

if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
if ($gradeId > 0) { $where[] = "g.id = ?"; $params[] = $gradeId; }
if ($classId > 0) { $where[] = "cg.id = ?"; $params[] = $classId; }
if ($status !== '') { $where[] = "sa.status = ?"; $params[] = $status; }

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT sa.*, s.name, s.nis, g.grade, cg.name AS class_name, a.name AS activity_name
    FROM student_attendances sa
    INNER JOIN students s ON s.id = sa.student_id
    INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN activities a ON a.id = sa.activity_id
    WHERE {$whereSql}
    ORDER BY sa.attendance_date DESC, g.sort_order, cg.name, s.name
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Hitung total ringkasan
$totTepat = 0; $totTelat = 0; $totIzin = 0; $totSakit = 0; $totAlpha = 0;
foreach($rows as $r) {
    if($r['status'] === 'tepat_waktu') $totTepat++;
    elseif($r['status'] === 'terlambat') $totTelat++;
    elseif($r['status'] === 'izin') $totIzin++;
    elseif($r['status'] === 'sakit') $totSakit++;
    else $totAlpha++;
}

class PDFReport extends FPDF {
    function Header() {
        // Logo di samping kiri atas (Kunci ukuran agar tidak terlalu besar, misal lebar 16mm)
        // Silakan sesuaikan path file logo Anda di bawah ini:
        $logoPath = '../logofull.jpg'; 
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 15, 8, 16); 
        }

        // Header Teks Kop
        $this->SetFont('Arial', 'B', 15);
        $this->SetTextColor(15, 23, 42); // Warna gelap elegan
        $this->Cell(0, 7, 'LAPORAN ABSENSI SISWA', 0, 1, 'C');
        
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

// Info Periode & Kotak Ringkasan Singkat yang Elegan
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(51, 65, 85);
$pdf->Cell(0, 6, 'Periode Laporan: ' . $dateStart . ' s/d ' . $dateEnd, 0, 1, 'L');

$pdf->SetFont('Arial', '', 9);
$pdf->SetFillColor(241, 245, 249);
$pdf->Cell(0, 7, "  Ringkasan Total -> Tepat Waktu: {$totTepat}  |  Terlambat: {$totTelat}  |  Izin: {$totIzin}  |  Sakit: {$totSakit}  |  Tidak Absen: {$totAlpha}  ", 1, 1, 'L', true);
$pdf->Ln(4);

// Header Tabel
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(2, 132, 199); // Biru profesional
$pdf->SetTextColor(255, 255, 255);

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

foreach ($headers as $h) {
    $pdf->Cell($h[1], 7, $h[0], 1, 0, 'C', true);
}
$pdf->Ln();

// Isi Tabel dengan batasan maksimal 20 baris di halaman pertama, selebihnya lanjut otomatis
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(15, 23, 42);

$fill = false;
$rowCounter = 0;

foreach ($rows as $index => $row) {
    // Batasi 20 baris khusus untuk halaman pertama, halaman berikutnya menyesuaikan standar fpdf
    if ($pdf->PageNo() == 1 && $rowCounter >= 20) {
        $pdf->AddPage();
        $rowCounter = 0; // reset counter halaman baru
        
        // Cetak ulang header tabel di halaman baru agar tetap rapi
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(2, 132, 199);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($headers as $h) {
            $pdf->Cell($h[1], 7, $h[0], 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(15, 23, 42);
    }

    $statusMap = [
        'tepat_waktu' => 'Tepat Waktu',
        'terlambat' => 'Terlambat',
        'izin' => 'Izin',
        'sakit' => 'Sakit',
        'tidak_absen' => 'Tidak Absen'
    ];
    $stText = $statusMap[$row['status']] ?? ucwords($row['status']);

    // Baris data dengan warna dasar putih bersih
    $pdf->SetFillColor(255, 255, 255);
    
    $pdf->Cell(10, 6, $index + 1, 1, 0, 'C', true);
    $pdf->Cell(22, 6, $row['attendance_date'], 1, 0, 'C', true);
    $pdf->Cell(45, 6, mb_substr($row['name'], 0, 26), 1, 0, 'L', true);
    $pdf->Cell(22, 6, $row['nis'], 1, 0, 'C', true);
    $pdf->Cell(25, 6, $row['grade'] . '-' . $row['class_name'], 1, 0, 'C', true);
    $pdf->Cell(45, 6, mb_substr($row['activity_name'], 0, 26), 1, 0, 'L', true);
    $pdf->Cell(18, 6, $row['time_in'] ?? '-', 1, 0, 'C', true);
    $pdf->Cell(18, 6, $row['time_out'] ?? '-', 1, 0, 'C', true);
    $pdf->Cell(28, 6, $stText, 1, 0, 'C', true);
    $pdf->Cell(46, 6, mb_substr($row['description'] ?? '-', 0, 28), 1, 0, 'L', true);
    $pdf->Ln();
    
    $rowCounter++;
}

$pdf->Output('D', 'laporan_absensi_siswa_' . $dateStart . '_to_' . $dateEnd . '.pdf');