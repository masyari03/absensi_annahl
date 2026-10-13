<?php
$pageTitle = 'Prestasi & Piagam Siswa';
require_once __DIR__ . '/includes/header.php';

// PROSES SUBMIT PRESTASI SISWA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_achievement'])) {
    $title = trim($_POST['title'] ?? '');
    $level = $_POST['level'] ?? 'sekolah';
    $category = trim($_POST['category'] ?? 'Akademik');
    $achievementDate = $_POST['achievement_date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');

    $allowedLevels = ['sekolah', 'kecamatan', 'kota_kabupaten', 'provinsi', 'nasional', 'internasional'];
    if (!in_array($level, $allowedLevels, true)) {
        $level = 'sekolah';
    }

    if ($title === '') {
        flash('error', 'Judul prestasi / nama kejuaraan wajib diisi.');
    } elseif (empty($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Wajib melampirkan foto/file sertifikat piagam kejuaraan.');
    } else {
        if ($_FILES['certificate']['size'] > 5 * 1024 * 1024) {
            flash('error', 'Ukuran file sertifikat maksimal 5 MB.');
            redirect('achievements.php');
        }

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf'
        ];

        $tmpPath = $_FILES['certificate']['tmp_name'];
        $mime = @mime_content_type($tmpPath);

        if (!isset($allowedMimes[$mime])) {
            flash('error', 'Format piagam harus JPG, PNG, WEBP, atau PDF.');
            redirect('achievements.php');
        }

        $uploadDir = UPLOAD_PATH . '/achievements/';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
        @chmod($uploadDir, 0777);

        $fileName = 'achieve_' . $studentId . '_' . uniqid() . '.' . $allowedMimes[$mime];
        if (!move_uploaded_file($tmpPath, $uploadDir . $fileName)) {
            flash('error', 'Gagal menyimpan file piagam ke server.');
            redirect('achievements.php');
        }
        @chmod($uploadDir . $fileName, 0644);

        try {
            $stmtInsert = $pdo->prepare("
                INSERT INTO student_achievements 
                (student_id, title, level, category, achievement_date, description, certificate_file, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmtInsert->execute([
                $studentId,
                $title,
                $level,
                $category,
                $achievementDate,
                $description,
                $fileName
            ]);

            // Catat ke Log Forensik Siswa
            logStudentActivity(
                $pdo, 
                $studentId, 
                $currentUserId, 
                'upload_achievement', 
                "Siswa mengunggah prestasi: '{$title}' tingkat {$level}"
            );

            flash('success', 'Piagam prestasi berhasil diunggah! Pihak sekolah akan memverifikasi berkas Anda.');
            redirect('achievements.php');
        } catch (Exception $e) {
            flash('error', 'Gagal menyimpan data prestasi: ' . $e->getMessage());
        }
    }
}

// Tarik daftar prestasi siswa
$stmtList = $pdo->prepare("
    SELECT sa.*, u.name as verifier_name
    FROM student_achievements sa
    LEFT JOIN users u ON u.id = sa.verified_by
    WHERE sa.student_id = ?
    ORDER BY sa.achievement_date DESC
");
$stmtList->execute([$studentId]);
$achievementsList = $stmtList->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 24px;">
    <!-- Form Unggah Prestasi -->
    <div class="card" style="margin: 0;">
        <div class="card-header">
            <div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                    <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i> Unggah Prestasi Baru
                </h3>
                <small style="color: #64748b;">Simpan rekam jejak piagam & penghargaan kejuaraan Anda</small>
            </div>
        </div>
        <div style="padding: 20px;">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="submit_achievement" value="1">

                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Judul Kejuaraan / Prestasi <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="title" required placeholder="Contoh: Juara 1 Olimpiade Matematika..." style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Tingkat Kejuaraan <span style="color: #ef4444;">*</span></label>
                        <select name="level" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px;">
                            <option value="sekolah">Antar Sekolah</option>
                            <option value="kecamatan">Kecamatan</option>
                            <option value="kota_kabupaten">Kota / Kabupaten</option>
                            <option value="provinsi">Provinsi</option>
                            <option value="nasional">Nasional</option>
                            <option value="internasional">Internasional</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Kategori Bidang <span style="color: #ef4444;">*</span></label>
                        <select name="category" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px;">
                            <option value="Akademik">Akademik / Sains</option>
                            <option value="Keagamaan">Keagamaan / Tahfidz</option>
                            <option value="Olahraga">Olahraga & Atletik</option>
                            <option value="Seni & Budaya">Seni & Budaya</option>
                            <option value="Robotik & Teknologi">Robotik & IT</option>
                            <option value="Lainnya">Lainnya</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Tanggal Perolehan <span style="color: #ef4444;">*</span></label>
                    <input type="date" name="achievement_date" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Keterangan Tambahan</label>
                    <textarea name="description" rows="2" placeholder="Contoh: Diselenggarakan oleh Kemendikbud di Jakarta..." style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; resize: vertical;"></textarea>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Upload Foto / Berkas Piagam <span style="color: #ef4444;">*</span></label>
                    <input type="file" name="certificate" accept="image/jpeg,image/png,image/webp,application/pdf" required style="width: 100%; padding: 10px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; font-size: 13px;">
                    <small style="color: #64748b; font-size: 11.5px; display: block; margin-top: 4px;">Format: JPG, PNG, WEBP, PDF (Maks. 5 MB).</small>
                </div>

                <button type="submit" class="btn" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; width: 100%; padding: 12px; font-weight: 800; font-size: 14px; border-radius: 8px; border: none; cursor: pointer;">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Simpan & Unggah Prestasi
                </button>
            </form>
        </div>
    </div>

    <!-- Info & Piagam Banner -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 14px;">
                <i class="fa-solid fa-award"></i>
            </div>
            <h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 800; color: #0f172a;">Portofolio Prestasi Sekolah</h3>
            <p style="margin: 0 0 16px 0; font-size: 13px; color: #64748b; line-height: 1.5;">
                Setiap piagam kejuaraan yang Anda unggah akan diverifikasi oleh Kepala Sekolah dan disimpan sebagai rekam jejak portofolio resmi Anda di An-Nahl Islamic School.
            </p>
            <div style="background: #f8fafc; border-radius: 10px; padding: 14px; border: 1px solid #e2e8f0;">
                <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">Total Piagam Terverifikasi</div>
                <div style="font-size: 26px; font-weight: 800; color: #d97706;">
                    <?= count(array_filter($achievementsList, fn($a) => $a['status'] === 'verified')) ?>
                    <span style="font-size: 14px; font-weight: 600; color: #64748b;">Sertifikat</span>
                </div>
            </div>
        </div>
        <div style="margin-top: 20px; font-size: 12px; color: #94a3b8; font-style: italic;">
            <i class="fa-solid fa-shield"></i> Terhubung langsung ke panel Kepala Sekolah unit Anda.
        </div>
    </div>
</div>

<!-- Galeri & Riwayat Prestasi -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                <i class="fa-solid fa-list-check" style="color: #f59e0b;"></i> Daftar Prestasi Siswa
            </h3>
            <small style="color: #64748b;">Seluruh piagam yang telah Anda daftarkan</small>
        </div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">No</th>
                    <th>Judul Kejuaraan</th>
                    <th>Tingkat</th>
                    <th>Kategori</th>
                    <th>Tanggal Perolehan</th>
                    <th>Berkas Piagam</th>
                    <th>Status Verifikasi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($achievementsList)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8;">
                            Belum ada prestasi atau piagam yang diunggah.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($achievementsList as $ach): 
                        $statusBadge = [
                            'pending'  => ['#fef3c7', '#92400e', 'fa-clock', 'Menunggu Verifikasi'],
                            'verified' => ['#dcfce7', '#166534', 'fa-certificate', 'Terverifikasi'],
                            'rejected' => ['#fee2e2', '#991b1b', 'fa-circle-xmark', 'Ditolak']
                        ];
                        $sb = $statusBadge[$ach['status']] ?? ['#f1f5f9', '#475569', 'fa-info', $ach['status']];
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <strong style="color: #0f172a; font-size: 13.5px;"><?= e($ach['title']) ?></strong>
                                <?php if (!empty($ach['description'])): ?>
                                    <div style="font-size: 12px; color: #64748b; margin-top: 2px;"><?= e($ach['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight: 600; text-transform: uppercase; font-size: 11.5px; color: #475569; background: #f1f5f9; padding: 3px 8px; border-radius: 6px;">
                                    <?= str_replace('_', ' ', e($ach['level'])) ?>
                                </span>
                            </td>
                            <td><?= e($ach['category']) ?></td>
                            <td><?= date('d F Y', strtotime($ach['achievement_date'])) ?></td>
                            <td>
                                <a href="<?= UPLOAD_URL ?>/achievements/<?= e($ach['certificate_file']) ?>" target="_blank" class="btn" style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; font-size: 12px; font-weight: 700; text-decoration: none; border-radius: 6px;">
                                    <i class="fa-solid fa-file-image"></i> Lihat Piagam
                                </a>
                            </td>
                            <td>
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; background: <?= $sb[0] ?>; color: <?= $sb[1] ?>;">
                                    <i class="fa-solid <?= $sb[2] ?>"></i> <?= $sb[3] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
