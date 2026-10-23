<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin · Payment verification queue.
 * Tabs by status (PENDING default) + search (UTR / PNR / student name / pass ID)
 * + 25-per-page list. Approvals POST straight to admin/actions.php;
 * rejections use ONE shared Bootstrap modal filled via data-attributes.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('payments');

$gv = static function (string $key, string $default = ''): string {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
};

$tabs   = ['PENDING', 'APPROVED', 'REJECTED', 'REFUNDED'];
$status = strtoupper($gv('status', 'PENDING'));
if (!in_array($status, $tabs, true)) {
    $status = 'PENDING';
}
$q         = $gv('q');
$canVerify = admin_can('payment_verify');

/* Counts per tab (single GROUP BY query) */
$counts = array_fill_keys($tabs, 0);
foreach (db()->query('SELECT status, COUNT(*) AS c FROM payments GROUP BY status') as $r) {
    $st = (string) $r['status'];
    if (isset($counts[$st])) {
        $counts[$st] = (int) $r['c'];
    }
}

/* Filtered query (LEFT JOIN so payments never hide if the student is gone) */
$where  = 'p.status = ?';
$params = [$status];
if ($q !== '') {
    $where .= ' AND (p.utr_number LIKE ? OR s.name LIKE ? OR s.pnr_number LIKE ? OR s.pass_id LIKE ?)';
    $like = '%' . addcslashes($q, '\\%_') . '%';
    array_push($params, $like, $like, $like, $like);
}

$stmt = db()->prepare("SELECT COUNT(*) FROM payments p LEFT JOIN students s ON s.id = p.student_id WHERE {$where}");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$perPage = 25;
$pages   = max(1, (int) ceil($total / $perPage));
$page    = is_numeric($gv('page', '1')) ? max(1, (int) $gv('page', '1')) : 1;
$page    = min($pages, max(1, $page));
$offset  = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT p.id, p.utr_number, p.amount, p.screenshot, p.payment_date, p.status, p.created_at,
            s.id AS student_id, s.name AS student_name, s.pnr_number, s.pass_id, s.photo, s.pass_type
     FROM payments p
     LEFT JOIN students s ON s.id = p.student_id
     WHERE {$where}
     ORDER BY p.created_at DESC, p.id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$back = 'admin/payments.php?status=' . urlencode($status)
    . ($q !== '' ? '&q=' . urlencode($q) : '')
    . ($page > 1 ? '&page=' . $page : '');

$pageUrl = static function (int $p) use ($status, $q): string {
    $u = 'admin/payments.php?status=' . urlencode($status);
    if ($q !== '') {
        $u .= '&q=' . urlencode($q);
    }
    return base_url($u . '&page=' . $p);
};

$avatar = static function ($photo, string $name, int $size = 38): string {
    $photo = is_string($photo) ? $photo : '';
    if ($photo !== '') {
        return '<img class="rounded-3" src="' . e(base_url($photo)) . '" alt="Photo of ' . e($name)
            . '" width="' . $size . '" height="' . $size . '" style="object-fit:cover">';
    }
    $initial = ($name !== '' && $name !== null) ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') : '?';
    return '<span class="avatar-thumb" style="width:' . $size . 'px;height:' . $size . 'px">' . e($initial) . '</span>';
};

$activeNav = 'payments';
$pageTitle = 'Payments';
$hideHeaderSearch = true;
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="chip-filter mb-3" role="toolbar" aria-label="Filter payments by status">
  <?php foreach ($tabs as $t): ?>
    <a class="btn btn-sm rounded-pill <?= $t === $status ? 'btn-teal' : 'btn-outline-teal' ?>"
       href="<?= e(base_url('admin/payments.php?status=' . urlencode($t) . ($q !== '' ? '&q=' . urlencode($q) : ''))) ?>">
      <?= e(ucfirst(strtolower($t))) ?>
      <span class="badge rounded-pill ms-1 <?= $t === $status ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) $counts[$t] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<form class="mb-3" method="get" action="<?= e(base_url('admin/payments.php')) ?>" role="search">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <div class="input-group">
    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
    <input class="form-control" type="search" name="q" value="<?= e($q) ?>"
           placeholder="Search by PNR number, UTR, student name or pass ID…" autocomplete="off">
    <button class="btn btn-teal" type="submit">Search</button>
    <?php if ($q !== ''): ?>
      <a class="btn btn-outline-secondary" href="<?= e(base_url('admin/payments.php?status=' . urlencode($status))) ?>">Clear</a>
    <?php endif; ?>
  </div>
</form>

<div class="card card-shadow">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-mobile table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th scope="col">Student</th>
            <th scope="col">PNR Number</th>
            <th scope="col">Pass ID</th>
            <th scope="col">Amount</th>
            <th scope="col">UTR / Ref</th>
            <th scope="col">Payment Date</th>
            <th scope="col">Submitted</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="9">
              <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <p class="mt-2 mb-0">No <?= e(strtolower($status)) ?> payments<?= $q !== '' ? ' matching “' . e($q) . '”' : '' ?>.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($rows as $p):
            $stName    = (string) ($p['student_name'] ?? '');
            $stPnr     = (string) ($p['pnr_number'] ?? '');
            $stPass    = (string) ($p['pass_id'] ?? '');
            $isPending = ((string) $p['status'] === 'PENDING');
        ?>
          <tr>
            <td data-label="Student">
              <div class="d-flex align-items-center gap-2">
                <?= $avatar($p['photo'] ?? null, $stName) ?>
                <div class="min-width-0">
                  <div class="fw-semibold text-truncate" style="max-width:190px"><?= e($stName !== '' ? $stName : '(student removed)') ?></div>
                  <div class="small text-muted-2 d-none d-md-inline"><?= e((string) ($p['pass_type'] ?? '')) ?></div>
                </div>
              </div>
            </td>
            <td data-label="PNR Number"><span class="font-monospace small fw-bold"><?= e($stPnr !== '' ? $stPnr : '—') ?></span></td>
            <td data-label="Pass ID"><span class="font-monospace small"><?= e($stPass !== '' ? $stPass : '—') ?></span></td>
            <td data-label="Amount"><span class="fw-semibold"><?= e(money($p['amount'])) ?></span></td>
            <td data-label="UTR"><span class="font-monospace small text-break"><?= e((string) $p['utr_number']) ?></span></td>
            <td data-label="Payment Date"><span class="small"><?= e(date_short((string) $p['payment_date'])) ?></span></td>
            <td data-label="Submitted"><span class="small"><?= e(dt_short((string) $p['created_at'])) ?></span></td>
            <td data-label="Status"><?= status_badge((string) $p['status']) ?></td>
            <td data-label="Actions" class="td-actions text-end">
              <div class="d-flex flex-wrap gap-1 justify-content-end">
                <a class="btn btn-sm btn-outline-teal" href="<?= e(base_url('admin/payment-view.php?id=' . (int) $p['id'])) ?>">
                  <i class="bi bi-eye"></i><span class="d-none d-md-inline ms-1">View</span>
                </a>
                <?php if ($isPending && $canVerify): ?>
                <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline"
                      data-confirm="Approve this payment of <?= e(money($p['amount'])) ?><?= $stName !== '' ? ' for ' . e($stName) : '' ?>?"
                      data-reason-prompt="Optional note for the audit log:">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve_payment">
                  <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <input type="hidden" name="back" value="<?= e($back) ?>">
                  <button type="submit" class="btn btn-sm btn-teal"><i class="bi bi-check-lg"></i><span class="d-none d-md-inline ms-1">Approve</span></button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                        data-payment-id="<?= (int) $p['id'] ?>"
                        data-student="<?= e($stName !== '' ? $stName : 'payment #' . (int) $p['id']) ?>">
                  <i class="bi bi-x-lg"></i><span class="d-none d-md-inline ms-1">Reject</span>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($total > 0): ?>
  <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span class="small text-muted-2">
      Showing <?= (int) ($total === 0 ? 0 : $offset + 1) ?>–<?= (int) min($total, $offset + $perPage) ?> of <?= (int) $total ?>
      <?= e(strtolower($status)) ?> payment<?= $total === 1 ? '' : 's' ?>
    </span>
    <?php if ($pages > 1): ?>
    <nav aria-label="Payments pages">
      <ul class="pagination pagination-sm mb-0 flex-wrap">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(max(1, $page - 1))) ?>">Prev</a></li>
        <?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): ?>
          <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($pageUrl($i)) ?>"><?= (int) $i ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(min($pages, $page + 1))) ?>">Next</a></li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($canVerify): ?>
<!-- Shared reject modal (JS copies payment id / student name from the button data attributes) -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true" aria-labelledby="rejectModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(base_url('admin/actions.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject_payment">
      <input type="hidden" name="payment_id" id="rejectPaymentId" value="">
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="rejectModalTitle"><i class="bi bi-x-circle text-danger me-2"></i>Reject payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Reject payment for <strong id="rejectStudentName"></strong>?</p>
        <label for="rejectReason" class="form-label small fw-semibold">Reason <span class="text-danger">*</span></label>
        <textarea class="form-control" id="rejectReason" name="reason" required maxlength="255" rows="3"
                  placeholder="e.g. UTR not found in bank statement / amount mismatch"></textarea>
        <div class="form-text">The student sees this reason and can submit a corrected payment.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i>Reject payment</button>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('rejectModal');
  if (!modal) { return; }
  modal.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    if (!btn) { return; }
    document.getElementById('rejectPaymentId').value = btn.getAttribute('data-payment-id') || '';
    document.getElementById('rejectStudentName').textContent = btn.getAttribute('data-student') || '';
    var ta = document.getElementById('rejectReason');
    if (ta) { ta.value = ''; }
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin-footer.php';
