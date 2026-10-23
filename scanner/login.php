<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Scanner / Volunteer Login (standalone dark page).
 * Volunteers log in with their volunteer code (V00X) + password and are
 * redirected to the scanner home. Session + CSRF protected.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/volunteer-auth.php';
secure_session_start();

/* Already logged in → straight to the scanner home */
if (current_volunteer()) {
    redirect_to('scanner/index.php');
}

$eventName = (string) setting('event_name', 'FRESHERS PARTY 2026');
$eventLogo = (string) setting('event_logo', 'assets/images/event-logo.png');

$error   = '';
$oldCode = '';

/* --- Brute-force lock: 5 failed attempts → 60s lockout --- */
$maxFails  = 5;
$lockSecs  = 60;
$fails     = (int) ($_SESSION['vol_login_fails'] ?? 0);
$lockUntil = (int) ($_SESSION['vol_login_lock'] ?? 0);

if ($lockUntil > 0 && time() >= $lockUntil) {
    $lockUntil = 0;
    $fails     = 0;
    unset($_SESSION['vol_login_lock'], $_SESSION['vol_login_fails']);
}
$locked   = $lockUntil > time();
$lockWait = max(0, $lockUntil - time());
if (($_GET['error'] ?? '') === 'expired') {
    $error = 'Your session or security token expired. Please sign in again.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$locked) {
    csrf_require();

    $oldCode  = strtoupper(trim((string) ($_POST['volunteer_id'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    $row = null;
    if ($oldCode !== '' && $password !== '') {
        try {
            $stmt = db()->prepare('SELECT * FROM volunteers WHERE volunteer_id = ? LIMIT 1');
            $stmt->execute([$oldCode]);
            $row = $stmt->fetch() ?: null;
        } catch (Throwable $ex) {
            error_log('[FresherParty] scanner login lookup failed: ' . $ex->getMessage());
            $row = null;
        }
    }

    if ($row
        && ($row['status'] ?? '') === 'ACTIVE'
        && password_verify($password, (string) $row['password_hash'])
    ) {
        unset($_SESSION['vol_login_fails'], $_SESSION['vol_login_lock']);
        volunteer_login($row);
        audit_log('VOLUNTEER_LOGIN', 'VOLUNTEER', (string) $row['volunteer_id']);
        redirect_to('scanner/index.php');
    }

    $fails++;
    $_SESSION['vol_login_fails'] = $fails;
    audit_log(
        'VOLUNTEER_LOGIN_FAILED',
        'VOLUNTEER',
        ($oldCode !== '' ? $oldCode : null),
        null,
        null,
        'Failed gate-scanner login attempt'
    );

    if ($fails >= $maxFails) {
        $_SESSION['vol_login_lock'] = time() + $lockSecs;
        $locked   = true;
        $lockWait = $lockSecs;
        $error = 'Too many failed attempts. Please wait ' . $lockSecs . ' seconds and try again.';
    } else {
        $error = 'Invalid volunteer ID or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b1516">
<meta name="robots" content="noindex">
<title>Scanner Login · <?= e($eventName) ?></title>
<link rel="icon" type="image/png" href="<?= e(base_url('assets/images/event-logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
<style>
/* ---- page-local scanner login tweaks (dark premium) ---- */
.sc-login-wrap {
  min-height: 100vh;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: 1.5rem 1rem;
}
.sc-login-card {
  width: 100%; max-width: 420px;
  background: linear-gradient(165deg, #132526, #0e1a1b);
  border: 1px solid rgba(255, 255, 255, .1);
  border-radius: 1.5rem;
  box-shadow: 0 24px 60px rgba(0, 0, 0, .5);
  padding: 2rem 1.5rem 1.5rem;
}
.sc-login-card .form-control {
  background: rgba(255, 255, 255, .07);
  border: 1px solid rgba(255, 255, 255, .16);
  color: #e6f4f2;
}
.sc-login-card .form-control::placeholder { color: rgba(230, 244, 242, .35); }
.sc-login-card .form-control:focus {
  background: rgba(255, 255, 255, .1);
  border-color: #f59e0b; color: #fff;
  box-shadow: 0 0 0 .2rem rgba(245, 158, 11, .22);
}
.sc-label {
  font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .12em;
  color: rgba(230, 244, 242, .55);
  margin-bottom: .35rem; display: block;
}
.sc-foot { color: rgba(230, 244, 242, .5); }
.sc-foot a { color: rgba(230, 244, 242, .85); font-weight: 600; }
.sc-foot a:hover { color: #fff; }
</style>
</head>
<body class="scanner-body">
<div class="sc-login-wrap">
  <main class="sc-login-card" aria-label="Volunteer login form">
    <div class="text-center">
      <img src="<?= e(base_url($eventLogo)) ?>" alt="<?= e($eventName) ?> logo" width="72" height="72" class="rounded-4 mb-3" style="background:#fff;padding:.35rem">
      <div class="sc-label"><?= e($eventName) ?></div>
      <h1 class="fs-4 fw-900 mt-1 mb-1">ENTRY SCANNER</h1>
      <div class="small sc-foot mb-4">Volunteer access only</div>
    </div>

    <?php render_flashes(); ?>
    <?php if ($error !== ''): ?>
      <div class="alert alert-danger py-2 mb-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(base_url('scanner/login.php')) ?>" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="sc-label" for="volunteer_id">Volunteer ID</label>
        <input type="text" class="form-control form-control-lg" id="volunteer_id" name="volunteer_id"
               value="<?= e($oldCode) ?>" placeholder="e.g. V001" required autofocus autocomplete="username">
      </div>
      <div class="mb-4">
        <label class="sc-label" for="password">Password</label>
        <input type="password" class="form-control form-control-lg" id="password" name="password"
               placeholder="••••••••" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-amber btn-lg btn-block">
        <i class="bi bi-box-arrow-in-right me-2"></i>LOGIN TO SCANNER
      </button>
    </form>
  </main>

  <div class="mt-4 small text-center sc-foot">
    <a href="<?= e(base_url('index.php')) ?>"><i class="bi bi-arrow-left me-1"></i>Back to event site</a>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
