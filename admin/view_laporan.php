<?php
require_once __DIR__ . '/bootstrap.php';
require_once '../config/sign_link.php';

// Check if user can manage reports
if (!isset($_SESSION['user_id']) || !canManageReports($_SESSION['jabatan'])) {
    header('Location: laporan.php?error=unauthorized');
    exit;
}

// Get report data - KEMBALI KE QUERY SEMULA
$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("
    SELECT m.*, 
           GROUP_CONCAT(s.nama SEPARATOR ' - ') as staff_names
    FROM maintenance m
    LEFT JOIN maintenance_staff ms ON m.id = ms.maintenance_id
    LEFT JOIN staff_it s ON ms.staff_id = s.id
    WHERE m.id = ?
    GROUP BY m.id
");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    header('Location: laporan.php?error=notfound');
    exit;
}

// // Get report data - KEMBALI KE QUERY SEMULA
// $id = $_GET['id'] ?? 0;
// $stmt = $pdo->prepare("
//     SELECT m.*, 
//            GROUP_CONCAT(s.nama SEPARATOR ' - ') as staff_names
//     FROM maintenance m
//     LEFT JOIN maintenance_staff ms ON m.id = ms.maintenance_id
//     LEFT JOIN staff_it s ON ms.staff_id = s.id
//     WHERE m.id = ?
//     GROUP BY m.id
// ");
// $stmt->execute([$id]);
// $report = $stmt->fetch();

if (!$report) {
    header('Location: laporan.php?error=notfound');
    exit;
}

// ==================================
// LINK TANDA TANGAN PENGGUNA
// ==================================

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$sign_notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sign_action'] ?? '') === 'regenerate') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        $sign_notice = 'Sesi kedaluwarsa, link tidak jadi diganti. Muat ulang halaman.';
    } elseif (signIsSigned($report)) {
        $sign_notice = 'Laporan sudah ditandatangani, link tidak perlu diganti.';
    } else {
        try {
            signTokenRegenerate($pdo, $report['id'], $_SESSION['user_id']);
            // Redirect supaya refresh tidak mengganti token lagi.
            header('Location: view_laporan.php?id=' . (int) $report['id'] . '&link=baru');
            exit;
        } catch (Exception $e) {
            error_log('Gagal membuat ulang token tanda tangan: ' . $e->getMessage());
            $sign_notice = 'Link gagal diganti. Coba lagi.';
        }
    }
}

$sign_token_row = null;
$sign_url       = null;
$sign_signed    = signIsSigned($report);

if (!$sign_signed) {
    try {
        $sign_token_row = signTokenEnsure($pdo, $report['id'], $_SESSION['user_id']);
        $sign_url       = signLinkUrl($sign_token_row['token']);
    } catch (Exception $e) {
        error_log('Gagal menyiapkan token tanda tangan: ' . $e->getMessage());
        $sign_notice = 'Link tanda tangan gagal disiapkan.';
    }
} else {
    $sign_token_row = signTokenFind($pdo, $report['id']);
}

// Nomor kontak pelapor untuk tombol kirim WhatsApp.
$sign_wa = null;
if (!$sign_signed && !empty($report['keluhan_id'])) {
    $stmt_kontak = $pdo->prepare("SELECT kontak FROM keluhan_wifi WHERE id = ?");
    $stmt_kontak->execute([$report['keluhan_id']]);
    $kontak_digits = preg_replace('/\D/', '', (string) $stmt_kontak->fetchColumn());
    if (strlen($kontak_digits) >= 9) {
        $sign_wa = $kontak_digits;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../uploads/icon/favicon.ico">
    <title>Detail Laporan - Psitek</title>
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/theme.css">
    <script src="../js/theme.js"></script>
</head>
<body>
    <div class="admin-container">
        <aside class="sidebar"> 
            <div class="sidebar-header">
                <div class="header-logo-container horizontal">
                    <img src="../uploads/images/logo-unisba.png" alt="PSITek Logo" class="sidebar-logo">
                    <h2>Network Psitek</h2>
                </div>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="dashboard.php">Dashboard</a>
                    </li>

                    <li>
                        <a href="keluhan_list.php">Daftar Keluhan</a>
                    </li>

                    <?php if (canManageReports($_SESSION['jabatan'])): ?>
                        <li class="active">
                            <a href="laporan.php">Daftar Laporan</a>
                        </li>
                    <?php endif; ?>
                    
                    <?php if (canManageStaff($_SESSION['jabatan'])): ?>
                        <li>
                            <a href="kelola_akun.php">Kelola Akun</a>
                        </li>
                    <?php endif; ?>
                    
                    <li>
                        <a href="../logout.php">Logout</a>
                    </li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <header class="content-header">
                <h1>Detail Laporan</h1>
                <div class="user-info">
                    <span class="user-name"><?php echo $_SESSION['nama']; ?> (<?php echo $_SESSION['jabatan']; ?>)</span>
                </div>
            </header>

            <a href="laporan.php" class="back-button">
                <span>←</span> Kembali ke Daftar Laporan
            </a>

            <div class="report-detail">
                <!-- Informasi Umum -->
                <div class="detail-section">
                    <h3>Informasi Umum</h3>
                    <div class="detail-grid">
                        <div class="detail-label">Nomor Laporan:</div>
                        <div class="detail-value">#<?php echo $report['id']; ?></div>
                        
                        <div class="detail-label">Tanggal:</div>
                        <div class="detail-value"><?php echo date('d/m/Y', strtotime($report['tanggal'])); ?></div>
                        
                        <div class="detail-label">Waktu:</div>
                        <div class="detail-value"><?php echo $report['waktu']; ?></div>
                        
                        <div class="detail-label">Staff IT yang Bertugas:</div>
                        <div class="detail-value"><?php echo $report['staff_names']; ?></div>
                    </div>
                </div>

                <!-- Informasi Pengguna -->
                <div class="detail-section">
                    <h3>Informasi Pengguna</h3>
                    <div class="detail-grid">
                        <div class="detail-label">Nama Pengguna:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($report['nama_pengguna']); ?></div>
                        
                        <div class="detail-label">Fakultas/Unit/Lembaga:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($report['unit_lembaga']); ?></div>
                    </div>
                </div>

                <!-- Keluhan -->
                <div class="detail-section">
                    <h3>Keluhan</h3>
                    <div class="text-content"><?php echo htmlspecialchars($report['keluhan']); ?></div>
                </div>

                <!-- Tindakan -->
                <div class="detail-section">
                    <h3>Tindakan</h3>
                    <div class="text-content"><?php echo htmlspecialchars($report['tindakan']); ?></div>
                </div>

                <!-- Hasil dan Catatan -->
                <!-- Hasil dan Catatan -->
<div class="detail-section">
    <h3>Hasil dan Catatan</h3>
    <div class="detail-grid">
        <div class="detail-label">Hasil:</div>
        <div class="detail-value">
            <?php 
            $status_class = '';
            $status_text = '';
            
            switch($report['hasil']) {
                case 'Selesai':
                    $status_class = 'status-selesai';
                    $status_text = 'SELESAI';
                    break;
                case 'Perlu monitoring':
                    $status_class = 'status-perlu-monitoring';
                    $status_text = 'MONITOR';
                    break;
                case 'Belum selesai':
                    $status_class = 'status-belum-selesai';
                    $status_text = 'PENDING';
                    break;
                default:
                    $status_class = 'status-normal-kembali';
                    $status_text = strtoupper(substr($report['hasil'], 0, 8));
            }
            ?>
            <span class="status-badge <?php echo $status_class; ?>" title="<?php echo htmlspecialchars($report['hasil']); ?>">
                <?php echo $status_text; ?>
            </span>
        </div>
        
        <?php if (!empty($report['catatan'])): ?>
            <div class="detail-label">Catatan:</div>
            <div class="detail-value">
                <div class="text-content"><?php echo htmlspecialchars($report['catatan']); ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

               <!-- Lampiran Foto -->
                    <?php
                    // Support both JSON array (dari perubahan upload multi-file) atau string tunggal (legacy)
                    $photos = [];
                    if (!empty($report['lampiran_foto'])) {
                        $decoded = json_decode($report['lampiran_foto'], true);
                        if (is_array($decoded)) {
                            $photos = $decoded;
                        } else {
                            // Asumsikan string nama file tunggal
                            $photos = [$report['lampiran_foto']];
                        }
                    }

                    // Pisahkan yang ada di server dan yang hilang
                    $existing = [];
                    $missing = [];
                    foreach ($photos as $p) {
                        $p = trim($p);
                        if ($p === '') continue;
                        $path = "../uploads/photos/" . $p;
                        if (file_exists($path)) {
                            $existing[] = $p;
                        } else {
                            $missing[] = $p;
                        }
                    }
                    ?>

                    <?php if (!empty($existing)): ?>
                    <div class="detail-section">
                        <h3>Lampiran Foto</h3>
                        <div class="photo-gallery">
                            <?php foreach ($existing as $photo): ?>
                                <div class="photo-item">
                                    <a href="../uploads/photos/<?php echo $photo; ?>" target="_blank">
                                        <img src="../uploads/photos/<?php echo $photo; ?>" 
                                            alt="Dokumentasi Maintenance" 
                                            class="photo-preview-small">
                                    </a>
                                    <div class="photo-actions">
                                        <a href="../uploads/photos/<?php echo $photo; ?>" target="_blank" class="btn btn-primary btn-sm">Lihat</a>
                                        <a href="../uploads/photos/<?php echo $photo; ?>" download class="btn btn-success btn-sm">Unduh</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top:8px; display:flex; gap:8px; align-items:center; justify-content:center;">
                            <a href="download_photo.php?id=<?php echo $report['id']; ?>&all=1" class="btn btn-outline" style="padding:8px 12px;">Download Semua (ZIP)</a>
                        </div>

                        <?php if (!empty($missing)): ?>
                            <div style="margin-top:12px; color:#856404; background:#fff3cd; padding:10px; border-radius:4px; text-align:center;">
                                ⚠️ Beberapa file tidak ditemukan di server: <?php echo htmlspecialchars(implode(', ', $missing)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php elseif (!empty($photos)): ?>
                    <div class="detail-section">
                        <h3>Lampiran Foto</h3>
                        <div style="color: #dc3545; padding: 10px; background: #f8d7da; border-radius: 4px;">
                            ⚠️ Tidak ada file foto yang ditemukan di server untuk laporan ini.
                        </div>
                    </div>
                    <?php endif; ?>

                <!-- Tanda Tangan -->
                <div class="detail-section">
                    <h3>Tanda Tangan</h3>
                    <div class="signature-container">
                        <div class="signature-box">
    <h4>Tanda Tangan IT</h4>
    <?php 
    // Gunakan staff_pemilik_tanda_tangan jika ada, jika tidak gunakan nama session
    $staff_tanda_tangan = !empty($report['staff_pemilik_tanda_tangan']) 
        ? $report['staff_pemilik_tanda_tangan'] 
        : ($_SESSION['nama'] ?? $report['staff_names']);
    ?>
    <?php if (!empty($report['tanda_tangan_it']) && file_exists("../uploads/signatures/" . $report['tanda_tangan_it'])): ?>
        <img src="../uploads/signatures/<?php echo $report['tanda_tangan_it']; ?>" 
             alt="Tanda Tangan IT" 
             class="signature-image">
        <div class="detail-value"><?php echo htmlspecialchars($staff_tanda_tangan); ?></div>
        <!-- <div class="signature-info">(Staff IT yang Login)</div> -->
    <?php else: ?>
        <div style="color: #999; font-style: italic; padding: 20px;">Tidak ada tanda tangan</div>
        <div class="detail-value"><?php echo htmlspecialchars($staff_tanda_tangan); ?></div>
        <!-- <div class="signature-info">(Staff IT yang Login)</div> -->
    <?php endif; ?>
</div>
                        
                        <div class="signature-box">
                            <h4>Tanda Tangan Pengguna</h4>
                            <?php if (!empty($report['tanda_tangan_pengguna']) && file_exists("../uploads/signatures/" . $report['tanda_tangan_pengguna'])): ?>
                                <img src="../uploads/signatures/<?php echo $report['tanda_tangan_pengguna']; ?>" 
                                     alt="Tanda Tangan Pengguna" 
                                     class="signature-image">
                                <div class="detail-value"><?php echo htmlspecialchars($report['nama_pengguna']); ?></div>
                            <?php else: ?>
                                <div style="color: #999; font-style: italic; padding: 20px;">Tidak ada tanda tangan</div>
                                <div class="detail-value"><?php echo htmlspecialchars($report['nama_pengguna']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Link Tanda Tangan Pengguna -->
                <div class="detail-section">
                    <h3>Link Tanda Tangan Pengguna</h3>

                    <?php if ($sign_notice !== null): ?>
                        <p class="sign-share-note" style="color:#a32b32;"><?php echo htmlspecialchars($sign_notice, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>

                    <?php if ($sign_signed): ?>
                        <div class="sign-share">
                            <span class="sign-share-state">
                                Pengguna sudah menandatangani
                                <?php if ($sign_token_row !== null && $sign_token_row['dipakai_pada'] !== null): ?>
                                    pada <?php echo date('d/m/Y H:i', strtotime($sign_token_row['dipakai_pada'])); ?> WIB
                                <?php endif; ?>
                            </span>
                            <p class="sign-share-note">
                                Link tanda tangan sudah terpakai dan tidak bisa dipakai lagi.
                            </p>
                        </div>
                    <?php elseif ($sign_url !== null): ?>
                        <?php $wa_text = rawurlencode("Assalamu'alaikum. Berikut laporan layanan jaringan Psitek Unisba. Mohon berkenan menandatangani sebagai bukti pekerjaan telah selesai:\n" . $sign_url); ?>
                        <div class="sign-share">
                            <h4>Belum ditandatangani</h4>
                            <p class="sign-share-desc">
                                Kirim link di bawah ke <strong><?php echo htmlspecialchars($report['nama_pengguna'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                                Penerima tidak perlu login, hanya bisa melihat isi laporan, dan satu-satunya
                                yang bisa diisi adalah tanda tangan pengguna.
                            </p>

                            <div class="sign-share-row">
                                <input type="text" class="sign-share-url" id="signShareUrl" readonly
                                       value="<?php echo htmlspecialchars($sign_url, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="button" class="btn btn-ttd btn-sm" id="signShareCopy">
                                    <span>Salin Link</span>
                                </button>
                                <?php if ($sign_wa !== null): ?>
                                    <a class="btn btn-success btn-sm" target="_blank" rel="noopener"
                                       href="https://wa.me/<?php echo htmlspecialchars($sign_wa, ENT_QUOTES, 'UTF-8'); ?>?text=<?php echo $wa_text; ?>">
                                        <span>Kirim WhatsApp</span>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <p class="sign-share-note">
                                Link berlaku sampai ditandatangani, lalu otomatis hangus.
                            </p>

                            <form method="POST" onsubmit="return confirm('Buat link baru? Link lama langsung tidak berlaku.');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="sign_action" value="regenerate">
                                <button type="submit" class="sign-share-regen">Buat link baru (batalkan link lama)</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Informasi Sistem -->
                <div class="detail-section">
                    <h3>Informasi Sistem</h3>
                    <div class="detail-grid">
                        <div class="detail-label">Dibuat Pada:</div>
                        <div class="detail-value"><?php echo date('d/m/Y H:i', strtotime($report['dibuat_pada'])); ?></div>
                        
                        <div class="detail-label">Diupdate Pada:</div>
                        <div class="detail-value"><?php echo date('d/m/Y H:i', strtotime($report['diupdate_pada'])); ?></div>
                    </div>
                </div>
            </div>

            <div class="action-buttons" style="text-align: center; margin-top: 30px;">
                <a href="edit_laporan.php?id=<?php echo $report['id']; ?>" class="btn btn-warning">
                    Edit Laporan
                </a>
                <a href="export_pdf.php?id=<?php echo $report['id']; ?>" class="btn btn-success">
                    Export PDF
                </a>
                <a href="laporan.php" class="btn btn-secondary">
                    Kembali
                </a>
            </div>
        </main>
    </div>

    <?php if (!$sign_signed && $sign_url !== null): ?>
    <script>
    // Salin link tanda tangan. Clipboard API butuh konteks aman, jadi ada
    // jalur cadangan lewat seleksi teks untuk akses via HTTP biasa.
    (function () {
        'use strict';

        var input = document.getElementById('signShareUrl');
        var button = document.getElementById('signShareCopy');
        if (!input || !button) {
            return;
        }

        function flash(message, tone) {
            var label = button.querySelector('span');
            var original = 'Salin Link';
            label.textContent = message;
            button.classList.add(tone);
            setTimeout(function () {
                label.textContent = original;
                button.classList.remove(tone);
            }, 2000);
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
            flash(ok ? 'Tersalin!' : 'Salin manual', ok ? 'is-copied' : 'is-failed');
        }

        button.addEventListener('click', function () {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(function () {
                    flash('Tersalin!', 'is-copied');
                }, fallbackCopy);
            } else {
                fallbackCopy();
            }
        });

        input.addEventListener('focus', function () { input.select(); });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
