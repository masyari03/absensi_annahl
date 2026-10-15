<?php

namespace App\Services;

class NotificationService
{
    /**
     * Kirim notifikasi WhatsApp ke orang tua/wali siswa.
     */
    public static function sendAttendanceNotification(
        string $phone,
        string $studentName,
        string $scanType,
        string $time,
        string $statusText,
        string $schoolName = 'An-Nahl Islamic School'
    ): bool {
        if (empty($phone)) {
            return false;
        }

        // Standarisasi nomor HP ke format internasional (62xxx)
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '62' . substr($cleanPhone, 1);
        }

        $greeting = ($scanType === 'masuk')
            ? "Alhamdulillah, ananda *{$studentName}* telah hadir di sekolah ({$statusText}) pada pukul {$time} WIB."
            : "Alhamdulillah, ananda *{$studentName}* telah menyelesaikan KBM dan melakukan absen kepulangan pada pukul {$time} WIB.";

        $message = "Assalamu'alaikum Warahmatullahi Wabarakatuh,\n\n"
                 . "Informasi Kehadiran Siswa - {$schoolName}:\n"
                 . "{$greeting}\n\n"
                 . "Semoga ananda senantiasa dalam lindungan Allah SWT dan mendapatkan ilmu yang bermanfaat.\n\n"
                 . "Wassalamu'alaikum Warahmatullahi Wabarakatuh.\n"
                 . "_Sistem Notifikasi Otomatis AIS Absensi_";

        return self::dispatchWhatsApp($cleanPhone, $message);
    }

    /**
     * Dispatch pesan WhatsApp ke gateway provider (Fonnte / Wablas / Mock).
     */
    public static function dispatchWhatsApp(string $phone, string $message): bool
    {
        $enabled = (getenv('WA_ENABLED') === 'true' || getenv('WA_ENABLED') === '1');
        $apiKey = getenv('WA_API_KEY') ?: '';
        $apiUrl = getenv('WA_API_URL') ?: 'https://api.fonnte.com/send';

        if (!$enabled || empty($apiKey)) {
            // Mock Mode: Catat ke PHP error log untuk keperluan verifikasi & dev
            error_log("[WA-MOCK] Kirim WA ke {$phone}: " . str_replace("\n", " ", $message));
            return true;
        }

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'target' => $phone,
                'message' => $message,
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: {$apiKey}"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($httpCode >= 200 && $httpCode < 300);
        } catch (\Throwable $e) {
            error_log("[WA-ERROR] Gagal kirim WA ke {$phone}: " . $e->getMessage());
            return false;
        }
    }
}
