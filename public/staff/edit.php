<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// 1. IZINKAN SUPER ADMIN DAN KEPALA SEKOLAH
requireLogin();
checkUserAccess('master_staff');
$currentRole = currentRole();
$pageTitle = 'Edit Staff';
$userId = currentUserId();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT s.*, u.username, u.role FROM staff s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1");
$stmt->execute([$id]);
$staff = $stmt->fetch();

if (!$staff) { die('Staff tidak ditemukan.'); }

// 2. PROTEKSI: PASTIKAN STAFF BERADA DI UNIT KEPALA SEKOLAH
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();

    if ($staff['unit_id'] != $ksUnitId) {
        flash('error', 'Akses ditolak. Staff ini berada di luar kendali unit Anda.');
        redirect('index.php');
        exit;
    }
}

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
    $photoNameSave = $staff['photo']; // Default pakai foto lama
    
    // 3. AMANKAN INPUT UNIT
    if ($currentRole === 'super_admin') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
    } else {
        $unitId = $staff['unit_id']; // Kepala sekolah dipaksa menggunakan unit bawaan
    }

    try {
        $pdo->beginTransaction();
        
        // Proses Upload Foto Baru
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photoNameSave = uniqid('staff_', true) . '.' . $ext;
            $uploadDir = '../../uploads/staff/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            
            // Hapus file foto lama jika ada
            if (!empty($staff['photo']) && file_exists($uploadDir . $staff['photo'])) {
                unlink($uploadDir . $staff['photo']);
            }
            
            move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoNameSave);
        }

        $staffUserId = $staff['user_id'];

        // Kelola Akun Pengguna (Users)
        if (!empty($username)) {
            if (empty($staffUserId)) {
                // Skenario 1: Belum ada user, buat baru dengan role 'staff'
                $passToHash = !empty($password) ? $password : '123456'; 
                $stmtUser = $pdo->prepare("INSERT INTO users (username, password, name, role, created_at) VALUES (?, ?, ?, 'staff', NOW())");
                $stmtUser->execute([$username, hashPassword($passToHash), $name]);
                $staffUserId = $pdo->lastInsertId();
            } else {
                // Skenario 2: Sudah ada user, lakukan update data & pastikan role 'staff'
                if ($password !== '') {
                    $stmtUser = $pdo->prepare("UPDATE users SET username = ?, password = ?, name = ?, role = 'staff' WHERE id = ?");
                    $stmtUser->execute([$username, hashPassword($password), $name, $staffUserId]);
                } else {
                    $stmtUser = $pdo->prepare("UPDATE users SET username = ?, name = ?, role = 'staff' WHERE id = ?");
                    $stmtUser->execute([$username, $name, $staffUserId]);
                }
            }
        }

        // Update data pada tabel staff termasuk nomor telepon, wa, rfid, fingerprint
        $stmt = $pdo->prepare("UPDATE staff SET unit_id = ?, user_id = ?, name = ?, phone = ?, wa_notify = ?, rfid_uid = ?, fingerprint_id = ?, nik = ?, photo = ? WHERE id = ?");
        $stmt->execute([
            $unitId, 
            $staffUserId, 
            $name, 
            $phone !== '' ? $phone : null, 
            $waNotify, 
            $rfidUid !== '' ? strtoupper($rfidUid) : null,
            $fingerprintId,
            $nik, 
            $photoNameSave, 
            $id
        ]);

        $pdo->commit();
        flash('success', 'Staff berhasil diperbarui.');
        redirect('index.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <h3>Edit Staff / Guru</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>Unit *</label>
                <?php if ($currentRole === 'super_admin'): ?>
                    <select name="unit_id" required>
                        <option value="">Pilih Unit...</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $staff['unit_id'] == $u['id'] ? 'selected' : '' ?>>
                                <?= e($u['unit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php 
                        $unitNameStr = '-';
                        foreach ($units as $u) {
                            if($u['id'] == $staff['unit_id']) { $unitNameStr = $u['unit']; break; }
                        }
                    ?>
                    <input type="text" value="<?= e($unitNameStr) ?>" disabled style="background:#f1f5f9; cursor:not-allowed;">
                    <small style="color:#ef4444;">Kepala Sekolah tidak dapat mengubah unit.</small>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label>Nama Lengkap *</label>
                <input type="text" name="name" value="<?= e($staff['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>NIK / Barcode *</label>
                <input type="text" name="nik" value="<?= e($staff['nik']) ?>" required>
            </div>
            <div class="form-group">
                <label><i class="fa-brands fa-whatsapp" style="color: #10b981;"></i> Nomor WhatsApp Staff</label>
                <input type="text" name="phone" placeholder="Contoh: 081234567890" value="<?= e($staff['phone'] ?? '') ?>">
                <small style="color: #64748b;">Untuk pengiriman rekapan kehadiran & lembur bulanan.</small>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-id-card" style="color: #6366f1;"></i> RFID Card UID (ESP32 / Tap Card)</label>
                <input type="text" name="rfid_uid" placeholder="Contoh: A4B2C19F" value="<?= e($staff['rfid_uid'] ?? '') ?>">
                <small style="color: #64748b;">UID kartu RFID MFRC522 (bisa di-tap di alat mana saja).</small>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-fingerprint" style="color: #0ea5e9;"></i> Fingerprint ID (Sensor Sidik Jari)</label>
                <input type="number" name="fingerprint_id" min="1" max="1000" placeholder="Contoh: 15" value="<?= e($staff['fingerprint_id'] ?? '') ?>">
                <small style="color: #64748b;">ID slot sidik jari yang didaftarkan di sensor ESP32.</small>
            </div>
            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" value="<?= e($staff['username']) ?>" required>
            </div>
            <div class="form-group">
                <label>Password Baru</label>
                <input type="password" name="password" placeholder="Kosongkan jika tidak diganti">
            </div>
            <div class="form-group full" style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" name="wa_notify" value="1" <?= (!isset($staff['wa_notify']) || (int)$staff['wa_notify'] === 1) ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: #10b981;" id="wa_notify_chk">
                <label for="wa_notify_chk" style="cursor: pointer; font-weight: 600; margin: 0;">Aktifkan Notifikasi WhatsApp (Rekapan Bulanan) untuk Staff Ini</label>
            </div>
            <div class="form-group full">
                <label>Ganti Foto (Opsional)</label>
                <?php if (!empty($staff['photo'])): ?>
                    <div style="margin-bottom:10px;">
                        <img src="../../uploads/staff/<?= e($staff['photo']) ?>" width="60" style="border-radius:10px;">
                    </div>
                <?php endif; ?>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                <small>Maksimal 2 MB.</small>
            </div>
        </div>
        <div style="margin-top:20px">
            <button class="btn btn-primary" type="submit">Simpan Perubahan</button>
            <a href="index.php" class="btn btn-success">Kembali</a>
        </div>
    </form>
</div>

<?php require '../../includes/footer.php'; ?>
