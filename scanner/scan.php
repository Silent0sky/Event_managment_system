<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Gate Scanner (live camera + manual PNR fallback).
 * Runs on Android Chrome over HTTPS. Camera via html5-qrcode 2.3.8,
 * verification via POST /api/verify-pass.php (see assets/js/scanner.js):
 * camera scans send the QR token, the manual box accepts the student's
 * college PNR number (e.g. 2425000000) — the universal student ID.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/volunteer-auth.php';
secure_session_start();

$volunteer = require_volunteer();

$eventName = (string) setting('event_name', 'FRESHERS PARTY 2026');
$eventLogo = (string) setting('event_logo', 'assets/images/event-logo.png');
$entryOpen = (string) setting('entry_status', 'OPEN') === 'OPEN';

/* Server-rendered initial "today's entries" counter */
$stmt = db()->prepare("SELECT COUNT(*) FROM students WHERE entry_status = 'ENTERED' AND entry_time >= ?");
$stmt->execute([today_start_db()]);
$enteredToday = (int) $stmt->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0b1516">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="robots" content="noindex">
<title>Entry Scanner · <?= e($eventName) ?></title>
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
.cam-placeholder {
  position: absolute; inset: 0; z-index: 5;
  display: flex; align-items: center; justify-content: center;
  background: #101d1e; padding: 1.25rem;
}
.cam-placeholder-inner { text-align: center; }
.cam-placeholder-inner > i {
  font-size: 3rem; color: rgba(245, 158, 11, .9);
  display: block; margin-bottom: .5rem;
}
.cam-placeholder-title { font-weight: 900; letter-spacing: .12em; font-size: 1.05rem; }
.cam-placeholder-sub { color: rgba(230, 244, 242, .55); font-size: .78rem; margin: .4rem auto 1rem; max-width: 300px; }
.cam-error {
  color: #fecdd3; background: rgba(225, 29, 72, .16);
  border: 1px solid rgba(225, 29, 72, .4);
  border-radius: .8rem; padding: .6rem .75rem;
  font-size: .78rem; margin: .9rem auto 0; max-width: 340px;
}
.manual-input {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  letter-spacing: .04em;
}
.manual-input::placeholder { letter-spacing: 0; color: rgba(230, 244, 242, .35); }
.scanner-panel .form-control {
  background: rgba(255, 255, 255, .07);
  border: 1px solid rgba(255, 255, 255, .16);
  color: #e6f4f2;
}
.scanner-panel .form-control:focus {
  background: rgba(255, 255, 255, .1);
  border-color: #f59e0b; color: #fff;
  box-shadow: 0 0 0 .2rem rgba(245, 158, 11, .22);
}
#cameraRegion, #reader, #reader * {
  background: #0b1516 !important;
  border-color: rgba(255, 255, 255, .12) !important;
  color: #e6f4f2 !important;
}
#reader video {
  background: #000 !important;
  object-fit: cover;
}
#reader img, #reader canvas {
  background: #0b1516 !important;
}
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
      <div class="ms-auto d-flex align-items-center gap-2 flex-shrink-0">
        <span class="badge text-bg-amber">Gate: <?= e($volunteer['gate_name']) ?></span>
        <a href="<?= e(base_url('scanner/participants.php')) ?>" class="btn btn-sm btn-teal text-white">
          <i class="bi bi-people-fill me-1"></i>List
        </a>
      </div>
    </div>
    <div class="d-flex justify-content-between align-items-center gap-2 mt-2 small">
      <span class="opacity-75 text-truncate">
        <i class="bi bi-person-check me-1"></i><?= e($volunteer['name']) ?> (<?= e($volunteer['volunteer_id']) ?>)
      </span>
      <?php if (!$entryOpen): ?>
        <span class="badge text-bg-danger flex-shrink-0">ENTRY CLOSED</span>
      <?php endif; ?>
    </div>
  </header>

  <!-- Camera region -->
  <section id="cameraRegion" class="mt-3" aria-label="QR code camera scan area">
    <div id="reader"></div>
    <div class="qr-sight" id="qrSight" style="display:none"><div class="sight-box"></div></div>
    <div class="cam-placeholder" id="camPlaceholder">
      <div class="cam-placeholder-inner">
        <i class="bi bi-qr-code-scan" aria-hidden="true"></i>
        <div class="cam-placeholder-title">TAP START SCANNER</div>
        <div class="cam-placeholder-sub">Point the camera at the QR code on the student's pass</div>
        <button type="button" class="btn btn-amber btn-lg" id="btnStartCam">
          <i class="bi bi-camera-video me-2"></i>START SCANNER
        </button>
        <div class="cam-error" id="camError" hidden></div>
      </div>
    </div>
  </section>

  <!-- Controls -->
  <div class="d-grid gap-2 mt-3">
    <button type="button" class="btn btn-amber btn-lg btn-block" id="btnStart">
      <i class="bi bi-camera-video me-2"></i>START SCANNER
    </button>
    <button type="button" class="btn btn-outline-light btn-lg" id="btnStop" hidden>
      <i class="bi bi-stop-circle me-2"></i>STOP SCANNER
    </button>
  </div>

  <!-- Manual fallback: PNR number entry (the universal student ID) -->
  <section class="scanner-panel p-3 mt-3" aria-label="Manual PNR entry">
    <div class="d-flex align-items-center gap-2 mb-2">
      <i class="bi bi-keyboard" aria-hidden="true"></i>
      <span class="fw-bold">QR damaged / no phone? Enter PNR number</span>
    </div>
    <form id="manualForm" novalidate>
      <div class="d-flex flex-wrap flex-sm-nowrap gap-2">
        <input type="text" id="manualPnr" name="pnr" class="form-control manual-input flex-grow-1"
               placeholder="college PNR e.g. 2425000000"
               inputmode="numeric" pattern="[0-9]{10,15}" maxlength="15"
               autocomplete="off" autocapitalize="off" spellcheck="false"
               aria-label="College PNR number (10 to 15 digits)">
        <button type="submit" class="btn btn-teal flex-shrink-0" id="btnManual" style="min-width:90px">SUBMIT</button>
      </div>
      <div class="small sc-foot mt-2 mb-0">The student's PNR number is printed on their pass and on their college ID.</div>
    </form>
  </section>

  <!-- Last scan -->
  <section class="last-scan-card p-3 mt-3" aria-label="Last scan result">
    <div class="d-flex justify-content-between align-items-start gap-2">
      <div class="min-width-0">
        <div class="sc-label mb-1">Last Scan</div>
        <div class="fw-bold text-truncate" id="lastScanName">—</div>
        <div class="small sc-foot mt-1" id="lastScanTime">—</div>
      </div>
      <div class="text-end flex-shrink-0" id="lastScanResult">
        <span class="badge rounded-pill text-bg-secondary fw-semibold">—</span>
      </div>
    </div>
  </section>

  <!-- Today's entries -->
  <section class="scanner-panel p-3 mt-3 d-flex align-items-center justify-content-between" aria-label="Today's entries">
    <div>
      <div class="sc-label mb-1">Today's Entries</div>
      <div class="small sc-foot">Marked since midnight</div>
    </div>
    <div class="today-count" id="todayCount"><?= $enteredToday ?></div>
  </section>

  <!-- Small footer -->
  <footer class="text-center small sc-foot mt-4">
    <a href="<?= e(base_url('scanner/logout.php')) ?>"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
    · Camera requires HTTPS + permission
  </footer>
</div>

<!-- Full-screen scan result overlay -->
<div class="scan-overlay" id="scanOverlay" role="alert" aria-live="assertive">
  <div class="scan-result-card" id="scanResultCard">
    <div class="scan-emoji" id="srEmoji">✓</div>
    <div class="scan-title" id="srTitle">ENTRY APPROVED</div>
    <div class="scan-sub" id="srSub"></div>
    <div class="scan-detail-grid" id="srDetails"></div>
    <div class="scan-dismiss fs-6">Tap anywhere to continue · auto-continue in <span id="srCount" class="fw-bold fs-5 text-warning">4</span>s</div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
<script>
window.FP_SCAN = {
  api: <?= json_encode(base_url('api/verify-pass.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  csrf: <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  gate: <?= json_encode((string) $volunteer['gate_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  volunteer: <?= json_encode((string) $volunteer['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
<script src="<?= e(base_url('assets/js/scanner.js')) ?>"></script>
</body>
</html>
