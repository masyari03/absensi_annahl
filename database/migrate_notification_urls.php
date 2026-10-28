<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

// Backup existing notifications table
$backupTable = 'notifications_backup_' . date('Ymd_His');
try {
    $pdo->exec("CREATE TABLE $backupTable LIKE notifications");
    $pdo->exec("INSERT INTO $backupTable SELECT * FROM notifications");
    echo "[Backup] Created backup table $backupTable\n";
} catch (PDOException $e) {
    echo "[Backup ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

$pdo->beginTransaction();
try {
    // Select rows with relative URLs (not starting with http or https)
    $stmtSelect = $pdo->prepare("SELECT id, link_url FROM notifications WHERE link_url NOT LIKE 'http%'");
    $stmtSelect->execute();
    $rows = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

    $stmtUpdate = $pdo->prepare("UPDATE notifications SET link_url = :new_url WHERE id = :id");
    foreach ($rows as $row) {
        $relative = $row['link_url'];
        // Normalize relative path (remove leading ./ or /)
        $clean = ltrim($relative, './');
        $absolute = rtrim(BASE_URL, '/') . '/' . ltrim($clean, '/');
        $stmtUpdate->execute([':new_url' => $absolute, ':id' => $row['id']]);
        echo "[Update] ID {$row['id']} => $absolute\n";
    }
    $pdo->commit();
    echo "[Success] Notification URLs migrated. Total updated: " . count($rows) . "\n";
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "[Migration ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
?>
