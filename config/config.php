<?php
// ======================================================================
// [HOSTING-SAFE] FORCE UTF-8 GLOBAL (Fix Mojibake Indo Chars / Judul Grafik)
// Hindari multi-byte / emoji di baris TERATAS file agar parser PHP strict
// di hosting shared tidak mendeteksi BOM / encoding salah (cp1252 -> mojibake).
// ======================================================================
if (!headers_sent()) {
    @ini_set('default_charset', 'UTF-8');
    if (function_exists('mb_internal_encoding')) { @mb_internal_encoding('UTF-8'); }
    if (function_exists('mb_regex_encoding'))  { @mb_regex_encoding('UTF-8'); }
    header('Content-Type: text/html; charset=utf-8');
}

// ======================================================================
// [HOSTING-SAFE] GLOBAL ERROR & EXCEPTION HANDLER
// Hosting default display_errors = 0 menyebabkan BLANK PAGE (putih kosong).
// Handler ini menampilkan pesan ERROR JELAS dalam Bahasa Indonesia
// agar user tahu penyebabnya tanpa buka cPanel -> Error Logs.
// ======================================================================
$_errHtmlStyle = 'background:#f8fafc;min-height:100vh;padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;';
$_errCardStyle = 'max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px 24px;box-shadow:0 12px 32px -8px rgba(15,23,42,.12);';
$_errTitle = 'background:#0f172a;color:#fff;font-weight:700;padding:12px 18px;border-radius:12px;margin:0 0 20px;font-size:15px;display:flex;align-items:center;gap:10px;';
$_errBtn  = 'display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:10px;text-decoration:none;font-weight:600;font-size:13px;margin-top:14px;';
$_errPre  = 'background:#0f172a;color:#e2e8f0;padding:14px;border-radius:10px;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.7;margin:14px 0;';

if (!function_exists('_configRenderFatal')) {
    function _configRenderFatal($title, $desc, $suggest = '', $rawErr = null) {
        global $_errHtmlStyle, $_errCardStyle, $_errTitle, $_errBtn, $_errPre;
        $sapi  = PHP_SAPI;
        $ver   = PHP_VERSION;
        $host  = $_SERVER['HTTP_HOST'] ?? '(cli)';
        $proto = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $host;
        if ($sapi !== 'cli' && !headers_sent()) {
            @http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        if ($sapi === 'cli') {
            echo "\n=== [ERROR KRITIS config.php] ===\n";
            echo "Title : $title\n";
            echo "Desc  : $desc\n";
            if ($suggest) echo "Saran : $suggest\n";
            if ($rawErr) echo "Detail: $rawErr\n";
            echo "PHP   : $ver | Host : $host\n";
            echo "=================================\n\n";
        } else {
            $safeDesc = htmlspecialchars((string)$desc, ENT_QUOTES, 'UTF-8');
            $safeSug  = htmlspecialchars((string)$suggest, ENT_QUOTES, 'UTF-8');
            $rawBlock  = '';
            if ($rawErr !== null) {
                $r = htmlspecialchars(trim((string)$rawErr), ENT_QUOTES, 'UTF-8');
                $rawBlock = "<pre style=\"$_errPre\">$r</pre>";
            }
            echo "<!doctype html><html lang=\"id\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Error - Engineering Report</title></head><body style=\"$_errHtmlStyle\"><div style=\"$_errCardStyle\">";
            echo "<div style=\"$_errTitle\">&#9888;&#65039;  $title</div>";
            echo "<h2 style=\"font-size:18px;margin:6px 0 12px;color:#0f172a;font-weight:700;\">Website belum bisa diakses — ada konfigurasi yang belum benar</h2>";
            echo "<p style=\"font-size:14px;line-height:1.8;color:#334155;margin:0 0 10px;\">$safeDesc</p>";
            if ($safeSug) { echo "<div style=\"background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:14px 16px;border-radius:12px;font-size:13.5px;line-height:1.8;\"><b style=\"display:block;margin-bottom:6px;\">&#128736;&#65039;  LANGKAH PERBAIKAN (cek di cPanel Hosting):</b>$safeSug</div>"; }
            echo $rawBlock;
            echo "<div style=\"display:flex;justify-content:space-between;align-items:center;margin-top:18px;border-top:1px solid #e2e8f0;padding-top:14px;flex-wrap:wrap;gap:10px;\">";
            echo "<div style=\"font-size:12px;color:#64748b;\">PHP v$ver | Running via $sapi | <a href=\"$proto\" style=\"color:#2563eb;text-decoration:none;\">Kembali ke Beranda</a></div>";
            echo "<a href=\"$proto\" style=\"$_errBtn\">&larr; Coba Lagi Akses Website</a>";
            echo "</div></div></body></html>";
        }
        exit(1);
    }
}

set_error_handler(function ($severity, $message, $file, $line) use (&$_errPre, &$_errHtmlStyle, &$_errCardStyle, &$_errTitle) {
    if (!(error_reporting() & $severity)) return false;
    $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    $isFatal = in_array($severity, $fatals, true);
    if (!$isFatal) return false;
    _configRenderFatal(
        'PHP Fatal Error (Runtime)',
        "Terjadi error serius pada file <b>" . basename($file) . "</b> di baris ke-$line, sehingga engine PHP tidak bisa melanjutkan eksekusi.",
        "1) Cek kembali sintaks file PHP tersebut. <br>2) Pastikan versi PHP hosting minimal <b>PHP 7.4+</b> (disarankan 8.1 / 8.2). <br>3) Jika error call to undefined function/class, pastikan file include sudah ter-upload lengkap.",
        "[$severity] $message\nFile : $file (Line $line)"
    );
    return true;
});

set_exception_handler(function (Throwable $e) use (&$_errPre, &$_errHtmlStyle, &$_errCardStyle, &$_errTitle) {
    _configRenderFatal(
        'Uncaught Exception',
        "Ada exception yang tidak tertangani (unhandled exception). Class: <b>" . get_class($e) . "</b>. Kemungkinan besar ini adalah error logic aplikasi.",
        "1) Paste error detail di bawah ke dev / tim support. <br>2) Jika error terkait SQL / DB, cek terlebih dahulu koneksi database di point selanjutnya.",
        $e->getMessage() . "\nFile : " . $e->getFile() . " (Line " . $e->getLine() . ")\n\nStack trace:\n" . $e->getTraceAsString()
    );
});

register_shutdown_function(function () use (&$_errPre, &$_errHtmlStyle, &$_errCardStyle, &$_errTitle) {
    $err = error_get_last();
    if ($err === null) return;
    $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($err['type'], $fatals, true)) return;
    _configRenderFatal(
        'PHP Shutdown Fatal Error',
        "PHP berhenti mendadak (shutdown) karena error fatal. Paling sering disebabkan: memory limit habis, atau require/include file tidak ada.",
        "1) Cek file upload: pastikan SEMUA file PHP ter-upload (tidak ada yang corrupt/terpotong upload). <br>2) Cek PHP Memory Limit di cPanel -> MultiPHP INI, minimal 128 MB. <br>3) Cek ulang folder permissions (chmod 755 folder, 644 file).",
        "[Fatal Type $err[type]] $err[message]\nFile : $err[file] (Line $err[line])"
    );
});

// ======================================================================
// DATABASE CREDENTIALS — Hosting-Safe pattern (LOCALHOST vs HOSTING)
// PETUNJUK JIKA PAKE CPANEL HOSTING SHARED:
//   [1] Buat DULU Database di cPanel -> MySQL Database Wizard
//   [2] Buat DULU User MySQL di cPanel -> tambahkan user ke database
//   [3] Beri HAK AKSES (ALL PRIVILEGES / GRANT) user ke database
//   [4] Nama DB / User di cPanel SELALU berawalan cPanel username-mu, misal:
//       cPanel user = vanisanet  ->  DB = vanisanet_registen_admin, user = vanisanet_registen_admin
// ======================================================================
$_cpDBHost   = 'localhost';   // GANTI jika hostingmu pake mysql remote, misal 'mysql37.niagahoster.co.id'
$_cpDBName   = 'regist_bali_dailylog';  // LOKAL
$_cpDBUser   = 'root';        // LOKAL
$_cpDBPass   = '';            // LOKAL (kosong di Laragon/XAMPP)

// -- Otomatis detect HOSTING (bukan localhost) -> TIDAK PAKAI credential LOKAL --
$_httpH = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$_isHosting = !in_array($_httpH, ['localhost', '127.0.0.1', '::1'], true)
              && substr($_httpH, 0, 8) !== '192.168.'
              && substr($_httpH, 0, 3) !== '10.';

if ($_isHosting) {
    // ======================================================================
    // >>> [WAJIB DIISI KHUSUS HOSTING] <<<
    // Ganti dengan credential DB dari cPanel. JANGAN pakai root / pass kosong!
    // ======================================================================
    $_cpDBHost = 'localhost';       // Biasanya tetap localhost (kecuali Hostinger/SiteGround -> ganti)
    $_cpDBName = 'registen_admin';  // CONTOH: biasanya PREFIX_cpaneluser_NAMADB
    $_cpDBUser = 'registen_admin';  // CONTOH: biasanya PREFIX_cpaneluser_NAMAUSER
    $_cpDBPass = 'adminjoki123';    // CONTOH: diisi PASSWORD user MySQL yang DIBUAT di cPanel
    // ======================================================================
}

define('DB_HOST', $_cpDBHost);
define('DB_NAME', $_cpDBName);
define('DB_USER', $_cpDBUser);
define('DB_PASS', $_cpDBPass);

$httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$isLocalhost = in_array($httpHost, ['localhost', '127.0.0.1', '::1'], true)
    || (substr($httpHost, 0, 8) === '192.168.')
    || (substr($httpHost, 0, 3) === '10.');

$projectRootPath = str_replace('\\', '/', (string)realpath(__DIR__ . '/../'));
$docRootPath = str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2))));
if ($docRootPath && substr($projectRootPath, 0, strlen($docRootPath)) === $docRootPath) {
    $relativeFromDocRoot = trim(substr($projectRootPath, strlen($docRootPath)), '/');
    $autoBaseUrl = $relativeFromDocRoot ? '/' . $relativeFromDocRoot . '/' : '/';
} else {
    $absConfigPath = str_replace('\\', '/', __FILE__);
    $absDocRootRaw = str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($absDocRootRaw && substr($absConfigPath, 0, strlen($absDocRootRaw)) === $absDocRootRaw) {
        $suffixRelToRoot = substr($absConfigPath, strlen($absDocRootRaw));
        $removeTrail = '/config/config.php';
        $baseRelPath = (substr($suffixRelToRoot, -strlen($removeTrail)) === $removeTrail)
            ? substr($suffixRelToRoot, 0, -strlen($removeTrail))
            : rtrim(dirname(dirname($suffixRelToRoot)), '/');
        $autoBaseUrl = $baseRelPath ? $baseRelPath . '/' : '/';
    } else {
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
        if (preg_match('#^(/[^/]+/[^/]+)(?:/|$)#', $scriptName, $m)) {
            $autoBaseUrl = $m[1] . '/';
        } else {
            $autoBaseUrl = '/';
        }
    }
}

if ($isLocalhost) {
    define('ENVIRONMENT', 'DEVELOPMENT');
} else {
    define('ENVIRONMENT', 'PRODUCTION');
}
if (!defined('BASE_URL')) {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    // Domain utama & subdomain umum (mencegah BASE_URL salah / assets 404 di hosting)
    $rootDomains = [
        'engineeringdept.my.id', 'www.engineeringdept.my.id',
        'registengengineering.com', 'www.registengengineering.com',
        'bali.engineeringdept.my.id', 'bali.registengengineering.com',
        'regist.engineeringdept.my.id', 'eng.registengengineering.com',
    ];
    if (in_array($host, $rootDomains, true)) {
        define('BASE_URL', '/');
    } elseif (stripos($scriptName, '/AGUSTUS/regist_bali/') !== false) {
        define('BASE_URL', '/AGUSTUS/regist_bali/');
    } elseif (stripos($scriptName, '/regist_bali/') !== false) {
        define('BASE_URL', '/regist_bali/');
    } else {
        define('BASE_URL', $autoBaseUrl);
    }
}
$cookieBasePath = rtrim(BASE_URL, '/') ?: '/';

define('UPLOAD_PATH', __DIR__ . '/../assets/uploads/');
define('UPLOAD_URL', BASE_URL . 'assets/uploads/');
// [HOSTING-SAFE] Hapus @chmod! Banyak hosting shared MELARANG chmod via PHP (blank putih / 500).
// Hanya mencoba mkdir jika folder belum ada, pakai try/catch agar tidak fatal.
try {
    if (!is_dir(UPLOAD_PATH)) {
        if (!@mkdir(UPLOAD_PATH, 0755, true)) {
            @mkdir(UPLOAD_PATH, 0777, true);
        }
    }
} catch (Throwable $e) { /* hosting open_basedir: biarkan, user upload error tapi page tetap jalan */ }

$storageLogDir = __DIR__ . '/../storage/logs/';
try {
    if (!is_dir($storageLogDir)) {
        if (!@mkdir($storageLogDir, 0755, true)) {
            @mkdir($storageLogDir, 0777, true);
        }
    }
} catch (Throwable $e) { /* biarkan diam */ }
$sessionDir = __DIR__ . '/../storage/sessions/';
try {
    if (!is_dir($sessionDir)) {
        if (!@mkdir($sessionDir, 0755, true)) {
            @mkdir($sessionDir, 0777, true);
        }
    }
    // Hosting kadang session save_path /tmp penuh atau readonly. Pindah ke storage/sessions lokal.
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        @session_save_path($sessionDir);
    }
} catch (Throwable $e) { /* biarkan default /tmp */ }

if (ENVIRONMENT === 'DEVELOPMENT') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '0');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    // Pakai $storageLogDir yang SUDAH dibuat & di-chmod friendly di atas (HAPUS @chmod!)
    if (!is_dir($storageLogDir)) {
        try { @mkdir($storageLogDir, 0755, true); } catch (Throwable $e) { /* diam */ }
    }
    $logFile = $storageLogDir . 'php_error_' . date('Y-m') . '.log';
    @ini_set('error_log', $logFile);
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
    session_start([
        'cookie_httponly'    => true,
        'cookie_samesite'    => 'Lax',
        'cookie_secure'      => $isHttps,
        'cookie_path'        => $cookieBasePath,
        'use_strict_mode'    => true,
        'use_trans_sid'      => false,
        'cache_limiter'      => 'nocache',
        'gc_maxlifetime'     => 86400,
    ]);
} else {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_path', $cookieBasePath);
    if ($isHttps) { ini_set('session.cookie_secure', '1'); }
    session_cache_limiter('nocache');
    session_start();
}
if (!isset($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['_ip'])) {
    $_SESSION['_ip'] = ($_SERVER['REMOTE_ADDR'] ?? php_uname('n'));
    $_SESSION['_ua'] = $_SERVER['HTTP_USER_AGENT'] ?? 'cli';
}

// ======================================================================
// [HOSTING-SAFE] INCLUDE FILE WAJIB + TEST KONEKSI DATABASE AWAL
// Cek DULU file_exists sebelum require, JIKA TIDAK ADA -> pesan JELAS
// (bukan blank putih). Lakukan TEST PING koneksi MySQL pertama agar
// error DB (password salah / user tidak punya grant) ketahuan di awal.
// ======================================================================
$_filePdo       = __DIR__ . '/pdo.php';
$_fileFunctions = __DIR__ . '/functions.php';

if (!file_exists($_filePdo)) {
    _configRenderFatal(
        'File config/pdo.php TIDAK DITEMUKAN',
        "File pendukung untuk koneksi database (pdo.php) tidak ada di folder <b>config/</b>. Ini artinya upload file ke hosting <b>BELUM LENGKAP</b> atau ada file yang terpotong.",
        "1) Upload ULANG file <b>config/pdo.php</b> dari folder project lokal ke hosting di path yang sama (folder config). <br>2) Cek CHMOD file: 644, folder: 755. <br>3) Pastikan tidak ada typo nama file (case-sensitive di hosting Linux!).",
        "Path yang dicari: $_filePdo | File exists? " . (file_exists($_filePdo) ? 'YA' : 'TIDAK')
    );
}
if (!file_exists($_fileFunctions)) {
    _configRenderFatal(
        'File config/functions.php TIDAK DITEMUKAN',
        "File helper fungsi-fungsi pendukung (functions.php) tidak ada di folder <b>config/</b>. Sama seperti pdo.php: upload BELUM LENGKAP.",
        "1) Upload ULANG file <b>config/functions.php</b> dari folder project lokal. <br>2) Cek juga file lain yang mungkin hilang (includes/header.php, includes/sidebar.php, dll).",
        "Path yang dicari: $_fileFunctions | File exists? " . (file_exists($_fileFunctions) ? 'YA' : 'TIDAK')
    );
}

require_once $_filePdo;
require_once $_fileFunctions;

// ======================================================================
// [HOSTING-SAFE] TEST KONEKSI DATABASE PERTAMA KALI
// Ini pengecekan PALING PENTING untuk hosting cPanel. Jika user salah
// mengisi nama DB / user DB / password DB / belum GRANT PRIVILEGES,
// user AKAN LANGSUNG DIBERI PETUNJUK LANGKAH perbaikan DI BAWAH INI,
// BUKAN cuma BLANK PAGE PUTIH (yang bikin pusing).
// ======================================================================
try {
    $_db = Database::getInstance();
    // Ping PDO: test SELECT 1 — eksekusi sungguhan (bukan cuma connect)
    $_db->fetchOne("SELECT 1 AS ping_ok");
    unset($_db);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $errLow = strtolower($msg);
    $saranTail = '';
    $kode = '';
    if (strpos($errLow, 'access denied') !== false) {
        $kode = 'ACCESS_DENIED (User/Password DB SALAH atau BELUM DI-GRANT)';
        $saranTail = "<br>4) Pastikan user MySQL yang dibuat <b>SUDAH DITAMBAHKAN KE DATABASE</b> di cPanel -> MySQL Databases -> bagian Add User To Database, lalu centang <b>ALL PRIVILEGES -> MAKE CHANGES</b>.";
    } elseif (strpos($errLow, 'unknown database') !== false) {
        $kode = 'UNKNOWN DATABASE (Nama DATABASE belum dibuat)';
        $saranTail = "<br>4) Buat DULU Database di cPanel -> MySQL Database Wizard. Nama DB di hosting <b>SELALU DIAWALI username_cpanel_</b> (contoh: vanisan_registen_admin).";
    } elseif (strpos($errLow, "can't connect") !== false || strpos($errLow, 'connection refused') !== false || strpos($errLow, 'could not find driver') !== false) {
        $kode = 'DB_HOST TIDAK BISA DIKONEKSI atau EXT PDO MYSQL TIDAK AKTIF';
        $saranTail = "<br>4) Cek di cPanel -> Select PHP Version -> Extensions: pastikan <b>pdo_mysql</b> & <b>mysqli</b> DICENTANG (enable). <br>5) Jika DB Host bukan localhost (Hostinger/SiteGround), ganti nilai <b>\\$_cpDBHost</b> di config.php dengan host MySQL yang diberikan cPanel (lihat menu Remote MySQL / Database).";
    } elseif (strpos($errLow, 'no such file or directory') !== false || strpos($errLow, 'socket') !== false) {
        $kode = 'MySQL Socket Not Found (Service MySQL MUNGKIN TURU / tidak start)';
        $saranTail = "<br>4) Lapor ke support hosting bahwa service MySQL socket tidak aktif.";
    } else {
        $kode = 'SQLSTATE ERROR (cek detail di bawah)';
        $saranTail = "<br>4) Kirim error detail ini ke tim dev / support agar cepat dianalisis.";
    }
    _configRenderFatal(
        'Gagal Koneksi ke Database MySQL -> ' . $kode,
        "Aplikasi tidak bisa terhubung ke database. Ini <b>penyebab NOMOR 1</b> website blank putih di hosting baru. Nilai yang dipakai saat ini: <br><b>Host:</b> " . DB_HOST . " | <b>Database:</b> " . DB_NAME . " | <b>User:</b> " . DB_USER . " | <b>Password length:</b> " . strlen(DB_PASS) . " char.",
        "1) Login ke <b>cPanel Hosting</b>. <br>2) Buka menu <b>MySQL Databases</b> / <b>MySQL Database Wizard</b>. <br>3) Pastikan 3 hal ini: <b>(a)</b> Nama Database <b>SUDAH DIBUAT</b>, <b>(b)</b> User MySQL <b>SUDAH DIBUAT</b>, <b>(c)</b> User MySQL <b>SUDAH PUNYA HAK AKSES (GRANT ALL PRIVILEGES)</b> ke Database tersebut." . $saranTail,
        "Credential saat ini (config.php)\n  DB_HOST = " . DB_HOST . "\n  DB_NAME = " . DB_NAME . "\n  DB_USER = " . DB_USER . "\n  DB_PASS_LEN = " . strlen(DB_PASS) . "\n\nPDO Throwable message:\n$msg"
    );
}

// initLanguage() callable check — JANGAN fatal error cuma karena function hilang.
if (!defined('APP_LANG')) {
    if (function_exists('initLanguage')) {
        initLanguage();
    } else {
        define('APP_LANG', 'id');
    }
}

/* =============================================================
   GLOBAL TARIFF SETTINGS (Auto Migration DB + CRUD Helper)
   ============================================================= */
function _tariffAutoMigrate(Database $db): void {
    static $migrated = false;
    if ($migrated) return;
    try {
        $db->query("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by INT UNSIGNED NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $defaults = [
            'tariff_electricity_per_kwh'      => 1850,
            'tariff_electricity_wbp_per_kwh'  => 1850,
            'tariff_electricity_lwbp_per_kwh' => 1200,
            'tariff_water_per_m3'             => 9600,
            'tariff_gas_per_kg'               => 24500,
            'tariff_fuel_per_liter'           => 17450,
        ];
        foreach ($defaults as $k => $v) {
            $exist = $db->fetchOne("SELECT setting_key FROM settings WHERE setting_key = ?", [$k]);
            if (!$exist) {
                $db->insert('settings', ['setting_key' => $k, 'setting_value' => (string)$v]);
            }
        }
        $migrated = true;
    } catch (Throwable $e) { /* silent jika table sudah ada / lock */ }
}

function getTariffSettings(): array {
    $db = Database::getInstance();
    _tariffAutoMigrate($db);
    try {
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'tariff_%'");
        $out = [];
        foreach ($rows as $r) $out[$r['setting_key']] = (int)$r['setting_value'];
        return [
            'electricity_per_kwh'      => (int)($out['tariff_electricity_per_kwh']      ?? 1850),
            'electricity_wbp_per_kwh'  => (int)($out['tariff_electricity_wbp_per_kwh']  ?? 1850),
            'electricity_lwbp_per_kwh' => (int)($out['tariff_electricity_lwbp_per_kwh'] ?? 1200),
            'water_per_m3'             => (int)($out['tariff_water_per_m3']             ?? 9600),
            'gas_per_kg'               => (int)($out['tariff_gas_per_kg']               ?? 24500),
            'fuel_per_liter'           => (int)($out['tariff_fuel_per_liter']           ?? 17450),
        ];
    } catch (Throwable $e) {
        return [
            'electricity_per_kwh'      => 1850,
            'electricity_wbp_per_kwh'  => 1850,
            'electricity_lwbp_per_kwh' => 1200,
            'water_per_m3'             => 9600,
            'gas_per_kg'               => 24500,
            'fuel_per_liter'           => 17450,
        ];
    }
}

function saveTariffSettings(array $vals, int $userId = 0): void {
    $db = Database::getInstance();
    _tariffAutoMigrate($db);
    $keys = [
        'electricity_per_kwh'      => 'tariff_electricity_per_kwh',
        'electricity_wbp_per_kwh'  => 'tariff_electricity_wbp_per_kwh',
        'electricity_lwbp_per_kwh' => 'tariff_electricity_lwbp_per_kwh',
        'water_per_m3'             => 'tariff_water_per_m3',
        'gas_per_kg'               => 'tariff_gas_per_kg',
        'fuel_per_liter'           => 'tariff_fuel_per_liter',
    ];
    foreach ($keys as $inKey => $dbKey) {
        $num = (int)($vals[$inKey] ?? 0);
        if ($num < 0) $num = 0;
        $exist = $db->fetchOne("SELECT setting_key FROM settings WHERE setting_key = ?", [$dbKey]);
        if ($exist) {
            $db->update('settings',
                ['setting_value' => (string)$num, 'updated_by' => $userId ?: null],
                'setting_key = :k',
                [':k' => $dbKey]
            );
        } else {
            $db->insert('settings', [
                'setting_key'   => $dbKey,
                'setting_value' => (string)$num,
                'updated_by'    => $userId ?: null,
            ]);
        }
    }
}
