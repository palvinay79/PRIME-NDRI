<?php
/**
 * Research Proposal and Project Management System
 * Role switcher disabled for real testing environment.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

flash('warning', 'Direct demo role switching has been disabled. Please log in with your account credentials.');
header("Location: " . url('/login.php'));
exit;
