<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin · Volunteer (gate scanner) management.
 * perm: volunteers — SUPER_ADMIN only (enforced by the role matrix in admin-auth.php).
 * All POST handlers (create / edit / toggle / reset_password) live in THIS file.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('volunteers');

$pv = static function (string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
};

/* ===================== POST HANDLERS (before any output) ===================== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    $action = $pv('action');

    $fail = static function (string $m): void {
        set_flash('danger', $m);
        redirect_to('admin/volunteers.php');
    };
    $done = static function (string $m): void {
        set_flash('success', $m);
        redirect_to('admin/volunteers.php');
    };

    if ($action === 'create') {
        $code     = strtoupper($pv('volunteer_id'));
        $name     = $pv('name');
        $password = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
        $gate     = $pv('gate_name');

        $errors = [];
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
            $errors[] = 'Volunteer ID must be 2–10 uppercase letters/numbers.';
        }
        if ($name === '' || mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            $errors[] = 'Name must be 3–100 characters.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!in_array($gate, gates(), true)) {
            $errors[] = 'Choose a valid gate.';
        }
        if (!$errors) {
            $stmt = db()->prepare('SELECT COUNT(*) FROM volunteers WHERE volunteer_id = ?');
            $stmt->execute([$code]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors[] = 'Volunteer ID ' . $code . ' already exists.';
            }
        }
        if ($errors) {
            $fail(implode(' · ', $errors));
        }
        try {
            $stmt = db()->prepare(
                'INSERT INTO volunteers (volunteer_id, name, password_hash, gate_name, status, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([$code, $name, password_hash($password, PASSWORD_DEFAULT), $gate, 'ACTIVE', now_db(), now_db()]);
            audit_log('VOLUNTEER_CREATED', 'VOLUNTEER', $code, null,
                ['name' => $name, 'gate' => $gate, 'status' => 'ACTIVE'], null);
            $done('Volunteer ' . $code . ' (' . $name . ') created for ' . $gate . '.');
        } catch (Throwable $ex) {
            error_log('[FresherParty] volunteer create: ' . $ex->getMessage());
            $fail('Could not create the volunteer. Please try again.');
        }
    }

    if ($action === 'toggle' || $action === 'edit' || $action === 'reset_password') {
        $rowId = is_numeric($pv('id')) ? (int) $pv('id') : 0;
        if ($rowId <= 0) {
            $fail('Invalid volunteer.');
        }
        $stmt = db()->prepare('SELECT * FROM volunteers WHERE id = ? LIMIT 1');
        $stmt->execute([$rowId]);
        $vol = $stmt->fetch();
        if (!$vol) {
            $fail('Volunteer not found.');
        }
        $code = (string) $vol['volunteer_id'];

        if ($action === 'toggle') {
            $newStatus = ((string) $vol['status'] === 'ACTIVE') ? 'DISABLED' : 'ACTIVE';
            $stmt = db()->prepare('UPDATE volunteers SET status = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$newStatus, now_db(), $rowId]);
            audit_log(
                $newStatus === 'DISABLED' ? 'VOLUNTEER_DISABLED' : 'VOLUNTEER_ENABLED',
                'VOLUNTEER', $code,
                ['status' => (string) $vol['status']], ['status' => $newStatus],
                $pv('reason') !== '' ? $pv('reason') : null
            );
            $done('Volunteer ' . $code . ' is now ' . strtolower($newStatus) . '.');
        }

        if ($action === 'edit') {
            $name = $pv('name');
            $gate = $pv('gate_name');
            if ($name === '' || mb_strlen($name) < 3 || mb_strlen($name) > 100) {
                $fail('Name must be 3–100 characters.');
            }
            if (!in_array($gate, gates(), true)) {
                $fail('Choose a valid gate.');
            }
            $stmt = db()->prepare('UPDATE volunteers SET name = ?, gate_name = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$name, $gate, now_db(), $rowId]);
            audit_log('VOLUNTEER_EDITED', 'VOLUNTEER', $code,
                ['name' => (string) $vol['name'], 'gate' => (string) $vol['gate_name']],
                ['name' => $name, 'gate' => $gate]);
            $done('Volunteer ' . $code . ' updated.');
        }

        if ($action === 'reset_password') {
            $password = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
            if (strlen($password) < 8) {
                $fail('New password must be at least 8 characters.');
            }
            $stmt = db()->prepare('UPDATE volunteers SET password_hash = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), now_db(), $rowId]);
            audit_log('VOLUNTEER_PASSWORD_RESET', 'VOLUNTEER', $code,
                null, ['password' => '(changed)']);
            $done('Password reset for volunteer ' . $code . '.');
        }
    }

    $fail('Unknown action.');
}

/* ===================== DATA ===================== */

/* Auto-suggest the next volunteer code: MAX numeric code + 1 */
$suggestedCode = 'V001';
$codes = db()->query("SELECT volunteer_id FROM volunteers WHERE volunteer_id LIKE 'V%'")->fetchAll(PDO::FETCH_COLUMN);
$maxNum = 0;
foreach ($codes as $c) {
    if (preg_match('/^V(\d{1,17})$/', (string) $c, $m)) {
        $maxNum = max($maxNum, (int) $m[1]);
    }
}
if ($maxNum > 0) {
    $suggestedCode = 'V' . str_pad((string) ($maxNum + 1), 3, '0', STR_PAD_LEFT);
}

$stmt = db()->prepare(
    "SELECT v.*, (SELECT COUNT(*) FROM entry_logs el WHERE el.volunteer_id = v.id AND el.scan_time >= ?) AS scans_today
     FROM volunteers v
     ORDER BY v.status ASC, v.volunteer_id ASC
     LIMIT 500"
);
$stmt->execute([today_start_db()]);
$volunteers = $stmt->fetchAll();

$activeCount = 0;
foreach ($volunteers as $v) {
    if ((string) $v['status'] === 'ACTIVE') {
        $activeCount++;
    }
}

$activeNav = 'volunteers';
$pageTitle = 'Volunteers';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="row g-4">
  <div class="col-12 col-xl-4">
    <!-- ADD VOLUNTEER -->
    <div class="card card-shadow">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-person-plus-fill me-2 text-teal"></i>Add volunteer</h2>
        <form method="post" action="<?= e(base_url('admin/volunteers.php')) ?>" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="new-code">Volunteer ID</label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-person-badge"></i></span>
              <input class="form-control font-monospace" id="new-code" name="volunteer_id" required
                     value="<?= e($suggestedCode) ?>" maxlength="10" pattern="[A-Za-z0-9]{2,10}"
                     title="2–10 letters and numbers">
            </div>
            <div class="form-text">Suggested next code — you can change it (A–Z, 0–9).</div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="new-name">Full name</label>
            <input class="form-control" id="new-name" name="name" required maxlength="100" placeholder="e.g. Aarav Sharma">
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="new-password">Password</label>
            <input class="form-control" id="new-password" name="password" type="password" required minlength="8"
                   maxlength="100" placeholder="Minimum 8 characters" autocomplete="new-password">
            <div class="form-text">Share this once — the volunteer logs in at <span class="font-monospace">scanner/login.php</span>.</div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="new-gate">Gate</label>
            <select class="form-select" id="new-gate" name="gate_name" required>
              <?php foreach (gates() as $g): ?>
                <option value="<?= e($g) ?>"><?= e($g) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn btn-teal btn-lg w-100"><i class="bi bi-plus-lg me-2"></i>CREATE VOLUNTEER</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <!-- LIST -->
    <div class="card card-shadow">
      <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="card-header-title"><i class="bi bi-people-fill me-2 text-teal"></i>Gate scanners</span>
        <span class="small text-muted-2"><?= (int) count($volunteers) ?> total · <?= (int) $activeCount ?> active</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-mobile table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th scope="col">Volunteer ID</th>
                <th scope="col">Name</th>
                <th scope="col">Gate</th>
                <th scope="col">Status</th>
                <th scope="col">Created</th>
                <th scope="col">Scans Today</th>
                <th scope="col" class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$volunteers): ?>
              <tr>
                <td colspan="7">
                  <div class="empty-state">
                    <i class="bi bi-person-badge"></i>
                    <p class="mt-2 mb-0">No volunteers yet — create the first one on the left.</p>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
            <?php foreach ($volunteers as $v):
                $isActive = ((string) $v['status'] === 'ACTIVE');
            ?>
              <tr>
                <td data-label="Volunteer ID"><span class="font-monospace fw-bold"><?= e((string) $v['volunteer_id']) ?></span></td>
                <td data-label="Name"><span class="fw-semibold"><?= e((string) $v['name']) ?></span></td>
                <td data-label="Gate"><?= e((string) $v['gate_name']) ?></td>
                <td data-label="Status"><?= status_badge((string) $v['status']) ?></td>
                <td data-label="Created"><span class="small"><?= e(date_short((string) $v['created_at'])) ?></span></td>
                <td data-label="Scans Today"><span class="badge rounded-pill text-bg-teal"><?= (int) $v['scans_today'] ?></span></td>
                <td data-label="Actions" class="td-actions text-end">
                  <div class="d-flex flex-wrap gap-1 justify-content-end">
                    <?php if ($isActive): ?>
                    <form method="post" action="<?= e(base_url('admin/volunteers.php')) ?>" class="d-inline"
                          data-confirm="Disable volunteer <?= e((string) $v['volunteer_id']) ?>? They will be logged out of the scanner immediately."
                          data-reason-prompt="Optional reason (audit log):">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                      <input type="hidden" name="reason" value="">
                      <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-pause-circle"></i><span class="d-none d-md-inline ms-1">Disable</span></button>
                    </form>
                    <?php else: ?>
                    <form method="post" action="<?= e(base_url('admin/volunteers.php')) ?>" class="d-inline"
                          data-confirm="Re-enable volunteer <?= e((string) $v['volunteer_id']) ?>?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                      <input type="hidden" name="reason" value="">
                      <button type="submit" class="btn btn-sm btn-outline-teal"><i class="bi bi-play-circle"></i><span class="d-none d-md-inline ms-1">Enable</span></button>
                    </form>
                    <?php endif; ?>

                    <button type="button" class="btn btn-sm btn-outline-secondary btn-resetpw" data-id="<?= (int) $v['id'] ?>"
                            data-code="<?= e((string) $v['volunteer_id']) ?>">
                      <i class="bi bi-key"></i><span class="d-none d-md-inline ms-1">Reset PW</span>
                    </button>

                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editVolunteerModal"
                            data-id="<?= (int) $v['id'] ?>" data-code="<?= e((string) $v['volunteer_id']) ?>"
                            data-name="<?= e((string) $v['name']) ?>" data-gate="<?= e((string) $v['gate_name']) ?>">
                      <i class="bi bi-pencil"></i><span class="d-none d-md-inline ms-1">Edit</span>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Shared EDIT modal -->
<div class="modal fade" id="editVolunteerModal" tabindex="-1" aria-hidden="true" aria-labelledby="editVolunteerTitle">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(base_url('admin/volunteers.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editVolunteerRowId" value="">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="editVolunteerTitle"><i class="bi bi-pencil-square me-2 text-teal"></i>Edit volunteer <span id="editVolunteerCodeLabel" class="font-monospace"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="editVolunteerName">Full name</label>
          <input class="form-control" id="editVolunteerName" name="name" required maxlength="100">
        </div>
        <div class="mb-1">
          <label class="form-label small fw-semibold" for="editVolunteerGate">Gate</label>
          <select class="form-select" id="editVolunteerGate" name="gate_name" required>
            <?php foreach (gates() as $g): ?>
              <option value="<?= e($g) ?>"><?= e($g) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-teal"><i class="bi bi-check-lg me-1"></i>Save changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Hidden form used by the Reset PW buttons -->
<form id="resetPwForm" method="post" action="<?= e(base_url('admin/volunteers.php')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="reset_password">
  <input type="hidden" name="id" value="">
  <input type="hidden" name="password" value="">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
  /* Fill the shared edit modal from row data attributes */
  var editModal = document.getElementById('editVolunteerModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      if (!btn) { return; }
      document.getElementById('editVolunteerRowId').value = btn.getAttribute('data-id') || '';
      document.getElementById('editVolunteerCodeLabel').textContent = btn.getAttribute('data-code') || '';
      document.getElementById('editVolunteerName').value = btn.getAttribute('data-name') || '';
      var gate = btn.getAttribute('data-gate') || '';
      var sel = document.getElementById('editVolunteerGate');
      if (sel) {
        for (var i = 0; i < sel.options.length; i++) {
          if (sel.options[i].value === gate) { sel.selectedIndex = i; break; }
        }
      }
    });
  }

  /* Reset password: prompt (min 8 chars) → confirm → post hidden form */
  document.querySelectorAll('.btn-resetpw').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var code = btn.getAttribute('data-code') || '';
      var pw = window.prompt('Enter a NEW password for volunteer ' + code + ' (minimum 8 characters):', '');
      if (pw === null) { return; }
      pw = pw.replace(/^\s+|\s+$/g, '');
      if (pw.length < 8) {
        window.alert('The new password must be at least 8 characters.');
        return;
      }
      if (!window.confirm('Reset the password for volunteer ' + code + '? They must log in again with the new password.')) {
        return;
      }
      var form = document.getElementById('resetPwForm');
      if (!form) { return; }
      form.querySelector('[name="id"]').value = btn.getAttribute('data-id') || '';
      form.querySelector('[name="password"]').value = pw;
      form.submit();
    });
  });
});
</script>

<?php include __DIR__ . '/../includes/admin-footer.php';
