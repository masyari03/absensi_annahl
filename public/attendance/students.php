<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('attendance_students');
$currentRole = currentRole();
$pageTitle = 'Absensi Siswa';
$userId = currentUserId();

// ==============================================================================
// SISTEM PENGATURAN AKSES KEPALA SEKOLAH (AUTO-CREATE TABLE JIKA BELUM ADA)
// ==============================================================================
$pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255)
)");

// Proses Toggle Akses (Hanya Super Admin yang bisa melakukan ini)
if ($currentRole === 'super_admin' && isset($_POST['toggle_ks_access'])) {
    $key = $_POST['setting_key'];
    $val = $_POST['setting_value'] === '1' ? '0' : '1'; // Balikkan nilai (Toggle)
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$key, $val, $val]);
    flash('success', 'Hak akses Kepala Sekolah berhasil diperbarui.');
    redirect('students.php');
}

// Ambil Status Akses Saat Ini
$ksCanDelete = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ks_delete_attendance'")->fetchColumn() === '1';
$ksCanEditTime = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ks_edit_time'")->fetchColumn() === '1';

// Logika Siapa yang Boleh Hapus
$canDelete = ($currentRole === 'super_admin' || ($currentRole === 'kepala_sekolah' && $ksCanDelete));

// ==============================================================================
// PROSES CATAT KEHADIRAN CEPAT PETUGAS (QUICK ATTENDANCE)
// ==============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['quick_record_attendance'])) {
    $stuId = (int)$_POST['quick_student_id'];
    $actId = (int)$_POST['quick_activity_id'];
    $attDate = $_POST['quick_date'] ?? date('Y-m-d');
    $newStat = $_POST['quick_status'] ?? 'tepat_waktu';
    $ket = trim($_POST['quick_keterangan'] ?? 'Dicatat Petugas');
    $timeIn = in_array($newStat, ['tepat_waktu', 'terlambat']) ? date('H:i:s') : null;

    $stmtEnr = $pdo->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND status = 'active' LIMIT 1");
    $stmtEnr->execute([$stuId]);
    $enrId = $stmtEnr->fetchColumn();

    if ($enrId) {
        // Jika activity_id belum ada, cari atau buat
        if (!$actId) {
            $stmtStuUnit = $pdo->prepare("SELECT g.unit_id FROM students s JOIN student_enrollments se ON se.student_id = s.id JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id WHERE s.id = ? LIMIT 1");
            $stmtStuUnit->execute([$stuId]);
            $sUnitId = (int)$stmtStuUnit->fetchColumn();

            $stmtAct = $pdo->prepare("SELECT id FROM activities WHERE activity_date = ? AND unit_id = ? LIMIT 1");
            $stmtAct->execute([$attDate, $sUnitId]);
            $actId = (int)$stmtAct->fetchColumn();
        }

        $stmtCheck = $pdo->prepare("SELECT id FROM student_attendances WHERE student_id = ? AND attendance_date = ? LIMIT 1");
        $stmtCheck->execute([$stuId, $attDate]);
        $existingId = $stmtCheck->fetchColumn();

        if ($existingId) {
            $pdo->prepare("UPDATE student_attendances SET status = ?, time_in = COALESCE(time_in, ?), keterangan = ? WHERE id = ?")
                ->execute([$newStat, $timeIn, $ket, $existingId]);
        } else {
            $pdo->prepare("INSERT INTO student_attendances (student_id, enrollment_id, activity_id, attendance_date, time_in, status, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$stuId, $enrId, $actId ?: 1, $attDate, $timeIn, $newStat, $ket]);
        }
        flash('success', 'Kehadiran siswa berhasil dicatat oleh petugas.');
    } else {
        flash('error', 'Siswa tidak memiliki enrollment aktif.');
    }
    redirect('students.php?' . http_build_query($_GET));
    exit;
}

// ==============================================================================
// PROSES HAPUS MASSAL (BULK DELETE)
// ==============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['bulk_delete'])) {
    if ($canDelete && !empty($_POST['attendance_ids'])) {
        $ids = array_filter(array_map('intval', $_POST['attendance_ids']));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtDelete = $pdo->prepare("DELETE FROM student_attendances WHERE id IN ($placeholders)");
            $stmtDelete->execute($ids);
            flash('success', count($ids) . ' data absensi terpilih berhasil dihapus.');
        } else {
            flash('error', 'Data absensi yang dipilih tidak valid.');
        }
        redirect('students.php?' . http_build_query($_GET));
        exit;
    } else {
        flash('error', 'Tidak ada data yang dipilih atau Anda tidak memiliki akses.');
    }
}

$name = trim($_GET['name'] ?? '');
$status = $_GET['status'] ?? '';
$dateStart = $_GET['date_start'] ?? date('Y-m-d');
$dateEnd = $_GET['date_end'] ?? date('Y-m-d');
$gradeId = (int)($_GET['grade_id'] ?? 0);
$classId = (int)($_GET['class_group_id'] ?? 0);

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

// Menyesuaikan Dropdown Filter berdasarkan Role (Filter Unit hanya untuk Super Admin)
if ($currentRole === 'super_admin') {
    $unitId = (int)($_GET['unit_id'] ?? 0);
    if ($unitId > 0) {
        $stmtGrades = $pdo->prepare("SELECT * FROM grades WHERE unit_id = ? ORDER BY sort_order, grade");
        $stmtGrades->execute([$unitId]);
        $grades = $stmtGrades->fetchAll();

        $stmtCg = $pdo->prepare("SELECT cg.id, cg.name, g.grade FROM class_groups cg INNER JOIN grades g ON g.id = cg.grade_id WHERE g.unit_id = ? ORDER BY g.sort_order, cg.name");
        $stmtCg->execute([$unitId]);
        $classGroups = $stmtCg->fetchAll();
    } else {
        $grades = $pdo->query("SELECT * FROM grades ORDER BY sort_order, grade")->fetchAll();
        $classGroups = $pdo->query("SELECT cg.id, cg.name, g.grade FROM class_groups cg INNER JOIN grades g ON g.id = cg.grade_id ORDER BY g.sort_order, cg.name")->fetchAll();
    }
} elseif ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    $unitId = $ksUnitId ? (int)$ksUnitId : -1;

    $stmtGrades = $pdo->prepare("SELECT * FROM grades WHERE unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?) ORDER BY sort_order, grade");
    $stmtGrades->execute([$userId]);
    $grades = $stmtGrades->fetchAll();
    
    $stmtCg = $pdo->prepare("SELECT cg.id, cg.name, g.grade FROM class_groups cg INNER JOIN grades g ON g.id = cg.grade_id WHERE g.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?) ORDER BY g.sort_order, cg.name");
    $stmtCg->execute([$userId]);
    $classGroups = $stmtCg->fetchAll();
} else {
    $unitId = 0;
    $grades = [];
    $classGroups = [];
}

$access = getStudentAccessCondition('g', 'cg');

$isDayView = ($dateStart === $dateEnd);

// ==============================================================================
// LOGIKA TAMPILAN:
// JIKA LIHAT HARI AKTIVITAS TUNGGAL ($isDayView):
// Tampilkan SELURUH siswa (termasuk yang belum absen) agar petugas bisa cek anak terlewat
// ==============================================================================
if ($isDayView) {
    // 1. Auto-inisialisasi kegiatan KBM aktif dari weekly_schedules jika belum ada di tabel activities
    $dayCode = (int)date('N', strtotime($dateStart));
    $targetUnits = ($unitId > 0) ? [$unitId] : array_column($units, 'id');
    if (!empty($targetUnits)) {
        $unitPlaceholders = implode(',', array_fill(0, count($targetUnits), '?'));
        $stmtEx = $pdo->prepare("SELECT unit_id FROM activities WHERE activity_date = ? AND unit_id IN ($unitPlaceholders)");
        $stmtEx->execute(array_merge([$dateStart], $targetUnits));
        $existingActs = $stmtEx->fetchAll(PDO::FETCH_COLUMN);

        $stmtWk = $pdo->prepare("SELECT * FROM weekly_schedules WHERE day_code = ? AND is_active = 'active' AND unit_id IN ($unitPlaceholders)");
        $stmtWk->execute(array_merge([$dayCode], $targetUnits));
        $neededSchedules = $stmtWk->fetchAll(PDO::FETCH_ASSOC);

        $ayId = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1")->fetchColumn() ?: 1;
        $insertAct = $pdo->prepare("INSERT INTO activities (academic_year_id, unit_id, name, activity_date, student_in, student_late, student_out, staff_in, staff_late, staff_out, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");

        foreach ($neededSchedules as $ns) {
            if (!in_array($ns['unit_id'], $existingActs)) {
                try {
                    $insertAct->execute([
                        $ayId,
                        $ns['unit_id'],
                        "KBM Reguler",
                        $dateStart,
                        $ns['student_in'],
                        $ns['student_late'],
                        $ns['student_out'],
                        $ns['staff_in'],
                        $ns['staff_late'],
                        $ns['staff_out']
                    ]);
                    $existingActs[] = $ns['unit_id'];
                } catch (Exception $e) {}
            }
        }
    }

    // 2. Query SEMUA siswa aktif pada hari aktivitas dengan LEFT JOIN student_attendances
    $where = [
        "s.deleted_at IS NULL",
        "(a.id IS NOT NULL OR sa.id IS NOT NULL)",
        $access['condition']
    ];
    $params = [$dateStart, $dateStart, $dateStart];
    $params = array_merge($params, $access['params']);

    if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
    if ($unitId > 0) { $where[] = "g.unit_id = ?"; $params[] = $unitId; }
    if ($gradeId > 0) { $where[] = "g.id = ?"; $params[] = $gradeId; }
    if ($classId > 0) { $where[] = "cg.id = ?"; $params[] = $classId; }

    if ($status === 'belum_absen') {
        $where[] = "(sa.id IS NULL OR sa.status = '' OR sa.status = 'tidak_absen' OR sa.status = 'alpha')";
    } elseif (in_array($status, ['tepat_waktu', 'terlambat', 'izin', 'sakit'])) {
        $where[] = "sa.status = ?"; $params[] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT 
            s.id AS student_id,
            s.name, 
            s.nis, 
            s.photo, 
            se.id AS enrollment_id,
            g.grade, 
            cg.name AS class_name, 
            un.unit AS unit_name,
            un.id AS unit_id,
            COALESCE(a.id, sa.activity_id) AS activity_id,
            COALESCE(a.name, 'KBM Reguler') AS activity_name,
            sa.id,
            COALESCE(sa.attendance_date, a.activity_date, ?) AS attendance_date,
            sa.time_in,
            sa.time_out,
            sa.status,
            sa.keterangan
        FROM students s
        INNER JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
        INNER JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
        INNER JOIN class_groups cg ON cg.id = se.class_group_id
        INNER JOIN grades g ON g.id = cg.grade_id
        LEFT JOIN units un ON un.id = g.unit_id
        LEFT JOIN activities a ON a.unit_id = g.unit_id AND a.activity_date = ?
        LEFT JOIN student_attendances sa ON sa.student_id = s.id AND sa.attendance_date = ?
        WHERE {$whereSql}
        ORDER BY un.id ASC, g.sort_order ASC, cg.name ASC, s.name ASC
    ";
} else {
    // MODE RENTANG TANGGAL (HISTORIS): Tampilkan log kehadiran yang sudah tercatat
    $where = [
        "s.deleted_at IS NULL",
        "sa.attendance_date BETWEEN ? AND ?",
        $access['condition']
    ];
    $params = [$dateStart, $dateEnd];
    $params = array_merge($params, $access['params']);

    if ($name !== '') { $where[] = "s.name LIKE ?"; $params[] = "%{$name}%"; }
    if ($unitId > 0) { $where[] = "g.unit_id = ?"; $params[] = $unitId; }
    if ($gradeId > 0) { $where[] = "g.id = ?"; $params[] = $gradeId; }
    if ($classId > 0) { $where[] = "cg.id = ?"; $params[] = $classId; }

    if ($status === 'belum_absen') {
        $where[] = "(sa.id IS NULL OR sa.status = '' OR sa.status = 'tidak_absen' OR sa.status = 'alpha')";
    } elseif (in_array($status, ['tepat_waktu', 'terlambat', 'izin', 'sakit'])) {
        $where[] = "sa.status = ?"; $params[] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT 
            sa.*, sa.student_id, s.name, s.nis, s.photo, 
            g.grade, cg.name AS class_name, 
            a.name AS activity_name,
            un.unit AS unit_name
        FROM student_attendances sa
        INNER JOIN students s ON s.id = sa.student_id
        INNER JOIN student_enrollments se ON se.id = sa.enrollment_id
        INNER JOIN class_groups cg ON cg.id = se.class_group_id
        INNER JOIN grades g ON g.id = cg.grade_id
        LEFT JOIN units un ON un.id = g.unit_id
        INNER JOIN activities a ON a.id = sa.activity_id
        WHERE {$whereSql}
        ORDER BY sa.attendance_date DESC, sa.id DESC, s.name
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung Statistik Ringkasan untuk Petugas
$statTotal = count($attendances);
$statHadir = 0;
$statTepat = 0;
$statTelat = 0;
$statIzin = 0;
$statSakit = 0;
$statBelum = 0;

foreach ($attendances as $row) {
    $isBelum = empty($row['id']) || empty($row['status']) || in_array($row['status'], ['tidak_absen', 'alpha']);
    if ($isBelum) {
        $statBelum++;
    } else {
        $statHadir++;
        if ($row['status'] === 'tepat_waktu') $statTepat++;
        elseif ($row['status'] === 'terlambat') $statTelat++;
        elseif ($row['status'] === 'izin') $statIzin++;
        elseif ($row['status'] === 'sakit') $statSakit++;
    }
}

require '../../includes/header.php';
?>

<?php if ($currentRole === 'super_admin'): ?>
<!-- PANEL PENGATURAN HAK AKSES KEPALA SEKOLAH -->
<div class="card" style="margin-bottom: 20px; background: #f8fafc; border: 1px solid #cbd5e1;">
    <div style="padding: 15px 20px;">
        <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #334155;">⚙️ Panel Akses: Hak Kepala Sekolah</h4>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="toggle_ks_access" value="1">
                <input type="hidden" name="setting_key" value="ks_delete_attendance">
                <input type="hidden" name="setting_value" value="<?= $ksCanDelete ? '1' : '0' ?>">
                <button type="submit" class="btn <?= $ksCanDelete ? 'btn-success' : 'btn-danger' ?>" style="font-size: 12px; padding: 7px 12px;">
                    Akses Hapus Absensi: <?= $ksCanDelete ? 'ON (Bisa Hapus)' : 'OFF (Terkunci)' ?>
                </button>
            </form>
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="toggle_ks_access" value="1">
                <input type="hidden" name="setting_key" value="ks_edit_time">
                <input type="hidden" name="setting_value" value="<?= $ksCanEditTime ? '1' : '0' ?>">
                <button type="submit" class="btn <?= $ksCanEditTime ? 'btn-success' : 'btn-danger' ?>" style="font-size: 12px; padding: 7px 12px;">
                    Akses Edit Jam: <?= $ksCanEditTime ? 'ON (Bisa Edit)' : 'OFF (Terkunci)' ?>
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Absensi Siswa</h3>
            <small>Data otomatis disesuaikan dengan hak akses dan jadwal kegiatan sekolah.</small>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="create_student.php" class="btn btn-primary">+ Input Izin / Sakit</a>
        </div>
    </div>

    <form method="GET">
        <div class="filter-grid">
            <div class="form-group">
                <label>Dari Tanggal</label>
                <input type="date" name="date_start" value="<?= e($dateStart) ?>">
            </div>
            <div class="form-group">
                <label>Sampai Tanggal</label>
                <input type="date" name="date_end" value="<?= e($dateEnd) ?>">
            </div>
            <div class="form-group">
                <label>Nama Siswa</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="Cari nama...">
            </div>

            <?php if ($currentRole === 'super_admin'): ?>
            <div class="form-group">
                <label>Unit</label>
                <select name="unit_id" onchange="this.form.submit()">
                    <option value="0">Semua Unit</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $unitId == $u['id'] ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if (in_array($currentRole, ['super_admin', 'kepala_sekolah'])): ?>
            <div class="form-group">
                <label>Grade</label>
                <select name="grade_id">
                    <option value="0">Semua Grade</option>
                    <?php foreach ($grades as $grade): ?>
                        <option value="<?= $grade['id'] ?>" <?= $gradeId == $grade['id'] ? 'selected' : '' ?>><?= e($grade['grade']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Subkelas</label>
                <select name="class_group_id">
                    <option value="0">Semua Subkelas</option>
                    <?php foreach ($classGroups as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= $classId == $class['id'] ? 'selected' : '' ?>><?= e($class['grade']) ?> - <?= e($class['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">Semua Status</option>
                    <option value="belum_absen" <?= $status === 'belum_absen' ? 'selected' : '' ?>>⚠️ Belum Absen (Anak Terlewat)</option>
                    <option value="tepat_waktu" <?= $status === 'tepat_waktu' ? 'selected' : '' ?>>Tepat Waktu</option>
                    <option value="terlambat" <?= $status === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                    <option value="izin" <?= $status === 'izin' ? 'selected' : '' ?>>Izin</option>
                    <option value="sakit" <?= $status === 'sakit' ? 'selected' : '' ?>>Sakit</option>
                </select>
            </div>
            <div>
                <button class="btn btn-primary" type="submit" style="margin-top:22px;">🔎 Cari</button>
            </div>
        </div>
    </form>
</div>

<!-- ============================================================================== -->
<!-- STATISTIK RINGKASAN PEMANTAUAN UNTUK PETUGAS                                    -->
<!-- ============================================================================== -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px;">
    <div style="background: white; padding: 14px 18px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.03);">
        <small style="color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase;">Total Siswa</small>
        <div style="font-size: 22px; font-weight: 800; color: #0f172a; margin-top: 2px;"><?= number_format($statTotal) ?></div>
        <small style="color: #94a3b8; font-size: 11px;">Terdaftar di filter</small>
    </div>

    <div style="background: white; padding: 14px 18px; border-radius: 12px; border: 1px solid #bbf7d0; box-shadow: 0 2px 4px rgba(0,0,0,0.03); border-left: 4px solid #10b981;">
        <small style="color: #15803d; font-weight: 600; font-size: 11px; text-transform: uppercase;">Sudah Hadir Masuk</small>
        <div style="font-size: 22px; font-weight: 800; color: #166534; margin-top: 2px;"><?= number_format($statHadir) ?></div>
        <small style="color: #15803d; font-size: 11px;">Tepat: <?= $statTepat ?> | Telat: <?= $statTelat ?></small>
    </div>

    <div style="background: white; padding: 14px 18px; border-radius: 12px; border: 1px solid #fed7aa; box-shadow: 0 2px 4px rgba(0,0,0,0.03); border-left: 4px solid #f97316;">
        <small style="color: #c2410c; font-weight: 700; font-size: 11px; text-transform: uppercase;">⚠️ Belum Absen (Terlewat)</small>
        <div style="font-size: 22px; font-weight: 800; color: #ea580c; margin-top: 2px; display: flex; align-items: center; justify-content: space-between;">
            <span><?= number_format($statBelum) ?></span>
            <?php if ($status !== 'belum_absen' && $statBelum > 0): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'belum_absen'])) ?>" class="btn btn-sm" style="background:#ffedd5; color:#c2410c; font-size:11px; font-weight:700; padding:3px 8px; border:1px solid #fed7aa;">Filter Cek</a>
            <?php endif; ?>
        </div>
        <small style="color: #9a3412; font-size: 11px;">Perlu dicek oleh petugas</small>
    </div>

    <div style="background: white; padding: 14px 18px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.03); border-left: 4px solid #3b82f6;">
        <small style="color: #1d4ed8; font-weight: 600; font-size: 11px; text-transform: uppercase;">Izin / Sakit</small>
        <div style="font-size: 22px; font-weight: 800; color: #1e40af; margin-top: 2px;"><?= number_format($statIzin + $statSakit) ?></div>
        <small style="color: #64748b; font-size: 11px;">Izin: <?= $statIzin ?> | Sakit: <?= $statSakit ?></small>
    </div>
</div>

<?php if ($isDayView): ?>
<div style="display: flex; align-items: center; justify-content: space-between; background: #eff6ff; border: 1px solid #bfdbfe; padding: 10px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; color: #1e40af;">
    <div>
        <i class="fa-solid fa-circle-check" style="color: #2563eb; margin-right: 6px;"></i>
        <strong>Mode Hari Aktivitas Aktif (Tanggal <?= date('d M Y', strtotime($dateStart)) ?>):</strong> Menampilkan <strong>seluruh siswa</strong> di unit/kelas terjadwal (termasuk yang belum absen), memudahkan petugas memeriksa siswa yang terlewat.
    </div>
    <?php if ($status === 'belum_absen'): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['status' => ''])) ?>" class="btn btn-sm" style="background:#dbeafe; color:#1e40af; font-weight:700; border:1px solid #93c5fd; font-size:11px;">Tampilkan Semua</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <form method="POST" id="bulkDeleteForm" onsubmit="return confirmBulkDelete(event)">
        <input type="hidden" name="bulk_delete" value="1">
        
        <?php if ($canDelete): ?>
        <div style="padding: 15px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: flex-start;">
            <button type="submit" class="btn" style="background-color: #ef4444; color: white; font-size: 13px;">
                🗑️ Hapus Data Terpilih
            </button>
        </div>
        <?php endif; ?>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <?php if ($canDelete): ?>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" id="selectAll" title="Pilih Semua">
                        </th>
                        <?php endif; ?>
                        <th>Tanggal</th>
                        <th>Nama</th>
                        <?php if ($currentRole === 'super_admin'): ?><th>Unit</th><?php endif; ?>
                        <th>Subkelas</th>
                        <th>Jam Masuk</th>
                        <th>Jam Keluar</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                        <th style="min-width: 130px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendances)): ?>
                    <tr>
                        <td colspan="<?= $canDelete ? ($currentRole === 'super_admin' ? 10 : 9) : ($currentRole === 'super_admin' ? 9 : 8) ?>" style="text-align: center; padding: 30px; color: #94a3b8;">
                            <i class="fa-solid fa-clipboard-question" style="font-size: 30px; margin-bottom: 10px; display: block; color: #cbd5e1;"></i>
                            Tidak ada data siswa ditemukan untuk kriteria filter ini.
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($attendances as $attendance): 
                        $hasRecord = !empty($attendance['id']);
                        $isBelumAbsen = !$hasRecord || empty($attendance['status']) || in_array($attendance['status'], ['tidak_absen', 'alpha']);
                    ?>
                    <tr style="<?= $isBelumAbsen ? 'background-color: #fffbf5;' : '' ?>">
                        <?php if ($canDelete): ?>
                        <td style="text-align: center;">
                            <?php if ($hasRecord): ?>
                                <input type="checkbox" name="attendance_ids[]" value="<?= $attendance['id'] ?>" class="checkItem">
                            <?php else: ?>
                                <span style="color: #cbd5e1;">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><?= e($attendance['attendance_date']) ?></td>
                        <td>
                            <strong><?= e($attendance['name']) ?></strong>
                            <?php if (!empty($attendance['nis'])): ?>
                                <br><small style="color: #94a3b8;">NIS: <?= e($attendance['nis']) ?></small>
                            <?php endif; ?>
                        </td>
                        <?php if ($currentRole === 'super_admin'): ?>
                            <td><span class="badge" style="background:#0284c7; color:white; font-size:11px;"><?= e($attendance['unit_name'] ?? '-') ?></span></td>
                        <?php endif; ?>
                        <td><?= e($attendance['class_name']) ?></td>
                        <td>
                            <?php if (!empty($attendance['time_in']) && $attendance['time_in'] !== '00:00:00'): ?>
                                <span style="font-weight: 600; color: #0f172a;"><?= e($attendance['time_in']) ?></span>
                            <?php else: ?>
                                <span style="color:#ef4444; font-weight:600; font-size:12px;"><i class="fa-solid fa-circle-xmark"></i> Belum Masuk</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($attendance['time_out']) && $attendance['time_out'] !== '00:00:00'): ?>
                                <span style="font-weight: 600; color: #0f172a;"><?= e($attendance['time_out']) ?></span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:12px;">tidak absen</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isBelumAbsen): ?>
                                <span class="badge" style="background: #f59e0b; color: white; font-weight: 700;">
                                    <i class="fa-solid fa-clock"></i> Belum Absen
                                </span>
                            <?php elseif ($attendance['status'] === 'tepat_waktu'): ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Tepat Waktu</span>
                            <?php elseif ($attendance['status'] === 'terlambat'): ?>
                                <span class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> Terlambat</span>
                            <?php elseif ($attendance['status'] === 'izin'): ?>
                                <span class="badge badge-info" style="background:#3b82f6; color:white;"><i class="fa-solid fa-envelope-open-text"></i> Izin</span>
                            <?php elseif ($attendance['status'] === 'sakit'): ?>
                                <span class="badge badge-warning" style="background:#f59e0b; color:white;"><i class="fa-solid fa-notes-medical"></i> Sakit</span>
                            <?php else: ?>
                                <span class="badge" style="background:#64748b; color:white;"><?= e($attendance['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= e($attendance['keterangan'] ?? '-') ?></small></td>
                        <td>
                            <?php if ($hasRecord): ?>
                                <a href="edit_student.php?id=<?= $attendance['id'] ?>" class="btn btn-success" style="padding: 4px 10px; font-size:12px;">Edit</a>
                            <?php else: ?>
                                <!-- AKSI CEPAT PETUGAS UNTUK SISWA BELUM ABSEN (TERLEWAT) -->
                                <div style="display: inline-flex; gap: 4px; flex-wrap: wrap; align-items: center;">
                                    <form method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Tandai <?= addslashes(e($attendance['name'])) ?> HADIR sekarang?');">
                                        <input type="hidden" name="quick_record_attendance" value="1">
                                        <input type="hidden" name="quick_student_id" value="<?= $attendance['student_id'] ?>">
                                        <input type="hidden" name="quick_activity_id" value="<?= $attendance['activity_id'] ?? 0 ?>">
                                        <input type="hidden" name="quick_date" value="<?= e($attendance['attendance_date']) ?>">
                                        <input type="hidden" name="quick_status" value="tepat_waktu">
                                        <button type="submit" class="btn btn-sm" style="background: #10b981; color: white; padding: 3px 8px; font-size: 11px; font-weight: 700; border-radius: 4px;" title="Tandai Hadir Tepat Waktu Langsung">
                                            ✓ Hadir
                                        </button>
                                    </form>

                                    <a href="create_student.php?student_id=<?= $attendance['student_id'] ?>&attendance_date=<?= e($attendance['attendance_date']) ?>&status=izin" class="btn btn-sm" style="background: #3b82f6; color: white; padding: 3px 8px; font-size: 11px; font-weight: 600; border-radius: 4px;" title="Input Izin">
                                        ℹ Izin
                                    </a>

                                    <a href="create_student.php?student_id=<?= $attendance['student_id'] ?>&attendance_date=<?= e($attendance['attendance_date']) ?>&status=sakit" class="btn btn-sm" style="background: #f59e0b; color: white; padding: 3px 8px; font-size: 11px; font-weight: 600; border-radius: 4px;" title="Input Sakit">
                                        ♥ Sakit
                                    </a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
// Script untuk Handle Checkbox Pilih Semua
document.getElementById('selectAll')?.addEventListener('change', function(e) {
    let checkboxes = document.querySelectorAll('.checkItem');
    checkboxes.forEach(cb => cb.checked = e.target.checked);
});

// Konfirmasi Hapus Massal
function confirmBulkDelete(e) {
    let checked = document.querySelectorAll('.checkItem:checked').length;
    if (checked === 0) {
        alert('Pilih minimal satu data absensi untuk dihapus.');
        e.preventDefault();
        return false;
    }
    if(!confirm('Yakin ingin menghapus ' + checked + ' data absensi terpilih secara permanen?')) {
        e.preventDefault();
        return false;
    }
    return true;
}
</script>

<?php require '../../includes/footer.php'; ?>
