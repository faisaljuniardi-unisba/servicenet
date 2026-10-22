<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config/upload_security.php';

// Check if user can manage reports
if (!isset($_SESSION['user_id']) || !canManageReports($_SESSION['jabatan'])) {
    header('Location: laporan.php?error=unauthorized');
    exit;
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

// Get report data
$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM maintenance WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    header('Location: laporan.php?error=notfound');
    exit;
}

// Get staff list - exclude Super Admin
$query = "SELECT id, nama FROM staff_it WHERE status = 'active' AND jabatan != 'Super Admin'";
$stmt = $pdo->query($query);
$staff_list = $stmt->fetchAll();

// Get selected staff for this report - FIXED QUERY
$stmt_selected = $pdo->prepare("SELECT staff_id FROM maintenance_staff WHERE maintenance_id = ?");
$stmt_selected->execute([$id]);
$selected_staff_ids = $stmt_selected->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['admin_csrf'], (string) $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan muat ulang formulir.');
    }
    // Process form submission
    $tanggal = $_POST['tanggal'];
    $waktu = $_POST['waktu'];
    $staff_it = $_POST['staff_it'] ?? [];
    $nama_pengguna = $_POST['nama_pengguna'];
    $unit_lembaga = $_POST['unit_lembaga'];
    $keluhan = $_POST['keluhan'];
    $tindakan = $_POST['tindakan'];
    $hasil = $_POST['hasil'];
    if (!in_array($hasil, ['Selesai', 'Perlu monitoring', 'Belum selesai'], true)) {
        header('Location: laporan.php?error=invalid_status');
        exit;
    }
    $catatan = $_POST['catatan'];
    
    // Handle file uploads (support multiple) and deletions
    // Parse existing photos (support legacy single filename or JSON array)
    $existing_photos = [];
    if (!empty($report['lampiran_foto'])) {
        $decoded = json_decode($report['lampiran_foto'], true);
        if (is_array($decoded)) {
            $existing_photos = $decoded;
        } else {
            $existing_photos = [$report['lampiran_foto']];
        }
    }

    // Delete selected photos (from checkbox list)
    $to_delete = $_POST['delete_photos'] ?? [];
    foreach ($to_delete as $del) {
        $del = trim($del);
        if ($del === '') continue;
        $path = "../uploads/photos/" . $del;
        if (file_exists($path)) {
            @unlink($path);
        }
        // remove from existing list
        $idx = array_search($del, $existing_photos);
        if ($idx !== false) unset($existing_photos[$idx]);
    }

    // Handle newly uploaded files
    $uploaded_photos = [];
    if (isset($_FILES['lampiran_foto'])) {
        $target_dir = __DIR__ . '/../uploads/photos';

        $file_count = is_array($_FILES['lampiran_foto']['name']) ? count($_FILES['lampiran_foto']['name']) : 0;
        for ($i = 0; $i < $file_count; $i++) {
            $error = $_FILES['lampiran_foto']['error'][$i];
            if ($error !== UPLOAD_ERR_OK) {
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
            }
        }
    }

    // Final photos = remaining existing + newly uploaded
    $final_photos = array_values(array_merge($existing_photos, $uploaded_photos));
    $lampiran_foto = '';
    if (!empty($final_photos)) {
        // store as JSON for consistency with new form
        $lampiran_foto = json_encode($final_photos);
    }
    
    try {
        $pdo->beginTransaction();
        
    // Update maintenance table
    $stmt = $pdo->prepare("UPDATE maintenance SET tanggal = ?, waktu = ?, nama_pengguna = ?, unit_lembaga = ?, keluhan = ?, tindakan = ?, hasil = ?, catatan = ?, lampiran_foto = ? WHERE id = ?");
    $stmt->execute([$tanggal, $waktu, $nama_pengguna, $unit_lembaga, $keluhan, $tindakan, $hasil, $catatan, $lampiran_foto, $id]);
        
        // Tim yang sudah tercatat merupakan jejak penugasan dan tidak boleh
        // diubah melalui pembaruan laporan. Untuk data lama yang belum punya
        // tim, set sekali dari pilihan form.
        if (empty($selected_staff_ids)) {
            $staff_it = array_values(array_unique(array_map('intval', $staff_it)));
            $stmt_insert = $pdo->prepare("INSERT INTO maintenance_staff (maintenance_id, staff_id, urutan) VALUES (?, ?, ?)");
            foreach ($staff_it as $urutan => $staff_id) {
                if ($staff_id > 0) {
                    $stmt_insert->execute([$id, $staff_id, $urutan]);
                }
            }
        }
        
        $pdo->commit();
        header('Location: laporan.php?success=updated');
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Terjadi kesalahan: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../uploads/icon/favicon.ico">
    <title>Edit Laporan - Psitek</title>
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
                <h1>Edit Laporan Maintenance</h1>
                <div class="user-info">
                    <span><?php echo $_SESSION['nama']; ?></span>
                </div>
            </header>

            <a href="laporan.php" class="back-button">
                <span>←</span> Kembali ke Daftar Laporan
            </a>

            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="form-container">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                    <!-- Informasi Umum -->
                    <div class="filter-section enhanced-filter">
                        <h3>Informasi Umum</h3>
                        
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="tanggal">Hari/Tanggal:</label>
                                <input type="date" id="tanggal" name="tanggal" value="<?php echo $report['tanggal']; ?>" required>
                            </div>
                            
                            <div class="filter-group">
                                <label for="waktu">⏰ Waktu:</label>
                                <input type="time" id="waktu" name="waktu" value="<?php echo $report['waktu']; ?>" required>
                            </div>
                        </div>
                        
                        <div class="filter-group">
                            <label>Staff IT:</label>
                            <div class="checkbox-group">
                                <?php foreach ($staff_list as $staff): ?>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="staff_it[]" value="<?php echo $staff['id']; ?>"
                                            <?php echo in_array($staff['id'], $selected_staff_ids) ? 'checked' : ''; ?>
                                            <?php echo !empty($selected_staff_ids) ? 'disabled' : ''; ?>>
                                        <?php if (!empty($selected_staff_ids) && in_array($staff['id'], $selected_staff_ids)): ?>
                                            <input type="hidden" name="staff_it[]" value="<?php echo $staff['id']; ?>">
                                        <?php endif; ?>
                                        <?php echo $staff['nama']; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Informasi Pengguna -->
                    <div class="filter-section enhanced-filter">
                        <h3>Informasi Pengguna</h3>
                        
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="nama_pengguna">Nama (yang melakukan keluhan):</label>
                                <input type="text" id="nama_pengguna" name="nama_pengguna" value="<?php echo htmlspecialchars($report['nama_pengguna']); ?>" required>
                            </div>
                            
                            <div class="filter-group">
                                <label for="unit_lembaga">Fakultas/Unit/Lembaga:</label>
                                <input type="text" id="unit_lembaga" name="unit_lembaga" value="<?php echo htmlspecialchars($report['unit_lembaga']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="filter-group">
                            <label for="keluhan">Keluhan:</label>
                            <textarea id="keluhan" name="keluhan" rows="4" required style="width: 100%; padding: 12px; border: 2px solid var(--border); border-radius: 8px; font-size: 0.9rem; font-family: inherit; resize: vertical;"><?php echo htmlspecialchars($report['keluhan']); ?></textarea>
                        </div>
                    </div>

                    <!-- Tindakan dan Hasil -->
                    <div class="filter-section enhanced-filter">
                        <h3>Tindakan dan Hasil</h3>
                        
                        <div class="filter-group">
                            <label for="tindakan">Tindakan:</label>
                            <textarea id="tindakan" name="tindakan" rows="4" required style="width: 100%; padding: 12px; border: 2px solid var(--border); border-radius: 8px; font-size: 0.9rem; font-family: inherit; resize: vertical;"><?php echo htmlspecialchars($report['tindakan']); ?></textarea>
                        </div>
                        
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="hasil">Hasil:</label>
                                <select id="hasil" name="hasil" required>
                                    <option value="Selesai" <?php echo $report['hasil'] == 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                                    <option value="Perlu monitoring" <?php echo $report['hasil'] == 'Perlu monitoring' ? 'selected' : ''; ?>>Perlu monitoring</option>
                                    <option value="Belum selesai" <?php echo $report['hasil'] == 'Belum selesai' ? 'selected' : ''; ?>>Belum selesai</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="lampiran_foto">Lampiran Foto (bisa multiple):</label>
                                <input type="file" id="lampiran_foto" name="lampiran_foto[]" accept="image/*" multiple style="padding: 8px; border: 2px solid var(--border); border-radius: 8px; background: white; width: 100%;">

                                <?php
                                // Tampilkan foto yang sudah ada (support JSON atau single)
                                $existing_photos = [];
                                if (!empty($report['lampiran_foto'])) {
                                    $dec = json_decode($report['lampiran_foto'], true);
                                    if (is_array($dec)) $existing_photos = $dec;
                                    else $existing_photos = [$report['lampiran_foto']];
                                }
                                ?>

                                <?php if (!empty($existing_photos)): ?>
                                    <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                        <?php foreach ($existing_photos as $ep): ?>
                                            <div style="width:140px; text-align:center; padding:6px; background: rgba(255,255,255,0.03); border-radius:6px;">
                                                <img src="../uploads/photos/<?php echo htmlspecialchars($ep); ?>" alt="<?php echo htmlspecialchars($ep); ?>" style="width:100%; height:90px; object-fit:cover; border-radius:4px; border:1px solid #ddd;" onerror="this.style.display='none'">
                                                <div style="margin-top:6px; font-size:0.85rem;">
                                                    <label style="display:flex; gap:6px; align-items:center; justify-content:center;">
                                                        <input type="checkbox" name="delete_photos[]" value="<?php echo htmlspecialchars($ep); ?>"> Hapus
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="filter-group">
                            <label for="catatan">Catatan:</label>
                            <textarea id="catatan" name="catatan" rows="3" style="width: 100%; padding: 12px; border: 2px solid var(--border); border-radius: 8px; font-size: 0.9rem; font-family: inherit; resize: vertical;"><?php echo htmlspecialchars($report['catatan']); ?></textarea>
                        </div>
                    </div>

                    <div class="filter-actions" style="margin-top: 30px;">
                        <button type="submit" class="btn-filter btn-filter-primary">
                            Simpan Perubahan
                        </button>
                        <a href="laporan.php" class="btn-filter btn-filter-secondary">
                            Batal
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
