<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Hanya Super Admin & Kepala Sekolah yang boleh mengelola kurikulum
if (!in_array(currentRole(), ['super_admin', 'kepala_sekolah'])) {
    flash('danger', 'Anda tidak memiliki hak akses untuk mengelola master kurikulum.');
    redirect('index.php');
}

$pageTitle = 'Master Kurikulum Adab Santri';

// PROSES SIMPAN / UPDATE KURIKULUM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Sesi telah kadaluarsa. Silakan ulangi kembali.');
        redirect('curriculum.php');
    }

    $action = $_POST['action'];
    $title = trim($_POST['title'] ?? '');
    $gradeLevel = (int)($_POST['grade_level'] ?? 7);
    $weekCode = trim($_POST['week_code'] ?? 'Pekan 1');
    $description = trim($_POST['description'] ?? '');
    $fileLink = trim($_POST['file_link'] ?? '');

    $task1 = trim($_POST['task_1'] ?? '');
    $task2 = trim($_POST['task_2'] ?? '');
    $task3 = trim($_POST['task_3'] ?? '');
    $task4 = trim($_POST['task_4'] ?? '');

    $p1 = trim($_POST['reflection_prompt_1'] ?? '');
    $p2 = trim($_POST['reflection_prompt_2'] ?? '');
    $p3 = trim($_POST['reflection_prompt_3'] ?? '');
    $p4 = trim($_POST['reflection_prompt_4'] ?? '');

    if ($action === 'create' && !empty($title)) {
        $stmtIns = $pdo->prepare("
            INSERT INTO mentoring_curriculums (
                title, grade_level, week_code, description, file_link,
                task_1, task_2, task_3, task_4,
                reflection_prompt_1, reflection_prompt_2, reflection_prompt_3, reflection_prompt_4
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIns->execute([
            $title, $gradeLevel, $weekCode, $description, $fileLink,
            $task1, $task2, $task3, $task4,
            $p1, $p2, $p3, $p4
        ]);
        flash('success', 'Modul materi kurikulum baru berhasil ditambahkan.');
    } elseif ($action === 'update') {
        $currId = (int)($_POST['curriculum_id'] ?? 0);
        if ($currId > 0 && !empty($title)) {
            $stmtUp = $pdo->prepare("
                UPDATE mentoring_curriculums SET
                    title = ?, grade_level = ?, week_code = ?, description = ?, file_link = ?,
                    task_1 = ?, task_2 = ?, task_3 = ?, task_4 = ?,
                    reflection_prompt_1 = ?, reflection_prompt_2 = ?, reflection_prompt_3 = ?, reflection_prompt_4 = ?
                WHERE id = ?
            ");
            $stmtUp->execute([
                $title, $gradeLevel, $weekCode, $description, $fileLink,
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
    redirect('curriculum.php');
}

// FILTER
$filterGrade = (int)($_GET['grade'] ?? 0);
$filterWeek = trim($_GET['week'] ?? '');

$where = ["1=1"];
$params = [];

if ($filterGrade > 0) {
    $where[] = "grade_level = ?";
    $params[] = $filterGrade;
}
if ($filterWeek !== '') {
    $where[] = "week_code = ?";
    $params[] = $filterWeek;
}
$whereSql = implode(' AND ', $where);

$stmtCurrs = $pdo->prepare("
    SELECT * FROM mentoring_curriculums 
    WHERE {$whereSql}
    ORDER BY grade_level ASC, week_code ASC, id ASC
");
$stmtCurrs->execute($params);
$curriculums = $stmtCurrs->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../../includes/header.php';
?>

<div class="content">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-book-open-reader" style="color: #0284c7;"></i> Master Kurikulum Adab Berjenjang
            </h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">
                Silabus amalan checklist harian & pertanyaan refleksi per jenjang kelas (7, 8, 9)
            </p>
        </div>

        <button type="button" class="btn btn-primary" onclick="openAddModal()" style="padding: 9px 16px; font-size: 13px; font-weight: 700; border-radius: 8px;">
            <i class="fa-solid fa-plus"></i> Tambah Modul Materi
        </button>
    </div>

    <!-- Filter Card -->
    <div class="card" style="padding: 16px 20px; border-radius: 14px; margin-bottom: 20px;">
        <form method="GET" action="curriculum.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <div>
                <select name="grade" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                    <option value="0">-- Semua Tingkat Kelas --</option>
                    <option value="7" <?= $filterGrade === 7 ? 'selected' : '' ?>>Kelas 7</option>
                    <option value="8" <?= $filterGrade === 8 ? 'selected' : '' ?>>Kelas 8</option>
                    <option value="9" <?= $filterGrade === 9 ? 'selected' : '' ?>>Kelas 9</option>
                </select>
            </div>

            <div>
                <select name="week" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                    <option value="">-- Semua Pekan --</option>
                    <option value="Pekan 1" <?= $filterWeek === 'Pekan 1' ? 'selected' : '' ?>>Pekan 1</option>
                    <option value="Pekan 2" <?= $filterWeek === 'Pekan 2' ? 'selected' : '' ?>>Pekan 2</option>
                    <option value="Pekan 3" <?= $filterWeek === 'Pekan 3' ? 'selected' : '' ?>>Pekan 3</option>
                    <option value="Pekan 4" <?= $filterWeek === 'Pekan 4' ? 'selected' : '' ?>>Pekan 4</option>
                </select>
            </div>

            <?php if ($filterGrade > 0 || !empty($filterWeek)): ?>
                <a href="curriculum.php" class="btn btn-secondary" style="padding: 8px 12px; font-size: 13px;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabel Daftar Kurikulum -->
    <div class="card" style="padding: 20px; border-radius: 16px;">
        <div class="table-responsive">
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 12px;">Tingkat & Pekan</th>
                        <th style="padding: 12px;">Judul Modul Materi</th>
                        <th style="padding: 12px;">Butir Amalan Checklist</th>
                        <th style="padding: 12px; text-align: center;">Status</th>
                        <th style="padding: 12px; text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($curriculums)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 30px; color: #94a3b8;">
                                Belum ada kurikulum yang sesuai kriteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($curriculums as $c): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                                <td style="padding: 12px;">
                                    <span style="padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: 800; background: #e0f2fe; color: #0369a1;">
                                        Kelas <?= $c['grade_level'] ?>
                                    </span>
                                    <div style="font-weight: 700; color: #0f172a; margin-top: 4px;"><?= e($c['week_code']) ?></div>
                                </td>
                                <td style="padding: 12px; max-width: 280px;">
                                    <strong><?= e($c['title']) ?></strong>
                                    <?php if (!empty($c['description'])): ?>
                                        <p style="margin: 4px 0 0; font-size: 11.5px; color: #64748b; line-height: 1.4;">
                                            <?= e(mb_strimwidth($c['description'], 0, 90, '...')) ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; max-width: 320px; font-size: 12px; color: #475569;">
                                    <ul style="margin: 0; padding-left: 18px; line-height: 1.5;">
                                        <?php if (!empty($c['task_1'])): ?><li><?= e($c['task_1']) ?></li><?php endif; ?>
                                        <?php if (!empty($c['task_2'])): ?><li><?= e($c['task_2']) ?></li><?php endif; ?>
                                        <?php if (!empty($c['task_3'])): ?><li><?= e($c['task_3']) ?></li><?php endif; ?>
                                        <?php if (!empty($c['task_4'])): ?><li><?= e($c['task_4']) ?></li><?php endif; ?>
                                    </ul>
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <span style="padding: 3px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 700; background: <?= $c['is_active'] ? '#ecfdf5' : '#f1f5f9' ?>; color: <?= $c['is_active'] ? '#065f46' : '#94a3b8' ?>;">
                                        <?= $c['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; text-align: right; white-space: nowrap;">
                                    <button type="button" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;" onclick="openEditModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="fa-solid fa-pen"></i> Edit
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
    <div style="background: white; border-radius: 16px; width: 100%; max-width: 650px; max-height: 90vh; overflow-y: auto; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;" id="modalCurrTitle">
                <i class="fa-solid fa-book-open" style="color: #0284c7;"></i> Kelola Modul Materi
            </h3>
            <button type="button" onclick="closeCurrModal()" style="border: none; background: none; font-size: 20px; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="curriculum.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="modalCurrAction" value="create">
            <input type="hidden" name="curriculum_id" id="modalCurrId" value="">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Tingkat Kelas</label>
                    <select name="grade_level" id="inputGradeLevel" class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" required>
                        <option value="7">Kelas 7</option>
                        <option value="8">Kelas 8</option>
                        <option value="9">Kelas 9</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Pekan</label>
                    <select name="week_code" id="inputWeekCode" class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" required>
                        <option value="Pekan 1">Pekan 1</option>
                        <option value="Pekan 2">Pekan 2</option>
                        <option value="Pekan 3">Pekan 3</option>
                        <option value="Pekan 4">Pekan 4</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Judul Modul Adab</label>
                <input type="text" name="title" id="inputTitle" placeholder="Contoh: Adab Kepada Orang Tua & Guru" class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" required>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Deskripsi / Dalil Pengantar</label>
                <textarea name="description" id="inputDescription" rows="3" class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px; resize: vertical;"></textarea>
            </div>

            <!-- 4 Butir Amalan Checklist -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 16px;">
                <label style="display: block; font-size: 12.5px; font-weight: 800; color: #0284c7; text-transform: uppercase; margin-bottom: 8px;">
                    4 Butir Checklist Amalan Santri
                </label>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <input type="text" name="task_1" id="inputTask1" placeholder="Butir 1..." class="filter-input" style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                    <input type="text" name="task_2" id="inputTask2" placeholder="Butir 2..." class="filter-input" style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                    <input type="text" name="task_3" id="inputTask3" placeholder="Butir 3..." class="filter-input" style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                    <input type="text" name="task_4" id="inputTask4" placeholder="Butir 4..." class="filter-input" style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeCurrModal()" class="btn btn-secondary" style="padding: 9px 18px; font-size: 13px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding: 9px 24px; font-size: 13px; font-weight: 700;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Modul
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalCurrTitle').innerHTML = '<i class="fa-solid fa-plus" style="color:#0284c7;"></i> Tambah Modul Kurikulum Baru';
    document.getElementById('modalCurrAction').value = 'create';
    document.getElementById('modalCurrId').value = '';
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
    document.getElementById('modalCurrTitle').innerHTML = '<i class="fa-solid fa-pen" style="color:#0284c7;"></i> Edit Modul Kurikulum';
    document.getElementById('modalCurrAction').value = 'update';
    document.getElementById('modalCurrId').value = c.id;
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
