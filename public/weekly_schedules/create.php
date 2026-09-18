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

$pageTitle = 'Tambah Jadwal Pekanan';
$userId = currentUserId();

$ksUnitId = 0;
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = (int)$stmtKs->fetchColumn();
}

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitId = $currentRole === 'super_admin' ? (int)$_POST['unit_id'] : $ksUnitId;
    $dayCodes = $_POST['day_codes'] ?? []; 
    
    $days = [1=>'Senin', 2=>'Selasa', 3=>'Rabu', 4=>'Kamis', 5=>'Jumat', 6=>'Sabtu', 7=>'Minggu'];

    if (!empty($dayCodes) && is_array($dayCodes) && $unitId > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO weekly_schedules 
            (unit_id, day_name, day_code, student_in, student_late, student_out, staff_in, staff_late, staff_out, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $pdo->beginTransaction();
        try {
            foreach ($dayCodes as $code) {
                $code = (int)$code;
                $dayName = $days[$code] ?? '';
                
                if ($dayName) {
                    $stmt->execute([
                        $unitId, $dayName, $code,
                        $_POST['student_in'], $_POST['student_late'], $_POST['student_out'],
                        $_POST['staff_in'], $_POST['staff_late'], $_POST['staff_out'],
                        $_POST['is_active']
                    ]);
                }
            }
            $pdo->commit();
            flash('success', 'Jadwal pekanan berhasil ditambahkan.');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('error', 'Terjadi kesalahan saat menyimpan data.');
        }
    }

    header('Location: index.php');
    exit;
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Tambah Jadwal Pekanan (Massal)</h3>
    <p style="color: #64748b; margin-bottom: 20px;">Anda dapat mengatur jadwal untuk beberapa hari sekaligus dengan menceklis hari yang diinginkan.</p>
    
    <form method="POST">
        <div class="form-grid">
            <div class="form-group full">
                <label>Pilih Unit *</label>
                <?php if ($currentRole === 'super_admin'): ?>
                    <select name="unit_id" required>
                        <option value="">Pilih Unit...</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= $unit['id'] ?>"><?= e($unit['unit']) ?></option>
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
                    <small style="color:#0284c7;">Otomatis ditambahkan ke unit Anda.</small>
                <?php endif; ?>
            </div>
            
            <div class="form-group full">
                <label>Pilih Hari (Ceklis yang jadwalnya sama)</label>
                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="1" checked> Senin</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="2" checked> Selasa</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="3" checked> Rabu</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="4" checked> Kamis</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="5" checked> Jumat</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="6"> Sabtu</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="7"> Minggu</label>
                </div>
            </div>
            
            <div class="form-group"><label>Siswa: Jam Masuk</label><input type="time" name="student_in" required></div>
            <div class="form-group"><label>Siswa: Batas Telat</label><input type="time" name="student_late" required></div>
            <div class="form-group"><label>Siswa: Jam Pulang</label><input type="time" name="student_out" required></div>

            <div class="form-group"><label>Staff: Jam Masuk</label><input type="time" name="staff_in" required></div>
            <div class="form-group"><label>Staff: Batas Telat</label><input type="time" name="staff_late" required></div>
            <div class="form-group"><label>Staff: Jam Pulang</label><input type="time" name="staff_out" required></div>

            <div class="form-group full">
                <label>Status Template</label>
                <select name="is_active">
                    <option value="active">Aktif</option>
                    <option value="inactive">Nonaktif</option>
                </select>
            </div>
        </div>
        <div style="margin-top:20px">
            <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
            <a href="index.php" class="btn btn-success">Batal</a>
        </div>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>