<?php

if (!defined('CAL_GREGORIAN')) {
    define('CAL_GREGORIAN', 0);
}

if (!function_exists('cal_days_in_month')) {
    function cal_days_in_month($calendar, $month, $year) {
        return (int)date('t', mktime(0, 0, 0, (int)$month, 1, (int)$year));
    }
}


if (!function_exists('redirect')) {
    function redirect($url) {
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }
        echo '<script>window.location.href = ' . json_encode($url) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

if (!function_exists('flash')) {
    function flash($type, $message) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'] = [
            'type'    => $type,
            'message' => $message
        ];
    }
}

if (!function_exists('getFlash')) {
    function getFlash() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }
}

if (!function_exists('setFlash')) {
    function setFlash($type, $message) {
        flash($type, $message);
    }
}

// =========================================================================
// ACCESS CONTROL — DATABASE-DRIVEN ACL DENGAN GRANULAR CRUD & MULTI-ROLE
// =========================================================================

/**
 * Dipanggil setelah login ATAU setelah Super Admin mengubah hak akses user.
 * Mengisi $_SESSION['roles'], $_SESSION['menu_keys'], dan $_SESSION['menu_permissions'].
 */
if (!function_exists('loadUserPermissions')) {
    function loadUserPermissions(PDO $pdo, int $userId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Ambil info primary role
        $stmtUser = $pdo->prepare("SELECT role, name, username FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $uData = $stmtUser->fetch(PDO::FETCH_ASSOC);
        $primaryRole = $uData['role'] ?? 'staff';
        $_SESSION['role'] = $primaryRole;

        // Ambil seluruh role keys dan role names dari user_roles (Multi-Role)
        $stmtRoles = $pdo->prepare("
            SELECT r.id, r.role_key, r.role_name, ur.is_primary
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
            ORDER BY ur.is_primary DESC, r.id ASC
        ");
        $stmtRoles->execute([$userId]);
        $assignedRoles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

        $roleKeys = [];
        $roleNames = [];
        foreach ($assignedRoles as $ar) {
            $roleKeys[] = $ar['role_key'];
            $roleNames[] = $ar['role_name'];
        }
        if (!in_array($primaryRole, $roleKeys, true)) {
            $roleKeys[] = $primaryRole;
        }

        $_SESSION['roles'] = array_values(array_unique($roleKeys));
        $_SESSION['role_names'] = array_values(array_unique($roleNames));

        // Jika user adalah super_admin, berikan full akses ke seluruh menu dengan Full CRUD
        if ($primaryRole === 'super_admin' || in_array('super_admin', $roleKeys, true)) {
            $stmtAll = $pdo->query("SELECT menu_key FROM app_menus WHERE is_active = 1 ORDER BY sort_order ASC");
            $allKeys = $stmtAll->fetchAll(PDO::FETCH_COLUMN);
            $_SESSION['menu_keys'] = $allKeys;
            $_SESSION['menu_permissions'] = [];
            foreach ($allKeys as $mk) {
                $_SESSION['menu_permissions'][$mk] = ['read' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1];
            }
            return;
        }

        // Hitung Additive Union Permissions dari:
        // 1. Seluruh role_menu_access untuk semua role yang dimiliki user
        // 2. user_menu_access custom overrides
        $sql = "
            SELECT 
                am.menu_key,
                GREATEST(COALESCE(MAX(uma.can_read), 0), COALESCE(MAX(rma.can_read), 0)) as can_read,
                GREATEST(COALESCE(MAX(uma.can_create), 0), COALESCE(MAX(rma.can_create), 0)) as can_create,
                GREATEST(COALESCE(MAX(uma.can_edit), 0), COALESCE(MAX(rma.can_edit), 0)) as can_edit,
                GREATEST(COALESCE(MAX(uma.can_delete), 0), COALESCE(MAX(rma.can_delete), 0)) as can_delete
            FROM app_menus am
            LEFT JOIN user_menu_access uma 
                ON uma.menu_id = am.id AND uma.user_id = ?
            LEFT JOIN user_roles ur 
                ON ur.user_id = ?
            LEFT JOIN roles r_ur 
                ON r_ur.id = ur.role_id
            LEFT JOIN users u 
                ON u.id = ?
            LEFT JOIN roles r_primary 
                ON r_primary.role_key = u.role
            LEFT JOIN role_menu_access rma 
                ON rma.menu_id = am.id AND (rma.role_id = ur.role_id OR rma.role_id = r_primary.id)
            WHERE am.is_active = 1
            GROUP BY am.id, am.menu_key, am.sort_order
            HAVING can_read > 0 OR can_create > 0 OR can_edit > 0 OR can_delete > 0
            ORDER BY am.sort_order ASC
        ";
        $stmtPerms = $pdo->prepare($sql);
        $stmtPerms->execute([$userId, $userId, $userId]);
        $rows = $stmtPerms->fetchAll(PDO::FETCH_ASSOC);

        $allowedMenuKeys = [];
        $menuPermissions = [];
        foreach ($rows as $row) {
            $mKey = $row['menu_key'];
            if ((int)$row['can_read'] > 0) {
                $allowedMenuKeys[] = $mKey;
            }
            $menuPermissions[$mKey] = [
                'read'   => (int)$row['can_read'],
                'create' => (int)$row['can_create'],
                'edit'   => (int)$row['can_edit'],
                'delete' => (int)$row['can_delete'],
            ];
        }

        $_SESSION['menu_keys'] = array_values(array_unique($allowedMenuKeys));
        $_SESSION['menu_permissions'] = $menuPermissions;
    }
}

/**
 * Ambil semua menu_key yang boleh diakses oleh $userId dari DB.
 */
if (!function_exists('getUserMenuKeys')) {
    function getUserMenuKeys(PDO $pdo, int $userId): array
    {
        loadUserPermissions($pdo, $userId);
        return $_SESSION['menu_keys'] ?? [];
    }
}

/**
 * Refresh session permissions user tertentu.
 */
if (!function_exists('refreshUserPermissions')) {
    function refreshUserPermissions(PDO $pdo, int $targetUserId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $currentUserId = (int)($_SESSION['user_id'] ?? 0);

        if ($targetUserId === $currentUserId) {
            loadUserPermissions($pdo, $currentUserId);
        }
    }
}

/**
 * FUNGSI UTAMA PENJAGA HALAMAN.
 *
 * @param string $menuKey  Nilai menu_key dari tabel app_menus.
 * @param string|null $redirect URL tujuan jika akses ditolak (default: dashboard).
 */
if (!function_exists('checkUserAccess')) {
    function checkUserAccess(string $menuKey, ?string $redirect = null): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Pastikan user sudah login
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }

        $role = $_SESSION['role'] ?? '';

        // Super Admin selalu lolos
        if ($role === 'super_admin') {
            return;
        }

        // Halaman Dashboard selalu diizinkan untuk semua user yang sudah login
        if ($menuKey === 'dashboard') {
            return;
        }

        // Jika belum ada di session, load dari DB
        if (!isset($_SESSION['menu_keys'])) {
            global $pdo;
            if (isset($pdo)) {
                loadUserPermissions($pdo, (int)$_SESSION['user_id']);
            }
        }

        $allowedKeys = $_SESSION['menu_keys'] ?? [];

        if (!in_array($menuKey, $allowedKeys)) {
            flash('error', 'Anda tidak memiliki hak akses untuk halaman tersebut.');
            $target = $redirect ?? BASE_URL . '/dashboard.php';
            header('Location: ' . $target);
            exit;
        }
    }
}

/**
 * Cek apakah user yang sedang login punya akses ke menu tertentu (tanpa redirect).
 */
if (!function_exists('hasMenuAccess')) {
    function hasMenuAccess(string $menuKey): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $role = $_SESSION['role'] ?? '';
        if ($role === 'super_admin') {
            return true;
        }

        if ($menuKey === 'dashboard') {
            return true;
        }

        if (!isset($_SESSION['menu_keys'])) {
            global $pdo;
            if (isset($pdo) && !empty($_SESSION['user_id'])) {
                loadUserPermissions($pdo, (int)$_SESSION['user_id']);
            }
        }

        $allowedKeys = $_SESSION['menu_keys'] ?? [];
        return in_array($menuKey, $allowedKeys);
    }
}

// =========================================================================
// ACCESS CONTROL SISWA (Filter berdasarkan izin unit/grade/kelas)
// =========================================================================

if (!function_exists('getMentoringAccessCondition')) {
    // Scoping unit untuk halaman Mentoring (MIP): super_admin & admin = semua unit;
    // lainnya dibatasi unit staff / admin_unit_permissions / grade & class permissions.
    function getMentoringAccessCondition($gradeAlias = 'g', $classAlias = 'cg') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (in_array(($_SESSION['role'] ?? ''), ['super_admin', 'admin'], true)) {
            return ['condition' => '1 = 1', 'params' => []];
        }
        return getStudentAccessCondition($gradeAlias, $classAlias);
    }
}

if (!function_exists('getMentoringGradeCondition')) {
    // Variant hanya-untuk-tabel-grades (tanpa alias class_groups).
    function getMentoringGradeCondition($gradeAlias = 'g') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (in_array(($_SESSION['role'] ?? ''), ['super_admin', 'admin'], true)) {
            return ['condition' => '1 = 1', 'params' => []];
        }
        $uid = currentUserId();
        $condition = "({$gradeAlias}.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?)
            OR {$gradeAlias}.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?)
            OR {$gradeAlias}.unit_id IN (SELECT unit_id FROM staff WHERE user_id = ? AND unit_id IS NOT NULL)
            OR EXISTS (SELECT 1 FROM class_groups cgx WHERE cgx.grade_id = {$gradeAlias}.id
                AND cgx.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?)))";
        return ['condition' => $condition, 'params' => [$uid, $uid, $uid, $uid]];
    }
}

if (!function_exists('getStudentAccessCondition')) {
    function getStudentAccessCondition($gradeAlias = 'g', $classAlias = 'cg') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (($_SESSION['role'] ?? '') === 'super_admin') {
            return [
                'condition' => '1 = 1',
                'params'    => []
            ];
        }

        $uid = currentUserId();
        return [
            'condition' => "(
                {$gradeAlias}.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?)
                OR {$gradeAlias}.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?)
                OR {$classAlias}.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?)
                OR {$gradeAlias}.unit_id IN (SELECT unit_id FROM staff WHERE user_id = ? AND unit_id IS NOT NULL)
            )",
            'params' => [
                $uid,
                $uid,
                $uid,
                $uid
            ]
        ];
    }
}

/**
 * Cek apakah pengguna berwenang melakukan aksi absensi manual / aksi hadir cepat siswa.
 * - Super Admin: Selalu berhak
 * - Kepala Sekolah: Otomatis berhak sesuai unitnya
 * - Guru / Staff: Hanya jika diberikan izin khusus oleh Super Admin
 */
if (!function_exists('canExecuteQuickAttendance')) {
    function canExecuteQuickAttendance(PDO $pdo, int $userId, string $role): bool {
        // Super Admin, Kepala Sekolah, dan Admin Pemantau otomatis berhak sesuai lingkup aksesnya
        if ($role === 'super_admin' || $role === 'kepala_sekolah' || $role === 'admin') {
            return true;
        }
        // Guru / Staff / role lain: periksa izin khusus yang diberikan Super Admin
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM officer_attendance_permissions WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }
}

// =========================================================================
// SUPERVISI PEMBELAJARAN (KURMAA) — SCOPING UNIT & ELIGIBLE USERS/STAFF
// =========================================================================

if (!function_exists('getUserUnitInfo')) {
    function getUserUnitInfo(PDO $pdo, ?int $userId = null): array {
        $uid = $userId !== null ? $userId : (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return ['unit_id' => null, 'unit_name' => null];

        $sql = '
            SELECT u.id as unit_id, u.unit as unit_name
            FROM admin_unit_permissions aup
            JOIN units u ON u.id = aup.unit_id
            WHERE aup.user_id = ? AND aup.unit_id IS NOT NULL
            UNION
            SELECT u.id as unit_id, u.unit as unit_name
            FROM staff s
            JOIN units u ON u.id = s.unit_id
            WHERE s.user_id = ? AND s.unit_id IS NOT NULL
            UNION
            SELECT un.id as unit_id, un.unit as unit_name
            FROM users usr
            JOIN staff st ON st.nik = usr.username
            JOIN units un ON un.id = st.unit_id
            WHERE usr.id = ? AND st.unit_id IS NOT NULL
            LIMIT 1
        ';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$uid, $uid, $uid]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                return ['unit_id' => (int)$res['unit_id'], 'unit_name' => $res['unit_name']];
            }
        } catch (Exception $e) {}
        return ['unit_id' => null, 'unit_name' => null];
    }
}

if (!function_exists('getSupervisionTeachers')) {
    function getSupervisionTeachers(PDO $pdo, ?int $unitId = null, ?int $excludeUserId = null): array {
        $where = ['s.deleted_at IS NULL', "s.name NOT IN ('absen', 'arif')", "s.nik NOT IN ('000', '0000')"];
        $params = [];

        if ($unitId !== null && $unitId > 0) {
            $where[] = 's.unit_id = ?';
            $params[] = $unitId;
        } else {
            // Hanya unit akademik (TK, SD, SMP, SMA)
            $where[] = 's.unit_id IN (1, 2, 3, 7)';
        }

        if ($excludeUserId !== null && $excludeUserId > 0) {
            $where[] = '(s.user_id != ? OR s.user_id IS NULL)';
            $params[] = $excludeUserId;
            $where[] = '(s.nik != (SELECT username FROM users WHERE id = ?) OR s.nik IS NULL)';
            $params[] = $excludeUserId;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT s.id, s.name, s.nik, s.unit_id, u.unit as unit_name
            FROM staff s
            LEFT JOIN units u ON u.id = s.unit_id
            WHERE {$whereSql}
            ORDER BY u.id ASC, s.name ASC
        ";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('getSupervisionObservers')) {
    function getSupervisionObservers(PDO $pdo, ?int $unitId = null): array {
        $where = [
            'u.deleted_at IS NULL',
            "u.role != 'siswa'",
            'u.student_id IS NULL',
            "u.name NOT IN ('absen', 'arif', 'Absen Barcode Siswa')",
            "u.username NOT IN ('000', '0000')"
        ];
        $params = [];

        if ($unitId !== null && $unitId > 0) {
            $where[] = '(
                u.role IN ("super_admin", "admin")
                OR s.unit_id = ?
                OR u.id IN (SELECT user_id FROM user_roles WHERE role_id IN (SELECT id FROM roles WHERE role_key = "observer"))
            )';
            $params[] = $unitId;
        } else {
            $where[] = '(
                u.role IN ("super_admin", "admin", "kepala_sekolah")
                OR s.unit_id IN (1, 2, 3, 7)
                OR u.id IN (SELECT user_id FROM user_roles WHERE role_id IN (SELECT id FROM roles WHERE role_key = "observer"))
            )';
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT DISTINCT u.id, u.name, u.username, u.role, un.unit as unit_name
            FROM users u
            LEFT JOIN staff s ON (s.user_id = u.id OR s.nik = u.username)
            LEFT JOIN units un ON un.id = s.unit_id
            WHERE {$whereSql}
            ORDER BY un.id ASC, u.name ASC
        ";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('formatIndoDate')) {
    function formatIndoDate(?string $dateStr): string {
        if (empty($dateStr)) return '-';
        $timestamp = strtotime($dateStr);
        if (!$timestamp) return $dateStr;
        $bulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];
        return date('j', $timestamp) . ' ' . $bulan[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    }
}

if (!function_exists('getSupervisionCumulativeData')) {
    function getSupervisionCumulativeData(PDO $pdo, int $teacherId): ?array {
        // 1. Teacher Info
        $stmtT = $pdo->prepare("
            SELECT s.id, s.name, s.nik, s.unit_id, u.unit as unit_name
            FROM staff s
            LEFT JOIN units u ON u.id = s.unit_id
            WHERE s.id = ?
        ");
        $stmtT->execute([$teacherId]);
        $teacher = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$teacher) return null;

        // 2. Latest Peer Supervision (type_id = 1)
        $stmtPeer = $pdo->prepare("
            SELECT ss.*, u.name as observer_name
            FROM supervision_sessions ss
            JOIN users u ON u.id = ss.observer_id
            WHERE ss.teacher_id = ? AND ss.type_id = 1 AND ss.status = 'completed'
            ORDER BY ss.observation_date DESC, ss.id DESC LIMIT 1
        ");
        $stmtPeer->execute([$teacherId]);
        $peer = $stmtPeer->fetch(PDO::FETCH_ASSOC);

        // 3. Latest Principal Supervision (type_id = 2)
        $stmtKepsek = $pdo->prepare("
            SELECT ss.*, u.name as observer_name
            FROM supervision_sessions ss
            JOIN users u ON u.id = ss.observer_id
            WHERE ss.teacher_id = ? AND ss.type_id = 2 AND ss.status = 'completed'
            ORDER BY ss.observation_date DESC, ss.id DESC LIMIT 1
        ");
        $stmtKepsek->execute([$teacherId]);
        $kepsek = $stmtKepsek->fetch(PDO::FETCH_ASSOC);

        // 4. Calculate 7 Components
        $categories = $pdo->query("SELECT * FROM supervision_categories ORDER BY type_id ASC, sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
        $components = [];
        foreach ($categories as $cat) {
            $cId = (int)$cat['id'];
            $tId = (int)$cat['type_id'];
            $sessId = ($tId === 1) ? ($peer['id'] ?? 0) : ($kepsek['id'] ?? 0);
            
            $userScore = 0.0;
            $maxScore = ($tId === 1) ? ($cId == 1 ? 40.0 : ($cId == 2 ? 32.0 : 16.0)) : ($cId == 4 ? 20.0 : ($cId == 5 ? 40.0 : ($cId == 6 ? 20.0 : 16.0)));
            
            if ($sessId > 0) {
                $stmt = $pdo->prepare("
                    SELECT SUM(ssc.score * sri.weight) as u_score,
                           SUM(sri.max_score * sri.weight) as m_score
                    FROM supervision_scores ssc
                    JOIN supervision_rubric_items sri ON sri.id = ssc.item_id
                    WHERE ssc.session_id = ? AND sri.category_id = ?
                ");
                $stmt->execute([$sessId, $cId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['u_score'] !== null) {
                    $userScore = (float)$row['u_score'];
                    $maxScore = (float)$row['m_score'];
                }
            }
            
            $pct = ($maxScore > 0) ? round(($userScore / $maxScore) * 100, 1) : 0.0;
            
            $cleanName = preg_replace('/^[0-9]+\.\s*/', '', $cat['category_name']);
            if ($tId == 2 && strpos($cleanName, 'Perencanaan') !== false) $cleanName = 'Perencanaan Ajar (Kepsek)';
            if ($tId == 2 && strpos($cleanName, 'Pelaksanaan') !== false) $cleanName = 'Pelaksanaan Kelas (Kepsek)';
            if ($tId == 2 && strpos($cleanName, 'Asesmen') !== false) $cleanName = 'Asesmen Hasil Belajar';
            if ($tId == 2 && strpos($cleanName, 'Komitmen') !== false) $cleanName = 'Pengembangan Diri Guru';
            if ($tId == 1 && strpos($cleanName, 'Keterlibatan') !== false) $cleanName = 'Keterlibatan Siswa';
            
            $color = '#2563EB';
            if ($cleanName === 'Keterlibatan Siswa') $color = '#4F46E5';
            if ($cleanName === 'Perencanaan Ajar (Kepsek)') $color = '#9333EA';
            if ($cleanName === 'Pelaksanaan Kelas (Kepsek)') $color = '#0D9488';
            if ($cleanName === 'Asesmen Hasil Belajar') $color = '#EA580C';
            if ($cleanName === 'Pengembangan Diri Guru') $color = '#E11D48';

            $components[] = [
                'id' => $cId,
                'type_id' => $tId,
                'name' => $cleanName,
                'score' => $userScore,
                'max_score' => $maxScore,
                'percentage' => $pct,
                'color' => $color
            ];
        }

        $peerScore = $peer ? (float)$peer['total_score'] : null;
        $peerMax = $peer ? (float)$peer['max_possible_score'] : 88.0;
        $peerPct = $peer ? (float)$peer['percentage_score'] : null;
        $peerPred = $peer ? $peer['predicate_name'] : 'Belum Ada';

        $kepsekScore = $kepsek ? (float)$kepsek['total_score'] : null;
        $kepsekMax = $kepsek ? (float)$kepsek['max_possible_score'] : 96.0;
        $kepsekPct = $kepsek ? (float)$kepsek['percentage_score'] : null;
        $kepsekPred = $kepsek ? $kepsek['predicate_name'] : 'Belum Ada';

        $totalScore = ($peerScore ?? 0.0) + ($kepsekScore ?? 0.0);
        $totalMax = ($peer ? $peerMax : 88.0) + ($kepsek ? $kepsekMax : 96.0);
        
        if ($peer && $kepsek) {
            $avgPct = round(($peerPct + $kepsekPct) / 2, 1);
        } elseif ($peer) {
            $avgPct = round($peerPct, 1);
        } elseif ($kepsek) {
            $avgPct = round($kepsekPct, 1);
        } else {
            $avgPct = 0.0;
        }

        $stmtP = $pdo->prepare('SELECT predicate_name FROM supervision_predicates WHERE min_percentage <= ? ORDER BY min_percentage DESC LIMIT 1');
        $stmtP->execute([$avgPct]);
        $avgPred = $stmtP->fetchColumn() ?: 'Need Improvement';

        $subjectName = $peer['subject_name'] ?? ($kepsek['subject_name'] ?? '-');
        $className = $peer['class_name'] ?? ($kepsek['class_name'] ?? '-');
        $obsDate = $peer['observation_date'] ?? ($kepsek['observation_date'] ?? date('Y-m-d'));

        $notesGood = [];
        if (!empty($peer['notes_good_practice'])) $notesGood[] = '[Sejawat] ' . trim($peer['notes_good_practice']);
        if (!empty($kepsek['notes_good_practice'])) $notesGood[] = '[Kepsek] ' . trim($kepsek['notes_good_practice']);

        $notesGrowth = [];
        if (!empty($peer['notes_growth_plan'])) $notesGrowth[] = '[Sejawat] ' . trim($peer['notes_growth_plan']);
        if (!empty($kepsek['notes_growth_plan'])) $notesGrowth[] = '[Kepsek] ' . trim($kepsek['notes_growth_plan']);

        $notesRecom = [];
        if (!empty($peer['notes_teacher_reflection'])) $notesRecom[] = '[Rekomendasi Sejawat] ' . trim($peer['notes_teacher_reflection']);
        if (!empty($kepsek['notes_teacher_reflection'])) $notesRecom[] = '[Rekomendasi Kepsek] ' . trim($kepsek['notes_teacher_reflection']);

        return [
            'teacher' => $teacher,
            'peer' => $peer ? [
                'id' => $peer['id'],
                'score' => $peerScore,
                'max_score' => $peerMax,
                'percentage' => $peerPct,
                'predicate' => $peerPred,
                'observer_name' => $peer['observer_name'] ?? '-',
                'date' => $peer['observation_date']
            ] : null,
            'kepsek' => $kepsek ? [
                'id' => $kepsek['id'],
                'score' => $kepsekScore,
                'max_score' => $kepsekMax,
                'percentage' => $kepsekPct,
                'predicate' => $kepsekPred,
                'observer_name' => $kepsek['observer_name'] ?? 'Awang Darmawan, SS',
                'date' => $kepsek['observation_date']
            ] : null,
            'cumulative' => [
                'total_score' => $totalScore,
                'max_score' => $totalMax,
                'avg_percentage' => $avgPct,
                'predicate' => $avgPred,
                'subject_name' => $subjectName,
                'class_name' => $className,
                'observation_date' => $obsDate,
                'academic_year' => '2026/2027 • Semester Ganjil',
                'principal_name' => $kepsek['signed_principal_name'] ?? ($kepsek['observer_name'] ?? 'Awang Darmawan, SS'),
                'peer_observer_name' => $peer['signed_observer_name'] ?? ($peer['observer_name'] ?? '-'),
                'teacher_name' => $teacher['name']
            ],
            'components' => $components,
            'notes' => [
                'good' => $notesGood,
                'growth' => $notesGrowth,
                'recommendations' => $notesRecom
            ]
        ];
    }
}

// =========================================================================
// UPLOAD FOTO
// =========================================================================

if (!function_exists('uploadStudentPhoto')) {
    function uploadStudentPhoto($file) {
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            return null;
        }

        $allowed = [
            'image/jpeg'  => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/jpg'   => 'jpg',
            'image/png'   => 'png',
            'image/x-png' => 'png',
            'image/webp'  => 'webp'
        ];

        $imgInfo = @getimagesize($file['tmp_name']);
        if (!$imgInfo) {
            return null;
        }
        $mime = $imgInfo['mime'] ?? @mime_content_type($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            return null;
        }

        $extension = $allowed[$mime];
        $filename  = uniqid('student_', true) . '.' . $extension;

        $targetDir = defined('UPLOAD_STUDENT') ? UPLOAD_STUDENT : (dirname(__DIR__) . '/uploads/students/');
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }
        @chmod($targetDir, 0777);

        $targetPath = $targetDir . $filename;
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            @chmod($targetPath, 0644);
            return $filename;
        }

        return null;
    }
}

// =========================================================================
// PERIODE LAPORAN
// =========================================================================

if (!function_exists('getReportPeriod')) {
    function getReportPeriod() {
        $preset = $_GET['preset'] ?? 'month';
        $today  = new DateTime();

        if ($preset === 'today') {
            return [
                'start' => $today->format('Y-m-d'),
                'end'   => $today->format('Y-m-d')
            ];
        }

        if ($preset === 'week') {
            $monday = new DateTime('monday this week');
            $sunday = new DateTime('sunday this week');
            return [
                'start' => $monday->format('Y-m-d'),
                'end'   => $sunday->format('Y-m-d')
            ];
        }

        if ($preset === 'month') {
            return [
                'start' => $today->format('Y-m-01'),
                'end'   => $today->format('Y-m-t')
            ];
        }

        $start = $_GET['start_date'] ?? $today->format('Y-m-01');
        $end   = $_GET['end_date']   ?? $today->format('Y-m-d');

        return [
            'start' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : $today->format('Y-m-01'),
            'end'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)   ? $end   : $today->format('Y-m-d')
        ];
    }
}

// =========================================================================
// URL QUERY STRING
// =========================================================================

if (!function_exists('buildQuery')) {
    function buildQuery(array $params = []) {
        return http_build_query(
            array_merge($_GET, $params)
        );
    }
}

// =========================================================================
// USER ACTIVITY & LOGIN AUDIT LOGGING
// =========================================================================

if (!function_exists('getClientIp')) {
    function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ips = explode(',', $_SERVER[$h]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

if (!function_exists('parseUserAgent')) {
    function parseUserAgent(?string $ua = null): array
    {
        $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $device = 'Desktop';
        $platform = 'Unknown OS';
        $browser = 'Unknown Browser';

        // Deteksi Device
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile|mobile)/i', $ua)) {
            $device = 'Mobile';
        }

        // Deteksi Platform / OS
        if (preg_match('/windows nt 10/i', $ua)) {
            $platform = 'Windows 10/11';
        } elseif (preg_match('/windows nt 6.3/i', $ua)) {
            $platform = 'Windows 8.1';
        } elseif (preg_match('/windows nt 6.2/i', $ua)) {
            $platform = 'Windows 8';
        } elseif (preg_match('/windows nt 6.1/i', $ua)) {
            $platform = 'Windows 7';
        } elseif (preg_match('/windows/i', $ua)) {
            $platform = 'Windows';
        } elseif (preg_match('/iphone/i', $ua)) {
            $platform = 'iOS (iPhone)';
        } elseif (preg_match('/ipad/i', $ua)) {
            $platform = 'iOS (iPad)';
        } elseif (preg_match('/android/i', $ua)) {
            $platform = 'Android';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $platform = 'macOS';
        } elseif (preg_match('/linux/i', $ua)) {
            $platform = 'Linux';
        }

        // Deteksi Browser
        if (preg_match('/edg/i', $ua)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/chrome/i', $ua) && !preg_match('/opr|opera/i', $ua)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/firefox/i', $ua)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) {
            $browser = 'Apple Safari';
        } elseif (preg_match('/opr|opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/trident|msie/i', $ua)) {
            $browser = 'Internet Explorer';
        }

        return [
            'device'   => $device,
            'platform' => $platform,
            'browser'  => $browser
        ];
    }
}

if (!function_exists('recordUserLogin')) {
    function recordUserLogin(PDO $pdo, int $userId, string $username, string $role): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $ip = getClientIp();
        $uaString = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uaParsed = parseUserAgent($uaString);

        $isSuperadmin = ($role === 'super_admin') ? 1 : 0;

        try {
            // Update table users
            $stmtUser = $pdo->prepare("
                UPDATE users 
                SET last_login_at = NOW(), 
                    last_login_ip = ?, 
                    last_activity = NOW(), 
                    is_online = 1 
                WHERE id = ?
            ");
            $stmtUser->execute([$ip, $userId]);

            // Insert ke user_login_logs
            $stmtLog = $pdo->prepare("
                INSERT INTO user_login_logs (
                    user_id, username, role, is_superadmin, 
                    ip_address, user_agent, device_type, browser, platform, 
                    login_at, last_seen_at, status
                ) VALUES (
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, 
                    NOW(), NOW(), 'online'
                )
            ");
            $stmtLog->execute([
                $userId,
                $username,
                $role,
                $isSuperadmin,
                $ip,
                substr($uaString, 0, 500),
                $uaParsed['device'],
                $uaParsed['browser'],
                $uaParsed['platform']
            ]);

            $logId = (int)$pdo->lastInsertId();
            $_SESSION['login_log_id'] = $logId;
            $_SESSION['last_activity_update'] = time();

            return $logId;
        } catch (Exception $e) {
            error_log('Error recordUserLogin: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('recordUserActivity')) {
    function recordUserActivity(PDO $pdo, int $userId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $now = time();
        // Throttle pembaruan ke DB: maksimal 1x per 30 detik agar performa tetap kencang
        if (isset($_SESSION['last_activity_update']) && ($now - $_SESSION['last_activity_update']) < 30) {
            return;
        }
        $_SESSION['last_activity_update'] = $now;

        try {
            $stmt = $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1 WHERE id = ?");
            $stmt->execute([$userId]);

            if (!empty($_SESSION['login_log_id'])) {
                $stmtLog = $pdo->prepare("UPDATE user_login_logs SET last_seen_at = NOW(), status = 'online' WHERE id = ?");
                $stmtLog->execute([(int)$_SESSION['login_log_id']]);
            }
        } catch (Exception $e) {
            // Ignore background error
        }
    }
}

if (!function_exists('recordUserLogout')) {
    function recordUserLogout(PDO $pdo, int $userId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $stmt = $pdo->prepare("UPDATE users SET is_online = 0, last_activity = NOW() WHERE id = ?");
            $stmt->execute([$userId]);

            if (!empty($_SESSION['login_log_id'])) {
                $stmtLog = $pdo->prepare("UPDATE user_login_logs SET logout_at = NOW(), status = 'logged_out' WHERE id = ?");
                $stmtLog->execute([(int)$_SESSION['login_log_id']]);
            } else {
                $stmtLog = $pdo->prepare("
                    UPDATE user_login_logs 
                    SET logout_at = NOW(), status = 'logged_out' 
                    WHERE user_id = ? AND status = 'online' 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtLog->execute([$userId]);
            }
        } catch (Exception $e) {
            // Ignore error
        }
    }
}

if (!function_exists('formatTimeAgo')) {
    function formatTimeAgo(?string $datetime): string
    {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return 'Belum pernah';
        }

        $time = strtotime($datetime);
        if (!$time) return 'Tidak valid';

        $diff = time() - $time;
        if ($diff < 0) return 'Baru saja';
        if ($diff < 60) return $diff . ' detik yang lalu';
        if ($diff < 3600) return floor($diff / 60) . ' menit yang lalu';
        if ($diff < 86400) return floor($diff / 3600) . ' jam yang lalu';
        if ($diff < 172800) return 'Kemarin ' . date('H:i', $time);
        if ($diff < 604800) return floor($diff / 86400) . ' hari yang lalu';

        return date('d/m/Y H:i', $time);
    }
}

// =========================================================================
// SISTEM LOG AKTIVITAS & AUDIT TRAIL DATA DENGAN FITUR ROLLBACK
// =========================================================================

if (!function_exists('recordActivityAudit')) {
    function recordActivityAudit(
        PDO $pdo, 
        string $module, 
        string $action, 
        string $tableName, 
        $recordId, 
        ?string $description = '', 
        ?array $oldData = null, 
        ?array $newData = null, 
        ?int $unitId = null
    ): ?int {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }

        if (empty($description)) {
            $description = "$action pada $tableName #" . ($recordId ?: '-');
        }

        $userId = function_exists('currentUserId') ? (currentUserId() ?: null) : ($_SESSION['user_id'] ?? null);
        $username = function_exists('currentUserName') ? (currentUserName() ?: 'System') : ($_SESSION['username'] ?? 'System');
        $role = function_exists('currentRole') ? (currentRole() ?: 'system') : ($_SESSION['role'] ?? 'system');
        $ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // Jika unitId tidak diberikan, coba cari dari oldData atau newData
        if ($unitId === null) {
            $unitId = $oldData['unit_id'] ?? $newData['unit_id'] ?? null;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_audit_logs 
                (user_id, username, role, unit_id, module, action, table_name, record_id, description, old_data, new_data, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $username,
                $role,
                $unitId ? (int)$unitId : null,
                $module,
                $action,
                $tableName,
                $recordId ? (int)$recordId : null,
                $description,
                $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null,
                $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null,
                $ip
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            error_log('Audit Log Error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('rollbackActivityAudit')) {
    function rollbackActivityAudit(PDO $pdo, int $auditLogId, string $operator = 'Super Admin'): array
    {
        $stmt = $pdo->prepare("SELECT * FROM activity_audit_logs WHERE id = ?");
        $stmt->execute([$auditLogId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            return ['success' => false, 'message' => 'Log aktivitas tidak ditemukan.'];
        }

        if ((int)$log['is_rolled_back'] === 1) {
            return ['success' => false, 'message' => 'Aktivitas ini sudah pernah di-rollback sebelumnya pada ' . $log['rolled_back_at']];
        }

        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $log['table_name']);
        $action = $log['action'];
        $oldData = !empty($log['old_data']) ? json_decode($log['old_data'], true) : null;
        $newData = !empty($log['new_data']) ? json_decode($log['new_data'], true) : null;
        $recordId = (int)$log['record_id'];

        $pdo->beginTransaction();
        try {
            if ($action === 'DELETE') {
                if (!$oldData || !is_array($oldData)) {
                    throw new Exception('Data cadangan (snapshot lama) tidak tersedia untuk rollback penghapusan ini.');
                }

                // Cek apakah tabel memiliki deleted_at
                $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'deleted_at'");
                $hasDeletedAt = (bool)$colsStmt->fetch();

                if ($hasDeletedAt && !empty($oldData['id'])) {
                    // Coba cek apakah row masih ada tapi deleted_at terisi
                    $checkRow = $pdo->prepare("SELECT id FROM `{$table}` WHERE id = ?");
                    $checkRow->execute([$oldData['id']]);
                    if ($checkRow->fetch()) {
                        $pdo->prepare("UPDATE `{$table}` SET deleted_at = NULL WHERE id = ?")->execute([$oldData['id']]);
                    } else {
                        // Re-insert row
                        $oldData['deleted_at'] = null;
                        $fields = array_keys($oldData);
                        $placeholders = implode(',', array_fill(0, count($fields), '?'));
                        $colNames = implode('`,`', $fields);
                        $stmtIns = $pdo->prepare("INSERT INTO `{$table}` (`{$colNames}`) VALUES ({$placeholders})");
                        $stmtIns->execute(array_values($oldData));
                    }
                } else {
                    // Re-insert row
                    $fields = array_keys($oldData);
                    $placeholders = implode(',', array_fill(0, count($fields), '?'));
                    $colNames = implode('`,`', $fields);
                    $stmtIns = $pdo->prepare("INSERT INTO `{$table}` (`{$colNames}`) VALUES ({$placeholders})");
                    $stmtIns->execute(array_values($oldData));
                }
            } elseif ($action === 'BULK_DELETE') {
                if (!$oldData || !is_array($oldData)) {
                    throw new Exception('Data snapshot massal tidak ditemukan.');
                }
                foreach ($oldData as $item) {
                    if (is_array($item)) {
                        $fields = array_keys($item);
                        $placeholders = implode(',', array_fill(0, count($fields), '?'));
                        $colNames = implode('`,`', $fields);
                        $stmtIns = $pdo->prepare("INSERT IGNORE INTO `{$table}` (`{$colNames}`) VALUES ({$placeholders})");
                        $stmtIns->execute(array_values($item));
                    }
                }
            } elseif ($action === 'UPDATE') {
                if (!$oldData || !is_array($oldData)) {
                    throw new Exception('Data snapshot sebelum perubahan tidak tersedia.');
                }
                $setClauses = [];
                $params = [];
                foreach ($oldData as $col => $val) {
                    if ($col === 'id') continue;
                    $setClauses[] = "`{$col}` = ?";
                    $params[] = $val;
                }
                $params[] = $recordId ?: $oldData['id'];
                $sqlUp = "UPDATE `{$table}` SET " . implode(', ', $setClauses) . " WHERE id = ?";
                $pdo->prepare($sqlUp)->execute($params);
            } elseif ($action === 'CREATE') {
                // Rollback CREATE = hapus data yang baru dibuat
                if ($recordId > 0) {
                    $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'deleted_at'");
                    if ($colsStmt->fetch()) {
                        $pdo->prepare("UPDATE `{$table}` SET deleted_at = NOW() WHERE id = ?")->execute([$recordId]);
                    } else {
                        $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?")->execute([$recordId]);
                    }
                }
            }

            // Tandai log ini sudah di-rollback
            $stmtMark = $pdo->prepare("
                UPDATE activity_audit_logs 
                SET is_rolled_back = 1, rolled_back_at = NOW(), rolled_back_by = ? 
                WHERE id = ?
            ");
            $stmtMark->execute([$operator, $auditLogId]);

            // Catat log audit untuk tindakan rollback itu sendiri
            $opUserId = function_exists('currentUserId') ? (currentUserId() ?: null) : ($_SESSION['user_id'] ?? null);
            $opRole = function_exists('currentRole') ? (currentRole() ?: 'super_admin') : ($_SESSION['role'] ?? 'super_admin');
            $opIp = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $stmtLogRoll = $pdo->prepare("
                INSERT INTO activity_audit_logs 
                (user_id, username, role, unit_id, module, action, table_name, record_id, description, ip_address, created_at)
                VALUES (?, ?, ?, ?, 'rollback', 'ROLLBACK', ?, ?, ?, ?, NOW())
            ");
            $stmtLogRoll->execute([
                $opUserId,
                $operator,
                $opRole,
                $log['unit_id'],
                $table,
                $recordId,
                "Memulihkan (Rollback) aktivitas ID #{$auditLogId}: {$log['description']}",
                $opIp
            ]);

            $pdo->commit();
            return ['success' => true, 'message' => "Berhasil me-rollback data untuk: {$log['description']}"];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Gagal melakukan rollback: ' . $e->getMessage()];
        }
    }
}

// =========================================================================
// SISTEM CEK KONTROL ABSEN OTOMATIS (AUTO-ALPHA EXCLUSION)
// =========================================================================

if (!function_exists('isAutoAttendanceDisabled')) {
    function isAutoAttendanceDisabled(
        PDO $pdo, 
        ?int $unitId = null, 
        ?int $gradeId = null, 
        ?int $classGroupId = null, 
        ?int $studentId = null, 
        ?int $staffId = null,
        bool $forceReload = false
    ): bool {
        static $rulesCache = null;
        if ($rulesCache === null || $forceReload) {
            try {
                $stmt = $pdo->query("SELECT scope_type, target_id FROM auto_attendance_exclusions WHERE is_disabled = 1");
                $rulesCache = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $rulesCache = [];
            }
        }

        foreach ($rulesCache as $r) {
            $st = $r['scope_type'];
            $tid = (int)$r['target_id'];

            if ($st === 'global') return true;
            if ($unitId !== null && $st === 'unit' && $tid === (int)$unitId) return true;
            if ($gradeId !== null && $st === 'grade' && $tid === (int)$gradeId) return true;
            if ($classGroupId !== null && $st === 'class_group' && $tid === (int)$classGroupId) return true;
            if ($studentId !== null && $st === 'student' && $tid === (int)$studentId) return true;
            if ($staffId !== null && $st === 'staff' && $tid === (int)$staffId) return true;
        }

        return false;
    }
}

// =========================================================================
// JADWAL EFEKTIF SISWA (REGULER, ESKUL, AKTIVITAS KHUSUS)
// Aturan:
// - Jam Masuk: ambil yang paling awal (min) jika ada beberapa jadwal
// - Jam Pulang: ambil yang paling akhir (max) jika ada beberapa jadwal
// =========================================================================
if (!function_exists('getStudentEffectiveSchedule')) {
    function getStudentEffectiveSchedule(PDO $pdo, int $studentId, int $unitId, string $dateToday, int $dayCode): array {
        // 1. Cek apakah ada Activity Khusus buatan admin (bukan placeholder KBM otomatis)
        // Aturan: Ketika ada activities khusus, jadwal pekanan tidak dianggap.
        // Ketika activities kosong/hanya placeholder, maka jadwal pekanan diutamakan.
        $stmtAct = $pdo->prepare("
            SELECT * FROM activities 
            WHERE activity_date = ? AND unit_id = ? AND status = 'active' AND is_holiday = 'no' 
              AND (target_type = 'student' OR target_type = 'all' OR target_type IS NULL)
              AND (is_auto_generated = 0 AND name NOT IN ('KBM Reguler', 'Jadwal Reguler Siswa', 'Jadwal Reguler Staff & Guru'))
            ORDER BY id DESC LIMIT 1
        ");
        $stmtAct->execute([$dateToday, $unitId]);
        $customActivity = $stmtAct->fetch(PDO::FETCH_ASSOC);

        $schedules = [];

        if ($customActivity && !empty($customActivity['student_in']) && $customActivity['student_in'] !== '00:00:00') {
            // JIKA ADA ACTIVITY KHUSUS: Gunakan Activity, jadwal pekanan tidak dianggap
            $lateTime = (!empty($customActivity['student_late']) && $customActivity['student_late'] !== '00:00:00')
                ? $customActivity['student_late']
                : date('H:i:s', strtotime('+15 minutes', strtotime($customActivity['student_in'])));

            $schedules[] = [
                'source' => 'activity',
                'name' => $customActivity['name'],
                'student_in' => $customActivity['student_in'],
                'student_late' => $lateTime,
                'student_out' => $customActivity['student_out']
            ];
        } else {
            // JIKA ACTIVITIES KOSONG / HANYA PLACEHOLDER: JADWAL PEKANAN DIUTAMAKAN
            $cgId = 0;
            $gId = 0;
            try {
                $stmtEnroll = $pdo->prepare("
                    SELECT se.class_group_id, cg.grade_id 
                    FROM student_enrollments se 
                    INNER JOIN class_groups cg ON cg.id = se.class_group_id
                    WHERE se.student_id = ? AND se.status = 'active' 
                    LIMIT 1
                ");
                $stmtEnroll->execute([$studentId]);
                $enroll = $stmtEnroll->fetch(PDO::FETCH_ASSOC);
                if ($enroll) {
                    $cgId = (int)$enroll['class_group_id'];
                    $gId = (int)$enroll['grade_id'];
                }
            } catch (Exception $e) {}

            $stmtWeek = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? 
                  AND day_code = ? 
                  AND is_active = 'active' 
                  AND (target_type = 'student' OR target_type IS NULL)
                  AND (schedule_type = 'reguler' OR schedule_type IS NULL)
                  AND (
                      class_group_id = ?
                      OR (grade_id = ? AND (class_group_id IS NULL OR class_group_id = 0))
                      OR ((grade_id IS NULL OR grade_id = 0) AND (class_group_id IS NULL OR class_group_id = 0))
                  )
                ORDER BY 
                  CASE 
                    WHEN class_group_id = ? AND class_group_id > 0 THEN 1
                    WHEN grade_id = ? AND grade_id > 0 THEN 2
                    ELSE 3
                  END ASC, 
                  id DESC 
                LIMIT 1
            ");
            $stmtWeek->execute([$unitId, $dayCode, $cgId, $gId, $cgId, $gId]);
            $weekly = $stmtWeek->fetch(PDO::FETCH_ASSOC);

            if (!$weekly) {
                // Fallback unit umum
                $stmtFb = $pdo->prepare("SELECT * FROM weekly_schedules WHERE unit_id = ? AND day_code = ? AND (schedule_type = 'reguler' OR schedule_type IS NULL) AND is_active = 'active' ORDER BY id DESC LIMIT 1");
                $stmtFb->execute([$unitId, $dayCode]);
                $weekly = $stmtFb->fetch(PDO::FETCH_ASSOC);
            }

            if ($weekly && !empty($weekly['student_in']) && $weekly['student_in'] !== '00:00:00') {
                $lateTime = (!empty($weekly['student_late']) && $weekly['student_late'] !== '00:00:00')
                    ? $weekly['student_late']
                    : date('H:i:s', strtotime('+15 minutes', strtotime($weekly['student_in'])));

                $schedules[] = [
                    'source' => 'reguler',
                    'name' => $weekly['name'] ?: 'KBM Reguler',
                    'student_in' => $weekly['student_in'],
                    'student_late' => $lateTime,
                    'student_out' => $weekly['student_out']
                ];
            } else {
                // Fallback terakhir jika weekly belum diatur sama sekali tapi ada data activities
                $stmtFallbackAct = $pdo->prepare("SELECT * FROM activities WHERE activity_date = ? AND unit_id = ? AND status = 'active' AND is_holiday = 'no' ORDER BY id DESC LIMIT 1");
                $stmtFallbackAct->execute([$dateToday, $unitId]);
                $fbAct = $stmtFallbackAct->fetch(PDO::FETCH_ASSOC);
                if ($fbAct && !empty($fbAct['student_in']) && $fbAct['student_in'] !== '00:00:00') {
                    $lateTime = (!empty($fbAct['student_late']) && $fbAct['student_late'] !== '00:00:00')
                        ? $fbAct['student_late']
                        : date('H:i:s', strtotime('+15 minutes', strtotime($fbAct['student_in'])));

                    $schedules[] = [
                        'source' => 'activity_fallback',
                        'name' => $fbAct['name'],
                        'student_in' => $fbAct['student_in'],
                        'student_late' => $lateTime,
                        'student_out' => $fbAct['student_out']
                    ];
                }
            }
        }

        // 2. Ambil jadwal eskul siswa yang aktif pada hari ini
        $stmtEskul = $pdo->prepare("
            SELECT ws.* 
            FROM weekly_schedules ws
            INNER JOIN weekly_schedule_students wss ON wss.weekly_schedule_id = ws.id
            WHERE wss.student_id = ? AND ws.day_code = ? AND ws.schedule_type = 'eskul' AND ws.is_active = 'active'
        ");
        $stmtEskul->execute([$studentId, $dayCode]);
        $eskuls = $stmtEskul->fetchAll(PDO::FETCH_ASSOC);

        $eskulNames = [];
        foreach ($eskuls as $esk) {
            $eskulNames[] = $esk['name'];
            $eskLate = (!empty($esk['student_late']) && $esk['student_late'] !== '00:00:00')
                ? $esk['student_late']
                : date('H:i:s', strtotime('+15 minutes', strtotime($esk['student_in'])));

            $schedules[] = [
                'source' => 'eskul',
                'name' => $esk['name'],
                'student_in' => $esk['student_in'],
                'student_late' => $eskLate,
                'student_out' => $esk['student_out']
            ];
        }

        if (empty($schedules)) {
            return [
                'has_schedule' => false,
                'student_in' => null,
                'student_late' => null,
                'student_out' => null,
                'eskul_names' => []
            ];
        }

        // 3. Tentukan jam masuk paling awal (min) dan jam pulang paling akhir (max)
        $earliestIn = null;
        $lateForEarliest = null;
        $latestOut = null;
        $earliestScheduleName = '';
        $latestScheduleName = '';

        foreach ($schedules as $s) {
            if (!empty($s['student_in']) && $s['student_in'] !== '00:00:00') {
                if ($earliestIn === null || $s['student_in'] < $earliestIn) {
                    $earliestIn = $s['student_in'];
                    $late = (!empty($s['student_late']) && $s['student_late'] !== '00:00:00') 
                        ? $s['student_late'] 
                        : date('H:i:s', strtotime('+15 minutes', strtotime($earliestIn)));
                    $lateForEarliest = $late;
                    $earliestScheduleName = $s['name'];
                }
            }
            if (!empty($s['student_out']) && $s['student_out'] !== '00:00:00') {
                if ($latestOut === null || $s['student_out'] > $latestOut) {
                    $latestOut = $s['student_out'];
                    $latestScheduleName = $s['name'];
                }
            }
        }

        return [
            'has_schedule' => true,
            'student_in' => $earliestIn,
            'student_late' => $lateForEarliest,
            'student_out' => $latestOut,
            'schedule_name' => $earliestScheduleName,
            'earliest_schedule_name' => $earliestScheduleName,
            'latest_schedule_name' => $latestScheduleName,
            'has_eskul' => !empty($eskulNames),
            'eskul_names' => $eskulNames,
            'all_schedules' => $schedules
        ];
    }
}

// =========================================================================
// JADWAL & SHIFT STAFF (TERMASUK SHIFT MALAM SECURITY CROSS-MIDNIGHT & ANTI-TABRAKAN)
// =========================================================================
if (!function_exists('getStaffShiftSchedule')) {
    function getStaffShiftSchedule(PDO $pdo, int $staffId, int $unitId, int $dayCode, ?string $currentTime = null): array {
        if ($currentTime === null) {
            $currentTime = date('H:i:s');
        }
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');
        $currentSeconds = strtotime($currentTime);

        // 1. PRIORITAS 1: Cek apakah staff ini secara spesifik ditugaskan ke shift tertentu
        if ($staffId > 0) {
            $stmtAssigned = $pdo->prepare("
                SELECT ws.* 
                FROM weekly_schedules ws
                INNER JOIN weekly_schedule_staff wss ON wss.weekly_schedule_id = ws.id
                WHERE wss.staff_id = ? AND ws.day_code = ? AND ws.is_active = 'active'
                LIMIT 1
            ");
            $stmtAssigned->execute([$staffId, $dayCode]);
            $assignedSched = $stmtAssigned->fetch(PDO::FETCH_ASSOC);

            if ($assignedSched) {
                $isOvernight = !empty($assignedSched['is_overnight']) || ($assignedSched['staff_in'] > $assignedSched['staff_out']);
                return [
                    'has_schedule' => true,
                    'id' => (int)$assignedSched['id'],
                    'schedule_id' => (int)$assignedSched['id'],
                    'is_overnight' => $isOvernight,
                    'staff_in' => $assignedSched['staff_in'],
                    'staff_late' => $assignedSched['staff_late'],
                    'staff_out' => $assignedSched['staff_out'],
                    'name' => $assignedSched['name'] ?: 'Shift Staff',
                    'assignment_type' => 'specific_staff'
                ];
            }
        }

        // 2. PRIORITAS 2: Deteksi Status Presensi Nyata (Cegah Tabrakan Shift 1 vs Shift 2)
        // A. Cek apakah ada sesi shift malam kemarin yang masih terbuka (belum scan pulang)
        $hasYesterdayOpenNightShift = false;
        if ($staffId > 0 && $currentSeconds >= strtotime('04:00:00') && $currentSeconds <= strtotime('12:00:00')) {
            $stmtYestOpen = $pdo->prepare("
                SELECT id FROM staff_attendances 
                WHERE staff_id = ? AND attendance_date = ? 
                  AND time_in IS NOT NULL AND time_in != '00:00:00'
                  AND (time_out IS NULL OR time_out = '00:00:00')
                LIMIT 1
            ");
            $stmtYestOpen->execute([$staffId, $yesterday]);
            if ($stmtYestOpen->fetchColumn()) {
                $hasYesterdayOpenNightShift = true;
            }
        }

        if ($hasYesterdayOpenNightShift) {
            // Ambil jadwal shift malam untuk unit ini
            $stmtNight = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? AND is_active = 'active' AND (target_type = 'staff' OR target_type IS NULL)
                  AND (is_overnight = 1 OR staff_in > staff_out)
                ORDER BY id ASC LIMIT 1
            ");
            $stmtNight->execute([$unitId]);
            $nightSched = $stmtNight->fetch(PDO::FETCH_ASSOC);
            if ($nightSched) {
                return [
                    'has_schedule' => true,
                    'id' => (int)$nightSched['id'],
                    'schedule_id' => (int)$nightSched['id'],
                    'is_overnight' => true,
                    'staff_in' => $nightSched['staff_in'],
                    'staff_late' => $nightSched['staff_late'],
                    'staff_out' => $nightSched['staff_out'],
                    'name' => $nightSched['name'] ?: 'Shift Malam Security',
                    'assignment_type' => 'open_night_shift'
                ];
            }
        }

        // B. Cek apakah ada sesi shift siang hari ini yang sedang berjalan (sudah scan masuk, belum scan pulang)
        $hasTodayOpenDayShift = false;
        if ($staffId > 0 && $currentSeconds >= strtotime('15:00:00') && $currentSeconds <= strtotime('22:00:00')) {
            $stmtTodayOpen = $pdo->prepare("
                SELECT id FROM staff_attendances 
                WHERE staff_id = ? AND attendance_date = ? 
                  AND time_in IS NOT NULL AND time_in != '00:00:00'
                  AND (time_out IS NULL OR time_out = '00:00:00')
                LIMIT 1
            ");
            $stmtTodayOpen->execute([$staffId, $today]);
            if ($stmtTodayOpen->fetchColumn()) {
                $hasTodayOpenDayShift = true;
            }
        }

        if ($hasTodayOpenDayShift) {
            // Ambil jadwal shift siang (non-overnight) untuk unit ini
            $stmtDay = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? AND day_code = ? AND is_active = 'active' AND (target_type = 'staff' OR target_type IS NULL)
                  AND (is_overnight = 0 AND staff_in <= staff_out)
                ORDER BY id ASC LIMIT 1
            ");
            $stmtDay->execute([$unitId, $dayCode]);
            $daySched = $stmtDay->fetch(PDO::FETCH_ASSOC);
            if ($daySched) {
                return [
                    'has_schedule' => true,
                    'id' => (int)$daySched['id'],
                    'schedule_id' => (int)$daySched['id'],
                    'is_overnight' => false,
                    'staff_in' => $daySched['staff_in'],
                    'staff_late' => $daySched['staff_late'],
                    'staff_out' => $daySched['staff_out'],
                    'name' => $daySched['name'] ?: 'Shift Siang Security',
                    'assignment_type' => 'open_day_shift'
                ];
            }
        }

        // 3. PRIORITAS 3: Berdasarkan Kedekatan Jam Kedatangan (Masuk Shift Terdekat)
        $stmtAll = $pdo->prepare("
            SELECT * FROM weekly_schedules 
            WHERE unit_id = ? AND day_code = ? AND is_active = 'active' AND (target_type = 'staff' OR target_type IS NULL)
            ORDER BY is_overnight ASC, id ASC
        ");
        $stmtAll->execute([$unitId, $dayCode]);
        $allShifts = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        // Fallback untuk jadwal legacy jika target_type = 'staff' belum ada
        if (empty($allShifts)) {
            $stmtLegacy = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? AND day_code = ? AND is_active = 'active' AND (staff_in IS NOT NULL AND staff_in != '00:00:00')
                ORDER BY is_overnight ASC, id ASC
            ");
            $stmtLegacy->execute([$unitId, $dayCode]);
            $allShifts = $stmtLegacy->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fallback jika hari ini belum terdaftar jadwal, ambil template shift dari hari lain di unit yang sama
        if (empty($allShifts)) {
            $stmtAny = $pdo->prepare("
                SELECT * FROM weekly_schedules 
                WHERE unit_id = ? AND is_active = 'active' 
                  AND (target_type = 'staff' OR (staff_in IS NOT NULL AND staff_in != '00:00:00'))
                ORDER BY id ASC LIMIT 1
            ");
            $stmtAny->execute([$unitId]);
            $any = $stmtAny->fetch(PDO::FETCH_ASSOC);

            return [
                'has_schedule' => true,
                'id' => $any ? (int)$any['id'] : 0,
                'schedule_id' => $any ? (int)$any['id'] : 0,
                'is_overnight' => !empty($any['is_overnight']),
                'staff_in' => $any['staff_in'] ?? '06:45:00',
                'staff_late' => $any['staff_late'] ?? '07:00:00',
                'staff_out' => $any['staff_out'] ?? '15:30:00',
                'name' => $any['name'] ?: 'Shift Reguler Staff'
            ];
        }

        if (count($allShifts) === 1) {
            $s = $allShifts[0];
            $isOvernight = !empty($s['is_overnight']) || ($s['staff_in'] > $s['staff_out']);
            return [
                'has_schedule' => true,
                'id' => (int)$s['id'],
                'schedule_id' => (int)$s['id'],
                'is_overnight' => $isOvernight,
                'staff_in' => $s['staff_in'],
                'staff_late' => $s['staff_late'],
                'staff_out' => $s['staff_out'],
                'name' => $s['name'] ?: 'Shift Staff'
            ];
        }

        // Jika terdapat lebih dari 1 shift (misal Shift 1 Siang 06:00 vs Shift 2 Malam 18:00):
        $selectedShift = null;
        $minDiff = PHP_INT_MAX;

        foreach ($allShifts as $s) {
            $shiftInSec = strtotime($s['staff_in']);
            $diff = abs($currentSeconds - $shiftInSec);
            if ($diff > 43200) {
                $diff = 86400 - $diff;
            }
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $selectedShift = $s;
            }
        }

        $s = $selectedShift ?: $allShifts[0];
        $isOvernight = !empty($s['is_overnight']) || ($s['staff_in'] > $s['staff_out']);
        return [
            'has_schedule' => true,
            'id' => (int)$s['id'],
            'schedule_id' => (int)$s['id'],
            'is_overnight' => $isOvernight,
            'staff_in' => $s['staff_in'],
            'staff_late' => $s['staff_late'],
            'staff_out' => $s['staff_out'],
            'name' => $s['name'] ?: 'Shift Staff'
        ];
    }
}

if (!function_exists('getDailyActivity')) {
    function getDailyActivity(PDO $pdo, int $unitId, string $dateToday, int $dayCode, string $targetType = 'student'): ?array {
        // 1. Cek apakah ada Activity Khusus buatan admin (bukan placeholder KBM otomatis)
        // Aturan: Ketika ada activities khusus, jadwal pekanan tidak dianggap.
        $stmt = $pdo->prepare("
            SELECT * FROM activities 
            WHERE activity_date = ? AND unit_id = ? 
              AND (target_type = ? OR target_type = 'all' OR target_type IS NULL) 
              AND status = 'active' AND is_holiday = 'no'
              AND (is_auto_generated = 0 AND name NOT IN ('KBM Reguler', 'Jadwal Reguler Siswa', 'Jadwal Reguler Staff & Guru'))
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$dateToday, $unitId, $targetType]);
        $customActivity = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($customActivity) {
            return $customActivity;
        }

        // 2. Jika activities khusus kosong, maka JADWAL PEKANAN diutamakan
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

        $studentIn = $weekly['student_in'] ?? '06:30:00';
        $studentLate = (!empty($weekly['student_late']) && $weekly['student_late'] !== '00:00:00') 
            ? $weekly['student_late'] 
            : date('H:i:s', strtotime('+15 minutes', strtotime($studentIn)));
        $studentOut = $weekly['student_out'] ?? '15:30:00';

        $staffIn = $weekly['staff_in'] ?? '06:45:00';
        $staffLate = (!empty($weekly['staff_late']) && $weekly['staff_late'] !== '00:00:00') 
            ? $weekly['staff_late'] 
            : '07:00:00';
        $staffOut = $weekly['staff_out'] ?? '15:30:00';

        // Pastikan placeholder di tabel activities ada & aktif untuk foreign key activity_id
        $stmtExist = $pdo->prepare("
            SELECT * FROM activities 
            WHERE activity_date = ? AND unit_id = ? 
              AND (target_type = ? OR target_type = 'all' OR target_type IS NULL)
            ORDER BY id DESC LIMIT 1
        ");
        $stmtExist->execute([$dateToday, $unitId, $targetType]);
        $activity = $stmtExist->fetch(PDO::FETCH_ASSOC);

        if (!$activity) {
            $stmtAy = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1");
            $ay = $stmtAy->fetch(PDO::FETCH_ASSOC);
            $ayId = (int)($ay ? $ay['id'] : 1);
            $actName = ($targetType === 'staff') ? "Jadwal Reguler Staff & Guru" : "KBM Reguler";

            $insertAct = $pdo->prepare("
                INSERT INTO activities 
                (academic_year_id, unit_id, target_type, name, activity_date, student_in, student_late, student_out, staff_in, staff_late, staff_out, status, is_auto_generated) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1)
            ");
            $insertAct->execute([
                $ayId, $unitId, $targetType, $actName, $dateToday, 
                $studentIn, $studentLate, $studentOut, 
                $staffIn, $staffLate, $staffOut
            ]);
            $stmtExist->execute([$dateToday, $unitId, $targetType]);
            $activity = $stmtExist->fetch(PDO::FETCH_ASSOC);
        } else {
            // Jika placeholder ada tapi closed atau jamnya berbeda dengan weekly_schedules, aktifkan & sinkronkan
            if ($activity['status'] !== 'active' || $activity['is_auto_generated'] == 1) {
                $pdo->prepare("
                    UPDATE activities 
                    SET status = 'active', student_in = ?, student_late = ?, student_out = ?, is_auto_generated = 1 
                    WHERE id = ?
                ")->execute([$studentIn, $studentLate, $studentOut, $activity['id']]);
                $activity['status'] = 'active';
                $activity['student_in'] = $studentIn;
                $activity['student_late'] = $studentLate;
                $activity['student_out'] = $studentOut;
            }
        }

        return $activity ?: null;
    }
}

if (!function_exists('getAttendanceUnitSettings')) {
    function getAttendanceUnitSettings(PDO $pdo, int $unitId): array {
        static $cache = [];
        $unitId = (int)$unitId;
        if (isset($cache[$unitId])) {
            return $cache[$unitId];
        }

        $default = [
            'unit_id' => $unitId,
            'early_departure_mode' => 'offset_minutes',
            'early_departure_minutes' => 0, // 0 = tepat jam kepulangan
            'early_departure_time' => null,
            'auto_attendance_mode' => 'offset_minutes',
            'auto_attendance_minutes' => 180, // default 180 menit (3 jam) setelah jam masuk
            'auto_attendance_time' => null,
            'checkout_window_hours' => 4.0, // default 4 jam setelah jam kepulangan
            'checkout_max_delay_minutes' => 60, // default 60 menit setelah jam kepulangan
        ];

        if ($unitId <= 0) {
            $cache[$unitId] = $default;
            return $default;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM attendance_unit_settings WHERE unit_id = ? LIMIT 1");
            $stmt->execute([$unitId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $cache[$unitId] = array_merge($default, $row);
                return $cache[$unitId];
            }
        } catch (Throwable $e) {}

        $cache[$unitId] = $default;
        return $default;
    }
}

if (!function_exists('getAttendanceSessionLimits')) {
    function getAttendanceSessionLimits(PDO $pdo, int $unitId, string $dateToday, string $studentInTime, string $studentOutTime): array {
        $settings = getAttendanceUnitSettings($pdo, $unitId);
        $jamMasukDetik = strtotime($studentInTime);
        $jamPulangDetik = strtotime($studentOutTime);

        // 1. Tutup Masuk / Mulai Auto-Alpha
        if ($settings['auto_attendance_mode'] === 'fixed_time' && !empty($settings['auto_attendance_time'])) {
            $tutupMasuk = strtotime($dateToday . ' ' . $settings['auto_attendance_time']);
        } else {
            $mins = (int)($settings['auto_attendance_minutes'] ?? 180);
            if ($mins <= 0) $mins = 180;
            $tutupMasuk = strtotime("+{$mins} minutes", $jamMasukDetik);
        }

        // 2. Batas Pulang Cepat
        if ($settings['early_departure_mode'] === 'fixed_time' && !empty($settings['early_departure_time'])) {
            $batasPulangCepat = strtotime($dateToday . ' ' . $settings['early_departure_time']);
        } else {
            $earlyMins = (int)($settings['early_departure_minutes'] ?? 0);
            $batasPulangCepat = strtotime("-{$earlyMins} minutes", $jamPulangDetik);
        }

        // 3. Batas Akses Kepulangan (Tutup Pulang)
        $windowHours = (float)($settings['checkout_window_hours'] ?? 4.0);
        if ($windowHours <= 0) $windowHours = 4.0;
        $tutupPulang = strtotime("+" . round($windowHours * 3600) . " seconds", $jamPulangDetik);

        // 4. Batas Pulang Terlalu Lama
        $maxDelayMins = (int)($settings['checkout_max_delay_minutes'] ?? 60);
        if ($maxDelayMins <= 0) $maxDelayMins = 60;
        $batasTerlaluLamaDetik = $maxDelayMins * 60;

        return [
            'settings' => $settings,
            'jamMasukDetik' => $jamMasukDetik,
            'jamPulangDetik' => $jamPulangDetik,
            'bukaMasuk' => strtotime('-2 hours', $jamMasukDetik),
            'tutupMasuk' => $tutupMasuk,
            'bukaPulang' => $tutupMasuk,
            'batasPulangCepat' => $batasPulangCepat,
            'tutupPulang' => $tutupPulang,
            'batasTerlaluLamaDetik' => $batasTerlaluLamaDetik
        ];
    }
}

/**
 * Resolusi ID Akun Mentor Terhubung (Official NIP User ID & Akun Duplikat/Alias)
 * Memastikan bila guru mentor login menggunakan NIP maupun username nama,
 * sistem secara otomatis menarik santri binaan sesuai kelompoknya.
 */
if (!function_exists('getMentorLinkedUserIds')) {
    function getMentorLinkedUserIds(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        // Peta relasi akun resmi NIP vs akun cadangan/alias hasil import sheet
        static $aliasMap = [
            // Abdul Azis Hawari, S.Pd
            8      => [8, 100132],
            100132 => [8, 100132],
            // Achmad Arizal Hamdhani Batamas Putra S.Pd
            22     => [22, 100139],
            100139 => [22, 100139],
            // M. Munarul Hidayat, S.Pd
            19     => [19, 100134],
            100134 => [19, 100134],
            // Muhammad Nauval Hamdi, M.Pd (User 32 aktif, 24 staff alt, 100137 dup)
            32     => [32, 24, 100137],
            24     => [32, 24, 100137],
            100137 => [32, 24, 100137],
            // R. Lina Pratiwi Putri, S.P.
            20     => [20, 100136],
            100136 => [20, 100136],
            // Restu, S.Pd.
            23     => [23, 100138],
            100138 => [23, 100138],
            // Surniyah Suta, S.Pd
            16     => [16, 100127],
            100127 => [16, 100127],
            // Widia Utami Ningsih, S.Pd.
            38     => [38, 100128],
            100128 => [38, 100128],
            // Subaehah
            17     => [17, 100133],
            100133 => [17, 100133],
            // Eko Sukristian Adi Nugeroho, M.Pd
            13     => [13, 100135],
            100135 => [13, 100135],
            // Ade Iwan, S.Sy
            25     => [25, 100142],
            100142 => [25, 100142],
        ];

        return $aliasMap[$userId] ?? [$userId];
    }
}

/**
 * Mengambil seluruh user ID yang berelasi dengan mentor:
 * Mencakup user ID asli, alias ID, serta seluruh akun yang memiliki kesamaan nama lengkap.
 * Mencegah data santri binaan terputus bila ada akun duplikat hasil import sheet.
 */
if (!function_exists('getMentorAllAccountIds')) {
    function getMentorAllAccountIds(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $ids = [$userId];

        try {
            $stmt = $pdo->prepare("SELECT TRIM(name) FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $name = $stmt->fetchColumn();

            if (!empty($name)) {
                $stmtAll = $pdo->prepare("SELECT id FROM users WHERE TRIM(name) = ?");
                $stmtAll->execute([$name]);
                $sameNameIds = $stmtAll->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($sameNameIds)) {
                    $ids = array_merge($ids, $sameNameIds);
                }
            }
        } catch (Exception $e) {
            // Null safety
        }

        if (function_exists('getMentorLinkedUserIds')) {
            $ids = array_merge($ids, getMentorLinkedUserIds($pdo, $userId));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
