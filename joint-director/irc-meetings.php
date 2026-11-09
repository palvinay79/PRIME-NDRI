<?php
/**
 * Research Proposal and Project Management System
 * Joint Director - Institute Research Council (IRC) Meetings & Approvals
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_JOINT_DIRECTOR);

$pageTitle = 'IRC Meetings & Decisions - Joint Director';
$db = get_db();

// Fetch all departments for division filter dropdown
$departmentsList = $db->query("SELECT id, department_name, department_code FROM departments ORDER BY department_name ASC")->fetchAll();
$priorityPrograms = get_institute_priority_programs();

// 1. Proposals Awaiting Final IRC Decision (Status: Approved for IRC Meeting)
$stmt = $db->prepare("SELECT p.*, u.name as scientist_name, d.department_name, d.department_code
                      FROM proposals p
                      JOIN users u ON p.scientist_id = u.id
                      JOIN departments d ON p.department_id = d.id
                      WHERE p.current_status = ?
                      ORDER BY p.updated_at ASC");
$stmt->execute([STATUS_APPROVED_IRC]);
$slatedProposals = $stmt->fetchAll();

// Category tab filter
$categoryFilter = sanitize($_GET['category'] ?? 'all');
$programFilter = strtoupper(trim(sanitize($_GET['program'] ?? '')));
$fundingTypeFilter = sanitize($_GET['funding_type'] ?? '');
$divisionFilter = (int)($_GET['division'] ?? 0);
$sortOption = sanitize($_GET['sort'] ?? 'program_in_house');
$searchQuery = trim(sanitize($_GET['q'] ?? ''));

// Calculate counts for each category tab
$countAll = count($slatedProposals);
$countNew = 0;
$countOngoing = 0;
$countCompleted = 0;

foreach ($slatedProposals as $p) {
    $cat = $p['proposal_category'] ?? 'new';
    if ($cat === 'completed') {
        $countCompleted++;
    } elseif ($cat === 'ongoing') {
        $countOngoing++;
    } else {
        $countNew++;
    }
}

// Filter the slated proposals
$filteredSlated = array_filter($slatedProposals, function($p) use ($categoryFilter, $programFilter, $fundingTypeFilter, $divisionFilter, $searchQuery) {
    // 1. Tab category filter
    $cat = $p['proposal_category'] ?? 'new';
    if ($categoryFilter === 'new') {
        if ($cat !== 'new' && !empty($cat)) return false;
    } elseif ($categoryFilter === 'ongoing') {
        if ($cat !== 'ongoing') return false;
    } elseif ($categoryFilter === 'completed') {
        if ($cat !== 'completed') return false;
    }

    // 2. Program filter (A to F)
    if (!empty($programFilter) && in_array($programFilter, ['A', 'B', 'C', 'D', 'E', 'F'])) {
        $pProg = resolve_program_letter($p['institute_priority_area'] ?? '');
        if ($pProg !== $programFilter) return false;
    }

    // 3. Funding Type filter (In-House vs External)
    if ($fundingTypeFilter === 'in_house') {
        if (($p['project_type'] ?? '') === 'funding_agency') return false;
    } elseif ($fundingTypeFilter === 'funding_agency' || $fundingTypeFilter === 'external') {
        if (($p['project_type'] ?? '') !== 'funding_agency') return false;
    }

    // 4. Division filter
    if ($divisionFilter > 0) {
        if ((int)$p['department_id'] !== $divisionFilter) return false;
    }

    // 5. Keyword search query
    if (!empty($searchQuery)) {
        $q = strtolower($searchQuery);
        $title = strtolower($p['title'] ?? '');
        $pNum = strtolower($p['proposal_number'] ?? '');
        $projNum = strtolower($p['project_number'] ?? '');
        $sci = strtolower($p['scientist_name'] ?? '');
        $dept = strtolower($p['department_name'] ?? '');
        $deptCode = strtolower($p['department_code'] ?? '');
        if (!str_contains($title, $q) && !str_contains($pNum, $q) && !str_contains($projNum, $q) && !str_contains($sci, $q) && !str_contains($dept, $q) && !str_contains($deptCode, $q)) {
            return false;
        }
    }

    return true;
});

// Re-index array
$filteredSlated = array_values($filteredSlated);

// Sort the filtered proposals
usort($filteredSlated, function($a, $b) use ($sortOption) {
    if ($sortOption === 'program_in_house') {
        $progA = resolve_program_letter($a['institute_priority_area'] ?? '');
        $progB = resolve_program_letter($b['institute_priority_area'] ?? '');
        if ($progA !== $progB) {
            return strcmp($progA, $progB);
        }
        $isExtA = (($a['project_type'] ?? '') === 'funding_agency') ? 1 : 0;
        $isExtB = (($b['project_type'] ?? '') === 'funding_agency') ? 1 : 0;
        if ($isExtA !== $isExtB) {
            return $isExtA - $isExtB;
        }
        return (int)$a['id'] - (int)$b['id'];
    } elseif ($sortOption === 'program_asc') {
        $progA = resolve_program_letter($a['institute_priority_area'] ?? '');
        $progB = resolve_program_letter($b['institute_priority_area'] ?? '');
        if ($progA !== $progB) return strcmp($progA, $progB);
        return (int)$a['id'] - (int)$b['id'];
    } elseif ($sortOption === 'division_asc') {
        $cmp = strcasecmp($a['department_name'] ?? '', $b['department_name'] ?? '');
        if ($cmp !== 0) return $cmp;
        return (int)$a['id'] - (int)$b['id'];
    } elseif ($sortOption === 'division_desc') {
        $cmp = strcasecmp($b['department_name'] ?? '', $a['department_name'] ?? '');
        if ($cmp !== 0) return $cmp;
        return (int)$a['id'] - (int)$b['id'];
    } elseif ($sortOption === 'funding_type') {
        $isExtA = (($a['project_type'] ?? '') === 'funding_agency') ? 1 : 0;
        $isExtB = (($b['project_type'] ?? '') === 'funding_agency') ? 1 : 0;
        if ($isExtA !== $isExtB) return $isExtA - $isExtB;
        $progA = resolve_program_letter($a['institute_priority_area'] ?? '');
        $progB = resolve_program_letter($b['institute_priority_area'] ?? '');
        return strcmp($progA, $progB);
    } elseif ($sortOption === 'date_desc') {
        return strtotime($b['updated_at'] ?? ($b['created_at'] ?? '')) - strtotime($a['updated_at'] ?? ($a['created_at'] ?? ''));
    } elseif ($sortOption === 'date_asc') {
        return strtotime($a['updated_at'] ?? ($a['created_at'] ?? '')) - strtotime($b['updated_at'] ?? ($b['created_at'] ?? ''));
    } elseif ($sortOption === 'budget_desc') {
        $bA = (float)($a['budget_allocated'] ?: ($a['proposed_budget'] ?? 0));
        $bB = (float)($b['budget_allocated'] ?: ($b['proposed_budget'] ?? 0));
        return ($bB <=> $bA);
    } elseif ($sortOption === 'budget_asc') {
        $bA = (float)($a['budget_allocated'] ?: ($a['proposed_budget'] ?? 0));
        $bB = (float)($b['budget_allocated'] ?: ($b['proposed_budget'] ?? 0));
        return ($bA <=> $bB);
    } elseif ($sortOption === 'title_asc') {
        return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
    }
    return 0;
});

// Calculate program counts for the currently filtered items
$programCounts = [];
foreach ($filteredSlated as $p) {
    $pProg = resolve_program_letter($p['institute_priority_area'] ?? '');
    $programCounts[$pProg] = ($programCounts[$pProg] ?? 0) + 1;
}

// 2. Finalized IRC Decisions (Sanctioned or Rejected)
$stmt = $db->query("SELECT d.*, p.proposal_number, p.title as proposal_title, p.current_status, p.proposal_category,
                           p.project_type, p.institute_priority_area, p.department_id,
                           u.name as scientist_name, dept.department_code, dept.department_name,
                           rec.name as recorded_by_name
                    FROM irc_decisions d
                    JOIN proposals p ON d.proposal_id = p.id
                    JOIN users u ON p.scientist_id = u.id
                    JOIN departments dept ON p.department_id = dept.id
                    LEFT JOIN users rec ON d.decided_by = rec.id
                    ORDER BY d.decided_at DESC");
$pastDecisions = $stmt->fetchAll();

// Filter past decisions with the same filters if applied
$filteredPastDecisions = array_filter($pastDecisions, function($d) use ($programFilter, $fundingTypeFilter, $divisionFilter, $searchQuery) {
    if (!empty($programFilter) && in_array($programFilter, ['A', 'B', 'C', 'D', 'E', 'F'])) {
        $dProg = resolve_program_letter($d['institute_priority_area'] ?? '');
        if ($dProg !== $programFilter) return false;
    }
    if ($fundingTypeFilter === 'in_house') {
        if (($d['project_type'] ?? '') === 'funding_agency') return false;
    } elseif ($fundingTypeFilter === 'funding_agency' || $fundingTypeFilter === 'external') {
        if (($d['project_type'] ?? '') !== 'funding_agency') return false;
    }
    if ($divisionFilter > 0) {
        if ((int)$d['department_id'] !== $divisionFilter) return false;
    }
    if (!empty($searchQuery)) {
        $q = strtolower($searchQuery);
        $title = strtolower($d['proposal_title'] ?? '');
        $pNum = strtolower($d['proposal_number'] ?? '');
        $projNum = strtolower($d['project_number'] ?? '');
        $sci = strtolower($d['scientist_name'] ?? '');
        $dept = strtolower($d['department_name'] ?? '');
        if (!str_contains($title, $q) && !str_contains($pNum, $q) && !str_contains($projNum, $q) && !str_contains($sci, $q) && !str_contains($dept, $q)) {
            return false;
        }
    }
    return true;
});
$filteredPastDecisions = array_values($filteredPastDecisions);

// Check if any filter is active
$hasActiveFilters = (!empty($programFilter) || !empty($fundingTypeFilter) || $divisionFilter > 0 || !empty($searchQuery) || $sortOption !== 'program_in_house');

// Helper to build URL preserving filters
function build_filter_url(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null && $v !== 0 && $v !== '0') {
            $clean[$k] = $v;
        } elseif ($k === 'category' && $v === 'all') {
            $clean[$k] = 'all';
        }
    }
    return url('/joint-director/irc-meetings.php?' . http_build_query($clean));
}

// Build export URL preserving active tab and filters
$exportParams = [
    'type' => ($categoryFilter === 'all' ? 'new' : $categoryFilter),
    'format' => 'docx'
];
if (!empty($programFilter)) $exportParams['program'] = $programFilter;
if (!empty($fundingTypeFilter)) $exportParams['funding_type'] = $fundingTypeFilter;
if ($divisionFilter > 0) $exportParams['division'] = $divisionFilter;
$exportUrl = url('/export-jdr-irc.php?' . http_build_query($exportParams));

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Institute Research Council (IRC) Governance</h3>
        <p class="text-muted small mb-0">Executive council proceedings, proposal deliberations, budget sanctions, and project code allocations</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $exportUrl ?>" class="btn btn-primary btn-sm fw-bold shadow-sm" title="Export compiled JDR Word Document (.docx)">
            <i class="bi bi-file-earmark-word-fill me-1"></i> Export (.docx)
        </a>
        <a href="<?= url("/joint-director/dashboard.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2 me-1"></i> Executive Dashboard
        </a>
        <a href="<?= url("/joint-director/projects.php") ?>" class="btn btn-success btn-sm">
            <i class="bi bi-kanban me-1"></i> Active Projects
        </a>
    </div>
</div>

<!-- Slated Proposals Section -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-calendar-check-fill text-warning me-2"></i> Proposals Slated for IRC Meeting Deliberation (<?= count($filteredSlated) ?>)
                </h5>
            </div>
            <small class="text-muted">Proposals that have received initial screening approval and await formal council sanction</small>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?= $exportUrl ?>" class="btn btn-outline-success btn-sm fw-bold" title="Export compiled JDR Word (.docx) with proposals organized by Program A to F (In-House then Externally Funded)">
                <i class="bi bi-file-earmark-word me-1"></i> Export (.docx)
            </a>
            <!-- Filter Tabs for New vs Ongoing vs Completed -->
            <ul class="nav nav-pills card-header-pills mb-0">
                <li class="nav-item">
                    <a class="nav-link <?= $categoryFilter === 'all' ? 'active bg-primary' : 'text-secondary' ?> py-1 px-3 fw-semibold small" 
                       href="<?= build_filter_url(['category' => 'all']) ?>">
                        <i class="bi bi-grid-fill me-1"></i> All Projects (<?= $countAll ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $categoryFilter === 'new' ? 'active bg-primary' : 'text-secondary' ?> py-1 px-3 fw-semibold small" 
                       href="<?= build_filter_url(['category' => 'new']) ?>">
                        <i class="bi bi-file-earmark-plus me-1"></i> New Proposals (<?= $countNew ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $categoryFilter === 'ongoing' ? 'active bg-info text-dark fw-bold' : 'text-secondary' ?> py-1 px-3 fw-semibold small" 
                       href="<?= build_filter_url(['category' => 'ongoing']) ?>">
                        <i class="bi bi-arrow-repeat me-1"></i> Progress Reports (<?= $countOngoing ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $categoryFilter === 'completed' ? 'active bg-success text-white' : 'text-secondary' ?> py-1 px-3 fw-semibold small" 
                       href="<?= build_filter_url(['category' => 'completed']) ?>">
                        <i class="bi bi-journal-check me-1"></i> Completed Projects (<?= $countCompleted ?>)
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Enhanced Filter & Sort Control Bar -->
    <form method="GET" action="<?= url('/joint-director/irc-meetings.php') ?>" class="p-3 bg-light border-bottom" id="ircFilterForm">
        <input type="hidden" name="category" value="<?= e($categoryFilter) ?>">
        
        <div class="row g-2 align-items-center">
            <!-- 1. Program Filter -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                    <i class="bi bi-bookmark-fill text-primary me-1"></i> Priority Program
                </label>
                <select name="program" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                    <option value="">All Programs (A to F)</option>
                    <?php foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $pLetter): ?>
                        <option value="<?= $pLetter ?>" <?= ($programFilter === $pLetter) ? 'selected' : '' ?>>
                            Program <?= $pLetter ?> : <?= e(substr($priorityPrograms[$pLetter] ?? '', 12, 45)) ?>...
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. Funding Type Filter -->
            <div class="col-md-2 col-sm-6">
                <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                    <i class="bi bi-cash-stack text-success me-1"></i> Funding Source
                </label>
                <select name="funding_type" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                    <option value="">All Funding Types</option>
                    <option value="in_house" <?= ($fundingTypeFilter === 'in_house') ? 'selected' : '' ?>>In-house (Core Mandate)</option>
                    <option value="funding_agency" <?= ($fundingTypeFilter === 'funding_agency' || $fundingTypeFilter === 'external') ? 'selected' : '' ?>>Externally Funded</option>
                </select>
            </div>

            <!-- 3. Division / Department Filter -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                    <i class="bi bi-building text-info me-1"></i> Division / Regional Station
                </label>
                <select name="division" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                    <option value="">All Divisions (14)</option>
                    <?php foreach ($departmentsList as $dept): ?>
                        <option value="<?= (int)$dept['id'] ?>" <?= ($divisionFilter === (int)$dept['id']) ? 'selected' : '' ?>>
                            <?= e($dept['department_name']) ?> (<?= e($dept['department_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 4. Sort By -->
            <div class="col-md-2 col-sm-6">
                <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                    <i class="bi bi-sort-down text-dark me-1"></i> Sort Order
                </label>
                <select name="sort" class="form-select form-select-sm fw-semibold text-primary" onchange="this.form.submit()">
                    <option value="program_in_house" <?= ($sortOption === 'program_in_house') ? 'selected' : '' ?>>Program (A-F) &amp; In-House 1st</option>
                    <option value="program_asc" <?= ($sortOption === 'program_asc') ? 'selected' : '' ?>>Program (A to F)</option>
                    <option value="funding_type" <?= ($sortOption === 'funding_type') ? 'selected' : '' ?>>Funding Type (In-House 1st)</option>
                    <option value="division_asc" <?= ($sortOption === 'division_asc') ? 'selected' : '' ?>>Division (A to Z)</option>
                    <option value="division_desc" <?= ($sortOption === 'division_desc') ? 'selected' : '' ?>>Division (Z to A)</option>
                    <option value="date_desc" <?= ($sortOption === 'date_desc') ? 'selected' : '' ?>>Date: Newest First</option>
                    <option value="date_asc" <?= ($sortOption === 'date_asc') ? 'selected' : '' ?>>Date: Oldest First</option>
                    <option value="budget_desc" <?= ($sortOption === 'budget_desc') ? 'selected' : '' ?>>Budget: High to Low</option>
                    <option value="budget_asc" <?= ($sortOption === 'budget_asc') ? 'selected' : '' ?>>Budget: Low to High</option>
                    <option value="title_asc" <?= ($sortOption === 'title_asc') ? 'selected' : '' ?>>Project Title (A-Z)</option>
                </select>
            </div>

            <!-- 5. Search & Actions -->
            <div class="col-md-2 col-12">
                <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                    <i class="bi bi-search text-secondary me-1"></i> Quick Search
                </label>
                <div class="input-group input-group-sm">
                    <input type="text" name="q" class="form-control" placeholder="Search..." value="<?= e($searchQuery) ?>" id="ircQuickSearch">
                    <button class="btn btn-outline-primary" type="submit" title="Search"><i class="bi bi-search"></i></button>
                    <?php if ($hasActiveFilters): ?>
                        <a href="<?= url('/joint-director/irc-meetings.php?category=' . urlencode($categoryFilter)) ?>" class="btn btn-outline-danger" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Active filter tags -->
        <?php if ($hasActiveFilters): ?>
            <div class="d-flex flex-wrap align-items-center gap-1 mt-2 pt-2 border-top extra-small text-muted">
                <span class="fw-bold me-1"><i class="bi bi-funnel-fill text-primary me-1"></i>Active Filters:</span>
                <?php if (!empty($programFilter)): ?>
                    <span class="badge bg-primary text-white">Program <?= e($programFilter) ?> <a href="<?= build_filter_url(['program' => '']) ?>" class="text-white text-decoration-none ms-1">&times;</a></span>
                <?php endif; ?>
                <?php if (!empty($fundingTypeFilter)): ?>
                    <span class="badge bg-success text-white"><?= $fundingTypeFilter === 'in_house' ? 'In-house' : 'Externally Funded' ?> <a href="<?= build_filter_url(['funding_type' => '']) ?>" class="text-white text-decoration-none ms-1">&times;</a></span>
                <?php endif; ?>
                <?php if ($divisionFilter > 0): 
                    $dName = '';
                    foreach ($departmentsList as $d) { if ((int)$d['id'] === $divisionFilter) { $dName = $d['department_code']; break; } }
                ?>
                    <span class="badge bg-info text-dark">Division: <?= e($dName) ?> <a href="<?= build_filter_url(['division' => '']) ?>" class="text-dark text-decoration-none ms-1">&times;</a></span>
                <?php endif; ?>
                <?php if (!empty($searchQuery)): ?>
                    <span class="badge bg-secondary text-white">"<?= e($searchQuery) ?>" <a href="<?= build_filter_url(['q' => '']) ?>" class="text-white text-decoration-none ms-1">&times;</a></span>
                <?php endif; ?>
                <?php if ($sortOption !== 'program_in_house'): ?>
                    <span class="badge bg-light text-dark border">Sort: <?= e($sortOption) ?> <a href="<?= build_filter_url(['sort' => 'program_in_house']) ?>" class="text-dark text-decoration-none ms-1">&times;</a></span>
                <?php endif; ?>
                <a href="<?= url('/joint-director/irc-meetings.php?category=' . urlencode($categoryFilter)) ?>" class="btn btn-link btn-xs text-danger text-decoration-none ms-auto py-0">Reset all filters</a>
            </div>
        <?php endif; ?>
    </form>

    <!-- JDR Export Dossier Announcement Banner -->
    <div class="p-3 bg-light border-bottom d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="p-2 rounded bg-primary text-white d-flex align-items-center justify-content-center shadow-xs" style="width: 38px; height: 38px;">
                <i class="bi bi-file-earmark-word fs-5"></i>
            </div>
            <div>
                <strong class="text-dark d-block">Official JDR Deliberation Dossier Export (.docx)</strong>
                <span class="text-muted small">
                    Export proposals slated for council deliberation formatted as a unified JDR dossier: organized by <strong>Program A</strong> (In-House &rarr; Externally Funded), then <strong>Program B</strong>, through <strong>Program F</strong>.
                </span>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= $exportUrl ?>" class="btn btn-primary btn-sm fw-bold shadow-sm" title="Export compiled JDR Word Document (.docx)">
                <i class="bi bi-file-earmark-word-fill me-1"></i> Export (.docx)
            </a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="slatedProposalsTable">
            <thead class="table-light">
                <tr>
                    <th style="width: 170px;">Proposal &amp; Category</th>
                    <th>Research Title &amp; Program</th>
                    <th>Principal Investigator</th>
                    <th>Division</th>
                    <th>Financials</th>
                    <th>Type / TRL</th>
                    <th class="text-end">Council Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filteredSlated)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-funnel text-secondary fs-2 d-block mb-2"></i>
                            <div class="fw-semibold text-dark">No proposals found matching the selected criteria.</div>
                            <div class="extra-small text-muted mt-1">Try switching tabs, clearing filters, or resetting your search query.</div>
                            <?php if ($hasActiveFilters): ?>
                                <a href="<?= url('/joint-director/irc-meetings.php?category=' . urlencode($categoryFilter)) ?>" class="btn btn-sm btn-outline-primary mt-2">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Filters
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                        $currentProg = null;
                        $currentType = null;
                        foreach ($filteredSlated as $p): 
                            $cat = $p['proposal_category'] ?? 'new';
                            $isComp = ($cat === 'completed');
                            $isOngoing = ($cat === 'ongoing');
                            $pProg = resolve_program_letter($p['institute_priority_area'] ?? '');
                            $pType = (($p['project_type'] ?? '') === 'funding_agency') ? 'funding_agency' : 'in_house';
                            $isExt = ($pType === 'funding_agency');

                            // Program group headers when sorting by program
                            if ($sortOption === 'program_in_house' || $sortOption === 'program_asc') {
                                if ($currentProg !== $pProg) {
                                    $currentProg = $pProg;
                                    $currentType = null;
                                    ?>
                                    <tr class="table-primary border-top border-primary-subtle">
                                        <td colspan="7" class="py-2 px-3">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="fw-bold text-dark fs-6">
                                                    <i class="bi bi-bookmark-star-fill text-primary me-2"></i>
                                                    Program <?= e($currentProg) ?> &mdash; <?= e($priorityPrograms[$currentProg] ?? "Program {$currentProg}") ?>
                                                </span>
                                                <span class="badge bg-primary text-white font-monospace"><?= $programCounts[$currentProg] ?? 0 ?> proposal(s)</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                if ($sortOption === 'program_in_house' && $currentType !== $pType) {
                                    $currentType = $pType;
                                    ?>
                                    <tr class="bg-body-secondary border-bottom">
                                        <td colspan="7" class="py-1 px-3 extra-small fw-bold text-uppercase <?= $isExt ? 'text-success' : 'text-primary' ?>">
                                            <?= $isExt 
                                                ? '<i class="bi bi-bank2 me-1"></i> Externally Funded Proposals &mdash; Program ' . e($currentProg) 
                                                : '<i class="bi bi-house-door-fill me-1"></i> In-House Proposals &mdash; Program ' . e($currentProg) ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } elseif ($sortOption === 'division_asc' || $sortOption === 'division_desc') {
                                if ($currentProg !== $p['department_id']) {
                                    $currentProg = $p['department_id'];
                                    ?>
                                    <tr class="table-secondary border-top border-secondary-subtle">
                                        <td colspan="7" class="py-2 px-3 fw-bold text-dark">
                                            <i class="bi bi-building me-2 text-secondary"></i>
                                            <?= e($p['department_name']) ?> (<?= e($p['department_code']) ?>)
                                        </td>
                                    </tr>
                                    <?php
                                }
                            }
                    ?>
                        <tr class="proposal-row">
                            <td>
                                <div class="mb-1">
                                    <?= render_proposal_category_badge($cat) ?>
                                </div>
                                <span class="badge bg-light text-primary border font-monospace d-block mb-1 text-start"><?= e($p['proposal_number']) ?></span>
                                <?php if (!empty($p['project_number'])): ?>
                                    <div class="text-muted extra-small font-monospace">
                                        Code: <strong class="text-dark"><?= e($p['project_number']) ?></strong>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url("/scientist/proposal-details.php?id=" . $p['id']) ?>" class="fw-bold text-dark text-decoration-none d-block">
                                    <?= e($p['title']) ?>
                                </a>
                                <div class="my-1 d-flex flex-wrap gap-1 align-items-center">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size: 0.72rem;">
                                        <i class="bi bi-tag-fill me-1"></i>Program <?= e($pProg) ?>
                                    </span>
                                    <?php if ($isExt): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem;">
                                            <i class="bi bi-bank2 me-1"></i><?= e($p['funding_agency'] ?: 'Externally Funded') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border" style="font-size: 0.72rem;">
                                            <i class="bi bi-house-door text-primary me-1"></i>In-house
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isOngoing): ?>
                                    <?php if (!empty($p['progress_report_period'])): ?>
                                        <span class="badge bg-info text-dark border border-info-subtle font-monospace my-1" style="font-size: 0.70rem;">
                                            <i class="bi bi-calendar-check me-1"></i><?= e($p['progress_report_period']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <small class="text-muted text-truncate d-block" style="max-width: 340px;">
                                        <?= e(clean_text_preview($p['progress_report'] ?? $p['significant_achievements'] ?? $p['objectives'] ?? 'Progress report awaiting IRC review.', 110)) ?>
                                    </small>
                                <?php elseif ($isComp && !empty($p['final_report'])): ?>
                                    <small class="text-muted text-truncate d-inline-block" style="max-width: 340px;">
                                        <i class="bi bi-file-text me-1 text-success"></i>Report: <?= e(clean_text_preview($p['final_report'], 100)) ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted text-truncate d-inline-block" style="max-width: 340px;">
                                        <?= e(clean_text_preview($p['objectives'] ?? 'No objectives specified.', 110)) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= e($p['scientist_name']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace fw-bold"><?= e($p['department_code']) ?></span>
                                <div class="extra-small text-muted text-truncate" style="max-width: 130px;" title="<?= e($p['department_name']) ?>"><?= e($p['department_name']) ?></div>
                            </td>
                            <td>
                                <?php if ($isComp || $isOngoing): ?>
                                    <div class="small">
                                        <span class="text-muted">Alloc:</span> <span class="fw-bold text-primary"><?= format_currency((float)($p['budget_allocated'] ?: $p['proposed_budget'])) ?></span>
                                    </div>
                                    <div class="small">
                                        <span class="text-muted">Util:</span> <span class="fw-bold text-success"><?= format_currency((float)$p['budget_utilized']) ?></span>
                                    </div>
                                    <?php 
                                        $alloc = (float)($p['budget_allocated'] ?: $p['proposed_budget']);
                                        $util = (float)$p['budget_utilized'];
                                        $pct = ($alloc > 0) ? round(($util / $alloc) * 100, 1) : 0;
                                    ?>
                                    <span class="badge <?= $pct > 100 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?>" style="font-size: 0.72rem;">
                                        <?= $pct ?>% util
                                    </span>
                                <?php else: ?>
                                    <span class="fw-semibold text-dark"><?= format_currency((float)$p['proposed_budget']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isExt): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle d-block mb-1">External</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-secondary border d-block mb-1">In-house</span>
                                <?php endif; ?>
                                <span class="badge bg-secondary-subtle text-secondary">TRL <?= (int)$p['trl_level'] ?></span>
                            </td>
                            <td class="text-end">
                                <a href="<?= url("/joint-director/irc-decision.php?id=" . $p['id']) ?>" class="btn btn-sm btn-primary fw-semibold">
                                    <i class="bi bi-award me-1"></i> IRC Decision
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Past Decisions History -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-journal-text text-primary me-2"></i> Past IRC Council Decisions &amp; Sanction Registry (<?= count($filteredPastDecisions) ?>)
            </h5>
            <small class="text-muted">Historical institutional registry of ratified decisions and allocated project IDs</small>
        </div>
        <?php if ($hasActiveFilters): ?>
            <span class="badge bg-light text-secondary border small">
                <i class="bi bi-funnel me-1"></i>Filtered by current criteria
            </span>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Proposal &amp; Category</th>
                    <th>PI &amp; Dept</th>
                    <th>Council Decision</th>
                    <th>Sanctioned Project ID</th>
                    <th>Approved Budget</th>
                    <th>Approved Term</th>
                    <th>Recorded By</th>
                    <th class="text-end">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filteredPastDecisions)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            No past IRC decisions match the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filteredPastDecisions as $d): ?>
                        <?php $dProg = resolve_program_letter($d['institute_priority_area'] ?? ''); ?>
                        <tr>
                            <td>
                                <div class="font-monospace small fw-bold text-primary"><?= e($d['proposal_number']) ?></div>
                                <div class="small fw-semibold text-dark text-truncate mb-1" style="max-width: 250px;"><?= e($d['proposal_title']) ?></div>
                                <div class="d-flex gap-1 align-items-center">
                                    <?= render_proposal_category_badge($d['proposal_category'] ?? 'new') ?>
                                    <span class="badge bg-light text-primary border font-monospace extra-small">Prog <?= e($dProg) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="small fw-semibold text-dark"><?= e($d['scientist_name']) ?></div>
                                <span class="badge bg-light text-secondary border" style="font-size: 0.7rem;"><?= e($d['department_code']) ?></span>
                            </td>
                            <td>
                                <?php if ($d['decision'] === 'approve_active' || $d['current_status'] === STATUS_ACTIVE): ?>
                                    <span class="badge bg-success-subtle text-success">
                                        <i class="bi bi-check-circle-fill me-1"></i> Approved &amp; Sanctioned
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger">
                                        <i class="bi bi-x-circle-fill me-1"></i> Rejected / Not Approved
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($d['project_number'])): ?>
                                    <span class="badge bg-primary font-monospace"><?= e($d['project_number']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($d['approved_budget']): ?>
                                    <span class="fw-semibold text-success">₹<?= number_format((float)$d['approved_budget'], 2) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="small text-secondary"><?= e($d['approved_timeframe'] ?? ($d['approved_start_date'] ? "From " . $d['approved_start_date'] : 'N/A')) ?></span>
                            </td>
                            <td>
                                <div class="small text-muted"><?= e($d['recorded_by_name'] ?? 'Joint Director') ?></div>
                                <div class="small text-muted" style="font-size: 0.7rem;"><?= format_datetime($d['decided_at']) ?></div>
                            </td>
                            <td class="text-end">
                                <a href="<?= url("/scientist/proposal-details.php?id=" . $d['proposal_id']) ?>" class="btn btn-xs btn-outline-secondary">
                                    <i class="bi bi-eye"></i>
                                </a>
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
    const searchInput = document.getElementById('ircQuickSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#slatedProposalsTable tbody tr.proposal-row');
            rows.forEach(function(row) {
                const text = row.innerText.toLowerCase();
                if (!query || text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
