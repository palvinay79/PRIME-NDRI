<?php
/**
 * Research Proposal and Project Management System
 * Official Institute Research Council (IRC) - Joint Director (Research) Dossier (.docx) Generator
 * 
 * Compiles all new research proposals slated for IRC meeting deliberation into an official JDR dossier.
 * Strictly organized by:
 *   Program A : In-House New Proposals -> Externally Funded New Proposals
 *   Program B : In-House New Proposals -> Externally Funded New Proposals
 *   Program C : In-House New Proposals -> Externally Funded New Proposals
 *   Program D : In-House New Proposals -> Externally Funded New Proposals
 *   Program E : In-House New Proposals -> Externally Funded New Proposals
 *   Program F : In-House New Proposals -> Externally Funded New Proposals
 * 
 * Rules:
 * - Omits any programs or sub-categories that have no proposals.
 * - Each proposal starts cleanly at the top of a new page.
 * - Workflow history table is excluded from the DOCX file.
 * - Uses clean UTF-8 dashes and characters (no unparsed &mdash; or &bull; entities in Word XML).
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';

require_login();

$db = get_db();
$userId = current_user_id();
$userRole = current_user_role_id();

// Filter parameters: defaults to 'new' proposals as specified by user
$typeFilter = sanitize($_GET['type'] ?? 'new'); // 'new', 'all', 'ongoing', 'completed'
$format = sanitize($_GET['format'] ?? 'docx'); // 'docx' or 'html'

// Query proposals slated for IRC Meeting Deliberation
$sql = "SELECT p.*, u.name as scientist_name, u.email as scientist_email, u.designation as scientist_designation,
               d.department_name, d.department_code
        FROM proposals p
        JOIN users u ON p.scientist_id = u.id
        JOIN departments d ON p.department_id = d.id
        WHERE p.current_status = ?";
$params = [STATUS_APPROVED_IRC];

if ($typeFilter === 'new') {
    $sql .= " AND (p.proposal_category = 'new' OR p.proposal_category IS NULL OR p.proposal_category = '')";
} elseif ($typeFilter === 'ongoing') {
    $sql .= " AND p.proposal_category = 'ongoing'";
} elseif ($typeFilter === 'completed') {
    $sql .= " AND p.proposal_category = 'completed'";
}

$sql .= " ORDER BY p.id ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$proposals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map proposals to programs A to F
$programsMeta = get_institute_priority_programs();
$programKeys = ['A', 'B', 'C', 'D', 'E', 'F'];

// Structure: $organized[PROGRAM_LETTER]['in_house' => [...], 'external' => [...]]
$organized = [];
foreach ($programKeys as $k) {
    $organized[$k] = [
        'name' => $programsMeta[$k] ?? "Program {$k}",
        'in_house' => [],
        'external' => []
    ];
}
$unassigned = [
    'name' => 'Other / Unassigned Priority Programs',
    'in_house' => [],
    'external' => []
];

// Helper to resolve program key from institute_priority_area
function resolve_program_letter(?string $area): string {
    if (empty($area)) return 'A';
    $clean = strtoupper(trim($area));
    if (in_array($clean, ['A', 'B', 'C', 'D', 'E', 'F'])) {
        return $clean;
    }
    if (preg_match('/(?:PROGRAM\s*|^)([A-F])\b/i', $clean, $m)) {
        return strtoupper($m[1]);
    }
    if (stripos($clean, 'basic') !== false || stripos($clean, 'viksit') !== false || stripos($clean, 'policy') !== false || stripos($clean, 'translational') !== false) return 'A';
    if (stripos($clean, 'germplasm') !== false || stripos($clean, 'computational') !== false || stripos($clean, 'biotechnological') !== false || stripos($clean, 'breeding') !== false || stripos($clean, 'embryo') !== false) return 'B';
    if (stripos($clean, 'climate') !== false || stripos($clean, 'silage') !== false || stripos($clean, 'processing') !== false || stripos($clean, 'resilient') !== false) return 'C';
    if (stripos($clean, 'functional') !== false || stripos($clean, 'circular') !== false || stripos($clean, 'packaging') !== false || stripos($clean, 'bioactive') !== false || stripos($clean, 'whey') !== false) return 'D';
    if (stripos($clean, 'one health') !== false || stripos($clean, 'amr') !== false || stripos($clean, 'safety') !== false || stripos($clean, 'biosensor') !== false || stripos($clean, 'mastitis') !== false || stripos($clean, 'quality milk') !== false) return 'E';
    if (stripos($clean, 'demand') !== false || stripos($clean, 'extension') !== false || stripos($clean, 'shg') !== false || stripos($clean, 'dissemination') !== false || stripos($clean, 'capacity') !== false || stripos($clean, 'stakeholder') !== false) return 'F';
    return 'A';
}

$totalProposalsCount = count($proposals);
$totalBudgetCount = 0.0;
$totalInHouseCount = 0;
$totalExternalCount = 0;

foreach ($proposals as &$p) {
    $progKey = resolve_program_letter($p['institute_priority_area'] ?? '');
    $isExternal = (($p['project_type'] ?? '') === 'funding_agency');
    
    // Attach Co-PIs
    $p['copis'] = get_proposal_copis((int)$p['id'], (int)($p['linked_project_id'] ?? 0), (string)($p['project_number'] ?? ''));
    
    // Attach review history (for HOD / Joint Director remarks extraction)
    $stmtHist = $db->prepare("SELECT * FROM proposal_status_history WHERE proposal_id = ? ORDER BY created_at ASC");
    $stmtHist->execute([(int)$p['id']]);
    $p['history'] = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    // Attach documents
    $stmtDocs = $db->prepare("SELECT * FROM proposal_documents WHERE proposal_id = ? ORDER BY created_at DESC");
    $stmtDocs->execute([(int)$p['id']]);
    $rawDocs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
    $p['documents'] = array_values(array_filter($rawDocs, function($doc) {
        return !empty(trim($doc['file_name'] ?? '')) && !empty(trim($doc['file_path'] ?? ''));
    }));

    $budgetVal = (float)($p['proposed_budget'] ?? 0);
    $totalBudgetCount += $budgetVal;

    if ($isExternal) {
        $totalExternalCount++;
        if (isset($organized[$progKey])) {
            $organized[$progKey]['external'][] = $p;
        } else {
            $unassigned['external'][] = $p;
        }
    } else {
        $totalInHouseCount++;
        if (isset($organized[$progKey])) {
            $organized[$progKey]['in_house'][] = $p;
        } else {
            $unassigned['in_house'][] = $p;
        }
    }
}
unset($p);

// -------------------------------------------------------------------------
// HTML PREVIEW / PRINT VIEW (if format=html requested)
// -------------------------------------------------------------------------
if ($format === 'html') {
    $filename = "JDR_IRC_Meeting_New_Proposals_" . date('Y_m_d') . ".html";
    header("Content-Type: text/html; charset=UTF-8");
    header("Content-Disposition: inline; filename=\"{$filename}\"");
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>IRC Meeting Deliberation - JDR Compilation Dossier</title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; margin: 40px; color: #1e293b; background: #fff; line-height: 1.6; font-size: 11pt; }
            .header-box { text-align: center; border-bottom: 2px solid #1a365d; padding-bottom: 12px; margin-bottom: 24px; }
            h1 { font-size: 20pt; color: #1a365d; margin: 0 0 4px 0; text-align: center; }
            h2 { font-size: 14pt; color: #475569; margin: 0 0 4px 0; text-align: center; }
            h3 { font-size: 12pt; color: #1a365d; border-bottom: 2px solid #1a365d; padding-bottom: 4px; margin-top: 24px; margin-bottom: 8px; }
            h4 { font-size: 11pt; color: #1e293b; margin-top: 14px; margin-bottom: 4px; }
            table { width: 100%; border-collapse: collapse; margin: 12px 0; }
            th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; font-size: 10pt; }
            th { background-color: #f1f5f9; font-weight: 600; color: #1e293b; }
            .stat-banner { background: #f8fafc; border: 1px solid #cbd5e1; padding: 12px; margin-bottom: 24px; display: flex; justify-content: space-around; font-size: 10.5pt; }
            .prog-banner { background: #1a365d; color: #fff; padding: 12px 16px; font-size: 13pt; font-weight: bold; margin-top: 36px; margin-bottom: 16px; border-radius: 3px; }
            .subprog-title { background: #f1f5f9; color: #1a365d; padding: 8px 12px; font-size: 11.5pt; font-weight: bold; margin-top: 20px; border-left: 4px solid #1a365d; }
            .proposal-block { margin-top: 24px; margin-bottom: 32px; border-bottom: 1px solid #e2e8f0; padding-bottom: 24px; }
            @media print {
                .no-print { display: none; }
                body { margin: 15mm; }
                .proposal-block { page-break-before: always; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 20px; text-align: right;">
            <a href="?type=<?= e($typeFilter) ?>&format=docx" style="background: #1a365d; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold;">Download Word (.docx)</a>
            <button onclick="window.print()" style="background: #059669; color: #fff; padding: 8px 16px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; margin-left: 8px;">Print Dossier</button>
        </div>

        <div class="header-box">
            <h1>NATIONAL DAIRY RESEARCH INSTITUTE (NDRI PRIME)</h1>
            <h2>JOINT DIRECTOR (RESEARCH) — IRC MEETING DELIBERATION DOSSIER</h2>
            <p style="margin: 6px 0 0 0; color: #64748b; font-size: 10pt;">
                Compilation of Slated New Research Proposals • <?= date('F d, Y') ?>
            </p>
        </div>

        <div class="stat-banner">
            <div><strong>Total Slated:</strong> <?= $totalProposalsCount ?> Proposals</div>
            <div><strong>In-House:</strong> <?= $totalInHouseCount ?> Proposals</div>
            <div><strong>Externally Funded:</strong> <?= $totalExternalCount ?> Proposals</div>
            <div><strong>Total Proposed Outlay:</strong> <?= format_currency($totalBudgetCount) ?></div>
        </div>

        <?php 
        $hasAnyProgramWithProposals = false;
        foreach ($programKeys as $pKey): 
            $pData = $organized[$pKey];
            $inHouseCount = count($pData['in_house']);
            $extCount = count($pData['external']);

            // Don't add if proposals are not there in program!
            if ($inHouseCount === 0 && $extCount === 0) {
                continue;
            }
            $hasAnyProgramWithProposals = true;
        ?>
            <div class="prog-banner">
                PROGRAM <?= $pKey ?>: <?= e(strtoupper($pData['name'])) ?>
                <span style="float: right; font-size: 10pt; font-weight: normal; opacity: 0.9;">
                    (<?= $inHouseCount ?> In-House, <?= $extCount ?> Externally Funded)
                </span>
            </div>

            <!-- In-House (only if present) -->
            <?php if ($inHouseCount > 0): ?>
                <div class="subprog-title">PROGRAM <?= $pKey ?> — IN-HOUSE NEW PROPOSALS (<?= $inHouseCount ?>)</div>
                <?php foreach ($pData['in_house'] as $p): ?>
                    <div class="proposal-block">
                        <div style="font-size: 13pt; font-weight: bold; color: #1a365d; text-align: left; margin-bottom: 6px; border-bottom: 2px solid #1a365d; padding-bottom: 2px;">PROGRAM <?= $pKey ?></div>
                        <h3><?= e($p['proposal_number']) ?> — <?= e($p['title']) ?></h3>
                        <table>
                            <tr>
                                <th style="width: 25%;">Proposal Number:</th><td><?= e($p['proposal_number']) ?></td>
                                <th style="width: 25%;">Current Status:</th><td><span style="color: #059669; font-weight: bold;"><?= e($p['current_status']) ?></span></td>
                            </tr>
                            <tr>
                                <th>Principal Investigator:</th><td><?= e($p['scientist_name']) ?> (<?= e($p['scientist_email']) ?>)</td>
                                <th>Department / Division:</th><td><?= e($p['department_name']) ?></td>
                            </tr>
                            <tr>
                                <th>Proposed Start Date:</th><td><?= format_date($p['proposed_start_date']) ?></td>
                                <th>Proposed End Date:</th><td><?= format_date($p['proposed_end_date']) ?></td>
                            </tr>
                            <tr>
                                <th>Proposed Duration:</th><td><?= format_duration($p['proposed_start_date'], $p['proposed_end_date']) ?></td>
                                <th>Technology Readiness Level:</th><td>TRL-<?= (int)$p['trl_level'] ?></td>
                            </tr>
                            <tr>
                                <th>Proposed Budget (INR):</th><td><strong style="color: #059669;"><?= format_currency((float)$p['proposed_budget']) ?></strong></td>
                                <th>Project Category:</th><td><strong>In-house Project</strong></td>
                            </tr>
                            <tr>
                                <th>Priority Program:</th><td colspan="3">Program <?= $pKey ?> — <?= e($pData['name']) ?></td>
                            </tr>
                        </table>

                        <h4>1. PROJECT TITLE</h4>
                        <p style="font-weight: bold; color: #1a365d;"><?= e($p['title']) ?></p>

                        <h4>2. PRIORITY ALIGNMENT</h4>
                        <p><strong>Institute Priority Area:</strong> <?= e(get_priority_area_title($p['institute_priority_area'] ?? '')) ?></p>
                        <p><strong>National Priority Area:</strong> <?= e($p['national_priority_area'] ?: 'None specified') ?></p>

                        <h4>3. PROJECT ASSOCIATES / CO-INVESTIGATORS (Co-PIs)</h4>
                        <?php if (!empty($p['copis'])): ?>
                            <table>
                                <tr><th>Name</th><th>Institution / Division</th><th>Designation</th><th>Email</th></tr>
                                <?php foreach ($p['copis'] as $c): ?>
                                    <tr><td><?= e($c['co_pi_name']) ?></td><td><?= e($c['institution']) ?></td><td><?= e($c['designation'] ?? 'Co-PI') ?></td><td><?= e($c['email'] ?? 'N/A') ?></td></tr>
                                <?php endforeach; ?>
                            </table>
                        <?php else: ?>
                            <p style="color: #64748b;">No Co-Principal Investigators registered for this proposal.</p>
                        <?php endif; ?>

                        <h4>4. RESEARCH PROBLEM &amp; BASELINE INFORMATION</h4>
                        <p><strong>Research Problem / Key Questions:</strong><br><?= nl2br(e($p['research_problem'])) ?></p>
                        <p><strong>Baseline Information:</strong><br><?= nl2br(e($p['baseline_info'])) ?></p>

                        <h4>5. NOVELTY, GAP ANALYSIS &amp; JUSTIFICATION</h4>
                        <p><strong>Novelty &amp; Gap Analysis:</strong><br><?= nl2br(e($p['novelty_gap_analysis'])) ?></p>
                        <p><strong>Justification &amp; Intended End-Users:</strong><br><?= nl2br(e($p['justification_end_users'])) ?></p>

                        <h4>6. OBJECTIVES &amp; TECHNICAL PROGRAM</h4>
                        <p><strong>Specific Objectives:</strong><br><?= nl2br(e(strip_tags($p['objectives']))) ?></p>
                        <p><strong>Technical Program Proposed:</strong><br><?= nl2br(e(strip_tags($p['technical_program'] ?? $p['methodology'] ?? ''))) ?></p>

                        <h4>7. EXPECTED OUTCOMES &amp; BUDGET JUSTIFICATION</h4>
                        <p><strong>Expected Deliverables &amp; Outcomes:</strong><br><?= nl2br(e(strip_tags($p['expected_outcomes'] ?? ''))) ?></p>
                        <p><strong>Financial &amp; Budget Justification:</strong><br><?= nl2br(e($p['budget_justification'])) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Externally Funded (only if present) -->
            <?php if ($extCount > 0): ?>
                <div class="subprog-title">PROGRAM <?= $pKey ?> — EXTERNALLY FUNDED NEW PROPOSALS (<?= $extCount ?>)</div>
                <?php foreach ($pData['external'] as $p): ?>
                    <?php 
                        $agencyDetails = get_funding_agency_details($p);
                        $agencyText = $agencyDetails['agency_name'] . (!empty($agencyDetails['agency_type']) ? ' (' . $agencyDetails['agency_type'] . ')' : '');
                    ?>
                    <div class="proposal-block">
                        <div style="font-size: 13pt; font-weight: bold; color: #1a365d; text-align: left; margin-bottom: 6px; border-bottom: 2px solid #1a365d; padding-bottom: 2px;">PROGRAM <?= $pKey ?></div>
                        <h3><?= e($p['proposal_number']) ?> — <?= e($p['title']) ?></h3>
                        <table>
                            <tr>
                                <th style="width: 25%;">Proposal Number:</th><td><?= e($p['proposal_number']) ?></td>
                                <th style="width: 25%;">Current Status:</th><td><span style="color: #059669; font-weight: bold;"><?= e($p['current_status']) ?></span></td>
                            </tr>
                            <tr>
                                <th>Principal Investigator:</th><td><?= e($p['scientist_name']) ?> (<?= e($p['scientist_email']) ?>)</td>
                                <th>Department / Division:</th><td><?= e($p['department_name']) ?></td>
                            </tr>
                            <tr>
                                <th>Proposed Start Date:</th><td><?= format_date($p['proposed_start_date']) ?></td>
                                <th>Proposed End Date:</th><td><?= format_date($p['proposed_end_date']) ?></td>
                            </tr>
                            <tr>
                                <th>Proposed Duration:</th><td><?= format_duration($p['proposed_start_date'], $p['proposed_end_date']) ?></td>
                                <th>Technology Readiness Level:</th><td>TRL-<?= (int)$p['trl_level'] ?></td>
                            </tr>
                            <tr>
                                <th>Proposed Budget (INR):</th><td><strong style="color: #059669;"><?= format_currency((float)$p['proposed_budget']) ?></strong></td>
                                <th>Project Category:</th><td><strong style="color: #4338ca;">Externally Funded Project</strong></td>
                            </tr>
                            <tr>
                                <th>Funding Agency:</th><td><strong><?= e($agencyText) ?></strong></td>
                                <th>Priority Program:</th><td>Program <?= $pKey ?> — <?= e($pData['name']) ?></td>
                            </tr>
                        </table>

                        <h4>1. PROJECT TITLE</h4>
                        <p style="font-weight: bold; color: #1a365d;"><?= e($p['title']) ?></p>

                        <h4>2. PRIORITY ALIGNMENT</h4>
                        <p><strong>Institute Priority Area:</strong> <?= e(get_priority_area_title($p['institute_priority_area'] ?? '')) ?></p>
                        <p><strong>National Priority Area:</strong> <?= e($p['national_priority_area'] ?: 'None specified') ?></p>

                        <h4>3. PROJECT ASSOCIATES / CO-INVESTIGATORS (Co-PIs)</h4>
                        <?php if (!empty($p['copis'])): ?>
                            <table>
                                <tr><th>Name</th><th>Institution / Division</th><th>Designation</th><th>Email</th></tr>
                                <?php foreach ($p['copis'] as $c): ?>
                                    <tr><td><?= e($c['co_pi_name']) ?></td><td><?= e($c['institution']) ?></td><td><?= e($c['designation'] ?? 'Co-PI') ?></td><td><?= e($c['email'] ?? 'N/A') ?></td></tr>
                                <?php endforeach; ?>
                            </table>
                        <?php else: ?>
                            <p style="color: #64748b;">No Co-Principal Investigators registered for this proposal.</p>
                        <?php endif; ?>

                        <h4>4. RESEARCH PROBLEM &amp; BASELINE INFORMATION</h4>
                        <p><strong>Research Problem / Key Questions:</strong><br><?= nl2br(e($p['research_problem'])) ?></p>
                        <p><strong>Baseline Information:</strong><br><?= nl2br(e($p['baseline_info'])) ?></p>

                        <h4>5. NOVELTY, GAP ANALYSIS &amp; JUSTIFICATION</h4>
                        <p><strong>Novelty &amp; Gap Analysis:</strong><br><?= nl2br(e($p['novelty_gap_analysis'])) ?></p>
                        <p><strong>Justification &amp; Intended End-Users:</strong><br><?= nl2br(e($p['justification_end_users'])) ?></p>

                        <h4>6. OBJECTIVES &amp; TECHNICAL PROGRAM</h4>
                        <p><strong>Specific Objectives:</strong><br><?= nl2br(e(strip_tags($p['objectives']))) ?></p>
                        <p><strong>Technical Program Proposed:</strong><br><?= nl2br(e(strip_tags($p['technical_program'] ?? $p['methodology'] ?? ''))) ?></p>

                        <h4>7. EXPECTED OUTCOMES &amp; BUDGET JUSTIFICATION</h4>
                        <p><strong>Expected Deliverables &amp; Outcomes:</strong><br><?= nl2br(e(strip_tags($p['expected_outcomes'] ?? ''))) ?></p>
                        <p><strong>Financial &amp; Budget Justification:</strong><br><?= nl2br(e($p['budget_justification'])) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!$hasAnyProgramWithProposals): ?>
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 24px; text-align: center; color: #64748b; font-size: 11pt; margin-top: 24px;">
                No new research proposals are currently slated for IRC Meeting Deliberation.
            </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------------------
// PURE PHP WORD DOCUMENT GENERATOR (.DOCX) - IDENTICAL TO export-doc.php
// -------------------------------------------------------------------------
class DocxBuilder {
    private string $body = '';

    private function escape(?string $str): string {
        // Decode any leftover HTML entities (like &mdash;, &bull;, &ndash;) to real UTF-8 characters first
        $decoded = html_entity_decode((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return htmlspecialchars($decoded, ENT_XML1, 'UTF-8');
    }

    public function addPageBreak(): void {
        $this->body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    public function addTitle(string $mainTitle, string $subTitle = ''): void {
        $this->body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="40"/></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:sz w:val="30"/><w:color w:val="1A365D"/><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr>'
            . '<w:t>' . $this->escape($mainTitle) . '</w:t></w:r></w:p>';

        if ($subTitle) {
            $this->body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="0" w:after="160"/></w:pPr>'
                . '<w:r><w:rPr><w:b/><w:sz w:val="22"/><w:color w:val="475569"/><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr>'
                . '<w:t>' . $this->escape($subTitle) . '</w:t></w:r></w:p>';
        }

        // Horizontal line
        $this->body .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="16" w:space="8" w:color="1A365D"/></w:pBdr><w:spacing w:after="160"/></w:pPr></w:p>';
    }

    public function addProgramHeaderTopLeft(string $text): void {
        $this->body .= '<w:p><w:pPr><w:jc w:val="left"/><w:spacing w:before="60" w:after="140"/><w:pBdr><w:bottom w:val="single" w:sz="12" w:space="6" w:color="1A365D"/></w:pBdr></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:sz w:val="28"/><w:color w:val="1A365D"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $this->escape($text) . '</w:t></w:r></w:p>';
    }

    public function addHeading(string $title): void {
        $this->body .= '<w:p><w:pPr><w:spacing w:before="240" w:after="80"/><w:pBdr><w:bottom w:val="single" w:sz="8" w:space="4" w:color="1A365D"/></w:pBdr></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:sz w:val="22"/><w:color w:val="1A365D"/><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr>'
            . '<w:t>' . $this->escape($title) . '</w:t></w:r></w:p>';
    }

    public function addSubHeading(string $title): void {
        $this->body .= '<w:p><w:pPr><w:spacing w:before="160" w:after="40"/><w:keepNext/></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:sz w:val="21"/><w:color w:val="1E293B"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $this->escape($title) . '</w:t></w:r></w:p>';
    }

    public function addParagraph(?string $text, bool $isBold = false, string $color = '333333'): void {
        $raw = (string)$text;
        $raw = str_replace(["\\r\\n", "\\r", "\\n"], "\n", $raw);
        $trimmed = trim($raw);
        if ($trimmed === '') {
            $trimmed = 'Not specified';
            $color = '64748B';
        }
        $lines = preg_split('/\r\n|\r|\n/', $trimmed);
        $this->body .= '<w:p><w:pPr><w:spacing w:after="100" w:line="276" w:lineRule="auto"/></w:pPr>';
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $this->body .= '<w:r><w:br/></w:r>';
            }
            $this->body .= '<w:r><w:rPr>';
            if ($isBold) $this->body .= '<w:b/>';
            if ($color !== '333333') $this->body .= '<w:color w:val="' . $color . '"/>';
            $this->body .= '<w:sz w:val="21"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
                . '<w:t xml:space="preserve">' . $this->escape($line) . '</w:t></w:r>';
        }
        $this->body .= '</w:p>';
    }

    public function addRichText(?string $html, string $color = '333333'): void {
        $raw = (string)$html;
        $raw = str_replace(["\\r\\n", "\\r", "\\n"], "\n", $raw);
        if (trim($raw) === '' || trim(strip_tags($raw)) === '') {
            $this->addParagraph('Not specified', false, '64748B');
            return;
        }
        $formatted = preg_replace('/<\s*li[^>]*>/i', "• ", $raw);
        $formatted = preg_replace('/<\s*\/\s*li\s*>/i', "\n", $formatted);
        $formatted = preg_replace('/<\s*br\s*\/?>/i', "\n", $formatted);
        $formatted = preg_replace('/<\s*\/\s*p\s*>/i', "\n\n", $formatted);
        $formatted = preg_replace('/<\s*\/\s*div\s*>/i', "\n", $formatted);
        $formatted = strip_tags($formatted);
        $formatted = html_entity_decode($formatted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $formatted = trim($formatted);
        if ($formatted === '') {
            $this->addParagraph('Not specified', false, '64748B');
            return;
        }
        $this->addParagraph($formatted, false, $color);
    }

    public function addKeyValueRow(string $key, ?string $value): void {
        $val = trim((string)$value);
        $isMissing = ($val === '');
        $displayVal = $isMissing ? 'Not specified' : $val;
        $valColor = $isMissing ? '64748B' : '333333';
        $this->body .= '<w:p><w:pPr><w:spacing w:after="60" w:line="260" w:lineRule="auto"/></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:sz w:val="21"/><w:color w:val="1E293B"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $this->escape($key) . ': </w:t></w:r>'
            . '<w:r><w:rPr><w:sz w:val="21"/><w:color w:val="' . $valColor . '"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $this->escape($displayVal) . '</w:t></w:r></w:p>';
    }

    public function addMetaTable(array $matrix): void {
        $this->body .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'
            . '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>'
            . '<w:left w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>'
            . '<w:bottom w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>'
            . '<w:right w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>'
            . '</w:tblBorders>'
            . '<w:tblCellMar><w:top w:w="90" w:type="dxa"/><w:bottom w:w="90" w:type="dxa"/><w:left w:w="120" w:type="dxa"/><w:right w:w="120" w:type="dxa"/></w:tblCellMar>'
            . '</w:tblPr>';

        foreach ($matrix as $row) {
            $this->body .= '<w:tr>';
            foreach ($row as $cell) {
                $bg = $cell['bg'] ?? 'FFFFFF';
                $isBold = !empty($cell['bold']);
                $color = $cell['color'] ?? '1E293B';
                $this->body .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/></w:tcPr>'
                    . '<w:p><w:pPr><w:spacing w:after="40"/></w:pPr>'
                    . '<w:r><w:rPr><w:sz w:val="20"/><w:color w:val="' . $color . '"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>' . ($isBold ? '<w:b/>' : '') . '</w:rPr>'
                    . '<w:t xml:space="preserve">' . $this->escape($cell['text'] ?? '') . '</w:t></w:r></w:p></w:tc>';
            }
            $this->body .= '</w:tr>';
        }
        $this->body .= '</w:tbl><w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
    }

    public function addTable(array $headers, array $rows): void {
        $this->body .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'
            . '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>'
            . '<w:left w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>'
            . '<w:bottom w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>'
            . '<w:right w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>'
            . '</w:tblBorders>'
            . '<w:tblCellMar><w:top w:w="100" w:type="dxa"/><w:bottom w:w="100" w:type="dxa"/><w:left w:w="120" w:type="dxa"/><w:right w:w="120" w:type="dxa"/></w:tblCellMar>'
            . '</w:tblPr>';

        // Header Row
        $this->body .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($headers as $h) {
            $this->body .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/></w:tcPr>'
                . '<w:p><w:pPr><w:spacing w:after="40"/></w:pPr>'
                . '<w:r><w:rPr><w:b/><w:sz w:val="20"/><w:color w:val="1E293B"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
                . '<w:t xml:space="preserve">' . $this->escape($h) . '</w:t></w:r></w:p></w:tc>';
        }
        $this->body .= '</w:tr>';

        // Rows
        if (empty($rows)) {
            $colCount = count($headers);
            $this->body .= '<w:tr><w:tc><w:tcPr><w:gridSpan w:val="' . $colCount . '"/></w:tcPr>'
                . '<w:p><w:pPr><w:spacing w:after="40"/></w:pPr><w:r><w:rPr><w:i/><w:sz w:val="20"/><w:color w:val="64748B"/></w:rPr>'
                . '<w:t>None recorded.</w:t></w:r></w:p></w:tc></w:tr>';
        } else {
            foreach ($rows as $row) {
                $this->body .= '<w:tr>';
                foreach ($row as $cell) {
                    $this->body .= '<w:tc><w:p><w:pPr><w:spacing w:after="40"/></w:pPr>'
                        . '<w:r><w:rPr><w:sz w:val="20"/><w:color w:val="333333"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
                        . '<w:t xml:space="preserve">' . $this->escape((string)$cell) . '</w:t></w:r></w:p></w:tc>';
                }
                $this->body .= '</w:tr>';
            }
        }
        $this->body .= '</w:tbl><w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
    }

    public function generateDocx(): string {
        $xmlDoc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>'
            . $this->body
            . '<w:sectPr>'
            . '<w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/>'
            . '</w:sectPr>'
            . '</w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults>'
            . '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/><w:color w:val="222222"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:line="276" w:lineRule="auto" w:after="120"/></w:pPr></w:pPrDefault>'
            . '</w:docDefaults>'
            . '</w:styles>';

        $files = [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rels,
            'word/_rels/document.xml.rels' => $docRels,
            'word/styles.xml' => $styles,
            'word/document.xml' => $xmlDoc
        ];

        // Pure PHP ZIP generation (Standard PKZIP format)
        $data = '';
        $cd = '';
        $dtime = (12 << 11) | (0 << 5) | (0 >> 1);
        $ddate = ((date('Y') - 1980) << 9) | (date('n') << 5) | date('j');

        foreach ($files as $name => $content) {
            $len = strlen($content);
            $crc = crc32($content);
            $offset = strlen($data);

            $lh = pack("VvvvvvVVVvv", 0x04034b50, 20, 0, 0, $dtime, $ddate, $crc, $len, $len, strlen($name), 0);
            $data .= $lh . $name . $content;

            $cdEntry = pack("VvvvvvvVVVvvvvvVV", 0x02014b50, 20, 20, 0, 0, $dtime, $ddate, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 32, $offset);
            $cd .= $cdEntry . $name;
        }

        $offsetCd = strlen($data);
        $lenCd = strlen($cd);
        $count = count($files);
        $eocd = pack("VvvvvVVv", 0x06054b50, 0, 0, $count, $count, $lenCd, $offsetCd, 0);

        return $data . $cd . $eocd;
    }
}

/**
 * Renders a complete proposal dossier using the exact same structure and sections as export-doc.php
 * Note: Workflow history table is intentionally excluded per user requirement.
 */
function render_proposal_dossier_docx(DocxBuilder $builder, array $proposal, string $programHeaderInfo = '', string $programLetter = ''): void {
    $cat = $proposal['proposal_category'] ?? 'new';
    $progKey = !empty($programLetter) ? $programLetter : resolve_program_letter($proposal['institute_priority_area'] ?? '');

    // On top of proposal name: Remove "NDRI etc" and add Program A or B or C etc on top left
    $builder->addProgramHeaderTopLeft("PROGRAM " . $progKey);

    // Metadata Overview Table
    $metaMatrix = [
        [
            ['text' => 'Proposal Number:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => (string)$proposal['proposal_number'], 'bold' => true, 'color' => '1A365D'],
            ['text' => 'Current Status:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => (string)$proposal['current_status'], 'bold' => true, 'color' => '059669']
        ],
        [
            ['text' => 'Principal Investigator:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => $proposal['scientist_name'] . ' (' . $proposal['scientist_email'] . ')'],
            ['text' => 'Department / Division:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => (string)$proposal['department_name']]
        ],
        [
            ['text' => 'Proposed Start Date:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => format_date($proposal['proposed_start_date'])],
            ['text' => 'Proposed End Date:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => format_date($proposal['proposed_end_date'])]
        ],
        [
            ['text' => 'Proposed Duration:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => format_duration($proposal['proposed_start_date'], $proposal['proposed_end_date']), 'bold' => true, 'color' => '1A365D'],
            ['text' => 'Technology Readiness Level:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => 'TRL-' . $proposal['trl_level']]
        ]
    ];

    if (in_array($cat, ['ongoing', 'completed'])) {
        $allocAmt = (float)($proposal['budget_allocated'] ?: $proposal['proposed_budget']);
        $utilAmt = (float)$proposal['budget_utilized'];
        $metaMatrix[] = [
            ['text' => 'Budget Allocated (INR):', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => format_currency($allocAmt), 'bold' => true],
            ['text' => 'Budget Utilized (INR):', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => format_currency($utilAmt), 'bold' => true, 'color' => ($allocAmt > 0 && $utilAmt > $allocAmt) ? 'DC2626' : '059669']
        ];
    } else {
        $metaMatrix[] = [
            ['text' => 'Proposed Budget (INR):', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => format_currency((float)$proposal['proposed_budget']), 'bold' => true],
            ['text' => 'Project Category:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => ($proposal['project_type'] ?? '') === 'funding_agency' ? 'Externally Funded Project' : 'In-house Project', 'bold' => true, 'color' => ($proposal['project_type'] ?? '') === 'funding_agency' ? '059669' : '1A365D']
        ];
    }

    // Funding Agency: only show for externally funded projects. For in-house projects, it is covered in Project Category.
    $isExternal = (($proposal['project_type'] ?? '') === 'funding_agency');
    if ($isExternal) {
        $agencyDetails = get_funding_agency_details($proposal);
        $agencyName = trim($agencyDetails['agency_name'] ?? '');
        if (!empty($agencyDetails['agency_type'])) {
            $agencyName = $agencyName ? ($agencyName . ' (' . $agencyDetails['agency_type'] . ')') : $agencyDetails['agency_type'];
        }
        if (empty($agencyName)) {
            $agencyName = 'External Funding Agency';
        }

        $metaMatrix[] = [
            ['text' => 'Funding Agency:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => $agencyName, 'bold' => true, 'color' => '1A365D'],
            ['text' => !empty($proposal['project_number']) ? 'Project Code:' : '', 'bold' => !empty($proposal['project_number']), 'bg' => !empty($proposal['project_number']) ? 'F8FAFC' : 'FFFFFF'],
            ['text' => (string)($proposal['project_number'] ?? ''), 'bold' => !empty($proposal['project_number'])]
        ];
    } elseif (!empty($proposal['project_number'])) {
        $metaMatrix[] = [
            ['text' => 'Project Code:', 'bold' => true, 'bg' => 'F8FAFC'],
            ['text' => (string)$proposal['project_number'], 'bold' => true, 'color' => '1A365D'],
            ['text' => '', 'bg' => 'FFFFFF'],
            ['text' => '']
        ];
    }

    $builder->addMetaTable($metaMatrix);

    // Section 1: Title
    $builder->addHeading("1. PROJECT TITLE");
    $builder->addParagraph($proposal['title'], true, '1A365D');

    // Section 2: Priority Alignment
    $builder->addHeading("2. PRIORITY ALIGNMENT");
    $builder->addKeyValueRow("Institute Priority Area", get_priority_area_title($proposal['institute_priority_area'] ?? ''));
    $builder->addKeyValueRow("National Priority Area", $proposal['national_priority_area'] ?: 'None specified');

    // Section 3: Co-Investigators
    $builder->addHeading("3. PROJECT ASSOCIATES / CO-INVESTIGATORS (Co-PIs)");
    $copis = $proposal['copis'] ?? [];
    if (!empty($copis)) {
        $copiRows = [];
        foreach ($copis as $c) {
            $copiRows[] = [
                $c['co_pi_name'] ?? '',
                $c['institution'] ?? '',
                $c['designation'] ?? '',
                $c['email'] ?? ''
            ];
        }
        $builder->addTable(["Name", "Institution / Division", "Designation", "Email"], $copiRows);
    } else {
        $builder->addParagraph("No Co-Principal Investigators registered for this proposal.");
    }

    // Category-Specific Sections (New Proposals)
    if ($cat === 'completed') {
        $builder->addHeading("4. EXECUTIVE FINAL REPORT");
        $builder->addRichText($proposal['final_report'] ?? '');
        $builder->addHeading("5. SIGNIFICANT SCIENTIFIC ACHIEVEMENTS");
        $builder->addRichText($proposal['significant_achievements'] ?? '');
        $builder->addHeading("6. ACTION TAKEN REPORT (ATR) ON PREVIOUS IRC RECOMMENDATIONS");
        $builder->addRichText($proposal['previous_irc_atr'] ?? '');
        $builder->addHeading("7. DELIVERABLES, OUTPUT & OUTCOME");
        $builder->addRichText($proposal['output_outcome'] ?? '');
    } elseif ($cat === 'ongoing') {
        $builder->addHeading("4. BIANNUAL PROGRESS REPORT");
        if (!empty($proposal['progress_report_period'])) {
            $builder->addKeyValueRow("Progress Report Period", $proposal['progress_report_period']);
        }
        $builder->addSubHeading("Executive Progress Summary:");
        $builder->addParagraph($proposal['progress_report'] ?? '');
        $builder->addHeading("5. SIGNIFICANT ACHIEVEMENTS DURING REPORTING PERIOD");
        $builder->addParagraph($proposal['significant_achievements'] ?? '');
        $builder->addHeading("6. ACTION TAKEN REPORT (ATR) ON PREVIOUS IRC RECOMMENDATIONS");
        $builder->addParagraph($proposal['previous_irc_atr'] ?? '');
        $builder->addHeading("7. INTERIM OUTPUT / OUTCOME (PUBLICATIONS, TECHNOLOGIES, PATENTS)");
        $builder->addParagraph($proposal['output_outcome'] ?? '');
    } else {
        // Standard New Proposal sections - identical to export-doc.php
        $builder->addHeading("4. RESEARCH PROBLEM & BASELINE INFORMATION");
        $builder->addSubHeading("Research Problem / Key Questions:");
        $builder->addParagraph($proposal['research_problem'] ?? '');
        $builder->addSubHeading("Baseline Information:");
        $builder->addParagraph($proposal['baseline_info'] ?? '');

        $builder->addHeading("5. NOVELTY, GAP ANALYSIS & JUSTIFICATION");
        $builder->addSubHeading("Novelty & Gap Analysis:");
        $builder->addParagraph($proposal['novelty_gap_analysis'] ?? '');
        $builder->addSubHeading("Justification & Intended End-Users:");
        $builder->addParagraph($proposal['justification_end_users'] ?? '');

        $builder->addHeading("6. OBJECTIVES & TECHNICAL PROGRAM");
        $builder->addSubHeading("Specific Objectives:");
        $builder->addRichText($proposal['objectives'] ?? '');
        $builder->addSubHeading("Technical Program Proposed (objective-wise, indicating role of Co-PIs):");
        $builder->addRichText($proposal['technical_program'] ?? $proposal['methodology'] ?? '');

        $builder->addHeading("7. EXPECTED OUTCOMES & BUDGET JUSTIFICATION");
        $builder->addSubHeading("Expected Deliverables & Outcomes:");
        $builder->addRichText($proposal['expected_outcomes'] ?? '');
        $builder->addSubHeading("Financial & Budget Justification:");
        $builder->addParagraph($proposal['budget_justification'] ?? '');
    }

    // Year-wise Budget Allocation Breakdown
    $yearlyTotal = (float)($proposal['approved_budget'] ?: ($proposal['budget_allocated'] ?: $proposal['proposed_budget']));
    $hasExplicitYearly = !empty($proposal['yearly_budget']) && !(is_string($proposal['yearly_budget']) && in_array(trim($proposal['yearly_budget']), ['', '[]', '{}', 'null']));
    if ($hasExplicitYearly || ($yearlyTotal > 0 && ($proposal['project_type'] ?? '') !== 'funding_agency')) {
        $yearlyArr = parse_yearly_budget($proposal['yearly_budget'] ?? null, $yearlyTotal);
        if (!empty($yearlyArr) && array_sum($yearlyArr) > 0) {
            $builder->addHeading("YEAR-WISE BUDGET ALLOCATION BREAKDOWN");
            $bRows = [];
            foreach ($yearlyArr as $yr => $amt) {
                $bRows[] = [$yr, format_currency((float)$amt)];
            }
            $bRows[] = ['Total Allocation', format_currency(array_sum($yearlyArr))];
            $builder->addTable(["Year / Reporting Cycle", "Allocation (INR)"], $bRows);
        }
    }

    // Attached Documents & Annexures (ONLY visible if documents are attached)
    $documents = $proposal['documents'] ?? [];
    if (!empty($documents)) {
        $builder->addHeading("ATTACHED DOCUMENTS & ANNEXURES");
        $docRows = [];
        foreach ($documents as $d) {
            $docRows[] = [
                $d['file_name'] ?? 'Document',
                format_file_size((int)($d['file_size'] ?? 0)),
                format_datetime($d['created_at'] ?? '')
            ];
        }
        $builder->addTable(["Document Name", "File Size", "Uploaded Date & Time"], $docRows);
    }
}

// -------------------------------------------------------------------------
// ASSEMBLE COMPILATION DOSSIER
// -------------------------------------------------------------------------
$builder = new DocxBuilder();

// 1. Master Compilation Cover Header
$builder->addTitle(
    "NATIONAL DAIRY RESEARCH INSTITUTE (NDRI PRIME)",
    "JOINT DIRECTOR (RESEARCH) — IRC MEETING DELIBERATION DOSSIER"
);

$summaryMeta = [
    [
        ['text' => 'Dossier Purpose:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => 'Official Deliberation Dossier for Institute Research Council (IRC) Meeting', 'bold' => true, 'color' => '1A365D'],
        ['text' => 'Compilation Authority:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => 'Joint Director (Research) & Directorate of Research', 'bold' => true]
    ],
    [
        ['text' => 'Date of Compilation:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => date('d F, Y, h:i A')],
        ['text' => 'Council Deliberation Stage:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => 'Approved for IRC Meeting Deliberation (Slated)', 'bold' => true, 'color' => '059669']
    ],
    [
        ['text' => 'Total Proposals Slated:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => "{$totalProposalsCount} Research Proposals", 'bold' => true, 'color' => '1A365D'],
        ['text' => 'Total Proposed Outlay:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => format_currency($totalBudgetCount), 'bold' => true, 'color' => '059669']
    ],
    [
        ['text' => 'In-House New Proposals:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => "{$totalInHouseCount} Projects", 'bold' => true],
        ['text' => 'Externally Funded New:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => "{$totalExternalCount} Projects", 'bold' => true, 'color' => '1A365D']
    ]
];
$builder->addMetaTable($summaryMeta);

// 2. Program Breakdown Table (Programs A to F) - only include programs with slated proposals!
$matrixHeaders = ["Program", "Program Description", "In-House New", "Externally Funded", "Total Slated", "Proposed Outlay (INR)"];
$matrixRows = [];

foreach ($programKeys as $pKey) {
    $pData = $organized[$pKey];
    $ihCount = count($pData['in_house']);
    $exCount = count($pData['external']);
    $progTotal = $ihCount + $exCount;
    
    // Don't add if proposals are not there in program!
    if ($progTotal === 0) {
        continue;
    }

    $progBudget = 0.0;
    foreach ($pData['in_house'] as $p) $progBudget += (float)$p['proposed_budget'];
    foreach ($pData['external'] as $p) $progBudget += (float)$p['proposed_budget'];

    $matrixRows[] = [
        "Program " . $pKey,
        $pData['name'],
        (string)$ihCount,
        (string)$exCount,
        (string)$progTotal,
        format_currency($progBudget)
    ];
}

if (!empty($matrixRows)) {
    $builder->addHeading("INSTITUTE PRIORITY PROGRAM ALLOCATION MATRIX");
    $matrixRows[] = [
        "TOTAL",
        "All Slated Proposals in Scheduled Programs",
        (string)$totalInHouseCount,
        (string)$totalExternalCount,
        (string)$totalProposalsCount,
        format_currency($totalBudgetCount)
    ];
    $builder->addTable($matrixHeaders, $matrixRows);
}

// 3. Agenda Index / Table of Contents (only if proposals exist)
if ($totalProposalsCount > 0) {
    $builder->addHeading("AGENDA INDEX OF SLATED NEW PROPOSALS");
    $indexHeaders = ["S.No.", "Program", "Funding Type", "Proposal Number", "Project Title", "Principal Investigator", "Division", "Budget (INR)"];
    $indexRows = [];
    $sNo = 1;

    foreach ($programKeys as $pKey) {
        if (empty($organized[$pKey]['in_house']) && empty($organized[$pKey]['external'])) {
            continue;
        }
        // In-House first
        foreach ($organized[$pKey]['in_house'] as $p) {
            $indexRows[] = [
                (string)$sNo++,
                "Program " . $pKey,
                "In-House",
                $p['proposal_number'],
                $p['title'],
                $p['scientist_name'],
                $p['department_code'],
                format_currency((float)$p['proposed_budget'])
            ];
        }
        // Externally funded next
        foreach ($organized[$pKey]['external'] as $p) {
            $indexRows[] = [
                (string)$sNo++,
                "Program " . $pKey,
                "External",
                $p['proposal_number'],
                $p['title'],
                $p['scientist_name'],
                $p['department_code'],
                format_currency((float)$p['proposed_budget'])
            ];
        }
    }
    $builder->addTable($indexHeaders, $indexRows);
} else {
    $builder->addParagraph("No research project proposals are currently slated for IRC Meeting Deliberation.");
}

// 4. Sequential Compilation: Program A through Program F
// - Don't add if proposals are not there in program
// - Each proposal starts from a brand new page on top in docx
foreach ($programKeys as $pKey) {
    $pData = $organized[$pKey];
    $ihProps = $pData['in_house'];
    $exProps = $pData['external'];

    // Don't add if proposals are not there in program!
    if (empty($ihProps) && empty($exProps)) {
        continue;
    }

    // -------------------------------------------------------------
    // PART 1: In-House New Proposals for this Program (only if present)
    // -------------------------------------------------------------
    if (!empty($ihProps)) {
        foreach ($ihProps as $idx => $p) {
            // Proposal starts from new page on top in docx!
            $builder->addPageBreak();
            $contextText = "Program {$pKey} — In-House New (" . ($idx + 1) . " of " . count($ihProps) . ")";
            render_proposal_dossier_docx($builder, $p, $contextText, $pKey);
        }
    }

    // -------------------------------------------------------------
    // PART 2: Externally Funded New Proposals for this Program (only if present)
    // -------------------------------------------------------------
    if (!empty($exProps)) {
        foreach ($exProps as $idx => $p) {
            // Proposal starts from new page on top in docx!
            $builder->addPageBreak();
            $contextText = "Program {$pKey} — Externally Funded New (" . ($idx + 1) . " of " . count($exProps) . ")";
            render_proposal_dossier_docx($builder, $p, $contextText, $pKey);
        }
    }
}

// Generate docx binary
$binaryDocx = $builder->generateDocx();
$filename = "JDR_IRC_Meeting_New_Proposals_Deliberation_" . date('Y_m_d') . ".docx";

// Send proper OpenXML headers
header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Content-Length: " . strlen($binaryDocx));
header("Cache-Control: max-age=0, no-cache, no-store, must-revalidate");
header("Pragma: public");

echo $binaryDocx;
exit;
