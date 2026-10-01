<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden - <?= e(APP_SHORT_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= url("/assets/css/style.css") ?>">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
    <div class="card shadow border-0 text-center p-4" style="max-width: 500px;">
        <div class="card-body">
            <div class="text-danger mb-3">
                <i class="bi bi-shield-lock-fill" style="font-size: 3.5rem;"></i>
            </div>
            <h3 class="card-title text-dark fw-bold">403 - Access Forbidden</h3>
            <p class="text-muted mb-4">
                You do not have administrative or departmental permission to access this workflow resource.
                All unauthorized attempts are logged for security auditing.
            </p>
            <div class="d-flex justify-content-center gap-2">
                <a href="javascript:history.back()" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Go Back
                </a>
                <a href="<?= url("/") ?>" class="btn btn-primary">
                    <i class="bi bi-speedometer2 me-1"></i> Return to Dashboard
                </a>
            </div>
        </div>
    </div>
</body>
</html>
