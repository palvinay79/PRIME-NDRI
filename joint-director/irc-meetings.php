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

$filteredSlated = array_filter($slatedProposals, function($p) use ($categoryFilter) {
    $cat = $p['proposal_category'] ?? 'new';
    if ($categoryFilter === 'new') {
        return ($cat === 'new' || empty($cat));
    }
    if ($categoryFilter === 'ongoing') {
        return $cat === 'ongoing';
    }
    if ($categoryFilter === 'completed') {
        return $cat === 'completed';
    }
    return true;
});

// 2. Finalized IRC Decisions (Sanctioned or Rejected)
$stmt = $db->query("SELECT d.*, p.proposal_number, p.title as proposal_title, p.current_status, p.proposal_category,
                           u.name as scientist_name, dept.department_code,
                           rec.name as recorded_by_name
                    FROM irc_decisions d
                    JOIN proposals p ON d.proposal_id = p.id
                    JOIN users u ON p.scientist_id = u.id
                    JOIN departments dept ON p.department_id = dept.id
                    LEFT JOIN users rec ON d.decided_by = rec.id
                    ORDER BY d.decided_at DESC");
$pastDecisions = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Institute Research Council (IRC) Governance</h3>
        <p class="text-muted small mb-0">Executive council proceedings, proposal deliberations, budget sanctions, and project code allocations</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/joint-director/dashboard.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2 me-1"></i> Executive Dashboard
        </a>
        <a href="<?= url("/joint-director/projects.php") ?>" class="btn btn-success btn-sm">
            <i class="bi bi-kanban me-1"></i> Active Projects
        </a>
    </div>
</div>

<?= render_flashes() ?>

<!-- Slated Proposals Section -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-calendar-check-fill text-warning me-2"></i> Proposals Slated for IRC Meeting Deliberation (<?= count($filteredSlated) ?>)
            </h5>
            <small class="text-muted">Proposals that have received initial screening approval and await formal council sanction</small>
        </div>
        <!-- Filter Tabs for New vs Ongoing vs Completed -->
        <ul class="nav nav-pills card-header-pills mb-0">
            <li class="nav-item">
                <a class="nav-link <?= $categoryFilter === 'all' ? 'active bg-primary' : 'text-secondary' ?> py-1 px-3 fw-semibold small" 
                   href="<?= url("/joint-director/irc-meetings.php?category=all") ?>">
                    <i class="bi bi-grid-fill me-1"></i> All Projects (<?= $countAll ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $categoryFilter === 'new' ? 'active bg-primary' : 'text-secondary' ?> py-1 px-3 fw-semibold small" 
                   href="<?= url("/joint-director/irc-meetings.php?category=new") ?>">
                    <i class="bi bi-file-earmark-plus me-1"></i> New Proposals (<?= $countNew ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $categoryFilter === 'ongoing' ? 'active bg-info text-dark fw-bold' : 'text-secondary' ?> py-1 px-3 fw-semibold small" 
                   href="<?= url("/joint-director/irc-meetings.php?category=ongoing") ?>">
                    <i class="bi bi-arrow-repeat me-1"></i> Progress Reports (<?= $countOngoing ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $categoryFilter === 'completed' ? 'active bg-success text-white' : 'text-secondary' ?> py-1 px-3 fw-semibold small" 
                   href="<?= url("/joint-director/irc-meetings.php?category=completed") ?>">
                    <i class="bi bi-journal-check me-1"></i> Completed Projects (<?= $countCompleted ?>)
                </a>
            </li>
        </ul>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 170px;">Proposal & Category</th>
                    <th>Research Title & Objectives</th>
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
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-check2-all text-success fs-3 d-block mb-1"></i>
                            No proposals currently awaiting final IRC council decision under this category.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filteredSlated as $p): ?>
                        <?php 
                            $cat = $p['proposal_category'] ?? 'new';
                            $isComp = ($cat === 'completed');
                            $isOngoing = ($cat === 'ongoing');
                        ?>
                        <tr>
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
                                <?php if ($isOngoing): ?>
                                    <?php if (!empty($p['progress_report_period'])): ?>
                                        <span class="badge bg-info text-dark border border-info-subtle font-monospace my-1" style="font-size: 0.70rem;">
                                            <i class="bi bi-calendar-check me-1"></i><?= e($p['progress_report_period']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <small class="text-muted text-truncate d-block" style="max-width: 320px;">
                                        <?= e(clean_text_preview($p['progress_report'] ?? $p['significant_achievements'] ?? $p['objectives'] ?? 'Progress report awaiting IRC review.', 110)) ?>
                                    </small>
                                <?php elseif ($isComp && !empty($p['final_report'])): ?>
                                    <small class="text-muted text-truncate d-inline-block" style="max-width: 320px;">
                                        <i class="bi bi-file-text me-1 text-success"></i>Report: <?= e(clean_text_preview($p['final_report'], 100)) ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted text-truncate d-inline-block" style="max-width: 320px;">
                                        <?= e(clean_text_preview($p['objectives'] ?? 'No objectives specified.', 110)) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= e($p['scientist_name']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= e($p['department_code']) ?></span>
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
                                <?php if (($p['project_type'] ?? '') === 'funding_agency'): ?>
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
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="bi bi-journal-text text-primary me-2"></i> Past IRC Council Decisions & Sanction Registry (<?= count($pastDecisions) ?>)
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Proposal & Category</th>
                    <th>PI & Dept</th>
                    <th>Council Decision</th>
                    <th>Sanctioned Project ID</th>
                    <th>Approved Budget</th>
                    <th>Approved Term</th>
                    <th>Recorded By</th>
                    <th class="text-end">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pastDecisions)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">No past IRC decisions logged yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pastDecisions as $d): ?>
                        <tr>
                            <td>
                                <div class="font-monospace small fw-bold text-primary"><?= e($d['proposal_number']) ?></div>
                                <div class="small fw-semibold text-dark text-truncate mb-1" style="max-width: 250px;"><?= e($d['proposal_title']) ?></div>
                                <?= render_proposal_category_badge($d['proposal_category'] ?? 'new') ?>
                            </td>
                            <td>
                                <div class="small fw-semibold text-dark"><?= e($d['scientist_name']) ?></div>
                                <span class="badge bg-light text-secondary border" style="font-size: 0.7rem;"><?= e($d['department_code']) ?></span>
                            </td>
                            <td>
                                <?php if ($d['decision'] === 'approve_active' || $d['current_status'] === STATUS_ACTIVE): ?>
                                    <span class="badge bg-success-subtle text-success">
                                        <i class="bi bi-check-circle-fill me-1"></i> Approved & Sanctioned
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
                                <div class="small text-muted" style="font-size: 0.7rem;"><?= date('d M Y, h:i A', strtotime($d['decided_at'])) ?></div>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
