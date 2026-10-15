<?php

namespace App\Services;

use PDO;
use Exception;

class AttendanceService
{
    /**
     * Dapatkan atau buat aktivitas harian secara aman (mencegah duplikasi).
     */
    public static function getDailyActivity(PDO $pdo, int $unitId, string $dateToday, int $dayCode): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM activities WHERE activity_date = ? AND unit_id = ? LIMIT 1");
        $stmt->execute([$dateToday, $unitId]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activity) {
            $stmtWeek = $pdo->prepare("SELECT * FROM weekly_schedules WHERE day_code = ? AND unit_id = ? AND is_active = 'active' LIMIT 1");
            $stmtWeek->execute([$dayCode, $unitId]);
            $weekly = $stmtWeek->fetch(PDO::FETCH_ASSOC);

            if ($weekly) {
                $stmtAy = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1");
                $ay = $stmtAy->fetch(PDO::FETCH_ASSOC);
                $ayId = $ay ? $ay['id'] : 1;

                try {
                    $insertAct = $pdo->prepare("
                        INSERT INTO activities 
                        (academic_year_id, unit_id, name, activity_date, student_in, student_late, student_out, staff_in, staff_late, staff_out, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                    ");
                    $insertAct->execute([
                        $ayId, $unitId, "KBM Reguler " . $weekly['day_name'], $dateToday, 
                        $weekly['student_in'], $weekly['student_late'], $weekly['student_out'],
                        $weekly['staff_in'], $weekly['staff_late'], $weekly['staff_out']
                    ]);
                } catch (\Throwable $e) {
                    // Jika ada race condition duplicate key, abaikan error dan re-fetch
                }

                $stmt->execute([$dateToday, $unitId]);
                $activity = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        return $activity ?: null;
    }

    /**
     * Proses scan kode barcode / QR / NIK / NIS baik untuk Siswa maupun Staff.
     */
    public static function processScan(PDO $pdo, string $rawCode, ?int $scannedBy = null): array
    {
        $code = trim($rawCode);
        if ($code === '') {
            return [
                'success' => false,
                'error'   => 'empty',
                'message' => 'Kode barcode / NIS / NIK tidak boleh kosong!',
                'sound'   => 'error'
            ];
        }

        $timeNow = date('H:i:s');
        $dateToday = date('Y-m-d');
        $waktuSekarangDetik = strtotime($timeNow);
        $dayCode = (int)date('N');

        // ======================================================================
        // 1. PROSES SCAN SISWA
        // ======================================================================
        $stmtStudent = $pdo->prepare("
            SELECT s.id, s.name, s.nis, s.nik, s.parent_phone, s.photo,
                   se.id AS enrollment_id, cg.name AS class_name, g.grade, g.unit_id
            FROM students s
            INNER JOIN student_enrollments se ON se.student_id = s.id
            INNER JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
            INNER JOIN class_groups cg ON cg.id = se.class_group_id
            INNER JOIN grades g ON g.id = cg.grade_id
            WHERE s.deleted_at IS NULL AND se.status = 'active' AND (s.nis = ? OR s.nik = ?) 
            LIMIT 1
        ");
        $stmtStudent->execute([$code, $code]);
        $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            $activity = self::getDailyActivity($pdo, (int)$student['unit_id'], $dateToday, $dayCode);
            if (!$activity || $activity['status'] !== 'active') {
                return [
                    'success'     => true,
                    'type'        => 'student',
                    'name'        => $student['name'],
                    'nis'         => $student['nis'],
                    'class_name'  => $student['class_name'],
                    'photo'       => $student['photo'],
                    'time'        => $timeNow,
                    'badge_text'  => 'ℹ TIDAK ADA JADWAL',
                    'badge_class' => 'libur',
                    'already'     => true,
                    'message'     => 'Hari Ini Tidak Ada Jadwal KBM Aktif',
                    'sound'       => 'warning'
                ];
            }

            $jamMasukDetik = strtotime($activity['student_in']);
            $jamPulangDetik = strtotime($activity['student_out']);
            $bukaMasuk = strtotime('-2 hours', $jamMasukDetik);
            $tutupMasuk = strtotime('+3 hours', $jamMasukDetik);
            $bukaPulang = strtotime('+4 hours', $jamMasukDetik);
            $tutupPulang = strtotime('+7 hours', $jamPulangDetik);

            if ($waktuSekarangDetik < $bukaMasuk) {
                return [
                    'success'     => true,
                    'type'        => 'student',
                    'name'        => $student['name'],
                    'nis'         => $student['nis'],
                    'class_name'  => $student['class_name'],
                    'photo'       => $student['photo'],
                    'time'        => $timeNow,
                    'badge_text'  => '✓ TEPAT WAKTU',
                    'badge_class' => 'ontime',
                    'scan_type'   => 'masuk',
                    'already'     => true,
                    'message'     => 'Belum waktunya absen masuk! Gerbang belum dibuka.',
                    'sound'       => 'warning'
                ];
            }

            if ($waktuSekarangDetik > $tutupPulang) {
                return [
                    'success'     => true,
                    'type'        => 'student',
                    'name'        => $student['name'],
                    'nis'         => $student['nis'],
                    'class_name'  => $student['class_name'],
                    'photo'       => $student['photo'],
                    'time'        => $timeNow,
                    'badge_text'  => '⚠ SESI HABIS',
                    'badge_class' => 'late',
                    'scan_type'   => 'pulang',
                    'already'     => true,
                    'message'     => 'Sesi absensi hari ini sudah ditutup.',
                    'sound'       => 'warning'
                ];
            }

            $message = '';
            $scanType = '';
            $badgeText = '';
            $badgeClass = '';
            $isAlready = false;

            $check = $pdo->prepare("SELECT id, time_in, time_out FROM student_attendances WHERE student_id = ? AND activity_id = ? LIMIT 1");
            $check->execute([$student['id'], $activity['id']]);
            $record = $check->fetch(PDO::FETCH_ASSOC);

            if ($waktuSekarangDetik <= $tutupMasuk) {
                // --- ZONA ABSEN MASUK ---
                $scanType = 'masuk';
                $statusKehadiran = ($timeNow > $activity['student_late']) ? 'terlambat' : 'tepat_waktu';
                $badgeText = ($statusKehadiran === 'tepat_waktu') ? '✓ TEPAT WAKTU' : '⚠ TERLAMBAT';
                $badgeClass = ($statusKehadiran === 'tepat_waktu') ? 'ontime' : 'late';

                if (!$record) {
                    $insert = $pdo->prepare("
                        INSERT INTO student_attendances 
                        (student_id, enrollment_id, activity_id, attendance_date, time_in, scan_code, scanned_by, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert->execute([$student['id'], $student['enrollment_id'], $activity['id'], $dateToday, $timeNow, $code, $scannedBy, $statusKehadiran]);

                    // Kirim notifikasi WA ke wali murid jika ada nomor telepon
                    if (!empty($student['parent_phone'])) {
                        NotificationService::sendAttendanceNotification(
                            $student['parent_phone'],
                            $student['name'],
                            'masuk',
                            substr($timeNow, 0, 5),
                            $badgeText
                        );
                    }
                } else {
                    $isAlready = true;
                    $message = 'Anda sudah absen masuk hari ini.';
                }
            } else {
                // --- ZONA ABSEN PULANG ---
                $scanType = 'pulang';
                $selisihPulang = $waktuSekarangDetik - $jamPulangDetik;

                if ($selisihPulang < 0) {
                    $badgeText = '⚠ PULANG CEPAT';
                    $badgeClass = 'late';
                } elseif ($selisihPulang >= (4 * 3600)) {
                    $badgeText = '⚠ HARAP LAPOR KE GURU';
                    $badgeClass = 'late';
                } elseif ($selisihPulang >= (1 * 3600)) {
                    $badgeText = '⚠ PULANG TERLALU LAMA';
                    $badgeClass = 'late';
                } else {
                    $badgeText = '✓ TEPAT WAKTU';
                    $badgeClass = 'ontime';
                }

                if (!$record) {
                    $insert = $pdo->prepare("
                        INSERT INTO student_attendances 
                        (student_id, enrollment_id, activity_id, attendance_date, time_in, time_out, scan_code, scanned_by, status) 
                        VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 'terlambat')
                    ");
                    $insert->execute([$student['id'], $student['enrollment_id'], $activity['id'], $dateToday, $timeNow, $code, $scannedBy]);
                    $message = 'Anda tidak absen pagi hari ini, namun absen pulang berhasil dicatat.';

                    if (!empty($student['parent_phone'])) {
                        NotificationService::sendAttendanceNotification(
                            $student['parent_phone'],
                            $student['name'],
                            'pulang',
                            substr($timeNow, 0, 5),
                            $badgeText
                        );
                    }
                } elseif (is_null($record['time_out'])) {
                    $selisihMenitMasuk = round(abs($waktuSekarangDetik - (empty($record['time_in']) ? 0 : strtotime($record['time_in']))) / 60);
                    if (!empty($record['time_in']) && $selisihMenitMasuk < 30) {
                        $isAlready = true;
                        $message = 'Anda baru saja absen masuk. Beri jeda waktu minimal 30 menit.';
                    } elseif ($waktuSekarangDetik < $bukaPulang) {
                        $isAlready = true;
                        $message = 'Belum waktunya absen pulang. Masih jam pembelajaran sekolah.';
                    } else {
                        $update = $pdo->prepare("UPDATE student_attendances SET time_out = ? WHERE id = ?");
                        $update->execute([$timeNow, $record['id']]);

                        if (!empty($student['parent_phone'])) {
                            NotificationService::sendAttendanceNotification(
                                $student['parent_phone'],
                                $student['name'],
                                'pulang',
                                substr($timeNow, 0, 5),
                                $badgeText
                            );
                        }
                    }
                } else {
                    $isAlready = true;
                    $message = 'Anda sudah berhasil absen pulang.';
                }
            }

            return [
                'success'     => true,
                'type'        => 'student',
                'name'        => $student['name'],
                'nis'         => $student['nis'],
                'class_name'  => $student['class_name'],
                'photo'       => $student['photo'],
                'time'        => $timeNow,
                'badge_text'  => $badgeText,
                'badge_class' => $badgeClass,
                'scan_type'   => $scanType,
                'already'     => $isAlready,
                'message'     => $message,
                'sound'       => $isAlready ? 'warning' : 'success'
            ];
        }

        // ======================================================================
        // 2. PROSES SCAN STAFF
        // ======================================================================
        $stmtStaff = $pdo->prepare("SELECT id, name, nik, photo, unit_id FROM staff WHERE nik = ? AND deleted_at IS NULL LIMIT 1");
        $stmtStaff->execute([$code]);
        $staff = $stmtStaff->fetch(PDO::FETCH_ASSOC);

        if ($staff) {
            $activity = self::getDailyActivity($pdo, (int)$staff['unit_id'], $dateToday, $dayCode);
            if (!$activity || $activity['status'] !== 'active') {
                return [
                    'success'     => true,
                    'type'        => 'staff',
                    'name'        => $staff['name'],
                    'nik'         => $staff['nik'],
                    'photo'       => $staff['photo'],
                    'time'        => $timeNow,
                    'badge_text'  => 'ℹ TIDAK ADA JADWAL',
                    'badge_class' => 'libur',
                    'already'     => true,
                    'message'     => 'Hari Ini Tidak Ada Jadwal Kerja Aktif',
                    'sound'       => 'warning'
                ];
            }

            $jamMasukDetik = strtotime($activity['staff_in']);
            $jamPulangDetik = strtotime($activity['staff_out']);
            $bukaMasuk = strtotime('-2 hours', $jamMasukDetik);
            $tutupMasuk = strtotime('+3 hours', $jamMasukDetik);
            $bukaPulang = strtotime('+4 hours', $jamMasukDetik);
            $tutupPulang = strtotime('+7 hours', $jamPulangDetik);

            if ($waktuSekarangDetik < $bukaMasuk) {
                return [
                    'success'     => true,
                    'type'        => 'staff',
                    'name'        => $staff['name'],
                    'nik'         => $staff['nik'],
                    'photo'       => $staff['photo'],
                    'time'        => $timeNow,
                    'badge_text'  => '✓ TEPAT WAKTU',
                    'badge_class' => 'ontime',
                    'scan_type'   => 'masuk',
                    'already'     => true,
                    'message'     => 'Belum waktunya absen masuk kerja!',
                    'sound'       => 'warning'
                ];
            }

            if ($waktuSekarangDetik > $tutupPulang) {
                return [
                    'success'     => true,
                    'type'        => 'staff',
                    'name'        => $staff['name'],
                    'nik'         => $staff['nik'],
                    'photo'       => $staff['photo'],
                    'time'        => $timeNow,
                    'badge_text'  => '⚠ SESI HABIS',
                    'badge_class' => 'late',
                    'scan_type'   => 'pulang',
                    'already'     => true,
                    'message'     => 'Sesi absensi hari ini sudah ditutup.',
                    'sound'       => 'warning'
                ];
            }

            $message = '';
            $scanType = '';
            $badgeText = '';
            $badgeClass = '';
            $isLembur = false;
            $lemburMsg = '';
            $isAlready = false;

            $check = $pdo->prepare("SELECT id, time_in, time_out FROM staff_attendances WHERE staff_id = ? AND activity_id = ? LIMIT 1");
            $check->execute([$staff['id'], $activity['id']]);
            $record = $check->fetch(PDO::FETCH_ASSOC);

            if ($waktuSekarangDetik <= $tutupMasuk) {
                // --- ZONA ABSEN MASUK STAFF ---
                $scanType = 'masuk';
                $statusKehadiran = ($timeNow > $activity['staff_late']) ? 'terlambat' : 'tepat_waktu';
                $badgeText = ($statusKehadiran === 'tepat_waktu') ? '✓ TEPAT WAKTU' : '⚠ TERLAMBAT';
                $badgeClass = ($statusKehadiran === 'tepat_waktu') ? 'ontime' : 'late';

                if (!$record) {
                    $insert = $pdo->prepare("
                        INSERT INTO staff_attendances 
                        (staff_id, activity_id, attendance_date, time_in, scan_code, scanned_by, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert->execute([$staff['id'], $activity['id'], $dateToday, $timeNow, $code, $scannedBy, $statusKehadiran]);
                } else {
                    $isAlready = true;
                    $message = 'Anda sudah absen masuk hari ini.';
                }
            } else {
                // --- ZONA ABSEN PULANG STAFF ---
                $scanType = 'pulang';
                $selisihPulang = $waktuSekarangDetik - $jamPulangDetik;

                if ($selisihPulang < 0) {
                    $badgeText = '⚠ PULANG CEPAT';
                    $badgeClass = 'late';
                } elseif ($selisihPulang >= (1 * 3600)) {
                    $badgeText = '⏱ LEMBUR YAA';
                    $badgeClass = 'ontime';
                } else {
                    $badgeText = '✓ TEPAT WAKTU';
                    $badgeClass = 'ontime';
                }

                if (!$record) {
                    $insert = $pdo->prepare("
                        INSERT INTO staff_attendances 
                        (staff_id, activity_id, attendance_date, time_in, time_out, scan_code, scanned_by, status) 
                        VALUES (?, ?, ?, NULL, ?, ?, ?, 'terlambat')
                    ");
                    $insert->execute([$staff['id'], $activity['id'], $dateToday, $timeNow, $code, $scannedBy]);
                    $message = 'Anda tidak absen pagi hari ini, namun absen pulang berhasil dicatat.';

                    if ($selisihPulang >= (1 * 3600)) {
                        $isLembur = true;
                        $lemburMsg = 'Sesi lembur telah tercatat ke dalam sistem.';
                        $insertLembur = $pdo->prepare("INSERT INTO staff_overtimes (staff_id, activity_id, overtime_date, time_out) VALUES (?, ?, ?, ?)");
                        $insertLembur->execute([$staff['id'], $activity['id'], $dateToday, $timeNow]);
                    }
                } elseif (is_null($record['time_out'])) {
                    $selisihMenitMasuk = round(abs($waktuSekarangDetik - (empty($record['time_in']) ? 0 : strtotime($record['time_in']))) / 60);
                    if (!empty($record['time_in']) && $selisihMenitMasuk < 30) {
                        $isAlready = true;
                        $message = 'Anda baru saja absen masuk. Beri jeda waktu minimal 30 menit.';
                    } elseif ($waktuSekarangDetik < $bukaPulang) {
                        $isAlready = true;
                        $message = 'Belum waktunya absen pulang. Masih jam operasional kerja.';
                    } else {
                        $update = $pdo->prepare("UPDATE staff_attendances SET time_out = ? WHERE id = ?");
                        $update->execute([$timeNow, $record['id']]);

                        if ($selisihPulang >= (1 * 3600)) {
                            $isLembur = true;
                            $lemburMsg = 'Sesi lembur telah tercatat ke dalam sistem.';
                            $insertLembur = $pdo->prepare("INSERT INTO staff_overtimes (staff_id, activity_id, overtime_date, time_out) VALUES (?, ?, ?, ?)");
                            $insertLembur->execute([$staff['id'], $activity['id'], $dateToday, $timeNow]);
                        }
                    }
                } else {
                    $isAlready = true;
                    $message = 'Anda sudah absen pulang hari ini.';
                }
            }

            return [
                'success'     => true,
                'type'        => 'staff',
                'name'        => $staff['name'],
                'nik'         => $staff['nik'],
                'photo'       => $staff['photo'],
                'time'        => $timeNow,
                'badge_text'  => $badgeText,
                'badge_class' => $badgeClass,
                'scan_type'   => $scanType,
                'is_lembur'   => $isLembur,
                'lembur_msg'  => $lemburMsg,
                'already'     => $isAlready,
                'message'     => $message,
                'sound'       => $isAlready ? 'warning' : 'success'
            ];
        }

        // Tidak ditemukan baik sebagai Siswa maupun Staff
        return [
            'success' => false,
            'error'   => 'not_found',
            'message' => 'Data kartu atau NIS / NIK tidak ditemukan dalam sistem!',
            'sound'   => 'error'
        ];
    }

    /**
     * Hitung jarak dua titik koordinat GPS menggunakan formula Haversine (dalam meter).
     */
    public static function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $earthRadius = 6371000; // Radius bumi dalam meter
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return (int) round($earthRadius * $c);
    }

    /**
     * Simpan foto selfie base64 ke folder uploads/staff/
     */
    public static function saveSelfie(string $base64Data, int $staffId): ?string
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            $data = substr($base64Data, strpos($base64Data, ',') + 1);
            $ext = strtolower($type[1]);
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $ext = 'jpg';
            }
            $decoded = base64_decode($data);
            if ($decoded !== false) {
                $fileName = 'selfie_' . $staffId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $targetDir = defined('UPLOAD_STAFF') ? UPLOAD_STAFF : dirname(__DIR__, 2) . '/uploads/staff/';
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
                $filePath = rtrim($targetDir, '/') . '/' . $fileName;
                if (file_put_contents($filePath, $decoded) !== false) {
                    return $fileName;
                }
            }
        }
        return null;
    }

    /**
     * Proses absensi staff via Mobile GPS dengan Geofencing dan verifikasi selfie.
     */
    public static function processStaffGpsAttendance(
        PDO $pdo,
        int $staffId,
        float $lat,
        float $lng,
        ?string $selfieBase64 = null,
        ?int $scannedBy = null
    ): array {
        $schoolLat = defined('SCHOOL_LATITUDE') ? SCHOOL_LATITUDE : -6.377038;
        $schoolLng = defined('SCHOOL_LONGITUDE') ? SCHOOL_LONGITUDE : 106.945892;
        $maxRadius = defined('SCHOOL_RADIUS_METERS') ? SCHOOL_RADIUS_METERS : 250;

        $distance = self::calculateDistance($lat, $lng, $schoolLat, $schoolLng);

        if ($distance > $maxRadius) {
            return [
                'success'    => false,
                'error'      => 'out_of_radius',
                'distance'   => $distance,
                'max_radius' => $maxRadius,
                'message'    => "Lokasi Anda ({$distance} meter) berada di luar radius sekolah (maksimal {$maxRadius} meter). Silakan lakukan absen di area sekolah."
            ];
        }

        // Cari data staff
        $stmtStaff = $pdo->prepare("SELECT id, name, nik, photo, unit_id FROM staff WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmtStaff->execute([$staffId]);
        $staff = $stmtStaff->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            return [
                'success' => false,
                'error'   => 'not_found',
                'message' => 'Data staff tidak ditemukan dalam sistem!'
            ];
        }

        $timeNow = date('H:i:s');
        $dateToday = date('Y-m-d');
        $waktuSekarangDetik = strtotime($timeNow);
        $dayCode = (int)date('N');

        $activity = self::getDailyActivity($pdo, (int)$staff['unit_id'], $dateToday, $dayCode);
        if (!$activity || $activity['status'] !== 'active') {
            return [
                'success' => false,
                'error'   => 'no_activity',
                'message' => 'Hari ini tidak ada jadwal kerja aktif untuk unit Anda.'
            ];
        }

        $jamMasukDetik = strtotime($activity['staff_in']);
        $jamPulangDetik = strtotime($activity['staff_out']);
        $bukaMasuk = strtotime('-2 hours', $jamMasukDetik);
        $tutupMasuk = strtotime('+3 hours', $jamMasukDetik);
        $bukaPulang = strtotime('+4 hours', $jamMasukDetik);
        $tutupPulang = strtotime('+7 hours', $jamPulangDetik);

        if ($waktuSekarangDetik < $bukaMasuk) {
            return [
                'success' => false,
                'error'   => 'too_early',
                'message' => 'Belum waktunya absen masuk kerja! Silakan coba lagi nanti.'
            ];
        }

        if ($waktuSekarangDetik > $tutupPulang) {
            return [
                'success' => false,
                'error'   => 'session_closed',
                'message' => 'Sesi absensi hari ini sudah ditutup.'
            ];
        }

        $selfiePhoto = null;
        if (!empty($selfieBase64)) {
            $selfiePhoto = self::saveSelfie($selfieBase64, $staff['id']);
        }

        $check = $pdo->prepare("SELECT id, time_in, time_out FROM staff_attendances WHERE staff_id = ? AND activity_id = ? LIMIT 1");
        $check->execute([$staff['id'], $activity['id']]);
        $record = $check->fetch(PDO::FETCH_ASSOC);

        $scanType = '';
        $badgeText = '';
        $badgeClass = '';
        $isLembur = false;
        $lemburMsg = '';
        $message = '';

        if ($waktuSekarangDetik <= $tutupMasuk) {
            // Absen Masuk
            $scanType = 'masuk';
            $statusKehadiran = ($timeNow > $activity['staff_late']) ? 'terlambat' : 'tepat_waktu';
            $badgeText = ($statusKehadiran === 'tepat_waktu') ? '✓ TEPAT WAKTU' : '⚠ TERLAMBAT';
            $badgeClass = ($statusKehadiran === 'tepat_waktu') ? 'ontime' : 'late';

            if (!$record) {
                $insert = $pdo->prepare("
                    INSERT INTO staff_attendances 
                    (staff_id, activity_id, attendance_date, time_in, scan_code, scanned_by, latitude, longitude, distance_meters, attendance_method, selfie_photo, status) 
                    VALUES (?, ?, ?, ?, 'GPS_MOBILE', ?, ?, ?, ?, 'mobile_gps', ?, ?)
                ");
                $insert->execute([$staff['id'], $activity['id'], $dateToday, $timeNow, $scannedBy, $lat, $lng, $distance, $selfiePhoto, $statusKehadiran]);
                $message = 'Absen masuk berhasil dicatat.';
            } else {
                return [
                    'success' => false,
                    'error'   => 'already_in',
                    'message' => 'Anda sudah melakukan absen masuk hari ini pukul ' . substr($record['time_in'], 0, 5) . '.'
                ];
            }
        } else {
            // Absen Pulang
            $scanType = 'pulang';
            $selisihPulang = $waktuSekarangDetik - $jamPulangDetik;

            if ($selisihPulang < 0) {
                $badgeText = '⚠ PULANG CEPAT';
                $badgeClass = 'late';
            } elseif ($selisihPulang >= 3600) {
                $badgeText = '⏱ LEMBUR YAA';
                $badgeClass = 'ontime';
            } else {
                $badgeText = '✓ TEPAT WAKTU';
                $badgeClass = 'ontime';
            }

            if (!$record) {
                $insert = $pdo->prepare("
                    INSERT INTO staff_attendances 
                    (staff_id, activity_id, attendance_date, time_in, time_out, scan_code, scanned_by, latitude, longitude, distance_meters, attendance_method, selfie_photo, status) 
                    VALUES (?, ?, ?, NULL, ?, 'GPS_MOBILE', ?, ?, ?, ?, 'mobile_gps', ?, 'terlambat')
                ");
                $insert->execute([$staff['id'], $activity['id'], $dateToday, $timeNow, $scannedBy, $lat, $lng, $distance, $selfiePhoto]);
                $message = 'Anda tidak absen masuk pagi ini, namun absen pulang berhasil dicatat.';

                if ($selisihPulang >= 3600) {
                    $isLembur = true;
                    $lemburMsg = 'Sesi lembur telah tercatat ke dalam sistem.';
                    $insertLembur = $pdo->prepare("INSERT INTO staff_overtimes (staff_id, activity_id, overtime_date, time_out) VALUES (?, ?, ?, ?)");
                    $insertLembur->execute([$staff['id'], $activity['id'], $dateToday, $timeNow]);
                }
            } elseif (is_null($record['time_out'])) {
                $selisihMenitMasuk = round(abs($waktuSekarangDetik - (empty($record['time_in']) ? 0 : strtotime($record['time_in']))) / 60);
                if (!empty($record['time_in']) && $selisihMenitMasuk < 30) {
                    return [
                        'success' => false,
                        'error'   => 'interval_short',
                        'message' => 'Anda baru saja absen masuk. Beri jeda waktu minimal 30 menit.'
                    ];
                } elseif ($waktuSekarangDetik < $bukaPulang) {
                    return [
                        'success' => false,
                        'error'   => 'too_early_out',
                        'message' => 'Belum waktunya absen pulang. Masih jam operasional kerja.'
                    ];
                } else {
                    $update = $pdo->prepare("
                        UPDATE staff_attendances 
                        SET time_out = ?, latitude = ?, longitude = ?, distance_meters = ?, attendance_method = 'mobile_gps', selfie_photo = COALESCE(?, selfie_photo)
                        WHERE id = ?
                    ");
                    $update->execute([$timeNow, $lat, $lng, $distance, $selfiePhoto, $record['id']]);
                    $message = 'Absen pulang berhasil dicatat.';

                    if ($selisihPulang >= 3600) {
                        $isLembur = true;
                        $lemburMsg = 'Sesi lembur telah tercatat ke dalam sistem.';
                        $insertLembur = $pdo->prepare("INSERT INTO staff_overtimes (staff_id, activity_id, overtime_date, time_out) VALUES (?, ?, ?, ?)");
                        $insertLembur->execute([$staff['id'], $activity['id'], $dateToday, $timeNow]);
                    }
                }
            } else {
                return [
                    'success' => false,
                    'error'   => 'already_out',
                    'message' => 'Anda sudah melakukan absen pulang hari ini pukul ' . substr($record['time_out'], 0, 5) . '.'
                ];
            }
        }

        return [
            'success'     => true,
            'type'        => 'staff',
            'name'        => $staff['name'],
            'nik'         => $staff['nik'],
            'photo'       => $staff['photo'],
            'selfie'      => $selfiePhoto,
            'time'        => $timeNow,
            'distance'    => $distance,
            'badge_text'  => $badgeText,
            'badge_class' => $badgeClass,
            'scan_type'   => $scanType,
            'is_lembur'   => $isLembur,
            'lembur_msg'  => $lemburMsg,
            'message'     => $message,
            'sound'       => 'success'
        ];
    }
}
