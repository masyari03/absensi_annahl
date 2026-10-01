<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// Pastikan user login
requireLogin();

// Cek hak akses ke menu 'data_cctv'
checkUserAccess('data_cctv');

$pageTitle = 'Pendataan CCTV & Status Perbaikan';
$currentUserId = currentUserId();
$currentRole = currentRole();

// =========================================================================
// 1. PROSES POST: CRUD, UPDATE STATUS KENDALA, REORDER, LINK HIK-CONNECT
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A. UPDATE STATUS KENDALA / PERBAIKAN ("Tandai di Web")
    if ($action === 'update_camera_status') {
        $id = (int)($_POST['cctv_id'] ?? 0);
        $newStatus = in_array($_POST['status'] ?? '', ['normal', 'error', 'maintenance']) ? $_POST['status'] : 'normal';
        $errorType = trim($_POST['error_type'] ?? '');
        $errorNotes = trim($_POST['error_notes'] ?? '');
        $repairPic = trim($_POST['repair_pic'] ?? '');

        if ($id > 0) {
            if ($newStatus === 'normal') {
                // Kamera sudah normal / selesai perbaikan
                $stmt = $pdo->prepare("
                    UPDATE cctv_devices 
                    SET status = 'normal', 
                        repair_date = NOW(),
                        repair_pic = COALESCE(NULLIF(?, ''), repair_pic)
                    WHERE id = ?
                ");
                $stmt->execute([$repairPic, $id]);
                flash('success', 'Status kamera berhasil ditandai NORMAL (Selesai Perbaikan).');
            } elseif ($newStatus === 'error') {
                // Kamera dilaporkan error/rusak
                $stmt = $pdo->prepare("
                    UPDATE cctv_devices 
                    SET status = 'error',
                        error_type = ?,
                        error_notes = ?,
                        error_date = COALESCE(error_date, NOW()),
                        repair_date = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$errorType ?: 'Kamera Mati / No Signal', $errorNotes, $id]);
                flash('success', 'Kamera berhasil ditandai ERROR / RUSAK (Perlu Perbaikan).');
            } elseif ($newStatus === 'maintenance') {
                // Sedang dalam penanganan teknisi
                $stmt = $pdo->prepare("
                    UPDATE cctv_devices 
                    SET status = 'maintenance',
                        error_type = ?,
                        error_notes = ?,
                        repair_pic = ?,
                        error_date = COALESCE(error_date, NOW())
                    WHERE id = ?
                ");
                $stmt->execute([$errorType ?: 'Sedang Dikerjakan', $errorNotes, $repairPic, $id]);
                flash('success', 'Status kamera ditandai SEDANG PERBAIKAN / MAINTENANCE.');
            }
        }
        
        $redirectUrl = 'index.php' . (!empty($_POST['return_query']) ? '?' . $_POST['return_query'] : '');
        redirect($redirectUrl);
    }

    // B. SIMPAN / UPDATE LINK PORTAL HIK-CONNECT
    if ($action === 'save_hikconnect_setting') {
        $url = trim($_POST['hikconnect_url'] ?? '');
        if ($url === '') {
            $url = 'https://www.hik-connect.com';
        }
        $stmt = $pdo->prepare("
            INSERT INTO cctv_settings (key_name, key_value) 
            VALUES ('hikconnect_url', ?) 
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)
        ");
        $stmt->execute([$url]);
        flash('success', 'Link portal Hik-Connect Web berhasil disimpan.');
        redirect('index.php');
    }

    // C. TAMBAH PENDATAAN KAMERA CCTV BARU
    if ($action === 'create_cctv') {
        $dvrServer = trim($_POST['dvr_server'] ?? 'DVR SMP');
        $roomName = trim($_POST['room_name'] ?? '');
        $floorName = trim($_POST['floor_name'] ?? 'Lantai 1');
        $channel = !empty($_POST['channel_number']) ? (int)$_POST['channel_number'] : 1;
        $sortOrder = !empty($_POST['sort_order']) ? (int)$_POST['sort_order'] : $channel;
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $name = $roomName . ' (' . $dvrServer . ')';
        }
        $status = in_array($_POST['status'] ?? '', ['normal', 'error', 'maintenance']) ? $_POST['status'] : 'normal';
        $ip = trim($_POST['ip_address'] ?? '');
        $errorType = trim($_POST['error_type'] ?? '');
        $errorNotes = trim($_POST['error_notes'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Default location ID
        $locId = (int)$pdo->query("SELECT id FROM cctv_locations LIMIT 1")->fetchColumn() ?: 1;

        if ($roomName === '') {
            flash('error', 'Nama Ruangan / Titik kamera wajib diisi.');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cctv_devices 
                (location_id, dvr_server, room_name, floor_name, channel_number, sort_order, name, status, ip_address, error_type, error_notes, error_date, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $errorDate = ($status === 'error' || $status === 'maintenance') ? date('Y-m-d H:i:s') : null;
            $stmt->execute([
                $locId,
                $dvrServer,
                $roomName,
                $floorName,
                $channel,
                $sortOrder,
                $name,
                $status,
                $ip ?: null,
                $errorType ?: null,
                $errorNotes ?: null,
                $errorDate,
                $notes ?: null
            ]);
            flash('success', 'Data kamera CCTV di "' . htmlspecialchars($roomName) . '" berhasil ditambahkan.');
        }

        $redirectQuery = http_build_query([
            'dvr' => $dvrServer,
            'floor' => $floorName
        ]);
        redirect('index.php?' . $redirectQuery);
    }

    // D. EDIT DATA KAMERA CCTV
    if ($action === 'update_cctv') {
        $id = (int)($_POST['cctv_id'] ?? 0);
        $dvrServer = trim($_POST['dvr_server'] ?? 'DVR SMP');
        $roomName = trim($_POST['room_name'] ?? '');
        $floorName = trim($_POST['floor_name'] ?? 'Lantai 1');
        $channel = !empty($_POST['channel_number']) ? (int)$_POST['channel_number'] : 1;
        $sortOrder = !empty($_POST['sort_order']) ? (int)$_POST['sort_order'] : $channel;
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $name = $roomName . ' (' . $dvrServer . ')';
        }
        $status = in_array($_POST['status'] ?? '', ['normal', 'error', 'maintenance']) ? $_POST['status'] : 'normal';
        $ip = trim($_POST['ip_address'] ?? '');
        $errorType = trim($_POST['error_type'] ?? '');
        $errorNotes = trim($_POST['error_notes'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($id > 0 && $roomName !== '') {
            $stmt = $pdo->prepare("
                UPDATE cctv_devices 
                SET dvr_server = ?, room_name = ?, floor_name = ?, channel_number = ?, 
                    sort_order = ?, name = ?, status = ?, ip_address = ?, 
                    error_type = ?, error_notes = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $dvrServer,
                $roomName,
                $floorName,
                $channel,
                $sortOrder,
                $name,
                $status,
                $ip ?: null,
                $errorType ?: null,
                $errorNotes ?: null,
                $notes ?: null,
                $id
            ]);
            flash('success', 'Data kamera di "' . htmlspecialchars($roomName) . '" berhasil diperbarui.');
        } else {
            flash('error', 'Gagal memperbarui: Data ruangan wajib diisi.');
        }

        $redirectUrl = 'index.php' . (!empty($_POST['return_query']) ? '?' . $_POST['return_query'] : '');
        redirect($redirectUrl);
    }

    // E. HAPUS DATA KAMERA
    if ($action === 'delete_cctv') {
        $id = (int)($_POST['cctv_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM cctv_devices WHERE id = ?");
            $stmt->execute([$id]);
            flash('success', 'Data kamera CCTV berhasil dihapus.');
        }
        $redirectUrl = 'index.php' . (!empty($_POST['return_query']) ? '?' . $_POST['return_query'] : '');
        redirect($redirectUrl);
    }

    // F. REORDER (GESER URUTAN NAIK / TURUN)
    if ($action === 'move_order') {
        $id = (int)($_POST['cctv_id'] ?? 0);
        $dir = $_POST['direction'] ?? 'up';

        $stmtCur = $pdo->prepare("SELECT id, dvr_server, floor_name, sort_order FROM cctv_devices WHERE id = ?");
        $stmtCur->execute([$id]);
        $current = $stmtCur->fetch(PDO::FETCH_ASSOC);

        if ($current) {
            $curDvr = $current['dvr_server'];
            $curFloor = $current['floor_name'];
            $curOrder = (int)$current['sort_order'];

            if ($dir === 'up') {
                $stmtNeighbor = $pdo->prepare("
                    SELECT id, sort_order FROM cctv_devices 
                    WHERE dvr_server = ? AND floor_name = ? AND sort_order < ? 
                    ORDER BY sort_order DESC LIMIT 1
                ");
            } else {
                $stmtNeighbor = $pdo->prepare("
                    SELECT id, sort_order FROM cctv_devices 
                    WHERE dvr_server = ? AND floor_name = ? AND sort_order > ? 
                    ORDER BY sort_order ASC LIMIT 1
                ");
            }
            $stmtNeighbor->execute([$curDvr, $curFloor, $curOrder]);
            $neighbor = $stmtNeighbor->fetch(PDO::FETCH_ASSOC);

            if ($neighbor) {
                $neighborId = $neighbor['id'];
                $neighborOrder = (int)$neighbor['sort_order'];

                $pdo->beginTransaction();
                $pdo->prepare("UPDATE cctv_devices SET sort_order = ? WHERE id = ?")->execute([$neighborOrder, $id]);
                $pdo->prepare("UPDATE cctv_devices SET sort_order = ? WHERE id = ?")->execute([$curOrder, $neighborId]);
                $pdo->commit();
            }
        }
        $redirectUrl = 'index.php' . (!empty($_POST['return_query']) ? '?' . $_POST['return_query'] : '');
        redirect($redirectUrl);
    }
}

// =========================================================================
// 2. QUERY & FILTER DATA PENDATAAN CCTV
// =========================================================================

// A. URL Hik-Connect Web
$hikConnectUrl = 'https://www.hik-connect.com';
$stmtSet = $pdo->query("SELECT key_value FROM cctv_settings WHERE key_name = 'hikconnect_url' LIMIT 1");
if ($val = $stmtSet->fetchColumn()) {
    $hikConnectUrl = $val;
}

// B. Ambil Daftar SVR / DVR yang Ada
$stmtDvrList = $pdo->query("
    SELECT DISTINCT dvr_server 
    FROM cctv_devices 
    WHERE dvr_server IS NOT NULL AND dvr_server != ''
    ORDER BY CASE 
        WHEN dvr_server = 'DVR SMP' THEN 1
        WHEN dvr_server = 'SMA' THEN 2
        WHEN dvr_server = 'SD 1' THEN 3
        WHEN dvr_server = 'SD 2' THEN 4
        WHEN dvr_server = 'SD 3' THEN 5
        WHEN dvr_server = 'FO' THEN 6
        WHEN dvr_server = 'Masjid' THEN 7
        ELSE 8 END, dvr_server ASC
");
$allDvrList = $stmtDvrList->fetchAll(PDO::FETCH_COLUMN);
if (empty($allDvrList)) {
    $allDvrList = ['DVR SMP', 'SMA', 'SD 1', 'SD 2', 'SD 3', 'FO', 'Masjid'];
}

// C. Ambil Daftar Lantai yang Ada
$stmtFloorList = $pdo->query("
    SELECT DISTINCT floor_name 
    FROM cctv_devices 
    WHERE floor_name IS NOT NULL AND floor_name != ''
    ORDER BY floor_name ASC
");
$allFloorList = $stmtFloorList->fetchAll(PDO::FETCH_COLUMN);
if (empty($allFloorList)) {
    $allFloorList = ['Lantai 1', 'Lantai 2', 'Lantai 3', 'Area Luar'];
}

// D. Filter Parameter
$filterDvr    = trim($_GET['dvr'] ?? '');
$filterFloor  = trim($_GET['floor'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$searchQuery  = trim($_GET['q'] ?? '');

// Current query string untuk redirect kembali ke filter yang sama
$currentFilterParams = [];
if ($filterDvr !== '')    $currentFilterParams['dvr'] = $filterDvr;
if ($filterFloor !== '')  $currentFilterParams['floor'] = $filterFloor;
if ($filterStatus !== '') $currentFilterParams['status'] = $filterStatus;
if ($searchQuery !== '')  $currentFilterParams['q'] = $searchQuery;
$currentQueryString = http_build_query($currentFilterParams);

// E. Hitung Statistik Ringkasan (KPI)
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM cctv_devices")->fetchColumn();
$normalCount = (int)$pdo->query("SELECT COUNT(*) FROM cctv_devices WHERE status = 'normal'")->fetchColumn();
$errorCount = (int)$pdo->query("SELECT COUNT(*) FROM cctv_devices WHERE status = 'error'")->fetchColumn();
$maintCount = (int)$pdo->query("SELECT COUNT(*) FROM cctv_devices WHERE status = 'maintenance'")->fetchColumn();

// F. Hitung Jumlah Kamera Per SVR / DVR untuk Badge Tab
$dvrCounts = [];
$stmtCountDvr = $pdo->query("SELECT dvr_server, COUNT(*) as cnt FROM cctv_devices GROUP BY dvr_server");
foreach ($stmtCountDvr->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dvrCounts[$row['dvr_server']] = (int)$row['cnt'];
}

// G. Query Data Kamera Sesuai Filter
$sql = "
    SELECT id, dvr_server, room_name, floor_name, channel_number, sort_order, name, 
           status, ip_address, error_type, error_notes, error_date, repair_date, repair_pic, notes, updated_at
    FROM cctv_devices
    WHERE 1=1
";
$params = [];

if ($filterDvr !== '') {
    $sql .= " AND dvr_server = :dvr";
    $params[':dvr'] = $filterDvr;
}

if ($filterFloor !== '') {
    $sql .= " AND floor_name = :floor";
    $params[':floor'] = $filterFloor;
}

if ($filterStatus !== '') {
    $sql .= " AND status = :status";
    $params[':status'] = $filterStatus;
}

if ($searchQuery !== '') {
    $sql .= " AND (room_name LIKE :q OR name LIKE :q2 OR dvr_server LIKE :q3 OR error_notes LIKE :q4 OR channel_number LIKE :q5)";
    $params[':q']  = "%{$searchQuery}%";
    $params[':q2'] = "%{$searchQuery}%";
    $params[':q3'] = "%{$searchQuery}%";
    $params[':q4'] = "%{$searchQuery}%";
    $params[':q5'] = "%{$searchQuery}%";
}

// Urutan: DVR -> Lantai -> Urutan / Channel
$sql .= " ORDER BY 
    CASE 
        WHEN dvr_server = 'DVR SMP' THEN 1
        WHEN dvr_server = 'SMA' THEN 2
        WHEN dvr_server = 'SD 1' THEN 3
        WHEN dvr_server = 'SD 2' THEN 4
        WHEN dvr_server = 'SD 3' THEN 5
        WHEN dvr_server = 'FO' THEN 6
        WHEN dvr_server = 'Masjid' THEN 7
        ELSE 8 END ASC,
    floor_name ASC, 
    sort_order ASC, 
    channel_number ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cctvList = $stmt->fetchAll(PDO::FETCH_ASSOC);

require '../../includes/header.php';
?>

<style>
/* ==========================================================================
   DESAIN MODERN: PENDATAAN CCTV & SISTEM STATUS PERBAIKAN
   ========================================================================== */
.cctv-mgmt {
    animation: fadeIn 0.3s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Header & Quick Action */
.page-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
}
.page-title-box h1 {
    margin: 0;
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 12px;
}
.page-title-box p {
    margin: 4px 0 0 0;
    color: #64748b;
    font-size: 13.5px;
}

/* JALUR HIK-CONNECT BANNER (JALUR KE WEB PORTAL HIKVISION) */
.hik-banner-card {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 14px;
    padding: 18px 24px;
    margin-bottom: 22px;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
}
.hik-banner-info {
    display: flex;
    align-items: center;
    gap: 16px;
}
.hik-logo-badge {
    width: 48px;
    height: 48px;
    background: #ef4444;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(239, 68, 68, 0.4);
    flex-shrink: 0;
}
.hik-banner-text h2 {
    margin: 0;
    font-size: 17px;
    font-weight: 800;
    color: #ffffff;
}
.hik-banner-text p {
    margin: 3px 0 0 0;
    color: #cbd5e1;
    font-size: 13px;
}
.hik-banner-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.btn-hik-connect {
    background: #ef4444;
    color: #ffffff;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
}
.btn-hik-connect:hover {
    background: #dc2626;
    color: #ffffff;
    transform: translateY(-2px);
}
.btn-setting-link {
    background: rgba(255, 255, 255, 0.1);
    color: #e2e8f0;
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 9px 15px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: 0.2s;
}
.btn-setting-link:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

/* KPI Summary Cards Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}
.kpi-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 18px 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.04);
    display: flex;
    align-items: center;
    gap: 16px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px -3px rgba(0, 0, 0, 0.08);
}
.kpi-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.kpi-card.total .kpi-icon { background: rgba(79, 70, 229, 0.12); color: #4f46e5; }
.kpi-card.normal .kpi-icon { background: rgba(16, 185, 129, 0.12); color: #10b981; }
.kpi-card.error .kpi-icon { background: rgba(239, 68, 68, 0.12); color: #ef4444; }
.kpi-card.maint .kpi-icon { background: rgba(245, 158, 11, 0.12); color: #f59e0b; }

.kpi-info h3 {
    margin: 0;
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}
.kpi-info span {
    font-size: 12.5px;
    font-weight: 600;
    color: #64748b;
}

/* SVR / DVR NAVIGATION TABS */
.svr-tabs-scroll {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 12px;
    margin-bottom: 18px;
    scrollbar-width: thin;
}
.svr-tab-btn {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 9px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.svr-tab-btn:hover {
    background: #f1f5f9;
    color: #0f172a;
    border-color: #94a3b8;
}
.svr-tab-btn.active {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
    box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
}
.svr-tab-badge {
    background: rgba(0, 0, 0, 0.08);
    padding: 2px 7px;
    border-radius: 20px;
    font-size: 11px;
}
.svr-tab-btn.active .svr-tab-badge {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
}

/* Control Bar */
.control-bar {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
}
.control-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.btn-add-cctv {
    background: #4f46e5;
    color: #ffffff;
    border: none;
    padding: 9px 18px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: 0.2s;
}
.btn-add-cctv:hover { background: #4338ca; }

.filter-form {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.filter-input {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    font-size: 13px;
    outline: none;
    background: #ffffff;
}
.filter-input:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.15);
}

/* TABLE SECTION */
.cctv-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
}
.table-responsive {
    overflow-x: auto;
    width: 100%;
}
.cctv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
    text-align: left;
}
.cctv-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11.5px;
    letter-spacing: 0.5px;
    padding: 12px 16px;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}
.cctv-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}
.cctv-table tr:hover td {
    background: #f8fafc;
}

/* Row Highlight when error */
.cctv-table tr.row-error td {
    background: #fffdfd;
}
.cctv-table tr.row-error:hover td {
    background: #fef2f2;
}
.cctv-table tr.row-maint td {
    background: #fffdf7;
}

/* SVR Badge */
.svr-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    background: #eef2ff;
    color: #4338ca;
    border: 1px solid #c7d2fe;
}
.svr-tag.smp { background: #e0e7ff; color: #3730a3; border-color: #c7d2fe; }
.svr-tag.sma { background: #fdf2f8; color: #9d174d; border-color: #fbcfe8; }
.svr-tag.sd  { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.svr-tag.masjid { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }

/* Status Badges */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}
.status-pill.normal { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.status-pill.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; animation: pulseRed 2s infinite; }
.status-pill.maintenance { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

@keyframes pulseRed {
    0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
    100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
}

.live-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
}
.status-pill.normal .live-dot { background: #10b981; }
.status-pill.error .live-dot { background: #ef4444; }
.status-pill.maintenance .live-dot { background: #f59e0b; }

/* Error Detail Callout in Table Cell */
.error-detail-box {
    margin-top: 6px;
    background: #fef2f2;
    border-left: 3px solid #ef4444;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 12px;
    color: #991b1b;
}
.error-detail-box strong {
    display: block;
    font-weight: 700;
    color: #b91c1c;
}
.maint-detail-box {
    margin-top: 6px;
    background: #fffbeb;
    border-left: 3px solid #f59e0b;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 12px;
    color: #92400e;
}

/* Order Pill & Reorder Buttons */
.order-badge-box {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.order-num-pill {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: #f1f5f9;
    color: #334155;
    font-weight: 800;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #cbd5e1;
}
.reorder-btns {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.btn-reorder {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #475569;
    width: 20px;
    height: 18px;
    border-radius: 4px;
    font-size: 9px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    transition: 0.15s;
}
.btn-reorder:hover {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
}

/* Action Buttons */
.action-btn-group {
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-tbl-action {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-tbl-action:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.btn-tbl-action.mark-status {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #bfdbfe;
}
.btn-tbl-action.mark-status:hover {
    background: #dbeafe;
    color: #1e40af;
}
.btn-tbl-action.delete:hover {
    background: #fef2f2;
    color: #dc2626;
    border-color: #fca5a5;
}

/* MODAL STYLES */
.custom-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    z-index: 2500;
    justify-content: center;
    align-items: center;
    padding: 20px;
    overflow-y: auto;
}
.custom-modal-box {
    background: #ffffff;
    border-radius: 16px;
    width: 100%;
    max-width: 580px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    overflow: hidden;
}
.modal-head {
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8fafc;
}
.modal-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-close-btn {
    background: transparent;
    border: none;
    font-size: 20px;
    color: #94a3b8;
    cursor: pointer;
    line-height: 1;
}
.modal-close-btn:hover { color: #0f172a; }

.modal-body {
    padding: 22px 24px;
    overflow-y: auto;
}
.modal-foot {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    background: #f8fafc;
}

.form-group {
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
}
.form-control {
    width: 100%;
    padding: 9px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    font-size: 13.5px;
    background: #ffffff;
    box-sizing: border-box;
    outline: none;
}
.form-control:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.15);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
</style>

<div class="cctv-mgmt">

    <!-- Top Header -->
    <div class="page-header-row">
        <div class="page-title-box">
            <h1><i class="fa-solid fa-video" style="color: #4f46e5;"></i> Pendataan CCTV & Status Perbaikan</h1>
            <p>Pencatatan inventaris kamera CCTV sekolah (SVR SMP, SMA, SD, Ruangan & Lantai) dan penandaan status perbaikan.</p>
        </div>
    </div>

    <!-- JALUR HIK-CONNECT PORTAL WEB -->
    <div class="hik-banner-card">
        <div class="hik-banner-info">
            <div class="hik-logo-badge">
                <i class="fa-solid fa-tower-broadcast"></i>
            </div>
            <div class="hik-banner-text">
                <h2>Jalur Akses Langsung Hik-Connect Web</h2>
                <p>Jalur cepat untuk melihat tampilan live view kamera langsung di portal web <strong>hik-connect.com</strong> atau NVR lokal.</p>
            </div>
        </div>
        <div class="hik-banner-actions">
            <a href="<?= e($hikConnectUrl) ?>" target="_blank" class="btn-hik-connect" title="Buka Portal Hik-Connect di Tab Baru">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Hik-Connect Web
            </a>
            <button type="button" onclick="openHikSettingModal()" class="btn-setting-link" title="Ubah Link URL Portal">
                <i class="fa-solid fa-gear"></i> Atur Link
            </button>
        </div>
    </div>

    <!-- KPI Summary Cards (Bisa Diklik untuk Filter Cepat) -->
    <div class="kpi-grid">
        <a href="index.php" class="kpi-card total" title="Tampilkan Semua Kamera">
            <div class="kpi-icon"><i class="fa-solid fa-video"></i></div>
            <div class="kpi-info">
                <h3><?= number_format($totalCount) ?></h3>
                <span>Total Titik Kamera</span>
            </div>
        </a>
        <a href="index.php?status=normal" class="kpi-card normal" title="Filter Kamera Normal">
            <div class="kpi-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="kpi-info">
                <h3><?= number_format($normalCount) ?></h3>
                <span>Normal / Berfungsi</span>
            </div>
        </a>
        <a href="index.php?status=error" class="kpi-card error" title="Filter Kamera Error / Rusak">
            <div class="kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="kpi-info">
                <h3><?= number_format($errorCount) ?></h3>
                <span>Perlu Perbaikan / Rusak</span>
            </div>
        </a>
        <a href="index.php?status=maintenance" class="kpi-card maint" title="Filter Kamera Dalam Perbaikan">
            <div class="kpi-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
            <div class="kpi-info">
                <h3><?= number_format($maintCount) ?></h3>
                <span>Sedang Perbaikan</span>
            </div>
        </a>
    </div>

    <!-- TAB FILTER PER SVR / DVR (IVMS-4200 MATCHING) -->
    <div class="svr-tabs-scroll">
        <a href="index.php<?= !empty($filterStatus) ? '?status=' . urlencode($filterStatus) : '' ?>" class="svr-tab-btn <?= empty($filterDvr) ? 'active' : '' ?>">
            <i class="fa-solid fa-server"></i> Semua SVR/DVR
            <span class="svr-tab-badge"><?= $totalCount ?></span>
        </a>

        <?php foreach ($allDvrList as $dvr): 
            $isActive = ($filterDvr === $dvr);
            $cCount = $dvrCounts[$dvr] ?? 0;
            $tabUrl = 'index.php?dvr=' . urlencode($dvr) . (!empty($filterStatus) ? '&status=' . urlencode($filterStatus) : '');
        ?>
            <a href="<?= $tabUrl ?>" class="svr-tab-btn <?= $isActive ? 'active' : '' ?>">
                <i class="fa-solid fa-hard-drive"></i> <?= e($dvr) ?>
                <span class="svr-tab-badge"><?= $cCount ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- CONTROL BAR: TAMBAH DATA & FILTER LANTAI / RUANG -->
    <div class="control-bar">
        <div class="control-left">
            <button type="button" onclick="openCreateCctvModal('<?= e($filterDvr ?: 'DVR SMP') ?>', '<?= e($filterFloor ?: 'Lantai 1') ?>')" class="btn-add-cctv">
                <i class="fa-solid fa-plus"></i> Tambah Data Kamera
            </button>
        </div>

        <form method="GET" class="filter-form">
            <?php if (!empty($filterDvr)): ?>
                <input type="hidden" name="dvr" value="<?= e($filterDvr) ?>">
            <?php endif; ?>

            <!-- Filter Lantai -->
            <select name="floor" class="filter-input" onchange="this.form.submit()">
                <option value="">-- Semua Lantai --</option>
                <?php foreach ($allFloorList as $fl): ?>
                    <option value="<?= e($fl) ?>" <?= $filterFloor === $fl ? 'selected' : '' ?>>
                        🏢 <?= e($fl) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Filter Status -->
            <select name="status" class="filter-input" onchange="this.form.submit()">
                <option value="">-- Semua Status --</option>
                <option value="normal" <?= $filterStatus === 'normal' ? 'selected' : '' ?>>🟢 Normal / Berfungsi</option>
                <option value="error" <?= $filterStatus === 'error' ? 'selected' : '' ?>>🔴 Error / Perlu Perbaikan</option>
                <option value="maintenance" <?= $filterStatus === 'maintenance' ? 'selected' : '' ?>>🟡 Sedang Perbaikan</option>
            </select>

            <!-- Search Box -->
            <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="🔍 Cari ruang / no / nama..." class="filter-input" style="width: 200px;">
            <button type="submit" class="btn-tbl-action" style="padding: 8px 14px; font-weight:700;">Cari</button>

            <?php if ($filterFloor !== '' || $filterStatus !== '' || $searchQuery !== ''): ?>
                <a href="index.php<?= !empty($filterDvr) ? '?dvr=' . urlencode($filterDvr) : '' ?>" class="btn-tbl-action" style="padding: 8px 12px;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- TABEL DATA CCTV & STATUS PERBAIKAN -->
    <div class="cctv-card">
        <div class="table-responsive">
            <table class="cctv-table">
                <thead>
                    <tr>
                        <th style="width: 75px;">No / Urut</th>
                        <th style="width: 85px;">Channel</th>
                        <th style="width: 130px;">SVR / DVR</th>
                        <th>Ruangan & Posisi</th>
                        <th>Lantai</th>
                        <th style="min-width: 170px;">Kondisi & Status</th>
                        <th>Keterangan / Kendala</th>
                        <th style="width: 180px; text-align: center;">Tandai & Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cctvList)): ?>
                        <tr>
                            <td colspan="8">
                                <div style="text-align: center; padding: 45px 20px; color: #94a3b8;">
                                    <i class="fa-solid fa-video-slash" style="font-size: 42px; margin-bottom: 12px; color: #cbd5e1;"></i>
                                    <p style="font-size: 14px; margin: 0;">Tidak ada data kamera yang sesuai dengan filter ini.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cctvList as $cctv): 
                            $isError = ($cctv['status'] === 'error');
                            $isMaint = ($cctv['status'] === 'maintenance');
                            $rowClass = $isError ? 'row-error' : ($isMaint ? 'row-maint' : '');

                            // SVR Badge styling
                            $dvrClass = 'smp';
                            if (stripos($cctv['dvr_server'], 'SMA') !== false) $dvrClass = 'sma';
                            elseif (stripos($cctv['dvr_server'], 'SD') !== false) $dvrClass = 'sd';
                            elseif (stripos($cctv['dvr_server'], 'Masjid') !== false) $dvrClass = 'masjid';
                        ?>
                            <tr class="<?= $rowClass ?>">
                                <!-- Urutan & Move Up/Down -->
                                <td>
                                    <div class="order-badge-box">
                                        <span class="order-num-pill" title="Urutan Ke-<?= $cctv['sort_order'] ?>">
                                            <?= $cctv['sort_order'] ?>
                                        </span>
                                        <div class="reorder-btns">
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="action" value="move_order">
                                                <input type="hidden" name="cctv_id" value="<?= $cctv['id'] ?>">
                                                <input type="hidden" name="direction" value="up">
                                                <input type="hidden" name="return_query" value="<?= e($currentQueryString) ?>">
                                                <button type="submit" class="btn-reorder" title="Urutkan Naik">▲</button>
                                            </form>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="action" value="move_order">
                                                <input type="hidden" name="cctv_id" value="<?= $cctv['id'] ?>">
                                                <input type="hidden" name="direction" value="down">
                                                <input type="hidden" name="return_query" value="<?= e($currentQueryString) ?>">
                                                <button type="submit" class="btn-reorder" title="Urutkan Turun">▼</button>
                                            </form>
                                        </div>
                                    </div>
                                </td>

                                <!-- Channel -->
                                <td>
                                    <?php if (!empty($cctv['channel_number'])): ?>
                                        <span style="font-weight: 700; font-size: 13px; color: #1e293b; background: #f8fafc; padding: 3px 8px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                            Ch <?= str_pad($cctv['channel_number'], 2, '0', STR_PAD_LEFT) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- SVR / DVR -->
                                <td>
                                    <span class="svr-tag <?= $dvrClass ?>">
                                        <i class="fa-solid fa-server" style="font-size:10px;"></i>
                                        <?= e($cctv['dvr_server'] ?: 'DVR SMP') ?>
                                    </span>
                                </td>

                                <!-- Ruangan & Nama Kamera -->
                                <td>
                                    <div style="font-weight: 700; font-size: 14px; color: #0f172a;">
                                        <?= e($cctv['room_name'] ?: $cctv['name']) ?>
                                    </div>
                                    <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                        Label: <code><?= e($cctv['name']) ?></code>
                                        <?php if (!empty($cctv['ip_address'])): ?>
                                            &bull; IP: <?= e($cctv['ip_address']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Lantai -->
                                <td>
                                    <span style="font-size: 13px; font-weight: 600; color: #334155;">
                                        <i class="fa-solid fa-layer-group" style="color: #6366f1; font-size: 11px;"></i>
                                        <?= e($cctv['floor_name'] ?: 'Lantai 1') ?>
                                    </span>
                                </td>

                                <!-- Kondisi & Status -->
                                <td>
                                    <?php if ($cctv['status'] === 'normal'): ?>
                                        <span class="status-pill normal">
                                            <span class="live-dot"></span> Normal / Aktif
                                        </span>
                                    <?php elseif ($cctv['status'] === 'error'): ?>
                                        <span class="status-pill error">
                                            <span class="live-dot"></span> ⚠️ ERROR / RUSAK
                                        </span>
                                        <div class="error-detail-box">
                                            <strong><?= e($cctv['error_type'] ?: 'Perlu Perbaikan') ?></strong>
                                            <?php if (!empty($cctv['error_notes'])): ?>
                                                <span><?= e($cctv['error_notes']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($cctv['error_date'])): ?>
                                                <small style="display:block; margin-top:2px; opacity:0.8;">
                                                    Tgl: <?= date('d M Y H:i', strtotime($cctv['error_date'])) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($cctv['status'] === 'maintenance'): ?>
                                        <span class="status-pill maintenance">
                                            <span class="live-dot"></span> 🛠️ DALAM PERBAIKAN
                                        </span>
                                        <div class="maint-detail-box">
                                            <strong><?= e($cctv['error_type'] ?: 'Sedang Dikerjakan') ?></strong>
                                            <?php if (!empty($cctv['error_notes'])): ?>
                                                <span><?= e($cctv['error_notes']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($cctv['repair_pic'])): ?>
                                                <small style="display:block; margin-top:2px;">Teknisi: <?= e($cctv['repair_pic']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Keterangan / Catatan -->
                                <td>
                                    <span style="font-size: 12.5px; color: #475569;">
                                        <?= e($cctv['notes'] ?: '-') ?>
                                    </span>
                                </td>

                                <!-- Tandai & Aksi -->
                                <td>
                                    <div class="action-btn-group" style="justify-content: center;">
                                        <!-- Tombol Cepat Tandai Status Error/Normal -->
                                        <button type="button" 
                                                onclick="openQuickStatusModal(<?= htmlspecialchars(json_encode($cctv), ENT_QUOTES, 'UTF-8') ?>)" 
                                                class="btn-tbl-action mark-status" 
                                                title="Tandai jika ada error atau perbaikan">
                                            <i class="fa-solid fa-flag"></i> Tandai Status
                                        </button>

                                        <!-- Edit Data -->
                                        <button type="button" 
                                                onclick="openEditCctvModal(<?= htmlspecialchars(json_encode($cctv), ENT_QUOTES, 'UTF-8') ?>)" 
                                                class="btn-tbl-action" 
                                                title="Edit Ruang / Nomor">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>

                                        <!-- Hapus Data -->
                                        <form method="POST" onsubmit="return confirm('Hapus data kamera di \'<?= e($cctv['room_name'] ?: $cctv['name']) ?>\'?')" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_cctv">
                                            <input type="hidden" name="cctv_id" value="<?= $cctv['id'] ?>">
                                            <input type="hidden" name="return_query" value="<?= e($currentQueryString) ?>">
                                            <button type="submit" class="btn-tbl-action delete" title="Hapus Data">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ========================================================================= -->
<!-- MODAL 1: TANDAI STATUS DI WEB (NORMAL / ERROR / MAINTENANCE)              -->
<!-- ========================================================================= -->
<div class="custom-modal-overlay" id="quickStatusModal">
    <div class="custom-modal-box" style="max-width: 500px;">
        <form method="POST">
            <input type="hidden" name="action" value="update_camera_status">
            <input type="hidden" name="cctv_id" id="quickStatusCctvId" value="">
            <input type="hidden" name="return_query" value="<?= e($currentQueryString) ?>">

            <div class="modal-head">
                <h3><i class="fa-solid fa-flag" style="color: #ef4444;"></i> Tandai Kondisi Kamera</h3>
                <button type="button" class="modal-close-btn" onclick="closeModal('quickStatusModal')">&times;</button>
            </div>

            <div class="modal-body">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px;">
                    <div style="font-weight: 800; font-size: 14px; color: #0f172a;" id="quickStatusRoomTitle">Ruang Kelas 8B</div>
                    <div style="font-size: 12.5px; color: #64748b;" id="quickStatusSubTitle">DVR SMP &bull; Channel 04 &bull; Lantai 2</div>
                </div>

                <div class="form-group">
                    <label>Pilih Status Kamera Saat Ini <span style="color:red;">*</span></label>
                    <select name="status" id="quickStatusSelect" class="form-control" onchange="toggleErrorFields(this.value)">
                        <option value="normal">🟢 Normal / Berfungsi Baik (Sudah Diperbaiki)</option>
                        <option value="error">🔴 Error / Rusak (Perlu Perbaikan)</option>
                        <option value="maintenance">🟡 Sedang Dalam Perbaikan (Maintenance)</option>
                    </select>
                </div>

                <div id="errorDetailsGroup" style="display: none;">
                    <div class="form-group">
                        <label>Jenis Kerusakan / Kendala</label>
                        <select name="error_type" id="quickStatusErrorType" class="form-control">
                            <option value="Kamera Mati / No Video">Kamera Mati / Layar Hitam (No Video)</option>
                            <option value="Gambar Buram / Noise">Gambar Buram / Noise / Garis</option>
                            <option value="Kabel Putus / Konektor Kendor">Kabel Putus / Konektor Kendor</option>
                            <option value="Adaptor / Power Mati">Adaptor / Power Supply Mati</option>
                            <option value="Offline di NVR / Port Switch">Offline di NVR / Masalah Switch Port</option>
                            <option value="Sudut Pandang / Arah Bergeser">Sudut Pandang / Arah Kamera Bergeser</option>
                            <option value="Lainnya">Lainnya...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Catatan Detail Perbaikan / Lokasi Kendala</label>
                        <textarea name="error_notes" id="quickStatusErrorNotes" class="form-control" rows="2" placeholder="Contoh: Lampu indikator mati, perlu dicek adaptor di plafon..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Teknisi / Petugas yang Menangani (Opsional)</label>
                        <input type="text" name="repair_pic" id="quickStatusRepairPic" class="form-control" placeholder="Contoh: Pak Budi / Vendor IT">
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn-tbl-action" onclick="closeModal('quickStatusModal')">Batal</button>
                <button type="submit" class="btn-add-cctv" style="background:#ef4444;">Simpan Status</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: TAMBAH / EDIT DATA LENGKAP KAMERA CCTV                           -->
<!-- ========================================================================= -->
<div class="custom-modal-overlay" id="cctvModal">
    <div class="custom-modal-box">
        <form method="POST">
            <input type="hidden" name="action" id="cctvFormAction" value="create_cctv">
            <input type="hidden" name="cctv_id" id="modalCctvId" value="">
            <input type="hidden" name="return_query" value="<?= e($currentQueryString) ?>">

            <div class="modal-head">
                <h3 id="cctvModalTitle"><i class="fa-solid fa-video" style="color: #4f46e5;"></i> Tambah Data Kamera CCTV</h3>
                <button type="button" class="modal-close-btn" onclick="closeModal('cctvModal')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>SVR / DVR Server <span style="color:red;">*</span></label>
                        <input type="text" name="dvr_server" id="modalCctvDvr" class="form-control" list="dvrListOptions" placeholder="Pilih atau ketik SVR..." required>
                        <datalist id="dvrListOptions">
                            <?php foreach ($allDvrList as $dvr): ?>
                                <option value="<?= e($dvr) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label>Lantai <span style="color:red;">*</span></label>
                        <input type="text" name="floor_name" id="modalCctvFloor" class="form-control" list="floorListOptions" placeholder="Pilih / ketik lantai..." required>
                        <datalist id="floorListOptions">
                            <?php foreach ($allFloorList as $fl): ?>
                                <option value="<?= e($fl) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <div class="form-group">
                    <label>Nama Ruangan / Titik Posisi <span style="color:red;">*</span></label>
                    <input type="text" name="room_name" id="modalCctvRoom" class="form-control" placeholder="Contoh: Kelas 8B, Ruang ICT, Lobi Utama, Tangga Ikhwan..." required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>No. Channel DVR / NVR</label>
                        <input type="number" name="channel_number" id="modalCctvChannel" class="form-control" placeholder="1, 2, 3...">
                    </div>
                    <div class="form-group">
                        <label>Nomor Urut Tampil</label>
                        <input type="number" name="sort_order" id="modalCctvOrder" class="form-control" placeholder="1, 2, 3...">
                    </div>
                </div>

                <div class="form-group">
                    <label>Nama Label Kamera (Di IVMS-4200)</label>
                    <input type="text" name="name" id="modalCctvName" class="form-control" placeholder="Contoh: Kelas 8B_dvr smp (kosongkan untuk otomatis)">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Status Kondisi</label>
                        <select name="status" id="modalCctvStatus" class="form-control">
                            <option value="normal">🟢 Normal / Berfungsi</option>
                            <option value="error">🔴 Error / Rusak</option>
                            <option value="maintenance">🟡 Sedang Perbaikan</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>IP Address Kamera (Opsional)</label>
                        <input type="text" name="ip_address" id="modalCctvIp" class="form-control" placeholder="Contoh: 192.168.1.104">
                    </div>
                </div>

                <div class="form-group">
                    <label>Catatan Kerusakan (Jika Ada Perbaikan)</label>
                    <input type="text" name="error_notes" id="modalCctvErrorNotes" class="form-control" placeholder="Catatan jika sedang ada kendala...">
                </div>

                <div class="form-group">
                    <label>Catatan Tambahan</label>
                    <textarea name="notes" id="modalCctvNotes" class="form-control" rows="2" placeholder="Catatan teknis port, jenis kamera, adaptor, dll..."></textarea>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn-tbl-action" onclick="closeModal('cctvModal')">Batal</button>
                <button type="submit" class="btn-add-cctv">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: PENGATURAN LINK HIK-CONNECT WEB                                  -->
<!-- ========================================================================= -->
<div class="custom-modal-overlay" id="hikSettingModal">
    <div class="custom-modal-box" style="max-width: 500px;">
        <form method="POST">
            <input type="hidden" name="action" value="save_hikconnect_setting">

            <div class="modal-head">
                <h3><i class="fa-solid fa-gear" style="color: #ef4444;"></i> Pengaturan Link Hik-Connect</h3>
                <button type="button" class="modal-close-btn" onclick="closeModal('hikSettingModal')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-group">
                    <label>URL Portal Web Hik-Connect / NVR</label>
                    <input type="url" name="hikconnect_url" value="<?= e($hikConnectUrl) ?>" class="form-control" placeholder="https://www.hik-connect.com atau IP NVR..." required>
                    <small style="color:#64748b; font-size:12px; line-height:1.4; display:block; margin-top:6px;">
                        Default: <code>https://www.hik-connect.com</code>.<br>
                        Bisa juga diisi dengan IP web NVR/DVR sekolah (misal: <code>http://192.168.1.200</code>).
                    </small>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn-tbl-action" onclick="closeModal('hikSettingModal')">Batal</button>
                <button type="submit" class="btn-add-cctv" style="background:#ef4444;">Simpan Link</button>
            </div>
        </form>
    </div>
</div>

<!-- JAVASCRIPT LOGIC -->
<script>
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function toggleErrorFields(status) {
    const grp = document.getElementById('errorDetailsGroup');
    if (status === 'error' || status === 'maintenance') {
        grp.style.display = 'block';
    } else {
        grp.style.display = 'none';
    }
}

function openQuickStatusModal(cctv) {
    document.getElementById('quickStatusCctvId').value = cctv.id;
    document.getElementById('quickStatusRoomTitle').textContent = (cctv.room_name || cctv.name);
    document.getElementById('quickStatusSubTitle').textContent = 
        (cctv.dvr_server || 'DVR SMP') + ' • Channel ' + (cctv.channel_number || '-') + ' • ' + (cctv.floor_name || 'Lantai 1');

    document.getElementById('quickStatusSelect').value = cctv.status || 'normal';
    document.getElementById('quickStatusErrorType').value = cctv.error_type || 'Kamera Mati / No Video';
    document.getElementById('quickStatusErrorNotes').value = cctv.error_notes || '';
    document.getElementById('quickStatusRepairPic').value = cctv.repair_pic || '';

    toggleErrorFields(cctv.status || 'normal');
    document.getElementById('quickStatusModal').style.display = 'flex';
}

function openCreateCctvModal(defaultDvr = 'DVR SMP', defaultFloor = 'Lantai 1') {
    document.getElementById('cctvFormAction').value = 'create_cctv';
    document.getElementById('cctvModalTitle').innerHTML = '<i class="fa-solid fa-plus-circle" style="color: #4f46e5;"></i> Tambah Data Kamera CCTV';
    document.getElementById('modalCctvId').value = '';
    document.getElementById('modalCctvDvr').value = defaultDvr;
    document.getElementById('modalCctvFloor').value = defaultFloor;
    document.getElementById('modalCctvRoom').value = '';
    document.getElementById('modalCctvChannel').value = '';
    document.getElementById('modalCctvOrder').value = '';
    document.getElementById('modalCctvName').value = '';
    document.getElementById('modalCctvStatus').value = 'normal';
    document.getElementById('modalCctvIp').value = '';
    document.getElementById('modalCctvErrorNotes').value = '';
    document.getElementById('modalCctvNotes').value = '';

    document.getElementById('cctvModal').style.display = 'flex';
}

function openEditCctvModal(data) {
    document.getElementById('cctvFormAction').value = 'update_cctv';
    document.getElementById('cctvModalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color: #4f46e5;"></i> Edit Data Kamera CCTV';
    document.getElementById('modalCctvId').value = data.id;
    document.getElementById('modalCctvDvr').value = data.dvr_server || 'DVR SMP';
    document.getElementById('modalCctvFloor').value = data.floor_name || 'Lantai 1';
    document.getElementById('modalCctvRoom').value = data.room_name || '';
    document.getElementById('modalCctvChannel').value = data.channel_number || '';
    document.getElementById('modalCctvOrder').value = data.sort_order || '';
    document.getElementById('modalCctvName').value = data.name || '';
    document.getElementById('modalCctvStatus').value = data.status || 'normal';
    document.getElementById('modalCctvIp').value = data.ip_address || '';
    document.getElementById('modalCctvErrorNotes').value = data.error_notes || '';
    document.getElementById('modalCctvNotes').value = data.notes || '';

    document.getElementById('cctvModal').style.display = 'flex';
}

function openHikSettingModal() {
    document.getElementById('hikSettingModal').style.display = 'flex';
}

// Tutup modal jika klik di luar box
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('custom-modal-overlay')) {
        e.target.style.display = 'none';
    }
});
</script>

<?php require '../../includes/footer.php'; ?>
