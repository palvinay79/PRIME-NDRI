<?php
/**
 * Research Proposal and Project Management System
 * Scientist - Edit Draft or Resubmit Returned Proposal
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD]);

$currentUser = current_user();
$userId = current_user_id();
$isHod = (current_user_role_id() === ROLE_HOD);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    flash('danger', 'Invalid proposal ID.');
    header("Location: " . url("/scientist/proposals.php"));
    exit;
}

$db = get_db();
$stmt = $db->prepare("SELECT p.*, d.department_name, d.department_code
                      FROM proposals p
                      LEFT JOIN departments d ON p.department_id = d.id
                      WHERE p.id = ?");
$stmt->execute([$id]);
$proposal = $stmt->fetch();

if (!$proposal) {
    flash('danger', 'Proposal not found.');
    header("Location: " . url("/scientist/proposals.php"));
    exit;
}

// Route ongoing progress reports to ongoing-projects.php
if (($proposal['proposal_category'] ?? '') === 'ongoing') {
    header("Location: " . url("/scientist/ongoing-projects.php?id={$id}"));
    exit;
}

// Route completed projects to create-completed-proposal.php
if (($proposal['proposal_category'] ?? '') === 'completed') {
    header("Location: " . url("/scientist/create-completed-proposal.php?id={$id}"));
    exit;
}

if (!can_edit_proposal($proposal)) {
    flash('danger', 'This proposal is locked and cannot be edited in its current workflow status.');
    header("Location: " . url("/scientist/proposal-details.php?id={$id}"));
    exit;
}

$pageTitle = 'Edit Proposal - ' . $proposal['proposal_number'];
$currentUser = current_user();
$userId = current_user_id();

// Fetch Co-PIs
$copis = get_proposal_copis($id, (int)($proposal['linked_project_id'] ?? 0), (string)($proposal['project_number'] ?? ''));

// Fetch active portal scientists and HODs only for Co-PI dropdown (no Admin, no Joint Director, and not current PI)
$portalScientists = $db->query("
    SELECT u.id, u.name, u.email, u.designation, u.role_id, d.department_name, d.department_code 
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id 
    WHERE u.status = 'active'
      AND u.role_id IN (" . ROLE_SCIENTIST . ", " . ROLE_HOD . ")
      AND u.id != " . (int)$userId . "
    ORDER BY u.name ASC
")->fetchAll();

// Fetch latest return comments if returned
$returnComment = null;
if (in_array($proposal['current_status'], [STATUS_RETURNED_HOD, STATUS_RETURNED_JD])) {
    $stmt = $db->prepare("SELECT * FROM proposal_status_history
                          WHERE proposal_id = ? AND new_status LIKE 'Returned%'
                          ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$id]);
    $returnComment = $stmt->fetch();
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $submissionAction = $_POST['submit_action'] ?? 'draft'; // 'save_draft' or 'resubmit'

    $projectType = sanitize($_POST['project_type'] ?? '');
    $fundingAgency = trim(sanitize($_POST['funding_agency'] ?? ''));
    $fundingAgencyType = sanitize($_POST['funding_agency_type'] ?? 'National');
    if (!in_array($fundingAgencyType, ['National', 'International'])) {
        $fundingAgencyType = 'National';
    }
    $title = sanitize($_POST['title'] ?? '');
    $discipline = trim(sanitize($_POST['discipline'] ?? ($proposal['discipline'] ?? '')));
    $rawPriority = trim(sanitize($_POST['institute_priority_area'] ?? ''));
    if (preg_match('/^(PROGRAM\s*)?([A-F])/i', $rawPriority, $matches)) {
        $institutePriority = strtoupper($matches[2]);
    } else {
        $institutePriority = strtoupper($rawPriority);
    }
    $nationalPriority = sanitize($_POST['national_priority_area'] ?? '');
    $trlLevel = (int)($_POST['trl_level'] ?? 1);

    $researchProblem = sanitize($_POST['research_problem'] ?? '');
    $baselineInfo = sanitize($_POST['baseline_info'] ?? '');
    $noveltyGap = sanitize($_POST['novelty_gap_analysis'] ?? '');
    $justification = sanitize($_POST['justification_end_users'] ?? '');
    $alignment = sanitize($_POST['institute_alignment'] ?? '');
    $technicalProgram = sanitize($_POST['technical_program'] ?? '');
    $objectives = sanitize_html($_POST['objectives'] ?? '');
    $methodology = null; // Methodology removed
    $expectedOutcomes = sanitize($_POST['expected_outcomes'] ?? '');
    $budget = (float)($_POST['proposed_budget'] ?? 0);

    // Process Year-wise Budget Allocation
    $yearlyAmounts = $_POST['yearly_budget_amount'] ?? [];
    $yearlyLabels = $_POST['yearly_budget_year'] ?? [];
    $yearlyBudgetMap = [];
    $sumYearly = 0.0;
    if (!empty($yearlyAmounts) && is_array($yearlyAmounts)) {
        for ($yi = 0; $yi < count($yearlyAmounts); $yi++) {
            $amt = (float)($yearlyAmounts[$yi] ?? 0);
            $lbl = trim(sanitize($yearlyLabels[$yi] ?? ''));
            if (empty($lbl)) {
                $lbl = 'Year ' . ($yi + 1);
            }
            if ($amt > 0 || count($yearlyAmounts) === 1) {
                $yearlyBudgetMap[$lbl] = $amt;
                $sumYearly += $amt;
            }
        }
    }
    if ($sumYearly > 0) {
        $budget = $sumYearly;
    } elseif ($budget > 0 && empty($yearlyBudgetMap)) {
        $yearlyBudgetMap = ['Year 1' => $budget];
    }
    $yearlyBudgetJson = !empty($yearlyBudgetMap) ? json_encode($yearlyBudgetMap) : null;

    $budgetJustification = sanitize($_POST['budget_justification'] ?? '');
    $startDate = !empty($_POST['proposed_start_date']) ? $_POST['proposed_start_date'] : null;
    $endDate = !empty($_POST['proposed_end_date']) ? $_POST['proposed_end_date'] : null;
    $resubmitRemarks = sanitize($_POST['resubmit_remarks'] ?? '');

    // Word count calculation helper
    $countWords = function($str) {
        $clean = trim(strip_tags((string)$str));
        return !empty($clean) ? count(preg_split('/\s+/', $clean)) : 0;
    };
    $rpWords = $countWords($researchProblem);
    $biWords = $countWords($baselineInfo);
    $novWords = $countWords($noveltyGap);
    $justWords = $countWords($justification);
    $alignWords = $countWords($alignment);
    $techWords = $countWords($technicalProgram);

    $isSrsErs = in_array(strtoupper($proposal['department_code'] ?? ''), ['SRS', 'ERS']);

    if ($submissionAction === 'resubmit' || $submissionAction === 'update_submitted') {
        if (empty($projectType) || !in_array($projectType, ['in_house', 'funding_agency'])) {
            $error = "Please select whether this is an In-house Project or Externally Funded Project. This selection is mandatory.";
        } elseif ($projectType === 'funding_agency' && empty($fundingAgency)) {
            $error = "Funding Agency Name is mandatory for Externally Funded Projects. Please enter the funding agency name.";
        } elseif (empty($title)) {
            $error = "Proposal title is mandatory.";
        } elseif ($isSrsErs && empty($discipline)) {
            $error = "Discipline is required for Regional Station ({$proposal['department_code']}).";
        } elseif (empty($institutePriority) || !in_array($institutePriority, ['A', 'B', 'C', 'D', 'E', 'F'])) {
            $error = "Institute Priority Area is mandatory. Please select Program A, B, C, D, E, or F.";
        } elseif (empty($nationalPriority)) {
            $error = "National Priority Area is mandatory.";
        } elseif (empty($trlLevel)) {
            $error = "Technology Readiness Level (TRL) is mandatory.";
        } elseif (empty($researchProblem)) {
            $error = "Research Problem / Key Questions is mandatory.";
        } elseif (empty($baselineInfo)) {
            $error = "Baseline Information is mandatory.";
        } elseif (empty($noveltyGap)) {
            $error = "Novelty / Gap Analysis is mandatory.";
        } elseif (empty($justification)) {
            $error = "Justification & End Users is mandatory.";
        } elseif (empty($alignment)) {
            $error = "Institute Alignment is mandatory.";
        } elseif (empty($technicalProgram)) {
            $error = "Technical Program Proposed is mandatory.";
        } elseif (empty(trim(strip_tags($objectives)))) {
            $error = "Specific Objectives are mandatory.";
        } elseif (empty($expectedOutcomes)) {
            $error = "Expected Outcomes & Deliverables are mandatory.";
        } elseif ($budget <= 0) {
            $error = "Proposed Budget is mandatory and must be greater than zero.";
        } elseif (empty($budgetJustification)) {
            $error = "Budget Justification is mandatory.";
        } elseif (empty($startDate) || empty($endDate)) {
            $error = "Both Proposed Start Date and End Date are mandatory.";
        } elseif (strtotime($endDate) <= strtotime($startDate)) {
            $error = "Proposed End Date must be after the Start Date.";
        } elseif ($rpWords > 100) {
            $error = "Research Problem / Key Questions must be strictly 100 words or less. Current word count is {$rpWords} words.";
        } elseif ($biWords > 100) {
            $error = "Baseline Information must be strictly 100 words or less. Current word count is {$biWords} words.";
        } elseif ($novWords > 75) {
            $error = "Novelty / Gap Analysis must be strictly 75 words or less. Current word count is {$novWords} words.";
        } elseif ($justWords > 100) {
            $error = "Justification & End Users must be strictly 100 words or less. Current word count is {$justWords} words.";
        } elseif ($alignWords > 50) {
            $error = "Institute Alignment must be strictly 50 words or less. Current word count is {$alignWords} words.";
        } elseif ($techWords > 250) {
            $error = "Technical Program proposed must be strictly 250 words or less. Current word count is {$techWords} words.";
        }
    } else {
        // Draft saving: no mandatory fields required!
        if (empty($title)) {
            $title = $proposal['title'] ?: ('Draft Proposal - ' . date('d M Y H:i'));
        }
        if (empty($projectType)) {
            $projectType = $proposal['project_type'] ?: 'in_house';
        }
        if (empty($institutePriority)) {
            $institutePriority = $proposal['institute_priority_area'] ?: 'A';
        }
    }

    if (empty($error)) {
        try {
            $db->beginTransaction();

            $prevStatus = $proposal['current_status'];
            $newStatus = $prevStatus;

            if ($submissionAction === 'resubmit' || $submissionAction === 'submit') {
                // If submitter is HOD, their submission goes directly to Joint Director
                if ($isHod) {
                    $newStatus = STATUS_FORWARDED_JD;
                } else {
                    $newStatus = STATUS_SUBMITTED_HOD;
                }
            } elseif ($prevStatus === STATUS_SUBMITTED_HOD) {
                // Remains submitted to HOD so HOD reviews the latest edited version
                $newStatus = STATUS_SUBMITTED_HOD;
            } elseif ($prevStatus === STATUS_FORWARDED_JD && $isHod) {
                // HOD updating while under Joint Director review
                $newStatus = STATUS_FORWARDED_JD;
            }

            $submissionRemarksToSave = !empty($resubmitRemarks) ? $resubmitRemarks : ($proposal['submission_remarks'] ?? null);

            $now = date('Y-m-d H:i:s');
            $sql = "UPDATE proposals SET
                project_type = ?, funding_agency = ?, funding_agency_type = ?,
                title = ?, discipline = ?, institute_priority_area = ?, national_priority_area = ?, trl_level = ?,
                research_problem = ?, baseline_info = ?, novelty_gap_analysis = ?, justification_end_users = ?,
                institute_alignment = ?, technical_program = ?, objectives = ?, methodology = ?,
                expected_outcomes = ?, proposed_budget = ?, yearly_budget = ?, budget_justification = ?,
                submission_remarks = ?,
                proposed_start_date = ?, proposed_end_date = ?, current_status = ?,
                updated_at = ?
                WHERE id = ?";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $projectType, ($projectType === 'funding_agency' ? $fundingAgency : null),
                ($projectType === 'funding_agency' ? $fundingAgencyType : 'National'),
                $title, ($discipline ?: null), $institutePriority, $nationalPriority, $trlLevel,
                $researchProblem, $baselineInfo, $noveltyGap, $justification,
                $alignment, $technicalProgram, $objectives, $methodology,
                $expectedOutcomes, $budget, $yearlyBudgetJson, $budgetJustification,
                $submissionRemarksToSave,
                $startDate, $endDate, $newStatus,
                $now,
                $id
            ]);

            // Update Co-PIs
            $db->prepare("DELETE FROM proposal_co_pis WHERE proposal_id = ?")->execute([$id]);
            $copiNames = $_POST['copi_name'] ?? [];
            $copiInsts = $_POST['copi_institution'] ?? [];
            $copiDesigs = $_POST['copi_designation'] ?? [];
            $copiEmails = $_POST['copi_email'] ?? [];

            if (!empty($copiNames) && is_array($copiNames)) {
                $stmtCoPi = $db->prepare("INSERT INTO proposal_co_pis (proposal_id, co_pi_name, institution, designation, email) VALUES (?, ?, ?, ?, ?)");
                for ($i = 0; $i < count($copiNames); $i++) {
                    $cName = sanitize($copiNames[$i] ?? '');
                    if (!empty($cName)) {
                        $stmtCoPi->execute([
                            $id,
                            $cName,
                            sanitize($copiInsts[$i] ?? ''),
                            sanitize($copiDesigs[$i] ?? ''),
                            sanitize($copiEmails[$i] ?? '')
                        ]);
                    }
                }
            }

            // Record status change, comments and audit log
            if ($submissionAction === 'resubmit' || $submissionAction === 'submit') {
                $submitterRole = $isHod ? 'Head of Department' : 'Scientist';
                $histComment = $isHod 
                    ? "Proposal revised and resubmitted by Head of Department directly to Joint Director for approval."
                    : "Proposal revised and resubmitted by Scientist.";
                if (!empty($resubmitRemarks)) {
                    $histComment .= " Remarks: " . $resubmitRemarks;
                    add_proposal_comment($id, $userId, ($isHod ? 'HOD Resubmission' : 'Scientist Resubmission'), $resubmitRemarks);
                }
                record_status_history($id, $prevStatus, $newStatus, $userId, $currentUser['role_name'], $histComment);
                log_audit($userId, 'PROPOSAL_RESUBMITTED', 'proposals', $id, "Proposal {$proposal['proposal_number']} resubmitted by {$submitterRole}.");
            } elseif ($prevStatus === STATUS_FORWARDED_JD && $isHod) {
                $histComment = "Proposal updated with revisions by Head of Department while pending Joint Director review.";
                if (!empty($resubmitRemarks)) {
                    $histComment .= " Remarks: " . $resubmitRemarks;
                    add_proposal_comment($id, $userId, 'HOD Update', $resubmitRemarks);
                }
                record_status_history($id, $prevStatus, $newStatus, $userId, $currentUser['role_name'], $histComment);
                log_audit($userId, 'PROPOSAL_UPDATED_UNDER_JD', 'proposals', $id, "Proposal {$proposal['proposal_number']} updated by HOD while under review by Joint Director.");
            } elseif ($prevStatus === STATUS_SUBMITTED_HOD) {
                $histComment = "Proposal updated with revisions by Scientist while pending HOD review.";
                if (!empty($resubmitRemarks)) {
                    $histComment .= " Remarks: " . $resubmitRemarks;
                    add_proposal_comment($id, $userId, 'Scientist Update', $resubmitRemarks);
                }
                record_status_history($id, $prevStatus, $newStatus, $userId, $currentUser['role_name'], $histComment);
                log_audit($userId, 'PROPOSAL_UPDATED_UNDER_HOD', 'proposals', $id, "Proposal {$proposal['proposal_number']} updated by Scientist while under review by HOD.");
            } else {
                if (!empty($resubmitRemarks)) {
                    add_proposal_comment($id, $userId, ($isHod ? 'HOD Note' : 'Scientist Note'), $resubmitRemarks);
                }
                log_audit($userId, 'PROPOSAL_UPDATED', 'proposals', $id, "Proposal {$proposal['proposal_number']} updated as {$newStatus}.");
            }

            $db->commit();

            if ($submissionAction === 'resubmit' || $submissionAction === 'submit') {
                if ($isHod) {
                    flash('success', "Proposal {$proposal['proposal_number']} has been successfully submitted directly to Joint Director for review.");
                } else {
                    flash('success', "Proposal {$proposal['proposal_number']} has been successfully resubmitted to Head of Department for review.");
                }
            } elseif ($prevStatus === STATUS_FORWARDED_JD && $isHod) {
                flash('success', "Proposal {$proposal['proposal_number']} was successfully updated and remains submitted to Joint Director for review.");
            } elseif ($prevStatus === STATUS_SUBMITTED_HOD) {
                flash('success', "Proposal {$proposal['proposal_number']} was successfully updated and remains submitted to Head of Department for review.");
            } else {
                flash('info', "Draft proposal changes saved successfully.");
            }

            header("Location: " . url("/scientist/proposal-details.php?id={$id}"));
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error updating proposal: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Edit Proposal: <?= e($proposal['proposal_number']) ?></h3>
        <p class="text-muted small mb-0">Current Status: <?= render_status_badge($proposal['current_status']) ?></p>
    </div>
    <a href="<?= url("/scientist/proposal-details.php?id=" . ($id) . "") ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Cancel & View
    </a>
</div>

<?php if ($proposal['current_status'] === STATUS_SUBMITTED_HOD): ?>
    <div class="alert alert-primary shadow-sm mb-4" role="alert" style="border-left: 5px solid #1a365d;">
        <div class="d-flex align-items-center">
            <i class="bi bi-info-circle-fill fs-4 me-3 text-primary"></i>
            <div>
                <h6 class="alert-heading fw-bold mb-1">Proposal Currently Under HOD Review</h6>
                <p class="mb-0 small text-dark">This proposal is currently with the Head of Department for review. You can make edits, corrections, or updates to any section at any time until the HOD takes an approval or rejection decision.</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($returnComment): ?>
    <div class="alert alert-danger shadow-sm mb-4" role="alert">
        <h6 class="alert-heading fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Reviewer's Feedback for Changes:</h6>
        <p class="mb-1 text-dark fst-italic">"<?= e($returnComment['comments']) ?>"</p>
        <small class="text-muted d-block mt-2">Returned by: <strong><?= e($returnComment['action_by_role']) ?></strong> on <?= format_datetime($returnComment['created_at']) ?></small>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-octagon-fill me-2"></i> <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<form method="POST" action="<?= url("/scientist/edit-proposal.php?id=" . ($id) . "") ?>" enctype="multipart/form-data" id="proposal-form">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <!-- Section 1: Basic Information -->
    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-info-circle text-primary"></i> 1. Administrative & Lead Investigator Details
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Division / Department</label>
                    <input type="text" class="form-control bg-light" value="<?= e($proposal['department_name']) ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">TRL Level (1-9)</label>
                    <select name="trl_level" class="form-select">
                        <?php for ($trl = 1; $trl <= 9; $trl++): ?>
                            <option value="<?= $trl ?>" <?= (int)$proposal['trl_level'] === $trl ? 'selected' : '' ?>>TRL-<?= $trl ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <?php 
                $isSrsErsDept = in_array(strtoupper($proposal['department_code'] ?? ''), ['SRS', 'ERS']);
                ?>
                <?php if ($isSrsErsDept): ?>
                <div class="col-md-12">
                    <label for="discipline" class="form-label small fw-semibold text-secondary">
                        <i class="bi bi-mortarboard text-primary me-1"></i> Discipline <span class="text-danger">*</span>
                        <span class="badge bg-primary-subtle text-primary ms-1" style="font-size: 0.7rem;">Required for <?= e($proposal['department_code']) ?></span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="bi bi-mortarboard"></i></span>
                        <input type="text" class="form-control" id="discipline" name="discipline"
                               value="<?= e($proposal['discipline'] ?? '') ?>"
                               placeholder="e.g. Dairy Cattle Nutrition / Dairy Microbiology"
                               list="disciplineList" required>
                    </div>
                    <datalist id="disciplineList">
                        <option value="Dairy Cattle Nutrition">
                        <option value="Animal Genetics & Breeding">
                        <option value="Livestock Production & Management">
                        <option value="Animal Reproduction, Gynecology & Obstetrics">
                        <option value="Animal Biotechnology">
                        <option value="Dairy Microbiology">
                        <option value="Dairy Chemistry">
                        <option value="Dairy Technology">
                        <option value="Dairy Engineering">
                        <option value="Dairy Economics & Statistics">
                        <option value="Dairy Extension Education">
                        <option value="Forage Agronomy">
                    </datalist>
                    <div class="form-text extra-small text-muted">Discipline recorded for regional station scientist.</div>
                </div>
                <?php endif; ?>

                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary d-block mb-1">
                        Project Category / Funding Source <span class="text-danger">*</span>
                    </label>
                    <?php $currentType = $proposal['project_type'] ?? 'in_house'; ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="d-block p-3 rounded border h-100 bg-white shadow-sm" id="box-in-house" style="cursor: pointer;">
                                <div class="form-check m-0">
                                    <input class="form-check-input" type="radio" name="project_type" id="type_in_house" value="in_house" <?= ($currentType !== 'funding_agency') ? 'checked' : '' ?> required onchange="toggleFundingAgencyInput()">
                                    <span class="form-check-label fw-bold text-dark ms-1">
                                        <i class="bi bi-house-door-fill text-primary me-1"></i> In-house Project
                                    </span>
                                </div>
                                <div class="text-muted small mt-2 ps-4" style="line-height: 1.4;">
                                    Institutional research project funded under NDRI regular budget mandate. The Joint Director assigns a custom Project ID upon approval.
                                </div>
                            </label>
                        </div>
                        <div class="col-md-6">
                            <label class="d-block p-3 rounded border h-100 bg-white shadow-sm" id="box-funding-agency" style="cursor: pointer;">
                                <div class="form-check m-0">
                                    <input class="form-check-input" type="radio" name="project_type" id="type_funding_agency" value="funding_agency" <?= ($currentType === 'funding_agency') ? 'checked' : '' ?> required onchange="toggleFundingAgencyInput()">
                                    <span class="form-check-label fw-bold text-dark ms-1">
                                        <i class="bi bi-bank2 text-success me-1"></i> Externally Funded Project
                                    </span>
                                </div>
                                <div class="text-muted small mt-2 ps-4" style="line-height: 1.4;">
                                    Externally sponsored research project (e.g. DBT, DST, National Extra-Mural, BIRAC, Industry). Enter the funding agency name below.
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <?php
                $currentAgencyType = $proposal['funding_agency_type'] ?? 'National';
                ?>
                <div class="col-12" id="funding-agency-wrapper" style="<?= ($currentType === 'funding_agency') ? '' : 'display: none;' ?>">
                    <div class="p-3 bg-light rounded border border-success-subtle shadow-xs">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-5">
                                <label class="form-label small fw-bold text-dark mb-1">
                                    <i class="bi bi-globe2 text-success me-1"></i> Agency Classification <span class="text-danger">*</span>
                                </label>
                                <div class="d-flex gap-2">
                                    <label class="d-flex align-items-center flex-fill p-2 rounded border bg-white shadow-xs" style="cursor: pointer;" id="opt-agency-national">
                                        <input type="radio" name="funding_agency_type" id="agency_type_national" value="National" class="form-check-input me-2 mt-0" <?= ($currentAgencyType !== 'International') ? 'checked' : '' ?> onchange="updateAgencyTypeUi()">
                                        <div>
                                            <span class="fw-bold text-primary small d-block"><i class="bi bi-flag-fill me-1"></i> National</span>
                                            <span class="text-muted extra-small">ICAR, DBT, DST, SERB, CSIR, MoFPI, etc.</span>
                                        </div>
                                    </label>
                                    <label class="d-flex align-items-center flex-fill p-2 rounded border bg-white shadow-xs" style="cursor: pointer;" id="opt-agency-international">
                                        <input type="radio" name="funding_agency_type" id="agency_type_international" value="International" class="form-check-input me-2 mt-0" <?= ($currentAgencyType === 'International') ? 'checked' : '' ?> onchange="updateAgencyTypeUi()">
                                        <div>
                                            <span class="fw-bold text-info small d-block"><i class="bi bi-globe me-1"></i> International</span>
                                            <span class="text-muted extra-small">FAO, World Bank, Gates Fdn, IAEA, etc.</span>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-7">
                                <label for="funding_agency" class="form-label small fw-bold text-dark mb-1">
                                    <i class="bi bi-bank2 text-success me-1"></i> Funding Agency Name <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-bank text-success"></i></span>
                                    <input type="text" 
                                           name="funding_agency" 
                                           id="funding_agency" 
                                           class="form-control fw-semibold" 
                                           placeholder="e.g. Department of Biotechnology (DBT), DST, BIRAC, FAO..." 
                                           value="<?= e($proposal['funding_agency'] ?? '') ?>">
                                </div>
                                <div class="form-text text-muted extra-small" id="funding_agency_hint">Specify the official name of the granting / sponsoring organization.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Project Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" value="<?= e($proposal['title']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Institute Priority Area <span class="text-danger">*</span></label>
                    <select name="institute_priority_area" class="form-select" required>
                        <option value="">-- Select Institute Priority Program (Program A to F) --</option>
                        <?php foreach (get_institute_priority_programs() as $pCode => $pDesc): ?>
                            <option value="<?= e($pCode) ?>" <?= strtoupper(trim($proposal['institute_priority_area'] ?? '')) === $pCode ? 'selected' : '' ?>>
                                <?= e($pDesc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted extra-small">Mandatory selection from institutional research programs A through F.</div>
                </div>
                <div class="col-md-12">
                    <label class="form-label small fw-semibold text-secondary">National Priority Area <span class="text-danger">*</span></label>
                    <input type="text" name="national_priority_area" class="form-control" placeholder="Enter National Priority Area..." value="<?= e($proposal['national_priority_area'] ?? '') ?>" required>
                    <div class="form-text text-muted extra-small">Specify the relevant National Priority Area, mission, or initiative.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Project Associates / Co-PIs -->
    <div class="detail-section-card">
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
                <!-- Dynamically loaded Co-PI Cards -->
            </div>
            <div id="no-copi-notice" class="text-center p-3 border rounded bg-light text-muted small" style="display: none;">
                <i class="bi bi-info-circle me-1 text-primary"></i> No Co-PIs currently assigned. Click <strong>"Add Co-PI"</strong> above to add collaborators.
            </div>
        </div>
    </div>

    <!-- Section 3: Scientific Description -->
    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-journal-text text-primary"></i> 3. Research Problem, Gap Analysis & Justification
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <!-- 1: Research Problem / Key Questions - 100 words max -->
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold text-secondary m-0">
                            Research Problem / Key Questions <span class="text-danger">*</span>
                        </label>
                        <span id="research_problem_counter" class="badge bg-light text-primary border fw-semibold">
                            <i class="bi bi-chat-left-text me-1"></i> 0 / 100 words
                        </span>
                    </div>
                    <textarea name="research_problem" id="research_problem" class="form-control" rows="3" placeholder="Define the primary scientific or technological problem being addressed (max 100 words)..." required oninput="updateFieldWordCount('research_problem', 100)"><?= e($proposal['research_problem'] ?? '') ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="form-text text-muted extra-small">Maximum 100 words allowed.</div>
                        <div id="research_problem_warning" class="text-danger small fw-bold" style="display: none;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded!
                        </div>
                    </div>
                </div>

                <!-- Baseline Information - 100 words max -->
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold text-secondary m-0">
                            Baseline Information <span class="text-danger">*</span>
                        </label>
                        <span id="baseline_info_counter" class="badge bg-light text-primary border fw-semibold">
                            <i class="bi bi-chat-left-text me-1"></i> 0 / 100 words
                        </span>
                    </div>
                    <textarea name="baseline_info" id="baseline_info" class="form-control" rows="3" placeholder="Benchmarks, any leads" required oninput="updateFieldWordCount('baseline_info', 100)"><?= e($proposal['baseline_info'] ?? '') ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="form-text text-muted extra-small">Maximum 100 words allowed.</div>
                        <div id="baseline_info_warning" class="text-danger small fw-bold" style="display: none;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded!
                        </div>
                    </div>
                </div>

                <!-- 2: Novelty / Gap Analysis - 75 words max -->
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold text-secondary m-0">
                            Novelty / Gap Analysis <span class="text-danger">*</span>
                        </label>
                        <span id="novelty_gap_analysis_counter" class="badge bg-light text-primary border fw-semibold">
                            <i class="bi bi-chat-left-text me-1"></i> 0 / 75 words
                        </span>
                    </div>
                    <textarea name="novelty_gap_analysis" id="novelty_gap_analysis" class="form-control" rows="3" placeholder="Identified research gaps and clear scientific novelty (max 75 words)..." required oninput="updateFieldWordCount('novelty_gap_analysis', 75)"><?= e($proposal['novelty_gap_analysis'] ?? '') ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="form-text text-muted extra-small">Maximum 75 words allowed.</div>
                        <div id="novelty_gap_analysis_warning" class="text-danger small fw-bold" style="display: none;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded!
                        </div>
                    </div>
                </div>

                <!-- Justification & End Users - 100 words max -->
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold text-secondary m-0">
                            Justification & End Users <span class="text-danger">*</span>
                        </label>
                        <span id="justification_end_users_counter" class="badge bg-light text-primary border fw-semibold">
                            <i class="bi bi-chat-left-text me-1"></i> 0 / 100 words
                        </span>
                    </div>
                    <textarea name="justification_end_users" id="justification_end_users" class="form-control" rows="3" placeholder="Target beneficiaries (dairy farmers, processing plants, breeders, policymakers) (max 100 words)..." required oninput="updateFieldWordCount('justification_end_users', 100)"><?= e($proposal['justification_end_users'] ?? '') ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="form-text text-muted extra-small">Maximum 100 words allowed.</div>
                        <div id="justification_end_users_warning" class="text-danger small fw-bold" style="display: none;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded!
                        </div>
                    </div>
                </div>

                <!-- 3: Institute Alignment - 50 words max -->
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold text-secondary m-0">
                            Institute Alignment <span class="text-danger">*</span>
                        </label>
                        <span id="institute_alignment_counter" class="badge bg-light text-primary border fw-semibold">
                            <i class="bi bi-chat-left-text me-1"></i> 0 / 50 words
                        </span>
                    </div>
                    <textarea name="institute_alignment" id="institute_alignment" class="form-control" rows="2" placeholder="How the project is aligned with the vision mandate of the institute" required oninput="updateFieldWordCount('institute_alignment', 50)"><?= e($proposal['institute_alignment'] ?? '') ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="form-text text-muted extra-small">Maximum 50 words allowed.</div>
                        <div id="institute_alignment_warning" class="text-danger small fw-bold" style="display: none;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded!
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 4: Technical Program & Objectives -->
    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-bullseye text-primary"></i> 4. Technical Program & Objectives
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary d-flex justify-content-between align-items-center">
                        <span>Specific Objectives <span class="text-danger">*</span></span>
                        <span class="badge bg-light text-primary border"><i class="bi bi-fonts me-1"></i>Rich Text Editor (CKEditor)</span>
                    </label>
                    <textarea name="objectives" id="objectives" class="form-control" rows="6"><?= e($proposal['objectives'] ?? '') ?></textarea>
                    <div class="form-text text-muted extra-small">State clear, quantifiable research objectives (numbered or bulleted lists supported).</div>
                </div>

                <!-- 4: Technical Program Proposed - 250 words max -->
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold text-secondary m-0">
                            Technical Program Proposed (objective wise, also indicate the role of Co Pis) <span class="text-danger">*</span>
                        </label>
                        <span id="technical_program_counter" class="badge bg-light text-primary border fw-semibold">
                            <i class="bi bi-chat-left-text me-1"></i> 0 / 250 words
                        </span>
                    </div>
                    <textarea name="technical_program" id="technical_program" class="form-control" rows="6" placeholder="1&#10;2&#10;3" required oninput="updateFieldWordCount('technical_program', 250)"><?= e($proposal['technical_program'] ?? '') ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="form-text text-muted extra-small">Objective-wise technical program, indicating the role of Co-PIs (max 250 words).</div>
                        <div id="technical_program_warning" class="text-danger small fw-bold" style="display: none;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded!
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Expected Deliverables / Outcomes <span class="text-danger">*</span></label>
                    <textarea name="expected_outcomes" class="form-control" rows="3" placeholder="Technologies, patentable formulations, research papers, high-yielding prototypes..." required><?= e($proposal['expected_outcomes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <?php
    $existingYearlyBudget = parse_yearly_budget($proposal['yearly_budget'] ?? null, (float)$proposal['proposed_budget']);
    ?>
    <!-- Section 5: Budget & Timeline (Year-wise Budget Allocation) -->
    <div class="detail-section-card">
        <div class="detail-section-header d-flex justify-content-between align-items-center">
            <div><i class="bi bi-cash-stack text-primary"></i> 5. Project Timeline & Year-wise Budget Allocation</div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                <i class="bi bi-calculator me-1"></i> Year-wise Formulation
            </span>
        </div>
        <div class="detail-section-body">
            <!-- Timeline & Dates -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Proposed Start Date <span class="text-danger">*</span></label>
                    <input type="date" name="proposed_start_date" id="proposed_start_date" class="form-control" value="<?= e($proposal['proposed_start_date']) ?>" required onchange="calculateProjectDuration()">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Proposed End Date <span class="text-danger">*</span></label>
                    <input type="date" name="proposed_end_date" id="proposed_end_date" class="form-control" value="<?= e($proposal['proposed_end_date']) ?>" required onchange="calculateProjectDuration()">
                </div>

                <!-- Calculated Project Duration Banner with Quick Year Selectors -->
                <div class="col-12">
                    <div class="p-3 bg-light rounded border d-flex flex-wrap align-items-center justify-content-between gap-3" style="border-left: 4px solid #1a365d !important;">
                        <div>
                            <div class="fw-bold text-dark d-flex align-items-center">
                                <i class="bi bi-hourglass-split text-primary fs-5 me-2"></i>
                                <span>Project Duration:</span>
                                <span id="project_duration_badge" class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fs-6 fw-bold ms-2">
                                    Duration
                                </span>
                            </div>
                            <div class="text-muted extra-small mt-1">Calculated from start and end dates. Use quick presets to adjust project tenure:</div>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;" onclick="setQuickDuration(1)">1 Year</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;" onclick="setQuickDuration(2)">2 Years</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;" id="btn-quick-dur-3" onclick="setQuickDuration(3)">3 Years</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;" onclick="setQuickDuration(4)">4 Years</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2" style="font-size: 0.78rem;" onclick="setQuickDuration(5)">5 Years</button>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="input-group input-group-sm" style="min-width: 250px;">
                                <span class="input-group-text bg-white text-primary fw-semibold"><i class="bi bi-calendar3"></i></span>
                                <input type="text" id="project_duration_display" class="form-control fw-bold text-primary bg-white fs-6" readonly value="Calculating duration...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Year-wise Budget Input Section -->
            <div class="p-3 bg-white rounded border mb-3 shadow-xs">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center">
                            <i class="bi bi-cash-coin text-success me-2 fs-5"></i>
                            Year-wise Budget Formulation (₹ INR) <span class="text-danger">*</span>
                        </h6>
                        <span class="text-muted extra-small">
                            Enter Year 1 budget first. Based on the <span id="budget_tenure_hint" class="fw-bold text-primary">project tenure</span>, click to add subsequent years.
                        </span>
                    </div>
                    <div id="add-year-btn-container">
                        <button type="button" id="btn-add-year-budget" class="btn btn-sm btn-outline-primary fw-semibold shadow-xs" onclick="addNextYearBudgetRow()">
                            <i class="bi bi-plus-circle me-1"></i> Add Next Year Budget
                        </button>
                    </div>
                </div>

                <!-- Dynamic Yearly Budget Container -->
                <div id="yearly-budget-container" class="row g-3 mb-3">
                    <?php 
                    $yIdx = 1;
                    foreach ($existingYearlyBudget as $yLabel => $yAmount): 
                    ?>
                        <div class="col-md-6 col-lg-4 yearly-budget-row" id="row-year-<?= $yIdx ?>" data-year="<?= $yIdx ?>">
                            <div class="p-3 rounded border bg-light h-100 position-relative shadow-xs">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label small fw-bold text-dark m-0">
                                        <i class="bi bi-<?= $yIdx <= 9 ? $yIdx : 'plus' ?>-circle-fill text-primary me-1"></i> <?= e($yLabel) ?> Budget (₹) <span class="text-danger">*</span>
                                    </label>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="badge bg-primary text-white extra-small"><?= $yIdx === 1 ? '1st Year' : ($yIdx === 2 ? '2nd Year' : ($yIdx === 3 ? '3rd Year' : "{$yIdx}th Year")) ?></span>
                                        <?php if ($yIdx > 1): ?>
                                            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" title="Remove <?= e($yLabel) ?>" onclick="removeYearBudgetRow(<?= $yIdx ?>)">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <span class="input-group-text bg-white fw-bold">₹</span>
                                    <input type="hidden" name="yearly_budget_year[]" value="<?= e($yLabel) ?>">
                                    <input type="number" 
                                           name="yearly_budget_amount[]" 
                                           class="form-control fw-bold fs-6 yearly-budget-input" 
                                           step="0.01" 
                                           min="0" 
                                           placeholder="e.g. 500000" 
                                           id="budget_year_<?= $yIdx ?>"
                                           value="<?= (float)$yAmount > 0 ? (float)$yAmount : '' ?>" 
                                           <?= $yIdx === 1 ? 'required' : '' ?> 
                                           oninput="calculateTotalBudget()">
                                </div>
                                <div class="form-text extra-small text-muted mt-1"><?= e($yLabel) ?> capital & operational outlay.</div>
                            </div>
                        </div>
                    <?php 
                        $yIdx++;
                    endforeach; 
                    ?>
                </div>

                <!-- Prominent Total Budget Summary Banner -->
                <div class="p-3 rounded border bg-primary-subtle border-primary-subtle d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <div class="text-secondary small fw-semibold text-uppercase">
                            <i class="bi bi-wallet2 text-primary me-1"></i> Total Proposed Budget (Sum of All Project Years)
                        </div>
                        <div class="d-flex align-items-baseline gap-2 mt-1">
                            <h3 class="fw-bold text-primary font-monospace mb-0" id="total_budget_display"><?= format_currency((float)$proposal['proposed_budget']) ?></h3>
                            <span class="badge bg-primary text-white font-monospace" id="total_years_badge"><?= count($existingYearlyBudget) ?> Year<?= count($existingYearlyBudget) !== 1 ? 's' : '' ?> Allocated</span>
                        </div>
                        <input type="hidden" name="proposed_budget" id="proposed_budget" value="<?= e($proposal['proposed_budget']) ?>">
                    </div>
                    <div id="yearly_breakdown_pill_container" class="d-flex flex-wrap gap-2 justify-content-end">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>

            <div class="col-12 mt-3">
                <label class="form-label small fw-semibold text-secondary">Budget Justification <span class="text-danger">*</span></label>
                <textarea name="budget_justification" class="form-control" rows="3" required><?= e($proposal['budget_justification']) ?></textarea>
                <div class="form-text extra-small text-muted">Provide head-wise justification for each project year.</div>
            </div>
        </div>
    </div>

    <!-- Resubmission Remarks or Submission Note -->
    <?php 
    $remarkInfo = get_latest_scientist_remark($id, $proposal);
    ?>
    <?php if (in_array($proposal['current_status'], [STATUS_RETURNED_HOD, STATUS_RETURNED_JD])): ?>
        <div class="detail-section-card border-warning shadow-sm">
            <div class="detail-section-header bg-warning-subtle text-dark d-flex justify-content-between align-items-center">
                <span><i class="bi bi-reply-fill text-warning me-1"></i> Response to Reviewer's Comments & Resubmission Remarks <span class="text-danger">*</span></span>
                <span class="badge bg-warning text-dark border border-warning">Visible to HOD</span>
            </div>
            <div class="detail-section-body">
                <?php if (!empty($remarkInfo['previous_return_comment'])): ?>
                    <div class="alert alert-danger border-danger-subtle p-3 mb-3">
                        <div class="fw-bold small text-danger text-uppercase mb-1">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reviewer's Return Directives (<?= e($remarkInfo['previous_return_role'] ?: 'Reviewer') ?>):
                        </div>
                        <div class="text-dark small fst-italic"><?= nl2br(e($remarkInfo['previous_return_comment'])) ?></div>
                    </div>
                <?php endif; ?>
                <label class="form-label small fw-bold text-dark">
                    Scientist's Clarifications / Action Taken Note / Modifications Made <span class="text-danger">*</span>
                </label>
                <textarea name="resubmit_remarks" class="form-control fw-semibold text-dark" rows="4" placeholder="Detail point-by-point modifications made in response to HOD / JD directives..." required><?= e($proposal['submission_remarks'] ?? '') ?></textarea>
                <div class="form-text extra-small text-muted">This response will be prominently visible to the Head of Department and Directorate upon resubmission.</div>
            </div>
        </div>
    <?php else: ?>
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-chat-left-text text-primary"></i> <?= $isHod ? "HOD's Submission Remarks / Note for Joint Director" : "Scientist's Submission Remarks / Note for Reviewers" ?>
            </div>
            <div class="detail-section-body">
                <label class="form-label small fw-semibold text-secondary">Optional Remarks / Note for <?= $isHod ? 'Joint Director' : 'HOD & Directorate' ?></label>
                <textarea name="resubmit_remarks" class="form-control" rows="2" placeholder="<?= $isHod ? 'Any specific context, priority highlights, or note for the Joint Director...' : 'Any specific context, priority highlights, or note for the Head of Department...' ?>"><?= e($proposal['submission_remarks'] ?? '') ?></textarea>
            </div>
        </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body d-flex justify-content-between align-items-center">
            <a href="<?= url("/scientist/proposal-details.php?id=" . ($id) . "") ?>" class="btn btn-outline-secondary">Cancel</a>
            <div class="d-flex gap-2">
                <?php if ($proposal['current_status'] === STATUS_SUBMITTED_HOD): ?>
                    <button type="submit" name="submit_action" value="update_submitted" class="btn btn-primary fw-semibold px-4" style="background-color: #1a365d; border-color: #1a365d;" data-confirm="Are you sure you want to save updates to this proposal?">
                        <i class="bi bi-check2-circle me-1"></i> Save Updates (Keep with HOD)
                    </button>
                <?php elseif ($proposal['current_status'] === STATUS_FORWARDED_JD && $isHod): ?>
                    <button type="submit" name="submit_action" value="update_submitted" class="btn btn-primary fw-semibold px-4" style="background-color: #1a365d; border-color: #1a365d;" data-confirm="Are you sure you want to save updates to this proposal?">
                        <i class="bi bi-check2-circle me-1"></i> Save Updates (Keep with Joint Director)
                    </button>
                <?php else: ?>
                    <button type="submit" name="submit_action" value="draft" class="btn btn-outline-primary" formnovalidate onclick="window.isDraftAction = true;">
                        <i class="bi bi-save me-1"></i> Save Changes (Draft)
                    </button>
                    <button type="submit" name="submit_action" value="resubmit" class="btn btn-success fw-semibold px-4" data-confirm="Are you sure you want to <?= $isHod ? 'submit this proposal directly to the Joint Director' : 'resubmit this proposal to the Head of Department' ?>?" onclick="window.isDraftAction = false;">
                        <i class="bi bi-send-check me-1"></i> <?= $isHod ? 'Submit Proposal to Joint Director' : 'Resubmit Proposal to HOD' ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<!-- CKEditor 5 CDN -->
<script src="https://cdn.ckeditor.com/ckeditor5/41.1.0/classic/ckeditor.js"></script>

<script>
// Portal scientists array for Co-PI selection
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

// Existing Co-PIs for pre-filling
const EXISTING_COPIS = <?= json_encode(array_map(function($cp) {
    return [
        'name' => $cp['co_pi_name'],
        'institution' => $cp['institution'],
        'designation' => $cp['designation'],
        'email' => $cp['email']
    ];
}, $copis)) ?>;

// --- 1. Project Duration Calculator ---
function calculateProjectDuration() {
    const startInput = document.getElementById('proposed_start_date');
    const endInput = document.getElementById('proposed_end_date');
    const display = document.getElementById('project_duration_display');
    const badge = document.getElementById('project_duration_badge');

    if (!startInput || !endInput || !display) return;

    const startVal = startInput.value;
    const endVal = endInput.value;

    if (!startVal || !endVal) {
        display.value = 'Please select start & end dates';
        if (badge) {
            badge.className = 'badge bg-secondary-subtle text-secondary border px-3 py-2 fs-6';
            badge.textContent = 'Dates Required';
        }
        return;
    }

    const start = new Date(startVal);
    const end = new Date(endVal);

    if (isNaN(start.getTime()) || isNaN(end.getTime())) {
        display.value = 'Invalid Date format';
        return;
    }

    if (end < start) {
        display.value = 'Invalid: End date must be after start date';
        if (badge) {
            badge.className = 'badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 fs-6';
            badge.textContent = 'Invalid Range';
        }
        return;
    }

    let y1 = start.getFullYear(), m1 = start.getMonth(), d1 = start.getDate();
    let y2 = end.getFullYear(), m2 = end.getMonth(), d2 = end.getDate();

    let years = y2 - y1;
    let months = m2 - m1;
    let days = d2 - d1;

    if (days < 0) {
        const prevMonth = new Date(y2, m2, 0);
        days += prevMonth.getDate();
        months--;
    }

    if (months < 0) {
        months += 12;
        years--;
    }

    const yStr = years + (years === 1 ? ' Year' : ' Years');
    const mStr = months + (months === 1 ? ' Month' : ' Months');
    const dStr = days + (days === 1 ? ' Day' : ' Days');

    const resultStr = `${yStr}, ${mStr}, ${dStr}`;
    display.value = resultStr;

    if (badge) {
        badge.className = 'badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fs-6 fw-semibold';
        badge.textContent = `${years}y ${months}m ${days}d`;
    }
}

// --- 2. Co-PI Management (Searchable Combobox for 150+ Scientists + External) ---
let copiIndex = 0;

window.addCoPiRow = function(prefillData = null) {
    const container = document.getElementById('copi-container');
    const notice = document.getElementById('no-copi-notice');
    if (!container) return;

    const currentRows = container.querySelectorAll('.copi-card');
    if (currentRows.length >= 10) {
        alert('Maximum 10 Co-PIs allowed per proposal.');
        return;
    }

    copiIndex++;
    const rowId = `copi-card-${copiIndex}`;
    const card = document.createElement('div');
    card.className = 'card border mb-3 shadow-2xs copi-card bg-white';
    card.id = rowId;

    // Build portal scientists options
    let portalOptions = '';
    PORTAL_SCIENTISTS.forEach(s => {
        portalOptions += `<option value="${s.id}" data-type="internal" data-name="${escapeHtml(s.name)}" data-inst="${escapeHtml(s.institution)}" data-desig="${escapeHtml(s.designation)}" data-email="${escapeHtml(s.email)}" data-dept="${escapeHtml(s.department)}">
            ${escapeHtml(s.name)} — ${escapeHtml(s.department)} | ${escapeHtml(s.designation)} (${escapeHtml(s.email)})
        </option>`;
    });

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
                        <option value="EXTERNAL" data-type="external">➕ Other / External Institute Scientist</option>
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

    // Handle pre-filling
    if (prefillData) {
        const select = card.querySelector('.copi-select');
        let matched = null;
        if (prefillData.email) {
            matched = PORTAL_SCIENTISTS.find(s => s.email && s.email.toLowerCase() === prefillData.email.toLowerCase());
        }
        if (!matched && prefillData.name) {
            matched = PORTAL_SCIENTISTS.find(s => s.name && s.name.toLowerCase() === prefillData.name.toLowerCase());
        }

        if (matched) {
            select.value = matched.id;
            handleCoPiSelect(select, rowId);
        } else {
            select.value = 'EXTERNAL';
            handleCoPiSelect(select, rowId, prefillData);
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

// --- 3. Toggle Funding Agency Input ---
window.toggleFundingAgencyInput = function() {
    const fundingRadio = document.getElementById('type_funding_agency');
    const isFunding = fundingRadio ? fundingRadio.checked : false;
    const wrapper = document.getElementById('funding-agency-wrapper');
    const input = document.getElementById('funding_agency');
    const inHouseBox = document.getElementById('box-in-house');
    const fundingBox = document.getElementById('box-funding-agency');

    if (wrapper) {
        wrapper.style.display = isFunding ? 'block' : 'none';
    }
    if (input) {
        input.required = isFunding;
        if (!isFunding) {
            input.value = '';
        }
    }
    if (inHouseBox && fundingBox) {
        if (isFunding) {
            fundingBox.classList.add('border-success', 'bg-success-subtle');
            fundingBox.classList.remove('bg-white');
            inHouseBox.classList.remove('border-primary', 'bg-primary-subtle');
            inHouseBox.classList.add('bg-white');
        } else {
            inHouseBox.classList.add('border-primary', 'bg-primary-subtle');
            inHouseBox.classList.remove('bg-white');
            fundingBox.classList.remove('border-success', 'bg-success-subtle');
            fundingBox.classList.add('bg-white');
        }
    }
};

// --- 4. Initialize CKEditor 5 & Form Listeners ---
document.addEventListener('DOMContentLoaded', function() {
    // A. Funding Agency Toggle
    const radios = document.querySelectorAll('input[name="project_type"]');
    radios.forEach(function(r) {
        r.addEventListener('change', toggleFundingAgencyInput);
    });
    toggleFundingAgencyInput();

    // B. Project Duration Listeners
    const startInput = document.getElementById('proposed_start_date');
    const endInput = document.getElementById('proposed_end_date');
    if (startInput) {
        startInput.addEventListener('change', calculateProjectDuration);
        startInput.addEventListener('input', calculateProjectDuration);
    }
    if (endInput) {
        endInput.addEventListener('change', calculateProjectDuration);
        endInput.addEventListener('input', calculateProjectDuration);
    }
    calculateProjectDuration();

    // C. Populate Existing Co-PIs
    if (EXISTING_COPIS && EXISTING_COPIS.length > 0) {
        EXISTING_COPIS.forEach(cp => {
            addCoPiRow(cp);
        });
    } else {
        const notice = document.getElementById('no-copi-notice');
        if (notice) notice.style.display = 'block';
    }

    // D. CKEditor 5 Initialization (Specific Objectives)
    let objectivesEditor = null;

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

        const objEl = document.querySelector('#objectives');
        if (objEl) {
            ClassicEditor
                .create(objEl, editorConfig)
                .then(editor => {
                    objectivesEditor = editor;
                })
                .catch(err => {
                    console.warn('CKEditor Objectives Init:', err);
                });
        }
    }

    // E. Live Word Counters Initialization
    const WORD_LIMITS = {
        'research_problem': 100,
        'baseline_info': 100,
        'novelty_gap_analysis': 75,
        'justification_end_users': 100,
        'institute_alignment': 50,
        'technical_program': 250
    };

    window.updateFieldWordCount = function(fieldId, maxWords) {
        const el = document.getElementById(fieldId);
        if (!el) return;
        const text = el.value.trim();
        const words = text.length > 0 ? text.split(/\s+/).length : 0;
        const counter = document.getElementById(fieldId + '_counter');
        const warning = document.getElementById(fieldId + '_warning');

        if (counter) {
            counter.innerHTML = `<i class="bi bi-chat-left-text me-1"></i> ${words} / ${maxWords} words`;
            if (words > maxWords) {
                counter.className = 'badge bg-danger text-white border fw-bold';
                el.classList.add('is-invalid');
                if (warning) {
                    warning.style.display = 'block';
                    warning.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> Exceeds limit by ${words - maxWords} word(s)! Please shorten.`;
                }
            } else {
                counter.className = words >= Math.floor(maxWords * 0.85) ? 'badge bg-warning-subtle text-warning border fw-semibold' : 'badge bg-light text-primary border fw-semibold';
                el.classList.remove('is-invalid');
                if (warning) warning.style.display = 'none';
            }
        }
    };

    // Initialize all counters on load
    Object.keys(WORD_LIMITS).forEach(id => {
        updateFieldWordCount(id, WORD_LIMITS[id]);
    });

    // F. Form submission: Synchronize CKEditor data and validate word limits
    const form = document.getElementById('proposal-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (objectivesEditor) {
                const data = objectivesEditor.getData();
                document.querySelector('#objectives').value = data;
            }

            const submitBtn = e.submitter || document.activeElement;
            const isDraft = window.isDraftAction || (submitBtn && submitBtn.name === 'submit_action' && submitBtn.value === 'draft') ||
                            (submitBtn && submitBtn.value === 'draft');

            if (isDraft) {
                // Draft saving: bypass word count alerts and required checks!
                form.querySelectorAll('[required]').forEach(el => el.removeAttribute('required'));
                return true;
            }

            // Enforce word counts on submission
            for (const [fieldId, maxWords] of Object.entries(WORD_LIMITS)) {
                const el = document.getElementById(fieldId);
                if (el) {
                    const text = el.value.trim();
                    const words = text.length > 0 ? text.split(/\s+/).length : 0;
                    if (words > maxWords) {
                        e.preventDefault();
                        el.focus();
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        alert(`Word count limit exceeded for "${fieldId.replace(/_/g, ' ').toUpperCase()}".\nMaximum allowed: ${maxWords} words.\nCurrent word count: ${words} words.\n\nPlease shorten the content before submitting.`);
                        return false;
                    }
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
