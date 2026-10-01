<?php
/**
 * Research Proposal and Project Management System
 * Scientist - Submit Final Project Completion Report
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_SCIENTIST);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$userId = current_user_id();
$db = get_db();

$stmt = $db->prepare("SELECT pr.*, p.title as proposal_title, p.id as proposal_id,
                             p.project_type as prop_project_type, p.funding_agency as prop_funding_agency,
                             p.funding_agency_type as prop_funding_agency_type, p.yearly_budget as prop_yearly_budget,
                             d.department_name
                      FROM projects pr
                      JOIN proposals p ON pr.proposal_id = p.id
                      JOIN departments d ON COALESCE(pr.department_id, p.department_id) = d.id
                      WHERE pr.id = ? AND pr.scientist_id = ?");
$stmt->execute([$projectId, $userId]);
$project = $stmt->fetch();

if (!$project) {
    flash('danger', 'Project not found or access unauthorized.');
    header("Location: " . url("/scientist/projects.php"));
    exit;
}

$copis = get_project_copis($projectId);
if (empty($copis)) {
    $copis = get_proposal_copis((int)($project['proposal_id'] ?? 0), $projectId, (string)($project['project_number'] ?? ''));
}

$pageTitle = 'Submit Final Completion Report - ' . $project['project_number'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $completionDate = $_POST['completion_date'] ?? date('Y-m-d');
    $finalSummary = sanitize($_POST['final_summary'] ?? '');
    $objectivesAchieved = sanitize($_POST['objectives_achieved'] ?? '');
    $researchOutcomes = sanitize($_POST['research_outcomes'] ?? '');
    $deliverables = sanitize($_POST['deliverables'] ?? '');
    $finalBudgetUtilized = (float)($_POST['final_budget_utilized'] ?? 0);
    $lessonsLearned = sanitize($_POST['lessons_learned'] ?? '');

    if (empty($finalSummary) || empty($objectivesAchieved) || empty($researchOutcomes)) {
        $error = "Final summary, objectives achieved, and research outcomes are mandatory.";
    } else {
        try {
            $db->beginTransaction();

            $docPath = null;

            // Save completion report
            $now = date('Y-m-d H:i:s');
            
            $cols = [];
            try {
                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'mysql') {
                    $cols = $db->query("SHOW COLUMNS FROM completion_reports")->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $cols = $db->query("PRAGMA table_info(completion_reports)")->fetchAll(PDO::FETCH_COLUMN, 1);
                }
            } catch (Exception $eCols) {}

            $insertData = [
                'project_id' => $projectId,
                'submitted_by' => $userId,
                'final_summary' => $finalSummary,
                'objectives_achieved' => $objectivesAchieved,
                'research_outcomes' => $researchOutcomes,
                'lessons_learned' => $lessonsLearned,
                'document_path' => $docPath,
            ];

            if (empty($cols) || in_array('completion_date', $cols)) {
                $insertData['completion_date'] = $completionDate;
            }
            if (empty($cols) || in_array('deliverables', $cols)) {
                $insertData['deliverables'] = $deliverables;
            }
            if (in_array('publications_deliverables', $cols)) {
                $insertData['publications_deliverables'] = $deliverables;
            }
            if (empty($cols) || in_array('final_budget_utilized', $cols)) {
                $insertData['final_budget_utilized'] = $finalBudgetUtilized;
            }
            if (in_array('final_budget_utilization', $cols)) {
                $insertData['final_budget_utilization'] = $finalBudgetUtilized;
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
            $sql = "INSERT INTO completion_reports (" . implode(', ', $colNames) . ") VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($insertData));

            // Update project status to Completed
            $db->prepare("UPDATE projects SET project_status = 'Completed', updated_at = ? WHERE id = ?")
               ->execute([$now, $projectId]);

            // Update proposal status to Completed
            $prevPropStatus = $db->query("SELECT current_status FROM proposals WHERE id = {$project['proposal_id']}")->fetchColumn();
            $db->prepare("UPDATE proposals SET current_status = ?, updated_at = ? WHERE id = ?")
               ->execute([STATUS_COMPLETED, $now, $project['proposal_id']]);

            // Status history
            record_status_history($project['proposal_id'], $prevPropStatus, STATUS_COMPLETED, $userId, 'Scientist', 'Project final completion dossier submitted. Status updated to Completed.');

            log_audit($userId, 'COMPLETION_REPORT_SUBMITTED', 'projects', $projectId, "Final completion report filed for project {$project['project_number']}. Project marked Completed.");

            $db->commit();
            flash('success', "Final Project Completion Report submitted successfully. Project {$project['project_number']} is now officially Completed.");
            header("Location: " . url("/scientist/projects.php"));
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to submit completion report: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Submit Project Completion Dossier</h3>
        <p class="text-muted small mb-0">Project: <strong><?= e($project['project_number']) ?></strong> - <?= e($project['proposal_title']) ?></p>
    </div>
    <a href="<?= url("/scientist/projects.php") ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Cancel
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<form method="POST" action="<?= url("/scientist/submit-completion.php?project_id=" . ($projectId) . "") ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <?php $agencyDetails = get_funding_agency_details($project); ?>
    <div class="detail-section-card">
        <div class="detail-section-header d-flex justify-content-between align-items-center">
            <div><i class="bi bi-flag-fill text-success me-1"></i> 1. Project Closeout Administrative & Financial Overview</div>
            <span class="badge bg-success-subtle text-success border border-success-subtle">
                <i class="bi bi-shield-check me-1"></i> Verified Project Record
            </span>
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Project Category & Funding Agency</label>
                    <div class="p-2 px-3 bg-light rounded border d-flex flex-column justify-content-center" style="min-height: 58px;">
                        <div><?= $agencyDetails['badge_html'] ?></div>
                        <?php if ($agencyDetails['is_external'] && !empty($agencyDetails['agency_name'])): ?>
                            <div class="text-muted extra-small mt-1">Agency: <strong><?= e($agencyDetails['agency_name']) ?></strong> (<?= e($agencyDetails['agency_type']) ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Official Project Completion Date *</label>
                    <input type="date" name="completion_date" class="form-control fw-bold text-dark font-monospace" value="<?= date('Y-m-d') ?>" required style="height: 58px;">
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Total Sanctioned Grant Budget</label>
                    <input type="text" class="form-control bg-light font-monospace fw-bold text-success fs-5" value="<?= format_currency((float)$project['approved_budget']) ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Final Grant Budget Utilized (₹ INR) *</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white fw-bold">₹</span>
                        <input type="number" name="final_budget_utilized" class="form-control font-monospace fw-bold fs-5 text-primary" step="0.01" min="0" value="<?= (float)$project['approved_budget'] ?>" required>
                    </div>
                </div>

                <!-- Year-wise Budget Allocation Breakdown -->
                <div class="col-12 mt-2 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold text-dark m-0">
                            <i class="bi bi-calendar3-range text-success me-1"></i> Year-wise Sanctioned Budget Formulation
                        </label>
                        <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace">
                            Sanctioned Total: <?= format_currency((float)$project['approved_budget']) ?>
                        </span>
                    </div>
                    <?= render_yearly_budget_html($project['yearly_budget'] ?: ($project['prop_yearly_budget'] ?? null), (float)$project['approved_budget']) ?>
                </div>

                <!-- Co-Principal Investigators of this project -->
                <div class="col-12 mt-2 pt-3 border-top">
                    <label class="form-label small fw-bold text-dark d-flex align-items-center justify-content-between mb-2">
                        <span><i class="bi bi-people-fill text-primary me-1"></i> Co-Principal Investigators (Co-PIs) on this Project</span>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">
                            <?= count($copis) ?> Co-PI<?= count($copis) !== 1 ? 's' : '' ?> Registered
                        </span>
                    </label>
                    <div>
                        <?php if (!empty($copis)): ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($copis as $cp): ?>
                                    <div class="badge bg-white text-dark border p-2 text-start d-inline-flex align-items-center gap-2 shadow-xs">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width: 34px; height: 34px; font-size: 0.85rem; flex-shrink: 0;">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark" style="font-size: 0.88rem;">
                                                <i class="bi bi-patch-check-fill text-success me-1"></i><?= e($cp['co_pi_name'] ?? ($cp['name'] ?? 'Co-PI')) ?>
                                            </div>
                                            <div class="extra-small text-muted">
                                                <?= e($cp['designation'] ?: 'Co-PI') ?><?= !empty($cp['institution']) ? ' &bull; ' . e($cp['institution']) : '' ?>
                                            </div>
                                            <?php if (!empty($cp['email'])): ?>
                                                <div class="extra-small text-secondary"><i class="bi bi-envelope me-1"></i><?= e($cp['email']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-3 bg-light rounded border text-muted small">
                                <i class="bi bi-info-circle me-1 text-primary"></i> No Co-Principal Investigators were registered in the original approved project charter.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-trophy text-primary"></i> 2. Final Results, Scientific Deliverables & Publications
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Executive Summary of Project Findings *</label>
                    <textarea name="final_summary" class="form-control" rows="4" placeholder="Comprehensive summary of final scientific results, breakthroughs, and findings..." required></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Objectives Achieved (Against Approved Proposal) *</label>
                    <textarea name="objectives_achieved" class="form-control" rows="4" placeholder="Point-by-point detailing of how each approved objective was accomplished..." required></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Research Outcomes & Scientific Outputs *</label>
                    <textarea name="research_outcomes" class="form-control" rows="4" placeholder="Published research papers, patent applications, technologies commercialized, student theses supervised..." required></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Deliverables Transferred / Prototypes Developed</label>
                    <textarea name="deliverables" class="form-control" rows="4" placeholder="Standard operating procedures (SOPs), software algorithms, dairy prototypes delivered to stakeholders..."></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Lessons Learned & Future Research Recommendations</label>
                    <textarea name="lessons_learned" class="form-control" rows="3" placeholder="Key insights, operational recommendations, and areas identified for follow-up studies..."></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body d-flex justify-content-between align-items-center">
            <a href="<?= url("/scientist/projects.php") ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-success fw-semibold px-4" data-confirm="Are you sure you want to mark this research project as officially COMPLETED?">
                <i class="bi bi-check2-circle me-1"></i> Submit Completion Report
            </button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
