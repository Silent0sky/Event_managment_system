<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin · EMERGENCY MANUAL ENTRY MODE.
 * perm: manual_entry.
 * Three ways to find a participant:
 *   1. Live AJAX search (api/search-participant.php, debounce 300ms) — PNR /
 *      name / mobile / pass ID
 *   2. Plain GET fallback ?s= (server-rendered, works without JS)
 *   3. Manual 32-hex QR token lookup (POST, own handler; failed lookups logged as INVALID)
 * Mark/reset actions POST to admin/actions.php (every action fully audited).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('manual_entry');

$gv = static function (string $key, string $default = ''): string {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
};

/* ---------- Manual QR-token lookup (POST — own handler) ---------- */
$tokenInput   = '';
$tokenStudent = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    $tokenInput = strtolower(trim((string) ($_POST['token'] ?? '')));
    if ($tokenInput === '') {
        redirect_to('admin/manual-entry.php');
    }
    $tokenStudent = student_by_token($tokenInput);
    if (!$tokenStudent) {
        entry_log('INVALID', null, null, (int) $admin['id'], 'ADMIN', $tokenInput, null, 'Manual token lookup failed');
        set_flash('danger', 'Token not found — no pass matches that QR token. Double-check the 32-character code.');
        redirect_to('admin/manual-entry.php');
    }
}

/* ---------- Plain GET search fallback ---------- */
$search  = $gv('s');
$results = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '\\%_') . '%';
    $stmt = db()->prepare(
        'SELECT id, pnr_number, pass_id, name, mobile, course, year, division,
                photo, pass_type, payment_status, pass_status, entry_status
         FROM students
         WHERE pnr_number LIKE ? OR name LIKE ? OR mobile LIKE ? OR pass_id LIKE ?
         ORDER BY (pnr_number = ?) DESC, name ASC
         LIMIT 15'
    );
    $stmt->execute([$like, $like, $like, $like, $search]);
    $results = $stmt->fetchAll();
}

$back = 'admin/manual-entry.php';
if ($search !== '') {
    $back .= '?s=' . urlencode($search);
}

$entryClosed = (string) setting('entry_status', 'OPEN') === 'CLOSED';
$canManual   = admin_can('manual_entry'); /* always true here — require_permission('manual_entry') */

/* Server-rendered participant card (used by ?s= results and token lookup) */
$renderCard = static function (array $st) use ($back, $canManual): void {
    $entered = ((string) $st['entry_status'] === 'ENTERED');
    $deny    = check_entry_eligibility($st);
    ?>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card card-shadow h-100">
        <div class="card-body p-3 d-flex flex-column">
          <div class="d-flex align-items-center gap-2 mb-2">
            <?php if (!empty($st['photo'])): ?>
              <img class="rounded-3" src="<?= e(base_url((string) $st['photo'])) ?>" alt="Photo of <?= e((string) $st['name']) ?>"
                   width="52" height="52" style="object-fit:cover">
            <?php else: ?>
              <span class="avatar-thumb" style="width:52px;height:52px;font-size:1.2rem"><?= e(mb_strtoupper(mb_substr((string) $st['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
            <?php endif; ?>
            <div class="min-width-0 flex-grow-1">
              <div class="fw-semibold text-truncate" style="max-width:170px"><?= e((string) $st['name']) ?></div>
              <div class="small font-monospace text-muted-2 text-truncate"><?= e((string) ($st['pnr_number'] ?: '— no PNR')) ?> · <?= e((string) $st['pass_id']) ?></div>
            </div>
          </div>
          <div class="small d-flex flex-wrap gap-3 mb-2">
            <?php if (!empty($st['mobile'])): ?>
              <a class="text-decoration-none" href="tel:<?= e((string) $st['mobile']) ?>"><i class="bi bi-telephone me-1"></i><?= e((string) $st['mobile']) ?></a>
            <?php else: ?>
              <span class="text-muted-2">No mobile</span>
            <?php endif; ?>
            <span><span class="text-muted-2">Course:</span> <strong><?= e((string) $st['course']) ?> · <?= e(years_list()[(string) $st['year']] ?? (string) $st['year']) ?></strong></span>
          </div>
          <div class="d-flex flex-wrap gap-1 mb-2">
            <?= status_badge((string) $st['pass_type']) ?>
            <?= status_badge((string) $st['payment_status']) ?>
            <?= status_badge((string) $st['pass_status']) ?>
            <?= status_badge((string) $st['entry_status']) ?>
          </div>
          <?php if (!$entered && $deny !== ''): ?>
            <div class="alert alert-warning py-2 px-3 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i><?= e(str_replace('_', ' ', $deny)) ?> — entry will be DENIED until resolved.</div>
          <?php endif; ?>
          <div class="mt-auto d-grid gap-2">
            <?php if ($canManual): ?>
              <?php if ($entered): ?>
                <form method="post" action="<?= e(base_url('admin/actions.php')) ?>"
                      data-confirm="Reset the entry for <?= e((string) $st['name']) ?>?"
                      data-reason-prompt="Reason for resetting this entry (required):"
                      data-reason-required="1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_entry">
                  <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <input type="hidden" name="back" value="<?= e($back) ?>">
                  <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-arrow-counterclockwise me-2"></i>RESET ENTRY</button>
                </form>
              <?php else: ?>
                <form method="post" action="<?= e(base_url('admin/actions.php')) ?>"
                      data-confirm="Mark <?= e((string) $st['name']) ?> as ENTERED manually?"
                      data-reason-prompt="Reason (e.g. QR damaged; college ID verified):"
                      data-reason-required="1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_entry">
                  <input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <input type="hidden" name="back" value="<?= e($back) ?>">
                  <button type="submit" class="btn btn-teal btn-lg w-100"><i class="bi bi-person-check-fill me-2"></i>MARK ENTRY MANUALLY</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('admin/participant-view.php?id=' . (int) $st['id'])) ?>">
              <i class="bi bi-eye me-1"></i>View participant
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php
};

$activeNav = 'manual';
$pageTitle = 'Manual Entry (Emergency)';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
  <i class="bi bi-exclamation-triangle-fill mt-1"></i>
  <div>
    <strong>Emergency manual verification.</strong> Use this mode only when the QR scanner has technical problems.
    Verify the person against their college ID / registration details before marking entry.
    <strong>Every action here is logged</strong> with your admin account, the participant and a reason.
  </div>
</div>

<?php if ($entryClosed): ?>
  <div class="alert alert-danger" role="alert">
    <i class="bi bi-lock-fill me-1"></i><strong>Entry is currently CLOSED in settings</strong> — all volunteer scans are blocked.
    Admin manual entry still works; reopen entry from Settings when ready.
  </div>
<?php endif; ?>

<div class="card card-shadow mb-4">
  <div class="card-body p-3 p-md-4">
    <label class="form-label fw-semibold" for="mq"><i class="bi bi-search me-1"></i>Find participant</label>
    <div class="input-group input-group-lg">
      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
      <input class="form-control" id="mq" type="search" autocomplete="off" inputmode="text"
             placeholder="PNR number, name, mobile or pass ID…">
    </div>
    <div id="mHint" class="form-text">Live search — type a PNR number (e.g. 2425000000) or at least 2 characters of a name.</div>
    <div id="mresults" class="mt-3" aria-live="polite"></div>
  </div>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-7">
    <div class="card card-shadow h-100">
      <div class="card-body p-3 p-md-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-search me-2 text-teal"></i>Search without JavaScript</h2>
        <form class="row g-2" method="get" action="<?= e(base_url('admin/manual-entry.php')) ?>" role="search">
          <div class="col">
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
              <input class="form-control" type="search" name="s" value="<?= e($search) ?>"
                     placeholder="PNR number, name, mobile or pass ID…" autocomplete="off" inputmode="text">
              <button class="btn btn-teal" type="submit">Search</button>
              <?php if ($search !== ''): ?>
                <a class="btn btn-outline-secondary" href="<?= e(base_url('admin/manual-entry.php')) ?>">Clear</a>
              <?php endif; ?>
            </div>
          </div>
        </form>
        <?php if ($search !== '' && !$results): ?>
          <div class="alert alert-secondary small mt-3 mb-0"><i class="bi bi-inbox me-1"></i>No participants found for “<?= e($search) ?>”.</div>
        <?php elseif ($results): ?>
          <div class="small text-muted-2 mt-3 mb-2"><?= (int) count($results) ?> match<?= count($results) === 1 ? '' : 'es' ?> for “<?= e($search) ?>”:</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card card-shadow h-100">
      <div class="card-body p-3 p-md-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-qr-code me-2 text-teal"></i>Manual QR-token lookup</h2>
        <p class="small text-muted-2">If the pass QR is readable by another device, copy the 32-character token under the QR and paste it here. The student's <strong>PNR number</strong> in the search box above is usually easier — it is printed on the pass and on their college ID.</p>
        <form method="post" action="<?= e(base_url('admin/manual-entry.php')) ?>">
          <?= csrf_field() ?>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-hash"></i></span>
            <input class="form-control font-monospace" type="text" name="token" maxlength="32"
                   value="<?= e($tokenInput) ?>" placeholder="32 hex characters (a–f, 0–9)" autocomplete="off">
            <button class="btn btn-teal" type="submit">Look up</button>
          </div>
        </form>
        <div class="form-text">Failed lookups are recorded in the entry logs as INVALID.</div>
      </div>
    </div>
  </div>
</div>

<?php if ($results): ?>
<div class="mt-4">
  <h2 class="h6 fw-bold mb-3"><i class="bi bi-list-ul me-2 text-teal"></i>Search results</h2>
  <div class="row g-3">
    <?php foreach ($results as $st) { $renderCard($st); } ?>
  </div>
</div>
<?php endif; ?>

<?php if ($tokenStudent): ?>
<div class="mt-4">
  <h2 class="h6 fw-bold mb-3"><i class="bi bi-qr-code me-2 text-teal"></i>Token match</h2>
  <div class="row g-3">
    <?php $renderCard($tokenStudent); ?>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  'use strict';
  var input = document.getElementById('mq');
  var box = document.getElementById('mresults');
  var hint = document.getElementById('mHint');
  if (!input || !box) { return; }

  var API_URL = <?= json_encode(base_url('api/search-participant.php')) ?>;
  var ACTIONS = <?= json_encode(base_url('admin/actions.php')) ?>;
  var VIEW_URL = <?= json_encode(base_url('admin/participant-view.php')) ?>;
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var BACK = <?= json_encode($back) ?>;

  var badgeMap = {
    APPROVED: 'success', ACTIVE: 'success', ENTERED: 'success', NOT_ENTERED: 'secondary',
    NOT_REQUIRED: 'teal', PENDING: 'warning', REJECTED: 'danger', REFUNDED: 'dark',
    DISABLED: 'danger', CANCELLED: 'danger',
    PAID: 'teal', FREE: 'success', GUEST: 'amber', ORGANIZER: 'teal', FACULTY: 'stone', VIP: 'gold'
  };
  var timer = null;

  function badge(v) {
    var s = document.createElement('span');
    s.className = 'badge rounded-pill text-bg-' + (badgeMap[v] || 'secondary') + ' fw-semibold';
    s.textContent = String(v || '').replace(/_/g, ' ');
    return s;
  }
  function field(label, value, mono) {
    var d = document.createElement('div');
    d.className = 'small';
    var l = document.createElement('span');
    l.className = 'text-muted-2';
    l.textContent = label + ': ';
    var v = document.createElement('strong');
    if (mono) { v.className = 'font-monospace'; }
    v.textContent = value || '—';
    d.appendChild(l); d.appendChild(v);
    return d;
  }
  function hidden(form, name, value) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = name; i.value = value;
    form.appendChild(i);
  }
  function quickAction(studentId, actionName, promptText, requireReason) {
    var reason = '';
    if (promptText) {
      reason = window.prompt(promptText, '');
      if (reason === null) { return; }
      reason = reason.replace(/^\s+|\s+$/g, '');
      if (requireReason && reason === '') {
        window.alert('A reason is required for this action.');
        return;
      }
    }
    var f = document.createElement('form');
    f.method = 'post';
    f.action = ACTIONS;
    hidden(f, 'csrf_token', CSRF);
    hidden(f, 'action', actionName);
    hidden(f, 'student_id', String(studentId));
    hidden(f, 'reason', reason);
    hidden(f, 'back', BACK);
    document.body.appendChild(f);
    f.submit();
  }
  function mkBtn(label, cls, onclick) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = cls;
    b.textContent = label;
    b.addEventListener('click', onclick);
    return b;
  }
  function msg(text, cls) {
    var d = document.createElement('div');
    d.className = 'alert ' + (cls || 'alert-secondary') + ' small mb-0';
    d.textContent = text;
    return d;
  }
  function card(r) {
    var c = document.createElement('div');
    c.className = 'card card-shadow';
    var b = document.createElement('div');
    b.className = 'card-body p-3 d-flex flex-column';
    c.appendChild(b);

    var head = document.createElement('div');
    head.className = 'd-flex align-items-center gap-2 mb-2';
    var av = document.createElement('span');
    av.className = 'avatar-thumb';
    av.style.width = '52px';
    av.style.height = '52px';
    av.style.fontSize = '1.2rem';
    av.textContent = (r.name || '?').charAt(0).toUpperCase();
    head.appendChild(av);
    var nm = document.createElement('div');
    nm.className = 'min-width-0 flex-grow-1';
    var n1 = document.createElement('div');
    n1.className = 'fw-semibold text-truncate';
    n1.style.maxWidth = '170px';
    n1.textContent = r.name || '—';
    var n2 = document.createElement('div');
    n2.className = 'small font-monospace text-muted-2 text-truncate';
    n2.textContent = (r.pnr || '— no PNR') + ' · ' + (r.pass_id || '');
    nm.appendChild(n1); nm.appendChild(n2);
    head.appendChild(nm);
    b.appendChild(head);

    var info = document.createElement('div');
    info.className = 'small d-flex flex-wrap gap-3 mb-2';
    info.appendChild(field('PNR', r.pnr, true));
    if (r.mobile) {
      var tel = document.createElement('a');
      tel.className = 'text-decoration-none';
      tel.href = 'tel:' + r.mobile;
      tel.textContent = r.mobile;
      info.appendChild(tel);
    }
    info.appendChild(field('Course', (r.course || '—') + (r.year ? ' · ' + r.year : '')));
    b.appendChild(info);

    var badges = document.createElement('div');
    badges.className = 'd-flex flex-wrap gap-1 mb-2';
    [r.pass_type, r.payment_status, r.pass_status, r.entry_status].forEach(function (v) { badges.appendChild(badge(v)); });
    b.appendChild(badges);

    var acts = document.createElement('div');
    acts.className = 'mt-auto d-grid gap-2';
    if (r.entry_status === 'ENTERED') {
      acts.appendChild(mkBtn('RESET ENTRY', 'btn btn-outline-danger', function () {
        quickAction(r.id, 'reset_entry', 'Reason for resetting this entry (required):', true);
      }));
    } else {
      var mark = mkBtn('MARK ENTRY MANUALLY', 'btn btn-teal btn-lg', function () {
        quickAction(r.id, 'mark_entry', 'Reason (e.g. QR damaged; college ID verified):', true);
      });
      acts.appendChild(mark);
    }
    var view = mkBtn('View participant', 'btn btn-outline-secondary btn-sm', function () {
      window.location.href = VIEW_URL + '?id=' + encodeURIComponent(r.id || '');
    });
    acts.appendChild(view);
    b.appendChild(acts);
    return c;
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    var q = input.value.replace(/^\s+|\s+$/g, '');
    if (q.length < 2) {
      box.innerHTML = '';
      if (hint) { hint.textContent = 'Live search — type at least 2 characters.'; }
      return;
    }
    if (hint) { hint.textContent = 'Searching…'; }
    timer = setTimeout(function () {
      fetch(API_URL + '?q=' + encodeURIComponent(q), { headers: { 'X-CSRF-Token': CSRF }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          box.innerHTML = '';
          if (hint) { hint.textContent = 'Live search — type at least 2 characters.'; }
          if (!data || !data.ok) {
            box.appendChild(msg('Search failed' + (data && data.message ? ': ' + data.message : '') + ' — use the manual search below.', 'alert-danger'));
            return;
          }
          if (!data.results || !data.results.length) {
            box.appendChild(msg('No participants found for “' + q + '”.', 'alert-secondary'));
            return;
          }
          data.results.forEach(function (r) { box.appendChild(card(r)); });
        })
        .catch(function () {
          box.innerHTML = '';
          if (hint) { hint.textContent = 'Live search — type at least 2 characters.'; }
          box.appendChild(msg('Search unavailable — use the manual search below.', 'alert-warning'));
        });
    }, 300);
  });
})();
</script>

<?php include __DIR__ . '/../includes/admin-footer.php';
