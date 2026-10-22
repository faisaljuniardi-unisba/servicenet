<?php

$tz = 'Asia/Jakarta';
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set($tz);
}

/*
 * Kredensial produksi disimpan di luar web root:
 * /www/wwwroot/servicenet-private/database.env
 *
 * Environment variable tetap didukung agar konfigurasi PHP-FPM dapat dipakai
 * bila diperlukan. Jangan menaruh nilai rahasia di file PHP ini.
 */
$config_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'servicenet-private' . DIRECTORY_SEPARATOR . 'database.env';
$config = is_readable($config_path)
    ? parse_ini_file($config_path, false, INI_SCANNER_RAW)
    : [];

if ($config === false) {
    $config = [];
}

$database_config_value = static function (array $config, string $key): string {
    $value = $config[$key] ?? getenv($key) ?: '';
    return is_string($value) ? trim($value) : '';
};

$host = $database_config_value($config, 'SERVICENET_DB_HOST');
$username = $database_config_value($config, 'SERVICENET_DB_USER');
$password = $database_config_value($config, 'SERVICENET_DB_PASSWORD');
$database = $database_config_value($config, 'SERVICENET_DB_NAME');

if ($host === '' || $username === '' || $password === '' || $database === '') {
    error_log('Konfigurasi database ServiceNet tidak lengkap.');
    http_response_code(500);
    exit('Aplikasi sedang tidak dapat terhubung ke database.');
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    // CURDATE(), CURTIME(), dan kolom TIMESTAMP mengikuti zona sesi MySQL,
    // bukan otomatis zona PHP. Tetapkan WIB untuk setiap koneksi aplikasi.
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    error_log('Koneksi database ServiceNet gagal: ' . $e->getMessage());
    http_response_code(500);
    exit('Aplikasi sedang tidak dapat terhubung ke database.');
}
