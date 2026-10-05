<?php
/**
 * REST API ABSENSI ESP32 (RFID RC522 & FINGERPRINT AS608)
 * 
 * Fitur Anti-Tabrakan (Anti-Collision Architecture):
 * 1. Setiap alat ESP32 memiliki Device ID dan Token Rahasia unik.
 * 2. Database row-level locking (SELECT ... FOR UPDATE) & atomic transaction.
 * 3. Debounce / Anti-Double Tap: mencegah tap ganda dalam 20 detik.
 * 4. Staf bebas absen di alat / unit mana saja (Staff can tap anywhere).
 * 5. Integrasi otomatis notifikasi WhatsApp ke orang tua / wali siswa.
 * 6. Pencatatan log mentah (iot_attendance_logs) untuk audit dan pemantauan realtime.
 */

error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Token, X-Device-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/whatsapp.php';

// Helper respon JSON
function apiResponse(string $status, string $message, array $data = [], int $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'status'    => $status,
        'message'   => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Tangkap Input Data (Mendukung JSON Body maupun Form POST)
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);

$deviceId = trim($_POST['device_id'] ?? $jsonInput['device_id'] ?? $_SERVER['HTTP_X_DEVICE_ID'] ?? '');
$deviceToken = trim($_POST['token'] ?? $jsonInput['token'] ?? $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
$scanType = strtolower(trim($_POST['type'] ?? $jsonInput['type'] ?? 'rfid')); // 'rfid' atau 'fingerprint'
$scanCode = trim($_POST['code'] ?? $jsonInput['code'] ?? '');
$action = trim($_POST['action'] ?? $jsonInput['action'] ?? 'scan'); // 'scan' atau 'ping'

// 2. Validasi Kredensial Perangkat ESP32
if (empty($deviceId) || empty($deviceToken)) {
    apiResponse('unauthorized', 'Device ID atau Token ESP32 tidak disertakan.', [
        'led'  => 'red_blink',
        'beep' => 3
    ], 401);
}

$stmtDev = $pdo->prepare("
    SELECT id, device_id, name, unit_id, is_active 
    FROM iot_devices 
    WHERE device_id = ? AND device_token = ? 
    LIMIT 1
");
$stmtDev->execute([$deviceId, $deviceToken]);
$device = $stmtDev->fetch(PDO::FETCH_ASSOC);

if (!$device) {
    apiResponse('unauthorized', 'Perangkat ESP32 tidak terdaftar atau token salah.', [
        'led'  => 'red_blink',
        'beep' => 3
    ], 401);
}

if ((int)$device['is_active'] !== 1) {
    apiResponse('disabled', 'Perangkat ESP32 ini sedang dinonaktifkan oleh administrator.', [
        'led'  => 'red_blink',
        'beep' => 3
    ], 403);
}

// Update Heartbeat & IP Address Perangkat
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$pdo->prepare("UPDATE iot_devices SET last_seen = NOW(), ip_address = ? WHERE id = ?")
    ->execute([$clientIp, $device['id']]);

// Jika hanya ping / heartbeat test
if ($action === 'ping') {
    apiResponse('success', 'ESP32 Device Ready & Online', [
        'device_name' => $device['name'],
        'unit_id'     => $device['unit_id'],
        'server_time' => date('Y-m-d H:i:s'),
        'led'         => 'green_solid'
    ]);
}

// 3. Validasi Kode Scan
if ($scanCode === '') {
    apiResponse('error', 'Kode scan RFID / Fingerprint kosong.', [
        'led'  => 'red_blink',
        'beep' => 2
    ], 400);
}

// Standardisasi kode RFID ke Uppercase Hex
if ($scanType === 'rfid') {
    $scanCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $scanCode));
}

$dateToday = date('Y-m-d');
$timeNow = date('H:i:s');
$timeNowSeconds = strtotime($timeNow);
$dayCode = date('N');

// =========================================================================
// ANTI-COLLISION & DEBOUNCE (Cegah Tap Ganda / Race Condition dalam 20 Detik)
// =========================================================================
$stmtRecent = $pdo->prepare("
    SELECT id, created_at, response_message 
    FROM iot_attendance_logs 
    WHERE scan_code = ? AND created_at >= (NOW() - INTERVAL 20 SECOND)
    ORDER BY id DESC LIMIT 1
");
$stmtRecent->execute([$scanCode]);
$recentLog = $stmtRecent->fetch(PDO::FETCH_ASSOC);

if ($recentLog) {
    apiResponse('cooldown', 'Baru saja absen. Silakan tunggu jeda 20 detik.', [
        'led'  => 'green_solid',
        'beep' => 1
    ]);
}

// Helper untuk mengambil jadwal harian aktif
function getDeviceDailyActivity(PDO $pdo, int $unitId, string $dateToday, int $dayCode, string $targetType = 'student'): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM activities 
        WHERE activity_date = ? AND unit_id = ? 
          AND (target_type = ? OR target_type = 'all' OR target_type IS NULL) 
        ORDER BY (target_type = ?) DESC, id ASC 
        LIMIT 1
    ");
    $stmt->execute([$dateToday, $unitId, $targetType, $targetType]);
    $act = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$act) {
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
            $ayId = (int)($stmtAy->fetchColumn() ?: 1);
            $actName = ($targetType === 'staff') ? "Jadwal Reguler Staff & Guru" : "Jadwal Reguler Siswa";

            $stmtIns = $pdo->prepare("
                INSERT INTO activities 
                (academic_year_id, unit_id, target_type, name, activity_date, student_in, student_late, student_out, staff_in, staff_late, staff_out, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmtIns->execute([
                $ayId, $unitId, $targetType, $actName, $dateToday, 
                $weekly['student_in'] ?? '00:00:00', $weekly['student_late'] ?? '00:00:00', $weekly['student_out'] ?? '00:00:00', 
                $weekly['staff_in'] ?? '06:45:00', $weekly['staff_late'] ?? '07:00:00', $weekly['staff_out'] ?? '15:30:00'
            ]);
            
            $stmt->execute([$dateToday, $unitId, $targetType, $targetType]);
            $act = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    return $act ?: null;
}

// Mulai Transaksi Database Atomic
try {
    $pdo->beginTransaction();

    // =========================================================================
    // IDENTIFIKASI USER: 1. CEK STAFF (STAF BISA ABSEN DI ALAT / UNIT MANA SAJA)
    // =========================================================================
    $stmtStaff = $pdo->prepare("
        SELECT st.id, st.name, st.nik, st.phone, st.wa_notify, st.unit_id, u.unit as unit_name
        FROM staff st
        LEFT JOIN units u ON u.id = st.unit_id
        WHERE st.deleted_at IS NULL 
          AND (st.rfid_uid = ? OR (st.fingerprint_id = ? AND ? > 0) OR st.nik = ?)
        LIMIT 1
    ");
    $fingerInt = is_numeric($scanCode) ? (int)$scanCode : 0;
    $stmtStaff->execute([$scanCode, $fingerInt, $fingerInt, $scanCode]);
    $staff = $stmtStaff->fetch(PDO::FETCH_ASSOC);

    if ($staff) {
        // Staf ditemukan!
        $staffId = (int)$staff['id'];
        $staffHomeUnit = (int)($staff['unit_id'] ?? $device['unit_id']);

        // Jadwal diambil dari Home Unit staff agar aturan jam kerjanya sesuai tugasnya
        $activity = getDeviceDailyActivity($pdo, $staffHomeUnit, $dateToday, $dayCode, 'staff');
        if (!$activity || $activity['status'] !== 'active') {
            $pdo->rollBack();
            // Catat log
            $pdo->prepare("INSERT INTO iot_attendance_logs (device_id, unit_id, scan_type, scan_code, user_type, user_id, status, response_message) VALUES (?, ?, ?, ?, 'staff', ?, 'error', 'Tidak ada jadwal aktif')")
                ->execute([$deviceId, $device['unit_id'], $scanType, $scanCode, $staffId]);

            apiResponse('no_schedule', "Tidak ada jadwal KBM/kerja aktif untuk {$staff['name']} hari ini.", [
                'name'      => $staff['name'],
                'user_type' => 'staff',
                'led'       => 'red_blink',
                'beep'      => 2
            ]);
        }

        $staffShift = function_exists('getStaffShiftSchedule')
            ? getStaffShiftSchedule($pdo, $staffId, $staffHomeUnit, (int)$dayCode, $timeNow)
            : ['has_schedule' => false, 'is_overnight' => false];
        $isOvernight = !empty($staffShift['is_overnight']);

        // =====================================================================
        // ALGORITMA SHIFT MALAM SECURITY CROSS-MIDNIGHT (18:00 - 06:00) DI ESP32
        // =====================================================================
        if ($isOvernight) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));

            // Pagi hari (04:00 - 11:30): Pulang shift malam kemarin
            if ($timeNowSeconds >= strtotime('04:00:00') && $timeNowSeconds <= strtotime('11:30:00')) {
                $checkYest = $pdo->prepare("
                    SELECT id, activity_id, time_in, time_out, status 
                    FROM staff_attendances 
                    WHERE staff_id = ? AND attendance_date = ? 
                    FOR UPDATE
                ");
                $checkYest->execute([$staffId, $yesterday]);
                $yestRecord = $checkYest->fetch(PDO::FETCH_ASSOC);

                $scanDirection = 'pulang';

                if ($yestRecord && (empty($yestRecord['time_out']) || $yestRecord['time_out'] === '00:00:00')) {
                    $pdo->prepare("UPDATE staff_attendances SET time_out = ? WHERE id = ?")
                        ->execute([$timeNow, $yestRecord['id']]);

                    $targetJamPulang = strtotime($staffShift['staff_out'] ?? '06:00:00');
                    if ($timeNowSeconds >= ($targetJamPulang + 3600)) {
                        $badge = 'LEMBUR';
                        $pdo->prepare("INSERT INTO staff_overtimes (staff_id, activity_id, overtime_date, time_out, created_at) VALUES (?, ?, ?, ?, NOW())")
                            ->execute([$staffId, $yestRecord['activity_id'], $yesterday, $timeNow]);
                    } else {
                        $badge = 'PULANG MALAM';
                    }
                    $msg = "Absen Pulang Shift Malam Berhasil ({$badge})";
                } elseif ($yestRecord && !empty($yestRecord['time_out']) && $yestRecord['time_out'] !== '00:00:00') {
                    $pdo->commit();
                    apiResponse('already', "Anda sudah absen pulang shift malam sebelumnya ({$yestRecord['time_out']}).", [
                        'name' => $staff['name'],
                        'user_type' => 'staff',
                        'led' => 'green_solid',
                        'beep' => 1
                    ]);
                } else {
                    $actId = $activity ? $activity['id'] : 1;
                    $pdo->prepare("INSERT INTO staff_attendances (staff_id, activity_id, attendance_date, time_in, time_out, scan_code, status, keterangan) VALUES (?, ?, ?, NULL, ?, ?, 'terlambat', 'Hanya Absen Pulang Shift Malam')")
                        ->execute([$staffId, $actId, $yesterday, $timeNow, "ESP32:{$deviceId}:{$scanCode}"]);
                    $msg = "Absen Pulang Shift Malam Berhasil Dicatat";
                    $badge = 'PULANG MALAM';
                }
            } elseif ($timeNowSeconds >= strtotime('16:00:00')) {
                // Masuk shift malam
                $scanDirection = 'masuk';
                $statusKehadiran = ($timeNow > ($staffShift['staff_late'] ?? '18:30:00')) ? 'terlambat' : 'tepat_waktu';
                $badge = ($statusKehadiran === 'tepat_waktu') ? 'TEPAT WAKTU' : 'TERLAMBAT';

                $checkToday = $pdo->prepare("SELECT id, time_in, time_out, status FROM staff_attendances WHERE staff_id = ? AND attendance_date = ? FOR UPDATE");
                $checkToday->execute([$staffId, $dateToday]);
                $recToday = $checkToday->fetch(PDO::FETCH_ASSOC);

                $actId = $activity ? $activity['id'] : 1;
                if (!$recToday) {
                    $pdo->prepare("INSERT INTO staff_attendances (staff_id, activity_id, attendance_date, time_in, scan_code, status, keterangan) VALUES (?, ?, ?, ?, ?, ?, 'Shift Malam')")
                        ->execute([$staffId, $actId, $dateToday, $timeNow, "ESP32:{$deviceId}:{$scanCode}", $statusKehadiran]);
                    $msg = "Absen Masuk Shift Malam Berhasil ({$badge})";
                } elseif (empty($recToday['time_in']) || $recToday['time_in'] === '00:00:00') {
                    $pdo->prepare("UPDATE staff_attendances SET time_in = ?, scan_code = ?, status = ?, keterangan = 'Shift Malam' WHERE id = ?")
                        ->execute([$timeNow, "ESP32:{$deviceId}:{$scanCode}", $statusKehadiran, $recToday['id']]);
                    $msg = "Absen Masuk Shift Malam Berhasil ({$badge})";
                } else {
                    $pdo->commit();
                    apiResponse('already', "Anda sudah berhasil absen masuk shift malam hari ini ({$recToday['time_in']}).", [
                        'name' => $staff['name'],
                        'user_type' => 'staff',
                        'led' => 'green_solid',
                        'beep' => 1
                    ]);
                }
            } else {
                $pdo->rollBack();
                apiResponse('outside_schedule', "Bukan jam dinas shift malam (Jadwal: 18:00 - 06:00).", [
                    'name' => $staff['name'],
                    'user_type' => 'staff',
                    'led' => 'red_blink',
                    'beep' => 2
                ]);
            }
        } else {
            $jamMasukStr = $staffShift['staff_in'] ?? $activity['staff_in'];
            $jamPulangStr = $staffShift['staff_out'] ?? $activity['staff_out'];
            $jamTelatStr = $staffShift['staff_late'] ?? $activity['staff_late'];

            $jamMasukDetik = strtotime($jamMasukStr);
            $jamPulangDetik = strtotime($jamPulangStr);
            $bukaMasuk = strtotime('-2 hours', $jamMasukDetik);
            $tutupMasuk = strtotime('+3 hours', $jamMasukDetik);
            $bukaPulang = strtotime('+4 hours', $jamMasukDetik);
            $tutupPulang = strtotime('+7 hours', $jamPulangDetik);

            // Kunci baris absensi staff hari ini (SELECT ... FOR UPDATE)
            $checkStmt = $pdo->prepare("
                SELECT id, time_in, time_out, status 
                FROM staff_attendances 
                WHERE staff_id = ? AND attendance_date = ? 
                FOR UPDATE
            ");
            $checkStmt->execute([$staffId, $dateToday]);
            $record = $checkStmt->fetch(PDO::FETCH_ASSOC);

            $timeInEmpty = !$record || empty($record['time_in']) || $record['time_in'] === '00:00:00';
            $timeOutEmpty = !$record || empty($record['time_out']) || $record['time_out'] === '00:00:00';

            $scanDirection = '';
            $badge = '';
            $msg = '';

            if ($timeNowSeconds <= $tutupMasuk) {
                // Sesi Masuk
                $scanDirection = 'masuk';
                $statusKehadiran = ($timeNow > $jamTelatStr) ? 'terlambat' : 'tepat_waktu';
                $badge = ($statusKehadiran === 'tepat_waktu') ? 'TEPAT WAKTU' : 'TERLAMBAT';

                if (!$record) {
                    $pdo->prepare("
                        INSERT INTO staff_attendances 
                        (staff_id, activity_id, attendance_date, time_in, scan_code, status) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$staffId, $activity['id'], $dateToday, $timeNow, "ESP32:{$deviceId}:{$scanCode}", $statusKehadiran]);
                    $msg = "Absen Masuk Berhasil ({$badge})";
                } elseif ($timeInEmpty) {
                    $pdo->prepare("
                        UPDATE staff_attendances 
                        SET time_in = ?, scan_code = ?, status = ?, keterangan = NULL 
                        WHERE id = ?
                    ")->execute([$timeNow, "ESP32:{$deviceId}:{$scanCode}", $statusKehadiran, $record['id']]);
                    $msg = "Absen Masuk Berhasil ({$badge})";
                } else {
                    $pdo->commit();
                    apiResponse('already', "Anda sudah berhasil absen masuk hari ini ({$record['time_in']}).", [
                        'name'      => $staff['name'],
                        'user_type' => 'staff',
                        'time_in'   => $record['time_in'],
                        'led'       => 'green_solid',
                        'beep'      => 1
                    ]);
                }
            } else {
            // Sesi Pulang
            $scanDirection = 'pulang';
            $badge = 'PULANG';

            if ($timeOutEmpty) {
                if (!$record) {
                    $pdo->prepare("
                        INSERT INTO staff_attendances 
                        (staff_id, activity_id, attendance_date, time_out, scan_code, status, keterangan) 
                        VALUES (?, ?, ?, ?, ?, 'tepat_waktu', 'Hanya Absen Pulang')
                    ")->execute([$staffId, $activity['id'], $dateToday, $timeNow, "ESP32:{$deviceId}:{$scanCode}"]);
                } else {
                    $pdo->prepare("UPDATE staff_attendances SET time_out = ? WHERE id = ?")
                        ->execute([$timeNow, $record['id']]);
                }

                // Cek apakah lembur (pulang lebih dari jam pulang normal)
                if ($timeNowSeconds > $jamPulangDetik) {
                    $durasiLembur = $timeNowSeconds - $jamPulangDetik;
                    if ($durasiLembur >= 900) { // minimal 15 menit
                        $badge = 'LEMBUR';
                        // Catat ke staff_overtimes
                        $stmtCheckOt = $pdo->prepare("SELECT id FROM staff_overtimes WHERE staff_id = ? AND overtime_date = ? LIMIT 1");
                        $stmtCheckOt->execute([$staffId, $dateToday]);
                        if (!$stmtCheckOt->fetch()) {
                            $pdo->prepare("INSERT INTO staff_overtimes (staff_id, activity_id, overtime_date, time_out, created_at) VALUES (?, ?, ?, ?, NOW())")
                                ->execute([$staffId, $activity['id'], $dateToday, $timeNow]);
                        }
                    }
                }
                $msg = "Absen Pulang Berhasil ({$badge})";
            } else {
                $pdo->commit();
                apiResponse('already', "Anda sudah berhasil absen pulang hari ini ({$record['time_out']}).", [
                    'name'      => $staff['name'],
                    'user_type' => 'staff',
                    'time_out'  => $record['time_out'],
                    'led'       => 'green_solid',
                    'beep'      => 1
                ]);
            }
        } // end of non-overnight else
    } // end of isOvernight else

        // Catat ke log ESP32
        $pdo->prepare("
            INSERT INTO iot_attendance_logs 
            (device_id, unit_id, scan_type, scan_code, user_type, user_id, status, response_message) 
            VALUES (?, ?, ?, ?, 'staff', ?, 'success', ?)
        ")->execute([$deviceId, $device['unit_id'], $scanType, $scanCode, $staffId, $msg]);

        $pdo->commit();

        apiResponse('success', $msg, [
            'name'        => $staff['name'],
            'user_type'   => 'staff',
            'unit'        => $staff['unit_name'],
            'device_name' => $device['name'],
            'scan_type'   => $scanDirection,
            'badge'       => $badge,
            'time'        => $timeNow,
            'led'         => 'green_blink',
            'beep'        => 1
        ]);
    }

    // =========================================================================
    // IDENTIFIKASI USER: 2. CEK SISWA
    // =========================================================================
    $stmtStudent = $pdo->prepare("
        SELECT s.id, s.name, s.nis, s.nik, s.parent_phone, s.wa_notify,
               se.id AS enrollment_id, cg.name AS class_name, g.grade, g.unit_id, un.unit as unit_name
        FROM students s
        INNER JOIN student_enrollments se ON se.student_id = s.id
        INNER JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
        INNER JOIN class_groups cg ON cg.id = se.class_group_id
        INNER JOIN grades g ON g.id = cg.grade_id
        INNER JOIN units un ON un.id = g.unit_id
        WHERE s.deleted_at IS NULL AND se.status = 'active'
          AND (s.rfid_uid = ? OR (s.fingerprint_id = ? AND ? > 0) OR s.nis = ? OR s.nik = ?)
        LIMIT 1
    ");
    $stmtStudent->execute([$scanCode, $fingerInt, $fingerInt, $scanCode, $scanCode]);
    $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $studentId = (int)$student['id'];
        $studentUnit = (int)$student['unit_id'];

        $activity = getDeviceDailyActivity($pdo, $studentUnit, $dateToday, $dayCode);
        $effSched = function_exists('getStudentEffectiveSchedule')
            ? getStudentEffectiveSchedule($pdo, $studentId, $studentUnit, $dateToday, $dayCode)
            : ['has_schedule' => false];

        $studentInTime = ($effSched['has_schedule'] && !empty($effSched['student_in'])) ? $effSched['student_in'] : ($activity['student_in'] ?? null);
        $studentLateTime = ($effSched['has_schedule'] && !empty($effSched['student_late'])) ? $effSched['student_late'] : ($activity['student_late'] ?? null);
        $studentOutTime = ($effSched['has_schedule'] && !empty($effSched['student_out'])) ? $effSched['student_out'] : ($activity['student_out'] ?? null);

        if (!$activity || $activity['status'] !== 'active' || empty($studentInTime) || empty($studentOutTime)) {
            $pdo->rollBack();
            $pdo->prepare("INSERT INTO iot_attendance_logs (device_id, unit_id, scan_type, scan_code, user_type, user_id, status, response_message) VALUES (?, ?, ?, ?, 'student', ?, 'error', 'Tidak ada jadwal KBM')")
                ->execute([$deviceId, $device['unit_id'], $scanType, $scanCode, $studentId]);

            apiResponse('no_schedule', "Tidak ada jadwal KBM hari ini untuk {$student['name']}.", [
                'name'      => $student['name'],
                'user_type' => 'student',
                'led'       => 'red_blink',
                'beep'      => 2
            ]);
        }

        $jamMasukDetik = strtotime($studentInTime);
        $jamPulangDetik = strtotime($studentOutTime);
        $tutupMasuk = strtotime('+3 hours', $jamMasukDetik);

        // Kunci baris absensi siswa hari ini
        $checkStmt = $pdo->prepare("
            SELECT id, time_in, time_out, status 
            FROM student_attendances 
            WHERE student_id = ? AND attendance_date = ? 
            FOR UPDATE
        ");
        $checkStmt->execute([$studentId, $dateToday]);
        $record = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $timeInEmpty = !$record || empty($record['time_in']) || $record['time_in'] === '00:00:00';
        $timeOutEmpty = !$record || empty($record['time_out']) || $record['time_out'] === '00:00:00';

        $scanDirection = '';
        $badge = '';
        $msg = '';

        if ($timeNowSeconds <= $tutupMasuk) {
            $scanDirection = 'masuk';
            $statusKehadiran = ($timeNow > $studentLateTime) ? 'terlambat' : 'tepat_waktu';
            $badge = ($statusKehadiran === 'tepat_waktu') ? 'TEPAT WAKTU' : 'TERLAMBAT';

            if (!$record) {
                $pdo->prepare("
                    INSERT INTO student_attendances 
                    (student_id, enrollment_id, activity_id, attendance_date, time_in, scan_code, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$studentId, $student['enrollment_id'], $activity['id'], $dateToday, $timeNow, "ESP32:{$deviceId}:{$scanCode}", $statusKehadiran]);
                $msg = "Absen Masuk Berhasil ({$badge})";
            } elseif ($timeInEmpty) {
                $pdo->prepare("
                    UPDATE student_attendances 
                    SET time_in = ?, scan_code = ?, status = ?, keterangan = NULL 
                    WHERE id = ?
                ")->execute([$timeNow, "ESP32:{$deviceId}:{$scanCode}", $statusKehadiran, $record['id']]);
                $msg = "Absen Masuk Berhasil ({$badge})";
            } else {
                $pdo->commit();
                apiResponse('already', "{$student['name']} sudah berhasil absen masuk hari ini.", [
                    'name'      => $student['name'],
                    'user_type' => 'student',
                    'led'       => 'green_solid',
                    'beep'      => 1
                ]);
            }

            // Kirim Notifikasi WhatsApp Masuk / Telat
            try {
                sendStudentWaNotification($pdo, $studentId, ($statusKehadiran === 'terlambat' ? 'late' : 'in'), [
                    'jam_absen'        => date('H:i', strtotime($timeNow)),
                    'status_kehadiran' => $statusKehadiran
                ]);
            } catch (Throwable $e) {}

        } else {
            // Sesi Pulang
            $scanDirection = 'pulang';
            $badge = 'PULANG';

            if ($timeOutEmpty) {
                if (!$record) {
                    $pdo->prepare("
                        INSERT INTO student_attendances 
                        (student_id, enrollment_id, activity_id, attendance_date, time_out, scan_code, status, keterangan) 
                        VALUES (?, ?, ?, ?, ?, 'tepat_waktu', 'Hanya Absen Pulang')
                    ")->execute([$studentId, $student['enrollment_id'], $activity['id'], $dateToday, $timeNow, "ESP32:{$deviceId}:{$scanCode}"]);
                } else {
                    $pdo->prepare("UPDATE student_attendances SET time_out = ? WHERE id = ?")
                        ->execute([$timeNow, $record['id']]);
                }
                $msg = "Absen Pulang Berhasil";

                // Kirim Notifikasi WhatsApp Pulang
                try {
                    sendStudentWaNotification($pdo, $studentId, 'out', [
                        'jam_absen'  => date('H:i', strtotime($timeNow)),
                        'jam_pulang' => substr($activity['student_out'] ?? '15:30', 0, 5)
                    ]);
                } catch (Throwable $e) {}

            } else {
                $pdo->commit();
                apiResponse('already', "{$student['name']} sudah berhasil absen pulang hari ini.", [
                    'name'      => $student['name'],
                    'user_type' => 'student',
                    'led'       => 'green_solid',
                    'beep'      => 1
                ]);
            }
        }

        $pdo->prepare("
            INSERT INTO iot_attendance_logs 
            (device_id, unit_id, scan_type, scan_code, user_type, user_id, status, response_message) 
            VALUES (?, ?, ?, ?, 'student', ?, 'success', ?)
        ")->execute([$deviceId, $device['unit_id'], $scanType, $scanCode, $studentId, $msg]);

        $pdo->commit();

        apiResponse('success', $msg, [
            'name'        => $student['name'],
            'user_type'   => 'student',
            'class'       => $student['grade'] . ' ' . $student['class_name'],
            'unit'        => $student['unit_name'],
            'device_name' => $device['name'],
            'scan_type'   => $scanDirection,
            'badge'       => $badge,
            'time'        => $timeNow,
            'led'         => 'green_blink',
            'beep'        => 1
        ]);
    }

    // =========================================================================
    // JIKA TIDAK DITEMUKAN DI STAFF MAUPUN SISWA
    // =========================================================================
    $pdo->rollBack();
    
    // Log kartu tak dikenal
    $pdo->prepare("
        INSERT INTO iot_attendance_logs 
        (device_id, unit_id, scan_type, scan_code, user_type, user_id, status, response_message) 
        VALUES (?, ?, ?, ?, 'unknown', NULL, 'unregistered', 'Kartu atau Fingerprint Belum Terdaftar')
    ")->execute([$deviceId, $device['unit_id'], $scanType, $scanCode]);

    apiResponse('unregistered', "ID Kartu / Sidik Jari [{$scanCode}] belum terdaftar di sistem.", [
        'scan_code' => $scanCode,
        'scan_type' => $scanType,
        'led'       => 'red_blink',
        'beep'      => 2
    ], 404);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    apiResponse('error', 'Terjadi kesalahan sistem saat memproses presensi: ' . $e->getMessage(), [
        'led'  => 'red_blink',
        'beep' => 2
    ], 500);
}
