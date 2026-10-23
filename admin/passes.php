<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin · Pass control (activate / disable / cancel).
 * perm: passes. Filter chips (status) + pass-type select + search, 25/page.
 * All mutations POST to admin/actions.php (disable_pass / activate_pass / cancel_pass).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('passes');

$gv = static function (string $key, string $default = ''): string {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
};

$statusFilters = [
    'all'       => 'All',
    'active'    => 'Active',
    'disabled'  => 'Disabled',
    'cancelled' => 'Cancelled',
];
$f    = strtolower($gv('f', 'all'));
if (!isset($statusFilters[$f])) {
    $f = 'all';
}
$typeF = strtoupper($gv('type'));
if (!in_array($typeF, pass_types(), true)) {
    $typeF = '';
}
$s = $gv('s');

/* Summary counts per pass status */
$passCounts = ['ACTIVE' => 0, 'DISABLED' => 0, 'CANCELLED' => 0];
foreach (db()->query('SELECT pass_status, COUNT(*) AS c FROM students GROUP BY pass_status') as $r) {
    $st = (string) $r['pass_status'];
    if (isset($passCounts[$st])) {
        $passCounts[$st] = (int) $r['c'];
    }
}

/* Filtered query */
$where  = '1=1';
$params = [];
if ($f !== 'all') {
    $where .= ' AND s.pass_status = ?';
    $params[] = strtoupper($f);
}
if ($typeF !== '') {
    $where .= ' AND s.pass_type = ?';
    $params[] = $typeF;
}
if ($s !== '') {
    $where .= ' AND (s.name LIKE ? OR s.pnr_number LIKE ? OR s.pass_id LIKE ?)';
    $like = '%' . addcslashes($s, '\\%_') . '%';
    array_push($params, $like, $like, $like);
}

$stmt = db()->prepare("SELECT COUNT(*) FROM students s WHERE {$where}");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$perPage = 25;
$pages   = max(1, (int) ceil($total / $perPage));
$page    = is_numeric($gv('page', '1')) ? (int) $gv('page', '1') : 1;
$page    = min($pages, max(1, $page));
$offset  = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT s.id, s.pnr_number, s.pass_id, s.name, s.photo, s.pass_type,
            s.payment_status, s.pass_status, s.entry_status, s.required_amount
     FROM students s
     WHERE {$where}
     ORDER BY s.name ASC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$backParts = ['s' => $s, 'f' => $f, 'type' => $typeF];
if ($page > 1) {
    $backParts['page'] = (string) $page;
}
$back = 'admin/passes.php?' . http_build_query($backParts);

$filterUrl = static function (string $nf) use ($s, $typeF): string {
    $parts = ['f' => $nf, 'type' => $typeF];
    if ($s !== '') {
        $parts['s'] = $s;
    }
    return base_url('admin/passes.php?' . http_build_query($parts));
};
$pageUrl = static function (int $p) use ($s, $f, $typeF): string {
    $parts = ['s' => $s, 'f' => $f, 'type' => $typeF, 'page' => (string) $p];
    return base_url('admin/passes.php?' . http_build_query($parts));
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

$activeNav = 'passes';
$pageTitle = 'Passes';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="chip-filter mb-3" role="toolbar" aria-label="Filter passes by status">
  <a class="btn btn-sm rounded-pill <?= $f === 'all' ? 'btn-teal' : 'btn-outline-secondary' ?>" href="<?= e($filterUrl('all')) ?>">
    All passes <span class="badge rounded-pill ms-1 <?= $f === 'all' ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) array_sum($passCounts) ?></span>
  </a>
  <a class="btn btn-sm rounded-pill <?= $f === 'active' ? 'btn-teal' : 'btn-outline-secondary' ?>" href="<?= e($filterUrl('active')) ?>">
    Active <span class="badge rounded-pill ms-1 <?= $f === 'active' ? 'text-bg-light' : 'text-bg-success' ?>"><?= (int) $passCounts['ACTIVE'] ?></span>
  </a>
  <a class="btn btn-sm rounded-pill <?= $f === 'disabled' ? 'btn-teal' : 'btn-outline-secondary' ?>" href="<?= e($filterUrl('disabled')) ?>">
    Disabled <span class="badge rounded-pill ms-1 <?= $f === 'disabled' ? 'text-bg-light' : 'text-bg-danger' ?>"><?= (int) $passCounts['DISABLED'] ?></span>
  </a>
  <a class="btn btn-sm rounded-pill <?= $f === 'cancelled' ? 'btn-teal' : 'btn-outline-secondary' ?>" href="<?= e($filterUrl('cancelled')) ?>">
    Cancelled <span class="badge rounded-pill ms-1 <?= $f === 'cancelled' ? 'text-bg-light' : 'text-bg-danger' ?>"><?= (int) $passCounts['CANCELLED'] ?></span>
  </a>
</div>

<form class="row g-2 mb-3" method="get" action="<?= e(base_url('admin/passes.php')) ?>" role="search">
  <input type="hidden" name="f" value="<?= e($f) ?>">
  <div class="col-12 col-md-4 col-lg-3">
    <label class="visually-hidden" for="typeSelect">Pass type</label>
    <select class="form-select" id="typeSelect" name="type">
      <option value="">All pass types</option>
      <?php foreach (pass_types() as $pt): ?>
        <option value="<?= e($pt) ?>" <?= $typeF === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-8 col-lg-9">
    <div class="input-group">
      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
      <input class="form-control" type="search" name="s" value="<?= e($s) ?>"
             placeholder="Search by PNR number, name or pass ID…" autocomplete="off">
      <button class="btn btn-teal" type="submit">Search</button>
      <?php if ($s !== '' || $typeF !== ''): ?>
        <a class="btn btn-outline-secondary" href="<?= e(base_url('admin/passes.php?f=' . urlencode($f))) ?>">Clear</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<div class="card card-shadow">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-mobile table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th scope="col">Student</th>
            <th scope="col">Pass ID</th>
            <th scope="col">Type</th>
            <th scope="col">Payment</th>
            <th scope="col">Pass</th>
            <th scope="col">Entry</th>
            <th scope="col">Fee</th>
            <th scope="col" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="8">
              <div class="empty-state">
                <i class="bi bi-ticket-perforated"></i>
                <p class="mt-2 mb-0">No passes found<?= $s !== '' ? ' matching “' . e($s) . '”' : '' ?>.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($rows as $st):
            $passStatus = (string) $st['pass_status'];
        ?>
          <tr>
            <td data-label="Student">
              <div class="d-flex align-items-center gap-2">
                <?= $avatar($st['photo'] ?? null, (string) $st['name']) ?>
                <div class="min-width-0">
                  <div class="fw-semibold text-truncate" style="max-width:170px"><?= e((string) $st['name']) ?></div>
                  <div class="small font-monospace text-muted-2"><?= e((string) ($st['pnr_number'] ?: '— no PNR')) ?></div>
                </div>
              </div>
            </td>
            <td data-label="Pass ID"><span class="font-monospace small fw-bold"><?= e((string) $st['pass_id']) ?></span></td>
            <td data-label="Type"><?= status_badge((string) $st['pass_type']) ?></td>
            <td data-label="Payment"><?= status_badge((string) $st['payment_status']) ?></td>
            <td data-label="Pass"><?= status_badge($passStatus) ?></td>
            <td data-label="Entry"><?= status_badge((string) $st['entry_status']) ?></td>
            <td data-label="Fee"><span class="fw-semibold"><?= e(money($st['required_amount'])) ?></span></td>
            <td data-label="Actions" class="td-actions text-end">
              <div class="d-flex flex-wrap gap-1 justify-content-end">
                <a class="btn btn-sm btn-outline-teal" href="<?= e(base_url('admin/participant-view.php?id=' . (int) $st['id'])) ?>">
                  <i class="bi bi-eye"></i><span class="d-none d-md-inline ms-1">View</span>
                </a>
                <?php if ($passStatus !== 'ACTIVE'): ?>
                <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline"
                      data-confirm="Activate the pass for <?= e((string) $st['name']) ?>?"
                      data-reason-prompt="Optional reason (audit log):">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="activate_pass">
                  <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <input type="hidden" name="back" value="<?= e($back) ?>">
                  <button type="submit" class="btn btn-sm btn-teal"><i class="bi bi-check-lg"></i><span class="d-none d-md-inline ms-1">Activate</span></button>
                </form>
                <?php endif; ?>
                <?php if ($passStatus === 'ACTIVE'): ?>
                <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline"
                      data-confirm="Temporarily DISABLE the pass for <?= e((string) $st['name']) ?>? They cannot enter until re-activated."
                      data-reason-prompt="Optional reason (audit log):">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="disable_pass">
                  <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <input type="hidden" name="back" value="<?= e($back) ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-pause-circle"></i><span class="d-none d-md-inline ms-1">Disable</span></button>
                </form>
                <?php endif; ?>
                <?php if ($passStatus !== 'CANCELLED'): ?>
                <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="d-inline"
                      data-confirm="PERMANENTLY cancel the pass for <?= e((string) $st['name']) ?>? This is usually a final action."
                      data-reason-prompt="Reason for cancelling this pass (recommended):">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="cancel_pass">
                  <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <input type="hidden" name="back" value="<?= e($back) ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-slash-circle"></i><span class="d-none d-md-inline ms-1">Cancel</span></button>
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

  <?php if ($total > 0): ?>
  <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span class="small text-muted-2">
      Showing <?= (int) ($total === 0 ? 0 : $offset + 1) ?>–<?= (int) min($total, $offset + $perPage) ?> of <?= (int) $total ?> pass<?= $total === 1 ? '' : 'es' ?>
    </span>
    <?php if ($pages > 1): ?>
    <nav aria-label="Pass pages">
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

<?php include __DIR__ . '/../includes/admin-footer.php';
