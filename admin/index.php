<?php
require_once __DIR__ . '/bootstrap.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['jabatan'] !== 'Super Admin') {
    header('Location: ../login.php');
    exit;
}

// Get statistics for dashboard
$total_laporan = $pdo->query("SELECT COUNT(*) FROM maintenance")->fetchColumn();
$laporan_normal = $pdo->query("SELECT COUNT(*) FROM maintenance WHERE hasil = 'Selesai'")->fetchColumn();
$laporan_monitoring = $pdo->query("SELECT COUNT(*) FROM maintenance WHERE hasil = 'Perlu monitoring'")->fetchColumn();
$laporan_belum = $pdo->query("SELECT COUNT(*) FROM maintenance WHERE hasil = 'Belum selesai'")->fetchColumn();

// Get monthly data for chart
$monthly_data = $pdo->query("
    SELECT DATE_FORMAT(tanggal, '%Y-%m') as bulan, COUNT(*) as jumlah 
    FROM maintenance 
    GROUP BY DATE_FORMAT(tanggal, '%Y-%m') 
    ORDER BY bulan DESC 
    LIMIT 12
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../uploads/icon/favicon.ico">
    <title>Network Psitek</title>
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/theme.css">
    <script src="../js/theme.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <h1>Dashboard Maintenance IT</h1>
                <div class="user-info">
                    <span>Halo, <?php echo $_SESSION['nama']; ?> (Admin)</span>
                </div>
            </header>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Laporan</h3>
                        <span class="stat-number"><?php echo $total_laporan; ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Selesai</h3>
                        <span class="stat-number"><?php echo $laporan_normal; ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Perlu Monitoring</h3>
                        <span class="stat-number"><?php echo $laporan_monitoring; ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Belum Selesai</h3>
                        <span class="stat-number"><?php echo $laporan_belum; ?></span>
                    </div>
                </div>
            </div>

            <div class="chart-section">
                <h2>Grafik Laporan Bulanan</h2>
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Chart configuration
        const monthlyData = <?php echo json_encode($monthly_data); ?>;
        const labels = monthlyData.map(item => item.bulan).reverse();
        const data = monthlyData.map(item => item.jumlah).reverse();

        const ctx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Jumlah Laporan',
                    data: data,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
