<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

// Proteksi Ketat: Hanya Super Admin yang berhak memonitor aktivitas & log
requireSuperAdmin();

$pageTitle = 'Monitoring & Log Aktivitas Pengguna';
$currentUserId = currentUserId();
$currentLogId = (int)($_SESSION['login_log_id'] ?? 0);

// =========================================================================
// 1. ENDPOINT AJAX: HEARTBEAT (Keep-Alive Sesi Pengguna)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'heartbeat') {
    header('Content-Type: application/json');
    if ($currentUserId > 0) {
        recordUserActivity($pdo, $currentUserId);
    }
    // Cek apakah sesi saat ini telah diputus
    $isRevoked = false;
    if ($currentLogId > 0) {
        $stmtStatus = $pdo->prepare("SELECT status FROM user_login_logs WHERE id = ?");
        $stmtStatus->execute([$currentLogId]);
        $st = $stmtStatus->fetchColumn();
        if ($st === 'revoked' || $st === 'logged_out') {
            $isRevoked = true;
        }
    }
    echo json_encode(['status' => $isRevoked ? 'revoked' : 'ok', 'time' => date('Y-m-d H:i:s')]);
    exit;
}

// =========================================================================
// 2. ENDPOINT AJAX: FORCE LOGOUT (Putuskan Sesi Tertentu)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'force_logout') {
    header('Content-Type: application/json');
    $logId = (int)($_POST['log_id'] ?? 0);
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    // Mencegah super admin memutuskan sesi yang sedang dia gunakan di browser ini
    if ($currentLogId > 0 && $logId === $currentLogId) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Anda tidak dapat memutuskan sesi Anda sendiri yang sedang aktif pada browser ini.'
        ]);
        exit;
    }

    try {
        if ($logId > 0) {
            $stmt = $pdo->prepare("UPDATE user_login_logs SET status = 'revoked', logout_at = NOW() WHERE id = ?");
            $stmt->execute([$logId]);
        }

        if ($targetUserId > 0) {
            // Cek apakah user target masih punya sesi 'online' lain
            $stmtCheckOther = $pdo->prepare("
                SELECT COUNT(*) FROM user_login_logs 
                WHERE user_id = ? AND status = 'online' AND id != ?
            ");
            $stmtCheckOther->execute([$targetUserId, $logId]);
            $otherActiveCount = (int)$stmtCheckOther->fetchColumn();

            if ($otherActiveCount === 0) {
                $stmtUser = $pdo->prepare("UPDATE users SET is_online = 0 WHERE id = ?");
                $stmtUser->execute([$targetUserId]);
            }
        }

        echo json_encode([
            'status' => 'success', 
            'message' => 'Sesi berhasil diputuskan secara permanen. Perangkat tersebut telah dikeluarkan dari sistem.'
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal memutuskan sesi: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 2B. ENDPOINT AJAX: FORCE LOGOUT SELURUH SESI USER (Putuskan Semua Sesi User)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'force_logout_user') {
    header('Content-Type: application/json');
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    try {
        if ($targetUserId > 0) {
            // Revoke semua sesi user ini kecuali sesi yang sedang dipakai superadmin saat ini
            $stmt = $pdo->prepare("
                UPDATE user_login_logs 
                SET status = 'revoked', logout_at = NOW() 
                WHERE user_id = ? AND status = 'online' AND id != ?
            ");
            $stmt->execute([$targetUserId, $currentLogId]);
            $revokedCount = $stmt->rowCount();

            // Jika bukan superadmin yang sedang aktif, set is_online = 0
            if ($targetUserId !== $currentUserId) {
                $pdo->prepare("UPDATE users SET is_online = 0 WHERE id = ?")->execute([$targetUserId]);
            }

            echo json_encode([
                'status' => 'success', 
                'message' => "Berhasil memutuskan {$revokedCount} sesi untuk pengguna tersebut!"
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'User ID tidak valid.']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 2C. ENDPOINT AJAX: PUTUSKAN SEMUA SESI SUPER ADMIN LAIN (KICK PEMBAJAK)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'kick_all_other_superadmins') {
    header('Content-Type: application/json');

    try {
        // Revoke seluruh sesi Super Admin selain perangkat ini
        $stmt = $pdo->prepare("
            UPDATE user_login_logs 
            SET status = 'revoked', logout_at = NOW() 
            WHERE (is_superadmin = 1 OR role = 'super_admin') 
              AND status = 'online' 
              AND id != ?
        ");
        $stmt->execute([$currentLogId]);
        $revokedCount = $stmt->rowCount();

        echo json_encode([
            'status' => 'success', 
            'message' => "Berhasil memutuskan {$revokedCount} sesi Super Admin lain! Hanya sesi pada perangkat ini yang tetap aktif."
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 3. ENDPOINT AJAX: DATA STATUS LIVE (Auto-refresh tanpa reload)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_live_data') {
    header('Content-Type: application/json');

    $onlineThreshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));

    $countOnline = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_online = 1 AND last_activity >= '{$onlineThreshold}'")->fetchColumn();
    $countTotal = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
    $countOffline = max(0, $countTotal - $countOnline);

    $countSuperAdminOnline = (int)$pdo->query("
        SELECT COUNT(DISTINCT id) FROM user_login_logs 
        WHERE (is_superadmin = 1 OR role = 'super_admin') AND status = 'online' AND last_seen_at >= '{$onlineThreshold}'
    ")->fetchColumn();

    $today = date('Y-m-d');
    $countLoginsToday = (int)$pdo->query("SELECT COUNT(*) FROM user_login_logs WHERE DATE(login_at) = '{$today}'")->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'kpi' => [
            'online' => $countOnline,
            'offline' => $countOffline,
            'super_admin_online' => $countSuperAdminOnline,
            'logins_today' => $countLoginsToday,
        ],
        'timestamp' => date('H:i:s')
    ]);
    exit;
}

// =========================================================================
// 4. QUERY DATA UNTUK TAMPILAN HALAMAN
// =========================================================================
$onlineThreshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));
$today = date('Y-m-d');

// KPI Counts
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
$onlineUsersCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_online = 1 AND last_activity >= '{$onlineThreshold}'")->fetchColumn();
$offlineUsersCount = max(0, $totalUsers - $onlineUsersCount);
$superAdminOnlineCount = (int)$pdo->query("
    SELECT COUNT(DISTINCT id) FROM user_login_logs 
    WHERE (is_superadmin = 1 OR role = 'super_admin') AND status = 'online' AND last_seen_at >= '{$onlineThreshold}'
")->fetchColumn();
$loginsTodayCount = (int)$pdo->query("SELECT COUNT(*) FROM user_login_logs WHERE DATE(login_at) = '{$today}'")->fetchColumn();

// TAB AKTIF (superadmin_logs | users_status | all_logs)
$activeTab = $_GET['tab'] ?? 'superadmin_logs';

// A. Log Masuk Akun Super Admin
$stmtSuperLogs = $pdo->prepare("
    SELECT ull.*, u.name as full_name, u.photo
    FROM user_login_logs ull
    LEFT JOIN users u ON u.id = ull.user_id
    WHERE ull.is_superadmin = 1 OR ull.role = 'super_admin'
    ORDER BY ull.login_at DESC
    LIMIT 100
");
$stmtSuperLogs->execute();
$superAdminLogs = $stmtSuperLogs->fetchAll(PDO::FETCH_ASSOC);

// B. Status Semua User (Online / Offline)
$filterRole = trim($_GET['role'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');

$sqlUsers = "
    SELECT 
        u.id, u.username, u.name, u.role, u.photo, 
        u.last_activity, u.last_login_at, u.last_login_ip, u.is_online,
        CASE 
            WHEN u.is_online = 1 AND u.last_activity >= :threshold THEN 1 
            ELSE 0 
        END AS is_currently_online
    FROM users u
    WHERE u.deleted_at IS NULL
";
$paramsUsers = [':threshold' => $onlineThreshold];

if ($filterRole !== '') {
    $sqlUsers .= " AND u.role = :role";
    $paramsUsers[':role'] = $filterRole;
}
if ($filterStatus === 'online') {
    $sqlUsers .= " AND u.is_online = 1 AND u.last_activity >= :threshold2";
    $paramsUsers[':threshold2'] = $onlineThreshold;
} elseif ($filterStatus === 'offline') {
    $sqlUsers .= " AND (u.is_online = 0 OR u.last_activity IS NULL OR u.last_activity < :threshold2)";
    $paramsUsers[':threshold2'] = $onlineThreshold;
}
if ($searchQuery !== '') {
    $sqlUsers .= " AND (u.name LIKE :q OR u.username LIKE :q2)";
    $paramsUsers[':q'] = "%{$searchQuery}%";
    $paramsUsers[':q2'] = "%{$searchQuery}%";
}

$sqlUsers .= " ORDER BY is_currently_online DESC, u.last_activity DESC, u.name ASC";
$stmtUsers = $pdo->prepare($sqlUsers);
$stmtUsers->execute($paramsUsers);
$allUsersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// C. Semua Riwayat Log Masuk (Audit Log)
$stmtAllLogs = $pdo->prepare("
    SELECT ull.*, u.name as full_name, u.photo
    FROM user_login_logs ull
    LEFT JOIN users u ON u.id = ull.user_id
    ORDER BY ull.login_at DESC
    LIMIT 150
");
$stmtAllLogs->execute();
$allLogs = $stmtAllLogs->fetchAll(PDO::FETCH_ASSOC);

require '../../includes/header.php';
?>

<style>
/* ==========================================================================
   DESAIN MODERN: MONITORING AKTIVITAS & SESI PENGGUNA
   ========================================================================== */
.activity-page {
    animation: fadeIn 0.3s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Header & Quick Action */
.page-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 24px;
}
.page-title-box h1 {
    margin: 0;
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 12px;
}
.page-title-box p {
    margin: 4px 0 0 0;
    color: #64748b;
    font-size: 13.5px;
}

/* KPI Summary Cards Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.kpi-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.25s ease;
    position: relative;
    overflow: hidden;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
}
.kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.kpi-card.online .kpi-icon { background: rgba(16, 185, 129, 0.12); color: #10b981; }
.kpi-card.offline .kpi-icon { background: rgba(100, 116, 139, 0.12); color: #64748b; }
.kpi-card.super .kpi-icon { background: rgba(99, 102, 241, 0.12); color: #6366f1; }
.kpi-card.today .kpi-icon { background: rgba(59, 130, 246, 0.12); color: #3b82f6; }

.kpi-info h3 {
    margin: 0;
    font-size: 26px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}
.kpi-info span {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
}

/* Tabs Navigation */
.nav-tabs-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 22px;
    flex-wrap: wrap;
    gap: 12px;
}
.activity-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: -2px;
}
.act-tab-btn {
    padding: 12px 20px;
    font-weight: 700;
    font-size: 13.5px;
    color: #64748b;
    border-bottom: 3px solid transparent;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    border-radius: 8px 8px 0 0;
}
.act-tab-btn:hover {
    color: #4f46e5;
    background: #f8fafc;
}
.act-tab-btn.active {
    color: #4f46e5;
    border-bottom-color: #4f46e5;
    background: rgba(79, 70, 229, 0.05);
}
.tab-badge {
    background: #e2e8f0;
    color: #334155;
    font-size: 11px;
    padding: 2px 7px;
    border-radius: 20px;
    font-weight: 700;
}
.act-tab-btn.active .tab-badge {
    background: #4f46e5;
    color: #ffffff;
}

/* Live Pulse Indicator */
.live-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}
.live-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.live-pulse-dot {
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulseGlow 1.8s infinite;
}
@keyframes pulseGlow {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.btn-refresh {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.btn-refresh:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

/* Card Container */
.data-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    overflow: hidden;
    margin-bottom: 30px;
}
.data-card-header {
    padding: 18px 24px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: #fafafa;
}
.data-card-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Modern Data Table */
.table-responsive {
    overflow-x: auto;
    width: 100%;
}
.activity-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
    text-align: left;
}
.activity-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11.5px;
    letter-spacing: 0.5px;
    padding: 12px 18px;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}
.activity-table td {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}
.activity-table tr:hover td {
    background: #f8fafc;
}

/* User Profile in Table */
.user-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}
.user-avatar-box {
    position: relative;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
}
.user-avatar-img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    background: #e2e8f0;
}
.user-avatar-placeholder {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: #e0e7ff;
    color: #4338ca;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
}
.status-indicator-dot {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #ffffff;
}
.status-indicator-dot.online {
    background: #10b981;
    box-shadow: 0 0 6px #10b981;
}
.status-indicator-dot.offline {
    background: #cbd5e1;
}

.user-name-title {
    font-weight: 700;
    color: #0f172a;
    display: block;
    line-height: 1.3;
}
.user-username-sub {
    font-size: 12px;
    color: #64748b;
}

/* Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.2px;
}
.status-badge.online {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.status-badge.offline {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #e2e8f0;
}
.status-badge.revoked {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.role-pill {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.role-pill.super_admin { background: #ede9fe; color: #6d28d9; }
.role-pill.kepala_sekolah { background: #e0f2fe; color: #0369a1; }
.role-pill.admin { background: #dcfce7; color: #15803d; }
.role-pill.staff { background: #f1f5f9; color: #475569; }

.ip-tag {
    font-family: monospace;
    font-size: 12px;
    background: #f1f5f9;
    padding: 3px 8px;
    border-radius: 6px;
    color: #334155;
    border: 1px solid #e2e8f0;
}
.device-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12.5px;
    color: #334155;
}

/* TOMBOL PUTUSKAN SESI (PROMINENT & HIGHLIGHTED) */
.btn-force-logout {
    background: #fee2e2;
    border: 1px solid #ef4444;
    color: #b91c1c;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 1px 2px rgba(239, 68, 68, 0.15);
}
.btn-force-logout:hover {
    background: #dc2626;
    color: #ffffff;
    border-color: #b91c1c;
    box-shadow: 0 4px 10px rgba(220, 38, 38, 0.35);
    transform: translateY(-1px);
}
.badge-current-session {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #86efac;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
}

/* Filter Bar */
.filter-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}
.filter-select, .filter-input {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    font-size: 13px;
    background: #fff;
    color: #334155;
    outline: none;
}
.filter-select:focus, .filter-input:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.15);
}

/* Empty State */
.empty-state {
    padding: 40px 20px;
    text-align: center;
    color: #94a3b8;
}
.empty-state i {
    font-size: 42px;
    margin-bottom: 12px;
    color: #cbd5e1;
}

/* Super Admin Alert Banner */
.security-alert-box {
    background: #ffffff;
    border-left: 4px solid #ef4444;
    border: 1px solid #fecaca;
    border-left-width: 5px;
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    box-shadow: 0 2px 4px rgba(239, 68, 68, 0.05);
}
.security-alert-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.security-alert-icon {
    font-size: 26px;
    color: #ef4444;
}
.security-alert-content h4 {
    margin: 0 0 4px 0;
    font-size: 14px;
    font-weight: 800;
    color: #991b1b;
}
.security-alert-content p {
    margin: 0;
    font-size: 13px;
    color: #64748b;
    line-height: 1.4;
}
.btn-kick-all {
    background: #dc2626;
    color: #ffffff;
    border: 1px solid #b91c1c;
    padding: 9px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: 0.2s;
    box-shadow: 0 2px 4px rgba(220, 38, 38, 0.2);
}
.btn-kick-all:hover {
    background: #b91c1c;
    box-shadow: 0 4px 10px rgba(185, 28, 28, 0.35);
}
</style>

<div class="activity-page">

    <!-- Top Header -->
    <div class="page-header-row">
        <div class="page-title-box">
            <h1><i class="fa-solid fa-user-clock" style="color: #4f46e5;"></i> Monitoring & Log Aktivitas Pengguna</h1>
            <p>Pantau pengguna online/offline secara real-time dan audit seluruh akses login ke akun Super Admin.</p>
        </div>
        <div class="live-controls">
            <span class="live-badge">
                <span class="live-pulse-dot"></span> LIVE MONITORING
            </span>
            <button class="btn-refresh" id="btnManualRefresh" onclick="refreshLiveData(true)" title="Segarkan Data Sekarang">
                <i class="fa-solid fa-arrows-rotate" id="refreshIcon"></i> Segarkan (<span id="refreshTimer">10</span>s)
            </button>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="kpi-grid">
        <div class="kpi-card online" style="cursor: pointer;" onclick="filterByStatus('online')">
            <div class="kpi-icon"><i class="fa-solid fa-user-check"></i></div>
            <div class="kpi-info">
                <h3 id="kpiOnline"><?= number_format($onlineUsersCount) ?></h3>
                <span>Pengguna Online</span>
            </div>
        </div>
        <div class="kpi-card offline" style="cursor: pointer;" onclick="filterByStatus('offline')">
            <div class="kpi-icon"><i class="fa-solid fa-user-xmark"></i></div>
            <div class="kpi-info">
                <h3 id="kpiOffline"><?= number_format($offlineUsersCount) ?></h3>
                <span>Pengguna Offline</span>
            </div>
        </div>
        <div class="kpi-card super" style="cursor: pointer;" onclick="switchTab('superadmin_logs')">
            <div class="kpi-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div class="kpi-info">
                <h3 id="kpiSuperAdmin"><?= number_format($superAdminOnlineCount) ?></h3>
                <span>Sesi Super Admin Aktif</span>
            </div>
        </div>
        <div class="kpi-card today" style="cursor: pointer;" onclick="switchTab('all_logs')">
            <div class="kpi-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div class="kpi-info">
                <h3 id="kpiLoginsToday"><?= number_format($loginsTodayCount) ?></h3>
                <span>Login Hari Ini</span>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="nav-tabs-wrapper">
        <div class="activity-tabs">
            <a href="?tab=superadmin_logs" class="act-tab-btn <?= $activeTab === 'superadmin_logs' ? 'active' : '' ?>">
                <i class="fa-solid fa-shield-halved"></i> Log Masuk Super Admin
                <span class="tab-badge"><?= count($superAdminLogs) ?></span>
            </a>
            <a href="?tab=users_status" class="act-tab-btn <?= $activeTab === 'users_status' ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i> Status Online / Offline Pengguna
                <span class="tab-badge"><?= count($allUsersList) ?></span>
            </a>
            <a href="?tab=all_logs" class="act-tab-btn <?= $activeTab === 'all_logs' ? 'active' : '' ?>">
                <i class="fa-solid fa-list-check"></i> Semua Riwayat Login
                <span class="tab-badge"><?= count($allLogs) ?></span>
            </a>
        </div>
    </div>

    <?php if ($activeTab === 'superadmin_logs'): ?>
        <!-- ========================================================================= -->
        <!-- TAB 1: LOG MASUK AKUN SUPER ADMIN (AUDIT KEAMANAN & ANTI-BAJAK)            -->
        <!-- ========================================================================= -->
        <div class="security-alert-box">
            <div class="security-alert-left">
                <div class="security-alert-icon"><i class="fa-solid fa-shield-virus"></i></div>
                <div class="security-alert-content">
                    <h4>Kontrol Keamanan Akun Super Admin</h4>
                    <p>Jika Anda melihat akun Super Admin dimasuki dari IP/perangkat yang tidak Anda kenali (indikasi pembajakan), klik tombol <strong>Putuskan Sesi</strong> untuk langsung menendang akun tersebut keluar dari sistem secara permanen.</p>
                </div>
            </div>
            <div>
                <button type="button" onclick="kickAllOtherSuperadmins()" class="btn-kick-all" title="Tendang semua sesi superadmin lain">
                    <i class="fa-solid fa-ban"></i> Putuskan Semua Sesi Super Admin Lain
                </button>
            </div>
        </div>

        <div class="data-card">
            <div class="data-card-header">
                <h3><i class="fa-solid fa-shield-halved" style="color: #6366f1;"></i> Riwayat Masuk Akun Super Admin</h3>
                <span style="font-size: 13px; color: #64748b;">Menampilkan 100 entri login terbaru</span>
            </div>
            <div class="table-responsive">
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>Waktu Masuk</th>
                            <th>Akun Super Admin</th>
                            <th>Alamat IP</th>
                            <th>Perangkat & Browser</th>
                            <th>Sistem Operasi</th>
                            <th>Status Sesi</th>
                            <th>Terakhir Terlihat</th>
                            <th style="min-width: 150px;">Aksi / Putuskan Sesi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($superAdminLogs)): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-shield-cat"></i>
                                        <p>Belum ada riwayat login Super Admin yang tercatat.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($superAdminLogs as $log): 
                                $isCurrentSession = ($currentLogId > 0 && $currentLogId === (int)$log['id']);
                                $isCurrentlyActive = ($log['status'] === 'online' && strtotime($log['last_seen_at']) >= strtotime('-5 minutes'));
                                $isRevoked = ($log['status'] === 'revoked');
                                $isLoggedOut = ($log['status'] === 'logged_out');
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: #0f172a;"><?= date('d/m/Y', strtotime($log['login_at'])) ?></div>
                                        <div style="font-size: 12px; color: #64748b;"><?= date('H:i:s', strtotime($log['login_at'])) ?> WIB</div>
                                    </td>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar-box">
                                                <?php if (!empty($log['photo']) && file_exists(UPLOAD_PATH . '/admins/' . $log['photo'])): ?>
                                                    <img src="<?= UPLOAD_URL ?>/admins/<?= e($log['photo']) ?>" class="user-avatar-img">
                                                <?php else: ?>
                                                    <div class="user-avatar-placeholder">
                                                        <?= strtoupper(substr($log['username'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="status-indicator-dot <?= $isCurrentlyActive ? 'online' : 'offline' ?>"></span>
                                            </div>
                                            <div>
                                                <span class="user-name-title"><?= e($log['full_name'] ?? $log['username']) ?></span>
                                                <span class="user-username-sub">@<?= e($log['username']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="ip-tag"><i class="fa-solid fa-network-wired" style="margin-right: 4px;"></i> <?= e($log['ip_address']) ?></span>
                                        <?php if ($log['ip_address'] === '127.0.0.1' || $log['ip_address'] === '::1'): ?>
                                            <span style="font-size: 11px; color: #64748b; display: block; margin-top: 2px;">(Localhost / Server)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="device-badge">
                                            <?php 
                                            $deviceIcon = 'fa-desktop';
                                            if ($log['device_type'] === 'Mobile') $deviceIcon = 'fa-mobile-screen';
                                            elseif ($log['device_type'] === 'Tablet') $deviceIcon = 'fa-tablet-screen-button';
                                            ?>
                                            <i class="fa-solid <?= $deviceIcon ?>" style="color: #6366f1;"></i>
                                            <span><?= e($log['device_type'] ?? 'Desktop') ?></span>
                                        </div>
                                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                            <?= e($log['browser'] ?? 'Browser') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: #334155; font-size: 13px;">
                                            <i class="fa-brands fa-windows" style="color: #0284c7; margin-right: 4px;"></i>
                                            <?= e($log['platform'] ?? 'OS') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isRevoked): ?>
                                            <span class="status-badge revoked">
                                                <i class="fa-solid fa-ban"></i> Sesi Diputus
                                            </span>
                                        <?php elseif ($isCurrentlyActive): ?>
                                            <span class="status-badge online">
                                                <span class="live-pulse-dot"></span> SEDANG AKTIF
                                            </span>
                                        <?php elseif ($isLoggedOut): ?>
                                            <span class="status-badge offline">
                                                <i class="fa-solid fa-right-from-bracket"></i> Sudah Logout
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge offline">
                                                <i class="fa-solid fa-clock"></i> Sesi Berakhir
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span title="<?= e($log['last_seen_at']) ?>">
                                            <?= formatTimeAgo($log['last_seen_at']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isCurrentSession): ?>
                                            <span class="badge-current-session">
                                                <i class="fa-solid fa-laptop"></i> Sesi Anda (Aktif)
                                            </span>
                                        <?php elseif ($isRevoked): ?>
                                            <span style="font-size: 12px; color: #dc2626; font-weight: 600;">
                                                <i class="fa-solid fa-circle-check"></i> Telah Diputus
                                            </span>
                                        <?php elseif ($isLoggedOut): ?>
                                            <span style="font-size: 12px; color: #94a3b8;">
                                                Sesi Selesai
                                            </span>
                                        <?php else: ?>
                                            <!-- Tombol Putuskan Sesi untuk sesi lain (termasuk superadmin pembajak) -->
                                            <button type="button" class="btn-force-logout" onclick="forceLogout(<?= $log['id'] ?>, <?= $log['user_id'] ?>, '<?= e($log['username']) ?>', '<?= e($log['ip_address']) ?>', '<?= e($log['device_type'] . ' - ' . $log['browser']) ?>')">
                                                <i class="fa-solid fa-ban"></i> Putuskan Sesi
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($activeTab === 'users_status'): ?>
        <!-- ========================================================================= -->
        <!-- TAB 2: STATUS ONLINE / OFFLINE SEMUA PENGGUNA                              -->
        <!-- ========================================================================= -->
        <div class="data-card">
            <div class="data-card-header">
                <form method="GET" class="filter-row" style="width: 100%;">
                    <input type="hidden" name="tab" value="users_status">

                    <div style="flex: 1; min-width: 200px;">
                        <input type="text" name="q" value="<?= e($searchQuery) ?>" class="filter-input" placeholder="🔍 Cari nama atau username..." style="width: 100%;">
                    </div>

                    <div>
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="">Semua Status</option>
                            <option value="online" <?= $filterStatus === 'online' ? 'selected' : '' ?>>🟢 Sedang Online</option>
                            <option value="offline" <?= $filterStatus === 'offline' ? 'selected' : '' ?>>⚪ Sedang Offline</option>
                        </select>
                    </div>

                    <div>
                        <select name="role" class="filter-select" onchange="this.form.submit()">
                            <option value="">Semua Role</option>
                            <option value="super_admin" <?= $filterRole === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                            <option value="kepala_sekolah" <?= $filterRole === 'kepala_sekolah' ? 'selected' : '' ?>>Kepala Sekolah</option>
                            <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Admin Pemantau</option>
                            <option value="staff" <?= $filterRole === 'staff' ? 'selected' : '' ?>>Staff / Guru</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-refresh" style="background: #4f46e5; color: #fff; border-color: #4f46e5;">
                        <i class="fa-solid fa-filter"></i> Filter
                    </button>
                    <?php if ($searchQuery !== '' || $filterStatus !== '' || $filterRole !== ''): ?>
                        <a href="?tab=users_status" class="btn-refresh" style="text-decoration: none;">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>Pengguna</th>
                            <th>Role / Hak Akses</th>
                            <th>Status Sekarang</th>
                            <th>Terakhir Aktif</th>
                            <th>Login Terakhir</th>
                            <th>IP Login Terakhir</th>
                            <th>Aksi / Putuskan Sesi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allUsersList)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-users-slash"></i>
                                        <p>Tidak ada pengguna yang cocok dengan filter yang dipilih.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allUsersList as $u): 
                                $isOnline = (int)$u['is_currently_online'] === 1;
                                $folder = ($u['role'] === 'super_admin' || $u['role'] === 'admin') ? 'admins' : 'staff';
                                $isMe = ((int)$u['id'] === $currentUserId);
                            ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar-box">
                                                <?php if (!empty($u['photo']) && file_exists(UPLOAD_PATH . '/' . $folder . '/' . $u['photo'])): ?>
                                                    <img src="<?= UPLOAD_URL ?>/<?= $folder ?>/<?= e($u['photo']) ?>" class="user-avatar-img">
                                                <?php else: ?>
                                                    <div class="user-avatar-placeholder">
                                                        <?= strtoupper(substr($u['name'] ?? $u['username'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="status-indicator-dot <?= $isOnline ? 'online' : 'offline' ?>"></span>
                                            </div>
                                            <div>
                                                <span class="user-name-title"><?= e($u['name']) ?></span>
                                                <span class="user-username-sub">@<?= e($u['username']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-pill <?= e($u['role']) ?>">
                                            <?= str_replace('_', ' ', e($u['role'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isOnline): ?>
                                            <span class="status-badge online">
                                                <span class="live-pulse-dot"></span> ONLINE
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge offline">
                                                <i class="fa-solid fa-circle" style="font-size: 8px; color: #94a3b8;"></i> OFFLINE
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isOnline): ?>
                                            <span style="color: #10b981; font-weight: 700;">Aktif sekarang</span>
                                        <?php else: ?>
                                            <span style="color: #64748b;">
                                                <?= formatTimeAgo($u['last_activity']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($u['last_login_at'])): ?>
                                            <div style="font-weight: 600; color: #334155; font-size: 13px;">
                                                <?= date('d/m/Y H:i', strtotime($u['last_login_at'])) ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 12px;">Belum pernah login</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($u['last_login_ip'])): ?>
                                            <span class="ip-tag"><?= e($u['last_login_ip']) ?></span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 12px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isMe): ?>
                                            <span class="badge-current-session">
                                                <i class="fa-solid fa-check"></i> Akun Anda
                                            </span>
                                        <?php elseif ($isOnline): ?>
                                            <button type="button" class="btn-force-logout" onclick="forceLogoutUser(<?= $u['id'] ?>, '<?= e($u['name']) ?>')">
                                                <i class="fa-solid fa-ban"></i> Putuskan Sesi
                                            </button>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($activeTab === 'all_logs'): ?>
        <!-- ========================================================================= -->
        <!-- TAB 3: SEMUA RIWAYAT LOG MASUK SISTEM (AUDIT LOG LENGKAP)                  -->
        <!-- ========================================================================= -->
        <div class="data-card">
            <div class="data-card-header">
                <h3><i class="fa-solid fa-clock-rotate-left" style="color: #3b82f6;"></i> Semua Riwayat Login Pengguna</h3>
                <span style="font-size: 13px; color: #64748b;">Menampilkan 150 entri login terakhir</span>
            </div>
            <div class="table-responsive">
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>Waktu Masuk</th>
                            <th>Pengguna</th>
                            <th>Role</th>
                            <th>Alamat IP</th>
                            <th>Perangkat & Browser</th>
                            <th>Status Sesi</th>
                            <th>Terakhir Aktif</th>
                            <th style="min-width: 140px;">Aksi / Putuskan Sesi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allLogs)): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-clock-rotate-left"></i>
                                        <p>Belum ada riwayat login yang tercatat.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allLogs as $log): 
                                $isCurrent = ($currentLogId > 0 && $currentLogId === (int)$log['id']);
                                $isRevoked = ($log['status'] === 'revoked');
                                $isLoggedOut = ($log['status'] === 'logged_out');
                                $isOnline = ($log['status'] === 'online');
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: #0f172a;"><?= date('d/m/Y', strtotime($log['login_at'])) ?></div>
                                        <div style="font-size: 12px; color: #64748b;"><?= date('H:i:s', strtotime($log['login_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="user-cell">
                                            <div>
                                                <span class="user-name-title"><?= e($log['full_name'] ?? $log['username']) ?></span>
                                                <span class="user-username-sub">@<?= e($log['username']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-pill <?= e($log['role']) ?>">
                                            <?= str_replace('_', ' ', e($log['role'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="ip-tag"><?= e($log['ip_address']) ?></span>
                                    </td>
                                    <td>
                                        <div class="device-badge">
                                            <span><?= e($log['device_type'] ?? 'Desktop') ?></span> • 
                                            <span><?= e($log['browser'] ?? 'Browser') ?></span>
                                        </div>
                                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                            <?= e($log['platform'] ?? 'OS') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($isRevoked): ?>
                                            <span class="status-badge revoked"><i class="fa-solid fa-ban"></i> Diputus</span>
                                        <?php elseif ($isOnline): ?>
                                            <span class="status-badge online"><span class="live-pulse-dot"></span> Online</span>
                                        <?php elseif ($isLoggedOut): ?>
                                            <span class="status-badge offline">Logout</span>
                                        <?php else: ?>
                                            <span class="status-badge offline">Expired</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= formatTimeAgo($log['last_seen_at']) ?>
                                    </td>
                                    <td>
                                        <?php if ($isCurrent): ?>
                                            <span class="badge-current-session">Sesi Anda</span>
                                        <?php elseif ($isRevoked): ?>
                                            <span style="font-size: 12px; color: #dc2626; font-weight: 600;">Telah Diputus</span>
                                        <?php elseif ($isLoggedOut): ?>
                                            <span style="font-size: 12px; color: #94a3b8;">-</span>
                                        <?php else: ?>
                                            <button type="button" class="btn-force-logout" onclick="forceLogout(<?= $log['id'] ?>, <?= $log['user_id'] ?>, '<?= e($log['username']) ?>', '<?= e($log['ip_address']) ?>', '<?= e($log['device_type']) ?>')">
                                                <i class="fa-solid fa-ban"></i> Putuskan Sesi
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- JAVASCRIPT: AUTO-REFRESH & FORCE LOGOUT -->
<script>
let countdownSeconds = 10;
let refreshInterval = null;

function updateCountdown() {
    countdownSeconds--;
    const timerEl = document.getElementById('refreshTimer');
    if (timerEl) timerEl.innerText = countdownSeconds;

    if (countdownSeconds <= 0) {
        countdownSeconds = 10;
        refreshLiveData(false);
    }
}

function refreshLiveData(isManual = false) {
    const icon = document.getElementById('refreshIcon');
    if (icon) icon.classList.add('fa-spin');

    fetch('activity.php?action=get_live_data')
        .then(response => response.json())
        .then(data => {
            if (icon) icon.classList.remove('fa-spin');
            if (data.status === 'success') {
                // Update KPI Cards
                const kpiOnline = document.getElementById('kpiOnline');
                const kpiOffline = document.getElementById('kpiOffline');
                const kpiSuper = document.getElementById('kpiSuperAdmin');
                const kpiLogins = document.getElementById('kpiLoginsToday');

                if (kpiOnline) kpiOnline.innerText = data.kpi.online;
                if (kpiOffline) kpiOffline.innerText = data.kpi.offline;
                if (kpiSuper) kpiSuper.innerText = data.kpi.super_admin_online;
                if (kpiLogins) kpiLogins.innerText = data.kpi.logins_today;

                if (isManual) {
                    window.location.reload();
                }
            }
        })
        .catch(err => {
            if (icon) icon.classList.remove('fa-spin');
        });
}

function switchTab(tab) {
    window.location.href = '?tab=' + tab;
}

function filterByStatus(status) {
    window.location.href = '?tab=users_status&status=' + status;
}

// 1. Putuskan satu sesi login tertentu
function forceLogout(logId, userId, username, ip, device) {
    let msg = 'Apakah Anda yakin ingin MEMUTUSKAN SESI login ini?\n\n' +
              '• Akun: @' + username + '\n' +
              '• IP: ' + (ip || '-') + '\n' +
              (device ? '• Perangkat: ' + device + '\n' : '') +
              '\nPerangkat/pembajak tersebut akan LANGSUNG ditendang keluar dari sistem saat melakukan aktivitas berikutnya.';

    if (!confirm(msg)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'force_logout');
    formData.append('log_id', logId);
    formData.append('user_id', userId);

    fetch('activity.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        alert(res.message);
        if (res.status === 'success') {
            window.location.reload();
        }
    })
    .catch(err => {
        alert('Terjadi kesalahan koneksi.');
    });
}

// 2. Putuskan seluruh sesi seorang user
function forceLogoutUser(userId, name) {
    if (!confirm('Putuskan seluruh sesi aktif untuk pengguna "' + name + '"?\n\nPengguna akan segera ditandai offline dan keluar dari sistem.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'force_logout_user');
    formData.append('user_id', userId);

    fetch('activity.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        alert(res.message);
        if (res.status === 'success') {
            window.location.reload();
        }
    })
    .catch(err => {
        alert('Terjadi kesalahan koneksi.');
    });
}

// 3. Putuskan semua sesi Super Admin lain (Tangani pembajakan akun)
function kickAllOtherSuperadmins() {
    let msg = '🚨 PERINGATAN KEAMANAN!\n\n' +
              'Anda akan memutuskan SEMUA sesi Super Admin lain yang saat ini sedang aktif di browser atau perangkat lain.\n\n' +
              'Hanya sesi di perangkat ini yang akan tetap aktif. Jika ada orang lain/pembajak yang sedang masuk ke akun Super Admin, mereka akan langsung dikeluarkan!\n\n' +
              'Lanjutkan pemutusan sesi massal?';

    if (!confirm(msg)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'kick_all_other_superadmins');

    fetch('activity.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        alert(res.message);
        if (res.status === 'success') {
            window.location.reload();
        }
    })
    .catch(err => {
        alert('Terjadi kesalahan koneksi.');
    });
}

// Mulai timer auto-refresh
refreshInterval = setInterval(updateCountdown, 1000);
</script>

<?php require '../../includes/footer.php'; ?>
