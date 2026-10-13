<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'all'; // 'all' (reset semua) atau 'missing' (hanya yg belum punya)
    $unitId = (int)($_POST['unit_id'] ?? 0); // 0 = semua unit

    $where = ["s.deleted_at IS NULL"];
    $params = [];

    if ($unitId > 0) {
        $where[] = "EXISTS (
            SELECT 1 FROM student_enrollments se 
            INNER JOIN class_groups cg ON cg.id = se.class_group_id
            INNER JOIN grades g ON g.id = cg.grade_id
            WHERE se.student_id = s.id AND g.unit_id = ?
        )";
        $params[] = $unitId;
    }

    $whereSql = implode(' AND ', $where);
    $stmt = $pdo->prepare("SELECT s.id, s.name, s.nis FROM students s WHERE {$whereSql} ORDER BY s.id ASC");
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $defaultPasswordHash = password_hash('password', PASSWORD_BCRYPT);
    $createdCount = 0;
    $updatedCount = 0;
    $skippedCount = 0;

    $pdo->beginTransaction();
    try {
        // Prepared statements
        $stmtFindById = $pdo->prepare("SELECT id, username FROM users WHERE student_id = ? LIMIT 1");
        $stmtFindByUser = $pdo->prepare("SELECT id, student_id FROM users WHERE username = ? LIMIT 1");
        $stmtInsert = $pdo->prepare("
            INSERT INTO users (username, password, name, role, student_id, created_at, updated_at)
            VALUES (?, ?, ?, 'siswa', ?, NOW(), NOW())
        ");
        $stmtUpdate = $pdo->prepare("
            UPDATE users 
            SET username = ?, password = ?, name = ?, role = 'siswa', student_id = ?, updated_at = NOW()
            WHERE id = ?
        ");

        foreach ($students as $s) {
            $username = !empty($s['nis']) ? trim($s['nis']) : ('siswa_' . $s['id']);

            // Cek apakah siswa sudah punya user via student_id
            $stmtFindById->execute([$s['id']]);
            $existingUser = $stmtFindById->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                if ($mode === 'all') {
                    // Reset ke NIS dan password "password"
                    $stmtUpdate->execute([$username, $defaultPasswordHash, $s['name'], $s['id'], $existingUser['id']]);
                    $updatedCount++;
                } else {
                    $skippedCount++;
                }
            } else {
                // Cek apakah username sudah terpakai oleh user lain
                $stmtFindByUser->execute([$username]);
                $userByUsername = $stmtFindByUser->fetch(PDO::FETCH_ASSOC);

                if ($userByUsername) {
                    // Update user yang bentrok ini untuk disambungkan ke siswa
                    $stmtUpdate->execute([$username, $defaultPasswordHash, $s['name'], $s['id'], $userByUsername['id']]);
                    $updatedCount++;
                } else {
                    // Buat user baru
                    $stmtInsert->execute([$username, $defaultPasswordHash, $s['name'], $s['id']]);
                    $createdCount++;
                }
            }
        }

        $pdo->commit();

        $msg = "Berhasil memproses akun siswa: {$createdCount} akun baru dibuat, {$updatedCount} akun di-reset ke default (Password: 'password').";
        if ($skippedCount > 0) {
            $msg .= " ({$skippedCount} akun sudah ada & tidak diubah).";
        }
        flash('success', $msg);
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'Gagal memproses akun siswa secara bulk: ' . $e->getMessage());
    }

    $redirectUrl = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    redirect($redirectUrl);
} else {
    redirect('index.php');
}
