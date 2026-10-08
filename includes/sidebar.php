<?php
/**
 * Research Proposal and Project Management System
 * Role-Aware Contextual Sidebar
 */

$roleId = current_user_role_id();
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';

$pendingRegCount = 0;
$scientistFeedbackCount = 0;

if ($roleId === ROLE_JOINT_DIRECTOR) {
    try {
        $pendingRegCount = (int)get_db()->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    } catch (Exception $e) {}
}

if ($roleId === ROLE_SCIENTIST) {
    try {
        $fbCheck = get_db()->prepare("SELECT COUNT(*) FROM progress_reports r
                                      JOIN projects pr ON r.project_id = pr.id
                                      WHERE (pr.scientist_id = ? OR r.submitted_by = ?)
                                        AND (r.feedback_viewed_by_scientist = 0 OR r.feedback_viewed_by_scientist IS NULL)
                                        AND ((r.reviewer_comments IS NOT NULL AND TRIM(r.reviewer_comments) != '') OR r.review_status IN ('Reviewed', 'Approved / Accepted', 'Needs Revision'))");
        $fbCheck->execute([$currentUser['id'], $currentUser['id']]);
        $scientistFeedbackCount = (int)$fbCheck->fetchColumn();
    } catch (Exception $e) {}
}
?>
<!-- Desktop Sidebar -->
<aside class="app-sidebar d-none d-md-flex">
    <div class="sidebar-user-block">
        <div class="fw-bold text-dark"><?= e($currentUser['name']) ?></div>
        <div class="text-muted small"><?= e($currentUser['designation'] ?? '') ?></div>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle mt-1">
            <?= e($currentUser['role_name'] ?? ($roleId == ROLE_SCIENTIST ? 'Scientist' : ($roleId == ROLE_HOD ? 'Head of Department' : 'Joint Director'))) ?>
        </span>
    </div>

    <div class="sidebar-nav">
        <?php if ($roleId === ROLE_SCIENTIST): ?>
            <div class="sidebar-heading">Scientist Portal</div>
            <a href="<?= url('/scientist/dashboard.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'scientist/dashboard') ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="<?= url('/scientist/create-proposal.php') ?>" class="nav-link-custom <?= (str_contains($currentScript, 'create-proposal') && !str_contains($currentScript, 'completed')) ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-plus"></i> New Proposal
            </a>
            <a href="<?= url('/scientist/ongoing-projects.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'ongoing-projects') ? 'active' : '' ?>">
                <i class="bi bi-arrow-repeat"></i> On Going Projects
            </a>
            <a href="<?= url('/scientist/create-completed-proposal.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'create-completed-proposal') ? 'active' : '' ?>">
                <i class="bi bi-check2-circle"></i> Completion Project
            </a>
            <a href="<?= url('/scientist/proposals.php') ?>" class="nav-link-custom <?= (str_contains($currentScript, 'scientist/proposals') || str_contains($currentScript, 'proposal-details')) ? 'active' : '' ?>">
                <i class="bi bi-folder2-open"></i> My Proposals
            </a>
            <a href="<?= url('/scientist/projects.php') ?>" class="nav-link-custom d-flex justify-content-between align-items-center <?= str_contains($currentScript, 'scientist/projects') ? 'active' : '' ?>">
                <span><i class="bi bi-kanban"></i> My Active Projects</span>
                <?php if ($scientistFeedbackCount > 0): ?>
                    <span class="badge bg-warning text-dark font-monospace" style="font-size: 0.68rem;" title="Reviewer feedback received">
                        <i class="bi bi-chat-quote-fill me-1"></i><?= $scientistFeedbackCount ?>
                    </span>
                <?php endif; ?>
            </a>

        <?php elseif ($roleId === ROLE_HOD): ?>
            <div class="sidebar-heading">Head of Department</div>
            <a href="<?= url('/hod/dashboard.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'hod/dashboard') ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> HOD Dashboard
            </a>
            <a href="<?= url('/hod/proposals.php') ?>" class="nav-link-custom <?= (str_contains($currentScript, 'hod/proposals') || str_contains($currentScript, 'review-proposal')) ? 'active' : '' ?>">
                <i class="bi bi-inbox"></i> Review Proposals
            </a>
            <a href="<?= url('/hod/projects.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'hod/projects') ? 'active' : '' ?>">
                <i class="bi bi-kanban"></i> Department Projects
            </a>

            <div class="sidebar-heading">HOD Research Submissions</div>
            <a href="<?= url('/scientist/create-proposal.php') ?>" class="nav-link-custom <?= (str_contains($currentScript, 'create-proposal') && !str_contains($currentScript, 'completed')) ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-plus"></i> New Proposal
            </a>
            <a href="<?= url('/scientist/ongoing-projects.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'ongoing-projects') ? 'active' : '' ?>">
                <i class="bi bi-arrow-repeat"></i> On Going Projects
            </a>
            <a href="<?= url('/scientist/create-completed-proposal.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'create-completed-proposal') ? 'active' : '' ?>">
                <i class="bi bi-check2-circle"></i> Completion Project
            </a>
            <a href="<?= url('/scientist/proposals.php') ?>" class="nav-link-custom <?= (str_contains($currentScript, 'scientist/proposals') || str_contains($currentScript, 'proposal-details')) ? 'active' : '' ?>">
                <i class="bi bi-folder2-open"></i> My Submissions
            </a>
            <a href="<?= url('/scientist/projects.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'scientist/projects') ? 'active' : '' ?>">
                <i class="bi bi-kanban"></i> My Active Projects
            </a>

        <?php elseif ($roleId === ROLE_JOINT_DIRECTOR): ?>
            <div class="sidebar-heading">Directorate Portal</div>
            <a href="<?= url('/joint-director/dashboard.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'joint-director/dashboard') ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Executive Dashboard
            </a>
            <a href="<?= url('/joint-director/users.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'joint-director/users') ? 'active' : '' ?>">
                <i class="bi bi-people"></i> User Management
                <?php if ($pendingRegCount > 0): ?>
                    <span class="badge bg-warning text-dark ms-auto font-monospace" style="font-size: 0.72rem;"><?= $pendingRegCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('/joint-director/departments.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'joint-director/departments') ? 'active' : '' ?>">
                <i class="bi bi-buildings"></i> Divisions & Depts
            </a>
            <a href="<?= url('/joint-director/proposals.php') ?>" class="nav-link-custom <?= (str_contains($currentScript, 'joint-director/proposals') || str_contains($currentScript, 'review-proposal')) ? 'active' : '' ?>">
                <i class="bi bi-clipboard-check"></i> Proposals Review
            </a>
            <a href="<?= url('/joint-director/irc-meetings.php') ?>" class="nav-link-custom <?= (str_contains($currentScript, 'irc-meetings') || str_contains($currentScript, 'irc-decision')) ? 'active' : '' ?>">
                <i class="bi bi-calendar-event"></i> IRC Meetings & Decisions
            </a>
            <a href="<?= url('/joint-director/projects.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'joint-director/projects') ? 'active' : '' ?>">
                <i class="bi bi-kanban"></i> Active Projects
            </a>
            <a href="<?= url('/joint-director/analytics.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'joint-director/analytics') ? 'active' : '' ?>">
                <i class="bi bi-graph-up"></i> Research Analytics
            </a>
        <?php endif; ?>

        <div class="sidebar-heading">Account</div>
        <a href="<?= url('/profile.php') ?>" class="nav-link-custom <?= str_contains($currentScript, 'profile') ? 'active' : '' ?>">
            <i class="bi bi-person-gear"></i> Profile & Password
        </a>
        <a href="<?= url('/logout.php') ?>" class="nav-link-custom text-danger">
            <i class="bi bi-box-arrow-right text-danger"></i> Sign Out
        </a>
    </div>
</aside>

<!-- Mobile Offcanvas Sidebar -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
    <div class="offcanvas-header bg-dark text-white">
        <h5 class="offcanvas-title" id="mobileSidebarLabel">NDRI PRIME Menu</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div class="p-3 bg-light border-bottom">
            <div class="fw-bold"><?= e($currentUser['name']) ?></div>
            <div class="small text-muted"><?= e($currentUser['role_name'] ?? ($roleId == ROLE_SCIENTIST ? 'Scientist' : ($roleId == ROLE_HOD ? 'Head of Department' : 'Joint Director'))) ?></div>
        </div>
        <div class="p-2">
            <?php if ($roleId === ROLE_SCIENTIST): ?>
                <a href="<?= url('/scientist/dashboard.php') ?>" class="nav-link-custom"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="<?= url('/scientist/create-proposal.php') ?>" class="nav-link-custom"><i class="bi bi-file-earmark-plus"></i> New Proposal</a>
                <a href="<?= url('/scientist/ongoing-projects.php') ?>" class="nav-link-custom"><i class="bi bi-arrow-repeat"></i> On Going Projects</a>
                <a href="<?= url('/scientist/create-completed-proposal.php') ?>" class="nav-link-custom"><i class="bi bi-check2-circle"></i> Completion Project</a>
                <a href="<?= url('/scientist/proposals.php') ?>" class="nav-link-custom"><i class="bi bi-folder2-open"></i> My Proposals</a>
                <a href="<?= url('/scientist/projects.php') ?>" class="nav-link-custom d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-kanban me-2"></i>My Active Projects</span>
                    <?php if ($scientistFeedbackCount > 0): ?>
                        <span class="badge bg-warning text-dark font-monospace"><i class="bi bi-chat-quote-fill me-1"></i><?= $scientistFeedbackCount ?></span>
                    <?php endif; ?>
                </a>
            <?php elseif ($roleId === ROLE_HOD): ?>
                <a href="<?= url('/hod/dashboard.php') ?>" class="nav-link-custom"><i class="bi bi-speedometer2"></i> HOD Dashboard</a>
                <a href="<?= url('/hod/proposals.php') ?>" class="nav-link-custom"><i class="bi bi-inbox"></i> Review Proposals</a>
                <a href="<?= url('/hod/projects.php') ?>" class="nav-link-custom"><i class="bi bi-kanban"></i> Department Projects</a>
                <div class="border-top my-2 pt-2 small text-uppercase text-muted px-3 fw-bold" style="font-size: 0.7rem;">HOD Research Submissions</div>
                <a href="<?= url('/scientist/create-proposal.php') ?>" class="nav-link-custom"><i class="bi bi-file-earmark-plus"></i> New Proposal</a>
                <a href="<?= url('/scientist/ongoing-projects.php') ?>" class="nav-link-custom"><i class="bi bi-arrow-repeat"></i> On Going Projects</a>
                <a href="<?= url('/scientist/create-completed-proposal.php') ?>" class="nav-link-custom"><i class="bi bi-check2-circle"></i> Completion Project</a>
                <a href="<?= url('/scientist/proposals.php') ?>" class="nav-link-custom"><i class="bi bi-folder2-open"></i> My Submissions</a>
                <a href="<?= url('/scientist/projects.php') ?>" class="nav-link-custom"><i class="bi bi-kanban"></i> My Active Projects</a>
            <?php elseif ($roleId === ROLE_JOINT_DIRECTOR): ?>
                <a href="<?= url('/joint-director/dashboard.php') ?>" class="nav-link-custom"><i class="bi bi-speedometer2"></i> Executive Dashboard</a>
                <a href="<?= url('/joint-director/users.php') ?>" class="nav-link-custom d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i>User Management</span>
                    <?php if ($pendingRegCount > 0): ?>
                        <span class="badge bg-warning text-dark font-monospace"><?= $pendingRegCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= url('/joint-director/departments.php') ?>" class="nav-link-custom"><i class="bi bi-buildings"></i> Divisions & Depts</a>
                <a href="<?= url('/joint-director/proposals.php') ?>" class="nav-link-custom"><i class="bi bi-clipboard-check"></i> Proposals Review</a>
                <a href="<?= url('/joint-director/irc-meetings.php') ?>" class="nav-link-custom"><i class="bi bi-calendar-event"></i> IRC Meetings & Decisions</a>
                <a href="<?= url('/joint-director/projects.php') ?>" class="nav-link-custom"><i class="bi bi-kanban"></i> Active Projects</a>
                <a href="<?= url('/joint-director/analytics.php') ?>" class="nav-link-custom"><i class="bi bi-graph-up"></i> Research Analytics</a>
            <?php endif; ?>
            <hr>
            <a href="<?= url('/profile.php') ?>" class="nav-link-custom"><i class="bi bi-person-gear"></i> Profile</a>
            <a href="<?= url('/logout.php') ?>" class="nav-link-custom text-danger"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </div>
    </div>
</div>
