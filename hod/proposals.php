<?php
/**
 * Research Proposal and Project Management System
 * HOD - Department Proposals Listing
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_HOD);

$pageTitle = 'Department Proposals - HOD';
$user = current_user();
$deptId = (int)($user['department_id'] ?? 1);
$db = get_db();

$search = sanitize($_GET['q'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$categoryFilter = sanitize($_GET['category'] ?? ($_GET['tab'] ?? 'all'));
if (!in_array($categoryFilter, ['all', 'new', 'ongoing', 'completed'])) {
    $categoryFilter = 'all';
}

$sql = "SELECT p.*, u.name as scientist_name, u.designation as scientist_designation
        FROM proposals p
        JOIN users u ON p.scientist_id = u.id
        WHERE p.department_id = ? AND p.current_status != 'Draft'";
$params = [$deptId];

// Active projects are tracked under Department Projects & Reports.
// Unless explicitly requested via status filter, exclude Approved / Project Active to avoid confusion.
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

$sql .= " ORDER BY CASE WHEN p.current_status = 'Submitted to HOD' THEN 1 ELSE 2 END, p.updated_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$proposals = $stmt->fetchAll();

// Counts for review tabs (excluding drafts and approved active projects)
$countBase = "FROM proposals WHERE department_id = {$deptId} AND current_status != 'Draft' AND current_status != 'Approved / Project Active'";
$countAll = (int)$db->query("SELECT COUNT(*) " . $countBase)->fetchColumn();
$countNew = (int)$db->query("SELECT COUNT(*) " . $countBase . " AND (proposal_category = 'new' OR proposal_category IS NULL)")->fetchColumn();
$countOngoing = (int)$db->query("SELECT COUNT(*) " . $countBase . " AND proposal_category = 'ongoing'")->fetchColumn();
$countCompleted = (int)$db->query("SELECT COUNT(*) " . $countBase . " AND proposal_category = 'completed'")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Review Department Proposals</h3>
        <p class="text-muted small mb-0">Evaluate new research proposals, ongoing progress reports, and completion project reports submitted by scientists</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/hod/projects.php") ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-kanban me-1"></i> Department Projects & Reports
        </a>
        <a href="<?= url("/hod/dashboard.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard
        </a>
    </div>
</div>

<!-- Nav Tabs: Filter by Submission Type -->
<ul class="nav nav-pills mb-4 p-1 bg-white border rounded shadow-xs" style="max-width: 820px;">
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'all' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/hod/proposals.php?category=all" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            All Submissions (<?= $countAll ?>)
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'new' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/hod/proposals.php?category=new" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            <i class="bi bi-file-earmark-plus me-1"></i> New Project Proposals (<?= $countNew ?>)
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'ongoing' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/hod/proposals.php?category=ongoing" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            <i class="bi bi-arrow-repeat me-1"></i> Progress Reports (<?= $countOngoing ?>)
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'completed' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/hod/proposals.php?category=completed" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            <i class="bi bi-check2-circle me-1"></i> Completion Reports (<?= $countCompleted ?>)
        </a>
    </li>
</ul>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= url("/hod/proposals.php") ?>" class="row g-2 align-items-center" id="hodFilterForm">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Search by title, proposal # or scientist..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="category" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <option value="new" <?= $categoryFilter === 'new' ? 'selected' : '' ?>>New Proposals</option>
                    <option value="ongoing" <?= $categoryFilter === 'ongoing' ? 'selected' : '' ?>>On Going Progress Reports</option>
                    <option value="completed" <?= $categoryFilter === 'completed' ? 'selected' : '' ?>>Completion Reports</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">-- All Statuses --</option>
                    <option value="<?= STATUS_SUBMITTED_HOD ?>" <?= $statusFilter === STATUS_SUBMITTED_HOD ? 'selected' : '' ?>>Submitted to HOD (Pending)</option>
                    <option value="<?= STATUS_RETURNED_HOD ?>" <?= $statusFilter === STATUS_RETURNED_HOD ? 'selected' : '' ?>>Returned by HOD</option>
                    <option value="<?= STATUS_FORWARDED_JD ?>" <?= $statusFilter === STATUS_FORWARDED_JD ? 'selected' : '' ?>>Forwarded to Joint Director</option>
                    <option value="<?= STATUS_APPROVED_IRC ?>" <?= $statusFilter === STATUS_APPROVED_IRC ? 'selected' : '' ?>>Approved for IRC Meeting</option>
                    <option value="<?= STATUS_APPROVED_ACTIVE ?>" <?= $statusFilter === STATUS_APPROVED_ACTIVE ? 'selected' : '' ?>>Approved / Active</option>
                    <option value="<?= STATUS_COMPLETED ?>" <?= $statusFilter === STATUS_COMPLETED ? 'selected' : '' ?>>Completed</option>
                    <option value="<?= STATUS_NOT_APPROVED_ARCHIVED ?>" <?= $statusFilter === STATUS_NOT_APPROVED_ARCHIVED ? 'selected' : '' ?>>Not Approved / Archived</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="<?= url("/hod/proposals.php" . ($categoryFilter !== 'all' ? "?category=" . urlencode($categoryFilter) : "")) ?>" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-circle"></i></a>
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
                    <th style="width: 180px;">Principal Investigator</th>
                    <th style="width: 140px;">Budget</th>
                    <th style="width: 190px;">Status</th>
                    <th style="width: 150px;" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($proposals)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            No departmental proposals match the criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($proposals as $prop): 
                        $cat = $prop['proposal_category'] ?? 'new';
                    ?>
                        <tr class="<?= $prop['current_status'] === STATUS_SUBMITTED_HOD ? 'table-warning-subtle' : '' ?>">
                            <td>
                                <div class="mb-1">
                                    <?= render_proposal_category_badge($cat) ?>
                                </div>
                                <div class="fw-semibold font-monospace small">
                                    <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="text-decoration-none">
                                        <?= e($prop['proposal_number']) ?>
                                    </a>
                                </div>
                                <?php if (!empty($prop['project_number'])): ?>
                                    <div class="badge bg-dark-subtle text-dark border border-secondary-subtle mt-1 font-monospace" style="font-size: 0.68rem;">
                                        <i class="bi bi-tag me-1"></i><?= e($prop['project_number']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="text-decoration-none fw-semibold text-dark d-block">
                                    <?= e($prop['title']) ?>
                                </a>
                                <div class="d-flex flex-wrap gap-1 align-items-center mt-1">
                                    <?php if ($cat === 'ongoing' && !empty($prop['progress_report_period'])): ?>
                                        <span class="badge bg-info text-dark border border-info-subtle font-monospace" style="font-size: 0.72rem;">
                                            <i class="bi bi-calendar-check me-1"></i><?= e($prop['progress_report_period']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <small class="text-muted">
                                        TRL-<?= e($prop['trl_level']) ?> | <?= e(get_priority_area_title($prop['institute_priority_area'] ?? '')) ?>
                                    </small>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark small"><?= e($prop['scientist_name']) ?></div>
                                <small class="text-muted"><?= e($prop['scientist_designation']) ?></small>
                            </td>
                            <td class="small">
                                <?php if (($prop['proposal_category'] ?? '') === 'completed' || ($prop['proposal_category'] ?? '') === 'ongoing'): ?>
                                    <div class="fw-bold text-success"><?= format_currency((float)$prop['budget_utilized']) ?></div>
                                    <small class="text-muted">Alloc: <?= format_currency((float)($prop['budget_allocated'] ?: $prop['proposed_budget'])) ?></small>
                                <?php else: ?>
                                    <div class="fw-bold text-dark"><?= format_currency((float)$prop['proposed_budget']) ?></div>
                                    <small class="text-muted">Proposed</small>
                                <?php endif; ?>
                            </td>
                            <td><?= render_status_badge($prop['current_status']) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($prop['current_status'] === STATUS_SUBMITTED_HOD): ?>
                                        <a href="<?= url("/hod/review-proposal.php?id=" . ($prop['id']) . "") ?>" class="btn btn-warning fw-semibold" title="Review Submission">
                                            <i class="bi bi-clipboard-check"></i> Review
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="btn btn-outline-secondary" title="View Full Details">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= url("/export-doc.php?id=" . ($prop['id']) . "") ?>" class="btn btn-outline-secondary" title="Word Export (.docx)">
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
