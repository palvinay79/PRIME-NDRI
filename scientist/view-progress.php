<?php
/**
 * Research Proposal and Project Management System
 * View Project Progress Reports, Financial Utilization & HOD/JD Review
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD, ROLE_JOINT_DIRECTOR]);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$userId = current_user_id();
$userRole = current_user_role_id();
$db = get_db();

// Determine default return link depending on user's role
$backUrl = url('/scientist/projects.php');
if ($userRole === ROLE_HOD) {
    $backUrl = url('/hod/projects.php');
} elseif ($userRole === ROLE_JOINT_DIRECTOR) {
    $backUrl = url('/joint-director/projects.php');
}

$stmt = $db->prepare("SELECT pr.*, COALESCE(pr.department_id, p.department_id) as department_id,
                             p.title as proposal_title, p.proposal_number,
                             p.project_type as prop_project_type, p.funding_agency as prop_funding_agency,
                             p.funding_agency_type as prop_funding_agency_type, p.yearly_budget as prop_yearly_budget,
                             u.name as scientist_name, u.email as scientist_email, u.designation as scientist_designation,
                             d.department_name, d.department_code
                      FROM projects pr
                      JOIN proposals p ON pr.proposal_id = p.id
                      JOIN users u ON pr.scientist_id = u.id
                      JOIN departments d ON COALESCE(pr.department_id, p.department_id) = d.id
                      WHERE pr.id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    flash('danger', 'Project not found.');
    header("Location: " . $backUrl);
    exit;
}

// Access check: scientist can view own; HOD can view department; JD can view all
if ($userRole === ROLE_SCIENTIST && (int)$project['scientist_id'] !== $userId) {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}
if ($userRole === ROLE_HOD && (int)$project['department_id'] !== (int)current_user_department_id()) {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}

// Handle POST actions: Review comments (HOD/JD) or Quick Budget Correction (Scientist)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Quick Budget Correction (Scientist / JD)
    if ($action === 'quick_fix_budget') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $newBudget = (float)($_POST['corrected_budget'] ?? 0);

        $checkStmt = $db->prepare("SELECT * FROM progress_reports WHERE id = ? AND project_id = ?");
        $checkStmt->execute([$reportId, $projectId]);
        $targetReport = $checkStmt->fetch();

        if ($targetReport && ($userRole === ROLE_JOINT_DIRECTOR || (int)$targetReport['submitted_by'] === $userId)) {
            if (!can_edit_progress_report($targetReport, $userId, $userRole)) {
                flash('danger', 'This progress report has been submitted to Joint Director / approved by IRC and its budget cannot be modified.');
                header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
                exit;
            }

            $oldAmt = (float)($targetReport['budget_utilized'] ?? $targetReport['budget_utilization'] ?? 0);
            $now = date('Y-m-d H:i:s');

            $db->prepare("UPDATE progress_reports
                          SET budget_utilized = ?, budget_utilization = ?, updated_at = ?
                          WHERE id = ?")->execute([$newBudget, $newBudget, $now, $reportId]);

            log_audit($userId, 'PROGRESS_REPORT_UPDATED', 'progress_reports', $reportId,
                "Corrected budget expenditure for period '{$targetReport['report_period']}' on project {$project['project_number']} from " . format_currency($oldAmt) . " to " . format_currency($newBudget) . ".");

            flash('success', "Period budget expenditure corrected to " . format_currency($newBudget) . " successfully.");
        } else {
            flash('danger', 'Unauthorized or invalid report.');
        }

        header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
        exit;
    }

    // 2. Review Comments & Status Update (HOD / JD)
    if ($action === 'review_report') {
        if ($userRole !== ROLE_HOD && $userRole !== ROLE_JOINT_DIRECTOR) {
            flash('danger', 'Only Head of Department or Joint Director can record progress reviews.');
            header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
            exit;
        }

        $reportId = (int)($_POST['report_id'] ?? 0);
        $reviewStatus = sanitize($_POST['review_status'] ?? 'Reviewed');
        $reviewerComments = sanitize($_POST['reviewer_comments'] ?? '');

        $checkStmt = $db->prepare("SELECT * FROM progress_reports WHERE id = ? AND project_id = ?");
        $checkStmt->execute([$reportId, $projectId]);
        $targetReport = $checkStmt->fetch();

        if ($targetReport) {
            $reviewerRoleName = ($userRole === ROLE_JOINT_DIRECTOR) ? 'Joint Director' : 'Head of Department';
            $now = date('Y-m-d H:i:s');

            try {
                $db->prepare("UPDATE progress_reports
                              SET review_status = ?, reviewer_comments = ?, reviewed_by = ?, reviewer_role = ?, reviewed_at = ?, feedback_viewed_by_scientist = 0, updated_at = ?
                              WHERE id = ?")->execute([$reviewStatus, $reviewerComments, $userId, $reviewerRoleName, $now, $now, $reportId]);
            } catch (Exception $e) {
                $db->prepare("UPDATE progress_reports
                              SET review_status = ?, reviewer_comments = ?
                              WHERE id = ?")->execute([$reviewStatus, $reviewerComments, $reportId]);
            }

            // If HOD or JD updates review status, synchronize with any linked ongoing proposal in proposals table
            $pPeriod = $targetReport['report_period'] ?? $targetReport['reporting_period'] ?? '';
            if (!empty($pPeriod)) {
                try {
                    $propStmt = $db->prepare("SELECT id, current_status FROM proposals 
                                              WHERE linked_project_id = ? 
                                                AND proposal_category = 'ongoing' 
                                                AND (progress_report_period = ? OR progress_report_period LIKE ?)
                                              LIMIT 1");
                    $propStmt->execute([$projectId, $pPeriod, '%' . $pPeriod . '%']);
                    $linkedP = $propStmt->fetch();
                    if ($linkedP) {
                        $propNewStatus = null;
                        if ($reviewStatus === 'Forwarded to Joint Director') {
                            $propNewStatus = STATUS_FORWARDED_JD;
                        } elseif ($reviewStatus === 'Needs Revision') {
                            $propNewStatus = ($userRole === ROLE_JOINT_DIRECTOR) ? STATUS_RETURNED_JD : STATUS_RETURNED_HOD;
                        } elseif (in_array($reviewStatus, ['Approved', 'Approved / Accepted'], true)) {
                            if ($userRole === ROLE_JOINT_DIRECTOR) {
                                $propNewStatus = STATUS_APPROVED_IRC_MEETING;
                            }
                        }

                        if ($propNewStatus && $propNewStatus !== $linkedP['current_status']) {
                            $db->prepare("UPDATE proposals SET current_status = ?, updated_at = ? WHERE id = ?")
                               ->execute([$propNewStatus, $now, $linkedP['id']]);
                            record_status_history($linkedP['id'], $linkedP['current_status'], $propNewStatus, $userId, $reviewerRoleName, 
                                "Progress review decision: {$reviewStatus}. Remarks: {$reviewerComments}");
                        }
                    }
                } catch (Throwable $eProp) {}
            }

            log_audit($userId, 'PROGRESS_REPORT_REVIEWED', 'progress_reports', $reportId,
                "Reviewed progress report for period '{$targetReport['report_period']}' on project {$project['project_number']}. Status: {$reviewStatus}. Comments: {$reviewerComments}");

            flash('success', "Progress report review saved successfully.");
        } else {
            flash('danger', 'Report not found.');
        }

        header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
        exit;
    }
}

$pageTitle = 'Progress Reports - ' . $project['project_number'];

// Fetch all progress reports with reviewer details and linked proposal status
$stmt = $db->prepare("SELECT r.*, u_rev.name as reviewer_name, u_rev.designation as reviewer_designation,
                             (SELECT p.current_status FROM proposals p 
                              WHERE p.linked_project_id = r.project_id 
                                AND p.proposal_category = 'ongoing' 
                                AND (p.progress_report_period = r.report_period OR p.progress_report_period = r.reporting_period) 
                              ORDER BY p.id DESC LIMIT 1) as linked_proposal_status
                      FROM progress_reports r
                      LEFT JOIN users u_rev ON r.reviewed_by = u_rev.id
                      WHERE r.project_id = ?
                      ORDER BY COALESCE(r.submitted_at, r.created_at, r.id) DESC");
$stmt->execute([$projectId]);
$reports = $stmt->fetchAll();

// Calculate total expenditure across all reports
$totalUtilized = 0.0;
foreach ($reports as $r) {
    $totalUtilized += (float)($r['budget_utilized'] ?? $r['budget_utilization'] ?? 0);
}
$approvedBudget = (float)$project['approved_budget'];
$remainingBudget = max(0, $approvedBudget - $totalUtilized);
$utilizationPct = $approvedBudget > 0 ? min(100, round(($totalUtilized / $approvedBudget) * 100)) : 0;

include __DIR__ . '/../includes/header.php';
?>

<?php 
$agencyDetails = get_funding_agency_details($project);
$projectCopis = get_project_copis($projectId);
if (empty($projectCopis)) {
    $projectCopis = get_proposal_copis((int)($project['proposal_id'] ?? 0), $projectId, (string)($project['project_number'] ?? ''));
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
            <h3 class="fw-bold mb-0 text-dark">Research Progress & Financial Dossiers</h3>
            <?= $agencyDetails['badge_html'] ?>
        </div>
        <p class="text-muted small mb-0">
            Project: <strong><?= e($project['project_number']) ?></strong> &bull; &ldquo;<?= e($project['proposal_title']) ?>&rdquo; |
            Lead: <strong><?= e($project['scientist_name']) ?></strong> (<?= e($project['department_name']) ?>)
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $backUrl ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Projects
        </a>
        <a href="<?= url("/scientist/proposal-details.php?id=" . (int)$project['proposal_id']) ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-file-text me-1"></i> View Proposal
        </a>
        <?php if ($userRole === ROLE_SCIENTIST || $userRole === ROLE_JOINT_DIRECTOR): ?>
            <a href="<?= url("/scientist/ongoing-projects.php?project_id=" . ($projectId) . "") ?>" class="btn btn-primary btn-sm" style="background-color: #1a365d; border-color: #1a365d;">
                <i class="bi bi-arrow-repeat me-1"></i> Submit Progress Report
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Project Financial & Administrative Overview Banner -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <div class="row g-3 align-items-center mb-3">
            <div class="col-md-3">
                <span class="text-muted small d-block">Approved Project Grant:</span>
                <h4 class="fw-bold text-success mb-0"><?= format_currency($approvedBudget) ?></h4>
            </div>
            <div class="col-md-3">
                <span class="text-muted small d-block">Total Expended to Date:</span>
                <h4 class="fw-bold <?= $totalUtilized > $approvedBudget ? 'text-danger' : 'text-primary' ?> mb-0">
                    <?= format_currency($totalUtilized) ?>
                    <?php if ($totalUtilized > $approvedBudget): ?>
                        <span class="badge bg-danger small ms-1" style="font-size: 0.68rem;">Exceeds Budget</span>
                    <?php endif; ?>
                </h4>
            </div>
            <div class="col-md-3">
                <span class="text-muted small d-block">Remaining Grant Balance:</span>
                <h4 class="fw-bold <?= $remainingBudget <= 0 ? 'text-secondary' : 'text-success' ?> mb-0">
                    <?= format_currency($remainingBudget) ?>
                </h4>
            </div>
            <div class="col-md-3">
                <span class="text-muted small d-block">Budget Utilization:</span>
                <div class="d-flex align-items-center gap-2 mt-1">
                    <div class="progress flex-grow-1" style="height: 8px;">
                        <div class="progress-bar <?= $totalUtilized > $approvedBudget ? 'bg-danger' : 'bg-success' ?>" role="progressbar" style="width: <?= $utilizationPct ?>%"></div>
                    </div>
                    <span class="small fw-bold text-dark"><?= $utilizationPct ?>%</span>
                </div>
            </div>
        </div>

        <!-- Year-wise Budget Allocation Breakdown -->
        <div class="pt-2 border-top">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="extra-small fw-bold text-uppercase text-secondary">
                    <i class="bi bi-calendar3-range text-success me-1"></i> Year-wise Sanctioned Budget Allocation
                </span>
                <span class="badge bg-success-subtle text-success border border-success-subtle extra-small">
                    Total: <?= format_currency($approvedBudget) ?>
                </span>
            </div>
            <?= render_yearly_budget_html($project['yearly_budget'] ?: ($project['prop_yearly_budget'] ?? null), $approvedBudget) ?>
        </div>

        <!-- Co-Principal Investigators of this Project -->
        <div class="pt-2 mt-2 border-top">
            <span class="extra-small fw-bold text-uppercase text-secondary d-block mb-1">
                <i class="bi bi-people-fill text-primary me-1"></i> Co-Principal Investigators (Co-PIs) on this Project (<?= count($projectCopis) ?>):
            </span>
            <?php if (!empty($projectCopis)): ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($projectCopis as $cp): ?>
                        <div class="badge bg-light text-dark border p-2 text-start d-inline-flex align-items-center gap-2">
                            <i class="bi bi-person-check-fill text-success fs-6"></i>
                            <div>
                                <div class="fw-bold"><?= e($cp['co_pi_name'] ?? ($cp['name'] ?? 'Co-PI')) ?></div>
                                <div class="extra-small text-muted"><?= e($cp['designation'] ?: 'Co-PI') ?><?= !empty($cp['institution']) ? ' &bull; ' . e($cp['institution']) : '' ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <span class="text-muted extra-small">No Co-Principal Investigators registered on this project charter.</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <?php if (empty($reports)): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 py-5 text-center text-muted">
                <i class="bi bi-file-earmark-text fs-1 mb-2"></i>
                <h5 class="fw-bold text-dark">No Progress Reports Filed Yet</h5>
                <p class="small mb-3">Keep research compliance current by filing periodic progress and expenditure dossiers.</p>
                <?php if ($userRole === ROLE_SCIENTIST || $userRole === ROLE_JOINT_DIRECTOR): ?>
                    <div>
                        <a href="<?= url("/scientist/ongoing-projects.php?project_id=" . ($projectId) . "") ?>" class="btn btn-primary btn-sm" style="background-color: #1a365d; border-color: #1a365d;">
                            <i class="bi bi-arrow-repeat me-1"></i> Submit First Progress Report
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($reports as $idx => $rep): ?>
            <?php
            $period = $rep['report_period'] ?? $rep['reporting_period'] ?? 'Reporting Period';
            $expenditure = (float)($rep['budget_utilized'] ?? $rep['budget_utilization'] ?? 0);
            $isOverBudget = $approvedBudget > 0 && $expenditure > $approvedBudget;
            $isReportApproved = is_report_approved_by_irc($rep);
            $isSubmittedToJd = is_report_submitted_to_jd($rep);
            $canEdit = can_edit_progress_report($rep, $userId, $userRole);
            $canDelete = can_delete_progress_report($rep, $userId, $userRole);
            $canReview = ($userRole === ROLE_HOD || $userRole === ROLE_JOINT_DIRECTOR);
            $status = $rep['review_status'] ?? 'Submitted';
            ?>
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary px-2 py-1"><?= e($period) ?></span>
                            <span class="small text-muted">
                                <i class="bi bi-clock me-1"></i>Submitted on <?= format_datetime($rep['created_at'] ?? $rep['submitted_at']) ?>
                            </span>
                            <?php if ($isOverBudget): ?>
                                <span class="badge bg-warning text-dark border border-warning" title="Expenditure exceeds approved grant">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Exceeds Total Budget
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php
                                $badgeCls = 'bg-secondary-subtle text-dark border';
                                if (in_array($status, ['Approved', 'Approved / Accepted', 'Reviewed'])) {
                                    $badgeCls = 'bg-success text-white';
                                } elseif (in_array($status, ['Forwarded to Joint Director', 'Submitted to Joint Director', 'Approved for IRC'])) {
                                    $badgeCls = 'bg-info text-dark border border-info';
                                } elseif (in_array($status, ['Needs Revision', 'Returned'])) {
                                    $badgeCls = 'bg-warning text-dark';
                                }
                            ?>
                            <span class="badge <?= $badgeCls ?>">
                                Status: <?= e($status) ?>
                            </span>

                            <?php if ($isReportApproved): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle" title="Approved by IRC - Report Locked">
                                    <i class="bi bi-lock-fill me-1"></i>Approved by IRC
                                </span>
                            <?php elseif ($isSubmittedToJd): ?>
                                <span class="badge bg-info-subtle text-primary border border-info-subtle" title="Submitted to Joint Director - Report Locked for Review">
                                    <i class="bi bi-lock-fill me-1"></i>With Joint Director
                                </span>
                            <?php endif; ?>

                            <?php if ($canEdit): ?>
                                <a href="<?= url("/scientist/edit-progress.php?id=" . (int)$rep['id']) ?>" class="btn btn-sm btn-outline-primary" title="Edit entire report">
                                    <i class="bi bi-pencil-square me-1"></i> Edit Report
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#quickFixModal<?= $rep['id'] ?>" title="Correct Period Budget Expenditure">
                                    <i class="bi bi-currency-rupee me-1"></i> Correct Budget
                                </button>
                            <?php elseif ($userRole === ROLE_SCIENTIST && ($isSubmittedToJd || $isReportApproved)): ?>
                                <span class="badge bg-light text-muted border px-2 py-1" title="Editing and deleting are disabled: HOD has submitted this progress report to Joint Director">
                                    <i class="bi bi-lock-fill text-secondary me-1"></i>Edit &amp; Delete Disabled
                                </span>
                            <?php endif; ?>

                            <?php if ($canDelete): ?>
                                <form method="POST" action="<?= url("/scientist/delete-progress.php") ?>" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this progress report?');">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="report_id" value="<?= (int)$rep['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Report">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canReview): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#reviewModal<?= $rep['id'] ?>">
                                    <i class="bi bi-chat-left-dots me-1"></i> Review Feedback
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <?php if ($isOverBudget): ?>
                            <div class="alert alert-warning py-2 px-3 small mb-3 border-warning">
                                <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                                <strong>Budget Notice:</strong> The recorded period expenditure of <strong><?= format_currency($expenditure) ?></strong> exceeds the total approved project grant of <strong><?= format_currency($approvedBudget) ?></strong>.
                                <?php if ($canEdit): ?>
                                    If this was a typographical mistake, click <strong>"Edit Report"</strong> or <strong>"Correct Budget"</strong> above to adjust the amount.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <h6 class="fw-bold text-dark small text-uppercase mb-1">Executive Summary:</h6>
                                <p class="text-secondary small mb-3" style="line-height: 1.6;"><?= nl2br(e($rep['progress_summary'])) ?></p>

                                <h6 class="fw-bold text-dark small text-uppercase mb-1">Work Accomplished Against Milestones:</h6>
                                <p class="text-secondary small mb-3" style="line-height: 1.6;"><?= nl2br(e($rep['work_completed'])) ?></p>

                                <?php if (!empty($rep['achievements'])): ?>
                                    <h6 class="fw-bold text-dark small text-uppercase mb-1">Key Achievements & Scientific Outputs:</h6>
                                    <p class="text-secondary small mb-3" style="line-height: 1.6;"><?= nl2br(e($rep['achievements'])) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($rep['challenges'])): ?>
                                    <h6 class="fw-bold text-dark small text-uppercase mb-1">Challenges & Protocol Deviations:</h6>
                                    <p class="text-secondary small mb-3 text-warning-emphasis" style="line-height: 1.6;"><?= nl2br(e($rep['challenges'])) ?></p>
                                <?php endif; ?>

                                <?php
                                $nextPlan = $rep['next_period_plan'] ?? $rep['next_steps'] ?? '';
                                if (!empty($nextPlan)):
                                ?>
                                    <h6 class="fw-bold text-dark small text-uppercase mb-1">Next Period Targets:</h6>
                                    <p class="text-secondary small mb-0" style="line-height: 1.6;"><?= nl2br(e($nextPlan)) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-4">
                                <div class="bg-light p-3 rounded border">
                                    <span class="text-muted small d-block">Period Budget Expenditure:</span>
                                    <h4 class="fw-bold <?= $isOverBudget ? 'text-danger' : 'text-success' ?> mb-2">
                                        <?= format_currency($expenditure) ?>
                                    </h4>

                                    <?php if (!empty($rep['document_path'])): ?>
                                        <hr class="my-2 text-muted">
                                        <span class="text-muted small d-block mb-1">Attached Dossier:</span>
                                        <a href="<?= e($rep['document_path']) ?>" download class="btn btn-sm btn-outline-primary w-100">
                                            <i class="bi bi-download me-1"></i> Download Attachment
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($rep['reviewer_comments'])): ?>
                                        <hr class="my-2 text-muted">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="text-primary small fw-semibold">
                                                <i class="bi bi-chat-quote-fill me-1"></i>Reviewer Feedback:
                                            </span>
                                            <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.7rem;">
                                                <?= e($rep['reviewer_role'] ?? 'Joint Director') ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($rep['reviewer_name'])): ?>
                                            <div class="text-muted extra-small mb-1" style="font-size: 0.73rem;">
                                                By: <strong><?= e($rep['reviewer_name']) ?></strong>
                                                <?= !empty($rep['reviewed_at']) ? ' &bull; ' . format_date($rep['reviewed_at']) : '' ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="small text-dark fst-italic p-2 bg-white rounded border border-start-3 border-start-primary">
                                            "<?= nl2br(e($rep['reviewer_comments'])) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Budget Correction Modal (Scientist / JD) -->
            <?php if ($canEdit): ?>
                <div class="modal fade" id="quickFixModal<?= $rep['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="<?= url("/scientist/view-progress.php?project_id={$projectId}") ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="quick_fix_budget">
                                <input type="hidden" name="report_id" value="<?= (int)$rep['id'] ?>">

                                <div class="modal-header">
                                    <h5 class="modal-title fw-bold">
                                        <i class="bi bi-pencil-square text-primary me-1"></i> Correct Period Budget Expenditure
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="text-muted small mb-3">
                                        Period: <strong><?= e($period) ?></strong> | Project Approved Budget: <strong class="text-success"><?= format_currency($approvedBudget) ?></strong>
                                    </p>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary">Corrected Expenditure Amount (₹ INR) *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" name="corrected_budget" class="form-control form-control-lg fw-bold" step="0.01" min="0" value="<?= e($expenditure) ?>" required>
                                        </div>
                                        <div class="form-text text-muted">
                                            Update this field if you mistakenly entered an incorrect expenditure (such as extra zeros).
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary fw-semibold" style="background-color: #1a365d; border-color: #1a365d;">
                                        <i class="bi bi-check2 me-1"></i> Update Expenditure
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- HOD / JD Review Feedback Modal -->
            <?php if ($canReview): ?>
                <div class="modal fade" id="reviewModal<?= $rep['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="<?= url("/scientist/view-progress.php?project_id={$projectId}") ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="review_report">
                                <input type="hidden" name="report_id" value="<?= (int)$rep['id'] ?>">

                                <div class="modal-header">
                                    <h5 class="modal-title fw-bold">
                                        <i class="bi bi-clipboard-check text-warning me-1"></i> Review Progress Report (<?= e($period) ?>)
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary">Review Evaluation Status</label>
                                        <select name="review_status" class="form-select">
                                            <option value="Forwarded to Joint Director" <?= $status === 'Forwarded to Joint Director' ? 'selected' : '' ?>>Forward to Joint Director</option>
                                            <option value="Reviewed" <?= $status === 'Reviewed' ? 'selected' : '' ?>>Reviewed</option>
                                            <option value="Approved / Accepted" <?= $status === 'Approved / Accepted' ? 'selected' : '' ?>>Approved / Accepted</option>
                                            <option value="Needs Revision" <?= $status === 'Needs Revision' ? 'selected' : '' ?>>Needs Revision</option>
                                            <option value="Under Review" <?= $status === 'Under Review' ? 'selected' : '' ?>>Under Review</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary">Reviewer Comments / Directives</label>
                                        <textarea name="reviewer_comments" class="form-control" rows="4" placeholder="Provide feedback on milestone targets, budget rate, or recommendations..."><?= e($rep['reviewer_comments'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary fw-semibold" style="background-color: #1a365d; border-color: #1a365d;">
                                        <i class="bi bi-send-check me-1"></i> Save Review Feedback
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
