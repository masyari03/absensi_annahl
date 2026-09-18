<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

$currentRole = $_SESSION['role'] ?? '';
if (!in_array($currentRole, ['super_admin', 'kepala_sekolah'])) {
    redirect('../dashboard.php');
}

$pageTitle = 'Input Manual Absensi Staff';
$userId = currentUserId();

// Ambil daftar Staff sesuai hak akses Unit
$where = "s.deleted_at IS NULL";
$params = [];
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    $where .= " AND s.unit_id = ?";
    $params[] = $ksUnitId;
}

$stmtStaff = $pdo->prepare("SELECT s.id, s.name, s.unit_id, un.unit as unit_name FROM staff s LEFT JOIN units un ON un.id = s.unit_id WHERE {$where} ORDER BY un.unit, s.name");
$stmtStaff->execute($params);
$staffList = $stmtStaff->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staffId = (int)$_POST['staff_id'];
    $date = $_POST['attendance_date'];
    $status = $_POST['status'];
    $keterangan = trim($_POST['keterangan']);
    
    $selectedStaff = null;
    foreach ($staffList as $s) {
        if ($s['id'] == $staffId) { $selectedStaff = $s; break; }
    }

    if ($selectedStaff) {
        $unitId = $selectedStaff['unit_id'];
        $dayCode = date('N', strtotime($date));

        // 1. Cek atau Buat Activity untuk hari itu
        $stmtAct = $pdo->prepare("SELECT id FROM activities WHERE activity_date = ? AND unit_id = ? LIMIT 1");
        $stmtAct->execute([$date, $unitId]);
        $activityId = $stmtAct->fetchColumn();

        if (!$activityId) {
            $stmtWk = $pdo->prepare("SELECT * FROM weekly_schedules WHERE day_code = ? AND unit_id = ? AND is_active = 'active' LIMIT 1");
            $stmtWk->execute([$dayCode, $unitId]);
            $weekly = $stmtWk->fetch();
            
            if ($weekly) {
                $ayId = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1")->fetchColumn() ?: 1;
                $insertAct = $pdo->prepare("INSERT INTO activities (academic_year_id, unit_id, name, activity_date, student_in, student_late, student_out, staff_in, staff_late, staff_out, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $insertAct->execute([$ayId, $unitId, "KBM Reguler " . $weekly['day_name'], $date, $weekly['student_in'], $weekly['student_late'], $weekly['student_out'], $weekly['staff_in'], $weekly['staff_late'], $weekly['staff_out']]);
                $activityId = $pdo->lastInsertId();
            } else {
                flash('error', 'Gagal: Tidak ada jadwal kerja / hari libur pada tanggal tersebut.');
                redirect('create_staff.php');
                exit;
            }
        }

        // 2. Cek apakah sudah absen
        $check = $pdo->prepare("SELECT id FROM staff_attendances WHERE staff_id = ? AND activity_id = ?");
        $check->execute([$staffId, $activityId]);
        if ($check->fetch()) {
            flash('error', 'Staff sudah memiliki data absensi pada tanggal tersebut.');
        } else {
            // 3. Insert Data Manual
            $insert = $pdo->prepare("INSERT INTO staff_attendances (staff_id, activity_id, attendance_date, time_in, time_out, status, keterangan) VALUES (?, ?, ?, NULL, NULL, ?, ?)");
            $insert->execute([$staffId, $activityId, $date, $status, $keterangan]);
            flash('success', 'Data kehadiran manual staff berhasil ditambahkan.');
            redirect('staff.php');
            exit;
        }
    } else {
        flash('error', 'Staff tidak valid atau di luar hak akses Anda.');
    }
}

require '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-header">
        <h3>+ Input Absensi Manual (Staff)</h3>
    </div>
    
    <form method="POST" style="padding: 20px;">
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:8px; font-weight:600;">Tanggal</label>
            <input type="date" name="attendance_date" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
        </div>
        
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:8px; font-weight:600;">Pilih Staff / Guru</label>
            <select name="staff_id" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                <option value="">-- Pilih Staff --</option>
                <?php foreach ($staffList as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= e($s['name']) ?> (Unit <?= e($s['unit_name'] ?? '-') ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:8px; font-weight:600;">Status Kehadiran</label>
            <select name="status" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                <option value="izin">ℹ Izin</option>
                <option value="sakit">♥ Sakit</option>
                <option value="terlambat">⚠ Terlambat (Manual)</option>
                <option value="tepat_waktu">✓ Tepat Waktu (Manual)</option>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 25px;">
            <label style="display:block; margin-bottom:8px; font-weight:600;">Keterangan</label>
            <textarea name="keterangan" rows="3" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family:inherit;" placeholder="Tulis alasan izin/sakit/dinas luar..."></textarea>
        </div>
        
        <div style="display:flex; justify-content: flex-end; gap:10px;">
            <a href="staff.php" class="btn" style="background: #ef4444; color: white;">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan Kehadiran</button>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>