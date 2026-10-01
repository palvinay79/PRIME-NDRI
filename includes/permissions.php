<?php
/**
 * Research Proposal and Project Management System
 * Business Rules and Workflow Permission Enforcement
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/auth.php';

/**
 * Rule 1: Can the user edit this proposal?
 * - Scientist who created the proposal can edit if status is 'Draft', 'Submitted to HOD' (until approved/rejected by HOD),
 *   'Returned by HOD', or 'Returned by Joint Director'.
 * - Once approved by HOD (forwarded to Joint Director), final IRC decision, or archived, scientist editing is locked.
 */
function can_edit_proposal(array $proposal, ?int $userId = null): bool {
    $userId = $userId ?? current_user_id();
    if (!$userId) return false;

    // Must be the proposal owner
    if ((int)$proposal['scientist_id'] !== (int)$userId) {
        return false;
    }

    // Editable in Draft, Submitted to HOD (prior to HOD action), or Returned states
    $editableStatuses = [
        STATUS_DRAFT,
        STATUS_SUBMITTED_HOD,
        STATUS_RETURNED_HOD,
        STATUS_RETURNED_JD
    ];

    if (!in_array($proposal['current_status'], $editableStatuses, true)) {
        return false;
    }

    // If ongoing progress report proposal:
    // Once HOD submits to Joint Director or once approved by IRC / ICR, scientist cannot edit
    if (($proposal['proposal_category'] ?? '') === 'ongoing') {
        if (in_array($proposal['current_status'], [
            STATUS_FORWARDED_JD,
            STATUS_APPROVED_IRC,
            STATUS_APPROVED_IRC_MEETING,
            STATUS_PENDING_IRC,
            STATUS_APPROVED_ACTIVE,
            STATUS_COMPLETED
        ], true)) {
            return false;
        }

        $period = $proposal['progress_report_period'] ?? '';
        $prjId = (int)($proposal['linked_project_id'] ?? 0);
        if ($prjId > 0 && !empty($period)) {
            try {
                $db = get_db();
                $stmt = $db->prepare("SELECT * FROM progress_reports WHERE project_id = ? AND (report_period = ? OR reporting_period = ? OR report_period LIKE ?)");
                $stmt->execute([$prjId, $period, $period, '%' . $period . '%']);
                $rep = $stmt->fetch();
                if ($rep && (is_report_submitted_to_jd($rep) || is_report_approved_by_irc($rep))) {
                    return false;
                }
            } catch (Throwable $e) {}
        }
    }

    return true;
}

/**
 * Rule: Can the user delete this proposal or progress report submission?
 * - Scientist who created the proposal can delete until HOD takes action:
 *   strictly allowed while in Draft or Submitted to HOD.
 *   Once HOD takes action (forwarded to Joint Director, returned, or IRC approved),
 *   the scientist is NOT able to delete the proposal.
 * - Active approved projects and completed projects cannot be deleted.
 */
function can_delete_proposal(array $proposal, ?int $userId = null): bool {
    $userId = $userId ?? current_user_id();
    if (!$userId) return false;

    $isOwner = ((int)($proposal['scientist_id'] ?? 0) === (int)$userId);
    $userRole = current_user_role_id();

    // Must be either the owner scientist or Joint Director
    if (!$isOwner && $userRole !== ROLE_JOINT_DIRECTOR) {
        return false;
    }

    // Never allow deleting active running projects or completed projects
    if (in_array($proposal['current_status'] ?? '', [STATUS_APPROVED_ACTIVE, STATUS_COMPLETED], true)) {
        return false;
    }

    // For Scientist (owner): can delete ONLY until HOD takes action.
    // Allowed statuses: Draft, Submitted to HOD.
    // Once HOD forwards to Joint Director (STATUS_FORWARDED_JD) or takes any action, deletion is prohibited.
    if ($userRole === ROLE_SCIENTIST || ($isOwner && $userRole !== ROLE_JOINT_DIRECTOR)) {
        $scientistDeletable = [
            STATUS_DRAFT,
            STATUS_SUBMITTED_HOD,
        ];
        if (!in_array($proposal['current_status'] ?? '', $scientistDeletable, true)) {
            return false;
        }

        // If ongoing progress report proposal:
        // Once HOD submits to Joint Director or once approved by IRC / ICR, cannot delete
        if (($proposal['proposal_category'] ?? '') === 'ongoing') {
            $period = $proposal['progress_report_period'] ?? '';
            $prjId = (int)($proposal['linked_project_id'] ?? 0);
            if ($prjId > 0 && !empty($period)) {
                try {
                    $db = get_db();
                    $stmt = $db->prepare("SELECT * FROM progress_reports WHERE project_id = ? AND (report_period = ? OR reporting_period = ? OR report_period LIKE ?)");
                    $stmt->execute([$prjId, $period, $period, '%' . $period . '%']);
                    $rep = $stmt->fetch();
                    if ($rep && (is_report_submitted_to_jd($rep) || is_report_approved_by_irc($rep))) {
                        return false;
                    }
                } catch (Throwable $e) {}
            }
        }

        return true;
    }

    // Administrative roles (Joint Director)
    $adminDeletable = [
        STATUS_DRAFT,
        STATUS_SUBMITTED_HOD,
        STATUS_RETURNED_HOD,
        STATUS_RETURNED_JD,
        STATUS_NOT_APPROVED_ARCHIVED,
    ];

    return in_array($proposal['current_status'] ?? '', $adminDeletable, true);
}

/**
 * Rule: Check if HOD has submitted / forwarded this progress report to the Joint Director
 * - review_status in progress_reports is 'Forwarded to Joint Director', 'Submitted to Joint Director',
 *   'Forwarded to JD', 'Submitted to JD', 'Reviewed', 'Approved for IRC', 'Approved for IRC Meeting',
 *   'Approved', 'Approved / Accepted', 'Approved by IRC', 'Approved by ICR', etc.
 * - OR reviewer is HOD / JD and review_status is not 'Needs Revision'
 * - OR the linked proposal in proposals table has been forwarded to Joint Director or beyond
 *   (e.g., STATUS_FORWARDED_JD, STATUS_APPROVED_IRC_MEETING, STATUS_APPROVED_IRC, STATUS_APPROVED_ACTIVE, STATUS_COMPLETED)
 */
function is_report_submitted_to_jd(array $report): bool {
    $status = trim($report['review_status'] ?? '');

    $submittedToJdStatuses = [
        'Forwarded to Joint Director',
        'Submitted to Joint Director',
        'Forwarded to JD',
        'Submitted to JD',
        'With Joint Director',
        'Reviewed',
        'Approved for IRC Meeting',
        'Approved for IRC',
        'Approved',
        'Approved / Accepted',
        'Approved by IRC',
        'Approved by ICR',
        'IRC Approved',
        'ICR Approved',
        'Approved / Project Active',
        'Completed'
    ];
    if (in_array($status, $submittedToJdStatuses, true)) {
        return true;
    }

    if (!empty($status) && (
        stripos($status, 'Joint Director') !== false ||
        stripos($status, 'Approved') !== false ||
        stripos($status, 'IRC') !== false ||
        stripos($status, 'ICR') !== false ||
        stripos($status, 'Reviewed') !== false ||
        stripos($status, 'Forward') !== false
    )) {
        if (stripos($status, 'Needs Revision') === false && stripos($status, 'Draft') === false) {
            return true;
        }
    }

    // If review record exists from HOD or JD and not 'Needs Revision' or 'Draft'
    if (!empty($report['reviewed_by']) || !empty($report['reviewer_role'])) {
        $role = trim($report['reviewer_role'] ?? '');
        if (in_array($role, ['Head of Department', 'HOD', 'Joint Director', 'JD'], true)) {
            if ($status !== 'Needs Revision' && $status !== 'Draft' && $status !== 'Submitted') {
                return true;
            }
        }
    }

    // Check linked proposal status if present in $report
    $propStatus = $report['linked_proposal_status'] ?? ($report['proposal_status'] ?? ($report['current_status'] ?? ''));
    if (!empty($propStatus)) {
        $nonJdStatuses = [STATUS_DRAFT, STATUS_SUBMITTED_HOD, STATUS_RETURNED_HOD];
        if (!in_array($propStatus, $nonJdStatuses, true)) {
            return true;
        }
    }

    // Check linked proposal in database if report project ID is available
    $period = $report['report_period'] ?? $report['reporting_period'] ?? '';
    $projectId = (int)($report['project_id'] ?? 0);
    if ($projectId > 0) {
        try {
            $db = get_db();
            if (!empty($period)) {
                $chkStmt = $db->prepare("SELECT current_status FROM proposals 
                                         WHERE linked_project_id = ? 
                                           AND (progress_report_period = ? OR progress_report_period LIKE ? OR ? LIKE '%' || progress_report_period || '%') 
                                         ORDER BY id DESC LIMIT 1");
                $chkStmt->execute([$projectId, $period, '%' . $period . '%', $period]);
                $foundProp = $chkStmt->fetch();
                if ($foundProp) {
                    $cur = $foundProp['current_status'];
                    $nonJdStatuses = [STATUS_DRAFT, STATUS_SUBMITTED_HOD, STATUS_RETURNED_HOD];
                    if (!in_array($cur, $nonJdStatuses, true)) {
                        return true;
                    }
                }
            }

            // Also check if any ongoing proposal for this project is forwarded to JD or approved
            $chkStmt2 = $db->prepare("SELECT current_status FROM proposals 
                                      WHERE linked_project_id = ? 
                                        AND proposal_category = 'ongoing'
                                        AND current_status IN ('" . STATUS_FORWARDED_JD . "', '" . STATUS_APPROVED_IRC . "', '" . STATUS_APPROVED_IRC_MEETING . "', '" . STATUS_PENDING_IRC . "', '" . STATUS_APPROVED_ACTIVE . "', '" . STATUS_COMPLETED . "')
                                      LIMIT 1");
            $chkStmt2->execute([$projectId]);
            if ($chkStmt2->fetch()) {
                if (!empty($status) && $status !== 'Draft' && $status !== 'Submitted' && $status !== 'Needs Revision') {
                    return true;
                }
            }
        } catch (Throwable $e) {}
    }

    return false;
}

/**
 * Rule: Check if a progress report has been approved by IRC / ICR
 * - review_status is 'Approved', 'Approved / Accepted', 'Approved by IRC', 'Approved by ICR', etc.
 * - OR reviewer is Joint Director and review_status is 'Approved' / 'Reviewed'
 * - OR the linked proposal in proposals table is approved by IRC / ICR / completed / active
 * - OR IRC decision recorded in irc_decisions
 * - OR parent project is marked Completed
 */
function is_report_approved_by_irc(array $report): bool {
    $status = trim($report['review_status'] ?? '');

    $approvedStatuses = [
        'Approved',
        'Approved / Accepted',
        'Approved by IRC',
        'Approved by ICR',
        'Approved for IRC Meeting',
        'Approved for IRC',
        'IRC Approved',
        'ICR Approved',
        'Approved / Project Active',
        'Completed'
    ];
    if (in_array($status, $approvedStatuses, true)) {
        return true;
    }

    if (!empty($status) && (
        stripos($status, 'Approved') !== false ||
        stripos($status, 'Accepted') !== false ||
        stripos($status, 'Completed') !== false ||
        (stripos($status, 'IRC') !== false && stripos($status, 'Reject') === false && stripos($status, 'Revision') === false) ||
        (stripos($status, 'ICR') !== false && stripos($status, 'Reject') === false && stripos($status, 'Revision') === false)
    )) {
        return true;
    }

    // If reviewed by Joint Director and positive
    $role = trim($report['reviewer_role'] ?? '');
    if (($role === 'Joint Director' || $role === 'JD') && in_array($status, ['Reviewed', 'Approved', 'Approved / Accepted'], true)) {
        return true;
    }

    $propStatus = $report['linked_proposal_status'] ?? ($report['proposal_status'] ?? ($report['current_status'] ?? ''));
    if (!empty($propStatus) && in_array($propStatus, [STATUS_APPROVED_IRC, STATUS_APPROVED_ACTIVE, STATUS_COMPLETED, STATUS_APPROVED_IRC_MEETING], true)) {
        return true;
    }

    // Check database
    $period = $report['report_period'] ?? $report['reporting_period'] ?? '';
    $projectId = (int)($report['project_id'] ?? 0);
    if ($projectId > 0) {
        try {
            $db = get_db();
            if (!empty($period)) {
                $chkStmt = $db->prepare("SELECT current_status FROM proposals 
                                         WHERE linked_project_id = ? 
                                           AND (progress_report_period = ? OR progress_report_period LIKE ? OR ? LIKE '%' || progress_report_period || '%') 
                                         LIMIT 1");
                $chkStmt->execute([$projectId, $period, '%' . $period . '%', $period]);
                $foundProp = $chkStmt->fetch();
                if ($foundProp && in_array($foundProp['current_status'], [STATUS_APPROVED_IRC, STATUS_APPROVED_ACTIVE, STATUS_COMPLETED, STATUS_APPROVED_IRC_MEETING], true)) {
                    return true;
                }
            }

            // Check irc_decisions
            $decStmt = $db->prepare("SELECT id FROM irc_decisions 
                                     WHERE (project_id = ? OR proposal_id IN (SELECT id FROM proposals WHERE linked_project_id = ?))
                                       AND decision IN ('Approved', 'Accepted', 'Approved / Project Active', 'Completed') 
                                     LIMIT 1");
            $decStmt->execute([$projectId, $projectId]);
            if ($decStmt->fetch()) {
                return true;
            }

            // Check parent project status
            $prjStmt = $db->prepare("SELECT project_status FROM projects WHERE id = ?");
            $prjStmt->execute([$projectId]);
            $prj = $prjStmt->fetch();
            if ($prj && $prj['project_status'] === 'Completed') {
                return true;
            }
        } catch (Throwable $e) {}
    }

    return false;
}

/**
 * Rule: Can the user edit or correct this progress report?
 * - Once HOD submits the report to Joint Director (or once approved by IRC / ICR),
 *   scientists CANNOT edit, update, or correct the report.
 * - Scientist who created/submitted the report can edit ONLY before HOD submits to Joint Director.
 * - Joint Director has administrative privileges.
 */
function can_edit_progress_report(array $report, ?int $userId = null, ?int $userRole = null): bool {
    $userId = $userId ?? current_user_id();
    $userRole = $userRole ?? current_user_role_id();
    if (!$userId) return false;

    // Once HOD submits to Joint Director or once approved by IRC / ICR, scientists cannot edit or modify
    if (is_report_submitted_to_jd($report) || is_report_approved_by_irc($report)) {
        if ($userRole === ROLE_SCIENTIST || $userRole === ROLE_HOD) {
            return false;
        }
    }

    if ($userRole === ROLE_SCIENTIST) {
        return ((int)($report['submitted_by'] ?? 0) === (int)$userId);
    }

    if ($userRole === ROLE_JOINT_DIRECTOR) {
        return true;
    }

    return false;
}

/**
 * Rule: Can the user delete this progress report?
 * - Once HOD submits the report to Joint Director (or once approved by IRC / ICR),
 *   it CANNOT be deleted by scientist or HOD.
 * - Scientist who created/submitted the report can delete ONLY before HOD submits to Joint Director.
 * - Joint Director has administrative privileges only before IRC approval.
 */
function can_delete_progress_report(array $report, ?int $userId = null, ?int $userRole = null): bool {
    $userId = $userId ?? current_user_id();
    $userRole = $userRole ?? current_user_role_id();
    if (!$userId) return false;

    // Once HOD submits to Joint Director or once approved by IRC / ICR, report cannot be deleted
    if (is_report_submitted_to_jd($report) || is_report_approved_by_irc($report)) {
        return false;
    }

    if ($userRole === ROLE_SCIENTIST) {
        return ((int)($report['submitted_by'] ?? 0) === (int)$userId);
    }

    if ($userRole === ROLE_JOINT_DIRECTOR) {
        return true;
    }

    return false;
}

/**
 * Rule 2 & 4: Can the user (HOD) review this proposal?
 * - Must have HOD role
 * - Proposal must belong to the HOD's department
 * - Proposal status must be 'Submitted to HOD'
 */
function can_hod_review_proposal(array $proposal, ?int $userId = null, ?int $deptId = null): bool {
    $roleId = current_user_role_id();
    if ($roleId !== ROLE_HOD) {
        return false;
    }

    $deptId = $deptId ?? current_user_department_id();
    if ((int)$proposal['department_id'] !== (int)$deptId) {
        return false; // HODs can only review proposals from their own department
    }

    return $proposal['current_status'] === STATUS_SUBMITTED_HOD;
}

/**
 * Rule 3 & 4: Can the Joint Director give Initial Approval for IRC Meeting?
 * - Must be Joint Director role
 * - Proposal status must be 'Forwarded to Joint Director'
 * - SPECIAL RULE: If the proposal was authored by the Joint Director himself,
 *   he CANNOT approve it if it hasn't passed HOD approval (status must already be 'Forwarded to Joint Director').
 *   Because status is already 'Forwarded to Joint Director' after HOD approval, this ensures
 *   the Joint Director could never bypass the HOD approval stage!
 * - Furthermore, prevent self-action if not properly forwarded.
 */
function can_jd_initial_review(array $proposal): bool {
    $roleId = current_user_role_id();
    if ($roleId !== ROLE_JOINT_DIRECTOR) {
        return false;
    }

    return $proposal['current_status'] === STATUS_FORWARDED_JD;
}

/**
 * Rule 5: Can the proposal receive an IRC Decision?
 * - Must have Joint Director role
 * - Proposal status MUST be 'Approved for IRC Meeting' or 'Pending IRC Decision'
 * - Under NO circumstances can a proposal receive final decision without initial IRC approval!
 */
function can_record_irc_decision(array $proposal): bool {
    $roleId = current_user_role_id();
    if ($roleId !== ROLE_JOINT_DIRECTOR) {
        return false;
    }

    return in_array($proposal['current_status'], [STATUS_APPROVED_IRC, STATUS_PENDING_IRC], true);
}

/**
 * Rule 8 & 9: Is this proposal or project permanently view-only (archived / completed)?
 */
function is_permanently_locked(array $proposal): bool {
    return in_array($proposal['current_status'], [
        STATUS_NOT_APPROVED_ARCHIVED,
        STATUS_COMPLETED
    ], true);
}

/**
 * Can user submit an ongoing progress report?
 * - Only the assigned Scientist of an 'Approved / Project Active' project
 */
function can_submit_progress_report(array $project, ?int $userId = null): bool {
    $userId = $userId ?? current_user_id();
    if (!$userId) return false;

    if ((int)$project['scientist_id'] !== (int)$userId) {
        return false;
    }

    return $project['project_status'] === PROJECT_STATUS_ACTIVE;
}

/**
 * Can user submit completion report?
 * - Only the assigned Scientist of an 'Approved / Project Active' project
 */
function can_submit_completion_report(array $project, ?int $userId = null): bool {
    $userId = $userId ?? current_user_id();
    if (!$userId) return false;

    if ((int)$project['scientist_id'] !== (int)$userId) {
        return false;
    }

    return $project['project_status'] === PROJECT_STATUS_ACTIVE;
}

/**
 * Can user view proposal details?
 * - Scientist can view their own proposals
 * - HOD can view proposals in their department
 * - Joint Director can view all proposals across the institute
 */
function can_view_proposal(array $proposal): bool {
    $roleId = current_user_role_id();
    $userId = current_user_id();
    $deptId = current_user_department_id();

    if ($roleId === ROLE_JOINT_DIRECTOR) {
        return true;
    }

    if ($roleId === ROLE_HOD) {
        return (int)$proposal['department_id'] === (int)$deptId;
    }

    if ($roleId === ROLE_SCIENTIST) {
        return (int)$proposal['scientist_id'] === (int)$userId;
    }

    return false;
}

/**
 * Check if user has permission to view audit logs of all roles:
 * - Only the Joint Director can view system-wide audit logs across all roles
 * - HODs and Scientists can only view their own respective audit logs
 */
function can_view_all_audit_logs(): bool {
    return current_user_role_id() === ROLE_JOINT_DIRECTOR;
}
