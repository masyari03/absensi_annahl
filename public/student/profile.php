<?php
$pageTitle = 'Profil & Pengaturan Akun';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 24px;">
    <!-- Kartu Informasi Siswa -->
    <div class="card" style="margin: 0;">
        <div class="card-header">
            <div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                    <i class="fa-solid fa-id-badge" style="color: #0284c7;"></i> Data Identitas Siswa
                </h3>
                <small style="color: #64748b;">Informasi resmi yang terdaftar pada sistem sekolah</small>
            </div>
        </div>
        <div style="padding: 20px;">
            <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9;">
                <div style="width: 60px; height: 60px; border-radius: 50%; overflow: hidden; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; flex-shrink: 0;">
                    <?php if (!empty($studentPhoto)): ?>
                        <img src="<?= UPLOAD_URL ?>/students/<?= e($studentPhoto) ?>" alt="Foto" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <?= strtoupper(substr($studentData['name'] ?? 'S', 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 style="margin: 0 0 4px 0; font-size: 16px; font-weight: 800; color: #0f172a;"><?= e($studentData['name']) ?></h3>
                    <span style="font-size: 12.5px; color: #0284c7; font-weight: 700; background: #e0f2fe; padding: 2px 8px; border-radius: 6px;">
                        <?= e($studentData['unit_name'] ?? 'Unit') ?> • <?= e($studentData['grade'] ?? '') ?> - <?= e($studentData['class_name'] ?? '') ?>
                    </span>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 12px; font-size: 13.5px;">
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f8fafc; padding-bottom: 8px;">
                    <span style="color: #64748b;">NIS Siswa:</span>
                    <strong style="color: #1e293b;"><?= e($studentData['nis'] ?? '-') ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f8fafc; padding-bottom: 8px;">
                    <span style="color: #64748b;">NIK Siswa:</span>
                    <strong style="color: #1e293b;"><?= e($studentData['nik'] ?? '-') ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f8fafc; padding-bottom: 8px;">
                    <span style="color: #64748b;">Nomor WA Orang Tua:</span>
                    <strong style="color: #1e293b;"><?= e($studentData['parent_phone'] ?? '-') ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f8fafc; padding-bottom: 8px;">
                    <span style="color: #64748b;">RFID UID Kartu:</span>
                    <strong style="color: #4338ca; font-family: monospace;"><?= e($studentData['rfid_uid'] ?? '-') ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #64748b;">Fingerprint ID:</span>
                    <strong style="color: #0284c7;"><?= !empty($studentData['fingerprint_id']) ? '#' . e($studentData['fingerprint_id']) : '-' ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Pengaturan Akses & Keamanan Akun -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <!-- KOTAK GANTI USERNAME (KONDISIONAL SUPER ADMIN) -->
        <div class="card" style="margin: 0;">
            <div class="card-header">
                <div>
                    <h3 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a;">
                        <i class="fa-solid fa-user-pen" style="color: #0284c7;"></i> Pengaturan Username
                    </h3>
                </div>
                <?php if ($canChangeUsername): ?>
                    <span style="font-size: 11.5px; font-weight: 700; color: #16a34a; background: #dcfce7; padding: 2px 8px; border-radius: 6px;">
                        <i class="fa-solid fa-lock-open"></i> Diizinkan
                    </span>
                <?php else: ?>
                    <span style="font-size: 11.5px; font-weight: 700; color: #92400e; background: #fef3c7; padding: 2px 8px; border-radius: 6px;">
                        <i class="fa-solid fa-lock"></i> Dikunci Super Admin
                    </span>
                <?php endif; ?>
            </div>
            <div style="padding: 20px;">
                <?php if ($canChangeUsername): ?>
                    <form action="<?= BASE_URL ?>/update_account.php" method="POST">
                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">Username Baru</label>
                            <input type="text" name="username" value="<?= e($_SESSION['username'] ?? '') ?>" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px;">
                        </div>
                        <button type="submit" class="btn btn-primary" style="font-size: 13px; font-weight: 700;">Simpan Username Baru</button>
                    </form>
                <?php else: ?>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; font-size: 13px; color: #64748b;">
                        Username Anda saat ini adalah: <strong style="color: #0f172a;">@<?= e($_SESSION['username'] ?? '') ?></strong> (dibuat dari NIS).
                        <p style="margin: 8px 0 0 0; color: #b45309; font-size: 12px;">
                            <i class="fa-solid fa-circle-exclamation"></i> Penggantian username sedang dinonaktifkan oleh Administrator. Hubungi pihak sekolah jika membutuhkan perubahan.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- KOTAK GANTI PASSWORD (SELALU AKTIF) -->
        <div class="card" style="margin: 0;">
            <div class="card-header">
                <div>
                    <h3 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a;">
                        <i class="fa-solid fa-key" style="color: #f59e0b;"></i> Ganti Password Akun
                    </h3>
                </div>
            </div>
            <div style="padding: 20px;">
                <form action="<?= BASE_URL ?>/update_account.php" method="POST">
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">Password Baru</label>
                        <input type="password" name="password" required placeholder="Ketikkan password baru..." style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px;">
                    </div>
                    <button type="submit" class="btn" style="background: #f59e0b; color: white; font-size: 13px; font-weight: 700; border: none; padding: 10px 16px; border-radius: 8px; cursor: pointer;">
                        Simpan Password Baru
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
