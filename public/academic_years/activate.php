<?php

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';

requireSuperAdmin();


$id =
    (int)($_POST['id'] ?? 0);


try {

    $pdo->beginTransaction();


    $pdo->exec("
        UPDATE academic_years
        SET status = 'closed'
        WHERE status = 'active'
    ");


    $stmt =
        $pdo->prepare("
            UPDATE academic_years

            SET status = 'active'

            WHERE id = ?
        ");

    $stmt->execute([
        $id
    ]);


    $pdo->commit();


    flash(
        'success',
        'Tahun ajaran sekarang sudah aktif.'
    );

} catch (Throwable $e) {

    if (
        $pdo->inTransaction()
    ) {

        $pdo->rollBack();

    }

    flash(
        'danger',
        'Gagal mengaktifkan tahun ajaran.'
    );
}


redirect(
    'index.php'
);