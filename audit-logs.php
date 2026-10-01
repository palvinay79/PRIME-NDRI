<?php
/**
 * Research Proposal and Project Management System
 * Immutable System Audit Logs
 * 
 * Access Control:
 * - Only Joint Director can view audit logs of all roles (Scientists, HODs, JD, and System).
 * - HODs can view their own audit logs.
 * - Scientists can view their own audit logs.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';

require_login();

flash('info', 'System audit logs have been disabled.');
header("Location: " . url("/"));
exit;

$search = sanitize($_GET['q'] ?? '');
$actionFilter = sanitize($_GET['action'] ?? '');
$roleFilter = ($isJointDirector && isset($_GET['role']) && is_numeric($_GET['role'])) ? (int)$_GET['role'] : null;

$sql = "SELECT a.*, u.name as user_name, u.email as user_email, u.role_id, d.department_name
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE 1=1";
$params = [];

// CRITICAL ACCESS CONTROL:
// Only Joint Director can view audit logs of all roles.
// HOD and Scientist can strictly only view their own audit logs.
if (!$isJointDirector) {
    $sql .= " AND a.user_id = ?";
    $params[] = $currentUserId;
} else {
    if ($roleFilter !== null && $roleFilter > 0) {
        $sql .= " AND u.role_id = ?";
        $params[] = $roleFilter;
    }
}

if (!empty($search)) {
    if ($isJointDirector) {
        $sql .= " AND (a.action LIKE ? OR a.details LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR a.ip_address LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    } else {
        $sql .= " AND (a.action LIKE ? OR a.details LIKE ? OR a.ip_address LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }
}

if (!empty($actionFilter)) {
    $sql .= " AND a.action = ?";
    $params[] = $actionFilter;
}

$sql .= " ORDER BY a.created_at DESC LIMIT 200";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get unique actions for filter:
// For Joint Director: all distinct actions across the system
// For HOD / Scientist: only distinct actions for their own audit records
if ($isJointDirector) {
    $actionsStmt = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
    $allActions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $actionsStmt = $db->prepare("SELECT DISTINCT action FROM audit_logs WHERE user_id = ? ORDER BY action ASC");
    $actionsStmt->execute([$currentUserId]);
    $allActions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h3 class="fw-bold mb-0">
                <i class="bi bi-shield-check text-primary me-2"></i>
                <?php if ($isJointDirector): ?>
                    Institutional Audit Logs (All Roles)
                <?php elseif ($currentRoleId === ROLE_HOD): ?>
                    Head of Department (HOD) Audit Logs
                <?php else: ?>
                    My Audit Logs
                <?php endif; ?>
            </h3>
            <?php if ($isJointDirector): ?>
                <span class="badge bg-primary text-white" style="font-size: 0.75rem;">
                    <i class="bi bi-eye-fill me-1"></i> Full Access (All Roles)
                </span>
            <?php elseif ($currentRoleId === ROLE_HOD): ?>
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size: 0.75rem;">
                    <i class="bi bi-person-badge me-1"></i> HOD Activity
                </span>
            <?php else: ?>
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle" style="font-size: 0.75rem;">
                    <i class="bi bi-person-check me-1"></i> Scientist Activity
                </span>
            <?php endif; ?>
        </div>
        <p class="text-muted small mb-0">
            <?php if ($isJointDirector): ?>
                Comprehensive immutable audit trail across Scientists, Heads of Department, and Directorate operations.
            <?php elseif ($currentRoleId === ROLE_HOD): ?>
                Immutable record of your proposal reviews, endorsements, and account authentication activity.
            <?php else: ?>
                Immutable record of your proposal submissions, project filings, and account authentication activity.
            <?php endif; ?>
        </p>
    </div>
    <div class="text-muted small">
        <span class="badge bg-light text-dark border px-3 py-2">
            <i class="bi bi-clock-history me-1 text-primary"></i> <strong><?= count($logs) ?></strong> record<?= count($logs) === 1 ? '' : 's' ?> listed
        </span>
    </div>
</div>

<?php if (!$isJointDirector): ?>
    <div class="alert alert-light border border-info-subtle shadow-sm py-2 px-3 small mb-3 d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill text-info fs-5"></i>
        <div>
            You are viewing your personal audit trail. Comprehensive institutional audit logs across all roles are reserved for the Joint Director.
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= url("/audit-logs.php") ?>" class="row g-2 align-items-center">
            <div class="<?= $isJointDirector ? 'col-md-4' : 'col-md-6' ?>">
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="<?= $isJointDirector ? 'Search user, action, details, IP...' : 'Search your actions, details, IP...' ?>" value="<?= e($search) ?>">
                </div>
            </div>

            <?php if ($isJointDirector): ?>
                <div class="col-md-3">
                    <select name="role" class="form-select">
                        <option value="">-- All Roles --</option>
                        <option value="<?= ROLE_SCIENTIST ?>" <?= $roleFilter === ROLE_SCIENTIST ? 'selected' : '' ?>>Scientists</option>
                        <option value="<?= ROLE_HOD ?>" <?= $roleFilter === ROLE_HOD ? 'selected' : '' ?>>Heads of Department (HOD)</option>
                        <option value="<?= ROLE_JOINT_DIRECTOR ?>" <?= $roleFilter === ROLE_JOINT_DIRECTOR ? 'selected' : '' ?>>Joint Director</option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="<?= $isJointDirector ? 'col-md-3' : 'col-md-4' ?>">
                <select name="action" class="form-select">
                    <option value="">-- All Actions (<?= count($allActions) ?>) --</option>
                    <?php foreach ($allActions as $act): ?>
                        <option value="<?= e($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>><?= e($act) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="<?= $isJointDirector ? 'col-md-2' : 'col-md-2' ?> d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100" title="Filter"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="<?= url("/audit-logs.php") ?>" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 170px;">Timestamp</th>
                    <th style="width: 180px;">Action</th>
                    <th style="width: 210px;">Performed By</th>
                    <th style="width: 130px;">Entity</th>
                    <th>Details & Remarks</th>
                    <th style="width: 110px;">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-shield-slash fs-2 d-block mb-2 text-secondary"></i>
                            <div>No matching audit records found.</div>
                            <small class="text-muted">Try clearing filters or search terms.</small>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="small text-muted font-monospace"><?= format_datetime($log['created_at']) ?></td>
                            <td>
                                <span class="badge bg-secondary-subtle text-dark border font-monospace" style="font-size: 0.75rem;">
                                    <?= e($log['action']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($log['user_name'])): ?>
                                    <div class="fw-semibold text-dark"><?= e($log['user_name']) ?></div>
                                    <div class="d-flex align-items-center gap-1 mt-1">
                                        <?php
                                        $uRole = (int)($log['role_id'] ?? 0);
                                        if ($uRole === ROLE_JOINT_DIRECTOR) {
                                            echo '<span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.68rem;">Joint Director</span>';
                                        } elseif ($uRole === ROLE_HOD) {
                                            echo '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size: 0.68rem;">HOD</span>';
                                        } elseif ($uRole === ROLE_SCIENTIST) {
                                            echo '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle" style="font-size: 0.68rem;">Scientist</span>';
                                        }
                                        ?>
                                        <small class="text-muted text-truncate" style="max-width: 130px;" title="<?= e($log['user_email']) ?>">
                                            <?= e($log['user_email']) ?>
                                        </small>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">System / Guest</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-secondary border">
                                    <?= e($log['entity_type']) ?> #<?= e($log['entity_id']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="small text-dark"><?= e($log['details'] ?: '-') ?></div>
                            </td>
                            <td class="small font-monospace text-muted"><?= e($log['ip_address'] ?? '127.0.0.1') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

