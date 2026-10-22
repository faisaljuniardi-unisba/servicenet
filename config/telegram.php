<?php

/*
 * Konfigurasi Telegram sengaja dibaca dari servicenet-private/database.env
 * atau environment server. Jangan menaruh token pada file aplikasi.
 */
function servicenet_telegram_config(string $key): string
{
    static $config = null;

    if ($config === null) {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'servicenet-private' . DIRECTORY_SEPARATOR . 'database.env';
        $config = is_readable($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : [];
        if ($config === false) {
            $config = [];
        }
    }

    $value = $config[$key] ?? getenv($key) ?: '';
    return is_string($value) ? trim($value) : '';
}

function notify_telegram_new_complaint(array $complaint): bool
{
    $token = servicenet_telegram_config('SERVICENET_TELEGRAM_TOKEN');
    $chatId = servicenet_telegram_config('SERVICENET_TELEGRAM_CHAT_ID');
    if ($token === '' || $chatId === '') {
        return false;
    }

    if (!function_exists('curl_init')) {
        error_log('Notifikasi Telegram tidak dikirim: ekstensi cURL PHP tidak tersedia.');
        return false;
    }

    $detailUrl = '';
    $appUrl = rtrim(servicenet_telegram_config('SERVICENET_APP_URL'), '/');
    $complaintId = (int) ($complaint['keluhan_id'] ?? 0);
    if ($appUrl !== '' && filter_var($appUrl, FILTER_VALIDATE_URL) && $complaintId > 0) {
        $detailUrl = $appUrl . '/admin/keluhan_detail.php?id=' . $complaintId;
    }

    $message = "🔔 Laporan keluhan baru\n\n"
        . 'Nomor Laporan: #' . (int) ($complaint['maintenance_id'] ?? 0) . "\n"
        . 'Nama: ' . (string) ($complaint['nama_pelapor'] ?? '-') . "\n"
        . 'Fakultas/Unit: ' . (string) ($complaint['unit_lembaga'] ?? '-') . "\n"
        . 'Lokasi: ' . (string) ($complaint['lokasi'] ?? '-') . "\n"
        . 'Keluhan: ' . (string) ($complaint['keluhan'] ?? '-') . "\n\n"
        . ($detailUrl !== ''
            ? "Silakan klik link ini untuk melihat laporan:\n" . $detailUrl
            : 'Silakan buka Dashboard Netcare untuk menindaklanjuti.');

    $request = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'text' => $message,
            'disable_web_page_preview' => 'true',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $error = curl_error($request);
    curl_close($request);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('Notifikasi Telegram gagal dikirim' . ($error !== '' ? ': ' . $error : '.') );
        return false;
    }

    return true;
}
