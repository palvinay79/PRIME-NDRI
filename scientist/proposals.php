<?php
/**
 * Research Proposal and Project Management System
 * Scientist - My Proposals List
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role(ROLE_SCIENTIST);

$pageTitle = 'My Proposals';
$userId = current_user_id();
$db = get_db();

$search = sanitize($_GET['q'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$categoryFilter = sanitize($_GET['category'] ?? ($_GET['tab'] ?? 'all'));
if (!in_array($categoryFilter, ['all', 'new', 'ongoing', 'completed'])) {
    $categoryFilter = 'all';
}

$sql = "SELECT p.*, d.department_name, d.department_code
        FROM proposals p
        LEFT JOIN departments d ON p.department_id = d.id
        WHERE p.scientist_id = ?";
$params = [$userId];

if ($categoryFilter === 'new') {
    $sql .= " AND (p.proposal_category = 'new' OR p.proposal_category IS NULL OR p.proposal_category = '')";
} elseif ($categoryFilter === 'ongoing') {
    $sql .= " AND p.proposal_category = 'ongoing'";
} elseif ($categoryFilter === 'completed') {
    $sql .= " AND p.proposal_category = 'completed'";
}

if (!empty($search)) {
    $sql .= " AND (p.title LIKE ? OR p.proposal_number LIKE ? OR p.institute_priority_area LIKE ? OR p.project_number LIKE ? OR p.progress_report_period LIKE ? OR d.department_name LIKE ? OR d.department_code LIKE ?)";
    $term = "%{$search}%";
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

// Accurate counts for category tabs
$countAll = (int)$db->query("SELECT COUNT(*) FROM proposals WHERE scientist_id = {$userId}")->fetchColumn();
$countNew = (int)$db->query("SELECT COUNT(*) FROM proposals WHERE scientist_id = {$userId} AND (proposal_category = 'new' OR proposal_category IS NULL OR proposal_category = '')")->fetchColumn();
$countOngoing = (int)$db->query("SELECT COUNT(*) FROM proposals WHERE scientist_id = {$userId} AND proposal_category = 'ongoing'")->fetchColumn();
$countCompleted = (int)$db->query("SELECT COUNT(*) FROM proposals WHERE scientist_id = {$userId} AND proposal_category = 'completed'")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">My Research Proposals</h3>
        <p class="text-muted small mb-0">Track lifecycle stage, review feedback, and submit new, ongoing, or completion projects</p>
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

<!-- Proposal Category Tabs -->
<ul class="nav nav-pills mb-4 p-1 bg-white border rounded shadow-xs" style="max-width: 780px;">
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'all' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/scientist/proposals.php?category=all" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            All (<?= $countAll ?>)
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'new' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/scientist/proposals.php?category=new" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            <i class="bi bi-file-earmark-plus me-1"></i> New (<?= $countNew ?>)
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'ongoing' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/scientist/proposals.php?category=ongoing" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            <i class="bi bi-arrow-repeat me-1"></i> On Going (<?= $countOngoing ?>)
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link <?= $categoryFilter === 'completed' ? 'active fw-bold' : 'text-secondary fw-semibold' ?> py-2" href="<?= url("/scientist/proposals.php?category=completed" . (!empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "") . (!empty($search) ? "&q=" . urlencode($search) : "")) ?>">
            <i class="bi bi-check2-circle me-1"></i> Completion (<?= $countCompleted ?>)
        </a>
    </li>
</ul>

<!-- Search & Filter Card -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= url("/scientist/proposals.php") ?>" class="row g-2 align-items-center" id="filterForm">
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
                        $canEdit = can_edit_proposal($prop);
                        $canDelete = can_delete_proposal($prop);
                        ?>
                        <tr class="proposal-row" data-title="<?= strtolower(e($prop['title'])) ?>" data-number="<?= strtolower(e($prop['proposal_number'])) ?>" data-status="<?= e($prop['current_status']) ?>" data-category="<?= e($cat) ?>">
                            <td class="fw-semibold font-monospace small">
                                <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="text-decoration-none">
                                    <?= e($prop['proposal_number']) ?>
                                </a>
                                <div class="mt-1 d-flex flex-wrap gap-1">
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
                                    <a href="<?= url("/scientist/proposal-details.php?id=" . ($prop['id']) . "") ?>" class="btn btn-outline-secondary" title="View Full Proposal">
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
