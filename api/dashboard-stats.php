<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — API: live dashboard statistics (admin only).
 *
 * GET only. Requires admin session with 'dashboard' permission
 * (require_permission_json sends 401/403 JSON when needed).
 *
 * Response (no-store):
 *  {ok:true, stats:{...}, progress:{entered,total,percent},
 *   recent:[{time,name,pnr,gate,method,result,by}], updated_at}
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

header('Cache-Control: no-store');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_out(['ok' => false, 'message' => 'Method not allowed. Use GET.'], 405);
}

secure_session_start();
require_permission_json('dashboard');

try {
    /* ---------- students aggregate ---------- */
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS registered,
                SUM(payment_status = 'APPROVED') AS payment_approved,
                SUM(payment_status = 'PENDING') AS payment_pending,
                SUM(payment_status IN ('REJECTED','REFUNDED')) AS payment_rejected,
                SUM(pass_status = 'ACTIVE') AS active_passes,
                SUM(entry_status = 'ENTERED') AS entered,
                SUM(entry_status = 'NOT_ENTERED') AS not_entered,
                SUM(entry_method = 'MANUAL' AND entry_status = 'ENTERED') AS manual_entries
         FROM students"
    );
    $stmt->execute();
    $s = $stmt->fetch() ?: [];

    $registered       = (int) ($s['registered'] ?? 0);
    $paymentApproved  = (int) ($s['payment_approved'] ?? 0);
    $paymentPending   = (int) ($s['payment_pending'] ?? 0);
    $paymentRejected  = (int) ($s['payment_rejected'] ?? 0);
    $activePasses     = (int) ($s['active_passes'] ?? 0);
    $entered          = (int) ($s['entered'] ?? 0);
    $notEntered       = (int) ($s['not_entered'] ?? 0);
    $manualEntries    = (int) ($s['manual_entries'] ?? 0);

    /* ---------- entry_logs aggregate ---------- */
    $stmt = db()->prepare(
        "SELECT SUM(result = 'INVALID') AS invalid_scans,
                SUM(result = 'ALREADY_USED') AS duplicate_scans
         FROM entry_logs"
    );
    $stmt->execute();
    $l = $stmt->fetch() ?: [];

    $invalidScans  = (int) ($l['invalid_scans'] ?? 0);
    $duplicateScans = (int) ($l['duplicate_scans'] ?? 0);

    /* ---------- recent activity (last 12 entry logs) ---------- */
    $stmt = db()->prepare(
        'SELECT el.scan_time, el.gate_name, el.entry_method, el.result,
                s.name AS student_name, s.pnr_number, s.pass_id,
                v.name AS v_name, v.volunteer_id AS v_code,
                a.name AS a_name
         FROM entry_logs el
         LEFT JOIN students s ON s.id = el.student_id
         LEFT JOIN volunteers v ON v.id = el.volunteer_id
         LEFT JOIN admins a ON a.id = el.admin_id
         ORDER BY el.id DESC
         LIMIT 12'
    );
    $stmt->execute();

    $recent = [];
    foreach ($stmt->fetchAll() as $r) {
        if ($r['v_name'] !== null && (string) $r['v_name'] !== '') {
            $by = (string) $r['v_name'] . ' (' . (string) $r['v_code'] . ')';
        } elseif ($r['a_name'] !== null && (string) $r['a_name'] !== '') {
            $by = (string) $r['a_name'];
        } else {
            $by = '—';
        }
        $recent[] = [
            'time'   => date('g:i A', strtotime((string) $r['scan_time'])),
            'name'   => (($r['student_name'] !== null && (string) $r['student_name'] !== '') ? (string) $r['student_name'] : '—'),
            'pnr'    => (($r['pnr_number'] !== null && (string) $r['pnr_number'] !== '') ? (string) $r['pnr_number'] : (($r['pass_id'] !== null && (string) $r['pass_id'] !== '') ? (string) $r['pass_id'] : '—')),
            'gate'   => (($r['gate_name'] !== null && (string) $r['gate_name'] !== '') ? (string) $r['gate_name'] : '—'),
            'method' => (($r['entry_method'] !== null && (string) $r['entry_method'] !== '') ? (string) $r['entry_method'] : '—'),
            'result' => (string) $r['result'],
            'by'     => $by,
        ];
    }
} catch (Throwable $ex) {
    error_log('[FresherParty] dashboard-stats failed: ' . $ex->getMessage());
    json_out(['ok' => false, 'message' => 'Could not load statistics. Please retry.'], 500);
}

$total   = $registered;
$percent = ($total > 0) ? (int) round($entered / $total * 100) : 0;

json_out([
    'ok' => true,
    'stats' => [
        'registered'       => $registered,
        'payment_approved' => $paymentApproved,
        'payment_pending'  => $paymentPending,
        'payment_rejected' => $paymentRejected,
        'active_passes'    => $activePasses,
        'entered'          => $entered,
        'not_entered'      => $notEntered,
        'manual_entries'   => $manualEntries,
        'invalid_scans'    => $invalidScans,
        'duplicate_scans'  => $duplicateScans,
    ],
    'progress' => [
        'entered'  => $entered,
        'total'    => $total,
        'percent'  => $percent,
    ],
    'recent'     => $recent,
    'updated_at' => now_db(),
    'updated_ts' => time(),
]);
