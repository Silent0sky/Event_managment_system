<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Scanner logout.
 * Audits the logout (only when a volunteer session exists), clears the
 * session slot and sends the volunteer back to the scanner login page.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/volunteer-auth.php';
secure_session_start();

if (current_volunteer()) {
    /* audit_log auto-attaches the currently logged-in volunteer */
    audit_log('VOLUNTEER_LOGOUT');
}

volunteer_logout();
redirect_to('scanner/login.php');
