<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Participant edit (profile fields only).
 * payment_status is intentionally NOT editable here (use approve/reject/refund actions).
 * Audit: STUDENT_EDITED with old/new diff arrays of changed fields only.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('participant_edit');
$activeNav = 'participants';
$pageTitle = 'Edit Participant';

$years = years_list();
$types = pass_types();

$stId    = (int) ($_GET['id'] ?? 0);
$student = $stId > 0 ? student_by_id($stId) : null;

if (!$student) {
    include __DIR__ . '/../includes/admin-header.php';
    echo '<div class="card card-shadow mx-auto text-center" style="max-width:520px"><div class="card-body p-5">'
        . '<div class="empty-state"><i class="bi bi-person-x"></i>'
        . '<h1 class="h4 fw-bold mt-2 mb-2">Participant not found</h1>'
        . '<p class="text-muted-2 mb-4">No participant exists for this record — search by <strong>PNR number</strong> from the Participants page.</p>'
        . '<a class="btn btn-teal btn-lg" href="' . e(base_url('admin/registrations.php')) . '"><i class="bi bi-arrow-left me-2"></i>Back to Participants</a>'
        . '</div></div></div>';
    include __DIR__ . '/../includes/admin-footer.php';
    exit;
}

$sid   = (int) $student['id'];
$stPnr = (string) ($student['pnr_number'] ?? '');

$old = [
    'name'            => (string) $student['name'],
    'mobile'          => (string) ($student['mobile'] ?? ''),
    'email'           => (string) ($student['email'] ?? ''),
    'pass_pin'        => '',
    'course'          => (string) $student['course'],
    'year'            => (string) $student['year'],
    'division'        => (string) ($student['division'] ?? ''),
    'photo'           => (string) ($student['photo'] ?? ''),
    'pass_type'       => (string) $student['pass_type'],
    'pass_status'     => (string) $student['pass_status'],
    'required_amount' => rtrim(rtrim(number_format((float) $student['required_amount'], 2, '.', ''), '0'), '.'),
    'paid_amount'     => rtrim(rtrim(number_format((float) $student['paid_amount'], 2, '.', ''), '0'), '.'),
];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();

    $old['name']            = trim((string) ($_POST['name'] ?? ''));
    $old['mobile']          = trim((string) ($_POST['mobile'] ?? ''));
    $old['email']           = trim((string) ($_POST['email'] ?? ''));
    $old['pass_pin']        = trim((string) ($_POST['pass_pin'] ?? ''));
    $old['course']          = trim((string) ($_POST['course'] ?? ''));
    $old['year']            = trim((string) ($_POST['year'] ?? ''));
    $old['division']        = trim((string) ($_POST['division'] ?? ''));
    $old['pass_type']       = trim((string) ($_POST['pass_type'] ?? ''));
    $old['pass_status']     = trim((string) ($_POST['pass_status'] ?? ''));
    $old['required_amount'] = trim((string) ($_POST['required_amount'] ?? '0'));
    $old['paid_amount']     = trim((string) ($_POST['paid_amount'] ?? '0'));

    /* ---- validations (same rules as manual registration) ---- */
    if (mb_strlen($old['name']) < 3 || mb_strlen($old['name']) > 100) {
        $errors[] = 'Full name must be 3–100 characters.';
    }
    if ($old['pass_pin'] !== '' && !preg_match('~^[0-9]{4}$~', $old['pass_pin'])) {
        $errors[] = 'Pass Security PIN must be exactly 4 digits (or leave blank to keep unchanged).';
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

    if (!in_array($old['pass_type'], $types, true)) {
        $errors[] = 'Please select a valid pass type.';
    }
    if (!in_array($old['pass_status'], ['ACTIVE', 'DISABLED', 'CANCELLED'], true)) {
        $errors[] = 'Please select a valid pass status.';
    }
    if (!is_numeric($old['required_amount']) || (float) $old['required_amount'] < 0) {
        $errors[] = 'Required amount must be a number ≥ 0.';
    }
    if (!is_numeric($old['paid_amount']) || (float) $old['paid_amount'] < 0) {
        $errors[] = 'Paid amount must be a number ≥ 0.';
    }

    /* ---- uniqueness (excluding the current row) ---- */
    if ($errors === [] && $mobile !== null) {
        $st = db()->prepare('SELECT COUNT(*) FROM students WHERE mobile = ? AND id <> ?');
        $st->execute([$mobile, $sid]);
        if ((int) $st->fetchColumn() > 0) {
            $errors[] = 'This mobile number is already used by another participant.';
        }
    }

    /* ---- optional photo replacement ---- */
    $newPhoto = (string) ($student['photo'] ?? '');
    if ($errors === []) {
        $hasFile = isset($_FILES['photo']) && is_array($_FILES['photo'])
            && (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        if ($hasFile) {
            $up = handle_image_upload('photo', 'students');
            if ($up['ok']) {
                $newPhoto = (string) $up['path'];
            } else {
                $errors[] = 'Profile photo: ' . (string) $up['error'];
            }
        }
    }

    /* ---- update + diff audit ---- */
    if ($errors === []) {
        $newName        = $old['name'];
        $newEmail       = $email !== '' ? $email : '';
        $newDivision    = $old['division'] !== '' ? $old['division'] : '';
        $requiredAmount = (float) $old['required_amount'];
        $paidAmount     = (float) $old['paid_amount'];

        try {
            if ($old['pass_pin'] !== '') {
                $newPinHash = password_hash($old['pass_pin'], PASSWORD_DEFAULT);
                $upd = db()->prepare(
                    'UPDATE students SET name=?, mobile=?, email=?, course=?, year=?, division=?,
                     photo=?, pass_type=?, pass_status=?, required_amount=?, paid_amount=?, pass_pin=?, updated_at=? WHERE id=?'
                );
                $upd->execute([
                    $newName, $mobile, $newEmail, $old['course'], $old['year'], $newDivision,
                    $newPhoto !== '' ? $newPhoto : null, $old['pass_type'], $old['pass_status'],
                    $requiredAmount, $paidAmount, $newPinHash, now_db(), $sid,
                ]);
            } else {
                $upd = db()->prepare(
                    'UPDATE students SET name=?, mobile=?, email=?, course=?, year=?, division=?,
                     photo=?, pass_type=?, pass_status=?, required_amount=?, paid_amount=?, updated_at=? WHERE id=?'
                );
                $upd->execute([
                    $newName, $mobile, $newEmail, $old['course'], $old['year'], $newDivision,
                    $newPhoto !== '' ? $newPhoto : null, $old['pass_type'], $old['pass_status'],
                    $requiredAmount, $paidAmount, now_db(), $sid,
                ]);
            }

            /* Diff of changed fields only */
            $oldDiff = [];
            $newDiff = [];
            if ($old['pass_pin'] !== '') {
                $oldDiff['pass_pin'] = '••••';
                $newDiff['pass_pin'] = 'Reset to new PIN';
            }
            $plainFields = [
                'name'        => [(string) $student['name'], $newName],
                'mobile'      => [(string) ($student['mobile'] ?? ''), $mobile ?? ''],
                'email'       => [(string) ($student['email'] ?? ''), $newEmail],
                'course'      => [(string) $student['course'], $old['course']],
                'year'        => [(string) $student['year'], $old['year']],
                'division'    => [(string) ($student['division'] ?? ''), $newDivision],
                'photo'       => [(string) ($student['photo'] ?? ''), $newPhoto],
                'pass_type'   => [(string) $student['pass_type'], $old['pass_type']],
                'pass_status' => [(string) $student['pass_status'], $old['pass_status']],
            ];
            foreach ($plainFields as $key => [$was, $now]) {
                if ($was !== $now) {
                    $oldDiff[$key] = $was;
                    $newDiff[$key] = $now;
                }
            }
            if ((float) $student['required_amount'] !== $requiredAmount) {
                $oldDiff['required_amount'] = (float) $student['required_amount'];
                $newDiff['required_amount'] = $requiredAmount;
            }
            if ((float) $student['paid_amount'] !== $paidAmount) {
                $oldDiff['paid_amount'] = (float) $student['paid_amount'];
                $newDiff['paid_amount'] = $paidAmount;
            }

            if ($oldDiff !== []) {
                audit_log('STUDENT_EDITED', 'STUDENT', $stPnr !== '' ? $stPnr : (string) $student['pass_id'], $oldDiff, $newDiff);
            }

            set_flash('success', 'Profile updated for ' . $newName . ($stPnr !== '' ? ' (PNR ' . $stPnr . ')' : '') . '.');
            redirect_to('admin/participant-view.php?id=' . $sid);
        } catch (Throwable $ex) {
            error_log('[FresherParty] participant-edit: ' . $ex->getMessage());
            $errors[] = 'Could not save the changes (database error). Please try again.';
        }
    }
}

include __DIR__ . '/../includes/admin-header.php';
?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('admin/registrations.php')) ?>">
    <i class="bi bi-arrow-left me-1"></i>All Participants
  </a>
  <a class="btn btn-outline-teal btn-sm" href="<?= e(base_url('admin/participant-view.php?id=' . $sid)) ?>">
    <i class="bi bi-eye me-1"></i>View Profile
  </a>
</div>

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

    <form method="post" action="<?= e(base_url('admin/participant-edit.php?id=' . $sid)) ?>" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>

      <div class="card card-shadow mb-3">
        <div class="card-header bg-white">
          <h2 class="h6 card-header-title mb-0"><i class="bi bi-person-vcard me-1 text-teal"></i>Participant Details</h2>
        </div>
        <div class="card-body p-4">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold" for="f-pnr-ro">PNR Number <span class="text-muted-2 fw-normal">(unique college ID — read-only)</span></label>
              <div class="form-control form-control-lg font-monospace bg-light d-flex align-items-center"><?= e($stPnr !== '' ? $stPnr : '— (no PNR: guest / faculty pass)') ?></div>
              <div class="form-text">The PNR is the student's universal ID — it cannot be changed here. Delete and re-register if it was entered wrong.</div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold" for="f-name">Full Name <span class="text-danger">*</span></label>
              <input class="form-control form-control-lg" id="f-name" name="name" required maxlength="100"
                     value="<?= e($old['name']) ?>" autocomplete="off">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-course">Course <span class="text-danger">*</span></label>
              <input class="form-control form-control-lg" id="f-course" name="course" required maxlength="100"
                     value="<?= e($old['course']) ?>" autocomplete="off">
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
                     value="<?= e($old['division']) ?>" autocomplete="off">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-mobile">Mobile <span class="text-muted-2 fw-normal">(10-digit Indian)</span></label>
              <input class="form-control form-control-lg" id="f-mobile" name="mobile" inputmode="tel" maxlength="14"
                     value="<?= e($old['mobile']) ?>" autocomplete="off">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-email">Email <span class="text-muted-2 fw-normal">(optional)</span></label>
              <input class="form-control form-control-lg" id="f-email" name="email" type="email" maxlength="120"
                     value="<?= e($old['email']) ?>" autocomplete="off">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="f-pin">Pass Security PIN <span class="text-muted-2 fw-normal">(4 digits · optional)</span></label>
              <input class="form-control form-control-lg font-monospace" id="f-pin" name="pass_pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4"
                     value="<?= e($old['pass_pin']) ?>" placeholder="Leave blank to keep current" autocomplete="off">
              <div class="form-text">Enter new 4-digit PIN to reset.</div>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold" for="f-photo">Profile Photo <span class="text-muted-2 fw-normal">(optional replacement · JPG / PNG / WEBP · max 4 MB)</span></label>
              <div class="d-flex align-items-center gap-3 flex-wrap">
                <?php if ($old['photo'] !== ''): ?>
                  <img src="<?= e(base_url($old['photo'])) ?>" alt="Current photo of <?= e($old['name']) ?>"
                       class="rounded-3 border" style="width:86px;height:86px;object-fit:cover">
                <?php else: ?>
                  <span class="avatar-thumb" style="width:86px;height:86px;font-size:1.7rem"><?= e(mb_strtoupper(mb_substr($old['name'], 0, 1))) ?></span>
                <?php endif; ?>
                <i class="bi bi-arrow-right text-muted-2 d-none d-sm-block"></i>
                <img id="photoPreview" class="photo-preview" alt="New photo preview">
                <input class="form-control" id="f-photo" name="photo" type="file"
                       accept="image/jpeg,image/png,image/webp" data-photo-preview="#photoPreview" style="max-width:280px">
              </div>
              <div class="form-text">Leave empty to keep the current photo.</div>
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
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold" for="passStatus">Pass Status <span class="text-danger">*</span></label>
              <select class="form-select form-select-lg" id="passStatus" name="pass_status">
                <option value="ACTIVE"    <?= $old['pass_status'] === 'ACTIVE'    ? 'selected' : '' ?>>ACTIVE</option>
                <option value="DISABLED"  <?= $old['pass_status'] === 'DISABLED'  ? 'selected' : '' ?>>DISABLED</option>
                <option value="CANCELLED" <?= $old['pass_status'] === 'CANCELLED' ? 'selected' : '' ?>>CANCELLED</option>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">Payment Status <span class="text-muted-2 fw-normal">(read-only)</span></label>
              <div class="form-control form-control-lg d-flex align-items-center justify-content-between bg-light">
                <?= status_badge((string) $student['payment_status']) ?>
                <span class="small text-muted-2">change via actions</span>
              </div>
              <div class="form-text">Payments are verified through the APPROVE / REJECT / REFUND actions on the profile page.</div>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold" for="requiredAmount">Required Amount (<?= e(APP_CURRENCY_SYMBOL) ?>)</label>
              <input class="form-control form-control-lg" id="requiredAmount" name="required_amount" inputmode="decimal"
                     value="<?= e($old['required_amount']) ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold" for="paidAmount">Paid Amount (<?= e(APP_CURRENCY_SYMBOL) ?>)</label>
              <input class="form-control form-control-lg" id="paidAmount" name="paid_amount" inputmode="decimal"
                     value="<?= e($old['paid_amount']) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <button class="btn btn-teal btn-lg px-4" type="submit">
          <i class="bi bi-check-lg me-1"></i>Save Changes
        </button>
        <a class="btn btn-outline-secondary btn-lg" href="<?= e(base_url('admin/participant-view.php?id=' . $sid)) ?>">Cancel</a>
      </div>
    </form>

  </div>
</div>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
