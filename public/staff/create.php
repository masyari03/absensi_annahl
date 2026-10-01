<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_staff');
$pageTitle = 'Tambah Staff';

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $nik = trim($_POST['nik'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $waNotify = isset($_POST['wa_notify']) ? 1 : 0;
    $rfidUid = trim($_POST['rfid_uid'] ?? '');
    $fingerprintId = trim($_POST['fingerprint_id'] ?? '');
    $fingerprintId = ($fingerprintId !== '' && is_numeric($fingerprintId)) ? (int)$fingerprintId : null;
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $unitId = (int)($_POST['unit_id'] ?? 0);

    if ($name === '' || $nik === '' || $username === '' || $password === '' || $unitId <= 0) {
        $error = 'Semua field bertanda * wajib diisi, termasuk Unit.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Proses Upload Foto
            $photoNameSave = null;
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $photoNameSave = uniqid('staff_', true) . '.' . $ext;
                $uploadDir = '../../uploads/staff/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoNameSave);
            }

            $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role) VALUES (?, ?, ?, 'staff')");
            $stmt->execute([$username, hashPassword($password), $name]);
            $userId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO staff (user_id, unit_id, name, phone, wa_notify, rfid_uid, fingerprint_id, nik, photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId, 
                $unitId, 
                $name, 
                $phone !== '' ? $phone : null, 
                $waNotify, 
                $rfidUid !== '' ? strtoupper($rfidUid) : null,
                $fingerprintId,
                $nik, 
                $photoNameSave
            ]);

            $pdo->commit();
            flash('success', 'Staff berhasil ditambahkan.');
            redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = $e->getMessage();
        }
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Tambah Staff / Guru</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit *</label>
                <select name="unit_id" required>
                    <option value="">Pilih Unit...</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (($_POST['unit_id'] ?? '') == $u['id']) ? 'selected' : '' ?>><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Nama Lengkap *</label>
                <input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>NIK / Barcode *</label>
                <input type="text" name="nik" required value="<?= e($_POST['nik'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><i class="fa-brands fa-whatsapp" style="color: #10b981;"></i> Nomor WhatsApp Staff</label>
                <input type="text" name="phone" placeholder="Contoh: 081234567890" value="<?= e($_POST['phone'] ?? '') ?>">
                <small style="color: #64748b;">Untuk pengiriman rekapan kehadiran & lembur bulanan.</small>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-id-card" style="color: #6366f1;"></i> RFID Card UID (ESP32 / Tap Card)</label>
                <input type="text" name="rfid_uid" placeholder="Contoh: A4B2C19F" value="<?= e($_POST['rfid_uid'] ?? '') ?>">
                <small style="color: #64748b;">UID kartu RFID MFRC522 (bisa di-tap di alat mana saja).</small>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-fingerprint" style="color: #0ea5e9;"></i> Fingerprint ID (Sensor Sidik Jari)</label>
                <input type="number" name="fingerprint_id" min="1" max="1000" placeholder="Contoh: 15" value="<?= e($_POST['fingerprint_id'] ?? '') ?>">
                <small style="color: #64748b;">ID slot sidik jari yang didaftarkan di sensor ESP32.</small>
            </div>
            <div class="form-group">
                <label>Username (Untuk Login) *</label>
                <input type="text" name="username" required value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group full" style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" name="wa_notify" value="1" <?= (!isset($_POST['name']) || !empty($_POST['wa_notify'])) ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: #10b981;" id="wa_notify_chk">
                <label for="wa_notify_chk" style="cursor: pointer; font-weight: 600; margin: 0;">Aktifkan Notifikasi WhatsApp (Rekapan Bulanan) untuk Staff Ini</label>
            </div>
            <div class="form-group full">
                <label>Foto Profil (Opsional)</label>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                <small>Maksimal 2 MB.</small>
            </div>
        </div>
        <div style="margin-top:20px">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>