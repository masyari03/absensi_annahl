<?php
$pageTitle = 'Pembiasaan Adab & Refleksi Santri';
require_once __DIR__ . '/includes/header.php';

// Tentukan Tingkat Kelas (7, 8, atau 9)
$className = $studentData['class_name'] ?? '';
$gradeNum = 7;
if (strpos($className, '8') !== false) {
    $gradeNum = 8;
} elseif (strpos($className, '9') !== false) {
    $gradeNum = 9;
}

// Tentukan Pekan Aktif (Pekan 1 s.d. Pekan 4)
// Jika tidak diset, hitung pekan dalam bulan berjalan
$currentWeekNum = (int)ceil(date('j') / 7);
if ($currentWeekNum > 4) $currentWeekNum = 4;
$selectedWeek = $_GET['week'] ?? ('Pekan ' . $currentWeekNum);
$validWeeks = ['Pekan 1', 'Pekan 2', 'Pekan 3', 'Pekan 4'];
if (!in_array($selectedWeek, $validWeeks)) {
    $selectedWeek = 'Pekan 1';
}

// Tentukan Hari Aktif untuk tab checklist ('Selasa', 'Rabu', 'Kamis')
$selectedDay = $_GET['day'] ?? 'Selasa';
$validDays = ['Selasa', 'Rabu', 'Kamis'];
if (!in_array($selectedDay, $validDays)) {
    $selectedDay = 'Selasa';
}

// Tarik Materi Kurikulum Sesuai Jenjang & Pekan
$stmtCurr = $pdo->prepare("
    SELECT * FROM mentoring_curriculums 
    WHERE grade_level = ? AND week_code = ? AND is_active = 1 
    LIMIT 1
");
$stmtCurr->execute([$gradeNum, $selectedWeek]);
$curriculum = $stmtCurr->fetch(PDO::FETCH_ASSOC);

// Tarik data log adab yang sudah tersimpan untuk santri ini di pekan tersebut
$stmtLog = $pdo->prepare("
    SELECT * FROM student_adab_logs 
    WHERE student_id = ? AND week_code = ? 
    ORDER BY submitted_at DESC LIMIT 1
");
$stmtLog->execute([$studentId, $selectedWeek]);
$existingLog = $stmtLog->fetch(PDO::FETCH_ASSOC);

// Parse JSON checklist
$checklistData = [
    'Selasa' => [false, false, false, false],
    'Rabu'   => [false, false, false, false],
    'Kamis'  => [false, false, false, false]
];
if ($existingLog && !empty($existingLog['checklist_json'])) {
    $decoded = json_decode($existingLog['checklist_json'], true);
    if (is_array($decoded)) {
        foreach (['Selasa', 'Rabu', 'Kamis'] as $d) {
            if (isset($decoded[$d]) && is_array($decoded[$d])) {
                $checklistData[$d] = array_slice(array_pad($decoded[$d], 4, false), 0, 4);
            }
        }
    }
}

// PROSES SUBMIT FORM
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_adab') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Sesi telah kadaluarsa. Silakan ulangi kembali.');
        header("Location: adab.php?week=" . urlencode($selectedWeek) . "&day=" . urlencode($selectedDay));
        exit;
    }

    $postDay = $_POST['day'] ?? $selectedDay;
    if (in_array($postDay, $validDays)) {
        $selectedDay = $postDay;
    }

    // Ambil nilai checkbox 4 butir amalan untuk hari yang disubmit
    $dayChecks = [];
    for ($i = 0; $i < 4; $i++) {
        $dayChecks[] = !empty($_POST['task_' . $i]);
    }
    $checklistData[$selectedDay] = $dayChecks;

    $reflectionAction    = trim($_POST['reflection_action'] ?? '');
    $reflectionObstacle  = trim($_POST['reflection_obstacle'] ?? '');
    $reflectionSolution  = trim($_POST['reflection_solution'] ?? '');
    $reflectionGratitude = trim($_POST['reflection_gratitude'] ?? '');

    try {
        $jsonChecklist = json_encode($checklistData);
        $currId = $curriculum ? (int)$curriculum['id'] : null;

        if ($existingLog) {
            // Update catatan log
            $stmtUp = $pdo->prepare("
                UPDATE student_adab_logs SET
                    curriculum_id = ?,
                    day_name = ?,
                    checklist_json = ?,
                    reflection_action = ?,
                    reflection_obstacle = ?,
                    reflection_solution = ?,
                    reflection_gratitude = ?,
                    submitted_at = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([
                $currId,
                $selectedDay,
                $jsonChecklist,
                $reflectionAction,
                $reflectionObstacle,
                $reflectionSolution,
                $reflectionGratitude,
                $existingLog['id']
            ]);
        } else {
            // Insert log baru
            $stmtIns = $pdo->prepare("
                INSERT INTO student_adab_logs (
                    student_id, curriculum_id, grade_name, week_code, day_name,
                    checklist_json, reflection_action, reflection_obstacle,
                    reflection_solution, reflection_gratitude, submitted_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtIns->execute([
                $studentId,
                $currId,
                $className,
                $selectedWeek,
                $selectedDay,
                $jsonChecklist,
                $reflectionAction,
                $reflectionObstacle,
                $reflectionSolution,
                $reflectionGratitude
            ]);
        }

        setFlash('success', 'Alhamdulillah! Catatan pembiasaan adab dan refleksi Anda berhasil disimpan.');
        header("Location: adab.php?week=" . urlencode($selectedWeek) . "&day=" . urlencode($selectedDay));
        exit;
    } catch (Exception $e) {
        $errorMessage = 'Gagal menyimpan data: ' . $e->getMessage();
    }
}
?>

<div style="display: flex; flex-direction: column; gap: 20px;">

    <!-- Flash message -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-radius: 12px; background: <?= $flash['type'] === 'success' ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $flash['type'] === 'success' ? '#065f46' : '#991b1b' ?>; border: 1px solid <?= $flash['type'] === 'success' ? '#a7f3d0' : '#fecaca' ?>;">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="font-size: 18px;"></i>
            <span style="font-size: 13.5px; font-weight: 600;"><?= e($flash['message']) ?></span>
        </div>
    <?php endif; ?>

    <!-- Banner Header & Selector Pekan -->
    <div class="card" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; padding: 24px; border-radius: 16px; border: none; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <div style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.3); border-radius: 20px; font-size: 11px; font-weight: 700; color: #38bdf8; text-transform: uppercase; margin-bottom: 8px;">
                    <i class="fa-solid fa-seedling"></i> Pembiasaan Adab Santri
                </div>
                <h2 style="margin: 0; font-size: 20px; font-weight: 800; letter-spacing: -0.01em;">
                    <?= $curriculum ? e($curriculum['title']) : 'Modul Adab Santri' ?>
                </h2>
                <p style="margin: 4px 0 0; color: #94a3b8; font-size: 13px;">
                    Kelas <?= e($className) ?> • <?= e($selectedWeek) ?> • Istiqomah Membentuk Akhlak Mulia
                </p>
            </div>

            <!-- Selector Pilihan Pekan -->
            <div style="display: flex; gap: 6px; background: rgba(255,255,255,0.06); padding: 4px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1);">
                <?php foreach ($validWeeks as $wk): ?>
                    <a href="adab.php?week=<?= urlencode($wk) ?>&day=<?= urlencode($selectedDay) ?>" 
                       style="padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.2s; color: <?= $selectedWeek === $wk ? '#0f172a' : '#cbd5e1' ?>; background: <?= $selectedWeek === $wk ? '#38bdf8' : 'transparent' ?>;">
                        <?= $wk ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($curriculum && !empty($curriculum['description'])): ?>
            <div style="margin-top: 16px; padding: 14px; background: rgba(255,255,255,0.04); border-radius: 10px; border-left: 3px solid #38bdf8; font-size: 12.5px; line-height: 1.6; color: #cbd5e1;">
                <i class="fa-solid fa-quote-left" style="color: #38bdf8; margin-right: 6px;"></i>
                <?= nl2br(e($curriculum['description'])) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Form Utama: Tab Hari & Checklist Amalan -->
    <div class="card" style="padding: 24px; border-radius: 16px;">
        <form method="POST" action="adab.php?week=<?= urlencode($selectedWeek) ?>&day=<?= urlencode($selectedDay) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_adab">
            <input type="hidden" name="day" id="inputDay" value="<?= e($selectedDay) ?>">

            <!-- Tab Pemilihan Hari Amalan -->
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 20px;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                        <i class="fa-solid fa-clipboard-check" style="color: #0284c7;"></i> Checklist Amalan Harian
                    </h3>
                    <small style="color: #64748b;">Pilih hari dan centang amalan yang telah Anda laksanakan dengan jujur</small>
                </div>

                <div style="display: flex; gap: 8px;">
                    <?php foreach ($validDays as $d): ?>
                        <a href="adab.php?week=<?= urlencode($selectedWeek) ?>&day=<?= urlencode($d) ?>"
                           style="padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; text-decoration: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; color: <?= $selectedDay === $d ? '#ffffff' : '#64748b' ?>; background: <?= $selectedDay === $d ? '#0284c7' : '#f1f5f9' ?>;">
                            <i class="fa-regular fa-calendar-check"></i> <?= $d ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 4 Butir Tugas Amalan Berdasarkan Kurikulum -->
            <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 28px;">
                <?php
                $tasks = [
                    $curriculum['task_1'] ?? 'Menjalankan kewajiban sholat tepat waktu.',
                    $curriculum['task_2'] ?? 'Senantiasa menjaga lisan dan sopan santun.',
                    $curriculum['task_3'] ?? 'Merasa diawasi oleh Allah SWT dalam setiap aktivitas.',
                    $curriculum['task_4'] ?? 'Menjauhi perbuatan yang dilarang agama.'
                ];
                $currentChecks = $checklistData[$selectedDay] ?? [false, false, false, false];

                foreach ($tasks as $idx => $tText):
                    if (empty($tText) || $tText === '-') continue;
                    $isChecked = !empty($currentChecks[$idx]);
                ?>
                    <label style="display: flex; align-items: flex-start; gap: 14px; padding: 14px 16px; border-radius: 12px; border: 1.5px solid <?= $isChecked ? '#bae6fd' : '#e2e8f0' ?>; background: <?= $isChecked ? '#f0f9ff' : '#ffffff' ?>; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="task_<?= $idx ?>" value="1" <?= $isChecked ? 'checked' : '' ?> 
                               style="width: 20px; height: 20px; accent-color: #0284c7; margin-top: 2px; cursor: pointer;">
                        <div style="flex: 1;">
                            <span style="font-size: 13.5px; font-weight: 600; color: #1e293b; line-height: 1.5;">
                                <?= e($tText) ?>
                            </span>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>

            <!-- Bagian 4 Dimensi Refleksi Karakter -->
            <div style="border-top: 2px solid #f1f5f9; padding-top: 20px; margin-bottom: 24px;">
                <h3 style="margin: 0 0 4px; font-size: 16px; font-weight: 800; color: #0f172a;">
                    <i class="fa-solid fa-heart-pulse" style="color: #ec4899;"></i> Refleksi Karakter 4 Dimensi
                </h3>
                <p style="margin: 0 0 16px; font-size: 12.5px; color: #64748b;">
                    Tuangkan pengalaman nyata, rintangan yang dihadapi, ikhtiar solusi, dan rasa syukur Anda pada pekan ini.
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                    <!-- 1. Aksi Nyata -->
                    <div style="background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                            <i class="fa-solid fa-bullseye" style="color: #0284c7;"></i> 1. Aksi Nyata
                        </label>
                        <small style="display: block; font-size: 11.5px; color: #64748b; margin-bottom: 8px;">
                            Apa tindakan konkret adab yang telah berhasil Anda praktikkan?
                        </small>
                        <textarea name="reflection_action" rows="3" class="filter-input" style="width: 100%; border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px; font-size: 13px; resize: vertical;" placeholder="Contoh: Saya menahan diri untuk tidak membalas ejekan teman dan meminta izin sebelum meminjam buku..."><?= e($existingLog['reflection_action'] ?? '') ?></textarea>
                    </div>

                    <!-- 2. Hambatan -->
                    <div style="background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                            <i class="fa-solid fa-mountain" style="color: #f59e0b;"></i> 2. Hambatan
                        </label>
                        <small style="display: block; font-size: 11.5px; color: #64748b; margin-bottom: 8px;">
                            Apa godaan, kemalasan, atau kendala yang sempat Anda rasakan?
                        </small>
                        <textarea name="reflection_obstacle" rows="3" class="filter-input" style="width: 100%; border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px; font-size: 13px; resize: vertical;" placeholder="Contoh: Terkadang masih terbawa emosi saat bermain dan lupa membaca doa safar..."><?= e($existingLog['reflection_obstacle'] ?? '') ?></textarea>
                    </div>

                    <!-- 3. Solusi -->
                    <div style="background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                            <i class="fa-solid fa-lightbulb" style="color: #10b981;"></i> 3. Solusi
                        </label>
                        <small style="display: block; font-size: 11.5px; color: #64748b; margin-bottom: 8px;">
                            Apa langkah perbaikan yang akan Anda lakukan selanjutnya?
                        </small>
                        <textarea name="reflection_solution" rows="3" class="filter-input" style="width: 100%; border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px; font-size: 13px; resize: vertical;" placeholder="Contoh: Mengingatkan diri dengan mencatat jadwal sholat dan beristighfar jika mulai marah..."><?= e($existingLog['reflection_solution'] ?? '') ?></textarea>
                    </div>

                    <!-- 4. Rasa Syukur -->
                    <div style="background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                            <i class="fa-solid fa-hands-praying" style="color: #8b5cf6;"></i> 4. Rasa Syukur
                        </label>
                        <small style="display: block; font-size: 11.5px; color: #64748b; margin-bottom: 8px;">
                            Nikmat atau kebaikan apa yang paling Anda syukuri pekan ini?
                        </small>
                        <textarea name="reflection_gratitude" rows="3" class="filter-input" style="width: 100%; border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px; font-size: 13px; resize: vertical;" placeholder="Contoh: Bersyukur masih diberikan kesehatan, teman yang saling mengingatkan kebaikan, dan bimbingan guru..."><?= e($existingLog['reflection_gratitude'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Tombol Simpan -->
            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="submit" class="btn btn-primary" style="padding: 12px 28px; font-size: 14px; font-weight: 700; border-radius: 10px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Catatan Adab Hari <?= e($selectedDay) ?>
                </button>
            </div>
        </form>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
