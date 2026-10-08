<?php
/**
 * Research Proposal and Project Management System
 * Authentication Login Page
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    header("Location: " . get_role_dashboard_url(current_user_role_id()));
    exit;
}

$error = null;
$email = '';
$redirect = $_GET['redirect'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirect = $_POST['redirect'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both institutional email and password.";
    } else {
        $db = get_db();
        $stmt = $db->prepare("SELECT u.*, r.role_name, d.department_name, d.department_code
                              FROM users u
                              JOIN roles r ON u.role_id = r.id
                              LEFT JOIN departments d ON u.department_id = d.id
                              WHERE LOWER(u.email) = LOWER(?)");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Check password (allows standard verify or demo fallback password)
        if ($user && (password_verify($password, $user['password']) || $password === 'password123')) {
            if ($user['status'] === 'pending') {
                $error = "Your registration for <strong>" . e($user['name']) . "</strong> (" . e($user['role_name']) . ") has been submitted and is currently <strong>Awaiting Approval</strong> by the Joint Director (Research). You will be granted login access once verified.";
                log_audit(null, 'LOGIN_BLOCKED_PENDING_APPROVAL', 'users', (int)$user['id'], "Login attempt for pending account {$email}");
            } elseif ($user['status'] === 'inactive') {
                $error = "Your account is currently deactivated. Please contact the Joint Director (Research) or portal administrator.";
                log_audit(null, 'LOGIN_BLOCKED_INACTIVE', 'users', (int)$user['id'], "Login attempt for inactive account {$email}");
            } elseif ($user['status'] === 'rejected') {
                $error = "Your registration request was declined by the Joint Director. Please contact the Joint Director's office for details.";
                log_audit(null, 'LOGIN_BLOCKED_REJECTED', 'users', (int)$user['id'], "Login attempt for rejected account {$email}");
            } else {
                login_user($user);
                flash('success', "Welcome back, {$user['name']}!");

                if (!empty($redirect) && !str_contains($redirect, 'login.php') && !str_contains($redirect, 'logout.php')) {
                    header("Location: " . $redirect);
                } else {
                    header("Location: " . get_role_dashboard_url((int)$user['role_id']));
                }
                exit;
            }
        } else {
            $error = "Invalid email or password credentials.";
            log_audit(null, 'FAILED_LOGIN_ATTEMPT', 'users', null, "Failed login for email: {$email}");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url('/assets/css/style.css') ?>">
</head>
<body class="bg-light d-flex flex-column min-vh-100">

<!-- Government Top Header Bar -->
<div class="gov-header py-3 px-4 shadow-sm">
    <div class="container-fluid d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="gov-logo-badge" style="width: 70px; min-width: 70px;">PRIME</div>
            <div>
                <h4 class="m-0 text-white fw-bold">NDRI PRIME</h4>
                <small class="text-white-50">Project Information Management and Evaluation System</small>
            </div>
        </div>
        <span class="text-white-50 small d-none d-md-inline">Official Institutional Research Portal</span>
    </div>
</div>

<div class="container my-auto py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="text-primary mb-2">
                            <i class="bi bi-shield-lock-fill" style="font-size: 2.8rem; color: #1a365d;"></i>
                        </div>
                        <h3 class="fw-bold text-dark">Log in</h3>
                        <p class="text-muted small">Sign in with your institutional credentials to access research proposals</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show small" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= $error ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?= render_flashes() ?>

                    <form method="POST" action="<?= url('/login.php') ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

                        <div class="mb-3">
                            <label for="email" class="form-label text-secondary small fw-semibold">Email address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" placeholder="name@icar.org.in" required autofocus>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <label for="password" class="form-label text-secondary small fw-semibold">Password</label>
                                <a href="<?= url('/forgot-password.php') ?>" class="small text-decoration-none">Forgot password?</a>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-key"></i></span>
                                <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
                            </div>
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember">
                            <label class="form-check-label small text-muted" for="remember">
                                Remember this workstation
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm" style="background-color: #1a365d; border-color: #1a365d;">
                            <i class="bi bi-box-arrow-in-right me-1"></i> LOG IN
                        </button>

                        <div class="mt-3 text-center">
                            <span class="text-muted small">New Faculty or Researcher?</span>
                            <a href="<?= url('/register.php') ?>" class="small fw-bold text-decoration-none ms-1 text-primary">
                                <i class="bi bi-person-plus-fill me-1"></i>Register as Faculty / HOD &rarr;
                            </a>
                        </div>
                    </form>

                    <hr class="my-4 text-muted">

                    <!-- Quick Testing Login Helper -->
                    <div>
                        <div class="text-center text-muted small fw-semibold mb-2">
                            <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Quick Role Access (Demo Accounts):
                        </div>
                        <div class="d-grid gap-2">
                            <a href="<?= url('/switch-role.php?user_id=1') ?>" class="btn btn-sm btn-outline-secondary text-start d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-person-workspace text-primary me-2"></i><strong>Dr. Aris Thorne</strong> (Scientist)</span>
                                <span class="badge bg-primary-subtle text-primary">Scientist</span>
                            </a>
                            <a href="<?= url('/switch-role.php?user_id=3') ?>" class="btn btn-sm btn-outline-secondary text-start d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-person-check text-success me-2"></i><strong>Prof. Rajesh Kumar</strong> (HOD - AGB)</span>
                                <span class="badge bg-success-subtle text-success">HOD</span>
                            </a>
                            <a href="<?= url('/switch-role.php?user_id=5') ?>" class="btn btn-sm btn-outline-secondary text-start d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-award text-warning me-2"></i><strong>Dr. Jay Dee</strong> (Joint Director)</span>
                                <span class="badge bg-warning-subtle text-dark">Joint Director</span>
                            </a>
                        </div>
                        <div class="text-center mt-2">
                            <small class="text-muted" style="font-size: 0.75rem;">Default credentials password: <code>password123</code></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="text-center text-muted small py-3 border-top bg-white">
    &copy; <?= date('Y') ?> NDRI PRIME - Project Information Management and Evaluation System. All rights reserved.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
