<?php
// index.php - Form Keluhan WiFi Publik

// Mulai session untuk error handling dan CSRF token
session_start();

// Generate CSRF token jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ambil data error dan form data dari session jika ada
$error_message = '';
$form_data = [];

if (isset($_SESSION['form_error'])) {
    $error_message = $_SESSION['form_error'];
    unset($_SESSION['form_error']);
}

if (isset($_SESSION['form_data'])) {
    $form_data = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}

// Atau ambil dari query string (fallback)
if (isset($_GET['error']) && $_GET['error'] == 1 && empty($error_message)) {
    $error_message = 'Terjadi kesalahan. Silakan coba lagi.';
}

$daftar_unit = require __DIR__ . '/config/units.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="uploads/icon/favicon.ico">
    <title>Form Keluhan - Psitek</title>
    <link rel="stylesheet" href="css/theme.css">
    <link rel="stylesheet" href="css/unit-picker.css">
    <script src="js/theme.js"></script>
    <script src="js/unit-picker.js" defer></script>
    <style>
        /* Custom Styles for Public Form */
        :root {
            --primary: #6f61e8;
            --primary-dark: #5b4ed4;
            --secondary: #8b7cf6;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
            --border: #dee2e6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f6f6fa;
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .public-container {
            width: 100%;
            max-width: 840px;
            margin: 0 auto;
        }

        .public-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #ecebf2;
            box-shadow: 0 5px 24px rgba(35, 31, 71, 0.07);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .public-header {
            background: white;
            color: #4d4c59;
            padding: 32px 30px;
            text-align: center;
            border-bottom: 1px solid #ecebf2;
        }

        .public-header h1 {
            font-size: 1.85rem;
            margin-bottom: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .public-header-logo {
            width: 42px;
            height: 42px;
            object-fit: contain;
            background: white;
            padding: 4px;
            border-radius: 8px;
        }

        .public-header .subtitle {
            font-size: 1.1rem;
            color: #777789;
            opacity: 1;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        .public-form {
            padding: 40px 30px;
        }

        /* Grid dengan 2 kolom untuk desktop, 1 kolom untuk mobile */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-bottom: 25px;
        }

        /* Form group yang akan memenuhi 2 kolom */
        .form-group-full {
            grid-column: span 2;
        }

        .form-group {
            margin-bottom: 25px;
        }

        /* Field dengan margin bottom lebih kecil saat di dalam grid */
        .form-grid .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        /* Label khusus untuk WhatsApp */
        .whatsapp-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--success);
        }

        .whatsapp-label::before {
            content: "📱";
            font-size: 1.1rem;
        }

        /* Label khusus untuk Lokasi */
        .location-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--info);
        }

        .location-label::before {
            content: "📍";
            font-size: 1.1rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--light);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        /* Input khusus untuk WhatsApp */
        .whatsapp-input {
            border-color: var(--success);
            background: rgba(40, 167, 69, 0.05);
        }

        .whatsapp-input:focus {
            border-color: var(--success);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
        }

        /* Input khusus untuk Lokasi */
        .location-input {
            border-color: var(--info);
            background: rgba(23, 162, 184, 0.05);
        }

        .location-input:focus {
            border-color: var(--info);
            box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.2);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 18px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease;
            text-decoration: none;
        }

        .btn-primary:hover {
            transform: none;
            box-shadow: none;
            background: var(--primary-dark);
        }

        .form-note {
            text-align: center;
            color: var(--gray);
            font-size: 0.9rem;
            margin-top: 25px;
            padding: 15px;
            background: var(--light);
            border-radius: 8px;
            border-left: 4px solid var(--warning);
        }

        .admin-access {
            margin: 16px 0 0;
            text-align: center;
            font-size: .78rem;
        }

        .admin-access a {
            color: #8a8996;
            text-decoration: none;
        }

        .admin-access a:hover,
        .admin-access a:focus-visible {
            color: var(--primary);
            text-decoration: underline;
        }

        .whatsapp-note {
            margin-top: 5px;
            font-size: 0.85rem;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .whatsapp-note::before {
            content: "💡";
        }

        .location-note {
            margin-top: 5px;
            font-size: 0.85rem;
            color: var(--info);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .location-note::before {
            content: "💡";
        }

        /* Error message styling */
        .error-message {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            padding: 15px 20px;
            border-radius: 10px;
            margin: 20px 30px;
            border-left: 4px solid var(--danger);
            animation: fadeIn 0.5s ease-out;
        }

        .error-message-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .public-header {
                padding: 30px 20px;
            }
            
            .public-header h1 {
                font-size: 1.8rem;
            }
            
            .public-form {
                padding: 30px 20px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            /* Reset span untuk mobile */
            .form-group-full {
                grid-column: span 1;
            }
            
            /* Tambah margin bottom untuk form group di mobile */
            .form-grid .form-group {
                margin-bottom: 20px;
            }

            .error-message {
                margin: 15px 20px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .public-header {
                padding: 25px 15px;
            }
            
            .public-header h1 {
                font-size: 1.6rem;
                flex-direction: column;
                gap: 10px;
            }
            
            .public-form {
                padding: 25px 15px;
            }

            .error-message {
                margin: 15px;
                padding: 12px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="public-container">
        <div class="public-card">
            <header class="public-header">
                <h1>
                    <img src="uploads/images/logo-unisba.png" alt="Logo Unisba" class="public-header-logo">
                    Form Keluhan
                </h1>
                <p class="subtitle">
                    Silakan sampaikan keluhan Internet Anda
                </p>
            </header>

            <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <div class="error-message-content">
                    <span style="font-size: 1.2rem;">⚠️</span>
                    <div>
                        <strong>Terjadi Kesalahan:</strong><br>
                        <?php echo htmlspecialchars((string) $error_message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" action="submit_keluhan.php" class="public-form" onsubmit="return validateWhatsApp()">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nama</label>
                        <input
                            type="text"
                            name="nama_pelapor"
                            required
                            placeholder="Nama lengkap"
                            value="<?php echo htmlspecialchars($form_data['nama_pelapor'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Nomor WhatsApp</label>
                        <input
                            type="text"
                            name="kontak"
                            id="whatsappInput"
                            class="whatsapp-input"
                            required
                            placeholder="Contoh: 628123456789"
                            pattern="^628[0-9]{8,11}$"
                            title="Format: 62xxxxxxxxxx (contoh: 628123456789)"
                            value="<?php echo htmlspecialchars($form_data['kontak'] ?? ''); ?>">
                        <small>Satu nomor WhatsApp dapat mengirim keluhan setiap 3 jam. Gunakan nomor Anda sendiri.</small>
                    </div>

                    <div class="form-group">
                        <label>Fakultas / Unit</label>
                        <select name="unit_lembaga" required>
                            <option value="">-- Pilih Fakultas / Unit --</option>
                            <?php 
                            $selected_unit = $form_data['unit_lembaga'] ?? '';
                            foreach ($daftar_unit as $unit): 
                                $selected = ($selected_unit === $unit) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($unit); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Lokasi (Gedung/Ruangan)</label>
                        <input
                            type="text"
                            name="lokasi"
                            class="location-input"
                            required
                            placeholder="Contoh: Gedung Kuliah Lt. 2, Ruang 203"
                            value="<?php echo htmlspecialchars($form_data['lokasi'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group form-group-full">
                    <label>Keluhan</label>
                    <textarea
                        name="keluhan"
                        rows="4"
                        required
                        placeholder="Jelaskan keluhan yang Anda alami"><?php echo htmlspecialchars($form_data['keluhan'] ?? ''); ?></textarea>
                </div>

                <!-- CSRF Token untuk keamanan -->
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <!-- Hidden field untuk jenis_masalah dengan value default -->
                <input type="hidden" name="jenis_masalah" value="WiFi">

                <button type="submit" class="btn-primary">
                    Kirim Keluhan
                </button>
            </form>

            <p class="form-note">
                Nomor WhatsApp akan digunakan untuk komunikasi lanjutan.
            </p>
            <p class="admin-access"><a href="login.php">AKSES IT</a></p>
        </div>
    </div>

    <script>
        function validateWhatsApp() {
            const whatsappInput = document.getElementById('whatsappInput');
            const phoneNumber = whatsappInput.value.trim();
            
            // Hapus karakter non-digit
            const cleanNumber = phoneNumber.replace(/\D/g, '');
            
            // Validasi format Indonesia (62xxxxxxxxxx)
            const whatsappRegex = /^628[0-9]{8,11}$/;
            
            if (!whatsappRegex.test(cleanNumber)) {
                alert('Format nomor WhatsApp tidak valid!\n\nGunakan format: 62xxxxxxxxxx\nContoh: 628123456789\n\nPastikan nomor diawali dengan 62 dan terdiri dari 11-14 digit.');
                whatsappInput.focus();
                whatsappInput.select();
                return false;
            }
            
            // Update value dengan nomor yang sudah dibersihkan
            whatsappInput.value = cleanNumber;
            return true;
        }
        
        // Real-time validation dan formatting
        document.getElementById('whatsappInput').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            // Auto-add 62 if starts with 0 or 8
            if (value.startsWith('0')) {
                value = '62' + value.substring(1);
            } else if (value.startsWith('8')) {
                value = '62' + value;
            }
            
            e.target.value = value;
        });

        // Focus pada field pertama yang error jika ada error message
        <?php if (!empty($error_message)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Cari input pertama yang kosong
            const inputs = document.querySelectorAll('input[required], select[required], textarea[required]');
            for (let input of inputs) {
                if (!input.value.trim()) {
                    input.focus();
                    break;
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
