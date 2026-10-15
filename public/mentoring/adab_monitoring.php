<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Pemantauan Pembiasaan Adab & Refleksi Santri';
$currentRole = currentRole();
$userId = currentUserId();

// FILTER PARAMETERS
$filterClass = trim($_GET['class'] ?? '');
$filterWeek  = trim($_GET['week'] ?? '');
$filterDay   = trim($_GET['day'] ?? '');
$filterSearch = trim($_GET['search'] ?? '');

$where = ["1=1"];
$params = [];

if ($filterClass !== '') {
    $where[] = "sal.grade_name = ?";
    $params[] = $filterClass;
}
if ($filterWeek !== '') {
    $where[] = "sal.week_code = ?";
    $params[] = $filterWeek;
}
if ($filterDay !== '') {
    $where[] = "sal.day_name = ?";
    $params[] = $filterDay;
}
if ($filterSearch !== '') {
    $where[] = "(s.name LIKE ? OR s.nis LIKE ?)";
    $params[] = "%{$filterSearch}%";
    $params[] = "%{$filterSearch}%";
}

$whereSql = implode(' AND ', $where);

// FITUR EXPORT CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Rekap_Adab_Santri_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM agar rapi di Microsoft Excel
    fputs($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'No', 'Waktu Submit', 'NIS', 'Nama Santri', 'Kelas', 'Pekan', 'Hari',
        'Capaian Checklist', '1. Aksi Nyata', '2. Hambatan', '3. Solusi', '4. Rasa Syukur'
    ]);

    $stmtExp = $pdo->prepare("
        SELECT sal.*, s.name as student_name, s.nis
        FROM student_adab_logs sal
        JOIN students s ON s.id = sal.student_id
        WHERE {$whereSql}
        ORDER BY sal.submitted_at DESC
    ");
    $stmtExp->execute($params);

    $no = 1;
    while ($row = $stmtExp->fetch(PDO::FETCH_ASSOC)) {
        // Parse checklist count
        $checkedCount = 0;
        $totalItems = 4;
        if (!empty($row['checklist_json'])) {
            $dec = json_decode($row['checklist_json'], true);
            if (is_array($dec) && isset($dec[$row['day_name']]) && is_array($dec[$row['day_name']])) {
                foreach ($dec[$row['day_name']] as $chk) {
                    if ($chk) $checkedCount++;
                }
            }
        }

        fputcsv($output, [
            $no++,
            $row['submitted_at'],
            $row['nis'],
            $row['student_name'],
            $row['grade_name'],
            $row['week_code'],
            $row['day_name'],
            "{$checkedCount} / {$totalItems}",
            $row['reflection_action'],
            $row['reflection_obstacle'],
            $row['reflection_solution'],
            $row['reflection_gratitude']
        ]);
    }
    fclose($output);
    exit;
}

// TARIK DAFTAR KELAS (untuk dropdown)
$classesList = $pdo->query("
    SELECT DISTINCT grade_name FROM student_adab_logs 
    WHERE grade_name != '' 
    ORDER BY grade_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

// METRIK STATISTIK CEPAT
$stmtStats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_entries,
        COUNT(DISTINCT sal.student_id) as total_students_active,
        SUM(CASE WHEN sal.reflection_action IS NOT NULL AND sal.reflection_action != '' THEN 1 ELSE 0 END) as with_reflections
    FROM student_adab_logs sal
    JOIN students s ON s.id = sal.student_id
    WHERE {$whereSql}
");
$stmtStats->execute($params);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// PAGINATION
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

$totalRows = (int)$stats['total_entries'];
$totalPages = ceil($totalRows / $limit);

$stmtList = $pdo->prepare("
    SELECT sal.*, s.name as student_name, s.nis, s.parent_phone,
           mc.title as curriculum_title
    FROM student_adab_logs sal
    JOIN students s ON s.id = sal.student_id
    LEFT JOIN mentoring_curriculums mc ON mc.id = sal.curriculum_id
    WHERE {$whereSql}
    ORDER BY sal.submitted_at DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmtList->execute($params);
$adabLogs = $stmtList->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../../includes/header.php';
?>

<div class="content">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-heart-circle-check" style="color: #ec4899;"></i> Pemantauan Pembiasaan Adab & Refleksi
            </h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">
                Radar kepatuhan amalan adab harian dan rekapitulasi refleksi 4 dimensi santri
            </p>
        </div>

        <div style="display: flex; gap: 8px;">
            <?php
            $exportQuery = $_GET;
            $exportQuery['export'] = 'csv';
            $exportUrl = 'adab_monitoring.php?' . http_build_query($exportQuery);
            ?>
            <a href="<?= $exportUrl ?>" class="btn btn-success" style="padding: 9px 16px; font-size: 13px; font-weight: 700; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-file-excel"></i> Export Excel (CSV)
            </a>
        </div>
    </div>

    <!-- 3 Kartu Metrik -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="card" style="padding: 18px; border-radius: 14px; border-left: 4px solid #0284c7;">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Total Catatan Masuk</span>
            <div style="font-size: 28px; font-weight: 800; color: #0f172a; margin-top: 4px;">
                <?= number_format($stats['total_entries']) ?> <span style="font-size: 13px; font-weight: 600; color: #94a3b8;">Log</span>
            </div>
        </div>

        <div class="card" style="padding: 18px; border-radius: 14px; border-left: 4px solid #10b981;">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Santri Aktif Mengisi</span>
            <div style="font-size: 28px; font-weight: 800; color: #10b981; margin-top: 4px;">
                <?= number_format($stats['total_students_active']) ?> <span style="font-size: 13px; font-weight: 600; color: #94a3b8;">Santri</span>
            </div>
        </div>

        <div class="card" style="padding: 18px; border-radius: 14px; border-left: 4px solid #8b5cf6;">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Tingkat Kelengkapan Refleksi</span>
            <div style="font-size: 28px; font-weight: 800; color: #8b5cf6; margin-top: 4px;">
                <?= $stats['total_entries'] > 0 ? round(($stats['with_reflections'] / $stats['total_entries']) * 100, 1) : 0 ?>%
            </div>
        </div>
    </div>

    <!-- Filter Bar Card -->
    <div class="card" style="padding: 16px 20px; border-radius: 14px; margin-bottom: 20px;">
        <form method="GET" action="adab_monitoring.php" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <div style="flex: 1; min-width: 180px;">
                <input type="text" name="search" value="<?= e($filterSearch) ?>" placeholder="Cari santri / NIS..." class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
            </div>

            <div>
                <select name="class" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Semua Kelas --</option>
                    <?php foreach ($classesList as $c): ?>
                        <option value="<?= e($c) ?>" <?= $filterClass === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <select name="week" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Semua Pekan --</option>
                    <option value="Pekan 1" <?= $filterWeek === 'Pekan 1' ? 'selected' : '' ?>>Pekan 1</option>
                    <option value="Pekan 2" <?= $filterWeek === 'Pekan 2' ? 'selected' : '' ?>>Pekan 2</option>
                    <option value="Pekan 3" <?= $filterWeek === 'Pekan 3' ? 'selected' : '' ?>>Pekan 3</option>
                    <option value="Pekan 4" <?= $filterWeek === 'Pekan 4' ? 'selected' : '' ?>>Pekan 4</option>
                </select>
            </div>

            <div>
                <select name="day" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Semua Hari --</option>
                    <option value="Selasa" <?= $filterDay === 'Selasa' ? 'selected' : '' ?>>Selasa</option>
                    <option value="Rabu" <?= $filterDay === 'Rabu' ? 'selected' : '' ?>>Rabu</option>
                    <option value="Kamis" <?= $filterDay === 'Kamis' ? 'selected' : '' ?>>Kamis</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 8px 16px; font-size: 13px; font-weight: 700;">Filter</button>
            <?php if (!empty($filterClass) || !empty($filterWeek) || !empty($filterDay) || !empty($filterSearch)): ?>
                <a href="adab_monitoring.php" class="btn btn-secondary" style="padding: 8px 12px; font-size: 13px;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabel Data Kepatuhan & Refleksi -->
    <div class="card" style="padding: 20px; border-radius: 16px;">
        <div class="table-responsive">
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 10px;">Waktu Submit</th>
                        <th style="padding: 10px;">Santri</th>
                        <th style="padding: 10px;">Kelas</th>
                        <th style="padding: 10px;">Pekan & Hari</th>
                        <th style="padding: 10px; text-align: center;">Checklist</th>
                        <th style="padding: 10px;">Refleksi Karakter (Aksi & Solusi)</th>
                        <th style="padding: 10px; text-align: right;">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($adabLogs)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8;">
                                Tidak ada data pengisian adab santri yang sesuai filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($adabLogs as $log): ?>
                            <?php
                            // Hitung butir checklist tercentang
                            $checkedCount = 0;
                            $totalItems = 4;
                            if (!empty($log['checklist_json'])) {
                                $dec = json_decode($log['checklist_json'], true);
                                if (is_array($dec) && isset($dec[$log['day_name']]) && is_array($dec[$log['day_name']])) {
                                    foreach ($dec[$log['day_name']] as $chk) {
                                        if ($chk) $checkedCount++;
                                    }
                                }
                            }
                            ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                                <td style="padding: 10px; color: #64748b; font-size: 12px;">
                                    <?= date('d/m/Y H:i', strtotime($log['submitted_at'])) ?>
                                </td>
                                <td style="padding: 10px;">
                                    <strong><?= e($log['student_name']) ?></strong>
                                    <div style="font-size: 11px; color: #64748b;">NIS: <?= e($log['nis']) ?></div>
                                </td>
                                <td style="padding: 10px;">
                                    <span style="padding: 2px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 700; background: #e0f2fe; color: #0369a1;">
                                        <?= e($log['grade_name']) ?>
                                    </span>
                                </td>
                                <td style="padding: 10px;">
                                    <div style="font-weight: 700; color: #0f172a;"><?= e($log['week_code']) ?></div>
                                    <span style="font-size: 11.5px; color: #0284c7; font-weight: 600;"><?= e($log['day_name']) ?></span>
                                </td>
                                <td style="padding: 10px; text-align: center;">
                                    <span style="padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: 800; background: <?= $checkedCount >= 3 ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $checkedCount >= 3 ? '#065f46' : '#991b1b' ?>;">
                                        <?= $checkedCount ?> / <?= $totalItems ?>
                                    </span>
                                </td>
                                <td style="padding: 10px; max-width: 320px;">
                                    <?php if (!empty($log['reflection_action'])): ?>
                                        <div style="color: #334155; font-size: 12px; line-height: 1.4;">
                                            <strong style="color: #0284c7;">Aksi:</strong> <?= e(mb_strimwidth($log['reflection_action'], 0, 75, '...')) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($log['reflection_solution'])): ?>
                                        <div style="color: #475569; font-size: 11.5px; line-height: 1.4; margin-top: 2px;">
                                            <strong style="color: #10b981;">Solusi:</strong> <?= e(mb_strimwidth($log['reflection_solution'], 0, 75, '...')) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (empty($log['reflection_action']) && empty($log['reflection_solution'])): ?>
                                        <span style="color: #cbd5e1; font-style: italic; font-size: 12px;">Refleksi belum diisi</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; text-align: right;">
                                    <button type="button" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;" onclick="showReflectionModal(<?= htmlspecialchars(json_encode($log), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="fa-solid fa-eye"></i> Detail
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
                <span style="font-size: 12.5px; color: #64748b;">
                    Halaman <?= $page ?> dari <?= $totalPages ?> (Total <?= number_format($totalRows) ?> catatan)
                </span>
                <div style="display: flex; gap: 6px;">
                    <?php if ($page > 1): ?>
                        <?php $prevQ = $_GET; $prevQ['page'] = $page - 1; ?>
                        <a href="adab_monitoring.php?<?= http_build_query($prevQ) ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">Sebelumnya</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <?php $nextQ = $_GET; $nextQ['page'] = $page + 1; ?>
                        <a href="adab_monitoring.php?<?= http_build_query($nextQ) ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">Berikutnya</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL DETAIL REFLEKSI 4 DIMENSI -->
<div id="modalReflect" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: white; border-radius: 16px; width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
            <div>
                <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;">
                    <i class="fa-solid fa-heart-pulse" style="color: #ec4899;"></i> Detail Refleksi Santri
                </h3>
                <small style="color: #64748b;" id="mReflectSubtitle">-</small>
            </div>
            <button type="button" onclick="closeReflectionModal()" style="border: none; background: none; font-size: 20px; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>

        <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px;">
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px; padding: 12px;">
                <label style="display: block; font-size: 12px; font-weight: 800; color: #0284c7; text-transform: uppercase; margin-bottom: 4px;">
                    <i class="fa-solid fa-bullseye"></i> 1. Aksi Nyata yang Dilakukan
                </label>
                <div id="mReflectAction" style="font-size: 13px; color: #0f172a; line-height: 1.5;">-</div>
            </div>

            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px;">
                <label style="display: block; font-size: 12px; font-weight: 800; color: #d97706; text-transform: uppercase; margin-bottom: 4px;">
                    <i class="fa-solid fa-mountain"></i> 2. Hambatan / Kendala yang Dihadapi
                </label>
                <div id="mReflectObstacle" style="font-size: 13px; color: #0f172a; line-height: 1.5;">-</div>
            </div>

            <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 10px; padding: 12px;">
                <label style="display: block; font-size: 12px; font-weight: 800; color: #059669; text-transform: uppercase; margin-bottom: 4px;">
                    <i class="fa-solid fa-lightbulb"></i> 3. Solusi & Langkah Perbaikan
                </label>
                <div id="mReflectSolution" style="font-size: 13px; color: #0f172a; line-height: 1.5;">-</div>
            </div>

            <div style="background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 10px; padding: 12px;">
                <label style="display: block; font-size: 12px; font-weight: 800; color: #7c3aed; text-transform: uppercase; margin-bottom: 4px;">
                    <i class="fa-solid fa-hands-praying"></i> 4. Rasa Syukur Pekan Ini
                </label>
                <div id="mReflectGratitude" style="font-size: 13px; color: #0f172a; line-height: 1.5;">-</div>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="button" onclick="closeReflectionModal()" class="btn btn-secondary" style="padding: 8px 18px; font-size: 13px;">Tutup</button>
        </div>
    </div>
</div>

<script>
function showReflectionModal(data) {
    document.getElementById('mReflectSubtitle').textContent = data.student_name + ' (' + data.grade_name + ') • ' + data.week_code + ' - ' + data.day_name;
    document.getElementById('mReflectAction').textContent = data.reflection_action || 'Tidak diisi';
    document.getElementById('mReflectObstacle').textContent = data.reflection_obstacle || 'Tidak diisi';
    document.getElementById('mReflectSolution').textContent = data.reflection_solution || 'Tidak diisi';
    document.getElementById('mReflectGratitude').textContent = data.reflection_gratitude || 'Tidak diisi';
    document.getElementById('modalReflect').style.display = 'flex';
}

function closeReflectionModal() {
    document.getElementById('modalReflect').style.display = 'none';
}

window.onclick = function(event) {
    var modal = document.getElementById('modalReflect');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
