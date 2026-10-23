<?php

declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Manual participant registration (admin created).
 * Validation, photo upload, optional APPROVED payment record, STUDENT_CREATED audit.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('participant_create');
$activeNav = 'participants';
$pageTitle = 'Add Participant';

$years     = years_list();
$types     = pass_types();
$defaultFee = (string) setting('registration_fee', '300');

$old = [
  'name'            => '',
  'pnr_number'      => '',
  'course'          => '',
  'year'            => 'FY',
  'division'        => '',
  'mobile'          => '',
  'email'           => '',
  'pass_pin'        => '',
  'pass_type'       => 'PAID',
  'pass_status'     => 'ACTIVE',
  'payment_status'  => 'PENDING',
  'required_amount' => $defaultFee,
  'paid_amount'     => '',
  'reason'          => '',
];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_require();

  $old['name']           = trim((string) ($_POST['name'] ?? ''));
  $old['pnr_number']     = preg_replace('~[\s\-]~', '', trim((string) ($_POST['pnr_number'] ?? '')));
  $old['course']         = trim((string) ($_POST['course'] ?? ''));
  $old['year']           = trim((string) ($_POST['year'] ?? ''));
  $old['division']       = trim((string) ($_POST['division'] ?? ''));
  $old['mobile']         = trim((string) ($_POST['mobile'] ?? ''));
  $old['email']          = trim((string) ($_POST['email'] ?? ''));
  $old['pass_pin']       = trim((string) ($_POST['pass_pin'] ?? ''));
  $old['pass_type']      = trim((string) ($_POST['pass_type'] ?? 'PAID'));
  $old['pass_status']    = trim((string) ($_POST['pass_status'] ?? 'ACTIVE'));
  $old['payment_status'] = trim((string) ($_POST['payment_status'] ?? 'PENDING'));
  $old['required_amount'] = trim((string) ($_POST['required_amount'] ?? '0'));
  $old['paid_amount']    = trim((string) ($_POST['paid_amount'] ?? '0'));
  $old['reason']         = trim((string) ($_POST['reason'] ?? ''));

  /* ---- validations ---- */
  if (mb_strlen($old['name']) < 3 || mb_strlen($old['name']) > 100) {
    $errors[] = 'Full name must be 3–100 characters.';
  }
  /* PNR required for student pass types; optional for GUEST / FACULTY / VIP */
  $studentPass = in_array(trim((string) ($_POST['pass_type'] ?? 'PAID')), ['PAID', 'FREE', 'ORGANIZER'], true);
  if ($old['pnr_number'] !== '' && !preg_match('~^[0-9]{10,15}$~', $old['pnr_number'])) {
    $errors[] = 'PNR number must be 10–15 digits (e.g. 2425000000) — or leave it blank for guests / faculty.';
  } elseif ($old['pnr_number'] === '' && $studentPass) {
    $errors[] = 'A college PNR number is required for PAID / FREE / ORGANIZER passes.';
  }
  if (mb_strlen($old['course']) < 2 || mb_strlen($old['course']) > 100) {
    $errors[] = 'Course is required (2–100 characters).';
  }
  if (!isset($years[$old['year']])) {
    $errors[] = 'Please select a valid year.';
  }
  if ($old['division'] !== '' && !preg_match('~^[A-Za-z0-9 /\-]{1,20}$~', $old['division'])) {
    $errors[] = 'Division may only contain letters, numbers, spaces, / and - (max 20 characters).';
  }

  $mobile = preg_replace('~[\s\-()]~', '', $old['mobile']);
  if ($mobile !== '' && !preg_match('~^(\+?91)?[6-9][0-9]{9}$~', $mobile)) {
    $errors[] = 'Please enter a valid 10-digit Indian mobile number (or leave it blank).';
  } elseif ($mobile !== '') {
    $mobile = substr($mobile, -10);
  } else {
    $mobile = null;
  }

  $email = $old['email'];
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address (or leave it blank).';
  }

  if ($old['pass_pin'] !== '' && !preg_match('~^[0-9]{4}$~', $old['pass_pin'])) {
    $errors[] = 'Pass Security PIN must be exactly 4 digits (or leave blank to auto-generate).';
  }

  if (!in_array($old['pass_type'], $types, true)) {
    $errors[] = 'Please select a valid pass type.';
  }
  if (!in_array($old['pass_status'], ['ACTIVE', 'DISABLED'], true)) {
    $errors[] = 'Please select a valid pass status.';
  }

  if ($old['pass_type'] === 'PAID') {
    if (!in_array($old['payment_status'], ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true)) {
      $errors[] = 'Please select a valid payment status for a PAID pass.';
    }
  } else {
    $old['payment_status'] = 'NOT_REQUIRED';
  }

  if (!is_numeric($old['required_amount']) || (float) $old['required_amount'] < 0) {
    $errors[] = 'Required amount must be a number ≥ 0.';
  }
  if (!is_numeric($old['paid_amount']) || (float) $old['paid_amount'] < 0) {
    $errors[] = 'Paid amount must be a number ≥ 0.';
  }

  if ($old['pass_type'] !== 'PAID') {
    $old['required_amount'] = '0';
    $old['paid_amount']     = '0';
    $old['payment_status']  = 'NOT_REQUIRED';
  } elseif ($old['payment_status'] === 'APPROVED' && (float) $old['paid_amount'] <= 0) {
    $errors[] = 'Paid amount is required (greater than 0) when the payment status is APPROVED.';
  }

  if (mb_strlen($old['reason']) < 5 || mb_strlen($old['reason']) > 255) {
    $errors[] = 'A reason for manual registration is required (5–255 characters).';
  }

  /* ---- uniqueness (only when provided) ---- */
  if ($errors === [] && $old['pnr_number'] !== '') {
    $st = db()->prepare('SELECT COUNT(*) FROM students WHERE pnr_number = ?');
    $st->execute([$old['pnr_number']]);
    if ((int) $st->fetchColumn() > 0) {
      $errors[] = 'This PNR number is already registered.';
    }
  }
  if ($errors === [] && $mobile !== null) {
    $st = db()->prepare('SELECT COUNT(*) FROM students WHERE mobile = ?');
    $st->execute([$mobile]);
    if ((int) $st->fetchColumn() > 0) {
      $errors[] = 'This mobile number is already registered.';
    }
  }

  /* ---- optional photo ---- */
  $photoPath = null;
  if ($errors === []) {
    $hasFile = isset($_FILES['photo']) && is_array($_FILES['photo'])
      && (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    if ($hasFile) {
      $up = handle_image_upload('photo', 'students');
      if ($up['ok']) {
        $photoPath = (string) $up['path'];
      } else {
        $errors[] = 'Profile photo: ' . (string) $up['error'];
      }
    }
  }

  /* ---- insert ---- */
  if ($errors === []) {
    $passId = new_pass_id();
    $token  = new_qr_token();

    $plainPin = $old['pass_pin'];
    if ($plainPin === '') {
      if ($mobile !== null && strlen($mobile) >= 4) {
        $plainPin = substr($mobile, -4);
      } elseif ($old['pnr_number'] !== '' && strlen($old['pnr_number']) >= 4) {
        $plainPin = substr($old['pnr_number'], -4);
      } else {
        $plainPin = str_pad((string) random_int(1000, 9999), 4, '0');
      }
    }
    $pinHash = password_hash($plainPin, PASSWORD_DEFAULT);

    $requiredAmount = $old['pass_type'] === 'PAID' ? (float) $old['required_amount'] : 0.0;
    $paidAmount     = ($old['pass_type'] === 'PAID' && $old['payment_status'] === 'APPROVED')
      ? (float) $old['paid_amount'] : 0.0;
    $now = now_db();

    try {
      $ins = db()->prepare(
        'INSERT INTO students
                 (pnr_number, pass_id, name, mobile, email, course, year, division, photo, pass_pin,
                  pass_type, qr_token, pass_status, payment_status, required_amount, paid_amount,
                  entry_status, entry_time, entry_method, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
      );
      $ins->execute([
        $old['pnr_number'] !== '' ? $old['pnr_number'] : null,
        $passId,
        $old['name'],
        $mobile,
        $email !== '' ? $email : '',
        $old['course'],
        $old['year'],
        $old['division'] !== '' ? $old['division'] : '',
        $photoPath,
        $pinHash,
        $old['pass_type'],
        $token,
        $old['pass_status'],
        $old['payment_status'],
        $requiredAmount,
        $paidAmount,
        'NOT_ENTERED',
        null,
        null,
        $now,
        $now,
      ]);
      $studentId = (int) db()->lastInsertId();

      // Approved-at-counter payment → record it in the payments history
      if ($old['pass_type'] === 'PAID' && $old['payment_status'] === 'APPROVED') {
        $pay = db()->prepare(
          "INSERT INTO payments
                     (student_id, utr_number, amount, screenshot, payment_date, status,
                      rejection_reason, verified_by, verified_at, created_at, updated_at)
                     VALUES (?,?,?,NULL,?,'APPROVED',NULL,?,?,?,?)"
        );
        $pay->execute([
          $studentId,
          'MANUAL',
          $paidAmount,
          date('Y-m-d'),
          (int) $admin['id'],
          $now,
          $now,
          $now,
        ]);
      }

      audit_log('STUDENT_CREATED', 'STUDENT', $old['pnr_number'] !== '' ? $old['pnr_number'] : $passId, null, [
        'name'            => $old['name'],
        'pnr_number'      => $old['pnr_number'] !== '' ? $old['pnr_number'] : null,
        'mobile'          => $mobile,
        'course'          => $old['course'],
        'year'            => $old['year'],
        'division'        => $old['division'],
        'pass_type'       => $old['pass_type'],
        'pass_status'     => $old['pass_status'],
        'payment_status'  => $old['payment_status'],
        'required_amount' => $requiredAmount,
        'paid_amount'     => $paidAmount,
        'photo'           => $photoPath,
        'source'          => 'manual admin registration',
      ], $old['reason']);

      set_flash('success', 'Participant ' . $old['name'] . ($old['pnr_number'] !== '' ? ' (PNR ' . $old['pnr_number'] . ')' : ' · ' . $passId) . ' registered successfully. 4-Digit Pass PIN: <strong>' . htmlspecialchars($plainPin) . '</strong>');
      redirect_to('admin/participant-view.php?id=' . $studentId);
    } catch (Throwable $ex) {
      error_log('[FresherParty] manual-register: ' . $ex->getMessage());
      $errors[] = 'Could not save the participant (database error). Please try again.';
    }
  }
}

include __DIR__ . '/../includes/admin-header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-xxl-10">

    <?php if ($errors !== []): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <div class="d-flex gap-2 align-items-start">
          <i class="bi bi-exclamation-triangle-fill mt-1"></i>
          <div>
            <strong>Please fix the following:</strong>
            <ul class="mb-0 mt-1">
              <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(base_url('admin/manual-register.php')) ?>" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>

      <div class="card card-shadow mb-3">
        <div class="card-header bg-white">
          <h2 class="h6 card-header-title mb-0"><i class="bi bi-person-plus me-1 text-teal"></i>Participant Details</h2>
        </div>
        <div class="card-body p-4">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold" for="f-name">Full Name <span class="text-danger">*</span></label>
              <input class="form-control form-control-lg" id="f-name" name="name" required maxlength="100"
                value="<?= e($old['name']) ?>" placeholder="e.g. Aarav Sharma" autocomplete="off">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold" for="f-pnr">College PNR Number <span class="text-muted-2 fw-normal">(unique ID — required for PAID / FREE / ORGANIZER)</span></label>
              <input class="form-control form-control-lg font-monospace" id="f-pnr" name="pnr_number"
                inputmode="numeric" pattern="[0-9]{10,15}" maxlength="15"
                value="<?= e($old['pnr_number']) ?>" placeholder="e.g. 2425000000" autocomplete="off">
              <div class="form-text">The student's college PNR / admission number — searchable everywhere. Leave blank only for guests / faculty / VIP without a college PNR.</div>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-course">Course <span class="text-danger">*</span></label>
              <input class="form-control form-control-lg" id="f-course" name="course" required maxlength="100"
                value="<?= e($old['course']) ?>" placeholder="e.g. BCA" autocomplete="off">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-year">Year <span class="text-danger">*</span></label>
              <select class="form-select form-select-lg" id="f-year" name="year" required>
                <?php foreach ($years as $k => $label): ?>
                  <option value="<?= e($k) ?>" <?= $old['year'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-div">Division <span class="text-muted-2 fw-normal">(optional)</span></label>
              <input class="form-control form-control-lg" id="f-div" name="division" maxlength="20"
                value="<?= e($old['division']) ?>" placeholder="e.g. A" autocomplete="off">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-mobile">Mobile <span class="text-muted-2 fw-normal">(10-digit Indian)</span></label>
              <input class="form-control form-control-lg" id="f-mobile" name="mobile" inputmode="tel" maxlength="14"
                value="<?= e($old['mobile']) ?>" placeholder="e.g. 9876543210" autocomplete="off">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-email">Email <span class="text-muted-2 fw-normal">(optional)</span></label>
              <input class="form-control form-control-lg" id="f-email" name="email" type="email" maxlength="120"
                value="<?= e($old['email']) ?>" placeholder="e.g. aarav@example.com" autocomplete="off">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-pin">Security PIN <span class="text-muted-2 fw-normal">(4 digits · optional)</span></label>
              <input class="form-control form-control-lg font-monospace" id="f-pin" name="pass_pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4"
                value="<?= e($old['pass_pin']) ?>" placeholder="e.g. 1234" autocomplete="off">
              <div class="form-text">Defaults to last 4 digits of mobile if blank.</div>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold" for="f-photo">Profile Photo <span class="text-muted-2 fw-normal">(optional · JPG / PNG / WEBP · max 4 MB)</span></label>
              <div class="d-flex align-items-center gap-3 flex-wrap">
                <img id="photoPreview" class="photo-preview" alt="Selected photo preview">
                <input class="form-control" id="f-photo" name="photo" type="file"
                  accept="image/jpeg,image/png,image/webp" data-photo-preview="#photoPreview">
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-shadow mb-3">
        <div class="card-header bg-white">
          <h2 class="h6 card-header-title mb-0"><i class="bi bi-ticket-perforated me-1 text-teal"></i>Pass &amp; Payment</h2>
        </div>
        <div class="card-body p-4">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="passType">Pass Type <span class="text-danger">*</span></label>
              <select class="form-select form-select-lg" id="passType" name="pass_type">
                <?php foreach ($types as $t): ?>
                  <option value="<?= e($t) ?>" <?= $old['pass_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Non-PAID passes require no payment.</div>
            </div>

            <div class="col-12 col-md-4" id="reqWrap">
              <label class="form-label fw-semibold" for="requiredAmount">Required Amount (<?= e(APP_CURRENCY_SYMBOL) ?>)</label>
              <input class="form-control form-control-lg" id="requiredAmount" name="required_amount" inputmode="decimal"
                value="<?= e($old['required_amount']) ?>" placeholder="e.g. <?= e($defaultFee) ?>">
            </div>

            <div class="col-12 col-md-4" id="payRow">
              <label class="form-label fw-semibold" for="paymentStatus">Payment Status <span class="text-danger">*</span></label>
              <select class="form-select form-select-lg" id="paymentStatus" name="payment_status">
                <option value="PENDING" <?= $old['payment_status'] === 'PENDING'   ? 'selected' : '' ?>>PENDING</option>
                <option value="APPROVED" <?= $old['payment_status'] === 'APPROVED'  ? 'selected' : '' ?>>APPROVED</option>
                <option value="REJECTED" <?= $old['payment_status'] === 'REJECTED'  ? 'selected' : '' ?>>REJECTED</option>
                <option value="CANCELLED" <?= $old['payment_status'] === 'CANCELLED' ? 'selected' : '' ?>>CANCELLED</option>
                <option value="NOT_REQUIRED" <?= $old['payment_status'] === 'NOT_REQUIRED' ? 'selected' : '' ?>>NOT_REQUIRED</option>
              </select>
            </div>

            <div class="col-12 col-md-4 d-none" id="paidWrap">
              <label class="form-label fw-semibold" for="paidAmount">Paid Amount (<?= e(APP_CURRENCY_SYMBOL) ?>) <span class="text-danger">*</span></label>
              <input class="form-control form-control-lg" id="paidAmount" name="paid_amount" inputmode="decimal"
                value="<?= e($old['paid_amount']) ?>" placeholder="Amount actually collected">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="passStatus">Pass Status <span class="text-danger">*</span></label>
              <select class="form-select form-select-lg" id="passStatus" name="pass_status">
                <option value="ACTIVE" <?= $old['pass_status'] === 'ACTIVE'   ? 'selected' : '' ?>>ACTIVE</option>
                <option value="DISABLED" <?= $old['pass_status'] === 'DISABLED' ? 'selected' : '' ?>>DISABLED</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-shadow mb-3">
        <div class="card-header bg-white">
          <h2 class="h6 card-header-title mb-0"><i class="bi bi-journal-text me-1 text-teal"></i>Reason <span class="text-danger">*</span></h2>
        </div>
        <div class="card-body p-4">
          <label class="form-label fw-semibold visually-hidden" for="f-reason">Reason for manual registration</label>
          <textarea class="form-control" id="f-reason" name="reason" required maxlength="255"
            placeholder="Why is this participant being registered manually? (recorded in the audit log)"><?= e($old['reason']) ?></textarea>
          <div class="form-text">This reason is stored in the audit trail together with your admin identity.</div>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <button class="btn btn-teal btn-lg px-4" type="submit">
          <i class="bi bi-check-lg me-1"></i>Create Participant
        </button>
        <a class="btn btn-outline-secondary btn-lg" href="<?= e(base_url('admin/registrations.php')) ?>">Cancel</a>
      </div>
    </form>

  </div>
</div>

<script>
  (function() {
    'use strict';
    var pt = document.getElementById('passType');
    var ra = document.getElementById('requiredAmount');
    var ps = document.getElementById('paymentStatus');
    var pa = document.getElementById('paidAmount');
    var paidWrap = document.getElementById('paidWrap');
    var reqWrap = document.getElementById('reqWrap');
    var payRow = document.getElementById('payRow');
    if (!pt || !ps) {
      return;
    }

    function syncPaid() {
      if (!paidWrap || !pa) {
        return;
      }
      var appr = ps.value === 'APPROVED';
      paidWrap.classList.toggle('d-none', !appr);
      pa.disabled = !appr;
      if (appr && (pa.value === '' || parseFloat(pa.value) === 0)) {
        pa.value = ra && ra.value !== '' ? ra.value : '0';
      }
      if (!appr) {
        pa.value = '0';
      }
    }

    function sync() {
      var isPaid = pt.value === 'PAID';
      reqWrap.classList.toggle('d-none', !isPaid);
      payRow.classList.toggle('d-none', !isPaid);
      if (!isPaid) {
        ra.value = '0';
        ps.value = 'NOT_REQUIRED';
        pa.value = '0';
      }
      var notReq = ps.querySelector('option[value="NOT_REQUIRED"]');
      if (notReq) {
        notReq.disabled = isPaid;
      }
      ra.disabled = !isPaid;
      ps.disabled = !isPaid;
      syncPaid();
    }

    pt.addEventListener('change', sync);
    ps.addEventListener('change', syncPaid);
    sync();
  })();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>