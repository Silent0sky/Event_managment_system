<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin layout footer (scripts + mobile bottom nav + More offcanvas).
 */
$settings  = get_settings();
$admin     = current_admin();
$navItems  = [
    ['key' => 'dashboard',   'label' => 'Dashboard',     'href' => 'admin/dashboard.php',        'icon' => 'bi-speedometer2',           'perm' => 'dashboard'],
    ['key' => 'participants','label' => 'Participants',  'href' => 'admin/registrations.php',    'icon' => 'bi-people',                 'perm' => 'participants'],
    ['key' => 'payments',    'label' => 'Payments',      'href' => 'admin/payments.php',         'icon' => 'bi-cash-stack',             'perm' => 'payments'],
    ['key' => 'entries',     'label' => 'Entries',       'href' => 'admin/entries.php',          'icon' => 'bi-door-open',              'perm' => 'entries'],
    ['key' => 'manual',      'label' => 'Manual Entry',  'href' => 'admin/manual-entry.php',     'icon' => 'bi-person-check',           'perm' => 'manual_entry'],
    ['key' => 'passes',      'label' => 'Passes',        'href' => 'admin/passes.php',           'icon' => 'bi-ticket-perforated',      'perm' => 'passes'],
    ['key' => 'volunteers',  'label' => 'Volunteers',    'href' => 'admin/volunteers.php',       'icon' => 'bi-person-badge',           'perm' => 'volunteers'],
    ['key' => 'reports',     'label' => 'Reports',       'href' => 'admin/reports.php',          'icon' => 'bi-file-earmark-arrow-down','perm' => 'reports'],
    ['key' => 'settings',    'label' => 'Settings',      'href' => 'admin/settings.php',         'icon' => 'bi-gear',                   'perm' => 'settings'],
    ['key' => 'admins',      'label' => 'Admin Users',   'href' => 'admin/admin-users.php',      'icon' => 'bi-shield-lock',            'perm' => 'admins'],
    ['key' => 'audit',       'label' => 'Audit Logs',    'href' => 'admin/audit-logs.php',       'icon' => 'bi-journal-text',           'perm' => 'audit_logs'],
];
$allowedNav = array_values(array_filter($navItems, fn ($n) => admin_can($n['perm'])));
$primaryKeys = ['dashboard', 'participants', 'payments', 'entries'];
$bottomNav   = array_values(array_filter($allowedNav, fn ($n) => in_array($n['key'], $primaryKeys, true)));
$bottomNav   = array_slice($bottomNav, 0, 4);
?>
    </div><!-- /container-fluid -->
  </div><!-- /admin-content -->
</div><!-- /d-flex -->

<footer class="admin-foot d-none d-lg-block">
  <div class="px-4 py-2 small text-secondary">
    <?= e($settings['event_name']) ?> · Admin Console · logged in as <strong><?= e($admin['name'] ?? '') ?></strong>
  </div>
</footer>

<!-- Mobile bottom navigation -->
<nav class="admin-bottomnav d-lg-none" aria-label="Admin quick navigation">
  <?php foreach ($bottomNav as $n): ?>
    <a href="<?= e(base_url($n['href'])) ?>" class="bottomnav-item<?= ($activeNav ?? '') === $n['key'] ? ' active' : '' ?>">
      <i class="bi <?= e($n['icon']) ?>"></i><span><?= e($n['label']) ?></span>
    </a>
  <?php endforeach; ?>
  <a href="#" class="bottomnav-item" data-bs-toggle="offcanvas" data-bs-target="#moreNav" role="button" aria-controls="moreNav">
    <i class="bi bi-grid"></i><span>More</span>
  </a>
</nav>

<!-- More navigation (offcanvas) -->
<div class="offcanvas offcanvas-bottom admin-more-offcanvas" tabindex="-1" id="moreNav" aria-labelledby="moreNavLabel" style="height:auto">
  <div class="offcanvas-header pb-1">
    <h5 class="offcanvas-title fw-bold" id="moreNavLabel">More</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body pt-1">
    <div class="row g-2 pb-2">
      <?php foreach ($allowedNav as $n): ?>
        <div class="col-6 col-sm-4">
          <a class="d-flex align-items-center gap-2 p-3 bg-white rounded-3 border text-decoration-none text-body more-item" href="<?= e(base_url($n['href'])) ?>">
            <i class="bi <?= e($n['icon']) ?> fs-4 text-teal"></i><span class="fw-semibold"><?= e($n['label']) ?></span>
          </a>
        </div>
      <?php endforeach; ?>
      <div class="col-6 col-sm-4">
        <a class="d-flex align-items-center gap-2 p-3 bg-white rounded-3 border text-decoration-none text-body more-item" href="<?= e(base_url('index.php')) ?>">
          <i class="bi bi-box-arrow-up-right fs-4 text-teal"></i><span class="fw-semibold">Public Site</span>
        </a>
      </div>
      <div class="col-6 col-sm-4">
        <a class="d-flex align-items-center gap-2 p-3 bg-white rounded-3 border text-decoration-none text-danger more-item" href="<?= e(base_url('admin/logout.php')) ?>">
          <i class="bi bi-box-arrow-right fs-4"></i><span class="fw-semibold">Logout</span>
        </a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(base_url('assets/js/app.js')) ?>"></script>
</body>
</html>
