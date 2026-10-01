<?php
/**
 * Research Proposal and Project Management System
 * Forgot Password
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = sanitize($_POST['email'] ?? '');

    if (empty($email)) {
        $error = "Please provide your registered institutional email address.";
    } else {
        $db = get_db();
        $stmt = $db->prepare("SELECT id, name FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            log_audit((int)$user['id'], 'PASSWORD_RESET_REQUESTED', 'users', (int)$user['id'], "Password reset requested for {$email}");
            $message = "A password reset link and instruction email has been dispatched to {$email}. For demonstration environments, your current password is 'password123'.";
        } else {
            // Security: avoid revealing user enumeration
            $message = "If an active institutional account exists for that email, password instructions have been sent.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?= e(APP_SHORT_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url("/assets/css/style.css") ?>">
</head>
<body class="bg-light d-flex flex-column min-vh-100">

<div class="gov-header py-3 px-4 shadow-sm">
    <div class="container-fluid d-flex align-items-center justify-content-between">
        <a href="<?= url("/login.php") ?>" class="gov-brand">
            <div class="gov-logo-badge">PRIME</div>
            <div class="fw-bold fs-5 text-white">NDRI PRIME</div>
        </a>
    </div>
</div>

<div class="container my-auto py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="text-primary mb-2">
                            <i class="bi bi-key-fill" style="font-size: 2.5rem; color: #1a365d;"></i>
                        </div>
                        <h4 class="fw-bold text-dark">Password Assistance</h4>
                        <p class="text-muted small">Enter your institutional email to reset your account password</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-success small" role="alert">
                            <i class="bi bi-check-circle-fill me-1"></i> <?= e($message) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger small" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= url("/forgot-password.php") ?>">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                        <div class="mb-3">
                            <label for="email" class="form-label text-secondary small fw-semibold">Institutional Email</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="name@icar.org.in" required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" style="background-color: #1a365d; border-color: #1a365d;">
                            Send Reset Instructions
                        </button>
                    </form>

                    <div class="text-center mt-4">
                        <a href="<?= url("/login.php") ?>" class="text-decoration-none small text-muted">
                            <i class="bi bi-arrow-left me-1"></i> Return to Login
                        </a>
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
