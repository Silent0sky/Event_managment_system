<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin login (standalone page, no admin layout).
 * - CSRF-protected POST, generic failure message (no detail leak)
 * - Session-based brute-force lock: 5 failed attempts → 60 s lock
 * - Honours $_SESSION['intended'] (relative admin/ paths only)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

secure_session_start();
$settings = get_settings();

// Already signed in → straight to the dashboard
if (current_admin()) {
    redirect_to('admin/dashboard.php');
}

$maxFails  = 5;
$lockSecs  = 60;

$fails     = (int) ($_SESSION['adm_login_fails'] ?? 0);
$lockUntil = (int) ($_SESSION['adm_login_lock'] ?? 0);

// Expired lock → start counting attempts again
if ($lockUntil > 0 && time() >= $lockUntil) {
    $lockUntil = 0;
    $fails     = 0;
    unset($_SESSION['adm_login_lock'], $_SESSION['adm_login_fails']);
}

$locked   = $lockUntil > time();
$lockWait = max(0, $lockUntil - time());
$error    = null;
if (($_GET['error'] ?? '') === 'expired') {
    $error = 'Your session or security token expired. Please enter your credentials to sign in.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$locked) {
    csrf_require();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    // Always run password_verify (also for unknown users) to equalize timing
    $hash     = $row ? (string) $row['password_hash'] : '$2y$10$usesomesillystringfore7hnbRV9uT1vnzJXhKk2vGzq0y2WZAy2';
    $passwordOk = password_verify($password, $hash);
    $activeOk   = $row && (string) $row['status'] === 'ACTIVE';

    if ($row && $passwordOk && $activeOk) {
        // Success — clear counters, start session, audit, honour intended URL
        $intended = (string) ($_SESSION['intended'] ?? '');
        unset($_SESSION['intended'], $_SESSION['adm_login_fails'], $_SESSION['adm_login_lock']);

        admin_login($row);
        audit_log('ADMIN_LOGIN', 'ADMIN', (string) $row['username']);

        $target = 'admin/dashboard.php';
        if ($intended !== '') {
            $cand = $intended;
            $base = BASE_PATH;
            if ($base !== '' && str_starts_with($cand, $base)) {
                $cand = substr($cand, strlen($base));
            }
            $cand = ltrim($cand, '/');
            if (preg_match('~^admin/[A-Za-z0-9._\-]*(\?.*)?$~', $cand) && !str_contains($cand, '..')) {
                $target = $cand;
            }
        }
        redirect_to($target);
    }

    // Failure — audit + count + lock after $maxFails
    $fails++;
    $_SESSION['adm_login_fails'] = $fails;
    audit_log('ADMIN_LOGIN_FAILED', 'ADMIN', $username !== '' ? $username : '(blank)');

    if ($fails >= $maxFails) {
        $_SESSION['adm_login_lock'] = time() + $lockSecs;
        $locked   = true;
        $lockWait = $lockSecs;
        $error    = 'Too many failed attempts. Please wait ' . $lockSecs . ' seconds and try again.';
    } else {
        $error = 'Invalid username or password, or account disabled.';
    }
}

$eventLogo = $settings['event_logo'] ?: 'assets/images/event-logo.png';
$pageTitle = 'Admin Login · ' . (string) $settings['event_name'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f766e">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?></title>
<link rel="icon" type="image/png" href="<?= e(base_url('assets/images/event-logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
</head>
<body class="d-flex flex-column min-vh-100">

<main class="flex-grow-1 d-flex align-items-center justify-content-center py-4 py-md-5">
  <div class="w-100 px-3" style="max-width:420px">

    <div class="card card-shadow">
      <div class="card-body p-4">

        <div class="text-center mb-4">
          <img src="<?= e(base_url($eventLogo)) ?>" alt="<?= e($settings['event_name']) ?> logo" width="80" height="80" class="rounded-4 mb-2 shadow-sm">
          <h1 class="h4 fw-bold mb-1"><?= e($settings['event_name']) ?></h1>
          <div class="small fw-semibold text-muted-2" style="letter-spacing:.14em">ADMIN CONSOLE</div>
        </div>

        <?php render_flashes(); ?>

        <?php if ($error !== null): ?>
          <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-shield-exclamation mt-1"></i>
            <div><?= e($error) ?></div>
          </div>
        <?php endif; ?>

        <?php if ($locked): ?>
          <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-hourglass-split mt-1"></i>
            <div>Locked for <strong><?= (int) $lockWait ?>s</strong>. Try again after the lock expires.</div>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url('admin/login.php')) ?>" autocomplete="on">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="l-user">Username</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text bg-white"><i class="bi bi-person-circle"></i></span>
              <input class="form-control" type="text" id="l-user" name="username" required maxlength="50"
                     autocomplete="username" autofocus value="<?= e($_POST['username'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold" for="l-pass">Password</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text bg-white"><i class="bi bi-key"></i></span>
              <input class="form-control" type="password" id="l-pass" name="password" required
                     autocomplete="current-password" <?= $locked ? 'disabled' : '' ?>>
            </div>
          </div>

          <button class="btn btn-teal btn-lg btn-block d-flex align-items-center justify-content-center gap-2" type="submit" <?= $locked ? 'disabled' : '' ?>>
            <i class="bi bi-box-arrow-in-right"></i> Sign In
          </button>
        </form>

      </div>
      <div class="card-footer bg-white small text-muted-2 text-center py-3">
        <i class="bi bi-shield-lock me-1"></i>Authorized staff only — every sign-in is audited.
      </div>
    </div>

    <p class="text-center small mt-3">
      <a class="footer-link" href="<?= e(base_url('index.php')) ?>"><i class="bi bi-arrow-left me-1"></i>Back to the event site</a>
    </p>

  </div>
</main>

<footer class="text-center small text-muted-2 pb-4">
  <?= e($settings['college_name']) ?> · <?= e($settings['event_name']) ?>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(base_url('assets/js/app.js')) ?>"></script>
</body>
</html>
