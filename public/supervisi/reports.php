<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('supervisi_rekap');

$pageTitle = 'Rekapitulasi Data Supervisi Guru';
$currentUserId = currentUserId();
$currentRole = currentRole();

// =========================================================================
// ACTION: EKSPOR EXCEL & BACKUP JSON
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=Rekap_Supervisi_Guru_' . date('Ymd_His') . '.xls');
    
    $stmtExp = $pdo->query("
        SELECT ss.*, st.name as type_name, s.name as teacher_name, u.name as observer_name
        FROM supervision_sessions ss
        JOIN supervision_types st ON st.id = ss.type_id
        JOIN staff s ON s.id = ss.teacher_id
        JOIN users u ON u.id = ss.observer_id
        ORDER BY ss.observation_date DESC, ss.id DESC
    ");
    $expRows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1'>";
    echo "<tr style='background:#f1f5f9; font-weight:bold;'>
            <th>ID</th>
            <th>Jenis Supervisi</th>
            <th>Guru yang Dinilai</th>
            <th>Guru Observer</th>
            <th>Mata Pelajaran</th>
            <th>Kelas</th>
            <th>Tanggal Supervisi</th>
            <th>Skor Total</th>
            <th>Skor Maksimal</th>
            <th>Persentase (%)</th>
            <th>Predikat</th>
            <th>Praktik Baik</th>
            <th>Growth Plan</th>
            <th>Refleksi Guru</th>
          </tr>";
    foreach ($expRows as $r) {
        echo "<tr>
                <td>{$r['id']}</td>
                <td>" . htmlspecialchars($r['type_name']) . "</td>
                <td>" . htmlspecialchars($r['teacher_name']) . "</td>
                <td>" . htmlspecialchars($r['observer_name']) . "</td>
                <td>" . htmlspecialchars($r['subject_name']) . "</td>
                <td>" . htmlspecialchars($r['class_name']) . "</td>
                <td>{$r['observation_date']}</td>
                <td>{$r['total_score']}</td>
                <td>{$r['max_possible_score']}</td>
                <td>{$r['percentage_score']}%</td>
                <td>" . htmlspecialchars($r['predicate_name']) . "</td>
                <td>" . htmlspecialchars($r['notes_good_practice'] ?? '') . "</td>
                <td>" . htmlspecialchars($r['notes_growth_plan'] ?? '') . "</td>
                <td>" . htmlspecialchars($r['notes_teacher_reflection'] ?? '') . "</td>
              </tr>";
    }
    echo "</table>";
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=Backup_Supervisi_Guru_' . date('Ymd_His') . '.json');
    
    $stmtJson = $pdo->query("
        SELECT ss.*, st.name as type_name, s.name as teacher_name, u.name as observer_name
        FROM supervision_sessions ss
        JOIN supervision_types st ON st.id = ss.type_id
        JOIN staff s ON s.id = ss.teacher_id
        JOIN users u ON u.id = ss.observer_id
        ORDER BY ss.id DESC
    ");
    $sessionsData = $stmtJson->fetchAll(PDO::FETCH_ASSOC);

    // Ambil detail rincian skor per butir
    foreach ($sessionsData as &$sess) {
        $stmtSc = $pdo->prepare("
            SELECT sc.item_id, sc.score, sri.aspek_label, sri.indicator_text, sri.code
            FROM supervision_scores sc
            JOIN supervision_rubric_items sri ON sri.id = sc.item_id
            WHERE sc.session_id = ?
        ");
        $stmtSc->execute([$sess['id']]);
        $sess['scores_detail'] = $stmtSc->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['status' => 'success', 'data' => $sessionsData], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// ACTION: HAPUS DOKUMEN SUPERVISI
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_session') {
    $sessId = (int)($_POST['session_id'] ?? 0);
    if ($sessId > 0) {
        $pdo->prepare("DELETE FROM supervision_sessions WHERE id = ?")->execute([$sessId]);
        flash('success', 'Dokumen supervisi berhasil dihapus.');
    }
    redirect('reports.php');
}

// =========================================================================
// AJAX: TARIK DETAIL DOKUMEN UNTUK CETAK RAPORT PDF (A4)
// =========================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_report_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $sessId = (int)($_GET['session_id'] ?? 0);

    $stmtS = $pdo->prepare("
        SELECT ss.*, st.name as type_name, st.type_key,
               s.name as teacher_name, s.nik as teacher_nik,
               u.name as observer_name
        FROM supervision_sessions ss
        JOIN supervision_types st ON st.id = ss.type_id
        JOIN staff s ON s.id = ss.teacher_id
        JOIN users u ON u.id = ss.observer_id
        WHERE ss.id = ? LIMIT 1
    ");
    $stmtS->execute([$sessId]);
    $session = $stmtS->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Dokumen tidak ditemukan.']);
        exit;
    }

    // Ambil rincian kategori dan skor butir
    $stmtScores = $pdo->prepare("
        SELECT sc.score, sri.id as item_id, sri.code, sri.aspek_label, sri.indicator_text,
               sri.min_score, sri.max_score, sri.weight,
               cat.id as category_id, cat.category_name
        FROM supervision_scores sc
        JOIN supervision_rubric_items sri ON sri.id = sc.item_id
        JOIN supervision_categories cat ON cat.id = sri.category_id
        WHERE sc.session_id = ?
        ORDER BY cat.sort_order ASC, sri.sort_order ASC, sri.id ASC
    ");
    $stmtScores->execute([$sessId]);
    $scoreRows = $stmtScores->fetchAll(PDO::FETCH_ASSOC);

    // Grouping by category
    $categoriesDetail = [];
    foreach ($scoreRows as $sr) {
        $cId = $sr['category_id'];
        if (!isset($categoriesDetail[$cId])) {
            $categoriesDetail[$cId] = [
                'category_name' => $sr['category_name'],
                'subtotal_score' => 0,
                'subtotal_max' => 0,
                'items' => []
            ];
        }
        $categoriesDetail[$cId]['items'][] = $sr;
        $categoriesDetail[$cId]['subtotal_score'] += ($sr['score'] * $sr['weight']);
        $categoriesDetail[$cId]['subtotal_max'] += ($sr['max_score'] * $sr['weight']);
    }

    echo json_encode([
        'success' => true,
        'session' => $session,
        'categories' => array_values($categoriesDetail)
    ]);
    exit;
}

// =========================================================================
// QUERY DATA TAMPILAN
// =========================================================================
$search = trim($_GET['search'] ?? '');
$filterPred = trim($_GET['predicate'] ?? '');
$filterType = (int)($_GET['type_id'] ?? 0);

$query = "
    SELECT ss.*, 
           st.name as type_name, st.type_key,
           s.name as teacher_name, s.nik as teacher_nik,
           u.name as observer_name
    FROM supervision_sessions ss
    JOIN supervision_types st ON st.id = ss.type_id
    JOIN staff s ON s.id = ss.teacher_id
    JOIN users u ON u.id = ss.observer_id
    WHERE 1=1
";
$params = [];

if ($filterType > 0) {
    $query .= " AND ss.type_id = ?";
    $params[] = $filterType;
}

if (!empty($filterPred)) {
    $query .= " AND ss.predicate_name = ?";
    $params[] = $filterPred;
}

if (!empty($search)) {
    $query .= " AND (s.name LIKE ? OR u.name LIKE ? OR ss.subject_name LIKE ? OR ss.class_name LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$query .= " ORDER BY ss.observation_date DESC, ss.id DESC";

$stmtSessions = $pdo->prepare($query);
$stmtSessions->execute($params);
$sessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

// Predikat List & Types List
$predicates = $pdo->query("SELECT * FROM supervision_predicates ORDER BY min_percentage DESC")->fetchAll(PDO::FETCH_ASSOC);
$types = $pdo->query("SELECT * FROM supervision_types WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

function getPredicateColor($predName) {
    switch (strtolower($predName)) {
        case 'outstanding': return '#8b5cf6';
        case 'excellent': return '#10b981';
        case 'good': return '#0ea5e9';
        default: return '#ef4444';
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="supervisi-fullwidth" style="width: 100%; max-width: 100%;">
    <!-- HEADER -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <span style="background: #e0f2fe; color: #0369a1; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 6px; text-transform: uppercase;">
                    <i class="fa-solid fa-table-list"></i> Arsip Dokumen Evaluasi
                </span>
                <span style="color: #94a3b8; font-size: 13px;">&bull; SMP An Nahl Islamic School</span>
            </div>
            <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #0f172a;">
                Rekapitulasi Data Supervisi Guru
            </h1>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 14px;">
                Menampilkan <?= count($sessions) ?> dokumen hasil evaluasi supervisi pembelajaran guru tersimpan.
            </p>
        </div>

        <!-- TOMBOL AKSI ATAS SESUAI SCREENSHOT 5 -->
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="reports.php?export=excel" class="btn btn-success" style="background: #059669; border-color: #059669; color: white; display: inline-flex; align-items: center; gap: 6px; font-weight: 700; padding: 9px 16px; border-radius: 8px;">
                <i class="fa-solid fa-file-excel"></i> Ekspor ke Excel (.xlsx)
            </a>
            <a href="reports.php?export=json" class="btn btn-secondary" style="background: #1e293b; color: white; border-color: #1e293b; display: inline-flex; align-items: center; gap: 6px; font-weight: 700; padding: 9px 16px; border-radius: 8px;">
                <i class="fa-solid fa-database"></i> Backup JSON
            </a>
            <div style="position: relative; display: inline-block;">
                <button type="button" class="btn btn-primary" onclick="toggleAddDropdown()" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; padding: 9px 18px; border-radius: 8px;">
                    <i class="fa-solid fa-plus"></i> Tambah Supervisi <i class="fa-solid fa-chevron-down" style="font-size: 11px;"></i>
                </button>
                <div id="dropdownAddSupervisi" style="display: none; position: absolute; right: 0; top: 110%; background: white; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15); width: 220px; z-index: 100; overflow: hidden;">
                    <a href="peer.php" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: #1e293b; text-decoration: none; font-size: 13.5px; font-weight: 600; border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                        <i class="fa-solid fa-user-pen" style="color: #4f46e5; width: 16px;"></i> Supervisi Sejawat
                    </a>
                    <a href="principal.php" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: #1e293b; text-decoration: none; font-size: 13.5px; font-weight: 600; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                        <i class="fa-solid fa-school" style="color: #9333ea; width: 16px;"></i> Supervisi Kepsek
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTER PENCARIAN & PREDIKAT -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
        <form method="GET" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 280px; position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 11px; color: #94a3b8; font-size: 13px;"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama guru, observer, mapel..." class="form-control" style="padding-left: 36px;">
            </div>

            <div style="width: 220px;">
                <select name="predicate" class="form-control" onchange="this.form.submit()">
                    <option value="">-- Semua Predikat --</option>
                    <?php foreach ($predicates as $p): ?>
                        <option value="<?= htmlspecialchars($p['predicate_name']) ?>" <?= ($filterPred === $p['predicate_name']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['predicate_name']) ?> (&ge; <?= (float)$p['min_percentage'] ?>%)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="width: 200px;">
                <select name="type_id" class="form-control" onchange="this.form.submit()">
                    <option value="0">-- Semua Jenis --</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($filterType == $t['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if (!empty($search) || !empty($filterPred) || $filterType > 0): ?>
                <a href="reports.php" class="btn btn-secondary" title="Reset Filter">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- TABEL REKAPITULASI SESUAI SCREENSHOT 5 -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <?php if (empty($sessions)): ?>
            <div style="padding: 48px; text-align: center; color: #64748b;">
                <i class="fa-solid fa-folder-open" style="font-size: 40px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                Belum ada dokumen supervisi yang tersimpan. Silakan isi form supervisi sejawat atau kepala sekolah.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                            <th style="padding: 14px 18px;">NAMA GURU</th>
                            <th style="padding: 14px 18px;">MATA PELAJARAN</th>
                            <th style="padding: 14px 18px;">GURU OBSERVER</th>
                            <th style="padding: 14px 16px; width: 120px;">TANGGAL</th>
                            <th style="padding: 14px 16px; width: 140px; text-align: center;">SKOR TOTAL</th>
                            <th style="padding: 14px 16px; width: 130px; text-align: center;">PREDIKAT</th>
                            <th style="padding: 14px 18px; width: 220px; text-align: right;">AKSI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $s): 
                            $badgeColor = getPredicateColor($s['predicate_name']);
                        ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                <td style="padding: 14px 18px;">
                                    <div style="font-weight: 700; color: #0f172a; font-size: 14px;">
                                        <?= htmlspecialchars($s['teacher_name']) ?>
                                    </div>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                        <?= htmlspecialchars($s['type_name']) ?>
                                    </div>
                                </td>
                                <td style="padding: 14px 18px;">
                                    <div style="font-weight: 600; color: #1e293b;">
                                        <?= htmlspecialchars($s['subject_name']) ?>
                                    </div>
                                    <div style="font-size: 11.5px; color: #64748b;">
                                        (<?= htmlspecialchars($s['class_name']) ?>) &bull; Integrasi KURMAA
                                    </div>
                                </td>
                                <td style="padding: 14px 18px; color: #334155; font-weight: 500;">
                                    <?= htmlspecialchars($s['observer_name']) ?>
                                </td>
                                <td style="padding: 14px 16px; color: #64748b; font-size: 12.5px;">
                                    <?= htmlspecialchars($s['observation_date']) ?>
                                </td>
                                <td style="padding: 14px 16px; text-align: center;">
                                    <div style="font-weight: 800; font-size: 14.5px; color: #0f172a;">
                                        <?= number_format($s['total_score'], 0) ?><span style="color: #94a3b8; font-weight: 600; font-size: 12px;"> / <?= number_format($s['max_possible_score'], 0) ?></span>
                                    </div>
                                    <div style="font-size: 11px; font-weight: 700; color: #4f46e5;">
                                        <?= number_format($s['percentage_score'], 2) ?>%
                                    </div>
                                </td>
                                <td style="padding: 14px 16px; text-align: center;">
                                    <span style="background: <?= $badgeColor ?>15; color: <?= $badgeColor ?>; border: 1px solid <?= $badgeColor ?>40; font-weight: 800; font-size: 12px; padding: 4px 12px; border-radius: 20px; display: inline-block;">
                                        <?= htmlspecialchars($s['predicate_name']) ?>
                                    </span>
                                </td>
                                <td style="padding: 14px 18px; text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px;">
                                        <!-- TOMBOL CETAK PDF RESMI A4 -->
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="openPrintReportModal(<?= $s['id'] ?>)" title="Cetak Raport PDF Resmi" style="display: inline-flex; align-items: center; gap: 4px; font-weight: 600;">
                                            <i class="fa-solid fa-file-lines"></i> Cetak PDF
                                        </button>

                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data supervisi ini?');">
                                            <input type="hidden" name="action" value="delete_session">
                                            <input type="hidden" name="session_id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus Dokumen">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: CETAK RAPORT RESMI A4 (PRINT PREVIEW) -->
<!-- ========================================================================= -->
<div id="modalPrintReport" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 900px; max-height: 90vh; overflow-y: auto; padding: 0;">
        <div class="custom-modal-header" style="background: #1e293b; color: white; padding: 14px 20px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-print"></i>
                <h3 style="margin: 0; font-size: 16px; color: white;">Raport Hasil Supervisi Pembelajaran Guru (Format A4)</h3>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" class="btn btn-sm btn-primary" onclick="printReportDocument()" style="background: #4f46e5; border-color: #4f46e5;">
                    <i class="fa-solid fa-print"></i> Cetak / Simpan PDF
                </button>
                <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalPrintReport')">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- AREA CETAK DOKUMEN -->
        <div id="printableReportArea" style="padding: 30px; background: white; font-family: 'Times New Roman', Times, serif; color: #000000; line-height: 1.4;">
            <!-- KOP SURAT RESMI AN NAHL -->
            <div style="display: flex; align-items: center; border-bottom: 3px double #000; padding-bottom: 12px; margin-bottom: 18px;">
                <img src="<?= BASE_URL ?>/logo.jpg" alt="Logo" style="width: 75px; height: 75px; object-fit: contain; margin-right: 18px;">
                <div style="text-align: center; flex: 1;">
                    <h2 style="margin: 0; font-size: 18px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">
                        SMP AN NAHL ISLAMIC SCHOOL
                    </h2>
                    <h3 style="margin: 3px 0; font-size: 14px; font-weight: 700; text-transform: uppercase;">
                        LEMBAR HASIL EVALUASI & SUPERVISI PEMBELAJARAN
                    </h3>
                    <p style="margin: 0; font-size: 11px; font-style: italic;">
                        Jl. Raya Ciangsana No. 88, Gunung Putri, Bogor, Jawa Barat &bull; Integrasi Kurikulum KURMAA
                    </p>
                </div>
            </div>

            <!-- IDENTITAS DOKUMEN -->
            <table style="width: 100%; font-size: 12px; margin-bottom: 16px; border: none;">
                <tr>
                    <td style="width: 18%; font-weight: bold;">Nama Guru</td>
                    <td style="width: 32%;">: <span id="rptTeacherName">-</span></td>
                    <td style="width: 18%; font-weight: bold;">Hari / Tanggal</td>
                    <td style="width: 32%;">: <span id="rptObsDate">-</span></td>
                </tr>
                <tr>
                    <td style="font-weight: bold;">Mata Pelajaran</td>
                    <td>: <span id="rptSubjectName">-</span></td>
                    <td style="font-weight: bold;">Lokasi / Ruang</td>
                    <td>: <span id="rptRoom">-</span></td>
                </tr>
                <tr>
                    <td style="font-weight: bold;">Kelas</td>
                    <td>: <span id="rptClassName">-</span></td>
                    <td style="font-weight: bold;">Guru Observer</td>
                    <td>: <span id="rptObserverName">-</span></td>
                </tr>
                <tr>
                    <td style="font-weight: bold;">Jenis Supervisi</td>
                    <td colspan="3">: <strong id="rptTypeName">-</strong></td>
                </tr>
            </table>

            <!-- TABEL INSTRUMEN DAN SKOR -->
            <div id="rptTableContainer" style="margin-bottom: 20px;">
                <!-- Diisi via JavaScript get_report_detail -->
            </div>

            <!-- RINGKASAN CAPAIAN & PREDIKAT -->
            <div style="border: 2px solid #000; padding: 12px 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 13px; font-weight: bold;">TOTAL PEROLEHAN SKOR:</div>
                    <div style="font-size: 20px; font-weight: bold;" id="rptTotalScoreDisplay">0 / 0 (0%)</div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 12px; font-weight: bold;">PREDIKAT EVALUASI:</div>
                    <div style="font-size: 18px; font-weight: bold; text-transform: uppercase;" id="rptPredicateDisplay">NEED IMPROVEMENT</div>
                </div>
            </div>

            <!-- CATATAN KUALITATIF & REKOMENDASI -->
            <div style="font-size: 12px; margin-bottom: 24px; border: 1px solid #999; padding: 12px;">
                <div style="margin-bottom: 10px;">
                    <strong>A. Apresiasi & Praktik Baik yang Ditemukan:</strong>
                    <div id="rptNotesGood" style="margin-top: 3px; font-style: italic; color: #222;">-</div>
                </div>
                <div style="margin-bottom: 10px;">
                    <strong>B. Teacher Growth Plan & Rekomendasi Tindak Lanjut:</strong>
                    <div id="rptNotesGrowth" style="margin-top: 3px; font-style: italic; color: #222;">-</div>
                </div>
                <div>
                    <strong>C. Refleksi Guru Terobservasi:</strong>
                    <div id="rptNotesReflection" style="margin-top: 3px; font-style: italic; color: #222;">-</div>
                </div>
            </div>

            <!-- BLOK TANDA TANGAN 3 KOLOM RESMI -->
            <table style="width: 100%; text-align: center; font-size: 12px; border: none; margin-top: 30px;">
                <tr>
                    <td style="width: 33%;">
                        Guru yang Dinilai,
                        <div style="height: 60px;"></div>
                        <strong id="rptSignTeacher">Nama Guru</strong>
                    </td>
                    <td style="width: 33%;">
                        Guru Observer,
                        <div style="height: 60px;"></div>
                        <strong id="rptSignObserver">Nama Observer</strong>
                    </td>
                    <td style="width: 33%;">
                        Mengetahui,<br>Kepala Sekolah
                        <div style="height: 60px;"></div>
                        <strong id="rptSignPrincipal">Awang Darmawan, SS</strong>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>

<script>
function toggleAddDropdown() {
    const dd = document.getElementById('dropdownAddSupervisi');
    dd.style.display = (dd.style.display === 'none' || dd.style.display === '') ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#dropdownAddSupervisi') && !e.target.closest('button[onclick="toggleAddDropdown()"]')) {
        const dd = document.getElementById('dropdownAddSupervisi');
        if (dd) dd.style.display = 'none';
    }
});

function openPrintReportModal(sessionId) {
    fetch('reports.php?ajax=get_report_detail&session_id=' + sessionId)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Gagal memuat detail raport.');
                return;
            }
            const s = data.session;
            document.getElementById('rptTeacherName').innerText = s.teacher_name;
            document.getElementById('rptObsDate').innerText = s.observation_date;
            document.getElementById('rptSubjectName').innerText = s.subject_name;
            document.getElementById('rptClassName').innerText = s.class_name;
            document.getElementById('rptRoom').innerText = s.room_location || '-';
            document.getElementById('rptObserverName').innerText = s.observer_name;
            document.getElementById('rptTypeName').innerText = s.type_name;

            document.getElementById('rptTotalScoreDisplay').innerText = Math.round(s.total_score) + ' / ' + Math.round(s.max_possible_score) + ' (' + parseFloat(s.percentage_score).toFixed(2) + '%)';
            document.getElementById('rptPredicateDisplay').innerText = s.predicate_name;

            document.getElementById('rptNotesGood').innerText = s.notes_good_practice || 'Tidak ada catatan khusus.';
            document.getElementById('rptNotesGrowth').innerText = s.notes_growth_plan || 'Pertahankan capaian yang ada.';
            document.getElementById('rptNotesReflection').innerText = s.notes_teacher_reflection || '-';

            document.getElementById('rptSignTeacher').innerText = s.signed_teacher_name || s.teacher_name;
            document.getElementById('rptSignObserver').innerText = s.signed_observer_name || s.observer_name;
            document.getElementById('rptSignPrincipal').innerText = s.signed_principal_name || 'Awang Darmawan, SS';

            // Render Tabel Butir Per Kategori
            let tableHtml = '<table style="width: 100%; border-collapse: collapse; font-size: 11px;" border="1">';
            tableHtml += '<tr style="background: #f1f5f9; text-align: center;">';
            tableHtml += '<th style="padding: 6px; width: 35px;">No</th>';
            tableHtml += '<th style="padding: 6px; width: 130px;">Aspek / Kode</th>';
            tableHtml += '<th style="padding: 6px;">Indikator Penilaian Pembelajaran</th>';
            tableHtml += '<th style="padding: 6px; width: 65px;">Skor (1-4)</th>';
            tableHtml += '</tr>';

            let globalNo = 1;
            data.categories.forEach(cat => {
                tableHtml += `<tr style="background: #e2e8f0; font-weight: bold;">
                    <td colspan="3" style="padding: 6px 8px;">${cat.category_name}</td>
                    <td style="padding: 6px; text-align: center;">${Math.round(cat.subtotal_score)} / ${Math.round(cat.subtotal_max)}</td>
                </tr>`;

                cat.items.forEach(it => {
                    tableHtml += `<tr>
                        <td style="padding: 4px 6px; text-align: center;">${globalNo++}</td>
                        <td style="padding: 4px 6px;"><strong>${it.aspek_label || it.code || '-'}</strong></td>
                        <td style="padding: 4px 6px;">${it.indicator_text}</td>
                        <td style="padding: 4px 6px; text-align: center; font-weight: bold;">${Math.round(it.score)}</td>
                    </tr>`;
                });
            });

            tableHtml += '</table>';
            document.getElementById('rptTableContainer').innerHTML = tableHtml;

            document.getElementById('modalPrintReport').style.display = 'flex';
        })
        .catch(err => {
            alert('Terjadi kesalahan saat memuat dokumen.');
        });
}

function printReportDocument() {
    const printContents = document.getElementById('printableReportArea').innerHTML;
    const printWindow = window.open('', '_blank', 'width=900,height=700');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Raport Supervisi - SMP An Nahl Islamic School</title>
            <style>
                @page { size: A4 portrait; margin: 15mm; }
                body { font-family: 'Times New Roman', Times, serif; color: #000; margin: 0; padding: 0; }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #000; padding: 5px; }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            ${printContents}
        </body>
        </html>
    `);
    printWindow.document.close();
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
