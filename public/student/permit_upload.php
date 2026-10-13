<?php
require_once __DIR__ . '/includes/header.php';

// Proteksi Ketat Master Control Super Admin
if (!$webPermitEnabled) {
    flash('error', 'Fitur pengajuan izin lewat web sedang dinonaktifkan oleh Administrator.');
    redirect('dashboard.php');
}

$pageTitle = 'Surat Izin / Sakit';

// Tarik data kelas siswa saat ini
$classGroupId = (int)($studentData['class_group_id'] ?? 0);
if ($classGroupId === 0) {
    $stmtCg = $pdo->prepare("SELECT class_group_id FROM student_enrollments WHERE student_id = ? AND status = 'active' LIMIT 1");
    $stmtCg->execute([$studentId]);
    $classGroupId = (int)$stmtCg->fetchColumn();
}

$waShareLink = null;
$waShareText = null;

// PROSES SUBMIT SURAT IZIN / SAKIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_permit'])) {
    $permitType = $_POST['permit_type'] ?? 'izin';
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $endDate = $_POST['end_date'] ?? $startDate;
    $reason = trim($_POST['reason'] ?? '');

    if (!in_array($permitType, ['izin', 'sakit'])) {
        $permitType = 'izin';
    }

    if ($reason === '') {
        flash('error', 'Alasan pengajuan izin/sakit wajib diisi.');
    } else {
        $attachmentName = null;

        // Proses Unggah Lampiran Bukti / Surat
        if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['attachment']['size'] > 5 * 1024 * 1024) {
                flash('error', 'Ukuran file lampiran maksimal 5 MB.');
                redirect('permit_upload.php');
            }

            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'application/pdf' => 'pdf'
            ];

            $tmpPath = $_FILES['attachment']['tmp_name'];
            $mime = @mime_content_type($tmpPath);

            if (!isset($allowedMimes[$mime])) {
                flash('error', 'Format lampiran harus JPG, PNG, WEBP, atau PDF.');
                redirect('permit_upload.php');
            }

            $uploadDir = UPLOAD_PATH . '/permits/';
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
            @chmod($uploadDir, 0777);

            $attachmentName = 'permit_' . $studentId . '_' . uniqid() . '.' . $allowedMimes[$mime];
            if (!move_uploaded_file($tmpPath, $uploadDir . $attachmentName)) {
                flash('error', 'Gagal menyimpan lampiran file ke server.');
                redirect('permit_upload.php');
            }
            @chmod($uploadDir . $attachmentName, 0644);
        }

        try {
            $stmtInsert = $pdo->prepare("
                INSERT INTO student_permits 
                (student_id, class_group_id, start_date, end_date, permit_type, reason, attachment, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmtInsert->execute([
                $studentId,
                $classGroupId,
                $startDate,
                $endDate,
                $permitType,
                $reason,
                $attachmentName
            ]);
            $permitId = $pdo->lastInsertId();

            // Catat ke Log Forensik Siswa
            logStudentActivity(
                $pdo, 
                $studentId, 
                $currentUserId, 
                'upload_permit', 
                "Siswa mengajukan surat {$permitType} ({$startDate} s/d {$endDate})"
            );

            // Buat Format Teks WhatsApp untuk Dikirim ke Wali Kelas
            $studentName = $studentData['name'] ?? 'Siswa';
            $className = ($studentData['grade'] ?? '') . ' - ' . ($studentData['class_name'] ?? '');
            $typeLabel = ($permitType === 'sakit') ? 'Sakit' : 'Izin';
            
            $rawMsg = "Assalamu'alaikum Warahmatullahi Wabarakatuh,\n\n"
                    . "Bapak/Ibu Guru Wali Kelas,\n"
                    . "Saya ingin menginformasikan pengajuan surat {$typeLabel} untuk siswa berikut:\n\n"
                    . "• Nama: {$studentName}\n"
                    . "• Kelas: {$className}\n"
                    . "• Tanggal: {$startDate} s/d {$endDate}\n"
                    . "• Keterangan: {$reason}\n\n"
                    . "Surat keterangan/bukti telah diunggah ke Portal Siswa AIS. Mohon konfirmasi dan doa/izinnya. Terima kasih.";

            $_SESSION['wa_share_msg'] = $rawMsg;
            flash('success', 'Pengajuan surat ' . $typeLabel . ' berhasil dikirim! Silakan klik tombol di bawah untuk mengirimkan kabar ke WhatsApp Wali Kelas.');
            redirect('permit_upload.php?success_id=' . $permitId);
        } catch (Exception $e) {
            flash('error', 'Gagal menyimpan permohonan izin: ' . $e->getMessage());
        }
    }
}

// Ambil pesan WhatsApp jika ada pengajuan yang baru selesai
$recentSuccessId = (int)($_GET['success_id'] ?? 0);
$waShareText = $_SESSION['wa_share_msg'] ?? null;
unset($_SESSION['wa_share_msg']);

// Tarik daftar riwayat permohonan izin siswa
$stmtList = $pdo->prepare("
    SELECT sp.*, u.name as reviewer_name
    FROM student_permits sp
    LEFT JOIN users u ON u.id = sp.reviewed_by
    WHERE sp.student_id = ?
    ORDER BY sp.created_at DESC
");
$stmtList->execute([$studentId]);
$permitsList = $stmtList->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if ($recentSuccessId > 0 && !empty($waShareText)): ?>
    <!-- KARTU SUKSES & TOMBOL SHARE WHATSAPP -->
    <div style="background: linear-gradient(135deg, #059669, #10b981); color: white; border-radius: 14px; padding: 22px 26px; margin-bottom: 24px; box-shadow: 0 10px 20px -5px rgba(16, 185, 129, 0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h3 style="margin: 0 0 6px 0; font-size: 18px; font-weight: 800;">
                    <i class="fa-solid fa-circle-check"></i> Surat Berhasil Diajukan!
                </h3>
                <p style="margin: 0; font-size: 13.5px; opacity: 0.95;">
                    Kirimkan kabar pengajuan ini langsung ke WhatsApp Guru / Wali Kelas agar segera diverifikasi.
                </p>
            </div>
            <div>
                <a href="https://api.whatsapp.com/send?text=<?= urlencode($waShareText) ?>" target="_blank" class="btn" style="background: #ffffff; color: #047857; font-weight: 800; font-size: 14px; padding: 10px 20px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
                    <i class="fa-brands fa-whatsapp" style="font-size: 18px; color: #16a34a;"></i> Kirim ke WhatsApp Wali Kelas
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 24px;">
    <!-- Form Pengajuan Surat Izin -->
    <div class="card" style="margin: 0;">
        <div class="card-header">
            <div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                    <i class="fa-solid fa-file-signature" style="color: #0284c7;"></i> Form Pengajuan Izin / Sakit
                </h3>
                <small style="color: #64748b;">Unggah surat dokter atau surat izin dari orang tua</small>
            </div>
        </div>
        <div style="padding: 20px;">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="submit_permit" value="1">

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Jenis Permohonan <span style="color: #ef4444;">*</span></label>
                    <div style="display: flex; gap: 14px;">
                        <label style="flex: 1; display: flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; background: #f8fafc;">
                            <input type="radio" name="permit_type" value="sakit" checked>
                            <span style="font-weight: 700; color: #7c3aed; font-size: 13.5px;"><i class="fa-solid fa-hospital-user"></i> Sakit</span>
                        </label>
                        <label style="flex: 1; display: flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; background: #f8fafc;">
                            <input type="radio" name="permit_type" value="izin">
                            <span style="font-weight: 700; color: #0284c7; font-size: 13.5px;"><i class="fa-solid fa-file-lines"></i> Izin Keperluan</span>
                        </label>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Mulai Tanggal <span style="color: #ef4444;">*</span></label>
                        <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Sampai Tanggal <span style="color: #ef4444;">*</span></label>
                        <input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px;">
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Alasan / Keterangan Lengkap <span style="color: #ef4444;">*</span></label>
                    <textarea name="reason" rows="3" required placeholder="Contoh: Sakit demam tinggi sejak malam, sedang istirahat di rumah..." style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; resize: vertical;"></textarea>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 6px;">Foto Surat Dokter / Surat Izin Ortu</label>
                    <input type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf" style="width: 100%; padding: 10px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; font-size: 13px;">
                    <small style="color: #64748b; font-size: 11.5px; display: block; margin-top: 4px;">Format: JPG, PNG, WEBP, PDF (Maks. 5 MB).</small>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-weight: 800; font-size: 14px; border-radius: 8px;">
                    <i class="fa-solid fa-paper-plane"></i> Kirim Pengajuan Surat
                </button>
            </form>
        </div>
    </div>

    <!-- Petunjuk & Alur Izin -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <h3 style="margin: 0 0 14px 0; font-size: 16px; font-weight: 800; color: #0f172a;">
            <i class="fa-solid fa-circle-info" style="color: #0284c7;"></i> Alur Pengajuan Izin / Sakit
        </h3>
        
        <div style="display: flex; flex-direction: column; gap: 14px;">
            <div style="display: flex; gap: 12px; align-items: flex-start;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0;">1</div>
                <div>
                    <strong style="font-size: 13.5px; color: #1e293b;">Isi Form & Unggah Foto Surat</strong>
                    <p style="margin: 2px 0 0 0; font-size: 12.5px; color: #64748b;">Pastikan tanggal dan alasan jelas serta melampirkan foto surat resmi.</p>
                </div>
            </div>

            <div style="display: flex; gap: 12px; align-items: flex-start;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0;">2</div>
                <div>
                    <strong style="font-size: 13.5px; color: #1e293b;">Kirim Berita ke WhatsApp Wali Kelas</strong>
                    <p style="margin: 2px 0 0 0; font-size: 12.5px; color: #64748b;">Setelah submit, klik tombol WhatsApp untuk langsung mengirim pesan siap pakai ke nomor wali kelas.</p>
                </div>
            </div>

            <div style="display: flex; gap: 12px; align-items: flex-start;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0;">3</div>
                <div>
                    <strong style="font-size: 13.5px; color: #1e293b;">Verifikasi Otomatis Guru</strong>
                    <p style="margin: 2px 0 0 0; font-size: 12.5px; color: #64748b;">Guru akan memeriksa dan menyetujui di panel web. Catatan absensi Anda otomatis terisi "Izin" atau "Sakit".</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Riwayat Pengajuan Izin Siswa -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                <i class="fa-solid fa-list-check" style="color: #0284c7;"></i> Riwayat Pengajuan Surat Anda
            </h3>
            <small style="color: #64748b;">Status verifikasi surat izin/sakit oleh pihak sekolah</small>
        </div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">No</th>
                    <th>Tanggal Pengajuan</th>
                    <th>Jenis</th>
                    <th>Rentang Tanggal</th>
                    <th>Alasan</th>
                    <th>Lampiran</th>
                    <th>Status</th>
                    <th>Catatan Guru</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($permitsList)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 30px; color: #94a3b8;">
                            Belum ada riwayat pengajuan surat izin atau sakit.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($permitsList as $p): 
                        $statusBadge = [
                            'pending'  => ['#fef3c7', '#92400e', 'fa-clock', 'Menunggu Verifikasi'],
                            'approved' => ['#dcfce7', '#166534', 'fa-circle-check', 'Disetujui'],
                            'rejected' => ['#fee2e2', '#991b1b', 'fa-circle-xmark', 'Ditolak']
                        ];
                        $sb = $statusBadge[$p['status']] ?? ['#f1f5f9', '#475569', 'fa-info', $p['status']];
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= date('d M Y, H:i', strtotime($p['created_at'])) ?></td>
                            <td>
                                <strong style="text-transform: uppercase; color: <?= $p['permit_type'] === 'sakit' ? '#7c3aed' : '#0284c7' ?>;">
                                    <?= e($p['permit_type']) ?>
                                </strong>
                            </td>
                            <td>
                                <?= date('d M Y', strtotime($p['start_date'])) ?> s/d <?= date('d M Y', strtotime($p['end_date'])) ?>
                            </td>
                            <td><?= e($p['reason']) ?></td>
                            <td>
                                <?php if (!empty($p['attachment'])): ?>
                                    <a href="<?= UPLOAD_URL ?>/permits/<?= e($p['attachment']) ?>" target="_blank" class="btn btn-primary" style="padding: 4px 10px; font-size: 11.5px;">
                                        <i class="fa-solid fa-file"></i> Lihat Berkas
                                    </a>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 12px;">Tanpa Lampiran</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; background: <?= $sb[0] ?>; color: <?= $sb[1] ?>;">
                                    <i class="fa-solid <?= $sb[2] ?>"></i> <?= $sb[3] ?>
                                </span>
                            </td>
                            <td>
                                <?= !empty($p['review_notes']) ? e($p['review_notes']) : '<span style="color: #cbd5e1;">-</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
