<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireLogin();
checkUserAccess('auto_attendance');

global $pdo;

$currentUserId = currentUserId();
$currentRole = currentRole();
$currentUserName = (function_exists('currentUserName') ? currentUserName() : null) ?: ($_SESSION['name'] ?? $_SESSION['username'] ?? 'Administrator');
$pageTitle = 'Kontrol & Pengaturan Absen Otomatis (Auto-Alpha)';

$isSuperAdmin = ($currentRole === 'super_admin');
$isKepsek = ($currentRole === 'kepala_sekolah');

if (!$isSuperAdmin && !$isKepsek) {
    flash('error', 'Hanya Super Admin dan Kepala Sekolah yang berhak mengatur kontrol absen otomatis.');
    redirect(BASE_URL . '/dashboard.php');
}

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
// PROSES AKSI POST: TOGGLE GLOBAL / UNIT, TAMBAH ATURAN, HAPUS ATURAN
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Toggle Global Absen Otomatis (Super Admin Only)
    if ($action === 'toggle_global' && $isSuperAdmin) {
        $status = (int)($_POST['is_disabled'] ?? 0);
        
        $check = $pdo->query("SELECT id FROM auto_attendance_exclusions WHERE scope_type = 'global' LIMIT 1")->fetch();
        if ($check) {
            $stmt = $pdo->prepare("UPDATE auto_attendance_exclusions SET is_disabled = ?, created_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $currentUserName, $check['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO auto_attendance_exclusions (scope_type, target_id, target_name, is_disabled, notes, created_by) VALUES ('global', 0, 'Seluruh Sistem (Global)', ?, 'Dimatikan secara global', ?)");
            $stmt->execute([$status, $currentUserName]);
        }

        $logAction = $status === 1 ? 'Matikan Absen Otomatis Global' : 'Aktifkan Kembali Absen Otomatis Global';
        recordActivityAudit($pdo, 'auto_attendance', 'UPDATE', 'auto_attendance_exclusions', $check['id'] ?? 1, $logAction, null, ['scope' => 'global', 'status' => $status], null);

        flash('success', "Absen otomatis berhasil " . ($status === 1 ? 'DIMATIKAN' : 'DIAKTIFKAN') . " secara global.");
        redirect('auto_attendance.php');
    }

    // 2. Toggle Unit Master (Super Admin & Kepala Sekolah)
    if ($action === 'toggle_unit_master') {
        $targetUnitId = $isSuperAdmin ? (int)($_POST['unit_id'] ?? 0) : $ksUnitId;
        $status = (int)($_POST['is_disabled'] ?? 0);

        if ($targetUnitId > 0) {
            $stmtU = $pdo->prepare("SELECT unit FROM units WHERE id = ?");
            $stmtU->execute([$targetUnitId]);
            $uName = $stmtU->fetchColumn() ?: "Unit #$targetUnitId";

            $check = $pdo->prepare("SELECT id FROM auto_attendance_exclusions WHERE scope_type = 'unit' AND target_id = ? LIMIT 1");
            $check->execute([$targetUnitId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $pdo->prepare("UPDATE auto_attendance_exclusions SET is_disabled = ?, created_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$status, $currentUserName, $existing['id']]);
                $ruleId = $existing['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO auto_attendance_exclusions (scope_type, target_id, target_name, is_disabled, notes, created_by) VALUES ('unit', ?, ?, ?, 'Dimatikan per unit', ?)");
                $stmt->execute([$targetUnitId, "Unit: $uName", $status, $currentUserName]);
                $ruleId = (int)$pdo->lastInsertId();
            }

            $logAction = $status === 1 ? "Matikan Absen Otomatis Unit $uName" : "Aktifkan Kembali Absen Otomatis Unit $uName";
            recordActivityAudit($pdo, 'auto_attendance', 'UPDATE', 'auto_attendance_exclusions', $ruleId, $logAction, null, ['scope' => 'unit', 'unit_id' => $targetUnitId, 'status' => $status], $targetUnitId);

            flash('success', "Absen otomatis untuk Unit {$uName} berhasil " . ($status === 1 ? 'DIMATIKAN' : 'DIAKTIFKAN') . ".");
        }
        redirect('auto_attendance.php');
    }

    // 3. Tambah Aturan Pengecualian Baru
    if ($action === 'add_exclusion') {
        $scopeType = trim($_POST['scope_type'] ?? 'unit');
        $targetId = 0;
        $targetName = '';
        $desc = trim($_POST['description'] ?? '');
        $ruleUnitId = null;

        if ($scopeType === 'unit') {
            $targetId = $isSuperAdmin ? (int)($_POST['target_unit_id'] ?? 0) : $ksUnitId;
            $stmtTn = $pdo->prepare("SELECT unit FROM units WHERE id = ?");
            $stmtTn->execute([$targetId]);
            $targetName = "Unit: " . ($stmtTn->fetchColumn() ?: "#$targetId");
            $ruleUnitId = $targetId;
        } elseif ($scopeType === 'grade') {
            $targetId = (int)($_POST['target_grade_id'] ?? 0);
            $stmtTn = $pdo->prepare("SELECT g.unit_id, CONCAT(g.grade, ' (', u.unit, ')') as g_name FROM grades g JOIN units u ON u.id = g.unit_id WHERE g.id = ?");
            $stmtTn->execute([$targetId]);
            $gRow = $stmtTn->fetch(PDO::FETCH_ASSOC);
            if ($gRow) {
                if ($isKepsek && (int)$gRow['unit_id'] !== $ksUnitId) {
                    flash('error', 'Akses ditolak. Tingkat/Jenjang berada di luar wewenang unit Anda.');
                    redirect('auto_attendance.php');
                }
                $targetName = "Jenjang: " . $gRow['g_name'];
                $ruleUnitId = (int)$gRow['unit_id'];
            }
        } elseif ($scopeType === 'class_group') {
            $targetId = (int)($_POST['target_class_id'] ?? 0);
            $stmtTn = $pdo->prepare("SELECT g.unit_id, CONCAT(cg.name, ' - Tingkat ', g.grade, ' (', u.unit, ')') as c_name FROM class_groups cg JOIN grades g ON g.id = cg.grade_id JOIN units u ON u.id = g.unit_id WHERE cg.id = ?");
            $stmtTn->execute([$targetId]);
            $cRow = $stmtTn->fetch(PDO::FETCH_ASSOC);
            if ($cRow) {
                if ($isKepsek && (int)$cRow['unit_id'] !== $ksUnitId) {
                    flash('error', 'Akses ditolak. Subkelas berada di luar wewenang unit Anda.');
                    redirect('auto_attendance.php');
                }
                $targetName = "Subkelas: " . $cRow['c_name'];
                $ruleUnitId = (int)$cRow['unit_id'];
            }
        } elseif ($scopeType === 'staff') {
            $targetId = (int)($_POST['target_staff_id'] ?? 0);
            $stmtTn = $pdo->prepare("SELECT st.unit_id, CONCAT(st.name, ' (NIK: ', st.nik, ')') as s_name, u.unit as unit_name FROM staff st LEFT JOIN units u ON u.id = st.unit_id WHERE st.id = ?");
            $stmtTn->execute([$targetId]);
            $sfRow = $stmtTn->fetch(PDO::FETCH_ASSOC);
            if ($sfRow) {
                if ($isKepsek && (int)$sfRow['unit_id'] !== $ksUnitId) {
                    flash('error', 'Akses ditolak. Guru/Staff berada di luar wewenang unit Anda.');
                    redirect('auto_attendance.php');
                }
                $targetName = "Guru/Staff: " . $sfRow['s_name'] . ($sfRow['unit_name'] ? " [{$sfRow['unit_name']}]" : "");
                $ruleUnitId = (int)$sfRow['unit_id'];
            }
        } elseif ($scopeType === 'student') {
            $targetId = (int)($_POST['target_student_id'] ?? 0);
            $stmtTn = $pdo->prepare("
                SELECT g.unit_id, CONCAT(s.name, ' (NIS: ', s.nis, ') - ', cg.name) as st_name 
                FROM students s 
                LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
                LEFT JOIN class_groups cg ON cg.id = se.class_group_id
                LEFT JOIN grades g ON g.id = cg.grade_id
                WHERE s.id = ?
            ");
            $stmtTn->execute([$targetId]);
            $stRow = $stmtTn->fetch(PDO::FETCH_ASSOC);
            if ($stRow) {
                if ($isKepsek && (int)$stRow['unit_id'] !== $ksUnitId) {
                    flash('error', 'Akses ditolak. Siswa berada di luar wewenang unit Anda.');
                    redirect('auto_attendance.php');
                }
                $targetName = "Siswa: " . $stRow['st_name'];
                $ruleUnitId = (int)$stRow['unit_id'];
            }
        }

        if ($targetId <= 0) {
            flash('error', 'Silakan pilih target yang valid untuk dimatikan absen otomatisnya.');
            redirect('auto_attendance.php');
        }

        // Cek duplikasi aturan
        $checkDup = $pdo->prepare("SELECT id FROM auto_attendance_exclusions WHERE scope_type = ? AND target_id = ? LIMIT 1");
        $checkDup->execute([$scopeType, $targetId]);
        if ($checkDup->fetch()) {
            flash('error', 'Aturan untuk target ini sudah ada dalam daftar.');
            redirect('auto_attendance.php');
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO auto_attendance_exclusions 
            (scope_type, target_id, target_name, is_disabled, notes, created_by) 
            VALUES (?, ?, ?, 1, ?, ?)
        ");
        $stmtIns->execute([$scopeType, $targetId, $targetName, $desc ?: 'Dimatikan oleh ' . ($isKepsek ? 'Kepala Sekolah' : 'Admin'), $currentUserName]);
        $newId = (int)$pdo->lastInsertId();

        recordActivityAudit($pdo, 'auto_attendance', 'CREATE', 'auto_attendance_exclusions', $newId, "Pengecualian absen otomatis ({$scopeType}: {$targetName})", null, [
            'scope' => $scopeType,
            'target_id' => $targetId,
            'target_name' => $targetName,
            'notes' => $desc
        ], $ruleUnitId);

        flash('success', 'Aturan pematian absen otomatis berhasil ditambahkan.');
        redirect('auto_attendance.php');
    }

    // 4. Hapus Aturan Pengecualian
    if ($action === 'delete_exclusion') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmtOld = $pdo->prepare("SELECT * FROM auto_attendance_exclusions WHERE id = ?");
            $stmtOld->execute([$id]);
            $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if ($oldData) {
                // Validasi otorisasi jika kepala sekolah
                if ($isKepsek) {
                    $st = $oldData['scope_type'];
                    $tid = (int)$oldData['target_id'];
                    $canDelete = false;

                    if ($st === 'unit' && $tid === $ksUnitId) $canDelete = true;
                    elseif ($st === 'grade') {
                        $gu = (int)$pdo->query("SELECT unit_id FROM grades WHERE id = $tid")->fetchColumn();
                        if ($gu === $ksUnitId) $canDelete = true;
                    } elseif ($st === 'class_group') {
                        $cu = (int)$pdo->query("SELECT g.unit_id FROM class_groups cg JOIN grades g ON g.id = cg.grade_id WHERE cg.id = $tid")->fetchColumn();
                        if ($cu === $ksUnitId) $canDelete = true;
                    } elseif ($st === 'staff') {
                        $su = (int)$pdo->query("SELECT unit_id FROM staff WHERE id = $tid")->fetchColumn();
                        if ($su === $ksUnitId) $canDelete = true;
                    } elseif ($st === 'student') {
                        $stuU = (int)$pdo->query("SELECT g.unit_id FROM student_enrollments se JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id WHERE se.student_id = $tid AND se.status = 'active' LIMIT 1")->fetchColumn();
                        if ($stuU === $ksUnitId) $canDelete = true;
                    }

                    if (!$canDelete) {
                        flash('error', 'Akses ditolak. Anda tidak berhak menghapus aturan unit lain.');
                        redirect('auto_attendance.php');
                    }
                }

                recordActivityAudit($pdo, 'auto_attendance', 'DELETE', 'auto_attendance_exclusions', $id, "Hapus aturan pengecualian absen otomatis #$id", $oldData, null, ($oldData['scope_type'] === 'unit' ? (int)$oldData['target_id'] : null));
            }

            $stmtDel = $pdo->prepare("DELETE FROM auto_attendance_exclusions WHERE id = ?");
            $stmtDel->execute([$id]);
            flash('success', 'Aturan berhasil dihapus. Absen otomatis kembali aktif untuk target tersebut.');
        }
        redirect('auto_attendance.php');
    }
}

// Cek status global & unit master
$isGlobalDisabled = (bool)$pdo->query("SELECT is_disabled FROM auto_attendance_exclusions WHERE scope_type = 'global' AND is_disabled = 1 LIMIT 1")->fetchColumn();

$isUnitDisabled = false;
if ($isKepsek && $ksUnitId) {
    $stmtUnitDis = $pdo->prepare("SELECT is_disabled FROM auto_attendance_exclusions WHERE scope_type = 'unit' AND target_id = ? AND is_disabled = 1 LIMIT 1");
    $stmtUnitDis->execute([$ksUnitId]);
    $isUnitDisabled = (bool)$stmtUnitDis->fetchColumn();
}

// Ambil daftar aturan
$rulesStmt = $pdo->query("
    SELECT aae.*,
           CASE 
               WHEN aae.scope_type = 'unit' THEN (SELECT u.unit FROM units u WHERE u.id = aae.target_id)
               WHEN aae.scope_type = 'grade' THEN (SELECT CONCAT(g.grade, ' (', un.unit, ')') FROM grades g JOIN units un ON un.id = g.unit_id WHERE g.id = aae.target_id)
               WHEN aae.scope_type = 'class_group' THEN (SELECT CONCAT(cg.name, ' - Tingkat ', g.grade, ' (', un.unit, ')') FROM class_groups cg JOIN grades g ON g.id = cg.grade_id JOIN units un ON un.id = g.unit_id WHERE cg.id = aae.target_id)
               WHEN aae.scope_type = 'student' THEN (SELECT CONCAT(s.name, ' (NIS: ', s.nis, ')') FROM students s WHERE s.id = aae.target_id)
               WHEN aae.scope_type = 'staff' THEN (SELECT CONCAT(st.name, ' (NIK: ', st.nik, ') [', COALESCE(u.unit, 'Staff'), ']') FROM staff st LEFT JOIN units u ON u.id = st.unit_id WHERE st.id = aae.target_id)
               ELSE 'Seluruh Sistem (Global)'
           END as display_target_name,
           CASE
               WHEN aae.scope_type = 'unit' THEN aae.target_id
               WHEN aae.scope_type = 'grade' THEN (SELECT g.unit_id FROM grades g WHERE g.id = aae.target_id)
               WHEN aae.scope_type = 'class_group' THEN (SELECT g.unit_id FROM class_groups cg JOIN grades g ON g.id = cg.grade_id WHERE cg.id = aae.target_id)
               WHEN aae.scope_type = 'staff' THEN (SELECT st.unit_id FROM staff st WHERE st.id = aae.target_id)
               WHEN aae.scope_type = 'student' THEN (SELECT g.unit_id FROM student_enrollments se JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id WHERE se.student_id = aae.target_id AND se.status = 'active' LIMIT 1)
               ELSE NULL
           END as resolved_unit_id
    FROM auto_attendance_exclusions aae
    ORDER BY aae.scope_type ASC, aae.id DESC
");
$allRulesRaw = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);

$rules = [];
foreach ($allRulesRaw as $r) {
    if ($isSuperAdmin) {
        $rules[] = $r;
    } elseif ($isKepsek && $ksUnitId) {
        if ($r['scope_type'] !== 'global' && (int)$r['resolved_unit_id'] === $ksUnitId) {
            $rules[] = $r;
        }
    }
}

// Master data dropdowns sesuai hak akses
if ($isSuperAdmin) {
    $allUnits = $pdo->query("SELECT id, unit FROM units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allGrades = $pdo->query("SELECT g.id, g.grade, u.unit as unit_name FROM grades g JOIN units u ON u.id = g.unit_id ORDER BY u.id ASC, g.sort_order ASC, g.grade ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allClasses = $pdo->query("SELECT cg.id, cg.name, g.grade, u.unit as unit_name FROM class_groups cg JOIN grades g ON g.id = cg.grade_id JOIN units u ON u.id = g.unit_id ORDER BY u.id ASC, cg.name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allStaff = $pdo->query("SELECT st.id, st.name, st.nik, u.unit as unit_name FROM staff st LEFT JOIN units u ON u.id = st.unit_id WHERE st.deleted_at IS NULL ORDER BY u.id ASC, st.name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allStudents = $pdo->query("SELECT s.id, s.name, s.nis, u.unit as unit_name, cg.name as class_name FROM students s JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active' JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id JOIN units u ON u.id = g.unit_id WHERE s.deleted_at IS NULL ORDER BY u.id ASC, cg.name ASC, s.name ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} else {
    // KEPALA SEKOLAH: HANYA TAMPILKAN UNITNYA SENDIRI
    $allUnits = $ksUnitId ? [['id' => $ksUnitId, 'unit' => $ksUnitName]] : [];
    $allGrades = $pdo->prepare("SELECT g.id, g.grade, u.unit as unit_name FROM grades g JOIN units u ON u.id = g.unit_id WHERE g.unit_id = ? ORDER BY g.sort_order ASC, g.grade ASC");
    $allGrades->execute([$ksUnitId]);
    $allGrades = $allGrades->fetchAll(PDO::FETCH_ASSOC);

    $allClasses = $pdo->prepare("SELECT cg.id, cg.name, g.grade, u.unit as unit_name FROM class_groups cg JOIN grades g ON g.id = cg.grade_id JOIN units u ON u.id = g.unit_id WHERE g.unit_id = ? ORDER BY cg.name ASC");
    $allClasses->execute([$ksUnitId]);
    $allClasses = $allClasses->fetchAll(PDO::FETCH_ASSOC);

    $allStaff = $pdo->prepare("SELECT st.id, st.name, st.nik, u.unit as unit_name FROM staff st LEFT JOIN units u ON u.id = st.unit_id WHERE st.deleted_at IS NULL AND st.unit_id = ? ORDER BY st.name ASC");
    $allStaff->execute([$ksUnitId]);
    $allStaff = $allStaff->fetchAll(PDO::FETCH_ASSOC);

    $allStudents = $pdo->prepare("SELECT s.id, s.name, s.nis, u.unit as unit_name, cg.name as class_name FROM students s JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active' JOIN class_groups cg ON cg.id = se.class_group_id JOIN grades g ON g.id = cg.grade_id JOIN units u ON u.id = g.unit_id WHERE s.deleted_at IS NULL AND g.unit_id = ? ORDER BY cg.name ASC, s.name ASC");
    $allStudents->execute([$ksUnitId]);
    $allStudents = $allStudents->fetchAll(PDO::FETCH_ASSOC);
}

require '../../includes/header.php';
?>

<!-- BANNER STATUS ABSEN OTOMATIS -->
<?php if ($isSuperAdmin): ?>
    <div class="card" style="margin-bottom: 24px; padding: 22px 24px; border-left: 5px solid <?= $isGlobalDisabled ? '#ef4444' : '#10b981' ?>; background: #ffffff;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-power-off" style="font-size: 26px; color: <?= $isGlobalDisabled ? '#ef4444' : '#10b981' ?>;"></i>
                    <h3 style="margin: 0; font-size: 20px; font-weight: 800; color: #0f172a;">
                        Sakelar Master Global: Absen Otomatis (Auto-Alpha)
                    </h3>
                </div>
                <p style="margin: 6px 0 0 0; color: #64748b; font-size: 13.5px; max-width: 750px;">
                    Ketika aktif, sistem menandai siswa/staff yang belum hadir saat kepulangan. Anda dapat mematikan fitur ini secara global, atau membuat aturan perkecil per unit, jenjang, subkelas, maupun guru/murid tertentu.
                </p>
            </div>

            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="text-align: right;">
                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #64748b;">Status Global</div>
                    <div style="font-size: 15px; font-weight: 800; color: <?= $isGlobalDisabled ? '#dc2626' : '#16a34a' ?>;">
                        <?= $isGlobalDisabled ? '🔴 DIMATIKAN (GLOBAL)' : '🟢 AKTIF BERJALAN' ?>
                    </div>
                </div>

                <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin mengubah status master absen otomatis secara global?');">
                    <input type="hidden" name="action" value="toggle_global">
                    <input type="hidden" name="is_disabled" value="<?= $isGlobalDisabled ? '0' : '1' ?>">
                    <button type="submit" class="btn <?= $isGlobalDisabled ? 'btn-success' : 'btn-danger' ?>" style="font-size: 13.5px; padding: 10px 18px; border-radius: 8px;">
                        <i class="fa-solid fa-power-off"></i> <?= $isGlobalDisabled ? 'Aktifkan Kembali Global' : 'Matikan Global (Semua)' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
<?php elseif ($isKepsek): ?>
    <div class="card" style="margin-bottom: 24px; padding: 22px 24px; border-left: 5px solid <?= $isUnitDisabled ? '#ef4444' : '#10b981' ?>; background: #ffffff;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-building-circle-check" style="font-size: 26px; color: <?= $isUnitDisabled ? '#ef4444' : '#10b981' ?>;"></i>
                    <h3 style="margin: 0; font-size: 20px; font-weight: 800; color: #0f172a;">
                        Kontrol Absen Otomatis Unit <?= e($ksUnitName) ?>
                    </h3>
                </div>
                <p style="margin: 6px 0 0 0; color: #64748b; font-size: 13.5px; max-width: 750px;">
                    Anda berwenang mengelola pengecualian absen otomatis (Auto-Alpha) untuk unit <strong><?= e($ksUnitName) ?></strong>. Anda dapat mematikan seluruh unit atau memilih jenjang, subkelas, guru/staff tertentu, maupun siswa tertentu.
                </p>
            </div>

            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="text-align: right;">
                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #64748b;">Status Unit <?= e($ksUnitName) ?></div>
                    <div style="font-size: 15px; font-weight: 800; color: <?= $isUnitDisabled ? '#dc2626' : '#16a34a' ?>;">
                        <?= $isUnitDisabled ? '🔴 DIMATIKAN (UNIT)' : '🟢 AKTIF BERJALAN' ?>
                    </div>
                </div>

                <form method="POST" onsubmit="return confirm('Ubah status absen otomatis untuk Unit <?= e($ksUnitName) ?>?');">
                    <input type="hidden" name="action" value="toggle_unit_master">
                    <input type="hidden" name="is_disabled" value="<?= $isUnitDisabled ? '0' : '1' ?>">
                    <button type="submit" class="btn <?= $isUnitDisabled ? 'btn-success' : 'btn-danger' ?>" style="font-size: 13.5px; padding: 10px 18px; border-radius: 8px;">
                        <i class="fa-solid fa-power-off"></i> <?= $isUnitDisabled ? "Aktifkan Unit {$ksUnitName}" : "Matikan Unit {$ksUnitName}" ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px; margin-bottom: 24px;">

    <!-- FORM TAMBAH PENGECUALIAN BERTINGKAT -->
    <div class="card" style="margin: 0;">
        <div class="card-header">
            <div>
                <h3 style="margin: 0; font-size: 16px;">
                    <i class="fa-solid fa-sliders" style="color: #0284c7; margin-right: 8px;"></i> Buat Pengecualian Absen Otomatis
                </h3>
                <small class="text-muted">
                    <?= $isKepsek ? "Atur pengecualian khusus untuk guru, jenjang, subkelas, atau murid di Unit {$ksUnitName}" : "Pilih unit, jenjang, subkelas, murid, atau staf yang ingin dimatikan absen otomatisnya" ?>
                </small>
            </div>
        </div>

        <form method="POST" style="padding: 20px;">
            <input type="hidden" name="action" value="add_exclusion">

            <div style="margin-bottom: 16px;">
                <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">
                    Pilih Target Cakupan Pengecualian:
                </label>
                <select name="scope_type" id="scopeTypeSelect" class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 6px; font-size: 13px;" onchange="handleScopeChange()">
                    <?php if ($isSuperAdmin): ?>
                        <option value="unit">1. Tingkat Unit Sekolah (Seluruh Siswa & Staff Unit)</option>
                    <?php endif; ?>
                    <option value="grade">1. Tingkat Jenjang / Grade (Misal: Kelas 1, Kelas 7, Kelas 10)</option>
                    <option value="class_group">2. Tingkat Subkelas / Rombel (Misal: 1-A, 7-B, 12-IPA)</option>
                    <option value="staff">3. Guru / Staff Tertentu (Pilih Guru / Karyawan)</option>
                    <option value="student">4. Murid / Siswa Tertentu (Pilih Siswa)</option>
                </select>
            </div>

            <!-- SELECT UNIT (Super Admin Only) -->
            <?php if ($isSuperAdmin): ?>
            <div id="wrapperUnit" style="margin-bottom: 16px;">
                <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Pilih Unit Sekolah:</label>
                <select name="target_unit_id" class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 6px; font-size: 13px;">
                    <option value="">-- Pilih Unit --</option>
                    <?php foreach ($allUnits as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['unit']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
                <input type="hidden" name="target_unit_id" value="<?= (int)$ksUnitId ?>">
            <?php endif; ?>

            <!-- SELECT GRADE -->
            <div id="wrapperGrade" style="margin-bottom: 16px;">
                <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">
                    Pilih Tingkat / Jenjang <?= $isKepsek ? "(Unit $ksUnitName)" : "" ?>:
                </label>
                <select name="target_grade_id" class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 6px; font-size: 13px;">
                    <option value="">-- Pilih Jenjang --</option>
                    <?php foreach ($allGrades as $g): ?>
                        <option value="<?= $g['id'] ?>">Tingkat <?= e($g['grade']) ?> <?= $isSuperAdmin ? "({$g['unit_name']})" : "" ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- SELECT CLASS -->
            <div id="wrapperClass" style="margin-bottom: 16px; display: none;">
                <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">
                    Pilih Subkelas / Rombel <?= $isKepsek ? "(Unit $ksUnitName)" : "" ?>:
                </label>
                <select name="target_class_id" class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 6px; font-size: 13px;">
                    <option value="">-- Pilih Subkelas --</option>
                    <?php foreach ($allClasses as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (Tingkat <?= e($c['grade']) ?>) <?= $isSuperAdmin ? " - {$c['unit_name']}" : "" ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- SELECT STAFF / GURU (CUSTOM GURU TERTENTU) -->
            <div id="wrapperStaff" style="margin-bottom: 16px; display: none;">
                <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">
                    <i class="fa-solid fa-chalkboard-user" style="color:#0284c7;"></i> Pilih Guru / Staff Tertentu <?= $isKepsek ? "(Khusus Unit $ksUnitName)" : "" ?>:
                </label>
                <select name="target_staff_id" class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 6px; font-size: 13px;">
                    <option value="">-- Pilih Guru / Staff --</option>
                    <?php foreach ($allStaff as $sf): ?>
                        <option value="<?= $sf['id'] ?>"><?= e($sf['name']) ?> (NIK: <?= e($sf['nik'] ?: '-') ?>) <?= $isSuperAdmin ? "[{$sf['unit_name']}]" : "" ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #64748b; margin-top: 4px; display: block;">
                    Guru/Staff yang dipilih tidak akan ditandai Alpha otomatis oleh sistem saat jam pulang.
                </small>
            </div>

            <!-- SELECT STUDENT -->
            <div id="wrapperStudent" style="margin-bottom: 16px; display: none;">
                <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">
                    Pilih Murid / Siswa Spesifik <?= $isKepsek ? "(Unit $ksUnitName)" : "" ?>:
                </label>
                <select name="target_student_id" class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 6px; font-size: 13px;">
                    <option value="">-- Pilih Siswa --</option>
                    <?php foreach ($allStudents as $st): ?>
                        <option value="<?= $st['id'] ?>"><?= e($st['name']) ?> (NIS: <?= e($st['nis']) ?>) - <?= e($st['class_name']) ?> <?= $isSuperAdmin ? "[{$st['unit_name']}]" : "" ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">
                    Alasan / Keterangan Pengecualian:
                </label>
                <input type="text" name="description" placeholder="Contoh: Tugas luar kota, dispensasi lomba, sakit berkepanjangan, dll." class="form-control" style="width: 100%; padding: 8px 12px; border-radius: 6px; font-size: 13px;">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; font-size: 14px; border-radius: 6px;">
                <i class="fa-solid fa-plus"></i> Simpan Pengecualian
            </button>
        </form>
    </div>

    <!-- PANDUAN PENGGUNAAN -->
    <div class="card" style="margin: 0; background: #f8fafc;">
        <div class="card-header">
            <h3 style="margin: 0; font-size: 16px; color: #1e293b;">
                <i class="fa-solid fa-circle-question" style="color: #0284c7; margin-right: 8px;"></i> Panduan & Hirarki Pengecualian
            </h3>
        </div>
        <div style="padding: 20px; font-size: 13px; color: #475569; line-height: 1.6;">
            <p style="margin-top:0;">
                Fitur ini digunakan untuk <strong>mencegah sistem menandai Alpha secara otomatis</strong> pada siswa atau guru tertentu tanpa perlu menghapus jadwal absensi mereka.
            </p>

            <h5 style="margin: 14px 0 6px 0; color: #1e293b; font-size: 13.5px;">Urutan Evaluasi Sistem:</h5>
            <ol style="margin: 0; padding-left: 20px;">
                <li style="margin-bottom: 4px;"><strong>Global</strong>: Mematikan seluruh proses auto-alpha di seluruh sekolah.</li>
                <li style="margin-bottom: 4px;"><strong>Unit</strong>: Mematikan auto-alpha untuk 1 unit sekolah tertentu (misal: Unit Security atau Unit <?= e($ksUnitName ?: 'SD') ?>).</li>
                <li style="margin-bottom: 4px;"><strong>Jenjang / Grade</strong>: Mematikan auto-alpha untuk satu tingkat jenjang (misal: Tingkat 12 yang sedang ujian).</li>
                <li style="margin-bottom: 4px;"><strong>Subkelas</strong>: Mematikan auto-alpha untuk satu rombel kelas tertentu (misal: Kelas 7-A yang sedang *field trip*).</li>
                <li style="margin-bottom: 4px;"><strong>Guru / Staff</strong>: Mematikan auto-alpha untuk guru tertentu (misal: dinas luar, cuti, atau pelatihan).</li>
                <li><strong>Murid / Siswa</strong>: Mematikan auto-alpha untuk anak tertentu yang berhalangan hadir jangka panjang.</li>
            </ol>
        </div>
    </div>

</div>

<!-- TABEL DAFTAR ATURAN PENGECUALIAN AKTIF -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h3 style="margin:0; font-size:16px;">
                <i class="fa-solid fa-list-check" style="color:#0284c7; margin-right:8px;"></i> Daftar Pengecualian Absen Otomatis Aktif
            </h3>
            <small class="text-muted">
                <?= $isKepsek ? "Daftar aturan aktif pada Unit {$ksUnitName}" : "Seluruh aturan aktif di semua unit" ?>
            </small>
        </div>
        <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700; font-size:12px; padding:4px 10px; border-radius:6px;">
            <?= count($rules) ?> Aturan Aktif
        </span>
    </div>

    <div class="table-wrapper">
        <table class="table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #e2e8f0;">
                    <th style="padding:12px 14px; width:150px;">Tingkat Cakupan</th>
                    <th style="padding:12px 14px;">Target Pengecualian</th>
                    <th style="padding:12px 14px;">Keterangan / Alasan</th>
                    <th style="padding:12px 14px;">Dibuat Oleh</th>
                    <th style="padding:12px 14px; text-align:center; width:120px;">Status</th>
                    <th style="padding:12px 14px; text-align:center; width:120px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rules)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:30px; color:#64748b;">
                            Belum ada aturan pengecualian aktif. Absen otomatis saat ini berjalan normal untuk semua siswa dan guru.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rules as $r): ?>
                    <?php
                    $badgeStyle = [
                        'global'      => ['bg' => '#fee2e2', 'color' => '#b91c1c', 'label' => 'GLOBAL'],
                        'unit'        => ['bg' => '#e0e7ff', 'color' => '#4338ca', 'label' => 'UNIT'],
                        'grade'       => ['bg' => '#fef3c7', 'color' => '#b45309', 'label' => 'JENJANG'],
                        'class_group' => ['bg' => '#dcfce7', 'color' => '#15803d', 'label' => 'SUBKELAS'],
                        'staff'       => ['bg' => '#f3e8ff', 'color' => '#7e22ce', 'label' => 'GURU/STAFF'],
                        'student'     => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'label' => 'SISWA'],
                    ][$r['scope_type']] ?? ['bg' => '#f1f5f9', 'color' => '#475569', 'label' => strtoupper($r['scope_type'])];
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px 14px;">
                            <span class="badge" style="background:<?= $badgeStyle['bg'] ?>; color:<?= $badgeStyle['color'] ?>; font-weight:700; font-size:11px; padding:3px 8px; border-radius:4px;">
                                <?= $badgeStyle['label'] ?>
                            </span>
                        </td>

                        <td style="padding:12px 14px;">
                            <strong style="color:#0f172a; font-size:13.5px;"><?= e($r['display_target_name'] ?: $r['target_name']) ?></strong>
                            <div style="font-size:11px; color:#64748b; font-family:monospace;">ID: #<?= (int)$r['target_id'] ?></div>
                        </td>

                        <td style="padding:12px 14px; font-size:13px; color:#475569;">
                            <?= e($r['notes'] ?: '-') ?>
                        </td>

                        <td style="padding:12px 14px; font-size:12px; color:#64748b;">
                            <?= e($r['created_by'] ?: 'Admin') ?><br>
                            <span style="font-size:11px; color:#94a3b8;"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></span>
                        </td>

                        <td style="padding:12px 14px; text-align:center;">
                            <span class="badge" style="background:#fee2e2; color:#b91c1c; font-size:11px; padding:3px 8px; border-radius:4px; font-weight:700;">
                                DIMATIKAN
                            </span>
                        </td>

                        <td style="padding:12px 14px; text-align:center;">
                            <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus aturan pengecualian ini? Absen otomatis akan kembali aktif untuk target ini.');">
                                <input type="hidden" name="action" value="delete_exclusion">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="font-size:11px; padding:4px 10px; border-radius:4px; cursor:pointer;">
                                    <i class="fa-solid fa-trash"></i> Hapus
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function handleScopeChange() {
    const sel = document.getElementById('scopeTypeSelect').value;
    const wUnit = document.getElementById('wrapperUnit');
    const wGrade = document.getElementById('wrapperGrade');
    const wClass = document.getElementById('wrapperClass');
    const wStudent = document.getElementById('wrapperStudent');
    const wStaff = document.getElementById('wrapperStaff');

    if (wUnit) wUnit.style.display = (sel === 'unit') ? 'block' : 'none';
    if (wGrade) wGrade.style.display = (sel === 'grade') ? 'block' : 'none';
    if (wClass) wClass.style.display = (sel === 'class_group') ? 'block' : 'none';
    if (wStudent) wStudent.style.display = (sel === 'student') ? 'block' : 'none';
    if (wStaff) wStaff.style.display = (sel === 'staff') ? 'block' : 'none';
}
document.addEventListener("DOMContentLoaded", handleScopeChange);
</script>

<?php require '../../includes/footer.php'; ?>
