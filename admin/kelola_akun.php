<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config/pagination.php';

// Check if user can manage reports
if (!isset($_SESSION['user_id']) || !canManageReports($_SESSION['jabatan'])) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// ... kode selanjutnya

// Initialize variables for edit mode
$edit_mode = false;
$edit_staff = null;

// Handle edit staff - get staff data
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM staff_it WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_staff = $stmt->fetch();
    
    if ($edit_staff) {
        $edit_mode = true;
    }
}

// Handle add/edit staff
if (isset($_POST['save_staff'])) {
    $nama = $_POST['nama'];
    $username = $_POST['username'];
    $jabatan = $_POST['jabatan'];
    
    if ($edit_mode && isset($_POST['staff_id'])) {
        // Update existing staff
        $staff_id = $_POST['staff_id'];
        
        if (!empty($_POST['password'])) {
            // Update with new password
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // GUNAKAN BCRYYT
            $stmt = $pdo->prepare("UPDATE staff_it SET nama = ?, username = ?, password = ?, jabatan = ? WHERE id = ?");
            $ok = $stmt->execute([$nama, $username, $password, $jabatan, $staff_id]);

            // Post-update verification (diagnostic): verify that stored hash matches given password
            if ($ok) {
                $check = $pdo->prepare("SELECT password FROM staff_it WHERE id = ?");
                $check->execute([$staff_id]);
                $stored = $check->fetchColumn();
                if ($stored === false) {
                    error_log("[kelola_akun] Unable to fetch password after update for staff_id={$staff_id}");
                    $error = "Terjadi kesalahan saat memperbarui password. Cek log server.";
                } else {
                    if (!password_verify($_POST['password'], $stored)) {
                        error_log("[kelola_akun] Password verification FAILED after update for staff_id={$staff_id}. Stored hash length=" . strlen($stored));
                        $error = "Password tampaknya tidak tersimpan dengan benar. Silakan coba lagi.";
                    }
                }
            }
        } else {
            // Update without changing password
            $stmt = $pdo->prepare("UPDATE staff_it SET nama = ?, username = ?, jabatan = ? WHERE id = ?");
            $stmt->execute([$nama, $username, $jabatan, $staff_id]);
        }
        
        if (!isset($error)) $success = "Staff IT berhasil diperbarui!";
        $edit_mode = false; // Keluar dari mode edit setelah berhasil update
    } else {
        // Add new staff
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // GUNAKAN BCRYPT
        $stmt = $pdo->prepare("INSERT INTO staff_it (nama, username, password, jabatan) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$nama, $username, $password, $jabatan])) {
            // verify insert
            $newId = $pdo->lastInsertId();
            $check = $pdo->prepare("SELECT password FROM staff_it WHERE id = ?");
            $check->execute([$newId]);
            $stored = $check->fetchColumn();
            if ($stored === false) {
                error_log("[kelola_akun] Unable to fetch password after insert for newId={$newId}");
                $error = "Terjadi kesalahan saat menyimpan password. Cek log server.";
            } else {
                if (!password_verify($_POST['password'], $stored)) {
                    error_log("[kelola_akun] Password verification FAILED after insert for newId={$newId}. Stored hash length=" . strlen($stored));
                    $error = "Password tidak tersimpan dengan benar. Silakan coba lagi.";
                } else {
                    $success = "Staff IT berhasil ditambahkan!";
                }
            }
        } else {
            $error = "Gagal menambahkan staff IT!";
        }
    }
}

// Handle cancel edit
if (isset($_POST['cancel_edit'])) {
    $edit_mode = false;
    $edit_staff = null;
}

// Handle delete staff
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM staff_it WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: kelola_akun.php?success=deleted');
    exit;
}

// Get all staff
$stmt = $pdo->query("SELECT * FROM staff_it
    ORDER BY CASE jabatan
        WHEN 'Super Admin' THEN 1
        WHEN 'Koordinator IT' THEN 2
        WHEN 'Staff IT' THEN 3
        ELSE 4
    END, nama ASC");
$staff_list = $stmt->fetchAll();
$total_staff = count($staff_list);
[$per_page, $page] = pagination_settings();
$total_pages = max(1, (int) ceil($total_staff / $per_page));
$page = min($page, $total_pages);
$staff_list = array_slice($staff_list, ($page - 1) * $per_page, $per_page);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../uploads/icon/favicon.ico">
    <title>Kelola Akun - Psitek</title>
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
                        <li>
                            <a href="laporan.php">Daftar Laporan</a>
                        </li>
                    <?php endif; ?>
                    
                    <?php if (canManageStaff($_SESSION['jabatan'])): ?>
                        <li class="active">
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
                <h1>Kelola Akun</h1>
                <div class="user-info">
                    <span class="user-name"><?php echo $_SESSION['nama']; ?> (<?php echo $_SESSION['jabatan']; ?>)</span>
                </div>
            </header>

            <?php if (isset($success)): ?>
                <div class="success-message"><?php echo $success; ?></div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['success'])): ?>
                <div class="success-message">
                    <?php if ($_GET['success'] == 'deleted'): ?>
                        Staff IT berhasil dihapus.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Form Tambah/Edit Staff IT Baru -->
            <div class="filter-section enhanced-filter staff-form-section">
                <h3>
                    <?php if ($edit_mode): ?>
                        Edit Staff IT
                    <?php else: ?>
                        Tambah Staff IT Baru
                    <?php endif; ?>
                </h3>
                <div class="filter-stats">
                    <div class="filter-info">
                        <?php if ($edit_mode): ?>
                            Edit data staff IT <?php echo htmlspecialchars($edit_staff['nama']); ?>
                        <?php else: ?>
                            Tambahkan staff IT baru ke dalam sistem
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" class="filter-form">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="staff_id" value="<?php echo $edit_staff['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="nama">Nama Lengkap</label>
                            <input type="text" id="nama" name="nama" 
                                   value="<?php echo $edit_mode ? htmlspecialchars($edit_staff['nama']) : ''; ?>" 
                                   placeholder="Masukkan nama lengkap" required>
                        </div>
                        
                        <div class="filter-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" 
                                   value="<?php echo $edit_mode ? htmlspecialchars($edit_staff['username']) : ''; ?>" 
                                   placeholder="Masukkan username" required>
                        </div>
                        
                        <div class="filter-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" 
                                   placeholder="<?php echo $edit_mode ? 'Kosongkan jika tidak ingin mengubah password' : 'Masukkan password'; ?>" 
                                   <?php echo !$edit_mode ? 'required' : ''; ?>>
                            <small style="color: rgba(255,255,255,0.8);">
                                <?php echo $edit_mode ? 'Kosongkan jika tidak ingin mengubah password' : 'Minimal 6 karakter'; ?>
                            </small>
                        </div>
                        
                        <div class="filter-group">
                            <label for="jabatan">Jabatan</label>
                            <select id="jabatan" name="jabatan" required>
                                <option value="">Pilih Jabatan</option>
                                <option value="Super Admin" <?php echo ($edit_mode && $edit_staff['jabatan'] == 'Super Admin') ? 'selected' : ''; ?>>Super Admin</option>
                                <option value="Koordinator IT" <?php echo ($edit_mode && $edit_staff['jabatan'] == 'Koordinator IT') ? 'selected' : ''; ?>>Koordinator IT</option>
                                <option value="Staff IT" <?php echo ($edit_mode && $edit_staff['jabatan'] == 'Staff IT') ? 'selected' : ''; ?>>Staff IT</option>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" name="save_staff" class="btn-filter btn-filter-primary">
                                <?php if ($edit_mode): ?>
                                    Simpan Perubahan
                                <?php else: ?>
                                    Tambah Staff
                                <?php endif; ?>
                            </button>
                            
                            <?php if ($edit_mode): ?>
                                <button type="submit" name="cancel_edit" class="btn-filter btn-filter-secondary">
                                Batal
                                </button>
                            <?php else: ?>
                                <button type="reset" class="btn-filter btn-filter-secondary">
                                    Reset Form
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Daftar Staff IT -->
            <div class="data-table">
                <div class="table-header">
                    <h3>Daftar Staff IT (<?php echo $total_staff; ?>)</h3>
                    <?php render_page_size_control($per_page); ?>
                </div>

                <div class="table-container">
                    <table class="staff-table">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Username</th>
                                <th>Jabatan</th>
                                <th width="150">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staff_list)): ?>
                                <tr>
                                    <td colspan="4" class="no-data">
                                        <div style="text-align: center; padding: 40px;">
                                            <p>Tidak ada data staff IT</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($staff_list as $staff): ?>
                                    <tr>
                                        <td data-label="Nama">
                                            <?php if ($edit_mode && $edit_staff['id'] == $staff['id']): ?>
                                                <strong style="color: var(--primary);"><?php echo htmlspecialchars($staff['nama']); ?></strong>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($staff['nama']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Username"><?php echo htmlspecialchars($staff['username']); ?></td>
                                        <td data-label="Jabatan">
                                            <span class="status-badge status-<?php echo str_replace(' ', '-', strtolower($staff['jabatan'])); ?>">
                                                <?php echo $staff['jabatan']; ?>
                                            </span>
                                        </td>
                                        <td data-label="Aksi">
                                            <div class="action-buttons">
                                                <a href="kelola_akun.php?edit=<?php echo $staff['id']; ?>" class="btn btn-warning btn-sm" title="Edit Staff">
                                                    Edit
                                                </a>
                                                <a href="kelola_akun.php?delete=<?php echo $staff['id']; ?>" 
                                                   class="btn btn-danger btn-sm" 
                                                   onclick="return confirm('Yakin ingin menghapus staff <?php echo htmlspecialchars($staff['nama']); ?>?')"
                                                   title="Hapus Staff">
                                                    Hapus
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php render_pagination($page, $total_pages, $per_page); ?>
            </div>
        </main>
    </div>

    <script>
        // Password strength indicator (optional)
        document.getElementById('password')?.addEventListener('input', function() {
            const password = this.value;
            const strengthText = document.getElementById('password-strength-text');
            
            if (strengthText) {
                if (password.length === 0) {
                    strengthText.textContent = '';
                } else if (password.length < 6) {
                    strengthText.textContent = 'Kekuatan: Lemah';
                    strengthText.style.color = '#ff4757';
                } else if (password.length < 8) {
                    strengthText.textContent = 'Kekuatan: Sedang';
                    strengthText.style.color = '#ffa502';
                } else {
                    strengthText.textContent = 'Kekuatan: Kuat';
                    strengthText.style.color = '#2ed573';
                }
            }
        });

        // Scroll to form when in edit mode
        <?php if ($edit_mode): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelector('.filter-section').scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
            });
        <?php endif; ?>
    </script>
</body>
</html>
