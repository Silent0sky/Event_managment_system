<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Regression Test Suite (Post-Fix)
 * Runs against the production database (fresher_party).
 * All writes are in transactions that get rolled back.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

// Start session for CSRF tests
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$results = [];
$counts  = ['total' => 0, 'pass' => 0, 'fail' => 0, 'warn' => 0];

function test(string $id, string $desc, callable $fn): void
{
    global $results, $counts;
    $counts['total']++;
    try {
        $result = $fn();
        if ($result === true || $result === null) {
            $results[] = ['id' => $id, 'status' => 'PASS', 'desc' => $desc, 'detail' => ''];
            $counts['pass']++;
        } elseif (is_string($result) && str_starts_with($result, 'WARN:')) {
            $results[] = ['id' => $id, 'status' => 'WARN', 'desc' => $desc, 'detail' => substr($result, 5)];
            $counts['warn']++;
        } else {
            $results[] = ['id' => $id, 'status' => 'FAIL', 'desc' => $desc, 'detail' => (string)$result];
            $counts['fail']++;
        }
    } catch (Throwable $ex) {
        $results[] = ['id' => $id, 'status' => 'FAIL', 'desc' => $desc, 'detail' => get_class($ex) . ': ' . $ex->getMessage()];
        $counts['fail']++;
    }
}

echo "============================================================\n";
echo " FRESHERS PARTY 2026 — REGRESSION TEST (Post-Fix)\n";
echo " " . date('Y-m-d H:i:s') . " | PHP " . phpversion() . "\n";
echo "============================================================\n\n";

$pdo = db();

// === DB Schema ===
test('DB-001', 'All required tables exist', function () use ($pdo) {
    $expected = ['admins', 'students', 'payments', 'volunteers', 'entry_logs', 'audit_logs', 'event_settings'];
    $stmt = $pdo->query("SHOW TABLES");
    $tables = array_map(fn($r) => array_values($r)[0], $stmt->fetchAll());
    $missing = array_diff($expected, $tables);
    return empty($missing) ? true : 'Missing: ' . implode(', ', $missing);
});

test('DB-002', 'students columns match code expectations', function () use ($pdo) {
    $stmt = $pdo->query("DESCRIBE students");
    $cols = array_column($stmt->fetchAll(), 'Field');
    $required = ['id', 'pnr_number', 'pass_id', 'name', 'mobile', 'email', 'course', 'year',
        'division', 'photo', 'pass_type', 'qr_token', 'pass_status', 'payment_status',
        'required_amount', 'paid_amount', 'entry_status', 'created_at', 'updated_at'];
    $missing = array_diff($required, $cols);
    return empty($missing) ? true : 'Missing: ' . implode(', ', $missing);
});

test('DB-003', 'PNR unique constraint exists', function () use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM students WHERE Key_name LIKE '%pnr%'");
    return $stmt->rowCount() > 0 ? true : 'No PNR unique index';
});

test('DB-004', 'event_settings default row exists', function () use ($pdo) {
    return (bool)$pdo->query("SELECT 1 FROM event_settings WHERE id = 1")->fetch();
});

test('DB-005', 'InnoDB engine on all tables', function () use ($pdo) {
    $bad = [];
    foreach ($pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'")->fetchAll() as $r) {
        if (strtolower($r['ENGINE']) !== 'innodb') $bad[] = $r['TABLE_NAME'];
    }
    return empty($bad) ? true : 'Non-InnoDB: ' . implode(', ', $bad);
});

// === Function Unit Tests ===
test('FN-001', 'e() escapes HTML', function () { return !str_contains(e('<script>'), '<script>'); });
test('FN-002', 'e() escapes quotes', function () { return e("'\"") === "&#039;&quot;"; });
test('FN-003', 'normalize_pnr() accepts valid PNR', function () { return normalize_pnr('2425000000') === '2425000000'; });
test('FN-004', 'normalize_pnr() strips spaces/dashes', function () { return normalize_pnr('2425-000 000') === '2425000000'; });
test('FN-005', 'normalize_pnr() rejects short', function () { return normalize_pnr('12345') === ''; });
test('FN-006', 'normalize_pnr() rejects SQL injection', function () { return normalize_pnr("' OR '1'='1") === ''; });
test('FN-007', 'normalize_pnr() rejects XSS', function () { return normalize_pnr('<script>alert(1)</script>') === ''; });
test('FN-008', 'money() formats correctly', function () { return str_contains(money(300), '300'); });
test('FN-009', 'dt_short() handles null', function () { return dt_short(null) === '—'; });
test('FN-010', 'status_badge() returns valid HTML', function () { return str_contains(status_badge('APPROVED'), 'badge'); });
test('FN-011', 'get_settings() returns array with required keys', function () {
    $s = get_settings();
    return isset($s['event_name']) && isset($s['registration_fee']) && isset($s['registration_status']);
});
test('FN-012', 'new_pass_id() generates valid ID', function () { return str_starts_with(new_pass_id(), REG_PREFIX . '-'); });
test('FN-013', 'new_qr_token() generates 32-char hex', function () { return (bool)preg_match('/^[a-f0-9]{32}$/', new_qr_token()); });

// === SQL Injection Protection ===
test('SQLI-001', 'student_by_pnr() immune to injection', function () { return student_by_pnr("' OR '1'='1' --") === null; });
test('SQLI-002', 'student_by_token() immune to injection', function () { return student_by_token("' OR '1'='1") === null; });
test('SQLI-003', 'PDO error mode is EXCEPTION', function () use ($pdo) { return $pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION; });

// === XSS ===
test('XSS-001', 'e() escapes <script>', function () { return str_contains(e('<script>alert(1)</script>'), '&lt;script&gt;'); });
test('XSS-002', 'e() escapes img onerror', function () { return str_contains(e('<img src=x onerror=alert(1)>'), '&lt;img'); });
test('XSS-003', 'status_badge() escapes values', function () { return str_contains(status_badge('<script>'), '&lt;'); });

// === CSRF ===
test('CSRF-001', 'csrf_token() is 64 chars', function () { return strlen(csrf_token()) === 64; });
test('CSRF-002', 'csrf_verify() rejects empty', function () { $_POST['csrf_token'] = ''; return csrf_verify() === false; });
test('CSRF-003', 'csrf_verify() rejects wrong', function () { $_POST['csrf_token'] = 'wrong'; return csrf_verify() === false; });
test('CSRF-004', 'csrf_verify() accepts correct', function () { $_POST['csrf_token'] = csrf_token(); return csrf_verify(); });
test('CSRF-005', 'register.php has csrf_field()', function () { return str_contains(file_get_contents(__DIR__.'/../register.php'), 'csrf_field()'); });
test('CSRF-006', 'payment-submit.php has csrf_require()', function () { return str_contains(file_get_contents(__DIR__.'/../payment-submit.php'), 'csrf_require()'); });
test('CSRF-007', 'admin/actions.php has csrf_require()', function () { return str_contains(file_get_contents(__DIR__.'/../admin/actions.php'), 'csrf_require()'); });
test('CSRF-008', 'admin/login.php has csrf_require()', function () { return str_contains(file_get_contents(__DIR__.'/../admin/login.php'), 'csrf_require()'); });
test('CSRF-009', 'scanner/login.php has csrf_require()', function () { return str_contains(file_get_contents(__DIR__.'/../scanner/login.php'), 'csrf_require()'); });
test('CSRF-010', 'admin/settings.php has csrf_require()', function () { return str_contains(file_get_contents(__DIR__.'/../admin/settings.php'), 'csrf_require()'); });
test('CSRF-011', 'admin/volunteers.php has csrf_require()', function () { return str_contains(file_get_contents(__DIR__.'/../admin/volunteers.php'), 'csrf_require()'); });
test('CSRF-012', 'admin/admin-users.php has csrf_require()', function () { return str_contains(file_get_contents(__DIR__.'/../admin/admin-users.php'), 'csrf_require()'); });
test('CSRF-013', 'api/verify-pass.php has csrf_verify()', function () { return str_contains(file_get_contents(__DIR__.'/../api/verify-pass.php'), 'csrf_verify()'); });

// === Session Security ===
test('SESS-001', 'Session HttpOnly', function () { return str_contains(file_get_contents(__DIR__.'/../includes/auth.php'), "'httponly' => true"); });
test('SESS-002', 'Session SameSite=Lax', function () { return str_contains(file_get_contents(__DIR__.'/../includes/auth.php'), "'samesite' => 'Lax'"); });
test('SESS-003', 'Session regeneration on admin login', function () { return str_contains(file_get_contents(__DIR__.'/../includes/admin-auth.php'), 'session_regenerate_id(true)'); });
test('SESS-004', 'Session regeneration on volunteer login', function () { return str_contains(file_get_contents(__DIR__.'/../includes/volunteer-auth.php'), 'session_regenerate_id(true)'); });
test('SESS-005', 'UA fingerprint binding', function () { return str_contains(file_get_contents(__DIR__.'/../includes/auth.php'), '_fp'); });
test('SESS-006', 'Session rotation', function () { return str_contains(file_get_contents(__DIR__.'/../includes/auth.php'), 'rotate_session'); });
test('SESS-007', 'Custom session name', function () { return str_contains(file_get_contents(__DIR__.'/../includes/auth.php'), "session_name('FPSESS')"); });

// === Auth/Authorization ===
test('AUTH-001', 'Admin dashboard requires auth', function () { return str_contains(file_get_contents(__DIR__.'/../admin/dashboard.php'), 'require_admin') || str_contains(file_get_contents(__DIR__.'/../admin/dashboard.php'), 'require_permission'); });
test('AUTH-002', 'Admin settings requires settings permission', function () { return str_contains(file_get_contents(__DIR__.'/../admin/settings.php'), "require_permission('settings')"); });
test('AUTH-003', 'Admin users requires admins permission', function () { return str_contains(file_get_contents(__DIR__.'/../admin/admin-users.php'), "require_permission('admins')"); });
test('AUTH-004', 'Scanner requires volunteer auth', function () { return str_contains(file_get_contents(__DIR__.'/../scanner/scan.php'), 'require_volunteer()'); });
test('AUTH-005', 'API dashboard-stats requires admin auth', function () { return str_contains(file_get_contents(__DIR__.'/../api/dashboard-stats.php'), "require_permission_json('dashboard')"); });
test('AUTH-006', 'API verify-pass requires volunteer auth', function () { return str_contains(file_get_contents(__DIR__.'/../api/verify-pass.php'), 'current_volunteer()'); });
test('AUTH-007', 'PAYMENT_ADMIN restricted properly', function () { $p = admin_permissions('PAYMENT_ADMIN'); return in_array('payments', $p) && !in_array('settings', $p) && !in_array('admins', $p); });
test('AUTH-008', 'ENTRY_MANAGER restricted properly', function () { $p = admin_permissions('ENTRY_MANAGER'); return in_array('entries', $p) && !in_array('payments', $p); });
test('AUTH-009', 'SUPER_ADMIN has all permissions', function () { $p = admin_permissions('SUPER_ADMIN'); return in_array('admins', $p) && in_array('settings', $p); });

// === Business Logic ===
test('BIZ-001', 'Denied: disabled pass', function () { return check_entry_eligibility(['pass_status'=>'DISABLED','payment_status'=>'APPROVED']) === 'PASS_DISABLED'; });
test('BIZ-002', 'Denied: cancelled pass', function () { return check_entry_eligibility(['pass_status'=>'CANCELLED','payment_status'=>'APPROVED']) === 'PASS_CANCELLED'; });
test('BIZ-003', 'Denied: pending payment', function () { return check_entry_eligibility(['pass_status'=>'ACTIVE','payment_status'=>'PENDING']) === 'PAYMENT_PENDING'; });
test('BIZ-004', 'Denied: rejected payment', function () { return check_entry_eligibility(['pass_status'=>'ACTIVE','payment_status'=>'REJECTED']) === 'PAYMENT_REJECTED'; });
test('BIZ-005', 'Allowed: approved + active', function () { return check_entry_eligibility(['pass_status'=>'ACTIVE','payment_status'=>'APPROVED']) === ''; });
test('BIZ-006', 'Allowed: NOT_REQUIRED + active', function () { return check_entry_eligibility(['pass_status'=>'ACTIVE','payment_status'=>'NOT_REQUIRED']) === ''; });

// === Input Validation ===
test('VAL-001', 'Registration validates name length', function () { return str_contains(file_get_contents(__DIR__.'/../register.php'), "mb_strlen(\$old['name']) < 3"); });
test('VAL-002', 'Registration validates name chars', function () { return str_contains(file_get_contents(__DIR__.'/../register.php'), "preg_match('~^[\\p{L}\\p{M}\\s.\\'\\-]+\$~u'"); });
test('VAL-003', 'Registration validates mobile', function () { return str_contains(file_get_contents(__DIR__.'/../register.php'), '[6-9][0-9]{9}'); });
test('VAL-004', 'Registration validates email', function () { return str_contains(file_get_contents(__DIR__.'/../register.php'), 'FILTER_VALIDATE_EMAIL'); });
test('VAL-005', 'Registration has honeypot', function () { return str_contains(file_get_contents(__DIR__.'/../register.php'), 'Spam detected'); });
test('VAL-006', 'UTR validated with regex', function () { return str_contains(file_get_contents(__DIR__.'/../payment-submit.php'), '{8,30}'); });
test('VAL-007', 'Duplicate UTR check', function () { return str_contains(file_get_contents(__DIR__.'/../payment-submit.php'), 'student_id <> ?'); });
test('VAL-008', 'Payment date future check', function () { return str_contains(file_get_contents(__DIR__.'/../payment-submit.php'), "\$dateRaw > date('Y-m-d')"); });
test('VAL-009', 'Capacity check', function () { return str_contains(file_get_contents(__DIR__.'/../register.php'), '$isFull'); });

// === File Upload Security ===
test('UPL-001', 'MIME whitelist JPG/PNG/WEBP', function () { $s=file_get_contents(__DIR__.'/../includes/functions.php'); return str_contains($s,"'image/jpeg'") && str_contains($s,"'image/png'") && str_contains($s,"'image/webp'"); });
test('UPL-002', 'Random filenames', function () { return str_contains(file_get_contents(__DIR__.'/../includes/functions.php'), "bin2hex(random_bytes(16))"); });
test('UPL-003', 'getimagesize validation', function () { return str_contains(file_get_contents(__DIR__.'/../includes/functions.php'), 'getimagesize($tmp) === false'); });
test('UPL-004', 'is_uploaded_file check', function () { return str_contains(file_get_contents(__DIR__.'/../includes/functions.php'), 'is_uploaded_file($tmp)'); });

// === IDOR ===
test('IDOR-001', 'Status page uses PNR', function () { return str_contains(file_get_contents(__DIR__.'/../status.php'), 'normalize_pnr'); });
test('IDOR-002', 'Pass page uses PNR', function () { return str_contains(file_get_contents(__DIR__.'/../pass.php'), 'normalize_pnr'); });
test('IDOR-003', 'Payment page uses PNR', function () { return str_contains(file_get_contents(__DIR__.'/../payment.php'), 'normalize_pnr'); });

// === API ===
test('API-001', 'verify-pass requires POST', function () { return str_contains(file_get_contents(__DIR__.'/../api/verify-pass.php'), "!== 'POST'"); });
test('API-002', 'verify-pass validates token format', function () { return str_contains(file_get_contents(__DIR__.'/../api/verify-pass.php'), '/^[a-f0-9]{32}$/'); });
test('API-003', 'verify-pass validates PNR format', function () { return str_contains(file_get_contents(__DIR__.'/../api/verify-pass.php'), '~^[0-9]{10,15}$~'); });
test('API-004', 'search-participant escapes LIKE', function () { return str_contains(file_get_contents(__DIR__.'/../api/search-participant.php'), "str_replace(['\\\\', '%', '_']"); });
test('API-005', 'search-participant limits results', function () { return str_contains(file_get_contents(__DIR__.'/../api/search-participant.php'), 'LIMIT 12'); });
test('API-006', 'JSON no-store cache', function () { return str_contains(file_get_contents(__DIR__.'/../includes/functions.php'), 'Cache-Control: no-store'); });

// === Security Headers (POST-FIX) ===
test('HDR-001', 'X-Content-Type-Options', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), 'X-Content-Type-Options'); });
test('HDR-002', 'X-Frame-Options', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), 'X-Frame-Options'); });
test('HDR-003', 'Referrer-Policy', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), 'Referrer-Policy'); });
test('HDR-004', 'Content-Security-Policy (NEW)', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), 'Content-Security-Policy'); });
test('HDR-005', 'Permissions-Policy (NEW)', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), 'Permissions-Policy'); });
test('HDR-006', 'Directory listing disabled', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), 'Options -Indexes'); });
test('HDR-007', 'ServerSignature Off', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), 'ServerSignature Off'); });
test('HDR-008', '.sql files blocked', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), '.sql'); });
test('HDR-009', 'config/ blocked', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), 'config/'); });
test('HDR-010', 'includes/ blocked', function () { return str_contains(file_get_contents(__DIR__.'/../.htaccess'), 'includes/'); });

// === Error Handling ===
test('ERR-001', 'display_errors=0', function () { return str_contains(file_get_contents(__DIR__.'/../config/config.php'), "ini_set('display_errors', '0')"); });
test('ERR-002', 'log_errors=1', function () { return str_contains(file_get_contents(__DIR__.'/../config/config.php'), "ini_set('log_errors', '1')"); });
test('ERR-003', 'Brute-force protection', function () { return str_contains(file_get_contents(__DIR__.'/../admin/login.php'), 'adm_login_lock'); });
test('ERR-004', 'install.php lock file', function () { return str_contains(file_get_contents(__DIR__.'/../install.php'), 'installed.lock'); });

// === Admin Actions ===
test('ACT-001', 'Approve requires payment_verify', function () { return str_contains(file_get_contents(__DIR__.'/../admin/actions.php'), "admin_can('payment_verify')"); });
test('ACT-002', 'Reject requires reason', function () { return str_contains(file_get_contents(__DIR__.'/../admin/actions.php'), 'rejection reason is required'); });
test('ACT-003', 'Delete requires reason', function () { return str_contains(file_get_contents(__DIR__.'/../admin/actions.php'), 'A reason is required to delete'); });
test('ACT-004', 'Approve uses FOR UPDATE', function () { return str_contains(file_get_contents(__DIR__.'/../admin/actions.php'), 'FOR UPDATE'); });
test('ACT-005', 'Back URL prevents open redirect', function () { return str_contains(file_get_contents(__DIR__.'/../admin/actions.php'), "preg_match('~^admin/"); });

// === JavaScript ===
test('JS-001', 'app.js uses strict mode', function () { return str_contains(file_get_contents(__DIR__.'/../assets/js/app.js'), "'use strict'"); });
test('JS-002', 'scanner.js uses strict mode', function () { return str_contains(file_get_contents(__DIR__.'/../assets/js/scanner.js'), "'use strict'"); });
test('JS-003', 'Scanner sends CSRF header', function () { return str_contains(file_get_contents(__DIR__.'/../assets/js/scanner.js'), 'X-CSRF-Token'); });
test('JS-004', 'Scanner has cooldown', function () { return str_contains(file_get_contents(__DIR__.'/../assets/js/scanner.js'), 'COOLDOWN_MS'); });
test('JS-005', 'Scanner handles network errors', function () { return str_contains(file_get_contents(__DIR__.'/../assets/js/scanner.js'), '.catch(function'); });

// === Upload .htaccess ===
test('UPL-005', 'uploads/.htaccess exists', function () { return file_exists(__DIR__.'/../uploads/.htaccess'); });
test('UPL-006', 'uploads/.htaccess blocks PHP', function () { $s=@file_get_contents(__DIR__.'/../uploads/.htaccess'); return $s && str_contains($s, '.php') && str_contains($s, 'denied'); });

// ────── Results ──────
echo "\n============================================================\n";
echo " REGRESSION RESULTS\n";
echo "============================================================\n";
printf(" TOTAL: %d | PASS: %d | FAIL: %d | WARN: %d\n", $counts['total'], $counts['pass'], $counts['fail'], $counts['warn']);
echo "============================================================\n\n";

$fails = array_filter($results, fn($r) => $r['status'] === 'FAIL');
$warns = array_filter($results, fn($r) => $r['status'] === 'WARN');

if (!empty($fails)) {
    echo "FAILURES:\n";
    foreach ($fails as $f) {
        echo "  ✗ [{$f['id']}] {$f['desc']} — {$f['detail']}\n";
    }
    echo "\n";
}
if (!empty($warns)) {
    echo "WARNINGS:\n";
    foreach ($warns as $w) {
        echo "  ⚠ [{$w['id']}] {$w['desc']} — {$w['detail']}\n";
    }
    echo "\n";
}

echo "ALL TESTS:\n";
foreach ($results as $r) {
    $icon = $r['status'] === 'PASS' ? '✓' : ($r['status'] === 'FAIL' ? '✗' : '⚠');
    printf("  %s [%-8s] %s\n", $icon, $r['id'], $r['desc']);
}

echo "\nCompleted: " . date('Y-m-d H:i:s') . "\n";
echo ($counts['fail'] === 0) ? "\n★ ALL TESTS PASSED — REGRESSION CLEAN ★\n" : "\n⚠ {$counts['fail']} test(s) failed\n";
