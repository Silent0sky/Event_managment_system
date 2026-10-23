<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin · Single payment detail / verification page.
 * ?id=PAYMENT_ID · perm: payments (verify actions additionally need payment_verify).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('payments');

$rawId = $_GET['id'] ?? '';
$id    = (is_string($rawId) && is_numeric($rawId)) ? (int) $rawId : 0;

$payment = null;
$student = null;
if ($id > 0) {
    $stmt = db()->prepare(
        'SELECT p.*, a.name AS verifier_name
         FROM payments p
         LEFT JOIN admins a ON a.id = p.verified_by
         WHERE p.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $payment = $stmt->fetch() ?: null;
    if ($payment) {
        $student = student_by_id((int) $payment['student_id']);
    }
}

$canVerify = admin_can('payment_verify');
$backToList = 'admin/payments.php' . ($payment ? '?status=' . urlencode((string) $payment['status']) : '');
$thisBack   = 'admin/payment-view.php?id=' . $id;

/* Audit trail: payment-level + student-level payment actions */
$audits = [];
if ($payment) {
    $stmt = db()->prepare(
        "SELECT al.created_at, al.user_type, al.action, al.reason, al.target_type, al.target_id,
                a.name AS admin_name, v.name AS volunteer_name
         FROM audit_logs al
         LEFT JOIN admins a ON al.user_type = 'ADMIN' AND al.user_id = a.id
         LEFT JOIN volunteers v ON al.user_type = 'VOLUNTEER' AND al.user_id = v.id
         WHERE (al.target_type = 'PAYMENT' AND al.target_id = ?)
            OR (al.target_type = 'STUDENT' AND al.target_id = ? AND al.action LIKE '%PAYMENT%')
         ORDER BY al.id DESC
         LIMIT 20"
    );
    $stmt->execute([(string) $id, $student ? (string) ($student['pnr_number'] ?? '') : '']);
    $audits = $stmt->fetchAll();
}

$activeNav = 'payments';
$pageTitle = 'Payment Details';
include __DIR__ . '/../includes/admin-header.php';
?>

<?php if (!$payment): ?>
  <div class="card card-shadow">
    <div class="card-body p-4 p-md-5 text-center">
      <div class="empty-state">
        <i class="bi bi-exclamation-triangle"></i>
        <p class="mt-2 mb-3">Payment not found<?= $id > 0 ? ' (ID ' . (int) $id . ')' : '' ?>.</p>
        <a class="btn btn-outline-teal" href="<?= e(base_url('admin/payments.php')) ?>"><i class="bi bi-arrow-left me-1"></i>Back to payments</a>
      </div>
    </div>
  </div>
<?php else:
    $pStatus = (string) $payment['status'];
    $stName  = $student ? (string) $student['name'] : '';
?>
<a class="btn btn-sm btn-outline-secondary mb-3" href="<?= e(base_url($backToList)) ?>">
  <i class="bi bi-arrow-left me-1"></i>Back to <?= e(strtolower($pStatus)) ?> payments
</a>

<div class="row g-4">
  <div class="col-12 col-lg-7">
    <!-- Payment details -->
    <div class="card card-shadow mb-4">
      <div class="card-body p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <h2 class="h5 fw-bold mb-0"><i class="bi bi-cash-stack me-2 text-teal"></i>Payment #<?= (int) $payment['id'] ?></h2>
          <?= status_badge($pStatus) ?>
        </div>
        <div class="mb-3">
          <div class="small text-muted-2 text-uppercase fw-semibold">Amount</div>
          <div class="display-5 fw-bold text-teal"><?= e(money($payment['amount'])) ?></div>
        </div>
        <div class="border-top pt-2">
          <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
            <span class="text-muted-2 small">UTR / Reference</span>
            <span class="font-monospace fw-bold text-break text-end"><?= e((string) $payment['utr_number']) ?></span>
          </div>
          <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
            <span class="text-muted-2 small">Payment date</span>
            <span><?= e(date_short((string) $payment['payment_date'])) ?></span>
          </div>
          <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
            <span class="text-muted-2 small">Submitted at</span>
            <span><?= e(dt_short((string) $payment['created_at'])) ?></span>
          </div>
          <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
            <span class="text-muted-2 small">Verified by</span>
            <span><?= e((string) ($payment['verifier_name'] ?? '—') ?: '—') ?></span>
          </div>
          <div class="d-flex justify-content-between gap-3 py-2">
            <span class="text-muted-2 small">Verified at</span>
            <span><?= e(dt_short($payment['verified_at'] !== null ? (string) $payment['verified_at'] : null)) ?></span>
          </div>
        </div>
        <?php if (!empty($payment['rejection_reason'])): ?>
          <div class="alert alert-warning mt-3 mb-0">
            <strong><i class="bi bi-exclamation-triangle me-1"></i>Rejection reason:</strong> <?= e((string) $payment['rejection_reason']) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Audit trail -->
    <div class="card card-shadow">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-journal-text me-2 text-teal"></i>Audit trail</h2>
        <?php if (!$audits): ?>
          <div class="empty-state"><i class="bi bi-inbox"></i><p class="mt-2 mb-0 small">No audit entries yet.</p></div>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($audits as $a):
                $actor = (string) ($a['admin_name'] ?? ($a['volunteer_name'] ?? ''));
                if ($actor === '' && $a['user_type'] === 'STUDENT') { $actor = 'Student'; }
                if ($actor === '' && $a['user_type'] === 'SYSTEM') { $actor = 'System'; }
            ?>
            <li class="list-group-item px-0 d-flex flex-wrap gap-2 align-items-start">
              <span class="small text-muted-2 text-nowrap" style="min-width:150px"><?= e(dt_short((string) $a['created_at'])) ?></span>
              <span class="badge text-bg-stone font-monospace fw-normal align-self-center" style="font-size:.7rem"><?= e((string) $a['action']) ?></span>
              <span class="small align-self-center">
                <?= e($actor !== '' ? $actor : (string) $a['user_type']) ?>
                <?php if ((string) $a['target_type'] === 'STUDENT'): ?>
                  · student <span class="font-monospace"><?= e((string) $a['target_id']) ?></span>
                <?php elseif ((string) $a['target_type'] === 'PAYMENT' && (string) $a['target_id'] !== (string) $payment['id']): ?>
                  · payment #<?= e((string) $a['target_id']) ?>
                <?php endif; ?>
              </span>
              <?php if ((string) $a['reason'] !== ''): ?>
                <span class="w-100 small text-muted-2 ps-4"><i class="bi bi-chat-left-text me-1"></i><?= e((string) $a['reason']) ?></span>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <!-- Screenshot -->
    <div class="card card-shadow mb-4">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-image me-2 text-teal"></i>Payment screenshot</h2>
        <?php if (!empty($payment['screenshot'])): ?>
          <div class="text-center">
            <a href="<?= e(base_url((string) $payment['screenshot'])) ?>" target="_blank" rel="noopener" class="d-inline-block">
              <img src="<?= e(base_url((string) $payment['screenshot'])) ?>" alt="Payment screenshot submitted<?= $stName !== '' ? ' by ' . e($stName) : '' ?>"
                   class="rounded-3 shadow-sm" style="max-width:380px;width:100%;height:auto">
            </a>
            <div class="form-text mt-2"><i class="bi bi-box-arrow-up-right me-1"></i>Tap the image to open the full-size screenshot.</div>
          </div>
        <?php else: ?>
          <div class="alert alert-secondary mb-0"><i class="bi bi-image me-2"></i>No screenshot attached (manually recorded payment).</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($student): ?>
    <!-- Student summary -->
    <div class="card card-shadow mb-4">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-person me-2 text-teal"></i>Participant</h2>
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <?php if (!empty($student['photo'])): ?>
            <img class="rounded-3" src="<?= e(base_url((string) $student['photo'])) ?>" alt="Photo of <?= e((string) $student['name']) ?>"
                 width="64" height="64" style="object-fit:cover">
          <?php else: ?>
            <span class="avatar-thumb" style="width:64px;height:64px;font-size:1.4rem"><?= e(mb_strtoupper(mb_substr((string) $student['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
          <?php endif; ?>
          <div class="min-width-0">
            <div class="fw-bold text-truncate" style="max-width:220px"><?= e((string) $student['name']) ?></div>
            <div class="small font-monospace"><?= e((string) ($student['pnr_number'] ?: '— no PNR')) ?> · <?= e((string) $student['pass_id']) ?></div>
            <div class="small text-muted-2"><?= e((string) $student['course']) ?> · <?= e(years_list()[(string) $student['year']] ?? (string) $student['year']) ?></div>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <?= status_badge((string) $student['pass_type']) ?>
          <?= status_badge((string) $student['payment_status']) ?>
          <?= status_badge((string) $student['pass_status']) ?>
          <?= status_badge((string) $student['entry_status']) ?>
        </div>
        <a class="btn btn-outline-teal btn-sm mt-3" href="<?= e(base_url('admin/participant-view.php?id=' . (int) $student['id'])) ?>">
          <i class="bi bi-box-arrow-up-right me-1"></i>Open participant profile
        </a>
      </div>
    </div>
    <?php else: ?>
    <div class="card card-shadow mb-4">
      <div class="card-body p-4">
        <div class="alert alert-warning mb-0"><i class="bi bi-person-x me-2"></i>The participant for this payment no longer exists (deleted).</div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($canVerify): ?>
    <!-- Verification actions -->
    <div class="card card-shadow">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-shield-check me-2 text-teal"></i>Verification actions</h2>

        <?php if ($pStatus === 'PENDING'): ?>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" class="mb-4"
                data-confirm="Approve this payment of <?= e(money($payment['amount'])) ?><?= $stName !== '' ? ' for ' . e($stName) : '' ?>? The pass becomes active immediately.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_payment">
            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
            <input type="hidden" name="back" value="<?= e($thisBack) ?>">
            <label class="form-label small fw-semibold" for="approveReason">Approve note (optional)</label>
            <textarea class="form-control mb-2" id="approveReason" name="reason" rows="2" maxlength="255"
                      placeholder="e.g. UTR matched bank statement"></textarea>
            <button type="submit" class="btn btn-teal btn-lg w-100"><i class="bi bi-check-lg me-2"></i>APPROVE PAYMENT</button>
          </form>
          <hr>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>"
                data-confirm="Reject this payment? The student will have to submit it again.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject_payment">
            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
            <input type="hidden" name="back" value="<?= e($thisBack) ?>">
            <label class="form-label small fw-semibold" for="rejectReasonPage">Rejection reason <span class="text-danger">*</span></label>
            <textarea class="form-control mb-2" id="rejectReasonPage" name="reason" rows="2" maxlength="255" required
                      placeholder="e.g. UTR not found in bank statement"></textarea>
            <button type="submit" class="btn btn-danger btn-lg w-100"><i class="bi bi-x-lg me-2"></i>REJECT PAYMENT</button>
          </form>

        <?php elseif ($pStatus === 'APPROVED'): ?>
          <div class="alert alert-success small">
            <i class="bi bi-check-circle me-1"></i>Verified by <strong><?= e((string) ($payment['verifier_name'] ?? '—') ?: '—') ?></strong>
            on <?= e(dt_short((string) ($payment['verified_at'] ?? ''))) ?>.
          </div>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>"
                data-confirm="Mark this payment as REFUNDED? The participant's pass will stop working.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="refund_payment">
            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
            <input type="hidden" name="back" value="<?= e($thisBack) ?>">
            <label class="form-label small fw-semibold" for="refundReason">Refund reason <span class="text-danger">*</span></label>
            <textarea class="form-control mb-2" id="refundReason" name="reason" rows="2" maxlength="255" required
                      placeholder="e.g. Duplicate payment refunded via UPI"></textarea>
            <button type="submit" class="btn btn-outline-danger btn-lg w-100"><i class="bi bi-arrow-counterclockwise me-2"></i>MARK REFUNDED</button>
          </form>

        <?php else: ?>
          <div class="alert alert-warning small mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>This payment is currently <strong><?= e(str_replace('_', ' ', $pStatus)) ?></strong>.
            <?= !empty($payment['rejection_reason']) ? 'Reason: ' . e((string) $payment['rejection_reason']) : '' ?>
          </div>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>"
                data-confirm="Re-approve this payment of <?= e(money($payment['amount'])) ?>?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_payment">
            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
            <input type="hidden" name="back" value="<?= e($thisBack) ?>">
            <label class="form-label small fw-semibold" for="approveReason2">Approve note (optional)</label>
            <textarea class="form-control mb-2" id="approveReason2" name="reason" rows="2" maxlength="255"
                      placeholder="e.g. Corrected UTR verified after re-check"></textarea>
            <button type="submit" class="btn btn-teal btn-lg w-100"><i class="bi bi-check-lg me-2"></i>APPROVE PAYMENT</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="card card-shadow">
      <div class="card-body p-4">
        <div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i>Your role can view payments but cannot verify them.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin-footer.php';
