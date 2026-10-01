<?php
/**
 * Research Proposal and Project Management System
 * Proposal Details & Complete Audit History Timeline
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    flash('danger', 'Invalid proposal identifier.');
    header("Location: " . url("/scientist/proposals.php"));
    exit;
}

$db = get_db();
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
    header("Location: " . url("/scientist/proposals.php"));
    exit;
}

if (!can_view_proposal($proposal)) {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}

$pageTitle = 'Proposal Details - ' . $proposal['proposal_number'];
$currentUser = current_user();

// Fetch Co-PIs
$copis = get_proposal_copis($id, (int)($proposal['linked_project_id'] ?? 0), (string)($proposal['project_number'] ?? ''));

// Fetch Documents
$stmt = $db->prepare("SELECT * FROM proposal_documents WHERE proposal_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$documents = $stmt->fetchAll();

// Fetch Workflow Status History
$stmt = $db->prepare("SELECT h.*, u.name as user_name
                      FROM proposal_status_history h
                      LEFT JOIN users u ON h.action_by = u.id
                      WHERE h.proposal_id = ?
                      ORDER BY h.created_at ASC");
$stmt->execute([$id]);
$history = $stmt->fetchAll();

// Fetch Reviewer Comments
$stmt = $db->prepare("SELECT c.*, u.name as user_name, r.role_name
                      FROM proposal_comments c
                      JOIN users u ON c.user_id = u.id
                      JOIN roles r ON u.role_id = r.id
                      WHERE c.proposal_id = ?
                      ORDER BY c.created_at ASC");
$stmt->execute([$id]);
$comments = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Header Banner -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="badge bg-secondary font-monospace"><?= e($proposal['proposal_number']) ?></span>
                    <?= render_status_badge($proposal['current_status']) ?>
                    <?php if (($proposal['proposal_category'] ?? '') === 'completed'): ?>
                        <span class="badge bg-success text-white shadow-xs">
                            <i class="bi bi-check2-circle me-1"></i> Completion Project
                        </span>
                    <?php elseif (($proposal['proposal_category'] ?? '') === 'ongoing'): ?>
                        <span class="badge bg-info text-white shadow-xs">
                            <i class="bi bi-arrow-repeat me-1"></i> On Going Project (Progress Report)
                        </span>
                    <?php else: ?>
                        <span class="badge bg-primary text-white shadow-xs">
                            <i class="bi bi-file-earmark-plus me-1"></i> New Proposal
                        </span>
                    <?php endif; ?>

                    <?php if (($proposal['project_type'] ?? '') === 'funding_agency'): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="bi bi-bank2 me-1"></i> Funding Agency
                        </span>
                        <?php if (!empty($proposal['funding_agency'])): ?>
                            <span class="badge bg-light text-dark border">
                                <?= e($proposal['funding_agency']) ?>
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                            <i class="bi bi-house-door-fill me-1"></i> In-house
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($proposal['project_number'])): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace">
                            Project Code: <?= e($proposal['project_number']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <h4 class="fw-bold text-dark mb-2"><?= e($proposal['title']) ?></h4>
                <div class="text-muted small">
                    <i class="bi bi-person me-1"></i> <strong><?= e($proposal['scientist_name']) ?></strong> (<?= e($proposal['scientist_designation']) ?>) |
                    <i class="bi bi-building me-1"></i> <?= e($proposal['department_name']) ?>
                    <?php if (!empty($proposal['discipline'])): ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1"><i class="bi bi-mortarboard me-1"></i>Discipline: <?= e($proposal['discipline']) ?></span>
                    <?php endif; ?> |
                    <i class="bi bi-clock-history me-1"></i> Submitted: <?= format_date($proposal['submitted_at'] ?? $proposal['created_at']) ?>
                </div>
            </div>

            <!-- Action Controls -->
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= url("/export-doc.php?id=" . ($proposal['id']) . "") ?>" class="btn btn-outline-secondary" title="Download Office Open XML Document (.docx)">
                    <i class="bi bi-file-earmark-word me-1"></i> Export Word (.docx)
                </a>

                <?php if (can_edit_proposal($proposal)): 
                    $editDetailUrl = url("/scientist/edit-proposal.php?id=" . $proposal['id']);
                    $editDetailLabel = ($proposal['current_status'] === STATUS_SUBMITTED_HOD) ? 'Edit Proposal' : 'Edit & Resubmit';
                    if (($proposal['proposal_category'] ?? '') === 'ongoing') {
                        $editDetailUrl = url("/scientist/ongoing-projects.php?id=" . $proposal['id']);
                        $editDetailLabel = ($proposal['current_status'] === STATUS_DRAFT) ? 'Edit Progress Report Draft' : 'Edit & Resubmit Report';
                    } elseif (($proposal['proposal_category'] ?? '') === 'completed') {
                        $editDetailUrl = url("/scientist/create-completed-proposal.php?id=" . $proposal['id']);
                        $editDetailLabel = ($proposal['current_status'] === STATUS_DRAFT) ? 'Edit Completed Project Draft' : 'Edit & Resubmit';
                    }
                ?>
                    <a href="<?= $editDetailUrl ?>" class="btn btn-primary" style="background-color: #1a365d; border-color: #1a365d;">
                        <i class="bi bi-pencil-square me-1"></i> <?= $editDetailLabel ?>
                    </a>
                <?php endif; ?>

                <?php if (can_hod_review_proposal($proposal)): ?>
                    <a href="<?= url("/hod/review-proposal.php?id=" . ($proposal['id']) . "") ?>" class="btn btn-warning fw-semibold">
                        <i class="bi bi-clipboard-check me-1"></i> HOD Review Decision
                    </a>
                <?php endif; ?>

                <?php if (can_jd_initial_review($proposal)): ?>
                    <a href="<?= url("/joint-director/review-proposal.php?id=" . ($proposal['id']) . "") ?>" class="btn btn-primary fw-semibold">
                        <i class="bi bi-clipboard-check me-1"></i> Joint Director Review
                    </a>
                <?php endif; ?>

                <?php if (can_record_irc_decision($proposal)): ?>
                    <a href="<?= url("/joint-director/irc-decision.php?id=" . ($proposal['id']) . "") ?>" class="btn btn-success fw-semibold">
                        <i class="bi bi-check2-all me-1"></i> Final IRC Decision
                    </a>
                <?php endif; ?>

                <?php if (can_delete_proposal($proposal)): ?>
                    <form method="POST" action="<?= url("/scientist/delete-proposal.php") ?>" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this <?= (($proposal['proposal_category'] ?? '') === 'ongoing') ? 'progress report submission' : 'proposal' ?>?');">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="proposal_id" value="<?= (int)$proposal['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger" title="Permanently Delete">
                            <i class="bi bi-trash me-1"></i> Delete
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (in_array($proposal['current_status'], [STATUS_RETURNED_HOD, STATUS_RETURNED_JD])): ?>
    <div class="alert alert-warning border-warning shadow-sm mb-4" role="alert">
        <h5 class="alert-heading fw-bold"><i class="bi bi-arrow-repeat me-2"></i>Proposal Returned for Revisions</h5>
        <p class="mb-2">
            This proposal has been returned by <strong><?= $proposal['current_status'] === STATUS_RETURNED_HOD ? 'Head of Department' : 'Joint Director' ?></strong>.
            Review the comments below, edit the proposal form accordingly, and resubmit for workflow re-evaluation.
        </p>
        <?php if (can_edit_proposal($proposal)): ?>
            <a href="<?= $editDetailUrl ?>" class="btn btn-sm btn-dark">
                <i class="bi bi-pencil-square me-1"></i> Open Editor
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left Column: Scientific Proposal Content -->
    <div class="col-lg-8">
        <?php if (($proposal['proposal_category'] ?? '') === 'completed'): ?>
            <!-- Extension Request Card if scientist requested extension -->
            <?php if (!empty($proposal['extension_requested'])): ?>
                <div class="card border-warning shadow-sm mb-4">
                    <div class="card-header bg-warning bg-opacity-25 border-warning d-flex justify-content-between align-items-center py-2">
                        <div class="fw-bold text-dark">
                            <i class="bi bi-clock-history text-warning-emphasis me-2"></i> Project Duration Extension & Additional Grant Requested
                        </div>
                        <span class="badge bg-warning text-dark border border-warning">Extension Requested</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <span class="text-muted small d-block">Requested Extended Completion Date:</span>
                                <h6 class="fw-bold text-primary mb-0"><i class="bi bi-calendar-event me-1"></i><?= format_date($proposal['extended_end_date']) ?></h6>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted small d-block">Additional Funds Requested (₹):</span>
                                <h6 class="fw-bold text-success mb-0"><i class="bi bi-currency-rupee me-1"></i><?= format_currency((float)$proposal['additional_funds_requested']) ?></h6>
                            </div>
                            <div class="col-12">
                                <span class="text-muted small d-block">Scientific Justification for Extension & Funds:</span>
                                <div class="bg-light p-3 rounded border small text-dark mt-1" style="line-height: 1.6;">
                                    <?= nl2br(e($proposal['extension_justification'] ?: 'No justification provided.')) ?>
                                </div>
                            </div>
                            <?php if (!empty($proposal['extension_decision'])): ?>
                                <div class="col-12">
                                    <div class="p-2 rounded bg-success-subtle border border-success text-success small">
                                        <strong><i class="bi bi-check-circle-fill me-1"></i> IRC Council Decision:</strong>
                                        <?php if ($proposal['extension_decision'] === 'extended_time_and_funds'): ?>
                                            Sanctioned Duration Extension to <strong><?= format_date($proposal['approved_extension_date']) ?></strong> with Additional Grant of <strong><?= format_currency((float)$proposal['approved_additional_funds']) ?></strong>.
                                        <?php elseif ($proposal['extension_decision'] === 'extended_time_only'): ?>
                                            Sanctioned Duration Extension to <strong><?= format_date($proposal['approved_extension_date']) ?></strong> without additional budget increase.
                                        <?php elseif ($proposal['extension_decision'] === 'concluded'): ?>
                                            Concluded as Completed without extension.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Completed Proposal Sections -->
            <!-- 1. Executive Final Report (250 Words Limit) -->
            <div class="detail-section-card">
                <div class="detail-section-header d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-file-earmark-text text-success"></i> Executive Final Report</div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                        Max 250 Words
                    </span>
                </div>
                <div class="detail-section-body">
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

            <!-- 2. Significant Achievements -->
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

            <!-- 3. ATR on Previous IRC Recommendations -->
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

            <!-- 4. Deliverables & Output / Outcome -->
            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-box-seam text-info"></i> Deliverables & Output / Outcome
                </div>
                <div class="detail-section-body">
                    <div class="p-3 bg-light rounded border text-dark" style="white-space: pre-wrap; line-height: 1.6;">
                        <?= e($proposal['output_outcome'] ?: 'None specified.') ?>
                    </div>
                </div>
            </div>

            <!-- 5. Other Details if any -->
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
            <!-- Ongoing Progress Report Sections -->
            <!-- 1. Executive Progress Report (250 Words Limit) -->
            <div class="detail-section-card">
                <div class="detail-section-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-file-earmark-text text-info"></i> Biannual Progress Report
                        <?php if (!empty($proposal['progress_report_period'])): ?>
                            <span class="badge bg-white text-info ms-2 fw-semibold"><?= e($proposal['progress_report_period']) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="badge bg-info-subtle text-info border border-info-subtle">
                        Max 250 Words
                    </span>
                </div>
                <div class="detail-section-body">
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

            <!-- 5. Other Details if any -->
            <?php if (!empty($proposal['other_details'])): ?>
                <div class="detail-section-card">
                    <div class="detail-section-header">
                        <i class="bi bi-info-circle text-secondary"></i> Other Details & Work Plan Ahead
                    </div>
                    <div class="detail-section-body">
                        <div class="p-3 bg-light rounded border text-secondary" style="white-space: pre-wrap; line-height: 1.6;">
                            <?= e($proposal['other_details']) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- 1. Metadata & Objectives -->
            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-bullseye text-primary"></i> Specific Objectives
                </div>
                <div class="detail-section-body">
                    <div class="p-3 bg-light rounded border mb-3 rich-text-preview">
                        <?= render_rich_text($proposal['objectives'] ?: 'None specified.') ?>
                    </div>
                </div>
            </div>

            <!-- 2. Technical Program Proposed -->
            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-gear-wide-connected text-primary"></i> Technical Program Proposed (objective wise, also indicate the role of Co Pis)
                </div>
                <div class="detail-section-body">
                    <div class="p-3 bg-light rounded border text-secondary small" style="line-height: 1.6; white-space: pre-wrap;">
                        <?= e($proposal['technical_program'] ?: 'None specified.') ?>
                    </div>
                </div>
            </div>

            <!-- 3. Scientific Background & Novelty -->
            <div class="detail-section-card">
                <div class="detail-section-header">
                    <i class="bi bi-journal-text text-primary"></i> Research Problem, Novelty & Expected Outcomes
                </div>
                <div class="detail-section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong class="d-block small text-muted text-uppercase">Research Problem / Key Questions:</strong>
                            <p class="small text-dark"><?= nl2br(e($proposal['research_problem'] ?: '-')) ?></p>
                        </div>
                        <div class="col-md-6">
                            <strong class="d-block small text-muted text-uppercase">Baseline Information:</strong>
                            <p class="small text-dark"><?= nl2br(e($proposal['baseline_info'] ?: '-')) ?></p>
                        </div>
                        <div class="col-md-6">
                            <strong class="d-block small text-muted text-uppercase">Novelty & Gap Analysis:</strong>
                            <p class="small text-dark"><?= nl2br(e($proposal['novelty_gap_analysis'] ?: '-')) ?></p>
                        </div>
                        <div class="col-md-6">
                            <strong class="d-block small text-muted text-uppercase">Justification & End Users:</strong>
                            <p class="small text-dark"><?= nl2br(e($proposal['justification_end_users'] ?: '-')) ?></p>
                        </div>
                        <div class="col-12">
                            <strong class="d-block small text-muted text-uppercase">Expected Deliverables / Outcomes:</strong>
                            <p class="small text-dark"><?= nl2br(e($proposal['expected_outcomes'] ?: '-')) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 4. Review Comments & Discussion -->
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-chat-left-text text-primary"></i> Official Review Comments & Remarks
            </div>
            <div class="detail-section-body">
                <?php if (empty($comments)): ?>
                    <p class="text-muted small mb-0">No review comments recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($comments as $c): ?>
                        <div class="border rounded p-3 mb-2 bg-light-subtle">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-semibold text-dark"><?= e($c['user_name']) ?> <span class="text-muted small">(<?= e($c['role_name']) ?>)</span></span>
                                <span class="badge bg-secondary-subtle text-dark border"><?= e($c['comment_stage']) ?></span>
                            </div>
                            <div class="small text-secondary mb-1"><?= nl2br(e($c['comment'])) ?></div>
                            <div class="text-muted small" style="font-size: 0.75rem;"><?= format_datetime($c['created_at']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Administrative Meta, Financials & Complete History -->
    <div class="col-lg-4">
        <!-- Financial & Timeline Summary -->
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-currency-rupee text-success"></i> Financial & Duration Summary
            </div>
            <div class="detail-section-body">
                <?php if (in_array(($proposal['proposal_category'] ?? ''), ['completed', 'ongoing'])): ?>
                    <div class="mb-2 pb-2 border-bottom">
                        <span class="text-muted small d-block">Budget Allocated:</span>
                        <h4 class="fw-bold text-primary mb-0"><?= format_currency((float)($proposal['budget_allocated'] ?: $proposal['proposed_budget'])) ?></h4>
                    </div>
                    <div class="mb-3 pb-2 border-bottom">
                        <span class="text-muted small d-block">Budget Utilized:</span>
                        <h4 class="fw-bold text-success mb-0"><?= format_currency((float)$proposal['budget_utilized']) ?></h4>
                        <?php 
                            $alloc = (float)($proposal['budget_allocated'] ?: $proposal['proposed_budget']);
                            $util = (float)$proposal['budget_utilized'];
                            $pct = ($alloc > 0) ? round(($util / $alloc) * 100, 1) : 0;
                        ?>
                        <div class="mt-2">
                            <span class="badge <?= $pct > 100 ? 'bg-danger text-white' : ($pct >= 85 ? 'bg-success text-white' : 'bg-warning text-dark') ?> px-2 py-1 fs-6 fw-bold">
                                <i class="bi bi-pie-chart-fill me-1"></i> Utilization: <?= $pct ?>%
                            </span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="mb-3 pb-2 border-bottom">
                        <span class="text-muted small d-block">Proposed Budget:</span>
                        <h4 class="fw-bold text-primary mb-0"><?= format_currency((float)$proposal['proposed_budget']) ?></h4>
                    </div>

                    <?php if ($proposal['approved_budget'] !== null): ?>
                        <div class="mb-3 pb-2 border-bottom">
                            <span class="text-muted small d-block">IRC Approved Budget:</span>
                            <h4 class="fw-bold text-success mb-0"><?= format_currency((float)$proposal['approved_budget']) ?></h4>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Year-wise Budget Formulation Breakdown -->
                <?php 
                $yearlyTotal = (float)($proposal['approved_budget'] ?: ($proposal['budget_allocated'] ?: $proposal['proposed_budget']));
                ?>
                <div class="mb-3 pb-2 border-bottom">
                    <span class="text-muted extra-small d-block fw-bold text-uppercase mb-1"><i class="bi bi-calendar3-range text-success me-1"></i> Year-wise Budget Allocation:</span>
                    <?= render_yearly_budget_html($proposal['yearly_budget'] ?? null, $yearlyTotal) ?>
                </div>

                <div class="row g-2 small">
                    <div class="col-6">
                        <span class="text-muted d-block">Start Date:</span>
                        <strong><?= format_date($proposal['proposed_start_date']) ?></strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block">End Date:</span>
                        <strong><?= format_date($proposal['proposed_end_date']) ?></strong>
                    </div>
                    <div class="col-12 mt-1">
                        <span class="text-muted d-block">Project Duration:</span>
                        <strong class="text-primary"><i class="bi bi-hourglass-split me-1"></i><?= format_duration($proposal['proposed_start_date'], $proposal['proposed_end_date']) ?></strong>
                    </div>
                    <div class="col-12 mt-2">
                        <span class="text-muted d-block">Institute Priority Area:</span>
                        <div class="fw-semibold text-dark mt-1">
                            <?= e(get_priority_area_title($proposal['institute_priority_area'] ?? '')) ?>
                        </div>
                    </div>
                    <?php if (!empty($proposal['national_priority_area'])): ?>
                        <div class="col-12 mt-1">
                            <span class="text-muted d-block">National Priority Area:</span>
                            <span class="text-dark fw-semibold"><?= e($proposal['national_priority_area']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php 
                    $agencyDetails = get_funding_agency_details($proposal);
                    ?>
                    <div class="col-12 mt-1">
                        <span class="text-muted d-block">Project Category & Funding:</span>
                        <div class="mt-1">
                            <?= $agencyDetails['badge_html'] ?>
                            <?php if ($agencyDetails['is_external'] && !empty($agencyDetails['agency_name'])): ?>
                                <div class="text-muted extra-small mt-1">Agency: <strong><?= e($agencyDetails['agency_name']) ?></strong> (<?= e($agencyDetails['agency_type']) ?>)</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12 mt-1">
                        <span class="text-muted d-block">Technology Readiness Level:</span>
                        <span class="badge bg-info-subtle text-dark border">TRL-<?= e($proposal['trl_level']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Co-Investigators -->
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-people text-primary"></i> Co-Principal Investigators
            </div>
            <div class="detail-section-body p-2">
                <?php if (empty($copis)): ?>
                    <p class="text-muted small p-2 mb-0">No Co-PIs registered.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($copis as $cp): ?>
                            <div class="list-group-item px-2 py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="small text-dark"><?= e($cp['co_pi_name']) ?></strong>
                                    <?php if (!empty($cp['designation'])): ?>
                                        <span class="badge bg-light text-secondary border"><?= e($cp['designation']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted d-block"><?= e($cp['institution']) ?></small>
                                <?php if (!empty($cp['email'])): ?>
                                    <small class="text-secondary"><i class="bi bi-envelope me-1"></i><?= e($cp['email']) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attached Annexures -->
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-paperclip text-primary"></i> Attached Documents
            </div>
            <div class="detail-section-body p-2">
                <?php if (empty($documents)): ?>
                    <p class="text-muted small p-2 mb-0">No files attached to this proposal.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($documents as $doc): ?>
                            <div class="list-group-item px-2 py-2 d-flex justify-content-between align-items-center">
                                <div class="text-truncate me-2">
                                    <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
                                    <span class="small fw-semibold text-dark"><?= e($doc['file_name']) ?></span>
                                </div>
                                <a href="<?= e($doc['file_path']) ?>" download class="btn btn-xs btn-outline-secondary" style="font-size: 0.75rem;">
                                    <i class="bi bi-download"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Complete Approval Workflow History Timeline -->
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-clock-history text-primary"></i> Workflow History Timeline
            </div>
            <div class="detail-section-body">
                <div class="timeline">
                    <?php foreach ($history as $h): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker <?= str_contains($h['new_status'], 'Approved') ? 'success' : (str_contains($h['new_status'], 'Returned') ? 'danger' : '') ?>"></div>
                            <div class="timeline-content">
                                <div class="d-flex justify-content-between align-items-baseline mb-1">
                                    <strong class="text-dark small"><?= e($h['new_status']) ?></strong>
                                    <span class="badge bg-secondary-subtle text-dark" style="font-size: 0.65rem;"><?= e($h['action_by_role']) ?></span>
                                </div>
                                <?php if (!empty($h['comments'])): ?>
                                    <p class="small text-secondary mb-1 fst-italic">"<?= e($h['comments']) ?>"</p>
                                <?php endif; ?>
                                <small class="text-muted d-block" style="font-size: 0.72rem;"><?= format_datetime($h['created_at']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
