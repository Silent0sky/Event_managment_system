<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — API: participant search (admin only).
 *
 * GET only, ?q=<min 2 chars>. Requires admin session with 'manual_entry'
 * permission. LIKE search on PNR number (the universal student ID), name,
 * mobile, pass_id — LIMIT 12. Read-only, so no CSRF token is needed.
 *
 * Response: {ok:true, results:[{id,pnr,pass_id,name,mobile,course,year,
 *           division,pass_type,payment_status,pass_status,entry_status}]}
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
require_permission_json('manual_entry');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    json_out(['ok' => false, 'message' => 'Type at least 2 characters'], 400);
}

/* Escape LIKE wildcards so user input cannot widen the pattern */
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

try {
    $stmt = db()->prepare(
        'SELECT id, pnr_number, pass_id, name, mobile, course, year, division,
                pass_type, payment_status, pass_status, entry_status
         FROM students
         WHERE pnr_number LIKE ? OR name LIKE ? OR mobile LIKE ? OR pass_id LIKE ?
         ORDER BY (pnr_number = ?) DESC, name ASC
         LIMIT 12'
    );
    $stmt->execute([$like, $like, $like, $like, $q]);

    $results = [];
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'id'              => (int) $r['id'],
            'pnr'             => ($r['pnr_number'] !== null ? (string) $r['pnr_number'] : ''),
            'pass_id'         => (string) $r['pass_id'],
            'name'            => (string) $r['name'],
            'mobile'          => ($r['mobile'] !== null ? (string) $r['mobile'] : ''),
            'course'          => (string) $r['course'],
            'year'            => (string) $r['year'],
            'division'        => (string) $r['division'],
            'pass_type'       => (string) $r['pass_type'],
            'payment_status'  => (string) $r['payment_status'],
            'pass_status'     => (string) $r['pass_status'],
            'entry_status'    => (string) $r['entry_status'],
        ];
    }
} catch (Throwable $ex) {
    error_log('[FresherParty] search-participant failed: ' . $ex->getMessage());
    json_out(['ok' => false, 'message' => 'Search failed. Please retry.'], 500);
}

json_out(['ok' => true, 'results' => $results]);
