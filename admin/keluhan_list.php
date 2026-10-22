<?php
// WAJIB LOGIN & ROLE ADMIN / KOORDINATOR
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config/pagination.php';

// Ambil semua keluhan WiFi
$stmt = $pdo->query("
    SELECT *
    FROM keluhan_wifi
    WHERE status IN ('baru', 'diproses')
    ORDER BY tanggal DESC, waktu DESC
");
$keluhan = $stmt->fetchAll();

// Hitung total keluhan
$total_keluhan = count($keluhan);
[$per_page, $page] = pagination_settings();
$total_pages = max(1, (int) ceil($total_keluhan / $per_page));
$page = min($page, $total_pages);
$keluhan = array_slice($keluhan, ($page - 1) * $per_page, $per_page);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../uploads/icon/favicon.ico">
    <title>Daftar Keluhan - Psitek</title>
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

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <header class="content-header">
                <h1>Daftar Keluhan</h1>
                <div class="user-info">
                    <span class="user-name"><?php echo $_SESSION['nama']; ?> (<?php echo $_SESSION['jabatan']; ?>)</span>
                </div>
            </header>

            <!-- INFO -->
            <div class="filter-stats">
                <div class="filter-info">
                    Total keluhan: <strong><?= $total_keluhan; ?></strong>
                </div>
            </div>

            <!-- TABLE -->
            <div class="data-table">
                <div class="table-header">
                    <h3>Data Keluhan</h3>
                    <?php render_page_size_control($per_page); ?>
                </div>

                <div class="table-container mobile-table-container">
                    <?php if ($total_keluhan == 0): ?>
                        <!-- Tampilan ketika tidak ada data -->
                        <div class="no-data" style="padding: 40px; text-align: center;">
                            <p style="font-size: 1.1rem; color: var(--dark); margin-bottom: 10px;">Tidak ada keluhan WiFi</p>
                            <p style="color: var(--gray); font-size: 0.9rem;">
                                Semua keluhan sudah diproses atau belum ada keluhan masuk.
                            </p>
                        </div>
                    <?php else: ?>
                        <!-- Tampilan ketika ada data -->
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>No. Laporan</th>
                                    <th>Tanggal</th>
                                    <th>Pelapor</th>
                                    <th>Unit</th>
                                    <th>Keluhan</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($keluhan as $k): 
                                    // Truncate keluhan untuk tampilan
                                    $keluhan_text = htmlspecialchars($k['keluhan']);
                                    $keluhan_preview = strlen($keluhan_text) > 150 ? substr($keluhan_text, 0, 150) . '...' : $keluhan_text;
                                ?>
                                <tr>
                                    <td data-label="No. Laporan">
                                        <strong>#<?= (int) $k['maintenance_id']; ?></strong>
                                    </td>
                                    <td data-label="Tanggal">
                                        <div style="display: flex; flex-direction: column;">
                                            <strong style="color: var(--dark); font-size: 0.9rem;"><?= date('d/m/Y', strtotime($k['tanggal'])); ?></strong>
                                            <small style="color: var(--gray); font-size: 0.75rem;"><?= $k['waktu']; ?></small>
                                        </div>
                                    </td>

                                    <td data-label="Pelapor">
                                        <div class="truncate-text" style="max-width: 120px;" title="<?= htmlspecialchars($k['nama_pelapor']); ?>">
                                            <?= htmlspecialchars($k['nama_pelapor']); ?>
                                        </div>
                                    </td>

                                    <td data-label="Unit">
                                        <div class="truncate-text" style="max-width: 150px;" title="<?= htmlspecialchars($k['unit_lembaga']); ?>">
                                            <?= htmlspecialchars($k['unit_lembaga']); ?>
                                        </div>
                                    </td>

                                    <td data-label="Keluhan">
                                        <div class="keluhan-content" title="<?= htmlspecialchars($k['keluhan']); ?>">
                                            <?= nl2br($keluhan_preview); ?>
                                        </div>
                                    </td>

                                    <td data-label="Status">
                                        <?php if ($k['status'] === 'baru'): ?>
                                            <span class="status-badge status-baru">BARU</span>
                                        <?php elseif ($k['status'] === 'diproses'): ?>
                                            <span class="status-badge status-diproses">DIPROSES</span>
                                        <?php elseif ($k['status'] === 'selesai'): ?>
                                            <span class="status-badge status-selesai">SELESAI</span>
                                        <?php endif; ?>
                                    </td>

                                    <td data-label="Aksi">
                                        <div class="action-buttons action-compact">
                                            <a href="keluhan_detail.php?id=<?= $k['id']; ?>"
                                               class="btn btn-info btn-sm"
                                               title="Lihat Detail">
                                                Lihat
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <?php render_pagination($page, $total_pages, $per_page); ?>
            </div>
        </main>
    </div>

</body>
</html>
