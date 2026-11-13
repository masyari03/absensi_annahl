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

// ACCESS CONTROL â€” DATABASE-DRIVEN ACL DENGAN GRANULAR CRUD & MULTI-ROLE

// =========================================================================

/**

 * Dipanggil setelah login ATAU setelah Super Admin mengubah hak akses user.

 * Mengisi $_SESSION['roles'], $_SESSION['menu_keys'], dan $_SESSION['menu_permissions'].

 */

if (!function_exists('loadUserPermissions')) {
    function loadUserPermissions(PDO $pdo, int $userId, bool $forceReload = false): void
    {
        static $loadedInRequest = [];
        if ($userId <= 0) {
            return;
        }
        if (!$forceReload && isset($loadedInRequest[$userId])) {
            return;
        }
        $loadedInRequest[$userId] = true;

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

        // Hitung Permissions: Explicit per-user override (user_menu_access) menimpa Role (additive multi-role union)
        $sql = "
            SELECT 
                am.menu_key,
                COALESCE(MAX(uma.can_read), MAX(rma.can_read), 0) as can_read,
                COALESCE(MAX(uma.can_create), MAX(rma.can_create), 0) as can_create,
                COALESCE(MAX(uma.can_edit), MAX(rma.can_edit), 0) as can_edit,
                COALESCE(MAX(uma.can_delete), MAX(rma.can_delete), 0) as can_delete
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

        $webPermitEnabled = !function_exists('getSystemSetting') || (getSystemSetting($pdo, 'student_web_permit_enabled', '1') === '1');
        $isSuperOrKepsek = in_array($primaryRole, ['super_admin', 'kepala_sekolah'], true)
            || in_array('super_admin', $roleKeys, true)
            || in_array('kepala_sekolah', $roleKeys, true);

        $allowedMenuKeys = [];
        $menuPermissions = [];
        foreach ($rows as $row) {
            $mKey = $row['menu_key'];
            if ($mKey === 'user_activity') {
                continue;
            }
            if (!$webPermitEnabled && $mKey === 'student_permits') {
                continue;
            }
            if (!$isSuperOrKepsek && $mKey === 'mentoring_assignments') {
                continue;
            }
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
            loadUserPermissions($pdo, $currentUserId, true);
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
    function checkUserAccess(string $menuKey, string $action = 'read', ?string $redirect = null): void
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
        $sessionRoles = $_SESSION['roles'] ?? [$role];
        // Super Admin selalu lolos
        if ($role === 'super_admin' || in_array('super_admin', $sessionRoles, true)) {
            return;
        }
        // Halaman Dashboard selalu diizinkan untuk read bagi semua user yang sudah login
        if ($menuKey === 'dashboard' && $action === 'read') {
            return;
        }
        // Sinkronkan dari DB (sekali per HTTP request via loadUserPermissions)
        global $pdo;
        if (isset($pdo)) {
            loadUserPermissions($pdo, (int)$_SESSION['user_id']);
        }
        // Validasi akses granular via hasMenuAccess jika tersedia, fallback ke menu_keys
        $hasAccess = function_exists('hasMenuAccess')
            ? hasMenuAccess($menuKey, $action, (int)$_SESSION['user_id'])
            : in_array($menuKey, $_SESSION['menu_keys'] ?? [], true);

        if (!$hasAccess) {
            flash('error', 'Anda tidak memiliki hak akses untuk halaman atau tindakan tersebut.');
            $target = $redirect ?? BASE_URL . '/dashboard.php';
            header('Location: ' . $target);
            exit;
        }
    }
}

// hasMenuAccess() canonical implementation lives in config/auth.php (supports $action & $userId)

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

// SUPERVISI PEMBELAJARAN (KURMAA) â€” SCOPING UNIT & ELIGIBLE USERS/STAFF

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

        // Role kepala_sekolah dikecualikan dari observer sejawat KECUALI diberi izin oleh Super Admin

        $kepsekFilter = '(u.role != "kepala_sekolah" OR u.id IN (SELECT user_id FROM supervision_kepsek_peer_permissions))';

        if ($unitId !== null && $unitId > 0) {

            $where[] = "(

                u.role IN ('super_admin', 'admin')

                OR (s.unit_id = ? AND {$kepsekFilter})

                OR (u.id IN (SELECT user_id FROM user_roles WHERE role_id IN (SELECT id FROM roles WHERE role_key = 'observer')) AND {$kepsekFilter})

            )";

            $params[] = $unitId;

        } else {

            $where[] = "(

                u.role IN ('super_admin', 'admin')

                OR (s.unit_id IN (1, 2, 3, 7) AND {$kepsekFilter})

                OR (u.id IN (SELECT user_id FROM user_roles WHERE role_id IN (SELECT id FROM roles WHERE role_key = 'observer')) AND {$kepsekFilter})

            )";

        }

        $whereSql = implode(' AND ', $where);

        $sql = "

            SELECT DISTINCT u.id, u.name, u.username, u.role, un.id as unit_id, un.unit as unit_name

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

            error_log("getSupervisionObservers error: " . $e->getMessage());

            return [];

        }

    }

}

if (!function_exists('getSupervisionPrincipals')) {

    function getSupervisionPrincipals(PDO $pdo, ?int $unitId = null): array {

        $where = [

            'u.deleted_at IS NULL',

            "u.role IN ('kepala_sekolah', 'admin', 'super_admin')",

            "u.name NOT IN ('absen', 'arif', 'Absen Barcode Siswa')"

        ];

        $params = [];

        if ($unitId !== null && $unitId > 0) {

            $where[] = '(u.role = "super_admin" OR s.unit_id = ? OR s.unit_id IS NULL)';

            $params[] = $unitId;

        }

        $whereSql = implode(' AND ', $where);

        $sql = "

            SELECT DISTINCT u.id, u.name, u.role, un.unit as unit_name

            FROM users u

            LEFT JOIN staff s ON (s.user_id = u.id OR s.nik = u.username)

            LEFT JOIN units un ON un.id = s.unit_id

            WHERE {$whereSql}

            ORDER BY FIELD(u.role, 'kepala_sekolah', 'admin', 'super_admin'), u.name ASC

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

if (!function_exists('canUserCreateSupervisionSchedule')) {

    function canUserCreateSupervisionSchedule(PDO $pdo, int $userId, string $role, ?int $unitId = null): bool {

        if (in_array($role, ['super_admin', 'admin', 'kepala_sekolah'], true)) {

            return true;

        }

        try {

            $stmt = $pdo->prepare("SELECT 1 FROM supervision_schedule_creator_permissions WHERE user_id = ? LIMIT 1");

            $stmt->execute([$userId]);

            return (bool)$stmt->fetchColumn();

        } catch (Exception $e) {

            return false;

        }

    }

}

if (!function_exists('getSupervisionScheduleCreators')) {

    function getSupervisionScheduleCreators(PDO $pdo, ?int $unitId = null): array {

        try {

            $sql = "

                SELECT sscp.*, u.name as teacher_name, u.username as teacher_username,

                       ug.name as granted_by_name, un.unit as unit_name

                FROM supervision_schedule_creator_permissions sscp

                JOIN users u ON u.id = sscp.user_id

                LEFT JOIN users ug ON ug.id = sscp.granted_by

                LEFT JOIN staff s ON (s.user_id = u.id OR s.nik = u.username)

                LEFT JOIN units un ON un.id = COALESCE(sscp.unit_id, s.unit_id)

            ";

            $params = [];

            if ($unitId !== null && $unitId > 0) {

                $sql .= " WHERE (sscp.unit_id = ? OR s.unit_id = ?)";

                $params = [$unitId, $unitId];

            }

            $sql .= " ORDER BY u.name ASC";

            $stmt = $pdo->prepare($sql);

            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {

            return [];

        }

    }

}

if (!function_exists('canKepsekAccessPeerSupervision')) {

    function canKepsekAccessPeerSupervision(PDO $pdo, int $userId): bool {

        try {

            $stmt = $pdo->prepare("SELECT 1 FROM supervision_kepsek_peer_permissions WHERE user_id = ? LIMIT 1");

            $stmt->execute([$userId]);

            return (bool)$stmt->fetchColumn();

        } catch (Exception $e) {

            return false;

        }

    }

}

if (!function_exists('getKepsekPeerAllowedUserIds')) {

    function getKepsekPeerAllowedUserIds(PDO $pdo): array {

        try {

            return $pdo->query("SELECT user_id FROM supervision_kepsek_peer_permissions")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        } catch (Exception $e) {

            return [];

        }

    }

}

if (!function_exists('getSupervisionTimeLockStatus')) {

    function getSupervisionTimeLockStatus(string $scheduledDate, ?string $timeStart, ?string $timeEnd, int $bypassLock): array {

        $now = time();

        $tStart = !empty($timeStart) ? $timeStart : '00:00:00';

        $startTs = strtotime("{$scheduledDate} {$tStart}");

        if (!empty($timeEnd)) {

            $endTs = strtotime("{$scheduledDate} {$timeEnd}");

        } elseif (!empty($timeStart)) {

            // Default toleransi 2 jam jika time_end tidak terisi

            $endTs = $startTs + 7200;

        } else {

            // Jika time_start dan time_end tidak terisi, berlaku hingga akhir hari (23:59:59)

            $endTs = strtotime("{$scheduledDate} 23:59:59");

        }

        $isBypassed = ($bypassLock === 1);

        if ($isBypassed) {

            return [

                'is_locked'    => false,

                'status'       => 'bypassed',

                'status_key'   => 'bypassed',

                'badge_label'  => 'Jam Dibuka',

                'badge_bg'     => '#dcfce7',

                'badge_color'  => '#15803d',

                'badge_icon'   => 'fa-lock-open',

                'lock_message' => ''

            ];

        }

        if ($now < $startTs) {

            $timeDisplay = !empty($timeStart) ? substr($timeStart, 0, 5) : '00:00';

            return [

                'is_locked'    => true,

                'status'       => 'before',

                'status_key'   => 'before',

                'badge_label'  => 'Belum Jamnya',

                'badge_bg'     => '#fef3c7',

                'badge_color'  => '#b45309',

                'badge_icon'   => 'fa-lock',

                'lock_message' => "Jadwal supervisi belum dimulai. Waktu pelaksanaan terdaftar pada " . formatIndoDate($scheduledDate) . " pukul " . $timeDisplay . ". Penilaian dikunci sampai jam pelaksanaan dimulai, atau sampai Kepala Sekolah membuka kunci jam supervisi."

            ];

        }

        if ($now > $endTs) {

            $timeDisplay = !empty($timeEnd) ? substr($timeEnd, 0, 5) : date('H:i', $endTs);

            return [

                'is_locked'    => true,

                'status'       => 'expired',

                'status_key'   => 'expired',

                'badge_label'  => 'Lewat Jam',

                'badge_bg'     => '#fee2e2',

                'badge_color'  => '#b91c1c',

                'badge_icon'   => 'fa-clock-xmark',

                'lock_message' => "Waktu pelaksanaan jadwal supervisi ini telah lewat (berakhir pada pukul {$timeDisplay}). Penilaian telah ditutup. Silakan hubungi Kepala Sekolah untuk membuka kunci jam jika membutuhkan dispensasi waktu pengisian."

            ];

        }

        return [

            'is_locked'    => false,

            'status'       => 'active',

                'status_key'   => 'active',

            'badge_label'  => 'Sesi Aktif',

            'badge_bg'     => '#dbeafe',

            'badge_color'  => '#1d4ed8',

            'badge_icon'   => 'fa-play',

            'lock_message' => ''

        ];

    }

}

if (!function_exists('isObserverSameUnit')) {

    function isObserverSameUnit(PDO $pdo, int $teacherStaffId, int $observerUserId): bool {

        try {

            $stmtT = $pdo->prepare("SELECT unit_id FROM staff WHERE id = ? LIMIT 1");

            $stmtT->execute([$teacherStaffId]);

            $tUnit = $stmtT->fetchColumn();

            if ($tUnit === false || $tUnit === null) return true;

            $stmtO = $pdo->prepare("

                SELECT s.unit_id, u.role 

                FROM users u

                LEFT JOIN staff s ON (s.user_id = u.id OR s.nik = u.username)

                WHERE u.id = ? LIMIT 1

            ");

            $stmtO->execute([$observerUserId]);

            $obs = $stmtO->fetch(PDO::FETCH_ASSOC);

            if (!$obs) return false;

            if ($obs['role'] === 'super_admin') return true;

            if ($obs['role'] === 'kepala_sekolah' && !canKepsekAccessPeerSupervision($pdo, $observerUserId)) return false;

            $oUnit = $obs['unit_id'];

            if ($oUnit === null) return true;

            return ((int)$tUnit === (int)$oUnit);

        } catch (Exception $e) {

            return true;

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

                'academic_year' => '2026/2027 â€¢ Semester Ganjil',

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

// =========================================================================

// SISTEM NOTIFIKASI TERINTEGRASI (PLAN EDISI 14)

// =========================================================================

if (!function_exists('calibrateNotificationUrl')) {
    /**
     * Kalibrasi URL Notifikasi ke Absolute Path BASE_URL
     * Mencegah 404 Not Found saat notifikasi diklik dari sub-folder mana pun.
     */
    function calibrateNotificationUrl(?string $url): string {
        if (empty($url) || trim($url) === '') {
            return (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/notifications/index.php';
        }
        $url = trim($url);
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        if (!empty($baseUrl) && strpos($url, $baseUrl) === 0) {
            return $url;
        }

        $cleanPath = ltrim($url, '/');
        if (strpos($cleanPath, 'public/') === 0) {
            $cleanPath = substr($cleanPath, 7);
        }

        return $baseUrl . '/' . $cleanPath;
    }
}

if (!function_exists('createNotification')) {

    /**

     * Membuat notifikasi baru untuk user tertentu secara aman.

     * Mendukung 2 signature urutan parameter:

     * 1. createNotification($pdo, $userId, $category, $title, $message, $linkUrl)

     * 2. createNotification($pdo, $userId, $title, $message, $category, $linkUrl)

     */

    function createNotification(PDO $pdo, int $userId, string $arg3, string $arg4, ?string $arg5 = null, ?string $linkUrl = null): bool

    {

        if ($userId <= 0) {

            return false;

        }

        $validCategories = ['supervisi', 'mentoring', 'presensi', 'sistem'];

        if (in_array($arg3, $validCategories, true)) {

            $category = $arg3;

            $title = $arg4;

            $message = (string)($arg5 ?? '');

        } else {

            $title = $arg3;

            $message = $arg4;

            $category = in_array((string)$arg5, $validCategories, true) ? (string)$arg5 : 'sistem';

        }

        if (empty($title)) {

            return false;

        }

        try {

            $stmt = $pdo->prepare("

                INSERT INTO notifications (user_id, title, message, category, link_url, is_read, created_at)

                VALUES (?, ?, ?, ?, ?, 0, NOW())

            ");

            $calibratedUrl = (!empty($linkUrl) && trim($linkUrl) !== '') ? calibrateNotificationUrl($linkUrl) : null;
            return $stmt->execute([$userId, trim($title), trim($message), $category, $calibratedUrl]);

        } catch (Exception $e) {

            error_log('Error createNotification: ' . $e->getMessage());

            return false;

        }

    }

}

if (!function_exists('getUnreadNotificationCount')) {

    function getUnreadNotificationCount(PDO $pdo, int $userId): int

    {

        if ($userId <= 0) return 0;

        try {

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");

            $stmt->execute([$userId]);

            return (int)$stmt->fetchColumn();

        } catch (Exception $e) {

            return 0;

        }

    }

}

if (!function_exists('getRecentNotifications')) {

    function getRecentNotifications(PDO $pdo, int $userId, int $limit = 5): array

    {

        if ($userId <= 0) return [];

        $limit = max(1, min(50, (int)$limit));

        try {

            $stmt = $pdo->prepare("

                SELECT id, title, message, category, link_url, is_read, created_at

                FROM notifications

                WHERE user_id = ?

                ORDER BY created_at DESC, id DESC

                LIMIT {$limit}

            ");

            $stmt->execute([$userId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {

            return [];

        }

    }

}

if (!function_exists('markNotificationAsRead')) {

    function markNotificationAsRead(PDO $pdo, int $notifId, int $userId): bool

    {

        if ($notifId <= 0 || $userId <= 0) return false;

        try {

            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");

            return $stmt->execute([$notifId, $userId]);

        } catch (Exception $e) {

            return false;

        }

    }

}

if (!function_exists('markAllNotificationsAsRead')) {

    function markAllNotificationsAsRead(PDO $pdo, int $userId): bool

    {

        if ($userId <= 0) return false;

        try {

            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");

            return $stmt->execute([$userId]);

        } catch (Exception $e) {

            return false;

        }

    }

}

if (!function_exists('formatTimeAgo')) {

    function formatTimeAgo($datetime): string

    {

        if (empty($datetime)) return '-';

        $time = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);

        $diff = time() - $time;

        if ($diff < 60) return 'Baru saja';

        if ($diff < 3600) return floor($diff / 60) . ' menit lalu';

        if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';

        if ($diff < 604800) return floor($diff / 86400) . ' hari lalu';

        return date('d M Y', $time);

    }

}

if (!function_exists('notifyLateStudentAttendance')) {

    /**

     * Kirim notifikasi otomatis kepada guru/wali kelas/kepsek pemantau ketika siswa hadir terlambat.

     */

    function notifyLateStudentAttendance(PDO $pdo, int $studentId, string $timeIn, string $status = 'terlambat'): void

    {

        if ($studentId <= 0 || $status !== 'terlambat') {

            return;

        }

        try {

            // Dapatkan informasi siswa, kelas, dan unit

            $stmt = $pdo->prepare("

                SELECT s.name AS student_name, cg.id AS class_group_id, cg.name AS class_name, g.id AS grade_id, g.unit_id

                FROM students s

                JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'

                JOIN class_groups cg ON cg.id = se.class_group_id

                JOIN grades g ON g.id = cg.grade_id

                WHERE s.id = ?

                LIMIT 1

            ");

            $stmt->execute([$studentId]);

            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {

                return;

            }

            $studentName = $student['student_name'];

            $className = $student['class_name'];

            $classId = (int)$student['class_group_id'];

            $gradeId = (int)$student['grade_id'];

            $unitId = (int)$student['unit_id'];

            $jam = date('H:i', strtotime($timeIn));

            $today = date('Y-m-d');

            // Cari user guru / pemantau / wali kelas / kepala sekolah yang punya izin ke kelas, tingkat, atau unit ini

            $stmtUsers = $pdo->prepare("

                SELECT DISTINCT u.id

                FROM users u

                LEFT JOIN admin_class_permissions acp ON acp.user_id = u.id AND acp.class_group_id = ?

                LEFT JOIN admin_grade_permissions agp ON agp.user_id = u.id AND agp.grade_id = ?

                LEFT JOIN admin_unit_permissions aup ON aup.user_id = u.id AND aup.unit_id = ?

                LEFT JOIN staff st ON st.user_id = u.id AND st.unit_id = ?

                WHERE (acp.class_group_id IS NOT NULL 

                       OR agp.grade_id IS NOT NULL 

                       OR aup.unit_id IS NOT NULL 

                       OR (st.unit_id IS NOT NULL AND u.role IN ('kepala_sekolah', 'admin')))

                  AND u.role IN ('guru', 'wali_kelas', 'kepala_sekolah', 'admin', 'staff')

            ");

            $stmtUsers->execute([$classId, $gradeId, $unitId, $unitId]);

            $recipients = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);

            if (empty($recipients)) {

                return;

            }

            $title = "Siswa Terlambat: {$studentName}";

            $message = "Siswa {$studentName} ({$className}) tercatat terlambat hadir pada pukul {$jam}.";

            $linkUrl = "attendance/students.php?class_group_id={$classId}&date={$today}";

            // Check duplicate notif today for this student & user to avoid spam

            $checkStmt = $pdo->prepare("

                SELECT id FROM notifications 

                WHERE user_id = ? AND category = 'presensi' 

                  AND title = ? AND DATE(created_at) = ? 

                LIMIT 1

            ");

            foreach ($recipients as $uid) {

                $uid = (int)$uid;

                $checkStmt->execute([$uid, $title, $today]);

                if (!$checkStmt->fetchColumn()) {

                    createNotification($pdo, $uid, 'presensi', $title, $message, $linkUrl);

                }

            }

        } catch (Throwable $e) {

            error_log("notifyLateStudentAttendance error: " . $e->getMessage());

        }

    }

}


// =========================================================================
// MENTORING TEACHER PERMISSIONS – Izin Guru Akses Kelola Mentoring
// Diberikan oleh Kepala Sekolah atau Super Admin per unit.
// Cara edit manual:
//   - grantMentoringTeacherPermission($pdo, $guruId, $kepsekId, $unitId)
//   - revokeMentoringTeacherPermission($pdo, $guruId)
// =========================================================================

if (!function_exists('canTeacherAccessMentoring')) {
    /**
     * Cek apakah guru diberi izin akses modul mentoring.
     * Terintegrasi penuh dengan Matriks RBAC (user_menu_access & hasMenuAccess),
     * tabel perizinan kepsek (mentoring_teacher_permissions),
     * serta penugasan mentor aktif (mentoring_assignments).
     *
     * @param PDO $pdo
     * @param int $userId  ID user guru yang dicek
     * @return bool
     */
    function canTeacherAccessMentoring(PDO $pdo, int $userId): bool {
        if ($userId <= 0) {
            return false;
        }

        // 1. Integrasi Matriks RBAC: jika user memiliki custom override di user_menu_access
        try {
            $stmtUma = $pdo->prepare("
                SELECT 1 FROM user_menu_access uma
                INNER JOIN app_menus am ON am.id = uma.menu_id
                WHERE uma.user_id = ? 
                  AND am.menu_key IN ('mentoring_dashboard', 'mentoring_adab', 'mentoring_report', 'mentoring_curriculum')
                  AND uma.can_read = 1
                LIMIT 1
            ");
            $stmtUma->execute([$userId]);
            if ($stmtUma->fetchColumn()) {
                return true;
            }
        } catch (Exception $e) {}

        // 2. Cek apakah ada record di mentoring_teacher_permissions
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM mentoring_teacher_permissions WHERE user_id = ? LIMIT 1"
            );
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (Exception $e) {}

        // 3. Cek apakah user ditugaskan sebagai mentor aktif di kelompok santri
        try {
            $stmtMa = $pdo->prepare(
                "SELECT 1 FROM mentoring_assignments WHERE mentor_user_id = ? AND is_active = 1 LIMIT 1"
            );
            $stmtMa->execute([$userId]);
            if ($stmtMa->fetchColumn()) {
                return true;
            }
        } catch (Exception $e) {}

        // 4. Role istimewa (super_admin, kepala_sekolah, admin) otomatis diizinkan
        try {
            $stmtR = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmtR->execute([$userId]);
            $uRole = $stmtR->fetchColumn();
            if (in_array($uRole, ['super_admin', 'kepala_sekolah', 'admin'], true)) {
                return true;
            }
        } catch (Exception $e) {}

        return false;
    }
}

if (!function_exists('getMentoringAllowedTeacherIds')) {
    /**
     * Ambil semua user_id guru yang diizinkan akses mentoring.
     * @param PDO      $pdo
     * @param int|null $unitId  Filter per unit jika diisi
     * @return int[]
     */
    function getMentoringAllowedTeacherIds(PDO $pdo, ?int $unitId = null): array {
        try {
            $ids = [];
            if ($unitId !== null && $unitId > 0) {
                $stmt = $pdo->prepare(
                    "SELECT user_id FROM mentoring_teacher_permissions WHERE unit_id = ?"
                );
                $stmt->execute([$unitId]);
            } else {
                $stmt = $pdo->query(
                    "SELECT user_id FROM mentoring_teacher_permissions"
                );
            }
            $ids = array_merge($ids, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            // Sertakan guru yang aktif ditugaskan sebagai mentor
            $stmtMa = $pdo->query("SELECT DISTINCT mentor_user_id FROM mentoring_assignments WHERE is_active = 1");
            $ids = array_merge($ids, $stmtMa->fetchAll(PDO::FETCH_COLUMN) ?: []);

            // Sertakan guru yang diberi izin custom di user_menu_access
            $stmtUma = $pdo->query("
                SELECT DISTINCT uma.user_id 
                FROM user_menu_access uma
                INNER JOIN app_menus am ON am.id = uma.menu_id
                WHERE am.menu_key IN ('mentoring_dashboard', 'mentoring_adab', 'mentoring_report')
                  AND uma.can_read = 1
            ");
            $ids = array_merge($ids, $stmtUma->fetchAll(PDO::FETCH_COLUMN) ?: []);

            return array_values(array_unique(array_filter(array_map('intval', $ids))));
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('grantMentoringTeacherPermission')) {
    /**
     * Berikan izin akses mentoring kepada seorang guru.
     * @param PDO      $pdo
     * @param int      $teacherUserId   ID guru
     * @param int      $grantedBy       ID kepala_sekolah/super_admin
     * @param int|null $unitId          Unit kepala sekolah (null untuk super_admin global)
     * @return bool
     */
    function grantMentoringTeacherPermission(PDO $pdo, int $teacherUserId, int $grantedBy, ?int $unitId = null): bool {
        try {
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO mentoring_teacher_permissions (user_id, granted_by, unit_id) VALUES (?, ?, ?)"
            );
            $stmt->execute([$teacherUserId, $grantedBy, $unitId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('revokeMentoringTeacherPermission')) {
    /**
     * Cabut izin akses mentoring dari seorang guru.
     * @param PDO $pdo
     * @param int $teacherUserId  ID guru yang dicabut izinnya
     * @return bool
     */
    function revokeMentoringTeacherPermission(PDO $pdo, int $teacherUserId): bool {
        try {
            $stmt = $pdo->prepare(
                "DELETE FROM mentoring_teacher_permissions WHERE user_id = ?"
            );
            $stmt->execute([$teacherUserId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('fetchAcademicPerformanceAverages')) {
    /**
     * Memanggil API Penilaian Kinerja Akademik dari server laptop sekolah (ais_source).
     * Dilengkapi Smart Dual-Fallback (Cloudflare Tunnel Publik -> Tailscale Internal),
     * Timeout ketat (5 detik), Anti-SSRF, dan smart file cache lokal (1 jam).
     *
     * @param int|null $unitId
     * @param int|null $tahunAjaranId
     * @param bool     $forceRefresh
     * @return array
     */
    function fetchAcademicPerformanceAverages(?int $unitId = null, ?int $tahunAjaranId = null, bool $forceRefresh = false): array {
        $primaryUrl  = defined('AIS_PERFORMANCE_API_URL') ? AIS_PERFORMANCE_API_URL : 'https://ais.codika.my.id/V1/performance/academic-averages';
        $fallbackUrl = defined('AIS_PERFORMANCE_API_FALLBACK_URL') ? AIS_PERFORMANCE_API_FALLBACK_URL : 'http://100.126.179.54:8069/V1/performance/academic-averages';
        $apiKey      = defined('AIS_PERFORMANCE_API_KEY') ? AIS_PERFORMANCE_API_KEY : 'c7f99b24e6a8d8102e3b8a139fa4219b6e828453';

        // 1. Setup Cache Path
        $cacheDir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $cacheKey = 'perf_averages_' . ($unitId ?? 'def') . '_' . ($tahunAjaranId ?? 'def') . '.json';
        $cacheFile = $cacheDir . '/' . $cacheKey;
        $cacheTtl = 3600; // 1 Jam

        // 2. Baca Cache Jika Masih Valid dan Tidak Force Refresh
        if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
            $cachedContent = @file_get_contents($cacheFile);
            if ($cachedContent) {
                $decoded = json_decode($cachedContent, true);
                if (is_array($decoded) && !empty($decoded['success'])) {
                    $decoded['from_cache'] = true;
                    return $decoded;
                }
            }
        }

        // 3. Siapkan Query Params
        $queryParams = [];
        if ($unitId !== null && $unitId > 0) {
            $queryParams['unit_id'] = $unitId;
        }
        if ($tahunAjaranId !== null && $tahunAjaranId > 0) {
            $queryParams['tahun_ajaran_id'] = $tahunAjaranId;
        }
        $queryStr = !empty($queryParams) ? ('?' . http_build_query($queryParams)) : '';

        // 4. Daftar URL Kandidat (Prioritas: Cloudflare Tunnel Publik -> Tailscale Internal)
        $candidateUrls = array_unique(array_filter([$primaryUrl, $fallbackUrl]));
        $lastCurlErr = '';
        $lastHttpCode = 0;
        $lastResponse = '';

        foreach ($candidateUrls as $baseUrl) {
            // Anti-SSRF Guard: pastikan skema valid dan bukan IP metadata cloud terlarang
            $parsedUrl = parse_url($baseUrl);
            $scheme = strtolower($parsedUrl['scheme'] ?? '');
            $host = strtolower($parsedUrl['host'] ?? '');

            if (!in_array($scheme, ['http', 'https'], true) || $host === '169.254.169.254') {
                continue;
            }

            $targetUrl = $baseUrl . $queryStr;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $targetUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-AIS-KEY: ' . $apiKey,
                'Accept: application/json',
                'User-Agent: AbsensiAnak-DataHub/1.0'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if (!$curlErr && $httpCode === 200) {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && !empty($decoded['success'])) {
                    // Simpan Hasil ke Cache Lokal
                    @file_put_contents($cacheFile, json_encode($decoded));
                    $decoded['from_cache'] = false;
                    return $decoded;
                }
            }

            $lastCurlErr  = $curlErr;
            $lastHttpCode = $httpCode;
            $lastResponse = $response;
        }

        // 5. Jika kedua jalur gagal, gunakan cache lama jika tersedia (Offline Resilient)
        if (file_exists($cacheFile)) {
            $stale = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($stale) && !empty($stale['success'])) {
                $stale['from_cache'] = true;
                $stale['warning'] = 'Menampilkan data cache offline. Server AIS laptop sekolah sedang offline (' . htmlspecialchars($lastCurlErr ?: "HTTP {$lastHttpCode}", ENT_QUOTES, 'UTF-8') . ').';
                return $stale;
            }
        }

        return [
            'success' => false,
            'meta'    => [],
            'data'    => [],
            'from_cache' => false,
            'error'   => 'Tidak dapat terhubung ke server AIS laptop sekolah: ' . ($lastCurlErr ?: ("HTTP status " . $lastHttpCode . " (" . strip_tags($lastResponse) . ")"))
        ];
    }
}

// =========================================================================
// PENILAIAN KINERJA: CAPAIAN TAHFIDZ & INDIKATOR JURNAL GURU QUR'AN
// =========================================================================

if (!function_exists('isAllowedAisApiUrl')) {
    /**
     * Guard Anti-SSRF untuk URL API AIS eksternal/tunnel/Tailscale yang dikonfigurasi.
     */
    function isAllowedAisApiUrl(string $url): bool
    {
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $allowedHosts = ['ais.codika.my.id', '100.126.179.54'];
        foreach (['AIS_TAHFIDZ_API_URL', 'AIS_TAHFIDZ_API_FALLBACK_URL', 'AIS_PERFORMANCE_API_URL', 'AIS_PERFORMANCE_API_FALLBACK_URL'] as $constName) {
            if (defined($constName)) {
                $cfgHost = strtolower((string)@parse_url((string)constant($constName), PHP_URL_HOST));
                if ($cfgHost !== '') {
                    $allowedHosts[] = $cfgHost;
                }
            }
        }
        $allowedHosts = array_unique($allowedHosts);

        if (!in_array($host, $allowedHosts, true)) {
            return false;
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Izinkan hanya IP Tailscale Peer yang terdaftar; tolak RFC1918 LAN & link-local metadata 169.254.0.0/16
            if (preg_match('/^(10\.|127\.|169\.254\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('fetchTahfidzAchievement')) {
    /**
     * Mengambil data Capaian Tahfidz Santri + Statistik Jurnal Guru Qur'an dari server AIS sekolah.
     * Dual-fallback: Cloudflare Tunnel HTTPS -> Tailscale Peer -> Local Smart Cache (1 jam).
     */
    function fetchTahfidzAchievement(?int $unitId = null, ?int $tahunAjaranId = null, int $month = 0, bool $forceRefresh = false): array
    {
        $unitPart  = ($unitId !== null && $unitId > 0) ? (int)$unitId : 'def';
        $taPart    = ($tahunAjaranId !== null && $tahunAjaranId > 0) ? (int)$tahunAjaranId : 'def';
        $monthSafe = ($month >= 1 && $month <= 12) ? $month : 0;

        $cacheDir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . "/tahfidz_achievement_u{$unitPart}_ta{$taPart}_m{$monthSafe}.json";
        $cacheTtl  = 3600;

        if (!$forceRefresh && is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < $cacheTtl) {
            $cachedRaw = @file_get_contents($cacheFile);
            if ($cachedRaw !== false) {
                $cachedData = json_decode($cachedRaw, true);
                if (is_array($cachedData) && !empty($cachedData['success'])) {
                    $cachedData['from_cache'] = true;
                    $cachedData['cache_time'] = date('Y-m-d H:i:s', (int)filemtime($cacheFile));
                    $cachedData['error']      = null;
                    return $cachedData;
                }
            }
        }

        $queryParams = [];
        if ($unitId !== null && $unitId > 0) {
            $queryParams['unit_id'] = (int)$unitId;
        }
        if ($tahunAjaranId !== null && $tahunAjaranId > 0) {
            $queryParams['tahun_ajaran_id'] = (int)$tahunAjaranId;
        }
        if ($monthSafe > 0) {
            $queryParams['month'] = $monthSafe;
        }
        $qs = !empty($queryParams) ? ('?' . http_build_query($queryParams)) : '';

        $primaryUrl  = defined('AIS_TAHFIDZ_API_URL') ? AIS_TAHFIDZ_API_URL : 'https://ais.codika.my.id/V1/performance/tahfidz-achievement';
        $fallbackUrl = defined('AIS_TAHFIDZ_API_FALLBACK_URL') ? AIS_TAHFIDZ_API_FALLBACK_URL : 'http://100.126.179.54:8069/V1/performance/tahfidz-achievement';
        $apiKey      = defined('AIS_PERFORMANCE_API_KEY') ? AIS_PERFORMANCE_API_KEY : '';

        $candidates = [
            ['url' => $primaryUrl . $qs, 'connect_timeout' => 8, 'timeout' => 18, 'source' => 'Cloudflare Tunnel'],
            ['url' => $fallbackUrl . $qs, 'connect_timeout' => 4, 'timeout' => 14, 'source' => 'Tailscale Direct'],
        ];

        $lastError = 'Layanan AIS belum dapat dijangkau.';
        foreach ($candidates as $cand) {
            if (!isAllowedAisApiUrl($cand['url'])) {
                error_log('[fetchTahfidzAchievement] URL ditolak oleh SSRF Guard: ' . $cand['url']);
                continue;
            }
            if (!function_exists('curl_init')) {
                $lastError = 'Ekstensi PHP cURL tidak tersedia di server.';
                break;
            }

            $ch = curl_init();
            $curlOpts = [
                CURLOPT_URL            => $cand['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $cand['connect_timeout'],
                CURLOPT_TIMEOUT        => $cand['timeout'],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'X-AIS-KEY: ' . $apiKey,
                    'User-Agent: AbsensiAnak-KinerjaTahfidz/1.0',
                ],
            ];
            if (stripos($cand['url'], 'https://') === 0) {
                $curlOpts[CURLOPT_SSL_VERIFYPEER] = true;
                $curlOpts[CURLOPT_SSL_VERIFYHOST] = 2;
            }
            curl_setopt_array($ch, $curlOpts);

            $raw      = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $curlErr !== '') {
                $lastError = "Koneksi {$cand['source']} gagal.";
                error_log("[fetchTahfidzAchievement] {$cand['source']} curl error: {$curlErr}");
                continue;
            }

            $decoded = json_decode((string)$raw, true);
            if ($httpCode === 200 && is_array($decoded) && !empty($decoded['success'])) {
                $decoded['from_cache'] = false;
                $decoded['source_via'] = $cand['source'];
                $decoded['cache_time'] = date('Y-m-d H:i:s');
                $decoded['error']      = null;

                $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                if ($encoded !== false) {
                    @file_put_contents($cacheFile, $encoded);
                }
                return $decoded;
            }

            $apiMsg = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
            $lastError = "Respon server AIS (HTTP {$httpCode})" . ($apiMsg !== '' ? ': ' . $apiMsg : '.');
            error_log("[fetchTahfidzAchievement] {$cand['source']} HTTP {$httpCode}: {$apiMsg}");
        }

        return [
            'success'       => false,
            'meta'          => [],
            'students'      => [],
            'guru_journals' => [],
            'from_cache'    => false,
            'error'         => $lastError,
        ];
    }
}

if (!function_exists('getTahfidzSurahCatalog')) {
    /**
     * Peta referensi resmi tangga surah Tahfidz An-Nahl untuk dropdown target & evaluasi konsisten.
     */
    function getTahfidzSurahCatalog(): array
    {
        return [
            'juz30_rank' => [
                0  => 'QS. Al-Fatihah',
                1  => 'QS. An-Nas',
                2  => 'QS. An-Nas – Al-Falaq',
                3  => 'QS. An-Nas – Al-Ikhlas',
                4  => 'QS. An-Nas – Al-Lahab',
                5  => 'QS. An-Nas – An-Nashr',
                6  => 'QS. An-Nas – Al-Kafirun',
                7  => 'QS. An-Nas – Al-Kautsar',
                8  => "QS. An-Nas – Al-Ma'un",
                9  => 'QS. An-Nas – Quraisy',
                10 => 'QS. An-Nas – Al-Fil',
                11 => 'QS. An-Nas – Al-Humazah',
                12 => "QS. An-Nas – Al-'Ashr",
                13 => 'QS. An-Nas – At-Takatsur',
                14 => "QS. An-Nas – Al-Qari'ah",
                15 => "QS. An-Nas – Al-'Adiyat",
                16 => 'QS. An-Nas – Az-Zalzalah (H1/30)',
                17 => 'QS. An-Nas – Al-Bayyinah',
                18 => 'QS. An-Nas – Al-Qadr',
                19 => "QS. An-Nas – Al-'Alaq",
                20 => 'QS. An-Nas – At-Tin',
                21 => 'QS. An-Nas – Al-Insyirah',
                22 => 'QS. An-Nas – Adh-Dhuha',
                23 => 'QS. An-Nas – Al-Lail',
                24 => 'QS. An-Nas – Asy-Syams',
                25 => 'QS. An-Nas – Al-Balad',
                26 => 'QS. An-Nas – Al-Fajr',
                27 => 'QS. An-Nas – Al-Ghasyiyah (H2/30)',
                28 => "QS. An-Nas – Al-A'laa",
                29 => 'QS. An-Nas – At-Thariq',
                30 => 'QS. An-Nas – Al-Buruj',
                31 => 'QS. An-Nas – Al-Insyiqaq',
                32 => 'QS. An-Nas – Al-Muthaffifin (H3/30)',
                33 => 'QS. An-Nas – Al-Infithar',
                34 => 'QS. An-Nas – At-Takwir',
                35 => "QS. An-Nas – 'Abasa",
                36 => "QS. An-Nas – An-Nazi'at",
                37 => "QS. An-Nas – An-Naba' (Tuntas Juz 30)",
            ],
            'juz29_rank' => [
                38 => 'QS. Al-Mulk (Juz 29 Awal)',
                39 => 'QS. Al-Mulk – Al-Qalam (H1/29)',
                40 => 'QS. Al-Mulk – Al-Haqqah',
                41 => "QS. Al-Mulk – Al-Ma'arij",
                42 => 'QS. Al-Mulk – Nuh (H2/29)',
                43 => 'QS. Al-Mulk – Al-Jin (Juz 29 Bag. 1)',
                44 => 'QS. Al-Mulk – Al-Muzzammil',
                45 => 'QS. Al-Mulk – Al-Muddatstsir (H3/29)',
                46 => 'QS. Al-Mulk – Al-Qiyamah',
                47 => 'QS. Al-Mulk – Al-Insan',
                48 => 'QS. Al-Mulk – Al-Mursalat (Tuntas Juz 29)',
            ],
            'juz28_rank' => [
                49 => 'QS. Al-Mujadilah (Juz 28 Awal)',
                50 => 'QS. Al-Mujadilah – Al-Hasyr',
                51 => 'QS. Al-Mujadilah – Al-Mumtahanah (H1/28)',
                52 => 'QS. Al-Mujadilah – As-Saff',
                53 => "QS. Al-Mujadilah – Al-Jumu'ah (H2/28)",
                54 => 'QS. Al-Mujadilah – Al-Munafiqun',
                55 => 'QS. Al-Mujadilah – At-Taghabun',
                56 => 'QS. Al-Mujadilah – At-Talaq (H3/28)',
                57 => 'QS. Al-Mujadilah – At-Tahrim (Tuntas Juz 28)',
            ],
            'juz_stage' => [
                1  => 'Tuntas Minimal Juz 30 (Stage 1)',
                2  => 'Tuntas Minimal Juz 29 (Stage 2)',
                3  => 'Tuntas Minimal Juz 1 (Stage 3)',
                4  => 'Tuntas Minimal Juz 2 (Stage 4)',
                5  => 'Tuntas Minimal Juz 3 (Stage 5)',
                6  => 'Tuntas Minimal Juz 4 (Stage 6)',
                7  => 'Tuntas Minimal Juz 5 (Stage 7)',
                8  => 'Tuntas Minimal Juz 6 (Stage 8)',
                9  => 'Tuntas Minimal Juz 7 (Stage 9)',
                10 => 'Tuntas Minimal Juz 8 (Stage 10)',
                11 => 'Tuntas Minimal Juz 9 (Stage 11)',
                12 => 'Tuntas Minimal Juz 10 (Stage 12)',
                17 => 'Tuntas Minimal Juz 15 (Stage 17)',
                30 => 'Tuntas Minimal Juz 28 (Stage 30)',
            ],
            'juz29_coverage' => [
                3  => 'Minimal 3 Surat Juz 29',
                6  => 'Minimal 6 Surat Juz 29',
                9  => 'Minimal 9 Surat Juz 29',
                11 => 'Tuntas Seluruh Juz 29 (11 Surat)',
            ],
            'juz28_coverage' => [
                3 => 'Minimal 3 Surat Juz 28',
                5 => 'Minimal 5 Surat Juz 28',
                9 => 'Tuntas Seluruh Juz 28 (9 Surat)',
            ],
            'teacher_individual' => [
                1 => 'Sesuai Target Juz Individu di Rapor AIS',
            ],
        ];
    }
}

if (!function_exists('getTahfidzTargetConfigs')) {
    /**
     * Memuat seluruh target kurikulum Tahfidz dari tabel kinerja_tahfidz_targets untuk 1 unit & semester.
     */
    function getTahfidzTargetConfigs(PDO $pdo, int $unitId, int $semester): array
    {
        $semSafe = ($semester === 2) ? 2 : 1;
        $stmt = $pdo->prepare("
            SELECT id, unit_id, paralel_id, paralel_nama, semester, target_type, target_value, target_label, require_munaqasyah, updated_at
            FROM kinerja_tahfidz_targets
            WHERE unit_id = :unit_id AND semester = :semester
            ORDER BY paralel_id ASC
        ");
        $stmt->execute([':unit_id' => $unitId, ':semester' => $semSafe]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['paralel_id']] = [
                'id'                 => (int)$row['id'],
                'unit_id'            => (int)$row['unit_id'],
                'paralel_id'         => (int)$row['paralel_id'],
                'paralel_nama'       => (string)$row['paralel_nama'],
                'semester'           => (int)$row['semester'],
                'target_type'        => (string)$row['target_type'],
                'target_value'       => (float)$row['target_value'],
                'target_label'       => (string)$row['target_label'],
                'require_munaqasyah' => (int)$row['require_munaqasyah'] === 1,
                'updated_at'         => $row['updated_at'],
            ];
        }
        return $indexed;
    }
}

if (!function_exists('evaluateStudentTahfidzTarget')) {
    /**
     * Mengevaluasi capaian seorang santri terhadap target kurikulum kelas/individu.
     */
    function evaluateStudentTahfidzTarget(array $student, ?array $targetCfg, int $depth = 0): array
    {
        $cfg = $targetCfg ?? [
            'target_type'        => 'juz30_rank',
            'target_value'       => 13.0,
            'target_label'       => 'QS. An-Nas – At-Takatsur',
            'require_munaqasyah' => false,
        ];

        $type      = (string)($cfg['target_type'] ?? 'juz30_rank');
        $targetVal = (float)($cfg['target_value'] ?? 13.0);
        $label     = (string)($cfg['target_label'] ?? '');
        $reqMunaq  = !empty($cfg['require_munaqasyah']);

        $j30      = (float)($student['juz30_rank'] ?? -1);
        $j29      = (float)($student['juz29_rank'] ?? -1);
        $j28      = (float)($student['juz28_rank'] ?? -1);
        $j29Cnt   = (int)($student['juz29_count'] ?? 0);
        $j28Cnt   = (int)($student['juz28_count'] ?? 0);
        $jStage   = (int)($student['juz_stage'] ?? 0);
        $m30      = !empty($student['has_munaqasyah_juz30']);
        $m29      = !empty($student['has_munaqasyah_juz29']);
        $m28      = !empty($student['has_munaqasyah_juz28']);
        $hasData  = ($j30 >= 0 || $j29 >= 38 || $j28 >= 49 || $jStage > 0 || $j29Cnt > 0 || $j28Cnt > 0);

        $achieved = false;
        $pct      = 0.0;
        $effLabel = $label;

        if ($type === 'teacher_individual' && $depth === 0) {
            $tType = $student['teacher_target_type'] ?? null;
            $tVal  = (float)($student['teacher_target_value'] ?? 0);
            $tLbl  = (string)($student['teacher_target_label'] ?? $student['teacher_target_raw'] ?? '');

            if (!empty($tType) && $tType !== 'teacher_individual') {
                return evaluateStudentTahfidzTarget($student, [
                    'target_type'        => $tType,
                    'target_value'       => $tVal,
                    'target_label'       => $tLbl !== '' ? $tLbl : $label,
                    'require_munaqasyah' => $reqMunaq,
                ], 1);
            }

            $effStage = (float)$jStage;
            if ($effStage <= 0 && $j29 >= 38) {
                $effStage = 2.0;
            } elseif ($effStage <= 0 && $j30 >= 37) {
                $effStage = 1.0;
            } elseif ($effStage <= 0 && $j30 > 0) {
                $effStage = $j30 / 37.0;
            }
            $minReq   = max(1.0, $targetVal);
            $achieved = ($effStage >= $minReq);
            $pct      = min(100.0, round(($effStage / $minReq) * 100.0, 1));
            $effLabel = $tLbl !== '' ? $tLbl : $label;
        } elseif ($type === 'juz30_rank') {
            $eff = max(
                0.0,
                $j30,
                $j29,
                $j28,
                ($m30 ? 37.0 : 0.0),
                ($jStage >= 2 ? 38.0 : 0.0)
            );
            if ($targetVal <= 0) {
                $achieved = $hasData;
                $pct      = $achieved ? 100.0 : 0.0;
            } else {
                $achieved = ($eff >= $targetVal);
                $pct      = min(100.0, round(($eff / $targetVal) * 100.0, 1));
            }
            if ($reqMunaq && $targetVal >= 37.0 && $achieved && !$m30 && $j29 < 38 && $jStage < 2) {
                $achieved = false;
                $pct      = min(95.0, $pct);
            }
        } elseif ($type === 'juz29_rank') {
            $eff = max(
                0.0,
                $j30,
                $j29,
                $j28,
                ($m29 ? 48.0 : ($m30 ? 37.0 : 0.0)),
                ($jStage >= 3 ? (48.0 + ($jStage - 2)) : ($jStage === 2 ? 38.0 : 0.0))
            );
            $den      = max(38.0, $targetVal);
            $achieved = ($eff >= $targetVal);
            $pct      = min(100.0, round(($eff / $den) * 100.0, 1));
            if ($reqMunaq && $targetVal >= 48.0 && $achieved && !$m29 && $jStage < 3) {
                $achieved = false;
                $pct      = min(95.0, $pct);
            }
        } elseif ($type === 'juz28_rank') {
            $eff = max(
                0.0,
                $j30,
                $j29,
                $j28,
                ($m28 ? 57.0 : ($m29 ? 48.0 : ($m30 ? 37.0 : 0.0))),
                ($jStage >= 30 ? 57.0 : 0.0)
            );
            $den      = max(49.0, $targetVal);
            $achieved = ($eff >= $targetVal);
            $pct      = min(100.0, round(($eff / $den) * 100.0, 1));
            if ($reqMunaq && $targetVal >= 57.0 && $achieved && !$m28) {
                $achieved = false;
                $pct      = min(95.0, $pct);
            }
        } elseif ($type === 'juz29_coverage') {
            $den = max(1.0, $targetVal);
            if ($m29 || $jStage >= 3) {
                $achieved = true;
                $pct      = 100.0;
            } else {
                $achieved = ($j29Cnt >= (int)ceil($den));
                $pct      = $j29Cnt > 0
                    ? min(100.0, round(($j29Cnt / $den) * 100.0, 1))
                    : ($j30 > 0 ? round(($j30 / 37.0) * 45.0, 1) : 0.0);
            }
        } elseif ($type === 'juz28_coverage') {
            $den = max(1.0, $targetVal);
            if ($m28 || $jStage >= 30) {
                $achieved = true;
                $pct      = 100.0;
            } else {
                $achieved = ($j28Cnt >= (int)ceil($den));
                $pct      = $j28Cnt > 0
                    ? min(100.0, round(($j28Cnt / $den) * 100.0, 1))
                    : 0.0;
            }
        } elseif ($type === 'juz_stage') {
            $effStage = (float)$jStage;
            if ($j28 >= 57) {
                $effStage = max($effStage, 30.0);
            } elseif ($j29 >= 48) {
                $effStage = max($effStage, 2.0);
            } elseif ($j29 >= 38) {
                $effStage = max($effStage, 1.0 + (($j29 - 37.0) / 11.0));
            } elseif ($j30 > 0 && $effStage < 1.0) {
                $effStage = $j30 / 37.0;
            }
            $den      = max(1.0, $targetVal);
            $achieved = ($effStage >= $den);
            $pct      = min(100.0, round(($effStage / $den) * 100.0, 1));
        }

        if ($achieved) {
            $pct = 100.0;
        }

        $statusLabel = 'Belum Ada Data';
        $statusKey   = 'empty';
        if ($achieved) {
            $statusLabel = 'Tuntas Target';
            $statusKey   = 'achieved';
        } elseif ($pct >= 75.0) {
            $statusLabel = 'Hampir Tuntas';
            $statusKey   = 'near';
        } elseif ($hasData) {
            $statusLabel = 'Berproses';
            $statusKey   = 'progress';
        }

        return [
            'is_achieved'    => $achieved,
            'progress_pct'   => round($pct, 1),
            'target_label'   => $effLabel,
            'has_data'       => $hasData,
            'status_key'     => $statusKey,
            'status_label'   => $statusLabel,
        ];
    }
}

if (!function_exists('calculateTahfidzHierarchy')) {
    /**
     * Menghitung agregasi Capaian Tahfidz 3 tingkat (Unit -> Paralel -> Rombel urut tertinggi)
     * sekaligus statistik Kualitas Anak Binaan per Pengajar Halaqah untuk komparasi Tab Jurnal.
     */
    function calculateTahfidzHierarchy(array $apiData, array $targetConfigs, string $sortMode = 'grade_asc'): array
    {
        $students    = is_array($apiData['students'] ?? null) ? $apiData['students'] : [];
        $metaParalels = is_array($apiData['meta']['paralels'] ?? null) ? $apiData['meta']['paralels'] : [];

        $paralelsMap  = [];
        $teacherStats = [];

        foreach ($metaParalels as $mp) {
            $pId = (int)($mp['paralel_id'] ?? 0);
            if ($pId <= 0) {
                continue;
            }
            $cfg = $targetConfigs[$pId] ?? null;
            $paralelsMap[$pId] = [
                'paralel_id'        => $pId,
                'paralel_nama'      => (string)($mp['paralel_nama'] ?? "Level {$pId}"),
                'target_config'     => $cfg,
                'target_label'      => (string)($cfg['target_label'] ?? 'Sesuai Rapor / Default'),
                'target_type'       => (string)($cfg['target_type'] ?? 'juz30_rank'),
                'total_students'    => 0,
                'achieved_students' => 0,
                'sum_progress_pct'  => 0.0,
                'percentage'        => 0.0,
                'avg_progress_pct'  => 0.0,
                'rombels'           => [],
            ];
        }

        foreach ($students as $stu) {
            $pId    = (int)($stu['paralel_id'] ?? 0);
            $rId    = (int)($stu['rombel_id'] ?? 0);
            $pNama  = (string)($stu['paralel_nama'] ?? "Level {$pId}");
            $rCode  = trim((string)($stu['rombel_code'] ?? ''));
            $rNama  = trim((string)($stu['rombel_nama'] ?? ($rCode !== '' ? $rCode : 'Tanpa Rombel')));
            $targetCfg = $targetConfigs[$pId] ?? null;

            $eval = evaluateStudentTahfidzTarget($stu, $targetCfg);

            $evaluatedStu = [
                'siswa_id'          => (int)($stu['siswa_id'] ?? 0),
                'nama'              => (string)($stu['nama'] ?? '-'),
                'paralel_id'        => $pId,
                'paralel_nama'      => $pNama,
                'rombel_id'         => $rId,
                'rombel_code'       => $rCode !== '' ? $rCode : $rNama,
                'rombel_nama'       => $rNama,
                'halaqah_id'        => (int)($stu['halaqah_id'] ?? 0),
                'pengajar_id'       => (int)($stu['pengajar_id'] ?? 0),
                'pengajar_nik'      => (string)($stu['pengajar_nik'] ?? ''),
                'pengajar_nama'     => (string)($stu['pengajar_nama'] ?? '-'),
                'data_source'       => (string)($stu['data_source'] ?? 'none'),
                'actual_display'    => (string)($stu['actual_display'] ?? 'Belum ada catatan hafalan'),
                'juz30_rank'        => (float)($stu['juz30_rank'] ?? -1),
                'juz29_rank'        => (float)($stu['juz29_rank'] ?? -1),
                'juz28_rank'        => (float)($stu['juz28_rank'] ?? -1),
                'juz_stage'         => (int)($stu['juz_stage'] ?? 0),
                'is_achieved'       => $eval['is_achieved'],
                'progress_pct'      => $eval['progress_pct'],
                'target_label'      => $eval['target_label'],
                'status_key'        => $eval['status_key'],
                'status_label'      => $eval['status_label'],
                'ai_badge'          => (string)($stu['ai_badge'] ?? ''),
                'ai_evidence'       => $stu['ai_evidence'] ?? null,
                'manual_override'   => $stu['manual_override'] ?? null,
            ];

            if (!isset($paralelsMap[$pId])) {
                $paralelsMap[$pId] = [
                    'paralel_id'        => $pId,
                    'paralel_nama'      => $pNama,
                    'target_config'     => $targetCfg,
                    'target_label'      => (string)($targetCfg['target_label'] ?? $eval['target_label']),
                    'target_type'       => (string)($targetCfg['target_type'] ?? 'juz30_rank'),
                    'total_students'    => 0,
                    'achieved_students' => 0,
                    'sum_progress_pct'  => 0.0,
                    'percentage'        => 0.0,
                    'avg_progress_pct'  => 0.0,
                    'rombels'           => [],
                ];
            }

            $paralelsMap[$pId]['total_students']++;
            if ($eval['is_achieved']) {
                $paralelsMap[$pId]['achieved_students']++;
            }
            $paralelsMap[$pId]['sum_progress_pct'] += $eval['progress_pct'];

            $rKey = $rId > 0 ? (string)$rId : ('name_' . strtolower($rCode ?: $rNama));
            if (!isset($paralelsMap[$pId]['rombels'][$rKey])) {
                $paralelsMap[$pId]['rombels'][$rKey] = [
                    'rombel_id'         => $rId,
                    'rombel_code'       => $rCode !== '' ? $rCode : $rNama,
                    'rombel_nama'       => $rNama,
                    'total_students'    => 0,
                    'achieved_students' => 0,
                    'sum_progress_pct'  => 0.0,
                    'percentage'        => 0.0,
                    'avg_progress_pct'  => 0.0,
                    'students'          => [],
                ];
            }
            $paralelsMap[$pId]['rombels'][$rKey]['total_students']++;
            if ($eval['is_achieved']) {
                $paralelsMap[$pId]['rombels'][$rKey]['achieved_students']++;
            }
            $paralelsMap[$pId]['rombels'][$rKey]['sum_progress_pct'] += $eval['progress_pct'];
            $paralelsMap[$pId]['rombels'][$rKey]['students'][] = $evaluatedStu;

            // Agregasi per Pengajar Halaqah (Tab JUMLAH ISI JURNAL)
            $gId = (int)($stu['pengajar_id'] ?? 0);
            if ($gId > 0) {
                if (!isset($teacherStats[$gId])) {
                    $cleanNik = preg_replace('/[^a-zA-Z0-9]/', '', (string)($stu['pengajar_nik'] ?? '')) ?? '';
                    $teacherStats[$gId] = [
                        'guru_id'           => $gId,
                        'clean_nik'         => $cleanNik,
                        'guru_nama'         => (string)($stu['pengajar_nama'] ?? '-'),
                        'total_students'    => 0,
                        'achieved_students' => 0,
                        'sum_progress_pct'  => 0.0,
                        'percentage'        => 0.0,
                        'avg_progress_pct'  => 0.0,
                        'students'          => [],
                    ];
                }
                $teacherStats[$gId]['total_students']++;
                if ($eval['is_achieved']) {
                    $teacherStats[$gId]['achieved_students']++;
                }
                $teacherStats[$gId]['sum_progress_pct'] += $eval['progress_pct'];
                $teacherStats[$gId]['students'][] = $evaluatedStu;
            }
        }

        // Hitung persentase & urutkan Rombel tertinggi -> terendah di dalam tiap Paralel
        $unitTotal      = 0;
        $unitAchieved   = 0;
        $unitSumProg    = 0.0;
        $allRombelFlat  = [];

        foreach ($paralelsMap as $pId => &$pItem) {
            $tot = $pItem['total_students'];
            $pItem['percentage']       = $tot > 0 ? round(($pItem['achieved_students'] / $tot) * 100.0, 1) : 0.0;
            $pItem['avg_progress_pct'] = $tot > 0 ? round($pItem['sum_progress_pct'] / $tot, 1) : 0.0;

            $unitTotal    += $tot;
            $unitAchieved += $pItem['achieved_students'];
            $unitSumProg  += $pItem['sum_progress_pct'];

            $rombelsArr = array_values($pItem['rombels']);
            foreach ($rombelsArr as &$rItem) {
                $rTot = $rItem['total_students'];
                $rItem['percentage']       = $rTot > 0 ? round(($rItem['achieved_students'] / $rTot) * 100.0, 1) : 0.0;
                $rItem['avg_progress_pct'] = $rTot > 0 ? round($rItem['sum_progress_pct'] / $rTot, 1) : 0.0;

                usort($rItem['students'], static function ($a, $b) {
                    if ($a['is_achieved'] !== $b['is_achieved']) {
                        return $b['is_achieved'] <=> $a['is_achieved'];
                    }
                    if (abs($b['progress_pct'] - $a['progress_pct']) > 0.05) {
                        return $b['progress_pct'] <=> $a['progress_pct'];
                    }
                    return strcmp($a['nama'], $b['nama']);
                });

                $allRombelFlat[] = [
                    'paralel_nama' => $pItem['paralel_nama'],
                    'rombel_code'  => $rItem['rombel_code'],
                    'rombel_nama'  => $rItem['rombel_nama'],
                    'percentage'   => $rItem['percentage'],
                    'avg_progress' => $rItem['avg_progress_pct'],
                    'achieved'     => $rItem['achieved_students'],
                    'total'        => $rItem['total_students'],
                ];
            }
            unset($rItem);

            usort($rombelsArr, static function ($a, $b) {
                if (abs($b['percentage'] - $a['percentage']) > 0.05) {
                    return $b['percentage'] <=> $a['percentage'];
                }
                if (abs($b['avg_progress_pct'] - $a['avg_progress_pct']) > 0.05) {
                    return $b['avg_progress_pct'] <=> $a['avg_progress_pct'];
                }
                if ($b['total_students'] !== $a['total_students']) {
                    return $b['total_students'] <=> $a['total_students'];
                }
                return strcmp($a['rombel_code'], $b['rombel_code']);
            });

            $pItem['rombels'] = $rombelsArr;
        }
        unset($pItem);

        // Finalisasi persentase per guru
        foreach ($teacherStats as &$ts) {
            $tTot = $ts['total_students'];
            $ts['percentage']       = $tTot > 0 ? round(($ts['achieved_students'] / $tTot) * 100.0, 1) : 0.0;
            $ts['avg_progress_pct'] = $tTot > 0 ? round($ts['sum_progress_pct'] / $tTot, 1) : 0.0;
            usort($ts['students'], static function ($a, $b) {
                if ($a['is_achieved'] !== $b['is_achieved']) {
                    return $a['is_achieved'] <=> $b['is_achieved']; // Belum tuntas tampil lebih dulu agar supervisi mudah
                }
                if (abs($a['progress_pct'] - $b['progress_pct']) > 0.05) {
                    return $a['progress_pct'] <=> $b['progress_pct'];
                }
                return strcmp($a['nama'], $b['nama']);
            });
        }
        unset($ts);

        $paralelsList = array_values(array_filter($paralelsMap, static fn($p) => $p['total_students'] > 0));
        if ($sortMode === 'highest') {
            usort($paralelsList, static function ($a, $b) {
                if (abs($b['percentage'] - $a['percentage']) > 0.05) {
                    return $b['percentage'] <=> $a['percentage'];
                }
                return $b['avg_progress_pct'] <=> $a['avg_progress_pct'];
            });
        } elseif ($sortMode === 'lowest') {
            usort($paralelsList, static function ($a, $b) {
                if (abs($a['percentage'] - $b['percentage']) > 0.05) {
                    return $a['percentage'] <=> $b['percentage'];
                }
                return $a['avg_progress_pct'] <=> $b['avg_progress_pct'];
            });
        } else {
            usort($paralelsList, static fn($a, $b) => $a['paralel_id'] <=> $b['paralel_id']);
        }

        $unitPct     = $unitTotal > 0 ? round(($unitAchieved / $unitTotal) * 100.0, 1) : 0.0;
        $unitAvgProg = $unitTotal > 0 ? round($unitSumProg / $unitTotal, 1) : 0.0;

        $sortedByBestParalel = $paralelsList;
        usort($sortedByBestParalel, static function ($a, $b) {
            if (abs($b['percentage'] - $a['percentage']) > 0.05) {
                return $b['percentage'] <=> $a['percentage'];
            }
            return $b['avg_progress_pct'] <=> $a['avg_progress_pct'];
        });

        usort($allRombelFlat, static function ($a, $b) {
            if (abs($b['percentage'] - $a['percentage']) > 0.05) {
                return $b['percentage'] <=> $a['percentage'];
            }
            return $b['avg_progress'] <=> $a['avg_progress'];
        });

        return [
            'unit_summary' => [
                'total_students'     => $unitTotal,
                'achieved_students'  => $unitAchieved,
                'percentage'         => $unitPct,
                'avg_progress_pct'   => $unitAvgProg,
                'total_paralels'     => count($paralelsList),
                'total_rombels'      => count($allRombelFlat),
                'best_paralel'       => $sortedByBestParalel[0] ?? null,
                'best_rombel'        => $allRombelFlat[0] ?? null,
                'lowest_rombel'      => !empty($allRombelFlat) ? $allRombelFlat[count($allRombelFlat) - 1] : null,
            ],
            'paralels'      => $paralelsList,
            'teacher_stats' => $teacherStats,
        ];
    }
}

if (!function_exists('normalizeTeacherPersonName')) {
    /**
     * Normalisasi nama guru (buang gelar umum & tanda baca) sebagai fallback pencocokan bila NIK kosong.
     */
    function normalizeTeacherPersonName(string $name): string
    {
        $s = strtolower($name);
        $s = preg_replace('/\b(ustadz|ustadzah|ust|s\.pd\.i?|s\.ag|s\.si|s\.hum|m\.pd\.i?|lc\.?|al[\s-]?hafizh|h\.|hj\.)\b/i', ' ', $s) ?? $s;
        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }
}

if (!function_exists('mergeTeacherAttendanceWithJournals')) {
    /**
     * Menggabungkan statistik jurnal guru Qur'an dari AIS dengan rekap kehadiran riil staff_attendances lokal,
     * serta mengaitkan Kualitas Santri Binaan untuk menghasilkan dataset tab "JUMLAH ISI JURNAL".
     */
    function mergeTeacherAttendanceWithJournals(
        PDO $pdo,
        array $guruJournals,
        array $teacherStudentStats,
        string $startDate,
        string $endDate,
        int $month = 0,
        int $hariEfektifUnit = 0
    ): array {
        // 1. Tentukan rentang tanggal absensi yang sinkron dengan periode semester / bulan terpilih
        $attStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? $startDate : date('Y-01-01');
        $attEnd   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ? $endDate : date('Y-m-d');

        if ($month >= 1 && $month <= 12) {
            $sYear = (int)substr($attStart, 0, 4);
            $eYear = (int)substr($attEnd, 0, 4);
            $targetYear = ($month >= 7) ? $sYear : $eYear;
            for ($yr = $sYear; $yr <= $eYear; $yr++) {
                $probe = sprintf('%04d-%02d-15', $yr, $month);
                if ($probe >= $attStart && $probe <= $attEnd) {
                    $targetYear = $yr;
                    break;
                }
            }
            $attStart = sprintf('%04d-%02d-01', $targetYear, $month);
            $attEnd   = date('Y-m-t', strtotime($attStart));
        }

        // 2. Muat semua staff lokal dalam 1 query agar zero N+1
        $staffRows = $pdo->query("
            SELECT id, name, nik, unit_id
            FROM staff
            WHERE deleted_at IS NULL
        ")->fetchAll(PDO::FETCH_ASSOC);

        $staffByCleanNik = [];
        $staffByNormName = [];
        foreach ($staffRows as $sr) {
            $cNik = preg_replace('/[^a-zA-Z0-9]/', '', (string)($sr['nik'] ?? '')) ?? '';
            if ($cNik !== '') {
                $staffByCleanNik[$cNik] = $sr;
            }
            $nName = normalizeTeacherPersonName((string)($sr['name'] ?? ''));
            if ($nName !== '') {
                $staffByNormName[$nName] = $sr;
            }
        }

        // Index teacherStudentStats juga berdasar clean_nik sebagai fallback
        $studentStatsByCleanNik = [];
        foreach ($teacherStudentStats as $gIdKey => $ts) {
            if (!empty($ts['clean_nik'])) {
                $studentStatsByCleanNik[$ts['clean_nik']] = $ts;
            }
        }

        // Cocokkan staff_id untuk seluruh guru AIS
        $matchedStaffMap = [];
        $staffIdsToQuery = [];
        foreach ($guruJournals as $gj) {
            $gId      = (int)($gj['guru_id'] ?? 0);
            $cleanNik = trim((string)($gj['clean_nik'] ?? ''));
            if ($cleanNik === '' && !empty($gj['guru_nik'])) {
                $cleanNik = preg_replace('/[^a-zA-Z0-9]/', '', (string)$gj['guru_nik']) ?? '';
            }
            $normName = normalizeTeacherPersonName((string)($gj['guru_nama'] ?? ''));

            $matchedStaff = null;
            $matchMethod  = 'unmatched';
            if ($cleanNik !== '' && isset($staffByCleanNik[$cleanNik])) {
                $matchedStaff = $staffByCleanNik[$cleanNik];
                $matchMethod  = 'nik';
            } elseif ($normName !== '' && isset($staffByNormName[$normName])) {
                $matchedStaff = $staffByNormName[$normName];
                $matchMethod  = 'name';
            }

            $matchedStaffMap[$gId] = [
                'staff'  => $matchedStaff,
                'method' => $matchMethod,
            ];
            if ($matchedStaff !== null) {
                $staffIdsToQuery[] = (int)$matchedStaff['id'];
            }
        }

        // 3. Batch Query kehadiran riil staff_attendances pada rentang tanggal terpilih
        $attByStaffId = [];
        $staffIdsToQuery = array_values(array_unique($staffIdsToQuery));
        if (!empty($staffIdsToQuery)) {
            $placeholders = implode(',', array_fill(0, count($staffIdsToQuery), '?'));
            $stmtAtt = $pdo->prepare("
                SELECT
                    sa.staff_id,
                    COUNT(DISTINCT CASE WHEN sa.status IN ('tepat_waktu', 'terlambat') THEN sa.attendance_date END) AS hari_masuk,
                    COUNT(DISTINCT CASE WHEN sa.status = 'tepat_waktu' THEN sa.attendance_date END) AS hari_tepat_waktu,
                    COUNT(DISTINCT CASE WHEN sa.status = 'terlambat' THEN sa.attendance_date END) AS hari_terlambat,
                    COUNT(DISTINCT CASE WHEN sa.status = 'izin' THEN sa.attendance_date END) AS hari_izin,
                    COUNT(DISTINCT CASE WHEN sa.status = 'sakit' THEN sa.attendance_date END) AS hari_sakit,
                    COUNT(DISTINCT CASE WHEN sa.status = 'alpha' THEN sa.attendance_date END) AS hari_alpha
                FROM staff_attendances sa
                WHERE sa.staff_id IN ({$placeholders})
                  AND sa.attendance_date BETWEEN ? AND ?
                GROUP BY sa.staff_id
            ");
            $params = array_merge($staffIdsToQuery, [$attStart, $attEnd]);
            $stmtAtt->execute($params);
            foreach ($stmtAtt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                $attByStaffId[(int)$ar['staff_id']] = $ar;
            }
        }

        // Cek apakah sistem absensi-anak secara umum sudah punya data di rentang tanggal ini
        $stmtGlobalAtt = $pdo->prepare("
            SELECT COUNT(*) FROM staff_attendances WHERE attendance_date BETWEEN ? AND ?
        ");
        $stmtGlobalAtt->execute([$attStart, $attEnd]);
        $globalPeriodAttCount = (int)$stmtGlobalAtt->fetchColumn();

        // 4. Gabungkan menjadi baris akhir Indikator Kerja Guru Qur'an
        $rows                 = [];
        $sumHariMasuk         = 0;
        $countGuruWithMasuk   = 0;
        $sumJurnalPct         = 0.0;
        $countDisiplin85      = 0;
        $countPerluPembinaan  = 0;
        $countKinerjaPrima    = 0;
        $disciplinedQualities = [];
        $otherQualities       = [];

        foreach ($guruJournals as $gj) {
            $gId       = (int)($gj['guru_id'] ?? 0);
            $cleanNik  = (string)($gj['clean_nik'] ?? '');
            $matchInfo = $matchedStaffMap[$gId] ?? ['staff' => null, 'method' => 'unmatched'];
            $staff     = $matchInfo['staff'];
            $staffId   = $staff !== null ? (int)$staff['id'] : 0;
            $att       = ($staffId > 0 && isset($attByStaffId[$staffId])) ? $attByStaffId[$staffId] : null;

            $hariMasuk   = $att ? (int)$att['hari_masuk'] : 0;
            $tepatWaktu  = $att ? (int)$att['hari_tepat_waktu'] : 0;
            $terlambat   = $att ? (int)$att['hari_terlambat'] : 0;
            $izin        = $att ? (int)$att['hari_izin'] : 0;
            $sakit       = $att ? (int)$att['hari_sakit'] : 0;
            $alpha       = $att ? (int)$att['hari_alpha'] : 0;

            $hariIsiJurnal = (int)($gj['hari_isi_jurnal'] ?? 0);
            $sesiTerisi    = (int)($gj['sesi_jurnal_terisi'] ?? 0);
            $totalEntri    = (int)($gj['total_entri_jurnal'] ?? 0);
            $hariNilai     = (int)($gj['hari_input_nilai'] ?? 0);
            $jmlHalaqah    = max(1, (int)($gj['jml_halaqah'] ?? 1));

            $hasAttendance = ($hariMasuk > 0);
            $jurnalPct     = $hasAttendance
                ? min(100.0, round(($hariIsiJurnal / $hariMasuk) * 100.0, 1))
                : null;
            $fallbackPct   = $hariEfektifUnit > 0
                ? min(100.0, round(($hariIsiJurnal / $hariEfektifUnit) * 100.0, 1))
                : 0.0;

            $sesiBasis    = $hasAttendance ? $hariMasuk : $hariEfektifUnit;
            $sesiExpected = $sesiBasis * $jmlHalaqah;
            $sesiPct      = $sesiExpected > 0
                ? min(100.0, round(($sesiTerisi / $sesiExpected) * 100.0, 1))
                : 0.0;

            $qStat = $teacherStudentStats[$gId] ?? ($cleanNik !== '' ? ($studentStatsByCleanNik[$cleanNik] ?? null) : null);
            $binaanTotal   = $qStat ? (int)$qStat['total_students'] : (int)($gj['total_santri'] ?? 0);
            $binaanTuntas  = $qStat ? (int)$qStat['achieved_students'] : 0;
            $binaanPct     = $qStat ? (float)$qStat['percentage'] : 0.0;
            $binaanAvgProg = $qStat ? (float)$qStat['avg_progress_pct'] : 0.0;
            $binaanStudents = $qStat ? $qStat['students'] : [];

            // Penentuan Kuadran Indikator Kerja Guru
            if ($binaanTotal === 0) {
                $qKey   = 'no_students';
                $qLabel = 'Belum Ada Santri';
                $qBadge = 'slate';
                $qDesc  = 'Belum ada roster santri halaqah aktif di periode ini.';
            } elseif (!$hasAttendance) {
                $qKey   = 'no_attendance';
                $qLabel = 'Belum Ada Data Absen';
                $qBadge = 'slate';
                $qDesc  = 'Belum ada catatan kehadiran di sistem absensi pada rentang ini; cek rasio pembanding Hari Efektif Unit.';
            } elseif ($jurnalPct >= 85.0 && $binaanPct >= 75.0) {
                $qKey   = 'prima';
                $qLabel = 'Kinerja Prima';
                $qBadge = 'emerald';
                $qDesc  = 'Disiplin jurnal sangat baik & mayoritas santri binaan tuntas target.';
                $countKinerjaPrima++;
            } elseif ($jurnalPct >= 85.0 && $binaanPct < 75.0) {
                $qKey   = 'perlu_metode';
                $qLabel = 'Tertib / Perlu Penguatan Metode';
                $qBadge = 'blue';
                $qDesc  = 'Guru tertib mengisi jurnal, namun capaian santri binaan masih di bawah 75% (cek pendampingan/kelas remedial).';
            } elseif ($jurnalPct >= 60.0 && $binaanPct >= 75.0) {
                $qKey   = 'baik_tingkatkan_jurnal';
                $qLabel = 'Hasil Baik • Tingkatkan Jurnal';
                $qBadge = 'teal';
                $qDesc  = 'Capaian anak sudah ≥75%, kedisiplinan pengisian jurnal perlu ditingkatkan ke ≥85%.';
            } elseif ($jurnalPct >= 60.0 && $binaanPct < 75.0) {
                $qKey   = 'pantau_berkala';
                $qLabel = 'Cukup • Pantau Berkala';
                $qBadge = 'amber';
                $qDesc  = 'Kedisiplinan jurnal maupun ketuntasan santri masih di zona menengah.';
            } elseif ($jurnalPct < 60.0 && $binaanPct >= 75.0) {
                $qKey   = 'penertiban_jurnal';
                $qLabel = 'Perlu Penertiban Jurnal';
                $qBadge = 'amber';
                $qDesc  = 'Kualitas anak baik, namun guru kurang tertib mencatat jurnal mengajar (<60%).';
            } else {
                $qKey   = 'prioritas_supervisi';
                $qLabel = 'Prioritas Supervisi';
                $qBadge = 'rose';
                $qDesc  = 'Pengisian jurnal rendah (<60%) sekaligus ketuntasan santri rendah (<75%).';
            }

            if ($hasAttendance) {
                $sumHariMasuk += $hariMasuk;
                $sumJurnalPct += (float)$jurnalPct;
                $countGuruWithMasuk++;
                if ($jurnalPct >= 85.0) {
                    $countDisiplin85++;
                    if ($binaanTotal > 0) {
                        $disciplinedQualities[] = $binaanPct;
                    }
                } else {
                    if ($jurnalPct < 60.0) {
                        $countPerluPembinaan++;
                    }
                    if ($binaanTotal > 0) {
                        $otherQualities[] = $binaanPct;
                    }
                }
            } else {
                if ($fallbackPct >= 85.0 && $binaanTotal > 0) {
                    $disciplinedQualities[] = $binaanPct;
                } elseif ($binaanTotal > 0) {
                    $otherQualities[] = $binaanPct;
                }
            }

            $rows[] = [
                'guru_id'             => $gId,
                'guru_nama'           => (string)($gj['guru_nama'] ?? '-'),
                'guru_nik'            => (string)($gj['guru_nik'] ?? ''),
                'clean_nik'           => $cleanNik,
                'staff_matched'       => ($staff !== null),
                'match_method'        => $matchInfo['method'],
                'staff_nik'           => $staff ? (string)($staff['nik'] ?? '') : '',
                'jml_halaqah'         => $jmlHalaqah,
                'level_list'          => (string)($gj['level_list'] ?? '-'),
                'hari_masuk'          => $hariMasuk,
                'hari_tepat_waktu'    => $tepatWaktu,
                'hari_terlambat'      => $terlambat,
                'hari_izin'           => $izin,
                'hari_sakit'          => $sakit,
                'hari_alpha'          => $alpha,
                'has_attendance'      => $hasAttendance,
                'hari_isi_jurnal'     => $hariIsiJurnal,
                'sesi_jurnal_terisi'  => $sesiTerisi,
                'sesi_expected'       => $sesiExpected,
                'sesi_pct'            => $sesiPct,
                'total_entri_jurnal'  => $totalEntri,
                'hari_input_nilai'    => $hariNilai,
                'tgl_pertama'         => $gj['tgl_pertama'] ?? null,
                'tgl_terakhir'        => $gj['tgl_terakhir'] ?? null,
                'jurnal_pct'          => $jurnalPct,
                'fallback_jurnal_pct' => $fallbackPct,
                'binaan_total'        => $binaanTotal,
                'binaan_tuntas'       => $binaanTuntas,
                'binaan_pct'          => $binaanPct,
                'binaan_avg_progress' => $binaanAvgProg,
                'binaan_students'     => $binaanStudents,
                'quadrant_key'        => $qKey,
                'quadrant_label'      => $qLabel,
                'quadrant_badge'      => $qBadge,
                'quadrant_desc'       => $qDesc,
            ];
        }

        $avgQualityDisiplin = !empty($disciplinedQualities)
            ? round(array_sum($disciplinedQualities) / count($disciplinedQualities), 1)
            : null;
        $avgQualityOther = !empty($otherQualities)
            ? round(array_sum($otherQualities) / count($otherQualities), 1)
            : null;

        return [
            'period_info' => [
                'att_start'               => $attStart,
                'att_end'                 => $attEnd,
                'hari_efektif_unit'       => $hariEfektifUnit,
                'has_any_attendance_data' => ($globalPeriodAttCount > 0 && $countGuruWithMasuk > 0),
                'global_period_att_rows'  => $globalPeriodAttCount,
            ],
            'summary' => [
                'total_guru'              => count($rows),
                'guru_with_attendance'    => $countGuruWithMasuk,
                'avg_hari_masuk'          => $countGuruWithMasuk > 0 ? round($sumHariMasuk / $countGuruWithMasuk, 1) : 0.0,
                'avg_jurnal_pct'          => $countGuruWithMasuk > 0 ? round($sumJurnalPct / $countGuruWithMasuk, 1) : null,
                'count_disiplin_85'       => $countDisiplin85,
                'count_perlu_pembinaan'   => $countPerluPembinaan,
                'count_kinerja_prima'     => $countKinerjaPrima,
                'avg_quality_disciplined' => $avgQualityDisiplin,
                'avg_quality_other'       => $avgQualityOther,
            ],
            'rows' => $rows,
        ];
    }
}

// =========================================================================
// TAHFIDZ AI SEMANTIC CACHE ENGINE (HYBRID DETERMINISTIC + LLM CLASSIFIER)
// =========================================================================

if (!function_exists('isAllowedAiEndpointUrl')) {
    /**
     * Anti-SSRF whitelist ketat: hanya mengizinkan endpoint API resmi Gemini/OpenAI HTTPS.
     */
    function isAllowedAiEndpointUrl(string $url): bool
    {
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');
        return $scheme === 'https' && in_array($host, [
            'generativelanguage.googleapis.com',
            'api.openai.com',
        ], true);
    }
}

if (!function_exists('getTahfidzAiSurahDictionary')) {
    /**
     * Kamus baku surat & rank resmi An-Nahl untuk mengonversi hasil ekstraksi AI secara deterministik.
     */
    function getTahfidzAiSurahDictionary(): array
    {
        $catalog = getTahfidzSurahCatalog();
        $juz30Ayats = [
            0 => 7, 1 => 6, 2 => 5, 3 => 4, 4 => 5, 5 => 3, 6 => 6, 7 => 3, 8 => 7, 9 => 4,
            10 => 5, 11 => 9, 12 => 3, 13 => 8, 14 => 11, 15 => 11, 16 => 8, 17 => 8, 18 => 5, 19 => 19,
            20 => 8, 21 => 8, 22 => 11, 23 => 21, 24 => 15, 25 => 20, 26 => 30, 27 => 26, 28 => 19, 29 => 17,
            30 => 22, 31 => 25, 32 => 36, 33 => 19, 34 => 29, 35 => 42, 36 => 46, 37 => 40,
        ];
        $juz30Keys = [
            0 => 'alfatihah', 1 => 'annas', 2 => 'alfalaq', 3 => 'alikhlas', 4 => 'allahab', 5 => 'annashr',
            6 => 'alkafirun', 7 => 'alkautsar', 8 => 'almaun', 9 => 'quraisy', 10 => 'alfil', 11 => 'alhumazah',
            12 => 'alashr', 13 => 'attakatsur', 14 => 'alqariah', 15 => 'aladiyat', 16 => 'azzalzalah', 17 => 'albayyinah',
            18 => 'alqadr', 19 => 'alalaq', 20 => 'attin', 21 => 'alinsyirah', 22 => 'adhdhuha', 23 => 'allail',
            24 => 'asysyams', 25 => 'albalad', 26 => 'alfajr', 27 => 'alghasyiyah', 28 => 'alalaa', 29 => 'atthariq',
            30 => 'alburuj', 31 => 'alinsyiqaq', 32 => 'almuthaffifin', 33 => 'alinfithar', 34 => 'attakwir', 35 => 'abasa',
            36 => 'annaziat', 37 => 'annaba',
        ];
        $juz29Ayats = [
            38 => ['key' => 'almulk', 'ayats' => 30],
            39 => ['key' => 'alqalam', 'ayats' => 52],
            40 => ['key' => 'alhaqqah', 'ayats' => 52],
            41 => ['key' => 'almaarij', 'ayats' => 44],
            42 => ['key' => 'nuh', 'ayats' => 28],
            43 => ['key' => 'aljin', 'ayats' => 28],
            44 => ['key' => 'almuzzammil', 'ayats' => 20],
            45 => ['key' => 'almuddatstsir', 'ayats' => 56],
            46 => ['key' => 'alqiyamah', 'ayats' => 40],
            47 => ['key' => 'alinsan', 'ayats' => 31],
            48 => ['key' => 'almursalat', 'ayats' => 50],
        ];
        $juz28Ayats = [
            49 => ['key' => 'almujadilah', 'ayats' => 22],
            50 => ['key' => 'alhasyr', 'ayats' => 24],
            51 => ['key' => 'almumtahanah', 'ayats' => 13],
            52 => ['key' => 'assaff', 'ayats' => 14],
            53 => ['key' => 'aljumuah', 'ayats' => 11],
            54 => ['key' => 'almunafiqun', 'ayats' => 11],
            55 => ['key' => 'attaghabun', 'ayats' => 18],
            56 => ['key' => 'attalaq', 'ayats' => 12],
            57 => ['key' => 'attahrim', 'ayats' => 12],
        ];

        $surahMap = [];
        foreach ($juz30Keys as $rk => $key) {
            $surahMap[$key] = [
                'juz'   => 30,
                'rank'  => $rk,
                'ayats' => $juz30Ayats[$rk],
                'label' => $catalog['juz30_rank'][$rk] ?? ucfirst($key),
            ];
        }
        foreach ($juz29Ayats as $rk => $meta) {
            $surahMap[$meta['key']] = [
                'juz'   => 29,
                'rank'  => $rk,
                'ayats' => $meta['ayats'],
                'label' => $catalog['juz29_rank'][$rk] ?? ucfirst($meta['key']),
            ];
        }
        foreach ($juz28Ayats as $rk => $meta) {
            $surahMap[$meta['key']] = [
                'juz'   => 28,
                'rank'  => $rk,
                'ayats' => $meta['ayats'],
                'label' => $catalog['juz28_rank'][$rk] ?? ucfirst($meta['key']),
            ];
        }

        // Alias umum yang mungkin dikembalikan LLM
        $aliases = [
            'annaas' => 'annas', 'nas' => 'annas', 'naas' => 'annas',
            'alqoriah' => 'alqariah', 'aladiyat' => 'aladiyat',
            'alzalzalah' => 'azzalzalah', 'atthariq' => 'atthariq', 'attariq' => 'atthariq',
            'abasa' => 'abasa', 'annaba' => 'annaba', 'annaziat' => 'annaziat',
            'almulk' => 'almulk', 'alqolam' => 'alqalam', 'aljinn' => 'aljin',
            'almuzammil' => 'almuzzammil', 'almuddassir' => 'almuddatstsir', 'almudatsir' => 'almuddatstsir',
            'alinsan' => 'alinsan', 'almursalat' => 'almursalat',
            'almujadalah' => 'almujadilah', 'ashshaf' => 'assaff', 'asshaf' => 'assaff',
        ];

        return ['surahs' => $surahMap, 'aliases' => $aliases];
    }
}

if (!function_exists('convertAiExtractionToRanks')) {
    /**
     * Mengonversi output semantik AI ({is_achievement, juz, surah_key, ayat_end, is_munaqasyah})
     * menjadi angka rank/stage resmi secara deterministik di PHP.
     */
    function convertAiExtractionToRanks(array $parsed, ?array $dict = null): array
    {
        $dict = $dict ?? getTahfidzAiSurahDictionary();
        $out = [
            'is_achievement' => !empty($parsed['is_achievement']) ? 1 : 0,
            'juz'            => isset($parsed['juz']) && $parsed['juz'] !== null ? (int)$parsed['juz'] : null,
            'surah_key'      => null,
            'ayat_end'       => isset($parsed['ayat_end']) && (int)$parsed['ayat_end'] > 0 ? (int)$parsed['ayat_end'] : null,
            'juz30_rank'     => 0.0,
            'juz29_rank'     => 0.0,
            'juz28_rank'     => 0.0,
            'juz_stage'      => 0,
            'is_munaqasyah'  => !empty($parsed['is_munaqasyah']) ? 1 : 0,
            'confidence'     => max(0.0, min(1.0, (float)($parsed['confidence'] ?? 0.0))),
            'ai_reason'      => mb_substr(trim(strip_tags((string)($parsed['reason'] ?? ''))), 0, 250),
            'surah_label'    => '',
        ];

        if ($out['is_achievement'] === 0) {
            return $out;
        }

        $rawKey = preg_replace('/[^a-z0-9]/', '', strtolower((string)($parsed['surah_key'] ?? ''))) ?? '';
        if ($rawKey !== '' && isset($dict['aliases'][$rawKey])) {
            $rawKey = $dict['aliases'][$rawKey];
        }

        if ($rawKey !== '' && isset($dict['surahs'][$rawKey])) {
            $info = $dict['surahs'][$rawKey];
            $out['surah_key'] = $rawKey;
            $out['juz']       = (int)$info['juz'];
            $frac = 1.0;
            if ($out['ayat_end'] !== null && $info['ayats'] > 0 && $out['ayat_end'] < $info['ayats']) {
                $frac = min(1.0, max(0.05, $out['ayat_end'] / $info['ayats']));
            }

            if ($info['juz'] === 30) {
                $out['juz30_rank'] = $info['rank'] === 0 ? 0.5 : round(($info['rank'] - 1) + $frac, 2);
                $out['juz_stage']  = 1;
            } elseif ($info['juz'] === 29) {
                $out['juz30_rank'] = 37.0;
                $out['juz29_rank'] = round(($info['rank'] - 1) + $frac, 2);
                $out['juz_stage']  = 2;
            } elseif ($info['juz'] === 28) {
                $out['juz30_rank'] = 37.0;
                $out['juz29_rank'] = 48.0;
                $out['juz28_rank'] = round(($info['rank'] - 1) + $frac, 2);
                $out['juz_stage']  = 30;
            }
            $out['surah_label'] = $info['label'] . ($out['ayat_end'] !== null ? " (Ayat s.d. {$out['ayat_end']})" : '');
        } elseif ($out['juz'] !== null && $out['juz'] >= 1 && $out['juz'] <= 30) {
            $jn = $out['juz'];
            if ($jn === 30) {
                $out['juz30_rank'] = 37.0;
                $out['juz_stage']  = 1;
                $out['surah_label'] = 'Juz 30';
            } elseif ($jn === 29) {
                $out['juz30_rank'] = 37.0;
                $out['juz29_rank'] = 48.0;
                $out['juz_stage']  = 2;
                $out['surah_label'] = 'Juz 29';
            } elseif ($jn === 28) {
                $out['juz30_rank'] = 37.0;
                $out['juz29_rank'] = 48.0;
                $out['juz28_rank'] = 57.0;
                $out['juz_stage']  = 30;
                $out['surah_label'] = 'Juz 28';
            } else {
                $out['juz30_rank'] = 37.0;
                $out['juz29_rank'] = 48.0;
                $out['juz_stage']  = 2 + $jn;
                $out['surah_label'] = "Juz {$jn}";
            }
        }

        return $out;
    }
}

if (!function_exists('callTahfidzAiProvider')) {
    /**
     * Memanggil LLM (Gemini / OpenAI) secara server-side dengan prompt JSON terstruktur,
     * proteksi SSRF, dan batas timeout <= 12 detik. API key tidak pernah dibocorkan ke client.
     */
    function callTahfidzAiProvider(array $batchNotes): array
    {
        if (empty($batchNotes)) {
            return ['success' => true, 'items' => [], 'provider' => '', 'model' => '', 'error' => null];
        }
        $enabled  = defined('TAHFIDZ_AI_ENABLED') ? (bool)TAHFIDZ_AI_ENABLED : false;
        $provider = defined('TAHFIDZ_AI_PROVIDER') ? (string)TAHFIDZ_AI_PROVIDER : 'gemini';
        $model    = defined('TAHFIDZ_AI_MODEL') ? (string)TAHFIDZ_AI_MODEL : 'gemini-3.1-flash-lite';
        $apiKey   = defined('TAHFIDZ_AI_API_KEY') ? trim((string)TAHFIDZ_AI_API_KEY) : '';

        if (!$enabled || $apiKey === '') {
            return [
                'success'  => false,
                'items'    => [],
                'provider' => $provider,
                'model'    => $model,
                'error'    => 'API Key AI belum dikonfigurasi pada file .env server (TAHFIDZ_AI_API_KEY).',
            ];
        }

        if (!function_exists('curl_init')) {
            return ['success' => false, 'items' => [], 'provider' => $provider, 'model' => $model, 'error' => 'Ekstensi PHP cURL tidak tersedia.'];
        }

        $itemsPayload = [];
        foreach ($batchNotes as $n) {
            $itemsPayload[] = [
                'note_hash'   => (string)$n['note_hash'],
                'source_type' => (string)$n['source_type'],
                'kd_nama'     => (string)$n['kd_nama'],
                'materi'      => (string)$n['materi'],
                'raw_text'    => (string)$n['raw_text'],
                'nilai'       => $n['nilai'],
            ];
        }

        $systemPrompt = implode("\n", [
            'Anda adalah analis data Tahfidz Al-Qur\'an di An-Nahl Islamic School.',
            'Tugas Anda HANYA mengekstrak informasi hafalan yang SUDAH DIHAFAL / TUNTAS dari catatan bebas guru menjadi JSON valid.',
            'Aturan Urutan Resmi:',
            '- Juz 30 (mundur): annas, alfalaq, alikhlas, allahab, annashr, alkafirun, alkautsar, almaun, quraisy, alfil, alhumazah, alashr, attakatsur, alqariah, aladiyat, azzalzalah, albayyinah, alqadr, alalaq, attin, alinsyirah, adhdhuha, allail, asysyams, albalad, alfajr, alghasyiyah, alalaa, atthariq, alburuj, alinsyiqaq, almuthaffifin, alinfithar, attakwir, abasa, annaziat, annaba.',
            '- Juz 29 (maju): almulk, alqalam, alhaqqah, almaarij, nuh, aljin, almuzzammil, almuddatstsir, alqiyamah, alinsan, almursalat.',
            '- Juz 28 (maju): almujadilah, alhasyr, almumtahanah, assaff, aljumuah, almunafiqun, attaghabun, attalaq, attahrim.',
            'Aturan Penilaian Konteks:',
            '1. Jika catatan adalah PR / target besok ("Lanjut Qs. Al Fajr" saat kd_nama="Al-Balad", atau "PR ...", "siapkan ..."), maka capaian yang tuntas adalah kd_nama/materi ("albalad"), BUKAN surat target PR ("alfajr")!',
            '2. Jika catatan menyebut batasan ayat nyata ("hafal sampe ayat 7", meski materi tertulis "1-11"), pakai ayat_end = 7.',
            '3. Jika catatan adalah absensi ("izin", "sakit", "tidak hadir"), remedial/belum hafal ("belum hafal", "ulang", "masih tertukar" dengan nilai <70), Turjuman (terjemah per kata), Ghoroibul Qur\'an/Tadarrus membaca, maka set is_achievement = false.',
            '4. Jika catatan jelas menyebut setoran ziyadah/lulus di keterangan walau kd_nama generik ("Ziyadah: Al Infithar, At Takwir 1-18" atau "Alhamdulillah lulus nilai 87" di kd H3/30), set is_achievement = true dengan surah_key tertinggi yang dicapai.',
            '5. Keluarkan JSON OBJECT dengan key "results" berupa array object berformat:',
            '   {"note_hash": string, "is_achievement": boolean, "juz": int|null, "surah_key": string|null, "ayat_end": int|null, "is_munaqasyah": boolean, "confidence": float 0..1, "reason": string singkat max 140 char}.',
        ]);

        $userPrompt = 'Ekstrak array "results" untuk catatan berikut: ' . json_encode($itemsPayload, JSON_UNESCAPED_UNICODE);

        // Bangun daftar model kandidat untuk auto-fallback bila model utama terkena lonjakan traffic sementara (HTTP 503 / 429)
        $candidateModels = ($provider === 'openai')
            ? [($model ?: 'gpt-4o-mini')]
            : array_values(array_unique(array_slice([
                $model ?: 'gemini-3.1-flash-lite',
                'gemini-3.1-flash-lite',
                'gemini-flash-lite-latest',
                'gemini-3.5-flash-lite',
            ], 0, 4)));
        if ($provider === 'gemini') {
            $candidateModels = array_slice($candidateModels, 0, 3);
        }

        $lastHttpCode = 0;
        $lastCurlErr  = '';
        $usedModel    = $candidateModels[0];

        foreach ($candidateModels as $attemptIdx => $tryModel) {
            if ($attemptIdx > 0) {
                usleep(250000);
            }
            $usedModel = $tryModel;

            if ($provider === 'openai') {
                $endpoint = 'https://api.openai.com/v1/chat/completions';
                $headers  = [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ];
                $body = json_encode([
                    'model'           => $tryModel,
                    'temperature'     => 0.1,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $safeModel = preg_replace('/[^a-zA-Z0-9._-]/', '', $tryModel) ?: 'gemini-3.1-flash-lite';
                $usedModel = $safeModel;
                $endpoint  = "https://generativelanguage.googleapis.com/v1beta/models/{$safeModel}:generateContent";
                $headers   = [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $apiKey,
                ];
                $body = json_encode([
                    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents'          => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
                    'generationConfig'  => [
                        'temperature'      => 0.1,
                        'responseMimeType' => 'application/json',
                    ],
                ], JSON_UNESCAPED_UNICODE);
            }

            if (!isAllowedAiEndpointUrl($endpoint)) {
                return ['success' => false, 'items' => [], 'provider' => $provider, 'model' => $usedModel, 'error' => 'Endpoint AI ditolak oleh proteksi SSRF.'];
            }

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ]);
            $rawResp      = curl_exec($ch);
            $lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastCurlErr  = curl_error($ch);
            curl_close($ch);

            if ($rawResp !== false && $lastCurlErr === '' && $lastHttpCode === 200) {
                $respJson = json_decode((string)$rawResp, true);
                if (is_array($respJson)) {
                    $jsonText = ($provider === 'openai')
                        ? (string)($respJson['choices'][0]['message']['content'] ?? '')
                        : (string)($respJson['candidates'][0]['content']['parts'][0]['text'] ?? '');
                    $jsonText = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($jsonText)) ?? trim($jsonText);
                    $parsed   = json_decode($jsonText, true);
                    $results  = is_array($parsed['results'] ?? null) ? $parsed['results'] : (is_array($parsed) && isset($parsed[0]) ? $parsed : []);

                    return [
                        'success'  => true,
                        'items'    => $results,
                        'provider' => $provider,
                        'model'    => $usedModel,
                        'error'    => null,
                    ];
                }
            }

            error_log("[callTahfidzAiProvider] {$provider}:{$usedModel} HTTP {$lastHttpCode} err={$lastCurlErr}");
            // Hentikan segera jika masalah otentikasi / request invalid; hanya fallback bila 503/429/404/timeout
            if (!in_array($lastHttpCode, [0, 404, 429, 500, 502, 503, 504], true)) {
                break;
            }
        }

        if ($lastCurlErr !== '' || $lastHttpCode === 0) {
            return [
                'success'  => false,
                'items'    => [],
                'provider' => $provider,
                'model'    => $usedModel,
                'error'    => 'Koneksi ke layanan AI melewati batas waktu (timeout). Silakan coba lagi.',
            ];
        }
        if (in_array($lastHttpCode, [429, 500, 502, 503, 504], true)) {
            return [
                'success'  => false,
                'items'    => [],
                'provider' => $provider,
                'model'    => $usedModel,
                'error'    => "Server AI Google sedang sibuk sementara (HTTP {$lastHttpCode} / High Demand). API Key Anda valid — silakan klik ulang analisis beberapa detik lagi.",
            ];
        }
        return [
            'success'  => false,
            'items'    => [],
            'provider' => $provider,
            'model'    => $usedModel,
            'error'    => "Layanan AI merespons HTTP {$lastHttpCode}. Periksa API Key atau nama model pada file .env.",
        ];
    }
}

if (!function_exists('extractTahfidzNotesWithAiBatch')) {
    /**
     * Memproses antrean catatan unik ke kinerja_tahfidz_ai_cache dalam sub-chunk aman (maks 10/request)
     * sehingga tiap chunk langsung tersimpan permanen dan terhindar dari timeout beban besar.
     */
    function extractTahfidzNotesWithAiBatch(PDO $pdo, array $pendingNotes, int $batchLimit = 20): array
    {
        $limitSafe = max(1, min(50, $batchLimit));
        if (empty($pendingNotes)) {
            return ['success' => true, 'processed' => 0, 'remaining' => 0, 'error' => null];
        }

        $pendingByHash = [];
        foreach ($pendingNotes as $n) {
            if (!empty($n['note_hash'])) {
                $pendingByHash[(string)$n['note_hash']] = $n;
            }
        }
        $allHashes = array_keys($pendingByHash);
        if (empty($allHashes)) {
            return ['success' => true, 'processed' => 0, 'remaining' => 0, 'error' => null];
        }

        $existing = [];
        foreach (array_chunk($allHashes, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmtEx = $pdo->prepare("SELECT note_hash FROM kinerja_tahfidz_ai_cache WHERE note_hash IN ({$ph})");
            $stmtEx->execute($chunk);
            foreach ($stmtEx->fetchAll(PDO::FETCH_COLUMN) as $h) {
                $existing[(string)$h] = true;
            }
        }

        $toProcess = [];
        foreach ($pendingByHash as $h => $note) {
            if (!isset($existing[$h])) {
                $toProcess[] = $note;
            }
        }

        if (empty($toProcess)) {
            return ['success' => true, 'processed' => 0, 'remaining' => 0, 'error' => null];
        }

        $batch      = array_slice($toProcess, 0, $limitSafe);
        $dict       = getTahfidzAiSurahDictionary();
        $totalSaved = 0;
        $lastErr    = null;

        try {
            $stmtUp = $pdo->prepare("
                INSERT INTO kinerja_tahfidz_ai_cache
                    (note_hash, source_type, kd_nama, materi, raw_text, is_achievement, juz, surah_key, ayat_end,
                     juz30_rank, juz29_rank, juz28_rank, juz_stage, is_munaqasyah, confidence, ai_reason,
                     ai_provider, ai_model, parsed_json, review_status)
                VALUES
                    (:note_hash, :source_type, :kd_nama, :materi, :raw_text, :is_achievement, :juz, :surah_key, :ayat_end,
                     :juz30_rank, :juz29_rank, :juz28_rank, :juz_stage, :is_munaqasyah, :confidence, :ai_reason,
                     :ai_provider, :ai_model, :parsed_json, 'auto_ai')
                ON DUPLICATE KEY UPDATE
                    is_achievement = VALUES(is_achievement),
                    juz            = VALUES(juz),
                    surah_key      = VALUES(surah_key),
                    ayat_end       = VALUES(ayat_end),
                    juz30_rank     = VALUES(juz30_rank),
                    juz29_rank     = VALUES(juz29_rank),
                    juz28_rank     = VALUES(juz28_rank),
                    juz_stage      = VALUES(juz_stage),
                    is_munaqasyah  = VALUES(is_munaqasyah),
                    confidence     = VALUES(confidence),
                    ai_reason      = VALUES(ai_reason),
                    ai_provider    = VALUES(ai_provider),
                    ai_model       = VALUES(ai_model),
                    parsed_json    = VALUES(parsed_json)
            ");

            foreach (array_chunk($batch, 10) as $subChunk) {
                $aiResult = callTahfidzAiProvider($subChunk);
                if (empty($aiResult['success'])) {
                    $lastErr = $aiResult['error'] ?? 'Ekstraksi AI terhenti sementara.';
                    break;
                }

                $chunkMap = [];
                foreach ($subChunk as $b) {
                    $chunkMap[(string)$b['note_hash']] = $b;
                }

                $pdo->beginTransaction();
                foreach ($aiResult['items'] as $item) {
                    if (!is_array($item) || empty($item['note_hash'])) {
                        continue;
                    }
                    $h = (string)$item['note_hash'];
                    if (!isset($chunkMap[$h])) {
                        continue;
                    }
                    $src  = $chunkMap[$h];
                    $conv = convertAiExtractionToRanks($item, $dict);

                    $stmtUp->execute([
                        ':note_hash'      => $h,
                        ':source_type'    => in_array($src['source_type'] ?? '', ['quran_nilai', 'tes_ummi', 'rapor', 'jurnal'], true) ? $src['source_type'] : 'quran_nilai',
                        ':kd_nama'        => mb_substr((string)($src['kd_nama'] ?? ''), 0, 150),
                        ':materi'         => mb_substr((string)($src['materi'] ?? ''), 0, 255),
                        ':raw_text'       => (string)($src['raw_text'] ?? ''),
                        ':is_achievement' => $conv['is_achievement'],
                        ':juz'            => $conv['juz'],
                        ':surah_key'      => $conv['surah_key'],
                        ':ayat_end'       => $conv['ayat_end'],
                        ':juz30_rank'     => $conv['juz30_rank'],
                        ':juz29_rank'     => $conv['juz29_rank'],
                        ':juz28_rank'     => $conv['juz28_rank'],
                        ':juz_stage'      => $conv['juz_stage'],
                        ':is_munaqasyah'  => $conv['is_munaqasyah'],
                        ':confidence'     => $conv['confidence'],
                        ':ai_reason'      => $conv['ai_reason'],
                        ':ai_provider'    => (string)$aiResult['provider'],
                        ':ai_model'       => (string)$aiResult['model'],
                        ':parsed_json'    => json_encode($item, JSON_UNESCAPED_UNICODE) ?: null,
                    ]);
                    $totalSaved++;
                }
                $pdo->commit();
            }

            return [
                'success'   => ($lastErr === null),
                'processed' => $totalSaved,
                'remaining' => max(0, count($toProcess) - $totalSaved),
                'error'     => $lastErr,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[extractTahfidzNotesWithAiBatch] DB error: ' . $e->getMessage());
            return [
                'success'   => false,
                'processed' => $totalSaved,
                'remaining' => max(0, count($toProcess) - $totalSaved),
                'error'     => 'Gagal menyimpan hasil ekstraksi AI ke database cache.',
            ];
        }
    }
}

if (!function_exists('applyTahfidzAiCacheToStudents')) {
    /**
     * Menggabungkan hasil AI Semantic Cache & Koreksi Manual (tanpa panggilan API eksternal) ke dataset santri
     * sebelum calculateTahfidzHierarchy() dijalankan.
     */
    function applyTahfidzAiCacheToStudents(PDO $pdo, array &$apiData, int $unitId, int $tahunAjaranId, float $confidenceThreshold = 0.80): array
    {
        $ambiguousMap = is_array($apiData['ambiguous_notes'] ?? null) ? $apiData['ambiguous_notes'] : [];
        $summary = [
            'total_ambiguous'      => count($ambiguousMap),
            'cached_count'         => 0,
            'pending_count'        => count($ambiguousMap),
            'pending_review_count' => 0,
            'ai_upgraded_count'    => 0,
            'override_count'       => 0,
            'pending_notes'        => array_values($ambiguousMap),
            'tables_ready'         => true,
        ];

        $students = is_array($apiData['students'] ?? null) ? $apiData['students'] : [];
        if (empty($students)) {
            return $summary;
        }

        $cacheRows     = [];
        $overrideRows  = [];

        try {
            $hashes = array_keys($ambiguousMap);
            if (!empty($hashes)) {
                foreach (array_chunk($hashes, 200) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $stmtC = $pdo->prepare("SELECT * FROM kinerja_tahfidz_ai_cache WHERE note_hash IN ({$ph})");
                    $stmtC->execute($chunk);
                    foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                        $cacheRows[(string)$cr['note_hash']] = $cr;
                    }
                }
            }

            if ($unitId > 0 && $tahunAjaranId > 0) {
                $stmtO = $pdo->prepare("
                    SELECT * FROM kinerja_tahfidz_student_overrides
                    WHERE unit_id = :unit_id AND tahun_ajaran_id = :ta_id
                ");
                $stmtO->execute([':unit_id' => $unitId, ':ta_id' => $tahunAjaranId]);
                foreach ($stmtO->fetchAll(PDO::FETCH_ASSOC) as $or) {
                    $overrideRows[(int)$or['siswa_id']] = $or;
                }
            }
        } catch (Throwable $e) {
            error_log('[applyTahfidzAiCacheToStudents] Table check skipped: ' . $e->getMessage());
            $summary['tables_ready'] = false;
            return $summary;
        }

        $pendingList = [];
        foreach ($ambiguousMap as $h => $noteMeta) {
            if (!isset($cacheRows[$h])) {
                $pendingList[] = $noteMeta;
            }
        }
        $summary['cached_count']  = count($cacheRows);
        $summary['pending_count'] = count($pendingList);
        $summary['pending_notes'] = $pendingList;

        $dict = getTahfidzAiSurahDictionary();

        foreach ($students as &$st) {
            $sid = (int)($st['siswa_id'] ?? 0);
            $st['ai_badge']    = '';
            $st['ai_evidence'] = null;
            $st['manual_override'] = null;

            // 1. Koreksi Manual Santri memiliki prioritas absolut
            if (isset($overrideRows[$sid])) {
                $ov = $overrideRows[$sid];
                $st['juz30_rank'] = max((float)($st['juz30_rank'] ?? 0), (float)$ov['juz30_rank']);
                $st['juz29_rank'] = max((float)($st['juz29_rank'] ?? 0), (float)$ov['juz29_rank']);
                $st['juz28_rank'] = max((float)($st['juz28_rank'] ?? 0), (float)$ov['juz28_rank']);
                $st['juz_stage']  = max((int)($st['juz_stage'] ?? 0), (int)$ov['juz_stage']);
                if (!empty($ov['has_munaqasyah_juz30'])) $st['has_munaqasyah_juz30'] = true;
                if (!empty($ov['has_munaqasyah_juz29'])) $st['has_munaqasyah_juz29'] = true;
                if (!empty($ov['has_munaqasyah_juz28'])) $st['has_munaqasyah_juz28'] = true;
                $st['actual_display'] = (string)$ov['display_label'];
                $st['ai_badge']       = 'manual_override';
                $st['manual_override'] = [
                    'display_label' => (string)$ov['display_label'],
                    'reason'        => (string)$ov['reason'],
                    'updated_at'    => $ov['updated_at'] ?? null,
                ];
                $summary['override_count']++;
                continue;
            }

            $noteHashes = is_array($st['note_hashes'] ?? null) ? $st['note_hashes'] : [];
            if (empty($noteHashes)) {
                continue;
            }

            $bestApplied = null;
            $bestPending = null;
            $curTopScore = max((float)($st['juz28_rank'] ?? 0), (float)($st['juz29_rank'] ?? 0), (float)($st['juz30_rank'] ?? 0));

            foreach ($noteHashes as $nh) {
                if (!isset($cacheRows[$nh])) {
                    continue;
                }
                $c = $cacheRows[$nh];
                if ($c['review_status'] === 'rejected' || (int)$c['is_achievement'] !== 1) {
                    continue;
                }

                $conf = (float)$c['confidence'];
                $cTopScore = max((float)$c['juz28_rank'], (float)$c['juz29_rank'], (float)$c['juz30_rank']);
                $cStage    = (int)$c['juz_stage'];
                $isHigher  = ($cTopScore > $curTopScore) || ($cStage > (int)($st['juz_stage'] ?? 0));

                $surahInfo = (!empty($c['surah_key']) && isset($dict['surahs'][$c['surah_key']]))
                    ? $dict['surahs'][$c['surah_key']]['label']
                    : ($c['juz'] ? ('Juz ' . (int)$c['juz']) : 'Catatan');

                $ev = [
                    'note_hash'     => $nh,
                    'source_type'   => (string)$c['source_type'],
                    'kd_nama'       => (string)$c['kd_nama'],
                    'raw_text'      => (string)$c['raw_text'],
                    'surah_label'   => $surahInfo . (!empty($c['ayat_end']) ? " (Ayat s.d. {$c['ayat_end']})" : ''),
                    'confidence'    => round($conf * 100, 0),
                    'ai_reason'     => (string)$c['ai_reason'],
                    'review_status' => (string)$c['review_status'],
                ];

                $noteScore = $cTopScore + ($cStage * 100);
                if ($c['review_status'] === 'verified' || $conf >= $confidenceThreshold) {
                    if ($bestApplied === null || $noteScore > $bestApplied['score']) {
                        $bestApplied = ['row' => $c, 'ev' => $ev, 'upgraded' => $isHigher, 'score' => $noteScore];
                    }
                } elseif ($isHigher && ($bestPending === null || $noteScore > $bestPending['score'])) {
                    $bestPending = ['row' => $c, 'ev' => $ev, 'score' => $noteScore];
                }
            }

            if ($bestApplied !== null && ($bestApplied['upgraded'] || ($st['actual_display'] ?? '') === 'Belum Ada Data')) {
                $c = $bestApplied['row'];
                $st['juz30_rank'] = max((float)($st['juz30_rank'] ?? 0), (float)$c['juz30_rank']);
                $st['juz29_rank'] = max((float)($st['juz29_rank'] ?? 0), (float)$c['juz29_rank']);
                $st['juz28_rank'] = max((float)($st['juz28_rank'] ?? 0), (float)$c['juz28_rank']);
                $st['juz_stage']  = max((int)($st['juz_stage'] ?? 0), (int)$c['juz_stage']);

                if ((int)$c['juz'] === 30 && !empty($c['is_munaqasyah'])) $st['has_munaqasyah_juz30'] = true;
                if ((int)$c['juz'] === 29 && !empty($c['is_munaqasyah'])) $st['has_munaqasyah_juz29'] = true;
                if ((int)$c['juz'] === 28 && !empty($c['is_munaqasyah'])) $st['has_munaqasyah_juz28'] = true;

                if ($st['juz_stage'] >= 2 || $st['juz29_rank'] >= 38) {
                    $st['juz30_rank'] = max($st['juz30_rank'], 37.0);
                }
                if ($st['juz_stage'] >= 3) {
                    $st['juz29_rank'] = max($st['juz29_rank'], 48.0);
                    $st['juz29_count'] = 11;
                }

                $st['ai_badge']    = ($c['review_status'] === 'verified') ? 'ai_verified' : 'ai_applied';
                $st['ai_evidence'] = $bestApplied['ev'];

                $prefix = ($c['review_status'] === 'verified') ? 'Terverifikasi: ' : 'AI Catatan: ';
                $st['actual_display'] = $prefix . $bestApplied['ev']['surah_label'];
                if ($bestApplied['upgraded']) {
                    $summary['ai_upgraded_count']++;
                }
            } elseif ($bestPending !== null) {
                $st['ai_badge']    = 'ai_pending';
                $st['ai_evidence'] = $bestPending['ev'];
                $summary['pending_review_count']++;
            }
        }
        unset($st);

        $apiData['students'] = $students;
        return $summary;
    }
}

// =========================================================================
// SHARED CONTEXT, PERBANDINGAN SEBELUM/SESUDAH AI & AUDIT CATATAN TAHFIDZ
// =========================================================================

if (!function_exists('getTahfidzAccessContext')) {
    /**
     * Mengambil konteks otorisasi unit akademik user untuk seluruh modul Tahfidz.
     */
    function getTahfidzAccessContext(PDO $pdo): array
    {
        $role = currentRole();
        $allowedLocalUnits = getUserAllowedUnits($pdo);
        $allowedUnitNames  = [];
        if ($role !== 'super_admin' && !empty($allowedLocalUnits)) {
            foreach ($allowedLocalUnits as $lu) {
                $uName = strtoupper(trim((string)($lu['unit'] ?? '')));
                if ($uName !== '') {
                    $allowedUnitNames[] = $uName;
                }
            }
        }
        return [
            'role'               => $role,
            'is_super_admin'     => ($role === 'super_admin'),
            'allowed_unit_names' => array_values(array_unique($allowedUnitNames)),
        ];
    }
}

if (!function_exists('filterTahfidzAisUnitsByAccess')) {
    /**
     * Menyaring daftar unit AIS berdasarkan unit akademik yang diizinkan pada user.
     */
    function filterTahfidzAisUnitsByAccess(array $aisUnits, array $accessCtx): array
    {
        $allowedNames = $accessCtx['allowed_unit_names'] ?? [];
        if (!empty($accessCtx['is_super_admin']) || empty($allowedNames)) {
            return $aisUnits;
        }
        return array_values(array_filter($aisUnits, static function ($u) use ($allowedNames) {
            $n = strtoupper(trim((string)($u['nama'] ?? '')));
            return in_array($n, $allowedNames, true);
        }));
    }
}

if (!function_exists('assertTahfidzPostUnitAccess')) {
    /**
     * Mencegah IDOR lintas unit saat POST action pada modul Tahfidz.
     */
    function assertTahfidzPostUnitAccess(int $postUnitId, array $accessCtx): void
    {
        $allowedNames = $accessCtx['allowed_unit_names'] ?? [];
        if (!empty($accessCtx['is_super_admin']) || empty($allowedNames)) {
            return;
        }
        $probeMeta   = fetchTahfidzAchievement($postUnitId, null, 0, false);
        $allowedPost = filterTahfidzAisUnitsByAccess($probeMeta['meta']['units'] ?? [], $accessCtx);
        $allowedIds  = array_map(static fn($u) => (int)($u['id'] ?? 0), $allowedPost);
        if (!in_array($postUnitId, $allowedIds, true)) {
            http_response_code(403);
            die('403 - Akses Ditolak: Anda tidak memiliki hak akses untuk mengubah data unit ini.');
        }
    }
}

if (!function_exists('loadTahfidzFilterContext')) {
    /**
     * Memuat filter Unit/TA/Semester/Bulan/Sort dan mengambil snapshot AIS dengan scoping RBAC.
     */
    function loadTahfidzFilterContext(PDO $pdo, array $accessCtx, array $query): array
    {
        $unitId          = isset($query['unit_id']) ? max(1, (int)$query['unit_id']) : 2;
        $reqTahunAjaranId = (isset($query['tahun_ajaran_id']) && (int)$query['tahun_ajaran_id'] > 0) ? (int)$query['tahun_ajaran_id'] : null;
        $reqYearName     = trim((string)($query['tahun'] ?? ''));
        $reqSemester     = (isset($query['semester']) && in_array((int)$query['semester'], [1, 2], true)) ? (int)$query['semester'] : null;
        $month           = isset($query['month']) ? max(0, min(12, (int)$query['month'])) : 0;
        $sortMode        = in_array($query['sort'] ?? '', ['grade_asc', 'highest', 'lowest'], true) ? (string)$query['sort'] : 'grade_asc';
        $forceRefresh    = isset($query['refresh']) && $query['refresh'] === '1';

        $apiResponse       = fetchTahfidzAchievement($unitId, $reqTahunAjaranId, $month, $forceRefresh);
        $availableUnitsAll = $apiResponse['meta']['units'] ?? [];
        $availableUnits    = filterTahfidzAisUnitsByAccess($availableUnitsAll, $accessCtx);

        if (!empty($availableUnits)) {
            $allowedAisIds = array_map(static fn($u) => (int)($u['id'] ?? 0), $availableUnits);
            if (!in_array($unitId, $allowedAisIds, true)) {
                $unitId      = $allowedAisIds[0];
                $apiResponse = fetchTahfidzAchievement($unitId, $reqTahunAjaranId, $month, $forceRefresh);
            }
        }

        $academicYears    = $apiResponse['meta']['academic_years'] ?? [];
        $selectedYear     = $apiResponse['meta']['selected_tahun_ajaran'] ?? [];
        $selectedYearName = $reqYearName !== '' ? $reqYearName : (string)($selectedYear['nama'] ?? '');
        $selectedSemester = $reqSemester !== null ? $reqSemester : (int)($selectedYear['semester'] ?? 1);

        if (!empty($academicYears) && ($reqYearName !== '' || $reqSemester !== null) && $reqTahunAjaranId === null) {
            $matchedTaId = null;
            foreach ($academicYears as $ay) {
                if ((string)($ay['nama'] ?? '') === $selectedYearName && (int)($ay['semester'] ?? 0) === $selectedSemester) {
                    $matchedTaId = (int)$ay['id'];
                    break;
                }
            }
            if ($matchedTaId === null) {
                foreach ($academicYears as $ay) {
                    if ((string)($ay['nama'] ?? '') === $selectedYearName) {
                        $matchedTaId      = (int)$ay['id'];
                        $selectedSemester = (int)($ay['semester'] ?? 1);
                        break;
                    }
                }
            }
            if ($matchedTaId !== null && $matchedTaId !== (int)($selectedYear['id'] ?? 0)) {
                $apiResponse   = fetchTahfidzAchievement($unitId, $matchedTaId, $month, $forceRefresh);
                $academicYears = $apiResponse['meta']['academic_years'] ?? $academicYears;
                $selectedYear  = $apiResponse['meta']['selected_tahun_ajaran'] ?? $selectedYear;
                if (!empty($selectedYear['nama'])) {
                    $selectedYearName = (string)$selectedYear['nama'];
                    $selectedSemester = (int)($selectedYear['semester'] ?? 1);
                }
            }
        }

        $isSuccess        = !empty($apiResponse['success']);
        $apiError         = $apiResponse['error'] ?? null;
        $isFromCache      = !empty($apiResponse['from_cache']);
        $sourceVia        = (string)($apiResponse['source_via'] ?? ($isFromCache ? 'Smart Cache' : 'Live API'));
        $meta             = is_array($apiResponse['meta'] ?? null) ? $apiResponse['meta'] : [];
        $selectedUnit     = $meta['selected_unit'] ?? ['id' => $unitId, 'nama' => 'SD'];
        $selectedYear     = $meta['selected_tahun_ajaran'] ?? $selectedYear;
        $selectedYearName = (string)($selectedYear['nama'] ?? $selectedYearName);
        $selectedSemester = (int)($selectedYear['semester'] ?? $selectedSemester);
        $selectedTaId     = (int)($selectedYear['id'] ?? 0);

        $uniqueYearNames = [];
        foreach ($academicYears as $ay) {
            $yn = (string)($ay['nama'] ?? '');
            if ($yn !== '' && !in_array($yn, $uniqueYearNames, true)) {
                $uniqueYearNames[] = $yn;
            }
        }

        return [
            'unit_id'           => $unitId,
            'month'             => $month,
            'sort_mode'         => $sortMode,
            'force_refresh'     => $forceRefresh,
            'api_response'      => $apiResponse,
            'available_units'   => $availableUnits,
            'academic_years'    => $academicYears,
            'unique_year_names' => $uniqueYearNames,
            'available_months'  => is_array($meta['available_months'] ?? null) ? $meta['available_months'] : [],
            'selected_unit'     => $selectedUnit,
            'selected_year'     => $selectedYear,
            'selected_year_name'=> $selectedYearName,
            'selected_semester' => $selectedSemester,
            'selected_ta_id'    => $selectedTaId,
            'is_success'        => $isSuccess,
            'api_error'         => $apiError,
            'is_from_cache'     => $isFromCache,
            'source_via'        => $sourceVia,
            'meta'              => $meta,
        ];
    }
}

if (!function_exists('fetchTahfidzAiNoteCatalogForAudit')) {
    /**
     * Mengambil katalog lengkap catatan bebas guru + status cache AI + sampel santri terkait untuk halaman Audit AI.
     */
    function fetchTahfidzAiNoteCatalogForAudit(PDO $pdo, array $apiData, float $confidenceThreshold = 0.80): array
    {
        $ambiguousMap = is_array($apiData['ambiguous_notes'] ?? null) ? $apiData['ambiguous_notes'] : [];
        $students     = is_array($apiData['students'] ?? null) ? $apiData['students'] : [];

        $counts = [
            'all'            => count($ambiguousMap),
            'pending'        => 0,
            'low_confidence' => 0,
            'auto_ai'        => 0,
            'verified'       => 0,
            'rejected'       => 0,
        ];

        if (empty($ambiguousMap)) {
            return ['items' => [], 'counts' => $counts, 'tables_ready' => true];
        }

        // Hubungkan setiap note_hash ke daftar santri pemiliknya agar penguji bisa langsung audit konteksnya
        $studentsByHash = [];
        foreach ($students as $st) {
            $hashes = is_array($st['note_hashes'] ?? null) ? $st['note_hashes'] : [];
            if (empty($hashes)) {
                continue;
            }
            $stRef = [
                'siswa_id'    => (int)($st['siswa_id'] ?? 0),
                'nama'        => (string)($st['nama'] ?? ''),
                'rombel_nama' => trim((string)(($st['paralel_nama'] ?? '') . ' ' . ($st['rombel_nama'] ?? ''))),
            ];
            foreach ($hashes as $nh) {
                $nhStr = (string)$nh;
                if (!isset($studentsByHash[$nhStr])) {
                    $studentsByHash[$nhStr] = ['count' => 0, 'samples' => []];
                }
                $studentsByHash[$nhStr]['count']++;
                if (count($studentsByHash[$nhStr]['samples']) < 3) {
                    $studentsByHash[$nhStr]['samples'][] = $stRef;
                }
            }
        }

        $cacheRows   = [];
        $tablesReady = true;
        try {
            $hashes = array_keys($ambiguousMap);
            foreach (array_chunk($hashes, 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $stmtC = $pdo->prepare("SELECT * FROM kinerja_tahfidz_ai_cache WHERE note_hash IN ({$ph})");
                $stmtC->execute($chunk);
                foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                    $cacheRows[(string)$cr['note_hash']] = $cr;
                }
            }
        } catch (Throwable $e) {
            error_log('[fetchTahfidzAiNoteCatalogForAudit] Error: ' . $e->getMessage());
            $tablesReady = false;
        }

        $dict  = getTahfidzAiSurahDictionary();
        $items = [];

        foreach ($ambiguousMap as $nh => $noteMeta) {
            $nhStr = (string)$nh;
            $c     = $cacheRows[$nhStr] ?? null;

            $auditBucket = 'pending';
            $surahLabel  = '-';
            $confPct     = 0;
            $aiReason    = '';
            $isAchieve   = 0;
            $reviewState = 'pending';

            if ($c !== null) {
                $conf        = (float)($c['confidence'] ?? 0);
                $confPct     = (int)round($conf * 100);
                $isAchieve   = (int)($c['is_achievement'] ?? 0);
                $aiReason    = (string)($c['ai_reason'] ?? '');
                $reviewState = (string)($c['review_status'] ?? 'auto_ai');

                $surahBase = (!empty($c['surah_key']) && isset($dict['surahs'][$c['surah_key']]))
                    ? $dict['surahs'][$c['surah_key']]['label']
                    : (!empty($c['juz']) ? ('Juz ' . (int)$c['juz']) : ($isAchieve ? 'Capaian Terbaca' : 'Bukan Capaian'));
                $surahLabel = $surahBase . (!empty($c['ayat_end']) ? (' (Ayat s.d. ' . (int)$c['ayat_end'] . ')') : '');

                if ($reviewState === 'rejected') {
                    $auditBucket = 'rejected';
                } elseif ($reviewState === 'verified') {
                    $auditBucket = 'verified';
                } elseif ($isAchieve === 1 && $conf < $confidenceThreshold) {
                    $auditBucket = 'low_confidence';
                } else {
                    $auditBucket = 'auto_ai';
                }
            }

            $counts[$auditBucket]++;
            $linked = $studentsByHash[$nhStr] ?? ['count' => 0, 'samples' => []];

            $items[] = [
                'note_hash'       => $nhStr,
                'source_type'     => (string)($noteMeta['source_type'] ?? 'quran_nilai'),
                'kd_nama'         => (string)($noteMeta['kd_nama'] ?? ''),
                'materi'          => (string)($noteMeta['materi'] ?? ''),
                'raw_text'        => (string)($noteMeta['raw_text'] ?? ''),
                'audit_bucket'    => $auditBucket,
                'review_status'   => $reviewState,
                'is_achievement'  => $isAchieve,
                'surah_label'     => $surahLabel,
                'confidence_pct'  => $confPct,
                'ai_reason'       => $aiReason,
                'ai_provider'     => (string)($c['ai_provider'] ?? ''),
                'ai_model'        => (string)($c['ai_model'] ?? ''),
                'student_count'   => (int)$linked['count'],
                'student_samples' => $linked['samples'],
                'in_cache'        => ($c !== null),
            ];
        }

        // Prioritaskan catatan butuh tinjauan (<80%), lalu pending antrean, lalu auto/verified/rejected
        $priorityOrder = ['low_confidence' => 1, 'pending' => 2, 'auto_ai' => 3, 'verified' => 4, 'rejected' => 5];
        usort($items, static function ($a, $b) use ($priorityOrder) {
            $pa = $priorityOrder[$a['audit_bucket']] ?? 9;
            $pb = $priorityOrder[$b['audit_bucket']] ?? 9;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return ($b['student_count'] <=> $a['student_count']);
        });

        return [
            'items'        => $items,
            'counts'       => $counts,
            'tables_ready' => $tablesReady,
        ];
    }
}

if (!function_exists('buildTahfidzAiComparisonData')) {
    /**
     * Membandingkan hasil hierarki Tahfidz Sebelum AI (Baku AIS) vs Sesudah AI (+ Koreksi Manual).
     */
    function buildTahfidzAiComparisonData(array $beforeHierarchy, array $afterHierarchy): array
    {
        $bUnit = $beforeHierarchy['unit_summary'] ?? [];
        $aUnit = $afterHierarchy['unit_summary'] ?? [];

        $beforeStudentsById = [];
        $beforeParalelById  = [];
        foreach (($beforeHierarchy['paralels'] ?? []) as $bp) {
            $beforeParalelById[(int)($bp['paralel_id'] ?? 0)] = $bp;
            foreach (($bp['rombels'] ?? []) as $br) {
                foreach (($br['students'] ?? []) as $bs) {
                    $beforeStudentsById[(int)($bs['siswa_id'] ?? 0)] = $bs;
                }
            }
        }

        $paralelRows     = [];
        $changedStudents = [];

        foreach (($afterHierarchy['paralels'] ?? []) as $ap) {
            $pid = (int)($ap['paralel_id'] ?? 0);
            $bp  = $beforeParalelById[$pid] ?? null;

            $bAch = (int)($bp['achieved_students'] ?? 0);
            $aAch = (int)($ap['achieved_students'] ?? 0);
            $bPct = (float)($bp['percentage'] ?? 0.0);
            $aPct = (float)($ap['percentage'] ?? 0.0);

            $paralelRows[] = [
                'paralel_id'      => $pid,
                'paralel_nama'    => (string)($ap['paralel_nama'] ?? ''),
                'target_label'    => (string)($ap['target_label'] ?? ''),
                'total_students'  => (int)($ap['total_students'] ?? 0),
                'before_achieved' => $bAch,
                'after_achieved'  => $aAch,
                'delta_achieved'  => $aAch - $bAch,
                'before_pct'      => $bPct,
                'after_pct'       => $aPct,
                'delta_pct'       => round($aPct - $bPct, 1),
            ];

            foreach (($ap['rombels'] ?? []) as $ar) {
                foreach (($ar['students'] ?? []) as $as) {
                    $sid      = (int)($as['siswa_id'] ?? 0);
                    $bs       = $beforeStudentsById[$sid] ?? null;
                    $aiBadge  = (string)($as['ai_badge'] ?? '');
                    $bDisp    = (string)($bs['actual_display'] ?? 'Belum Ada Data');
                    $aDisp    = (string)($as['actual_display'] ?? 'Belum Ada Data');
                    $bAchSt   = !empty($bs['achieved']);
                    $aAchSt   = !empty($as['achieved']);
                    $bProg    = (float)($bs['progress_pct'] ?? 0.0);
                    $aProg    = (float)($as['progress_pct'] ?? 0.0);

                    if ($aiBadge !== '' || $bDisp !== $aDisp || $bAchSt !== $aAchSt || abs($aProg - $bProg) > 0.05) {
                        $changedStudents[] = [
                            'siswa_id'         => $sid,
                            'nama'             => (string)($as['nama'] ?? ''),
                            'paralel_nama'     => (string)($ap['paralel_nama'] ?? ''),
                            'rombel_nama'      => (string)($ar['rombel_nama'] ?? ''),
                            'pengajar_nama'    => (string)($as['pengajar_nama'] ?? '-'),
                            'before_display'   => $bDisp,
                            'after_display'    => $aDisp,
                            'before_progress'  => $bProg,
                            'after_progress'   => $aProg,
                            'before_achieved'  => $bAchSt,
                            'after_achieved'   => $aAchSt,
                            'flipped_achieved' => (!$bAchSt && $aAchSt),
                            'ai_badge'         => $aiBadge,
                            'ai_evidence'      => $as['ai_evidence'] ?? null,
                            'manual_override'  => $as['manual_override'] ?? null,
                        ];
                    }
                }
            }
        }

        usort($changedStudents, static function ($x, $y) {
            if ($x['flipped_achieved'] !== $y['flipped_achieved']) {
                return $y['flipped_achieved'] <=> $x['flipped_achieved'];
            }
            $dx = $x['after_progress'] - $x['before_progress'];
            $dy = $y['after_progress'] - $y['before_progress'];
            if (abs($dy - $dx) > 0.05) {
                return ($dy > $dx) ? 1 : -1;
            }
            return strcasecmp($x['nama'], $y['nama']);
        });

        $bUnitAch = (int)($bUnit['achieved_students'] ?? 0);
        $aUnitAch = (int)($aUnit['achieved_students'] ?? 0);
        $bUnitPct = (float)($bUnit['percentage'] ?? 0.0);
        $aUnitPct = (float)($aUnit['percentage'] ?? 0.0);

        return [
            'unit_delta' => [
                'total_students'  => (int)($aUnit['total_students'] ?? 0),
                'before_achieved' => $bUnitAch,
                'after_achieved'  => $aUnitAch,
                'delta_achieved'  => $aUnitAch - $bUnitAch,
                'before_pct'      => $bUnitPct,
                'after_pct'       => $aUnitPct,
                'delta_pct'       => round($aUnitPct - $bUnitPct, 1),
            ],
            'paralels'         => $paralelRows,
            'changed_students' => $changedStudents,
        ];
    }
}
