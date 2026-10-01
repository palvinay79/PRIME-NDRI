<?php
/**
 * Research Proposal and Project Management System
 * Helper and Utility Functions
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate an application URL taking BASE_URL into account
 */
if (!function_exists('url')) {
    function url(string $path = ''): string {
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        if ($path === '' || $path === '/') {
            return $base !== '' ? $base . '/' : '/';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return $path;
        }
        return ($base !== '' ? $base : '') . '/' . ltrim($path, '/');
    }
}

/**
 * Redirect to an application URL and exit
 */
if (!function_exists('redirect')) {
    function redirect(string $path): void {
        header("Location: " . url($path));
        exit;
    }
}

/**
 * XSS Escape helper
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize string input
 */
function sanitize(?string $input): string {
    if ($input === null) return '';
    return trim(strip_tags($input));
}

/**
 * Sanitize rich text HTML input (allowing safe tags like paragraphs, lists, formatting, tables)
 */
function sanitize_html(?string $input): string {
    if ($input === null) return '';
    $allowedTags = '<p><br><strong><b><em><i><u><s><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><table><thead><tbody><tr><th><td><span><sub><sup>';
    $cleaned = strip_tags($input, $allowedTags);
    // Remove inline event handlers (onclick, onload, etc.) and unsafe attributes
    $cleaned = preg_replace('/<([a-z][a-z0-9]*)[^>]*?(\bon\w+)=("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '<$1', $cleaned);
    $cleaned = preg_replace('/href=(["\']?)(javascript|vbscript|data):/i', 'href=$1#disabled-', $cleaned);
    return trim($cleaned);
}

/**
 * Polyfills for mbstring functions if extension is not installed
 */
if (!function_exists('mb_strlen')) {
    function mb_strlen(?string $str, ?string $encoding = null): int {
        return strlen($str ?? '');
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(?string $str, int $start, ?int $length = null, ?string $encoding = null): string {
        return substr($str ?? '', $start, $length !== null ? $length : strlen($str ?? ''));
    }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth(?string $str, int $start, int $width, string $trimmarker = '', ?string $encoding = null): string {
        $str = $str ?? '';
        if (strlen($str) <= $width) {
            return $str;
        }
        $markerLen = strlen($trimmarker);
        if ($width <= $markerLen) {
            return substr($trimmarker, 0, $width);
        }
        return substr($str, $start, $width - $markerLen) . $trimmarker;
    }
}

/**
 * Clean text preview helper (decodes entities, strips all HTML tags, normalizes whitespace, and truncates)
 */
function clean_text_preview(?string $text, int $limit = 160, string $fallback = ''): string {
    if ($text === null || trim($text) === '') {
        return $fallback;
    }
    // Decode any entity-encoded tags first (e.g. &lt;p&gt; -> <p>)
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Strip all HTML tags
    $stripped = trim(strip_tags($decoded));
    // Normalize multi-spaces and newlines into single spaces
    $stripped = preg_replace('/\s+/', ' ', $stripped);
    if ($limit > 0 && mb_strlen($stripped) > $limit) {
        return mb_substr($stripped, 0, $limit) . '...';
    }
    return $stripped ?: $fallback;
}

/**
 * Render rich text content safely (supports CKEditor HTML, entity-encoded HTML, and legacy plain text with newlines)
 */
function render_rich_text(?string $text, string $fallback = 'None specified.'): string {
    if ($text === null || trim($text) === '') {
        return '<span class="text-muted">' . e($fallback) . '</span>';
    }

    $content = trim($text);

    // If text contains entity-encoded HTML tags (e.g. &lt;p&gt;, &lt;strong&gt;, etc.), decode them first
    if (preg_match('/&lt;(p|br|div|span|strong|b|em|i|u|ul|ol|li|h[1-6]|table|tr|td|th)[^&]*?&gt;/i', $content)) {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // Check if contains actual HTML tags
    if (strip_tags($content) !== $content) {
        return '<div class="rich-text-content">' . sanitize_html($content) . '</div>';
    }

    return '<div class="rich-text-content" style="white-space: pre-wrap; line-height: 1.6;">' . nl2br(e($content)) . '</div>';
}

/**
 * Calculate and format human-readable duration between two dates in Years, Months, and Days
 */
function format_duration(?string $startDate, ?string $endDate): string {
    if (empty($startDate) || empty($endDate)) return 'Not specified';
    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($end < $start) return 'Invalid timeline (End before Start)';
        $diff = $start->diff($end);
        $parts = [];
        if ($diff->y > 0) $parts[] = $diff->y . ($diff->y === 1 ? ' Year' : ' Years');
        if ($diff->m > 0) $parts[] = $diff->m . ($diff->m === 1 ? ' Month' : ' Months');
        if ($diff->d > 0 || empty($parts)) $parts[] = $diff->d . ($diff->d === 1 ? ' Day' : ' Days');
        return implode(', ', $parts);
    } catch (Exception $e) {
        return 'Not specified';
    }
}

/**
 * Generate or get CSRF token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require valid CSRF token or terminate request with 403
 */
function require_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        die("Invalid or expired CSRF token. Please refresh the page and try again.");
    }
}

/**
 * Flash message helper
 */
function flash(string $type, string $message): void {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = [
        'type' => $type, // success, danger, warning, info
        'message' => $message
    ];
}

/**
 * Get and clear flash messages
 */
function get_flashes(): array {
    $flashes = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $flashes;
}

/**
 * Render flash messages HTML
 */
function render_flashes(): string {
    $flashes = get_flashes();
    if (empty($flashes)) return '';

    $html = '<div class="flashes-container mb-3">';
    foreach ($flashes as $f) {
        $type = e($f['type']);
        $msg = e($f['message']);
        $html .= "<div class=\"alert alert-{$type} alert-dismissible fade show shadow-sm\" role=\"alert\">
            {$msg}
            <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button>
        </div>";
    }
    $html .= '</div>';
    return $html;
}

/**
 * Format Currency (INR / ₹)
 */
function format_currency(?float $amount): string {
    if ($amount === null) return '₹ 0.00';
    return '₹ ' . number_format($amount, 2, '.', ',');
}

/**
 * Format Date (e.g. 24-Oct-2026)
 */
function format_date(?string $dateStr): string {
    if (empty($dateStr)) return '-';
    $timestamp = strtotime($dateStr);
    if (!$timestamp) return '-';
    return date('d M Y', $timestamp);
}

/**
 * Format DateTime (e.g. 24-Oct-2026 14:30)
 */
function format_datetime(?string $dateStr): string {
    if (empty($dateStr)) return '-';
    $timestamp = strtotime($dateStr);
    if (!$timestamp) return '-';
    return date('d M Y, h:i A', $timestamp);
}

/**
 * Render Proposal Status Badge
 */
function render_status_badge(string $status): string {
    $classMap = [
        STATUS_DRAFT => 'bg-secondary text-white',
        STATUS_SUBMITTED_HOD => 'bg-primary text-white',
        STATUS_RETURNED_HOD => 'bg-danger text-white',
        STATUS_FORWARDED_JD => 'bg-info text-dark',
        STATUS_RETURNED_JD => 'bg-danger text-white',
        STATUS_APPROVED_IRC => 'bg-warning text-dark',
        STATUS_PENDING_IRC => 'bg-warning text-dark',
        STATUS_APPROVED_ACTIVE => 'bg-success text-white',
        STATUS_NOT_APPROVED_ARCHIVED => 'bg-dark text-white',
        STATUS_COMPLETION_SUBMITTED => 'bg-primary text-white',
        STATUS_COMPLETED => 'bg-success text-white'
    ];

    $badgeClass = $classMap[$status] ?? 'bg-secondary text-white';
    return '<span class="badge rounded-pill ' . $badgeClass . ' px-3 py-2 fw-medium"><i class="bi bi-circle-fill me-1 small" style="font-size: 0.6rem;"></i>' . e($status) . '</span>';
}

/**
 * Get human-readable label for proposal category
 */
function get_proposal_category_label(?string $category): string {
    if ($category === 'ongoing') {
        return 'Progress Report';
    } elseif ($category === 'completed') {
        return 'Completion Report';
    }
    return 'New Project Proposal';
}

/**
 * Render Proposal Category Badge (Progress Report, New Project Proposal, Completion Report)
 */
function render_proposal_category_badge(?string $category, bool $includeIcon = true, string $size = 'sm'): string {
    $cat = $category ?? 'new';
    if ($cat === 'ongoing') {
        $icon = $includeIcon ? '<i class="bi bi-arrow-repeat me-1"></i>' : '';
        return '<span class="badge bg-primary text-white shadow-xs px-2 py-1 fw-semibold" style="font-size: 0.78rem; letter-spacing: 0.02em;">' . $icon . 'Progress Report</span>';
    } elseif ($cat === 'completed') {
        $icon = $includeIcon ? '<i class="bi bi-check2-circle me-1"></i>' : '';
        return '<span class="badge text-white shadow-xs px-2 py-1 fw-semibold" style="background-color: #6b21a8; font-size: 0.78rem; letter-spacing: 0.02em;">' . $icon . 'Completion Report</span>';
    } else {
        $icon = $includeIcon ? '<i class="bi bi-file-earmark-plus me-1"></i>' : '';
        return '<span class="badge bg-success text-white shadow-xs px-2 py-1 fw-semibold" style="font-size: 0.78rem; letter-spacing: 0.02em;">' . $icon . 'New Project Proposal</span>';
    }
}

/**
 * Record action in immutable audit log
 */
function log_audit(?int $userId, string $action, string $entityType, ?int $entityId, ?string $details = null): void {
    try {
        $db = get_db();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $entityType, $entityId, $details, $ip, $now]);
    } catch (Exception $e) {
        error_log("Audit log failure: " . $e->getMessage());
    }
}

/**
 * Record Status History
 */
function record_status_history(int $proposalId, ?string $prevStatus, string $newStatus, int $actionBy, string $actionByRole, ?string $comments = null): void {
    try {
        $db = get_db();
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO proposal_status_history (proposal_id, previous_status, new_status, action_by, action_by_role, comments, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$proposalId, $prevStatus, $newStatus, $actionBy, $actionByRole, $comments, $now]);
    } catch (Throwable $e) {
        error_log("Status history failure: " . $e->getMessage());
    }
}

/**
 * Safe helper to record proposal status transition and audit log
 */
function log_proposal_action(int $proposalId, string $status, string $title, ?string $remarks = null): void {
    try {
        $userId = current_user_id() ?: 1;
        $user = current_user();
        $roleName = $user['role_name'] ?? 'User';
        record_status_history($proposalId, null, $status, $userId, $roleName, $remarks ?: $title);
        log_audit($userId, 'PROPOSAL_ACTION', 'proposals', $proposalId, "{$title} - {$remarks}");
    } catch (Throwable $e) {
        error_log("log_proposal_action error: " . $e->getMessage());
    }
}

/**
 * Generate unique proposal number
 */
function generate_proposal_number(string $deptCode): string {
    $db = get_db();
    $year = date('Y');
    $prefix = "PROP-{$year}-{$deptCode}-";
    $stmt = $db->prepare("SELECT COUNT(*) FROM proposals WHERE proposal_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$count, 3, '0', STR_PAD_LEFT);
}

/**
 * Check if a project ID / project number is unique across all projects and proposals
 */
function is_project_number_unique(string $projectNumber, int $excludeProjectId = 0, int $excludeProposalId = 0): bool {
    $clean = strtoupper(trim($projectNumber));
    if ($clean === '') {
        return false;
    }
    $db = get_db();

    // Check in projects
    $sqlProjects = "SELECT COUNT(*) FROM projects WHERE UPPER(TRIM(project_number)) = ?";
    $paramsP = [$clean];
    if ($excludeProjectId > 0) {
        $sqlProjects .= " AND id != ?";
        $paramsP[] = $excludeProjectId;
    }
    $stmtP = $db->prepare($sqlProjects);
    $stmtP->execute($paramsP);
    if ((int)$stmtP->fetchColumn() > 0) {
        return false;
    }

    // Check in proposals
    $sqlProposals = "SELECT COUNT(*) FROM proposals WHERE UPPER(TRIM(project_number)) = ?";
    $paramsProp = [$clean];
    if ($excludeProposalId > 0) {
        $sqlProposals .= " AND id != ?";
        $paramsProp[] = $excludeProposalId;
    }
    $stmtProp = $db->prepare($sqlProposals);
    $stmtProp->execute($paramsProp);
    if ((int)$stmtProp->fetchColumn() > 0) {
        return false;
    }

    return true;
}

/**
 * Generate suggested unique project number/ID
 */
function generate_project_number(?string $deptCode = null): string {
    $db = get_db();
    $year = date('Y');
    $dept = !empty($deptCode) ? strtoupper(trim($deptCode)) : 'NDRI';
    $prefix = "NDRI/{$dept}/{$year}/";

    $stmt = $db->prepare("SELECT project_number FROM projects WHERE project_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxSeq = 0;
    foreach ($existing as $pNum) {
        if (preg_match('/\/(\d+)$/', (string)$pNum, $m)) {
            $maxSeq = max($maxSeq, (int)$m[1]);
        } elseif (preg_match('/-(\d+)$/', (string)$pNum, $m)) {
            $maxSeq = max($maxSeq, (int)$m[1]);
        }
    }

    $n = $maxSeq + 1;
    $candidate = $prefix . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    while (!is_project_number_unique($candidate)) {
        $n++;
        $candidate = $prefix . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    }
    return $candidate;
}

/**
 * Get the full list of Institute Priority Programs
 */
function get_institute_priority_programs(): array {
    global $INSTITUTE_PRIORITY_PROGRAMS;
    if (!empty($INSTITUTE_PRIORITY_PROGRAMS) && is_array($INSTITUTE_PRIORITY_PROGRAMS)) {
        return $INSTITUTE_PRIORITY_PROGRAMS;
    }
    return [
        'A' => 'Program A : Translational Basic, strategic and policy research in dairying for ViksitBharat.',
        'B' => 'Program B : Production, multiplication and dissemination of elite dairy germplasm through cutting edge biotechnological tools and computational approaches.',
        'C' => 'Program C : Climate resilient smart dairy production and processing.',
        'D' => 'Program D : Driving innovation in functional foods, biologicals, and sustainable dairy solutions for a circular economy.',
        'E' => 'Program E : Research-driven implementation of the One Health approach to advance animal health, ensure food safety, and enhance quality milk production.',
        'F' => 'Program F : Advancing demand-driven innovations through systematic technologyassessment, refinement, dissemination, and capacity building for dairysector stakeholders.'
    ];
}

/**
 * Get formatted title for an institute priority program
 */
function get_priority_area_title(?string $code): string {
    if ($code === null || trim($code) === '') {
        return 'None Specified';
    }
    $programs = get_institute_priority_programs();
    $clean = strtoupper(trim($code));
    if (preg_match('/^PROGRAM\s*([A-F])/i', $clean, $m)) {
        $clean = $m[1];
    }
    if (isset($programs[$clean])) {
        return $programs[$clean];
    }
    return 'Program ' . $code;
}

/**
 * Get all Co-Principal Investigators for a proposal.
 * Automatically resolves Co-PIs directly from proposal_co_pis, or falls back to
 * the linked parent project's proposal if this is an ongoing/completed submission.
 */
function get_proposal_copis(int $proposalId, ?int $linkedProjectId = null, ?string $projectNumber = null): array {
    $db = get_db();
    
    // 1. Direct Co-PIs on the proposal
    if ($proposalId > 0) {
        $stmt = $db->prepare("SELECT id, proposal_id, co_pi_name, co_pi_name as name, designation, institution, email FROM proposal_co_pis WHERE proposal_id = ? ORDER BY id ASC");
        $stmt->execute([$proposalId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            return $rows;
        }
    }
    
    // 2. If empty, check linked project
    if ($linkedProjectId !== null && $linkedProjectId > 0) {
        $stmtP = $db->prepare("SELECT proposal_id FROM projects WHERE id = ?");
        $stmtP->execute([$linkedProjectId]);
        $parentPropId = (int)$stmtP->fetchColumn();
        if ($parentPropId > 0 && $parentPropId !== $proposalId) {
            $stmt = $db->prepare("SELECT id, proposal_id, co_pi_name, co_pi_name as name, designation, institution, email FROM proposal_co_pis WHERE proposal_id = ? ORDER BY id ASC");
            $stmt->execute([$parentPropId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                return $rows;
            }
        }
    }
    
    // 3. If empty, check by project number
    if (!empty($projectNumber)) {
        $cleanPnum = trim($projectNumber);
        $stmtP = $db->prepare("SELECT proposal_id FROM projects WHERE UPPER(TRIM(project_number)) = UPPER(?)");
        $stmtP->execute([$cleanPnum]);
        $parentPropId = (int)$stmtP->fetchColumn();
        if ($parentPropId > 0 && $parentPropId !== $proposalId) {
            $stmt = $db->prepare("SELECT id, proposal_id, co_pi_name, co_pi_name as name, designation, institution, email FROM proposal_co_pis WHERE proposal_id = ? ORDER BY id ASC");
            $stmt->execute([$parentPropId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                return $rows;
            }
        }
        // Also check any other proposal with this project_number that has Co-PIs
        $stmtOther = $db->prepare("SELECT id, proposal_id, co_pi_name, co_pi_name as name, designation, institution, email FROM proposal_co_pis WHERE proposal_id IN (SELECT id FROM proposals WHERE UPPER(TRIM(project_number)) = UPPER(?) AND id != ?) ORDER BY id ASC");
        $stmtOther->execute([$cleanPnum, $proposalId]);
        $rows = $stmtOther->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            return $rows;
        }
    }
    
    return [];
}

/**
 * Get all Co-Principal Investigators for an approved active/completed project.
 */
function get_project_copis(int $projectId): array {
    $db = get_db();
    if ($projectId <= 0) return [];
    
    $stmtPrj = $db->prepare("SELECT id, proposal_id, project_number FROM projects WHERE id = ?");
    $stmtPrj->execute([$projectId]);
    $prj = $stmtPrj->fetch(PDO::FETCH_ASSOC);
    if (!$prj) return [];
    
    return get_proposal_copis((int)$prj['proposal_id'], (int)$prj['id'], (string)($prj['project_number'] ?? ''));
}

/**
 * Retrieve the HOD who reviewed/endorsed/approved a proposal to the Joint Director.
 * Returns associative array with name, designation, department_name, approved_at, and comments.
 */
function get_hod_approver_info(int $proposalId, ?int $departmentId = null): array {
    $db = get_db();
    
    // 1. From proposal_status_history
    if ($proposalId > 0) {
        $stmt = $db->prepare("
            SELECT psh.*, u.name as hod_name, u.designation as hod_designation, u.email as hod_email,
                   d.department_name, d.department_code
            FROM proposal_status_history psh
            JOIN users u ON psh.action_by = u.id
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE psh.proposal_id = ? AND (psh.new_status = 'Forwarded to Joint Director' OR psh.action_by_role = 'HOD')
            ORDER BY psh.id DESC LIMIT 1
        ");
        $stmt->execute([$proposalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['hod_name'])) {
            return [
                'name' => $row['hod_name'],
                'designation' => $row['hod_designation'] ?: 'Head of Department',
                'department_name' => $row['department_name'] ?: '',
                'department_code' => $row['department_code'] ?: '',
                'approved_at' => $row['created_at'],
                'comments' => $row['comments'] ?: ''
            ];
        }
    }
    
    // 2. Fallback: HOD of the department
    if ($departmentId !== null && $departmentId > 0) {
        $stmtH = $db->prepare("
            SELECT u.name as hod_name, u.designation as hod_designation, u.email as hod_email,
                   d.department_name, d.department_code
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.department_id = ? AND u.role_id = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmtH->execute([$departmentId, ROLE_HOD]);
        $rowH = $stmtH->fetch(PDO::FETCH_ASSOC);
        if ($rowH && !empty($rowH['hod_name'])) {
            return [
                'name' => $rowH['hod_name'],
                'designation' => $rowH['hod_designation'] ?: 'Head of Department',
                'department_name' => $rowH['department_name'] ?: '',
                'department_code' => $rowH['department_code'] ?: '',
                'approved_at' => null,
                'comments' => ''
            ];
        }
    }
    
    return [
        'name' => 'Head of Department',
        'designation' => 'Head of Department',
        'department_name' => '',
        'department_code' => '',
        'approved_at' => null,
        'comments' => ''
    ];
}

/**
 * Parse and normalize yearly budget breakdown from JSON or array.
 * Falls back to computing equal or sensible yearly breakdown if only total budget is known.
 */
function parse_yearly_budget($rawBudget, float $fallbackTotal = 0, int $fallbackYears = 3): array {
    $breakdown = [];
    if (is_array($rawBudget)) {
        $breakdown = $rawBudget;
    } elseif (is_string($rawBudget) && trim($rawBudget) !== '') {
        $decoded = json_decode($rawBudget, true);
        if (is_array($decoded)) {
            $breakdown = $decoded;
        }
    }

    $normalized = [];
    if (!empty($breakdown)) {
        $idx = 1;
        foreach ($breakdown as $key => $val) {
            $amount = (float)$val;
            $label = is_string($key) && !is_numeric($key) ? $key : "Year {$idx}";
            if (is_numeric($key)) {
                $label = "Year {$key}";
            }
            $normalized[$label] = $amount;
            $idx++;
        }
        return $normalized;
    }

    if ($fallbackTotal > 0) {
        $numYears = max(1, $fallbackYears);
        $base = round($fallbackTotal / $numYears, 2);
        $sum = 0;
        for ($y = 1; $y <= $numYears; $y++) {
            if ($y === $numYears) {
                $normalized["Year {$y}"] = round($fallbackTotal - $sum, 2);
            } else {
                $normalized["Year {$y}"] = $base;
                $sum += $base;
            }
        }
        return $normalized;
    }

    return ['Year 1' => 0.0];
}

/**
 * Render an HTML view of the year-wise budget breakdown.
 */
function render_yearly_budget_html($yearlyBudget, float $totalBudget = 0, bool $compact = false): string {
    $parsed = parse_yearly_budget($yearlyBudget, $totalBudget);
    $computedTotal = array_sum($parsed);
    if ($computedTotal <= 0 && $totalBudget > 0) {
        $computedTotal = $totalBudget;
    }

    if (empty($parsed)) {
        return '<span class="text-muted small">No yearly breakdown recorded</span>';
    }

    if ($compact) {
        $html = '<div class="d-flex flex-wrap gap-1 align-items-center">';
        foreach ($parsed as $yr => $amt) {
            $html .= '<span class="badge bg-white text-dark border extra-small px-2 py-1">';
            $html .= '<strong class="text-secondary">' . htmlspecialchars($yr) . ':</strong> ' . format_currency((float)$amt);
            $html .= '</span>';
        }
        $html .= '<span class="badge bg-success-subtle text-success border border-success-subtle extra-small px-2 py-1">';
        $html .= '<strong>Total:</strong> ' . format_currency((float)$computedTotal);
        $html .= '</span>';
        $html .= '</div>';
        return $html;
    }

    $html = '<div class="table-responsive border rounded bg-white mt-2">';
    $html .= '<table class="table table-sm table-bordered table-striped mb-0 text-center align-middle" style="font-size: 0.85rem;">';
    $html .= '<thead class="table-light">';
    $html .= '<tr>';
    foreach (array_keys($parsed) as $yr) {
        $html .= '<th class="fw-semibold text-secondary py-2">' . htmlspecialchars($yr) . '</th>';
    }
    $html .= '<th class="fw-bold text-dark bg-primary-subtle py-2">Total Budget</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    $html .= '<tr>';
    foreach ($parsed as $amt) {
        $html .= '<td class="fw-bold font-monospace text-dark py-2">' . format_currency((float)$amt) . '</td>';
    }
    $html .= '<td class="fw-bold font-monospace text-primary bg-primary-subtle py-2 fs-6">' . format_currency((float)$computedTotal) . '</td>';
    $html .= '</tr>';
    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';

    return $html;
}

/**
 * Get funding agency details including agency name, agency type (National/International),
 * and styled badges.
 */
function get_funding_agency_details($item): array {
    $projectType = $item['project_type'] ?? ($item['prop_project_type'] ?? 'in_house');
    $isExternal = ($projectType === 'funding_agency');
    $agencyName = trim($item['funding_agency'] ?? ($item['prop_funding_agency'] ?? ''));
    $agencyType = trim($item['funding_agency_type'] ?? ($item['prop_funding_agency_type'] ?? 'National'));
    if (empty($agencyType)) {
        $agencyType = 'National';
    }

    $badgeHtml = '';
    if ($isExternal) {
        $typeBadge = ($agencyType === 'International')
            ? '<span class="badge bg-info text-white shadow-xs me-1"><i class="bi bi-globe me-1"></i>International Funding Agency</span>'
            : '<span class="badge bg-primary text-white shadow-xs me-1"><i class="bi bi-flag-fill me-1"></i>National Funding Agency</span>';
        $agencyBadge = !empty($agencyName) ? '<span class="badge bg-light text-dark border"><i class="bi bi-bank2 text-success me-1"></i>' . htmlspecialchars($agencyName) . '</span>' : '';
        $badgeHtml = $typeBadge . ' ' . $agencyBadge;
    } else {
        $badgeHtml = '<span class="badge bg-secondary-subtle text-secondary border"><i class="bi bi-house-door-fill me-1"></i>In-house (Core Mandate)</span>';
    }

    return [
        'is_external' => $isExternal,
        'project_type' => $projectType,
        'agency_name' => $agencyName,
        'agency_type' => $agencyType,
        'badge_html' => trim($badgeHtml)
    ];
}

/**
 * Fetch all review and scientist comments for a proposal
 */
function get_proposal_comments(int $proposalId): array {
    $db = get_db();
    try {
        $stmt = $db->prepare("SELECT c.*, u.name as user_name, u.email as user_email, u.designation as user_designation, r.role_name
                              FROM proposal_comments c
                              JOIN users u ON c.user_id = u.id
                              JOIN roles r ON u.role_id = r.id
                              WHERE c.proposal_id = ?
                              ORDER BY c.created_at ASC");
        $stmt->execute([$proposalId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Safely add a comment to proposal_comments
 */
function add_proposal_comment(int $proposalId, int $userId, string $commentStage, string $comment): void {
    $comment = trim($comment);
    if (empty($comment)) {
        return;
    }
    try {
        $db = get_db();
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO proposal_comments (proposal_id, user_id, comment_stage, comment, created_at)
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$proposalId, $userId, $commentStage, $comment, $now]);
    } catch (Throwable $e) {
        error_log("add_proposal_comment error: " . $e->getMessage());
    }
}

/**
 * Fetch complete workflow status history for a proposal
 */
function get_proposal_status_history(int $proposalId): array {
    $db = get_db();
    try {
        $stmt = $db->prepare("SELECT h.*, u.name as user_name
                              FROM proposal_status_history h
                              LEFT JOIN users u ON h.action_by = u.id
                              WHERE h.proposal_id = ?
                              ORDER BY h.created_at ASC");
        $stmt->execute([$proposalId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Extract the latest scientist remarks, resubmission response, and previous reviewer directives
 */
function get_latest_scientist_remark(int $proposalId, ?array $proposal = null): array {
    $db = get_db();
    if (!$proposal) {
        $stmt = $db->prepare("SELECT * FROM proposals WHERE id = ?");
        $stmt->execute([$proposalId]);
        $proposal = $stmt->fetch();
    }

    $result = [
        'has_remark' => false,
        'text' => '',
        'stage' => 'Scientist Submission',
        'created_at' => $proposal['updated_at'] ?? date('Y-m-d H:i:s'),
        'is_resubmission' => false,
        'previous_return_comment' => null,
        'previous_return_role' => null,
        'previous_return_date' => null,
    ];

    // Check status history for return event to detect resubmission
    try {
        $histStmt = $db->prepare("SELECT * FROM proposal_status_history 
                                  WHERE proposal_id = ? 
                                  AND (new_status LIKE '%Returned%' OR previous_status LIKE '%Returned%' OR comments LIKE '%Returned%')
                                  ORDER BY created_at DESC LIMIT 1");
        $histStmt->execute([$proposalId]);
        $retHist = $histStmt->fetch();
        if ($retHist) {
            $result['is_resubmission'] = true;
            $result['previous_return_comment'] = $retHist['comments'] ?? null;
            $result['previous_return_role'] = $retHist['action_by_role'] ?? 'Reviewer';
            $result['previous_return_date'] = $retHist['created_at'] ?? null;
        }
    } catch (Throwable $eH) {}

    // 1. Direct column in proposals
    if (!empty($proposal['submission_remarks'])) {
        $result['has_remark'] = true;
        $result['text'] = trim($proposal['submission_remarks']);
        $result['created_at'] = $proposal['updated_at'] ?? date('Y-m-d H:i:s');
        if ($result['is_resubmission']) {
            $result['stage'] = 'Scientist Resubmission';
        }
        return $result;
    }

    // 2. Proposal comments table
    try {
        $cStmt = $db->prepare("SELECT * FROM proposal_comments 
                               WHERE proposal_id = ? AND (comment_stage LIKE '%Scientist%' OR comment_stage = 'Resubmission')
                               ORDER BY created_at DESC LIMIT 1");
        $cStmt->execute([$proposalId]);
        $cRow = $cStmt->fetch();
        if ($cRow && !empty($cRow['comment'])) {
            $result['has_remark'] = true;
            $result['text'] = trim($cRow['comment']);
            $result['stage'] = $cRow['comment_stage'];
            $result['created_at'] = $cRow['created_at'];
            if (stripos($cRow['comment_stage'], 'Resubmission') !== false) {
                $result['is_resubmission'] = true;
            }
            return $result;
        }
    } catch (Throwable $eC) {}

    // 3. Fallback to status history remarks
    try {
        $hStmt = $db->prepare("SELECT * FROM proposal_status_history 
                               WHERE proposal_id = ? 
                               AND (comments LIKE '%Remarks:%' OR comments LIKE '%resubmitted%')
                               ORDER BY created_at DESC LIMIT 1");
        $hStmt->execute([$proposalId]);
        $hRow = $hStmt->fetch();
        if ($hRow && !empty($hRow['comments'])) {
            $raw = $hRow['comments'];
            $text = $raw;
            if (strpos($raw, 'Remarks:') !== false) {
                $parts = explode('Remarks:', $raw, 2);
                $text = trim($parts[1] ?? $raw);
            }
            if (!empty($text)) {
                $result['has_remark'] = true;
                $result['text'] = $text;
                $result['stage'] = 'Scientist Resubmission';
                $result['created_at'] = $hRow['created_at'];
                $result['is_resubmission'] = true;
                return $result;
            }
        }
    } catch (Throwable $eS) {}

    return $result;
}

/**
 * Render a high-visibility alert/card for Scientist Comments & Remarks
 */
function render_scientist_remarks_box(array $proposal, ?array $remarkData = null): string {
    if (!$remarkData) {
        $remarkData = get_latest_scientist_remark((int)$proposal['id'], $proposal);
    }

    if (!$remarkData['has_remark'] && !$remarkData['is_resubmission']) {
        return '';
    }

    $scientistName = htmlspecialchars($proposal['scientist_name'] ?? 'Principal Investigator');
    $scientistDesig = htmlspecialchars($proposal['scientist_designation'] ?? 'Scientist');
    $text = htmlspecialchars($remarkData['text']);
    $dateFormatted = format_datetime($remarkData['created_at']);

    $html = '';

    if ($remarkData['is_resubmission']) {
        // High priority resubmission banner
        $html .= '<div class="alert alert-warning border-warning shadow-sm p-3 mb-4 rounded-3">';
        $html .= '  <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 pb-2 border-bottom border-warning-subtle gap-2">';
        $html .= '    <div class="d-flex align-items-center gap-2">';
        $html .= '      <span class="p-2 rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">';
        $html .= '        <i class="bi bi-arrow-repeat fs-6 fw-bold"></i>';
        $html .= '      </span>';
        $html .= '      <h6 class="fw-bold text-dark m-0">';
        $html .= '        Scientist Resubmission Remarks & Response to Review Feedback';
        $html .= '      </h6>';
        $html .= '    </div>';
        $html .= '    <span class="badge bg-warning text-dark border border-warning fw-bold px-2 py-1">';
        $html .= '      <i class="bi bi-patch-check-fill me-1"></i> Resubmitted Revision';
        $html .= '    </span>';
        $html .= '  </div>';

        if (!empty($remarkData['previous_return_comment'])) {
            $prevRole = htmlspecialchars($remarkData['previous_return_role'] ?: 'Reviewer');
            $prevDate = $remarkData['previous_return_date'] ? ' on ' . format_datetime($remarkData['previous_return_date']) : '';
            $prevComment = htmlspecialchars($remarkData['previous_return_comment']);
            $html .= '  <div class="p-2 px-3 mb-2 rounded bg-white bg-opacity-75 border border-warning-subtle small">';
            $html .= '    <div class="text-danger fw-bold extra-small text-uppercase mb-1">';
            $html .= '      <i class="bi bi-arrow-counterclockwise me-1"></i> Previous ' . $prevRole . ' Return Directives' . $prevDate . ':';
            $html .= '    </div>';
            $html .= '    <div class="text-dark fst-italic">' . nl2br($prevComment) . '</div>';
            $html .= '  </div>';
        }

        if (!empty($text)) {
            $html .= '  <div class="p-3 bg-white rounded border border-warning shadow-xs text-dark" style="font-size: 0.95rem; line-height: 1.6;">';
            $html .= '    <div class="fw-bold text-secondary extra-small text-uppercase mb-1">';
            $html .= '      <i class="bi bi-chat-dots-fill text-warning me-1"></i> Scientist\'s Statement of Modifications Made:';
            $html .= '    </div>';
            $html .= '    <div class="fw-semibold text-dark" style="white-space: pre-wrap;">' . nl2br($text) . '</div>';
            $html .= '  </div>';
        } else {
            $html .= '  <div class="p-2 bg-white rounded border border-warning-subtle text-muted small fst-italic">';
            $html .= '    Proposal was revised and resubmitted by the scientist according to reviewer feedback.';
            $html .= '  </div>';
        }

        $html .= '  <div class="d-flex justify-content-between align-items-center mt-2 extra-small text-muted px-1">';
        $html .= '    <span><i class="bi bi-person-fill text-primary me-1"></i><strong>' . $scientistName . '</strong> (' . $scientistDesig . ')</span>';
        $html .= '    <span><i class="bi bi-clock me-1"></i>Resubmitted: ' . $dateFormatted . '</span>';
        $html .= '  </div>';
        $html .= '</div>';
    } else {
        // Standard submission remarks card
        $html .= '<div class="alert alert-info border-info shadow-sm p-3 mb-4 rounded-3">';
        $html .= '  <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 pb-2 border-bottom border-info-subtle gap-2">';
        $html .= '    <div class="d-flex align-items-center gap-2">';
        $html .= '      <span class="p-2 rounded-circle bg-info text-white d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">';
        $html .= '        <i class="bi bi-chat-quote-fill fs-6"></i>';
        $html .= '      </span>';
        $html .= '      <h6 class="fw-bold text-dark m-0">Scientist\'s Submission Remarks / Statement</h6>';
        $html .= '    </div>';
        $html .= '    <span class="badge bg-info text-white fw-bold px-2 py-1"><i class="bi bi-send-check me-1"></i>Initial Submission Note</span>';
        $html .= '  </div>';
        $html .= '  <div class="p-3 bg-white rounded border border-info shadow-xs text-dark" style="font-size: 0.95rem; line-height: 1.6;">';
        $html .= '    <div class="fw-semibold text-dark" style="white-space: pre-wrap;">' . nl2br($text) . '</div>';
        $html .= '  </div>';
        $html .= '  <div class="d-flex justify-content-between align-items-center mt-2 extra-small text-muted px-1">';
        $html .= '    <span><i class="bi bi-person-fill text-primary me-1"></i><strong>' . $scientistName . '</strong> (' . $scientistDesig . ')</span>';
        $html .= '    <span><i class="bi bi-clock me-1"></i>Submitted: ' . $dateFormatted . '</span>';
        $html .= '  </div>';
        $html .= '</div>';
    }

    return $html;
}


