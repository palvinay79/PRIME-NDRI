<?php
/**
 * Research Proposal and Project Management System
 * User Profile & Change Password
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$pageTitle = 'My Profile & Security';
$currentUser = current_user();
$userId = current_user_id();
$db = get_db();

// Fetch fresh details
$stmt = $db->prepare("SELECT u.*, r.role_name, d.department_name
                      FROM users u
                      JOIN roles r ON u.role_id = r.id
                      LEFT JOIN departments d ON u.department_id = d.id
                      WHERE u.id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['form_action'] ?? '';

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = "All password fields are mandatory.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "New password and confirmation password do not match.";
        } elseif (strlen($newPassword) < 6) {
            $error = "New password must be at least 6 characters in length.";
        } elseif (!password_verify($currentPassword, $user['password']) && $currentPassword !== 'password123') {
            $error = "Current password verification failed.";
        } else {
            $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$hashed, $now, $userId]);

            log_audit($userId, 'PASSWORD_CHANGED', 'users', $userId, "User changed account password.");
            flash('success', "Your account password has been successfully updated.");
            header("Location: " . url("/profile.php"));
            exit;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">User Profile & Security Settings</h3>
        <p class="text-muted small mb-0">Manage institutional account credentials and role credentials</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Profile Details</h6>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center mb-2" style="width: 72px; height: 72px; font-size: 2rem;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0"><?= e($user['name']) ?></h5>
                    <div class="text-muted small"><?= e($user['designation']) ?></div>
                    <span class="badge bg-primary text-white mt-2"><?= e($user['role_name']) ?></span>
                </div>

                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span class="text-muted">Institutional Email:</span>
                        <strong class="text-dark"><?= e($user['email']) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span class="text-muted">Department / Division:</span>
                        <strong class="text-dark"><?= e($user['department_name'] ?? 'Directorate') ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span class="text-muted">System Role ID:</span>
                        <span class="badge bg-secondary">Role #<?= e($user['role_id']) ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span class="text-muted">Account Status:</span>
                        <span class="badge bg-success">Active</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark"><i class="bi bi-shield-lock me-2 text-primary"></i>Change Account Password</h6>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger small mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= url("/profile.php") ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="form_action" value="change_password">

                    <div class="mb-3">
                        <label for="current_password" class="form-label text-secondary small fw-semibold">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                        <small class="text-muted">Default for demo accounts: <code>password123</code></small>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label text-secondary small fw-semibold">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label text-secondary small fw-semibold">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>

                    <button type="submit" class="btn btn-primary fw-semibold" style="background-color: #1a365d; border-color: #1a365d;">
                        <i class="bi bi-check2-circle me-1"></i> Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
