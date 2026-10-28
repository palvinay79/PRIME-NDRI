<?php
/**
 * Research Proposal and Project Management System
 * Scientist - Create Proposal Form
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD]);

$pageTitle = 'Create New Research Proposal';
$currentUser = current_user();
$userId = current_user_id();
$isHod = (current_user_role_id() === ROLE_HOD);
$db = get_db();

// Fetch department
$stmt = $db->prepare("SELECT * FROM departments WHERE id = ?");
$stmt->execute([$currentUser['department_id'] ?? 1]);
$department = $stmt->fetch() ?: ['id' => 1, 'department_code' => 'AGB', 'department_name' => 'Animal Genetics & Breeding Division'];

// Fetch all departments in case user wants to specify primary division
$allDepts = $db->query("SELECT * FROM departments ORDER BY department_name ASC")->fetchAll();

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

$error = null;
$projectType = 'in_house';
$fundingAgency = '';
$fundingAgencyType = 'National';
$yearlyBudgetMap = [];
$institutePriority = '';
$nationalPriority = '';
$discipline = $currentUser['discipline'] ?? '';
$technicalProgram = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $submissionAction = $_POST['submit_action'] ?? 'draft'; // 'draft' or 'submit'
    $targetStatus = ($submissionAction === 'submit') ? ($isHod ? STATUS_FORWARDED_JD : STATUS_SUBMITTED_HOD) : STATUS_DRAFT;

    $projectType = sanitize($_POST['project_type'] ?? '');
    $fundingAgency = trim(sanitize($_POST['funding_agency'] ?? ''));
    $fundingAgencyType = sanitize($_POST['funding_agency_type'] ?? 'National');
    if (!in_array($fundingAgencyType, ['National', 'International'])) {
        $fundingAgencyType = 'National';
    }
    $title = sanitize($_POST['title'] ?? '');
    $deptId = (int)($_POST['department_id'] ?? $department['id']);
    $discipline = trim(sanitize($_POST['discipline'] ?? ''));
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
    $technicalProgram = sanitize_html($_POST['technical_program'] ?? '');
    $objectives = sanitize_html($_POST['objectives'] ?? '');
    $methodology = null; // Methodology field removed as per requirements
    $expectedOutcomes = sanitize_html($_POST['expected_outcomes'] ?? '');
    $startDate = !empty($_POST['proposed_start_date']) ? $_POST['proposed_start_date'] : null;
    $endDate = !empty($_POST['proposed_end_date']) ? $_POST['proposed_end_date'] : null;
    $budget = (float)($_POST['proposed_budget'] ?? 0);
    $singleBudget = (float)($_POST['proposed_budget_single'] ?? 0);

    // Calculate project tenure in whole budget years (any fractional months/days round up to next year)
    $tenureYears = 1;
    if (!empty($startDate) && !empty($endDate)) {
        try {
            $d1 = new DateTime($startDate);
            $d2 = new DateTime($endDate);
            if ($d2 > $d1) {
                $diff = $d1->diff($d2);
                $tenureYears = $diff->y + (($diff->m > 0 || $diff->d > 0) ? 1 : 0);
                $tenureYears = max(1, $tenureYears);
            }
        } catch (Exception $eTenure) {}
    }

    if ($projectType === 'funding_agency') {
        // Externally funded project: single budget input field, no yearly breakdown required
        if ($singleBudget > 0) {
            $budget = $singleBudget;
        }
        $yearlyBudgetMap = [];
        $yearlyBudgetJson = null;
    } else {
        // Process Year-wise Budget Allocation for In-house projects
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
                $yearlyBudgetMap[$lbl] = $amt;
                $sumYearly += $amt;
            }
        }

        // Check if single budget was passed as fallback
        if ($budget <= 0 && $singleBudget > 0) {
            $budget = $singleBudget;
        }

        if ($sumYearly > 0) {
            // User provided valid positive yearly breakdown amounts
            $budget = $sumYearly;
        } elseif ($budget > 0) {
            // User entered total proposed budget, but yearly amounts were 0 or blank.
            // Distribute total proposed budget across the project tenure years!
            $yearKeys = !empty($yearlyBudgetMap) ? array_keys($yearlyBudgetMap) : [];
            if (empty($yearKeys)) {
                $numY = max(1, $tenureYears ?? 1);
                for ($y = 1; $y <= $numY; $y++) {
                    $yearKeys[] = "Year {$y}";
                }
            }
            $numY = count($yearKeys);
            $base = round($budget / $numY, 2);
            $runningSum = 0;
            $yearlyBudgetMap = [];
            foreach ($yearKeys as $kIdx => $k) {
                if ($kIdx === $numY - 1) {
                    $yearlyBudgetMap[$k] = round($budget - $runningSum, 2);
                } else {
                    $yearlyBudgetMap[$k] = $base;
                    $runningSum += $base;
                }
            }
        }
        $yearlyBudgetJson = (!empty($yearlyBudgetMap) && array_sum($yearlyBudgetMap) > 0) ? json_encode($yearlyBudgetMap) : null;
    }

    $budgetJustification = sanitize($_POST['budget_justification'] ?? '');
    $submissionRemarks = sanitize($_POST['submission_remarks'] ?? '');

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
    $eoWords = $countWords($expectedOutcomes);
    $bjWords = $countWords($budgetJustification);

    // Fetch target department code for station check & proposal numbering
    $effectiveDeptId = $deptId ?: (int)($currentUser['department_id'] ?: 1);
    $stmtD = $db->prepare("SELECT department_code FROM departments WHERE id = ?");
    $stmtD->execute([$effectiveDeptId]);
    $deptCode = $stmtD->fetchColumn() ?: 'NDRI';

    // Validation
    if ($submissionAction === 'submit') {
        if (empty($projectType) || !in_array($projectType, ['in_house', 'funding_agency'])) {
            $error = "Please select whether this is an In-house Project or Externally Funded Project. This selection is mandatory.";
        } elseif ($projectType === 'funding_agency' && empty($fundingAgency)) {
            $error = "Funding Agency Name is mandatory for Externally Funded Projects. Please enter the funding agency name.";
        } elseif (empty($deptId)) {
            $error = "Division / Department is mandatory.";
        } elseif (($deptCode === 'SRS' || $deptCode === 'ERS') && empty($discipline)) {
            $error = "Discipline is required for Regional Station ({$deptCode}). Please specify your discipline.";
        } elseif (empty($title)) {
            $error = "Proposal title is mandatory.";
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
        } elseif (empty(trim(strip_tags($technicalProgram)))) {
            $error = "Technical Program Proposed is mandatory.";
        } elseif (empty(trim(strip_tags($objectives)))) {
            $error = "Specific Objectives are mandatory.";
        } elseif (empty(trim(strip_tags($expectedOutcomes)))) {
            $error = "Expected Outcomes & Deliverables are mandatory.";
        } elseif (empty($startDate) || empty($endDate)) {
            $error = "Both Proposed Start Date and End Date are mandatory.";
        } elseif (strtotime($endDate) <= strtotime($startDate)) {
            $error = "Proposed End Date must be after the Start Date.";
        } elseif ($budget <= 0) {
            $error = "Proposed Budget is mandatory and must be greater than zero.";
        } elseif (empty($budgetJustification)) {
            $error = "Budget Justification is mandatory.";
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
        } elseif ($eoWords > 40) {
            $error = "Expected Outcomes & Deliverables must be strictly 40 words or less. Current word count is {$eoWords} words.";
        } elseif ($bjWords > 25) {
            $error = "Budget Justification must be strictly 25 words or less. Current word count is {$bjWords} words.";
        }

        // Validate all year budget fields are provided and > 0 for all calculated tenure years (only for in-house projects)
        if (!$error && $projectType === 'in_house') {
            for ($yi = 1; $yi <= $tenureYears; $yi++) {
                $lbl = "Year {$yi}";
                $amt = (float)($yearlyBudgetMap[$lbl] ?? 0);
                if ($amt <= 0) {
                    $ordinalWord = ($yi === 1 ? '1st' : ($yi === 2 ? '2nd' : ($yi === 3 ? '3rd' : "{$yi}th")));
                    $error = "Budget for {$ordinalWord} Year ({$lbl}) is mandatory and must be greater than zero.";
                    break;
                }
            }
        }
    } else {
        // Draft saving: no mandatory fields required!
        if (empty($title)) {
            $title = 'Draft Proposal - ' . date('d M Y H:i');
        }
        if (empty($deptId)) {
            $deptId = $effectiveDeptId;
        }
        if (empty($projectType)) {
            $projectType = 'in_house';
        }
        if (empty($institutePriority)) {
            $institutePriority = 'A';
        }
    }

    // Document upload validation (Optional, strictly ONE document allowed, formats: PDF, DOCX, DOC, etc., strictly under 1 MB)
    $uploadedDoc = null;
    $allowedDocExts = ['pdf', 'docx', 'doc', 'odt', 'rtf', 'txt', 'xlsx', 'xls', 'png', 'jpg', 'jpeg'];
    $maxDocBytes = 1048576; // 1 MB (1024 * 1024 bytes)

    $rawFilesToCheck = [];
    if (isset($_FILES['proposal_document']) && !empty($_FILES['proposal_document']['name']) && !is_array($_FILES['proposal_document']['name']) && $_FILES['proposal_document']['error'] !== UPLOAD_ERR_NO_FILE) {
        $rawFilesToCheck[] = [
            'name' => basename($_FILES['proposal_document']['name']),
            'error' => (int)$_FILES['proposal_document']['error'],
            'size' => (int)$_FILES['proposal_document']['size'],
            'tmp_name' => $_FILES['proposal_document']['tmp_name'],
            'type' => $_FILES['proposal_document']['type'] ?? 'application/octet-stream'
        ];
    } elseif (isset($_FILES['proposal_documents']) && is_array($_FILES['proposal_documents']['name'])) {
        foreach ($_FILES['proposal_documents']['name'] as $fIdx => $rawName) {
            if (!empty($rawName) && ($_FILES['proposal_documents']['error'][$fIdx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $rawFilesToCheck[] = [
                    'name' => basename($rawName),
                    'error' => (int)$_FILES['proposal_documents']['error'][$fIdx],
                    'size' => (int)$_FILES['proposal_documents']['size'][$fIdx],
                    'tmp_name' => $_FILES['proposal_documents']['tmp_name'][$fIdx],
                    'type' => $_FILES['proposal_documents']['type'][$fIdx] ?? 'application/octet-stream'
                ];
            }
        }
    }

    if (count($rawFilesToCheck) > 1) {
        $error = "Only one document can be attached to the proposal. Please select only a single file.";
    } elseif (!empty($rawFilesToCheck)) {
        $item = $rawFilesToCheck[0];
        if ($item['error'] !== UPLOAD_ERR_OK) {
            $error = "Error uploading document '{$item['name']}': " . ($item['error'] === UPLOAD_ERR_INI_SIZE || $item['error'] === UPLOAD_ERR_FORM_SIZE ? "File exceeds server upload size limit." : "Code {$item['error']}");
        } elseif ($item['size'] > $maxDocBytes) {
            $sizeKb = round($item['size'] / 1024, 1);
            $error = "The uploaded file '{$item['name']}' ({$sizeKb} KB) exceeds the 1 MB limit. Please attach a document strictly under 1 MB.";
        } elseif ($item['size'] <= 0) {
            $error = "The uploaded file '{$item['name']}' is empty (0 bytes).";
        } else {
            $fileExt = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExt, $allowedDocExts)) {
                $error = "The file '{$item['name']}' has an unsupported format (.{$fileExt}). Allowed formats are PDF (.pdf), Word (.docx, .doc), OpenDocument (.odt), Text (.txt, .rtf), Excel (.xlsx, .xls), or Images (.png, .jpg).";
            } else {
                $uploadedDoc = $item;
            }
        }
    }

    if (!$error) {
        try {
            $db->beginTransaction();

                $proposalNumber = generate_proposal_number($deptCode);
                $submittedAt = ($submissionAction === 'submit') ? date('Y-m-d H:i:s') : null;

                $now = date('Y-m-d H:i:s');
                $sql = "INSERT INTO proposals (
                    proposal_number, project_type, funding_agency, funding_agency_type, proposal_category, title, scientist_id, department_id, discipline, institute_priority_area, national_priority_area,
                    trl_level, research_problem, baseline_info, novelty_gap_analysis, justification_end_users, institute_alignment,
                    technical_program, objectives, methodology, expected_outcomes, proposed_budget, yearly_budget, budget_justification,
                    submission_remarks,
                    proposed_start_date, proposed_end_date, current_status, submitted_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $proposalNumber, $projectType, ($projectType === 'funding_agency' ? $fundingAgency : null),
                    ($projectType === 'funding_agency' ? $fundingAgencyType : 'National'),
                    $title, $userId, $deptId, ($discipline ?: null), $institutePriority, $nationalPriority,
                    $trlLevel, $researchProblem, $baselineInfo, $noveltyGap, $justification, $alignment,
                    $technicalProgram, $objectives, $methodology, $expectedOutcomes, $budget, $yearlyBudgetJson, $budgetJustification,
                    (!empty($submissionRemarks) ? $submissionRemarks : null),
                    $startDate, $endDate, $targetStatus, $submittedAt, $now, $now
                ]);
                $proposalId = (int)$db->lastInsertId();

            // Save attached single document if provided
            if ($uploadedDoc) {
                $uploadDir = __DIR__ . '/../uploads/proposals';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $cleanOrig = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $uploadedDoc['name']);
                $savedFileName = 'prop_' . $proposalId . '_' . bin2hex(random_bytes(6)) . '_' . $cleanOrig;
                $targetPath = $uploadDir . '/' . $savedFileName;

                if (move_uploaded_file($uploadedDoc['tmp_name'], $targetPath)) {
                    $relPath = '/uploads/proposals/' . $savedFileName;
                    $stmtDoc = $db->prepare("INSERT INTO proposal_documents (proposal_id, file_name, file_path, file_type, file_size, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmtDoc->execute([
                        $proposalId,
                        $uploadedDoc['name'],
                        $relPath,
                        $uploadedDoc['type'],
                        $uploadedDoc['size'],
                        $userId,
                        date('Y-m-d H:i:s')
                    ]);
                } else {
                    error_log("Failed to move uploaded proposal document to {$targetPath}");
                }
            }

            // Save Co-PIs
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
                            $proposalId,
                            $cName,
                            sanitize($copiInsts[$i] ?? ''),
                            sanitize($copiDesigs[$i] ?? ''),
                            sanitize($copiEmails[$i] ?? '')
                        ]);
                    }
                }
            }

            // Record Scientist Comment if provided
            if (!empty($submissionRemarks)) {
                add_proposal_comment($proposalId, $userId, 'Scientist Initial Submission', $submissionRemarks);
            }

            // Record Status History
            $historyComment = ($submissionAction === 'submit') 
                ? ($isHod ? 'Initial submission by Head of Department forwarded directly to Joint Director for screening.' : 'Initial submission sent to Head of Department for review.') 
                : 'Proposal saved as draft.';
            if (!empty($submissionRemarks)) {
                $historyComment .= " Remarks: " . $submissionRemarks;
            }
            record_status_history($proposalId, null, $targetStatus, $userId, $currentUser['role_name'], $historyComment);

            // Audit log
            log_audit($userId, ($submissionAction === 'submit' ? 'PROPOSAL_SUBMITTED' : 'PROPOSAL_DRAFT_CREATED'), 'proposals', $proposalId, "Proposal {$proposalNumber} created with status '{$targetStatus}'.");

            $db->commit();

            if ($submissionAction === 'submit') {
                if ($isHod) {
                    flash('success', "Proposal {$proposalNumber} submitted successfully and forwarded directly to Joint Director for review.");
                } else {
                    flash('success', "Proposal {$proposalNumber} submitted successfully to Head of Department.");
                }
                header("Location: " . url("/scientist/proposal-details.php?id={$proposalId}"));
            } else {
                flash('success', "Proposal {$proposalNumber} saved as draft. Continuing in edit mode.");
                header("Location: " . url("/scientist/edit-proposal.php?id={$proposalId}"));
            }
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error saving proposal: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Submit Project Proposal</h3>
        <p class="text-muted small mb-0">Follow standard NDRI project formulation guidelines</p>
    </div>
    <a href="<?= url("/scientist/proposals.php") ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Back to Proposals
    </a>
</div>

<!-- Type Switcher Tabs -->
<ul class="nav nav-pills mb-4 p-1 bg-white border rounded shadow-xs" style="max-width: 660px;">
    <li class="nav-item flex-fill text-center">
        <a class="nav-link active fw-semibold text-white py-2 shadow-sm" style="background-color: #1a365d;" href="<?= url('/scientist/create-proposal.php') ?>">
            <i class="bi bi-file-earmark-plus me-1"></i> New Proposal
        </a>
    </li>
    <li class="nav-item flex-fill text-center">
        <a class="nav-link fw-semibold text-secondary py-2" href="<?= url('/scientist/ongoing-projects.php') ?>">
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
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-octagon-fill me-2"></i> <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<form method="POST" action="<?= url("/scientist/create-proposal.php") ?>" enctype="multipart/form-data" id="proposal-form">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="submit_action" id="submit_action_hidden" value="draft">

    <!-- Section 1: Administrative Identification -->
    <div class="detail-section-card">
        <div class="detail-section-header">
            <i class="bi bi-info-circle text-primary"></i> 1. Administrative & Lead Investigator Details
        </div>
        <div class="detail-section-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Principal Investigator (Project Leader)</label>
                    <input type="text" class="form-control bg-light" value="<?= e($currentUser['name']) ?> (<?= e($currentUser['designation']) ?>)" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Division / Department *</label>
                    <select name="department_id" id="department_id" class="form-select" required onchange="handleDepartmentChange(this)">
                        <?php foreach ($allDepts as $d): ?>
                            <option value="<?= $d['id'] ?>" data-code="<?= e($d['department_code']) ?>" <?= (($deptId ?? $currentUser['department_id']) == $d['id']) ? 'selected' : '' ?>>
                                <?= e($d['department_name']) ?> (<?= e($d['department_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Conditional Discipline for SRS and ERS Regional Stations -->
                <div class="col-md-6" id="disciplineContainer" style="display: none;">
                    <label for="discipline" class="form-label small fw-semibold text-secondary">
                        <i class="bi bi-mortarboard text-primary me-1"></i> Discipline <span class="text-danger">*</span>
                        <span class="badge bg-primary-subtle text-primary ms-1" style="font-size: 0.7rem;">Required for SRS / ERS</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="bi bi-mortarboard"></i></span>
                        <input type="text" class="form-control" id="discipline" name="discipline"
                               value="<?= e($discipline ?? ($currentUser['discipline'] ?? '')) ?>"
                               placeholder="e.g. Dairy Cattle Nutrition / Dairy Microbiology"
                               list="disciplineList">
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
                    <div class="form-text extra-small text-muted">Discipline recorded for SRS / ERS regional station scientists.</div>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary d-block mb-1">
                        Project Category / Funding Source <span class="text-danger">*</span>
                    </label>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="d-block p-3 rounded border h-100 bg-white shadow-sm" id="box-in-house" style="cursor: pointer;">
                                <div class="form-check m-0">
                                    <input class="form-check-input" type="radio" name="project_type" id="type_in_house" value="in_house" <?= ($projectType !== 'funding_agency') ? 'checked' : '' ?> required onchange="toggleFundingAgencyInput()">
                                    <span class="form-check-label fw-bold text-dark ms-1">
                                        <i class="bi bi-house-door-fill text-primary me-1"></i> In-house Project
                                    </span>
                                </div>
                                <div class="text-muted small mt-2 ps-4" style="line-height: 1.4;">
                                    Institutional research project funded under NDRI regular budget mandate. The Joint Director assigns a custom Project ID upon IRC approval.
                                </div>
                            </label>
                        </div>
                        <div class="col-md-6">
                            <label class="d-block p-3 rounded border h-100 bg-white shadow-sm" id="box-funding-agency" style="cursor: pointer;">
                                <div class="form-check m-0">
                                    <input class="form-check-input" type="radio" name="project_type" id="type_funding_agency" value="funding_agency" <?= ($projectType === 'funding_agency') ? 'checked' : '' ?> required onchange="toggleFundingAgencyInput()">
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

                <div class="col-12" id="funding-agency-wrapper" style="<?= ($projectType === 'funding_agency') ? '' : 'display: none;' ?>">
                    <div class="p-3 bg-light rounded border border-success-subtle shadow-xs">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-5">
                                <label class="form-label small fw-bold text-dark mb-1">
                                    <i class="bi bi-globe2 text-success me-1"></i> Agency Classification <span class="text-danger">*</span>
                                </label>
                                <div class="d-flex gap-2">
                                    <label class="d-flex align-items-center flex-fill p-2 rounded border bg-white shadow-xs" style="cursor: pointer;" id="opt-agency-national">
                                        <input type="radio" name="funding_agency_type" id="agency_type_national" value="National" class="form-check-input me-2 mt-0" <?= ($fundingAgencyType !== 'International') ? 'checked' : '' ?> onchange="updateAgencyTypeUi()">
                                        <div>
                                            <span class="fw-bold text-primary small d-block"><i class="bi bi-flag-fill me-1"></i> National</span>
                                            <span class="text-muted extra-small">ICAR, DBT, DST, SERB, CSIR, MoFPI, etc.</span>
                                        </div>
                                    </label>
                                    <label class="d-flex align-items-center flex-fill p-2 rounded border bg-white shadow-xs" style="cursor: pointer;" id="opt-agency-international">
                                        <input type="radio" name="funding_agency_type" id="agency_type_international" value="International" class="form-check-input me-2 mt-0" <?= ($fundingAgencyType === 'International') ? 'checked' : '' ?> onchange="updateAgencyTypeUi()">
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
                                           value="<?= e($fundingAgency) ?>">
                                </div>
                                <div class="form-text text-muted extra-small" id="funding_agency_hint">Specify the official name of the granting / sponsoring organization.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Project Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="Descriptive scientific title of the research project..." value="<?= e($title ?? '') ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Institute Priority Area <span class="text-danger">*</span></label>
                    <select name="institute_priority_area" class="form-select" required>
                        <option value="">-- Select Institute Priority Program (Program A to F) --</option>
                        <?php foreach (get_institute_priority_programs() as $pCode => $pDesc): ?>
                            <option value="<?= e($pCode) ?>" <?= ($institutePriority === $pCode) ? 'selected' : '' ?>>
                                <?= e($pDesc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted extra-small">Mandatory selection from institutional research programs A through F.</div>
                </div>
                <div class="col-md-9">
                    <label class="form-label small fw-semibold text-secondary">National Priority Area <span class="text-danger">*</span></label>
                    <input type="text" name="national_priority_area" class="form-control" placeholder="Enter National Priority Area..." value="<?= e($nationalPriority) ?>" required>
                    <div class="form-text text-muted extra-small">Specify the relevant National Priority Area, mission, or initiative.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-secondary">TRL Level (1-9) <span class="text-danger">*</span></label>
                    <select name="trl_level" class="form-select" required>
                        <?php for ($trl = 1; $trl <= 9; $trl++): ?>
                            <option value="<?= $trl ?>" <?= ($trlLevel ?? 1) === $trl ? 'selected' : '' ?>>TRL-<?= $trl ?></option>
                        <?php endfor; ?>
                    </select>
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
                <!-- Dynamically generated Co-PI Cards -->
            </div>
            <div id="no-copi-notice" class="text-center p-3 border rounded bg-light text-muted small" style="display: none;">
                <i class="bi bi-info-circle me-1 text-primary"></i> No Co-PIs added. Click <strong>"Add Co-PI"</strong> above if this project involves collaborators.
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
                    <textarea name="research_problem" id="research_problem" class="form-control" rows="3" placeholder="Define the primary scientific or technological problem being addressed (max 100 words)..." required oninput="updateFieldWordCount('research_problem', 100)"><?= e($_POST['research_problem'] ?? '') ?></textarea>
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
                    <textarea name="baseline_info" id="baseline_info" class="form-control" rows="3" placeholder="Benchmarks, any leads" required oninput="updateFieldWordCount('baseline_info', 100)"><?= e($_POST['baseline_info'] ?? '') ?></textarea>
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
                    <textarea name="novelty_gap_analysis" id="novelty_gap_analysis" class="form-control" rows="3" placeholder="Identified research gaps and clear scientific novelty (max 75 words)..." required oninput="updateFieldWordCount('novelty_gap_analysis', 75)"><?= e($_POST['novelty_gap_analysis'] ?? '') ?></textarea>
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
                    <textarea name="justification_end_users" id="justification_end_users" class="form-control" rows="3" placeholder="Target beneficiaries (dairy farmers, processing plants, breeders, policymakers) (max 100 words)..." required oninput="updateFieldWordCount('justification_end_users', 100)"><?= e($_POST['justification_end_users'] ?? '') ?></textarea>
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
                    <textarea name="institute_alignment" id="institute_alignment" class="form-control" rows="2" placeholder="How the project is aligned with the vision mandate of the institute" required oninput="updateFieldWordCount('institute_alignment', 50)"><?= e($_POST['institute_alignment'] ?? '') ?></textarea>
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
                    <textarea name="objectives" id="objectives" class="form-control" rows="6" placeholder="1. Formulate novel functional dairy matrix...&#10;2. Characterize stability under accelerated thermal stress..."><?= e($objectives ?? '') ?></textarea>
                    <div class="form-text text-muted extra-small">State clear, quantifiable research objectives (numbered or bulleted lists supported).</div>
                </div>

                <!-- 4: Technical Program Proposed - 250 words max -->
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
                        <label class="form-label small fw-semibold text-secondary m-0">
                            Technical Program Proposed (objective wise, also indicate the role of Co-PIs) <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-light text-primary border"><i class="bi bi-fonts me-1"></i>Rich Text Editor (CKEditor)</span>
                            <span id="technical_program_counter" class="badge bg-light text-primary border fw-semibold">
                                <i class="bi bi-chat-left-text me-1"></i> 0 / 250 words
                            </span>
                        </div>
                    </div>
                    <textarea name="technical_program" id="technical_program" class="form-control" rows="6" placeholder="1. Work package or objective-wise milestones&#10;2. Specific activities & technical methodologies&#10;3. Explicit role and responsibilities of Co-PIs..."><?= htmlspecialchars($technicalProgram ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="form-text text-muted extra-small">Objective-wise technical program, indicating the role of Co-PIs (strictly 250 words or less). Numbering, bullets &amp; tables supported.</div>
                        <div id="technical_program_warning" class="text-danger small fw-bold" style="display: none;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded! (Max 250 words)
                        </div>
                    </div>
                </div>

                <!-- Expected Outcomes & Deliverables - 40 words max -->
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
                        <label class="form-label small fw-semibold text-secondary m-0">
                            Expected Outcomes & Deliverables <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-light text-primary border"><i class="bi bi-fonts me-1"></i>Rich Text Editor (CKEditor)</span>
                            <span id="expected_outcomes_counter" class="badge bg-light text-primary border fw-semibold">
                                <i class="bi bi-chat-left-text me-1"></i> 0 / 40 words
                            </span>
                        </div>
                    </div>
                    <textarea name="expected_outcomes" id="expected_outcomes" class="form-control" rows="3" placeholder="Technologies, patentable formulations, research papers, high-yielding prototypes..."><?= htmlspecialchars($expectedOutcomes ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div class="form-text text-muted extra-small">Specific measurable outcomes & deliverables (strictly 40 words or less). Rich text formatting supported.</div>
                        <div id="expected_outcomes_warning" class="text-danger small fw-bold" style="display: none;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded! (Max 40 words)
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 5: Budget & Timeline -->
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
                    <input type="date" name="proposed_start_date" id="proposed_start_date" class="form-control" value="<?= e($startDate ?: date('Y-m-d', strtotime('+1 month'))) ?>" required onchange="calculateProjectDuration()">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Proposed End Date <span class="text-danger">*</span></label>
                    <input type="date" name="proposed_end_date" id="proposed_end_date" class="form-control" value="<?= e($endDate ?: date('Y-m-d', strtotime('+3 years +1 month'))) ?>" required onchange="calculateProjectDuration()">
                </div>

                <!-- Calculated Project Duration Banner -->
                <div class="col-12">
                    <div class="p-3 bg-light rounded border d-flex flex-wrap align-items-center justify-content-between gap-3" style="border-left: 4px solid #1a365d !important;">
                        <div>
                            <div class="fw-bold text-dark d-flex align-items-center">
                                <i class="bi bi-hourglass-split text-primary fs-5 me-2"></i>
                                <span>Project Duration:</span>
                                <span id="project_duration_badge" class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 fs-6 fw-bold ms-2">
                                    Calculating duration...
                                </span>
                            </div>
                            <div class="text-muted extra-small mt-1">
                                Duration is calculated automatically from Proposed Start Date and End Date (e.g. 1 year 5 months = 2 years tenure). All corresponding yearly budget fields will be generated below automatically.
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

            <!-- Single Budget Input Section for Externally Funded Projects -->
            <div id="single-budget-section" class="p-3 bg-white rounded border mb-3 shadow-xs" style="<?= ($projectType === 'funding_agency') ? '' : 'display: none;' ?>">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center">
                            <i class="bi bi-bank2 text-success me-2 fs-5"></i>
                            Total External Project Budget / Grant Amount (₹ INR) <span class="text-danger">*</span>
                        </h6>
                        <span class="text-muted extra-small">
                            For externally funded projects, enter the total project grant amount in this single input field. Yearly breakdown is not required.
                        </span>
                    </div>
                    <div>
                        <span class="badge bg-success-subtle text-success border border-success-subtle py-1 px-3 fw-semibold">
                            <i class="bi bi-check2-circle me-1"></i> Single Budget Field
                        </span>
                    </div>
                </div>
                <div class="row g-3 align-items-center">
                    <div class="col-md-7 col-lg-6">
                        <label class="form-label small fw-bold text-dark mb-1" for="proposed_budget_single">
                            Total Proposed / Sanctioned Budget (₹ INR) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-white fw-bold text-success">₹</span>
                            <input type="number" 
                                   name="proposed_budget_single" 
                                   id="proposed_budget_single" 
                                   class="form-control fw-bold font-monospace text-primary fs-5" 
                                   step="0.01" 
                                   min="1" 
                                   placeholder="e.g. 2500000" 
                                   value="<?= ($budget > 0) ? e($budget) : '' ?>"
                                   oninput="handleSingleBudgetChange(this.value)"
                                   onchange="handleSingleBudgetChange(this.value)">
                        </div>
                        <div class="form-text extra-small text-muted mt-1">
                            Enter the total sanctioned or proposed grant funding for the entire project duration.
                        </div>
                    </div>
                    <div class="col-md-5 col-lg-6">
                        <div class="p-3 rounded border bg-light">
                            <div class="text-secondary small fw-semibold text-uppercase">Grant Overview</div>
                            <div class="fw-bold fs-4 text-success font-monospace" id="single_budget_preview">
                                <?= ($budget > 0) ? format_currency((float)$budget) : '₹ 0.00' ?>
                            </div>
                            <div class="text-muted extra-small">Single Project Grant Allocation</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Year-wise Budget Input Section (In-house Projects) -->
            <div id="yearly-budget-section" class="p-3 bg-white rounded border mb-3 shadow-xs" style="<?= ($projectType === 'funding_agency') ? 'display: none;' : '' ?>">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center">
                            <i class="bi bi-cash-coin text-success me-2 fs-5"></i>
                            Year-wise Budget Formulation (₹ INR) <span class="text-danger">*</span>
                        </h6>
                        <span class="text-muted extra-small">
                            Mandatory annual budget fields automatically populated based on <span id="budget_tenure_hint" class="fw-bold text-primary">project tenure</span>. All generated year fields are mandatory.
                        </span>
                    </div>
                    <div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle py-1 px-3 fw-semibold" id="budget_fields_status_badge">
                            <i class="bi bi-calendar-check me-1"></i> Active Years
                        </span>
                    </div>
                </div>

                <!-- Dynamic Yearly Budget Container (Populated automatically from Start & End Dates) -->
                <div id="yearly-budget-container" class="row g-3 mb-3">
                    <!-- Populated dynamically by calculateProjectDuration() -->
                </div>

                <!-- Prominent Total Budget Summary Banner -->
                <div class="p-3 rounded border bg-primary-subtle border-primary-subtle d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <div class="text-secondary small fw-semibold text-uppercase">
                            <i class="bi bi-wallet2 text-primary me-1"></i> Total Proposed Budget (Sum of All Project Years)
                        </div>
                        <div class="d-flex align-items-baseline gap-2 mt-1">
                            <h3 class="fw-bold text-primary font-monospace mb-0" id="total_budget_display">₹ 0.00</h3>
                            <span class="badge bg-primary text-white font-monospace" id="total_years_badge">1 Year Allocated</span>
                        </div>
                        <input type="hidden" name="proposed_budget" id="proposed_budget" value="<?= (!empty($budget) && $budget > 0) ? e($budget) : '' ?>">
                    </div>
                    <div id="yearly_breakdown_pill_container" class="d-flex flex-wrap gap-2 justify-content-end">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>

            <!-- Budget Justification - 25 words max -->
            <div class="col-12 mt-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold text-secondary m-0">
                        Budget Justification <span class="text-danger">*</span>
                    </label>
                    <span id="budget_justification_counter" class="badge bg-light text-primary border fw-semibold">
                        <i class="bi bi-chat-left-text me-1"></i> 0 / 25 words
                    </span>
                </div>
                <textarea name="budget_justification" id="budget_justification" class="form-control" rows="3" placeholder="Itemized breakdown (Consumables, Contingencies, Travel, Minor equipment, Analytical services across years)..." required oninput="updateFieldWordCount('budget_justification', 25)"><?= e($_POST['budget_justification'] ?? '') ?></textarea>
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <div class="form-text extra-small text-muted">Provide head-wise justification for each project year (strictly 25 words or less).</div>
                    <div id="budget_justification_warning" class="text-danger small fw-bold" style="display: none;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Word limit exceeded! (Max 25 words)
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attached Document & Supporting Annexure Upload (Single Document, Under 1 MB, Optional) -->
    <div class="card shadow-sm border-0 mb-4" id="document-attachment-card">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-paperclip text-primary fs-5"></i>
                <h6 class="fw-bold text-dark m-0">Attached Document &amp; Supporting Annexure</h6>
                <span class="badge bg-secondary-subtle text-secondary border font-monospace">Optional</span>
            </div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                <i class="bi bi-file-earmark-check me-1"></i>Max 1 Document &middot; Under 1 MB &middot; PDF, DOCX, etc.
            </span>
        </div>
        <div class="card-body p-4">
            <div class="p-3 bg-light rounded border mb-2">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <label for="proposal_document" class="form-label small fw-bold text-dark m-0">
                        <i class="bi bi-file-earmark-arrow-up text-primary me-1"></i> Attach Proposal Document (Optional)
                    </label>
                    <span class="badge bg-info-subtle text-dark border">
                        <i class="bi bi-info-circle me-1"></i> Strictly 1 Document
                    </span>
                </div>
                <input type="file" 
                       name="proposal_document" 
                       id="proposal_document" 
                       class="form-control bg-white" 
                       accept=".pdf,.docx,.doc,.txt,.rtf,.odt,.xlsx,.xls,.png,.jpg,.jpeg">
                <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
                    <div class="form-text extra-small text-muted m-0">
                        <strong>Allowed Formats:</strong> PDF (.pdf), Word (.docx, .doc), OpenDocument (.odt), Text (.txt, .rtf), Excel (.xlsx, .xls), Images (.png, .jpg).
                        <span class="text-danger fw-semibold d-inline-block ms-1"><i class="bi bi-exclamation-circle me-1"></i>Must be strictly under 1 MB. Maximum 1 document.</span>
                    </div>
                </div>
            </div>

            <!-- Real-time Selected Single File Preview -->
            <div id="selected_files_list_container" class="mt-3" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small fw-bold text-secondary text-uppercase">
                        <i class="bi bi-file-earmark-check me-1"></i> Selected Document:
                    </span>
                    <button type="button" class="btn btn-xs btn-outline-danger py-0 px-2" style="font-size: 0.75rem;" onclick="clearSelectedProposalDocuments()">
                        <i class="bi bi-x-circle me-1"></i> Remove File
                    </button>
                </div>
                <div id="selected_files_list" class="list-group mb-2">
                    <!-- Populated dynamically via JS -->
                </div>
            </div>

            <div id="doc_file_warning" class="alert alert-danger py-2 px-3 small fw-bold mb-0 mt-2" style="display: none;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> <span id="doc_file_warning_text">The selected file exceeds the 1 MB limit!</span>
            </div>
        </div>
    </div>

    <!-- Scientist's Remarks / Note for Reviewers -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center">
            <i class="bi bi-chat-left-quote text-primary me-2 fs-5"></i>
            <h6 class="fw-bold text-dark m-0"><?= $isHod ? "Head of Department's Submission Remarks / Note for Directorate" : "Scientist's Submission Remarks / Note for Reviewers (HOD & Directorate)" ?></h6>
        </div>
        <div class="card-body">
            <label class="form-label small fw-semibold text-secondary"><?= $isHod ? "Remarks / Covering Note for Joint Director" : "Optional Remarks / Covering Note for Head of Department" ?></label>
            <textarea name="submission_remarks" class="form-control" rows="2" placeholder="Provide any special context, urgent operational priorities, or notes for review..."><?= e($_POST['submission_remarks'] ?? '') ?></textarea>
            <div class="form-text extra-small text-muted">These comments and remarks will be prominently visible when screening and reviewing your submission.</div>
        </div>
    </div>

    <!-- Submission Action Buttons -->
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body d-flex justify-content-between align-items-center">
            <a href="<?= $isHod ? url("/hod/dashboard.php") : url("/scientist/proposals.php") ?>" class="btn btn-outline-secondary">Cancel</a>
            <div class="d-flex gap-2">
                <button type="submit" name="submit_action" value="draft" class="btn btn-outline-primary" formnovalidate onclick="document.getElementById('submit_action_hidden').value = 'draft'; window.isDraftAction = true;">
                    <i class="bi bi-save me-1"></i> Save Changes (Draft)
                </button>
                <button type="submit" name="submit_action" value="submit" class="btn btn-primary fw-semibold px-4" style="background-color: #1a365d; border-color: #1a365d;" onclick="document.getElementById('submit_action_hidden').value = 'submit'; window.isDraftAction = false;">
                    <i class="bi bi-send-check me-1"></i> <?= $isHod ? 'Submit to Joint Director' : 'Submit to HOD' ?>
                </button>
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

// Initial yearly budget data (for drafts / re-edits / validation returns)
const INITIAL_YEARLY_BUDGET = <?= json_encode(!empty($yearlyBudgetMap) ? $yearlyBudgetMap : (object)[]) ?>;
window.projectDurationYears = 1;

function getOrdinalWord(n) {
    const words = ['', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th', '11th', '12th', '13th', '14th', '15th'];
    return words[n] || (n + 'th');
}

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
            badge.className = 'badge bg-secondary-subtle text-secondary border px-3 py-1 fs-6';
            badge.textContent = 'Dates Required';
        }
        return;
    }

    const [y1, m1, d1] = startVal.split('-').map(Number);
    const [y2, m2, d2] = endVal.split('-').map(Number);

    if (!y1 || !m1 || !d1 || !y2 || !m2 || !d2) {
        display.value = 'Invalid Date format';
        return;
    }

    const startDateObj = new Date(y1, m1 - 1, d1);
    const endDateObj = new Date(y2, m2 - 1, d2);

    if (endDateObj <= startDateObj) {
        display.value = 'Invalid: End date must be after start date';
        if (badge) {
            badge.className = 'badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 fs-6';
            badge.textContent = 'Invalid Range';
        }
        return;
    }

    let years = y2 - y1;
    let months = m2 - m1;
    let days = d2 - d1;

    if (days < 0) {
        const prevMonth = new Date(y2, m2 - 1, 0);
        days += prevMonth.getDate();
        months--;
    }

    if (months < 0) {
        months += 12;
        years--;
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

    // Standard formulation: any fractional months or days round UP to next full budget year
    let totalYears = years;
    if (months > 0 || days > 0) {
        totalYears += 1;
    }
    totalYears = Math.max(1, totalYears);
    window.projectDurationYears = totalYears;

    const parts = [];
    if (years > 0) parts.push(years + (years === 1 ? ' Year' : ' Years'));
    if (months > 0) parts.push(months + (months === 1 ? ' Month' : ' Months'));
    if (days > 0) parts.push(days + (days === 1 ? ' Day' : ' Days'));
    const breakdownStr = parts.length > 0 ? parts.join(', ') : `${totalYears} Years`;

    const displayText = (parts.length === 1 && years > 0) ? `${years} Year${years !== 1 ? 's' : ''}` : `${totalYears} Year${totalYears !== 1 ? 's' : ''} (${breakdownStr})`;
    display.value = displayText;

    if (badge) {
        badge.className = 'badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 fs-6 fw-bold';
        badge.textContent = displayText;
    }

    const tenureHint = document.getElementById('budget_tenure_hint');
    if (tenureHint) {
        tenureHint.textContent = `${totalYears} year${totalYears !== 1 ? 's' : ''}`;
    }

    // Automatically synchronize yearly budget input fields
    syncYearlyBudgetInputs(totalYears);
    calculateTotalBudget();
}

function syncYearlyBudgetInputs(totalYears) {
    const container = document.getElementById('yearly-budget-container');
    if (!container) return;

    const fundingRadio = document.getElementById('type_funding_agency');
    const isFunding = fundingRadio ? fundingRadio.checked : false;

    // Collect currently entered values to preserve what the user already typed
    const currentValues = {};
    container.querySelectorAll('.yearly-budget-row').forEach(row => {
        const y = parseInt(row.getAttribute('data-year'), 10);
        const inp = row.querySelector('.yearly-budget-input');
        if (y && inp && inp.value !== '') {
            currentValues['Year ' + y] = inp.value;
        }
    });

    // Merge with INITIAL_YEARLY_BUDGET for any years not yet in currentValues
    if (typeof INITIAL_YEARLY_BUDGET === 'object' && INITIAL_YEARLY_BUDGET !== null) {
        Object.keys(INITIAL_YEARLY_BUDGET).forEach(k => {
            if (currentValues[k] === undefined || currentValues[k] === '') {
                const val = INITIAL_YEARLY_BUDGET[k];
                if (val !== undefined && val !== null && val !== '') {
                    currentValues[k] = val;
                }
            }
        });
    }

    let html = '';
    for (let y = 1; y <= totalYears; y++) {
        const ord = getOrdinalWord(y);
        const key = 'Year ' + y;
        const val = currentValues[key] !== undefined && currentValues[key] !== null ? currentValues[key] : '';
        const iconNum = y <= 9 ? y : 'circle';
        const reqAttr = isFunding ? '' : 'required';
        const disAttr = isFunding ? 'disabled' : '';

        html += `
            <div class="col-md-6 col-lg-4 yearly-budget-row" id="row-year-${y}" data-year="${y}">
                <div class="p-3 rounded border bg-light h-100 position-relative shadow-2xs">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label small fw-bold text-dark m-0" for="budget_year_${y}">
                            <i class="bi bi-${iconNum}-circle-fill text-primary me-1"></i> Year ${y} Budget (₹) <span class="text-danger">*</span>
                        </label>
                        <span class="badge bg-primary text-white extra-small">${ord} Year</span>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text bg-white fw-bold">₹</span>
                        <input type="hidden" name="yearly_budget_year[]" value="Year ${y}" ${disAttr}>
                        <input type="number" 
                               name="yearly_budget_amount[]" 
                               class="form-control fw-bold fs-6 yearly-budget-input" 
                               step="0.01" 
                               min="0" 
                               placeholder="e.g. 500000" 
                               id="budget_year_${y}"
                               value="${val}" 
                               ${reqAttr} 
                               ${disAttr}
                               oninput="calculateTotalBudget()"
                               onchange="calculateTotalBudget()">
                    </div>
                    <div class="form-text extra-small text-muted mt-1">${ord} Year capital & operational outlay.</div>
                </div>
            </div>
        `;
    }

    container.innerHTML = html;

    const statusBadge = document.getElementById('budget_fields_status_badge');
    if (statusBadge) {
        statusBadge.innerHTML = `<i class="bi bi-calendar-check me-1"></i> ${totalYears} Year${totalYears !== 1 ? 's' : ''} Mandatory`;
    }

    const tenureHint = document.getElementById('budget_tenure_hint');
    if (tenureHint) {
        tenureHint.textContent = `${totalYears} year${totalYears !== 1 ? 's' : ''}`;
    }
}

function calculateTotalBudget() {
    const fundingRadio = document.getElementById('type_funding_agency');
    const isFunding = fundingRadio ? fundingRadio.checked : false;

    if (isFunding) {
        const singleInput = document.getElementById('proposed_budget_single');
        if (singleInput && typeof handleSingleBudgetChange === 'function') {
            handleSingleBudgetChange(singleInput.value);
        }
        return;
    }

    const inputs = document.querySelectorAll('.yearly-budget-input:not([disabled])');
    let total = 0;
    const breakdown = [];

    inputs.forEach((inp, idx) => {
        const val = parseFloat(inp.value) || 0;
        total += val;
        breakdown.push({ year: idx + 1, amount: val });
    });

    const display = document.getElementById('total_budget_display');
    const hidden = document.getElementById('proposed_budget');
    const badge = document.getElementById('total_years_badge');
    const pillContainer = document.getElementById('yearly_breakdown_pill_container');

    if (display) {
        display.textContent = '₹ ' + total.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (hidden) {
        hidden.value = total > 0 ? total.toFixed(2) : '';
    }
    if (badge) {
        badge.textContent = `${inputs.length} Year${inputs.length !== 1 ? 's' : ''} Total`;
    }

    if (pillContainer) {
        pillContainer.innerHTML = '';
        breakdown.forEach(item => {
            const pct = total > 0 ? ((item.amount / total) * 100).toFixed(1) : 0;
            const span = document.createElement('span');
            span.className = 'badge bg-white text-dark border extra-small px-2 py-1 shadow-2xs font-monospace';
            span.innerHTML = `<span class="text-primary fw-bold">Yr ${item.year}:</span> ₹ ${item.amount.toLocaleString('en-IN')} <span class="text-muted">(${pct}%)</span>`;
            pillContainer.appendChild(span);
        });
    }
}

function updateAgencyTypeUi() {
    const nat = document.getElementById('agency_type_national');
    const intl = document.getElementById('agency_type_international');
    const boxNat = document.getElementById('opt-agency-national');
    const boxIntl = document.getElementById('opt-agency-international');
    const hint = document.getElementById('funding_agency_hint');

    if (nat && nat.checked) {
        if (boxNat) {
            boxNat.classList.add('border-primary', 'bg-primary-subtle');
            boxNat.classList.remove('bg-white');
        }
        if (boxIntl) {
            boxIntl.classList.remove('border-info', 'bg-info-subtle');
            boxIntl.classList.add('bg-white');
        }
        if (hint) {
            hint.textContent = 'National external funding agency (e.g. DBT, DST, ICAR, CSIR, BIRAC, MoFPI).';
        }
    } else if (intl && intl.checked) {
        if (boxIntl) {
            boxIntl.classList.add('border-info', 'bg-info-subtle');
            boxIntl.classList.remove('bg-white');
        }
        if (boxNat) {
            boxNat.classList.remove('border-primary', 'bg-primary-subtle');
            boxNat.classList.add('bg-white');
        }
        if (hint) {
            hint.textContent = 'International external funding agency (e.g. FAO, World Bank, Bill & Melinda Gates Foundation, IAEA, WHO).';
        }
    }
}

// --- 2. Department Change & Conditional Discipline (SRS / ERS) ---
function handleDepartmentChange(deptSelect) {
    if (!deptSelect) return;
    const selectedOpt = deptSelect.options[deptSelect.selectedIndex];
    const code = (selectedOpt ? (selectedOpt.getAttribute('data-code') || '') : '').toUpperCase().trim();
    const container = document.getElementById('disciplineContainer');
    const input = document.getElementById('discipline');

    if (code === 'SRS' || code === 'ERS') {
        if (container) container.style.display = 'block';
        if (input) input.setAttribute('required', 'required');
    } else {
        if (container) container.style.display = 'none';
        if (input) input.removeAttribute('required');
    }
}

// --- 3. Co-PI Management (Searchable Combobox for 150+ Scientists + External) ---
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
    const select = card.querySelector('.copi-select');
    if (prefillData) {
        if (prefillData.selectedId) {
            select.value = prefillData.selectedId;
        } else if (prefillData.isExternal) {
            select.value = 'EXTERNAL';
        }
        handleCoPiSelect(select, rowId, prefillData);
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

// --- Single Budget Handler for Externally Funded Projects ---
window.handleSingleBudgetChange = function(val) {
    const amt = parseFloat(val) || 0;
    const hidden = document.getElementById('proposed_budget');
    if (hidden) {
        hidden.value = amt > 0 ? amt : '';
    }
    const preview = document.getElementById('single_budget_preview');
    if (preview) {
        preview.textContent = '₹ ' + amt.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    const display = document.getElementById('total_budget_display');
    if (display) {
        display.textContent = '₹ ' + amt.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
};

// --- 3. Toggle Funding Agency Input & Budget Formulation Mode ---
window.toggleFundingAgencyInput = function() {
    const fundingRadio = document.getElementById('type_funding_agency');
    const isFunding = fundingRadio ? fundingRadio.checked : false;
    const wrapper = document.getElementById('funding-agency-wrapper');
    const input = document.getElementById('funding_agency');
    const inHouseBox = document.getElementById('box-in-house');
    const fundingBox = document.getElementById('box-funding-agency');
    const yearlySec = document.getElementById('yearly-budget-section');
    const singleSec = document.getElementById('single-budget-section');
    const singleInput = document.getElementById('proposed_budget_single');

    if (wrapper) {
        wrapper.style.display = isFunding ? 'block' : 'none';
    }
    if (input) {
        input.required = isFunding;
        if (!isFunding) {
            input.value = '';
        }
    }

    if (isFunding) {
        // Externally funded project: DO NOT ask budget yearly wise! Show single input field.
        if (yearlySec) yearlySec.style.display = 'none';
        if (singleSec) singleSec.style.display = 'block';
        if (singleInput) {
            singleInput.required = true;
            singleInput.disabled = false;
            handleSingleBudgetChange(singleInput.value);
        }
        // Remove required and disable yearly inputs
        document.querySelectorAll('.yearly-budget-input').forEach(el => {
            el.removeAttribute('required');
            el.disabled = true;
        });
        document.querySelectorAll('input[name="yearly_budget_year[]"]').forEach(el => {
            el.disabled = true;
        });
    } else {
        // In-house project: Show year-wise budget formulation
        if (yearlySec) yearlySec.style.display = 'block';
        if (singleSec) singleSec.style.display = 'none';
        if (singleInput) {
            singleInput.required = false;
            singleInput.disabled = true;
        }
        // Restore required and enable yearly inputs
        document.querySelectorAll('.yearly-budget-input').forEach(el => {
            el.setAttribute('required', 'required');
            el.disabled = false;
        });
        document.querySelectorAll('input[name="yearly_budget_year[]"]').forEach(el => {
            el.disabled = false;
        });
        if (typeof calculateTotalBudget === 'function') {
            calculateTotalBudget();
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
    // A0. Initialize Department & Discipline (SRS / ERS check)
    const deptSelect = document.getElementById('department_id');
    if (deptSelect) {
        handleDepartmentChange(deptSelect);
    }

    // A. Funding Agency Toggle & Agency Type
    const radios = document.querySelectorAll('input[name="project_type"]');
    radios.forEach(function(r) {
        r.addEventListener('change', toggleFundingAgencyInput);
    });
    toggleFundingAgencyInput();

    const agencyTypeRadios = document.querySelectorAll('input[name="funding_agency_type"]');
    agencyTypeRadios.forEach(function(r) {
        r.addEventListener('change', updateAgencyTypeUi);
    });
    updateAgencyTypeUi();

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
    calculateTotalBudget();

    // C. Initial Co-PI Row if none exists
    const container = document.getElementById('copi-container');
    if (container && container.querySelectorAll('.copi-card').length === 0) {
        addCoPiRow();
    }

    // D. CKEditor 5 Initialization (Specific Objectives, Technical Program Proposed, Expected Outcomes)
    let objectivesEditor = null;
    let technicalProgramEditor = null;
    let expectedOutcomesEditor = null;

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
                    editor.model.document.on('change:data', () => {
                        objEl.value = editor.getData();
                    });
                })
                .catch(err => {
                    console.warn('CKEditor Objectives Init:', err);
                });
        }

        const techEl = document.querySelector('#technical_program');
        if (techEl) {
            ClassicEditor
                .create(techEl, editorConfig)
                .then(editor => {
                    technicalProgramEditor = editor;
                    editor.model.document.on('change:data', () => {
                        techEl.value = editor.getData();
                        updateFieldWordCount('technical_program', 250);
                    });
                    setTimeout(() => updateFieldWordCount('technical_program', 250), 300);
                })
                .catch(err => {
                    console.warn('CKEditor Technical Program Init:', err);
                });
        }

        const eoEl = document.querySelector('#expected_outcomes');
        if (eoEl) {
            ClassicEditor
                .create(eoEl, editorConfig)
                .then(editor => {
                    expectedOutcomesEditor = editor;
                    editor.model.document.on('change:data', () => {
                        eoEl.value = editor.getData();
                        updateFieldWordCount('expected_outcomes', 40);
                    });
                    setTimeout(() => updateFieldWordCount('expected_outcomes', 40), 300);
                })
                .catch(err => {
                    console.warn('CKEditor Expected Outcomes Init:', err);
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
        'technical_program': 250,
        'expected_outcomes': 40,
        'budget_justification': 25
    };

    window.updateFieldWordCount = function(fieldId, maxWords) {
        const el = document.getElementById(fieldId);
        if (!el) return;
        let text = el.value || '';
        // If content contains HTML from CKEditor, strip tags to count actual words
        if (text.includes('<') && text.includes('>')) {
            const temp = document.createElement('div');
            temp.innerHTML = text;
            text = (temp.textContent || temp.innerText || '').trim();
        } else {
            text = text.trim();
        }
        text = text.replace(/[\s\u00A0\u2000-\u200B]+/g, ' ').trim();
        const words = text.length > 0 ? text.split(/\s+/).filter(w => w.length > 0).length : 0;
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
                document.querySelector('#objectives').value = objectivesEditor.getData();
            }
            if (technicalProgramEditor) {
                document.querySelector('#technical_program').value = technicalProgramEditor.getData();
            }
            if (expectedOutcomesEditor) {
                document.querySelector('#expected_outcomes').value = expectedOutcomesEditor.getData();
            }

            const submitBtn = e.submitter || document.activeElement;
            const isDraft = window.isDraftAction || (submitBtn && submitBtn.name === 'submit_action' && submitBtn.value === 'draft') ||
                            (submitBtn && submitBtn.value === 'draft');

            if (isDraft) {
                // Draft saving: bypass word count alerts and required checks!
                const draftActionInput = document.getElementById('submit_action_hidden');
                if (draftActionInput) draftActionInput.value = 'draft';
                form.querySelectorAll('[required]').forEach(el => el.removeAttribute('required'));
                return true;
            }

            const fundingRadio = document.getElementById('type_funding_agency');
            const isFunding = fundingRadio ? fundingRadio.checked : false;

            if (isFunding) {
                // Externally funded project: Enforce single budget field
                const singleInput = document.getElementById('proposed_budget_single');
                const singleAmt = parseFloat(singleInput ? singleInput.value : 0) || 0;
                if (singleAmt <= 0) {
                    e.preventDefault();
                    if (singleInput) {
                        singleInput.focus();
                        singleInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    alert('Total Proposed Budget is mandatory and must be greater than zero.');
                    return false;
                }
            } else {
                // In-house project: Enforce yearly budget inputs on submission
                const yearlyInputs = form.querySelectorAll('.yearly-budget-input');
                for (let i = 0; i < yearlyInputs.length; i++) {
                    const inp = yearlyInputs[i];
                    const amt = parseFloat(inp.value) || 0;
                    if (amt <= 0) {
                        e.preventDefault();
                        inp.focus();
                        inp.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        alert(`Year ${i + 1} Budget is mandatory and must be greater than zero.`);
                        return false;
                    }
                }
            }

            // Enforce word counts on submission
            for (const [fieldId, maxWords] of Object.entries(WORD_LIMITS)) {
                const el = document.getElementById(fieldId);
                if (el) {
                    let text = el.value || '';
                    if (text.includes('<') && text.includes('>')) {
                        const temp = document.createElement('div');
                        temp.innerHTML = text;
                        text = (temp.textContent || temp.innerText || '').trim();
                    } else {
                        text = text.trim();
                    }
                    text = text.replace(/[\s\u00A0\u2000-\u200B]+/g, ' ').trim();
                    const words = text.length > 0 ? text.split(/\s+/).filter(w => w.length > 0).length : 0;
                    if (words > maxWords) {
                        e.preventDefault();
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        alert(`Word count limit exceeded for "${fieldId.replace(/_/g, ' ').toUpperCase()}".\nMaximum allowed: ${maxWords} words.\nCurrent word count: ${words} words.\n\nPlease shorten the content before submitting.`);
                        return false;
                    }
                }
            }

            // Also check attached single document size strictly under 1 MB
            const docInput = document.getElementById('proposal_document');
            if (docInput && docInput.files && docInput.files.length > 0) {
                const maxBytes = 1048576; // 1 MB
                if (docInput.files.length > 1) {
                    e.preventDefault();
                    alert('Only one document can be attached to the proposal. Please select only a single file.');
                    docInput.focus();
                    docInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
                const file = docInput.files[0];
                if (file.size > maxBytes) {
                    e.preventDefault();
                    const sizeKb = (file.size / 1024).toFixed(1);
                    alert(`The attached document "${file.name}" (${sizeKb} KB) exceeds the 1 MB size limit.\n\nPlease attach a document strictly under 1 MB.`);
                    docInput.focus();
                    docInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
            }
        });
    }

    // G. Document upload size validation and preview (Strictly Single Document, Max 1 MB)
    window.clearSelectedProposalDocuments = function() {
        const docInput = document.getElementById('proposal_document');
        if (docInput) {
            docInput.value = '';
            docInput.classList.remove('is-invalid');
        }
        const container = document.getElementById('selected_files_list_container');
        const list = document.getElementById('selected_files_list');
        const warning = document.getElementById('doc_file_warning');
        if (container) container.style.display = 'none';
        if (list) list.innerHTML = '';
        if (warning) warning.style.display = 'none';
    };

    const docInput = document.getElementById('proposal_document');
    if (docInput) {
        docInput.addEventListener('change', function() {
            const warning = document.getElementById('doc_file_warning');
            const warningText = document.getElementById('doc_file_warning_text');
            const container = document.getElementById('selected_files_list_container');
            const list = document.getElementById('selected_files_list');
            const maxBytes = 1048576; // 1 MB

            if (!this.files || this.files.length === 0) {
                if (container) container.style.display = 'none';
                if (warning) warning.style.display = 'none';
                this.classList.remove('is-invalid');
                return;
            }

            if (this.files.length > 1) {
                alert('Only one document is allowed. Please choose only a single file.');
                this.value = '';
                this.classList.add('is-invalid');
                if (container) container.style.display = 'none';
                if (warning) {
                    if (warningText) warningText.textContent = 'Only 1 file allowed! Multiple files were deselected.';
                    warning.style.display = 'block';
                }
                return;
            }

            const f = this.files[0];
            const sizeKb = (f.size / 1024).toFixed(1);
            const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
            const displaySize = f.size >= 1048576 ? sizeMb + ' MB' : sizeKb + ' KB';
            const isOver = f.size > maxBytes;
            const ext = f.name.split('.').pop().toLowerCase();

            let iconHtml = '<i class="bi bi-file-earmark-text text-secondary fs-4 me-2"></i>';
            if (ext === 'pdf') {
                iconHtml = '<i class="bi bi-file-earmark-pdf-fill text-danger fs-4 me-2"></i>';
            } else if (['doc', 'docx'].includes(ext)) {
                iconHtml = '<i class="bi bi-file-earmark-word-fill text-primary fs-4 me-2"></i>';
            } else if (['xls', 'xlsx'].includes(ext)) {
                iconHtml = '<i class="bi bi-file-earmark-excel-fill text-success fs-4 me-2"></i>';
            } else if (['png', 'jpg', 'jpeg'].includes(ext)) {
                iconHtml = '<i class="bi bi-file-earmark-image-fill text-info fs-4 me-2"></i>';
            }

            const itemHtml = `
                <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 ${isOver ? 'bg-danger-subtle border-danger' : 'bg-white'}">
                    <div class="d-flex align-items-center text-truncate me-2">
                        ${iconHtml}
                        <div>
                            <div class="fw-semibold text-dark small text-truncate" style="max-width: 420px;" title="${f.name}">${f.name}</div>
                            <div class="extra-small text-muted">${displaySize} &middot; .${ext.toUpperCase()}</div>
                        </div>
                    </div>
                    <div>
                        ${isOver 
                            ? '<span class="badge bg-danger text-white"><i class="bi bi-exclamation-triangle-fill me-1"></i>Exceeds 1 MB limit</span>' 
                            : '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle me-1"></i>Under 1 MB</span>'
                        }
                    </div>
                </div>
            `;

            if (container && list) {
                list.innerHTML = itemHtml;
                container.style.display = 'block';
            }

            if (isOver) {
                this.classList.add('is-invalid');
                if (warning) {
                    if (warningText) {
                        warningText.textContent = `The selected file "${f.name}" (${displaySize}) exceeds the 1 MB limit. Please choose a file strictly under 1 MB.`;
                    }
                    warning.style.display = 'block';
                }
                alert(`Warning: The selected file "${f.name}" (${displaySize}) exceeds the 1 MB limit.\n\nPlease choose a document strictly under 1 MB.`);
            } else {
                this.classList.remove('is-invalid');
                if (warning) warning.style.display = 'none';
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
