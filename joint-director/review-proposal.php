<?php
/**
 * Research Proposal and Project Management System
 * Joint Director - Initial Review & Screening
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role(ROLE_JOINT_DIRECTOR);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    flash('danger', 'Invalid proposal ID.');
    header("Location: " . url("/joint-director/proposals.php"));
    exit;
}

$db = get_db();
$userId = current_user_id();

$stmt = $db->prepare("SELECT p.*, u.name as scientist_name, u.email as scientist_email, u.designation as scientist_designation,
                             d.department_name, d.department_code
                      FROM proposals p
                      JOIN users u ON p.scientist_id = u.id
                      JOIN departments d ON p.department_id = d.id
                      WHERE p.id = ?");
$stmt->execute([$id]);
$proposal = $stmt->fetch();

if (!$proposal) {
    flash('danger', 'Proposal not found.');
    header("Location: " . url("/joint-director/proposals.php"));
    exit;
}

if (!can_jd_initial_review($proposal)) {
    flash('warning', "Proposal {$proposal['proposal_number']} is in status '{$proposal['current_status']}' and not currently awaiting Joint Director initial review.");
    header("Location: " . url("/scientist/proposal-details.php?id={$id}"));
    exit;
}

$pageTitle = 'Joint Director Review - ' . $proposal['proposal_number'];

// Fetch comments and history
$comments = $db->prepare("SELECT c.*, u.name as user_name, r.role_name
                         FROM proposal_comments c
                         JOIN users u ON c.user_id = u.id
                         JOIN roles r ON u.role_id = r.id
                         WHERE c.proposal_id = ?
                         ORDER BY c.created_at ASC");
$comments->execute([$id]);
$comments = $comments->fetchAll();

$copis = get_proposal_copis($id, (int)($proposal['linked_project_id'] ?? 0), (string)($proposal['project_number'] ?? ''));
$hodInfo = get_hod_approver_info($id, (int)$proposal['department_id']);

$docs = $db->prepare("SELECT * FROM proposal_documents WHERE proposal_id = ?");
$docs->execute([$id]);
$docs = $docs->fetchAll();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $decision = $_POST['decision'] ?? ''; // 'return' or 'approve_irc'
    $remarks = trim(sanitize($_POST['remarks'] ?? ''));

    if ($decision === 'return' && empty($remarks)) {
        $error = "Please provide specific directives and comments when returning a proposal for revision.";
    } elseif ($decision !== 'return' && $decision !== 'approve_irc') {
        $error = "Please select a valid review decision.";
    } else {
        try {
            $db->beginTransaction();

            $prevStatus = $proposal['current_status'];

            $isSubmitterHod = ($hodInfo['name'] === $proposal['scientist_name']);
            if ($decision === 'return') {
                $newStatus = STATUS_RETURNED_JD;
                $actionType = 'PROPOSAL_RETURNED_BY_JD';
                $histComment = "Returned by Joint Director (Research) for revisions. Directives: " . $remarks;
                $flashMsg = "Proposal {$proposal['proposal_number']} returned to " . ($isSubmitterHod ? "Head of Department" : "Scientist") . " for revision.";
            } else {
                $newStatus = STATUS_APPROVED_IRC;
                $actionType = 'PROPOSAL_APPROVED_FOR_IRC';
                $histComment = "Screened and Approved for Institute Research Council (IRC) presentation by Joint Director." . (!empty($remarks) ? " Remarks: {$remarks}" : '');
                $flashMsg = "Proposal {$proposal['proposal_number']} approved for Institute Research Council (IRC) meeting.";
            }

            $now = date('Y-m-d H:i:s');
            // Update proposal status
            $stmt = $db->prepare("UPDATE proposals SET current_status = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$newStatus, $now, $id]);

            // If this is an ongoing progress report, update the progress_reports record accordingly
            if (($proposal['proposal_category'] ?? '') === 'ongoing') {
                $linkedPid = (int)($proposal['linked_project_id'] ?? 0);
                if ($linkedPid <= 0 && !empty($proposal['project_number'])) {
                    $findP = $db->prepare("SELECT id FROM projects WHERE project_number = ? LIMIT 1");
                    $findP->execute([$proposal['project_number']]);
                    $fp = $findP->fetch();
                    if ($fp) $linkedPid = (int)$fp['id'];
                }
                if ($linkedPid > 0) {
                    $repReviewStatus = ($decision === 'return') ? 'Returned' : 'Approved for IRC';
                    try {
                        $stmtR = $db->prepare("UPDATE progress_reports 
                            SET review_status = ?, 
                                reviewer_comments = ?, 
                                reviewed_by = ?, 
                                reviewer_role = 'Joint Director', 
                                reviewed_at = ?, 
                                updated_at = ? 
                            WHERE project_id = ? AND (review_status IS NULL OR review_status = 'Submitted' OR review_status = 'Pending Review')");
                        $stmtR->execute([
                            $repReviewStatus, 
                            $remarks ?: ($decision === 'return' ? 'Returned by Joint Director' : 'Approved for IRC Meeting by Joint Director'), 
                            $userId, 
                            $now, 
                            $now, 
                            $linkedPid
                        ]);
                    } catch (Throwable $eR) {}
                }
            }

            // Save comment
            if (!empty($remarks)) {
                $stmtC = $db->prepare("INSERT INTO proposal_comments (proposal_id, user_id, comment_stage, comment, created_at)
                                       VALUES (?, ?, 'Joint Director Review', ?, ?)");
                $stmtC->execute([$id, $userId, $remarks, $now]);
            }

            // Record status history
            record_status_history($id, $prevStatus, $newStatus, $userId, 'Joint Director', $histComment);

            // Audit log
            log_audit($userId, $actionType, 'proposals', $id, "Joint Director recorded '{$decision}' on proposal {$proposal['proposal_number']}. Remarks: {$remarks}");

            $db->commit();
            flash('success', $flashMsg);
            header("Location: " . url("/joint-director/dashboard.php"));
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to record Joint Director review: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <?= render_proposal_category_badge($proposal['proposal_category'] ?? 'new') ?>
            <h3 class="fw-bold mb-0 text-dark">
                <?php if (($proposal['proposal_category'] ?? '') === 'ongoing'): ?>
                    Joint Director Review: Progress Report
                <?php elseif (($proposal['proposal_category'] ?? '') === 'completed'): ?>
                    Joint Director Review: Completion Report
                <?php else: ?>
                    Joint Director Initial Proposal Screening
                <?php endif; ?>
            </h3>
        </div>
        <p class="text-muted small mb-0">
            Evaluating <?= e($proposal['proposal_number']) ?> &mdash; <?= e($proposal['title']) ?>
            <?php if (!empty($proposal['progress_report_period'])): ?>
                | Period: <span class="badge bg-info text-dark font-monospace"><?= e($proposal['progress_report_period']) ?></span>
            <?php endif; ?>
            <?php if (!empty($proposal['project_number'])): ?>
                | Code: <span class="badge bg-dark-subtle text-dark font-monospace"><?= e($proposal['project_number']) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/export-doc.php?id=" . ($id) . "") ?>" class="btn btn-outline-secondary btn-sm" title="Download Office Open XML Document (.docx)">
            <i class="bi bi-file-earmark-word me-1"></i> Export Word (.docx)
        </a>
        <a href="<?= url("/joint-director/dashboard.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-octagon-fill me-2"></i> <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- HOD Endorsement Banner -->
<div class="alert alert-info border-info shadow-sm d-flex flex-wrap align-items-center justify-content-between p-3 mb-4 gap-2">
    <div class="d-flex align-items-center gap-3">
        <div class="p-2 rounded-circle bg-primary text-white">
            <i class="bi bi-person-check-fill fs-5"></i>
        </div>
        <div>
            <div class="fw-bold text-dark fs-6">
                Approved & Endorsed by Head of Department: <span class="text-primary"><?= e($hodInfo['name']) ?></span> (<?= e($hodInfo['designation']) ?>)
            </div>
            <div class="small text-muted mt-1">
                Division: <strong><?= e($proposal['department_name']) ?> (<?= e($proposal['department_code']) ?>)</strong>
                <?php if (!empty($hodInfo['approved_at'])): ?>
                    &bull; Forwarded on: <?= format_date($hodInfo['approved_at'], 'd M Y, h:i A') ?>
                <?php endif; ?>
                <?php if (!empty($hodInfo['comments'])): ?>
                    <div class="mt-1 text-dark fst-italic">&ldquo;<?= e($hodInfo['comments']) ?>&rdquo;</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <span class="badge bg-success text-white py-2 px-3 font-monospace"><i class="bi bi-patch-check-fill me-1"></i>HOD Endorsed</span>
</div>

<!-- JD Decision Action Box at Top -->
<div class="card shadow-sm border-0 mb-4" style="border-top: 4px solid #1a365d !important;">
    <div class="card-header bg-white py-3">
        <h5 class="fw-bold text-dark m-0">
            <i class="bi bi-shield-check text-primary me-2"></i>
            <?php if (($proposal['proposal_category'] ?? '') === 'ongoing'): ?>
                Stage 1: Joint Director Review &mdash; Progress Report Decision
            <?php elseif (($proposal['proposal_category'] ?? '') === 'completed'): ?>
                Stage 1: Joint Director Review &mdash; Completion Report Decision
            <?php else: ?>
                Stage 1: Joint Director Review &mdash; Initial Screening Decision
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="<?= url("/joint-director/review-proposal.php?id=" . ($id) . "") ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="mb-3">
                <label for="remarks" class="form-label small fw-semibold text-secondary">
                    Directorate Observations / Revision Directives:
                </label>
                <textarea id="remarks" name="remarks" class="form-control" rows="4" placeholder="Mandatory if returning for revision. Note any institutional modifications, budget caps, or inter-divisional collaborator requirements..."></textarea>
            </div>

            <div class="d-flex flex-wrap gap-3 justify-content-end align-items-center pt-3 border-top">
                <button type="submit" name="decision" value="return" class="btn btn-outline-danger fw-semibold" data-confirm="Are you sure you want to RETURN this submission for revision?">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Return for Revision
                </button>
                <button type="submit" name="decision" value="approve_irc" class="btn btn-primary fw-semibold px-4" style="background-color: #1a365d; border-color: #1a365d;" data-confirm="Approve this submission for presentation at the next Institute Research Council (IRC) Meeting?">
                    <i class="bi bi-award me-1"></i> Approve for IRC Meeting
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Proposal Details Summary -->
<div class="row g-4">
    <div class="col-lg-8">
        <?php if (($proposal['proposal_category'] ?? '') === 'completed'): ?>
            <!-- Completed Proposal Dossier -->
            <div class="detail-section-card">
                <div class="detail-section-header d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-file-earmark-text text-success"></i> Executive Final Report</div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                        Max 250 Words
                    </span>
                </div>
                <div class="detail-section-body">
                    <h4 class="fw-bold text-dark mb-3"><?= e($proposal['title']) ?></h4>
                    <div class="p-3 bg-light rounded border mb-2 text-dark font-monospace" style="white-space: pre-wrap; line-height: 1.6; font-size: 0.92rem;">
                        <?= e($proposal['final_report'] ?: 'No final report summary entered.') ?>
                    </div>
                    <?php 
                        $reportWords = !empty($proposal['final_report']) ? count(preg_split('/\s+/', trim(strip_tags($proposal['final_report'])))) : 0;
                    ?>
                    <div class="text-muted extra-small text-end">
                        <i class="bi bi-fonts me-1"></i> Word Count: <?= $reportWords ?> / 250 words
                    </div>
                </div>
            </div>

            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-trophy text-warning"></i> Significant Achievements
                </div>
                <div class="detail-section-body">
                    <div class="p-3 bg-light rounded border text-dark" style="white-space: pre-wrap; line-height: 1.6;">
                        <?= e($proposal['significant_achievements'] ?: 'None recorded.') ?>
                    </div>
                </div>
            </div>

            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-clipboard-check text-primary"></i> Action Taken Report (ATR) on Previous IRC Recommendations
                </div>
                <div class="detail-section-body">
                    <div class="p-3 bg-light rounded border text-dark" style="white-space: pre-wrap; line-height: 1.6;">
                        <?= e($proposal['previous_irc_atr'] ?: 'N/A (No prior recommendations recorded).') ?>
                    </div>
                </div>
            </div>

            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-box-seam text-info"></i> Project Deliverables & Output / Outcome
                </div>
                <div class="detail-section-body">
                    <div class="p-3 bg-light rounded border text-dark" style="white-space: pre-wrap; line-height: 1.6;">
                        <?= e($proposal['output_outcome'] ?: 'None specified.') ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($proposal['other_details'])): ?>
                <div class="detail-section-card">
                    <div class="detail-section-header">
                        <i class="bi bi-info-circle text-secondary"></i> Other Details & Remarks
                    </div>
                    <div class="detail-section-body">
                        <div class="p-3 bg-light rounded border text-secondary" style="white-space: pre-wrap; line-height: 1.6;">
                            <?= e($proposal['other_details']) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif (($proposal['proposal_category'] ?? '') === 'ongoing'): ?>
            <!-- Ongoing Progress Report Dossier -->
            <!-- 1. Executive Progress Report (250 Words Limit) -->
            <div class="detail-section-card">
                <div class="detail-section-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-file-earmark-text text-primary"></i> Biannual Progress Report
                        <?php if (!empty($proposal['progress_report_period'])): ?>
                            <span class="badge bg-white text-primary ms-2 fw-semibold"><?= e($proposal['progress_report_period']) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                        Max 250 Words
                    </span>
                </div>
                <div class="detail-section-body">
                    <h4 class="fw-bold text-dark mb-3"><?= e($proposal['title']) ?></h4>
                    <div class="p-3 bg-light rounded border mb-2 text-dark font-monospace" style="white-space: pre-wrap; line-height: 1.6; font-size: 0.92rem;">
                        <?= e($proposal['progress_report'] ?: 'No progress report summary entered.') ?>
                    </div>
                    <?php 
                        $pWords = !empty($proposal['progress_report']) ? count(preg_split('/\s+/', trim(strip_tags($proposal['progress_report'])))) : 0;
                    ?>
                    <div class="text-muted extra-small text-end">
                        <i class="bi bi-fonts me-1"></i> Word Count: <?= $pWords ?> / 250 words
                    </div>
                </div>
            </div>

            <!-- 2. Significant Achievements During Reporting Period -->
            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-trophy text-warning"></i> Significant Achievements During This Reporting Period
                </div>
                <div class="detail-section-body">
                    <div class="p-3 bg-light rounded border text-dark" style="white-space: pre-wrap; line-height: 1.6;">
                        <?= e($proposal['significant_achievements'] ?: 'None recorded.') ?>
                    </div>
                </div>
            </div>

            <!-- 3. Action Taken Report (ATR) on Previous IRC Recommendations -->
            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-clipboard-check text-primary"></i> Action Taken Report (ATR) on Previous IRC Recommendations
                </div>
                <div class="detail-section-body">
                    <div class="p-3 bg-light rounded border text-dark" style="white-space: pre-wrap; line-height: 1.6;">
                        <?= e($proposal['previous_irc_atr'] ?: 'N/A (No prior recommendations recorded).') ?>
                    </div>
                </div>
            </div>

            <!-- 4. Interim Output / Outcome -->
            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-box-seam text-info"></i> Project Output / Outcome (Publications, Technologies, Data)
                </div>
                <div class="detail-section-body">
                    <div class="p-3 bg-light rounded border text-dark" style="white-space: pre-wrap; line-height: 1.6;">
                        <?= e($proposal['output_outcome'] ?: 'None specified.') ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($proposal['other_details'])): ?>
                <div class="detail-section-card">
                    <div class="detail-section-header">
                        <i class="bi bi-info-circle text-secondary"></i> Other Details & Remarks
                    </div>
                    <div class="detail-section-body">
                        <div class="p-3 bg-light rounded border text-secondary" style="white-space: pre-wrap; line-height: 1.6;">
                            <?= e($proposal['other_details']) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-journal-text text-primary"></i> Proposal Scientific Summary
                </div>
                <div class="detail-section-body">
                    <h4 class="fw-bold text-dark mb-3"><?= e($proposal['title']) ?></h4>

                    <div class="mb-3">
                        <strong class="small text-muted text-uppercase d-block mb-1">Specific Objectives:</strong>
                        <div class="bg-light p-3 rounded rich-text-preview small">
                            <?= render_rich_text($proposal['objectives']) ?>
                        </div>
                    </div>

                    <?php if (!empty($proposal['technical_program'])): ?>
                        <div class="mb-3">
                            <strong class="small text-muted text-uppercase d-block mb-1">Technical Program Proposed (objective wise, also indicate the role of Co Pis):</strong>
                            <div class="bg-light p-3 rounded rich-text-preview small">
                                <?= render_rich_text($proposal['technical_program']) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($proposal['expected_outcomes'])): ?>
                        <div class="mb-3">
                            <strong class="small text-muted text-uppercase d-block mb-1">Expected Deliverables:</strong>
                            <p class="text-secondary small"><?= nl2br(e($proposal['expected_outcomes'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Background & Gap Analysis -->
            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-lightbulb text-primary"></i> Background & Gap Analysis
                </div>
                <div class="detail-section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong class="small text-muted text-uppercase d-block">Research Problem:</strong>
                            <p class="small text-dark"><?= nl2br(e($proposal['research_problem'] ?: 'Not provided')) ?></p>
                        </div>
                        <div class="col-md-6">
                            <strong class="small text-muted text-uppercase d-block">Baseline Information:</strong>
                            <p class="small text-dark"><?= nl2br(e($proposal['baseline_info'] ?: 'Not provided')) ?></p>
                        </div>
                        <div class="col-md-6">
                            <strong class="small text-muted text-uppercase d-block">Novelty & Gap Analysis:</strong>
                            <p class="small text-dark"><?= nl2br(e($proposal['novelty_gap_analysis'] ?: 'Not provided')) ?></p>
                        </div>
                        <div class="col-md-6">
                            <strong class="small text-muted text-uppercase d-block">Target Beneficiaries & End Users:</strong>
                            <p class="small text-dark"><?= nl2br(e($proposal['justification_end_users'] ?: 'Not provided')) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Previous Reviewer Comments -->
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-chat-dots text-primary"></i> Previous Review Comments (HOD Endorsement)
            </div>
            <div class="detail-section-body">
                <?php if (empty($comments)): ?>
                    <p class="text-muted small mb-0">No prior comments recorded.</p>
                <?php else: ?>
                    <?php foreach ($comments as $c): ?>
                        <div class="border rounded p-3 mb-2 bg-light-subtle">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong><?= e($c['user_name']) ?> <span class="text-muted small">(<?= e($c['role_name']) ?>)</span></strong>
                                <span class="badge bg-secondary-subtle text-dark border"><?= e($c['comment_stage']) ?></span>
                            </div>
                            <div class="small text-secondary mb-1"><?= nl2br(e($c['comment'])) ?></div>
                            <small class="text-muted"><?= format_datetime($c['created_at']) ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-bank text-primary"></i> Administrative & Strategic Meta
            </div>
            <div class="detail-section-body">
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Proposal Category:</span>
                        <?php if (($proposal['proposal_category'] ?? '') === 'completed'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace">
                                <i class="bi bi-journal-check me-1"></i> Completed Proposal
                            </span>
                        <?php else: ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">
                                <i class="bi bi-file-earmark-plus me-1"></i> New Proposal
                            </span>
                        <?php endif; ?>
                    </li>
                    <?php if (!empty($proposal['project_number'])): ?>
                        <li class="list-group-item px-0 py-2">
                            <span class="text-muted d-block">Project Code / Number:</span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace fs-6">
                                <?= e($proposal['project_number']) ?>
                            </span>
                        </li>
                    <?php endif; ?>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Lead Scientist:</span>
                        <strong class="text-dark"><?= e($proposal['scientist_name']) ?></strong> (<?= e($proposal['scientist_designation']) ?>)
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Division:</span>
                        <strong><?= e($proposal['department_name']) ?></strong>
                    </li>
                    <?php if (($proposal['proposal_category'] ?? '') === 'completed'): ?>
                        <li class="list-group-item px-0 py-2">
                            <span class="text-muted d-block">Budget Allocated:</span>
                            <h5 class="fw-bold text-primary m-0"><?= format_currency((float)($proposal['budget_allocated'] ?: $proposal['proposed_budget'])) ?></h5>
                        </li>
                        <li class="list-group-item px-0 py-2">
                            <span class="text-muted d-block">Budget Utilized:</span>
                            <h4 class="fw-bold text-success m-0"><?= format_currency((float)$proposal['budget_utilized']) ?></h4>
                            <?php 
                                $alloc = (float)($proposal['budget_allocated'] ?: $proposal['proposed_budget']);
                                $util = (float)$proposal['budget_utilized'];
                                $pct = ($alloc > 0) ? round(($util / $alloc) * 100, 1) : 0;
                            ?>
                            <span class="badge <?= $pct > 100 ? 'bg-danger text-white' : 'bg-success text-white' ?> mt-1">
                                Utilization: <?= $pct ?>%
                            </span>
                        </li>
                    <?php else: ?>
                        <li class="list-group-item px-0 py-2">
                            <span class="text-muted d-block">Proposed Budget:</span>
                            <h4 class="fw-bold text-primary m-0"><?= format_currency((float)$proposal['proposed_budget']) ?></h4>
                        </li>
                    <?php endif; ?>
                    <?php 
                    $agencyDetails = get_funding_agency_details($proposal);
                    $revBudgetTotal = (float)($proposal['approved_budget'] ?: ($proposal['budget_allocated'] ?: $proposal['proposed_budget']));
                    ?>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block mb-1">Year-wise Budget Formulation:</span>
                        <?= render_yearly_budget_html($proposal['yearly_budget'] ?? null, $revBudgetTotal) ?>
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block mb-1">Project Category & Funding:</span>
                        <?= $agencyDetails['badge_html'] ?>
                        <?php if ($agencyDetails['is_external'] && !empty($agencyDetails['agency_name'])): ?>
                            <div class="mt-1 small fw-bold text-dark"><i class="bi bi-bank me-1"></i>Agency: <?= e($agencyDetails['agency_name']) ?> (<?= e($agencyDetails['agency_type']) ?>)</div>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Institute Priority Area:</span>
                        <div class="fw-semibold text-dark mt-1"><?= e(get_priority_area_title($proposal['institute_priority_area'] ?? '')) ?></div>
                    </li>
                    <?php if (!empty($proposal['national_priority_area'])): ?>
                        <li class="list-group-item px-0 py-2">
                            <span class="text-muted d-block">National Priority Area:</span>
                            <span class="text-dark fw-semibold"><?= e($proposal['national_priority_area']) ?></span>
                        </li>
                    <?php endif; ?>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Proposed Timeline:</span>
                        <strong><?= format_date($proposal['proposed_start_date']) ?></strong> to <strong><?= format_date($proposal['proposed_end_date']) ?></strong>
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Project Duration:</span>
                        <strong class="text-primary"><i class="bi bi-hourglass-split me-1"></i><?= format_duration($proposal['proposed_start_date'], $proposal['proposed_end_date']) ?></strong>
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">TRL Readiness:</span>
                        <span class="badge bg-info-subtle text-dark border">TRL-<?= e($proposal['trl_level']) ?></span>
                    </li>
                </ul>

                <?php if (!empty($copis)): ?>
                    <h6 class="fw-bold small text-uppercase text-secondary mt-3 mb-2">Co-Investigators:</h6>
                    <?php foreach ($copis as $cp): ?>
                        <div class="p-2 border rounded mb-1 bg-light small">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong><?= e($cp['co_pi_name']) ?></strong>
                                <?php if (!empty($cp['designation'])): ?>
                                    <span class="badge bg-white text-secondary border"><?= e($cp['designation']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted"><?= e($cp['institution']) ?></div>
                            <?php if (!empty($cp['email'])): ?>
                                <div class="text-secondary extra-small"><i class="bi bi-envelope me-1"></i><?= e($cp['email']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($docs)): ?>
                    <h6 class="fw-bold small text-uppercase text-secondary mt-3 mb-2">Attached Documents:</h6>
                    <?php foreach ($docs as $d): ?>
                        <a href="<?= e($d['file_path']) ?>" download class="btn btn-sm btn-outline-secondary w-100 text-start text-truncate mb-1">
                            <i class="bi bi-file-earmark-pdf me-1 text-danger"></i> <?= e($d['file_name']) ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
