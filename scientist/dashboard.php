<?php
/**
 * Research Proposal and Project Management System
 * Scientist Dashboard
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_SCIENTIST);

$pageTitle = 'Scientist Dashboard';
$userId = current_user_id();
$db = get_db();

// Metrics
$metrics = [
    'total' => 0,
    'draft' => 0,
    'pending' => 0,
    'returned' => 0,
    'active_projects' => 0,
    'completed_projects' => 0
];

// Count proposals by status
$stmt = $db->prepare("SELECT current_status, COUNT(*) as cnt FROM proposals WHERE scientist_id = ? GROUP BY current_status");
$stmt->execute([$userId]);
$statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$metrics['total'] = array_sum($statusCounts);
$metrics['draft'] = $statusCounts[STATUS_DRAFT] ?? 0;
$metrics['returned'] = ($statusCounts[STATUS_RETURNED_HOD] ?? 0) + ($statusCounts[STATUS_RETURNED_JD] ?? 0);
$metrics['pending'] = ($statusCounts[STATUS_SUBMITTED_HOD] ?? 0) +
                      ($statusCounts[STATUS_FORWARDED_JD] ?? 0) +
                      ($statusCounts[STATUS_APPROVED_IRC] ?? 0) +
                      ($statusCounts[STATUS_PENDING_IRC] ?? 0);

// Projects count
$stmt = $db->prepare("SELECT project_status, COUNT(*) as cnt FROM projects WHERE scientist_id = ? GROUP BY project_status");
$stmt->execute([$userId]);
$projCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$metrics['active_projects'] = $projCounts[PROJECT_STATUS_ACTIVE] ?? 0;
$metrics['completed_projects'] = $projCounts[PROJECT_STATUS_COMPLETED] ?? 0;

// Proposal category counts
$stmt = $db->prepare("SELECT COUNT(*) FROM proposals WHERE scientist_id = ? AND (proposal_category = 'new' OR proposal_category IS NULL)");
$stmt->execute([$userId]);
$countNew = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM proposals WHERE scientist_id = ? AND proposal_category = 'ongoing'");
$stmt->execute([$userId]);
$countOngoing = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM proposals WHERE scientist_id = ? AND proposal_category = 'completed'");
$stmt->execute([$userId]);
$countCompleted = (int)$stmt->fetchColumn();

// Active tab for proposal filtering
$activeTab = sanitize($_GET['tab'] ?? 'all');
if (!in_array($activeTab, ['all', 'new', 'ongoing', 'completed'])) {
    $activeTab = 'all';
}

// Recent proposals filtered by active tab
$recentSql = "SELECT p.*, d.department_code, d.department_name
              FROM proposals p
              LEFT JOIN departments d ON p.department_id = d.id
              WHERE p.scientist_id = ?";
$recentParams = [$userId];

if ($activeTab === 'new') {
    $recentSql .= " AND (p.proposal_category = 'new' OR p.proposal_category IS NULL)";
} elseif ($activeTab === 'ongoing') {
    $recentSql .= " AND p.proposal_category = 'ongoing'";
} elseif ($activeTab === 'completed') {
    $recentSql .= " AND p.proposal_category = 'completed'";
}

$recentSql .= " ORDER BY p.updated_at DESC LIMIT 6";
$stmt = $db->prepare($recentSql);
$stmt->execute($recentParams);
$recentProposals = $stmt->fetchAll();

// Active Projects with feedback indicators
$stmt = $db->prepare("SELECT pr.*, p.title as proposal_title,
                      (SELECT COUNT(*) FROM progress_reports WHERE project_id = pr.id) as reports_count,
                      (SELECT COALESCE(SUM(COALESCE(budget_utilized, budget_utilization, 0)), 0) FROM progress_reports WHERE project_id = pr.id) as total_expenditure,
                      (SELECT COUNT(*) FROM progress_reports WHERE project_id = pr.id AND (reviewer_comments IS NOT NULL AND TRIM(reviewer_comments) != '')) as feedback_count,
                      (SELECT reviewer_comments FROM progress_reports WHERE project_id = pr.id AND (reviewer_comments IS NOT NULL AND TRIM(reviewer_comments) != '') ORDER BY COALESCE(reviewed_at, updated_at, id) DESC LIMIT 1) as latest_feedback,
                      (SELECT reviewer_role FROM progress_reports WHERE project_id = pr.id AND (reviewer_comments IS NOT NULL AND TRIM(reviewer_comments) != '') ORDER BY COALESCE(reviewed_at, updated_at, id) DESC LIMIT 1) as latest_reviewer_role,
                      (SELECT review_status FROM progress_reports WHERE project_id = pr.id AND (reviewer_comments IS NOT NULL AND TRIM(reviewer_comments) != '') ORDER BY COALESCE(reviewed_at, updated_at, id) DESC LIMIT 1) as latest_review_status
                      FROM projects pr
                      JOIN proposals p ON pr.proposal_id = p.id
                      WHERE pr.scientist_id = ? AND pr.project_status = 'Active'
                      ORDER BY pr.created_at DESC");
$stmt->execute([$userId]);
$activeProjects = $stmt->fetchAll();

// Handle acknowledging/dismissing feedback alert
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'acknowledge_feedback') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security validation failed.');
        header('Location: ' . url('/scientist/dashboard.php'));
        exit;
    }
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId > 0) {
        $db->prepare("UPDATE progress_reports SET feedback_viewed_by_scientist = 1 WHERE id = ? AND (submitted_by = ? OR project_id IN (SELECT id FROM projects WHERE scientist_id = ?))")
           ->execute([$reportId, $userId, $userId]);
        flash('success', 'Reviewer feedback marked as acknowledged.');
    }
    header('Location: ' . url('/scientist/dashboard.php'));
    exit;
}

// Check for progress reports with reviewer feedback (Joint Director / HOD)
$feedbackStmt = $db->prepare("
    SELECT r.*, pr.id as project_id, pr.project_number, p.title as proposal_title,
           d.department_code, d.department_name,
           u_rev.name as reviewer_name, u_rev.designation as reviewer_designation
    FROM progress_reports r
    JOIN projects pr ON r.project_id = pr.id
    JOIN proposals p ON pr.proposal_id = p.id
    LEFT JOIN departments d ON COALESCE(pr.department_id, p.department_id) = d.id
    LEFT JOIN users u_rev ON r.reviewed_by = u_rev.id
    WHERE (pr.scientist_id = ? OR r.submitted_by = ?)
      AND (
          (r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '')
          OR r.review_status IN ('Reviewed', 'Approved / Accepted', 'Needs Revision')
      )
    ORDER BY COALESCE(r.reviewed_at, r.updated_at, r.submitted_at, r.created_at) DESC
");
$feedbackStmt->execute([$userId, $userId]);
$reportFeedbacks = $feedbackStmt->fetchAll();

// Active feedbacks that should trigger the high-visibility alert banner
$activeFeedbacks = array_filter($reportFeedbacks, function($fb) {
    return empty($fb['feedback_viewed_by_scientist']) || $fb['review_status'] === 'Needs Revision';
});

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Scientist Research Portal</h3>
        <p class="text-muted small mb-0">Manage research proposals, track HOD/JD reviews, and file project progress updates</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= url("/scientist/create-proposal.php") ?>" class="btn btn-primary shadow-sm" style="background-color: #1a365d; border-color: #1a365d;">
            <i class="bi bi-file-earmark-plus me-1"></i> New Proposal
        </a>
        <a href="<?= url("/scientist/create-completed-proposal.php") ?>" class="btn btn-outline-primary shadow-sm bg-white">
            <i class="bi bi-journal-check me-1"></i> Completed Proposal
        </a>
    </div>
</div>

<!-- Metrics Overview Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-primary"></div>
            <span class="text-muted small text-uppercase fw-semibold">Total Proposals</span>
            <div class="fs-3 fw-bold text-dark mt-1"><?= $metrics['total'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-secondary"></div>
            <span class="text-muted small text-uppercase fw-semibold">Drafts</span>
            <div class="fs-3 fw-bold text-secondary mt-1"><?= $metrics['draft'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-warning"></div>
            <span class="text-muted small text-uppercase fw-semibold">In Review</span>
            <div class="fs-3 fw-bold text-warning-emphasis mt-1"><?= $metrics['pending'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-danger"></div>
            <span class="text-muted small text-uppercase fw-semibold">Returned</span>
            <div class="fs-3 fw-bold text-danger mt-1"><?= $metrics['returned'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-success"></div>
            <span class="text-muted small text-uppercase fw-semibold">Active Projects</span>
            <div class="fs-3 fw-bold text-success mt-1"><?= $metrics['active_projects'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-dark"></div>
            <span class="text-muted small text-uppercase fw-semibold">Completed</span>
            <div class="fs-3 fw-bold text-dark mt-1"><?= $metrics['completed_projects'] ?></div>
        </div>
    </div>
</div>

<?php if ($metrics['returned'] > 0): ?>
    <div class="alert alert-danger d-flex align-items-center mb-4 shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
        <div>
            <strong>Action Required:</strong> You have <strong><?= $metrics['returned'] ?> proposal(s)</strong> returned by the Head of Department or Joint Director for revisions. Please review the reviewer comments, update the proposal, and resubmit.
            <a href="<?= url("/scientist/proposals.php?status=Returned") ?>" class="alert-link ms-2">View Returned Proposals &rarr;</a>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($activeFeedbacks)): ?>
    <!-- Reviewer Feedback Alert Banner -->
    <div class="card border-0 shadow-sm mb-4" style="border-left: 5px solid #0284c7 !important; background: #f0f9ff;">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary px-3 py-2 fs-6 shadow-xs">
                        <i class="bi bi-chat-quote-fill me-1"></i> Reviewer Feedback on Progress Report
                    </span>
                    <span class="badge bg-white text-primary border border-primary-subtle fw-semibold">
                        <?= count($activeFeedbacks) ?> Pending Feedback Alert<?= count($activeFeedbacks) > 1 ? 's' : '' ?>
                    </span>
                </div>
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i> Feedback issued by Joint Director / HOD requires your review
                </small>
            </div>
            
            <p class="text-muted small mb-3">
                The institutional review committee / Directorate has reviewed your submitted quarterly milestone report(s) and logged technical observations, guidance, or revision directives.
            </p>

            <div class="d-flex flex-column gap-3">
                <?php foreach ($activeFeedbacks as $fb): ?>
                    <?php
                    $isRevision = ($fb['review_status'] === 'Needs Revision');
                    $isApproved = ($fb['review_status'] === 'Approved / Accepted');
                    $statusColor = $isRevision ? 'warning' : ($isApproved ? 'success' : 'primary');
                    $statusBadgeBg = $isRevision ? 'bg-warning text-dark' : ($isApproved ? 'bg-success text-white' : 'bg-primary text-white');
                    ?>
                    <div class="bg-white rounded-3 p-3 border shadow-xs">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace small px-2 py-1">
                                    <i class="bi bi-journal-code me-1"></i><?= e($fb['project_number']) ?>
                                </span>
                                <strong class="text-dark ms-2"><?= e($fb['proposal_title']) ?></strong>
                                <span class="text-muted small d-block d-md-inline ms-md-2 mt-1 mt-md-0">
                                    &bull; Reporting Period: <strong><?= e($fb['report_period']) ?></strong>
                                </span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge <?= $statusBadgeBg ?> px-2 py-1">
                                    <?= e($fb['review_status'] ?: 'Reviewed') ?>
                                </span>
                                <span class="badge bg-light text-dark border">
                                    <i class="bi bi-person-badge me-1"></i><?= e($fb['reviewer_role'] ?: 'Joint Director') ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($fb['reviewer_comments'])): ?>
                            <div class="bg-light p-3 rounded-2 border-start border-4 border-<?= $isRevision ? 'warning' : 'primary' ?> my-2">
                                <div class="small fw-semibold text-secondary mb-1 d-flex justify-content-between">
                                    <span>
                                        <i class="bi bi-chat-left-quote-fill text-<?= $isRevision ? 'warning' : 'primary' ?> me-1"></i>
                                        Reviewer Observations & Directives:
                                    </span>
                                    <?php if (!empty($fb['reviewer_name'])): ?>
                                        <span class="text-muted small fw-normal">
                                            By: <strong><?= e($fb['reviewer_name']) ?></strong> (<?= e($fb['reviewer_designation'] ?? 'Directorate') ?>)
                                            <?= !empty($fb['reviewed_at']) ? ' &bull; ' . format_date($fb['reviewed_at']) : '' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-dark fst-italic ps-2">
                                    "<?= nl2br(e($fb['reviewer_comments'])) ?>"
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-2 border-top">
                            <div class="d-flex flex-wrap gap-2">
                                <a href="<?= url('/scientist/view-progress.php?project_id=' . $fb['project_id'] . '#report-' . $fb['id']) ?>" class="btn btn-sm btn-primary" style="background-color: #1a365d; border-color: #1a365d;">
                                    <i class="bi bi-journal-text me-1"></i> View Full Feedback Dossier
                                </a>
                                <?php if ($isRevision): ?>
                                    <a href="<?= url('/scientist/edit-progress.php?id=' . $fb['id']) ?>" class="btn btn-sm btn-warning fw-semibold">
                                        <i class="bi bi-pencil-square me-1"></i> Amend Progress Report
                                    </a>
                                <?php endif; ?>
                            </div>
                            <form method="POST" action="<?= url('/scientist/dashboard.php') ?>" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="acknowledge_feedback">
                                <input type="hidden" name="report_id" value="<?= (int)$fb['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-check2-circle me-1"></i> Mark as Read / Dismiss Alert
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Recent Proposals -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h6 class="m-0 fw-bold text-dark"><i class="bi bi-folder2-open text-primary me-2"></i>My Proposals</h6>
                </div>
                <ul class="nav nav-pills card-header-pills m-0 gap-1" style="font-size: 0.85rem;">
                    <li class="nav-item">
                        <a class="nav-link <?= $activeTab === 'all' ? 'active' : 'text-secondary' ?> py-1 px-3 fw-semibold" href="<?= url("/scientist/dashboard.php?tab=all") ?>">
                            All (<?= $metrics['total'] ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activeTab === 'new' ? 'active' : 'text-secondary' ?> py-1 px-3 fw-semibold" href="<?= url("/scientist/dashboard.php?tab=new") ?>">
                            <i class="bi bi-file-earmark-plus me-1"></i> New (<?= $countNew ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activeTab === 'ongoing' ? 'active' : 'text-secondary' ?> py-1 px-3 fw-semibold" href="<?= url("/scientist/dashboard.php?tab=ongoing") ?>">
                            <i class="bi bi-arrow-repeat me-1"></i> Ongoing (<?= $countOngoing ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activeTab === 'completed' ? 'active' : 'text-secondary' ?> py-1 px-3 fw-semibold" href="<?= url("/scientist/dashboard.php?tab=completed") ?>">
                            <i class="bi bi-journal-check me-1"></i> Completed (<?= $countCompleted ?>)
                        </a>
                    </li>
                </ul>
                <a href="<?= url("/scientist/proposals.php" . ($activeTab !== 'all' ? "?category={$activeTab}" : "")) ?>" class="btn btn-sm btn-outline-primary">
                    View All &rarr;
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Proposal #</th>
                            <th>Type</th>
                            <th>Title & Details</th>
                            <th>Status</th>
                            <th>Budget</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentProposals)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    No <?= ($activeTab !== 'all' ? e($activeTab) . ' ' : '') ?>proposals found. 
                                    <div class="mt-2">
                                        <a href="<?= url("/scientist/create-proposal.php") ?>" class="btn btn-sm btn-outline-primary me-2">
                                            <i class="bi bi-file-earmark-plus me-1"></i> Submit New Proposal
                                        </a>
                                        <a href="<?= url("/scientist/create-completed-proposal.php") ?>" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-journal-check me-1"></i> Submit Completed Proposal
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentProposals as $prop): ?>
                                <tr>
                                    <td class="fw-semibold font-monospace small">
                                        <?= e($prop['proposal_number']) ?>
                                        <?php if (!empty($prop['project_number'])): ?>
                                            <div class="extra-small text-muted font-monospace"><i class="bi bi-hash"></i><?= e($prop['project_number']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= render_proposal_category_badge($prop['proposal_category'] ?? 'new') ?>
                                    </td>
                                    <td>
                                        <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="text-decoration-none fw-semibold text-dark d-block">
                                            <?= e(mb_strimwidth($prop['title'], 0, 42, '...')) ?>
                                        </a>
                                        <small class="text-muted"><?= e($prop['department_name']) ?> | TRL-<?= e($prop['trl_level']) ?></small>
                                    </td>
                                    <td><?= render_status_badge($prop['current_status']) ?></td>
                                    <td class="small fw-semibold">
                                        <?php if (($prop['proposal_category'] ?? '') === 'completed' && !empty($prop['budget_utilized'])): ?>
                                            <div><?= format_currency((float)$prop['budget_allocated']) ?></div>
                                            <div class="extra-small text-muted">Util: <?= format_currency((float)$prop['budget_utilized']) ?></div>
                                        <?php else: ?>
                                            <?= format_currency((float)$prop['proposed_budget']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="btn btn-outline-secondary" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if (in_array($prop['current_status'], [STATUS_DRAFT, STATUS_RETURNED_HOD, STATUS_RETURNED_JD])): 
                                                $editUrl = url("/scientist/edit-proposal.php?id=" . $prop['id']);
                                                if (($prop['proposal_category'] ?? '') === 'ongoing') {
                                                    $editUrl = url("/scientist/ongoing-projects.php?id=" . $prop['id']);
                                                } elseif (($prop['proposal_category'] ?? '') === 'completed') {
                                                    $editUrl = url("/scientist/create-completed-proposal.php?id=" . $prop['id']);
                                                }
                                            ?>
                                                <a href="<?= $editUrl ?>" class="btn btn-outline-primary" title="<?= ($prop['proposal_category'] ?? '') === 'ongoing' ? 'Edit Ongoing Draft' : 'Edit Proposal' ?>">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?= url("/export-doc.php?id=" . ($prop['id']) . "") ?>" class="btn btn-outline-secondary" title="Download Word Export (.docx)">
                                                <i class="bi bi-file-earmark-word"></i>
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
    </div>

    <!-- Active Projects & Progress Tracking -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="m-0 fw-bold text-dark"><i class="bi bi-kanban text-success me-2"></i>My Active Projects</h6>
                <a href="<?= url("/scientist/projects.php") ?>" class="btn btn-sm btn-outline-success">Manage</a>
            </div>
            <div class="card-body p-3">
                <?php if (empty($activeProjects)): ?>
                    <p class="text-muted small mb-0 text-center py-3">No active approved projects currently running.</p>
                <?php else: ?>
                    <?php foreach ($activeProjects as $proj): ?>
                        <div class="border rounded p-3 mb-3 bg-light-subtle">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace small">
                                    <?= e($proj['project_number']) ?>
                                </span>
                                <small class="text-muted"><?= format_date($proj['start_date']) ?> to <?= format_date($proj['end_date']) ?></small>
                            </div>
                            <h6 class="fw-bold mb-2 text-dark">
                                <?= e(mb_strimwidth($proj['proposal_title'], 0, 55, '...')) ?>
                            </h6>
                            <div class="d-flex justify-content-between text-muted small mb-1">
                                <span>Approved Budget:</span>
                                <strong class="text-success"><?= format_currency((float)$proj['approved_budget']) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between text-muted small mb-2">
                                <span>Expended to Date:</span>
                                <strong class="<?= (float)$proj['total_expenditure'] > (float)$proj['approved_budget'] ? 'text-danger' : 'text-primary' ?>">
                                    <?= format_currency((float)$proj['total_expenditure']) ?>
                                </strong>
                            </div>

                            <?php if (!empty($proj['feedback_count']) && $proj['feedback_count'] > 0): ?>
                                <div class="bg-warning-subtle border border-warning-subtle rounded p-2 mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="badge bg-warning text-dark font-monospace" style="font-size: 0.68rem;">
                                            <i class="bi bi-chat-left-text-fill me-1"></i><?= e($proj['latest_reviewer_role'] ?: 'Reviewer') ?> Feedback
                                        </span>
                                        <span class="badge bg-dark" style="font-size: 0.65rem;"><?= e($proj['latest_review_status'] ?: 'Reviewed') ?></span>
                                    </div>
                                    <?php if (!empty($proj['latest_feedback'])): ?>
                                        <div class="text-dark small fst-italic" style="font-size: 0.78rem;">
                                            "<?= e(mb_strimwidth($proj['latest_feedback'], 0, 95, '...')) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex gap-2">
                                <a href="<?= url("/scientist/view-progress.php?project_id=" . ($proj['id']) . "") ?>" class="btn btn-sm btn-outline-success w-100">
                                    <i class="bi bi-list-check me-1"></i> Reports (<?= (int)($proj['reports_count'] ?? 0) ?>)
                                </a>
                                <a href="<?= url("/scientist/ongoing-projects.php?project_id=" . ($proj['id']) . "") ?>" class="btn btn-sm btn-outline-primary w-100">
                                    <i class="bi bi-arrow-repeat me-1"></i> Progress Report
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
