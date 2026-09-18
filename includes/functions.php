<?php

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function flash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlash() {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}


function uploadStudentPhoto($file) {
    if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return null;
    }

    $extension = $allowed[$mime];
    $filename = uniqid('student_', true) . '.' . $extension;

    global $uploadStudentPath;
    if (!is_dir($uploadStudentPath)) {
        mkdir($uploadStudentPath, 0755, true);
    }

    move_uploaded_file($file['tmp_name'], $uploadStudentPath . $filename);
    return $filename;
}

/*
|--------------------------------------------------------------------------
| ACCESS CONTROL SISWA (SUDAH DISESUAIKAN DENGAN UNIT KEPALA SEKOLAH)
|--------------------------------------------------------------------------
*/
function getStudentAccessCondition($gradeAlias = 'g', $classAlias = 'cg') {
    if (($_SESSION['role'] ?? '') === 'super_admin') {
        return [
            'condition' => '1 = 1',
            'params' => []
        ];
    }

    // Role Kepala Sekolah / Admin akan membaca semua izin (Unit, Grade, atau Subkelas)
    return [
        'condition' => "(
            {$gradeAlias}.unit_id IN (SELECT unit_id FROM admin_unit_permissions WHERE user_id = ?)
            OR {$gradeAlias}.id IN (SELECT grade_id FROM admin_grade_permissions WHERE user_id = ?)
            OR {$classAlias}.id IN (SELECT class_group_id FROM admin_class_permissions WHERE user_id = ?)
        )",
        'params' => [
            currentUserId(),
            currentUserId(),
            currentUserId()
        ]
    ];
}

/*
|--------------------------------------------------------------------------
| PERIODE LAPORAN
|--------------------------------------------------------------------------
*/
function getReportPeriod() {
    $preset = $_GET['preset'] ?? 'month';
    $today = new DateTime();

    if ($preset === 'today') {
        return [
            'start' => $today->format('Y-m-d'),
            'end' => $today->format('Y-m-d')
        ];
    }

    if ($preset === 'week') {
        $monday = new DateTime('monday this week');
        $sunday = new DateTime('sunday this week');
        return [
            'start' => $monday->format('Y-m-d'),
            'end' => $sunday->format('Y-m-d')
        ];
    }

    if ($preset === 'month') {
        return [
            'start' => $today->format('Y-m-01'),
            'end' => $today->format('Y-m-t')
        ];
    }

    $start = $_GET['start_date'] ?? $today->format('Y-m-01');
    $end = $_GET['end_date'] ?? $today->format('Y-m-d');

    return [
        'start' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : $today->format('Y-m-01'),
        'end' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? $end : $today->format('Y-m-d')
    ];
}

/*
|--------------------------------------------------------------------------
| URL QUERY STRING
|--------------------------------------------------------------------------
*/
function buildQuery(array $params = []) {
    return http_build_query(
        array_merge($_GET, $params)
    );
}