<?php
/**
 * Migrasi Menu, RBAC, & Tabel Kurikulum Target Capaian Tahfidz
 * Jalankan: php database/migrate_penilaian_kinerja_tahfidz.php
 */

require_once __DIR__ . '/../config/database.php';

echo "=================================================================\n";
echo "  MIGRASI: PENILAIAN KINERJA -> CAPAIAN TAHFIDZ\n";
echo "=================================================================\n";

try {
    // 1. DDL Tabel (di luar transaksi karena MySQL DDL auto-commit)
    echo "[1/5] Memastikan tabel kinerja_tahfidz_targets tersedia & ENUM mutakhir...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kinerja_tahfidz_targets (
            id INT NOT NULL AUTO_INCREMENT,
            unit_id INT NOT NULL,
            paralel_id INT NOT NULL,
            paralel_nama VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            semester TINYINT NOT NULL DEFAULT 1,
            target_type ENUM('juz30_rank','juz29_rank','juz28_rank','juz29_coverage','juz28_coverage','juz_stage','teacher_individual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'juz30_rank',
            target_value DECIMAL(6,2) NOT NULL DEFAULT 13.00,
            target_label VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL,
            require_munaqasyah TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_unit_paralel_sem (unit_id, paralel_id, semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        ALTER TABLE kinerja_tahfidz_targets
        MODIFY COLUMN target_type ENUM('juz30_rank','juz29_rank','juz28_rank','juz29_coverage','juz28_coverage','juz_stage','teacher_individual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'juz30_rank'
    ");

    $pdo->beginTransaction();

    // 2. Pastikan Parent Menu ID 112 (kinerja_parent)
    echo "[2/5] Memastikan Menu Parent 'Penilaian Kinerja' (ID 112)...\n";
    $stmtParent = $pdo->prepare("
        INSERT INTO app_menus (id, menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active)
        VALUES (112, 'kinerja_parent', 'Penilaian Kinerja', 'PENILAIAN KINERJA', '#', 'fa-chart-pie', NULL, 23, 1)
        ON DUPLICATE KEY UPDATE
            menu_name  = VALUES(menu_name),
            category   = VALUES(category),
            menu_icon  = VALUES(menu_icon),
            sort_order = VALUES(sort_order),
            is_active  = 1
    ");
    $stmtParent->execute();

    // 3. Registrasi Sub-menu ID 115 (kinerja_capaian_tahfidz)
    echo "[3/5] Mendaftarkan Sub-menu 'Capaian Tahfidz' (ID 115)...\n";
    $stmtMenu = $pdo->prepare("
        INSERT INTO app_menus (id, menu_key, menu_name, category, menu_url, menu_icon, parent_id, sort_order, is_active)
        VALUES (115, 'kinerja_capaian_tahfidz', 'Capaian Tahfidz', 'PENILAIAN KINERJA', 'kinerja/tahfidz.php', 'fa-book-quran', 112, 3, 1)
        ON DUPLICATE KEY UPDATE
            menu_name  = VALUES(menu_name),
            category   = VALUES(category),
            menu_url   = VALUES(menu_url),
            menu_icon  = VALUES(menu_icon),
            parent_id  = VALUES(parent_id),
            sort_order = VALUES(sort_order),
            is_active  = 1
    ");
    $stmtMenu->execute();

    // 4. Sinkronisasi Akses Role (super_admin=1, lainnya default=0 via INSERT IGNORE)
    echo "[4/5] Mengatur RBAC default untuk Menu ID 115...\n";
    $roles = $pdo->query("SELECT id, role_key FROM roles")->fetchAll(PDO::FETCH_ASSOC);
    $stmtSuper = $pdo->prepare("
        INSERT INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
        VALUES (:role_id, 115, 1, 1, 1, 1)
        ON DUPLICATE KEY UPDATE can_read = 1, can_create = 1, can_edit = 1, can_delete = 1
    ");
    $stmtOther = $pdo->prepare("
        INSERT IGNORE INTO role_menu_access (role_id, menu_id, can_read, can_create, can_edit, can_delete)
        VALUES (:role_id, 115, 0, 0, 0, 0)
    ");

    foreach ($roles as $r) {
        if ($r['role_key'] === 'super_admin') {
            $stmtSuper->execute([':role_id' => (int)$r['id']]);
        } else {
            $stmtOther->execute([':role_id' => (int)$r['id']]);
        }
    }

    // 5. Seed Default Target Kurikulum (INSERT IGNORE + upgrade Kelas 6 juz29_rank)
    echo "[5/5] Menyinkronkan default target kurikulum Tahfidz...\n";
    $defaults = [
        // Unit 1: TK
        [1, 2,  'PLAYGROUP',       1, 'juz30_rank', 1.00,  'QS. Al-Fatihah – An-Nas'],
        [1, 2,  'PLAYGROUP',       2, 'juz30_rank', 3.00,  'QS. An-Nas – Al-Ikhlas'],
        [1, 3,  'KINDERGARTEN A',  1, 'juz30_rank', 4.00,  'QS. An-Nas – Al-Lahab'],
        [1, 3,  'KINDERGARTEN A',  2, 'juz30_rank', 9.00,  'QS. An-Nas – Quraisy'],
        [1, 4,  'KINDERGARTEN B',  1, 'juz30_rank', 13.00, 'QS. An-Nas – At-Takatsur'],
        [1, 4,  'KINDERGARTEN B',  2, 'juz30_rank', 17.00, 'QS. An-Nas – Al-Bayyinah'],
        // Unit 2: SD
        [2, 5,  'Kelas 1',         1, 'juz30_rank', 13.00, 'QS. An-Nas – At-Takatsur'],
        [2, 5,  'Kelas 1',         2, 'juz30_rank', 17.00, 'QS. An-Nas – Al-Bayyinah'],
        [2, 6,  'Kelas 2',         1, 'juz30_rank', 22.00, 'QS. An-Nas – Adh-Dhuha'],
        [2, 6,  'Kelas 2',         2, 'juz30_rank', 25.00, 'QS. An-Nas – Al-Balad'],
        [2, 7,  'Kelas 3',         1, 'juz30_rank', 28.00, "QS. An-Nas – Al-A'laa"],
        [2, 7,  'Kelas 3',         2, 'juz30_rank', 31.00, 'QS. An-Nas – Al-Insyiqaq'],
        [2, 8,  'Kelas 4',         1, 'juz30_rank', 33.00, 'QS. An-Nas – Al-Infithar'],
        [2, 8,  'Kelas 4',         2, 'juz30_rank', 35.00, "QS. An-Nas – 'Abasa"],
        [2, 9,  'Kelas 5',         1, 'juz30_rank', 37.00, "QS. An-Nas – An-Naba' (Juz 30)"],
        [2, 9,  'Kelas 5',         2, 'juz30_rank', 37.00, 'Tuntas Juz 30 / Munaqasyah Juz 30'],
        [2, 10, 'Kelas 6',         1, 'juz29_rank', 43.00, 'QS. Al-Mulk – Al-Jin (Juz 29 Bag. 1)'],
        [2, 10, 'Kelas 6',         2, 'juz29_rank', 48.00, 'QS. Al-Mulk – Al-Mursalat (Tuntas Juz 29)'],
        // Unit 3: SMP
        [3, 11, 'Kelas 7',         1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [3, 11, 'Kelas 7',         2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [3, 12, 'Kelas 8',         1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [3, 12, 'Kelas 8',         2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [3, 13, 'Kelas 9',         1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [3, 13, 'Kelas 9',         2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        // Unit 4: SMA
        [4, 14, 'Kelas 10',        1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [4, 14, 'Kelas 10',        2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [4, 15, 'Kelas 11',        1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [4, 15, 'Kelas 11',        2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [4, 16, 'Kelas 12',        1, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
        [4, 16, 'Kelas 12',        2, 'teacher_individual', 1.00, 'Sesuai Target Juz Individu di Rapor AIS'],
    ];

    $stmtTarget = $pdo->prepare("
        INSERT IGNORE INTO kinerja_tahfidz_targets
            (unit_id, paralel_id, paralel_nama, semester, target_type, target_value, target_label, require_munaqasyah)
        VALUES
            (:unit_id, :paralel_id, :paralel_nama, :semester, :target_type, :target_value, :target_label, 0)
    ");

    foreach ($defaults as $row) {
        $stmtTarget->execute([
            ':unit_id'      => $row[0],
            ':paralel_id'   => $row[1],
            ':paralel_nama' => $row[2],
            ':semester'     => $row[3],
            ':target_type'  => $row[4],
            ':target_value' => $row[5],
            ':target_label' => $row[6],
        ]);
    }

    // Upgrade legacy seed Kelas 6 (juz29_coverage -> juz29_rank Al-Mulk -> Al-Mursalat)
    $stmtUpd6S1 = $pdo->prepare("
        UPDATE kinerja_tahfidz_targets
        SET target_type = 'juz29_rank', target_value = 43.00, target_label = 'QS. Al-Mulk – Al-Jin (Juz 29 Bag. 1)'
        WHERE unit_id = 2 AND paralel_id = 10 AND semester = 1 AND target_type = 'juz29_coverage'
    ");
    $stmtUpd6S1->execute();

    $stmtUpd6S2 = $pdo->prepare("
        UPDATE kinerja_tahfidz_targets
        SET target_type = 'juz29_rank', target_value = 48.00, target_label = 'QS. Al-Mulk – Al-Mursalat (Tuntas Juz 29)'
        WHERE unit_id = 2 AND paralel_id = 10 AND semester = 2 AND target_type = 'juz29_coverage'
    ");
    $stmtUpd6S2->execute();

    $pdo->commit();

    $totalTargets = (int)$pdo->query("SELECT COUNT(*) FROM kinerja_tahfidz_targets")->fetchColumn();
    echo "✅ Migrasi selesai dengan sukses! Total baris target kurikulum: {$totalTargets}\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[migrate_penilaian_kinerja_tahfidz] Error: ' . $e->getMessage());
    fwrite(STDERR, "❌ Migrasi gagal: " . $e->getMessage() . "\n");
    exit(1);
}
