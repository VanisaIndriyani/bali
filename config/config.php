<?php
// 🔣 FORCE UTF-8 GLOBAL (Fix Hosting Mojibake Judul Grafik Emoji / Indo Chars)
//    Cegah karakter jadi âš¡ / ðŸ’§ (UTF-8 bytes diinterpretasi Windows-1252)
if (!headers_sent()) {
    ini_set('default_charset', 'UTF-8');
    if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');
    header('Content-Type: text/html; charset=utf-8');
}
define('DB_HOST', 'localhost');
define('DB_NAME', 'regist_bali_dailylog');
define('DB_USER', 'root');
define('DB_PASS', '');

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
    if ($host === 'engineeringdept.my.id' || $host === 'www.engineeringdept.my.id'
        || $host === 'registengengineering.com' || $host === 'www.registengengineering.com') {
        define('BASE_URL', '/');
    } elseif (stripos($scriptName, '/AGUSTUS/regist_bali/') !== false) {
        define('BASE_URL', '/AGUSTUS/regist_bali/');
    } else {
        define('BASE_URL', $autoBaseUrl);
    }
}
$cookieBasePath = rtrim(BASE_URL, '/') ?: '/';

define('UPLOAD_PATH', __DIR__ . '/../assets/uploads/');
define('UPLOAD_URL', BASE_URL . 'assets/uploads/');
if (!is_dir(UPLOAD_PATH)) {
    @mkdir(UPLOAD_PATH, 0775, true);
}
@chmod(UPLOAD_PATH, 0775);

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
    $logDir = __DIR__ . '/../storage/logs/';
    if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); @chmod($logDir, 0775); }
    ini_set('error_log', $logDir . 'php_error_' . date('Y-m') . '.log');
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

require_once __DIR__ . '/pdo.php';
require_once __DIR__ . '/functions.php';
if (!defined('APP_LANG')) {
    initLanguage();
}

/* =============================================================
   💰 GLOBAL TARIFF SETTINGS (Auto Migration DB + CRUD Helper)
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
            'tariff_electricity_per_kwh' => 1850,
            'tariff_water_per_m3'        => 9600,
            'tariff_gas_per_kg'          => 24500,
            'tariff_fuel_per_liter'      => 17450,
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
            'electricity_per_kwh' => (int)($out['tariff_electricity_per_kwh'] ?? 1850),
            'water_per_m3'        => (int)($out['tariff_water_per_m3']        ?? 9600),
            'gas_per_kg'          => (int)($out['tariff_gas_per_kg']          ?? 24500),
            'fuel_per_liter'      => (int)($out['tariff_fuel_per_liter']      ?? 17450),
        ];
    } catch (Throwable $e) {
        return [
            'electricity_per_kwh' => 1850,
            'water_per_m3'        => 9600,
            'gas_per_kg'          => 24500,
            'fuel_per_liter'      => 17450,
        ];
    }
}

function saveTariffSettings(array $vals, int $userId = 0): void {
    $db = Database::getInstance();
    _tariffAutoMigrate($db);
    $keys = [
        'electricity_per_kwh' => 'tariff_electricity_per_kwh',
        'water_per_m3'        => 'tariff_water_per_m3',
        'gas_per_kg'          => 'tariff_gas_per_kg',
        'fuel_per_liter'      => 'tariff_fuel_per_liter',
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
