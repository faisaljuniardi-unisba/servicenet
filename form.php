<?php
session_start();
include 'config/database.php';
include 'config/roles.php';
require_once 'config/sign_link.php';
require_once 'config/upload_security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Form maintenance hanya boleh diakses oleh peran yang berwenang mengelola laporan.
if (!canManageReports($_SESSION['jabatan'] ?? '')) {
    header('Location: admin/dashboard.php?error=unauthorized');
    exit;
}

if (empty($_SESSION['maintenance_csrf'])) {
    $_SESSION['maintenance_csrf'] = bin2hex(random_bytes(32));
}

// ... kode selanjutnya

// Ambil data staff IT untuk pilihan
$query = "SELECT id, nama, jabatan FROM staff_it WHERE status = 'active'";
// Super Admin tidak perlu muncul di list staff, tapi Koordinator IT perlu muncul
$query .= " AND jabatan != 'Super Admin'";
$stmt = $pdo->query($query);
$staff_list = $stmt->fetchAll();

$keluhan_data = null;
$locked_staff_ids = [];

if (isset($_GET['keluhan_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM keluhan_wifi WHERE id = ?");
    $stmt->execute([$_GET['keluhan_id']]);
    $keluhan_data = $stmt->fetch();

    if (!$keluhan_data) {
        die('Keluhan tidak ditemukan');
    }

    if (!empty($keluhan_data['maintenance_id'])) {
        $stmt = $pdo->prepare("SELECT staff_id FROM maintenance_staff WHERE maintenance_id = ? ORDER BY urutan, id");
        $stmt->execute([$keluhan_data['maintenance_id']]);
        $locked_staff_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['maintenance_csrf'], (string) $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan muat ulang formulir.');
    }
    // Proses form submission
    $tanggal = $_POST['tanggal'];
    $waktu = $_POST['waktu'];
    $selected_staff = $_POST['staff_it'] ?? [];
    $nama_pengguna = $_POST['nama_pengguna'];
    $unit_lembaga = $_POST['unit_lembaga'];
    $keluhan = $_POST['keluhan'];
    $tindakan = $_POST['tindakan'];
    $hasil = $_POST['hasil'];
    // Nilai lama "Normal kembali" dipetakan ke enum database yang sah.
    if ($hasil === 'Normal kembali') {
        $hasil = 'Selesai';
    }
    if (!in_array($hasil, ['Selesai', 'Perlu monitoring', 'Belum selesai'], true)) {
        header('Location: form.php?error=invalid_status');
        exit;
    }
    $catatan = $_POST['catatan'];
    $staff_pemilik_tanda_tangan = $_SESSION['nama'];

    // Data pelapor selalu bersumber dari keluhan asli; nilai POST tidak boleh
    // mengubah identitas, unit, ataupun isi keluhan yang sudah dikirim.
    $keluhan_id_post = (int) ($_POST['keluhan_id'] ?? 0);
    if ($keluhan_id_post > 0) {
        $stmt = $pdo->prepare("SELECT nama_pelapor, unit_lembaga, keluhan FROM keluhan_wifi WHERE id = ?");
        $stmt->execute([$keluhan_id_post]);
        $locked_keluhan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$locked_keluhan) {
            header('Location: admin/keluhan_list.php?error=notfound');
            exit;
        }
        $nama_pengguna = $locked_keluhan['nama_pelapor'];
        $unit_lembaga = $locked_keluhan['unit_lembaga'];
        $keluhan = $locked_keluhan['keluhan'];
    }
    
    // Handle file upload (support multiple files)
    $lampiran_foto = ''; // akan menyimpan JSON array nama file jika ada
    if (isset($_FILES['lampiran_foto'])) {
        $target_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'photos';

        $uploaded_photos = [];

        // Normalize structure: PHP memberikan array untuk multiple inputs
        $file_count = is_array($_FILES['lampiran_foto']['name']) ? count($_FILES['lampiran_foto']['name']) : 0;

        for ($i = 0; $i < $file_count; $i++) {
            $error = $_FILES['lampiran_foto']['error'][$i];
            if ($error !== UPLOAD_ERR_OK) {
                error_log("Skipping file index {$i} due to upload error: " . $error);
                continue;
            }

            $file = [
                'error' => $_FILES['lampiran_foto']['error'][$i],
                'name' => $_FILES['lampiran_foto']['name'][$i],
                'tmp_name' => $_FILES['lampiran_foto']['tmp_name'][$i],
                'size' => $_FILES['lampiran_foto']['size'][$i],
            ];
            $uploadError = null;
            $unique_name = storeSafeUploadedImage($file, $target_dir, 'photo', $uploadError);
            if ($unique_name !== null) {
                $uploaded_photos[] = $unique_name;
            } else {
                error_log('Foto ditolak: ' . $uploadError);
            }
        }

        if (!empty($uploaded_photos)) {
            // Simpan sebagai JSON agar mudah disimpan dan diambil kembali
            $lampiran_foto = json_encode($uploaded_photos);
        } else {
            error_log("No valid uploaded photos found");
        }
    } else {
        error_log("No file field for 'lampiran_foto' present in request");
    }
    
    // Handle signature IT
    $tanda_tangan_it = '';
    if (!empty($_POST['signature_it'])) {
        $signatureError = null;
        $decoded_data = signDecodePng($_POST['signature_it'], $signatureError);
        
        $target_dir = "uploads/signatures/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        $tanda_tangan_it = "signature_it_" . $_SESSION['user_id'] . "_" . time() . ".png";
        if ($decoded_data !== null && file_put_contents($target_dir . $tanda_tangan_it, $decoded_data, LOCK_EX)) {
            @chmod($target_dir . $tanda_tangan_it, 0644);
            error_log("Signature IT saved: " . $tanda_tangan_it);
        } else {
            $tanda_tangan_it = '';
            error_log('Tanda tangan IT ditolak atau gagal disimpan: ' . ($signatureError ?? 'data tidak valid'));
        }
    } else {
        error_log("No signature IT data");
    }
    
    // Handle signature pengguna
    $tanda_tangan_pengguna = '';
    if (!empty($_POST['signature_pengguna'])) {
        $signatureError = null;
        $decoded_data = signDecodePng($_POST['signature_pengguna'], $signatureError);
        
        $target_dir = "uploads/signatures/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        $tanda_tangan_pengguna = "signature_pengguna_" . time() . ".png";
        if ($decoded_data !== null && file_put_contents($target_dir . $tanda_tangan_pengguna, $decoded_data, LOCK_EX)) {
            @chmod($target_dir . $tanda_tangan_pengguna, 0644);
            error_log("Signature pengguna saved: " . $tanda_tangan_pengguna);
        } else {
            $tanda_tangan_pengguna = '';
            error_log('Tanda tangan pengguna ditolak atau gagal disimpan: ' . ($signatureError ?? 'data tidak valid'));
        }
    } else {
        error_log("No signature pengguna data");
    }
    
    try {
        $pdo->beginTransaction();
        
        $keluhan_id = (int) ($_POST['keluhan_id'] ?? 0);
        $maintenance_id = null;

        // Keluhan publik sudah memiliki placeholder maintenance. Lengkapi
        // baris itu agar nomor laporan yang diberikan ke pelapor tidak berubah.
        if ($keluhan_id > 0) {
            $stmt = $pdo->prepare("SELECT maintenance_id FROM keluhan_wifi WHERE id = ? FOR UPDATE");
            $stmt->execute([$keluhan_id]);
            $maintenance_id = (int) $stmt->fetchColumn();
        }

        if ($maintenance_id > 0) {
            $stmt = $pdo->prepare("UPDATE maintenance SET tanggal = ?, waktu = ?, nama_pengguna = ?, unit_lembaga = ?, keluhan = ?, tindakan = ?, hasil = ?, catatan = ?, lampiran_foto = ?, tanda_tangan_it = ?, tanda_tangan_pengguna = ?, staff_pemilik_tanda_tangan = ? WHERE id = ?");
            $result = $stmt->execute([$tanggal, $waktu, $nama_pengguna, $unit_lembaga, $keluhan, $tindakan, $hasil, $catatan, $lampiran_foto, $tanda_tangan_it, $tanda_tangan_pengguna, $staff_pemilik_tanda_tangan, $maintenance_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO maintenance (keluhan_id, tanggal, waktu, nama_pengguna, unit_lembaga, keluhan, tindakan, hasil, catatan, lampiran_foto, tanda_tangan_it, tanda_tangan_pengguna, staff_pemilik_tanda_tangan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([$keluhan_id ?: null, $tanggal, $waktu, $nama_pengguna, $unit_lembaga, $keluhan, $tindakan, $hasil, $catatan, $lampiran_foto, $tanda_tangan_it, $tanda_tangan_pengguna, $staff_pemilik_tanda_tangan]);
            $maintenance_id = (int) $pdo->lastInsertId();
        }

        if (!$result) {
            throw new Exception("Gagal menyimpan laporan maintenance");
        }

// ==================================
// LOGIKA KELUHAN
// ==================================

if (!empty($_POST['keluhan_id'])) {

    $status_keluhan = ($hasil === 'Selesai')
        ? 'selesai'
        : 'diproses';

    $stmtKeluhan = $pdo->prepare("
        UPDATE keluhan_wifi
        SET status = ?, maintenance_id = ?
        WHERE id = ?
    ");
    $stmtKeluhan->execute([
        $status_keluhan,
        $maintenance_id,
        $_POST['keluhan_id']
    ]);

} else {

    if ($hasil !== 'Selesai') {

        $stmtKeluhanBaru = $pdo->prepare("
            INSERT INTO keluhan_wifi (
                nama_pelapor,
                unit_lembaga,
                kontak,
                keluhan,
                tanggal,
                waktu,
                status,
                maintenance_id
            ) VALUES (?, ?, ?, ?, ?, ?, 'diproses', ?)
        ");

        $stmtKeluhanBaru->execute([
            $nama_pengguna,
            $unit_lembaga,
            '-',
            $keluhan,
            $tanggal,
            $waktu,
            $maintenance_id
        ]);
    }
}

// ==================================
// INSERT maintenance_staff
// ==================================

$stmt_existing_staff = $pdo->prepare("SELECT staff_id FROM maintenance_staff WHERE maintenance_id = ? ORDER BY urutan, id FOR UPDATE");
$stmt_existing_staff->execute([$maintenance_id]);
$existing_staff_ids = array_map('intval', $stmt_existing_staff->fetchAll(PDO::FETCH_COLUMN));

// Penugasan pertama ditentukan dari form (pemilik akun selalu terlibat).
// Pada pembaruan berikutnya, penugasan yang sudah tersimpan dipakai apa
// adanya agar tidak ganda ataupun berubah saat pekerjaan dimonitoring.
if (empty($existing_staff_ids)) {
    $sorted_staff = [(int) $_SESSION['user_id']];
    foreach ($selected_staff as $staff_id) {
        $staff_id = (int) $staff_id;
        if ($staff_id > 0 && !in_array($staff_id, $sorted_staff, true)) {
            $sorted_staff[] = $staff_id;
        }
    }

    $stmt_staff = $pdo->prepare("INSERT INTO maintenance_staff (maintenance_id, staff_id, urutan) VALUES (?, ?, ?)");
    foreach ($sorted_staff as $urutan => $staff_id) {
        $stmt_staff->execute([$maintenance_id, $staff_id, $urutan]);
    }
}

$pdo->commit();

// Token link tanda tangan publik dibuat sekalian, supaya admin bisa
// langsung menyalin linknya dari pesan sukses.
$sign_redirect = 'form.php?success=1';
try {
    $sign_token_row = signTokenEnsure($pdo, $maintenance_id, $_SESSION['user_id']);
    $sign_redirect .= '&t=' . rawurlencode($sign_token_row['token']);
} catch (Exception $e) {
    error_log('Gagal membuat token tanda tangan: ' . $e->getMessage());
}

header('Location: ' . $sign_redirect);
exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $error = "Terjadi kesalahan: " . $e->getMessage();
    error_log("Database error: " . $e->getMessage());
}

}

// Data link tanda tangan publik, hanya diisi tepat setelah laporan tersimpan.
$sign_link = null;
if (isset($_GET['success']) && isset($_GET['t'])) {
    $sign_row = signTokenResolve($pdo, $_GET['t']);
    if ($sign_row !== null) {
        $sign_link = [
            'url'     => signLinkUrl($sign_row['token']),
            'nama'    => $sign_row['nama_pengguna'],
            'whatsapp' => null,
        ];

        // Nomor kontak pelapor dipakai untuk tombol kirim WhatsApp.
        if (!empty($sign_row['keluhan_id'])) {
            $stmt_kontak = $pdo->prepare("SELECT kontak FROM keluhan_wifi WHERE id = ?");
            $stmt_kontak->execute([$sign_row['keluhan_id']]);
            $kontak = preg_replace('/\D/', '', (string) $stmt_kontak->fetchColumn());
            if (strlen($kontak) >= 9) {
                $sign_link['whatsapp'] = $kontak;
            }
        }
    }
}

// Daftar Fakultas / Unit (dipakai untuk dropdown)
$daftar_unit = require __DIR__ . '/config/units.php';

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/x-icon" href="uploads/icon/favicon.ico">
    <title>Form Service</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/theme.css">
    <link rel="stylesheet" href="css/unit-picker.css">
    <script src="js/theme.js"></script>
    <script src="js/unit-picker.js" defer></script>
    <style>
    /* Tambahkan style untuk signature pad agar lebih stabil di mobile */
    .signature-pad {
        position: relative;
        margin-bottom: 15px;
    }
    
    .signature-pad {
        width: 100%;
        height: 200px;
    }
    
    .signature-pad canvas {
        width: 100%;
        height: 100%;
        display: block;
        background-color: #f8f9fa;
        border-radius: 4px;
        border: 1px solid #ddd;
    }
    
    .signature-actions {
        display: flex;
        gap: 10px;
        margin-top: 10px;
    }
    
    .btn-clear, .btn-save-signature {
        padding: 8px 15px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        flex: 1;
    }
    
    .btn-clear {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .btn-save-signature {
        background-color: #d4edda;
        color: #155724;
    }
    
    .btn-clear:hover {
        background-color: #f5c6cb;
    }
    
    .btn-save-signature:hover {
        background-color: #c3e6cb;
    }
    
    .signature-saved {
        background-color: #d4edda;
        color: #155724;
        padding: 3px 8px;
        border-radius: 3px;
        font-weight: bold;
    }

    /* Panel link tanda tangan publik, tampil setelah laporan tersimpan. */
    .sign-link-panel {
        margin: 0 0 20px;
        padding: 20px 22px;
        border: 1px solid #d9dcf0;
        border-left: 4px solid #667eea;
        border-radius: 12px;
        background: linear-gradient(180deg, #f8f9ff, #f1f3fc);
    }

    .sign-link-panel h3 {
        margin: 0 0 6px;
        font-size: 1.02rem;
        color: #3c4260;
    }

    .sign-link-desc {
        margin: 0 0 14px;
        font-size: .88rem;
        color: #5b6079;
        line-height: 1.55;
    }

    .sign-link-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .sign-link-input {
        flex: 1 1 260px;
        min-width: 0;
        padding: 11px 14px;
        border: 1px solid #ccd1e8;
        border-radius: 8px;
        background: #fff;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: .82rem;
        color: #3c4260;
    }

    .sign-link-input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, .16);
    }

    .sign-link-copy,
    .sign-link-wa {
        flex: 0 0 auto;
        padding: 11px 20px;
        border: none;
        border-radius: 8px;
        font-family: inherit;
        font-size: .88rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        transition: transform .18s cubic-bezier(.16,1,.3,1), box-shadow .18s cubic-bezier(.16,1,.3,1), background-color .18s;
    }

    .sign-link-copy {
        background: linear-gradient(120deg, #667eea, #764ba2);
        color: #fff;
        box-shadow: 0 10px 20px -12px rgba(102, 126, 234, .9);
    }

    .sign-link-wa {
        background: #1faa53;
        color: #fff;
        box-shadow: 0 10px 20px -12px rgba(31, 170, 83, .9);
    }

    .sign-link-copy:hover,
    .sign-link-wa:hover { transform: translateY(-2px); }

    .sign-link-copy.copied { background: #1e7a4c; }

    .sign-link-copy:focus-visible,
    .sign-link-wa:focus-visible { outline: 3px solid #667eea; outline-offset: 2px; }

    .sign-link-note {
        margin: 12px 0 0;
        font-size: .8rem;
        color: #7c8199;
    }

    /* Tampilan Form Service mengikuti tema sederhana area admin. */
    body {
        background: #f7f7fa;
    }

    .container {
        max-width: 1180px;
        margin: 24px auto;
        padding: 0 28px 28px;
        background: #fff;
        border: 1px solid #e9e8ef;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(34, 31, 59, .07);
    }

    .container > header {
        margin: 0 -28px 24px;
        padding: 18px 28px;
        background: #fff;
        color: #33323d;
        border: 0;
        border-bottom: 1px solid #ecebf1;
        border-radius: 12px 12px 0 0;
    }

    header h1 {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #4e4c5c;
        font-size: 1.4rem;
    }

    .form-title-logo {
        width: 30px;
        height: 30px;
        object-fit: contain;
    }

    .header-actions { gap: 10px; }
    .header-actions .btn-back {
        margin: 0 !important;
        padding: 8px 12px !important;
        color: #5f53d4 !important;
        background: #f0efff !important;
        border: 1px solid #ddd9ff !important;
        border-radius: 6px !important;
        box-shadow: none !important;
    }
    .header-actions .btn-back:hover { background: #e8e5ff !important; transform: none; }
    .user-info { padding: 7px 12px; color: #5f5d6b; background: #f7f7fa; border-radius: 7px; }
    .user-info .btn-logout { color: #5f53d4; background: transparent; border: 0; }

    .form-section {
        margin-bottom: 18px;
        padding: 22px;
        background: #fff;
        border: 1px solid #e9e8ef;
        border-left: 0;
        border-radius: 10px;
        box-shadow: none;
    }
    .form-section:hover { transform: none; }
    .form-section h2 {
        margin-bottom: 18px;
        color: #4e4c5c;
        font-size: 1.08rem;
    }
    .form-section h2::before { display: none; }
    .form-group { margin-bottom: 16px; }
    label { margin-bottom: 6px; color: #565463; font-size: .9rem; }
    input[type="text"], input[type="date"], input[type="time"], input[type="file"], select, textarea {
        padding: 10px 12px;
        border: 1px solid #d9d8e2;
        border-radius: 6px;
    }
    .checkbox-group { border: 1px solid #dfdee6; background: #fbfbfd; }
    .checkbox-label:hover { background: #f2f1f8; }

    .signature-section { gap: 18px; }
    .signature-box {
        padding: 18px;
        border: 1px solid #dedde6;
        border-radius: 8px;
        background: #fff;
    }
    .signature-box h3 {
        margin-bottom: 6px;
        color: #565463;
        font-size: 1rem;
    }
    .signature-person {
        margin: 0 0 10px;
        color: #565463;
        font-size: .82rem;
        font-weight: 600;
        line-height: 1.4;
        text-align: center;
    }
    .signature-person strong { font: inherit; }
    .signature-pad { margin-bottom: 12px; border: 1px dashed #d8d7e0; border-radius: 6px; }
    .signature-pad canvas { background: #fafafe; border: 0; border-radius: 6px; }
    .signature-actions { gap: 8px; margin-top: 8px; }
    .btn-clear, .btn-save-signature {
        padding: 8px 12px;
        border: 1px solid transparent;
        border-radius: 6px;
        font: 600 .84rem/1.2 inherit;
    }
    .btn-clear { color: #b44055; background: #fff1f3; border-color: #ffd8df; }
    .btn-save-signature { color: #247349; background: #eef9f2; border-color: #d2eedc; }
    .btn-clear:hover { background: #ffe6ea; transform: none; }
    .btn-save-signature:hover { background: #e2f5e9; }
    .signature-status { color: #73717f !important; }

    .form-actions { margin-top: 22px; padding-top: 20px; border-top: 1px solid #e9e8ef; }
    .btn-submit, .btn-reset {
        min-width: 150px;
        padding: 10px 18px;
        border-radius: 6px;
        box-shadow: none;
        font-size: .9rem;
    }
    .btn-submit { background: #3f9d68; }
    .btn-reset { background: #767482; }
    .btn-submit:hover, .btn-reset:hover { transform: none; box-shadow: none; }

    .success-message, .error, .sign-link-panel { margin-bottom: 18px; }

    @media (max-width: 768px) {
        .container { margin: 0; padding: 0 16px 20px; border: 0; border-radius: 0; }
        .container > header { margin: 0 -16px 20px; padding: 16px; border-radius: 0; }
        .container > header > div {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center !important;
        }
        header h1 { font-size: 1.2rem; text-align: left; }
        .theme-in-service-header { grid-column: 2; grid-row: 1; margin: 0; }
        .header-actions {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: center;
            gap: 8px;
            width: 100%;
        }
        .header-actions .btn-back { width: 100%; justify-content: center; margin: 0 !important; }
        .header-actions .user-info {
            width: 100%;
            flex-direction: row;
            justify-content: center;
            gap: 8px;
            padding: 7px 9px;
            font-size: .78rem;
            text-align: center;
        }
        .header-actions .user-info span { min-width: 0; }
        .header-actions .user-info .btn-logout {
            padding: 4px 7px;
            color: #c53f61;
            background: #fff0f3;
            border: 1px solid #f2ccd6;
            border-radius: 5px;
            font-weight: 750;
            text-decoration: none;
        }
        .signature-section { grid-template-columns: 1fr; }
    }

    @media (prefers-reduced-motion: reduce) {
        .sign-link-copy, .sign-link-wa { transition: none; }
        .sign-link-copy:hover, .sign-link-wa:hover { transform: none; }
    }
</style>
</head>
<body>
    <div class="container">
        <header>
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <h1><img src="uploads/images/logo-unisba.png" class="form-title-logo" alt="Logo Unisba">Form Service</h1>
                <div class="header-actions">
                    <?php 
                    // Debug langsung di HTML untuk melihat apa yang terjadi
                    $canAccess = canAccessAdmin($_SESSION['jabatan']);
                    error_log("Rendering header - Can access admin: " . ($canAccess ? 'YES' : 'NO'));
                    ?>
                    
                    <?php if ($canAccess): ?>
                        <a href="admin/dashboard.php" class="btn-back" style="background: linear-gradient(135deg, #667eea, #764ba2); margin-right: 10px; text-decoration: none; color: white; padding: 10px 15px; border-radius: 5px; display: inline-flex; align-items: center; gap: 5px;">
                            Halaman Admin
                        </a>
                    <?php endif; ?>
                    
                    <div class="user-info">
                        <span><?php echo htmlspecialchars($_SESSION['nama'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <a href="logout.php" class="btn-logout">Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">
                Data maintenance berhasil disimpan!
            </div>

            <?php if ($sign_link !== null): ?>
                <?php $wa_text = rawurlencode("Assalamu'alaikum. Berikut laporan layanan jaringan Psitek Unisba. Mohon berkenan menandatangani sebagai bukti pekerjaan telah selesai:\n" . $sign_link['url']); ?>
                <div class="sign-link-panel">
                    <h3>Link Tanda Tangan Pengguna</h3>
                    <p class="sign-link-desc">
                        Kirim link ini ke <strong><?= htmlspecialchars($sign_link['nama'], ENT_QUOTES, 'UTF-8'); ?></strong> untuk di tanda tangan.
                    </p>
                    <div class="sign-link-row">
                        <input type="text" id="signLinkInput" class="sign-link-input" readonly
                               value="<?= htmlspecialchars($sign_link['url'], ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="button" class="sign-link-copy" id="signLinkCopy">Salin</button>
                        <?php if ($sign_link['whatsapp'] !== null): ?>
                            <a class="sign-link-wa" target="_blank" rel="noopener"
                               href="https://wa.me/<?= htmlspecialchars($sign_link['whatsapp'], ENT_QUOTES, 'UTF-8'); ?>?text=<?= $wa_text; ?>">WhatsApp</a>
                        <?php endif; ?>
                    </div>
                    <p class="sign-link-note">Link berlaku sampai ditandatangani. Setelah ditandatangani, link tidak bisa dipakai lagi.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="maintenance-form" id="maintenanceForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['maintenance_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="keluhan_id"
           value="<?= htmlspecialchars($keluhan_data['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-section">
                <h2>Informasi Umum</h2>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="tanggal">Hari/Tanggal:</label>
                        <input type="date" id="tanggal" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="waktu">Waktu:</label>
                        <input type="time" id="waktu" name="waktu" value="<?php echo date('H:i'); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Staff yang Terlibat:</label>
                    <div class="checkbox-group">
                        <?php foreach ($staff_list as $staff): ?>
                            <?php if (showInStaffList($staff['jabatan'])): ?>
                                <?php
                                $is_current_staff = (int) $staff['id'] === (int) $_SESSION['user_id'];
                                $is_assignment_locked = !empty($locked_staff_ids);
                                $is_checked = $is_assignment_locked
                                    ? in_array((int) $staff['id'], $locked_staff_ids, true)
                                    : $is_current_staff;
                                ?>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="staff_it[]" value="<?php echo $staff['id']; ?>" 
                                        <?php echo $is_checked ? 'checked' : ''; ?>
                                        <?php echo ($is_assignment_locked || $is_current_staff) ? 'disabled' : ''; ?>>
                                    <?php if ($is_checked && ($is_assignment_locked || $is_current_staff)): ?>
                                        <input type="hidden" name="staff_it[]" value="<?php echo $staff['id']; ?>">
                                    <?php endif; ?>
                                    <?php echo $staff['nama']; ?>
                                    <?php if ($is_current_staff): ?>
                                    <?php elseif ($is_assignment_locked && $is_checked): ?>
                                    <?php endif; ?>
                                    <!-- <small style="color: #888; font-style: italic;">(<?php echo $staff['jabatan']; ?>)</small> -->
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Informasi Pengguna</h2>
                
                <div class="form-group">
                    <label for="nama_pengguna">Nama Pengguna:</label>
                    <input type="text" id="nama_pengguna" name="nama_pengguna"
       value="<?= htmlspecialchars($keluhan_data['nama_pelapor'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required <?= $keluhan_data ? 'readonly' : ''; ?>>

                </div>
                
                <div class="form-group">
                    <label for="unit_lembaga">Fakultas/Unit/Lembaga:</label>
                    <select id="unit_lembaga" name="unit_lembaga" required <?= $keluhan_data ? 'disabled' : ''; ?>>
                        <option value="">-- Pilih Fakultas / Unit --</option>

                        <?php foreach ($daftar_unit as $unit): ?>
                            <option value="<?= htmlspecialchars($unit); ?>"
                                <?= ($keluhan_data['unit_lembaga'] ?? '') === $unit ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($unit); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($keluhan_data): ?>
                        <input type="hidden" name="unit_lembaga" value="<?= htmlspecialchars($keluhan_data['unit_lembaga'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>

                </div>

                <div class="form-group">
                    <label for="lokasi">Lokasi:</label>
                    <input type="text" id="lokasi" value="<?= htmlspecialchars($keluhan_data['lokasi'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label for="keluhan">Keluhan:</label>
                    <textarea id="keluhan" name="keluhan" rows="4" required <?= $keluhan_data ? 'readonly' : ''; ?>><?=
    htmlspecialchars($keluhan_data['keluhan'] ?? '', ENT_QUOTES, 'UTF-8');
?></textarea>

                </div>
            </div>

            <div class="form-section">
                <h2>Tindakan dan Hasil</h2>
                
                <div class="form-group">
                    <label for="tindakan">Tindakan:</label>
                    <textarea id="tindakan" name="tindakan" rows="4" required></textarea>
                </div>
                
                <div class="form-group">
                    <label for="hasil">Hasil:</label>
                    <select id="hasil" name="hasil" required>
                        <option value="">Pilih Hasil</option>
                        <option value="Selesai">Selesai</option>
                        <option value="Perlu monitoring">Perlu monitoring</option>
                        <option value="Belum selesai">Belum selesai</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="catatan">Catatan:</label>
                    <textarea id="catatan" name="catatan" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="lampiran_foto">Lampiran Foto Dokumentasi:</label>
                    <input type="file" id="lampiran_foto" name="lampiran_foto[]" accept="image/*" multiple>
                    <small style="display:block; color:#666;">Anda dapat memilih beberapa foto (maks 5MB per file).</small>
                </div>
            </div>

            <div class="form-section">
    <h2>Tanda Tangan</h2>
    
    <div class="signature-section">
        <div class="signature-box">
            <h3>Tanda Tangan IT</h3>
            <p class="signature-person">
                <strong><?php echo $_SESSION['nama']; ?></strong>
            </p>
            <div class="signature-pad" id="signature-pad-it">
                <canvas width="400" height="200"></canvas>
                <div class="signature-actions">
                    <button type="button" class="btn-clear" data-pad="it">Hapus</button>
                    <button type="button" class="btn-save-signature" data-pad="it">Simpan Tanda Tangan</button>
                </div>
                <div class="signature-status" id="status-it" style="margin-top: 5px; font-size: 12px; color: #666;">
                    Belum disimpan
                </div>
            </div>
            <input type="hidden" name="signature_it" id="signature-it-input">
        </div>
        
        <div class="signature-box">
            <h3>Tanda Tangan Pengguna</h3>
            <p class="signature-person">
                <strong id="pengguna-name-preview">Nama Pengguna</strong>
            </p>
            <div class="signature-pad" id="signature-pad-pengguna">
                <canvas width="400" height="200"></canvas>
                <div class="signature-actions">
                    <button type="button" class="btn-clear" data-pad="pengguna">Hapus</button>
                    <button type="button" class="btn-save-signature" data-pad="pengguna">Simpan Tanda Tangan</button>
                </div>
                <div class="signature-status" id="status-pengguna" style="margin-top: 5px; font-size: 12px; color: #666;">
                    Belum disimpan
                </div>
            </div>
            <input type="hidden" name="signature_pengguna" id="signature-pengguna-input">
        </div>
    </div>
    
</div>

             <div class="form-actions">
                <button type="submit" class="btn-submit" id="submitBtn">
                    Simpan Laporan
                </button>
                <button type="reset" class="btn-reset">
                    Reset Form
                </button>
            </div>
        </form>
    </div>

    <script src="js/signature_pad.umd.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Variables to store signature pads
        let signaturePadIT, signaturePadPengguna;
        let signatureEventsReady = false;
        let savedSignatures = {
            it: null,
            pengguna: null
        };
        
        // Initialize signature pads
        function initializeSignaturePads() {
            const canvasIT = document.querySelector('#signature-pad-it canvas');
            const canvasPengguna = document.querySelector('#signature-pad-pengguna canvas');
            
            // Clear previous instances
            if (signaturePadIT) {
                signaturePadIT.off();
            }
            if (signaturePadPengguna) {
                signaturePadPengguna.off();
            }
            
            const darkTheme = document.documentElement.dataset.theme === 'dark';
            const signatureColors = {
                backgroundColor: darkTheme ? 'rgb(27, 27, 35)' : 'rgb(250, 250, 254)',
                penColor: darkTheme ? 'rgb(236, 234, 242)' : 'rgb(47, 47, 58)'
            };

            // Initialize new instances
            signaturePadIT = new SignaturePad(canvasIT, {
                backgroundColor: signatureColors.backgroundColor,
                penColor: signatureColors.penColor,
                minWidth: 0.5,
                maxWidth: 2.5,
                velocityFilterWeight: 0.7
            });
            
            signaturePadPengguna = new SignaturePad(canvasPengguna, {
                backgroundColor: signatureColors.backgroundColor,
                penColor: signatureColors.penColor,
                minWidth: 0.5,
                maxWidth: 2.5,
                velocityFilterWeight: 0.7
            });
            
            // Adjust canvas size
            resizeCanvas(canvasIT, signaturePadIT);
            resizeCanvas(canvasPengguna, signaturePadPengguna);
            
            // Setup event listeners
            setupSignaturePadEvents();
            
            // Restore saved signatures if they exist
            restoreSavedSignatures();
        }
        
        // Adjust canvas size
        function resizeCanvas(canvas, signaturePad) {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
        
            const width = canvas.offsetWidth;
            const height = canvas.offsetHeight;
        
            canvas.width = width * ratio;
            canvas.height = height * ratio;
        
            const ctx = canvas.getContext("2d");
            ctx.scale(ratio, ratio);
        
            signaturePad.clear(); // penting supaya area sinkron
        }
        
        // Setup event listeners
        function setupSignaturePadEvents() {
            if (signatureEventsReady) {
                return;
            }
            signatureEventsReady = true;

            // Clear buttons
            document.querySelectorAll('.btn-clear').forEach(button => {
                button.addEventListener('click', function() {
                    const pad = this.getAttribute('data-pad');
                    if (pad === 'it') {
                        signaturePadIT.clear();
                        savedSignatures.it = null;
                        updateStatus('it', 'clear');
                    } else {
                        signaturePadPengguna.clear();
                        savedSignatures.pengguna = null;
                        updateStatus('pengguna', 'clear');
                    }
                });
            });
            
            // Save signature buttons
            document.querySelectorAll('.btn-save-signature').forEach(button => {
                button.addEventListener('click', function() {
                    const pad = this.getAttribute('data-pad');
                    saveSignature(pad);
                });
            });
            
            // Prevent scrolling when drawing
            document.querySelectorAll('.signature-pad canvas').forEach(canvas => {
                canvas.addEventListener('touchstart', function(e) {
                    if (e.touches.length === 1) {
                        e.preventDefault();
                    }
                }, { passive: false });
                
                canvas.addEventListener('touchmove', function(e) {
                    if (e.touches.length === 1) {
                        e.preventDefault();
                    }
                }, { passive: false });
            });
        }
        
        // Save signature function
        function saveSignature(padType) {
            let signaturePad, statusElement, inputElement;
            
            if (padType === 'it') {
                signaturePad = signaturePadIT;
                statusElement = document.getElementById('status-it');
                inputElement = document.getElementById('signature-it-input');
            } else {
                signaturePad = signaturePadPengguna;
                statusElement = document.getElementById('status-pengguna');
                inputElement = document.getElementById('signature-pengguna-input');
            }
            
            if (signaturePad.isEmpty()) {
                alert('Silakan gambar tanda tangan terlebih dahulu!');
                return;
            }
            
            // Get signature data
            const signatureData = signaturePad.toDataURL();
            
            // Save to object and hidden input
            savedSignatures[padType] = signatureData;
            inputElement.value = signatureData;
            
            // Update status
            statusElement.innerHTML = '<span class="signature-saved">✓ Tersimpan</span>';
            
            console.log('Signature ' + padType + ' saved successfully');
        }
        
        // Update status display
        function updateStatus(padType, action) {
            const statusElement = document.getElementById('status-' + padType);
            const inputElement = document.getElementById('signature-' + padType + '-input');
            
            if (action === 'clear') {
                statusElement.innerHTML = 'Belum disimpan';
                inputElement.value = '';
            }
        }
        
        // Restore saved signatures to canvas
        function restoreSavedSignatures() {
            const signatureItInput = document.getElementById('signature-it-input');
            const signaturePenggunaInput = document.getElementById('signature-pengguna-input');
            
            if (signatureItInput.value) {
                loadSignatureFromDataURL(signatureItInput.value, 'it');
                savedSignatures.it = signatureItInput.value;
                updateStatus('it', 'restore');
            }
            
            if (signaturePenggunaInput.value) {
                loadSignatureFromDataURL(signaturePenggunaInput.value, 'pengguna');
                savedSignatures.pengguna = signaturePenggunaInput.value;
                updateStatus('pengguna', 'restore');
            }
        }
        
        // Load signature from data URL
        function loadSignatureFromDataURL(dataURL, padType) {
            const img = new Image();
            img.onload = function() {
                let signaturePad;
                if (padType === 'it') {
                    signaturePad = signaturePadIT;
                    document.getElementById('status-it').innerHTML = '<span class="signature-saved">✓ Tersimpan</span>';
                } else {
                    signaturePad = signaturePadPengguna;
                    document.getElementById('status-pengguna').innerHTML = '<span class="signature-saved">✓ Tersimpan</span>';
                }
                
                const ctx = signaturePad._ctx;
                ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
                ctx.drawImage(img, 0, 0);
            };
            img.src = dataURL;
        }
        
        // Form submission validation
        function setupFormValidation() {
            const form = document.getElementById('maintenanceForm');
            const submitBtn = document.getElementById('submitBtn');
            
            form.addEventListener('submit', function(e) {
                console.log('Form submitted');
                
                // Validate required fields
                const requiredFields = form.querySelectorAll('[required]');
                let valid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        valid = false;
                        field.style.borderColor = 'red';
                    } else {
                        field.style.borderColor = '';
                    }
                });
                
                // Validate signatures
                const signatureIt = document.getElementById('signature-it-input').value;
                const signaturePengguna = document.getElementById('signature-pengguna-input').value;
                
                // signature optional
                console.log("Signature IT:", signatureIt ? "Ada" : "Kosong");
                console.log("Signature Pengguna:", signaturePengguna ? "Ada" : "Kosong");
                
                if (!valid) {
                    e.preventDefault();
                    alert('Harap isi semua field yang wajib diisi!');
                    return;
                }
                
                // Show loading state
                submitBtn.textContent = 'Menyimpan...';
                submitBtn.disabled = true;
            });
            
            // Update nama pengguna preview
            const namaPenggunaInput = document.getElementById('nama_pengguna');
            const penggunaPreview = document.getElementById('pengguna-name-preview');

            // Nilai dapat sudah terisi dari keluhan yang dipilih sebelum halaman dibuka.
            penggunaPreview.textContent = namaPenggunaInput.value.trim() || 'Nama Pengguna';
            
            namaPenggunaInput.addEventListener('input', function() {
                penggunaPreview.textContent = this.value || 'Nama Pengguna';
            });
        }
        
        // Handle window resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                initializeSignaturePads();
            }, 250);
        });
        
        // Initialize everything
        function initializeAll() {
            initializeSignaturePads();
            setupFormValidation();
        }
        
        // Start initialization
        initializeAll();
    });
</script>

<?php if ($sign_link !== null): ?>
<script>
// Tombol salin link tanda tangan. Clipboard API hanya tersedia di konteks
// aman (https/localhost), jadi disediakan jalur cadangan lewat seleksi teks.
(function () {
    'use strict';

    var input = document.getElementById('signLinkInput');
    var button = document.getElementById('signLinkCopy');
    if (!input || !button) {
        return;
    }

    function flash(message) {
        var original = 'Salin';
        button.textContent = message;
        button.classList.add('copied');
        setTimeout(function () {
            button.textContent = original;
            button.classList.remove('copied');
        }, 1800);
    }

    function fallbackCopy() {
        input.removeAttribute('readonly');
        input.select();
        input.setSelectionRange(0, input.value.length);
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (err) {
            ok = false;
        }
        input.setAttribute('readonly', 'readonly');
        flash(ok ? 'Tersalin!' : 'Salin manual');
    }

    button.addEventListener('click', function () {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(function () {
                flash('Tersalin!');
            }, fallbackCopy);
        } else {
            fallbackCopy();
        }
    });

    input.addEventListener('focus', function () {
        input.select();
    });
})();
</script>
<?php endif; ?>
</body>
</html>
