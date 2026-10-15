<?php
/**
 * API Adapter: Menghubungkan Antarmuka Mentoring MIP ke Basis Data MariaDB
 * Menggantikan panggilan Google Apps Script (google.script.run)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    // 1. GET SISWA DATA
    if ($action === 'getSiswaData') {
        $stmt = $pdo->query("
            SELECT s.nis as col1, s.name as col2, cg.name as col3, u.name as col4, '' as col5
            FROM students s
            LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
            LEFT JOIN class_groups cg ON cg.id = se.class_group_id
            LEFT JOIN mentoring_assignments ma ON ma.student_id = s.id AND ma.is_active = 1
            LEFT JOIN users u ON u.id = ma.mentor_user_id
            WHERE s.deleted_at IS NULL
            ORDER BY cg.name ASC, s.name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // 2. GET USERS DATA
    if ($action === 'getUsersData') {
        $stmt = $pdo->query("
            SELECT name as username, 'password123' as password, 
                   CASE WHEN role IN ('super_admin', 'kepala_sekolah', 'admin') THEN 'admin' ELSE 'mentor' END as role
            FROM users
            WHERE role IN ('staff', 'admin', 'kepala_sekolah', 'super_admin')
            ORDER BY name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // 3. GET BANK MATERI
    if ($action === 'getBankMateri') {
        $stmt = $pdo->query("
            SELECT id, title as judul, 
                   CONCAT('Kelas ', grade_level) as kelas,
                   week_code as pekan,
                   description as deskripsi,
                   file_link as fileUrl,
                   task_1, task_2, task_3, task_4,
                   reflection_prompt_1 as reflection_1, reflection_prompt_2 as reflection_2, 
                   reflection_prompt_3 as reflection_3, reflection_prompt_4 as reflection_4
            FROM mentoring_curriculums
            WHERE is_active = 1
            ORDER BY grade_level ASC, week_code ASC
        ");
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $list = [];
        foreach ($raw as $r) {
            $tasks = array_values(array_filter([$r['task_1'], $r['task_2'], $r['task_3'], $r['task_4']]));
            $refs = array_values(array_filter([$r['reflection_1'], $r['reflection_2'], $r['reflection_3'], $r['reflection_4']]));
            $list[] = [
                'id' => (int)$r['id'],
                'judul' => $r['judul'],
                'kelas' => $r['kelas'],
                'pekan' => $r['pekan'],
                'deskripsi' => $r['deskripsi'],
                'fileUrl' => $r['fileUrl'] ?: '',
                'penugasanTasks' => !empty($tasks) ? $tasks : [
                    "Niat ikhlas & ibadah harian.",
                    "Shalat berjamaah tepat waktu.",
                    "Tilawah Al-Qur'an harian.",
                    "Menjaga lisan & sopan santun."
                ],
                'refleksiQuestions' => !empty($refs) ? $refs : [
                    "Apa aksi nyata adab yang telah dilakukan?",
                    "Apa hambatan terbesar dalam menjaga adab?",
                    "Bagaimana rencana perbaikan esok hari?",
                    "Apa catatan refleksi & rasa syukur hari ini?"
                ]
            ];
        }
        echo json_encode(['success' => true, 'data' => $list]);
        exit;
    }

    // 4. GET REKAP DATA (HISTORICAL MENTORING SESSIONS)
    if ($action === 'getRekapData') {
        $stmt = $pdo->query("
            SELECT ms.id, ms.session_date as tanggal, '2026/2027' as tahunAjaran,
                   ms.session_code as sesiKey, ms.month_name as bulan,
                   s.name as mentee, s.nis, cg.name as kelas, u.name as mentor,
                   ms.score_mip as scoreMIP, ms.notes_mip as catatanMIP,
                   ms.score_academic as scoreAkademik, ms.notes_academic as catatanAkademik,
                   ms.score_mutabaah as scoreMutabaah, ms.notes_mutabaah as catatanMutabaah,
                   ms.score_adab as scoreAdab, ms.notes_adab as catatanAdab,
                   ms.notes_feedback as catatanSaran
            FROM mentoring_sessions ms
            JOIN students s ON s.id = ms.student_id
            JOIN users u ON u.id = ms.mentor_user_id
            LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
            LEFT JOIN class_groups cg ON cg.id = se.class_group_id
            ORDER BY ms.session_date DESC, ms.id DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // 5. GET ADAB DATA (HISTORICAL STUDENT ADAB LOGS)
    if ($action === 'getAdabData') {
        $stmt = $pdo->query("
            SELECT al.id, s.nis, s.name as namaMurid, cg.name as kelas,
                   'September' as bulan, al.week_code as pekan, al.day_name as hari,
                   al.checklist_json, al.reflection_action as refleksi1,
                   al.reflection_obstacle as refleksi2, al.reflection_solution as refleksi3,
                   al.reflection_gratitude as refleksi4, al.submitted_at as timestamp
            FROM student_adab_logs al
            JOIN students s ON s.id = al.student_id
            LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.status = 'active'
            LEFT JOIN class_groups cg ON cg.id = se.class_group_id
            ORDER BY al.id DESC
            LIMIT 1000
        ");
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $store = [];
        foreach ($raw as $r) {
            $chk = json_decode($r['checklist_json'] ?? '{}', true) ?: (object)[];
            $key = "{$r['nis']}_September_{$r['pekan']}";
            $store[$key] = [
                'nis' => $r['nis'],
                'namaMurid' => $r['namaMurid'],
                'kelas' => $r['kelas'] ?: '7A',
                'bulan' => 'September',
                'pekan' => $r['pekan'],
                'hari' => $r['hari'],
                'checklist' => $chk,
                'refleksi1' => $r['refleksi1'] ?: '-',
                'refleksi2' => $r['refleksi2'] ?: '-',
                'refleksi3' => $r['refleksi3'] ?: '-',
                'refleksi4' => $r['refleksi4'] ?: '-',
                'timestamp' => $r['timestamp']
            ];
        }
        echo json_encode(['success' => true, 'data' => $store]);
        exit;
    }

    // 6. SIMPAN DATA MENTORING (POST)
    if ($action === 'simpanDataMentoring' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);

        if (!empty($payload)) {
            $items = isset($payload['mentee']) ? [$payload] : (is_array($payload) ? $payload : []);
            
            $stmtFindStudent = $pdo->prepare("SELECT id FROM students WHERE name = ? OR nis = ? LIMIT 1");
            $stmtFindMentor  = $pdo->prepare("SELECT id FROM users WHERE name = ? LIMIT 1");
            $stmtIns = $pdo->prepare("
                INSERT INTO mentoring_sessions (
                    student_id, mentor_user_id, session_date, session_code, month_name, year_val,
                    score_mip, notes_mip, score_academic, notes_academic, score_mutabaah, notes_mutabaah,
                    score_adab, notes_adab, score_feedback, notes_feedback, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW())
            ");

            foreach ($items as $d) {
                $menteeName = trim($d['mentee'] ?? '');
                $mentorName = trim($d['mentor'] ?? '');
                $stmtFindStudent->execute([$menteeName, $menteeName]);
                $studentId = $stmtFindStudent->fetchColumn();

                $stmtFindMentor->execute([$mentorName]);
                $mentorId = $stmtFindMentor->fetchColumn() ?: ($_SESSION['user_id'] ?? 1);

                if ($studentId) {
                    $sDate = !empty($d['tanggal']) ? $d['tanggal'] : date('Y-m-d');
                    $sesiLabel = trim($d['pekan'] ?? 'Pekan 1');
                    if (!empty($d['hari'])) $sesiLabel .= ' - ' . trim($d['hari']);
                    $mName = date('F', strtotime($sDate));
                    $yVal  = (int)date('Y', strtotime($sDate));

                    $sMip  = is_numeric($d['scoreMIP'] ?? null) ? (float)$d['scoreMIP'] : null;
                    $sAcad = is_numeric($d['scoreAkademik'] ?? null) ? (float)$d['scoreAkademik'] : null;
                    $sMut  = is_numeric($d['scoreMutabaah'] ?? null) ? (float)$d['scoreMutabaah'] : null;
                    $sAdab = is_numeric($d['scoreAdab'] ?? null) ? (float)$d['scoreAdab'] : null;

                    $stmtIns->execute([
                        $studentId, $mentorId, $sDate, $sesiLabel, $mName, $yVal,
                        $sMip, $d['catatanMIP'] ?? '-',
                        $sAcad, $d['catatanAkademik'] ?? '-',
                        $sMut, $d['catatanMutabaah'] ?? '-',
                        $sAdab, $d['catatanAdab'] ?? '-',
                        $d['catatanSaran'] ?? '-'
                    ]);
                }
            }
            echo json_encode(['success' => true, 'message' => 'Data sesi mentoring berhasil disimpan ke basis data!']);
            exit;
        }
    }

    // 7. SIMPAN ADAB SISWA (POST)
    if ($action === 'simpanAdabSiswa' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);

        if (!empty($payload)) {
            $nis = trim($payload['nis'] ?? '');
            $stmtSt = $pdo->prepare("SELECT id FROM students WHERE nis = ? OR name = ? LIMIT 1");
            $stmtSt->execute([$nis, $payload['namaMurid'] ?? '']);
            $studentId = $stmtSt->fetchColumn();

            if ($studentId) {
                $weekCode = trim($payload['pekan'] ?? 'Pekan 1');
                $dayName = trim($payload['hari'] ?? 'Selasa');
                $chkStr = is_string($payload['checklist']) ? $payload['checklist'] : json_encode($payload['checklist'] ?? []);

                // Cek materi id
                $currId = $pdo->query("SELECT id FROM mentoring_curriculums LIMIT 1")->fetchColumn() ?: 1;

                $stmtIns = $pdo->prepare("
                    INSERT INTO student_adab_logs (
                        student_id, curriculum_id, grade_name, week_code, day_name, checklist_json,
                        reflection_action, reflection_obstacle, reflection_solution, reflection_gratitude, submitted_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmtIns->execute([
                    $studentId, $currId, $payload['kelas'] ?? '7A', $weekCode, $dayName, $chkStr,
                    $payload['refleksi1'] ?? '-',
                    $payload['refleksi2'] ?? '-',
                    $payload['refleksi3'] ?? '-',
                    $payload['refleksi4'] ?? '-'
                ]);
            }
            echo json_encode(['success' => true, 'message' => 'Penugasan dan refleksi adab berhasil disimpan!']);
            exit;
        }
    }

    // 8. SIMPAN BANK MATERI (POST)
    if (($action === 'simpanBankMateri' || $action === 'updateAllBankMateri') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);

        if (is_array($payload)) {
            $items = isset($payload['judul']) ? [$payload] : $payload;
            $stmtIns = $pdo->prepare("
                INSERT INTO mentoring_curriculums (
                    grade_level, week_code, title, description, file_link,
                    task_1, task_2, task_3, task_4,
                    reflection_prompt_1, reflection_prompt_2, reflection_prompt_3, reflection_prompt_4, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");

            foreach ($items as $m) {
                $kelasStr = $m['kelas'] ?? '7';
                preg_match('/\d+/', $kelasStr, $gMatches);
                $grade = !empty($gMatches[0]) ? (int)$gMatches[0] : 7;
                $tasks = $m['penugasanTasks'] ?? [];
                $refs = $m['refleksiQuestions'] ?? [];

                $stmtIns->execute([
                    $grade,
                    $m['pekan'] ?? 'Pekan 1',
                    $m['judul'] ?? 'Materi Adab',
                    $m['deskripsi'] ?? '-',
                    $m['fileUrl'] ?? '',
                    $tasks[0] ?? null, $tasks[1] ?? null, $tasks[2] ?? null, $tasks[3] ?? null,
                    $refs[0] ?? null, $refs[1] ?? null, $refs[2] ?? null, $refs[3] ?? null
                ]);
            }
            echo json_encode(['success' => true, 'message' => 'Bank materi berhasil diperbarui!']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
