<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — API: verify a pass and mark the entry.
 *
 * POST only. Requires an ACTIVE volunteer session (JSON-safe auth — never
 * redirects) + CSRF token (X-CSRF-Token header or csrf_token POST field).
 *
 * Body (EITHER):
 *   token=<32 hex chars>   → QR camera scan
 *   pnr=<10–15 digits>     → manual PNR entry at the gate (damaged QR /
 *                            forgotten phone). PNR is the universal student ID.
 * Always replies HTTP 200 (business results) with:
 *  {ok:true, result, message, student:{...}|null, entry:{...}|null, stats:{entered_today}}
 * result ∈ SUCCESS|MANUAL_APPROVED|ALREADY_USED|INVALID|PAYMENT_PENDING|PAYMENT_REJECTED|
 *          PASS_DISABLED|PASS_CANCELLED|ENTRY_CLOSED  (+ defensive ERROR)
 * Errors: 401 (not logged in / disabled), 419 (CSRF), 405 (wrong method).
 *
 * The atomic duplicate protection lives in attempt_entry() (includes/functions.php)
 * — the same guarded UPDATE protects BOTH the QR and the PNR path, so a token
 * scan and a manual PNR entry racing at two gates still yield exactly one entry.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/volunteer-auth.php';

header('Cache-Control: no-store');
secure_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

/* JSON-safe volunteer auth (no redirects) */
$v = current_volunteer();
if (!$v) {
    json_out(['ok' => false, 'message' => 'Volunteer login required.'], 401);
}

/* Re-check the volunteer row is still ACTIVE in the DB */
try {
    $stmt = db()->prepare('SELECT id, volunteer_id, name, gate_name, status FROM volunteers WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $v['id']]);
    $vRow = $stmt->fetch();
} catch (Throwable $ex) {
    error_log('[FresherParty] verify-pass volunteer check failed: ' . $ex->getMessage());
    $vRow = false;
}
if (!$vRow || (string) ($vRow['status'] ?? '') !== 'ACTIVE') {
    json_out(['ok' => false, 'message' => 'Volunteer account is disabled. Login again.'], 401);
}

$volId = (int) $vRow['id'];
$gate  = (string) $vRow['gate_name'];

if (!csrf_verify()) {
    json_out(['ok' => false, 'message' => 'Session expired — reload the page.'], 419);
}

/* ---------- helpers ---------- */

function fp_entered_today(): int
{
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM students WHERE entry_status = 'ENTERED' AND entry_time >= ?");
        $stmt->execute([today_start_db()]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

function fp_student_payload(array $s): array
{
    return [
        'pnr'      => (string) ($s['pnr_number'] ?? ''),
        'name'     => (string) ($s['name'] ?? ''),
        'pass_id'  => (string) ($s['pass_id'] ?? ''),
        'pass_type' => (string) ($s['pass_type'] ?? ''),
        'course'   => (string) ($s['course'] ?? ''),
        'year'     => (string) ($s['year'] ?? ''),
        'division' => (string) ($s['division'] ?? ''),
    ];
}

function fp_scan_payload(string $result, string $message, ?array $student, ?array $entry, int $enteredToday): array
{
    return [
        'ok'      => true,
        'result'  => $result,
        'message' => $message,
        'student' => $student,
        'entry'   => $entry,
        'stats'   => ['entered_today' => $enteredToday],
        'csrf'    => csrf_token(),
    ];
}

/* ---------- input: token (QR) OR pnr (manual) ---------- */

$student   = null;
$scanToken = null;
$r         = null;

$rawToken = $_POST['token'] ?? '';
$token    = is_string($rawToken) ? strtolower(trim($rawToken)) : '';
$rawPnr   = $_POST['pnr'] ?? '';
$pnr      = is_string($rawPnr) ? preg_replace('~[\s\-]~', '', trim($rawPnr)) : '';
$method   = $pnr !== '' ? 'MANUAL' : 'QR';      // PNR lookups are manual entries

/* ---------- global entry control ---------- */

if ((string) setting('entry_status', 'OPEN') !== 'OPEN') {
    entry_log('ENTRY_CLOSED', null, $volId, null, $gate, $token !== '' ? $token : $pnr, $method, 'Entry closed by event admin');
    json_out(fp_scan_payload('ENTRY_CLOSED', 'Entry is currently closed by the event admin.', null, null, fp_entered_today()));
}

/* ---------- student lookup ---------- */

try {
    if ($pnr !== '') {
        /* ---------- MANUAL PNR PATH ---------- */
        if (!preg_match('~^[0-9]{10,15}$~', $pnr)) {
            entry_log('INVALID', null, $volId, null, $gate, substr($pnr, 0, 64), 'MANUAL', 'Malformed PNR');
            json_out(fp_scan_payload('INVALID', 'That PNR number is not valid — it must be 10–15 digits.', null, null, fp_entered_today()));
        }
        $student = student_by_pnr($pnr);
        if (!$student) {
            entry_log('INVALID', null, $volId, null, $gate, $pnr, 'MANUAL', 'PNR not registered');
            json_out(fp_scan_payload('INVALID', 'No registration found for PNR ' . $pnr . '.', null, null, fp_entered_today()));
        }
        $scanToken = null;                     // never log the PNR as a token
    } else {
        /* ---------- QR TOKEN PATH ---------- */
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            entry_log('INVALID', null, $volId, null, $gate, substr($token, 0, 64), 'QR', 'Malformed token');
            json_out(fp_scan_payload('INVALID', 'This QR code is not registered.', null, null, fp_entered_today()));
        }
        $student = student_by_token($token);
        if (!$student) {
            entry_log('INVALID', null, $volId, null, $gate, $token, 'QR', 'Unregistered token');
            json_out(fp_scan_payload('INVALID', 'This QR code is not registered.', null, null, fp_entered_today()));
        }
        $scanToken = $token;
    }
} catch (Throwable $ex) {
    error_log('[FresherParty] verify-pass lookup failed: ' . $ex->getMessage());
    json_out(fp_scan_payload('ERROR', 'Server error while verifying. Please try again.', null, null, fp_entered_today()));
}

/* ---------- atomic entry attempt (same guarded UPDATE for both paths) ---------- */

try {
    $r = attempt_entry($student, $method, $volId, null, $gate, $scanToken, $method === 'MANUAL' ? 'Manual PNR entry at gate' : null);
} catch (Throwable $ex) {
    error_log('[FresherParty] verify-pass attempt_entry failed: ' . $ex->getMessage());
    json_out(fp_scan_payload('ERROR', 'Server error while marking the entry. Please try again.', null, null, fp_entered_today()));
}

$result = (string) ($r['result'] ?? 'ERROR');
$fresh  = is_array($r['student'] ?? null) ? $r['student'] : $student;
$first  = is_array($r['first'] ?? null) ? $r['first'] : null;

$studentPayload = fp_student_payload($fresh);

$messages = [
    'SUCCESS'         => 'ENTRY APPROVED',
    'MANUAL_APPROVED' => 'ENTRY APPROVED',
    'ALREADY_USED'    => 'PASS ALREADY USED',
    'PAYMENT_PENDING' => 'Payment verification is still pending.',
    'PAYMENT_REJECTED' => 'Payment was rejected.',
    'PASS_DISABLED'   => 'Pass disabled by organizers.',
    'PASS_CANCELLED'  => 'Pass cancelled by organizers.',
];

if ($result === 'SUCCESS' || $result === 'MANUAL_APPROVED') {
    /* Fresh, just-marked entry */
    $entryPayload = [
        'time'       => (string) ($fresh['entry_time'] ?? ''),
        'gate'       => $gate,
        'method'     => $method,
        'first_time' => (string) ($first['time'] ?? ($fresh['entry_time'] ?? '')),
        'first_gate' => (string) ($first['gate'] ?? $gate),
        'first_by'   => (string) ($first['by'] ?? ''),
    ];
} elseif ($result === 'ALREADY_USED') {
    /* Not re-marked — carry the FIRST successful entry info */
    $entryPayload = [
        'time'       => '',
        'gate'       => '',
        'method'     => '',
        'first_time' => (string) ($first['time'] ?? ''),
        'first_gate' => (string) ($first['gate'] ?? ''),
        'first_by'   => (string) ($first['by'] ?? ''),
    ];
} else {
    /* Denied (payment/pass problems) — no entry info */
    $entryPayload = null;
}

json_out(fp_scan_payload(
    $result,
    (string) ($messages[$result] ?? 'Scan processed.'),
    $studentPayload,
    $entryPayload,
    fp_entered_today()
));
