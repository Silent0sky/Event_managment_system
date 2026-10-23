<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin user management (SUPER_ADMIN only).
 * Create / disable / reset passwords for EVENT_ADMIN, PAYMENT_ADMIN,
 * ENTRY_MANAGER (and other SUPER_ADMIN) accounts.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('admins');
$activeNav = 'admins';
$pageTitle = 'Admin Users';

$roles = ['SUPER_ADMIN', 'EVENT_ADMIN', 'PAYMENT_ADMIN', 'ENTRY_MANAGER'];

/* ---------------- POST handlers ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'create') {
        $name     = trim((string) ($_POST['name'] ?? ''));
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $role     = in_array((string) ($_POST['role'] ?? ''), $roles, true) ? (string) $_POST['role'] : '';
        $pass     = (string) ($_POST['password'] ?? '');
        $pass2    = (string) ($_POST['password2'] ?? '');
        $err = '';
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            $err = 'Enter a full name (3–100 characters).';
        } elseif (!preg_match('/^[a-z0-9_.]{3,30}$/', $username)) {
            $err = 'Username must be 3–30 lowercase letters/digits (a-z, 0-9, _ or .).';
        } elseif ($role === '') {
            $err = 'Choose a role.';
        } elseif (strlen($pass) < 8) {
            $err = 'Password must be at least 8 characters.';
        } elseif ($pass !== $pass2) {
            $err = 'Passwords do not match.';
        } else {
            try {
                $stmt = db()->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
                $stmt->execute([$username]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $err = 'That username is already taken.';
                }
            } catch (Throwable $ex) {
                $err = 'Database error — please try again.';
            }
        }
        if ($err === '') {
            try {
                $stmt = db()->prepare("INSERT INTO admins (name, username, password_hash, role, status, created_at, updated_at)
                                       VALUES (?,?,?,?,'ACTIVE',?,?)");
                $stmt->execute([$name, $username, password_hash($pass, PASSWORD_DEFAULT), $role, now_db(), now_db()]);
                audit_log('ADMIN_CREATED', 'ADMIN', $username, null, ['name' => $name, 'role' => $role]);
                set_flash('success', 'Admin account "' . $name . '" (' . $role . ') created.');
                redirect_to('admin/admin-users.php');
            } catch (Throwable $ex) {
                set_flash('danger', 'Could not create the admin account. Please try again.');
            }
        } else {
            set_flash('danger', $err);
        }
    } elseif ($do === 'toggle') {
        $id = (int) ($_POST['admin_id'] ?? 0);
        try {
            $stmt = db()->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                set_flash('danger', 'Admin not found.');
            } elseif ((int) $target['id'] === (int) $admin['id']) {
                set_flash('warning', 'You cannot disable your own account.');
            } else {
                $new = ($target['status'] === 'ACTIVE') ? 'DISABLED' : 'ACTIVE';
                $stmt = db()->prepare('UPDATE admins SET status = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([$new, now_db(), $id]);
                audit_log($new === 'DISABLED' ? 'ADMIN_DISABLED' : 'ADMIN_ENABLED', 'ADMIN', (string) $target['username'],
                    ['status' => $target['status']], ['status' => $new]);
                set_flash('success', 'Admin "' . $target['name'] . '" is now ' . strtolower($new) . '.');
            }
        } catch (Throwable $ex) {
            set_flash('danger', 'Database error — please try again.');
        }
    } elseif ($do === 'reset_password') {
        $id = (int) ($_POST['admin_id'] ?? 0);
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($pass) < 8) {
            set_flash('danger', 'New password must be at least 8 characters.');
        } else {
            try {
                $stmt = db()->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    set_flash('danger', 'Admin not found.');
                } else {
                    $stmt = db()->prepare('UPDATE admins SET password_hash = ?, updated_at = ? WHERE id = ?');
                    $stmt->execute([password_hash($pass, PASSWORD_DEFAULT), now_db(), $id]);
                    audit_log('ADMIN_PASSWORD_RESET', 'ADMIN', (string) $target['username']);
                    set_flash('success', 'Password reset for "' . $target['name'] . '".');
                }
            } catch (Throwable $ex) {
                set_flash('danger', 'Database error — please try again.');
            }
        }
    }
    redirect_to('admin/admin-users.php');
}

/* ---------------- Data ---------------- */
$rows = db()->query('SELECT id, name, username, role, status, created_at FROM admins ORDER BY id ASC')->fetchAll();
$nextSuggested = '';
include __DIR__ . '/../includes/admin-header.php';
?>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card card-shadow h-100">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-person-gear me-2 text-teal"></i>Add admin account</h2>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="create">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold" for="a-name">Full name</label>
              <input class="form-control form-control-lg" id="a-name" name="name" required maxlength="100" placeholder="e.g. Priya Verma">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold" for="a-username">Username</label>
              <input class="form-control form-control-lg" id="a-username" name="username" required pattern="[a-z0-9_.]{3,30}" placeholder="priya">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold" for="a-role">Role</label>
              <select class="form-select form-select-lg" id="a-role" name="role" required>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= e($r) ?>"><?= e(str_replace('_', ' ', $r)) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Pay verifier → PAYMENT_ADMIN. Gate manager → ENTRY_MANAGER.</div>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold" for="a-pass">Password</label>
              <input class="form-control form-control-lg" type="password" id="a-pass" name="password" required minlength="8" autocomplete="new-password">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold" for="a-pass2">Repeat password</label>
              <input class="form-control form-control-lg" type="password" id="a-pass2" name="password2" required minlength="8" autocomplete="new-password">
            </div>
            <div class="col-12">
              <button class="btn btn-teal btn-lg btn-block" type="submit"><i class="bi bi-plus-circle me-2"></i>Create admin</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card card-shadow h-100">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-people me-2 text-teal"></i>Admin accounts <span class="badge text-bg-teal rounded-pill"><?= count($rows) ?></span></h2>
        <div class="table-responsive">
          <table class="table table-hover table-mobile align-middle mb-0">
            <thead>
              <tr>
                <th scope="col">Name</th>
                <th scope="col">Username</th>
                <th scope="col">Role</th>
                <th scope="col">Status</th>
                <th scope="col">Created</th>
                <th scope="col" class="td-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): $self = ((int) $r['id'] === (int) $admin['id']); ?>
              <tr>
                <td data-label="Name"><strong><?= e((string) $r['name']) ?></strong><?= $self ? ' <i class="bi bi-person-check text-teal" title="you"></i>' : '' ?></td>
                <td data-label="Username" class="font-monospace small"><?= e((string) $r['username']) ?></td>
                <td data-label="Role"><?= status_badge((string) $r['role']) ?></td>
                <td data-label="Status"><?= status_badge((string) $r['status']) ?></td>
                <td data-label="Created" class="small text-muted-2"><?= e(date_short((string) $r['created_at'])) ?></td>
                <td data-label="Actions" class="td-actions">
                  <form method="post" class="d-inline" data-confirm="<?= $r['status'] === 'ACTIVE' ? 'Disable this admin account?' : 'Re-enable this admin account?' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="toggle">
                    <input type="hidden" name="admin_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm <?= $r['status'] === 'ACTIVE' ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit" <?= $self ? 'disabled' : '' ?>>
                      <i class="bi <?= $r['status'] === 'ACTIVE' ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                      <?= $r['status'] === 'ACTIVE' ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>
                  <form method="post" class="d-inline" data-confirm="Reset this admin's password?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="reset_password">
                    <input type="hidden" name="admin_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="password" value="">
                    <button class="btn btn-sm btn-outline-secondary" type="submit" data-reveal="password">
                      <i class="bi bi-key"></i> Reset PW
                    </button>
                  </form>
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

<script>
/* Reset PW button prompts for the new password before submitting */
document.querySelectorAll('form[data-confirm] button[data-reveal]').forEach(function (btn) {
  btn.addEventListener('click', function (ev) {
    ev.preventDefault();
    var pw = window.prompt('Enter the new password (min 8 characters):', '');
    if (pw === null) { return; }
    if (pw.trim().length < 8) { window.alert('Password must be at least 8 characters.'); return; }
    var form = btn.closest('form');
    form.querySelector('[name="password"]').value = pw;
    form.submit();
  });
});
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
