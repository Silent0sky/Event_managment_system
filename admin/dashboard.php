<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin dashboard.
 * 10 live stat cards, entry progress, permission-gated quick actions,
 * live entry log (auto-refreshed every 10 s from api/dashboard-stats.php).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('dashboard');
$activeNav = 'dashboard';
$pageTitle = 'Dashboard';
$settings = get_settings();

/* ---------------- Stats (server-rendered initial values) ---------------- */
$count = function (string $sql): int {
    $st = db()->prepare($sql);
    $st->execute();
    return (int) $st->fetchColumn();
};

$stats = [
    'registered'       => $count('SELECT COUNT(*) FROM students'),
    'payment_approved' => $count("SELECT COUNT(*) FROM students WHERE payment_status = 'APPROVED'"),
    'payment_pending'  => $count("SELECT COUNT(*) FROM students WHERE payment_status = 'PENDING'"),
    'payment_rejected' => $count("SELECT COUNT(*) FROM students WHERE payment_status IN ('REJECTED','REFUNDED')"),
    'active_passes'    => $count("SELECT COUNT(*) FROM students WHERE pass_status = 'ACTIVE'"),
    'entered'          => $count("SELECT COUNT(*) FROM students WHERE entry_status = 'ENTERED'"),
    'not_entered'      => $count("SELECT COUNT(*) FROM students WHERE entry_status = 'NOT_ENTERED'"),
    'manual_entries'   => $count("SELECT COUNT(*) FROM students WHERE entry_method = 'MANUAL' AND entry_status = 'ENTERED'"),
    'invalid_scans'    => $count("SELECT COUNT(*) FROM entry_logs WHERE result = 'INVALID'"),
    'duplicate_scans'  => $count("SELECT COUNT(*) FROM entry_logs WHERE result = 'ALREADY_USED'"),
];

$progressTotal = max(1, (int) $stats['registered']);
$percent       = $progressTotal > 0 ? round($stats['entered'] * 100 / $progressTotal, 1) : 0.0;
$barWidth      = max(0.0, min(100.0, (float) $percent));

/* ---------------- Live entry log (latest 12) ---------------- */
$st = db()->prepare(
    'SELECT el.scan_time, el.result, el.gate_name, el.entry_method,
            s.name AS student_name, s.pnr_number, s.pass_id,
            v.name AS volunteer_name, a.name AS admin_name
     FROM entry_logs el
     LEFT JOIN students  s ON s.id  = el.student_id
     LEFT JOIN volunteers v ON v.id = el.volunteer_id
     LEFT JOIN admins    a ON a.id = el.admin_id
     ORDER BY el.id DESC
     LIMIT 12'
);
$st->execute();
$recent = $st->fetchAll() ?: [];

$fmtTime = static function (?string $t): string {
    if (!$t) {
        return '—';
    }
    return str_starts_with($t, date('Y-m-d')) ? time_short($t) : date_short($t) . ', ' . time_short($t);
};

/* ---------------- Quick actions (permission-gated) ---------------- */
$quickActions = [];
if (admin_can('participant_create')) {
    $quickActions[] = ['Add Participant', 'bi-person-plus', 'admin/manual-register.php', 'si-teal'];
}
if (admin_can('payments')) {
    $quickActions[] = ['Pending Payments', 'bi-hourglass-split', 'admin/payments.php?status=PENDING', 'si-amber'];
}
if (admin_can('manual_entry')) {
    $quickActions[] = ['Manual Entry', 'bi-person-check', 'admin/manual-entry.php', 'si-green'];
}
if (admin_can('entries')) {
    $quickActions[] = ['Not Entered', 'bi-door-closed', 'admin/entries.php?tab=not_entered', 'si-stone'];
}
if (admin_can('settings')) {
    $quickActions[] = ['Settings', 'bi-gear', 'admin/settings.php', 'si-rose'];
}

$statCards = [
    ['registered',       'TOTAL REGISTERED',  'bi-people',              'si-teal',   'admin/registrations.php'],
    ['payment_approved', 'PAYMENT APPROVED',  'bi-check2-circle',       'si-green',  'admin/payments.php?status=APPROVED'],
    ['payment_pending',  'PAYMENT PENDING',   'bi-hourglass-split',     'si-amber',  'admin/payments.php?status=PENDING'],
    ['payment_rejected', 'PAYMENT REJECTED',  'bi-x-circle',            'si-rose',   'admin/payments.php?status=REJECTED'],
    ['active_passes',    'ACTIVE PASSES',     'bi-ticket-perforated',   'si-teal',   'admin/passes.php'],
    ['entered',          'TOTAL ENTERED',     'bi-door-open',           'si-green',  'admin/entries.php?tab=entered'],
    ['not_entered',      'NOT ENTERED',       'bi-door-closed',         'si-stone',  'admin/entries.php?tab=not_entered'],
    ['manual_entries',   'MANUAL ENTRIES',    'bi-person-check',        'si-amber',  'admin/registrations.php?f=manual'],
    ['invalid_scans',    'INVALID SCANS',     'bi-exclamation-triangle','si-rose',   'admin/entries.php?tab=live'],
    ['duplicate_scans',  'DUPLICATE SCANS',   'bi-arrow-repeat',        'si-stone',  'admin/entries.php?tab=live'],
];

include __DIR__ . '/../includes/admin-header.php';
?>

<!-- Event meta strip -->
<div class="d-flex flex-wrap gap-2 mb-3 small">
  <span class="badge text-bg-teal rounded-pill fw-semibold"><i class="bi bi-calendar-event me-1"></i><?= e(date_short((string) ($settings['event_date'] ?? ''))) ?></span>
  <a href="<?= e(base_url('admin/settings.php')) ?>" class="text-decoration-none">
    <span class="badge rounded-pill fw-semibold <?= (string) $settings['registration_status'] === 'OPEN' ? 'text-bg-success' : 'text-bg-danger' ?>" title="Click to manage registration status">Registration <?= e(ucfirst(strtolower((string) $settings['registration_status']))) ?> <i class="bi bi-pencil-square ms-1"></i></span>
  </a>
  <a href="<?= e(base_url('admin/settings.php')) ?>" class="text-decoration-none">
    <span class="badge rounded-pill fw-semibold <?= (string) $settings['entry_status'] === 'OPEN' ? 'text-bg-success' : 'text-bg-danger' ?>" title="Click to manage entry status">Entry <?= e(ucfirst(strtolower((string) $settings['entry_status']))) ?> <i class="bi bi-pencil-square ms-1"></i></span>
  </a>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-3">
  <?php foreach ($statCards as [$key, $label, $icon, $iconClass, $href]): ?>
  <div class="col-6 col-md-4 col-xl-3">
    <a class="stat-card text-decoration-none hover-elevate" href="<?= e(base_url($href)) ?>" title="View <?= e($label) ?>">
      <span class="stat-icon <?= e($iconClass) ?>"><i class="bi <?= e($icon) ?>"></i></span>
      <div class="min-width-0">
        <div class="stat-value pulse-num"><span data-stat="<?= e($key) ?>"><?= (int) $stats[$key] ?></span></div>
        <div class="stat-label"><?= e($label) ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">

  <!-- Entry progress -->
  <div class="col-12 col-xl-5">
    <div class="card card-shadow h-100">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-0"><i class="bi bi-speedometer2 me-1 text-teal"></i>ENTRY PROGRESS</h2>

        <div class="text-center my-3">
          <div class="display-5 fw-900">
            <span class="pulse-num" data-stat="entered"><?= (int) $stats['entered'] ?></span>
            <span class="text-muted-2 fw-semibold">/</span>
            <span class="pulse-num" data-stat="total"><?= (int) $progressTotal ?></span>
          </div>
          <div class="small text-muted-2 mt-1">entered · registered participants</div>
        </div>

        <div class="progress mt-2" role="progressbar" aria-label="Entry progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) $barWidth) ?>">
          <div class="progress-bar" data-stat="percent" style="width:<?= e((string) $barWidth) ?>%"></div>
        </div>
        <div class="d-flex justify-content-between small text-muted-2 mt-1">
          <span>0</span>
          <span><?= e(number_format((float) $percent, 1)) ?>%</span>
        </div>

        <hr class="my-3">
        <div class="row g-2 small text-center">
          <div class="col-4">
            <a class="mini-stat border rounded-3 py-2 text-decoration-none d-block" href="<?= e(base_url('admin/registrations.php?f=manual')) ?>" title="View Manual Entries">
              <div class="fw-900 fs-5 pulse-num text-dark"><span data-stat="manual_entries"><?= (int) $stats['manual_entries'] ?></span></div>
              <div class="text-muted-2">Manual</div>
            </a>
          </div>
          <div class="col-4">
            <a class="mini-stat border rounded-3 py-2 text-decoration-none d-block" href="<?= e(base_url('admin/entries.php?tab=not_entered')) ?>" title="View Not Entered Participants">
              <div class="fw-900 fs-5 pulse-num text-dark"><span data-stat="not_entered"><?= (int) $stats['not_entered'] ?></span></div>
              <div class="text-muted-2">Not Entered</div>
            </a>
          </div>
          <div class="col-4">
            <a class="mini-stat border rounded-3 py-2 text-decoration-none d-block" href="<?= e(base_url('admin/passes.php')) ?>" title="View Active Passes">
              <div class="fw-900 fs-5 pulse-num text-dark"><span data-stat="active_passes"><?= (int) $stats['active_passes'] ?></span></div>
              <div class="text-muted-2">Active Passes</div>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick actions -->
  <div class="col-12 col-xl-7">
    <div class="card card-shadow h-100">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-lightning-charge me-1 text-teal"></i>QUICK ACTIONS</h2>
        <?php if (!$quickActions): ?>
          <p class="text-muted-2 small mb-0">No quick actions are available for your role.</p>
        <?php else: ?>
        <div class="row g-2">
          <?php foreach ($quickActions as [$label, $icon, $href, $iconClass]): ?>
          <div class="col-6 col-md-4">
            <a class="d-flex align-items-center gap-2 p-3 border rounded-3 text-decoration-none more-item text-body h-100"
               href="<?= e(base_url($href)) ?>">
              <span class="stat-icon <?= e($iconClass) ?>"><i class="bi <?= e($icon) ?>"></i></span>
              <span class="fw-semibold small"><?= e($label) ?></span>
            </a>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Live entry log -->
<div class="card card-shadow">
  <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h2 class="h6 fw-bold mb-0"><i class="bi bi-activity me-1 text-teal"></i>LIVE ENTRY LOG</h2>
    <span class="small text-muted-2">
      <i class="bi bi-arrow-repeat me-1"></i>Auto-refresh every 10s · Updated
      <span id="autoRefreshLabel" data-ts="<?= (int) time() ?>">just now</span>
    </span>
  </div>
  <div class="card-body p-0" style="max-height:70vh;overflow-y:auto">
    <table class="table table-hover table-mobile align-middle mb-0">
      <thead>
        <tr>
          <th scope="col">Time</th>
          <th scope="col">Participant</th>
          <th scope="col">Gate</th>
          <th scope="col">Method</th>
          <th scope="col">Result</th>
          <th scope="col">By</th>
        </tr>
      </thead>
      <tbody id="liveLogBody">
        <?php if (!$recent): ?>
        <tr>
          <td data-label="Entry log" colspan="6">
            <div class="empty-state"><i class="bi bi-activity"></i><p class="mb-0 mt-2">No entry activity yet — scans and manual entries will appear here in real time.</p></div>
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($recent as $r): ?>
        <tr>
          <td data-label="Time" class="small text-muted-2 text-nowrap"><?= e($fmtTime($r['scan_time'] ? (string) $r['scan_time'] : null)) ?></td>
          <td data-label="Participant">
            <strong><?= e((string) ($r['student_name'] ?: 'Unknown / no pass')) ?></strong>
            <div class="small text-muted-2 font-monospace"><?= e((string) ($r['pnr_number'] ?: ((string) ($r['pass_id'] ?? '') ?: 'token not recognized'))) ?></div>
          </td>
          <td data-label="Gate" class="small"><?= e((string) ($r['gate_name'] ?: '—')) ?></td>
          <td data-label="Method" class="small"><?= e((string) ($r['entry_method'] ?: '—')) ?></td>
          <td data-label="Result"><?= status_badge((string) $r['result']) ?></td>
          <td data-label="By" class="small"><?= e((string) ($r['volunteer_name'] ?: ($r['admin_name'] ?: 'System'))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  'use strict';
  var STATS_URL = <?= json_encode(base_url('api/dashboard-stats.php')) ?>;
  var TODAY = <?= json_encode(date('Y-m-d')) ?>;
  var BADGE = {
    SUCCESS: 'success', MANUAL_APPROVED: 'success',
    ALREADY_USED: 'danger', INVALID: 'danger', PAYMENT_REJECTED: 'danger',
    PASS_DISABLED: 'danger', PASS_CANCELLED: 'danger',
    PAYMENT_PENDING: 'warning', ENTRY_CLOSED: 'warning',
    MANUAL_RESET: 'secondary'
  };
  var KEYS = ['registered', 'payment_approved', 'payment_pending', 'payment_rejected', 'active_passes',
              'entered', 'not_entered', 'manual_entries', 'invalid_scans', 'duplicate_scans'];

  function setStat(key, val) {
    var els = document.querySelectorAll('[data-stat="' + key + '"]');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.classList.contains('progress-bar')) {
        var pct = Math.max(0, Math.min(100, parseFloat(val) || 0));
        el.style.width = pct + '%';
        el.setAttribute('aria-valuenow', String(pct));
        continue;
      }
      var txt = String(val);
      if (el.textContent !== txt) {
        el.textContent = txt;
        el.classList.add('pulse-num', 'updating');
        (function (node) {
          setTimeout(function () { node.classList.remove('updating'); }, 700);
        })(el);
      }
    }
  }

  function fmtTime(t) {
    if (t === null || t === undefined || t === '') { return '—'; }
    var s = String(t);
    var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!m) { return s; }
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var hh = parseInt(m[4], 10);
    var h12 = hh % 12; if (h12 === 0) { h12 = 12; }
    var timeStr = h12 + ':' + m[5] + ' ' + (hh >= 12 ? 'PM' : 'AM');
    if (m[1] + '-' + m[2] + '-' + m[3] === TODAY) { return timeStr; }
    return parseInt(m[3], 10) + ' ' + months[parseInt(m[2], 10) - 1] + ', ' + timeStr;
  }

  function makeTd(label, className) {
    var td = document.createElement('td');
    td.setAttribute('data-label', label);
    if (className) { td.className = className; }
    return td;
  }

  function renderLog(rows) {
    var body = document.getElementById('liveLogBody');
    if (!body) { return; }
    body.textContent = '';
    if (!rows || !rows.length) {
      var tr = document.createElement('tr');
      var td = makeTd('Entry log');
      td.colSpan = 6;
      var div = document.createElement('div');
      div.className = 'empty-state';
      var ic = document.createElement('i');
      ic.className = 'bi bi-activity';
      var p = document.createElement('p');
      p.className = 'mb-0 mt-2';
      p.textContent = 'No entry activity yet.';
      div.appendChild(ic); div.appendChild(p); td.appendChild(div); tr.appendChild(td); body.appendChild(tr);
      return;
    }
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var tr2 = document.createElement('tr');

      var tdTime = makeTd('Time', 'small text-muted-2 text-nowrap');
      tdTime.textContent = fmtTime(r.time);
      tr2.appendChild(tdTime);

      var tdName = makeTd('Participant');
      var strong = document.createElement('strong');
      strong.textContent = r.name || 'Unknown / no pass';
      var sub = document.createElement('div');
      sub.className = 'small text-muted-2 font-monospace';
      sub.textContent = (r.pnr || r.pass_id || 'token not recognized');
      tdName.appendChild(strong); tdName.appendChild(sub);
      tr2.appendChild(tdName);

      var tdGate = makeTd('Gate', 'small');
      tdGate.textContent = r.gate || '—';
      tr2.appendChild(tdGate);

      var tdMethod = makeTd('Method', 'small');
      tdMethod.textContent = r.method || '—';
      tr2.appendChild(tdMethod);

      var tdResult = makeTd('Result');
      var badge = document.createElement('span');
      badge.className = 'badge rounded-pill text-bg-' + (BADGE[r.result] || 'secondary') + ' fw-semibold';
      badge.textContent = String(r.result || '').replace(/_/g, ' ');
      tdResult.appendChild(badge);
      tr2.appendChild(tdResult);

      var tdBy = makeTd('By', 'small');
      tdBy.textContent = r.by || 'System';
      tr2.appendChild(tdBy);

      body.appendChild(tr2);
    }
  }

  function refresh() {
    fetch(STATS_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || data.ok !== true) { return; }
        if (data.stats) {
          for (var k = 0; k < KEYS.length; k++) {
            var key = KEYS[k];
            if (typeof data.stats[key] !== 'undefined') { setStat(key, data.stats[key]); }
          }
        }
        if (data.progress) {
          if (typeof data.progress.entered !== 'undefined') { setStat('entered', data.progress.entered); }
          if (typeof data.progress.total !== 'undefined') { setStat('total', data.progress.total); }
          if (typeof data.progress.percent !== 'undefined') { setStat('percent', data.progress.percent); }
        }
        if (data.recent) { renderLog(data.recent); }
        var label = document.getElementById('autoRefreshLabel');
        if (label) {
          var ts = parseInt(data.updated_ts || data.updated_at, 10);
          if (isNaN(ts) || ts < 1000000000) { ts = Math.floor(Date.now() / 1000); }
          label.setAttribute('data-ts', String(ts));
          label.textContent = 'just now';
        }
      })
      .catch(function () { /* keep last known values */ });
  }

  setInterval(refresh, 10000);
})();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
