<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin · Audit log viewer.
 * perm: audit_logs. Filters: user_type / action (LIKE) / q (target_id or reason LIKE)
 * / from–to date range (inclusive). 50 rows per page.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('audit_logs');

$gv = static function (string $key, string $default = ''): string {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
};

$userTypes = ['ALL', 'ADMIN', 'VOLUNTEER', 'STUDENT', 'SYSTEM'];
$userType  = strtoupper($gv('user_type', 'ALL'));
if (!in_array($userType, $userTypes, true)) {
    $userType = 'ALL';
}
$actionF = $gv('action');
$q       = $gv('q');
$from    = $gv('from');
$to      = $gv('to');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

/* ---------- WHERE builder ---------- */
$where  = '1=1';
$params = [];
if ($userType !== 'ALL') {
    $where .= ' AND al.user_type = ?';
    $params[] = $userType;
}
if ($actionF !== '') {
    $where .= ' AND al.action LIKE ?';
    $params[] = '%' . $actionF . '%';
}
if ($q !== '') {
    $where .= ' AND (al.target_id LIKE ? OR al.reason LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($from !== '') {
    $where .= ' AND al.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= ' AND al.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}

$stmt = db()->prepare("SELECT COUNT(*) FROM audit_logs al WHERE {$where}");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$perPage = 50;
$pages   = max(1, (int) ceil($total / $perPage));
$page    = is_numeric($gv('page', '1')) ? (int) $gv('page', '1') : 1;
$page    = min($pages, max(1, $page));
$offset  = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT al.*, a.name AS admin_name, v.name AS volunteer_name
     FROM audit_logs al
     LEFT JOIN admins a ON al.user_type = 'ADMIN' AND al.user_id = a.id
     LEFT JOIN volunteers v ON al.user_type = 'VOLUNTEER' AND al.user_id = v.id
     WHERE {$where}
     ORDER BY al.id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageUrl = static function (int $p) use ($userType, $actionF, $q, $from, $to): string {
    $parts = [
        'user_type' => $userType,
        'action'    => $actionF,
        'q'         => $q,
        'from'      => $from,
        'to'        => $to,
        'page'      => (string) $p,
    ];
    return base_url('admin/audit-logs.php?' . http_build_query(array_filter($parts, static fn ($v) => $v !== '')));
};

$trunc = static function (?string $s, int $max = 600): string {
    $s = (string) $s;
    if (mb_strlen($s, 'UTF-8') > $max) {
        return mb_substr($s, 0, $max, 'UTF-8') . '…';
    }
    return $s;
};

$activeNav = 'audit';
$pageTitle = 'Audit Logs';
include __DIR__ . '/../includes/admin-header.php';
?>

<form class="card card-shadow mb-3" method="get" action="<?= e(base_url('admin/audit-logs.php')) ?>" role="search">
  <div class="card-body p-3">
    <div class="row g-2">
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-0" for="f-user">User type</label>
        <select class="form-select" id="f-user" name="user_type">
          <option value="ALL">All</option>
          <?php foreach (['ADMIN', 'VOLUNTEER', 'STUDENT', 'SYSTEM'] as $ut): ?>
            <option value="<?= e($ut) ?>" <?= $userType === $ut ? 'selected' : '' ?>><?= e(ucfirst(strtolower($ut))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-0" for="f-action">Action contains</label>
        <input class="form-control" type="text" id="f-action" name="action" value="<?= e($actionF) ?>" placeholder="e.g. PAYMENT" autocomplete="off">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small fw-semibold mb-0" for="f-q">Target ID / reason contains</label>
        <input class="form-control" type="search" id="f-q" name="q" value="<?= e($q) ?>" placeholder="e.g. 2425000000 (PNR)" autocomplete="off">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-0" for="f-from">From date</label>
        <input class="form-control" type="date" id="f-from" name="from" value="<?= e($from) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-0" for="f-to">To date</label>
        <input class="form-control" type="date" id="f-to" name="to" value="<?= e($to) ?>">
      </div>
      <div class="col-12 col-md-1 d-flex align-items-end gap-1">
        <button class="btn btn-teal w-100" type="submit"><i class="bi bi-funnel"></i></button>
      </div>
    </div>
    <?php if ($userType !== 'ALL' || $actionF !== '' || $q !== '' || $from !== '' || $to !== ''): ?>
      <div class="mt-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('admin/audit-logs.php')) ?>"><i class="bi bi-x-lg me-1"></i>Clear filters</a>
      </div>
    <?php endif; ?>
  </div>
</form>

<div class="card card-shadow">
  <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span class="card-header-title"><i class="bi bi-journal-text me-2 text-teal"></i>Activity trail</span>
    <span class="small text-muted-2"><?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?><?= $total > 0 ? ' · newest first' : '' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-mobile table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th scope="col">Time</th>
            <th scope="col">User</th>
            <th scope="col">Action</th>
            <th scope="col">Target</th>
            <th scope="col">Reason</th>
            <th scope="col">IP</th>
            <th scope="col">Changes</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="7">
              <div class="empty-state">
                <i class="bi bi-journal-x"></i>
                <p class="mt-2 mb-0">No audit entries match the current filters.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $actor = (string) ($r['admin_name'] ?? ($r['volunteer_name'] ?? ''));
            if ($actor === '' && (string) $r['user_type'] === 'STUDENT') {
                $actor = 'Student';
            }
            if ($actor === '' && (string) $r['user_type'] === 'SYSTEM') {
                $actor = 'System';
            }
            if ($actor === '' && $r['user_id'] !== null) {
                $actor = '#' . (string) $r['user_id'];
            }
            $oldV = (string) ($r['old_value'] ?? '');
            $newV = (string) ($r['new_value'] ?? '');
        ?>
          <tr>
            <td data-label="Time"><span class="small"><?= e(dt_short((string) $r['created_at'])) ?></span></td>
            <td data-label="User">
              <div class="d-flex flex-wrap align-items-center gap-1">
                <?= status_badge((string) $r['user_type']) ?>
                <span class="small"><?= e($actor !== '' ? $actor : '—') ?></span>
              </div>
            </td>
            <td data-label="Action">
              <span class="badge text-bg-stone font-monospace fw-normal" style="font-size:.7rem"><?= e((string) $r['action']) ?></span>
            </td>
            <td data-label="Target">
              <div class="small fw-semibold"><?= e((string) ($r['target_type'] ?: '—')) ?></div>
              <div class="small font-monospace text-muted-2"><?= e((string) ($r['target_id'] ?: '—')) ?></div>
            </td>
            <td data-label="Reason"><span class="small text-muted-2 text-break"><?= e((string) ($r['reason'] ?: '—')) ?></span></td>
            <td data-label="IP"><span class="small font-monospace"><?= e((string) ($r['ip_address'] ?: '—')) ?></span></td>
            <td data-label="Changes">
              <?php if ($oldV === '' && $newV === ''): ?>
                <span class="text-muted-2 small">—</span>
              <?php else: ?>
                <details>
                  <summary class="small" role="button" style="cursor:pointer">view</summary>
                  <pre class="small mb-0 mt-1 p-2 bg-teal-soft rounded-3" style="white-space:pre-wrap;word-break:break-word;max-width:360px"><?php
                    if ($oldV !== '') {
                        echo 'OLD: ' . e($trunc($oldV));
                    }
                    if ($newV !== '') {
                        echo ($oldV !== '' ? "\n→\n" : '') . 'NEW: ' . e($trunc($newV));
                    }
                  ?></pre>
                </details>
              <?php endif; ?>
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
      Showing <?= (int) ($offset + 1) ?>–<?= (int) min($total, $offset + $perPage) ?> of <?= (int) $total ?>
    </span>
    <?php if ($pages > 1): ?>
    <nav aria-label="Audit log pages">
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
