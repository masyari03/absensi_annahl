<?php
ob_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireStudent();

$currentUserId = (int)$_SESSION['user_id'];
$studentId = (int)($_SESSION['student_id'] ?? 0);

if ($studentId <= 0 && $currentUserId > 0) {
    $stmtFind = $pdo->prepare("SELECT student_id FROM users WHERE id = ?");
    $stmtFind->execute([$currentUserId]);
    $studentId = (int)$stmtFind->fetchColumn();
    $_SESSION['student_id'] = $studentId;
}

// Tarik data kelas siswa saat ini
$stmtStudent = $pdo->prepare("
    SELECT s.*, cg.name as class_name, g.grade
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN grades g ON g.id = cg.grade_id
    WHERE s.id = ? LIMIT 1
");
$stmtStudent->execute([$studentId]);
$studentData = $stmtStudent->fetch(PDO::FETCH_ASSOC);

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

// Tentukan Bulan Kalender & Tahun Pelajaran Aktif
$monthsList = [
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober',
    11 => 'November', 12 => 'Desember', 1 => 'Januari', 2 => 'Februari',
    3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni'
];

$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if (!isset($monthsList[$selectedMonth])) {
    $selectedMonth = (int)date('n');
}
$monthName = $monthsList[$selectedMonth] ?? 'Bulan ' . $selectedMonth;

$activeAyId = (int)$pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1")->fetchColumn() ?: 1;

// Tarik Materi Kurikulum Sesuai Jenjang, Pekan, Bulan & Tahun Pelajaran Aktif
$stmtCurr = $pdo->prepare("
    SELECT * FROM mentoring_curriculums 
    WHERE grade_level = ? AND week_code = ? AND is_active = 1
      AND (academic_year_id = ? OR academic_year_id IS NULL)
    ORDER BY (month_number = ?) DESC, id DESC
    LIMIT 1
");
$stmtCurr->execute([$gradeNum, $selectedWeek, $activeAyId, $selectedMonth]);
$curriculum = $stmtCurr->fetch(PDO::FETCH_ASSOC);

// 1. Tentukan Hari Aktif Pemantauan (Fleksibel dari Kurikulum Adab atau Default Sekolah)
$validDays = ['Rabu', 'Kamis', 'Jumat'];
if ($curriculum && !empty($curriculum['active_days'])) {
    $decodedDays = json_decode($curriculum['active_days'], true);
    if (is_array($decodedDays) && !empty($decodedDays)) {
        $validDays = $decodedDays;
    }
} else {
    $defaultDaysSetting = getSystemSetting($pdo, 'mentoring_default_active_days', '["Rabu","Kamis","Jumat"]');
    $decodedDefault = json_decode($defaultDaysSetting, true);
    if (is_array($decodedDefault) && !empty($decodedDefault)) {
        $validDays = $decodedDefault;
    }
}

$selectedDay = $_GET['day'] ?? $validDays[0];
if (!in_array($selectedDay, $validDays)) {
    $selectedDay = $validDays[0];
}

// 2. Tentukan Butir Penugasan Dinamis (Bebas berapa saja)
$curriculumTasks = [];
if ($curriculum && !empty($curriculum['tasks_json'])) {
    $decodedTasks = json_decode($curriculum['tasks_json'], true);
    if (is_array($decodedTasks) && !empty($decodedTasks)) {
        $curriculumTasks = $decodedTasks;
    }
}
if (empty($curriculumTasks) && $curriculum) {
    $curriculumTasks = array_values(array_filter([
        $curriculum['task_1'] ?? '',
        $curriculum['task_2'] ?? '',
        $curriculum['task_3'] ?? '',
        $curriculum['task_4'] ?? ''
    ], fn($t) => !empty($t) && $t !== '-'));
}
if (empty($curriculumTasks)) {
    $curriculumTasks = [
        'Berwudhu sebelum membaca Al-Qur\'an.',
        'Berniat ikhlas karna Allah.',
        'Mengawali dengan ta\'awudz dan basmalah.',
        'Membaca Al-Qur\'an dengan tartil.',
        'Mengamalkan kandungan Al-Qur\'an.'
    ];
}
$totalTasksCount = count($curriculumTasks);

// 3. Tentukan Pertanyaan Refleksi Dinamis
$reflectionPrompts = [];
if ($curriculum && !empty($curriculum['reflection_prompts_json'])) {
    $decodedPrompts = json_decode($curriculum['reflection_prompts_json'], true);
    if (is_array($decodedPrompts) && !empty($decodedPrompts)) {
        $reflectionPrompts = $decodedPrompts;
    }
}
if (empty($reflectionPrompts) && $curriculum) {
    $reflectionPrompts = array_values(array_filter([
        $curriculum['reflection_prompt_1'] ?? '',
        $curriculum['reflection_prompt_2'] ?? '',
        $curriculum['reflection_prompt_3'] ?? '',
        $curriculum['reflection_prompt_4'] ?? ''
    ], fn($p) => !empty($p)));
}
if (empty($reflectionPrompts)) {
    $reflectionPrompts = [
        'Apa yang kamu rasakan ketika membaca atau mendengarkan ayat-ayat Al-Qur\'an?',
        'Apa yang biasanya kamu lakukan ketika menemukan Al-Qur\'an diletakkan di tempat yang kurang layak?',
        'Apakah kamu sudah menjadikan Al-Qur\'an sebagai pedoman hidupmu?',
        'Sudahkah kamu membiasakan tilawah setiap hari?',
        'Selanjutnya apa yang akan kamu perbaiki dari adab terhadap Al-Qur\'an?'
    ];
}

// 4. Tarik data log adab yang sudah tersimpan untuk santri ini di pekan & kurikulum tersebut
$currId = $curriculum ? (int)$curriculum['id'] : 0;
$stmtLog = $pdo->prepare("
    SELECT * FROM student_adab_logs 
    WHERE student_id = ? AND week_code = ?
      AND (curriculum_id = ? OR ? = 0)
    ORDER BY submitted_at DESC LIMIT 1
");
$stmtLog->execute([$studentId, $selectedWeek, $currId, $currId]);
$existingLog = $stmtLog->fetch(PDO::FETCH_ASSOC);

// Inisialisasi struktur checklist untuk seluruh hari aktif
$checklistData = [];
foreach ($validDays as $d) {
    $checklistData[$d] = array_fill(0, $totalTasksCount, false);
}
if ($existingLog && !empty($existingLog['checklist_json'])) {
    $decoded = json_decode($existingLog['checklist_json'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $dKey => $checks) {
            if (is_array($checks)) {
                $checklistData[$dKey] = array_values(array_pad($checks, $totalTasksCount, false));
            }
        }
    }
}

// Inisialisasi jawaban refleksi yang sudah tersimpan
$savedReflections = [];
if ($existingLog) {
    if (!empty($existingLog['reflections_json'])) {
        $decRef = json_decode($existingLog['reflections_json'], true);
        if (is_array($decRef)) $savedReflections = $decRef;
    }
    if (empty($savedReflections)) {
        $savedReflections = [
            $existingLog['reflection_action'] ?? '',
            $existingLog['reflection_obstacle'] ?? '',
            $existingLog['reflection_solution'] ?? '',
            $existingLog['reflection_gratitude'] ?? ''
        ];
    }
}

// PROSES SUBMIT FORM (SEBELUM HEADER HTML RENDERED)
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_adab') {
    $postMonth = isset($_POST['month']) ? (int)$_POST['month'] : $selectedMonth;
    if (isset($monthsList[$postMonth])) {
        $selectedMonth = $postMonth;
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Sesi telah kadaluarsa. Silakan ulangi kembali.');
        redirect("adab.php?month=" . $selectedMonth . "&week=" . urlencode($selectedWeek) . "&day=" . urlencode($selectedDay));
    }

    $postDay = $_POST['day'] ?? $selectedDay;
    if (in_array($postDay, $validDays)) {
        $selectedDay = $postDay;
    }

    // Ambil nilai checkbox butir amalan untuk hari yang disubmit
    $dayChecks = [];
    for ($i = 0; $i < $totalTasksCount; $i++) {
        $dayChecks[] = !empty($_POST['task_' . $i]);
    }
    $checklistData[$selectedDay] = $dayChecks;

    // Ambil jawaban refleksi dinamis
    $submittedReflections = [];
    for ($i = 0; $i < count($reflectionPrompts); $i++) {
        $submittedReflections[] = trim($_POST['reflection_' . $i] ?? '');
    }
    $jsonReflections = json_encode($submittedReflections, JSON_UNESCAPED_UNICODE);

    // Kompatibilitas dengan 4 kolom refleksi lama
    $reflectionAction    = $submittedReflections[0] ?? '';
    $reflectionObstacle  = $submittedReflections[1] ?? '';
    $reflectionSolution  = $submittedReflections[2] ?? '';
    $reflectionGratitude = $submittedReflections[3] ?? '';

    try {
        $jsonChecklist = json_encode($checklistData, JSON_UNESCAPED_UNICODE);
        $currId = $curriculum ? (int)$curriculum['id'] : null;

        if ($existingLog) {
            // Update catatan log
            $stmtUp = $pdo->prepare("
                UPDATE student_adab_logs SET
                    curriculum_id = ?,
                    day_name = ?,
                    checklist_json = ?,
                    reflections_json = ?,
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
                $jsonReflections,
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
                    checklist_json, reflections_json, reflection_action, reflection_obstacle,
                    reflection_solution, reflection_gratitude, submitted_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtIns->execute([
                $studentId,
                $currId,
                $className,
                $selectedWeek,
                $selectedDay,
                $jsonChecklist,
                $jsonReflections,
                $reflectionAction,
                $reflectionObstacle,
                $reflectionSolution,
                $reflectionGratitude
            ]);
        }

        // Kirim notifikasi ke Guru Mentor pembina kelompok santri ini (Plan Edisi 14)
        if (function_exists('createNotification')) {
            try {
                $stmtMentor = $pdo->prepare("
                    SELECT ma.mentor_user_id, s.name as student_name
                    FROM mentoring_assignments ma
                    JOIN students s ON s.id = ma.student_id
                    WHERE ma.student_id = ? AND ma.is_active = 1
                    LIMIT 1
                ");
                $stmtMentor->execute([$studentId]);
                $mentorRow = $stmtMentor->fetch(PDO::FETCH_ASSOC);
                if ($mentorRow && !empty($mentorRow['mentor_user_id'])) {
                    $mentorUserId = (int)$mentorRow['mentor_user_id'];
                    $stName = $mentorRow['student_name'] ?? 'Santri';
                    $notifTitle = "Jurnal Adab Diisi: {$stName}";
                    $notifMsg = "Santri binaan Anda, {$stName}, telah mengisi catatan adab & refleksi untuk {$selectedWeek} ({$selectedDay}).";
                    $notifUrl = BASE_URL . "/mentoring/adab_monitoring.php?search=" . urlencode($stName);
                    createNotification($pdo, $mentorUserId, $notifTitle, $notifMsg, 'mentoring', $notifUrl);
                }
            } catch (Exception $ne) {
                // Null safety
            }
        }

        flash('success', 'Alhamdulillah! Catatan pembiasaan adab dan refleksi Anda berhasil disimpan.');
        redirect("adab.php?month=" . $selectedMonth . "&week=" . urlencode($selectedWeek) . "&day=" . urlencode($selectedDay));
    } catch (Exception $e) {
        $errorMessage = 'Gagal menyimpan data: ' . $e->getMessage();
    }
}

// SETELAH POST SELESAI DIPROSES, BARU INCLUDE HEADER VIEW HTML
$pageTitle = 'Pembiasaan Adab & Refleksi Santri';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; flex-direction: column; gap: 20px;">

    <!-- Flash message -->
    <?php $flash = $flash ?? getFlash(); if ($flash): ?>
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
                    Kelas <?= e($className) ?> • <?= e($monthName) ?> • <?= e($selectedWeek) ?> • Istiqomah Membentuk Akhlak Mulia
                </p>
            </div>

            <!-- Selector Bulan & Pekan -->
            <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                <!-- Filter Dropdown Bulan -->
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 12px; color: #94a3b8; font-weight: 700;">Bulan:</span>
                    <select onchange="location.href='adab.php?month=' + this.value + '&week=<?= urlencode($selectedWeek) ?>&day=<?= urlencode($selectedDay) ?>'"
                            style="padding: 6px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.2); background: #1e293b; color: #ffffff; font-size: 12px; font-weight: 700; outline: none; cursor: pointer;">
                        <?php foreach ($monthsList as $mNum => $mLabel): ?>
                            <option value="<?= $mNum ?>" <?= $selectedMonth == $mNum ? 'selected' : '' ?>>
                                <?= $mLabel ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Selector Pilihan Pekan -->
                <div style="display: flex; gap: 6px; background: rgba(255,255,255,0.06); padding: 4px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); flex-wrap: wrap;">
                    <?php foreach ($validWeeks as $wk): ?>
                        <a href="adab.php?month=<?= $selectedMonth ?>&week=<?= urlencode($wk) ?>&day=<?= urlencode($selectedDay) ?>" 
                           style="padding: 7px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.2s; color: <?= $selectedWeek === $wk ? '#0f172a' : '#cbd5e1' ?>; background: <?= $selectedWeek === $wk ? '#38bdf8' : 'transparent' ?>;">
                            <?= $wk ?>
                        </a>
                    <?php endforeach; ?>
                </div>
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
        <form method="POST" action="adab.php?month=<?= $selectedMonth ?>&week=<?= urlencode($selectedWeek) ?>&day=<?= urlencode($selectedDay) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_adab">
            <input type="hidden" name="month" value="<?= $selectedMonth ?>">
            <input type="hidden" name="day" id="inputDay" value="<?= e($selectedDay) ?>">

            <!-- Tab Pemilihan Hari Amalan -->
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">
                        <i class="fa-solid fa-clipboard-check" style="color: #0284c7;"></i> Checklist Amalan Harian
                    </h3>
                    <small style="color: #64748b;">Pilih hari dan centang amalan yang telah Anda laksanakan dengan jujur</small>
                </div>

                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php foreach ($validDays as $d): ?>
                        <a href="adab.php?month=<?= $selectedMonth ?>&week=<?= urlencode($selectedWeek) ?>&day=<?= urlencode($d) ?>"
                           style="padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; text-decoration: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; color: <?= $selectedDay === $d ? '#ffffff' : '#64748b' ?>; background: <?= $selectedDay === $d ? '#0284c7' : '#f1f5f9' ?>;">
                            <i class="fa-regular fa-calendar-check"></i> <?= $d ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Butir Tugas Amalan Berdasarkan Kurikulum Dinamis -->
            <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 28px;">
                <?php
                $currentChecks = $checklistData[$selectedDay] ?? array_fill(0, $totalTasksCount, false);

                foreach ($curriculumTasks as $idx => $tText):
                    if (empty($tText) || $tText === '-') continue;
                    $isChecked = !empty($currentChecks[$idx]);
                ?>
                    <label style="display: flex; align-items: flex-start; gap: 14px; padding: 14px 16px; border-radius: 12px; border: 1.5px solid <?= $isChecked ? '#bae6fd' : '#e2e8f0' ?>; background: <?= $isChecked ? '#f0f9ff' : '#ffffff' ?>; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="task_<?= $idx ?>" value="1" <?= $isChecked ? 'checked' : '' ?> 
                               style="width: 20px; height: 20px; accent-color: #0284c7; margin-top: 2px; cursor: pointer;">
                        <div style="flex: 1;">
                            <span style="font-size: 13.5px; font-weight: 600; color: #1e293b; line-height: 1.5;">
                                <?= ($idx + 1) ?>. <?= e($tText) ?>
                            </span>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>

            <!-- Bagian Refleksi Karakter Dinamis -->
            <div style="border-top: 2px solid #f1f5f9; padding-top: 20px; margin-bottom: 24px;">
                <h3 style="margin: 0 0 4px; font-size: 16px; font-weight: 800; color: #0f172a;">
                    <i class="fa-solid fa-heart-pulse" style="color: #ec4899;"></i> Refleksi Karakter & Pemahaman Santri
                </h3>
                <p style="margin: 0 0 16px; font-size: 12.5px; color: #64748b;">
                    Tuangkan pengalaman nyata, pemahaman materi adab, dan muhasabah diri Anda pada pekan ini secara jujur.
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                    <?php 
                    $refIcons = ['fa-bullseye', 'fa-mountain', 'fa-lightbulb', 'fa-hands-praying', 'fa-compass', 'fa-shield-heart'];
                    $refColors = ['#0284c7', '#f59e0b', '#10b981', '#8b5cf6', '#0ea5e9', '#ec4899'];
                    
                    foreach ($reflectionPrompts as $idx => $promptText):
                        $icon = $refIcons[$idx % count($refIcons)];
                        $color = $refColors[$idx % count($refColors)];
                        $val = $savedReflections[$idx] ?? '';
                    ?>
                        <div style="background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
                            <label style="display: block; font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                                <i class="fa-solid <?= $icon ?>" style="color: <?= $color ?>;"></i> <?= ($idx + 1) ?>. Refleksi <?= ($idx + 1) ?>
                            </label>
                            <small style="display: block; font-size: 11.5px; color: #64748b; margin-bottom: 8px; line-height: 1.4;">
                                <?= e($promptText) ?>
                            </small>
                            <textarea name="reflection_<?= $idx ?>" rows="3" class="filter-input" style="width: 100%; border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px; font-size: 13px; resize: vertical;" placeholder="Tuliskan jawaban refleksi Anda..."><?= e($val) ?></textarea>
                        </div>
                    <?php endforeach; ?>
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
