<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Installer.
 * 1) Requirements check  2) Database + tables  3) Create first admin  4) Lock.
 * After a successful install this file disables itself via config/installed.lock.
 * DELETE install.php from the server after installation (recommended).
 */
define('INSTALLER', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

/* Start the hardened session once, before any output (required for CSRF) */
secure_session_start();
$installCsrf = csrf_token();

$lockFile = __DIR__ . '/config/installed.lock';
if (file_exists($lockFile)) {
    http_response_code(403);
    $when = @filemtime($lockFile);
    $when = $when ? date('j M Y, g:i A', $when) : 'unknown';
    exit('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Installer locked</title><style>body{font-family:system-ui,sans-serif;background:#f6f8f9;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:1rem;color:#1c2b2a}div{max-width:480px;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:2rem;text-align:center}h1{font-size:1.3rem;margin:0 0 .5rem}a{color:#0d9488;font-weight:600}</style>'
        . '</head><body><div><h1>🔒 Installer already ran</h1>'
        . '<p>This system was installed on <strong>' . $when . '</strong> and the installer is locked.</p>'
        . '<p class="small">For maximum security, <strong>delete install.php</strong> from your hosting now.</p>'
        . '<p><a href="' . e(BASE_URL) . '/index.php">→ Go to the website</a></p></div></body></html>');
}

$step  = (string) ($_GET['step'] ?? 'requirements');
$steps = ['requirements', 'database', 'admin', 'finish'];
if (!in_array($step, $steps, true)) {
    $step = 'requirements';
}
$errors = [];
$notices = [];

function req_row(string $label, bool $ok, string $hint = ''): string
{
    return '<div class="list-group-item d-flex justify-content-between align-items-center">'
        . '<div><div class="fw-semibold">' . $label . '</div>' . ($hint ? '<div class="small text-muted">' . $hint . '</div>' : '') . '</div>'
        . ($ok ? '<span class="badge text-bg-success rounded-pill">OK</span>' : '<span class="badge text-bg-danger rounded-pill">FAIL</span>')
        . '</div>';
}

function run_schema(): array
{
    $out = ['ok' => true, 'messages' => []];
    try {
        $sql = file_get_contents(__DIR__ . '/database.sql');
        if ($sql === false) {
            throw new RuntimeException('database.sql not found next to install.php.');
        }
        // strip comment lines, split statements
        $lines = array_filter(explode("\n", $sql), fn ($l) => !preg_match('/^--/', ltrim($l)) && trim($l) !== '');
        $sql = implode("\n", $lines);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, (int) DB_PORT, DB_NAME),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        foreach ($statements as $stmt) {
            if ($stmt === '') { continue; }
            $pdo->exec($stmt);
        }
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $out['messages'][] = 'Tables ready: ' . implode(', ', $tables);
    } catch (Throwable $ex) {
        $out['ok'] = false;
        $out['messages'][] = $ex->getMessage();
    }
    return $out;
}

function ensure_folders(): array
{
    $out = ['ok' => true, 'messages' => []];
    $dirs = ['', '/uploads', '/uploads/students', '/uploads/payments', '/uploads/event'];
    foreach ($dirs as $d) {
        $dir = APP_ROOT . $d;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $out['ok'] = false;
            $out['messages'][] = "Could not create folder: $d";
        }
    }
    // uploads protection
    $ht = APP_ROOT . '/uploads/.htaccess';
    if (!file_exists($ht)) {
        $content = "# Deny execution/serving of any .php scripts inside uploads\n"
            . "Options -Indexes\n"
            . "<FilesMatch \"(?i)\\.(php|php\\d*|phtml|phar|pl|py|cgi|sh|asp|aspx|jsp|html|htm|svg)$\">\n"
            . "  Require all denied\n"
            . "</FilesMatch>\n"
            . "<IfModule mod_headers.c>\n"
            . "  Header set X-Content-Type-Options \"nosniff\"\n"
            . "  Header set Content-Security-Policy \"default-src 'none'; sandbox\"\n"
            . "</IfModule>\n";
        if (@file_put_contents($ht, $content) === false) {
            $out['messages'][] = 'Could not write uploads/.htaccess (add it manually from the README).';
        }
    }
    // index placeholders to avoid listing
    foreach (['/uploads/students', '/uploads/payments', '/uploads/event'] as $d) {
        $idx = APP_ROOT . $d . '/index.html';
        if (!file_exists($idx)) {
            @file_put_contents($idx, '');
        }
    }
    return $out;
}

/* ---------------- Handle POSTs ---------------- */
$adminCreated = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired — please try again.';
    }
    $posted = (string) ($_POST['do'] ?? '');

    if ($posted === 'database') {
        $r = run_schema();
        $f = ensure_folders();
        if (!$r['ok']) {
            $errors = array_merge($errors, $r['messages']);
        }
        if (!$f['ok']) {
            $errors = array_merge($errors, $f['messages']);
        }
        if (!$errors) {
            $notices = array_merge($r['messages'], $f['messages']);
            header('Location: install.php?step=admin');
            exit;
        }
        $step = 'database';
    } elseif ($posted === 'admin') {
        $name     = trim((string) ($_POST['name'] ?? ''));
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $pass     = (string) ($_POST['password'] ?? '');
        $pass2    = (string) ($_POST['password2'] ?? '');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            $errors[] = 'Enter the admin full name (3–100 characters).';
        }
        if (!preg_match('/^[a-z0-9_.]{3,30}$/', $username)) {
            $errors[] = 'Username must be 3–30 lowercase letters/digits (a-z, 0-9, _ or .).';
        }
        if (strlen($pass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($pass !== $pass2) {
            $errors[] = 'Passwords do not match.';
        }
        if (!$errors) {
            try {
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, (int) DB_PORT, DB_NAME),
                    DB_USER, DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $count = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
                if ($count > 0) {
                    $errors[] = 'An admin account already exists. For security, installation is finished — delete install.php.';
                    $adminCreated = true;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO admins (name, username, password_hash, role, status, created_at, updated_at)
                                           VALUES (?,?,?,'SUPER_ADMIN','ACTIVE',?,?)");
                    $stmt->execute([$name, $username, password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                    audit_log_install($pdo, $username);
                    $adminCreated = true;
                }
                if ($adminCreated && !$errors) {
                    header('Location: install.php?step=finish');
                    exit;
                }
            } catch (Throwable $ex) {
                $errors[] = 'Database error: ' . $ex->getMessage();
            }
        }
        $step = 'admin';
    }
}

function audit_log_install(PDO $pdo, string $username): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_type, user_id, action, target_type, target_id, new_value, reason, ip_address, created_at)
                               VALUES ('SYSTEM', NULL, 'SYSTEM_INSTALLED', 'ADMIN', ?, ?, 'Initial installation', ?, ?)");
        $stmt->execute([
            $username,
            json_encode(['role' => 'SUPER_ADMIN', 'created' => true]),
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $ignored) {
    }
}

/* ---------------- Requirements data ---------------- */
$checks = [
    'label' => [], 'ok' => [],
];
$phpOk    = PHP_VERSION_ID >= 80000;
$pdoOk    = extension_loaded('pdo_mysql');
$sesOk    = session_status() !== PHP_SESSION_DISABLED;
$uplOk    = (bool) ini_get('file_uploads');
$finfoOk  = function_exists('finfo_open') || function_exists('getimagesize');
$folders  = ensure_folders();
$configOk = @is_writable(__DIR__ . '/config');
$lockOk   = @is_writable(__DIR__ . '/config');
$allOk    = $phpOk && $pdoOk && $sesOk && $uplOk && $finfoOk && $folders['ok'] && $lockOk;

/* lock writing on finish */
if ($step === 'finish' && !file_exists($lockFile)) {
    if (@file_put_contents($lockFile, 'installed=' . date('c') . "\n") === false) {
        $errors[] = 'Could not write config/installed.lock — manually create an empty file with that name to lock the installer.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · FRESHERS PARTY 2026</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<style>
body{font-family:'Inter',system-ui,sans-serif;background:#f0f5f4;color:#1c2b2a}
.wizard{max-width:640px;margin:2rem auto}
.brandline{display:flex;align-items:center;gap:.75rem;justify-content:center;margin-bottom:1.25rem}
.step-pills{display:flex;gap:.4rem;justify-content:center;margin-bottom:1.25rem;flex-wrap:wrap}
.step-pill{padding:.35rem .9rem;border-radius:999px;font-size:.8rem;font-weight:700;background:#e2e8e6;color:#64748b}
.step-pill.active{background:#0d9488;color:#fff}
.step-pill.done{background:#059669;color:#fff}
.card{border:0;border-radius:1rem;box-shadow:0 12px 30px rgba(19,78,74,.1)}
.btn-primary{background:#0d9488;border-color:#0d9488}
.btn-primary:hover{background:#0f766e;border-color:#0f766e}
</style>
</head>
<body class="installer-body">
<div class="wizard px-3 pb-5">
  <div class="brandline">
    <img src="assets/images/event-logo.png" alt="" width="52" height="52" class="rounded-3">
    <div><div class="fs-4 fw-800 lh-1">FRESHERS PARTY 2026</div><div class="small text-muted">Installer · v1.0.0</div></div>
  </div>
  <div class="step-pills">
    <span class="step-pill <?= in_array($step, ['database', 'admin', 'finish'], true) ? 'done' : ($step === 'requirements' ? 'active' : '') ?>">1 · Requirements</span>
    <span class="step-pill <?= in_array($step, ['admin', 'finish'], true) ? 'done' : ($step === 'database' ? 'active' : '') ?>">2 · Database</span>
    <span class="step-pill <?= $step === 'finish' ? 'done' : ($step === 'admin' ? 'active' : '') ?>">3 · Admin</span>
    <span class="step-pill <?= $step === 'finish' ? 'active' : '' ?>">4 · Finish</span>
  </div>

  <div class="card mb-3">
    <div class="card-body p-4">

      <?php if ($errors): ?>
        <div class="alert alert-danger"><strong><i class="bi bi-exclamation-triangle me-1"></i>Problem:</strong>
          <ul class="mb-0 mt-1 small"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <?php if ($notices): ?>
        <div class="alert alert-success small"><i class="bi bi-check-circle me-1"></i><?= implode('<br>', array_map('e', $notices)) ?></div>
      <?php endif; ?>

      <?php if ($step === 'requirements'): ?>
        <h1 class="h4 fw-800 mb-3"><i class="bi bi-clipboard-check me-2 text-teal"></i>Server requirements</h1>
        <div class="list-group mb-3">
          <?= req_row('PHP 8.0 or newer', $phpOk, 'Current: ' . PHP_VERSION) ?>
          <?= req_row('MySQL (PDO driver)', $pdoOk, 'pdo_mysql extension') ?>
          <?= req_row('Sessions enabled', $sesOk) ?>
          <?= req_row('File uploads enabled', $uplOk, (string) ini_get('upload_max_filesize') . ' per file') ?>
          <?= req_row('Image detection (finfo/getimagesize)', $finfoOk, 'for secure upload validation') ?>
          <?= req_row('Upload folders writable', $folders['ok'], '/uploads/students · /uploads/payments · /uploads/event') ?>
          <?= req_row('config/ folder writable', $configOk, 'for the installation lock file') ?>
        </div>
        <div class="alert <?= DB_PASS === '' && DB_USER === 'root' ? 'alert-warning' : 'alert-light border' ?> small">
          <strong><i class="bi bi-database-gear me-1"></i>Database credentials</strong> are read from <code>config/config.php</code>:
          <div class="mt-1">Host: <code><?= e(DB_HOST) ?></code> · DB: <code><?= e(DB_NAME) ?></code> · User: <code><?= e(DB_USER) ?></code></div>
          <div class="mt-1 text-muted">On InfinityFree, use the values from your client area → MySQL Databases (e.g. host <code>sql123.epizy.com</code>, database <code>epiz_…_fresher</code>). Create the database first, then edit config.php and reload this page.</div>
        </div>
        <a class="btn btn-primary btn-lg w-100 <?= $allOk ? '' : 'disabled' ?>" href="install.php?step=database">Continue to database setup →</a>

      <?php elseif ($step === 'database'): ?>
        <h1 class="h4 fw-800 mb-3"><i class="bi bi-database-add me-2 text-teal"></i>Database setup</h1>
        <p class="text-muted small">This will connect to MySQL using <code>config/config.php</code>, create all 7 tables (they are skipped if already imported from <code>database.sql</code>) and prepare the upload folders.</p>
        <?php
        $dbTest = ['ok' => false, 'msg' => ''];
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, (int) DB_PORT, DB_NAME), DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            $dbTest = ['ok' => true, 'msg' => 'Connection OK'];
        } catch (Throwable $ex) {
            $dbTest = ['ok' => false, 'msg' => $ex->getMessage()];
        }
        ?>
        <div class="alert <?= $dbTest['ok'] ? 'alert-success' : 'alert-danger' ?> small">
          <i class="bi <?= $dbTest['ok'] ? 'bi-plug-check' : 'bi-plug-x' ?> me-1"></i>
          <?= $dbTest['ok'] ? 'MySQL connection successful.' : 'MySQL connection failed: ' . e($dbTest['msg']) ?>
        </div>
        <form method="post" action="install.php?step=database">
          <input type="hidden" name="csrf_token" value="<?= e($installCsrf) ?>">
          <input type="hidden" name="do" value="database">
          <button class="btn btn-primary btn-lg w-100 <?= $dbTest['ok'] ? '' : 'disabled' ?>" type="submit">
            <i class="bi bi-play-fill me-1"></i>Create tables &amp; folders
          </button>
        </form>

      <?php elseif ($step === 'admin'): ?>
        <h1 class="h4 fw-800 mb-3"><i class="bi bi-person-gear me-2 text-teal"></i>Create super admin</h1>
        <p class="text-muted small">This is the master account (SUPER_ADMIN). Choose a strong password — it will not be stored anywhere in plain text.</p>
        <form method="post" action="install.php?step=admin" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= e($installCsrf) ?>">
          <input type="hidden" name="do" value="admin">
          <div class="row g-3">
            <div class="col-12"><label class="form-label fw-semibold">Full name</label>
              <input class="form-control form-control-lg" name="name" required maxlength="100" value="<?= e(trim((string) ($_POST['name'] ?? ''))) ?>"></div>
            <div class="col-sm-6"><label class="form-label fw-semibold">Username</label>
              <input class="form-control form-control-lg" name="username" required pattern="[a-z0-9_.]{3,30}" value="<?= e(strtolower(trim((string) ($_POST['username'] ?? '')))) ?>" placeholder="admin"></div>
            <div class="col-sm-6"><label class="form-label fw-semibold">Role</label>
              <input class="form-control form-control-lg" value="SUPER_ADMIN" disabled></div>
            <div class="col-sm-6"><label class="form-label fw-semibold">Password</label>
              <input class="form-control form-control-lg" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
            <div class="col-sm-6"><label class="form-label fw-semibold">Repeat password</label>
              <input class="form-control form-control-lg" type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
            <div class="col-12"><button class="btn btn-primary btn-lg w-100" type="submit"><i class="bi bi-shield-lock me-1"></i>Create admin &amp; finish</button></div>
          </div>
        </form>

      <?php elseif ($step === 'finish'): ?>
        <div class="text-center py-2">
          <div class="display-3 text-success"><i class="bi bi-check-circle-fill"></i></div>
          <h1 class="h4 fw-800 mt-2">Installation complete!</h1>
          <p class="text-muted small">The installer is now locked. <strong>Delete install.php from the server now</strong> for maximum security.</p>
        </div>
        <div class="list-group mb-3">
          <a class="list-group-item list-group-item-action" href="index.php"><i class="bi bi-house me-2 text-teal"></i>Open the website</a>
          <a class="list-group-item list-group-item-action" href="admin/login.php"><i class="bi bi-shield-lock me-2 text-teal"></i>Open the Admin console</a>
          <a class="list-group-item list-group-item-action" href="scanner/login.php"><i class="bi bi-qr-code-scan me-2 text-teal"></i>Open the Volunteer scanner</a>
        </div>
        <div class="alert alert-warning small mb-0">
          <strong>Next steps:</strong>
          <ol class="mb-0 mt-1">
            <li>Login as admin → <strong>Settings</strong>: set event date, fee, UPI ID, upload logos &amp; your real UPI QR.</li>
            <li><strong>Volunteers</strong>: create scanner accounts for each gate.</li>
            <li>Enable HTTPS (free Let's Encrypt in the InfinityFree client area) — the scanner camera requires it.</li>
          </ol>
        </div>
      <?php endif; ?>

    </div>
  </div>
  <p class="text-center small text-muted">FRESHERS PARTY 2026 · Registration · Payment Verification · QR Pass · Entry Management</p>
</div>
</body>
</html>
