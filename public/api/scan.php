<?php
header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

use App\Services\AttendanceService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metode HTTP harus POST.'
    ]);
    exit;
}

// Support raw JSON body atau form-data POST
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$code = '';
if (is_array($data) && !empty($data['code'])) {
    $code = trim($data['code']);
} elseif (!empty($_POST['code'])) {
    $code = trim($_POST['code']);
}

if ($code === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'empty',
        'message' => 'Kode barcode / NIS / NIK tidak boleh kosong!'
    ]);
    exit;
}

try {
    $scannedBy = currentUserId();
    $result = AttendanceService::processScan($pdo, $code, $scannedBy);

    if (!($result['success'] ?? false)) {
        http_response_code(404);
    } else {
        http_response_code(200);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'server_error',
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
