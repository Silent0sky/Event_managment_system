<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin · Entry management.
 * Tabs: entered (default) / not_entered (mobile-friendly cards) / live (auto-refresh feed).
 * The live tab re-fetches itself every 8s via entries.php?tab=live&fragment=1 which
 * renders ONLY the table markup (layout skipped) and exits.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('entries');

$gv = static function (string $key, string $default = ''): string {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
};

$tab = strtolower($gv('tab', 'entered'));
if (!in_array($tab, ['entered', 'not_entered', 'live'], true)) {
    $tab = 'entered';
}
$canManual = admin_can('manual_entry');

$avatar = static function ($photo, string $name, int $size = 44): string {
    $photo = is_string($photo) ? $photo : '';
    if ($photo !== '') {
        return '<img class="rounded-3" src="' . e(base_url($photo)) . '" alt="Photo of ' . e($name)
            . '" width="' . $size . '" height="' . $size . '" style="object-fit:cover">';
    }
    $initial = ($name !== '' && $name !== null) ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') : '?';
    return '<span class="avatar-thumb" style="width:' . $size . 'px;height:' . $size . 'px">' . e($initial) . '</span>';
};

/* Live feed table renderer — used both for the page AND the ?fragment=1 AJAX response */
$renderLiveTable = static function (): void {
    $stmt = db()->prepare(
        'SELECT el.id, el.scan_time, el.result, el.entry_method, el.reason, el.gate_name,
                s.name AS student_name, s.pnr_number, s.pass_id,
                v.name AS volunteer_name, a.name AS admin_name
         FROM entry_logs el
         LEFT JOIN students s ON s.id = el.student_id
         LEFT JOIN volunteers v ON v.id = el.volunteer_id
         LEFT JOIN admins a ON a.id = el.admin_id
         ORDER BY el.id DESC
         LIMIT 50'
    );
    $stmt->execute();
    $logs = $stmt->fetchAll();
    echo '<table class="table table-mobile table-hover align-middle mb-0">';
    echo '<thead class="table-light"><tr>'
        . '<th scope="col">Time</th><th scope="col">Student</th><th scope="col">Gate</th>'
        . '<th scope="col">Method</th><th scope="col">Result</th><th scope="col">Reason</th><th scope="col">By</th>'
        . '</tr></thead><tbody>';
    if (!$logs) {
        echo '<tr><td colspan="7"><div class="empty-state"><i class="bi bi-activity"></i>'
            . '<p class="mt-2 mb-0">No scans yet — the feed fills up as gates start scanning.</p></div></td></tr>';
    }
    foreach ($logs as $l) {
        $stName = (string) ($l['student_name'] ?? '');
        $stPnr  = (string) ($l['pnr_number'] ?? '');
        $by     = (string) ($l['volunteer_name'] ?? ($l['admin_name'] ?? ''));
        $byRole = $l['volunteer_name'] !== null ? 'Volunteer' : ($l['admin_name'] !== null ? 'Admin' : 'System');
        echo '<tr>';
        echo '<td data-label="Time"><span class="small">' . e(dt_short((string) $l['scan_time'])) . '</span></td>';
        echo '<td data-label="Student"><div class="fw-semibold text-truncate" style="max-width:180px">' . e($stName !== '' ? $stName : '(unknown)') . '</div>'
            . '<span class="small font-monospace text-muted-2">' . e($stPnr !== '' ? 'PNR ' . $stPnr : '—') . '</span></td>';
        echo '<td data-label="Gate"><span class="small">' . e((string) ($l['gate_name'] ?: '—')) . '</span></td>';
        echo '<td data-label="Method"><span class="small font-monospace">' . e((string) ($l['entry_method'] ?: '—')) . '</span></td>';
        echo '<td data-label="Result">' . status_badge((string) $l['result']) . '</td>';
        echo '<td data-label="Reason"><span class="small text-muted-2 text-break">' . e((string) ($l['reason'] ?: '—')) . '</span></td>';
        echo '<td data-label="By"><span class="small">' . e($by !== '' ? $by : 'System') . '</span>'
            . '<span class="small text-muted-2 d-block">' . e($byRole) . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
};

/* ---------- Live-tab AJAX fragment: markup only, then stop ---------- */
if ($tab === 'live' && $gv('fragment') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $renderLiveTable();
    exit;
}

/* ---------- Tab counts ---------- */
$enteredCount = (int) db()->query("SELECT COUNT(*) FROM students WHERE entry_status = 'ENTERED'")->fetchColumn();
$eligibleCount = (int) db()->query(
    "SELECT COUNT(*) FROM students
     WHERE entry_status = 'NOT_ENTERED' AND pass_status = 'ACTIVE'
       AND payment_status IN ('APPROVED','NOT_REQUIRED')"
)->fetchColumn();
$pendingNotEntered = (int) db()->query(
    "SELECT COUNT(*) FROM students
     WHERE entry_status = 'NOT_ENTERED' AND pass_status = 'ACTIVE' AND payment_status = 'PENDING'"
)->fetchColumn();
$scansToday = (int) db()->query(
    'SELECT COUNT(*) FROM entry_logs WHERE scan_time >= ' . db()->quote(today_start_db())
)->fetchColumn();

$includePending = ($tab === 'not_entered' && $gv('include_pending') === '1');

/* ---------- ENTERED / NOT_ENTERED pagination (25/page) ---------- */
$page = 1;
$pages = 1;
$total = 0;
$offset = 0;
$perPage = 25;
$rows = [];

if ($tab === 'entered') {
    $total = $enteredCount;
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = is_numeric($gv('page', '1')) ? (int) $gv('page', '1') : 1;
    $page  = min($pages, max(1, $page));
    $offset = ($page - 1) * $perPage;
    $stmt = db()->prepare(
        "SELECT id, pnr_number, pass_id, name, photo, pass_type, payment_status, entry_time, entry_method, entry_status
         FROM students
         WHERE entry_status = 'ENTERED'
         ORDER BY entry_time DESC, id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
} elseif ($tab === 'not_entered') {
    $where = "entry_status = 'NOT_ENTERED' AND pass_status = 'ACTIVE' AND payment_status IN ('APPROVED','NOT_REQUIRED'"
        . ($includePending ? ",'PENDING')" : ')');
    $stmt = db()->prepare("SELECT COUNT(*) FROM students WHERE {$where}");
    $stmt->execute();
    $total = (int) $stmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = is_numeric($gv('page', '1')) ? (int) $gv('page', '1') : 1;
    $page  = min($pages, max(1, $page));
    $offset = ($page - 1) * $perPage;
    $stmt = db()->prepare(
        "SELECT id, pnr_number, pass_id, name, mobile, photo, pass_type, payment_status, pass_status, entry_status
         FROM students
         WHERE {$where}
         ORDER BY name ASC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$back = 'admin/entries.php?tab=' . urlencode($tab);
if ($tab === 'not_entered' && $includePending) {
    $back .= '&include_pending=1';
}
if ($tab !== 'live' && $page > 1) {
    $back .= '&page=' . $page;
}

$pageUrl = static function (int $p) use ($tab, $includePending): string {
    $u = 'admin/entries.php?tab=' . urlencode($tab);
    if ($includePending) {
        $u .= '&include_pending=1';
    }
    return base_url($u . '&page=' . $p);
};

$activeNav = 'entries';
$pageTitle = 'Entries';
$hideHeaderSearch = true;
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="row g-3 align-items-stretch mb-3">
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <span class="stat-icon si-green"><i class="bi bi-door-open-fill"></i></span>
      <div><div class="stat-value"><?= (int) $enteredCount ?></div><div class="stat-label">Entered</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <span class="stat-icon si-amber"><i class="bi bi-hourglass-split"></i></span>
      <div><div class="stat-value"><?= (int) $eligibleCount ?></div><div class="stat-label">Eligible, waiting</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <span class="stat-icon si-rose"><i class="bi bi-cash-coin"></i></span>
      <div><div class="stat-value"><?= (int) $pendingNotEntered ?></div><div class="stat-label">Payment pending</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <span class="stat-icon si-teal"><i class="bi bi-upc-scan"></i></span>
      <div><div class="stat-value"><?= (int) $scansToday ?></div><div class="stat-label">Scans today</div></div>
    </div>
  </div>
</div>

<div class="chip-filter mb-3" role="toolbar" aria-label="Entry views">
  <a class="btn btn-sm rounded-pill <?= $tab === 'entered' ? 'btn-teal' : 'btn-outline-teal' ?>"
     href="<?= e(base_url('admin/entries.php?tab=entered')) ?>">
    Entered <span class="badge rounded-pill ms-1 <?= $tab === 'entered' ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) $enteredCount ?></span>
  </a>
  <a class="btn btn-sm rounded-pill <?= $tab === 'not_entered' ? 'btn-amber' : 'btn-outline-teal' ?>"
     href="<?= e(base_url('admin/entries.php?tab=not_entered')) ?>">
    Not Entered <span class="badge rounded-pill ms-1 <?= $tab === 'not_entered' ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) ($eligibleCount + $pendingNotEntered) ?></span>
  </a>
  <a class="btn btn-sm rounded-pill <?= $tab === 'live' ? 'btn-teal' : 'btn-outline-teal' ?>"
     href="<?= e(base_url('admin/entries.php?tab=live')) ?>">
    <span class="spinner-grow spinner-grow-sm me-1" style="width:.55rem;height:.55rem" role="status" aria-hidden="true"></span>
    Live Feed <span class="badge rounded-pill ms-1 <?= $tab === 'live' ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) $scansToday ?> today</span>
  </a>
</div>

<?php if ($tab === 'entered'): ?>
  <div class="card card-shadow">
    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
      <span class="card-header-title"><i class="bi bi-door-open-fill me-2 text-teal"></i>Participants inside</span>
      <span class="small text-muted-2">Newest entries first</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-mobile table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th scope="col">Student</th>
              <th scope="col">Reg ID</th>
              <th scope="col">Pass ID</th>
              <th scope="col">Entry Time</th>
              <th scope="col">Gate</th>
              <th scope="col">Method</th>
              <th scope="col">By</th>
              <th scope="col" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8">
              <div class="empty-state"><i class="bi bi-door-closed"></i><p class="mt-2 mb-0">Nobody has entered yet.</p></div>
            </td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $st):
              $first = first_entry_info((int) $st['id']);
          ?>
            <tr>
              <td data-label="Student">
                <div class="d-flex align-items-center gap-2">
                  <?= $avatar($st['photo'] ?? null, (string) $st['name']) ?>
                  <div class="min-width-0">
                    <div class="fw-semibold text-truncate" style="max-width:170px"><?= e((string) $st['name']) ?></div>
                    <div class="small text-muted-2"><?= e((string) $st['pass_type']) ?></div>
                  </div>
                </div>
              </td>
              <td data-label="PNR Number"><span class="font-monospace small fw-bold"><?= e((string) ($st['pnr_number'] ?: '—')) ?></span></td>
              <td data-label="Pass ID"><span class="font-monospace small"><?= e((string) $st['pass_id']) ?></span></td>
              <td data-label="Entry Time"><span class="fw-semibold"><?= e(time_short((string) $st['entry_time'])) ?></span>
                <span class="small text-muted-2 d-block"><?= e(date_short((string) $st['entry_time'])) ?></span></td>
              <td data-label="Gate"><span class="small"><?= e($first ? (string) $first['gate'] : '—') ?></span></td>
              <td data-label="Method"><span class="small font-monospace"><?= e($first ? (string) $first['method'] : ((string) ($st['entry_method'] ?: '—'))) ?></span></td>
              <td data-label="By"><span class="small"><?= e($first ? (string) $first['by'] : '—') ?></span></td>
              <td data-label="Actions" class="td-actions text-end">
                <div class="d-flex flex-wrap gap-1 justify-content-end">
                  <a class="btn btn-sm btn-outline-teal" href="<?= e(base_url('admin/participant-view.php?id=' . (int) $st['id'])) ?>">
                    <i class="bi bi-eye"></i><span class="d-none d-md-inline ms-1">View</span>
                  </a>
                  <?php if ($canManual): ?>
                  <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline"
                        data-confirm="Reset the entry for <?= e((string) $st['name']) ?>? They will be able to enter again."
                        data-reason-prompt="Reason for resetting this entry (required):"
                        data-reason-required="1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_entry">
                    <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <input type="hidden" name="back" value="<?= e($back) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise"></i><span class="d-none d-md-inline ms-1">Reset Entry</span></button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span class="small text-muted-2">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
      <nav aria-label="Entered pages">
        <ul class="pagination pagination-sm mb-0 flex-wrap">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(max(1, $page - 1))) ?>">Prev</a></li>
          <?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($pageUrl($i)) ?>"><?= (int) $i ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(min($pages, $page + 1))) ?>">Next</a></li>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'not_entered'): ?>
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="small text-muted-2">
      <strong><?= (int) ($eligibleCount + $pendingNotEntered) ?></strong> still to enter ·
      <strong><?= (int) $eligibleCount ?></strong> eligible now<?php if ($pendingNotEntered > 0): ?> ·
      <strong><?= (int) $pendingNotEntered ?></strong> pending payment<?php endif; ?>
    </div>
    <a class="btn btn-sm rounded-pill <?= $includePending ? 'btn-amber' : 'btn-outline-teal' ?>"
       href="<?= e(base_url('admin/entries.php?tab=not_entered' . ($includePending ? '' : '&include_pending=1'))) ?>">
      <i class="bi bi-funnel me-1"></i><?= $includePending ? 'Hide pending payments' : 'Include pending payments' ?>
      <?php if ($pendingNotEntered > 0): ?><span class="badge rounded-pill ms-1 <?= $includePending ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) $pendingNotEntered ?></span><?php endif; ?>
    </a>
  </div>

  <?php if (!$rows): ?>
    <div class="card card-shadow"><div class="card-body">
      <div class="empty-state">
        <i class="bi bi-check2-circle"></i>
        <p class="mt-2 mb-0">Nothing waiting here — everyone<?= $includePending ? ', including pending payments,' : '' ?> has entered.</p>
      </div>
    </div></div>
  <?php else: ?>
  <div class="row g-3">
    <?php foreach ($rows as $st):
        $isPendingPay = ((string) $st['payment_status'] === 'PENDING');
    ?>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card card-shadow h-100">
        <div class="card-body p-3 d-flex flex-column">
          <div class="d-flex align-items-center gap-2 mb-2">
            <?= $avatar($st['photo'] ?? null, (string) $st['name']) ?>
            <div class="min-width-0 flex-grow-1">
              <div class="fw-semibold text-truncate" style="max-width:170px"><?= e((string) $st['name']) ?></div>
              <div class="small font-monospace text-muted-2 text-truncate"><?= e((string) ($st['pnr_number'] ?: '— no PNR')) ?> · <?= e((string) $st['pass_id']) ?></div>
            </div>
            <?= status_badge((string) $st['payment_status']) ?>
          </div>
          <div class="small d-flex flex-wrap gap-3 mb-2">
            <span class="text-muted-2"><?= e((string) $st['pass_type']) ?></span>
            <?php if (!empty($st['mobile'])): ?>
              <a class="fw-semibold text-decoration-none" href="tel:<?= e((string) $st['mobile']) ?>"><i class="bi bi-telephone me-1"></i><?= e((string) $st['mobile']) ?></a>
            <?php else: ?>
              <span class="text-muted-2">No mobile</span>
            <?php endif; ?>
          </div>
          <?php if ($isPendingPay): ?>
            <div class="alert alert-warning py-2 px-3 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Payment still PENDING — entry will be denied until approved.</div>
          <?php endif; ?>
          <div class="mt-auto d-grid gap-2">
            <?php if ($canManual): ?>
              <form method="post" action="<?= e(base_url('admin/actions.php')) ?>"
                    data-confirm="Mark <?= e((string) $st['name']) ?> as ENTERED now?"
                    data-reason-prompt="Reason (e.g. QR damaged; college ID verified):"
                    data-reason-required="1">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_entry">
                <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                <input type="hidden" name="reason" value="">
                <input type="hidden" name="back" value="<?= e($back) ?>">
                <button type="submit" class="btn btn-teal btn-lg w-100"><i class="bi bi-person-check-fill me-2"></i>MARK ENTRY</button>
              </form>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('admin/participant-view.php?id=' . (int) $st['id'])) ?>">
              <i class="bi bi-eye me-1"></i>View participant
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
  <nav class="mt-3" aria-label="Waiting pages">
    <ul class="pagination pagination-sm flex-wrap justify-content-center">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(max(1, $page - 1))) ?>">Prev</a></li>
      <?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($pageUrl($i)) ?>"><?= (int) $i ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(min($pages, $page + 1))) ?>">Next</a></li>
    </ul>
  </nav>
  <?php endif; ?>

<?php else: /* live */ ?>
  <div class="card card-shadow">
    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
      <span class="card-header-title"><i class="bi bi-activity me-2 text-teal"></i>Live scan feed · last 50</span>
      <span class="small text-muted-2"><span class="badge text-bg-teal rounded-pill">AUTO</span> refreshes every 8s</span>
    </div>
    <div class="card-body p-0" id="liveWrap">
      <?php $renderLiveTable(); ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'live'): ?>
<script>
(function () {
  'use strict';
  var wrap = document.getElementById('liveWrap');
  if (!wrap) { return; }
  var url = <?= json_encode(base_url('admin/entries.php') . '?tab=live&fragment=1') ?>;
  setInterval(function () {
    fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        if (typeof t === 'string' && t.trim().indexOf('<table') === 0) {
          wrap.innerHTML = t.trim();
        }
      })
      .catch(function () { /* silent — next tick retries */ });
  }, 8000);
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin-footer.php';
