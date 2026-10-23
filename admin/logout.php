<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin logout (audited).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$admin = current_admin();
if ($admin) {
    audit_log('ADMIN_LOGOUT', 'ADMIN', (string) $admin['username']);
}
admin_logout();
set_flash('info', 'You have been signed out of the admin console.');
redirect_to('admin/login.php');
