<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — CSRF protection helpers.
 * Works for regular POST forms (hidden input) and AJAX (X-CSRF-Token header).
 */
require_once __DIR__ . '/auth.php';

/** Get (or create) the CSRF token for this session. */
function csrf_token(): string
{
    secure_session_start();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input markup to drop inside any POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Verify the submitted token (POST field or header). */
function csrf_verify(): bool
{
    secure_session_start();
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || $sent === '') {
        return false;
    }
    $known = (string) ($_SESSION['csrf_token'] ?? '');
    return $known !== '' && hash_equals($known, $sent);
}

/** Abort the request or redirect gracefully if the CSRF token is missing/invalid. */
function csrf_require(): void
{
    if (!csrf_verify()) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (!empty($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'))
               || !empty($_SERVER['HTTP_X_CSRF_TOKEN']);

        if ($isAjax) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Session expired. Please refresh the page and try again.',
                'code'    => 'CSRF_EXPIRED',
                'csrf'    => csrf_token(),
            ]);
            exit;
        }

        $script  = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        // If from admin login or scanner login, redirect gracefully to login page with error param
        if (str_contains($script, 'admin/login.php') || str_contains($referer, 'admin/login.php')) {
            http_response_code(303);
            header('Location: ' . base_url('admin/login.php?error=expired'));
            exit;
        }

        if (str_contains($script, 'scanner/login.php') || str_contains($referer, 'scanner/login.php')) {
            http_response_code(303);
            header('Location: ' . base_url('scanner/login.php?error=expired'));
            exit;
        }

        // Set flash message for forms that use sessions
        if (function_exists('set_flash')) {
            set_flash('danger', 'Your session or security token expired. Please try again.');
        }

        if ($referer !== '') {
            $refHost = parse_url($referer, PHP_URL_HOST);
            $curHost = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
            if ($refHost === $curHost || empty($refHost)) {
                http_response_code(303);
                header('Location: ' . $referer);
                exit;
            }
        }

        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Session Expired · FRESHERS PARTY 2026</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<style>
body { background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: system-ui, -apple-system, sans-serif; padding: 1.5rem; margin: 0; }
.card { background: #1e293b; border: 1px solid rgba(255,255,255,0.12); border-radius: 1rem; max-width: 440px; width: 100%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
.btn-teal { background: #0f766e; border: none; color: #fff; font-weight: 600; padding: 0.75rem 1.5rem; border-radius: 0.5rem; }
.btn-teal:hover { background: #115e59; color: #fff; }
</style>
</head>
<body>
<div class="card p-4 text-center">
  <div class="mb-3">
    <div class="d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning rounded-circle" style="width: 64px; height: 64px; font-size: 2rem;">
      <i class="bi bi-shield-exclamation"></i>
    </div>
  </div>
  <h4 class="fw-bold mb-2">Session Expired</h4>
  <p class="text-secondary small mb-4">Session expired. Please go back, refresh the page and try again.</p>
  <div class="d-grid gap-2">
    <a href="javascript:history.back()" class="btn btn-teal"><i class="bi bi-arrow-left me-1"></i> Go Back &amp; Refresh</a>
    <a href="<?= htmlspecialchars(base_url(''), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary text-light btn-sm">Return to Home</a>
  </div>
</div>
</body>
</html>
        <?php
        exit;
    }
}
