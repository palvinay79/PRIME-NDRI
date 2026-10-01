<?php
/**
 * Research Proposal and Project Management System
 * Joint Director - Master Proposals Directory
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_JOINT_DIRECTOR);

$pageTitle = 'Master Proposals Directory - JD';
$db = get_db();

$search = sanitize($_GET['q'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$deptFilter = (int)($_GET['dept'] ?? 0);
$categoryFilter = sanitize($_GET['category'] ?? 'all');
if (!in_array($categoryFilter, ['all', 'new', 'ongoing', 'completed'])) {
    $categoryFilter = 'all';
}

$sql = "SELECT p.*, u.name as scientist_name, d.department_name, d.department_code
        FROM proposals p
        JOIN users u ON p.scientist_id = u.id
        JOIN departments d ON p.department_id = d.id
        WHERE p.current_status != 'Draft'";
$params = [];

// Approved active projects belong to the Active Projects registry.
// Proposals Review strictly focuses on items currently under review or slated for council decision.
if (empty($statusFilter)) {
    $sql .= " AND p.current_status != 'Approved / Project Active'";
} else {
    $sql .= " AND p.current_status = ?";
    $params[] = $statusFilter;
}

if ($categoryFilter === 'new') {
    $sql .= " AND (p.proposal_category = 'new' OR p.proposal_category IS NULL)";
} elseif ($categoryFilter === 'ongoing') {
    $sql .= " AND p.proposal_category = 'ongoing'";
} elseif ($categoryFilter === 'completed') {
    $sql .= " AND p.proposal_category = 'completed'";
}

if (!empty($search)) {
    $sql .= " AND (p.title LIKE ? OR p.proposal_number LIKE ? OR u.name LIKE ? OR p.project_number LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($deptFilter > 0) {
    $sql .= " AND p.department_id = ?";
    $params[] = $deptFilter;
}

$sql .= " ORDER BY CASE WHEN p.current_status = 'Forwarded to Joint Director' THEN 1 WHEN p.current_status = 'Approved for IRC Meeting' THEN 2 ELSE 3 END, p.updated_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$proposals = $stmt->fetchAll();

// Counts for review queue tabs (strictly excluding drafts and already-approved active projects)
$countBase = "FROM proposals WHERE current_status != 'Draft' AND current_status != 'Approved / Project Active'";
$countAll = (int)$db->query("SELECT COUNT(*) " . $countBase)->fetchColumn();
$countNew = (int)$db->query("SELECT COUNT(*) " . $countBase . " AND (proposal_category = 'new' OR proposal_category IS NULL)")->fetchColumn();
$countOngoing = (int)$db->query("SELECT COUNT(*) " . $countBase . " AND proposal_category = 'ongoing'")->fetchColumn();
$countCompleted = (int)$db->query("SELECT COUNT(*) " . $countBase . " AND proposal_category = 'completed'")->fetchColumn();

// All departments for dropdown
$departments = $db->query("SELECT * FROM departments ORDER BY department_name ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Research Proposals & Reports Review Queue</h3>
        <p class="text-muted small mb-0">Directorate screening and review portal for New Project Proposals, Biannual Progress Reports, and Completion Reports</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('/joint-director/irc-meetings.php') ?>" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-calendar-event me-1"></i> IRC Meetings & Decisions
        </a>
        <a href="<?= url('/joint-director/projects.php') ?>" class="btn btn-success btn-sm fw-semibold">
            <i class="bi bi-kanban me-1"></i> Active Projects (<?= (int)$db->query("SELECT COUNT(*) FROM projects WHERE project_status = 'Active'")->fetchColumn() ?>)
        </a>
    </div>
</div>

<!-- Scope Clarification Banner -->
<div class="alert alert-light border d-flex flex-wrap align-items-center justify-content-between p-3 mb-4 rounded shadow-xs gap-2">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill text-primary fs-5"></i>
        <div>
            <span class="fw-semibold text-dark">Active Review Pipeline:</span>
            <span class="text-muted small">This queue strictly displays submissions awaiting screening, review, or IRC Council deliberation. Approved, ongoing research projects are tracked under <strong class="text-dark">Active Projects</strong>.</span>
        </div>
    </div>
    <a href="<?= url('/joint-director/projects.php') ?>" class="btn btn-outline-success btn-sm fw-semibold text-nowrap">
        <i class="bi bi-kanban me-1"></i> Open Active Projects
    </a>
</div>

<!-- Proposal Category Tabs -->
<ul class="nav nav-pills mb-4 p-1 bg-white border rounded shadow-xs" style="max-width: 820px;">
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'all' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/joint-director/proposals.php?category=all" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . ($deptFilter > 0 ? "&dept=" . $deptFilter : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            All Submissions (<?= $countAll ?>)
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'new' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/joint-director/proposals.php?category=new" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . ($deptFilter > 0 ? "&dept=" . $deptFilter : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            <i class="bi bi-file-earmark-plus me-1"></i> New Project Proposals (<?= $countNew ?>)
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'ongoing' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/joint-director/proposals.php?category=ongoing" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . ($deptFilter > 0 ? "&dept=" . $deptFilter : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            <i class="bi bi-arrow-repeat me-1"></i> Progress Reports (<?= $countOngoing ?>)
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'completed' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/joint-director/proposals.php?category=completed" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . ($deptFilter > 0 ? "&dept=" . $deptFilter : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            <i class="bi bi-check2-circle me-1"></i> Completion Reports (<?= $countCompleted ?>)
        </a>
    </li>
</ul>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= url("/joint-director/proposals.php") ?>" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Search by title, number or PI..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">-- All Active Review Statuses --</option>
                    <option value="<?= STATUS_FORWARDED_JD ?>" <?= $statusFilter === STATUS_FORWARDED_JD ? 'selected' : '' ?>>Forwarded to Joint Director (Pending Review)</option>
                    <option value="<?= STATUS_SUBMITTED_HOD ?>" <?= $statusFilter === STATUS_SUBMITTED_HOD ? 'selected' : '' ?>>Submitted to HOD (Awaiting HOD)</option>
                    <option value="<?= STATUS_APPROVED_IRC ?>" <?= $statusFilter === STATUS_APPROVED_IRC ? 'selected' : '' ?>>Approved for IRC Meeting</option>
                    <option value="<?= STATUS_RETURNED_JD ?>" <?= $statusFilter === STATUS_RETURNED_JD ? 'selected' : '' ?>>Returned by Joint Director</option>
                    <option value="<?= STATUS_RETURNED_HOD ?>" <?= $statusFilter === STATUS_RETURNED_HOD ? 'selected' : '' ?>>Returned by HOD</option>
                    <option value="<?= STATUS_NOT_APPROVED_ARCHIVED ?>" <?= $statusFilter === STATUS_NOT_APPROVED_ARCHIVED ? 'selected' : '' ?>>Not Approved / Archived</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="dept" class="form-select">
                    <option value="0">-- All Departments --</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= e($d['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="<?= url("/joint-director/proposals.php") ?>" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 190px;">Submission Type & No.</th>
                    <th>Proposal Title / Details</th>
                    <th style="width: 170px;">Principal Investigator</th>
                    <th style="width: 110px;">Division</th>
                    <th style="width: 140px;">Budget / Utilized</th>
                    <th style="width: 180px;">Workflow Status</th>
                    <th style="width: 160px;" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($proposals)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-folder-x fs-2 d-block mb-2"></i>
                            No proposals match the current query and filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($proposals as $p): 
                        $cat = $p['proposal_category'] ?? 'new';
                    ?>
                        <tr>
                            <td>
                                <div class="mb-1">
                                    <?= render_proposal_category_badge($cat) ?>
                                </div>
                                <div class="font-monospace fw-semibold small">
                                    <a href="<?= url("/scientist/proposal-details.php?id=" . ($p['id']) . "") ?>" class="text-decoration-none">
                                        <?= e($p['proposal_number']) ?>
                                    </a>
                                </div>
                                <?php if (!empty($p['project_number'])): ?>
                                    <div class="badge bg-dark-subtle text-dark border border-secondary-subtle mt-1 font-monospace" style="font-size: 0.68rem;">
                                        <i class="bi bi-tag me-1"></i><?= e($p['project_number']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url("/scientist/proposal-details.php?id=" . ($p['id']) . "") ?>" class="text-decoration-none fw-semibold text-dark d-block">
                                    <?= e($p['title']) ?>
                                </a>
                                <div class="d-flex flex-wrap gap-1 align-items-center mt-1">
                                    <?php if ($cat === 'ongoing' && !empty($p['progress_report_period'])): ?>
                                        <span class="badge bg-info text-dark border border-info-subtle font-monospace" style="font-size: 0.72rem;">
                                            <i class="bi bi-calendar-check me-1"></i><?= e($p['progress_report_period']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <small class="text-muted">TRL-<?= e($p['trl_level']) ?> | <?= e(get_priority_area_title($p['institute_priority_area'] ?? '')) ?></small>
                                </div>
                            </td>
                            <td class="small text-dark"><?= e($p['scientist_name']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($p['department_code']) ?></span></td>
                            <td class="small">
                                <?php if ($cat === 'completed' || $cat === 'ongoing'): ?>
                                    <div class="fw-bold text-success"><?= format_currency((float)$p['budget_utilized']) ?></div>
                                    <small class="text-muted">Alloc: <?= format_currency((float)($p['budget_allocated'] ?: $p['proposed_budget'])) ?></small>
                                <?php else: ?>
                                    <div class="fw-bold text-dark"><?= format_currency((float)$p['proposed_budget']) ?></div>
                                    <small class="text-muted">Proposed</small>
                                <?php endif; ?>
                            </td>
                            <td><?= render_status_badge($p['current_status']) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($p['current_status'] === STATUS_FORWARDED_JD): ?>
                                        <?php if ($cat === 'ongoing'): ?>
                                            <a href="<?= url("/joint-director/review-proposal.php?id=" . ($p['id']) . "") ?>" class="btn btn-warning fw-semibold" title="Review Progress Report">
                                                <i class="bi bi-arrow-repeat"></i> Review
                                            </a>
                                        <?php elseif ($cat === 'completed'): ?>
                                            <a href="<?= url("/joint-director/review-proposal.php?id=" . ($p['id']) . "") ?>" class="btn btn-warning fw-semibold" title="Review Completion Report">
                                                <i class="bi bi-check2-circle"></i> Review
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= url("/joint-director/review-proposal.php?id=" . ($p['id']) . "") ?>" class="btn btn-warning fw-semibold" title="Screen & Review">
                                                <i class="bi bi-clipboard-check"></i> Review
                                            </a>
                                        <?php endif; ?>
                                    <?php elseif ($p['current_status'] === STATUS_APPROVED_IRC): ?>
                                        <a href="<?= url("/joint-director/irc-decision.php?id=" . ($p['id']) . "") ?>" class="btn btn-primary fw-semibold" title="Record Final IRC Council Decision">
                                            <i class="bi bi-award me-1"></i> IRC Decision
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= url("/scientist/proposal-details.php?id=" . ($p['id']) . "") ?>" class="btn btn-outline-secondary" title="View Details">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= url("/export-doc.php?id=" . ($p['id']) . "") ?>" class="btn btn-outline-secondary" title="Word Export (.docx)">
                                        <i class="bi bi-file-earmark-word"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
