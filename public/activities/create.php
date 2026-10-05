<?php
require_once '../../config/config.php'; 
require_once '../../config/database.php'; 
require_once '../../config/auth.php'; 
require_once '../../config/security.php'; 
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_activities');
$currentRole = currentRole();
$pageTitle = 'Tambah Activity / Kegiatan Khusus';
$userId = currentUserId();

$initialType = in_array($_GET['tab'] ?? '', ['student', 'staff']) ? $_GET['tab'] : 'student';

$ksUnitId = 0;
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();
}

$years = $pdo->query("SELECT * FROM academic_years ORDER BY start_date DESC")->fetchAll();
$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetType = in_array($_POST['target_type'] ?? '', ['student', 'staff']) ? $_POST['target_type'] : 'student';
    $unitId = $currentRole === 'super_admin' ? (int)$_POST['unit_id'] : $ksUnitId;
    $activityDate = $_POST['activity_date'];
    $isHoliday = $_POST['is_holiday'] ?? 'no';
    $name = trim($_POST['name'] ?? '');
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

    if ($status === 'active') {
        $stmtClose = $pdo->prepare("UPDATE activities SET status = 'closed' WHERE activity_date = ? AND unit_id = ? AND target_type = ? AND status = 'active'");
        $stmtClose->execute([$activityDate, $unitId, $targetType]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities 
        (academic_year_id, unit_id, target_type, name, activity_date, is_holiday, student_in, student_late, student_out, staff_in, staff_late, staff_out, description, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $academicYearId, $unitId, $targetType, $name, $activityDate, $isHoliday,
        $studentIn, $studentLate, $studentOut,
        $staffIn, $staffLate, $staffOut,
        $description ?: null, $status
    ]);

    flash('success', 'Kegiatan khusus ' . ($targetType === 'student' ? 'Siswa' : 'Staff') . ' berhasil dibuat.');
    redirect('index.php?tab=' . $targetType);
}

require '../../includes/header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h3 style="margin: 0;"><i class="fa-solid fa-calendar-plus" style="color:#0284c7; margin-right:8px;"></i> Tambah Kegiatan Khusus (Activity)</h3>
            <p style="color: #64748b; margin: 4px 0 0 0;">Pilih sasaran kegiatan apakah untuk Siswa (KBM/Ujian/Libur) atau Staff & Guru (Raker/Dinas/Libur).</p>
        </div>

        <!-- MODE SELECTOR BUTTONS -->
        <div style="display: flex; gap: 8px;">
            <button type="button" id="btnModeStudent" class="btn <?= $initialType === 'student' ? 'btn-primary' : 'btn-light' ?>" onclick="switchActivityMode('student')" style="border: 1px solid #cbd5e1; font-weight: 700;">
                <i class="fa-solid fa-graduation-cap"></i> 🎓 Kegiatan Siswa
            </button>
            <button type="button" id="btnModeStaff" class="btn <?= $initialType === 'staff' ? 'btn-primary' : 'btn-light' ?>" onclick="switchActivityMode('staff')" style="border: 1px solid #cbd5e1; font-weight: 700;">
                <i class="fa-solid fa-user-tie"></i> 💼 Kegiatan Staff & Guru
            </button>
        </div>
    </div>

    <form method="POST">
        <input type="hidden" name="target_type" id="inputTargetType" value="<?= e($initialType) ?>">

        <div class="form-grid">
            <div class="form-group">
                <label>Unit Sekolah *</label>
                <?php if ($currentRole === 'super_admin'): ?>
                    <select name="unit_id" required>
                        <option value="">-- Pilih Unit Sekolah --</option>
                        <?php foreach($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php 
                        $unitNameStr = '-';
                        foreach ($units as $u) {
                            if($u['id'] == $ksUnitId) { $unitNameStr = $u['unit']; break; }
                        }
                    ?>
                    <input type="text" value="<?= e($unitNameStr) ?>" disabled style="background:#f1f5f9; cursor:not-allowed;">
                    <small style="color:#0284c7;">Otomatis ditambahkan ke unit wewenang Anda.</small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Tahun Ajaran *</label>
                <select name="academic_year_id" required>
                    <option value="">-- Pilih Tahun Ajaran --</option>
                    <?php foreach($years as $y): ?>
                        <option value="<?= $y['id'] ?>" <?= $y['status'] === 'active' ? 'selected' : '' ?>><?= e($y['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label id="lblActivityName">Nama Kegiatan Siswa *</label>
                <input type="text" name="name" required placeholder="Contoh: Ujian Tengah Semester, Pesantren Kilat, Upacara...">
            </div>

            <div class="form-group">
                <label>Tanggal Pelaksanaan *</label>
                <input type="date" name="activity_date" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-group">
                <label>Hari Libur?</label>
                <select name="is_holiday" id="selectIsHoliday" onchange="toggleHolidayFields()">
                    <option value="no" selected>Tidak (Masuk & Absen Berjalan)</option>
                    <option value="yes">Ya (Libur Khusus / Mesin Absen Mati)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Status Kegiatan</label>
                <select name="status">
                    <option value="active" selected>Aktif</option>
                    <option value="closed">Ditutup</option>
                </select>
            </div>

            <!-- JAM SISWA (TAMPIL HANYA JIKA MODE SISWA) -->
            <div id="rowHoursStudent" style="<?= $initialType === 'student' ? 'display:contents;' : 'display:none;' ?>">
                <div class="form-group"><label>Siswa: Jam Masuk</label><input type="time" name="student_in" value="07:00"></div>
                <div class="form-group"><label>Siswa: Batas Terlambat</label><input type="time" name="student_late" value="07:15"></div>
                <div class="form-group"><label>Siswa: Jam Pulang</label><input type="time" name="student_out" value="14:00"></div>
            </div>

            <!-- JAM STAFF (TAMPIL HANYA JIKA MODE STAFF) -->
            <div id="rowHoursStaff" style="<?= $initialType === 'staff' ? 'display:contents;' : 'display:none;' ?>">
                <div class="form-group"><label>Staff: Jam Masuk</label><input type="time" name="staff_in" value="06:45"></div>
                <div class="form-group"><label>Staff: Batas Terlambat</label><input type="time" name="staff_late" value="07:00"></div>
                <div class="form-group"><label>Staff: Jam Pulang</label><input type="time" name="staff_out" value="15:30"></div>
            </div>

            <div class="form-group full">
                <label>Keterangan Tambahan (Opsional)</label>
                <textarea name="description" placeholder="Catatan atau keterangan kegiatan..."></textarea>
            </div>
        </div>

        <div style="margin-top:24px; display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700;">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Kegiatan
            </button>
            <a href="index.php?tab=<?= e($initialType) ?>" class="btn btn-light" style="border: 1px solid #cbd5e1; padding: 10px 18px;">Batal</a>
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
    const lblName = document.getElementById('lblActivityName');

    if (mode === 'student') {
        btnStu.className = 'btn btn-primary';
        btnStf.className = 'btn btn-light';
        rowStu.style.display = 'contents';
        rowStf.style.display = 'none';
        lblName.innerText = 'Nama Kegiatan Siswa *';
    } else {
        btnStu.className = 'btn btn-light';
        btnStf.className = 'btn btn-primary';
        rowStu.style.display = 'none';
        rowStf.style.display = 'contents';
        lblName.innerText = 'Nama Kegiatan Staff & Guru *';
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
