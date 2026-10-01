<?php
/**
 * Research Proposal and Project Management System
 * Joint Director - Department & Division Administration
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_JOINT_DIRECTOR);

$pageTitle = 'Department & Division Management - JD';
$db = get_db();
$currentUserId = current_user_id();

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Create Department
    if ($action === 'create_department') {
        $rawCode = trim(sanitize($_POST['department_code'] ?? ''));
        $code = (preg_match('/^[a-zA-Z0-9]+$/', $rawCode)) ? strtoupper($rawCode) : $rawCode;
        $name = trim(sanitize($_POST['department_name'] ?? ''));

        if (empty($code) || empty($name)) {
            flash('danger', 'Both Department Code and Department Name are required.');
        } else {
            $stmt = $db->prepare("SELECT id FROM departments WHERE UPPER(department_code) = UPPER(?)");
            $stmt->execute([$code]);
            if ($stmt->fetch()) {
                flash('danger', "A department with code '{$code}' already exists.");
            } else {
                $stmt = $db->prepare("INSERT INTO departments (department_code, department_name) VALUES (?, ?)");
                $stmt->execute([$code, $name]);
                $newDeptId = (int)$db->lastInsertId();

                log_audit($currentUserId, 'DEPARTMENT_CREATED', 'departments', $newDeptId, "Created department {$code} - {$name}");
                flash('success', "Department <strong>" . e($code) . " (" . e($name) . ")</strong> created successfully.");
            }
        }
        redirect('/joint-director/departments.php');
    }

    // 2. Edit Department
    if ($action === 'edit_department') {
        $deptId = (int)($_POST['department_id'] ?? 0);
        $rawCode = trim(sanitize($_POST['department_code'] ?? ''));
        $code = (preg_match('/^[a-zA-Z0-9]+$/', $rawCode)) ? strtoupper($rawCode) : $rawCode;
        $name = trim(sanitize($_POST['department_name'] ?? ''));

        if ($deptId <= 0 || empty($code) || empty($name)) {
            flash('danger', 'Valid department information is required.');
        } else {
            $stmt = $db->prepare("SELECT id FROM departments WHERE UPPER(department_code) = UPPER(?) AND id != ?");
            $stmt->execute([$code, $deptId]);
            if ($stmt->fetch()) {
                flash('danger', "Another department is already using code '{$code}'.");
            } else {
                $stmt = $db->prepare("UPDATE departments SET department_code = ?, department_name = ? WHERE id = ?");
                $stmt->execute([$code, $name, $deptId]);

                log_audit($currentUserId, 'DEPARTMENT_UPDATED', 'departments', $deptId, "Updated department {$code} - {$name}");
                flash('success', "Department <strong>" . e($code) . "</strong> updated successfully.");
            }
        }
        redirect('/joint-director/departments.php');
    }

    // 3. Delete Department
    if ($action === 'delete_department') {
        $deptId = (int)($_POST['department_id'] ?? 0);
        if ($deptId > 0) {
            // Check associated users
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
            $stmt->execute([$deptId]);
            $userCount = (int)$stmt->fetchColumn();

            // Check associated proposals
            $stmt = $db->prepare("SELECT COUNT(*) FROM proposals WHERE department_id = ?");
            $stmt->execute([$deptId]);
            $propCount = (int)$stmt->fetchColumn();

            if ($userCount > 0 || $propCount > 0) {
                flash('danger', "Cannot delete department: {$userCount} user(s) and {$propCount} proposal(s) are currently associated with it.");
            } else {
                $stmt = $db->prepare("DELETE FROM departments WHERE id = ?");
                $stmt->execute([$deptId]);
                log_audit($currentUserId, 'DEPARTMENT_DELETED', 'departments', $deptId, "Deleted department ID {$deptId}");
                flash('success', "Department deleted successfully.");
            }
        }
        redirect('/joint-director/departments.php');
    }
}

// Fetch all departments with aggregated statistics
$sql = "SELECT d.*,
               (SELECT COUNT(*) FROM users WHERE department_id = d.id AND role_id = " . ROLE_SCIENTIST . ") as scientists_count,
               (SELECT COUNT(*) FROM users WHERE department_id = d.id AND role_id = " . ROLE_HOD . ") as hods_count,
               (SELECT u.name FROM users u WHERE u.department_id = d.id AND u.role_id = " . ROLE_HOD . " AND u.status = 'active' LIMIT 1) as active_hod_name,
               (SELECT COUNT(*) FROM proposals WHERE department_id = d.id) as proposals_count,
               (SELECT COUNT(*) FROM projects pr JOIN proposals p ON pr.proposal_id = p.id WHERE p.department_id = d.id AND pr.project_status = 'Active') as active_projects_count
        FROM departments d
        ORDER BY d.department_name ASC";
$departments = $db->query($sql)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Divisions & Departments Management</h3>
        <p class="text-muted small mb-0">Configure research divisions, view assigned Heads of Department (HOD), and track division metrics</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/joint-director/users.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-people me-1"></i> User Directory
        </a>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createDeptModal" style="background-color: #1a365d; border-color: #1a365d;">
            <i class="bi bi-plus-lg me-1"></i> Add New Division / Dept
        </button>
    </div>
</div>

<?= render_flashes() ?>

<!-- Department Cards / Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="bi bi-buildings text-primary me-2"></i> Institute Divisions (<?= count($departments) ?>)
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 100px;">Code</th>
                    <th>Department / Division Name</th>
                    <th>Assigned Head of Department</th>
                    <th>Scientists</th>
                    <th>Proposals</th>
                    <th>Active Projects</th>
                    <th class="text-end" style="min-width: 130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($departments)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">No departments configured yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($departments as $dept): ?>
                        <tr>
                            <td>
                                <span class="badge bg-primary text-white fs-6 font-monospace px-2 py-1"><?= e($dept['department_code']) ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= e($dept['department_name']) ?></div>
                            </td>
                            <td>
                                <?php if (!empty($dept['active_hod_name'])): ?>
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-person-check-fill text-success me-2"></i>
                                        <span class="fw-semibold text-dark"><?= e($dept['active_hod_name']) ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis">
                                        <i class="bi bi-exclamation-triangle me-1"></i> No Active HOD
                                    </span>
                                    <a href="<?= url("/joint-director/users.php?role=" . ROLE_HOD . "&dept=" . $dept['id']) ?>" class="btn btn-xs btn-link p-0 ms-1 small">Assign HOD</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info-subtle text-info-emphasis px-2 py-1">
                                    <i class="bi bi-person me-1"></i><?= (int)$dept['scientists_count'] ?> Scientists
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border px-2 py-1">
                                    <?= (int)$dept['proposals_count'] ?> Proposals
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-success-subtle text-success px-2 py-1">
                                    <?= (int)$dept['active_projects_count'] ?> Active
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= url("/joint-director/users.php?dept=" . $dept['id']) ?>" class="btn btn-outline-secondary" title="View Users in this Department">
                                        <i class="bi bi-people"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-primary" title="Edit Department"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editDeptModal"
                                            data-id="<?= $dept['id'] ?>"
                                            data-code="<?= e($dept['department_code']) ?>"
                                            data-name="<?= e($dept['department_name']) ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <?php if ((int)$dept['scientists_count'] === 0 && (int)$dept['proposals_count'] === 0): ?>
                                        <form method="POST" action="<?= url("/joint-director/departments.php") ?>" class="d-inline" onsubmit="return confirm('Delete department <?= e(addslashes($dept['department_code'])) ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete_department">
                                            <input type="hidden" name="department_id" value="<?= $dept['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Delete Department">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: ADD DEPARTMENT -->
<div class="modal fade" id="createDeptModal" tabindex="-1" aria-labelledby="createDeptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= url("/joint-director/departments.php") ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="create_department">

                <div class="modal-header bg-primary text-white" style="background-color: #1a365d !important;">
                    <h5 class="modal-title fw-bold" id="createDeptModalLabel">
                        <i class="bi bi-buildings-fill me-2"></i> Add Research Department / Division
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-dark">Department Code / Acronym <span class="text-danger">*</span></label>
                        <input type="text" name="department_code" class="form-control font-monospace text-uppercase" placeholder="e.g., ANBI, DT, DCB, LPM" required maxlength="15">
                        <div class="form-text">Short unique division acronym.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-dark">Full Department / Division Name <span class="text-danger">*</span></label>
                        <input type="text" name="department_name" class="form-control" placeholder="e.g., Animal Biochemistry Division" required>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background-color: #1a365d; border-color: #1a365d;">
                        <i class="bi bi-check-lg me-1"></i> Save Department
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: EDIT DEPARTMENT -->
<div class="modal fade" id="editDeptModal" tabindex="-1" aria-labelledby="editDeptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="<?= url("/joint-director/departments.php") ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="edit_department">
                <input type="hidden" name="department_id" id="editDeptId">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold" id="editDeptModalLabel">
                        <i class="bi bi-pencil-square me-2"></i> Edit Department
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-dark">Department Code <span class="text-danger">*</span></label>
                        <input type="text" name="department_code" id="editDeptCode" class="form-control font-monospace text-uppercase" required maxlength="15">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-dark">Department Name <span class="text-danger">*</span></label>
                        <input type="text" name="department_name" id="editDeptName" class="form-control" required>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background-color: #1a365d; border-color: #1a365d;">
                        <i class="bi bi-save me-1"></i> Update Department
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const editDeptModal = document.getElementById('editDeptModal');
if (editDeptModal) {
    editDeptModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        document.getElementById('editDeptId').value = button.getAttribute('data-id');
        document.getElementById('editDeptCode').value = button.getAttribute('data-code');
        document.getElementById('editDeptName').value = button.getAttribute('data-name');
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
