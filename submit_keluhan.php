<?php
// submit.php - Versi sederhana untuk testing

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/telegram.php';

// Rapikan input sebelum disimpan.
//
// Sengaja TIDAK memakai htmlspecialchars() di sini: escaping adalah urusan
// saat menampilkan, bukan saat menyimpan. Kalau di-escape lebih awal, database
// ikut menyimpan entitasnya (contoh: "Syari&#039;ah") sehingga nilainya tidak
// lagi cocok saat dibandingkan atau dicari, dan tampil salah di halaman yang
// meng-escape sekali lagi.
//
// stripslashes() juga dibuang: magic_quotes sudah dihapus sejak PHP 5.4, jadi
// fungsi itu hanya merusak backslash yang memang diketik pengguna.
function clean_input($data) {
    return trim((string) $data);
}

// Cek apakah form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submission_lock = null;
    try {
        if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Permintaan tidak valid. Silakan isi formulir kembali.');
        }

        // Ambil dan bersihkan data
        $nama_pelapor = clean_input($_POST['nama_pelapor'] ?? '');
        $kontak = clean_input($_POST['kontak'] ?? '');
        $unit_lembaga = clean_input($_POST['unit_lembaga'] ?? '');
        $lokasi = clean_input($_POST['lokasi'] ?? '');
        $keluhan = clean_input($_POST['keluhan'] ?? '');
        $jenis_masalah = 'WiFi';
        
        // Validasi sederhana
        if (empty($nama_pelapor) || empty($kontak) || empty($unit_lembaga) || empty($lokasi) || empty($keluhan)) {
            throw new Exception('Semua field wajib diisi!');
        }
        
        // Format nomor WhatsApp
        $clean_number = preg_replace('/\D/', '', $kontak);
        if (substr($clean_number, 0, 1) === '0') {
            $clean_number = '62' . substr($clean_number, 1);
        }
        if (!preg_match('/^628[0-9]{8,11}$/', $clean_number)) {
            throw new Exception('Masukkan nomor WhatsApp Indonesia yang valid, diawali 08 atau 628.');
        }

        // Kunci per nomor mencegah dua permintaan bersamaan lolos pengecekan.
        // Tidak memakai IP agar pengguna dalam jaringan yang sama tidak terblokir.
        $lock_name = 'keluhan:' . substr(hash('sha256', $clean_number), 0, 56);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->execute([$lock_name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new Exception('Pengiriman untuk nomor ini sedang diproses. Silakan tunggu sebentar.');
        }
        $submission_lock = $lock_name;

        $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(NOW()) AS sekarang,
            UNIX_TIMESTAMP(MAX(TIMESTAMP(tanggal, waktu))) AS terakhir_unix
            FROM keluhan_wifi WHERE kontak = ?");
        $stmt->execute([$clean_number]);
        $recent = $stmt->fetch();
        $last_submission = (int) ($recent['terakhir_unix'] ?? 0);
        $remaining = (3 * 60 * 60) - ((int) $recent['sekarang'] - $last_submission);
        if ($remaining > 0) {
            throw new Exception('Keluhan sebelumnya sudah terkirim. Silakan tunggu ' . (int) ceil($remaining / 60) . ' menit sebelum mengirim keluhan lagi.');
        }
        
        $pdo->beginTransaction();

        // Simpan detail keluhan. ID internal ini tetap dipakai sebagai relasi,
        // sedangkan nomor yang diberikan ke pengguna adalah ID maintenance.
        $stmt = $pdo->prepare("
            INSERT INTO keluhan_wifi 
            (nama_pelapor, unit_lembaga, lokasi, jenis_masalah, kontak, keluhan, tanggal, waktu, status) 
            VALUES (?, ?, ?, ?, ?, ?, CURDATE(), CURTIME(), 'baru')
        ");
        
        $stmt->execute([
            $nama_pelapor,
            $unit_lembaga,
            $lokasi,
            $jenis_masalah,
            $clean_number,
            $keluhan
        ]);
        
        // Buat laporan placeholder agar setiap keluhan langsung memperoleh
        // nomor tunggal dari sequence maintenance.
        $keluhan_id = $pdo->lastInsertId();
        $stmt = $pdo->prepare("
            INSERT INTO maintenance
                (keluhan_id, tanggal, waktu, nama_pengguna, unit_lembaga, keluhan, tindakan, hasil, catatan)
            VALUES (?, CURDATE(), CURTIME(), ?, ?, ?, '', 'Belum selesai', '')
        ");
        $stmt->execute([$keluhan_id, $nama_pelapor, $unit_lembaga, $keluhan]);
        $maintenance_id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("UPDATE keluhan_wifi SET maintenance_id = ? WHERE id = ?");
        $stmt->execute([$maintenance_id, $keluhan_id]);
        $pdo->commit();
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$submission_lock]);
        $submission_lock = null;

        // Notifikasi tidak boleh membatalkan laporan yang sudah berhasil tersimpan.
        notify_telegram_new_complaint([
            'maintenance_id' => $maintenance_id,
            'keluhan_id' => $keluhan_id,
            'nama_pelapor' => $nama_pelapor,
            'unit_lembaga' => $unit_lembaga,
            'lokasi' => $lokasi,
            'keluhan' => $keluhan,
        ]);
        
        // Debug: Tampilkan data yang disimpan
        // Redirect ke halaman sukses
        header("Location: sukses_keluhan.php?id=" . $maintenance_id);
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($submission_lock !== null) {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$submission_lock]);
        }
        $_SESSION['form_error'] = $e->getMessage();
        $_SESSION['form_data'] = $_POST;
        header("Location: index.php");
        exit;
    }
} else {
    // Jika akses langsung, redirect ke form
    header("Location: index.php");
    exit;
}
?>
