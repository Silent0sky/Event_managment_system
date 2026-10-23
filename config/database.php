<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — PDO Database Connection (singleton)
 */
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, (int) DB_PORT, DB_NAME);
        try {
            $dbPass = constant('DB_' . 'PASS');
            $pdo = new PDO($dsn, DB_USER, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::ATTR_PERSISTENT         => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('[FresherParty] DB connection failed: ' . $e->getMessage());
            $msg = 'Database connection failed. Configure DB_HOST, DB_NAME, DB_USER and DB_PASS using environment variables or config/secrets.php.';
            if (defined('INSTALLER') && INSTALLER === true) {
                // let the installer display a friendly message instead of dying
                throw $e;
            }
            die('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Database Error</title><style>body{font-family:system-ui,sans-serif;background:#f6f8fa;color:#1f2937;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:1rem}div{max-width:480px;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:2rem;text-align:center}h1{font-size:1.25rem;margin:0 0 .5rem}</style>'
                . '</head><body><div><h1>⚠ Database Error</h1><p>' . $msg . '</p></div></body></html>');
        }
    }
    return $pdo;
}

/** Table prefix-free helper: quote identifier */
function q(string $col): string
{
    return '`' . str_replace('`', '', $col) . '`';
}
