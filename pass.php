<?php

declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Digital QR entry pass.
 * Opened with the student's PNR number (?pnr=2425000000) — the same ID used
 * for status check and gate search. ?t=<qr_token> also works (admin/guests
 * without a PNR). The QR itself contains ONLY the secure random token
 * (no personal data, no DB id).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
secure_session_start();

$settings = get_settings();

$pnr      = normalize_pnr($_GET['pnr'] ?? ($_GET['id'] ?? ''));
$tokenIn  = strtolower(trim(is_string($_GET['t'] ?? null) ? (string) $_GET['t'] : ''));
$student  = $pnr !== '' ? student_by_pnr($pnr) : null;
if (!$student && $tokenIn !== '' && preg_match('/^[a-f0-9]{32}$/', $tokenIn)) {
  $student = student_by_token($tokenIn);
}
$pnr = $student ? (string) ($student['pnr_number'] ?? '') : $pnr;

$pageTitle = 'Digital Pass · ' . $settings['event_name'];

if (!$student) {
  include __DIR__ . '/includes/header.php';
  echo '<div class="card card-shadow mx-auto mb-4 border-0" style="max-width:440px"><div class="card-body p-4 p-md-5 text-center">';
  echo '<div class="d-inline-flex align-items-center justify-content-center bg-teal bg-opacity-10 text-teal rounded-circle mb-3" style="width:64px;height:64px;font-size:1.8rem;"><i class="bi bi-ticket-perforated"></i></div>';
  echo '<h1 class="h4 fw-bold mb-1">Open Entry Pass</h1>';
  echo '<p class="text-muted small mb-4">Enter your College PNR Number to look up your pass.</p>';
  echo '<form method="get" action="' . e(base_url('pass.php')) . '">';
  echo '<div class="mb-3"><input class="form-control form-control-lg font-monospace text-center fs-4" id="p-pnr" name="pnr" inputmode="numeric" maxlength="15" pattern="[0-9]{10,15}" placeholder="e.g. 2425000000" value="' . e($pnr) . '" required autofocus></div>';
  echo '<button class="btn btn-teal btn-lg w-100 fw-bold" type="submit"><i class="bi bi-arrow-right-circle me-1"></i>Next</button>';
  echo '</form></div></div>';
  include __DIR__ . '/includes/footer.php';
  exit;
}

$paymentStatus = (string) $student['payment_status'];
$eligible      = in_array($paymentStatus, ['APPROVED', 'NOT_REQUIRED'], true);

if (!$eligible) {
  include __DIR__ . '/includes/header.php';
  echo render_steps($paymentStatus === 'PENDING' ? 3 : 3);
  echo '<div class="big-state ' . ($paymentStatus === 'PENDING' ? 'wait' : 'bad') . ' mb-4" style="max-width:520px;margin:0 auto">';
  echo $paymentStatus === 'PENDING'
    ? '<i class="bi bi-hourglass-split"></i> ⏳ Your pass is not available yet — payment is still under verification.'
    : '<i class="bi bi-x-circle"></i> ✕ Payment ' . e(strtolower($paymentStatus)) . ' — pass not issued.';
  echo '</div>';
  echo '<div class="text-center"><a class="btn btn-teal btn-lg" href="' . e(base_url('status.php?pnr=' . urlencode($pnr))) . '"><i class="bi bi-card-checklist me-2"></i>Check Status</a></div>';
  include __DIR__ . '/includes/footer.php';
  exit;
}

$passStatus = (string) $student['pass_status'];
if ($passStatus !== 'ACTIVE') {
  include __DIR__ . '/includes/header.php';
  echo '<div class="big-state bad mb-4" style="max-width:520px;margin:0 auto"><i class="bi bi-slash-circle"></i> This pass has been ' . e(strtolower($passStatus)) . ' by the organizers. Please contact the event desk.</div>';
  echo '<div class="text-center"><a class="btn btn-outline-teal btn-lg" href="' . e(base_url('status.php?pnr=' . urlencode($pnr))) . '">Check Status</a></div>';
  include __DIR__ . '/includes/footer.php';
  exit;
}

/* ---------------- 4-Digit PIN Security Gate ---------------- */
$studentId      = (int) $student['id'];
$isAdminPreview = (current_admin() !== null && isset($_GET['admin_preview']));
$lastVerified   = (int) ($_SESSION['pass_verified_' . $studentId] ?? 0);
$isVerified     = $isAdminPreview || ($lastVerified > 0 && (time() - $lastVerified) < 900);
$pinError       = '';

if (!$isVerified && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['verify_pass_pin'])) {
  csrf_require();
  $enteredPin = trim((string) ($_POST['pass_pin'] ?? ''));
  $pinMatched = false;

  if (!empty($student['pass_pin'])) {
    $pinMatched = password_verify($enteredPin, (string) $student['pass_pin']);
  } else {
    // Fallback for legacy records created before PIN: verify with last 4 digits of registered mobile
    $mobDigits = preg_replace('~[^0-9]~', '', (string) ($student['mobile'] ?? ''));
    $pinMatched = (strlen($mobDigits) >= 4 && substr($mobDigits, -4) === $enteredPin);
  }

  if ($pinMatched) {
    $isVerified = true;
    $_SESSION['pass_verified_' . $studentId] = time();
  } else {
    $pinError = 'Incorrect 4-digit Security PIN. Please try again.';
  }
}

if (!$isVerified) {
  include __DIR__ . '/includes/header.php';
?>
  <div class="card card-shadow mx-auto mb-4 border-0" style="max-width:440px">
    <div class="card-body p-4 p-md-5 text-center">
      <div class="d-inline-flex align-items-center justify-content-center bg-teal bg-opacity-10 text-teal rounded-circle mb-3" style="width:64px;height:64px;font-size:1.8rem;">
        <i class="bi bi-shield-lock-fill"></i>
      </div>
      <h1 class="h4 fw-bold mb-1">Pass Security PIN</h1>
      <p class="text-muted small mb-4">
        Access to entry pass for PNR <strong class="font-monospace text-dark"><?= e($pnr) ?></strong> is protected. Please enter the 4-digit secret PIN set during registration.
      </p>

      <?php if ($pinError !== ''): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-3" role="alert">
          <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
          <div><?= e($pinError) ?></div>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(base_url('pass.php?pnr=' . urlencode($pnr))) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="verify_pass_pin" value="1">
        <div class="mb-4">
          <label class="form-label fw-semibold small text-uppercase" for="p-pin" style="letter-spacing:.06em">4-Digit Security PIN</label>
          <input class="form-control form-control-lg font-monospace text-center fs-2 fw-bold"
            type="password" id="p-pin" name="pass_pin" required
            inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="••••"
            autofocus autocomplete="current-password" style="letter-spacing: .35em;">
          <div class="form-text mt-2 small">
            Enter your 4-digit PIN chosen during registration. (If issued manually by organizers, use the last 4 digits of your registered mobile).
          </div>
        </div>
        <button class="btn btn-teal btn-lg w-100 fw-bold shadow-sm" type="submit">
          <i class="bi bi-unlock me-1"></i>Unlock &amp; View Pass
        </button>
      </form>
      <div class="mt-4 pt-3 border-top">
        <a class="text-secondary small text-decoration-none" href="<?= e(base_url('status.php?pnr=' . urlencode($pnr))) ?>">
          <i class="bi bi-arrow-left me-1"></i>Back to Status Check
        </a>
      </div>
    </div>
  </div>
<?php
  include __DIR__ . '/includes/footer.php';
  exit;
}

$collegeLogo = $settings['college_logo'] ?: 'assets/images/college-logo.png';
$eventLogo   = $settings['event_logo'] ?: 'assets/images/event-logo.png';
$entered     = (string) $student['entry_status'] === 'ENTERED';
$initials    = mb_strtoupper(mb_substr((string) $student['name'], 0, 1) . (mb_substr((string) $student['name'], mb_strlen((string) $student['name']) - 1, 1) ?: ''));

include __DIR__ . '/includes/header.php';
?>
<div class="pass-wrap">

  <div class="card card-shadow pass-card mb-4" id="passCard">
    <div class="pass-head">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <img class="logo img-fluid" src="<?= e(base_url($collegeLogo)) ?>" alt="<?= e($settings['college_name']) ?>" style="max-width:46px;max-height:46px;object-fit:contain">
        <img class="logo img-fluid" src="<?= e(base_url($eventLogo)) ?>" alt="<?= e($settings['event_name']) ?>" style="max-width:46px;max-height:46px;object-fit:contain">
      </div>
      <div class="text-center">
        <div class="small fw-semibold" style="opacity:.85;letter-spacing:.14em"><?= e($settings['college_name']) ?></div>
        <div class="hero-title" style="font-size:1.6rem;margin:.3rem 0"><?= e($settings['event_name']) ?></div>
        <div class="small" style="opacity:.9">
          <?= $settings['event_date'] ? '<i class="bi bi-calendar-event me-1"></i>' . e(date_short((string) $settings['event_date'])) : '' ?>
          <?= $settings['start_time'] ? ' · <i class="bi bi-clock me-1"></i>' . e(date('g:i A', strtotime((string) $settings['start_time']))) : '' ?>
        </div>
      </div>
    </div>

    <div class="pass-divider"></div>

    <div class="p-3 p-md-4 text-center">
      <div class="d-flex align-items-center gap-3 text-start mb-3">
        <?php if ($student['photo']): ?>
          <img class="pass-photo" src="<?= e(base_url((string) $student['photo'])) ?>" alt="Photo of <?= e($student['name']) ?>">
        <?php else: ?>
          <div class="pass-photo-initial"><?= e($initials) ?></div>
        <?php endif; ?>
        <div class="min-width-0">
          <div class="fs-4 fw-900 text-truncate"><?= e($student['name']) ?></div>
          <div class="text-muted-2 small"><?= e((string) $student['course']) ?> · <?= e(years_list()[(string) $student['year']] ?? (string) $student['year']) ?><?= $student['division'] ? ' · Div ' . e((string) $student['division']) : '' ?></div>
          <div class="mt-1"><?= status_badge((string) $student['pass_type']) ?><?= $entered ? ' ' . status_badge('ENTERED') : '' ?></div>
        </div>
      </div>

      <div class="mb-2 small text-muted-2 fw-semibold">PNR NUMBER</div>
      <div class="pass-id-mono mb-1"><?= e((string) ($student['pnr_number'] ?: $student['pass_id'])) ?></div>
      <div class="mb-3 small text-muted-2">Pass ID: <span class="font-monospace"><?= e((string) $student['pass_id']) ?></span></div>

      <div class="qr-tile mb-2">
        <div id="qrBox" data-token="<?= e((string) $student['qr_token']) ?>" data-size="230" aria-label="Entry QR code"></div>
      </div>
      <div class="small text-muted-2"><i class="bi bi-qr-code-scan me-1"></i>Scan this QR at the entry gate</div>
    </div>

    <div class="pass-valid-strip">✓ VALID ENTRY PASS</div>
    <div class="text-center small text-muted-2 p-2 mb-0">
      PNR: <span class="font-monospace"><?= e((string) ($student['pnr_number'] ?: '—')) ?></span> · Carry a valid college ID card · Single entry only
    </div>
  </div>

  <?php if ($entered): ?>
    <div class="big-state ok mb-4"><i class="bi bi-door-open"></i> Entry already recorded <?= e(dt_short((string) $student['entry_time'])) ?>. Re-entry will be denied.</div>
  <?php endif; ?>

  <div class="d-grid d-sm-flex gap-2 no-print">
    <button class="btn btn-teal btn-pass flex-grow-1" type="button" id="btnDownloadPass" data-filename="Fresher-Pass-<?= e((string) $student['pass_id']) ?>">
      <i class="bi bi-download me-2"></i>DOWNLOAD PASS
    </button>
    <button class="btn btn-outline-teal btn-pass flex-grow-1" type="button" id="btnPrintPass">
      <i class="bi bi-printer me-2"></i>PRINT PASS
    </button>
  </div>
  <div class="text-center mt-3 no-print">
    <a class="footer-link small" href="<?= e(base_url('status.php?pnr=' . urlencode($pnr))) ?>"><i class="bi bi-arrow-left me-1"></i>Back to status</a>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>