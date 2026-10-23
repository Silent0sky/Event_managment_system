<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin layout header.
 * Set before including: $admin (array from require_permission), $activeNav (string), $pageTitle (string)
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/admin-auth.php';

$admin     = $admin ?? require_permission('dashboard');
$activeNav = $activeNav ?? '';
$pageTitle = $pageTitle ?? 'Dashboard';
$settings  = get_settings();

$navItems = [
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

// Bottom nav (mobile): up to 4 primary items + "More"
$primaryKeys = ['dashboard', 'participants', 'payments', 'entries'];
$bottomNav   = array_values(array_filter($allowedNav, fn ($n) => in_array($n['key'], $primaryKeys, true)));
$bottomNav   = array_slice($bottomNav, 0, 4);

$navLink = function (array $n) use ($activeNav): string {
    $active = ($activeNav === $n['key']) ? ' active' : '';
    return '<a class="nav-link-admin' . $active . '" href="' . e(base_url($n['href'])) . '">'
        . '<i class="bi ' . e($n['icon']) . '"></i><span>' . e($n['label']) . '</span></a>';
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f766e">
<title>Admin · <?= e($pageTitle) ?> · <?= e($settings['event_name']) ?></title>
<link rel="icon" type="image/png" href="<?= e(base_url('assets/images/event-logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
</head>
<body class="admin-body d-flex flex-column min-vh-100">

<!-- Top bar -->
<header class="admin-topbar sticky-top">
  <div class="container-fluid px-2 px-lg-3">
    <div class="d-flex align-items-center justify-content-between gap-2 py-2">
      <div class="d-flex align-items-center gap-2 min-width-0">
        <img src="<?= e(base_url($settings['event_logo'] ?: 'assets/images/event-logo.png')) ?>" alt="" width="36" height="36" class="rounded-3 d-none d-sm-block">
        <div class="lh-1 min-width-0">
          <strong class="d-block text-truncate" style="max-width:60vw"><?= e($settings['event_name']) ?></strong>
          <small class="text-secondary d-none d-sm-block">Admin Console</small>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <form class="d-none d-md-block" role="search" method="get" action="<?= e(base_url('admin/registrations.php')) ?>">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input class="form-control" type="search" name="s" placeholder="Search name, roll, mobile, pass ID…" autocomplete="off" style="min-width:240px">
          </div>
        </form>
        <div class="dropdown">
          <button class="btn btn-light btn-sm d-flex align-items-center gap-2 border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-circle fs-5"></i>
            <span class="d-none d-sm-inline text-truncate" style="max-width:120px"><?= e($admin['name']) ?></span>
            <i class="bi bi-chevron-down small"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li class="px-3 py-2">
              <div class="fw-semibold text-truncate" style="max-width:200px"><?= e($admin['name']) ?></div>
              <div class="mt-1"><?= status_badge((string) $admin['role']) ?></div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(base_url('index.php')) ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-2"></i>Open public site</a></li>
            <li><a class="dropdown-item text-danger" href="<?= e(base_url('admin/logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</header>

<div class="d-flex flex-grow-1 w-100">
  <!-- Desktop sidebar -->
  <aside class="admin-sidebar d-none d-lg-flex flex-column pt-2 pb-3">
    <nav class="nav flex-column px-2 gap-1" aria-label="Admin navigation">
      <?php foreach ($allowedNav as $n) { echo $navLink($n); } ?>
    </nav>
    <div class="mt-auto px-3 small text-white-50">
      <div class="mb-1"><i class="bi bi-calendar-event me-1"></i><?= e(date_short((string) ($settings['event_date'] ?? ''))) ?></div>
      <div><?= e(ucfirst(strtolower((string) $settings['registration_status']))) ?> registration</div>
    </div>
  </aside>

  <!-- Content -->
  <div class="flex-grow-1 min-width-0 admin-content">
    <div class="container-fluid px-2 px-lg-4 py-3 py-lg-4 pb-5 pb-lg-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h4 fw-bold mb-0"><?= e($pageTitle) ?></h1>
        <?php if (empty($hideHeaderSearch)): ?>
        <button class="btn btn-outline-secondary btn-sm d-md-none" type="button" data-bs-toggle="collapse" data-bs-target="#adminSearch" aria-expanded="false" aria-controls="adminSearch">
          <i class="bi bi-search me-1"></i>Search
        </button>
        <?php endif; ?>
      </div>
      <?php if (empty($hideHeaderSearch)): ?>
      <div class="collapse d-md-none mb-3" id="adminSearch">
        <form role="search" method="get" action="<?= e(base_url('admin/registrations.php')) ?>">
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input class="form-control" type="search" name="s" placeholder="Search name, roll, mobile, pass ID…" autocomplete="off">
            <button class="btn btn-teal" type="submit">Go</button>
          </div>
        </form>
      </div>
      <?php endif; ?>
      <?php render_flashes(); ?>
