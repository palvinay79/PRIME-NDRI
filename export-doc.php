<?php
/**
 * Research Proposal and Project Management System
 * Professional Office Open XML (.docx) Generator
 * 
 * Generates valid, native .docx documents that open seamlessly on Mac (Microsoft Word,
 * Apple Pages, LibreOffice, Google Docs) and Windows without requiring the ZipArchive extension.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';

require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Invalid proposal ID.");
}

$db = get_db();
$stmt = $db->prepare("SELECT p.*, u.name as scientist_name, u.email as scientist_email,
                             d.department_name, d.department_code
                      FROM proposals p
                      JOIN users u ON p.scientist_id = u.id
                      JOIN departments d ON p.department_id = d.id
                      WHERE p.id = ?");
$stmt->execute([$id]);
$proposal = $stmt->fetch();

if (!$proposal) {
    die("Proposal not found.");
}

if (!can_view_proposal($proposal)) {
    die("Unauthorized to view this proposal.");
}

// Fetch Co-PIs
$copis = get_proposal_copis($id, (int)($proposal['linked_project_id'] ?? 0), (string)($proposal['project_number'] ?? ''));

// Fetch History
$stmt = $db->prepare("SELECT * FROM proposal_status_history WHERE proposal_id = ? ORDER BY created_at ASC");
$stmt->execute([$id]);
$history = $stmt->fetchAll();

// Optional legacy HTML view if requested
if (isset($_GET['format']) && $_GET['format'] === 'html') {
    $filename = "Proposal_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $proposal['proposal_number']) . ".html";
    header("Content-Type: text/html; charset=UTF-8");
    header("Content-Disposition: inline; filename=\"{$filename}\"");
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title><?= e($proposal['title']) ?></title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; margin: 40px; color: #1e293b; line-height: 1.6; }
            h1 { color: #1a365d; text-align: center; margin-bottom: 4px; font-size: 20pt; }
            h2 { color: #475569; text-align: center; margin-top: 0; font-size: 14pt; }
            h3 { color: #1a365d; border-bottom: 2px solid #1a365d; padding-bottom: 4px; margin-top: 24px; font-size: 12pt; }
            table { width: 100%; border-collapse: collapse; margin: 12px 0; }
            th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; font-size: 10.5pt; }
            th { background-color: #f1f5f9; font-weight: 600; }
        </style>
    </head>
    <body>
        <h1>NATIONAL DAIRY RESEARCH INSTITUTE (NDRI PRIME)</h1>
        <h2>PROJECT PROPOSAL SUBMISSION & APPROVAL RECORD</h2>
        <hr style="border: 1px solid #1a365d;">
        <p><strong>Proposal Number:</strong> <?= e($proposal['proposal_number']) ?> | <strong>Status:</strong> <?= e($proposal['current_status']) ?></p>
        <p><strong>Principal Investigator:</strong> <?= e($proposal['scientist_name']) ?> (<?= e($proposal['scientist_email']) ?>)</p>
        <p><strong>Department:</strong> <?= e($proposal['department_name']) ?></p>
        <h3>1. PROJECT TITLE</h3>
        <p><?= e($proposal['title']) ?></p>
        <script>window.print();</script>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Pure PHP Docx Builder (Generates valid Office Open XML zip container)
 */
class DocxBuilder {
    private string $body = '';

    private function escape(?string $str): string {
        return htmlspecialchars((string)$str, ENT_XML1, 'UTF-8');
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

    public function addHeading(string $title): void {
        $this->body .= '<w:p><w:pPr><w:spacing w:before="240" w:after="80"/><w:pBdr><w:bottom w:val="single" w:sz="8" w:space="4" w:color="1A365D"/></w:pBdr></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:sz w:val="22"/><w:color w:val="1A365D"/><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr>'
            . '<w:t>' . $this->escape($title) . '</w:t></w:r></w:p>';
    }

    public function addParagraph(?string $text, bool $isBold = false, string $color = '333333'): void {
        if ($text === null || trim($text) === '') {
            $text = 'Not specified';
        }
        $lines = preg_split('/\r\n|\r|\n/', (string)$text);
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
        if ($html === null || trim($html) === '') {
            $this->addParagraph('Not specified');
            return;
        }
        // Convert html elements to clean text representation for docx
        $formatted = preg_replace('/<\s*li[^>]*>/i', "• ", (string)$html);
        $formatted = preg_replace('/<\s*\/\s*li\s*>/i', "\n", $formatted);
        $formatted = preg_replace('/<\s*br\s*\/?>/i', "\n", $formatted);
        $formatted = preg_replace('/<\s*\/\s*p\s*>/i', "\n\n", $formatted);
        $formatted = preg_replace('/<\s*\/\s*div\s*>/i', "\n", $formatted);
        $formatted = strip_tags($formatted);
        $formatted = html_entity_decode($formatted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $formatted = trim($formatted);
        $this->addParagraph($formatted, false, $color);
    }

    public function addKeyValueRow(string $key, ?string $value): void {
        $this->body .= '<w:p><w:pPr><w:spacing w:after="60" w:line="260" w:lineRule="auto"/></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:sz w:val="21"/><w:color w:val="1E293B"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $this->escape($key) . ': </w:t></w:r>'
            . '<w:r><w:rPr><w:sz w:val="21"/><w:color w:val="333333"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $this->escape($value ?: 'Not specified') . '</w:t></w:r></w:p>';
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
                        . '<w:t xml:space="preserve">' . $this->escape($cell) . '</w:t></w:r></w:p></w:tc>';
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
        $ddate = ((2026 - 1980) << 9) | (9 << 5) | 3;

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

// Build Document Content
$builder = new DocxBuilder();
$builder->addTitle("NATIONAL DAIRY RESEARCH INSTITUTE (NDRI PRIME)", "PROJECT PROPOSAL SUBMISSION & DOSSIER");

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
    ],
    [
        ['text' => 'Proposed Budget (INR):', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => format_currency((float)$proposal['proposed_budget']), 'bold' => true],
        ['text' => 'Project Category:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => ($proposal['project_type'] ?? '') === 'funding_agency' ? 'Externally Funded Project' : 'In-house Project', 'bold' => true, 'color' => ($proposal['project_type'] ?? '') === 'funding_agency' ? '059669' : '1A365D']
    ],
    [
        ['text' => 'Funding Agency:', 'bold' => true, 'bg' => 'F8FAFC'],
        ['text' => !empty($proposal['funding_agency']) ? (string)$proposal['funding_agency'] : 'N/A', 'bold' => !empty($proposal['funding_agency'])],
        ['text' => '', 'bg' => 'FFFFFF'],
        ['text' => '']
    ]
];
$builder->addMetaTable($metaMatrix);

// Section 1: Title
$builder->addHeading("1. PROJECT TITLE");
$builder->addParagraph($proposal['title'], true, '1A365D');

// Section 2: Priority Alignment
$builder->addHeading("2. PRIORITY ALIGNMENT");
$builder->addKeyValueRow("Institute Priority Area", "Program " . ($proposal['institute_priority_area'] ?: 'A'));
$builder->addKeyValueRow("National Priority Area", $proposal['national_priority_area'] ?: 'None specified');

// Section 3: Co-Investigators
$builder->addHeading("3. PROJECT ASSOCIATES / CO-INVESTIGATORS (Co-PIs)");
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

// Section 4: Research Problem & Baseline
$builder->addHeading("4. RESEARCH PROBLEM & BASELINE INFORMATION");
$builder->addKeyValueRow("Research Problem / Questions", "");
$builder->addParagraph($proposal['research_problem']);
$builder->addKeyValueRow("Baseline Information", "");
$builder->addParagraph($proposal['baseline_info']);

// Section 5: Novelty & Gap Analysis
$builder->addHeading("5. NOVELTY, GAP ANALYSIS & JUSTIFICATION");
$builder->addKeyValueRow("Novelty / Gap Analysis", "");
$builder->addParagraph($proposal['novelty_gap_analysis']);
$builder->addKeyValueRow("Justification & Intended End-Users", "");
$builder->addParagraph($proposal['justification_end_users']);

// Section 6: Objectives & Technical Program
$builder->addHeading("6. OBJECTIVES & TECHNICAL PROGRAM");
$builder->addKeyValueRow("Specific Objectives", "");
$builder->addRichText($proposal['objectives']);
$builder->addKeyValueRow("Technical Program Proposed (objective wise, also indicate the role of Co Pis)", "");
$builder->addRichText($proposal['technical_program']);

// Section 7: Expected Outcomes & Budget
$builder->addHeading("7. EXPECTED OUTCOMES & BUDGET JUSTIFICATION");
$builder->addKeyValueRow("Expected Deliverables & Outcomes", "");
$builder->addParagraph($proposal['expected_outcomes']);
$builder->addKeyValueRow("Financial & Budget Justification", "");
$builder->addParagraph($proposal['budget_justification']);

// Section 8: Review & Workflow History
$builder->addHeading("8. PROPOSAL WORKFLOW & REVIEW HISTORY");
if (!empty($history)) {
    $histRows = [];
    foreach ($history as $h) {
        $histRows[] = [
            format_datetime($h['created_at']),
            $h['previous_status'] ?: 'Draft Initiated',
            $h['new_status'] ?? '',
            $h['action_by_role'] ?? '',
            $h['comments'] ?: 'None'
        ];
    }
    $builder->addTable(["Date & Time", "Previous Status", "New Status", "Role", "Comments / Remarks"], $histRows);
} else {
    $builder->addParagraph("No workflow history recorded yet.");
}

// Generate docx binary
$binaryDocx = $builder->generateDocx();
$cleanPropNum = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$proposal['proposal_number']);
$filename = "Proposal_{$cleanPropNum}.docx";

// Send proper OpenXML headers
header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Content-Length: " . strlen($binaryDocx));
header("Cache-Control: max-age=0, no-cache, no-store, must-revalidate");
header("Pragma: public");

echo $binaryDocx;
exit;
