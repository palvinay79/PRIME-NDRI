<?php
/**
 * Research Proposal and Project Management System
 * Joint Director / Administrator - User Management Portal
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_JOINT_DIRECTOR);

$pageTitle = 'User Management - Joint Director';
$db = get_db();
$currentUserId = current_user_id();

// Handle User Management Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Create New User
    if ($action === 'create_user') {
        $name = trim(sanitize($_POST['name'] ?? ''));
        $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
        $mobileNo = trim(sanitize($_POST['mobile_no'] ?? ''));
        $discipline = trim(sanitize($_POST['discipline'] ?? ''));
        $password = $_POST['password'] ?? '';
        $roleId = (int)($_POST['role_id'] ?? 0);
        $departmentId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $designation = trim(sanitize($_POST['designation'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($name)) {
            flash('danger', 'Full name is required.');
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'A valid email address is required.');
        } elseif (empty($password) || strlen($password) < 6) {
            flash('danger', 'Password must be at least 6 characters.');
        } elseif (!in_array($roleId, [ROLE_SCIENTIST, ROLE_HOD, ROLE_JOINT_DIRECTOR], true)) {
            flash('danger', 'Please select a valid user role.');
        } else {
            // Check for duplicate email
            $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                flash('danger', "A user account with email '{$email}' already exists.");
            } else {
                // If designation is blank, set a sensible default based on role
                if (empty($designation)) {
                    if ($roleId === ROLE_SCIENTIST) $designation = 'Scientist';
                    elseif ($roleId === ROLE_HOD) $designation = 'Head of Department';
                    elseif ($roleId === ROLE_JOINT_DIRECTOR) $designation = 'Joint Director (Research)';
                }

                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $now = date('Y-m-d H:i:s');
                
                try {
                    $stmt = $db->prepare("INSERT INTO users (name, email, password, role_id, department_id, designation, mobile_no, discipline, status, created_at, updated_at) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $hashedPassword, $roleId, $departmentId, $designation, $mobileNo, $discipline, $status, $now, $now]);
                    $newUserId = (int)$db->lastInsertId();
                    $roleName = get_role_name_by_id($roleId);

                    log_audit($currentUserId, 'USER_CREATED', 'users', $newUserId, "Created {$roleName} account for {$name} ({$email})");

                    flash('success', "User <strong>" . e($name) . "</strong> created successfully as <strong>" . e($roleName) . "</strong>. They can now log in with email <code>" . e($email) . "</code>.");
                } catch (Exception $e) {
                    error_log("Error creating user: " . $e->getMessage());
                    flash('danger', "Failed to create user: " . e($e->getMessage()));
                }
            }
        }
        redirect('/joint-director/users.php');
    }

    // 2. Edit User
    if ($action === 'edit_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $name = trim(sanitize($_POST['name'] ?? ''));
        $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
        $mobileNo = trim(sanitize($_POST['mobile_no'] ?? ''));
        $discipline = trim(sanitize($_POST['discipline'] ?? ''));
        $roleId = (int)($_POST['role_id'] ?? 0);
        $departmentId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $designation = trim(sanitize($_POST['designation'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $newPassword = $_POST['new_password'] ?? '';

        if ($userId <= 0) {
            flash('danger', 'Invalid user ID.');
        } elseif (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Name and valid email are required.');
        } elseif (!in_array($roleId, [ROLE_SCIENTIST, ROLE_HOD, ROLE_JOINT_DIRECTOR], true)) {
            flash('danger', 'Please select a valid user role.');
        } else {
            // Check duplicate email
            $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?");
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                flash('danger', "Another user is already registered with email '{$email}'.");
            } else {
                // Check if current user is changing their own status to inactive
                if ($userId === $currentUserId && $status === 'inactive') {
                    flash('danger', 'You cannot deactivate your own administrative account.');
                } else {
                    if (!empty($newPassword)) {
                        if (strlen($newPassword) < 6) {
                            flash('danger', 'New password must be at least 6 characters.');
                            redirect('/joint-director/users.php');
                        }
                        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
                        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, mobile_no = ?, discipline = ?, password = ?, role_id = ?, department_id = ?, designation = ?, status = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $mobileNo, $discipline, $hashed, $roleId, $departmentId, $designation, $status, $userId]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, mobile_no = ?, discipline = ?, role_id = ?, department_id = ?, designation = ?, status = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $mobileNo, $discipline, $roleId, $departmentId, $designation, $status, $userId]);
                    }

                    log_audit($currentUserId, 'USER_UPDATED', 'users', $userId, "Updated profile for {$name} (Role: " . get_role_name_by_id($roleId) . ", Phone: " . ($mobileNo ?: 'N/A') . ")");
                    flash('success', "User profile for <strong>" . e($name) . "</strong> has been updated successfully.");
                }
            }
        }
        redirect('/joint-director/users.php');
    }

    // 3. Reset Password
    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if ($userId <= 0 || empty($newPassword) || strlen($newPassword) < 6) {
            flash('danger', 'Password must be at least 6 characters long.');
        } else {
            $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $userId]);

            $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch();

            log_audit($currentUserId, 'USER_PASSWORD_RESET', 'users', $userId, "Admin reset password for user {$targetUser['email']}");
            flash('success', "Password for <strong>" . e($targetUser['name'] ?? 'User') . "</strong> has been reset successfully.");
        }
        redirect('/joint-director/users.php');
    }

    // 4. Toggle Status (Active / Inactive)
    if ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            flash('danger', 'Invalid user.');
        } elseif ($userId === $currentUserId) {
            flash('danger', 'You cannot deactivate your own active session.');
        } else {
            $stmt = $db->prepare("SELECT status, name FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
            if ($u) {
                $newStatus = ($u['status'] === 'active') ? 'inactive' : 'active';
                $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $userId]);

                log_audit($currentUserId, 'USER_STATUS_TOGGLED', 'users', $userId, "Changed status of {$u['name']} to {$newStatus}");
                flash('info', "Account status for <strong>" . e($u['name']) . "</strong> set to <strong>" . ucfirst($newStatus) . "</strong>.");
            }
        }
        redirect('/joint-director/users.php');
    }

    // 5. Delete User
    if ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            flash('danger', 'Invalid user.');
        } elseif ($userId === $currentUserId) {
            flash('danger', 'You cannot delete your own account.');
        } else {
            $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();

            if ($u) {
                // Check if user is associated with any proposals or projects
                $stmt = $db->prepare("SELECT COUNT(*) FROM proposals WHERE scientist_id = ?");
                $stmt->execute([$userId]);
                $propCount = (int)$stmt->fetchColumn();

                $stmt = $db->prepare("SELECT COUNT(*) FROM projects WHERE scientist_id = ?");
                $stmt->execute([$userId]);
                $projCount = (int)$stmt->fetchColumn();

                if ($propCount > 0 || $projCount > 0) {
                    // Cannot hard-delete due to institutional research records; deactivate instead
                    $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
                    $stmt->execute([$userId]);
                    log_audit($currentUserId, 'USER_DEACTIVATED_INSTEAD_OF_DELETE', 'users', $userId, "User {$u['name']} has {$propCount} proposals / {$projCount} projects; deactivated instead of deleted.");
                    flash('warning', "User <strong>" . e($u['name']) . "</strong> cannot be permanently deleted because they have {$propCount} research proposal(s) and {$projCount} project(s) on file. The account has been deactivated instead.");
                } else {
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    log_audit($currentUserId, 'USER_DELETED', 'users', $userId, "Deleted user {$u['name']} (ID: {$userId})");
                    flash('success', "User <strong>" . e($u['name']) . "</strong> has been permanently removed.");
                }
            }
        }
        redirect('/joint-director/users.php');
    }

    // 6. Approve User Registration
    if ($action === 'approve_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $assignedRoleId = !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null;
        $assignedDeptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $assignedDesig = !empty($_POST['designation']) ? trim(sanitize($_POST['designation'])) : null;
        $assignedPhone = isset($_POST['mobile_no']) ? trim(sanitize($_POST['mobile_no'])) : null;
        $assignedDiscipline = isset($_POST['discipline']) ? trim(sanitize($_POST['discipline'])) : null;

        if ($userId <= 0) {
            flash('danger', 'Invalid user request.');
        } else {
            $stmt = $db->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
            $stmt->execute([$userId]);
            $userToApprove = $stmt->fetch();

            if (!$userToApprove) {
                flash('danger', 'User registration record not found.');
            } else {
                $roleId = $assignedRoleId ?: (int)$userToApprove['role_id'];
                $deptId = ($assignedDeptId !== null && $assignedDeptId > 0) ? $assignedDeptId : $userToApprove['department_id'];
                $designation = $assignedDesig ?: $userToApprove['designation'];
                $phone = $assignedPhone !== null ? $assignedPhone : $userToApprove['mobile_no'];
                $discipline = $assignedDiscipline !== null ? $assignedDiscipline : $userToApprove['discipline'];

                try {
                    $now = date('Y-m-d H:i:s');
                    $stmt = $db->prepare("UPDATE users SET status = 'active', role_id = ?, department_id = ?, designation = ?, mobile_no = ?, discipline = ?, updated_at = ? WHERE id = ?");
                    $stmt->execute([$roleId, $deptId, $designation, $phone, $discipline, $now, $userId]);
                } catch (Exception $e) {
                    error_log("Failed to approve user: " . $e->getMessage());
                    flash('danger', "Error activating account: " . e($e->getMessage()));
                    redirect('/joint-director/users.php');
                }

                $roleName = get_role_name_by_id($roleId);
                log_audit($currentUserId, 'USER_REGISTRATION_APPROVED', 'users', $userId, "Joint Director approved registration for {$userToApprove['name']} ({$userToApprove['email']}) as {$roleName}");

                flash('success', "Registration for <strong>" . e($userToApprove['name']) . "</strong> has been <strong>Approved and Activated</strong> as <strong>" . e($roleName) . "</strong>. They can now log in immediately.");
            }
        }
        redirect('/joint-director/users.php');
    }

    // 7. Reject / Decline User Registration
    if ($action === 'reject_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $reason = trim(sanitize($_POST['reason'] ?? ''));

        if ($userId <= 0) {
            flash('danger', 'Invalid user.');
        } else {
            $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $target = $stmt->fetch();

            if ($target) {
                $stmt = $db->prepare("UPDATE users SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$userId]);

                log_audit($currentUserId, 'USER_REGISTRATION_REJECTED', 'users', $userId, "Joint Director declined registration for {$target['name']} ({$target['email']}). " . (!empty($reason) ? "Reason: {$reason}" : ""));
                flash('info', "Registration request for <strong>" . e($target['name']) . "</strong> has been declined.");
            }
        }
        redirect('/joint-director/users.php');
    }
}

// Fetch Filters and Search
$search = sanitize($_GET['q'] ?? '');
$roleFilter = (int)($_GET['role'] ?? 0);
$deptFilter = (int)($_GET['dept'] ?? 0);
$statusFilter = sanitize($_GET['status'] ?? '');

$sql = "SELECT u.*, r.role_name, d.department_name, d.department_code,
               (SELECT COUNT(*) FROM proposals WHERE scientist_id = u.id) as proposals_count,
               (SELECT COUNT(*) FROM projects WHERE scientist_id = u.id) as projects_count
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.designation LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($roleFilter > 0) {
    $sql .= " AND u.role_id = ?";
    $params[] = $roleFilter;
}

if ($deptFilter > 0) {
    $sql .= " AND u.department_id = ?";
    $params[] = $deptFilter;
}

if (!empty($statusFilter)) {
    $sql .= " AND u.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY u.role_id DESC, u.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Fetch pending user approvals
$pendingUsers = $db->query("SELECT u.*, r.role_name, d.department_name, d.department_code
                            FROM users u
                            JOIN roles r ON u.role_id = r.id
                            LEFT JOIN departments d ON u.department_id = d.id
                            WHERE u.status = 'pending'
                            ORDER BY u.created_at DESC")->fetchAll();
$pendingCount = count($pendingUsers);

// Fetch summary metrics
$summaryCounts = [
    'total' => 0,
    'scientists' => 0,
    'hods' => 0,
    'directors' => 0,
    'active' => 0,
    'pending' => $pendingCount
];
$stmt = $db->query("SELECT role_id, status, COUNT(*) as cnt FROM users GROUP BY role_id, status");
foreach ($stmt->fetchAll() as $row) {
    $cnt = (int)$row['cnt'];
    $summaryCounts['total'] += $cnt;
    if ($row['status'] === 'active') {
        $summaryCounts['active'] += $cnt;
    }
    if ((int)$row['role_id'] === ROLE_SCIENTIST) $summaryCounts['scientists'] += $cnt;
    elseif ((int)$row['role_id'] === ROLE_HOD) $summaryCounts['hods'] += $cnt;
    elseif ((int)$row['role_id'] === ROLE_JOINT_DIRECTOR) $summaryCounts['directors'] += $cnt;
}

// Fetch all departments for select dropdowns
$departments = $db->query("SELECT * FROM departments ORDER BY department_name ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">User & Role Administration</h3>
        <p class="text-muted small mb-0">Joint Director central management of Scientists, Heads of Department (HOD), and Directorate personnel</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/joint-director/departments.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-buildings me-1"></i> Divisions & Departments
        </a>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createUserModal" style="background-color: #1a365d; border-color: #1a365d;">
            <i class="bi bi-person-plus-fill me-1"></i> Create New User
        </button>
    </div>
</div>

<?= render_flashes() ?>

<!-- Summary Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-<?php echo ($pendingCount > 0) ? '2' : '3'; ?>">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-primary"></div>
            <span class="text-muted small text-uppercase fw-semibold">Total Accounts</span>
            <div class="fs-3 fw-bold text-dark mt-1"><?= $summaryCounts['total'] ?></div>
            <small class="text-success"><i class="bi bi-check-circle-fill me-1"></i><?= $summaryCounts['active'] ?> Active</small>
        </div>
    </div>
    <?php if ($pendingCount > 0): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3 border border-warning bg-warning-subtle">
            <div class="stat-card-accent bg-warning"></div>
            <span class="text-warning-emphasis small text-uppercase fw-bold">Pending Approvals</span>
            <div class="fs-3 fw-bold text-warning-emphasis mt-1"><?= $pendingCount ?></div>
            <small class="text-danger fw-semibold"><i class="bi bi-exclamation-circle-fill me-1"></i>Awaiting Decision</small>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-<?php echo ($pendingCount > 0) ? '3' : '3'; ?>">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-info"></div>
            <span class="text-muted small text-uppercase fw-semibold">Scientists (PI / Co-PI)</span>
            <div class="fs-3 fw-bold text-info-emphasis mt-1"><?= $summaryCounts['scientists'] ?></div>
            <small class="text-muted">Research Investigators</small>
        </div>
    </div>
    <div class="col-6 col-md-<?php echo ($pendingCount > 0) ? '2' : '3'; ?>">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-success"></div>
            <span class="text-muted small text-uppercase fw-semibold">Heads of Dept</span>
            <div class="fs-3 fw-bold text-success mt-1"><?= $summaryCounts['hods'] ?></div>
            <small class="text-muted">Department Gatekeepers</small>
        </div>
    </div>
    <div class="col-6 col-md-<?php echo ($pendingCount > 0) ? '2' : '3'; ?>">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-dark"></div>
            <span class="text-muted small text-uppercase fw-semibold">Directorate</span>
            <div class="fs-3 fw-bold text-dark mt-1"><?= $summaryCounts['directors'] ?></div>
            <small class="text-muted">Executive Oversight</small>
        </div>
    </div>
</div>

<?php if (!empty($pendingUsers)): ?>
<!-- PENDING REGISTRATION APPROVALS QUEUE -->
<div class="card border-warning shadow-sm mb-4">
    <div class="card-header bg-warning-subtle text-dark py-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-hourglass-split fs-5 text-warning-emphasis"></i>
            <div>
                <h5 class="fw-bold mb-0 text-dark">Pending Registration Requests (<?= $pendingCount ?>)</h5>
                <small class="text-muted">Scientists and Heads of Department who have registered and are waiting for your administrative approval</small>
            </div>
        </div>
        <span class="badge bg-warning text-dark px-3 py-2 fw-bold">Action Required</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 250px;">Applicant & Email</th>
                    <th>Requested Role</th>
                    <th>Department / Division</th>
                    <th>Designation</th>
                    <th>Submitted</th>
                    <th class="text-end" style="min-width: 240px;">Joint Director Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingUsers as $pu): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar-circle bg-warning-subtle text-warning-emphasis me-2 fw-bold rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                    <?= strtoupper(substr($pu['name'], 0, 2)) ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark"><?= e($pu['name']) ?></div>
                                    <div class="text-muted small"><?= e($pu['email']) ?></div>
                                    <?php if (!empty($pu['mobile_no'])): ?>
                                        <div class="text-muted small"><i class="bi bi-telephone me-1"></i><?= e($pu['mobile_no']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ((int)$pu['role_id'] === ROLE_HOD): ?>
                                <span class="badge bg-success-subtle text-success border border-success">
                                    <i class="bi bi-person-check me-1"></i> Head of Dept (HOD)
                                </span>
                            <?php else: ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary">
                                    <i class="bi bi-person-workspace me-1"></i> Faculty / PI
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($pu['department_code'])): ?>
                                <span class="badge bg-light text-dark border me-1 fw-semibold"><?= e($pu['department_code']) ?></span>
                                <span class="small text-secondary"><?= e($pu['department_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted small"><em>None Specified</em></span>
                            <?php endif; ?>
                            <?php if (!empty($pu['discipline'])): ?>
                                <div class="small text-primary mt-1"><i class="bi bi-mortarboard me-1"></i>Discipline: <strong><?= e($pu['discipline']) ?></strong></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="small fw-semibold text-dark"><?= e($pu['designation'] ?: 'Not Specified') ?></span>
                        </td>
                        <td>
                            <span class="small text-muted" title="<?= e($pu['created_at']) ?>">
                                <?= date('d M Y, h:i A', strtotime($pu['created_at'])) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="d-flex justify-content-end gap-1">
                                <!-- Fast 1-Click Approve -->
                                <form method="POST" action="<?= url("/joint-director/users.php") ?>" class="d-inline" onsubmit="return confirm('Approve and immediately activate <?= e(addslashes($pu['name'])) ?> as <?= e(addslashes($pu['role_name'])) ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="approve_user">
                                    <input type="hidden" name="user_id" value="<?= $pu['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success fw-semibold">
                                        <i class="bi bi-check-circle-fill me-1"></i> Approve
                                    </button>
                                </form>

                                <!-- Review & Modify modal trigger -->
                                <button type="button" class="btn btn-sm btn-outline-primary" title="Review & Adjust Department / Role"
                                        data-bs-toggle="modal"
                                        data-bs-target="#reviewPendingModal"
                                        data-id="<?= $pu['id'] ?>"
                                        data-name="<?= e($pu['name']) ?>"
                                        data-email="<?= e($pu['email']) ?>"
                                        data-phone="<?= e($pu['mobile_no'] ?? '') ?>"
                                        data-discipline="<?= e($pu['discipline'] ?? '') ?>"
                                        data-role="<?= $pu['role_id'] ?>"
                                        data-dept="<?= $pu['department_id'] ?? '' ?>"
                                        data-designation="<?= e($pu['designation']) ?>">
                                    <i class="bi bi-sliders me-1"></i> Review
                                </button>

                                <!-- Decline / Reject -->
                                <form method="POST" action="<?= url("/joint-director/users.php") ?>" class="d-inline" onsubmit="return confirm('Decline registration request for <?= e(addslashes($pu['name'])) ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="reject_user">
                                    <input type="hidden" name="user_id" value="<?= $pu['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Decline Registration">
                                        <i class="bi bi-x-circle"></i> Decline
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Search & Filter Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?= url("/joint-director/users.php") ?>" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Search by name, email, designation..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="role" class="form-select form-select-sm">
                    <option value="">All Roles</option>
                    <option value="<?= ROLE_SCIENTIST ?>" <?= $roleFilter === ROLE_SCIENTIST ? 'selected' : '' ?>>Scientist</option>
                    <option value="<?= ROLE_HOD ?>" <?= $roleFilter === ROLE_HOD ? 'selected' : '' ?>>Head of Dept (HOD)</option>
                    <option value="<?= ROLE_JOINT_DIRECTOR ?>" <?= $roleFilter === ROLE_JOINT_DIRECTOR ? 'selected' : '' ?>>Joint Director</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= e($d['department_code']) ?> - <?= e($d['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending Approval (<?= $pendingCount ?>)</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Declined Only</option>
                </select>
            </div>
            <div class="col-6 col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-dark w-100"><i class="bi bi-funnel"></i></button>
                <?php if (!empty($search) || $roleFilter > 0 || $deptFilter > 0 || !empty($statusFilter)): ?>
                    <a href="<?= url("/joint-director/users.php") ?>" class="btn btn-sm btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Users Table Card -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="bi bi-people-fill text-primary me-2"></i> Registered Personnel Directory (<?= count($users) ?>)
        </h5>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
            <i class="bi bi-plus-lg me-1"></i> Add User
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 250px;">User & Email</th>
                    <th>Role</th>
                    <th>Department / Division</th>
                    <th>Designation</th>
                    <th>Research Activity</th>
                    <th>Status</th>
                    <th class="text-end" style="min-width: 170px;">Admin Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-people fs-1 d-block mb-2"></i>
                            No users found matching your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center fw-bold me-2 text-primary" style="width: 38px; height: 38px; font-size: 0.9rem;">
                                        <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark">
                                            <?= e($u['name']) ?>
                                            <?php if ((int)$u['id'] === $currentUserId): ?>
                                                <span class="badge bg-secondary-subtle text-secondary ms-1" style="font-size: 0.65rem;">You</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted small"><?= e($u['email']) ?></div>
                                        <?php if (!empty($u['mobile_no'])): ?>
                                            <div class="text-muted small"><i class="bi bi-telephone me-1"></i><?= e($u['mobile_no']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ((int)$u['role_id'] === ROLE_JOINT_DIRECTOR): ?>
                                    <span class="badge bg-warning-subtle text-dark border border-warning">
                                        <i class="bi bi-shield-shaded me-1"></i> Joint Director
                                    </span>
                                <?php elseif ((int)$u['role_id'] === ROLE_HOD): ?>
                                    <span class="badge bg-success-subtle text-success border border-success">
                                        <i class="bi bi-person-check me-1"></i> Head of Dept
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary">
                                        <i class="bi bi-person-workspace me-1"></i> Faculty / PI
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($u['department_code'])): ?>
                                    <span class="badge bg-light text-dark border me-1 fw-semibold"><?= e($u['department_code']) ?></span>
                                    <span class="small text-secondary"><?= e($u['department_name']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small"><em>Institute Administration</em></span>
                                <?php endif; ?>
                                <?php if (!empty($u['discipline'])): ?>
                                    <div class="small text-primary mt-1"><i class="bi bi-mortarboard me-1"></i>Discipline: <strong><?= e($u['discipline']) ?></strong></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="small fw-semibold text-dark"><?= e($u['designation']) ?></span>
                            </td>
                            <td>
                                <div class="small">
                                    <span class="text-primary fw-semibold"><?= (int)$u['proposals_count'] ?></span> <span class="text-muted">Proposals</span>
                                    <span class="mx-1 text-muted">|</span>
                                    <span class="text-success fw-semibold"><?= (int)$u['projects_count'] ?></span> <span class="text-muted">Projects</span>
                                </div>
                            </td>
                            <td>
                                <?php if ($u['status'] === 'pending'): ?>
                                    <span class="badge bg-warning text-dark border border-warning">
                                        <i class="bi bi-hourglass-split me-1"></i> Pending Approval
                                    </span>
                                <?php elseif ($u['status'] === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success">
                                        <i class="bi bi-check-circle-fill me-1"></i> Active
                                    </span>
                                <?php elseif ($u['status'] === 'rejected'): ?>
                                    <span class="badge bg-danger-subtle text-danger">
                                        <i class="bi bi-x-circle-fill me-1"></i> Declined
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary">
                                        <i class="bi bi-dash-circle-fill me-1"></i> Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($u['status'] === 'pending'): ?>
                                    <div class="d-flex justify-content-end gap-1">
                                        <!-- Fast 1-Click Approve -->
                                        <form method="POST" action="<?= url("/joint-director/users.php") ?>" class="d-inline" onsubmit="return confirm('Approve and immediately activate <?= e(addslashes($u['name'])) ?> as <?= e(addslashes($u['role_name'])) ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="approve_user">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-success fw-semibold" title="Approve Registration">
                                                <i class="bi bi-check-circle-fill me-1"></i> Approve
                                            </button>
                                        </form>

                                        <!-- Review & Modify modal trigger -->
                                        <button type="button" class="btn btn-sm btn-outline-primary" title="Review & Adjust Department / Role"
                                                data-bs-toggle="modal"
                                                data-bs-target="#reviewPendingModal"
                                                data-id="<?= $u['id'] ?>"
                                                data-name="<?= e($u['name']) ?>"
                                                data-email="<?= e($u['email']) ?>"
                                                data-phone="<?= e($u['mobile_no'] ?? '') ?>"
                                                data-discipline="<?= e($u['discipline'] ?? '') ?>"
                                                data-role="<?= $u['role_id'] ?>"
                                                data-dept="<?= $u['department_id'] ?? '' ?>"
                                                data-designation="<?= e($u['designation']) ?>">
                                            <i class="bi bi-sliders"></i>
                                        </button>

                                        <!-- Decline / Reject -->
                                        <form method="POST" action="<?= url("/joint-director/users.php") ?>" class="d-inline" onsubmit="return confirm('Decline registration request for <?= e(addslashes($u['name'])) ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="reject_user">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Decline Registration">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="btn-group btn-group-sm">
                                        <!-- Quick Switch / Impersonate for quick testing -->
                                        <a href="<?= url("/switch-role.php?user_id=" . $u['id']) ?>" class="btn btn-outline-secondary" title="Login / Switch to this user for evaluation">
                                            <i class="bi bi-box-arrow-in-right"></i>
                                        </a>

                                        <!-- Edit User Button -->
                                        <button type="button" class="btn btn-outline-primary" title="Edit Profile"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editUserModal"
                                                data-id="<?= $u['id'] ?>"
                                                data-name="<?= e($u['name']) ?>"
                                                data-email="<?= e($u['email']) ?>"
                                                data-phone="<?= e($u['mobile_no'] ?? '') ?>"
                                                data-discipline="<?= e($u['discipline'] ?? '') ?>"
                                                data-role="<?= $u['role_id'] ?>"
                                                data-dept="<?= $u['department_id'] ?? '' ?>"
                                                data-designation="<?= e($u['designation']) ?>"
                                                data-status="<?= $u['status'] ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <!-- Reset Password Button -->
                                        <button type="button" class="btn btn-outline-secondary" title="Reset Password"
                                                data-bs-toggle="modal"
                                                data-bs-target="#resetPasswordModal"
                                                data-id="<?= $u['id'] ?>"
                                                data-name="<?= e($u['name']) ?>">
                                            <i class="bi bi-key"></i>
                                        </button>

                                        <!-- Toggle Status Button -->
                                        <?php if ((int)$u['id'] !== $currentUserId): ?>
                                            <form method="POST" action="<?= url("/joint-director/users.php") ?>" class="d-inline" onsubmit="return confirm('Change status for <?= e(addslashes($u['name'])) ?>?');">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <?php if ($u['status'] === 'active'): ?>
                                                    <button type="submit" class="btn btn-outline-warning" title="Deactivate User">
                                                        <i class="bi bi-pause-circle"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-outline-success" title="Activate User">
                                                        <i class="bi bi-play-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>

                                            <!-- Delete User -->
                                            <form method="POST" action="<?= url("/joint-director/users.php") ?>" class="d-inline" onsubmit="return confirm('Are you sure you want to delete/deactivate <?= e(addslashes($u['name'])) ?>?');">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Delete User">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: CREATE USER -->
<div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= url("/joint-director/users.php") ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="create_user">

                <div class="modal-header bg-primary text-white" style="background-color: #1a365d !important;">
                    <h5 class="modal-title fw-bold" id="createUserModalLabel">
                        <i class="bi bi-person-plus-fill me-2"></i> Create User Account
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">
                        Register a new Scientist (PI), Head of Department (HOD), or Directorate Administrator. The user will be able to immediately log into NDRI PRIME.
                    </p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Full Name & Title <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g., Dr. Vikram Singh" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Institutional Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="e.g., vikram.singh@icar.org.in" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">System Role <span class="text-danger">*</span></label>
                            <select name="role_id" id="createRoleSelect" class="form-select" required onchange="handleRoleChange(this, 'createDeptSelect')">
                                <option value="<?= ROLE_SCIENTIST ?>" selected>Faculty / PI (PI/Co-PI)</option>
                                <option value="<?= ROLE_HOD ?>">Head of Department (HOD)</option>
                                <option value="<?= ROLE_JOINT_DIRECTOR ?>">Joint Director (Admin)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">Department / Division</label>
                            <select name="department_id" id="createDeptSelect" class="form-select" onchange="handleUserDeptChange(this, 'createDisciplineContainer', 'createDisciplineInput')">
                                <option value="" data-code="">Select Department...</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" data-code="<?= e($d['department_code']) ?>"><?= e($d['department_code']) ?> - <?= e($d['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Official Designation</label>
                        <input type="text" name="designation" class="form-control" placeholder="e.g., Senior Scientist, Principal Scientist, Division Head">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small"><i class="bi bi-telephone me-1"></i> Phone / Mobile No.</label>
                            <input type="tel" name="mobile_no" class="form-control" placeholder="e.g., 9876543210" maxlength="15">
                        </div>
                        <div class="col-md-6" id="createDisciplineContainer" style="display: none;">
                            <label class="form-label fw-semibold text-dark small"><i class="bi bi-mortarboard me-1"></i> Discipline</label>
                            <input type="text" name="discipline" id="createDisciplineInput" class="form-control" placeholder="e.g., Dairy Microbiology">
                            <div class="form-text small text-muted">Required for ERS and SRS Regional Stations.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small d-flex justify-content-between">
                            <span>Initial Password <span class="text-danger">*</span></span>
                            <a href="javascript:void(0)" class="text-decoration-none small" onclick="generateRandomPass('createPassInput')">Generate Secure Pass</a>
                        </label>
                        <div class="input-group">
                            <input type="text" name="password" id="createPassInput" class="form-control font-monospace" value="password123" required minlength="6">
                            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('createPassInput').value='password123'">Default</button>
                        </div>
                        <div class="form-text">Min 6 characters. Default is <code>password123</code>.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Account Status</label>
                        <select name="status" class="form-select">
                            <option value="active" selected>Active (Can login immediately)</option>
                            <option value="inactive">Inactive (Suspended)</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background-color: #1a365d; border-color: #1a365d;">
                        <i class="bi bi-check-lg me-1"></i> Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: EDIT USER -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= url("/joint-director/users.php") ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="editUserId">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold" id="editUserModalLabel">
                        <i class="bi bi-pencil-square me-2"></i> Edit User Profile
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editUserName" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Institutional Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="editUserEmail" class="form-control" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">System Role <span class="text-danger">*</span></label>
                            <select name="role_id" id="editUserRole" class="form-select" required>
                                <option value="<?= ROLE_SCIENTIST ?>">Faculty / PI</option>
                                <option value="<?= ROLE_HOD ?>">Head of Dept (HOD)</option>
                                <option value="<?= ROLE_JOINT_DIRECTOR ?>">Joint Director</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">Department / Division</label>
                            <select name="department_id" id="editUserDept" class="form-select" onchange="handleUserDeptChange(this, 'editDisciplineContainer', 'editUserDiscipline')">
                                <option value="" data-code="">None / Administrative</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" data-code="<?= e($d['department_code']) ?>"><?= e($d['department_code']) ?> - <?= e($d['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Designation</label>
                        <input type="text" name="designation" id="editUserDesignation" class="form-control">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">
                                <i class="bi bi-telephone me-1 text-primary"></i> Phone / Mobile Number
                            </label>
                            <input type="tel" name="mobile_no" id="editUserPhone" class="form-control" placeholder="e.g., 9876543210" maxlength="15">
                            <div class="form-text text-muted small">Joint Director editable</div>
                        </div>
                        <div class="col-md-6" id="editDisciplineContainer" style="display: none;">
                            <label class="form-label fw-semibold text-dark small">
                                <i class="bi bi-mortarboard me-1 text-primary"></i> Discipline
                            </label>
                            <input type="text" name="discipline" id="editUserDiscipline" class="form-control" placeholder="e.g., Dairy Microbiology">
                            <div class="form-text small text-muted">Applicable for ERS and SRS.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Change Password (Leave blank to keep existing)</label>
                        <input type="password" name="new_password" class="form-control" placeholder="Enter new password if changing" minlength="6">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Status</label>
                        <select name="status" id="editUserStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background-color: #1a365d; border-color: #1a365d;">
                        <i class="bi bi-save me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: RESET PASSWORD -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= url("/joint-director/users.php") ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">

                <div class="modal-header bg-warning-subtle text-dark">
                    <h6 class="modal-title fw-bold" id="resetPasswordModalLabel">
                        <i class="bi bi-key-fill text-warning me-2"></i> Reset Password
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-3">
                    <p class="small text-muted mb-2">Reset password for <strong id="resetUserName" class="text-dark"></strong>:</p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">New Password</label>
                        <input type="text" name="new_password" id="resetPassInput" class="form-control font-monospace" value="password123" required minlength="6">
                    </div>
                </div>

                <div class="modal-footer bg-light p-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning fw-semibold">
                        <i class="bi bi-check-lg me-1"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: REVIEW & APPROVE PENDING REGISTRATION -->
<div class="modal fade" id="reviewPendingModal" tabindex="-1" aria-labelledby="reviewPendingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= url("/joint-director/users.php") ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="approve_user">
                <input type="hidden" name="user_id" id="reviewUserId">

                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold" id="reviewPendingModalLabel">
                        <i class="bi bi-shield-check me-2"></i> Review & Approve Registration
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="alert alert-info py-2 px-3 small mb-3">
                        <i class="bi bi-info-circle-fill me-1"></i> As Joint Director, you can verify or adjust the applicant's assigned role and department before granting active institutional access.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Applicant Name</label>
                        <input type="text" id="reviewUserName" class="form-control bg-light" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Institutional Email</label>
                        <input type="email" id="reviewUserEmail" class="form-control bg-light" readonly>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">Assigned Role <span class="text-danger">*</span></label>
                            <select name="role_id" id="reviewUserRole" class="form-select" required>
                                <option value="<?= ROLE_SCIENTIST ?>">Faculty / PI (PI / Co-PI)</option>
                                <option value="<?= ROLE_HOD ?>">Head of Dept (HOD)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small">Department / Division <span class="text-danger">*</span></label>
                            <select name="department_id" id="reviewUserDept" class="form-select" required onchange="handleUserDeptChange(this, 'reviewDisciplineContainer', 'reviewUserDiscipline')">
                                <option value="" data-code="">Select Division</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" data-code="<?= e($d['department_code']) ?>"><?= e($d['department_code']) ?> - <?= e($d['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-dark small">Designation Title</label>
                        <input type="text" name="designation" id="reviewUserDesignation" class="form-control" placeholder="e.g. Principal Scientist, Senior Scientist">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-dark small"><i class="bi bi-telephone me-1 text-primary"></i> Phone / Mobile No.</label>
                            <input type="tel" name="mobile_no" id="reviewUserPhone" class="form-control" placeholder="e.g. 9876543210" maxlength="15">
                        </div>
                        <div class="col-md-6" id="reviewDisciplineContainer" style="display: none;">
                            <label class="form-label fw-semibold text-dark small"><i class="bi bi-mortarboard me-1 text-primary"></i> Discipline</label>
                            <input type="text" name="discipline" id="reviewUserDiscipline" class="form-control" placeholder="e.g. Dairy Microbiology">
                            <div class="form-text small text-muted">Applicable for ERS and SRS.</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-semibold">
                        <i class="bi bi-check-circle-fill me-1"></i> Approve & Activate Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function handleRoleChange(roleSelect, deptSelectId) {
    const roleId = parseInt(roleSelect.value);
    const deptSelect = document.getElementById(deptSelectId);
    // If HOD or Scientist, department is strongly encouraged
    if (roleId === 1 || roleId === 2) {
        deptSelect.required = true;
    } else {
        deptSelect.required = false;
    }
}

function generateRandomPass(inputId) {
    const chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%";
    let pass = "";
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById(inputId).value = pass;
}

function handleUserDeptChange(deptSelect, containerId, inputId) {
    if (!deptSelect) return;
    const container = document.getElementById(containerId);
    const input = inputId ? document.getElementById(inputId) : null;
    if (!container) return;

    const selectedOption = deptSelect.options[deptSelect.selectedIndex];
    const deptCode = (selectedOption ? (selectedOption.getAttribute('data-code') || '') : '').toUpperCase().trim();
    const optText = (selectedOption ? (selectedOption.text || '') : '').toUpperCase();

    const isRegional = (deptCode === 'SRS' || deptCode === 'ERS' || optText.includes('(SRS)') || optText.includes('(ERS)') || optText.includes('SRS') || optText.includes('ERS'));

    if (isRegional) {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
        if (input && inputId === 'createDisciplineInput') {
            input.value = '';
        }
    }
}

// Edit Modal Data Binding
const editUserModal = document.getElementById('editUserModal');
if (editUserModal) {
    editUserModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        document.getElementById('editUserId').value = button.getAttribute('data-id');
        document.getElementById('editUserName').value = button.getAttribute('data-name');
        document.getElementById('editUserEmail').value = button.getAttribute('data-email');
        document.getElementById('editUserRole').value = button.getAttribute('data-role');
        const deptSelect = document.getElementById('editUserDept');
        deptSelect.value = button.getAttribute('data-dept');
        document.getElementById('editUserDesignation').value = button.getAttribute('data-designation') || '';
        document.getElementById('editUserPhone').value = button.getAttribute('data-phone') || '';
        document.getElementById('editUserDiscipline').value = button.getAttribute('data-discipline') || '';
        document.getElementById('editUserStatus').value = button.getAttribute('data-status');
        
        handleUserDeptChange(deptSelect, 'editDisciplineContainer', 'editUserDiscipline');
    });
}

// Review Pending Modal Data Binding
const reviewPendingModal = document.getElementById('reviewPendingModal');
if (reviewPendingModal) {
    reviewPendingModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        document.getElementById('reviewUserId').value = button.getAttribute('data-id');
        document.getElementById('reviewUserName').value = button.getAttribute('data-name');
        document.getElementById('reviewUserEmail').value = button.getAttribute('data-email');
        document.getElementById('reviewUserRole').value = button.getAttribute('data-role');
        const deptSelect = document.getElementById('reviewUserDept');
        deptSelect.value = button.getAttribute('data-dept');
        document.getElementById('reviewUserDesignation').value = button.getAttribute('data-designation') || '';
        document.getElementById('reviewUserPhone').value = button.getAttribute('data-phone') || '';
        document.getElementById('reviewUserDiscipline').value = button.getAttribute('data-discipline') || '';
        
        handleUserDeptChange(deptSelect, 'reviewDisciplineContainer', 'reviewUserDiscipline');
    });
}

// Reset Password Modal Data Binding
const resetPasswordModal = document.getElementById('resetPasswordModal');
if (resetPasswordModal) {
    resetPasswordModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        document.getElementById('resetUserId').value = button.getAttribute('data-id');
        document.getElementById('resetUserName').textContent = button.getAttribute('data-name');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const createDept = document.getElementById('createDeptSelect');
    if (createDept) {
        handleUserDeptChange(createDept, 'createDisciplineContainer', 'createDisciplineInput');
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
