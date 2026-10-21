<?php
/**
 * Research Proposal and Project Management System
 * Scientist - My Proposals List
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD]);

$currentUser = current_user();
$userId = current_user_id();
$isHod = (current_user_role_id() === ROLE_HOD);
$pageTitle = $isHod ? 'My Submissions' : 'My Proposals';
$db = get_db();

$search = sanitize($_GET['q'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$categoryFilter = sanitize($_GET['category'] ?? ($_GET['tab'] ?? 'all'));
if (!in_array($categoryFilter, ['all', 'new', 'ongoing', 'completed'])) {
    $categoryFilter = 'all';
}

$userEmail = trim($currentUser['email'] ?? '');
$userName = trim($currentUser['name'] ?? '');
$normUserName = normalize_person_name($userName);

$roleFilter = sanitize($_GET['role'] ?? 'all');
if (!in_array($roleFilter, ['all', 'lead', 'copi'])) {
    $roleFilter = 'all';
}

$copiSubquery = "p.id IN (
    SELECT pcp.proposal_id FROM proposal_co_pis pcp 
    WHERE (pcp.email IS NOT NULL AND TRIM(pcp.email) != '' AND LOWER(TRIM(pcp.email)) = LOWER(TRIM(?)))
       OR (LOWER(TRIM(pcp.co_pi_name)) = LOWER(TRIM(?)))
       OR (LOWER(TRIM(pcp.co_pi_name)) = LOWER(TRIM(?)))
       OR (LOWER(TRIM(pcp.co_pi_name)) LIKE ?)
)";
$copiSubqueryParams = [$userEmail, $userName, $normUserName, '%' . $normUserName . '%'];

$sql = "SELECT p.*, d.department_name, d.department_code,
               u.name as lead_pi_name, u.designation as lead_pi_designation
        FROM proposals p
        LEFT JOIN departments d ON p.department_id = d.id
        LEFT JOIN users u ON p.scientist_id = u.id
        WHERE 1=1";

if ($roleFilter === 'lead') {
    $sql .= " AND p.scientist_id = ?";
    $params = [$userId];
} elseif ($roleFilter === 'copi') {
    $sql .= " AND p.scientist_id != ? AND (LOWER(TRIM(p.current_status)) != 'draft' AND {$copiSubquery})";
    $params = array_merge([$userId], $copiSubqueryParams);
} else {
    $sql .= " AND (
        p.scientist_id = ? OR 
        (LOWER(TRIM(p.current_status)) != 'draft' AND {$copiSubquery})
    )";
    $params = array_merge([$userId], $copiSubqueryParams);
}

if ($categoryFilter === 'new') {
    $sql .= " AND (p.proposal_category = 'new' OR p.proposal_category IS NULL OR p.proposal_category = '')";
} elseif ($categoryFilter === 'ongoing') {
    $sql .= " AND p.proposal_category = 'ongoing'";
} elseif ($categoryFilter === 'completed') {
    $sql .= " AND p.proposal_category = 'completed'";
}

if (!empty($search)) {
    $sql .= " AND (p.title LIKE ? OR p.proposal_number LIKE ? OR p.institute_priority_area LIKE ? OR p.project_number LIKE ? OR p.progress_report_period LIKE ? OR d.department_name LIKE ? OR d.department_code LIKE ? OR u.name LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (!empty($statusFilter)) {
    if ($statusFilter === 'Returned') {
        $sql .= " AND (p.current_status = ? OR p.current_status = ?)";
        $params[] = STATUS_RETURNED_HOD;
        $params[] = STATUS_RETURNED_JD;
    } else {
        $sql .= " AND p.current_status = ?";
        $params[] = $statusFilter;
    }
}

$sql .= " ORDER BY p.updated_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$proposals = $stmt->fetchAll();

// Accurate counts for category tabs (Lead PI or Co-PI)
$countStmt = $db->prepare("
    SELECT COUNT(DISTINCT p.id) FROM proposals p 
    WHERE (
        p.scientist_id = ? OR 
        (LOWER(TRIM(p.current_status)) != 'draft' AND {$copiSubquery})
    )
");
$countStmt->execute(array_merge([$userId], $copiSubqueryParams));
$countAll = (int)$countStmt->fetchColumn();

// Lead PI count
$countLeadStmt = $db->prepare("SELECT COUNT(DISTINCT id) FROM proposals WHERE scientist_id = ?");
$countLeadStmt->execute([$userId]);
$countLead = (int)$countLeadStmt->fetchColumn();

// Co-PI count (submitted/pending/approved non-draft)
$countCopiStmt = $db->prepare("
    SELECT COUNT(DISTINCT p.id) FROM proposals p 
    WHERE p.scientist_id != ? AND LOWER(TRIM(p.current_status)) != 'draft' AND {$copiSubquery}
");
$countCopiStmt->execute(array_merge([$userId], $copiSubqueryParams));
$countCopi = (int)$countCopiStmt->fetchColumn();

$countNewStmt = $db->prepare("
    SELECT COUNT(DISTINCT p.id) FROM proposals p 
    WHERE (
        p.scientist_id = ? OR 
        (LOWER(TRIM(p.current_status)) != 'draft' AND {$copiSubquery})
    ) AND (p.proposal_category = 'new' OR p.proposal_category IS NULL OR p.proposal_category = '')
");
$countNewStmt->execute(array_merge([$userId], $copiSubqueryParams));
$countNew = (int)$countNewStmt->fetchColumn();

$countOngoingStmt = $db->prepare("
    SELECT COUNT(DISTINCT p.id) FROM proposals p 
    WHERE (
        p.scientist_id = ? OR 
        (LOWER(TRIM(p.current_status)) != 'draft' AND {$copiSubquery})
    ) AND p.proposal_category = 'ongoing'
");
$countOngoingStmt->execute(array_merge([$userId], $copiSubqueryParams));
$countOngoing = (int)$countOngoingStmt->fetchColumn();

$countCompletedStmt = $db->prepare("
    SELECT COUNT(DISTINCT p.id) FROM proposals p 
    WHERE (
        p.scientist_id = ? OR 
        (LOWER(TRIM(p.current_status)) != 'draft' AND {$copiSubquery})
    ) AND p.proposal_category = 'completed'
");
$countCompletedStmt->execute(array_merge([$userId], $copiSubqueryParams));
$countCompleted = (int)$countCompletedStmt->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark"><?= $isHod ? 'My Research Submissions' : 'My Research Proposals' ?></h3>
        <p class="text-muted small mb-0"><?= $isHod ? 'Track your personal research submissions and co-investigator proposals forwarded for review' : 'Track lifecycle stage, review feedback, and view proposals where you are Lead PI or Co-PI' ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= url("/scientist/create-proposal.php") ?>" class="btn btn-primary shadow-sm" style="background-color: #1a365d; border-color: #1a365d;">
            <i class="bi bi-file-earmark-plus me-1"></i> New Proposal
        </a>
        <a href="<?= url("/scientist/ongoing-projects.php") ?>" class="btn btn-outline-primary shadow-sm bg-white">
            <i class="bi bi-arrow-repeat me-1"></i> On Going Projects
        </a>
        <a href="<?= url("/scientist/create-completed-proposal.php") ?>" class="btn btn-outline-secondary shadow-sm bg-white">
            <i class="bi bi-check2-circle me-1"></i> Completion Project
        </a>
    </div>
</div>

<!-- Role View Selector & Category Tabs -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <!-- Category Tabs -->
    <ul class="nav nav-pills p-1 bg-white border rounded shadow-xs m-0" style="max-width: 650px;">
        <li class="nav-item">
            <a class="nav-link <?= $categoryFilter === 'all' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2 px-3" href="<?= url("/scientist/proposals.php?category=all&role=" . urlencode($roleFilter) . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
                All Categories (<?= $countAll ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $categoryFilter === 'new' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2 px-3" href="<?= url("/scientist/proposals.php?category=new&role=" . urlencode($roleFilter) . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
                <i class="bi bi-file-earmark-plus me-1"></i> New (<?= $countNew ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $categoryFilter === 'ongoing' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2 px-3" href="<?= url("/scientist/proposals.php?category=ongoing&role=" . urlencode($roleFilter) . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
                <i class="bi bi-arrow-repeat me-1"></i> On Going (<?= $countOngoing ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $categoryFilter === 'completed' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2 px-3" href="<?= url("/scientist/proposals.php?category=completed&role=" . urlencode($roleFilter) . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
                <i class="bi bi-check2-circle me-1"></i> Completion (<?= $countCompleted ?>)
            </a>
        </li>
    </ul>

    <!-- Role Filter Pills (All / Lead PI / Co-PI) -->
    <div class="btn-group btn-group-sm p-1 bg-white border rounded shadow-xs" role="group">
        <a href="<?= url("/scientist/proposals.php?role=all" . ($categoryFilter !== 'all' ? "&category=" . urlencode($categoryFilter) : "") . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>" class="btn <?= $roleFilter === 'all' ? 'btn-primary active fw-bold' : 'btn-light text-secondary' ?>" title="Show all proposals where you are Lead PI or Co-PI">
            <i class="bi bi-collection me-1"></i> All Roles (<?= $countAll ?>)
        </a>
        <a href="<?= url("/scientist/proposals.php?role=lead" . ($categoryFilter !== 'all' ? "&category=" . urlencode($categoryFilter) : "") . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>" class="btn <?= $roleFilter === 'lead' ? 'btn-primary active fw-bold' : 'btn-light text-secondary' ?>" title="Proposals where you are Principal Investigator">
            <i class="bi bi-person-fill me-1"></i> Lead PI (<?= $countLead ?>)
        </a>
        <a href="<?= url("/scientist/proposals.php?role=copi" . ($categoryFilter !== 'all' ? "&category=" . urlencode($categoryFilter) : "") . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>" class="btn <?= $roleFilter === 'copi' ? 'btn-primary active fw-bold text-white' : 'btn-light text-secondary' ?>" style="<?= $roleFilter === 'copi' ? 'background-color: #6f42c1 !important; border-color: #6f42c1 !important;' : '' ?>" title="Proposals where you are Co-Investigator (View Only)">
            <i class="bi bi-people-fill me-1"></i> Co-PI Submissions (<?= $countCopi ?>)
        </a>
    </div>
</div>

<?php if ($roleFilter === 'copi'): ?>
    <div class="alert alert-info border shadow-xs d-flex align-items-center justify-content-between mb-4 p-3" style="background-color: #f3e8ff; border-color: #d8b4fe !important; color: #581c87;">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-people-fill fs-4 text-purple"></i>
            <div>
                <strong>Co-Principal Investigator Submissions (View Only Mode):</strong>
                <div class="small text-muted mt-1" style="color: #6b21a8 !important;">
                    Showing all research proposals submitted to HOD, pending review, with Joint Director, or approved where you are listed as a <strong>Co-PI</strong>. As a Co-PI, you have view access to monitor status and inspect research designs.
                </div>
            </div>
        </div>
        <a href="<?= url("/scientist/proposals.php?role=all" . ($categoryFilter !== 'all' ? "&category=" . urlencode($categoryFilter) : "")) ?>" class="btn btn-sm btn-outline-secondary bg-white">
            Show All Roles
        </a>
    </div>
<?php endif; ?>

<!-- Search & Filter Card -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= url("/scientist/proposals.php") ?>" class="row g-2 align-items-center" id="filterForm">
            <input type="hidden" name="role" value="<?= e($roleFilter) ?>">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" id="searchInput" class="form-control" placeholder="Search by title, #, code, priority..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="category" class="form-select" id="categorySelect">
                    <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <option value="new" <?= $categoryFilter === 'new' ? 'selected' : '' ?>>New Proposals</option>
                    <option value="ongoing" <?= $categoryFilter === 'ongoing' ? 'selected' : '' ?>>On Going Progress Reports</option>
                    <option value="completed" <?= $categoryFilter === 'completed' ? 'selected' : '' ?>>Completion Reports</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select" id="statusSelect">
                    <option value="">-- All Statuses --</option>
                    <option value="Draft" <?= $statusFilter === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="<?= STATUS_SUBMITTED_HOD ?>" <?= $statusFilter === STATUS_SUBMITTED_HOD ? 'selected' : '' ?>><?= STATUS_SUBMITTED_HOD ?></option>
                    <option value="Returned" <?= $statusFilter === 'Returned' ? 'selected' : '' ?>>Returned (HOD / JD)</option>
                    <option value="<?= STATUS_FORWARDED_JD ?>" <?= $statusFilter === STATUS_FORWARDED_JD ? 'selected' : '' ?>><?= STATUS_FORWARDED_JD ?></option>
                    <option value="<?= STATUS_APPROVED_IRC ?>" <?= $statusFilter === STATUS_APPROVED_IRC ? 'selected' : '' ?>><?= STATUS_APPROVED_IRC ?></option>
                    <option value="<?= STATUS_PENDING_IRC ?>" <?= $statusFilter === STATUS_PENDING_IRC ? 'selected' : '' ?>><?= STATUS_PENDING_IRC ?></option>
                    <option value="<?= STATUS_APPROVED_ACTIVE ?>" <?= $statusFilter === STATUS_APPROVED_ACTIVE ? 'selected' : '' ?>><?= STATUS_APPROVED_ACTIVE ?></option>
                    <option value="<?= STATUS_NOT_APPROVED_ARCHIVED ?>" <?= $statusFilter === STATUS_NOT_APPROVED_ARCHIVED ? 'selected' : '' ?>><?= STATUS_NOT_APPROVED_ARCHIVED ?></option>
                    <option value="<?= STATUS_COMPLETED ?>" <?= $statusFilter === STATUS_COMPLETED ? 'selected' : '' ?>><?= STATUS_COMPLETED ?></option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="<?= url("/scientist/proposals.php" . ($categoryFilter !== 'all' ? "?category=" . urlencode($categoryFilter) : "")) ?>" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="proposalsTable">
            <thead class="table-light">
                <tr>
                    <th style="width: 170px;">Proposal No.</th>
                    <th>Proposal Title & Details</th>
                    <th style="width: 150px;">Priority Area</th>
                    <th style="width: 140px;">Budget</th>
                    <th style="width: 180px;">Status</th>
                    <th style="width: 120px;">Created</th>
                    <th style="width: 150px;" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($proposals)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-folder-x fs-2 d-block mb-2"></i>
                            No research proposals match the selected filters.
                            <?php if (!empty($search) || !empty($statusFilter) || $categoryFilter !== 'all'): ?>
                                <div class="mt-2">
                                    <a href="<?= url("/scientist/proposals.php") ?>" class="btn btn-sm btn-outline-primary">
                                        View All Proposals
                                    </a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($proposals as $prop): ?>
                        <?php
                        $cat = $prop['proposal_category'] ?? 'new';
                        $isOngoing = ($cat === 'ongoing');
                        $isCompleted = ($cat === 'completed');
                        $isCoPi = ((int)$prop['scientist_id'] !== (int)$userId);
                        $canEdit = can_edit_proposal($prop);
                        $canDelete = can_delete_proposal($prop);
                        ?>
                        <tr class="proposal-row <?= $isCoPi ? 'table-light' : '' ?>" data-title="<?= strtolower(e($prop['title'])) ?>" data-number="<?= strtolower(e($prop['proposal_number'])) ?>" data-status="<?= e($prop['current_status']) ?>" data-category="<?= e($cat) ?>">
                            <td class="fw-semibold font-monospace small">
                                <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="text-decoration-none">
                                    <?= e($prop['proposal_number']) ?>
                                </a>
                                <div class="mt-1 d-flex flex-wrap gap-1 align-items-center">
                                    <?php if ($isCoPi): ?>
                                        <span class="badge text-white font-monospace shadow-xs px-2 py-1" style="background-color: #6f42c1 !important; font-size: 0.72rem;">
                                            <i class="bi bi-people-fill me-1"></i>Co-PI
                                        </span>
                                    <?php endif; ?>

                                    <?= render_proposal_category_badge($cat) ?>

                                    <?php if (($prop['project_type'] ?? '') === 'funding_agency'): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle font-monospace" style="font-size: 0.68rem;">
                                            <i class="bi bi-bank2 me-1"></i><?= e($prop['funding_agency'] ?: 'Sponsored') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.68rem;">
                                            <i class="bi bi-house-door me-1"></i>In-house
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($prop['project_number'])): ?>
                                    <div class="badge bg-success-subtle text-success border border-success-subtle mt-1 font-monospace" style="font-size: 0.7rem;">
                                        Code: <?= e($prop['project_number']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="text-decoration-none fw-semibold text-dark d-block">
                                    <?= e($prop['title']) ?>
                                </a>
                                <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                    <small class="text-muted"><?= e($prop['department_name'] ?: 'NDRI') ?> | TRL-<?= e($prop['trl_level']) ?></small>
                                    <?php if ($isOngoing && !empty($prop['progress_report_period'])): ?>
                                        <span class="badge bg-info text-white font-monospace" style="font-size: 0.7rem;">
                                            <i class="bi bi-calendar-check me-1"></i><?= e($prop['progress_report_period']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isCoPi): ?>
                                    <div class="mt-2 d-flex flex-wrap align-items-center gap-1">
                                        <span class="badge border extra-small" style="background-color: #f3e8ff; color: #581c87; border-color: #d8b4fe !important;">
                                            <i class="bi bi-person-badge-fill me-1"></i>Lead PI: <strong><?= e($prop['lead_pi_name'] ?? 'Principal Investigator') ?></strong>
                                            <?php if (!empty($prop['lead_pi_designation'])): ?>
                                                (<?= e($prop['lead_pi_designation']) ?>)
                                            <?php endif; ?>
                                        </span>
                                        <span class="badge bg-light text-secondary border extra-small">
                                            <i class="bi bi-lock-fill me-1"></i>View Only Mode
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <span class="badge bg-light text-dark border" title="<?= e(get_priority_area_title($prop['institute_priority_area'])) ?>">Program <?= e($prop['institute_priority_area'] ?: 'A') ?></span>
                                <?php if (!empty($prop['national_priority_area'])): ?>
                                    <div class="extra-small text-secondary mt-1" style="font-size: 0.75rem;"><?= e(mb_strimwidth($prop['national_priority_area'], 0, 35, '...')) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($isOngoing || $isCompleted): ?>
                                    <div class="fw-bold text-success"><?= format_currency((float)$prop['budget_utilized']) ?></div>
                                    <small class="text-muted">Alloc: <?= format_currency((float)($prop['budget_allocated'] ?: $prop['proposed_budget'])) ?></small>
                                <?php else: ?>
                                    <div class="fw-bold text-dark"><?= format_currency((float)$prop['proposed_budget']) ?></div>
                                    <small class="text-muted">Proposed</small>
                                <?php endif; ?>
                            </td>
                            <td><?= render_status_badge($prop['current_status']) ?></td>
                            <td class="small text-muted"><?= format_date($prop['created_at']) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($isCoPi): ?>
                                        <span class="badge bg-light text-secondary border me-1 align-self-center py-1 px-2" style="font-size: 0.7rem;" title="Co-PI View Only Mode">
                                            <i class="bi bi-eye me-1"></i>View Only
                                        </span>
                                    <?php endif; ?>
                                    <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="btn btn-outline-secondary" title="<?= $isCoPi ? 'View Proposal Details (Co-PI Mode)' : 'View Full Proposal' ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($canEdit): 
                                        $editProposalUrl = url("/scientist/edit-proposal.php?id=" . $prop['id']);
                                        if ($isOngoing) {
                                            $editProposalUrl = url("/scientist/ongoing-projects.php?id=" . $prop['id']);
                                        } elseif ($isCompleted) {
                                            $editProposalUrl = url("/scientist/create-completed-proposal.php?id=" . $prop['id']);
                                        }
                                    ?>
                                        <a href="<?= $editProposalUrl ?>" class="btn btn-outline-primary" title="<?= $isOngoing ? 'Edit Ongoing Draft' : ($prop['current_status'] === STATUS_SUBMITTED_HOD ? 'Edit Proposal' : 'Edit & Resubmit') ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= url("/export-doc.php?id=" . ($prop['id']) . "") ?>" class="btn btn-outline-secondary" title="Word Export (.docx)">
                                        <i class="bi bi-file-earmark-word"></i>
                                    </a>
                                    <?php if ($canDelete): ?>
                                        <form method="POST" action="<?= url("/scientist/delete-proposal.php") ?>" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete <?= $isOngoing ? 'this progress report proposal' : 'this proposal' ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="proposal_id" value="<?= (int)$prop['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Delete Proposal">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // When category select changes, submit filter form automatically
    const catSelect = document.getElementById('categorySelect');
    if (catSelect) {
        catSelect.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    }

    // Interactive client-side live filter for instant responsiveness as user types
    const searchInput = document.getElementById('searchInput');
    const tableRows = document.querySelectorAll('.proposal-row');
    if (searchInput && tableRows.length > 0) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            tableRows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
