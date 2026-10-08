<?php
/**
 * Research Proposal and Project Management System
 * Faculty & Researcher Self-Registration Portal
 * Allows Scientists and HODs to submit registration requests awaiting Joint Director approval.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// If user is already logged in, redirect them to their respective dashboard
if (is_logged_in()) {
    header("Location: " . get_role_dashboard_url(current_user_role_id()));
    exit;
}

$db = get_db();
$errors = [];
$registrationSuccess = false;
$submittedData = [];

// Fetch active departments for dropdown
$departments = $db->query("SELECT id, department_code, department_name FROM departments ORDER BY department_name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $titlePrefix = sanitize($_POST['title_prefix'] ?? 'Dr.');
    $rawName = trim(sanitize($_POST['full_name'] ?? ''));
    $fullName = (!empty($titlePrefix) && !str_starts_with($rawName, $titlePrefix)) ? "{$titlePrefix} {$rawName}" : $rawName;
    
    $email = strtolower(trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL)));
    $mobileNo = trim(sanitize($_POST['mobile_no'] ?? ''));
    $roleId = (int)($_POST['role_id'] ?? 0);
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $discipline = trim(sanitize($_POST['discipline'] ?? ''));
    $designation = trim(sanitize($_POST['designation'] ?? ''));
    $employeeId = trim(sanitize($_POST['employee_id'] ?? ''));
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $agreed = isset($_POST['agree_terms']);

    // Validation
    if (empty($rawName)) {
        $errors[] = "Full Name is required.";
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid institutional email address is required.";
    }

    if (empty($mobileNo)) {
        $errors[] = "Mobile No. is required.";
    } elseif (!preg_match('/^[0-9+\-\s]{10,15}$/', $mobileNo)) {
        $errors[] = "Please provide a valid 10-digit mobile number.";
    }

    if (!in_array($roleId, [ROLE_SCIENTIST, ROLE_HOD], true)) {
        $errors[] = "Please select an authorized role: Faculty / PI or Head of Department (HOD). Joint Director accounts cannot be self-registered.";
    }

    if ($departmentId <= 0) {
        $errors[] = "Please select your primary Division / Department.";
    }

    // Check if the selected department is SRS or ERS
    $selectedDeptCode = '';
    if ($departmentId > 0) {
        $deptStmt = $db->prepare("SELECT department_code FROM departments WHERE id = ?");
        $deptStmt->execute([$departmentId]);
        $deptRow = $deptStmt->fetch();
        if ($deptRow) {
            $selectedDeptCode = strtoupper(trim($deptRow['department_code']));
        }
    }

    $isRegionalStation = ($selectedDeptCode === 'SRS' || $selectedDeptCode === 'ERS');
    if ($isRegionalStation && empty($discipline)) {
        $errors[] = "Discipline is required when selecting {$selectedDeptCode} (Regional Station).";
    }

    if (empty($designation)) {
        $designation = ($roleId === ROLE_HOD) ? 'Head of Department' : 'Faculty / PI';
    }

    if (empty($password) || strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters in length.";
    }

    if ($password !== $passwordConfirm) {
        $errors[] = "Password confirmation does not match.";
    }

    if (!$agreed) {
        $errors[] = "You must acknowledge and certify the institutional research declaration.";
    }

    // Check if email already exists in users table
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id, name, status, role_id FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['status'] === 'pending') {
                $errors[] = "A registration request with email '{$email}' has already been submitted and is currently awaiting approval by the Joint Director.";
            } elseif ($existing['status'] === 'active') {
                $errors[] = "An account with email '{$email}' already exists. Please <a href='" . url('/login.php') . "' class='alert-link'>sign in here</a>.";
            } else {
                $errors[] = "An account with email '{$email}' exists but is currently deactivated. Please contact the Joint Director.";
            }
        }
    }

    // Process Registration
    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $finalDesignation = !empty($employeeId) ? "{$designation} [ID: {$employeeId}]" : $designation;

        $now = date('Y-m-d H:i:s');
        try {
            // Insert user with status = 'pending'
            $stmt = $db->prepare("INSERT INTO users (name, email, password, role_id, department_id, designation, mobile_no, discipline, status, created_at, updated_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
            $stmt->execute([$fullName, $email, $hashedPassword, $roleId, $departmentId, $finalDesignation, $mobileNo, $discipline ?: null, $now, $now]);
            $newUserId = (int)$db->lastInsertId();
        } catch (Exception $e) {
            error_log("Registration DB error: " . $e->getMessage());
            $errors[] = "Database error during registration: " . $e->getMessage();
        }

        $roleTitle = ($roleId === ROLE_HOD) ? 'Head of Department (HOD)' : 'Faculty / PI';
        
        // Log to audit log
        log_audit(
            $newUserId,
            'USER_REGISTRATION_SUBMITTED',
            'users',
            $newUserId,
            "Self-registration submitted by {$fullName} ({$email}) as {$roleTitle}. Awaiting Joint Director approval."
        );

        $registrationSuccess = true;
        $submittedData = [
            'id' => $newUserId,
            'name' => $fullName,
            'email' => $email,
            'mobile_no' => $mobileNo,
            'discipline' => $discipline ?: null,
            'department_code' => $selectedDeptCode,
            'role' => $roleTitle,
            'designation' => $finalDesignation
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty & Researcher Registration - <?= e(APP_SHORT_NAME) ?></title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom Styles -->
    <link rel="stylesheet" href="<?= url('/assets/css/style.css') ?>">
    <style>
        .role-option-card {
            cursor: pointer;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        .role-option-card:hover {
            border-color: #1a365d;
            background-color: #f8fafc;
        }
        .role-option-card.selected {
            border-color: #1a365d;
            background-color: #f0f4f8;
            box-shadow: 0 0 0 1px #1a365d;
        }
    </style>
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
        <div class="d-none d-md-flex align-items-center gap-2 text-white-50 small">
            <i class="bi bi-shield-lock-fill text-warning"></i>
            <span>Institutional Research Governance</span>
        </div>
    </div>
</div>

<div class="container my-auto py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <?php if ($registrationSuccess): ?>
                <!-- Registration Success View -->
                <div class="card shadow-sm border-0 rounded-3">
                    <div class="card-body p-4 p-md-5 text-center">
                        <div class="mb-3">
                            <span class="d-inline-flex align-items-center justify-content-center bg-success-subtle text-success rounded-circle" style="width: 76px; height: 76px;">
                                <i class="bi bi-check-circle-fill" style="font-size: 2.5rem;"></i>
                            </span>
                        </div>
                        <h3 class="fw-bold text-dark mb-2">Registration Submitted!</h3>
                        <p class="text-secondary fs-6 mb-4">
                            Your account request has been registered and forwarded for administrative review.
                        </p>

                        <div class="alert alert-warning border-0 text-start p-4 mb-4 rounded-3" style="background-color: #fff9db; color: #664d03;">
                            <div class="d-flex align-items-start gap-3">
                                <i class="bi bi-hourglass-split fs-3 text-warning"></i>
                                <div>
                                    <h6 class="fw-bold mb-1">Awaiting Joint Director (Research) Approval</h6>
                                    <p class="small mb-2">
                                        As per NDRI research governance guidelines, all new accounts for <strong>Faculty / PI</strong> and <strong>Head of Department (HOD)</strong> roles require approval by the <strong>Joint Director (Research)</strong> before activation.
                                    </p>
                                    <ul class="small mb-0 ps-3">
                                        <li>Applicant: <strong><?= e($submittedData['name']) ?></strong></li>
                                        <li>Institutional Email: <code><?= e($submittedData['email']) ?></code></li>
                                        <li>Mobile No.: <strong><?= e($submittedData['mobile_no'] ?? '') ?></strong></li>
                                        <?php if (!empty($submittedData['discipline'])): ?>
                                            <li>Discipline: <strong><?= e($submittedData['discipline']) ?></strong></li>
                                        <?php endif; ?>
                                        <li>Requested Role: <span class="badge bg-primary text-white"><?= e($submittedData['role']) ?></span></li>
                                        <li>Designation: <?= e($submittedData['designation']) ?></li>
                                        <li>Status: <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending Review</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                            <a href="<?= url('/login.php') ?>" class="btn btn-primary px-4 py-2" style="background-color: #1a365d; border-color: #1a365d;">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Go to Sign In Page
                            </a>
                            <a href="<?= url('/switch-role.php?user_id=5') ?>" class="btn btn-outline-warning px-4 py-2 text-dark">
                                <i class="bi bi-shield-check me-1"></i> Switch to Joint Director to Approve Now
                            </a>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <!-- Registration Form Card -->
                <div class="card shadow-sm border-0 rounded-3">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <div class="d-inline-block p-3 rounded-circle bg-light text-primary mb-2">
                                <i class="bi bi-person-plus-fill" style="font-size: 2.2rem; color: #1a365d;"></i>
                            </div>
                            <h3 class="fw-bold text-dark">Faculty & Researcher Registration</h3>
                            <p class="text-muted small mb-0">Register as Faculty / PI or Head of Department (HOD) to access NDRI Project Information Management and Evaluation System</p>
                        </div>

                        <!-- Governance Notice Banner -->
                        <div class="alert alert-info border-0 rounded-3 small mb-4 d-flex align-items-start gap-2" style="background-color: #e8f4fd; color: #0d47a1;">
                            <i class="bi bi-shield-check fs-5 mt-n1 text-primary"></i>
                            <div>
                                <strong>Joint Director Verification Required:</strong> Newly registered accounts remain in <em>Pending</em> status until officially reviewed and approved by the Joint Director (Research). Once approved, you can immediately sign in.
                            </div>
                        </div>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger alert-dismissible fade show small" role="alert">
                                <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon-fill me-1"></i> Please fix the following errors:</div>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $err): ?>
                                        <li><?= $err ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="<?= url('/register.php') ?>" id="registrationForm" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <!-- Step 1: Role Selection -->
                            <div class="mb-4">
                                <label class="form-label text-dark fw-bold small text-uppercase">
                                    1. Select Institutional Role <span class="text-danger">*</span>
                                </label>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="role-option-card p-3 d-flex align-items-start gap-3 w-100 <?= (($_POST['role_id'] ?? '1') == '1') ? 'selected' : '' ?>" id="cardScientist">
                                            <input class="form-check-input mt-1" type="radio" name="role_id" value="<?= ROLE_SCIENTIST ?>" id="roleScientist" <?= (($_POST['role_id'] ?? '1') == '1') ? 'checked' : '' ?> onchange="updateRoleSelection()">
                                            <div>
                                                <div class="fw-bold text-dark d-flex align-items-center gap-1">
                                                    <i class="bi bi-person-workspace text-primary"></i> Faculty / PI
                                                </div>
                                                <small class="text-muted d-block mt-1" style="font-size: 0.8rem;">
                                                    Author research proposals, submit milestone updates, log budget expenses & annual reports.
                                                </small>
                                            </div>
                                        </label>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="role-option-card p-3 d-flex align-items-start gap-3 w-100 <?= (($_POST['role_id'] ?? '') == '2') ? 'selected' : '' ?>" id="cardHOD">
                                            <input class="form-check-input mt-1" type="radio" name="role_id" value="<?= ROLE_HOD ?>" id="roleHOD" <?= (($_POST['role_id'] ?? '') == '2') ? 'checked' : '' ?> onchange="updateRoleSelection()">
                                            <div>
                                                <div class="fw-bold text-dark d-flex align-items-center gap-1">
                                                    <i class="bi bi-person-check text-success"></i> Head of Department (HOD)
                                                </div>
                                                <small class="text-muted d-block mt-1" style="font-size: 0.8rem;">
                                                    Scrutinize and forward divisional research proposals, oversee faculty research projects.
                                                </small>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                <div class="form-text text-muted small mt-1">
                                    <i class="bi bi-info-circle me-1"></i> Joint Director (Administrator) accounts are governed directly by institutional authorities.
                                </div>
                            </div>

                            <!-- Step 2: Personal & Institutional Details -->
                            <div class="mb-4">
                                <label class="form-label text-dark fw-bold small text-uppercase">
                                    2. Personal & Professional Details
                                </label>
                                <div class="row g-3">
                                    <!-- Title and Name -->
                                    <div class="col-sm-4 col-md-3">
                                        <label for="title_prefix" class="form-label text-secondary small fw-semibold">Salutation</label>
                                        <select class="form-select" id="title_prefix" name="title_prefix">
                                            <option value="Dr." <?= (($_POST['title_prefix'] ?? '') === 'Dr.') ? 'selected' : '' ?>>Dr.</option>
                                            <option value="Prof." <?= (($_POST['title_prefix'] ?? '') === 'Prof.') ? 'selected' : '' ?>>Prof.</option>
                                            <option value="Er." <?= (($_POST['title_prefix'] ?? '') === 'Er.') ? 'selected' : '' ?>>Er.</option>
                                            <option value="Sh." <?= (($_POST['title_prefix'] ?? '') === 'Sh.') ? 'selected' : '' ?>>Sh.</option>
                                            <option value="Smt." <?= (($_POST['title_prefix'] ?? '') === 'Smt.') ? 'selected' : '' ?>>Smt.</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-8 col-md-9">
                                        <label for="full_name" class="form-label text-secondary small fw-semibold">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" placeholder="e.g. Ramesh Chandra" required>
                                    </div>

                                    <!-- Institutional Email -->
                                    <div class="col-md-6">
                                        <label for="email" class="form-label text-secondary small fw-semibold">Institutional Email <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted"><i class="bi bi-envelope"></i></span>
                                            <input type="email" class="form-control" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="name@icar.org.in" required>
                                        </div>
                                        <div class="form-text small">Please use your official NDRI institutional email.</div>
                                    </div>

                                    <!-- Mobile No. -->
                                    <div class="col-md-6">
                                        <label for="mobile_no" class="form-label text-secondary small fw-semibold">Mobile No. <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted"><i class="bi bi-telephone"></i></span>
                                            <input type="tel" class="form-control" id="mobile_no" name="mobile_no" value="<?= e($_POST['mobile_no'] ?? '') ?>" placeholder="e.g. 9876543210" pattern="[0-9+\-\s]{10,15}" maxlength="15" required>
                                        </div>
                                        <div class="form-text small">Primary 10-digit mobile contact number.</div>
                                    </div>

                                    <!-- Division / Department / Regional Station -->
                                    <div class="col-md-6">
                                        <label for="department_id" class="form-label text-secondary small fw-semibold">Division / Department / Station <span class="text-danger">*</span></label>
                                        <select class="form-select" id="department_id" name="department_id" required onchange="handleDepartmentChange()">
                                            <option value="" data-code="">-- Select Division / Station --</option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?= $dept['id'] ?>" 
                                                        data-code="<?= strtoupper(e($dept['department_code'])) ?>"
                                                        <?= (($_POST['department_id'] ?? '') == $dept['id']) ? 'selected' : '' ?>>
                                                    <?= e($dept['department_name']) ?> (<?= e($dept['department_code']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text small">Choose your primary division or regional station.</div>
                                    </div>

                                    <!-- Discipline Field (Dynamically displayed for Regional Stations SRS and ERS) -->
                                    <div class="col-md-6" id="disciplineContainer" style="display: none;">
                                        <label for="discipline" class="form-label text-secondary small fw-semibold">
                                            Discipline <span class="text-danger">*</span>
                                            <span class="badge bg-primary-subtle text-primary ms-1" style="font-size: 0.7rem;">Required for SRS / ERS</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted"><i class="bi bi-mortarboard"></i></span>
                                            <input type="text" class="form-control" id="discipline" name="discipline" 
                                                   value="<?= e($_POST['discipline'] ?? '') ?>" 
                                                   placeholder="e.g. Dairy Cattle Nutrition / Animal Genetics"
                                                   list="disciplineList">
                                        </div>
                                        <datalist id="disciplineList">
                                            <option value="Dairy Cattle Nutrition">
                                            <option value="Animal Genetics & Breeding">
                                            <option value="Livestock Production & Management">
                                            <option value="Animal Reproduction, Gynecology & Obstetrics">
                                            <option value="Animal Biotechnology">
                                            <option value="Dairy Microbiology">
                                            <option value="Dairy Chemistry">
                                            <option value="Dairy Technology">
                                            <option value="Dairy Engineering">
                                            <option value="Dairy Economics & Statistics">
                                            <option value="Dairy Extension Education">
                                            <option value="Forage Agronomy">
                                        </datalist>
                                        <div class="form-text small">Specify your discipline at SRS or ERS.</div>
                                    </div>

                                    <!-- Official Designation -->
                                    <div class="col-md-6">
                                        <label for="designation" class="form-label text-secondary small fw-semibold">Official Designation</label>
                                        <input type="text" class="form-control" id="designation" name="designation" list="designationList" value="<?= e($_POST['designation'] ?? '') ?>" placeholder="e.g. Senior Scientist">
                                        <datalist id="designationList">
                                            <option value="Faculty / PI">
                                            <option value="Scientist">
                                            <option value="Scientist (Senior Scale)">
                                            <option value="Senior Scientist">
                                            <option value="Principal Scientist">
                                            <option value="Head of Department">
                                            <option value="Professor">
                                            <option value="Associate Professor">
                                            <option value="Assistant Professor">
                                            <option value="National Fellow">
                                            <option value="Senior Research Fellow">
                                        </datalist>
                                    </div>

                                    <!-- Faculty / Employee ID -->
                                    <div class="col-md-6">
                                        <label for="employee_id" class="form-label text-secondary small fw-semibold">Faculty / Employee ID <span class="text-muted">(Optional)</span></label>
                                        <input type="text" class="form-control" id="employee_id" name="employee_id" value="<?= e($_POST['employee_id'] ?? '') ?>" placeholder="e.g. NDRI-FAC-108">
                                    </div>
                                </div>
                            </div>

                            <!-- Step 3: Security Credentials -->
                            <div class="mb-4">
                                <label class="form-label text-dark fw-bold small text-uppercase">
                                    3. Account Password
                                </label>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="password" class="form-label text-secondary small fw-semibold">Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted"><i class="bi bi-key"></i></span>
                                            <input type="password" class="form-control" id="password" name="password" placeholder="At least 6 characters" required>
                                            <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('password', this)" tabindex="-1">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="password_confirm" class="form-label text-secondary small fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted"><i class="bi bi-shield-check"></i></span>
                                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" placeholder="Re-enter password" required>
                                            <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('password_confirm', this)" tabindex="-1">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 4: Institutional Declaration -->
                            <div class="mb-4">
                                <div class="form-check p-3 rounded-2 bg-light border">
                                    <input class="form-check-input ms-0 me-2" type="checkbox" id="agree_terms" name="agree_terms" required <?= isset($_POST['agree_terms']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small text-dark" for="agree_terms">
                                        I hereby certify that the information provided above is accurate, that I am an authorized scientific researcher / faculty member at NDRI, and I agree to research governance oversight by the Joint Director (Research).
                                    </label>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm mb-3" style="background-color: #1a365d; border-color: #1a365d;">
                                <i class="bi bi-send-check-fill me-1"></i> Submit Registration for Joint Director Approval
                            </button>

                            <div class="text-center">
                                <span class="text-muted small">Already have an approved account?</span>
                                <a href="<?= url('/login.php') ?>" class="small fw-semibold text-decoration-none ms-1">Sign in here &rarr;</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<footer class="text-center text-muted small py-3 border-top bg-white mt-auto">
    &copy; <?= date('Y') ?> NDRI PRIME - Project Information Management and Evaluation System. All rights reserved.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateRoleSelection() {
    const isScientist = document.getElementById('roleScientist').checked;
    const isHOD = document.getElementById('roleHOD').checked;
    
    document.getElementById('cardScientist').classList.toggle('selected', isScientist);
    document.getElementById('cardHOD').classList.toggle('selected', isHOD);

    const designationInput = document.getElementById('designation');
    if (isHOD && (!designationInput.value || designationInput.value === 'Scientist' || designationInput.value === 'Faculty / PI')) {
        designationInput.value = 'Head of Department';
    } else if (isScientist && designationInput.value === 'Head of Department') {
        designationInput.value = 'Faculty / PI';
    }
}

function handleDepartmentChange() {
    const deptSelect = document.getElementById('department_id');
    if (!deptSelect) return;
    const selectedOption = deptSelect.options[deptSelect.selectedIndex];
    const deptCode = (selectedOption ? (selectedOption.getAttribute('data-code') || '') : '').toUpperCase().trim();
    
    const disciplineContainer = document.getElementById('disciplineContainer');
    const disciplineInput = document.getElementById('discipline');
    
    if (disciplineContainer && disciplineInput) {
        if (deptCode === 'SRS' || deptCode === 'ERS') {
            disciplineContainer.style.display = 'block';
            disciplineInput.setAttribute('required', 'required');
        } else {
            disciplineContainer.style.display = 'none';
            disciplineInput.removeAttribute('required');
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    handleDepartmentChange();
});

function togglePasswordVisibility(fieldId, btn) {
    const field = document.getElementById(fieldId);
    const icon = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}
</script>
</body>
</html>
