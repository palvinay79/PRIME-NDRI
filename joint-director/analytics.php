<?php
/**
 * Research Proposal and Project Management System
 * Joint Director - Institutional Research Analytics Dashboard
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_JOINT_DIRECTOR);

$pageTitle = 'Institutional Research Analytics';
$db = get_db();

// 1. Status breakdown
$statusData = $db->query("SELECT current_status, COUNT(*) as cnt FROM proposals GROUP BY current_status")->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Department-wise count and proposed budget
$deptStats = $db->query("SELECT d.department_code, d.department_name, COUNT(p.id) as prop_count, COALESCE(SUM(p.proposed_budget), 0) as total_budget
                         FROM departments d
                         LEFT JOIN proposals p ON d.id = p.department_id
                         GROUP BY d.id
                         ORDER BY prop_count DESC")->fetchAll();

// 3. TRL distribution
$trlStats = $db->query("SELECT trl_level, COUNT(*) as cnt FROM proposals GROUP BY trl_level ORDER BY trl_level ASC")->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. Priority Area distribution
$priorityStats = $db->query("SELECT institute_priority_area, COUNT(*) as cnt FROM proposals WHERE institute_priority_area != '' GROUP BY institute_priority_area ORDER BY cnt DESC LIMIT 6")->fetchAll();

// 5. Overall approval calculation
$totalProposals = (int)$db->query("SELECT COUNT(*) FROM proposals WHERE current_status != 'Draft'")->fetchColumn();
$approvedProposals = (int)$db->query("SELECT COUNT(*) FROM proposals WHERE current_status IN ('Approved / Active', 'Completed')")->fetchColumn();
$approvalRate = ($totalProposals > 0) ? round(($approvedProposals / $totalProposals) * 100, 1) : 0;

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Institutional Research Analytics & Metrics</h3>
        <p class="text-muted small mb-0">High-level executive metrics on divisional research pipelines, TRL readiness, and grant distributions</p>
    </div>
    <a href="<?= url("/joint-director/dashboard.php") ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>

<!-- Key Performance Indicators -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-primary"></div>
            <span class="text-muted small text-uppercase fw-semibold">Evaluated Proposals</span>
            <div class="fs-3 fw-bold text-dark mt-1"><?= $totalProposals ?></div>
            <small class="text-muted">Excluding drafts</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-success"></div>
            <span class="text-muted small text-uppercase fw-semibold">Sanctioned Grants</span>
            <div class="fs-3 fw-bold text-success mt-1"><?= $approvedProposals ?></div>
            <small class="text-muted">Active or completed projects</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-warning"></div>
            <span class="text-muted small text-uppercase fw-semibold">IRC Approval Rate</span>
            <div class="fs-3 fw-bold text-warning-emphasis mt-1"><?= $approvalRate ?>%</div>
            <small class="text-muted">Council sanction ratio</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card p-3">
            <div class="stat-card-accent bg-dark"></div>
            <span class="text-muted small text-uppercase fw-semibold">Academic Divisions</span>
            <div class="fs-3 fw-bold text-dark mt-1"><?= count($deptStats) ?></div>
            <small class="text-muted">Research departments</small>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Department Breakdown Table -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark"><i class="bi bi-bar-chart-fill text-primary me-2"></i>Departmental Research Pipeline</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Division Name</th>
                            <th class="text-center" style="width: 130px;">Proposals</th>
                            <th class="text-end" style="width: 160px;">Total Budget Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deptStats as $ds): ?>
                            <tr>
                                <td>
                                    <strong class="text-dark d-block"><?= e($ds['department_name']) ?></strong>
                                    <span class="badge bg-light text-secondary border"><?= e($ds['department_code']) ?></span>
                                </td>
                                <td class="text-center fw-bold">
                                    <span class="badge bg-primary-subtle text-primary fs-6 px-3">
                                        <?= (int)$ds['prop_count'] ?>
                                    </span>
                                </td>
                                <td class="text-end fw-semibold small text-success">
                                    <?= format_currency((float)$ds['total_budget']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Status Distribution -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark"><i class="bi bi-pie-chart-fill text-primary me-2"></i>Workflow Stage Distribution</h6>
            </div>
            <div class="card-body p-3">
                <div class="list-group list-group-flush">
                    <?php foreach ($statusData as $stName => $stCount): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-2 py-2">
                            <div><?= render_status_badge($stName) ?></div>
                            <span class="badge bg-secondary font-monospace"><?= $stCount ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Technology Readiness Level (TRL) Breakdown -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark"><i class="bi bi-speedometer2 text-primary me-2"></i>Technology Readiness Level (TRL) Spread</h6>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <?php for ($trl = 1; $trl <= 9; $trl++): ?>
                        <?php $tCount = $trlStats[$trl] ?? 0; ?>
                        <div class="col-4">
                            <div class="p-3 border rounded text-center bg-light">
                                <div class="badge bg-dark mb-1">TRL-<?= $trl ?></div>
                                <div class="fs-4 fw-bold text-primary"><?= $tCount ?></div>
                                <small class="text-muted" style="font-size: 0.72rem;">
                                    <?= $trl <= 3 ? 'Basic Science' : ($trl <= 6 ? 'Validation' : 'Deployment') ?>
                                </small>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Priority Area Focus -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark"><i class="bi bi-compass text-primary me-2"></i>Top Strategic Research Focus Areas</h6>
            </div>
            <div class="card-body">
                <?php if (empty($priorityStats)): ?>
                    <p class="text-muted small">No priority data recorded.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($priorityStats as $ps): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <span class="small text-dark fw-semibold"><?= e($ps['institute_priority_area']) ?></span>
                                <span class="badge bg-info-subtle text-dark border"><?= (int)$ps['cnt'] ?> proposals</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
