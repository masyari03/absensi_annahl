<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Hanya Super Admin & Kepala Sekolah yang boleh mengelola pembagian mentor
if (!in_array(currentRole(), ['super_admin', 'kepala_sekolah'])) {
    flash('danger', 'Anda tidak memiliki hak akses untuk mengelola pembagian kelompok mentoring.');
    redirect('index.php');
}

$pageTitle = 'Pengaturan Kelompok Mentee & Mentor';

// ==========================================
// 1. DATA MASTER PENDUKUNG FORM & FILTER
// ==========================================
$academicYears = $pdo->query("SELECT id, name, status FROM academic_years ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Tentukan Tahun Pelajaran Terpilih (Default ke status active)
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
$filterSemester = in_array($_GET['semester'] ?? '', ['ganjil', 'genap']) ? $_GET['semester'] : 'ganjil';
$filterClass    = trim($_GET['class'] ?? '');
$filterMentor   = (int)($_GET['mentor_id'] ?? 0);
$filterSearch   = trim($_GET['search'] ?? '');
$filterStatus   = trim($_GET['status'] ?? '');

// DAFTAR GURU MENTOR
$mentors = $pdo->query("
    SELECT id, name, username 
    FROM users 
    WHERE role IN ('staff', 'admin', 'kepala_sekolah')
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// DAFTAR TINGKAT KELAS BESERTA SUB KELAS (ROMBEL)
$grades = $pdo->query("SELECT id, grade FROM grades ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$classGroups = $pdo->query("SELECT id, grade_id, name FROM class_groups ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// DAFTAR SELURUH SANTRI AKTIF UNTUK PILIHAN FORM (LENGKAP DENGAN KELAS & SUB-KELAS)
$stmtAllStudents = $pdo->prepare("
    SELECT s.id as student_id, s.name as student_name, s.nis, 
           cg.id as class_group_id, cg.name as class_group_name,
           g.id as grade_id, g.grade as grade_name,
           ma.mentor_user_id, ma.group_name as current_group_name, u.name as current_mentor_name
    FROM students s
    JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    JOIN class_groups cg ON cg.id = se.class_group_id
    JOIN grades g ON g.id = cg.grade_id
    LEFT JOIN mentoring_assignments ma ON ma.student_id = s.id 
         AND ma.academic_year_id = ? 
         AND ma.semester = ? 
         AND ma.is_active = 1
    LEFT JOIN users u ON u.id = ma.mentor_user_id
    WHERE s.deleted_at IS NULL
    ORDER BY g.sort_order ASC, cg.name ASC, s.name ASC
");
$stmtAllStudents->execute([$filterAyId, $filterSemester]);
$allStudents = $stmtAllStudents->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// 2. PROSES POST (SIMPAN KELOMPOK BARU / EDIT / HAPUS)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Sesi telah kadaluarsa. Silakan ulangi kembali.');
        redirect("assignments.php?academic_year_id={$filterAyId}&semester={$filterSemester}");
    }

    $action       = $_POST['action'];
    $postAyId     = (int)($_POST['academic_year_id'] ?? $filterAyId);
    $postSemester = in_array($_POST['semester'] ?? '', ['ganjil', 'genap']) ? $_POST['semester'] : 'ganjil';

    // A. PEMBUATAN / PENUGASAN KELOMPOK MENTORING (FORM UTAMA)
    if ($action === 'save_group') {
        $groupName    = trim($_POST['group_name'] ?? '');
        $mentorUserId = (int)($_POST['mentor_user_id'] ?? 0);
        $studentIds   = $_POST['student_ids'] ?? [];

        if (empty($groupName)) {
            flash('danger', 'Nama kelompok mentoring wajib diisi!');
            redirect("assignments.php?academic_year_id={$postAyId}&semester={$postSemester}");
        }
        if ($mentorUserId <= 0) {
            flash('danger', 'Harap pilih guru mentor penanggung jawab!');
            redirect("assignments.php?academic_year_id={$postAyId}&semester={$postSemester}");
        }
        if (empty($studentIds) || !is_array($studentIds)) {
            flash('danger', 'Harap pilih minimal satu santri binaan untuk kelompok ini!');
            redirect("assignments.php?academic_year_id={$postAyId}&semester={$postSemester}");
        }

        $assignedCount = 0;
        foreach ($studentIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0) continue;

            $stmtCheck = $pdo->prepare("
                SELECT id FROM mentoring_assignments 
                WHERE student_id = ? AND academic_year_id = ? AND semester = ? 
                LIMIT 1
            ");
            $stmtCheck->execute([$sid, $postAyId, $postSemester]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUp = $pdo->prepare("
                    UPDATE mentoring_assignments 
                    SET mentor_user_id = ?, group_name = ?, is_active = 1 
                    WHERE id = ?
                ");
                $stmtUp->execute([$mentorUserId, $groupName, $existingId]);
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO mentoring_assignments (student_id, mentor_user_id, group_name, academic_year_id, semester, is_active) 
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmtIns->execute([$sid, $mentorUserId, $groupName, $postAyId, $postSemester]);
            }
            $assignedCount++;
        }

        flash('success', "Alhamdulillah! Kelompok '{$groupName}' berhasil disimpan dengan {$assignedCount} santri binaan.");
        redirect("assignments.php?academic_year_id={$postAyId}&semester={$postSemester}");
    }

    // B. AKSI MASSAL DARI TABEL (BULK REASSIGN)
    elseif ($action === 'bulk_assign') {
        $studentIds   = $_POST['student_ids'] ?? [];
        $mentorUserId = (int)($_POST['bulk_mentor_user_id'] ?? 0);
        $groupName    = trim($_POST['bulk_group_name'] ?? '');

        if (!empty($studentIds) && $mentorUserId > 0) {
            $count = 0;
            foreach ($studentIds as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) continue;

                $stmtCheck = $pdo->prepare("SELECT id FROM mentoring_assignments WHERE student_id = ? AND academic_year_id = ? AND semester = ? LIMIT 1");
                $stmtCheck->execute([$sid, $postAyId, $postSemester]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    $stmtUp = $pdo->prepare("UPDATE mentoring_assignments SET mentor_user_id = ?, group_name = IF(? != '', ?, group_name), is_active = 1 WHERE id = ?");
                    $stmtUp->execute([$mentorUserId, $groupName, $groupName, $existingId]);
                } else {
                    $stmtIns = $pdo->prepare("INSERT INTO mentoring_assignments (student_id, mentor_user_id, group_name, academic_year_id, semester, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                    $stmtIns->execute([$sid, $mentorUserId, $groupName, $postAyId, $postSemester]);
                }
                $count++;
            }
            flash('success', "Berhasil memindahkan {$count} santri ke mentor tujuan.");
        } else {
            flash('danger', 'Harap pilih minimal satu santri dan tentukan guru mentor tujuan.');
        }
        redirect("assignments.php?academic_year_id={$postAyId}&semester={$postSemester}");
    }

    // C. UPDATE NAMA KELOMPOK HALAQAH PER MENTOR
    elseif ($action === 'update_group_name') {
        $targetMentorId = (int)($_POST['target_mentor_id'] ?? 0);
        $newGroupName   = trim($_POST['new_group_name'] ?? '');

        if ($targetMentorId > 0 && !empty($newGroupName)) {
            $stmtUpGroup = $pdo->prepare("UPDATE mentoring_assignments SET group_name = ? WHERE mentor_user_id = ? AND academic_year_id = ? AND semester = ?");
            $stmtUpGroup->execute([$newGroupName, $targetMentorId, $postAyId, $postSemester]);
            flash('success', "Nama kelompok halaqah berhasil diperbarui.");
        }
        redirect("assignments.php?academic_year_id={$postAyId}&semester={$postSemester}");
    }
}

// ==========================================
// 3. QUERY TABEL SANTRI SESUAI FILTER
// ==========================================
$where = ["s.deleted_at IS NULL"];
$params = [$filterAyId, $filterSemester];

if ($filterClass !== '') {
    $where[] = "cg.name = ?";
    $params[] = $filterClass;
}
if ($filterMentor > 0) {
    $where[] = "ma.mentor_user_id = ?";
    $params[] = $filterMentor;
}
if ($filterSearch !== '') {
    $where[] = "(s.name LIKE ? OR s.nis LIKE ? OR ma.group_name LIKE ?)";
    $params[] = "%{$filterSearch}%";
    $params[] = "%{$filterSearch}%";
    $params[] = "%{$filterSearch}%";
}
if ($filterStatus === 'assigned') {
    $where[] = "ma.mentor_user_id IS NOT NULL";
} elseif ($filterStatus === 'unassigned') {
    $where[] = "ma.mentor_user_id IS NULL";
}

$whereSql = implode(' AND ', $where);

// STATISTIK KELOMPOK
$stmtStats = $pdo->prepare("
    SELECT u.id as mentor_id, u.name as mentor_name, 
           COUNT(ma.student_id) as total_mentee,
           MAX(ma.group_name) as group_name
    FROM users u
    JOIN mentoring_assignments ma ON ma.mentor_user_id = u.id AND ma.academic_year_id = ? AND ma.semester = ? AND ma.is_active = 1
    JOIN students s ON s.id = ma.student_id AND s.deleted_at IS NULL
    GROUP BY u.id, u.name
    ORDER BY total_mentee DESC, u.name ASC
");
$stmtStats->execute([$filterAyId, $filterSemester]);
$mentorStats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

$totalAssignedStudents = array_sum(array_column($mentorStats, 'total_mentee'));
$totalActiveStudents   = count($allStudents);

// TARIK LIST TABEL
$stmtStudents = $pdo->prepare("
    SELECT s.id as student_id, s.name as student_name, s.nis, cg.name as class_name,
           ma.id as assignment_id, ma.mentor_user_id, ma.group_name, u.name as mentor_name
    FROM students s
    JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN mentoring_assignments ma ON ma.student_id = s.id AND ma.academic_year_id = ? AND ma.semester = ? AND ma.is_active = 1
    LEFT JOIN users u ON u.id = ma.mentor_user_id
    WHERE {$whereSql}
    ORDER BY cg.name ASC, s.name ASC
");
$stmtStudents->execute($params);
$studentsList = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../../includes/header.php';
?>

<div class="content">
    <!-- Header Title & Action -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-users-rectangle" style="color: #0284c7;"></i> Pengaturan Kelompok Mentoring (Mentor / Mentee)
            </h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">
                Pembuatan kelompok halaqah baru, pemilihan mentor, dan alokasi santri terintegrasi filter kelas & sub-kelas
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <button type="button" class="btn btn-primary" onclick="toggleFormKelompok()" style="padding: 9px 18px; font-size: 13px; font-weight: 800; border-radius: 8px; background: #0284c7; border-color: #0284c7; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(2,132,199,0.25);">
                <i class="fa-solid fa-plus-circle"></i> Buat Kelompok Mentoring Baru
            </button>
            <a href="curriculum.php?academic_year_id=<?= $filterAyId ?>" class="btn" style="padding: 9px 16px; font-size: 13px; font-weight: 700; background: #059669; color: white; border-radius: 8px; text-decoration: none;">
                <i class="fa-solid fa-book-quran"></i> Kurikulum Adab
            </a>
            <a href="index.php" class="btn btn-secondary" style="padding: 9px 16px; font-size: 13px;">
                <i class="fa-solid fa-arrow-left"></i> Dashboard Mentoring
            </a>
        </div>
    </div>

    <!-- ========================================================
         FORM PEMBUATAN / ALOKASI KELOMPOK MENTORING BARU
         Alur: Tahun Pelajaran -> Semester -> Nama Kelompok -> Pilih Mentor -> Filter Kelas/Sub-Kelas -> Pilih Siswa -> Simpan
         ======================================================== -->
    <div id="cardFormKelompok" class="card" style="padding: 24px; border-radius: 16px; margin-bottom: 24px; border: 2px solid #0284c7; background: #ffffff; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 14px; margin-bottom: 20px;">
            <div>
                <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-user-plus" style="color: #0284c7;"></i> Form Pembuatan & Pembagian Kelompok Mentoring
                </h3>
                <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">
                    Lengkapi parameter kelompok lalu pilih santri binaan di bawah
                </p>
            </div>
            <button type="button" onclick="toggleFormKelompok()" style="background: none; border: none; font-size: 22px; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="assignments.php" id="mainGroupForm" onsubmit="return validateGroupForm()">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_group">

            <!-- 1. BARIS ATRIBUT KELOMPOK: TAHUN PELAJARAN, SEMESTER, NAMA KELOMPOK, PILIH MENTOR -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #1e293b; margin-bottom: 6px;">
                        1. Tahun Pelajaran <span style="color: #ef4444;">*</span>
                    </label>
                    <select name="academic_year_id" style="width: 100%; padding: 9px 12px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 13px; font-weight: 700; color: #0284c7; background: #f0f9ff;" required>
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $filterAyId == $ay['id'] ? 'selected' : '' ?>>
                                TP <?= e($ay['name']) ?> <?= $ay['status'] === 'active' ? '(Aktif)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #1e293b; margin-bottom: 6px;">
                        2. Semester <span style="color: #ef4444;">*</span>
                    </label>
                    <select name="semester" style="width: 100%; padding: 9px 12px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 13px; font-weight: 700; color: #334155;" required>
                        <option value="ganjil" <?= $filterSemester === 'ganjil' ? 'selected' : '' ?>>Semester Ganjil</option>
                        <option value="genap" <?= $filterSemester === 'genap' ? 'selected' : '' ?>>Semester Genap</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #1e293b; margin-bottom: 6px;">
                        3. Nama Kelompok / Halaqah <span style="color: #ef4444;">*</span>
                    </label>
                    <input type="text" name="group_name" id="formGroupName" placeholder="Contoh: Halaqah Abu Bakar" required style="width: 100%; padding: 9px 12px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 13px; font-weight: 600;">
                </div>

                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #1e293b; margin-bottom: 6px;">
                        4. Pilih Guru Mentor <span style="color: #ef4444;">*</span>
                    </label>
                    <select name="mentor_user_id" id="formMentorUserId" style="width: 100%; padding: 9px 12px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 13px; font-weight: 600;" required>
                        <option value="">-- Pilih Guru Mentor --</option>
                        <?php foreach ($mentors as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= e($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- 2. AREA PILIH SISWA LENGKAP DENGAN FILTER KELAS & SUB-KELAS -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 14px;">
                    <div>
                        <label style="font-size: 13.5px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-user-check" style="color: #10b981;"></i> 5. Pilih Santri Binaan Kelompok Ini
                        </label>
                        <span style="font-size: 12px; color: #64748b;">
                            Gunakan filter kelas & sub-kelas di samping untuk mempermudah pencarian
                        </span>
                    </div>

                    <!-- Filter Kelas & Sub-Kelas Dinamis di Dalam Form -->
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <!-- Filter Tingkat Kelas -->
                        <select id="formFilterGrade" onchange="filterStudentsForm()" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px; font-weight: 600; background: #ffffff;">
                            <option value="">Semua Tingkat (7, 8, 9)</option>
                            <?php foreach ($grades as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= e($g['grade']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Filter Sub-Kelas (Rombel) -->
                        <select id="formFilterSubClass" onchange="filterStudentsForm()" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px; font-weight: 600; background: #ffffff;">
                            <option value="">Semua Sub-Kelas (7A, 7B, dst)</option>
                            <?php foreach ($classGroups as $cg): ?>
                                <option value="<?= $cg['id'] ?>" data-grade-id="<?= $cg['grade_id'] ?>">Kelas <?= e($cg['name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Cari Cepat Nama Santri -->
                        <input type="text" id="formFilterSearchText" onkeyup="filterStudentsForm()" placeholder="Cari santri/NIS..." style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px; width: 140px; background: #ffffff;">

                        <!-- Tombol Cepat Pilih Semua yang Tampil -->
                        <button type="button" class="btn btn-secondary" onclick="checkAllVisible(true)" style="padding: 6px 10px; font-size: 11.5px; font-weight: 700;">
                            Pilih Semua
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="checkAllVisible(false)" style="padding: 6px 10px; font-size: 11.5px;">
                            Batal Pilih
                        </button>
                    </div>
                </div>

                <!-- Indikator Jumlah Terpilih -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 12px; color: #475569;">
                    <span>Total santri terpilih untuk kelompok ini: <strong id="formSelectedCount" style="color: #0284c7; font-size: 14px;">0</strong> santri</span>
                    <span id="formVisibleCount" style="color: #64748b;">Menampilkan <?= count($allStudents) ?> santri</span>
                </div>

                <!-- Box Daftar Santri Berbasis Grid Checklist -->
                <div id="studentsChecklistBox" style="max-height: 320px; overflow-y: auto; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 12px; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px;">
                    <?php foreach ($allStudents as $st): ?>
                        <div class="student-select-item" 
                             data-grade-id="<?= $st['grade_id'] ?>" 
                             data-subclass-id="<?= $st['class_group_id'] ?>" 
                             data-search="<?= strtolower(e($st['student_name'] . ' ' . $st['nis'] . ' ' . $st['class_group_name'])) ?>"
                             style="display: flex; flex-direction: row; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; cursor: pointer; transition: all 0.15s; background: <?= !empty($st['current_mentor_name']) ? '#f8fafc' : '#ffffff' ?>;"
                             onclick="toggleStudentItemCheckbox(this, event)">
                            <div style="padding-top: 2px;">
                                <input type="checkbox" name="student_ids[]" value="<?= $st['student_id'] ?>" class="form-student-checkbox" onchange="updateFormSelectedCount()" style="width: 18px !important; height: 18px !important; min-width: 18px !important; min-height: 18px !important; margin: 0; padding: 0; cursor: pointer; display: inline-block !important;">
                            </div>
                            <div style="overflow: hidden; flex: 1; min-width: 0;">
                                <div style="font-weight: 800; color: #0f172a; font-size: 13px; line-height: 1.35; word-break: break-word;">
                                    <?= e($st['student_name']) ?>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: #64748b; margin-top: 4px; flex-wrap: wrap;">
                                    <span style="font-weight: 800; color: #0284c7; background: #e0f2fe; padding: 2px 6px; border-radius: 4px;">
                                        Kelas <?= e($st['class_group_name']) ?>
                                    </span>
                                    <span style="font-weight: 600;">NIS: <?= e($st['nis']) ?></span>
                                    <?php if (!empty($st['current_mentor_name'])): ?>
                                        <span style="color: #059669; font-weight: 700; background: #ecfdf5; padding: 1px 6px; border-radius: 4px;" title="Mentor Saat Ini: <?= e($st['current_mentor_name']) ?> (<?= e($st['current_group_name']) ?>)">
                                            <i class="fa-solid fa-check"></i> <?= e(mb_strimwidth($st['current_mentor_name'], 0, 14, '..')) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Tombol Aksi Simpan -->
            <div style="display: flex; justify-content: flex-end; gap: 10px; align-items: center;">
                <button type="button" onclick="toggleFormKelompok()" class="btn btn-secondary" style="padding: 10px 20px; font-size: 13px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 28px; font-size: 13px; font-weight: 800; background: #0284c7; border-color: #0284c7; box-shadow: 0 4px 6px -1px rgba(2,132,199,0.3);">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Kelompok Mentoring
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Metrics Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 22px;">
        <div class="card" style="padding: 16px 20px; border-radius: 14px; border-left: 5px solid #0284c7; background: #ffffff;">
            <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Tahun & Semester</div>
            <div style="font-size: 18px; font-weight: 800; color: #0f172a; margin-top: 4px; display: flex; align-items: center; justify-content: space-between;">
                <?php 
                    $currAyName = 'Tahun Ajaran';
                    foreach ($academicYears as $ay) {
                        if ($ay['id'] == $filterAyId) { $currAyName = $ay['name']; break; }
                    }
                    echo e($currAyName) . ' (' . ucfirst($filterSemester) . ')';
                ?>
                <i class="fa-solid fa-calendar-check" style="color: #0284c7; opacity: 0.8;"></i>
            </div>
            <div style="font-size: 12px; color: #0284c7; margin-top: 4px; font-weight: 600;">Terikat database bimbingan</div>
        </div>

        <div class="card" style="padding: 16px 20px; border-radius: 14px; border-left: 5px solid #10b981; background: #ffffff;">
            <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Santri Telah Terbagi</div>
            <div style="font-size: 22px; font-weight: 800; color: #059669; margin-top: 4px; display: flex; align-items: center; justify-content: space-between;">
                <?= number_format($totalAssignedStudents) ?> / <?= number_format($totalActiveStudents) ?>
                <i class="fa-solid fa-user-check" style="color: #10b981; opacity: 0.8;"></i>
            </div>
            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                Persentase: <?= $totalActiveStudents > 0 ? round(($totalAssignedStudents / $totalActiveStudents) * 100, 1) : 0 ?>% santri binaan
            </div>
        </div>

        <div class="card" style="padding: 16px 20px; border-radius: 14px; border-left: 5px solid #8b5cf6; background: #ffffff;">
            <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Guru Mentor Aktif</div>
            <div style="font-size: 22px; font-weight: 800; color: #7c3aed; margin-top: 4px; display: flex; align-items: center; justify-content: space-between;">
                <?= count($mentorStats) ?> Ustadz / Guru
                <i class="fa-solid fa-chalkboard-user" style="color: #8b5cf6; opacity: 0.8;"></i>
            </div>
            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Rasio rata-rata ~<?= count($mentorStats) > 0 ? round($totalAssignedStudents / count($mentorStats), 1) : 0 ?> santri/mentor</div>
        </div>

        <div class="card" style="padding: 16px 20px; border-radius: 14px; border-left: 5px solid #f59e0b; background: #ffffff;">
            <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Belum Terbagi Kelompok</div>
            <div style="font-size: 22px; font-weight: 800; color: #d97706; margin-top: 4px; display: flex; align-items: center; justify-content: space-between;">
                <?= max(0, $totalActiveStudents - $totalAssignedStudents) ?> Santri
                <i class="fa-solid fa-user-clock" style="color: #f59e0b; opacity: 0.8;"></i>
            </div>
            <div style="font-size: 12px; color: #d97706; margin-top: 4px; font-weight: 600;">Klik "Buat Kelompok Baru" di atas</div>
        </div>
    </div>

    <!-- Panel Kuota & Nama Kelompok per Guru Mentor -->
    <div class="card" style="padding: 20px; border-radius: 16px; margin-bottom: 22px; background: #ffffff;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-layer-group" style="color: #7c3aed;"></i> Kuota & Nama Kelompok Halaqah Mentor
            </h3>
            <span style="font-size: 12px; color: #64748b;">Klik tombol pensil untuk mengubah nama kelompok halaqah</span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
            <?php if (empty($mentorStats)): ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #94a3b8; font-size: 13px;">
                    Belum ada kelompok mentoring yang dibuat pada tahun pelajaran dan semester ini.
                </div>
            <?php else: ?>
                <?php foreach ($mentorStats as $ms): ?>
                    <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; background: #f8fafc; display: flex; justify-content: space-between; align-items: center;">
                        <div style="overflow: hidden; padding-right: 8px;">
                            <div style="font-weight: 700; color: #0f172a; font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= e($ms['mentor_name']) ?>
                            </div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                                <span style="background: #e0f2fe; color: #0284c7; padding: 2px 7px; border-radius: 4px; font-weight: 700; font-size: 11px;">
                                    <?= e($ms['group_name'] ?: 'Halaqah Mandiri') ?>
                                </span>
                                <span>• <strong><?= $ms['total_mentee'] ?></strong> santri</span>
                            </div>
                        </div>

                        <button type="button" class="btn btn-secondary" 
                                style="padding: 6px 10px; font-size: 12px; border-radius: 8px; flex-shrink: 0;"
                                onclick="openEditGroupModal(<?= $ms['mentor_id'] ?>, '<?= e(addslashes($ms['mentor_name'])) ?>', '<?= e(addslashes($ms['group_name'] ?? '')) ?>')">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Bar Interaktif -->
    <div class="card" style="padding: 16px 20px; border-radius: 14px; margin-bottom: 20px; background: #ffffff;">
        <form method="GET" action="assignments.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <!-- Pilihan Tahun Pelajaran -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Tahun Pelajaran</label>
                <select name="academic_year_id" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1.5px solid #0284c7; font-size: 13px; font-weight: 700; color: #0369a1; background: #f0f9ff;" onchange="this.form.submit()">
                    <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $filterAyId == $ay['id'] ? 'selected' : '' ?>>
                            TP <?= e($ay['name']) ?> <?= $ay['status'] === 'active' ? '(Aktif)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Pilihan Semester -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Semester</label>
                <select name="semester" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;" onchange="this.form.submit()">
                    <option value="ganjil" <?= $filterSemester === 'ganjil' ? 'selected' : '' ?>>Semester Ganjil</option>
                    <option value="genap" <?= $filterSemester === 'genap' ? 'selected' : '' ?>>Semester Genap</option>
                </select>
            </div>

            <!-- Pencarian Santri -->
            <div style="flex: 1; min-width: 170px;">
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Cari Santri / Kelompok</label>
                <input type="text" name="search" value="<?= e($filterSearch) ?>" placeholder="Nama santri, NIS, halaqah..." class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
            </div>

            <!-- Filter Kelas -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Sub-Kelas</label>
                <select name="class" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Semua Sub-Kelas --</option>
                    <?php foreach ($classGroups as $cg): ?>
                        <option value="<?= e($cg['name']) ?>" <?= $filterClass === $cg['name'] ? 'selected' : '' ?>>Kelas <?= e($cg['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Guru Mentor -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Guru Mentor</label>
                <select name="mentor_id" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="0">-- Semua Guru Mentor --</option>
                    <?php foreach ($mentors as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $filterMentor == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Status Penugasan -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Status</label>
                <select name="status" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Semua --</option>
                    <option value="assigned" <?= $filterStatus === 'assigned' ? 'selected' : '' ?>>Sudah Terbagi</option>
                    <option value="unassigned" <?= $filterStatus === 'unassigned' ? 'selected' : '' ?>>Belum Ada Mentor</option>
                </select>
            </div>

            <div style="align-self: flex-end;">
                <button type="submit" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px; font-weight: 700;">
                    <i class="fa-solid fa-filter"></i> Terapkan
                </button>
                <?php if (!empty($filterClass) || $filterMentor > 0 || !empty($filterSearch) || !empty($filterStatus)): ?>
                    <a href="assignments.php?academic_year_id=<?= $filterAyId ?>&semester=<?= $filterSemester ?>" class="btn btn-secondary" style="padding: 8px 14px; font-size: 13px;">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Tabel Daftar Penugasan Santri & Fitur Bulk Assign -->
    <div class="card" style="padding: 20px; border-radius: 16px; background: #ffffff;">
        <form method="POST" action="assignments.php" id="bulkForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_assign">
            <input type="hidden" name="academic_year_id" value="<?= $filterAyId ?>">
            <input type="hidden" name="semester" value="<?= $filterSemester ?>">

            <!-- Toolbar Aksi Massal (Bulk Bar) -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; padding: 12px 16px; background: #f1f5f9; border-radius: 10px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; font-weight: 700; color: #1e293b;">
                        <input type="checkbox" id="selectAllCheckbox" style="transform: scale(1.2); cursor: pointer;" onchange="toggleSelectAll(this)">
                        Pilih Semua Santri (<span id="selectedCount">0</span> terpilih)
                    </label>
                </div>

                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span style="font-size: 13px; font-weight: 700; color: #475569;">Pindah Massal:</span>
                    <input type="text" name="bulk_group_name" placeholder="Nama Kelompok Baru" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px; width: 170px;">
                    <select name="bulk_mentor_user_id" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;" id="bulkMentorSelect">
                        <option value="">-- Tetapkan ke Mentor --</option>
                        <?php foreach ($mentors as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= e($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary" style="padding: 6px 14px; font-size: 12.5px; font-weight: 700; background: #0284c7;" onclick="return confirmBulkAssign()">
                        <i class="fa-solid fa-users-gear"></i> Terapkan
                    </button>
                </div>
            </div>

            <!-- Tabel Data -->
            <div class="table-responsive">
                <table class="table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                            <th style="padding: 12px; width: 40px; text-align: center;">#</th>
                            <th style="padding: 12px;">Santri & NIS</th>
                            <th style="padding: 12px;">Sub-Kelas</th>
                            <th style="padding: 12px;">Kelompok / Halaqah</th>
                            <th style="padding: 12px;">Guru Mentor Terpilih</th>
                            <th style="padding: 12px; text-align: right;">Aksi Satuan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($studentsList)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 35px; color: #94a3b8;">
                                    <i class="fa-solid fa-folder-open" style="font-size: 32px; margin-bottom: 8px; display: block; opacity: 0.5;"></i>
                                    Tidak ada data santri yang sesuai kriteria filter pencarian.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($studentsList as $st): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                                    <td style="padding: 12px; text-align: center;">
                                        <input type="checkbox" name="student_ids[]" value="<?= $st['student_id'] ?>" class="student-checkbox" style="transform: scale(1.15); cursor: pointer;" onchange="updateSelectedCount()">
                                    </td>
                                    <td style="padding: 12px;">
                                        <strong><?= e($st['student_name']) ?></strong>
                                        <div style="font-size: 11.5px; color: #64748b;">NIS: <?= e($st['nis']) ?></div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; background: #e0f2fe; color: #0369a1;">
                                            Kelas <?= e($st['class_name'] ?? 'Santri') ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="padding: 3px 9px; border-radius: 6px; font-size: 12px; font-weight: 600; background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb;">
                                            <i class="fa-solid fa-users-line" style="margin-right: 4px; color: #9ca3af;"></i>
                                            <?= e($st['group_name'] ?: 'Halaqah Mandiri') ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?php if (!empty($st['mentor_name'])): ?>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
                                                <span style="font-weight: 700; color: #0f172a;"><?= e($st['mentor_name']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span style="padding: 3px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 700; background: #fef2f2; color: #991b1b; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> Belum Ada Mentor
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px; text-align: right;">
                                        <button type="button" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;" onclick="openSingleAssignModal(<?= $st['student_id'] ?>, '<?= e(addslashes($st['student_name'])) ?>', <?= (int)$st['mentor_user_id'] ?>, '<?= e(addslashes($st['group_name'] ?? '')) ?>')">
                                            <i class="fa-solid fa-user-pen"></i> Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     MODAL EDIT NAMA KELOMPOK HALAQAH
     ========================================== -->
<div id="modalEditGroup" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #ffffff; width: 100%; max-width: 450px; border-radius: 16px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
        <h3 style="margin: 0 0 8px; font-size: 17px; font-weight: 800; color: #0f172a;">
            <i class="fa-solid fa-pen-to-square" style="color: #7c3aed;"></i> Edit Nama Kelompok Halaqah
        </h3>
        <p style="margin: 0 0 16px; font-size: 13px; color: #64748b;">
            Mengubah nama kelompok untuk seluruh santri binaan mentor: <strong id="modalMentorNameText" style="color: #0f172a;"></strong>
        </p>

        <form method="POST" action="assignments.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_group_name">
            <input type="hidden" name="academic_year_id" value="<?= $filterAyId ?>">
            <input type="hidden" name="semester" value="<?= $filterSemester ?>">
            <input type="hidden" name="target_mentor_id" id="modalTargetMentorId">

            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 6px;">Nama Kelompok / Halaqah</label>
                <input type="text" name="new_group_name" id="modalNewGroupName" placeholder="Contoh: Halaqah Abu Bakar Ash-Shiddiq" required style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditGroupModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="background: #7c3aed; border-color: #7c3aed; font-weight: 700;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     MODAL EDIT SATUAN MENTEE
     ========================================== -->
<div id="modalSingleAssign" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 16px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
        <h3 style="margin: 0 0 6px; font-size: 17px; font-weight: 800; color: #0f172a;">
            <i class="fa-solid fa-user-pen" style="color: #0284c7;"></i> Alokasi Mentor Santri
        </h3>
        <p style="margin: 0 0 16px; font-size: 13px; color: #64748b;">
            Santri: <strong id="modalSingleStudentName" style="color: #0f172a;"></strong>
        </p>

        <form method="POST" action="assignments.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_group">
            <input type="hidden" name="academic_year_id" value="<?= $filterAyId ?>">
            <input type="hidden" name="semester" value="<?= $filterSemester ?>">
            <input type="hidden" name="student_ids[]" id="modalSingleStudentId">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 6px;">Guru Mentor</label>
                <select name="mentor_user_id" id="modalSingleMentorId" required style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Pilih Guru Mentor --</option>
                    <?php foreach ($mentors as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 6px;">Nama Kelompok / Halaqah</label>
                <input type="text" name="group_name" id="modalSingleGroupName" placeholder="Contoh: Halaqah Salman Al-Farisi" required style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeSingleAssignModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Toggle Buka/Tutup Card Form Kelompok
function toggleFormKelompok() {
    const card = document.getElementById('cardFormKelompok');
    if (card.style.display === 'none' || card.style.display === '') {
        card.style.display = 'block';
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        card.style.display = 'none';
    }
}

// Filter Dinamis Daftar Santri di Form Berdasarkan Kelas, Sub-Kelas, dan Pencarian
function filterStudentsForm() {
    const selectedGradeId = document.getElementById('formFilterGrade').value;
    const selectedSubClassId = document.getElementById('formFilterSubClass').value;
    const searchText = document.getElementById('formFilterSearchText').value.toLowerCase().trim();

    // Cascading: jika tingkat kelas dipilih, sesuaikan dropdown sub-kelas
    const subClassOptions = document.querySelectorAll('#formFilterSubClass option');
    subClassOptions.forEach(opt => {
        if (!opt.value) return; // opsi semua
        const optGradeId = opt.getAttribute('data-grade-id');
        if (!selectedGradeId || optGradeId === selectedGradeId) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
            if (selectedSubClassId === opt.value) {
                document.getElementById('formFilterSubClass').value = '';
            }
        }
    });

    const activeSubClassId = document.getElementById('formFilterSubClass').value;
    const items = document.querySelectorAll('.student-select-item');
    let visibleCount = 0;

    items.forEach(item => {
        const itemGradeId = item.getAttribute('data-grade-id');
        const itemSubClassId = item.getAttribute('data-subclass-id');
        const itemSearch = item.getAttribute('data-search');

        let matchesGrade = !selectedGradeId || itemGradeId === selectedGradeId;
        let matchesSubClass = !activeSubClassId || itemSubClassId === activeSubClassId;
        let matchesSearch = !searchText || itemSearch.includes(searchText);

        if (matchesGrade && matchesSubClass && matchesSearch) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });

    document.getElementById('formVisibleCount').innerText = `Menampilkan ${visibleCount} santri`;
}

// Checklist Item Santri via Baris Card
function toggleStudentItemCheckbox(container, event) {
    // Jika yang diklik langsung checkbox-nya, biarkan event native berjalan
    if (event.target.type === 'checkbox') return;
    const cb = container.querySelector('.form-student-checkbox');
    if (cb) {
        cb.checked = !cb.checked;
        updateFormSelectedCount();
    }
}

// Checklist Semua yang Terlihat
function checkAllVisible(check) {
    const items = document.querySelectorAll('.student-select-item');
    items.forEach(item => {
        if (item.style.display !== 'none') {
            const cb = item.querySelector('.form-student-checkbox');
            if (cb) cb.checked = check;
        }
    });
    updateFormSelectedCount();
}

// Update Counter Terpilih di Form
function updateFormSelectedCount() {
    const checked = document.querySelectorAll('.form-student-checkbox:checked').length;
    document.getElementById('formSelectedCount').innerText = checked;
}

// Validasi Sebelum Submit Form Kelompok
function validateGroupForm() {
    const groupName = document.getElementById('formGroupName').value.trim();
    if (!groupName) {
        alert('Silakan ketikkan Nama Kelompok / Halaqah!');
        document.getElementById('formGroupName').focus();
        return false;
    }
    const mentorId = document.getElementById('formMentorUserId').value;
    if (!mentorId) {
        alert('Silakan tentukan Guru Mentor penanggung jawab kelompok!');
        document.getElementById('formMentorUserId').focus();
        return false;
    }
    const checked = document.querySelectorAll('.form-student-checkbox:checked').length;
    if (checked === 0) {
        alert('Silakan pilih minimal 1 santri binaan untuk kelompok ini!');
        return false;
    }
    return confirm(`Simpan kelompok '${groupName}' dengan ${checked} santri binaan?`);
}

// Fitur Toolbar Tabel Massal
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.student-checkbox:checked').length;
    document.getElementById('selectedCount').innerText = checked;
}

function confirmBulkAssign() {
    const checked = document.querySelectorAll('.student-checkbox:checked').length;
    if (checked === 0) {
        alert('Silakan centang minimal satu santri pada tabel terlebih dahulu!');
        return false;
    }
    const mentorSelect = document.getElementById('bulkMentorSelect');
    if (!mentorSelect.value) {
        alert('Silakan pilih guru mentor tujuan untuk penugasan massal!');
        mentorSelect.focus();
        return false;
    }
    return confirm(`Apakah Anda yakin ingin memindahkan ${checked} santri terpilih ke guru mentor ini?`);
}

function openEditGroupModal(mentorId, mentorName, groupName) {
    // 1. Set data ke form utama pembuatan/alokasi kelompok
    document.getElementById('cardFormKelompok').style.display = 'block';
    document.getElementById('formGroupName').value = groupName;
    document.getElementById('formMentorUserId').value = mentorId;

    // 2. Centang otomatis santri yang saat ini dibimbing oleh mentor tersebut
    const items = document.querySelectorAll('.student-select-item');
    items.forEach(item => {
        const titleSpan = item.querySelector('span[title]');
        const cb = item.querySelector('.form-student-checkbox');
        if (titleSpan && titleSpan.getAttribute('title').includes(mentorName)) {
            cb.checked = true;
        } else {
            cb.checked = false;
        }
    });
    updateFormSelectedCount();

    // 3. Scroll halus ke form kelompok
    document.getElementById('cardFormKelompok').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeEditGroupModal() {
    document.getElementById('modalEditGroup').style.display = 'none';
}

function openSingleAssignModal(studentId, studentName, mentorId, groupName) {
    document.getElementById('modalSingleStudentId').value = studentId;
    document.getElementById('modalSingleStudentName').innerText = studentName;
    document.getElementById('modalSingleMentorId').value = mentorId;
    document.getElementById('modalSingleGroupName').value = groupName;
    document.getElementById('modalSingleAssign').style.display = 'flex';
}

function closeSingleAssignModal() {
    document.getElementById('modalSingleAssign').style.display = 'none';
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
