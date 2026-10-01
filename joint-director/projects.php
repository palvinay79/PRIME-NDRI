<?php
/**
 * Research Proposal and Project Management System
 * Joint Director - Institute Research Projects Master Monitoring & Grant Utilization
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_JOINT_DIRECTOR);

$db = get_db();
$userId = current_user_id();

// Real-time AJAX uniqueness check endpoint for Joint Director
if (isset($_GET['check_unique'])) {
    header('Content-Type: application/json');
    $checkVal = trim(sanitize($_GET['project_number'] ?? ''));
    $excludeProjId = (int)($_GET['project_id'] ?? 0);
    $excludePropId = (int)($_GET['proposal_id'] ?? 0);
    if (empty($checkVal)) {
        echo json_encode(['unique' => false, 'message' => 'Project ID cannot be empty.']);
        exit;
    }
    $isUnique = is_project_number_unique($checkVal, $excludeProjId, $excludePropId);
    echo json_encode([
        'unique' => $isUnique,
        'message' => $isUnique ? "Project ID '{$checkVal}' is available and unique." : "Project ID '{$checkVal}' is already in use by another project."
    ]);
    exit;
}

// Handle Joint Director project ID customization / update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_project_id') {
    require_csrf();
    $projectId = (int)($_POST['project_id'] ?? 0);
    $newProjectNumber = trim(sanitize($_POST['new_project_number'] ?? ''));
    $newFundingAgency = trim(sanitize($_POST['funding_agency'] ?? ''));

    $stmtPrj = $db->prepare("SELECT pr.*, p.id as prop_id, p.project_type as prop_project_type FROM projects pr JOIN proposals p ON pr.proposal_id = p.id WHERE pr.id = ?");
    $stmtPrj->execute([$projectId]);
    $prjData = $stmtPrj->fetch();

    if (!$prjData) {
        flash('danger', 'Project record not found.');
    } elseif (empty($newProjectNumber)) {
        flash('danger', 'Project ID / Project No. cannot be empty.');
    } elseif (!is_project_number_unique($newProjectNumber, $projectId, (int)$prjData['prop_id'])) {
        flash('danger', "Project ID '{$newProjectNumber}' is already assigned to another project. Joint Director must assign a unique Project ID.");
    } else {
        $oldNumber = $prjData['project_number'];
        $db->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $db->prepare("UPDATE projects SET project_number = ?, funding_agency = CASE WHEN ? != '' THEN ? ELSE funding_agency END, updated_at = ? WHERE id = ?")
               ->execute([$newProjectNumber, $newFundingAgency, $newFundingAgency, $now, $projectId]);
            $db->prepare("UPDATE proposals SET project_number = ?, funding_agency = CASE WHEN ? != '' THEN ? ELSE funding_agency END, updated_at = ? WHERE id = ?")
               ->execute([$newProjectNumber, $newFundingAgency, $newFundingAgency, $now, (int)$prjData['prop_id']]);
            
            try {
                $db->prepare("UPDATE irc_decisions SET project_number = ? WHERE proposal_id = ?")
                   ->execute([$newProjectNumber, (int)$prjData['prop_id']]);
            } catch (Exception $eIrc) {
                // optional
            }

            log_audit($userId, 'PROJECT_ID_CUSTOMIZED', 'projects', $projectId, "Joint Director updated Project ID '{$newProjectNumber}' and funding agency details");
            $db->commit();
            flash('success', "Project details successfully updated.");
        } catch (Exception $e) {
            $db->rollBack();
            flash('danger', 'Failed to update Project details: ' . $e->getMessage());
        }
    }
    header("Location: " . url("/joint-director/projects.php"));
    exit;
}

$pageTitle = 'Institute Projects Monitoring & Grant Utilization - JD';

$search = sanitize($_GET['search'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$deptFilter = (int)($_GET['dept'] ?? 0);

$sql = "SELECT pr.*, p.title as proposal_title, p.project_type as prop_project_type, p.funding_agency as prop_funding_agency,
               p.proposal_category, p.budget_allocated as prop_budget_allocated, p.budget_utilized as prop_budget_utilized,
               u.name as scientist_name, d.department_name, d.department_code,
               (SELECT COUNT(*) FROM progress_reports r WHERE r.project_id = pr.id) as reports_count,
               CASE 
                   WHEN (SELECT COUNT(*) FROM progress_reports r WHERE r.project_id = pr.id) > 0 
                   THEN (SELECT COALESCE(SUM(COALESCE(r.budget_utilized, r.budget_utilization, 0)), 0) FROM progress_reports r WHERE r.project_id = pr.id)
                   ELSE COALESCE(p.budget_utilized, 0)
               END as total_utilized,
               (SELECT COUNT(*) FROM progress_reports r WHERE r.project_id = pr.id AND (r.review_status IS NULL OR r.review_status = 'Submitted' OR r.review_status = 'Pending Review')) as pending_reviews_count,
               (SELECT p_ongoing.current_status FROM proposals p_ongoing WHERE (p_ongoing.linked_project_id = pr.id OR (p_ongoing.project_number = pr.project_number AND p_ongoing.project_number IS NOT NULL AND p_ongoing.project_number != '')) AND p_ongoing.proposal_category = 'ongoing' ORDER BY p_ongoing.id DESC LIMIT 1) as latest_ongoing_status,
               (SELECT p_ongoing.id FROM proposals p_ongoing WHERE (p_ongoing.linked_project_id = pr.id OR (p_ongoing.project_number = pr.project_number AND p_ongoing.project_number IS NOT NULL AND p_ongoing.project_number != '')) AND p_ongoing.proposal_category = 'ongoing' ORDER BY p_ongoing.id DESC LIMIT 1) as latest_ongoing_id,
               (SELECT p_ongoing.progress_report_period FROM proposals p_ongoing WHERE (p_ongoing.linked_project_id = pr.id OR (p_ongoing.project_number = pr.project_number AND p_ongoing.project_number IS NOT NULL AND p_ongoing.project_number != '')) AND p_ongoing.proposal_category = 'ongoing' ORDER BY p_ongoing.id DESC LIMIT 1) as latest_ongoing_period,
               (SELECT MAX(submitted_at) FROM progress_reports r WHERE r.project_id = pr.id) as latest_report_date
        FROM projects pr
        JOIN proposals p ON pr.proposal_id = p.id
        JOIN users u ON pr.scientist_id = u.id
        JOIN departments d ON COALESCE(pr.department_id, p.department_id) = d.id
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (pr.project_number LIKE ? OR p.title LIKE ? OR u.name LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (!empty($statusFilter)) {
    $sql .= " AND pr.project_status = ?";
    $params[] = $statusFilter;
}

if ($deptFilter > 0) {
    $sql .= " AND COALESCE(pr.department_id, p.department_id) = ?";
    $params[] = $deptFilter;
}

$sql .= " ORDER BY pr.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Calculate institutional financial metrics across listed projects
$totalAllBudget = 0.0;
$totalAllUtilized = 0.0;
$activeCount = 0;
$completedCount = 0;
$totalPendingReviews = 0;

foreach ($projects as $prj) {
    $totalAllBudget += (float)$prj['approved_budget'];
    $totalAllUtilized += (float)$prj['total_utilized'];
    $totalPendingReviews += (int)($prj['pending_reviews_count'] ?? 0);
    if ($prj['project_status'] === PROJECT_STATUS_ACTIVE) {
        $activeCount++;
    } else {
        $completedCount++;
    }
}

$totalBalance = $totalAllBudget - $totalAllUtilized;
$overallPercent = $totalAllBudget > 0 ? round(($totalAllUtilized / $totalAllBudget) * 100, 1) : 0.0;

$departments = $db->query("SELECT * FROM departments ORDER BY department_name ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Institute Research Projects Portfolio</h3>
        <p class="text-muted small mb-0">High-level institutional monitoring of active projects, cumulative grant utilization, and deliverables</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/joint-director/analytics.php") ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-graph-up me-1"></i> Research Analytics
        </a>
        <a href="<?= url("/joint-director/proposals.php") ?>" class="btn btn-sm btn-primary" style="background-color: #1a365d; border-color: #1a365d;">
            <i class="bi bi-clipboard-check me-1"></i> Review Proposals
        </a>
    </div>
</div>

<!-- Financial & Milestone Overview Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card p-3 h-100">
            <div class="stat-card-accent bg-primary"></div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted small text-uppercase fw-semibold">Portfolio Size</span>
                <span class="badge bg-primary-subtle text-primary"><?= count($projects) ?> Total</span>
            </div>
            <div class="fs-3 fw-bold text-dark mt-1"><?= $activeCount ?> <span class="fs-6 fw-normal text-muted">Active</span></div>
            <div class="text-muted small mt-1">
                <span class="text-success"><i class="bi bi-check2-all me-1"></i><?= $completedCount ?> Completed</span>
                <?php if ($totalPendingReviews > 0): ?>
                    &bull; <span class="text-danger fw-semibold"><i class="bi bi-exclamation-circle me-1"></i><?= $totalPendingReviews ?> Reports Pending Review</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="stat-card p-3 h-100">
            <div class="stat-card-accent bg-success"></div>
            <span class="text-muted small text-uppercase fw-semibold">Total Approved Grants</span>
            <div class="fs-3 fw-bold text-success mt-1"><?= format_currency($totalAllBudget) ?></div>
            <div class="text-muted small mt-1">
                Allocated across <?= count($projects) ?> research project<?= count($projects) != 1 ? 's' : '' ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="stat-card p-3 h-100">
            <div class="stat-card-accent bg-info"></div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted small text-uppercase fw-semibold">Total Utilization</span>
                <span class="badge bg-info-subtle text-info font-monospace"><?= $overallPercent ?>%</span>
            </div>
            <div class="fs-3 fw-bold text-primary mt-1"><?= format_currency($totalAllUtilized) ?></div>
            <div class="progress mt-2" style="height: 6px;">
                <div class="progress-bar bg-primary" role="progressbar" style="width: <?= min(100, $overallPercent) ?>%" aria-valuenow="<?= $overallPercent ?>" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="stat-card p-3 h-100">
            <div class="stat-card-accent <?= $totalBalance < 0 ? 'bg-danger' : 'bg-warning' ?>"></div>
            <span class="text-muted small text-uppercase fw-semibold">Grant Balance Pool</span>
            <div class="fs-3 fw-bold <?= $totalBalance < 0 ? 'text-danger' : 'text-dark' ?> mt-1">
                <?= format_currency($totalBalance) ?>
            </div>
            <div class="text-muted small mt-1">
                <?= 100 - $overallPercent ?>% remaining institutional budget
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter Bar -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= url("/joint-director/projects.php") ?>" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search project #, title, or scientist..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">-- All Project Statuses --</option>
                    <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active Projects</option>
                    <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed Projects</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="dept" class="form-select">
                    <option value="0">-- All Academic Divisions --</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= e($d['department_name']) ?> (<?= e($d['department_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="<?= url("/joint-director/projects.php") ?>" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Master Projects & Total Utilization Table -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-table me-2 text-primary"></i>Active Projects & Total Grant Utilization List
        </h6>
        <span class="badge bg-light text-secondary border">
            Showing <?= count($projects) ?> project record<?= count($projects) != 1 ? 's' : '' ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 170px;">Project No.</th>
                    <th>Project Title & Duration</th>
                    <th style="width: 170px;">Principal Investigator</th>
                    <th style="width: 100px;">Division</th>
                    <th style="width: 135px;" class="text-end">Approved Grant</th>
                    <th style="width: 155px;" class="text-end bg-light-subtle">
                        <span class="text-primary fw-bold">Total Utilized</span>
                    </th>
                    <th style="width: 135px;" class="text-end">Balance</th>
                    <th style="width: 120px;" class="text-center">Utilization %</th>
                    <th style="width: 95px;" class="text-center">Status</th>
                    <th style="width: 130px;" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($projects)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-5 text-muted">
                            <i class="bi bi-kanban fs-2 d-block mb-2 text-secondary"></i>
                            No research projects match the selected filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($projects as $prj): ?>
                        <?php
                        $budget = (float)$prj['approved_budget'];
                        $utilized = (float)$prj['total_utilized'];
                        $bal = $budget - $utilized;
                        $pct = $budget > 0 ? round(($utilized / $budget) * 100, 1) : 0.0;
                        $isOver = $utilized > $budget;
                        $pendingReviews = (int)($prj['pending_reviews_count'] ?? 0);
                        ?>
                        <?php
                        $isFunding = (($prj['project_type'] ?? '') === 'funding_agency' || ($prj['prop_project_type'] ?? '') === 'funding_agency');
                        $agencyName = !empty($prj['funding_agency']) ? $prj['funding_agency'] : (!empty($prj['prop_funding_agency']) ? $prj['prop_funding_agency'] : '');
                        ?>
                        <tr>
                            <td class="font-monospace fw-semibold small">
                                <div class="d-flex align-items-center gap-1">
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                        <i class="bi bi-journal-code me-1"></i><?= e($prj['project_number']) ?>
                                    </span>
                                    <button type="button" 
                                            class="btn btn-sm btn-light border py-0 px-1 shadow-sm" 
                                            title="Customize Project ID & Funding Agency (Joint Director Only)" 
                                            onclick="openEditProjectIdModal(<?= (int)$prj['id'] ?>, <?= (int)$prj['proposal_id'] ?>, '<?= e(addslashes($prj['project_number'])) ?>', '<?= e(addslashes($prj['proposal_title'])) ?>', '<?= e(addslashes($prj['department_code'])) ?>', <?= $isFunding ? 'true' : 'false' ?>, '<?= e(addslashes($agencyName)) ?>')">
                                        <i class="bi bi-pencil text-primary" style="font-size: 0.75rem;"></i>
                                    </button>
                                </div>
                                <div class="mt-1 d-flex flex-wrap gap-1">
                                    <?php if (($prj['proposal_category'] ?? '') === 'completed'): ?>
                                        <span class="badge bg-success text-white" style="font-size: 0.68rem; font-family: inherit;">
                                            <i class="bi bi-journal-check me-1"></i>Completed
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($isFunding): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle text-wrap text-start" style="font-size: 0.68rem; font-family: inherit;">
                                            <i class="bi bi-bank2 me-1"></i><?= e($agencyName ?: 'Funding Agency') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.68rem; font-family: inherit;">
                                            <i class="bi bi-house-door-fill me-1"></i>In-house
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong class="text-dark d-block"><?= e($prj['proposal_title']) ?></strong>
                                <div class="text-muted small mt-1 d-flex flex-wrap align-items-center gap-1">
                                    <span><i class="bi bi-calendar3 me-1"></i><?= format_date($prj['start_date']) ?> &rarr; <?= format_date($prj['end_date']) ?></span>
                                    <span class="text-muted">&bull;</span>
                                    <a href="<?= url("/scientist/view-progress.php?project_id=" . ($prj['id']) . "") ?>" class="text-decoration-none fw-semibold">
                                        <i class="bi bi-file-earmark-text me-1"></i><?= (int)$prj['reports_count'] ?> Report<?= (int)$prj['reports_count'] != 1 ? 's' : '' ?>
                                    </a>
                                    <?php 
                                        $latestOngoingStatus = $prj['latest_ongoing_status'] ?? '';
                                        $latestOngoingId = (int)($prj['latest_ongoing_id'] ?? 0);
                                        $latestPeriod = $prj['latest_ongoing_period'] ?? '';
                                    ?>
                                    <?php if ($latestOngoingStatus === STATUS_APPROVED_IRC && $latestOngoingId > 0): ?>
                                        <a href="<?= url("/joint-director/irc-decision.php?id=" . $latestOngoingId) ?>" class="badge bg-info-subtle text-info-emphasis border border-info-subtle text-decoration-none ms-1 font-monospace" style="font-size: 0.68rem;" title="Approved by JD for IRC Meeting. Click to record final IRC Council decision">
                                            <i class="bi bi-award me-1"></i>Slated for IRC Decision <?= !empty($latestPeriod) ? "(" . e($latestPeriod) . ")" : "" ?>
                                        </a>
                                    <?php elseif ($latestOngoingStatus === STATUS_FORWARDED_JD && $latestOngoingId > 0): ?>
                                        <a href="<?= url("/joint-director/review-proposal.php?id=" . $latestOngoingId) ?>" class="badge bg-warning text-dark border border-warning-subtle text-decoration-none ms-1 font-monospace" style="font-size: 0.68rem;" title="Forwarded by HOD. Click to screen and review">
                                            <i class="bi bi-hourglass-split me-1"></i>JD Screening Pending
                                        </a>
                                    <?php elseif ($latestOngoingStatus === STATUS_SUBMITTED_HOD): ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1 font-monospace" style="font-size: 0.68rem;">
                                            <i class="bi bi-clock me-1"></i>Awaiting HOD Review
                                        </span>
                                    <?php elseif ($pendingReviews > 0): ?>
                                        <span class="badge bg-warning text-dark font-monospace ms-1" style="font-size: 0.68rem;">
                                            <i class="bi bi-clock-history me-1"></i>Review in Progress
                                        </span>
                                    <?php elseif ((int)$prj['reports_count'] > 0): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle ms-1 font-monospace" style="font-size: 0.68rem;">
                                            <i class="bi bi-check2-circle me-1"></i>All Reports Approved
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="small fw-semibold text-dark"><?= e($prj['scientist_name']) ?></div>
                                <span class="text-muted extra-small d-block" style="font-size: 0.75rem;">PI / Lead Scientist</span>
                                <?php 
                                $prjCopis = get_project_copis((int)$prj['id']);
                                if (!empty($prjCopis)): 
                                ?>
                                    <div class="mt-1" title="<?= e(implode(', ', array_column($prjCopis, 'co_pi_name'))) ?>">
                                        <span class="badge bg-light text-secondary border extra-small font-monospace" style="font-size: 0.68rem;">
                                            <i class="bi bi-people-fill text-primary me-1"></i><?= count($prjCopis) ?> Co-PI<?= count($prjCopis) !== 1 ? 's' : '' ?>
                                        </span>
                                        <div class="extra-small text-muted text-truncate" style="max-width: 160px; font-size: 0.68rem;">
                                            <?= e(implode(', ', array_column($prjCopis, 'co_pi_name'))) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace"><?= e($prj['department_code']) ?></span>
                            </td>
                            <td class="text-end small fw-semibold text-dark">
                                <?= format_currency($budget) ?>
                            </td>
                            <td class="text-end small fw-bold bg-light-subtle <?= $isOver ? 'text-danger' : 'text-primary' ?>">
                                <?= format_currency($utilized) ?>
                                <?php if ($isOver): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle d-block font-monospace" style="font-size: 0.65rem;">
                                        Over Budget
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end small <?= $bal < 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                <?= format_currency($bal) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge <?= $isOver ? 'bg-danger' : ($pct > 75 ? 'bg-warning text-dark' : 'bg-primary-subtle text-primary') ?> font-monospace mb-1" style="font-size: 0.72rem;">
                                    <?= $pct ?>%
                                </span>
                                <div class="progress mx-auto" style="height: 4px; width: 65px;">
                                    <div class="progress-bar <?= $isOver ? 'bg-danger' : ($pct > 75 ? 'bg-warning' : 'bg-primary') ?>" role="progressbar" style="width: <?= min(100, $pct) ?>%"></div>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge <?= $prj['project_status'] === PROJECT_STATUS_ACTIVE ? 'bg-success' : 'bg-dark' ?>">
                                    <?= e($prj['project_status']) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= url("/scientist/view-progress.php?project_id=" . ($prj['id']) . "") ?>" class="btn btn-sm btn-primary" title="Review Progress Reports & Grant Expenditures" style="background-color: #1a365d; border-color: #1a365d;">
                                    <i class="bi bi-search me-1"></i> Review
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($projects)): ?>
                <tfoot class="table-light border-top border-2">
                    <tr class="fw-bold">
                        <td colspan="4" class="text-dark py-3">
                            <i class="bi bi-calculator me-2 text-primary"></i>
                            Total for Listed Projects (<?= count($projects) ?>):
                        </td>
                        <td class="text-end py-3 text-dark">
                            <?= format_currency($totalAllBudget) ?>
                        </td>
                        <td class="text-end py-3 bg-light-subtle text-primary fs-6">
                            <?= format_currency($totalAllUtilized) ?>
                        </td>
                        <td class="text-end py-3 <?= $totalBalance < 0 ? 'text-danger' : 'text-dark' ?>">
                            <?= format_currency($totalBalance) ?>
                        </td>
                        <td class="text-center py-3">
                            <span class="badge bg-primary text-white font-monospace">
                                <?= $overallPercent ?>% Avg
                            </span>
                        </td>
                        <td colspan="2" class="text-end text-muted small py-3">
                            Institutional Summary
                        </td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Modal: Joint Director Edit Project ID -->
<div class="modal fade" id="editProjectIdModal" tabindex="-1" aria-labelledby="editProjectIdModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fs-6 fw-bold m-0" id="editProjectIdModalLabel">
                    <i class="bi bi-pencil-square me-2"></i>Customize Project ID (Joint Director)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= url("/joint-director/projects.php") ?>" id="form-edit-project-id">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_project_id">
                <input type="hidden" name="project_id" id="modal-project-id" value="">
                <input type="hidden" name="proposal_id" id="modal-proposal-id" value="">

                <div class="modal-body p-4">
                    <div class="mb-3 p-2 bg-light rounded border">
                        <span class="text-muted extra-small d-block text-uppercase fw-bold">Project Title:</span>
                        <div id="modal-project-title" class="fw-semibold text-dark small mt-1"></div>
                        <div class="mt-2 text-muted small">
                            Current Project ID: <span id="modal-current-id-badge" class="badge bg-secondary font-monospace"></span>
                        </div>
                    </div>

                    <div class="mb-3" id="modal-funding-agency-group" style="display: none;">
                        <label for="modal-funding-agency" class="form-label fw-bold small text-dark mb-1">
                            <i class="bi bi-bank2 text-success me-1"></i> Funding Agency Name
                        </label>
                        <input type="text" 
                               name="funding_agency" 
                               id="modal-funding-agency" 
                               class="form-control fw-semibold" 
                               placeholder="e.g. Department of Biotechnology (DBT), DST, National Extra-Mural, BIRAC...">
                        <div class="form-text text-muted extra-small">Add or update the sanctioning funding agency for this project.</div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="new_project_number" class="form-label fw-bold small text-dark mb-0">
                                Project ID / Sanction No. <span class="text-danger">*</span>
                            </label>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.7rem;">
                                Joint Director Privilege
                            </span>
                        </div>
                        <p class="text-muted extra-small mb-2" style="font-size: 0.78rem;">
                            Assign any unique institutional project identifier of your choice.
                        </p>
                        <div class="input-group">
                            <span class="input-group-text bg-light font-monospace fw-bold text-muted">ID</span>
                            <input type="text" 
                                   name="new_project_number" 
                                   id="new_project_number" 
                                   class="form-control font-monospace fw-bold text-dark" 
                                   required 
                                   autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="btn-modal-check" onclick="checkModalProjectIdUnique()">
                                <i class="bi bi-shield-check me-1"></i> Verify
                            </button>
                        </div>
                        <div id="modal-id-feedback" class="small mt-2" style="display: none;"></div>
                    </div>

                    <div class="mb-2" id="modal-suggestions-wrapper">
                        <label class="form-label text-muted extra-small fw-bold text-uppercase d-block mb-1">Quick Suggestions:</label>
                        <div class="d-flex flex-wrap gap-1" id="modal-suggestions-box">
                            <!-- Populated dynamically -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2 px-4">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm px-3 fw-semibold">
                        <i class="bi bi-check2 me-1"></i> Save Project Details
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentDeptCode = 'NDRI';
let activeModalPrjId = 0;
let activeModalPropId = 0;

function openEditProjectIdModal(projectId, proposalId, currentId, title, deptCode, isFunding, fundingAgency) {
    activeModalPrjId = projectId;
    activeModalPropId = proposalId;
    currentDeptCode = deptCode || 'NDRI';

    document.getElementById('modal-project-id').value = projectId;
    document.getElementById('modal-proposal-id').value = proposalId;
    document.getElementById('modal-project-title').textContent = title;
    document.getElementById('modal-current-id-badge').textContent = currentId;

    const fundingGroup = document.getElementById('modal-funding-agency-group');
    const fundingInput = document.getElementById('modal-funding-agency');
    const suggestionsWrapper = document.getElementById('modal-suggestions-wrapper');

    if (fundingGroup && fundingInput) {
        if (isFunding) {
            fundingGroup.style.display = 'block';
            fundingInput.value = fundingAgency || '';
        } else {
            fundingGroup.style.display = 'none';
            fundingInput.value = '';
        }
    }
    
    const input = document.getElementById('new_project_number');
    input.value = currentId;
    input.classList.remove('is-valid', 'is-invalid');

    const feedback = document.getElementById('modal-id-feedback');
    feedback.style.display = 'none';

    // Populate suggestions
    const yr = new Date().getFullYear();
    const suggestionsBox = document.getElementById('modal-suggestions-box');
    suggestionsBox.innerHTML = `
        <button type="button" class="btn btn-xs btn-outline-primary py-1 px-2 text-start font-monospace" style="font-size: 0.75rem;" onclick="setModalSuggestedId('NDRI/${currentDeptCode}/${yr}/${String(projectId).padStart(3, '0')}')">
            NDRI/${currentDeptCode}/${yr}/${String(projectId).padStart(3, '0')}
        </button>
        <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2 text-start font-monospace" style="font-size: 0.75rem;" onclick="setModalSuggestedId('NDRI-PRIME-${currentDeptCode}-${yr}-${String(projectId).padStart(3, '0')}')">
            NDRI-PRIME-${currentDeptCode}-${yr}-${String(projectId).padStart(3, '0')}
        </button>
        <button type="button" class="btn btn-xs btn-outline-dark py-1 px-2 text-start font-monospace" style="font-size: 0.75rem;" onclick="setModalSuggestedId('PROJ-NDRI-${yr}-${String(projectId).padStart(3, '0')}')">
            PROJ-NDRI-${yr}-${String(projectId).padStart(3, '0')}
        </button>
    `;

    const modalEl = document.getElementById('editProjectIdModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

function setModalSuggestedId(val) {
    const input = document.getElementById('new_project_number');
    if (input) {
        input.value = val;
        checkModalProjectIdUnique();
    }
}

async function checkModalProjectIdUnique() {
    const input = document.getElementById('new_project_number');
    const feedback = document.getElementById('modal-id-feedback');
    const btn = document.getElementById('btn-modal-check');
    if (!input || !feedback) return;

    const val = input.value.trim();
    if (!val) {
        feedback.style.display = 'block';
        feedback.className = 'small mt-2 text-danger fw-semibold';
        feedback.innerHTML = '<i class="bi bi-x-circle me-1"></i> Please enter a Project ID.';
        return;
    }

    if (btn) btn.disabled = true;
    feedback.style.display = 'block';
    feedback.className = 'small mt-2 text-muted';
    feedback.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Checking uniqueness...';

    try {
        const resp = await fetch('<?= url("/joint-director/projects.php?check_unique=1") ?>&project_number=' + encodeURIComponent(val) + '&project_id=' + activeModalPrjId + '&proposal_id=' + activeModalPropId);
        const data = await resp.json();
        if (data.unique) {
            feedback.className = 'small mt-2 text-success fw-semibold';
            feedback.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> ' + data.message;
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
        } else {
            feedback.className = 'small mt-2 text-danger fw-semibold';
            feedback.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' + data.message;
            input.classList.remove('is-valid');
            input.classList.add('is-invalid');
        }
    } catch (err) {
        feedback.className = 'small mt-2 text-warning';
        feedback.innerHTML = '<i class="bi bi-info-circle me-1"></i> Uniqueness check available upon submit.';
    } finally {
        if (btn) btn.disabled = false;
    }
}

let modalTypingTimer;
document.getElementById('new_project_number')?.addEventListener('input', function() {
    clearTimeout(modalTypingTimer);
    modalTypingTimer = setTimeout(checkModalProjectIdUnique, 500);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

