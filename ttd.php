<?php
/**
 * Halaman tanda tangan pengguna — publik, tanpa login.
 *
 * Diakses lewat link bertoken yang dibagikan admin. Halaman ini hanya
 * menampilkan isi laporan sebagai teks (tidak ada field yang bisa diubah)
 * dan menerima satu hal saja dari pengunjung: tanda tangan pengguna.
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/sign_link.php';

header('X-Robots-Tag: noindex, nofollow', true);
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/** Pintasan escape supaya penulisan template tetap ringkas. */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$token  = isset($_GET['t']) ? $_GET['t'] : '';
$report = signTokenResolve($pdo, $token);

// Satu token CSRF per sesi pengunjung, dipakai untuk POST tanda tangan.
if (empty($_SESSION['ttd_csrf'])) {
    $_SESSION['ttd_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['ttd_csrf'];

$error       = null;
$just_signed = isset($_GET['ok']);

if ($report !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (!hash_equals($csrf, (string) $posted_csrf)) {
        $error = 'Sesi Anda sudah kedaluwarsa. Muat ulang halaman lalu tanda tangan kembali.';
    } elseif (signIsSigned($report) || $report['token_dipakai_pada'] !== null) {
        $error = 'Laporan ini sudah ditandatangani sebelumnya.';
    } else {
        $png = signDecodePng(isset($_POST['signature_pengguna']) ? $_POST['signature_pengguna'] : '', $decode_error);

        if ($png === null) {
            $error = $decode_error;
        } else {
            $filename   = 'signature_pengguna_' . (int) $report['id'] . '_' . time() . '.png';
            $target_dir = __DIR__ . '/uploads/signatures/';

            try {
                $pdo->beginTransaction();

                // Kedua UPDATE di bawah memakai syarat "belum terisi", jadi dua
                // pengiriman bersamaan tidak bisa saling menimpa.
                $stmt = $pdo->prepare(
                    "UPDATE sign_token
                        SET dipakai_pada = CURRENT_TIMESTAMP, ip_penandatangan = ?, user_agent = ?
                      WHERE id = ? AND dipakai_pada IS NULL"
                );
                $stmt->execute([
                    signClientIp(),
                    isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                    $report['token_id'],
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('Laporan ini sudah ditandatangani sebelumnya.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE maintenance
                        SET tanda_tangan_pengguna = ?
                      WHERE id = ?
                        AND (tanda_tangan_pengguna IS NULL OR tanda_tangan_pengguna = '')"
                );
                $stmt->execute([$filename, $report['id']]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('Laporan ini sudah ditandatangani sebelumnya.');
                }

                if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true)) {
                    throw new RuntimeException('Folder tanda tangan tidak bisa dibuat.');
                }

                if (file_put_contents($target_dir . $filename, $png) === false) {
                    throw new RuntimeException('Tanda tangan gagal disimpan di server.');
                }

                $pdo->commit();

                // Pola POST/Redirect/GET supaya refresh tidak mengirim ulang.
                header('Location: ' . SIGN_LINK_PAGE . '?t=' . rawurlencode($report['token']) . '&ok=1');
                exit;
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                @unlink($target_dir . $filename);

                $error = $ex instanceof RuntimeException
                    ? $ex->getMessage()
                    : 'Terjadi gangguan saat menyimpan tanda tangan. Silakan coba lagi.';
                error_log('ttd.php gagal menyimpan: ' . $ex->getMessage());
            }
        }
    }

    // Muat ulang agar status tanda tangan yang tampil selalu yang terbaru.
    $report = signTokenResolve($pdo, $token);
}

$is_signed = $report !== null && signIsSigned($report);

// Foto dokumentasi disimpan sebagai JSON array; format lama berupa satu nama file.
$photos = [];
if ($report !== null && !empty($report['lampiran_foto'])) {
    $decoded = json_decode($report['lampiran_foto'], true);
    foreach (is_array($decoded) ? $decoded : [$report['lampiran_foto']] as $photo) {
        $photo = trim((string) $photo);
        if ($photo !== '' && is_file(__DIR__ . '/uploads/photos/' . $photo)) {
            $photos[] = $photo;
        }
    }
}

$hasil_labels = [
    'Normal kembali'   => ['Selesai', 'ok'],
    'Perlu monitoring' => ['Perlu Monitoring', 'watch'],
    'Belum selesai'    => ['Belum Selesai', 'pending'],
];
$hasil_key   = $report !== null ? (string) $report['hasil'] : '';
$hasil_label = isset($hasil_labels[$hasil_key]) ? $hasil_labels[$hasil_key][0] : $hasil_key;
$hasil_tone  = isset($hasil_labels[$hasil_key]) ? $hasil_labels[$hasil_key][1] : 'ok';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#5b5fd6">
<link rel="icon" type="image/x-icon" href="uploads/icon/favicon.ico">
<title>Tanda Tangan Laporan — Network Psitek Unisba</title>
<style>
:root {
    --brand-start: #667eea;
    --brand-end: #764ba2;
    --ink: #1c1f2b;
    --ink-soft: #545a6d;
    --ink-faint: #878da0;
    --line: #e3e6ef;
    --surface: #ffffff;
    --ground: #eef0f7;
    --ok: #1e7a4c;
    --ok-bg: #e4f5ec;
    --watch: #8a6100;
    --watch-bg: #fdf3dc;
    --pending: #a32b32;
    --pending-bg: #fdeaea;
    --radius: 18px;
    --shadow-card: 0 18px 48px -22px rgba(28, 31, 43, .38), 0 2px 8px -2px rgba(28, 31, 43, .08);
    --ease: cubic-bezier(.16, 1, .3, 1);
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--ground);
    color: var(--ink);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 16px;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

/* Pita gradien di belakang kartu memberi kedalaman tanpa gambar apa pun. */
.masthead {
    background: linear-gradient(135deg, var(--brand-start), var(--brand-end));
    padding: 28px 20px 96px;
    text-align: center;
    color: #fff;
}

.masthead .mark {
    width: 54px;
    height: 54px;
    margin: 0 auto 14px;
    background: #fff;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px;
    box-shadow: 0 8px 20px -6px rgba(0, 0, 0, .35);
}

.masthead .mark img { width: 100%; height: 100%; object-fit: contain; display: block; }
.masthead h1 { margin: 0; font-size: 1.15rem; font-weight: 700; letter-spacing: -.01em; }
.masthead p { margin: 4px 0 0; font-size: .85rem; color: rgba(255, 255, 255, .82); }

.shell { max-width: 720px; margin: -72px auto 56px; padding: 0 16px; }

.card {
    background: var(--surface);
    border-radius: var(--radius);
    box-shadow: var(--shadow-card);
    overflow: hidden;
}

.card-top {
    padding: 26px 26px 22px;
    border-bottom: 1px solid var(--line);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.eyebrow {
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--ink-faint);
    margin: 0 0 2px;
}

/* Kontras skala: nomor laporan jadi jangkar visual halaman. */
.card-top h2 { margin: 0; font-size: 1.75rem; font-weight: 700; letter-spacing: -.02em; }

.badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 700;
    white-space: nowrap;
}

.badge.ok      { background: var(--ok-bg);      color: var(--ok); }
.badge.watch   { background: var(--watch-bg);   color: var(--watch); }
.badge.pending { background: var(--pending-bg); color: var(--pending); }

.section { padding: 24px 26px; border-bottom: 1px solid var(--line); }

.section > h3 {
    margin: 0 0 16px;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--ink-faint);
}

.facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 22px; }
.facts .wide { grid-column: 1 / -1; }
.facts dt { font-size: .74rem; font-weight: 600; color: var(--ink-faint); margin-bottom: 3px; }
.facts dd { margin: 0; font-weight: 600; color: var(--ink); overflow-wrap: anywhere; }

/* Garis aksen di kiri membedakan teks bebas dari data terstruktur. */
.prose {
    margin: 0 0 18px;
    padding-left: 16px;
    border-left: 3px solid var(--brand-start);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    color: var(--ink-soft);
}

.prose:last-child { margin-bottom: 0; }
.prose strong { display: block; color: var(--ink); font-size: .74rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 4px; }

.gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 10px; }

.gallery a {
    display: block;
    aspect-ratio: 1;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid var(--line);
    transition: transform .25s var(--ease), box-shadow .25s var(--ease);
}

.gallery a:hover, .gallery a:focus-visible {
    transform: translateY(-3px);
    box-shadow: 0 10px 22px -12px rgba(28, 31, 43, .5);
}

.gallery img { width: 100%; height: 100%; object-fit: cover; display: block; }

.sig-proof { text-align: center; }
.sig-proof img { max-width: 240px; width: 100%; height: auto; display: block; margin: 0 auto 8px; }
.sig-proof .who { font-weight: 700; }
.sig-proof .when { font-size: .8rem; color: var(--ink-faint); }

/* Panel bertinta memisahkan satu-satunya bagian yang interaktif. */
.act {
    padding: 26px;
    background: linear-gradient(180deg, #f7f8fd, #eef0fa);
}

.act h3 { margin: 0 0 4px; font-size: 1.15rem; font-weight: 700; letter-spacing: -.01em; }
.act .who { margin: 0 0 4px; font-weight: 600; color: var(--ink); }
.act .pledge { margin: 0 0 18px; font-size: .87rem; color: var(--ink-soft); }

.pad {
    background: var(--surface);
    border: 2px dashed #c2c8de;
    border-radius: 14px;
    padding: 10px;
    transition: border-color .2s var(--ease);
}

.pad.inked { border-style: solid; border-color: var(--brand-start); }
.pad canvas { width: 100%; height: 220px; display: block; border-radius: 8px; touch-action: none; cursor: crosshair; }

.pad-hint {
    text-align: center;
    font-size: .8rem;
    color: var(--ink-faint);
    margin: 10px 0 0;
}

.buttons { display: flex; gap: 10px; margin-top: 16px; }

.btn {
    flex: 1;
    padding: 15px 18px;
    border: none;
    border-radius: 12px;
    font-family: inherit;
    font-size: .97rem;
    font-weight: 700;
    cursor: pointer;
    transition: transform .18s var(--ease), box-shadow .18s var(--ease), background-color .18s var(--ease);
}

.btn:focus-visible { outline: 3px solid var(--brand-start); outline-offset: 3px; }

.btn-ghost { flex: 0 0 auto; min-width: 110px; background: #fff; color: var(--ink-soft); border: 1px solid var(--line); }
.btn-ghost:hover { background: #f4f5fa; color: var(--ink); }

.btn-primary {
    background: linear-gradient(120deg, var(--brand-start), var(--brand-end));
    color: #fff;
    box-shadow: 0 12px 24px -12px rgba(102, 126, 234, .85);
}

.btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 18px 30px -14px rgba(102, 126, 234, .9); }
.btn-primary:active:not(:disabled) { transform: translateY(0); }
.btn-primary:disabled { opacity: .55; cursor: not-allowed; box-shadow: none; }

.note { padding: 16px 18px; border-radius: 12px; font-size: .9rem; margin: 0 0 18px; }
.note.bad  { background: var(--pending-bg); color: var(--pending); }
.note.good { background: var(--ok-bg);      color: var(--ok); }

.done { padding: 30px 26px; text-align: center; background: linear-gradient(180deg, #f2fbf6, #e6f6ed); }
.done .tick { font-size: 2.1rem; line-height: 1; }
.done h3 { margin: 10px 0 4px; font-size: 1.2rem; color: var(--ok); }
.done p { margin: 0 0 18px; font-size: .9rem; color: var(--ink-soft); }

.done .frame {
    background: #fff;
    border: 1px solid #cfe8da;
    border-radius: 14px;
    padding: 18px;
    max-width: 320px;
    margin: 0 auto;
}

.foot { text-align: center; font-size: .78rem; color: var(--ink-faint); margin-top: 22px; }

.blank { max-width: 460px; margin: -72px auto 56px; padding: 0 16px; }
.blank .card { padding: 40px 28px; text-align: center; }
.blank .tick { font-size: 2.4rem; }
.blank h2 { margin: 12px 0 8px; font-size: 1.25rem; }
.blank p { margin: 0; color: var(--ink-soft); font-size: .92rem; }

@media (max-width: 560px) {
    .shell { padding: 0 12px; }
    .card-top, .section, .act { padding-left: 18px; padding-right: 18px; }
    .card-top h2 { font-size: 1.5rem; }
    .facts { grid-template-columns: 1fr; gap: 14px; }
    .buttons { flex-direction: column-reverse; }
    .btn-ghost { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    * { transition: none !important; animation: none !important; }
    .btn-primary:hover:not(:disabled), .gallery a:hover { transform: none; }
}
</style>
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js"></script>
</head>
<body>

<header class="masthead">
    <div class="mark"><img src="uploads/images/logo-unisba.png" alt=""></div>
    <h1>Network Psitek</h1>
    <p>Universitas Islam Bandung</p>
</header>

<?php if ($report === null): ?>

    <div class="blank">
        <div class="card">
            <div class="tick">🔗</div>
            <h2>Link tidak berlaku</h2>
            <p>
                Link tanda tangan ini tidak dikenali atau sudah diganti oleh petugas.
                Silakan hubungi petugas IT yang menangani laporan Anda untuk meminta link baru.
            </p>
        </div>
    </div>

<?php else: ?>

    <div class="shell">
        <main class="card">

            <div class="card-top">
                <div>
                    <p class="eyebrow">Berita Acara Layanan</p>
                    <h2>Laporan #<?= e($report['id']) ?></h2>
                </div>
                <span class="badge <?= e($hasil_tone) ?>"><?= e($hasil_label) ?></span>
            </div>

            <section class="section">
                <h3>Pelaksanaan</h3>
                <dl class="facts">
                    <div>
                        <dt>Tanggal</dt>
                        <dd><?= e(date('d F Y', strtotime($report['tanggal']))) ?></dd>
                    </div>
                    <div>
                        <dt>Waktu</dt>
                        <dd><?= e(substr($report['waktu'], 0, 5)) ?> WIB</dd>
                    </div>
                    <div class="wide">
                        <dt>Petugas IT</dt>
                        <dd><?= e($report['staff_names'] ?: '-') ?></dd>
                    </div>
                </dl>
            </section>

            <section class="section">
                <h3>Pengguna</h3>
                <dl class="facts">
                    <div>
                        <dt>Nama</dt>
                        <dd><?= e($report['nama_pengguna']) ?></dd>
                    </div>
                    <div>
                        <dt>Fakultas / Unit</dt>
                        <dd><?= e($report['unit_lembaga']) ?></dd>
                    </div>
                </dl>
            </section>

            <section class="section">
                <h3>Keluhan dan Tindakan</h3>
                <p class="prose"><strong>Keluhan</strong><?= e($report['keluhan']) ?></p>
                <p class="prose"><strong>Tindakan</strong><?= e($report['tindakan']) ?></p>
                <?php if (trim((string) $report['catatan']) !== ''): ?>
                    <p class="prose"><strong>Catatan</strong><?= e($report['catatan']) ?></p>
                <?php endif; ?>
            </section>

            <?php if ($photos): ?>
                <section class="section">
                    <h3>Dokumentasi</h3>
                    <div class="gallery">
                        <?php foreach ($photos as $photo): ?>
                            <a href="uploads/photos/<?= e(rawurlencode($photo)) ?>" target="_blank" rel="noopener noreferrer">
                                <img src="uploads/photos/<?= e(rawurlencode($photo)) ?>" alt="Dokumentasi laporan" loading="lazy" width="200" height="200">
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php
            $sig_it      = trim((string) $report['tanda_tangan_it']);
            $sig_it_file = $sig_it !== '' && is_file(__DIR__ . '/uploads/signatures/' . $sig_it);
            ?>
            <?php if ($sig_it_file): ?>
                <section class="section">
                    <h3>Tanda Tangan Petugas IT</h3>
                    <div class="sig-proof">
                        <img src="uploads/signatures/<?= e(rawurlencode($sig_it)) ?>" alt="Tanda tangan petugas IT" loading="lazy">
                        <div class="who"><?= e($report['staff_pemilik_tanda_tangan'] ?: $report['staff_names']) ?></div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($is_signed): ?>

                <div class="done">
                    <div class="tick">✅</div>
                    <h3><?= $just_signed ? 'Tanda tangan tersimpan' : 'Sudah ditandatangani' ?></h3>
                    <p>
                        <?= $just_signed
                            ? 'Terima kasih. Laporan ini sudah lengkap dan tidak perlu ditandatangani lagi.'
                            : 'Laporan ini sudah ditandatangani, jadi tidak bisa ditandatangani ulang lewat link ini.' ?>
                    </p>
                    <?php
                    $sig_user      = trim((string) $report['tanda_tangan_pengguna']);
                    $sig_user_file = $sig_user !== '' && is_file(__DIR__ . '/uploads/signatures/' . $sig_user);
                    ?>
                    <div class="frame sig-proof">
                        <?php if ($sig_user_file): ?>
                            <img src="uploads/signatures/<?= e(rawurlencode($sig_user)) ?>" alt="Tanda tangan pengguna">
                        <?php endif; ?>
                        <div class="who"><?= e($report['nama_pengguna']) ?></div>
                        <?php if ($report['token_dipakai_pada'] !== null): ?>
                            <div class="when"><?= e(date('d F Y, H:i', strtotime($report['token_dipakai_pada']))) ?> WIB</div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>

                <div class="act">
                    <?php if ($error !== null): ?>
                        <p class="note bad"><?= e($error) ?></p>
                    <?php endif; ?>

                    <h3>Tanda Tangan Pengguna</h3>
                    <p class="who"><?= e($report['nama_pengguna']) ?></p>
                    <p class="pledge">
                        Dengan menandatangani, Anda menyatakan bahwa tindakan yang tercantum di atas
                        benar telah dilakukan oleh petugas IT.
                    </p>

                    <form method="POST" id="signForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="signature_pengguna" id="signatureField">

                        <div class="pad" id="pad">
                            <canvas id="canvas" aria-label="Area tanda tangan"></canvas>
                        </div>
                        <p class="pad-hint" id="hint">Gambar tanda tangan Anda di dalam kotak</p>

                        <div class="buttons">
                            <button type="button" class="btn btn-ghost" id="resetBtn">Ulangi</button>
                            <button type="submit" class="btn btn-primary" id="submitBtn">Kirim Tanda Tangan</button>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </main>

        <p class="foot">Link ini bersifat pribadi. Mohon tidak diteruskan ke orang lain.</p>
    </div>

<?php endif; ?>

<?php if ($report !== null && !$is_signed): ?>
<script src="js/signature_pad.umd.min.js"></script>
<script>
(function () {
    'use strict';

    var canvas    = document.getElementById('canvas');
    var pad       = document.getElementById('pad');
    var hint      = document.getElementById('hint');
    var field     = document.getElementById('signatureField');
    var form      = document.getElementById('signForm');
    var resetBtn  = document.getElementById('resetBtn');
    var submitBtn = document.getElementById('submitBtn');

    if (typeof SignaturePad !== 'function') {
        hint.textContent = 'Komponen tanda tangan gagal dimuat. Coba muat ulang halaman.';
        submitBtn.disabled = true;
        return;
    }

    var signaturePad = new SignaturePad(canvas, {
        backgroundColor: 'rgb(255, 255, 255)',
        penColor: 'rgb(20, 22, 33)',
        minWidth: 0.7,
        maxWidth: 2.6,
        velocityFilterWeight: 0.7
    });

    // Canvas harus digambar ulang setiap ukurannya berubah, kalau tidak
    // koordinat sentuhan meleset dari posisi garis.
    function fitCanvas() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var data  = signaturePad.isEmpty() ? null : signaturePad.toData();

        canvas.width  = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);

        signaturePad.clear();
        if (data) {
            signaturePad.fromData(data);
        }
    }

    function refreshState() {
        var inked = !signaturePad.isEmpty();
        pad.classList.toggle('inked', inked);
        hint.textContent = inked
            ? 'Sudah terisi. Tekan "Ulangi" bila ingin menggambar ulang.'
            : 'Gambar tanda tangan Anda di dalam kotak';
    }

    fitCanvas();
    signaturePad.addEventListener('endStroke', refreshState);

    resetBtn.addEventListener('click', function () {
        signaturePad.clear();
        field.value = '';
        refreshState();
    });

    // Ukuran berubah saat rotasi layar atau keyboard ponsel muncul.
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(fitCanvas, 180);
    });

    // Isi field langsung saat submit, jadi tidak ada langkah "simpan" terpisah
    // yang bisa terlupakan oleh pengguna.
    form.addEventListener('submit', function (event) {
        if (signaturePad.isEmpty()) {
            event.preventDefault();
            hint.textContent = 'Tanda tangan masih kosong. Silakan gambar terlebih dahulu.';
            pad.scrollIntoView({ block: 'center', behavior: 'smooth' });
            return;
        }

        field.value = signaturePad.toDataURL('image/png');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Mengirim…';
    });
})();
</script>
<?php endif; ?>

</body>
</html>
