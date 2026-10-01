<?php
/**
 * Research Proposal and Project Management System
 * Joint Director - Final Institute Research Council (IRC) Decision
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role(ROLE_JOINT_DIRECTOR);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    flash('danger', 'Invalid proposal ID.');
    header("Location: " . url("/joint-director/proposals.php"));
    exit;
}

$db = get_db();
$userId = current_user_id();

$stmt = $db->prepare("SELECT p.*, u.name as scientist_name, u.email as scientist_email, u.designation as scientist_designation,
                             d.department_name, d.department_code
                      FROM proposals p
                      JOIN users u ON p.scientist_id = u.id
                      JOIN departments d ON p.department_id = d.id
                      WHERE p.id = ?");
$stmt->execute([$id]);
$proposal = $stmt->fetch();

if (!$proposal) {
    flash('danger', 'Proposal not found.');
    header("Location: " . url("/joint-director/proposals.php"));
    exit;
}

// Project category determination
$isFundingAgency = (($proposal['project_type'] ?? '') === 'funding_agency');
$isCompletedProposal = (($proposal['proposal_category'] ?? '') === 'completed');
$isOngoingProposal = (($proposal['proposal_category'] ?? '') === 'ongoing');

$linkedProjectId = (int)($proposal['linked_project_id'] ?? 0);
if ($linkedProjectId <= 0 && !empty($proposal['project_number'])) {
    $stmtFindP = $db->prepare("SELECT id FROM projects WHERE UPPER(TRIM(project_number)) = UPPER(TRIM(?)) LIMIT 1");
    $stmtFindP->execute([$proposal['project_number']]);
    $linkedProjectId = (int)$stmtFindP->fetchColumn();
}
$linkedProject = null;
if ($linkedProjectId > 0) {
    $stmtLP = $db->prepare("SELECT * FROM projects WHERE id = ?");
    $stmtLP->execute([$linkedProjectId]);
    $linkedProject = $stmtLP->fetch();

    if (empty($proposal['objectives']) && !empty($linkedProject['proposal_id'])) {
        $stmtOrigObj = $db->prepare("SELECT objectives FROM proposals WHERE id = ?");
        $stmtOrigObj->execute([$linkedProject['proposal_id']]);
        $objVal = $stmtOrigObj->fetchColumn();
        if ($objVal) {
            $proposal['objectives'] = $objVal;
        }
    }
}

// Real-time AJAX uniqueness check endpoint for Joint Director
if (isset($_GET['check_unique'])) {
    header('Content-Type: application/json');
    $checkVal = trim(sanitize($_GET['project_number'] ?? ''));
    if (empty($checkVal)) {
        echo json_encode(['unique' => false, 'message' => 'Project ID cannot be empty.']);
        exit;
    }
    $isUnique = is_project_number_unique($checkVal, $linkedProjectId, $id);
    echo json_encode([
        'unique' => $isUnique,
        'message' => $isUnique ? "Project ID '{$checkVal}' is available and unique." : "Project ID '{$checkVal}' is already in use by another project."
    ]);
    exit;
}

if (!can_record_irc_decision($proposal)) {
    flash('warning', "Proposal {$proposal['proposal_number']} is in status '{$proposal['current_status']}' and not currently awaiting IRC council decision.");
    header("Location: " . url("/scientist/proposal-details.php?id={$id}"));
    exit;
}

$pageTitle = 'Final IRC Decision - ' . $proposal['proposal_number'];
$error = null;

// Generate sensible institutional suggestions that Joint Director can choose from or customize
$deptCode = !empty($proposal['department_code']) ? strtoupper($proposal['department_code']) : 'RES';
$currentYear = date('Y');
$suggestedDeptId = generate_project_number($deptCode);
$suggestedStdId = "PROJ-NDRI-{$currentYear}-" . str_pad((string)$proposal['id'], 3, '0', STR_PAD_LEFT);
$suggestedPrimeId = "NDRI-PRIME-{$deptCode}-{$currentYear}-" . str_pad((string)$proposal['id'], 3, '0', STR_PAD_LEFT);
$suggestedExtId = "EXT-NDRI-{$currentYear}-" . str_pad((string)$proposal['id'], 3, '0', STR_PAD_LEFT);

$defaultProjectNumber = !empty($proposal['project_number']) 
    ? $proposal['project_number'] 
    : ($isFundingAgency ? $suggestedExtId : $suggestedDeptId);

$enteredProjectNumber = $_POST['project_number'] ?? $defaultProjectNumber;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $decision = $_POST['decision'] ?? ''; 
    $ircRemarks = trim(sanitize($_POST['irc_remarks'] ?? ''));
    $customProjectNumber = trim(sanitize($_POST['project_number'] ?? ''));
    $enteredProjectNumber = $customProjectNumber;

    // Allowed decisions
    $validDecisions = [
        'approve_active',
        'approve_completion',
        'extend_time_and_funds',
        'extend_time_only',
        'approve_ongoing',
        'reject_archive'
    ];

    if (!in_array($decision, $validDecisions, true)) {
        $error = "Please select a valid IRC Council decision.";
    } elseif ($decision === 'reject_archive' && empty($ircRemarks)) {
        $error = "Please provide the official IRC council minutes/justification for not approving this dossier.";
    } elseif ($decision === 'extend_time_and_funds' && empty($_POST['approved_extension_date'])) {
        $error = "Please specify the Sanctioned Extended End Date for the project extension.";
    } elseif ($decision === 'extend_time_only' && empty($_POST['approved_extension_date'])) {
        $error = "Please specify the Sanctioned Extended End Date for the project duration extension.";
    } elseif ($decision === 'approve_active' && !$isCompletedProposal && !$isOngoingProposal && empty($customProjectNumber)) {
        $error = "Please provide a Project ID / Project No. for this project. Only the Joint Director can assign this ID.";
    } elseif ($decision === 'approve_active' && !$isCompletedProposal && !$isOngoingProposal && !is_project_number_unique($customProjectNumber, $linkedProjectId, $id)) {
        $error = "The Project ID '" . e($customProjectNumber) . "' is already assigned to another project. Please enter a unique Project ID.";
    } else {
        try {
            $db->beginTransaction();

            $prevStatus = $proposal['current_status'];
            $now = date('Y-m-d H:i:s');
            $fundingAgency = ($isFundingAgency ? ($proposal['funding_agency'] ?? null) : null);
            $meetingId = (int)$db->query("SELECT id FROM irc_meetings ORDER BY id DESC LIMIT 1")->fetchColumn();
            if (!$meetingId) {
                $db->prepare("INSERT INTO irc_meetings (meeting_number, meeting_date, remarks, created_by) VALUES (?, ?, ?, ?)")
                   ->execute(['IRC-' . date('Y') . '-01', date('Y-m-d'), 'Annual Institute Research Council Meeting', $userId]);
                $meetingId = (int)$db->lastInsertId();
            }

            // BRANCH 1: Duration Extension AND Budget Increase (JD decision on Completed Proposal)
            if ($decision === 'extend_time_and_funds') {
                $approvedExtDate = !empty($_POST['approved_extension_date']) ? $_POST['approved_extension_date'] : ($proposal['extended_end_date'] ?: date('Y-m-d', strtotime('+1 year')));
                $approvedAddFunds = max(0, (float)($_POST['approved_additional_funds'] ?? ($proposal['additional_funds_requested'] ?: 0)));
                $currentBudget = $linkedProject ? (float)$linkedProject['approved_budget'] : (float)($proposal['budget_allocated'] ?: $proposal['proposed_budget']);
                $newTotalBudget = $currentBudget + $approvedAddFunds;
                $newStatus = STATUS_APPROVED_ACTIVE;
                $projectNumber = !empty($proposal['project_number']) ? $proposal['project_number'] : $customProjectNumber;

                // Update existing project or create active project
                if ($linkedProjectId > 0) {
                    $stmtP = $db->prepare("UPDATE projects SET end_date = ?, approved_budget = ?, project_status = 'Active', updated_at = ? WHERE id = ?");
                    $stmtP->execute([$approvedExtDate, $newTotalBudget, $now, $linkedProjectId]);
                } else {
                    $stmtP = $db->prepare("INSERT INTO projects (
                        project_number, project_type, funding_agency, funding_agency_type, proposal_id, scientist_id, department_id,
                        approved_budget, yearly_budget, start_date, end_date, project_status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
                    $stmtP->execute([
                        $projectNumber,
                        ($isFundingAgency ? 'funding_agency' : 'in_house'),
                        $fundingAgency,
                        ($isFundingAgency ? ($proposal['funding_agency_type'] ?: 'National') : 'National'),
                        $id,
                        $proposal['scientist_id'],
                        $proposal['department_id'],
                        $newTotalBudget,
                        $proposal['yearly_budget'] ?: null,
                        $proposal['proposed_start_date'] ?: date('Y-m-d'),
                        $approvedExtDate,
                        $now,
                        $now
                    ]);
                    $linkedProjectId = (int)$db->lastInsertId();
                }

                // Update proposal record
                $stmt = $db->prepare("UPDATE proposals SET
                    current_status = ?,
                    extension_decision = 'extended_time_and_funds',
                    approved_extension_date = ?,
                    approved_additional_funds = ?,
                    approved_budget = ?,
                    proposed_end_date = ?,
                    linked_project_id = ?,
                    updated_at = ?
                    WHERE id = ?");
                $stmt->execute([
                    $newStatus,
                    $approvedExtDate,
                    $approvedAddFunds,
                    $newTotalBudget,
                    $approvedExtDate,
                    $linkedProjectId,
                    $now,
                    $id
                ]);

                // Record in irc_decisions
                $stmtD = $db->prepare("INSERT INTO irc_decisions (
                    proposal_id, irc_meeting_id, decision, approved_budget, approved_start_date, approved_end_date, project_number, remarks, decided_by, decided_at
                ) VALUES (?, ?, 'Extension & Budget Increase Approved', ?, ?, ?, ?, ?, ?, ?)");
                $stmtD->execute([$id, $meetingId, $newTotalBudget, $proposal['proposed_start_date'], $approvedExtDate, $projectNumber, $ircRemarks, $userId, $now]);

                $histComment = "Joint Director & IRC approved Project Duration Extension to " . format_date($approvedExtDate) . " with Additional Grant of " . format_currency($approvedAddFunds) . ". Total Revised Budget: " . format_currency($newTotalBudget) . ". Project remains Active.";
                if (!empty($ircRemarks)) {
                    $histComment .= " Council Remarks: " . $ircRemarks;
                }
                $flashMsg = "Project duration extended to " . format_date($approvedExtDate) . " with additional grant of " . format_currency($approvedAddFunds) . "! Project remains Active.";
                $actionType = 'PROJECT_TIME_AND_BUDGET_EXTENDED';

            // BRANCH 2: Duration Extension ONLY (No Budget Increase)
            } elseif ($decision === 'extend_time_only') {
                $approvedExtDate = !empty($_POST['approved_extension_date']) ? $_POST['approved_extension_date'] : ($proposal['extended_end_date'] ?: date('Y-m-d', strtotime('+1 year')));
                $currentBudget = $linkedProject ? (float)$linkedProject['approved_budget'] : (float)($proposal['budget_allocated'] ?: $proposal['proposed_budget']);
                $newStatus = STATUS_APPROVED_ACTIVE;
                $projectNumber = !empty($proposal['project_number']) ? $proposal['project_number'] : $customProjectNumber;

                if ($linkedProjectId > 0) {
                    $stmtP = $db->prepare("UPDATE projects SET end_date = ?, project_status = 'Active', updated_at = ? WHERE id = ?");
                    $stmtP->execute([$approvedExtDate, $now, $linkedProjectId]);
                } else {
                    $stmtP = $db->prepare("INSERT INTO projects (
                        project_number, project_type, funding_agency, proposal_id, scientist_id, department_id,
                        approved_budget, start_date, end_date, project_status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
                    $stmtP->execute([
                        $projectNumber,
                        ($isFundingAgency ? 'funding_agency' : 'in_house'),
                        $fundingAgency,
                        $id,
                        $proposal['scientist_id'],
                        $proposal['department_id'],
                        $currentBudget,
                        $proposal['proposed_start_date'] ?: date('Y-m-d'),
                        $approvedExtDate,
                        $now,
                        $now
                    ]);
                    $linkedProjectId = (int)$db->lastInsertId();
                }

                $stmt = $db->prepare("UPDATE proposals SET
                    current_status = ?,
                    extension_decision = 'extended_time_only',
                    approved_extension_date = ?,
                    approved_additional_funds = 0.00,
                    proposed_end_date = ?,
                    linked_project_id = ?,
                    updated_at = ?
                    WHERE id = ?");
                $stmt->execute([
                    $newStatus,
                    $approvedExtDate,
                    $approvedExtDate,
                    $linkedProjectId,
                    $now,
                    $id
                ]);

                $stmtD = $db->prepare("INSERT INTO irc_decisions (
                    proposal_id, irc_meeting_id, decision, approved_budget, approved_start_date, approved_end_date, project_number, remarks, decided_by, decided_at
                ) VALUES (?, ?, 'Extension (Time Only) Approved', ?, ?, ?, ?, ?, ?, ?)");
                $stmtD->execute([$id, $meetingId, $currentBudget, $proposal['proposed_start_date'], $approvedExtDate, $projectNumber, $ircRemarks, $userId, $now]);

                $histComment = "Joint Director & IRC approved Project Duration Extension to " . format_date($approvedExtDate) . " without additional funds. Budget remains unchanged at " . format_currency($currentBudget) . ". Project remains Active.";
                if (!empty($ircRemarks)) {
                    $histComment .= " Council Remarks: " . $ircRemarks;
                }
                $flashMsg = "Project duration extended to " . format_date($approvedExtDate) . " (without additional funds)! Project remains Active.";
                $actionType = 'PROJECT_TIME_EXTENDED_ONLY';

            // BRANCH 3: Conclude Completed Project (Formally Complete & Close)
            } elseif ($decision === 'approve_completion' || ($isCompletedProposal && $decision === 'approve_active')) {
                $finalBudget = (float)($_POST['approved_budget'] ?? ($proposal['budget_allocated'] ?: $proposal['proposed_budget']));
                $projectNumber = !empty($customProjectNumber) ? $customProjectNumber : $proposal['project_number'];
                $newStatus = STATUS_COMPLETED;

                if ($linkedProjectId > 0) {
                    $stmtP = $db->prepare("UPDATE projects SET project_status = 'Completed', updated_at = ? WHERE id = ?");
                    $stmtP->execute([$now, $linkedProjectId]);
                } else {
                    $stmtP = $db->prepare("INSERT INTO projects (
                        project_number, project_type, funding_agency, funding_agency_type, proposal_id, scientist_id, department_id,
                        approved_budget, yearly_budget, start_date, end_date, project_status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', ?, ?)");
                    $stmtP->execute([
                        $projectNumber,
                        ($isFundingAgency ? 'funding_agency' : 'in_house'),
                        $fundingAgency,
                        ($isFundingAgency ? ($proposal['funding_agency_type'] ?: 'National') : 'National'),
                        $id,
                        $proposal['scientist_id'],
                        $proposal['department_id'],
                        $finalBudget,
                        $proposal['yearly_budget'] ?: null,
                        $proposal['proposed_start_date'] ?: date('Y-m-d'),
                        $proposal['proposed_end_date'] ?: date('Y-m-d'),
                        $now,
                        $now
                    ]);
                    $linkedProjectId = (int)$db->lastInsertId();
                }

                $stmt = $db->prepare("UPDATE proposals SET
                    current_status = ?,
                    extension_decision = 'concluded',
                    project_number = ?,
                    approved_budget = ?,
                    linked_project_id = ?,
                    updated_at = ?
                    WHERE id = ?");
                $stmt->execute([
                    $newStatus,
                    $projectNumber,
                    $finalBudget,
                    $linkedProjectId,
                    $now,
                    $id
                ]);

                $stmtD = $db->prepare("INSERT INTO irc_decisions (
                    proposal_id, irc_meeting_id, decision, approved_budget, approved_start_date, approved_end_date, project_number, remarks, decided_by, decided_at
                ) VALUES (?, ?, 'Project Concluded & Completed', ?, ?, ?, ?, ?, ?, ?)");
                $stmtD->execute([$id, $meetingId, $finalBudget, $proposal['proposed_start_date'], $proposal['proposed_end_date'], $projectNumber, $ircRemarks, $userId, $now]);

                $histComment = "Final Institute Research Council (IRC) Sanction granted for Completed Project {$projectNumber}. Project formally concluded and archived in institutional registry.";
                if (!empty($ircRemarks)) {
                    $histComment .= " Council Remarks: " . $ircRemarks;
                }
                $flashMsg = "Completed Project {$projectNumber} approved and formally closed by IRC!";
                $actionType = 'COMPLETED_PROJECT_SANCTIONED';

            // BRANCH 4: Approve On Going Project Biannual Progress Report
            } elseif ($decision === 'approve_ongoing' || ($isOngoingProposal && $decision === 'approve_active')) {
                $newStatus = STATUS_APPROVED_ACTIVE;
                $projectNumber = $proposal['project_number'];

                if ($linkedProjectId <= 0 && !empty($projectNumber)) {
                    $stmtFindP = $db->prepare("SELECT id FROM projects WHERE project_number = ? LIMIT 1");
                    $stmtFindP->execute([$projectNumber]);
                    $foundP = $stmtFindP->fetch();
                    if ($foundP) {
                        $linkedProjectId = (int)$foundP['id'];
                    }
                }

                if ($linkedProjectId > 0) {
                    $stmtP = $db->prepare("UPDATE projects SET updated_at = ? WHERE id = ?");
                    $stmtP->execute([$now, $linkedProjectId]);

                    // Update corresponding progress_reports to mark review_status as 'Approved'
                    try {
                        $stmtR = $db->prepare("UPDATE progress_reports 
                            SET review_status = 'Approved', 
                                reviewer_comments = ?, 
                                reviewed_by = ?, 
                                reviewer_role = 'Joint Director', 
                                reviewed_at = ?, 
                                feedback_viewed_by_scientist = 0, 
                                updated_at = ? 
                            WHERE project_id = ? AND (review_status IS NULL OR review_status = 'Submitted' OR review_status = 'Pending Review' OR review_status = 'Approved for IRC')");
                        $stmtR->execute([$ircRemarks ?: 'Approved by Institute Research Council (IRC)', $userId, $now, $now, $linkedProjectId]);
                    } catch (Throwable $eR) {}
                }

                $stmt = $db->prepare("UPDATE proposals SET
                    current_status = ?,
                    linked_project_id = ?,
                    updated_at = ?
                    WHERE id = ?");
                $stmt->execute([
                    $newStatus,
                    $linkedProjectId > 0 ? $linkedProjectId : null,
                    $now,
                    $id
                ]);

                $stmtD = $db->prepare("INSERT INTO irc_decisions (
                    proposal_id, irc_meeting_id, decision, approved_budget, approved_start_date, approved_end_date, project_number, remarks, decided_by, decided_at
                ) VALUES (?, ?, 'Progress Report Approved', ?, ?, ?, ?, ?, ?, ?)");
                $stmtD->execute([$id, $meetingId, $proposal['approved_budget'] ?: $proposal['proposed_budget'], $proposal['proposed_start_date'], $proposal['proposed_end_date'], $projectNumber, $ircRemarks, $userId, $now]);

                $histComment = "Biannual Progress Report for cycle " . e($proposal['progress_report_period'] ?: 'General') . " approved by IRC Council. Active research project maintained.";
                if (!empty($ircRemarks)) {
                    $histComment .= " Council Remarks: " . $ircRemarks;
                }
                $flashMsg = "Progress report approved! Active research project maintained.";
                $actionType = 'ONGOING_PROGRESS_REPORT_APPROVED';

            // BRANCH 5: Standard New Proposal - Grant Final Approval & Create Active Project
            } elseif ($decision === 'approve_active') {
                $approvedBudget = (float)($_POST['approved_budget'] ?? $proposal['proposed_budget']);
                $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : ($proposal['proposed_start_date'] ?: date('Y-m-d'));
                $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : ($proposal['proposed_end_date'] ?: date('Y-m-d', strtotime('+3 years')));
                $projectNumber = $customProjectNumber;
                $newStatus = STATUS_APPROVED_ACTIVE;

                $stmt = $db->prepare("UPDATE proposals SET
                    current_status = ?,
                    project_number = ?,
                    approved_budget = ?,
                    proposed_start_date = ?,
                    proposed_end_date = ?,
                    updated_at = ?
                    WHERE id = ?");
                $stmt->execute([
                    $newStatus,
                    $projectNumber,
                    $approvedBudget,
                    $startDate,
                    $endDate,
                    $now,
                    $id
                ]);

                $stmtP = $db->prepare("INSERT INTO projects (
                    project_number, project_type, funding_agency, funding_agency_type, proposal_id, scientist_id, department_id,
                    approved_budget, yearly_budget, start_date, end_date, project_status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
                $stmtP->execute([
                    $projectNumber,
                    ($isFundingAgency ? 'funding_agency' : 'in_house'),
                    $fundingAgency,
                    ($isFundingAgency ? ($proposal['funding_agency_type'] ?: 'National') : 'National'),
                    $id,
                    $proposal['scientist_id'],
                    $proposal['department_id'],
                    $approvedBudget,
                    $proposal['yearly_budget'] ?: null,
                    $startDate,
                    $endDate,
                    $now,
                    $now
                ]);
                $newProjectId = (int)$db->lastInsertId();
                $db->prepare("UPDATE proposals SET linked_project_id = ? WHERE id = ?")->execute([$newProjectId, $id]);

                $stmtD = $db->prepare("INSERT INTO irc_decisions (
                    proposal_id, irc_meeting_id, decision, approved_budget, approved_start_date, approved_end_date, project_number, remarks, decided_by, decided_at
                ) VALUES (?, ?, 'Approved', ?, ?, ?, ?, ?, ?, ?)");
                $stmtD->execute([$id, $meetingId, $approvedBudget, $startDate, $endDate, $projectNumber, $ircRemarks, $userId, $now]);

                $histComment = "Final Institute Research Council (IRC) Sanction granted. Project ID {$projectNumber} allocated by Joint Director with approved grant of " . format_currency($approvedBudget) . ".";
                if (!empty($ircRemarks)) {
                    $histComment .= " Council Remarks: " . $ircRemarks;
                }
                $flashMsg = "Proposal approved by IRC! Active Research Project {$projectNumber} created successfully.";
                $actionType = 'PROPOSAL_APPROVED_ACTIVE';

            // BRANCH 6: Reject / Archive
            } else {
                $newStatus = STATUS_NOT_APPROVED_ARCHIVED;
                $stmt = $db->prepare("UPDATE proposals SET current_status = ?, updated_at = ? WHERE id = ?");
                $stmt->execute([$newStatus, $now, $id]);

                $stmtD = $db->prepare("INSERT INTO irc_decisions (
                    proposal_id, irc_meeting_id, decision, approved_budget, approved_start_date, approved_end_date, project_number, remarks, decided_by, decided_at
                ) VALUES (?, ?, 'Not Approved', NULL, NULL, NULL, NULL, ?, ?, ?)");
                $stmtD->execute([$id, $meetingId, $ircRemarks, $userId, $now]);

                $histComment = "Not approved by Institute Research Council (IRC). Dossier archived. Reason: " . $ircRemarks;
                $flashMsg = "Proposal {$proposal['proposal_number']} marked Not Approved / Archived.";
                $actionType = 'PROPOSAL_ARCHIVED';
            }

            // Record comment
            if (!empty($ircRemarks)) {
                $stmtC = $db->prepare("INSERT INTO proposal_comments (proposal_id, user_id, comment_stage, comment, created_at)
                                       VALUES (?, ?, 'IRC Final Decision', ?, ?)");
                $stmtC->execute([$id, $userId, $ircRemarks, $now]);
            }

            // Record status history
            record_status_history($id, $prevStatus, $newStatus, $userId, 'Joint Director (IRC)', $histComment);

            // Audit log
            log_audit($userId, $actionType, 'proposals', $id, "IRC Council decision '{$decision}' recorded by Joint Director. Remarks: {$ircRemarks}");

            $db->commit();
            flash('success', $flashMsg);

            if ($decision === 'reject_archive') {
                header("Location: " . url("/joint-director/proposals.php"));
            } else {
                header("Location: " . url("/joint-director/projects.php"));
            }
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to record IRC decision: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <?= render_proposal_category_badge($proposal['proposal_category'] ?? 'new') ?>
            <h3 class="fw-bold mb-0 text-dark">
                <?php if ($isOngoingProposal): ?>
                    Institute Research Council (IRC) &mdash; Progress Report Decision
                <?php elseif ($isCompletedProposal): ?>
                    Institute Research Council (IRC) &mdash; Project Completion Decision
                <?php else: ?>
                    Institute Research Council (IRC) &mdash; Final Decision
                <?php endif; ?>
            </h3>
        </div>
        <p class="text-muted small mb-0">Record the official council consensus, grant sanction, and active project charter</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url("/export-doc.php?id=" . ($id) . "") ?>" class="btn btn-outline-secondary btn-sm" title="Download Office Open XML Document (.docx)">
            <i class="bi bi-file-earmark-word me-1"></i> Export Word (.docx)
        </a>
        <a href="<?= url("/joint-director/dashboard.php") ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-octagon-fill me-2"></i> <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Decision Form Box -->
<div class="card shadow-sm border-0 mb-4" style="border-top: 4px solid #10b981 !important;">
    <div class="card-header bg-white py-3">
        <h5 class="fw-bold text-dark m-0">
            <i class="bi bi-award-fill text-success me-2"></i>Record Official IRC Council Decision
        </h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="<?= url("/joint-director/irc-decision.php?id=" . ($id) . "") ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="row g-3 mb-3">
                <?php if ($isCompletedProposal): ?>
                    <!-- Completed Proposal / Completion Project Review -->
                    <?php if (!empty($proposal['extension_requested'])): ?>
                        <div class="col-12">
                            <div class="p-3 bg-warning bg-opacity-10 rounded-3 border border-warning">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-warning text-dark px-2 py-1 fw-bold fs-6">
                                            <i class="bi bi-clock-history me-1"></i> Extension & Additional Funds Requested
                                        </span>
                                        <span class="text-muted small">Submitted by PI in Completion Dossier</span>
                                    </div>
                                    <span class="badge bg-white text-dark border font-monospace">Project No: <?= e($proposal['project_number'] ?: 'N/A') ?></span>
                                </div>
                                <div class="row g-3 small text-dark mt-1">
                                    <div class="col-md-4">
                                        <strong class="text-muted d-block">Requested Extended End Date:</strong>
                                        <span class="fs-6 fw-bold text-danger"><?= !empty($proposal['extended_end_date']) ? format_date($proposal['extended_end_date']) : 'Not specified' ?></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong class="text-muted d-block">Additional Funds Requested:</strong>
                                        <span class="fs-6 fw-bold text-primary"><?= format_currency((float)($proposal['additional_funds_requested'] ?? 0)) ?></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong class="text-muted d-block">Current Approved Budget:</strong>
                                        <span class="fs-6 fw-bold text-success"><?= format_currency((float)($linkedProject['approved_budget'] ?? $proposal['budget_allocated'] ?: $proposal['proposed_budget'])) ?></span>
                                    </div>
                                    <?php if (!empty($proposal['extension_justification'])): ?>
                                        <div class="col-12">
                                            <strong class="text-muted d-block">Justification for Extension:</strong>
                                            <div class="bg-white p-2 rounded border mt-1 text-dark" style="line-height: 1.5;">
                                                <?= nl2br(e($proposal['extension_justification'])) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Joint Director Extension Decision Configuration Box -->
                        <div class="col-12">
                            <div class="p-3 bg-light rounded-3 border border-primary">
                                <h6 class="fw-bold text-primary mb-2">
                                    <i class="bi bi-sliders me-1"></i> IRC Extension Sanction Parameters (Joint Director Prerogative)
                                </h6>
                                <p class="text-muted small mb-3">
                                    In the IRC meeting, the Joint Director can decide to: <strong>(1)</strong> Extend time and sanction additional budget, <strong>(2)</strong> Extend time only without additional budget, or <strong>(3)</strong> Decline extension and conclude the project as completed.
                                </p>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">
                                            <i class="bi bi-calendar-event me-1"></i> Sanctioned Extended End Date
                                        </label>
                                        <input type="date" 
                                               name="approved_extension_date" 
                                               id="approved_extension_date"
                                               class="form-control fw-bold" 
                                               value="<?= e($proposal['extended_end_date'] ?: date('Y-m-d', strtotime('+1 year'))) ?>">
                                        <small class="text-muted">Used if you approve duration extension.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-dark">
                                            <i class="bi bi-cash me-1"></i> Approved Additional Grant (₹ INR)
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" 
                                                   name="approved_additional_funds" 
                                                   id="approved_additional_funds"
                                                   class="form-control fw-bold text-success" 
                                                   step="0.01" 
                                                   min="0" 
                                                   value="<?= e($proposal['additional_funds_requested'] ?: 0) ?>">
                                        </div>
                                        <small class="text-muted">Used if you grant extra funding. Total revised budget will be updated automatically.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Completed Project Number Confirmation -->
                    <div class="col-12">
                        <div class="p-3 bg-light rounded-3 border border-success">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-success text-white px-2 py-1 fw-bold fs-6">
                                        <i class="bi bi-journal-check me-1"></i> <?= $isFundingAgency ? 'Externally Funded Project' : 'In-House Project' ?>
                                    </span>
                                    <span class="text-muted small">Project No: <strong><?= e($proposal['project_number'] ?: 'N/A') ?></strong></span>
                                </div>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-white font-monospace fw-bold text-success">
                                    <i class="bi bi-fingerprint me-1"></i> CONFIRMED PROJECT NO
                                </span>
                                <input type="text" 
                                       id="project_number" 
                                       name="project_number" 
                                       class="form-control font-monospace fw-bold fs-6 text-dark" 
                                       value="<?= e($enteredProjectNumber) ?>" 
                                       required 
                                       autocomplete="off">
                                <button class="btn btn-outline-secondary px-3" type="button" id="btn-check-unique" onclick="checkProjectIdUnique()">
                                    <i class="bi bi-check-circle me-1"></i> Check Uniqueness
                                </button>
                            </div>
                            <div id="project-id-feedback" class="small mt-2" style="display: none;"></div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-secondary">Final Sanctioned / Total Budget (₹ INR) *</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" name="approved_budget" class="form-control fw-bold text-success" step="0.01" min="0" value="<?= e($proposal['budget_allocated'] ?: $proposal['proposed_budget']) ?>" required>
                        </div>
                        <small class="text-muted">Allocated: <?= format_currency((float)($proposal['budget_allocated'] ?: $proposal['proposed_budget'])) ?> | Utilized: <?= format_currency((float)$proposal['budget_utilized']) ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-secondary">Project Completion / Sanction Date *</label>
                        <input type="date" name="end_date" class="form-control" value="<?= e($proposal['proposed_end_date'] ?: date('Y-m-d')) ?>" required>
                    </div>

                <?php elseif ($isOngoingProposal): ?>
                    <!-- On Going Project Progress Report Review -->
                    <div class="col-12">
                        <div class="p-3 bg-light rounded-3 border border-primary">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-primary text-white px-2 py-1 fw-bold fs-6">
                                        <i class="bi bi-arrow-repeat me-1"></i> On Going Project - Biannual Progress Report
                                    </span>
                                    <span class="badge bg-info-subtle text-dark border">
                                        <i class="bi bi-calendar2-range me-1"></i> <?= e($proposal['progress_report_period'] ?: 'General Progress') ?>
                                    </span>
                                </div>
                                <span class="text-dark font-monospace fw-bold">Project No: <?= e($proposal['project_number']) ?></span>
                            </div>
                            <p class="text-muted small mb-2">
                                Review the progress report submitted by the Principal Investigator for IRC consideration. Approving this report maintains the project in <strong>Active</strong> status.
                            </p>
                            <input type="hidden" id="project_number" name="project_number" value="<?= e($proposal['project_number']) ?>">
                        </div>
                    </div>

                <?php elseif ($isFundingAgency): ?>
                    <!-- Funding Agency Project Section -->
                    <div class="col-12">
                        <div class="p-3 bg-light rounded-3 border border-success">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fw-bold fs-6">
                                        <i class="bi bi-bank2 me-1"></i> Externally Funded Project
                                    </span>
                                    <span class="text-muted small">Assigned by Scientist during proposal creation</span>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setSuggestedId('<?= e($suggestedExtId) ?>')">
                                    <i class="bi bi-magic me-1"></i> Default Format: <?= e($suggestedExtId) ?>
                                </button>
                            </div>

                            <div class="row g-3 mt-1 align-items-center">
                                <div class="col-md-5">
                                    <div class="bg-white p-3 rounded border border-success-subtle">
                                        <span class="text-muted small d-block mb-1">Funding Agency (from Scientist Proposal):</span>
                                        <span class="fs-6 fw-bold text-dark d-flex align-items-center">
                                            <i class="bi bi-bank2 text-success me-2 fs-5"></i>
                                            <?= e($proposal['funding_agency'] ?: 'Not Specified') ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="col-md-7">
                                    <label for="project_number" class="form-label fw-bold text-dark mb-1">
                                        <i class="bi bi-fingerprint text-primary me-1"></i> Sanctioned Project ID / Sanction No. <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="text" 
                                               id="project_number" 
                                               name="project_number" 
                                               class="form-control font-monospace fw-bold text-dark" 
                                               placeholder="e.g. DBT/NDRI/2026/001, EXT-NDRI-2026-001" 
                                               value="<?= e($enteredProjectNumber) ?>" 
                                               required 
                                               autocomplete="off">
                                        <button class="btn btn-outline-secondary px-3" type="button" id="btn-check-unique" onclick="checkProjectIdUnique()">
                                            <i class="bi bi-check-circle me-1"></i> Check Uniqueness
                                        </button>
                                    </div>
                                    <div id="project-id-feedback" class="small mt-1" style="display: none;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-secondary">Sanctioned / Approved Budget (₹ INR) *</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" name="approved_budget" class="form-control fw-bold text-success" step="0.01" min="0" value="<?= e($proposal['proposed_budget']) ?>" required>
                        </div>
                        <small class="text-muted">Proposed: <?= format_currency((float)$proposal['proposed_budget']) ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-secondary">Sanctioned Start Date *</label>
                        <input type="date" name="start_date" class="form-control" value="<?= e($proposal['proposed_start_date'] ?: date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-secondary">Sanctioned End Date *</label>
                        <input type="date" name="end_date" class="form-control" value="<?= e($proposal['proposed_end_date'] ?: date('Y-m-d', strtotime('+3 years'))) ?>" required>
                    </div>

                <?php else: ?>
                    <!-- In-House New Project Section: Joint Director Choice of Project ID -->
                    <div class="col-12">
                        <div class="p-3 bg-light rounded-3 border border-primary border-opacity-50">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                                <div>
                                    <label for="project_number" class="form-label fw-bold text-dark mb-0 fs-6">
                                        <i class="bi bi-fingerprint text-primary me-1"></i> In-House Project ID (Joint Director Choice) <span class="text-danger">*</span>
                                    </label>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-2">
                                        <i class="bi bi-house-door-fill me-1"></i>In-house Project
                                    </span>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary" onclick="setSuggestedId('<?= e($suggestedDeptId) ?>')">
                                        <i class="bi bi-magic me-1"></i> Division Format: <?= e($suggestedDeptId) ?>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="setSuggestedId('<?= e($suggestedStdId) ?>')">
                                        <i class="bi bi-hash me-1"></i> Standard: <?= e($suggestedStdId) ?>
                                    </button>
                                    <button type="button" class="btn btn-outline-dark" onclick="setSuggestedId('<?= e($suggestedPrimeId) ?>')">
                                        <i class="bi bi-building me-1"></i> NDRI PRIME: <?= e($suggestedPrimeId) ?>
                                    </button>
                                </div>
                            </div>
                            <p class="text-muted small mb-2">
                                As Joint Director, you have the option to give the <strong>Project ID of your choice</strong> for this approved in-house project. You can type any custom institutional alphanumeric ID or select a format above.
                            </p>
                            <div class="input-group">
                                <span class="input-group-text bg-white font-monospace fw-bold text-primary">
                                    <i class="bi bi-journal-code me-1"></i> PROJECT ID
                                </span>
                                <input type="text" 
                                       id="project_number" 
                                       name="project_number" 
                                       class="form-control font-monospace fw-bold fs-6 text-dark" 
                                       placeholder="e.g. NDRI/ABT/2026/001, NDRI-PRIME-DAIRY-2026-05" 
                                       value="<?= e($enteredProjectNumber) ?>" 
                                       required 
                                       autocomplete="off">
                                <button class="btn btn-outline-secondary px-3" type="button" id="btn-check-unique" onclick="checkProjectIdUnique()">
                                    <i class="bi bi-check-circle me-1"></i> Check Uniqueness
                                </button>
                            </div>
                            <div id="project-id-feedback" class="small mt-2" style="display: none;"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-secondary">Sanctioned / Approved Budget (₹ INR) *</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" name="approved_budget" class="form-control fw-bold text-success" step="0.01" min="0" value="<?= e($proposal['proposed_budget']) ?>" required>
                        </div>
                        <small class="text-muted">Proposed: <?= format_currency((float)$proposal['proposed_budget']) ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-secondary">Sanctioned Start Date *</label>
                        <input type="date" name="start_date" class="form-control" value="<?= e($proposal['proposed_start_date'] ?: date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-secondary">Sanctioned End Date *</label>
                        <input type="date" name="end_date" class="form-control" value="<?= e($proposal['proposed_end_date'] ?: date('Y-m-d', strtotime('+3 years'))) ?>" required>
                    </div>
                <?php endif; ?>

                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Council Minutes / Remarks / Resolution</label>
                    <textarea name="irc_remarks" class="form-control" rows="3" placeholder="Enter Council resolution number, recommended follow-up actions, or reason if not approved..."></textarea>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 justify-content-end align-items-center pt-3 border-top">
                <button type="submit" name="decision" value="reject_archive" class="btn btn-outline-danger fw-semibold" data-confirm="Are you sure you want to mark this dossier as NOT APPROVED / ARCHIVED by the IRC?">
                    <i class="bi bi-archive me-1"></i> Not Approved / Archive
                </button>

                <?php if ($isCompletedProposal): ?>
                    <?php if (!empty($proposal['extension_requested'])): ?>
                        <!-- If extension requested, provide the two extension choices -->
                        <button type="submit" name="decision" value="extend_time_only" class="btn btn-outline-primary fw-semibold" data-confirm="Extend project duration ONLY (no additional funds)? The project will remain Active.">
                            <i class="bi bi-clock-history me-1"></i> Approve Duration Extension (Time Only)
                        </button>
                        <button type="submit" name="decision" value="extend_time_and_funds" class="btn btn-primary fw-semibold px-3" data-confirm="Approve project duration extension AND additional budget? The project will remain Active with revised budget.">
                            <i class="bi bi-plus-circle me-1"></i> Approve Extension & Increase Budget
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="decision" value="approve_completion" class="btn btn-success fw-semibold px-3" data-confirm="Are you sure you want to grant FINAL IRC SANCTION and formally conclude this project as Completed?">
                        <i class="bi bi-check2-all me-1"></i> Conclude Project as Completed
                    </button>
                <?php elseif ($isOngoingProposal): ?>
                    <button type="submit" name="decision" value="approve_ongoing" class="btn btn-success fw-semibold px-4" data-confirm="Are you sure you want to approve this Biannual Progress Report? Project will remain Active.">
                        <i class="bi bi-check2-all me-1"></i> Approve Progress Report & Continue Project
                    </button>
                <?php else: ?>
                    <button type="submit" name="decision" value="approve_active" class="btn btn-success fw-semibold px-4" data-confirm="Are you sure you want to grant FINAL IRC APPROVAL and create an active research project?">
                        <i class="bi bi-check2-all me-1"></i> Grant Final IRC Approval & Create Project
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Proposal Details Summary -->
<div class="row g-4">
    <div class="col-lg-8">
        <?php if ($isOngoingProposal): ?>
            <!-- ONGOING PROJECT PROGRESS REPORT VIEW -->
            <div class="detail-section-card mb-4 border-primary-subtle shadow-sm">
                <div class="detail-section-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-arrow-repeat me-2"></i> Biannual Progress Report (<?= e($proposal['progress_report_period'] ?: 'General Progress') ?>)</span>
                    <span class="badge bg-white text-primary fw-semibold font-monospace" style="font-size: 0.75rem;">
                        Project No: <?= e($proposal['project_number']) ?>
                    </span>
                </div>
                <div class="detail-section-body">
                    <?php if (!empty($proposal['final_report'])): ?>
                        <div class="bg-light p-3 rounded border text-dark" style="line-height: 1.7; font-size: 0.95rem;">
                            <?= render_rich_text($proposal['final_report']) ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted fst-italic mb-0">No progress summary submitted.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Significant Achievements During Progress Cycle -->
            <div class="detail-section-card mb-4 shadow-sm">
                <div class="detail-section-header bg-white border-bottom">
                    <i class="bi bi-trophy-fill text-warning me-2"></i> Significant Achievements During This Reporting Period
                </div>
                <div class="detail-section-body">
                    <?php if (!empty($proposal['significant_achievements'])): ?>
                        <div class="text-dark" style="line-height: 1.6;">
                            <?= render_rich_text($proposal['significant_achievements']) ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted fst-italic mb-0">No specific achievements recorded for this reporting period.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action Taken Report (ATR) on Previous IRC Recommendations -->
            <div class="detail-section-card mb-4 shadow-sm">
                <div class="detail-section-header bg-white border-bottom">
                    <i class="bi bi-clock-history text-primary me-2"></i> Action Taken Report (ATR) on Previous IRC Recommendations
                </div>
                <div class="detail-section-body">
                    <?php if (!empty($proposal['previous_irc_atr'])): ?>
                        <div class="bg-light p-3 rounded border text-dark" style="line-height: 1.6;">
                            <?= render_rich_text($proposal['previous_irc_atr']) ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted fst-italic mb-0">None reported or not applicable.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Output / Outcome -->
            <div class="detail-section-card mb-4 shadow-sm">
                <div class="detail-section-header bg-white border-bottom">
                    <i class="bi bi-box-seam text-info me-2"></i> Period Deliverables, Publications & Outcomes
                </div>
                <div class="detail-section-body">
                    <?php if (!empty($proposal['output_outcome'])): ?>
                        <div class="text-dark" style="line-height: 1.6;">
                            <?= render_rich_text($proposal['output_outcome']) ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted fst-italic mb-0">No publications or outputs reported for this period.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Financial Utilization Summary Card -->
            <div class="detail-section-card mb-4 shadow-sm">
                <div class="detail-section-header bg-white border-bottom">
                    <i class="bi bi-cash-stack text-success me-2"></i> Budget Allocation & Progressive Expenditure Statement
                </div>
                <div class="detail-section-body">
                    <?php
                        $alloc = (float)($proposal['budget_allocated'] ?: $proposal['proposed_budget']);
                        $util = (float)($proposal['budget_utilized'] ?? 0);
                        $balance = $alloc - $util;
                        $pct = ($alloc > 0) ? round(($util / $alloc) * 100, 1) : 0;
                    ?>
                    <div class="row g-3 text-center">
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded border">
                                <small class="text-muted d-block text-uppercase fw-semibold mb-1">Sanctioned Budget</small>
                                <h4 class="fw-bold text-primary mb-0"><?= format_currency($alloc) ?></h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded border">
                                <small class="text-muted d-block text-uppercase fw-semibold mb-1">Progressive Expenditure</small>
                                <h4 class="fw-bold text-success mb-0"><?= format_currency($util) ?></h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded border">
                                <small class="text-muted d-block text-uppercase fw-semibold mb-1">Available Balance</small>
                                <h4 class="fw-bold <?= $balance < 0 ? 'text-danger' : 'text-dark' ?> mb-0"><?= format_currency($balance) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Grant Utilization Percentage</span>
                            <span class="fw-bold text-dark"><?= $pct ?>%</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar <?= $pct > 100 ? 'bg-danger' : 'bg-success' ?>" 
                                 role="progressbar" 
                                 style="width: <?= min(100, $pct) ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($proposal['other_details'])): ?>
                <div class="detail-section-card mb-4 shadow-sm">
                    <div class="detail-section-header bg-white border-bottom">
                        <i class="bi bi-calendar-check text-secondary me-2"></i> Next Cycle Milestones & Plan
                    </div>
                    <div class="detail-section-body text-secondary small">
                        <?= render_rich_text($proposal['other_details']) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($proposal['objectives'])): ?>
                <div class="detail-section-card mb-4 shadow-sm">
                    <div class="detail-section-header bg-white border-bottom">
                        <i class="bi bi-bullseye text-primary me-2"></i> Approved Project Specific Objectives
                    </div>
                    <div class="detail-section-body text-dark" style="line-height: 1.6;">
                        <?= render_rich_text($proposal['objectives']) ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($isCompletedProposal): ?>
            <!-- COMPLETED PROPOSAL DOSSIER VIEW -->
            <div class="detail-section-card mb-4 border-success-subtle shadow-sm">
                <div class="detail-section-header bg-success text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-file-earmark-text-fill me-2"></i> Final Report (Executive Summary)</span>
                    <span class="badge bg-white text-success fw-semibold font-monospace" style="font-size: 0.75rem;">
                        Limit: 250 words
                    </span>
                </div>
                <div class="detail-section-body">
                    <?php if (!empty($proposal['final_report'])): ?>
                        <div class="bg-light p-3 rounded border text-dark" style="line-height: 1.7; font-size: 0.95rem;">
                            <?= render_rich_text($proposal['final_report']) ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted fst-italic mb-0">No final report submitted.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Significant Achievements -->
            <div class="detail-section-card mb-4 shadow-sm">
                <div class="detail-section-header bg-white border-bottom">
                    <i class="bi bi-trophy-fill text-warning me-2"></i> Significant Achievements During Project Tenure
                </div>
                <div class="detail-section-body">
                    <?php if (!empty($proposal['significant_achievements'])): ?>
                        <div class="text-dark" style="line-height: 1.6;">
                            <?= render_rich_text($proposal['significant_achievements']) ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted fst-italic mb-0">No significant achievements recorded.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action Taken Report (ATR) on Previous IRC Recommendations -->
            <div class="detail-section-card mb-4 shadow-sm">
                <div class="detail-section-header bg-white border-bottom">
                    <i class="bi bi-clock-history text-primary me-2"></i> Action Taken Report (ATR) on Recommendations Made in Previous IRC
                </div>
                <div class="detail-section-body">
                    <?php if (!empty($proposal['previous_irc_atr'])): ?>
                        <div class="bg-light p-3 rounded border text-dark" style="line-height: 1.6;">
                            <?= render_rich_text($proposal['previous_irc_atr']) ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted fst-italic mb-0">None reported or not applicable.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Output / Outcome -->
            <div class="detail-section-card mb-4 shadow-sm">
                <div class="detail-section-header bg-white border-bottom">
                    <i class="bi bi-box-seam text-info me-2"></i> Project Output / Outcome (Deliverables, Publications, Technologies, Patents)
                </div>
                <div class="detail-section-body">
                    <?php if (!empty($proposal['output_outcome'])): ?>
                        <div class="text-dark" style="line-height: 1.6;">
                            <?= render_rich_text($proposal['output_outcome']) ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted fst-italic mb-0">No outputs or outcomes specified.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($proposal['objectives'])): ?>
                <div class="detail-section-card mb-4 shadow-sm">
                    <div class="detail-section-header bg-white border-bottom">
                        <i class="bi bi-bullseye text-primary me-2"></i> Project Specific Objectives
                    </div>
                    <div class="detail-section-body text-dark" style="line-height: 1.6;">
                        <?= render_rich_text($proposal['objectives']) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Financial Utilization Summary Card -->
            <div class="detail-section-card mb-4 shadow-sm">
                <div class="detail-section-header bg-white border-bottom">
                    <i class="bi bi-cash-stack text-success me-2"></i> Budget Allocation & Utilization Statement
                </div>
                <div class="detail-section-body">
                    <?php
                        $alloc = (float)($proposal['budget_allocated'] ?: $proposal['proposed_budget']);
                        $util = (float)($proposal['budget_utilized'] ?? 0);
                        $balance = $alloc - $util;
                        $pct = ($alloc > 0) ? round(($util / $alloc) * 100, 1) : 0;
                    ?>
                    <div class="row g-3 text-center">
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded border">
                                <small class="text-muted d-block text-uppercase fw-semibold mb-1">Budget Allocated</small>
                                <h4 class="fw-bold text-primary mb-0"><?= format_currency($alloc) ?></h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded border">
                                <small class="text-muted d-block text-uppercase fw-semibold mb-1">Budget Utilized</small>
                                <h4 class="fw-bold text-success mb-0"><?= format_currency($util) ?></h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded border">
                                <small class="text-muted d-block text-uppercase fw-semibold mb-1">Unutilized Balance</small>
                                <h4 class="fw-bold <?= $balance < 0 ? 'text-danger' : 'text-dark' ?> mb-0"><?= format_currency($balance) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Grant Utilization Percentage</span>
                            <span class="fw-bold text-dark"><?= $pct ?>%</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar <?= $pct > 100 ? 'bg-danger' : 'bg-success' ?>" 
                                 role="progressbar" 
                                 style="width: <?= min(100, $pct) ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($proposal['other_details'])): ?>
                <div class="detail-section-card mb-4 shadow-sm">
                    <div class="detail-section-header bg-white border-bottom">
                        <i class="bi bi-info-circle text-secondary me-2"></i> Other Pertinent Details
                    </div>
                    <div class="detail-section-body text-secondary small">
                        <?= render_rich_text($proposal['other_details']) ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- STANDARD NEW PROPOSAL VIEW -->
            <div class="detail-section-card mb-4">
                <div class="detail-section-header">
                    <i class="bi bi-journal-text text-primary"></i> Proposal Summary for Council Consideration
                </div>
                <div class="detail-section-body">
                    <h4 class="fw-bold text-dark mb-3"><?= e($proposal['title']) ?></h4>

                    <div class="mb-3">
                        <strong class="small text-muted text-uppercase d-block mb-1">Specific Objectives:</strong>
                        <div class="bg-light p-3 rounded text-dark" style="line-height: 1.6;">
                            <?= render_rich_text($proposal['objectives']) ?>
                        </div>
                    </div>

                    <?php if (!empty($proposal['technical_program'])): ?>
                    <div class="mb-3">
                        <strong class="small text-muted text-uppercase d-block mb-1">Technical Program Proposed (objective wise, also indicate the role of Co Pis):</strong>
                        <div class="bg-light p-3 rounded text-secondary" style="line-height: 1.6; white-space: pre-wrap;">
                            <?= e($proposal['technical_program']) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <strong class="small text-muted text-uppercase d-block mb-1">Budget Justification:</strong>
                        <p class="text-secondary small"><?= nl2br(e($proposal['budget_justification'] ?: 'None specified')) ?></p>
                    </div>
                </div>
            </div>

            <!-- Background & Gap Analysis -->
            <div class="detail-section-card mb-4">
                <div class="detail-section-header">
                    <i class="bi bi-lightbulb text-primary"></i> Background & Gap Analysis
                </div>
                <div class="detail-section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong class="small text-muted text-uppercase d-block">Research Problem:</strong>
                            <div class="small text-dark"><?= render_rich_text($proposal['research_problem'] ?: 'Not provided') ?></div>
                        </div>
                        <div class="col-md-6">
                            <strong class="small text-muted text-uppercase d-block">Baseline Information:</strong>
                            <div class="small text-dark"><?= render_rich_text($proposal['baseline_info'] ?: 'Not provided') ?></div>
                        </div>
                        <div class="col-md-6">
                            <strong class="small text-muted text-uppercase d-block">Novelty & Gap Analysis:</strong>
                            <div class="small text-dark"><?= render_rich_text($proposal['novelty_gap_analysis'] ?: 'Not provided') ?></div>
                        </div>
                        <div class="col-md-6">
                            <strong class="small text-muted text-uppercase d-block">Target Beneficiaries & End Users:</strong>
                            <div class="small text-dark"><?= render_rich_text($proposal['justification_end_users'] ?: 'Not provided') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="detail-section-card">
            <div class="detail-section-header">
                <i class="bi bi-person-badge text-primary"></i> Investigator & Division
            </div>
            <div class="detail-section-body">
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Proposal Category:</span>
                        <?php if ($isOngoingProposal): ?>
                            <span class="badge bg-primary text-white">
                                <i class="bi bi-arrow-repeat me-1"></i> On Going Progress Report
                            </span>
                            <div class="small fw-semibold text-primary mt-1">
                                Cycle: <?= e($proposal['progress_report_period'] ?: 'Progress Report') ?>
                            </div>
                            <?php if (!empty($proposal['project_number'])): ?>
                                <div class="font-monospace small text-dark mt-1">
                                    Project Code: <strong><?= e($proposal['project_number']) ?></strong>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($isCompletedProposal): ?>
                            <span class="badge bg-success text-white">
                                <i class="bi bi-journal-check me-1"></i> Completed Project Dossier
                            </span>
                            <?php if (!empty($proposal['project_number'])): ?>
                                <div class="font-monospace small text-dark mt-1">
                                    Project Code: <strong><?= e($proposal['project_number']) ?></strong>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                <i class="bi bi-file-earmark-plus me-1"></i> New Proposal
                            </span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Project Type:</span>
                        <?php if (($proposal['project_type'] ?? '') === 'funding_agency'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-bank2 me-1"></i> Externally Funded Project
                            </span>
                            <?php if (!empty($proposal['funding_agency'])): ?>
                                <div class="small fw-semibold text-dark mt-1"><?= e($proposal['funding_agency']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-light text-secondary border">
                                <i class="bi bi-house-door-fill me-1"></i> In-House Project
                            </span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Lead Scientist / PI:</span>
                        <strong class="text-dark"><?= e($proposal['scientist_name']) ?></strong> (<?= e($proposal['scientist_designation']) ?>)
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Division:</span>
                        <strong><?= e($proposal['department_name']) ?></strong>
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Project Duration:</span>
                        <div class="fw-semibold text-dark mt-1">
                            <?= e($proposal['proposed_start_date'] ?: 'Not set') ?> to <?= e($proposal['proposed_end_date'] ?: 'Not set') ?>
                        </div>
                    </li>
                    <?php if ($isCompletedProposal || $isOngoingProposal): ?>
                        <li class="list-group-item px-0 py-2">
                            <span class="text-muted d-block">Budget Allocated:</span>
                            <h5 class="fw-bold text-primary m-0"><?= format_currency((float)($proposal['budget_allocated'] ?: $proposal['proposed_budget'])) ?></h5>
                        </li>
                        <li class="list-group-item px-0 py-2">
                            <span class="text-muted d-block">Budget Utilized:</span>
                            <h5 class="fw-bold text-success m-0"><?= format_currency((float)$proposal['budget_utilized']) ?></h5>
                        </li>
                    <?php else: ?>
                        <li class="list-group-item px-0 py-2">
                            <span class="text-muted d-block">Proposed Budget:</span>
                            <h4 class="fw-bold text-primary m-0"><?= format_currency((float)$proposal['proposed_budget']) ?></h4>
                        </li>
                    <?php endif; ?>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Institute Priority:</span>
                        <div class="fw-semibold text-dark mt-1"><?= e(get_priority_area_title($proposal['institute_priority_area'] ?? '')) ?></div>
                    </li>
                    <?php if (!empty($proposal['national_priority_area'])): ?>
                        <li class="list-group-item px-0 py-2">
                            <span class="text-muted d-block">National Priority:</span>
                            <span class="text-dark fw-semibold"><?= e($proposal['national_priority_area']) ?></span>
                        </li>
                    <?php endif; ?>
                    <li class="list-group-item px-0 py-2">
                        <span class="text-muted d-block">Technology Readiness:</span>
                        <span class="badge bg-info-subtle text-dark border">TRL-<?= e($proposal['trl_level']) ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function setSuggestedId(val) {
    const input = document.getElementById('project_number');
    if (input) {
        input.value = val;
        checkProjectIdUnique();
    }
}

async function checkProjectIdUnique() {
    const input = document.getElementById('project_number');
    const feedback = document.getElementById('project-id-feedback');
    const btn = document.getElementById('btn-check-unique');
    if (!input || !feedback) return;

    const val = input.value.trim();
    if (!val) {
        feedback.style.display = 'block';
        feedback.className = 'small mt-2 text-danger fw-semibold';
        feedback.innerHTML = '<i class="bi bi-x-circle me-1"></i> Please enter a Project ID to verify.';
        return;
    }

    if (btn) btn.disabled = true;
    feedback.style.display = 'block';
    feedback.className = 'small mt-2 text-muted';
    feedback.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Checking institutional registry uniqueness...';

    try {
        const resp = await fetch('<?= url("/joint-director/irc-decision.php?id={$id}&check_unique=1") ?>&project_number=' + encodeURIComponent(val));
        const data = await resp.json();
        if (data.unique) {
            feedback.className = 'small mt-2 text-success fw-semibold';
            feedback.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> ' + data.message;
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
        } else {
            feedback.className = 'small mt-2 text-danger fw-semibold';
            feedback.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' + data.message;
            input.classList.remove('is-valid');
            input.classList.add('is-invalid');
        }
    } catch (err) {
        feedback.className = 'small mt-2 text-warning';
        feedback.innerHTML = '<i class="bi bi-info-circle me-1"></i> Could not check uniqueness right now (will be verified upon submission).';
    } finally {
        if (btn) btn.disabled = false;
    }
}

// Auto-check on typing (debounced)
let typingTimer;
document.getElementById('project_number')?.addEventListener('input', function() {
    clearTimeout(typingTimer);
    typingTimer = setTimeout(checkProjectIdUnique, 500);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

