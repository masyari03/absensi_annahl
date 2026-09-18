<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin(); 
$pageTitle = 'Import Staff XLSX';

function parseXlsxFile($filePath) {
    if (!class_exists('ZipArchive')) { throw new Exception("Fitur ZipArchive belum aktif."); }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) { throw new Exception("Gagal membaca file XLSX."); }
    
    $sharedStrings = [];
    if (($ssXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ssXml);
        if ($xml) {
            foreach ($xml->si as $val) {
                $text = '';
                if (isset($val->t)) { $text = (string)$val->t; }
                elseif (isset($val->r)) { foreach ($val->r as $r) { $text .= (string)$r->t; } }
                $sharedStrings[] = $text;
            }
        }
    }
    
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) { $zip->close(); throw new Exception("Format tidak sesuai."); }
    
    $sheet = simplexml_load_string($sheetXml);
    $rows = [];
    if ($sheet && isset($sheet->sheetData->row)) {
        foreach ($sheet->sheetData->row as $row) {
            $rowData = array_fill(0, 10, '');
            foreach ($row->c as $c) {
                $r = (string)$c['r'];
                $colStr = preg_replace('/[0-9]/', '', $r);
                $colIdx = 0;
                for($i=0; $i<strlen($colStr); $i++) { $colIdx = $colIdx * 26 + (ord(strtoupper($colStr[$i])) - 64); }
                $idx = $colIdx - 1;
                $val = (string)$c->v;
                if (isset($c['t']) && (string)$c['t'] == 's') { $val = $sharedStrings[(int)$val] ?? ''; }
                if ($idx < 10) { $rowData[$idx] = $val; }
            }
            $rows[] = $rowData;
        }
    }
    $zip->close(); 
    return $rows;
}

$unitsMap = $pdo->query("SELECT id, unit FROM units")->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_excel'])) {
    $uploadedPhotos = [];
    if (!empty($_FILES['photos']['name'][0])) {
        foreach ($_FILES['photos']['name'] as $i => $origName) {
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                $uploadedPhotos[$origName] = $_FILES['photos']['tmp_name'][$i];
            }
        }
    }

    try {
        $rows = parseXlsxFile($_FILES['file_excel']['tmp_name']);
        if ($rows) {
            $success = 0; $failed = 0;
            $pdo->beginTransaction();
            
            $stmtUser = $pdo->prepare("INSERT INTO users (username, password, name, role, created_at) VALUES (?, ?, ?, 'staff', NOW())");
            $stmtStaff = $pdo->prepare("INSERT INTO staff (user_id, unit_id, name, nik, photo, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            
            array_shift($rows); // Skip Header Baris 1
            foreach ($rows as $data) {
                $nama = trim($data[0] ?? ''); 
                $nik = trim($data[1] ?? ''); 
                $username = trim($data[2] ?? '');
                $password = trim($data[3] ?? '');
                $namaUnit = trim($data[4] ?? ''); 
                $namaFileFoto = trim($data[5] ?? '');

                if (!empty($nama) && !empty($nik) && !empty($username) && !empty($password) && !empty($namaUnit)) {
                    $unitId = array_search($namaUnit, $unitsMap);
                    if (!$unitId) { $failed++; continue; } 

                    $photoNameSave = null;
                    if (!empty($namaFileFoto) && isset($uploadedPhotos[$namaFileFoto])) {
                        $uploadDir = '../../uploads/staff/';
                        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                        $ext = pathinfo($namaFileFoto, PATHINFO_EXTENSION);
                        $photoNameSave = uniqid('staff_', true) . '.' . $ext;
                        move_uploaded_file($uploadedPhotos[$namaFileFoto], $uploadDir . $photoNameSave);
                    }
                    
                    // Eksekusi insert user & staff
                    $stmtUser->execute([$username, hashPassword($password), $nama]);
                    $userId = $pdo->lastInsertId();
                    
                    $stmtStaff->execute([$userId, $unitId, $nama, $nik, $photoNameSave]);
                    $success++;
                } else {
                    $failed++;
                }
            }
            $pdo->commit();
            flash('success', "Import Staff selesai! Berhasil: $success, Gagal/Lewat: $failed.");
            redirect('index.php');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash('error', $e->getMessage());
    }
}

require '../../includes/header.php';
?>
<div class="card">
    <h3>Import Data Staff + Foto (Excel XLSX)</h3>
    <div style="margin-bottom: 20px; background: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; border-radius: 5px;">
        <strong>Format Kolom Excel Baris Pertama (Wajib Sesuai Urutan):</strong><br>
        <table style="width:100%; margin-top:10px;" border="1">
            <tr style="background-color: #e2e8f0;">
                <th>A (Nama Lengkap)</th><th>B (NIK / Barcode)</th><th>C (Username)</th><th>D (Password)</th><th>E (Nama Unit)</th><th>F (Nama File Foto)</th>
            </tr>
            <tr>
                <td>Agus Salim</td><td>2021001</td><td>agus123</td><td>rahasia123</td><td>SMP</td><td>agus.jpg</td>
            </tr>
        </table>
        <small style="color:red; display:block; margin-top:5px;">* Pastikan nama Unit (Kolom E) sama persis dengan yang ada di sistem (contoh: SD, SMP, SMA). Kolom F boleh dikosongkan.</small>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>1. Upload File Excel (.xlsx)</label>
            <input type="file" name="file_excel" accept=".xlsx" required style="padding: 10px; border: 1px dashed #94a3b8; width: 100%; border-radius: 5px;">
        </div>
        <div class="form-group">
            <label>2. Upload File Foto Sekaligus (Blok/Pilih Semua File Foto)</label>
            <input type="file" name="photos[]" multiple accept="image/*" style="padding: 10px; border: 1px dashed #94a3b8; width: 100%; border-radius: 5px;">
            <small>Sistem akan otomatis mencocokkan nama file ini dengan yang Anda ketik di Kolom F Excel.</small>
        </div>
        <div style="margin-top: 20px;">
            <button type="submit" class="btn btn-primary">Mulai Import</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>