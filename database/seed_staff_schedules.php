<?php
// Seeder & Migrasi Jadwal Staff & Guru untuk seluruh unit sekolah
require_once __DIR__ . '/../config/database.php';

echo "=== SEEDING JADWAL STAFF & GURU UNTUK SEMUA UNIT ===\n";

$daysMap = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

// 1. Bersihkan jam staff dari jadwal siswa legacy (ID 1-7)
echo "1. Membersihkan jam staff dari jadwal siswa legacy...\n";
$pdo->exec("
    UPDATE weekly_schedules 
    SET staff_in = '00:00:00', staff_late = '00:00:00', staff_out = '00:00:00', name = 'KBM Reguler SD'
    WHERE id = 1
");
$pdo->exec("
    UPDATE weekly_schedules 
    SET staff_in = '00:00:00', staff_late = '00:00:00', staff_out = '00:00:00', name = 'KBM Reguler SMP'
    WHERE id BETWEEN 2 AND 7
");

// 2. Daftar Template Shift Staff per Unit
$staffSchedulesToSeed = [
    // Unit 1: TK (Senin - Jumat)
    1 => [
        'name' => 'Shift Reguler Guru & Staff TK',
        'days' => [1, 2, 3, 4, 5],
        'in' => '06:45:00', 'late' => '07:00:00', 'out' => '14:30:00',
        'is_overnight' => 0
    ],
    // Unit 2: SD (Senin - Jumat)
    2 => [
        'name' => 'Shift Reguler Guru & Staff SD',
        'days' => [1, 2, 3, 4, 5],
        'in' => '06:30:00', 'late' => '06:45:00', 'out' => '15:30:00',
        'is_overnight' => 0
    ],
    // Unit 3: SMP (Senin - Sabtu)
    3 => [
        'name' => 'Shift Reguler Guru & Staff SMP',
        'days' => [1, 2, 3, 4, 5, 6],
        'in' => '06:30:00', 'late' => '06:45:00', 'out' => '16:00:00',
        'is_overnight' => 0
    ],
    // Unit 4: SDM & Umum (Senin - Jumat)
    4 => [
        'name' => 'Shift Reguler SDM & Umum',
        'days' => [1, 2, 3, 4, 5],
        'in' => '07:00:00', 'late' => '07:30:00', 'out' => '16:00:00',
        'is_overnight' => 0
    ],
    // Unit 5: Office Boy (Senin - Sabtu)
    5 => [
        'name' => 'Shift Reguler Office Boy',
        'days' => [1, 2, 3, 4, 5, 6],
        'in' => '05:30:00', 'late' => '06:00:00', 'out' => '16:30:00',
        'is_overnight' => 0
    ],
    // Unit 7: SMA (Senin - Jumat)
    7 => [
        'name' => 'Shift Reguler Guru & Staff SMA',
        'days' => [1, 2, 3, 4, 5],
        'in' => '06:30:00', 'late' => '06:45:00', 'out' => '16:00:00',
        'is_overnight' => 0
    ],
    // Unit 8: Gardener (Senin - Sabtu)
    8 => [
        'name' => 'Shift Reguler Gardener',
        'days' => [1, 2, 3, 4, 5, 6],
        'in' => '06:00:00', 'late' => '06:30:00', 'out' => '15:00:00',
        'is_overnight' => 0
    ],
    // Unit 9: Litbang (Senin - Jumat)
    9 => [
        'name' => 'Shift Reguler Litbang',
        'days' => [1, 2, 3, 4, 5],
        'in' => '07:30:00', 'late' => '08:00:00', 'out' => '16:00:00',
        'is_overnight' => 0
    ],
    // Unit 10: Marketing & Keuangan (Senin - Jumat)
    10 => [
        'name' => 'Shift Reguler Marketing & Keuangan',
        'days' => [1, 2, 3, 4, 5],
        'in' => '07:30:00', 'late' => '08:00:00', 'out' => '16:00:00',
        'is_overnight' => 0
    ]
];

$stmtCheckStaff = $pdo->prepare("SELECT id FROM weekly_schedules WHERE unit_id = ? AND target_type = 'staff' AND day_code = ? LIMIT 1");
$stmtInsertStaff = $pdo->prepare("
    INSERT INTO weekly_schedules 
    (unit_id, target_type, grade_id, class_group_id, schedule_type, name, day_name, day_code, student_in, student_late, student_out, staff_in, staff_late, staff_out, is_overnight, is_active)
    VALUES (?, 'staff', NULL, NULL, 'reguler', ?, ?, ?, '00:00:00', '00:00:00', '00:00:00', ?, ?, ?, ?, 'active')
");

echo "2. Memeriksa dan menambahkan jadwal staff per unit...\n";
foreach ($staffSchedulesToSeed as $unitId => $cfg) {
    $insertedCount = 0;
    foreach ($cfg['days'] as $dayCode) {
        $stmtCheckStaff->execute([$unitId, $dayCode]);
        if (!$stmtCheckStaff->fetchColumn()) {
            $dayName = $daysMap[$dayCode] ?? '';
            $stmtInsertStaff->execute([
                $unitId, $cfg['name'], $dayName, $dayCode,
                $cfg['in'], $cfg['late'], $cfg['out'], $cfg['is_overnight']
            ]);
            $insertedCount++;
        }
    }
    echo "  + Unit #{$unitId} ({$cfg['name']}): {$insertedCount} jadwal staff ditambahkan.\n";
}

// 3. Tambahkan jadwal KBM Siswa untuk unit yang belum lengkap (TK, SD hari lain, SMA)
echo "3. Melengkapi jadwal KBM Siswa...\n";
$stmtCheckStudent = $pdo->prepare("SELECT id FROM weekly_schedules WHERE unit_id = ? AND target_type = 'student' AND schedule_type = 'reguler' AND day_code = ? LIMIT 1");
$stmtInsertStudent = $pdo->prepare("
    INSERT INTO weekly_schedules 
    (unit_id, target_type, grade_id, class_group_id, schedule_type, name, day_name, day_code, student_in, student_late, student_out, staff_in, staff_late, staff_out, is_overnight, is_active)
    VALUES (?, 'student', NULL, NULL, 'reguler', ?, ?, ?, ?, ?, ?, '00:00:00', '00:00:00', '00:00:00', 0, 'active')
");

// Siswa TK (Unit 1): Senin-Jumat
foreach ([1, 2, 3, 4, 5] as $dayCode) {
    $stmtCheckStudent->execute([1, $dayCode]);
    if (!$stmtCheckStudent->fetchColumn()) {
        $stmtInsertStudent->execute([1, 'KBM Reguler TK', $daysMap[$dayCode], $dayCode, '07:15:00', '07:30:00', '12:00:00']);
        echo "  + Unit #1 (TK) KBM Siswa Hari {$daysMap[$dayCode]} ditambahkan.\n";
    }
}

// Siswa SD (Unit 2): Senin-Rabu & Jumat (Kamis sudah ada)
foreach ([1, 2, 3, 5] as $dayCode) {
    $stmtCheckStudent->execute([2, $dayCode]);
    if (!$stmtCheckStudent->fetchColumn()) {
        $stmtInsertStudent->execute([2, 'KBM Reguler SD', $daysMap[$dayCode], $dayCode, '06:30:00', '06:45:00', '15:30:00']);
        echo "  + Unit #2 (SD) KBM Siswa Hari {$daysMap[$dayCode]} ditambahkan.\n";
    }
}

// Siswa SMA (Unit 7): Senin-Jumat
foreach ([1, 2, 3, 4, 5] as $dayCode) {
    $stmtCheckStudent->execute([7, $dayCode]);
    if (!$stmtCheckStudent->fetchColumn()) {
        $stmtInsertStudent->execute([7, 'KBM Reguler SMA', $daysMap[$dayCode], $dayCode, '06:30:00', '06:45:00', '15:45:00']);
        echo "  + Unit #7 (SMA) KBM Siswa Hari {$daysMap[$dayCode]} ditambahkan.\n";
    }
}

echo "\n=== SEEDING SELESAI DENGAN SUKSES! ===\n";
