<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('attendance_students');

$webPermitEnabled = (getSystemSetting($pdo, 'student_web_permit_enabled', '1') === '1');
if (!$webPermitEnabled) {
    flash('error', 'Fitur pengajuan izin siswa lewat web sedang dinonaktifkan oleh Super Admin.');
    redirect('../dashboard.php');
}

$pageTitle = 'Persetujuan Izin & Surat Sakit Siswa';
$currentRole = currentRole();
$userId = currentUserId();

// PROSES APPROVAL / REJECTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_permit'])) {
    $permitId = (int)($_POST['permit_id'] ?? 0);
    $action = $_POST['action_permit']; // 'approve' atau 'reject'
    $notes = trim($_POST['review_notes'] ?? '');

    $stmtCheck = $pdo->prepare("SELECT * FROM student_permits WHERE id = ?");
    $stmtCheck->execute([$permitId]);
    $permit = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$permit) {
        flash('error', 'Data pengajuan tidak ditemukan.');
    } else {
        try {
            $pdo->beginTransaction();

            if ($action === 'approve') {
                $stmtUp = $pdo->prepare("
                    UPDATE student_permits 
                    SET status = 'approved', reviewed_by = ?, review_notes = ?, reviewed_at = NOW()
                    WHERE id = ?
                ");
                $stmtUp->execute([$userId, $notes, $permitId]);

                // Sinkronisasi otomatis ke student_attendances dari start_date s/d end_date
                $start = new DateTime($permit['start_date']);
                $end = new DateTime($permit['end_date']);
                $end->modify('+1 day'); // inclusive
                $period = new DatePeriod($start, new DateInterval('P1D'), $end);

                $attStatus = ($permit['permit_type'] === 'sakit') ? 'sakit' : 'izin';

                // Dapatkan enrollment_id aktif
                $stmtEnr = $pdo->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND status = 'active' LIMIT 1");
                $stmtEnr->execute([$permit['student_id']]);
                $enrollmentId = (int)$stmtEnr->fetchColumn();
                if ($enrollmentId === 0) {
                    $stmtEnrFallback = $pdo->prepare("SELECT id FROM student_enrollments WHERE student_id = ? ORDER BY id DESC LIMIT 1");
                    $stmtEnrFallback->execute([$permit['student_id']]);
                    $enrollmentId = (int)$stmtEnrFallback->fetchColumn();
                }

                // Dapatkan activity_id yang berlaku
                $stmtAct = $pdo->query("SELECT id FROM activities WHERE status = 'active' ORDER BY id DESC LIMIT 1");
                $activityId = (int)$stmtAct->fetchColumn();
                if ($activityId === 0) {
                    $activityId = 1;
                }

                $stmtCheckAtt = $pdo->prepare("SELECT id FROM student_attendances WHERE student_id = ? AND attendance_date = ? LIMIT 1");
                $stmtUpdateAtt = $pdo->prepare("UPDATE student_attendances SET status = ?, keterangan = ? WHERE id = ?");
                $stmtInsertAtt = $pdo->prepare("
                    INSERT INTO student_attendances (student_id, enrollment_id, activity_id, attendance_date, status, keterangan, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");

                foreach ($period as $dt) {
                    $attDate = $dt->format('Y-m-d');
                    $attNotes = "Disetujui dari pengajuan online: " . $permit['reason'];
                    
                    $stmtCheckAtt->execute([$permit['student_id'], $attDate]);
                    $existingAttId = $stmtCheckAtt->fetchColumn();

                    if ($existingAttId) {
                        $stmtUpdateAtt->execute([$attStatus, $attNotes, $existingAttId]);
                    } elseif ($enrollmentId > 0) {
                        $stmtInsertAtt->execute([$permit['student_id'], $enrollmentId, $activityId, $attDate, $attStatus, $attNotes]);
                    }
                }

                $pdo->commit();
                flash('success', 'Pengajuan izin berhasil disetujui dan otomatis disinkronkan ke laporan absensi siswa.');
            } elseif ($action === 'reject') {
                $stmtUp = $pdo->prepare("
                    UPDATE student_permits 
                    SET status = 'rejected', reviewed_by = ?, review_notes = ?, reviewed_at = NOW()
                    WHERE id = ?
                ");
                $stmtUp->execute([$userId, $notes, $permitId]);
                $pdo->commit();
                flash('info', 'Pengajuan izin telah ditolak.');
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('error', 'Gagal memproses pengajuan izin: ' . $e->getMessage());
        }
    }
    redirect('permits.php');
}

// FILTER PERIZINAN
$statusFilter = $_GET['status'] ?? 'pending';
$nameFilter = trim($_GET['name'] ?? '');

$where = ["1=1"];
$params = [];

if ($statusFilter !== '') {
    $where[] = "sp.status = ?";
    $params[] = $statusFilter;
}

if ($nameFilter !== '') {
    $where[] = "s.name LIKE ?";
    $params[] = "%{$nameFilter}%";
}

// Penguncian Unit bagi Kepala Sekolah
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("SELECT unit_id FROM admin_unit_permissions WHERE user_id = ? LIMIT 1");
    $stmtKs->execute([$userId]);
    $ksUnitId = $stmtKs->fetchColumn();
    if ($ksUnitId) {
        $where[] = "g.unit_id = ?";
        $params[] = (int)$ksUnitId;
    }
}

$whereSql = implode(' AND ', $where);

$stmtList = $pdo->prepare("
    SELECT sp.*, s.name as student_name, s.nis, s.photo as student_photo,
           cg.name as class_name, g.grade, un.unit as unit_name,
           u.name as reviewer_name
    FROM student_permits sp
    INNER JOIN students s ON s.id = sp.student_id
    LEFT JOIN class_groups cg ON cg.id = sp.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    LEFT JOIN units un ON un.id = g.unit_id
    LEFT JOIN users u ON u.id = sp.reviewed_by
    WHERE {$whereSql}
    ORDER BY sp.created_at DESC
");
$stmtList->execute($params);
$permits = $stmtList->fetchAll(PDO::FETCH_ASSOC);

require '../../includes/header.php';
?>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header" style="flex-wrap: wrap; gap: 10px; justify-content: space-between;">
        <div>
            <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">
                <i class="fa-solid fa-file-medical" style="color: #0284c7;"></i> Persetujuan Surat Izin & Sakit Siswa
            </h3>
            <small style="color: #64748b;">Verifikasi permohonan surat izin/sakit yang diajukan siswa lewat web</small>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="students.php" class="btn" style="background: #e2e8f0; color: #334155;">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Absensi Siswa
            </a>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET">
        <div class="filter-grid" style="align-items: flex-end;">
            <div class="form-group">
                <label>Status</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>Semua Status</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>⏳ Menunggu Persetujuan</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>✅ Disetujui</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>❌ Ditolak</option>
                </select>
            </div>
            <div class="form-group">
                <label>Nama Siswa</label>
                <input type="text" name="name" value="<?= e($nameFilter) ?>" placeholder="Cari nama siswa...">
            </div>
            <div>
                <button type="submit" class="btn btn-primary" style="margin-top: 22px;">🔎 Cari</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">No</th>
                    <th>Siswa</th>
                    <th>Kelas / Unit</th>
                    <th>Jenis</th>
                    <th>Rentang Tanggal</th>
                    <th>Alasan</th>
                    <th>Lampiran Berkas</th>
                    <th>Status</th>
                    <th>Aksi Verifikasi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($permits)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #94a3b8;">
                            Tidak ada pengajuan surat izin/sakit yang sesuai filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($permits as $row): 
                        $badges = [
                            'pending'  => ['#fef3c7', '#92400e', 'Menunggu'],
                            'approved' => ['#dcfce7', '#166534', 'Disetujui'],
                            'rejected' => ['#fee2e2', '#991b1b', 'Ditolak']
                        ];
                        $sb = $badges[$row['status']] ?? ['#f1f5f9', '#475569', $row['status']];
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <strong style="color: #0f172a;"><?= e($row['student_name']) ?></strong>
                                <div style="font-size: 11.5px; color: #64748b;">NIS: <?= e($row['nis'] ?? '-') ?></div>
                            </td>
                            <td>
                                <div><?= e($row['grade'] ?? '') ?> - <?= e($row['class_name'] ?? '') ?></div>
                                <small style="color: #0284c7; font-weight: 700;"><?= e($row['unit_name'] ?? '') ?></small>
                            </td>
                            <td>
                                <span style="font-weight: 800; text-transform: uppercase; font-size: 11.5px; color: <?= $row['permit_type'] === 'sakit' ? '#7c3aed' : '#0284c7' ?>;">
                                    <?= e($row['permit_type']) ?>
                                </span>
                            </td>
                            <td>
                                <?= date('d M Y', strtotime($row['start_date'])) ?> s/d <?= date('d M Y', strtotime($row['end_date'])) ?>
                            </td>
                            <td><?= e($row['reason']) ?></td>
                            <td>
                                <?php if (!empty($row['attachment'])): ?>
                                    <a href="<?= UPLOAD_URL ?>/permits/<?= e($row['attachment']) ?>" target="_blank" class="btn" style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; font-size: 11.5px; text-decoration: none; border-radius: 6px;">
                                        <i class="fa-solid fa-file"></i> Berkas
                                    </a>
                                <?php else: ?>
                                    <span style="color: #cbd5e1; font-size: 12px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; background: <?= $sb[0] ?>; color: <?= $sb[1] ?>;">
                                    <?= $sb[2] ?>
                                </span>
                                <?php if (!empty($row['reviewer_name'])): ?>
                                    <div style="font-size: 10px; color: #94a3b8; margin-top: 3px;">oleh: <?= e($row['reviewer_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'pending'): ?>
                                    <div style="display: flex; gap: 6px;">
                                        <form method="POST" onsubmit="return confirm('Setujui pengajuan izin ini? Catatan absensi siswa akan otomatis disinkronkan.')">
                                            <input type="hidden" name="permit_id" value="<?= $row['id'] ?>">
                                            <input type="hidden" name="action_permit" value="approve">
                                            <button type="submit" class="btn btn-success" style="padding: 4px 10px; font-size: 12px;">
                                                <i class="fa-solid fa-check"></i> Setujui
                                            </button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Tolak permohonan izin ini?')">
                                            <input type="hidden" name="permit_id" value="<?= $row['id'] ?>">
                                            <input type="hidden" name="action_permit" value="reject">
                                            <button type="submit" class="btn btn-danger" style="padding: 4px 10px; font-size: 12px;">
                                                <i class="fa-solid fa-xmark"></i> Tolak
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: #94a3b8; font-style: italic;">Selesai</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>
