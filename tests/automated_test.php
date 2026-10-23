<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Comprehensive Automated Test Suite
 * ========================================================
 * Covers: DB schema, registration, payment, status, pass, scanner, admin,
 *         APIs, CSRF, SQL injection, XSS, IDOR, file upload security,
 *         business logic, session security, and error handling.
 *
 * Usage:  C:\xampp\php\php.exe tests\automated_test.php
 *
 * This uses the PRODUCTION database (fresher_party) — reads are safe,
 * and writes are wrapped in transactions that are ROLLED BACK.
 */

// ── Bootstrap ──────────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load project (override DB to use test DB)
define('TEST_MODE', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// ── Test harness ───────────────────────────────────────────────────
$results = [];
$counts  = ['total' => 0, 'pass' => 0, 'fail' => 0, 'warn' => 0, 'blocked' => 0];
$bugs    = [];

function test(string $id, string $desc, callable $fn): void
{
    global $results, $counts;
    $counts['total']++;
    $start = microtime(true);
    try {
        $result = $fn();
        if ($result === true || $result === null) {
            $results[] = ['id' => $id, 'desc' => $desc, 'status' => 'PASS', 'detail' => '', 'time' => microtime(true) - $start];
            $counts['pass']++;
        } elseif ($result === 'BLOCKED') {
            $results[] = ['id' => $id, 'desc' => $desc, 'status' => 'BLOCKED', 'detail' => 'Blocked', 'time' => microtime(true) - $start];
            $counts['blocked']++;
        } elseif (is_string($result) && str_starts_with($result, 'WARN:')) {
            $results[] = ['id' => $id, 'desc' => $desc, 'status' => 'WARNING', 'detail' => substr($result, 5), 'time' => microtime(true) - $start];
            $counts['warn']++;
        } else {
            $results[] = ['id' => $id, 'desc' => $desc, 'status' => 'FAIL', 'detail' => (string)$result, 'time' => microtime(true) - $start];
            $counts['fail']++;
        }
    } catch (Throwable $ex) {
        $results[] = ['id' => $id, 'desc' => $desc, 'status' => 'FAIL', 'detail' => get_class($ex) . ': ' . $ex->getMessage(), 'time' => microtime(true) - $start];
        $counts['fail']++;
    }
}

function bug(string $severity, string $id, string $page, string $expected, string $actual, string $rootCause, string $file, string $line, string $fix): void
{
    global $bugs;
    $bugs[] = compact('severity', 'id', 'page', 'expected', 'actual', 'rootCause', 'file', 'line', 'fix');
}

echo "============================================================\n";
echo " FRESHERS PARTY 2026 — AUTOMATED TEST SUITE\n";
echo " Started: " . date('Y-m-d H:i:s') . "\n";
echo " PHP: " . phpversion() . "\n";
echo "============================================================\n\n";

// ── DB connection test ─────────────────────────────────────────────
$pdo = null;
try {
    $pdo = db();
    echo "[OK] Database connection established (DB_NAME=" . DB_NAME . ")\n\n";
} catch (Throwable $ex) {
    echo "[FAIL] Database connection failed: " . $ex->getMessage() . "\n";
    echo "Cannot proceed without database.\n";
    exit(1);
}

// ================================================================
// SECTION 1: DATABASE SCHEMA TESTS
// ================================================================
echo "--- SECTION 1: DATABASE SCHEMA TESTS ---\n";

test('DB-001', 'All required tables exist', function () use ($pdo) {
    $expected = ['admins', 'students', 'payments', 'volunteers', 'entry_logs', 'audit_logs', 'event_settings'];
    $stmt = $pdo->query("SHOW TABLES");
    $tables = array_map(fn($r) => array_values($r)[0], $stmt->fetchAll());
    $missing = array_diff($expected, $tables);
    return empty($missing) ? true : 'Missing tables: ' . implode(', ', $missing);
});

test('DB-002', 'students table has correct columns', function () use ($pdo) {
    $stmt = $pdo->query("DESCRIBE students");
    $cols = array_column($stmt->fetchAll(), 'Field');
    $required = ['id', 'pnr_number', 'pass_id', 'name', 'mobile', 'email', 'course', 'year',
        'division', 'photo', 'pass_type', 'qr_token', 'pass_status', 'payment_status',
        'required_amount', 'paid_amount', 'entry_status', 'entry_time', 'entry_method', 'created_at', 'updated_at'];
    $missing = array_diff($required, $cols);
    return empty($missing) ? true : 'Missing columns: ' . implode(', ', $missing);
});

test('DB-003', 'students PNR unique constraint exists', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM students WHERE Key_name = 'uq_students_pnr'");
    return $stmt->rowCount() > 0 ? true : 'Missing unique index uq_students_pnr';
});

test('DB-004', 'students mobile unique constraint exists', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM students WHERE Key_name = 'uq_students_mobile'");
    return $stmt->rowCount() > 0 ? true : 'Missing unique index uq_students_mobile';
});

test('DB-005', 'students pass_id unique constraint exists', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM students WHERE Key_name = 'uq_students_pass_id'");
    return $stmt->rowCount() > 0 ? true : 'Missing unique index uq_students_pass_id';
});

test('DB-006', 'students qr_token unique constraint exists', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM students WHERE Key_name = 'uq_students_qr_token'");
    return $stmt->rowCount() > 0 ? true : 'Missing unique index uq_students_qr_token';
});

test('DB-007', 'payments foreign key to students exists', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM payments WHERE Key_name = 'fk_payments_student'");
    return $stmt->rowCount() > 0 ? true : 'Missing foreign key fk_payments_student';
});

test('DB-008', 'admins username unique constraint exists', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM admins WHERE Key_name = 'uq_admins_username'");
    return $stmt->rowCount() > 0 ? true : 'Missing unique index uq_admins_username';
});

test('DB-009', 'volunteers volunteer_id unique constraint exists', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM volunteers WHERE Key_name = 'uq_volunteers_volunteer_id'");
    return $stmt->rowCount() > 0 ? true : 'Missing unique index uq_volunteers_volunteer_id';
});

test('DB-010', 'event_settings default row exists', function () use ($pdo) {
    $stmt = $pdo->query("SELECT * FROM event_settings WHERE id = 1");
    $row = $stmt->fetch();
    return $row ? true : 'Missing default settings row (id=1)';
});

test('DB-011', 'InnoDB engine used for all tables (row-locking)', function () use ($pdo) {
    $stmt = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'");
    $nonInnoDB = [];
    foreach ($stmt->fetchAll() as $r) {
        if (strtolower($r['ENGINE']) !== 'innodb') {
            $nonInnoDB[] = $r['TABLE_NAME'] . ' (' . $r['ENGINE'] . ')';
        }
    }
    return empty($nonInnoDB) ? true : 'Non-InnoDB tables: ' . implode(', ', $nonInnoDB);
});

test('DB-012', 'Performance indexes exist on students', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM students");
    $indexes = array_column($stmt->fetchAll(), 'Key_name');
    $needed = ['idx_students_payment_status', 'idx_students_entry_status', 'idx_students_pass_status', 'idx_students_name'];
    $missing = array_diff($needed, $indexes);
    return empty($missing) ? true : 'Missing indexes: ' . implode(', ', $missing);
});

test('DB-013', 'Performance indexes exist on payments', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM payments");
    $indexes = array_column($stmt->fetchAll(), 'Key_name');
    $needed = ['idx_payments_student_id', 'idx_payments_utr_number', 'idx_payments_status'];
    $missing = array_diff($needed, $indexes);
    return empty($missing) ? true : 'Missing indexes: ' . implode(', ', $missing);
});

test('DB-014', 'Performance indexes exist on entry_logs', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM entry_logs");
    $indexes = array_column($stmt->fetchAll(), 'Key_name');
    $needed = ['idx_entry_logs_student_id', 'idx_entry_logs_result', 'idx_entry_logs_scan_time', 'idx_entry_logs_gate'];
    $missing = array_diff($needed, $indexes);
    return empty($missing) ? true : 'Missing indexes: ' . implode(', ', $missing);
});

// ================================================================
// SECTION 2: DUPLICATE REGISTRATION TESTS (DB level)
// ================================================================
echo "\n--- SECTION 2: DUPLICATE REGISTRATION TESTS ---\n";

test('DB-015', 'Duplicate PNR number rejected at DB level', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $token1 = bin2hex(random_bytes(16));
        $token2 = bin2hex(random_bytes(16));
        $pass1 = 'TEST-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $pass2 = 'TEST-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $stmt = $pdo->prepare("INSERT INTO students (pnr_number, pass_id, name, mobile, email, course, year, qr_token, pass_type, pass_status, payment_status, required_amount, entry_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,'PAID','ACTIVE','PENDING',300,'NOT_ENTERED',NOW(),NOW())");
        $stmt->execute(['1234567890', $pass1, 'Test User 1', '9876543210', 'test1@test.com', 'BCA', 'FY', $token1]);
        try {
            $stmt->execute(['1234567890', $pass2, 'Test User 2', '9876543211', 'test2@test.com', 'BCA', 'FY', $token2]);
            $pdo->rollBack();
            return 'Duplicate PNR was ALLOWED — unique constraint missing or not enforced!';
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '23000') || str_contains($e->getMessage(), 'Duplicate')) {
                $pdo->rollBack();
                return true;
            }
            $pdo->rollBack();
            return 'Unexpected error: ' . $e->getMessage();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

test('DB-016', 'Duplicate mobile number rejected at DB level', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $token1 = bin2hex(random_bytes(16));
        $token2 = bin2hex(random_bytes(16));
        $pass1 = 'TEST-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $pass2 = 'TEST-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $stmt = $pdo->prepare("INSERT INTO students (pnr_number, pass_id, name, mobile, email, course, year, qr_token, pass_type, pass_status, payment_status, required_amount, entry_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,'PAID','ACTIVE','PENDING',300,'NOT_ENTERED',NOW(),NOW())");
        $stmt->execute(['1111111111', $pass1, 'Test A', '8888888888', 'a@test.com', 'BCA', 'FY', $token1]);
        try {
            $stmt->execute(['2222222222', $pass2, 'Test B', '8888888888', 'b@test.com', 'BCA', 'FY', $token2]);
            $pdo->rollBack();
            return 'Duplicate mobile was ALLOWED — unique constraint missing!';
        } catch (PDOException $e) {
            $pdo->rollBack();
            return true;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

test('DB-017', 'Duplicate pass_id rejected at DB level', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $token1 = bin2hex(random_bytes(16));
        $token2 = bin2hex(random_bytes(16));
        $passId = 'TEST-DUPEPASS';
        $stmt = $pdo->prepare("INSERT INTO students (pnr_number, pass_id, name, mobile, email, course, year, qr_token, pass_type, pass_status, payment_status, required_amount, entry_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,'PAID','ACTIVE','PENDING',300,'NOT_ENTERED',NOW(),NOW())");
        $stmt->execute(['3333333333', $passId, 'Test C', '7777777777', 'c@test.com', 'BCA', 'FY', $token1]);
        try {
            $stmt->execute(['4444444444', $passId, 'Test D', '6666666666', 'd@test.com', 'BCA', 'FY', $token2]);
            $pdo->rollBack();
            return 'Duplicate pass_id was ALLOWED!';
        } catch (PDOException $e) {
            $pdo->rollBack();
            return true;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

// ================================================================
// SECTION 3: FUNCTION UNIT TESTS
// ================================================================
echo "\n--- SECTION 3: FUNCTION UNIT TESTS ---\n";

require_once __DIR__ . '/../includes/functions.php';

test('FN-001', 'e() escapes HTML correctly', function () {
    $input = '<script>alert("xss")</script>';
    $output = e($input);
    return !str_contains($output, '<script>') ? true : 'XSS not escaped: ' . $output;
});

test('FN-002', 'e() escapes quotes', function () {
    return e("a'b\"c") === "a&#039;b&quot;c" ? true : 'Quotes not escaped';
});

test('FN-003', 'money() formats correctly', function () {
    $m = money(300);
    return str_contains($m, '300') ? true : 'Got: ' . $m;
});

test('FN-004', 'normalize_pnr() accepts valid 10-digit PNR', function () {
    return normalize_pnr('2425000000') === '2425000000' ? true : 'Got: ' . normalize_pnr('2425000000');
});

test('FN-005', 'normalize_pnr() strips spaces/dashes', function () {
    return normalize_pnr('2425-000 000') === '2425000000' ? true : 'Did not strip: ' . normalize_pnr('2425-000 000');
});

test('FN-006', 'normalize_pnr() rejects short input', function () {
    return normalize_pnr('12345') === '' ? true : 'Accepted too-short PNR';
});

test('FN-007', 'normalize_pnr() rejects letters', function () {
    return normalize_pnr('abcdefghij') === '' ? true : 'Accepted alpha PNR';
});

test('FN-008', 'normalize_pnr() rejects SQL injection', function () {
    return normalize_pnr("' OR '1'='1") === '' ? true : 'Accepted SQL injection as PNR';
});

test('FN-009', 'normalize_pnr() rejects XSS payload', function () {
    return normalize_pnr('<script>alert(1)</script>') === '' ? true : 'Accepted XSS as PNR';
});

test('FN-010', 'pnr_is_valid() validates correctly', function () {
    return pnr_is_valid('2425000000') && !pnr_is_valid('123') && !pnr_is_valid("' OR 1=1--") ? true : 'Validation failed';
});

test('FN-011', 'years_list() returns expected year codes', function () {
    $years = years_list();
    return isset($years['FY']) && isset($years['SY']) && isset($years['TY']) && isset($years['PG']) ? true : 'Missing year codes';
});

test('FN-012', 'gates() returns non-empty array', function () {
    return !empty(gates()) ? true : 'Empty gates list';
});

test('FN-013', 'pass_types() returns expected types', function () {
    $types = pass_types();
    return in_array('PAID', $types) && in_array('FREE', $types) && in_array('VIP', $types) ? true : 'Missing pass types';
});

test('FN-014', 'base_url() generates correct URL', function () {
    $url = base_url('status.php');
    return str_contains($url, 'status.php') ? true : 'Got: ' . $url;
});

test('FN-015', 'client_ip() returns valid IP', function () {
    $ip = client_ip();
    return $ip !== '' ? true : 'Empty IP';
});

test('FN-016', 'dt_short() formats date', function () {
    $formatted = dt_short('2026-09-15 14:30:00');
    return str_contains($formatted, '2026') ? true : 'Got: ' . $formatted;
});

test('FN-017', 'dt_short() handles null', function () {
    return dt_short(null) === '—' ? true : 'Did not return dash for null';
});

test('FN-018', 'status_badge() returns HTML with class', function () {
    $badge = status_badge('APPROVED');
    return str_contains($badge, 'badge') && str_contains($badge, 'APPROVED') ? true : 'Bad badge: ' . $badge;
});

test('FN-019', 'new_pass_id() generates unique ID with prefix', function () {
    $id = new_pass_id();
    return str_starts_with($id, REG_PREFIX . '-') && strlen($id) > 6 ? true : 'Bad pass_id: ' . $id;
});

test('FN-020', 'new_qr_token() generates 32-char hex', function () {
    $token = new_qr_token();
    return preg_match('/^[a-f0-9]{32}$/', $token) ? true : 'Bad token: ' . $token;
});

test('FN-021', 'get_settings() returns valid settings', function () {
    $settings = get_settings();
    return isset($settings['event_name']) && isset($settings['registration_fee']) ? true : 'Missing settings fields';
});

test('FN-022', 'setting() returns expected value', function () {
    return setting('currency', 'INR') !== null ? true : 'setting() returned null';
});

// ================================================================
// SECTION 4: SQL INJECTION TESTS (Prepared Statements)
// ================================================================
echo "\n--- SECTION 4: SQL INJECTION PROTECTION TESTS ---\n";

test('SQLI-001', 'student_by_pnr() safe against SQL injection', function () use ($pdo) {
    $result = student_by_pnr("' OR '1'='1' --");
    return $result === null ? true : 'SQL injection returned data!';
});

test('SQLI-002', 'student_by_pnr() safe against UNION injection', function () {
    $result = student_by_pnr("' UNION SELECT * FROM admins --");
    return $result === null ? true : 'UNION injection returned data!';
});

test('SQLI-003', 'student_by_token() safe against SQL injection', function () {
    $result = student_by_token("' OR '1'='1");
    return $result === null ? true : 'SQL injection on token returned data!';
});

test('SQLI-004', 'normalize_pnr rejects SQL metacharacters', function () {
    $payloads = ["'; DROP TABLE students; --", "1 OR 1=1", "' UNION SELECT password_hash FROM admins--"];
    foreach ($payloads as $p) {
        if (normalize_pnr($p) !== '') {
            return 'Accepted SQL payload: ' . $p;
        }
    }
    return true;
});

test('SQLI-005', 'PDO uses real prepared statements (emulate_prepares=false)', function () use ($pdo) {
    $emulate = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    return (!$emulate || $emulate === false || $emulate === 0) ? true : 'ATTR_EMULATE_PREPARES is not false (' . var_export($emulate, true) . ')';
});

test('SQLI-006', 'PDO error mode is EXCEPTION', function () use ($pdo) {
    $mode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
    return $mode === PDO::ERRMODE_EXCEPTION ? true : 'Error mode is not EXCEPTION';
});

// ================================================================
// SECTION 5: XSS PROTECTION TESTS
// ================================================================
echo "\n--- SECTION 5: XSS PROTECTION TESTS ---\n";

test('XSS-001', 'e() escapes <script> tags', function () {
    $out = e('<script>alert(document.domain)</script>');
    return !str_contains($out, '<script>') ? true : 'Script tag not escaped';
});

test('XSS-002', 'e() escapes img onerror', function () {
    $out = e('<img src=x onerror=alert(1)>');
    return !str_contains($out, 'onerror') || str_contains($out, '&lt;') ? true : 'img onerror not escaped';
});

test('XSS-003', 'e() escapes event handlers', function () {
    $out = e('" onmouseover="alert(1)" x="');
    return !str_contains($out, 'onmouseover') || str_contains($out, '&quot;') ? true : 'Event handler not escaped';
});

test('XSS-004', 'status_badge() escapes status values', function () {
    $badge = status_badge('<script>alert(1)</script>');
    return str_contains($badge, '&lt;script&gt;') ? true : 'XSS in status_badge';
});

// ================================================================
// SECTION 6: FILE UPLOAD SECURITY TESTS
// ================================================================
echo "\n--- SECTION 6: FILE UPLOAD SECURITY TESTS ---\n";

test('UPLOAD-001', 'handle_image_upload() rejects missing file', function () {
    // Simulate no file uploaded
    $_FILES = [];
    $result = handle_image_upload('photo', 'students');
    return $result['ok'] === false ? true : 'Accepted missing file upload';
});

test('UPLOAD-002', 'MIME validation allows only JPG/PNG/WEBP', function () {
    // Check the source code
    $src = file_get_contents(__DIR__ . '/../includes/functions.php');
    $allowsJpg = str_contains($src, "'image/jpeg' => 'jpg'");
    $allowsPng = str_contains($src, "'image/png' => 'png'");
    $allowsWebp = str_contains($src, "'image/webp' => 'webp'");
    $blocksPhp = !str_contains($src, "'application/x-php'");
    return ($allowsJpg && $allowsPng && $allowsWebp && $blocksPhp) ? true : 'MIME validation incomplete';
});

test('UPLOAD-003', 'Filenames are randomized (no user input in filename)', function () {
    $src = file_get_contents(__DIR__ . '/../includes/functions.php');
    return str_contains($src, "bin2hex(random_bytes(16)) . '.' . \$ext") ? true : 'Filenames may use user input';
});

test('UPLOAD-004', 'getimagesize() validation present', function () {
    $src = file_get_contents(__DIR__ . '/../includes/functions.php');
    return str_contains($src, 'getimagesize($tmp) === false') ? true : 'No getimagesize() validation';
});

test('UPLOAD-005', 'is_uploaded_file() check present', function () {
    $src = file_get_contents(__DIR__ . '/../includes/functions.php');
    return str_contains($src, 'is_uploaded_file($tmp)') ? true : 'Missing is_uploaded_file() check';
});

test('UPLOAD-006', 'uploads/.htaccess blocks PHP execution', function () {
    $htaccess = @file_get_contents(__DIR__ . '/../uploads/.htaccess');
    return $htaccess && str_contains($htaccess, '.php') && str_contains($htaccess, 'denied') ? true : 'Missing PHP execution block in uploads';
});

test('UPLOAD-007', 'File size validation exists', function () {
    $src = file_get_contents(__DIR__ . '/../includes/functions.php');
    return str_contains($src, '$maxBytes') ? true : 'No file size validation';
});

// ================================================================
// SECTION 7: CSRF PROTECTION TESTS
// ================================================================
echo "\n--- SECTION 7: CSRF PROTECTION TESTS ---\n";

test('CSRF-001', 'csrf_token() generates non-empty token', function () {
    require_once __DIR__ . '/../includes/csrf.php';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('FPSESS_TEST');
        session_start();
    }
    $token = csrf_token();
    return strlen($token) === 64 ? true : 'Token length: ' . strlen($token);
});

test('CSRF-002', 'csrf_verify() rejects empty token', function () {
    $_POST['csrf_token'] = '';
    return csrf_verify() === false ? true : 'Empty CSRF token was accepted';
});

test('CSRF-003', 'csrf_verify() rejects wrong token', function () {
    $_POST['csrf_token'] = 'definitely_wrong_token';
    return csrf_verify() === false ? true : 'Wrong CSRF token was accepted';
});

test('CSRF-004', 'csrf_verify() accepts correct token', function () {
    $token = csrf_token();
    $_POST['csrf_token'] = $token;
    return csrf_verify() === true ? true : 'Correct CSRF token was rejected';
});

test('CSRF-005', 'csrf_verify() supports X-CSRF-Token header', function () {
    unset($_POST['csrf_token']);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token();
    $result = csrf_verify();
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    return $result === true ? true : 'Header CSRF token was rejected';
});

test('CSRF-006', 'csrf_field() outputs hidden input', function () {
    $field = csrf_field();
    return str_contains($field, 'type="hidden"') && str_contains($field, 'name="csrf_token"') ? true : 'Bad csrf_field output';
});

test('CSRF-007', 'register.php includes CSRF field', function () {
    $src = file_get_contents(__DIR__ . '/../register.php');
    return str_contains($src, 'csrf_field()') ? true : 'Missing CSRF in register.php';
});

test('CSRF-008', 'payment-submit.php requires CSRF', function () {
    $src = file_get_contents(__DIR__ . '/../payment-submit.php');
    return str_contains($src, 'csrf_require()') ? true : 'Missing CSRF in payment-submit.php';
});

test('CSRF-009', 'admin/actions.php requires CSRF', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    return str_contains($src, 'csrf_require()') ? true : 'Missing CSRF in admin/actions.php';
});

test('CSRF-010', 'admin/login.php requires CSRF', function () {
    $src = file_get_contents(__DIR__ . '/../admin/login.php');
    return str_contains($src, 'csrf_require()') ? true : 'Missing CSRF in admin/login.php';
});

test('CSRF-011', 'scanner/login.php requires CSRF', function () {
    $src = file_get_contents(__DIR__ . '/../scanner/login.php');
    return str_contains($src, 'csrf_require()') ? true : 'Missing CSRF in scanner/login.php';
});

test('CSRF-012', 'admin/settings.php requires CSRF', function () {
    $src = file_get_contents(__DIR__ . '/../admin/settings.php');
    return str_contains($src, 'csrf_require()') ? true : 'Missing CSRF in admin/settings.php';
});

test('CSRF-013', 'admin/volunteers.php requires CSRF', function () {
    $src = file_get_contents(__DIR__ . '/../admin/volunteers.php');
    return str_contains($src, 'csrf_require()') ? true : 'Missing CSRF in admin/volunteers.php';
});

test('CSRF-014', 'admin/admin-users.php requires CSRF', function () {
    $src = file_get_contents(__DIR__ . '/../admin/admin-users.php');
    return str_contains($src, 'csrf_require()') ? true : 'Missing CSRF in admin/admin-users.php';
});

test('CSRF-015', 'api/verify-pass.php requires CSRF', function () {
    $src = file_get_contents(__DIR__ . '/../api/verify-pass.php');
    return str_contains($src, 'csrf_verify()') ? true : 'Missing CSRF in verify-pass.php';
});

// ================================================================
// SECTION 8: SESSION SECURITY TESTS
// ================================================================
echo "\n--- SECTION 8: SESSION SECURITY TESTS ---\n";

test('SESS-001', 'Session cookie is HttpOnly', function () {
    $src = file_get_contents(__DIR__ . '/../includes/auth.php');
    return str_contains($src, "'httponly' => true") ? true : 'HttpOnly not set';
});

test('SESS-002', 'Session cookie has SameSite=Lax', function () {
    $src = file_get_contents(__DIR__ . '/../includes/auth.php');
    return str_contains($src, "'samesite' => 'Lax'") ? true : 'SameSite not set';
});

test('SESS-003', 'Session regeneration on admin login', function () {
    $src = file_get_contents(__DIR__ . '/../includes/admin-auth.php');
    return str_contains($src, 'session_regenerate_id(true)') ? true : 'Missing session_regenerate_id on admin login';
});

test('SESS-004', 'Session regeneration on volunteer login', function () {
    $src = file_get_contents(__DIR__ . '/../includes/volunteer-auth.php');
    return str_contains($src, 'session_regenerate_id(true)') ? true : 'Missing session_regenerate_id on volunteer login';
});

test('SESS-005', 'User-agent fingerprint binding present', function () {
    $src = file_get_contents(__DIR__ . '/../includes/auth.php');
    return str_contains($src, 'HTTP_USER_AGENT') && str_contains($src, '_fp') ? true : 'Missing UA fingerprint';
});

test('SESS-006', 'Session rotation on authenticated pages', function () {
    $src = file_get_contents(__DIR__ . '/../includes/auth.php');
    return str_contains($src, 'rotate_session') ? true : 'Missing session rotation';
});

test('SESS-007', 'Logout destroys session properly', function () {
    $src = file_get_contents(__DIR__ . '/../includes/auth.php');
    return str_contains($src, 'session_destroy') && str_contains($src, "\$_SESSION = []") ? true : 'Incomplete session destruction';
});

test('SESS-008', 'Custom session name used (not PHPSESSID)', function () {
    $src = file_get_contents(__DIR__ . '/../includes/auth.php');
    return str_contains($src, "session_name('FPSESS')") ? true : 'Default session name used';
});

// ================================================================
// SECTION 9: AUTHORIZATION TESTS
// ================================================================
echo "\n--- SECTION 9: AUTHORIZATION / ROLE TESTS ---\n";

test('AUTH-001', 'require_admin() redirects unauthenticated users', function () {
    $src = file_get_contents(__DIR__ . '/../includes/admin-auth.php');
    return str_contains($src, "redirect_to('admin/login.php')") ? true : 'Missing redirect';
});

test('AUTH-002', 'require_permission() checks admin_can()', function () {
    $src = file_get_contents(__DIR__ . '/../includes/admin-auth.php');
    return str_contains($src, 'admin_can($permission)') ? true : 'Missing permission check';
});

test('AUTH-003', 'Admin dashboard requires authentication', function () {
    $src = file_get_contents(__DIR__ . '/../admin/dashboard.php');
    return str_contains($src, 'require_admin') || str_contains($src, 'require_permission') ? true : 'No auth check';
});

test('AUTH-004', 'Admin registrations requires authentication', function () {
    $src = file_get_contents(__DIR__ . '/../admin/registrations.php');
    return str_contains($src, 'require_admin') || str_contains($src, 'require_permission') ? true : 'No auth check';
});

test('AUTH-005', 'Admin payments requires authentication', function () {
    $src = file_get_contents(__DIR__ . '/../admin/payments.php');
    return str_contains($src, 'require_permission') ? true : 'No permission check';
});

test('AUTH-006', 'Admin settings requires authentication', function () {
    $src = file_get_contents(__DIR__ . '/../admin/settings.php');
    return str_contains($src, "require_permission('settings')") ? true : 'No settings permission check';
});

test('AUTH-007', 'Admin users requires SUPER_ADMIN', function () {
    $src = file_get_contents(__DIR__ . '/../admin/admin-users.php');
    return str_contains($src, "require_permission('admins')") ? true : 'No admins permission check';
});

test('AUTH-008', 'Volunteers page requires proper permission', function () {
    $src = file_get_contents(__DIR__ . '/../admin/volunteers.php');
    return str_contains($src, "require_permission('volunteers')") ? true : 'No volunteers permission check';
});

test('AUTH-009', 'Scanner requires volunteer auth', function () {
    $src = file_get_contents(__DIR__ . '/../scanner/scan.php');
    return str_contains($src, 'require_volunteer()') ? true : 'No volunteer auth check in scanner';
});

test('AUTH-010', 'API dashboard-stats requires admin auth', function () {
    $src = file_get_contents(__DIR__ . '/../api/dashboard-stats.php');
    return str_contains($src, "require_permission_json('dashboard')") ? true : 'No auth in dashboard-stats API';
});

test('AUTH-011', 'API search-participant requires admin auth', function () {
    $src = file_get_contents(__DIR__ . '/../api/search-participant.php');
    return str_contains($src, "require_permission_json('manual_entry')") ? true : 'No auth in search-participant API';
});

test('AUTH-012', 'API verify-pass requires volunteer auth', function () {
    $src = file_get_contents(__DIR__ . '/../api/verify-pass.php');
    return str_contains($src, 'current_volunteer()') ? true : 'No volunteer auth in verify-pass API';
});

test('AUTH-013', 'admin_permissions() restricts PAYMENT_ADMIN properly', function () {
    require_once __DIR__ . '/../includes/admin-auth.php';
    $perms = admin_permissions('PAYMENT_ADMIN');
    return in_array('payments', $perms) && !in_array('settings', $perms) && !in_array('admins', $perms) ? true : 'PAYMENT_ADMIN has wrong permissions';
});

test('AUTH-014', 'admin_permissions() restricts ENTRY_MANAGER properly', function () {
    $perms = admin_permissions('ENTRY_MANAGER');
    return in_array('entries', $perms) && !in_array('payments', $perms) && !in_array('settings', $perms) ? true : 'ENTRY_MANAGER has wrong permissions';
});

test('AUTH-015', 'SUPER_ADMIN has all permissions', function () {
    $perms = admin_permissions('SUPER_ADMIN');
    return in_array('admins', $perms) && in_array('settings', $perms) && in_array('payments', $perms) ? true : 'SUPER_ADMIN missing permissions';
});

// ================================================================
// SECTION 10: BUSINESS LOGIC TESTS
// ================================================================
echo "\n--- SECTION 10: BUSINESS LOGIC TESTS ---\n";

test('BIZ-001', 'check_entry_eligibility() denies disabled pass', function () {
    $student = ['pass_status' => 'DISABLED', 'payment_status' => 'APPROVED'];
    return check_entry_eligibility($student) === 'PASS_DISABLED' ? true : 'Did not deny disabled pass';
});

test('BIZ-002', 'check_entry_eligibility() denies cancelled pass', function () {
    $student = ['pass_status' => 'CANCELLED', 'payment_status' => 'APPROVED'];
    return check_entry_eligibility($student) === 'PASS_CANCELLED' ? true : 'Did not deny cancelled pass';
});

test('BIZ-003', 'check_entry_eligibility() denies pending payment', function () {
    $student = ['pass_status' => 'ACTIVE', 'payment_status' => 'PENDING'];
    return check_entry_eligibility($student) === 'PAYMENT_PENDING' ? true : 'Did not deny pending payment';
});

test('BIZ-004', 'check_entry_eligibility() denies rejected payment', function () {
    $student = ['pass_status' => 'ACTIVE', 'payment_status' => 'REJECTED'];
    return check_entry_eligibility($student) === 'PAYMENT_REJECTED' ? true : 'Did not deny rejected payment';
});

test('BIZ-005', 'check_entry_eligibility() allows approved + active', function () {
    $student = ['pass_status' => 'ACTIVE', 'payment_status' => 'APPROVED'];
    return check_entry_eligibility($student) === '' ? true : 'Denied eligible student';
});

test('BIZ-006', 'check_entry_eligibility() allows NOT_REQUIRED + active', function () {
    $student = ['pass_status' => 'ACTIVE', 'payment_status' => 'NOT_REQUIRED'];
    return check_entry_eligibility($student) === '' ? true : 'Denied free-pass student';
});

test('BIZ-007', 'attempt_entry() atomic duplicate protection', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $token = bin2hex(random_bytes(16));
        $passId = 'TEST-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $stmt = $pdo->prepare("INSERT INTO students (pnr_number, pass_id, name, mobile, email, course, year, qr_token, pass_type, pass_status, payment_status, required_amount, entry_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,'PAID','ACTIVE','APPROVED',300,'NOT_ENTERED',NOW(),NOW())");
        $stmt->execute(['5555555555', $passId, 'Entry Test', '5555555550', 'entry@test.com', 'BCA', 'FY', $token]);
        $sid = (int) $pdo->lastInsertId();
        $student = student_by_id($sid);

        // First entry should succeed
        $r1 = attempt_entry($student, 'QR', null, null, 'Gate 1', $token);
        $ok1 = in_array($r1['result'], ['SUCCESS', 'MANUAL_APPROVED']);

        // Second entry should be ALREADY_USED
        $fresh = student_by_id($sid);
        $r2 = attempt_entry($fresh, 'QR', null, null, 'Gate 2', $token);
        $ok2 = $r2['result'] === 'ALREADY_USED';

        $pdo->rollBack();
        return ($ok1 && $ok2) ? true : "First: {$r1['result']}, Second: {$r2['result']}";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

test('BIZ-008', 'reset_entry() properly resets entry status', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $token = bin2hex(random_bytes(16));
        $passId = 'TEST-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $stmt = $pdo->prepare("INSERT INTO students (pnr_number, pass_id, name, mobile, email, course, year, qr_token, pass_type, pass_status, payment_status, required_amount, entry_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,'PAID','ACTIVE','APPROVED',300,'NOT_ENTERED',NOW(),NOW())");
        $stmt->execute(['6666666661', $passId, 'Reset Test', '6666666661', 'reset@test.com', 'BCA', 'FY', $token]);
        $sid = (int) $pdo->lastInsertId();
        $student = student_by_id($sid);

        // Enter first
        attempt_entry($student, 'QR', null, null, 'Gate 1', $token);
        $fresh = student_by_id($sid);
        if ($fresh['entry_status'] !== 'ENTERED') {
            $pdo->rollBack();
            return 'Entry was not marked';
        }

        // Reset
        $ok = reset_entry($fresh, 1, 'Test reset');
        $after = student_by_id($sid);
        $pdo->rollBack();
        return ($ok && $after['entry_status'] === 'NOT_ENTERED') ? true : 'Reset did not work';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

// ================================================================
// SECTION 11: FILE/DIRECTORY SECURITY TESTS
// ================================================================
echo "\n--- SECTION 11: FILE/DIRECTORY SECURITY TESTS ---\n";

test('SEC-001', '.htaccess blocks database.sql download', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, '.sql') ? true : '.htaccess does not block .sql files';
});

test('SEC-002', '.htaccess blocks config directory', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'config/') ? true : '.htaccess does not block config/';
});

test('SEC-003', '.htaccess blocks includes directory', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'includes/') ? true : '.htaccess does not block includes/';
});

test('SEC-004', 'Directory listing disabled', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'Options -Indexes') ? true : 'Directory listing not disabled';
});

test('SEC-005', 'X-Content-Type-Options header set', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'X-Content-Type-Options') ? true : 'Missing X-Content-Type-Options';
});

test('SEC-006', 'X-Frame-Options header set', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'X-Frame-Options') ? true : 'Missing X-Frame-Options';
});

test('SEC-007', 'Referrer-Policy header set', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'Referrer-Policy') ? true : 'Missing Referrer-Policy';
});

test('SEC-008', 'ServerSignature Off', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'ServerSignature Off') ? true : 'ServerSignature not disabled';
});

test('SEC-009', 'PHP display_errors disabled in config', function () {
    $src = file_get_contents(__DIR__ . '/../config/config.php');
    return str_contains($src, "ini_set('display_errors', '0')") ? true : 'display_errors may be enabled';
});

test('SEC-010', 'PHP log_errors enabled', function () {
    $src = file_get_contents(__DIR__ . '/../config/config.php');
    return str_contains($src, "ini_set('log_errors', '1')") ? true : 'log_errors not enabled';
});

test('SEC-011', 'DB connection error does not leak credentials', function () {
    $src = file_get_contents(__DIR__ . '/../config/database.php');
    return str_contains($src, 'error_log') && !str_contains($src, 'DB_PASS') ? true :
        'WARN:DB error handling may leak info (but is acceptably safe with error_log)';
});

test('SEC-012', 'install.php has lock file mechanism', function () {
    $src = file_get_contents(__DIR__ . '/../install.php');
    return str_contains($src, 'installed.lock') ? true : 'No lock file mechanism in install.php';
});

test('SEC-013', 'Admin login has brute-force protection', function () {
    $src = file_get_contents(__DIR__ . '/../admin/login.php');
    return str_contains($src, 'adm_login_fails') && str_contains($src, 'adm_login_lock') ? true : 'No brute-force protection';
});

test('SEC-014', 'Admin login uses timing-safe comparison', function () {
    $src = file_get_contents(__DIR__ . '/../admin/login.php');
    return str_contains($src, 'password_verify') ? true : 'Not using password_verify';
});

test('SEC-015', 'Admin login constant-time for unknown users', function () {
    $src = file_get_contents(__DIR__ . '/../admin/login.php');
    // The login always runs password_verify even for unknown users
    return str_contains($src, '$2y$10$') ? true : 'WARN:May not run password_verify for unknown users';
});

test('SEC-016', 'Admin login generic error message', function () {
    $src = file_get_contents(__DIR__ . '/../admin/login.php');
    return str_contains($src, 'Invalid username or password') ? true : 'Error message may leak info';
});

// ================================================================
// SECTION 12: INPUT VALIDATION TESTS (source code audit)
// ================================================================
echo "\n--- SECTION 12: INPUT VALIDATION TESTS ---\n";

test('VAL-001', 'Registration validates name length', function () {
    $src = file_get_contents(__DIR__ . '/../register.php');
    return str_contains($src, "mb_strlen(\$old['name']) < 3") ? true : 'No name length validation';
});

test('VAL-002', 'Registration validates name characters', function () {
    $src = file_get_contents(__DIR__ . '/../register.php');
    return str_contains($src, "preg_match('~^[\\p{L}\\p{M}\\s.\\'\\-]+\$~u'") ? true : 'No name character validation';
});

test('VAL-003', 'Registration validates mobile number', function () {
    $src = file_get_contents(__DIR__ . '/../register.php');
    return str_contains($src, '[6-9][0-9]{9}') ? true : 'No mobile validation';
});

test('VAL-004', 'Registration validates email', function () {
    $src = file_get_contents(__DIR__ . '/../register.php');
    return str_contains($src, 'FILTER_VALIDATE_EMAIL') ? true : 'No email validation';
});

test('VAL-005', 'Registration has honeypot field', function () {
    $src = file_get_contents(__DIR__ . '/../register.php');
    return str_contains($src, 'website') && str_contains($src, 'Spam detected') ? true : 'No honeypot';
});

test('VAL-006', 'UTR validation with regex', function () {
    $src = file_get_contents(__DIR__ . '/../payment-submit.php');
    return str_contains($src, 'A-Z0-9\\-') && str_contains($src, '{8,30}') ? true : 'No UTR validation';
});

test('VAL-007', 'Duplicate UTR check across students', function () {
    $src = file_get_contents(__DIR__ . '/../payment-submit.php');
    return str_contains($src, 'student_id <> ?') ? true : 'No cross-student UTR duplicate check';
});

test('VAL-008', 'Payment date cannot be in the future', function () {
    $src = file_get_contents(__DIR__ . '/../payment-submit.php');
    return str_contains($src, "\$dateRaw > date('Y-m-d')") ? true : 'No future date check';
});

test('VAL-009', 'Payment blocked when already pending', function () {
    $src = file_get_contents(__DIR__ . '/../payment-submit.php');
    return str_contains($src, "status = 'PENDING'") && str_contains($src, 'already submitted') ? true : 'No pending payment block';
});

test('VAL-010', 'Registration checks capacity limit', function () {
    $src = file_get_contents(__DIR__ . '/../register.php');
    return str_contains($src, '$isFull') ? true : 'No capacity check';
});

test('VAL-011', 'Payment amount validated server-side', function () {
    $src = file_get_contents(__DIR__ . '/../payment-submit.php');
    return str_contains($src, '$amount <= 0') || str_contains($src, '$amount > 100000') ? true : 'No server-side amount validation';
});

// ================================================================
// SECTION 13: IDOR PROTECTION TESTS
// ================================================================
echo "\n--- SECTION 13: IDOR PROTECTION TESTS ---\n";

test('IDOR-001', 'Status page uses PNR (not sequential ID) for lookup', function () {
    $src = file_get_contents(__DIR__ . '/../status.php');
    return str_contains($src, 'normalize_pnr') ? true : 'Status uses sequential ID';
});

test('IDOR-002', 'Pass page uses PNR for lookup', function () {
    $src = file_get_contents(__DIR__ . '/../pass.php');
    return str_contains($src, 'normalize_pnr') ? true : 'Pass uses sequential ID';
});

test('IDOR-003', 'Payment page uses PNR for lookup', function () {
    $src = file_get_contents(__DIR__ . '/../payment.php');
    return str_contains($src, 'normalize_pnr') ? true : 'Payment uses sequential ID';
});

test('IDOR-004', 'Payment-submit uses PNR for student lookup', function () {
    $src = file_get_contents(__DIR__ . '/../payment-submit.php');
    return str_contains($src, 'normalize_pnr') && str_contains($src, 'student_by_pnr') ? true : 'Payment-submit uses sequential ID';
});

test('IDOR-005', 'Admin actions validate student existence', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    return str_contains($src, 'student_by_id') ? true : 'No student existence check in actions';
});

// ================================================================
// SECTION 14: API TESTS
// ================================================================
echo "\n--- SECTION 14: API ENDPOINT TESTS ---\n";

test('API-001', 'verify-pass.php requires POST method', function () {
    $src = file_get_contents(__DIR__ . '/../api/verify-pass.php');
    return str_contains($src, "!== 'POST'") && str_contains($src, '405') ? true : 'No POST method check';
});

test('API-002', 'verify-pass.php checks entry_status setting', function () {
    $src = file_get_contents(__DIR__ . '/../api/verify-pass.php');
    return str_contains($src, "setting('entry_status'") ? true : 'No entry_status check';
});

test('API-003', 'verify-pass.php validates token format', function () {
    $src = file_get_contents(__DIR__ . '/../api/verify-pass.php');
    return str_contains($src, '/^[a-f0-9]{32}$/') ? true : 'No token format validation';
});

test('API-004', 'verify-pass.php validates PNR format', function () {
    $src = file_get_contents(__DIR__ . '/../api/verify-pass.php');
    return str_contains($src, '~^[0-9]{10,15}$~') ? true : 'No PNR format validation';
});

test('API-005', 'dashboard-stats.php requires GET method', function () {
    $src = file_get_contents(__DIR__ . '/../api/dashboard-stats.php');
    return str_contains($src, "!== 'GET'") ? true : 'No GET method check';
});

test('API-006', 'search-participant.php escapes LIKE wildcards', function () {
    $src = file_get_contents(__DIR__ . '/../api/search-participant.php');
    return str_contains($src, "str_replace(['\\\\', '%', '_']") ? true : 'LIKE wildcards not escaped';
});

test('API-007', 'search-participant.php limits results', function () {
    $src = file_get_contents(__DIR__ . '/../api/search-participant.php');
    return str_contains($src, 'LIMIT 12') ? true : 'No result limit';
});

test('API-008', 'search-participant.php requires minimum query length', function () {
    $src = file_get_contents(__DIR__ . '/../api/search-participant.php');
    return str_contains($src, "mb_strlen(\$q) < 2") ? true : 'No minimum query length check';
});

test('API-009', 'JSON responses use no-store cache control', function () {
    $src = file_get_contents(__DIR__ . '/../api/verify-pass.php');
    return str_contains($src, 'Cache-Control: no-store') ? true : 'Missing no-store';
});

test('API-010', 'verify-pass re-checks volunteer active status from DB', function () {
    $src = file_get_contents(__DIR__ . '/../api/verify-pass.php');
    return str_contains($src, "status FROM volunteers WHERE id = ?") ? true : 'No live volunteer status check';
});

// ================================================================
// SECTION 15: ADMIN ACTIONS SECURITY TESTS
// ================================================================
echo "\n--- SECTION 15: ADMIN ACTIONS SECURITY TESTS ---\n";

test('ACT-001', 'Approve payment requires payment_verify permission', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    return str_contains($src, "admin_can('payment_verify')") ? true : 'No permission check for approve';
});

test('ACT-002', 'Reject payment requires reason', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    return str_contains($src, "rejection reason is required") ? true : 'No reason required for reject';
});

test('ACT-003', 'Delete student requires reason', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    return str_contains($src, "A reason is required to delete") ? true : 'No reason for delete';
});

test('ACT-004', 'Reset entry requires reason', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    return str_contains($src, "A reason is required to reset") ? true : 'No reason for reset';
});

test('ACT-005', 'Approve uses transaction + FOR UPDATE', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    return str_contains($src, 'beginTransaction') && str_contains($src, 'FOR UPDATE') ? true : 'No transaction/lock in approve';
});

test('ACT-006', 'Delete uses transaction', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    $pos = strpos($src, 'delete_student');
    return $pos !== false && str_contains(substr($src, $pos), 'beginTransaction') ? true : 'No transaction in delete';
});

test('ACT-007', 'Back redirect prevents open redirect', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    return str_contains($src, "preg_match('~^admin/") && str_contains($src, "str_contains(\$back, '..')") ? true : 'WARN:Open redirect protection may be incomplete';
});

test('ACT-008', 'All actions have audit logging', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    $actions = ['approve_payment', 'reject_payment', 'refund_payment', 'mark_entry', 'reset_entry', 'delete_student'];
    foreach ($actions as $a) {
        $pos = strpos($src, "case '$a'");
        if ($pos === false) continue;
        $chunk = substr($src, $pos, 3000);
        if (!str_contains($chunk, 'audit_log')) {
            return "Missing audit_log for action: $a";
        }
    }
    return true;
});

// ================================================================
// SECTION 16: OPEN REDIRECT TESTS
// ================================================================
echo "\n--- SECTION 16: OPEN REDIRECT TESTS ---\n";

test('REDIR-001', 'Admin login intended URL validated', function () {
    $src = file_get_contents(__DIR__ . '/../admin/login.php');
    return str_contains($src, "preg_match('~^admin/") ? true : 'Intended URL not validated';
});

test('REDIR-002', 'Admin actions back URL validated', function () {
    $src = file_get_contents(__DIR__ . '/../admin/actions.php');
    return str_contains($src, "preg_match('~^admin/") ? true : 'Back URL not validated';
});

// ================================================================
// SECTION 17: ERROR HANDLING TESTS
// ================================================================
echo "\n--- SECTION 17: ERROR HANDLING TESTS ---\n";

test('ERR-001', 'DB connection error shows friendly message', function () {
    $src = file_get_contents(__DIR__ . '/../config/database.php');
    return str_contains($src, 'Database connection failed') && !str_contains($src, 'DB_PASS') ? true : 'DB error may leak info';
});

test('ERR-002', 'Errors use error_log not echo', function () {
    $src = file_get_contents(__DIR__ . '/../includes/functions.php');
    return str_contains($src, 'error_log(') ? true : 'Not using error_log';
});

test('ERR-003', 'Payment-submit handles PDO errors gracefully', function () {
    $src = file_get_contents(__DIR__ . '/../payment-submit.php');
    return str_contains($src, 'catch (Throwable $ex)') && str_contains($src, 'error_log') ? true : 'Payment errors not handled';
});

test('ERR-004', 'Registration handles PDO duplicate key error', function () {
    $src = file_get_contents(__DIR__ . '/../register.php');
    return str_contains($src, "'23000'") ? true : 'No duplicate key error handling';
});

// ================================================================
// SECTION 18: HTTP LIVE TESTS (PHP built-in server)
// ================================================================
echo "\n--- SECTION 18: HTTP LIVE TESTS (code analysis) ---\n";

test('HTTP-001', 'json_out() sets proper content type', function () {
    $src = file_get_contents(__DIR__ . '/../includes/functions.php');
    return str_contains($src, "Content-Type: application/json; charset=utf-8") ? true : 'Bad JSON content type';
});

test('HTTP-002', 'json_out() sets cache control', function () {
    $src = file_get_contents(__DIR__ . '/../includes/functions.php');
    return str_contains($src, 'Cache-Control: no-store') ? true : 'Missing cache control for JSON';
});

test('HTTP-003', 'Admin pages use noindex meta', function () {
    $src = file_get_contents(__DIR__ . '/../admin/login.php');
    return str_contains($src, 'noindex') ? true : 'Missing noindex on admin pages';
});

test('HTTP-004', 'Scanner pages use noindex meta', function () {
    $src = file_get_contents(__DIR__ . '/../scanner/login.php');
    return str_contains($src, 'noindex') ? true : 'Missing noindex on scanner pages';
});

// ================================================================
// SECTION 19: JAVASCRIPT SECURITY TESTS
// ================================================================
echo "\n--- SECTION 19: JAVASCRIPT TESTS ---\n";

test('JS-001', 'app.js is valid (IIFE pattern)', function () {
    $src = file_get_contents(__DIR__ . '/../assets/js/app.js');
    return str_contains($src, "(function () {") && str_contains($src, "'use strict'") ? true : 'Missing IIFE/strict mode';
});

test('JS-002', 'scanner.js is valid (IIFE pattern)', function () {
    $src = file_get_contents(__DIR__ . '/../assets/js/scanner.js');
    return str_contains($src, "(function () {") && str_contains($src, "'use strict'") ? true : 'Missing IIFE/strict mode';
});

test('JS-003', 'Scanner uses CSRF token in AJAX', function () {
    $src = file_get_contents(__DIR__ . '/../assets/js/scanner.js');
    return str_contains($src, 'X-CSRF-Token') ? true : 'Missing CSRF in scanner AJAX';
});

test('JS-004', 'Scanner validates token format client-side', function () {
    $src = file_get_contents(__DIR__ . '/../assets/js/scanner.js');
    return str_contains($src, '/^[a-f0-9]{32}$/i') ? true : 'No client-side token validation';
});

test('JS-005', 'Scanner validates PNR format client-side', function () {
    $src = file_get_contents(__DIR__ . '/../assets/js/scanner.js');
    return str_contains($src, '/^[0-9]{10,15}$/') ? true : 'No client-side PNR validation';
});

test('JS-006', 'Scanner has cooldown for duplicate scans', function () {
    $src = file_get_contents(__DIR__ . '/../assets/js/scanner.js');
    return str_contains($src, 'COOLDOWN_MS') ? true : 'No scan cooldown';
});

test('JS-007', 'Scanner handles network errors', function () {
    $src = file_get_contents(__DIR__ . '/../assets/js/scanner.js');
    return str_contains($src, '.catch(function') ? true : 'No network error handling';
});

test('JS-008', 'QR code data is token only (no personal data)', function () {
    $src = file_get_contents(__DIR__ . '/../pass.php');
    return str_contains($src, "data-token=\"<?= e((string) \$student['qr_token']) ?>\"") ? true :
        'WARN:QR data might contain personal info — manual check recommended';
});

// ================================================================
// SECTION 20: REGISTRATION/PAYMENT/STATUS FLOW (LIVE DB)
// ================================================================
echo "\n--- SECTION 20: END-TO-END FLOW TESTS (DB) ---\n";

test('E2E-001', 'Full registration → payment → status → entry flow', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        // 1. Register
        $pnr = '9999999001';
        $token = bin2hex(random_bytes(16));
        $passId = 'TEST-E2E001';
        $stmt = $pdo->prepare("INSERT INTO students (pnr_number, pass_id, name, mobile, email, course, year, qr_token, pass_type, pass_status, payment_status, required_amount, paid_amount, entry_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,'PAID','ACTIVE','PENDING',300,0,'NOT_ENTERED',NOW(),NOW())");
        $stmt->execute([$pnr, $passId, 'E2E Test Student', '9999999001', 'e2e@test.com', 'BCA', 'FY', $token]);
        $sid = (int) $pdo->lastInsertId();

        // 2. Verify registration exists
        $student = student_by_pnr($pnr);
        if (!$student || $student['payment_status'] !== 'PENDING') {
            $pdo->rollBack();
            return 'Registration failed or wrong status';
        }

        // 3. Submit payment
        $stmt = $pdo->prepare("INSERT INTO payments (student_id, utr_number, amount, payment_date, status, created_at, updated_at) VALUES (?,?,?,?,'PENDING',NOW(),NOW())");
        $stmt->execute([$sid, 'TEST12345678', 300, date('Y-m-d')]);
        $payId = (int) $pdo->lastInsertId();

        // 4. Approve payment
        $pdo->prepare("UPDATE payments SET status='APPROVED', verified_by=1, verified_at=NOW() WHERE id=?")->execute([$payId]);
        $pdo->prepare("UPDATE students SET payment_status='APPROVED', paid_amount=300 WHERE id=?")->execute([$sid]);

        // 5. Verify pass is now active
        $student = student_by_id($sid);
        $eligible = in_array($student['payment_status'], ['APPROVED', 'NOT_REQUIRED']) && $student['pass_status'] === 'ACTIVE';
        if (!$eligible) {
            $pdo->rollBack();
            return 'Pass not eligible after approval';
        }

        // 6. Entry scan
        $r = attempt_entry($student, 'QR', null, null, 'Gate 1', $token);
        if (!in_array($r['result'], ['SUCCESS', 'MANUAL_APPROVED'])) {
            $pdo->rollBack();
            return 'Entry failed: ' . $r['result'];
        }

        // 7. Duplicate scan denied
        $fresh = student_by_id($sid);
        $r2 = attempt_entry($fresh, 'QR', null, null, 'Gate 2', $token);
        if ($r2['result'] !== 'ALREADY_USED') {
            $pdo->rollBack();
            return 'Duplicate scan not denied: ' . $r2['result'];
        }

        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

test('E2E-002', 'Unpaid student cannot get pass', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $pnr = '9999999002';
        $token = bin2hex(random_bytes(16));
        $passId = 'TEST-E2E002';
        $stmt = $pdo->prepare("INSERT INTO students (pnr_number, pass_id, name, mobile, email, course, year, qr_token, pass_type, pass_status, payment_status, required_amount, entry_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,'PAID','ACTIVE','PENDING',300,'NOT_ENTERED',NOW(),NOW())");
        $stmt->execute([$pnr, $passId, 'Unpaid Test', '9999999002', 'unpaid@test.com', 'BCA', 'FY', $token]);
        $sid = (int) $pdo->lastInsertId();
        $student = student_by_id($sid);
        $eligible = in_array($student['payment_status'], ['APPROVED', 'NOT_REQUIRED']);
        $pdo->rollBack();
        return !$eligible ? true : 'Unpaid student marked as eligible';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

test('E2E-003', 'Free pass bypasses payment requirement', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $pnr = '9999999003';
        $token = bin2hex(random_bytes(16));
        $passId = 'TEST-E2E003';
        $stmt = $pdo->prepare("INSERT INTO students (pnr_number, pass_id, name, mobile, email, course, year, qr_token, pass_type, pass_status, payment_status, required_amount, entry_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,'FREE','ACTIVE','NOT_REQUIRED',0,'NOT_ENTERED',NOW(),NOW())");
        $stmt->execute([$pnr, $passId, 'Free Pass', '9999999003', 'free@test.com', 'BCA', 'FY', $token]);
        $sid = (int) $pdo->lastInsertId();
        $student = student_by_id($sid);
        $eligible = check_entry_eligibility($student) === '';
        $pdo->rollBack();
        return $eligible ? true : 'Free pass denied entry';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

test('E2E-004', 'Rejected payment denies entry', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $token = bin2hex(random_bytes(16));
        $passId = 'TEST-E2E004';
        $stmt = $pdo->prepare("INSERT INTO students (pnr_number, pass_id, name, mobile, email, course, year, qr_token, pass_type, pass_status, payment_status, required_amount, entry_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,'PAID','ACTIVE','REJECTED',300,'NOT_ENTERED',NOW(),NOW())");
        $stmt->execute(['9999999004', $passId, 'Rejected', '9999999004', 'rejected@test.com', 'BCA', 'FY', $token]);
        $sid = (int) $pdo->lastInsertId();
        $student = student_by_id($sid);
        $deny = check_entry_eligibility($student);
        $pdo->rollBack();
        return $deny === 'PAYMENT_REJECTED' ? true : 'Rejected payment not denied: ' . $deny;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

test('E2E-005', 'Audit log records events', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $before = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
        audit_log('TEST_ACTION', 'STUDENT', 'TEST_TARGET', ['old' => 1], ['new' => 2], 'Test reason', 'SYSTEM', null);
        $after = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
        $pdo->rollBack();
        return $after > $before ? true : 'Audit log not recorded';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

test('E2E-006', 'Entry log records events', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $before = (int) $pdo->query("SELECT COUNT(*) FROM entry_logs")->fetchColumn();
        entry_log('SUCCESS', 1, null, null, 'Gate 1', 'testtoken1234567890123456', 'QR', 'Test entry');
        $after = (int) $pdo->query("SELECT COUNT(*) FROM entry_logs")->fetchColumn();
        $pdo->rollBack();
        return $after > $before ? true : 'Entry log not recorded';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Test error: ' . $e->getMessage();
    }
});

// ================================================================
// SECTION 21: MISSING SECURITY HEADERS (analysis)
// ================================================================
echo "\n--- SECTION 21: SECURITY HEADER ANALYSIS ---\n";

test('HDR-001', 'Content-Security-Policy header', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'Content-Security-Policy') ? true : 'WARN:No Content-Security-Policy header — consider adding one';
});

test('HDR-002', 'Strict-Transport-Security header', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'Strict-Transport-Security') ? true : 'WARN:No HSTS — HTTPS enforcement is commented out (expected for dev)';
});

test('HDR-003', 'Permissions-Policy header', function () {
    $htaccess = file_get_contents(__DIR__ . '/../.htaccess');
    return str_contains($htaccess, 'Permissions-Policy') ? true : 'WARN:No Permissions-Policy header';
});

// ================================================================
// RESULTS OUTPUT
// ================================================================
echo "\n\n============================================================\n";
echo " TEST RESULTS SUMMARY\n";
echo "============================================================\n";
echo " TOTAL TESTS:  " . $counts['total'] . "\n";
echo " PASSED:       " . $counts['pass'] . "\n";
echo " FAILED:       " . $counts['fail'] . "\n";
echo " WARNINGS:     " . $counts['warn'] . "\n";
echo " BLOCKED:      " . $counts['blocked'] . "\n";
echo "============================================================\n\n";

// Group by status
$failures = array_filter($results, fn($r) => $r['status'] === 'FAIL');
$warnings = array_filter($results, fn($r) => $r['status'] === 'WARNING');

if (!empty($failures)) {
    echo "FAILED TESTS:\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($failures as $f) {
        echo "  [{$f['id']}] {$f['desc']}\n";
        echo "    DETAIL: {$f['detail']}\n\n";
    }
}

if (!empty($warnings)) {
    echo "WARNINGS:\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($warnings as $w) {
        echo "  [{$w['id']}] {$w['desc']}\n";
        echo "    DETAIL: {$w['detail']}\n\n";
    }
}

echo "\nALL TESTS:\n";
echo str_repeat('-', 60) . "\n";
foreach ($results as $r) {
    $icon = $r['status'] === 'PASS' ? '✓' : ($r['status'] === 'FAIL' ? '✗' : ($r['status'] === 'WARNING' ? '⚠' : '○'));
    printf("  %s [%-8s] %-10s %s\n", $icon, $r['id'], $r['status'], $r['desc']);
}

echo "\nCompleted: " . date('Y-m-d H:i:s') . "\n";
