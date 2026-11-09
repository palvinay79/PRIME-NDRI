# NDRI PRIME
### Project Information Management and Evaluation System
**ICAR - National Dairy Research Institute (Deemed University), Karnal, Haryana**

---

## Overview

**NDRI PRIME** is an enterprise-grade research project management, evaluation, and institutional governance platform tailored for the **ICAR - National Dairy Research Institute (NDRI)**. 

The system streamlines the entire research lifecycle:
- **Proposal Conceptualization & Submission** by Principal Investigators (PIs) and Co-PIs across specialized institute programs (Program A to F).
- **Division Scrutiny & Endorsement** by Heads of Divisions (HODs) across all 11 research divisions.
- **Institutional Screening & Decision Registry** by the Joint Director (Research) / PME Cell.
- **Staff Research Council (SRC / IRC) Deliberation & Proceedings** management with automated agenda formulation and single-click Microsoft Word (`.docx`) dossier generation.
- **Ongoing Progress Tracking** and annual milestone reviews.
- **Project Completion & Output Archival**, including final technical reports, Action Taken Reports (ATR), patents, publications, and technologies transferred.

---

## Key Modules & Features

### 1. Scientist / Principal Investigator Portal
- **Proposal Authoring**: Guided submission workflow supporting In-House and Externally Funded research projects.
- **Externally Funded Agency Directory**: Direct integration with 20 leading national funding bodies (DST, DBT, ANRF/SERB, ICAR, ICMR, CSIR, MoFPI, DAHD, NABARD, NDDB, etc.) with dynamic custom agency specification.
- **Collaborative Co-PI Management**: Division-aware scientist search and co-investigator designation with institutional percentage contribution tracking.
- **Budgetary Outlay Matrix**: Comprehensive financial breakdowns across operational costs, recurring consumables, travel, and capital infrastructure.
- **Rich Content Editing**: Integrated CKEditor suite for Executive Summaries, Methodologies, Significant Achievements, and ATRs.

### 2. Head of Division (HOD) Review Suite
- **Division-Locked Integrity**: Enforced security preventing division reassignment on active submissions.
- **Technical Scrutiny & Comments**: Detailed field review, capability verification, and endorsement workflow.
- **Action Triggers**: One-click forwarding to the Joint Director (Research) or return with revision notes to the PI.

### 3. Joint Director (Research) & PME Cell
- **Institutional Screening**: Verification against national and institutional priority matrices (Programs A through F).
- **SRC / IRC Meeting Docket Management**: Batch assignment to meeting cohorts and formal IRC agenda formulation.
- **Deliberation Decision Registry**: Recording formal committee recommendations (Approved, Approved with Modifications, Re-submit, Archived).
- **Official Word (.docx) Dossier Export**: Server-side generation of high-fidelity, formatted meeting dossiers categorized by research program with clean typography, page breaks, and standardized tabular layouts.

### 4. Ongoing & Completion Lifecycle
- **Progress Tracking**: Periodic milestone monitoring and budget utilization audits.
- **Completion Reporting**: Standardized Form RPD-III submission with achievements, patent disclosures, commercialization prospects, and output metrics.

---

## Technology Stack

- **Backend Architecture**: PHP 8.2 with PDO (supporting MySQL/MariaDB and SQLite engines).
- **Frontend Framework**: Bootstrap 5, Modern Responsive UI, Lucide Icons, and CKEditor 5.
- **Document Processing**: OpenXML / `.docx` packaging pipeline for native Microsoft Office interoperability.
- **Development Tooling**: Node.js & TypeScript proxy server for unified environment orchestration.

---

## Directory Structure

```text
├── config/                  # Database connections, constants, and system settings
│   ├── constants.php        # Roles, workflow statuses, priority programs, funding agencies
│   └── database.php         # PDO connection abstraction with auto-schema migration
├── includes/                # Shared UI templates, auth guards, and utility helpers
│   ├── auth.php             # Authentication middleware & role-based access control
│   ├── docx_export.php      # Microsoft Word dossier export engine
│   ├── header.php           # Standard institutional navigation bar
│   └── footer.php           # Standard institutional footer & script assets
├── scientist/               # Principal Investigator portal pages & controllers
├── hod/                     # Head of Division review & forwarding controllers
├── joint-director/          # Joint Director screening, IRC meeting & dossier modules
├── uploads/                 # Secure storage for project documents and annexures
├── index.php                # Authentication gateway & unified login portal
├── register.php             # Scientist registration & division onboarding
└── server.ts                # Application gateway server
```

---

## Setup & Local Installation

### Prerequisites
- PHP 8.2 or later with PDO, SQLite3, MBString, and Zip extensions enabled.
- Node.js 18+ (for running the unified development runtime).
- Modern web browser (Chrome, Edge, Firefox, or Safari).

### Quick Start
1. Clone the repository:
   ```bash
   git clone https://github.com/<your-organization>/ndri-prime.git
   cd ndri-prime
   ```
2. Copy the environment configuration template:
   ```bash
   cp .env.example .env
   ```
3. Install project dependencies:
   ```bash
   npm install
   ```
4. Start the application:
   ```bash
   npm run dev
   ```
5. Open your browser and navigate to `http://localhost:3000`.

---

## Security & Compliance
- Role-Based Access Control (RBAC) strictly segregating Scientist, HOD, and Joint Director capabilities.
- Prepared PDO statements utilized across all database interactions to guard against SQL injection.
- Session-based authentication with cryptographically secure password hashing (`PASSWORD_DEFAULT` / Bcrypt).

---

## Institutional Attribution
Developed for the **ICAR - National Dairy Research Institute (Deemed University)**  
Karnal - 132001, Haryana, India.
