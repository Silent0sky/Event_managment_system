<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Volunteer (scanner) authentication.
 */
require_once __DIR__ . '/functions.php';

function volunteer_login(array $volunteer): void
{
    secure_session_start();
    session_regenerate_id(true);
    $_SESSION['volunteer'] = [
        'id'          => (int) $volunteer['id'],
        'volunteer_id' => (string) $volunteer['volunteer_id'],
        'name'        => (string) $volunteer['name'],
        'gate_name'   => (string) $volunteer['gate_name'],
    ];
}

function volunteer_logout(): void
{
    secure_session_start();
    unset($_SESSION['volunteer']);
}

/** @return array volunteer session row; redirects to scanner login when absent. */
function require_volunteer(): array
{
    $volunteer = current_volunteer();
    if (!$volunteer) {
        redirect_to('scanner/login.php');
    }
    // Re-check live status from DB on every request (disable takes effect instantly)
    $stmt = db()->prepare('SELECT * FROM volunteers WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $volunteer['id']]);
    $row = $stmt->fetch();
    if (!$row || ($row['status'] ?? '') !== 'ACTIVE') {
        volunteer_logout();
        set_flash('warning', 'Your scanner account is disabled. Contact the administrator.');
        redirect_to('scanner/login.php');
    }
    rotate_session();
    $_SESSION['volunteer']['gate_name'] = (string) $row['gate_name'];
    return array_merge($_SESSION['volunteer'], ['gate_name' => (string) $row['gate_name']]);
}
