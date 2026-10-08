<?php
/**
 * Research Proposal and Project Management System
 * Scientist - Submit Periodic Progress Report
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD]);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$redirectUrl = $projectId > 0 
    ? url("/scientist/ongoing-projects.php?project_id=" . $projectId)
    : url("/scientist/ongoing-projects.php");

header("Location: " . $redirectUrl);
exit;

$pageTitle = 'Submit Progress Report - ' . $project['project_number'];
$error = null;

// Calculate prior utilized expenditure
$priorStmt = $db->prepare("SELECT COALESCE(SUM(COALESCE(budget_utilized, budget_utilization, 0)), 0)
                           FROM progress_reports WHERE project_id = ?");
$priorStmt->execute([$projectId]);
$priorUtilized = (float)$priorStmt->fetchColumn();
$approvedBudget = (float)$project['approved_budget'];
$remainingBudget = max(0, $approvedBudget - $priorUtilized);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $reportPeriod = sanitize($_POST['report_period'] ?? '');
    $progressSummary = sanitize($_POST['progress_summary'] ?? '');
    $workCompleted = sanitize($_POST['work_completed'] ?? '');
    $achievements = sanitize($_POST['achievements'] ?? '');
    $challenges = sanitize($_POST['challenges'] ?? '');
    $nextPeriodPlan = sanitize($_POST['next_period_plan'] ?? '');
    $budgetUtilized = (float)($_POST['budget_utilized'] ?? 0);

    if (empty($reportPeriod) || empty($progressSummary) || empty($workCompleted)) {
        $error = "Reporting period, progress summary, and work completed are mandatory.";
    } else {
        try {
            $db->beginTransaction();

            $docPath = null;

            $now = date('Y-m-d H:i:s');
            
            // Defensive insertion for progress_reports
            $cols = [];
            try {
                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'mysql') {
                    $cols = $db->query("SHOW COLUMNS FROM progress_reports")->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $cols = $db->query("PRAGMA table_info(progress_reports)")->fetchAll(PDO::FETCH_COLUMN, 1);
                }
            } catch (Exception $eCols) {}

            $insertData = [
                'project_id' => $projectId,
                'submitted_by' => $userId,
                'progress_summary' => $progressSummary,
                'work_completed' => $workCompleted,
                'achievements' => $achievements,
                'challenges' => $challenges,
                'document_path' => $docPath,
            ];

            if (empty($cols) || in_array('report_period', $cols)) {
                $insertData['report_period'] = $reportPeriod;
            }
            if (in_array('reporting_period', $cols)) {
                $insertData['reporting_period'] = $reportPeriod;
            }
            if (empty($cols) || in_array('budget_utilized', $cols)) {
                $insertData['budget_utilized'] = $budgetUtilized;
            }
            if (in_array('budget_utilization', $cols)) {
                $insertData['budget_utilization'] = $budgetUtilized;
            }
            if (empty($cols) || in_array('next_period_plan', $cols)) {
                $insertData['next_period_plan'] = $nextPeriodPlan;
            }
            if (in_array('next_steps', $cols)) {
                $insertData['next_steps'] = $nextPeriodPlan;
            }
            if (empty($cols) || in_array('review_status', $cols)) {
                $insertData['review_status'] = 'Submitted';
            }
            if (empty($cols) || in_array('created_at', $cols)) {
                $insertData['created_at'] = $now;
            }
            if (empty($cols) || in_array('updated_at', $cols)) {
                $insertData['updated_at'] = $now;
            }
            if (in_array('submitted_at', $cols)) {
                $insertData['submitted_at'] = $now;
            }

            $colNames = array_keys($insertData);
            $placeholders = array_fill(0, count($colNames), '?');
            $sql = "INSERT INTO progress_reports (" . implode(', ', $colNames) . ") VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($insertData));
            $reportId = (int)$db->lastInsertId();

            // Update project updated_at
            $db->prepare("UPDATE projects SET updated_at = ? WHERE id = ?")->execute([$now, $projectId]);

            log_audit($userId, 'PROGRESS_REPORT_SUBMITTED', 'projects', $projectId, "Progress report for period '{$reportPeriod}' submitted for project {$project['project_number']}.");

            $db->commit();
            flash('success', "Periodic Progress Report for '{$reportPeriod}' filed successfully.");
            header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to submit progress report: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Submit Progress Report</h3>
        <p class="text-muted small mb-0">Project: <strong><?= e($project['project_number']) ?></strong> - <?= e($project['proposal_title']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/scientist/view-progress.php?project_id={$projectId}") ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-list-check me-1"></i> View Past Reports
        </a>
        <a href="<?= url("/scientist/projects.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Projects
        </a>
    </div>
</div>

<!-- Project Financial Scope Information Card -->
<div class="card shadow-sm border-0 mb-4 bg-light">
    <div class="card-body p-3">
        <div class="row g-3 align-items-center">
            <div class="col-md-4">
                <span class="text-muted small d-block">Total Approved Project Grant:</span>
                <h4 class="fw-bold text-success mb-0" id="approvedBudgetVal" data-value="<?= $approvedBudget ?>">
                    <?= format_currency($approvedBudget) ?>
                </h4>
            </div>
            <div class="col-md-4">
                <span class="text-muted small d-block">Utilized in Past Reports:</span>
                <h5 class="fw-bold text-dark mb-0">
                    <?= format_currency($priorUtilized) ?>
                </h5>
            </div>
            <div class="col-md-4">
                <span class="text-muted small d-block">Available Grant Balance:</span>
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

<form method="POST" action="<?= url("/scientist/submit-progress.php?project_id=" . ($projectId) . "") ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-calendar3 text-primary"></i> 1. Reporting Period & Financial Utilization
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Reporting Period (Quarter / Milestone) *</label>
                    <input type="text" name="report_period" class="form-control" placeholder="e.g. Q1 (Apr - Jun 2026) or Annual Report Year-1" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Grant Budget Utilized in This Period (₹ INR) *</label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" id="budgetUtilizedInput" name="budget_utilized" class="form-control fw-bold" step="0.01" min="0" placeholder="e.g. 125000" required>
                    </div>
                    <div id="budgetWarningBox" class="alert alert-warning py-2 px-3 small mt-2 mb-0 d-none">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>Notice:</strong> The entered period expenditure (<span id="warnEnteredAmt">₹0.00</span>) exceeds the total project approved grant (<?= format_currency($approvedBudget) ?>). Please ensure you have not accidentally added extra digits.
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
                    <textarea name="progress_summary" class="form-control" rows="3" placeholder="High-level overview of accomplishments against agreed targets..." required></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Detailed Work Completed Against Objectives *</label>
                    <textarea name="work_completed" class="form-control" rows="4" placeholder="Specific assays, field sampling, herd experiments, or technical modules executed..." required></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Key Achievements / Publications / Patents</label>
                    <textarea name="achievements" class="form-control" rows="3" placeholder="Manuscripts under review, conference posters, preliminary patents, or data registries..."></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Technical Challenges / Deviations</label>
                    <textarea name="challenges" class="form-control" rows="3" placeholder="Any supply chain issues, seasonal animal sampling delays, or protocol adjustments..."></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Plan of Work for Next Milestone / Reporting Period</label>
                    <textarea name="next_period_plan" class="form-control" rows="3" placeholder="Next phase of experiments, validations, or field trials planned..."></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body d-flex justify-content-between align-items-center">
            <a href="<?= url("/scientist/projects.php") ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary fw-semibold px-4" style="background-color: #1a365d; border-color: #1a365d;">
                <i class="bi bi-send-check me-1"></i> Submit Progress Report
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
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
