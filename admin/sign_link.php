<?php
/**
 * Endpoint JSON: ambil link tanda tangan publik untuk satu laporan.
 *
 * Dipakai tombol "Salin Link TTD" di daftar laporan supaya token hanya dibuat
 * saat benar-benar dibutuhkan, bukan untuk semua baris tabel sekaligus.
 */

require_once __DIR__ . '/bootstrap.php';
require_once '../config/sign_link.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Kirim balasan JSON lalu hentikan skrip. */
function respond($status, array $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['ok' => false, 'error' => 'Metode tidak diizinkan.']);
}

$id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : false;
if ($id === false || $id <= 0) {
    respond(400, ['ok' => false, 'error' => 'ID laporan tidak valid.']);
}

$stmt = $pdo->prepare("SELECT id, nama_pengguna, tanda_tangan_pengguna FROM maintenance WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    respond(404, ['ok' => false, 'error' => 'Laporan tidak ditemukan.']);
}

try {
    $token_row = signTokenEnsure($pdo, $report['id'], $_SESSION['user_id']);
} catch (Exception $e) {
    error_log('sign_link.php gagal membuat token: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'Link gagal dibuat. Coba lagi.']);
}

respond(200, [
    'ok'        => true,
    'url'       => signLinkUrl($token_row['token']),
    'nama'      => $report['nama_pengguna'],
    'signed'    => signIsSigned($report),
    'signed_at' => $token_row['dipakai_pada'],
]);
