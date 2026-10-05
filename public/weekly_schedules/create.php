<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('master_schedules');
$currentRole = currentRole();
$pageTitle = 'Tambah Jadwal Pekanan & Eskul';
$userId = currentUserId();

$ksUnitId = 0;
$ksUnitName = '';
if ($currentRole === 'kepala_sekolah') {
    $stmtKs = $pdo->prepare("
        SELECT aup.unit_id, u.unit 
        FROM admin_unit_permissions aup
        JOIN units u ON u.id = aup.unit_id
        WHERE aup.user_id = ? LIMIT 1
    ");
    $stmtKs->execute([$userId]);
    $ksRow = $stmtKs->fetch(PDO::FETCH_ASSOC);
    if ($ksRow) {
        $ksUnitId = (int)$ksRow['unit_id'];
        $ksUnitName = $ksRow['unit'];
    }
}

$units = $pdo->query("SELECT * FROM units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch grades dan class groups untuk dropdown dinamis
$gradesStmt = $pdo->query("SELECT g.id, g.grade, g.unit_id, u.unit as unit_name FROM grades g JOIN units u ON u.id = g.unit_id ORDER BY u.id, g.sort_order ASC");
$allGrades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);

$classesStmt = $pdo->query("SELECT cg.id, cg.name, cg.grade_id, g.unit_id FROM class_groups cg JOIN grades g ON g.id = cg.grade_id ORDER BY g.unit_id, cg.name ASC");
$allClasses = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

$initialType = trim($_GET['type'] ?? $_GET['tab'] ?? 'student');
if (!in_array($initialType, ['student', 'staff', 'eskul'])) {
    $initialType = 'student';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = in_array($_POST['schedule_mode'] ?? '', ['student', 'staff', 'eskul']) ? $_POST['schedule_mode'] : 'student';
    $unitId = ($currentRole === 'super_admin') ? (int)($_POST['unit_id'] ?? 0) : $ksUnitId;
    
    $dayCodes = $_POST['day_codes'] ?? [];
    $days = [1=>'Senin', 2=>'Selasa', 3=>'Rabu', 4=>'Kamis', 5=>'Jumat', 6=>'Sabtu', 7=>'Minggu'];
    $isActive = ($_POST['is_active'] ?? 'active') === 'active' ? 'active' : 'inactive';

    if (empty($dayCodes) || !is_array($dayCodes) || $unitId <= 0) {
        flash('error', 'Mohon pilih unit dan minimal satu hari pelaksanaan.');
    } else {
        $pdo->beginTransaction();
        try {
            if ($mode === 'student') {
                // JADWAL SISWA (KBM)
                $targetType = 'student';
                $scheduleType = 'reguler';
                $name = 'KBM Reguler';

                $scopeLevel = $_POST['student_scope_level'] ?? 'unit';
                $gradeId = null;
                $classGroupId = null;

                if ($scopeLevel === 'grade') {
                    $gradeId = (int)($_POST['grade_id'] ?? 0) ?: null;
                } elseif ($scopeLevel === 'class_group') {
                    $classGroupId = (int)($_POST['class_group_id'] ?? 0) ?: null;
                    // Ambil grade_id dari class_group
                    if ($classGroupId) {
                        $stmtCgG = $pdo->prepare("SELECT grade_id FROM class_groups WHERE id = ?");
                        $stmtCgG->execute([$classGroupId]);
                        $gradeId = (int)$stmtCgG->fetchColumn() ?: null;
                    }
                }

                $studentIn = $_POST['student_in'] ?? '07:00';
                $studentLate = $_POST['student_late'] ?? '07:15';
                $studentOut = $_POST['student_out'] ?? '14:00';
                $staffIn = '00:00:00';
                $staffLate = '00:00:00';
                $staffOut = '00:00:00';
                $isOvernight = 0;

                $stmt = $pdo->prepare("
                    INSERT INTO weekly_schedules 
                    (unit_id, target_type, grade_id, class_group_id, schedule_type, name, day_name, day_code, student_in, student_late, student_out, staff_in, staff_late, staff_out, is_overnight, is_active)
                    VALUES (?, 'student', ?, ?, 'reguler', ?, ?, ?, ?, ?, ?, '00:00:00', '00:00:00', '00:00:00', 0, ?)
                ");

                foreach ($dayCodes as $code) {
                    $code = (int)$code;
                    $dayName = $days[$code] ?? '';
                    if ($dayName) {
                        $stmt->execute([
                            $unitId, $gradeId, $classGroupId, $name, $dayName, $code,
                            $studentIn, $studentLate, $studentOut, $isActive
                        ]);
                        $schId = (int)$pdo->lastInsertId();

                        recordActivityAudit($pdo, 'jadwal', 'CREATE', 'weekly_schedules', $schId, 
                            "Tambah Jadwal Siswa Hari {$dayName} (Unit #{$unitId}, Grade #{$gradeId}, Subkelas #{$classGroupId})", 
                            null, ['unit_id' => $unitId, 'grade_id' => $gradeId, 'class_group_id' => $classGroupId, 'student_in' => $studentIn, 'student_out' => $studentOut], $unitId);
                    }
                }

                $pdo->commit();
                flash('success', 'Jadwal KBM Siswa berhasil ditambahkan.');
                redirect('index.php?tab=student' . ($currentRole === 'super_admin' ? '&unit_id=' . $unitId : ''));
                exit;

            } elseif ($mode === 'staff') {
                // JADWAL STAFF & GURU
                $targetType = 'staff';
                $scheduleType = 'reguler';
                $name = trim($_POST['staff_shift_name'] ?? '');
                if (empty($name)) {
                    $name = 'Shift Reguler Staff';
                }

                $staffIn = $_POST['staff_in'] ?? '06:45';
                $staffLate = $_POST['staff_late'] ?? '07:00';
                $staffOut = $_POST['staff_out'] ?? '15:30';

                $isOvernight = 0;
                if ($staffIn && $staffOut && strtotime($staffOut) < strtotime($staffIn)) {
                    $isOvernight = 1;
                }
                if (!empty($_POST['is_overnight'])) {
                    $isOvernight = 1;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO weekly_schedules 
                    (unit_id, target_type, grade_id, class_group_id, schedule_type, name, day_name, day_code, student_in, student_late, student_out, staff_in, staff_late, staff_out, is_overnight, is_active)
                    VALUES (?, 'staff', NULL, NULL, 'reguler', ?, ?, ?, '00:00:00', '00:00:00', '00:00:00', ?, ?, ?, ?, ?)
                ");

                $staffIds = $_POST['staff_ids'] ?? [];
                $stmtStaffRel = $pdo->prepare("
                    INSERT IGNORE INTO weekly_schedule_staff (weekly_schedule_id, staff_id) VALUES (?, ?)
                ");

                foreach ($dayCodes as $code) {
                    $code = (int)$code;
                    $dayName = $days[$code] ?? '';
                    if ($dayName) {
                        $stmt->execute([
                            $unitId, $name, $dayName, $code,
                            $staffIn, $staffLate, $staffOut, $isOvernight, $isActive
                        ]);
                        $schId = (int)$pdo->lastInsertId();

                        if (!empty($staffIds) && is_array($staffIds)) {
                            foreach ($staffIds as $stId) {
                                $stmtStaffRel->execute([$schId, (int)$stId]);
                            }
                        }

                        recordActivityAudit($pdo, 'jadwal', 'CREATE', 'weekly_schedules', $schId, 
                            "Tambah Jadwal Staff ({$name}) Hari {$dayName} - Unit #{$unitId}", 
                            null, ['unit_id' => $unitId, 'name' => $name, 'staff_in' => $staffIn, 'staff_out' => $staffOut, 'is_overnight' => $isOvernight, 'staff_count' => count($staffIds)], $unitId);
                    }
                }

                $pdo->commit();
                flash('success', 'Jadwal Staff & Guru berhasil ditambahkan.');
                redirect('index.php?tab=staff' . ($currentRole === 'super_admin' ? '&unit_id=' . $unitId : ''));
                exit;

            } elseif ($mode === 'eskul') {
                // KEGIATAN ESKUL
                $targetType = 'student';
                $scheduleType = 'eskul';
                $name = trim($_POST['eskul_name'] ?? '');
                if (empty($name)) {
                    throw new Exception('Nama kegiatan eskul wajib diisi.');
                }

                $studentIn = $_POST['eskul_in'] ?? '15:00';
                $studentLate = $_POST['eskul_late'] ?? '15:15';
                $studentOut = $_POST['eskul_out'] ?? '17:00';
                $studentIds = $_POST['student_ids'] ?? [];

                $stmt = $pdo->prepare("
                    INSERT INTO weekly_schedules 
                    (unit_id, target_type, grade_id, class_group_id, schedule_type, name, day_name, day_code, student_in, student_late, student_out, staff_in, staff_late, staff_out, is_overnight, is_active)
                    VALUES (?, 'student', NULL, NULL, 'eskul', ?, ?, ?, ?, ?, ?, '00:00:00', '00:00:00', '00:00:00', 0, ?)
                ");

                $stmtStudentRel = $pdo->prepare("
                    INSERT IGNORE INTO weekly_schedule_students (weekly_schedule_id, student_id) VALUES (?, ?)
                ");

                foreach ($dayCodes as $code) {
                    $code = (int)$code;
                    $dayName = $days[$code] ?? '';
                    if ($dayName) {
                        $stmt->execute([
                            $unitId, $name, $dayName, $code,
                            $studentIn, $studentLate, $studentOut, $isActive
                        ]);
                        $schId = (int)$pdo->lastInsertId();

                        if (!empty($studentIds) && is_array($studentIds)) {
                            foreach ($studentIds as $sId) {
                                $stmtStudentRel->execute([$schId, (int)$sId]);
                            }
                        }

                        recordActivityAudit($pdo, 'jadwal', 'CREATE', 'weekly_schedules', $schId, 
                            "Tambah Kegiatan Eskul ({$name}) Hari {$dayName} - Unit #{$unitId} dengan " . count($studentIds) . " peserta", 
                            null, ['unit_id' => $unitId, 'name' => $name, 'peserta_count' => count($studentIds)], $unitId);
                    }
                }

                $pdo->commit();
                flash('success', 'Kegiatan Eskul berhasil ditambahkan.');
                redirect('index.php?tab=eskul' . ($currentRole === 'super_admin' ? '&unit_id=' . $unitId : ''));
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('error', 'Terjadi kesalahan saat menyimpan jadwal: ' . $e->getMessage());
        }
    }
}

require '../../includes/header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h3 style="margin: 0;"><i class="fa-solid fa-plus-circle" style="color:#0284c7; margin-right:8px;"></i> Tambah Jadwal Pekanan / Eskul</h3>
            <p style="color: #64748b; margin: 4px 0 0 0;">Pilih jenis jadwal yang ingin Anda buat untuk mengatur jam masuk dan kepulangan.</p>
        </div>
        
        <!-- MODE SELECTOR BUTTONS -->
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="button" id="btnModeStudent" class="btn <?= $initialType === 'student' ? 'btn-primary' : 'btn-light' ?>" onclick="switchScheduleMode('student')" style="border: 1px solid #cbd5e1; font-weight: 600;">
                <i class="fa-solid fa-graduation-cap"></i> 🎓 Jadwal Siswa (KBM)
            </button>
            <button type="button" id="btnModeStaff" class="btn <?= $initialType === 'staff' ? 'btn-primary' : 'btn-light' ?>" onclick="switchScheduleMode('staff')" style="border: 1px solid #cbd5e1; font-weight: 600;">
                <i class="fa-solid fa-user-tie"></i> 💼 Jadwal Staff & Guru
            </button>
            <button type="button" id="btnModeEskul" class="btn <?= $initialType === 'eskul' ? 'btn-primary' : 'btn-light' ?>" onclick="switchScheduleMode('eskul')" style="border: 1px solid #cbd5e1; font-weight: 600;">
                <i class="fa-solid fa-futbol"></i> ⚽ Kegiatan Eskul
            </button>
        </div>
    </div>

    <form method="POST" id="formSchedule">
        <input type="hidden" name="schedule_mode" id="inputScheduleMode" value="<?= e($initialType) ?>">

        <div class="form-grid">
            <!-- Pilihan Unit -->
            <div class="form-group full">
                <label>Pilih Unit Sekolah *</label>
                <?php if ($currentRole === 'super_admin'): ?>
                    <select name="unit_id" id="selectUnit" required onchange="onUnitChange()" style="font-weight: 600;">
                        <option value="">-- Pilih Unit Sekolah --</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" value="<?= e($ksUnitName ?: 'Unit Anda') ?>" disabled style="background:#f1f5f9; cursor:not-allowed; font-weight: 700;">
                    <input type="hidden" name="unit_id" id="selectUnit" value="<?= (int)$ksUnitId ?>">
                    <small style="color:#0284c7; font-weight:600;"><i class="fa-solid fa-circle-check"></i> Khusus ditambahkan ke Unit Anda.</small>
                <?php endif; ?>
            </div>

            <!-- ================================================================= -->
            <!-- 1. BAGIAN KHUSUS JADWAL SISWA (KBM)                               -->
            <!-- ================================================================= -->
            <div id="sectionStudentSchedule" class="form-group full" style="<?= $initialType === 'student' ? '' : 'display:none;' ?>">
                <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 16px; margin-bottom: 15px;">
                    <label style="font-size: 13.5px; font-weight: 700; color: #0369a1; display: block; margin-bottom: 10px;">
                        <i class="fa-solid fa-sitemap"></i> Lingkup Pengaturan Jadwal Siswa (KBM):
                    </label>
                    <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom: 12px;">
                        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:#0f172a;">
                            <input type="radio" name="student_scope_level" value="unit" checked onchange="onStudentScopeChange('unit')">
                            🏫 Seluruh Unit (Umum)
                        </label>
                        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:#0f172a;">
                            <input type="radio" name="student_scope_level" value="grade" onchange="onStudentScopeChange('grade')">
                            🎯 Per Tingkat / Grade (Misal Kelas 1, 2, dst)
                        </label>
                        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:#0f172a;">
                            <input type="radio" name="student_scope_level" value="class_group" onchange="onStudentScopeChange('class_group')">
                            🏷️ Per Subkelas (Misal 1A, 1B, dst)
                        </label>
                    </div>

                    <!-- Dropdown Grade -->
                    <div id="rowSelectGrade" style="display:none; margin-top:10px;">
                        <label style="font-size:12.5px; font-weight:600; color:#0369a1;">Pilih Tingkat / Grade *</label>
                        <select name="grade_id" id="selectStudentGrade" onchange="onStudentGradeChange()" style="width:100%; max-width:400px; padding:7px 10px; border-radius:6px; border:1px solid #7dd3fc; background:white;">
                            <option value="">-- Pilih Tingkat / Grade --</option>
                        </select>
                    </div>

                    <!-- Dropdown Subkelas -->
                    <div id="rowSelectClass" style="display:none; margin-top:10px;">
                        <label style="font-size:12.5px; font-weight:600; color:#0369a1;">Pilih Subkelas *</label>
                        <select name="class_group_id" id="selectStudentClass" style="width:100%; max-width:400px; padding:7px 10px; border-radius:6px; border:1px solid #7dd3fc; background:white;">
                            <option value="">-- Pilih Subkelas --</option>
                        </select>
                    </div>

                    <small style="color:#0284c7; display:block; margin-top:8px;">
                        <i class="fa-solid fa-circle-info"></i> Jika Anda mengatur jadwal pada Subkelas atau Grade, jam tersebut otomatis memprioritaskan siswa di kelas/tingkat tersebut (menimpa jadwal unit).
                    </small>
                </div>
            </div>

            <!-- ================================================================= -->
            <!-- 2. BAGIAN KHUSUS JADWAL STAFF & GURU                              -->
            <!-- ================================================================= -->
            <div id="sectionStaffSchedule" class="form-group full" style="<?= $initialType === 'staff' ? '' : 'display:none;' ?>">
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin-bottom: 15px;">
                    <label style="font-size: 13px; font-weight: 700; color: #166534;">Nama Shift Staff / Guru *</label>
                    <input type="text" name="staff_shift_name" id="inputStaffShiftName" placeholder="Contoh: Shift Pagi Guru, Shift Siang Staff, Shift Malam Security..." style="margin-top:6px;">
                    <small style="color: #15803d; margin-top:4px; display:block;">Berikan nama pengenal shift kerja untuk staff di unit ini.</small>

                    <div style="margin-top: 12px; background: white; padding: 10px 14px; border-radius: 6px; border: 1px solid #86efac;">
                        <label style="cursor:pointer; display: flex; align-items: center; gap: 8px; color: #92400e; font-weight: 700; font-size: 13px;">
                            <input type="checkbox" name="is_overnight" id="checkOvernight" value="1">
                            🌙 Shift Malam (Lintas Hari / Pulang Keesokan Paginya - Khusus Security / Penjaga)
                        </label>
                        <small style="color: #b45309; display:block; margin-top:2px;">Centang jika jam dinas melewati tengah malam (misal masuk 18:00 malam, pulang 06:00 pagi).</small>
                    </div>
                </div>
            </div>

            <!-- ================================================================= -->
            <!-- 3. BAGIAN KHUSUS KEGIATAN ESKUL                                   -->
            <!-- ================================================================= -->
            <div id="sectionEskulSchedule" class="form-group full" style="<?= $initialType === 'eskul' ? '' : 'display:none;' ?>">
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 16px; margin-bottom: 15px;">
                    <label style="font-size: 13px; font-weight: 700; color: #92400e;">Nama Kegiatan Eskul *</label>
                    <input type="text" name="eskul_name" id="inputEskulName" placeholder="Contoh: Eskul Futsal, Tari Tradisional, Pramuka, Robotik, Tahfidz..." style="margin-top:6px;">
                    <small style="color: #b45309; margin-top:4px; display:block;">Nama kegiatan ekstrakurikuler yang akan muncul pada laporan presensi dan notifikasi WhatsApp.</small>
                </div>
            </div>

            <!-- PILIHAN HARI PELAKSANAAN -->
            <div class="form-group full">
                <label style="font-weight: 700;">Pilih Hari Pelaksanaan (Ceklis semua hari yang memiliki jam sama) *</label>
                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 8px; background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="1" checked> Senin</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="2" checked> Selasa</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="3" checked> Rabu</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="4" checked> Kamis</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="5" checked> Jumat</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="6"> Sabtu</label>
                    <label style="cursor:pointer;"><input type="checkbox" name="day_codes[]" value="7"> Minggu</label>
                </div>
            </div>

            <!-- ================================================================= -->
            <!-- JAM MASUK, TELAT, DAN PULANG (MENYESUAIKAN MODE AKTIF)            -->
            <!-- ================================================================= -->
            <!-- Mode Siswa KBM -->
            <div id="rowHoursStudent" style="<?= $initialType === 'student' ? 'display:contents;' : 'display:none;' ?>">
                <div class="form-group"><label>Siswa: Jam Masuk</label><input type="time" name="student_in" value="07:00"></div>
                <div class="form-group"><label>Siswa: Batas Telat</label><input type="time" name="student_late" value="07:15"></div>
                <div class="form-group"><label>Siswa: Jam Pulang</label><input type="time" name="student_out" value="14:00"></div>
            </div>

            <!-- Mode Staff & Guru -->
            <div id="rowHoursStaff" style="<?= $initialType === 'staff' ? 'display:contents;' : 'display:none;' ?>">
                <div class="form-group"><label>Staff: Jam Masuk</label><input type="time" name="staff_in" value="06:45"></div>
                <div class="form-group"><label>Staff: Batas Telat</label><input type="time" name="staff_late" value="07:00"></div>
                <div class="form-group"><label>Staff: Jam Pulang</label><input type="time" name="staff_out" value="15:30"></div>
            </div>

            <!-- Mode Eskul -->
            <div id="rowHoursEskul" style="<?= $initialType === 'eskul' ? 'display:contents;' : 'display:none;' ?>">
                <div class="form-group"><label>Eskul: Jam Mulai</label><input type="time" name="eskul_in" value="15:00"></div>
                <div class="form-group"><label>Eskul: Batas Telat</label><input type="time" name="eskul_late" value="15:15"></div>
                <div class="form-group"><label>Eskul: Jam Selesai / Pulang</label><input type="time" name="eskul_out" value="17:00"></div>
            </div>

            <div class="form-group full">
                <label>Status Template</label>
                <select name="is_active">
                    <option value="active">Aktif</option>
                    <option value="inactive">Nonaktif</option>
                </select>
            </div>

            <!-- ================================================================= -->
            <!-- PEMILIHAN SISWA PESERTA ESKUL (DENGAN FILTER TINGKAT, SUBKELAS & CARI) -->
            <!-- ================================================================= -->
            <div class="form-group full" id="sectionEskulStudents" style="<?= $initialType === 'eskul' ? '' : 'display:none;' ?>">
                <div style="background: #f0fdf4; border: 1px solid #86efac; padding: 18px; border-radius: 10px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                        <div>
                            <h4 style="margin:0; color:#166534;"><i class="fa-solid fa-users"></i> Pilih Murid Peserta Eskul Ini</h4>
                            <small style="color:#15803d;">Gunakan filter tingkat, subkelas, atau pencarian nama murid di bawah untuk memilih peserta eskul.</small>
                        </div>
                        <div>
                            <span id="selectedCounterBadge" style="background:#15803d; color:white; padding:5px 12px; border-radius:20px; font-size:12.5px; font-weight:bold;">
                                0 Siswa Terpilih
                            </span>
                        </div>
                    </div>

                    <!-- TOOLBAR FILTER SISWA LENGKAP -->
                    <div style="background:white; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <!-- Pencarian Nama / NIS -->
                        <div style="flex:2; min-width:180px;">
                            <input type="text" id="searchStudentInput" placeholder="🔍 Cari nama atau NIS murid..." onkeyup="filterStudentList()" style="width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12.5px;">
                        </div>

                        <!-- Filter Dropdown Tingkat -->
                        <div style="flex:1; min-width:130px;">
                            <select id="filterStudentGrade" onchange="filterStudentList()" style="width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12.5px;">
                                <option value="">Semua Tingkat</option>
                            </select>
                        </div>

                        <!-- Filter Dropdown Subkelas -->
                        <div style="flex:1; min-width:130px;">
                            <select id="filterStudentClass" onchange="filterStudentList()" style="width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12.5px;">
                                <option value="">Semua Subkelas</option>
                            </select>
                        </div>

                        <!-- Tombol Cepat -->
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="btn btn-sm btn-light" onclick="toggleSelectAllStudents(true)" style="padding:7px 12px; font-size:12px; font-weight:600; border:1px solid #cbd5e1;">Pilih Yang Tampil</button>
                            <button type="button" class="btn btn-sm btn-light" onclick="toggleSelectAllStudents(false)" style="padding:7px 12px; font-size:12px; font-weight:600; border:1px solid #cbd5e1;">Batal Yang Tampil</button>
                        </div>
                    </div>

                    <!-- CONTAINER GRID DAFTAR SISWA -->
                    <div id="studentListContainer" style="max-height: 320px; overflow-y: auto; background: white; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 8px;">
                        <p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 20px;">Silakan pilih Unit terlebih dahulu untuk memuat daftar murid.</p>
                    </div>
                </div>
            </div>

            <!-- ================================================================= -->
            <!-- PENUGASAN PERSONEL KHUSUS SHIFT STAFF (OPSIONAL)                  -->
            <!-- ================================================================= -->
            <div class="form-group full" id="sectionStaffAssignment" style="<?= $initialType === 'staff' ? '' : 'display:none;' ?>">
                <div style="background: #f0fdf4; border: 1px solid #86efac; padding: 18px; border-radius: 10px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                        <div>
                            <h4 style="margin:0; color:#166534;"><i class="fa-solid fa-user-shield"></i> Personel Khusus Shift Ini (Opsional)</h4>
                            <small style="color:#15803d;">
                                Jika tidak ada personel yang dicentang, shift ini berlaku umum untuk <strong>semua staff unit</strong>.<br>
                                Centang personel di bawah jika shift ini khusus (misal: Regu Security Shift 1 atau Shift 2) agar absensi otomatis terarah.
                            </small>
                        </div>
                        <div>
                            <span id="staffSelectedBadge" style="background:#15803d; color:white; padding:5px 12px; border-radius:20px; font-size:12.5px; font-weight:bold;">
                                0 Personel Terpilih
                            </span>
                        </div>
                    </div>

                    <!-- Filter & Cari Staff -->
                    <div style="background:white; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <div style="flex:2; min-width:180px;">
                            <input type="text" id="searchStaffInput" placeholder="🔍 Cari nama atau NIK staff/security..." onkeyup="filterStaffList()" style="width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12.5px;">
                        </div>
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="btn btn-sm btn-light" onclick="toggleSelectAllStaff(true)" style="padding:7px 12px; font-size:12px; font-weight:600; border:1px solid #cbd5e1;">Pilih Yang Tampil</button>
                            <button type="button" class="btn btn-sm btn-light" onclick="toggleSelectAllStaff(false)" style="padding:7px 12px; font-size:12px; font-weight:600; border:1px solid #cbd5e1;">Batal Yang Tampil</button>
                        </div>
                    </div>

                    <!-- Container Grid Staff -->
                    <div id="staffListContainer" style="max-height: 280px; overflow-y: auto; background: white; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 8px;">
                        <p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 20px;">Silakan pilih Unit terlebih dahulu untuk memuat daftar staff.</p>
                    </div>
                </div>
            </div>

        </div>

        <div style="margin-top:24px; display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700;">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Jadwal
            </button>
            <a href="index.php?tab=<?= e($initialType) ?>" class="btn btn-light" style="border: 1px solid #cbd5e1; padding: 10px 18px;">Batal</a>
        </div>
    </form>
</div>

<script>
const allGradesData = <?= json_encode($allGrades) ?>;
const allClassesData = <?= json_encode($allClasses) ?>;
let currentStudentsData = [];
let currentStaffData = [];

function switchScheduleMode(mode) {
    document.getElementById('inputScheduleMode').value = mode;

    const btnStu = document.getElementById('btnModeStudent');
    const btnStf = document.getElementById('btnModeStaff');
    const btnEsk = document.getElementById('btnModeEskul');

    btnStu.className = mode === 'student' ? 'btn btn-primary' : 'btn btn-light';
    btnStf.className = mode === 'staff' ? 'btn btn-primary' : 'btn btn-light';
    btnEsk.className = mode === 'eskul' ? 'btn btn-primary' : 'btn btn-light';

    document.getElementById('sectionStudentSchedule').style.display = (mode === 'student') ? 'block' : 'none';
    document.getElementById('sectionStaffSchedule').style.display = (mode === 'staff') ? 'block' : 'none';
    document.getElementById('sectionEskulSchedule').style.display = (mode === 'eskul') ? 'block' : 'none';

    document.getElementById('rowHoursStudent').style.display = (mode === 'student') ? 'contents' : 'none';
    document.getElementById('rowHoursStaff').style.display = (mode === 'staff') ? 'contents' : 'none';
    document.getElementById('rowHoursEskul').style.display = (mode === 'eskul') ? 'contents' : 'none';

    document.getElementById('sectionEskulStudents').style.display = (mode === 'eskul') ? 'block' : 'none';
    document.getElementById('sectionStaffAssignment').style.display = (mode === 'staff') ? 'block' : 'none';

    // Required toggles
    const inputEskulName = document.getElementById('inputEskulName');
    if (inputEskulName) inputEskulName.required = (mode === 'eskul');

    // Jika masuk ke eskul/staff dan unit sudah dipilih, muat data
    const unitId = getSelectedUnitId();
    if (mode === 'eskul' && currentStudentsData.length === 0 && unitId > 0) {
        loadStudentsByUnit(unitId);
    }
    if (mode === 'staff' && currentStaffData.length === 0 && unitId > 0) {
        loadStaffByUnit(unitId);
    }
}

function onStudentScopeChange(scope) {
    const rowG = document.getElementById('rowSelectGrade');
    const rowC = document.getElementById('rowSelectClass');

    if (scope === 'unit') {
        rowG.style.display = 'none';
        rowC.style.display = 'none';
    } else if (scope === 'grade') {
        rowG.style.display = 'block';
        rowC.style.display = 'none';
        populateStudentGrades();
    } else if (scope === 'class_group') {
        rowG.style.display = 'block';
        rowC.style.display = 'block';
        populateStudentGrades();
        populateStudentClasses();
    }
}

function getSelectedUnitId() {
    const el = document.getElementById('selectUnit');
    return el ? parseInt(el.value) || 0 : 0;
}

function populateStudentGrades() {
    const unitId = getSelectedUnitId();
    const selG = document.getElementById('selectStudentGrade');
    if (!selG) return;

    selG.innerHTML = '<option value="">-- Pilih Tingkat / Grade --</option>';
    const filtered = allGradesData.filter(g => !unitId || g.unit_id == unitId);
    filtered.forEach(g => {
        selG.innerHTML += `<option value="${g.id}">Tingkat ${g.grade} (${g.unit_name})</option>`;
    });
}

function onStudentGradeChange() {
    populateStudentClasses();
}

function populateStudentClasses() {
    const unitId = getSelectedUnitId();
    const gradeId = parseInt(document.getElementById('selectStudentGrade').value) || 0;
    const selC = document.getElementById('selectStudentClass');
    if (!selC) return;

    selC.innerHTML = '<option value="">-- Pilih Subkelas --</option>';
    const filtered = allClassesData.filter(c => {
        if (gradeId > 0) return c.grade_id == gradeId;
        if (unitId > 0) return c.unit_id == unitId;
        return true;
    });
    filtered.forEach(c => {
        selC.innerHTML += `<option value="${c.id}">${c.name}</option>`;
    });
}

function onUnitChange() {
    const unitId = getSelectedUnitId();
    populateStudentGrades();
    populateStudentClasses();

    if (!unitId) {
        document.getElementById('studentListContainer').innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 20px;">Silakan pilih Unit terlebih dahulu.</p>';
        document.getElementById('staffListContainer').innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 20px;">Silakan pilih Unit terlebih dahulu.</p>';
        return;
    }

    loadStudentsByUnit(unitId);
    loadStaffByUnit(unitId);
}

function loadStudentsByUnit(unitId) {
    if (!unitId) return;
    document.getElementById('studentListContainer').innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 20px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat daftar murid unit...</p>';

    fetch('get_students_by_unit.php?unit_id=' + encodeURIComponent(unitId))
        .then(res => res.json())
        .then(data => {
            currentStudentsData = data;
            buildEskulGradeClassFilters(data);
            renderStudentList(data);
        })
        .catch(err => {
            document.getElementById('studentListContainer').innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: red; padding: 20px;">Gagal memuat daftar murid.</p>';
        });
}

function loadStaffByUnit(unitId) {
    if (!unitId) return;
    document.getElementById('staffListContainer').innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 20px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat daftar staff unit...</p>';

    fetch('get_staff_by_unit.php?unit_id=' + encodeURIComponent(unitId))
        .then(res => res.json())
        .then(data => {
            currentStaffData = data;
            renderStaffList(data);
        })
        .catch(err => {
            document.getElementById('staffListContainer').innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: red; padding: 20px;">Gagal memuat daftar staff.</p>';
        });
}


function buildEskulGradeClassFilters(students) {
    const selG = document.getElementById('filterStudentGrade');
    const selC = document.getElementById('filterStudentClass');
    if (!selG || !selC) return;

    const gradesSet = new Map();
    const classesSet = new Map();

    students.forEach(s => {
        if (s.grade_id && s.grade) gradesSet.set(s.grade_id, s.grade);
        if (s.class_group_id && s.class_name) classesSet.set(s.class_group_id, s.class_name);
    });

    selG.innerHTML = '<option value="">Semua Tingkat</option>';
    gradesSet.forEach((val, key) => {
        selG.innerHTML += `<option value="${key}">Tingkat ${val}</option>`;
    });

    selC.innerHTML = '<option value="">Semua Subkelas</option>';
    classesSet.forEach((val, key) => {
        selC.innerHTML += `<option value="${key}">${val}</option>`;
    });
}

function renderStudentList(students) {
    const container = document.getElementById('studentListContainer');
    if (!students || students.length === 0) {
        container.innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 20px;">Tidak ada murid aktif di unit ini.</p>';
        updateCounter();
        return;
    }

    let html = '';
    students.forEach(s => {
        html += `
            <label class="student-item" style="display:flex; align-items:center; gap:8px; padding:6px 10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer;" 
                   data-name="${s.name.toLowerCase()}" 
                   data-nis="${(s.nis || '').toLowerCase()}" 
                   data-grade="${s.grade_id || ''}" 
                   data-class="${s.class_group_id || ''}">
                <input type="checkbox" name="student_ids[]" value="${s.id}" class="student-cb" onchange="updateCounter()">
                <div style="font-size:12px; line-height:1.2;">
                    <strong>${s.name}</strong><br>
                    <span style="color:#64748b; font-size:11px;">${s.class_name || (s.grade ? 'Tingkat ' + s.grade : '-')} | NIS: ${s.nis || '-'}</span>
                </div>
            </label>
        `;
    });
    container.innerHTML = html;
    updateCounter();
}

function filterStudentList() {
    const q = document.getElementById('searchStudentInput').value.toLowerCase().trim();
    const gFilter = document.getElementById('filterStudentGrade').value;
    const cFilter = document.getElementById('filterStudentClass').value;

    const items = document.querySelectorAll('.student-item');
    items.forEach(el => {
        const name = el.getAttribute('data-name');
        const nis = el.getAttribute('data-nis');
        const grade = el.getAttribute('data-grade');
        const cls = el.getAttribute('data-class');

        const matchQ = !q || name.includes(q) || nis.includes(q);
        const matchG = !gFilter || grade === gFilter;
        const matchC = !cFilter || cls === cFilter;

        if (matchQ && matchG && matchC) {
            el.style.display = 'flex';
        } else {
            el.style.display = 'none';
        }
    });
}

function toggleSelectAllStudents(select) {
    const checkboxes = document.querySelectorAll('.student-cb');
    checkboxes.forEach(cb => {
        const parent = cb.closest('.student-item');
        if (parent && parent.style.display !== 'none') {
            cb.checked = select;
        }
    });
    updateCounter();
}

function updateCounter() {
    const checked = document.querySelectorAll('.student-cb:checked').length;
    document.getElementById('selectedCounterBadge').innerText = checked + ' Siswa Terpilih';
}

function renderStaffList(staff) {
    const container = document.getElementById('staffListContainer');
    if (!staff || staff.length === 0) {
        container.innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 20px;">Tidak ada staff aktif di unit ini.</p>';
        updateStaffCounter();
        return;
    }

    let html = '';
    staff.forEach(s => {
        html += `
            <label class="staff-item" style="display:flex; align-items:center; gap:8px; padding:6px 10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer;" 
                   data-name="${s.name.toLowerCase()}" 
                   data-nik="${(s.nik || '').toLowerCase()}">
                <input type="checkbox" name="staff_ids[]" value="${s.id}" class="staff-cb" onchange="updateStaffCounter()">
                <div style="font-size:12px; line-height:1.2;">
                    <strong>${s.name}</strong><br>
                    <span style="color:#64748b; font-size:11px;">NIK: ${s.nik || '-'}</span>
                </div>
            </label>
        `;
    });
    container.innerHTML = html;
    updateStaffCounter();
}

function filterStaffList() {
    const q = document.getElementById('searchStaffInput').value.toLowerCase().trim();
    const items = document.querySelectorAll('.staff-item');
    items.forEach(el => {
        const name = el.getAttribute('data-name');
        const nik = el.getAttribute('data-nik');
        if (!q || name.includes(q) || nik.includes(q)) {
            el.style.display = 'flex';
        } else {
            el.style.display = 'none';
        }
    });
}

function toggleSelectAllStaff(select) {
    const checkboxes = document.querySelectorAll('.staff-cb');
    checkboxes.forEach(cb => {
        const parent = cb.closest('.staff-item');
        if (parent && parent.style.display !== 'none') {
            cb.checked = select;
        }
    });
    updateStaffCounter();
}

function updateStaffCounter() {
    const badge = document.getElementById('staffSelectedBadge');
    if (!badge) return;
    const checked = document.querySelectorAll('.staff-cb:checked').length;
    badge.innerText = checked + ' Personel Terpilih';
}

window.addEventListener('DOMContentLoaded', () => {
    const unitId = getSelectedUnitId();
    if (unitId > 0) {
        onUnitChange();
    }
});
</script>

<?php require '../../includes/footer.php'; ?>

