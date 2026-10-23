<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Graceful Error Page
 * Handles 400, 403, 404, 500 gracefully without leaking server details.
 */
@require_once __DIR__ . '/config/config.php';
@require_once __DIR__ . '/includes/functions.php';

$code = (int) ($_GET['code'] ?? http_response_code() ?: 404);
if (!in_array($code, [400, 403, 404, 500], true)) {
    $code = 404;
}

http_response_code($code);

$titles = [
    400 => 'Bad Request',
    403 => 'Access Denied',
    404 => 'Page Not Found',
    500 => 'Server Error',
];

$descriptions = [
    400 => 'The request could not be processed. Please verify your link and try again.',
    403 => 'You do not have permission to access this page or resource.',
    404 => 'The page or registration record you are looking for does not exist or has been moved.',
    500 => 'An unexpected error occurred. Our team has logged this issue. Please try again in a few moments.',
];

$icons = [
    400 => 'bi-exclamation-octagon',
    403 => 'bi-shield-lock',
    404 => 'bi-question-circle',
    500 => 'bi-exclamation-triangle',
];

$title = $titles[$code] ?? 'Error';
$desc  = $descriptions[$code] ?? 'An unexpected error occurred.';
$icon  = $icons[$code] ?? 'bi-exclamation-circle';

$base = defined('BASE_URL') ? BASE_URL : './';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> (<?= $code ?>) — FRESHERS PARTY 2026</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --fp-teal: #0d9488;
      --fp-teal-dark: #134e4a;
      --fp-bg: #f5f8f7;
    }
    body {
      font-family: 'Outfit', system-ui, -apple-system, sans-serif;
      background: var(--fp-bg);
      color: #1e293b;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }
    .error-card {
      max-width: 480px;
      width: 100%;
      background: #ffffff;
      border-radius: 1.25rem;
      box-shadow: 0 16px 36px rgba(13, 148, 136, 0.09);
      border: 1px solid rgba(13, 148, 136, 0.12);
      padding: 2.5rem 1.75rem;
      text-align: center;
    }
    .error-code {
      font-size: 4.5rem;
      font-weight: 900;
      line-height: 1;
      color: var(--fp-teal);
      margin-bottom: .5rem;
      letter-spacing: -0.04em;
    }
    .error-icon {
      font-size: 2.5rem;
      color: var(--fp-teal);
      margin-bottom: 1rem;
    }
    .btn-teal {
      background: linear-gradient(135deg, #0d9488, #14b8a6);
      color: #fff;
      font-weight: 600;
      border: none;
      padding: .65rem 1.4rem;
      border-radius: .75rem;
      min-height: 44px;
    }
    .btn-teal:hover {
      background: linear-gradient(135deg, #0f766e, #0d9488);
      color: #fff;
    }
  </style>
</head>
<body>
  <div class="error-card">
    <div class="error-icon"><i class="bi <?= $icon ?>"></i></div>
    <div class="error-code"><?= $code ?></div>
    <h1 class="h4 fw-bold mb-2"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="text-muted mb-4"><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></p>
    <div class="d-grid d-sm-flex gap-2 justify-content-center">
      <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-teal">
        <i class="bi bi-house-door me-1"></i> Home Page
      </a>
      <a href="<?= htmlspecialchars($base . '/status.php', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-search me-1"></i> Check Status
      </a>
    </div>
  </div>
</body>
</html>
