<?php
/**
 * Research Proposal and Project Management System
 * Global HTML Header & Navbar
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$pageTitle = $pageTitle ?? 'Dashboard';
$currentUser = current_user();
$currentRoleId = current_user_role_id();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= e(APP_SHORT_NAME) ?></title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom Theme -->
    <link rel="stylesheet" href="<?= url('/assets/css/style.css?v=2.1') ?>">
</head>
<body>

<?php if (is_logged_in()): ?>
<!-- Quick Role Switcher Banner for Seamless Testing -->
<div class="role-switch-bar no-print">
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-warning text-dark"><i class="bi bi-person-badge me-1"></i>Active Role:</span>
        <strong><?= e($currentUser['name']) ?></strong>
        <span class="text-secondary">(<?= e($currentUser['role_name'] ?? ($currentUser['role_id'] == 1 ? 'Scientist' : ($currentUser['role_id'] == 2 ? 'HOD' : 'Joint Director'))) ?> - <?= e($currentUser['department_code'] ?? 'CS') ?>)</span>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="text-white-50 small">Switch Demo Role:</span>
        <a href="<?= url('/switch-role.php?user_id=2&redirect=' . urlencode($_SERVER['REQUEST_URI'])) ?>" class="btn btn-xs btn-outline-light <?= $currentUser['id'] == 2 ? 'active fw-bold' : '' ?>" style="font-size: 0.75rem; padding: 2px 8px;">
            Scientist (Dr. Sarah)
        </a>
        <a href="<?= url('/switch-role.php?user_id=1&redirect=' . urlencode($_SERVER['REQUEST_URI'])) ?>" class="btn btn-xs btn-outline-light <?= $currentUser['id'] == 1 ? 'active fw-bold' : '' ?>" style="font-size: 0.75rem; padding: 2px 8px;">
            Scientist (Dr. Aris)
        </a>
        <a href="<?= url('/switch-role.php?user_id=3&redirect=' . urlencode($_SERVER['REQUEST_URI'])) ?>" class="btn btn-xs btn-outline-light <?= $currentUser['id'] == 3 ? 'active fw-bold' : '' ?>" style="font-size: 0.75rem; padding: 2px 8px;">
            HOD (Prof. Rajesh)
        </a>
        <a href="<?= url('/switch-role.php?user_id=5&redirect=' . urlencode($_SERVER['REQUEST_URI'])) ?>" class="btn btn-xs btn-outline-light <?= $currentUser['id'] == 5 ? 'active fw-bold' : '' ?>" style="font-size: 0.75rem; padding: 2px 8px;">
            Joint Director (Dr. Jay Dee)
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Top Institutional Header -->
<header class="gov-header py-2 px-3 px-md-4">
    <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-link text-white d-md-none p-0 me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">
                <i class="bi bi-list fs-3"></i>
            </button>
            <a href="<?= url('/') ?>" class="gov-brand">
                <div class="gov-logo-badge" style="width: 70px; min-width: 70px;">PRIME</div>
                <div>
                    <div class="fw-bold fs-5 text-white" style="letter-spacing: 0.5px;">NDRI PRIME</div>
                    <div class="small text-white-50 d-none d-sm-block" style="font-size: 0.75rem;">Project Information Management and Evaluation System</div>
                </div>
            </a>
        </div>

        <div class="d-flex align-items-center gap-3">
            <?php if (is_logged_in()): ?>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-light dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle fs-6"></i>
                        <span class="d-none d-sm-inline"><?= e($currentUser['name']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li class="dropdown-header">
                            <strong><?= e($currentUser['name']) ?></strong><br>
                            <small class="text-muted"><?= e($currentUser['designation'] ?? '') ?></small>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= url('/profile.php') ?>"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= url('/logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
                    </ul>
                </div>
            <?php else: ?>
                <a href="<?= url('/login.php') ?>" class="btn btn-sm btn-warning fw-semibold px-3">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="app-wrapper">
    <?php if (is_logged_in()): ?>
        <?php include __DIR__ . '/sidebar.php'; ?>
    <?php endif; ?>

    <main class="app-content">
        <?= render_flashes() ?>
