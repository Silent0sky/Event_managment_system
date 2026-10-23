<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Public site footer.
 */
$settings = get_settings();
?>
</div><!-- /container -->
</main>

<footer class="site-footer mt-auto">
  <div class="container py-4">
    <div class="row g-4 align-items-center">
      <div class="col-md-6 text-center text-md-start">
        <div class="d-flex align-items-center justify-content-center justify-content-md-start gap-2 mb-1">
          <img src="<?= e(base_url($settings['event_logo'] ?: 'assets/images/event-logo.png')) ?>" alt="" width="28" height="28" class="rounded-2">
          <strong><?= e($settings['event_name']) ?></strong>
        </div>
        <div class="small text-secondary">
          <?= e($settings['college_name']) ?><?= $settings['event_date'] ? ' · ' . e(date_short((string) $settings['event_date'])) : '' ?>
        </div>
      </div>
      <div class="col-md-6">
        <div class="d-flex flex-wrap gap-3 justify-content-center justify-content-md-end small text-secondary">
          <?php if ($settings['contact_number']): ?>
            <span><i class="bi bi-telephone me-1"></i><a class="footer-link" href="tel:<?= e($settings['contact_number']) ?>"><?= e($settings['contact_number']) ?></a></span>
          <?php endif; ?>
          <?php if ($settings['contact_email']): ?>
            <span><i class="bi bi-envelope me-1"></i><a class="footer-link" href="mailto:<?= e($settings['contact_email']) ?>"><?= e($settings['contact_email']) ?></a></span>
          <?php endif; ?>
          <span><i class="bi bi-shield-check me-1"></i>Secured registration system</span>
        </div>
      </div>
    </div>
  </div>
</footer>

<!-- =========================================================
     ADMIN & CANDIDATE / VOLUNTEER LOGIN MODAL (MOBILE FRIENDLY)
     ========================================================= -->
<div class="modal fade" id="portalModal" tabindex="-1" aria-labelledby="portalModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
      <div class="modal-header border-0 pb-0 pt-4 px-4">
        <div>
          <span class="badge bg-teal-soft text-teal px-2 py-1 rounded-pill small fw-bold text-uppercase" style="font-size: .7rem;">Select Portal</span>
          <h5 class="modal-title fw-bold mt-1" id="portalModalLabel">Admin &amp; Candidate Login</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <p class="text-muted-2 small mb-3">Tap to access your dedicated management or scanning portal:</p>
        
        <div class="d-grid gap-3">
          <!-- 1. Admin Portal Link -->
          <a href="<?= e(base_url('admin/login.php')) ?>" class="d-flex align-items-center gap-3 p-3 rounded-3 border text-decoration-none bg-white shadow-xs text-dark hover-elevate">
            <span class="icon-blob bg-dark text-white rounded-3 p-2 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:46px;height:46px;font-size:1.3rem;">
              <i class="bi bi-shield-lock-fill"></i>
            </span>
            <div class="flex-grow-1 min-width-0">
              <div class="d-flex align-items-center justify-content-between">
                <strong class="d-block text-dark" style="font-size: 1rem;">Admin Console</strong>
                <span class="badge bg-dark text-white small">Admin</span>
              </div>
              <small class="text-muted text-truncate d-block" style="font-size:.78rem;">Verify payments, exports &amp; event control</small>
            </div>
            <i class="bi bi-chevron-right text-muted ms-auto"></i>
          </a>

          <!-- 2. Candidate / Volunteer Scanner Link -->
          <a href="<?= e(base_url('scanner/login.php')) ?>" class="d-flex align-items-center gap-3 p-3 rounded-3 border text-decoration-none bg-white shadow-xs text-dark hover-elevate">
            <span class="icon-blob bg-success text-white rounded-3 p-2 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:46px;height:46px;font-size:1.3rem;">
              <i class="bi bi-qr-code-scan"></i>
            </span>
            <div class="flex-grow-1 min-width-0">
              <div class="d-flex align-items-center justify-content-between">
                <strong class="d-block text-dark" style="font-size: 1rem;">Volunteer / Candidate</strong>
                <span class="badge bg-success text-white small">Gate Entry</span>
              </div>
              <small class="text-muted text-truncate d-block" style="font-size:.78rem;">Live gate QR pass scanning &amp; entry</small>
            </div>
            <i class="bi bi-chevron-right text-muted ms-auto"></i>
          </a>

          <!-- 3. Student Portal Link -->
          <a href="<?= e(base_url('status.php')) ?>" class="d-flex align-items-center gap-3 p-3 rounded-3 border text-decoration-none bg-white shadow-xs text-dark hover-elevate">
            <span class="icon-blob bg-teal-soft text-teal rounded-3 p-2 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:46px;height:46px;font-size:1.3rem;">
              <i class="bi bi-mortarboard-fill"></i>
            </span>
            <div class="flex-grow-1 min-width-0">
              <div class="d-flex align-items-center justify-content-between">
                <strong class="d-block text-dark" style="font-size: 1rem;">Student Portal</strong>
                <span class="badge bg-teal text-white small">Student</span>
              </div>
              <small class="text-muted text-truncate d-block" style="font-size:.78rem;">PNR status check &amp; digital pass</small>
            </div>
            <i class="bi bi-chevron-right text-muted ms-auto"></i>
          </a>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 pb-4 px-4 justify-content-center">
        <small class="text-muted text-center" style="font-size:.78rem;">Fresher Party 2026 · Secure Portal System</small>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(base_url('assets/js/app.js')) ?>"></script>
</body>
</html>
