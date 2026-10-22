<?php
require_once __DIR__ . '/bootstrap.php';

// Debug session admin

// Check if user has admin access
if (!isset($_SESSION['user_id']) || !canAccessAdmin($_SESSION['jabatan'])) {
    header('Location: ../login.php');
    exit;
}
// ... kode selanjutnya

// Get statistics for dashboard
$total_laporan = $pdo->query("SELECT COUNT(*) FROM maintenance")->fetchColumn();
$laporan_hari_ini = $pdo->query("SELECT COUNT(*) FROM maintenance WHERE tanggal = CURDATE()")->fetchColumn();
$laporan_normal = $pdo->query("SELECT COUNT(*) FROM maintenance WHERE hasil = 'Selesai'")->fetchColumn();
$laporan_monitoring = $pdo->query("SELECT COUNT(*) FROM maintenance WHERE hasil = 'Perlu monitoring'")->fetchColumn();
$laporan_belum = $pdo->query("SELECT COUNT(*) FROM maintenance WHERE hasil = 'Belum selesai'")->fetchColumn();

// Get top units with most complaints
$top_units = $pdo->query("
    SELECT unit_lembaga, COUNT(*) as jumlah 
    FROM maintenance 
    GROUP BY unit_lembaga 
    ORDER BY jumlah DESC 
    LIMIT 5
")->fetchAll();

// Get monthly data for chart
$monthly_data = $pdo->query("
    SELECT DATE_FORMAT(tanggal, '%Y-%m') as bulan, COUNT(*) as jumlah 
    FROM maintenance 
    GROUP BY DATE_FORMAT(tanggal, '%Y-%m') 
    ORDER BY bulan DESC 
    LIMIT 12
")->fetchAll();
// // Get staff performance - HANYA Staff IT saja (tidak termasuk Super Admin)
// $staff_performance = $pdo->query("
//     SELECT s.id, s.nama, 
//            COUNT(ms.maintenance_id) as total_laporan,
//            SUM(CASE WHEN m.hasil = 'Normal kembali' THEN 1 ELSE 0 END) as selesai
//     FROM staff_it s
//     LEFT JOIN maintenance_staff ms ON s.id = ms.staff_id
//     LEFT JOIN maintenance m ON ms.maintenance_id = m.id
//     WHERE s.status = 'active' AND s.jabatan = 'Staff IT'
//     GROUP BY s.id, s.nama
//     ORDER BY total_laporan DESC
// ")->fetchAll();
// Query ini berlaku sama bagi Super Admin dan Koordinator IT.
$staff_performance_query = "
    SELECT s.id, s.nama,
           COUNT(ms.maintenance_id) AS total_laporan,
           SUM(CASE WHEN m.hasil = 'Selesai' THEN 1 ELSE 0 END) AS selesai
    FROM staff_it s
    LEFT JOIN maintenance_staff ms ON s.id = ms.staff_id
    LEFT JOIN maintenance m ON ms.maintenance_id = m.id
    WHERE s.status = 'active' AND s.jabatan != 'Super Admin'
    GROUP BY s.id, s.nama
    ORDER BY total_laporan DESC
";

$staff_performance = $pdo->query($staff_performance_query)->fetchAll();

// Ambil keluhan WiFi yang masih baru
$stmt_keluhan = $pdo->query("
    SELECT *
    FROM keluhan_wifi
    WHERE status = 'baru'
    ORDER BY tanggal DESC, waktu DESC, id DESC
    LIMIT 5
");
$keluhan_masuk = $stmt_keluhan->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../uploads/icon/favicon.ico">
    <title>Dashboard - Psitek</title>
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/theme.css">
    <script src="../js/theme.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Tambahkan CSS ini di dalam tag <style> di head -->
<style>
    a.stat-card { color: inherit; text-decoration: none; }
    a.stat-card:hover { transform: translateY(-3px); }
    a.stat-card:focus-visible { outline: 3px solid var(--primary); outline-offset: 4px; }
    /* CSS untuk Keluhan WiFi Masuk di Dashboard */
    
    /* Wrapper untuk tabel */
    .mobile-table-wrapper {
        position: relative;
    }
    
    /* Desktop View - Tampil di layar besar */
    .desktop-view {
        display: table;
        width: 100%;
    }
    
    /* Mobile Cards View - Sembunyikan di desktop */
    .mobile-cards-view {
        display: none;
    }
    
    /* Preview untuk keluhan di desktop */
    .keluhan-preview {
        max-height: 60px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        line-height: 1.4;
        font-size: 0.9rem;
        color: var(--gray);
    }
    
    /* Action buttons di dashboard */
    .action-dashboard {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .action-dashboard .btn {
        min-width: 70px;
        padding: 6px 10px;
        font-size: 0.75rem;
    }
    
    /* Mobile Cards Styling */
    .keluhan-card {
        background: white;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 15px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        border-left: 4px solid var(--info);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--border);
    }
    
    .card-date {
        display: flex;
        flex-direction: column;
    }
    
    .card-date strong {
        color: var(--dark);
        font-size: 0.9rem;
    }
    
    .card-date small {
        color: var(--gray);
        font-size: 0.75rem;
    }
    
    .card-body {
        margin-bottom: 15px;
    }
    
    .card-row {
        display: flex;
        margin-bottom: 8px;
        align-items: flex-start;
    }
    
    .card-row strong {
        flex: 0 0 70px;
        color: var(--dark);
        font-size: 0.85rem;
    }
    
    .card-row span {
        flex: 1;
        color: var(--gray);
        font-size: 0.85rem;
    }
    
    .card-keluhan {
        max-height: 80px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 4;
        -webkit-box-orient: vertical;
        line-height: 1.4;
        color: var(--gray);
        font-size: 0.85rem;
    }
    
    .card-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .card-actions .btn {
        flex: 1;
        min-width: 120px;
        padding: 8px 12px;
        font-size: 0.8rem;
        text-align: center;
    }
    
    /* Status badges untuk mobile */
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
    }
    
    .status-baru-mobile {
        background: rgba(255, 193, 7, 0.15);
        color: #856404;
        border: 1px solid #ffc107;
    }
    
    .status-diproses-mobile {
        background: rgba(23, 162, 184, 0.15);
        color: #0c5460;
        border: 1px solid #17a2b8;
    }
    
    /* Responsive Breakpoints */
    @media (max-width: 768px) {
        /* Sembunyikan tabel desktop, tampilkan cards */
        .desktop-view {
            display: none;
        }
        
        .mobile-cards-view {
            display: block;
        }
        
        /* Perbaikan action buttons di mobile */
        .card-actions {
            flex-direction: column;
        }
        
        .card-actions .btn {
            width: 100%;
            margin-bottom: 5px;
        }
    }
    
    @media (min-width: 769px) and (max-width: 1024px) {
        /* Tablet view - sesuaikan tabel */
        .desktop-view th,
        .desktop-view td {
            padding: 10px 8px;
            font-size: 0.85rem;
        }
        
        .keluhan-preview {
            -webkit-line-clamp: 2;
            max-height: 40px;
        }
        
        .action-dashboard {
            flex-direction: column;
            gap: 5px;
        }
        
        .action-dashboard .btn {
            width: 100%;
            padding: 5px 8px;
        }
    }
    
    /* Very small mobile */
    @media (max-width: 480px) {
        .keluhan-card {
            padding: 12px;
        }
        
        .card-row {
            flex-direction: column;
            margin-bottom: 10px;
        }
        
        .card-row strong {
            flex: none;
            margin-bottom: 3px;
        }
        
        .card-keluhan {
            -webkit-line-clamp: 3;
        }
        
        .card-actions .btn {
            min-width: 100px;
            padding: 7px 10px;
        }
    }
    
    @media (max-width: 360px) {
        .card-actions {
            flex-direction: column;
        }
        
        .card-actions .btn {
            width: 100%;
        }
        
        .status-badge {
            min-width: 70px;
            font-size: 0.6rem;
            padding: 3px 6px;
        }
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
                    <li class="active">
                        <a href="dashboard.php">Dashboard</a>
                    </li>

                    <li>
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
            <!-- <div class="dashboard-header"> -->
                <header class="content-header">
                    <div class="header-left">
                        <a href="../form.php" class="btn btn-primary">
                            Form Maintenance
                        </a>
                    </div>
                    <div class="user-info">
                        <span class="user-name"><?php echo $_SESSION['nama']; ?> (<?php echo $_SESSION['jabatan']; ?>)</span>
                    </div>
                </header>
            <!-- </div> -->

            <!-- Performance Section dengan Keluhan WiFi Masuk -->
            <div class="performance-section">
                <h2>Keluhan Masuk</h2>
                <p style="color: var(--gray);">Maksimal 5 keluhan baru terbaru.</p>

                <?php if (empty($keluhan_masuk)): ?>
                    <p style="text-align: center; color: var(--gray); padding: 20px; background: var(--light); border-radius: 10px;">
                        Tidak ada keluhan baru.
                    </p>
                <?php else: ?>
                    <!-- Container untuk tabel responsif -->
                    <div class="table-container mobile-table-container" style="margin-top: 20px;">
                        <div class="mobile-table-wrapper">
                            <!-- Desktop View -->
                            <table class="responsive-table desktop-view">
                                <thead>
                                    <tr>
                                        <th width="100">Tanggal</th>
                                        <th width="100">Pelapor</th>
                                        <th width="120">Unit</th>
                                        <th>Keluhan</th>
                                        <th width="150">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($keluhan_masuk as $k): ?>
                                    <tr>
                                        <td data-label="Tanggal">
                                            <div style="display: flex; flex-direction: column;">
                                                <strong><?php echo date('d/m/Y', strtotime($k['tanggal'])); ?></strong>
                                                <small style="color: var(--gray); font-size: 0.8rem;"><?php echo $k['waktu']; ?></small>
                                            </div>
                                        </td>
                                        <td data-label="Pelapor" class="truncate-text">
                                            <?php echo htmlspecialchars($k['nama_pelapor']); ?>
                                        </td>
                                        <td data-label="Unit" class="truncate-text">
                                            <?php echo htmlspecialchars($k['unit_lembaga']); ?>
                                        </td>
                                        <td data-label="Keluhan">
                                            <div class="keluhan-preview" title="<?php echo htmlspecialchars($k['keluhan']); ?>">
                                                <?php 
                                                $keluhan_text = htmlspecialchars($k['keluhan']);
                                                $preview = strlen($keluhan_text) > 80 ? substr($keluhan_text, 0, 80) . '...' : $keluhan_text;
                                                echo $preview;
                                                ?>
                                            </div>
                                        </td>
                                        <td data-label="Aksi">
                                            <div class="action-buttons action-dashboard">
                                                <a href="keluhan_detail.php?id=<?php echo $k['id']; ?>"
                                                class="btn btn-info btn-sm">
                                                    Lihat
                                                </a>
                                                <a href="../form.php?keluhan_id=<?php echo $k['id']; ?>"
                                                class="btn btn-success btn-sm">
                                                    Kerjakan
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <!-- Mobile View (Card Layout) -->
                            <div class="mobile-cards-view">
                                <?php foreach ($keluhan_masuk as $k): ?>
                                <div class="keluhan-card">
                                    <div class="card-header">
                                        <div class="card-date">
                                            <strong><?php echo date('d/m/Y', strtotime($k['tanggal'])); ?></strong>
                                            <small><?php echo $k['waktu']; ?></small>
                                        </div>
                                        <div class="card-status">
                                            <?php if ($k['status'] === 'baru'): ?>
                                                <span class="status-badge status-baru-mobile">BARU</span>
                                            <?php elseif ($k['status'] === 'diproses'): ?>
                                                <span class="status-badge status-diproses-mobile">DIPROSES</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="card-body">
                                        <div class="card-row">
                                            <strong>Pelapor:</strong>
                                            <span><?php echo htmlspecialchars($k['nama_pelapor']); ?></span>
                                        </div>
                                        <div class="card-row">
                                            <strong>Unit:</strong>
                                            <span><?php echo htmlspecialchars($k['unit_lembaga']); ?></span>
                                        </div>
                                        <div class="card-row">
                                            <strong>Keluhan:</strong>
                                            <div class="card-keluhan">
                                                <?php 
                                                $keluhan_text = htmlspecialchars($k['keluhan']);
                                                $preview = strlen($keluhan_text) > 100 ? substr($keluhan_text, 0, 100) . '...' : $keluhan_text;
                                                echo nl2br($preview);
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card-actions">
                                        <a href="keluhan_detail.php?id=<?php echo $k['id']; ?>"
                                        class="btn btn-info btn-sm">
                                            Lihat Detail
                                        </a>
                                        <a href="../form.php?keluhan_id=<?php echo $k['id']; ?>"
                                        class="btn btn-success btn-sm">
                                            Kerjakan
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 15px; text-align: center;">
                        <a href="keluhan_list.php" class="btn btn-secondary btn-sm">
                            Lihat Semua Keluhan
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <a class="stat-card" <?php if (canManageReports($_SESSION['jabatan'])): ?>href="laporan.php"<?php endif; ?>>
                    <div class="stat-info">
                        <h3>Total Laporan</h3>
                        <span class="stat-number"><?php echo $total_laporan; ?></span>
                        <small>Semua waktu</small>
                    </div>
                </a>

                <!-- <div class="stat-card">
                    <div class="stat-info">
                        <h3>Hari Ini</h3>
                        <span class="stat-number"><?php echo $laporan_hari_ini; ?></span>
                        <small>Laporan hari ini</small>
                    </div>
                </div> -->

                <a class="stat-card" <?php if (canManageReports($_SESSION['jabatan'])): ?>href="laporan.php?hasil=Selesai"<?php endif; ?>>
                    <div class="stat-info">
                        <h3>Selesai</h3>
                        <span class="stat-number"><?php echo $laporan_normal; ?></span>
                        <small><?php echo $total_laporan > 0 ? round(($laporan_normal/$total_laporan)*100, 1) : 0; ?>%</small>
                    </div>
                </a>

                <a class="stat-card" href="keluhan_list.php">
                    <div class="stat-info">
                        <h3>Perlu Monitoring</h3>
                        <span class="stat-number"><?php echo $laporan_monitoring; ?></span>
                        <small><?php echo $total_laporan > 0 ? round(($laporan_monitoring/$total_laporan)*100, 1) : 0; ?>%</small>
                    </div>
                </a>
            </div>
            
            <!-- Charts and Additional Info -->
            <div class="charts-grid">
                <div class="chart-section">
                    <h2>Grafik Laporan Bulanan</h2>
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
                
                <div class="info-section">
                    <h2>Unit dengan Keluhan Terbanyak</h2>
                    <div class="top-units">
                        <?php if (empty($top_units)): ?>
                            <p class="no-data">Belum ada data laporan</p>
                        <?php else: ?>
                            <?php foreach ($top_units as $index => $unit): ?>
                                <div class="unit-item">
                                    <span class="unit-rank">#<?php echo $index + 1; ?></span>
                                    <span class="unit-name"><?php echo $unit['unit_lembaga']; ?></span>
                                    <span class="unit-count"><?php echo $unit['jumlah']; ?> laporan</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>




            <!-- Staff Performance - HANYA Staff IT
            <div class="performance-section">
                <h2>Performance Staff IT</h2>
                <?php if (empty($staff_performance)): ?>
                    <div class="no-data" style="text-align: center; padding: 40px;">
                        <p>Belum ada data performance staff IT</p>
                        <small style="color: #666;">Data hanya menampilkan staff dengan jabatan "Staff IT"</small>
                    </div>
                <?php else: ?>
                    <div class="performance-grid">
                        <?php foreach ($staff_performance as $staff): ?>
                            <div class="performance-card">
                                <h3><?php echo $staff['nama']; ?></h3>
                                <div class="performance-stats">
                                    <div class="stat">
                                        <span class="stat-value"><?php echo $staff['total_laporan']; ?></span>
                                        <span class="stat-label">Total Laporan</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-value"><?php echo $staff['selesai']; ?></span>
                                        <span class="stat-label">Selesai</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-value">
                                            <?php echo $staff['total_laporan'] > 0 ? round(($staff['selesai']/$staff['total_laporan'])*100, 1) : 0; ?>%
                                        </span>
                                        <span class="stat-label">Success Rate</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div> -->
        </main>
    </div>

    <script>
        // Monthly Chart
        const monthlyData = <?php echo json_encode($monthly_data); ?>;
        const labels = monthlyData.map(item => {
            const date = new Date(item.bulan + '-01');
            return date.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
        }).reverse();
        
        const data = monthlyData.map(item => item.jumlah).reverse();
        const chartColors = () => document.documentElement.dataset.theme === 'dark'
            ? { bar: 'rgba(151, 140, 255, .72)', border: '#a79dff', text: '#d9d6e6', grid: 'rgba(217, 214, 230, .16)' }
            : { bar: 'rgba(111, 97, 232, .70)', border: '#6f61e8', text: '#686778', grid: 'rgba(104, 103, 120, .14)' };
        let monthlyChartColors = chartColors();

        const ctx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Jumlah Laporan',
                    data: data,
                    backgroundColor: monthlyChartColors.bar,
                    borderColor: monthlyChartColors.border,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        ticks: { color: monthlyChartColors.text },
                        grid: { color: monthlyChartColors.grid }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: monthlyChartColors.text
                        },
                        grid: { color: monthlyChartColors.grid },
                    }
                }
            }
        });

        window.addEventListener('netcarethemechange', () => {
            monthlyChartColors = chartColors();
            monthlyChart.data.datasets[0].backgroundColor = monthlyChartColors.bar;
            monthlyChart.data.datasets[0].borderColor = monthlyChartColors.border;
            monthlyChart.options.scales.x.ticks.color = monthlyChartColors.text;
            monthlyChart.options.scales.x.grid.color = monthlyChartColors.grid;
            monthlyChart.options.scales.y.ticks.color = monthlyChartColors.text;
            monthlyChart.options.scales.y.grid.color = monthlyChartColors.grid;
            monthlyChart.update();
        });

        // Script untuk responsive table di dashboard
    document.addEventListener('DOMContentLoaded', function() {
        const mobileTableWrapper = document.querySelector('.mobile-table-wrapper');
        
        if (mobileTableWrapper) {
            // Function to toggle between table and card views
            function toggleView() {
                const isMobile = window.innerWidth <= 768;
                
                // Update view based on screen size
                if (isMobile) {
                    // Mobile view - use cards
                    mobileTableWrapper.querySelector('.desktop-view').style.display = 'none';
                    mobileTableWrapper.querySelector('.mobile-cards-view').style.display = 'block';
                } else {
                    // Desktop view - use table
                    mobileTableWrapper.querySelector('.desktop-view').style.display = 'table';
                    mobileTableWrapper.querySelector('.mobile-cards-view').style.display = 'none';
                }
            }
            
            // Initial check
            toggleView();
            
            // Check on resize
            window.addEventListener('resize', toggleView);
        }
        
        // Add click to expand functionality for keluhan preview
        const keluhanPreviews = document.querySelectorAll('.keluhan-preview, .card-keluhan');
        keluhanPreviews.forEach(preview => {
            preview.addEventListener('click', function() {
                const fullText = this.getAttribute('title') || this.textContent;
                if (fullText.length > 100) {
                    alert('Keluhan Lengkap:\n\n' + fullText);
                }
            });
            
            // Add cursor style if text is truncated
            if (preview.scrollHeight > preview.clientHeight) {
                preview.style.cursor = 'pointer';
                preview.title = preview.title || 'Klik untuk melihat keluhan lengkap';
            }
        });
    });
    </script>
</body>
</html>
