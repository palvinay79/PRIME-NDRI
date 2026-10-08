<?php
/**
 * Research Proposal and Project Management System
 * Joint Director (JD) - Executive Research Directorate Dashboard
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_JOINT_DIRECTOR);

$pageTitle = 'Joint Director Dashboard';
$db = get_db();

// Global Metrics
$metrics = [
    'pending_jd' => 0,
    'approved_irc' => 0,
    'active_projects' => 0,
    'total_proposals' => 0,
    'total_budget_sanctioned' => 0.0
];

$stmt = $db->query("SELECT current_status, COUNT(*) as cnt FROM proposals GROUP BY current_status");
$statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$metrics['pending_jd'] = $statusCounts[STATUS_FORWARDED_JD] ?? 0;
$metrics['approved_irc'] = $statusCounts[STATUS_APPROVED_IRC] ?? 0;
$metrics['total_proposals'] = array_sum($statusCounts);

$stmt = $db->query("SELECT COUNT(*) as active_cnt, COALESCE(SUM(approved_budget), 0) as total_sanctioned FROM projects WHERE project_status = 'Active'");
$projData = $stmt->fetch();
$metrics['active_projects'] = (int)($projData['active_cnt'] ?? 0);
$metrics['total_budget_sanctioned'] = (float)($projData['total_sanctioned'] ?? 0.0);

// Admin & Personnel Metrics
$adminMetrics = [
    'total_users' => 0,
    'scientists' => 0,
    'hods' => 0,
    'pending_approvals' => 0,
    'departments' => (int)$db->query("SELECT COUNT(*) FROM departments")->fetchColumn()
];
$stmt = $db->query("SELECT role_id, COUNT(*) as cnt FROM users WHERE status = 'active' GROUP BY role_id");
foreach ($stmt->fetchAll() as $r) {
    $adminMetrics['total_users'] += (int)$r['cnt'];
    if ((int)$r['role_id'] === ROLE_SCIENTIST) $adminMetrics['scientists'] = (int)$r['cnt'];
    if ((int)$r['role_id'] === ROLE_HOD) $adminMetrics['hods'] = (int)$r['cnt'];
}
$adminMetrics['pending_approvals'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();

// Proposals Pending Initial JD Review
$stmt = $db->prepare("SELECT p.*, u.name as scientist_name, d.department_name, d.department_code
                      FROM proposals p
                      JOIN users u ON p.scientist_id = u.id
                      JOIN departments d ON p.department_id = d.id
                      WHERE p.current_status = ?
                      ORDER BY p.updated_at ASC");
$stmt->execute([STATUS_FORWARDED_JD]);
$pendingJdProposals = $stmt->fetchAll();

// Attach HOD approver info to each pending proposal
$approvingHods = [];
foreach ($pendingJdProposals as &$p) {
    $hInfo = get_hod_approver_info((int)$p['id'], (int)$p['department_id']);
    $p['hod_approver_name'] = $hInfo['name'];
    $p['hod_approver_designation'] = $hInfo['designation'];
    $p['hod_approved_at'] = $hInfo['approved_at'];
    $p['hod_comments'] = $hInfo['comments'];
    if (!empty($hInfo['name']) && !in_array($hInfo['name'], $approvingHods)) {
        $approvingHods[] = $hInfo['name'];
    }
}
unset($p);

// Breakdown of pending JD queue by category
$stmtJdCat = $db->prepare("
    SELECT COALESCE(proposal_category, 'new') as cat, COUNT(*) as cnt
    FROM proposals
    WHERE current_status = ?
    GROUP BY COALESCE(proposal_category, 'new')
");
$stmtJdCat->execute([STATUS_FORWARDED_JD]);
$jdPendingByCat = $stmtJdCat->fetchAll(PDO::FETCH_KEY_PAIR);
$jdPendingProgress = $jdPendingByCat['ongoing'] ?? 0;
$jdPendingNew = $jdPendingByCat['new'] ?? 0;
$jdPendingCompleted = $jdPendingByCat['completed'] ?? 0;

// Proposals Slated for Final IRC Meeting Decision
$stmt = $db->prepare("SELECT p.*, u.name as scientist_name, d.department_name, d.department_code
                      FROM proposals p
                      JOIN users u ON p.scientist_id = u.id
                      JOIN departments d ON p.department_id = d.id
                      WHERE p.current_status = ?
                      ORDER BY p.updated_at ASC");
$stmt->execute([STATUS_APPROVED_IRC]);
$ircProposals = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Research Directorate Executive Dashboard</h3>
        <p class="text-muted small mb-0">Joint Director (Research) Oversight, Institute Research Council (IRC) Gatekeeping & Project Governance</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/joint-director/users.php") ?>" class="btn btn-outline-dark btn-sm">
            <i class="bi bi-people-fill me-1"></i> User Management
            <?php if ($adminMetrics['pending_approvals'] > 0): ?>
                <span class="badge bg-danger ms-1" title="<?= $adminMetrics['pending_approvals'] ?> pending registrations"><?= $adminMetrics['pending_approvals'] ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= url("/joint-director/departments.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-buildings me-1"></i> Divisions
        </a>
        <a href="<?= url("/joint-director/analytics.php") ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-graph-up me-1"></i> Research Analytics
        </a>
        <a href="<?= url("/joint-director/proposals.php") ?>" class="btn btn-primary btn-sm" style="background-color: #1a365d; border-color: #1a365d;">
            <i class="bi bi-folder2-open me-1"></i> All Proposals
        </a>
    </div>
</div>

<?php if ($metrics['pending_jd'] > 0): ?>
    <!-- Notice: Submissions Pending JD Screening with HOD Name Visibility -->
    <div class="alert alert-warning border-warning shadow-sm py-3 px-3 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <div class="d-flex align-items-center gap-3">
                <div class="p-2 rounded-circle bg-warning text-dark">
                    <i class="bi bi-bell-fill fs-5"></i>
                </div>
                <div>
                    <h6 class="text-dark fw-bold mb-0">
                        Action Required: <?= $metrics['pending_jd'] ?> Submission<?= $metrics['pending_jd'] > 1 ? 's' : '' ?> Awaiting Joint Director Screening
                    </h6>
                    <div class="small text-dark mt-1">
                        <i class="bi bi-person-check-fill text-success me-1"></i>Endorsed / Submitted by Head of Department: 
                        <span class="badge bg-dark text-white px-2 py-1 fs-6 fw-bold"><?= !empty($approvingHods) ? e(implode(' &bull; ', $approvingHods)) : 'Head of Department' ?></span>
                    </div>
                </div>
            </div>
            <a href="#jd-review-queue-table" class="btn btn-warning btn-sm fw-bold">
                <i class="bi bi-arrow-down-short me-1"></i> View Review Queue Below
            </a>
        </div>

        <div class="small mt-1 text-muted d-flex flex-wrap gap-2 align-items-center mb-2">
            <?php if ($jdPendingProgress > 0): ?>
                <span class="badge bg-primary text-white"><i class="bi bi-arrow-repeat me-1"></i><?= $jdPendingProgress ?> Progress Report(s)</span>
            <?php endif; ?>
            <?php if ($jdPendingNew > 0): ?>
                <span class="badge bg-success text-white"><i class="bi bi-file-earmark-plus me-1"></i><?= $jdPendingNew ?> New Project Proposal(s)</span>
            <?php endif; ?>
            <?php if ($jdPendingCompleted > 0): ?>
                <span class="badge text-white" style="background-color: #6b21a8;"><i class="bi bi-check2-circle me-1"></i><?= $jdPendingCompleted ?> Completion Report(s)</span>
            <?php endif; ?>
            <span>Examine dossiers below to screen or slate for Institute Research Council (IRC) presentation.</span>
        </div>
        
        <!-- Detailed listing of each submission with approving HOD Name -->
        <div class="bg-white rounded border border-warning-subtle p-2 mt-2">
            <div class="small fw-semibold text-secondary mb-1 px-2 pt-1 text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                <i class="bi bi-check2-square text-success me-1"></i> Submissions Approved by HOD & Forwarded to Directorate:
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($pendingJdProposals as $p): 
                    $cat = $p['proposal_category'] ?? 'new';
                ?>
                    <div class="list-group-item px-2 py-2 d-flex flex-wrap justify-content-between align-items-center gap-2 border-0 border-bottom border-light">
                        <div class="d-flex align-items-center gap-2 flex-grow-1">
                            <?= render_proposal_category_badge($cat) ?>
                            <span class="font-monospace fw-semibold small text-primary"><?= e($p['proposal_number']) ?></span>
                            <span class="text-dark fw-semibold small text-truncate" style="max-width: 380px;">&ldquo;<?= e($p['title']) ?>&rdquo;</span>
                            <span class="badge bg-light text-secondary border extra-small"><?= e($p['scientist_name']) ?></span>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <?php if ($p['hod_approver_name'] === $p['scientist_name']): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace py-1 px-2" style="font-size: 0.75rem;">
                                    <i class="bi bi-send-check text-primary me-1"></i>Direct Submission by HOD: <strong><?= e($p['hod_approver_name']) ?></strong> (<?= e($p['department_code']) ?>)
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle font-monospace py-1 px-2" style="font-size: 0.75rem;">
                                    <i class="bi bi-person-check-fill text-success me-1"></i>Approved by HOD: <strong><?= e($p['hod_approver_name']) ?></strong> (<?= e($p['department_code']) ?>)
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($p['hod_approved_at'])): ?>
                                <small class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?= format_date($p['hod_approved_at'], 'd M Y') ?></small>
                            <?php endif; ?>
                            <a href="<?= url("/joint-director/review-proposal.php?id=" . $p['id']) ?>" class="btn btn-xs btn-primary fw-semibold" style="background-color: #1a365d; border-color: #1a365d; font-size: 0.75rem; padding: 3px 10px;">
                                Review & Screen &rarr;
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($adminMetrics['pending_approvals'] > 0): ?>
<!-- High Priority Pending Registration Alert -->
<div class="alert alert-warning border-warning shadow-sm d-flex flex-wrap align-items-center justify-content-between py-2 px-3 mb-4 gap-2">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-person-fill-exclamation fs-4 text-warning-emphasis"></i>
        <div>
            <strong class="text-dark"><?= $adminMetrics['pending_approvals'] ?> New Scientist / HOD Registration Request(s) Awaiting Approval</strong>
            <div class="small text-muted">Applicants have submitted their credentials and department affiliations; approve them to grant portal login.</div>
        </div>
    </div>
    <a href="<?= url('/joint-director/users.php?status=pending') ?>" class="btn btn-warning btn-sm fw-bold">
        <i class="bi bi-shield-check me-1"></i> Review & Approve Applicants
    </a>
</div>
<?php endif; ?>

<!-- Admin & Personnel Overview Banner -->
<div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);">
    <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center">
                <div class="p-2 rounded-3 bg-primary text-white me-2">
                    <i class="bi bi-shield-lock-fill fs-5"></i>
                </div>
                <div>
                    <div class="fw-bold text-dark small text-uppercase">Institute Administration</div>
                    <div class="small text-muted">Full administrative control over users, roles & divisions</div>
                </div>
            </div>
            <div class="vr d-none d-md-block" style="height: 30px;"></div>
            <div class="d-flex gap-3 small">
                <div>
                    <span class="text-muted">Total Users:</span>
                    <strong class="text-dark ms-1"><?= $adminMetrics['total_users'] ?></strong>
                </div>
                <div>
                    <span class="text-muted">Scientists:</span>
                    <strong class="text-primary ms-1"><?= $adminMetrics['scientists'] ?></strong>
                </div>
                <div>
                    <span class="text-muted">HODs:</span>
                    <strong class="text-success ms-1"><?= $adminMetrics['hods'] ?></strong>
                </div>
                <?php if ($adminMetrics['pending_approvals'] > 0): ?>
                <div>
                    <span class="text-muted">Pending:</span>
                    <strong class="text-danger ms-1"><?= $adminMetrics['pending_approvals'] ?></strong>
                </div>
                <?php endif; ?>
                <div>
                    <span class="text-muted">Divisions:</span>
                    <strong class="text-secondary ms-1"><?= $adminMetrics['departments'] ?></strong>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url("/joint-director/users.php") ?>" class="btn btn-sm btn-primary" style="background-color: #1a365d; border-color: #1a365d;">
                <i class="bi bi-person-plus-fill me-1"></i> Create / Manage Users
            </a>
            <a href="<?= url("/joint-director/departments.php") ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus-circle me-1"></i> Manage Divisions
            </a>
        </div>
    </div>
</div>

<!-- Executive Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-warning"></div>
            <span class="text-muted small text-uppercase fw-semibold">JD Review Queue</span>
            <div class="fs-3 fw-bold text-warning-emphasis mt-1"><?= $metrics['pending_jd'] ?></div>
            <small class="text-muted">
                <?php if ($jdPendingProgress > 0 && $jdPendingNew > 0): ?>
                    <?= $jdPendingProgress ?> Progress, <?= $jdPendingNew ?> New
                <?php elseif ($jdPendingProgress > 0): ?>
                    <?= $jdPendingProgress ?> Progress Report(s)
                <?php elseif ($jdPendingNew > 0): ?>
                    <?= $jdPendingNew ?> New Proposal(s)
                <?php else: ?>
                    Awaiting screening
                <?php endif; ?>
            </small>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-primary"></div>
            <span class="text-muted small text-uppercase fw-semibold">Slated for IRC</span>
            <div class="fs-3 fw-bold text-primary mt-1"><?= $metrics['approved_irc'] ?></div>
            <small class="text-muted">Council presentation</small>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-success"></div>
            <span class="text-muted small text-uppercase fw-semibold">Active Research Projects</span>
            <div class="fs-3 fw-bold text-success mt-1"><?= $metrics['active_projects'] ?></div>
            <small class="text-muted">Across all departments</small>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-dark"></div>
            <span class="text-muted small text-uppercase fw-semibold">Total Grants Sanctioned</span>
            <div class="fs-4 fw-bold text-dark mt-1"><?= format_currency($metrics['total_budget_sanctioned']) ?></div>
            <small class="text-muted">Active project grants</small>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-secondary"></div>
            <span class="text-muted small text-uppercase fw-semibold">Total Submissions</span>
            <div class="fs-3 fw-bold text-secondary mt-1"><?= $metrics['total_proposals'] ?></div>
            <small class="text-muted">Lifecycle proposals</small>
        </div>
    </div>
</div>

<!-- Section 1: Proposals Awaiting JD Initial Review -->
<div class="card shadow-sm border-0 mb-4" id="jd-review-queue-table">
    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-hourglass-split text-warning me-2"></i>Stage 1: Proposals Awaiting Initial JD Review (Forwarded by HOD)
            <span class="badge bg-warning text-dark ms-2"><?= count($pendingJdProposals) ?></span>
        </h6>
        <div class="small text-muted">
            Endorsed by Department Heads; screen dossiers before IRC presentation
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 190px;">Submission Type & No.</th>
                    <th>Title & Alignment</th>
                    <th style="width: 170px;">PI & Division</th>
                    <th style="width: 140px;">Budget / Utilized</th>
                    <th style="width: 110px;">TRL Level</th>
                    <th style="width: 170px;" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingJdProposals)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            <i class="bi bi-check-circle text-success fs-3 d-block mb-1"></i>
                            No proposals or progress reports pending initial Joint Director review.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendingJdProposals as $p): 
                        $cat = $p['proposal_category'] ?? 'new';
                    ?>
                        <tr class="table-warning-subtle">
                            <td>
                                <div class="mb-1">
                                    <?= render_proposal_category_badge($cat) ?>
                                </div>
                                <div class="font-monospace fw-semibold small text-dark">
                                    <?= e($p['proposal_number']) ?>
                                </div>
                                <?php if (!empty($p['project_number'])): ?>
                                    <div class="text-primary font-monospace extra-small mt-1" title="Linked Project Code">
                                        <i class="bi bi-tag-fill me-1"></i><?= e($p['project_number']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url("/joint-director/review-proposal.php?id=" . ($p['id']) . "") ?>" class="text-decoration-none fw-semibold text-dark d-block">
                                    <?= e($p['title']) ?>
                                </a>
                                <div class="d-flex flex-wrap gap-1 align-items-center mt-1">
                                    <?php if ($cat === 'ongoing' && !empty($p['progress_report_period'])): ?>
                                        <span class="badge bg-info text-dark border border-info-subtle font-monospace" style="font-size: 0.72rem;">
                                            <i class="bi bi-calendar-check me-1"></i><?= e($p['progress_report_period']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <small class="text-muted"><?= e($p['institute_priority_area'] ?: 'Dairy Innovation') ?></small>
                                </div>
                            </td>
                            <td>
                                <strong class="small text-dark d-block"><i class="bi bi-person me-1"></i><?= e($p['scientist_name']) ?></strong>
                                <small class="text-muted d-block"><?= e($p['department_code']) ?> &bull; <?= e($p['department_name']) ?></small>
                                <div class="mt-1">
                                    <?php if ($p['hod_approver_name'] === $p['scientist_name']): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size: 0.68rem;">
                                            <i class="bi bi-send-check me-1"></i>Direct HOD Submission
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle font-monospace" style="font-size: 0.68rem;">
                                            <i class="bi bi-person-check-fill me-1"></i>HOD: <?= e($p['hod_approver_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="small">
                                <?php if ($cat === 'ongoing' || $cat === 'completed'): ?>
                                    <div class="fw-bold text-success"><?= format_currency((float)$p['budget_utilized']) ?></div>
                                    <small class="text-muted">Utilized of <?= format_currency((float)($p['budget_allocated'] ?: $p['proposed_budget'])) ?></small>
                                <?php else: ?>
                                    <div class="fw-bold text-dark"><?= format_currency((float)$p['proposed_budget']) ?></div>
                                    <small class="text-muted">Proposed Grant</small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border">TRL-<?= e($p['trl_level']) ?></span></td>
                            <td class="text-end">
                                <?php if ($cat === 'ongoing'): ?>
                                    <a href="<?= url("/joint-director/review-proposal.php?id=" . ($p['id']) . "") ?>" class="btn btn-sm btn-primary fw-semibold" style="background-color: #1a365d; border-color: #1a365d;">
                                        <i class="bi bi-arrow-repeat me-1"></i> Review Progress
                                    </a>
                                <?php elseif ($cat === 'completed'): ?>
                                    <a href="<?= url("/joint-director/review-proposal.php?id=" . ($p['id']) . "") ?>" class="btn btn-sm text-white fw-semibold" style="background-color: #6b21a8; border-color: #6b21a8;">
                                        <i class="bi bi-check2-circle me-1"></i> Review Completion
                                    </a>
                                <?php else: ?>
                                    <a href="<?= url("/joint-director/review-proposal.php?id=" . ($p['id']) . "") ?>" class="btn btn-sm btn-primary fw-semibold" style="background-color: #1a365d; border-color: #1a365d;">
                                        <i class="bi bi-clipboard-check me-1"></i> Review & Screen
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

<!-- Section 2: Proposals Slated for Final IRC Council Decision -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-dark">
            <i class="bi bi-award-fill text-primary me-2"></i>Stage 2: Approved for IRC Meeting - Final Council Decision Required
            <span class="badge bg-primary text-white ms-2"><?= count($ircProposals) ?></span>
        </h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 190px;">Submission Type & No.</th>
                    <th>Title & Alignment</th>
                    <th style="width: 170px;">PI & Division</th>
                    <th style="width: 130px;">Budget</th>
                    <th style="width: 140px;">Current Stage</th>
                    <th style="width: 180px;" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ircProposals)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            No proposals currently pending final IRC decision.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ircProposals as $p): 
                        $cat = $p['proposal_category'] ?? 'new';
                    ?>
                        <tr class="table-primary-subtle">
                            <td>
                                <div class="mb-1">
                                    <?= render_proposal_category_badge($cat) ?>
                                </div>
                                <div class="font-monospace fw-semibold small text-dark">
                                    <?= e($p['proposal_number']) ?>
                                </div>
                                <?php if (!empty($p['project_number'])): ?>
                                    <div class="text-primary font-monospace extra-small mt-1">
                                        <i class="bi bi-tag-fill me-1"></i><?= e($p['project_number']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url("/joint-director/irc-decision.php?id=" . ($p['id']) . "") ?>" class="text-decoration-none fw-semibold text-dark d-block">
                                    <?= e($p['title']) ?>
                                </a>
                                <div class="d-flex flex-wrap gap-1 align-items-center mt-1">
                                    <?php if ($cat === 'ongoing' && !empty($p['progress_report_period'])): ?>
                                        <span class="badge bg-info text-dark border border-info-subtle font-monospace" style="font-size: 0.72rem;">
                                            <i class="bi bi-calendar-check me-1"></i><?= e($p['progress_report_period']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <small class="text-muted"><?= e($p['institute_priority_area'] ?: 'General') ?></small>
                                </div>
                            </td>
                            <td>
                                <strong class="small text-dark d-block"><?= e($p['scientist_name']) ?></strong>
                                <small class="text-muted"><?= e($p['department_name']) ?></small>
                            </td>
                            <td class="small fw-semibold">
                                <?php if ($cat === 'ongoing' || $cat === 'completed'): ?>
                                    <div class="fw-bold text-success"><?= format_currency((float)$p['budget_utilized']) ?></div>
                                    <small class="text-muted">Utilized</small>
                                <?php else: ?>
                                    <?= format_currency((float)$p['proposed_budget']) ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-primary">Approved for IRC</span></td>
                            <td class="text-end">
                                <a href="<?= url("/joint-director/irc-decision.php?id=" . ($p['id']) . "") ?>" class="btn btn-sm btn-success fw-semibold">
                                    <i class="bi bi-check2-all me-1"></i> Record IRC Decision
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
