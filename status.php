<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Student status page.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
secure_session_start();

$settings = get_settings();
$pnr      = normalize_pnr($_GET['pnr'] ?? ($_GET['id'] ?? ''));   // id kept as legacy alias
$student  = $pnr !== '' ? student_by_pnr($pnr) : null;

$pageTitle = 'Check Status · ' . $settings['event_name'];
include __DIR__ . '/includes/header.php';
?>

<div class="card card-shadow mb-4 mx-auto" style="max-width:720px">
  <div class="card-body p-3 p-md-4">
    <form class="row g-2" method="get" action="<?= e(base_url('status.php')) ?>" role="search">
      <div class="col">
        <label class="form-label small fw-semibold mb-0 visually-hidden" for="s-pnr">College PNR Number</label>
        <div class="input-group input-group-lg">
          <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
          <input class="form-control font-monospace" id="s-pnr" name="pnr" inputmode="numeric"
                 placeholder="Enter your College PNR Number (e.g. 2425000000)" value="<?= e($pnr) ?>"
                 maxlength="15" pattern="[0-9]{10,15}" autocomplete="off">
          <button class="btn btn-teal" type="submit">Check</button>
        </div>
        <div class="form-text mt-1 mb-0">Your PNR number is your registration ID — it works everywhere: status check, pass and gate entry.</div>
      </div>
    </form>
  </div>
</div>

<?php if ($pnr !== '' && !$student): ?>
  <div class="big-state bad mb-4"><i class="bi bi-x-circle"></i> No registration found for PNR <span class="font-monospace"><?= e($pnr) ?></span></div>
<?php elseif ($student):
    $paymentStatus = (string) $student['payment_status'];
    $passOk        = in_array($paymentStatus, ['APPROVED', 'NOT_REQUIRED'], true) && (string) $student['pass_status'] === 'ACTIVE';
    $entered       = (string) $student['entry_status'] === 'ENTERED';
    unset($_SESSION['pass_verified_' . (int) $student['id']]);

    // latest payment row for this student
    $stmt = db()->prepare('SELECT * FROM payments WHERE student_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int) $student['id']]);
    $pay = $stmt->fetch() ?: null;
    $verifier = null;
    if ($pay && $pay['verified_by']) {
        $vs = db()->prepare('SELECT name FROM admins WHERE id = ? LIMIT 1');
        $vs->execute([(int) $pay['verified_by']]);
        $verifier = $vs->fetchColumn() ?: null;
    }
    $firstEntry = $entered ? first_entry_info((int) $student['id']) : null;

    $step = 4;
    if ($paymentStatus === 'PENDING') { $step = 3; }
    elseif (in_array($paymentStatus, ['REJECTED', 'REFUNDED', 'CANCELLED'], true)) { $step = 3; }
    elseif (!$passOk) { $step = 3; }
    elseif ($entered) { $step = 4; }
?>
  <?= render_steps(min($step, 4)) ?>

  <div class="card card-shadow mb-4 mx-auto" style="max-width:720px">
    <div class="card-body p-4">
      <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
        <?php if ($student['photo']): ?>
          <img class="rounded-3" src="<?= e(base_url((string) $student['photo'])) ?>" alt="Photo of <?= e($student['name']) ?>" width="72" height="72" style="object-fit:cover">
        <?php else: ?>
          <div class="pass-photo-initial" style="width:72px;height:72px;font-size:1.4rem"><?= e(mb_strtoupper(mb_substr((string) $student['name'], 0, 1))) ?></div>
        <?php endif; ?>
        <div class="min-width-0">
          <h1 class="h4 fw-bold mb-1 text-truncate"><?= e($student['name']) ?></h1>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <?= status_badge((string) $student['pass_type']) ?>
            <?= status_badge($paymentStatus) ?>
            <?= status_badge((string) $student['entry_status']) ?>
          </div>
        </div>
        <div class="ms-auto text-md-end">
          <div class="small text-muted-2">PNR Number</div>
          <div class="font-monospace fw-bold"><?= e((string) ($student['pnr_number'] ?: '—')) ?></div>
          <div class="small text-muted-2 mt-1">Pass ID</div>
          <div class="font-monospace fw-bold"><?= e((string) $student['pass_id']) ?></div>
        </div>
      </div>
      <hr>
      <div class="row g-3 small">
        <div class="col-6 col-md-3"><div class="text-muted-2">Course</div><strong><?= e((string) $student['course']) ?></strong></div>
        <div class="col-6 col-md-3"><div class="text-muted-2">Year</div><strong><?= e(years_list()[(string) $student['year']] ?? (string) $student['year']) ?></strong></div>
        <?php
        $mob = (string) ($student['mobile'] ?: '');
        $maskedMob = (strlen($mob) >= 10) ? substr($mob, 0, 2) . '******' . substr($mob, -2) : ($mob ?: '—');
        ?>
        <div class="col-6 col-md-3"><div class="text-muted-2">Mobile</div><strong><?= e($maskedMob) ?></strong></div>
        <div class="col-6 col-md-3"><div class="text-muted-2">Required Fee</div><strong><?= e(money($student['required_amount'])) ?></strong></div>
        <div class="col-6 col-md-3"><div class="text-muted-2">Paid</div><strong><?= e(money($student['paid_amount'])) ?></strong></div>
        <div class="col-6 col-md-3"><div class="text-muted-2">Registered On</div><strong><?= e(date_short((string) $student['created_at'])) ?></strong></div>
      </div>
    </div>
  </div>

  <div class="card card-shadow mb-4 mx-auto" style="max-width:720px">
    <div class="card-body p-4">
      <h2 class="h5 fw-bold mb-3"><i class="bi bi-list-check me-2 text-teal"></i>Progress</h2>
      <div class="d-flex flex-column gap-2">

        <div class="status-line sl-ok">
          <span class="sl-icon"><i class="bi bi-check-lg"></i></span>
          <div class="flex-grow-1"><strong>REGISTRATION</strong><div class="small text-muted-2">Completed <?= e(date_short((string) $student['created_at'])) ?></div></div>
          <?= status_badge('APPROVED') ?>
        </div>

        <?php if ($paymentStatus === 'PENDING'): ?>
          <div class="big-state wait"><i class="bi bi-hourglass-split"></i> ⏳ Payment Under Verification</div>
          <div class="small text-muted-2 text-center">Submitted <?= $pay ? e(dt_short((string) $pay['created_at'])) : '—' ?> · UTR <?= $pay ? e((string) $pay['utr_number']) : '—' ?></div>
        <?php elseif ($paymentStatus === 'REJECTED'): ?>
          <div class="big-state bad"><i class="bi bi-x-circle"></i> ✕ Payment Rejected</div>
          <?php if ($pay && $pay['rejection_reason']): ?>
            <div class="alert alert-warning small mb-0"><strong>Reason:</strong> <?= e((string) $pay['rejection_reason']) ?></div>
          <?php endif; ?>
          <div class="text-center">
            <a class="btn btn-outline-teal btn-lg" href="<?= e(base_url('payment.php?pnr=' . urlencode($pnr))) ?>"><i class="bi bi-arrow-repeat me-2"></i>Submit Payment Again</a>
          </div>
        <?php elseif ($paymentStatus === 'NOT_REQUIRED'): ?>
          <div class="status-line sl-ok">
            <span class="sl-icon"><i class="bi bi-check-lg"></i></span>
            <div class="flex-grow-1"><strong>PAYMENT</strong><div class="small text-muted-2">Not required for <?= e((string) $student['pass_type']) ?> passes</div></div>
            <?= status_badge('NOT_REQUIRED') ?>
          </div>
        <?php elseif ($paymentStatus === 'APPROVED'): ?>
          <div class="status-line sl-ok">
            <span class="sl-icon"><i class="bi bi-check-lg"></i></span>
            <div class="flex-grow-1"><strong>PAYMENT</strong><div class="small text-muted-2">Approved <?= $pay ? e(dt_short((string) $pay['verified_at'])) : '' ?><?= $verifier ? ' by ' . e((string) $verifier) : '' ?></div></div>
            <?= status_badge('APPROVED') ?>
          </div>
        <?php else: ?>
          <div class="status-line sl-bad">
            <span class="sl-icon"><i class="bi bi-x-lg"></i></span>
            <div class="flex-grow-1"><strong>PAYMENT</strong><div class="small text-muted-2"><?= e(str_replace('_', ' ', $paymentStatus)) ?></div></div>
            <?= status_badge($paymentStatus) ?>
          </div>
        <?php endif; ?>

        <?php if ($passOk): ?>
          <div class="status-line sl-ok">
            <span class="sl-icon"><i class="bi bi-check-lg"></i></span>
            <div class="flex-grow-1"><strong>PASS</strong><div class="small text-muted-2">Active — valid for entry</div></div>
            <?= status_badge('ACTIVE') ?>
          </div>
        <?php else: ?>
          <div class="status-line sl-<?= in_array($paymentStatus, ['REJECTED', 'REFUNDED', 'CANCELLED'], true) ? 'bad' : 'wait' ?>">
            <span class="sl-icon"><i class="bi bi-ticket-perforated"></i></span>
            <div class="flex-grow-1"><strong>PASS</strong><div class="small text-muted-2">
              <?php if ((string) $student['pass_status'] !== 'ACTIVE'): ?><?= e(strtolower((string) $student['pass_status'])) ?> — contact organizers<?php else: ?>waiting for payment approval<?php endif; ?>
            </div></div>
            <?= status_badge((string) $student['pass_status']) ?>
          </div>
        <?php endif; ?>

        <?php if ($entered): ?>
          <div class="status-line sl-ok">
            <span class="sl-icon"><i class="bi bi-door-open"></i></span>
            <div class="flex-grow-1"><strong>ENTRY</strong><div class="small text-muted-2">Entered <?= e(dt_short((string) $student['entry_time'])) ?><?= $firstEntry ? ' · ' . e((string) $firstEntry['gate']) : '' ?></div></div>
            <?= status_badge('ENTERED') ?>
          </div>
        <?php else: ?>
          <div class="status-line sl-neutral">
            <span class="sl-icon"><i class="bi bi-door-closed"></i></span>
            <div class="flex-grow-1"><strong>ENTRY</strong><div class="small text-muted-2">Not Yet Entered</div></div>
            <?= status_badge('NOT_ENTERED') ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($passOk): ?>
        <div class="d-grid mt-4">
          <button type="button" class="btn btn-teal btn-lg shadow-sm" data-bs-toggle="modal" data-bs-target="#passPinModal">
            <i class="bi bi-shield-lock-fill me-2"></i>VIEW DIGITAL PASS
          </button>
          <div class="text-center text-muted small mt-2">
            <i class="bi bi-key-fill text-teal me-1"></i>Requires your 4-digit Security PIN set during registration
          </div>
        </div>

        <!-- Modal: Pass Security PIN -->
        <div class="modal fade" id="passPinModal" tabindex="-1" aria-labelledby="passPinModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
              <div class="modal-header border-0 pb-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body p-4 pt-1 text-center">
                <div class="d-inline-flex align-items-center justify-content-center bg-teal bg-opacity-10 text-teal rounded-circle mb-3" style="width:64px;height:64px;font-size:1.8rem;">
                  <i class="bi bi-shield-lock-fill"></i>
                </div>
                <h2 class="h5 fw-bold mb-1" id="passPinModalLabel">Pass Security PIN</h2>
                <p class="text-muted small mb-3">
                  Enter the 4-digit secret PIN set during registration to unlock the digital pass for PNR <strong class="text-dark font-monospace"><?= e($pnr) ?></strong>.
                </p>
                <form method="post" action="<?= e(base_url('pass.php?pnr=' . urlencode($pnr))) ?>" autocomplete="off">
                  <?= csrf_field() ?>
                  <input type="hidden" name="verify_pass_pin" value="1">
                  <div class="mb-3">
                    <label class="form-label fw-semibold small text-uppercase" for="modal-pin" style="letter-spacing:.06em">4-Digit Security PIN</label>
                    <input class="form-control form-control-lg font-monospace text-center fs-2 fw-bold"
                           type="password" id="modal-pin" name="pass_pin" required
                           inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="••••"
                           autofocus autocomplete="current-password" style="letter-spacing: .35em;">
                    <div class="form-text mt-2 small text-muted">
                      Enter your 4-digit PIN chosen during registration.
                    </div>
                  </div>
                  <button class="btn btn-teal btn-lg w-100 fw-bold shadow-sm" type="submit">
                    <i class="bi bi-unlock me-1"></i>Unlock &amp; View Pass
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php elseif ($paymentStatus === 'PENDING'): ?>
        <div class="d-grid mt-4">
          <a class="btn btn-outline-teal btn-lg" href="<?= e(base_url('payment.php?pnr=' . urlencode($pnr))) ?>">
            <i class="bi bi-eye me-2"></i>View Payment Details
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
