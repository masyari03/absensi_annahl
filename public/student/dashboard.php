<?php
$pageTitle = 'Dashboard Siswa';
require_once __DIR__ . '/includes/header.php';

$currentMonth = date('Y-m');
$monthName = date('F Y');

// Hitung statistik absensi bulan ini
$stmtStats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'tepat_waktu' THEN 1 ELSE 0 END) as count_present,
        SUM(CASE WHEN status = 'terlambat' THEN 1 ELSE 0 END) as count_late,
        SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) as count_permit,
        SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) as count_sick,
        SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as count_absent
    FROM student_attendances
    WHERE student_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
");
$stmtStats->execute([$studentId, $currentMonth]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

$totalDays = (int)($stats['total_days'] ?? 0);
$present = (int)($stats['count_present'] ?? 0);
$late = (int)($stats['count_late'] ?? 0);
$permit = (int)($stats['count_permit'] ?? 0);
$sick = (int)($stats['count_sick'] ?? 0);
$absent = (int)($stats['count_absent'] ?? 0);

$totalAttended = $present + $late;
$attendancePercentage = $totalDays > 0 ? round(($totalAttended / $totalDays) * 100, 1) : 100;

// Riwayat 7 Hari Terakhir
$stmtRecent = $pdo->prepare("
    SELECT *
    FROM student_attendances
    WHERE student_id = ?
    ORDER BY attendance_date DESC
    LIMIT 7
");
$stmtRecent->execute([$studentId]);
$recentAttendances = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

// Hitung jumlah prestasi siswa
$stmtAch = $pdo->prepare("SELECT COUNT(*) FROM student_achievements WHERE student_id = ? AND status = 'verified'");
$stmtAch->execute([$studentId]);
$verifiedAchievementsCount = (int)$stmtAch->fetchColumn();
?>

<!-- Welcome Banner -->
<div style="background: linear-gradient(135deg, #0284c7, #2563eb); border-radius: 16px; padding: 26px 30px; color: #ffffff; margin-bottom: 24px; box-shadow: 0 10px 20px -5px rgba(2, 132, 199, 0.3); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
    <div>
        <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
            PORTAL SISWA RESMI
        </span>
        <h1 style="margin: 10px 0 6px 0; font-size: 24px; font-weight: 800;">
            Assalamu'alaikum, <?= e($studentData['name'] ?? 'Siswa') ?>! 👋
        </h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">
            Kelas <?= e($studentData['grade'] ?? '') ?> - <?= e($studentData['class_name'] ?? '') ?> • Unit <?= e($studentData['unit_name'] ?? '') ?> • NIS: <strong><?= e($studentData['nis'] ?? '-') ?></strong>
        </p>
    </div>
    <div style="background: rgba(255,255,255,0.15); backdrop-filter: blur(5px); border-radius: 12px; padding: 12px 20px; text-align: center; border: 1px solid rgba(255,255,255,0.25);">
        <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; opacity: 0.85;">Kehadiran Bulan Ini</div>
        <div style="font-size: 28px; font-weight: 800; line-height: 1.1; margin-top: 2px;"><?= $attendancePercentage ?>%</div>
        <div style="font-size: 11px; opacity: 0.85; margin-top: 2px;"><?= e($monthName) ?></div>
    </div>
</div>

<!-- KPI Grid Statistik Absensi Bulan Ini -->
<h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 14px 0;">
    <i class="fa-solid fa-chart-pie" style="color: #0284c7;"></i> Rekapan Kehadiran Bulan Ini (<?= e($monthName) ?>)
</h3>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px;">
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span style="font-size: 12.5px; font-weight: 600; color: #64748b;">Tepat Waktu</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
        <div style="font-size: 24px; font-weight: 800; color: #16a34a;"><?= number_format($present) ?> <small style="font-size: 13px; font-weight: 500; color: #64748b;">hari</small></div>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span style="font-size: 12.5px; font-weight: 600; color: #64748b;">Terlambat</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                <i class="fa-solid fa-clock"></i>
            </div>
        </div>
        <div style="font-size: 24px; font-weight: 800; color: #d97706;"><?= number_format($late) ?> <small style="font-size: 13px; font-weight: 500; color: #64748b;">hari</small></div>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span style="font-size: 12.5px; font-weight: 600; color: #64748b;">Izin</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                <i class="fa-solid fa-file-lines"></i>
            </div>
        </div>
        <div style="font-size: 24px; font-weight: 800; color: #0284c7;"><?= number_format($permit) ?> <small style="font-size: 13px; font-weight: 500; color: #64748b;">hari</small></div>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span style="font-size: 12.5px; font-weight: 600; color: #64748b;">Sakit</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: #ede9fe; color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                <i class="fa-solid fa-hospital-user"></i>
            </div>
        </div>
        <div style="font-size: 24px; font-weight: 800; color: #7c3aed;"><?= number_format($sick) ?> <small style="font-size: 13px; font-weight: 500; color: #64748b;">hari</small></div>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span style="font-size: 12.5px; font-weight: 600; color: #64748b;">Tanpa Keterangan</span>
            <div style="width: 32px; height: 32px; border-radius: 8px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                <i class="fa-solid fa-circle-xmark"></i>
            </div>
        </div>
        <div style="font-size: 24px; font-weight: 800; color: #dc2626;"><?= number_format($absent) ?> <small style="font-size: 13px; font-weight: 500; color: #64748b;">hari</small></div>
    </div>
</div>

<!-- Grid Baris Bawah: Riwayat 7 Hari & Kartu Aksi Cepat -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
    <!-- Riwayat 7 Hari Terakhir -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a;">
                <i class="fa-solid fa-clock-rotate-left" style="color: #0284c7;"></i> Riwayat Tap Absensi Terbaru
            </h3>
            <a href="attendance.php" style="font-size: 12.5px; font-weight: 700; color: #0284c7; text-decoration: none;">
                Lihat Semua <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <?php if (empty($recentAttendances)): ?>
            <div style="text-align: center; padding: 30px 10px; color: #94a3b8;">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 32px; margin-bottom: 8px;"></i>
                <p style="margin: 0; font-size: 13px;">Belum ada riwayat tap absensi yang tercatat.</p>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($recentAttendances as $att): 
                    $statusStyles = [
                        'tepat_waktu' => ['#dcfce7', '#166534', 'Hadir Tepat Waktu'],
                        'terlambat'   => ['#fef3c7', '#92400e', 'Terlambat'],
                        'izin'        => ['#e0f2fe', '#075985', 'Izin'],
                        'sakit'       => ['#ede9fe', '#5b21b6', 'Sakit'],
                        'alpha'       => ['#fee2e2', '#991b1b', 'Alpha'],
                    ];
                    $st = $statusStyles[$att['status']] ?? ['#f1f5f9', '#475569', $att['status']];
                ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <div>
                            <div style="font-size: 13.5px; font-weight: 700; color: #1e293b;">
                                <?= date('l, d M Y', strtotime($att['attendance_date'])) ?>
                            </div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                Masuk: <strong><?= !empty($att['time_in']) ? date('H:i', strtotime($att['time_in'])) : '-' ?></strong> 
                                • Pulang: <strong><?= !empty($att['time_out']) ? date('H:i', strtotime($att['time_out'])) : '-' ?></strong>
                            </div>
                        </div>
                        <span style="font-size: 11.5px; font-weight: 700; padding: 4px 10px; border-radius: 20px; background: <?= $st[0] ?>; color: <?= $st[1] ?>;">
                            <?= $st[2] ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pintasan & Status Fitur -->
    <div style="display: flex; flex-direction: column; gap: 16px;">
        <!-- PINTASAN SURAT IZIN (KONDISIONAL MASTER SWITCH SUPER ADMIN) -->
        <?php if ($webPermitEnabled): ?>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; align-items: center; gap: 16px;">
                <div style="width: 50px; height: 50px; border-radius: 12px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                    <i class="fa-solid fa-file-medical"></i>
                </div>
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 800; color: #0f172a;">Pengajuan Surat Izin / Sakit</h4>
                    <p style="margin: 0; font-size: 12.5px; color: #64748b; line-height: 1.4;">Tidak bisa masuk sekolah? Unggah foto surat dokter/keterangan ortu sekarang.</p>
                </div>
                <a href="permit_upload.php" class="btn btn-primary" style="font-size: 12.5px; font-weight: 700; white-space: nowrap;">
                    Ajukan <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>

        <!-- PINTASAN PRESTASI SISWA -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; align-items: center; gap: 16px;">
            <div style="width: 50px; height: 50px; border-radius: 12px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                <i class="fa-solid fa-trophy"></i>
            </div>
            <div style="flex: 1;">
                <h4 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 800; color: #0f172a;">Prestasi & Piagam Siswa</h4>
                <p style="margin: 0; font-size: 12.5px; color: #64748b; line-height: 1.4;">
                    Kamu memiliki <strong><?= $verifiedAchievementsCount ?></strong> piagam terverifikasi. Unggah sertifikat barumu!
                </p>
            </div>
            <a href="achievements.php" class="btn" style="background: #f59e0b; color: white; font-size: 12.5px; font-weight: 700; white-space: nowrap;">
                Unggah <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <!-- INFORMASI KEAMANAN AKUN -->
        <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 14px; padding: 16px 18px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                <i class="fa-solid fa-shield-halved" style="color: #0284c7; font-size: 16px;"></i>
                <strong style="font-size: 13.5px; color: #1e293b;">Keamanan Akun Siswa</strong>
            </div>
            <p style="margin: 0; font-size: 12.5px; color: #64748b; line-height: 1.5;">
                Pastikan mengganti password default "password" dengan password baru yang rahasia. Jangan berikan akses login kepada orang lain.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
