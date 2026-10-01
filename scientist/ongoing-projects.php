<?php
/**
 * Research Proposal and Project Management System
 * Scientist - Ongoing Projects Progress Report Submission
 * NDRI PRIME
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role(ROLE_SCIENTIST);

$pageTitle = 'Submit Ongoing Project Progress Report';
$currentUser = current_user();
$userId = current_user_id();
$db = get_db();

// Check if editing an existing draft/ongoing proposal
$editProposalId = (int)($_GET['id'] ?? ($_GET['edit_id'] ?? ($_POST['proposal_id'] ?? 0)));
$editProposal = null;
$isEditMode = false;
$existingDocs = [];

if ($editProposalId > 0) {
    $stmtEdit = $db->prepare("
        SELECT p.*, d.department_name, d.department_code
        FROM proposals p
        LEFT JOIN departments d ON p.department_id = d.id
        WHERE p.id = ?
    ");
    $stmtEdit->execute([$editProposalId]);
    $editProposal = $stmtEdit->fetch();

    if (!$editProposal) {
        flash('danger', 'Ongoing project proposal record not found.');
        header("Location: " . url("/scientist/proposals.php?category=ongoing"));
        exit;
    }

    // If it is not an ongoing proposal, redirect to appropriate editor
    if (($editProposal['proposal_category'] ?? '') === 'new') {
        header("Location: " . url("/scientist/edit-proposal.php?id={$editProposalId}"));
        exit;
    } elseif (($editProposal['proposal_category'] ?? '') === 'completed') {
        header("Location: " . url("/scientist/create-completed-proposal.php?id={$editProposalId}"));
        exit;
    }

    // Verify edit permissions
    if (!can_edit_proposal($editProposal, $userId)) {
        flash('danger', 'This ongoing progress report is locked and cannot be edited in its current status (' . e($editProposal['current_status']) . ').');
        header("Location: " . url("/scientist/proposal-details.php?id={$editProposalId}"));
        exit;
    }

    $isEditMode = true;
    $pageTitle = 'Edit Ongoing Project Progress Report - ' . $editProposal['proposal_number'];

    // Fetch existing documents
    $stmtDocs = $db->prepare("SELECT * FROM proposal_documents WHERE proposal_id = ? ORDER BY id ASC");
    $stmtDocs->execute([$editProposalId]);
    $existingDocs = $stmtDocs->fetchAll();
}

// Fetch department
$stmt = $db->prepare("SELECT * FROM departments WHERE id = ?");
$stmt->execute([$currentUser['department_id'] ?? 1]);
$department = $stmt->fetch() ?: ['id' => 1, 'department_code' => 'AGB', 'department_name' => 'Animal Genetics & Breeding Division'];

// Fetch all active/ongoing projects belonging to this scientist
$userEmail = $currentUser['email'] ?? '';
$stmtPrj = $db->prepare("
    SELECT p.*, pr.title as proposal_title, pr.institute_priority_area, pr.national_priority_area, pr.trl_level,
           d.department_name, d.department_code, u.name as scientist_name, u.designation as scientist_designation
    FROM projects p
    LEFT JOIN proposals pr ON p.proposal_id = pr.id
    LEFT JOIN departments d ON p.department_id = d.id
    LEFT JOIN users u ON p.scientist_id = u.id
    WHERE (p.scientist_id = ? OR p.id IN (
        SELECT project_id FROM progress_reports WHERE submitted_by = ?
    ) OR p.proposal_id IN (
        SELECT proposal_id FROM proposal_co_pis WHERE email = ?
    )) AND p.project_status = 'Active'
    ORDER BY p.id DESC
");
$stmtPrj->execute([$userId, $userId, $userEmail]);
$ongoingProjects = $stmtPrj->fetchAll();

foreach ($ongoingProjects as &$prj) {
    $prj['copis'] = get_project_copis((int)$prj['id']);
    if (empty($prj['copis'])) {
        $prj['copis'] = get_proposal_copis((int)($prj['proposal_id'] ?? 0), (int)$prj['id'], (string)($prj['project_number'] ?? ''));
    }
}
unset($prj);

// Check if a project is pre-selected via query string or edit mode
$selectedProjectId = (int)($_GET['project_id'] ?? ($_POST['project_id'] ?? 0));
if ($isEditMode && $editProposal) {
    $selectedProjectId = (int)($editProposal['linked_project_id'] ?: 0);
}
if (!$selectedProjectId && !empty($ongoingProjects)) {
    $selectedProjectId = (int)$ongoingProjects[0]['id'];
}

$selectedProject = null;
foreach ($ongoingProjects as $prj) {
    if ((int)$prj['id'] === $selectedProjectId) {
        $selectedProject = $prj;
        break;
    }
}

// Fallback: If project not in current user's ongoing list, fetch direct by ID
if (!$selectedProject && $selectedProjectId > 0) {
    $stmtDirect = $db->prepare("
        SELECT p.*, pr.title as proposal_title, pr.institute_priority_area, pr.national_priority_area, pr.trl_level,
               pr.funding_agency_type as prop_funding_agency_type, pr.yearly_budget as prop_yearly_budget,
               d.department_name, d.department_code, u.name as scientist_name, u.designation as scientist_designation
        FROM projects p
        LEFT JOIN proposals pr ON p.proposal_id = pr.id
        LEFT JOIN departments d ON p.department_id = d.id
        LEFT JOIN users u ON p.scientist_id = u.id
        WHERE p.id = ?
    ");
    $stmtDirect->execute([$selectedProjectId]);
    $selectedProject = $stmtDirect->fetch(PDO::FETCH_ASSOC);
}

// If editing a proposal whose project is not in active list (or project_number matches), synthesize project info
if (!$selectedProject && $editProposal) {
    foreach ($ongoingProjects as $prj) {
        if ($prj['project_number'] === $editProposal['project_number']) {
            $selectedProject = $prj;
            break;
        }
    }
    if (!$selectedProject) {
        $selectedProject = [
            'id' => (int)($editProposal['linked_project_id'] ?: 0),
            'project_number' => $editProposal['project_number'],
            'proposal_title' => $editProposal['title'],
            'project_type' => $editProposal['project_type'] ?: 'in_house',
            'funding_agency' => $editProposal['funding_agency'],
            'funding_agency_type' => $editProposal['funding_agency_type'] ?? 'National',
            'approved_budget' => (float)($editProposal['budget_allocated'] ?: $editProposal['proposed_budget']),
            'yearly_budget' => $editProposal['yearly_budget'] ?? null,
            'start_date' => $editProposal['proposed_start_date'],
            'end_date' => $editProposal['proposed_end_date'],
            'department_id' => $editProposal['department_id'],
            'department_name' => $editProposal['department_name'],
            'department_code' => $editProposal['department_code'],
            'scientist_id' => $editProposal['scientist_id'],
            'scientist_name' => $currentUser['name'],
            'scientist_designation' => $currentUser['designation'] ?? 'Scientist',
            'institute_priority_area' => $editProposal['institute_priority_area'],
            'national_priority_area' => $editProposal['national_priority_area'],
            'trl_level' => $editProposal['trl_level'],
        ];
    }
}

if ($selectedProject) {
    $selectedProject['copis'] = get_project_copis((int)$selectedProject['id']);
    if (empty($selectedProject['copis'])) {
        $selectedProject['copis'] = get_proposal_copis((int)($selectedProject['proposal_id'] ?? 0), (int)$selectedProject['id'], (string)($selectedProject['project_number'] ?? ''));
    }
    if (empty($selectedProject['funding_agency_type'])) {
        $selectedProject['funding_agency_type'] = $selectedProject['prop_funding_agency_type'] ?? 'National';
    }
    if (empty($selectedProject['yearly_budget']) && !empty($selectedProject['prop_yearly_budget'])) {
        $selectedProject['yearly_budget'] = $selectedProject['prop_yearly_budget'];
    }
}

$error = null;
$reportPeriodCycle = 'October / November Cycle (Mid-Term Review)';
$reportYear = date('Y') . '-' . (date('Y') + 1);
$progressReport = '';
$achievements = '';
$budgetUtilized = '';
$previousIrcAtr = '';
$outputOutcome = '';
$otherDetails = '';

// Pre-populate if in edit mode and GET request
if ($isEditMode && $editProposal && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $periodStr = $editProposal['progress_report_period'] ?? '';
    if (preg_match('/^(.*?)\s*\(([^)]+)\)$/', $periodStr, $matches)) {
        $reportPeriodCycle = trim($matches[1]);
        $reportYear = trim($matches[2]);
    } elseif (strpos($periodStr, 'October') !== false) {
        $reportPeriodCycle = 'October / November Cycle (Mid-Term Review)';
        $reportYear = trim(str_replace(['October / November Cycle (Mid-Term Review)', '(', ')'], '', $periodStr)) ?: (date('Y') . '-' . (date('Y') + 1));
    } elseif (strpos($periodStr, 'March') !== false) {
        $reportPeriodCycle = 'March / April Cycle (Annual Review)';
        $reportYear = trim(str_replace(['March / April Cycle (Annual Review)', '(', ')'], '', $periodStr)) ?: (date('Y') . '-' . (date('Y') + 1));
    } else {
        $reportPeriodCycle = 'October / November Cycle (Mid-Term Review)';
        $reportYear = date('Y') . '-' . (date('Y') + 1);
    }
    $progressReport = $editProposal['progress_report'] ?? '';
    $achievements = $editProposal['significant_achievements'] ?? '';
    $budgetUtilized = $editProposal['budget_utilized'] ?? '';
    $previousIrcAtr = $editProposal['previous_irc_atr'] ?? '';
    $outputOutcome = $editProposal['output_outcome'] ?? '';
    $otherDetails = $editProposal['other_details'] ?? '';
    $submissionRemarks = $editProposal['submission_remarks'] ?? '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportPeriodCycle = $_POST['report_period_cycle'] ?? $reportPeriodCycle;
    $reportYear = $_POST['report_year'] ?? $reportYear;
    $progressReport = $_POST['progress_report'] ?? '';
    $achievements = $_POST['significant_achievements'] ?? '';
    $budgetUtilized = $_POST['budget_utilized'] ?? '';
    $previousIrcAtr = $_POST['previous_irc_atr'] ?? '';
    $outputOutcome = $_POST['output_outcome'] ?? '';
    $otherDetails = $_POST['other_details'] ?? '';
    $submissionRemarks = $_POST['submission_remarks'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $submissionAction = $_POST['submit_action'] ?? 'draft';
    $targetStatus = ($submissionAction === 'submit') ? STATUS_SUBMITTED_HOD : STATUS_DRAFT;

    $projectId = (int)($_POST['project_id'] ?? ($selectedProject['id'] ?? 0));
    $reportPeriodCycle = trim(sanitize($_POST['report_period_cycle'] ?? ''));
    $reportYear = trim(sanitize($_POST['report_year'] ?? ''));
    $progressReport = trim(sanitize($_POST['progress_report'] ?? ''));
    $achievements = trim(sanitize($_POST['significant_achievements'] ?? ''));
    $budgetUtilizedVal = (float)($_POST['budget_utilized'] ?? 0);
    $previousIrcAtr = trim(sanitize($_POST['previous_irc_atr'] ?? ''));
    $outputOutcome = trim(sanitize($_POST['output_outcome'] ?? ''));
    $otherDetails = trim(sanitize($_POST['other_details'] ?? ''));
    $submissionRemarks = trim(sanitize($_POST['submission_remarks'] ?? ''));

    // Verify chosen project
    $matchedProject = $selectedProject;
    if (!$matchedProject && $projectId > 0) {
        $stmtFind = $db->prepare("
            SELECT p.*, pr.title as proposal_title, pr.institute_priority_area, pr.national_priority_area, pr.trl_level,
                   d.department_code, d.department_name
            FROM projects p
            LEFT JOIN proposals pr ON p.proposal_id = pr.id
            LEFT JOIN departments d ON p.department_id = d.id
            WHERE p.id = ? AND (p.scientist_id = ? OR p.id IN (SELECT project_id FROM progress_reports WHERE submitted_by = ?) OR p.proposal_id IN (SELECT proposal_id FROM proposal_co_pis WHERE email = ?))
        ");
        $stmtFind->execute([$projectId, $userId, $userId, $userEmail]);
        $matchedProject = $stmtFind->fetch();
    }

    if (!$matchedProject) {
        $error = "Please select a valid active ongoing project from your assigned list.";
    } elseif ($submissionAction === 'submit') {
        if (empty($reportPeriodCycle)) {
            $error = "Please select the Progress Reporting Cycle (October/November or March/April).";
        } elseif (empty($reportYear)) {
            $error = "Reporting Financial Year is mandatory.";
        } elseif (empty($progressReport)) {
            $error = "Executive Progress Report narrative is mandatory for submission to HOD.";
        } elseif (empty($achievements)) {
            $error = "Significant Achievements during this reporting period are mandatory for submission.";
        } elseif (empty($previousIrcAtr)) {
            $error = "Action Taken Report (ATR) on Recommendations of Previous IRC is mandatory (enter 'N/A' if not applicable).";
        } elseif (empty($outputOutcome)) {
            $error = "Deliverables, Output & Outcomes during this Cycle is mandatory.";
        } elseif (empty($otherDetails)) {
            $error = "Other Pertinent Details / Constraints / Next Target is mandatory (enter 'N/A' if not applicable).";
        } elseif (!isset($_POST['budget_utilized']) || trim((string)$_POST['budget_utilized']) === '') {
            $error = "Cumulative Budget Utilized to Date is mandatory.";
        }
    } else {
        // Draft saving: no mandatory fields required! Whatever the scientist filled should be saved.
        if (empty($reportPeriodCycle)) {
            $reportPeriodCycle = 'October / November Cycle (Mid-Term Review)';
        }
        if (empty($reportYear)) {
            $reportYear = date('Y') . '-' . (date('Y') + 1);
        }
    }

    if (empty($error)) {
        try {
            $db->beginTransaction();

            $deptCode = $matchedProject['department_code'] ?: 'NDRI';
            $fullPeriodString = "{$reportPeriodCycle} ({$reportYear})";
            $now = date('Y-m-d H:i:s');
            $budgetAllocated = (float)($matchedProject['approved_budget'] ?? 0);

            // Verify that this period's progress report has not already been submitted to Joint Director or approved by ICR/IRC
            $stmtChkExistingReport = $db->prepare("SELECT * FROM progress_reports WHERE project_id = ? AND (report_period = ? OR reporting_period = ?)");
            $stmtChkExistingReport->execute([(int)$matchedProject['id'], $fullPeriodString, $fullPeriodString]);
            $existingReport = $stmtChkExistingReport->fetch();
            if ($existingReport && !can_edit_progress_report($existingReport, $userId, (int)($currentUser['role_id'] ?? ROLE_SCIENTIST))) {
                throw new Exception("The progress report for '{$fullPeriodString}' has already been submitted to the Joint Director or approved by ICR/IRC and is locked against modifications.");
            }

            $stmtChkExistingProp = $db->prepare("SELECT * FROM proposals WHERE linked_project_id = ? AND (progress_report_period = ? OR progress_report_period LIKE ?)");
            $stmtChkExistingProp->execute([(int)$matchedProject['id'], $fullPeriodString, '%' . $fullPeriodString . '%']);
            $existingProp = $stmtChkExistingProp->fetch();
            if ($existingProp && (!$isEditMode || (int)$existingProp['id'] !== $editProposalId)) {
                if (!can_edit_proposal($existingProp, $userId)) {
                    throw new Exception("A progress report proposal for '{$fullPeriodString}' has already been submitted to the Joint Director or approved by ICR/IRC and cannot be modified.");
                }
            }

            if ($isEditMode && $editProposalId > 0) {
                if (!can_edit_proposal($editProposal, $userId)) {
                    throw new Exception("This ongoing progress report is locked and cannot be edited in its current status.");
                }
                // UPDATE existing proposal
                $proposalNumber = $editProposal['proposal_number'];
                $submittedAt = ($submissionAction === 'submit') ? $now : $editProposal['submitted_at'];

                $sqlUpdate = "UPDATE proposals SET
                    significant_achievements = ?,
                    budget_utilized = ?,
                    progress_report = ?,
                    progress_report_period = ?,
                    previous_irc_atr = ?,
                    output_outcome = ?,
                    other_details = ?,
                    submission_remarks = ?,
                    current_status = ?,
                    submitted_at = ?,
                    updated_at = ?
                WHERE id = ? AND scientist_id = ?";

                $stmtUpd = $db->prepare($sqlUpdate);
                $stmtUpd->execute([
                    $achievements,
                    $budgetUtilizedVal,
                    $progressReport,
                    $fullPeriodString,
                    $previousIrcAtr,
                    $outputOutcome,
                    $otherDetails,
                    (!empty($submissionRemarks) ? $submissionRemarks : ($editProposal['submission_remarks'] ?? null)),
                    $targetStatus,
                    $submittedAt,
                    $now,
                    $editProposalId,
                    $userId
                ]);
                $proposalId = $editProposalId;
            } else {
                // INSERT new ongoing proposal
                $proposalNumber = generate_proposal_number($deptCode);
                $submittedAt = ($submissionAction === 'submit') ? $now : null;
                $projectTitle = $matchedProject['proposal_title'] ?: "Project " . $matchedProject['project_number'];

                $sql = "INSERT INTO proposals (
                    proposal_number, project_type, funding_agency, proposal_category, project_number,
                    linked_project_id, title, scientist_id, department_id, institute_priority_area, national_priority_area,
                    trl_level, proposed_start_date, proposed_end_date, significant_achievements,
                    proposed_budget, budget_allocated, budget_utilized, progress_report, progress_report_period,
                    previous_irc_atr, output_outcome, other_details, submission_remarks, current_status, submitted_at, created_at, updated_at
                ) VALUES (?, ?, ?, 'ongoing', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmtIns = $db->prepare($sql);
                $stmtIns->execute([
                    $proposalNumber,
                    $matchedProject['project_type'] ?: 'in_house',
                    $matchedProject['funding_agency'] ?: null,
                    $matchedProject['project_number'],
                    $projectId,
                    $projectTitle,
                    $userId,
                    $matchedProject['department_id'] ?: $department['id'],
                    $matchedProject['institute_priority_area'] ?: 'A',
                    $matchedProject['national_priority_area'] ?: null,
                    $matchedProject['trl_level'] ?: 3,
                    $matchedProject['start_date'],
                    $matchedProject['end_date'],
                    $achievements,
                    $budgetAllocated,
                    $budgetAllocated,
                    $budgetUtilizedVal,
                    $progressReport,
                    $fullPeriodString,
                    $previousIrcAtr,
                    $outputOutcome,
                    $otherDetails,
                    (!empty($submissionRemarks) ? $submissionRemarks : null),
                    $targetStatus,
                    $submittedAt,
                    $now,
                    $now
                ]);
                $proposalId = (int)$db->lastInsertId();
            }

            if (!empty($submissionRemarks)) {
                add_proposal_comment($proposalId, $userId, ($isEditMode ? 'Scientist Progress Resubmission' : 'Scientist Progress Submission'), $submissionRemarks);
            }

            // Sync Co-PIs from matched project to this ongoing proposal
            try {
                $existingCopisCount = (int)$db->query("SELECT COUNT(*) FROM proposal_co_pis WHERE proposal_id = {$proposalId}")->fetchColumn();
                if ($existingCopisCount === 0) {
                    $projCopis = get_project_copis((int)$matchedProject['id']);
                    if (!empty($projCopis)) {
                        $insCoPi = $db->prepare("INSERT INTO proposal_co_pis (proposal_id, co_pi_name, institution, designation, email) VALUES (?, ?, ?, ?, ?)");
                        foreach ($projCopis as $cp) {
                            $insCoPi->execute([
                                $proposalId,
                                $cp['co_pi_name'],
                                $cp['institution'] ?: '',
                                $cp['designation'] ?: '',
                                $cp['email'] ?: ''
                            ]);
                        }
                    }
                }
            } catch (Throwable $eSyncCopis) {}

            // Sync with progress_reports table
            try {
                $stmtChkPrg = $db->prepare("SELECT id FROM progress_reports WHERE project_id = ? AND (report_period = ? OR reporting_period = ?)");
                $stmtChkPrg->execute([(int)$matchedProject['id'], $fullPeriodString, $fullPeriodString]);
                $prgId = $stmtChkPrg->fetchColumn();

                if ($prgId) {
                    $stmtPrgUpd = $db->prepare("
                        UPDATE progress_reports 
                        SET achievements = ?, progress_summary = ?, work_completed = ?,
                            budget_utilized = ?, budget_utilization = ?, review_status = ?,
                            submitted_at = COALESCE(submitted_at, ?), updated_at = ?
                        WHERE id = ?
                    ");
                    $stmtPrgUpd->execute([
                        $achievements, $progressReport, $progressReport,
                        $budgetUtilizedVal, $budgetUtilizedVal,
                        ($submissionAction === 'submit' ? 'Submitted' : 'Draft'),
                        $submittedAt, $now, $prgId
                    ]);
                } else {
                    $stmtPrgIns = $db->prepare("
                        INSERT INTO progress_reports (
                            project_id, report_period, reporting_period, achievements, progress_summary, work_completed,
                            budget_utilized, budget_utilization, review_status, submitted_by, submitted_at, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtPrgIns->execute([
                        (int)$matchedProject['id'],
                        $fullPeriodString,
                        $fullPeriodString,
                        $achievements,
                        $progressReport,
                        $progressReport,
                        $budgetUtilizedVal,
                        $budgetUtilizedVal,
                        ($submissionAction === 'submit' ? 'Submitted' : 'Draft'),
                        $userId,
                        $submittedAt,
                        $now,
                        $now
                    ]);
                }
            } catch (Throwable $pe) {}

            // Record Status History and Audit Log
            $historyComment = ($submissionAction === 'submit') 
                ? "Ongoing Project Progress Report for {$matchedProject['project_number']} ({$fullPeriodString}) submitted to Head of Department for review." 
                : ($isEditMode ? "Ongoing Project Progress Report draft updated." : "Ongoing Project Progress Report draft saved.");
            
            $oldStatus = $isEditMode ? ($editProposal['current_status'] ?? null) : null;
            record_status_history($proposalId, $oldStatus, $targetStatus, $userId, $currentUser['role_name'] ?? 'Scientist', $historyComment);
            
            $auditAction = ($submissionAction === 'submit') 
                ? 'ONGOING_REPORT_SUBMITTED' 
                : ($isEditMode ? 'ONGOING_REPORT_DRAFT_UPDATED' : 'ONGOING_REPORT_DRAFT');
            log_audit($userId, $auditAction, 'proposals', $proposalId, "Ongoing Progress Report {$proposalNumber} for project {$matchedProject['project_number']} status '{$targetStatus}'.");

            $db->commit();

            if ($submissionAction === 'submit') {
                flash('success', "Ongoing Project Progress Report ({$proposalNumber}) submitted successfully to Head of Department for IRC review.");
            } else {
                flash('info', $isEditMode 
                    ? "Ongoing Project Progress Report ({$proposalNumber}) draft updated successfully." 
                    : "Ongoing Project Progress Report ({$proposalNumber}) saved as draft.");
            }

            header("Location: " . url("/scientist/proposal-details.php?id={$proposalId}"));
            exit;

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Error saving progress report: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">On Going Projects</h3>
        <p class="text-muted small mb-0">Submit mandatory half-yearly progress reports for active research projects</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/scientist/projects.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-kanban me-1"></i> Active Projects Portfolio
        </a>
        <a href="<?= url("/scientist/proposals.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Proposals
        </a>
    </div>
</div>

<!-- Type Switcher Tabs -->
<ul class="nav nav-pills mb-4 p-1 bg-white border rounded shadow-xs" style="max-width: 660px;">
    <li class="nav-item flex-fill text-center">
        <a class="nav-link fw-semibold text-secondary py-2" href="<?= url('/scientist/create-proposal.php') ?>">
            <i class="bi bi-file-earmark-plus me-1"></i> New Proposal
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link active fw-semibold text-white py-2 shadow-sm" style="background-color: #1a365d;" href="<?= url('/scientist/ongoing-projects.php') ?>">
            <i class="bi bi-arrow-repeat me-1"></i> On Going Projects
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link fw-semibold text-secondary py-2" href="<?= url('/scientist/create-completed-proposal.php') ?>">
            <i class="bi bi-check2-circle me-1"></i> Completion Project
        </a>
    </li>
</ul>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-exclamation-octagon-fill me-2"></i> <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($isEditMode && $editProposal): ?>
    <div class="alert alert-info border-info shadow-sm mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="alert-heading fw-bold mb-1">
                <i class="bi bi-pencil-square me-2 text-primary"></i>Editing Ongoing Project Draft Progress Report: <?= e($editProposal['proposal_number']) ?>
            </h6>
            <div class="small text-muted">
                Project: <strong class="text-dark font-monospace"><?= e($editProposal['project_number']) ?></strong> &bull;
                Status: <span class="badge bg-secondary"><?= e($editProposal['current_status']) ?></span> &bull;
                Last Saved: <?= format_date($editProposal['updated_at'] ?? $editProposal['created_at'], 'd M Y, h:i A') ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url("/scientist/proposal-details.php?id=" . $editProposal['id']) ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-eye me-1"></i> View Details
            </a>
            <a href="<?= url("/scientist/proposals.php?category=ongoing") ?>" class="btn btn-sm btn-outline-dark">
                <i class="bi bi-arrow-left me-1"></i> Back to Ongoing Proposals
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Section 1: Active Projects Visual Selection -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-collection-play-fill text-primary me-2"></i> 1. Select Active Ongoing Project
        </h5>
        <?php if ($isEditMode): ?>
            <span class="badge bg-secondary">Project Selection Locked (Editing Draft)</span>
        <?php else: ?>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                <?= count($ongoingProjects) ?> Active Project<?= count($ongoingProjects) !== 1 ? 's' : '' ?> Found
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body bg-light-subtle p-3">
        <?php if (empty($ongoingProjects)): ?>
            <div class="text-center py-4 bg-white rounded border border-dashed p-4">
                <i class="bi bi-inbox text-muted display-6 d-block mb-2"></i>
                <h6 class="fw-bold text-dark">No Active Ongoing Projects Found</h6>
                <p class="text-muted small mb-3">You currently do not have any active research projects sanctioned by the Joint Director.</p>
                <a href="<?= url('/scientist/create-proposal.php') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-file-earmark-plus me-1"></i> Submit New Project Proposal
                </a>
            </div>
        <?php else: ?>
            <p class="text-muted small mb-3">
                Click an ongoing project below to automatically populate all institutional metadata, category, and budget details:
            </p>
            <div class="row g-3">
                <?php foreach ($ongoingProjects as $prj): 
                    $isSel = ($selectedProject && (int)$selectedProject['id'] === (int)$prj['id']);
                    $isFunding = (($prj['project_type'] ?? '') === 'funding_agency');
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 border transition-all cursor-pointer <?= $isSel ? 'border-primary shadow-sm bg-white ring-2 ring-primary' : 'border-secondary-subtle bg-white hover-shadow' ?>"
                             style="cursor: pointer; transition: 0.2s;"
                             onclick="selectOngoingProject(<?= (int)$prj['id'] ?>)">
                            <div class="card-body p-3 d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge font-monospace <?= $isFunding ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle' ?>">
                                        <?= e($prj['project_number']) ?>
                                    </span>
                                    <?php if ($isSel): ?>
                                        <span class="badge bg-primary text-white"><i class="bi bi-check-circle-fill me-1"></i> Selected</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border">Select</span>
                                    <?php endif; ?>
                                </div>
                                <h6 class="fw-bold text-dark mb-2 text-truncate-2" style="line-height: 1.4; min-height: 2.8rem;">
                                    <?= e($prj['proposal_title'] ?: 'Research Project #' . $prj['id']) ?>
                                </h6>
                                <div class="mb-2">
                                    <?php if ($isFunding): ?>
                                        <span class="badge bg-light text-dark border extra-small">
                                            <i class="bi bi-bank2 text-success me-1"></i> <?= e($prj['funding_agency'] ?: 'Externally Funded') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border extra-small">
                                            <i class="bi bi-house-door-fill text-primary me-1"></i> NDRI In-house Project
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($prj['copis'])): ?>
                                    <div class="extra-small text-muted mb-2 text-truncate" title="<?= e(implode(', ', array_column($prj['copis'], 'co_pi_name'))) ?>">
                                        <i class="bi bi-people-fill text-primary me-1"></i><strong>Co-PIs:</strong> <?= e(implode(', ', array_column($prj['copis'], 'co_pi_name'))) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-auto pt-2 border-top text-muted extra-small d-flex justify-content-between">
                                    <span><i class="bi bi-cash me-1"></i> <?= format_currency($prj['approved_budget']) ?></span>
                                    <span><i class="bi bi-calendar3 me-1"></i> <?= format_date($prj['end_date']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($selectedProject): 
    $isFunding = (($selectedProject['project_type'] ?? '') === 'funding_agency');
    $durationMonths = 0;
    if (!empty($selectedProject['start_date']) && !empty($selectedProject['end_date'])) {
        $d1 = new DateTime($selectedProject['start_date']);
        $d2 = new DateTime($selectedProject['end_date']);
        $interval = $d1->diff($d2);
        $durationMonths = ($interval->y * 12) + $interval->m;
    }
?>
<form method="POST" action="<?= url("/scientist/ongoing-projects.php" . ($isEditMode ? "?id=" . $editProposalId : "")) ?>" id="ongoing-form" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="project_id" id="hidden_project_id" value="<?= (int)$selectedProject['id'] ?>">
    <?php if ($isEditMode): ?>
        <input type="hidden" name="proposal_id" value="<?= (int)$editProposalId ?>">
    <?php endif; ?>

    <!-- Section 2: Autofilled Details (Pre-Known, No Questions Asked) -->
    <div class="detail-section-card mb-4 shadow-sm">
        <div class="detail-section-header d-flex justify-content-between align-items-center">
            <div><i class="bi bi-shield-check text-primary me-1"></i> 2. Project Profile (Autofilled from System Records)</div>
            <span class="badge bg-success-subtle text-success border border-success-subtle">
                <i class="bi bi-lock-fill me-1"></i> Verified Active Project
            </span>
        </div>
        <div class="detail-section-body bg-light-subtle">
            <div class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center small">
                <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                <div>
                    <strong>Category Already Verified:</strong> The project category (In-house or Externally Funded), assigned Project No., and sanction budget are automatically retrieved from institutional records.
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label small fw-semibold text-secondary">Project Title</label>
                    <input type="text" class="form-control fw-bold bg-white" value="<?= e($selectedProject['proposal_title'] ?: 'Research Project #' . $selectedProject['id']) ?>" readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-secondary">Assigned Project No. / Code</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-hash text-primary"></i></span>
                        <input type="text" class="form-control font-monospace fw-bold bg-white text-primary" value="<?= e($selectedProject['project_number']) ?>" readonly>
                    </div>
                </div>

                <?php $agencyDetails = get_funding_agency_details($selectedProject); ?>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-secondary">Project Category & Funding</label>
                    <div class="p-2 px-3 bg-white rounded border d-flex flex-column justify-content-center" style="min-height: 58px;">
                        <div><?= $agencyDetails['badge_html'] ?></div>
                        <?php if ($agencyDetails['is_external'] && !empty($agencyDetails['agency_name'])): ?>
                            <div class="text-muted extra-small mt-1">Agency: <strong><?= e($agencyDetails['agency_name']) ?></strong> (<?= e($agencyDetails['agency_type']) ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-secondary">Division / Department</label>
                    <input type="text" class="form-control bg-white" value="<?= e($selectedProject['department_name'] ?: $department['department_name']) ?>" readonly>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-secondary">Principal Investigator</label>
                    <input type="text" class="form-control bg-white" value="<?= e($selectedProject['scientist_name'] ?: $currentUser['name']) ?> (<?= e($selectedProject['scientist_designation'] ?: $currentUser['designation']) ?>)" readonly>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-secondary">Project Duration</label>
                    <div class="p-2 px-3 bg-white rounded border text-dark small font-monospace">
                        <i class="bi bi-calendar-range text-secondary me-1"></i>
                        <?= format_date($selectedProject['start_date']) ?> &rarr; <?= format_date($selectedProject['end_date']) ?>
                        <span class="badge bg-light text-secondary border ms-1"><?= $durationMonths ?> Months</span>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-secondary">Total Sanctioned Budget Allocated (₹)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-currency-rupee text-success"></i></span>
                        <input type="text" class="form-control fw-bold bg-white text-dark font-monospace" id="display_budget_allocated" value="<?= number_format((float)$selectedProject['approved_budget'], 2) ?>" readonly>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-secondary">Institute Priority Area</label>
                    <input type="text" class="form-control bg-white" value="Program <?= e($selectedProject['institute_priority_area'] ?: 'A') ?>" readonly>
                </div>

                <!-- Year-wise Budget Allocation Breakdown -->
                <div class="col-12 mt-2 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold text-dark m-0">
                            <i class="bi bi-calendar3-range text-success me-1"></i> Year-wise Sanctioned Budget Formulation
                        </label>
                        <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace">
                            Total Approved: <?= format_currency((float)$selectedProject['approved_budget']) ?>
                        </span>
                    </div>
                    <?= render_yearly_budget_html($selectedProject['yearly_budget'] ?? null, (float)$selectedProject['approved_budget']) ?>
                </div>

                <!-- Co-Principal Investigators of this ongoing project -->
                <div class="col-12 mt-2 pt-3 border-top">
                    <label class="form-label small fw-bold text-dark d-flex align-items-center justify-content-between mb-2">
                        <span><i class="bi bi-people-fill text-primary me-1"></i> Co-Principal Investigators (Co-PIs) on this Project</span>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" id="copis_count_badge">
                            <?= count($selectedProject['copis'] ?? []) ?> Co-PI<?= count($selectedProject['copis'] ?? []) !== 1 ? 's' : '' ?> Registered
                        </span>
                    </label>
                    <div id="ongoing_copis_list">
                        <?php if (!empty($selectedProject['copis'])): ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($selectedProject['copis'] as $cp): ?>
                                    <div class="badge bg-white text-dark border p-2 text-start d-inline-flex align-items-center gap-2 shadow-xs">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width: 34px; height: 34px; font-size: 0.85rem; flex-shrink: 0;">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark" style="font-size: 0.88rem;">
                                                <i class="bi bi-patch-check-fill text-success me-1"></i><?= e($cp['co_pi_name']) ?>
                                            </div>
                                            <div class="extra-small text-muted">
                                                <?= e($cp['designation'] ?: 'Co-PI') ?><?= !empty($cp['institution']) ? ' &bull; ' . e($cp['institution']) : '' ?>
                                            </div>
                                            <?php if (!empty($cp['email'])): ?>
                                                <div class="extra-small text-secondary"><i class="bi bi-envelope me-1"></i><?= e($cp['email']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-3 bg-white rounded border text-muted small">
                                <i class="bi bi-info-circle me-1 text-primary"></i> No Co-Principal Investigators were registered in the original approved project charter.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Progress Report Requirements (Twice a Year Cycle) -->
    <div class="detail-section-card mb-4 shadow-sm">
        <div class="detail-section-header d-flex justify-content-between align-items-center">
            <div><i class="bi bi-calendar2-check text-warning me-1"></i> 3. Progress Reporting Cycle & Schedule</div>
            <span class="badge bg-warning-subtle text-dark border border-warning-subtle">
                Mandatory: 2 Reports / Year
            </span>
        </div>
        <div class="detail-section-body">
            <div class="p-3 bg-light rounded border border-warning-subtle mb-3">
                <div class="d-flex align-items-center mb-2">
                    <i class="bi bi-info-circle-fill text-warning fs-5 me-2"></i>
                    <h6 class="fw-bold text-dark mb-0">NDRI Half-Yearly Research Reporting Mandate</h6>
                </div>
                <p class="text-muted small mb-0">
                    Per Institute Research Council (IRC) regulations, all Principal Investigators must submit two comprehensive progress reports per year:
                    <strong>(1) October / November Cycle</strong> for mid-term review, and <strong>(2) March / April Cycle</strong> for annual appraisal.
                </p>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="report_period_cycle" class="form-label small fw-bold text-dark">
                        Reporting Cycle Period <span class="text-danger">*</span>
                    </label>
                    <select name="report_period_cycle" id="report_period_cycle" class="form-select fw-semibold" required>
                        <option value="October / November Cycle (Mid-Term Review)" <?= ($reportPeriodCycle === 'October / November Cycle (Mid-Term Review)') ? 'selected' : '' ?>>
                            🍂 October / November Cycle (Mid-Term IRC Review)
                        </option>
                        <option value="March / April Cycle (Annual Review)" <?= ($reportPeriodCycle === 'March / April Cycle (Annual Review)') ? 'selected' : '' ?>>
                            🌸 March / April Cycle (Annual / Year-End IRC Review)
                        </option>
                    </select>
                    <div class="form-text text-muted extra-small">Select the applicable half-yearly IRC review session.</div>
                </div>

                <div class="col-md-6">
                    <label for="report_year" class="form-label small fw-bold text-dark">
                        Reporting Financial Year <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="report_year" id="report_year" class="form-control font-monospace fw-semibold" value="<?= e($reportYear) ?>" placeholder="e.g. 2026-2027" required>
                    <div class="form-text text-muted extra-small">Specify the financial or academic cycle year.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 4: Technical Progress Report Dossier -->
    <div class="detail-section-card mb-4 shadow-sm">
        <div class="detail-section-header d-flex justify-content-between align-items-center">
            <div><i class="bi bi-file-earmark-medical text-primary me-1"></i> 4. Technical Progress Report & Work Completed</div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Reporting Period Summary</span>
        </div>
        <div class="detail-section-body">
            <div class="mb-4">
                <label for="progress_report" class="form-label small fw-bold text-dark">
                    Executive Progress Report (Period Summary) <span class="text-danger">*</span>
                </label>
                <textarea name="progress_report" id="progress_report" class="form-control font-monospace" rows="6" placeholder="Provide a concise, comprehensive narrative of the scientific progress, experimental work, methodologies applied, and intermediate observations during this cycle..." required><?= e($progressReport) ?></textarea>
                <div class="form-text text-muted extra-small">
                    Highlight experimental series executed, animal/microbial cohorts sampled, analytical assays run, and status against scheduled milestones.
                </div>
            </div>

            <div class="mb-4">
                <label for="significant_achievements" class="form-label small fw-bold text-dark">
                    Significant Achievements during this Reporting Period <span class="text-danger">*</span>
                </label>
                <textarea name="significant_achievements" id="significant_achievements" class="form-control font-monospace" rows="5" placeholder="Bullet points detailing prominent scientific findings, validated biomarkers, improved protocols, genomic markers, or pilot batches completed..." required><?= e($achievements) ?></textarea>
                <div class="form-text text-muted extra-small">List major breakthroughs, verified milestones, or significant technical outcomes.</div>
            </div>

            <div class="mb-4">
                <label for="previous_irc_atr" class="form-label small fw-bold text-dark">
                    Action Taken Report (ATR) on Recommendations of Previous IRC <span class="text-danger">*</span>
                </label>
                <textarea name="previous_irc_atr" id="previous_irc_atr" class="form-control font-monospace" rows="3" placeholder="If previous IRC committee provided specific suggestions, directives, or protocol modifications, state compliance actions taken... (Enter 'N/A' if not applicable)" required><?= e($previousIrcAtr) ?></textarea>
                <div class="form-text text-muted extra-small">Compliance report responding to prior IRC recommendations (Enter 'N/A' if not applicable).</div>
            </div>

            <div class="mb-4">
                <label for="output_outcome" class="form-label small fw-bold text-dark">
                    Deliverables, Output & Outcomes during this Cycle <span class="text-danger">*</span>
                </label>
                <textarea name="output_outcome" id="output_outcome" class="form-control font-monospace" rows="4" placeholder="Publications submitted/accepted, patents filed, technologies developed, sequences deposited in NCBI, industry interactions, workshops conducted..." required><?= e($outputOutcome) ?></textarea>
                <div class="form-text text-muted extra-small">Tangible outputs generated in this reporting timeframe.</div>
            </div>

            <div class="mb-3">
                <label for="other_details" class="form-label small fw-bold text-dark">
                    Other Pertinent Details / Constraints / Next Target <span class="text-danger">*</span>
                </label>
                <textarea name="other_details" id="other_details" class="form-control font-monospace" rows="3" placeholder="Facilities, specialized reagent procurement, student thesis integration, or work plan for subsequent reporting cycle... (Enter 'N/A' if not applicable)" required><?= e($otherDetails) ?></textarea>
                <div class="form-text text-muted extra-small">Any operational constraints, equipment calibration, or prospective next-cycle plans (Enter 'N/A' if not applicable).</div>
            </div>

            <?php if (!empty($existingDocs)): ?>
                <div class="mb-3 p-3 bg-white border rounded">
                    <label class="form-label small fw-bold text-dark d-block mb-2">
                        <i class="bi bi-paperclip text-success me-1"></i> Attached Documents on Record
                    </label>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($existingDocs as $doc): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <div>
                                    <i class="bi bi-file-earmark-pdf text-danger me-2"></i>
                                    <span class="fw-semibold"><?= e($doc['file_name']) ?></span>
                                    <span class="text-muted extra-small ms-2">(<?= format_file_size((int)($doc['file_size'] ?? 0)) ?> &bull; <?= format_date($doc['created_at']) ?>)</span>
                                </div>
                                <a href="<?= url($doc['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2" title="View Document">
                                    <i class="bi bi-box-arrow-up-right me-1"></i> View
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Section 5: Financial Statement for Reporting Period -->
    <div class="detail-section-card mb-4 shadow-sm">
        <div class="detail-section-header">
            <i class="bi bi-wallet2 text-success me-1"></i> 5. Financial Statement (Expenditure & Budget Utilization)
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Total Sanctioned Budget Allocated (₹)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-currency-rupee text-success"></i></span>
                        <input type="text" class="form-control fw-bold bg-light font-monospace" id="calc_allocated" value="<?= (float)$selectedProject['approved_budget'] ?>" readonly>
                    </div>
                </div>

                <div class="col-md-6">
                    <label for="budget_utilized" class="form-label small fw-bold text-dark">
                        Cumulative Budget Utilized to Date (₹) <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-graph-up-arrow text-primary"></i></span>
                        <input type="number" step="0.01" min="0" max="<?= (float)$selectedProject['approved_budget'] * 1.5 ?>" name="budget_utilized" id="budget_utilized" class="form-control font-monospace fw-bold" placeholder="e.g. 850000.00" value="<?= e($budgetUtilized) ?>" required oninput="calcUtilization()">
                    </div>
                    <div class="form-text text-muted extra-small">Total expenditure incurred under consumables, contingency, equipment, travel, etc.</div>
                </div>

                <div class="col-12 mt-3">
                    <div class="p-3 bg-light rounded border">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-dark">Budget Utilization Rate:</span>
                            <span class="fw-bold font-monospace text-primary" id="utilization_percent_badge">0.0%</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-primary" id="utilization_progress_bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scientist's Remarks / Covering Note for HOD -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
            <i class="bi bi-chat-left-quote text-primary me-2 fs-5"></i>
            <h6 class="fw-bold text-dark m-0">Scientist's Progress Report Remarks / Note for Reviewers (HOD & Directorate)</h6>
        </div>
        <div class="card-body">
            <label class="form-label small fw-semibold text-secondary">Optional Remarks / Note for Head of Department</label>
            <textarea name="submission_remarks" class="form-control" rows="2" placeholder="Provide any special remarks, constraints faced, highlights of the reporting cycle, or notes for the Head of Department and Joint Director..."><?= e($submissionRemarks ?? '') ?></textarea>
            <div class="form-text extra-small text-muted">These comments and remarks will be prominently visible to the HOD when screening and reviewing your progress report.</div>
        </div>
    </div>

    <!-- Submission Action Bar -->
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center text-muted small">
                <i class="bi bi-diagram-3-fill text-primary me-2 fs-5"></i>
                <div>
                    <strong>Standard Institutional Workflow:</strong>
                    Scientist &rarr; Head of Department (HOD) &rarr; Joint Director (Directorate) &rarr; IRC Council Approval.
                </div>
            </div>
            <div class="d-flex gap-2">
                <?php if ($isEditMode): ?>
                    <a href="<?= url("/scientist/proposals.php?category=ongoing") ?>" class="btn btn-outline-secondary px-3">
                        <i class="bi bi-x-circle me-1"></i> Discard Changes
                    </a>
                <?php endif; ?>
                <button type="submit" name="submit_action" value="draft" class="btn btn-outline-secondary px-3" formnovalidate onclick="window.isDraftAction = true;">
                    <i class="bi bi-save me-1"></i> <?= $isEditMode ? 'Update Draft' : 'Save as Draft' ?>
                </button>
                <button type="submit" name="submit_action" value="submit" class="btn btn-primary px-4 shadow-sm" style="background-color: #1a365d; border-color: #1a365d;" onclick="window.isDraftAction = false;">
                    <i class="bi bi-send-fill me-1"></i> Submit Progress Report to HOD
                </button>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>

<script>
function selectOngoingProject(projectId) {
    window.location.href = '<?= url("/scientist/ongoing-projects.php") ?>?project_id=' + projectId;
}

function calcUtilization() {
    const allocatedInput = document.getElementById('calc_allocated');
    const utilizedInput = document.getElementById('budget_utilized');
    const badge = document.getElementById('utilization_percent_badge');
    const bar = document.getElementById('utilization_progress_bar');

    if (!allocatedInput || !utilizedInput) return;

    const allocated = parseFloat(allocatedInput.value) || 0;
    const utilized = parseFloat(utilizedInput.value) || 0;

    if (allocated > 0) {
        let percent = (utilized / allocated) * 100;
        let displayPercent = Math.min(percent, 100).toFixed(1);
        badge.textContent = percent.toFixed(1) + '%';
        bar.style.width = displayPercent + '%';

        if (percent > 100) {
            bar.className = 'progress-bar bg-danger';
            badge.className = 'fw-bold font-monospace text-danger';
        } else if (percent > 85) {
            bar.className = 'progress-bar bg-warning';
            badge.className = 'fw-bold font-monospace text-warning';
        } else {
            bar.className = 'progress-bar bg-primary';
            badge.className = 'fw-bold font-monospace text-primary';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    calcUtilization();

    const form = document.querySelector('form[method="POST"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            const submitBtn = e.submitter || document.activeElement;
            const isDraft = window.isDraftAction || (submitBtn && (submitBtn.value === 'draft' || submitBtn.getAttribute('value') === 'draft'));
            if (isDraft) {
                form.querySelectorAll('[required]').forEach(el => el.removeAttribute('required'));
                return true;
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
