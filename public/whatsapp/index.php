<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/whatsapp.php';

requireLogin();
checkUserAccess('whatsapp_notif');

$pageTitle = 'Integrasi Notifikasi WhatsApp (Fonnte)';
$currentUserId = currentUserId();
$currentRole = currentRole();
$isSuperAdmin = ($currentRole === 'super_admin');
$isKepsek = ($currentRole === 'kepala_sekolah');

// Cek Unit Kepala Sekolah
$ksUnitId = null;
$ksUnitName = '';
if ($isKepsek) {
    $stmtKs = $pdo->prepare("
        SELECT aup.unit_id, u.unit 
        FROM admin_unit_permissions aup
        JOIN units u ON u.id = aup.unit_id
        WHERE aup.user_id = ? LIMIT 1
    ");
    $stmtKs->execute([$currentUserId]);
    $ksRow = $stmtKs->fetch(PDO::FETCH_ASSOC);
    if ($ksRow) {
        $ksUnitId = (int)$ksRow['unit_id'];
        $ksUnitName = $ksRow['unit'];
    }
}

// =========================================================================
// PROSES POST ACTION
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Simpan Pengaturan Global & Token Fonnte (Khusus Super Admin)
    if ($action === 'save_global_settings') {
        if (!$isSuperAdmin) {
            flash('error', 'Hanya Super Admin yang berhak mengubah token dan pengaturan utama API.');
            redirect('index.php?tab=settings');
        }

        $waEnabled = isset($_POST['wa_enabled']) ? '1' : '0';
        $fonnteToken = trim($_POST['fonnte_token'] ?? '');
        $countryCode = trim($_POST['wa_country_code'] ?? '62');

        updateWaSetting($pdo, 'wa_enabled', $waEnabled);
        updateWaSetting($pdo, 'fonnte_token', $fonnteToken);
        updateWaSetting($pdo, 'wa_country_code', $countryCode ?: '62');

        flash('success', 'Konfigurasi Utama WhatsApp Fonnte berhasil disimpan.');
        redirect('index.php?tab=settings');
    }

    // 2. Simpan Sakelar 4 Pesan, Waktu Pengiriman & Template
    if ($action === 'save_message_settings') {
        $msgIn = isset($_POST['msg_in_enabled']) ? '1' : '0';
        $msgLate = isset($_POST['msg_late_enabled']) ? '1' : '0';
        $msgOut = isset($_POST['msg_out_enabled']) ? '1' : '0';
        $msgOutLate = isset($_POST['msg_out_late_enabled']) ? '1' : '0';

        $msgInTiming = trim($_POST['msg_in_timing'] ?? 'realtime');
        $msgLateTiming = trim($_POST['msg_late_timing'] ?? 'after_late');
        $msgLateRef = in_array($_POST['msg_late_ref'] ?? '', ['student_late', 'student_in']) ? $_POST['msg_late_ref'] : 'student_late';
        $msgLateDelay = max(1, (int)($_POST['msg_late_delay_minutes'] ?? 30));
        $msgOutTiming = trim($_POST['msg_out_timing'] ?? 'realtime');
        $msgOutLateTiming = trim($_POST['msg_out_late_timing'] ?? 'after_out');
        $msgOutLateDelay = max(1, (int)($_POST['msg_out_late_delay_minutes'] ?? 45));

        if ($isSuperAdmin) {
            updateWaSetting($pdo, 'msg_in_enabled', $msgIn);
            updateWaSetting($pdo, 'msg_late_enabled', $msgLate);
            updateWaSetting($pdo, 'msg_out_enabled', $msgOut);
            updateWaSetting($pdo, 'msg_out_late_enabled', $msgOutLate);

            updateWaSetting($pdo, 'msg_in_timing', $msgInTiming);
            updateWaSetting($pdo, 'msg_late_timing', $msgLateTiming);
            updateWaSetting($pdo, 'msg_late_ref', $msgLateRef);
            updateWaSetting($pdo, 'msg_late_delay_minutes', (string)$msgLateDelay);
            updateWaSetting($pdo, 'msg_out_timing', $msgOutTiming);
            updateWaSetting($pdo, 'msg_out_late_timing', $msgOutLateTiming);
            updateWaSetting($pdo, 'msg_out_late_delay_minutes', (string)$msgOutLateDelay);

            if (isset($_POST['template_in'])) updateWaSetting($pdo, 'template_in', trim($_POST['template_in']));
            if (isset($_POST['template_late'])) updateWaSetting($pdo, 'template_late', trim($_POST['template_late']));
            if (isset($_POST['template_out'])) updateWaSetting($pdo, 'template_out', trim($_POST['template_out']));
            if (isset($_POST['template_out_late'])) updateWaSetting($pdo, 'template_out_late', trim($_POST['template_out_late']));

            flash('success', 'Pengaturan 4 Jenis Pesan Otomatis, Waktu Pengiriman & Template berhasil diperbarui.');
        } elseif ($isKepsek && $ksUnitId) {
            // Kepala Sekolah mengatur status & waktu kirim 4 pesan untuk unitnya
            $stmtUpUnit = $pdo->prepare("
                UPDATE wa_unit_settings 
                SET msg_in_enabled = ?, msg_late_enabled = ?, msg_out_enabled = ?, msg_out_late_enabled = ?,
                    msg_late_delay_minutes = ?, msg_out_late_delay_minutes = ?, msg_late_ref = ?
                WHERE unit_id = ?
            ");
            $stmtUpUnit->execute([$msgIn, $msgLate, $msgOut, $msgOutLate, $msgLateDelay, $msgOutLateDelay, $msgLateRef, $ksUnitId]);
            flash('success', "Pengaturan status & waktu pengiriman pesan untuk Unit {$ksUnitName} berhasil diperbarui.");
        }

        redirect('index.php?tab=messages');
    }

    // 3. Toggle Status Pembatasan Unit (Super Admin & Kepala Sekolah)
    if ($action === 'toggle_unit_status') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $newStatus = (int)($_POST['is_enabled'] ?? 1);

        if ($isKepsek && $unitId !== $ksUnitId) {
            flash('error', 'Akses ditolak. Anda hanya dapat mengatur unit Anda sendiri.');
            redirect('index.php?tab=units');
        }

        $stmtToggle = $pdo->prepare("
            INSERT INTO wa_unit_settings (unit_id, is_enabled) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE is_enabled = ?
        ");
        $stmtToggle->execute([$unitId, $newStatus, $newStatus]);

        $statusLabel = $newStatus ? 'DIAKTIFKAN' : 'DINONAKTIFKAN (DIBATASI)';
        flash('success', "Layanan WhatsApp untuk unit berhasil {$statusLabel}.");
        redirect('index.php?tab=units');
    }

    // 4. Toggle Status WhatsApp Siswa (Aktif / Nonaktif)
    if ($action === 'toggle_student_wa') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $newStatus = (int)($_POST['wa_notify'] ?? 1);

        // Validasi akses Kepsek
        if ($isKepsek && $ksUnitId) {
            $stmtCheck = $pdo->prepare("
                SELECT g.unit_id 
                FROM students s
                JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
                JOIN class_groups cg ON cg.id = se.class_group_id
                JOIN grades g ON g.id = cg.grade_id
                WHERE s.id = ? LIMIT 1
            ");
            $stmtCheck->execute([$studentId]);
            $stuUnitId = (int)$stmtCheck->fetchColumn();
            if ($stuUnitId !== $ksUnitId) {
                flash('error', 'Akses ditolak. Siswa berada di unit yang berbeda.');
                redirect('index.php?tab=students');
            }
        }

        $stmtUp = $pdo->prepare("UPDATE students SET wa_notify = ? WHERE id = ?");
        $stmtUp->execute([$newStatus, $studentId]);

        $statusText = $newStatus ? 'Diaktifkan' : 'Dinonaktifkan';
        flash('success', "Status notifikasi WhatsApp siswa berhasil {$statusText}.");
        redirect('index.php?tab=students&' . http_build_query($_GET));
    }

    // 5. Update Nomor WhatsApp Orang Tua & Status Notif Siswa
    if ($action === 'update_student_phone') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $parentPhone = trim($_POST['parent_phone'] ?? '');
        $waNotify = isset($_POST['wa_notify']) ? 1 : 0;

        if ($isKepsek && $ksUnitId) {
            $stmtCheck = $pdo->prepare("
                SELECT g.unit_id 
                FROM students s
                JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
                JOIN class_groups cg ON cg.id = se.class_group_id
                JOIN grades g ON g.id = cg.grade_id
                WHERE s.id = ? LIMIT 1
            ");
            $stmtCheck->execute([$studentId]);
            $stuUnitId = (int)$stmtCheck->fetchColumn();
            if ($stuUnitId !== $ksUnitId) {
                flash('error', 'Akses ditolak. Siswa berada di unit yang berbeda.');
                redirect('index.php?tab=students');
            }
        }

        $stmtUp = $pdo->prepare("UPDATE students SET parent_phone = ?, wa_notify = ? WHERE id = ?");
        $stmtUp->execute([$parentPhone !== '' ? $parentPhone : null, $waNotify, $studentId]);

        flash('success', 'Data nomor WhatsApp orang tua siswa berhasil disimpan.');
        redirect('index.php?tab=students&' . http_build_query($_GET));
    }

    // 6. Bulk Action Siswa (Aktifkan / Nonaktifkan Semua dalam Filter)
    if ($action === 'bulk_toggle_students') {
        $bulkStatus = (int)($_POST['bulk_status'] ?? 1);
        $filterUnit = (int)($_POST['bulk_unit_id'] ?? 0);
        $filterClass = (int)($_POST['bulk_class_group_id'] ?? 0);

        if ($isKepsek && $ksUnitId) {
            $filterUnit = $ksUnitId;
        }

        $bulkSql = "
            UPDATE students s
            INNER JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
            INNER JOIN class_groups cg ON cg.id = se.class_group_id
            INNER JOIN grades g ON g.id = cg.grade_id
            SET s.wa_notify = ?
            WHERE s.deleted_at IS NULL
        ";
        $bParams = [$bulkStatus];

        if ($filterUnit > 0) {
            $bulkSql .= " AND g.unit_id = ?";
            $bParams[] = $filterUnit;
        }
        if ($filterClass > 0) {
            $bulkSql .= " AND cg.id = ?";
            $bParams[] = $filterClass;
        }

        $stmtBulk = $pdo->prepare($bulkSql);
        $stmtBulk->execute($bParams);
        $affected = $stmtBulk->rowCount();

        $actionStr = $bulkStatus ? 'diaktifkan' : 'dinonaktifkan';
        flash('success', "Sebanyak {$affected} siswa berhasil {$actionStr} notifikasi WhatsApp-nya.");
        redirect('index.php?tab=students&' . http_build_query($_GET));
    }

    // 7. Kirim Pesan Uji Coba WhatsApp
    if ($action === 'send_test_message') {
        $testTarget = trim($_POST['target'] ?? '');
        $testMessage = trim($_POST['message'] ?? '');

        $settings = getWaSettings($pdo);
        $token = trim($settings['fonnte_token'] ?? '');

        if (empty($token)) {
            flash('error', 'Token Fonnte belum diisi. Silakan isi token API terlebih dahulu.');
            redirect('index.php?tab=settings');
        }

        if (empty($testTarget) || empty($testMessage)) {
            flash('error', 'Nomor tujuan dan isi pesan uji coba wajib diisi.');
            redirect('index.php?tab=settings');
        }

        $res = sendRawFonnteMessage($token, $testTarget, $testMessage, $settings['wa_country_code'] ?? '62');

        // Catat ke log
        $pdo->prepare("
            INSERT INTO wa_message_logs (student_id, unit_id, phone_number, message_type, message_text, status, response_api, sent_date)
            VALUES (NULL, NULL, ?, 'test', ?, ?, ?, CURDATE())
        ")->execute([
            $testTarget,
            $testMessage,
            $res['success'] ? 'success' : 'failed',
            $res['raw'] ?? $res['message']
        ]);

        if ($res['success']) {
            flash('success', "Pesan uji coba BERHASIL dikirim ke {$testTarget}! Respon Fonnte: " . htmlspecialchars($res['message']));
        } else {
            flash('error', "Gagal mengirim pesan uji coba ke {$testTarget}. Alasan: " . htmlspecialchars($res['message']));
        }

        redirect('index.php?tab=settings');
    }

    // 8. Jalankan Pengecekan Pengingat Manual (Pesan 2 & 4)
    if ($action === 'run_manual_check') {
        $lateSent = runLateCheckReminders($pdo);
        $outLateSent = runDepartureCheckReminders($pdo);
        $totalSent = $lateSent + $outLateSent;

        flash('success', "Pemeriksaan otomatis berhasil dijalankan! Pesan 2 (Belum Masuk): {$lateSent} terkirim, Pesan 4 (Belum Pulang): {$outLateSent} terkirim. Total: {$totalSent} notifikasi.");
        redirect('index.php?tab=messages');
    }

    // 9. Simpan Pengaturan Rekapan Bulanan Staff (Super Admin & Kepala Sekolah)
    if ($action === 'save_staff_recap_settings') {
        $staffRecapGlobal = isset($_POST['staff_recap_enabled']) ? '1' : '0';
        $template = trim($_POST['staff_recap_template'] ?? '');

        if ($isSuperAdmin) {
            updateWaSetting($pdo, 'staff_recap_enabled', $staffRecapGlobal);
            if (!empty($template)) {
                updateWaSetting($pdo, 'staff_recap_template', $template);
            }
            flash('success', 'Pengaturan Rekapan Bulanan Staff & Template berhasil disimpan.');
        } elseif ($isKepsek && $ksUnitId) {
            $unitRecap = isset($_POST['unit_staff_recap_enabled']) ? 1 : 0;
            $stmtUpUnit = $pdo->prepare("UPDATE wa_unit_settings SET staff_recap_enabled = ? WHERE unit_id = ?");
            $stmtUpUnit->execute([$unitRecap, $ksUnitId]);
            flash('success', "Pengaturan status kirim rekapan staff untuk Unit {$ksUnitName} berhasil diperbarui.");
        }

        redirect('index.php?tab=staff_recap');
    }

    // 10. Kirim Rekapan Bulanan ke Single Staff
    if ($action === 'send_single_staff_recap') {
        $targetStaffId = (int)($_POST['staff_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? date('Y-m-01');
        $endDate = $_POST['end_date'] ?? date('Y-m-t');

        if ($targetStaffId <= 0) {
            flash('error', 'Staff tidak valid.');
            redirect('index.php?tab=staff_recap');
        }

        $res = sendStaffMonthlyRecapNotification($pdo, $targetStaffId, $startDate, $endDate);
        if ($res['success']) {
            flash('success', "Rekapan bulanan BERHASIL dikirimkan ke {$res['staff_name']} ({$res['phone']})!");
        } else {
            $errMsg = $res['reason'] ?? $res['message'] ?? 'Gagal mengirim pesan';
            flash('error', "Gagal mengirim rekapan ke staff: {$errMsg}");
        }

        redirect('index.php?tab=staff_recap&' . http_build_query([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'unit_id'    => $_POST['unit_id'] ?? 0
        ]));
    }

    // 11. Kirim Rekapan Bulanan Massal (Bulk Send)
    if ($action === 'send_bulk_staff_recap') {
        $startDate = $_POST['start_date'] ?? date('Y-m-01');
        $endDate = $_POST['end_date'] ?? date('Y-m-t');
        $targetUnitId = (int)($_POST['unit_id'] ?? 0);

        if ($isKepsek && $ksUnitId) {
            $targetUnitId = $ksUnitId;
        }

        $staffRecapList = getStaffMonthlyRecap($pdo, $startDate, $endDate, $targetUnitId ?: null);
        $sentCount = 0;
        $failCount = 0;
        $skippedCount = 0;

        foreach ($staffRecapList as $st) {
            if (!$st['has_phone'] || $st['wa_notify'] === 0) {
                $skippedCount++;
                continue;
            }

            $res = sendStaffMonthlyRecapNotification($pdo, (int)$st['staff_id'], $startDate, $endDate);
            if ($res['success']) {
                $sentCount++;
            } else {
                $failCount++;
            }
        }

        flash('success', "Proses Pengiriman Rekapan Bulanan Selesai! Berhasil terkirim: {$sentCount}, Gagal: {$failCount}, Dilewati (No WA kosong/OFF): {$skippedCount}.");
        redirect('index.php?tab=staff_recap&' . http_build_query([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'unit_id'    => $targetUnitId
        ]));
    }
}

// =========================================================================
// QUERY DATA TAMPILAN
// =========================================================================
$activeTab = $_GET['tab'] ?? ($isKepsek ? 'students' : 'settings');
$waSettings = getWaSettings($pdo);
$ksUnitSettings = ($isKepsek && $ksUnitId) ? getWaUnitSettings($pdo, $ksUnitId) : null;

$currentLateDelay = ($isKepsek && !empty($ksUnitSettings['msg_late_delay_minutes']))
    ? (int)$ksUnitSettings['msg_late_delay_minutes']
    : (int)($waSettings['msg_late_delay_minutes'] ?? 30);

$currentLateRef = ($isKepsek && !empty($ksUnitSettings['msg_late_ref']))
    ? $ksUnitSettings['msg_late_ref']
    : ($waSettings['msg_late_ref'] ?? 'student_late');

$currentOutLateDelay = ($isKepsek && !empty($ksUnitSettings['msg_out_late_delay_minutes']))
    ? (int)$ksUnitSettings['msg_out_late_delay_minutes']
    : (int)($waSettings['msg_out_late_delay_minutes'] ?? 45);

$currentMsgInTiming = $waSettings['msg_in_timing'] ?? 'realtime';
$currentMsgOutTiming = $waSettings['msg_out_timing'] ?? 'realtime';

// Cek status perangkat jika di tab settings
$deviceInfo = null;
if ($activeTab === 'settings' && !empty($waSettings['fonnte_token'])) {
    $deviceInfo = checkFonnteDevice($waSettings['fonnte_token']);
}

// 1. Data Unit & Status Pembatasannya
$unitQuery = "
    SELECT u.id, u.unit, 
           COALESCE(wus.is_enabled, 1) as is_enabled,
           COALESCE(wus.msg_in_enabled, 1) as msg_in_enabled,
           COALESCE(wus.msg_late_enabled, 1) as msg_late_enabled,
           COALESCE(wus.msg_out_enabled, 1) as msg_out_enabled,
           COALESCE(wus.msg_out_late_enabled, 1) as msg_out_late_enabled,
           (SELECT COUNT(*) FROM student_enrollments se 
            JOIN class_groups cg ON cg.id = se.class_group_id 
            JOIN grades g ON g.id = cg.grade_id 
            WHERE g.unit_id = u.id AND se.status = 'active') as total_students,
           (SELECT COUNT(*) FROM students s 
            JOIN student_enrollments se2 ON se2.student_id = s.id AND se2.status = 'active'
            JOIN class_groups cg2 ON cg2.id = se2.class_group_id 
            JOIN grades g2 ON g2.id = cg2.grade_id 
            WHERE g2.unit_id = u.id AND s.wa_notify = 1 AND s.parent_phone IS NOT NULL AND s.parent_phone != '') as total_wa_active
    FROM units u
    LEFT JOIN wa_unit_settings wus ON wus.unit_id = u.id
";
if ($isKepsek && $ksUnitId) {
    $unitQuery .= " WHERE u.id = " . (int)$ksUnitId;
}
$unitQuery .= " ORDER BY u.id ASC";
$unitList = $pdo->query($unitQuery)->fetchAll(PDO::FETCH_ASSOC);

// 2. Data Siswa untuk Tab Kontrol Siswa
$searchName = trim($_GET['search'] ?? '');
$filterUnitId = (int)($_GET['unit_id'] ?? 0);
$filterClassId = (int)($_GET['class_group_id'] ?? 0);
$filterWaStatus = $_GET['wa_status'] ?? ''; // '1', '0', 'no_phone', ''

if ($isKepsek && $ksUnitId) {
    $filterUnitId = $ksUnitId;
}

$stuWhere = ["s.deleted_at IS NULL", "se.status = 'active'", "ay.status = 'active'"];
$stuParams = [];

if ($filterUnitId > 0) {
    $stuWhere[] = "g.unit_id = ?";
    $stuParams[] = $filterUnitId;
}
if ($filterClassId > 0) {
    $stuWhere[] = "cg.id = ?";
    $stuParams[] = $filterClassId;
}
if ($searchName !== '') {
    $stuWhere[] = "(s.name LIKE ? OR s.nis LIKE ? OR s.parent_phone LIKE ?)";
    $stuParams[] = "%{$searchName}%";
    $stuParams[] = "%{$searchName}%";
    $stuParams[] = "%{$searchName}%";
}
if ($filterWaStatus === '1') {
    $stuWhere[] = "s.wa_notify = 1 AND s.parent_phone IS NOT NULL AND s.parent_phone != ''";
} elseif ($filterWaStatus === '0') {
    $stuWhere[] = "s.wa_notify = 0";
} elseif ($filterWaStatus === 'no_phone') {
    $stuWhere[] = "(s.parent_phone IS NULL OR s.parent_phone = '')";
}

$stuWhereSql = implode(' AND ', $stuWhere);

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 40;
$offset = ($page - 1) * $limit;

$countStuSql = "
    SELECT COUNT(DISTINCT s.id)
    FROM students s
    INNER JOIN student_enrollments se ON se.student_id = s.id
    INNER JOIN academic_years ay ON ay.id = se.academic_year_id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    WHERE {$stuWhereSql}
";
$stmtCountStu = $pdo->prepare($countStuSql);
$stmtCountStu->execute($stuParams);
$totalStudents = (int)$stmtCountStu->fetchColumn();
$totalPages = max(1, ceil($totalStudents / $limit));

$stuSql = "
    SELECT s.id, s.name, s.nis, s.nik, s.photo, s.parent_phone, s.wa_notify,
           cg.id AS class_group_id, cg.name AS class_name,
           g.grade, un.unit AS unit_name
    FROM students s
    INNER JOIN student_enrollments se ON se.student_id = s.id
    INNER JOIN academic_years ay ON ay.id = se.academic_year_id
    INNER JOIN class_groups cg ON cg.id = se.class_group_id
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN units un ON un.id = g.unit_id
    WHERE {$stuWhereSql}
    ORDER BY un.id ASC, g.sort_order ASC, cg.name ASC, s.name ASC
    LIMIT {$limit} OFFSET {$offset}
";
$stmtStu = $pdo->prepare($stuSql);
$stmtStu->execute($stuParams);
$studentList = $stmtStu->fetchAll(PDO::FETCH_ASSOC);

// Subkelas untuk Filter
$cgFilterSql = "
    SELECT cg.id, cg.name, g.grade, un.unit as unit_name
    FROM class_groups cg
    JOIN grades g ON g.id = cg.grade_id
    JOIN units un ON un.id = g.unit_id
";
if ($isKepsek && $ksUnitId) {
    $cgFilterSql .= " WHERE un.id = " . (int)$ksUnitId;
}
$cgFilterSql .= " ORDER BY un.id ASC, g.sort_order ASC, cg.name ASC";
$filterClassList = $pdo->query($cgFilterSql)->fetchAll(PDO::FETCH_ASSOC);

// 3. Log Pengiriman
$logLimit = 50;
$logQuery = "
    SELECT wl.*, s.name as student_name, un.unit as unit_name
    FROM wa_message_logs wl
    LEFT JOIN students s ON s.id = wl.student_id
    LEFT JOIN units un ON un.id = wl.unit_id
";
if ($isKepsek && $ksUnitId) {
    $logQuery .= " WHERE wl.unit_id = " . (int)$ksUnitId;
}
$logQuery .= " ORDER BY wl.id DESC LIMIT {$logLimit}";
$messageLogs = $pdo->query($logQuery)->fetchAll(PDO::FETCH_ASSOC);

// Statistik Singkat
$statTodaySuccess = (int)$pdo->query("SELECT COUNT(*) FROM wa_message_logs WHERE sent_date = CURDATE() AND status = 'success'")->fetchColumn();
$statTodayFailed = (int)$pdo->query("SELECT COUNT(*) FROM wa_message_logs WHERE sent_date = CURDATE() AND status = 'failed'")->fetchColumn();
$statTotalWithPhone = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL AND parent_phone IS NOT NULL AND parent_phone != ''")->fetchColumn();
$statTotalActiveNotif = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL AND wa_notify = 1 AND parent_phone IS NOT NULL AND parent_phone != ''")->fetchColumn();

require '../../includes/header.php';
?>

<style>
.wa-header-card {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border-radius: 16px;
    padding: 24px;
    color: #ffffff;
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.3);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.wa-stat-badge {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    padding: 8px 14px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.wa-tab-nav {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 24px;
    overflow-x: auto;
    padding-bottom: 4px;
}
.wa-tab-link {
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 700;
    color: #64748b;
    text-decoration: none;
    border-radius: 10px 10px 0 0;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}
.wa-tab-link:hover {
    color: #059669;
    background: #f0fdf4;
}
.wa-tab-link.active {
    color: #059669;
    background: #ffffff;
    border: 2px solid #e2e8f0;
    border-bottom: 3px solid #10b981;
    margin-bottom: -6px;
}
.switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
    vertical-align: middle;
}
.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #cbd5e1;
    transition: .3s;
    border-radius: 24px;
}
.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
input:checked + .slider {
    background-color: #10b981;
}
input:checked + .slider:before {
    transform: translateX(20px);
}
.msg-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    transition: 0.2s;
}
.msg-card:hover {
    border-color: #10b981;
    box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.1);
}
.msg-tag {
    display: inline-block;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-family: monospace;
    font-weight: 600;
    margin: 2px;
}
</style>

<!-- ========================================================================= -->
<!-- HEADER BANNER & STATISTIK REALTIME                                        -->
<!-- ========================================================================= -->
<div class="wa-header-card">
    <div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fa-brands fa-whatsapp" style="font-size: 32px;"></i>
            <h2 style="margin: 0; font-size: 22px; font-weight: 800;">Integrasi Notifikasi WhatsApp (Fonnte API)</h2>
        </div>
        <p style="margin: 6px 0 0 0; opacity: 0.9; font-size: 13.5px;">
            Kirim notifikasi otomatis ke WhatsApp orang tua: Hadir Masuk, Terlambat (1 Jam Batas), Pulang, & Konfirmasi Kepulangan.
            <?= $isKepsek ? "<br><strong>Unit Anda: " . htmlspecialchars($ksUnitName) . "</strong>" : "" ?>
        </p>
    </div>
    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <div class="wa-stat-badge">
            <i class="fa-solid fa-paper-plane" style="font-size: 18px;"></i>
            <div>
                <div style="font-size: 11px; opacity: 0.85;">Terkirim Hari Ini</div>
                <div style="font-size: 16px; font-weight: 800;"><?= number_format($statTodaySuccess) ?> Pesan</div>
            </div>
        </div>
        <div class="wa-stat-badge">
            <i class="fa-solid fa-users" style="font-size: 18px;"></i>
            <div>
                <div style="font-size: 11px; opacity: 0.85;">Siswa Siap WA</div>
                <div style="font-size: 16px; font-weight: 800;"><?= number_format($statTotalActiveNotif) ?> Siswa</div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- TAB NAVIGASI                                                              -->
<!-- ========================================================================= -->
<div class="wa-tab-nav">
    <?php if ($isSuperAdmin): ?>
        <a href="?tab=settings" class="wa-tab-link <?= $activeTab === 'settings' ? 'active' : '' ?>">
            <i class="fa-solid fa-sliders"></i> Konfigurasi API Fonnte
        </a>
    <?php endif; ?>

    <a href="?tab=messages" class="wa-tab-link <?= $activeTab === 'messages' ? 'active' : '' ?>">
        <i class="fa-solid fa-comment-dots"></i> Kontrol 4 Jenis Pesan Otomatis
    </a>

    <a href="?tab=units" class="wa-tab-link <?= $activeTab === 'units' ? 'active' : '' ?>">
        <i class="fa-solid fa-building-shield"></i> Pembatasan Unit Sekolah
    </a>

    <a href="?tab=students" class="wa-tab-link <?= $activeTab === 'students' ? 'active' : '' ?>">
        <i class="fa-solid fa-user-check"></i> Kontrol Siswa & Nomor WA Ortu
    </a>

    <a href="?tab=staff_recap" class="wa-tab-link <?= $activeTab === 'staff_recap' ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-check"></i> Rekapan Bulanan Staff & Lembur
    </a>

    <a href="?tab=logs" class="wa-tab-link <?= $activeTab === 'logs' ? 'active' : '' ?>">
        <i class="fa-solid fa-clock-rotate-left"></i> Log Riwayat Notifikasi
    </a>
</div>

<!-- ========================================================================= -->
<!-- TAB 1: KONFIGURASI API FONNTE (SUPER ADMIN ONLY)                          -->
<!-- ========================================================================= -->
<?php if ($activeTab === 'settings' && $isSuperAdmin): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px;">
        
        <!-- CARD PENGATURAN TOKEN & GLOBAL ON/OFF -->
        <div class="card" style="margin: 0;">
            <div class="card-header">
                <div>
                    <h3 style="margin: 0;"><i class="fa-solid fa-key" style="color: #10b981; margin-right: 8px;"></i> Pengaturan Token API Fonnte</h3>
                    <small>Dapatkan token dari dashboard Fonnte Anda (<a href="https://fonnte.com/" target="_blank" style="color: #059669; font-weight: bold;">https://fonnte.com/</a>)</small>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_global_settings">
                
                <div style="background: #f8fafc; padding: 16px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <div style="font-weight: 700; color: #1e293b; font-size: 15px;">Status Integrasi WhatsApp API</div>
                        <div style="font-size: 12px; color: #64748b;">Nyalakan atau matikan seluruh notifikasi WhatsApp sistem</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="wa_enabled" value="1" <?= (!empty($waSettings['wa_enabled']) && $waSettings['wa_enabled'] === '1') ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; color: #334155;">Token Akun / Device Fonnte *</label>
                    <input type="text" name="fonnte_token" value="<?= htmlspecialchars($waSettings['fonnte_token'] ?? '') ?>" placeholder="Masukkan token Fonnte Anda..." required style="font-family: monospace; font-size: 14px;">
                    <small style="color: #64748b;">Token ini digunakan untuk otentikasi pengiriman pesan melalui nomor WhatsApp yang ditautkan.</small>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #334155;">Kode Negara Default</label>
                    <input type="text" name="wa_country_code" value="<?= htmlspecialchars($waSettings['wa_country_code'] ?? '62') ?>" style="width: 100px;">
                    <small style="color: #64748b;">Default 62 (Indonesia). Nomor 08xxx otomatis dikonversi ke 628xxx.</small>
                </div>

                <button type="submit" class="btn btn-primary" style="background: #059669; border-color: #059669;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Konfigurasi
                </button>
            </form>
        </div>

        <!-- CARD STATUS KONEKSI DEVICE & TEST PENGIRIMAN -->
        <div class="card" style="margin: 0;">
            <div class="card-header">
                <div>
                    <h3 style="margin: 0;"><i class="fa-solid fa-signal" style="color: #0284c7; margin-right: 8px;"></i> Status Perangkat & Kuota Fonnte</h3>
                    <small>Informasi koneksi nomor WhatsApp gateway</small>
                </div>
            </div>

            <?php if (!empty($deviceInfo)): ?>
                <div style="background: <?= !empty($deviceInfo['status']) ? '#f0fdf4' : '#fef2f2' ?>; border: 1px solid <?= !empty($deviceInfo['status']) ? '#bbf7d0' : '#fecaca' ?>; padding: 16px; border-radius: 12px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span style="font-weight: 700; color: #1e293b;">Status WhatsApp Gateway:</span>
                        <span style="padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 800; background: <?= !empty($deviceInfo['status']) ? '#22c55e' : '#ef4444' ?>; color: white;">
                            <?= !empty($deviceInfo['status']) ? 'TERHUBUNG (ONLINE)' : 'TERPUTUS (OFFLINE)' ?>
                        </span>
                    </div>
                    <div style="font-size: 13px; color: #475569; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <div><strong>Perangkat / Nomor:</strong> <?= htmlspecialchars($deviceInfo['device']) ?></div>
                        <div><strong>Sisa Kuota:</strong> <?= number_format((int)$deviceInfo['quota']) ?> pesan</div>
                        <div><strong>Masa Berlaku:</strong> <?= htmlspecialchars($deviceInfo['expired']) ?></div>
                        <div><strong>Total Terkirim:</strong> <?= number_format((int)$deviceInfo['messages']) ?> pesan</div>
                    </div>
                </div>
            <?php else: ?>
                <div style="background: #f1f5f9; padding: 14px; border-radius: 10px; margin-bottom: 20px; font-size: 13px; color: #64748b;">
                    <i class="fa-solid fa-circle-info"></i> Masukkan Token Fonnte terlebih dahulu untuk mengecek koneksi perangkat dan kuota Anda.
                </div>
            <?php endif; ?>

            <!-- FORM KIRIM PESAN UJI COBA -->
            <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #1e293b;"><i class="fa-solid fa-paper-plane" style="color: #059669;"></i> Kirim Pesan Uji Coba Langsung</h4>
            <form method="POST">
                <input type="hidden" name="action" value="send_test_message">
                
                <div class="form-group" style="margin-bottom: 10px;">
                    <label style="font-size: 12px; font-weight: 600;">Nomor WhatsApp Tujuan (HP Anda / Ortu)</label>
                    <input type="text" name="target" placeholder="Contoh: 081234567890" required style="font-size: 13px;">
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 12px; font-weight: 600;">Isi Pesan Uji Coba</label>
                    <textarea name="message" rows="2" style="font-size: 13px;" required>Halo, ini adalah pesan uji coba integrasi WhatsApp Fonnte dari Sistem Absensi Sekolah. Sistem berfungsi dengan baik!</textarea>
                </div>

                <button type="submit" class="btn btn-secondary" style="font-size: 13px; font-weight: 700;">
                    <i class="fa-solid fa-paper-plane"></i> Kirim Tes Pesan Sekarang
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- TAB 2: KONTROL 4 JENIS PESAN OTOMATIS & TEMPLATE                          -->
<!-- ========================================================================= -->
<?php if ($activeTab === 'messages'): ?>
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="margin: 0;"><i class="fa-solid fa-toggle-on" style="color: #10b981; margin-right: 8px;"></i> Kontrol 4 Jenis Pesan Notifikasi Otomatis</h3>
                <small>Super Admin dan Kepala Sekolah dapat mematikan pesan tertentu sewaktu-waktu (misal ada libur khusus, ujian, atau acara yang tidak perlu absen).</small>
            </div>
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="run_manual_check">
                <button type="submit" class="btn btn-secondary" style="font-size: 12.5px; font-weight: 700; background: #ede9fe; color: #6d28d9; border-color: #ddd6fe;" title="Jalankan pemeriksaan siswa yang belum hadir atau belum pulang sekarang juga">
                    <i class="fa-solid fa-bolt"></i> Jalankan Pemeriksaan Pesan 2 & 4 Sekarang
                </button>
            </form>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_message_settings">

            <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; color: #1e3a8a;">
                <strong>Variabel Template yang dapat digunakan:</strong><br>
                <span class="msg-tag">{nama_siswa}</span>
                <span class="msg-tag">{nis}</span>
                <span class="msg-tag">{kelas}</span>
                <span class="msg-tag">{unit}</span>
                <span class="msg-tag">{tanggal}</span>
                <span class="msg-tag">{jam_absen}</span>
                <span class="msg-tag">{status_kehadiran}</span>
                <span class="msg-tag">{jam_batas}</span>
                <span class="msg-tag">{jam_pulang}</span>
                <span class="msg-tag">{jam_sekarang}</span>
            </div>

            <!-- PESAN 1: ABSEN MASUK -->
            <div class="msg-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <div>
                        <span style="background: #dcfce7; color: #15803d; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase;">PESAN 1</span>
                        <h4 style="margin: 4px 0 2px 0; font-size: 16px; color: #0f172a;">Absen Masuk (Hadir di Sekolah)</h4>
                        <small style="color: #64748b;">Notifikasi konfirmasi kehadiran siswa saat jam masuk sekolah.</small>
                        <div style="margin-top: 6px;">
                            <span style="display: inline-flex; align-items: center; gap: 6px; background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700;">
                                <i class="fa-solid fa-bolt"></i> Waktu Kirim: <strong>Ketika Absen</strong> (Seketika saat scan masuk)
                            </span>
                        </div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="msg_in_enabled" value="1" <?= (!empty($waSettings['msg_in_enabled']) && $waSettings['msg_in_enabled'] === '1') ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                <input type="hidden" name="msg_in_timing" value="realtime">
                <?php if ($isSuperAdmin): ?>
                    <div class="form-group" style="margin-top: 12px;">
                        <label style="font-size: 12px; font-weight: 600;">Template Pesan Absen Masuk:</label>
                        <textarea name="template_in" rows="4" style="font-family: monospace; font-size: 12.5px;"><?= htmlspecialchars($waSettings['template_in'] ?? '') ?></textarea>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PESAN 2: TERLAMBAT / BELUM HADIR (SESUDAH JAM MASUK) -->
            <div class="msg-card" style="border-left: 5px solid #f59e0b;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <div>
                        <span style="background: #fef3c7; color: #b45309; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase;">PESAN 2</span>
                        <h4 style="margin: 4px 0 2px 0; font-size: 16px; color: #0f172a;">Terlambat / Belum Hadir (Sesudah Jam Masuk)</h4>
                        <small style="color: #64748b;">Terkirim otomatis ke orang tua jika siswa belum melakukan scan absensi masuk setelah jam yang ditentukan.</small>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="msg_late_enabled" value="1" <?= (!empty($waSettings['msg_late_enabled']) && $waSettings['msg_late_enabled'] === '1') ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- PENGATURAN WAKTU PENGIRIMAN PESAN 2 -->
                <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 14px; margin-bottom: 15px;">
                    <label style="font-size: 13px; font-weight: 700; color: #92400e; display: flex; align-items: center; gap: 6px; margin-bottom: 10px;">
                        <i class="fa-solid fa-clock"></i> Pengaturan Waktu Pengiriman (Sesudah Jam Masuk):
                    </label>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                        <div>
                            <label style="font-size: 12px; color: #78350f; font-weight: 600; display: block; margin-bottom: 4px;">Patokan Waktu Acuan:</label>
                            <select name="msg_late_ref" style="padding: 7px 10px; border-radius: 6px; border: 1px solid #fcd34d; font-size: 13px; background: white;">
                                <option value="student_late" <?= ($currentLateRef === 'student_late') ? 'selected' : '' ?>>Batas Jam Masuk / Toleransi Telat (student_late)</option>
                                <option value="student_in" <?= ($currentLateRef === 'student_in') ? 'selected' : '' ?>>Jam Mulai Masuk Sekolah (student_in)</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12px; color: #78350f; font-weight: 600; display: block; margin-bottom: 4px;">Jeda Pengiriman (Berapa Menit Sesudahnya):</label>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <input type="number" id="input_late_delay" name="msg_late_delay_minutes" min="1" max="300" value="<?= e($currentLateDelay) ?>" style="width: 85px; padding: 7px 10px; border-radius: 6px; border: 1px solid #fcd34d; font-size: 13px; font-weight: 700; text-align: center; background: white;">
                                <span style="font-size: 13px; color: #92400e; font-weight: 600;">Menit</span>
                            </div>
                        </div>
                        <div style="display: flex; gap: 5px; align-items: center;">
                            <span style="font-size: 11px; color: #92400e; font-weight: 600;">Pilih Cepat:</span>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_late_delay').value=15" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fcd34d; cursor: pointer;">15m</button>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_late_delay').value=30" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fcd34d; cursor: pointer;">30m</button>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_late_delay').value=45" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fcd34d; cursor: pointer;">45m</button>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_late_delay').value=60" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fcd34d; cursor: pointer;">1 Jam</button>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_late_delay').value=90" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fcd34d; cursor: pointer;">1.5 Jam</button>
                        </div>
                    </div>
                    <div style="margin-top: 10px; font-size: 12px; color: #b45309; line-height: 1.5;">
                        <i class="fa-solid fa-circle-info"></i> <strong>Contoh Penerapan:</strong> Jika batas telat jam <strong>07:00</strong> dan jeda disetel <strong><?= e($currentLateDelay) ?> Menit</strong>, maka pada pukul <strong><?= date('H:i', strtotime("+{$currentLateDelay} minutes", strtotime('07:00:00'))) ?></strong> sistem otomatis mengirim notifikasi kepada orang tua yang anaknya belum ada data absen masuk.
                    </div>
                </div>

                <?php if ($isSuperAdmin): ?>
                    <div class="form-group">
                        <label style="font-size: 12px; font-weight: 600;">Template Pesan Belum Hadir (Sesudah Jam Masuk):</label>
                        <textarea name="template_late" rows="4" style="font-family: monospace; font-size: 12.5px;"><?= htmlspecialchars($waSettings['template_late'] ?? '') ?></textarea>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PESAN 3: ABSEN PULANG -->
            <div class="msg-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <div>
                        <span style="background: #e0e7ff; color: #4338ca; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase;">PESAN 3</span>
                        <h4 style="margin: 4px 0 2px 0; font-size: 16px; color: #0f172a;">Absen Pulang (Meninggalkan Sekolah)</h4>
                        <small style="color: #64748b;">Notifikasi kepulangan siswa saat tap RFID / barcode kepulangan di gerbang sekolah.</small>
                        <div style="margin-top: 6px;">
                            <span style="display: inline-flex; align-items: center; gap: 6px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700;">
                                <i class="fa-solid fa-person-walking-arrow-right"></i> Waktu Kirim: <strong>Ketika Pulang</strong> (Seketika saat scan pulang)
                            </span>
                        </div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="msg_out_enabled" value="1" <?= (!empty($waSettings['msg_out_enabled']) && $waSettings['msg_out_enabled'] === '1') ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                <input type="hidden" name="msg_out_timing" value="realtime">
                <?php if ($isSuperAdmin): ?>
                    <div class="form-group" style="margin-top: 12px;">
                        <label style="font-size: 12px; font-weight: 600;">Template Pesan Absen Pulang:</label>
                        <textarea name="template_out" rows="4" style="font-family: monospace; font-size: 12.5px;"><?= htmlspecialchars($waSettings['template_out'] ?? '') ?></textarea>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PESAN 4: PERINGATAN KONFIRMASI LEWAT JAM PULANG -->
            <div class="msg-card" style="border-left: 5px solid #ef4444;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <div>
                        <span style="background: #fee2e2; color: #b91c1c; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase;">PESAN 4</span>
                        <h4 style="margin: 4px 0 2px 0; font-size: 16px; color: #0f172a;">Konfirmasi Kepulangan (Berapa Menit Ketika Lewat Jam Pulang)</h4>
                        <small style="color: #64748b;">Terkirim ke orang tua jika setelah jam pulang anaknya belum absen pulang untuk konfirmasi apakah anak sudah sampai di rumah.</small>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="msg_out_late_enabled" value="1" <?= (!empty($waSettings['msg_out_late_enabled']) && $waSettings['msg_out_late_enabled'] === '1') ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- PENGATURAN WAKTU PENGIRIMAN PESAN 4 -->
                <div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 14px; margin-bottom: 15px;">
                    <label style="font-size: 13px; font-weight: 700; color: #991b1b; display: flex; align-items: center; gap: 6px; margin-bottom: 10px;">
                        <i class="fa-solid fa-clock"></i> Pengaturan Waktu Pengiriman (Berapa Menit Ketika Lewat Jam Pulang):
                    </label>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                        <div>
                            <label style="font-size: 12px; color: #991b1b; font-weight: 600; display: block; margin-bottom: 4px;">Patokan Waktu Acuan:</label>
                            <div style="padding: 7px 12px; border-radius: 6px; border: 1px solid #fca5a5; font-size: 13px; background: white; color: #991b1b; font-weight: 600;">
                                <i class="fa-solid fa-bell"></i> Jam Pulang Sekolah Terjadwal (student_out)
                            </div>
                        </div>
                        <div>
                            <label style="font-size: 12px; color: #991b1b; font-weight: 600; display: block; margin-bottom: 4px;">Jeda Pengiriman (Menit Lewat Jam Pulang):</label>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <input type="number" id="input_out_delay" name="msg_out_late_delay_minutes" min="1" max="300" value="<?= e($currentOutLateDelay) ?>" style="width: 85px; padding: 7px 10px; border-radius: 6px; border: 1px solid #fca5a5; font-size: 13px; font-weight: 700; text-align: center; background: white;">
                                <span style="font-size: 13px; color: #991b1b; font-weight: 600;">Menit</span>
                            </div>
                        </div>
                        <div style="display: flex; gap: 5px; align-items: center;">
                            <span style="font-size: 11px; color: #991b1b; font-weight: 600;">Pilih Cepat:</span>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_out_delay').value=15" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fca5a5; cursor: pointer;">15m</button>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_out_delay').value=30" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fca5a5; cursor: pointer;">30m</button>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_out_delay').value=45" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fca5a5; cursor: pointer;">45m</button>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_out_delay').value=60" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fca5a5; cursor: pointer;">1 Jam</button>
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('input_out_delay').value=90" style="font-size: 11px; padding: 3px 8px; background: white; border: 1px solid #fca5a5; cursor: pointer;">1.5 Jam</button>
                        </div>
                    </div>
                    <div style="margin-top: 10px; font-size: 12px; color: #b91c1c; line-height: 1.5;">
                        <i class="fa-solid fa-circle-info"></i> <strong>Contoh Penerapan:</strong> Jika jadwal pulang sekolah jam <strong>15:30</strong> dan jeda disetel <strong><?= e($currentOutLateDelay) ?> Menit</strong>, maka pada pukul <strong><?= date('H:i', strtotime("+{$currentOutLateDelay} minutes", strtotime('15:30:00'))) ?></strong> sistem otomatis mengirim pesan konfirmasi kepada orang tua siswa yang belum tap pulang.
                    </div>
                </div>

                <?php if ($isSuperAdmin): ?>
                    <div class="form-group">
                        <label style="font-size: 12px; font-weight: 600;">Template Pesan Konfirmasi Kepulangan (Lewat Jam Pulang):</label>
                        <textarea name="template_out_late" rows="4" style="font-family: monospace; font-size: 12.5px;"><?= htmlspecialchars($waSettings['template_out_late'] ?? '') ?></textarea>
                    </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary" style="background: #059669; border-color: #059669; padding: 10px 24px;">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Pengaturan Pesan
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- TAB 3: PEMBATASAN PER UNIT SEKOLAH                                        -->
<!-- ========================================================================= -->
<?php if ($activeTab === 'units'): ?>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 style="margin: 0;"><i class="fa-solid fa-building-shield" style="color: #0284c7; margin-right: 8px;"></i> Pembatasan Notifikasi WhatsApp Per Unit</h3>
                <small>Aktifkan atau batasi pengiriman WhatsApp khusus untuk unit tertentu (misal TK dimatikan, SMP & SMA diaktifkan).</small>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Unit Sekolah</th>
                        <th style="text-align: center;">Total Siswa</th>
                        <th style="text-align: center;">Siswa Memiliki No WA</th>
                        <th style="text-align: center;">Status Layanan WA Unit</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unitList as $idx => $u): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                <strong style="font-size: 15px; color: #0f172a;"><?= htmlspecialchars($u['unit']) ?></strong>
                            </td>
                            <td style="text-align: center; font-weight: 600;">
                                <?= number_format((int)$u['total_students']) ?> siswa
                            </td>
                            <td style="text-align: center;">
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; background: #ecfdf5; color: #059669; border-radius: 12px; font-weight: 700; font-size: 12px;">
                                    <i class="fa-brands fa-whatsapp"></i> <?= number_format((int)$u['total_wa_active']) ?> siswa
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ((int)$u['is_enabled'] === 1): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #dcfce7; color: #166534; border-radius: 20px; font-size: 12px; font-weight: 700; border: 1px solid #bbf7d0;">
                                        <i class="fa-solid fa-circle-check"></i> AKTIF (Bisa Pakai)
                                    </span>
                                <?php else: ?>
                                    <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #fee2e2; color: #991b1b; border-radius: 20px; font-size: 12px; font-weight: 700; border: 1px solid #fecaca;">
                                        <i class="fa-solid fa-ban"></i> DIBATASI (Gak Pakai)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <form method="POST" style="margin: 0; display: inline;">
                                    <input type="hidden" name="action" value="toggle_unit_status">
                                    <input type="hidden" name="unit_id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="is_enabled" value="<?= ((int)$u['is_enabled'] === 1) ? 0 : 1 ?>">
                                    
                                    <?php if ((int)$u['is_enabled'] === 1): ?>
                                        <button type="submit" class="btn btn-danger" style="padding: 5px 12px; font-size: 12px;" onclick="return confirm('Apakah Anda yakin ingin mematikan / membatasi layanan WhatsApp untuk unit <?= htmlspecialchars($u['unit']) ?>?');">
                                            <i class="fa-solid fa-ban"></i> Matikan (Gak Pakai)
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-success" style="padding: 5px 12px; font-size: 12px; background: #059669;">
                                            <i class="fa-solid fa-check"></i> Aktifkan (Bisa Pakai)
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- TAB 4: KONTROL SISWA & NOMOR WA ORANG TUA                                 -->
<!-- ========================================================================= -->
<?php if ($activeTab === 'students'): ?>
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <div>
                <h3 style="margin: 0;"><i class="fa-solid fa-user-gear" style="color: #059669; margin-right: 8px;"></i> Kontrol Akses WhatsApp Siswa</h3>
                <small>Kepala Sekolah dan Super Admin dapat menentukan siswa mana saja yang notifikasinya aktif atau nonaktif, serta menginput nomor WA orang tua.</small>
            </div>
            
            <!-- BULK ACTION -->
            <div style="display: flex; gap: 8px;">
                <form method="POST" style="display: inline;" onsubmit="return confirm('Aktifkan notifikasi WhatsApp untuk seluruh siswa sesuai filter ini?');">
                    <input type="hidden" name="action" value="bulk_toggle_students">
                    <input type="hidden" name="bulk_status" value="1">
                    <input type="hidden" name="bulk_unit_id" value="<?= $filterUnitId ?>">
                    <input type="hidden" name="bulk_class_group_id" value="<?= $filterClassId ?>">
                    <button type="submit" class="btn btn-success" style="font-size: 12px; background: #059669; padding: 6px 12px;">
                        <i class="fa-solid fa-check-double"></i> Aktifkan Semua
                    </button>
                </form>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Nonaktifkan notifikasi WhatsApp untuk seluruh siswa sesuai filter ini?');">
                    <input type="hidden" name="action" value="bulk_toggle_students">
                    <input type="hidden" name="bulk_status" value="0">
                    <input type="hidden" name="bulk_unit_id" value="<?= $filterUnitId ?>">
                    <input type="hidden" name="bulk_class_group_id" value="<?= $filterClassId ?>">
                    <button type="submit" class="btn btn-danger" style="font-size: 12px; padding: 6px 12px;">
                        <i class="fa-solid fa-xmark"></i> Matikan Semua
                    </button>
                </form>
            </div>
        </div>

        <!-- FILTER -->
        <form method="GET">
            <input type="hidden" name="tab" value="students">
            <div class="filter-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                <div class="form-group">
                    <label>Cari Nama / NIS / No WA</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchName) ?>" placeholder="Ketik kata kunci...">
                </div>

                <?php if ($isSuperAdmin): ?>
                    <div class="form-group">
                        <label>Unit Sekolah</label>
                        <select name="unit_id">
                            <option value="0">Semua Unit</option>
                            <?php foreach ($unitList as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $filterUnitId == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['unit']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Subkelas</label>
                    <select name="class_group_id">
                        <option value="0">Semua Subkelas</option>
                        <?php foreach ($filterClassList as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $filterClassId == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['unit_name']) ?> - <?= htmlspecialchars($c['grade']) ?> (<?= htmlspecialchars($c['name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Status WA Siswa</label>
                    <select name="wa_status">
                        <option value="">Semua Siswa</option>
                        <option value="1" <?= $filterWaStatus === '1' ? 'selected' : '' ?>>Aktif (Bisa Kirim)</option>
                        <option value="0" <?= $filterWaStatus === '0' ? 'selected' : '' ?>>Nonaktif (Dimatikan)</option>
                        <option value="no_phone" <?= $filterWaStatus === 'no_phone' ? 'selected' : '' ?>>Belum Ada Nomor WA</option>
                    </select>
                </div>

                <div style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary" style="margin-bottom: 2px;">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- TABEL DATA SISWA & KONTROL WA -->
    <div class="card">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Foto</th>
                        <th>Nama Siswa</th>
                        <th>NIS</th>
                        <th>Unit</th>
                        <th>Subkelas</th>
                        <th>Nomor WhatsApp Ortu</th>
                        <th style="text-align: center;">Status Notif Siswa</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($studentList)): ?>
                        <tr><td colspan="9" style="text-align: center; padding: 24px; color: #94a3b8;">Tidak ada data siswa yang cocok dengan filter.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($studentList as $sIdx => $st): ?>
                        <tr>
                            <td><?= $offset + $sIdx + 1 ?></td>
                            <td>
                                <?php if (!empty($st['photo'])): ?>
                                    <img src="../../uploads/students/<?= htmlspecialchars($st['photo']) ?>" width="40" height="40" style="object-fit: cover; border-radius: 8px;">
                                <?php else: ?>
                                    <div class="avatar" style="width: 40px; height: 40px; font-size: 14px;"><?= strtoupper(substr($st['name'], 0, 1)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: #0f172a;"><?= htmlspecialchars($st['name']) ?></strong>
                            </td>
                            <td><?= htmlspecialchars($st['nis'] ?? '-') ?></td>
                            <td>
                                <span class="badge badge-primary" style="background: #0284c7; color: white;">
                                    <?= htmlspecialchars($st['unit_name']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($st['grade']) ?> - <?= htmlspecialchars($st['class_name']) ?></td>
                            <td>
                                <?php if (!empty($st['parent_phone'])): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 6px; font-family: monospace; font-size: 13px; font-weight: 700; color: #047857; background: #dcfce7; padding: 3px 8px; border-radius: 6px;">
                                        <i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars($st['parent_phone']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size: 11.5px; color: #dc2626; font-style: italic; background: #fee2e2; padding: 2px 6px; border-radius: 4px;">
                                        <i class="fa-solid fa-triangle-exclamation"></i> Belum Ada No WA
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <form method="POST" style="margin: 0; display: inline;">
                                    <input type="hidden" name="action" value="toggle_student_wa">
                                    <input type="hidden" name="student_id" value="<?= $st['id'] ?>">
                                    <input type="hidden" name="wa_notify" value="<?= ((int)$st['wa_notify'] === 1) ? 0 : 1 ?>">
                                    
                                    <?php if ((int)$st['wa_notify'] === 1): ?>
                                        <button type="submit" class="btn btn-sm btn-success" style="padding: 3px 10px; font-size: 11.5px; border-radius: 12px; background: #059669;" title="Klik untuk mematikan notifikasi WA siswa ini">
                                            <i class="fa-solid fa-check"></i> AKTIF (Bisa Pakai)
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-danger" style="padding: 3px 10px; font-size: 11.5px; border-radius: 12px;" title="Klik untuk mengaktifkan notifikasi WA siswa ini">
                                            <i class="fa-solid fa-xmark"></i> NONAKTIF (Gak Pakai)
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                            <td style="text-align: right;">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="openEditPhoneModal(<?= $st['id'] ?>, '<?= htmlspecialchars(addslashes($st['name'])) ?>', '<?= htmlspecialchars(addslashes($st['parent_phone'] ?? '')) ?>', <?= (int)$st['wa_notify'] ?>)" style="padding: 4px 10px; font-size: 12px;">
                                    <i class="fa-solid fa-phone"></i> Edit No WA
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div style="display:flex; gap:8px; margin-top:20px; flex-wrap:wrap;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= buildQuery(['page' => $i, 'tab' => 'students']) ?>" class="btn <?= $page == $i ? 'btn-primary' : 'btn-success' ?>" style="font-size: 12px; padding: 4px 10px;"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- TAB 5: LOG RIWAYAT NOTIFIKASI                                             -->
<!-- ========================================================================= -->
<?php if ($activeTab === 'logs'): ?>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 style="margin: 0;"><i class="fa-solid fa-clock-rotate-left" style="color: #059669; margin-right: 8px;"></i> Log Riwayat Notifikasi WhatsApp</h3>
                <small>Menampilkan <?= count($messageLogs) ?> data pengiriman notifikasi terakhir.</small>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Siswa</th>
                        <th>Unit</th>
                        <th>Nomor WA Ortu</th>
                        <th>Jenis Pesan</th>
                        <th>Status</th>
                        <th>Isi Pesan Singkat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messageLogs)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 24px; color: #94a3b8;">Belum ada riwayat pengiriman pesan notifikasi.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($messageLogs as $log): ?>
                        <?php
                            $typeLabel = 'Lainnya';
                            $typeBadge = 'background: #f1f5f9; color: #475569;';
                            if ($log['message_type'] === 'in') {
                                $typeLabel = '1. Absen Masuk';
                                $typeBadge = 'background: #dcfce7; color: #166534;';
                            } elseif ($log['message_type'] === 'late') {
                                $typeLabel = '2. Belum Hadir (1 Jam)';
                                $typeBadge = 'background: #fef3c7; color: #92400e;';
                            } elseif ($log['message_type'] === 'out') {
                                $typeLabel = '3. Absen Pulang';
                                $typeBadge = 'background: #e0e7ff; color: #3730a3;';
                            } elseif ($log['message_type'] === 'out_late') {
                                $typeLabel = '4. Konfirmasi Pulang (1 Jam)';
                                $typeBadge = 'background: #fee2e2; color: #991b1b;';
                            } elseif ($log['message_type'] === 'test') {
                                $typeLabel = 'Tes Pengiriman';
                                $typeBadge = 'background: #f3e8ff; color: #6b21a8;';
                            }
                        ?>
                        <tr>
                            <td style="font-size: 12px; color: #64748b; white-space: nowrap;">
                                <?= date('d M Y H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($log['student_name'] ?? 'Uji Coba') ?></strong>
                            </td>
                            <td>
                                <span class="badge" style="font-size: 11px;"><?= htmlspecialchars($log['unit_name'] ?? '-') ?></span>
                            </td>
                            <td style="font-family: monospace; font-size: 12px;">
                                <?= htmlspecialchars($log['phone_number']) ?>
                            </td>
                            <td>
                                <span style="display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; <?= $typeBadge ?>">
                                    <?= $typeLabel ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($log['status'] === 'success'): ?>
                                    <span style="color: #16a34a; font-weight: 700; font-size: 12px;"><i class="fa-solid fa-check"></i> Berhasil</span>
                                <?php else: ?>
                                    <span style="color: #dc2626; font-weight: 700; font-size: 12px;" title="<?= htmlspecialchars($log['response_api'] ?? '') ?>"><i class="fa-solid fa-triangle-exclamation"></i> Gagal</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 11.5px; color: #475569; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= htmlspecialchars($log['message_text']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- TAB: REKAPAN BULANAN STAFF & LEMBUR VIA WHATSAPP                          -->
<!-- ========================================================================= -->
<?php if ($activeTab === 'staff_recap'): 
    $recapStartDate = $_GET['start_date'] ?? date('Y-m-01');
    $recapEndDate = $_GET['end_date'] ?? date('Y-m-t');
    $recapUnitId = (int)($_GET['unit_id'] ?? ($isKepsek ? $ksUnitId : 0));
    if ($isKepsek && $ksUnitId) { $recapUnitId = $ksUnitId; }

    $staffRecapData = getStaffMonthlyRecap($pdo, $recapStartDate, $recapEndDate, $recapUnitId ?: null);

    // Ambil status sakelar
    $isGlobalRecapEnabled = ($waSettings['staff_recap_enabled'] ?? '1') === '1';
    $isUnitRecapEnabled = true;
    if ($ksUnitId) {
        $stmtUconf = $pdo->prepare("SELECT staff_recap_enabled FROM wa_unit_settings WHERE unit_id = ? LIMIT 1");
        $stmtUconf->execute([$ksUnitId]);
        $uConfRow = $stmtUconf->fetch(PDO::FETCH_ASSOC);
        if ($uConfRow && isset($uConfRow['staff_recap_enabled'])) {
            $isUnitRecapEnabled = ((int)$uConfRow['staff_recap_enabled'] === 1);
        }
    }

    $readyCount = 0;
    foreach ($staffRecapData as $row) {
        if ($row['has_phone'] && $row['wa_notify'] === 1) $readyCount++;
    }
?>
    <!-- CARD KONTROL SAKELAR & TEMPLATE PESAN -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px; margin-bottom: 24px;">
        <div class="card" style="margin: 0;">
            <div class="card-header">
                <div>
                    <h3 style="margin: 0; font-size: 16px;"><i class="fa-solid fa-sliders" style="color: #059669;"></i> Status Fitur Rekapan Staff</h3>
                    <small>Atur apakah pengiriman rekapan bulanan staff diaktifkan.</small>
                </div>
            </div>
            <form method="POST" style="padding-top: 15px;">
                <input type="hidden" name="action" value="save_staff_recap_settings">
                
                <?php if ($isSuperAdmin): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 15px;">
                        <div>
                            <div style="font-weight: 700; font-size: 14px; color: #1e293b;">Master Sakelar Rekap Staff (Global)</div>
                            <small style="color: #64748b;">Mengaktifkan atau menonaktifkan fitur rekapan bulanan seluruh unit.</small>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="staff_recap_enabled" value="1" <?= $isGlobalRecapEnabled ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                <?php endif; ?>

                <?php if ($isKepsek && $ksUnitId): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 15px;">
                        <div>
                            <div style="font-weight: 700; font-size: 14px; color: #1e293b;">Sakelar Rekap Staff Unit <?= htmlspecialchars($ksUnitName) ?></div>
                            <small style="color: #64748b;">Khusus untuk staff yang bertugas di unit Anda.</small>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="unit_staff_recap_enabled" value="1" <?= $isUnitRecapEnabled ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                <?php endif; ?>

                <?php if ($isSuperAdmin): ?>
                    <div class="form-group">
                        <label style="font-weight: 700;">Template Pesan WhatsApp Rekap Staff</label>
                        <textarea name="staff_recap_template" rows="8" style="width: 100%; font-family: monospace; font-size: 12.5px; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1;"><?= htmlspecialchars($waSettings['staff_recap_template'] ?? '') ?></textarea>
                        <div style="margin-top: 6px;">
                            <small style="color: #64748b;">Tag Placeholder yang tersedia:</small><br>
                            <span class="msg-tag">{nama_staff}</span>
                            <span class="msg-tag">{nik}</span>
                            <span class="msg-tag">{unit}</span>
                            <span class="msg-tag">{periode}</span>
                            <span class="msg-tag">{total_hadir}</span>
                            <span class="msg-tag">{tepat_waktu}</span>
                            <span class="msg-tag">{terlambat}</span>
                            <span class="msg-tag">{izin_sakit}</span>
                            <span class="msg-tag">{alpa}</span>
                            <span class="msg-tag">{persentase_kehadiran}</span>
                            <span class="msg-tag">{total_lembur}</span>
                            <span class="msg-tag">{frekuensi_lembur}</span>
                        </div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary" style="margin-top: 10px;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Pengaturan Rekap
                </button>
            </form>
        </div>

        <!-- CARD INFO JADWAL & PENGIRIMAN MASSAL -->
        <div class="card" style="margin: 0; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div class="card-header">
                    <div>
                        <h3 style="margin: 0; font-size: 16px;"><i class="fa-solid fa-paper-plane" style="color: #0284c7;"></i> Eksekusi Pengiriman Massal</h3>
                        <small>Kirim rekapan ke seluruh staff yang siap menerima WhatsApp.</small>
                    </div>
                </div>
                <div style="padding-top: 15px;">
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px; margin-bottom: 15px;">
                        <div style="font-weight: 700; color: #166534; font-size: 14px; margin-bottom: 4px;">
                            <i class="fa-solid fa-circle-check"></i> Siap Dikirim: <?= $readyCount ?> dari <?= count($staffRecapData) ?> Staff
                        </div>
                        <div style="font-size: 12.5px; color: #15803d;">
                            Staff yang nomor WhatsApp-nya aktif dan terdaftar akan menerima pesan ringkasan presensi 1 bulan dan durasi lembur.
                        </div>
                    </div>

                    <div style="font-size: 13px; color: #475569; line-height: 1.6;">
                        📌 <b>Tips Jadwal Akhir Bulan:</b><br>
                        Pengiriman biasanya dilakukan setiap tanggal 28-31 pada akhir bulan jam kerja, atau kapan saja tanggal ditentukan oleh Super Admin & Kepala Sekolah.
                    </div>
                </div>
            </div>

            <form method="POST" onsubmit="return confirm('Kirimkan notifikasi WhatsApp rekapan presensi & lembur sekarang ke <?= $readyCount ?> staff?');" style="margin-top: 20px;">
                <input type="hidden" name="action" value="send_bulk_staff_recap">
                <input type="hidden" name="start_date" value="<?= htmlspecialchars($recapStartDate) ?>">
                <input type="hidden" name="end_date" value="<?= htmlspecialchars($recapEndDate) ?>">
                <input type="hidden" name="unit_id" value="<?= $recapUnitId ?>">

                <button type="submit" class="btn" style="width: 100%; background: linear-gradient(135deg, #059669, #10b981); color: white; padding: 12px; font-weight: 700; font-size: 14px; border: none; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);" <?= ($readyCount === 0 || (!$isGlobalRecapEnabled || !$isUnitRecapEnabled)) ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-paper-plane"></i> Kirim Rekapan ke <?= $readyCount ?> Staff Sekarang
                </button>
            </form>
        </div>
    </div>

    <!-- FILTER PERIODE TANGGAL & UNIT -->
    <div class="card" style="margin-bottom: 20px;">
        <form method="GET">
            <input type="hidden" name="tab" value="staff_recap">
            <div class="filter-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="form-group">
                    <label>Tanggal Mulai *</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($recapStartDate) ?>" required>
                </div>
                <div class="form-group">
                    <label>Tanggal Akhir *</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($recapEndDate) ?>" required>
                </div>

                <?php if ($isSuperAdmin): ?>
                <div class="form-group">
                    <label>Unit Sekolah</label>
                    <select name="unit_id">
                        <option value="0">Semua Unit</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $recapUnitId == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div style="display: flex; align-items: flex-end; gap: 8px;">
                    <button type="submit" class="btn btn-primary" style="height: 40px;">
                        <i class="fa-solid fa-filter"></i> Hitung Rekap
                    </button>
                    <button type="button" class="btn" style="height: 40px; background: #f1f5f9; color: #475569;" onclick="setPeriodeBulanIni()">
                        Bulan Ini
                    </button>
                    <button type="button" class="btn" style="height: 40px; background: #f1f5f9; color: #475569;" onclick="setPeriodeBulanLalu()">
                        Bulan Lalu
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- TABEL REKAPITULASI KEHADIRAN & LEMBUR STAFF -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 style="margin: 0; font-size: 17px;">Rekapitulasi Presensi & Lembur Staff</h3>
                <small>Periode: <b><?= date('d M Y', strtotime($recapStartDate)) ?> &mdash; <?= date('d M Y', strtotime($recapEndDate)) ?></b> &bull; Total: <?= count($staffRecapData) ?> Staff</small>
            </div>
        </div>

        <div class="table-wrapper" style="margin-top: 15px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="width: 40px; text-align: center;">No</th>
                        <th>Nama Staff</th>
                        <th>Unit</th>
                        <th>No. WhatsApp</th>
                        <th style="text-align: center;">Hari Kerja</th>
                        <th style="text-align: center;">Hadir (Tepat / Telat)</th>
                        <th style="text-align: center;">Izin / Sakit</th>
                        <th style="text-align: center;">Alpa</th>
                        <th style="text-align: center;">% Kehadiran</th>
                        <th style="text-align: center;">Total Lembur</th>
                        <th style="text-align: center; width: 140px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staffRecapData)): ?>
                        <tr><td colspan="11" style="text-align: center; padding: 30px; color: #94a3b8;">Tidak ada data staff untuk unit/filter ini.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($staffRecapData as $idx => $st): 
                        $canSend = $st['has_phone'] && $st['wa_notify'] === 1;
                    ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="text-align: center; color: #64748b;"><?= $idx + 1 ?></td>
                        <td>
                            <div style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($st['name']) ?></div>
                            <small style="color: #64748b;">NIK: <?= htmlspecialchars($st['nik'] ?: '-') ?></small>
                        </td>
                        <td>
                            <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 11px;">
                                <?= htmlspecialchars($st['unit_name']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($st['has_phone']): ?>
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; background: <?= ($st['wa_notify'] === 1) ? '#dcfce7' : '#f1f5f9' ?>; color: <?= ($st['wa_notify'] === 1) ? '#166534' : '#64748b' ?>; border-radius: 6px; font-size: 11.5px; font-weight: 600;">
                                    <i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars($st['phone']) ?>
                                    <?= ($st['wa_notify'] === 0) ? '<span style="color:#ef4444;">(OFF)</span>' : '' ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size: 11px; color: #94a3b8; font-style: italic;">Belum diisi</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; font-weight: 600;"><?= $st['total_hari_kerja'] ?></td>
                        <td style="text-align: center;">
                            <span style="color: #166534; font-weight: 700;"><?= $st['total_hadir'] ?></span>
                            <small style="color: #64748b;">(<?= $st['tepat_waktu'] ?> / <?= $st['terlambat'] ?>)</small>
                        </td>
                        <td style="text-align: center;">
                            <span style="color: #d97706; font-weight: 600;"><?= $st['izin_sakit'] ?></span>
                        </td>
                        <td style="text-align: center;">
                            <span style="color: <?= $st['alpa'] > 0 ? '#dc2626' : '#64748b' ?>; font-weight: <?= $st['alpa'] > 0 ? '700' : 'normal' ?>;">
                                <?= $st['alpa'] ?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <span class="badge" style="background: #f0fdf4; color: #166534; font-weight: 700; font-size: 11.5px;">
                                <?= $st['persentase_kehadiran'] ?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <span style="font-weight: 700; color: #4338ca;">
                                <?= $st['total_lembur'] ?>
                            </span>
                            <div><small style="color: #64748b;"><?= $st['frekuensi_lembur'] ?> kali lembur</small></div>
                        </td>
                        <td style="text-align: center;">
                            <div style="display: flex; gap: 5px; justify-content: center;">
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('Kirim notifikasi WA rekapan bulanan ke <?= htmlspecialchars($st['name']) ?>?');">
                                    <input type="hidden" name="action" value="send_single_staff_recap">
                                    <input type="hidden" name="staff_id" value="<?= $st['staff_id'] ?>">
                                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($recapStartDate) ?>">
                                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($recapEndDate) ?>">
                                    <input type="hidden" name="unit_id" value="<?= $recapUnitId ?>">
                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 11px; background: #059669; color: white; border: none; border-radius: 6px;" <?= !$canSend ? 'disabled title="Nomor WA belum ada atau dinonaktifkan"' : '' ?>>
                                        <i class="fa-brands fa-whatsapp"></i> Kirim
                                    </button>
                                </form>
                                <button type="button" class="btn" style="padding: 4px 8px; font-size: 11px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 6px;" onclick="openPreviewModal(<?= htmlspecialchars(json_encode($st)) ?>, '<?= $recapStartDate ?>', '<?= $recapEndDate ?>')">
                                    <i class="fa-solid fa-eye"></i> Preview
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL PREVIEW PESAN WHATSAPP REKAP -->
    <div id="modalPreviewRecap" class="modal-overlay">
        <div class="modal-box" style="max-width: 500px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h3 style="margin: 0; font-size: 16px;"><i class="fa-brands fa-whatsapp" style="color: #10b981;"></i> Preview Pesan Rekap Staff</h3>
                <button type="button" onclick="document.getElementById('modalPreviewRecap').style.display='none'" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #94a3b8;">&times;</button>
            </div>
            
            <div style="background: #e5ddd5; padding: 18px; border-radius: 12px; margin-bottom: 15px;">
                <div style="background: #dcf8c6; padding: 14px; border-radius: 10px; font-size: 13px; line-height: 1.5; color: #111b21; white-space: pre-wrap; font-family: sans-serif; box-shadow: 0 1px 2px rgba(0,0,0,0.15);" id="previewText">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569;" onclick="document.getElementById('modalPreviewRecap').style.display='none'">Tutup</button>
            </div>
        </div>
    </div>

    <script>
    function setPeriodeBulanIni() {
        const date = new Date();
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const lastDay = new Date(y, date.getMonth() + 1, 0).getDate();
        document.querySelector('input[name="start_date"]').value = `${y}-${m}-01`;
        document.querySelector('input[name="end_date"]').value = `${y}-${m}-${lastDay}`;
    }

    function setPeriodeBulanLalu() {
        const date = new Date();
        date.setDate(1);
        date.setMonth(date.getMonth() - 1);
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const lastDay = new Date(y, date.getMonth() + 1, 0).getDate();
        document.querySelector('input[name="start_date"]').value = `${y}-${m}-01`;
        document.querySelector('input[name="end_date"]').value = `${y}-${m}-${lastDay}`;
    }

    function openPreviewModal(st, sDate, eDate) {
        let msg = `Halo Bapak/Ibu *${st.name}*,\nBerikut adalah rekapan presensi & lembur Anda untuk periode *${sDate} s/d ${eDate}* di *${st.unit_name}*:\n\n📋 *Ringkasan Kehadiran:*\n• Total Hari Hadir: *${st.total_hadir} hari* (${st.persentase_kehadiran})\n• Hadir Tepat Waktu: *${st.tepat_waktu}*\n• Terlambat: *${st.terlambat}*\n• Izin / Sakit: *${st.izin_sakit}*\n• Alpa: *${st.alpa}*\n\n⏱️ *Rekapitulasi Lembur:*\n• Total Durasi Lembur: *${st.total_lembur}*\n• Frekuensi Lembur: *${st.frekuensi_lembur} kali*\n\nTerima kasih atas dedikasi dan kerja keras Anda.`;
        document.getElementById('previewText').textContent = msg;
        document.getElementById('modalPreviewRecap').style.display = 'flex';
    }
    </script>
<?php endif; ?>


<!-- ========================================================================= -->
<!-- MODAL: EDIT NOMOR WA SISWA                                                -->
<!-- ========================================================================= -->
<div id="modalEditPhone" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); z-index: 2500; justify-content: center; align-items: center; padding: 20px;">
    <div style="background: white; border-radius: 16px; max-width: 480px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden;">
        <div style="padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 17px; color: #0f172a;">
                <i class="fa-brands fa-whatsapp" style="color: #10b981; margin-right: 8px;"></i> Edit Nomor WA Orang Tua
            </h3>
            <button type="button" onclick="closeEditPhoneModal()" style="background: none; border: none; font-size: 20px; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="update_student_phone">
            <input type="hidden" name="student_id" id="modalStudentId" value="">

            <div style="padding: 24px;">
                <div style="margin-bottom: 14px;">
                    <label style="font-size: 12px; color: #64748b;">Nama Siswa</label>
                    <div id="modalStudentName" style="font-weight: 700; font-size: 15px; color: #1e293b;">-</div>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-size: 13px; font-weight: 600; color: #334155;">Nomor WhatsApp Orang Tua / Wali *</label>
                    <input type="text" name="parent_phone" id="modalParentPhone" placeholder="Contoh: 081234567890" style="font-size: 14px; font-weight: 600;">
                    <small style="color: #64748b;">Gunakan awalan 08 atau 62.</small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" name="wa_notify" id="modalWaNotify" value="1" style="width: 18px; height: 18px; accent-color: #10b981;">
                        <span>Aktifkan Notifikasi WhatsApp untuk Siswa Ini</span>
                    </label>
                </div>
            </div>

            <div style="padding: 14px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditPhoneModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="background: #059669; border-color: #059669;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan No WA
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditPhoneModal(studentId, studentName, parentPhone, waNotify) {
    document.getElementById('modalStudentId').value = studentId;
    document.getElementById('modalStudentName').innerText = studentName;
    document.getElementById('modalParentPhone').value = parentPhone || '';
    document.getElementById('modalWaNotify').checked = (waNotify === 1);
    
    const modal = document.getElementById('modalEditPhone');
    modal.style.display = 'flex';
}

function closeEditPhoneModal() {
    document.getElementById('modalEditPhone').style.display = 'none';
}
</script>

<?php require '../../includes/footer.php'; ?>
