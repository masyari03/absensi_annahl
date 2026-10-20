<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMenuAccess('supervisi_jadwal');

$pageTitle = 'Jadwal Supervisi Sekolah';
$currentUserId = currentUserId();
$currentRole = currentRole();

// =========================================================================
// ACTION: POST HANDLERS (TAMBAH / EDIT / HAPUS JADWAL)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH JADWAL BARU
    if ($action === 'add_schedule') {
        $typeId = (int)($_POST['type_id'] ?? 1);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $observerId = (int)($_POST['observer_id'] ?? 0);
        $subjectName = trim($_POST['subject_name'] ?? '');
        $className = trim($_POST['class_name'] ?? '');
        $schedDate = trim($_POST['scheduled_date'] ?? '');
        $timeStart = !empty($_POST['time_start']) ? trim($_POST['time_start']) : null;
        $timeEnd = !empty($_POST['time_end']) ? trim($_POST['time_end']) : null;
        $roomLocation = trim($_POST['room_location'] ?? '');

        if ($teacherId > 0 && $observerId > 0 && !empty($subjectName) && !empty($schedDate)) {
            $stmt = $pdo->prepare("
                INSERT INTO supervision_schedules (
                    type_id, teacher_id, observer_id, subject_name, class_name,
                    scheduled_date, time_start, time_end, room_location, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)
            ");
            $stmt->execute([$typeId, $teacherId, $observerId, $subjectName, $className, $schedDate, $timeStart, $timeEnd, $roomLocation, $currentUserId]);
            flash('success', 'Jadwal supervisi baru berhasil ditambahkan!');
        } else {
            flash('error', 'Harap lengkapi informasi guru, observer, mapel, dan tanggal jadwal.');
        }
        redirect('schedules.php');
    }

    // 2. EDIT JADWAL
    if ($action === 'edit_schedule') {
        $schedId = (int)($_POST['schedule_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 1);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $observerId = (int)($_POST['observer_id'] ?? 0);
        $subjectName = trim($_POST['subject_name'] ?? '');
        $className = trim($_POST['class_name'] ?? '');
        $schedDate = trim($_POST['scheduled_date'] ?? '');
        $timeStart = !empty($_POST['time_start']) ? trim($_POST['time_start']) : null;
        $timeEnd = !empty($_POST['time_end']) ? trim($_POST['time_end']) : null;
        $roomLocation = trim($_POST['room_location'] ?? '');
        $status = trim($_POST['status'] ?? 'scheduled');

        if ($schedId > 0 && $teacherId > 0 && $observerId > 0) {
            $stmt = $pdo->prepare("
                UPDATE supervision_schedules
                SET type_id = ?, teacher_id = ?, observer_id = ?, subject_name = ?, class_name = ?,
                    scheduled_date = ?, time_start = ?, time_end = ?, room_location = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$typeId, $teacherId, $observerId, $subjectName, $className, $schedDate, $timeStart, $timeEnd, $roomLocation, $status, $schedId]);
            flash('success', 'Jadwal supervisi berhasil diperbarui!');
        }
        redirect('schedules.php');
    }

    // 3. HAPUS JADWAL
    if ($action === 'delete_schedule') {
        $schedId = (int)($_POST['schedule_id'] ?? 0);
        if ($schedId > 0) {
            $pdo->prepare("DELETE FROM supervision_schedules WHERE id = ?")->execute([$schedId]);
            flash('success', 'Jadwal supervisi berhasil dihapus.');
        }
        redirect('schedules.php');
    }
}

// =========================================================================
// QUERY DATA TAMPILAN
// =========================================================================
$search = trim($_GET['search'] ?? '');
$filterType = (int)($_GET['type_id'] ?? 0);

$query = "
    SELECT ss.*, 
           st.name as type_name, st.type_key,
           s.name as teacher_name, s.nik as teacher_nik,
           u.name as observer_name
    FROM supervision_schedules ss
    JOIN supervision_types st ON st.id = ss.type_id
    JOIN staff s ON s.id = ss.teacher_id
    JOIN users u ON u.id = ss.observer_id
    WHERE 1=1
";
$params = [];

if ($filterType > 0) {
    $query .= " AND ss.type_id = ?";
    $params[] = $filterType;
}

if (!empty($search)) {
    $query .= " AND (s.name LIKE ? OR u.name LIKE ? OR ss.subject_name LIKE ? OR ss.class_name LIKE ? OR ss.room_location LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$query .= " ORDER BY ss.scheduled_date DESC, ss.time_start ASC, ss.id DESC";

$stmtSchedules = $pdo->prepare($query);
$stmtSchedules->execute($params);
$schedules = $stmtSchedules->fetchAll(PDO::FETCH_ASSOC);

// Master Data untuk Dropdown Form
$types = $pdo->query("SELECT id, name, type_key FROM supervision_types WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$teachers = $pdo->query("SELECT id, name, nik FROM staff WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$observers = $pdo->query("SELECT id, name, username, role FROM users WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$mapelList = [
    "Pendidikan Agama Islam / Al-Qur'an",
    "Bahasa Indonesia",
    "Bahasa Inggris",
    "Matematika",
    "Ilmu Pengetahuan Alam (IPA)",
    "Ilmu Pengetahuan Sosial (IPS)",
    "Informatika / TIK",
    "Pendidikan Pancasila (PPKn)",
    "Seni Budaya & Prakarya",
    "PJOK"
];

function formatIndoDate($dateStr) {
    if (empty($dateStr)) return '-';
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($dateStr);
    $dayName = $days[(int)date('w', $ts)];
    $d = date('j', $ts);
    $m = $months[(int)date('n', $ts)];
    $y = date('Y', $ts);
    return "{$dayName}, {$d} {$m} {$y}";
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="supervisi-fullwidth" style="width: 100%; max-width: 100%;">
    <!-- HEADER -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <span style="background: #e0f2fe; color: #0369a1; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 6px; text-transform: uppercase;">
                    <i class="fa-solid fa-calendar-days"></i> Manajemen Waktu
                </span>
                <span style="color: #94a3b8; font-size: 13px;">&bull; SMP An Nahl Islamic School</span>
            </div>
            <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #0f172a;">
                Jadwal Supervisi Sekolah
            </h1>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 14px;">
                Menampilkan <?= count($schedules) ?> jadwal supervisi pembelajaran guru terencana.
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <button type="button" class="btn btn-primary" onclick="openAddScheduleModal()" style="display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);">
                <i class="fa-solid fa-plus"></i> Tambah Jadwal Baru
            </button>
        </div>
    </div>

    <!-- FILTER & SEARCH BAR -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
        <form method="GET" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 280px; position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 11px; color: #94a3b8; font-size: 13px;"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari jadwal berdasar nama guru, observer, hari, mapel, atau ruang..." class="form-control" style="padding-left: 36px;">
            </div>

            <div style="width: 220px;">
                <select name="type_id" class="form-control" onchange="this.form.submit()">
                    <option value="0">-- Semua Jenis Supervisi --</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($filterType == $t['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if (!empty($search) || $filterType > 0): ?>
                <a href="schedules.php" class="btn btn-secondary" title="Reset Filter">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- TABEL JADWAL SUPERVISI -->
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <?php if (empty($schedules)): ?>
            <div style="padding: 48px; text-align: center; color: #64748b;">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 40px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                Belum ada jadwal supervisi yang sesuai. Silakan klik tombol <strong>"Tambah Jadwal Baru"</strong> untuk menjadwalkan.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                            <th style="padding: 14px 16px; width: 45px; text-align: center;">No</th>
                            <th style="padding: 14px 16px; width: 190px;">Hari & Tanggal</th>
                            <th style="padding: 14px 14px; width: 120px;">Waktu</th>
                            <th style="padding: 14px 16px;">Guru yang Dinilai</th>
                            <th style="padding: 14px 16px;">Guru Observer</th>
                            <th style="padding: 14px 16px;">Mata Pelajaran</th>
                            <th style="padding: 14px 14px; width: 110px;">Lokasi</th>
                            <th style="padding: 14px 16px; width: 220px; text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($schedules as $sc): 
                            $isCompleted = ($sc['status'] === 'completed');
                            $timeStr = '-';
                            if (!empty($sc['time_start'])) {
                                $timeStr = substr($sc['time_start'], 0, 5) . (!empty($sc['time_end']) ? ' - ' . substr($sc['time_end'], 0, 5) : '');
                            }
                            $isPeer = ($sc['type_key'] === 'peer');
                            $evalUrl = $isPeer ? "peer.php?schedule_id={$sc['id']}" : "principal.php?schedule_id={$sc['id']}";
                        ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                <td style="padding: 14px 16px; text-align: center; color: #94a3b8; font-weight: 700;">
                                    <?= $no++ ?>
                                </td>
                                <td style="padding: 14px 16px; font-weight: 600; color: #1e293b;">
                                    <?= formatIndoDate($sc['scheduled_date']) ?>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                        <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: <?= $isPeer ? '#4f46e5' : '#9333ea' ?>; margin-right: 4px;"></span>
                                        <?= htmlspecialchars($sc['type_name']) ?>
                                    </div>
                                </td>
                                <td style="padding: 14px 14px; color: #475569; font-weight: 600; font-size: 12.5px;">
                                    <?= htmlspecialchars($timeStr) ?>
                                </td>
                                <td style="padding: 14px 16px;">
                                    <span style="font-weight: 700; color: #0f172a; display: block;">
                                        <?= htmlspecialchars($sc['teacher_name']) ?>
                                    </span>
                                    <?php if (!empty($sc['class_name'])): ?>
                                        <span style="font-size: 11px; color: #64748b;">
                                            Kelas: <?= htmlspecialchars($sc['class_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 14px 16px; color: #334155; font-weight: 500;">
                                    <?= htmlspecialchars($sc['observer_name']) ?>
                                </td>
                                <td style="padding: 14px 16px; color: #1e293b; font-weight: 600;">
                                    <?= htmlspecialchars($sc['subject_name']) ?>
                                </td>
                                <td style="padding: 14px 14px; color: #64748b; font-size: 12.5px;">
                                    <?= htmlspecialchars($sc['room_location'] ?: '-') ?>
                                </td>
                                <td style="padding: 14px 16px; text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px;">
                                        <?php if ($isCompleted): ?>
                                            <span style="background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 700; padding: 5px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fa-solid fa-check-circle"></i> Selesai
                                            </span>
                                        <?php else: ?>
                                            <!-- TOMBOL HIJAU NILAI SEKARANG SESUAI SCREENSHOT 4 -->
                                            <a href="<?= $evalUrl ?>" class="btn btn-sm btn-success" style="background: #10b981; border-color: #10b981; color: white; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; border-radius: 6px; text-decoration: none;">
                                                <i class="fa-solid fa-plus"></i> Nilai Sekarang
                                            </a>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-sm btn-secondary" onclick='openEditScheduleModal(<?= json_encode($sc) ?>)' title="Edit Jadwal">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>

                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus jadwal supervisi ini?');">
                                            <input type="hidden" name="action" value="delete_schedule">
                                            <input type="hidden" name="schedule_id" value="<?= $sc['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus Jadwal">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TAMBAH / EDIT JADWAL SUPERVISI -->
<!-- ========================================================================= -->
<div id="modalSchedule" class="custom-modal-overlay">
    <div class="custom-modal-box" style="max-width: 620px;">
        <div class="custom-modal-header">
            <h3 id="modalScheduleTitle" style="margin: 0; font-size: 17px; color: #0f172a;">Tambah Jadwal Supervisi Baru</h3>
            <button type="button" class="btn-close-sidebar" style="position: static;" onclick="closeModal('modalSchedule')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" id="schedActionInput" value="add_schedule">
            <input type="hidden" name="schedule_id" id="schedIdInput" value="">

            <div class="custom-modal-body" style="padding: 20px;">
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Jenis Supervisi *</label>
                    <select name="type_id" id="schedTypeSelect" class="form-control" required>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Guru yang Dinilai *</label>
                        <select name="teacher_id" id="schedTeacherSelect" class="form-control" required>
                            <option value="">-- Pilih Guru --</option>
                            <?php foreach ($teachers as $tc): ?>
                                <option value="<?= $tc['id'] ?>"><?= htmlspecialchars($tc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Guru Observer *</label>
                        <select name="observer_id" id="schedObserverSelect" class="form-control" required>
                            <option value="">-- Pilih Observer --</option>
                            <?php foreach ($observers as $ob): ?>
                                <option value="<?= $ob['id'] ?>"><?= htmlspecialchars($ob['name']) ?> (<?= htmlspecialchars($ob['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Mata Pelajaran *</label>
                        <input type="text" name="subject_name" id="schedSubjectInput" list="mapelModalList" placeholder="Misal: Bahasa Inggris" class="form-control" required>
                        <datalist id="mapelModalList">
                            <?php foreach ($mapelList as $mp): ?>
                                <option value="<?= htmlspecialchars($mp) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Kelas *</label>
                        <input type="text" name="class_name" id="schedClassInput" placeholder="Misal: 8C" class="form-control" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Tanggal Supervisi *</label>
                        <input type="date" name="scheduled_date" id="schedDateInput" value="<?= date('Y-m-d') ?>" class="form-control" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Jam Mulai</label>
                        <input type="time" name="time_start" id="schedTimeStartInput" class="form-control">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Jam Selesai</label>
                        <input type="time" name="time_end" id="schedTimeEndInput" class="form-control">
                    </div>
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Lokasi / Ruangan</label>
                    <input type="text" name="room_location" id="schedRoomInput" placeholder="Misal: Kelas 8C / Lab Komputer" class="form-control">
                </div>

                <div id="schedStatusContainer" style="display: none; margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #334155;">Status Jadwal</label>
                    <select name="status" id="schedStatusSelect" class="form-control">
                        <option value="scheduled">Dijadwalkan</option>
                        <option value="in_progress">Sedang Berlangsung</option>
                        <option value="completed">Selesai</option>
                        <option value="cancelled">Dibatalkan</option>
                    </select>
                </div>
            </div>

            <div class="custom-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalSchedule')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddScheduleModal() {
    document.getElementById('modalScheduleTitle').innerText = 'Tambah Jadwal Supervisi Baru';
    document.getElementById('schedActionInput').value = 'add_schedule';
    document.getElementById('schedIdInput').value = '';
    document.getElementById('schedTeacherSelect').value = '';
    document.getElementById('schedObserverSelect').value = '<?= $currentUserId ?>';
    document.getElementById('schedSubjectInput').value = '';
    document.getElementById('schedClassInput').value = '';
    document.getElementById('schedDateInput').value = '<?= date('Y-m-d') ?>';
    document.getElementById('schedTimeStartInput').value = '07:30';
    document.getElementById('schedTimeEndInput').value = '08:50';
    document.getElementById('schedRoomInput').value = '';
    document.getElementById('schedStatusContainer').style.display = 'none';
    document.getElementById('modalSchedule').style.display = 'flex';
}

function openEditScheduleModal(sc) {
    document.getElementById('modalScheduleTitle').innerText = 'Edit Jadwal Supervisi';
    document.getElementById('schedActionInput').value = 'edit_schedule';
    document.getElementById('schedIdInput').value = sc.id;
    document.getElementById('schedTypeSelect').value = sc.type_id;
    document.getElementById('schedTeacherSelect').value = sc.teacher_id;
    document.getElementById('schedObserverSelect').value = sc.observer_id;
    document.getElementById('schedSubjectInput').value = sc.subject_name;
    document.getElementById('schedClassInput').value = sc.class_name;
    document.getElementById('schedDateInput').value = sc.scheduled_date;
    document.getElementById('schedTimeStartInput').value = sc.time_start ? sc.time_start.substring(0, 5) : '';
    document.getElementById('schedTimeEndInput').value = sc.time_end ? sc.time_end.substring(0, 5) : '';
    document.getElementById('schedRoomInput').value = sc.room_location || '';
    document.getElementById('schedStatusSelect').value = sc.status;
    document.getElementById('schedStatusContainer').style.display = 'block';
    document.getElementById('modalSchedule').style.display = 'flex';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
