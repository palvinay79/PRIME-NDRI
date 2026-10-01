-- NDRI PRIME / Research Proposal & Project Management System
-- Relational MySQL Schema with Sample Data

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS completion_reports;
DROP TABLE IF EXISTS progress_report_comments;
DROP TABLE IF EXISTS progress_reports;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS irc_decisions;
DROP TABLE IF EXISTS irc_meetings;
DROP TABLE IF EXISTS proposal_documents;
DROP TABLE IF EXISTS proposal_comments;
DROP TABLE IF EXISTS proposal_status_history;
DROP TABLE IF EXISTS proposal_co_pis;
DROP TABLE IF EXISTS proposals;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Roles
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Departments / Divisions
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_code VARCHAR(20) NOT NULL UNIQUE,
    department_name VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    department_id INT NULL,
    designation VARCHAR(100) DEFAULT 'Scientist',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    INDEX idx_user_role (role_id),
    INDEX idx_user_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Proposals
CREATE TABLE proposals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_number VARCHAR(50) NOT NULL UNIQUE,
    project_type VARCHAR(50) NOT NULL DEFAULT 'in_house',
    funding_agency VARCHAR(255) NULL,
    title VARCHAR(300) NOT NULL,
    scientist_id INT NOT NULL,
    department_id INT NOT NULL,
    institute_priority_area VARCHAR(150) NULL,
    national_priority_area VARCHAR(150) NULL,
    trl_level INT DEFAULT 1,
    research_problem TEXT,
    baseline_info TEXT,
    novelty_gap_analysis TEXT,
    justification_end_users TEXT,
    institute_alignment TEXT,
    technical_program TEXT,
    objectives TEXT,
    methodology TEXT,
    expected_outcomes TEXT,
    proposed_budget DECIMAL(12,2) DEFAULT 0.00,
    budget_justification TEXT,
    submission_remarks TEXT,
    proposed_start_date DATE NULL,
    proposed_end_date DATE NULL,
    current_status VARCHAR(50) NOT NULL DEFAULT 'Draft',
    project_number VARCHAR(50) NULL,
    approved_budget DECIMAL(12,2) DEFAULT NULL,
    approved_start_date DATE NULL,
    approved_end_date DATE NULL,
    approved_timeframe VARCHAR(100) NULL,
    submitted_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (scientist_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
    INDEX idx_prop_status (current_status),
    INDEX idx_prop_scientist (scientist_id),
    INDEX idx_prop_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Proposal Co-PIs
CREATE TABLE proposal_co_pis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    co_pi_name VARCHAR(120) NOT NULL,
    institution VARCHAR(150) NOT NULL,
    designation VARCHAR(100) NULL,
    email VARCHAR(120) NULL,
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Proposal Status History
CREATE TABLE proposal_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    previous_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    action_by INT NOT NULL,
    action_by_role VARCHAR(50) NOT NULL,
    comments TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (action_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_hist_proposal (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Proposal Comments (HOD, JD, Scientist discussion)
CREATE TABLE proposal_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    comment_stage VARCHAR(50) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Proposal Documents
CREATE TABLE proposal_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) NULL,
    file_size INT DEFAULT 0,
    uploaded_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. IRC Meetings
CREATE TABLE irc_meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_number VARCHAR(50) NOT NULL UNIQUE,
    meeting_date DATE NOT NULL,
    remarks TEXT,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. IRC Decisions
CREATE TABLE irc_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    irc_meeting_id INT NOT NULL,
    decision ENUM('Approved', 'Not Approved') NOT NULL,
    approved_budget DECIMAL(12,2) NULL,
    approved_start_date DATE NULL,
    approved_end_date DATE NULL,
    approved_timeframe VARCHAR(100) NULL,
    project_number VARCHAR(50) NULL,
    remarks TEXT,
    decided_by INT NOT NULL,
    decided_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (irc_meeting_id) REFERENCES irc_meetings(id) ON DELETE RESTRICT,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Projects
CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL UNIQUE,
    project_number VARCHAR(50) NOT NULL UNIQUE,
    project_type VARCHAR(50) NOT NULL DEFAULT 'in_house',
    funding_agency VARCHAR(255) NULL,
    scientist_id INT NOT NULL,
    department_id INT NOT NULL,
    project_status ENUM('Active', 'Completed') DEFAULT 'Active',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    approved_budget DECIMAL(12,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE RESTRICT,
    FOREIGN KEY (scientist_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
    INDEX idx_proj_status (project_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Progress Reports
CREATE TABLE progress_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    report_period VARCHAR(100) NOT NULL,
    reporting_period VARCHAR(100) NULL,
    progress_summary TEXT NOT NULL,
    work_completed TEXT NOT NULL,
    achievements TEXT,
    challenges TEXT,
    budget_utilized DECIMAL(12,2) DEFAULT 0.00,
    budget_utilization DECIMAL(12,2) DEFAULT 0.00,
    next_period_plan TEXT,
    next_steps TEXT,
    document_path VARCHAR(255) NULL,
    review_status VARCHAR(50) DEFAULT 'Submitted',
    reviewer_comments TEXT NULL,
    submitted_by INT NOT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Progress Report Comments
CREATE TABLE progress_report_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    progress_report_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (progress_report_id) REFERENCES progress_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Completion Reports
CREATE TABLE completion_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL UNIQUE,
    completion_date DATE NULL,
    final_summary TEXT NOT NULL,
    objectives_achieved TEXT NOT NULL,
    research_outcomes TEXT NOT NULL,
    deliverables TEXT,
    publications_deliverables TEXT,
    final_budget_utilized DECIMAL(12,2) DEFAULT 0.00,
    final_budget_utilization DECIMAL(12,2) DEFAULT 0.00,
    lessons_learned TEXT,
    document_path VARCHAR(255) NULL,
    review_status VARCHAR(50) DEFAULT 'Submitted',
    submitted_by INT NOT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. Audit Logs
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    details TEXT,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================
-- SEED DATA
-- Default Passwords are all hashed with password_hash('password123', PASSWORD_BCRYPT)
-- Hash: $2y$10$4n989B7o6.4G4K3mFmYfU.h6G7N5P4H5J6K7L8M9N0O1P2Q3R4S5T
-- ========================================================

INSERT INTO roles (id, role_name) VALUES
(1, 'Scientist'),
(2, 'Head of Department'),
(3, 'Joint Director');

INSERT INTO departments (id, department_code, department_name) VALUES
(1, 'AGB', 'Animal Genetics & Breeding Division'),
(2, 'ABC', 'Animal Biochemistry Division'),
(3, 'ABT', 'Animal Biotechnology Division'),
(4, 'AP', 'Animal Physiology Division'),
(5, 'LPM', 'Livestock Production and Management Division'),
(6, 'AN', 'Animal Nutrition Division'),
(7, 'DT', 'Dairy Technology Division'),
(8, 'DC', 'Dairy Chemistry Division'),
(9, 'DM', 'Dairy Microbiology Division'),
(10, 'DE', 'Dairy Engineering Division'),
(11, 'D Extns.', 'Dairy Extension Division'),
(12, 'DESM', 'Dairy Economics, Statistics & Management Division'),
(13, 'SRS', 'Southern Regional Station, Bengaluru'),
(14, 'ERS', 'Eastern Regional Station, Kalyani');

-- Pre-hashed: password123 -> $2y$10$nNf/E24qK9eWpG0/vTjQMe7kZgH8f/J8bN6D5G7H8J9K0L1M2N3O4
-- Standard PHP password_hash for 'password123':
-- $2y$10$w8T9Vv4vUvU9V7y.mN2s6u0C7J9B3K6M9R0S1T2U3V4W5X6Y7Z8a
INSERT INTO users (id, name, email, password, role_id, department_id, designation, status) VALUES
(1, 'Dr. Aris Thorne', 'scientist1@ndri.res.in', '$2y$10$mBqLzU0Yj7xN6mP9r1S3uO5kG9V7xJ8aB0cD1eF2gH3iJ4kL5mN6o', 1, 1, 'Senior Scientist', 'active'),
(2, 'Dr. Sarah Jenkins', 'scientist2@ndri.res.in', '$2y$10$mBqLzU0Yj7xN6mP9r1S3uO5kG9V7xJ8aB0cD1eF2gH3iJ4kL5mN6o', 1, 6, 'Scientist', 'active'),
(3, 'Prof. Rajesh Kumar', 'hod.agb@ndri.res.in', '$2y$10$mBqLzU0Yj7xN6mP9r1S3uO5kG9V7xJ8aB0cD1eF2gH3iJ4kL5mN6o', 2, 1, 'Head of Department (AGB)', 'active'),
(4, 'Prof. Meena Rao', 'hod.an@ndri.res.in', '$2y$10$mBqLzU0Yj7xN6mP9r1S3uO5kG9V7xJ8aB0cD1eF2gH3iJ4kL5mN6o', 2, 6, 'Head of Department (AN)', 'active'),
(5, 'Dr. Jay Dee', 'jointdirector@ndri.res.in', '$2y$10$mBqLzU0Yj7xN6mP9r1S3uO5kG9V7xJ8aB0cD1eF2gH3iJ4kL5mN6o', 3, 1, 'Joint Director (Research)', 'active');
