<?php
error_reporting(0); // Matikan tampilan error default agar tidak merusak format JSON
session_start();
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../includes/functions.php';
require_once '../includes/whatsapp.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid Method']); exit;
}
$code = trim($_POST['code'] ?? '');
if ($code === '') {
    echo json_encode(['status' => 'error', 'msg' => 'Kode Kosong']); exit;
}

$timeNow = date('H:i:s');
$dateToday = date('Y-m-d');
$waktuSekarangDetik = strtotime($timeNow);
$dayCode = (int)date('N'); 
$now = time();

// FUNGSI AUTO-ALPHA (Dukungan kontrol pengecualian & unit shift malam)
function runAutoAlpha($pdo, $unitId, $dateToday, $activityId, $excludeStudentId = 0, $excludeStaffId = 0) {
    try {
        if (function_exists('isAutoAttendanceDisabled') && isAutoAttendanceDisabled($pdo, (int)$unitId)) {
            return;
        }

        // Siswa yang belum absen hari ini di unit ini
        $excludeStuSql = ($excludeStudentId > 0) ? " AND s.id != " . (int)$excludeStudentId : "";
        $stmtSiswa = $pdo->prepare("
            SELECT s.id, se.id as enrollment_id, cg.id as class_group_id, g.id as grade_id
            FROM students s
            INNER JOIN student_enrollments se ON se.student_id = s.id
            INNER JOIN class_groups cg ON cg.id = se.class_group_id
            INNER JOIN grades g ON g.id = cg.grade_id
            WHERE s.deleted_at IS NULL AND se.status = 'active' AND g.unit_id = ?
            {$excludeStuSql}
            AND NOT EXISTS (SELECT 1 FROM student_attendances sa WHERE sa.student_id = s.id AND sa.attendance_date = ?)
        ");
        $stmtSiswa->execute([$unitId, $dateToday]);
        $unmarkedStudents = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);

        $insSiswa = $pdo->prepare("INSERT IGNORE INTO student_attendances (student_id, enrollment_id, activity_id, attendance_date, status, keterangan) VALUES (?, ?, ?, ?, 'terlambat', 'Otomatis (Belum Absen Masuk)')");
        foreach ($unmarkedStudents as $st) {
            if (function_exists('isAutoAttendanceDisabled') && isAutoAttendanceDisabled($pdo, (int)$unitId, (int)$st['grade_id'], (int)$st['class_group_id'], (int)$st['id'])) {
                continue;
            }
            $insSiswa->execute([(int)$st['id'], (int)$st['enrollment_id'], $activityId, $dateToday]);
        }

        // Cek apakah ini unit shift malam (misal Security)
        $dayCode = (int)date('N');
        $stmtSecCheck = $pdo->prepare("SELECT is_overnight, staff_in FROM weekly_schedules WHERE unit_id = ? AND day_code = ? LIMIT 1");
        $stmtSecCheck->execute([$unitId, $dayCode]);
        $secSched = $stmtSecCheck->fetch(PDO::FETCH_ASSOC);

        $isOvernight = !empty($secSched['is_overnight']) || ($secSched && $secSched['staff_in'] >= '17:00:00');
        $timeNow = date('H:i:s');

        // Jika unit shift malam dan saat ini masih siang/sore (sebelum jam dinas malam), jangan tandai alpha!
        if ($isOvernight && $timeNow < ($secSched['staff_in'] ?? '18:00:00')) {
            return;
        }

        // Staff yang belum absen hari ini di unit ini
        $excludeStfSql = ($excludeStaffId > 0) ? " AND st.id != " . (int)$excludeStaffId : "";
        $stmtStaff = $pdo->prepare("
            SELECT st.id
            FROM staff st
            WHERE st.deleted_at IS NULL AND st.unit_id = ?
            {$excludeStfSql}
            AND NOT EXISTS (SELECT 1 FROM staff_attendances sta WHERE sta.staff_id = st.id AND sta.attendance_date = ?)
        ");
        $stmtStaff->execute([$unitId, $dateToday]);
        $unmarkedStaff = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);

        $insStaff = $pdo->prepare("INSERT IGNORE INTO staff_attendances (staff_id, activity_id, attendance_date, status, keterangan) VALUES (?, ?, ?, 'terlambat', 'Otomatis (Belum Absen Masuk)')");
        foreach ($unmarkedStaff as $stf) {
            if (function_exists('isAutoAttendanceDisabled') && isAutoAttendanceDisabled($pdo, (int)$unitId, null, null, null, (int)$stf['id'])) {
                continue;
            }
            $insStaff->execute([(int)$stf['id'], $activityId, $dateToday]);
        }
    } catch(Exception $e) {}
}

// FUNGSI MENGAMBIL JADWAL AKTIVITAS (MEMBEDAKAN SISWA VS STAFF)
if (!function_exists('getDailyActivity')) {
    function getDailyActivity($pdo, $unitId, $dateToday, $dayCode, $targetType = 'student') {
        $stmt = $pdo->prepare("
            SELECT * FROM activities 
            WHERE activity_date = ? AND unit_id = ? 
              AND (target_type = ? OR target_type = 'all' OR target_type IS NULL) 
            ORDER BY (target_type = ?) DESC, id ASC 
            LIMIT 1
        ");
        $stmt->execute([$dateToday, $unitId, $targetType, $targetType]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activity) {
            $stmtWeek = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE day_code = ? AND unit_id = ? AND is_active = 'active' 
                  AND (target_type = ? OR target_type IS NULL) 
                  AND (schedule_type = 'reguler' OR schedule_type IS NULL) 
                ORDER BY (grade_id IS NULL AND class_group_id IS NULL) DESC, id ASC 
                LIMIT 1
            ");
            $stmtWeek->execute([$dayCode, $unitId, $targetType]);
            $weekly = $stmtWeek->fetch(PDO::FETCH_ASSOC);

            // Fallback 1: Jika staff tapi belum ada target_type = 'staff', cari jadwal legacy dengan staff_in valid
            if (!$weekly && $targetType === 'staff') {
                $stmtFallback = $pdo->prepare("
                    SELECT * FROM weekly_schedules 
                    WHERE day_code = ? AND unit_id = ? AND is_active = 'active' 
                      AND (staff_in IS NOT NULL AND staff_in != '00:00:00')
                    ORDER BY id ASC LIMIT 1
                ");
                $stmtFallback->execute([$dayCode, $unitId]);
                $weekly = $stmtFallback->fetch(PDO::FETCH_ASSOC);
            }

            // Fallback 2: Jika masih kosong untuk staff, ambil template hari lain di unit yang sama atau default jam kerja
            if (!$weekly && $targetType === 'staff') {
                $stmtAny = $pdo->prepare("
                    SELECT * FROM weekly_schedules 
                    WHERE unit_id = ? AND is_active = 'active' 
                      AND (target_type = 'staff' OR (staff_in IS NOT NULL AND staff_in != '00:00:00'))
                    ORDER BY id ASC LIMIT 1
                ");
                $stmtAny->execute([$unitId]);
                $anyStaff = $stmtAny->fetch(PDO::FETCH_ASSOC);

                $weekly = [
                    'student_in'   => '00:00:00',
                    'student_late' => '00:00:00',
                    'student_out'  => '00:00:00',
                    'staff_in'     => $anyStaff['staff_in'] ?? '06:45:00',
                    'staff_late'   => $anyStaff['staff_late'] ?? '07:00:00',
                    'staff_out'    => $anyStaff['staff_out'] ?? '15:30:00',
                    'name'         => 'Jadwal Reguler Staff'
                ];
            }
            
            if ($weekly) {
                $stmtAy = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1");
                $ay = $stmtAy->fetch(PDO::FETCH_ASSOC);
                $ayId = $ay ? $ay['id'] : 1;

                $actName = ($targetType === 'staff') ? "Jadwal Reguler Staff & Guru" : "Jadwal Reguler Siswa";

                $insertAct = $pdo->prepare("
                    INSERT INTO activities 
                    (academic_year_id, unit_id, target_type, name, activity_date, student_in, student_late, student_out, staff_in, staff_late, staff_out, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $insertAct->execute([
                    $ayId, $unitId, $targetType, $actName, $dateToday, 
                    $weekly['student_in'] ?? '00:00:00', $weekly['student_late'] ?? '00:00:00', $weekly['student_out'] ?? '00:00:00', 
                    $weekly['staff_in'] ?? '06:45:00', $weekly['staff_late'] ?? '07:00:00', $weekly['staff_out'] ?? '15:30:00'
                ]);
                $stmt->execute([$dateToday, $unitId, $targetType, $targetType]);
                $activity = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        return $activity;
    }
}

$responseData = [];

// ======================================================================
// BUNGKUS DENGAN TRY CATCH AGAR JIKA ERROR, POP UP ERROR MUNCUL DI LAYAR
// ======================================================================
try {
    
    /* --------------------------------------------------------------------------
       PROSES SCAN SISWA
    -------------------------------------------------------------------------- */
    $stmtStudent = $pdo->prepare("
        SELECT s.id, s.name, s.nis, s.nik, s.photo, se.id AS enrollment_id, cg.name AS class_name, g.grade, g.unit_id
        FROM students s
        INNER JOIN student_enrollments se ON se.student_id = s.id
        INNER JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
        INNER JOIN class_groups cg ON cg.id = se.class_group_id
        INNER JOIN grades g ON g.id = cg.grade_id
        WHERE s.deleted_at IS NULL AND se.status = 'active' AND (s.nis = ? OR s.nik = ? OR s.rfid_uid = ? OR (s.fingerprint_id = ? AND ? > 0)) LIMIT 1
    ");
    $fingerInt = (is_numeric($code) && (int)$code > 0) ? (int)$code : 0;
    $stmtStudent->execute([$code, $code, $code, $fingerInt, $fingerInt]);
    $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $activity = getDailyActivity($pdo, $student['unit_id'], $dateToday, $dayCode, 'student');
        
        // Ambil jadwal efektif siswa (KBM reguler + Eskul / kegiatan)
        $effSched = function_exists('getStudentEffectiveSchedule') 
            ? getStudentEffectiveSchedule($pdo, (int)$student['id'], (int)$student['unit_id'], $dateToday, $dayCode)
            : ['has_schedule' => false];

        $studentInTime = ($effSched['has_schedule'] && !empty($effSched['student_in'])) ? $effSched['student_in'] : ($activity['student_in'] ?? null);
        $studentLateTime = ($effSched['has_schedule'] && !empty($effSched['student_late'])) ? $effSched['student_late'] : ($activity['student_late'] ?? null);
        $studentOutTime = ($effSched['has_schedule'] && !empty($effSched['student_out'])) ? $effSched['student_out'] : ($activity['student_out'] ?? null);

        if (!$activity || $activity['status'] !== 'active' || empty($studentInTime) || empty($studentOutTime)) { 
            $responseData = ['type'=>'student', 'name'=>$student['name'], 'nis'=>$student['nis'], 'class_name'=>$student['class_name'], 'photo'=>$student['photo'], 'time'=>$timeNow, 'badge_text'=>'ℹ TIDAK ADA JADWAL', 'badge_class'=>'late', 'already'=>true, 'message'=>'Hari Ini Tidak Ada Jadwal'];
        } else {
            $jamMasukDetik = strtotime($studentInTime);
            $jamPulangDetik = strtotime($studentOutTime);
            
            $bukaMasuk = strtotime('-2 hours', $jamMasukDetik);
            $tutupMasuk = strtotime('+3 hours', $jamMasukDetik);
            $bukaPulang = strtotime('+3 hours', $jamMasukDetik);
            $tutupPulang = strtotime('+7 hours', $jamPulangDetik);

            if ($waktuSekarangDetik < $bukaMasuk) {
                $responseData = ['type'=>'student', 'name'=>$student['name'], 'nis'=>$student['nis'], 'class_name'=>$student['class_name'], 'photo'=>$student['photo'], 'time'=>$timeNow, 'badge_text'=>'✓ KEPAGIAN', 'badge_class'=>'ontime', 'scan_type'=>'masuk', 'already'=>true, 'message'=>'Belum waktunya absen masuk!'];
            } elseif ($waktuSekarangDetik > $tutupPulang) {
                $responseData = ['type'=>'student', 'name'=>$student['name'], 'nis'=>$student['nis'], 'class_name'=>$student['class_name'], 'photo'=>$student['photo'], 'time'=>$timeNow, 'badge_text'=>'⚠ SESI HABIS', 'badge_class'=>'late', 'scan_type'=>'pulang', 'already'=>true, 'message'=>'Sesi absensi hari ini sudah ditutup.'];
            } else {
                $message = ''; $scanType = ''; $badgeText = ''; $badgeClass = '';

                $check = $pdo->prepare("SELECT id, time_in, time_out, status FROM student_attendances WHERE student_id = ? AND attendance_date = ? LIMIT 1");
                $check->execute([$student['id'], $dateToday]);
                $record = $check->fetch(PDO::FETCH_ASSOC);
                
                $timeInEmpty = !$record || empty($record['time_in']) || $record['time_in'] === '00:00:00';
                $timeOutEmpty = !$record || empty($record['time_out']) || $record['time_out'] === '00:00:00';
                $isAutoAlpha = $record && $timeInEmpty && $timeOutEmpty; 

                if ($waktuSekarangDetik <= $tutupMasuk) {
                    // === ABSEN MASUK ===
                    $scanType = 'masuk';
                    $statusKehadiran = ($timeNow > $studentLateTime) ? 'terlambat' : 'tepat_waktu';
                    $badgeText = ($statusKehadiran === 'tepat_waktu') ? '✓ TEPAT WAKTU' : '⚠ TERLAMBAT';
                    $badgeClass = ($statusKehadiran === 'tepat_waktu') ? 'ontime' : 'late';

                    $ketMasuk = $effSched['has_eskul'] ? ('Termasuk Eskul: ' . implode(', ', $effSched['eskul_names'])) : null;

                    if (!$record) {
                        $pdo->prepare("INSERT INTO student_attendances (student_id, enrollment_id, activity_id, attendance_date, time_in, scan_code, status, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$student['id'], $student['enrollment_id'], $activity['id'], $dateToday, $timeNow, $code, $statusKehadiran, $ketMasuk]);
                    } elseif ($isAutoAlpha || $timeInEmpty) {
                        $pdo->prepare("UPDATE student_attendances SET time_in = ?, scan_code = ?, status = ?, keterangan = ? WHERE id = ?")
                            ->execute([$timeNow, $code, $statusKehadiran, $ketMasuk, $record['id']]);
                    } else {
                        $message = 'Anda sudah absen masuk hari ini.';
                    }

                    // Kirim Notifikasi WhatsApp Masuk (Pesan 1)
                    if ($message === '') {
                        try {
                            sendStudentWaNotification($pdo, (int)$student['id'], 'in', [
                                'jam_absen'        => date('H:i', strtotime($timeNow)),
                                'status_kehadiran' => $statusKehadiran,
                                'jam_batas'        => substr($studentLateTime, 0, 5)
                            ]);
                        } catch (Throwable $e) {}
                    }
                } else {
                    // === ABSEN PULANG ===
                    $scanType = 'pulang';
                    $selisihPulang = $waktuSekarangDetik - $jamPulangDetik; 

                    if ($selisihPulang < 0) { 
                        $badgeText = '⚠ PULANG CEPAT'; 
                        $badgeClass = 'late'; 
                        if ($effSched['has_eskul']) {
                            $message = 'Perhatian: Siswa terdaftar pada eskul (' . implode(', ', $effSched['eskul_names']) . ') s.d. ' . substr($studentOutTime, 0, 5);
                        }
                    } elseif ($selisihPulang >= (1 * 3600)) { 
                        $badgeText = '⚠ PULANG TERLALU LAMA'; 
                        $badgeClass = 'late'; 
                    } else { 
                        $badgeText = '✓ TEPAT WAKTU'; 
                        $badgeClass = 'ontime'; 
                    }

                    runAutoAlpha($pdo, $student['unit_id'], $dateToday, $activity['id'], (int)$student['id'], 0);

                    // Re-sync record status to prevent any race condition
                    $check->execute([$student['id'], $dateToday]);
                    $record = $check->fetch(PDO::FETCH_ASSOC);
                    $timeInEmpty = !$record || empty($record['time_in']) || $record['time_in'] === '00:00:00';
                    $timeOutEmpty = !$record || empty($record['time_out']) || $record['time_out'] === '00:00:00';
                    $isAutoAlpha = $record && $timeInEmpty && $timeOutEmpty;

                    if (!$record || $isAutoAlpha) {
                        $ketPulang = 'Hanya scan pulang' . ($effSched['has_eskul'] ? (' (Eskul: ' . implode(', ', $effSched['eskul_names']) . ')') : '');
                        if (!$record) {
                            $pdo->prepare("
                                INSERT INTO student_attendances 
                                (student_id, enrollment_id, activity_id, attendance_date, time_in, time_out, scan_code, status, keterangan) 
                                VALUES (?, ?, ?, ?, NULL, ?, ?, 'terlambat', ?)
                                ON DUPLICATE KEY UPDATE time_out = VALUES(time_out), scan_code = VALUES(scan_code), status = 'terlambat', keterangan = VALUES(keterangan)
                            ")->execute([$student['id'], $student['enrollment_id'], $activity['id'], $dateToday, $timeNow, $code, $ketPulang]);
                        } else {
                            $pdo->prepare("UPDATE student_attendances SET time_out = ?, scan_code = ?, status = 'terlambat', keterangan = ? WHERE id = ?")
                                ->execute([$timeNow, $code, $ketPulang, $record['id']]);
                        }
                        if (empty($message)) {
                            $message = 'Anda tidak absen pagi, namun absen pulang dicatat.';
                        }
                    } elseif ($timeOutEmpty) {
                        $selisihMenitMasuk = round(abs($waktuSekarangDetik - ($timeInEmpty ? 0 : strtotime($record['time_in']))) / 60);
                        if (!$timeInEmpty && $selisihMenitMasuk < 30) {
                            $message = 'Beri jeda minimal 30 menit dari absen masuk.';
                        } elseif ($waktuSekarangDetik < $bukaPulang) {
                            $message = 'Belum waktunya absen pulang.';
                        } else {
                            $pdo->prepare("UPDATE student_attendances SET time_out = ? WHERE id = ?")->execute([$timeNow, $record['id']]);
                        }
                    } else {
                        $message = 'Anda sudah berhasil absen pulang hari ini.';
                    }

                    // Kirim Notifikasi WhatsApp Pulang (Pesan 3) jika scan pulang berhasil dicatat
                    if ($message === '' || strpos($message, 'namun absen pulang dicatat') !== false) {
                        try {
                            sendStudentWaNotification($pdo, (int)$student['id'], 'out', [
                                'jam_absen'  => date('H:i', strtotime($timeNow)),
                                'jam_pulang' => substr($studentOutTime, 0, 5)
                            ]);
                        } catch (Throwable $e) {}
                    }
                }
                $responseData = ['type'=>'student', 'name'=>$student['name'], 'nis'=>$student['nis'], 'class_name'=>$student['class_name'], 'photo'=>$student['photo'], 'time'=>$timeNow, 'badge_text'=>$badgeText, 'badge_class'=>$badgeClass, 'scan_type'=>$scanType];
                if ($message !== '') { $responseData['already'] = true; $responseData['message'] = $message; }
            }
        }
    }
    
    /* --------------------------------------------------------------------------
       PROSES SCAN STAFF (TERMASUK DUKUNGAN SHIFT MALAM SECURITY LINTAS HARI)
    -------------------------------------------------------------------------- */
    else {
        $stmtStaff = $pdo->prepare("
            SELECT id, name, nik, photo, unit_id 
            FROM staff 
            WHERE (nik = ? OR rfid_uid = ? OR (fingerprint_id = ? AND ? > 0)) AND deleted_at IS NULL 
            LIMIT 1
        ");
        $stmtStaff->execute([$code, $code, $fingerInt, $fingerInt]);
        $staff = $stmtStaff->fetch(PDO::FETCH_ASSOC);

        if ($staff) {
            $staffId = (int)$staff['id'];
            $staffUnitId = (int)$staff['unit_id'];

            $activity = getDailyActivity($pdo, $staffUnitId, $dateToday, $dayCode, 'staff');
            $staffShift = function_exists('getStaffShiftSchedule')
                ? getStaffShiftSchedule($pdo, $staffId, $staffUnitId, $dayCode, $timeNow)
                : ['has_schedule' => false, 'is_overnight' => false];

            $isOvernight = !empty($staffShift['is_overnight']);

            // ==================================================================
            // ALGORITMA KHUSUS: UNIT SECURITY / SHIFT MALAM CROSS-MIDNIGHT (18:00 - 06:00)
            // ==================================================================
            if ($isOvernight) {
                $yesterday = date('Y-m-d', strtotime('-1 day'));

                // Jika scan dilakukan pada pagi hari (04:00 - 11:30): Ini adalah kepulangan shift malam kemarin!
                if ($waktuSekarangDetik >= strtotime('04:00:00') && $waktuSekarangDetik <= strtotime('11:30:00')) {
                    $checkYest = $pdo->prepare("
                        SELECT id, activity_id, time_in, time_out, status 
                        FROM staff_attendances 
                        WHERE staff_id = ? AND attendance_date = ? 
                        LIMIT 1
                    ");
                    $checkYest->execute([$staffId, $yesterday]);
                    $yestRecord = $checkYest->fetch(PDO::FETCH_ASSOC);

                    $targetJamPulangMalam = strtotime($staffShift['staff_out'] ?? '06:00:00');
                    $selisihPulangMalam = $waktuSekarangDetik - $targetJamPulangMalam;

                    $isLembur = false;
                    $lemburMsg = '';
                    $badgeText = '✓ PULANG SHIFT MALAM';
                    $badgeClass = 'ontime';

                    if ($selisihPulangMalam < -1800) { // Lebih dari 30 menit sebelum jam 06:00
                        $badgeText = '⚠ PULANG LEBIH AWAL';
                        $badgeClass = 'late';
                    } elseif ($selisihPulangMalam >= 3600) { // Lembur 1 jam atau lebih setelah jam 06:00
                        $isLembur = true;
                        $lemburMsg = 'Sesi lembur shift malam telah tercatat.';
                        $badgeText = '⏱ LEMBUR SHIFT MALAM';
                        $badgeClass = 'ontime';
                    }

                    if ($yestRecord && (empty($yestRecord['time_out']) || $yestRecord['time_out'] === '00:00:00')) {
                        // Catat kepulangan untuk shift kemarin
                        $pdo->prepare("UPDATE staff_attendances SET time_out = ?, scan_code = ? WHERE id = ?")
                            ->execute([$timeNow, $code, $yestRecord['id']]);

                        if ($isLembur) {
                            $pdo->prepare("INSERT INTO staff_overtimes (staff_id, activity_id, overtime_date, time_out) VALUES (?, ?, ?, ?)")
                                ->execute([$staffId, $yestRecord['activity_id'], $yesterday, $timeNow]);
                        }

                        $responseData = [
                            'type'        => 'staff',
                            'name'        => $staff['name'],
                            'nik'         => $staff['nik'],
                            'photo'       => $staff['photo'],
                            'time'        => $timeNow,
                            'badge_text'  => $badgeText,
                            'badge_class' => $badgeClass,
                            'scan_type'   => 'pulang',
                            'is_lembur'   => $isLembur,
                            'lembur_msg'  => $lemburMsg,
                            'message'     => "Absen pulang shift malam kemarin ({$yesterday}) berhasil dicatat."
                        ];
                    } elseif ($yestRecord && !empty($yestRecord['time_out']) && $yestRecord['time_out'] !== '00:00:00') {
                        $responseData = [
                            'type'        => 'staff',
                            'name'        => $staff['name'],
                            'nik'         => $staff['nik'],
                            'photo'       => $staff['photo'],
                            'time'        => $timeNow,
                            'badge_text'  => '✓ SUDAH PULANG',
                            'badge_class' => 'ontime',
                            'scan_type'   => 'pulang',
                            'already'     => true,
                            'message'     => "Anda sudah absen pulang shift malam sebelumnya."
                        ];
                    } else {
                        // Tidak ada scan masuk tadi malam, namun scan pulang pagi ini dicatat
                        $actId = $activity ? $activity['id'] : 1;
                        $pdo->prepare("INSERT INTO staff_attendances (staff_id, activity_id, attendance_date, time_in, time_out, scan_code, status, keterangan) VALUES (?, ?, ?, NULL, ?, ?, 'terlambat', 'Hanya scan pulang shift malam')")
                            ->execute([$staffId, $actId, $yesterday, $timeNow, $code]);

                        $responseData = [
                            'type'        => 'staff',
                            'name'        => $staff['name'],
                            'nik'         => $staff['nik'],
                            'photo'       => $staff['photo'],
                            'time'        => $timeNow,
                            'badge_text'  => '⚠ PULANG SHIFT MALAM',
                            'badge_class' => 'late',
                            'scan_type'   => 'pulang',
                            'already'     => true,
                            'message'     => "Tidak tercatat absen masuk malam, absensi pulang shift malam telah dicatat."
                        ];
                    }
                } 
                // Scan pada sore / malam hari (mulai jam 16:00 ke atas): Ini adalah MASUK shift malam hari ini!
                elseif ($waktuSekarangDetik >= strtotime('16:00:00')) {
                    $jamMasukMalam = $staffShift['staff_in'] ?? '18:00:00';
                    $jamTelatMalam = $staffShift['staff_late'] ?? '18:30:00';

                    $statusKehadiran = ($timeNow > $jamTelatMalam) ? 'terlambat' : 'tepat_waktu';
                    $badgeText = ($statusKehadiran === 'tepat_waktu') ? '✓ MASUK SHIFT MALAM' : '⚠ TERLAMBAT SHIFT MALAM';
                    $badgeClass = ($statusKehadiran === 'tepat_waktu') ? 'ontime' : 'late';

                    $checkToday = $pdo->prepare("SELECT id, time_in, time_out, status FROM staff_attendances WHERE staff_id = ? AND attendance_date = ? LIMIT 1");
                    $checkToday->execute([$staffId, $dateToday]);
                    $recToday = $checkToday->fetch(PDO::FETCH_ASSOC);

                    $actId = $activity ? $activity['id'] : 1;

                    if (!$recToday) {
                        $pdo->prepare("INSERT INTO staff_attendances (staff_id, activity_id, attendance_date, time_in, scan_code, status, keterangan) VALUES (?, ?, ?, ?, ?, ?, 'Shift Malam')")
                            ->execute([$staffId, $actId, $dateToday, $timeNow, $code, $statusKehadiran]);
                        $message = '';
                    } elseif (empty($recToday['time_in']) || $recToday['time_in'] === '00:00:00') {
                        $pdo->prepare("UPDATE staff_attendances SET time_in = ?, scan_code = ?, status = ?, keterangan = 'Shift Malam' WHERE id = ?")
                            ->execute([$timeNow, $code, $statusKehadiran, $recToday['id']]);
                        $message = '';
                    } else {
                        $message = 'Anda sudah absen masuk shift malam hari ini.';
                    }

                    $responseData = [
                        'type'        => 'staff',
                        'name'        => $staff['name'],
                        'nik'         => $staff['nik'],
                        'photo'       => $staff['photo'],
                        'time'        => $timeNow,
                        'badge_text'  => $badgeText,
                        'badge_class' => $badgeClass,
                        'scan_type'   => 'masuk'
                    ];
                    if ($message !== '') {
                        $responseData['already'] = true;
                        $responseData['message'] = $message;
                    }
                } else {
                    $responseData = [
                        'type'        => 'staff',
                        'name'        => $staff['name'],
                        'nik'         => $staff['nik'],
                        'photo'       => $staff['photo'],
                        'time'        => $timeNow,
                        'badge_text'  => 'ℹ DILUAR JADWAL',
                        'badge_class' => 'late',
                        'already'     => true,
                        'message'     => 'Bukan jam shift malam (Jadwal: 18:00 - 06:00).'
                    ];
                }
            } 
            // ==================================================================
            // FLOW STANDARD STAFF REGULER (SIANG)
            // ==================================================================
            else {
                if (!$activity || $activity['status'] !== 'active') { 
                    $responseData = ['type'=>'staff', 'name'=>$staff['name'], 'nik'=>$staff['nik'], 'photo'=>$staff['photo'], 'time'=>$timeNow, 'badge_text'=>'ℹ TIDAK ADA JADWAL', 'badge_class'=>'late', 'already'=>true, 'message'=>'Hari Ini Tidak Ada Jadwal'];
                } else {
                    $jamMasukStr = $staffShift['staff_in'] ?? $activity['staff_in'];
                    $jamPulangStr = $staffShift['staff_out'] ?? $activity['staff_out'];
                    $jamTelatStr = $staffShift['staff_late'] ?? $activity['staff_late'];

                    $jamMasukDetik = strtotime($jamMasukStr);
                    $jamPulangDetik = strtotime($jamPulangStr);
                    $bukaMasuk = strtotime('-2 hours', $jamMasukDetik);
                    $tutupMasuk = strtotime('+3 hours', $jamMasukDetik); 
                    $bukaPulang = strtotime('+3 hours', $jamMasukDetik);
                    $tutupPulang = strtotime('+7 hours', $jamPulangDetik);

                    if ($waktuSekarangDetik < $bukaMasuk) {
                        $responseData = ['type'=>'staff', 'name'=>$staff['name'], 'nik'=>$staff['nik'], 'photo'=>$staff['photo'], 'time'=>$timeNow, 'badge_text'=>'✓ KEPAGIAN', 'badge_class'=>'ontime', 'scan_type'=>'masuk', 'already'=>true, 'message'=>'Belum waktunya absen masuk!'];
                    } elseif ($waktuSekarangDetik > $tutupPulang) {
                        $responseData = ['type'=>'staff', 'name'=>$staff['name'], 'nik'=>$staff['nik'], 'photo'=>$staff['photo'], 'time'=>$timeNow, 'badge_text'=>'⚠ SESI HABIS', 'badge_class'=>'late', 'scan_type'=>'pulang', 'already'=>true, 'message'=>'Sesi absensi hari ini sudah ditutup.'];
                    } else {
                        $message = ''; $scanType = ''; $badgeText = ''; $badgeClass = ''; $isLembur = false; $lemburMsg = '';
                        
                        $check = $pdo->prepare("SELECT id, time_in, time_out, status FROM staff_attendances WHERE staff_id = ? AND attendance_date = ? LIMIT 1");
                        $check->execute([$staff['id'], $dateToday]);
                        $record = $check->fetch(PDO::FETCH_ASSOC);

                        $timeInEmpty = !$record || empty($record['time_in']) || $record['time_in'] === '00:00:00';
                        $timeOutEmpty = !$record || empty($record['time_out']) || $record['time_out'] === '00:00:00';
                        $isAutoAlpha = $record && $timeInEmpty && $timeOutEmpty; 

                        if ($waktuSekarangDetik <= $tutupMasuk) {
                            $scanType = 'masuk';
                            $statusKehadiran = ($timeNow > $jamTelatStr) ? 'terlambat' : 'tepat_waktu';
                            $badgeText = ($statusKehadiran === 'tepat_waktu') ? '✓ TEPAT WAKTU' : '⚠ TERLAMBAT';
                            $badgeClass = ($statusKehadiran === 'tepat_waktu') ? 'ontime' : 'late';
                            
                            if (!$record) {
                                $pdo->prepare("
                                    INSERT INTO staff_attendances (staff_id, activity_id, attendance_date, time_in, scan_code, status) 
                                    VALUES (?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), scan_code = VALUES(scan_code), status = VALUES(status), keterangan = NULL
                                ")->execute([$staff['id'], $activity['id'], $dateToday, $timeNow, $code, $statusKehadiran]);
                            } elseif ($isAutoAlpha || $timeInEmpty) {
                                $pdo->prepare("UPDATE staff_attendances SET time_in = ?, scan_code = ?, status = ?, keterangan = NULL WHERE id = ?")
                                    ->execute([$timeNow, $code, $statusKehadiran, $record['id']]);
                            } else {
                                $message = 'Anda sudah absen masuk hari ini.'; 
                            }
                        } else {
                            $scanType = 'pulang';
                            $selisihPulang = $waktuSekarangDetik - $jamPulangDetik; 

                            if ($selisihPulang < 0) { $badgeText = '⚠ PULANG CEPAT'; $badgeClass = 'late'; }
                            elseif ($selisihPulang >= (1 * 3600)) { $badgeText = '⏱ LEMBUR YAA'; $badgeClass = 'ontime'; }
                            else { $badgeText = '✓ TEPAT WAKTU'; $badgeClass = 'ontime'; }

                            runAutoAlpha($pdo, $staff['unit_id'], $dateToday, $activity['id'], 0, (int)$staff['id']);

                            // Re-sync record status to prevent any race condition
                            $check->execute([$staff['id'], $dateToday]);
                            $record = $check->fetch(PDO::FETCH_ASSOC);
                            $timeInEmpty = !$record || empty($record['time_in']) || $record['time_in'] === '00:00:00';
                            $timeOutEmpty = !$record || empty($record['time_out']) || $record['time_out'] === '00:00:00';
                            $isAutoAlpha = $record && $timeInEmpty && $timeOutEmpty;

                            if (!$record || $isAutoAlpha) {
                                if (!$record) {
                                    $pdo->prepare("
                                        INSERT INTO staff_attendances (staff_id, activity_id, attendance_date, time_in, time_out, scan_code, status, keterangan) 
                                        VALUES (?, ?, ?, NULL, ?, ?, 'terlambat', 'Hanya scan pulang')
                                        ON DUPLICATE KEY UPDATE time_out = VALUES(time_out), scan_code = VALUES(scan_code), status = 'terlambat', keterangan = 'Hanya scan pulang'
                                    ")->execute([$staff['id'], $activity['id'], $dateToday, $timeNow, $code]);
                                } else {
                                    $pdo->prepare("UPDATE staff_attendances SET time_out = ?, scan_code = ?, status = 'terlambat', keterangan = 'Hanya scan pulang' WHERE id = ?")
                                        ->execute([$timeNow, $code, $record['id']]);
                                }
                                $message = 'Anda tidak absen pagi, namun absen pulang dicatat.';
                                
                                if ($selisihPulang >= (1 * 3600)) {
                                    $isLembur = true; $lemburMsg = 'Sesi lembur telah tercatat.';
                                    $pdo->prepare("INSERT INTO staff_overtimes (staff_id, activity_id, overtime_date, time_out) VALUES (?, ?, ?, ?)")->execute([$staff['id'], $activity['id'], $dateToday, $timeNow]);
                                }
                            } elseif ($timeOutEmpty) {
                                $selisihMenitMasuk = round(abs($waktuSekarangDetik - ($timeInEmpty ? 0 : strtotime($record['time_in']))) / 60);
                                if (!$timeInEmpty && $selisihMenitMasuk < 30) {
                                    $message = 'Beri jeda minimal 30 menit dari absen masuk.';
                                } elseif ($waktuSekarangDetik < $bukaPulang) {
                                    $message = 'Belum waktunya absen pulang.';
                                } else {
                                    $pdo->prepare("UPDATE staff_attendances SET time_out = ? WHERE id = ?")->execute([$timeNow, $record['id']]);
                                    if ($selisihPulang >= (1 * 3600)) {
                                        $isLembur = true; $lemburMsg = 'Sesi lembur telah tercatat.';
                                        $pdo->prepare("INSERT INTO staff_overtimes (staff_id, activity_id, overtime_date, time_out) VALUES (?, ?, ?, ?)")->execute([$staff['id'], $activity['id'], $dateToday, $timeNow]);
                                    }
                                }
                            } else { $message = 'Anda sudah absen pulang hari ini.'; }
                        }
                        $responseData = ['type'=>'staff', 'name'=>$staff['name'], 'nik'=>$staff['nik'], 'photo'=>$staff['photo'], 'time'=>$timeNow, 'badge_text'=>$badgeText, 'badge_class'=>$badgeClass, 'scan_type'=>$scanType, 'is_lembur'=>$isLembur, 'lembur_msg'=>$lemburMsg];
                        if ($message !== '') { $responseData['already'] = true; $responseData['message'] = $message; }
                    }
                }
            }
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Data tidak ditemukan! (Barcode/NIS/NIK Salah)']); exit;
        }
    }

    // ----------------------------------------------------------------------------------
    // PEMBENTUKAN TAMPILAN TERKINI & JSON RESPONSE
    // ----------------------------------------------------------------------------------
    $stmtStats = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM student_attendances WHERE attendance_date = ? AND status NOT IN ('alpha', 'tidak_absen', '')) as total_siswa,
            (SELECT COUNT(*) FROM student_attendances WHERE attendance_date = ? AND status = 'tepat_waktu') as tepat_siswa,
            (SELECT COUNT(*) FROM staff_attendances WHERE attendance_date = ? AND status NOT IN ('alpha', 'tidak_absen', '')) as total_staff
    ");
    $stmtStats->execute([$dateToday, $dateToday, $dateToday]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

    $stmtRecent = $pdo->prepare("
        (SELECT COALESCE(sa.time_out, sa.time_in) as scan_time, s.name, cg.name as class_name, sa.status, 'student' as type, s.photo
        FROM student_attendances sa 
        JOIN students s ON s.id = sa.student_id 
        JOIN student_enrollments se ON se.id = sa.enrollment_id 
        JOIN class_groups cg ON cg.id = se.class_group_id
        WHERE sa.attendance_date = ? AND (sa.time_in IS NOT NULL OR sa.time_out IS NOT NULL))
        UNION ALL
        (SELECT COALESCE(sta.time_out, sta.time_in) as scan_time, st.name, 'Guru/Staff' as class_name, sta.status, 'staff' as type, st.photo
        FROM staff_attendances sta 
        JOIN staff st ON st.id = sta.staff_id
        WHERE sta.attendance_date = ? AND (sta.time_in IS NOT NULL OR sta.time_out IS NOT NULL))
        ORDER BY scan_time DESC LIMIT 5
    ");

    $stmtRecent->execute([$dateToday, $dateToday]);
    $recents = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    $recentHTML = '';
    foreach($recents as $r) {
        if(empty($r['scan_time'])) continue;
        $color = ($r['status'] === 'tepat_waktu') ? 'text-green' : 'text-red';
        $time = date('H:i', strtotime($r['scan_time']));
        $folder = $r['type'] === 'staff' ? 'staff' : 'students';
        $imgSrc = !empty($r['photo']) ? "../uploads/{$folder}/" . e($r['photo']) : 'https://via.placeholder.com/80/cbd5e1/475569?text=' . strtoupper(substr($r['name'], 0, 1));

        $recentHTML .= "
        <div class='recent-item' style='display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.05);'>
            <img src='{$imgSrc}' style='width: 40px; height: 40px; border-radius: 50%; object-fit: cover; background: #fff;'>
            <div class='recent-info' style='flex: 1;'>
                <h4 style='margin: 0 0 3px 0; font-size: 14px; color: #f1f5f9;'>".e($r['name'])."</h4>
                <p style='margin: 0; font-size: 11px; color: #94a3b8;'>".e($r['class_name'])."</p>
            </div>
            <div class='recent-time {$color}' style='font-size: 13px; font-weight: 700;'>{$time}</div>
        </div>";
    }

    // KIRIM RESPONSE SUKSES
    echo json_encode(['status' => 'success', 'data' => $responseData, 'stats' => $stats, 'recent' => $recentHTML]);
    exit;

} catch (PDOException $e) {
    // JIKA ADA ERROR DATABASE, MUNCULKAN ERRORNYA DI LAYAR SCANNER
    echo json_encode(['status' => 'error', 'msg' => 'Database Error: ' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'System Error: ' . $e->getMessage()]);
    exit;
}