<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Application Configuration
 * =====================================================
 * Database credentials are loaded from environment variables or the
 * ignored config/secrets.php file. Never commit production credentials.
 */

/* ---------------------------------------------------------
 * DATABASE (environment variables + optional local secrets file)
 * --------------------------------------------------------- */
$__rawHost    = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
$__isLocalEnv = in_array($__rawHost, ['localhost', '127.0.0.1'], true)
                || (defined('PHP_SAPI') && PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST']));

$__secretFile = __DIR__ . '/secrets.php';
$__secrets = is_file($__secretFile) ? require $__secretFile : [];
if (!is_array($__secrets)) {
    throw new RuntimeException('config/secrets.php must return an array.');
}
$__configValue = static function (string $key, string $default = '') use ($__secrets): string {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    return isset($__secrets[$key]) && is_string($__secrets[$key]) ? $__secrets[$key] : $default;
};
$__localDefaults = $__isLocalEnv
    ? ['DB_HOST' => 'localhost', 'DB_NAME' => 'fresher_party', 'DB_USER' => 'root', 'DB_PASS' => '']
    : ['DB_HOST' => '', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASS' => ''];

define('DB_HOST', $__configValue('DB_HOST', $__localDefaults['DB_HOST']));
define('DB_NAME', $__configValue('DB_NAME', $__localDefaults['DB_NAME']));
define('DB_USER', $__configValue('DB_USER', $__localDefaults['DB_USER']));
define('DB_PASS', $__configValue('DB_PASS', $__localDefaults['DB_PASS']));
define('DB_PORT', (int) $__configValue('DB_PORT', '3306'));

/* ---------------------------------------------------------
 * APPLICATION
 * --------------------------------------------------------- */
define('APP_NAME', 'FRESHERS PARTY 2026');
define('APP_VERSION', '1.1.0');
define('APP_CURRENCY_SYMBOL', '₹');
define('CURRENCY_CODE', 'INR');
define('MAX_UPLOAD_BYTES', 4 * 1024 * 1024);   // 4 MB per image upload
define('REG_PREFIX', 'FR26');                   // Registration / Pass ID prefix
define('APP_TIMEZONE', 'Asia/Kolkata');
define('UPI_ID_DEFAULT', 'example@upi');        // Placeholder until admin sets a real one

/* ---------------------------------------------------------
 * AUTO-COMPUTED (do not edit below this line)
 * --------------------------------------------------------- */
define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '0');   // never leak errors on shared hosting
ini_set('log_errors', '1');

$__scheme  = 'http';
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? 80) === 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
    || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains((string)$_SERVER['HTTP_CF_VISITOR'], 'https'))
    || (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower((string)$_SERVER['HTTP_FRONT_END_HTTPS']) === 'on')
    || (isset($_SERVER['HTTP_X_FORWARDED_PORT']) && (int)$_SERVER['HTTP_X_FORWARDED_PORT'] === 443)
    || (!$__isLocalEnv && !empty($_SERVER['HTTP_HOST']))) {
    $__scheme = 'https';
}
$__host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$__docRoot = str_replace('\\', '/', rtrim((string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')));
$__appRoot = str_replace('\\', '/', rtrim((string) (realpath(APP_ROOT) ?: APP_ROOT)));
$__base    = '';
if ($__docRoot !== '' && str_starts_with(strtolower($__appRoot), strtolower($__docRoot))) {
    $__base = substr($__appRoot, strlen($__docRoot));
} else {
    // Dynamic detection supporting symlinks, NTFS junctions and directory aliases
    $__scriptFile = str_replace('\\', '/', rtrim((string) (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '')));
    $__scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($__scriptFile !== '' && $__appRoot !== '' && strncasecmp($__scriptFile, $__appRoot, strlen($__appRoot)) === 0) {
        $__rel = substr($__scriptFile, strlen($__appRoot));
        if ($__rel !== '' && str_ends_with(strtolower($__scriptName), strtolower($__rel))) {
            $__base = substr($__scriptName, 0, -strlen($__rel));
        }
    }
}
define('BASE_PATH', ($__base === '/' || $__base === '') ? '' : rtrim($__base, '/'));
define('BASE_URL', $__scheme . '://' . $__host . BASE_PATH);
define('IS_HTTPS', $__scheme === 'https');
