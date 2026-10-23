<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Payment page (UPI QR + verification form).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
secure_session_start();

$settings = get_settings();
$pnr      = normalize_pnr($_GET['pnr'] ?? '');
$student  = $pnr !== '' ? student_by_pnr($pnr) : null;

$pageTitle = 'Payment · ' . $settings['event_name'];

if (!$student) {
    include __DIR__ . '/includes/header.php';
    echo '<div class="big-state bad mb-4"><i class="bi bi-x-circle"></i> Registration not found.<br><span class="fw-normal small">Check your PNR number and try again.</span></div>';
    echo '<div class="text-center"><a class="btn btn-outline-teal btn-lg" href="' . e(base_url('register.php')) . '"><i class="bi bi-person-plus me-2"></i>Register instead</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// Special passes never see this page
if ((string) $student['pass_type'] !== 'PAID') {
    redirect_to('status.php?pnr=' . urlencode((string) $student['pnr_number']));
}
// Already approved → straight to status/pass
if ((string) $student['payment_status'] === 'APPROVED') {
    set_flash('success', 'Your payment is already approved — your digital pass is ready!');
    redirect_to('status.php?pnr=' . urlencode($pnr));
}

$payOpen     = (string) $settings['payment_status'] === 'OPEN';
$upiAccounts = parse_upi_accounts((string) setting('upi_id', 'example@upi'));
$firstAccount = $upiAccounts[0] ?? ['name' => 'Organizer', 'upi' => 'example@upi'];
$amount      = (float) $student['required_amount'];   // historical amount — never overwritten by fee changes
$qrImage     = $settings['payment_qr'] ?: 'assets/images/payment-qr.png';
$today       = date('Y-m-d');
$whatsappText = "Registration confirmation\nEvent: {$settings['event_name']}\nName: {$student['name']}\nPNR: {$student['pnr_number']}\nRegistration fee: " . money($amount) . "\n\nI am proceeding to payment verification.";
$whatsappUrl  = 'https://wa.me/?text=' . rawurlencode($whatsappText);

// Existing payment states
$pendingPayment = null;
$lastRejected   = null;
try {
    $stmt = db()->prepare("SELECT * FROM payments WHERE student_id = ? ORDER BY id DESC LIMIT 2");
    $stmt->execute([(int) $student['id']]);
    foreach ($stmt->fetchAll() as $p) {
        if ($p['status'] === 'PENDING' && $pendingPayment === null) {
            $pendingPayment = $p;
        }
        if ($p['status'] === 'REJECTED' && $lastRejected === null) {
            $lastRejected = $p;
        }
    }
} catch (Throwable $ex) {
}

include __DIR__ . '/includes/header.php';
?>

<?= render_steps(2) ?>

<div class="card card-shadow mb-4 mx-auto" style="max-width:720px">
  <div class="card-body p-4 p-md-5">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <h1 class="h4 fw-bold mb-0"><i class="bi bi-cash-coin me-2 text-teal"></i>Complete your payment</h1>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge text-bg-teal rounded-pill p-2"><i class="bi bi-hash me-1"></i>PNR <?= e($pnr) ?></span>
        <a class="btn btn-success btn-sm d-inline-flex align-items-center gap-1" href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener">
          <i class="bi bi-whatsapp"></i><span class="d-none d-sm-inline">Share on WhatsApp</span><span class="d-inline d-sm-none">Share</span>
        </a>
      </div>
    </div>
    <p class="text-muted-2">Hello <strong><?= e($student['name']) ?></strong>! Pay the registration fee below using any UPI app, then submit your payment details for verification.</p>

    <!-- UPI & QR SELECTION DROPDOWN -->
    <div class="p-3 mb-4 rounded-3 border bg-light">
      <label for="upiAccountSelect" class="form-label fw-bold text-dark mb-1 d-flex flex-wrap align-items-center gap-1">
        <i class="bi bi-wallet2 text-teal fs-5"></i>
        <span>Select Payee / UPI QR Code (भुगतान के लिए UPI / QR चुनें):</span>
      </label>
      <select id="upiAccountSelect" class="form-select form-select-lg fw-bold text-truncate" style="border: 2px solid var(--fp-teal); max-width: 100%;">
        <?php foreach ($upiAccounts as $idx => $acc): ?>
          <option value="<?= e($acc['upi']) ?>" data-name="<?= e($acc['name']) ?>" <?= $idx === 0 ? 'selected' : '' ?>>
            QR #<?= $idx + 1 ?>: <?= e($acc['name']) ?> (<?= e($acc['upi']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text mt-1 text-muted small">
        <i class="bi bi-arrow-repeat text-teal me-1"></i>Dropdown se payee badalne par QR code aur UPI ID turant update ho jayenge.
      </div>
    </div>

    <div class="row g-4 align-items-center">
      <div class="col-md-5 text-center">
        <div class="qr-frame bg-white p-2 rounded-3 shadow-sm border d-flex align-items-center justify-content-center mx-auto" style="min-height:200px; max-width:250px;">
          <div id="dynamicUpiQr"></div>
        </div>
        <div class="mt-2 small fw-bold text-teal" id="selectedPayeeBadge">
          <i class="bi bi-qr-code me-1"></i>Active: <span id="payeeNameDisplay"><?= e($firstAccount['name']) ?></span>
        </div>
        <div class="small text-muted-2">Scan with PhonePe, GPay, Paytm, BHIM</div>
      </div>
      <div class="col-md-7">
        <div class="mb-3">
          <div class="small text-muted-2 fw-semibold mb-1">Registration Fee (locked for you)</div>
          <div class="display-6 fw-900 text-teal"><?= e(money($amount)) ?></div>
        </div>
        <div class="small text-muted-2 fw-semibold mb-1">Active Payee UPI ID</div>
        <div class="upi-id-box mb-3">
          <span class="text-truncate font-monospace fw-bold" id="activeUpiDisplay"><?= e($firstAccount['upi']) ?></span>
          <button class="btn btn-teal btn-sm flex-shrink-0" type="button" id="copyUpiBtn" data-copy="<?= e($firstAccount['upi']) ?>" data-toast="0">
            <i class="bi bi-clipboard me-1"></i><span data-copy-label>COPY</span>
          </button>
        </div>
        <div class="small text-muted-2 mt-2"><i class="bi bi-info-circle me-1"></i>Scan the QR manually, add <strong><?= e(money($amount)) ?></strong> and pay to the UPI ID above.</div>
      </div>
    </div>
  </div>
</div>

<?php if ($pendingPayment): ?>
  <div class="card card-shadow mb-4 mx-auto" style="max-width:720px">
    <div class="card-body p-4 text-center">
      <div class="big-state wait mb-3"><i class="bi bi-hourglass-split"></i> ⏳ Payment Under Verification</div>
      <p class="mb-1">We received your payment details (UTR <strong><?= e($pendingPayment['utr_number']) ?></strong>, <?= e(money($pendingPayment['amount'])) ?>).</p>
      <p class="text-muted-2 small mb-3">Submitted <?= e(dt_short((string) $pendingPayment['created_at'])) ?>. Your payment will be verified by the organizers within 24–48 hours.</p>
      <a class="btn btn-teal btn-lg" href="<?= e(base_url('status.php?pnr=' . urlencode($pnr))) ?>"><i class="bi bi-card-checklist me-2"></i>Check Status</a>
    </div>
  </div>
<?php elseif (!$payOpen): ?>
  <div class="card card-shadow mb-4 mx-auto" style="max-width:720px">
    <div class="card-body p-4 text-center">
      <div class="big-state wait"><i class="bi bi-pause-circle"></i> Payment submission is temporarily closed.</div>
      <p class="text-muted-2 small mt-2 mb-0">You can pay now and submit your screenshot once submissions reopen. This page will update automatically.</p>
    </div>
  </div>
<?php else: ?>

  <?php if ($lastRejected): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
      <strong><i class="bi bi-exclamation-triangle me-1"></i>Your last submission was rejected.</strong>
      <div class="small mt-1">Reason: <?= e($lastRejected['rejection_reason'] ?: 'Not specified') ?> (UTR <?= e($lastRejected['utr_number']) ?>). Please check and submit again — use the exact UTR of a <em>new successful payment</em> if you paid again.</div>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <div class="card card-shadow mx-auto" style="max-width:720px">
    <div class="card-body p-4 p-md-5">
      <h2 class="h5 fw-bold mb-3"><i class="bi bi-upload me-2 text-teal"></i>Submit payment proof</h2>
      <p class="text-muted-2 small">Enter the transaction reference (UTR) printed in your UPI app's payment receipt and upload a screenshot of the successful payment.</p>
      <form method="post" action="<?= e(base_url('payment-submit.php')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="pnr" value="<?= e($pnr) ?>">
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label fw-semibold" for="p-utr">UTR / Transaction ID <span class="text-danger">*</span></label>
            <input class="form-control form-control-lg" id="p-utr" name="utr_number" required minlength="8" maxlength="30"
                   pattern="[A-Za-z0-9\-]{8,30}" placeholder="e.g. 431298765432" inputmode="numeric">
            <div class="form-text">12-digit reference number from your UPI receipt.</div>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold" for="p-amount">Amount Paid (₹) <span class="text-danger">*</span></label>
            <input class="form-control form-control-lg" id="p-amount" name="amount" required type="number" min="1" max="100000" step="0.01"
                   value="<?= e(number_format($amount, 2, '.', '')) ?>" inputmode="decimal">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold" for="p-date">Payment Date <span class="text-danger">*</span></label>
            <input class="form-control form-control-lg" id="p-date" name="payment_date" required type="date" max="<?= e($today) ?>" value="<?= e($today) ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold" for="p-shot">Payment Screenshot <span class="text-danger">*</span></label>
            <input class="form-control form-control-lg" id="p-shot" name="screenshot" required type="file"
                   accept="image/jpeg,image/png,image/webp">
            <div class="form-text">JPG / PNG / WEBP, max <?= round(MAX_UPLOAD_BYTES / 1048576) ?> MB.</div>
          </div>
          <div class="col-12">
            <button class="btn btn-teal btn-lg btn-block" type="submit">
              <i class="bi bi-send-check me-2"></i>Submit for Verification
            </button>
          </div>
        </div>
      </form>
      <div class="alert alert-light border small mt-3 mb-0">
        <i class="bi bi-shield-check me-1 text-teal"></i>Payments are manually verified by the organizers. Do not submit the same UTR twice — duplicates are rejected.
      </div>
    </div>
  </div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
  'use strict';
  const collegeName = <?= json_encode((string) $settings['college_name']) ?>;
  const amountStr   = <?= json_encode(number_format($amount, 2, '.', '')) ?>;
  const pnrStr      = <?= json_encode($pnr) ?>;

  function buildUpiUri(upiId, name) {
    const note = 'FP26 ' + pnrStr;
    return 'upi://pay?pa=' + encodeURIComponent(upiId) +
           '&pn=' + encodeURIComponent(name + ' - ' + collegeName) +
           '&am=' + encodeURIComponent(amountStr) +
           '&cu=INR' +
           '&tn=' + encodeURIComponent(note);
  }

  const selectEl         = document.getElementById('upiAccountSelect');
  const qrBox            = document.getElementById('dynamicUpiQr');
  const activeUpiDisplay = document.getElementById('activeUpiDisplay');
  const copyUpiBtn       = document.getElementById('copyUpiBtn');
  const payeeNameDisplay = document.getElementById('payeeNameDisplay');

  let qrInstance = null;

  function updatePaymentDetails() {
    if (!selectEl) return;
    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    if (!selectedOpt) return;

    const upiId = selectedOpt.value;
    const name  = selectedOpt.getAttribute('data-name') || 'Organizer';

    if (activeUpiDisplay) activeUpiDisplay.textContent = upiId;
    if (payeeNameDisplay) payeeNameDisplay.textContent = name + ' (' + upiId + ')';
    if (copyUpiBtn) {
      copyUpiBtn.setAttribute('data-copy', upiId);
    }

    const uri = buildUpiUri(upiId, name);

    if (qrBox) {
      qrBox.innerHTML = '';
      if (typeof QRCode !== 'undefined') {
        qrInstance = new QRCode(qrBox, {
          text: uri,
          width: 210,
          height: 210,
          colorDark: '#0b1516',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      }
    }
  }

  if (selectEl) {
    selectEl.addEventListener('change', updatePaymentDetails);
    // Initial render
    setTimeout(updatePaymentDetails, 50);
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
