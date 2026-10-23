<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Public logout (clears any session and returns home).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (current_admin() || current_volunteer()) {
    audit_log('LOGOUT', null, null, null, null, 'Public logout endpoint');
}
destroy_all_sessions();
redirect_to('index.php');
