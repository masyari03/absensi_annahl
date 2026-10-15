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

$pageTitle = 'Penugasan Guru Mentor Santri';

// PROSES REASSIGN / ASSIGN MENTOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_mentor') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Sesi telah kadaluarsa. Silakan ulangi kembali.');
        redirect('assignments.php');
    }

    $studentId    = (int)($_POST['student_id'] ?? 0);
    $mentorUserId = (int)($_POST['mentor_user_id'] ?? 0);

    if ($studentId > 0 && $mentorUserId > 0) {
        // Cek apakah penugasan sudah ada
        $stmtCheck = $pdo->prepare("SELECT id FROM mentoring_assignments WHERE student_id = ? LIMIT 1");
        $stmtCheck->execute([$studentId]);
        $existing = $stmtCheck->fetchColumn();

        if ($existing) {
            $stmtUp = $pdo->prepare("UPDATE mentoring_assignments SET mentor_user_id = ?, is_active = 1 WHERE id = ?");
            $stmtUp->execute([$mentorUserId, $existing]);
        } else {
            $stmtIns = $pdo->prepare("INSERT INTO mentoring_assignments (student_id, mentor_user_id, is_active) VALUES (?, ?, 1)");
            $stmtIns->execute([$studentId, $mentorUserId]);
        }

        flash('success', 'Penugasan guru mentor berhasil diperbarui.');
    }
    redirect('assignments.php');
}

// FILTER PARAMETERS
$filterClass  = trim($_GET['class'] ?? '');
$filterMentor = (int)($_GET['mentor_id'] ?? 0);
$filterSearch = trim($_GET['search'] ?? '');

$where = ["s.deleted_at IS NULL"];
$params = [];

if ($filterClass !== '') {
    $where[] = "cg.name = ?";
    $params[] = $filterClass;
}
if ($filterMentor > 0) {
    $where[] = "ma.mentor_user_id = ?";
    $params[] = $filterMentor;
}
if ($filterSearch !== '') {
    $where[] = "(s.name LIKE ? OR s.nis LIKE ?)";
    $params[] = "%{$filterSearch}%";
    $params[] = "%{$filterSearch}%";
}

$whereSql = implode(' AND ', $where);

// DAFTAR GURU MENTOR (untuk dropdown pilihan)
$mentors = $pdo->query("
    SELECT id, name, username 
    FROM users 
    WHERE role IN ('staff', 'admin', 'kepala_sekolah')
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// DAFTAR KELAS
$classes = $pdo->query("SELECT DISTINCT name FROM class_groups ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

// TARIK DATA SANTRI & MENTOR AKTIF
$stmtStudents = $pdo->prepare("
    SELECT s.id as student_id, s.name as student_name, s.nis, cg.name as class_name,
           ma.id as assignment_id, ma.mentor_user_id, u.name as mentor_name
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
    LEFT JOIN class_groups cg ON cg.id = se.class_group_id
    LEFT JOIN mentoring_assignments ma ON ma.student_id = s.id AND ma.is_active = 1
    LEFT JOIN users u ON u.id = ma.mentor_user_id
    WHERE {$whereSql}
    ORDER BY cg.name ASC, s.name ASC
");
$stmtStudents->execute($params);
$studentsList = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../../includes/header.php';
?>

<div class="content">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-user-gear" style="color: #0284c7;"></i> Penugasan Guru Mentor Santri
            </h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">
                Alokasi santri binaan per kelompok bimbingan guru pendamping (rasio 4–6 santri)
            </p>
        </div>

        <a href="index.php" class="btn btn-secondary" style="padding: 8px 16px; font-size: 13px;">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard
        </a>
    </div>

    <!-- Filter Card -->
    <div class="card" style="padding: 16px 20px; border-radius: 14px; margin-bottom: 20px;">
        <form method="GET" action="assignments.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 180px;">
                <input type="text" name="search" value="<?= e($filterSearch) ?>" placeholder="Cari santri / NIS..." class="filter-input" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
            </div>

            <div>
                <select name="class" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Semua Kelas --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= e($c) ?>" <?= $filterClass === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <select name="mentor_id" class="filter-input" style="padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="0">-- Semua Guru Mentor --</option>
                    <?php foreach ($mentors as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $filterMentor == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 8px 16px; font-size: 13px; font-weight: 700;">Filter</button>
            <?php if (!empty($filterClass) || $filterMentor > 0 || !empty($filterSearch)): ?>
                <a href="assignments.php" class="btn btn-secondary" style="padding: 8px 12px; font-size: 13px;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabel Daftar Penugasan -->
    <div class="card" style="padding: 20px; border-radius: 16px;">
        <div class="table-responsive">
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 12px;">Santri</th>
                        <th style="padding: 12px;">Kelas</th>
                        <th style="padding: 12px;">Guru Mentor Aktif</th>
                        <th style="padding: 12px; text-align: right;">Ganti Mentor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($studentsList)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 30px; color: #94a3b8;">
                                Tidak ada santri yang sesuai filter pencarian.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($studentsList as $st): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
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
                                    <?php if (!empty($st['mentor_name'])): ?>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-user-check" style="color: #10b981;"></i>
                                            <span style="font-weight: 600; color: #0f172a;"><?= e($st['mentor_name']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span style="padding: 3px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 700; background: #fef2f2; color: #991b1b;">
                                            Belum Ditugaskan
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; text-align: right;">
                                    <form method="POST" action="assignments.php" style="display: inline-flex; gap: 6px; align-items: center;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="assign_mentor">
                                        <input type="hidden" name="student_id" value="<?= $st['student_id'] ?>">
                                        <select name="mentor_user_id" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px;" required onchange="this.form.submit()">
                                            <option value="">-- Pilih Mentor --</option>
                                            <?php foreach ($mentors as $m): ?>
                                                <option value="<?= $m['id'] ?>" <?= $st['mentor_user_id'] == $m['id'] ? 'selected' : '' ?>>
                                                    <?= e($m['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
