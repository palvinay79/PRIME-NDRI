<?php
/**
 * Research Proposal and Project Management System
 * System Constants and Configuration
 */

// Application Info
if (!defined('APP_NAME')) {
    define('APP_NAME', 'NDRI PRIME');
}
if (!defined('APP_SHORT_NAME')) {
    define('APP_SHORT_NAME', 'NDRI PRIME');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}

// =========================================================================
// Base URL Configuration
// -------------------------------------------------------------------------
// If your application is located in a subfolder (e.g. /demo/pme/):
// Change $subfolder below to your subdirectory or leave '' for root domain.
// =========================================================================
if (!defined('BASE_URL')) {
    // Configure your subfolder here:
    $subfolder = '/demo/pme';

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    $isLocalDev = (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || str_contains($host, 'run.app'));

    if ($isLocalDev && !str_contains($uri, '/demo/pme')) {
        // Automatic fallback for local container preview
        define('BASE_URL', '');
    } elseif (!empty($subfolder)) {
        define('BASE_URL', rtrim($subfolder, '/'));
    } else {
        // Auto-detect base folder from SCRIPT_NAME
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($scriptPath));
        $dir = preg_replace('#/(scientist|hod|joint-director|config|includes|assets)$#i', '', $dir);
        define('BASE_URL', ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/'));
    }
}

// User Roles
if (!defined('ROLE_SCIENTIST')) {
    define('ROLE_SCIENTIST', 1);
}
if (!defined('ROLE_HOD')) {
    define('ROLE_HOD', 2);
}
if (!defined('ROLE_JOINT_DIRECTOR')) {
    define('ROLE_JOINT_DIRECTOR', 3);
}

// 11 Core Proposal Statuses
if (!defined('STATUS_DRAFT')) {
    define('STATUS_DRAFT', 'Draft');
}
if (!defined('STATUS_SUBMITTED_HOD')) {
    define('STATUS_SUBMITTED_HOD', 'Submitted to HOD');
}
if (!defined('STATUS_RETURNED_HOD')) {
    define('STATUS_RETURNED_HOD', 'Returned by HOD');
}
if (!defined('STATUS_FORWARDED_JD')) {
    define('STATUS_FORWARDED_JD', 'Forwarded to Joint Director');
}
if (!defined('STATUS_RETURNED_JD')) {
    define('STATUS_RETURNED_JD', 'Returned by Joint Director');
}
if (!defined('STATUS_APPROVED_IRC')) {
    define('STATUS_APPROVED_IRC', 'Approved for IRC Meeting');
}
if (!defined('STATUS_PENDING_IRC')) {
    define('STATUS_PENDING_IRC', 'Pending IRC Decision');
}
if (!defined('STATUS_APPROVED_ACTIVE')) {
    define('STATUS_APPROVED_ACTIVE', 'Approved / Project Active');
}
if (!defined('STATUS_NOT_APPROVED_ARCHIVED')) {
    define('STATUS_NOT_APPROVED_ARCHIVED', 'Not Approved / Archived');
}
if (!defined('STATUS_COMPLETION_SUBMITTED')) {
    define('STATUS_COMPLETION_SUBMITTED', 'Completion Report Submitted');
}
if (!defined('STATUS_COMPLETED')) {
    define('STATUS_COMPLETED', 'Completed');
}

// Project Statuses
if (!defined('PROJECT_STATUS_ACTIVE')) {
    define('PROJECT_STATUS_ACTIVE', 'Active');
}
if (!defined('PROJECT_STATUS_COMPLETED')) {
    define('PROJECT_STATUS_COMPLETED', 'Completed');
}

// File Upload Settings
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', __DIR__ . '/../uploads/');
}
if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
}

// Allowed MIME Types & Extensions
$ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'png', 'jpg'];

// Project Categories
define('PROJECT_TYPE_IN_HOUSE', 'in_house');
define('PROJECT_TYPE_FUNDING_AGENCY', 'funding_agency');

// Priority Areas (Institute & National)
// Institute Priority Area is strictly Program A to F with full descriptions (Mandatory)
$INSTITUTE_PRIORITY_PROGRAMS = [
    'A' => 'Program A : Translational Basic, strategic and policy research in dairying for ViksitBharat.',
    'B' => 'Program B : Production, multiplication and dissemination of elite dairy germplasm through cutting edge biotechnological tools and computational approaches.',
    'C' => 'Program C : Climate resilient smart dairy production and processing.',
    'D' => 'Program D : Driving innovation in functional foods, biologicals, and sustainable dairy solutions for a circular economy.',
    'E' => 'Program E : Research-driven implementation of the One Health approach to advance animal health, ensure food safety, and enhance quality milk production.',
    'F' => 'Program F : Advancing demand-driven innovations through systematic technologyassessment, refinement, dissemination, and capacity building for dairysector stakeholders.'
];

$INSTITUTE_PRIORITY_AREAS = [
    'A',
    'B',
    'C',
    'D',
    'E',
    'F'
];

$NATIONAL_PRIORITY_AREAS = [
    'National Dairy Plan / Rashtriya Gokul Mission',
    'Sustainable Livestock Production',
    'Greenhouse Gas Mitigation in Ruminants',
    'Import Substitution & Export Quality Dairy Products',
    'Farmer Livelihood & Women Empowerment in Dairy'
];
