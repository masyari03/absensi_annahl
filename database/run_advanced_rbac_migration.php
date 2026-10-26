<?php
/**
 * MIGRATION: ADVANCED GRANULAR RBAC (2D CRUD MATRIX & DYNAMIC MULTI-ROLE)
 * Database: library_v4
 */

require_once __DIR__ . '/../config/database.php';

echo "=== MEMULAI MIGRASI ADVANCED GRANULAR RBAC ===" . PHP_EOL;

function colExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.columns 
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

try {
    // 1. Pastikan role 'siswa' ada di tabel roles
    $stmtSiswa = $pdo->query("SELECT id FROM roles WHERE role_key = 'siswa' LIMIT 1");
    if (!$stmtSiswa->fetchColumn()) {
        $pdo->exec("
            INSERT INTO roles (role_key, role_name, description, is_system)
            VALUES ('siswa', 'Siswa / Santri', 'Akses portal mandiri siswa dan santri', 1)
        ");
        echo "[1] Role 'siswa' berhasil ditambahkan ke master roles." . PHP_EOL;
    } else {
        echo "[1] Role 'siswa' sudah terdaftar di master roles." . PHP_EOL;
    }

    // 2. Ubah kolom users.role menjadi VARCHAR(50) agar dinamis (mendukung role baru apapun)
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff'");
    echo "[2] Kolom users.role diubah menjadi VARCHAR(50) dinamis." . PHP_EOL;

    // 3. Buat tabel user_roles (Pivot Multi-Role)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_roles` (
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `role_id` INT(10) UNSIGNED NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `role_id`),
            KEY `idx_ur_user` (`user_id`),
            KEY `idx_ur_role` (`role_id`),
            CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[3] Tabel user_roles (multi-role pivot) siap." . PHP_EOL;

    // 4. Seeding user_roles dari data users yang sudah ada
    $stmtSeedRoles = $pdo->exec("
        INSERT IGNORE INTO user_roles (user_id, role_id, is_primary)
        SELECT u.id, r.id, 1
        FROM users u
        INNER JOIN roles r ON r.role_key = u.role
    ");
    echo "[4] Seeding user_roles dari akun pengguna eksisting selesai." . PHP_EOL;

    // 5. Tambah kolom granular CRUD di role_menu_access
    if (!colExists($pdo, 'role_menu_access', 'can_read')) {
        $pdo->exec("ALTER TABLE role_menu_access ADD COLUMN can_read TINYINT(1) NOT NULL DEFAULT 1");
        echo "[5a] Added role_menu_access.can_read" . PHP_EOL;
    }
    if (!colExists($pdo, 'role_menu_access', 'can_create')) {
        $pdo->exec("ALTER TABLE role_menu_access ADD COLUMN can_create TINYINT(1) NOT NULL DEFAULT 0");
        echo "[5b] Added role_menu_access.can_create" . PHP_EOL;
    }
    if (!colExists($pdo, 'role_menu_access', 'can_edit')) {
        $pdo->exec("ALTER TABLE role_menu_access ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 0");
        echo "[5c] Added role_menu_access.can_edit" . PHP_EOL;
    }
    if (!colExists($pdo, 'role_menu_access', 'can_delete')) {
        $pdo->exec("ALTER TABLE role_menu_access ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0");
        echo "[5d] Added role_menu_access.can_delete" . PHP_EOL;
    }

    // 6. Tambah kolom granular CRUD di user_menu_access
    if (!colExists($pdo, 'user_menu_access', 'can_read')) {
        $pdo->exec("ALTER TABLE user_menu_access ADD COLUMN can_read TINYINT(1) NOT NULL DEFAULT 1");
        echo "[6a] Added user_menu_access.can_read" . PHP_EOL;
    }
    if (!colExists($pdo, 'user_menu_access', 'can_create')) {
        $pdo->exec("ALTER TABLE user_menu_access ADD COLUMN can_create TINYINT(1) NOT NULL DEFAULT 0");
        echo "[6b] Added user_menu_access.can_create" . PHP_EOL;
    }
    if (!colExists($pdo, 'user_menu_access', 'can_edit')) {
        $pdo->exec("ALTER TABLE user_menu_access ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 0");
        echo "[6c] Added user_menu_access.can_edit" . PHP_EOL;
    }
    if (!colExists($pdo, 'user_menu_access', 'can_delete')) {
        $pdo->exec("ALTER TABLE user_menu_access ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0");
        echo "[6d] Added user_menu_access.can_delete" . PHP_EOL;
    }

    // 7. Berikan default CRUD untuk Super Admin dan Role Utama
    // Super Admin: Full CRUD (1, 1, 1, 1)
    $superAdminRoleId = $pdo->query("SELECT id FROM roles WHERE role_key = 'super_admin' LIMIT 1")->fetchColumn();
    if ($superAdminRoleId) {
        $pdo->exec("UPDATE role_menu_access SET can_read = 1, can_create = 1, can_edit = 1, can_delete = 1 WHERE role_id = " . (int)$superAdminRoleId);
        echo "[7a] Super Admin role menu CRUD diset penuh (Read, Create, Edit, Delete)." . PHP_EOL;
    }

    // Kepala Sekolah & Admin: Read, Create, Edit (1, 1, 1, 0)
    $mgrRoles = $pdo->query("SELECT id FROM roles WHERE role_key IN ('kepala_sekolah', 'admin')")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($mgrRoles)) {
        $idsStr = implode(',', array_map('intval', $mgrRoles));
        $pdo->exec("UPDATE role_menu_access SET can_read = 1, can_create = 1, can_edit = 1 WHERE role_id IN ($idsStr)");
        echo "[7b] Kepala Sekolah & Admin role menu CRUD diset Read, Create, Edit." . PHP_EOL;
    }

    // Staff / Guru: Read & Create (1, 1, 0, 0)
    $staffRoleId = $pdo->query("SELECT id FROM roles WHERE role_key = 'staff' LIMIT 1")->fetchColumn();
    if ($staffRoleId) {
        $pdo->exec("UPDATE role_menu_access SET can_read = 1, can_create = 1 WHERE role_id = " . (int)$staffRoleId);
        echo "[7c] Staff role menu CRUD diset Read & Create." . PHP_EOL;
    }

    echo "=== MIGRASI ADVANCED GRANULAR RBAC SELESAI DENGAN SUKSES ===" . PHP_EOL;
} catch (Exception $e) {
    echo "ERROR MIGRASI: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
