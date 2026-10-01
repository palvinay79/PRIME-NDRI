<?php
/**
 * Research Proposal and Project Management System
 * Head of Department (HOD) - Department Projects Overview & Progress Monitoring
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_HOD);

$pageTitle = 'Department Projects - HOD';
$user = current_user();
$deptId = (int)($user['department_id'] ?? current_user_department_id() ?? 1);
$db = get_db();

// Fetch department details
$deptStmt = $db->prepare("SELECT * FROM departments WHERE id = ?");
$deptStmt->execute([$deptId]);
$department = $deptStmt->fetch();

// Search and filter parameters
$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT pr.*, COALESCE(pr.department_id, p.department_id) as department_id,
               p.title as proposal_title, p.proposal_number, p.institute_priority_area,
               u.name as scientist_name, u.email as scientist_email, u.designation as scientist_designation,
               (SELECT COUNT(*) FROM progress_reports r WHERE r.project_id = pr.id) as reports_count,
               (SELECT COALESCE(SUM(COALESCE(r.budget_utilized, r.budget_utilization, 0)), 0) FROM progress_reports r WHERE r.project_id = pr.id) as total_utilized,
               (SELECT MAX(COALESCE(r.created_at, r.submitted_at)) FROM progress_reports r WHERE r.project_id = pr.id) as latest_report_date
        FROM projects pr
        JOIN proposals p ON pr.proposal_id = p.id
        JOIN users u ON pr.scientist_id = u.id
        WHERE COALESCE(pr.department_id, p.department_id) = ?";

$params = [$deptId];

if ($statusFilter !== '') {
    $sql .= " AND pr.project_status = ?";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $sql .= " AND (pr.project_number LIKE ? OR p.title LIKE ? OR u.name LIKE ?)";
    $likeSearch = "%{$search}%";
    $params[] = $likeSearch;
    $params[] = $likeSearch;
    $params[] = $likeSearch;
}

$sql .= " ORDER BY pr.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Overall departmental statistics
$statStmt = $db->prepare("SELECT COUNT(*) as total_projects,
                                 SUM(CASE WHEN pr.project_status = ? THEN 1 ELSE 0 END) as active_projects,
                                 COALESCE(SUM(pr.approved_budget), 0) as total_budget
                          FROM projects pr
                          JOIN proposals p ON pr.proposal_id = p.id
                          WHERE COALESCE(pr.department_id, p.department_id) = ?");
$statStmt->execute([PROJECT_STATUS_ACTIVE, $deptId]);
$deptStats = $statStmt->fetch();

$totalReportsStmt = $db->prepare("SELECT COUNT(*) FROM progress_reports r
                                  JOIN projects pr ON r.project_id = pr.id
                                  JOIN proposals p ON pr.proposal_id = p.id
                                  WHERE COALESCE(pr.department_id, p.department_id) = ?");
$totalReportsStmt->execute([$deptId]);
$totalReportsCount = (int)$totalReportsStmt->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Department Research Projects</h3>
        <p class="text-muted small mb-0">
            Division: <strong><?= e($department['department_name'] ?? 'Department') ?> (<?= e($department['department_code'] ?? 'NDRI') ?>)</strong> |
            Monitor project execution, milestone compliance, and scientist progress reports
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('/hod/proposals.php') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-inbox me-1"></i> Review Proposals
        </a>
    </div>
</div>

<!-- Department Summary Metrics -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-primary"></div>
            <span class="text-muted small text-uppercase fw-semibold">Total Projects</span>
            <div class="fs-3 fw-bold text-dark mt-1"><?= (int)($deptStats['total_projects'] ?? 0) ?></div>
            <small class="text-muted">Registered in division</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-success"></div>
            <span class="text-muted small text-uppercase fw-semibold">Active Projects</span>
            <div class="fs-3 fw-bold text-success mt-1"><?= (int)($deptStats['active_projects'] ?? 0) ?></div>
            <small class="text-muted">Currently in execution</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-info"></div>
            <span class="text-muted small text-uppercase fw-semibold">Total Approved Grants</span>
            <div class="fs-4 fw-bold text-info-emphasis mt-1"><?= format_currency((float)($deptStats['total_budget'] ?? 0)) ?></div>
            <small class="text-muted">Departmental grant pool</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-warning"></div>
            <span class="text-muted small text-uppercase fw-semibold">Progress Reports Filed</span>
            <div class="fs-3 fw-bold text-warning-emphasis mt-1"><?= $totalReportsCount ?></div>
            <small class="text-muted">Quarterly dossiers logged</small>
        </div>
    </div>
</div>

<!-- Filters & Search Form -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?= url('/hod/projects.php') ?>" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="q" class="form-control border-start-0" placeholder="Search by Project No, Title, or Scientist..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="<?= PROJECT_STATUS_ACTIVE ?>" <?= $statusFilter === PROJECT_STATUS_ACTIVE ? 'selected' : '' ?>>Active Projects</option>
                    <option value="<?= PROJECT_STATUS_COMPLETED ?>" <?= $statusFilter === PROJECT_STATUS_COMPLETED ? 'selected' : '' ?>>Completed Projects</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100" style="background-color: #1a365d; border-color: #1a365d;">
                    <i class="bi bi-funnel me-1"></i> Apply Filter
                </button>
                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a href="<?= url('/hod/projects.php') ?>" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                        <i class="bi bi-x-circle"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Projects Table -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-kanban text-primary me-2"></i>Department Projects (<?= count($projects) ?>)
        </h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 170px;">Project No.</th>
                    <th>Research Title</th>
                    <th style="width: 200px;">Principal Investigator</th>
                    <th style="width: 160px;">Grant & Expenditure</th>
                    <th style="width: 160px;">Duration</th>
                    <th style="width: 110px;">Status</th>
                    <th style="width: 170px;" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($projects)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-kanban fs-2 d-block mb-2"></i>
                            No department research projects found matching your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($projects as $prj): ?>
                        <?php
                        $budget = (float)$prj['approved_budget'];
                        $utilized = (float)$prj['total_utilized'];
                        $pct = $budget > 0 ? min(100, round(($utilized / $budget) * 100)) : 0;
                        ?>
                        <tr>
                            <td class="fw-semibold font-monospace small">
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                    <i class="bi bi-journal-code me-1"></i><?= e($prj['project_number']) ?>
                                </span>
                                <?php if (!empty($prj['proposal_number'])): ?>
                                    <div class="text-muted extra-small mt-1 font-monospace" style="font-size: 0.72rem;">
                                        Prop: <?= e($prj['proposal_number']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong class="text-dark d-block mb-1"><?= e($prj['proposal_title']) ?></strong>
                                <div class="small text-muted d-flex align-items-center gap-2">
                                    <span class="badge bg-light text-secondary border"><?= e($prj['institute_priority_area'] ?: 'General') ?></span>
                                    <span>
                                        <i class="bi bi-file-earmark-text text-primary me-1"></i>
                                        <strong><?= (int)$prj['reports_count'] ?></strong> report(s) filed
                                    </span>
                                    <?php if ($prj['latest_report_date']): ?>
                                        <span class="text-secondary small">
                                            (Last: <?= format_date($prj['latest_report_date']) ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark small"><?= e($prj['scientist_name']) ?></div>
                                <div class="text-muted small"><?= e($prj['scientist_designation'] ?: 'Scientist') ?></div>
                                <div class="text-muted extra-small" style="font-size: 0.75rem;"><?= e($prj['scientist_email']) ?></div>
                                <?php 
                                $prjCopis = get_project_copis((int)$prj['id']);
                                if (!empty($prjCopis)): 
                                ?>
                                    <div class="mt-1">
                                        <span class="badge bg-light text-secondary border extra-small font-monospace" style="font-size: 0.68rem;">
                                            <i class="bi bi-people-fill text-primary me-1"></i><?= count($prjCopis) ?> Co-PI<?= count($prjCopis) !== 1 ? 's' : '' ?>
                                        </span>
                                        <div class="extra-small text-muted text-truncate" style="max-width: 170px; font-size: 0.68rem;">
                                            <?= e(implode(', ', array_column($prjCopis, 'co_pi_name'))) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="small fw-bold text-success"><?= format_currency($budget) ?></div>
                                <div class="text-muted extra-small" style="font-size: 0.75rem;">
                                    Expended: <strong><?= format_currency($utilized) ?></strong>
                                </div>
                                <div class="progress mt-1" style="height: 5px;" title="<?= $pct ?>% utilized">
                                    <div class="progress-bar <?= $utilized > $budget ? 'bg-danger' : 'bg-primary' ?>" role="progressbar" style="width: <?= $pct ?>%"></div>
                                </div>
                            </td>
                            <td class="small text-muted">
                                <div><i class="bi bi-calendar-event me-1"></i><?= format_date($prj['start_date']) ?></div>
                                <div><i class="bi bi-calendar-check me-1"></i><?= format_date($prj['end_date']) ?></div>
                            </td>
                            <td>
                                <span class="badge <?= $prj['project_status'] === PROJECT_STATUS_ACTIVE ? 'bg-success' : 'bg-dark' ?>">
                                    <?= e($prj['project_status']) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= url('/scientist/view-progress.php?project_id=' . (int)$prj['id']) ?>" class="btn btn-primary" title="View Progress Reports" style="background-color: #1a365d; border-color: #1a365d;">
                                        <i class="bi bi-file-earmark-text me-1"></i> Reports (<?= (int)$prj['reports_count'] ?>)
                                    </a>
                                    <a href="<?= url('/scientist/proposal-details.php?id=' . (int)$prj['proposal_id']) ?>" class="btn btn-outline-secondary" title="View Proposal Dossier">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
