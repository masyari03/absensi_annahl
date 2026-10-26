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

$userUnit = getUserUnitInfo($pdo, $currentUserId);
$userUnitId = $userUnit['unit_id'] ?? null;
$userUnitName = $userUnit['unit_name'] ?? null;
$isSuperAdmin = ($currentRole === 'super_admin');
$filterUnit = (int)($_GET['unit_id'] ?? 0);

// =========================================================================
// ACTION: EKSPOR EXCEL & BACKUP JSON
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=Rekap_Supervisi_Guru_' . date('Ymd_His') . '.xls');
    
    $expQuery = "
        SELECT ss.*, st.name as type_name, s.name as teacher_name, u.name as observer_name
        FROM supervision_sessions ss
        JOIN supervision_types st ON st.id = ss.type_id
        JOIN staff s ON s.id = ss.teacher_id
        JOIN users u ON u.id = ss.observer_id
        WHERE 1=1
    ";
    $expParams = [];
    if (!$isSuperAdmin && $userUnitId > 0) {
        $expQuery .= " AND s.unit_id = ?";
        $expParams[] = $userUnitId;
    } elseif ($isSuperAdmin && $filterUnit > 0) {
        $expQuery .= " AND s.unit_id = ?";
        $expParams[] = $filterUnit;
    }
    $expQuery .= " ORDER BY ss.observation_date DESC, ss.id DESC";
    $stmtExp = $pdo->prepare($expQuery);
    $stmtExp->execute($expParams);
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
// AJAX: TARIK DATA RANGKUMAN AKUMULASI GABUNGAN (SEJAWAT & KEPSEK)
// =========================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_cumulative_report') {
    header('Content-Type: application/json; charset=utf-8');
    $teacherId = (int)($_GET['teacher_id'] ?? 0);
    $data = getSupervisionCumulativeData($pdo, $teacherId);
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Data guru atau supervisi tidak ditemukan.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// =========================================================================
// STANDALONE VIEW: CETAK / LIHAT LEMBAR RANGKUMAN AKUMULASI RESMI
// =========================================================================
if (isset($_GET['print_cumulative'])) {
    $teacherId = (int)($_GET['teacher_id'] ?? 0);
    $data = getSupervisionCumulativeData($pdo, $teacherId);
    if (!$data) {
        die('Data guru atau hasil supervisi tidak ditemukan.');
    }
    $t = $data['teacher'];
    $peer = $data['peer'];
    $kepsek = $data['kepsek'];
    $cum = $data['cumulative'];
    $comps = $data['components'];
    $notes = $data['notes'];
    
    $logoDataUri = 'logo_sm.png';
    if (file_exists(__DIR__ . '/../logo_sm.png')) {
        $logoDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/../logo_sm.png'));
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Sistem Perekaman & Analisis Supervisi Guru - SMP An Nahl Islamic School</title>
        <style>
            @page {
                size: A4 portrait;
                margin: 10mm 14mm;
            }
            * {
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                color: #000000;
                margin: 0;
                padding: 0;
                background: #ffffff;
                line-height: 1.35;
                font-size: 11px;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .page-container {
                max-width: 800px;
                margin: 0 auto;
                padding: 0;
            }
            .header-box {
                display: flex;
                align-items: center;
                margin-bottom: 4px;
            }
            .logo-img {
                width: 66px;
                height: 66px;
                object-fit: contain;
                margin-right: 14px;
            }
            .header-center {
                flex: 1;
                text-align: center;
            }
            .school-name {
                margin: 0;
                font-size: 16px;
                font-weight: 800;
                color: #1D4ED8;
                letter-spacing: 0.5px;
                text-transform: uppercase;
            }
            .school-address {
                margin: 2px 0;
                font-size: 9.5px;
                color: #4B5563;
            }
            .header-divider-thin {
                border-bottom: 1px solid #E5E7EB;
                margin: 4px 0;
            }
            .doc-title {
                margin: 0;
                font-size: 13.5px;
                font-weight: 800;
                color: #000000;
                text-transform: uppercase;
                letter-spacing: 0.2px;
            }
            .doc-subtitle {
                margin: 2px 0 0 0;
                font-size: 10.5px;
                font-weight: 600;
                color: #1E40AF;
            }
            .header-accent-bar {
                height: 3px;
                background: #1E3A8A;
                margin: 6px 0 10px 0;
            }
            .meta-table {
                width: 100%;
                margin-bottom: 8px;
                font-size: 11px;
                border-collapse: collapse;
            }
            .meta-table td {
                padding: 2px 0;
                vertical-align: top;
            }
            .section-header {
                background: #E2E8F0;
                border-left: 4px solid #1E3A8A;
                padding: 3.5px 10px;
                font-size: 11px;
                font-weight: 800;
                color: #000000;
                text-transform: uppercase;
                margin: 8px 0 5px 0;
            }
            .table-data {
                width: 100%;
                border-collapse: collapse;
                font-size: 10.5px;
                margin-bottom: 4px;
            }
            .table-data th, .table-data td {
                border: 1px solid #000000;
                padding: 4.5px 8px;
            }
            .table-data th {
                background: #F8FAFC;
                font-weight: bold;
                text-align: center;
            }
            .badge-predicate {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 6px;
                font-weight: 800;
                font-size: 10.5px;
                background: #DBEAFE;
                border: 1px solid #93C5FD;
                color: #1E40AF;
            }
            .components-box {
                border: 1px solid #E2E8F0;
                border-radius: 6px;
                padding: 8px 12px;
                margin-bottom: 4px;
                background: #FFFFFF;
            }
            .comp-row {
                display: flex;
                align-items: center;
                margin-bottom: 5px;
                font-size: 10px;
            }
            .comp-row:last-child {
                margin-bottom: 0;
            }
            .comp-label {
                width: 28%;
                font-weight: bold;
                color: #000000;
            }
            .comp-track {
                flex: 1;
                height: 14px;
                background: #F1F5F9;
                border: 1px solid #E2E8F0;
                border-radius: 9999px;
                overflow: hidden;
                margin: 0 10px;
                position: relative;
            }
            .comp-bar {
                height: 100%;
                border-radius: 9999px;
                display: flex;
                align-items: center;
                justify-content: flex-end;
                padding-right: 6px;
                color: #FFFFFF;
                font-weight: 800;
                font-size: 9px;
                min-width: 24px;
            }
            .comp-val {
                width: 55px;
                text-align: right;
                font-weight: bold;
                color: #000000;
            }
            .notes-container {
                border: 1px solid #E2E8F0;
                border-radius: 6px;
                padding: 8px 12px;
                font-size: 10px;
                line-height: 1.35;
                margin-bottom: 6px;
                background: #FFFFFF;
            }
            .note-group {
                margin-bottom: 6px;
            }
            .note-group:last-child {
                margin-bottom: 0;
            }
            .note-title {
                font-weight: 800;
                margin-bottom: 2px;
            }
            .note-item {
                margin: 2px 0 2px 14px;
                color: #1F2937;
            }
            .signatures-table {
                width: 100%;
                margin-top: 10px;
                border-collapse: collapse;
                font-size: 10.5px;
                text-align: center;
                border-top: 1px solid #E5E7EB;
                padding-top: 6px;
            }
            .signatures-table td {
                width: 33.33%;
                vertical-align: top;
                padding: 2px 6px;
            }
            .sign-space {
                height: 44px;
            }
            .sign-name {
                font-weight: bold;
                text-decoration: underline;
            }
        </style>
    </head>
    <body <?= isset($_GET['autoprint']) ? 'onload="window.print()"' : '' ?>>
    <!-- TOP TOOLBAR FOR SCREEN ONLY -->
    <div style="position: sticky; top: 0; background: #1e293b; color: #ffffff; padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);" class="no-print-toolbar">
        <div style="font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <span>Lembar Rangkuman Akumulasi Supervisi Akademik Gabungan &bull; SMP An Nahl</span>
        </div>
        <div style="display: flex; gap: 10px;">
            <button onclick="window.print()" style="background: #4338ca; color: #ffffff; padding: 7px 16px; font-size: 12.5px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 6px;">
                <span>🖨️ Cetak / Simpan PDF</span>
            </button>
            <button onclick="window.close()" style="background: #475569; color: #ffffff; padding: 7px 16px; font-size: 12.5px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 6px;">
                <span>✕ Tutup Halaman</span>
            </button>
        </div>
    </div>
    <div class="page-container" style="margin-top: 20px;">
        <!-- KOP SURAT -->
        <div class="header-box">
            <img class="logo-img" src="<?= $logoDataUri ?>" alt="Logo">
            <div class="header-center">
                <h1 class="school-name">SMP AN NAHL ISLAMIC SCHOOL</h1>
                <p class="school-address">Jl. Raya Ciangsana No. 88, Gunung Putri, Kab. Bogor • Telp. (021) 8493-2288 • NPSN: 69978900</p>
                <div class="header-divider-thin"></div>
                <h2 class="doc-title">LEMBAR RANGKUMAN AKUMULASI SUPERVISI AKADEMIK GABUNGAN</h2>
                <p class="doc-subtitle">Integrasi Penilaian Kepala Sekolah & Teman Sejawat Berbasis Pembelajaran Mendalam & KURMAA</p>
            </div>
        </div>
        <div class="header-accent-bar"></div>

        <!-- METADATA GURU -->
        <table class="meta-table">
            <tr>
                <td style="width: 18%; font-weight: bold;">Nama Guru:</td>
                <td style="width: 32%; font-weight: bold;"><?= htmlspecialchars($cum['teacher_name']) ?></td>
                <td style="width: 22%; font-weight: bold;">Kepala Sekolah:</td>
                <td style="width: 28%; font-weight: bold;"><?= htmlspecialchars($cum['principal_name']) ?></td>
            </tr>
            <tr>
                <td style="font-weight: bold;">Mata Pelajaran:</td>
                <td><?= htmlspecialchars($cum['subject_name']) ?> (<?= htmlspecialchars($cum['class_name']) ?>)</td>
                <td style="font-weight: bold;">Guru Observer Sejawat:</td>
                <td><?= htmlspecialchars($cum['peer_observer_name']) ?></td>
            </tr>
            <tr>
                <td style="font-weight: bold;">Tanggal Pelaksanaan:</td>
                <td><?= htmlspecialchars(formatIndoDate($cum['observation_date'])) ?></td>
                <td style="font-weight: bold;">Tahun Pelajaran:</td>
                <td><?= htmlspecialchars($cum['academic_year']) ?></td>
            </tr>
        </table>

        <!-- BAGIAN 1: REKAPITULASI & PERBANDINGAN NILAI -->
        <div class="section-header">1. REKAPITULASI & PERBANDINGAN NILAI SUPERVISI</div>
        <table class="table-data">
            <thead>
                <tr>
                    <th style="text-align: left; width: 34%;">Kategori Supervisi</th>
                    <th style="width: 16%;">Skor Capaian</th>
                    <th style="width: 16%;">Skor Maksimal</th>
                    <th style="width: 16%;">Persentase</th>
                    <th style="width: 18%;">Predikat Mutu</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="font-weight: bold; color: #1E40AF;">Supervisi Teman Sejawat</td>
                    <td style="text-align: center; font-weight: bold;"><?= $peer ? number_format($peer['score'], 0) : '-' ?></td>
                    <td style="text-align: center;"><?= $peer ? number_format($peer['max_score'], 0) : '88' ?></td>
                    <td style="text-align: center; font-weight: bold;"><?= $peer ? number_format($peer['percentage'], 2) . '%' : '-' ?></td>
                    <td style="text-align: center; font-weight: bold;"><?= $peer ? htmlspecialchars($peer['predicate']) : 'Belum Ada' ?></td>
                </tr>
                <tr>
                    <td style="font-weight: bold; color: #581C87;">Supervisi Kepala Sekolah</td>
                    <td style="text-align: center; font-weight: bold;"><?= $kepsek ? number_format($kepsek['score'], 0) : '-' ?></td>
                    <td style="text-align: center;"><?= $kepsek ? number_format($kepsek['max_score'], 0) : '96' ?></td>
                    <td style="text-align: center; font-weight: bold;"><?= $kepsek ? number_format($kepsek['percentage'], 2) . '%' : '-' ?></td>
                    <td style="text-align: center; font-weight: bold;"><?= $kepsek ? htmlspecialchars($kepsek['predicate']) : 'Belum Ada' ?></td>
                </tr>
                <tr style="background: #F0F9FF;">
                    <td style="font-weight: bold;">RERATA AKUMULASI GABUNGAN:</td>
                    <td style="text-align: center; font-weight: bold; color: #1D4ED8;">Nilai Terpadu</td>
                    <td style="text-align: center; color: #64748B;"><?= number_format($cum['max_score'], 0) ?></td>
                    <td style="text-align: center; font-weight: bold; color: #1D4ED8; font-size: 11.5px;"><?= number_format($cum['avg_percentage'], 1) ?>%</td>
                    <td style="text-align: center;">
                        <span class="badge-predicate"><?= htmlspecialchars($cum['predicate']) ?></span>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- BAGIAN 2: DIAGRAM KOMPARASI CAPAIAN KOMPONEN PEMBELAJARAN -->
        <div class="section-header">2. DIAGRAM KOMPARASI CAPAIAN KOMPONEN PEMBELAJARAN</div>
        <div class="components-box">
            <?php foreach ($comps as $c): ?>
                <div class="comp-row">
                    <div class="comp-label"><?= htmlspecialchars($c['name']) ?></div>
                    <div class="comp-track">
                        <div class="comp-bar" style="width: <?= max(4, min(100, $c['percentage'])) ?>%; background: <?= $c['color'] ?>;">
                            <?= number_format($c['percentage'], 1) ?>%
                        </div>
                    </div>
                    <div class="comp-val"><?= number_format($c['score'], 0) ?>/<?= number_format($c['max_score'], 0) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- BAGIAN 3: CATATAN ANALISIS, AREA PENINGKATAN & TINDAK LANJUT -->
        <div class="section-header">3. CATATAN ANALISIS, AREA PENINGKATAN & TINDAK LANJUT</div>
        <div class="notes-container">
            <div class="note-group">
                <div class="note-title" style="color: #047857;">🌟 Hal yang Baik / Praktik Unggul:</div>
                <?php if (empty($notes['good'])): ?>
                    <div class="note-item" style="font-style: italic; color: #6B7280;">- Belum ada catatan hal baik khusus.</div>
                <?php else: ?>
                    <?php foreach ($notes['good'] as $ng): ?>
                        <div class="note-item">• <?= htmlspecialchars($ng) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="note-group" style="margin-top: 6px;">
                <div class="note-title" style="color: #C2410C;">📈 Area yang Perlu Ditingkatkan:</div>
                <?php if (empty($notes['growth'])): ?>
                    <div class="note-item" style="font-style: italic; color: #6B7280;">- Pertahankan dan tingkatkan konsistensi pembelajaran.</div>
                <?php else: ?>
                    <?php foreach ($notes['growth'] as $ngw): ?>
                        <div class="note-item">• <?= htmlspecialchars($ngw) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="note-group" style="margin-top: 6px;">
                <div class="note-title" style="color: #1D4ED8;">🎯 Tindak Lanjut & Rekomendasi:</div>
                <?php if (empty($notes['recommendations'])): ?>
                    <div class="note-item" style="font-style: italic; color: #6B7280;">- Belum ada rekomendasi lanjutan khusus.</div>
                <?php else: ?>
                    <?php foreach ($notes['recommendations'] as $nr): ?>
                        <div class="note-item">• <?= htmlspecialchars($nr) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- TANDA TANGAN 3 PIHAK -->
        <table class="signatures-table">
            <tr>
                <td>
                    Guru yang Disupervisi,
                    <div class="sign-space"></div>
                    <div class="sign-name"><?= htmlspecialchars($cum['teacher_name']) ?></div>
                </td>
                <td>
                    Guru Observer Sejawat,
                    <div class="sign-space"></div>
                    <div class="sign-name"><?= htmlspecialchars($cum['peer_observer_name']) ?></div>
                </td>
                <td>
                    Mengetahui,<br>Kepala Sekolah SMP An Nahl,
                    <div class="sign-space" style="height: 30px;"></div>
                    <div class="sign-name"><?= htmlspecialchars($cum['principal_name']) ?></div>
                </td>
            </tr>
        </table>
    </div>
    </body>
    </html>
    <?php
    exit;
}


// =========================================================================
// STANDALONE VIEW: CETAK / LIHAT LEMBAR HASIL EVALUASI SUPERVISI (HALAMAN BARU)
// =========================================================================
if (isset($_GET['print_session'])) {
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
        die('Dokumen evaluasi supervisi tidak ditemukan.');
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

    $logoDataUri = 'logo_sm.png';
    if (file_exists(__DIR__ . '/../logo_sm.png')) {
        $logoDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/../logo_sm.png'));
    }

    $badgeColor = '#2563eb';
    if ($session['predicate_name'] === 'Outstanding') $badgeColor = '#1d4ed8';
    elseif ($session['predicate_name'] === 'Very Good' || $session['predicate_name'] === 'Excellent') $badgeColor = '#059669';
    elseif ($session['predicate_name'] === 'Good') $badgeColor = '#0d9488';
    elseif ($session['predicate_name'] === 'Fair') $badgeColor = '#d97706';
    elseif ($session['predicate_name'] === 'Need Improvement') $badgeColor = '#dc2626';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Lembar Hasil Evaluasi & Supervisi Pembelajaran - <?= htmlspecialchars($session['teacher_name']) ?></title>
        <style>
            @page {
                size: A4 portrait;
                margin: 12mm 14mm;
            }
            * {
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                color: #0f172a;
                margin: 0;
                padding: 0;
                background: #f1f5f9;
                line-height: 1.4;
                font-size: 11px;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            @media screen {
                .top-toolbar {
                    position: sticky;
                    top: 0;
                    background: #1e293b;
                    color: #ffffff;
                    padding: 10px 20px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    z-index: 1000;
                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                }
                .toolbar-title {
                    font-weight: 700;
                    font-size: 14px;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .toolbar-actions {
                    display: flex;
                    gap: 10px;
                }
                .btn-action {
                    padding: 7px 16px;
                    font-size: 12.5px;
                    font-weight: 700;
                    border-radius: 8px;
                    cursor: pointer;
                    border: none;
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    text-decoration: none;
                }
                .btn-primary {
                    background: #4f46e5;
                    color: #ffffff;
                }
                .btn-primary:hover {
                    background: #4338ca;
                }
                .btn-secondary {
                    background: #475569;
                    color: #ffffff;
                }
                .btn-secondary:hover {
                    background: #334155;
                }
                .doc-wrapper {
                    max-width: 800px;
                    margin: 24px auto 40px auto;
                    background: #ffffff;
                    padding: 24px 28px;
                    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
                    border-radius: 8px;
                }
            }
            @media print {
            .no-print-toolbar { display: none !important; }

                .top-toolbar {
                    display: none !important;
                }
                body {
                    background: #ffffff;
                }
                .doc-wrapper {
                    max-width: 100%;
                    margin: 0;
                    padding: 0;
                    box-shadow: none;
                    border-radius: 0;
                }
                tr {
                    page-break-inside: avoid;
                }
                .signature-block {
                    page-break-inside: avoid;
                }
                .notes-block {
                    page-break-inside: avoid;
                }
            }

            /* KOP SURAT */
            .header-box {
                display: flex;
                align-items: center;
                margin-bottom: 4px;
            }
            .logo-img {
                width: 66px;
                height: 66px;
                object-fit: contain;
                margin-right: 14px;
            }
            .header-center {
                flex: 1;
                text-align: center;
            }
            .school-name {
                margin: 0;
                font-size: 16px;
                font-weight: 800;
                color: #1D4ED8;
                letter-spacing: 0.5px;
                text-transform: uppercase;
            }
            .school-address {
                margin: 2px 0;
                font-size: 9.5px;
                color: #4B5563;
            }
            .header-divider-thin {
                border-bottom: 1px solid #E5E7EB;
                margin: 4px 0;
            }
            .doc-title {
                margin: 0;
                font-size: 13.5px;
                font-weight: 800;
                color: #000000;
                text-transform: uppercase;
                letter-spacing: 0.2px;
            }
            .doc-subtitle {
                margin: 2px 0 0 0;
                font-size: 10.5px;
                font-weight: 600;
                color: #1E40AF;
            }
            .header-accent-bar {
                height: 3px;
                background: #1E3A8A;
                margin: 6px 0 12px 0;
            }

            /* METADATA GRID */
            .meta-card {
                background: #F8FAFC;
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                padding: 10px 14px;
                margin-bottom: 14px;
            }
            .meta-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
            }
            .meta-table td {
                padding: 3px 0;
                vertical-align: top;
            }

            /* KPI SUMMARY CARDS */
            .kpi-grid {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 10px;
                margin-bottom: 14px;
            }
            .kpi-card {
                background: #FFFFFF;
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                padding: 10px 14px;
                text-align: center;
            }
            .kpi-label {
                font-size: 10px;
                font-weight: 700;
                color: #64748B;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 2px;
            }
            .kpi-value {
                font-size: 18px;
                font-weight: 800;
                color: #0F172A;
            }
            .kpi-badge {
                display: inline-block;
                padding: 3px 12px;
                border-radius: 20px;
                font-weight: 800;
                font-size: 12px;
                background: <?= $badgeColor ?>15;
                color: <?= $badgeColor ?>;
                border: 1px solid <?= $badgeColor ?>40;
            }

            /* SECTION HEADER */
            .section-header {
                background: #E2E8F0;
                border-left: 4px solid #1E3A8A;
                padding: 5px 10px;
                font-size: 11px;
                font-weight: 800;
                color: #000000;
                text-transform: uppercase;
                margin: 14px 0 6px 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            /* TABLE */
            .rubric-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10.5px;
                margin-bottom: 8px;
            }
            .rubric-table th {
                background: #F1F5F9;
                border: 1px solid #CBD5E1;
                padding: 6px 8px;
                font-weight: 700;
                color: #334155;
                text-transform: uppercase;
                font-size: 10px;
            }
            .rubric-table td {
                border: 1px solid #E2E8F0;
                padding: 6px 8px;
                vertical-align: top;
            }
            .rubric-table tr:nth-child(even) {
                background: #F8FAFC;
            }
            .category-row td {
                background: #F1F5F9 !important;
                font-weight: 800;
                color: #1E3A8A;
                border-top: 1.5px solid #CBD5E1;
                border-bottom: 1.5px solid #CBD5E1;
                padding: 6px 8px;
            }
            .score-pill {
                display: inline-block;
                width: 24px;
                height: 24px;
                line-height: 24px;
                text-align: center;
                border-radius: 50%;
                background: #4F46E5;
                color: #FFFFFF;
                font-weight: 800;
                font-size: 11px;
            }

            /* NOTES CONTAINER */
            .notes-card {
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                padding: 10px 14px;
                font-size: 10.5px;
                line-height: 1.4;
                background: #FFFFFF;
                margin-bottom: 16px;
            }
            .note-group {
                margin-bottom: 8px;
            }
            .note-group:last-child {
                margin-bottom: 0;
            }
            .note-title {
                font-weight: 800;
                margin-bottom: 2px;
                font-size: 11px;
            }
            .note-content {
                margin: 2px 0 0 14px;
                color: #1E293B;
            }

            /* SIGNATURES */
            .signatures-table {
                width: 100%;
                margin-top: 18px;
                border-collapse: collapse;
                font-size: 11px;
                text-align: center;
            }
            .signatures-table td {
                width: 33.33%;
                vertical-align: top;
                padding: 4px 8px;
            }
            .sign-space {
                height: 50px;
            }
            .sign-name {
                font-weight: 800;
                text-decoration: underline;
            }
        </style>
    </head>
    <body <?= isset($_GET['autoprint']) ? 'onload="window.print()"' : '' ?>>
        <!-- TOP TOOLBAR FOR SCREEN ONLY -->
        <div class="top-toolbar">
            <div class="toolbar-title">
                <span>Raport Hasil Supervisi Pembelajaran Guru &bull; SMP An Nahl Islamic School</span>
            </div>
            <div class="toolbar-actions">
                <button class="btn-action btn-primary" onclick="window.print()">
                    <span>🖨️ Cetak / Simpan PDF</span>
                </button>
                <button class="btn-action btn-secondary" onclick="window.close()">
                    <span>✕ Tutup Halaman</span>
                </button>
            </div>
        </div>

        <div class="doc-wrapper">
            <!-- KOP SURAT RESMI -->
            <div class="header-box">
                <img class="logo-img" src="<?= $logoDataUri ?>" alt="Logo AIS">
                <div class="header-center">
                    <h1 class="school-name">SMP AN NAHL ISLAMIC SCHOOL</h1>
                    <p class="school-address">Jl. Raya Ciangsana No. 88, Gunung Putri, Kab. Bogor • Telp. (021) 8493-2288 • NPSN: 69978900</p>
                    <div class="header-divider-thin"></div>
                    <h2 class="doc-title">LEMBAR HASIL EVALUASI & SUPERVISI PEMBELAJARAN</h2>
                    <p class="doc-subtitle">Instrumen Penilaian Berbasis Pembelajaran Mendalam & Integrasi KURMAA</p>
                </div>
            </div>
            <div class="header-accent-bar"></div>

            <?php if ($session['status'] === 'draft'): ?>
                <div style="background: #fef3c7; border: 1.5px dashed #f59e0b; color: #b45309; padding: 10px 16px; border-radius: 8px; margin-bottom: 14px; text-align: center; font-weight: 800; font-size: 13px;">
                    ⚠️ DOKUMEN DRAF SEMENTARA (BELUM DIFINALISASI) &bull; SKOR & CATATAN MASIH DAPAT DILANJUTKAN / DIUBAH
                </div>
            <?php endif; ?>

            <!-- METADATA GRID -->
            <div class="meta-card">
                <table class="meta-table">
                    <tr>
                        <td style="width: 18%; font-weight: 700; color: #475569;">Nama Guru:</td>
                        <td style="width: 34%; font-weight: 800; color: #0F172A;"><?= htmlspecialchars($session['teacher_name']) ?></td>
                        <td style="width: 20%; font-weight: 700; color: #475569;">Jenis Supervisi:</td>
                        <td style="width: 28%; font-weight: 800; color: #4338CA;"><?= htmlspecialchars($session['type_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: 700; color: #475569;">Mata Pelajaran:</td>
                        <td><?= htmlspecialchars($session['subject_name']) ?> (<?= htmlspecialchars($session['class_name']) ?>)</td>
                        <td style="font-weight: 700; color: #475569;">Guru Observer:</td>
                        <td style="font-weight: 700;"><?= htmlspecialchars($session['observer_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: 700; color: #475569;">Ruang / Lokasi:</td>
                        <td><?= htmlspecialchars($session['room_location'] ?: 'Ruang Kelas ' . $session['class_name']) ?></td>
                        <td style="font-weight: 700; color: #475569;">Tanggal Pelaksanaan:</td>
                        <td><?= htmlspecialchars(formatIndoDate($session['observation_date'])) ?></td>
                    </tr>
                </table>
            </div>

            <!-- KPI SUMMARY CARDS -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Total Capaian Skor</div>
                    <div class="kpi-value"><?= number_format($session['total_score'], 0) ?> <span style="font-size: 12px; color: #64748B; font-weight: 600;">/ <?= number_format($session['max_possible_score'], 0) ?></span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Persentase Mutu</div>
                    <div class="kpi-value" style="color: #2563EB;"><?= number_format($session['percentage_score'], 2) ?>%</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Predikat Evaluasi</div>
                    <div style="margin-top: 4px;"><span class="kpi-badge"><?= htmlspecialchars($session['predicate_name']) ?></span></div>
                </div>
            </div>

            <!-- TABEL RUBRIK EVALUASI -->
            <div class="section-header">
                <span>I. MATRIKS PENILAIAN INDIKATOR PEMBELAJARAN</span>
            </div>
            <table class="rubric-table">
                <thead>
                    <tr>
                        <th style="width: 5%; text-align: center;">No</th>
                        <th style="width: 24%; text-align: left;">Aspek / Kode</th>
                        <th style="width: 58%; text-align: left;">Indikator Penilaian Pembelajaran</th>
                        <th style="width: 13%; text-align: center;">Skor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $globalNo = 1;
                    foreach ($categoriesDetail as $cat): 
                    ?>
                        <tr class="category-row">
                            <td colspan="3"><?= htmlspecialchars($cat['category_name']) ?></td>
                            <td style="text-align: center;"><?= number_format($cat['subtotal_score'], 0) ?> / <?= number_format($cat['subtotal_max'], 0) ?></td>
                        </tr>
                        <?php foreach ($cat['items'] as $it): ?>
                            <tr>
                                <td style="text-align: center;"><?= $globalNo++ ?></td>
                                <td><strong><?= htmlspecialchars($it['aspek_label'] ?: $it['code'] ?: '-') ?></strong></td>
                                <td><?= htmlspecialchars($it['indicator_text']) ?></td>
                                <td style="text-align: center;"><span class="score-pill"><?= round($it['score']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- CATATAN KUALITATIF -->
            <div class="section-header">
                <span>II. CATATAN KUALITATIF & REKOMENDASI TINDAK LANJUT</span>
            </div>
            <div class="notes-card">
                <div class="note-group">
                    <div class="note-title" style="color: #047857;">🌟 A. Apresiasi & Praktik Baik yang Ditemukan:</div>
                    <div class="note-content">
                        <?= !empty($session['notes_good_practice']) ? '&bull; ' . nl2br(htmlspecialchars($session['notes_good_practice'])) : '<span style="font-style: italic; color: #64748b;">- Tidak ada catatan khusus.</span>' ?>
                    </div>
                </div>
                <div class="note-group" style="margin-top: 8px;">
                    <div class="note-title" style="color: #C2410C;">📈 B. Teacher Growth Plan & Area Peningkatan:</div>
                    <div class="note-content">
                        <?= !empty($session['notes_growth_plan']) ? '&bull; ' . nl2br(htmlspecialchars($session['notes_growth_plan'])) : '<span style="font-style: italic; color: #64748b;">- Pertahankan capaian dan konsistensi pembelajaran yang sudah baik.</span>' ?>
                    </div>
                </div>
                <div class="note-group" style="margin-top: 8px;">
                    <div class="note-title" style="color: #1D4ED8;">💬 C. Refleksi Guru Terobservasi:</div>
                    <div class="note-content">
                        <?= !empty($session['notes_teacher_reflection']) ? '&bull; ' . nl2br(htmlspecialchars($session['notes_teacher_reflection'])) : '<span style="font-style: italic; color: #64748b;">- Refleksi pembelajaran terlaksana secara positif.</span>' ?>
                    </div>
                </div>
            </div>

            <!-- TANDA TANGAN -->
            <table class="signatures-table">
                <tr>
                    <td>
                        Guru yang Dinilai,
                        <div class="sign-space"></div>
                        <div class="sign-name"><?= htmlspecialchars($session['signed_teacher_name'] ?: $session['teacher_name']) ?></div>
                    </td>
                    <td>
                        Guru Observer,
                        <div class="sign-space"></div>
                        <div class="sign-name"><?= htmlspecialchars($session['signed_observer_name'] ?: $session['observer_name']) ?></div>
                    </td>
                    <td>
                        Mengetahui,<br>Kepala Sekolah SMP An Nahl,
                        <div class="sign-space" style="height: 36px;"></div>
                        <div class="sign-name"><?= htmlspecialchars($session['signed_principal_name'] ?: 'Awang Darmawan, SS') ?></div>
                    </td>
                </tr>
            </table>
        </div>
    </body>
    </html>
    <?php
    exit;
}



// =========================================================================
// QUERY DATA TAMPILAN
// =========================================================================
$search = trim($_GET['search'] ?? '');
$filterPred = trim($_GET['predicate'] ?? '');
$filterType = (int)($_GET['type_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');

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

if (!$isSuperAdmin && $userUnitId > 0) {
    $query .= " AND s.unit_id = ?";
    $params[] = $userUnitId;
} elseif ($isSuperAdmin && $filterUnit > 0) {
    $query .= " AND s.unit_id = ?";
    $params[] = $filterUnit;
}

if (!empty($filterPred)) {
    $query .= " AND ss.predicate_name = ?";
    $params[] = $filterPred;
}

if (!empty($filterStatus)) {
    $query .= " AND ss.status = ?";
    $params[] = $filterStatus;
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
$unitsList = $pdo->query("SELECT id, unit FROM units WHERE id IN (1, 2, 3, 7) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$teachersList = getSupervisionTeachers($pdo, $userUnitId);

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
                <span style="color: #94a3b8; font-size: 13px;">&bull; <?= $userUnitName ? 'Unit ' . htmlspecialchars($userUnitName) : 'Seluruh Unit Sekolah' ?> &bull; An Nahl Islamic School</span>
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
                        <!-- TOMBOL RANGKUMAN AKUMULASI GABUNGAN -->
            <button type="button" class="btn btn-primary" onclick="openCumulativeReportModal(92)" style="background: #4338ca; border-color: #4338ca; color: white; display: inline-flex; align-items: center; gap: 6px; font-weight: 700; padding: 9px 16px; border-radius: 8px; box-shadow: 0 2px 8px rgba(67, 56, 202, 0.25);">
                <i class="fa-solid fa-award"></i> Rangkuman Akumulasi Gabungan
            </button>
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

            <div style="width: 180px;">
                <select name="type_id" class="form-control" onchange="this.form.submit()">
                    <option value="0">-- Semua Jenis --</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($filterType == $t['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="width: 160px;">
                <select name="status" class="form-control" onchange="this.form.submit()">
                    <option value="">-- Semua Status --</option>
                    <option value="completed" <?= ($filterStatus === 'completed') ? 'selected' : '' ?>>Hanya Selesai</option>
                    <option value="draft" <?= ($filterStatus === 'draft') ? 'selected' : '' ?>>Hanya Draf</option>
                </select>
            </div>

            <?php if ($isSuperAdmin): ?>
                <div style="width: 170px;">
                    <select name="unit_id" class="form-control" onchange="this.form.submit()">
                        <option value="0">-- Semua Unit --</option>
                        <?php foreach ($unitsList as $un): ?>
                            <option value="<?= $un['id'] ?>" <?= ($filterUnit == $un['id']) ? 'selected' : '' ?>>
                                Unit <?= htmlspecialchars($un['unit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

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
                                    <?= htmlspecialchars(formatIndoDate($s['observation_date'])) ?>
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
                                    <?php if ($s['status'] === 'draft'): ?>
                                        <span style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-weight: 800; font-size: 11.5px; padding: 4px 10px; border-radius: 20px; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-clock-rotate-left"></i> DRAF
                                        </span>
                                    <?php else: ?>
                                        <span style="background: <?= $badgeColor ?>15; color: <?= $badgeColor ?>; border: 1px solid <?= $badgeColor ?>40; font-weight: 800; font-size: 12px; padding: 4px 12px; border-radius: 20px; display: inline-block;">
                                            <?= htmlspecialchars($s['predicate_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 14px 18px; text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px;">
                                        <?php 
                                        $editUrl = ($s['type_key'] === 'peer') ? "peer.php?session_id={$s['id']}" : "principal.php?session_id={$s['id']}";
                                        ?>
                                        <?php if ($s['status'] === 'draft'): ?>
                                            <!-- TOMBOL LANJUTKAN PENILAIAN DRAF -->
                                            <a href="<?= $editUrl ?>" class="btn btn-sm btn-warning" title="Lanjutkan Pengisian Nilai Supervisi" style="background: #f59e0b; border-color: #f59e0b; color: white; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: 6px; text-decoration: none;">
                                                <i class="fa-solid fa-pen-to-square"></i> Lanjutkan
                                            </a>
                                        <?php else: ?>
                                            <!-- TOMBOL EDIT DOKUMEN -->
                                            <a href="<?= $editUrl ?>" class="btn btn-sm btn-secondary" title="Ubah / Edit Dokumen Supervisi" style="font-weight: 600; display: inline-flex; align-items: center; gap: 4px; padding: 5px 8px; border-radius: 6px; text-decoration: none;">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>
                                            <!-- TOMBOL LEMBAR RANGKUMAN AKUMULASI GABUNGAN -->
                                            <button type="button" class="btn btn-sm" onclick="openCumulativeReportModal(<?= $s['teacher_id'] ?>)" title="Lembar Rangkuman Akumulasi Supervisi Gabungan (Sejawat & Kepsek)" style="background: #e0e7ff; color: #4338ca; font-weight: 700; border: 1px solid #c7d2fe; display: inline-flex; align-items: center; gap: 4px; padding: 5px 9px; border-radius: 6px;">
                                                <i class="fa-solid fa-award"></i> Akumulasi
                                            </button>
                                        <?php endif; ?>

                                        <!-- TOMBOL CETAK PDF RESMI (BUKA TAB BARU STANDALONE) -->
                                        <a href="reports.php?print_session=1&session_id=<?= $s['id'] ?>" target="_blank" class="btn btn-sm btn-secondary" title="Buka Raport Resmi di Halaman Baru (Cetak PDF)" style="display: inline-flex; align-items: center; gap: 4px; font-weight: 600; text-decoration: none; padding: 5px 9px; border-radius: 6px;">
                                            <i class="fa-solid fa-file-lines"></i> Cetak PDF
                                        </a>

                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data supervisi ini?');">
                                            <input type="hidden" name="action" value="delete_session">
                                            <input type="hidden" name="session_id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus Dokumen" style="padding: 5px 8px; border-radius: 6px;">
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
<!-- MODAL: LEMBAR RANGKUMAN AKUMULASI SUPERVISI GABUNGAN (A4 PRINT PREVIEW) -->
<!-- ========================================================================= -->
<div id="modalPrintCumulativeReport" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); z-index: 9999; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div style="background: white; border-radius: 16px; max-width: 900px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); display: flex; flex-direction: column; max-height: 94vh;">
        <!-- MODAL HEADER -->
        <div style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border-radius: 16px 16px 0 0; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 10px; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                    <i class="fa-solid fa-award"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                        Lembar Rangkuman Akumulasi Supervisi Akademik Gabungan
                    </h3>
                    <p style="margin: 2px 0 0; color: #64748b; font-size: 12px;">
                        Integrasi Penilaian Kepala Sekolah & Teman Sejawat Berbasis Pembelajaran Mendalam & KURMAA
                    </p>
                </div>
            </div>

            <!-- ACTIONS & TEACHER SELECTOR -->
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <label style="font-size: 12px; font-weight: 700; color: #475569;">Pilih Guru:</label>
                    <select id="selCumulativeTeacher" class="form-control form-control-sm" style="width: 220px; font-size: 12.5px; font-weight: 600;" onchange="loadCumulativeReport(this.value)">
                        <?php if (!empty($teachersList)): ?>
                            <?php foreach ($teachersList as $tl): ?>
                                <option value="<?= $tl['id'] ?>" <?= ($tl['id'] == 92) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tl['name']) ?> (<?= htmlspecialchars($tl['unit_name'] ?? 'SMP') ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="92">Nita Sulistiyawati, S.Hum (SMP)</option>
                        <?php endif; ?>
                    </select>
                </div>

                <button type="button" class="btn btn-primary" onclick="printCumulativeReportDocument()" style="background: #4338ca; border-color: #4338ca; display: inline-flex; align-items: center; gap: 6px; font-weight: 700; padding: 7px 14px; border-radius: 8px;">
                    <i class="fa-solid fa-print"></i> Cetak / Simpan PDF
                </button>
                <button type="button" onclick="closeModal('modalPrintCumulativeReport')" style="background: none; border: none; font-size: 20px; color: #94a3b8; cursor: pointer; padding: 4px 8px;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- MODAL BODY (PRINTABLE DOCUMENT AREA MATCHING REFERENCE) -->
        <div style="padding: 24px; overflow-y: auto; background: #f1f5f9; display: flex; justify-content: center;">
            <div id="printableCumulativeReportArea" style="background: white; width: 100%; max-width: 780px; padding: 25px 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-radius: 6px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #000; font-size: 11px; line-height: 1.35;">
                <!-- KOP SURAT -->
                <div style="display: flex; align-items: center; margin-bottom: 4px;">
                    <img src="<?= BASE_URL ?>/logo_sm.png" style="width: 66px; height: 66px; object-fit: contain; margin-right: 14px;" alt="Logo AIS">
                    <div style="flex: 1; text-align: center;">
                        <h1 style="margin: 0; font-size: 16px; font-weight: 800; color: #1D4ED8; letter-spacing: 0.5px; text-transform: uppercase;">
                            SMP AN NAHL ISLAMIC SCHOOL
                        </h1>
                        <p style="margin: 2px 0; font-size: 9.5px; color: #4B5563;">
                            Jl. Raya Ciangsana No. 88, Gunung Putri, Kab. Bogor • Telp. (021) 8493-2288 • NPSN: 69978900
                        </p>
                        <div style="border-bottom: 1px solid #E5E7EB; margin: 4px 0;"></div>
                        <h2 style="margin: 0; font-size: 13.5px; font-weight: 800; color: #000; text-transform: uppercase; letter-spacing: 0.2px;">
                            LEMBAR RANGKUMAN AKUMULASI SUPERVISI AKADEMIK GABUNGAN
                        </h2>
                        <p style="margin: 2px 0 0 0; font-size: 10.5px; font-weight: 600; color: #1E40AF;">
                            Integrasi Penilaian Kepala Sekolah & Teman Sejawat Berbasis Pembelajaran Mendalam & KURMAA
                        </p>
                    </div>
                </div>
                <div style="height: 3px; background: #1E3A8A; margin: 6px 0 10px 0;"></div>

                <!-- METADATA GURU -->
                <table style="width: 100%; margin-bottom: 8px; font-size: 11px; border-collapse: collapse;">
                    <tr>
                        <td style="width: 18%; font-weight: bold; padding: 2px 0;">Nama Guru:</td>
                        <td style="width: 32%; font-weight: bold; padding: 2px 0;" id="cumTeacherName">-</td>
                        <td style="width: 22%; font-weight: bold; padding: 2px 0;">Kepala Sekolah:</td>
                        <td style="width: 28%; font-weight: bold; padding: 2px 0;" id="cumPrincipal">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; padding: 2px 0;">Mata Pelajaran:</td>
                        <td id="cumSubject" style="padding: 2px 0;">-</td>
                        <td style="font-weight: bold; padding: 2px 0;">Guru Observer Sejawat:</td>
                        <td id="cumPeerObserver" style="padding: 2px 0;">-</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; padding: 2px 0;">Tanggal Pelaksanaan:</td>
                        <td id="cumDate" style="padding: 2px 0;">-</td>
                        <td style="font-weight: bold; padding: 2px 0;">Tahun Pelajaran:</td>
                        <td id="cumAcademicYear" style="padding: 2px 0;">2026/2027 • Semester Ganjil</td>
                    </tr>
                </table>

                <!-- BAGIAN 1: REKAPITULASI & PERBANDINGAN NILAI -->
                <div style="background: #E2E8F0; border-left: 4px solid #1E3A8A; padding: 3.5px 10px; font-size: 11px; font-weight: 800; color: #000; text-transform: uppercase; margin: 8px 0 5px 0;">
                    1. REKAPITULASI & PERBANDINGAN NILAI SUPERVISI
                </div>
                <table style="width: 100%; border-collapse: collapse; font-size: 10.5px; margin-bottom: 4px;">
                    <thead>
                        <tr style="background: #F8FAFC;">
                            <th style="border: 1px solid #000; padding: 4.5px 8px; text-align: left; width: 34%;">Kategori Supervisi</th>
                            <th style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; width: 16%;">Skor Capaian</th>
                            <th style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; width: 16%;">Skor Maksimal</th>
                            <th style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; width: 16%;">Persentase</th>
                            <th style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; width: 18%;">Predikat Mutu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; font-weight: bold; color: #1E40AF;">Supervisi Teman Sejawat</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; font-weight: bold;" id="cumPeerScore">-</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center;" id="cumPeerMax">88</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; font-weight: bold;" id="cumPeerPct">-</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; font-weight: bold;" id="cumPeerPred">-</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; font-weight: bold; color: #581C87;">Supervisi Kepala Sekolah</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; font-weight: bold;" id="cumKepsekScore">-</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center;" id="cumKepsekMax">96</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; font-weight: bold;" id="cumKepsekPct">-</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; font-weight: bold;" id="cumKepsekPred">-</td>
                        </tr>
                        <tr style="background: #F0F9FF;">
                            <td style="border: 1px solid #000; padding: 4.5px 8px; font-weight: bold;">RERATA AKUMULASI GABUNGAN:</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; font-weight: bold; color: #1D4ED8;">Nilai Terpadu</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; color: #64748B;" id="cumTotalMax">184</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center; font-weight: bold; color: #1D4ED8; font-size: 11.5px;" id="cumAvgPct">-</td>
                            <td style="border: 1px solid #000; padding: 4.5px 8px; text-align: center;">
                                <span class="badge-predicate" id="cumAvgPredBadge" style="display: inline-block; padding: 2px 10px; border-radius: 6px; font-weight: 800; font-size: 10.5px; background: #DBEAFE; border: 1px solid #93C5FD; color: #1E40AF;">-</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- BAGIAN 2: DIAGRAM KOMPARASI CAPAIAN KOMPONEN PEMBELAJARAN -->
                <div style="background: #E2E8F0; border-left: 4px solid #1E3A8A; padding: 3.5px 10px; font-size: 11px; font-weight: 800; color: #000; text-transform: uppercase; margin: 8px 0 5px 0;">
                    2. DIAGRAM KOMPARASI CAPAIAN KOMPONEN PEMBELAJARAN
                </div>
                <div id="cumComponentsContainer" style="border: 1px solid #E2E8F0; border-radius: 6px; padding: 8px 12px; margin-bottom: 4px; background: #FFFFFF;">
                    <!-- JS renders 7 components -->
                </div>

                <!-- BAGIAN 3: CATATAN ANALISIS, AREA PENINGKATAN & TINDAK LANJUT -->
                <div style="background: #E2E8F0; border-left: 4px solid #1E3A8A; padding: 3.5px 10px; font-size: 11px; font-weight: 800; color: #000; text-transform: uppercase; margin: 8px 0 5px 0;">
                    3. CATATAN ANALISIS, AREA PENINGKATAN & TINDAK LANJUT
                </div>
                <div style="border: 1px solid #E2E8F0; border-radius: 6px; padding: 8px 12px; font-size: 10px; line-height: 1.35; margin-bottom: 6px; background: #FFFFFF;">
                    <div style="margin-bottom: 6px;">
                        <div style="color: #047857; font-weight: 800; margin-bottom: 2px;">🌟 Hal yang Baik / Praktik Unggul:</div>
                        <div id="cumNotesGood"></div>
                    </div>
                    <div style="margin-top: 6px; margin-bottom: 6px;">
                        <div style="color: #C2410C; font-weight: 800; margin-bottom: 2px;">📈 Area yang Perlu Ditingkatkan:</div>
                        <div id="cumNotesGrowth"></div>
                    </div>
                    <div style="margin-top: 6px;">
                        <div style="color: #1D4ED8; font-weight: 800; margin-bottom: 2px;">🎯 Tindak Lanjut & Rekomendasi:</div>
                        <div id="cumNotesRecom"></div>
                    </div>
                </div>

                <!-- TANDA TANGAN 3 PIHAK -->
                <table style="width: 100%; margin-top: 10px; border-collapse: collapse; font-size: 10.5px; text-align: center; border-top: 1px solid #E5E7EB; padding-top: 6px;">
                    <tr>
                        <td style="width: 33.33%; vertical-align: top; padding: 2px 6px;">
                            Guru yang Disupervisi,
                            <div style="height: 44px;"></div>
                            <div style="font-weight: bold; text-decoration: underline;" id="cumSignTeacher">-</div>
                        </td>
                        <td style="width: 33.33%; vertical-align: top; padding: 2px 6px;">
                            Guru Observer Sejawat,
                            <div style="height: 44px;"></div>
                            <div style="font-weight: bold; text-decoration: underline;" id="cumSignPeerObserver">-</div>
                        </td>
                        <td style="width: 33.33%; vertical-align: top; padding: 2px 6px;">
                            Mengetahui,<br>Kepala Sekolah SMP An Nahl,
                            <div style="height: 30px;"></div>
                            <div style="font-weight: bold; text-decoration: underline;" id="cumSignPrincipal">-</div>
                        </td>
                    </tr>
                </table>
            </div>
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

let currentCumulativeTeacherId = 92;

function openCumulativeReportModal(teacherId) {
    if (!teacherId) teacherId = 92;
    currentCumulativeTeacherId = teacherId;
    const modal = document.getElementById('modalPrintCumulativeReport');
    if (modal) modal.style.display = 'flex';
    loadCumulativeReport(teacherId);
}

function loadCumulativeReport(teacherId) {
    currentCumulativeTeacherId = teacherId;
    const sel = document.getElementById('selCumulativeTeacher');
    if (sel) sel.value = teacherId;

    fetch(`reports.php?ajax=get_cumulative_report&teacher_id=${teacherId}`)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                alert(res.message || 'Data supervisi tidak ditemukan.');
                return;
            }
            const d = res.data;
            const cum = d.cumulative;
            const peer = d.peer;
            const kepsek = d.kepsek;

            // Metadata
            document.getElementById('cumTeacherName').textContent = cum.teacher_name;
            document.getElementById('cumPrincipal').textContent = cum.principal_name;
            document.getElementById('cumSubject').textContent = `${cum.subject_name} (${cum.class_name})`;
            document.getElementById('cumPeerObserver').textContent = cum.peer_observer_name;
            document.getElementById('cumDate').textContent = cum.observation_date;
            document.getElementById('cumAcademicYear').textContent = cum.academic_year;

            // Table
            document.getElementById('cumPeerScore').textContent = peer ? Math.round(peer.score) : '-';
            document.getElementById('cumPeerMax').textContent = peer ? Math.round(peer.max_score) : '88';
            document.getElementById('cumPeerPct').textContent = peer ? Number(peer.percentage).toFixed(2) + '%' : '-';
            document.getElementById('cumPeerPred').textContent = peer ? peer.predicate : 'Belum Ada';

            document.getElementById('cumKepsekScore').textContent = kepsek ? Math.round(kepsek.score) : '-';
            document.getElementById('cumKepsekMax').textContent = kepsek ? Math.round(kepsek.max_score) : '96';
            document.getElementById('cumKepsekPct').textContent = kepsek ? Number(kepsek.percentage).toFixed(2) + '%' : '-';
            document.getElementById('cumKepsekPred').textContent = kepsek ? kepsek.predicate : 'Belum Ada';

            document.getElementById('cumTotalMax').textContent = Math.round(cum.max_score);
            document.getElementById('cumAvgPct').textContent = Number(cum.avg_percentage).toFixed(1) + '%';
            document.getElementById('cumAvgPredBadge').textContent = cum.predicate;

            // Components
            let compHtml = '';
            d.components.forEach(c => {
                const pct = Number(c.percentage).toFixed(1);
                const widthPct = Math.max(4, Math.min(100, c.percentage));
                compHtml += `
                    <div style="display: flex; align-items: center; margin-bottom: 5px; font-size: 10px;">
                        <div style="width: 28%; font-weight: bold; color: #000;">${c.name}</div>
                        <div style="flex: 1; height: 14px; background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 9999px; overflow: hidden; margin: 0 10px; position: relative;">
                            <div style="width: ${widthPct}%; height: 100%; background: ${c.color}; border-radius: 9999px; display: flex; align-items: center; justify-content: flex-end; padding-right: 6px; color: #fff; font-weight: 800; font-size: 9px; min-width: 24px;">
                                ${pct}%
                            </div>
                        </div>
                        <div style="width: 55px; text-align: right; font-weight: bold; color: #000;">${Math.round(c.score)}/${Math.round(c.max_score)}</div>
                    </div>
                `;
            });
            document.getElementById('cumComponentsContainer').innerHTML = compHtml;

            // Notes
            const notes = d.notes;
            let goodHtml = '';
            if (notes.good && notes.good.length > 0) {
                notes.good.forEach(g => { goodHtml += `<div style="margin: 2px 0 2px 14px; color: #1F2937;">• ${escapeHtml(g)}</div>`; });
            } else {
                goodHtml = '<div style="margin: 2px 0 2px 14px; color: #6B7280; font-style: italic;">- Belum ada catatan khusus.</div>';
            }
            document.getElementById('cumNotesGood').innerHTML = goodHtml;

            let growthHtml = '';
            if (notes.growth && notes.growth.length > 0) {
                notes.growth.forEach(g => { growthHtml += `<div style="margin: 2px 0 2px 14px; color: #1F2937;">• ${escapeHtml(g)}</div>`; });
            } else {
                growthHtml = '<div style="margin: 2px 0 2px 14px; color: #6B7280; font-style: italic;">- Pertahankan capaian positif yang sudah berjalan.</div>';
            }
            document.getElementById('cumNotesGrowth').innerHTML = growthHtml;

            let recomHtml = '';
            if (notes.recommendations && notes.recommendations.length > 0) {
                notes.recommendations.forEach(r => { recomHtml += `<div style="margin: 2px 0 2px 14px; color: #1F2937;">• ${escapeHtml(r)}</div>`; });
            } else {
                recomHtml = '<div style="margin: 2px 0 2px 14px; color: #6B7280; font-style: italic;">- Diharapkan terus konsisten dalam penguatan pembelajaran bermakna.</div>';
            }
            document.getElementById('cumNotesRecom').innerHTML = recomHtml;

            // Signatures
            document.getElementById('cumSignTeacher').textContent = cum.teacher_name;
            document.getElementById('cumSignPeerObserver').textContent = cum.peer_observer_name;
            document.getElementById('cumSignPrincipal').textContent = cum.principal_name;

            document.getElementById('modalPrintCumulativeReport').style.display = 'flex';
        })
        .catch(err => {
            alert('Gagal memuat rangkuman akumulasi supervisi.');
        });
}

function printCumulativeReportDocument() {
    window.open(`reports.php?print_cumulative=1&autoprint=1&teacher_id=${currentCumulativeTeacherId}`, '_blank');
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}


function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

window.addEventListener('click', function(e) {
    const modal1 = document.getElementById('modalPrintCumulativeReport');
    if (modal1 && e.target === modal1) {
        modal1.style.display = 'none';
    }

});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
