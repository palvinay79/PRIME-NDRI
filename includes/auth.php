<?php
/**
 * Research Proposal and Project Management System
 * Authentication & Session Guards
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    // Hardened session settings
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

/**
 * Check if user is logged in
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

/**
 * Return current logged-in user array
 */
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Current user ID
 */
function current_user_id(): ?int {
    return $_SESSION['user']['id'] ?? null;
}

/**
 * Current user role ID (1 = Scientist, 2 = HOD, 3 = Joint Director)
 */
function current_user_role_id(): ?int {
    return isset($_SESSION['user']['role_id']) ? (int)$_SESSION['user']['role_id'] : null;
}

/**
 * Current user role name
 */
function current_user_role_name(): ?string {
    return $_SESSION['user']['role_name'] ?? null;
}

/**
 * Current user department ID
 */
function current_user_department_id(): ?int {
    return isset($_SESSION['user']['department_id']) ? (int)$_SESSION['user']['department_id'] : null;
}

/**
 * Enforce authentication. Redirect to login.php if not authenticated.
 */
function require_login(): void {
    if (!is_logged_in()) {
        flash('warning', 'Please sign in to access the portal.');
        $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: ' . url('/login.php?redirect=' . urlencode($currentUri)));
        exit;
    }
}

/**
 * Enforce Role-Based Access Control.
 * @param int|array $allowedRoles
 */
function require_role($allowedRoles): void {
    require_login();

    $roleId = current_user_role_id();
    $allowed = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];

    if (!in_array($roleId, $allowed, true)) {
        log_audit(current_user_id(), 'UNAUTHORIZED_ACCESS_ATTEMPT', 'page', 0, "Access denied to URI: " . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

/**
 * Log in a user and set up session
 */
function login_user(array $user): void {
    // Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role_id' => (int)$user['role_id'],
        'role_name' => $user['role_name'] ?? get_role_name_by_id((int)$user['role_id']),
        'department_id' => !empty($user['department_id']) ? (int)$user['department_id'] : null,
        'department_name' => $user['department_name'] ?? null,
        'department_code' => $user['department_code'] ?? null,
        'designation' => $user['designation'] ?? 'Scientist'
    ];

    log_audit((int)$user['id'], 'USER_LOGIN', 'users', (int)$user['id'], "User logged in successfully.");
}

/**
 * Log out user
 */
function logout_user(): void {
    if (is_logged_in()) {
        log_audit(current_user_id(), 'USER_LOGOUT', 'users', current_user_id(), "User logged out.");
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Helper to get role name
 */
function get_role_name_by_id(int $roleId): string {
    switch ($roleId) {
        case ROLE_SCIENTIST: return 'Scientist';
        case ROLE_HOD: return 'Head of Department';
        case ROLE_JOINT_DIRECTOR: return 'Joint Director';
        default: return 'User';
    }
}

/**
 * Return landing route for role
 */
function get_role_dashboard_url(int $roleId): string {
    switch ($roleId) {
        case ROLE_SCIENTIST: return url('/scientist/dashboard.php');
        case ROLE_HOD: return url('/hod/dashboard.php');
        case ROLE_JOINT_DIRECTOR: return url('/joint-director/dashboard.php');
        default: return url('/login.php');
    }
}
