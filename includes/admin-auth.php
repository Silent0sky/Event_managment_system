<?php
declare(strict_types=1);
/**
 * FRESHERS PARTY 2026 — Admin authentication & role-based authorization.
 * Permissions are ALWAYS enforced server-side (never trust hidden HTML).
 */
require_once __DIR__ . '/functions.php';

function admin_permissions(string $role): array
{
    $all = [
        'dashboard', 'participants', 'participant_view', 'participant_edit', 'participant_create',
        'participant_delete', 'payments', 'payment_verify', 'volunteers', 'entries', 'manual_entry',
        'passes', 'reports', 'reports_participants', 'reports_payments', 'reports_entries',
        'settings', 'audit_logs', 'scanner', 'admins',
    ];
    switch ($role) {
        case 'SUPER_ADMIN':
            return $all;
        case 'EVENT_ADMIN':
            return [
                'dashboard', 'participants', 'participant_view', 'participant_edit', 'participant_create',
                'participant_delete', 'passes', 'entries', 'reports', 'reports_participants',
                'reports_payments', 'reports_entries', 'settings',
            ];
        case 'PAYMENT_ADMIN':
            return ['dashboard', 'participant_view', 'payments', 'payment_verify', 'reports', 'reports_payments'];
        case 'ENTRY_MANAGER':
            return ['dashboard', 'participant_view', 'entries', 'manual_entry', 'reports', 'reports_entries', 'scanner'];
        default:
            return [];
    }
}

function admin_can(string $permission): bool
{
    $admin = current_admin();
    if (!$admin) {
        return false;
    }
    return in_array($permission, admin_permissions((string) $admin['role']), true);
}

function admin_login(array $admin): void
{
    secure_session_start();
    session_regenerate_id(true);
    $_SESSION['admin'] = [
        'id'       => (int) $admin['id'],
        'name'     => (string) $admin['name'],
        'username' => (string) $admin['username'],
        'role'     => (string) $admin['role'],
    ];
}

function admin_logout(): void
{
    secure_session_start();
    unset($_SESSION['admin']);
}

/** @return array admin row from session; redirects to login page if absent. */
function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        secure_session_start();
        $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect_to('admin/login.php');
    }
    rotate_session();
    return $admin;
}

/** @return array admin row; redirects with error flash when the role lacks the permission. */
function require_permission(string $permission): array
{
    $admin = require_admin();
    if (!admin_can($permission)) {
        set_flash('danger', 'Your role (' . $admin['role'] . ') is not allowed to access that area.');
        redirect_to('admin/dashboard.php');
    }
    return $admin;
}

/** For JSON APIs: verify admin session + permission, reply with JSON errors instead of redirects. */
function require_permission_json(string $permission): array
{
    $admin = current_admin();
    if (!$admin) {
        json_out(['ok' => false, 'message' => 'Admin authentication required.'], 401);
    }
    if (!admin_can($permission)) {
        json_out(['ok' => false, 'message' => 'Permission denied for role ' . $admin['role'] . '.'], 403);
    }
    return $admin;
}
