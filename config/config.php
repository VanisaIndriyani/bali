<?php
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
