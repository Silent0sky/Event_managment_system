<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Payment submission handler (target of payment.php form).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
secure_session_start();

$back = function (string $pnr): void {
    redirect_to('payment.php?pnr=' . urlencode($pnr));
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_to('index.php');
}

csrf_require();

$pnr     = normalize_pnr($_POST['pnr'] ?? '');
$student = $pnr !== '' ? student_by_pnr($pnr) : null;
if (!$student) {
    set_flash('danger', 'Registration not found — please re-open your payment page from the status check (PNR + mobile).');
    redirect_to('index.php');
}

$settings = get_settings();

// Gate: payment must be open, pass must be PAID & awaiting verification
if ((string) $settings['payment_status'] !== 'OPEN') {
    set_flash('warning', 'Payment submission is currently closed by the organizers.');
    $back($pnr);
}
if ((string) $student['pass_type'] !== 'PAID') {
    redirect_to('status.php?pnr=' . urlencode($pnr));
}
if ((string) $student['payment_status'] === 'APPROVED') {
    set_flash('info', 'Your payment is already approved.');
    redirect_to('status.php?pnr=' . urlencode($pnr));
}
// Block re-submission while a payment is already pending
$stmt = db()->prepare("SELECT COUNT(*) FROM payments WHERE student_id = ? AND status = 'PENDING'");
$stmt->execute([(int) $student['id']]);
if ((int) $stmt->fetchColumn() > 0) {
    set_flash('warning', 'Your payment is already submitted and under verification. Please wait for the result.');
    $back($pnr);
}

/* ----- Validate input ----- */
$errors = [];

$utr = strtoupper(trim((string) ($_POST['utr_number'] ?? '')));
if (!preg_match('~^[A-Z0-9\-]{8,30}$~', $utr)) {
    $errors[] = 'UTR / Transaction ID must be 8–30 letters/digits.';
}
// Duplicate UTR across other students?
$stmt = db()->prepare('SELECT s.pnr_number FROM payments p JOIN students s ON s.id = p.student_id WHERE p.utr_number = ? AND p.student_id <> ? LIMIT 1');
$stmt->execute([$utr, (int) $student['id']]);
$dupe = $stmt->fetch();
if ($dupe) {
    $errors[] = 'This UTR number was already submitted by another registration. Please check your UPI receipt.';
}

$amount = (float) ($_POST['amount'] ?? 0);
if ($amount <= 0 || $amount > 100000) {
    $errors[] = 'Please enter a valid paid amount.';
}

$dateRaw = (string) ($_POST['payment_date'] ?? '');
$d = DateTime::createFromFormat('Y-m-d', $dateRaw);
if (!$d || $d->format('Y-m-d') !== $dateRaw || $dateRaw > date('Y-m-d') || $dateRaw < date('Y-m-d', strtotime('-90 days'))) {
    $errors[] = 'Please enter a valid payment date (not in the future).';
}

$shotPath = null;
$up = handle_image_upload('screenshot', 'payments');
if (!$up['ok']) {
    $errors[] = 'Payment screenshot: ' . $up['error'];
} else {
    $shotPath = $up['path'];
}

if ($errors) {
    set_flash('danger', implode(' ', $errors));
    $back($pnr);
}

/* ----- Save payment (PENDING) ----- */
try {
    $stmt = db()->prepare(
        "INSERT INTO payments (student_id, utr_number, amount, screenshot, payment_date, status, created_at, updated_at)
         VALUES (?,?,?,?,?, 'PENDING', ?, ?)"
    );
    $stmt->execute([(int) $student['id'], $utr, $amount, $shotPath, $dateRaw, now_db(), now_db()]);
    $stmt = db()->prepare("UPDATE students SET payment_status = 'PENDING', updated_at = ? WHERE id = ?");
    $stmt->execute([now_db(), (int) $student['id']]);
    audit_log('STUDENT_PAYMENT_SUBMITTED', 'STUDENT', $pnr, null,
        ['utr' => $utr, 'amount' => $amount, 'date' => $dateRaw]);
    set_flash('success', 'Payment submitted successfully. Your payment will be verified by the organizers.');
    redirect_to('status.php?pnr=' . urlencode($pnr) . '&submitted=1');
} catch (Throwable $ex) {
    error_log('[FresherParty] payment submit: ' . $ex->getMessage());
    set_flash('danger', 'Could not save your payment details. Please try again.');
    $back($pnr);
}
