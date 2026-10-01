<?php
/**
 * Research Proposal and Project Management System
 * HOD - Review Proposal & Decision Handler
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role(ROLE_HOD);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    flash('danger', 'Invalid proposal ID.');
    header("Location: " . url("/hod/proposals.php"));
    exit;
}

$db = get_db();
$user = current_user();
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
    header("Location: " . url("/hod/proposals.php"));
    exit;
}

// Department verification: HOD can only review proposals within their department
if ((int)$proposal['department_id'] !== (int)$user['department_id']) {
    flash('danger', 'Access denied: You can only review proposals belonging to your department.');
    header("Location: " . url("/hod/proposals.php"));
    exit;
}

// Check if proposal is eligible for HOD review
if (!can_hod_review_proposal($proposal)) {
    flash('warning', "Proposal {$proposal['proposal_number']} is currently in status '{$proposal['current_status']}' and is not awaiting HOD review.");
    header("Location: " . url("/scientist/proposal-details.php?id={$id}"));
    exit;
}

$pageTitle = 'HOD Review - ' . $proposal['proposal_number'];

// Fetch Co-PIs & documents
$copis = get_proposal_copis($id, (int)($proposal['linked_project_id'] ?? 0), (string)($proposal['project_number'] ?? ''));

// Fetch comments, history and scientist remarks
$scientistRemarkData = get_latest_scientist_remark($id, $proposal);
$commentsList = get_proposal_comments($id);
$statusHistory = get_proposal_status_history($id);

$docs = $db->prepare("SELECT * FROM proposal_documents WHERE proposal_id = ?");
$docs->execute([$id]);
$docs = $docs->fetchAll();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $decision = $_POST['decision'] ?? ''; // 'return' or 'forward'
    $comments = trim(sanitize($_POST['comments'] ?? ''));

    if ($decision === 'return' && empty($comments)) {
        $error = "Please provide detailed comments and revision directives when returning a proposal to the Scientist.";
    } elseif ($decision !== 'return' && $decision !== 'forward') {
        $error = "Please choose a valid review decision (Return for Revision or Forward to Joint Director).";
    } else {
        try {
            $db->beginTransaction();

            $prevStatus = $proposal['current_status'];

            if ($decision === 'return') {
                $newStatus = STATUS_RETURNED_HOD;
                $actionType = 'PROPOSAL_RETURNED_BY_HOD';
                $histComment = "Returned by Head of Department for revisions. Comments: " . $comments;
                $flashMsg = "Proposal {$proposal['proposal_number']} returned to Scientist for revision.";
            } else {
                $newStatus = STATUS_FORWARDED_JD;
                $actionType = 'PROPOSAL_FORWARDED_BY_HOD';
                $histComment = "Endorsed and forwarded to Joint Director by Head of Department." . (!empty($comments) ? " Endorsement Remarks: {$comments}" : '');
                $flashMsg = "Proposal {$proposal['proposal_number']} successfully forwarded to Joint Director.";
            }

            $now = date('Y-m-d H:i:s');
            // Update proposal status
            $stmt = $db->prepare("UPDATE proposals SET current_status = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$newStatus, $now, $id]);

            // Sync with progress_reports table if this is an ongoing project progress report
            if (($proposal['proposal_category'] ?? '') === 'ongoing' || !empty($proposal['linked_project_id'])) {
                $linkedPid = (int)($proposal['linked_project_id'] ?? 0);
                if ($linkedPid <= 0 && !empty($proposal['project_number'])) {
                    $findP = $db->prepare("SELECT id FROM projects WHERE project_number = ?");
                    $findP->execute([$proposal['project_number']]);
                    $fp = $findP->fetch();
                    if ($fp) $linkedPid = (int)$fp['id'];
                }
                if ($linkedPid > 0) {
                    $repReviewStatus = ($decision === 'return') ? 'Needs Revision' : 'Forwarded to Joint Director';
                    $reportPeriod = $proposal['progress_report_period'] ?? '';
                    try {
                        if (!empty($reportPeriod)) {
                            $stmtR = $db->prepare("UPDATE progress_reports 
                                SET review_status = ?, 
                                    reviewer_comments = ?, 
                                    reviewed_by = ?, 
                                    reviewer_role = 'Head of Department', 
                                    reviewed_at = ?, 
                                    feedback_viewed_by_scientist = 0,
                                    updated_at = ? 
                                WHERE project_id = ? AND (report_period = ? OR reporting_period = ?)");
                            $stmtR->execute([
                                $repReviewStatus, 
                                $comments ?: ($decision === 'return' ? 'Returned by HOD for revision' : 'Endorsed and forwarded to Joint Director by HOD'), 
                                $userId, 
                                $now, 
                                $now, 
                                $linkedPid,
                                $reportPeriod,
                                $reportPeriod
                            ]);
                        } else {
                            $stmtR = $db->prepare("UPDATE progress_reports 
                                SET review_status = ?, 
                                    reviewer_comments = ?, 
                                    reviewed_by = ?, 
                                    reviewer_role = 'Head of Department', 
                                    reviewed_at = ?, 
                                    feedback_viewed_by_scientist = 0,
                                    updated_at = ? 
                                WHERE project_id = ? AND (review_status IS NULL OR review_status = 'Submitted' OR review_status = 'Pending Review')");
                            $stmtR->execute([
                                $repReviewStatus, 
                                $comments ?: ($decision === 'return' ? 'Returned by HOD for revision' : 'Endorsed and forwarded to Joint Director by HOD'), 
                                $userId, 
                                $now, 
                                $now, 
                                $linkedPid
                            ]);
                        }
                    } catch (Throwable $eR) {}
                }
            }

            // Record comment
            if (!empty($comments)) {
                $stmtC = $db->prepare("INSERT INTO proposal_comments (proposal_id, user_id, comment_stage, comment, created_at)
                                       VALUES (?, ?, 'HOD Review', ?, ?)");
                $stmtC->execute([$id, $userId, $comments, $now]);
            }

            // Record Status History
            record_status_history($id, $prevStatus, $newStatus, $userId, 'HOD', $histComment);

            // Audit log
            log_audit($userId, $actionType, 'proposals', $id, "HOD decided '{$decision}' on proposal {$proposal['proposal_number']}. Remarks: {$comments}");

            $db->commit();
            flash('success', $flashMsg);
            header("Location: " . url("/hod/dashboard.php"));
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error processing review decision: " . $e->getMessage();
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
                    Head of Department (HOD) Evaluation: Progress Report
                <?php elseif (($proposal['proposal_category'] ?? '') === 'completed'): ?>
                    Head of Department (HOD) Evaluation: Completion Report
                <?php else: ?>
                    Head of Department (HOD) Proposal Evaluation
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
        <a href="<?= url("/hod/dashboard.php") ?>" class="btn btn-outline-secondary btn-sm">
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

<!-- Scientist Resubmission Remarks / Response to Feedback Banner -->
<?= render_scientist_remarks_box($proposal, $scientistRemarkData) ?>

<!-- Review Decision Box at Top for Fast Action -->
<div class="card shadow-sm border-0 mb-4" style="border-top: 4px solid #f59e0b !important;">
    <div class="card-header bg-white py-3">
        <h5 class="fw-bold text-dark m-0">
            <i class="bi bi-pen-fill text-warning me-2"></i>
            <?php if (($proposal['proposal_category'] ?? '') === 'ongoing'): ?>
                Record HOD Progress Report Endorsement Decision
            <?php elseif (($proposal['proposal_category'] ?? '') === 'completed'): ?>
                Record HOD Completion Report Endorsement Decision
            <?php else: ?>
                Record HOD Review Decision
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="<?= url("/hod/review-proposal.php?id=" . ($id) . "") ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="mb-3">
                <label for="comments" class="form-label small fw-semibold text-secondary">
                    Reviewer's Comments / Feedback / Endorsement Remarks:
                </label>
                <textarea id="comments" name="comments" class="form-control" rows="4" placeholder="Mandatory if returning for revisions. Enter technical observations, gap critiques, or budget recommendations..."></textarea>
                <small class="text-muted">These comments will be permanently recorded in the proposal timeline and visible to the Scientist and Joint Director.</small>
            </div>

            <div class="d-flex flex-wrap gap-3 justify-content-end align-items-center pt-3 border-top">
                <button type="submit" name="decision" value="return" class="btn btn-outline-danger fw-semibold" data-confirm="Are you sure you want to RETURN this submission to the Scientist for revision?">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Return for Revision
                </button>
                <button type="submit" name="decision" value="forward" class="btn btn-primary fw-semibold px-4" style="background-color: #1a365d; border-color: #1a365d;" data-confirm="Are you sure you want to ENDORSE and FORWARD this submission to the Joint Director?">
                    <i class="bi bi-check2-circle me-1"></i> Endorse & Forward to Joint Director
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
                    <i class="bi bi-journal-text text-primary"></i> Proposal Overview & Objectives
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
                            <strong class="small text-muted text-uppercase d-block mb-1">Expected Deliverables / Outcomes:</strong>
                            <p class="text-secondary small"><?= nl2br(e($proposal['expected_outcomes'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

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

        <!-- Review Comments, Scientist Remarks & Timeline History -->
        <div class="detail-section-card shadow-sm border-0 mb-4">
            <div class="detail-section-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-chat-left-quote-fill text-primary me-2"></i>
                    <strong>Submission Remarks, Reviewer Comments & Discussion History</strong>
                </div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">
                    <?= count($commentsList) + (!empty($proposal['submission_remarks']) ? 1 : 0) ?> Entry / Entries
                </span>
            </div>
            <div class="detail-section-body p-3">
                <?php if (empty($commentsList) && empty($proposal['submission_remarks'])): ?>
                    <div class="p-3 bg-light rounded border text-muted small text-center">
                        <i class="bi bi-chat-square-dots fs-4 d-block mb-1 text-secondary"></i>
                        No formal review comments or remarks recorded for this submission yet.
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush mb-3">
                        <?php if (!empty($proposal['submission_remarks'])): 
                            $alreadyInComments = false;
                            foreach ($commentsList as $tc) {
                                if (trim($tc['comment']) === trim($proposal['submission_remarks'])) {
                                    $alreadyInComments = true;
                                    break;
                                }
                            }
                            if (!$alreadyInComments):
                        ?>
                            <div class="list-group-item px-3 py-3 border rounded mb-2 bg-light-subtle border-primary-subtle shadow-xs">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark"><i class="bi bi-person-fill text-primary me-1"></i><?= e($proposal['scientist_name']) ?> <span class="text-muted small">(Principal Investigator)</span></span>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">Scientist Submission Remarks</span>
                                </div>
                                <div class="text-dark small fw-semibold mt-2" style="white-space: pre-wrap; line-height: 1.6;"><?= nl2br(e($proposal['submission_remarks'])) ?></div>
                                <div class="extra-small text-muted mt-2"><i class="bi bi-clock me-1"></i><?= format_datetime($proposal['submitted_at'] ?: $proposal['updated_at']) ?></div>
                            </div>
                        <?php endif; endif; ?>

                        <?php foreach ($commentsList as $c): 
                            $isScientist = stripos($c['comment_stage'], 'Scientist') !== false || stripos($c['role_name'], 'Scientist') !== false;
                        ?>
                            <div class="list-group-item px-3 py-3 border rounded mb-2 <?= $isScientist ? 'bg-light-subtle border-primary-subtle' : 'bg-white border-warning-subtle' ?> shadow-xs">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark">
                                        <i class="bi <?= $isScientist ? 'bi-person-fill text-primary' : 'bi-award-fill text-warning' ?> me-1"></i>
                                        <?= e($c['user_name']) ?> <span class="text-muted small">(<?= e($c['role_name']) ?>)</span>
                                    </span>
                                    <span class="badge <?= $isScientist ? 'bg-primary-subtle text-primary' : 'bg-warning-subtle text-warning-emphasis' ?> border font-monospace">
                                        <?= e($c['comment_stage']) ?>
                                    </span>
                                </div>
                                <div class="text-dark small fw-semibold mt-2" style="white-space: pre-wrap; line-height: 1.6;"><?= nl2br(e($c['comment'])) ?></div>
                                <div class="extra-small text-muted mt-2"><i class="bi bi-clock me-1"></i><?= format_datetime($c['created_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Complete Workflow Status History Log -->
                <?php if (!empty($statusHistory)): ?>
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-clock-history text-secondary me-2"></i>Workflow Status History Log</h6>
                        <div class="timeline ps-2">
                            <?php foreach ($statusHistory as $h): ?>
                                <div class="timeline-item pb-3 mb-2 border-start border-2 border-primary-subtle ps-3 position-relative">
                                    <div class="d-flex justify-content-between align-items-baseline">
                                        <strong class="small text-dark"><?= e($h['new_status']) ?></strong>
                                        <span class="badge bg-light text-secondary border extra-small"><?= e($h['action_by_role']) ?></span>
                                    </div>
                                    <?php if (!empty($h['comments'])): ?>
                                        <div class="small text-secondary mt-1 fst-italic">"<?= e($h['comments']) ?>"</div>
                                    <?php endif; ?>
                                    <small class="text-muted extra-small d-block mt-1"><?= format_datetime($h['created_at']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-person-badge text-primary"></i> Investigator & Financials
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
                        <span class="text-muted d-block">Principal Investigator:</span>
                        <strong class="text-dark"><?= e($proposal['scientist_name']) ?></strong> (<?= e($proposal['scientist_designation']) ?>)
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Department:</span>
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
                        <span class="text-muted d-block">Readiness Level:</span>
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
