<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Public site header.
 * Set before including: $pageTitle (string), $bodyClass (string), $hideNav (bool)
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
secure_session_start();

$settings    = get_settings();
$pageTitle   = $pageTitle ?? (string) $settings['event_name'];
$bodyClass   = $bodyClass ?? '';
$hideNav     = $hideNav ?? false;
$regOpen     = (string) $settings['registration_status'] === 'OPEN';
$collegeLogo = $settings['college_logo'] ?: 'assets/images/college-logo.png';
$eventLogo   = $settings['event_logo'] ?: 'assets/images/event-logo.png';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d9488">
<meta name="description" content="<?= e($settings['event_name']) ?> — online registration, payment verification and digital entry pass. <?= e($settings['college_name']) ?>">
<title><?= e($pageTitle) ?> · <?= e($settings['college_name']) ?></title>
<link rel="icon" type="image/png" href="<?= e(base_url('assets/images/event-logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
</head>
<body class="d-flex flex-column min-vh-100 <?= e($bodyClass) ?>">

<?php if (!$hideNav): ?>
<nav class="navbar navbar-expand-lg sticky-top site-nav" aria-label="Main navigation">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(base_url('index.php')) ?>">
      <img src="<?= e(base_url($eventLogo)) ?>" alt="<?= e($settings['event_name']) ?> logo" width="38" height="38" class="rounded-3">
      <span class="d-flex flex-column lh-1">
        <strong class="brand-title"><?= e($settings['event_name']) ?></strong>
        <small class="text-secondary brand-sub"><?= e($settings['college_name']) ?></small>
      </span>
    </a>

    <!-- Mobile Quick Login Button + Hamburger -->
    <div class="d-flex align-items-center gap-2 d-lg-none">
      <button class="btn btn-outline-teal btn-sm px-2 py-1 fw-bold d-inline-flex align-items-center gap-1"
              type="button" data-bs-toggle="modal" data-bs-target="#portalModal" aria-label="Open Portals Menu">
        <i class="bi bi-box-arrow-in-right"></i>
        <span>Login</span>
      </button>
      <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
        <i class="bi bi-list fs-2 text-dark"></i>
      </button>
    </div>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1 py-3 py-lg-0 text-center text-lg-start">
        <li class="nav-item">
          <a class="nav-link py-2" href="<?= e(base_url('index.php')) ?>"><i class="bi bi-house-door me-1 text-teal"></i>Home</a>
        </li>
        <li class="nav-item">
          <a class="nav-link py-2 <?= $regOpen ? '' : 'disabled' ?>" href="<?= e(base_url('register.php')) ?>"><i class="bi bi-person-plus me-1 text-amber"></i>Register</a>
        </li>
        <li class="nav-item">
          <a class="nav-link py-2" href="<?= e(base_url('status.php')) ?>"><i class="bi bi-search me-1 text-teal"></i>Check Status</a>
        </li>
        <li class="nav-item">
          <a class="nav-link py-2" href="<?= e(base_url('pass.php')) ?>"><i class="bi bi-ticket-perforated me-1 text-teal"></i>View Pass</a>
        </li>
        <li class="nav-item dropdown my-2 my-lg-0 ms-lg-2">
          <a class="btn btn-outline-teal btn-sm dropdown-toggle fw-bold px-3 py-2 w-100 w-lg-auto d-inline-flex align-items-center justify-content-center gap-1" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-shield-lock"></i> Admin &amp; Candidate Login
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-2 text-start" style="border-radius: 1rem; min-width: 250px;">
            <li><h6 class="dropdown-header text-uppercase text-muted-2 small fw-bold" style="font-size: .68rem;">Staff &amp; Admin Access</h6></li>
            <li>
              <a class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center gap-2" href="<?= e(base_url('admin/login.php')) ?>">
                <span class="icon-blob bg-dark text-white rounded-2 p-1" style="width:30px;height:30px;font-size:.95rem;"><i class="bi bi-shield-lock"></i></span>
                <div>
                  <strong class="d-block" style="font-size:.9rem;">Admin Login</strong>
                  <small class="text-muted" style="font-size:.72rem;">Management &amp; Approvals</small>
                </div>
              </a>
            </li>
            <li>
              <a class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center gap-2" href="<?= e(base_url('scanner/login.php')) ?>">
                <span class="icon-blob bg-success text-white rounded-2 p-1" style="width:30px;height:30px;font-size:.95rem;"><i class="bi bi-qr-code-scan"></i></span>
                <div>
                  <strong class="d-block" style="font-size:.9rem;">Volunteer / Scanner</strong>
                  <small class="text-muted" style="font-size:.72rem;">Gate Pass Verification</small>
                </div>
              </a>
            </li>
            <li><hr class="dropdown-divider my-2"></li>
            <li>
              <a class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center gap-2" href="<?= e(base_url('status.php')) ?>">
                <span class="icon-blob bg-teal text-white rounded-2 p-1" style="width:30px;height:30px;font-size:.95rem;"><i class="bi bi-mortarboard"></i></span>
                <div>
                  <strong class="d-block" style="font-size:.9rem;">Student Portal</strong>
                  <small class="text-muted" style="font-size:.72rem;">PNR Login &amp; Pass Check</small>
                </div>
              </a>
            </li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<?php endif; ?>

<main class="flex-grow-1 d-flex flex-column">
<div class="container py-4 py-md-5">
<?php render_flashes(); ?>
