<?php
session_start();
include '../config/roles.php';

// Check if user can access admin
if (!isset($_SESSION['user_id']) || !canAccessAdmin($_SESSION['jabatan'])) {
    header('Location: ../login.php');
    exit;
}

// Additional permission checks for specific admin pages
$current_page = basename($_SERVER['PHP_SELF']);

// Untuk kelola_akun.php - hanya yang bisa manage staff
if ($current_page == 'kelola_akun.php' && !canManageStaff($_SESSION['jabatan'])) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// Untuk laporan.php, edit_laporan.php, view_laporan.php, export_pdf.php, download_photo.php - semua yang bisa manage reports boleh akses
$report_pages = ['laporan.php', 'edit_laporan.php', 'view_laporan.php', 'export_pdf.php', 'download_photo.php'];
if (in_array($current_page, $report_pages) && !canManageReports($_SESSION['jabatan'])) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}
?>
