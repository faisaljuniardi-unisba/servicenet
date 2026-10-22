<?php
// sukses.php - Halaman konfirmasi sukses pengiriman keluhan (SEDERHANA)

// Ambil ID keluhan dari URL
$id = (int) ($_GET['id'] ?? 0); // dipaksa integer: nilainya ikut dicetak ke dalam <script>

// Jika tidak ada ID, redirect ke form
if (!$id) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="uploads/icon/favicon.ico">
    <title>Keluhan Terkirim - Kampus</title>
    <style>
        /* Custom Styles for Success Page */
        :root {
            --primary: #667eea;
            --primary-dark: #5a6fd8;
            --secondary: #764ba2;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .success-container {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }

        .success-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
            text-align: center;
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

        .success-header {
            background: linear-gradient(135deg, var(--success), #20c997);
            color: white;
            padding: 40px 30px;
            border-bottom: 4px solid rgba(255, 255, 255, 0.1);
        }

        .success-icon {
            font-size: 5rem;
            margin-bottom: 20px;
            display: block;
        }

        .success-header h1 {
            font-size: 2rem;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .success-header .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.6;
        }

        .success-content {
            padding: 30px;
        }

        .success-message {
            font-size: 1.1rem;
            color: var(--gray);
            margin-bottom: 30px;
            line-height: 1.8;
        }

        .whatsapp-info {
            background: rgba(37, 211, 102, 0.1);
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            border-left: 4px solid #25D366;
        }

        .whatsapp-info p {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #128C7E;
            font-weight: 500;
        }

        .keluhan-id {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
            border: 2px solid var(--border);
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--primary);
        }

        .action-buttons {
            margin-top: 30px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            width: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        /* Commented buttons - siap digunakan nanti jika diperlukan */
        /*
        .btn-secondary {
            background: var(--light);
            color: var(--dark);
            border: 2px solid var(--border);
            margin-top: 10px;
        }
        
        .btn-secondary:hover {
            background: var(--border);
            transform: translateY(-3px);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success), #20c997);
            color: white;
            margin-top: 10px;
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(40, 167, 69, 0.4);
        }
        */

        .info-note {
            margin: 20px 0;
            padding: 18px;
            background: #fff8e6;
            border: 1px solid #f3c969;
            border-left: 5px solid #f0ad24;
            border-radius: 10px;
            color: #6b4b00;
            text-align: center;
            line-height: 1.55;
        }

        .info-note-title {
            display: block;
            margin-bottom: 6px;
            font-size: 1rem;
            font-weight: 700;
            color: #8a5a00;
        }

        .survey-link {
            color: #604000;
            font-weight: 700;
            text-decoration: underline;
            text-underline-offset: 3px;
            word-break: break-word;
        }

        .survey-link:hover {
            color: #1f5faa;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .success-header {
                padding: 30px 20px;
            }
            
            .success-header h1 {
                font-size: 1.7rem;
            }
            
            .success-content {
                padding: 25px 20px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .success-header {
                padding: 25px 15px;
            }
            
            .success-header h1 {
                font-size: 1.5rem;
            }
            
            .success-content {
                padding: 20px 15px;
            }
            
            .success-message {
                font-size: 1rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js"></script>
</head>
<body>
    <div class="success-container">
        <div class="success-card">
            <header class="success-header">
                <div class="success-icon">✅</div>
                <h1>Laporan Berhasil Terkirim!</h1>
                <p class="subtitle">
                    Terima kasih telah melaporkan keluhan Anda
                </p>
            </header>

            <div class="success-content">
                <div class="success-message">
                    Keluhan Anda telah berhasil dikirim ke tim IT Psitek.
                </div>

                <div class="whatsapp-info">
                    <p>
                        <strong>Komunikasi via WhatsApp:</strong>
                    </p>
                    <p style="margin-top: 5px; font-size: 0.95rem;">
                        Tim Psitek akan menghubungi Anda melalui nomor WhatsApp yang terdaftar.
                    </p>
                </div>

                <div class="keluhan-id">
                    Nomor Laporan: <strong>#<?php echo htmlspecialchars($id); ?></strong>
                </div>
                
                <div class="info-note">
                    <span class="info-note-title">Catatan:</span>
                    Silahkan isi Kuesioner Layanan Psitek di link berikut:
                    <a class="survey-link" href="https://forms.gle/mhkrsjD2hh4iy2Vx8" target="_blank" rel="noopener noreferrer">https://forms.gle/mhkrsjD2hh4iy2Vx8</a>
                </div>
               

                <div class="action-buttons">
                    <a href="index.php" class="btn btn-primary">
                        <span>←</span> Kembali ke Form
                    </a>
                    
                    <!-- Tombol yang di-nonaktifkan (dikomentari) untuk kebutuhan masa depan -->
                    <!--
                    <button onclick="printPage()" class="btn btn-secondary">
                        <span>🖨️</span> Cetak Konfirmasi
                    </button>
                    
                    <a href="https://wa.me/628123456789?text=Halo,%20saya%20ingin%20konfirmasi%20keluhan%20WiFi%20dengan%20nomor:%20%23<?php echo $id; ?>" 
                       target="_blank" 
                       class="btn btn-success">
                        <span>💬</span> Konfirmasi via WhatsApp
                    </a>
                    -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto scroll ke atas
        window.scrollTo(0, 0);
        
        /*
        // Fungsi untuk mencetak halaman (di-nonaktifkan)
        function printPage() {
            window.print();
        }
        
        // Fungsi untuk menyalin nomor keluhan ke clipboard (di-nonaktifkan)
        function copyKeluhanId() {
            const keluhanId = "<?php echo $id; ?>";
            navigator.clipboard.writeText(keluhanId).then(() => {
                alert("Nomor keluhan #" + keluhanId + " telah disalin ke clipboard!");
            });
        }
        */
    </script>
</body>
</html>
