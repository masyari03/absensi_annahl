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

// AMBIL DAFTAR TAHUN PELAJARAN
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

$filterAyId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : $activeAyId;

// ==========================================
// 1. PROSES POST AKSI (ASSIGN / BULK / UPDATE GROUP)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Sesi telah kadaluarsa. Silakan ulangi kembali.');
        redirect('assignments.php?academic_year_id=' . $filterAyId);
    }

    $action = $_POST['action'];
    $postAyId = (int)($_POST['academic_year_id'] ?? $filterAyId);

    // Aksi 1: Assign Satuan
    if ($action === 'assign_single') {
        $studentId    = (int)($_POST['student_id'] ?? 0);
        $mentorUserId = (int)($_POST['mentor_user_id'] ?? 0);
        $groupName    = trim($_POST['group_name'] ?? '');

        if ($studentId > 0 && $mentorUserId > 0) {
            $stmtCheck = $pdo->prepare("SELECT id FROM mentoring_assignments WHERE student_id = ? AND academic_year_id = ? LIMIT 1");
            $stmtCheck->execute([$studentId, $postAyId]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUp = $pdo->prepare("UPDATE mentoring_assignments SET mentor_user_id = ?, group_name = ?, is_active = 1 WHERE id = ?");
                $stmtUp->execute([$mentorUserId, $groupName, $existingId]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO mentoring_assignments (student_id, mentor_user_id, group_name, academic_year_id, is_active) VALUES (?, ?, ?, ?, 1)");
                $stmtIns->execute([$studentId, $mentorUserId, $groupName, $postAyId]);
            }
            flash('success', 'Penugasan guru mentor berhasil disimpan.');
        }
    }

    // Aksi 2: Bulk Assign / Pindah Massal
    elseif ($action === 'bulk_assign') {
        $studentIds   = $_POST['student_ids'] ?? [];
        $mentorUserId = (int)($_POST['bulk_mentor_user_id'] ?? 0);
        $groupName    = trim($_POST['bulk_group_name'] ?? '');

        if (!empty($studentIds) && $mentorUserId > 0) {
            $count = 0;
            foreach ($studentIds as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) continue;

                $stmtCheck = $pdo->prepare("SELECT id FROM mentoring_assignments WHERE student_id = ? AND academic_year_id = ? LIMIT 1");
                $stmtCheck->execute([$sid, $postAyId]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    $stmtUp = $pdo->prepare("UPDATE mentoring_assignments SET mentor_user_id = ?, group_name = IF(? != '', ?, group_name), is_active = 1 WHERE id = ?");
                    $stmtUp->execute([$mentorUserId, $groupName, $groupName, $existingId]);
                } else {
                    $stmtIns = $pdo->prepare("INSERT INTO mentoring_assignments (student_id, mentor_user_id, group_name, academic_year_id, is_active) VALUES (?, ?, ?, ?, 1)");
                    $stmtIns->execute([$sid, $mentorUserId, $groupName, $postAyId]);
                }
                $count++;
            }
            flash('success', "Berhasil menugaskan {$count} santri ke guru mentor terpilih.");
        } else {
            flash('danger', 'Harap pilih minimal satu santri dan tentukan guru mentor tujuan.');
        }
    }

    // Aksi 3: Update Nama Kelompok Halaqah Per Mentor
    elseif ($action === 'update_group_name') {
        $targetMentorId = (int)($_POST['target_mentor_id'] ?? 0);
        $newGroupName   = trim($_POST['new_group_name'] ?? '');

        if ($targetMentorId > 0 && !empty($newGroupName)) {
            $stmtUpGroup = $pdo->prepare("UPDATE mentoring_assignments SET group_name = ? WHERE mentor_user_id = ? AND academic_year_id = ?");
            $stmtUpGroup->execute([$newGroupName, $targetMentorId, $postAyId]);
            flash('success', "Nama kelompok halaqah berhasil diperbarui untuk seluruh santri binaan mentor ini.");
        }
    }

    redirect('assignments.php?academic_year_id=' . $postAyId);
}

// ==========================================
// 2. QUERY FILTER & STATISTIK
// ==========================================
$filterClass  = trim($_GET['class'] ?? '');
$filterMentor = (int)($_GET['mentor_id'] ?? 0);
$filterSearch = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? ''); // 'assigned' vs 'unassigned'

$where = ["s.deleted_at IS NULL"];
$params = [$filterAyId];

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

// DAFTAR GURU MENTOR
$mentors = $pdo->query("
    SELECT id, name, username 
    FROM users 
    WHERE role IN ('staff', 'admin', 'kepala_sekolah')
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// DAFTAR KELAS
$classes = $pdo->query("SELECT DISTINCT name FROM class_groups ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

// STATISTIK KELOMPOK / MENTOR PADA TAHUN AJARAN INI
$stmtStats = $pdo->prepare("
    SELECT u.id as mentor_id, u.name as mentor_name, 
           COUNT(ma.student_id) as total_mentee,
           MAX(ma.group_name) as group_name
    FROM users u
    JOIN mentoring_assignments ma ON ma.mentor_user_id = u.id AND ma.academic_year_id = ? AND ma.is_active = 1
    JOIN students s ON s.id = ma.student_id AND s.deleted_at IS NULL
    GROUP BY u.id, u.name
    ORDER BY total_mentee DESC, u.name ASC
");
$stmtStats->execute([$filterAyId]);
$mentorStats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

$totalAssignedStudents = array_sum(array_column($mentorStats, 'total_mentee'));

// TOTAL SELURUH SANTRI AKTIF
$totalActiveStudents = (int)$pdo->query("
    SELECT COUNT(DISTINCT s.id) 
    FROM students s 
    JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    WHERE s.deleted_at IS NULL
")->fetchColumn();

// DAFTAR SANTRI SESUAI FILTER
$stmtStudents = $pdo->prepare("
    SELECT s.id as student_id, s.name as student_name, s.nis, cg.name as class_name,
           ma.id as assignment_id, ma.mentor_user_id, ma.group_name, u.name as mentor_name
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN mentoring_assignments ma ON ma.student_id = s.id AND ma.academic_year_id = ? AND ma.is_active = 1
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
                <i class="fa-solid fa-users-rectangle" style="color: #0284c7;"></i> Pengaturan Kelompok Mentee & Mentor
            </h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">
                Alokasi pembagian santri binaan per kelompok halaqah & guru pendamping terikat Tahun Pelajaran
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="curriculum.php?academic_year_id=<?= $filterAyId ?>" class="btn btn-primary" style="padding: 8px 16px; font-size: 13px; font-weight: 700; background: #059669; border-color: #059669;">
                <i class="fa-solid fa-book-quran"></i> Kelola Kurikulum Adab
            </a>
            <a href="index.php" class="btn btn-secondary" style="padding: 8px 16px; font-size: 13px;">
                <i class="fa-solid fa-arrow-left"></i> Dashboard Mentoring
            </a>
        </div>
    </div>

    <!-- Summary Metrics Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 22px;">
        <div class="card" style="padding: 16px 20px; border-radius: 14px; border-left: 5px solid #0284c7; background: #ffffff;">
            <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Tahun Pelajaran Aktif</div>
            <div style="font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 4px; display: flex; align-items: center; justify-content: space-between;">
                <?php 
                    $currAyName = 'Tahun Ajaran';
                    foreach ($academicYears as $ay) {
                        if ($ay['id'] == $filterAyId) { $currAyName = $ay['name']; break; }
                    }
                    echo e($currAyName);
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
            <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Belum Dapat Mentor</div>
            <div style="font-size: 22px; font-weight: 800; color: #d97706; margin-top: 4px; display: flex; align-items: center; justify-content: space-between;">
                <?= max(0, $totalActiveStudents - $totalAssignedStudents) ?> Santri
                <i class="fa-solid fa-user-clock" style="color: #f59e0b; opacity: 0.8;"></i>
            </div>
            <div style="font-size: 12px; color: #d97706; margin-top: 4px; font-weight: 600;">Perlu dialokasikan ke kelompok</div>
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
                    Belum ada guru mentor yang ditugaskan pada tahun pelajaran ini.
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

            <!-- Pencarian Santri -->
            <div style="flex: 1; min-width: 170px;">
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Cari Santri / Kelompok</label>
                <input type="text" name="search" value="<?= e($filterSearch) ?>" placeholder="Nama santri, NIS, halaqah..." class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
            </div>

            <!-- Filter Kelas -->
            <div>
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Kelas</label>
                <select name="class" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Semua Kelas --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= e($c) ?>" <?= $filterClass === $c ? 'selected' : '' ?>><?= e($c) ?></option>
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
                <label style="display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">Status Penugasan</label>
                <select name="status" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Semua Status --</option>
                    <option value="assigned" <?= $filterStatus === 'assigned' ? 'selected' : '' ?>>Sudah Ditugaskan</option>
                    <option value="unassigned" <?= $filterStatus === 'unassigned' ? 'selected' : '' ?>>Belum Ada Mentor</option>
                </select>
            </div>

            <div style="align-self: flex-end;">
                <button type="submit" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px; font-weight: 700;">
                    <i class="fa-solid fa-filter"></i> Terapkan
                </button>
                <?php if (!empty($filterClass) || $filterMentor > 0 || !empty($filterSearch) || !empty($filterStatus)): ?>
                    <a href="assignments.php?academic_year_id=<?= $filterAyId ?>" class="btn btn-secondary" style="padding: 8px 14px; font-size: 13px;">Reset</a>
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

            <!-- Toolbar Aksi Massal (Bulk Bar) -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; padding: 12px 16px; background: #f1f5f9; border-radius: 10px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; font-weight: 700; color: #1e293b;">
                        <input type="checkbox" id="selectAllCheckbox" style="transform: scale(1.2); cursor: pointer;" onchange="toggleSelectAll(this)">
                        Pilih Semua Santri (<span id="selectedCount">0</span> terpilih)
                    </label>
                </div>

                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span style="font-size: 13px; font-weight: 700; color: #475569;">Aksi Massal:</span>
                    <input type="text" name="bulk_group_name" placeholder="Nama Kelompok (Opsional)" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px; width: 170px;">
                    <select name="bulk_mentor_user_id" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12.5px;" id="bulkMentorSelect">
                        <option value="">-- Tetapkan ke Mentor --</option>
                        <?php foreach ($mentors as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= e($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary" style="padding: 6px 14px; font-size: 12.5px; font-weight: 700; background: #0284c7;" onclick="return confirmBulkAssign()">
                        <i class="fa-solid fa-users-gear"></i> Terapkan Massal
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
                            <th style="padding: 12px;">Kelas</th>
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
                                            <?= e($st['class_name'] ?? 'Santri') ?>
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
                                            <i class="fa-solid fa-user-pen"></i> Kelola
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
            <input type="hidden" name="action" value="assign_single">
            <input type="hidden" name="academic_year_id" value="<?= $filterAyId ?>">
            <input type="hidden" name="student_id" id="modalSingleStudentId">

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
                <label style="display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 6px;">Nama Kelompok / Halaqah (Opsional)</label>
                <input type="text" name="group_name" id="modalSingleGroupName" placeholder="Contoh: Halaqah Salman Al-Farisi" style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeSingleAssignModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 700;">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
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
    return confirm(`Apakah Anda yakin ingin menugaskan ${checked} santri ke guru mentor terpilih?`);
}

function openEditGroupModal(mentorId, mentorName, groupName) {
    document.getElementById('modalTargetMentorId').value = mentorId;
    document.getElementById('modalMentorNameText').innerText = mentorName;
    document.getElementById('modalNewGroupName').value = groupName;
    const modal = document.getElementById('modalEditGroup');
    modal.style.display = 'flex';
}

function closeEditGroupModal() {
    document.getElementById('modalEditGroup').style.display = 'none';
}

function openSingleAssignModal(studentId, studentName, mentorId, groupName) {
    document.getElementById('modalSingleStudentId').value = studentId;
    document.getElementById('modalSingleStudentName').innerText = studentName;
    document.getElementById('modalSingleMentorId').value = mentorId;
    document.getElementById('modalSingleGroupName').value = groupName;
    const modal = document.getElementById('modalSingleAssign');
    modal.style.display = 'flex';
}

function closeSingleAssignModal() {
    document.getElementById('modalSingleAssign').style.display = 'none';
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
