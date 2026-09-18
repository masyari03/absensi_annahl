<?php
date_default_timezone_set('Asia/Jakarta');
$host = '127.0.0.1';

$dbname = 'library_v4';

$username = 'root';

$password = '';

try {

    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false
        ]
    );

} catch (PDOException $e) {

    die(
        'Database tidak dapat terhubung.'
    );

}
