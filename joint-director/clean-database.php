<?php
/**
 * Research Proposal and Project Management System
 * Joint Director - Temporary Database Cleanup & Testing Utility
 * Allows granular checkbox-based or one-click cleaning of test data.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(ROLE_JOINT_DIRECTOR);

$pageTitle = 'Database Cleanup & Maintenance (Testing Utility)';
$db = get_db();
$currentUserId = (int)current_user_id();
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

// Handle Database Clean Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // Disable foreign key constraints during batch operation for seamless execution
    if ($driver === 'mysql') {
        $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    } else {
        $db->exec("PRAGMA foreign_keys = OFF;");
    }

    $cleanedItems = [];

    // 1. Granular Selective Clean
    if ($action === 'clean_selected') {
        $cleanProjects  = !empty($_POST['clean_projects']);
        $cleanProposals = !empty($_POST['clean_proposals']);
        $cleanMeetings  = !empty($_POST['clean_meetings']);
        $cleanUsers     = !empty($_POST['clean_users']);
        $cleanLogs      = !empty($_POST['clean_logs']);

        if (!$cleanProjects && !$cleanProposals && !$cleanMeetings && !$cleanUsers && !$cleanLogs) {
            flash('warning', 'Please select at least one data category checkbox to clean.');
            redirect('/joint-director/clean-database.php');
        }

        try {
            $db->beginTransaction();

            // A. Clean Projects & Reports
            if ($cleanProjects || $cleanProposals) {
                $cRepComments = $db->exec("DELETE FROM progress_report_comments");
                $cRep = $db->exec("DELETE FROM progress_reports");
                $cComp = $db->exec("DELETE FROM completion_reports");
                $cProj = $db->exec("DELETE FROM projects");

                // Reset project linkage on proposals if proposals are not being wiped
                if (!$cleanProposals) {
                    $db->exec("UPDATE proposals SET linked_project_id = NULL, current_status = 'Draft' WHERE current_status = 'Approved / Active'");
                }

                $cleanedItems[] = "<strong>{$cProj}</strong> Projects, <strong>" . ($cRep + $cComp) . "</strong> Reports";
            }

            // B. Clean Proposals & Review Trails
            if ($cleanProposals) {
                $cDocs = $db->exec("DELETE FROM proposal_documents");
                $cComments = $db->exec("DELETE FROM proposal_comments");
                $cCoPis = $db->exec("DELETE FROM proposal_co_pis");
                $cHistory = $db->exec("DELETE FROM proposal_status_history");
                $cProps = $db->exec("DELETE FROM proposals");

                $cleanedItems[] = "<strong>{$cProps}</strong> Proposals (and associated review trails, documents, and co-PIs)";
            }

            // C. Clean IRC Meetings & Decisions
            if ($cleanMeetings) {
                $cDec = $db->exec("DELETE FROM irc_decisions");
                $cMeet = $db->exec("DELETE FROM irc_meetings");
                $cleanedItems[] = "<strong>{$cMeet}</strong> IRC Meetings & <strong>{$cDec}</strong> Decisions";
            }

            // D. Clean Users (Safely preserving Joint Director / Administrator accounts)
            if ($cleanUsers) {
                // Delete non-admin users only
                $stmt = $db->prepare("DELETE FROM users WHERE id != ? AND role_id != ? AND LOWER(email) NOT IN ('jointdirector@ndri.res.in', 'admin@ndri.res.in')");
                $stmt->execute([$currentUserId, ROLE_JOINT_DIRECTOR]);
                $cUsers = $stmt->rowCount();

                // Reassign any remaining proposals/projects to current Joint Director so FKs remain valid
                $db->prepare("UPDATE proposals SET scientist_id = ? WHERE scientist_id NOT IN (SELECT id FROM users)")->execute([$currentUserId]);
                $db->prepare("UPDATE projects SET scientist_id = ? WHERE scientist_id NOT IN (SELECT id FROM users)")->execute([$currentUserId]);

                $cleanedItems[] = "<strong>{$cUsers}</strong> Non-Admin Users (Your Joint Director admin account was safely preserved)";
            }

            // E. Clean Audit Logs
            if ($cleanLogs) {
                $cLogs = $db->exec("DELETE FROM audit_logs");
                $cleanedItems[] = "<strong>{$cLogs}</strong> Audit Log Records";
            }

            $db->commit();

            log_audit($currentUserId, 'DATABASE_CLEANED', 'system', 0, "Selective database clean: " . implode(', ', array_map('strip_tags', $cleanedItems)));
            flash('success', "Database cleanup completed successfully! Removed: " . implode('; ', $cleanedItems) . ".");
        } catch (Exception $e) {
            $db->rollBack();
            flash('danger', "Database cleanup failed: " . e($e->getMessage()));
        }
    }

    // 2. Full Complete Reset (One-Click Clean All)
    elseif ($action === 'clean_all') {
        try {
            $db->beginTransaction();

            $cRepComm = $db->exec("DELETE FROM progress_report_comments");
            $cRep = $db->exec("DELETE FROM progress_reports");
            $cComp = $db->exec("DELETE FROM completion_reports");
            $cProj = $db->exec("DELETE FROM projects");

            $cDec = $db->exec("DELETE FROM irc_decisions");
            $cMeet = $db->exec("DELETE FROM irc_meetings");

            $cDocs = $db->exec("DELETE FROM proposal_documents");
            $cComm = $db->exec("DELETE FROM proposal_comments");
            $cCoPis = $db->exec("DELETE FROM proposal_co_pis");
            $cHist = $db->exec("DELETE FROM proposal_status_history");
            $cProps = $db->exec("DELETE FROM proposals");

            // Delete non-admin users (preserve Joint Director admin)
            $stmt = $db->prepare("DELETE FROM users WHERE id != ? AND role_id != ? AND LOWER(email) NOT IN ('jointdirector@ndri.res.in', 'admin@ndri.res.in')");
            $stmt->execute([$currentUserId, ROLE_JOINT_DIRECTOR]);
            $cUsers = $stmt->rowCount();

            $cLogs = $db->exec("DELETE FROM audit_logs");

            $db->commit();

            log_audit($currentUserId, 'DATABASE_FULL_RESET', 'system', 0, "Full institute database reset executed.");
            flash('success', "One-Click Full Reset complete! Cleared all proposals ({$cProps}), projects ({$cProj}), reports, IRC meetings, and non-admin users ({$cUsers}). Your Joint Director account and official divisions remain ready for fresh testing.");
        } catch (Exception $e) {
            $db->rollBack();
            flash('danger', "Full reset failed: " . e($e->getMessage()));
        }
    }

    // 3. Re-seed Standard Sample Test Data
    elseif ($action === 'reseed_samples') {
        try {
            $db->beginTransaction();

            // Clear previous proposals/projects to avoid duplicate IDs
            $db->exec("DELETE FROM progress_report_comments");
            $db->exec("DELETE FROM progress_reports");
            $db->exec("DELETE FROM completion_reports");
            $db->exec("DELETE FROM projects");
            $db->exec("DELETE FROM irc_decisions");
            $db->exec("DELETE FROM irc_meetings");
            $db->exec("DELETE FROM proposal_documents");
            $db->exec("DELETE FROM proposal_comments");
            $db->exec("DELETE FROM proposal_co_pis");
            $db->exec("DELETE FROM proposal_status_history");
            $db->exec("DELETE FROM proposals");

            $defaultHash = '$2y$10$3m4AJfA0m.gQP2BQvkIRGO2oYwYGXzKfnWqZif8.MMCxzQENFdG2C'; // password123

            // Ensure baseline faculty users exist
            $baseUsers = [
                [1, 'Dr. Aris Thorne', 'scientist1@ndri.res.in', $defaultHash, 1, 1, 'Senior Scientist', 'active'],
                [2, 'Dr. Sarah Jenkins', 'scientist2@ndri.res.in', $defaultHash, 1, 6, 'Scientist', 'active'],
                [3, 'Prof. Rajesh Kumar', 'hod.agb@ndri.res.in', $defaultHash, 2, 1, 'Head of Department (AGB)', 'active'],
                [4, 'Prof. Meena Rao', 'hod.an@ndri.res.in', $defaultHash, 2, 6, 'Head of Department (AN)', 'active']
            ];

            foreach ($baseUsers as $bu) {
                $chk = (int)$db->query("SELECT COUNT(*) FROM users WHERE id = {$bu[0]}")->fetchColumn();
                if ($chk === 0) {
                    $ins = $db->prepare("INSERT INTO users (id, name, email, password, role_id, department_id, designation, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute($bu);
                }
            }

            // Seed realistic proposals
            $stmtProp = $db->prepare("INSERT INTO proposals (
                id, proposal_number, title, scientist_id, department_id, institute_priority_area, national_priority_area,
                trl_level, research_problem, baseline_info, novelty_gap_analysis, justification_end_users, institute_alignment,
                technical_program, objectives, methodology, expected_outcomes, proposed_budget, budget_justification,
                proposed_start_date, proposed_end_date, current_status, project_number, approved_budget, approved_start_date,
                approved_end_date, approved_timeframe, submitted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '-5 days'))");

            $stmtProp->execute([
                1, 'PROP-2026-AGB-001', 'AI-Driven Somatic Cell Count Analysis in Bovine Milk',
                1, 1, 'Milk Quality, Safety & Biosensors', 'Sustainable Livestock Production',
                3, 'Early mastitis detection lacks non-invasive rapid optical screening.', 'Current CMT tests are subjective.',
                'Uses deep learning edge models on optical diffraction images.', 'Dairy farmers and cooperatives.',
                'Aligns with Institute priority on digital livestock diagnostics.', 'Phased algorithm training and sensor validation.',
                '1. Develop edge-AI model\n2. Calibrate sensor\n3. On-farm validation', 'Computer vision and optical spectroscopy.',
                'High-throughput mobile mastitis screening device.', 850000.00, 'Consumables, optical sensor kit, field trial travel.',
                '2026-04-01', '2028-03-31', 'Forwarded to Joint Director', null, null, null, null, null
            ]);

            $stmtProp->execute([
                2, 'PROP-2025-AN-004', 'Methane Abatement in Dairy Cattle via Tannin-Enriched Feed Supplements',
                2, 6, 'Ruminant Nutrition & Feed Resources', 'Greenhouse Gas Mitigation in Ruminants',
                4, 'Enteric methane emissions contribute 40% of livestock carbon footprint.', 'Traditional feed additives reduce palatability.',
                'Indigenous tannin bioactive formulations optimized for ruminal fermentation.', 'Progressive dairy farm clusters.',
                'Directly supports climate-resilient dairy livestock vision.', 'In vitro fermentation followed by in vivo cattle trials.',
                '1. Screen phyto-sources\n2. Formulation trial\n3. Cattle respiration chamber trials', 'In vivo calorimeter chamber trials.',
                'Standardized feed blend reducing methane by >18%.', 1420000.00, 'Calorimeter operational expenses, cattle feedstocks, assays.',
                '2025-07-01', '2028-06-30', 'Approved / Active', 'PROJ-NDRI-2025-089', 1350000.00, '2025-08-01', '2028-07-31', '3 Years'
            ]);

            $db->exec("INSERT INTO projects (id, proposal_id, project_number, scientist_id, department_id, project_status, start_date, end_date, approved_budget)
                       VALUES (1, 2, 'PROJ-NDRI-2025-089', 2, 6, 'Active', '2025-08-01', '2028-07-31', 1350000.00);");

            $db->commit();

            log_audit($currentUserId, 'DATABASE_RESEEDED', 'system', 0, "Re-seeded default demonstration test records.");
            flash('success', "Realistic sample test data re-seeded successfully! (Proposals, Active Projects, and Research records restored).");
        } catch (Exception $e) {
            $db->rollBack();
            flash('danger', "Re-seeding failed: " . e($e->getMessage()));
        }
    }

    // Re-enable foreign key constraints
    if ($driver === 'mysql') {
        $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
    } else {
        $db->exec("PRAGMA foreign_keys = ON;");
    }

    redirect('/joint-director/clean-database.php');
}

// Fetch live statistics of all tables
$counts = [
    'proposals'         => (int)$db->query("SELECT COUNT(*) FROM proposals")->fetchColumn(),
    'proposal_co_pis'   => (int)$db->query("SELECT COUNT(*) FROM proposal_co_pis")->fetchColumn(),
    'proposal_comments' => (int)$db->query("SELECT COUNT(*) FROM proposal_comments")->fetchColumn(),
    'proposal_history'  => (int)$db->query("SELECT COUNT(*) FROM proposal_status_history")->fetchColumn(),
    'projects'          => (int)$db->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
    'progress_reports'  => (int)$db->query("SELECT COUNT(*) FROM progress_reports")->fetchColumn(),
    'completion_reports'=> (int)$db->query("SELECT COUNT(*) FROM completion_reports")->fetchColumn(),
    'irc_meetings'      => (int)$db->query("SELECT COUNT(*) FROM irc_meetings")->fetchColumn(),
    'irc_decisions'     => (int)$db->query("SELECT COUNT(*) FROM irc_decisions")->fetchColumn(),
    'non_admin_users'   => (int)$db->query("SELECT COUNT(*) FROM users WHERE role_id != " . ROLE_JOINT_DIRECTOR . " AND id != {$currentUserId}")->fetchColumn(),
    'admin_users'       => (int)$db->query("SELECT COUNT(*) FROM users WHERE role_id = " . ROLE_JOINT_DIRECTOR . " OR id = {$currentUserId}")->fetchColumn(),
    'audit_logs'        => (int)$db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn(),
    'departments'       => (int)$db->query("SELECT COUNT(*) FROM departments")->fetchColumn(),
];

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h3 class="fw-bold mb-0 text-dark">Database Cleanup & Maintenance Utility</h3>
            <span class="badge bg-warning text-dark border border-warning">Testing Utility (Temporary)</span>
        </div>
        <p class="text-muted small mb-0">Selectively clean projects, proposals, IRC records, or users to conduct fresh testing rounds.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('/joint-director/dashboard.php') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<!-- Notice banner about temporary testing nature -->
<div class="alert alert-info border-info d-flex align-items-center gap-3 py-3 mb-4 shadow-sm">
    <div class="fs-3 text-info"><i class="bi bi-info-circle-fill"></i></div>
    <div class="small">
        <strong>Administrator Testing Tool:</strong> This utility is provided specifically for testing so you can wipe experimental proposals, active projects, and user test submissions in one click or by choosing individual categories. 
        <div class="mt-1 text-secondary">
            <i class="bi bi-shield-check text-success me-1"></i><strong>Admin Safety Guard:</strong> Your active Joint Director administrative account and the 14 official ICAR-NDRI divisions are permanently protected and will <strong>never</strong> be deleted.
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Main Column: Selective Cleanup Form -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-check2-square text-primary me-2"></i>Select Data Categories to Clean
                </h6>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" onclick="toggleAllCheckboxes(true)">
                        <i class="bi bi-check-all me-1"></i>Select All
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="toggleAllCheckboxes(false)">
                        <i class="bi bi-x me-1"></i>Clear All
                    </button>
                </div>
            </div>

            <div class="card-body p-4">
                <form id="cleanSelectiveForm" method="POST" action="<?= url('/joint-director/clean-database.php') ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="clean_selected">

                    <div class="list-group list-group-flush border rounded-3 mb-4">
                        <!-- 1. Projects & Reports -->
                        <label class="list-group-item list-group-item-action d-flex align-items-start gap-3 p-3">
                            <input class="form-check-input flex-shrink-0 mt-1 category-checkbox" type="checkbox" name="clean_projects" value="1" id="chk_projects">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="text-dark"><i class="bi bi-kanban text-primary me-1"></i> Research Projects & Reports</strong>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                        <?= $counts['projects'] ?> Projects &bull; <?= ($counts['progress_reports'] + $counts['completion_reports']) ?> Reports
                                    </span>
                                </div>
                                <div class="text-muted small mt-1">
                                    Wipes all active/ongoing projects, progress reports, progress report comments, and project completion reports.
                                </div>
                            </div>
                        </label>

                        <!-- 2. Proposals & Review Trails -->
                        <label class="list-group-item list-group-item-action d-flex align-items-start gap-3 p-3">
                            <input class="form-check-input flex-shrink-0 mt-1 category-checkbox" type="checkbox" name="clean_proposals" value="1" id="chk_proposals">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="text-dark"><i class="bi bi-file-earmark-text text-info me-1"></i> Research Proposals & Review Trails</strong>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle">
                                        <?= $counts['proposals'] ?> Proposals &bull; <?= $counts['proposal_co_pis'] ?> Co-PIs
                                    </span>
                                </div>
                                <div class="text-muted small mt-1">
                                    Wipes all proposals across all stages (Drafts, HOD Review, JD Screening, IRC decisions), status history, review remarks, and attached documents.
                                </div>
                            </div>
                        </label>

                        <!-- 3. IRC Meetings & Decisions -->
                        <label class="list-group-item list-group-item-action d-flex align-items-start gap-3 p-3">
                            <input class="form-check-input flex-shrink-0 mt-1 category-checkbox" type="checkbox" name="clean_meetings" value="1" id="chk_meetings">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="text-dark"><i class="bi bi-calendar-event text-warning me-1"></i> IRC Meetings & Council Decisions</strong>
                                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle">
                                        <?= $counts['irc_meetings'] ?> Meetings &bull; <?= $counts['irc_decisions'] ?> Decisions
                                    </span>
                                </div>
                                <div class="text-muted small mt-1">
                                    Wipes scheduled and past Institute Research Council (IRC) meetings, presentation agendas, and recorded committee recommendations.
                                </div>
                            </div>
                        </label>

                        <!-- 4. Scientists & HOD Accounts -->
                        <label class="list-group-item list-group-item-action d-flex align-items-start gap-3 p-3">
                            <input class="form-check-input flex-shrink-0 mt-1 category-checkbox" type="checkbox" name="clean_users" value="1" id="chk_users">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="text-dark"><i class="bi bi-people text-danger me-1"></i> Non-Admin Faculty & Scientist User Accounts</strong>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                        <?= $counts['non_admin_users'] ?> Accounts
                                    </span>
                                </div>
                                <div class="text-muted small mt-1">
                                    Deletes registered scientist and Head of Department user accounts so you can register real faculty members from scratch.
                                    <span class="text-success fw-semibold"><i class="bi bi-shield-lock-fill"></i> Your Joint Director admin account will NOT be touched.</span>
                                </div>
                            </div>
                        </label>

                        <!-- 5. Audit Activity Logs -->
                        <label class="list-group-item list-group-item-action d-flex align-items-start gap-3 p-3">
                            <input class="form-check-input flex-shrink-0 mt-1 category-checkbox" type="checkbox" name="clean_logs" value="1" id="chk_logs">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="text-dark"><i class="bi bi-clock-history text-secondary me-1"></i> System Activity & Audit Logs</strong>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                        <?= $counts['audit_logs'] ?> Records
                                    </span>
                                </div>
                                <div class="text-muted small mt-1">
                                    Wipes all timestamped system audit logs, login history, and stage transition records.
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-2">
                        <span class="small text-muted" id="selectedCountMsg">No categories selected</span>
                        <button type="button" class="btn btn-danger fw-semibold px-4" onclick="confirmSelectiveClean()">
                            <i class="bi bi-trash3 me-1"></i> Clean Selected Data
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Quick Re-seed Demonstration Data Card -->
        <div class="card shadow-sm border-0 border-start border-4 border-info">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold text-dark mb-1">
                            <i class="bi bi-arrow-repeat text-info me-2"></i>Need Demonstration Data Again?
                        </h6>
                        <p class="small text-muted mb-0">
                            If you ever want to restore clean, realistic NDRI sample proposals and projects after testing, you can re-seed standard demonstration records in one click.
                        </p>
                    </div>
                    <form method="POST" action="<?= url('/joint-director/clean-database.php') ?>" onsubmit="return confirm('Restore standard sample test proposals, projects, and baseline accounts?');">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="reseed_samples">
                        <button type="submit" class="btn btn-outline-info btn-sm fw-semibold">
                            <i class="bi bi-database-fill-down me-1"></i> Re-seed Sample Data
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Instant Summary & Full One-Click Reset -->
    <div class="col-lg-4">
        <!-- Live Data Summary Card -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark">
                    <i class="bi bi-pie-chart-fill text-primary me-2"></i>Current Database Status
                </h6>
            </div>
            <div class="card-body p-3">
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span>Research Proposals:</span>
                        <strong class="text-dark"><?= $counts['proposals'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span>Active Projects:</span>
                        <strong class="text-dark"><?= $counts['projects'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span>Progress Reports:</span>
                        <strong class="text-dark"><?= $counts['progress_reports'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span>IRC Meetings:</span>
                        <strong class="text-dark"><?= $counts['irc_meetings'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span>Faculty / Scientists:</span>
                        <strong class="text-dark"><?= $counts['non_admin_users'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span>Joint Director Admin:</span>
                        <strong class="text-success"><i class="bi bi-shield-check me-1"></i>Protected</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span>Official ICAR Divisions:</span>
                        <strong class="text-success"><?= $counts['departments'] ?> Divisions (Fixed)</strong>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Danger Zone: One-Click Full Clean -->
        <div class="card shadow-sm border-danger border-2">
            <div class="card-header bg-danger text-white py-3">
                <h6 class="m-0 fw-bold">
                    <i class="bi bi-exclamation-octagon-fill me-2"></i>One-Click Clean Everything
                </h6>
            </div>
            <div class="card-body p-4">
                <p class="small text-secondary mb-3">
                    Want to reset the entire database to a completely clean slate in 1 click? This removes all proposals, projects, reports, meetings, and faculty accounts at once.
                </p>
                <div class="p-2 mb-3 bg-light rounded border small">
                    <div class="text-success fw-bold"><i class="bi bi-check-circle-fill me-1"></i>What remains safe:</div>
                    <ul class="mb-0 ps-3 text-muted">
                        <li>Your Joint Director login credentials</li>
                        <li>All 14 NDRI official division configurations</li>
                    </ul>
                </div>
                <form id="fullResetForm" method="POST" action="<?= url('/joint-director/clean-database.php') ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="clean_all">
                    <button type="button" class="btn btn-outline-danger w-100 fw-bold py-2" onclick="confirmFullReset()">
                        <i class="bi bi-radioactive me-1"></i> One-Click Full Reset
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Confirm Selective Clean -->
<div class="modal fade" id="confirmSelectiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Database Cleanup
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-2">You are about to permanently remove records for the following selected categories:</p>
                <ul id="selectedCategoriesList" class="fw-semibold text-danger mb-3"></ul>
                <div class="alert alert-warning small mb-0">
                    <i class="bi bi-shield-exclamation me-1"></i> This action is irreversible. Are you sure you wish to proceed?
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm fw-bold px-3" onclick="submitSelectiveClean()">
                    <i class="bi bi-trash3 me-1"></i> Yes, Clean Selected
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Confirm Full Reset -->
<div class="modal fade" id="confirmFullResetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-radioactive me-2"></i>Confirm Complete Institute Reset
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-danger fw-bold mb-2">WARNING: This will wipe all test data from the entire application!</p>
                <p class="small text-muted mb-3">
                    All proposals, projects, progress reports, IRC agendas, and scientist accounts will be permanently deleted. Your Joint Director account will remain active.
                </p>
                <label for="confirmText" class="form-label small fw-semibold text-dark">Type <strong>RESET</strong> below to confirm:</label>
                <input type="text" id="confirmText" class="form-control" placeholder="RESET" autocomplete="off">
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm fw-bold px-3" id="btnExecuteFullReset" onclick="executeFullReset()" disabled>
                    <i class="bi bi-trash3-fill me-1"></i> Permanently Reset Database
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function updateCountMsg() {
    const checked = document.querySelectorAll('.category-checkbox:checked').length;
    const msg = document.getElementById('selectedCountMsg');
    if (checked === 0) {
        msg.textContent = 'No categories selected';
        msg.className = 'small text-muted';
    } else {
        msg.textContent = checked + ' categor' + (checked === 1 ? 'y' : 'ies') + ' selected for cleanup';
        msg.className = 'small text-danger fw-bold';
    }
}

function toggleAllCheckboxes(state) {
    document.querySelectorAll('.category-checkbox').forEach(cb => {
        cb.checked = state;
    });
    updateCountMsg();
}

document.querySelectorAll('.category-checkbox').forEach(cb => {
    cb.addEventListener('change', updateCountMsg);
});

function confirmSelectiveClean() {
    const checkedBoxes = document.querySelectorAll('.category-checkbox:checked');
    if (checkedBoxes.length === 0) {
        alert('Please select at least one checkbox category to clean.');
        return;
    }

    const list = document.getElementById('selectedCategoriesList');
    list.innerHTML = '';
    checkedBoxes.forEach(cb => {
        const label = cb.closest('label').querySelector('strong').textContent.trim();
        const li = document.createElement('li');
        li.textContent = label;
        list.appendChild(li);
    });

    const modal = new bootstrap.Modal(document.getElementById('confirmSelectiveModal'));
    modal.show();
}

function submitSelectiveClean() {
    document.getElementById('cleanSelectiveForm').submit();
}

function confirmFullReset() {
    document.getElementById('confirmText').value = '';
    document.getElementById('btnExecuteFullReset').disabled = true;
    const modal = new bootstrap.Modal(document.getElementById('confirmFullResetModal'));
    modal.show();
}

document.getElementById('confirmText').addEventListener('input', function() {
    const val = this.value.trim().toUpperCase();
    document.getElementById('btnExecuteFullReset').disabled = (val !== 'RESET');
});

function executeFullReset() {
    document.getElementById('fullResetForm').submit();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
