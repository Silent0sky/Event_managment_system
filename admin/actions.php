<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Central admin mutation endpoint.
 * Every admin "quick action" (approve/reject payment, mark/reset entry, pass control,
 * delete student) POSTs here. Server-side permission + CSRF enforcement on every action.
 *
 * POST fields: action, student_id, payment_id, reason, back, csrf_token
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_to('admin/dashboard.php');
}
csrf_require();
$admin = require_admin();

$action    = (string) ($_POST['action'] ?? '');
$reason    = trim((string) ($_POST['reason'] ?? ''));
$studentId = (int) ($_POST['student_id'] ?? 0);
$paymentId = (int) ($_POST['payment_id'] ?? 0);

// Safe "back" redirect — only relative admin/ paths (no open redirect)
$back = (string) ($_POST['back'] ?? 'admin/dashboard.php');
if (!preg_match('~^admin/[A-Za-z0-9._\-]*(\?.*)?$~', $back) || str_contains($back, '..')) {
    $back = 'admin/dashboard.php';
}

$fail = function (string $m) use ($back): void {
    set_flash('danger', $m);
    redirect_to($back);
};
$done = function (string $m) use ($back): void {
    set_flash('success', $m);
    redirect_to($back);
};

$student = $studentId > 0 ? student_by_id($studentId) : null;

switch ($action) {

    /* ---------------- PAYMENTS ---------------- */

    case 'approve_payment': {
        // Approve payment with verification and audit_log
        if (!admin_can('payment_verify')) { $fail('Your role cannot verify payments.'); }
        $db = db();
        if ($paymentId > 0) {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
                $stmt->execute([$paymentId]);
                $pay = $stmt->fetch();
                if (!$pay) { $db->rollBack(); $fail('Payment record not found.'); }
                $target = student_by_id((int) $pay['student_id']);
                if (!$target) { $db->rollBack(); $fail('Student for this payment no longer exists.'); }
                if ($pay['status'] === 'APPROVED') { $db->rollBack(); $fail('Payment #' . $paymentId . ' is already approved.'); }
                $upd = $db->prepare("UPDATE payments SET status='APPROVED', verified_by=?, verified_at=?, updated_at=? WHERE id=?");
                $upd->execute([(int) $admin['id'], now_db(), now_db(), $paymentId]);
                $upd2 = $db->prepare("UPDATE students SET payment_status='APPROVED', paid_amount=? WHERE id=?");
                $upd2->execute([(float) $pay['amount'], (int) $target['id']]);
                $db->commit();
                audit_log('PAYMENT_APPROVED', 'PAYMENT', (string) $paymentId,
                    ['status' => $pay['status']],
                    ['status' => 'APPROVED', 'student' => $target['pnr_number'] ?? $target['pass_id'], 'amount' => $pay['amount']],
                    $reason !== '' ? $reason : null);
                $done('Payment approved for ' . $target['name'] . ' (PNR ' . ($target['pnr_number'] ?? $target['pass_id']) . '). Pass is now active.');
            } catch (Throwable $ex) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log('[FresherParty] approve_payment: ' . $ex->getMessage());
                $fail('Could not approve payment. Please try again.');
            }
        } elseif ($student) {
            // Manual payment override (no payment_id given)
            if ($student['payment_status'] === 'APPROVED') { $fail($student['name'] . ' already has an approved payment.'); }
            if ((string) $student['pass_type'] !== 'PAID') { $fail('Special passes do not require payment approval.'); }
            if ($reason === '') { $fail('A reason is required for manual payment approval.'); }
            try {
                $db->beginTransaction();
                // If a pending payment row exists, approve that one (keeps history consistent)
                $stmt = $db->prepare('SELECT * FROM payments WHERE student_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                $stmt->execute([(int) $student['id']]);
                $pay = $stmt->fetch();
                if ($pay && $pay['status'] === 'PENDING') {
                    $upd = $db->prepare("UPDATE payments SET status='APPROVED', verified_by=?, verified_at=?, updated_at=? WHERE id=?");
                    $upd->execute([(int) $admin['id'], now_db(), now_db(), (int) $pay['id']]);
                    $upd2 = $db->prepare("UPDATE students SET payment_status='APPROVED', paid_amount=? WHERE id=?");
                    $upd2->execute([(float) $pay['amount'], (int) $student['id']]);
                    $db->commit();
                    audit_log('PAYMENT_APPROVED', 'PAYMENT', (string) $pay['id'],
                        ['status' => 'PENDING'], ['status' => 'APPROVED', 'student' => $student['pnr_number'] ?? $student['pass_id']], $reason);
                    $done('Payment approved for ' . $student['name'] . ' (PNR ' . ($student['pnr_number'] ?? $student['pass_id']) . '). Pass is now active.');
                }
                if ($pay && $pay['status'] === 'APPROVED') { $db->rollBack(); $fail('Latest payment is already approved.'); }
                // No usable payment row → create an APPROVED manual record (e.g. cash at counter)
                $ins = $db->prepare("INSERT INTO payments (student_id, utr_number, amount, screenshot, payment_date, status, rejection_reason, verified_by, verified_at, created_at, updated_at)
                                     VALUES (?,?,?,NULL,?,'APPROVED',NULL,?,?,?,?)");
                $ins->execute([(int) $student['id'], 'MANUAL', (float) $student['required_amount'], date('Y-m-d'), (int) $admin['id'], now_db(), now_db(), now_db()]);
                $newPayId = (int) $db->lastInsertId();
                $upd = $db->prepare("UPDATE students SET payment_status='APPROVED', paid_amount=? WHERE id=?");
                $upd->execute([(float) $student['required_amount'], (int) $student['id']]);
                $db->commit();
                audit_log('MANUAL_PAYMENT_APPROVED', 'PAYMENT', (string) $newPayId,
                    ['payment_status' => $student['payment_status']],
                    ['payment_status' => 'APPROVED', 'amount' => (float) $student['required_amount']],
                    $reason);
                $done('Payment manually approved for ' . $student['name'] . ' (PNR ' . ($student['pnr_number'] ?? $student['pass_id']) . ').');
            } catch (Throwable $ex) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log('[FresherParty] manual approve: ' . $ex->getMessage());
                $fail('Could not approve payment manually. Please try again.');
            }
        } else {
            $fail('Invalid request.');
        }
        break;
    }

    case 'reject_payment': {
        // Reject payment with audit_log
        if (!admin_can('payment_verify')) { $fail('Your role cannot verify payments.'); }
        if ($reason === '') { $fail('A rejection reason is required.'); }
        if ($paymentId <= 0) { $fail('Invalid payment.'); }
        $db = db();
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $pay = $stmt->fetch();
            if (!$pay) { $db->rollBack(); $fail('Payment record not found.'); }
            $target = student_by_id((int) $pay['student_id']);
            if ($pay['status'] === 'APPROVED') { $db->rollBack(); $fail('An approved payment cannot be rejected — refund it instead.'); }
            $upd = $db->prepare("UPDATE payments SET status='REJECTED', rejection_reason=?, verified_by=?, verified_at=?, updated_at=? WHERE id=?");
            $upd->execute([$reason, (int) $admin['id'], now_db(), now_db(), $paymentId]);
            if ($target) {
                $upd2 = $db->prepare("UPDATE students SET payment_status='REJECTED' WHERE id=?");
                $upd2->execute([(int) $target['id']]);
            }
            $db->commit();
            audit_log('PAYMENT_REJECTED', 'PAYMENT', (string) $paymentId,
                ['status' => $pay['status']],
                ['status' => 'REJECTED', 'student' => $target['pnr_number'] ?? ($target['pass_id'] ?? '')],
                $reason);
            $done('Payment rejected' . ($target ? ' for ' . $target['name'] : '') . '. Reason recorded.');
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[FresherParty] reject_payment: ' . $ex->getMessage());
            $fail('Could not reject payment. Please try again.');
        }
        break;
    }

    case 'refund_payment': {
        if (!admin_can('payment_verify')) { $fail('Your role cannot manage payments.'); }
        if ($reason === '') { $fail('A refund reason is required.'); }
        if ($paymentId <= 0) { $fail('Invalid payment.'); }
        $db = db();
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $pay = $stmt->fetch();
            if (!$pay) { $db->rollBack(); $fail('Payment record not found.'); }
            if ($pay['status'] !== 'APPROVED') { $db->rollBack(); $fail('Only approved payments can be marked refunded.'); }
            $upd = $db->prepare("UPDATE payments SET status='REFUNDED', rejection_reason=?, verified_by=?, verified_at=?, updated_at=? WHERE id=?");
            $upd->execute([$reason, (int) $admin['id'], now_db(), now_db(), $paymentId]);
            $upd2 = $db->prepare("UPDATE students SET payment_status='REFUNDED' WHERE id=?");
            $upd2->execute([(int) $pay['student_id']]);
            $db->commit();
            audit_log('PAYMENT_REFUNDED', 'PAYMENT', (string) $paymentId,
                ['status' => 'APPROVED'], ['status' => 'REFUNDED'], $reason);
            $done('Payment marked as refunded.');
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[FresherParty] refund_payment: ' . $ex->getMessage());
            $fail('Could not mark refund. Please try again.');
        }
        break;
    }

    /* ---------------- ENTRIES ---------------- */

    case 'mark_entry': {
        if (!admin_can('manual_entry')) { $fail('Your role cannot mark entries.'); }
        if (!$student) { $fail('Participant not found.'); }
        $r = attempt_entry($student, 'MANUAL', null, (int) $admin['id'], 'ADMIN', null, $reason !== '' ? $reason : 'Marked manually by admin');
        if (in_array($r['result'], ['SUCCESS', 'MANUAL_APPROVED'], true)) {
            audit_log('MANUAL_ENTRY', 'STUDENT', (string) ($student['pnr_number'] ?? $student['pass_id']),
                ['entry_status' => 'NOT_ENTERED'], ['entry_status' => 'ENTERED'],
                $reason !== '' ? $reason : 'Marked manually by admin');
            $done('Entry marked for ' . $student['name'] . ' (PNR ' . ($student['pnr_number'] ?? $student['pass_id']) . ') at ' . time_short((string) $r['student']['entry_time']) . '.');
        }
        if ($r['result'] === 'ALREADY_USED') {
            set_flash('warning', $student['name'] . ' is already marked as ENTERED' .
                ($r['first'] ? ' (first entry ' . time_short((string) $r['first']['time']) . ' at ' . e((string) $r['first']['gate']) . ')' : '') . '.');
            redirect_to($back);
        }
        $fail('Entry denied (' . str_replace('_', ' ', $r['result']) . ') — this participant is not currently eligible to enter.');
        break;
    }

    case 'reset_entry': {
        if (!admin_can('manual_entry')) { $fail('Your role cannot reset entries.'); }
        if (!$student) { $fail('Participant not found.'); }
        if ($reason === '') { $fail('A reason is required to reset an entry.'); }
        if ((string) $student['entry_status'] !== 'ENTERED') { $fail($student['name'] . ' has not entered yet.'); }
        if (reset_entry($student, (int) $admin['id'], $reason)) {
            audit_log('ENTRY_RESET', 'STUDENT', (string) ($student['pnr_number'] ?? $student['pass_id']),
                ['entry_status' => 'ENTERED'], ['entry_status' => 'NOT_ENTERED'], $reason);
            $done('Entry reset for ' . $student['name'] . '. Previous entry kept in history.');
        }
        $fail('Could not reset entry. Please try again.');
        break;
    }

    /* ---------------- PASS CONTROL ---------------- */

    case 'disable_pass':
    case 'activate_pass':
    case 'cancel_pass': {
        if (!admin_can('passes')) { $fail('Your role cannot manage passes.'); }
        if (!$student) { $fail('Participant not found.'); }
        $newStatus = ['disable_pass' => 'DISABLED', 'activate_pass' => 'ACTIVE', 'cancel_pass' => 'CANCELLED'][$action];
        $auditName = ['disable_pass' => 'PASS_DISABLED', 'activate_pass' => 'PASS_ACTIVATED', 'cancel_pass' => 'PASS_CANCELLED'][$action];
        if ((string) $student['pass_status'] === $newStatus) { $fail('Pass is already ' . $newStatus . '.'); }
        $stmt = db()->prepare('UPDATE students SET pass_status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$newStatus, now_db(), (int) $student['id']]);
        audit_log($auditName, 'STUDENT', (string) ($student['pnr_number'] ?? $student['pass_id']),
            ['pass_status' => $student['pass_status']], ['pass_status' => $newStatus], $reason !== '' ? $reason : null);
        $done('Pass ' . strtolower($newStatus) . ' for ' . $student['name'] . ' (' . $student['pass_id'] . ').');
        break;
    }

    /* ---------------- DELETE ---------------- */

    case 'delete_student': {
        if (!admin_can('participant_delete')) { $fail('Your role cannot delete participants.'); }
        if (!$student) { $fail('Participant not found.'); }
        if ($reason === '') { $fail('A reason is required to delete a participant.'); }
        $snapshot = $student;
        $db = db();
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('DELETE FROM payments WHERE student_id = ?');
            $stmt->execute([(int) $student['id']]);
            $stmt = $db->prepare('DELETE FROM students WHERE id = ?');
            $stmt->execute([(int) $student['id']]);
            $db->commit();
            audit_log('STUDENT_DELETED', 'STUDENT', (string) ($student['pnr_number'] ?? $student['pass_id']), $snapshot, null, $reason);
            $done('Participant ' . $student['name'] . ' (PNR ' . ($student['pnr_number'] ?? $student['pass_id']) . ') deleted. Full snapshot stored in audit log.');
        } catch (Throwable $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[FresherParty] delete_student: ' . $ex->getMessage());
            $fail('Could not delete participant. Please try again.');
        }
        break;
    }

    default:
        $fail('Unknown action.');
}
