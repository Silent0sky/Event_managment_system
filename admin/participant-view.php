<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Participant profile (view) with payments, entry history
 * and permission-gated quick actions (all mutations POST to admin/actions.php).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = require_permission('participant_view');
$activeNav = 'participants';
$pageTitle = 'Participant Profile';

$stId    = (int) ($_GET['id'] ?? 0);
$student = $stId > 0 ? student_by_id($stId) : null;

include __DIR__ . '/../includes/admin-header.php';

if (!$student): ?>
  <div class="card card-shadow mx-auto text-center" style="max-width:520px">
    <div class="card-body p-5">
      <div class="empty-state">
        <i class="bi bi-person-x"></i>
        <h1 class="h4 fw-bold mt-2 mb-2">Participant not found</h1>
        <p class="text-muted-2 mb-4">
          No participant exists for this record — search by <strong>PNR number</strong> from the Participants page.
        </p>
        <a class="btn btn-teal btn-lg" href="<?= e(base_url('admin/registrations.php')) ?>">
          <i class="bi bi-arrow-left me-2"></i>Back to Participants
        </a>
      </div>
    </div>
  </div>
<?php
else:
    $sid    = (int) $student['id'];
    $stPnr  = (string) ($student['pnr_number'] ?? '');
    $back   = 'admin/participant-view.php?id=' . $sid;
    $passLink = ($stPnr !== '' ? 'pass.php?pnr=' . urlencode($stPnr) : 'pass.php?t=' . urlencode((string) $student['qr_token'])) . '&admin_preview=1';

    $entered      = (string) $student['entry_status'] === 'ENTERED';
    $passViewable = in_array((string) $student['payment_status'], ['APPROVED', 'NOT_REQUIRED'], true);

    /* ---- payments (newest first) ---- */
    $st = db()->prepare(
        'SELECT p.*, a.name AS verifier_name
         FROM payments p
         LEFT JOIN admins a ON a.id = p.verified_by
         WHERE p.student_id = ?
         ORDER BY p.id DESC'
    );
    $st->execute([$sid]);
    $payments = $st->fetchAll() ?: [];

    $latestPayment  = $payments[0] ?? null;
    $pendingPayment = null;
    foreach ($payments as $p) {
        if ((string) $p['status'] === 'PENDING') {
            $pendingPayment = $p;
            break;
        }
    }
    $refundablePayment = ($latestPayment && (string) $latestPayment['status'] === 'APPROVED') ? $latestPayment : null;

    /* ---- entry history (last 15) ---- */
    $st = db()->prepare(
        'SELECT el.scan_time, el.result, el.gate_name, el.entry_method, el.reason,
                v.name AS volunteer_name, ad.name AS admin_name
         FROM entry_logs el
         LEFT JOIN volunteers v ON v.id = el.volunteer_id
         LEFT JOIN admins ad ON ad.id = el.admin_id
         WHERE el.student_id = ?
         ORDER BY el.id DESC
         LIMIT 15'
    );
    $st->execute([$sid]);
    $entryLogs = $st->fetchAll() ?: [];

    $firstEntry = first_entry_info($sid);

    $canVerify = admin_can('payment_verify');
    $canEntry  = admin_can('manual_entry');
    $canPass   = admin_can('passes');
    $canEdit   = admin_can('participant_edit');
    $canDelete = admin_can('participant_delete');

    /* Quick action form helper (kept tiny to avoid repetition) */
    $actionForm = static function (string $action, array $fields, string $btnClass, string $icon, string $label, string $formAttrs = '') use ($back): void {
        echo '<form method="post" action="' . e(base_url('admin/actions.php')) . '" class="d-inline"' . ($formAttrs !== '' ? ' ' . $formAttrs : '') . '>';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="' . e($action) . '">';
        foreach ($fields as $name => $value) {
            echo '<input type="hidden" name="' . e($name) . '" value="' . e((string) $value) . '">';
        }
        echo '<input type="hidden" name="back" value="' . e($back) . '">';
        echo '<input type="hidden" name="reason" value="">';
        echo '<button class="btn btn-sm ' . e($btnClass) . '" type="submit"><i class="bi ' . e($icon) . '"></i> ' . e($label) . '</button>';
        echo '</form>';
    };
?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('admin/registrations.php')) ?>">
    <i class="bi bi-arrow-left me-1"></i>All Participants
  </a>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($passViewable): ?>
      <a class="btn btn-outline-teal btn-sm" href="<?= e(base_url($passLink)) ?>" target="_blank" rel="noopener">
        <i class="bi bi-ticket-perforated me-1"></i>View Pass
      </a>
    <?php endif; ?>
    <?php if ($canEdit): ?>
      <a class="btn btn-teal btn-sm" href="<?= e(base_url('admin/participant-edit.php?id=' . $sid)) ?>">
        <i class="bi bi-pencil-square me-1"></i>Edit
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-8">

    <!-- ============ PROFILE ============ -->
    <div class="card card-shadow mb-3">
      <div class="card-header bg-white">
        <h2 class="h6 card-header-title mb-0"><i class="bi bi-person-vcard me-1 text-teal"></i>PROFILE</h2>
      </div>
      <div class="card-body p-4">
        <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
          <?php if (!empty($student['photo'])): ?>
            <img src="<?= e(base_url((string) $student['photo'])) ?>" alt="Photo of <?= e((string) $student['name']) ?>"
                 class="rounded-3 shadow-sm" style="width:140px;height:140px;object-fit:cover">
          <?php else: ?>
            <div class="pass-photo-initial" style="width:140px;height:140px;font-size:2.6rem"><?= e(mb_strtoupper(mb_substr((string) $student['name'], 0, 1))) ?></div>
          <?php endif; ?>
          <div class="min-width-0">
            <h1 class="h3 fw-bold mb-2"><?= e((string) $student['name']) ?></h1>
            <div class="d-flex flex-wrap gap-2">
              <?= status_badge((string) $student['pass_type']) ?>
              <?= status_badge((string) $student['payment_status']) ?>
              <?= status_badge((string) $student['pass_status']) ?>
              <?= status_badge((string) $student['entry_status']) ?>
            </div>
          </div>
        </div>
        <hr>
        <div class="row g-3 small">
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">PNR Number</div><span class="font-monospace fw-bold"><?= e($stPnr !== '' ? $stPnr : '—') ?></span></div>
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">Pass ID</div><span class="font-monospace fw-bold"><?= e((string) $student['pass_id']) ?></span></div>
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">Course</div><strong><?= e((string) $student['course']) ?></strong></div>
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">Year</div><strong><?= e(years_list()[(string) $student['year']] ?? (string) $student['year']) ?></strong></div>
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">Division</div><strong><?= e((string) ($student['division'] ?: '—')) ?></strong></div>
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">Mobile</div><strong><?= e((string) ($student['mobile'] ?: '—')) ?></strong></div>
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">Email</div><strong class="text-break"><?= e((string) ($student['email'] ?: '—')) ?></strong></div>
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">Required Amount</div><strong><?= e(money($student['required_amount'])) ?></strong></div>
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">Paid Amount</div><strong><?= e(money($student['paid_amount'])) ?></strong></div>
          <div class="col-6 col-md-4 col-xl-3"><div class="text-muted-2">Pass Security</div><strong><?= !empty($student['pass_pin']) ? '<span class="text-success"><i class="bi bi-shield-lock-fill me-1"></i>PIN Set</span>' : '<span class="text-muted"><i class="bi bi-shield me-1"></i>Mobile Fallback</span>' ?></strong></div>
          <div class="col-12 col-md-4 col-xl-3"><div class="text-muted-2">Registered On</div><strong><?= e(dt_short((string) $student['created_at'])) ?></strong></div>
        </div>
      </div>
    </div>

    <!-- ============ PAYMENTS ============ -->
    <div class="card card-shadow mb-3">
      <div class="card-header bg-white">
        <h2 class="h6 card-header-title mb-0"><i class="bi bi-cash-stack me-1 text-teal"></i>PAYMENT</h2>
      </div>
      <div class="card-body p-4">
        <?php if (!$payments): ?>
          <div class="empty-state">
            <i class="bi bi-receipt"></i>
            <p class="mb-0 mt-2">
              <?php if ((string) $student['payment_status'] === 'NOT_REQUIRED'): ?>
                No payment needed for this pass type — no records exist.
              <?php else: ?>
                No payment submitted yet.
              <?php endif; ?>
            </p>
          </div>
        <?php else: ?>
          <?php foreach ($payments as $i => $p): ?>
            <div class="border rounded-3 p-3 mb-2 <?= $i === 0 ? 'border-2' : '' ?>">
              <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
                <div class="min-width-0">
                  <?= status_badge((string) $p['status']) ?>
                  <?php if ($i === 0): ?><span class="badge rounded-pill text-bg-stone fw-semibold">LATEST</span><?php endif; ?>
                  <div class="mt-2">
                    <strong class="fs-5"><?= e(money($p['amount'])) ?></strong>
                    <span class="text-muted-2 small ms-2">UTR <span class="font-monospace"><?= e((string) $p['utr_number']) ?></span></span>
                  </div>
                </div>
                <div class="small text-muted-2 text-md-end">
                  <div><i class="bi bi-calendar3 me-1"></i>Payment date: <?= e(date_short($p['payment_date'] ? (string) $p['payment_date'] : null)) ?></div>
                  <div><i class="bi bi-upload me-1"></i>Submitted: <?= e(dt_short((string) $p['created_at'])) ?></div>
                  <div>
                    <i class="bi bi-person-check me-1"></i>Verified by:
                    <?= $p['verified_by'] ? e((string) ($p['verifier_name'] ?: ('Admin #' . (int) $p['verified_by']))) : '—' ?>
                    <?= $p['verified_at'] ? ' · ' . e(dt_short((string) $p['verified_at'])) : '' ?>
                  </div>
                </div>
              </div>
              <?php if (!empty($p['screenshot'])): ?>
                <div class="mt-2">
                  <a href="<?= e(base_url((string) $p['screenshot'])) ?>" target="_blank" rel="noopener" title="Open payment screenshot">
                    <img src="<?= e(base_url((string) $p['screenshot'])) ?>" alt="Payment screenshot for <?= e($stPnr !== '' ? $stPnr : (string) $student['pass_id']) ?>"
                         class="rounded-3 border" style="width:90px;height:90px;object-fit:cover" loading="lazy">
                  </a>
                  <span class="small text-muted-2 ms-2">Tap to open full screenshot</span>
                </div>
              <?php endif; ?>
              <?php if (!empty($p['rejection_reason'])): ?>
                <div class="alert alert-warning small mt-2 mb-0">
                  <strong><i class="bi bi-exclamation-triangle me-1"></i>Reason:</strong> <?= e((string) $p['rejection_reason']) ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ============ ENTRY ============ -->
    <div class="card card-shadow">
      <div class="card-header bg-white">
        <h2 class="h6 card-header-title mb-0"><i class="bi bi-door-open me-1 text-teal"></i>ENTRY</h2>
      </div>
      <div class="card-body p-4">
        <div class="row g-3 small mb-3">
          <div class="col-6 col-md-3"><div class="text-muted-2">Entry Status</div><?= status_badge((string) $student['entry_status']) ?></div>
          <div class="col-6 col-md-3"><div class="text-muted-2">Entry Time</div><strong><?= e(dt_short($student['entry_time'] ? (string) $student['entry_time'] : null)) ?></strong></div>
          <div class="col-6 col-md-3"><div class="text-muted-2">Entry Method</div><strong><?= e((string) ($student['entry_method'] ?: '—')) ?></strong></div>
          <div class="col-6 col-md-3"><div class="text-muted-2">Pass Status</div><?= status_badge((string) $student['pass_status']) ?></div>
        </div>

        <?php if ($firstEntry): ?>
          <div class="status-line sl-ok mb-3">
            <span class="sl-icon"><i class="bi bi-flag"></i></span>
            <div class="flex-grow-1">
              <strong>FIRST ENTRY</strong>
              <div class="small text-muted-2">
                <?= e(dt_short((string) $firstEntry['time'])) ?> · Gate: <?= e((string) $firstEntry['gate']) ?>
                · <?= e((string) $firstEntry['method']) ?> · by <?= e((string) $firstEntry['by']) ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <h3 class="h6 fw-bold">Scan / Entry History <span class="text-muted-2 fw-normal small">(latest 15)</span></h3>
        <?php if (!$entryLogs): ?>
          <div class="empty-state"><i class="bi bi-activity"></i><p class="mb-0 mt-2">No scans or entry attempts recorded yet.</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-mobile align-middle mb-0">
            <thead>
              <tr>
                <th scope="col">Time</th>
                <th scope="col">Result</th>
                <th scope="col">Gate</th>
                <th scope="col">Method</th>
                <th scope="col">Reason / Note</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($entryLogs as $log): ?>
              <tr>
                <td data-label="Time" class="small text-muted-2 text-nowrap"><?= e(dt_short((string) $log['scan_time'])) ?></td>
                <td data-label="Result"><?= status_badge((string) $log['result']) ?></td>
                <td data-label="Gate" class="small"><?= e((string) ($log['gate_name'] ?: '—')) ?></td>
                <td data-label="Method" class="small"><?= e((string) ($log['entry_method'] ?: '—')) ?></td>
                <td data-label="Reason" class="small text-muted-2"><?= e((string) ($log['reason'] ?: '—')) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ============ ACTIONS ============ -->
  <div class="col-12 col-xl-4">
    <div class="card card-shadow">
      <div class="card-header bg-white">
        <h2 class="h6 card-header-title mb-0"><i class="bi bi-lightning-charge me-1 text-teal"></i>ACTIONS</h2>
      </div>
      <div class="card-body p-3">

        <?php if ($canVerify && (string) $student['pass_type'] === 'PAID' && (string) $student['payment_status'] !== 'APPROVED'): ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-1"><i class="bi bi-check2-circle me-1 text-success"></i>Approve Payment</div>
          <p class="small text-muted-2 mb-2">Marks this participant as paid (current status: <?= e(str_replace('_', ' ', (string) $student['payment_status'])) ?>).</p>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" data-confirm="Approve payment for <?= e((string) $student['name']) ?>?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_payment">
            <input type="hidden" name="student_id" value="<?= $sid ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <input class="form-control form-control-sm mb-2" type="text" name="reason" maxlength="255" placeholder="Reason (optional)">
            <button class="btn btn-sm btn-teal btn-block" type="submit"><i class="bi bi-check-lg me-1"></i>APPROVE PAYMENT</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if ($canVerify && $pendingPayment): ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-1"><i class="bi bi-x-circle me-1 text-danger"></i>Reject Payment</div>
          <p class="small text-muted-2 mb-2">Rejects payment #<?= (int) $pendingPayment['id'] ?> (<?= e(money($pendingPayment['amount'])) ?>, UTR <?= e((string) $pendingPayment['utr_number']) ?>).</p>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" data-confirm="Reject this payment? The student will be able to submit it again.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject_payment">
            <input type="hidden" name="payment_id" value="<?= (int) $pendingPayment['id'] ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <textarea class="form-control form-control-sm mb-2" name="reason" required maxlength="255"
                      placeholder="Rejection reason (required)"></textarea>
            <button class="btn btn-sm btn-outline-danger btn-block" type="submit"><i class="bi bi-x-lg me-1"></i>REJECT PAYMENT</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if ($canVerify && $refundablePayment): ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-1"><i class="bi bi-cash-coin me-1 text-warning"></i>Refund Payment</div>
          <p class="small text-muted-2 mb-2">Marks approved payment #<?= (int) $refundablePayment['id'] ?> (<?= e(money($refundablePayment['amount'])) ?>) as refunded.</p>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>" data-confirm="Mark this payment as REFUNDED? The pass will stop working.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="refund_payment">
            <input type="hidden" name="payment_id" value="<?= (int) $refundablePayment['id'] ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <textarea class="form-control form-control-sm mb-2" name="reason" required maxlength="255"
                      placeholder="Refund reason (required)"></textarea>
            <button class="btn btn-sm btn-outline-warning btn-block" type="submit"><i class="bi bi-arrow-return-left me-1"></i>REFUND PAYMENT</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if ($canEntry && !$entered): ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-1"><i class="bi bi-door-open me-1 text-teal"></i>Mark Entry</div>
          <p class="small text-muted-2 mb-2">Manually marks <?= e((string) $student['name']) ?> as ENTERED (bypasses QR scan).</p>
          <?php $actionForm('mark_entry', ['student_id' => $sid], 'btn-teal btn-block', 'bi-door-open', 'MARK ENTRY', 'data-reason-prompt="Reason for manual entry:" data-reason-required="1"'); ?>
        </div>
        <?php endif; ?>

        <?php if ($canEntry && $entered): ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-1"><i class="bi bi-arrow-counterclockwise me-1 text-warning"></i>Reset Entry</div>
          <p class="small text-muted-2 mb-2">Allows the pass to be scanned again. Current entry is kept in history.</p>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>"
                data-reason-prompt="Reason for resetting entry (required):" data-reason-required="1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_entry">
            <input type="hidden" name="student_id" value="<?= $sid ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <input type="hidden" name="reason" value="">
            <button class="btn btn-sm btn-outline-warning btn-block" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i>RESET ENTRY</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if ($canPass && (string) $student['pass_status'] === 'ACTIVE'): ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-1"><i class="bi bi-slash-circle me-1 text-danger"></i>Disable Pass</div>
          <p class="small text-muted-2 mb-2">Temporarily blocks this pass at all gates (can be re-activated later).</p>
          <?php $actionForm('disable_pass', ['student_id' => $sid], 'btn-outline-danger btn-block', 'bi-slash-circle', 'DISABLE PASS', 'data-reason-prompt="Reason for disabling pass (optional):"'); ?>
        </div>
        <?php endif; ?>

        <?php if ($canPass && (string) $student['pass_status'] === 'DISABLED'): ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-1"><i class="bi bi-check2-circle me-1 text-success"></i>Activate Pass</div>
          <p class="small text-muted-2 mb-2">Re-enables this currently disabled pass.</p>
          <?php $actionForm('activate_pass', ['student_id' => $sid], 'btn-outline-teal btn-block', 'bi-check2-circle', 'ACTIVATE PASS', 'data-reason-prompt="Reason for activating pass (optional):"'); ?>
        </div>
        <?php endif; ?>

        <?php if ($canPass && (string) $student['pass_status'] !== 'CANCELLED'): ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-1"><i class="bi bi-x-octagon me-1 text-danger"></i>Cancel Pass</div>
          <p class="small text-muted-2 mb-2">Permanently cancels this pass — the QR code will stop working at every gate.</p>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>"
                data-confirm="Cancel this pass permanently?" data-reason-prompt="Reason for cancelling the pass (optional):">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_pass">
            <input type="hidden" name="student_id" value="<?= $sid ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <input type="hidden" name="reason" value="">
            <button class="btn btn-sm btn-outline-danger btn-block" type="submit"><i class="bi bi-x-octagon me-1"></i>CANCEL PASS</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if ($canDelete): ?>
        <div class="border border-danger rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-1 text-danger"><i class="bi bi-trash3 me-1"></i>Delete Participant</div>
          <p class="small text-muted-2 mb-2">Permanently deletes this participant, their payments and entry history. A full snapshot is stored in the audit log — this cannot be undone.</p>
          <form method="post" action="<?= e(base_url('admin/actions.php')) ?>"
                data-confirm="PERMANENTLY DELETE <?= e((string) $student['name']) ?>? This removes the participant, payments and entry history. This action cannot be undone."
                data-reason-prompt="Reason for deleting this participant (required):" data-reason-required="1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_student">
            <input type="hidden" name="student_id" value="<?= $sid ?>">
            <input type="hidden" name="back" value="admin/registrations.php">
            <input type="hidden" name="reason" value="">
            <button class="btn btn-sm btn-danger btn-block" type="submit"><i class="bi bi-trash3 me-1"></i>DELETE PARTICIPANT</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if (!$canVerify && !$canEntry && !$canPass && !$canEdit && !$canDelete): ?>
          <div class="empty-state"><i class="bi bi-shield-lock"></i><p class="mb-0 mt-2">Your role has view-only access to participants.</p></div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
