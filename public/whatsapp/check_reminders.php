<?php
/**
 * Runner Pengecekan Notifikasi WhatsApp Otomatis:
 * - Pesan 2: Terlambat / Belum Hadir 1 Jam Setelah Batas Telat
 * - Pesan 4: Konfirmasi Kepulangan 1 Jam Setelah Jam Pulang Belum Absen
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/whatsapp.php';

$isCli = (php_sapi_name() === 'cli');

$lateSent = runLateCheckReminders($pdo);
$outLateSent = runDepartureCheckReminders($pdo);

$response = [
    'status'       => 'success',
    'sent_late'    => $lateSent,
    'sent_out_late'=> $outLateSent,
    'total_sent'   => ($lateSent + $outLateSent),
    'timestamp'    => date('Y-m-d H:i:s')
];

if (!$isCli) {
    header('Content-Type: application/json');
    echo json_encode($response);
} else {
    echo "[" . date('Y-m-d H:i:s') . "] Pengecekan Selesai. Terkirim Pesan 2: {$lateSent}, Pesan 4: {$outLateSent}.\n";
}
