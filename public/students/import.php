<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

$currentRole = $_SESSION['role'] ?? '';
if (!in_array($currentRole, ['super_admin', 'kepala_sekolah'])) {
    redirect('index.php');
}

$pageTitle = 'Import Siswa XLSX';
$userId = currentUserId();

// Fungsi Native Membaca XLSX
function parseXlsxFile($filePath) {
    if (!class_exists('ZipArchive')) {
        throw new Exception("Fitur ZipArchive belum aktif di server PHP Anda.");
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        throw new Exception("Gagal membaca file. Pastikan format file adalah .xlsx yang valid.");
    }
    
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
    if ($sheetXml === false) {
        $zip->close();
        throw new Exception("Format Excel tidak sesuai. Sheet pertama tidak ditemukan.");
    }
    
    $sheet = simplexml_load_string($sheetXml);
    $rows = [];
    if ($sheet && isset($sheet->sheetData->row)) {
        foreach ($sheet->sheetData->row as $row) {
            $rowData = array_fill(0, 10, '');
            foreach ($row->c as $c) {
                $r = (string)$c['r'];
                $colStr = preg_replace('/[0-9]/', '', $r);
                $colIdx = 0;
                for($i=0; $i<strlen($colStr); $i++) { 
                    $colIdx = $colIdx * 26 + (ord(strtoupper($colStr[$i])) - 64); 
                }
                $idx = $colIdx - 1;
                
                $val = (string)$c->v;
                if (isset($c['t']) && (string)$c['t'] == 's') { 
                    $val = $sharedStrings[(int)$val] ?? ''; 
                }
                if ($idx < 10) { $rowData[$idx] = $val; }
            }
            $rows[] = $rowData;
        }
    }
    $zip->close(); 
    return $rows;
}

$ay = $pdo->query("SELECT id FROM academic_years WHERE status='active' LIMIT 1")->fetchColumn();

// BATASI AKSES KELAS BERDASARKAN ROLE
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    $stmtCg = $pdo->prepare("SELECT cg.id, cg.name FROM class_groups cg INNER JOIN grades g ON g.id = cg.grade_id WHERE g.unit_id = ?");
    $stmtCg->execute([$ksUnitId]);
    $classGroups = $stmtCg->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $classGroups = $pdo->query("SELECT id, name FROM class_groups")->fetchAll(PDO::FETCH_KEY_PAIR);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_excel'])) {
    if (!$ay) {
        flash('error', 'Tahun Ajaran aktif belum diatur.');
        redirect('index.php');
    }

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
            
            $stmtStudent = $pdo->prepare("INSERT INTO students (name, nis, nik, photo, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmtEnroll = $pdo->prepare("INSERT INTO student_enrollments (student_id, academic_year_id, class_group_id, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
            
            array_shift($rows); // Skip Header Baris 1
            foreach ($rows as $data) {
                $nama = trim($data[0] ?? ''); 
                $nis = trim($data[1] ?? ''); 
                $nik = trim($data[2] ?? '');
                $subkelasName = trim($data[3] ?? ''); 
                $namaFileFoto = trim($data[4] ?? '');

                if (!empty($nama) && !empty($subkelasName)) {
                    $classId = array_search($subkelasName, $classGroups);
                    // Jika kelas tidak ditemukan (atau beda unit bagi Kepsek), lewati (gagal).
                    if (!$classId) { $failed++; continue; } 

                    $photoNameSave = null;
                    if (!empty($namaFileFoto) && isset($uploadedPhotos[$namaFileFoto])) {
                        if (!is_dir(UPLOAD_STUDENT)) { mkdir(UPLOAD_STUDENT, 0755, true); }
                        $ext = pathinfo($namaFileFoto, PATHINFO_EXTENSION);
                        $photoNameSave = uniqid('student_', true) . '.' . $ext;
                        move_uploaded_file($uploadedPhotos[$namaFileFoto], UPLOAD_STUDENT . $photoNameSave);
                    }

                    $stmtStudent->execute([$nama, $nis !== '' ? $nis : null, $nik !== '' ? $nik : null, $photoNameSave]);
                    $stmtEnroll->execute([$pdo->lastInsertId(), $ay, $classId]);
                    $success++;
                }
            }
            $pdo->commit();
            flash('success', "Import selesai! Berhasil: $success, Gagal/Dilewati: $failed.");
            redirect('index.php');
        } else {
            flash('error', "Gagal membaca format XLSX atau file kosong.");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash('error', $e->getMessage());
    }
}

require '../../includes/header.php';
?>
<div class="card">
    <h3>Import Data Siswa + Foto (Excel XLSX)</h3>
    <div style="margin-bottom: 20px; background: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; border-radius: 5px;">
        <strong>Format Kolom Excel Baris Pertama (Wajib Sesuai Urutan):</strong><br>
        <table style="width:100%; margin-top:10px;" border="1">
            <tr style="background-color: #e2e8f0;">
                <th>A (Nama)</th><th>B (NIS)</th><th>C (NIK)</th><th>D (Nama Subkelas)</th><th>E (Nama File Foto)</th>
            </tr>
            <tr>
                <td>Budi Santoso</td><td>12345</td><td>3201...</td><td>7A</td><td>budi.jpg</td>
            </tr>
        </table>
        <small style="color:red; display:block; margin-top:5px;">* Pastikan nama subkelas (Kolom D) sama persis dengan yang ada di sistem (contoh: 7A, 8B). Kolom E boleh dikosongkan jika tidak ada foto.</small>
        <?php if($currentRole === 'kepala_sekolah'): ?>
            <small style="color:#0284c7; display:block; margin-top:5px;">* Catatan: Anda hanya dapat melakukan import ke subkelas yang berada di dalam Unit Anda.</small>
        <?php endif; ?>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>1. Upload File Excel (.xlsx)</label>
            <input type="file" name="file_excel" accept=".xlsx" required style="padding: 10px; border: 1px dashed #94a3b8; width: 100%; border-radius: 5px;">
        </div>
        <div class="form-group">
            <label>2. Upload File Foto Sekaligus (Blok/Pilih Semua File Foto)</label>
            <input type="file" name="photos[]" multiple accept="image/*" style="padding: 10px; border: 1px dashed #94a3b8; width: 100%; border-radius: 5px;">
            <small>Pilih banyak file sekaligus. Sistem akan otomatis mencocokkan nama file ini dengan yang Anda ketik di Kolom E Excel.</small>
        </div>
        <div style="margin-top: 20px;">
            <button type="submit" class="btn btn-primary">Mulai Import</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>