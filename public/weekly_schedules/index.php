<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_schedules');
$currentRole = currentRole();
$pageTitle = 'Master Jadwal Pekanan & Eskul';
$userId = currentUserId();

$activeTab = trim($_GET['tab'] ?? 'student');
if (!in_array($activeTab, ['student', 'staff', 'eskul'])) {
    $activeTab = 'student';
}

$selectedUnit = trim($_GET['unit_id'] ?? '');
$selectedDay = trim($_GET['day_code'] ?? '');
$selectedGrade = trim($_GET['grade_id'] ?? '');
$selectedClass = trim($_GET['class_group_id'] ?? '');

$ksUnitId = null;
$ksUnitName = '';
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("
        SELECT aup.unit_id, u.unit 
        FROM admin_unit_permissions aup 
        JOIN units u ON u.id = aup.unit_id 
        WHERE aup.user_id = ? LIMIT 1
    ");
    $stmtKs->execute([$userId]);
    $ksRow = $stmtKs->fetch(PDO::FETCH_ASSOC);
    if ($ksRow) {
        $ksUnitId = (int)$ksRow['unit_id'];
        $ksUnitName = $ksRow['unit'];
    }
}

// Ambil daftar unit
$allUnits = $pdo->query("SELECT id, unit FROM units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Effective unit filter
$filterUnitId = ($currentRole === 'kepala_sekolah') ? $ksUnitId : (!empty($selectedUnit) ? (int)$selectedUnit : null);

// Ambil data Grades dan Classes untuk filter tab siswa
$filterGrades = [];
$filterClasses = [];
if ($filterUnitId) {
    $stmtG = $pdo->prepare("SELECT id, grade FROM grades WHERE unit_id = ? ORDER BY sort_order ASC, grade ASC");
    $stmtG->execute([$filterUnitId]);
    $filterGrades = $stmtG->fetchAll(PDO::FETCH_ASSOC);

    $stmtC = $pdo->prepare("
        SELECT cg.id, cg.name, cg.grade_id 
        FROM class_groups cg 
        JOIN grades g ON g.id = cg.grade_id 
        WHERE g.unit_id = ? 
        ORDER BY g.sort_order ASC, cg.name ASC
    ");
    $stmtC->execute([$filterUnitId]);
    $filterClasses = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} else {
    $filterGrades = $pdo->query("SELECT g.id, g.grade, g.unit_id, u.unit as unit_name FROM grades g JOIN units u ON u.id = g.unit_id ORDER BY u.id, g.sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    $filterClasses = $pdo->query("SELECT cg.id, cg.name, cg.grade_id FROM class_groups cg ORDER BY cg.name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Bangun query berdasarkan tab
$whereSql = [];
$params = [];

if ($filterUnitId) {
    $whereSql[] = "ws.unit_id = ?";
    $params[] = $filterUnitId;
}

if (!empty($selectedDay)) {
    $whereSql[] = "ws.day_code = ?";
    $params[] = (int)$selectedDay;
}

if ($activeTab === 'student') {
    $whereSql[] = "ws.target_type = 'student'";
    $whereSql[] = "ws.schedule_type = 'reguler'";

    if (!empty($selectedGrade)) {
        $whereSql[] = "ws.grade_id = ?";
        $params[] = (int)$selectedGrade;
    }
    if (!empty($selectedClass)) {
        $whereSql[] = "ws.class_group_id = ?";
        $params[] = (int)$selectedClass;
    }
} elseif ($activeTab === 'staff') {
    $whereSql[] = "ws.target_type = 'staff'";
} elseif ($activeTab === 'eskul') {
    $whereSql[] = "ws.schedule_type = 'eskul'";
}

$whereClause = !empty($whereSql) ? "WHERE " . implode(" AND ", $whereSql) : "";

$query = "
    SELECT ws.*, u.unit AS unit_name, g.grade, cg.name AS class_name,
           (SELECT COUNT(*) FROM weekly_schedule_students wss WHERE wss.weekly_schedule_id = ws.id) AS student_count,
           (SELECT COUNT(*) FROM weekly_schedule_staff wsf WHERE wsf.weekly_schedule_id = ws.id) AS staff_count
    FROM weekly_schedules ws
    INNER JOIN units u ON u.id = ws.unit_id
    LEFT JOIN grades g ON g.id = ws.grade_id
    LEFT JOIN class_groups cg ON cg.id = ws.class_group_id
    {$whereClause}
    ORDER BY u.id ASC, ws.day_code ASC, ws.id ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map eskul students for modal
$eskulStudents = [];
if ($activeTab === 'eskul' && !empty($schedules)) {
    $schIds = array_column($schedules, 'id');
    $inQ = implode(',', array_fill(0, count($schIds), '?'));
    $stStudents = $pdo->prepare("
        SELECT wss.weekly_schedule_id, s.id, s.nis, s.name, cg.name AS class_name, g.grade
        FROM weekly_schedule_students wss
        JOIN students s ON s.id = wss.student_id
        LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
        LEFT JOIN class_groups cg ON cg.id = se.class_group_id
        LEFT JOIN grades g ON g.id = cg.grade_id
        WHERE wss.weekly_schedule_id IN ({$inQ})
        ORDER BY s.name ASC
    ");
    $stStudents->execute($schIds);
    while ($r = $stStudents->fetch(PDO::FETCH_ASSOC)) {
        $eskulStudents[$r['weekly_schedule_id']][] = $r;
    }
}

// Map assigned staff for modal
$staffAssigned = [];
if ($activeTab === 'staff' && !empty($schedules)) {
    $schIds = array_column($schedules, 'id');
    $inQ = implode(',', array_fill(0, count($schIds), '?'));
    $stStaff = $pdo->prepare("
        SELECT wsf.weekly_schedule_id, st.id, st.nik, st.name, COALESCE(u.role, 'Staff') AS role
        FROM weekly_schedule_staff wsf
        JOIN staff st ON st.id = wsf.staff_id
        LEFT JOIN users u ON u.id = st.user_id
        WHERE wsf.weekly_schedule_id IN ({$inQ})
        ORDER BY st.name ASC
    ");
    $stStaff->execute($schIds);
    while ($r = $stStaff->fetch(PDO::FETCH_ASSOC)) {
        $staffAssigned[$r['weekly_schedule_id']][] = $r;
    }
}


$daysMap = [1=>'Senin', 2=>'Selasa', 3=>'Rabu', 4=>'Kamis', 5=>'Jumat', 6=>'Sabtu', 7=>'Minggu'];


require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="margin:0 0 4px 0;"><i class="fa-solid fa-calendar-days" style="color:#0284c7; margin-right:8px;"></i> Master Jadwal Pekanan & Eskul</h3>
            <small class="text-muted">
                Pengaturan jam masuk & pulang terpisah untuk Siswa (KBM), Staff/Guru, dan Kegiatan Eskul 
                <?= $currentRole === 'kepala_sekolah' ? "<strong>(Unit: " . e($ksUnitName) . ")</strong>" : '' ?>
            </small>
        </div>
        <div style="display:flex; gap:8px;">
            <a href="create.php?type=<?= e($activeTab) ?>" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-plus"></i> Tambah <?= $activeTab === 'student' ? 'Jadwal Siswa' : ($activeTab === 'staff' ? 'Jadwal Staff' : 'Kegiatan Eskul') ?>
            </a>
        </div>
    </div>

    <!-- TABS NAVIGASI 3 JENIS JADWAL -->
    <div style="display: flex; gap: 4px; border-bottom: 2px solid #e2e8f0; padding: 10px 20px 0 20px; background: #f8fafc; flex-wrap: wrap;">
        <a href="index.php?tab=student<?= $filterUnitId && $currentRole !== 'kepala_sekolah' ? '&unit_id=' . urlencode($filterUnitId) : '' ?>" 
           style="padding: 10px 18px; font-size: 13.5px; font-weight: 700; text-decoration: none; border-radius: 8px 8px 0 0; display: inline-flex; align-items: center; gap: 8px; border: 1px solid <?= $activeTab === 'student' ? '#cbd5e1' : 'transparent' ?>; border-bottom: <?= $activeTab === 'student' ? '3px solid #0284c7' : 'none' ?>; background: <?= $activeTab === 'student' ? 'white' : 'transparent' ?>; color: <?= $activeTab === 'student' ? '#0284c7' : '#64748b' ?>;">
            <i class="fa-solid fa-graduation-cap"></i> 🎓 Jadwal Siswa (KBM)
        </a>
        <a href="index.php?tab=staff<?= $filterUnitId && $currentRole !== 'kepala_sekolah' ? '&unit_id=' . urlencode($filterUnitId) : '' ?>" 
           style="padding: 10px 18px; font-size: 13.5px; font-weight: 700; text-decoration: none; border-radius: 8px 8px 0 0; display: inline-flex; align-items: center; gap: 8px; border: 1px solid <?= $activeTab === 'staff' ? '#cbd5e1' : 'transparent' ?>; border-bottom: <?= $activeTab === 'staff' ? '3px solid #059669' : 'none' ?>; background: <?= $activeTab === 'staff' ? 'white' : 'transparent' ?>; color: <?= $activeTab === 'staff' ? '#059669' : '#64748b' ?>;">
            <i class="fa-solid fa-user-tie"></i> 💼 Jadwal Staff & Guru
        </a>
        <a href="index.php?tab=eskul<?= $filterUnitId && $currentRole !== 'kepala_sekolah' ? '&unit_id=' . urlencode($filterUnitId) : '' ?>" 
           style="padding: 10px 18px; font-size: 13.5px; font-weight: 700; text-decoration: none; border-radius: 8px 8px 0 0; display: inline-flex; align-items: center; gap: 8px; border: 1px solid <?= $activeTab === 'eskul' ? '#cbd5e1' : 'transparent' ?>; border-bottom: <?= $activeTab === 'eskul' ? '3px solid #d97706' : 'none' ?>; background: <?= $activeTab === 'eskul' ? 'white' : 'transparent' ?>; color: <?= $activeTab === 'eskul' ? '#d97706' : '#64748b' ?>;">
            <i class="fa-solid fa-futbol"></i> ⚽ Kegiatan Eskul
        </a>
    </div>

    <!-- Filter Bar -->
    <div style="padding: 12px 20px; background: white; border-bottom: 1px solid #e2e8f0;">
        <form method="GET" action="index.php" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <input type="hidden" name="tab" value="<?= e($activeTab) ?>">

            <?php if ($currentRole !== 'kepala_sekolah'): ?>
                <div style="display:flex; align-items:center; gap:6px;">
                    <label style="font-size:12px; color:#475569; font-weight:600;">Unit:</label>
                    <select name="unit_id" onchange="this.form.submit()" class="form-control" style="font-size:12.5px; padding:5px 10px; width:auto; border-radius:6px;">
                        <option value="">-- Semua Unit --</option>
                        <?php foreach ($allUnits as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $selectedUnit == $u['id'] ? 'selected' : '' ?>>
                                <?= e($u['unit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'student'): ?>
                <!-- Filter Tingkat / Grade -->
                <div style="display:flex; align-items:center; gap:6px;">
                    <label style="font-size:12px; color:#475569; font-weight:600;">Tingkat:</label>
                    <select name="grade_id" onchange="this.form.submit()" class="form-control" style="font-size:12.5px; padding:5px 10px; width:auto; border-radius:6px;">
                        <option value="">-- Semua Tingkat --</option>
                        <?php foreach ($filterGrades as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= $selectedGrade == $g['id'] ? 'selected' : '' ?>>
                                Tingkat <?= e($g['grade']) ?> <?= isset($g['unit_name']) ? '(' . e($g['unit_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filter Subkelas -->
                <div style="display:flex; align-items:center; gap:6px;">
                    <label style="font-size:12px; color:#475569; font-weight:600;">Subkelas:</label>
                    <select name="class_group_id" onchange="this.form.submit()" class="form-control" style="font-size:12.5px; padding:5px 10px; width:auto; border-radius:6px;">
                        <option value="">-- Semua Subkelas --</option>
                        <?php foreach ($filterClasses as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $selectedClass == $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <!-- Filter Hari -->
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:12px; color:#475569; font-weight:600;">Hari:</label>
                <select name="day_code" onchange="this.form.submit()" class="form-control" style="font-size:12.5px; padding:5px 10px; width:auto; border-radius:6px;">
                    <option value="">-- Semua Hari --</option>
                    <?php foreach ($daysMap as $c => $d): ?>
                        <option value="<?= $c ?>" <?= $selectedDay == $c ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!empty($selectedUnit) || !empty($selectedDay) || !empty($selectedGrade) || !empty($selectedClass)): ?>
                <a href="index.php?tab=<?= e($activeTab) ?>" class="btn btn-sm btn-light" style="padding: 5px 10px; font-size:12px;">Reset Filter</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- BULK ACTION TOOLBAR (Muncul otomatis saat ada jadwal yang dicentang) -->
    <div id="bulkActionToolbar" style="display:none; margin: 12px 20px; padding: 12px 16px; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #16a34a; color: white; font-weight: bold; font-size: 13px;" id="selectedCountBadge">0</span>
            <span style="font-size: 13.5px; font-weight: 600; color: #166534;" id="selectedCountText">0 jadwal terpilih</span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <!-- Tombol Aktifkan Sekaligus -->
            <button type="button" class="btn btn-sm btn-success" onclick="submitBulkDirect('activate')" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; background: #16a34a; border-color: #16a34a; color: white; border-radius: 6px; cursor: pointer;">
                <i class="fa-solid fa-circle-check"></i> Aktifkan Terpilih
            </button>
            <!-- Tombol Nonaktifkan Sekaligus -->
            <button type="button" class="btn btn-sm btn-warning" onclick="submitBulkDirect('deactivate')" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; background: #eab308; border-color: #ca8a04; color: #713f12; border-radius: 6px; cursor: pointer;">
                <i class="fa-solid fa-circle-xmark"></i> Nonaktifkan Terpilih
            </button>
            <!-- Tombol Edit Sekaligus -->
            <button type="button" class="btn btn-sm btn-primary" onclick="openBulkEditModal()" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; background: #0284c7; border-color: #0284c7; color: white; border-radius: 6px; cursor: pointer;">
                <i class="fa-solid fa-pen-to-square"></i> Edit Sekaligus
            </button>
            <!-- Tombol Hapus Terpilih -->
            <button type="button" class="btn btn-sm btn-danger" onclick="submitBulkDirect('delete')" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; background: #dc2626; border-color: #dc2626; color: white; border-radius: 6px; cursor: pointer;">
                <i class="fa-solid fa-trash"></i> Hapus Terpilih
            </button>
        </div>
    </div>

    <!-- Hidden Form for Direct Bulk Actions (activate, deactivate, delete) -->
    <form id="formBulkDirect" method="POST" action="bulk_action.php" style="display:none;">
        <input type="hidden" name="bulk_action_type" id="bulkDirectActionType" value="">
        <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
        <input type="hidden" name="unit_id" value="<?= (int)($filterUnitId ?: 0) ?>">
        <div id="bulkDirectHiddenInputs"></div>
    </form>

    <!-- TABEL DATA JADWAL -->
    <div class="table-wrapper">
        <table class="table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f1f5f9; text-align:left; font-size:12.5px; color:#334155;">
                    <th style="padding:10px 14px; width:44px; text-align:center;">
                        <input type="checkbox" id="selectAllSchedules" title="Pilih Semua Jadwal di Tab Ini" style="width:16px; height:16px; cursor:pointer;" onchange="toggleSelectAllSchedules(this)">
                    </th>
                    <?php if ($activeTab === 'student'): ?>
                        <th style="padding:10px 14px;">Lingkup Jadwal</th>
                        <th style="padding:10px 14px;">Unit</th>
                        <th style="padding:10px 14px;">Hari</th>
                        <th style="padding:10px 14px;">Jam Masuk</th>
                        <th style="padding:10px 14px;">Batas Telat</th>
                        <th style="padding:10px 14px;">Jam Pulang</th>
                        <th style="padding:10px 14px;">Status</th>
                        <th style="padding:10px 14px; text-align:center;">Aksi</th>
                    <?php elseif ($activeTab === 'staff'): ?>
                        <th style="padding:10px 14px;">Nama Shift / Keterangan</th>
                        <th style="padding:10px 14px;">Unit</th>
                        <th style="padding:10px 14px;">Hari</th>
                        <th style="padding:10px 14px;">Jam Masuk</th>
                        <th style="padding:10px 14px;">Batas Telat</th>
                        <th style="padding:10px 14px;">Jam Pulang</th>
                        <th style="padding:10px 14px;">Tipe Shift</th>
                        <th style="padding:10px 14px;">Personel Ditugaskan</th>
                        <th style="padding:10px 14px;">Status</th>
                        <th style="padding:10px 14px; text-align:center;">Aksi</th>
                    <?php elseif ($activeTab === 'eskul'): ?>
                        <th style="padding:10px 14px;">Nama Kegiatan Eskul</th>
                        <th style="padding:10px 14px;">Unit</th>
                        <th style="padding:10px 14px;">Hari</th>
                        <th style="padding:10px 14px;">Jam Mulai</th>
                        <th style="padding:10px 14px;">Batas Telat</th>
                        <th style="padding:10px 14px;">Jam Selesai (Pulang)</th>
                        <th style="padding:10px 14px;">Peserta Terdaftar</th>
                        <th style="padding:10px 14px;">Status</th>
                        <th style="padding:10px 14px; text-align:center;">Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($schedules)): ?>
                    <tr>
                        <td colspan="<?= $activeTab === 'staff' ? 11 : ($activeTab === 'eskul' ? 10 : 9) ?>" style="text-align:center; padding: 32px; color:#64748b;">
                            <i class="fa-regular fa-folder-open" style="font-size: 28px; margin-bottom: 8px; display:block; color:#94a3b8;"></i>
                            Belum ada data jadwal yang sesuai filter pada tab ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($schedules as $s): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9; font-size:13px;" id="schedule-row-<?= $s['id'] ?>">
                            <td style="padding:10px 14px; text-align:center;">
                                <input type="checkbox" class="schedule-checkbox" value="<?= $s['id'] ?>" style="width:16px; height:16px; cursor:pointer;" onchange="onScheduleCheckChanged()">
                            </td>
                            <?php if ($activeTab === 'student'): ?>
                                <!-- Lingkup Jadwal Siswa -->
                                <td style="padding:10px 14px;">
                                    <?php if (!empty($s['class_group_id'])): ?>
                                        <span style="display:inline-flex; align-items:center; gap:5px; background:#f3e8ff; color:#7e22ce; padding:3px 8px; border-radius:6px; font-weight:700; font-size:11.5px;">
                                            <i class="fa-solid fa-users"></i> Subkelas: <?= e($s['class_name'] ?: 'ID #' . $s['class_group_id']) ?>
                                            <?= !empty($s['grade']) ? '(Tk. ' . e($s['grade']) . ')' : '' ?>
                                        </span>
                                    <?php elseif (!empty($s['grade_id'])): ?>
                                        <span style="display:inline-flex; align-items:center; gap:5px; background:#e0f2fe; color:#0369a1; padding:3px 8px; border-radius:6px; font-weight:700; font-size:11.5px;">
                                            <i class="fa-solid fa-layer-group"></i> Tingkat / Grade <?= e($s['grade'] ?: 'ID #' . $s['grade_id']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="display:inline-flex; align-items:center; gap:5px; background:#f1f5f9; color:#475569; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11.5px;">
                                            <i class="fa-solid fa-school"></i> Seluruh Unit <?= e($s['unit_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px;"><strong><?= e($s['unit_name']) ?></strong></td>
                                <td style="padding:10px 14px;"><span style="font-weight:600;"><?= e($s['day_name']) ?></span></td>
                                <td style="padding:10px 14px; font-family:monospace; color:#059669; font-weight:700;"><?= substr($s['student_in'] ?? '00:00', 0, 5) ?></td>
                                <td style="padding:10px 14px; font-family:monospace; color:#d97706; font-weight:700;"><?= substr($s['student_late'] ?? '00:00', 0, 5) ?></td>
                                <td style="padding:10px 14px; font-family:monospace; color:#dc2626; font-weight:700;"><?= substr($s['student_out'] ?? '00:00', 0, 5) ?></td>

                            <?php elseif ($activeTab === 'staff'): ?>
                                <!-- Jadwal Staff -->
                                <td style="padding:10px 14px;">
                                    <strong><?= e($s['name'] ?: 'Reguler Staff') ?></strong>
                                </td>
                                <td style="padding:10px 14px;"><strong><?= e($s['unit_name']) ?></strong></td>
                                <td style="padding:10px 14px;"><span style="font-weight:600;"><?= e($s['day_name']) ?></span></td>
                                <td style="padding:10px 14px; font-family:monospace; color:#059669; font-weight:700;"><?= substr($s['staff_in'] ?? '00:00', 0, 5) ?></td>
                                <td style="padding:10px 14px; font-family:monospace; color:#d97706; font-weight:700;"><?= substr($s['staff_late'] ?? '00:00', 0, 5) ?></td>
                                <td style="padding:10px 14px; font-family:monospace; color:#dc2626; font-weight:700;"><?= substr($s['staff_out'] ?? '00:00', 0, 5) ?></td>
                                <td style="padding:10px 14px;">
                                    <?php if (!empty($s['is_overnight'])): ?>
                                        <span style="display:inline-flex; align-items:center; gap:4px; background:#fffbeb; color:#b45309; border:1px solid #fde68a; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700;">
                                            🌙 Shift Malam (Lintas Hari)
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#64748b; font-size:11.5px;">Shift Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px;">
                                    <?php if ((int)($s['staff_count'] ?? 0) > 0): ?>
                                        <button type="button" class="btn btn-sm btn-light" onclick="showStaffModal(<?= $s['id'] ?>, '<?= e(addslashes($s['name'] ?: 'Shift Staff')) ?>')" style="padding:3px 8px; font-size:12px; font-weight:700; border:1px solid #bbf7d0; border-radius:6px; background:#f0fdf4; color:#166534;">
                                            <i class="fa-solid fa-user-shield" style="color:#16a34a;"></i> <?= (int)$s['staff_count'] ?> Personel
                                        </button>
                                    <?php else: ?>
                                        <span style="font-size:11.5px; color:#64748b; background:#f1f5f9; padding:3px 8px; border-radius:6px;">Semua Staff Unit</span>
                                    <?php endif; ?>
                                </td>

                            <?php elseif ($activeTab === 'eskul'): ?>
                                <!-- Kegiatan Eskul -->
                                <td style="padding:10px 14px;">
                                    <div style="font-weight:700; color:#1e293b;"><?= e($s['name']) ?></div>
                                </td>
                                <td style="padding:10px 14px;"><strong><?= e($s['unit_name']) ?></strong></td>
                                <td style="padding:10px 14px;"><span style="font-weight:600;"><?= e($s['day_name']) ?></span></td>
                                <td style="padding:10px 14px; font-family:monospace; color:#059669; font-weight:700;"><?= substr($s['student_in'] ?? '00:00', 0, 5) ?></td>
                                <td style="padding:10px 14px; font-family:monospace; color:#d97706; font-weight:700;"><?= substr($s['student_late'] ?? '00:00', 0, 5) ?></td>
                                <td style="padding:10px 14px; font-family:monospace; color:#dc2626; font-weight:700;"><?= substr($s['student_out'] ?? '00:00', 0, 5) ?></td>
                                <td style="padding:10px 14px;">
                                    <button type="button" class="btn btn-sm btn-light" onclick="showStudentModal(<?= $s['id'] ?>, '<?= e(addslashes($s['name'])) ?>')" style="padding:3px 8px; font-size:12px; font-weight:700; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc;">
                                        <i class="fa-solid fa-users" style="color:#16a34a;"></i> <?= (int)$s['student_count'] ?> Siswa
                                    </button>
                                </td>
                            <?php endif; ?>

                            <!-- Status -->
                            <td style="padding:10px 14px;">
                                <?php if ($s['is_active'] === 'active'): ?>
                                    <span style="background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700;">Aktif</span>
                                <?php else: ?>
                                    <span style="background:#fee2e2; color:#b91c1c; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700;">Nonaktif</span>
                                <?php endif; ?>
                            </td>

                            <!-- Aksi -->
                            <td style="padding:10px 14px; text-align:center;">
                                <div style="display:inline-flex; gap:6px; align-items:center;">
                                    <a href="edit.php?id=<?= $s['id'] ?>&tab=<?= e($activeTab) ?>" class="btn btn-sm btn-primary" style="padding:4px 8px; font-size:12px;" title="Edit Jadwal">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <form method="POST" action="delete.php" style="display:inline; margin:0;" onsubmit="return confirm('Yakin ingin menghapus jadwal ini?');">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="padding:4px 8px; font-size:12px;" title="Hapus Jadwal">
                                            <i class="fa-solid fa-trash"></i>
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

<!-- MODAL LIHAT SISWA ESKUL -->
<div id="modalEskulStudents" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:white; border-radius:12px; width:90%; max-width:550px; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h4 style="margin:0; font-size:16px; color:#0f172a;" id="modalEskulTitle">Daftar Siswa Eskul</h4>
                <small style="color:#64748b;">Peserta yang otomatis mengikuti jadwal kepulangan eskul ini</small>
            </div>
            <button type="button" onclick="closeStudentModal()" style="border:none; background:transparent; font-size:20px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="padding:12px 20px; border-bottom:1px solid #f1f5f9;">
            <input type="text" id="modalSearchInput" placeholder="Cari nama atau NIS siswa..." onkeyup="filterModalStudents()" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
        </div>
        <div id="modalStudentsList" style="padding:16px 20px; overflow-y:auto; flex:1; display:flex; flex-direction:column; gap:6px;">
            <!-- Render via JS -->
        </div>
        <div style="padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-light" onclick="closeStudentModal()" style="padding:6px 14px;">Tutup</button>
        </div>
    </div>
</div>

<script>
const eskulData = <?= json_encode($eskulStudents) ?>;

function showStudentModal(scheduleId, name) {
    document.getElementById('modalEskulTitle').innerText = 'Daftar Peserta: ' + name;
    const container = document.getElementById('modalStudentsList');
    const students = eskulData[scheduleId] || [];

    if (students.length === 0) {
        container.innerHTML = '<p style="text-align:center; color:#64748b; margin:20px 0;">Belum ada siswa yang ditambahkan ke eskul ini.</p>';
    } else {
        let html = '';
        students.forEach((s, idx) => {
            html += `
                <div class="modal-student-row" data-name="${s.name.toLowerCase()}" data-nis="${(s.nis || '').toLowerCase()}" style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                    <div>
                        <strong>${idx + 1}. ${s.name}</strong><br>
                        <small style="color:#64748b;">${s.class_name ? s.class_name : (s.grade ? 'Tingkat ' + s.grade : '-')} | NIS: ${s.nis || '-'}</small>
                    </div>
                    <span style="background:#dcfce7; color:#15803d; font-size:11px; font-weight:bold; padding:2px 8px; border-radius:12px;">Terdaftar</span>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    document.getElementById('modalSearchInput').value = '';
    document.getElementById('modalEskulStudents').style.display = 'flex';
}

function closeStudentModal() {
    document.getElementById('modalEskulStudents').style.display = 'none';
}

function filterModalStudents() {
    const q = document.getElementById('modalSearchInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.modal-student-row');
    rows.forEach(r => {
        const name = r.getAttribute('data-name');
        const nis = r.getAttribute('data-nis');
        if (name.includes(q) || nis.includes(q)) {
            r.style.display = 'flex';
        } else {
            r.style.display = 'none';
        }
    });
}

// Tutup modal jika klik di luar box
window.addEventListener('click', function(e) {
    const modalEskul = document.getElementById('modalEskulStudents');
    if (e.target === modalEskul) {
        closeStudentModal();
    }
    const modalStaff = document.getElementById('modalStaffList');
    if (e.target === modalStaff) {
        closeStaffModal();
    }
    const modalBulk = document.getElementById('modalBulkEdit');
    if (e.target === modalBulk) {
        closeBulkEditModal();
    }
});

const staffAssignedData = <?= json_encode($staffAssigned) ?>;

function showStaffModal(scheduleId, name) {
    document.getElementById('modalStaffTitle').innerText = 'Personel Ditugaskan: ' + name;
    const container = document.getElementById('modalStaffItems');
    const staffList = staffAssignedData[scheduleId] || [];

    if (staffList.length === 0) {
        container.innerHTML = '<p style="text-align:center; color:#64748b; margin:20px 0;">Tidak ada personel spesifik yang ditugaskan (berlaku untuk semua staff unit).</p>';
    } else {
        let html = '';
        staffList.forEach((st, idx) => {
            html += `
                <div class="modal-staff-row" data-name="${st.name.toLowerCase()}" data-nik="${(st.nik || '').toLowerCase()}" style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                    <div>
                        <strong>${idx + 1}. ${st.name}</strong><br>
                        <small style="color:#64748b;">NIK: ${st.nik || '-'} | Role/Posisi: ${st.role || 'Staff'}</small>
                    </div>
                    <span style="background:#dcfce7; color:#15803d; font-size:11px; font-weight:bold; padding:2px 8px; border-radius:12px;">Ditugaskan</span>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    document.getElementById('modalSearchStaffInput').value = '';
    document.getElementById('modalStaffList').style.display = 'flex';
}

function closeStaffModal() {
    document.getElementById('modalStaffList').style.display = 'none';
}

function filterModalStaff() {
    const q = document.getElementById('modalSearchStaffInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.modal-staff-row');
    rows.forEach(r => {
        const name = r.getAttribute('data-name');
        const nik = r.getAttribute('data-nik');
        if (name.includes(q) || nik.includes(q)) {
            r.style.display = 'flex';
        } else {
            r.style.display = 'none';
        }
    });
}

/* ========================================================
   LOGIKA MASSAL / CHECKBOX (BULK ACTIONS)
   ======================================================== */
function getSelectedScheduleIds() {
    const checkboxes = document.querySelectorAll('.schedule-checkbox:checked');
    const ids = [];
    checkboxes.forEach(cb => {
        const val = parseInt(cb.value);
        if (val > 0) ids.push(val);
    });
    return ids;
}

function updateBulkToolbar() {
    const totalCheckboxes = document.querySelectorAll('.schedule-checkbox');
    const checkedCheckboxes = document.querySelectorAll('.schedule-checkbox:checked');
    const count = checkedCheckboxes.length;
    const toolbar = document.getElementById('bulkActionToolbar');
    const masterCheckbox = document.getElementById('selectAllSchedules');
    const badge = document.getElementById('selectedCountBadge');
    const text = document.getElementById('selectedCountText');

    if (toolbar) {
        if (count > 0) {
            toolbar.style.display = 'flex';
            if (badge) badge.innerText = count;
            if (text) text.innerText = count + ' jadwal terpilih';
        } else {
            toolbar.style.display = 'none';
        }
    }

    if (masterCheckbox && totalCheckboxes.length > 0) {
        if (count === 0) {
            masterCheckbox.checked = false;
            masterCheckbox.indeterminate = false;
        } else if (count === totalCheckboxes.length) {
            masterCheckbox.checked = true;
            masterCheckbox.indeterminate = false;
        } else {
            masterCheckbox.checked = false;
            masterCheckbox.indeterminate = true;
        }
    }
}

function toggleSelectAllSchedules(master) {
    const checkboxes = document.querySelectorAll('.schedule-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = master.checked;
        const row = document.getElementById('schedule-row-' + cb.value);
        if (row) {
            row.style.background = master.checked ? '#f0fdf4' : '';
        }
    });
    updateBulkToolbar();
}

function onScheduleCheckChanged() {
    const checkboxes = document.querySelectorAll('.schedule-checkbox');
    checkboxes.forEach(cb => {
        const row = document.getElementById('schedule-row-' + cb.value);
        if (row) {
            row.style.background = cb.checked ? '#f0fdf4' : '';
        }
    });
    updateBulkToolbar();
}

function submitBulkDirect(actionType) {
    const ids = getSelectedScheduleIds();
    if (ids.length === 0) {
        alert('Silakan centang minimal satu jadwal terlebih dahulu.');
        return;
    }

    let confirmMsg = '';
    if (actionType === 'activate') {
        confirmMsg = `Yakin ingin MENGAKTIFKAN ${ids.length} jadwal terpilih?`;
    } else if (actionType === 'deactivate') {
        confirmMsg = `Yakin ingin MENONAKTIFKAN ${ids.length} jadwal terpilih?`;
    } else if (actionType === 'delete') {
        confirmMsg = `PERINGATAN: Yakin ingin MENGHAPUS ${ids.length} jadwal terpilih secara permanen? Tindakan ini tidak dapat dibatalkan!`;
    }

    if (!confirm(confirmMsg)) {
        return;
    }

    const form = document.getElementById('formBulkDirect');
    const actionTypeInput = document.getElementById('bulkDirectActionType');
    const container = document.getElementById('bulkDirectHiddenInputs');

    actionTypeInput.value = actionType;
    container.innerHTML = '';
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'schedule_ids[]';
        input.value = id;
        container.appendChild(input);
    });

    form.submit();
}

function openBulkEditModal() {
    const ids = getSelectedScheduleIds();
    if (ids.length === 0) {
        alert('Silakan centang minimal satu jadwal terlebih dahulu.');
        return;
    }

    const count = ids.length;
    const subtitle = document.getElementById('bulkEditSubtitle');
    if (subtitle) {
        subtitle.innerText = `Memperbarui ${count} jadwal terpilih secara serentak`;
    }

    const container = document.getElementById('bulkEditHiddenInputs');
    container.innerHTML = '';
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'schedule_ids[]';
        input.value = id;
        container.appendChild(input);
    });

    document.getElementById('modalBulkEdit').style.display = 'flex';
}

function closeBulkEditModal() {
    document.getElementById('modalBulkEdit').style.display = 'none';
}

function toggleBulkSection(section, checked) {
    let sectionEl = null;
    if (section === 'hours') {
        sectionEl = document.getElementById('sectionHoursFields');
    } else if (section === 'shift_name') {
        sectionEl = document.getElementById('sectionShiftNameFields');
    } else if (section === 'overnight') {
        sectionEl = document.getElementById('sectionOvernightFields');
    } else if (section === 'status') {
        sectionEl = document.getElementById('sectionStatusFields');
    }

    if (sectionEl) {
        sectionEl.style.display = checked ? (section === 'hours' ? 'grid' : 'block') : 'none';
    }
}

function validateBulkEditSubmit() {
    const chkHours = document.getElementById('chkApplyHours');
    const chkShiftName = document.getElementById('chkApplyShiftName');
    const chkOvernight = document.getElementById('chkApplyOvernight');
    const chkStatus = document.getElementById('chkApplyStatus');

    const anyChecked = (chkHours && chkHours.checked) || 
                       (chkShiftName && chkShiftName.checked) || 
                       (chkOvernight && chkOvernight.checked) || 
                       (chkStatus && chkStatus.checked);

    if (!anyChecked) {
        alert('Silakan centang minimal satu bagian yang ingin diubah (Jam Operasional, Nama Shift, Shift Malam, atau Status).');
        return false;
    }

    return confirm('Simpan perubahan pada semua jadwal terpilih?');
}
</script>

<!-- MODAL LIHAT STAFF PENUGASAN -->
<div id="modalStaffList" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:white; border-radius:12px; width:90%; max-width:550px; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h4 style="margin:0; font-size:16px; color:#0f172a;" id="modalStaffTitle">Daftar Personel Shift</h4>
                <small style="color:#64748b;">Personel yang diprioritaskan mengikuti jam shift ini</small>
            </div>
            <button type="button" onclick="closeStaffModal()" style="border:none; background:transparent; font-size:20px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="padding:12px 20px; border-bottom:1px solid #f1f5f9;">
            <input type="text" id="modalSearchStaffInput" placeholder="Cari nama atau NIK staff/security..." onkeyup="filterModalStaff()" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
        </div>
        <div id="modalStaffItems" style="padding:16px 20px; overflow-y:auto; flex:1; display:flex; flex-direction:column; gap:6px;">
            <!-- Render via JS -->
        </div>
        <div style="padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-light" onclick="closeStaffModal()" style="padding:6px 14px;">Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL EDIT MASSAL JADWAL -->
<div id="modalBulkEdit" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:white; border-radius:12px; width:92%; max-width:620px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h4 style="margin:0; font-size:16px; color:#0f172a;"><i class="fa-solid fa-pen-to-square" style="color:#0284c7; margin-right:6px;"></i> Edit Jadwal Sekaligus</h4>
                <small style="color:#64748b;" id="bulkEditSubtitle">Perbarui jam operasional atau status untuk jadwal yang dipilih</small>
            </div>
            <button type="button" onclick="closeBulkEditModal()" style="border:none; background:transparent; font-size:22px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        
        <form method="POST" action="bulk_action.php" id="formBulkEditModal" onsubmit="return validateBulkEditSubmit();" style="display:flex; flex-direction:column; flex:1; overflow:hidden; margin:0;">
            <input type="hidden" name="bulk_action_type" value="bulk_edit">
            <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
            <input type="hidden" name="unit_id" value="<?= (int)($filterUnitId ?: 0) ?>">
            <div id="bulkEditHiddenInputs"></div>

            <div style="padding:16px 20px; overflow-y:auto; flex:1; display:flex; flex-direction:column; gap:16px;">
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; font-size:12.5px; color:#1e40af;">
                    <i class="fa-solid fa-circle-info"></i> Centang kotak centang pada bagian yang ingin diubah. Bagian yang tidak dicentang tidak akan diubah pada jadwal terpilih.
                </div>

                <!-- Bagian 1: Ubah Jam -->
                <div style="border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; background:#fafafa;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:700; font-size:13.5px; color:#1e293b; margin-bottom:10px;">
                        <input type="checkbox" name="apply_hours" value="1" id="chkApplyHours" onchange="toggleBulkSection('hours', this.checked)" style="width:16px; height:16px; cursor:pointer;">
                        <span><i class="fa-regular fa-clock" style="color:#0284c7;"></i> Ubah Jam Operasional</span>
                    </label>
                    <div id="sectionHoursFields" style="display:none; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:10px; margin-top:8px;">
                        <?php if ($activeTab === 'student'): ?>
                            <div>
                                <label style="font-size:11.5px; color:#475569; font-weight:600; display:block; margin-bottom:4px;">Jam Masuk Siswa</label>
                                <input type="time" name="bulk_student_in" class="form-control" style="font-size:13px; padding:6px 10px; width:100%; border-radius:6px;">
                            </div>
                            <div>
                                <label style="font-size:11.5px; color:#475569; font-weight:600; display:block; margin-bottom:4px;">Batas Terlambat</label>
                                <input type="time" name="bulk_student_late" class="form-control" style="font-size:13px; padding:6px 10px; width:100%; border-radius:6px;">
                            </div>
                            <div>
                                <label style="font-size:11.5px; color:#475569; font-weight:600; display:block; margin-bottom:4px;">Jam Pulang Siswa</label>
                                <input type="time" name="bulk_student_out" class="form-control" style="font-size:13px; padding:6px 10px; width:100%; border-radius:6px;">
                            </div>
                        <?php elseif ($activeTab === 'staff'): ?>
                            <div>
                                <label style="font-size:11.5px; color:#475569; font-weight:600; display:block; margin-bottom:4px;">Jam Masuk Staff</label>
                                <input type="time" name="bulk_staff_in" class="form-control" style="font-size:13px; padding:6px 10px; width:100%; border-radius:6px;">
                            </div>
                            <div>
                                <label style="font-size:11.5px; color:#475569; font-weight:600; display:block; margin-bottom:4px;">Batas Terlambat</label>
                                <input type="time" name="bulk_staff_late" class="form-control" style="font-size:13px; padding:6px 10px; width:100%; border-radius:6px;">
                            </div>
                            <div>
                                <label style="font-size:11.5px; color:#475569; font-weight:600; display:block; margin-bottom:4px;">Jam Pulang Staff</label>
                                <input type="time" name="bulk_staff_out" class="form-control" style="font-size:13px; padding:6px 10px; width:100%; border-radius:6px;">
                            </div>
                        <?php elseif ($activeTab === 'eskul'): ?>
                            <div>
                                <label style="font-size:11.5px; color:#475569; font-weight:600; display:block; margin-bottom:4px;">Jam Mulai Eskul</label>
                                <input type="time" name="bulk_eskul_in" class="form-control" style="font-size:13px; padding:6px 10px; width:100%; border-radius:6px;">
                            </div>
                            <div>
                                <label style="font-size:11.5px; color:#475569; font-weight:600; display:block; margin-bottom:4px;">Batas Toleransi</label>
                                <input type="time" name="bulk_eskul_late" class="form-control" style="font-size:13px; padding:6px 10px; width:100%; border-radius:6px;">
                            </div>
                            <div>
                                <label style="font-size:11.5px; color:#475569; font-weight:600; display:block; margin-bottom:4px;">Jam Selesai Eskul</label>
                                <input type="time" name="bulk_eskul_out" class="form-control" style="font-size:13px; padding:6px 10px; width:100%; border-radius:6px;">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($activeTab === 'staff'): ?>
                    <!-- Bagian 2: Ubah Nama Shift (Khusus Staff) -->
                    <div style="border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; background:#fafafa;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:700; font-size:13.5px; color:#1e293b; margin-bottom:10px;">
                            <input type="checkbox" name="apply_shift_name" value="1" id="chkApplyShiftName" onchange="toggleBulkSection('shift_name', this.checked)" style="width:16px; height:16px; cursor:pointer;">
                            <span><i class="fa-solid fa-tag" style="color:#059669;"></i> Ubah Nama Shift / Keterangan</span>
                        </label>
                        <div id="sectionShiftNameFields" style="display:none; margin-top:8px;">
                            <input type="text" name="bulk_staff_shift_name" placeholder="Contoh: Shift 1 Pagi, Reguler Staff, Security Malam..." class="form-control" style="font-size:13px; padding:7px 12px; width:100%; border-radius:6px;">
                        </div>
                    </div>

                    <!-- Bagian 3: Ubah Pengaturan Shift Malam (Khusus Staff) -->
                    <div style="border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; background:#fafafa;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:700; font-size:13.5px; color:#1e293b; margin-bottom:10px;">
                            <input type="checkbox" name="apply_overnight" value="1" id="chkApplyOvernight" onchange="toggleBulkSection('overnight', this.checked)" style="width:16px; height:16px; cursor:pointer;">
                            <span><i class="fa-solid fa-moon" style="color:#b45309;"></i> Ubah Tipe Shift (Normal / Malam Lintas Hari)</span>
                        </label>
                        <div id="sectionOvernightFields" style="display:none; margin-top:8px;">
                            <select name="bulk_is_overnight" class="form-control" style="font-size:13px; padding:7px 12px; width:100%; border-radius:6px;">
                                <option value="0">Normal (Masuk & Pulang di Hari yang Sama)</option>
                                <option value="1">🌙 Shift Malam / Overnight (Pulang Keesokan Paginya)</option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Bagian 4: Ubah Status Aktif/Nonaktif -->
                <div style="border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; background:#fafafa;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:700; font-size:13.5px; color:#1e293b; margin-bottom:10px;">
                        <input type="checkbox" name="apply_status" value="1" id="chkApplyStatus" onchange="toggleBulkSection('status', this.checked)" style="width:16px; height:16px; cursor:pointer;">
                        <span><i class="fa-solid fa-toggle-on" style="color:#16a34a;"></i> Ubah Status (Aktif / Nonaktif)</span>
                    </label>
                    <div id="sectionStatusFields" style="display:none; margin-top:8px;">
                        <select name="bulk_status" class="form-control" style="font-size:13px; padding:7px 12px; width:100%; border-radius:6px;">
                            <option value="active">Aktif</option>
                            <option value="inactive">Nonaktif</option>
                        </select>
                    </div>
                </div>
            </div>

            <div style="padding:14px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
                <button type="button" class="btn btn-light" onclick="closeBulkEditModal()" style="padding:7px 16px; cursor:pointer;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:7px 20px; font-weight:600; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan Sekaligus
                </button>
            </div>
        </form>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>

