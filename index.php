<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Home page.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

$settings = get_settings();
$fee      = setting('registration_fee', '300');
$regOpen  = (string) $settings['registration_status'] === 'OPEN';
$payOpen  = (string) $settings['payment_status'] === 'OPEN';

try {
    $counts = db()->query(
        "SELECT
           (SELECT COUNT(*) FROM students) AS total,
           (SELECT COUNT(*) FROM students WHERE payment_status = 'APPROVED') AS paid,
           (SELECT COUNT(*) FROM students WHERE entry_status = 'ENTERED') AS entered"
    )->fetch();
} catch (Throwable $ex) {
    $counts = ['total' => 0, 'paid' => 0, 'entered' => 0];
}
$maxParticipants = (int) setting('max_participants', 500);
$seatsLeft       = max(0, $maxParticipants - (int) $counts['total']);
$full            = $regOpen && $seatsLeft <= 0;

$pageTitle = $settings['event_name'] . ' · Official Registration';
$banner    = $settings['event_banner'] ?: 'assets/images/event-banner.jpg';
include __DIR__ . '/includes/header.php';
?>

<section class="hero-section mb-4" aria-label="Event hero">
  <img class="hero-bg-img" src="<?= e(base_url($banner)) ?>" alt="">
  <div class="hero-content p-4 p-md-5">
    <span class="hero-kicker mb-3"><i class="bi bi-stars"></i><?= e($settings['college_name']) ?></span>
    <h1 class="hero-title mt-3 mb-2"><?= e($settings['event_name']) ?></h1>
    <p class="hero-sub mb-4">"Welcome to the beginning of something unforgettable."</p>

    <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-lg-start hero-actions mb-4">
      <?php if ($regOpen && !$full): ?>
        <a class="btn btn-amber btn-lg px-4 fw-bold" href="<?= e(base_url('register.php')) ?>">
          <i class="bi bi-person-plus me-2"></i>REGISTER NOW
        </a>
      <?php elseif ($regOpen && $full): ?>
        <button class="btn btn-light btn-lg px-4 fw-bold" disabled>
          <i class="bi bi-person-x me-2"></i>Registration is Full
        </button>
      <?php else: ?>
        <button class="btn btn-light btn-lg px-4 fw-bold" disabled>
          <i class="bi bi-slash-circle me-2"></i>Registration Closed
        </button>
      <?php endif; ?>
      <a class="btn btn-outline-light btn-lg px-4" href="<?= e(base_url('status.php')) ?>">
        <i class="bi bi-search me-2"></i>CHECK REGISTRATION
      </a>
      <a class="btn btn-outline-light btn-lg px-4" href="<?= e(base_url('pass.php')) ?>">
        <i class="bi bi-ticket-perforated me-2"></i>VIEW PASS
      </a>
    </div>

    <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-lg-start">
      <div class="hero-chip">
        <div class="chip-label"><i class="bi bi-calendar-event me-1"></i>Event Date</div>
        <div class="chip-value"><?= $settings['event_date'] ? e(date_short((string) $settings['event_date'])) : 'To be announced' ?></div>
      </div>
      <div class="hero-chip">
        <div class="chip-label"><i class="bi bi-clock me-1"></i>Time</div>
        <div class="chip-value">
          <?= $settings['start_time'] ? e(date('g:i A', strtotime((string) $settings['start_time']))) : '—' ?>
          <?= $settings['end_time'] ? ' – ' . e(date('g:i A', strtotime((string) $settings['end_time']))) : '' ?>
        </div>
      </div>
      <div class="hero-chip">
        <div class="chip-label"><i class="bi bi-cash-coin me-1"></i>Registration Fee</div>
        <div class="chip-value"><?= e(money($fee)) ?></div>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================
     STUDENT PORTAL & REGISTRATION SECTION (MOBILE OPTIMIZED)
     ========================================================= -->
<section class="student-portal-section mb-4" aria-label="Student Login and Registration">
  <div class="card card-shadow border-0 overflow-hidden" style="background: linear-gradient(180deg, #ffffff 0%, #f7faf9 100%);">
    <div class="card-body p-3 p-md-4">
      <div class="row align-items-center g-3">
        <div class="col-lg-6">
          <div class="d-flex align-items-center gap-3 mb-2">
            <span class="icon-blob" style="width:48px;height:48px;font-size:1.3rem;">
              <i class="bi bi-mortarboard-fill text-teal"></i>
            </span>
            <div>
              <span class="badge bg-teal-soft text-teal fw-bold px-2 py-1 small text-uppercase">Student Access</span>
              <h2 class="h5 fw-bold mb-0">Student Login &amp; Status Check</h2>
            </div>
          </div>
          <p class="text-muted-2 small mb-0">
            Already registered? Enter your College PNR Number to track payment verification, download your digital QR entry pass, or update payment details.
          </p>
        </div>

        <div class="col-lg-6">
          <form action="<?= e(base_url('status.php')) ?>" method="get" class="d-flex flex-column flex-sm-row gap-2" role="search">
            <div class="input-group input-group-lg flex-grow-1">
              <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
              <input type="text" class="form-control font-monospace border-start-0" id="student_pnr_home" name="pnr"
                     placeholder="Enter College PNR (e.g. 2425000000)" maxlength="15" pattern="[0-9]{10,15}"
                     inputmode="numeric" required autocomplete="off">
            </div>
            <button type="submit" class="btn btn-teal btn-lg px-4 fw-bold">
              <i class="bi bi-arrow-right-circle me-1"></i> Check
            </button>
          </form>
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2 pt-1">
            <small class="text-muted" style="font-size:.78rem;">
              <i class="bi bi-shield-check text-teal me-1"></i>No password required · Use College PNR
            </small>
            <?php if ($regOpen && !$full): ?>
              <a href="<?= e(base_url('register.php')) ?>" class="text-teal small fw-bold text-decoration-none">
                <i class="bi bi-person-plus-fill me-1"></i>New Student? Register Now &rarr;
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="row g-4 mb-4">
  <div class="col-12">
    <div class="card card-shadow h-100">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-signpost-split me-2 text-teal"></i>How it works</h2>
        <div class="row g-3">
          <?php
          $steps = [
              ['bi-person-plus', 'Register', 'Fill the registration form with your details and photo.'],
              ['bi-qr-code', 'Pay via UPI', 'Scan the UPI QR, pay the fee and upload your payment screenshot with the UTR number.'],
              ['bi-hourglass-split', 'Verification', 'Organizers verify your payment within 24–48 hours.'],
              ['bi-ticket-perforated', 'Get your pass', 'Your digital QR entry pass is generated instantly after approval.'],
          ];
          foreach ($steps as $i => $s): ?>
            <div class="col-6 col-lg-3">
              <div class="d-flex flex-column align-items-center text-center h-100">
                <div class="icon-blob mb-2"><i class="bi <?= e($s[0]) ?>"></i></div>
                <div class="fw-bold small"><?= e($s[1]) ?></div>
                <div class="text-muted-2" style="font-size:.78rem"><?= e($s[2]) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card card-shadow h-100">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-info-circle me-2 text-teal"></i>Important instructions</h2>
        <ul class="instruction-list mb-0">
          <li><i class="bi bi-check-circle-fill"></i><span>One registration per student. Your <strong>college PNR number</strong> must be unique.</span></li>
          <li><i class="bi bi-check-circle-fill"></i><span>Pay the exact fee <strong><?= e(money($fee)) ?></strong> via UPI and keep the transaction reference (UTR) handy.</span></li>
          <li><i class="bi bi-check-circle-fill"></i><span>Upload a <strong>clear screenshot</strong> of the successful payment — unclear screenshots delay verification.</span></li>
          <li><i class="bi bi-check-circle-fill"></i><span>Carry your <strong>digital QR pass</strong> and a <strong>college ID card</strong> on event day.</span></li>
          <li><i class="bi bi-check-circle-fill"></i><span>Each pass allows <strong>single entry</strong>. Scanning a pass twice will be denied.</span></li>
          <li><i class="bi bi-exclamation-triangle-fill text-warning"></i><span>Your PNR number is your ID everywhere — keep it handy to check status and open your pass. Do not share your QR pass publicly.</span></li>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card card-shadow h-100">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-telephone me-2 text-teal"></i>Contact &amp; support</h2>
        <?php if ($settings['contact_number']): ?>
          <a class="d-flex align-items-center gap-3 p-3 mb-2 bg-white rounded-3 border" href="tel:<?= e($settings['contact_number']) ?>">
            <span class="icon-blob" style="width:44px;height:44px;font-size:1.1rem"><i class="bi bi-telephone"></i></span>
            <span><span class="d-block small text-muted-2">Call / WhatsApp</span><strong><?= e($settings['contact_number']) ?></strong></span>
          </a>
        <?php endif; ?>
        <?php if ($settings['contact_email']): ?>
          <a class="d-flex align-items-center gap-3 p-3 mb-2 bg-white rounded-3 border" href="mailto:<?= e($settings['contact_email']) ?>">
            <span class="icon-blob" style="width:44px;height:44px;font-size:1.1rem"><i class="bi bi-envelope"></i></span>
            <span class="text-truncate"><span class="d-block small text-muted-2">Email</span><strong><?= e($settings['contact_email']) ?></strong></span>
          </a>
        <?php endif; ?>
        <div class="d-flex align-items-center gap-3 p-3 bg-white rounded-3 border">
          <span class="icon-blob" style="width:44px;height:44px;font-size:1.1rem"><i class="bi bi-graph-up-arrow"></i></span>
          <span>
            <span class="d-block small text-muted-2">Live registration counter</span>
            <strong><?= (int) $counts['total'] ?> registered</strong>
            <span class="text-muted-2 small"> · <?= $seatsLeft ?> seats left</span>
          </span>
        </div>
        <?php if (!$payOpen && $regOpen): ?>
          <div class="alert alert-warning mt-3 mb-0 small"><i class="bi bi-pause-circle me-1"></i>Payment submission is temporarily closed by the organizers. You can still register.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
