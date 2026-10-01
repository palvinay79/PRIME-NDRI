<?php
/**
 * Research Proposal and Project Management System
 * Fast Role Switcher (For Evaluation & Multi-role Testing)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$roleParam = isset($_GET['role']) ? (int)$_GET['role'] : 0;
$redirect = $_GET['redirect'] ?? '';

$db = get_db();

if ($userId <= 0 && $roleParam > 0) {
    // Map role to default demo user
    $roleToUser = [
        ROLE_SCIENTIST => 1,
        ROLE_HOD => 3,
        ROLE_JOINT_DIRECTOR => 5
    ];
    $userId = $roleToUser[$roleParam] ?? 1;
}

if ($userId > 0) {
    $stmt = $db->prepare("SELECT u.*, r.role_name, d.department_name, d.department_code
                          FROM users u
                          JOIN roles r ON u.role_id = r.id
                          LEFT JOIN departments d ON u.department_id = d.id
                          WHERE u.id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user) {
        login_user($user);
        flash('info', "Switched active session to {$user['name']} ({$user['role_name']}).");

        // If current redirect is not accessible to new role, route to their default dashboard
        if (str_contains($redirect, 'scientist') && (int)$user['role_id'] !== ROLE_SCIENTIST) {
            $redirect = get_role_dashboard_url((int)$user['role_id']);
        } elseif (str_contains($redirect, 'hod') && (int)$user['role_id'] !== ROLE_HOD) {
            $redirect = get_role_dashboard_url((int)$user['role_id']);
        } elseif (str_contains($redirect, 'joint-director') && (int)$user['role_id'] !== ROLE_JOINT_DIRECTOR) {
            $redirect = get_role_dashboard_url((int)$user['role_id']);
        }

        if (empty($redirect) || $redirect === '/') {
            $redirect = get_role_dashboard_url((int)$user['role_id']);
        }

        header("Location: " . $redirect);
        exit;
    }
}

redirect('/');
