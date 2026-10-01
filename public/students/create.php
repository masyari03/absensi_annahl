<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_students');
$currentRole = currentRole();
$pageTitle = 'Tambah Siswa';
$userId = currentUserId();

/*
|--------------------------------------------------------------------------
| DATA SUBKELAS (DI-FILTER JIKA KEPALA SEKOLAH)
|--------------------------------------------------------------------------
*/
$cgSql = "
    SELECT cg.id, cg.name, cg.grade_id, g.grade 
    FROM class_groups cg 
    INNER JOIN grades g ON g.id = cg.grade_id 
";
$cgParams = [];

if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    
    $cgSql .= " WHERE g.unit_id = ? ";
    $cgParams[] = $ksUnitId;
}

$cgSql .= " ORDER BY g.sort_order, cg.name";
$stmtCg = $pdo->prepare($cgSql);
$stmtCg->execute($cgParams);
$classGroups = $stmtCg->fetchAll();

$stmtAy = $pdo->query("SELECT * FROM academic_years WHERE status = 'active' LIMIT 1");
$academicYear = $stmtAy->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $nis = trim($_POST['nis'] ?? '');
    $nik = trim($_POST['nik'] ?? '');
    $parentPhone = trim($_POST['parent_phone'] ?? '');
    $waNotify = isset($_POST['wa_notify']) ? 1 : 0;
    $rfidUid = trim($_POST['rfid_uid'] ?? '');
    $fingerprintId = trim($_POST['fingerprint_id'] ?? '');
    $fingerprintId = ($fingerprintId !== '' && is_numeric($fingerprintId)) ? (int)$fingerprintId : null;
    $classGroupId = (int)($_POST['class_group_id'] ?? 0);

    if (!$academicYear) {
        $error = 'Belum ada Tahun Ajaran aktif.';
    } elseif ($name === '' || $classGroupId <= 0) {
        $error = 'Nama dan subkelas wajib diisi.';
    } else {
        try {
            $pdo->beginTransaction();

            $photo = null;
            if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $mime = mime_content_type($_FILES['photo']['tmp_name']);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime])) { throw new Exception('Format foto harus JPG, PNG, atau WEBP.'); }
                if ($_FILES['photo']['size'] > 2 * 1024 * 1024) { throw new Exception('Ukuran foto maksimal 2 MB.'); }
                if (!is_dir(UPLOAD_STUDENT)) { mkdir(UPLOAD_STUDENT, 0755, true); }
                
                $photo = uniqid('student_', true) . '.' . $allowed[$mime];
                move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_STUDENT . $photo);
            }

            $stmt = $pdo->prepare("INSERT INTO students (name, nis, nik, parent_phone, wa_notify, rfid_uid, fingerprint_id, photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name, 
                $nis !== '' ? $nis : null, 
                $nik !== '' ? $nik : null, 
                $parentPhone !== '' ? $parentPhone : null, 
                $waNotify, 
                $rfidUid !== '' ? strtoupper($rfidUid) : null,
                $fingerprintId,
                $photo
            ]);
            $studentId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO student_enrollments (student_id, academic_year_id, class_group_id, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$studentId, $academicYear['id'], $classGroupId]);

            $pdo->commit();
            flash('success', 'Siswa berhasil ditambahkan.');
            redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if (!empty($photo) && file_exists(UPLOAD_STUDENT . $photo)) { unlink(UPLOAD_STUDENT . $photo); }
            $error = $e->getMessage();
        }
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3>Tambah Siswa</h3>
            <small>Tahun Ajaran: <?= e($academicYear['name'] ?? 'Belum aktif') ?></small>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>Nama Siswa *</label>
                <input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>NIS</label>
                <input type="text" name="nis" value="<?= e($_POST['nis'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>NIK</label>
                <input type="text" name="nik" value="<?= e($_POST['nik'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Subkelas *</label>
                <select name="class_group_id" required>
                    <option value="">Pilih Subkelas</option>
                    <?php foreach ($classGroups as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= (($_POST['class_group_id'] ?? '') == $class['id']) ? 'selected' : '' ?>>
                            <?= e($class['grade']) ?> - <?= e($class['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-id-card" style="color: #6366f1;"></i> RFID Card UID (ESP32 / Tap Card)</label>
                <input type="text" name="rfid_uid" placeholder="Contoh: A4B2C19F" value="<?= e($_POST['rfid_uid'] ?? '') ?>">
                <small style="color: #64748b;">UID kartu RFID MFRC522 (bisa di-tap langsung jika ada reader USB).</small>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-fingerprint" style="color: #0ea5e9;"></i> Fingerprint ID (Sensor Sidik Jari)</label>
                <input type="number" name="fingerprint_id" min="1" max="1000" placeholder="Contoh: 12" value="<?= e($_POST['fingerprint_id'] ?? '') ?>">
                <small style="color: #64748b;">Nomor ID slot sidik jari yang didaftarkan pada sensor ESP32 (1 - 1000).</small>
            </div>
            <div class="form-group">
                <label><i class="fa-brands fa-whatsapp" style="color: #10b981;"></i> Nomor WhatsApp Orang Tua / Wali</label>
                <input type="text" name="parent_phone" placeholder="Contoh: 081234567890" value="<?= e($_POST['parent_phone'] ?? '') ?>">
                <small style="color: #64748b;">Akan menerima notifikasi absensi masuk, telat, dan pulang.</small>
            </div>
            <div class="form-group" style="display: flex; flex-direction: column; justify-content: center;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-top: 15px; font-weight: 600;">
                    <input type="checkbox" name="wa_notify" value="1" <?= (!isset($_POST['name']) || !empty($_POST['wa_notify'])) ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: #10b981;">
                    <span>Aktifkan Notifikasi WhatsApp untuk Siswa Ini</span>
                </label>
            </div>
            <div class="form-group full">
                <label>Foto</label>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                <small>Maksimal 2 MB.</small>
            </div>
        </div>
        <div style="margin-top:20px">
            <button type="submit" class="btn btn-primary">Simpan Siswa</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>
<?php require '../../includes/footer.php'; ?>
