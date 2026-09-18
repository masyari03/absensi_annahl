<?php
error_reporting(0); // Matikan tampilan error default agar tidak merusak format JSON
session_start();
require_once '../config/database.php';
require_once '../config/security.php';

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
$dayCode = date('N'); 
$now = time();

// FUNGSI AUTO-ALPHA (Berjalan otomatis saat ada 1 orang yang absen pulang)
function runAutoAlpha($pdo, $unitId, $dateToday, $activityId) {
    try {
        // Menggunakan status 'terlambat' agar aman dan tidak ditolak oleh ENUM database
        $sqlSiswa = "INSERT IGNORE INTO student_attendances (student_id, enrollment_id, activity_id, attendance_date, status, keterangan)
                     SELECT s.id, se.id, ?, ?, 'terlambat', 'Otomatis (Belum Absen Masuk)'
                     FROM students s
                     INNER JOIN student_enrollments se ON se.student_id = s.id
                     INNER JOIN class_groups cg ON cg.id = se.class_group_id
                     INNER JOIN grades g ON g.id = cg.grade_id
                     WHERE s.deleted_at IS NULL AND se.status = 'active' AND g.unit_id = ?
                     AND NOT EXISTS (SELECT 1 FROM student_attendances sa WHERE sa.student_id = s.id AND sa.attendance_date = ?)";
        $pdo->prepare($sqlSiswa)->execute([$activityId, $dateToday, $unitId, $dateToday]);

        $sqlStaff = "INSERT IGNORE INTO staff_attendances (staff_id, activity_id, attendance_date, status, keterangan)
                     SELECT st.id, ?, ?, 'terlambat', 'Otomatis (Belum Absen Masuk)'
                     FROM staff st
                     WHERE st.deleted_at IS NULL AND st.unit_id = ?
                     AND NOT EXISTS (SELECT 1 FROM staff_attendances sta WHERE sta.staff_id = st.id AND sta.attendance_date = ?)";
        $pdo->prepare($sqlStaff)->execute([$activityId, $dateToday, $unitId, $dateToday]);
    } catch(Exception $e) {}
}

// FUNGSI MENGAMBIL JADWAL AKTIVITAS
function getDailyActivity($pdo, $unitId, $dateToday, $dayCode) {
    $stmt = $pdo->prepare("SELECT * FROM activities WHERE activity_date = ? AND unit_id = ? LIMIT 1");
    $stmt->execute([$dateToday, $unitId]);
    $activity = $stmt->fetch();

    if (!$activity) {
        $stmtWeek = $pdo->prepare("SELECT * FROM weekly_schedules WHERE day_code = ? AND unit_id = ? AND is_active = 'active' LIMIT 1");
        $stmtWeek->execute([$dayCode, $unitId]);
        $weekly = $stmtWeek->fetch();
        
        if ($weekly) {
            $stmtAy = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1");
            $ay = $stmtAy->fetch();
            $ayId = $ay ? $ay['id'] : 1;

            $insertAct = $pdo->prepare("INSERT INTO activities (academic_year_id, unit_id, name, activity_date, student_in, student_late, student_out, staff_in, staff_late, staff_out, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $insertAct->execute([$ayId, $unitId, "KBM Reguler", $dateToday, $weekly['student_in'], $weekly['student_late'], $weekly['student_out'], $weekly['staff_in'], $weekly['staff_late'], $weekly['staff_out']]);
            $stmt->execute([$dateToday, $unitId]);
            $activity = $stmt->fetch();
        }
    }
    return $activity;
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
        WHERE s.deleted_at IS NULL AND se.status = 'active' AND (s.nis = ? OR s.nik = ?) LIMIT 1
    ");
    $stmtStudent->execute([$code, $code]);
    $student = $stmtStudent->fetch();

    if ($student) {
        $activity = getDailyActivity($pdo, $student['unit_id'], $dateToday, $dayCode);
        if (!$activity || $activity['status'] !== 'active') { 
            $responseData = ['type'=>'student', 'name'=>$student['name'], 'nis'=>$student['nis'], 'class_name'=>$student['class_name'], 'photo'=>$student['photo'], 'time'=>$timeNow, 'badge_text'=>'ℹ TIDAK ADA JADWAL', 'badge_class'=>'late', 'already'=>true, 'message'=>'Hari Ini Tidak Ada Jadwal'];
        } else {
            $jamMasukDetik = strtotime($activity['student_in']);
            $jamPulangDetik = strtotime($activity['student_out']);
            
            $bukaMasuk = strtotime('-2 hours', $jamMasukDetik);
            $tutupMasuk = strtotime('+3 hours', $jamMasukDetik);
            $bukaPulang = strtotime('+4 hours', $jamMasukDetik);
            $tutupPulang = strtotime('+7 hours', $jamPulangDetik);

            if ($waktuSekarangDetik < $bukaMasuk) {
                $responseData = ['type'=>'student', 'name'=>$student['name'], 'nis'=>$student['nis'], 'class_name'=>$student['class_name'], 'photo'=>$student['photo'], 'time'=>$timeNow, 'badge_text'=>'✓ KEPAGIAN', 'badge_class'=>'ontime', 'scan_type'=>'masuk', 'already'=>true, 'message'=>'Belum waktunya absen masuk!'];
            } elseif ($waktuSekarangDetik > $tutupPulang) {
                $responseData = ['type'=>'student', 'name'=>$student['name'], 'nis'=>$student['nis'], 'class_name'=>$student['class_name'], 'photo'=>$student['photo'], 'time'=>$timeNow, 'badge_text'=>'⚠ SESI HABIS', 'badge_class'=>'late', 'scan_type'=>'pulang', 'already'=>true, 'message'=>'Sesi absensi hari ini sudah ditutup.'];
            } else {
                $message = ''; $scanType = ''; $badgeText = ''; $badgeClass = '';

                $check = $pdo->prepare("SELECT id, time_in, time_out, status FROM student_attendances WHERE student_id = ? AND attendance_date = ? LIMIT 1");
                $check->execute([$student['id'], $dateToday]);
                $record = $check->fetch();
                
                $timeInEmpty = !$record || empty($record['time_in']) || $record['time_in'] === '00:00:00';
                $timeOutEmpty = !$record || empty($record['time_out']) || $record['time_out'] === '00:00:00';
                $isAutoAlpha = $record && $timeInEmpty && $timeOutEmpty; 

                if ($waktuSekarangDetik <= $tutupMasuk) {
                    // === ABSEN MASUK ===
                    $scanType = 'masuk';
                    $statusKehadiran = ($timeNow > $activity['student_late']) ? 'terlambat' : 'tepat_waktu';
                    $badgeText = ($statusKehadiran === 'tepat_waktu') ? '✓ TEPAT WAKTU' : '⚠ TERLAMBAT';
                    $badgeClass = ($statusKehadiran === 'tepat_waktu') ? 'ontime' : 'late';

                    if (!$record) {
                        $pdo->prepare("INSERT INTO student_attendances (student_id, enrollment_id, activity_id, attendance_date, time_in, scan_code, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$student['id'], $student['enrollment_id'], $activity['id'], $dateToday, $timeNow, $code, $statusKehadiran]);
                    } elseif ($isAutoAlpha || $timeInEmpty) {
                        $pdo->prepare("UPDATE student_attendances SET time_in = ?, scan_code = ?, status = ?, keterangan = NULL WHERE id = ?")
                            ->execute([$timeNow, $code, $statusKehadiran, $record['id']]);
                    } else {
                        $message = 'Anda sudah absen masuk hari ini.';
                    }
                } else {
                    // === ABSEN PULANG ===
                    $scanType = 'pulang';
                    $selisihPulang = $waktuSekarangDetik - $jamPulangDetik; 

                    if ($selisihPulang < 0) { $badgeText = '⚠ PULANG CEPAT'; $badgeClass = 'late'; }
                    elseif ($selisihPulang >= (1 * 3600)) { $badgeText = '⚠ PULANG TERLALU LAMA'; $badgeClass = 'late'; }
                    else { $badgeText = '✓ TEPAT WAKTU'; $badgeClass = 'ontime'; }

                    runAutoAlpha($pdo, $student['unit_id'], $dateToday, $activity['id']);

                    if (!$record || $isAutoAlpha) {
                        if (!$record) {
                            $pdo->prepare("INSERT INTO student_attendances (student_id, enrollment_id, activity_id, attendance_date, time_in, time_out, scan_code, status) VALUES (?, ?, ?, ?, NULL, ?, ?, 'terlambat')")
                                ->execute([$student['id'], $student['enrollment_id'], $activity['id'], $dateToday, $timeNow, $code]);
                        } else {
                            $pdo->prepare("UPDATE student_attendances SET time_out = ?, scan_code = ?, status = 'terlambat', keterangan = 'Hanya scan pulang' WHERE id = ?")
                                ->execute([$timeNow, $code, $record['id']]);
                        }
                        $message = 'Anda tidak absen pagi, namun absen pulang dicatat.';
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
                }
                $responseData = ['type'=>'student', 'name'=>$student['name'], 'nis'=>$student['nis'], 'class_name'=>$student['class_name'], 'photo'=>$student['photo'], 'time'=>$timeNow, 'badge_text'=>$badgeText, 'badge_class'=>$badgeClass, 'scan_type'=>$scanType];
                if ($message !== '') { $responseData['already'] = true; $responseData['message'] = $message; }
            }
        }
    }
    
    /* --------------------------------------------------------------------------
       PROSES SCAN STAFF
    -------------------------------------------------------------------------- */
    else {
        $stmtStaff = $pdo->prepare("SELECT id, name, nik, photo, unit_id FROM staff WHERE nik = ? AND deleted_at IS NULL LIMIT 1");
        $stmtStaff->execute([$code]);
        $staff = $stmtStaff->fetch();

        if ($staff) {
            $activity = getDailyActivity($pdo, $staff['unit_id'], $dateToday, $dayCode);
            if (!$activity || $activity['status'] !== 'active') { 
                $responseData = ['type'=>'staff', 'name'=>$staff['name'], 'nik'=>$staff['nik'], 'photo'=>$staff['photo'], 'time'=>$timeNow, 'badge_text'=>'ℹ TIDAK ADA JADWAL', 'badge_class'=>'late', 'already'=>true, 'message'=>'Hari Ini Tidak Ada Jadwal'];
            } else {
                $jamMasukDetik = strtotime($activity['staff_in']);
                $jamPulangDetik = strtotime($activity['staff_out']);
                $bukaMasuk = strtotime('-2 hours', $jamMasukDetik);
                $tutupMasuk = strtotime('+3 hours', $jamMasukDetik); 
                $bukaPulang = strtotime('+4 hours', $jamMasukDetik);
                $tutupPulang = strtotime('+7 hours', $jamPulangDetik);

                if ($waktuSekarangDetik < $bukaMasuk) {
                    $responseData = ['type'=>'staff', 'name'=>$staff['name'], 'nik'=>$staff['nik'], 'photo'=>$staff['photo'], 'time'=>$timeNow, 'badge_text'=>'✓ KEPAGIAN', 'badge_class'=>'ontime', 'scan_type'=>'masuk', 'already'=>true, 'message'=>'Belum waktunya absen masuk!'];
                } elseif ($waktuSekarangDetik > $tutupPulang) {
                    $responseData = ['type'=>'staff', 'name'=>$staff['name'], 'nik'=>$staff['nik'], 'photo'=>$staff['photo'], 'time'=>$timeNow, 'badge_text'=>'⚠ SESI HABIS', 'badge_class'=>'late', 'scan_type'=>'pulang', 'already'=>true, 'message'=>'Sesi absensi hari ini sudah ditutup.'];
                } else {
                    $message = ''; $scanType = ''; $badgeText = ''; $badgeClass = ''; $isLembur = false; $lemburMsg = '';
                    
                    $check = $pdo->prepare("SELECT id, time_in, time_out, status FROM staff_attendances WHERE staff_id = ? AND attendance_date = ? LIMIT 1");
                    $check->execute([$staff['id'], $dateToday]);
                    $record = $check->fetch();

                    $timeInEmpty = !$record || empty($record['time_in']) || $record['time_in'] === '00:00:00';
                    $timeOutEmpty = !$record || empty($record['time_out']) || $record['time_out'] === '00:00:00';
                    $isAutoAlpha = $record && $timeInEmpty && $timeOutEmpty; 

                    if ($waktuSekarangDetik <= $tutupMasuk) {
                        $scanType = 'masuk';
                        $statusKehadiran = ($timeNow > $activity['staff_late']) ? 'terlambat' : 'tepat_waktu';
                        $badgeText = ($statusKehadiran === 'tepat_waktu') ? '✓ TEPAT WAKTU' : '⚠ TERLAMBAT';
                        $badgeClass = ($statusKehadiran === 'tepat_waktu') ? 'ontime' : 'late';
                        
                        if (!$record) {
                            $pdo->prepare("INSERT INTO staff_attendances (staff_id, activity_id, attendance_date, time_in, scan_code, status) VALUES (?, ?, ?, ?, ?, ?)")
                                ->execute([$staff['id'], $activity['id'], $dateToday, $timeNow, $code, $statusKehadiran]);
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

                        runAutoAlpha($pdo, $staff['unit_id'], $dateToday, $activity['id']);

                        if (!$record || $isAutoAlpha) {
                            if (!$record) {
                                $pdo->prepare("INSERT INTO staff_attendances (staff_id, activity_id, attendance_date, time_in, time_out, scan_code, status) VALUES (?, ?, ?, NULL, ?, ?, 'terlambat')")
                                    ->execute([$staff['id'], $activity['id'], $dateToday, $timeNow, $code]);
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
    $stats = $stmtStats->fetch();

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
    $recents = $stmtRecent->fetchAll();

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