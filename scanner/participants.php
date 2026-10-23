<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Volunteer Attendee / Participant List.
 * Allows volunteers to see who has entered, who has not, search participants,
 * and mark entry directly if a participant's QR cannot be scanned.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/volunteer-auth.php';
secure_session_start();

$volunteer = require_volunteer();

$eventName = (string) setting('event_name', 'FRESHERS PARTY 2026');
$eventLogo = (string) setting('event_logo', 'assets/images/event-logo.png');
$entryOpen = (string) setting('entry_status', 'OPEN') === 'OPEN';

// Handle one-click manual entry from attendee list
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'mark_entry') {
    csrf_require();
    if (!$entryOpen) {
        set_flash('danger', 'Entry is currently closed by event administration.');
        redirect_to('scanner/participants.php');
    }
    $sid = (int) ($_POST['student_id'] ?? 0);
    $student = student_by_id($sid);
    if (!$student) {
        set_flash('danger', 'Participant not found.');
    } else {
        $eligibility = check_entry_eligibility($student);
        if ($eligibility !== '') {
            set_flash('warning', 'Cannot mark entry: ' . str_replace('_', ' ', $eligibility));
        } else {
            $res = attempt_entry(
                $student,
                'MANUAL',
                (int) $volunteer['id'],
                null,
                (string) $volunteer['gate_name'],
                null,
                'Marked at gate by volunteer ' . $volunteer['name']
            );
            if (($res['result'] ?? '') === 'SUCCESS' || ($res['result'] ?? '') === 'MANUAL_APPROVED') {
                set_flash('success', '✓ Entry approved for ' . $student['name'] . ' (PNR: ' . ($student['pnr_number'] ?: $student['pass_id']) . ')');
            } else {
                set_flash('warning', 'Participant entry status: ' . str_replace('_', ' ', (string) ($res['result'] ?? 'FAILED')));
            }
        }
    }
    $filterParam = trim((string) ($_POST['current_filter'] ?? 'all'));
    redirect_to('scanner/participants.php?filter=' . urlencode($filterParam));
}

// Counts
$totalCount = (int) db()->query('SELECT COUNT(*) FROM students')->fetchColumn();
$enteredCount = (int) db()->query("SELECT COUNT(*) FROM students WHERE entry_status = 'ENTERED'")->fetchColumn();
$notEnteredCount = max(0, $totalCount - $enteredCount);

// Active filter & search
$filter = trim((string) ($_GET['filter'] ?? 'all'));
if (!in_array($filter, ['all', 'entered', 'not_entered'], true)) {
    $filter = 'all';
}
$search = trim((string) ($_GET['q'] ?? ''));

// Build Query
$sql = 'SELECT id, pnr_number, pass_id, name, course, year, division, photo, pass_type,
               pass_status, payment_status, entry_status, entry_time, entry_method
        FROM students WHERE 1=1';
$params = [];

if ($filter === 'entered') {
    $sql .= " AND entry_status = 'ENTERED'";
} elseif ($filter === 'not_entered') {
    $sql .= " AND (entry_status != 'ENTERED' OR entry_status IS NULL)";
}

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR pnr_number LIKE ? OR course LIKE ? OR pass_id LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$sql .= ' ORDER BY CASE WHEN entry_status = "ENTERED" THEN 0 ELSE 1 END, entry_time DESC, id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$participants = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en" class="scanner-html">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0b1516">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="robots" content="noindex">
<title>Attendee List · <?= e($eventName) ?></title>
<link rel="icon" type="image/png" href="<?= e(base_url('assets/images/event-logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
<style>
/* ---- guaranteed dark theme for scanner on all mobile browsers ---- */
html, html.scanner-html, body.scanner-body {
  background: #0b1516 !important;
  background-color: #0b1516 !important;
  color: #e6f4f2 !important;
  min-height: 100vh;
  min-height: 100dvh;
}
.scanner-shell {
  max-width: 600px;
  margin: 0 auto;
  background: #0b1516 !important;
  min-height: 100vh;
  min-height: 100dvh;
}
.scanner-header {
  background: linear-gradient(135deg, #134e4a, #0d9488) !important;
  border-radius: 1.25rem;
  padding: 1rem 1.1rem;
  color: #fff !important;
  box-shadow: 0 12px 30px rgba(0, 0, 0, .35);
}
.scanner-panel {
  background: rgba(255, 255, 255, .07) !important;
  border: 1px solid rgba(255, 255, 255, .12) !important;
  border-radius: 1rem;
}
.filter-tab {
  border-radius: 999px;
  font-weight: 700;
  font-size: .82rem;
  padding: .45rem 1rem;
  text-decoration: none;
  border: 1px solid rgba(255, 255, 255, .15);
  color: rgba(230, 244, 242, .75);
  background: rgba(255, 255, 255, .05);
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  transition: all .15s ease;
  white-space: nowrap;
}
.filter-tab:hover {
  color: #fff;
  background: rgba(255, 255, 255, .1);
  border-color: rgba(255, 255, 255, .3);
}
.filter-tab.active {
  background: #0d9488 !important;
  border-color: #0d9488 !important;
  color: #fff !important;
  box-shadow: 0 4px 12px rgba(13, 148, 136, .35);
}
.participant-item {
  background: rgba(255, 255, 255, .05);
  border: 1px solid rgba(255, 255, 255, .1);
  border-radius: 1rem;
  transition: transform .12s ease, border-color .12s ease;
}
.participant-item:hover {
  border-color: rgba(20, 184, 166, .4);
}
.participant-item.is-entered {
  border-left: 4px solid #10b981;
}
.participant-item.is-not-entered {
  border-left: 4px solid #f59e0b;
}
.sc-label {
  font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .12em;
  color: rgba(230, 244, 242, .65);
}
.sc-foot { color: rgba(230, 244, 242, .55); }
.sc-foot a { color: rgba(230, 244, 242, .85); font-weight: 600; }
.sc-foot a:hover { color: #fff; }
.form-dark {
  background: rgba(255, 255, 255, .08) !important;
  border: 1px solid rgba(255, 255, 255, .18) !important;
  color: #fff !important;
  border-radius: .8rem;
}
.form-dark:focus {
  background: rgba(255, 255, 255, .12) !important;
  border-color: #f59e0b !important;
  color: #fff !important;
  box-shadow: 0 0 0 .2rem rgba(245, 158, 11, .25);
}
.form-dark::placeholder {
  color: rgba(230, 244, 242, .4);
}
.avatar-sm {
  width: 44px; height: 44px; border-radius: 50%;
  object-fit: cover; background: #134e4a;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 1rem; color: #a7f3d0;
  flex-shrink: 0;
}
.btn-amber {
  background: #f59e0b !important;
  border-color: #f59e0b !important;
  color: #0b1516 !important;
  font-weight: 800 !important;
}
.btn-amber:hover, .btn-amber:focus {
  background: #d97706 !important;
  border-color: #d97706 !important;
  color: #000 !important;
}
.btn-teal {
  background: #0d9488 !important;
  border-color: #0d9488 !important;
  color: #fff !important;
  font-weight: 700 !important;
}
.btn-teal:hover, .btn-teal:focus {
  background: #0f766e !important;
  color: #fff !important;
}
.btn-outline-light {
  border-color: rgba(255, 255, 255, .25) !important;
  color: rgba(255, 255, 255, .85) !important;
}
.btn-outline-light:hover, .btn-outline-light:active, .btn-outline-light:focus {
  background: rgba(255, 255, 255, .12) !important;
  color: #fff !important;
}
</style>
</head>
<body class="scanner-body">
<div class="scanner-shell px-3 py-4">

  <!-- Header -->
  <header class="scanner-header mb-3">
    <div class="d-flex align-items-center justify-content-between gap-2">
      <div class="d-flex align-items-center gap-2 min-width-0">
        <a href="<?= e(base_url('scanner/index.php')) ?>" class="btn btn-sm btn-outline-light flex-shrink-0" style="border-radius:.6rem" title="Back to Scanner Home">
          <i class="bi bi-arrow-left"></i>
        </a>
        <div class="lh-1 min-width-0">
          <div class="text-uppercase opacity-75" style="font-size:.62rem;letter-spacing:.14em"><?= e($eventName) ?></div>
          <div class="fw-900 fs-5 text-truncate">PARTICIPANTS LIST</div>
        </div>
      </div>
      <div class="text-end flex-shrink-0">
        <span class="badge text-bg-amber">Gate: <?= e($volunteer['gate_name']) ?></span>
      </div>
    </div>
  </header>

  <?php render_flashes(); ?>

  <!-- Summary Stats Bar -->
  <section class="row g-2 mb-3">
    <div class="col-4">
      <a href="?filter=all&q=<?= urlencode($search) ?>" class="text-decoration-none">
        <div class="scanner-panel p-2 text-center" style="<?= $filter === 'all' ? 'border-color:#14b8a6 !important;background:rgba(20,184,166,.15) !important;' : '' ?>">
          <div class="sc-label" style="font-size:.6rem">TOTAL</div>
          <div class="fw-900 fs-4 text-white"><?= $totalCount ?></div>
        </div>
      </a>
    </div>
    <div class="col-4">
      <a href="?filter=entered&q=<?= urlencode($search) ?>" class="text-decoration-none">
        <div class="scanner-panel p-2 text-center" style="<?= $filter === 'entered' ? 'border-color:#10b981 !important;background:rgba(16,185,129,.15) !important;' : '' ?>">
          <div class="sc-label" style="font-size:.6rem;color:#6ee7b7">AAYE HUE</div>
          <div class="fw-900 fs-4 text-success"><?= $enteredCount ?></div>
        </div>
      </a>
    </div>
    <div class="col-4">
      <a href="?filter=not_entered&q=<?= urlencode($search) ?>" class="text-decoration-none">
        <div class="scanner-panel p-2 text-center" style="<?= $filter === 'not_entered' ? 'border-color:#f59e0b !important;background:rgba(245,158,11,.15) !important;' : '' ?>">
          <div class="sc-label" style="font-size:.6rem;color:#fcd34d">BAKI HAIN</div>
          <div class="fw-900 fs-4 text-warning"><?= $notEnteredCount ?></div>
        </div>
      </a>
    </div>
  </section>

  <!-- Live Search Bar -->
  <form method="get" action="participants.php" class="mb-3">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <div class="input-group">
      <span class="input-group-text form-dark border-end-0"><i class="bi bi-search"></i></span>
      <input type="text" name="q" class="form-control form-dark border-start-0 ps-0" placeholder="Search by name, PNR, course..." value="<?= e($search) ?>" autocomplete="off">
      <?php if ($search !== ''): ?>
        <a href="?filter=<?= e($filter) ?>" class="btn btn-outline-light border-start-0"><i class="bi bi-x"></i></a>
      <?php endif; ?>
      <button type="submit" class="btn btn-teal px-3">Search</button>
    </div>
  </form>

  <!-- Filter Pills -->
  <div class="d-flex gap-2 overflow-x-auto pb-2 mb-3">
    <a href="?filter=all<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
      All (<?= $totalCount ?>)
    </a>
    <a href="?filter=entered<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="filter-tab <?= $filter === 'entered' ? 'active' : '' ?>">
      <i class="bi bi-check-circle-fill text-success"></i> Entered / Aaye hue (<?= $enteredCount ?>)
    </a>
    <a href="?filter=not_entered<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="filter-tab <?= $filter === 'not_entered' ? 'active' : '' ?>">
      <i class="bi bi-hourglass-split text-warning"></i> Not Entered / Baki (<?= $notEnteredCount ?>)
    </a>
  </div>

  <!-- Participants List -->
  <div class="participants-container">
    <?php if (!$participants): ?>
      <div class="scanner-panel p-4 text-center sc-foot">
        <i class="bi bi-people d-block mb-2" style="font-size:2.2rem;opacity:.5"></i>
        No participants match your search.
      </div>
    <?php else: ?>
      <?php foreach ($participants as $p): ?>
        <?php
          $isEntered = ($p['entry_status'] === 'ENTERED');
          $isPaid = ($p['pass_type'] === 'PAID');
          $payApproved = ($p['payment_status'] === 'APPROVED' || !$isPaid);
        ?>
        <div class="participant-item p-3 mb-2 <?= $isEntered ? 'is-entered' : 'is-not-entered' ?>">
          <div class="d-flex align-items-start gap-3">
            <!-- Photo or Initial Avatar -->
            <?php if (!empty($p['photo'])): ?>
              <img src="<?= e(base_url($p['photo'])) ?>" alt="<?= e($p['name']) ?>" class="avatar-sm">
            <?php else: ?>
              <div class="avatar-sm">
                <?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?>
              </div>
            <?php endif; ?>

            <!-- Info -->
            <div class="min-width-0 flex-grow-1">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="fw-bold text-white fs-6 text-truncate"><?= e($p['name']) ?></div>
                <!-- Entry status badge -->
                <?php if ($isEntered): ?>
                  <span class="badge text-bg-success flex-shrink-0"><i class="bi bi-check2-circle me-1"></i>ENTERED</span>
                <?php else: ?>
                  <span class="badge text-bg-secondary flex-shrink-0" style="background:#475569 !important">NOT ENTERED</span>
                <?php endif; ?>
              </div>

              <!-- PNR & Course -->
              <div class="small sc-foot d-flex flex-wrap align-items-center gap-2 mt-1">
                <span>PNR: <strong class="text-white font-monospace"><?= e($p['pnr_number'] ?: $p['pass_id']) ?></strong></span>
                <span>•</span>
                <span><?= e($p['course']) ?><?= $p['year'] ? ' (' . e($p['year']) . ')' : '' ?></span>
                <?php if ($p['division']): ?>
                  <span>• Div <?= e($p['division']) ?></span>
                <?php endif; ?>
              </div>

              <!-- Entry timestamp or Action -->
              <div class="d-flex justify-content-between align-items-center gap-2 mt-2 pt-1 border-top border-light border-opacity-10">
                <div class="small" style="font-size:.75rem">
                  <?php if ($isEntered): ?>
                    <span class="text-success"><i class="bi bi-clock me-1"></i>Entered <?= e(dt_short((string) $p['entry_time'])) ?></span>
                  <?php else: ?>
                    <span class="<?= $payApproved ? 'text-teal' : 'text-warning' ?>">
                      Payment: <strong><?= e($p['payment_status']) ?></strong>
                    </span>
                  <?php endif; ?>
                </div>

                <!-- One-click Manual Entry button if not entered & payment approved -->
                <?php if (!$isEntered && $payApproved && $entryOpen): ?>
                  <form method="post" action="participants.php" onsubmit="return confirm('Mark <?= e(addslashes($p['name'])) ?> as ENTERED?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_entry">
                    <input type="hidden" name="student_id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="current_filter" value="<?= e($filter) ?>">
                    <button type="submit" class="btn btn-sm btn-teal py-0 px-2 fw-bold" style="font-size:.75rem">
                      <i class="bi bi-box-arrow-in-right me-1"></i>Mark Entry
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Bottom Nav -->
  <div class="d-grid gap-2 mt-4">
    <a href="<?= e(base_url('scanner/scan.php')) ?>" class="btn btn-amber btn-lg btn-block shadow-sm">
      <i class="bi bi-camera-video me-2"></i>GO TO CAMERA SCANNER
    </a>
    <a href="<?= e(base_url('scanner/index.php')) ?>" class="btn btn-outline-light btn-lg btn-block">
      <i class="bi bi-house me-2"></i>Scanner Home
    </a>
  </div>

</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
