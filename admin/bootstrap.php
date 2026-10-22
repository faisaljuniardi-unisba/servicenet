<?php
/**
 * Bootstrap bersama untuk seluruh halaman admin.
 * Menyatukan inisialisasi sesi, otorisasi, dan koneksi database.
 */
require_once __DIR__ . '/check_admin.php';
require_once __DIR__ . '/../config/database.php';
