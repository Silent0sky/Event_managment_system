<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Scanner home (volunteer).
 * Shows the assigned gate, today's entry counters and this volunteer's
 * last 8 scans. Entry point to the live camera scanner.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/volunteer-auth.php';
secure_session_start();

$volunteer = require_volunteer();

$eventName = (string) setting('event_name', 'FRESHERS PARTY 2026');
$eventLogo = (string) setting('event_logo', 'assets/images/event-logo.png');

/* Entered today (since midnight, server timezone Asia/Kolkata) */
$stmt = db()->prepare("SELECT COUNT(*) FROM students WHERE entry_status = 'ENTERED' AND entry_time >= ?");
$stmt->execute([today_start_db()]);
$enteredToday = (int) $stmt->fetchColumn();

/* Total registered participants */
$stmt = db()->prepare('SELECT COUNT(*) FROM students');
$stmt->execute();
$totalRegistered = (int) $stmt->fetchColumn();

/* This volunteer's last 8 scans (deduplicated by participant to avoid repeat clutter) */
$stmt = db()->prepare(
    'SELECT el.scan_time, el.result, s.name AS student_name, s.pnr_number
     FROM entry_logs el
     LEFT JOIN students s ON s.id = el.student_id
     WHERE el.id IN (
         SELECT MAX(id)
         FROM entry_logs
         WHERE volunteer_id = ?
         GROUP BY COALESCE(student_id, -id)
     )
     ORDER BY el.id DESC
     LIMIT 8'
);
$stmt->execute([(int) $volunteer['id']]);
$recentScans = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en" class="scanner-html">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0b1516">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="robots" content="noindex">
<title>Scanner · <?= e($eventName) ?></title>
<link rel="icon" type="image/png" href="<?= e(base_url('assets/images/event-logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
<style>
/* ---- guaranteed dark theme for scanner on all mobile browsers ---- */
html, html.scanner-html, body.scanner-body {
  background: #0b1516 !important;
  background-color: #0b1516 !important;
  color: #e6f4f2 !important;
  min-height: 100vh;
  min-height: 100dvh;
}
.scanner-shell {
  max-width: 520px;
  margin: 0 auto;
  background: #0b1516 !important;
  min-height: 100vh;
  min-height: 100dvh;
}
.scanner-header {
  background: linear-gradient(135deg, #134e4a, #0d9488) !important;
  border-radius: 1.25rem;
  padding: 1rem 1.1rem;
  color: #fff !important;
  box-shadow: 0 12px 30px rgba(0, 0, 0, .35);
}
.scanner-panel {
  background: rgba(255, 255, 255, .07) !important;
  border: 1px solid rgba(255, 255, 255, .12) !important;
  border-radius: 1rem;
}
.btn-amber {
  background: #f59e0b !important;
  border-color: #f59e0b !important;
  color: #0b1516 !important;
  font-weight: 800 !important;
}
.btn-amber:hover, .btn-amber:focus {
  background: #d97706 !important;
  border-color: #d97706 !important;
  color: #000 !important;
}
.btn-teal {
  background: #0d9488 !important;
  border-color: #0d9488 !important;
  color: #fff !important;
  font-weight: 700 !important;
}
.btn-teal:hover, .btn-teal:focus {
  background: #0f766e !important;
  color: #fff !important;
}
.btn-outline-light {
  border-color: rgba(255, 255, 255, .25) !important;
  color: rgba(255, 255, 255, .85) !important;
}
.btn-outline-light:hover, .btn-outline-light:active, .btn-outline-light:focus {
  background: rgba(255, 255, 255, .12) !important;
  color: #fff !important;
}
.sc-label {
  font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .12em;
  color: rgba(230, 244, 242, .65);
}
.sc-foot { color: rgba(230, 244, 242, .55); }
.sc-foot a { color: rgba(230, 244, 242, .85); font-weight: 600; }
.sc-foot a:hover { color: #fff; }
.recent-list { max-height: 22rem; overflow-y: auto; }
.recent-item { border-bottom: 1px solid rgba(255, 255, 255, .08); }
.recent-item:last-child { border-bottom: 0; }
.today-count { font-size: 1.6rem; font-weight: 900; }
.hover-lift { transition: transform .15s ease, border-color .15s ease; }
.hover-lift:hover { transform: translateY(-2px); border-color: rgba(20, 184, 166, .4) !important; }
</style>
</head>
<body class="scanner-body">
<div class="scanner-shell px-3 py-4">

  <!-- Scanner header -->
  <header class="scanner-header">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= e(base_url($eventLogo)) ?>" alt="<?= e($eventName) ?> logo" width="36" height="36" class="rounded-3 flex-shrink-0" style="background:#fff;padding:.15rem">
      <div class="lh-1 min-width-0">
        <div class="text-uppercase opacity-75" style="font-size:.64rem;letter-spacing:.16em"><?= e($eventName) ?></div>
        <div class="fw-900 fs-5">ENTRY SCANNER</div>
      </div>
    </div>
    <hr class="border-light border-opacity-25 my-2">
    <div class="d-flex justify-content-between align-items-end gap-2">
      <div class="min-width-0">
        <div class="sc-label mb-1">Volunteer</div>
        <div class="fw-bold text-truncate">
          <?= e($volunteer['name']) ?>
          <span class="opacity-75">(<?= e($volunteer['volunteer_id']) ?>)</span>
        </div>
      </div>
      <div class="text-end flex-shrink-0">
        <div class="sc-label mb-1">Assigned Gate</div>
        <div class="fw-900 fs-4"><?= e($volunteer['gate_name']) ?></div>
      </div>
    </div>
  </header>

  <!-- Today stats (Clickable to view participant lists) -->
  <section class="row g-3 mt-3" aria-label="Today statistics">
    <div class="col-6">
      <a href="<?= e(base_url('scanner/participants.php?filter=entered')) ?>" class="text-decoration-none d-block h-100">
        <div class="scanner-panel p-3 h-100 hover-lift">
          <div class="sc-label mb-1" style="color:#5eead4"><i class="bi bi-person-check-fill me-1"></i>Entered Today</div>
          <div class="today-count text-white"><?= $enteredToday ?></div>
          <div class="small sc-foot mt-1" style="font-size:.72rem">Tap to view who came &rarr;</div>
        </div>
      </a>
    </div>
    <div class="col-6">
      <a href="<?= e(base_url('scanner/participants.php?filter=all')) ?>" class="text-decoration-none d-block h-100">
        <div class="scanner-panel p-3 h-100 hover-lift">
          <div class="sc-label mb-1"><i class="bi bi-people me-1"></i>Total Registered</div>
          <div class="today-count text-white opacity-75"><?= $totalRegistered ?></div>
          <div class="small sc-foot mt-1" style="font-size:.72rem">View all students &rarr;</div>
        </div>
      </a>
    </div>
  </section>

  <!-- Recent scans (this volunteer) -->
  <section class="scanner-panel p-3 mt-3" aria-label="Recent scans">
    <div class="d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-clock-history" aria-hidden="true"></i>
      <span class="fw-bold">RECENT SCANS</span>
      <span class="ms-auto small sc-foot">unique <?= count($recentScans) ?></span>
    </div>
    <div class="recent-list">
      <?php if (!$recentScans): ?>
        <div class="text-center py-4 sc-foot">
          <i class="bi bi-qr-code d-block mb-2" style="font-size:1.8rem"></i>
          No scans yet — press START SCANNER.
        </div>
      <?php else: ?>
        <?php foreach ($recentScans as $r): ?>
          <div class="recent-item d-flex justify-content-between align-items-center gap-2 py-2">
            <div class="min-width-0">
              <div class="fw-semibold text-truncate"><?= e($r['student_name'] ?: 'Unknown QR code') ?></div>
              <div class="small sc-foot"><?= e(time_short((string) $r['scan_time'])) ?></div>
            </div>
            <?= status_badge((string) $r['result']) ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- Actions -->
  <div class="d-grid gap-2 mt-3">
    <a href="<?= e(base_url('scanner/scan.php')) ?>" class="btn btn-amber btn-lg btn-block shadow-sm">
      <i class="bi bi-qr-code-scan me-2"></i>START SCANNER
    </a>
    <a href="<?= e(base_url('scanner/participants.php')) ?>" class="btn btn-teal btn-lg btn-block shadow-sm">
      <i class="bi bi-people-fill me-2"></i>PARTICIPANTS LIST (Kon Aaya / Baki)
    </a>
    <a href="<?= e(base_url('scanner/logout.php')) ?>" class="btn btn-outline-light btn-lg btn-block">
      <i class="bi bi-box-arrow-right me-2"></i>LOGOUT
    </a>
  </div>

  <div class="text-center small sc-foot mt-4">
    <a href="<?= e(base_url('index.php')) ?>"><i class="bi bi-arrow-left me-1"></i>Back to event site</a>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
