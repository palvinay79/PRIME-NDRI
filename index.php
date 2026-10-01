<?php
/**
 * Research Proposal and Project Management System
 * Root Dispatcher
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header("Location: " . get_role_dashboard_url(current_user_role_id()));
    exit;
} else {
    redirect('/login.php');
}
