<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Secure session bootstrap & shared session helpers.
 */
require_once dirname(__DIR__) . '/config/database.php';

/** Start a hardened session (call before any page output). */
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $params = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => defined('IS_HTTPS') && IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_name('FPSESS');
    session_set_cookie_params($params);
    session_start();

    // Bind session to a User-Agent fingerprint (mitigates simple session theft)
    $fp = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'));
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
               || str_starts_with($host, 'localhost:')
               || str_starts_with($host, '127.0.0.1:')
               || (defined('PHP_SAPI') && PHP_SAPI === 'cli');

    if (isset($_SESSION['_fp'])) {
        if (!hash_equals((string) $_SESSION['_fp'], $fp)) {
            if ($isLocal) {
                // In local development / DevTools responsive testing, UA changes when switching device modes.
                // Update fingerprint without destroying session or invalidating CSRF tokens.
                $_SESSION['_fp'] = $fp;
            } else {
                session_unset();
                session_destroy();
                session_set_cookie_params($params);
                session_start();
                $_SESSION['_fp'] = $fp;
            }
        }
    } else {
        $_SESSION['_fp'] = $fp;
    }
}

/** Currently logged-in admin row (subset) or NULL. Bootstraps the session. */
function current_admin(): ?array
{
    secure_session_start();
    return isset($_SESSION['admin']) && is_array($_SESSION['admin']) ? $_SESSION['admin'] : null;
}

/** Currently logged-in volunteer row (subset) or NULL. Bootstraps the session. */
function current_volunteer(): ?array
{
    secure_session_start();
    return isset($_SESSION['volunteer']) && is_array($_SESSION['volunteer']) ? $_SESSION['volunteer'] : null;
}

/** Destroy the whole session (logout everywhere). */
function destroy_all_sessions(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        secure_session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

/** Periodic session ID rotation (call on authenticated pages). */
function rotate_session(): void
{
    $now = time();
    if (!isset($_SESSION['_rot'])) {
        $_SESSION['_rot'] = $now;
    } elseif ($now - (int) $_SESSION['_rot'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_rot'] = $now;
    }
}
