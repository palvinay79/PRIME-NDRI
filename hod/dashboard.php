<?php
/**
 * Research Proposal and Project Management System
 * Head of Department (HOD) Dashboard
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_HOD);

$pageTitle = 'HOD Dashboard';
$user = current_user();
$deptId = (int)($user['department_id'] ?? 1);
$db = get_db();

// Department name
$stmt = $db->prepare("SELECT * FROM departments WHERE id = ?");
$stmt->execute([$deptId]);
$department = $stmt->fetch();

// Status counts for this department
$stmt = $db->prepare("SELECT current_status, COUNT(*) as cnt FROM proposals WHERE department_id = ? GROUP BY current_status");
$stmt->execute([$deptId]);
$statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$pendingCount = $statusCounts[STATUS_SUBMITTED_HOD] ?? 0;
$returnedCount = $statusCounts[STATUS_RETURNED_HOD] ?? 0;
$forwardedCount = ($statusCounts[STATUS_FORWARDED_JD] ?? 0) + ($statusCounts[STATUS_APPROVED_IRC] ?? 0);
$approvedCount = ($statusCounts[STATUS_APPROVED_ACTIVE] ?? 0) + ($statusCounts[STATUS_COMPLETED] ?? 0);
$totalDept = array_sum($statusCounts);

// Breakdown of pending submissions by category (New vs Progress Report vs Completion)
$stmtCat = $db->prepare("
    SELECT COALESCE(proposal_category, 'new') as cat, COUNT(*) as cnt
    FROM proposals
    WHERE department_id = ? AND current_status = ?
    GROUP BY COALESCE(proposal_category, 'new')
");
$stmtCat->execute([$deptId, STATUS_SUBMITTED_HOD]);
$pendingByCat = $stmtCat->fetchAll(PDO::FETCH_KEY_PAIR);
$pendingProgressCount = $pendingByCat['ongoing'] ?? 0;
$pendingNewCount = $pendingByCat['new'] ?? 0;
$pendingCompletedCount = $pendingByCat['completed'] ?? 0;

// Pending Review Proposals for this HOD
$stmt = $db->prepare("SELECT p.*, u.name as scientist_name, u.designation as scientist_designation
                      FROM proposals p
                      JOIN users u ON p.scientist_id = u.id
                      WHERE p.department_id = ? AND p.current_status = ?
                      ORDER BY p.submitted_at ASC");
$stmt->execute([$deptId, STATUS_SUBMITTED_HOD]);
$pendingProposals = $stmt->fetchAll();

// Recent proposals in department
$stmt = $db->prepare("SELECT p.*, u.name as scientist_name
                      FROM proposals p
                      JOIN users u ON p.scientist_id = u.id
                      WHERE p.department_id = ?
                      ORDER BY p.updated_at DESC LIMIT 5");
$stmt->execute([$deptId]);
$recentProposals = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Department Review Portal (HOD)</h3>
        <p class="text-muted small mb-0">
            Division: <strong><?= e($department['department_name'] ?? 'Department') ?> (<?= e($department['department_code'] ?? 'NDRI') ?>)</strong> |
            Review, evaluate and endorse departmental research submissions
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/hod/proposals.php") ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-collection me-1"></i> All Department Proposals
        </a>
        <a href="<?= url("/hod/projects.php") ?>" class="btn btn-primary btn-sm" style="background-color: #1a365d; border-color: #1a365d;">
            <i class="bi bi-kanban me-1"></i> Department Projects & Reports
        </a>
    </div>
</div>

<?php if ($pendingCount > 0): ?>
    <!-- Notice: Submissions Pending HOD Review -->
    <div class="alert alert-warning border-warning shadow-sm d-flex flex-wrap align-items-center justify-content-between py-2 px-3 mb-4 gap-2">
        <div class="d-flex align-items-center gap-3">
            <div class="p-2 rounded-circle bg-warning text-dark">
                <i class="bi bi-bell-fill fs-5"></i>
            </div>
            <div>
                <strong class="text-dark">
                    Action Required: <?= $pendingCount ?> Submission(s) Awaiting HOD Review & Endorsement
                </strong>
                <div class="small mt-1 text-muted d-flex flex-wrap gap-2 align-items-center">
                    <?php if ($pendingProgressCount > 0): ?>
                        <span class="badge bg-primary text-white"><i class="bi bi-arrow-repeat me-1"></i><?= $pendingProgressCount ?> Progress Report(s)</span>
                    <?php endif; ?>
                    <?php if ($pendingNewCount > 0): ?>
                        <span class="badge bg-success text-white"><i class="bi bi-file-earmark-plus me-1"></i><?= $pendingNewCount ?> New Project Proposal(s)</span>
                    <?php endif; ?>
                    <?php if ($pendingCompletedCount > 0): ?>
                        <span class="badge text-white" style="background-color: #6b21a8;"><i class="bi bi-check2-circle me-1"></i><?= $pendingCompletedCount ?> Completion Report(s)</span>
                    <?php endif; ?>
                    <span>Review and endorse to forward to Joint Director.</span>
                </div>
            </div>
        </div>
        <a href="#pending-submissions-table" class="btn btn-warning btn-sm fw-bold">
            <i class="bi bi-arrow-down-short me-1"></i> View Submissions Below
        </a>
    </div>
<?php endif; ?>

<!-- Department Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-warning"></div>
            <span class="text-muted small text-uppercase fw-semibold">Pending My Review</span>
            <div class="fs-3 fw-bold text-warning-emphasis mt-1"><?= $pendingCount ?></div>
            <small class="text-muted">
                <?php if ($pendingProgressCount > 0 && $pendingNewCount > 0): ?>
                    <?= $pendingProgressCount ?> Progress, <?= $pendingNewCount ?> New
                <?php elseif ($pendingProgressCount > 0): ?>
                    <?= $pendingProgressCount ?> Progress Report(s)
                <?php elseif ($pendingNewCount > 0): ?>
                    <?= $pendingNewCount ?> New Proposal(s)
                <?php else: ?>
                    Awaiting endorsement
                <?php endif; ?>
            </small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-info"></div>
            <span class="text-muted small text-uppercase fw-semibold">Forwarded to JD</span>
            <div class="fs-3 fw-bold text-info-emphasis mt-1"><?= $forwardedCount ?></div>
            <small class="text-muted">In JD / IRC review</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-danger"></div>
            <span class="text-muted small text-uppercase fw-semibold">Returned to Scientist</span>
            <div class="fs-3 fw-bold text-danger mt-1"><?= $returnedCount ?></div>
            <small class="text-muted">Under revision</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <a href="<?= url("/hod/projects.php") ?>" class="text-decoration-none d-block">
            <div class="stat-card p-3">
                <div class="stat-card-accent bg-success"></div>
                <span class="text-muted small text-uppercase fw-semibold">Approved Projects</span>
                <div class="fs-3 fw-bold text-success mt-1"><?= $approvedCount ?></div>
                <small class="text-success"><i class="bi bi-arrow-right-short me-1"></i>View Projects & Reports</small>
            </div>
        </a>
    </div>
</div>

<!-- Proposals Pending HOD Action -->
<div class="card shadow-sm border-0 mb-4" id="pending-submissions-table">
    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="m-0 fw-bold text-dark">
                <i class="bi bi-inbox-fill text-warning me-2"></i>Submissions Awaiting HOD Endorsement
                <span class="badge bg-warning text-dark ms-2"><?= count($pendingProposals) ?> Pending</span>
            </h6>
        </div>
        <div class="small text-muted">
            Click on review button to examine dossier and record endorsement decision
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 190px;">Submission Type & No.</th>
                    <th>Proposal / Project Details</th>
                    <th style="width: 170px;">Principal Investigator</th>
                    <th style="width: 140px;">Budget / Utilized</th>
                    <th style="width: 120px;">Submitted</th>
                    <th style="width: 170px;" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingProposals)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            <i class="bi bi-check-circle text-success fs-3 d-block mb-1"></i>
                            No proposals or progress reports currently pending review in your department. All up to date!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendingProposals as $prop): 
                        $cat = $prop['proposal_category'] ?? 'new';
                    ?>
                        <tr class="table-warning-subtle">
                            <td>
                                <div class="mb-1">
                                    <?= render_proposal_category_badge($cat) ?>
                                </div>
                                <div class="fw-semibold font-monospace small text-dark">
                                    <?= e($prop['proposal_number']) ?>
                                </div>
                                <?php if (!empty($prop['project_number'])): ?>
                                    <div class="text-primary font-monospace extra-small mt-1" title="Linked Project Code">
                                        <i class="bi bi-tag-fill me-1"></i><?= e($prop['project_number']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url("/hod/review-proposal.php?id=" . ($prop['id']) . "") ?>" class="text-decoration-none fw-semibold text-dark d-block">
                                    <?= e($prop['title']) ?>
                                </a>
                                <div class="d-flex flex-wrap gap-1 align-items-center mt-1">
                                    <?php if ($cat === 'ongoing' && !empty($prop['progress_report_period'])): ?>
                                        <span class="badge bg-info text-dark border border-info-subtle font-monospace" style="font-size: 0.72rem;">
                                            <i class="bi bi-calendar-check me-1"></i><?= e($prop['progress_report_period']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <small class="text-muted">TRL-<?= e($prop['trl_level']) ?> | <?= e($prop['institute_priority_area'] ?: 'General') ?></small>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark small"><?= e($prop['scientist_name']) ?></div>
                                <small class="text-muted"><?= e($prop['scientist_designation']) ?></small>
                            </td>
                            <td class="small">
                                <?php if ($cat === 'ongoing' || $cat === 'completed'): ?>
                                    <div class="fw-bold text-success"><?= format_currency((float)$prop['budget_utilized']) ?></div>
                                    <small class="text-muted">Utilized of <?= format_currency((float)($prop['budget_allocated'] ?: $prop['proposed_budget'])) ?></small>
                                <?php else: ?>
                                    <div class="fw-bold text-dark"><?= format_currency((float)$prop['proposed_budget']) ?></div>
                                    <small class="text-muted">Proposed Grant</small>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= format_date($prop['submitted_at']) ?></td>
                            <td class="text-end">
                                <?php if ($cat === 'ongoing'): ?>
                                    <a href="<?= url("/hod/review-proposal.php?id=" . ($prop['id']) . "") ?>" class="btn btn-sm btn-primary fw-semibold" style="background-color: #1a365d; border-color: #1a365d;">
                                        <i class="bi bi-arrow-repeat me-1"></i> Review Progress Report
                                    </a>
                                <?php elseif ($cat === 'completed'): ?>
                                    <a href="<?= url("/hod/review-proposal.php?id=" . ($prop['id']) . "") ?>" class="btn btn-sm text-white fw-semibold" style="background-color: #6b21a8; border-color: #6b21a8;">
                                        <i class="bi bi-check2-circle me-1"></i> Review Completion
                                    </a>
                                <?php else: ?>
                                    <a href="<?= url("/hod/review-proposal.php?id=" . ($prop['id']) . "") ?>" class="btn btn-sm btn-warning text-dark fw-semibold">
                                        <i class="bi bi-clipboard-check me-1"></i> Review & Endorse
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- All Recent Department Proposals -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-dark"><i class="bi bi-clock-history text-secondary me-2"></i>Recent Department Submissions</h6>
        <a href="<?= url("/hod/proposals.php") ?>" class="btn btn-sm btn-outline-secondary">View Department Archive</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 170px;">Submission Type & No.</th>
                    <th>Title</th>
                    <th>Lead Investigator</th>
                    <th>Status</th>
                    <th>Budget</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentProposals)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-3 text-muted">No department proposals found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentProposals as $prop): ?>
                        <tr>
                            <td>
                                <div class="mb-1">
                                    <?= render_proposal_category_badge($prop['proposal_category'] ?? 'new') ?>
                                </div>
                                <div class="font-monospace small fw-semibold text-dark">
                                    <?= e($prop['proposal_number']) ?>
                                </div>
                            </td>
                            <td>
                                <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="text-decoration-none fw-semibold text-dark">
                                    <?= e(mb_strimwidth($prop['title'], 0, 50, '...')) ?>
                                </a>
                                <?php if (!empty($prop['progress_report_period'])): ?>
                                    <div class="text-muted extra-small font-monospace"><?= e($prop['progress_report_period']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-dark"><?= e($prop['scientist_name']) ?></td>
                            <td><?= render_status_badge($prop['current_status']) ?></td>
                            <td class="small fw-semibold"><?= format_currency((float)$prop['proposed_budget']) ?></td>
                            <td class="text-end">
                                <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="btn btn-xs btn-outline-secondary">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
