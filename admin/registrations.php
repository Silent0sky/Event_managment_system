<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Participants list (search + filter chips + pagination).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('participants');
$activeNav = 'participants';
$pageTitle = 'Participants';

/* ---------------- Filters (whitelist) ---------------- */
$filters = [
    'all'          => 'All',
    'approved'     => 'Approved',
    'pending'      => 'Pending',
    'not_required' => 'Free Pass',
    'entered'      => 'Entered',
    'not_entered'  => 'Not Entered',
    'rejected'     => 'Rejected',
    'manual'       => 'Manual',
    'disabled'     => 'Disabled',
    'cancelled'    => 'Cancelled',
];
$conditions = [
    'all'          => '',
    'approved'     => "students.payment_status = 'APPROVED'",
    'pending'      => "students.payment_status = 'PENDING'",
    'rejected'     => "students.payment_status IN ('REJECTED','REFUNDED')",
    'not_required' => "students.payment_status = 'NOT_REQUIRED'",
    'entered'      => "students.entry_status = 'ENTERED'",
    'not_entered'  => "students.entry_status = 'NOT_ENTERED' AND students.payment_status IN ('APPROVED','NOT_REQUIRED')",
    'manual'       => "students.entry_method = 'MANUAL' AND students.entry_status = 'ENTERED'",
    'disabled'     => "students.pass_status = 'DISABLED'",
    'cancelled'    => "students.pass_status = 'CANCELLED'",
];

$search    = trim((string) ($_GET['s'] ?? ''));
$filterKey = (string) ($_GET['f'] ?? 'all');
if (!isset($filters[$filterKey])) {
    $filterKey = 'all';
}
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

/* ---------------- Build WHERE ---------------- */
$where  = [];
$params = [];
$cond   = $conditions[$filterKey];
if ($cond !== '') {
    $where[] = '(' . $cond . ')';
}
if (mb_strlen($search) >= 2) {
    $like = '%' . addcslashes($search, '\\%_') . '%';
    $where[] = '(students.pnr_number LIKE ? OR students.name LIKE ? OR students.mobile LIKE ?
                 OR students.pass_id LIKE ? OR students.email LIKE ?
                 OR EXISTS (SELECT 1 FROM payments p WHERE p.student_id = students.id AND p.utr_number LIKE ?))';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---------------- Count + page ---------------- */
$countStmt = db()->prepare("SELECT COUNT(*) FROM students {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;

$rowsStmt = db()->prepare(
    "SELECT * FROM students {$whereSql} ORDER BY students.id DESC LIMIT {$perPage} OFFSET {$offset}"
);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll() ?: [];

$from = $total === 0 ? 0 : $offset + 1;
$to   = min($offset + $perPage, $total);

/* ---------------- URL helpers (preserve state) ---------------- */
$buildQs = static function (array $overrides = []) use ($search, $filterKey, $page): string {
    $q = array_filter([
        's'    => $search,
        'f'    => $filterKey,
        'page' => $page,
    ], static fn ($v) => $v !== '' && $v !== null);
    $q = array_merge($q, $overrides);
    $qs = http_build_query($q);
    return 'admin/registrations.php' . ($qs !== '' ? '?' . $qs : '');
};
$backUrl = $buildQs();   // current listing state for quick-action "back"

$canCreate = admin_can('participant_create');
$canEntry  = admin_can('manual_entry');
$canPass   = admin_can('passes');

$hideHeaderSearch = true;

include __DIR__ . '/../includes/admin-header.php';
?>

<!-- Toolbar -->
<div class="d-flex align-items-center justify-content-between gap-2 mb-3">
  <div class="small text-muted-2">
    <?= $total === 0 ? 'No participants found.' : 'Showing <strong>' . (int) $from . '–' . (int) $to . '</strong> of <strong>' . (int) $total . '</strong>' ?>
  </div>
  <?php if ($canCreate): ?>
    <a class="btn btn-teal btn-sm fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" href="<?= e(base_url('admin/manual-register.php')) ?>">
      <i class="bi bi-person-plus-fill"></i><span>Add Participant</span>
    </a>
  <?php endif; ?>
</div>

<!-- Search & Filters -->
<div class="card card-shadow mb-3 border-0">
  <div class="card-body p-2 p-md-3">
    <form method="get" action="<?= e(base_url('admin/registrations.php')) ?>" role="search" class="mb-2">
      <input type="hidden" name="f" value="<?= e($filterKey) ?>">
      <div class="input-group">
        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
        <input class="form-control border-start-0 ps-0" type="search" name="s" value="<?= e($search) ?>"
               placeholder="Search by name, PNR, mobile, pass ID, UTR…" autocomplete="off" inputmode="text">
        <?php if ($search !== ''): ?>
          <a class="btn btn-outline-secondary border-start-0" href="<?= e(base_url('admin/registrations.php?f=' . urlencode($filterKey))) ?>" title="Clear search">
            <i class="bi bi-x-lg"></i>
          </a>
        <?php endif; ?>
        <button class="btn btn-teal px-3" type="submit">Search</button>
      </div>
    </form>

    <!-- Scrollable Filter Chips -->
    <div class="d-flex align-items-center gap-1 overflow-x-auto py-1" style="scrollbar-width: none; -webkit-overflow-scrolling: touch;">
      <?php foreach ($filters as $key => $label):
        $chipQs = http_build_query(array_filter(['f' => $key, 's' => $search], static fn ($v) => $v !== ''));
        $isActive = ($key === $filterKey);
      ?>
        <a class="btn btn-sm rounded-pill px-3 py-1 flex-shrink-0 <?= $isActive ? 'btn-teal fw-semibold shadow-xs' : 'btn-light border text-secondary' ?>"
           style="font-size: .8rem; white-space: nowrap;"
           href="<?= e(base_url('admin/registrations.php?' . $chipQs)) ?>">
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- List -->
<?php if (!$rows): ?>
  <div class="card card-shadow">
    <div class="card-body">
      <div class="empty-state">
        <i class="bi bi-people"></i>
        <p class="mb-0 mt-2">No participants match the current search / filter.</p>
        <a class="btn btn-outline-teal btn-sm mt-3" href="<?= e(base_url('admin/registrations.php')) ?>">Reset filters</a>
      </div>
    </div>
  </div>
<?php else: ?>

  <!-- MOBILE CARDS (Simple, clean, touch-friendly) -->
  <div class="d-md-none d-flex flex-column gap-2 mb-3">
    <?php foreach ($rows as $row):
      $entered      = ((string) $row['entry_status'] === 'ENTERED');
      $passViewable = in_array((string) $row['payment_status'], ['APPROVED', 'NOT_REQUIRED'], true);
      $stId         = (int) $row['id'];
      $stPnr        = (string) ($row['pnr_number'] ?? '');
      $stName       = (string) ($row['name'] ?? '');
    ?>
      <div class="card card-shadow border-0 rounded-3 p-3">
        <!-- Top: Avatar + Name/Course + Payment Badge -->
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
          <div class="d-flex align-items-center gap-2 min-width-0">
            <?php if (!empty($row['photo'])): ?>
              <img class="avatar-thumb flex-shrink-0" src="<?= e(base_url((string) $row['photo'])) ?>"
                   alt="" width="42" height="42" style="object-fit:cover; border-radius:50%;">
            <?php else: ?>
              <span class="avatar-thumb flex-shrink-0"><?= e(mb_strtoupper(mb_substr($stName, 0, 1))) ?></span>
            <?php endif; ?>
            <div class="min-width-0">
              <a class="fw-bold text-dark text-decoration-none text-truncate d-block" style="font-size:.95rem;"
                 href="<?= e(base_url('admin/participant-view.php?id=' . $stId)) ?>">
                <?= e($stName) ?>
              </a>
              <div class="small text-muted text-truncate" style="font-size:.78rem;">
                <?= e((string) $row['course']) ?> · <?= e(years_list()[(string) $row['year']] ?? (string) $row['year']) ?>
                <?php if (!empty($row['division'])): ?> · Div <?= e((string) $row['division']) ?><?php endif; ?>
              </div>
            </div>
          </div>
          <div class="flex-shrink-0 text-end">
            <?= status_badge((string) $row['payment_status']) ?>
          </div>
        </div>

        <!-- Info Strip: PNR, Pass ID, Entry Badge -->
        <div class="d-flex align-items-center justify-content-between bg-light rounded-2 px-2 py-1 mb-2 small" style="font-size:.8rem;">
          <div>
            <span class="text-muted">PNR:</span> <strong class="font-monospace text-dark"><?= e($stPnr !== '' ? $stPnr : '—') ?></strong>
          </div>
          <div>
            <span class="text-muted">Pass:</span> <span class="font-monospace"><?= e((string) $row['pass_id']) ?></span>
          </div>
          <div>
            <?= status_badge((string) $row['entry_status']) ?>
          </div>
        </div>

        <!-- Actions Row -->
        <div class="d-flex align-items-center gap-1 pt-2 border-top">
          <a class="btn btn-sm btn-outline-teal flex-fill py-1 d-inline-flex align-items-center justify-content-center gap-1"
             href="<?= e(base_url('admin/participant-view.php?id=' . $stId)) ?>">
            <i class="bi bi-eye"></i><span>View</span>
          </a>

          <?php if ($passViewable): ?>
            <a class="btn btn-sm btn-outline-secondary flex-fill py-1 d-inline-flex align-items-center justify-content-center gap-1"
               href="<?= e(base_url($stPnr !== '' ? 'pass.php?pnr=' . urlencode($stPnr) : 'pass.php?t=' . urlencode((string) $row['qr_token']))) ?>"
               target="_blank" rel="noopener">
              <i class="bi bi-ticket-perforated"></i><span>Pass</span>
            </a>
          <?php endif; ?>

          <?php if ($canEntry && !$entered): ?>
            <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline flex-fill"
                  data-reason-prompt="Reason for manual entry:" data-reason-required="1">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_entry">
              <input type="hidden" name="student_id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="reason" value="">
              <input type="hidden" name="back" value="<?= e($backUrl) ?>">
              <button class="btn btn-sm btn-outline-success w-100 py-1 d-inline-flex align-items-center justify-content-center gap-1" type="submit">
                <i class="bi bi-door-open"></i><span>Entry</span>
              </button>
            </form>
          <?php elseif ($canEntry && $entered): ?>
            <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline flex-fill"
                  data-reason-prompt="Reason for resetting entry (required):" data-reason-required="1">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_entry">
              <input type="hidden" name="student_id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="reason" value="">
              <input type="hidden" name="back" value="<?= e($backUrl) ?>">
              <button class="btn btn-sm btn-outline-warning w-100 py-1 d-inline-flex align-items-center justify-content-center gap-1" type="submit">
                <i class="bi bi-arrow-counterclockwise"></i><span>Reset</span>
              </button>
            </form>
          <?php endif; ?>

          <?php if ($canPass && (string) $row['pass_status'] === 'ACTIVE'): ?>
            <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline"
                  data-reason-prompt="Reason for disabling pass (optional):">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="disable_pass">
              <input type="hidden" name="student_id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="reason" value="">
              <input type="hidden" name="back" value="<?= e($backUrl) ?>">
              <button class="btn btn-sm btn-outline-danger px-2 py-1" type="submit" title="Disable pass">
                <i class="bi bi-slash-circle"></i>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- DESKTOP TABLE -->
  <div class="card card-shadow d-none d-md-block">
    <div class="card-body p-2 p-md-3">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">Participant</th>
              <th scope="col">PNR Number</th>
              <th scope="col">Pass ID</th>
              <th scope="col" class="d-none d-md-table-cell">Mobile</th>
              <th scope="col">Pass Type</th>
              <th scope="col">Payment</th>
              <th scope="col">Entry</th>
              <th scope="col" class="d-none d-lg-table-cell">Registered</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
              $entered      = (string) $row['entry_status'] === 'ENTERED';
              $passViewable = in_array((string) $row['payment_status'], ['APPROVED', 'NOT_REQUIRED'], true);
              $stId         = (int) $row['id'];
              $stPnr        = (string) ($row['pnr_number'] ?? '');
              ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if (!empty($row['photo'])): ?>
                    <img class="avatar-thumb" src="<?= e(base_url((string) $row['photo'])) ?>"
                         alt="Photo of <?= e((string) $row['name']) ?>" width="42" height="42" loading="lazy">
                  <?php else: ?>
                    <span class="avatar-thumb"><?= e(mb_strtoupper(mb_substr((string) $row['name'], 0, 1))) ?></span>
                  <?php endif; ?>
                  <div class="min-width-0">
                    <a class="fw-semibold text-decoration-none" href="<?= e(base_url('admin/participant-view.php?id=' . $stId)) ?>"><?= e((string) $row['name']) ?></a>
                    <div class="small text-muted-2 text-truncate"><?= e((string) $row['course']) ?> · <?= e(years_list()[(string) $row['year']] ?? (string) $row['year']) ?></div>
                  </div>
                </div>
              </td>
              <td><span class="font-monospace small fw-bold"><?= e($stPnr !== '' ? $stPnr : '—') ?></span></td>
              <td><span class="font-monospace small"><?= e((string) $row['pass_id']) ?></span></td>
              <td class="d-none d-md-table-cell small"><?= e((string) ($row['mobile'] ?: '—')) ?></td>
              <td><?= status_badge((string) $row['pass_type']) ?></td>
              <td><?= status_badge((string) $row['payment_status']) ?></td>
              <td>
                <?= status_badge((string) $row['entry_status']) ?>
                <?php if ($entered && (string) $row['entry_method'] !== ''): ?>
                  <div class="small text-muted-2 mt-1"><?= e((string) $row['entry_method']) ?></div>
                <?php endif; ?>
              </td>
              <td class="d-none d-lg-table-cell small text-muted-2"><?= e(date_short((string) $row['created_at'])) ?></td>
              <td class="td-actions">
                <div class="d-flex flex-wrap align-items-center gap-1">
                  <a class="btn btn-sm btn-outline-teal" href="<?= e(base_url('admin/participant-view.php?id=' . $stId)) ?>" title="View profile" aria-label="View <?= e((string) $row['name']) ?>">
                    <i class="bi bi-eye"></i><span class="visually-hidden">View</span>
                  </a>
                  <?php if ($passViewable): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url($stPnr !== '' ? 'pass.php?pnr=' . urlencode($stPnr) : 'pass.php?t=' . urlencode((string) $row['qr_token']))) ?>" target="_blank" rel="noopener" title="View pass" aria-label="View pass of <?= e((string) $row['name']) ?>">
                      <i class="bi bi-ticket-perforated"></i><span class="visually-hidden">View pass</span>
                    </a>
                  <?php endif; ?>

                  <?php if ($canEntry && !$entered): ?>
                  <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline"
                        data-reason-prompt="Reason for manual entry:" data-reason-required="1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_entry">
                    <input type="hidden" name="student_id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <input type="hidden" name="back" value="<?= e($backUrl) ?>">
                    <button class="btn btn-sm btn-outline-teal" type="submit" title="Mark as entered" aria-label="Mark <?= e((string) $row['name']) ?> as entered">
                      <i class="bi bi-door-open"></i><span class="visually-hidden">Mark entry</span>
                    </button>
                  </form>
                  <?php elseif ($canEntry && $entered): ?>
                  <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline"
                        data-reason-prompt="Reason for resetting entry (required):" data-reason-required="1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_entry">
                    <input type="hidden" name="student_id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <input type="hidden" name="back" value="<?= e($backUrl) ?>">
                    <button class="btn btn-sm btn-outline-warning" type="submit" title="Reset entry" aria-label="Reset entry of <?= e((string) $row['name']) ?>">
                      <i class="bi bi-arrow-counterclockwise"></i><span class="visually-hidden">Reset entry</span>
                    </button>
                  </form>
                  <?php endif; ?>

                  <?php if ($canPass && (string) $row['pass_status'] === 'ACTIVE'): ?>
                  <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline"
                        data-reason-prompt="Reason for disabling pass (optional):">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="disable_pass">
                    <input type="hidden" name="student_id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <input type="hidden" name="back" value="<?= e($backUrl) ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Disable pass" aria-label="Disable pass of <?= e((string) $row['name']) ?>">
                      <i class="bi bi-slash-circle"></i><span class="visually-hidden">Disable pass</span>
                    </button>
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
  </div>
<?php endif; ?>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<nav aria-label="Participants pagination" class="mt-3">
  <ul class="pagination pagination-sm flex-wrap justify-content-center mb-0">
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e(base_url($buildQs(['page' => max(1, $page - 1)]))) ?>" aria-label="Previous page">&laquo;</a>
    </li>
    <?php
    $startPage = max(1, $page - 3);
    $endPage   = min($pages, $page + 3);
    if ($startPage > 1): ?>
      <li class="page-item"><a class="page-link" href="<?= e(base_url($buildQs(['page' => 1]))) ?>">1</a></li>
      <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
    <?php endif; ?>
    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="<?= e(base_url($buildQs(['page' => $p]))) ?>"><?= (int) $p ?></a>
      </li>
    <?php endfor; ?>
    <?php if ($endPage < $pages): ?>
      <?php if ($endPage < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
      <li class="page-item"><a class="page-link" href="<?= e(base_url($buildQs(['page' => $pages]))) ?>"><?= (int) $pages ?></a></li>
    <?php endif; ?>
    <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e(base_url($buildQs(['page' => min($pages, $page + 1)]))) ?>" aria-label="Next page">&raquo;</a>
    </li>
  </ul>
</nav>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
