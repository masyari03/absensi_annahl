<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_staff');
$currentRole = currentRole();
$pageTitle = 'Bulk Update Staff / Guru';
$userId = currentUserId();

$isSuperAdmin = ($currentRole === 'super_admin');
$isKepsek = ($currentRole === 'kepala_sekolah');

// Penguncian Unit untuk Kepala Sekolah
$ksUnitId = null;
if ($isKepsek) {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();
}

$unitId = (int)($_GET['unit_id'] ?? ($isKepsek ? $ksUnitId : 0));
$filterEmpty = $_GET['filter_empty'] ?? 'all'; // all, empty_phone, empty_rfid, empty_finger
$search = trim($_GET['search'] ?? '');

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

// =========================================================================
// ACTION: EXPORT CSV (GOOGLE ADMIN STYLE PRE-FILLED DATA)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $whereExp = ["s.deleted_at IS NULL"];
    $paramsExp = [];

    if ($isKepsek && $ksUnitId) {
        $whereExp[] = "s.unit_id = ?";
        $paramsExp[] = $ksUnitId;
    } elseif ($unitId > 0) {
        $whereExp[] = "s.unit_id = ?";
        $paramsExp[] = $unitId;
    }

    if ($search !== '') {
        $whereExp[] = "(s.name LIKE ? OR s.nik LIKE ?)";
        $paramsExp[] = "%{$search}%"; $paramsExp[] = "%{$search}%";
    }

    $whereExpSql = implode(' AND ', $whereExp);
    $stmtExp = $pdo->prepare("
        SELECT s.id, s.name, s.nik, un.unit as unit_name,
               s.phone, s.wa_notify, s.rfid_uid, s.fingerprint_id
        FROM staff s
        LEFT JOIN units un ON un.id = s.unit_id
        WHERE {$whereExpSql}
        ORDER BY un.unit, s.name
    ");
    $stmtExp->execute($paramsExp);
    $exportRows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=bulk_staff_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header CSV
    fputcsv($output, ['ID_Staff', 'Nama_Staff', 'NIK', 'Unit', 'Nomor_WA', 'Notif_WA(1=ON/0=OFF)', 'RFID_UID', 'Fingerprint_ID']);

    foreach ($exportRows as $r) {
        fputcsv($output, [
            $r['id'],
            $r['name'],
            $r['nik'],
            $r['unit_name'] ?? '-',
            $r['phone'] ?? '',
            (int)($r['wa_notify'] ?? 1),
            $r['rfid_uid'] ?? '',
            $r['fingerprint_id'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// =========================================================================
// ACTION: POST BULK UPDATE (WEB TABLE ATAU CSV IMPORT)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_table';

    // 1. IMPORT DARI CSV
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'File CSV gagal diunggah.');
            redirect('bulk_edit.php?' . http_build_query($_GET));
        }

        $ignoreEmpty = isset($_POST['ignore_empty_cells']) && $_POST['ignore_empty_cells'] === '1';
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$file) {
            flash('error', 'Gagal membaca isi file CSV.');
            redirect('bulk_edit.php?' . http_build_query($_GET));
        }

        $bom = fread($file, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($file);
        }

        $header = fgetcsv($file);
        $updatedCount = 0;
        $pdo->beginTransaction();

        try {
            while (($row = fgetcsv($file)) !== false) {
                if (empty($row) || !isset($row[0])) continue;
                $staffId = (int)$row[0];
                if ($staffId <= 0) continue;

                // Validasi akses Kepsek
                if ($isKepsek && $ksUnitId) {
                    $checkUnit = $pdo->prepare("SELECT unit_id FROM staff WHERE id = ? LIMIT 1");
                    $checkUnit->execute([$staffId]);
                    if ((int)$checkUnit->fetchColumn() !== $ksUnitId) continue;
                }

                $phone = isset($row[4]) ? trim($row[4]) : '';
                $waNotify = isset($row[5]) ? trim($row[5]) : '';
                $rfidUid = isset($row[6]) ? trim($row[6]) : '';
                $fingerprintId = isset($row[7]) ? trim($row[7]) : '';

                $curStmt = $pdo->prepare("SELECT phone, wa_notify, rfid_uid, fingerprint_id FROM staff WHERE id = ?");
                $curStmt->execute([$staffId]);
                $currentData = $curStmt->fetch(PDO::FETCH_ASSOC);
                if (!$currentData) continue;

                $newPhone = $currentData['phone'];
                $newNotify = $currentData['wa_notify'];
                $newRfid = $currentData['rfid_uid'];
                $newFinger = $currentData['fingerprint_id'];

                if ($ignoreEmpty) {
                    if ($phone !== '') $newPhone = $phone;
                    if ($waNotify !== '') $newNotify = (int)$waNotify;
                    if ($rfidUid !== '') $newRfid = strtoupper($rfidUid);
                    if ($fingerprintId !== '' && is_numeric($fingerprintId)) $newFinger = (int)$fingerprintId;
                } else {
                    $newPhone = $phone !== '' ? $phone : null;
                    $newNotify = $waNotify !== '' ? (int)$waNotify : 1;
                    $newRfid = $rfidUid !== '' ? strtoupper($rfidUid) : null;
                    $newFinger = ($fingerprintId !== '' && is_numeric($fingerprintId)) ? (int)$fingerprintId : null;
                }

                $upStmt = $pdo->prepare("
                    UPDATE staff 
                    SET phone = ?, wa_notify = ?, rfid_uid = ?, fingerprint_id = ?
                    WHERE id = ?
                ");
                $upStmt->execute([$newPhone, $newNotify, $newRfid, $newFinger, $staffId]);
                $updatedCount++;
            }

            $pdo->commit();
            fclose($file);
            flash('success', "Berhasil memperbarui {$updatedCount} data staff dari CSV.");
            redirect('bulk_edit.php?' . http_build_query($_GET));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fclose($file);
            flash('error', 'Gagal memproses CSV: ' . $e->getMessage());
            redirect('bulk_edit.php?' . http_build_query($_GET));
        }
    }

    // 2. SIMPAN DARI TABEL WEB
    if ($action === 'save_table') {
        $staffData = $_POST['staff'] ?? [];
        $selectedIds = $_POST['selected_ids'] ?? [];

        if (empty($staffData)) {
            flash('error', 'Tidak ada data yang dikirim.');
            redirect('bulk_edit.php?' . http_build_query($_GET));
        }

        $updatedCount = 0;
        $pdo->beginTransaction();

        try {
            foreach ($staffData as $sid => $fields) {
                $sid = (int)$sid;
                if ($sid <= 0) continue;

                if (!empty($selectedIds) && !in_array($sid, $selectedIds)) {
                    continue;
                }

                if ($isKepsek && $ksUnitId) {
                    $checkUnit = $pdo->prepare("SELECT unit_id FROM staff WHERE id = ? LIMIT 1");
                    $checkUnit->execute([$sid]);
                    if ((int)$checkUnit->fetchColumn() !== $ksUnitId) continue;
                }

                $phone = trim($fields['phone'] ?? '');
                $waNotify = isset($fields['wa_notify']) ? 1 : 0;
                $rfidUid = trim($fields['rfid_uid'] ?? '');
                $fingerprintId = trim($fields['fingerprint_id'] ?? '');

                $finalPhone = $phone !== '' ? $phone : null;
                $finalRfid = $rfidUid !== '' ? strtoupper($rfidUid) : null;
                $finalFinger = ($fingerprintId !== '' && is_numeric($fingerprintId)) ? (int)$fingerprintId : null;

                $upStmt = $pdo->prepare("
                    UPDATE staff 
                    SET phone = ?, wa_notify = ?, rfid_uid = ?, fingerprint_id = ?
                    WHERE id = ?
                ");
                $upStmt->execute([$finalPhone, $waNotify, $finalRfid, $finalFinger, $sid]);
                $updatedCount++;
            }

            $pdo->commit();
            flash('success', "Berhasil memperbarui {$updatedCount} data staff/guru secara massal.");
            redirect('bulk_edit.php?' . http_build_query($_GET));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal menyimpan perubahan: ' . $e->getMessage());
            redirect('bulk_edit.php?' . http_build_query($_GET));
        }
    }
}

// =========================================================================
// QUERY DATA STAFF
// =========================================================================
$where = ["s.deleted_at IS NULL"];
$params = [];

if ($isKepsek && $ksUnitId) {
    $where[] = "s.unit_id = ?";
    $params[] = $ksUnitId;
} elseif ($unitId > 0) {
    $where[] = "s.unit_id = ?";
    $params[] = $unitId;
}

if ($search !== '') {
    $where[] = "(s.name LIKE ? OR s.nik LIKE ?)";
    $params[] = "%{$search}%"; $params[] = "%{$search}%";
}

if ($filterEmpty === 'empty_phone') {
    $where[] = "(s.phone IS NULL OR s.phone = '')";
} elseif ($filterEmpty === 'empty_rfid') {
    $where[] = "(s.rfid_uid IS NULL OR s.rfid_uid = '')";
} elseif ($filterEmpty === 'empty_finger') {
    $where[] = "(s.fingerprint_id IS NULL OR s.fingerprint_id = 0)";
}

$whereSql = implode(' AND ', $where);

$stmtStaff = $pdo->prepare("
    SELECT s.id, s.name, s.nik, s.photo, s.phone, s.wa_notify, s.rfid_uid, s.fingerprint_id,
           s.unit_id, un.unit as unit_name
    FROM staff s
    LEFT JOIN units un ON un.id = s.unit_id
    WHERE {$whereSql}
    ORDER BY un.unit, s.name
    LIMIT 200
");
$stmtStaff->execute($params);
$staffList = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);

require '../../includes/header.php';
?>

<style>
.bulk-badge {
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}
.bulk-badge-empty {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px dashed #fca5a5;
}
.bulk-badge-filled {
    background: #f0fdf4;
    color: #166534;
}
.cell-input {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    transition: all 0.2s;
    background: #fff;
    box-sizing: border-box;
}
.cell-input:focus {
    border-color: #3b82f6;
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}
.cell-input.is-empty {
    background: #fffbeb;
    border-color: #fde68a;
}
.cell-input.modified {
    background: #ecfdf5 !important;
    border-color: #10b981 !important;
    font-weight: 600;
}
.row-modified {
    background-color: #f0fdf4 !important;
}
.switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
}
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background-color: #cbd5e1; transition: .3s; border-radius: 24px;
}
.slider:before {
    position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px;
    background-color: white; transition: .3s; border-radius: 50%;
}
input:checked + .slider { background-color: #10b981; }
input:checked + .slider:before { transform: translateX(20px); }
.sticky-bar {
    position: sticky;
    top: 15px;
    z-index: 100;
    background: #ffffff;
    padding: 14px 20px;
    border-radius: 12px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
</style>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header" style="flex-wrap: wrap; gap: 15px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="background: linear-gradient(135deg, #059669, #10b981); color: white; padding: 6px 12px; border-radius: 8px; font-size: 14px; font-weight: 700;">
                    <i class="fa-solid fa-table-cells"></i> Google Admin Style
                </span>
                <h3 style="margin: 0;">Bulk Update Staff / Guru (No. WA, RFID & Fingerprint)</h3>
            </div>
            <p style="margin: 6px 0 0 0; color: #64748b; font-size: 13.5px;">
                Perbarui data staff massal. Nomor WhatsApp staff untuk rekapan bulanan, serta UID kartu RFID dan ID Fingerprint untuk alat ESP32.
            </p>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="index.php" class="btn" style="background:#f1f5f9; color:#475569;">
                <i class="fa-solid fa-arrow-left"></i> Kembali
            </a>
            <a href="bulk_edit.php?action=export_csv&<?= http_build_query($_GET) ?>" class="btn" style="background:#0284c7; color:white;">
                <i class="fa-solid fa-file-arrow-down"></i> Download CSV Pre-filled
            </a>
            <button type="button" class="btn" style="background:#10b981; color:white;" onclick="document.getElementById('modalImportCsv').style.display='flex'">
                <i class="fa-solid fa-file-arrow-up"></i> Upload Perubahan CSV
            </button>
        </div>
    </div>

    <!-- FILTER BAR -->
    <form method="GET" style="margin-top: 15px;">
        <div class="filter-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="form-group">
                <label>Pencarian</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Nama atau NIK...">
            </div>

            <?php if ($isSuperAdmin): ?>
            <div class="form-group">
                <label>Unit Sekolah</label>
                <select name="unit_id" onchange="this.form.submit()">
                    <option value="0">Semua Unit</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Status Kelengkapan</label>
                <select name="filter_empty" onchange="this.form.submit()">
                    <option value="all" <?= $filterEmpty === 'all' ? 'selected' : '' ?>>Semua Data</option>
                    <option value="empty_phone" <?= $filterEmpty === 'empty_phone' ? 'selected' : '' ?>>⚠️ No WA Staff Kosong</option>
                    <option value="empty_rfid" <?= $filterEmpty === 'empty_rfid' ? 'selected' : '' ?>>💳 RFID Belum Ada</option>
                    <option value="empty_finger" <?= $filterEmpty === 'empty_finger' ? 'selected' : '' ?>>👆 Fingerprint Kosong</option>
                </select>
            </div>

            <div style="display: flex; align-items: flex-end; gap: 5px;">
                <button type="submit" class="btn btn-primary" style="height: 40px; width: 100%;">
                    <i class="fa-solid fa-filter"></i> Terapkan
                </button>
            </div>
        </div>
    </form>
</div>

<!-- STICKY ACTION BAR -->
<form method="POST" id="bulkForm">
<input type="hidden" name="action" value="save_table">

<div class="sticky-bar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 700; user-select: none;">
            <input type="checkbox" id="checkAll" style="width: 18px; height: 18px; accent-color: #3b82f6;">
            <span>Pilih Semua</span>
        </label>
        <span style="color: #64748b; font-size: 13px;">
            (<span id="selectedCount">0</span> staff terpilih &bull; <span id="modifiedCount" style="color: #059669; font-weight: 700;">0</span> baris diubah)
        </span>
    </div>

    <div style="display: flex; align-items: center; gap: 10px;">
        <div class="dropdown-wrapper" style="display: inline-block;">
            <button type="button" class="btn" style="background: #f8fafc; border: 1px solid #cbd5e1; color: #334155;" onclick="toggleBatchMenu()">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Aksi Cepat Massal <i class="fa-solid fa-chevron-down" style="font-size: 11px;"></i>
            </button>
            <div id="batchMenu" style="display: none; position: absolute; right: 200px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); z-index: 1000; min-width: 220px; padding: 5px 0;">
                <a href="javascript:void(0)" onclick="setBatchWa(1)" style="display: block; padding: 8px 15px; color: #166534; text-decoration: none; font-size: 13px;">
                    <i class="fa-solid fa-toggle-on" style="color: #10b981;"></i> Set WA: ON Semua Terpilih
                </a>
                <a href="javascript:void(0)" onclick="setBatchWa(0)" style="display: block; padding: 8px 15px; color: #991b1b; text-decoration: none; font-size: 13px;">
                    <i class="fa-solid fa-toggle-off" style="color: #ef4444;"></i> Set WA: OFF Semua Terpilih
                </a>
                <a href="javascript:void(0)" onclick="clearBatch('rfid')" style="display: block; padding: 8px 15px; color: #475569; text-decoration: none; font-size: 13px;">
                    <i class="fa-solid fa-eraser"></i> Kosongkan RFID Terpilih
                </a>
                <a href="javascript:void(0)" onclick="clearBatch('finger')" style="display: block; padding: 8px 15px; color: #475569; text-decoration: none; font-size: 13px;">
                    <i class="fa-solid fa-eraser"></i> Kosongkan Finger Terpilih
                </a>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 10px 22px; font-weight: 700; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3);">
            <i class="fa-solid fa-floppy-disk"></i> Simpan Semua Perubahan
        </button>
    </div>
</div>

<!-- TABEL SPREADSHEET BULK UPDATE STAFF -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-wrapper" style="max-height: 650px; overflow-y: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; position: sticky; top: 0; z-index: 10;">
                    <th style="width: 40px; text-align: center;">#</th>
                    <th style="width: 50px;">Foto</th>
                    <th style="min-width: 180px;">Nama Staff</th>
                    <th style="width: 120px;">NIK / Barcode</th>
                    <th style="width: 140px;">Unit</th>
                    <th style="min-width: 200px;">
                        <i class="fa-brands fa-whatsapp" style="color: #10b981;"></i> Nomor WhatsApp Staff
                    </th>
                    <th style="width: 90px; text-align: center;">Notif WA</th>
                    <th style="min-width: 170px;">
                        <i class="fa-solid fa-id-card" style="color: #6366f1;"></i> RFID Card UID
                    </th>
                    <th style="width: 140px;">
                        <i class="fa-solid fa-fingerprint" style="color: #0ea5e9;"></i> Fingerprint ID
                    </th>
                    <th style="width: 90px; text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffList)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="fa-solid fa-circle-exclamation" style="font-size: 28px; margin-bottom: 10px; display: block;"></i>
                            Tidak ada staff yang sesuai dengan filter pencarian di atas.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($staffList as $idx => $st): 
                    $hasPhone = !empty($st['phone']);
                    $hasRfid = !empty($st['rfid_uid']);
                    $hasFinger = !empty($st['fingerprint_id']);
                ?>
                <tr id="row-<?= $st['id'] ?>" style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;">
                    <td style="text-align: center;">
                        <input type="checkbox" name="selected_ids[]" value="<?= $st['id'] ?>" class="row-checkbox" onchange="updateCounts()" style="width: 16px; height: 16px; accent-color: #3b82f6;">
                    </td>
                    <td>
                        <?php if (!empty($st['photo'])): ?>
                            <img src="../../uploads/staff/<?= e($st['photo']) ?>" width="36" height="36" style="object-fit:cover; border-radius:8px;">
                        <?php else: ?>
                            <div class="avatar" style="width: 36px; height: 36px; font-size: 12px;"><?= strtoupper(substr($st['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #1e293b;"><?= e($st['name']) ?></div>
                        <small style="color: #64748b;">ID: <?= $st['id'] ?></small>
                    </td>
                    <td>
                        <div><b><?= e($st['nik'] ?: '-') ?></b></div>
                    </td>
                    <td>
                        <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 11px; padding: 3px 7px;">
                            <?= e($st['unit_name'] ?? '-') ?>
                        </span>
                    </td>
                    <!-- CELL NO WA STAFF -->
                    <td>
                        <input type="text" 
                               name="staff[<?= $st['id'] ?>][phone]" 
                               value="<?= e($st['phone'] ?? '') ?>" 
                               placeholder="Kosong (isi no WA)..." 
                               class="cell-input <?= !$hasPhone ? 'is-empty' : '' ?>"
                               data-orig="<?= e($st['phone'] ?? '') ?>"
                               oninput="markModified(this, <?= $st['id'] ?>)">
                    </td>
                    <!-- CELL TOGGLE NOTIF WA -->
                    <td style="text-align: center;">
                        <label class="switch">
                            <input type="checkbox" 
                                   name="staff[<?= $st['id'] ?>][wa_notify]" 
                                   value="1" 
                                   <?= ((int)$st['wa_notify'] === 1) ? 'checked' : '' ?>
                                   data-orig="<?= ((int)$st['wa_notify'] === 1) ? '1' : '0' ?>"
                                   onchange="markModified(this, <?= $st['id'] ?>)">
                            <span class="slider"></span>
                        </label>
                    </td>
                    <!-- CELL RFID UID -->
                    <td>
                        <input type="text" 
                               name="staff[<?= $st['id'] ?>][rfid_uid]" 
                               value="<?= e($st['rfid_uid'] ?? '') ?>" 
                               placeholder="Tap kartu / ketik..." 
                               class="cell-input <?= !$hasRfid ? 'is-empty' : '' ?>"
                               data-orig="<?= e($st['rfid_uid'] ?? '') ?>"
                               oninput="this.value = this.value.toUpperCase(); markModified(this, <?= $st['id'] ?>)">
                    </td>
                    <!-- CELL FINGERPRINT ID -->
                    <td>
                        <input type="number" 
                               name="staff[<?= $st['id'] ?>][fingerprint_id]" 
                               min="1" max="1000"
                               value="<?= e($st['fingerprint_id'] ?? '') ?>" 
                               placeholder="1-1000" 
                               class="cell-input <?= !$hasFinger ? 'is-empty' : '' ?>"
                               data-orig="<?= e($st['fingerprint_id'] ?? '') ?>"
                               oninput="markModified(this, <?= $st['id'] ?>)">
                    </td>
                    <!-- CELL STATUS -->
                    <td style="text-align: center;">
                        <span id="badge-<?= $st['id'] ?>" class="bulk-badge bulk-badge-filled">Tersimpan</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</form>

<!-- MODAL IMPORT CSV -->
<div id="modalImportCsv" class="modal-overlay">
    <div class="modal-box" style="max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;"><i class="fa-solid fa-file-arrow-up" style="color: #10b981;"></i> Upload Perubahan CSV Staff</h3>
            <button type="button" onclick="document.getElementById('modalImportCsv').style.display='none'" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <p style="color: #64748b; font-size: 13px; margin-top: 0;">
            Unggah file CSV yang telah Anda edit (disarankan menggunakan template dari tombol <b>Download CSV Pre-filled</b>).
        </p>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_csv">

            <div class="form-group" style="margin-bottom: 15px;">
                <label>Pilih File CSV *</label>
                <input type="file" name="csv_file" accept=".csv" required style="width: 100%; padding: 8px; border: 1px dashed #cbd5e1; border-radius: 8px;">
            </div>

            <div style="background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 13px;">
                    <input type="checkbox" name="ignore_empty_cells" value="1" checked style="margin-top: 3px; accent-color: #10b981;">
                    <span>
                        <b>Abaikan kolom kosong</b><br>
                        <span style="color: #64748b; font-size: 12px;">Jika dicentang, data lama di database tidak akan terhapus jika di CSV kosong. Jika tidak dicentang, data di database akan dikosongkan jika sel CSV kosong.</span>
                    </span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569;" onclick="document.getElementById('modalImportCsv').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Mulai Import</button>
            </div>
        </form>
    </div>
</div>

<script>
let modifiedRows = new Set();

function markModified(elem, rowId) {
    const orig = elem.getAttribute('data-orig');
    let currentVal = elem.type === 'checkbox' ? (elem.checked ? '1' : '0') : elem.value.trim();
    
    const row = document.getElementById('row-' + rowId);
    const chk = row.querySelector('.row-checkbox');
    const badge = document.getElementById('badge-' + rowId);

    let isRowChanged = false;
    row.querySelectorAll('[data-orig]').forEach(input => {
        let oVal = input.getAttribute('data-orig');
        let cVal = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value.trim();
        if (oVal !== cVal) {
            isRowChanged = true;
            if (input.type !== 'checkbox') input.classList.add('modified');
        } else {
            if (input.type !== 'checkbox') input.classList.remove('modified');
        }
    });

    if (isRowChanged) {
        modifiedRows.add(rowId);
        row.classList.add('row-modified');
        chk.checked = true;
        badge.className = 'bulk-badge bulk-badge-empty';
        badge.textContent = 'Diubah';
    } else {
        modifiedRows.delete(rowId);
        row.classList.remove('row-modified');
        badge.className = 'bulk-badge bulk-badge-filled';
        badge.textContent = 'Tersimpan';
    }

    updateCounts();
}

function updateCounts() {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    let selCount = 0;
    checkboxes.forEach(c => { if(c.checked) selCount++; });

    document.getElementById('selectedCount').textContent = selCount;
    document.getElementById('modifiedCount').textContent = modifiedRows.size;
}

document.getElementById('checkAll').addEventListener('change', function() {
    const checked = this.checked;
    document.querySelectorAll('.row-checkbox').forEach(c => { c.checked = checked; });
    updateCounts();
});

function toggleBatchMenu() {
    const menu = document.getElementById('batchMenu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown-wrapper')) {
        const menu = document.getElementById('batchMenu');
        if (menu) menu.style.display = 'none';
    }
});

function setBatchWa(state) {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    if (checked.length === 0) {
        alert('Pilih minimal 1 staff terlebih dahulu.');
        return;
    }
    checked.forEach(c => {
        const row = c.closest('tr');
        const sid = c.value;
        const toggle = row.querySelector('input[type="checkbox"][name*="[wa_notify]"]');
        if (toggle) {
            toggle.checked = (state === 1);
            markModified(toggle, sid);
        }
    });
    toggleBatchMenu();
}

function clearBatch(field) {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    if (checked.length === 0) {
        alert('Pilih minimal 1 staff terlebih dahulu.');
        return;
    }
    if (!confirm('Yakin ingin mengosongkan nilai ' + field.toUpperCase() + ' untuk staff yang dipilih?')) return;

    checked.forEach(c => {
        const row = c.closest('tr');
        const sid = c.value;
        let input;
        if (field === 'rfid') {
            input = row.querySelector('input[name*="[rfid_uid]"]');
        } else if (field === 'finger') {
            input = row.querySelector('input[name*="[fingerprint_id]"]');
        }
        if (input) {
            input.value = '';
            markModified(input, sid);
        }
    });
    toggleBatchMenu();
}
</script>

<?php require '../../includes/footer.php'; ?>
