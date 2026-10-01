<?php
/**
 * Research Proposal and Project Management System
 * Logout Handler
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

logout_user();
flash('info', 'You have been successfully signed out.');
redirect('/login.php');
