<?php
// AKTIFKAN ERROR REPORTING UNTUK DEBUG
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/bootstrap.php';

// Check if user can manage reports
if (!isset($_SESSION['user_id']) || !canManageReports($_SESSION['jabatan'])) {
    error_log("ACCESS DENIED to download_photo.php - User: " . ($_SESSION['nama'] ?? 'Unknown') . ", Jabatan: " . ($_SESSION['jabatan'] ?? 'Unknown'));
    header('Location: laporan.php?error=unauthorized');
    exit;
}

// DEBUG: Tampilkan informasi request
error_log("Download Photo Request: ID=" . ($_GET['id'] ?? 'NULL') . ", User: " . ($_SESSION['nama'] ?? 'Unknown') . ", Jabatan: " . ($_SESSION['jabatan'] ?? 'Unknown'));

$id = $_GET['id'] ?? 0;

// Get photo filename from database
$stmt = $pdo->prepare("SELECT lampiran_foto FROM maintenance WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

// DEBUG: Tampilkan data dari database
error_log("Database Result: " . print_r($report, true));

if (!$report || empty($report['lampiran_foto'])) {
    error_log("Photo not found in database for ID: $id");
    header('Location: laporan.php?error=photonotfound');
    exit;
}

$file_path = "../uploads/photos/" . $report['lampiran_foto'];
$file_path_absolute = realpath($file_path);

// DEBUG: Tampilkan path file
error_log("File Path: $file_path");
error_log("Absolute Path: $file_path_absolute");
error_log("File Exists: " . (file_exists($file_path) ? 'YES' : 'NO'));

// Support both single filename (legacy) and JSON array of filenames
$photos = [];
 $decoded = json_decode($report['lampiran_foto'], true);
 if (is_array($decoded)) {
     $photos = $decoded;
 } else {
     $photos = [$report['lampiran_foto']];
 }

 // If 'all' parameter is set, create ZIP of all existing photos
 $download_all = isset($_GET['all']) && ($_GET['all'] == '1' || $_GET['all'] == 'true');

 $existing_files = [];
 foreach ($photos as $p) {
     $p = trim(basename($p));
     if ($p === '') continue;
     $path = "../uploads/photos/" . $p;
     if (file_exists($path)) {
         $existing_files[] = [$path, $p];
     } else {
         error_log("Missing photo skipped: " . $path);
     }
 }

 if (empty($existing_files)) {
     error_log("No existing photos to download for ID: $id");
     header('Location: laporan.php?error=filenotfound');
     exit;
 }

 if ($download_all && count($existing_files) > 1) {
     if (!class_exists('ZipArchive')) {
         error_log('ZipArchive not available on this server');
         header('Location: laporan.php?error=nozip');
         exit;
     }

     $tmpfile = tempnam(sys_get_temp_dir(), 'photos_zip_');
     $zip = new ZipArchive();
     if ($zip->open($tmpfile, ZipArchive::OVERWRITE) !== true) {
         error_log('Failed to create zip file');
         header('Location: laporan.php?error=zipfail');
         exit;
     }

     foreach ($existing_files as $ef) {
         $zip->addFile($ef[0], $ef[1]);
     }
     $zip->close();

     // Stream zip to client
     $zip_name = 'dokumentasi_maintenance_' . $id . '.zip';
     header('Content-Type: application/zip');
     header('Content-Disposition: attachment; filename="' . $zip_name . '"');
     header('Content-Length: ' . filesize($tmpfile));

     // Clear output buffers
     while (ob_get_level()) ob_end_clean();
     readfile($tmpfile);
     @unlink($tmpfile);
     exit;
 }

 // Otherwise, serve the first existing file (legacy behavior) or file specified by 'file' param
 $file_requested = $_GET['file'] ?? null;
 if ($file_requested) {
     $file_requested = basename($file_requested);
     $found = null;
     foreach ($existing_files as $ef) {
         if ($ef[1] === $file_requested) { $found = $ef; break; }
     }
     if ($found) {
         $file_path = $found[0];
         $file_basename = $found[1];
     } else {
         header('Location: laporan.php?error=filenotfound');
         exit;
     }
 } else {
     // serve first
     $file_path = $existing_files[0][0];
     $file_basename = $existing_files[0][1];
 }

 $file_path_absolute = realpath($file_path);
 if (!file_exists($file_path)) {
     error_log("File not found: $file_path");
     header('Location: laporan.php?error=filenotfound');
     exit;
 }

 $file_name = 'dokumentasi_maintenance_' . $id . '_' . $file_basename;
 $file_size = filesize($file_path);
 $file_extension = strtolower(pathinfo($file_basename, PATHINFO_EXTENSION));

 $content_types = [
     'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp', 'webp' => 'image/webp'
 ];
 $content_type = $content_types[$file_extension] ?? 'application/octet-stream';

 header('Content-Description: File Transfer');
 header('Content-Type: ' . $content_type);
 header('Content-Disposition: attachment; filename="' . $file_name . '"');
 header('Content-Transfer-Encoding: binary');
 header('Expires: 0');
 header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
 header('Pragma: public');
 header('Content-Length: ' . $file_size);

 while (ob_get_level()) ob_end_clean();
 readfile($file_path);
 exit;
?>
