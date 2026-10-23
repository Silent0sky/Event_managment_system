<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin · Event settings (single row id=1).
 * perm: settings. One big multipart form: event, fee & payment, branding,
 * limits, open/close toggles, contact. Full SETTINGS_CHANGED audit diff.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('settings');

$pv = static function (string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
};

/* ===================== POST HANDLER (before any output) ===================== */
$submitted = null;   /* set when the form must re-render with the submitted values */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    $old = get_settings();

    $eventName    = $pv('event_name');
    $collegeName  = $pv('college_name');
    $eventDate    = $pv('event_date');
    $startTime    = $pv('start_time');
    $endTime      = $pv('end_time');
    $fee          = $pv('registration_fee');
    $currency     = strtoupper($pv('currency', 'INR'));
    $upiId        = $pv('upi_id');
    $maxPart      = $pv('max_participants');
    $regStatus    = strtoupper($pv('registration_status', 'OPEN'));
    $payStatus    = strtoupper($pv('payment_status', 'OPEN'));
    $entryStatus  = strtoupper($pv('entry_status', 'OPEN'));
    $contactNum   = $pv('contact_number');
    $contactEmail = $pv('contact_email');

    /* ---------- validation ---------- */
    $errors = [];
    if ($eventName === '' || mb_strlen($eventName) > 120) {
        $errors[] = 'Event name is required (max 120 characters).';
    }
    if ($collegeName === '' || mb_strlen($collegeName) > 150) {
        $errors[] = 'College name is required (max 150 characters).';
    }
    if ($eventDate !== '') {
        $okDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $eventDate, $dm)
            && checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1]);
        if (!$okDate) {
            $eventDate = '';
        }
    }
    /* Normalise times to HH:MM:SS (accepts HH:MM or HH:MM:SS from browsers) */
    $normalizeTime = static function (string $t) {
        if ($t === '') {
            return null;             /* empty → NULL */
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)
            && (int) $m[1] <= 23 && (int) $m[2] <= 59 && (int) ($m[3] ?? 0) <= 59) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return false;                /* invalid → error below */
    };
    $startTime = $normalizeTime($startTime);
    $endTime   = $normalizeTime($endTime);
    if ($startTime === false || $endTime === false) {
        $errors[] = 'Start / end time must be a valid time (HH:MM).';
        $startTime = $startTime ?: null;
        $endTime   = $endTime ?: null;
    }
    if (!is_numeric($fee) || (float) $fee < 0 || (float) $fee > 99999999) {
        $errors[] = 'Registration fee must be a number of 0 or more.';
    }
    if ($currency === '' || strlen($currency) > 5) {
        $currency = 'INR';
    }
    if (strlen($upiId) > 100) {
        $errors[] = 'UPI ID must be 100 characters or fewer.';
    }
    if (!is_numeric($maxPart) || (int) $maxPart < 1 || (int) $maxPart > 1000000) {
        $errors[] = 'Max participants must be a whole number of 1 or more.';
    }
    if (!in_array($regStatus, ['OPEN', 'CLOSED'], true)) {
        $regStatus = 'OPEN';
    }
    if (!in_array($payStatus, ['OPEN', 'CLOSED'], true)) {
        $payStatus = 'OPEN';
    }
    if (!in_array($entryStatus, ['OPEN', 'CLOSED'], true)) {
        $entryStatus = 'OPEN';
    }
    if (strlen($contactNum) > 20) {
        $errors[] = 'Contact number must be 20 characters or fewer.';
    }
    if ($contactEmail !== '' && (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || strlen($contactEmail) > 120)) {
        $errors[] = 'Contact email is not a valid email address.';
    }

    if ($errors) {
        set_flash('danger', 'Settings not saved: ' . implode(' · ', $errors));
    }

    /* ---------- optional image uploads (keep old path when none) ---------- */
    $images = [];
    $uploadFields = ['payment_qr' => 'Payment QR', 'college_logo' => 'College logo',
                     'event_banner' => 'Event banner', 'event_logo' => 'Event logo'];
    if (!$errors) {
        foreach ($uploadFields as $field => $label) {
            $hasFile = isset($_FILES[$field]) && is_array($_FILES[$field]) && !empty($_FILES[$field]['name']);
            if (!$hasFile) {
                continue; /* nothing chosen → keep current image */
            }
            $up = handle_image_upload($field, 'event');
            if ($up['ok']) {
                $images[$field] = $up['path'];
            } else {
                /* upload attempted but failed → re-render form, keep every old value */
                set_flash('danger', $label . ' upload failed: ' . $up['error'] . ' No other settings were changed.');
                $errors[] = 'image upload';
                break;
            }
        }
    }

    if ($errors) {
        /* Failed validation / upload → re-render the form with the submitted
           text values and the OLD images (nothing was persisted). */
        $submitted = [
            'event_name'          => $eventName,
            'college_name'        => $collegeName,
            'event_date'          => $eventDate !== '' ? $eventDate : null,
            'start_time'          => $startTime,
            'end_time'            => $endTime,
            'registration_fee'    => $fee,
            'currency'            => $currency,
            'upi_id'              => $upiId,
            'payment_qr'          => $images['payment_qr'] ?? $old['payment_qr'],
            'college_logo'        => $images['college_logo'] ?? $old['college_logo'],
            'event_banner'        => $images['event_banner'] ?? $old['event_banner'],
            'event_logo'          => $images['event_logo'] ?? $old['event_logo'],
            'max_participants'    => $maxPart,
            'registration_status' => $regStatus,
            'payment_status'      => $payStatus,
            'entry_status'        => $entryStatus,
            'contact_number'      => $contactNum,
            'contact_email'       => $contactEmail,
        ];
    } else {
        $new = $old;
        $new['event_name']          = $eventName;
        $new['college_name']        = $collegeName;
        $new['event_date']          = $eventDate !== '' ? $eventDate : null;
        $new['start_time']          = $startTime;
        $new['end_time']            = $endTime;
        $new['registration_fee']    = number_format((float) $fee, 2, '.', '');
        $new['currency']            = $currency;
        $new['upi_id']              = $upiId !== '' ? $upiId : null;
        $new['payment_qr']          = $images['payment_qr'] ?? $old['payment_qr'];
        $new['college_logo']        = $images['college_logo'] ?? $old['college_logo'];
        $new['event_banner']        = $images['event_banner'] ?? $old['event_banner'];
        $new['event_logo']          = $images['event_logo'] ?? $old['event_logo'];
        $new['max_participants']    = (int) $maxPart;
        $new['registration_status'] = $regStatus;
        $new['payment_status']      = $payStatus;
        $new['entry_status']        = $entryStatus;
        $new['contact_number']      = $contactNum !== '' ? $contactNum : null;
        $new['contact_email']       = $contactEmail !== '' ? $contactEmail : null;

        try {
            $stmt = db()->prepare(
                'UPDATE event_settings SET
                    event_name = ?, college_name = ?, event_date = ?, start_time = ?, end_time = ?,
                    registration_fee = ?, currency = ?, upi_id = ?,
                    payment_qr = ?, college_logo = ?, event_banner = ?, event_logo = ?,
                    max_participants = ?, registration_status = ?, payment_status = ?, entry_status = ?,
                    contact_number = ?, contact_email = ?, updated_at = ?
                 WHERE id = 1'
            );
            $stmt->execute([
                $new['event_name'], $new['college_name'], $new['event_date'], $new['start_time'], $new['end_time'],
                $new['registration_fee'], $new['currency'], $new['upi_id'],
                $new['payment_qr'], $new['college_logo'], $new['event_banner'], $new['event_logo'],
                $new['max_participants'], $new['registration_status'], $new['payment_status'], $new['entry_status'],
                $new['contact_number'], $new['contact_email'], now_db(),
            ]);
            audit_log('SETTINGS_CHANGED', 'SETTINGS', '1', $old, $new);
            set_flash('success', 'Settings saved. Changes are live immediately on the next page load.');
        } catch (Throwable $ex) {
            error_log('[FresherParty] settings save: ' . $ex->getMessage());
            set_flash('danger', 'Could not save settings. Please try again.');
        }
        redirect_to('admin/settings.php');
    }
}

/* ===================== FORM VALUES ===================== */
/* On a failed save the submitted values re-render; otherwise the saved row. */
$settings = get_settings();
if (is_array($submitted)) {
    $settings = array_merge($settings, $submitted);
}

$val = static function (string $key) use ($settings): string {
    $v = $settings[$key] ?? '';
    return is_scalar($v) ? (string) $v : '';
};
$timeVal = static function (string $key) use ($settings): string {
    $v = $settings[$key] ?? '';
    $v = is_scalar($v) ? (string) $v : '';
    return substr($v, 0, 5);
};

$regStatus = $val('registration_status') ?: 'OPEN';
$payStatus = $val('payment_status') ?: 'OPEN';
$entryStatus = $val('entry_status') ?: 'OPEN';

$activeNav = 'settings';
$pageTitle = 'Settings';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="alert alert-info small d-flex gap-2 align-items-start" role="alert">
  <i class="bi bi-info-circle-fill mt-1"></i>
  <div>Old registrations keep their original <strong>required_amount</strong> — the fee only applies to <strong>NEW</strong> registrations.</div>
</div>

<form method="post" action="<?= e(base_url('admin/settings.php')) ?>" enctype="multipart/form-data" class="needs-validation" novalidate>
  <?= csrf_field() ?>

  <!-- ================= EVENT ================= -->
  <div class="card card-shadow mb-4">
    <div class="card-header bg-white"><span class="card-header-title"><i class="bi bi-calendar-event me-2 text-teal"></i>Event</span></div>
    <div class="card-body p-4">
      <div class="row g-3">
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold" for="event_name">Event name <span class="text-danger">*</span></label>
          <input class="form-control" type="text" id="event_name" name="event_name" required maxlength="120" value="<?= e($val('event_name')) ?>">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold" for="college_name">College name <span class="text-danger">*</span></label>
          <input class="form-control" type="text" id="college_name" name="college_name" required maxlength="150" value="<?= e($val('college_name')) ?>">
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold" for="event_date">Event date</label>
          <input class="form-control" type="date" id="event_date" name="event_date" value="<?= e($val('event_date')) ?>">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label small fw-semibold" for="start_time">Start time</label>
          <input class="form-control" type="time" id="start_time" name="start_time" value="<?= e($timeVal('start_time')) ?>">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label small fw-semibold" for="end_time">End time</label>
          <input class="form-control" type="time" id="end_time" name="end_time" value="<?= e($timeVal('end_time')) ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- ================= FEE & PAYMENT ================= -->
  <div class="card card-shadow mb-4">
    <div class="card-header bg-white"><span class="card-header-title"><i class="bi bi-cash-coin me-2 text-teal"></i>Fee &amp; payment</span></div>
    <div class="card-body p-4">
      <div class="row g-3">
        <div class="col-6 col-md-3">
          <label class="form-label small fw-semibold" for="registration_fee">Registration fee (₹)</label>
          <input class="form-control" type="number" id="registration_fee" name="registration_fee" min="0" step="0.01" required value="<?= e($val('registration_fee')) ?>">
          <div class="form-text">Existing registrations are NOT affected.</div>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small fw-semibold" for="currency">Currency code</label>
          <input class="form-control" type="text" id="currency" name="currency" maxlength="5" value="<?= e($val('currency') ?: 'INR') ?>" placeholder="INR">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold" for="upi_id">UPI ID</label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-qr-code"></i></span>
            <input class="form-control font-monospace" type="text" id="upi_id" name="upi_id" maxlength="100" value="<?= e($val('upi_id')) ?>" placeholder="college@upi">
          </div>
          <div class="form-text">Shown on the payment page with the QR below.</div>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold" for="payment_qr">Payment QR image</label>
          <div class="d-flex align-items-center gap-3 flex-wrap mb-2">
            <img src="<?= e(base_url($val('payment_qr') ?: 'assets/images/payment-qr.png')) ?>" alt="Current payment QR code"
                 class="rounded-3 border" width="110" height="110" style="object-fit:contain;background:#fff">
            <img id="preview-payment-qr" class="photo-preview" alt="New QR preview">
          </div>
          <input class="form-control" type="file" id="payment_qr" name="payment_qr" accept="image/jpeg,image/png,image/webp" data-photo-preview="#preview-payment-qr">
          <div class="form-text">JPG / PNG / WEBP, max 4 MB. Leave empty to keep the current image<?= $val('payment_qr') === '' ? ' (using the default placeholder)' : '' ?>.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= BRANDING ================= -->
  <div class="card card-shadow mb-4">
    <div class="card-header bg-white"><span class="card-header-title"><i class="bi bi-brush me-2 text-teal"></i>Branding</span></div>
    <div class="card-body p-4">
      <div class="row g-4">
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold" for="college_logo">College logo</label>
          <div class="d-flex align-items-center gap-3 mb-2">
            <img src="<?= e(base_url($val('college_logo') ?: 'assets/images/college-logo.png')) ?>" alt="Current college logo"
                 class="rounded-3 border bg-white" width="72" height="72" style="object-fit:contain">
            <img id="preview-college-logo" class="photo-preview" alt="New college logo preview">
          </div>
          <input class="form-control" type="file" id="college_logo" name="college_logo" accept="image/jpeg,image/png,image/webp" data-photo-preview="#preview-college-logo">
          <div class="form-text">Leave empty to keep the current image.</div>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold" for="event_logo">Event logo</label>
          <div class="d-flex align-items-center gap-3 mb-2">
            <img src="<?= e(base_url($val('event_logo') ?: 'assets/images/event-logo.png')) ?>" alt="Current event logo"
                 class="rounded-3 border bg-white" width="72" height="72" style="object-fit:contain">
            <img id="preview-event-logo" class="photo-preview" alt="New event logo preview">
          </div>
          <input class="form-control" type="file" id="event_logo" name="event_logo" accept="image/jpeg,image/png,image/webp" data-photo-preview="#preview-event-logo">
          <div class="form-text">Used in the admin bar &amp; passes.</div>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold" for="event_banner">Event banner</label>
          <div class="d-flex align-items-center gap-3 mb-2">
            <img src="<?= e(base_url($val('event_banner') ?: 'assets/images/event-banner.jpg')) ?>" alt="Current event banner"
                 class="rounded-3 border" width="120" height="68" style="object-fit:cover">
            <img id="preview-event-banner" class="photo-preview" alt="New banner preview">
          </div>
          <input class="form-control" type="file" id="event_banner" name="event_banner" accept="image/jpeg,image/png,image/webp" data-photo-preview="#preview-event-banner">
          <div class="form-text">Wide image (≈ 1340×768) for the homepage hero.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= LIMITS + CONTACT ================= -->
  <div class="card card-shadow mb-4">
    <div class="card-header bg-white"><span class="card-header-title"><i class="bi bi-sliders me-2 text-teal"></i>Limits &amp; contact</span></div>
    <div class="card-body p-4">
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold" for="max_participants">Max participants</label>
          <input class="form-control" type="number" id="max_participants" name="max_participants" min="1" step="1" required value="<?= e((string) (int) $val('max_participants')) ?>">
          <div class="form-text">New registrations stop after this count.</div>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold" for="contact_number">Contact number</label>
          <input class="form-control" type="tel" id="contact_number" name="contact_number" maxlength="20" value="<?= e($val('contact_number')) ?>" placeholder="e.g. 9876543210">
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold" for="contact_email">Contact email</label>
          <input class="form-control" type="email" id="contact_email" name="contact_email" maxlength="120" value="<?= e($val('contact_email')) ?>" placeholder="help@college.edu">
        </div>
      </div>
    </div>
  </div>

  <!-- ================= CONTROLS ================= -->
  <div class="card card-shadow mb-4">
    <div class="card-header bg-white"><span class="card-header-title"><i class="bi bi-toggles me-2 text-teal"></i>Open / close controls</span></div>
    <div class="card-body p-4">
      <div class="row g-4">
        <div class="col-12 col-lg-4">
          <div class="fw-semibold small mb-2">REGISTRATION</div>
          <div class="btn-group w-100" role="group" aria-label="Registration open or closed">
            <input type="radio" class="btn-check" name="registration_status" id="reg-open" value="OPEN" autocomplete="off" <?= $regStatus === 'OPEN' ? 'checked' : '' ?>>
            <label class="btn btn-outline-teal btn-lg" for="reg-open"><i class="bi bi-unlock-fill me-1"></i>OPEN REGISTRATION</label>
            <input type="radio" class="btn-check" name="registration_status" id="reg-close" value="CLOSED" autocomplete="off" <?= $regStatus === 'CLOSED' ? 'checked' : '' ?>>
            <label class="btn btn-outline-danger btn-lg" for="reg-close"><i class="bi bi-lock-fill me-1"></i>CLOSE REGISTRATION</label>
          </div>
          <div class="form-text">Closing stops the public register form for new students.</div>
        </div>
        <div class="col-12 col-lg-4">
          <div class="fw-semibold small mb-2">PAYMENT</div>
          <div class="btn-group w-100" role="group" aria-label="Payment submissions open or closed">
            <input type="radio" class="btn-check" name="payment_status" id="pay-open" value="OPEN" autocomplete="off" <?= $payStatus === 'OPEN' ? 'checked' : '' ?>>
            <label class="btn btn-outline-teal btn-lg" for="pay-open"><i class="bi bi-unlock-fill me-1"></i>OPEN PAYMENT</label>
            <input type="radio" class="btn-check" name="payment_status" id="pay-close" value="CLOSED" autocomplete="off" <?= $payStatus === 'CLOSED' ? 'checked' : '' ?>>
            <label class="btn btn-outline-danger btn-lg" for="pay-close"><i class="bi bi-lock-fill me-1"></i>CLOSE PAYMENT</label>
          </div>
          <div class="form-text">Students can no longer submit payment screenshots.</div>
        </div>
        <div class="col-12 col-lg-4">
          <div class="fw-semibold small mb-2">ENTRY</div>
          <div class="btn-group w-100" role="group" aria-label="Gates entry open or closed">
            <input type="radio" class="btn-check" name="entry_status" id="entry-open" value="OPEN" autocomplete="off" <?= $entryStatus === 'OPEN' ? 'checked' : '' ?>>
            <label class="btn btn-outline-teal btn-lg" for="entry-open"><i class="bi bi-unlock-fill me-1"></i>OPEN ENTRY</label>
            <input type="radio" class="btn-check" name="entry_status" id="entry-close" value="CLOSED" autocomplete="off" <?= $entryStatus === 'CLOSED' ? 'checked' : '' ?>>
            <label class="btn btn-outline-danger btn-lg" for="entry-close"><i class="bi bi-lock-fill me-1"></i>CLOSE ENTRY</label>
          </div>
          <div class="form-text"><strong class="text-danger">Closing entry blocks ALL volunteer scans immediately.</strong></div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-grid mb-4">
    <button type="submit" class="btn btn-teal btn-lg"><i class="bi bi-check-lg me-2"></i>SAVE SETTINGS</button>
  </div>
</form>

<?php include __DIR__ . '/../includes/admin-footer.php';
