<?php
/**
 * Research Proposal and Project Management System
 * Scientist - Completed Proposal Submission Form
 * NDRI PRIME
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD]);

$pageTitle = 'Completion Project';
$currentUser = current_user();
$userId = current_user_id();
$isHod = (current_user_role_id() === ROLE_HOD);
$db = get_db();

// Fetch department
$stmt = $db->prepare("SELECT * FROM departments WHERE id = ?");
$stmt->execute([$currentUser['department_id'] ?? 1]);
$department = $stmt->fetch() ?: ['id' => 1, 'department_code' => 'AGB', 'department_name' => 'Animal Genetics & Breeding Division'];

// Fetch all departments
$allDepts = $db->query("SELECT * FROM departments ORDER BY department_name ASC")->fetchAll();

// Fetch active projects for this scientist from system DB
if ($isHod) {
    $stmtActive = $db->prepare("
        SELECT p.*, pr.title as proposal_title, pr.institute_priority_area, pr.national_priority_area, pr.trl_level, pr.objectives,
               pr.funding_agency_type as prop_funding_agency_type, pr.yearly_budget as prop_yearly_budget,
               d.department_name, d.department_code, u.name as scientist_name, u.designation as scientist_designation
        FROM projects p
        LEFT JOIN proposals pr ON p.proposal_id = pr.id
        LEFT JOIN departments d ON p.department_id = d.id
        LEFT JOIN users u ON p.scientist_id = u.id
        WHERE (p.scientist_id = ? OR p.department_id = ? OR p.id IN (SELECT project_id FROM progress_reports WHERE submitted_by = ?) OR p.proposal_id IN (SELECT proposal_id FROM proposal_co_pis WHERE email = ?))
          AND p.project_status = 'Active'
        ORDER BY p.id DESC
    ");
    $stmtActive->execute([$userId, (int)($currentUser['department_id'] ?? 1), $userId, $currentUser['email'] ?? '']);
} else {
    $stmtActive = $db->prepare("
        SELECT p.*, pr.title as proposal_title, pr.institute_priority_area, pr.national_priority_area, pr.trl_level, pr.objectives,
               pr.funding_agency_type as prop_funding_agency_type, pr.yearly_budget as prop_yearly_budget,
               d.department_name, d.department_code, u.name as scientist_name, u.designation as scientist_designation
        FROM projects p
        LEFT JOIN proposals pr ON p.proposal_id = pr.id
        LEFT JOIN departments d ON p.department_id = d.id
        LEFT JOIN users u ON p.scientist_id = u.id
        WHERE (p.scientist_id = ? OR p.id IN (SELECT project_id FROM progress_reports WHERE submitted_by = ?) OR p.proposal_id IN (SELECT proposal_id FROM proposal_co_pis WHERE email = ?))
          AND p.project_status = 'Active'
        ORDER BY p.id DESC
    ");
    $stmtActive->execute([$userId, $userId, $currentUser['email'] ?? '']);
}
$activeProjects = $stmtActive->fetchAll();

foreach ($activeProjects as &$ap) {
    $ap['copis'] = get_project_copis((int)$ap['id']);
    if (empty($ap['copis'])) {
        $ap['copis'] = get_proposal_copis((int)($ap['proposal_id'] ?? 0), (int)$ap['id'], (string)($ap['project_number'] ?? ''));
    }
    if (empty($ap['funding_agency_type'])) {
        $ap['funding_agency_type'] = $ap['prop_funding_agency_type'] ?? 'National';
    }
    if (empty($ap['yearly_budget']) && !empty($ap['prop_yearly_budget'])) {
        $ap['yearly_budget'] = $ap['prop_yearly_budget'];
    }
}
unset($ap);

// Fetch active portal scientists for Co-PI dropdown
$portalScientists = $db->query("
    SELECT u.id, u.name, u.email, u.designation, u.role_id, d.department_name, d.department_code 
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id 
    WHERE u.status = 'active'
      AND u.role_id IN (" . ROLE_SCIENTIST . ", " . ROLE_HOD . ")
      AND u.id != " . (int)$userId . "
    ORDER BY u.name ASC
")->fetchAll();

$error = null;
$editProposalId = (int)($_GET['id'] ?? ($_GET['edit_id'] ?? ($_POST['edit_id'] ?? ($_POST['proposal_id'] ?? 0))));
$editProposal = null;
$existingCopis = [];

if ($editProposalId > 0) {
    $stmtEdit = $db->prepare("
        SELECT p.*, d.department_name, d.department_code
        FROM proposals p
        LEFT JOIN departments d ON p.department_id = d.id
        WHERE p.id = ?
    ");
    $stmtEdit->execute([$editProposalId]);
    $editProposal = $stmtEdit->fetch();

    if ($editProposal) {
        $existingCopis = get_proposal_copis($editProposalId);
        $pageTitle = 'Edit Completion Project - ' . ($editProposal['proposal_number'] ?? '');
    }
}

$linkedProjectId = (int)($editProposal['linked_project_id'] ?? ($_GET['project_id'] ?? ($_POST['linked_project_id'] ?? 0)));
if ($linkedProjectId <= 0 && !empty($activeProjects) && empty($_POST) && !$editProposal) {
    $linkedProjectId = (int)$activeProjects[0]['id'];
}
$selectedProject = null;
$projectType = $editProposal['project_type'] ?? 'in_house';
$fundingAgency = $editProposal['funding_agency'] ?? '';
$fundingAgencyType = $editProposal['funding_agency_type'] ?? 'National';
$yearlyBudget = $editProposal['yearly_budget'] ?? null;
$institutePriority = $editProposal['institute_priority_area'] ?? '';
$nationalPriority = $editProposal['national_priority_area'] ?? '';
$projectCode = $editProposal['project_number'] ?? '';
$title = $editProposal['title'] ?? '';
$startDate = $editProposal['proposed_start_date'] ?? '';
$endDate = $editProposal['proposed_end_date'] ?? '';
$achievements = $editProposal['significant_achievements'] ?? '';
$budgetAllocated = $editProposal['budget_allocated'] ?? ($editProposal['proposed_budget'] ?? '');
$budgetUtilized = isset($editProposal['budget_utilized']) && $editProposal['budget_utilized'] !== null ? $editProposal['budget_utilized'] : '';
$finalReport = $editProposal['final_report'] ?? '';
$previousIrcAtr = $editProposal['previous_irc_atr'] ?? '';
$outputOutcome = $editProposal['output_outcome'] ?? '';
$otherDetails = $editProposal['other_details'] ?? '';
$trlLevel = (int)($editProposal['trl_level'] ?? 3);
$extensionRequested = (int)($editProposal['extension_requested'] ?? 0);
$extendedEndDate = $editProposal['extended_end_date'] ?? '';
$additionalFundsRequested = (float)($editProposal['additional_funds_requested'] ?? 0.00);
$extensionJustification = $editProposal['extension_justification'] ?? '';

// Pre-fill from linked project if specified and not already filled from edit draft
if ($linkedProjectId > 0 && !$editProposal) {
    foreach ($activeProjects as $ap) {
        if ((int)$ap['id'] === $linkedProjectId) {
            $selectedProject = $ap;
            break;
        }
    }
    if (!$selectedProject) {
        $stmtSingle = $db->prepare("
            SELECT p.*, pr.title as proposal_title, pr.institute_priority_area, pr.national_priority_area, pr.trl_level, pr.objectives,
                   pr.funding_agency_type as prop_funding_agency_type, pr.yearly_budget as prop_yearly_budget,
                   d.department_name, d.department_code, u.name as scientist_name, u.designation as scientist_designation
            FROM projects p
            LEFT JOIN proposals pr ON p.proposal_id = pr.id
            LEFT JOIN departments d ON p.department_id = d.id
            LEFT JOIN users u ON p.scientist_id = u.id
            WHERE p.id = ?
        ");
        $stmtSingle->execute([$linkedProjectId]);
        $selectedProject = $stmtSingle->fetch();
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
        $title = $selectedProject['proposal_title'] ?: ($selectedProject['title'] ?: '');
        $projectType = $selectedProject['project_type'] ?: 'in_house';
        $fundingAgency = $selectedProject['funding_agency'] ?: '';
        $fundingAgencyType = $selectedProject['funding_agency_type'] ?? 'National';
        $yearlyBudget = $selectedProject['yearly_budget'] ?? null;
        $projectCode = $selectedProject['project_number'] ?: '';
        $startDate = $selectedProject['start_date'] ?: '';
        $endDate = $selectedProject['end_date'] ?: '';
        $budgetAllocated = $selectedProject['approved_budget'] ?: '';
        $institutePriority = $selectedProject['institute_priority_area'] ?: 'A';
        $nationalPriority = $selectedProject['national_priority_area'] ?: '';
        $trlLevel = (int)($selectedProject['trl_level'] ?: 3);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $submissionAction = $_POST['submit_action'] ?? 'draft'; // 'draft' or 'submit'
    $targetStatus = ($submissionAction === 'submit') ? ($isHod ? STATUS_FORWARDED_JD : STATUS_SUBMITTED_HOD) : STATUS_DRAFT;

    $linkedProjectId = (int)($_POST['linked_project_id'] ?? ($_POST['selected_db_project_id'] ?? 0));
    $dbProj = null;
    if ($linkedProjectId > 0) {
        $stmtProj = $db->prepare("
            SELECT p.*, pr.title as proposal_title, pr.institute_priority_area, pr.national_priority_area, pr.trl_level, pr.objectives,
                   pr.funding_agency_type as prop_funding_agency_type, pr.yearly_budget as prop_yearly_budget,
                   d.department_name, d.department_code
            FROM projects p 
            LEFT JOIN proposals pr ON p.proposal_id = pr.id 
            LEFT JOIN departments d ON p.department_id = d.id
            WHERE p.id = ?
        ");
        $stmtProj->execute([$linkedProjectId]);
        $dbProj = $stmtProj->fetch(PDO::FETCH_ASSOC);
    }

    if ($dbProj) {
        $projectType = $dbProj['project_type'] ?: 'in_house';
        $fundingAgency = ($projectType === 'funding_agency') ? ($dbProj['funding_agency'] ?: '') : '';
        $fundingAgencyType = $dbProj['funding_agency_type'] ?: ($dbProj['prop_funding_agency_type'] ?? 'National');
        $yearlyBudget = $dbProj['yearly_budget'] ?: ($dbProj['prop_yearly_budget'] ?? null);
        $projectCode = ($projectType === 'in_house') ? ($dbProj['project_number'] ?: '') : '';
        // Division / Department is strictly locked to the project's recorded department
        $deptId = (int)$dbProj['department_id'];
        
        $rawPriority = !empty($_POST['institute_priority_area']) ? trim(sanitize($_POST['institute_priority_area'])) : ($dbProj['institute_priority_area'] ?: 'A');
        if (preg_match('/^(PROGRAM\s*)?([A-F])/i', $rawPriority, $matches)) {
            $institutePriority = strtoupper($matches[2]);
        } else {
            $institutePriority = strtoupper($rawPriority ?: 'A');
        }
        $nationalPrioritySelect = trim(sanitize($_POST['national_priority_area_select'] ?? ''));
        $nationalPriorityOther = trim(sanitize($_POST['national_priority_area_other'] ?? ''));
        if ($nationalPrioritySelect === 'OTHER') {
            $nationalPriority = $nationalPriorityOther;
        } elseif (!empty($nationalPrioritySelect)) {
            $nationalPriority = $nationalPrioritySelect;
        } else {
            $nationalPriority = !empty($_POST['national_priority_area']) ? sanitize($_POST['national_priority_area']) : ($dbProj['national_priority_area'] ?: '');
        }
        $trlLevel = !empty($_POST['trl_level']) ? (int)$_POST['trl_level'] : (int)($dbProj['trl_level'] ?: 3);

        $startDate = !empty($_POST['proposed_start_date']) ? $_POST['proposed_start_date'] : $dbProj['start_date'];
        $endDate = !empty($_POST['proposed_end_date']) ? $_POST['proposed_end_date'] : $dbProj['end_date'];
        $budgetAllocated = !empty($_POST['budget_allocated']) ? (float)$_POST['budget_allocated'] : (float)$dbProj['approved_budget'];
        $origObjectives = $dbProj['objectives'] ?: '';
    } else {
        $projectType = sanitize($_POST['project_type'] ?? '');
        $fundingAgencySelect = trim(sanitize($_POST['funding_agency_select'] ?? ''));
        $fundingAgencyOther = trim(sanitize($_POST['funding_agency_other'] ?? ''));
        if ($fundingAgencySelect === 'OTHER') {
            $fundingAgency = $fundingAgencyOther;
        } else {
            $fundingAgency = $fundingAgencySelect ?: trim(sanitize($_POST['funding_agency'] ?? ''));
        }
        $fundingAgencyType = 'National';
        $yearlyBudget = null;
        $projectCode = trim(sanitize($_POST['project_number'] ?? ''));
        $title = sanitize($_POST['title'] ?? '');
        // Division / Department is strictly locked to user's affiliated department
        $deptId = (int)($currentUser['department_id'] ?: ($department['id'] ?? 1));
        
        $rawPriority = trim(sanitize($_POST['institute_priority_area'] ?? ''));
        if (preg_match('/^(PROGRAM\s*)?([A-F])/i', $rawPriority, $matches)) {
            $institutePriority = strtoupper($matches[2]);
        } else {
            $institutePriority = strtoupper($rawPriority);
        }
        $nationalPrioritySelect = trim(sanitize($_POST['national_priority_area_select'] ?? ''));
        $nationalPriorityOther = trim(sanitize($_POST['national_priority_area_other'] ?? ''));
        if ($nationalPrioritySelect === 'OTHER') {
            $nationalPriority = $nationalPriorityOther;
        } else {
            $nationalPriority = $nationalPrioritySelect ?: trim(sanitize($_POST['national_priority_area'] ?? ''));
        }
        $trlLevel = (int)($_POST['trl_level'] ?? 1);
        $submissionRemarks = trim(sanitize($_POST['submission_remarks'] ?? ''));

        $startDate = !empty($_POST['proposed_start_date']) ? $_POST['proposed_start_date'] : null;
        $endDate = !empty($_POST['proposed_end_date']) ? $_POST['proposed_end_date'] : null;
        $budgetAllocated = (float)($_POST['budget_allocated'] ?? 0);
        $origObjectives = '';
    }

    $achievements = sanitize_html($_POST['significant_achievements'] ?? '');
    $budgetUtilized = (float)($_POST['budget_utilized'] ?? 0);
    $finalReport = sanitize_html($_POST['final_report'] ?? '');
    $previousIrcAtr = sanitize_html($_POST['previous_irc_atr'] ?? '');
    $outputOutcome = sanitize_html($_POST['output_outcome'] ?? '');
    $otherDetails = sanitize($_POST['other_details'] ?? '');

    // Extension & Additional Funds
    $extensionRequested = !empty($_POST['extension_requested']) ? 1 : 0;
    $extendedEndDate = !empty($_POST['extended_end_date']) ? $_POST['extended_end_date'] : null;
    $additionalFundsRequested = (float)($_POST['additional_funds_requested'] ?? 0);
    $extensionJustification = trim(sanitize($_POST['extension_justification'] ?? ''));

    // Word count check for Final Report (max 250 words)
    $cleanReportText = strip_tags($finalReport);
    $cleanReportText = html_entity_decode($cleanReportText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $cleanReportText = preg_replace('/[\s\u00A0\u2000-\u200B]+/u', ' ', $cleanReportText);
    $cleanReportText = trim($cleanReportText);
    $wordCount = !empty($cleanReportText) ? count(preg_split('/\s+/', $cleanReportText)) : 0;

    // Fetch target department code
    $effectiveDeptId = $deptId ?: (int)($currentUser['department_id'] ?: 1);
    $stmtD = $db->prepare("SELECT department_code FROM departments WHERE id = ?");
    $stmtD->execute([$effectiveDeptId]);
    $deptCode = $stmtD->fetchColumn() ?: 'NDRI';

    // Validation
    if ($submissionAction === 'submit') {
        if ($dbProj && (int)$dbProj['scientist_id'] !== $userId && !$isHod) {
            $error = "Access Restricted: You are assigned as a Co-PI on this project (View Only). Only the Principal Investigator (Lead PI) can submit project completion reports.";
        } elseif (!$dbProj && (empty($projectType) || !in_array($projectType, ['in_house', 'funding_agency']))) {
            $error = "Please select whether this is an In-house Project or Externally Funded Project.";
        } elseif (!$dbProj && $projectType === 'funding_agency' && empty($fundingAgency)) {
            $error = "Funding Agency Name is mandatory for Externally Funded Projects.";
        } elseif (!$dbProj && $projectType === 'in_house' && empty($projectCode)) {
            $error = "Project No. / Project Code is mandatory for in-house completion projects. Please enter the assigned Project Code.";
        } elseif (empty($title)) {
            $error = "Project Title is mandatory.";
        } elseif (empty($deptId)) {
            $error = "Division / Department is mandatory.";
        } elseif (($deptCode === 'SRS' || $deptCode === 'ERS') && empty($discipline)) {
            $error = "Discipline is required for Regional Station ({$deptCode}). Please specify your discipline.";
        } elseif (empty($institutePriority) || !in_array($institutePriority, ['A', 'B', 'C', 'D', 'E', 'F'])) {
            $error = "Institute Priority Area is mandatory. Please select Program A, B, C, D, E, or F.";
        } elseif (!$dbProj && empty($nationalPriority)) {
            $error = "National Priority Area is mandatory.";
        } elseif (empty($startDate) || empty($endDate)) {
            $error = "Both Project Start Date and Completion / End Date are mandatory.";
        } elseif (strtotime($endDate) <= strtotime($startDate)) {
            $error = "Project Completion / End Date must be after the Start Date.";
        } elseif (!$dbProj && $budgetAllocated <= 0) {
            $error = "Sanctioned Budget Allocated is mandatory and must be greater than zero.";
        } elseif (!isset($_POST['budget_utilized']) || trim($_POST['budget_utilized']) === '') {
            $error = "Actual Budget Utilized is mandatory.";
        } elseif (empty(trim(strip_tags($achievements)))) {
            $error = "Significant Achievements are mandatory.";
        } elseif (empty(trim(strip_tags($finalReport)))) {
            $error = "Final Report is mandatory.";
        } elseif ($wordCount > 250) {
            $error = "Final Report must be strictly 250 words or less. Current word count is " . $wordCount . " words.";
        } elseif (empty(trim(strip_tags($previousIrcAtr)))) {
            $error = "Action Taken Report (ATR) on Recommendations of Previous IRC is mandatory (enter 'N/A' if not applicable).";
        } elseif (empty(trim(strip_tags($outputOutcome)))) {
            $error = "Output / Outcome is mandatory.";
        } elseif (empty($otherDetails)) {
            $error = "Other Details field is mandatory (enter 'N/A' if not applicable).";
        } elseif ($extensionRequested && empty($extendedEndDate)) {
            $error = "Proposed Extended End Date is mandatory when requesting a project extension.";
        } elseif ($extensionRequested && !empty($endDate) && strtotime($extendedEndDate) <= strtotime($endDate)) {
            $error = "Proposed Extended End Date must be later than the current Project Completion Date (" . format_date($endDate) . ").";
        } elseif ($extensionRequested && empty($extensionJustification)) {
            $error = "Please provide detailed justification for requesting extension of project duration or additional funds.";
        }
    } else {
        // Draft saving: no mandatory fields required!
        if (empty($title)) {
            $title = $selectedProject ? ($selectedProject['proposal_title'] ?: $selectedProject['project_number']) : ('Completed Project Draft - ' . date('d M Y H:i'));
        }
        if (empty($deptId)) {
            $deptId = $effectiveDeptId;
        }
        if (empty($projectType)) {
            $projectType = $selectedProject['project_type'] ?? 'in_house';
        }
        if (empty($institutePriority)) {
            $institutePriority = $selectedProject['institute_priority_area'] ?? 'A';
        }
        if (empty($startDate)) {
            $startDate = $selectedProject['start_date'] ?? null;
        }
        if (empty($endDate)) {
            $endDate = $selectedProject['end_date'] ?? null;
        }
        if (empty($projectCode) && $projectType === 'in_house' && $selectedProject) {
            $projectCode = $selectedProject['project_number'] ?? null;
        }
    }

    if (!$error) {
        try {
            $db->beginTransaction();

            $submittedAt = ($submissionAction === 'submit') ? date('Y-m-d H:i:s') : null;
            $now = date('Y-m-d H:i:s');

            if ($editProposalId > 0) {
                $proposalId = $editProposalId;
                $proposalNumber = $editProposal['proposal_number'] ?? generate_proposal_number($deptCode);
                $stmtUpdate = $db->prepare("
                    UPDATE proposals SET
                        project_type = ?, funding_agency = ?, funding_agency_type = ?, proposal_category = 'completed', project_number = ?,
                        title = ?, department_id = ?, institute_priority_area = ?, national_priority_area = ?,
                        trl_level = ?, proposed_start_date = ?, proposed_end_date = ?, significant_achievements = ?,
                        proposed_budget = ?, yearly_budget = ?, budget_allocated = ?, budget_utilized = ?, final_report = ?, previous_irc_atr = ?,
                        output_outcome = ?, other_details = ?, submission_remarks = ?, linked_project_id = ?, extension_requested = ?,
                        extended_end_date = ?, additional_funds_requested = ?, extension_justification = ?,
                        current_status = ?, submitted_at = CASE WHEN ? IS NOT NULL THEN ? ELSE submitted_at END, updated_at = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $projectType,
                    ($projectType === 'funding_agency' ? $fundingAgency : null),
                    ($projectType === 'funding_agency' ? $fundingAgencyType : 'National'),
                    ($projectType === 'in_house' ? $projectCode : null),
                    $title,
                    $deptId,
                    $institutePriority,
                    $nationalPriority,
                    $trlLevel,
                    $startDate,
                    $endDate,
                    $achievements,
                    $budgetAllocated,
                    $yearlyBudget,
                    $budgetAllocated,
                    $budgetUtilized,
                    $finalReport,
                    $previousIrcAtr,
                    $outputOutcome,
                    $otherDetails,
                    (!empty($submissionRemarks) ? $submissionRemarks : null),
                    ($linkedProjectId > 0 ? $linkedProjectId : null),
                    $extensionRequested,
                    $extendedEndDate,
                    $additionalFundsRequested,
                    $extensionJustification,
                    $targetStatus,
                    $submittedAt,
                    $submittedAt,
                    $now,
                    $proposalId
                ]);

                // Clear previous Co-PIs for re-insertion
                $db->prepare("DELETE FROM proposal_co_pis WHERE proposal_id = ?")->execute([$proposalId]);
            } else {
                $proposalNumber = generate_proposal_number($deptCode);

                $sql = "INSERT INTO proposals (
                    proposal_number, project_type, funding_agency, funding_agency_type, proposal_category, project_number,
                    title, scientist_id, department_id, institute_priority_area, national_priority_area,
                    trl_level, proposed_start_date, proposed_end_date, significant_achievements,
                    proposed_budget, yearly_budget, budget_allocated, budget_utilized, final_report, previous_irc_atr,
                    output_outcome, other_details, submission_remarks, linked_project_id, extension_requested,
                    extended_end_date, additional_funds_requested, extension_justification,
                    objectives, current_status, submitted_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $proposalNumber,
                    $projectType,
                    ($projectType === 'funding_agency' ? $fundingAgency : null),
                    ($projectType === 'funding_agency' ? $fundingAgencyType : 'National'),
                    ($projectType === 'in_house' ? $projectCode : null),
                    $title,
                    $userId,
                    $deptId,
                    $institutePriority,
                    $nationalPriority,
                    $trlLevel,
                    $startDate,
                    $endDate,
                    $achievements,
                    $budgetAllocated,
                    $yearlyBudget,
                    $budgetAllocated,
                    $budgetUtilized,
                    $finalReport,
                    $previousIrcAtr,
                    $outputOutcome,
                    $otherDetails,
                    (!empty($submissionRemarks) ? $submissionRemarks : null),
                    ($linkedProjectId > 0 ? $linkedProjectId : null),
                    $extensionRequested,
                    $extendedEndDate,
                    $additionalFundsRequested,
                    $extensionJustification,
                    $origObjectives,
                    $targetStatus,
                    $submittedAt,
                    $now,
                    $now
                ]);
                $proposalId = (int)$db->lastInsertId();
            }

            if (!empty($submissionRemarks)) {
                add_proposal_comment($proposalId, $userId, 'Scientist Completion Submission', $submissionRemarks);
            }

            // Save Co-PIs
            $copiNames = $_POST['copi_name'] ?? [];
            $copiInsts = $_POST['copi_institution'] ?? [];
            $copiDesigs = $_POST['copi_designation'] ?? [];
            $copiEmails = $_POST['copi_email'] ?? [];
            $savedCopisCount = 0;

            if (!empty($copiNames) && is_array($copiNames)) {
                $stmtCoPi = $db->prepare("INSERT INTO proposal_co_pis (proposal_id, co_pi_name, institution, designation, email) VALUES (?, ?, ?, ?, ?)");
                for ($i = 0; $i < count($copiNames); $i++) {
                    $cName = sanitize($copiNames[$i] ?? '');
                    if (!empty($cName)) {
                        $stmtCoPi->execute([
                            $proposalId,
                            $cName,
                            sanitize($copiInsts[$i] ?? ''),
                            sanitize($copiDesigs[$i] ?? ''),
                            sanitize($copiEmails[$i] ?? '')
                        ]);
                        $savedCopisCount++;
                    }
                }
            }

            // If no manual Co-PIs were entered, automatically copy Co-PIs from linked project database record!
            if ($savedCopisCount === 0 && $linkedProjectId > 0) {
                $dbCopis = get_project_copis($linkedProjectId);
                if (!empty($dbCopis)) {
                    $stmtCoPi = $db->prepare("INSERT INTO proposal_co_pis (proposal_id, co_pi_name, institution, designation, email) VALUES (?, ?, ?, ?, ?)");
                    foreach ($dbCopis as $cp) {
                        $stmtCoPi->execute([
                            $proposalId,
                            $cp['co_pi_name'],
                            $cp['institution'] ?: '',
                            $cp['designation'] ?: '',
                            $cp['email'] ?: ''
                        ]);
                    }
                }
            }

            // Record Status History
            $historyComment = ($submissionAction === 'submit') 
                ? ($isHod
                    ? "Completion Project dossier (Project Code: {$projectCode})" . ($extensionRequested ? " with duration extension / additional funds request" : "") . " submitted by Head of Department and forwarded directly to Joint Director for review."
                    : "Completion Project dossier (Project Code: {$projectCode})" . ($extensionRequested ? " with duration extension / additional funds request" : "") . " submitted to Head of Department for review.")
                : "Completion Project dossier draft saved.";
            record_status_history($proposalId, null, $targetStatus, $userId, $currentUser['role_name'] ?? ($isHod ? 'Head of Department' : 'Scientist'), $historyComment);

            // Audit log
            log_audit($userId, ($submissionAction === 'submit' ? 'COMPLETED_PROPOSAL_SUBMITTED' : 'COMPLETED_PROPOSAL_DRAFT'), 'proposals', $proposalId, "Completion Project {$proposalNumber} (Code: {$projectCode}) created with status '{$targetStatus}'.");

            $db->commit();

            if ($submissionAction === 'submit') {
                if ($isHod) {
                    flash('success', "Completion Project dossier ({$projectCode}) submitted successfully and forwarded directly to Joint Director for review.");
                } else {
                    flash('success', "Completion Project dossier ({$projectCode}) submitted successfully to Head of Department.");
                }
                header("Location: " . url("/scientist/proposal-details.php?id={$proposalId}"));
            } else {
                flash('success', "Completion Project dossier ({$projectCode}) saved as draft. Continuing in edit mode.");
                header("Location: " . url("/scientist/create-completed-proposal.php?id={$proposalId}"));
            }
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error saving completion project: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Completion Project</h3>
        <p class="text-muted small mb-0">Submit completion project dossier, executive final report, achievements, and duration/fund extension requests</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/scientist/proposals.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Proposals
        </a>
    </div>
</div>

<!-- Type Switcher Tabs -->
<ul class="nav nav-pills mb-4 p-1 bg-white border rounded shadow-xs" style="max-width: 650px;">
    <li class="nav-item flex-fill text-center">
        <a class="nav-link fw-semibold text-secondary py-2" href="<?= url('/scientist/create-proposal.php') ?>">
            <i class="bi bi-file-earmark-plus me-1"></i> New Proposal
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link fw-semibold text-secondary py-2" href="<?= url('/scientist/ongoing-projects.php') ?>">
            <i class="bi bi-arrow-repeat me-1"></i> On Going Projects
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link active fw-semibold text-white py-2 shadow-sm" style="background-color: #1a365d;" href="<?= url('/scientist/create-completed-proposal.php') ?>">
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

<div class="alert alert-info border-info-subtle shadow-xs mb-4 d-flex align-items-center">
    <i class="bi bi-info-circle-fill fs-4 me-3 text-info"></i>
    <div>
        <strong>Completion Project Workflow:</strong>
        Select an active project from the system to automatically load its project code, funding type, approved budget, and duration, or enter details for legacy projects. Provide Significant Achievements, Budget Allocated vs Utilized, Final Report (strictly up to 250 words), and ATR. You can also request an extension of project duration or additional funds. Upon submission, it routes to HOD, then Joint Director, and to the IRC Council.
    </div>
</div>

<form method="POST" action="<?= url("/scientist/create-completed-proposal.php") ?>" enctype="multipart/form-data" id="completed-proposal-form">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="submit_action" id="submit_action_hidden" value="draft">
    <input type="hidden" name="edit_id" id="edit_id" value="<?= (int)($editProposalId ?? 0) ?>">

    <!-- Section 1: Project Identification & Selection -->
    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-card-heading text-primary"></i> 1. Administrative Identification & Project Selection
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <!-- Project Selection Dropdown -->
                <div class="col-12">
                    <div class="p-3 bg-light rounded-3 border border-primary-subtle shadow-xs">
                        <label class="form-label fw-bold text-dark d-flex justify-content-between align-items-center mb-1">
                            <span><i class="bi bi-search text-primary me-1"></i> Select Project for Completion</span>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">System Autofill</span>
                        </label>
                        <p class="text-muted small mb-2">
                            Select an active project from the system database to automatically retrieve its title, project code, funding type, TRL, Co-PIs, approved budget, and tenure. If submitting a legacy or past project not in the system, select "Project Not In System".
                        </p>
                        <select name="selected_db_project_id" id="selected_db_project_id" class="form-select border-secondary-subtle" style="font-size: 0.92rem; font-weight: 500;" onchange="handleDbProjectSelection(this.value)">
                            <option value="">-- Choose an active project to autofill details (or select manual entry below) --</option>
                            <?php if (!empty($activeProjects)): ?>
                                <optgroup label="Your Active Projects in Database">
                                    <?php foreach ($activeProjects as $p): ?>
                                        <option value="<?= $p['id'] ?>" <?= ($linkedProjectId == $p['id']) ? 'selected' : '' ?>>
                                            [<?= e($p['project_number']) ?>] <?= e($p['proposal_title'] ?: ($p['title'] ?: 'Project #' . $p['id'])) ?> (<?= $p['project_type'] === 'funding_agency' ? 'Funding: ' . e($p['funding_agency']) : 'In-House' ?> - <?= format_currency((float)$p['approved_budget']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <option value="manual" <?= ($linkedProjectId === 0 && !empty($_POST)) ? 'selected' : '' ?>>
                                ➕ Project Not In System / Legacy Project (Enter All Fields Manually)
                            </option>
                        </select>
                        <input type="hidden" name="linked_project_id" id="linked_project_id" value="<?= e($linkedProjectId) ?>">
                    </div>
                </div>

                <!-- Verified Database Project Display Card (Visible when DB project is selected) -->
                <div class="col-12" id="db-project-info-card" style="display: <?= $selectedProject ? 'block' : 'none' ?>;">
                    <div class="card border-primary border-opacity-25 shadow-sm bg-white overflow-hidden">
                        <div class="card-header bg-primary bg-opacity-10 py-2 px-3 border-bottom border-primary border-opacity-10 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-primary font-monospace" id="db_card_project_number">
                                <i class="bi bi-patch-check-fill text-primary me-1"></i> [<?= e($selectedProject['project_number'] ?? '') ?>]
                            </span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                <i class="bi bi-database-check me-1"></i> Active Project in System
                            </span>
                        </div>
                        <div class="card-body p-3">
                            <h5 class="fw-bold text-dark mb-3" id="db_card_title">
                                <?= e($selectedProject['proposal_title'] ?? ($selectedProject['title'] ?? '')) ?>
                            </h5>
                            
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <div class="p-2 rounded bg-light border">
                                        <div class="text-muted extra-small text-uppercase fw-bold">Category & Funding Classification</div>
                                        <div class="fw-bold text-dark fs-6 mt-1" id="db_card_category">
                                            <?php if ($selectedProject): 
                                                $selAgency = get_funding_agency_details($selectedProject);
                                            ?>
                                                <div><?= $selAgency['badge_html'] ?></div>
                                                <?php if ($selAgency['is_external'] && !empty($selAgency['agency_name'])): ?>
                                                    <div class="text-muted extra-small mt-1">Funding Agency: <strong><?= e($selAgency['agency_name']) ?></strong></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="p-2 rounded bg-light border">
                                        <div class="text-muted extra-small text-uppercase fw-bold">TRL Level</div>
                                        <div class="fw-bold text-dark fs-6" id="db_card_trl">
                                            <span class="badge bg-primary text-white">TRL-<?= e($selectedProject['trl_level'] ?? 3) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 rounded bg-light border">
                                        <div class="text-muted extra-small text-uppercase fw-bold">Division & Priority Area</div>
                                        <div class="fw-bold text-dark fs-6" id="db_card_dept_priority">
                                            <?= e($selectedProject['department_name'] ?? '') ?> &bull; <?= e(get_priority_area_title($selectedProject['institute_priority_area'] ?? 'A')) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Research Team in DB Project -->
                            <div class="mt-3 pt-3 border-top">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="text-muted extra-small text-uppercase fw-bold mb-1"><i class="bi bi-person-fill text-primary"></i> Principal Investigator (PI)</div>
                                        <div class="fw-semibold text-dark" id="db_card_pi">
                                            <?= e($selectedProject['scientist_name'] ?? $currentUser['name']) ?> (<?= e($selectedProject['scientist_designation'] ?? $currentUser['designation']) ?>)
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-muted extra-small text-uppercase fw-bold mb-1"><i class="bi bi-people-fill text-primary"></i> Co-Principal Investigators (Co-PIs)</div>
                                        <div id="db_card_copis_list">
                                            <?php if (!empty($selectedProject['copis'])): ?>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <?php foreach ($selectedProject['copis'] as $cp): ?>
                                                        <span class="badge bg-light text-dark border p-2 text-start">
                                                            <div class="fw-bold"><i class="bi bi-person-check text-success me-1"></i><?= e($cp['co_pi_name'] ?? ($cp['name'] ?? 'Co-PI')) ?></div>
                                                            <div class="extra-small text-muted"><?= e($cp['designation'] ?? 'Co-PI') ?><?= !empty($cp['institution']) ? ' &bull; ' . e($cp['institution']) : '' ?></div>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">No Co-PIs recorded in original proposal master.</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Direct to unified Section 2 -->
                                <div class="mt-3 p-2 bg-light rounded border text-muted small">
                                    <i class="bi bi-info-circle-fill text-primary me-1"></i>
                                    Project Associates (Co-PIs) are loaded below in <strong>Section 2</strong>. You can search, add, edit, or remove collaborators there.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Manual Fields Section: Displayed ONLY if "Project Not In System / Legacy" is selected -->
                <div class="col-12" id="manual-project-fields-section" style="display: <?= $selectedProject ? 'none' : 'block' ?>;">
                    <div class="alert alert-warning border-warning-subtle small mb-3">
                        <i class="bi bi-exclamation-circle-fill text-warning me-1"></i>
                        <strong>Manual Entry Mode:</strong> Because this project is not in the active database, please provide all administrative, funding, team, and budget details below.
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Principal Investigator (Project Leader)</label>
                            <input type="text" class="form-control bg-light" value="<?= e($currentUser['name']) ?> (<?= e($currentUser['designation']) ?>)" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">
                                Division / Department <span class="badge bg-light text-secondary border ms-1"><i class="bi bi-lock-fill me-1"></i>Locked</span>
                            </label>
                            <input type="text" class="form-control bg-light text-dark fw-medium" value="<?= e($department['department_name']) ?> (<?= e($department['department_code']) ?>)" readonly>
                            <input type="hidden" name="department_id" id="department_id" value="<?= (int)($department['id'] ?? ($currentUser['department_id'] ?? 1)) ?>">
                            <div class="form-text extra-small text-muted mt-1"><i class="bi bi-shield-lock-fill text-secondary me-1"></i>Division is locked to your affiliated department.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold text-secondary d-block mb-1">
                                Project Category / Funding Source <span class="text-danger">*</span>
                            </label>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="d-block p-3 rounded border h-100 bg-white shadow-sm" id="box-in-house" style="cursor: pointer;">
                                        <div class="form-check m-0">
                                            <input class="form-check-input" type="radio" name="project_type" id="type_in_house" value="in_house" <?= ($projectType !== 'funding_agency') ? 'checked' : '' ?> onchange="toggleFundingAgencyInput()">
                                            <span class="form-check-label fw-bold text-dark ms-1">
                                                <i class="bi bi-house-door-fill text-primary me-1"></i> In-house Project
                                            </span>
                                        </div>
                                        <div class="text-muted small mt-2 ps-4" style="line-height: 1.4;">
                                            Institutional research project funded under NDRI regular budget mandate.
                                        </div>
                                    </label>
                                </div>
                                <div class="col-md-6">
                                    <label class="d-block p-3 rounded border h-100 bg-white shadow-sm" id="box-funding-agency" style="cursor: pointer;">
                                        <div class="form-check m-0">
                                            <input class="form-check-input" type="radio" name="project_type" id="type_funding_agency" value="funding_agency" <?= ($projectType === 'funding_agency') ? 'checked' : '' ?> onchange="toggleFundingAgencyInput()">
                                            <span class="form-check-label fw-bold text-dark ms-1">
                                                <i class="bi bi-bank2 text-success me-1"></i> Externally Funded Project
                                            </span>
                                        </div>
                                        <div class="text-muted small mt-2 ps-4" style="line-height: 1.4;">
                                            Externally sponsored research project (e.g. DBT, DST, BIRAC, Industry, etc.).
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <?php
                        $selectedAgencyVal = '';
                        $isOtherAgency = false;
                        $otherAgencyVal = '';
                        $externalAgenciesList = get_external_funding_agencies();

                        if (!empty($fundingAgency)) {
                            if (in_array($fundingAgency, $externalAgenciesList, true)) {
                                $selectedAgencyVal = $fundingAgency;
                            } else {
                                $selectedAgencyVal = 'OTHER';
                                $isOtherAgency = true;
                                $otherAgencyVal = $fundingAgency;
                            }
                        }
                        if (isset($_POST['funding_agency_select']) && $_POST['funding_agency_select'] === 'OTHER') {
                            $selectedAgencyVal = 'OTHER';
                            $isOtherAgency = true;
                            $otherAgencyVal = trim(sanitize($_POST['funding_agency_other'] ?? ''));
                        }
                        ?>
                        <div class="col-12" id="funding-agency-wrapper" style="<?= ($projectType === 'funding_agency') ? '' : 'display: none;' ?>">
                            <div class="p-3 bg-light rounded border border-success-subtle shadow-xs">
                                <div class="row g-3">
                                    <div class="<?= $isOtherAgency ? 'col-md-6' : 'col-12' ?>" id="funding_agency_select_col">
                                        <label for="funding_agency_select" class="form-label small fw-bold text-dark mb-1">
                                            <i class="bi bi-bank2 text-success me-1"></i> External Funding Agency <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-bank text-success"></i></span>
                                            <select name="funding_agency_select" id="funding_agency_select" class="form-select fw-semibold" onchange="handleFundingAgencyChange()">
                                                <option value="">-- Select External Funding Agency --</option>
                                                <?php foreach ($externalAgenciesList as $agencyItem): ?>
                                                    <option value="<?= e($agencyItem) ?>" <?= ($selectedAgencyVal === $agencyItem) ? 'selected' : '' ?>>
                                                        <?= e($agencyItem) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <option value="OTHER" <?= ($selectedAgencyVal === 'OTHER') ? 'selected' : '' ?>>
                                                    ➕ Other / External Funding Agency (Specify)
                                                </option>
                                            </select>
                                        </div>
                                        <div class="form-text text-muted extra-small">Select the granting / sponsoring department or council.</div>
                                    </div>
                                    <div class="col-md-6" id="funding_agency_other_wrapper" style="<?= $isOtherAgency ? '' : 'display: none;' ?>">
                                        <label for="funding_agency_other" class="form-label small fw-bold text-dark mb-1">
                                            <i class="bi bi-pencil-square text-success me-1"></i> Specify Other Funding Agency <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-pencil-fill text-muted"></i></span>
                                            <input type="text" 
                                                   name="funding_agency_other" 
                                                   id="funding_agency_other" 
                                                   class="form-control fw-semibold" 
                                                   placeholder="Enter funding agency name..." 
                                                   value="<?= e($otherAgencyVal) ?>">
                                        </div>
                                        <div class="form-text text-muted extra-small">Please specify the official granting body name.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Manually Filled Project Code / No. (Only for In-house projects; hidden for Externally Funded projects) -->
                        <div class="col-md-6" id="project-code-wrapper" style="<?= ($projectType === 'funding_agency') ? 'display: none;' : '' ?>">
                            <label class="form-label small fw-bold text-primary">
                                <i class="bi bi-upc-scan me-1"></i> Project No. / Project Code <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-primary fw-semibold"><i class="bi bi-hash"></i></span>
                                <input type="text" 
                                       name="project_number" 
                                       id="project_number" 
                                       class="form-control fw-bold font-monospace text-primary fs-6" 
                                       placeholder="e.g. NDRI-AB-2022-005..." 
                                       value="<?= e($projectCode) ?>">
                            </div>
                            <div class="form-text text-muted extra-small">Manually enter the assigned Project Number / Code for this in-house completed project.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">TRL Level (1-9) <span class="text-danger">*</span></label>
                            <select name="trl_level" id="trl_level" class="form-select" <?= !$selectedProject ? 'required' : '' ?>>
                                <?php for ($trl = 1; $trl <= 9; $trl++): ?>
                                    <option value="<?= $trl ?>" <?= ($trlLevel == $trl) ? 'selected' : '' ?>>TRL-<?= $trl ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold text-secondary">Project Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="title" class="form-control fw-semibold" placeholder="Scientific or operational title of the completed project..." value="<?= e($title) ?>" <?= !$selectedProject ? 'required' : '' ?>>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Institute Priority Area <span class="text-danger">*</span></label>
                            <select name="institute_priority_area" id="institute_priority_area" class="form-select" <?= !$selectedProject ? 'required' : '' ?>>
                                <option value="">-- Select Institute Priority Program (Program A to F) --</option>
                                <?php foreach (get_institute_priority_programs() as $pCode => $pDesc): ?>
                                    <option value="<?= e($pCode) ?>" <?= ($institutePriority === $pCode) ? 'selected' : '' ?>>
                                        <?= e($pDesc) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php
                        $nationalAreasList = get_national_priority_areas();
                        $currentNatPriority = $nationalPriority ?? '';
                        $selectedNatPriorityVal = '';
                        $isOtherNatPriority = false;
                        $otherNatPriorityVal = '';
                        if (!empty($currentNatPriority)) {
                            if (in_array($currentNatPriority, $nationalAreasList, true)) {
                                $selectedNatPriorityVal = $currentNatPriority;
                            } else {
                                $selectedNatPriorityVal = 'OTHER';
                                $isOtherNatPriority = true;
                                $otherNatPriorityVal = $currentNatPriority;
                            }
                        }
                        if (isset($_POST['national_priority_area_select']) && $_POST['national_priority_area_select'] === 'OTHER') {
                            $selectedNatPriorityVal = 'OTHER';
                            $isOtherNatPriority = true;
                            $otherNatPriorityVal = trim(sanitize($_POST['national_priority_area_other'] ?? ''));
                        }
                        ?>
                        <div class="col-md-6" id="national_priority_select_col">
                            <label for="national_priority_area_select" class="form-label small fw-semibold text-secondary">
                                National Priority Area <span class="text-danger">*</span>
                            </label>
                            <select name="national_priority_area_select" id="national_priority_area_select" class="form-select" onchange="handleNationalPriorityChange()" <?= !$selectedProject ? 'required' : '' ?>>
                                <option value="">-- Select National Priority Area --</option>
                                <?php foreach ($nationalAreasList as $nIndex => $nArea): ?>
                                    <option value="<?= e($nArea) ?>" <?= ($selectedNatPriorityVal === $nArea) ? 'selected' : '' ?>>
                                        <?= ($nIndex + 1) ?>. <?= e($nArea) ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="OTHER" <?= ($selectedNatPriorityVal === 'OTHER') ? 'selected' : '' ?>>Other National Priority Area (Specify)</option>
                            </select>
                            <div class="form-text text-muted extra-small">Select the relevant national thrust area or select Other to specify.</div>
                        </div>
                        <div class="col-12" id="national_priority_other_wrapper" style="<?= $isOtherNatPriority ? '' : 'display: none;' ?>">
                            <label for="national_priority_area_other" class="form-label small fw-semibold text-secondary">
                                Specify National Priority Area <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="national_priority_area_other" id="national_priority_area_other" class="form-control" placeholder="Enter custom National Priority Area..." value="<?= e($otherNatPriorityVal) ?>" <?= ($isOtherNatPriority && !$selectedProject) ? 'required' : '' ?>>
                            <div class="form-text text-muted extra-small">Enter custom national priority area.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Project Associates / Co-PIs -->
    <div class="detail-section-card" id="copi-section">
        <div class="detail-section-header d-flex justify-content-between align-items-center">
            <div><i class="bi bi-people text-primary"></i> 2. Project Associates (Co-PIs)</div>
            <button type="button" id="add-copi-btn" onclick="addCoPiRow()" class="btn btn-sm btn-outline-primary fw-semibold" style="font-size: 0.82rem;">
                <i class="bi bi-person-plus me-1"></i> Add Co-PI
            </button>
        </div>
        <div class="detail-section-body">
            <p class="text-muted small mb-3">
                Select Co-Principal Investigators from NDRI portal faculty (division, designation, and email are automatically loaded), or select <strong>Other / External Institute Scientist</strong> to enter external collaborator details.
            </p>
            <div id="copi-container">
                <!-- Dynamically generated Co-PI Cards -->
            </div>
            <div id="no-copi-notice" class="text-center p-3 border rounded bg-light text-muted small" style="display: none;">
                <i class="bi bi-info-circle me-1 text-primary"></i> No Co-PIs added. Click <strong>"Add Co-PI"</strong> above if this project involved collaborators.
            </div>
        </div>
    </div>

    <!-- Section 3: Duration & Financial Performance (Budget Allocated vs Utilized) -->
    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-calendar-range text-primary"></i> 3. Duration & Financial Performance (Budget Allocated vs Utilized)
        </div>
        <div class="detail-section-body">
            <!-- Timeline & Allocated Budget Summary for DB Projects -->
            <div id="db-timeline-budget-summary" class="mb-4" style="display: <?= $selectedProject ? 'block' : 'none' ?>;">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded border">
                            <div class="text-muted extra-small text-uppercase fw-bold"><i class="bi bi-calendar-check text-primary me-1"></i> Sanctioned Project Tenure</div>
                            <div class="fw-bold text-dark fs-6 mt-1" id="db_timeline_dates">
                                <?php if ($selectedProject && !empty($selectedProject['start_date'])): ?>
                                    <?= format_date($selectedProject['start_date']) ?> &rarr; <?= format_date($selectedProject['end_date']) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </div>
                            <div class="extra-small text-muted mt-1" id="db_timeline_duration">
                                Verified duration from active project record.
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded border">
                            <div class="text-muted extra-small text-uppercase fw-bold"><i class="bi bi-cash-stack text-success me-1"></i> Sanctioned Budget Allocated</div>
                            <div class="fw-bold text-success fs-5 mt-1" id="db_timeline_budget">
                                <?= $selectedProject ? format_currency((float)$selectedProject['approved_budget']) : '₹ 0.00' ?>
                            </div>
                            <div class="extra-small text-muted mt-1">Total approved grant sanctioned for this project.</div>
                        </div>
                    </div>

                    <!-- Year-wise Budget Allocation Breakdown for DB Project -->
                    <div class="col-12 mt-2 pt-2 border-top" id="db_yearly_budget_wrapper">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold text-dark m-0">
                                <i class="bi bi-calendar3-range text-success me-1"></i> Year-wise Sanctioned Budget Allocation
                            </label>
                            <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace" id="db_yearly_budget_total_badge">
                                Total: <?= $selectedProject ? format_currency((float)$selectedProject['approved_budget']) : '₹ 0.00' ?>
                            </span>
                        </div>
                        <div id="db_yearly_budget_container">
                            <?= $selectedProject ? render_yearly_budget_html($selectedProject['yearly_budget'] ?: ($selectedProject['prop_yearly_budget'] ?? null), (float)$selectedProject['approved_budget']) : '' ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Manual Timeline & Allocated Budget Inputs (Visible ONLY when manual mode is chosen) -->
            <div id="manual-timeline-budget-inputs" style="display: <?= $selectedProject ? 'none' : 'block' ?>;">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-secondary">Project Start Date <span class="text-danger">*</span></label>
                        <input type="date" name="proposed_start_date" id="proposed_start_date" class="form-control" value="<?= e($startDate) ?>" <?= !$selectedProject ? 'required' : '' ?> onchange="calculateProjectDuration()">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-secondary">Project Completion / End Date <span class="text-danger">*</span></label>
                        <input type="date" name="proposed_end_date" id="proposed_end_date" class="form-control" value="<?= e($endDate) ?>" <?= !$selectedProject ? 'required' : '' ?> onchange="calculateProjectDuration()">
                    </div>

                    <!-- Calculated Duration Banner -->
                    <div class="col-12">
                        <div class="p-3 bg-light rounded border d-flex flex-wrap align-items-center justify-content-between gap-3" style="border-left: 4px solid #1a365d !important;">
                            <div>
                                <div class="fw-bold text-dark d-flex align-items-center">
                                    <i class="bi bi-hourglass-split text-primary fs-5 me-2"></i>
                                    <span>Project Duration</span>
                                </div>
                                <div class="text-muted extra-small">Calculated span between start and completion dates.</div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="input-group input-group-sm" style="min-width: 280px;">
                                    <span class="input-group-text bg-white text-primary fw-semibold"><i class="bi bi-calendar3"></i></span>
                                    <input type="text" id="project_duration_display" class="form-control fw-bold text-primary bg-white fs-6" readonly value="Select dates">
                                </div>
                                <span id="project_duration_badge" class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fs-6 fw-semibold">
                                    Duration
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Budget Allocated Input (Manual) -->
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-secondary">Sanctioned Budget Allocated (₹ INR) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" 
                                   name="budget_allocated" 
                                   id="budget_allocated" 
                                   class="form-control fw-semibold" 
                                   step="0.01" 
                                   min="0" 
                                   placeholder="e.g. 1500000" 
                                   value="<?= ($budgetAllocated > 0) ? e($budgetAllocated) : '' ?>" 
                                   <?= !$selectedProject ? 'required' : '' ?> 
                                   oninput="calculateBudgetUtilization()">
                        </div>
                        <div class="form-text text-muted extra-small">Total budget approved/sanctioned for the project.</div>
                    </div>
                </div>
            </div>

            <!-- Always Asked: Actual Budget Utilized -->
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label small fw-bold text-dark">
                        <i class="bi bi-calculator text-primary me-1"></i> Actual Budget Utilized (₹ INR) <span class="text-danger">*</span>
                    </label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-light fw-bold">₹</span>
                        <input type="number" 
                               name="budget_utilized" 
                               id="budget_utilized" 
                               class="form-control fw-bold fs-5 text-dark" 
                               step="0.01" 
                               min="0" 
                               placeholder="e.g. 1420000" 
                               value="<?= ($budgetUtilized > 0) ? e($budgetUtilized) : '' ?>" 
                               required 
                               oninput="calculateBudgetUtilization()">
                    </div>
                    <div class="form-text text-muted extra-small">Total actual expenditure incurred upon project completion.</div>
                </div>

                <!-- Financial Performance Card -->
                <div class="col-12">
                    <div class="p-3 rounded border bg-light d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="fs-4 text-success"><i class="bi bi-pie-chart-fill"></i></div>
                            <div>
                                <div class="fw-bold text-dark">Budget Utilization Rate</div>
                                <div class="text-muted extra-small" id="budget_difference_text">Enter utilized amount to compute balance against allocated budget.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <span id="budget_rate_badge" class="badge bg-secondary-subtle text-secondary px-3 py-2 fs-6 fw-bold">
                                0.0% Utilized
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 4: Final Report, Significant Achievements & ATR -->
    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-file-earmark-text text-primary"></i> 4. Final Report & Achievements
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <!-- Significant Achievements -->
                <div class="col-12">
                    <label for="significant_achievements" class="form-label small fw-semibold text-secondary">Significant Achievements <span class="text-danger">*</span></label>
                    <textarea name="significant_achievements" id="significant_achievements" class="form-control" rows="5" placeholder="Highlight key scientific breakthroughs, technologies developed, optimized protocols, patents, or germplasm evaluated..."><?= e($achievements) ?></textarea>
                    <div class="form-text text-muted extra-small">Detail the most significant scientific accomplishments achieved during the project tenure.</div>
                </div>

                <!-- Final Report (250 words only) -->
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="final_report" class="form-label small fw-bold text-dark m-0">
                            Final Report (Maximum 250 Words) <span class="text-danger">*</span>
                        </label>
                        <span id="final_report_counter" class="badge bg-light text-primary border fw-semibold">
                            <i class="bi bi-chat-left-text me-1"></i> 0 / 250 words
                        </span>
                    </div>
                    <textarea name="final_report" 
                              id="final_report" 
                              class="form-control font-monospace" 
                              rows="6" 
                              placeholder="Executive summary of the completed project (background, methodologies, major findings, and conclusions). Must not exceed 250 words..." 
                              oninput="updateWordCount()"><?= e($finalReport) ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="form-text text-muted extra-small">Strict 250-word executive summary for institutional records and IRC proceedings.</div>
                        <div id="word_limit_warning" class="text-danger small fw-bold" style="display: none;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded!
                        </div>
                    </div>
                </div>

                <!-- ATR on Recommendations made by the House in previous IRC if any -->
                <div class="col-12">
                    <label for="previous_irc_atr" class="form-label small fw-semibold text-secondary">
                        Action Taken Report (ATR) on Recommendations Made by the House in Previous IRC (if any) <span class="text-danger">*</span>
                    </label>
                    <textarea name="previous_irc_atr" id="previous_irc_atr" class="form-control" rows="4" placeholder="Provide point-wise Action Taken Report (ATR) addressing any recommendations, observations, or directives given by previous IRC meetings... (Enter 'N/A' if not applicable)"><?= e($previousIrcAtr) ?></textarea>
                    <div class="form-text text-muted extra-small">Specify action taken on previous council directives. If not applicable, mention 'N/A'.</div>
                </div>

                <!-- Output / Outcome -->
                <div class="col-12">
                    <label for="output_outcome" class="form-label small fw-semibold text-secondary">Output / Outcome <span class="text-danger">*</span></label>
                    <textarea name="output_outcome" id="output_outcome" class="form-control" rows="4" placeholder="List concrete research deliverables: publications (NAAS rated), technologies commercialized/transferred, prototypes, human resource trained, policy briefs..."><?= e($outputOutcome) ?></textarea>
                    <div class="form-text text-muted extra-small">Quantifiable deliverables and outcomes generated from this completed project.</div>
                </div>

                <!-- Other Details if any -->
                <div class="col-12">
                    <label for="other_details" class="form-label small fw-semibold text-secondary">Other Details (if any) <span class="text-danger">*</span></label>
                    <textarea name="other_details" id="other_details" class="form-control" rows="2" placeholder="Any additional remarks, collaborations, external funding acknowledgments, or handover notes... (Enter 'N/A' if not applicable)" required><?= e($otherDetails) ?></textarea>
                    <div class="form-text text-muted extra-small">Any additional remarks. If not applicable, enter 'N/A'.</div>
                </div>

            </div>
        </div>
    </div>

    <!-- Section 5: Extension of Duration & Additional Funds Request -->
    <div class="detail-section-card border border-warning-subtle shadow-xs">
        <div class="detail-section-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-calendar-plus-fill text-warning me-2"></i> 5. Request Project Duration Extension & Additional Funds (Optional)
            </div>
            <span class="badge bg-warning-subtle text-dark border border-warning-subtle">
                Subject to IRC & Joint Director Approval
            </span>
        </div>
        <div class="detail-section-body">
            <div class="p-3 bg-light rounded border mb-3">
                <div class="form-check form-switch fs-6 m-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="extension_requested" name="extension_requested" value="1" <?= $extensionRequested ? 'checked' : '' ?> onchange="toggleExtensionFields()">
                    <label class="form-check-label fw-bold text-dark ms-2" for="extension_requested">
                        Request Extension of Project Duration and / or Additional Budget Funds
                    </label>
                </div>
                <p class="text-muted small mb-0 mt-2">
                    Enable this if you require additional time beyond the scheduled completion date or additional grant allocation to accomplish project objectives. In the IRC meeting, the Joint Director and Council can decide to: (1) Grant both time extension and additional budget, (2) Grant time extension only (no additional budget), or (3) Conclude the project as scheduled.
                </p>
            </div>

            <div id="extension_fields_container" style="display: <?= $extensionRequested ? 'block' : 'none' ?>;">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-secondary">
                            Proposed Extended Completion Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="extended_end_date" id="extended_end_date" class="form-control" value="<?= e($extendedEndDate) ?>">
                        <small class="text-muted">Must be later than current Project Completion Date.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-secondary">
                            Additional Funds Requested (₹ INR)
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" name="additional_funds_requested" id="additional_funds_requested" class="form-control font-monospace fw-bold" step="0.01" min="0" value="<?= e($additionalFundsRequested ?: '0.00') ?>" placeholder="0.00">
                        </div>
                        <small class="text-muted">Enter 0 if requesting time extension only without additional funds.</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold text-secondary">
                            Scientific Justification for Extension & Funds <span class="text-danger">*</span>
                        </label>
                        <textarea name="extension_justification" id="extension_justification" class="form-control" rows="3" placeholder="Provide detailed scientific justification outlining why the extension is required, pending milestones/experiments, deliverables to be completed during the extended tenure, and break-up of additional funds requested..."><?= e($extensionJustification) ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Project Closeout Remarks / Covering Note -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
            <i class="bi bi-chat-left-quote text-primary me-2 fs-5"></i>
            <h6 class="fw-bold text-dark m-0"><?= $isHod ? "HOD's Project Closeout Remarks / Note for Joint Director" : "Scientist's Project Closeout Remarks / Note for Reviewers (HOD & Directorate)" ?></h6>
        </div>
        <div class="card-body">
            <label class="form-label small fw-semibold text-secondary">Optional Remarks / Closeout Note for <?= $isHod ? 'Joint Director' : 'Head of Department' ?></label>
            <textarea name="submission_remarks" class="form-control" rows="2" placeholder="<?= $isHod ? 'Provide any concluding observations, institutional handover notes, or remarks for the Joint Director...' : 'Provide any concluding observations, institutional handover notes, or remarks for the Head of Department and Joint Director...' ?>"><?= e($submissionRemarks ?? '') ?></textarea>
            <div class="form-text extra-small text-muted">These comments and remarks will be prominently visible to the <?= $isHod ? 'Joint Director' : 'HOD' ?> when screening and reviewing your completion report.</div>
        </div>
    </div>

    <!-- Submission Actions -->
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body d-flex justify-content-between align-items-center">
            <a href="<?= $isHod ? url("/hod/dashboard.php") : url("/scientist/proposals.php") ?>" class="btn btn-outline-secondary">Cancel</a>
            <div class="d-flex gap-2">
                <?php if ($selectedProject && (int)$selectedProject['scientist_id'] !== $userId && !$isHod): ?>
                    <span class="badge bg-secondary-subtle text-secondary border px-3 py-2 font-monospace">
                        <i class="bi bi-lock me-1"></i> Co-PI View-Only Mode
                    </span>
                    <a href="<?= url("/scientist/view-progress.php?project_id=" . $selectedProject['id']) ?>" class="btn btn-primary px-3" style="background-color: #1a365d; border-color: #1a365d;">
                        <i class="bi bi-eye me-1"></i> View Reports & Observations
                    </a>
                <?php else: ?>
                    <button type="submit" name="submit_action" value="draft" class="btn btn-outline-primary" formnovalidate onclick="const el = document.getElementById('submit_action_hidden'); if(el) el.value = 'draft'; window.isDraftAction = true;">
                        <i class="bi bi-save me-1"></i> Save as Draft
                    </button>
                    <button type="submit" name="submit_action" value="submit" class="btn btn-primary fw-semibold px-4" style="background-color: #1a365d; border-color: #1a365d;" id="submit_hod_btn" onclick="const el = document.getElementById('submit_action_hidden'); if(el) el.value = 'submit'; window.isDraftAction = false;">
                        <i class="bi bi-send-check me-1"></i> <?= $isHod ? 'Submit to Joint Director' : 'Submit to HOD' ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<!-- CKEditor 5 CDN -->
<script src="https://cdn.ckeditor.com/ckeditor5/41.1.0/classic/ckeditor.js"></script>

<script>
// Portal scientists for Co-PIs
const PORTAL_SCIENTISTS = <?= json_encode(array_map(function($s) {
    return [
        'id' => (int)$s['id'],
        'name' => $s['name'],
        'email' => $s['email'],
        'designation' => $s['designation'] ?: ($s['role_id'] == ROLE_HOD ? 'Head of Department' : 'Scientist'),
        'department' => $s['department_name'] ?: 'NDRI',
        'department_code' => $s['department_code'] ?: '',
        'institution' => 'NDRI, Karnal (' . ($s['department_name'] ?: 'General') . ')'
    ];
}, $portalScientists)) ?>;

// Existing Co-PIs for draft editing or pre-fills
const EXISTING_COPIS = <?= json_encode($existingCopis) ?>;

// Active projects in database for autofill
const ACTIVE_PROJECTS = <?= json_encode(array_map(function($p) {
    return [
        'id' => (int)$p['id'],
        'project_number' => $p['project_number'],
        'project_type' => $p['project_type'],
        'funding_agency' => $p['funding_agency'] ?: '',
        'funding_agency_type' => $p['funding_agency_type'] ?: ($p['prop_funding_agency_type'] ?? 'National'),
        'yearly_budget' => $p['yearly_budget'] ?: ($p['prop_yearly_budget'] ?? null),
        'yearly_budget_html' => render_yearly_budget_html($p['yearly_budget'] ?: ($p['prop_yearly_budget'] ?? null), (float)$p['approved_budget']),
        'title' => $p['proposal_title'] ?: ($p['title'] ?: ''),
        'department_id' => (int)$p['department_id'],
        'department_name' => $p['department_name'] ?: '',
        'start_date' => $p['start_date'] ?: '',
        'end_date' => $p['end_date'] ?: '',
        'start_date_formatted' => !empty($p['start_date']) ? format_date($p['start_date']) : '',
        'end_date_formatted' => !empty($p['end_date']) ? format_date($p['end_date']) : '',
        'approved_budget' => (float)$p['approved_budget'],
        'approved_budget_formatted' => format_currency((float)$p['approved_budget']),
        'institute_priority_area' => $p['institute_priority_area'] ?: 'A',
        'institute_priority_title' => get_priority_area_title($p['institute_priority_area'] ?: 'A'),
        'national_priority_area' => $p['national_priority_area'] ?: '',
        'trl_level' => (int)($p['trl_level'] ?: 3),
        'scientist_name' => $p['scientist_name'] ?: '',
        'scientist_designation' => $p['scientist_designation'] ?: '',
        'copis' => $p['copis'] ?? []
    ];
}, $activeProjects)) ?>;

function handleDbProjectSelection(selectedVal) {
    const hiddenId = document.getElementById('linked_project_id');
    const dbCard = document.getElementById('db-project-info-card');
    const manualFields = document.getElementById('manual-project-fields-section');
    const dbTimeline = document.getElementById('db-timeline-budget-summary');
    const manualTimeline = document.getElementById('manual-timeline-budget-inputs');

    const titleInput = document.getElementById('title');
    const deptSelect = document.getElementById('department_id');
    const inHouseRadio = document.getElementById('type_in_house');
    const fundingRadio = document.getElementById('type_funding_agency');
    const fundingAgencyInput = document.getElementById('funding_agency');
    const projectCodeInput = document.getElementById('project_number');
    const trlSelect = document.getElementById('trl_level');
    const prioritySelect = document.getElementById('institute_priority_area');
    const nationalSelect = document.getElementById('national_priority_area_select');
    const nationalOther = document.getElementById('national_priority_area_other');
    const nationalOtherCol = document.getElementById('national_priority_other_wrapper');
    const startDateInput = document.getElementById('proposed_start_date');
    const endDateInput = document.getElementById('proposed_end_date');
    const budgetAllocInput = document.getElementById('budget_allocated');

    if (!selectedVal || selectedVal === 'manual') {
        hiddenId.value = '';
        if (dbCard) dbCard.style.display = 'none';
        if (manualFields) manualFields.style.display = 'block';
        if (dbTimeline) dbTimeline.style.display = 'none';
        if (manualTimeline) manualTimeline.style.display = 'block';

        // Re-enable validation for manual entry
        if (titleInput) titleInput.required = true;
        if (deptSelect) deptSelect.required = true;
        if (prioritySelect) prioritySelect.required = true;
        if (startDateInput) startDateInput.required = true;
        if (endDateInput) endDateInput.required = true;
        if (budgetAllocInput) budgetAllocInput.required = true;
        if (nationalSelect) nationalSelect.required = true;
        handleNationalPriorityChange();

        toggleFundingAgencyInput();
        calculateProjectDuration();
        calculateBudgetUtilization();
        return;
    }

    const projId = parseInt(selectedVal, 10);
    const proj = ACTIVE_PROJECTS.find(p => p.id === projId);
    if (!proj) return;

    hiddenId.value = proj.id;

    // Show Database Record card and hide manual entry blocks (Co-PI section remains visible)
    if (dbCard) dbCard.style.display = 'block';
    if (manualFields) manualFields.style.display = 'none';
    if (dbTimeline) dbTimeline.style.display = 'block';
    if (manualTimeline) manualTimeline.style.display = 'none';

    // Populate Co-PIs into Section 2
    const copiContainer = document.getElementById('copi-container');
    const copiNotice = document.getElementById('no-copi-notice');
    if (copiContainer) {
        copiContainer.innerHTML = '';
        copiIndex = 0;
        if (proj.copis && proj.copis.length > 0) {
            proj.copis.forEach(cp => {
                addCoPiRow({
                    name: cp.name || cp.co_pi_name || '',
                    institution: cp.institution || '',
                    designation: cp.designation || '',
                    email: cp.email || ''
                });
            });
            if (copiNotice) copiNotice.style.display = 'none';
        } else {
            if (copiNotice) copiNotice.style.display = 'block';
        }
    }

    // Populate Database Record card
    const cardNum = document.getElementById('db_card_project_number');
    if (cardNum) cardNum.innerHTML = `<i class="bi bi-patch-check-fill text-primary me-1"></i> [${escapeHtml(proj.project_number)}]`;

    const cardTitle = document.getElementById('db_card_title');
    if (cardTitle) cardTitle.textContent = proj.title;

    const cardCategory = document.getElementById('db_card_category');
    if (cardCategory) {
        if (proj.project_type === 'funding_agency') {
            const agencyName = proj.funding_agency || 'External';
            const nameBadge = `<span class="badge bg-light text-dark border"><i class="bi bi-bank2 text-success me-1"></i>${escapeHtml(agencyName)}</span>`;
            cardCategory.innerHTML = `<div><span class="badge bg-success text-white shadow-xs me-1"><i class="bi bi-bank2 me-1"></i>Externally Funded</span> ${nameBadge}</div><div class="text-muted extra-small mt-1">Funding Agency: <strong>${escapeHtml(agencyName)}</strong></div>`;
        } else {
            cardCategory.innerHTML = `<span class="badge bg-secondary-subtle text-secondary border"><i class="bi bi-house-door-fill me-1"></i>In-house (Core Mandate)</span>`;
        }
    }

    const cardTrl = document.getElementById('db_card_trl');
    if (cardTrl) cardTrl.innerHTML = `<span class="badge bg-primary text-white">TRL-${proj.trl_level || 3}</span>`;

    const cardDeptPriority = document.getElementById('db_card_dept_priority');
    if (cardDeptPriority) {
        cardDeptPriority.textContent = `${proj.department_name} • ${proj.institute_priority_title}`;
    }

    const cardPi = document.getElementById('db_card_pi');
    if (cardPi) {
        cardPi.textContent = `${proj.scientist_name || 'Dr. Scientist'} (${proj.scientist_designation || 'Scientist'})`;
    }

    const cardCopis = document.getElementById('db_card_copis_list');
    if (cardCopis) {
        if (proj.copis && proj.copis.length > 0) {
            let copiHtml = '<div class="d-flex flex-wrap gap-2">';
            proj.copis.forEach(cp => {
                const cName = cp.name || cp.co_pi_name || 'Co-Investigator';
                const cDesig = cp.designation || 'Co-PI';
                const cInst = cp.institution || '';
                copiHtml += `
                    <div class="badge bg-light text-dark border p-2 text-start d-inline-flex align-items-center gap-2">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width: 28px; height: 28px; font-size: 0.75rem; flex-shrink: 0;">
                            <i class="bi bi-person-fill"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.85rem;"><i class="bi bi-person-check-fill text-success me-1"></i>${escapeHtml(cName)}</div>
                            <div class="extra-small text-muted">${escapeHtml(cDesig)}${cInst ? ' &bull; ' + escapeHtml(cInst) : ''}</div>
                        </div>
                    </div>
                `;
            });
            copiHtml += '</div>';
            cardCopis.innerHTML = copiHtml;
        } else {
            cardCopis.innerHTML = '<span class="text-muted small"><i class="bi bi-info-circle me-1"></i>No Co-PIs recorded in original proposal master.</span>';
        }
    }

    // Populate timeline and budget summary for DB project
    const timelineDates = document.getElementById('db_timeline_dates');
    if (timelineDates) {
        timelineDates.innerHTML = `${proj.start_date_formatted} &rarr; ${proj.end_date_formatted}`;
    }

    const timelineBudget = document.getElementById('db_timeline_budget');
    if (timelineBudget) {
        timelineBudget.textContent = proj.approved_budget_formatted;
    }

    const yearlyContainer = document.getElementById('db_yearly_budget_container');
    const yearlyTotalBadge = document.getElementById('db_yearly_budget_total_badge');
    if (yearlyContainer && proj.yearly_budget_html) {
        yearlyContainer.innerHTML = proj.yearly_budget_html;
    }
    if (yearlyTotalBadge) {
        yearlyTotalBadge.textContent = 'Total: ' + proj.approved_budget_formatted;
    }

    // Populate hidden / form fields so form submits seamlessly
    if (titleInput) {
        titleInput.value = proj.title;
        titleInput.required = false;
    }
    if (deptSelect) {
        deptSelect.value = proj.department_id;
        deptSelect.required = false;
    }
    if (prioritySelect) {
        prioritySelect.value = proj.institute_priority_area;
        prioritySelect.required = false;
    }
    if (nationalSelect) {
        nationalSelect.required = false;
        const natVal = proj.national_priority_area || '';
        let found = false;
        for (let opt of nationalSelect.options) {
            if (opt.value && opt.value === natVal) {
                nationalSelect.value = natVal;
                found = true;
                break;
            }
        }
        if (!found && natVal) {
            nationalSelect.value = 'OTHER';
            if (nationalOtherCol) nationalOtherCol.style.display = 'block';
            if (nationalOther) nationalOther.value = natVal;
        } else if (found) {
            if (nationalOtherCol) nationalOtherCol.style.display = 'none';
            if (nationalOther) nationalOther.value = '';
        } else {
            nationalSelect.value = '';
            if (nationalOtherCol) nationalOtherCol.style.display = 'none';
            if (nationalOther) nationalOther.value = '';
        }
    }
    if (trlSelect) trlSelect.value = proj.trl_level;

    if (proj.project_type === 'funding_agency') {
        if (fundingRadio) fundingRadio.checked = true;
        if (inHouseRadio) inHouseRadio.checked = false;
        if (fundingAgencyInput) {
            fundingAgencyInput.value = proj.funding_agency;
            fundingAgencyInput.required = false;
        }
        if (projectCodeInput) {
            projectCodeInput.value = '';
            projectCodeInput.required = false;
        }
    } else {
        if (inHouseRadio) inHouseRadio.checked = true;
        if (fundingRadio) fundingRadio.checked = false;
        if (projectCodeInput) {
            projectCodeInput.value = proj.project_number;
            projectCodeInput.required = false;
        }
        if (fundingAgencyInput) {
            fundingAgencyInput.value = '';
            fundingAgencyInput.required = false;
        }
    }

    if (startDateInput) {
        startDateInput.value = proj.start_date;
        startDateInput.required = false;
    }
    if (endDateInput) {
        endDateInput.value = proj.end_date;
        endDateInput.required = false;
        const extEnd = document.getElementById('extended_end_date');
        if (extEnd) extEnd.min = proj.end_date;
    }
    if (budgetAllocInput) {
        budgetAllocInput.value = proj.approved_budget;
        budgetAllocInput.required = false;
    }

    // Calculate duration display
    calculateProjectDuration();
    const durationDisplay = document.getElementById('project_duration_display');
    const timelineDuration = document.getElementById('db_timeline_duration');
    if (durationDisplay && timelineDuration && durationDisplay.value) {
        timelineDuration.textContent = 'Calculated Duration: ' + durationDisplay.value;
    }

    calculateBudgetUtilization();
}

function toggleAdditionalCoPiSection() {
    const wrapper = document.getElementById('additional-copi-wrapper');
    const toggleText = document.getElementById('add-collaborator-toggle-text');
    if (!wrapper) return;
    
    if (wrapper.style.display === 'none') {
        wrapper.style.display = 'block';
        if (toggleText) toggleText.textContent = '- Hide Additional Collaborator / Co-PI Section';
        const container = document.getElementById('additional-copi-container');
        if (container && container.children.length === 0) {
            addCoPiRow('additional-copi-container');
        }
    } else {
        wrapper.style.display = 'none';
        if (toggleText) toggleText.textContent = '+ Add Additional Collaborator / Co-PI for Completion';
    }
}

function toggleExtensionFields() {
    const isChecked = document.getElementById('extension_requested').checked;
    const container = document.getElementById('extension_fields_container');
    const extEnd = document.getElementById('extended_end_date');
    const extJust = document.getElementById('extension_justification');

    if (container) container.style.display = isChecked ? 'block' : 'none';
    if (extEnd) extEnd.required = isChecked;
    if (extJust) extJust.required = isChecked;
}

function handleFundingAgencyChange() {
    const sel = document.getElementById('funding_agency_select');
    const otherCol = document.getElementById('funding_agency_other_wrapper');
    const otherInput = document.getElementById('funding_agency_other');
    const selectCol = document.getElementById('funding_agency_select_col');

    if (!sel) return;

    if (sel.value === 'OTHER') {
        if (otherCol) otherCol.style.display = 'block';
        if (selectCol) {
            selectCol.classList.remove('col-12');
            selectCol.classList.add('col-md-6');
        }
        if (otherInput) {
            otherInput.required = true;
        }
    } else {
        if (otherCol) otherCol.style.display = 'none';
        if (selectCol) {
            selectCol.classList.remove('col-md-6');
            selectCol.classList.add('col-12');
        }
        if (otherInput) {
            otherInput.required = false;
        }
    }
}

function handleNationalPriorityChange() {
    const sel = document.getElementById('national_priority_area_select');
    const otherCol = document.getElementById('national_priority_other_wrapper');
    const otherInput = document.getElementById('national_priority_area_other');
    const hiddenId = document.getElementById('linked_project_id');
    const isManual = !hiddenId || !hiddenId.value;

    if (!sel) return;

    if (sel.value === 'OTHER') {
        if (otherCol) otherCol.style.display = 'block';
        if (otherInput) {
            otherInput.required = isManual;
            otherInput.focus();
        }
    } else {
        if (otherCol) otherCol.style.display = 'none';
        if (otherInput) {
            otherInput.required = false;
        }
    }
}

// Toggle funding agency input and project code input for manual mode
function toggleFundingAgencyInput() {
    const isFunding = document.getElementById('type_funding_agency').checked;
    const hiddenId = document.getElementById('linked_project_id');
    const isDbProject = hiddenId && hiddenId.value !== '';
    
    // Funding agency input wrapper
    const fundingWrapper = document.getElementById('funding-agency-wrapper');
    const agencySelect = document.getElementById('funding_agency_select');
    const agencyOther = document.getElementById('funding_agency_other');
    if (fundingWrapper) fundingWrapper.style.display = isFunding ? 'block' : 'none';
    if (agencySelect) agencySelect.required = !isDbProject && isFunding;
    if (isFunding) {
        handleFundingAgencyChange();
    } else {
        if (agencyOther) agencyOther.required = false;
    }

    // Project code input wrapper (ONLY for In-house projects; hidden for Funding Agency)
    const codeWrapper = document.getElementById('project-code-wrapper');
    const codeInput = document.getElementById('project_number');
    if (codeWrapper) codeWrapper.style.display = isFunding ? 'none' : 'block';
    if (codeInput) {
        codeInput.required = !isDbProject && !isFunding;
        if (isFunding && !isDbProject) {
            codeInput.value = '';
        }
    }
}

// Calculate Duration
function calculateProjectDuration() {
    const startVal = document.getElementById('proposed_start_date').value;
    const endVal = document.getElementById('proposed_end_date').value;
    const display = document.getElementById('project_duration_display');
    const badge = document.getElementById('project_duration_badge');

    if (!startVal || !endVal) {
        if (display) display.value = 'Select start & end dates';
        if (badge) {
            badge.className = 'badge bg-secondary-subtle text-secondary px-3 py-2 fs-6';
            badge.textContent = 'Dates Required';
        }
        return;
    }

    const start = new Date(startVal);
    const end = new Date(endVal);

    if (isNaN(start.getTime()) || isNaN(end.getTime())) {
        if (display) display.value = 'Invalid dates';
        return;
    }

    if (end < start) {
        if (display) display.value = 'End date cannot precede start date';
        if (badge) {
            badge.className = 'badge bg-danger-subtle text-danger px-3 py-2 fs-6';
            badge.textContent = 'Invalid Range';
        }
        return;
    }

    let years = end.getFullYear() - start.getFullYear();
    let months = end.getMonth() - start.getMonth();
    let days = end.getDate() - start.getDate();

    if (days < 0) {
        months--;
        const prevMonth = new Date(end.getFullYear(), end.getMonth(), 0);
        days += prevMonth.getDate();
    }
    if (months < 0) {
        years--;
        months += 12;
    }

    // If inclusive tenure gives 11 months and 28+ days (e.g. 11 months and 30 days), round up to full year
    if (months === 11 && days >= 28) {
        years += 1;
        months = 0;
        days = 0;
    } else if (days >= 30) {
        months += 1;
        days = 0;
        if (months >= 12) {
            years += 1;
            months = 0;
        }
    }

    const parts = [];
    if (years > 0) parts.push(years + (years === 1 ? ' Year' : ' Years'));
    if (months > 0) parts.push(months + (months === 1 ? ' Month' : ' Months'));
    if (days > 0 || parts.length === 0) parts.push(days + (days === 1 ? ' Day' : ' Days'));

    const res = parts.join(', ');
    if (display) display.value = res;
    if (badge) {
        badge.className = 'badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fs-6 fw-semibold';
        badge.textContent = res;
    }
}

// Calculate Budget Utilization
function calculateBudgetUtilization() {
    const alloc = parseFloat(document.getElementById('budget_allocated').value) || 0;
    const util = parseFloat(document.getElementById('budget_utilized').value) || 0;
    const badge = document.getElementById('budget_rate_badge');
    const diffText = document.getElementById('budget_difference_text');

    if (!badge) return;

    if (alloc <= 0) {
        badge.textContent = '0.0% Utilized';
        badge.className = 'badge bg-secondary-subtle text-secondary px-3 py-2 fs-6 fw-bold';
        if (diffText) diffText.textContent = 'Enter utilized amount to compute balance against allocated budget.';
        return;
    }

    const rate = ((util / alloc) * 100).toFixed(1);
    const balance = alloc - util;

    badge.textContent = rate + '% Utilized';
    if (rate > 100) {
        badge.className = 'badge bg-danger text-white px-3 py-2 fs-6 fw-bold';
        if (diffText) diffText.textContent = 'Expenditure exceeded allocation by ₹' + Math.abs(balance).toLocaleString('en-IN', {maximumFractionDigits: 2});
    } else if (rate >= 85) {
        badge.className = 'badge bg-success text-white px-3 py-2 fs-6 fw-bold';
        if (diffText) diffText.textContent = 'Optimal utilization. Remaining balance: ₹' + balance.toLocaleString('en-IN', {maximumFractionDigits: 2});
    } else {
        badge.className = 'badge bg-warning text-dark px-3 py-2 fs-6 fw-bold';
        if (diffText) diffText.textContent = 'Remaining balance: ₹' + balance.toLocaleString('en-IN', {maximumFractionDigits: 2});
    }
}

// Word count tracking for 250-word final report
function updateWordCount() {
    const textInput = document.getElementById('final_report');
    if (!textInput) return 0;
    let text = (typeof finalReportEditor !== 'undefined' && finalReportEditor) ? finalReportEditor.getData() : textInput.value;
    if (text.includes('<') && text.includes('>')) {
        const temp = document.createElement('div');
        temp.innerHTML = text;
        text = (temp.textContent || temp.innerText || '').trim();
    } else {
        text = text.trim();
    }
    text = text.replace(/[\s\u00A0\u2000-\u200B]+/g, ' ').trim();
    const words = text.length > 0 ? text.split(/\s+/).filter(w => w.length > 0).length : 0;
    const counter = document.getElementById('final_report_counter');
    const warning = document.getElementById('word_limit_warning');

    if (counter) {
        counter.innerHTML = `<i class="bi bi-chat-left-text me-1"></i> ${words} / 250 words`;

        if (words > 250) {
            counter.className = 'badge bg-danger text-white border fw-bold';
            if (warning) {
                warning.style.display = 'block';
                warning.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> Exceeds limit by ${words - 250} word(s)! Please shorten.`;
            }
        } else {
            counter.className = words >= 200 ? 'badge bg-warning-subtle text-warning border fw-semibold' : 'badge bg-light text-primary border fw-semibold';
            if (warning) warning.style.display = 'none';
        }
    }
    return words;
}

// --- Co-PI Management (Searchable Combobox for 150+ Scientists + External) ---
let copiIndex = 0;

window.addCoPiRow = function(prefillData = null) {
    const container = document.getElementById('copi-container');
    const notice = document.getElementById('no-copi-notice');
    if (!container) return;

    const currentRows = container.querySelectorAll('.copi-card');
    if (currentRows.length >= 15) {
        alert('Maximum 15 Co-PIs allowed per proposal.');
        return;
    }

    copiIndex++;
    const rowId = `copi-card-${copiIndex}`;
    const card = document.createElement('div');
    card.className = 'card border mb-3 shadow-2xs copi-card bg-white';
    card.id = rowId;

    // Build portal scientists options for hidden select compatibility
    let portalOptions = '';
    PORTAL_SCIENTISTS.forEach(s => {
        const isSelected = (prefillData && prefillData.selectedId == s.id) ? 'selected' : '';
        portalOptions += `<option value="${s.id}" ${isSelected} data-type="internal" data-name="${escapeHtml(s.name)}" data-inst="${escapeHtml(s.institution)}" data-desig="${escapeHtml(s.designation)}" data-email="${escapeHtml(s.email)}" data-dept="${escapeHtml(s.department)}">
            ${escapeHtml(s.name)} — ${escapeHtml(s.department)} | ${escapeHtml(s.designation)} (${escapeHtml(s.email)})
        </option>`;
    });

    const isExternalSelected = (prefillData && prefillData.isExternal) ? 'selected' : '';

    card.innerHTML = `
        <div class="card-header bg-light-subtle d-flex justify-content-between align-items-center py-2 px-3">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary text-white rounded-pill px-2">Co-PI #${copiIndex}</span>
                <span class="copi-status-badge badge bg-secondary-subtle text-secondary border">Select Scientist</span>
            </div>
            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" onclick="removeCoPiRow('${rowId}')" title="Remove Co-PI">
                <i class="bi bi-trash me-1"></i> Remove
            </button>
        </div>
        <div class="card-body p-3">
            <div class="mb-3 copi-search-container position-relative">
                <label class="form-label small fw-bold text-dark mb-1 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person-search text-primary me-1"></i> Choose Scientist from NDRI Portal or External Institute:</span>
                    <span class="badge bg-light text-muted border extra-small"><i class="bi bi-people me-1"></i>${PORTAL_SCIENTISTS.length} Scientists in Directory</span>
                </label>
                
                <!-- Live Search Box with Filter & Browse -->
                <div class="input-group">
                    <span class="input-group-text bg-white text-primary"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control copi-search-input" 
                           placeholder="Type scientist name or division to search (e.g. Sharma, Animal Nutrition)..." 
                           autocomplete="off"
                           onfocus="openCoPiDropdown('${rowId}')"
                           oninput="filterCoPiDropdown('${rowId}', this.value)">
                    <button class="btn btn-outline-secondary dropdown-toggle" type="button" 
                            onclick="toggleCoPiDropdown('${rowId}')" title="Browse all scientists">
                        <span class="d-none d-sm-inline small">Browse All</span>
                    </button>
                    <button class="btn btn-outline-danger btn-clear-search d-none" type="button" 
                            onclick="clearCoPiSearch('${rowId}')" title="Clear selection">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <!-- Hidden native select for standard form processing -->
                <select class="form-select copi-select d-none" onchange="handleCoPiSelect(this, '${rowId}')">
                    <option value="">-- Select Co-PI Scientist from NDRI Portal --</option>
                    <optgroup label="NDRI Portal Scientists & HODs">
                        ${portalOptions}
                    </optgroup>
                    <optgroup label="Other / External Institution">
                        <option value="EXTERNAL" ${isExternalSelected} data-type="external">➕ Other / External Institute Scientist</option>
                    </optgroup>
                </select>

                <!-- Custom Search Results Dropdown -->
                <div class="dropdown-menu w-100 shadow-lg border copi-dropdown-results p-0 mt-1" 
                     id="copi-results-${rowId}" 
                     style="max-height: 290px; overflow-y: auto; display: none; z-index: 1050;">
                    <div class="p-2 border-bottom bg-light d-flex justify-content-between align-items-center">
                        <span class="extra-small text-muted search-count-text">
                            <i class="bi bi-people me-1"></i> ${PORTAL_SCIENTISTS.length} Portal Scientists Available
                        </span>
                        <span class="badge bg-primary-subtle text-primary extra-small">Instant Live Filter</span>
                    </div>
                    <div class="list-group list-group-flush copi-list-group">
                        <!-- Populated dynamically -->
                    </div>
                </div>

                <div class="form-text text-muted extra-small mt-1">
                    Start typing to instantly search through 150+ scientists by name, division, or designation, or choose "Other / External" for external collaborators.
                </div>
            </div>

            <!-- Co-PI Detailed Input Fields -->
            <div class="copi-fields-wrapper row g-2">
                <div class="col-md-3">
                    <label class="form-label extra-small text-muted mb-1">Co-PI Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="copi_name[]" class="form-control form-control-sm copi-name" placeholder="Scientist Name" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label extra-small text-muted mb-1">Institution / Division <span class="text-danger">*</span></label>
                    <input type="text" name="copi_institution[]" class="form-control form-control-sm copi-institution" placeholder="Institution / Division Name" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label extra-small text-muted mb-1">Designation</label>
                    <input type="text" name="copi_designation[]" class="form-control form-control-sm copi-designation" placeholder="e.g. Senior Scientist">
                </div>
                <div class="col-md-2">
                    <label class="form-label extra-small text-muted mb-1">Email Address</label>
                    <input type="email" name="copi_email[]" class="form-control form-control-sm copi-email" placeholder="name@icar.org.in">
                </div>
                <div class="col-12 mt-1">
                    <div class="copi-meta-text text-muted extra-small"></div>
                </div>
            </div>
        </div>
    `;

    container.appendChild(card);
    if (notice) notice.style.display = 'none';

    // Handle pre-fill if given
    if (prefillData) {
        const select = card.querySelector('.copi-select');
        let matched = null;
        const testEmail = prefillData.email || prefillData.co_pi_email;
        const testName = prefillData.name || prefillData.co_pi_name;
        if (testEmail) {
            matched = PORTAL_SCIENTISTS.find(s => s.email && s.email.toLowerCase() === testEmail.toLowerCase());
        }
        if (!matched && testName) {
            matched = PORTAL_SCIENTISTS.find(s => s.name && s.name.toLowerCase() === testName.toLowerCase());
        }

        if (matched) {
            select.value = matched.id;
            handleCoPiSelect(select, rowId);
        } else {
            select.value = 'EXTERNAL';
            handleCoPiSelect(select, rowId, {
                name: testName || '',
                institution: prefillData.institution || '',
                designation: prefillData.designation || '',
                email: testEmail || ''
            });
        }
    }
};

window.removeCoPiRow = function(rowId) {
    const card = document.getElementById(rowId);
    if (card) {
        card.remove();
    }
    const container = document.getElementById('copi-container');
    const notice = document.getElementById('no-copi-notice');
    if (container && container.querySelectorAll('.copi-card').length === 0) {
        if (notice) notice.style.display = 'block';
    }
};

window.removeCoPiCard = function(idx) {
    removeCoPiRow('copi-card-' + idx);
};

window.openCoPiDropdown = function(rowId) {
    const card = document.getElementById(rowId);
    if (!card) return;
    const input = card.querySelector('.copi-search-input');
    const query = input ? input.value : '';
    filterCoPiDropdown(rowId, query);
};

window.toggleCoPiDropdown = function(rowId) {
    const menu = document.getElementById(`copi-results-${rowId}`);
    if (menu && menu.style.display === 'block') {
        menu.style.display = 'none';
    } else {
        filterCoPiDropdown(rowId, '');
        const card = document.getElementById(rowId);
        const input = card ? card.querySelector('.copi-search-input') : null;
        if (input) input.focus();
    }
};

window.clearCoPiSearch = function(rowId) {
    const card = document.getElementById(rowId);
    if (!card) return;
    const select = card.querySelector('.copi-select');
    if (select) {
        select.value = '';
        handleCoPiSelect(select, rowId);
    }
    const input = card.querySelector('.copi-search-input');
    if (input) {
        input.value = '';
        input.focus();
    }
    const menu = document.getElementById(`copi-results-${rowId}`);
    if (menu) menu.style.display = 'none';
};

window.filterCoPiDropdown = function(rowId, query) {
    const card = document.getElementById(rowId);
    if (!card) return;
    const menu = document.getElementById(`copi-results-${rowId}`);
    if (!menu) return;

    // Close any other open dropdowns
    document.querySelectorAll('.copi-dropdown-results').forEach(m => {
        if (m.id !== `copi-results-${rowId}`) m.style.display = 'none';
    });

    const listGroup = menu.querySelector('.copi-list-group');
    const countText = menu.querySelector('.search-count-text');
    if (!listGroup) return;

    const q = (query || '').toLowerCase().trim();
    let filtered = PORTAL_SCIENTISTS;
    if (q.length > 0 && !q.includes('➕ external institute scientist')) {
        filtered = PORTAL_SCIENTISTS.filter(s => {
            return (s.name && s.name.toLowerCase().includes(q)) ||
                   (s.department && s.department.toLowerCase().includes(q)) ||
                   (s.designation && s.designation.toLowerCase().includes(q)) ||
                   (s.email && s.email.toLowerCase().includes(q));
        });
    }

    if (countText) {
        countText.innerHTML = `<i class="bi bi-people me-1"></i> ${filtered.length} of ${PORTAL_SCIENTISTS.length} Scientists Found`;
    }

    let itemsHtml = `
        <a href="javascript:void(0)" class="list-group-item list-group-item-action list-group-item-warning py-2 px-3 fw-bold d-flex align-items-center justify-content-between" onclick="selectCoPiFromMenu('${rowId}', 'EXTERNAL')">
            <div>
                <i class="bi bi-globe me-2 text-warning"></i> ➕ Other / External Institute Scientist
                <div class="extra-small text-muted fw-normal">For collaborators from IVRI, IARI, SAUs, or other universities</div>
            </div>
            <span class="badge bg-warning text-dark border">External</span>
        </a>
    `;

    if (filtered.length === 0) {
        itemsHtml += `
            <div class="p-3 text-center text-muted small">
                <i class="bi bi-search text-secondary d-block fs-5 mb-1"></i>
                No NDRI scientists found matching "<strong>${escapeHtml(query)}</strong>".
                <div class="extra-small text-muted mt-1">Try another keyword, or click above for external collaborator.</div>
            </div>
        `;
    } else {
        filtered.forEach(s => {
            itemsHtml += `
                <a href="javascript:void(0)" class="list-group-item list-group-item-action py-2 px-3 copi-menu-item" onclick="selectCoPiFromMenu('${rowId}', ${s.id})">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold text-dark mb-0">${escapeHtml(s.name)}</div>
                            <div class="extra-small text-muted">
                                <i class="bi bi-building me-1 text-primary"></i>${escapeHtml(s.department)} &bull; <span class="text-secondary">${escapeHtml(s.designation)}</span>
                            </div>
                        </div>
                        <span class="badge bg-light text-secondary border font-monospace extra-small ms-2">${escapeHtml(s.email)}</span>
                    </div>
                </a>
            `;
        });
    }

    listGroup.innerHTML = itemsHtml;
    menu.style.display = 'block';
};

window.selectCoPiFromMenu = function(rowId, val) {
    const card = document.getElementById(rowId);
    if (!card) return;
    const select = card.querySelector('.copi-select');
    if (select) {
        select.value = val;
        handleCoPiSelect(select, rowId);
    }
    const menu = document.getElementById(`copi-results-${rowId}`);
    if (menu) menu.style.display = 'none';
};

// Global click listener to close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.copi-search-container')) {
        document.querySelectorAll('.copi-dropdown-results').forEach(m => {
            m.style.display = 'none';
        });
    }
});

window.handleCoPiSelect = function(select, rowId, customData = null) {
    const card = document.getElementById(rowId);
    if (!card) return;

    const badge = card.querySelector('.copi-status-badge');
    const nameInput = card.querySelector('.copi-name');
    const instInput = card.querySelector('.copi-institution');
    const desigInput = card.querySelector('.copi-designation');
    const emailInput = card.querySelector('.copi-email');
    const metaText = card.querySelector('.copi-meta-text');
    const searchInput = card.querySelector('.copi-search-input');
    const clearBtn = card.querySelector('.btn-clear-search');

    const selectedOption = select.options[select.selectedIndex];
    const val = select.value;

    if (!val) {
        if (badge) {
            badge.className = 'copi-status-badge badge bg-secondary-subtle text-secondary border';
            badge.textContent = 'Select Scientist';
        }
        if (searchInput) searchInput.value = '';
        if (clearBtn) clearBtn.classList.add('d-none');
        nameInput.value = '';
        instInput.value = '';
        desigInput.value = '';
        emailInput.value = '';
        nameInput.readOnly = false;
        instInput.readOnly = false;
        desigInput.readOnly = false;
        emailInput.readOnly = false;
        metaText.textContent = '';
        return;
    }

    if (val === 'EXTERNAL') {
        // External Scientist selected
        if (badge) {
            badge.className = 'copi-status-badge badge bg-warning-subtle text-dark border border-warning-subtle';
            badge.innerHTML = '<i class="bi bi-globe me-1 text-warning"></i> External Institute Scientist';
        }
        if (searchInput) searchInput.value = '➕ External Institute Scientist';
        if (clearBtn) clearBtn.classList.remove('d-none');

        nameInput.readOnly = false;
        instInput.readOnly = false;
        desigInput.readOnly = false;
        emailInput.readOnly = false;

        if (customData) {
            nameInput.value = customData.name || '';
            instInput.value = customData.institution || '';
            desigInput.value = customData.designation || '';
            emailInput.value = customData.email || '';
        } else {
            nameInput.value = '';
            instInput.value = '';
            desigInput.value = '';
            emailInput.value = '';
            nameInput.focus();
        }
        metaText.innerHTML = '<span class="text-primary"><i class="bi bi-info-circle me-1"></i> Please enter the external collaborator\'s full name, parent institute, designation and contact email.</span>';
    } else {
        // NDRI Portal Scientist selected
        const name = selectedOption.getAttribute('data-name') || '';
        const inst = selectedOption.getAttribute('data-inst') || '';
        const desig = selectedOption.getAttribute('data-desig') || '';
        const email = selectedOption.getAttribute('data-email') || '';
        const dept = selectedOption.getAttribute('data-dept') || '';

        if (searchInput) searchInput.value = `${name} (${dept})`;
        if (clearBtn) clearBtn.classList.remove('d-none');

        nameInput.value = name;
        instInput.value = inst;
        desigInput.value = desig;
        emailInput.value = email;

        nameInput.readOnly = true;
        instInput.readOnly = true;
        desigInput.readOnly = true;
        emailInput.readOnly = true;

        if (badge) {
            badge.className = 'copi-status-badge badge bg-success-subtle text-success border border-success-subtle';
            badge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> NDRI Portal Scientist';
        }
        metaText.innerHTML = `<span class="text-success"><i class="bi bi-building me-1"></i> <strong>Division:</strong> ${escapeHtml(dept)} &nbsp;|&nbsp; <strong>Designation:</strong> ${escapeHtml(desig)} &nbsp;|&nbsp; <strong>Email:</strong> ${escapeHtml(email)}</span>`;
    }
};

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

// --- CKEditor 5 Initialization ---
let achievementsEditor = null;
let finalReportEditor = null;
let previousIrcAtrEditor = null;
let outputOutcomeEditor = null;

if (typeof ClassicEditor !== 'undefined') {
    const editorConfig = {
        toolbar: [
            'heading', '|',
            'bold', 'italic', 'underline', 'strikethrough', '|',
            'bulletedList', 'numberedList', '|',
            'outdent', 'indent', '|',
            'insertTable', 'blockQuote', '|',
            'undo', 'redo'
        ]
    };

    const achEl = document.querySelector('#significant_achievements');
    if (achEl) {
        ClassicEditor.create(achEl, editorConfig)
            .then(editor => {
                achievementsEditor = editor;
                editor.model.document.on('change:data', () => {
                    achEl.value = editor.getData();
                });
            })
            .catch(err => console.warn('CKEditor Significant Achievements Init:', err));
    }

    const frEl = document.querySelector('#final_report');
    if (frEl) {
        ClassicEditor.create(frEl, editorConfig)
            .then(editor => {
                finalReportEditor = editor;
                editor.model.document.on('change:data', () => {
                    frEl.value = editor.getData();
                    updateWordCount();
                });
                setTimeout(updateWordCount, 300);
            })
            .catch(err => console.warn('CKEditor Final Report Init:', err));
    }

    const atrEl = document.querySelector('#previous_irc_atr');
    if (atrEl) {
        ClassicEditor.create(atrEl, editorConfig)
            .then(editor => {
                previousIrcAtrEditor = editor;
                editor.model.document.on('change:data', () => {
                    atrEl.value = editor.getData();
                });
            })
            .catch(err => console.warn('CKEditor Previous IRC ATR Init:', err));
    }

    const outEl = document.querySelector('#output_outcome');
    if (outEl) {
        ClassicEditor.create(outEl, editorConfig)
            .then(editor => {
                outputOutcomeEditor = editor;
                editor.model.document.on('change:data', () => {
                    outEl.value = editor.getData();
                });
            })
            .catch(err => console.warn('CKEditor Output / Outcome Init:', err));
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Check if we have prefilled Co-PIs from draft edit
    if (typeof EXISTING_COPIS !== 'undefined' && EXISTING_COPIS && EXISTING_COPIS.length > 0) {
        const container = document.getElementById('copi-container');
        if (container) container.innerHTML = '';
        copiIndex = 0;
        EXISTING_COPIS.forEach(cp => {
            addCoPiRow({
                name: cp.co_pi_name || cp.name || '',
                institution: cp.institution || '',
                designation: cp.designation || '',
                email: cp.email || ''
            });
        });
    } else {
        const selectElem = document.getElementById('selected_db_project_id');
        if (selectElem && selectElem.value && selectElem.value !== 'manual') {
            handleDbProjectSelection(selectElem.value);
        } else {
            toggleFundingAgencyInput();
            handleNationalPriorityChange();
            calculateProjectDuration();
            calculateBudgetUtilization();
        }
    }
    updateWordCount();

    const form = document.getElementById('completed-proposal-form') || document.querySelector('form[method="POST"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Synchronize CKEditor 5 instances
            if (achievementsEditor) {
                document.querySelector('#significant_achievements').value = achievementsEditor.getData();
            }
            if (finalReportEditor) {
                document.querySelector('#final_report').value = finalReportEditor.getData();
            }
            if (previousIrcAtrEditor) {
                document.querySelector('#previous_irc_atr').value = previousIrcAtrEditor.getData();
            }
            if (outputOutcomeEditor) {
                document.querySelector('#output_outcome').value = outputOutcomeEditor.getData();
            }

            const submitBtn = e.submitter || document.activeElement;
            const isDraft = window.isDraftAction || (submitBtn && (submitBtn.value === 'draft' || submitBtn.getAttribute('value') === 'draft'));
            if (isDraft) {
                const draftActionInput = document.getElementById('submit_action_hidden');
                if (draftActionInput) draftActionInput.value = 'draft';
                form.querySelectorAll('[required]').forEach(el => el.removeAttribute('required'));
                return true;
            }

            // Validate National Priority Area
            const hiddenId = document.getElementById('linked_project_id');
            const isManual = !hiddenId || !hiddenId.value;
            const natPrioritySel = document.getElementById('national_priority_area_select');
            const natPriorityOther = document.getElementById('national_priority_area_other');
            if (isManual && natPrioritySel && !natPrioritySel.value) {
                e.preventDefault();
                natPrioritySel.focus();
                natPrioritySel.scrollIntoView({ behavior: 'smooth', block: 'center' });
                alert('Please select a National Priority Area.');
                return false;
            }
            if (natPrioritySel && natPrioritySel.value === 'OTHER' && (!natPriorityOther || !natPriorityOther.value.trim())) {
                e.preventDefault();
                if (natPriorityOther) {
                    natPriorityOther.focus();
                    natPriorityOther.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                alert('Please specify the custom National Priority Area.');
                return false;
            }

            // Word count limit for Final Report on submit
            const finalWords = updateWordCount();
            if (finalWords > 250) {
                e.preventDefault();
                alert(`Final Report must be strictly 250 words or less.\nMaximum allowed: 250 words.\nCurrent word count: ${finalWords} words.\n\nPlease shorten the summary before submitting.`);
                const frCard = document.querySelector('#final_report');
                if (frCard) frCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            // Validate mandatory rich text fields
            const achRaw = (document.querySelector('#significant_achievements').value || '').replace(/<[^>]*>/g, '').trim();
            if (!achRaw) {
                e.preventDefault();
                alert('Significant Achievements is mandatory.');
                document.querySelector('#significant_achievements').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            const frRaw = (document.querySelector('#final_report').value || '').replace(/<[^>]*>/g, '').trim();
            if (!frRaw) {
                e.preventDefault();
                alert('Final Report is mandatory.');
                document.querySelector('#final_report').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            const atrRaw = (document.querySelector('#previous_irc_atr').value || '').replace(/<[^>]*>/g, '').trim();
            if (!atrRaw) {
                e.preventDefault();
                alert('Action Taken Report (ATR) on Recommendations of Previous IRC is mandatory (enter N/A if not applicable).');
                document.querySelector('#previous_irc_atr').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            const outRaw = (document.querySelector('#output_outcome').value || '').replace(/<[^>]*>/g, '').trim();
            if (!outRaw) {
                e.preventDefault();
                alert('Output / Outcome is mandatory.');
                document.querySelector('#output_outcome').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
