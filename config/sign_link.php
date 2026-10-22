<?php
/**
 * Helper link tanda tangan publik (tanpa login).
 *
 * Setiap laporan maintenance punya satu token permanen sepanjang 43 karakter
 * hasil encode base64url dari 32 byte acak (256 bit entropi), sehingga tidak
 * mungkin ditebak atau di-brute force. Token hanya memberi dua kemampuan:
 * membaca satu laporan itu, dan mengisi tanda tangan pengguna satu kali.
 *
 * Token disimpan apa adanya (bukan hash) karena admin perlu bisa menyalin
 * ulang link yang sama berkali-kali dari halaman laporan.
 */

// Isi bila link harus selalu memakai domain tertentu, contoh:
// 'https://network.unisba.ac.id'. Dibiarkan kosong = ikut host request.
if (!defined('SIGN_LINK_BASE_URL')) {
    define('SIGN_LINK_BASE_URL', '');
}

// Nama halaman publik, relatif terhadap root situs.
if (!defined('SIGN_LINK_PAGE')) {
    define('SIGN_LINK_PAGE', 'ttd.php');
}

// Kosong = deteksi otomatis dari URL aplikasi. Isi, misalnya
// '/servicenet', bila aplikasi dipasang di subfolder tertentu.
if (!defined('SIGN_LINK_BASE_PATH')) {
    define('SIGN_LINK_BASE_PATH', '');
}

// Batas ukuran PNG tanda tangan yang diterima.
if (!defined('SIGN_MAX_PNG_BYTES')) {
    define('SIGN_MAX_PNG_BYTES', 2 * 1024 * 1024);
}

if (!function_exists('signTokenGenerate')) {

    /**
     * Buat token acak URL-safe sepanjang 43 karakter.
     */
    function signTokenGenerate()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Periksa bentuk token sebelum menyentuh database.
     */
    function signTokenIsWellFormed($token)
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    /**
     * Ambil baris token milik sebuah laporan, atau null bila belum pernah dibuat.
     */
    function signTokenFind(PDO $pdo, $maintenance_id)
    {
        $stmt = $pdo->prepare("SELECT * FROM sign_token WHERE maintenance_id = ?");
        $stmt->execute([$maintenance_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Ambil token laporan; dibuat otomatis bila belum ada.
     *
     * @return array Baris tabel sign_token.
     */
    function signTokenEnsure(PDO $pdo, $maintenance_id, $staff_id = null)
    {
        $existing = signTokenFind($pdo, $maintenance_id);
        if ($existing !== null) {
            return $existing;
        }

        // Balapan antar dua admin yang membuka laporan sama pada saat
        // bersamaan diselesaikan oleh UNIQUE KEY di kolom maintenance_id:
        // yang kalah cukup membaca ulang token yang sudah ada.
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO sign_token (maintenance_id, token, dibuat_oleh) VALUES (?, ?, ?)"
            );
            $stmt->execute([$maintenance_id, signTokenGenerate(), $staff_id]);
        } catch (PDOException $e) {
            $retry = signTokenFind($pdo, $maintenance_id);
            if ($retry !== null) {
                return $retry;
            }
            throw $e;
        }

        return signTokenFind($pdo, $maintenance_id);
    }

    /**
     * Ganti token laporan dengan yang baru. Dipakai bila link lama terlanjur
     * tersebar ke pihak yang tidak berkepentingan.
     *
     * @return array Baris sign_token yang sudah diperbarui.
     */
    function signTokenRegenerate(PDO $pdo, $maintenance_id, $staff_id = null)
    {
        signTokenEnsure($pdo, $maintenance_id, $staff_id);

        $stmt = $pdo->prepare(
            "UPDATE sign_token
                SET token = ?, dibuat_oleh = ?, dibuat_pada = CURRENT_TIMESTAMP
              WHERE maintenance_id = ?"
        );
        $stmt->execute([signTokenGenerate(), $staff_id, $maintenance_id]);

        return signTokenFind($pdo, $maintenance_id);
    }

    /**
     * Cari laporan berdasarkan token publik.
     *
     * @return array|null Gabungan kolom laporan, nama staff, dan data token.
     */
    function signTokenResolve(PDO $pdo, $token)
    {
        if (!signTokenIsWellFormed($token)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT m.*,
                    GROUP_CONCAT(s.nama ORDER BY ms.urutan SEPARATOR ' - ') AS staff_names,
                    t.id           AS token_id,
                    t.token        AS token,
                    t.dipakai_pada AS token_dipakai_pada
               FROM sign_token t
               JOIN maintenance m ON m.id = t.maintenance_id
          LEFT JOIN maintenance_staff ms ON ms.maintenance_id = m.id
          LEFT JOIN staff_it s ON s.id = ms.staff_id
              WHERE t.token = ?
           GROUP BY m.id"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Alamat dasar situs, dipakai untuk merangkai link absolut yang bisa
     * ditempel ke WhatsApp.
     */
    function signLinkBaseUrl()
    {
        if (SIGN_LINK_BASE_URL !== '') {
            return rtrim(SIGN_LINK_BASE_URL, '/');
        }

        $forwarded = isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            ? strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'])
            : '';
        $is_https = $forwarded === 'https'
            || (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        // Host header dikendalikan klien, jadi hanya diterima bila bentuknya
        // memang nama host yang wajar.
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if (preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host) !== 1) {
            $host = 'network.unisba.ac.id';
        }

        return ($is_https ? 'https://' : 'http://') . $host;
    }

    /**
     * Link publik lengkap untuk sebuah token.
     */
    function signLinkUrl($token)
    {
        return signLinkBaseUrl() . signLinkAppPath() . '/' . SIGN_LINK_PAGE . '?t=' . rawurlencode($token);
    }

    /** Path aplikasi saat dipasang pada subfolder, tanpa garis miring akhir. */
    function signLinkAppPath()
    {
        if (SIGN_LINK_BASE_PATH !== '') {
            return '/' . trim(SIGN_LINK_BASE_PATH, '/');
        }

        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
        if ($script === '') {
            return '';
        }

        // Endpoint admin berada satu direktori di bawah root aplikasi.
        $adminMarker = '/admin/';
        $adminPos = strpos($script, $adminMarker);
        if ($adminPos !== false) {
            return rtrim(substr($script, 0, $adminPos), '/');
        }

        $path = str_replace('\\', '/', dirname($script));
        return $path === '/' || $path === '.' ? '' : rtrim($path, '/');
    }

    /**
     * Laporan dianggap sudah ditandatangani bila berkas tanda tangan pengguna
     * sudah tercatat, dari mana pun asalnya (form admin maupun link publik).
     */
    function signIsSigned(array $report)
    {
        return isset($report['tanda_tangan_pengguna'])
            && trim((string) $report['tanda_tangan_pengguna']) !== '';
    }

    /**
     * Ubah data URL dari signature pad menjadi biner PNG yang sudah divalidasi.
     *
     * @param  string      $data_url Isi field tersembunyi dari signature pad.
     * @param  string|null $error    Diisi pesan kesalahan bila gagal.
     * @return string|null Biner PNG, atau null bila tidak valid.
     */
    function signDecodePng($data_url, &$error = null)
    {
        $error = null;

        if (!is_string($data_url) || trim($data_url) === '') {
            $error = 'Tanda tangan belum digambar.';
            return null;
        }

        // Data URL selalu lebih besar dari binernya (base64 ~133%), jadi
        // pembatasan awal ini mencegah decode payload raksasa.
        if (strlen($data_url) > SIGN_MAX_PNG_BYTES * 2) {
            $error = 'Ukuran tanda tangan terlalu besar.';
            return null;
        }

        if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $data_url, $m) !== 1) {
            $error = 'Format tanda tangan tidak dikenali.';
            return null;
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($binary === false || strlen($binary) < 100) {
            $error = 'Data tanda tangan rusak.';
            return null;
        }

        if (strlen($binary) > SIGN_MAX_PNG_BYTES) {
            $error = 'Ukuran tanda tangan terlalu besar.';
            return null;
        }

        // Cocokkan magic bytes PNG lalu pastikan GD benar-benar mengenalinya,
        // supaya file lain tidak bisa diselundupkan lewat data URL.
        if (strncmp($binary, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            $error = 'Berkas tanda tangan bukan PNG.';
            return null;
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            $error = 'Berkas tanda tangan bukan PNG yang sah.';
            return null;
        }

        return $binary;
    }

    /**
     * Alamat IP pengunjung, untuk jejak audit tanda tangan.
     */
    function signClientIp()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
