<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Import Grade Excel / CSV';

// Download Template
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_grade.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nama Unit', 'Nama Grade', 'Urutan']);
    fputcsv($out, ['TK', 'Kelompok A', '1']);
    fputcsv($out, ['TK', 'Kelompok B', '2']);
    fputcsv($out, ['SD', 'Kelas 1', '1']);
    fputcsv($out, ['SD', 'Kelas 2', '2']);
    fputcsv($out, ['SD', 'Kelas 3', '3']);
    fputcsv($out, ['SD', 'Kelas 4', '4']);
    fputcsv($out, ['SD', 'Kelas 5', '5']);
    fputcsv($out, ['SD', 'Kelas 6', '6']);
    fputcsv($out, ['SMP', 'Kelas 7', '7']);
    fputcsv($out, ['SMP', 'Kelas 8', '8']);
    fputcsv($out, ['SMP', 'Kelas 9', '9']);
    fputcsv($out, ['SMA', 'Kelas 10', '10']);
    fputcsv($out, ['SMA', 'Kelas 11', '11']);
    fputcsv($out, ['SMA', 'Kelas 12', '12']);
    fclose($out);
    exit;
}

// Fungsi Parser XLSX Native
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

// Fungsi Parser Umum (Mendukung .xlsx dan .csv)
function parseSpreadsheetRows($filePath, $fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $rows = [];
        if (($handle = fopen($filePath, 'r')) !== false) {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            while (($data = fgetcsv($handle, 2000, ",")) !== false) {
                if (count($data) === 1 && strpos($data[0], ';') !== false) {
                    $data = str_getcsv($data[0], ';');
                }
                $rows[] = $data;
            }
            fclose($handle);
        }
        return $rows;
    }
    return parseXlsxFile($filePath);
}

// Ambil daftar Unit yang tersedia
$unitsRaw = $pdo->query("SELECT id, unit FROM units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$unitsMap = [];
foreach ($unitsRaw as $u) {
    $unitsMap[strtolower(trim($u['unit']))] = (int)$u['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_grade'])) {
    requireCsrf();

    if ($_FILES['file_grade']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Gagal mengunggah file. Silakan periksa ukuran file.');
        redirect('import.php');
    }

    try {
        $origName = $_FILES['file_grade']['name'];
        $tmpName = $_FILES['file_grade']['tmp_name'];
        $rows = parseSpreadsheetRows($tmpName, $origName);

        if (empty($rows) || count($rows) <= 1) {
            flash('error', 'File Excel/CSV kosong atau hanya berisi baris header.');
            redirect('import.php');
        }

        array_shift($rows); // Lewati header baris pertama
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $errorDetails = [];

        $pdo->beginTransaction();

        $stmtCheck = $pdo->prepare("SELECT id FROM grades WHERE unit_id = ? AND LOWER(TRIM(grade)) = LOWER(TRIM(?)) LIMIT 1");
        $stmtInsert = $pdo->prepare("INSERT INTO grades (unit_id, grade, sort_order, created_at) VALUES (?, ?, ?, NOW())");
        $stmtUpdate = $pdo->prepare("UPDATE grades SET sort_order = ? WHERE id = ?");

        foreach ($rows as $lineNum => $row) {
            $rowNum = $lineNum + 2;
            $namaUnit = trim($row[0] ?? '');
            $namaGrade = trim($row[1] ?? '');
            $sortOrder = isset($row[2]) && trim($row[2]) !== '' ? (int)$row[2] : 0;

            if (empty($namaUnit) && empty($namaGrade)) {
                continue;
            }

            if (empty($namaUnit) || empty($namaGrade)) {
                $failed++;
                if (count($errorDetails) < 5) {
                    $errorDetails[] = "Baris $rowNum: Unit atau Nama Grade kosong.";
                }
                continue;
            }

            $unitKey = strtolower($namaUnit);
            if (!isset($unitsMap[$unitKey])) {
                $failed++;
                if (count($errorDetails) < 5) {
                    $errorDetails[] = "Baris $rowNum: Unit '$namaUnit' tidak terdaftar dalam sistem.";
                }
                continue;
            }

            $unitId = $unitsMap[$unitKey];

            $stmtCheck->execute([$unitId, $namaGrade]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUpdate->execute([$sortOrder, $existingId]);
                $skipped++;
            } else {
                $stmtInsert->execute([$unitId, $namaGrade, $sortOrder]);
                $success++;
            }
        }

        $pdo->commit();
        logAudit($pdo, 'import_grades', 'grades', null, [
            'success' => $success,
            'updated' => $skipped,
            'failed'  => $failed
        ]);

        $msg = "Import Grade berhasil! Data baru: $success, Diperbarui: $skipped, Gagal: $failed.";
        if (!empty($errorDetails)) {
            $msg .= ' Catatan: ' . implode(' | ', $errorDetails);
        }
        flash('success', $msg);
        redirect('index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Terjadi kesalahan saat memproses file: ' . $e->getMessage());
        redirect('import.php');
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3 style="display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-file-excel" style="color: #10b981;"></i>
            Import Data Grade (Excel / CSV)
        </h3>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="?download_template=1" class="btn btn-success" style="background-color: #10b981; color: white;">
                <i class="fa-solid fa-download"></i> Download Template (CSV / Excel)
            </a>
            <a href="index.php" class="btn" style="background-color: #64748b; color: white;">
                <i class="fa-solid fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>

    <div style="margin-bottom: 24px; background: #f8fafc; border: 1px solid #cbd5e1; padding: 18px; border-radius: 12px;">
        <strong style="color: #1e293b; font-size: 14px;">Petunjuk Format Kolom File:</strong>
        <p style="margin: 6px 0 12px 0; font-size: 13px; color: #475569;">
            Pastikan baris pertama adalah judul kolom. Data dimulai dari baris ke-2 dengan urutan berikut:
        </p>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 14px; background: white;" border="1" cellpadding="8">
            <thead>
                <tr style="background-color: #f1f5f9; text-align: left;">
                    <th style="width: 30%;">Kolom A (Nama Unit)</th>
                    <th style="width: 45%;">Kolom B (Nama Grade)</th>
                    <th style="width: 25%;">Kolom C (Urutan / Sort Order)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>SD</code></td>
                    <td><code>Kelas 1</code></td>
                    <td><code>1</code></td>
                </tr>
                <tr>
                    <td><code>SMP</code></td>
                    <td><code>Kelas 7</code></td>
                    <td><code>7</code></td>
                </tr>
                <tr>
                    <td><code>SMA</code></td>
                    <td><code>Kelas 10</code></td>
                    <td><code>10</code></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 12px; font-size: 12.5px; color: #334155;">
            <strong>Nama Unit Resmi yang Terdaftar di Sistem:</strong>
            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px;">
                <?php foreach ($unitsRaw as $u): ?>
                    <span style="background: #e2e8f0; color: #1e293b; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 12px;">
                        <?= e($u['unit']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <small style="color: #64748b; display: block; margin-top: 8px;">
                * Penulisan nama Unit tidak membedakan huruf besar/kecil (contoh: <code>sd</code> atau <code>SD</code> sama saja).
            </small>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="form-group" style="margin-bottom: 20px;">
            <label style="font-weight: 600; font-size: 14px; margin-bottom: 8px; display: block;">
                Pilih File Excel (.xlsx) atau CSV (.csv) *
            </label>
            <input type="file" name="file_grade" accept=".xlsx, .csv" required style="padding: 12px; border: 2px dashed #cbd5e1; width: 100%; border-radius: 10px; background: #fdfdfd;">
            <small style="color: #64748b; margin-top: 6px; display: block;">
                Mendukung file Microsoft Excel (.xlsx) atau Comma Separated Values (.csv).
            </small>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 22px;">
                <i class="fa-solid fa-cloud-arrow-up"></i> Mulai Import Grade
            </button>
            <a href="index.php" class="btn" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">
                Batal
            </a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>
