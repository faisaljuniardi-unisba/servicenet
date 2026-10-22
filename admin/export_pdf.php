<?php
require_once __DIR__ . '/bootstrap.php';

// Check if user can manage reports
if (!isset($_SESSION['user_id']) || !canManageReports($_SESSION['jabatan'])) {
    header('Location: laporan.php?error=unauthorized');
    exit;
}

// Include DomPDF
require_once '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Increase memory limit for PDF generation
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);

// Cache for images to avoid reprocessing same images
$imageCache = [];

// Function to convert image to base64 with error handling and caching
function imageToBase64($imagePath, &$cache = null) {
    global $imageCache;
    
    // Normalize path
    $imagePath = str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
    $imagePath = str_replace('\\', DIRECTORY_SEPARATOR, $imagePath);
    
    // Check cache first
    $cacheKey = md5($imagePath . filemtime($imagePath));
    if (isset($imageCache[$cacheKey])) {
        return $imageCache[$cacheKey];
    }
    
    if (!file_exists($imagePath) || !is_file($imagePath) || filesize($imagePath) == 0) {
        error_log("File not found or empty: " . $imagePath);
        return null;
    }
    
    try {
        // Get image info first
        $imageInfo = getimagesize($imagePath);
        if ($imageInfo === false) {
            error_log("Invalid image file: " . $imagePath);
            return null;
        }
        
        $mimeType = $imageInfo['mime'];
        $imageData = null;
        
        // Handle different image types more efficiently
        $image = null;
        
        switch ($mimeType) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($imagePath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($imagePath);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($imagePath);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($imagePath);
                break;
            default:
                error_log("Unsupported image type: " . $mimeType);
                return null;
        }
        
        if ($image === false) {
            error_log("Failed to create image from: " . $imagePath);
            return null;
        }
        
        // Handle EXIF orientation for JPEGs
        if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($imagePath);
                if ($exif && isset($exif['Orientation'])) {
                    $orientation = $exif['Orientation'];
                    if ($orientation > 1) {
                        $rotated = $image;
                        switch ($orientation) {
                            case 3:
                                $rotated = imagerotate($image, 180, 0);
                                break;
                            case 6:
                                $rotated = imagerotate($image, -90, 0);
                                break;
                            case 8:
                                $rotated = imagerotate($image, 90, 0);
                                break;
                        }
                        if ($rotated !== $image) {
                            imagedestroy($image);
                            $image = $rotated;
                        }
                    }
                }
            } catch (Exception $e) {
                // Ignore EXIF errors
            }
        }
        
        // Use output buffering to capture image data
        ob_start();
        switch ($mimeType) {
            case 'image/jpeg':
                imagejpeg($image, null, 80); // Lower quality to reduce size
                break;
            case 'image/png':
                // Reduce PNG size
                imagesavealpha($image, true);
                imagepng($image, null, 6);
                break;
            case 'image/gif':
                imagegif($image);
                break;
            case 'image/webp':
                imagewebp($image, null, 70);
                break;
            default:
                imagedestroy($image);
                ob_end_clean();
                return null;
        }
        $imageData = ob_get_clean();
        
        // Free memory
        imagedestroy($image);
        
        if ($imageData === false || empty($imageData)) {
            error_log("Failed to encode image: " . $imagePath);
            return null;
        }
        
        // Encode to base64 and cache
        $result = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
        $imageCache[$cacheKey] = $result;
        
        // Free the raw data
        unset($imageData);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error processing image: " . $e->getMessage());
        return null;
    }
}

// Handle single export
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("
        SELECT m.*, 
               GROUP_CONCAT(s.nama SEPARATOR ' - ') as staff_names
        FROM maintenance m
        LEFT JOIN maintenance_staff ms ON m.id = ms.maintenance_id
        LEFT JOIN staff_it s ON ms.staff_id = s.id
        WHERE m.id = ?
        GROUP BY m.id
    ");
    $stmt->execute([$id]);
    $report = $stmt->fetch();
    
    if (!$report) {
        header('Location: laporan.php?error=notfound');
        exit;
    }
    
    generateSinglePDF($report);
}

// Handle bulk export
if (isset($_GET['bulk']) && isset($_SESSION['export_ids'])) {
    $ids = $_SESSION['export_ids'];
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT m.*, 
               GROUP_CONCAT(s.nama SEPARATOR ' - ') as staff_names
        FROM maintenance m
        LEFT JOIN maintenance_staff ms ON m.id = ms.maintenance_id
        LEFT JOIN staff_it s ON ms.staff_id = s.id
        WHERE m.id IN ($placeholders)
        GROUP BY m.id
        ORDER BY m.tanggal DESC, m.waktu DESC
    ");
    $stmt->execute($ids);
    $reports = $stmt->fetchAll();
    
    unset($_SESSION['export_ids']);
    generateBulkPDF($reports);
}

// Handle export all
if (isset($_GET['all']) && isset($_SESSION['export_all'])) {
    $stmt = $pdo->query("
        SELECT m.*, 
               GROUP_CONCAT(s.nama SEPARATOR ' - ') as staff_names
        FROM maintenance m
        LEFT JOIN maintenance_staff ms ON m.id = ms.maintenance_id
        LEFT JOIN staff_it s ON ms.staff_id = s.id
        GROUP BY m.id
        ORDER BY m.tanggal DESC, m.waktu DESC
    ");
    $reports = $stmt->fetchAll();
    
    unset($_SESSION['export_all']);
    generateBulkPDF($reports);
}

function generateSinglePDF($report) {
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    
    $dompdf = new Dompdf($options);
    
    $html = generatePDFContent($report, true);
    
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $dompdf->stream('laporan_maintenance_' . $report['id'] . '.pdf', [
        'Attachment' => true
    ]);
    exit;
}

function generateBulkPDF($reports) {
    global $imageCache;
    
    // Clear cache between bulk exports
    $imageCache = [];
    
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isPhpEnabled', true);
    
    $dompdf = new Dompdf($options);
    
    // Generate HTML in chunks to save memory
    $html = generateBulkPDFContent($reports);
    
    // Free report data before generating PDF
    unset($reports);
    
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Clear cache before streaming
    $imageCache = [];
    
    $dompdf->stream('laporan_maintenance_bulk_' . date('Y-m-d_H-i') . '.pdf', [
        'Attachment' => true
    ]);
    exit;
}

function generateLetterheadHTML() {
    global $imageCache;
    
    $logoPath = '../uploads/images/unisba.png';
    $logoBase64 = imageToBase64($logoPath, $imageCache);
    
    $letterheadHTML = '
    <div style="margin-bottom: 10px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 30%; vertical-align: middle; padding-right: 15px; height: 80px;">
    ';
    
    if ($logoBase64) {
        $letterheadHTML .= '
                    <img src="' . $logoBase64 . '" style="max-height: 80px; width: auto; display: block;" alt="Logo">
        ';
    } else {
        $letterheadHTML .= '
                    <div style="height: 80px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #666;">
                        LOGO
                    </div>
        ';
    }
    
    $letterheadHTML .= '
                </td>
                <td style="width: 70%; vertical-align: middle;">
                    <div style="text-align: right; height: 80px; display: flex; flex-direction: column; justify-content: center;">
                        <div style="font-size: 11px; color: #555; line-height: 1.4;">
                            <strong style="font-size: 12px; color: #000;">Universitas Islam Bandung</strong><br>
                            Jl. Tamansari No.24, Bandung  40116<br>
                            Telp: (022) 4203368<br>
                            Email: network.psitek@unisba.ac.id<br>
                            www.unisba.ac.id
                        </div>
                    </div>
                </td>
            </tr>
        </table>
        
        <div style="border-top: 3px solid #000; margin-top: 10px;"></div>
        <div style="border-top: 1px solid #000; margin-top: 2px; margin-bottom: 15px;"></div>
    </div>
    ';
    
    return $letterheadHTML;
}

function generatePDFContent($report, $isSingle = false) {
    global $imageCache;
    
    // Convert images to base64
    $signatureIT = '';
    $signaturePengguna = '';
    $photoDokumentasiList = [];
    
    if (!empty($report['tanda_tangan_it'])) {
        $signaturePath = '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR . $report['tanda_tangan_it'];
        $signatureIT = imageToBase64($signaturePath, $imageCache);
    }
    
    if (!empty($report['tanda_tangan_pengguna'])) {
        $signaturePath = '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR . $report['tanda_tangan_pengguna'];
        $signaturePengguna = imageToBase64($signaturePath, $imageCache);
    }
    
    // Support multiple photos
    if (!empty($report['lampiran_foto'])) {
        $decodedPhotos = json_decode($report['lampiran_foto'], true);
        if (is_array($decodedPhotos)) {
            $photoNames = $decodedPhotos;
        } else {
            $photoNames = [$report['lampiran_foto']];
        }

        foreach ($photoNames as $pn) {
            $pn = trim($pn);
            if ($pn === '') continue;
            $photoPath = '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . $pn;
            $b64 = imageToBase64($photoPath, $imageCache);
            if ($b64) {
                $photoDokumentasiList[] = $b64;
            }
        }
    }
    
    $letterheadHTML = generateLetterheadHTML();
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page {
                margin: 1.2cm;
            }
            
            body { 
                font-family: DejaVu Sans, Arial, sans-serif; 
                margin: 0;
                padding: 0;
                line-height: 1.3;
                font-size: 11px;
                color: #333;
                width: 100%;
            }
            
            .header { 
                text-align: center; 
                padding-bottom: 5px; 
                margin-bottom: 15px; 
            }
            .section { 
                margin-bottom: 7px;
            }
            .label { 
                font-weight: bold; 
                width: 150px; 
                display: inline-block; 
                vertical-align: top; 
            }
            .content { 
                display: inline-block; 
                width: calc(100% - 160px); 
            }
            .text-content { 
                margin: 0;
                padding: 0;
                background: transparent;
                border: 0;
            }
            .signature-section {
                margin-top: 18px;
                page-break-inside: avoid;
                display: table;
                width: 100%;
                table-layout: fixed;
            }
            .signature-box {
                display: table-cell;
                width: 50%;
                text-align: center;
                vertical-align: top;
                padding: 8px;
            }
            .signature-image {
                max-width: 200px;
                max-height: 80px;
                border: 1px solid #ddd;
                margin-bottom: 10px;
            }
            .signature-mini {
                width: 120px;
                height: 50px;
                object-fit: contain;
                border: 1px solid #ddd;
                background: white;
                display: block;
                margin: 0 auto;
            }
            .no-image-box {
                width: 120px;
                height: 50px;
                border: 1px dashed #999;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 10px;
                color: #999;
                background: #f9f9f9;
                margin: 0 auto;
            }
            .signature-line { 
                border-top: 1px solid #000; 
                width: 200px; 
                margin: 0 auto; 
                padding-top: 5px;
                margin-top: 10px;
            }
            .signature-name {
                margin-top: 6px;
                padding-top: 6px;
                border-top: 1px solid #ddd;
                width: 200px;
                margin-left: auto;
                margin-right: auto;
                color: #222;
                font-weight: 600;
            }
            .footer { 
                margin-top: 50px; 
                text-align: center; 
                font-size: 10px; 
                color: #666; 
            }
            .documentation-section {
                margin-top: 8px;
            }
            .photo-section {
                margin-top: 4px;
                text-align: center;
            }
            .photo-image {
                max-width: 300px;
                max-height: 200px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .photo-grid {
                text-align: center;
            }
            .photo-item {
                display: inline-block;
                vertical-align: top;
                width: 160px;
                text-align: center;
                margin: 4px;
                page-break-inside: avoid;
            }
            .photo-image {
                width: auto;
                height: auto;
                max-height: 145px;
                max-width: 160px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .no-image {
                color: #999;
                font-style: italic;
                padding: 20px;
                text-align: center;
                background: #f5f5f5;
                border-radius: 4px;
            }
            .info-box {
                background: #e8f4fd;
                border: 1px solid #b8daff;
                border-radius: 4px;
                padding: 6px 8px;
                margin: 6px 0;
            }
        </style>
    </head>
    <body>
        ' . $letterheadHTML . '
        
        <div class="info-box">
            <strong>Informasi Umum</strong>
        </div>
        
        <div class="section">
            <span class="label">Nomor Laporan:</span>
            <span class="content"><strong>#' . $report['id'] . '</strong></span>
        </div>
        
        <div class="section">
            <span class="label">Tanggal:</span>
            <span class="content">' . date('d/m/Y', strtotime($report['tanggal'])) . '</span>
        </div>
        
        <div class="section">
            <span class="label">Waktu:</span>
            <span class="content">' . $report['waktu'] . '</span>
        </div>
        
        <div class="section">
            <span class="label">Staff IT:</span>
            <span class="content">' . $report['staff_names'] . '</span>
        </div>
        
        <div class="info-box">
            <strong>Informasi Pengguna</strong>
        </div>
        
        <div class="section">
            <span class="label">Nama Pengguna:</span>
            <span class="content">' . htmlspecialchars($report['nama_pengguna']) . '</span>
        </div>
        
        <div class="section">
            <span class="label">Fakultas/Unit:</span>
            <span class="content">' . htmlspecialchars($report['unit_lembaga']) . '</span>
        </div>
        
        <div class="section">
            <span class="label">Keluhan:</span>
            <div class="content">
                <div class="text-content">' . nl2br(htmlspecialchars($report['keluhan'])) . '</div>
            </div>
        </div>
        
        <div class="section">
            <span class="label">Tindakan:</span>
            <div class="content">
                <div class="text-content">' . nl2br(htmlspecialchars($report['tindakan'])) . '</div>
            </div>
        </div>
        
        <div class="section">
            <span class="label">Hasil:</span>
            <span class="content"><strong>' . $report['hasil'] . '</strong></span>
        </div>';
        
    if (!empty($report['catatan'])) {
        $html .= '
        <div class="section">
            <span class="label">Catatan:</span>
            <div class="content">
                <div class="text-content">' . nl2br(htmlspecialchars($report['catatan'])) . '</div>
            </div>
        </div>';
    }
    
    $html .= "\n        <div class=\"documentation-section\">\n            <div class=\"info-box\">\n                <strong>Dokumentasi Foto</strong>\n            </div>";
    if (!empty($photoDokumentasiList)) {
        $html .= "\n        <div class=\"photo-section\">\n            <div class=\"photo-grid\">";
        foreach ($photoDokumentasiList as $pd) {
            $html .= "\n                <div class=\"photo-item\">\n                    <img src=\"" . $pd . "\" class=\"photo-image\" alt=\"Dokumentasi Maintenance\">\n                </div>";
        }
        $html .= "\n            </div>\n        </div>";
    } else {
        $html .= "\n        <div class=\"photo-section\" style=\"text-align:center;\">\n            <div class=\"no-image\">Tidak ada foto dokumentasi</div>\n        </div>";
    }
    $html .= "\n        </div>";
    
    $staff_tanda_tangan = !empty($report['staff_pemilik_tanda_tangan']) 
        ? $report['staff_pemilik_tanda_tangan'] 
        : ($_SESSION['nama'] ?? $report['staff_names']);
    
    $html .= '
        <div class="signature-section" style="text-align:center;">
            <div class="signature-box">
                <div style="margin-bottom:10px;"><strong>Tanda Tangan IT</strong></div>';
                
if ($signatureIT) {
    $html .= '
                <img src="' . $signatureIT . '" class="signature-mini" alt="Tanda Tangan IT">';
} else {
    $html .= '
                <div class="no-image-box">Tidak ada tanda tangan</div>';
}

$html .= '
                <div class="signature-line"></div>
                <div class="signature-name">' . htmlspecialchars($staff_tanda_tangan) . '</div>
                <div style="font-size: 10px; color: #666;"></div>
            </div>
            
            <div class="signature-box">
                <div style="margin-bottom:10px;"><strong>Tanda Tangan Pengguna</strong></div>';
                
    if ($signaturePengguna) {
        $html .= '
                <img src="' . $signaturePengguna . '" class="signature-mini" alt="Tanda Tangan Pengguna">';
    } else {
        $html .= '
                <div class="no-image-box">Tidak ada tanda tangan user</div>';
    }
    
    $html .= '
                <div class="signature-line"></div>
                <div class="signature-name">' . htmlspecialchars($report['nama_pengguna']) . '</div>
            </div>
        </div>';

    $html .= "\n        </body>\n    </html>";

    return $html;
}

function generateBulkPDFContent($reports) {
    global $imageCache;
    
    $totalReports = count($reports);
    
    // Generate logo once
    $logoPath = '../uploads/images/unisba.png';
    $logoBase64 = imageToBase64($logoPath, $imageCache);
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page {
                margin: 1.5cm;
            }
            
            body { 
                font-family: DejaVu Sans, Arial, sans-serif; 
                margin: 0;
                padding: 0;
                line-height: 1.3;
                font-size: 11px;
                color: #333;
                width: 100%;
            }
            
            .report-container {
                width: 100%;
                min-height: 26.7cm;
                page-break-after: always;
                margin: 0;
                padding: 0;
            }
            
            .report-container:last-child {
                page-break-after: avoid;
            }
            
            .content-wrapper {
                width: 100%;
                height: 100%;
                padding: 0;
            }
            
            .letterhead {
                margin-bottom: 15px;
            }
            
            .letterhead table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .letterhead-logo-cell {
                width: 30%;
                vertical-align: middle;
                padding-right: 15px;
            }
            
            .letterhead-address-cell {
                width: 70%;
                vertical-align: middle;
            }
            
            .letterhead-logo {
                max-height: 80px;
                width: auto;
                display: block;
            }
            
            .letterhead-address {
                text-align: right;
            }
            
            .letterhead-address div {
                font-size: 11px;
                color: #555;
                line-height: 1.4;
            }
            
            .letterhead-line {
                border-top: 3px solid #000;
                margin-top: 10px;
                margin-bottom: 2px;
            }
            
            .letterhead-line-thin {
                border-top: 1px solid #000;
                margin-bottom: 15px;
            }
            
            .info-box {
                background: #e8f4fd;
                border: 1px solid #b8daff;
                border-radius: 4px;
                padding: 8px;
                margin: 8px 0;
                font-weight: bold;
            }
            
            .section { 
                margin-bottom: 10px; 
            }
            
            .label { 
                font-weight: bold; 
                width: 120px; 
                display: inline-block; 
                vertical-align: top;
            }
            
            .content { 
                display: inline-block; 
                width: calc(100% - 125px);
                vertical-align: top;
            }
            
            .text-content {
                margin: 0;
                padding: 0;
                background: transparent;
                border: 0;
                font-size: 10px;
                line-height: 1.3;
            }
            
            .signature-section {
                margin-top: 20px;
                display: table;
                width: 100%;
                table-layout: fixed;
            }
            
            .signature-box {
                display: table-cell;
                width: 50%;
                text-align: center;
                vertical-align: top;
                padding: 6px;
            }
            
            .signature-mini {
                width: 120px;
                height: 50px;
                object-fit: contain;
                border: 1px solid #ddd;
                background: white;
                display: block;
                margin: 0 auto 8px auto;
            }
            
            .no-image-box {
                width: 120px;
                height: 50px;
                border: 1px dashed #999;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 9px;
                color: #999;
                background: #f9f9f9;
                margin: 0 auto 8px auto;
            }
            
            .signature-name {
                margin-top: 5px;
                padding-top: 5px;
                border-top: 1px solid #ddd;
                width: 140px;
                margin-left: auto;
                margin-right: auto;
                color: #222;
                font-weight: 600;
                font-size: 10px;
            }
            
            .documentation-section {
                margin-top: 10px;
                page-break-inside: avoid;
            }

            .photo-section {
                margin-top: 8px;
            }
            
            .photo-grid {
                text-align: center;
                margin: 10px 0;
            }
            
            .photo-item {
                display: inline-block;
                vertical-align: top;
                width: 160px;
                text-align: center;
                margin: 5px;
            }
            
            .photo-image {
                width: auto;
                height: auto;
                max-width: 160px;
                max-height: 120px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            .no-image {
                color: #999;
                font-style: italic;
                padding: 15px;
                text-align: center;
                background: #f5f5f5;
                border-radius: 4px;
                margin: 10px 0;
            }
            
            .page-number {
                position: fixed;
                bottom: 1.0cm;
                right: 1.5cm;
                font-size: 9px;
                color: #666;
            }
            
            .clear {
                clear: both;
            }
        </style>
    </head>
    <body>';
    
    foreach ($reports as $index => $report) {
        $reportNumber = $index + 1;
        
        // Convert images untuk setiap report
        $signatureIT = '';
        $signaturePengguna = '';
        
        if (!empty($report['tanda_tangan_it'])) {
            $signaturePath = '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR . $report['tanda_tangan_it'];
            $signatureIT = imageToBase64($signaturePath, $imageCache);
        }
        
        if (!empty($report['tanda_tangan_pengguna'])) {
            $signaturePath = '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR . $report['tanda_tangan_pengguna'];
            $signaturePengguna = imageToBase64($signaturePath, $imageCache);
        }
        
        $photoDokumentasiList = [];
        if (!empty($report['lampiran_foto'])) {
            $decodedPhotos = json_decode($report['lampiran_foto'], true);
            if (is_array($decodedPhotos)) $photoNames = $decodedPhotos;
            else $photoNames = [$report['lampiran_foto']];

            foreach ($photoNames as $pn) {
                $pn = trim($pn);
                if ($pn === '') continue;
                $photoPath = '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . $pn;
                $b64 = imageToBase64($photoPath, $imageCache);
                if ($b64) {
                    $photoDokumentasiList[] = $b64;
                    // Limit to 2 photos per report in bulk mode
                    if (count($photoDokumentasiList) >= 2) break;
                }
            }
        }
        
        $html .= '
        <div class="report-container">
            <div class="content-wrapper">
                <div class="letterhead">
                    <table>
                        <tr>
                            <td class="letterhead-logo-cell">';
        
        if ($logoBase64) {
            $html .= '
                                <img src="' . $logoBase64 . '" class="letterhead-logo" alt="Logo">';
        } else {
            $html .= '
                                <div style="height: 80px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #666;">
                                    LOGO
                                </div>';
        }
        
        $html .= '
                            </td>
                            <td class="letterhead-address-cell">
                                <div class="letterhead-address">
                                    <div>
                                        <strong style="font-size: 12px; color: #000;">Universitas Islam Bandung</strong><br>
                                        Jl. Tamansari No.24, Bandung 40116<br>
                                        Telp: (022) 4203368<br>
                                        Email: network.psitek@unisba.ac.id<br>
                                        www.unisba.ac.id
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>
                    <div class="letterhead-line"></div>
                    <div class="letterhead-line-thin"></div>
                </div>
                
                <div class="info-box">Informasi Umum</div>
                
                <div class="section">
                    <span class="label">Tanggal/Waktu:</span>
                    <span class="content">' . date('d/m/Y', strtotime($report['tanggal'])) . ' ' . $report['waktu'] . '</span>
                </div>
                
                <div class="section">
                    <span class="label">Staff IT:</span>
                    <span class="content">' . $report['staff_names'] . '</span>
                </div>
                
                <div class="info-box">Informasi Pengguna</div>
                
                <div class="section">
                    <span class="label">Pengguna:</span>
                    <span class="content">' . htmlspecialchars($report['nama_pengguna']) . '</span>
                </div>
                
                <div class="section">
                    <span class="label">Fakultas/Unit:</span>
                    <span class="content">' . htmlspecialchars($report['unit_lembaga']) . '</span>
                </div>
                
                <div class="section">
                    <span class="label">Hasil:</span>
                    <span class="content"><strong>' . $report['hasil'] . '</strong></span>
                </div>
                
                <div class="section">
                    <span class="label">Keluhan:</span>
                    <div class="content">
                        <div class="text-content">' . nl2br(htmlspecialchars($report['keluhan'])) . '</div>
                    </div>
                </div>
                
                <div class="section">
                    <span class="label">Tindakan:</span>
                    <div class="content">
                        <div class="text-content">' . nl2br(htmlspecialchars($report['tindakan'])) . '</div>
                    </div>
                </div>';
                
        if (!empty($report['catatan'])) {
            $html .= '
                <div class="section">
                    <span class="label">Catatan:</span>
                    <div class="content">
                        <div class="text-content">' . nl2br(htmlspecialchars($report['catatan'])) . '</div>
                    </div>
                </div>';
        }
        
        $html .= '
                <div class="documentation-section">
                    <div class="info-box">Dokumentasi Foto</div>';
        
        if (!empty($photoDokumentasiList)) {
            $html .= '
                <div class="photo-section">
                    <div class="photo-grid">';
            foreach ($photoDokumentasiList as $pd) {
                $html .= '
                        <div class="photo-item">
                            <img src="' . $pd . '" class="photo-image" alt="Dokumentasi Maintenance">
                        </div>';
            }
            $html .= '
                    </div>
                </div>';
        } else {
            $html .= '
                <div class="no-image">Tidak ada foto dokumentasi</div>';
        }

        $html .= '
                </div>';
        
        $html .= '
                <div class="signature-section">
                    <div class="signature-box">
                        <div style="margin-bottom:5px;"><strong>Tanda Tangan IT</strong></div>';
        if ($signatureIT) {
            $html .= '
                        <img src="' . $signatureIT . '" class="signature-mini" alt="Tanda Tangan IT">';
        } else {
            $html .= '
                        <div class="no-image-box">Tidak ada tanda tangan IT</div>';
        }
        $html .= '
                        <div class="signature-name">' . htmlspecialchars($report['staff_pemilik_tanda_tangan'] ?? ($_SESSION['nama'] ?? $report['staff_names'])) . '</div>
                    </div>
                    <div class="signature-box">
                        <div style="margin-bottom:5px;"><strong>Tanda Tangan User</strong></div>';
        if ($signaturePengguna) {
            $html .= '
                        <img src="' . $signaturePengguna . '" class="signature-mini" alt="Tanda Tangan Pengguna">';
        } else {
            $html .= '
                        <div class="no-image-box">Tidak ada tanda tangan user</div>';
        }
        $html .= '
                        <div class="signature-name">' . htmlspecialchars($report['nama_pengguna']) . '</div>
                    </div>
                </div>
                
                <div class="page-number">Halaman ' . $reportNumber . ' dari ' . $totalReports . '</div>
                <div class="clear"></div>
            </div>
        </div>';
        
        // Free report data after processing
        unset($report);
    }
    
    $html .= '</body></html>';
    
    return $html;
}

// If no valid export request, redirect
header('Location: laporan.php');
exit;
