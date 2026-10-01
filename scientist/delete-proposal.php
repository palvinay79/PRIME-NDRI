<?php
/**
 * Research Proposal and Project Management System
 * Scientist - Delete Proposal / Draft / Progress Report Submission
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD, ROLE_JOINT_DIRECTOR]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . url("/scientist/proposals.php"));
    exit;
}

require_csrf();

$proposalId = isset($_POST['proposal_id']) ? (int)$_POST['proposal_id'] : 0;
$userId = current_user_id();
$userRole = current_user_role_id();
$db = get_db();

$stmt = $db->prepare("SELECT p.*, pr.project_number as linked_prj_num
                      FROM proposals p
                      LEFT JOIN projects pr ON p.linked_project_id = pr.id
                      WHERE p.id = ?");
$stmt->execute([$proposalId]);
$proposal = $stmt->fetch();

if (!$proposal) {
    flash('danger', 'Proposal not found.');
    header("Location: " . url("/scientist/proposals.php"));
    exit;
}

// Access check: Only the scientist who owns it, or JD if applicable
if ($userRole === ROLE_SCIENTIST && (int)$proposal['scientist_id'] !== $userId) {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}

// Cannot delete if not allowed by workflow permissions
if (!can_delete_proposal($proposal, $userId)) {
    if (in_array($proposal['current_status'], [STATUS_FORWARDED_JD, STATUS_APPROVED_IRC, STATUS_PENDING_IRC], true)) {
        flash('danger', "Cannot delete proposal '{$proposal['proposal_number']}': HOD has already forwarded this proposal to the Joint Director. Proposals can only be deleted before HOD takes action.");
    } elseif ($proposal['current_status'] === STATUS_APPROVED_ACTIVE || $proposal['current_status'] === STATUS_COMPLETED) {
        flash('danger', "Active or completed research projects cannot be deleted.");
    } else {
        flash('danger', "You do not have permission to delete this proposal in its current status ('" . e($proposal['current_status']) . "').");
    }
    header("Location: " . url("/scientist/proposals.php"));
    exit;
}

$cat = $proposal['proposal_category'] ?? 'new';
$propNum = $proposal['proposal_number'];
$title = $proposal['title'];

try {
    $db->beginTransaction();

    // If ongoing progress report proposal, delete from progress_reports table too
    if ($cat === 'ongoing') {
        $period = $proposal['progress_report_period'] ?? '';
        $prjId = (int)($proposal['linked_project_id'] ?? 0);

        if ($prjId > 0) {
            $delRepStmt = $db->prepare("DELETE FROM progress_reports 
                                        WHERE project_id = ? 
                                          AND (report_period = ? OR reporting_period = ? OR report_period LIKE ?)");
            $delRepStmt->execute([$prjId, $period, $period, "%{$period}%"]);
        }
    }

    // If completion report proposal, delete from completion_reports table too
    if ($cat === 'completed') {
        $prjId = (int)($proposal['linked_project_id'] ?? 0);
        if ($prjId > 0) {
            $delCompStmt = $db->prepare("DELETE FROM completion_reports WHERE project_id = ?");
            $delCompStmt->execute([$prjId]);
        }
    }

    // Delete associated relations
    $db->prepare("DELETE FROM proposal_documents WHERE proposal_id = ?")->execute([$proposalId]);
    $db->prepare("DELETE FROM proposal_comments WHERE proposal_id = ?")->execute([$proposalId]);
    $db->prepare("DELETE FROM proposal_co_pis WHERE proposal_id = ?")->execute([$proposalId]);
    $db->prepare("DELETE FROM proposal_status_history WHERE proposal_id = ?")->execute([$proposalId]);

    // Delete the proposal record
    $db->prepare("DELETE FROM proposals WHERE id = ?")->execute([$proposalId]);

    $db->commit();

    log_audit($userId, 'PROPOSAL_DELETED', 'proposals', $proposalId, "Deleted proposal {$propNum} ('{$title}', category: {$cat}).");

    $label = ($cat === 'ongoing') ? 'Progress report proposal' : (($cat === 'completed') ? 'Completion report proposal' : 'Proposal');
    flash('success', "{$label} '{$propNum}' was deleted successfully.");
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash('danger', 'An error occurred while attempting to delete the proposal: ' . $e->getMessage());
}

$redirectTab = ($cat === 'ongoing' || $cat === 'completed') ? $cat : 'all';
header("Location: " . url("/scientist/proposals.php?category={$redirectTab}"));
exit;
