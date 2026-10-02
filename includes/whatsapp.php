<?php
/**
 * HELPER INTEGRASI WHATSAPP GATEWAY (FONNTE API)
 * https://fonnte.com/
 *
 * Fitur:
 * 1. Global Enable / Disable
 * 2. Pembatasan Per Unit Sekolah
 * 3. Kontrol Per Siswa (wa_notify & parent_phone)
 * 4. Kontrol 4 Jenis Pesan Otomatis:
 *    - in       : Absen Masuk (Hadir di Sekolah)
 *    - late     : Terlambat / Belum Hadir (1 Jam Setelah Batas Masuk)
 *    - out      : Absen Pulang
 *    - out_late : Peringatan Konfirmasi Kepulangan (1 Jam Setelah Jam Pulang Belum Absen)
 */

if (!defined('FONNTE_API_URL')) {
    define('FONNTE_API_URL', 'https://api.fonnte.com/send');
}
if (!defined('FONNTE_DEVICE_URL')) {
    define('FONNTE_DEVICE_URL', 'https://api.fonnte.com/device');
}

/**
 * Ambil semua pengaturan WhatsApp dari database
 */
function getWaSettings(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $stmt = $pdo->query("SELECT key_name, key_value FROM wa_settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key_name']] = $row['key_value'];
    }
    $cache = $settings;
    return $settings;
}

/**
 * Simpan / perbarui satu key pengaturan
 */
function updateWaSetting(PDO $pdo, string $key, string $value): bool {
    $stmt = $pdo->prepare("INSERT INTO wa_settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
    return $stmt->execute([$key, $value, $value]);
}

/**
 * Format nomor telepon ke standar internasional Indonesia (628xxx)
 */
function formatWaPhoneNumber(?string $phone): ?string {
    if (!$phone) return null;
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    if (empty($cleaned) || strlen($cleaned) < 8) return null;

    if (substr($cleaned, 0, 1) === '0') {
        $cleaned = '62' . substr($cleaned, 1);
    } elseif (substr($cleaned, 0, 2) !== '62') {
        $cleaned = '62' . $cleaned;
    }
    return $cleaned;
}

/**
 * Cek status perangkat & kuota Fonnte
 */
function checkFonnteDevice(string $token): array {
    if (empty($token)) {
        return ['success' => false, 'message' => 'Token Fonnte belum diisi'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => FONNTE_DEVICE_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . trim($token)
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => 'Gagal terhubung ke Fonnte: ' . $err];
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        return ['success' => false, 'message' => 'Respon tidak valid dari server Fonnte (HTTP ' . $httpCode . ')', 'raw' => $response];
    }

    return [
        'success' => true,
        'status' => $json['status'] ?? false,
        'device' => $json['device'] ?? '-',
        'device_status' => $json['device_status'] ?? ($json['status'] ? 'connect' : 'disconnect'),
        'quota' => $json['quota'] ?? 0,
        'expired' => $json['expired'] ?? '-',
        'messages' => $json['messages'] ?? 0,
        'raw' => $json
    ];
}

/**
 * Kirim pesan WhatsApp melalui Fonnte API
 */
function sendRawFonnteMessage(string $token, string $target, string $message, string $countryCode = '62'): array {
    $target = formatWaPhoneNumber($target);
    if (!$target) {
        return ['success' => false, 'message' => 'Nomor WhatsApp tujuan tidak valid.'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => FONNTE_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'target' => $target,
            'message' => $message,
            'countryCode' => $countryCode
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . trim($token)
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => 'Koneksi error: ' . $err, 'raw' => $err];
    }

    $json = json_decode($response, true);
    $isSuccess = is_array($json) && !empty($json['status']);

    return [
        'success' => $isSuccess,
        'message' => $json['reason'] ?? ($isSuccess ? 'Pesan terkirim ke antrean Fonnte' : 'Gagal mengirim pesan'),
        'http_code' => $httpCode,
        'raw' => $response
    ];
}

/**
 * Ambil data lengkap siswa termasuk kelas, grade, unit, dan nomor WA orang tua
 */
function getStudentWaDetails(PDO $pdo, int $studentId): ?array {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.nis, s.nik, s.parent_phone, s.wa_notify,
               cg.id AS class_group_id, cg.name AS class_name,
               g.id AS grade_id, g.grade,
               un.id AS unit_id, un.unit AS unit_name
        FROM students s
        LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
        LEFT JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
        LEFT JOIN class_groups cg ON cg.id = se.class_group_id
        LEFT JOIN grades g ON g.id = cg.grade_id
        LEFT JOIN units un ON un.id = g.unit_id
        WHERE s.id = ? AND s.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Kirim Notifikasi WhatsApp Siswa berdasarkan Jenis Pesan
 * 
 * $type: 'in' | 'late' | 'out' | 'out_late' | 'test'
 */
function sendStudentWaNotification(PDO $pdo, int $studentId, string $type, array $extraData = []): array {
    $settings = getWaSettings($pdo);

    // 1. Cek Sakelar Global
    if (empty($settings['wa_enabled']) || $settings['wa_enabled'] !== '1') {
        return ['success' => false, 'skipped' => true, 'reason' => 'Integrasi WhatsApp sedang dinonaktifkan secara global'];
    }

    $token = trim($settings['fonnte_token'] ?? '');
    if (empty($token)) {
        return ['success' => false, 'skipped' => true, 'reason' => 'Token API Fonnte belum disetel'];
    }

    // 2. Ambil data siswa
    $student = getStudentWaDetails($pdo, $studentId);
    if (!$student) {
        return ['success' => false, 'skipped' => true, 'reason' => 'Data siswa tidak ditemukan'];
    }

    // 3. Cek apakah nomor WA orang tua tersedia
    $phone = formatWaPhoneNumber($student['parent_phone'] ?? '');
    if (!$phone) {
        return ['success' => false, 'skipped' => true, 'reason' => 'Nomor WA orang tua belum diisi untuk siswa: ' . $student['name']];
    }

    // 4. Cek kontrol siswa (Apakah notifikasi untuk siswa ini diaktifkan?)
    if (isset($student['wa_notify']) && (int)$student['wa_notify'] === 0) {
        return ['success' => false, 'skipped' => true, 'reason' => 'Notifikasi WA untuk siswa ini telah dinonaktifkan oleh Admin/Kepsek'];
    }

    $unitId = (int)($student['unit_id'] ?? 0);

    // 5. Cek Pembatasan Per Unit
    if ($unitId > 0) {
        $stmtUnit = $pdo->prepare("SELECT is_enabled, msg_in_enabled, msg_late_enabled, msg_out_enabled, msg_out_late_enabled FROM wa_unit_settings WHERE unit_id = ? LIMIT 1");
        $stmtUnit->execute([$unitId]);
        $unitConf = $stmtUnit->fetch(PDO::FETCH_ASSOC);

        if ($unitConf) {
            if ((int)$unitConf['is_enabled'] === 0) {
                return ['success' => false, 'skipped' => true, 'reason' => "Pengiriman WhatsApp dibatasi (dinonaktifkan) untuk Unit {$student['unit_name']}"];
            }

            // Cek sakelar jenis pesan pada level unit
            $unitMsgKey = "msg_{$type}_enabled";
            if (isset($unitConf[$unitMsgKey]) && (int)$unitConf[$unitMsgKey] === 0) {
                return ['success' => false, 'skipped' => true, 'reason' => "Jenis pesan '{$type}' dinonaktifkan untuk Unit {$student['unit_name']}"];
            }
        }
    }

    // 6. Cek Sakelar Jenis Pesan Global (Apakah Pesan Ini Diaktifkan?)
    $globalMsgKey = "msg_{$type}_enabled";
    if (isset($settings[$globalMsgKey]) && $settings[$globalMsgKey] !== '1') {
        return ['success' => false, 'skipped' => true, 'reason' => "Jenis pesan '{$type}' sedang dinonaktifkan oleh Admin/Kepsek"];
    }

    $dateToday = date('Y-m-d');

    // 7. Cek Duplikasi Pesan (Khusus 'late' dan 'out_late', kirim maksimal 1 kali per hari)
    if (in_array($type, ['late', 'out_late'])) {
        $stmtDup = $pdo->prepare("
            SELECT id FROM wa_message_logs 
            WHERE student_id = ? AND sent_date = ? AND message_type = ? AND status = 'success'
            LIMIT 1
        ");
        $stmtDup->execute([$studentId, $dateToday, $type]);
        if ($stmtDup->fetch()) {
            return ['success' => true, 'skipped' => true, 'reason' => "Pesan peringatan '{$type}' sudah pernah dikirimkan hari ini untuk siswa {$student['name']}"];
        }
    }

    // 8. Siapkan Template Pesan
    $templateKey = "template_{$type}";
    $messageTemplate = $settings[$templateKey] ?? '';
    if (empty($messageTemplate)) {
        return ['success' => false, 'skipped' => true, 'reason' => "Template pesan '{$type}' kosong"];
    }

    // Replace Placeholders
    $namaHari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $tglStr = $namaHari[(int)date('w')] . ', ' . date('j') . ' ' . $namaBulan[(int)date('n')] . ' ' . date('Y');

    $statusKehadiran = $extraData['status_kehadiran'] ?? 'Tepat Waktu';
    if ($statusKehadiran === 'tepat_waktu') $statusKehadiran = 'Tepat Waktu';
    if ($statusKehadiran === 'terlambat') $statusKehadiran = 'Terlambat';

    $replacements = [
        '{nama_siswa}'       => $student['name'],
        '{nis}'              => $student['nis'] ?? '-',
        '{nik}'              => $student['nik'] ?? '-',
        '{kelas}'            => $student['class_name'] ?? '-',
        '{grade}'            => $student['grade'] ?? '-',
        '{unit}'             => $student['unit_name'] ?? '-',
        '{tanggal}'          => $tglStr,
        '{jam_absen}'        => $extraData['jam_absen'] ?? date('H:i'),
        '{status_kehadiran}' => $statusKehadiran,
        '{jam_sekarang}'     => date('H:i'),
        '{jam_batas}'        => $extraData['jam_batas'] ?? '07:00',
        '{jam_pulang}'       => $extraData['jam_pulang'] ?? '15:30',
        '{sekolah}'          => 'Sekolah'
    ];

    $finalMessage = str_replace(array_keys($replacements), array_values($replacements), $messageTemplate);

    // 9. Eksekusi Pengiriman via Fonnte API
    $apiResult = sendRawFonnteMessage($token, $phone, $finalMessage, $settings['wa_country_code'] ?? '62');
    $statusLog = $apiResult['success'] ? 'success' : 'failed';

    // 10. Catat ke Tabel wa_message_logs
    try {
        $stmtLog = $pdo->prepare("
            INSERT INTO wa_message_logs 
            (student_id, unit_id, phone_number, message_type, message_text, status, response_api, sent_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtLog->execute([
            $studentId,
            $unitId ?: null,
            $phone,
            $type,
            $finalMessage,
            $statusLog,
            $apiResult['raw'] ?? $apiResult['message'],
            $dateToday
        ]);
    } catch (Exception $e) {
        // Logging tidak boleh menghentikan alur utama
    }

    return [
        'success' => $apiResult['success'],
        'message' => $apiResult['message'],
        'phone' => $phone,
        'student_name' => $student['name'],
        'type' => $type
    ];
}

/**
 * Ambil Pengaturan Khusus Unit
 */
function getWaUnitSettings(PDO $pdo, int $unitId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM wa_unit_settings WHERE unit_id = ? LIMIT 1");
    $stmt->execute([$unitId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Pengecekan Pesan 2: Terlambat / Belum Masuk (Sesudah Jam Masuk / Batas Telat)
 * Waktu keterlambatan dapat dikonfigurasi per menit (default 30 menit sesudah batas telat)
 */
function runLateCheckReminders(PDO $pdo): int {
    $settings = getWaSettings($pdo);
    if (empty($settings['wa_enabled']) || $settings['wa_enabled'] !== '1') return 0;
    if (isset($settings['msg_late_enabled']) && $settings['msg_late_enabled'] !== '1') return 0;

    $dateToday = date('Y-m-d');
    $timeNow = date('H:i:s');
    $dayCode = date('N');

    // Ambil semua aktivitas aktif hari ini
    $stmtAct = $pdo->prepare("
        SELECT a.id, a.unit_id, a.student_in, a.student_late, a.name AS act_name, u.unit AS unit_name
        FROM activities a
        JOIN units u ON u.id = a.unit_id
        WHERE a.activity_date = ? AND a.status = 'active' AND a.is_holiday = 'no'
    ");
    $stmtAct->execute([$dateToday]);
    $activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

    $sentCount = 0;

    foreach ($activities as $act) {
        $unitId = (int)$act['unit_id'];
        
        // Cek setting unit jika ada
        $unitSetting = getWaUnitSettings($pdo, $unitId);
        if ($unitSetting) {
            if ((int)$unitSetting['is_enabled'] === 0) continue;
            if (isset($unitSetting['msg_late_enabled']) && (int)$unitSetting['msg_late_enabled'] === 0) continue;
        }

        // Tentukan patokan jam (student_late atau student_in)
        $refType = $unitSetting['msg_late_ref'] ?? $settings['msg_late_ref'] ?? 'student_late';
        $baseTime = ($refType === 'student_in' && !empty($act['student_in'])) ? $act['student_in'] : $act['student_late'];
        if (empty($baseTime)) continue;

        // Ambil delay menit (prioritas unit -> global -> default 30 menit)
        $delayMinutes = isset($unitSetting['msg_late_delay_minutes']) && $unitSetting['msg_late_delay_minutes'] !== null && $unitSetting['msg_late_delay_minutes'] !== ''
            ? (int)$unitSetting['msg_late_delay_minutes']
            : (int)($settings['msg_late_delay_minutes'] ?? 30);
        if ($delayMinutes < 0) $delayMinutes = 30;

        // Hitung waktu pengiriman target: baseTime + delayMinutes
        $targetSendTime = date('H:i:s', strtotime("+{$delayMinutes} minutes", strtotime($baseTime)));

        // Jika waktu sekarang sudah melewati waktu target pengiriman
        if ($timeNow >= $targetSendTime) {
            // Ambil semua siswa di unit ini yang WA-nya aktif dan belum pernah absen masuk hari ini
            $stmtMissing = $pdo->prepare("
                SELECT s.id, s.name, s.parent_phone
                FROM students s
                INNER JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
                INNER JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
                INNER JOIN class_groups cg ON cg.id = se.class_group_id
                INNER JOIN grades g ON g.id = cg.grade_id
                WHERE s.deleted_at IS NULL 
                  AND g.unit_id = ?
                  AND s.wa_notify = 1
                  AND s.parent_phone IS NOT NULL AND s.parent_phone != ''
                  AND NOT EXISTS (
                      SELECT 1 FROM student_attendances sa 
                      WHERE sa.student_id = s.id AND sa.attendance_date = ? AND sa.time_in IS NOT NULL AND sa.time_in != '00:00:00'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM wa_message_logs wl 
                      WHERE wl.student_id = s.id AND wl.sent_date = ? AND wl.message_type = 'late'
                  )
                LIMIT 50
            ");
            $stmtMissing->execute([$unitId, $dateToday, $dateToday]);
            $missingStudents = $stmtMissing->fetchAll(PDO::FETCH_ASSOC);

            foreach ($missingStudents as $mStudent) {
                $res = sendStudentWaNotification($pdo, (int)$mStudent['id'], 'late', [
                    'jam_batas' => substr($baseTime, 0, 5)
                ]);
                if (!empty($res['success'])) {
                    $sentCount++;
                }
            }
        }
    }

    return $sentCount;
}

/**
 * Pengecekan Pesan 4: Konfirmasi Kepulangan (Berapa Menit Ketika Lewat Jam Pulang)
 * Waktu jeda kepulangan dapat dikonfigurasi per menit (default 45 menit sesudah jam pulang)
 */
function runDepartureCheckReminders(PDO $pdo): int {
    $settings = getWaSettings($pdo);
    if (empty($settings['wa_enabled']) || $settings['wa_enabled'] !== '1') return 0;
    if (isset($settings['msg_out_late_enabled']) && $settings['msg_out_late_enabled'] !== '1') return 0;

    $dateToday = date('Y-m-d');
    $timeNow = date('H:i:s');

    // Ambil aktivitas hari ini
    $stmtAct = $pdo->prepare("
        SELECT a.id, a.unit_id, a.student_out, a.name AS act_name, u.unit AS unit_name
        FROM activities a
        JOIN units u ON u.id = a.unit_id
        WHERE a.activity_date = ? AND a.status = 'active' AND a.is_holiday = 'no'
    ");
    $stmtAct->execute([$dateToday]);
    $activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

    $sentCount = 0;

    foreach ($activities as $act) {
        $unitId = (int)$act['unit_id'];
        $jamPulang = $act['student_out'];
        if (empty($jamPulang)) continue;

        // Cek setting unit jika ada
        $unitSetting = getWaUnitSettings($pdo, $unitId);
        if ($unitSetting) {
            if ((int)$unitSetting['is_enabled'] === 0) continue;
            if (isset($unitSetting['msg_out_late_enabled']) && (int)$unitSetting['msg_out_late_enabled'] === 0) continue;
        }

        // Ambil delay menit (prioritas unit -> global -> default 45 menit)
        $delayMinutes = isset($unitSetting['msg_out_late_delay_minutes']) && $unitSetting['msg_out_late_delay_minutes'] !== null && $unitSetting['msg_out_late_delay_minutes'] !== ''
            ? (int)$unitSetting['msg_out_late_delay_minutes']
            : (int)($settings['msg_out_late_delay_minutes'] ?? 45);
        if ($delayMinutes < 0) $delayMinutes = 45;

        // Hitung waktu target kirim: jamPulang + delayMinutes
        $targetSendTime = date('H:i:s', strtotime("+{$delayMinutes} minutes", strtotime($jamPulang)));

        // Jika waktu sekarang sudah melewati waktu target kirim kepulangan
        if ($timeNow >= $targetSendTime) {
            // Ambil siswa yang hadir pagi ini (atau terdaftar), namun belum pernah scan pulang
            $stmtNoOut = $pdo->prepare("
                SELECT s.id, s.name, s.parent_phone
                FROM students s
                INNER JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
                INNER JOIN academic_years ay ON ay.id = se.academic_year_id AND ay.status = 'active'
                INNER JOIN class_groups cg ON cg.id = se.class_group_id
                INNER JOIN grades g ON g.id = cg.grade_id
                WHERE s.deleted_at IS NULL 
                  AND g.unit_id = ?
                  AND s.wa_notify = 1
                  AND s.parent_phone IS NOT NULL AND s.parent_phone != ''
                  AND EXISTS (
                      SELECT 1 FROM student_attendances sa 
                      WHERE sa.student_id = s.id AND sa.attendance_date = ? AND sa.time_in IS NOT NULL AND sa.time_in != '00:00:00'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM student_attendances sa2 
                      WHERE sa2.student_id = s.id AND sa2.attendance_date = ? AND sa2.time_out IS NOT NULL AND sa2.time_out != '00:00:00'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM wa_message_logs wl 
                      WHERE wl.student_id = s.id AND wl.sent_date = ? AND wl.message_type = 'out_late'
                  )
                LIMIT 50
            ");
            $stmtNoOut->execute([$unitId, $dateToday, $dateToday, $dateToday]);
            $noOutStudents = $stmtNoOut->fetchAll(PDO::FETCH_ASSOC);

            foreach ($noOutStudents as $noStudent) {
                $res = sendStudentWaNotification($pdo, (int)$noStudent['id'], 'out_late', [
                    'jam_pulang' => substr($jamPulang, 0, 5)
                ]);
                if (!empty($res['success'])) {
                    $sentCount++;
                }
            }
        }
    }

    return $sentCount;
}

/**
 * Format detik ke format Jam & Menit yang ramah dibaca
 */
function formatSecondsToHoursMinutes(int $seconds): string {
    if ($seconds <= 0) return '0 Menit';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = [];
    if ($hours > 0) $parts[] = "{$hours} Jam";
    if ($minutes > 0 || empty($parts)) $parts[] = "{$minutes} Menit";
    return implode(' ', $parts);
}

/**
 * Ambil data rekapitulasi kehadiran dan lembur staff untuk periode tertentu
 */
function getStaffMonthlyRecap(PDO $pdo, string $startDate, string $endDate, ?int $unitId = null, ?int $staffId = null): array {
    $whereStaff = ["s.deleted_at IS NULL"];
    $paramsStaff = [];

    if ($staffId !== null && $staffId > 0) {
        $whereStaff[] = "s.id = ?";
        $paramsStaff[] = $staffId;
    }
    if ($unitId !== null && $unitId > 0) {
        $whereStaff[] = "s.unit_id = ?";
        $paramsStaff[] = $unitId;
    }

    $whereStaffSql = implode(' AND ', $whereStaff);
    $stmtStaff = $pdo->prepare("
        SELECT s.id, s.name, s.nik, s.phone, s.wa_notify, s.unit_id, un.unit as unit_name
        FROM staff s
        LEFT JOIN units un ON un.id = s.unit_id
        WHERE {$whereStaffSql}
        ORDER BY un.unit, s.name
    ");
    $stmtStaff->execute($paramsStaff);
    $staffList = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($staffList as $st) {
        $sid = (int)$st['id'];
        $sUnitId = (int)($st['unit_id'] ?? 0);

        // 1. Hitung total hari kerja aktif pada periode ini untuk unit staff
        $stmtKbm = $pdo->prepare("
            SELECT COUNT(DISTINCT activity_date) 
            FROM activities 
            WHERE unit_id = ? AND activity_date BETWEEN ? AND ? 
              AND status = 'active' AND is_holiday = 'no'
        ");
        $stmtKbm->execute([$sUnitId, $startDate, $endDate]);
        $totalHariKerja = (int)$stmtKbm->fetchColumn();
        if ($totalHariKerja <= 0) {
            // Fallback: hitung hari kerja weekday (Senin-Jumat) dalam rentang tanggal
            $startTs = strtotime($startDate);
            $endTs = strtotime($endDate);
            $weekdays = 0;
            for ($d = $startTs; $d <= $endTs; $d = strtotime('+1 day', $d)) {
                $dayOfWeek = (int)date('N', $d);
                if ($dayOfWeek <= 5) $weekdays++;
            }
            $totalHariKerja = max(1, $weekdays);
        }

        // 2. Hitung statistik presensi staff pada periode
        $stmtAtt = $pdo->prepare("
            SELECT status, COUNT(*) as cnt
            FROM staff_attendances
            WHERE staff_id = ? AND attendance_date BETWEEN ? AND ?
              AND (NULLIF(time_in, '00:00:00') IS NOT NULL OR NULLIF(time_out, '00:00:00') IS NOT NULL)
            GROUP BY status
        ");
        $stmtAtt->execute([$sid, $startDate, $endDate]);
        $attRows = $stmtAtt->fetchAll(PDO::FETCH_KEY_PAIR);

        $tepatWaktu = (int)($attRows['tepat_waktu'] ?? 0);
        $terlambat = (int)($attRows['terlambat'] ?? 0);
        $izin = (int)($attRows['izin'] ?? 0);
        $sakit = (int)($attRows['sakit'] ?? 0);
        $totalHadir = $tepatWaktu + $terlambat;
        $izinSakit = $izin + $sakit;
        
        $alpa = max(0, $totalHariKerja - ($totalHadir + $izinSakit));
        $persentaseKehadiran = round(($totalHadir / max(1, $totalHariKerja)) * 100);

        // 3. Hitung lembur staff dari tabel staff_overtimes
        $stmtOt = $pdo->prepare("
            SELECT so.overtime_date, so.time_out as actual_out, a.staff_out as normal_out
            FROM staff_overtimes so
            LEFT JOIN activities a ON a.id = so.activity_id
            WHERE so.staff_id = ? AND so.overtime_date BETWEEN ? AND ?
        ");
        $stmtOt->execute([$sid, $startDate, $endDate]);
        $otRows = $stmtOt->fetchAll(PDO::FETCH_ASSOC);

        $totalDetikLembur = 0;
        $frekuensiLembur = 0;

        foreach ($otRows as $ot) {
            $norm = !empty($ot['normal_out']) ? strtotime($ot['normal_out']) : 0;
            $act = !empty($ot['actual_out']) ? strtotime($ot['actual_out']) : 0;
            if ($norm > 0 && $act > $norm) {
                $durasi = $act - $norm;
                $totalDetikLembur += $durasi;
                $frekuensiLembur++;
            }
        }

        // Jika staff_overtimes kosong, cek juga dari staff_attendances (lembur otomatis jika pulang > jadwal pulang)
        if ($frekuensiLembur === 0) {
            $stmtAttOt = $pdo->prepare("
                SELECT sa.attendance_date, sa.time_out as actual_out, a.staff_out as normal_out
                FROM staff_attendances sa
                LEFT JOIN activities a ON a.id = sa.activity_id
                WHERE sa.staff_id = ? AND sa.attendance_date BETWEEN ? AND ?
                  AND sa.time_out IS NOT NULL AND sa.time_out != '00:00:00'
            ");
            $stmtAttOt->execute([$sid, $startDate, $endDate]);
            foreach ($stmtAttOt->fetchAll(PDO::FETCH_ASSOC) as $aot) {
                $norm = !empty($aot['normal_out']) ? strtotime($aot['normal_out']) : 0;
                $act = !empty($aot['actual_out']) ? strtotime($aot['actual_out']) : 0;
                if ($norm > 0 && $act > ($norm + 900)) { // minimal lembur 15 menit
                    $totalDetikLembur += ($act - $norm);
                    $frekuensiLembur++;
                }
            }
        }

        $results[] = [
            'staff_id'             => $sid,
            'name'                 => $st['name'],
            'nik'                  => $st['nik'],
            'phone'                => $st['phone'],
            'wa_notify'            => (int)($st['wa_notify'] ?? 1),
            'unit_id'              => $sUnitId,
            'unit_name'            => $st['unit_name'] ?? '-',
            'total_hari_kerja'     => $totalHariKerja,
            'total_hadir'          => $totalHadir,
            'tepat_waktu'          => $tepatWaktu,
            'terlambat'            => $terlambat,
            'izin'                 => $izin,
            'sakit'                => $sakit,
            'izin_sakit'           => $izinSakit,
            'alpa'                 => $alpa,
            'persentase_kehadiran' => $persentaseKehadiran . '%',
            'frekuensi_lembur'     => $frekuensiLembur,
            'total_detik_lembur'   => $totalDetikLembur,
            'total_lembur'         => formatSecondsToHoursMinutes($totalDetikLembur),
            'has_phone'            => !empty($st['phone'])
        ];
    }

    return $results;
}

/**
 * Kirim Pesan WhatsApp Rekapitulasi Bulanan Ke Staff
 */
function sendStaffMonthlyRecapNotification(PDO $pdo, int $staffId, string $startDate, string $endDate, ?string $customTemplate = null): array {
    $settings = getWaSettings($pdo);

    // 1. Cek Sakelar Global
    if (empty($settings['wa_enabled']) || $settings['wa_enabled'] !== '1') {
        return ['success' => false, 'skipped' => true, 'reason' => 'Integrasi WhatsApp sedang dinonaktifkan secara global'];
    }
    if (isset($settings['staff_recap_enabled']) && $settings['staff_recap_enabled'] !== '1') {
        return ['success' => false, 'skipped' => true, 'reason' => 'Fitur Rekapan Bulanan Staff sedang dinonaktifkan'];
    }

    $token = trim($settings['fonnte_token'] ?? '');
    if (empty($token)) {
        return ['success' => false, 'skipped' => true, 'reason' => 'Token API Fonnte belum disetel'];
    }

    // 2. Ambil data rekap staff ini
    $recapDataList = getStaffMonthlyRecap($pdo, $startDate, $endDate, null, $staffId);
    if (empty($recapDataList)) {
        return ['success' => false, 'skipped' => true, 'reason' => 'Data staff tidak ditemukan'];
    }
    $recap = $recapDataList[0];

    // 3. Cek Nomor Telepon & Sakelar Staff
    $phone = formatWaPhoneNumber($recap['phone'] ?? '');
    if (!$phone) {
        return ['success' => false, 'skipped' => true, 'reason' => 'Nomor WhatsApp belum diisi untuk staff: ' . $recap['name']];
    }
    if ((int)$recap['wa_notify'] === 0) {
        return ['success' => false, 'skipped' => true, 'reason' => 'Notifikasi WhatsApp dinonaktifkan untuk staff ini'];
    }

    // 4. Cek Pembatasan Unit
    $unitId = (int)$recap['unit_id'];
    if ($unitId > 0) {
        $stmtUnit = $pdo->prepare("SELECT is_enabled, staff_recap_enabled FROM wa_unit_settings WHERE unit_id = ? LIMIT 1");
        $stmtUnit->execute([$unitId]);
        $unitConf = $stmtUnit->fetch(PDO::FETCH_ASSOC);

        if ($unitConf) {
            if ((int)$unitConf['is_enabled'] === 0) {
                return ['success' => false, 'skipped' => true, 'reason' => "Pengiriman WhatsApp dibatasi untuk Unit {$recap['unit_name']}"];
            }
            if (isset($unitConf['staff_recap_enabled']) && (int)$unitConf['staff_recap_enabled'] === 0) {
                return ['success' => false, 'skipped' => true, 'reason' => "Rekapan bulanan staff dinonaktifkan untuk Unit {$recap['unit_name']}"];
            }
        }
    }

    // 5. Format Periode String
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $startFmt = date('j', strtotime($startDate)) . ' ' . $namaBulan[(int)date('n', strtotime($startDate))] . ' ' . date('Y', strtotime($startDate));
    $endFmt = date('j', strtotime($endDate)) . ' ' . $namaBulan[(int)date('n', strtotime($endDate))] . ' ' . date('Y', strtotime($endDate));
    $periodeStr = ($startDate === $endDate) ? $startFmt : "{$startFmt} s/d {$endFmt}";

    // 6. Template Pesan
    $template = $customTemplate ?: ($settings['staff_recap_template'] ?? '');
    if (empty($template)) {
        $template = "Halo Bapak/Ibu *{nama_staff}*,\nBerikut adalah rekapan kehadiran & lembur Anda untuk periode *{periode}* di *{unit}*:\n\n📋 *Ringkasan Presensi:*\n- Total Hari Hadir: *{total_hadir} hari* ({persentase_kehadiran})\n- Tepat Waktu: *{tepat_waktu}*\n- Terlambat: *{terlambat}*\n- Izin / Sakit: *{izin_sakit}*\n- Alpa: *{alpa}*\n\n⏱️ *Rekapitulasi Lembur:*\n- Total Durasi Lembur: *{total_lembur}*\n- Frekuensi Lembur: *{frekuensi_lembur} kali*\n\nTerima kasih atas dedikasi dan kerja keras Anda.";
    }

    $replacements = [
        '{nama_staff}'           => $recap['name'],
        '{nik}'                  => $recap['nik'] ?: '-',
        '{unit}'                 => $recap['unit_name'] ?: 'Sekolah',
        '{periode}'              => $periodeStr,
        '{total_hari_kerja}'     => $recap['total_hari_kerja'],
        '{total_hadir}'          => $recap['total_hadir'],
        '{tepat_waktu}'          => $recap['tepat_waktu'],
        '{terlambat}'            => $recap['terlambat'],
        '{izin}'                 => $recap['izin'],
        '{sakit}'                => $recap['sakit'],
        '{izin_sakit}'           => $recap['izin_sakit'],
        '{alpa}'                 => $recap['alpa'],
        '{persentase_kehadiran}' => $recap['persentase_kehadiran'],
        '{total_lembur}'         => $recap['total_lembur'],
        '{frekuensi_lembur}'     => $recap['frekuensi_lembur']
    ];

    $finalMessage = str_replace(array_keys($replacements), array_values($replacements), $template);

    // 7. Kirim via Fonnte API
    $apiResult = sendRawFonnteMessage($token, $phone, $finalMessage, $settings['wa_country_code'] ?? '62');
    $statusLog = $apiResult['success'] ? 'success' : 'failed';

    // 8. Log ke wa_message_logs
    try {
        $stmtLog = $pdo->prepare("
            INSERT INTO wa_message_logs 
            (student_id, staff_id, unit_id, phone_number, message_type, message_text, status, response_api, sent_date)
            VALUES (NULL, ?, ?, ?, 'staff_monthly_recap', ?, ?, ?, CURDATE())
        ");
        $stmtLog->execute([
            $staffId,
            $unitId ?: null,
            $phone,
            $finalMessage,
            $statusLog,
            $apiResult['raw'] ?? $apiResult['message']
        ]);
    } catch (Exception $e) {}

    return [
        'success'     => $apiResult['success'],
        'message'     => $apiResult['message'],
        'phone'       => $phone,
        'staff_name'  => $recap['name'],
        'type'        => 'staff_monthly_recap'
    ];
}

