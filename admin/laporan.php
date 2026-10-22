<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config/pagination.php';

// Check if user can manage reports
if (!isset($_SESSION['user_id']) || !canManageReports($_SESSION['jabatan'])) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$admin_csrf = $_SESSION['admin_csrf'];
$csrf_valid = static function (): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['admin_csrf'], (string) $_POST['csrf_token']);
};

// Daftar unit/fakultas (sama dengan di index.php)
$daftar_unit = require __DIR__ . '/../config/units.php';

// Penghapusan wajib POST + CSRF, agar tautan atau situs lain tidak bisa
// menghapus laporan atas nama admin yang sedang login.
if (isset($_POST['delete_report'])) {
    if (!$csrf_valid()) {
        http_response_code(403);
        exit('Permintaan tidak valid.');
    }
    $id = (int) $_POST['delete_report'];
    $stmt = $pdo->prepare("DELETE FROM maintenance WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: laporan.php?success=deleted');
    exit;
}

// Handle bulk delete
if (isset($_POST['delete_selected'])) {
    if (!$csrf_valid()) {
        http_response_code(403);
        exit('Permintaan tidak valid.');
    }
    $selected_ids = $_POST['selected_reports'] ?? [];
    if (!empty($selected_ids)) {
        $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM maintenance WHERE id IN ($placeholders)");
        $stmt->execute($selected_ids);
        header('Location: laporan.php?success=bulk_deleted&count=' . count($selected_ids));
        exit;
    } else {
        $error = "Pilih setidaknya satu laporan untuk dihapus!";
    }
}

// Handle bulk export
if (isset($_POST['export_selected'])) {
    $selected_ids = $_POST['selected_reports'] ?? [];
    if (!empty($selected_ids)) {
        $_SESSION['export_ids'] = $selected_ids;
        header('Location: export_pdf.php?bulk=1');
        exit;
    } else {
        $error = "Pilih setidaknya satu laporan untuk diexport!";
    }
}

    if (isset($_GET['error'])) {
        $error_messages = [
            'photonotfound' => 'Data foto tidak ditemukan di database.',
            'filenotfound' => 'File foto tidak ditemukan di server.',
            'notfound' => 'Laporan tidak ditemukan.'
        ];
        
        if (isset($error_messages[$_GET['error']])) {
            echo '<div class="alert alert-danger">' . $error_messages[$_GET['error']] . '</div>';
        }
    }

// Handle export all
if (isset($_POST['export_all'])) {
    $_SESSION['export_all'] = true;
    header('Location: export_pdf.php?all=1');
    exit;
}

// Get all reports with staff names - pemilik akun di posisi pertama
$user_id = $_SESSION['user_id'];
$stmt = $pdo->query("
    SELECT m.*, 
           COALESCE(GROUP_CONCAT(s.nama 
    ORDER BY ms.urutan ASC
    SEPARATOR ' - '
 ), '-') AS staff_names
    FROM maintenance m
    LEFT JOIN maintenance_staff ms ON m.id = ms.maintenance_id
    LEFT JOIN staff_it s ON ms.staff_id = s.id
    GROUP BY m.id
    ORDER BY m.tanggal DESC, m.waktu DESC
");
$reports = $stmt->fetchAll();

// Get filter values
$filter_unit = $_GET['unit'] ?? '';
$filter_hasil = $_GET['hasil'] ?? '';
$filter_bulan = $_GET['bulan'] ?? '';

// Apply filters
if ($filter_unit || $filter_hasil || $filter_bulan) {
    $filtered_reports = [];
    foreach ($reports as $report) {
        $match = true;
        
        if ($filter_unit && stripos($report['unit_lembaga'], $filter_unit) === false) {
            $match = false;
        }
        
        if ($filter_hasil && $report['hasil'] !== $filter_hasil) {
            $match = false;
        }
        
        // if ($filter_tanggal && $report['tanggal'] !== $filter_tanggal) {
        //     $match = false;
        // }
        
        // if ($filter_staff && stripos($report['staff_names'], $filter_staff) === false) {
        //     $match = false;
        // }
        
        if ($filter_bulan) {
            $report_bulan = date('Y-m', strtotime($report['tanggal']));
            if ($report_bulan !== $filter_bulan) {
                $match = false;
            }
        }
        
        if ($match) {
            $filtered_reports[] = $report;
        }
    }
    $reports = $filtered_reports;
}

$total_reports = count($reports);
[$per_page, $page] = pagination_settings();
$total_pages = max(1, (int) ceil($total_reports / $per_page));
$page = min($page, $total_pages);
$reports = array_slice($reports, ($page - 1) * $per_page, $per_page);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../uploads/icon/favicon.ico">
    <title>Daftar Laporan - Psitek</title>
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/theme.css">
    <link rel="stylesheet" href="../css/unit-picker.css">
    <script src="../js/theme.js"></script>
    <script src="../js/unit-picker.js" defer></script>
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
                <h1>Laporan Maintenance</h1>
                <div class="user-info">
                    <span class="user-name"><?php echo $_SESSION['nama']; ?> (<?php echo $_SESSION['jabatan']; ?>)</span>
                </div>
            </header>

            <?php if (isset($_GET['success'])): ?>
                <div class="success-message">
                    <?php if ($_GET['success'] == 'deleted'): ?>
                        Laporan berhasil dihapus!
                    <?php elseif ($_GET['success'] == 'bulk_deleted'): ?>
                        <?php echo $_GET['count']; ?> laporan berhasil dihapus!
                    <?php elseif ($_GET['success'] == 'updated'): ?>
                        Laporan berhasil diperbarui!
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Enhanced Filter Section -->
            <div class="filter-section enhanced-filter">
                <h3>Filter</h3>
                
                <div class="filter-stats">
                    <div class="filter-info">
                        Total ditemukan: <strong><?php echo $total_reports; ?> laporan</strong>
                    </div>
                    <?php if ($filter_unit || $filter_hasil || $filter_bulan): ?>
                        <div class="filter-info">
                            Filter aktif: 
                            <?php 
                            $active_filters = [];
                            if ($filter_unit) $active_filters[] = "Unit: $filter_unit";
                            // if ($filter_staff) $active_filters[] = "Staff: $filter_staff";
                            if ($filter_hasil) $active_filters[] = "Hasil: $filter_hasil";
                            // if ($filter_tanggal) $active_filters[] = "Tanggal: $filter_tanggal";
                            if ($filter_bulan) $active_filters[] = "Bulan: $filter_bulan";
                            echo implode(', ', $active_filters);
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <form method="GET" class="filter-form report-filter-form">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="unit">Fakultas/Unit</label>
                            <select id="unit" name="unit">
                                <option value="">-- Semua Fakultas/Unit --</option>
                                <optgroup label="Fakultas">
                                    <?php
                                    // Loop untuk semua unit
                                    foreach ($daftar_unit as $unit) {
                                        // Cek apakah unit termasuk dalam fakultas (bisa disesuaikan)
                                        // Untuk sederhananya, kita tampilkan semua dalam satu optgroup
                                        $selected = ($filter_unit === $unit) ? 'selected' : '';
                                        echo "<option value=\"" . htmlspecialchars($unit) . "\" $selected>" . htmlspecialchars($unit) . "</option>";
                                    }
                                    ?>
                                </optgroup>
                            </select>
                        </div>
                        
                        <!-- <div class="filter-group">
                            <label for="staff">Staff IT</label>
                            <select id="staff" name="staff">
                                <option value="">-- Semua Staff IT --</option>
                                <?php
                                // Get staff list ordered by position (Koordinator first, then Staff IT)
                                $stmt_staff = $pdo->query("
                                    SELECT id, nama, jabatan 
                                    FROM staff_it 
                                    WHERE status = 'active' 
                                    AND jabatan IN ('Koordinator IT', 'Staff IT')
                                    ORDER BY 
                                        CASE 
                                            WHEN jabatan = 'Koordinator IT' THEN 1
                                            WHEN jabatan = 'Staff IT' THEN 2
                                            ELSE 3
                                        END,
                                        nama
                                ");
                                $staff_options = $stmt_staff->fetchAll();
                                
                                // Group staff by position
                                $koordinator_staff = [];
                                $staff_it = [];
                                
                                foreach ($staff_options as $staff) {
                                    if ($staff['jabatan'] == 'Koordinator IT') {
                                        $koordinator_staff[] = $staff;
                                    } else {
                                        $staff_it[] = $staff;
                                    }
                                }
                                ?>
                                
                                <?php if (!empty($koordinator_staff)): ?>
                                    <optgroup label="Koordinator IT">
                                        <?php foreach ($koordinator_staff as $staff): ?>
                                            <option value="<?php echo htmlspecialchars($staff['nama']); ?>" 
                                                <?php echo $filter_staff == $staff['nama'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($staff['nama']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                
                                <?php if (!empty($staff_it)): ?>
                                    <optgroup label="Staff IT">
                                        <?php foreach ($staff_it as $staff): ?>
                                            <option value="<?php echo htmlspecialchars($staff['nama']); ?>" 
                                                <?php echo $filter_staff == $staff['nama'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($staff['nama']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div> -->
                        
                        <div class="filter-group">
                            <label for="hasil">Hasil</label>
                            <select id="hasil" name="hasil">
                                <option value="">Semua Hasil</option>
                                <option value="Selesai" <?php echo $filter_hasil == 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                                <option value="Perlu monitoring" <?php echo $filter_hasil == 'Perlu monitoring' ? 'selected' : ''; ?>>Perlu monitoring</option>
                                <option value="Belum selesai" <?php echo $filter_hasil == 'Belum selesai' ? 'selected' : ''; ?>>Belum selesai</option>
                            </select>
                        </div>
                        
                        <!-- <div class="filter-group">
                            <label for="tanggal">Tanggal Spesifik</label>
                            <input type="date" id="tanggal" name="tanggal" value="<?php echo $filter_tanggal; ?>">
                        </div> -->
                        
                        <div class="filter-group">
                            <label for="bulan">Bulan</label>
                            <input type="month" id="bulan" name="bulan" value="<?php echo $filter_bulan; ?>">
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn-filter btn-filter-primary">
                                Terapkan Filter
                            </button>
                            <a href="laporan.php" class="btn-filter btn-filter-secondary">
                                Reset Filter
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Reports Table -->
            <div class="data-table">
                <div class="table-header">
                    <h3>Daftar Laporan (<?php echo $total_reports; ?>)</h3>
                    <div class="table-actions">
                        <?php render_page_size_control($per_page); ?>
                        <button type="button" onclick="exportSelected()" class="btn btn-success">
                            Export Terpilih
                        </button>
                        <button type="button" onclick="deleteSelected()" class="btn btn-danger">
                            Hapus Terpilih
                        </button>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="export_all" class="btn btn-primary">
                            Export Semua PDF
                            </button>
                        </form>
                    </div>
                </div>

                <div class="table-container">
                    <form method="POST" id="exportForm">
                        <table>
                            <thead>
                                <tr>
                                    <th width="30">
                                        <input type="checkbox" id="select-all">
                                    </th>
                                    <th>Tanggal/Waktu</th>
                                    <th>Staff IT</th>
                                    <th>Nama Pengguna</th>
                                    <th>Fakultas/Unit</th>
                                    <th>Hasil</th>
                                    <th width="120">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($reports)): ?>
                                    <tr>
                                        <td colspan="7" class="no-data">
                                            <div style="text-align: center; padding: 40px;">
                                                <p>Tidak ada data laporan</p>
                                                <?php if ($filter_unit || $filter_hasil): ?>
                                                    <p style="font-size: 0.9rem; color: #666;">
                                                        Coba ubah filter pencarian Anda
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($reports as $report): ?>
                                        <tr>
                                            <td data-label="Pilih">
                                                <input type="checkbox" name="selected_reports[]" value="<?php echo $report['id']; ?>" class="report-checkbox">
                                            </td>
                                            <td data-label="Tanggal/Waktu">
                                                <strong><?php echo date('d/m/Y', strtotime($report['tanggal'])); ?></strong><br>
                                                <small style="color: #666;"><?php echo $report['waktu']; ?></small>
                                            </td>
                                            <td data-label="Staff IT">
                                                <div class="staff-multiline">
                                                    <?php echo htmlspecialchars($report['staff_names'] ?? '-'); ?>
                                                </div>
                                            </td>

                                            <td data-label="Nama Pengguna">
                                                <div class="truncate-text" title="<?php echo htmlspecialchars($report['nama_pengguna'] ?? '-'); ?>">
                                                    <?php echo htmlspecialchars($report['nama_pengguna'] ?? '-'); ?>
                                                </div>
                                            </td>
                                            <td data-label="Unit">
                                                <div class="truncate-text" title="<?php echo htmlspecialchars($report['unit_lembaga']); ?>">
                                                    <?php echo htmlspecialchars($report['unit_lembaga']); ?>
                                                </div>
                                            </td>
                                            <!-- <td data-label="Keluhan">
                                                <div class="truncate-text" title="<?php echo htmlspecialchars($report['keluhan']); ?>">
                                                    <?php echo strlen($report['keluhan']) > 50 ? substr($report['keluhan'], 0, 50) . '...' : $report['keluhan']; ?>
                                                </div>
                                            </td> -->
                                            <td data-label="Hasil">
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
                                            </td>
                                            <td data-label="Aksi">
                                                <div class="action-buttons action-compact">
                                                    <a href="view_laporan.php?id=<?php echo $report['id']; ?>"
                                                    class="btn btn-info btn-xs"
                                                    title="Lihat Detail">
                                                    <span>Lihat</span>
                                                    </a>

                                                    <a href="export_pdf.php?id=<?php echo $report['id']; ?>"
                                                    class="btn btn-success btn-xs"
                                                    title="Export PDF">
                                                    <span>PDF</span>
                                                    </a>

                                                    <?php $sudah_ttd = trim((string) ($report['tanda_tangan_pengguna'] ?? '')) !== ''; ?>
                                                    <button type="button"
                                                    class="btn btn-xs btn-ttd<?php echo $sudah_ttd ? ' is-signed' : ''; ?>"
                                                    data-sign-copy="<?php echo $report['id']; ?>"
                                                    <?php echo $sudah_ttd ? 'disabled' : ''; ?>
                                                    title="<?php echo $sudah_ttd ? 'Pengguna sudah menandatangani' : 'Salin link tanda tangan pengguna'; ?>">
                                                    <span><?php echo $sudah_ttd ? 'TTD ✓' : 'Link TTD'; ?></span>
                                                    </button>

                                                    <button type="button" class="btn btn-danger btn-xs" title="Hapus" onclick="deleteReport(<?php echo (int) $report['id']; ?>)">Hapus</button>
                                                </div>
                                            </td>

                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
                <?php render_pagination($page, $total_pages, $per_page); ?>
            </div>
        </main>
    </div>

    <script>
    function exportSelected() {
        const checkboxes = document.querySelectorAll('.report-checkbox:checked');
        if (checkboxes.length === 0) {
            alert('Pilih setidaknya satu laporan untuk diexport!');
            return false;
        }
        
        // Create form dynamically
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = <?php echo json_encode($admin_csrf); ?>;
        form.appendChild(csrf);
        
        checkboxes.forEach(checkbox => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_reports[]';
            input.value = checkbox.value;
            form.appendChild(input);
        });
        
        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'export_selected';
        submitInput.value = '1';
        form.appendChild(submitInput);
        
        document.body.appendChild(form);
        form.submit();
    }

    function deleteSelected() {
        const checkboxes = document.querySelectorAll('.report-checkbox:checked');
        if (checkboxes.length === 0) {
            alert('Pilih setidaknya satu laporan untuk dihapus!');
            return false;
        }
        
        if (!confirm(`Yakin ingin menghapus ${checkboxes.length} laporan yang dipilih?`)) {
            return false;
        }
        
        // Create form dynamically
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = <?php echo json_encode($admin_csrf); ?>;
        form.appendChild(csrf);
        
        checkboxes.forEach(checkbox => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_reports[]';
            input.value = checkbox.value;
            form.appendChild(input);
        });
        
        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'delete_selected';
        submitInput.value = '1';
        form.appendChild(submitInput);
        
        document.body.appendChild(form);
        form.submit();
    }

    function deleteReport(reportId) {
        if (!confirm('Yakin ingin menghapus laporan ini?')) {
            return;
        }
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.innerHTML = '<input type="hidden" name="csrf_token"><input type="hidden" name="delete_report">';
        form.querySelector('[name="csrf_token"]').value = <?php echo json_encode($admin_csrf); ?>;
        form.querySelector('[name="delete_report"]').value = reportId;
        document.body.appendChild(form);
        form.submit();
    }

    // Select all checkbox
    document.getElementById('select-all').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.report-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });

    // Update select all checkbox state when individual checkboxes change
    document.querySelectorAll('.report-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allCheckboxes = document.querySelectorAll('.report-checkbox');
            const selectAll = document.getElementById('select-all');
            const checkedCount = document.querySelectorAll('.report-checkbox:checked').length;
            
            selectAll.checked = checkedCount === allCheckboxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
        });
    });
    </script>

    <script>
    // Tombol "Link TTD": token dibuat lewat endpoint saat tombol ditekan,
    // sehingga membuka daftar laporan tidak membuat token untuk semua baris.
    (function () {
        'use strict';

        function flash(button, message, tone) {
            var label = button.querySelector('span');
            var original = label.textContent;
            label.textContent = message;
            button.classList.add(tone);
            setTimeout(function () {
                label.textContent = original;
                button.classList.remove(tone);
            }, 2000);
        }

        function copyText(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }

            // Konteks non-HTTPS tidak punya Clipboard API, jadi pakai
            // textarea sementara sebagai cadangan.
            return new Promise(function (resolve, reject) {
                var scratch = document.createElement('textarea');
                scratch.value = text;
                scratch.setAttribute('readonly', '');
                scratch.style.position = 'fixed';
                scratch.style.opacity = '0';
                document.body.appendChild(scratch);
                scratch.select();
                var ok = false;
                try {
                    ok = document.execCommand('copy');
                } catch (err) {
                    ok = false;
                }
                document.body.removeChild(scratch);
                ok ? resolve() : reject(new Error('copy gagal'));
            });
        }

        document.querySelectorAll('[data-sign-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var id = button.getAttribute('data-sign-copy');
                button.disabled = true;

                fetch('sign_link.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data.ok) {
                            throw new Error(data.error || 'Gagal');
                        }
                        return copyText(data.url).then(function () {
                            flash(button, 'Tersalin!', 'is-copied');
                        });
                    })
                    .catch(function () {
                        flash(button, 'Gagal', 'is-failed');
                    })
                    .then(function () { button.disabled = false; });
            });
        });
    })();
    </script>
</body>
</html>
