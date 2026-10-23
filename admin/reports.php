<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin · CSV report exports.
 * perm: reports (each export additionally gated by reports_participants /
 * reports_payments / reports_entries). Handler runs before any page output.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('reports');

$gv = static function (string $key, string $default = ''): string {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
};

/* =========================================================
 * Export catalogue (SQL aliases match the header keys exactly)
 * ========================================================= */
$exports = [

    /* ---------- Participants (reports_participants) ---------- */
    'all_participants' => [
        'perm'  => 'reports_participants',
        'title' => 'All Participants',
        'desc'  => 'Every registered participant with payment, pass and entry state — keyed by college PNR number.',
        'icon'  => 'bi-people-fill',
        'class' => 'si-teal',
        'header' => ['pnr_number', 'pass_id', 'name', 'mobile', 'email', 'course', 'year',
                     'division', 'pass_type', 'payment_status', 'required_amount', 'paid_amount', 'pass_status',
                     'entry_status', 'entry_time', 'entry_method', 'created_at'],
        'sql' => 'SELECT pnr_number, pass_id, name, mobile, email, course, year, division,
                         pass_type, payment_status, required_amount, paid_amount, pass_status,
                         entry_status, entry_time, entry_method, created_at
                  FROM students ORDER BY id',
        'params' => [],
    ],
    'not_entered' => [
        'perm'  => 'reports_participants',
        'title' => 'Not Entered (Eligible)',
        'desc'  => 'Approved / not-required payment, active pass, still waiting at the gate.',
        'icon'  => 'bi-hourglass-split',
        'class' => 'si-amber',
        'header' => ['pnr_number', 'pass_id', 'name', 'mobile', 'course', 'year',
                     'payment_status', 'pass_type'],
        'sql' => "SELECT s.pnr_number, s.pass_id, s.name, s.mobile, s.course, s.year,
                         s.payment_status, s.pass_type
                  FROM students s
                  WHERE s.payment_status IN ('APPROVED','NOT_REQUIRED') AND s.pass_status = 'ACTIVE'
                    AND s.entry_status = 'NOT_ENTERED'
                  ORDER BY s.name",
        'params' => [],
    ],
    'entered' => [
        'perm'  => 'reports_participants',
        'title' => 'Entered Participants',
        'desc'  => 'Everyone inside with their first entry time, gate and method.',
        'icon'  => 'bi-door-open-fill',
        'class' => 'si-green',
        'header' => ['pnr_number', 'pass_id', 'name', 'mobile', 'entry_time', 'entry_method', 'gate', 'payment_status'],
        'sql' => "SELECT s.pnr_number, s.pass_id, s.name, s.mobile, s.entry_time, s.entry_method,
                         el.gate_name AS gate, s.payment_status
                  FROM students s
                  LEFT JOIN (SELECT student_id, MIN(id) AS first_id FROM entry_logs
                             WHERE result IN ('SUCCESS','MANUAL_APPROVED') GROUP BY student_id) f ON f.student_id = s.id
                  LEFT JOIN entry_logs el ON el.id = f.first_id
                  WHERE s.entry_status = 'ENTERED'
                  ORDER BY s.entry_time DESC",
        'params' => [],
    ],
    'manual_entries' => [
        'perm'  => 'reports_participants',
        'title' => 'Manual Entries',
        'desc'  => 'Entries marked manually by admins (incl. gate PNR entries), including the recorded reason.',
        'icon'  => 'bi-person-check-fill',
        'class' => 'si-rose',
        'header' => ['pnr_number', 'pass_id', 'name', 'mobile', 'entry_time', 'reason'],
        'sql' => "SELECT s.pnr_number, s.pass_id, s.name, s.mobile, s.entry_time,
                         el.reason AS reason
                  FROM students s
                  LEFT JOIN (SELECT student_id, MIN(id) AS first_id FROM entry_logs
                             WHERE result = 'MANUAL_APPROVED' GROUP BY student_id) f ON f.student_id = s.id
                  LEFT JOIN entry_logs el ON el.id = f.first_id
                  WHERE s.entry_method = 'MANUAL' AND s.entry_status = 'ENTERED'
                  ORDER BY s.entry_time DESC",
        'params' => [],
    ],

    /* ---------- Payments (reports_payments) ---------- */
    'paid_participants' => [
        'perm'  => 'reports_payments',
        'title' => 'Paid Participants',
        'desc'  => 'Approved payments plus every non-paid pass type (free/guest/etc.).',
        'icon'  => 'bi-patch-check-fill',
        'class' => 'si-green',
        'header' => ['pnr_number', 'pass_id', 'name', 'mobile', 'email', 'course', 'year',
                     'division', 'pass_type', 'payment_status', 'required_amount', 'paid_amount', 'pass_status',
                     'entry_status', 'entry_time', 'entry_method', 'created_at'],
        'sql' => "SELECT pnr_number, pass_id, name, mobile, email, course, year, division,
                         pass_type, payment_status, required_amount, paid_amount, pass_status,
                         entry_status, entry_time, entry_method, created_at
                  FROM students
                  WHERE payment_status = 'APPROVED' OR pass_type <> 'PAID'
                  ORDER BY id",
        'params' => [],
    ],
    'pending_payments' => [
        'perm'  => 'reports_payments',
        'title' => 'Pending Payments',
        'desc'  => 'Submitted payments waiting for verification (UTR + amount).',
        'icon'  => 'bi-hourglass-split',
        'class' => 'si-amber',
        'header' => ['payment_id', 'pnr_number', 'name', 'pass_id', 'utr_number', 'amount', 'payment_date', 'submitted_at', 'status'],
        'sql' => "SELECT p.id AS payment_id, s.pnr_number, s.name, s.pass_id, p.utr_number, p.amount,
                         p.payment_date, p.created_at AS submitted_at, p.status
                  FROM payments p
                  LEFT JOIN students s ON s.id = p.student_id
                  WHERE p.status = 'PENDING'
                  ORDER BY p.created_at DESC",
        'params' => [],
    ],
    'approved_payments' => [
        'perm'  => 'reports_payments',
        'title' => 'Approved Payments',
        'desc'  => 'Verified payments with who approved them and when.',
        'icon'  => 'bi-check2-circle',
        'class' => 'si-teal',
        'header' => ['payment_id', 'pnr_number', 'name', 'pass_id', 'utr_number', 'amount', 'payment_date',
                     'submitted_at', 'status', 'verified_by', 'verified_at'],
        'sql' => "SELECT p.id AS payment_id, s.pnr_number, s.name, s.pass_id, p.utr_number, p.amount, p.payment_date,
                         p.created_at AS submitted_at, p.status, a.name AS verified_by, p.verified_at
                  FROM payments p
                  LEFT JOIN students s ON s.id = p.student_id
                  LEFT JOIN admins a ON a.id = p.verified_by
                  WHERE p.status = 'APPROVED'
                  ORDER BY p.verified_at DESC",
        'params' => [],
    ],

    /* ---------- Entry logs (reports_entries) ---------- */
    'entry_logs' => [
        'perm'  => 'reports_entries',
        'title' => 'All Entry Logs',
        'desc'  => 'Every scan attempt — success, duplicate, invalid and denial.',
        'icon'  => 'bi-upc-scan',
        'class' => 'si-stone',
        'header' => ['id', 'scan_time', 'pnr_number', 'name', 'gate', 'method', 'result', 'reason', 'by', 'ip'],
        'sql' => 'SELECT el.id, el.scan_time, s.pnr_number, s.name AS name, el.gate_name AS gate,
                         el.entry_method AS method, el.result, el.reason,
                         COALESCE(v.name, a.name, \'\') AS `by`, el.ip_address AS ip
                  FROM entry_logs el
                  LEFT JOIN students s ON s.id = el.student_id
                  LEFT JOIN volunteers v ON v.id = el.volunteer_id
                  LEFT JOIN admins a ON a.id = el.admin_id
                  ORDER BY el.id DESC
                  LIMIT 10000',
        'params' => [],
    ],
    'invalid_scans' => [
        'perm'  => 'reports_entries',
        'title' => 'Invalid Scans',
        'desc'  => 'Unknown / broken QR tokens and unknown PNR numbers presented at the gates.',
        'icon'  => 'bi-x-octagon',
        'class' => 'si-rose',
        'header' => ['id', 'scan_time', 'pnr_number', 'name', 'gate', 'method', 'result', 'reason', 'by', 'ip'],
        'sql' => "SELECT el.id, el.scan_time, s.pnr_number, s.name AS name, el.gate_name AS gate,
                         el.entry_method AS method, el.result, el.reason,
                         COALESCE(v.name, a.name, '') AS `by`, el.ip_address AS ip
                  FROM entry_logs el
                  LEFT JOIN students s ON s.id = el.student_id
                  LEFT JOIN volunteers v ON v.id = el.volunteer_id
                  LEFT JOIN admins a ON a.id = el.admin_id
                  WHERE el.result = 'INVALID'
                  ORDER BY el.id DESC
                  LIMIT 10000",
        'params' => [],
    ],
    'duplicate_scans' => [
        'perm'  => 'reports_entries',
        'title' => 'Duplicate Scans',
        'desc'  => 'Passes scanned again after already being used.',
        'icon'  => 'bi-files',
        'class' => 'si-amber',
        'header' => ['id', 'scan_time', 'pnr_number', 'name', 'gate', 'method', 'result', 'reason', 'by', 'ip'],
        'sql' => "SELECT el.id, el.scan_time, s.pnr_number, s.name AS name, el.gate_name AS gate,
                         el.entry_method AS method, el.result, el.reason,
                         COALESCE(v.name, a.name, '') AS `by`, el.ip_address AS ip
                  FROM entry_logs el
                  LEFT JOIN students s ON s.id = el.student_id
                  LEFT JOIN volunteers v ON v.id = el.volunteer_id
                  LEFT JOIN admins a ON a.id = el.admin_id
                  WHERE el.result = 'ALREADY_USED'
                  ORDER BY el.id DESC
                  LIMIT 10000",
        'params' => [],
    ],
];

/* =========================================================
 * CSV download handler (before ANY page output)
 * ========================================================= */
$exportKey = $gv('export');
if ($exportKey !== '') {
    if (!isset($exports[$exportKey])) {
        set_flash('danger', 'Unknown report requested.');
        redirect_to('admin/reports.php');
    }
    $ex = $exports[$exportKey];
    if (!admin_can($ex['perm'])) {
        set_flash('danger', 'Your role (' . $admin['role'] . ') cannot export this report.');
        redirect_to('admin/reports.php');
    }
    try {
        $stmt = db()->prepare($ex['sql']);
        $stmt->execute($ex['params']);
        $raw = $stmt->fetchAll();
        $rows = [];
        foreach ($raw as $r) {
            $line = [];
            foreach ($ex['header'] as $col) {
                $line[] = (string) ($r[$col] ?? '');
            }
            $rows[] = $line;
        }
        output_csv('fresher-party-' . $exportKey . '-' . date('Y-m-d') . '.csv', $ex['header'], $rows);
    } catch (Throwable $ex2) {
        error_log('[FresherParty] report export: ' . $ex2->getMessage());
        set_flash('danger', 'Could not build this report. Please try again.');
        redirect_to('admin/reports.php');
    }
}

/* Row counts for the export cards */
foreach ($exports as $key => &$ex) {
    if (!admin_can($ex['perm'])) {
        $ex['count'] = null;
        continue;
    }
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM (' . $ex['sql'] . ') AS t');
        $stmt->execute($ex['params']);
        $ex['count'] = (int) $stmt->fetchColumn();
    } catch (Throwable $ex2) {
        $ex['count'] = null;
    }
}
unset($ex);

$activeNav = 'reports';
$pageTitle = 'Reports';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="alert alert-info small" role="alert">
  <i class="bi bi-file-earmark-arrow-down me-1"></i>All exports are CSV files with a UTF-8 BOM — they open cleanly in
  Excel and Google Sheets. Only the report sections your role can use are shown.
</div>

<div class="row g-3">
  <?php foreach ($exports as $key => $ex): ?>
    <?php if (!admin_can($ex['perm'])) { continue; } ?>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card card-shadow h-100">
        <div class="card-body p-4 d-flex flex-column">
          <div class="d-flex align-items-center gap-3 mb-2">
            <span class="stat-icon <?= e($ex['class']) ?>"><i class="bi <?= e($ex['icon']) ?>"></i></span>
            <div class="min-width-0">
              <h2 class="h6 fw-bold mb-0"><?= e($ex['title']) ?></h2>
              <span class="small text-muted-2">
                <?php if ($ex['count'] !== null): ?><?= (int) $ex['count'] ?> row<?= $ex['count'] === 1 ? '' : 's' ?><?php else: ?>report ready<?php endif; ?>
              </span>
            </div>
          </div>
          <p class="small text-muted-2 mb-3 flex-grow-1"><?= e($ex['desc']) ?></p>
          <a class="btn btn-teal btn-lg" href="<?= e(base_url('admin/reports.php?export=' . urlencode($key))) ?>">
            <i class="bi bi-download me-2"></i>Download CSV
          </a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/admin-footer.php';
