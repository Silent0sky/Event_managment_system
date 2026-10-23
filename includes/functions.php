<?php

declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Shared helper library.
 * Loaded by every page: also brings in config, database and auth layers.
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

/* =========================================================
 * Formatting / output
 * ========================================================= */

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function money($n): string
{
    $f = (float) $n;
    $sym = defined('APP_CURRENCY_SYMBOL') ? APP_CURRENCY_SYMBOL : '₹';
    return $sym . number_format($f, (floor($f) === $f) ? 0 : 2);
}

function now_db(): string
{
    return date('Y-m-d H:i:s');
}

function today_start_db(): string
{
    return date('Y-m-d 00:00:00');
}

function dt_short(?string $dt): string
{
    return $dt ? date('j M Y, g:i A', strtotime($dt)) : '—';
}

function time_short(?string $dt): string
{
    return $dt ? date('g:i A', strtotime($dt)) : '—';
}

function date_short(?string $dt): string
{
    return $dt ? date('j M Y', strtotime($dt)) : '—';
}

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', (string) $_SERVER[$k])[0];
            return substr(trim($ip), 0, 45);
        }
    }
    return '0.0.0.0';
}

/* =========================================================
 * URLs / responses / redirects
 * ========================================================= */

function base_url(string $path = ''): string
{
    $p = ltrim($path, '/');
    return BASE_URL . ($p === '' ? '' : '/' . $p);
}

function redirect_to(string $path): void
{
    $url = preg_match('~^https?://~i', $path) ? $path : base_url($path);
    header('Location: ' . $url);
    exit;
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
 * Event settings (single row table, cached per request)
 * ========================================================= */

function get_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $defaults = [
        'id' => 1,
        'event_name' => 'FRESHERS PARTY 2026',
        'college_name' => 'Your College Name',
        'event_date' => null,
        'start_time' => null,
        'end_time' => null,
        'registration_fee' => '300',
        'currency' => 'INR',
        'upi_id' => UPI_ID_DEFAULT,
        'payment_qr' => null,
        'college_logo' => null,
        'event_banner' => null,
        'event_logo' => null,
        'max_participants' => 500,
        'registration_status' => 'OPEN',
        'payment_status' => 'OPEN',
        'entry_status' => 'OPEN',
        'contact_number' => null,
        'contact_email' => null,
    ];
    try {
        $row = db()->query('SELECT * FROM event_settings WHERE id = 1')->fetch();
        $cache = is_array($row) ? array_merge($defaults, $row) : $defaults;
    } catch (Throwable $ex) {
        $cache = $defaults;
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $v = get_settings()[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

/**
 * Parse raw upi_id setting (supports JSON array, comma-separated list,
 * newline-separated, or single string) into a list of UPI accounts.
 *
 * @param string|null $raw
 * @return array<int, array{name: string, upi: string}>
 */
function parse_upi_accounts(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [['name' => 'Organizer', 'upi' => 'example@upi']];
    }

    $list = [];
    if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $list = $decoded;
        }
    }

    if (empty($list)) {
        $cleaned = trim($raw, '[]');
        $parts = preg_split('/[\r\n,]+/', $cleaned);
        if (is_array($parts)) {
            $list = $parts;
        }
    }

    $results = [];
    foreach ($list as $item) {
        $clean = is_string($item) ? $item : (string) ($item['upi'] ?? '');
        $clean = trim($clean, " \t\n\r\0\x0B\"'\\");
        if ($clean === '') {
            continue;
        }
        $name = '';
        if (preg_match('/^(.*?)\((.*?)\)$/', $clean, $m)) {
            $name  = trim($m[1]);
            $clean = trim($m[2]);
        }
        if ($name === '') {
            $parts = explode('@', $clean);
            $name  = ucfirst($parts[0] ?? 'UPI');
        }
        $results[] = [
            'name' => $name,
            'upi'  => $clean,
        ];
    }

    return !empty($results) ? $results : [['name' => 'Organizer', 'upi' => 'example@upi']];
}

function pass_types(): array
{
    return ['PAID', 'FREE', 'GUEST', 'ORGANIZER', 'FACULTY', 'VIP'];
}

function gates(): array
{
    return ['Gate 1', 'Gate 2', 'Gate 3', 'VIP Gate', 'Faculty Gate'];
}

function years_list(): array
{
    return ['FY' => 'First Year', 'SY' => 'Second Year', 'TY' => 'Third Year', 'PG' => 'Post Graduate'];
}

/* =========================================================
 * ID / token generation (uniqueness-checked)
 * pnr_number is NOT generated — it is the student's own college
 * PNR / admission number (e.g. 2425000000), captured at registration.
 * ========================================================= */

/** Validate + normalise a college PNR number. '' when invalid. */
function normalize_pnr($raw): string
{
    $pnr = preg_replace('~[\s\-]~', '', is_string($raw) ? trim($raw) : '');
    return preg_match('~^[0-9]{10,15}$~', $pnr) ? $pnr : '';
}

function pnr_is_valid(string $pnr): bool
{
    return (bool) preg_match('~^[0-9]{10,15}$~', $pnr);
}

function new_pass_id(): string
{
    $charset = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE pass_id = ?');
    for ($i = 0; $i < 25; $i++) {
        $id = REG_PREFIX . '-';
        for ($j = 0; $j < 6; $j++) {
            $id .= $charset[random_int(0, strlen($charset) - 1)];
        }
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $id;
        }
    }
    throw new RuntimeException('Unable to generate a unique pass ID.');
}

function new_qr_token(): string
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE qr_token = ?');
    for ($i = 0; $i < 25; $i++) {
        $token = bin2hex(random_bytes(16));
        $stmt->execute([$token]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $token;
        }
    }
    throw new RuntimeException('Unable to generate a unique QR token.');
}

/* =========================================================
 * Secure image uploads (photos / payment screenshots / event assets)
 * ========================================================= */

function handle_image_upload(string $field, string $subdir, int $maxBytes = MAX_UPLOAD_BYTES): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return ['ok' => false, 'error' => 'No file was uploaded.', 'path' => null];
    }
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please choose an image file.', 'path' => null];
    }
    if (($f['error'] ?? -1) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed (PHP error code ' . (int) $f['error'] . ').', 'path' => null];
    }
    if (($f['size'] ?? 0) <= 0 || (int) $f['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'Image must be smaller than ' . round($maxBytes / 1048576) . ' MB.', 'path' => null];
    }
    $tmp = (string) $f['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload source.', 'path' => null];
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string) finfo_file($fi, $tmp) : '';
        if ($fi) {
            finfo_close($fi);
        }
    }
    if ($mime === '' && function_exists('getimagesize')) {
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    }
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG or WEBP images are allowed.', 'path' => null];
    }
    if (@getimagesize($tmp) === false) {
        return ['ok' => false, 'error' => 'The file is not a valid image.', 'path' => null];
    }

    $ext  = $allowed[$mime];
    $dir  = APP_ROOT . '/uploads/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => 'Upload folder is missing. Run install.php again.', 'path' => null];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;   // fully random — no user-controlled filename
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Could not save the uploaded file (folder permissions?).', 'path' => null];
    }
    @chmod($dest, 0644);
    return ['ok' => true, 'error' => null, 'path' => 'uploads/' . $subdir . '/' . $name];
}

/* =========================================================
 * Flash messages (bootstrap alerts)
 * ========================================================= */

function set_flash(string $type, string $message): void
{
    secure_session_start();
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function render_flashes(): void
{
    secure_session_start();
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    if (!is_array($flashes) || !$flashes) {
        return;
    }
    $allowed = ['success', 'danger', 'warning', 'info'];
    foreach ($flashes as $f) {
        $type = in_array($f['type'], $allowed, true) ? $f['type'] : 'info';
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show shadow-sm" role="alert">'
            . '<div class="d-flex gap-2 align-items-start"><i class="bi bi-info-circle-fill mt-1"></i><div>'
            . e($f['msg']) . '</div></div>'
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
}

/* =========================================================
 * Badges & student progress indicator
 * ========================================================= */

function status_badge(string $status): string
{
    static $classes = [
        // statuses
        'APPROVED' => 'success',
        'ACTIVE' => 'success',
        'SUCCESS' => 'success',
        'ENTERED' => 'success',
        'MANUAL_APPROVED' => 'success',
        'NOT_ENTERED' => 'secondary',
        'NOT_REQUIRED' => 'teal',
        'PENDING' => 'warning',
        'PAYMENT_PENDING' => 'warning',
        'REJECTED' => 'danger',
        'REFUNDED' => 'dark',
        'CANCELLED' => 'danger',
        'DISABLED' => 'danger',
        'ALREADY_USED' => 'danger',
        'INVALID' => 'danger',
        'PAYMENT_REJECTED' => 'danger',
        'PASS_DISABLED' => 'danger',
        'PASS_CANCELLED' => 'danger',
        'ENTRY_CLOSED' => 'warning',
        'OPEN' => 'success',
        'CLOSED' => 'danger',
        // pass types
        'PAID' => 'teal',
        'FREE' => 'success',
        'GUEST' => 'amber',
        'ORGANIZER' => 'teal',
        'FACULTY' => 'stone',
        'VIP' => 'gold',
        // roles
        'SUPER_ADMIN' => 'dark',
        'EVENT_ADMIN' => 'teal',
        'PAYMENT_ADMIN' => 'amber',
        'ENTRY_MANAGER' => 'stone',
    ];
    $cls = $classes[$status] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $cls . ' fw-semibold">' . e(str_replace('_', ' ', $status)) . '</span>';
}

/**
 * Renders the 4-step student progress indicator.
 * @param int $current 1..4
 * @return string
 */
function render_steps(int $current): string
{
    $steps = [
        1 => ['Registration', 'bi-person-plus'],
        2 => ['Payment', 'bi-cash-coin'],
        3 => ['Verification', 'bi-hourglass-split'],
        4 => ['Pass', 'bi-ticket-perforated'],
    ];
    $html = '<div class="step-indicator mb-4" role="progressbar" aria-label="Registration progress" aria-valuemin="1" aria-valuemax="4" aria-valuenow="' . $current . '">';
    foreach ($steps as $n => $meta) {
        $state = $n < $current ? 'done' : ($n === $current ? 'active' : '');
        $html .= '<div class="step ' . $state . '">'
            . '<span class="step-dot">' . ($n < $current ? '<i class="bi bi-check-lg"></i>' : (string) $n) . '</span>'
            . '<span class="step-label">' . e($meta[0]) . '</span></div>';
    }
    $html .= '</div>';
    return $html;
}

/* =========================================================
 * Audit log & entry log
 * ========================================================= */

function audit_log(string $action, ?string $targetType = null, ?string $targetId = null, $old = null, $new = null, ?string $reason = null, ?string $userType = null, ?int $userId = null): void
{
    try {
        if ($userType === null) {
            if (current_admin()) {
                $userType = 'ADMIN';
                $userId  = (int) current_admin()['id'];
            } elseif (current_volunteer()) {
                $userType = 'VOLUNTEER';
                $userId  = (int) current_volunteer()['id'];
            } else {
                $userType = 'SYSTEM';
                $userId  = null;
            }
        }
        $enc = function ($v): ?string {
            if ($v === null) {
                return null;
            }
            return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        };
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_type, user_id, action, target_type, target_id, old_value, new_value, reason, ip_address)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $userType,
            $userId,
            $action,
            $targetType,
            $targetId,
            $enc($old),
            $enc($new),
            $reason,
            client_ip(),
        ]);
    } catch (Throwable $ignored) {
        error_log('[FresherParty] audit_log failed: ' . $ignored->getMessage());
    }
}

function entry_log(string $result, ?int $studentId = null, ?int $volunteerId = null, ?int $adminId = null, ?string $gate = null, ?string $scanToken = null, ?string $method = null, ?string $reason = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO entry_logs (student_id, volunteer_id, admin_id, gate_name, scan_token, result, entry_method, reason, scan_time, ip_address)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $studentId,
            $volunteerId,
            $adminId,
            $gate,
            $scanToken !== null ? substr($scanToken, 0, 64) : null,
            $result,
            $method,
            $reason,
            now_db(),
            client_ip(),
        ]);
    } catch (Throwable $ignored) {
        error_log('[FresherParty] entry_log failed: ' . $ignored->getMessage());
    }
}

/* =========================================================
 * Student lookups
 * ========================================================= */

function student_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function student_by_pnr(string $pnr): ?array
{
    $pnr = preg_replace('~[\s\-]~', '', trim($pnr));
    if (!preg_match('~^[0-9]{10,15}$~', $pnr)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM students WHERE pnr_number = ? LIMIT 1');
    $stmt->execute([$pnr]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function student_by_token(string $token): ?array
{
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM students WHERE qr_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/* =========================================================
 * ENTRY ENGINE — eligibility, atomic mark, reset
 * ========================================================= */

/** '' = eligible. Otherwise one of PASS_DISABLED|PASS_CANCELLED|PAYMENT_PENDING|PAYMENT_REJECTED */
function check_entry_eligibility(array $student): string
{
    $pass = (string) ($student['pass_status'] ?? '');
    if ($pass === 'DISABLED') {
        return 'PASS_DISABLED';
    }
    if ($pass === 'CANCELLED') {
        return 'PASS_CANCELLED';
    }
    $pay = (string) ($student['payment_status'] ?? '');
    if ($pay === 'PENDING') {
        return 'PAYMENT_PENDING';
    }
    if (in_array($pay, ['REJECTED', 'REFUNDED', 'CANCELLED'], true)) {
        return 'PAYMENT_REJECTED';
    }
    return '';
}

/**
 * Attempt to mark a student as ENTERED — ATOMIC duplicate protection.
 * Works across multiple gates / simultaneous scans: the guarded UPDATE
 * inside a transaction is the single source of truth.
 *
 * Returns ['result'=>..., 'student'=>fresh row, 'first'=>?entry info]
 */
function attempt_entry(array $student, string $method, ?int $volunteerId, ?int $adminId, ?string $gate, ?string $scanToken = null, ?string $reason = null): array
{
    $method = ($method === 'MANUAL') ? 'MANUAL' : 'QR';
    $sid = (int) $student['id'];

    $deny = check_entry_eligibility($student);
    if ($deny !== '') {
        entry_log($deny, $sid, $volunteerId, $adminId, $gate, $scanToken, $method, 'Denied: ' . $deny);
        return [
            'result'  => $deny,
            'student' => student_by_id($sid) ?: $student,
            'first'   => first_entry_info($sid),
        ];
    }

    $db = db();
    $inTx = $db->inTransaction();
    try {
        if (!$inTx) {
            $db->beginTransaction();
        }
        $stmt = $db->prepare(
            "UPDATE students SET entry_status = 'ENTERED', entry_time = ?, entry_method = ?
             WHERE id = ? AND entry_status = 'NOT_ENTERED'"
        );
        $stmt->execute([now_db(), $method, $sid]);

        if ($stmt->rowCount() === 1) {
            if (!$inTx) {
                $db->commit();
            }
            $result = ($method === 'MANUAL') ? 'MANUAL_APPROVED' : 'SUCCESS';
            $fresh = student_by_id($sid) ?: $student;
            entry_log($result, $sid, $volunteerId, $adminId, $gate, $scanToken, $method, $reason);
            return [
                'result'  => $result,
                'student' => $fresh,
                'first'   => ['time' => $fresh['entry_time'], 'gate' => $gate, 'method' => $method, 'by' => null],
            ];
        }

        if (!$inTx) {
            $db->rollBack();
        }
        $fresh = student_by_id($sid) ?: $student;
        $first = first_entry_info($sid);
        try {
            $recentLog = db()->prepare("SELECT id FROM entry_logs WHERE student_id = ? AND result = 'ALREADY_USED' AND scan_time >= ? LIMIT 1");
            $recentLog->execute([$sid, date('Y-m-d H:i:s', time() - 15)]);
            if (!$recentLog->fetchColumn()) {
                entry_log('ALREADY_USED', $sid, $volunteerId, $adminId, $gate, $scanToken, $method, 'Duplicate scan — pass already used');
            }
        } catch (Throwable $e) {
            entry_log('ALREADY_USED', $sid, $volunteerId, $adminId, $gate, $scanToken, $method, 'Duplicate scan — pass already used');
        }
        return ['result' => 'ALREADY_USED', 'student' => $fresh, 'first' => $first];
    } catch (Throwable $ex) {
        if (!$inTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $ex;
    }
}

/** Reset an incorrectly marked entry (admin only). Old entry logs are preserved. */
function reset_entry(array $student, int $adminId, string $reason): bool
{
    $db = db();
    $stmt = $db->prepare(
        "UPDATE students SET entry_status = 'NOT_ENTERED', entry_time = NULL, entry_method = NULL
         WHERE id = ? AND entry_status = 'ENTERED'"
    );
    $stmt->execute([(int) $student['id']]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    entry_log('MANUAL_RESET', (int) $student['id'], null, $adminId, 'ADMIN', null, null, $reason);
    audit_log(
        'ENTRY_RESET',
        'STUDENT',
        (string) ($student['pnr_number'] ?? $student['pass_id']),
        ['entry_status' => 'ENTERED', 'entry_time' => $student['entry_time']],
        ['entry_status' => 'NOT_ENTERED'],
        $reason
    );
    return true;
}

/** First successful entry info for a student (from entry_logs history). */
function first_entry_info(int $studentId): ?array
{
    $stmt = db()->prepare(
        "SELECT el.scan_time, el.gate_name, el.entry_method, v.name AS volunteer_name, a.name AS admin_name
         FROM entry_logs el
         LEFT JOIN volunteers v ON v.id = el.volunteer_id
         LEFT JOIN admins a ON a.id = el.admin_id
         WHERE el.student_id = ? AND el.result IN ('SUCCESS','MANUAL_APPROVED')
         ORDER BY el.scan_time ASC, el.id ASC LIMIT 1"
    );
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'time'   => $row['scan_time'],
        'gate'   => $row['gate_name'] ?: '—',
        'method' => $row['entry_method'] ?: '—',
        'by'     => $row['volunteer_name'] ?: ($row['admin_name'] ?: '—'),
    ];
}

/* =========================================================
 * CSV export
 * ========================================================= */

function output_csv(string $filename, array $header, array $rows): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: public');
    header('Expires: 0');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel opens ₹ and names correctly
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}
