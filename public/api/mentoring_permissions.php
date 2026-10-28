<?php
/**
 * API: Kelola Izin Akses Guru ke Modul Mentoring
 * Endpoint: /public/api/mentoring_permissions.php
 *
 * GET:
 *   Mengembalikan daftar guru (khusus unit kepala sekolah bersangkutan, atau seluruh unit untuk super_admin)
 *   beserta flag `has_access` (true/false) untuk kemudahan checklist UI.
 *
 * POST:
 *   action: 'grant' | 'revoke'
 *   teacher_user_id: int
 *
 * Multi-tenant Scoping:
 *   - Kepala sekolah dibatasi hanya dapat mengelola guru yang bertugas pada unit sekolahnya.
 *   - Super admin dapat mengelola semua guru.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();

$currentRole = currentRole();
$currentUserId = currentUserId();

// ── Validasi Role: hanya super_admin atau kepala_sekolah ──────────────────
$allowedGrantors = ['super_admin', 'admin', 'kepala_sekolah'];
if (!in_array($currentRole, $allowedGrantors, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya Kepala Sekolah atau Super Admin yang dapat mengelola izin guru mentoring.']);
    exit;
}

// ── Ambil unit_id pemberi izin (untuk kepala_sekolah) ────────────────────
$grantorUnitId = null;
if ($currentRole === 'kepala_sekolah') {
    try {
        $stmtUnit = $pdo->prepare(
            "SELECT s.unit_id FROM staff s WHERE s.user_id = ? OR s.nik = (SELECT username FROM users WHERE id = ?) LIMIT 1"
        );
        $stmtUnit->execute([$currentUserId, $currentUserId]);
        $grantorUnitId = (int)($stmtUnit->fetchColumn() ?: 0) ?: null;
    } catch (Exception $e) {
        $grantorUnitId = null;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET: list semua guru di unit dengan status izin mentoring ─────────────
if ($method === 'GET') {
    try {
        $sql = "
            SELECT u.id, u.name, u.username, u.role, un.unit AS unit_name, s.unit_id,
                   CASE WHEN mtp.id IS NOT NULL THEN 1 ELSE 0 END AS has_access
            FROM users u
            LEFT JOIN staff s ON (s.user_id = u.id OR s.nik = u.username)
            LEFT JOIN units un ON un.id = s.unit_id
            LEFT JOIN mentoring_teacher_permissions mtp ON mtp.user_id = u.id
            WHERE u.deleted_at IS NULL
              AND u.role IN ('staff', 'teacher', 'guru')
        ";
        $params = [];
        if ($currentRole === 'kepala_sekolah' && $grantorUnitId) {
            $sql .= " AND s.unit_id = ?";
            $params[] = $grantorUnitId;
        }

        $sql .= " ORDER BY u.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format data
        $data = array_map(function($r) {
            return [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'username' => $r['username'],
                'role' => $r['role'],
                'unit_id' => $r['unit_id'] ? (int)$r['unit_id'] : null,
                'unit_name' => $r['unit_name'] ?: 'Umum',
                'has_access' => (bool)$r['has_access']
            ];
        }, $rows);

        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        error_log("mentoring_permissions GET error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal memuat data guru.']);
    }
    exit;
}

// ── POST: grant / revoke ──────────────────────────────────────────────────
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!$body) {
        $body = $_POST;
    }

    $action        = trim($body['action'] ?? '');
    $teacherUserId = (int)($body['teacher_user_id'] ?? 0);

    if (!in_array($action, ['grant', 'revoke'], true) || $teacherUserId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parameter tidak valid.']);
        exit;
    }

    // Verifikasi user yang dituju
    try {
        $stmtCheck = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmtCheck->execute([$teacherUserId]);
        $targetUser = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("mentoring_permissions user check error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal memeriksa data pengguna.']);
        exit;
    }

    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Guru tidak ditemukan.']);
        exit;
    }

    // Tenant check jika kepala_sekolah
    if ($currentRole === 'kepala_sekolah' && $grantorUnitId) {
        try {
            $stmtUnitCheck = $pdo->prepare("SELECT unit_id FROM staff WHERE user_id = ? OR nik = (SELECT username FROM users WHERE id = ?) LIMIT 1");
            $stmtUnitCheck->execute([$teacherUserId, $teacherUserId]);
            $tUnit = $stmtUnitCheck->fetchColumn();
            if ($tUnit && (int)$tUnit !== (int)$grantorUnitId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Guru berasal dari unit yang berbeda dengan Kepala Sekolah.']);
                exit;
            }
        } catch (Exception $e) {
            error_log("mentoring_permissions tenant check error: " . $e->getMessage());
        }
    }

    if ($action === 'grant') {
        $ok = grantMentoringTeacherPermission($pdo, $teacherUserId, $currentUserId, $grantorUnitId);
        if ($ok) {
            echo json_encode(['success' => true, 'message' => "Izin mentoring diberikan kepada {$targetUser['name']}."]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal memberikan izin.']);
        }
    } else {
        $ok = revokeMentoringTeacherPermission($pdo, $teacherUserId);
        if ($ok) {
            echo json_encode(['success' => true, 'message' => "Izin mentoring dicabut dari {$targetUser['name']}."]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal mencabut izin.']);
        }
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
