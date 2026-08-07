<?php
/**
 * Konfigurasi backoffice — TERPISAH TOTAL dari config CMS/website Svashta
 * (shared/config.php). Database beda, subdomain beda (backoffice.svashtahome.com).
 *
 * SETUP: salin file ini jadi "config.php" (folder yang sama), isi kredensial
 * asli dari cPanel > MySQL Databases. "config.php" (bukan .example) sengaja
 * TIDAK di-commit — isinya kredensial asli, treat seperti password.
 */

define('DEBUG_MODE', false);
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// Dev override: kalau file config.local.php ada DAN kita jalan di localhost (php -S buat
// testing lokal), pakai kredensial lokal itu — lihat config.local.example.php.
$isLocalDev = php_sapi_name() === 'cli-server' || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost');
if ($isLocalDev && file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'GANTI_DENGAN_NAMA_DB');
    define('DB_USER', 'GANTI_DENGAN_USER_DB');
    define('DB_PASS', 'GANTI_DENGAN_PASSWORD_DB');

    define('APP_URL', 'https://backoffice.svashtahome.com');
}
