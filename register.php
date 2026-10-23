<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Student registration.
 * Creates the student record (PAID pass, PENDING payment) and redirects to payment.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
secure_session_start();

$settings = get_settings();
$regOpen  = (string) $settings['registration_status'] === 'OPEN';
$fee      = (string) setting('registration_fee', '300');
$maxP     = (int) setting('max_participants', 500);

$totalRegistered = 0;
try {
    $totalRegistered = (int) db()->query('SELECT COUNT(*) FROM students')->fetchColumn();
} catch (Throwable $ex) {
}
$isFull = $totalRegistered >= $maxP;

$errors = [];
$old    = ['name' => '', 'pnr_number' => '', 'course' => '', 'year' => 'FY', 'division' => '', 'mobile' => '', 'email' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($old as $k => $v) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $old['year'] = in_array($old['year'], array_keys(years_list()), true) ? $old['year'] : '';

    if (!$regOpen) {
        $errors[] = 'Registration is currently closed by the organizers.';
    } elseif ($isFull) {
        $errors[] = 'Registration is currently full. Please contact the organizers.';
    }
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please fill the form again.';
    }
    if (trim((string) ($_POST['website'] ?? '')) !== '') {   // honeypot — bots only
        $errors[] = 'Spam detected.';
    }

    if ($old['name'] === '' || mb_strlen($old['name']) < 3 || mb_strlen($old['name']) > 100) {
        $errors[] = 'Please enter your full name (3–100 characters).';
    } elseif (!preg_match('~^[\p{L}\p{M}\s.\'\-]+$~u', $old['name'])) {
        $errors[] = 'Name can only contain letters, spaces and dots.';
    }
    $old['pnr_number'] = preg_replace('~[\s\-]~', '', $old['pnr_number']);
    if (!preg_match('~^[0-9]{10,15}$~', $old['pnr_number'])) {
        $errors[] = 'Please enter your college PNR number (10–15 digits, e.g. 2425000000) exactly as printed on your admission receipt / college ID.';
    }
    if ($old['course'] === '' || mb_strlen($old['course']) > 100) {
        $errors[] = 'Please enter your course (e.g. BCA Science).';
    }
    if ($old['year'] === '') {
        $errors[] = 'Please select your year.';
    }
    $div = $old['division'];
    if ($div !== '' && !preg_match('~^[A-Za-z0-9 /\-]{1,20}$~', $div)) {
        $errors[] = 'Division looks invalid.';
    }
    $mobile = preg_replace('~[\s\-()]~', '', $old['mobile']);
    if (!preg_match('~^(\+?91)?[6-9][0-9]{9}$~', $mobile)) {
        $errors[] = 'Please enter a valid 10-digit Indian mobile number.';
    } else {
        $mobile = substr($mobile, -10);
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 120) {
        $errors[] = 'Please enter a valid email address.';
    }

    $pin = trim((string) ($_POST['pass_pin'] ?? ''));
    if (!preg_match('~^[0-9]{4}$~', $pin)) {
        $errors[] = 'Please choose a 4-digit numeric Security PIN (e.g. 1234). You will need this PIN to view/download your pass.';
    }

    // Duplicate checks (unique index also protects at DB level)
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE pnr_number = ?');
        $stmt->execute([$old['pnr_number']]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'This PNR number is already registered. Check your status from the home page using your PNR + mobile number.';
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE mobile = ?');
        $stmt->execute([$mobile]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'This mobile number is already registered.';
        }
    } catch (Throwable $ex) {
        $errors[] = 'Could not verify duplicates right now. Please try again.';
    }

    $photoPath = null;
    if (!$errors) {
        $up = handle_image_upload('photo', 'students');
        if (!$up['ok']) {
            $errors[] = 'Profile photo: ' . $up['error'];
        } else {
            $photoPath = $up['path'];
        }
    }

    if (!$errors) {
        $db = db();
        try {
            $passId = new_pass_id();
            $token  = new_qr_token();
            $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                "INSERT INTO students
                   (pnr_number, pass_id, name, mobile, email, course, year, division, photo, pass_pin,
                    pass_type, qr_token, pass_status, payment_status, required_amount, paid_amount,
                    entry_status, entry_method, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?, 'PAID', ?, 'ACTIVE', 'PENDING', ?, 0, 'NOT_ENTERED', NULL, ?, ?)"
            );
            $stmt->execute([
                $old['pnr_number'], $passId, $old['name'], $mobile, $old['email'],
                $old['course'], $old['year'], $div, $photoPath, $pinHash,
                $token, (float) $fee, now_db(), now_db(),
            ]);
            $studentId = (int) $db->lastInsertId();
            audit_log('STUDENT_SELF_REGISTERED', 'STUDENT', $old['pnr_number'], null,
                ['name' => $old['name'], 'pnr_number' => $old['pnr_number'], 'required_amount' => (float) $fee, 'payment_status' => 'PENDING'],
                'Online self-registration');
            redirect_to('payment.php?pnr=' . urlencode($old['pnr_number']));
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'This PNR number or mobile number is already registered.';
            } else {
                error_log('[FresherParty] register insert: ' . $ex->getMessage());
                $errors[] = 'Something went wrong while saving your registration. Please try again.';
            }
        }
    }
}

$pageTitle = 'Register · ' . $settings['event_name'];
include __DIR__ . '/includes/header.php';
?>

<?= render_steps(1) ?>

<?php if (!$regOpen): ?>
  <div class="big-state bad mb-4"><i class="bi bi-slash-circle"></i> Registration is currently closed by the organizers.</div>
<?php elseif ($isFull): ?>
  <div class="big-state wait mb-4"><i class="bi bi-people-fill"></i> Registration is currently full (<?= $maxP ?>/<?= $maxP ?> seats).<br><span class="fw-normal small">Please contact the organizers to be added to the waiting list.</span></div>
<?php else: ?>

<div class="card card-shadow mx-auto" style="max-width:720px">
  <div class="card-body p-4 p-md-5">
    <h1 class="h4 fw-bold mb-1"><i class="bi bi-person-plus me-2 text-teal"></i>Student Registration</h1>
    <p class="text-muted-2 mb-4">Fill the form below to register for <strong><?= e($settings['event_name']) ?></strong>. Registration fee: <strong><?= e(money($fee)) ?></strong> (payable in the next step).</p>

    <?php if ($errors): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Please fix the following:</strong>
        <ul class="mb-0 mt-1 small"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(base_url('register.php')) ?>" enctype="multipart/form-data" novalidate autocomplete="on">
      <?= csrf_field() ?>
      <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="d-none" aria-hidden="true">

      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold" for="f-name">Full Name <span class="text-danger">*</span></label>
          <input class="form-control form-control-lg" id="f-name" name="name" required minlength="3" maxlength="100"
                 value="<?= e($old['name']) ?>" placeholder="e.g. Rahul Patil" autocomplete="name">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="f-pnr">College PNR Number <span class="text-danger">*</span></label>
          <input class="form-control form-control-lg font-monospace" id="f-pnr" name="pnr_number" required
                 inputmode="numeric" pattern="[0-9]{10,15}" maxlength="15"
                 value="<?= e($old['pnr_number']) ?>" placeholder="e.g. 2425000000" autocomplete="off">
          <div class="form-text">Your college PNR / admission number — digits only. This is your <strong>unique ID for everything</strong>: status check, pass and gate entry.</div>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="f-course">Course <span class="text-danger">*</span></label>
          <input class="form-control form-control-lg" id="f-course" name="course" required maxlength="100" list="courses"
                 value="<?= e($old['course']) ?>" placeholder="e.g. BCA Science">
          <datalist id="courses">
            <option value="BCA Science"><option value="BCA"><option value="BBA"><option value="B.Sc Computer Science">
            <option value="B.Sc IT"><option value="B.Com"><option value="BA"><option value="B.Tech">
            <option value="MCA"><option value="MBA"><option value="M.Sc">
          </datalist>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="f-year">Year <span class="text-danger">*</span></label>
          <select class="form-select form-select-lg" id="f-year" name="year" required>
            <?php foreach (years_list() as $code => $label): ?>
              <option value="<?= e($code) ?>" <?= $old['year'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="f-div">Division</label>
          <input class="form-control form-control-lg" id="f-div" name="division" maxlength="20"
                 value="<?= e($old['division']) ?>" placeholder="e.g. A">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="f-mobile">Mobile Number <span class="text-danger">*</span></label>
          <input class="form-control form-control-lg" id="f-mobile" name="mobile" required inputmode="tel" maxlength="14"
                 value="<?= e($old['mobile']) ?>" placeholder="10-digit mobile" autocomplete="tel">
          <div class="form-text">Used by organizers if there is an issue with your payment.</div>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="f-email">Email <span class="text-danger">*</span></label>
          <input class="form-control form-control-lg" id="f-email" name="email" required type="email" maxlength="120"
                 value="<?= e($old['email']) ?>" placeholder="you@example.com" autocomplete="email">
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="f-pin">Create 4-Digit Pass PIN <span class="text-danger">*</span></label>
          <div class="input-group input-group-lg">
            <span class="input-group-text bg-white"><i class="bi bi-shield-lock text-teal"></i></span>
            <input class="form-control font-monospace" id="f-pin" name="pass_pin" required type="password"
                   inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="•••• (4 digits)" autocomplete="new-password">
          </div>
          <div class="form-text">Required to download your pass so others cannot access it.</div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" for="f-photo">Profile Photo <span class="text-danger">*</span></label>
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <img class="photo-preview" id="photoPreview" alt="Photo preview">
            <input class="form-control form-control-lg flex-grow-1" style="min-width:220px" id="f-photo" name="photo" required
                   type="file" accept="image/jpeg,image/png,image/webp" data-photo-preview="#photoPreview">
          </div>
          <div class="form-text">JPG / PNG / WEBP, max <?= round(MAX_UPLOAD_BYTES / 1048576) ?> MB. A clear front-facing photo is printed on your entry pass.</div>
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="f-agree" required>
            <label class="form-check-label small" for="f-agree">
              I confirm the details above are correct and I will pay the registration fee of <strong><?= e(money($fee)) ?></strong> via UPI.
            </label>
          </div>
        </div>
        <div class="col-12">
          <button class="btn btn-teal btn-lg btn-block" type="submit">
            <i class="bi bi-arrow-right-circle me-2"></i>Continue to Payment
          </button>
          <p class="small text-muted-2 mt-2 mb-0 text-center">Your <strong>PNR number is your registration ID</strong> — use it with your mobile number to check status or reopen your pass at any time.</p>
        </div>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
