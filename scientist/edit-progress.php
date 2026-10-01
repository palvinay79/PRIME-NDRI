<?php
/**
 * Research Proposal and Project Management System
 * Scientist / HOD - Edit & Correct Periodic Progress Report
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD, ROLE_JOINT_DIRECTOR]);

$reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$userId = current_user_id();
$userRole = current_user_role_id();
$db = get_db();

$stmt = $db->prepare("SELECT r.*, pr.project_number, pr.approved_budget, pr.start_date, pr.end_date,
                             p.title as proposal_title, p.proposal_number,
                             COALESCE(pr.department_id, p.department_id) as department_id,
                             u.name as scientist_name,
                             (SELECT p_on.current_status FROM proposals p_on 
                              WHERE p_on.linked_project_id = r.project_id 
                                AND p_on.proposal_category = 'ongoing' 
                                AND (p_on.progress_report_period = r.report_period OR p_on.progress_report_period = r.reporting_period OR p_on.progress_report_period LIKE '%' || r.report_period || '%') 
                              ORDER BY p_on.id DESC LIMIT 1) as linked_proposal_status
                      FROM progress_reports r
                      JOIN projects pr ON r.project_id = pr.id
                      JOIN proposals p ON pr.proposal_id = p.id
                      JOIN users u ON pr.scientist_id = u.id
                      WHERE r.id = ?");
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    flash('danger', 'Progress report not found.');
    header("Location: " . url("/scientist/projects.php"));
    exit;
}

// Access check:
// Scientist can only edit their own report
if ($userRole === ROLE_SCIENTIST && (int)$report['submitted_by'] !== $userId) {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}
// HOD can only edit reports in their department
if ($userRole === ROLE_HOD && (int)$report['department_id'] !== (int)current_user_department_id()) {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}

$projectId = (int)$report['project_id'];

// Check permission: Once submitted to Joint Director or approved by IRC, report cannot be edited by scientist
if (!can_edit_progress_report($report, $userId, $userRole)) {
    flash('danger', 'This progress report has been submitted to the Joint Director / approved by IRC and is locked against modifications.');
    header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
    exit;
}
$approvedBudget = (float)$report['approved_budget'];

// Calculate other reports' total expenditure
$otherStmt = $db->prepare("SELECT COALESCE(SUM(COALESCE(budget_utilized, budget_utilization, 0)), 0)
                           FROM progress_reports
                           WHERE project_id = ? AND id != ?");
$otherStmt->execute([$projectId, $reportId]);
$otherUtilized = (float)$otherStmt->fetchColumn();
$remainingBudget = max(0, $approvedBudget - $otherUtilized);

$currentExpenditure = (float)($report['budget_utilized'] ?? $report['budget_utilization'] ?? 0);
$reportPeriod = $report['report_period'] ?? $report['reporting_period'] ?? '';
$nextPlan = $report['next_period_plan'] ?? $report['next_steps'] ?? '';

$pageTitle = 'Edit Progress Report - ' . $report['project_number'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!can_edit_progress_report($report, $userId, $userRole)) {
        flash('danger', 'This progress report has been submitted to the Joint Director / approved by IRC and cannot be updated.');
        header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
        exit;
    }

    $newReportPeriod = sanitize($_POST['report_period'] ?? '');
    $newProgressSummary = sanitize($_POST['progress_summary'] ?? '');
    $newWorkCompleted = sanitize($_POST['work_completed'] ?? '');
    $newAchievements = sanitize($_POST['achievements'] ?? '');
    $newChallenges = sanitize($_POST['challenges'] ?? '');
    $newNextPeriodPlan = sanitize($_POST['next_period_plan'] ?? '');
    $newBudgetUtilized = (float)($_POST['budget_utilized'] ?? 0);

    if (empty($newReportPeriod) || empty($newProgressSummary) || empty($newWorkCompleted)) {
        $error = "Reporting period, progress summary, and work completed are mandatory fields.";
    } else {
        try {
            $db->beginTransaction();

            $docPath = $report['document_path'];

            $now = date('Y-m-d H:i:s');

            // Defensive columns check
            $cols = [];
            try {
                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'mysql') {
                    $cols = $db->query("SHOW COLUMNS FROM progress_reports")->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $cols = $db->query("PRAGMA table_info(progress_reports)")->fetchAll(PDO::FETCH_COLUMN, 1);
                }
            } catch (Exception $eCols) {}

            $updateFields = [
                'progress_summary = ?',
                'work_completed = ?',
                'achievements = ?',
                'challenges = ?',
                'document_path = ?',
            ];
            $params = [
                $newProgressSummary,
                $newWorkCompleted,
                $newAchievements,
                $newChallenges,
                $docPath,
            ];

            if (empty($cols) || in_array('report_period', $cols)) {
                $updateFields[] = 'report_period = ?';
                $params[] = $newReportPeriod;
            }
            if (in_array('reporting_period', $cols)) {
                $updateFields[] = 'reporting_period = ?';
                $params[] = $newReportPeriod;
            }
            if (empty($cols) || in_array('budget_utilized', $cols)) {
                $updateFields[] = 'budget_utilized = ?';
                $params[] = $newBudgetUtilized;
            }
            if (in_array('budget_utilization', $cols)) {
                $updateFields[] = 'budget_utilization = ?';
                $params[] = $newBudgetUtilized;
            }
            if (empty($cols) || in_array('next_period_plan', $cols)) {
                $updateFields[] = 'next_period_plan = ?';
                $params[] = $newNextPeriodPlan;
            }
            if (in_array('next_steps', $cols)) {
                $updateFields[] = 'next_steps = ?';
                $params[] = $newNextPeriodPlan;
            }
            if (empty($cols) || in_array('updated_at', $cols)) {
                $updateFields[] = 'updated_at = ?';
                $params[] = $now;
            }

            $params[] = $reportId;
            $sql = "UPDATE progress_reports SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $updateStmt = $db->prepare($sql);
            $updateStmt->execute($params);

            // Audit log
            $auditDesc = "Progress report for period '{$newReportPeriod}' updated. Budget expenditure adjusted from " . format_currency($currentExpenditure) . " to " . format_currency($newBudgetUtilized) . ".";
            log_audit($userId, 'PROGRESS_REPORT_UPDATED', 'progress_reports', $reportId, $auditDesc);

            $db->commit();

            flash('success', "Progress report updated successfully. Period expenditure recorded as " . format_currency($newBudgetUtilized) . ".");
            header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to update progress report: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Edit Progress Report</h3>
        <p class="text-muted small mb-0">Project: <strong><?= e($report['project_number']) ?></strong> - <?= e($report['proposal_title']) ?></p>
    </div>
    <div>
        <a href="<?= url("/scientist/view-progress.php?project_id={$projectId}") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Reports
        </a>
    </div>
</div>

<!-- Project Financial Scope Information Card -->
<div class="card shadow-sm border-0 mb-4 bg-light">
    <div class="card-body p-3">
        <div class="row g-3 align-items-center">
            <div class="col-md-4">
                <span class="text-muted small d-block">Approved Project Grant:</span>
                <h4 class="fw-bold text-success mb-0" id="approvedBudgetVal" data-value="<?= $approvedBudget ?>">
                    <?= format_currency($approvedBudget) ?>
                </h4>
            </div>
            <div class="col-md-4">
                <span class="text-muted small d-block">Previously Entered Expenditure:</span>
                <h5 class="fw-bold <?= $currentExpenditure > $approvedBudget ? 'text-danger' : 'text-dark' ?> mb-0">
                    <?= format_currency($currentExpenditure) ?>
                    <?php if ($currentExpenditure > $approvedBudget): ?>
                        <span class="badge bg-danger small ms-1" style="font-size: 0.7rem;">Exceeds Budget</span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="col-md-4">
                <span class="text-muted small d-block">Remaining Balance (excl. this report):</span>
                <h5 class="fw-bold text-primary mb-0" id="remainingBudgetVal" data-value="<?= $remainingBudget ?>">
                    <?= format_currency($remainingBudget) ?>
                </h5>
            </div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<form method="POST" action="<?= url("/scientist/edit-progress.php?id={$reportId}") ?>" enctype="multipart/form-data" id="editReportForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-calendar3 text-primary"></i> 1. Reporting Period & Financial Utilization
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Reporting Period (Quarter / Milestone) *</label>
                    <input type="text" name="report_period" class="form-control" value="<?= e($reportPeriod) ?>" placeholder="e.g. Q1 (Apr - Jun 2026)" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Grant Budget Utilized in This Period (₹ INR) *</label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" id="budgetUtilizedInput" name="budget_utilized" class="form-control fw-bold" step="0.01" min="0" value="<?= e($currentExpenditure) ?>" required>
                    </div>
                    <div id="budgetWarningBox" class="alert alert-warning py-2 px-3 small mt-2 mb-0 <?= $currentExpenditure > $approvedBudget ? '' : 'd-none' ?>">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>Notice:</strong> The entered period expenditure (<span id="warnEnteredAmt">₹<?= number_format($currentExpenditure, 2) ?></span>) exceeds the total project approved grant (₹<?= number_format($approvedBudget, 2) ?>). Please ensure you have not accidentally added extra digits.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-check2-circle text-primary"></i> 2. Technical Milestones & Experimental Achievements
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Executive Progress Summary *</label>
                    <textarea name="progress_summary" class="form-control" rows="3" required><?= e($report['progress_summary']) ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Detailed Work Completed Against Objectives *</label>
                    <textarea name="work_completed" class="form-control" rows="4" required><?= e($report['work_completed']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Key Achievements / Publications / Patents</label>
                    <textarea name="achievements" class="form-control" rows="3"><?= e($report['achievements']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Technical Challenges / Deviations</label>
                    <textarea name="challenges" class="form-control" rows="3"><?= e($report['challenges']) ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Plan of Work for Next Milestone / Reporting Period</label>
                    <textarea name="next_period_plan" class="form-control" rows="3"><?= e($nextPlan) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body d-flex justify-content-between align-items-center">
            <a href="<?= url("/scientist/view-progress.php?project_id={$projectId}") ?>" class="btn btn-outline-secondary">
                Cancel
            </a>
            <button type="submit" class="btn btn-primary fw-semibold px-4" style="background-color: #1a365d; border-color: #1a365d;">
                <i class="bi bi-check2-circle me-1"></i> Save Report Changes
            </button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const budgetInput = document.getElementById('budgetUtilizedInput');
    const warningBox = document.getElementById('budgetWarningBox');
    const warnAmtSpan = document.getElementById('warnEnteredAmt');
    const approvedBudget = parseFloat(document.getElementById('approvedBudgetVal').dataset.value || 0);

    function checkBudget() {
        const val = parseFloat(budgetInput.value || 0);
        if (approvedBudget > 0 && val > approvedBudget) {
            warningBox.classList.remove('d-none');
            warnAmtSpan.textContent = '₹' + val.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        } else {
            warningBox.classList.add('d-none');
        }
    }

    budgetInput.addEventListener('input', checkBudget);
    checkBudget();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
