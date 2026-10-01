<?php
/**
 * Research Proposal and Project Management System
 * Scientist / HOD - Delete Progress Report
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

require_role([ROLE_SCIENTIST, ROLE_HOD, ROLE_JOINT_DIRECTOR]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . url("/scientist/projects.php"));
    exit;
}

require_csrf();

$reportId = isset($_POST['report_id']) ? (int)$_POST['report_id'] : 0;
$userId = current_user_id();
$userRole = current_user_role_id();
$db = get_db();

$stmt = $db->prepare("SELECT r.*, pr.project_number, COALESCE(pr.department_id, p.department_id) as department_id,
                             (SELECT p_on.current_status FROM proposals p_on 
                              WHERE p_on.linked_project_id = r.project_id 
                                AND p_on.proposal_category = 'ongoing' 
                                AND (p_on.progress_report_period = r.report_period OR p_on.progress_report_period = r.reporting_period OR p_on.progress_report_period LIKE '%' || r.report_period || '%') 
                              ORDER BY p_on.id DESC LIMIT 1) as linked_proposal_status
                      FROM progress_reports r
                      JOIN projects pr ON r.project_id = pr.id
                      JOIN proposals p ON pr.proposal_id = p.id
                      WHERE r.id = ?");
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    flash('danger', 'Report not found.');
    header("Location: " . url("/scientist/projects.php"));
    exit;
}

// Access check:
if ($userRole === ROLE_SCIENTIST && (int)$report['submitted_by'] !== $userId) {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}
if ($userRole === ROLE_HOD && (int)$report['department_id'] !== (int)current_user_department_id()) {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}

$projectId = (int)$report['project_id'];

// Check permission: Once submitted to Joint Director or approved by IRC, report cannot be deleted
if (!can_delete_progress_report($report, $userId, $userRole)) {
    flash('danger', 'This progress report has been submitted to the Joint Director / approved by IRC and cannot be deleted.');
    header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
    exit;
}

$period = $report['report_period'] ?? $report['reporting_period'] ?? 'N/A';

// 1. Delete comments on the progress report
try {
    $db->prepare("DELETE FROM progress_report_comments WHERE progress_report_id = ?")->execute([$reportId]);
} catch (Throwable $t) {}

// 2. Delete the progress report record
$delStmt = $db->prepare("DELETE FROM progress_reports WHERE id = ?");
$delStmt->execute([$reportId]);

// 3. Synchronously find and delete corresponding proposal record in proposals table
try {
    $findPropStmt = $db->prepare("SELECT id FROM proposals 
                                  WHERE proposal_category = 'ongoing' 
                                    AND linked_project_id = ? 
                                    AND (progress_report_period = ? OR progress_report_period = ? OR progress_report_period LIKE ?)");
    $findPropStmt->execute([$projectId, $period, ($report['reporting_period'] ?? $period), "%{$period}%"]);
    $linkedPropIds = $findPropStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($linkedPropIds as $pId) {
        $pId = (int)$pId;
        $db->prepare("DELETE FROM proposal_documents WHERE proposal_id = ?")->execute([$pId]);
        $db->prepare("DELETE FROM proposal_comments WHERE proposal_id = ?")->execute([$pId]);
        $db->prepare("DELETE FROM proposal_co_pis WHERE proposal_id = ?")->execute([$pId]);
        $db->prepare("DELETE FROM proposal_status_history WHERE proposal_id = ?")->execute([$pId]);
        $db->prepare("DELETE FROM proposals WHERE id = ?")->execute([$pId]);
        log_audit($userId, 'ONGOING_PROPOSAL_DELETED', 'proposals', $pId, "Deleted ongoing proposal ID {$pId} corresponding to deleted progress report period '{$period}'.");
    }
} catch (Throwable $t) {}

log_audit($userId, 'PROGRESS_REPORT_DELETED', 'projects', $projectId, "Progress report for period '{$period}' was deleted from project {$report['project_number']}.");

flash('success', "Progress report for '{$period}' was removed successfully.");
header("Location: " . url("/scientist/view-progress.php?project_id={$projectId}"));
exit;
