<?php
$pageTitle = 'Catatan Mentoring Siswa (MIP)';
require_once __DIR__ . '/includes/header.php';

// Tarik data mentor pembimbing
$stmtMentor = $pdo->prepare("
    SELECT u.name as mentor_name, u.username, st.phone as mentor_phone, u.photo as mentor_photo
    FROM mentoring_assignments ma
    JOIN users u ON u.id = ma.mentor_user_id
    LEFT JOIN staff st ON st.user_id = u.id
    WHERE ma.student_id = ? AND ma.is_active = 1
    LIMIT 1
");
$stmtMentor->execute([$studentId]);
$mentorData = $stmtMentor->fetch(PDO::FETCH_ASSOC);

// Tarik seluruh riwayat sesi evaluasi bimbingan santri ini
$stmtSessions = $pdo->prepare("
    SELECT ms.*, u.name as evaluator_name
    FROM mentoring_sessions ms
    JOIN users u ON u.id = ms.mentor_user_id
    WHERE ms.student_id = ?
    ORDER BY ms.session_date DESC, ms.id DESC
");
$stmtSessions->execute([$studentId]);
$sessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

// Hitung rata-rata skor per pilar
$avgScores = [
    'mip' => 0, 'academic' => 0, 'mutabaah' => 0, 'adab' => 0
];
$counts = ['mip' => 0, 'academic' => 0, 'mutabaah' => 0, 'adab' => 0];

foreach ($sessions as $s) {
    if (!empty($s['score_mip'])) { $avgScores['mip'] += (float)$s['score_mip']; $counts['mip']++; }
    if (!empty($s['score_academic'])) { $avgScores['academic'] += (float)$s['score_academic']; $counts['academic']++; }
    if (!empty($s['score_mutabaah'])) { $avgScores['mutabaah'] += (float)$s['score_mutabaah']; $counts['mutabaah']++; }
    if (!empty($s['score_adab'])) { $avgScores['adab'] += (float)$s['score_adab']; $counts['adab']++; }
}
foreach ($avgScores as $k => $v) {
    $avgScores[$k] = $counts[$k] > 0 ? round($v / $counts[$k], 1) : 0;
}
?>

<div style="display: flex; flex-direction: column; gap: 20px;">

    <!-- Header & Profil Mentor -->
    <div class="card" style="background: linear-gradient(135deg, #064e3b 0%, #065f46 100%); color: white; padding: 24px; border-radius: 16px; border: none; box-shadow: 0 10px 25px -5px rgba(6, 78, 59, 0.25);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 56px; height: 56px; border-radius: 14px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 24px; color: #a7f3d0; border: 1px solid rgba(255,255,255,0.2);">
                    <i class="fa-solid fa-hands-holding-child"></i>
                </div>
                <div>
                    <div style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 8px; background: rgba(167, 243, 208, 0.2); border-radius: 20px; font-size: 11px; font-weight: 700; color: #a7f3d0; text-transform: uppercase; margin-bottom: 4px;">
                        <i class="fa-solid fa-user-tie"></i> Guru Mentor Pendamping
                    </div>
                    <h2 style="margin: 0; font-size: 20px; font-weight: 800; letter-spacing: -0.01em;">
                        <?= $mentorData ? e($mentorData['mentor_name']) : 'Belum Ditugaskan' ?>
                    </h2>
                    <p style="margin: 2px 0 0; color: #a7f3d0; font-size: 13px;">
                        Santri: <strong><?= e($studentData['name']) ?></strong> (<?= e($studentData['class_name'] ?? 'Santri') ?>)
                    </p>
                </div>
            </div>

            <?php if ($mentorData && !empty($mentorData['mentor_phone'])): ?>
                <?php 
                $cleanPhone = preg_replace('/[^0-9]/', '', $mentorData['mentor_phone']);
                if (substr($cleanPhone, 0, 1) === '0') $cleanPhone = '62' . substr($cleanPhone, 1);
                ?>
                <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" style="padding: 10px 18px; border-radius: 10px; background: #ffffff; color: #065f46; font-size: 13px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                    <i class="fa-brands fa-whatsapp" style="font-size: 16px; color: #16a34a;"></i> Hubungi Mentor
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ringkasan Rata-rata 4 Pilar -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
        <div class="card" style="padding: 16px; border-radius: 14px; text-align: center; border-left: 4px solid #0284c7;">
            <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">1. Progres MIP & Jurnal</span>
            <div style="font-size: 24px; font-weight: 800; color: #0284c7; margin-top: 4px;">
                <?= $avgScores['mip'] > 0 ? $avgScores['mip'] : '-' ?> <small style="font-size: 13px; color: #94a3b8;">/10</small>
            </div>
        </div>

        <div class="card" style="padding: 16px; border-radius: 14px; text-align: center; border-left: 4px solid #10b981;">
            <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">2. Akademik & Mapel</span>
            <div style="font-size: 24px; font-weight: 800; color: #10b981; margin-top: 4px;">
                <?= $avgScores['academic'] > 0 ? $avgScores['academic'] : '-' ?> <small style="font-size: 13px; color: #94a3b8;">/10</small>
            </div>
        </div>

        <div class="card" style="padding: 16px; border-radius: 14px; text-align: center; border-left: 4px solid #f59e0b;">
            <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">3. Mutaba'ah Ibadah</span>
            <div style="font-size: 24px; font-weight: 800; color: #f59e0b; margin-top: 4px;">
                <?= $avgScores['mutabaah'] > 0 ? $avgScores['mutabaah'] : '-' ?> <small style="font-size: 13px; color: #94a3b8;">/10</small>
            </div>
        </div>

        <div class="card" style="padding: 16px; border-radius: 14px; text-align: center; border-left: 4px solid #8b5cf6;">
            <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">4. Adab & Akhlak</span>
            <div style="font-size: 24px; font-weight: 800; color: #8b5cf6; margin-top: 4px;">
                <?= $avgScores['adab'] > 0 ? $avgScores['adab'] : '-' ?> <small style="font-size: 13px; color: #94a3b8;">/10</small>
            </div>
        </div>
    </div>

    <!-- Riwayat Sesi Evaluasi Bimbingan -->
    <div class="card" style="padding: 24px; border-radius: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                    <i class="fa-solid fa-list-check" style="color: #059669;"></i> Riwayat Sesi Evaluasi Mentoring
                </h3>
                <small style="color: #64748b;">Catatan bimbingan langsung dari guru mentor pekan demi pekan</small>
            </div>
            <span style="padding: 4px 10px; background: #ecfdf5; color: #065f46; font-size: 12px; font-weight: 700; border-radius: 8px;">
                Total <?= count($sessions) ?> Sesi
            </span>
        </div>

        <?php if (empty($sessions)): ?>
            <div style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                <i class="fa-solid fa-book-open" style="font-size: 32px; margin-bottom: 12px; opacity: 0.5;"></i>
                <p style="margin: 0; font-weight: 600; font-size: 14px;">Belum ada catatan evaluasi mentoring dari guru pendamping.</p>
                <small>Catatan akan muncul otomatis setelah sesi mentoring tatap muka berlangsung.</small>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <?php foreach ($sessions as $idx => $s): ?>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; transition: all 0.2s;">
                        <!-- Sesi Header Bar -->
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 14px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="background: #0284c7; color: white; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 800;">
                                    <?= e($s['session_code']) ?>
                                </span>
                                <span style="font-size: 13.5px; font-weight: 700; color: #0f172a;">
                                    <?= date('d M Y', strtotime($s['session_date'])) ?>
                                </span>
                            </div>
                            <small style="color: #64748b; font-size: 12px;">
                                Evaluator: <strong><?= e($s['evaluator_name']) ?></strong>
                            </small>
                        </div>

                        <!-- 4 Pilar Scores & Notes Grid -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 14px;">
                            <!-- Pilar 1 -->
                            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <span style="font-size: 11.5px; font-weight: 700; color: #0284c7;">1. MIP</span>
                                    <span style="font-size: 12px; font-weight: 800; color: #0f172a;"><?= !empty($s['score_mip']) ? $s['score_mip'] : '-' ?></span>
                                </div>
                                <p style="margin: 0; font-size: 12px; color: #475569; line-height: 1.4;">
                                    <?= !empty($s['notes_mip']) ? e($s['notes_mip']) : '<span style="color:#94a3b8;">-</span>' ?>
                                </p>
                            </div>

                            <!-- Pilar 2 -->
                            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <span style="font-size: 11.5px; font-weight: 700; color: #10b981;">2. Akademik</span>
                                    <span style="font-size: 12px; font-weight: 800; color: #0f172a;"><?= !empty($s['score_academic']) ? $s['score_academic'] : '-' ?></span>
                                </div>
                                <p style="margin: 0; font-size: 12px; color: #475569; line-height: 1.4;">
                                    <?= !empty($s['notes_academic']) ? e($s['notes_academic']) : '<span style="color:#94a3b8;">-</span>' ?>
                                </p>
                            </div>

                            <!-- Pilar 3 -->
                            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <span style="font-size: 11.5px; font-weight: 700; color: #f59e0b;">3. Mutaba'ah</span>
                                    <span style="font-size: 12px; font-weight: 800; color: #0f172a;"><?= !empty($s['score_mutabaah']) ? $s['score_mutabaah'] : '-' ?></span>
                                </div>
                                <p style="margin: 0; font-size: 12px; color: #475569; line-height: 1.4;">
                                    <?= !empty($s['notes_mutabaah']) ? e($s['notes_mutabaah']) : '<span style="color:#94a3b8;">-</span>' ?>
                                </p>
                            </div>

                            <!-- Pilar 4 -->
                            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <span style="font-size: 11.5px; font-weight: 700; color: #8b5cf6;">4. Adab</span>
                                    <span style="font-size: 12px; font-weight: 800; color: #0f172a;"><?= !empty($s['score_adab']) ? $s['score_adab'] : '-' ?></span>
                                </div>
                                <p style="margin: 0; font-size: 12px; color: #475569; line-height: 1.4;">
                                    <?= !empty($s['notes_adab']) ? e($s['notes_adab']) : '<span style="color:#94a3b8;">-</span>' ?>
                                </p>
                            </div>
                        </div>

                        <!-- Masukan & Solusi Mentor -->
                        <?php if (!empty($s['notes_feedback'])): ?>
                            <div style="background: #fdf2f8; border: 1px solid #fbcfe8; border-radius: 10px; padding: 12px 14px;">
                                <span style="font-size: 11.5px; font-weight: 700; color: #db2777; display: block; margin-bottom: 2px;">
                                    <i class="fa-solid fa-comment-dots"></i> Masukan & Arahan Khusus Mentor:
                                </span>
                                <p style="margin: 0; font-size: 12.5px; color: #831843; line-height: 1.5;">
                                    <?= nl2br(e($s['notes_feedback'])) ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
