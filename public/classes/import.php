<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Import Subkelas Excel / CSV';

// Download Template
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_subkelas.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nama Grade', 'Nama Subkelas', 'Nama Unit (Opsional)']);
    fputcsv($out, ['Kelas 1', '1A', 'SD']);
    fputcsv($out, ['Kelas 1', '1B', 'SD']);
    fputcsv($out, ['Kelas 2', '2A', 'SD']);
    fputcsv($out, ['Kelas 7', '7A', 'SMP']);
    fputcsv($out, ['Kelas 7', '7B', 'SMP']);
    fputcsv($out, ['Kelas 10', '10 MIPA 1', 'SMA']);
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

// Ambil semua Grade yang terdaftar beserta Unit
$gradesList = $pdo->query("
    SELECT g.id, g.grade, g.unit_id, un.unit AS unit_name
    FROM grades g
    LEFT JOIN units un ON un.id = g.unit_id
    ORDER BY g.sort_order ASC, g.grade ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_classes'])) {
    requireCsrf();

    if ($_FILES['file_classes']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Gagal mengunggah file. Silakan periksa ukuran file.');
        redirect('import.php');
    }

    if (empty($gradesList)) {
        flash('error', 'Belum ada Grade di sistem. Silakan input atau import data Grade terlebih dahulu.');
        redirect('import.php');
    }

    try {
        $origName = $_FILES['file_classes']['name'];
        $tmpName = $_FILES['file_classes']['tmp_name'];
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

        $stmtCheck = $pdo->prepare("SELECT id FROM class_groups WHERE grade_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
        $stmtInsert = $pdo->prepare("INSERT INTO class_groups (grade_id, name, created_at) VALUES (?, ?, NOW())");

        foreach ($rows as $lineNum => $row) {
            $rowNum = $lineNum + 2;
            $namaGrade = trim($row[0] ?? '');
            $namaSubkelas = trim($row[1] ?? '');
            $namaUnit = trim($row[2] ?? '');

            if (empty($namaGrade) && empty($namaSubkelas)) {
                continue;
            }

            if (empty($namaGrade) || empty($namaSubkelas)) {
                $failed++;
                if (count($errorDetails) < 5) {
                    $errorDetails[] = "Baris $rowNum: Nama Grade atau Nama Subkelas kosong.";
                }
                continue;
            }

            // Pencarian Grade ID
            $matchedGradeId = null;
            $gradeKey = strtolower($namaGrade);
            $unitKey = strtolower($namaUnit);

            // Jika unit diisi, cocokkan grade + unit
            if (!empty($unitKey)) {
                foreach ($gradesList as $g) {
                    if (strtolower(trim($g['grade'])) === $gradeKey && strtolower(trim($g['unit_name'] ?? '')) === $unitKey) {
                        $matchedGradeId = (int)$g['id'];
                        break;
                    }
                }
            }

            // Jika belum ketemu atau unit tidak diisi, cocokkan nama grade saja
            if (!$matchedGradeId) {
                foreach ($gradesList as $g) {
                    if (strtolower(trim($g['grade'])) === $gradeKey) {
                        $matchedGradeId = (int)$g['id'];
                        break;
                    }
                }
            }

            if (!$matchedGradeId) {
                $failed++;
                if (count($errorDetails) < 5) {
                    $unitInfo = !empty($namaUnit) ? " pada Unit '$namaUnit'" : "";
                    $errorDetails[] = "Baris $rowNum: Grade '$namaGrade'$unitInfo tidak ditemukan.";
                }
                continue;
            }

            // Cek duplikasi subkelas di grade tersebut
            $stmtCheck->execute([$matchedGradeId, $namaSubkelas]);
            $exists = $stmtCheck->fetchColumn();

            if ($exists) {
                $skipped++;
            } else {
                $stmtInsert->execute([$matchedGradeId, $namaSubkelas]);
                $success++;
            }
        }

        $pdo->commit();
        logAudit($pdo, 'import_classes', 'class_groups', null, [
            'success' => $success,
            'skipped' => $skipped,
            'failed'  => $failed
        ]);

        $msg = "Import Subkelas selesai! Berhasil: $success, Duplikat/Dilewati: $skipped, Gagal: $failed.";
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
            Import Data Subkelas (Excel / CSV)
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

    <?php if (empty($gradesList)): ?>
        <div class="alert alert-warning" style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; margin-bottom: 20px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <strong>Perhatian:</strong> Belum ada data Grade di sistem. Pastikan Anda telah menginput atau
            <a href="../grades/import.php" style="text-decoration: underline; font-weight: bold; color: #b45309;">mengimport data Grade terlebih dahulu</a> sebelum mengimport Subkelas.
        </div>
    <?php endif; ?>

    <div style="margin-bottom: 24px; background: #f8fafc; border: 1px solid #cbd5e1; padding: 18px; border-radius: 12px;">
        <strong style="color: #1e293b; font-size: 14px;">Petunjuk Format Kolom File:</strong>
        <p style="margin: 6px 0 12px 0; font-size: 13px; color: #475569;">
            Pastikan baris pertama adalah judul kolom. Data dimulai dari baris ke-2 dengan urutan berikut:
        </p>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 14px; background: white;" border="1" cellpadding="8">
            <thead>
                <tr style="background-color: #f1f5f9; text-align: left;">
                    <th style="width: 40%;">Kolom A (Nama Grade)</th>
                    <th style="width: 35%;">Kolom B (Nama Subkelas)</th>
                    <th style="width: 25%;">Kolom C (Nama Unit - Opsional)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>Kelas 1</code></td>
                    <td><code>1A</code></td>
                    <td><code>SD</code></td>
                </tr>
                <tr>
                    <td><code>Kelas 7</code></td>
                    <td><code>7A</code></td>
                    <td><code>SMP</code></td>
                </tr>
                <tr>
                    <td><code>Kelas 10</code></td>
                    <td><code>10 MIPA 1</code></td>
                    <td><code>SMA</code></td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($gradesList)): ?>
        <div style="margin-top: 12px; font-size: 12.5px; color: #334155;">
            <strong>Nama Grade yang Sudah Terdaftar di Sistem:</strong>
            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; max-height: 120px; overflow-y: auto; padding: 4px;">
                <?php foreach ($gradesList as $g): ?>
                    <span style="background: #e2e8f0; color: #1e293b; padding: 3px 8px; border-radius: 6px; font-weight: 500; font-size: 11.5px;">
                        <?= e($g['grade']) ?> <small style="color: #64748b;">(<?= e($g['unit_name'] ?? '-') ?>)</small>
                    </span>
                <?php endforeach; ?>
            </div>
            <small style="color: #64748b; display: block; margin-top: 8px;">
                * Kolom C (Unit) bersifat opsional. Namun jika ada nama grade yang sama di jenjang berbeda, isi Kolom C agar sistem dapat mencocokkan secara tepat.
            </small>
        </div>
        <?php endif; ?>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="form-group" style="margin-bottom: 20px;">
            <label style="font-weight: 600; font-size: 14px; margin-bottom: 8px; display: block;">
                Pilih File Excel (.xlsx) atau CSV (.csv) *
            </label>
            <input type="file" name="file_classes" accept=".xlsx, .csv" required style="padding: 12px; border: 2px dashed #cbd5e1; width: 100%; border-radius: 10px; background: #fdfdfd;">
            <small style="color: #64748b; margin-top: 6px; display: block;">
                Mendukung file Microsoft Excel (.xlsx) atau Comma Separated Values (.csv).
            </small>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 22px;">
                <i class="fa-solid fa-cloud-arrow-up"></i> Mulai Import Subkelas
            </button>
            <a href="index.php" class="btn" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">
                Batal
            </a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>
