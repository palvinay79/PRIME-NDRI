<?php
/**
 * Research Proposal and Project Management System
 * Scientist - Active Projects Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD]);

$currentUser = current_user();
$userId = current_user_id();
$isHod = (current_user_role_id() === ROLE_HOD);
$pageTitle = $isHod ? 'My Research Projects' : 'My Research Projects';
$db = get_db();

if ($isHod) {
    $stmt = $db->prepare("SELECT pr.*, p.title as proposal_title, p.institute_priority_area, p.proposed_budget,
                                 p.project_type as prop_project_type, p.funding_agency as prop_funding_agency,
                                 p.funding_agency_type as prop_funding_agency_type, p.yearly_budget as prop_yearly_budget,
                                 d.department_name, d.department_code,
                                 (SELECT COUNT(*) FROM progress_reports r WHERE r.project_id = pr.id) as reports_count,
                                 (SELECT MAX(submitted_at) FROM progress_reports r WHERE r.project_id = pr.id) as latest_report_date,
                                 (SELECT COALESCE(SUM(COALESCE(budget_utilized, budget_utilization, 0)), 0) FROM progress_reports r WHERE r.project_id = pr.id) as total_expenditure,
                                 (SELECT COUNT(*) FROM progress_reports r WHERE r.project_id = pr.id AND (r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '')) as feedback_count,
                                 (SELECT r.reviewer_comments FROM progress_reports r WHERE r.project_id = pr.id AND (r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '') ORDER BY COALESCE(r.reviewed_at, r.updated_at, r.id) DESC LIMIT 1) as latest_feedback,
                                 (SELECT r.reviewer_role FROM progress_reports r WHERE r.project_id = pr.id AND (r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '') ORDER BY COALESCE(r.reviewed_at, r.updated_at, r.id) DESC LIMIT 1) as latest_reviewer_role,
                                 (SELECT r.review_status FROM progress_reports r WHERE r.project_id = pr.id AND (r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '') ORDER BY COALESCE(r.reviewed_at, r.updated_at, r.id) DESC LIMIT 1) as latest_review_status
                          FROM projects pr
                          JOIN proposals p ON pr.proposal_id = p.id
                          JOIN departments d ON COALESCE(pr.department_id, p.department_id) = d.id
                          WHERE (pr.scientist_id = ? OR pr.department_id = ?)
                          ORDER BY pr.created_at DESC");
    $stmt->execute([$userId, (int)($currentUser['department_id'] ?? 1)]);
} else {
    $stmt = $db->prepare("SELECT pr.*, p.title as proposal_title, p.institute_priority_area, p.proposed_budget,
                                 p.project_type as prop_project_type, p.funding_agency as prop_funding_agency,
                                 p.funding_agency_type as prop_funding_agency_type, p.yearly_budget as prop_yearly_budget,
                                 d.department_name, d.department_code,
                                 (SELECT COUNT(*) FROM progress_reports r WHERE r.project_id = pr.id) as reports_count,
                                 (SELECT MAX(submitted_at) FROM progress_reports r WHERE r.project_id = pr.id) as latest_report_date,
                                 (SELECT COALESCE(SUM(COALESCE(budget_utilized, budget_utilization, 0)), 0) FROM progress_reports r WHERE r.project_id = pr.id) as total_expenditure,
                                 (SELECT COUNT(*) FROM progress_reports r WHERE r.project_id = pr.id AND (r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '')) as feedback_count,
                                 (SELECT r.reviewer_comments FROM progress_reports r WHERE r.project_id = pr.id AND (r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '') ORDER BY COALESCE(r.reviewed_at, r.updated_at, r.id) DESC LIMIT 1) as latest_feedback,
                                 (SELECT r.reviewer_role FROM progress_reports r WHERE r.project_id = pr.id AND (r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '') ORDER BY COALESCE(r.reviewed_at, r.updated_at, r.id) DESC LIMIT 1) as latest_reviewer_role,
                                 (SELECT r.review_status FROM progress_reports r WHERE r.project_id = pr.id AND (r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '') ORDER BY COALESCE(r.reviewed_at, r.updated_at, r.id) DESC LIMIT 1) as latest_review_status
                          FROM projects pr
                          JOIN proposals p ON pr.proposal_id = p.id
                          JOIN departments d ON COALESCE(pr.department_id, p.department_id) = d.id
                          WHERE pr.scientist_id = ?
                          ORDER BY pr.created_at DESC");
    $stmt->execute([$userId]);
}
$projects = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Approved Research Projects</h3>
        <p class="text-muted small mb-0">Manage execution milestones, submit progress reports, and file project completion dossiers</p>
    </div>
</div>

<div class="row g-4">
    <?php if (empty($projects)): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 py-5 text-center text-muted">
                <i class="bi bi-kanban fs-1 text-secondary mb-3"></i>
                <h5 class="fw-bold text-dark">No Active Approved Projects</h5>
                <p class="small mb-0">Projects are automatically created here once a research proposal receives final IRC approval by the Joint Director.</p>
                <div class="mt-3">
                    <a href="<?= url("/scientist/proposals.php") ?>" class="btn btn-outline-primary btn-sm">Check Proposal Statuses</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($projects as $prj): ?>
            <?php 
            $agencyDetails = get_funding_agency_details($prj);
            ?>
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace px-2 py-1">
                                <i class="bi bi-journal-code me-1"></i> <?= e($prj['project_number']) ?>
                            </span>
                            <?= $agencyDetails['badge_html'] ?>
                        </div>
                        <span class="badge <?= $prj['project_status'] === PROJECT_STATUS_ACTIVE ? 'bg-success' : 'bg-dark' ?>">
                            <?= e($prj['project_status']) ?>
                        </span>
                    </div>
                    <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                            <h5 class="fw-bold text-dark mb-2"><?= e($prj['proposal_title']) ?></h5>
                            <div class="text-muted small mb-3">
                                <i class="bi bi-building me-1"></i> <?= e($prj['department_name']) ?> |
                                <i class="bi bi-tag me-1"></i> Priority Area <?= e($prj['institute_priority_area'] ?: 'A') ?>
                            </div>

                            <div class="row g-2 mb-3 bg-light p-3 rounded">
                                <div class="col-6">
                                    <span class="text-muted small d-block">Approved Grant:</span>
                                    <strong class="text-success"><?= format_currency((float)$prj['approved_budget']) ?></strong>
                                </div>
                                <div class="col-6">
                                    <span class="text-muted small d-block">Total Expended:</span>
                                    <strong class="<?= (float)$prj['total_expenditure'] > (float)$prj['approved_budget'] ? 'text-danger' : 'text-primary' ?>">
                                        <?= format_currency((float)$prj['total_expenditure']) ?>
                                    </strong>
                                </div>
                                <div class="col-6 mt-2">
                                    <span class="text-muted small d-block">Project Duration:</span>
                                    <small class="text-dark"><?= format_date($prj['start_date']) ?> to <?= format_date($prj['end_date']) ?></small>
                                </div>
                                <div class="col-6 mt-2">
                                    <span class="text-muted small d-block">Progress Reports:</span>
                                    <span class="badge bg-primary-subtle text-primary"><?= (int)$prj['reports_count'] ?> filed</span>
                                    <?php if ($prj['latest_report_date']): ?>
                                        <small class="text-secondary d-block" style="font-size: 0.72rem;"><?= format_date($prj['latest_report_date']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 mt-2 pt-2 border-top">
                                    <span class="text-muted extra-small d-block fw-bold text-uppercase mb-1"><i class="bi bi-calendar3 text-success me-1"></i> Year-wise Budget:</span>
                                    <?= render_yearly_budget_html($prj['yearly_budget'] ?: ($prj['prop_yearly_budget'] ?? null), (float)$prj['approved_budget'], true) ?>
                                </div>
                            </div>

                            <?php 
                            $prjCopis = get_project_copis((int)$prj['id']);
                            if (empty($prjCopis)) {
                                $prjCopis = get_proposal_copis((int)$prj['proposal_id'], (int)$prj['id'], (string)$prj['project_number']);
                            }
                            if (!empty($prjCopis)): 
                            ?>
                                <div class="mb-3 p-2 bg-light rounded small">
                                    <span class="text-muted extra-small d-block fw-bold text-uppercase mb-1"><i class="bi bi-people-fill text-primary me-1"></i> Co-Principal Investigators (<?= count($prjCopis) ?>):</span>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($prjCopis as $cp): ?>
                                            <span class="badge bg-white text-dark border font-monospace" style="font-size: 0.73rem;">
                                                <i class="bi bi-person-check-fill text-success me-1"></i><?= e($cp['co_pi_name'] ?? ($cp['name'] ?? 'Co-PI')) ?> <span class="text-muted">(<?= e($cp['designation'] ?: 'Co-PI') ?>)</span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($prj['feedback_count']) && $prj['feedback_count'] > 0): ?>
                                <div class="alert alert-warning py-2 px-3 mb-3 border-start border-4 border-warning shadow-xs">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <strong class="small text-dark">
                                            <i class="bi bi-chat-left-quote-fill text-warning me-1"></i>
                                            <?= e($prj['latest_reviewer_role'] ?: 'Reviewer') ?> Feedback
                                        </strong>
                                        <span class="badge bg-dark font-monospace" style="font-size: 0.68rem;">
                                            <?= e($prj['latest_review_status'] ?: 'Reviewed') ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($prj['latest_feedback'])): ?>
                                        <div class="small text-muted fst-italic mb-1">
                                            "<?= e(mb_strimwidth($prj['latest_feedback'], 0, 120, '...')) ?>"
                                        </div>
                                    <?php endif; ?>
                                    <a href="<?= url("/scientist/view-progress.php?project_id=" . ($prj['id']) . "") ?>" class="btn btn-xs btn-outline-dark" style="font-size: 0.75rem; padding: 2px 8px;">
                                        <i class="bi bi-eye me-1"></i> Review Full Observations
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex flex-wrap gap-2 pt-2 border-top">
                            <a href="<?= url("/scientist/ongoing-projects.php?project_id=" . ($prj['id']) . "") ?>" class="btn btn-sm btn-primary" style="background-color: #1a365d; border-color: #1a365d;">
                                <i class="bi bi-arrow-repeat me-1"></i> Submit Progress Report
                            </a>
                            <a href="<?= url("/scientist/view-progress.php?project_id=" . ($prj['id']) . "") ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-list-check me-1"></i> View Reports (<?= (int)$prj['reports_count'] ?>)
                            </a>
                            <?php if ($prj['project_status'] === PROJECT_STATUS_ACTIVE): ?>
                                <a href="<?= url("/scientist/create-completed-proposal.php?project_id=" . ($prj['id']) . "") ?>" class="btn btn-sm btn-outline-dark">
                                    <i class="bi bi-check2-circle me-1"></i> Completion Report
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
