<?php
require_once __DIR__ . '/bootstrap.php';

/* ambil id keluhan */
$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: keluhan_list.php');
    exit;
}

/* ambil data keluhan */
$stmt = $pdo->prepare("SELECT * FROM keluhan_wifi WHERE id = ?");
$stmt->execute([$id]);
$k = $stmt->fetch();

if (!$k) {
    echo "Keluhan tidak ditemukan";
    exit;
}

/* ambil riwayat pengerjaan */
$stmtRiwayat = $pdo->prepare("
    SELECT *
    FROM maintenance
    WHERE keluhan_id = ?
    ORDER BY tanggal DESC, waktu DESC
");
$stmtRiwayat->execute([$k['id']]);
$riwayat = $stmtRiwayat->fetchAll();

// Fungsi untuk membersihkan dan memformat nomor WhatsApp
function formatWhatsApp($phone) {
    if (empty($phone)) return null;
    
    // Hapus semua karakter non-digit
    $clean = preg_replace('/\D/', '', $phone);
    
    // Jika dimulai dengan 0, ganti dengan 62
    if (substr($clean, 0, 1) === '0') {
        $clean = '62' . substr($clean, 1);
    }
    
    // Jika dimulai dengan 8 (tanpa kode negara), tambahkan 62
    if (substr($clean, 0, 1) === '8' && substr($clean, 0, 2) !== '62') {
        $clean = '62' . $clean;
    }
    
    // Pastikan panjang minimal 11 digit (62 + 9 digit)
    if (strlen($clean) >= 11) {
        return $clean;
    }
    
    return null;
}

// Format nomor untuk tampilan
$display_phone = $k['kontak'];
$whatsapp_number = formatWhatsApp($k['kontak']);
$whatsapp_url = $whatsapp_number ? "https://wa.me/{$whatsapp_number}" : "#";
$nomor_laporan = (int) ($k['maintenance_id'] ?? 0);

// Status badge untuk keluhan
$status_class = 'status-diproses';
$status_text = strtoupper($k['status']);
if ($k['status'] == 'selesai') {
    $status_class = 'status-selesai';
} elseif ($k['status'] == 'baru') {
    $status_class = 'status-baru';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../uploads/icon/favicon.ico">
    <title>Detail Keluhan - Psitek</title>
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/theme.css">
    <script src="../js/theme.js"></script>
    <style>
        /* Custom styles untuk keluhan detail */
        .btn-whatsapp {
            background: linear-gradient(135deg, #25D366, #128C7E);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }
        
        .btn-whatsapp:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(37, 211, 102, 0.4);
            color: white;
        }
        
        .btn-whatsapp:disabled {
            background: linear-gradient(135deg, #cccccc, #999999);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-whatsapp:disabled:hover {
            transform: none;
            box-shadow: none;
        }
        
        .history-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary);
        }
        
        .phone-display {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        
        /* Responsive actions */
        .detail-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .detail-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .detail-actions .btn,
            .detail-actions .btn-whatsapp {
                width: 100%;
                justify-content: center;
                margin: 5px 0;
            }
            
            .history-card {
                padding: 15px;
            }
            
            .detail-grid {
                gap: 10px !important;
            }
        }
        
        /* Custom status badges untuk keluhan */
        .status-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            display: inline-block;
            text-align: center;
            line-height: 1;
            min-width: 80px;
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .status-baru {
            background: rgba(255, 193, 7, 0.15);
            color: #856404;
            border: 1px solid #ffc107;
        }
        
        .status-diproses {
            background: rgba(23, 162, 184, 0.15);
            color: #0c5460;
            border: 1px solid #17a2b8;
        }
        
        .status-selesai {
            background: rgba(40, 167, 69, 0.15);
            color: #155724;
            border: 1px solid #28a745;
        }
    </style>
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

                    <li class="active">
                        <a href="keluhan_list.php">Daftar Keluhan</a>
                    </li>

                    <?php if (canManageReports($_SESSION['jabatan'])): ?>
                        <li>
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
                <h1>Detail Keluhan</h1>
                <div class="user-info">
                    <span class="user-name"><?php echo $_SESSION['nama']; ?> (<?php echo $_SESSION['jabatan']; ?>)</span>
                </div>
            </header>

            <a href="keluhan_list.php" class="back-button">
                <span>←</span> Kembali ke Daftar Keluhan
            </a>

            <!-- DETAIL KELUHAN -->
            <div class="report-detail">
                <!-- Informasi Pengguna -->
                <div class="detail-section">
                    <h3>Informasi Pelapor</h3>
                    <div class="detail-grid">
                        <div class="detail-label">Nomor Laporan:</div>
                        <div class="detail-value">
                            <?php echo $nomor_laporan > 0 ? '#' . $nomor_laporan : '-'; ?>
                        </div>

                        <div class="detail-label">Nama Pelapor:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($k['nama_pelapor']); ?></div>
                        
                        <div class="detail-label">Kontak WhatsApp:</div>
                        <div class="detail-value">
                            <span class="phone-display"><?php echo htmlspecialchars($display_phone); ?></span>
                        </div>
                        
                        <div class="detail-label">Fakultas/Unit/Lembaga:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($k['unit_lembaga']); ?></div>
                        
                        <div class="detail-label">Lokasi:</div>
                        <div class="detail-value">
                            <?php 
                            if (!empty($k['lokasi'])) {
                                echo '<span style="color: var(--info); font-weight: 500;">' . htmlspecialchars($k['lokasi']) . '</span>';
                            } else {
                                echo '<span style="color: #999; font-style: italic;">(Tidak dilengkapi)</span>';
                            }
                            ?>
                        </div>
                        
                        <div class="detail-label">Tanggal & Waktu:</div>
                        <div class="detail-value">
                            <strong><?php echo date('d/m/Y', strtotime($k['tanggal'])); ?></strong> <?php echo htmlspecialchars($k['waktu']); ?>
                        </div>
                        
                        <div class="detail-label">Masalah:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($k['keluhan'] ?? '-'); ?></div>
                        
                        <div class="detail-label">Status:</div>
                        <div class="detail-value">
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Keluhan -->
                <div class="detail-section">
                    <h3>Keluhan</h3>
                    <div class="text-content"><?php echo nl2br(htmlspecialchars($k['keluhan'])); ?></div>
                </div>

                <!-- Action Buttons -->
                <div class="detail-section" style="border-bottom: none; padding-top: 20px;">
                    <div class="detail-actions">
                        <a href="../form.php?keluhan_id=<?php echo $k['id']; ?>" class="btn btn-success">
                            Kerjakan Keluhan
                        </a>
                        
                        <?php if ($whatsapp_number): ?>
                            <a href="<?php echo $whatsapp_url; ?>" target="_blank" class="btn-whatsapp">
                                Hubungi via WhatsApp
                            </a>
                        <?php else: ?>
                            <button class="btn-whatsapp" disabled title="Nomor WhatsApp tidak valid">
                                Hubungi via WhatsApp
                            </button>
                        <?php endif; ?>
                        
                        <a href="keluhan_list.php" class="btn btn-secondary">
                            <span>←</span> Kembali
                        </a>
                    </div>
                    
                    <?php if ($whatsapp_number): ?>
                        <div style="text-align: center; margin-top: 15px; color: #666; font-size: 0.9rem;">
                            Akan membuka WhatsApp ke:
                            <strong><?php echo $whatsapp_number; ?></strong>
                        </div>
                    <?php elseif (!empty($k['kontak'])): ?>
                        <div style="text-align: center; margin-top: 15px; color: #dc3545; font-size: 0.9rem;">
                            <span>⚠️ Format nomor tidak valid untuk WhatsApp: </span>
                            <strong><?php echo htmlspecialchars($k['kontak']); ?></strong>
                            <br>
                            <small>Pastikan format: 62xxxxxxxxxx (contoh: 628123456789)</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIWAYAT PENGERJAAN -->
            <?php if (!empty($riwayat)): ?>
            <div class="report-detail" style="margin-top: 30px;">
                <div class="detail-section">
                    <h3>Riwayat Pengerjaan</h3>
                    
                    <?php foreach ($riwayat as $index => $m): ?>
                        <div class="history-card">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                                <strong style="color: var(--dark); font-size: 1.1rem;">
                                    Pengerjaan ke-<?php echo count($riwayat) - $index; ?>
                                </strong>
                                <span style="color: var(--gray); font-size: 0.9rem;">
                                    <strong><?php echo date('d/m/Y', strtotime($m['tanggal'])); ?></strong> <?php echo htmlspecialchars($m['waktu']); ?>
                                </span>
                            </div>

                            <div style="background: white; padding: 15px; border-radius: 8px; margin-top: 10px;">
                                <p style="margin-bottom: 10px;">
                                    <strong style="color: var(--dark);">Hasil:</strong> 
                                    <?php 
                                    $hasil_class = 'status-perlu-monitoring';
                                    if ($m['hasil'] == 'Selesai') {
                                        $hasil_class = 'status-selesai';
                                    } elseif ($m['hasil'] == 'Belum selesai') {
                                        $hasil_class = 'status-belum-selesai';
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $hasil_class; ?>">
                                        <?php echo htmlspecialchars($m['hasil']); ?>
                                    </span>
                                </p>
                                
                                <?php if (!empty($m['tindakan'])): ?>
                                    <p style="margin-bottom: 5px; color: var(--dark); font-weight: 500;">Tindakan:</p>
                                    <div class="text-content" style="margin-top: 10px;">
                                        <?php echo nl2br(htmlspecialchars($m['tindakan'])); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($m['catatan'])): ?>
                                    <p style="margin-top: 15px; margin-bottom: 5px; color: var(--dark); font-weight: 500;">Catatan:</p>
                                    <div style="background: var(--light); padding: 10px; border-radius: 6px; color: var(--gray);">
                                        <?php echo nl2br(htmlspecialchars($m['catatan'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Responsive untuk detail grid
        document.addEventListener('DOMContentLoaded', function() {
            const detailGrids = document.querySelectorAll('.detail-grid');
            
            detailGrids.forEach(grid => {
                // Tambahkan data-label untuk mobile view
                const labels = grid.querySelectorAll('.detail-label');
                const values = grid.querySelectorAll('.detail-value');
                
                if (labels.length === values.length) {
                    for (let i = 0; i < labels.length; i++) {
                        const labelText = labels[i].textContent.replace(':', '');
                        values[i].setAttribute('data-label', labelText);
                    }
                }
            });
            
            // WhatsApp button handler
            const whatsappBtn = document.querySelector('.btn-whatsapp:not(:disabled)');
            if (whatsappBtn) {
                whatsappBtn.addEventListener('click', function(e) {
                    if (!confirm('Buka WhatsApp untuk menghubungi pelapor?')) {
                        e.preventDefault();
                    }
                });
            }
        });
    </script>
</body>
</html>
