<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Hanya Super Admin, Admin, dan Kepala Sekolah yang boleh mengelola kurikulum adab
if (!in_array(currentRole(), ['super_admin', 'admin', 'kepala_sekolah'])) {
    flash('danger', 'Anda tidak memiliki hak akses untuk mengelola kurikulum adab.');
    redirect('index.php');
}

$pageTitle = 'Master Kurikulum Adab Santri';

// DAFTAR NAMA BULAN DALAM BAHASA INDONESIA
$monthsList = [
    7  => 'Juli (Awal Semester Ganjil)',
    8  => 'Agustus',
    9  => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember (Akhir Semester Ganjil)',
    1  => 'Januari (Awal Semester Genap)',
    2  => 'Februari',
    3  => 'Maret',
    4  => 'April',
    5  => 'Mei',
    6  => 'Juni (Akhir Semester Genap)'
];

// AMBIL DAFTAR TAHUN PELAJARAN
$academicYears = $pdo->query("SELECT id, name, status FROM academic_years ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$activeAyId = 0;
foreach ($academicYears as $ay) {
    if ($ay['status'] === 'active') {
        $activeAyId = (int)$ay['id'];
        break;
    }
}
if ($activeAyId === 0 && !empty($academicYears)) {
    $activeAyId = (int)$academicYears[0]['id'];
}

$filterAyId     = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : $activeAyId;
$filterSemester = trim($_GET['semester'] ?? '');
$filterMonth    = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
$filterGrade    = (int)($_GET['grade'] ?? 0);
$filterWeek     = trim($_GET['week'] ?? '');

// PROSES SIMPAN / UPDATE KURIKULUM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Sesi telah kadaluarsa. Silakan ulangi kembali.');
        redirect('curriculum.php?academic_year_id=' . $filterAyId);
    }

    $action       = $_POST['action'];
    $postAyId     = (int)($_POST['academic_year_id'] ?? $filterAyId);
    $semester     = in_array($_POST['semester'] ?? '', ['ganjil', 'genap']) ? $_POST['semester'] : 'ganjil';
    $monthNumber  = !empty($_POST['month_number']) ? (int)$_POST['month_number'] : null;
    $gradeLevel   = (int)($_POST['grade_level'] ?? 7);
    $weekCode     = trim($_POST['week_code'] ?? 'Pekan 1');
    $title        = trim($_POST['title'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $fileLink     = trim($_POST['file_link'] ?? '');

    $task1 = trim($_POST['task_1'] ?? '');
    $task2 = trim($_POST['task_2'] ?? '');
    $task3 = trim($_POST['task_3'] ?? '');
    $task4 = trim($_POST['task_4'] ?? '');

    $p1 = trim($_POST['reflection_prompt_1'] ?? 'Bagaimana amalan adab ini berhasil saya laksanakan pekan ini?');
    $p2 = trim($_POST['reflection_prompt_2'] ?? 'Tantangan atau kendala apa yang saya temui saat mengamalkannya?');
    $p3 = trim($_POST['reflection_prompt_3'] ?? 'Langkah perbaikan apa yang akan saya lakukan selanjutnya?');
    $p4 = trim($_POST['reflection_prompt_4'] ?? 'Rasa syukur atau hikmah apa yang saya rasakan setelah melakukannya?');

    if ($action === 'create' && !empty($title)) {
        $stmtIns = $pdo->prepare("
            INSERT INTO mentoring_curriculums (
                title, grade_level, academic_year_id, semester, month_number, week_code, description, file_link,
                task_1, task_2, task_3, task_4,
                reflection_prompt_1, reflection_prompt_2, reflection_prompt_3, reflection_prompt_4,
                is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmtIns->execute([
            $title, $gradeLevel, $postAyId, $semester, $monthNumber, $weekCode, $description, $fileLink,
            $task1, $task2, $task3, $task4,
            $p1, $p2, $p3, $p4
        ]);
        flash('success', 'Modul materi kurikulum baru berhasil ditambahkan.');
    } elseif ($action === 'update') {
        $currId = (int)($_POST['curriculum_id'] ?? 0);
        if ($currId > 0 && !empty($title)) {
            $stmtUp = $pdo->prepare("
                UPDATE mentoring_curriculums SET
                    title = ?, grade_level = ?, academic_year_id = ?, semester = ?, month_number = ?,
                    week_code = ?, description = ?, file_link = ?,
                    task_1 = ?, task_2 = ?, task_3 = ?, task_4 = ?,
                    reflection_prompt_1 = ?, reflection_prompt_2 = ?, reflection_prompt_3 = ?, reflection_prompt_4 = ?
                WHERE id = ?
            ");
            $stmtUp->execute([
                $title, $gradeLevel, $postAyId, $semester, $monthNumber,
                $weekCode, $description, $fileLink,
                $task1, $task2, $task3, $task4,
                $p1, $p2, $p3, $p4,
                $currId
            ]);
            flash('success', 'Modul materi kurikulum berhasil diperbarui.');
        }
    } elseif ($action === 'toggle_active') {
        $currId = (int)($_POST['curriculum_id'] ?? 0);
        $pdo->prepare("UPDATE mentoring_curriculums SET is_active = IF(is_active=1, 0, 1) WHERE id = ?")->execute([$currId]);
        flash('success', 'Status keaktifan materi berhasil diubah.');
    }
    redirect('curriculum.php?academic_year_id=' . $postAyId);
}

// FILTER BUILDER
$where = ["1=1"];
$params = [];

if ($filterAyId > 0) {
    $where[] = "(c.academic_year_id = ? OR c.academic_year_id IS NULL)";
    $params[] = $filterAyId;
}
if (!empty($filterSemester)) {
    $where[] = "c.semester = ?";
    $params[] = $filterSemester;
}
if ($filterMonth !== null && $filterMonth > 0) {
    $where[] = "c.month_number = ?";
    $params[] = $filterMonth;
}
if ($filterGrade > 0) {
    $where[] = "c.grade_level = ?";
    $params[] = $filterGrade;
}
if ($filterWeek !== '') {
    $where[] = "c.week_code = ?";
    $params[] = $filterWeek;
}
$whereSql = implode(' AND ', $where);

$stmtCurrs = $pdo->prepare("
    SELECT c.*, ay.name as academic_year_name
    FROM mentoring_curriculums c
    LEFT JOIN academic_years ay ON ay.id = c.academic_year_id
    WHERE {$whereSql}
    ORDER BY c.grade_level ASC, c.month_number ASC, c.week_code ASC, c.id ASC
");
$stmtCurrs->execute($params);
$curriculums = $stmtCurrs->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../../includes/header.php';
?>

<div class="content">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-book-quran" style="color: #059669;"></i> Master Kurikulum Adab Berjenjang
            </h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">
                Silabus amalan checklist santri dan refleksi terikat Tahun Pelajaran, Semester, dan Bulan Kalender
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="assignments.php?academic_year_id=<?= $filterAyId ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 13px;">
                <i class="fa-solid fa-users-rectangle"></i> Kelompok Mentee & Mentor
            </a>
            <button type="button" class="btn btn-primary" onclick="openAddModal()" style="padding: 8px 18px; font-size: 13px; font-weight: 700; border-radius: 8px; background: #059669; border-color: #059669;">
                <i class="fa-solid fa-plus"></i> Tambah Modul Adab
            </button>
        </div>
    </div>

    <!-- Filter Card Berjenjang -->
    <div class="card" style="padding: 16px 20px; border-radius: 14px; margin-bottom: 20px; background: #ffffff;">
        <form method="GET" action="curriculum.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <!-- Tahun Pelajaran -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Tahun Pelajaran</label>
                <select name="academic_year_id" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1.5px solid #059669; font-size: 13px; font-weight: 700; color: #047857; background: #ecfdf5;" onchange="this.form.submit()">
                    <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $filterAyId == $ay['id'] ? 'selected' : '' ?>>
                            TP <?= e($ay['name']) ?> <?= $ay['status'] === 'active' ? '(Aktif)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Semester -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Semester</label>
                <select name="semester" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Semester --</option>
                    <option value="ganjil" <?= $filterSemester === 'ganjil' ? 'selected' : '' ?>>Semester Ganjil</option>
                    <option value="genap" <?= $filterSemester === 'genap' ? 'selected' : '' ?>>Semester Genap</option>
                </select>
            </div>

            <!-- Bulan Kalender -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Bulan</label>
                <select name="month" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Bulan --</option>
                    <?php foreach ($monthsList as $mNum => $mLabel): ?>
                        <option value="<?= $mNum ?>" <?= $filterMonth === $mNum ? 'selected' : '' ?>><?= $mLabel ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Tingkat Kelas -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Tingkat Kelas</label>
                <select name="grade" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                    <option value="0">-- Semua Kelas --</option>
                    <option value="7" <?= $filterGrade === 7 ? 'selected' : '' ?>>Kelas 7</option>
                    <option value="8" <?= $filterGrade === 8 ? 'selected' : '' ?>>Kelas 8</option>
                    <option value="9" <?= $filterGrade === 9 ? 'selected' : '' ?>>Kelas 9</option>
                </select>
            </div>

            <!-- Pekan -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Pekan</label>
                <select name="week" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Pekan --</option>
                    <option value="Pekan 1" <?= $filterWeek === 'Pekan 1' ? 'selected' : '' ?>>Pekan 1</option>
                    <option value="Pekan 2" <?= $filterWeek === 'Pekan 2' ? 'selected' : '' ?>>Pekan 2</option>
                    <option value="Pekan 3" <?= $filterWeek === 'Pekan 3' ? 'selected' : '' ?>>Pekan 3</option>
                    <option value="Pekan 4" <?= $filterWeek === 'Pekan 4' ? 'selected' : '' ?>>Pekan 4</option>
                </select>
            </div>

            <div style="align-self: flex-end;">
                <?php if ($filterGrade > 0 || !empty($filterWeek) || !empty($filterSemester) || $filterMonth !== null): ?>
                    <a href="curriculum.php?academic_year_id=<?= $filterAyId ?>" class="btn btn-secondary" style="padding: 8px 14px; font-size: 13px;">Reset Filter</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Tabel Daftar Kurikulum -->
    <div class="card" style="padding: 20px; border-radius: 16px; background: #ffffff;">
        <div class="table-responsive">
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 12px;">Periode & Jenjang</th>
                        <th style="padding: 12px;">Judul Modul Materi Adab</th>
                        <th style="padding: 12px;">4 Butir Amalan Checklist Santri</th>
                        <th style="padding: 12px; text-align: center;">Status</th>
                        <th style="padding: 12px; text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($curriculums)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 35px; color: #94a3b8;">
                                <i class="fa-solid fa-book-open" style="font-size: 32px; margin-bottom: 8px; display: block; opacity: 0.5;"></i>
                                Belum ada kurikulum yang sesuai dengan filter periode ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($curriculums as $c): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                                <td style="padding: 12px; min-width: 170px;">
                                    <div style="display: flex; gap: 6px; align-items: center; margin-bottom: 6px;">
                                        <span style="padding: 2px 7px; border-radius: 6px; font-size: 11.5px; font-weight: 800; background: #e0f2fe; color: #0369a1;">
                                            Kelas <?= $c['grade_level'] ?>
                                        </span>
                                        <span style="padding: 2px 7px; border-radius: 6px; font-size: 11.5px; font-weight: 700; background: #fef3c7; color: #92400e;">
                                            <?= e($c['week_code']) ?>
                                        </span>
                                    </div>
                                    <div style="font-size: 12px; color: #475569; font-weight: 600;">
                                        <?= !empty($c['month_number']) ? ($monthsList[$c['month_number']] ?? 'Bulan ' . $c['month_number']) : 'Semua Bulan' ?>
                                    </div>
                                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase;">
                                        Sem. <?= e($c['semester'] ?? 'Ganjil') ?> • <?= e($c['academic_year_name'] ?? 'TP Aktif') ?>
                                    </div>
                                </td>
                                <td style="padding: 12px; max-width: 260px;">
                                    <strong style="font-size: 14px; color: #0f172a; display: block; line-height: 1.3;">
                                        <?= e($c['title']) ?>
                                    </strong>
                                    <?php if (!empty($c['description'])): ?>
                                        <p style="margin: 4px 0 0; font-size: 11.5px; color: #64748b; line-height: 1.4;">
                                            <?= e(mb_strimwidth($c['description'], 0, 95, '...')) ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; max-width: 330px; font-size: 12px; color: #334155;">
                                    <ul style="margin: 0; padding-left: 18px; line-height: 1.5;">
                                        <?php if (!empty($c['task_1'])): ?><li><?= e($c['task_1']) ?></li><?php endif; ?>
                                        <?php if (!empty($c['task_2'])): ?><li><?= e($c['task_2']) ?></li><?php endif; ?>
                                        <?php if (!empty($c['task_3'])): ?><li><?= e($c['task_3']) ?></li><?php endif; ?>
                                        <?php if (!empty($c['task_4'])): ?><li><?= e($c['task_4']) ?></li><?php endif; ?>
                                    </ul>
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <form method="POST" action="curriculum.php" style="display: inline;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="curriculum_id" value="<?= $c['id'] ?>">
                                        <button type="submit" style="background: none; border: none; cursor: pointer; padding: 0;">
                                            <span style="padding: 4px 9px; border-radius: 6px; font-size: 11.5px; font-weight: 700; background: <?= $c['is_active'] ? '#ecfdf5' : '#f1f5f9' ?>; color: <?= $c['is_active'] ? '#065f46' : '#94a3b8' ?>; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fa-solid <?= $c['is_active'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                                                <?= $c['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                            </span>
                                        </button>
                                    </form>
                                </td>
                                <td style="padding: 12px; text-align: right; white-space: nowrap;">
                                    <button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;" onclick="openEditModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH / EDIT KURIKULUM -->
<div id="modalCurr" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: white; border-radius: 16px; width: 100%; max-width: 680px; max-height: 90vh; overflow-y: auto; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;" id="modalCurrTitle">
                <i class="fa-solid fa-book-open" style="color: #059669;"></i> Kelola Modul Materi Adab
            </h3>
            <button type="button" onclick="closeCurrModal()" style="border: none; background: none; font-size: 20px; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="curriculum.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="modalCurrAction" value="create">
            <input type="hidden" name="curriculum_id" id="modalCurrId" value="">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 14px;">
                <!-- Pengikat Tahun Pelajaran -->
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Tahun Pelajaran</label>
                    <select name="academic_year_id" id="inputAcademicYearId" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" required>
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $filterAyId == $ay['id'] ? 'selected' : '' ?>>TP <?= e($ay['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Semester -->
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Semester</label>
                    <select name="semester" id="inputSemester" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" required>
                        <option value="ganjil">Semester Ganjil</option>
                        <option value="genap">Semester Genap</option>
                    </select>
                </div>

                <!-- Bulan Kalender -->
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Bulan</label>
                    <select name="month_number" id="inputMonthNumber" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                        <option value="">-- Berlaku Semua Bulan --</option>
                        <?php foreach ($monthsList as $mNum => $mLabel): ?>
                            <option value="<?= $mNum ?>"><?= $mLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Tingkat Jenjang Santri</label>
                    <select name="grade_level" id="inputGradeLevel" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" required>
                        <option value="7">Kelas 7 (Fase Transisi & Adab Dasar)</option>
                        <option value="8">Kelas 8 (Fase Penguatan & Kedisiplinan)</option>
                        <option value="9">Kelas 9 (Fase Teladan & Kepemimpinan)</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Pekan Siklus Amalan</label>
                    <select name="week_code" id="inputWeekCode" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" required>
                        <option value="Pekan 1">Pekan 1</option>
                        <option value="Pekan 2">Pekan 2</option>
                        <option value="Pekan 3">Pekan 3</option>
                        <option value="Pekan 4">Pekan 4</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Judul Modul Adab</label>
                <input type="text" name="title" id="inputTitle" placeholder="Contoh: Adab Bergaul & Berbicara Santun Kepada Teman" required style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Dalil & Uraian Pengantar</label>
                <textarea name="description" id="inputDescription" rows="2" placeholder="Tuliskan dalil Al-Qur'an / Hadits / nasihat ulama pengantar adab..." style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px; resize: vertical;"></textarea>
            </div>

            <!-- 4 Butir Amalan Checklist -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-bottom: 16px;">
                <label style="display: block; font-size: 12px; font-weight: 800; color: #059669; text-transform: uppercase; margin-bottom: 8px;">
                    <i class="fa-solid fa-square-check"></i> 4 Butir Checklist Amalan Santri (Selasa, Rabu, Kamis)
                </label>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <input type="text" name="task_1" id="inputTask1" placeholder="Amalan 1: misal Senyum, salam, dan menyapa saat berpapasan..." style="width: 100%; padding: 7px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                    <input type="text" name="task_2" id="inputTask2" placeholder="Amalan 2: misal Berbicara jujur dan tidak memotong pembicaraan..." style="width: 100%; padding: 7px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                    <input type="text" name="task_3" id="inputTask3" placeholder="Amalan 3: misal Menjaga kebersihan meja dan loker pribadi..." style="width: 100%; padding: 7px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                    <input type="text" name="task_4" id="inputTask4" placeholder="Amalan 4: misal Mendoakan kebaikan untuk teman dan guru..." style="width: 100%; padding: 7px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeCurrModal()" class="btn btn-secondary" style="padding: 9px 18px; font-size: 13px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding: 9px 24px; font-size: 13px; font-weight: 700; background: #059669; border-color: #059669;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Modul Kurikulum
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalCurrTitle').innerHTML = '<i class="fa-solid fa-plus" style="color:#059669;"></i> Tambah Modul Kurikulum Baru';
    document.getElementById('modalCurrAction').value = 'create';
    document.getElementById('modalCurrId').value = '';
    document.getElementById('inputAcademicYearId').value = '<?= $filterAyId ?>';
    document.getElementById('inputSemester').value = 'ganjil';
    document.getElementById('inputMonthNumber').value = '';
    document.getElementById('inputTitle').value = '';
    document.getElementById('inputGradeLevel').value = '7';
    document.getElementById('inputWeekCode').value = 'Pekan 1';
    document.getElementById('inputDescription').value = '';
    document.getElementById('inputTask1').value = '';
    document.getElementById('inputTask2').value = '';
    document.getElementById('inputTask3').value = '';
    document.getElementById('inputTask4').value = '';
    document.getElementById('modalCurr').style.display = 'flex';
}

function openEditModal(c) {
    document.getElementById('modalCurrTitle').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:#059669;"></i> Edit Modul Kurikulum Adab';
    document.getElementById('modalCurrAction').value = 'update';
    document.getElementById('modalCurrId').value = c.id;
    document.getElementById('inputAcademicYearId').value = c.academic_year_id || '<?= $filterAyId ?>';
    document.getElementById('inputSemester').value = c.semester || 'ganjil';
    document.getElementById('inputMonthNumber').value = c.month_number || '';
    document.getElementById('inputTitle').value = c.title || '';
    document.getElementById('inputGradeLevel').value = c.grade_level || '7';
    document.getElementById('inputWeekCode').value = c.week_code || 'Pekan 1';
    document.getElementById('inputDescription').value = c.description || '';
    document.getElementById('inputTask1').value = c.task_1 || '';
    document.getElementById('inputTask2').value = c.task_2 || '';
    document.getElementById('inputTask3').value = c.task_3 || '';
    document.getElementById('inputTask4').value = c.task_4 || '';
    document.getElementById('modalCurr').style.display = 'flex';
}

function closeCurrModal() {
    document.getElementById('modalCurr').style.display = 'none';
}

window.onclick = function(event) {
    var modal = document.getElementById('modalCurr');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
