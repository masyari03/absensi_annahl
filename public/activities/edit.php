<?php
require_once '../../config/config.php'; 
require_once '../../config/database.php'; 
require_once '../../config/auth.php'; 
require_once '../../config/security.php'; 
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_activities');
$currentRole = currentRole();
$pageTitle = 'Edit Activity';
$userId = currentUserId();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM activities WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) { 
    flash('error', 'Activity / Kegiatan tidak ditemukan.');
    redirect('index.php');
    exit;
}

// PROTEKSI KEPALA SEKOLAH
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();

    if ((int)$activity['unit_id'] !== $ksUnitId) {
        flash('error', 'Akses ditolak. Anda tidak bisa mengubah jadwal unit lain.');
        redirect('index.php');
        exit;
    }
}

$currentMode = (!empty($activity['target_type']) && $activity['target_type'] === 'staff') ? 'staff' : 'student';
$years = $pdo->query("SELECT * FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetType = in_array($_POST['target_type'] ?? '', ['student', 'staff']) ? $_POST['target_type'] : $currentMode;
    $name = trim($_POST['name'] ?? '');
    $activityDate = $_POST['activity_date'];
    $isHoliday = $_POST['is_holiday'] ?? 'no';
    $status = $_POST['status'] ?? 'active';
    $academicYearId = (int)$_POST['academic_year_id'];
    $description = trim($_POST['description'] ?? '');

    $studentIn = null;
    $studentLate = null;
    $studentOut = null;
    $staffIn = null;
    $staffLate = null;
    $staffOut = null;

    if ($targetType === 'student') {
        $studentIn = $_POST['student_in'] ?: null;
        $studentLate = $_POST['student_late'] ?: null;
        $studentOut = $_POST['student_out'] ?: null;
    } else {
        $staffIn = $_POST['staff_in'] ?: null;
        $staffLate = $_POST['staff_late'] ?: null;
        $staffOut = $_POST['staff_out'] ?: null;
    }

    $stmtUp = $pdo->prepare("
        UPDATE activities
        SET
            academic_year_id = ?, target_type = ?, name = ?, activity_date = ?, is_holiday = ?,
            student_in = ?, student_late = ?, student_out = ?,
            staff_in = ?, staff_late = ?, staff_out = ?, description = ?, status = ?
        WHERE id = ?
    ");

    $stmtUp->execute([
        $academicYearId, $targetType, $name, $activityDate, $isHoliday,
        $studentIn, $studentLate, $studentOut,
        $staffIn, $staffLate, $staffOut,
        $description ?: null, $status, $id
    ]);

    flash('success', 'Kegiatan berhasil diperbarui.');
    redirect('index.php?tab=' . $targetType);
    exit;
}

require '../../includes/header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h3 style="margin: 0;"><i class="fa-solid fa-pen-to-square" style="color:#0284c7; margin-right:8px;"></i> Edit Activity / Kegiatan Khusus</h3>
            <p style="color: #64748b; margin: 4px 0 0 0;">Ubah detail kegiatan harian atau status hari libur.</p>
        </div>

        <!-- MODE SELECTOR BUTTONS -->
        <div style="display: flex; gap: 8px;">
            <button type="button" id="btnModeStudent" class="btn <?= $currentMode === 'student' ? 'btn-primary' : 'btn-light' ?>" onclick="switchActivityMode('student')" style="border: 1px solid #cbd5e1; font-weight: 700;">
                <i class="fa-solid fa-graduation-cap"></i> 🎓 Kegiatan Siswa
            </button>
            <button type="button" id="btnModeStaff" class="btn <?= $currentMode === 'staff' ? 'btn-primary' : 'btn-light' ?>" onclick="switchActivityMode('staff')" style="border: 1px solid #cbd5e1; font-weight: 700;">
                <i class="fa-solid fa-user-tie"></i> 💼 Kegiatan Staff & Guru
            </button>
        </div>
    </div>

    <form method="POST">
        <input type="hidden" name="target_type" id="inputTargetType" value="<?= e($currentMode) ?>">

        <div class="form-grid">
            <div class="form-group">
                <label>Tahun Ajaran *</label>
                <select name="academic_year_id" required>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year['id'] ?>" <?= ($activity['academic_year_id'] == $year['id']) ? 'selected' : '' ?>>
                            <?= e($year['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label id="lblActivityName">Nama Kegiatan *</label>
                <input type="text" name="name" value="<?= e($activity['name']) ?>" required>
            </div>

            <div class="form-group">
                <label>Tanggal Pelaksanaan *</label>
                <input type="date" name="activity_date" value="<?= e($activity['activity_date']) ?>" required>
            </div>

            <div class="form-group">
                <label>Hari Libur?</label>
                <select name="is_holiday" id="selectIsHoliday" onchange="toggleHolidayFields()">
                    <option value="no" <?= ($activity['is_holiday'] === 'no') ? 'selected' : '' ?>>Tidak (Masuk & Absen Berjalan)</option>
                    <option value="yes" <?= ($activity['is_holiday'] === 'yes') ? 'selected' : '' ?>>Ya (Libur Khusus / Mesin Absen Mati)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Status Kegiatan</label>
                <select name="status">
                    <option value="active" <?= $activity['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="closed" <?= $activity['status'] === 'closed' ? 'selected' : '' ?>>Ditutup</option>
                </select>
            </div>

            <!-- JAM SISWA -->
            <div id="rowHoursStudent" style="<?= $currentMode === 'student' ? 'display:contents;' : 'display:none;' ?>">
                <div class="form-group"><label>Siswa: Jam Masuk</label><input type="time" name="student_in" value="<?= e($activity['student_in']) ?>"></div>
                <div class="form-group"><label>Siswa: Batas Terlambat</label><input type="time" name="student_late" value="<?= e($activity['student_late']) ?>"></div>
                <div class="form-group"><label>Siswa: Jam Pulang</label><input type="time" name="student_out" value="<?= e($activity['student_out']) ?>"></div>
            </div>

            <!-- JAM STAFF -->
            <div id="rowHoursStaff" style="<?= $currentMode === 'staff' ? 'display:contents;' : 'display:none;' ?>">
                <div class="form-group"><label>Staff: Jam Masuk</label><input type="time" name="staff_in" value="<?= e($activity['staff_in']) ?>"></div>
                <div class="form-group"><label>Staff: Batas Terlambat</label><input type="time" name="staff_late" value="<?= e($activity['staff_late']) ?>"></div>
                <div class="form-group"><label>Staff: Jam Pulang</label><input type="time" name="staff_out" value="<?= e($activity['staff_out']) ?>"></div>
            </div>

            <div class="form-group full">
                <label>Keterangan Tambahan (Opsional)</label>
                <textarea name="description"><?= e($activity['description']) ?></textarea>
            </div>
        </div>

        <div style="margin-top:24px; display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700;">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan
            </button>
            <a href="index.php?tab=<?= e($currentMode) ?>" class="btn btn-light" style="border: 1px solid #cbd5e1; padding: 10px 18px;">Batal</a>
        </div>
    </form>
</div>

<script>
function switchActivityMode(mode) {
    document.getElementById('inputTargetType').value = mode;

    const btnStu = document.getElementById('btnModeStudent');
    const btnStf = document.getElementById('btnModeStaff');
    const rowStu = document.getElementById('rowHoursStudent');
    const rowStf = document.getElementById('rowHoursStaff');

    if (mode === 'student') {
        btnStu.className = 'btn btn-primary';
        btnStf.className = 'btn btn-light';
        rowStu.style.display = 'contents';
        rowStf.style.display = 'none';
    } else {
        btnStu.className = 'btn btn-light';
        btnStf.className = 'btn btn-primary';
        rowStu.style.display = 'none';
        rowStf.style.display = 'contents';
    }
}

function toggleHolidayFields() {
    const isHol = document.getElementById('selectIsHoliday').value === 'yes';
    const rowStu = document.getElementById('rowHoursStudent');
    const rowStf = document.getElementById('rowHoursStaff');
    const currentMode = document.getElementById('inputTargetType').value;

    if (isHol) {
        rowStu.style.display = 'none';
        rowStf.style.display = 'none';
    } else {
        if (currentMode === 'student') rowStu.style.display = 'contents';
        else rowStf.style.display = 'contents';
    }
}
</script>

<?php require '../../includes/footer.php'; ?>
